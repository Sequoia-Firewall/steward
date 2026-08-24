<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/users/index');
    exit;
}
verifyCsrf();

$db  = getDB();
$id  = (int)($_POST['id'] ?? 0);

if ($id === currentUserId()) {
    setFlash('error', 'You cannot delete your own account.');
    header('Location: ' . BASE_PATH . '/users/index');
    exit;
}

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();

if ($user && $user['role'] === 'administrator' && (int)$user['is_active'] === 1) {
    $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE role = ? AND is_active = 1 AND id != ?');
    $chk->execute(['administrator', $id]);
    if ((int)$chk->fetchColumn() === 0) {
        setFlash('error', 'Cannot delete the last active administrator.');
        header('Location: ' . BASE_PATH . '/users/index');
        exit;
    }
}

if ($user) {
    $txnChk = $db->prepare('SELECT COUNT(*) FROM transactions WHERE created_by = ?');
    $txnChk->execute([$id]);
    if ((int)$txnChk->fetchColumn() > 0) {
        setFlash('error', 'User "' . $user['username'] . '" has transaction history and cannot be deleted. Deactivate the account instead.');
        header('Location: ' . BASE_PATH . '/users/index');
        exit;
    }

    $db->prepare('DELETE FROM user_prefs WHERE user_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM dashboard_bookmarks WHERE user_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    setFlash('success', 'User "' . $user['username'] . '" deleted.');
} else {
    setFlash('error', 'User not found.');
}
header('Location: ' . BASE_PATH . '/users/index');
exit;
