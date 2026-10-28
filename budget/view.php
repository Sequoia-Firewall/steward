<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_lib.php';
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

// Available years (transactions + current)
$years = $db->query(
    "SELECT DISTINCT YEAR(transaction_date) AS yr FROM transactions ORDER BY yr DESC"
)->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($today->format('Y'), $years)) $years[] = (int)$today->format('Y');
rsort($years);

// All budget math (targets, signed actuals, pacing, untracked) lives in _lib.php
$model = loadBudgetModel($budgetId, $selYear, $selMon, ['with_inv_income' => $showInvIncome]);
$acctIds     = $model['acct_ids'];
$incomeCats  = $model['income'];
$expenseCats = $model['expense'];
$nbIncome    = $model['untracked']['income'];
$nbExpense   = $model['untracked']['expense'];
$nbInvIncome = $model['inv_income'];

$monthName = date('F Y', strtotime($monStart));

// ── CSV Export ─────────────────────────────────────────────────
function csvSectionRows(array $cats, array $tot, string $sectionLabel): array {
    $rows = [];
    $fmt  = fn($v) => number_format($v, 2, '.', '');
    foreach ([...$cats, null] as $c) {
        $src = $c ?? $tot;
        $row = $src['metrics'];
        $rows[] = [
            $sectionLabel, $c ? $c['display_name'] : 'Total',
            $fmt($src['mon_budget']),
            $fmt($src['carry_in']),
            $fmt($src['mon_actual']),
            $fmt($row['monDiff']),
            $fmt($row['annualBudget']),
            $fmt($src['ytd_actual']),
            $row['pctAnnualUsed'] !== null ? round($row['pctAnnualUsed'], 1) . '%' : '',
            $fmt($row['expected']),
            $row['paceLabel'] ?? '',
            $fmt($row['annualRemaining']),
        ];
    }
    return $rows;
}

if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    if (!empty($incomeCats))  $csvRows = array_merge($csvRows, csvSectionRows($incomeCats,  $model['totals']['income'],  'Income'));
    if (!empty($expenseCats)) $csvRows = array_merge($csvRows, csvSectionRows($expenseCats, $model['totals']['expense'], 'Expense'));
    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $budget['name']);
    outputCsv(
        'budget_' . $slug . '_' . $monStart . '.csv',
        ['Section', 'Category', 'This Month Budget', 'Rolled Over', 'This Month Actual', 'This Month Remaining',
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
        // Signed in the section's direction: net refunds show negative
        $monAmt = (float)$c['mon_actual'];
        $ytdAmt = (float)$c['ytd_actual'];
        if ($monAmt == 0 && $ytdAmt == 0) continue;
    ?>
    <tr>
      <td><?= h($c['display_name']) ?></td>
      <td class="text-end"><?= $monAmt != 0 ? budgetMoney($monAmt) : '<span class="text-muted">—</span>' ?></td>
      <td class="text-end"><?= $ytdAmt != 0 ? budgetMoney($ytdAmt) : '<span class="text-muted">—</span>' ?></td>
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
// $pctExpected places the marker at the scheduled expected-to-date share of the annual budget.
function renderBarCell(float $actual, float $target, ?float $rawPct, string $barCls, string $captionSuffix,
                        ?float $pctExpected = null, ?string $paceLabel = null, string $paceCls = ''): void {
    $fillWidth = $rawPct !== null ? max(0, min($rawPct, 100)) : 0;
    ?>
    <div class="budget-bar-track">
      <div class="budget-bar-fill <?= $barCls ?>" style="width:<?= round($fillWidth, 1) ?>%"></div>
      <?php if ($pctExpected !== null && $target > 0): ?>
      <div class="budget-bar-pace" style="left:<?= round(min($pctExpected, 100), 1) ?>%" title="Pace marker: <?= round($pctExpected) ?>% of the annual budget expected by now"></div>
      <?php endif; ?>
    </div>
    <div class="bv-cell-caption">
      <?php if ($target > 0): ?>
      <?= budgetMoney($actual) ?> of <?= formatMoney($target) ?><?= $captionSuffix ?>
      <?php elseif ($actual != 0): ?>
      <?= budgetMoney($actual) ?><?= $captionSuffix ?>
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
function renderBudgetSection(string $title, string $icon, array $cats, array $tot, bool $isIncome): void {
    $totMonBudget    = $tot['mon_available'];   // includes rolled-over amounts
    $totAnnualBudget = $tot['annual_budget'];
    $totMonActual    = $tot['mon_actual'];
    $totYtdActual    = $tot['ytd_actual'];
    $totRow          = $tot['metrics'];
    ?>
<div class="bv-section">
  <div class="bv-section-header">
    <span><i class="bi <?= $icon ?>"></i> <?= $title ?></span>
    <span class="bv-section-totals">
      <span class="text-muted small me-3">This month: <strong><?= budgetMoney($totMonActual) ?></strong> / <?= formatMoney($totMonBudget) ?></span>
      <span class="text-muted small">This year: <strong><?= budgetMoney($totYtdActual) ?></strong> / <?= formatMoney($totAnnualBudget) ?><?= $totRow['pctAnnualUsed'] !== null ? ' (' . round($totRow['pctAnnualUsed']) . '% used)' : '' ?></span>
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
        $monActual = $c['mon_actual'];
        $ytdActual = $c['ytd_actual'];
        $row       = $c['metrics'];
        $monSuffix = $row['monBudget'] > 0 || $c['rollover']
            ? ($row['monDiff'] < 0
                ? ' <span class="text-danger">(' . formatMoney(abs($row['monDiff'])) . ($isIncome ? ' short' : ' over') . ')</span>'
                : ' <span class="text-muted">(' . formatMoney($row['monDiff']) . ($isIncome ? ' surplus' : ' left') . ')</span>')
            : '';
        if ($c['rollover'] && abs($c['carry_in']) >= 0.005) {
            $monSuffix .= '<div class="bv-rollover-note">' . formatMoney($c['mon_budget']) . ' budget '
                        . ($c['carry_in'] > 0 ? '+ ' . formatMoney($c['carry_in']) . ' rolled over'
                                              : '− ' . formatMoney(-$c['carry_in']) . ' overspent earlier')
                        . '</div>';
        }
        $remaining = $row['annualRemaining'];
    ?>
    <tr>
      <td><?= h($c['display_name']) ?>
        <?php if ($c['entry_type'] === 'annual'): ?>
        <span class="badge bg-secondary ms-1" style="font-size:.6rem"<?= $c['due_months'] ? '' : ' title="No due months: no monthly target, paced evenly over the year"' ?>>Annual<?= $c['due_months'] ? ' · ' . h(implode(', ', array_map(fn($m) => date('M', mktime(0, 0, 0, $m, 1)), $c['due_months']))) : '' ?></span>
        <?php elseif ($c['entry_type'] === 'variable'): ?>
        <span class="badge bg-info text-dark ms-1" style="font-size:.6rem">Variable</span>
        <?php endif; ?>
        <?php if ($c['rollover']): ?>
        <span class="badge bg-light text-dark border ms-1" style="font-size:.6rem" title="Unspent budget (or overspending) carries into later months of the year">Rollover</span>
        <?php endif; ?>
        <?php if ($c['alert']): ?>
        <i class="bi bi-exclamation-triangle-fill ms-1 bv-alert-icon bv-alert-<?= $c['alert']['level'] ?>" title="<?= h($c['alert']['text']) ?>"></i>
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
                        $row['pctExpected'], $row['paceLabel'], $row['paceCls']);
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
                          $totRow['pctExpected'], $totRow['paceLabel'], $totRow['paceCls']);
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

<?php if (!empty($model['alerts'])): ?>
<div class="bv-alerts">
  <div class="bv-alerts-title"><i class="bi bi-exclamation-triangle-fill"></i> <?= count($model['alerts']) ?> budget alert<?= count($model['alerts']) === 1 ? '' : 's' ?></div>
  <ul class="mb-0">
    <?php foreach ($model['alerts'] as $a): ?>
    <li class="bv-alert-<?= $a['alert']['level'] ?>"><strong><?= h($a['display_name']) ?></strong> — <?= h($a['alert']['text']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!empty($incomeCats)): ?>
<?php renderBudgetSection('Income', 'bi-arrow-down-circle', $incomeCats, $model['totals']['income'], true); ?>
<?php endif; ?>

<?php if (!empty($expenseCats)): ?>
<?php renderBudgetSection('Expenses', 'bi-arrow-up-circle', $expenseCats, $model['totals']['expense'], false); ?>
<?php endif; ?>

<?php renderNonBudgetedSection($nbIncome, $nbExpense, $nbInvIncome); ?>

<?php if (empty($incomeCats) && empty($expenseCats)): ?>
<div class="text-muted text-center mt-4">
  No categories in this budget.
  <?php if (canManageBudgets()): ?><a href="<?= BASE_PATH ?>/budget/create?id=<?= $budgetId ?>">Edit budget</a> to add categories.<?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
