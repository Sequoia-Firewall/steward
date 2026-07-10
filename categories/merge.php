<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/categories/index');
    exit;
}
verifyCsrf();

$db       = getDB();
$sourceId = (int)($_POST['source_id'] ?? 0);
$targetId = (int)($_POST['target_id'] ?? 0);

if (!$sourceId || !$targetId || $sourceId === $targetId) {
    setFlash('error', 'Invalid merge request.');
    header('Location: ' . BASE_PATH . '/categories/index');
    exit;
}

$stmt = $db->prepare('SELECT * FROM categories WHERE id = ? AND is_active = 1');
$stmt->execute([$sourceId]);
$source = $stmt->fetch();
$stmt->execute([$targetId]);
$target = $stmt->fetch();

if (!$source || !$target) {
    setFlash('error', 'Category not found.');
    header('Location: ' . BASE_PATH . '/categories/index');
    exit;
}

// Determine which (category_id, subcategory_id) slot the target occupies in transaction_splits
$targetParentId = $target['parent_id'] ? (int)$target['parent_id'] : null;
$targetCatId    = $targetParentId ?? (int)$target['id'];
$targetSubId    = $targetParentId ? (int)$target['id'] : null;

$db->beginTransaction();
try {
    // Splits where source is the category (no subcategory) → remap fully to target
    $db->prepare(
        'UPDATE transaction_splits SET category_id=?, subcategory_id=?
         WHERE category_id=? AND subcategory_id IS NULL'
    )->execute([$targetCatId, $targetSubId, $sourceId]);

    // Splits where source is the category AND a subcategory is already set → remap category only
    $db->prepare(
        'UPDATE transaction_splits SET category_id=?
         WHERE category_id=? AND subcategory_id IS NOT NULL'
    )->execute([$targetCatId, $sourceId]);

    // Splits where source is the subcategory → remap fully to target
    $db->prepare(
        'UPDATE transaction_splits SET category_id=?, subcategory_id=?
         WHERE subcategory_id=?'
    )->execute([$targetCatId, $targetSubId, $sourceId]);

    // Re-parent source's children (categories are max 2 levels deep)
    if ($targetSubId === null) {
        // Target is top-level: children become children of target
        $db->prepare('UPDATE categories SET parent_id=? WHERE parent_id=? AND is_active=1')
           ->execute([$targetId, $sourceId]);
    } else {
        // Target is a subcategory: move children up to target's parent
        $db->prepare('UPDATE categories SET parent_id=? WHERE parent_id=? AND is_active=1')
           ->execute([$targetParentId, $sourceId]);
    }

    // Deactivate source
    $db->prepare('UPDATE categories SET is_active=0 WHERE id=?')->execute([$sourceId]);

    $db->commit();
    setFlash('success', 'Merged "' . $source['name'] . '" into "' . $target['name'] . '".');
} catch (Exception $e) {
    $db->rollBack();
    setFlash('error', 'Merge failed: ' . $e->getMessage());
}

header('Location: ' . BASE_PATH . '/categories/index');
exit;
