<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!canEdit()) {
    setFlash('error', 'Permission denied.');
    header('Location: ' . BASE_PATH . '/bills/index');
    exit;
}

verifyCsrf();

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid request.');
    header('Location: ' . BASE_PATH . '/bills/index');
    exit;
}

try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM scheduled_bills WHERE id = ? AND is_active = 1');
    $stmt->execute([$id]);
    $bill = $stmt->fetch();

    if (!$bill) {
        setFlash('error', 'Scheduled item not found.');
        header('Location: ' . BASE_PATH . '/bills/index');
        exit;
    }

    // Allow caller to override amount and date
    $postAmt  = isset($_POST['amount']) && is_numeric($_POST['amount']) ? abs((float)$_POST['amount']) : null;
    $postDate = !empty($_POST['transaction_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['transaction_date'])
                ? $_POST['transaction_date'] : null;
    $useAmount = $postAmt  ?? abs((float)$bill['amount']);
    $useDate   = $postDate ?? $bill['next_due_date'];

    $db->beginTransaction();

    if ($bill['type'] === 'transfer') {
        // Create paired transfer transactions
        $ins = $db->prepare(
            'INSERT INTO transactions
             (account_id, type, transaction_date, payee, memo, amount, cleared_status, is_split)
             VALUES (?, \'transfer\', ?, ?, ?, ?, \'\', 0)'
        );
        // Withdrawal from source
        $ins->execute([
            $bill['account_id'],
            $useDate,
            $bill['name'],
            $bill['notes'] ?: null,
            -$useAmount,
        ]);
        $srcId = (int)$db->lastInsertId();
        // Deposit to destination
        $ins->execute([
            $bill['to_account_id'],
            $useDate,
            $bill['name'],
            $bill['notes'] ?: null,
            $useAmount,
        ]);
        $dstId = (int)$db->lastInsertId();
        // Link the pair
        $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?')->execute([$dstId, $srcId]);
        $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?')->execute([$srcId, $dstId]);
        // Assign system category to both legs
        $xferCatId = getSystemCategoryId('{Cash Transfer}');
        if ($xferCatId) {
            $insSplit = $db->prepare(
                'INSERT INTO transaction_splits (transaction_id, category_id, amount, memo) VALUES (?, ?, ?, ?)'
            );
            $insSplit->execute([$srcId, $xferCatId, -$useAmount, $bill['notes'] ?: '']);
            $insSplit->execute([$dstId, $xferCatId,  $useAmount, $bill['notes'] ?: '']);
        }
    } else {
        // Amount: bills are withdrawals (negative), deposits are positive
        $txnAmount = $bill['type'] === 'bill' ? -$useAmount : $useAmount;
        $txnType   = $bill['type'] === 'bill' ? 'withdrawal' : 'deposit';

        $ins = $db->prepare(
            'INSERT INTO transactions
             (account_id, type, transaction_date, payee, memo, amount, cleared_status, is_split)
             VALUES (?, ?, ?, ?, ?, ?, \'\', 0)'
        );
        $ins->execute([
            $bill['account_id'],
            $txnType,
            $useDate,
            $bill['name'],
            $bill['notes'] ?: null,
            $txnAmount,
        ]);
        $txnId = (int)$db->lastInsertId();

        // Insert split record if category assigned
        if ($bill['category_id']) {
            $db->prepare(
                'INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount, memo)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $txnId,
                $bill['category_id'],
                $bill['subcategory_id'] ?: null,
                $txnAmount,
                $bill['notes'] ?: '',
            ]);
        }
    }

    // Advance next_due_date based on frequency
    $nextDue = advanceDueDate($bill['next_due_date'], $bill['frequency']);

    if ($bill['frequency'] === 'once' || $nextDue === null) {
        // Deactivate one-time bills after posting
        $db->prepare('UPDATE scheduled_bills SET is_active = 0 WHERE id = ?')->execute([$id]);
    } else {
        $db->prepare('UPDATE scheduled_bills SET next_due_date = ? WHERE id = ?')
           ->execute([$nextDue, $id]);
    }

    $db->commit();
    setFlash('success', 'Transaction posted for "' . $bill['name'] . '".');

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    setFlash('error', 'Failed to post transaction.');
}

header('Location: ' . BASE_PATH . '/bills/index');
exit;
