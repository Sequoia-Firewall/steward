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
    // Explicit single-security fetch (price-history modal, watchlist row refresh) stays
    // unfiltered on purpose — a manual fetch should work for any security.
    $stmt = $db->prepare("SELECT id, name, symbol, type FROM investments WHERE id = ? AND is_active = 1 AND disable_quotes = 0 AND symbol != ''");
    $stmt->execute([$investmentId]);
    $investments = $stmt->fetchAll();
} else {
    $investments = getQuotableInvestments();
}

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

// Safety check before overwriting history: statement and manual prices are
// as traded, so downloaded bars on the same dates should be close to them.
// Off by about a split ratio means the provider adjusted for a split it
// didn't report — storing those bars would corrupt pre-split valuations.
// Returns an error message, or null when the bars look right.
function checkBarsAgainstStatements(PDO $db, int $invId, array $bars): ?string {
    $stmt = $db->prepare(
        "SELECT price_date, close_price FROM investment_prices
         WHERE investment_id = ? AND source IN ('statement', 'manual') AND close_price > 0
           AND price_date BETWEEN ? AND ?"
    );
    $stmt->execute([$invId, $bars[0]['date'], end($bars)['date']]);
    $stored = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$stored) return null;

    $ratios = [];
    foreach ($bars as $bar) {
        if (isset($stored[$bar['date']]) && $bar['close'] > 0) $ratios[] = (float)$stored[$bar['date']] / $bar['close'];
    }
    if (!$ratios) return null;
    sort($ratios);
    $median = $ratios[intdiv(count($ratios), 2)];
    if ($median > 0.8 && $median < 1.25) return null;

    // $median = statement ÷ downloaded: above 1, the download is too small
    $n = fn(float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return 'downloaded prices are about ' . ($median > 1 ? '1/' . $n($median) . ' of' : $n(1 / $median) . '×')
         . ' your statement prices on the same dates (likely a split the provider adjusted for); not saved';
}

// Records the provider's splits from $from on in investment_splits, skipping
// any within a week of a split already recorded (a user-entered one may be
// dated a day off). Returns the added splits.
function recordProviderSplits(PDO $db, int $invId, array $splits, string $from): array {
    $existing = $db->prepare('SELECT split_date FROM investment_splits WHERE investment_id = ?');
    $existing->execute([$invId]);
    $have   = $existing->fetchAll(PDO::FETCH_COLUMN);
    $insert = $db->prepare('INSERT IGNORE INTO investment_splits (investment_id, split_date, ratio) VALUES (?, ?, ?)');
    $added  = [];
    foreach ($splits as $sp) {
        if ($sp['date'] < $from) continue;
        foreach ($have as $d) {
            if (abs(strtotime($d) - strtotime($sp['date'])) <= 7 * 86400) continue 2;
        }
        $insert->execute([$invId, $sp['date'], round($sp['ratio'], 8)]);
        $have[]  = $sp['date'];
        $added[] = $sp;
    }
    return $added;
}

if ($mode === 'history') {
    $earliestStmt = $db->prepare('SELECT MIN(price_date) FROM investment_prices WHERE investment_id = ?');
    foreach ($investments as $inv) {
        $yahoo = usesYahooOnly($inv['type']);
        $p     = $yahoo ? $yahooProvider : $provider;
        $bars  = [];
        $fallback = false;
        $errMsg   = '';

        // A split after $from changes the as-traded prices of every earlier
        // date too. Stored rows before $from may be from an older,
        // split-adjusted download, so re-download from the earliest stored
        // price to keep the whole history in as-traded terms.
        $invFrom  = $from;
        $extended = false;
        $splits   = $yahooProvider->getSplits($inv['symbol']) ?? [];
        if (array_filter($splits, fn($sp) => $sp['date'] > $from)) {
            $earliestStmt->execute([$inv['id']]);
            $earliest = $earliestStmt->fetchColumn();
            if ($earliest && $earliest < $from) { $invFrom = $earliest; $extended = true; }
        }

        try {
            $bars = $p->getHistoricalBars($inv['symbol'], $invFrom, $to);
        } catch (Exception $e) {
            $errMsg = $e->getMessage();
        }

        // Fallback to Yahoo if primary returned nothing and wasn't already Yahoo
        if (empty($bars) && !$yahoo) {
            try {
                $fbBars = $yahooProvider->getHistoricalBars($inv['symbol'], $invFrom, $to);
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

        if ($msg = checkBarsAgainstStatements($db, (int)$inv['id'], $bars)) {
            $errors[]  = $inv['symbol'] . ': ' . $msg;
            $skipped++;
            $results[] = ['symbol' => $inv['symbol'], 'name' => $inv['name'],
                          'status' => 'error', 'source' => $source, 'fallback' => $fallback,
                          'count' => 0, 'last_quote' => null, 'change_pct' => null,
                          'message' => $msg];
            continue;
        }

        $n      = storeBars($db, $upsert, $inv['id'], $bars, $source);
        $updated += $n;

        $notes = [];
        if ($extended) {
            $notes[] = 'Extended back to ' . date('m/d/Y', strtotime($invFrom)) . ' to keep the history consistent across a split';
        }
        if ($splits) {
            $added = recordProviderSplits($db, (int)$inv['id'], $splits, $invFrom);
            if ($added) {
                $notes[] = 'Recorded ' . implode(', ', array_map(
                    fn($sp) => fmtSplitRatio($sp['ratio']) . ' split on ' . date('m/d/Y', strtotime($sp['date'])),
                    $added));
            }
        }

        $results[] = ['symbol' => $inv['symbol'], 'name' => $inv['name'],
                      'status' => 'ok', 'source' => $source,
                      'fallback' => $fallback, 'count' => $n,
                      'last_quote' => $lastQuote, 'change_pct' => $changePct,
                      'message' => implode('. ', $notes)];
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
