<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!canManageBudgets()) { http_response_code(403); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/budget/index'); exit;
}
verifyCsrf();

$db       = getDB();
$userId   = currentUserId();
$budgetId = (int)($_POST['budget_id'] ?? 0);
$name     = trim($_POST['name'] ?? '');
$dashFlag = !empty($_POST['show_on_dashboard']) ? 1 : 0;
$active   = !empty($_POST['is_active']) ? 1 : 0;

if ($name === '') {
    setFlash('error', 'Budget name is required.');
    $back = $budgetId ? BASE_PATH . '/budget/create?id=' . $budgetId : BASE_PATH . '/budget/create';
    header('Location: ' . $back); exit;
}

// Validate account IDs
$rawAccts    = is_array($_POST['account_ids'] ?? null) ? array_map('intval', $_POST['account_ids']) : [];
$validAccts  = array_flip(array_column(
    $db->query("SELECT id FROM accounts WHERE is_active = 1 AND is_investment_cash = 0")->fetchAll(),
    'id'
));
$acctIds = array_values(array_filter($rawAccts, fn($id) => isset($validAccts[$id])));

// Validate category IDs
$rawCats    = is_array($_POST['cats'] ?? null) ? $_POST['cats'] : [];
$validCatIds = array_flip(array_column(
    $db->query("SELECT id FROM categories WHERE is_active = 1 AND type IN ('income','expense')")->fetchAll(),
    'id'
));

$db->beginTransaction();
try {
    if ($budgetId) {
        // Update existing budget
        $db->prepare(
            "UPDATE budgets SET name=?, show_on_dashboard=?, is_active=?, updated_at=NOW() WHERE id=?"
        )->execute([$name, $dashFlag, $active, $budgetId]);
        // Clear existing accounts and categories (cascade removes monthly amounts)
        $db->prepare("DELETE FROM budget_accounts WHERE budget_id = ?")->execute([$budgetId]);
        $db->prepare("DELETE FROM budget_categories WHERE budget_id = ?")->execute([$budgetId]);
    } else {
        // Create new budget
        $db->prepare(
            "INSERT INTO budgets (name, show_on_dashboard, is_active, created_by) VALUES (?,?,?,?)"
        )->execute([$name, $dashFlag, $active, $userId]);
        $budgetId = (int)$db->lastInsertId();
    }

    // Only one budget can feed the dashboard widget at a time — turning it on
    // here turns it off everywhere else, like a radio button.
    if ($dashFlag) {
        $db->prepare("UPDATE budgets SET show_on_dashboard = 0 WHERE id != ?")->execute([$budgetId]);
    }

    // Insert accounts
    $insAcct = $db->prepare("INSERT INTO budget_accounts (budget_id, account_id) VALUES (?,?)");
    foreach ($acctIds as $aid) {
        $insAcct->execute([$budgetId, $aid]);
    }

    // Insert categories
    $insCat = $db->prepare(
        "INSERT INTO budget_categories (budget_id, category_id, entry_type, amount, show_on_dashboard)
         VALUES (?,?,?,?,?)"
    );
    $insMon = $db->prepare(
        "INSERT INTO budget_monthly_amounts (budget_category_id, month, amount) VALUES (?,?,?)"
    );
    foreach ($rawCats as $catIdRaw => $data) {
        $catId = (int)$catIdRaw;
        if (!isset($validCatIds[$catId])) continue;
        if (empty($data['include'])) continue;

        $entryType = in_array($data['type'] ?? '', ['annual','monthly','variable'])
            ? $data['type'] : 'monthly';
        $amount    = $entryType !== 'variable' ? max(0, (float)($data['amount'] ?? 0)) : 0;
        $dashCat   = !empty($data['dashboard']) ? 1 : 0;

        $insCat->execute([$budgetId, $catId, $entryType, $amount, $dashCat]);
        $bcId = (int)$db->lastInsertId();

        if ($entryType === 'variable') {
            $monthData = is_array($data['months'] ?? null) ? $data['months'] : [];
            for ($m = 1; $m <= 12; $m++) {
                $amt = max(0, (float)($monthData[$m] ?? 0));
                $insMon->execute([$bcId, $m, $amt]);
            }
        }
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    setFlash('error', 'Error saving budget: ' . $e->getMessage());
    $back = BASE_PATH . '/budget/create' . ($budgetId ? '?id=' . $budgetId : '');
    header('Location: ' . $back); exit;
}

setFlash('success', 'Budget "' . $name . '" saved.');
header('Location: ' . BASE_PATH . '/budget/view?id=' . $budgetId);
exit;
