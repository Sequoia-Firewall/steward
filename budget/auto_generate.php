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

$period       = $_POST['period'] ?? 'last_month';
$validPeriods = ['last_month', 'last_3mo', 'last_6mo', 'last_12mo'];
if (!in_array($period, $validPeriods, true)) $period = 'last_month';

$today = new DateTime();

// Build date range covering N complete calendar months ending last month
$months = match($period) {
    'last_month' => 1,
    'last_3mo'   => 3,
    'last_6mo'   => 6,
    'last_12mo'  => 12,
};

$rangeEnd   = (clone $today)->modify('last day of last month');
$rangeStart = (clone $today)->modify('first day of last month')
                             ->modify('-' . ($months - 1) . ' months');

$startDate = $rangeStart->format('Y-m-01');
$endDate   = $rangeEnd->format('Y-m-d');

$periodLabel = match($period) {
    'last_month' => (clone $today)->modify('first day of last month')->format('F Y'),
    'last_3mo'   => 'last 3 months',
    'last_6mo'   => 'last 6 months',
    'last_12mo'  => 'last 12 months',
};

$db     = getDB();
$userId = currentUserId();

// Compute average monthly amount per leaf category over the period.
// Groups by the effective (leaf) category using subcategory when present.
// Filters to income/expense categories only; excludes zero totals.
$stmt = $db->prepare(
    "SELECT COALESCE(ts.subcategory_id, ts.category_id) AS cat_id,
            ABS(SUM(ts.amount)) / :months AS avg_monthly
     FROM transaction_splits ts
     JOIN transactions t  ON t.id  = ts.transaction_id
     JOIN categories   c  ON c.id  = ts.category_id
     WHERE t.transaction_date BETWEEN :start AND :end
       AND c.type  IN ('income', 'expense')
       AND c.is_active = 1
       AND c.name  != '--Split--'
     GROUP BY cat_id
     HAVING avg_monthly > 0.005"
);
$stmt->execute([':months' => $months, ':start' => $startDate, ':end' => $endDate]);
$catAmounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Validate computed categories against active income/expense list
$validCatIds = array_flip(
    $db->query(
        "SELECT id FROM categories WHERE is_active = 1 AND type IN ('income','expense') AND name != '--Split--'"
    )->fetchAll(PDO::FETCH_COLUMN, 0)
);

// All active non-investment-cash accounts
$acctIds = array_column(
    $db->query("SELECT id FROM accounts WHERE is_active = 1 AND is_investment_cash = 0")->fetchAll(),
    'id'
);

$db->beginTransaction();
try {
    // Create the budget with a provisional name the user can rename in the wizard
    $db->prepare(
        "INSERT INTO budgets (name, show_on_dashboard, is_active, created_by) VALUES (?, 0, 1, ?)"
    )->execute(['Auto Budget', $userId]);
    $budgetId = (int)$db->lastInsertId();

    // Attach all active accounts
    $insAcct = $db->prepare("INSERT INTO budget_accounts (budget_id, account_id) VALUES (?, ?)");
    foreach ($acctIds as $aid) {
        $insAcct->execute([$budgetId, (int)$aid]);
    }

    // Insert categories with computed monthly amounts
    $insCat = $db->prepare(
        "INSERT INTO budget_categories (budget_id, category_id, entry_type, amount, show_on_dashboard)
         VALUES (?, ?, 'monthly', ?, 0)"
    );
    foreach ($catAmounts as $row) {
        $catId  = (int)$row['cat_id'];
        $amount = round((float)$row['avg_monthly'], 2);
        if ($amount <= 0 || !isset($validCatIds[$catId])) continue;
        $insCat->execute([$budgetId, $catId, $amount]);
    }

    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    setFlash('error', 'Error generating budget: ' . $e->getMessage());
    header('Location: ' . BASE_PATH . '/budget/index'); exit;
}

$catCount = count($catAmounts);
setFlash('success',
    'Budget pre-filled from ' . $periodLabel . ' — ' . $catCount . ' categor' . ($catCount === 1 ? 'y' : 'ies') . ' found. '
    . 'Set a name, review the amounts, and save when ready.'
);
header('Location: ' . BASE_PATH . '/budget/create?id=' . $budgetId);
exit;
