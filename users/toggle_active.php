<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/users/index');
    exit;
}
verifyCsrf();

$db = getDB();
$id = (int)($_POST['id'] ?? 0);

if ($id === currentUserId()) {
    setFlash('error', 'You cannot deactivate your own account.');
    header('Location: ' . BASE_PATH . '/users/index');
    exit;
}

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: ' . BASE_PATH . '/users/index');
    exit;
}

$activating = (int)$user['is_active'] === 0;

if (!$activating && $user['role'] === 'administrator') {
    $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE role = ? AND is_active = 1 AND id != ?');
    $chk->execute(['administrator', $id]);
    if ((int)$chk->fetchColumn() === 0) {
        setFlash('error', 'Cannot deactivate — this is the last active administrator.');
        header('Location: ' . BASE_PATH . '/users/index');
        exit;
    }
}

$db->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$activating ? 1 : 0, $id]);
setFlash('success', 'User "' . $user['username'] . '" ' . ($activating ? 'activated' : 'deactivated') . '.');
header('Location: ' . BASE_PATH . '/users/index');
exit;
