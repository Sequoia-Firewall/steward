<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Parameters ─────────────────────────────────────────────────
$defaultStart = date('Y-01-01');
$defaultEnd   = date('Y-12-31');

$startDate = $_GET['start'] ?? $defaultStart;
$endDate   = $_GET['end']   ?? $defaultEnd;
$txnType  = $_GET['type']  ?? 'expense'; // expense | income | all
$limitRaw = $_GET['limit'] ?? '25';
$limit    = ($limitRaw === 'all') ? 0 : max(10, min(500, (int)$limitRaw));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = $defaultEnd;
if ($endDate < $startDate) $endDate = $startDate;

$excludePayees = array_values(array_filter((array)($_GET['exclude'] ?? []), fn($p) => $p !== ''));

// ── Account filter ─────────────────────────────────────────────
require_once __DIR__ . '/../includes/report_acct_filter.php';

// ── Build type filter ──────────────────────────────────────────
$typeWhere = '';
if ($txnType === 'expense') {
    $typeWhere = "AND t.amount < 0 AND t.type NOT IN ('transfer')";
} elseif ($txnType === 'income') {
    $typeWhere = "AND t.amount > 0 AND t.type NOT IN ('transfer')";
} else {
    $typeWhere = "AND t.type NOT IN ('transfer')";
}

// ── Data ───────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT t.payee,
            COUNT(*) AS txn_count,
            SUM(CASE WHEN t.amount < 0 THEN ABS(t.amount) ELSE 0 END) AS total_debit,
            SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END) AS total_credit,
            MIN(t.transaction_date) AS first_date,
            MAX(t.transaction_date) AS last_date
     FROM transactions t
     WHERE t.transaction_date BETWEEN ? AND ?
       $typeWhere
       $acctWhere
       AND t.payee <> ''
     GROUP BY t.payee
     ORDER BY " . ($txnType === 'income' ? 'total_credit' : 'total_debit') . " DESC"
     . ($limit > 0 ? " LIMIT ?" : "")
);
$params = array_merge([$startDate, $endDate], $acctParams);
if ($limit > 0) $params[] = $limit;
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Filter excluded payees
if (!empty($excludePayees)) {
    $excludeSet = array_flip($excludePayees);
    $rows = array_values(array_filter($rows, fn($r) => !isset($excludeSet[$r['payee']])));
}

$grandDebit  = array_sum(array_column($rows, 'total_debit'));
$grandCredit = array_sum(array_column($rows, 'total_credit'));

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($rows as $i => $r) {
        $csvRows[] = [
            $i + 1,
            $r['payee'],
            (int)$r['txn_count'],
            $r['total_debit']  > 0 ? number_format((float)$r['total_debit'],  2, '.', '') : '',
            $r['total_credit'] > 0 ? number_format((float)$r['total_credit'], 2, '.', '') : '',
            $r['first_date'],
            $r['last_date'],
        ];
    }
    outputCsv('payee_summary_' . $startDate . '_' . $endDate . '.csv',
              ['#', 'Payee', 'Transactions', 'Spent', 'Received', 'First Date', 'Last Date'],
              $csvRows);
}

// Chart — top 10
$chartRows   = array_slice($rows, 0, 10);
$chartLabels = array_column($chartRows, 'payee');
$chartValues = $txnType === 'income'
    ? array_map(fn($r) => round((float)$r['total_credit'], 2), $chartRows)
    : array_map(fn($r) => round((float)$r['total_debit'],  2), $chartRows);

$acctQs = $acctParam !== '' ? '&accts=' . urlencode($acctParam) : '';
$baseQs = '?start=' . urlencode($startDate) . '&end=' . urlencode($endDate)
        . '&type=' . urlencode($txnType) . '&limit=' . urlencode($limitRaw) . $acctQs;
$buildExcludeQs = function(array $payees): string {
    return implode('', array_map(fn($p) => '&exclude[]=' . urlencode($p), $payees));
};

$pageTitle   = 'Payee Summary';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Payee Summary'; $reportFavIcon = 'bi-shop'; ?>
<div class="page-header">
  <h2><i class="bi bi-shop"></i> Payee Summary</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <div class="filter-group">
    <label>From</label>
    <input type="date" name="start" class="form-control form-control-sm" value="<?= h($startDate) ?>">
  </div>
  <div class="filter-group">
    <label>To</label>
    <input type="date" name="end" class="form-control form-control-sm" value="<?= h($endDate) ?>">
  </div>
  <div class="filter-group">
    <label>Type</label>
    <select name="type" class="form-select form-select-sm">
      <option value="expense" <?= $txnType==='expense'?'selected':'' ?>>Expenses</option>
      <option value="income"  <?= $txnType==='income' ?'selected':'' ?>>Income</option>
      <option value="all"     <?= $txnType==='all'    ?'selected':'' ?>>All</option>
    </select>
  </div>
  <div class="filter-group">
    <label>Show</label>
    <select name="limit" class="form-select form-select-sm">
      <?php foreach ([25, 50, 100] as $l): ?>
      <option value="<?= $l ?>" <?= $limitRaw==(string)$l?'selected':'' ?>>Top <?= $l ?></option>
      <?php endforeach; ?>
      <option value="all" <?= $limitRaw==='all'?'selected':'' ?>>All</option>
    </select>
  </div>
  <?php include __DIR__ . '/../includes/report_acct_filter_ui.php'; ?>
  <?php foreach ($excludePayees as $ep): ?>
  <input type="hidden" name="exclude[]" value="<?= h($ep) ?>">
  <?php endforeach; ?>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
    <div class="quick-ranges">
      <?php
      $ranges = [
        'This Month' => [date('Y-m-01'), date('Y-m-t')],
        'This Year'  => [date('Y').'-01-01', date('Y').'-12-31'],
        'Last Year'  => [(date('Y')-1).'-01-01', (date('Y')-1).'-12-31'],
      ];
      foreach ($ranges as $lbl => [$s, $e]): ?>
      <a href="?start=<?= $s ?>&end=<?= $e ?>&type=<?= h($txnType) ?>&limit=<?= urlencode($limitRaw) ?><?= $acctQs . $buildExcludeQs($excludePayees) ?>"
         class="btn btn-sm btn-outline-secondary<?= ($startDate===$s&&$endDate===$e)?' active':'' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</form>

<?php if (!empty($excludePayees)): ?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
  <span class="text-muted small">Excluded:</span>
  <?php foreach ($excludePayees as $ep):
    $restorePayees = array_values(array_filter($excludePayees, fn($p) => $p !== $ep));
    $restoreUrl    = $baseQs . $buildExcludeQs($restorePayees);
  ?>
  <a href="<?= h($restoreUrl) ?>" class="badge bg-secondary text-decoration-none d-inline-flex align-items-center gap-1" title="Restore <?= h($ep) ?>">
    <?= h($ep) ?> <i class="bi bi-x"></i>
  </a>
  <?php endforeach; ?>
  <a href="<?= h($baseQs) ?>" class="btn btn-sm btn-outline-secondary py-0">Clear all</a>
</div>
<?php endif; ?>

<?php if (empty($rows)): ?>
  <p class="text-muted mt-3">No transactions found for the selected period.</p>
<?php else: ?>

<div class="report-tiles">
  <?php if ($txnType !== 'income'): ?>
  <div class="report-tile tile-expense">
    <div class="tile-label">Total Spending</div>
    <div class="tile-value"><?= formatMoney($grandDebit) ?></div>
  </div>
  <?php endif; ?>
  <?php if ($txnType !== 'expense'): ?>
  <div class="report-tile tile-income">
    <div class="tile-label">Total Income</div>
    <div class="tile-value"><?= formatMoney($grandCredit) ?></div>
  </div>
  <?php endif; ?>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Unique Payees</div>
    <div class="tile-value"><?= count($rows) ?></div>
  </div>
</div>

<div class="report-two-col">
  <div class="report-chart-wrap report-chart-sm">
    <canvas id="payeeChart"></canvas>
  </div>

  <table class="table table-sm report-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Payee</th>
        <th class="text-end">Transactions</th>
        <?php if ($txnType !== 'income'):  ?><th class="text-end">Spent</th><?php endif; ?>
        <?php if ($txnType !== 'expense'): ?><th class="text-end">Received</th><?php endif; ?>
        <th>First</th>
        <th>Last</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $i => $r):
        $newExcludePayees = array_merge($excludePayees, [$r['payee']]);
        $excludeUrl       = $baseQs . $buildExcludeQs($newExcludePayees);
      ?>
      <tr>
        <td class="text-muted"><?= $i + 1 ?></td>
        <td class="fw-medium">
          <?= h($r['payee']) ?>
          <a href="<?= h($excludeUrl) ?>" class="payee-exclude-btn" title="Exclude <?= h($r['payee']) ?>">
            <i class="bi bi-x-lg"></i>
          </a>
        </td>
        <td class="text-end"><?= (int)$r['txn_count'] ?></td>
        <?php if ($txnType !== 'income'):  ?><td class="text-end amount-debit"><?= $r['total_debit']  > 0 ? formatMoney((float)$r['total_debit'])  : '—' ?></td><?php endif; ?>
        <?php if ($txnType !== 'expense'): ?><td class="text-end amount-credit"><?= $r['total_credit'] > 0 ? formatMoney((float)$r['total_credit']) : '—' ?></td><?php endif; ?>
        <td class="text-nowrap text-muted"><?= formatDate($r['first_date']) ?></td>
        <td class="text-nowrap text-muted"><?= formatDate($r['last_date'])  ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<style>
.payee-exclude-btn {
  color: #adb5bd;
  line-height: 1;
  padding: 2px 4px;
  border-radius: 3px;
  text-decoration: none;
  opacity: 0;
  transition: opacity .15s, color .15s;
}
tr:hover .payee-exclude-btn { opacity: 1; }
.payee-exclude-btn:hover { color: #dc3545; }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels = <?= json_encode($chartLabels) ?>;
  const values = <?= json_encode($chartValues) ?>;
  const palette = ['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac'];
  new Chart(document.getElementById('payeeChart'), {
    type: 'doughnut',
    data: {
      labels,
      datasets:[{ data:values, backgroundColor:palette.slice(0,labels.length), borderWidth:1 }]
    },
    options:{
      responsive:true,
      plugins:{
        legend:{ position:'right', labels:{ boxWidth:12 } },
        tooltip:{ callbacks:{ label: ctx => ' $'+ctx.parsed.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) }}
      }
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
