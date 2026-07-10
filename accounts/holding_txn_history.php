<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$accountId    = (int)($_GET['account_id']    ?? 0);
$investmentId = (int)($_GET['investment_id'] ?? 0);

if (!$accountId || !$investmentId) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters.']);
    exit;
}

$account = getAccount($accountId);
if (!$account) {
    echo json_encode(['ok' => false, 'error' => 'Invalid account.']);
    exit;
}

$db = getDB();

$stmt = $db->prepare(
    'SELECT
        t.transaction_date AS date,
        t.memo,
        it.activity,
        it.quantity,
        it.price,
        it.commission
     FROM investment_transactions it
     JOIN transactions t ON t.id = it.transaction_id
     WHERE t.account_id = ? AND it.investment_id = ?
     ORDER BY t.transaction_date ASC, t.id ASC'
);
$stmt->execute([$accountId, $investmentId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$txns = [];
foreach ($rows as $r) {
    $txns[] = [
        'date'       => $r['date'],
        'activity'   => $r['activity'],
        'quantity'   => (float)$r['quantity'],
        'price'      => (float)$r['price'],
        'commission' => (float)$r['commission'],
        'memo'       => $r['memo'],
    ];
}

echo json_encode(['ok' => true, 'transactions' => $txns]);
