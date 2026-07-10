<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('user', 'administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/accounts/index');
    exit;
}

verifyCsrf();

$accountId = (int)($_POST['account_id'] ?? 0);
$account   = getAccount($accountId);

if (!$account || $account['type'] === 'Asset') {
    setFlash('error', 'Invalid account.');
    header('Location: ' . BASE_PATH . '/accounts/index');
    exit;
}

$ids             = array_map('intval', $_POST['txn_ids'] ?? []);
$ids             = array_filter($ids); // remove any zeros
$statementDate   = $_POST['statement_date']    ?? '';
$statementBalance = (float)($_POST['statement_balance'] ?? 0);

$db = getDB();

if (!empty($ids)) {
    // Verify every ID belongs to this account (prevents cross-account tampering)
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $verify       = $db->prepare(
        "SELECT COUNT(*) FROM transactions WHERE id IN ($placeholders) AND account_id = ?"
    );
    $verify->execute([...$ids, $accountId]);
    if ((int)$verify->fetchColumn() !== count($ids)) {
        setFlash('error', 'Invalid transaction IDs.');
        header('Location: ' . BASE_PATH . '/accounts/reconcile?id=' . $accountId);
        exit;
    }

    $db->prepare(
        "UPDATE transactions SET cleared_status = 'reconciled', updated_at = NOW()
         WHERE id IN ($placeholders) AND account_id = ?"
    )->execute([...$ids, $accountId]);
}

// Record date and balance of this reconciliation on the account
$reconDate = $statementDate ?: date('Y-m-d');
$db->prepare(
    'UPDATE accounts SET last_reconciled_date = ?, last_reconciled_balance = ? WHERE id = ?'
)->execute([$reconDate, $statementBalance, $accountId]);

$n = count($ids);
$msg = $n > 0
    ? $n . ' transaction' . ($n === 1 ? '' : 's') . ' reconciled successfully.'
    : 'Account reconciled — no new transactions since last reconciliation.';
setFlash('success', $msg);
header('Location: ' . BASE_PATH . '/accounts/register?id=' . $accountId);
exit;
