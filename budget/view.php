<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// All budgets for the selector
$allBudgets = $db->query(
    "SELECT id, name FROM budgets WHERE is_active = 1 ORDER BY name"
)->fetchAll();

$budgetId = (int)($_GET['id'] ?? ($allBudgets[0]['id'] ?? 0));

// If a non-active budget is requested, fetch it anyway
$bStmt = $db->prepare("SELECT * FROM budgets WHERE id = ?");
$bStmt->execute([$budgetId]);
$budget = $bStmt->fetch();
if (!$budget) {
    setFlash('error', 'Budget not found.');
    header('Location: ' . BASE_PATH . '/budget/index'); exit;
}

if (isset($_GET['show_inv_income'])) {
    $_SESSION['budget_show_inv_income'] = ($_GET['show_inv_income'] === '1');
}
$showInvIncome = (bool)($_SESSION['budget_show_inv_income'] ?? false);

$today   = new DateTime();
$selYear = (int)($_GET['year']  ?? $today->format('Y'));
$selMon  = (int)($_GET['month'] ?? $today->format('n'));
$selMon  = max(1, min(12, $selMon));

$monStart = sprintf('%04d-%02d-01', $selYear, $selMon);
$monEnd   = date('Y-m-t', strtotime($monStart));
$ytdStart = sprintf('%04d-01-01', $selYear);
$ytdEnd   = $monEnd;

// Available years (transactions + current)
$years = $db->query(
    "SELECT DISTINCT YEAR(transaction_date) AS yr FROM transactions ORDER BY yr DESC"
)->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($today->format('Y'), $years)) $years[] = (int)$today->format('Y');
rsort($years);

// Budget accounts
$acctStmt = $db->prepare("SELECT account_id FROM budget_accounts WHERE budget_id = ?");
$acctStmt->execute([$budgetId]);
$acctIds = array_column($acctStmt->fetchAll(), 'account_id');

// Budget categories with monthly amounts (join parent for display names)
$cStmt = $db->prepare(
    "SELECT bc.id AS bc_id, bc.category_id, bc.entry_type, bc.amount,
            c.name, c.type AS cat_type, c.parent_id,
            p.name AS parent_name,
            GROUP_CONCAT(bma.month ORDER BY bma.month SEPARATOR ',') AS months,
            GROUP_CONCAT(bma.amount ORDER BY bma.month SEPARATOR ',') AS month_amounts
     FROM budget_categories bc
     JOIN categories c ON c.id = bc.category_id
     LEFT JOIN categories p ON p.id = c.parent_id
     LEFT JOIN budget_monthly_amounts bma ON bma.budget_category_id = bc.id
     WHERE bc.budget_id = ?
     GROUP BY bc.id
     ORDER BY c.type, COALESCE(p.name, c.name), c.name"
);
$cStmt->execute([$budgetId]);

$incomeCats  = [];
$expenseCats = [];
foreach ($cStmt->fetchAll() as $r) {
    // Rebuild monthly amounts map
    $mMap = [];
    if ($r['months']) {
        $mons = explode(',', $r['months']);
        $amts = explode(',', $r['month_amounts']);
        foreach ($mons as $i => $m) {
            $mMap[(int)$m] = (float)$amts[$i];
        }
    }
    $r['month_map']    = $mMap;
    $r['display_name'] = $r['parent_name'] ? $r['parent_name'] . ': ' . $r['name'] : $r['name'];

    // Monthly budget amount for selected month
    $r['mon_budget'] = match($r['entry_type']) {
        'annual'   => (float)$r['amount'] / 12,
        'variable' => $mMap[$selMon] ?? 0,
        default    => (float)$r['amount'],
    };

    // Full annual budget target — all 12 months, independent of the selected month.
    // This is the number annual-compliance tracking (pace, % used) is measured against.
    $r['annual_budget'] = match($r['entry_type']) {
        'annual'   => (float)$r['amount'],
        'variable' => array_sum(array_map(fn($m) => $mMap[$m] ?? 0, range(1, 12))),
        default    => (float)$r['amount'] * 12,
    };

    // Expected-to-date budget: prorated Jan-through-selected-month target, used only
    // as the "where you should be" pace reference — not the annual total.
    if ($r['entry_type'] === 'annual') {
        $r['expected_to_date'] = (float)$r['amount'] / 12 * $selMon;
    } elseif ($r['entry_type'] === 'variable') {
        $ytdSum = 0;
        for ($m = 1; $m <= $selMon; $m++) $ytdSum += $mMap[$m] ?? 0;
        $r['expected_to_date'] = $ytdSum;
    } else {
        $r['expected_to_date'] = (float)$r['amount'] * $selMon;
    }

    if ($r['cat_type'] === 'income') $incomeCats[]  = $r;
    else                             $expenseCats[]  = $r;
}

// Compute actuals
// Splits store parent in category_id and child in subcategory_id, so we group
// by the effective leaf (COALESCE) and fall back to category_id for parent-level budgets.
function fetchActuals(PDO $db, array $acctIds, array $cats, string $start, string $end): array {
    if (empty($acctIds) || empty($cats)) return [];
    $catIds  = array_column($cats, 'category_id');
    $catSet  = array_flip($catIds);
    $aPhs    = implode(',', array_fill(0, count($acctIds), '?'));
    $cPhs    = implode(',', array_fill(0, count($catIds), '?'));
    $stmt    = $db->prepare(
        "SELECT ts.category_id,
                COALESCE(ts.subcategory_id, ts.category_id) AS eff_cat_id,
                SUM(ts.amount) AS total
         FROM transaction_splits ts
         JOIN transactions t ON t.id = ts.transaction_id
         WHERE t.transaction_date BETWEEN ? AND ?
           AND t.account_id IN ($aPhs)
           AND (ts.category_id IN ($cPhs) OR ts.subcategory_id IN ($cPhs))
         GROUP BY ts.category_id, COALESCE(ts.subcategory_id, ts.category_id)"
    );
    $stmt->execute([$start, $end, ...$acctIds, ...$catIds, ...$catIds]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $eid = (int)$r['eff_cat_id'];
        $cid = (int)$r['category_id'];
        // Prefer the leaf category if it is in the budget; otherwise credit the parent.
        $key = isset($catSet[$eid]) ? $eid : (isset($catSet[$cid]) ? $cid : null);
        if ($key !== null) {
            $map[$key] = ($map[$key] ?? 0.0) + (float)$r['total'];
        }
    }
    return $map;
}

// ── Annual-compliance metrics (shared by HTML render + CSV export) ─────
// Pace = how far through the year we are (by elapsed months), compared against
// how much of the annual budget has actually been used/earned to date.
function budgetBarClass(?float $rawPct, bool $isIncome): string {
    if ($rawPct === null) return 'bar-none';
    if ($isIncome) return $rawPct >= 100 ? 'bar-ok' : ($rawPct >= 80 ? 'bar-warn' : 'bar-over');
    return $rawPct > 100 ? 'bar-over' : ($rawPct >= 80 ? 'bar-warn' : 'bar-ok');
}

function computeBudgetRow(array $c, float $monActual, float $ytdActual, bool $isIncome, int $selMon): array {
    $monBudget    = (float)$c['mon_budget'];
    $annualBudget = (float)$c['annual_budget'];
    $expected     = (float)$c['expected_to_date'];

    $monDiff         = round($isIncome ? ($monActual - $monBudget) : ($monBudget - $monActual), MONEY_DECIMALS);
    $annualRemaining = round($annualBudget - $ytdActual, MONEY_DECIMALS);

    $pctYearElapsed = $selMon / 12 * 100;
    $pctAnnualUsed  = $annualBudget > 0 ? ($ytdActual / $annualBudget * 100) : null;

    // Normalize so positive paceDiff always means "bad" regardless of income/expense.
    $paceDiff = $pctAnnualUsed !== null
        ? ($isIncome ? ($pctYearElapsed - $pctAnnualUsed) : ($pctAnnualUsed - $pctYearElapsed))
        : null;

    if ($paceDiff === null) {
        $paceLabel = null; $paceCls = 'bv-pace-none';
    } elseif ($paceDiff > 15) {
        $paceLabel = $isIncome ? 'Behind Pace' : 'Over Pace';    $paceCls = 'bv-pace-bad';
    } elseif ($paceDiff > 5) {
        $paceLabel = $isIncome ? 'Slightly Behind' : 'Slightly Over'; $paceCls = 'bv-pace-warn';
    } else {
        $paceLabel = 'On Track'; $paceCls = 'bv-pace-good';
    }

    $monRawPct = $monBudget > 0 ? ($monActual / $monBudget * 100) : null;
    $yrRawPct  = $annualBudget > 0 ? ($ytdActual / $annualBudget * 100) : null;

    $yrBarCls = match ($paceCls) {
        'bv-pace-bad'  => 'bar-over',
        'bv-pace-warn' => 'bar-warn',
        'bv-pace-good' => 'bar-ok',
        default        => 'bar-none',
    };

    return [
        'monBudget'       => $monBudget,
        'annualBudget'    => $annualBudget,
        'expected'        => $expected,
        'monDiff'         => $monDiff,
        'annualRemaining' => $annualRemaining,
        'pctYearElapsed'  => $pctYearElapsed,
        'pctAnnualUsed'   => $pctAnnualUsed,
        'paceLabel'       => $paceLabel,
        'paceCls'         => $paceCls,
        'monRawPct'       => $monRawPct,
        'monBarCls'       => budgetBarClass($monRawPct, $isIncome),
        'yrRawPct'        => $yrRawPct,
        'yrBarCls'        => $yrBarCls,
    ];
}

$incomeMonActuals  = fetchActuals($db, $acctIds, $incomeCats,  $monStart, $monEnd);
$incomeYtdActuals  = fetchActuals($db, $acctIds, $incomeCats,  $ytdStart, $ytdEnd);
$expenseMonActuals = fetchActuals($db, $acctIds, $expenseCats, $monStart, $monEnd);
$expenseYtdActuals = fetchActuals($db, $acctIds, $expenseCats, $ytdStart, $ytdEnd);

// Non-budgeted actuals: categories with transactions not in this budget
$nbIncome = $nbExpense = [];
if (!empty($acctIds)) {
    $budgetedCatIds = array_merge(
        array_column($incomeCats,  'category_id'),
        array_column($expenseCats, 'category_id')
    );
    $aPhs = implode(',', array_fill(0, count($acctIds), '?'));
    $excludeClause  = '';
    $excludeParams  = [];
    if (!empty($budgetedCatIds)) {
        $cPhs          = implode(',', array_fill(0, count($budgetedCatIds), '?'));
        $excludeClause = "AND ts.category_id NOT IN ($cPhs)";
        $excludeParams = $budgetedCatIds;
    }
    $nbStmt = $db->prepare(
        "SELECT ts.category_id, c.name, c.type AS cat_type, c.parent_id, p.name AS parent_name,
                SUM(CASE WHEN t.transaction_date BETWEEN ? AND ? THEN ts.amount ELSE 0 END) AS mon_actual,
                SUM(ts.amount) AS ytd_actual
         FROM transaction_splits ts
         JOIN transactions t ON t.id = ts.transaction_id
         JOIN categories c ON c.id = ts.category_id
         LEFT JOIN categories p ON p.id = c.parent_id
         WHERE t.transaction_date BETWEEN ? AND ?
           AND t.account_id IN ($aPhs)
           $excludeClause
           AND c.type IN ('income','expense')
           AND c.name != '--Split--'
         GROUP BY ts.category_id, c.name, c.type, c.parent_id, p.name
         HAVING ytd_actual != 0
         ORDER BY c.type, COALESCE(p.name, c.name), c.name"
    );
    $nbStmt->execute([$monStart, $monEnd, $ytdStart, $ytdEnd, ...$acctIds, ...$excludeParams]);
    foreach ($nbStmt->fetchAll() as $r) {
        $r['display_name'] = $r['parent_name'] ? $r['parent_name'] . ': ' . $r['name'] : $r['name'];
        if ($r['cat_type'] === 'income') $nbIncome[] = $r;
        else                             $nbExpense[] = $r;
    }
}

// Investment income (div/int/reinvest_div/reinvest_cap) — these never get
// transaction_splits rows (see reports/investment_income.php), so they're
// invisible to the actuals queries above. Mirrors that report's amount
// formula: cash div/int use abs(transactions.amount); reinvested activity
// uses quantity*price instead, since transactions.amount's sign isn't a
// reliable indicator of magnitude for reinvest_div/reinvest_cap.
$nbInvIncome = [];
if ($showInvIncome && !empty($acctIds)) {
    $aPhs = implode(',', array_fill(0, count($acctIds), '?'));
    $invStmt = $db->prepare(
        "SELECT t.transaction_date AS date, it.activity, it.quantity, it.price, t.amount
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         WHERE it.activity IN ('div','int','reinvest_div','reinvest_cap')
           AND t.transaction_date BETWEEN ? AND ?
           AND t.account_id IN ($aPhs)"
    );
    $invStmt->execute([$ytdStart, $ytdEnd, ...$acctIds]);
    $invTotals = [];
    foreach ($invStmt->fetchAll() as $r) {
        $amt = in_array($r['activity'], ['div', 'int'], true)
            ? abs((float)$r['amount'])
            : (float)$r['quantity'] * (float)$r['price'];
        $act = $r['activity'];
        if (!isset($invTotals[$act])) $invTotals[$act] = ['mon' => 0.0, 'ytd' => 0.0];
        $invTotals[$act]['ytd'] += $amt;
        if ($r['date'] >= $monStart && $r['date'] <= $monEnd) $invTotals[$act]['mon'] += $amt;
    }
    foreach ($invTotals as $act => $t) {
        if ($t['ytd'] == 0.0) continue;
        $nbInvIncome[] = [
            'display_name' => investmentActivityLabel($act),
            'mon_actual'   => $t['mon'],
            'ytd_actual'   => $t['ytd'],
        ];
    }
    usort($nbInvIncome, fn($a, $b) => $b['ytd_actual'] <=> $a['ytd_actual']);
}

$monthName = date('F Y', strtotime($monStart));

// ── CSV Export ─────────────────────────────────────────────────
function csvSectionRows(array $cats, array $monActuals, array $ytdActuals, bool $isIncome, string $sectionLabel, int $selMon): array {
    $rows = [];
    $totMonBudget = 0; $totMonActual = 0; $totAnnualBudget = 0; $totYtdActual = 0; $totExpected = 0;
    foreach ($cats as $c) {
        $cid       = (int)$c['category_id'];
        $monRaw    = $monActuals[$cid] ?? 0;
        $ytdRaw    = $ytdActuals[$cid] ?? 0;
        $monActual = $isIncome ? max(0, $monRaw) : abs(min(0, $monRaw));
        $ytdActual = $isIncome ? max(0, $ytdRaw) : abs(min(0, $ytdRaw));
        $row       = computeBudgetRow($c, $monActual, $ytdActual, $isIncome, $selMon);
        $rows[] = [
            $sectionLabel, $c['display_name'],
            number_format($row['monBudget'],       2, '.', ''),
            number_format($monActual,              2, '.', ''),
            number_format($row['monDiff'],         2, '.', ''),
            number_format($row['annualBudget'],    2, '.', ''),
            number_format($ytdActual,              2, '.', ''),
            $row['pctAnnualUsed'] !== null ? round($row['pctAnnualUsed'], 1) . '%' : '',
            number_format($row['expected'],        2, '.', ''),
            $row['paceLabel'] ?? '',
            number_format($row['annualRemaining'], 2, '.', ''),
        ];
        $totMonBudget    += $row['monBudget'];    $totMonActual += $monActual;
        $totAnnualBudget += $row['annualBudget']; $totYtdActual += $ytdActual;
        $totExpected     += $row['expected'];
    }
    $totMonDiff  = round($isIncome ? $totMonActual - $totMonBudget : $totMonBudget - $totMonActual, MONEY_DECIMALS);
    $totRow      = computeBudgetRow(
        ['mon_budget' => $totMonBudget, 'annual_budget' => $totAnnualBudget, 'expected_to_date' => $totExpected],
        $totMonActual, $totYtdActual, $isIncome, $selMon
    );
    $rows[] = [
        $sectionLabel, 'Total',
        number_format($totMonBudget,       2, '.', ''),
        number_format($totMonActual,       2, '.', ''),
        number_format($totMonDiff,         2, '.', ''),
        number_format($totAnnualBudget,    2, '.', ''),
        number_format($totYtdActual,       2, '.', ''),
        $totRow['pctAnnualUsed'] !== null ? round($totRow['pctAnnualUsed'], 1) . '%' : '',
        number_format($totRow['expected'], 2, '.', ''),
        $totRow['paceLabel'] ?? '',
        number_format($totRow['annualRemaining'], 2, '.', ''),
    ];
    return $rows;
}

if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    if (!empty($incomeCats))  $csvRows = array_merge($csvRows, csvSectionRows($incomeCats,  $incomeMonActuals,  $incomeYtdActuals,  true,  'Income',  $selMon));
    if (!empty($expenseCats)) $csvRows = array_merge($csvRows, csvSectionRows($expenseCats, $expenseMonActuals, $expenseYtdActuals, false, 'Expense', $selMon));
    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $budget['name']);
    outputCsv(
        'budget_' . $slug . '_' . $monStart . '.csv',
        ['Section', 'Category', 'This Month Budget', 'This Month Actual', 'This Month Remaining',
         'Annual Budget', 'YTD Actual', '% of Annual Used', 'Expected-to-Date', 'Pace Status', 'Annual Remaining'],
        $csvRows
    );
}

function renderNonBudgetedSection(array $nbIncome, array $nbExpense, array $nbInvIncome = []): void {
    if (empty($nbIncome) && empty($nbExpense) && empty($nbInvIncome)) return;
    $groups = [];
    if (!empty($nbIncome))     $groups[] = ['Income',             'bi-arrow-down-circle', $nbIncome,    true];
    if (!empty($nbInvIncome))  $groups[] = ['Investment Income',  'bi-graph-up-arrow',    $nbInvIncome, true];
    if (!empty($nbExpense))    $groups[] = ['Expenses',           'bi-arrow-up-circle',   $nbExpense,   false];
    ?>
<div class="bv-section bv-section-unbudgeted mt-2">
  <div class="bv-section-header">
    <span><i class="bi bi-dash-circle"></i> Untracked Activity</span>
    <span class="small fst-italic" style="font-weight:400;color:#6c757d">Transactions in budget accounts not covered by this budget</span>
  </div>
  <?php foreach ($groups as [$title, $icon, $cats, $isIncome]): ?>
  <?php if (count($groups) > 1): ?>
  <div class="bv-sub-label"><i class="bi <?= $icon ?>"></i> <?= $title ?></div>
  <?php endif; ?>
  <table class="table table-sm report-table bv-table bv-table-unbudgeted mb-0">
    <thead>
      <tr>
        <th>Category</th>
        <th class="text-end">This Month</th>
        <th class="text-end">YTD</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($cats as $c):
        $monRaw = (float)$c['mon_actual'];
        $ytdRaw = (float)$c['ytd_actual'];
        $monAmt = $isIncome ? max(0, $monRaw)      : abs(min(0, $monRaw));
        $ytdAmt = $isIncome ? max(0, $ytdRaw)      : abs(min(0, $ytdRaw));
        if ($monAmt == 0 && $ytdAmt == 0) continue;
    ?>
    <tr>
      <td><?= h($c['display_name']) ?></td>
      <td class="text-end"><?= $monAmt > 0 ? formatMoney($monAmt) : '<span class="text-muted">—</span>' ?></td>
      <td class="text-end"><?= $ytdAmt > 0 ? formatMoney($ytdAmt) : '<span class="text-muted">—</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endforeach; ?>
</div>
    <?php
}

$pageTitle   = h($budget['name']) . ' — Budget';
$currentPage = 'budget';
include __DIR__ . '/../includes/header.php';

// Renders one bar cell: fill + caption, optionally with a pace-marker tick and status line.
function renderBarCell(float $actual, float $target, ?float $rawPct, string $barCls, string $captionSuffix,
                        ?float $pctYearElapsed = null, ?string $paceLabel = null, string $paceCls = ''): void {
    $fillWidth = $rawPct !== null ? min($rawPct, 100) : 0;
    ?>
    <div class="budget-bar-track">
      <div class="budget-bar-fill <?= $barCls ?>" style="width:<?= round($fillWidth, 1) ?>%"></div>
      <?php if ($pctYearElapsed !== null && $target > 0): ?>
      <div class="budget-bar-pace" style="left:<?= round(min($pctYearElapsed, 100), 1) ?>%" title="Pace marker: <?= round($pctYearElapsed) ?>% of the year elapsed"></div>
      <?php endif; ?>
    </div>
    <div class="bv-cell-caption">
      <?php if ($target > 0): ?>
      <?= formatMoney($actual) ?> of <?= formatMoney($target) ?><?= $captionSuffix ?>
      <?php elseif ($actual > 0): ?>
      <?= formatMoney($actual) ?>
      <?php else: ?>
      <span class="text-muted">—</span>
      <?php endif; ?>
    </div>
    <?php if ($paceLabel !== null): ?>
    <div class="bv-pace-status <?= $paceCls ?>"><?= h($paceLabel) ?></div>
    <?php endif; ?>
    <?php
}

// Helper to render a section
function renderBudgetSection(
    string $title, string $icon, array $cats,
    array $monActuals, array $ytdActuals,
    bool $isIncome, int $selMon
): void {
    $totMonBudget = array_sum(array_column($cats, 'mon_budget'));
    $totAnnualBudget = array_sum(array_column($cats, 'annual_budget'));
    $totExpected  = array_sum(array_column($cats, 'expected_to_date'));
    $totMonActual = 0; $totYtdActual = 0;
    foreach ($cats as $c) {
        $raw = $monActuals[(int)$c['category_id']] ?? 0;
        $totMonActual += $isIncome ? max(0, $raw) : abs(min(0, $raw));
        $raw = $ytdActuals[(int)$c['category_id']] ?? 0;
        $totYtdActual += $isIncome ? max(0, $raw) : abs(min(0, $raw));
    }
    $totRow = computeBudgetRow(
        ['mon_budget' => $totMonBudget, 'annual_budget' => $totAnnualBudget, 'expected_to_date' => $totExpected],
        $totMonActual, $totYtdActual, $isIncome, $selMon
    );
    ?>
<div class="bv-section">
  <div class="bv-section-header">
    <span><i class="bi <?= $icon ?>"></i> <?= $title ?></span>
    <span class="bv-section-totals">
      <span class="text-muted small me-3">This month: <strong><?= formatMoney($totMonActual) ?></strong> / <?= formatMoney($totMonBudget) ?></span>
      <span class="text-muted small">This year: <strong><?= formatMoney($totYtdActual) ?></strong> / <?= formatMoney($totAnnualBudget) ?><?= $totRow['pctAnnualUsed'] !== null ? ' (' . round($totRow['pctAnnualUsed']) . '% used)' : '' ?></span>
    </span>
  </div>
  <table class="table table-sm report-table bv-table">
    <thead>
      <tr>
        <th>Category</th>
        <th style="min-width:150px">This Month</th>
        <th style="min-width:190px">This Year <i class="bi bi-info-circle" title="Bar shows year-to-date actual vs. the full annual budget. The dark marker shows how far through the year we are — compare it to the filled portion to see if spending/income is on pace."></i></th>
        <th class="text-end" style="width:110px">Annual Remaining</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($cats as $c):
        $cid       = (int)$c['category_id'];
        $monRaw    = $monActuals[$cid] ?? 0;
        $ytdRaw    = $ytdActuals[$cid] ?? 0;
        $monActual = $isIncome ? max(0, $monRaw) : abs(min(0, $monRaw));
        $ytdActual = $isIncome ? max(0, $ytdRaw) : abs(min(0, $ytdRaw));
        $row       = computeBudgetRow($c, $monActual, $ytdActual, $isIncome, $selMon);
        $monSuffix = $row['monBudget'] > 0
            ? ($row['monDiff'] < 0
                ? ' <span class="text-danger">(' . formatMoney(abs($row['monDiff'])) . ($isIncome ? ' short' : ' over') . ')</span>'
                : ' <span class="text-muted">(' . formatMoney($row['monDiff']) . ($isIncome ? ' surplus' : ' left') . ')</span>')
            : '';
        $remaining = $row['annualRemaining'];
    ?>
    <tr>
      <td><?= h($c['display_name']) ?>
        <?php if ($c['entry_type'] === 'annual'): ?>
        <span class="badge bg-secondary ms-1" style="font-size:.6rem">Annual</span>
        <?php elseif ($c['entry_type'] === 'variable'): ?>
        <span class="badge bg-info text-dark ms-1" style="font-size:.6rem">Variable</span>
        <?php endif; ?>
      </td>
      <td>
        <?php renderBarCell($monActual, $row['monBudget'], $row['monRawPct'], $row['monBarCls'], $monSuffix); ?>
      </td>
      <td>
        <?php
          $yrSuffix = $row['annualBudget'] > 0 && $row['pctAnnualUsed'] !== null
              ? ' (' . round($row['pctAnnualUsed']) . '% used)' : '';
          renderBarCell($ytdActual, $row['annualBudget'], $row['yrRawPct'], $row['yrBarCls'], $yrSuffix,
                        $row['pctYearElapsed'], $row['paceLabel'], $row['paceCls']);
        ?>
      </td>
      <td class="text-end <?= ($isIncome ? $remaining > 0 : $remaining < 0) ? 'amount-debit' : '' ?>">
        <?php if ($row['annualBudget'] > 0): ?>
          <?= formatMoney(abs($remaining)) ?>
          <?php if ($isIncome): ?>
            <span class="small <?= $remaining <= 0 ? 'text-muted' : '' ?>"><?= $remaining <= 0 ? 'exceeded' : 'to go' ?></span>
          <?php else: ?>
            <?php if ($remaining < 0): ?><span class="text-danger small">over</span><?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td>Total</td>
        <td>
          <?php
            $totMonSuffix = $totMonBudget > 0
                ? ($totRow['monDiff'] < 0
                    ? ' <span class="text-danger">(' . formatMoney(abs($totRow['monDiff'])) . ($isIncome ? ' short' : ' over') . ')</span>'
                    : ' <span class="text-muted">(' . formatMoney($totRow['monDiff']) . ($isIncome ? ' surplus' : ' left') . ')</span>')
                : '';
            renderBarCell($totMonActual, $totMonBudget, $totRow['monRawPct'], $totRow['monBarCls'], $totMonSuffix);
          ?>
        </td>
        <td>
          <?php
            $totYrSuffix = $totAnnualBudget > 0 && $totRow['pctAnnualUsed'] !== null
                ? ' (' . round($totRow['pctAnnualUsed']) . '% used)' : '';
            renderBarCell($totYtdActual, $totAnnualBudget, $totRow['yrRawPct'], $totRow['yrBarCls'], $totYrSuffix,
                          $totRow['pctYearElapsed'], $totRow['paceLabel'], $totRow['paceCls']);
          ?>
        </td>
        <td class="text-end <?= ($isIncome ? $totRow['annualRemaining'] > 0 : $totRow['annualRemaining'] < 0) ? 'amount-debit' : '' ?>">
          <?php if ($totAnnualBudget > 0): ?>
            <?= formatMoney(abs($totRow['annualRemaining'])) ?>
            <?php if ($isIncome): ?>
              <span class="small"><?= $totRow['annualRemaining'] <= 0 ? 'exceeded' : 'to go' ?></span>
            <?php elseif ($totRow['annualRemaining'] < 0): ?>
              <span class="text-danger small">over</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    </tfoot>
  </table>
</div>
    <?php
}
?>

<div class="page-header">
  <h2><i class="bi bi-bar-chart-line"></i> <?= h($budget['name']) ?></h2>
  <div class="d-flex gap-2">
    <?php if (canManageBudgets()): ?>
    <a href="<?= BASE_PATH ?>/budget/create?id=<?= $budgetId ?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-pencil"></i> Edit
    </a>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/budget/index" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-chevron-left"></i> All Budgets
    </a>
  </div>
</div>

<form method="get" class="report-filters mb-4">
  <input type="hidden" name="id" value="<?= $budgetId ?>">
  <?php if (count($allBudgets) > 1): ?>
  <div class="filter-group">
    <label>Budget</label>
    <select name="id" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach ($allBudgets as $b): ?>
      <option value="<?= $b['id'] ?>" <?= $b['id'] == $budgetId ? 'selected' : '' ?>><?= h($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="filter-group">
    <label>Year</label>
    <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>" <?= $y == $selYear ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Month</label>
    <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php for ($m = 1; $m <= 12; $m++): ?>
      <option value="<?= $m ?>" <?= $m == $selMon ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
      <?php endfor; ?>
    </select>
  </div>
  <div class="filter-group filter-group-btns">
    <label class="bv-inv-income-toggle">
      <input type="hidden" name="show_inv_income" value="0">
      <input type="checkbox" name="show_inv_income" value="1" <?= $showInvIncome ? 'checked' : '' ?> onchange="this.form.submit()">
      Show Investment Income
    </label>
  </div>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <a href="<?= BASE_PATH ?>/budget/view?id=<?= $budgetId ?>&year=<?= $selYear ?>&month=<?= $selMon ?>&export=csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </a>
  </div>
</form>

<?php if (empty($acctIds)): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> No accounts assigned to this budget.<?php if (canManageBudgets()): ?> <a href="<?= BASE_PATH ?>/budget/create?id=<?= $budgetId ?>">Edit budget</a> to add accounts.<?php endif; ?></div>
<?php endif; ?>

<div class="bv-period-heading"><?= h($monthName) ?> · Year-to-date through <?= date('F', mktime(0,0,0,$selMon,1)) ?> <?= $selYear ?> (<?= round($selMon / 12 * 100) ?>% of year elapsed)</div>

<?php if (!empty($incomeCats)): ?>
<?php renderBudgetSection('Income', 'bi-arrow-down-circle', $incomeCats, $incomeMonActuals, $incomeYtdActuals, true, $selMon); ?>
<?php endif; ?>

<?php if (!empty($expenseCats)): ?>
<?php renderBudgetSection('Expenses', 'bi-arrow-up-circle', $expenseCats, $expenseMonActuals, $expenseYtdActuals, false, $selMon); ?>
<?php endif; ?>

<?php renderNonBudgetedSection($nbIncome, $nbExpense, $nbInvIncome); ?>

<?php if (empty($incomeCats) && empty($expenseCats)): ?>
<div class="text-muted text-center mt-4">
  No categories in this budget.
  <?php if (canManageBudgets()): ?><a href="<?= BASE_PATH ?>/budget/create?id=<?= $budgetId ?>">Edit budget</a> to add categories.<?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
