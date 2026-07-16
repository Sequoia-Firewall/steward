<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Parameters ─────────────────────────────────────────────────
$defaultStart = date('Y-m-01');
$defaultEnd   = date('Y-m-t');

$startDate       = $_GET['start'] ?? $defaultStart;
$endDate         = $_GET['end']   ?? $defaultEnd;
$viewMode        = in_array($_GET['view'] ?? '', ['payee','subcat']) ? $_GET['view'] : '';
$showPayeeTotals = $viewMode === 'payee';
$showSubcatTotals= $viewMode === 'subcat';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = $defaultEnd;
if ($endDate < $startDate) $endDate = $startDate;

$payeeFilter = $_GET['payee'] ?? '';
$payeeWhere  = $payeeFilter !== '' ? "AND t.payee = ?" : '';
$payeeParams = $payeeFilter !== '' ? [$payeeFilter] : [];

$excludeIds  = array_values(array_filter(array_map('intval', (array)($_GET['exclude'] ?? []))));

// ── Account filter ─────────────────────────────────────────────
require_once __DIR__ . '/../includes/report_acct_filter.php';

// ── Data ───────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT
       COALESCE(cp.id,   c.id)   AS parent_id,
       COALESCE(cp.name, c.name) AS parent_name,
       IF(cp.id IS NOT NULL, c.id,   sc.id)   AS sub_id,
       IF(cp.id IS NOT NULL, c.name, sc.name) AS sub_name,
       t.transaction_date,
       t.payee,
       -ts.amount AS amount
     FROM transaction_splits ts
     JOIN transactions t   ON t.id   = ts.transaction_id
     JOIN categories   c   ON c.id   = ts.category_id
     LEFT JOIN categories  cp ON cp.id  = c.parent_id
     LEFT JOIN categories  sc ON sc.id  = ts.subcategory_id
     WHERE c.type = 'expense'
       AND t.type != 'transfer'
       AND t.transaction_date BETWEEN ? AND ?
       $acctWhere
       $payeeWhere
     ORDER BY COALESCE(cp.name, c.name), IF(cp.id IS NOT NULL, c.name, sc.name), t.transaction_date DESC, t.id DESC"
);
$stmt->execute(array_merge([$startDate, $endDate], $acctParams, $payeeParams));
$rows = $stmt->fetchAll();

$catGroups  = [];
$grandTotal = 0;
foreach ($rows as $r) {
    $pid = (int)$r['parent_id'];
    if (!isset($catGroups[$pid])) {
        $catGroups[$pid] = ['name' => $r['parent_name'], 'total' => 0, 'txns' => []];
    }
    $catGroups[$pid]['total'] += (float)$r['amount'];
    $catGroups[$pid]['txns'][] = $r;
    $grandTotal += (float)$r['amount'];
}
uasort($catGroups, fn($a, $b) => $b['total'] <=> $a['total']);

// Build payee totals per category when view=payee
if ($showPayeeTotals) {
    foreach ($catGroups as &$cat) {
        $pm = [];
        foreach ($cat['txns'] as $txn) {
            $key = $txn['payee'];
            if (!isset($pm[$key])) $pm[$key] = ['payee' => $txn['payee'], 'amount' => 0, 'count' => 0];
            $pm[$key]['amount'] += (float)$txn['amount'];
            $pm[$key]['count']++;
        }
        uasort($pm, fn($a, $b) => $b['amount'] <=> $a['amount']);
        $cat['payees'] = array_values($pm);
    }
    unset($cat);
}

// Build subcategory totals per category when view=subcat
if ($showSubcatTotals) {
    foreach ($catGroups as &$cat) {
        $sm = [];
        foreach ($cat['txns'] as $txn) {
            $key = $txn['sub_id'] !== null ? (int)$txn['sub_id'] : 'none';
            $lbl = $txn['sub_name'] ?? '(no subcategory)';
            if (!isset($sm[$key])) $sm[$key] = ['name' => $lbl, 'amount' => 0.0, 'count' => 0];
            $sm[$key]['amount'] += (float)$txn['amount'];
            $sm[$key]['count']++;
        }
        uasort($sm, fn($a, $b) => $b['amount'] <=> $a['amount']);
        $cat['subs'] = array_values($sm);
    }
    unset($cat);
}

// Filter excluded categories
$excludedCategories = [];
foreach ($excludeIds as $eid) {
    if (isset($catGroups[$eid])) {
        $excludedCategories[$eid] = $catGroups[$eid]['name'];
        unset($catGroups[$eid]);
    }
}
$grandTotal = 0;
foreach ($catGroups as $g) $grandTotal += $g['total'];

// Chart data
$chartLabels = [];
$chartValues = [];
foreach ($catGroups as $g) {
    $chartLabels[] = $g['name'];
    $chartValues[] = round(max(0, $g['total']), 2); // donut can't render net-refund categories
}

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    if ($showPayeeTotals) {
        $csvRows = [];
        foreach ($catGroups as $cat) {
            foreach ($cat['payees'] as $p) {
                $csvRows[] = [$cat['name'], $p['payee'], number_format($p['amount'], 2, '.', ''), $p['count']];
            }
        }
        outputCsv(
            'spending_by_category_payee_' . $startDate . '_' . $endDate . '.csv',
            ['Category', 'Payee', 'Total Amount', 'Transactions'],
            $csvRows
        );
    } elseif ($showSubcatTotals) {
        $csvRows = [];
        foreach ($catGroups as $cat) {
            foreach ($cat['subs'] as $s) {
                $csvRows[] = [$cat['name'], $s['name'], number_format($s['amount'], 2, '.', ''), $s['count']];
            }
        }
        outputCsv(
            'spending_by_subcategory_' . $startDate . '_' . $endDate . '.csv',
            ['Category', 'Subcategory', 'Total Amount', 'Transactions'],
            $csvRows
        );
    } else {
        $csvRows = [];
        foreach ($catGroups as $cat) {
            foreach ($cat['txns'] as $txn) {
                $csvRows[] = [$cat['name'], $txn['sub_name'] ?? '', $txn['transaction_date'], $txn['payee'], number_format((float)$txn['amount'], 2, '.', '')];
            }
        }
        outputCsv(
            'spending_by_category_' . $startDate . '_' . $endDate . '.csv',
            ['Category', 'Subcategory', 'Date', 'Payee', 'Amount'],
            $csvRows
        );
    }
}

$acctQs  = $filteringAccts ? '&accts=' . urlencode($acctParam) : '';
$payeeQs = $payeeFilter !== '' ? '&payee=' . urlencode($payeeFilter) : '';
$viewQs  = $viewMode !== '' ? '&view=' . $viewMode : '';

$baseQs = '?start=' . urlencode($startDate) . '&end=' . urlencode($endDate) . $viewQs . $acctQs . $payeeQs;
$buildExcludeQs = function(array $ids): string {
    return implode('', array_map(fn($id) => '&exclude[]=' . $id, $ids));
};

$pageTitle   = 'Spending by Category';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Spending by Category'; $reportFavIcon = 'bi-pie-chart'; ?>
<div class="page-header">
  <h2><i class="bi bi-pie-chart"></i> Spending by Category</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <div class="filter-group">
    <label for="startDate">From</label>
    <input type="date" name="start" id="startDate" class="form-control form-control-sm"
           value="<?= h($startDate) ?>">
  </div>
  <div class="filter-group">
    <label for="endDate">To</label>
    <input type="date" name="end" id="endDate" class="form-control form-control-sm"
           value="<?= h($endDate) ?>">
  </div>
  <div class="filter-group">
    <label>Detail</label>
    <select name="view" class="form-select form-select-sm">
      <option value="">Transactions</option>
      <option value="subcat" <?= $showSubcatTotals ? 'selected' : '' ?>>Totals by Subcategory</option>
      <option value="payee"  <?= $showPayeeTotals  ? 'selected' : '' ?>>Totals by Payee</option>
    </select>
  </div>
  <?php include __DIR__ . '/../includes/report_acct_filter_ui.php'; ?>
  <?php foreach ($excludeIds as $eid): ?>
  <input type="hidden" name="exclude[]" value="<?= $eid ?>">
  <?php endforeach; ?>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
    <div class="quick-ranges">
      <?php
      $ranges  = [
        'This Month'   => [date('Y-m-01'), date('Y-m-t')],
        'Last Month'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        'Last 90 Days' => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
        'This Year'    => [date('Y').'-01-01', date('Y').'-12-31'],
        'Last Year'    => [(date('Y')-1).'-01-01', (date('Y')-1).'-12-31'],
      ];
      foreach ($ranges as $rangeLabel => [$s, $e]):
      ?>
      <a href="?start=<?= $s ?>&end=<?= $e ?><?= $viewQs . $acctQs . $payeeQs . $buildExcludeQs($excludeIds) ?>" class="btn btn-sm btn-outline-secondary
        <?= ($startDate === $s && $endDate === $e) ? ' active' : '' ?>"><?= $rangeLabel ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</form>

<?php if ($payeeFilter !== ''): ?>
<div class="alert alert-info d-flex align-items-center gap-2 py-2 px-3 mb-3">
  <i class="bi bi-funnel-fill"></i>
  Payee: <strong><?= h($payeeFilter) ?></strong>
  <a href="?start=<?= h($startDate) ?>&end=<?= h($endDate) ?><?= $acctQs . $viewQs . $buildExcludeQs($excludeIds) ?>"
     class="ms-auto btn btn-sm btn-outline-secondary">
    <i class="bi bi-x"></i> Clear
  </a>
</div>
<?php endif; ?>

<?php if (!empty($excludedCategories)): ?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
  <span class="text-muted small">Excluded:</span>
  <?php foreach ($excludedCategories as $eid => $ename):
    $restoreIds = array_values(array_filter($excludeIds, fn($id) => $id !== $eid));
    $restoreUrl = $baseQs . $buildExcludeQs($restoreIds);
  ?>
  <a href="<?= h($restoreUrl) ?>" class="badge bg-secondary text-decoration-none d-inline-flex align-items-center gap-1" title="Restore <?= h($ename) ?>">
    <?= h($ename) ?> <i class="bi bi-x"></i>
  </a>
  <?php endforeach; ?>
  <a href="<?= h($baseQs) ?>" class="btn btn-sm btn-outline-secondary py-0">Clear all</a>
</div>
<?php endif; ?>

<?php if (empty($catGroups)): ?>
  <p class="text-muted mt-3">No spending found for the selected period.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-expense">
    <div class="tile-label">Total Spending</div>
    <div class="tile-value"><?= formatMoney($grandTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Categories</div>
    <div class="tile-value"><?= count($catGroups) ?></div>
  </div>
</div>

<div class="report-two-col">

  <!-- Donut chart -->
  <div class="report-chart-wrap report-chart-sm">
    <canvas id="spendChart"></canvas>
  </div>

  <!-- Category breakdown -->
  <div class="report-cat-table">
    <?php foreach ($catGroups as $pid => $cat):
      $newExcludeIds = array_merge($excludeIds, [$pid]);
      $excludeUrl    = $baseQs . $buildExcludeQs($newExcludeIds);
    ?>
    <div class="cat-group">
      <div class="cat-group-header">
        <span class="cat-group-name"><?= h($cat['name']) ?></span>
        <span class="cat-group-total"><?= formatMoney($cat['total']) ?></span>
        <?php $pct = $grandTotal > 0 ? max(0, $cat['total']) / $grandTotal * 100 : 0; ?>
        <div class="progress cat-bar">
          <div class="progress-bar bg-primary" style="width:<?= round($pct,1) ?>%" title="<?= round($pct,1) ?>%"></div>
        </div>
        <span class="cat-pct"><?= round($pct) ?>%</span>
        <a href="<?= h($excludeUrl) ?>" class="cat-exclude-btn" title="Exclude <?= h($cat['name']) ?>">
          <i class="bi bi-x-lg"></i>
        </a>
      </div>
      <?php if ($showSubcatTotals && !empty($cat['subs'])): ?>
      <div class="cat-subs">
        <?php foreach ($cat['subs'] as $s): ?>
        <div class="cat-sub-row">
          <span class="cat-sub-name"><?= h($s['name']) ?></span>
          <span class="cat-sub-date text-muted"><?= $s['count'] ?> txn<?= $s['count'] !== 1 ? 's' : '' ?></span>
          <span class="cat-sub-amt"><?= formatMoney($s['amount']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php elseif ($showPayeeTotals && !empty($cat['payees'])): ?>
      <div class="cat-subs">
        <?php foreach ($cat['payees'] as $p): ?>
        <?php $payeeHref = '?start=' . urlencode($startDate) . '&end=' . urlencode($endDate) . $acctQs . '&payee=' . urlencode($p['payee']); ?>
        <div class="cat-sub-row">
          <span class="cat-sub-name"><a href="<?= h($payeeHref) ?>"><?= h($p['payee']) ?></a></span>
          <span class="cat-sub-date text-muted"><?= $p['count'] ?> txn<?= $p['count'] !== 1 ? 's' : '' ?></span>
          <span class="cat-sub-amt"><?= formatMoney($p['amount']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php elseif (!$showPayeeTotals && !$showSubcatTotals && !empty($cat['txns'])): ?>
      <div class="cat-subs">
        <?php foreach ($cat['txns'] as $txn): ?>
        <div class="cat-sub-row">
          <span class="cat-sub-name">
            <?= h($txn['payee']) ?>
            <?php if (!empty($txn['sub_name'])): ?>
              <span class="cat-sub-tag"><?= h($txn['sub_name']) ?></span>
            <?php endif; ?>
          </span>
          <span class="cat-sub-date"><?= formatDate($txn['transaction_date']) ?></span>
          <span class="cat-sub-amt"><?= formatMoney((float)$txn['amount']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

</div><!-- /.report-two-col -->
<?php endif; ?>

<style>
.cat-exclude-btn {
  color: #adb5bd;
  line-height: 1;
  padding: 2px 4px;
  border-radius: 3px;
  text-decoration: none;
  opacity: 0;
  transition: opacity .15s, color .15s;
}
.cat-group-header:hover .cat-exclude-btn { opacity: 1; }
.cat-exclude-btn:hover { color: #dc3545; }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels = <?= json_encode($chartLabels) ?>;
  const values = <?= json_encode($chartValues) ?>;
  const palette = [
    '#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f',
    '#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac'
  ];
  new Chart(document.getElementById('spendChart'), {
    type: 'doughnut',
    data: {
      labels,
      datasets:[{ data: values, backgroundColor: palette.slice(0, labels.length), borderWidth:1 }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 12 } },
        tooltip: { callbacks: { label: ctx => ' $' + ctx.parsed.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) }}
      }
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
