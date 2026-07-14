<?php
/**
 * CLI price-fetch script — called by cron, never directly via web.
 * Usage: php /var/www/html/steward/scripts/fetch_prices.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}

define('MONEY_CLI_MODE', true);
$appRoot = dirname(__DIR__);
require_once $appRoot . '/config/app.php';
require_once $appRoot . '/config/database.php';
require_once $appRoot . '/includes/functions.php';
require_once $appRoot . '/includes/price_providers.php';

$provider = getPriceProvider();
if (!$provider) {
    fwrite(STDERR, "No price provider configured. Visit Settings → Investments to configure one.\n");
    exit(1);
}

$db = getDB();
$investments = getQuotableInvestments();

if (empty($investments)) {
    echo "No investments with ticker symbols found.\n";
    exit(0);
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

$yahooProvider = new YahooProvider();

function usesYahooOnly(string $type): bool {
    return in_array($type, ['Index', 'Mutual Fund'], true);
}

// Build URL map for parallel fetch
$urlMap = [];
foreach ($investments as $inv) {
    $p = usesYahooOnly($inv['type']) ? $yahooProvider : $provider;
    $urlMap[$inv['id']] = $p->latestBarUrl($inv['symbol']);
}

$responses = PriceProvider::curlMultiFetch($urlMap);

// Identify which need Yahoo fallback
$fallbackUrls = [];
foreach ($investments as $inv) {
    if (usesYahooOnly($inv['type'])) continue;
    if ($provider->parseLatestBarData($responses[$inv['id']] ?? null) === null) {
        $fallbackUrls[$inv['id']] = $yahooProvider->latestBarUrl($inv['symbol']);
    }
}
$fallbackResponses = PriceProvider::curlMultiFetch($fallbackUrls);

$updated = 0;
$skipped = 0;

foreach ($investments as $inv) {
    $invId  = $inv['id'];
    $yahoo  = usesYahooOnly($inv['type']);
    $p      = $yahoo ? $yahooProvider : $provider;
    $bar    = $p->parseLatestBarData($responses[$invId] ?? null);
    $fallback = false;

    if ($bar === null && !$yahoo && array_key_exists($invId, $fallbackResponses)) {
        $fbBar = $yahooProvider->parseLatestBarData($fallbackResponses[$invId] ?? null);
        if ($fbBar !== null) {
            $bar      = $fbBar;
            $fallback = true;
        }
    }

    if ($bar === null) {
        fwrite(STDERR, "  [skip] {$inv['symbol']}: no price returned\n");
        $skipped++;
        continue;
    }

    $source = ($fallback ? $yahooProvider : $p)->getName() . ($fallback ? ' (fallback)' : '');
    $db->beginTransaction();
    try {
        $upsert->execute([
            $invId, $bar['date'],
            $bar['open'], $bar['high'], $bar['low'], $bar['close'],
            $bar['volume'], $bar['vwap'], $source,
        ]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        fwrite(STDERR, "  [error] {$inv['symbol']}: " . $e->getMessage() . "\n");
        $skipped++;
        continue;
    }

    echo "  [ok] {$inv['symbol']} — {$bar['date']} \${$bar['close']} via $source\n";
    $updated++;
}

setSetting('price_last_fetched', date('Y-m-d H:i:s'));
logActivity('auto_quote_fetch', "Auto price fetch: $updated updated, $skipped skipped", 0, 'system');

echo "Done: $updated updated, $skipped skipped.\n";
