<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/price_providers.php';
requireRole('user', 'administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

$mode         = $_POST['mode']          ?? 'latest';
$investmentId = (int)($_POST['investment_id'] ?? 0);
$from         = $_POST['from'] ?? date('Y-m-d', strtotime('-1 year'));
$to           = $_POST['to']   ?? date('Y-m-d');

$provider = getPriceProvider();
if (!$provider) {
    echo json_encode(['ok' => false, 'error' => 'No price provider configured. Visit Settings to configure one.']);
    exit;
}

$db = getDB();

if ($investmentId) {
    $stmt = $db->prepare("SELECT id, name, symbol, type FROM investments WHERE id = ? AND is_active = 1 AND disable_quotes = 0 AND symbol != ''");
    $stmt->execute([$investmentId]);
} else {
    $stmt = $db->query("SELECT id, name, symbol, type FROM investments WHERE is_active = 1 AND disable_quotes = 0 AND symbol != ''");
}
$investments = $stmt->fetchAll();

if (empty($investments)) {
    echo json_encode(['ok' => false, 'error' => 'No investments with ticker symbols found.']);
    exit;
}

$upsert = $db->prepare(
    'INSERT INTO investment_prices
       (investment_id, price_date, open_price, high_price, low_price, close_price, volume, vwap, source)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       open_price  = VALUES(open_price),
       high_price  = VALUES(high_price),
       low_price   = VALUES(low_price),
       close_price = VALUES(close_price),
       volume      = VALUES(volume),
       vwap        = VALUES(vwap),
       source      = VALUES(source),
       updated_at  = NOW()'
);

// Indices and mutual funds always use Yahoo Finance — Massive/AlphaVantage don't support these on free plans
$yahooProvider = new YahooProvider();

$updated = 0;
$skipped = 0;
$errors  = [];
$results = [];

// Returns true if this investment type should skip the primary provider entirely.
function usesYahooOnly(string $type): bool {
    return in_array($type, ['Index', 'Mutual Fund'], true);
}

// Inserts bars in a single transaction and returns the count stored.
function storeBars(PDO $db, PDOStatement $upsert, int $invId, array $bars, string $source): int {
    $n = 0;
    $db->beginTransaction();
    try {
        foreach ($bars as $bar) {
            $upsert->execute([
                $invId, $bar['date'],
                $bar['open'], $bar['high'], $bar['low'], $bar['close'],
                $bar['volume'], $bar['vwap'], $source,
            ]);
            $n++;
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    return $n;
}

if ($mode === 'history') {
    foreach ($investments as $inv) {
        $yahoo = usesYahooOnly($inv['type']);
        $p     = $yahoo ? $yahooProvider : $provider;
        $bars  = [];
        $fallback = false;
        $errMsg   = '';

        try {
            $bars = $p->getHistoricalBars($inv['symbol'], $from, $to);
        } catch (Exception $e) {
            $errMsg = $e->getMessage();
        }

        // Fallback to Yahoo if primary returned nothing and wasn't already Yahoo
        if (empty($bars) && !$yahoo) {
            try {
                $fbBars = $yahooProvider->getHistoricalBars($inv['symbol'], $from, $to);
                if (!empty($fbBars)) {
                    $bars     = $fbBars;
                    $fallback = true;
                    $errMsg   = '';
                }
            } catch (Exception $e2) {
                // keep original error
            }
        }

        if (empty($bars)) {
            $msg = $errMsg ?: 'no data returned';
            $errors[]  = $inv['symbol'] . ': ' . $msg;
            $skipped++;
            $results[] = ['symbol' => $inv['symbol'], 'name' => $inv['name'],
                          'status' => 'error', 'source' => '', 'fallback' => false,
                          'count' => 0, 'last_quote' => null, 'change_pct' => null,
                          'message' => $msg];
            continue;
        }

        $source = ($fallback ? $yahooProvider : $p)->getName() . ($fallback ? ' (fallback)' : '');
        $lastBar = end($bars) ?: null;
        $lastQuote = isset($lastBar['close']) ? (float)$lastBar['close'] : null;
        $lastOpen  = isset($lastBar['open']) && $lastBar['open'] !== null ? (float)$lastBar['open'] : null;
        $changePct = ($lastQuote !== null && $lastOpen !== null && $lastOpen > 0)
            ? (($lastQuote - $lastOpen) / $lastOpen) * 100
            : null;
        $n      = storeBars($db, $upsert, $inv['id'], $bars, $source);
        $updated += $n;
        $results[] = ['symbol' => $inv['symbol'], 'name' => $inv['name'],
                      'status' => 'ok', 'source' => $source,
                      'fallback' => $fallback, 'count' => $n,
                      'last_quote' => $lastQuote, 'change_pct' => $changePct,
                      'message' => ''];
    }
} else {
    // Build a single URL map for all investments, then fetch everything in parallel.
    // Keys encode both the investment id and which provider so we can route responses.
    $urlMap = [];
    foreach ($investments as $inv) {
        $p = usesYahooOnly($inv['type']) ? $yahooProvider : $provider;
        $urlMap[$inv['id']] = $p->latestBarUrl($inv['symbol']);
    }

    $responses = PriceProvider::curlMultiFetch($urlMap);

    // Investments whose primary fetch failed need a Yahoo fallback.
    $fallbackUrls = [];
    foreach ($investments as $inv) {
        if (usesYahooOnly($inv['type'])) continue; // already used Yahoo
        $invId = $inv['id'];
        $bar   = $provider->parseLatestBarData($responses[$invId] ?? null);
        if ($bar === null) {
            $fallbackUrls[$invId] = $yahooProvider->latestBarUrl($inv['symbol']);
        }
    }

    $fallbackResponses = PriceProvider::curlMultiFetch($fallbackUrls);

    foreach ($investments as $inv) {
        $invId    = $inv['id'];
        $yahoo    = usesYahooOnly($inv['type']);
        $p        = $yahoo ? $yahooProvider : $provider;
        $bar      = $p->parseLatestBarData($responses[$invId] ?? null);
        $fallback = false;

        if ($bar === null && !$yahoo && array_key_exists($invId, $fallbackResponses)) {
            $fbBar = $yahooProvider->parseLatestBarData($fallbackResponses[$invId] ?? null);
            if ($fbBar !== null) {
                $bar      = $fbBar;
                $fallback = true;
            }
        }

        if ($bar === null) {
            $errors[]  = $inv['symbol'] . ': no price returned';
            $skipped++;
            $results[] = ['symbol' => $inv['symbol'], 'name' => $inv['name'],
                          'status' => 'error', 'source' => '', 'fallback' => false,
                          'count' => 0, 'last_quote' => null, 'change_pct' => null,
                          'message' => 'no price returned'];
            continue;
        }

        $source = ($fallback ? $yahooProvider : $p)->getName() . ($fallback ? ' (fallback)' : '');
        $lastQuote = isset($bar['close']) ? (float)$bar['close'] : null;
        $lastOpen  = isset($bar['open']) && $bar['open'] !== null ? (float)$bar['open'] : null;
        $changePct = ($lastQuote !== null && $lastOpen !== null && $lastOpen > 0)
            ? (($lastQuote - $lastOpen) / $lastOpen) * 100
            : null;
        storeBars($db, $upsert, $inv['id'], [$bar], $source);
        $updated++;
        $results[] = ['symbol' => $inv['symbol'], 'name' => $inv['name'],
                      'status' => 'ok', 'source' => $source,
                      'fallback' => $fallback, 'count' => 1,
                      'last_quote' => $lastQuote, 'change_pct' => $changePct,
                      'message' => ''];
    }
}

setSetting('price_last_fetched', date('Y-m-d H:i:s'));

echo json_encode(['ok' => true, 'updated' => $updated, 'skipped' => $skipped,
                  'errors' => $errors, 'results' => $results]);
