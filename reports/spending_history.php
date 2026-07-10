<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Parameters ─────────────────────────────────────────────────
$periodMode = in_array($_GET['period'] ?? '', ['quarter','year']) ? $_GET['period'] : 'month';

$defaultStart = match($periodMode) {
    'year'    => (date('Y') - 4) . '-01-01',
    'quarter' => date('Y-m-01', strtotime('-23 months')),
    default   => date('Y-m-01', strtotime('-11 months')),
};
$defaultEnd = date('Y-m-d');

$startDate = $_GET['start'] ?? $defaultStart;
$endDate   = $_GET['end']   ?? $defaultEnd;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = $defaultEnd;
if ($endDate < $startDate) $endDate = $startDate;

$excludeIds = array_values(array_filter(array_map('intval', (array)($_GET['exclude'] ?? []))));

// ── Category filter ─────────────────────────────────────────────
$allExpenseCats = $db->query(
    "SELECT id, name FROM categories
     WHERE type = 'expense' AND parent_id IS NULL AND is_active = 1
     ORDER BY name"
)->fetchAll();
$allCatIds = array_map('intval', array_column($allExpenseCats, 'id'));

$catParam = trim($_GET['cats'] ?? '');
if ($catParam === '' || $catParam === 'all') {
    $selectedCatIds = $allCatIds;
    $filteringCats  = false;
} else {
    $parsed = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $catParam)),
        fn($id) => in_array($id, $allCatIds, true)
    )));
    if (empty($parsed) || count($parsed) >= count($allCatIds)) {
        $selectedCatIds = $allCatIds;
        $filteringCats  = false;
    } else {
        $selectedCatIds = $parsed;
        $filteringCats  = true;
    }
}

// ── Account filter ─────────────────────────────────────────────
require_once __DIR__ . '/../includes/report_acct_filter.php';

// ── Period grouping expression ──────────────────────────────────
$periodExpr = match($periodMode) {
    'year'    => "CAST(YEAR(t.transaction_date) AS CHAR)",
    'quarter' => "CONCAT(YEAR(t.transaction_date), '-Q', QUARTER(t.transaction_date))",
    default   => "DATE_FORMAT(t.transaction_date, '%Y-%m')",
};

// ── Data ───────────────────────────────────────────────────────
$stmt = $db->prepare(
    "SELECT
       COALESCE(cp.id,   c.id)   AS cat_id,
       COALESCE(cp.name, c.name) AS cat_name,
       $periodExpr               AS period,
       SUM(ABS(ts.amount))       AS amount
     FROM transaction_splits ts
     JOIN transactions t  ON t.id  = ts.transaction_id
     JOIN categories   c  ON c.id  = ts.category_id
     LEFT JOIN categories cp ON cp.id = c.parent_id
     WHERE c.type = 'expense'
       AND t.type != 'transfer'
       AND t.transaction_date BETWEEN ? AND ?
       $acctWhere
     GROUP BY COALESCE(cp.id, c.id), COALESCE(cp.name, c.name), period
     ORDER BY COALESCE(cp.name, c.name), period"
);
$stmt->execute(array_merge([$startDate, $endDate], $acctParams));
$rows = $stmt->fetchAll();

// ── Build pivot ─────────────────────────────────────────────────
$allPeriods = [];
$catData    = [];
foreach ($rows as $r) {
    $p   = $r['period'];
    $cid = (int)$r['cat_id'];
    $allPeriods[$p] = true;
    if (!isset($catData[$cid])) {
        $catData[$cid] = ['name' => $r['cat_name'], 'periods' => [], 'total' => 0.0];
    }
    $catData[$cid]['periods'][$p] = (float)$r['amount'];
    $catData[$cid]['total'] += (float)$r['amount'];
}
ksort($allPeriods);
$allPeriods = array_keys($allPeriods);

// ── Apply category selection filter ────────────────────────────
if ($filteringCats) {
    $selSet = array_flip($selectedCatIds);
    foreach (array_keys($catData) as $cid) {
        if (!isset($selSet[$cid])) unset($catData[$cid]);
    }
}

// ── Filter excluded categories ──────────────────────────────────
$excludedCategories = [];
foreach ($excludeIds as $eid) {
    if (isset($catData[$eid])) {
        $excludedCategories[$eid] = $catData[$eid]['name'];
        unset($catData[$eid]);
    }
}
uasort($catData, fn($a, $b) => $b['total'] <=> $a['total']);

// ── Period totals ───────────────────────────────────────────────
$periodTotals = [];
foreach ($allPeriods as $p) {
    $periodTotals[$p] = 0.0;
    foreach ($catData as $cat) $periodTotals[$p] += $cat['periods'][$p] ?? 0.0;
}
$grandTotal = array_sum($periodTotals);

// ── Period label formatter ──────────────────────────────────────
$fmtPeriod = fn(string $p): string => match($periodMode) {
    'year'    => $p,
    'quarter' => substr($p, 5) . ' ' . substr($p, 0, 4),
    default   => date('M Y', mktime(0, 0, 0, (int)substr($p, 5, 2), 1, (int)substr($p, 0, 4))),
};

// ── CSV export ──────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $hdr = ['Category', ...array_map($fmtPeriod, $allPeriods), 'Total'];
    $csvRows = [];
    foreach ($catData as $cat) {
        $row = [$cat['name']];
        foreach ($allPeriods as $p) $row[] = number_format($cat['periods'][$p] ?? 0, 2, '.', '');
        $row[] = number_format($cat['total'], 2, '.', '');
        $csvRows[] = $row;
    }
    $tot = ['TOTAL'];
    foreach ($allPeriods as $p) $tot[] = number_format($periodTotals[$p], 2, '.', '');
    $tot[] = number_format($grandTotal, 2, '.', '');
    $csvRows[] = $tot;
    outputCsv('spending_history_' . $startDate . '_' . $endDate . '.csv', $hdr, $csvRows);
}

// ── Category colors (for chart + table dots) ────────────────────
$palette = ['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac'];
$catColors = [];
$i = 0;
foreach ($catData as $cid => $cat) {
    $catColors[$cid] = $palette[$i % count($palette)];
    $i++;
}

// ── Chart datasets (top 8 categories, stacked bar) ──────────────
$chartDatasets = [];
$i = 0;
foreach (array_slice($catData, 0, 8, true) as $cid => $cat) {
    $chartDatasets[] = [
        'label'           => $cat['name'],
        'data'            => array_values(array_map(fn($p) => round($cat['periods'][$p] ?? 0, 2), $allPeriods)),
        'backgroundColor' => $catColors[$cid],
        'stack'           => 's',
    ];
    $i++;
}
$chartLabels = array_values(array_map($fmtPeriod, $allPeriods));

// ── URL helpers ─────────────────────────────────────────────────
$acctQs = $filteringAccts ? '&accts=' . urlencode($acctParam) : '';
$catQs  = $filteringCats  ? '&cats='  . urlencode($catParam)  : '';
$baseQs = '?start=' . urlencode($startDate) . '&end=' . urlencode($endDate)
        . '&period=' . $periodMode . $acctQs . $catQs;
$buildExcludeQs = fn(array $ids): string =>
    implode('', array_map(fn($id) => '&exclude[]=' . $id, $ids));

// ── Category filter button label ────────────────────────────────
if (!$filteringCats) {
    $catBtnLabel = 'All Categories';
} elseif (count($selectedCatIds) === 1) {
    $m = array_values(array_filter($allExpenseCats, fn($c) => (int)$c['id'] === $selectedCatIds[0]));
    $catBtnLabel = $m ? $m[0]['name'] : '1 Category';
} else {
    $catBtnLabel = count($selectedCatIds) . ' Categories';
}

$pageTitle   = 'Spending History';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Spending History'; $reportFavIcon = 'bi-clock-history'; ?>
<div class="page-header">
  <h2><i class="bi bi-clock-history"></i> Spending History</h2>
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
    <label>Group by</label>
    <select name="period" class="form-select form-select-sm">
      <option value="month"   <?= $periodMode==='month'   ? 'selected' : '' ?>>Month</option>
      <option value="quarter" <?= $periodMode==='quarter' ? 'selected' : '' ?>>Quarter</option>
      <option value="year"    <?= $periodMode==='year'    ? 'selected' : '' ?>>Year</option>
    </select>
  </div>
  <?php include __DIR__ . '/../includes/report_acct_filter_ui.php'; ?>
  <div class="filter-group">
    <label>Categories</label>
    <input type="hidden" name="cats" id="catHidden" value="<?= h($catParam) ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown">
        <span id="catFilterLabel"><?= h($catBtnLabel) ?></span>
      </button>
      <ul class="dropdown-menu acct-filter-menu p-2" style="max-height:280px;overflow-y:auto;min-width:200px">
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" id="catAll" <?= !$filteringCats ? 'checked' : '' ?>>
            <strong>All Categories</strong>
          </label>
        </li>
        <li><hr class="dropdown-divider my-1"></li>
        <?php foreach ($allExpenseCats as $ec): ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" class="cat-chk" value="<?= (int)$ec['id'] ?>"
                   data-name="<?= h($ec['name']) ?>"
                   <?= in_array((int)$ec['id'], $selectedCatIds, true) ? 'checked' : '' ?>>
            <span><?= h($ec['name']) ?></span>
          </label>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <script>
  (function(){
    const allChk  = document.getElementById('catAll');
    const chkList = Array.from(document.querySelectorAll('.cat-chk'));
    const hidden  = document.getElementById('catHidden');
    const label   = document.getElementById('catFilterLabel');
    function update() {
      const checked = chkList.filter(c => c.checked);
      const isAll   = checked.length === 0 || checked.length === chkList.length;
      hidden.value  = isAll ? '' : checked.map(c => c.value).join(',');
      label.textContent = isAll ? 'All Categories'
        : checked.length === 1 ? checked[0].dataset.name
        : checked.length + ' Categories';
      allChk.checked       = isAll || checked.length === chkList.length;
      allChk.indeterminate = !isAll && checked.length > 0;
    }
    allChk.addEventListener('change', function() {
      chkList.forEach(c => c.checked = this.checked);
      this.indeterminate = false;
      update();
    });
    chkList.forEach(c => c.addEventListener('change', update));
  })();
  </script>
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
      $qRanges = [
        '12 Mo'     => [date('Y-m-01', strtotime('-11 months')), date('Y-m-d')],
        'This Year' => [date('Y').'-01-01', date('Y-m-d')],
        'Last Year' => [(date('Y')-1).'-01-01', (date('Y')-1).'-12-31'],
        '5 Years'   => [(date('Y')-4).'-01-01', date('Y-m-d')],
      ];
      foreach ($qRanges as $lbl => [$s, $e]): ?>
      <a href="?start=<?= $s ?>&end=<?= $e ?>&period=<?= $periodMode ?><?= $acctQs . $catQs . $buildExcludeQs($excludeIds) ?>"
         class="btn btn-sm btn-outline-secondary<?= ($startDate===$s&&$endDate===$e)?' active':'' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</form>

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

<?php if (empty($catData)): ?>
  <p class="text-muted mt-3">No spending found for the selected period.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-expense">
    <div class="tile-label">Total Spending</div>
    <div class="tile-value"><?= formatMoney($grandTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Categories</div>
    <div class="tile-value"><?= count($catData) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label"><?= ucfirst($periodMode) ?>s</div>
    <div class="tile-value"><?= count($allPeriods) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Avg / <?= $periodMode === 'year' ? 'Year' : ($periodMode === 'quarter' ? 'Quarter' : 'Month') ?></div>
    <div class="tile-value"><?= count($allPeriods) > 0 ? formatMoney($grandTotal / count($allPeriods)) : '—' ?></div>
  </div>
</div>

<!-- Stacked bar chart -->
<div style="height:300px; margin-bottom:1.75rem">
  <canvas id="historyChart"></canvas>
</div>

<!-- Pivot table -->
<div class="table-responsive">
<table class="table table-sm report-table spending-history-table">
  <thead>
    <tr>
      <th class="sh-cat-col">Category</th>
      <?php foreach ($allPeriods as $p): ?>
      <th class="text-end sh-amt-col"><?= h($fmtPeriod($p)) ?></th>
      <?php endforeach; ?>
      <th class="text-end sh-total-col">Total</th>
      <th class="sh-x-col"></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($catData as $cid => $cat):
      $newExcludeIds = array_merge($excludeIds, [$cid]);
      $excludeUrl    = $baseQs . $buildExcludeQs($newExcludeIds);
    ?>
    <tr>
      <td class="sh-cat-col fw-medium">
        <span class="sh-dot" style="background:<?= $catColors[$cid] ?>"></span><?= h($cat['name']) ?>
      </td>
      <?php foreach ($allPeriods as $p):
        $amt = $cat['periods'][$p] ?? 0;
      ?>
      <td class="text-end sh-amt-col"><?= $amt > 0 ? formatMoney($amt) : '<span class="text-muted">—</span>' ?></td>
      <?php endforeach; ?>
      <td class="text-end sh-total-col fw-medium"><?= formatMoney($cat['total']) ?></td>
      <td class="sh-x-col">
        <a href="<?= h($excludeUrl) ?>" class="sh-exclude-btn" title="Exclude <?= h($cat['name']) ?>">
          <i class="bi bi-x-lg"></i>
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="sh-foot">
      <td class="sh-cat-col fw-medium">Total</td>
      <?php foreach ($allPeriods as $p): ?>
      <td class="text-end sh-amt-col fw-medium"><?= formatMoney($periodTotals[$p]) ?></td>
      <?php endforeach; ?>
      <td class="text-end sh-total-col fw-medium"><?= formatMoney($grandTotal) ?></td>
      <td class="sh-x-col"></td>
    </tr>
  </tfoot>
</table>
</div>
<?php endif; ?>

<style>
.spending-history-table { min-width: max-content; }
.sh-cat-col   { min-width: 170px; position: sticky; left: 0; z-index: 1; background: var(--bs-body-bg, #fff); }
.sh-amt-col   { min-width: 90px; white-space: nowrap; }
.sh-total-col { min-width: 100px; white-space: nowrap; }
.sh-x-col     { width: 28px; }
.sh-dot {
  display: inline-block; width: 9px; height: 9px; border-radius: 50%;
  margin-right: 6px; vertical-align: middle; flex-shrink: 0;
}
.sh-exclude-btn {
  color: #adb5bd; line-height: 1; padding: 2px 4px; border-radius: 3px;
  text-decoration: none; opacity: 0; transition: opacity .15s, color .15s;
}
tr:hover .sh-exclude-btn { opacity: 1; }
.sh-exclude-btn:hover { color: #dc3545; }
.sh-foot td { background: var(--bs-table-striped-bg, #f8f9fa); }
.sh-foot .sh-cat-col { background: var(--bs-table-striped-bg, #f8f9fa); }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels   = <?= json_encode($chartLabels) ?>;
  const datasets = <?= json_encode($chartDatasets) ?>;
  new Chart(document.getElementById('historyChart'), {
    type: 'bar',
    data: { labels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } },
        tooltip: {
          callbacks: {
            label: ctx => ' ' + ctx.dataset.label + ': $' +
              ctx.parsed.y.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})
          }
        }
      },
      scales: {
        x: { stacked: true, grid: { display: false } },
        y: {
          stacked: true,
          ticks: { callback: v => '$' + (v >= 1000 ? (v/1000).toFixed(0) + 'k' : v) }
        }
      }
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
