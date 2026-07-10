<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');

// ── Date range ─────────────────────────────────────────────────
$range = $_GET['range'] ?? 'year';

switch ($range) {
    case 'month':
        $startDate = date('Y-m-01');
        $endDate   = date('Y-m-t');
        break;
    case 'last30':
        $startDate = date('Y-m-d', strtotime('-29 days'));
        $endDate   = $today;
        break;
    case 'custom':
        $startDate = $_GET['start'] ?? date('Y-01-01');
        $endDate   = $_GET['end']   ?? date('Y-12-31');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-01-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = date('Y-12-31');
        if ($endDate < $startDate) $endDate = $startDate;
        break;
    case 'year':
    default:
        $range    = 'year';
        $selYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $startDate = $selYear . '-01-01';
        $endDate   = $selYear . '-12-31';
        break;
}

// Available years for the year preset selector
$years = $db->query(
    "SELECT DISTINCT YEAR(transaction_date) AS yr FROM transactions ORDER BY yr DESC"
)->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) $years = [(int)date('Y')];

// ── Account filter ─────────────────────────────────────────────
require_once __DIR__ . '/../includes/report_acct_filter.php';

// ── Category filter (income & expense, top-level + descendants) ─
require_once __DIR__ . '/../includes/report_cat_filter.php';

[$incomeTopCats, $incomeDescendants]   = loadCategoryFilterData($db, 'income');
[$expenseTopCats, $expenseDescendants] = loadCategoryFilterData($db, 'expense');
$allIncomeTopIds  = array_column($incomeTopCats, 'id');
$allExpenseTopIds = array_column($expenseTopCats, 'id');

$incCatParam = trim($_GET['inccats'] ?? '');
$expCatParam = trim($_GET['expcats'] ?? '');
[$selectedIncTopIds, $filteringIncCats] = parseCatTopSelection($incCatParam, $allIncomeTopIds);
[$selectedExpTopIds, $filteringExpCats] = parseCatTopSelection($expCatParam, $allExpenseTopIds);

$incCatBtnLabel = catTopBtnLabel($filteringIncCats, $selectedIncTopIds, $incomeTopCats, 'All Categories');
$expCatBtnLabel = catTopBtnLabel($filteringExpCats, $selectedExpTopIds, $expenseTopCats, 'All Categories');

// Canonical filter values for the transaction drill-down (passed to income_expense_detail.php)
$incCatQueryVal = $filteringIncCats ? implode(',', $selectedIncTopIds) : '';
$expCatQueryVal = $filteringExpCats ? implode(',', $selectedExpTopIds) : '';

$incCatWhere = ''; $incCatParams = [];
if ($filteringIncCats) {
    $incCatParams = expandCatTopSelection($selectedIncTopIds, $incomeDescendants);
    $incPh        = implode(',', array_fill(0, count($incCatParams), '?'));
    $incCatWhere  = "AND ts.category_id IN ($incPh)";
}

$expCatWhere = ''; $expCatParams = [];
if ($filteringExpCats) {
    $expCatParams = expandCatTopSelection($selectedExpTopIds, $expenseDescendants);
    $expPh        = implode(',', array_fill(0, count($expCatParams), '?'));
    $expCatWhere  = "AND ts.category_id IN ($expPh)";
}

// ── Investment income toggle (dividends/interest/cap-gain distributions) ──
// Sourced from investment_transactions.activity, same as reports/investment_income.php —
// these have no category/split, so they're normally invisible to this report.
$includeInvInc = ($_GET['incinv'] ?? '') === '1';

// ── Data query — grouped by year+month ────────────────────────
$incomeRows = $db->prepare(
    "SELECT YEAR(t.transaction_date) AS yr, MONTH(t.transaction_date) AS mo,
            ABS(SUM(ts.amount)) AS total
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories   c ON c.id = ts.category_id
     WHERE c.type = 'income'
       AND t.type != 'transfer'
       AND t.transaction_date BETWEEN ? AND ?
       $acctWhere
       $incCatWhere
     GROUP BY yr, mo ORDER BY yr, mo"
);
$incomeRows->execute(array_merge([$startDate, $endDate], $acctParams, $incCatParams));
$incomeMap = [];
foreach ($incomeRows->fetchAll() as $r) {
    $incomeMap[$r['yr'] . '-' . str_pad($r['mo'], 2, '0', STR_PAD_LEFT)] = (float)$r['total'];
}

// ── Investment income (dividends/interest/cap-gain distributions) ──────
$invIncomeMap  = [];
$totalInvInc   = 0.0;
if ($includeInvInc) {
    $invIncomeRows = $db->prepare(
        "SELECT YEAR(t.transaction_date) AS yr, MONTH(t.transaction_date) AS mo,
                SUM(CASE WHEN it.activity IN ('div','int') THEN ABS(t.amount)
                         ELSE it.quantity * it.price END) AS total
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         WHERE it.activity IN ('reinvest_div', 'reinvest_cap', 'div', 'int')
           AND t.transaction_date BETWEEN ? AND ?
         GROUP BY yr, mo ORDER BY yr, mo"
    );
    $invIncomeRows->execute([$startDate, $endDate]);
    foreach ($invIncomeRows->fetchAll() as $r) {
        $ym = $r['yr'] . '-' . str_pad($r['mo'], 2, '0', STR_PAD_LEFT);
        $invIncomeMap[$ym] = (float)$r['total'];
        $totalInvInc      += (float)$r['total'];
        $incomeMap[$ym]    = ($incomeMap[$ym] ?? 0) + (float)$r['total'];
    }
}

$expenseRows = $db->prepare(
    "SELECT YEAR(t.transaction_date) AS yr, MONTH(t.transaction_date) AS mo,
            ABS(SUM(ts.amount)) AS total
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories   c ON c.id = ts.category_id
     WHERE c.type = 'expense'
       AND t.type != 'transfer'
       AND t.transaction_date BETWEEN ? AND ?
       $acctWhere
       $expCatWhere
     GROUP BY yr, mo ORDER BY yr, mo"
);
$expenseRows->execute(array_merge([$startDate, $endDate], $acctParams, $expCatParams));
$expenseMap = [];
foreach ($expenseRows->fetchAll() as $r) {
    $expenseMap[$r['yr'] . '-' . str_pad($r['mo'], 2, '0', STR_PAD_LEFT)] = (float)$r['total'];
}

// Build rows for every month in the range
$months   = [];
$totalInc = 0;
$totalExp = 0;
$cursor   = new DateTime(date('Y-m-01', strtotime($startDate)));
$endDt    = new DateTime(date('Y-m-01', strtotime($endDate)));
while ($cursor <= $endDt) {
    $ym    = $cursor->format('Y-m');
    $inc   = $incomeMap[$ym]  ?? 0;
    $exp   = $expenseMap[$ym] ?? 0;
    $net   = $inc - $exp;
    $totalInc += $inc;
    $totalExp += $exp;
    // Intersect the calendar month with the report's overall date range, since
    // the first/last rows may be partial months (e.g. "Last 30 Days").
    $rowStart = max($cursor->format('Y-m-01'), $startDate);
    $rowEnd   = min($cursor->format('Y-m-t'),  $endDate);
    $months[] = ['label' => $cursor->format('M Y'), 'inc' => $inc, 'exp' => $exp, 'net' => $net,
                 'start' => $rowStart, 'end' => $rowEnd];
    $cursor->modify('+1 month');
}
$totalNet = $totalInc - $totalExp;

$chartLabels  = array_column($months, 'label');
$chartIncome  = array_column($months, 'inc');
$chartExpense = array_column($months, 'exp');
$chartNet     = array_column($months, 'net');

// ── CSV Export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($months as $row) {
        $csvRows[] = [$row['label'],
                      $row['inc'] > 0 ? number_format($row['inc'], 2, '.', '') : '',
                      $row['exp'] > 0 ? number_format($row['exp'], 2, '.', '') : '',
                      number_format($row['net'], 2, '.', '')];
    }
    $csvRows[] = ['Total',
                  number_format($totalInc, 2, '.', ''),
                  number_format($totalExp, 2, '.', ''),
                  number_format($totalNet, 2, '.', '')];
    outputCsv('income_expense_' . $startDate . '_' . $endDate . '.csv',
              ['Period', 'Income', 'Expenses', 'Net'], $csvRows);
}

$pageTitle   = 'Income vs. Expense';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Income vs. Expense'; $reportFavIcon = 'bi-arrow-left-right'; ?>
<div class="page-header">
  <h2><i class="bi bi-arrow-left-right"></i> Income vs. Expense</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters" id="ieForm">
  <input type="hidden" name="range" id="rangeHidden" value="<?= h($range) ?>">
  <!-- Custom date pickers (shown only for custom range) -->
  <div class="filter-group" id="customDates" style="<?= $range === 'custom' ? '' : 'display:none' ?>">
    <label>From</label>
    <input type="date" name="start" id="startDate" class="form-control form-control-sm"
           value="<?= h($range === 'custom' ? $startDate : '') ?>">
  </div>
  <div class="filter-group" id="customDatesEnd" style="<?= $range === 'custom' ? '' : 'display:none' ?>">
    <label>To</label>
    <input type="date" name="end" id="endDate" class="form-control form-control-sm"
           value="<?= h($range === 'custom' ? $endDate : '') ?>">
  </div>
  <!-- Year selector (shown only for year range) -->
  <div class="filter-group" id="yearGroup" style="<?= $range === 'year' ? '' : 'display:none' ?>">
    <label>Year</label>
    <select name="year" id="yearSel" class="form-select form-select-sm">
      <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>" <?= isset($selYear) && $y == $selYear ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php include __DIR__ . '/../includes/report_acct_filter_ui.php'; ?>
  <?php renderTopCatFilterDropdown('incCat', 'inccats', 'Income Categories', $incomeTopCats, $selectedIncTopIds, $filteringIncCats, $incCatBtnLabel); ?>
  <?php renderTopCatFilterDropdown('expCat', 'expcats', 'Expense Categories', $expenseTopCats, $selectedExpTopIds, $filteringExpCats, $expCatBtnLabel); ?>
  <div class="filter-group">
    <div class="form-check form-check-sm mt-4">
      <input class="form-check-input" type="checkbox" name="incinv" value="1"
             id="chkIncInv" <?= $includeInvInc ? 'checked' : '' ?>>
      <label class="form-check-label" for="chkIncInv">Include Investment Income
        <span class="text-muted">(dividends/interest)</span>
      </label>
    </div>
  </div>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
    <div class="quick-ranges">
      <?php
        $acctQs = $acctParam !== '' ? '&accts=' . urlencode($acctParam) : '';
        $catQs  = ($filteringIncCats ? '&inccats=' . urlencode(implode(',', $selectedIncTopIds)) : '')
                . ($filteringExpCats ? '&expcats=' . urlencode(implode(',', $selectedExpTopIds)) : '');
        $invQs  = $includeInvInc ? '&incinv=1' : '';
      ?>
      <a href="?range=month<?= $acctQs . $catQs . $invQs ?>"
         class="btn btn-sm btn-outline-secondary<?= $range==='month'?' active':'' ?>">This Month</a>
      <?php foreach ($years as $y): ?>
      <a href="?range=year&year=<?= $y ?><?= $acctQs . $catQs . $invQs ?>"
         class="btn btn-sm btn-outline-secondary<?= ($range==='year'&&isset($selYear)&&$selYear==$y)?' active':'' ?>"><?= $y ?></a>
      <?php endforeach; ?>
      <a href="?range=last30<?= $acctQs . $catQs . $invQs ?>"
         class="btn btn-sm btn-outline-secondary<?= $range==='last30'?' active':'' ?>">Last 30 Days</a>
      <a href="#" onclick="setCustomRange(); return false;"
         class="btn btn-sm btn-outline-secondary<?= $range==='custom'?' active':'' ?>">Custom…</a>
    </div>
  </div>
</form>

<!-- Summary tiles -->
<div class="report-tiles">
  <div class="report-tile tile-income">
    <div class="tile-label">Total Income</div>
    <div class="tile-value"><?= formatMoney($totalInc) ?></div>
    <?php if ($includeInvInc && $totalInvInc > 0): ?>
    <div class="tile-sub">includes <?= formatMoney($totalInvInc) ?> investment income</div>
    <?php endif; ?>
  </div>
  <?php if ($includeInvInc): ?>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Investment Income</div>
    <div class="tile-value"><?= formatMoney($totalInvInc) ?></div>
  </div>
  <?php endif; ?>
  <div class="report-tile tile-expense">
    <div class="tile-label">Total Expenses</div>
    <div class="tile-value"><?= formatMoney($totalExp) ?></div>
  </div>
  <div class="report-tile <?= $totalNet >= 0 ? 'tile-positive' : 'tile-negative' ?>">
    <div class="tile-label">Net Cash Flow</div>
    <div class="tile-value"><?= formatMoney($totalNet, true) ?></div>
  </div>
</div>

<!-- Bar chart -->
<div class="report-chart-wrap">
  <canvas id="ieChart" height="100"></canvas>
</div>

<!-- Monthly table -->
<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Period</th>
      <th class="text-end">Income</th>
      <th class="text-end">Expenses</th>
      <th class="text-end">Net</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($months as $row): ?>
    <tr>
      <td><?= $row['label'] ?></td>
      <td class="text-end amount-credit">
        <?php if ($row['inc'] > 0): ?>
        <a href="#" class="ie-amt-link" data-type="income" data-start="<?= h($row['start']) ?>" data-end="<?= h($row['end']) ?>" data-label="<?= h($row['label']) ?>"><?= formatMoney($row['inc']) ?></a>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="text-end amount-debit">
        <?php if ($row['exp'] > 0): ?>
        <a href="#" class="ie-amt-link" data-type="expense" data-start="<?= h($row['start']) ?>" data-end="<?= h($row['end']) ?>" data-label="<?= h($row['label']) ?>"><?= formatMoney($row['exp']) ?></a>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="text-end <?= $row['net'] >= 0 ? 'amount-credit' : 'amount-debit' ?>">
        <?= ($row['inc'] > 0 || $row['exp'] > 0) ? formatMoney($row['net'], true) : '—' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="fw-bold">
      <td>Total</td>
      <td class="text-end amount-credit">
        <?php if ($totalInc > 0): ?>
        <a href="#" class="ie-amt-link" data-type="income" data-start="<?= h($startDate) ?>" data-end="<?= h($endDate) ?>" data-label="Total"><?= formatMoney($totalInc) ?></a>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="text-end amount-debit">
        <?php if ($totalExp > 0): ?>
        <a href="#" class="ie-amt-link" data-type="expense" data-start="<?= h($startDate) ?>" data-end="<?= h($endDate) ?>" data-label="Total"><?= formatMoney($totalExp) ?></a>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="text-end <?= $totalNet >= 0 ? 'amount-credit' : 'amount-debit' ?>"><?= formatMoney($totalNet, true) ?></td>
    </tr>
  </tfoot>
</table>

<style>
.ie-sort-th { cursor: pointer; }
.ie-sort-th:hover { color: rgba(255,255,255,.75); }
</style>

<!-- ── Transaction Drill-down Modal ──────────────────────────────── -->
<div class="modal fade" id="ieDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="ieDetailTitle">Transactions</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="ieDetailLoading" class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Loading…</div>
        <div id="ieDetailEmpty" class="text-center text-muted py-4" style="display:none">No transactions found.</div>
        <div class="register-grid-wrapper" id="ieDetailWrap" style="display:none">
          <table class="register-grid search-results-table">
            <thead>
              <tr>
                <th class="ie-sort-th" data-sort="date">Date <i class="bi bi-arrow-down-up reg-sort-icon"></i></th>
                <th class="ie-sort-th" data-sort="account">Account <i class="bi bi-arrow-down-up reg-sort-icon"></i></th>
                <th class="ie-sort-th" data-sort="payee">Payee / Memo <i class="bi bi-arrow-down-up reg-sort-icon"></i></th>
                <th class="ie-sort-th" data-sort="category">Category <i class="bi bi-arrow-down-up reg-sort-icon"></i></th>
                <th class="text-end ie-sort-th" data-sort="amount">Amount <i class="bi bi-arrow-down-up reg-sort-icon"></i></th>
              </tr>
            </thead>
            <tbody id="ieDetailBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer py-2 d-flex justify-content-between">
        <span class="text-muted small" id="ieDetailCount"></span>
        <span class="fw-bold" id="ieDetailTotal"></span>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const filters = {
    accts:   <?= json_encode($acctParam) ?>,
    inccats: <?= json_encode($incCatQueryVal) ?>,
    expcats: <?= json_encode($expCatQueryVal) ?>,
    incinv:  <?= json_encode($includeInvInc ? '1' : '') ?>,
  };

  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function fmtUSD(n) {
    const v = parseFloat(n);
    const sign = v < 0 ? '-' : '';
    return sign + '$' + Math.abs(v).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
  }
  function fmtDate(iso) {
    const [y, m, d] = iso.split('-');
    return m + '/' + d + '/' + y;
  }

  let curTransactions = [];
  let sortCol = 'date';
  let sortDir = 'asc';

  document.addEventListener('click', function(e) {
    const link = e.target.closest('.ie-amt-link');
    if (link) {
      e.preventDefault();
      openIeDetail(link.dataset.type, link.dataset.start, link.dataset.end, link.dataset.label);
      return;
    }
    const th = e.target.closest('.ie-sort-th');
    if (th) {
      const col = th.dataset.sort;
      sortDir = (sortCol === col && sortDir === 'asc') ? 'desc' : 'asc';
      sortCol = col;
      renderSortIcons();
      renderRows(curTransactions);
    }
  });

  function renderSortIcons() {
    document.querySelectorAll('.ie-sort-th').forEach(th => {
      const icon = th.querySelector('.reg-sort-icon');
      if (th.dataset.sort !== sortCol) {
        icon.className = 'bi bi-arrow-down-up reg-sort-icon';
      } else {
        icon.className = 'bi ' + (sortDir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') + ' reg-sort-icon active';
      }
    });
  }

  function sortKey(t, col) {
    switch (col) {
      case 'date':     return t.date;
      case 'account':  return t.account_name.toLowerCase();
      case 'payee':    return t.payee.toLowerCase();
      case 'category': return (t.category_name || '').toLowerCase();
      case 'amount':   return t.amount;
      default:         return t.date;
    }
  }

  function renderRows(transactions) {
    const sorted = [...transactions].sort((a, b) => {
      const ka = sortKey(a, sortCol), kb = sortKey(b, sortCol);
      let cmp = ka < kb ? -1 : ka > kb ? 1 : 0;
      if (cmp === 0) cmp = a.id - b.id; // stable tiebreaker
      return sortDir === 'asc' ? cmp : -cmp;
    });
    const tbody = document.getElementById('ieDetailBody');
    tbody.innerHTML = sorted.map(t => {
      const registerUrl = <?= json_encode(BASE_PATH) ?> + '/accounts/register?id=' + t.account_id;
      const memo = t.memo ? `<div class="txn-memo">${esc(t.memo)}</div>` : '';
      const amtCls = t.amount < 0 ? 'amount-debit' : 'amount-credit';
      return `<tr class="register-row" onclick="window.location='${registerUrl}#txn-${t.id}'" title="Open in register">
        <td class="col-date text-nowrap">${fmtDate(t.date)}</td>
        <td class="col-acct-name">${esc(t.account_name)}</td>
        <td class="col-payee"><div class="payee-name">${esc(t.payee)}</div>${memo}</td>
        <td class="col-cat">${t.category_name ? esc(t.category_name) : '<span class="text-muted">—</span>'}</td>
        <td class="text-end ${amtCls}">${fmtUSD(t.amount)}</td>
      </tr>`;
    }).join('');
  }

  async function openIeDetail(type, start, end, label) {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('ieDetailModal'));
    document.getElementById('ieDetailTitle').textContent =
      (type === 'income' ? 'Income' : 'Expenses') + ' — ' + label;
    document.getElementById('ieDetailLoading').style.display = '';
    document.getElementById('ieDetailEmpty').style.display   = 'none';
    document.getElementById('ieDetailWrap').style.display    = 'none';
    document.getElementById('ieDetailCount').textContent     = '';
    document.getElementById('ieDetailTotal').textContent     = '';
    sortCol = 'date';
    sortDir = 'asc';
    renderSortIcons();
    modal.show();

    const params = new URLSearchParams({
      type, start, end,
      accts:   filters.accts,
      inccats: filters.inccats,
      expcats: filters.expcats,
      incinv:  filters.incinv,
    });

    try {
      const res  = await fetch(<?= json_encode(BASE_PATH) ?> + '/reports/income_expense_detail?' + params.toString());
      const data = await res.json();
      document.getElementById('ieDetailLoading').style.display = 'none';
      if (!data.ok || !data.transactions.length) {
        document.getElementById('ieDetailEmpty').style.display = '';
        return;
      }
      curTransactions = data.transactions;
      renderRows(curTransactions);
      document.getElementById('ieDetailWrap').style.display = '';
      document.getElementById('ieDetailCount').textContent  = data.count + ' transaction' + (data.count !== 1 ? 's' : '');
      document.getElementById('ieDetailTotal').textContent  = 'Total: ' + fmtUSD(Math.abs(data.total));
    } catch (e) {
      console.error(e);
      document.getElementById('ieDetailLoading').style.display = 'none';
      document.getElementById('ieDetailEmpty').style.display   = '';
      document.getElementById('ieDetailEmpty').textContent     = 'Failed to load transactions.';
    }
  }
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function setCustomRange() {
    document.getElementById('rangeHidden').value = 'custom';
    document.getElementById('customDates').style.display    = '';
    document.getElementById('customDatesEnd').style.display = '';
    document.getElementById('yearGroup').style.display      = 'none';
    if (!document.getElementById('startDate').value)
        document.getElementById('startDate').value = '<?= date('Y-01-01') ?>';
    if (!document.getElementById('endDate').value)
        document.getElementById('endDate').value = '<?= date('Y-12-31') ?>';
    document.getElementById('startDate').focus();
}
(function(){
  const labels  = <?= json_encode($chartLabels) ?>;
  const income  = <?= json_encode(array_map('floatval', $chartIncome)) ?>;
  const expense = <?= json_encode(array_map('floatval', $chartExpense)) ?>;
  const net     = <?= json_encode(array_map('floatval', $chartNet)) ?>;

  new Chart(document.getElementById('ieChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Income',   data: income,  backgroundColor: 'rgba(40,167,69,0.7)',  borderColor: 'rgba(40,167,69,1)',  borderWidth:1 },
        { label: 'Expenses', data: expense, backgroundColor: 'rgba(220,53,69,0.7)',  borderColor: 'rgba(220,53,69,1)',  borderWidth:1 },
        { label: 'Net',      data: net,     type: 'line', borderColor: 'rgba(13,110,253,1)', backgroundColor:'transparent', borderWidth:2, pointRadius:4, tension:0.3 }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode:'index', intersect:false },
      plugins: { legend:{ position:'top' } },
      scales: { y: { ticks:{ callback: v => '$'+v.toLocaleString() } } }
    }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
