<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sector_fetcher.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

verifyCsrf();

$raw = trim($_POST['symbols'] ?? '');
if ($raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No symbols provided.']);
    exit;
}

$symbols = array_values(array_unique(array_filter(
    array_map('trim', explode(',', $raw)),
    fn($s) => preg_match('/^[A-Za-z0-9.\-]{1,20}$/', $s)
)));

if (empty($symbols)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No valid symbols.']);
    exit;
}

$db      = getDB();
$fetcher = new SectorFetcher($db);
$results = $fetcher->fetchMissingFromAV($symbols, 20);

$out = [];
foreach ($results as $sym => $ok) {
    $out[] = ['symbol' => $sym, 'fetched' => $ok];
}

echo json_encode(['ok' => true, 'results' => $out]);
