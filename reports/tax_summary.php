<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

$taxCatCount = (int)$db->query('SELECT COUNT(*) FROM categories WHERE tax_related = 1')->fetchColumn();

$years = $db->query(
    "SELECT DISTINCT YEAR(t.transaction_date) AS yr
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories c   ON c.id = ts.category_id
     LEFT JOIN categories sc ON sc.id = ts.subcategory_id
     WHERE t.type != 'transfer' AND (c.tax_related = 1 OR sc.tax_related = 1)
     ORDER BY yr DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$currentYear = (int)date('Y');
if (!in_array($currentYear, $years, true)) {
    array_unshift($years, $currentYear);
    rsort($years);
}

$yearFilter = (int)($_GET['year'] ?? $currentYear);
if (!in_array($yearFilter, $years, true)) $yearFilter = $currentYear;

$stmt = $db->prepare(
    "SELECT
       c.id AS cat_id, c.name AS cat_name, c.type AS cat_type,
       sc.id AS sub_id, sc.name AS sub_name, sc.tax_related AS sub_tax,
       DATE_FORMAT(t.transaction_date, '%Y-%m') AS ym,
       ABS(ts.amount) AS amount
     FROM transaction_splits ts
     JOIN transactions t  ON t.id  = ts.transaction_id
     JOIN categories   c  ON c.id  = ts.category_id
     LEFT JOIN categories sc ON sc.id = ts.subcategory_id
     WHERE t.type != 'transfer'
       AND YEAR(t.transaction_date) = ?
       AND (c.tax_related = 1 OR sc.tax_related = 1)
     ORDER BY c.name, sc.name"
);
$stmt->execute([$yearFilter]);
$rows = $stmt->fetchAll();

$groups        = ['income' => [], 'expense' => []];
$totalIncome   = 0.0;
$totalExpense  = 0.0;

foreach ($rows as $r) {
    $useSubLevel = !empty($r['sub_tax']) && $r['sub_id'];
    $key   = $useSubLevel ? 'sub_' . $r['sub_id'] : 'cat_' . $r['cat_id'];
    $label = $useSubLevel ? $r['cat_name'] . ' → ' . $r['sub_name'] : $r['cat_name'];
    $type  = $r['cat_type'] === 'income' ? 'income' : 'expense';
    $amt   = (float)$r['amount'];

    if (!isset($groups[$type][$key])) {
        $groups[$type][$key] = ['label' => $label, 'total' => 0.0, 'count' => 0];
    }
    $groups[$type][$key]['total'] += $amt;
    $groups[$type][$key]['count']++;

    if ($type === 'income') $totalIncome += $amt;
    else $totalExpense += $amt;
}

uasort($groups['income'],  fn($a, $b) => $b['total'] <=> $a['total']);
uasort($groups['expense'], fn($a, $b) => $b['total'] <=> $a['total']);

$netImpact = $totalIncome - $totalExpense;

if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach (['income' => 'Income', 'expense' => 'Expense'] as $t => $tLabel) {
        foreach ($groups[$t] as $g) {
            $csvRows[] = [$tLabel, $g['label'], $g['count'], number_format($g['total'], 2, '.', '')];
        }
    }
    outputCsv('tax_summary_' . $yearFilter . '.csv', ['Type', 'Category', 'Transactions', 'Total'], $csvRows);
}

$pageTitle   = 'Tax Summary';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Tax Summary'; $reportFavIcon = 'bi-receipt'; ?>
<div class="page-header">
  <h2><i class="bi bi-receipt"></i> Tax Summary</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <div class="filter-group">
    <label>Tax Year</label>
    <select name="year" class="form-select form-select-sm">
      <?php foreach ($years as $yr): ?>
      <option value="<?= $yr ?>" <?= $yearFilter == $yr ? 'selected' : '' ?>><?= $yr ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
  </div>
</form>

<p class="text-muted small mt-2">
  Totals only include categories marked tax-related.
  <?= $taxCatCount ?> categor<?= $taxCatCount === 1 ? 'y is' : 'ies are' ?> currently flagged —
  <a href="<?= BASE_PATH ?>/categories">manage in Categories</a>.
</p>

<?php if ($taxCatCount === 0): ?>
<p class="text-muted mt-3">No categories are marked tax-related yet. Open a category in
  <a href="<?= BASE_PATH ?>/categories">Categories</a> and check "Tax-related" to include it here.</p>
<?php elseif (empty($rows)): ?>
<p class="text-muted mt-3">No tax-related transactions found for <?= $yearFilter ?>.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-income">
    <div class="tile-label">Tax-Related Income</div>
    <div class="tile-value"><?= formatMoney($totalIncome) ?></div>
  </div>
  <div class="report-tile tile-expense">
    <div class="tile-label">Deductible / Tax Expenses</div>
    <div class="tile-value"><?= formatMoney($totalExpense) ?></div>
  </div>
  <div class="report-tile <?= $netImpact >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Net</div>
    <div class="tile-value"><?= ($netImpact >= 0 ? '+' : '') . formatMoney($netImpact) ?></div>
  </div>
</div>

<?php if (!empty($groups['income'])): ?>
<div class="dash-section mt-4">
  <h6 class="mb-3">Income</h6>
  <table class="table table-sm dash-table report-table">
    <thead>
      <tr>
        <th>Category</th>
        <th class="text-end">Transactions</th>
        <th class="text-end">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($groups['income'] as $g): ?>
      <tr>
        <td><?= h($g['label']) ?></td>
        <td class="text-end text-muted"><?= $g['count'] ?></td>
        <td class="text-end amount-credit"><?= formatMoney($g['total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td>Total</td>
        <td></td>
        <td class="text-end amount-credit"><?= formatMoney($totalIncome) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<?php if (!empty($groups['expense'])): ?>
<div class="dash-section mt-4">
  <h6 class="mb-3">Deductible / Tax Expenses</h6>
  <table class="table table-sm dash-table report-table">
    <thead>
      <tr>
        <th>Category</th>
        <th class="text-end">Transactions</th>
        <th class="text-end">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($groups['expense'] as $g): ?>
      <tr>
        <td><?= h($g['label']) ?></td>
        <td class="text-end text-muted"><?= $g['count'] ?></td>
        <td class="text-end amount-debit"><?= formatMoney($g['total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td>Total</td>
        <td></td>
        <td class="text-end amount-debit"><?= formatMoney($totalExpense) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
