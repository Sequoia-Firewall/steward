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

$id            = (int)($_POST['id']             ?? 0);
$name          = trim($_POST['name']            ?? '');
$targetAmount  = (float)($_POST['target_amount'] ?? 0);
$currentAmount = (float)($_POST['current_amount']?? 0);
$targetDate    = trim($_POST['target_date']     ?? '') ?: null;
$accountId     = (int)($_POST['account_id']     ?? 0) ?: null;
$notes         = trim($_POST['notes']           ?? '') ?: null;

if (!$name)            { echo json_encode(['ok' => false, 'error' => 'Goal name is required.']); exit; }
if ($targetAmount <= 0){ echo json_encode(['ok' => false, 'error' => 'Target amount must be greater than zero.']); exit; }

// If linked to an account, current_amount is ignored (computed from account balance)
if ($accountId) $currentAmount = 0;

try {
    $db = getDB();
    if ($id) {
        $db->prepare(
            'UPDATE savings_goals
             SET name=?, target_amount=?, current_amount=?, target_date=?, account_id=?, notes=?
             WHERE id=?'
        )->execute([$name, $targetAmount, $currentAmount, $targetDate, $accountId, $notes, $id]);
    } else {
        $db->prepare(
            'INSERT INTO savings_goals (name, target_amount, current_amount, target_date, account_id, notes, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$name, $targetAmount, $currentAmount, $targetDate, $accountId, $notes, currentUserId()]);
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
}
