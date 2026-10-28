<?php
/**
 * Inline edit of one budget line from the budget view (POST, JSON).
 * Fields: bc_id, amount (monthly/annual), due[] (annual), rollover
 * (monthly/variable), months[1..12] (variable). The entry type itself is
 * changed in the full editor.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_lib.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
verifyCsrf();
if (!canManageBudgets()) {
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to edit budgets.']);
    exit;
}

$db   = getDB();
$bcId = (int)($_POST['bc_id'] ?? 0);
$stmt = $db->prepare("SELECT id, entry_type FROM budget_categories WHERE id = ?");
$stmt->execute([$bcId]);
$line = $stmt->fetch();
if (!$line) {
    echo json_encode(['ok' => false, 'error' => 'Budget line not found.']);
    exit;
}

$num = function ($v): ?float {
    if (is_array($v)) return null;
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    return is_numeric($v) && (float)$v >= 0 ? round((float)$v, 2) : null;
};

$type = $line['entry_type'];
$db->beginTransaction();
try {
    if ($type === 'variable') {
        $months = is_array($_POST['months'] ?? null) ? $_POST['months'] : [];
        $upd = $db->prepare(
            "INSERT INTO budget_monthly_amounts (budget_category_id, month, amount) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount)"
        );
        for ($m = 1; $m <= 12; $m++) {
            $amt = $num($months[$m] ?? 0);
            if ($amt === null) throw new InvalidArgumentException('Monthly amounts must be numbers of 0 or more.');
            $upd->execute([$bcId, $m, $amt]);
        }
        $db->prepare("UPDATE budget_categories SET rollover = ? WHERE id = ?")
           ->execute([!empty($_POST['rollover']) ? 1 : 0, $bcId]);
    } else {
        $amount = $num($_POST['amount'] ?? 0);
        if ($amount === null) throw new InvalidArgumentException('Amount must be a number of 0 or more.');
        $due  = $type === 'annual' ? formatDueMonths($_POST['due'] ?? []) : null;
        $roll = $type !== 'annual' && !empty($_POST['rollover']) ? 1 : 0;
        $db->prepare("UPDATE budget_categories SET amount = ?, due_months = ?, rollover = ? WHERE id = ?")
           ->execute([$amount, $due, $roll, $bcId]);
    }
    $db->commit();
} catch (InvalidArgumentException $e) {
    $db->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true]);
