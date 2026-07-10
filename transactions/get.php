<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$id  = (int)($_GET['id'] ?? 0);
$db  = getDB();
$stmt = $db->prepare('SELECT * FROM transactions WHERE id = ?');
$stmt->execute([$id]);
$txn = $stmt->fetch();

if (!$txn) {
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

// Fetch splits
$splits = getTransactionSplits($id);

// For transfers, get the paired account info
$pairedAccount = null;
if ($txn['type'] === 'transfer' && $txn['transfer_pair_id']) {
    $pStmt = $db->prepare('SELECT account_id FROM transactions WHERE id = ?');
    $pStmt->execute([$txn['transfer_pair_id']]);
    $pRow = $pStmt->fetch();
    if ($pRow) $pairedAccount = (int)$pRow['account_id'];
}

// For investment transactions, fetch detail and linked cash account
$invDetail     = null;
$cashAccountId = null;
if ($txn['type'] === 'investment') {
    $iStmt = $db->prepare('SELECT * FROM investment_transactions WHERE transaction_id = ?');
    $iStmt->execute([$id]);
    $invDetail = $iStmt->fetch() ?: null;
    if ($txn['transfer_pair_id']) {
        $cStmt = $db->prepare('SELECT account_id FROM transactions WHERE id = ?');
        $cStmt->execute([$txn['transfer_pair_id']]);
        $cRow = $cStmt->fetch();
        if ($cRow) $cashAccountId = (int)$cRow['account_id'];
    }
}

// A cash-side reciprocal of a security-register transaction must only be edited from
// the investment account register, not here.
$invPair = getInvestmentPairInfo($txn['transfer_pair_id']);

echo json_encode([
    'ok'                    => true,
    'txn'                   => $txn,
    'splits'                => $splits,
    'paired_account'        => $pairedAccount,
    'inv_detail'            => $invDetail,
    'cash_account_id'       => $cashAccountId,
    'is_investment_reciprocal' => $invPair !== null,
    'investment_account_id'    => $invPair['investment_account_id'] ?? null,
    'investment_txn_id'        => $invPair['investment_txn_id'] ?? null,
]);
