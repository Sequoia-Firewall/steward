<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

$configFile = __DIR__ . '/../config/database.php';

if (!file_exists($configFile) || !is_readable($configFile)) {
    http_response_code(404);
    exit('Configuration file not found.');
}

$filename = 'database.php';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($configFile));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($configFile);
exit;
