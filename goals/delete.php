<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!isAdmin()) {
    setFlash('error', 'Permission denied.');
    header('Location: ' . BASE_PATH . '/goals/index');
    exit;
}

verifyCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $db = getDB();
    $db->prepare('UPDATE savings_goals SET is_active = 0 WHERE id = ?')->execute([$id]);
    setFlash('success', 'Goal deleted.');
}

header('Location: ' . BASE_PATH . '/goals/index');
exit;
