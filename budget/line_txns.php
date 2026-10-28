<?php
/**
 * Row drawer data for one budget line (GET bc_id, year, month; JSON):
 * the line from loadBudgetModel() (with last year), and the year-to-date
 * transactions that count toward it, signed in the line's direction.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_lib.php';
requireLogin();

header('Content-Type: application/json');

$db    = getDB();
$bcId  = (int)($_GET['bc_id'] ?? 0);
$year  = (int)($_GET['year']  ?? date('Y'));
$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));

$stmt = $db->prepare("SELECT budget_id FROM budget_categories WHERE id = ?");
$stmt->execute([$bcId]);
$budgetId = (int)$stmt->fetchColumn();
$model    = $budgetId ? loadBudgetModel($budgetId, $year, $month, ['with_untracked' => false, 'with_last_year' => true]) : null;
$line     = null;
foreach ($model['lines'] ?? [] as $l) if ($l['bc_id'] === $bcId) $line = $l;
if (!$line) {
    echo json_encode(['ok' => false, 'error' => 'Budget line not found.']);
    exit;
}

// Year-to-date splits that could belong to this line, then the same mapping
// the model uses keeps only the ones that do (a more specific budgeted
// subcategory wins over this line).
$txns = [];
if ($model['acct_ids']) {
    $lineByCat = [];
    foreach ($model['lines'] as $l) $lineByCat[$l['category_id']] = $l['bc_id'];
    $cid  = $line['category_id'];
    $aPhs = implode(',', array_fill(0, count($model['acct_ids']), '?'));
    $tStmt = $db->prepare(
        "SELECT t.id, t.account_id, a.name AS account_name, t.transaction_date, t.payee,
                ts.category_id, COALESCE(ts.subcategory_id, ts.category_id) AS leaf_id, lc.parent_id AS leaf_parent,
                lc.name AS leaf_name, ts.amount, COALESCE(NULLIF(ts.memo, ''), t.memo) AS memo
         FROM transaction_splits ts
         JOIN transactions t ON t.id = ts.transaction_id
         JOIN accounts a ON a.id = t.account_id
         LEFT JOIN categories lc ON lc.id = COALESCE(ts.subcategory_id, ts.category_id)
         WHERE t.transaction_date BETWEEN ? AND ?
           AND t.account_id IN ($aPhs)
           AND (ts.category_id = ? OR ts.subcategory_id = ? OR lc.parent_id = ?)
         ORDER BY t.transaction_date DESC, t.id DESC"
    );
    $monEnd = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $tStmt->execute([sprintf('%04d-01-01', $year), $monEnd, ...$model['acct_ids'], $cid, $cid, $cid]);
    $sign = $line['is_income'] ? 1 : -1;
    foreach ($tStmt->fetchAll() as $t) {
        $owner = budgetLineForSplit($lineByCat, (int)$t['category_id'], (int)$t['leaf_id'],
                                    $t['leaf_parent'] !== null ? (int)$t['leaf_parent'] : null);
        if ($owner !== $bcId) continue;
        $txns[] = [
            'id'       => (int)$t['id'],
            'date'     => $t['transaction_date'],
            'account'  => $t['account_name'],
            'url'      => BASE_PATH . '/accounts/register?id=' . (int)$t['account_id'] . '#txn-' . (int)$t['id'],
            'payee'    => $t['payee'],
            'category' => (int)$t['leaf_id'] !== $cid ? $t['leaf_name'] : '',
            'memo'     => (string)$t['memo'],
            'amount'   => round($sign * (float)$t['amount'], MONEY_DECIMALS),
        ];
    }
}

$m = $line['metrics'];
echo json_encode([
    'ok'   => true,
    'line' => [
        'bc_id'         => $line['bc_id'],
        'name'          => $line['display_name'],
        'is_income'     => $line['is_income'],
        'entry_type'    => $line['entry_type'],
        'amount'        => $line['amount'],
        'due_months'    => $line['due_months'],
        'rollover'      => $line['rollover'],
        'targets'       => array_values($line['targets']),
        'actuals'       => array_values($line['actuals']),
        'ly_actuals'    => array_values($line['ly_actuals']),
        'projected'     => array_values($line['projected']),
        'mon_budget'    => $line['mon_budget'],
        'carry_in'      => $line['carry_in'],
        'mon_available' => $line['mon_available'],
        'mon_actual'    => $line['mon_actual'],
        'annual_budget' => $line['annual_budget'],
        'expected'      => $line['expected_to_date'],
        'ytd_actual'    => $line['ytd_actual'],
        'ly_ytd'        => $line['ly_ytd'],
        'projected_total' => $line['projected_total'],
        'pct_used'      => $m['pctAnnualUsed'],
        'pace_label'    => $m['paceLabel'],
        'alert'         => $line['alert']['text'] ?? null,
    ],
    'year'  => $year,
    'month' => $month,
    'txns'  => $txns,
    'can_edit' => canManageBudgets(),
]);
