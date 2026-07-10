<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/settings/preferences');
    exit;
}
verifyCsrf();

setSetting('instance_name',               trim($_POST['instance_name'] ?? ''));
$tz = trim($_POST['timezone'] ?? '');
setSetting('timezone', in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'America/New_York');
$cs = trim($_POST['currency_symbol'] ?? '');
setSetting('currency_symbol', ($cs !== '' && mb_strlen($cs) <= 5) ? $cs : '$');
setSetting('users_can_import',               isset($_POST['users_can_import'])               ? '1' : '0');
setSetting('users_can_manage_budgets',       isset($_POST['users_can_manage_budgets'])        ? '1' : '0');
setSetting('users_can_delete_transactions',  isset($_POST['users_can_delete_transactions'])   ? '1' : '0');
setSetting('enforce_https',               isset($_POST['enforce_https'])               ? '1' : '0');
$validTimeouts = [0, 15, 30, 60, 120, 240, 480, 1440];
$timeout = (int)($_POST['session_timeout_minutes'] ?? 0);
setSetting('session_timeout_minutes', (string)(in_array($timeout, $validTimeouts, true) ? $timeout : 0));
$validRetention = [0, 30, 90, 180, 365];
$retention = (int)($_POST['log_retention_days'] ?? 90);
setSetting('log_retention_days', (string)(in_array($retention, $validRetention, true) ? $retention : 90));
setSetting('sidebar_balance',             ($_POST['sidebar_balance'] ?? '') === 'current' ? 'current' : 'ending');
setSetting('sidebar_hide_investment_cash',       isset($_POST['sidebar_hide_investment_cash'])       ? '1' : '0');
setSetting('sidebar_cash_in_investment_balance', isset($_POST['sidebar_cash_in_investment_balance']) ? '1' : '0');
setSetting('nav_hide_loans',              isset($_POST['nav_hide_loans'])              ? '1' : '0');
setSetting('nav_hide_goals',              isset($_POST['nav_hide_goals'])              ? '1' : '0');
setSetting('nav_search_icon_only',        isset($_POST['nav_search_icon_only'])        ? '1' : '0');
$validSchemes = ['blue', 'green', 'red', 'gray', 'brown'];
$scheme = trim($_POST['color_scheme'] ?? 'blue');
setSetting('color_scheme', in_array($scheme, $validSchemes, true) ? $scheme : 'blue');
$validNegFmts = ['color', 'minus', 'parens', 'parens-bw'];
$negFmt = trim($_POST['negative_format'] ?? 'color');
setSetting('negative_format', in_array($negFmt, $validNegFmts, true) ? $negFmt : 'color');
setSetting('register_form_top',  isset($_POST['register_form_top'])  ? '1' : '0');
setSetting('register_sort_desc', isset($_POST['register_sort_desc']) ? '1' : '0');

$quoteAutoFetch = isset($_POST['quote_auto_fetch']) ? '1' : '0';
setSetting('quote_auto_fetch', $quoteAutoFetch);
$validQuoteTimes = ['16:15', '16:30', '17:00', '17:30', '18:00'];
$quoteFetchTime  = $_POST['quote_fetch_time'] ?? '16:15';
if (!in_array($quoteFetchTime, $validQuoteTimes, true)) $quoteFetchTime = '16:15';
setSetting('quote_fetch_time', $quoteFetchTime);
updateQuotesCron($quoteAutoFetch === '1', $quoteFetchTime);

setFlash('success', 'Preferences saved.');
header('Location: ' . BASE_PATH . '/settings/preferences');
exit;

function updateQuotesCron(bool $enabled, string $etTime): void
{
    $phpBin = PHP_BINARY ?: '/usr/bin/php';
    $script  = dirname(__DIR__) . '/scripts/fetch_prices.php';
    $marker  = '# steward-quotes';
    $cronLine = '';

    if ($enabled) {
        [$h, $m] = array_map('intval', explode(':', $etTime));
        $dt = new DateTime('today ' . sprintf('%02d:%02d', $h, $m), new DateTimeZone('America/New_York'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        $cronLine = $dt->format('i') . ' ' . $dt->format('G') . " * * 1-5 $phpBin $script";
    }

    exec('crontab -l 2>/dev/null', $existing);

    $filtered = [];
    $skipNext = false;
    foreach ($existing as $line) {
        if ($line === $marker) { $skipNext = true; continue; }
        if ($skipNext)         { $skipNext = false; continue; }
        $filtered[] = $line;
    }

    if ($cronLine !== '') {
        $filtered[] = $marker;
        $filtered[] = $cronLine;
    }

    $input = implode("\n", $filtered);
    if ($input !== '' && !str_ends_with($input, "\n")) {
        $input .= "\n";
    }

    $proc = proc_open('crontab -', [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($proc)) return;
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
}
