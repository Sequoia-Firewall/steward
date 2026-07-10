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

$name = $_POST['file'] ?? '';

// Validate: only allow expected filename pattern, no path components
if (!preg_match('/^steward-backup-\d{4}-\d{2}-\d{2}_\d{6}\.sql$/', $name)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid filename']);
    exit;
}

$dir  = rtrim(getSetting('backup_dir', '/var/backups/steward'), '/');
$path = $dir . '/' . $name;

if (!file_exists($path)) {
    echo json_encode(['ok' => false, 'error' => 'File not found']);
    exit;
}

if (!unlink($path)) {
    echo json_encode(['ok' => false, 'error' => 'Could not delete file']);
    exit;
}

logActivity('backup_delete', "Deleted stored backup: $name");
echo json_encode(['ok' => true]);
