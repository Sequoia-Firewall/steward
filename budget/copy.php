<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!canManageBudgets()) {
    setFlash('error', 'You do not have permission to create budgets.');
    header('Location: ' . BASE_PATH . '/budget/index'); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/budget/index'); exit;
}
verifyCsrf();

$db  = getDB();
$srcId = (int)($_POST['id'] ?? 0);

$src = $db->prepare("SELECT * FROM budgets WHERE id = ?");
$src->execute([$srcId]);
$budget = $src->fetch();
if (!$budget) {
    setFlash('error', 'Budget not found.');
    header('Location: ' . BASE_PATH . '/budget/index'); exit;
}

$db->beginTransaction();
try {
    // New budget: copy of the source, never auto-enabled as the dashboard budget
    // (avoids two budgets silently fighting over which one feeds the widget).
    $db->prepare(
        "INSERT INTO budgets (name, show_on_dashboard, is_active, created_by) VALUES (?, 0, ?, ?)"
    )->execute([$budget['name'] . ' (Copy)', $budget['is_active'], currentUserId()]);
    $newId = (int)$db->lastInsertId();

    $insAcct = $db->prepare("INSERT INTO budget_accounts (budget_id, account_id) VALUES (?, ?)");
    $acctStmt = $db->prepare("SELECT account_id FROM budget_accounts WHERE budget_id = ?");
    $acctStmt->execute([$srcId]);
    foreach ($acctStmt->fetchAll() as $a) {
        $insAcct->execute([$newId, $a['account_id']]);
    }

    $insCat = $db->prepare(
        "INSERT INTO budget_categories (budget_id, category_id, entry_type, amount, show_on_dashboard)
         VALUES (?, ?, ?, ?, ?)"
    );
    $insMon = $db->prepare(
        "INSERT INTO budget_monthly_amounts (budget_category_id, month, amount) VALUES (?, ?, ?)"
    );
    $catStmt = $db->prepare(
        "SELECT id, category_id, entry_type, amount, show_on_dashboard FROM budget_categories WHERE budget_id = ?"
    );
    $catStmt->execute([$srcId]);
    $monStmt = $db->prepare("SELECT month, amount FROM budget_monthly_amounts WHERE budget_category_id = ?");
    foreach ($catStmt->fetchAll() as $c) {
        $insCat->execute([$newId, $c['category_id'], $c['entry_type'], $c['amount'], $c['show_on_dashboard']]);
        $newBcId = (int)$db->lastInsertId();

        if ($c['entry_type'] === 'variable') {
            $monStmt->execute([$c['id']]);
            foreach ($monStmt->fetchAll() as $m) {
                $insMon->execute([$newBcId, $m['month'], $m['amount']]);
            }
        }
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    setFlash('error', 'Error copying budget: ' . $e->getMessage());
    header('Location: ' . BASE_PATH . '/budget/index'); exit;
}

setFlash('success', 'Copied "' . $budget['name'] . '" — review and save the new budget below.');
header('Location: ' . BASE_PATH . '/budget/create?id=' . $newId);
exit;
