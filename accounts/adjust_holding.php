<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if (!canEdit()) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
    exit;
}

verifyCsrf();

$accountId    = (int)($_POST['account_id']    ?? 0);
$investmentId = (int)($_POST['investment_id'] ?? 0);
$newQty       = (float)($_POST['new_qty']     ?? -1);

if (!$accountId || !$investmentId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}
if ($newQty < 0) {
    echo json_encode(['ok' => false, 'error' => 'Quantity cannot be negative.']);
    exit;
}

try {
    $db = getDB();

    // Verify account is an active investment account
    $acct = $db->prepare('SELECT id FROM accounts WHERE id = ? AND type = ? AND is_active = 1 AND is_investment_cash = 0');
    $acct->execute([$accountId, 'Investment']);
    if (!$acct->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Account not found.']);
        exit;
    }

    // Get current net quantity for this investment in this account
    $qStmt = $db->prepare(
        'SELECT COALESCE(SUM(CASE
             WHEN it.activity IN (\'buy\',\'add\',\'split\',\'reinvest_div\',\'reinvest_cap\') THEN  it.quantity
             WHEN it.activity IN (\'sell\',\'remove\')                                         THEN -it.quantity
             ELSE 0
         END), 0) AS net_qty,
         i.name AS inv_name
         FROM investments i
         JOIN investment_transactions it ON it.investment_id = i.id
         JOIN transactions t ON t.id = it.transaction_id
         WHERE i.id = ? AND i.is_active = 1 AND t.account_id = ?
         GROUP BY i.id, i.name'
    );
    $qStmt->execute([$investmentId, $accountId]);
    $row = $qStmt->fetch();

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Investment not found.']);
        exit;
    }

    $currentQty = (float)$row['net_qty'];
    $invName    = $row['inv_name'];
    $diff       = $newQty - $currentQty;

    if (abs($diff) < 0.000001) {
        echo json_encode(['ok' => true, 'message' => 'No change needed.']);
        exit;
    }

    $activity = $diff > 0 ? 'add' : 'remove';
    $qty      = abs($diff);
    $sign     = $diff > 0 ? '+' : '-';
    $memo     = 'Number of shares adjusted by user (' . $sign . rtrim(rtrim(number_format($qty, 6), '0'), '.') . ')';

    $db->beginTransaction();

    $db->prepare(
        'INSERT INTO transactions
         (account_id, transaction_date, payee, type, amount, cleared_status, memo, created_by)
         VALUES (?, CURDATE(), ?, \'investment\', 0, \'\', ?, ?)'
    )->execute([$accountId, $invName, $memo, currentUserId()]);
    $txnId = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO investment_transactions (transaction_id, investment_id, activity, quantity, price, commission)
         VALUES (?, ?, ?, ?, 0, 0)'
    )->execute([$txnId, $investmentId, $activity, $qty]);

    $db->commit();

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
}
