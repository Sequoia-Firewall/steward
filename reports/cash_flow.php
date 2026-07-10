<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Parameters ─────────────────────────────────────────────────
$thisYear = (int)date('Y');
$year     = isset($_GET['year']) ? (int)$_GET['year'] : $thisYear;

$years = $db->query(
    "SELECT DISTINCT YEAR(transaction_date) AS yr FROM transactions ORDER BY yr DESC"
)->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) $years = [$thisYear];

// ── Account filter ─────────────────────────────────────────────
require_once __DIR__ . '/../includes/report_acct_filter.php';

// ── Income by month ────────────────────────────────────────────
$incStmt = $db->prepare(
    "SELECT MONTH(t.transaction_date) AS mo, ABS(SUM(ts.amount)) AS total
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories   c ON c.id = ts.category_id
     WHERE c.type = 'income'
       AND t.type != 'transfer'
       AND YEAR(t.transaction_date) = ?
       $acctWhere
     GROUP BY mo"
);
$incStmt->execute(array_merge([$year], $acctParams));
$incMap = [];
foreach ($incStmt->fetchAll() as $r) $incMap[(int)$r['mo']] = (float)$r['total'];

// ── Expense by month ───────────────────────────────────────────
$expStmt = $db->prepare(
    "SELECT MONTH(t.transaction_date) AS mo, ABS(SUM(ts.amount)) AS total
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories   c ON c.id = ts.category_id
     WHERE c.type = 'expense'
       AND t.type != 'transfer'
       AND YEAR(t.transaction_date) = ?
       $acctWhere
     GROUP BY mo"
);
$expStmt->execute(array_merge([$year], $acctParams));
$expMap = [];
foreach ($expStmt->fetchAll() as $r) $expMap[(int)$r['mo']] = (float)$r['total'];

// ── Build rows ─────────────────────────────────────────────────
$rows      = [];
$totInc    = 0; $totExp = 0;
for ($m = 1; $m <= 12; $m++) {
    $inc = $incMap[$m] ?? 0;
    $exp = $expMap[$m] ?? 0;
    $net = $inc - $exp;
    $totInc += $inc; $totExp += $exp;
    $rows[] = ['label' => date('F', mktime(0,0,0,$m,1)), 'inc' => $inc, 'exp' => $exp, 'net' => $net];
}
$totNet = $totInc - $totExp;
$avgInc = $totInc / 12;
$avgExp = $totExp / 12;
$avgNet = $totNet / 12;

// Chart
$chartLabels  = array_column($rows, 'label');
$chartIncome  = array_map(fn($r) => round($r['inc'], 2), $rows);
$chartExpense = array_map(fn($r) => round($r['exp'], 2), $rows);
$chartNet     = array_map(fn($r) => round($r['net'], 2), $rows);

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($rows as $r) {
        $rate = $r['inc'] > 0 ? round($r['net'] / $r['inc'] * 100, 1) : null;
        $csvRows[] = [
            $r['label'],
            $r['inc'] > 0 ? number_format($r['inc'], 2, '.', '') : '',
            $r['exp'] > 0 ? number_format($r['exp'], 2, '.', '') : '',
            number_format($r['net'], 2, '.', ''),
            $rate !== null ? $rate . '%' : '',
        ];
    }
    $csvRows[] = ['Total',
        number_format($totInc, 2, '.', ''), number_format($totExp, 2, '.', ''),
        number_format($totNet, 2, '.', ''),
        $totInc > 0 ? round($totNet / $totInc * 100, 1) . '%' : ''];
    outputCsv('cash_flow_' . $year . '.csv',
              ['Month', 'Income', 'Expenses', 'Net Flow', 'Savings Rate'], $csvRows);
}

$pageTitle   = 'Cash Flow';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Cash Flow'; $reportFavIcon = 'bi-water'; ?>
<div class="page-header">
  <h2><i class="bi bi-water"></i> Cash Flow — <?= $year ?></h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <div class="filter-group">
    <label for="yearSel">Year</label>
    <select name="year" id="yearSel" class="form-select form-select-sm">
      <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php include __DIR__ . '/../includes/report_acct_filter_ui.php'; ?>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
  </div>
</form>

<div class="report-tiles">
  <div class="report-tile tile-income">
    <div class="tile-label">Total Income</div>
    <div class="tile-value"><?= formatMoney($totInc) ?></div>
    <div class="tile-sub">avg <?= formatMoney($avgInc) ?>/mo</div>
  </div>
  <div class="report-tile tile-expense">
    <div class="tile-label">Total Expenses</div>
    <div class="tile-value"><?= formatMoney($totExp) ?></div>
    <div class="tile-sub">avg <?= formatMoney($avgExp) ?>/mo</div>
  </div>
  <div class="report-tile <?= $totNet >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Net Cash Flow</div>
    <div class="tile-value"><?= formatMoney($totNet, true) ?></div>
    <div class="tile-sub">avg <?= formatMoney($avgNet, true) ?>/mo</div>
  </div>
</div>

<div class="report-chart-wrap">
  <canvas id="cfChart" height="90"></canvas>
</div>

<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Month</th>
      <th class="text-end">Income</th>
      <th class="text-end">Expenses</th>
      <th class="text-end">Net Flow</th>
      <th class="text-end">Savings Rate</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r):
      $rate = $r['inc'] > 0 ? ($r['net'] / $r['inc'] * 100) : null;
    ?>
    <tr>
      <td><?= h($r['label']) ?></td>
      <td class="text-end <?= $r['inc'] > 0 ? 'amount-credit' : '' ?>"><?= $r['inc'] > 0 ? formatMoney($r['inc']) : '—' ?></td>
      <td class="text-end <?= $r['exp'] > 0 ? 'amount-debit' : '' ?>"><?= $r['exp'] > 0 ? formatMoney($r['exp']) : '—' ?></td>
      <td class="text-end <?= $r['net'] >= 0 ? 'amount-credit' : 'amount-debit' ?>">
        <?= ($r['inc'] > 0 || $r['exp'] > 0) ? formatMoney($r['net'], true) : '—' ?>
      </td>
      <td class="text-end <?= $rate !== null ? ($rate >= 0 ? 'amount-credit' : 'amount-debit') : '' ?>">
        <?= $rate !== null ? round($rate, 1) . '%' : '—' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td>Total</td>
      <td class="text-end amount-credit"><?= formatMoney($totInc) ?></td>
      <td class="text-end amount-debit"><?= formatMoney($totExp) ?></td>
      <td class="text-end <?= $totNet >= 0 ? 'amount-credit' : 'amount-debit' ?>"><?= formatMoney($totNet, true) ?></td>
      <td class="text-end <?= $totInc > 0 ? ($totNet >= 0 ? 'amount-credit' : 'amount-debit') : '' ?>">
        <?= $totInc > 0 ? round($totNet / $totInc * 100, 1) . '%' : '—' ?>
      </td>
    </tr>
  </tfoot>
</table>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels  = <?= json_encode($chartLabels) ?>;
  const income  = <?= json_encode($chartIncome) ?>;
  const expense = <?= json_encode($chartExpense) ?>;
  const net     = <?= json_encode($chartNet) ?>;

  new Chart(document.getElementById('cfChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label:'Income',  data:income,  backgroundColor:'rgba(40,167,69,0.65)',  borderColor:'rgba(40,167,69,1)', borderWidth:1 },
        { label:'Expense', data:expense, backgroundColor:'rgba(220,53,69,0.65)',  borderColor:'rgba(220,53,69,1)', borderWidth:1 },
        { label:'Net',     data:net,     type:'line', borderColor:'#0d6efd', backgroundColor:'transparent', borderWidth:2, pointRadius:4, tension:0.3 }
      ]
    },
    options: {
      responsive:true,
      interaction:{ mode:'index', intersect:false },
      plugins:{ legend:{ position:'top' } },
      scales:{ y:{ ticks:{ callback: v => '$'+v.toLocaleString() } } }
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
