<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('user', 'administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/index');
    exit;
}
verifyCsrf();

$db        = getDB();
$id        = (int)($_POST['id']         ?? 0);
$accountId = (int)($_POST['account_id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM transactions WHERE id = ?');
$stmt->execute([$id]);
$txn = $stmt->fetch();

if (!$txn) {
    setFlash('error', 'Transaction not found.');
    header('Location: ' . BASE_PATH . '/accounts/register?id=' . $accountId);
    exit;
}

// The cash-side leg of a buy/sell/div/int/reinvest is a reciprocal of an investment-side
// transaction — it must only be deleted from the security register, not the cash register.
if (getInvestmentPairInfo($txn['transfer_pair_id'])) {
    setFlash('error', 'This transaction is linked to a security-register entry — delete it from the investment account register instead.');
    header('Location: ' . BASE_PATH . '/accounts/register?id=' . $accountId);
    exit;
}

// Non-admin cannot delete reconciled transactions
if ($txn['cleared_status'] === 'reconciled' && !isAdmin()) {
    setFlash('error', 'Cannot delete reconciled transactions.');
    header('Location: ' . BASE_PATH . '/accounts/register?id=' . $accountId);
    exit;
}

// Setting may restrict non-admin from deleting any transaction
if (!canDelete()) {
    setFlash('error', 'You do not have permission to delete transactions.');
    header('Location: ' . BASE_PATH . '/accounts/register?id=' . $accountId);
    exit;
}

// If this is a transfer, delete the paired transaction too
if ($txn['type'] === 'transfer') {
    $pairStmt = $db->prepare(
        'SELECT from_transaction_id, to_transaction_id FROM transfers
         WHERE from_transaction_id = ? OR to_transaction_id = ?'
    );
    $pairStmt->execute([$id, $id]);
    $pair = $pairStmt->fetch();
    if ($pair) {
        $otherId = ($pair['from_transaction_id'] == $id) ? $pair['to_transaction_id'] : $pair['from_transaction_id'];
        $db->prepare('DELETE FROM transactions WHERE id = ?')->execute([$otherId]);
    }
}

// If this is an investment buy/sell, delete the paired cash transaction
if ($txn['type'] === 'investment' && $txn['transfer_pair_id']) {
    $db->prepare('DELETE FROM transactions WHERE id = ?')->execute([$txn['transfer_pair_id']]);
}

$db->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]);
logActivity('txn_deleted', sprintf('Deleted #%d — %s %s on %s in account #%d',
    $id, $txn['type'], $txn['payee'] ? '"' . $txn['payee'] . '"' : '',
    $txn['transaction_date'], $accountId));
setFlash('success', 'Transaction deleted.');
header('Location: ' . BASE_PATH . '/accounts/register?id=' . $accountId);
exit;
