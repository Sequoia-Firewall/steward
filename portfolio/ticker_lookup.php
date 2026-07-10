<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$symbol = strtoupper(trim($_GET['symbol'] ?? ''));
if ($symbol === '' || strlen($symbol) > 20 || !preg_match('/^[\w\^\-\.]+$/', $symbol)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid symbol.']);
    exit;
}

$url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
     . rawurlencode($symbol) . '?interval=1d&range=1d';

$ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'header'        => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nAccept: application/json",
    'timeout'       => 10,
    'ignore_errors' => true,
]]);

$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) {
    echo json_encode(['ok' => false, 'error' => 'Could not reach Yahoo Finance.']);
    exit;
}

$data   = json_decode($raw, true);
$result = $data['chart']['result'][0] ?? null;

if (!$result) {
    $msg = $data['chart']['error']['description'] ?? 'Symbol not found.';
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$meta = $result['meta'] ?? [];
$name = $meta['longName'] ?? $meta['shortName'] ?? '';

$typeMap = [
    'EQUITY'     => 'Stock',
    'ETF'        => 'ETF',
    'MUTUALFUND' => 'Mutual Fund',
    'INDEX'      => 'Index',
    'BOND'       => 'Bond',
];
$type = $typeMap[$meta['instrumentType'] ?? ''] ?? 'Stock';

echo json_encode([
    'ok'     => true,
    'name'   => $name,
    'type'   => $type,
    'symbol' => $meta['symbol'] ?? $symbol,
]);
