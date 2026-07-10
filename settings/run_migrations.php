<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals(csrfToken(), $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$pending = getPendingMigrations();
if (empty($pending)) {
    echo json_encode(['ok' => true, 'applied' => [], 'timestamp' => date('Y-m-d H:i:s')]);
    exit;
}

$applied = [];
$errors  = [];

foreach ($pending as $ver => $path) {
    $result = runMigration($ver, $path);
    if ($result['ok']) {
        $applied[] = $ver;
    } else {
        $errors[] = 'v' . $ver . ': ' . implode('; ', $result['errors']);
    }
}

if (!empty($errors)) {
    echo json_encode([
        'ok'        => false,
        'applied'   => $applied,
        'timestamp' => date('Y-m-d H:i:s'),
        'error'     => implode(' | ', $errors),
    ]);
} else {
    unset($_SESSION['mig_ok_ts']); // Force re-check on next request so guard clears immediately
    echo json_encode([
        'ok'        => true,
        'applied'   => $applied,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
}
