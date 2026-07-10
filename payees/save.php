<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

if (!canEdit()) {
    echo json_encode(['ok' => false, 'error' => 'Permission denied']); exit;
}

verifyCsrf();

$action = $_POST['action'] ?? '';
$db     = getDB();

// ── Save / update payee profile ────────────────────────────────
if ($action === 'save') {
    $name    = trim($_POST['name']           ?? '');
    $addr    = trim($_POST['address']        ?? '');
    $phone   = trim($_POST['phone']          ?? '');
    $website = trim($_POST['website']        ?? '');
    $acctNum = trim($_POST['account_number'] ?? '');
    $note    = trim($_POST['note']           ?? '');
    $catId   = ($_POST['category_id']    ?? '') !== '' ? (int)$_POST['category_id']    : null;
    $subId   = ($_POST['subcategory_id'] ?? '') !== '' ? (int)$_POST['subcategory_id'] : null;

    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Payee name is required.']); exit; }

    // Upsert
    $existing = $db->prepare('SELECT id FROM payees WHERE name = ?');
    $existing->execute([$name]);
    $row = $existing->fetch();

    if ($row) {
        $stmt = $db->prepare(
            'UPDATE payees SET address=?, phone=?, website=?, account_number=?, note=?,
                               category_id=?, subcategory_id=?, updated_at=NOW()
             WHERE name=?'
        );
        $stmt->execute([$addr, $phone, $website, $acctNum, $note, $catId, $subId, $name]);
        echo json_encode(['ok' => true, 'id' => (int)$row['id']]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO payees (name, address, phone, website, account_number, note, category_id, subcategory_id)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$name, $addr, $phone, $website, $acctNum, $note, $catId, $subId]);
        echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }

// ── Rename payee ───────────────────────────────────────────────
} elseif ($action === 'rename') {
    $oldName = trim($_POST['old_name'] ?? '');
    $newName = trim($_POST['new_name'] ?? '');

    if (!$oldName || !$newName) {
        echo json_encode(['ok'=>false,'error'=>'Both old and new name required.']); exit;
    }
    if ($oldName === $newName) {
        echo json_encode(['ok'=>false,'error'=>'New name is the same as the current name.']); exit;
    }

    // Check name not already taken
    $clash = $db->prepare('SELECT id FROM payees WHERE name = ?');
    $clash->execute([$newName]);
    if ($clash->fetch()) {
        echo json_encode(['ok'=>false,'error'=>'A payee profile with that name already exists. Use Merge instead.']); exit;
    }

    $db->beginTransaction();
    try {
        // Update transactions
        $db->prepare('UPDATE transactions SET payee = ? WHERE payee = ?')->execute([$newName, $oldName]);
        // Update payees table (may not exist yet — ignore if not found)
        $db->prepare('UPDATE payees SET name = ? WHERE name = ?')->execute([$newName, $oldName]);
        $db->commit();

        $count = $db->prepare('SELECT COUNT(*) FROM transactions WHERE payee = ?');
        $count->execute([$newName]);
        echo json_encode(['ok' => true, 'txn_count' => (int)$count->fetchColumn()]);
    } catch (Throwable $e) {
        $db->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }

// ── Merge payees ───────────────────────────────────────────────
} elseif ($action === 'merge') {
    $sourceName = trim($_POST['source_name'] ?? '');
    $targetName = trim($_POST['target_name'] ?? '');

    if (!$sourceName || !$targetName) {
        echo json_encode(['ok'=>false,'error'=>'Source and target required.']); exit;
    }
    if ($sourceName === $targetName) {
        echo json_encode(['ok'=>false,'error'=>'Source and target cannot be the same.']); exit;
    }

    $db->beginTransaction();
    try {
        // Move all transactions from source → target
        $db->prepare('UPDATE transactions SET payee = ? WHERE payee = ?')->execute([$targetName, $sourceName]);
        // Delete source profile (target profile, if any, is kept)
        $db->prepare('DELETE FROM payees WHERE name = ?')->execute([$sourceName]);
        $db->commit();

        $count = $db->prepare('SELECT COUNT(*) FROM transactions WHERE payee = ?');
        $count->execute([$targetName]);
        echo json_encode(['ok' => true, 'txn_count' => (int)$count->fetchColumn()]);
    } catch (Throwable $e) {
        $db->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }

// ── Delete payee profile (not transactions) ────────────────────
} elseif ($action === 'delete_profile') {
    $name = trim($_POST['name'] ?? '');
    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Name required.']); exit; }
    $db->prepare('DELETE FROM payees WHERE name = ?')->execute([$name]);
    echo json_encode(['ok' => true]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
}
