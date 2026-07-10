<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('user', 'administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

$db       = getDB();
$id         = (int)($_POST['cat_id']   ?? 0);
$name       = trim($_POST['name']      ?? '');
$parentId   = (int)($_POST['parent_id'] ?? 0);
$type       = $_POST['type'] ?? 'expense';
$taxRelated = !empty($_POST['tax_related']) ? 1 : 0;

if ($name === '') {
    echo json_encode(['ok' => false, 'error' => 'Name is required']);
    exit;
}
if (!in_array($type, ['income', 'expense'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid type']);
    exit;
}

// If parent is set, inherit its type
if ($parentId) {
    $pStmt = $db->prepare('SELECT type FROM categories WHERE id = ?');
    $pStmt->execute([$parentId]);
    $parent = $pStmt->fetch();
    if ($parent) $type = $parent['type'];
}

// Prevent making a category into a subcategory if it already has subcategories of its own
if ($parentId && $id) {
    $childCount = $db->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = ?');
    $childCount->execute([$id]);
    if ((int)$childCount->fetchColumn() > 0) {
        echo json_encode(['ok' => false, 'error' => 'This category has subcategories of its own and cannot be made into a subcategory. Reassign or remove its subcategories first.']);
        exit;
    }
}

if ($id) {
    // Block edits to system categories
    $sysChk = $db->prepare('SELECT is_system FROM categories WHERE id = ?');
    $sysChk->execute([$id]);
    if (!empty($sysChk->fetchColumn())) {
        echo json_encode(['ok' => false, 'error' => 'System categories cannot be modified.']);
        exit;
    }

    // Fetch current state before update
    $oldStmt = $db->prepare('SELECT parent_id FROM categories WHERE id = ?');
    $oldStmt->execute([$id]);
    $old = $oldStmt->fetch();
    $oldParentId = $old ? (int)($old['parent_id'] ?? 0) : 0;
    $newParentId = $parentId; // 0 means top-level

    // Fetch old type so we know if we need to cascade to subcategories
    $oldTypeStmt = $db->prepare('SELECT type FROM categories WHERE id = ?');
    $oldTypeStmt->execute([$id]);
    $oldType = $oldTypeStmt->fetchColumn();

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE categories SET name=?, parent_id=?, type=?, tax_related=? WHERE id=?')
           ->execute([$name, $newParentId ?: null, $type, $taxRelated, $id]);

        // Cascade type change to subcategories when the parent type changed
        if ($oldType !== $type) {
            $db->prepare('UPDATE categories SET type=? WHERE parent_id=?')
               ->execute([$type, $id]);
        }

        // Propagate hierarchy change to transaction_splits
        if ($oldParentId !== $newParentId) {
            if ($oldParentId === 0 && $newParentId !== 0) {
                // Was top-level, now a subcategory:
                // All splits using this as category_id → new parent becomes category, this becomes sub
                // (overwrites any existing subcategory_id, since 3-level hierarchy is not supported)
                $db->prepare(
                    'UPDATE transaction_splits
                     SET category_id = ?, subcategory_id = ?
                     WHERE category_id = ?'
                )->execute([$newParentId, $id, $id]);
            } elseif ($oldParentId !== 0 && $newParentId === 0) {
                // Was a subcategory, now top-level:
                // splits that stored this as subcategory → promote it to category_id, clear sub
                $db->prepare(
                    'UPDATE transaction_splits
                     SET category_id = ?, subcategory_id = NULL
                     WHERE subcategory_id = ?'
                )->execute([$id, $id]);
            } else {
                // Moved from one parent to another:
                // splits that stored this as subcategory → update category_id to new parent
                $db->prepare(
                    'UPDATE transaction_splits SET category_id = ? WHERE subcategory_id = ?'
                )->execute([$newParentId, $id]);
            }
        }

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
        exit;
    }
} else {
    $db->prepare('INSERT INTO categories (name, parent_id, type, tax_related, created_by) VALUES (?, ?, ?, ?, ?)')
       ->execute([$name, $parentId ?: null, $type, $taxRelated, currentUserId()]);
}

echo json_encode(['ok' => true]);
