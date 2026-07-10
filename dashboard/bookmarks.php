<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

$userId = currentUserId();
$action = $_POST['action'] ?? '';

function validateUrl(string $url): bool {
    $isAbsolute = (bool)filter_var($url, FILTER_VALIDATE_URL) && (bool)preg_match('/^https?:\/\//i', $url);
    $isRelative = str_starts_with($url, '/');
    return $isAbsolute || $isRelative;
}

ensureBookmarksTable();
$db = getDB();

switch ($action) {

    case 'add':
        $title = trim($_POST['title'] ?? '');
        $url   = trim($_POST['url']   ?? '');
        if ($title === '' || $url === '') {
            echo json_encode(['ok' => false, 'error' => 'Title and URL are required']);
            exit;
        }
        if (!validateUrl($url)) {
            echo json_encode(['ok' => false, 'error' => 'URL must start with http://, https://, or /']);
            exit;
        }
        $maxQ = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM dashboard_bookmarks WHERE user_id = ?');
        $maxQ->execute([$userId]);
        $sortOrder = (int)$maxQ->fetchColumn();
        $stmt = $db->prepare('INSERT INTO dashboard_bookmarks (user_id, title, url, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $title, $url, $sortOrder]);
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        break;

    case 'update':
        $id    = (int)($_POST['id']    ?? 0);
        $title = trim($_POST['title']  ?? '');
        $url   = trim($_POST['url']    ?? '');
        if ($id <= 0 || $title === '' || $url === '') {
            echo json_encode(['ok' => false, 'error' => 'Invalid data']);
            exit;
        }
        if (!validateUrl($url)) {
            echo json_encode(['ok' => false, 'error' => 'URL must start with http://, https://, or /']);
            exit;
        }
        $stmt = $db->prepare('UPDATE dashboard_bookmarks SET title = ?, url = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$title, $url, $id, $userId]);
        echo json_encode(['ok' => true]);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid id']);
            exit;
        }
        $stmt = $db->prepare('DELETE FROM dashboard_bookmarks WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        echo json_encode(['ok' => true]);
        break;

    case 'reorder':
        $rawIds = trim($_POST['ids'] ?? '');
        $ids    = array_values(array_filter(array_map('intval', explode(',', $rawIds))));
        if (empty($ids)) {
            echo json_encode(['ok' => false, 'error' => 'No ids provided']);
            exit;
        }
        $upd = $db->prepare('UPDATE dashboard_bookmarks SET sort_order = ? WHERE id = ? AND user_id = ?');
        foreach ($ids as $pos => $id) {
            $upd->execute([$pos, $id, $userId]);
        }
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
