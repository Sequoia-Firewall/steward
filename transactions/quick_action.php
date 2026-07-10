<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
if (!canEdit()) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}
verifyCsrf();

$db     = getDB();
$action = trim($_POST['action'] ?? '');
$id     = (int)($_POST['id']     ?? 0);

$stmt = $db->prepare('SELECT * FROM transactions WHERE id = ?');
$stmt->execute([$id]);
$txn = $stmt->fetch();
if (!$txn) {
    echo json_encode(['ok' => false, 'error' => 'Transaction not found']);
    exit;
}

// ── Set Cleared Status ──────────────────────────────────────────
if ($action === 'set_cleared') {
    $status = $_POST['status'] ?? '';
    if (!in_array($status, ['', 'cleared', 'reconciled'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid status']);
        exit;
    }
    if (($status === 'reconciled' || $txn['cleared_status'] === 'reconciled') && !isAdmin()) {
        echo json_encode(['ok' => false, 'error' => 'Only admin can change reconciled status']);
        exit;
    }
    $db->prepare('UPDATE transactions SET cleared_status=?, updated_at=NOW() WHERE id=?')
       ->execute([$status, $id]);
    $label = $status === 'cleared' ? 'cleared' : ($status === 'reconciled' ? 'reconciled' : 'uncleared');
    logActivity('txn_edited', sprintf('Set cleared status to "%s" on txn #%d', $label, $id));
    echo json_encode(['ok' => true, 'new_status' => $status]);
    exit;
}

// ── Flip Debit / Credit ─────────────────────────────────────────
if ($action === 'flip_type') {
    if ($txn['type'] === 'transfer') {
        echo json_encode(['ok' => false, 'error' => 'Cannot flip a transfer transaction']);
        exit;
    }
    if ($txn['cleared_status'] === 'reconciled') {
        echo json_encode(['ok' => false, 'error' => 'Cannot flip a reconciled transaction']);
        exit;
    }
    $newType   = $txn['type'] === 'withdrawal' ? 'deposit' : 'withdrawal';
    $newAmount = -(float)$txn['amount'];
    $db->beginTransaction();
    $db->prepare('UPDATE transactions SET type=?, amount=?, updated_at=NOW() WHERE id=?')
       ->execute([$newType, $newAmount, $id]);
    // Splits must flip sign too, or sum(splits) desyncs from transactions.amount.
    $db->prepare('UPDATE transaction_splits SET amount = -amount WHERE transaction_id = ?')
       ->execute([$id]);
    $db->commit();
    logActivity('txn_edited', sprintf('Flipped txn #%d from %s to %s', $id, $txn['type'], $newType));
    echo json_encode(['ok' => true, 'new_type' => $newType]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
