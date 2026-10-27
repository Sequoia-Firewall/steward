<?php
// Transaction drill-down for reports/income_expense.php — returns the
// individual transactions behind a clicked Income/Expense amount, using the
// exact same filters (date range, accounts, category selection) as the report.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$db = getDB();

$type      = in_array($_GET['type'] ?? '', ['income', 'toinv'], true) ? $_GET['type'] : 'expense';
$startDate = $_GET['start'] ?? '';
$endDate   = $_GET['end']   ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date range']);
    exit;
}
if ($endDate < $startDate) [$startDate, $endDate] = [$endDate, $startDate];

// ── Account filter (same logic/params as the report) ────────────
require_once __DIR__ . '/../includes/report_acct_filter.php';

// ── Category filter (same logic/params as the report) ───────────
require_once __DIR__ . '/../includes/report_cat_filter.php';

[$topCats, $descendants] = loadCategoryFilterData($db, $type === 'toinv' ? 'expense' : $type);
$allTopIds = array_column($topCats, 'id');
$catParam  = trim($_GET[$type === 'income' ? 'inccats' : 'expcats'] ?? '');
[$selectedTopIds, $filteringCats] = parseCatTopSelection($catParam, $allTopIds);

$catWhere = ''; $catParams = [];
if ($filteringCats) {
    $catParams = expandCatTopSelection($selectedTopIds, $descendants);
    $ph        = implode(',', array_fill(0, count($catParams), '?'));
    $catWhere  = "AND ts.category_id IN ($ph)";
}

// Portfolio withdrawals (income) and contributions (To Investments), same rules as the
// report: on unless pw=0; withdrawals only when no income-category filter is active.
$countWithdrawals = ($_GET['pw'] ?? '1') !== '0';
$flowRows = [];
if ($countWithdrawals && ($type === 'toinv' || ($type === 'income' && !$filteringCats))) {
    foreach (getPortfolioFlows($startDate, $endDate, ['accounts' => $selectedAcctIds,
             'skip_income' => ($_GET['incinv'] ?? '') === '1']) as $f) {
        if (($type === 'income') !== ($f['amount'] > 0)) continue;
        $flowRows[] = [
            'id'            => $f['id'],
            'date'          => $f['date'],
            'payee'         => $f['payee'],
            'memo'          => $f['memo'],
            'account_id'    => $f['account_id'],
            'account_name'  => $f['account_name'],
            'category_name' => ($type === 'income' ? 'Portfolio Withdrawal from ' : 'To ') . $f['portfolio_account_name'],
            'amount'        => $f['amount'],
        ];
    }
}
if ($type === 'toinv') {
    echo json_encode([
        'ok' => true, 'type' => $type, 'start' => $startDate, 'end' => $endDate,
        'count' => count($flowRows), 'total' => array_sum(array_column($flowRows, 'amount')),
        'transactions' => $flowRows,
    ]);
    exit;
}

$stmt = $db->prepare(
    "SELECT t.id, t.transaction_date, t.payee, t.memo,
            a.id AS account_id, a.name AS account_name,
            c.name AS category_name, ts.amount
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories   c ON c.id = ts.category_id
     JOIN accounts     a ON a.id = t.account_id
     WHERE c.type = ?
       AND t.type != 'transfer'
       AND t.transaction_date BETWEEN ? AND ?
       $acctWhere
       $catWhere
     ORDER BY t.transaction_date, t.id"
);
$stmt->execute(array_merge([$type, $startDate, $endDate], $acctParams, $catParams));
$rows = $stmt->fetchAll();

$transactions = array_map(fn($r) => [
    'id'            => (int)$r['id'],
    'date'          => $r['transaction_date'],
    'payee'         => $r['payee'],
    'memo'          => $r['memo'],
    'account_id'    => (int)$r['account_id'],
    'account_name'  => $r['account_name'],
    'category_name' => $r['category_name'],
    'amount'        => (float)$r['amount'],
], $rows);

// ── Investment income (dividends/interest/cap-gain distributions) ──────
// Sourced from investment_transactions.activity, mirroring the report's own
// toggle — these have no category/split so the query above never sees them.
if ($type === 'income' && ($_GET['incinv'] ?? '') === '1') {
    $invStmt = $db->prepare(
        "SELECT t.id, t.transaction_date, i.name AS inv_name, it.activity,
                a.id AS account_id, a.name AS account_name,
                (CASE WHEN it.activity IN ('div','int') THEN ABS(t.amount)
                      ELSE it.quantity * it.price END) AS amount
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         JOIN investments  i ON i.id = it.investment_id
         JOIN accounts      a ON a.id = t.account_id
         WHERE it.activity IN ('reinvest_div', 'reinvest_cap', 'div', 'int')
           AND t.transaction_date BETWEEN ? AND ?
         ORDER BY t.transaction_date, t.id"
    );
    $invStmt->execute([$startDate, $endDate]);
    $invLabel = ['div' => 'Dividend', 'int' => 'Interest',
                 'reinvest_div' => 'Dividend (Reinvested)', 'reinvest_cap' => 'Cap Gain Dist. (Reinvested)'];
    foreach ($invStmt->fetchAll() as $r) {
        $transactions[] = [
            'id'            => (int)$r['id'],
            'date'          => $r['transaction_date'],
            'payee'         => $r['inv_name'],
            'memo'          => null,
            'account_id'    => (int)$r['account_id'],
            'account_name'  => $r['account_name'],
            'category_name' => $invLabel[$r['activity']] ?? 'Investment Income',
            'amount'        => (float)$r['amount'],
        ];
    }
}

$transactions = array_merge($transactions, $flowRows);

echo json_encode([
    'ok'           => true,
    'type'         => $type,
    'start'        => $startDate,
    'end'          => $endDate,
    'count'        => count($transactions),
    'total'        => array_sum(array_column($transactions, 'amount')),
    'transactions' => $transactions,
]);
