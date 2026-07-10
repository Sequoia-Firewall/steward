<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
verifyCsrf();

$rangeDays = ['30' => 30, '60' => 60, '365' => 365, 'all' => null];
$range     = $_POST['range'] ?? '';
if (!array_key_exists($range, $rangeDays)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid range']);
    exit;
}
$days = $rangeDays[$range];

$db = getDB();

if ($days === null) {
    $deleted = (int)$db->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();
    $db->exec('DELETE FROM activity_log');
    $desc = "Administrator deleted all activity log entries ({$deleted} record" . ($deleted !== 1 ? 's' : '') . ")";
} else {
    $stmt = $db->prepare('SELECT COUNT(*) FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$days]);
    $deleted = (int)$stmt->fetchColumn();

    $del = $db->prepare('DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $del->execute([$days]);
    $desc = "Administrator deleted activity log entries older than {$days} days ({$deleted} record" . ($deleted !== 1 ? 's' : '') . ")";
}

logActivity('activity_log_purged', $desc);

echo json_encode(['ok' => true, 'deleted' => $deleted]);
