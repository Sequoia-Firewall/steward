<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../budget/_lib.php';
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

// ── Budget lines and actuals (budget/_lib.php) ────────────────
$model = loadBudgetModel($budgetId, $year, $month, ['with_untracked' => false]);
if (!$model || empty($model['lines'])) {
    $pageTitle = 'Budget vs. Actual'; $currentPage = 'reports';
    include __DIR__ . '/../includes/header.php';
    echo '<div class="page-header"><h2><i class="bi bi-bar-chart-line"></i> Budget vs. Actual</h2></div>';
    echo '<p class="text-muted mt-3">No categories in this budget. <a href="'.BASE_PATH.'/budget/create?id='.$budgetId.'">Edit budget.</a></p>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Actuals are signed in each line's direction (net refunds come out negative).
// Remaining is budget minus actual for both: money left to spend, or income still to come.
function bvaItems(array $lines): array {
    return array_map(function ($l) {
        $budgeted = $l['mon_budget'];
        $actual   = $l['mon_actual'];
        $rawPct   = $budgeted > 0 ? ($actual / $budgeted * 100) : null;
        return [
            'name'     => $l['display_name'],
            'budgeted' => $budgeted,
            'actual'   => $actual,
            'diff'     => round($budgeted - $actual, MONEY_DECIMALS),
            'pct'      => $rawPct !== null ? max(0, min($rawPct, 100)) : null,
            'raw_pct'  => $rawPct,
            'bar_cls'  => budgetBarClass($rawPct, $l['is_income']),
        ];
    }, $lines);
}
$sections = [];
foreach (['income' => 'Income', 'expense' => 'Expenses'] as $key => $label) {
    if (empty($model[$key])) continue;
    $tot = $model['totals'][$key];
    $sections[$key] = [
        'label'     => $label,
        'is_income' => $key === 'income',
        'items'     => bvaItems($model[$key]),
        'budget'    => $tot['mon_budget'],
        'actual'    => $tot['mon_actual'],
        'diff'      => round($tot['mon_budget'] - $tot['mon_actual'], MONEY_DECIMALS),
    ];
}
$incBudget = $sections['income']['budget']  ?? 0.0;
$incActual = $sections['income']['actual']  ?? 0.0;
$expBudget = $sections['expense']['budget'] ?? 0.0;
$expActual = $sections['expense']['actual'] ?? 0.0;
$netBudget = $incBudget - $expBudget;
$netActual = $incActual - $expActual;

// Chart data (horizontal bar): income lines first, then expense lines
$allItems      = array_merge(...array_map(fn($s) => $s['items'], array_values($sections)));
$chartLabels   = array_column($allItems, 'name');
$chartBudgeted = array_map(fn($i) => round($i['budgeted'], 2), $allItems);
$chartActual   = array_map(fn($i) => round($i['actual'],   2), $allItems);

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $fmt = fn($v) => number_format($v, 2, '.', '');
    $pct = fn($a, $b) => $b > 0 ? round($a / $b * 100, 1) . '%' : '';
    $csvRows = [];
    foreach ($sections as $sec) {
        foreach ($sec['items'] as $i) {
            $csvRows[] = [$sec['label'], $i['name'], $fmt($i['budgeted']), $fmt($i['actual']), $fmt($i['diff']),
                          $i['raw_pct'] !== null ? round($i['raw_pct'], 1) . '%' : ''];
        }
        $csvRows[] = [$sec['label'], 'Total', $fmt($sec['budget']), $fmt($sec['actual']), $fmt($sec['diff']),
                      $pct($sec['actual'], $sec['budget'])];
    }
    if (count($sections) === 2) {
        $csvRows[] = ['Net', 'Income − Expenses', $fmt($netBudget), $fmt($netActual), $fmt($netActual - $netBudget), ''];
    }
    outputCsv('budget_vs_actual_' . $startDate . '.csv',
              ['Section', 'Category', 'Budgeted', 'Actual', 'Remaining', '% Used'], $csvRows);
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
  <?php if (isset($sections['income'])): ?>
  <div class="report-tile tile-income">
    <div class="tile-label">Income</div>
    <div class="tile-value"><?= budgetMoney($incActual) ?></div>
    <div class="tile-sub">of <?= formatMoney($incBudget) ?> budgeted</div>
  </div>
  <?php endif; ?>
  <?php if (isset($sections['expense'])): ?>
  <div class="report-tile tile-expense">
    <div class="tile-label">Expenses</div>
    <div class="tile-value"><?= budgetMoney($expActual) ?></div>
    <div class="tile-sub">of <?= formatMoney($expBudget) ?> budgeted</div>
  </div>
  <div class="report-tile <?= $sections['expense']['diff'] >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label"><?= $sections['expense']['diff'] >= 0 ? 'Left to Spend' : 'Over Budget' ?></div>
    <div class="tile-value"><?= formatMoney(abs($sections['expense']['diff'])) ?></div>
  </div>
  <?php endif; ?>
  <?php if (count($sections) === 2): ?>
  <div class="report-tile <?= $netActual >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Net</div>
    <div class="tile-value"><?= budgetMoney($netActual) ?></div>
    <div class="tile-sub">plan <?= formatMoney($netBudget) ?></div>
  </div>
  <?php endif; ?>
</div>

<div class="report-chart-wrap">
  <canvas id="bvaChart" height="<?= max(60, count($allItems) * 20) ?>"></canvas>
</div>

<?php foreach ($sections as $sec): $isInc = $sec['is_income']; ?>
<h5 class="mt-3"><i class="bi <?= $isInc ? 'bi-arrow-down-circle' : 'bi-arrow-up-circle' ?>"></i> <?= $sec['label'] ?></h5>
<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Category</th>
      <th class="text-end">Budgeted</th>
      <th class="text-end">Actual</th>
      <th class="text-end"><?= $isInc ? 'To Go' : 'Remaining' ?></th>
      <th style="width:140px">Progress</th>
      <th class="text-end">%</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($sec['items'] as $item): ?>
    <tr>
      <td><?= h($item['name']) ?></td>
      <td class="text-end"><?= formatMoney($item['budgeted']) ?></td>
      <td class="text-end"><?= $item['actual'] != 0 ? budgetMoney($item['actual']) : '—' ?></td>
      <td class="text-end <?= !$isInc && $item['diff'] < 0 ? 'amount-debit' : '' ?>">
        <?= $item['budgeted'] > 0 ? formatMoney($item['diff']) : '—' ?>
      </td>
      <td>
        <div class="budget-bar-track">
          <div class="budget-bar-fill <?= $item['bar_cls'] ?>" style="width:<?= round($item['pct'] ?? 0, 1) ?>%"></div>
        </div>
      </td>
      <td class="text-end budget-pct <?= $item['bar_cls'] ?>">
        <?= $item['raw_pct'] !== null ? round($item['raw_pct']) . '%' : '—' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td>Total</td>
      <td class="text-end"><?= formatMoney($sec['budget']) ?></td>
      <td class="text-end"><?= budgetMoney($sec['actual']) ?></td>
      <td class="text-end <?= !$isInc && $sec['diff'] < 0 ? 'amount-debit' : '' ?>"><?= formatMoney($sec['diff']) ?></td>
      <td></td>
      <td class="text-end <?= !$isInc && $sec['budget'] > 0 && $sec['actual'] > $sec['budget'] ? 'amount-debit' : '' ?>">
        <?= $sec['budget'] > 0 ? round($sec['actual'] / $sec['budget'] * 100) . '%' : '—' ?>
      </td>
    </tr>
  </tfoot>
</table>
<?php endforeach; ?>

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
