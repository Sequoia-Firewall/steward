<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

verifyCsrf();

$action = $_POST['action'] ?? '';
$db     = getDB();

if ($action === 'add') {
    $title = trim($_POST['title'] ?? '');
    $url   = trim($_POST['url']   ?? '');
    $icon  = trim($_POST['icon']  ?? 'bi-file-earmark-bar-graph');
    $type  = in_array($_POST['type'] ?? '', ['dashboard','saved']) ? $_POST['type'] : 'dashboard';

    if (!$title || !$url) {
        echo json_encode(['ok' => false, 'error' => 'Title and URL are required.']);
        exit;
    }

    // Sanitise: keep only known icon names (alphanumeric + dash)
    if (!preg_match('/^bi-[a-z0-9\-]+$/', $icon)) {
        $icon = 'bi-file-earmark-bar-graph';
    }

    // Validate optional graph config JSON
    $graphConfig = null;
    $rawGC = trim($_POST['graph_config'] ?? '');
    if ($rawGC) {
        json_decode($rawGC);
        if (json_last_error() === JSON_ERROR_NONE) $graphConfig = $rawGC;
    }

    // Deduplicate by URL + type; update graph_config if provided
    $existing = $db->prepare('SELECT id FROM favorite_reports WHERE url = ? AND type = ? LIMIT 1');
    $existing->execute([$url, $type]);
    if ($row = $existing->fetch()) {
        if ($graphConfig !== null) {
            $db->prepare('UPDATE favorite_reports SET graph_config = ? WHERE id = ?')
               ->execute([$graphConfig, (int)$row['id']]);
        }
        echo json_encode(['ok' => true, 'id' => (int)$row['id'], 'already_existed' => true]);
        exit;
    }

    $maxOrder = $db->query('SELECT COALESCE(MAX(sort_order),0) FROM favorite_reports')->fetchColumn();
    $stmt = $db->prepare('INSERT INTO favorite_reports (title, url, icon, type, sort_order, graph_config) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$title, $url, $icon, $type, (int)$maxOrder + 1, $graphConfig]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);

} elseif ($action === 'rename') {
    $id    = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    if (!$id || !$title) {
        echo json_encode(['ok' => false, 'error' => 'ID and title required.']);
        exit;
    }
    $rawGC = trim($_POST['graph_config'] ?? '');
    $graphConfig = null;
    if ($rawGC) {
        json_decode($rawGC);
        if (json_last_error() === JSON_ERROR_NONE) $graphConfig = $rawGC;
    }
    if ($graphConfig !== null) {
        $db->prepare('UPDATE favorite_reports SET title = ?, graph_config = ? WHERE id = ? AND type = ?')
           ->execute([$title, $graphConfig, $id, 'saved']);
    } else {
        $db->prepare('UPDATE favorite_reports SET title = ? WHERE id = ? AND type = ?')
           ->execute([$title, $id, 'saved']);
    }
    echo json_encode(['ok' => true, 'id' => $id]);

} elseif ($action === 'remove') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        echo json_encode(['ok' => false, 'error' => 'Invalid ID.']);
        exit;
    }
    $stmt = $db->prepare('DELETE FROM favorite_reports WHERE id = ?');
    $stmt->execute([$id]);
    echo json_encode(['ok' => true]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
}
