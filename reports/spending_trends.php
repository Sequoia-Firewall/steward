<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

$months = in_array((int)($_GET['months'] ?? 12), [6, 12, 18, 24]) ? (int)$_GET['months'] : 12;

$endDate   = date('Y-m-d');
$startDate = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));

require_once __DIR__ . '/../includes/report_acct_filter.php';

$stmt = $db->prepare(
    "SELECT
       COALESCE(cp.id,   c.id)   AS cat_id,
       COALESCE(cp.name, c.name) AS cat_name,
       DATE_FORMAT(t.transaction_date, '%Y-%m') AS ym,
       SUM(ABS(ts.amount)) AS total
     FROM transaction_splits ts
     JOIN transactions t  ON t.id  = ts.transaction_id
     JOIN categories   c  ON c.id  = ts.category_id
     LEFT JOIN categories cp ON cp.id = c.parent_id
     WHERE c.type = 'expense'
       AND t.type != 'transfer'
       AND t.transaction_date BETWEEN ? AND ?
       $acctWhere
     GROUP BY cat_id, cat_name, ym
     ORDER BY cat_name, ym"
);
$stmt->execute(array_merge([$startDate, $endDate], $acctParams));
$rows = $stmt->fetchAll();

$allMonths = [];
$cursor = new DateTime(date('Y-m-01', strtotime($startDate)));
$endDt  = new DateTime(date('Y-m-01', strtotime($endDate)));
while ($cursor <= $endDt) {
    $allMonths[] = $cursor->format('Y-m');
    $cursor->modify('+1 month');
}

$catData = [];
foreach ($rows as $r) {
    $cid = (int)$r['cat_id'];
    if (!isset($catData[$cid])) {
        $catData[$cid] = ['name' => $r['cat_name'], 'months' => [], 'total' => 0.0];
    }
    $catData[$cid]['months'][$r['ym']] = (float)$r['total'];
    $catData[$cid]['total'] += (float)$r['total'];
}

$catData = array_filter($catData, fn($c) => $c['total'] > 0);
uasort($catData, fn($a, $b) => $b['total'] <=> $a['total']);

$monthTotals = [];
foreach ($allMonths as $ym) {
    $monthTotals[$ym] = 0.0;
    foreach ($catData as $cat) $monthTotals[$ym] += $cat['months'][$ym] ?? 0.0;
}
$grandTotal = array_sum($monthTotals);

$fmtMonth = fn(string $ym): string =>
    date('M Y', mktime(0, 0, 0, (int)substr($ym, 5, 2), 1, (int)substr($ym, 0, 4)));

$pageTitle   = 'Spending Trends';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Spending Trends'; $reportFavIcon = 'bi-grid-3x3'; ?>
<div class="page-header">
  <h2><i class="bi bi-grid-3x3"></i> Spending Trends</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <div class="filter-group">
    <label>Period</label>
    <select name="months" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach ([6, 12, 18, 24] as $n): ?>
      <option value="<?= $n ?>" <?= $months === $n ? 'selected' : '' ?>><?= $n ?> Months</option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php include __DIR__ . '/../includes/report_acct_filter_ui.php'; ?>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
  </div>
</form>

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
    <div class="tile-label">Avg / Month</div>
    <div class="tile-value"><?= count($allMonths) > 0 ? formatMoney($grandTotal / count($allMonths)) : '—' ?></div>
  </div>
</div>

<p class="text-muted small mb-2">
  <i class="bi bi-info-circle"></i>
  Darker = higher spending relative to that category's peak month. Each row is normalized independently.
</p>

<div class="table-responsive">
<table class="table table-sm report-table spending-trends-table">
  <thead>
    <tr>
      <th class="st-cat-col">Category</th>
      <?php foreach ($allMonths as $ym): ?>
      <th class="text-end st-amt-col"><?= h($fmtMonth($ym)) ?></th>
      <?php endforeach; ?>
      <th class="text-end st-total-col">Total</th>
      <th class="text-end st-avg-col">Avg</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($catData as $cid => $cat):
      $rowMax    = max(array_values($cat['months']) ?: [0]);
      $nonZero   = array_filter($cat['months'], fn($v) => $v > 0);
      $rowAvg    = count($nonZero) > 0 ? array_sum($nonZero) / count($nonZero) : 0;
    ?>
    <tr>
      <td class="st-cat-col fw-medium"><?= h($cat['name']) ?></td>
      <?php foreach ($allMonths as $ym):
        $amt = $cat['months'][$ym] ?? 0;
        $opacity = ($rowMax > 0 && $amt > 0) ? round($amt / $rowMax, 3) : 0;
      ?>
      <td class="text-end st-amt-col"
          <?php if ($opacity > 0): ?>
          style="background:rgba(220,53,69,<?= $opacity ?>);<?= $opacity >= 0.6 ? 'color:#fff;' : '' ?>"
          <?php endif ?>>
        <?= $amt > 0 ? '<span class="st-cell-amt">' . formatMoney($amt) . '</span>' : '' ?>
      </td>
      <?php endforeach; ?>
      <td class="text-end st-total-col fw-medium"><?= formatMoney($cat['total']) ?></td>
      <td class="text-end st-avg-col text-muted"><?= $rowAvg > 0 ? formatMoney($rowAvg) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="st-foot">
      <td class="st-cat-col fw-medium">Total</td>
      <?php foreach ($allMonths as $ym): ?>
      <td class="text-end st-amt-col fw-medium"><?= $monthTotals[$ym] > 0 ? formatMoney($monthTotals[$ym]) : '—' ?></td>
      <?php endforeach; ?>
      <td class="text-end st-total-col fw-medium"><?= formatMoney($grandTotal) ?></td>
      <td class="text-end st-avg-col fw-medium">
        <?= count($allMonths) > 0 ? formatMoney($grandTotal / count($allMonths)) : '—' ?>
      </td>
    </tr>
  </tfoot>
</table>
</div>

<style>
.spending-trends-table { min-width: max-content; border-collapse: separate; border-spacing: 0; }
.st-cat-col   { min-width: 170px; position: sticky; left: 0; z-index: 1; background: var(--bs-body-bg, #fff); }
.st-amt-col   { min-width: 85px; white-space: nowrap; }
.st-total-col { min-width: 95px; white-space: nowrap; }
.st-avg-col   { min-width: 85px; white-space: nowrap; }
.st-cell-amt  { font-size: .82rem; }
.st-foot td   { background: var(--bs-table-striped-bg, #f8f9fa); }
.st-foot .st-cat-col { background: var(--bs-table-striped-bg, #f8f9fa); }
</style>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
