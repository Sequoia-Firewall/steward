<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

$name = $_GET['file'] ?? '';

// Validate: only allow expected filename pattern, no path components
if (!preg_match('/^steward-backup-\d{4}-\d{2}-\d{2}_\d{6}\.sql$/', $name)) {
    http_response_code(400);
    exit('Invalid filename');
}

$dir  = rtrim(getSetting('backup_dir', '/var/backups/steward'), '/');
$path = $dir . '/' . $name;

if (!file_exists($path)) {
    http_response_code(404);
    exit('File not found');
}

while (ob_get_level()) ob_end_clean();

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-cache, no-store, must-revalidate');

readfile($path);
