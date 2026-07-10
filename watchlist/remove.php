<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if (!canEdit()) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
    exit;
}

verifyCsrf();

$id = (int)($_POST['investment_id'] ?? 0);
if (!$id) {
    echo json_encode(['ok' => false, 'error' => 'Invalid investment.']);
    exit;
}

try {
    getDB()->prepare('UPDATE investments SET in_watchlist = 0 WHERE id = ?')->execute([$id]);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
}
