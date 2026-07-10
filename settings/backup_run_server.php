<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
if (!hash_equals(csrfToken(), $_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token — please reload the page and try again.']);
    exit;
}

set_time_limit(0);

$result = createServerBackup();
if (!$result['ok']) {
    echo json_encode([
        'ok'                       => false,
        'status'                   => $result['status'] ?? 'backup_failed',
        'error'                    => $result['error'],
        'can_configure_backup_dir' => isAdmin(),
        'backup_settings_url'      => isAdmin() ? (BASE_PATH . '/settings/backup') : null,
    ]);
    exit;
}

logActivity('manual_backup', 'Manual server backup saved: ' . $result['file']);

echo json_encode([
    'ok'   => true,
    'file' => $result['file'],
    'size' => $result['size'],
]);

