<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!isAdmin()) {
    setFlash('error', 'Only administrators can delete scheduled items.');
    header('Location: ' . BASE_PATH . '/bills/index');
    exit;
}

verifyCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $db = getDB();
    $db->prepare('DELETE FROM scheduled_bills WHERE id = ?')->execute([$id]);
    setFlash('success', 'Scheduled item deleted.');
}

header('Location: ' . BASE_PATH . '/bills/index');
exit;
