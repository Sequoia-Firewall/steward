<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Budget selector ────────────────────────────────────────────
$allBudgets = $db->query(
    "SELECT id, name FROM budgets WHERE is_active = 1 ORDER BY name"
)->fetchAll();

$budgetId = (int)($_GET['budget_id'] ?? ($allBudgets[0]['id'] ?? 0));
$selBudget = null;
foreach ($allBudgets as $b) { if ((int)$b['id'] === $budgetId) { $selBudget = $b; break; } }

if (empty($allBudgets)) {
    $pageTitle = 'Budget vs. Actual'; $currentPage = 'reports';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="page-header"><h2><i class="bi bi-bar-chart-line"></i> Budget vs. Actual</h2></div>';
    echo '<p class="text-muted mt-3">No budgets set up. <a href="'.BASE_PATH.'/budget/index">Create a budget.</a></p>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// ── Parameters ─────────────────────────────────────────────────
$thisYear = (int)date('Y');
$thisMon  = (int)date('n');

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : $thisYear;
$month = isset($_GET['month']) ? (int)$_GET['month'] : $thisMon;
$month = max(1, min(12, $month));

$years = $db->query(
    "SELECT DISTINCT YEAR(transaction_date) AS yr FROM transactions ORDER BY yr DESC"
)->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) $years = [$thisYear];

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate   = date('Y-m-t', strtotime($startDate));
$monthName = date('F Y', strtotime($startDate));

// ── Budget categories for selected budget ──────────────────────
$bcStmt = $db->prepare(
    "SELECT bc.id AS bc_id, bc.category_id, bc.entry_type, bc.amount, c.name, c.type AS category_type, p.name AS parent_name,
            GROUP_CONCAT(bma.month ORDER BY bma.month SEPARATOR ',') AS months,
            GROUP_CONCAT(bma.amount ORDER BY bma.month SEPARATOR ',') AS month_amounts
     FROM budget_categories bc
     JOIN categories c ON c.id = bc.category_id
     LEFT JOIN categories p ON p.id = c.parent_id
     LEFT JOIN budget_monthly_amounts bma ON bma.budget_category_id = bc.id
     WHERE bc.budget_id = ?
     GROUP BY bc.id
     ORDER BY COALESCE(p.name, c.name), c.name"
);
$bcStmt->execute([$budgetId]);
$budgetItems = $bcStmt->fetchAll();

if (empty($budgetItems)) {
    $pageTitle = 'Budget vs. Actual'; $currentPage = 'reports';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="page-header"><h2><i class="bi bi-bar-chart-line"></i> Budget vs. Actual</h2></div>';
    echo '<p class="text-muted mt-3">No categories in this budget. <a href="'.BASE_PATH.'/budget/create?id='.$budgetId.'">Edit budget.</a></p>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Budget accounts for actuals filter
$acctStmt = $db->prepare("SELECT account_id FROM budget_accounts WHERE budget_id = ?");
$acctStmt->execute([$budgetId]);
$acctIds = array_column($acctStmt->fetchAll(), 'account_id');

// ── Compute budgeted amount for this month ─────────────────────
foreach ($budgetItems as &$b) {
    $mMap = [];
    if ($b['months']) {
        $mons = explode(',', $b['months']);
        $amts = explode(',', $b['month_amounts']);
        foreach ($mons as $i => $m) $mMap[(int)$m] = (float)$amts[$i];
    }
    $b['budgeted'] = match($b['entry_type']) {
        'annual'   => (float)$b['amount'] / 12,
        'variable' => $mMap[$month] ?? 0,
        default    => (float)$b['amount'],
    };
    $b['display_name'] = $b['parent_name'] ? $b['parent_name'] . ': ' . $b['name'] : $b['name'];
}
unset($b);

// ── Actuals for this period (budget accounts only) ─────────────
$catIds = array_column($budgetItems, 'category_id');
$actualMap = [];
if (!empty($acctIds) && !empty($catIds)) {
    $catSet  = array_flip($catIds);
    $aPhs    = implode(',', array_fill(0, count($acctIds), '?'));
    $cPhs    = implode(',', array_fill(0, count($catIds), '?'));
    $actStmt = $db->prepare(
        "SELECT ts.category_id,
                COALESCE(ts.subcategory_id, ts.category_id) AS eff_cat_id,
                SUM(ts.amount) AS actual
         FROM transaction_splits ts
         JOIN transactions t ON t.id = ts.transaction_id
         WHERE t.transaction_date BETWEEN ? AND ?
           AND t.account_id IN ($aPhs)
           AND (ts.category_id IN ($cPhs) OR ts.subcategory_id IN ($cPhs))
         GROUP BY ts.category_id, COALESCE(ts.subcategory_id, ts.category_id)"
    );
    $actStmt->execute([$startDate, $endDate, ...$acctIds, ...$catIds, ...$catIds]);
    foreach ($actStmt->fetchAll() as $r) {
        $eid = (int)$r['eff_cat_id'];
        $cid = (int)$r['category_id'];
        $key = isset($catSet[$eid]) ? $eid : (isset($catSet[$cid]) ? $cid : null);
        if ($key !== null) $actualMap[$key] = ($actualMap[$key] ?? 0.0) + (float)$r['actual'];
    }
}

// ── Combine ────────────────────────────────────────────────────
$items     = [];
$totBudget = 0;
$totActual = 0;
foreach ($budgetItems as $b) {
    $budgeted = (float)$b['budgeted'];
    // Signed sum nets refunds against spending; normalize per category type
    // like budget/view.php (a net-refund month floors at 0, not |net|).
    $raw      = $actualMap[(int)$b['category_id']] ?? 0.0;
    $actual   = $b['category_type'] === 'income' ? max(0.0, $raw) : abs(min(0.0, $raw));
    $diff     = round($budgeted - $actual, MONEY_DECIMALS);
    $pct      = $budgeted > 0 ? min($actual / $budgeted * 100, 100) : null;
    $rawPct   = $budgeted > 0 ? ($actual / $budgeted * 100) : null;
    $totBudget += $budgeted;
    $totActual += $actual;
    $items[] = [
        'name'     => $b['display_name'],
        'budgeted' => $budgeted,
        'actual'   => $actual,
        'diff'     => $diff,
        'pct'      => $pct,
        'raw_pct'  => $rawPct,
    ];
}
$totDiff = round($totBudget - $totActual, MONEY_DECIMALS);

// Chart data (horizontal bar)
$chartLabels   = array_column($items, 'name');
$chartBudgeted = array_map(fn($i) => round($i['budgeted'], 2), $items);
$chartActual   = array_map(fn($i) => round($i['actual'],   2), $items);

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = array_map(
        fn($i) => [$i['name'],
                   number_format($i['budgeted'], 2, '.', ''),
                   number_format($i['actual'],   2, '.', ''),
                   number_format($i['diff'],     2, '.', ''),
                   $i['raw_pct'] !== null ? round($i['raw_pct'], 1) . '%' : ''],
        $items
    );
    $csvRows[] = ['Total',
                  number_format($totBudget, 2, '.', ''), number_format($totActual, 2, '.', ''),
                  number_format($totDiff,   2, '.', ''),
                  $totBudget > 0 ? round($totActual / $totBudget * 100, 1) . '%' : ''];
    outputCsv('budget_vs_actual_' . $startDate . '.csv',
              ['Category', 'Budgeted', 'Actual', 'Remaining', '% Used'], $csvRows);
}

$pageTitle   = 'Budget vs. Actual';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Budget vs. Actual'; $reportFavIcon = 'bi-bar-chart-line'; ?>
<div class="page-header">
  <h2><i class="bi bi-bar-chart-line"></i> Budget vs. Actual — <?= h($monthName) ?></h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <?php if (count($allBudgets) > 1): ?>
  <div class="filter-group">
    <label>Budget</label>
    <select name="budget_id" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach ($allBudgets as $b): ?>
      <option value="<?= $b['id'] ?>" <?= $b['id'] == $budgetId ? 'selected' : '' ?>><?= h($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php else: ?>
  <input type="hidden" name="budget_id" value="<?= $budgetId ?>">
  <?php endif; ?>
  <div class="filter-group">
    <label>Year</label>
    <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group">
    <label>Month</label>
    <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php for ($m = 1; $m <= 12; $m++): ?>
      <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
      <?php endfor; ?>
    </select>
  </div>
  <div class="filter-group filter-group-btns">
    <a href="?budget_id=<?= $budgetId ?>&year=<?= $year ?>&month=<?= $month ?>&export=csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </a>
  </div>
</form>

<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Total Budget</div>
    <div class="tile-value"><?= formatMoney($totBudget) ?></div>
  </div>
  <div class="report-tile tile-expense">
    <div class="tile-label">Total Actual</div>
    <div class="tile-value"><?= formatMoney($totActual) ?></div>
  </div>
  <div class="report-tile <?= $totDiff >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label"><?= $totDiff >= 0 ? 'Remaining' : 'Over Budget' ?></div>
    <div class="tile-value"><?= formatMoney(abs($totDiff)) ?></div>
  </div>
</div>

<div class="report-chart-wrap">
  <canvas id="bvaChart" height="<?= max(60, count($items) * 20) ?>"></canvas>
</div>

<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Category</th>
      <th class="text-end">Budgeted</th>
      <th class="text-end">Actual</th>
      <th class="text-end">Remaining</th>
      <th style="width:140px">Progress</th>
      <th class="text-end">%</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $item):
      $barCls = $item['raw_pct'] === null ? 'bar-none'
              : ($item['raw_pct'] > 100 ? 'bar-over' : ($item['raw_pct'] >= 80 ? 'bar-warn' : 'bar-ok'));
    ?>
    <tr>
      <td><?= h($item['name']) ?></td>
      <td class="text-end"><?= formatMoney($item['budgeted']) ?></td>
      <td class="text-end"><?= $item['actual'] > 0 ? formatMoney($item['actual']) : '—' ?></td>
      <td class="text-end <?= $item['diff'] < 0 ? 'amount-debit' : '' ?>">
        <?= $item['budgeted'] > 0 ? formatMoney($item['diff']) : '—' ?>
      </td>
      <td>
        <div class="budget-bar-track">
          <div class="budget-bar-fill <?= $barCls ?>" style="width:<?= round($item['pct'] ?? 0, 1) ?>%"></div>
        </div>
      </td>
      <td class="text-end budget-pct <?= $barCls ?>">
        <?= $item['raw_pct'] !== null ? round($item['raw_pct']) . '%' : '—' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td>Total</td>
      <td class="text-end"><?= formatMoney($totBudget) ?></td>
      <td class="text-end"><?= formatMoney($totActual) ?></td>
      <td class="text-end <?= $totDiff < 0 ? 'amount-debit' : '' ?>"><?= formatMoney($totDiff) ?></td>
      <td></td>
      <td class="text-end <?= $totBudget > 0 ? ($totActual/$totBudget > 1 ? 'amount-debit' : '') : '' ?>">
        <?= $totBudget > 0 ? round($totActual / $totBudget * 100) . '%' : '—' ?>
      </td>
    </tr>
  </tfoot>
</table>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels   = <?= json_encode($chartLabels) ?>;
  const budgeted = <?= json_encode($chartBudgeted) ?>;
  const actual   = <?= json_encode($chartActual) ?>;

  new Chart(document.getElementById('bvaChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label:'Budgeted', data:budgeted, backgroundColor:'rgba(13,110,253,0.5)', borderColor:'#0d6efd', borderWidth:1 },
        { label:'Actual',   data:actual,   backgroundColor:'rgba(220,53,69,0.6)',  borderColor:'#dc3545', borderWidth:1 }
      ]
    },
    options: {
      indexAxis: 'y',
      responsive:true,
      plugins:{ legend:{ position:'top' } },
      scales:{ x:{ ticks:{ callback: v => '$'+v.toLocaleString() } } }
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
