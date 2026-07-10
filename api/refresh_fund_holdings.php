<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fund_holdings_fetcher.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

verifyCsrf();

$symbolsParam = trim($_POST['symbols'] ?? '');
if ($symbolsParam === '') {
    echo json_encode(['ok' => false, 'error' => 'No symbols provided']);
    exit;
}

$symbols = array_values(array_filter(
    array_map('trim', explode(',', $symbolsParam)),
    fn($s) => preg_match('/^[A-Za-z0-9.\-]{1,20}$/', $s)
));
if (empty($symbols)) {
    echo json_encode(['ok' => false, 'error' => 'No valid symbols']);
    exit;
}

$fetcher = new FundHoldingsFetcher(getDB());
$fetcher->batchFetch($symbols, true);

$results = [];
foreach ($symbols as $sym) {
    $sym      = strtoupper(trim($sym));
    $holdings = $fetcher->getHoldings($sym);
    $sumW     = array_sum(array_column($holdings, 'weight_pct'));
    $results[] = [
        'symbol'       => $sym,
        'count'        => count($holdings),
        'coverage_pct' => round((float)$sumW, 1),
        'fetched_at'   => $fetcher->getFetchedAt($sym),
    ];
}

echo json_encode(['ok' => true, 'results' => $results]);
