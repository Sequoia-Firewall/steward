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

$investmentId = (int)($_POST['investment_id'] ?? 0);
if (!$investmentId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid investment.']);
    exit;
}

try {
    $db = getDB();
    $check = $db->prepare('SELECT id FROM investments WHERE id = ? AND is_active = 1');
    $check->execute([$investmentId]);
    if (!$check->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Investment not found.']);
        exit;
    }
    $del = $db->prepare('DELETE FROM investment_prices WHERE investment_id = ?');
    $del->execute([$investmentId]);
    echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Database error.']);
}
