<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!isAdmin()) {
    setFlash('error', 'Only administrators can delete investments.');
    header('Location: ' . BASE_PATH . '/portfolio/index');
    exit;
}

verifyCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $db = getDB();
    $db->prepare('UPDATE investments SET is_active = 0 WHERE id = ?')->execute([$id]);
    setFlash('success', 'Investment removed from portfolio.');
}

header('Location: ' . BASE_PATH . '/portfolio/index');
exit;
