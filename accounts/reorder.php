<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
verifyCsrf();

header('Content-Type: application/json');

if (!canEdit()) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
    exit;
}

$ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
if (empty($ids)) {
    echo json_encode(['ok' => false, 'error' => 'No IDs provided.']);
    exit;
}

try {
    $db   = getDB();
    $stmt = $db->prepare('UPDATE accounts SET sort_order = ? WHERE id = ?');
    foreach (array_values($ids) as $pos => $id) {
        $stmt->execute([$pos + 1, $id]);
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
}
