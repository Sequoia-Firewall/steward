<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/categories/index');
    exit;
}
verifyCsrf();

$db = getDB();

// Collect IDs: bulk (ids[]) or single (id)
$rawIds = [];
if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
    $rawIds = $_POST['ids'];
} elseif (!empty($_POST['id'])) {
    $rawIds = [$_POST['id']];
}

$ids = array_values(array_unique(array_map('intval', array_filter($rawIds))));

if (empty($ids)) {
    setFlash('error', 'No category selected.');
    header('Location: ' . BASE_PATH . '/categories/index');
    exit;
}

$deleted = 0;
foreach ($ids as $id) {
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $cat = $stmt->fetch();
    if (!$cat || !empty($cat['is_system'])) continue;

    $db->prepare('UPDATE transaction_splits SET category_id = NULL WHERE category_id = ?')->execute([$id]);
    $db->prepare('UPDATE transaction_splits SET subcategory_id = NULL WHERE subcategory_id = ?')->execute([$id]);
    $db->prepare('UPDATE categories SET is_active = 0 WHERE id = ?')->execute([$id]);
    $db->prepare('UPDATE categories SET is_active = 0 WHERE parent_id = ?')->execute([$id]);
    $deleted++;
}

$noun = $deleted === 1 ? 'category' : 'categories';
setFlash('success', "Deleted {$deleted} {$noun}.");

header('Location: ' . BASE_PATH . '/categories/index');
exit;
