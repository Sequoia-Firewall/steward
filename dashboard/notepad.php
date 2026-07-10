<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = $db->query(
        "SELECT dn.content, dn.updated_at, u.full_name AS updated_by_name
         FROM dashboard_notes dn
         LEFT JOIN users u ON u.id = dn.updated_by
         WHERE dn.id = 1"
    )->fetch();

    echo json_encode([
        'ok'         => true,
        'content'    => $row['content']         ?? '',
        'updated_at' => $row['updated_at']       ?? null,
        'updated_by' => $row['updated_by_name']  ?? null,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $content = $_POST['content'] ?? '';
    $userId  = currentUserId();
    $db->prepare(
        "UPDATE dashboard_notes SET content = ?, updated_by = ?, updated_at = NOW() WHERE id = 1"
    )->execute([$content, $userId]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
