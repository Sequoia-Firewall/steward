<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$id = max(0, (int)($_GET['id'] ?? 0));
if (!$id) {
    header('Location: ' . BASE_PATH . '/reports/index');
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT url, graph_config FROM favorite_reports WHERE id = ? AND type = 'saved' LIMIT 1");
$stmt->execute([$id]);
$row  = $stmt->fetch();

if (!$row) {
    header('Location: ' . BASE_PATH . '/reports/index');
    exit;
}

$target = BASE_PATH . $row['url'];
if (!empty($row['graph_config'])) {
    $sep     = strpos($row['url'], '?') !== false ? '&' : '?';
    $target .= $sep . 'saved_id=' . $id;
}
header('Location: ' . $target, true, 302);
exit;
