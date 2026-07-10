<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

header('Content-Type: application/json');

$dir = rtrim(getSetting('backup_dir', '/var/backups/steward'), '/');

if (!is_dir($dir)) {
    echo json_encode(['ok' => true, 'files' => [], 'dir_ok' => false]);
    exit;
}

$files = glob($dir . '/steward-backup-*.sql') ?: [];
usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

$result = [];
foreach ($files as $path) {
    $name = basename($path);
    // Parse date from filename: steward-backup-YYYY-MM-DD_HHMMSS.sql
    $ts = filemtime($path);
    if (preg_match('/steward-backup-(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})\.sql$/', $name, $m)) {
        $ts = strtotime($m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . $m[4]);
    }
    $result[] = [
        'name'    => $name,
        'size'    => filesize($path),
        'ts'      => $ts,
        'display' => date('M j, Y g:i A', $ts),
    ];
}

echo json_encode(['ok' => true, 'files' => $result, 'dir_ok' => true]);
