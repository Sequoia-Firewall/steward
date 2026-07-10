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

$action = $_POST['action'] ?? '';
if (!in_array($action, ['preview', 'purge'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}

$db = getDB();

// Find purgeable category IDs: soft-deleted, not system, no transaction refs, no budget refs.
// Subcategories are evaluated individually; parent categories are only purgeable if none
// of their subcategories are blocking (have transactions or budget entries).
$purgeable = $db->query(
    "SELECT c.id, c.name, c.type, c.parent_id,
            p.name AS parent_name
     FROM categories c
     LEFT JOIN categories p ON p.id = c.parent_id
     WHERE c.is_active  = 0
       AND c.is_system  = 0
       AND c.id NOT IN (SELECT category_id    FROM transaction_splits WHERE category_id    IS NOT NULL)
       AND c.id NOT IN (SELECT subcategory_id FROM transaction_splits WHERE subcategory_id IS NOT NULL)
       AND c.id NOT IN (SELECT category_id    FROM budget_categories)
       AND NOT EXISTS (
           SELECT 1 FROM categories sub
           WHERE sub.parent_id = c.id
             AND (
               sub.id IN (SELECT category_id    FROM transaction_splits WHERE category_id    IS NOT NULL)
               OR sub.id IN (SELECT subcategory_id FROM transaction_splits WHERE subcategory_id IS NOT NULL)
               OR sub.id IN (SELECT category_id    FROM budget_categories)
             )
       )
     ORDER BY c.parent_id IS NULL, c.parent_id, c.name"
)->fetchAll();

if ($action === 'preview') {
    $items = array_map(fn($r) => [
        'name'   => $r['name'],
        'type'   => $r['type'],
        'parent' => $r['parent_name'],
    ], $purgeable);
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

// Purge — delete subcategories first to avoid FK parent_id conflicts
$ids       = array_column($purgeable, 'id');
$deleted   = 0;

if (!empty($ids)) {
    // Subcategories first (have a parent_id)
    $subs    = array_filter($purgeable, fn($r) => $r['parent_id'] !== null);
    $parents = array_filter($purgeable, fn($r) => $r['parent_id'] === null);

    $db->beginTransaction();
    try {
        foreach ([$subs, $parents] as $group) {
            foreach ($group as $row) {
                $stmt = $db->prepare('DELETE FROM categories WHERE id = ? AND is_active = 0 AND is_system = 0');
                $stmt->execute([$row['id']]);
                $deleted += $stmt->rowCount() ? 1 : 0;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        logActivity('maintenance_error', 'Purge categories failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Purge failed: ' . $e->getMessage()]);
        exit;
    }
}

logActivity('categories_purged', "Permanently deleted {$deleted} soft-deleted categories");
echo json_encode(['ok' => true, 'deleted' => $deleted]);
