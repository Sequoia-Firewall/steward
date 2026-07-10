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

$db   = getDB();
$stmt = $db->prepare('SELECT * FROM scheduled_bills WHERE id = ? AND is_active = 1');
$stmt->execute([$id]);
$bill = $stmt->fetch();

if (!$bill) {
    setFlash('error', 'Scheduled item not found.');
    header('Location: ' . BASE_PATH . '/bills/index');
    exit;
}

$nextDue = advanceDueDate($bill['next_due_date'], $bill['frequency']);

if ($bill['frequency'] === 'once' || $nextDue === null) {
    $db->prepare('UPDATE scheduled_bills SET is_active = 0 WHERE id = ?')->execute([$id]);
} else {
    $db->prepare('UPDATE scheduled_bills SET next_due_date = ? WHERE id = ?')
       ->execute([$nextDue, $id]);
}

logActivity('bill_skipped', sprintf('Skipped "%s" — advanced from %s to %s',
    $bill['name'], $bill['next_due_date'], $nextDue ?? 'deactivated'));

setFlash('success', 'Skipped "' . $bill['name'] . '" — next due ' . ($nextDue ? formatDate($nextDue) : 'N/A (deactivated)') . '.');
header('Location: ' . BASE_PATH . '/bills/index');
exit;
