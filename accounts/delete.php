<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/accounts/index');
    exit;
}
verifyCsrf();

$id      = (int)($_POST['id'] ?? 0);
$account = getAccount($id);
if ($account) {
    $db = getDB();

    // Require zero balance before deletion
    $mainBalance = getAccountBalance($id);
    if (abs($mainBalance) >= 0.005) {
        setFlash('error', 'Cannot delete "' . $account['name'] . '" — balance must be $0.00 before deletion (current: ' . formatMoney($mainBalance) . ').');
        header('Location: ' . BASE_PATH . '/accounts/index');
        exit;
    }
    $linkedId = (int)($account['linked_account_id'] ?? 0);
    if ($linkedId) {
        $cashBalance = getAccountBalance($linkedId);
        if (abs($cashBalance) >= 0.005) {
            setFlash('error', 'Cannot delete "' . $account['name'] . '" — linked cash account balance must be $0.00 before deletion (current: ' . formatMoney($cashBalance) . ').');
            header('Location: ' . BASE_PATH . '/accounts/index');
            exit;
        }
    }

    // Collect all account IDs being deleted (the account itself + linked cash account)
    $deletingIds = [$id];
    if ($linkedId) {
        $deletingIds[] = $linkedId;
    }

    // Fix orphaned transfer partners on OTHER accounts before cascading:
    // Join through the deleted transaction to get the deleted account's name, then
    // convert the partner to a plain withdrawal/deposit and append a memo note.
    $ph = implode(',', array_fill(0, count($deletingIds), '?'));
    $db->prepare(
        "UPDATE transactions t
           JOIN transactions dt  ON dt.id  = t.transfer_pair_id
           JOIN accounts     da  ON da.id  = dt.account_id
            SET t.type             = CASE WHEN t.amount < 0 THEN 'withdrawal' ELSE 'deposit' END,
                t.transfer_pair_id = NULL,
                t.memo             = CONCAT(
                                       CASE WHEN t.memo != '' THEN CONCAT(t.memo, '; ') ELSE '' END,
                                       CASE WHEN t.amount < 0 THEN 'Transfer to' ELSE 'Transfer from' END,
                                       ' deleted account: ',
                                       da.name
                                     )
          WHERE dt.account_id IN ($ph)"
    )->execute($deletingIds);

    // Delete linked cash account first (no FK from accounts to accounts)
    if ($linkedId) {
        $db->prepare('DELETE FROM accounts WHERE id = ? AND is_investment_cash = 1')->execute([$linkedId]);
    }
    // Cascade delete handles transactions and splits via FK
    $db->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
    logActivity('account_deleted', 'Deleted ' . $account['type'] . ' account "' . $account['name'] . '"');
    setFlash('success', 'Account "' . $account['name'] . '" deleted.');
} else {
    setFlash('error', 'Account not found.');
}
header('Location: ' . BASE_PATH . '/accounts/index');
exit;
