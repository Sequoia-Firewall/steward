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

// Available years (transactions + current + the one being viewed)
$years = $db->query(
    "SELECT DISTINCT YEAR(transaction_date) AS yr FROM transactions ORDER BY yr DESC"
)->fetchAll(PDO::FETCH_COLUMN);
$years = array_map('intval', $years);
foreach ([(int)$today->format('Y'), $selYear] as $y) if (!in_array($y, $years, true)) $years[] = $y;
rsort($years);

// Month stepper: previous / next month, wrapping across years
$prevYm = $selMon === 1  ? [$selYear - 1, 12] : [$selYear, $selMon - 1];
$nextYm = $selMon === 12 ? [$selYear + 1, 1]  : [$selYear, $selMon + 1];

// All budget math (targets, signed actuals, pacing, untracked) lives in _lib.php
$model = loadBudgetModel($budgetId, $selYear, $selMon, ['with_inv_income' => $showInvIncome, 'with_last_year' => true]);
$acctIds     = $model['acct_ids'];
$incomeCats  = $model['income'];
$expenseCats = $model['expense'];
$nbIncome    = $model['untracked']['income'];
$nbExpense   = $model['untracked']['expense'];
$nbInvIncome = $model['inv_income'];

$monthName = date('F Y', strtotime($monStart));
$monShort  = array_map(fn($m) => date('M', mktime(0, 0, 0, $m, 1)), range(1, 12));

/** How far a line or total is from plan to date; positive = worse (overspent / under-earned). */
function budgetVariance(array $l, bool $isIncome): float {
    $d = $l['ytd_actual'] - $l['expected_to_date'];
    return round($isIncome ? -$d : $d, MONEY_DECIMALS);
}

/** Filter status: over (ahead of plan to date), under, or unspent (no activity this year). */
function budgetStatus(array $l): string {
    if (abs($l['ytd_actual']) < 0.005) return 'unspent';
    return $l['ytd_actual'] > $l['expected_to_date'] + 0.005 ? 'over' : 'under';
}

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
            $fmt($src['ly_ytd']),
        ];
    }
    return $rows;
}

// Untracked and investment-income rows: actuals only
function csvActivityRows(array $rows, string $sectionLabel): array {
    $fmt = fn($v) => number_format($v, 2, '.', '');
    return array_map(fn($r) => [$sectionLabel, $r['display_name'], '', '', $fmt($r['mon_actual']), '', '',
                                $fmt($r['ytd_actual']), '', '', '', '', ''], $rows);
}

if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    if (!empty($incomeCats))  $csvRows = array_merge($csvRows, csvSectionRows($incomeCats,  $model['totals']['income'],  'Income'));
    if (!empty($expenseCats)) $csvRows = array_merge($csvRows, csvSectionRows($expenseCats, $model['totals']['expense'], 'Expense'));
    $csvRows = array_merge($csvRows,
        csvActivityRows($nbIncome,    'Untracked Income'),
        csvActivityRows($nbInvIncome, 'Investment Income'),
        csvActivityRows($nbExpense,   'Untracked Expense'));
    $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $budget['name']);
    outputCsv(
        'budget_' . $slug . '_' . $monStart . '.csv',
        ['Section', 'Category', 'This Month Budget', 'Rolled Over', 'This Month Actual', 'This Month Remaining',
         'Annual Budget', 'YTD Actual', '% of Annual Used', 'Expected-to-Date', 'Pace Status', 'Annual Remaining',
         'Last Year Same Period'],
        $csvRows
    );
}

// ── Chart data ─────────────────────────────────────────────────
function chartSeries(array $lines, int $month): array {
    $sum = function (string $k) use ($lines) {
        $out = array_fill(1, 12, 0.0);
        foreach ($lines as $l) foreach ($l[$k] as $m => $v) $out[$m] += $v;
        return array_map(fn($v) => round($v, 2), array_values($out));
    };
    $actual = $sum('actuals');
    foreach ($actual as $i => $v) if ($i + 1 > $month) $actual[$i] = null;
    return ['target' => $sum('targets'), 'actual' => $actual, 'projected' => $sum('projected'), 'ly' => $sum('ly_actuals')];
}
function chartVariances(array $lines, bool $isIncome): array {
    $v = array_map(fn($l) => ['name' => $l['display_name'], 'value' => budgetVariance($l, $isIncome)], $lines);
    $v = array_values(array_filter($v, fn($x) => abs($x['value']) >= 0.005));
    usort($v, fn($a, $b) => abs($b['value']) <=> abs($a['value']));
    return array_slice($v, 0, 12);
}
function chartGroups(array $lines): array {
    $g = [];
    foreach ($lines as $l) {
        $name = $l['parent_name'] ?? $l['name'];
        $g[$name] = ($g[$name] ?? 0.0) + $l['ytd_actual'];
    }
    $g = array_filter($g, fn($v) => $v > 0.005);   // a donut can't show net refunds
    arsort($g);
    return array_map(fn($n, $v) => ['name' => $n, 'value' => round($v, 2)], array_keys($g), $g);
}
$chartData = [
    'labels'    => $monShort,
    'month'     => $selMon,
    'series'    => ['expense' => chartSeries($expenseCats, $selMon), 'income' => chartSeries($incomeCats, $selMon)],
    'variances' => ['expense' => chartVariances($expenseCats, false), 'income' => chartVariances($incomeCats, true)],
    'groups'    => ['expense' => chartGroups($expenseCats), 'income' => chartGroups($incomeCats)],
    'has'       => ['expense' => !empty($expenseCats), 'income' => !empty($incomeCats)],
];
$chartPref = explode(':', getUserPref(currentUserId(), 'budget_chart', null) ?? 'cumulative:expense');
$sortPref  = getUserPref(currentUserId(), 'budget_sort', null) ?? 'name:asc';

// ── Tiles (year to date through the selected month) ────────────
$ti = $model['totals']['income'];
$te = $model['totals']['expense'];
$netYtd      = $ti['ytd_actual'] - $te['ytd_actual'];
$netPlan     = $ti['expected_to_date'] - $te['expected_to_date'];
$netProj     = $ti['projected_total'] - $te['projected_total'];
$netAnnual   = $ti['annual_budget'] - $te['annual_budget'];

/** "$X over plan" / "$X under plan" caption for a tile; $goodWhenAbove for income/net. */
function tileVsPlan(float $actual, float $plan, bool $goodWhenAbove): string {
    $d = round($actual - $plan, MONEY_DECIMALS);
    if (abs($d) < 0.005) return 'on plan (' . formatMoney($plan) . ')';
    $good = $goodWhenAbove ? $d > 0 : $d < 0;
    return '<span class="' . ($good ? 'text-success' : 'text-danger') . '">' . formatMoney(abs($d)) . ($d > 0 ? ' above' : ' below')
         . '</span> plan of ' . formatMoney($plan);
}

$pageTitle   = h($budget['name']) . ' — Budget';
$currentPage = 'budget';
include __DIR__ . '/../includes/header.php';

/**
 * A budget bar. Past 100% the bar shows the whole actual: the budgeted part in
 * the normal fill and the overflow past the budget as a separate segment.
 * $pacePct places the expected-to-date marker (percent of target).
 */
function budgetBar(?float $rawPct, string $barCls, ?float $pacePct = null): string {
    $scale = max(100.0, (float)$rawPct);
    $html  = '<div class="budget-bar-track">';
    if ($rawPct !== null && $rawPct > 100) {
        $inside = 100 / $rawPct * 100;
        $html .= '<div class="budget-bar-fill ' . $barCls . ' bar-capped" style="width:' . round($inside, 1) . '%"></div>'
               . '<div class="budget-bar-overflow" style="left:' . round($inside, 1) . '%;width:' . round(100 - $inside, 1) . '%"'
               . ' title="' . round($rawPct - 100) . '% past budget"></div>';
    } else {
        $html .= '<div class="budget-bar-fill ' . $barCls . '" style="width:' . round(max(0, (float)$rawPct), 1) . '%"></div>';
    }
    if ($pacePct !== null) {
        $html .= '<div class="budget-bar-pace" style="left:' . round(min($pacePct, 100) * 100 / $scale, 1) . '%"'
               . ' title="Expected by now: ' . round($pacePct) . '% of the annual budget"></div>';
    }
    return $html . '</div>';
}

/** 12-month sparkline: actual bars through the selected month, dashed target line. */
function budgetSparkline(array $targets, array $actuals, int $month, bool $isIncome): string {
    $w = 84; $h = 22; $bw = $w / 12;
    $max = max(1.0, max($targets), max(array_map('abs', $actuals)));
    $svg = '<svg class="bv-spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true">';
    for ($m = 1; $m <= $month; $m++) {
        $a = $actuals[$m];
        if (abs($a) < 0.005) continue;
        $bh  = max(1, abs($a) / $max * ($h - 2));
        $over = $targets[$m] > 0 && ($isIncome ? $a < $targets[$m] : $a > $targets[$m]);
        $cls = $a < 0 ? 'neg' : ($over ? 'over' : 'ok');
        $svg .= '<rect class="bv-spark-' . $cls . '" x="' . round(($m - 1) * $bw + 1, 1) . '" y="' . round($h - $bh, 1)
              . '" width="' . round($bw - 2, 1) . '" height="' . round($bh, 1) . '"/>';
    }
    $pts = [];
    foreach ($targets as $m => $t) $pts[] = round(($m - 0.5) * $bw, 1) . ',' . round($h - $t / $max * ($h - 2), 1);
    if (max($targets) > 0) $svg .= '<polyline class="bv-spark-target" points="' . implode(' ', $pts) . '"/>';
    return $svg . '</svg>';
}

function lastYearCell(float $ly, float $now): string {
    if (abs($ly) < 0.005) return '<span class="text-muted">—</span>';
    $chg = ($now - $ly) / abs($ly) * 100;
    return budgetMoney($ly) . '<div class="bv-ly-chg">' . ($chg >= 0 ? '+' : '') . round($chg) . '%</div>';
}

function remainingCell(float $remaining, float $annual, bool $isIncome): string {
    if ($annual <= 0) return '<span class="text-muted">—</span>';
    $out = formatMoney(abs($remaining));
    if ($isIncome) return $out . ' <span class="small' . ($remaining <= 0 ? ' text-muted' : '') . '">' . ($remaining <= 0 ? 'exceeded' : 'to go') . '</span>';
    return $out . ($remaining < 0 ? ' <span class="text-danger small">over</span>' : '');
}

/** This Month cell: bar against the available amount (target + rollover) and caption. */
function monthCell(array $l, bool $isIncome): string {
    $row   = $l['metrics'];
    $avail = $l['mon_available'];
    $act   = $l['mon_actual'];
    $html  = budgetBar($row['monRawPct'], $row['monBarCls']) . '<div class="bv-cell-caption">';
    if ($avail > 0 || !empty($l['rollover'])) {
        $html .= budgetMoney($act) . ' of ' . budgetMoney($avail);
        $html .= $row['monDiff'] < 0
            ? ' <span class="text-danger">(' . formatMoney(abs($row['monDiff'])) . ($isIncome ? ' short' : ' over') . ')</span>'
            : ' <span class="text-muted">(' . formatMoney($row['monDiff']) . ($isIncome ? ' surplus' : ' left') . ')</span>';
    } elseif (abs($act) >= 0.005) {
        $html .= budgetMoney($act);
    } else {
        $html .= '<span class="text-muted">—</span>';
    }
    $html .= '</div>';
    if (!empty($l['rollover']) && abs($l['carry_in']) >= 0.005) {
        $html .= '<div class="bv-rollover-note">' . formatMoney($l['mon_budget']) . ' budget '
               . ($l['carry_in'] > 0 ? '+ ' . formatMoney($l['carry_in']) . ' rolled over'
                                     : '− ' . formatMoney(-$l['carry_in']) . ' overspent earlier')
               . '</div>';
    }
    return $html;
}

/** This Year cell: bar against the annual budget with the pace marker, caption and status. */
function yearCell(array $l): string {
    $row  = $l['metrics'];
    $html = budgetBar($row['yrRawPct'], $row['yrBarCls'], $l['annual_budget'] > 0 ? $row['pctExpected'] : null)
          . '<div class="bv-cell-caption">';
    if ($l['annual_budget'] > 0) {
        $html .= budgetMoney($l['ytd_actual']) . ' of ' . formatMoney($l['annual_budget'])
               . ($row['pctAnnualUsed'] !== null ? ' (' . round($row['pctAnnualUsed']) . '% used)' : '');
    } elseif (abs($l['ytd_actual']) >= 0.005) {
        $html .= budgetMoney($l['ytd_actual']);
    } else {
        $html .= '<span class="text-muted">—</span>';
    }
    $html .= '</div>';
    if ($row['paceLabel'] !== null) $html .= '<div class="bv-pace-status ' . $row['paceCls'] . '">' . h($row['paceLabel']) . '</div>';
    return $html;
}

/** data-* sort keys shared by line rows and group bodies. */
function sortAttrs(string $name, array $l, bool $isIncome): string {
    $used = $l['annual_budget'] > 0 ? $l['ytd_actual'] / $l['annual_budget'] * 100 : -1;
    return ' data-name="' . h(mb_strtolower($name)) . '" data-used="' . round($used, 2) . '"'
         . ' data-variance="' . budgetVariance($l, $isIncome) . '" data-actual="' . round($l['ytd_actual'], 2) . '"'
         . ' data-remaining="' . round($l['annual_budget'] - $l['ytd_actual'], 2) . '" data-last_year="' . round($l['ly_ytd'], 2) . '"';
}

/**
 * One section (income or expenses): lines grouped under their parent category,
 * each group a <tbody> with a collapsible subtotal header when it holds more
 * than the parent's own line.
 */
function renderBudgetTable(string $title, string $icon, array $lines, array $tot, bool $isIncome, int $selMon): void {
    $groups = [];
    foreach ($lines as $l) {
        $key = $l['parent_id'] ?? $l['category_id'];
        $groups[$key]['name']    = $l['parent_name'] ?? $l['name'];
        $groups[$key]['lines'][] = $l;
    }
    $totRow = $tot['metrics'];
    ?>
<div class="bv-section" data-section="<?= $isIncome ? 'income' : 'expense' ?>">
  <div class="bv-section-header">
    <span><i class="bi <?= $icon ?>"></i> <?= $title ?></span>
    <span class="bv-section-totals">
      <span class="text-muted small me-3">This month: <strong><?= budgetMoney($tot['mon_actual']) ?></strong> / <?= budgetMoney($tot['mon_available']) ?></span>
      <span class="text-muted small">This year: <strong><?= budgetMoney($tot['ytd_actual']) ?></strong> / <?= formatMoney($tot['annual_budget']) ?><?= $totRow['pctAnnualUsed'] !== null ? ' (' . round($totRow['pctAnnualUsed']) . '% used)' : '' ?></span>
    </span>
  </div>
  <div class="table-responsive">
  <table class="table table-sm report-table bv-table">
    <thead>
      <tr>
        <th>Category</th>
        <th style="min-width:150px">This Month</th>
        <th style="min-width:190px">This Year <i class="bi bi-info-circle" title="Year-to-date actual vs. the full annual budget. The dark marker is where the line's schedule expects you to be by now."></i></th>
        <th class="d-none d-lg-table-cell" title="Monthly actuals (bars) vs. targets (dashed line)">Trend</th>
        <th class="text-end" title="Same months last year, and the change this year">Last Year</th>
        <th class="text-end" style="width:110px">Annual Remaining</th>
      </tr>
    </thead>
    <?php foreach ($groups as $key => $g):
        $glines = $g['lines'];
        $single = count($glines) === 1 && $glines[0]['category_id'] === $key;
        $sum = budgetTotals($glines, $isIncome);
    ?>
    <tbody class="bv-group<?= $single ? ' bv-group-single' : '' ?>"<?= sortAttrs($g['name'], $sum, $isIncome) ?>>
      <?php if (!$single): ?>
      <tr class="bv-group-head" onclick="BV.toggleGroup(this)">
        <td><i class="bi bi-chevron-down bv-caret"></i> <strong><?= h($g['name']) ?></strong> <span class="text-muted small">(<?= count($glines) ?>)</span></td>
        <td><div class="bv-cell-caption"><?= budgetMoney($sum['mon_actual']) ?> of <?= budgetMoney($sum['mon_available']) ?></div></td>
        <td><?= budgetBar($sum['metrics']['yrRawPct'], $sum['metrics']['yrBarCls'], $sum['annual_budget'] > 0 ? $sum['metrics']['pctExpected'] : null) ?>
            <div class="bv-cell-caption"><?= budgetMoney($sum['ytd_actual']) ?> of <?= formatMoney($sum['annual_budget']) ?></div></td>
        <td class="d-none d-lg-table-cell"></td>
        <td class="text-end"><?= lastYearCell($sum['ly_ytd'], $sum['ytd_actual']) ?></td>
        <td class="text-end"><?= remainingCell($sum['metrics']['annualRemaining'], $sum['annual_budget'], $isIncome) ?></td>
      </tr>
      <?php endif; ?>
      <?php foreach ($glines as $c): $isChild = !$single && $c['category_id'] !== $key; ?>
      <tr class="bv-line<?= $isChild ? ' bv-line-child' : '' ?>" data-bc="<?= $c['bc_id'] ?>" data-status="<?= budgetStatus($c) ?>"
          <?= sortAttrs($c['display_name'], $c, $isIncome) ?> onclick="BV.openLine(event, <?= $c['bc_id'] ?>)" title="Click for details">
        <td>
          <?= h($isChild ? $c['name'] : $c['display_name']) ?>
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
        <td><?= monthCell($c, $isIncome) ?></td>
        <td><?= yearCell($c) ?></td>
        <td class="d-none d-lg-table-cell"><?= budgetSparkline($c['targets'], $c['actuals'], $selMon, $isIncome) ?></td>
        <td class="text-end"><?= lastYearCell($c['ly_ytd'], $c['ytd_actual']) ?></td>
        <td class="text-end <?= ($isIncome ? $c['metrics']['annualRemaining'] > 0 : $c['metrics']['annualRemaining'] < 0) ? 'amount-debit' : '' ?>">
          <?= remainingCell($c['metrics']['annualRemaining'], $c['annual_budget'], $isIncome) ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <?php endforeach; ?>
    <tfoot>
      <tr class="fw-bold">
        <td>Total</td>
        <td><?= monthCell(['metrics' => $totRow] + $tot, $isIncome) ?></td>
        <td><?= yearCell($tot) ?></td>
        <td class="d-none d-lg-table-cell"></td>
        <td class="text-end"><?= lastYearCell($tot['ly_ytd'], $tot['ytd_actual']) ?></td>
        <td class="text-end"><?= remainingCell($totRow['annualRemaining'], $tot['annual_budget'], $isIncome) ?></td>
      </tr>
    </tfoot>
  </table>
  </div>
</div>
    <?php
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

$stepUrl = fn(array $ym) => BASE_PATH . '/budget/view?id=' . $budgetId . '&year=' . $ym[0] . '&month=' . $ym[1];
$allLines = $model['lines'];
$statusCounts = array_count_values(array_map('budgetStatus', $allLines));
?>

<div class="page-header">
  <h2><i class="bi bi-bar-chart-line"></i> <?= h($budget['name']) ?></h2>
  <div class="d-flex gap-2">
    <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
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
  <?php if (count($allBudgets) > 1): ?>
  <div class="filter-group">
    <label>Budget</label>
    <select name="id" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach ($allBudgets as $b): ?>
      <option value="<?= $b['id'] ?>" <?= $b['id'] == $budgetId ? 'selected' : '' ?>><?= h($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php else: ?>
  <input type="hidden" name="id" value="<?= $budgetId ?>">
  <?php endif; ?>
  <div class="filter-group">
    <label>Month</label>
    <div class="d-flex gap-1 align-items-center">
      <a href="<?= h($stepUrl($prevYm)) ?>" class="btn btn-sm btn-outline-secondary" title="Previous month"><i class="bi bi-chevron-left"></i></a>
      <select name="month" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Month">
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m == $selMon ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
        <?php endfor; ?>
      </select>
      <select name="year" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Year">
        <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $y == $selYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
      <a href="<?= h($stepUrl($nextYm)) ?>" class="btn btn-sm btn-outline-secondary" title="Next month"><i class="bi bi-chevron-right"></i></a>
    </div>
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

<?php if (!empty($allLines)): ?>
<div class="report-tiles">
  <?php if (!empty($incomeCats)): ?>
  <div class="report-tile tile-income">
    <div class="tile-label">Income YTD</div>
    <div class="tile-value"><?= budgetMoney($ti['ytd_actual']) ?></div>
    <div class="tile-sub"><?= tileVsPlan($ti['ytd_actual'], $ti['expected_to_date'], true) ?></div>
  </div>
  <?php endif; ?>
  <?php if (!empty($expenseCats)): ?>
  <div class="report-tile tile-expense">
    <div class="tile-label">Expenses YTD</div>
    <div class="tile-value"><?= budgetMoney($te['ytd_actual']) ?></div>
    <div class="tile-sub"><?= tileVsPlan($te['ytd_actual'], $te['expected_to_date'], false) ?></div>
  </div>
  <?php endif; ?>
  <?php if (!empty($incomeCats) && !empty($expenseCats)): ?>
  <div class="report-tile <?= $netYtd >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Net YTD</div>
    <div class="tile-value"><?= budgetMoney($netYtd) ?></div>
    <div class="tile-sub"><?= tileVsPlan($netYtd, $netPlan, true) ?></div>
  </div>
  <?php endif; ?>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Projected Year End</div>
    <?php if (!empty($incomeCats) && !empty($expenseCats)): ?>
    <div class="tile-value"><?= budgetMoney($netProj) ?> <span class="small text-muted">net</span></div>
    <div class="tile-sub"><?= tileVsPlan($netProj, $netAnnual, true) ?></div>
    <?php else: $pt = !empty($expenseCats) ? $te : $ti; ?>
    <div class="tile-value"><?= budgetMoney($pt['projected_total']) ?></div>
    <div class="tile-sub"><?= tileVsPlan($pt['projected_total'], $pt['annual_budget'], empty($expenseCats)) ?></div>
    <?php endif; ?>
    <div class="tile-sub" title="Actuals through <?= h($monthName) ?>, then each month's budget<?= $model['is_current_month'] ? ' or its scheduled bills and deposits, whichever is larger' : '' ?>"><i class="bi bi-info-circle"></i> how this is projected</div>
  </div>
</div>

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

<div class="report-chart-wrap bv-chart-card">
  <div class="bv-chart-toolbar d-print-none">
    <div class="btn-group btn-group-sm" role="group" aria-label="Chart">
      <?php foreach (['cumulative' => 'Cumulative', 'monthly' => 'By Month', 'variance' => 'Variances', 'groups' => 'By Group'] as $k => $lbl): ?>
      <button type="button" class="btn btn-outline-secondary bv-chart-tab" data-tab="<?= $k ?>"><?= $lbl ?></button>
      <?php endforeach; ?>
    </div>
    <div class="btn-group btn-group-sm" role="group" aria-label="Series">
      <?php foreach (['expense' => 'Expenses', 'income' => 'Income', 'net' => 'Net'] as $k => $lbl):
          if (($k === 'expense' && empty($expenseCats)) || ($k === 'income' && empty($incomeCats))
              || ($k === 'net' && (empty($expenseCats) || empty($incomeCats)))) continue; ?>
      <button type="button" class="btn btn-outline-secondary bv-chart-series" data-series="<?= $k ?>"><?= $lbl ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="bv-chart-box"><canvas id="bvChart"></canvas></div>
  <div class="bv-chart-note small text-muted" id="bvChartNote"></div>
</div>

<div class="bv-table-toolbar d-print-none">
  <div class="btn-group btn-group-sm" role="group" aria-label="Filter lines">
    <button type="button" class="btn btn-outline-secondary active" data-filter="all">All <span class="badge bg-secondary"><?= count($allLines) ?></span></button>
    <button type="button" class="btn btn-outline-secondary" data-filter="over" title="Ahead of plan to date (spending more, or earning more, than scheduled)">Over <span class="badge bg-secondary"><?= $statusCounts['over'] ?? 0 ?></span></button>
    <button type="button" class="btn btn-outline-secondary" data-filter="under" title="At or behind plan to date">Under <span class="badge bg-secondary"><?= $statusCounts['under'] ?? 0 ?></span></button>
    <button type="button" class="btn btn-outline-secondary" data-filter="unspent" title="No activity this year">Unspent <span class="badge bg-secondary"><?= $statusCounts['unspent'] ?? 0 ?></span></button>
  </div>
  <div class="d-flex gap-1 align-items-center">
    <label for="bvSort" class="small text-muted mb-0">Sort</label>
    <select id="bvSort" class="form-select form-select-sm" style="width:auto">
      <option value="name">Name</option>
      <option value="used">% of annual used</option>
      <option value="variance">Variance vs. plan</option>
      <option value="actual">YTD actual</option>
      <option value="remaining">Annual remaining</option>
      <option value="last_year">Last year</option>
    </select>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="bvSortDir" title="Reverse sort"><i class="bi bi-sort-down"></i></button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="BV.toggleAll(true)" title="Collapse all groups"><i class="bi bi-arrows-collapse"></i></button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="BV.toggleAll(false)" title="Expand all groups"><i class="bi bi-arrows-expand"></i></button>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($incomeCats)): ?>
<?php renderBudgetTable('Income', 'bi-arrow-down-circle', $incomeCats, $ti, true, $selMon); ?>
<?php endif; ?>

<?php if (!empty($expenseCats)): ?>
<?php renderBudgetTable('Expenses', 'bi-arrow-up-circle', $expenseCats, $te, false, $selMon); ?>
<?php endif; ?>

<?php if (!empty($allLines)): ?>
<div class="bv-legend">
  <span><span class="bv-legend-sw bar-ok"></span> Within budget</span>
  <span><span class="bv-legend-sw bar-warn"></span> Near the limit / slightly off pace</span>
  <span><span class="bv-legend-sw bar-over"></span> Over budget / behind</span>
  <span><span class="bv-legend-sw bv-legend-overflow"></span> Past 100% of budget</span>
  <span><span class="bv-legend-pace"></span> Expected by now</span>
  <span><svg width="22" height="10" aria-hidden="true"><line x1="0" y1="5" x2="22" y2="5" class="bv-spark-target"/></svg> Monthly target (trend)</span>
</div>
<?php endif; ?>

<?php renderNonBudgetedSection($nbIncome, $nbExpense, $nbInvIncome); ?>

<?php if (empty($incomeCats) && empty($expenseCats)): ?>
<div class="text-muted text-center mt-4">
  No categories in this budget.
  <?php if (canManageBudgets()): ?><a href="<?= BASE_PATH ?>/budget/create?id=<?= $budgetId ?>">Edit budget</a> to add categories.<?php endif; ?>
</div>
<?php endif; ?>

<!-- Line drawer -->
<div class="offcanvas offcanvas-end bv-drawer" tabindex="-1" id="bvDrawer" aria-labelledby="bvDrawerTitle">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="bvDrawerTitle">Budget line</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body" id="bvDrawerBody"></div>
</div>

<?php if (!empty($allLines)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const BV = (function () {
  const DATA   = <?= json_encode($chartData) ?>;
  const YEAR   = <?= $selYear ?>;
  const MONTH  = <?= $selMon ?>;
  const MONTHS = <?= json_encode($monShort) ?>;
  const BASE   = <?= json_encode(BASE_PATH) ?>;
  const CSRF   = <?= json_encode(csrfToken()) ?>;
  const money  = v => (v < 0 ? '-' : '') + '$' + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const esc    = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const cum    = arr => { let t = 0; return arr.map(v => v === null ? null : (t += v)); };

  // ── Chart ───────────────────────────────────────────────────
  let [tab, series] = <?= json_encode($chartPref) ?>;
  if (!DATA.has[series] && series !== 'net') series = DATA.has.expense ? 'expense' : 'income';
  if (series === 'net' && !(DATA.has.expense && DATA.has.income)) series = DATA.has.expense ? 'expense' : 'income';
  if (!['cumulative', 'monthly', 'variance', 'groups'].includes(tab)) tab = 'cumulative';
  let chart = null;

  function seriesData(s) {
    if (s !== 'net') return DATA.series[s];
    const i = DATA.series.income, e = DATA.series.expense, out = {};
    for (const k of ['target', 'actual', 'projected', 'ly']) out[k] = i[k].map((v, n) => v === null ? null : v - e[k][n]);
    return out;
  }

  function draw() {
    document.querySelectorAll('.bv-chart-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    document.querySelectorAll('.bv-chart-series').forEach(b => b.classList.toggle('active', b.dataset.series === series));
    if (chart) chart.destroy();
    const note = document.getElementById('bvChartNote');
    const ctx  = document.getElementById('bvChart');
    const sd   = seriesData(series);
    const label = { expense: 'Expenses', income: 'Income', net: 'Net' }[series];
    const fmtTick = { ticks: { callback: v => '$' + Number(v).toLocaleString() } };
    let cfg;
    if (tab === 'cumulative' || tab === 'monthly') {
      const isCum = tab === 'cumulative';
      const f = isCum ? cum : (a => a);
      // Projection continues from the last actual month
      const proj = f(sd.projected).map((v, n) => n >= MONTH - 1 ? v : null);
      cfg = {
        type: isCum ? 'line' : 'bar',
        data: { labels: MONTHS, datasets: [
          { label: 'Budget', data: f(sd.target), borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.25)', borderDash: isCum ? [6, 4] : [], tension: .2, order: 3 },
          { label: 'Actual', data: f(sd.actual), borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,.6)', tension: .2, order: 2 },
          ...(isCum ? [{ label: 'Projected', data: proj, borderColor: '#fd7e14', borderDash: [3, 3], pointRadius: 0, tension: .2, order: 1 }] : []),
          { label: 'Last year', data: f(sd.ly), type: 'line', borderColor: '#6c757d', backgroundColor: '#6c757d', borderWidth: 1.5, pointRadius: 0, tension: .2, order: 0 },
        ] },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                   plugins: { tooltip: { callbacks: { label: c => c.dataset.label + ': ' + money(c.parsed.y ?? 0) } } },
                   scales: { y: fmtTick } },
      };
      note.textContent = label + (isCum ? ', cumulative from January' : ' per month') + '. Projected: actuals through ' + MONTHS[MONTH - 1] + ', then the plan (or scheduled bills, if larger).';
    } else if (tab === 'variance') {
      let v = series === 'net' ? [...DATA.variances.expense, ...DATA.variances.income].sort((a, b) => Math.abs(b.value) - Math.abs(a.value)).slice(0, 12)
                               : DATA.variances[series];
      cfg = {
        type: 'bar',
        data: { labels: v.map(x => x.name), datasets: [{ label: 'vs. plan to date', data: v.map(x => x.value),
                backgroundColor: v.map(x => x.value > 0 ? 'rgba(220,53,69,.75)' : 'rgba(25,135,84,.75)') }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false },
                   tooltip: { callbacks: { label: c => (c.parsed.x > 0 ? 'Worse than plan by ' : 'Better than plan by ') + money(Math.abs(c.parsed.x)) } } },
                   scales: { x: fmtTick } },
      };
      note.textContent = v.length ? 'Biggest differences from plan to date. Red: overspent or under-earned; green: the reverse.' : 'Every line is exactly on plan.';
    } else {
      const g = DATA.groups[series === 'net' ? 'expense' : series];
      const palette = ['#0d6efd', '#dc3545', '#198754', '#fd7e14', '#6f42c1', '#20c997', '#ffc107', '#0dcaf0', '#d63384', '#6c757d'];
      cfg = {
        type: 'doughnut',
        data: { labels: g.map(x => x.name), datasets: [{ data: g.map(x => x.value), backgroundColor: g.map((x, n) => palette[n % palette.length]) }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' },
                   tooltip: { callbacks: { label: c => c.label + ': ' + money(c.parsed) } } } },
      };
      note.textContent = (series === 'income' ? 'Income' : 'Spending') + ' year to date by category group' + (series === 'net' ? ' (expenses)' : '') + '.';
    }
    chart = new Chart(ctx, cfg);
  }
  function setChart(t, s) {
    if (t) tab = t;
    if (s) series = s;
    draw();
    savePref('budget_chart', tab + ':' + series);
  }
  document.querySelectorAll('.bv-chart-tab').forEach(b => b.addEventListener('click', () => setChart(b.dataset.tab, null)));
  document.querySelectorAll('.bv-chart-series').forEach(b => b.addEventListener('click', () => setChart(null, b.dataset.series)));

  // ── Table: groups, filters, sort ────────────────────────────
  function toggleGroup(head) { head.closest('tbody').classList.toggle('collapsed'); }
  function toggleAll(collapse) { document.querySelectorAll('.bv-group:not(.bv-group-single)').forEach(g => g.classList.toggle('collapsed', collapse)); }

  let filter = 'all';
  function applyFilter() {
    document.querySelectorAll('.bv-table-toolbar [data-filter]').forEach(b => b.classList.toggle('active', b.dataset.filter === filter));
    document.querySelectorAll('.bv-group').forEach(g => {
      let shown = 0;
      g.querySelectorAll('tr.bv-line').forEach(r => {
        const on = filter === 'all' || r.dataset.status === filter;
        r.classList.toggle('bv-filtered', !on);
        if (on) shown++;
      });
      g.classList.toggle('bv-filtered', shown === 0);
    });
  }
  document.querySelectorAll('.bv-table-toolbar [data-filter]').forEach(b => b.addEventListener('click', () => { filter = b.dataset.filter; applyFilter(); }));

  let [sortKey, sortDir] = <?= json_encode(explode(':', $sortPref)) ?>;
  const sortSel = document.getElementById('bvSort');
  const dirBtn  = document.getElementById('bvSortDir');
  function sortVal(el) { return sortKey === 'name' ? el.dataset.name : parseFloat(el.dataset[sortKey]); }
  function cmp(a, b) {
    const x = sortVal(a), y = sortVal(b);
    const r = typeof x === 'string' ? x.localeCompare(y) : x - y;
    return sortDir === 'asc' ? r : -r;
  }
  function applySort() {
    sortSel.value = sortKey;
    dirBtn.innerHTML = '<i class="bi ' + (sortDir === 'asc' ? 'bi-sort-up' : 'bi-sort-down') + '"></i>';
    document.querySelectorAll('.bv-table').forEach(t => {
      const groups = [...t.querySelectorAll(':scope > tbody.bv-group')].sort(cmp);
      const foot = t.querySelector(':scope > tfoot');
      groups.forEach(g => {
        const head  = g.querySelector('.bv-group-head');
        const lines = [...g.querySelectorAll('tr.bv-line')];
        // The parent's own line stays first under its header
        const own   = lines.filter(r => !r.classList.contains('bv-line-child'));
        const kids  = lines.filter(r => r.classList.contains('bv-line-child')).sort(cmp);
        [head, ...own, ...kids].filter(Boolean).forEach(r => g.appendChild(r));
        t.insertBefore(g, foot);
      });
    });
  }
  sortSel.addEventListener('change', () => { sortKey = sortSel.value; applySort(); savePref('budget_sort', sortKey + ':' + sortDir); });
  dirBtn.addEventListener('click', () => { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; applySort(); savePref('budget_sort', sortKey + ':' + sortDir); });

  // ── Line drawer ─────────────────────────────────────────────
  let drawerChart = null;
  function openLine(e, bcId) {
    if (e.target.closest('a, button, input, select')) return;
    const body = document.getElementById('bvDrawerBody');
    body.innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm"></span> Loading…</div>';
    bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('bvDrawer')).show();
    fetch(BASE + '/budget/line_txns.php?bc_id=' + bcId + '&year=' + YEAR + '&month=' + MONTH)
      .then(r => r.json())
      .then(d => { if (!d.ok) throw new Error(d.error || 'Error'); renderDrawer(d); })
      .catch(err => { body.innerHTML = '<div class="alert alert-danger">' + esc(err.message) + '</div>'; });
  }

  function renderDrawer(d) {
    const l = d.line;
    document.getElementById('bvDrawerTitle').textContent = l.name;
    const stat = (k, v) => '<div class="bv-stat"><div class="bv-stat-k">' + k + '</div><div class="bv-stat-v">' + v + '</div></div>';
    const type = { monthly: 'Monthly', annual: 'Annual', variable: 'Variable' }[l.entry_type];
    let html = '';
    if (l.alert) html += '<div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle-fill"></i> ' + esc(l.alert) + '</div>';
    html += '<div class="bv-stats">'
      + stat('Type', type + (l.due_months.length ? ' · due ' + l.due_months.map(m => MONTHS[m - 1]).join(', ') : '') + (l.rollover ? ' · rollover' : ''))
      + stat(MONTHS[MONTH - 1] + ' available', money(l.mon_available) + (Math.abs(l.carry_in) >= .005 ? ' <span class="small text-muted">(' + money(l.carry_in) + ' carried)</span>' : ''))
      + stat(MONTHS[MONTH - 1] + ' actual', money(l.mon_actual))
      + stat('YTD actual', money(l.ytd_actual))
      + stat('Expected by now', money(l.expected))
      + stat('Annual budget', money(l.annual_budget) + (l.pct_used !== null ? ' <span class="small text-muted">(' + Math.round(l.pct_used) + '% used)</span>' : ''))
      + stat('Last year, same period', money(l.ly_ytd))
      + stat('Projected year end', money(l.projected_total))
      + stat('Pace', l.pace_label ? esc(l.pace_label) : '—')
      + '</div>';
    html += '<div class="bv-drawer-chart"><canvas id="bvDrawerChart"></canvas></div>';
    html += '<h6 class="mt-3">By month</h6><table class="table table-sm bv-drawer-months"><thead><tr><th>Month</th><th class="text-end">Budget</th><th class="text-end">Actual</th><th class="text-end">Last Year</th></tr></thead><tbody>';
    for (let m = 0; m < 12; m++) {
      const past = m < MONTH;
      html += '<tr' + (m === MONTH - 1 ? ' class="table-active"' : '') + '><td>' + MONTHS[m] + '</td><td class="text-end">' + money(l.targets[m]) + '</td>'
            + '<td class="text-end">' + (past ? money(l.actuals[m]) : '<span class="text-muted">' + money(l.projected[m]) + ' proj.</span>') + '</td>'
            + '<td class="text-end">' + money(l.ly_actuals[m]) + '</td></tr>';
    }
    html += '</tbody></table>';
    html += '<h6 class="mt-3">Transactions, ' + YEAR + ' through ' + MONTHS[MONTH - 1] + ' <span class="text-muted small">(' + d.txns.length + ')</span></h6>';
    if (!d.txns.length) {
      html += '<p class="text-muted small">None.</p>';
    } else {
      html += '<div class="bv-drawer-txns"><table class="table table-sm"><thead><tr><th>Date</th><th>Payee</th><th class="text-end">Amount</th></tr></thead><tbody>';
      d.txns.forEach(t => {
        html += '<tr><td class="text-nowrap"><a href="' + esc(t.url) + '">' + esc(t.date) + '</a></td><td>' + esc(t.payee)
              + '<div class="small text-muted">' + esc(t.account) + (t.category ? ' · ' + esc(t.category) : '') + (t.memo ? ' · ' + esc(t.memo) : '') + '</div></td>'
              + '<td class="text-end' + (t.amount < 0 ? ' neg' : '') + '">' + money(t.amount) + '</td></tr>';
      });
      html += '</tbody></table></div>';
    }
    if (d.can_edit) html += editForm(l);
    document.getElementById('bvDrawerBody').innerHTML = html;

    if (drawerChart) drawerChart.destroy();
    const proj = cum(l.projected).map((v, n) => n >= MONTH - 1 ? v : null);
    drawerChart = new Chart(document.getElementById('bvDrawerChart'), {
      type: 'line',
      data: { labels: MONTHS, datasets: [
        { label: 'Budget', data: cum(l.targets), borderColor: '#0d6efd', borderDash: [6, 4], pointRadius: 0 },
        { label: 'Actual', data: cum(l.actuals).map((v, n) => n < MONTH ? v : null), borderColor: '#dc3545' },
        { label: 'Projected', data: proj, borderColor: '#fd7e14', borderDash: [3, 3], pointRadius: 0 },
        { label: 'Last year', data: cum(l.ly_actuals), borderColor: '#6c757d', borderWidth: 1.5, pointRadius: 0 },
      ] },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                 plugins: { legend: { labels: { boxWidth: 12 } }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + money(c.parsed.y ?? 0) } } },
                 scales: { y: { ticks: { callback: v => '$' + Number(v).toLocaleString() } } } },
    });
    const f = document.getElementById('bvLineForm');
    if (f) f.addEventListener('submit', saveLine);
  }

  function editForm(l) {
    let h = '<form id="bvLineForm" class="bv-line-form"><h6 class="mt-3"><i class="bi bi-pencil"></i> Edit line</h6>'
          + '<input type="hidden" name="bc_id" value="' + l.bc_id + '">';
    if (l.entry_type === 'variable') {
      h += '<div class="bv-line-months">';
      for (let m = 1; m <= 12; m++) h += '<label>' + MONTHS[m - 1] + '<input type="number" class="form-control form-control-sm" name="months[' + m + ']" min="0" step="0.01" value="' + l.targets[m - 1].toFixed(2) + '"></label>';
      h += '</div>';
    } else {
      h += '<label class="form-label small mb-1">' + (l.entry_type === 'annual' ? 'Annual amount' : 'Monthly amount') + '</label>'
         + '<div class="input-group input-group-sm mb-2" style="max-width:180px"><span class="input-group-text">$</span>'
         + '<input type="number" class="form-control" name="amount" min="0" step="0.01" value="' + Number(l.amount).toFixed(2) + '"></div>';
    }
    if (l.entry_type === 'annual') {
      h += '<div class="small text-muted mb-1">Due months</div><div class="wiz-due-months mb-2">';
      for (let m = 1; m <= 12; m++) h += '<label class="wiz-due-month" title="' + MONTHS[m - 1] + '"><input type="checkbox" name="due[]" value="' + m + '"' + (l.due_months.includes(m) ? ' checked' : '') + '><span>' + MONTHS[m - 1][0] + '</span></label>';
      h += '</div>';
    } else {
      h += '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="rollover" value="1" id="bvRoll"' + (l.rollover ? ' checked' : '') + '>'
         + '<label class="form-check-label small" for="bvRoll">Rollover unspent budget to later months</label></div>';
    }
    h += '<button type="submit" class="btn btn-sm btn-primary">Save</button> <span class="small text-muted ms-2">The page reloads with the new numbers.</span></form>';
    return h;
  }

  function saveLine(e) {
    e.preventDefault();
    const body = new URLSearchParams(new FormData(e.target));
    body.append('csrf_token', CSRF);
    e.target.querySelector('button[type=submit]').disabled = true;
    fetch(BASE + '/budget/save_line.php', { method: 'POST', body })
      .then(r => r.json())
      .then(j => {
        if (!j.ok) { showToast(j.error || 'Error saving.', 'error'); e.target.querySelector('button[type=submit]').disabled = false; return; }
        location.reload();
      })
      .catch(err => { console.error(err); showToast('Network error.', 'error'); e.target.querySelector('button[type=submit]').disabled = false; });
  }

  draw();
  applySort();
  return { toggleGroup, toggleAll, openLine };
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
