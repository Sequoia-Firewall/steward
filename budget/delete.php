<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/budget/index'); exit;
}
verifyCsrf();

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $db   = getDB();
    $stmt = $db->prepare("SELECT name FROM budgets WHERE id = ?");
    $stmt->execute([$id]);
    $row  = $stmt->fetch();
    if ($row) {
        $db->prepare("DELETE FROM budgets WHERE id = ?")->execute([$id]);
        setFlash('success', 'Budget "' . $row['name'] . '" deleted.');
    }
}
header('Location: ' . BASE_PATH . '/budget/index');
exit;
