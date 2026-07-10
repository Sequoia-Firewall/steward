<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$period   = $_GET['period'] ?? 'month';
$rawAccts = trim($_GET['acct_ids'] ?? '');
$acctIds  = $rawAccts !== '' ? array_values(array_filter(array_map('intval', explode(',', $rawAccts)))) : [];

if (!in_array($period, ['month', 'last_month', 'year', '90days'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid period']);
    exit;
}

echo json_encode(['ok' => true, 'rows' => array_values(getDashboardSpending($period, $acctIds))]);
