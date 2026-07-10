<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/users/index');
    exit;
}
verifyCsrf();

$targetId = (int)($_POST['id'] ?? 0);
if ($targetId <= 0) {
    setFlash('error', 'Invalid user.');
    header('Location: ' . BASE_PATH . '/users/index');
    exit;
}

$db = getDB();
$user = $db->prepare('SELECT id, username FROM users WHERE id = ?');
$user->execute([$targetId]);
$user = $user->fetch();

if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: ' . BASE_PATH . '/users/index');
    exit;
}

$db->prepare('DELETE FROM user_prefs WHERE user_id = ?')->execute([$targetId]);

setFlash('success', 'Preferences reset for ' . $user['username'] . '.');
header('Location: ' . BASE_PATH . '/users/index');
exit;
