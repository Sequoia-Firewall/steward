<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');

$defaultStart = date('Y-01-01');
$defaultEnd   = $today;

$startDate = $_GET['start'] ?? $defaultStart;
$endDate   = $_GET['end']   ?? $defaultEnd;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = $defaultStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = $defaultEnd;
if ($endDate < $startDate) $endDate = $startDate;

// Account filter — all account types, including investment accounts, so
// investment income (merged in below) can be scoped the same way. Investment-
// cash sub-accounts are pure cash sleeves for their parent investment account,
// so they're folded into the parent (via linked_account_id) below rather than
// listed as separate selectable rows.
$allAccounts = $db->query(
    "SELECT id, name, type, is_retirement FROM accounts
     WHERE is_active = 1 AND is_investment_cash = 0
     ORDER BY CASE
                WHEN type = 'Checking'                        THEN 1
                WHEN type = 'Savings'                          THEN 2
                WHEN type = 'Credit Card'                      THEN 3
                WHEN type = 'Investment' AND is_retirement = 0 THEN 4
                WHEN type = 'Investment' AND is_retirement = 1 THEN 5
                WHEN type = 'Asset'                            THEN 6
                WHEN type = 'Crypto'                           THEN 7
                ELSE 8
              END, name"
)->fetchAll();

$cashMap = [];
foreach ($db->query(
    "SELECT id, linked_account_id FROM accounts
     WHERE is_active = 1 AND is_investment_cash = 1 AND linked_account_id IS NOT NULL"
)->fetchAll() as $c) {
    $cashMap[(int)$c['linked_account_id']] = (int)$c['id'];
}

$allAcctIds = array_map('intval', array_column($allAccounts, 'id'));

$acctParam = trim($_GET['accts'] ?? '');
if ($acctParam === '' || $acctParam === 'all') {
    $selectedAcctIds = $allAcctIds;
    $filteringAccts  = false;
} else {
    $parsed = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $acctParam)),
        fn($id) => in_array($id, $allAcctIds, true)
    )));
    if (empty($parsed) || count($parsed) >= count($allAcctIds)) {
        $selectedAcctIds = $allAcctIds;
        $filteringAccts  = false;
    } else {
        $selectedAcctIds = $parsed;
        $filteringAccts  = true;
    }
}

// Expand the SQL scope with each selected account's paired investment-cash
// sub-account, so folding it into the parent doesn't drop its transactions.
$queryAcctIds = $selectedAcctIds;
foreach ($selectedAcctIds as $id) {
    if (isset($cashMap[$id])) $queryAcctIds[] = $cashMap[$id];
}

if (!$filteringAccts) {
    $acctBtnLabel = 'All Accounts';
} elseif (count($selectedAcctIds) === 1) {
    $m = current(array_filter($allAccounts, fn($a) => (int)$a['id'] === $selectedAcctIds[0]));
    $acctBtnLabel = $m ? $m['name'] : '1 Account';
} else {
    $acctBtnLabel = count($selectedAcctIds) . ' Accounts';
}

$ph         = implode(',', array_fill(0, count($queryAcctIds), '?'));
$acctWhere  = "AND t.account_id IN ($ph)";
$acctParams = $queryAcctIds;

$stmt = $db->prepare(
    "SELECT
       COALESCE(cp.id,   c.id)   AS parent_id,
       COALESCE(cp.name, c.name) AS parent_name,
       IF(cp.id IS NOT NULL, c.name, sc.name) AS sub_name,
       DATE_FORMAT(t.transaction_date, '%Y-%m') AS ym,
       t.payee,
       ABS(ts.amount) AS amount
     FROM transaction_splits ts
     JOIN transactions t  ON t.id  = ts.transaction_id
     JOIN categories   c  ON c.id  = ts.category_id
     LEFT JOIN categories cp ON cp.id = c.parent_id
     LEFT JOIN categories sc ON sc.id = ts.subcategory_id
     WHERE c.type = 'income'
       AND t.type != 'transfer'
       AND t.transaction_date BETWEEN ? AND ?
       $acctWhere
     ORDER BY parent_name, ym"
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
$numMonths = max(1, count($allMonths));

$catData   = [];
$payeeData = [];
$grandTotal = 0.0;

foreach ($rows as $r) {
    $pid  = (int)$r['parent_id'];
    $pname = $r['parent_name'];
    $ym   = $r['ym'];
    $amt  = (float)$r['amount'];

    if (!isset($catData[$pid])) {
        $catData[$pid] = ['name' => $pname, 'total' => 0.0, 'months' => [], 'subs' => []];
    }
    $catData[$pid]['total'] += $amt;
    $catData[$pid]['months'][$ym] = ($catData[$pid]['months'][$ym] ?? 0.0) + $amt;

    $sub = $r['sub_name'] ?? '';
    if ($sub !== '' && $sub !== null) {
        $catData[$pid]['subs'][$sub] = ($catData[$pid]['subs'][$sub] ?? 0.0) + $amt;
    }

    $payee = $r['payee'] ?? '';
    if ($payee !== '') {
        if (!isset($payeeData[$payee])) $payeeData[$payee] = ['total' => 0.0, 'count' => 0, 'kind' => 'regular'];
        $payeeData[$payee]['total'] += $amt;
        $payeeData[$payee]['count']++;
    }

    $grandTotal += $amt;
}

// ── Investment income (dividends, interest, reinvested distributions) ──
// Sourced from investment_transactions.activity — these postings carry no
// transaction_splits/category, so they're invisible to the query above.
if (!empty($queryAcctIds)) {
    $stmt2 = $db->prepare(
        "SELECT t.transaction_date AS date, i.id AS investment_id, i.name AS inv_name, i.symbol,
                it.activity, it.quantity, it.price, t.amount AS txn_amount
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         JOIN investments  i ON i.id = it.investment_id
         WHERE it.activity IN ('reinvest_div', 'reinvest_cap', 'div', 'int')
           AND t.transaction_date BETWEEN ? AND ?
           AND t.account_id IN ($ph)"
    );
    $stmt2->execute(array_merge([$startDate, $endDate], $queryAcctIds));

    $invKey = 'investment_income';
    foreach ($stmt2->fetchAll() as $r) {
        $ym  = substr($r['date'], 0, 7);
        $act = $r['activity'];
        $amt = in_array($act, ['div', 'int'])
            ? abs((float)$r['txn_amount'])
            : (float)$r['quantity'] * (float)$r['price'];
        $subLabel = match ($act) {
            'reinvest_cap' => 'Capital Gain Distributions',
            'int'          => 'Interest',
            'div'          => 'Dividends (Cash)',
            default        => 'Dividends (Reinvested)',
        };

        if (!isset($catData[$invKey])) {
            $catData[$invKey] = ['name' => 'Investment Income', 'total' => 0.0, 'months' => [], 'subs' => []];
        }
        $catData[$invKey]['total'] += $amt;
        $catData[$invKey]['months'][$ym] = ($catData[$invKey]['months'][$ym] ?? 0.0) + $amt;
        $catData[$invKey]['subs'][$subLabel] = ($catData[$invKey]['subs'][$subLabel] ?? 0.0) + $amt;

        // Display label only — the real transactions.payee text for these postings
        // (raw broker-statement strings) often doesn't match i.name/symbol at all,
        // so this label can't be used to search transactions by payee text. Link to
        // the security's own transaction history instead (via investment_id).
        $payee = trim($r['inv_name'] . ($r['symbol'] ? ' (' . $r['symbol'] . ')' : ''));
        if ($payee !== '') {
            if (!isset($payeeData[$payee])) {
                $payeeData[$payee] = ['total' => 0.0, 'count' => 0, 'kind' => 'investment', 'investment_id' => (int)$r['investment_id']];
            }
            $payeeData[$payee]['total'] += $amt;
            $payeeData[$payee]['count']++;
        }

        $grandTotal += $amt;
    }
}

uasort($catData, fn($a, $b) => $b['total'] <=> $a['total']);
arsort($payeeData);

$monthlyAvg = $grandTotal / $numMonths;
$topSource  = '';
$topAmt     = 0.0;
foreach ($catData as $c) {
    if ($c['total'] > $topAmt) { $topAmt = $c['total']; $topSource = $c['name']; }
}

$palette = [
    '#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f',
    '#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac',
];

$chartDatasets = [];
$pi = 0;
foreach ($catData as $cat) {
    $monthValues = [];
    foreach ($allMonths as $ym) {
        $monthValues[] = round($cat['months'][$ym] ?? 0.0, 2);
    }
    $color = $palette[$pi % count($palette)];
    $chartDatasets[] = [
        'label'           => $cat['name'],
        'data'            => $monthValues,
        'backgroundColor' => $color,
        'borderColor'     => $color,
        'borderWidth'     => 1,
    ];
    $pi++;
}

$chartLabels = array_map(fn($ym) => date('M Y', strtotime($ym . '-01')), $allMonths);

if (($_GET['export'] ?? '') === 'csv') {
    $headers = array_merge(['Category'], array_map(fn($ym) => date('M Y', strtotime($ym.'-01')), $allMonths), ['Total', 'Avg/Month', '% of Total']);
    $csvRows = [];
    foreach ($catData as $cat) {
        $row = [$cat['name']];
        foreach ($allMonths as $ym) {
            $row[] = $cat['months'][$ym] > 0 ? number_format($cat['months'][$ym], 2, '.', '') : '';
        }
        $row[] = number_format($cat['total'], 2, '.', '');
        $row[] = number_format($cat['total'] / $numMonths, 2, '.', '');
        $row[] = $grandTotal > 0 ? round($cat['total'] / $grandTotal * 100, 1) . '%' : '0%';
        $csvRows[] = $row;
    }
    $totRow = ['Total'];
    foreach ($allMonths as $ym) {
        $mo = 0.0;
        foreach ($catData as $cat) $mo += $cat['months'][$ym] ?? 0.0;
        $totRow[] = number_format($mo, 2, '.', '');
    }
    $totRow[] = number_format($grandTotal, 2, '.', '');
    $totRow[] = number_format($monthlyAvg, 2, '.', '');
    $totRow[] = '100%';
    $csvRows[] = $totRow;
    outputCsv('income_analysis_' . $startDate . '_' . $endDate . '.csv', $headers, $csvRows);
}

$acctQs = $filteringAccts ? '&accts=' . urlencode($acctParam) : '';
$baseQs = '?start=' . urlencode($startDate) . '&end=' . urlencode($endDate) . $acctQs;

// "Show all payees" toggle — table is capped at 25 rows by default.
$showAllPayees = ($_GET['show_payees'] ?? '') === 'all';
$payeeLimit    = $showAllPayees ? PHP_INT_MAX : 25;

// Only pass a single account through to the transaction-search drill-down link;
// search.php only supports one account at a time, so a multi-account filter here
// is left unrestricted there rather than silently narrowed to the wrong account.
$singleAcctId = ($filteringAccts && count($selectedAcctIds) === 1) ? $selectedAcctIds[0] : null;

$pageTitle   = 'Income Analysis';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Income Analysis'; $reportFavIcon = 'bi-cash-stack'; ?>
<div class="page-header">
  <h2><i class="bi bi-cash-stack"></i> Income Analysis</h2>
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
    <label>Accounts</label>
    <input type="hidden" name="accts" id="iaAcctHidden" value="<?= h($acctParam) ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown">
        <span id="iaAcctLabel"><?= h($acctBtnLabel) ?></span>
      </button>
      <ul class="dropdown-menu acct-filter-menu p-2" style="max-height:320px;overflow-y:auto;min-width:220px">
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" id="iaAcctAll" <?= !$filteringAccts ? 'checked' : '' ?>>
            <strong>All Accounts</strong>
          </label>
        </li>
        <li><hr class="dropdown-divider my-1"></li>
        <?php
        $prevDispType = '';
        foreach ($allAccounts as $a):
            $dispType = ($a['type'] === 'Investment' && !empty($a['is_retirement'])) ? 'Retirement' : $a['type'];
            if ($dispType !== $prevDispType):
                $prevDispType = $dispType;
        ?>
        <li class="px-3 pt-1 pb-0">
          <label class="d-flex gap-2 align-items-center" style="cursor:pointer;margin:0;padding:2px 0">
            <input type="checkbox" class="ia-type-chk" data-type="<?= h($dispType) ?>">
            <span class="text-uppercase fw-semibold" style="font-size:.68rem;letter-spacing:.04em;color:#555"><?= h($dispType) ?></span>
          </label>
        </li>
        <?php endif; ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center py-1">
            <input type="checkbox" class="ia-acct-chk" value="<?= (int)$a['id'] ?>"
                   data-name="<?= h($a['name']) ?>"
                   data-type="<?= h($dispType) ?>"
                   <?= in_array((int)$a['id'], $selectedAcctIds, true) ? 'checked' : '' ?>>
            <?= h($a['name']) ?>
          </label>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <script>
  (function () {
    const allChk  = document.getElementById('iaAcctAll');
    const chkList = Array.from(document.querySelectorAll('.ia-acct-chk'));
    const typChks = Array.from(document.querySelectorAll('.ia-type-chk'));
    const hidden  = document.getElementById('iaAcctHidden');
    const lbl     = document.getElementById('iaAcctLabel');

    function updateTypeState(type) {
      const ofType = chkList.filter(c => c.dataset.type === type);
      const n = ofType.filter(c => c.checked).length;
      const tc = typChks.find(t => t.dataset.type === type);
      if (!tc) return;
      tc.indeterminate = n > 0 && n < ofType.length;
      tc.checked = n === ofType.length;
    }

    function updateAcct() {
      const checked = chkList.filter(c => c.checked);
      const isAll   = checked.length === 0 || checked.length === chkList.length;
      hidden.value  = isAll ? '' : checked.map(c => c.value).join(',');
      lbl.textContent = isAll       ? 'All Accounts'
        : checked.length === 1      ? checked[0].dataset.name
        : checked.length + ' Accounts';
      allChk.indeterminate = !isAll && checked.length > 0;
      if (isAll) allChk.checked = checked.length === chkList.length;
      typChks.forEach(tc => updateTypeState(tc.dataset.type));
    }

    allChk.addEventListener('change', function () {
      chkList.forEach(c => c.checked = this.checked);
      typChks.forEach(tc => { tc.checked = this.checked; tc.indeterminate = false; });
      this.indeterminate = false;
      updateAcct();
    });
    typChks.forEach(tc => {
      tc.addEventListener('change', function () {
        chkList.filter(c => c.dataset.type === this.dataset.type).forEach(c => c.checked = this.checked);
        this.indeterminate = false;
        updateAcct();
      });
    });
    chkList.forEach(c => c.addEventListener('change', updateAcct));
    typChks.forEach(tc => updateTypeState(tc.dataset.type));
  })();
  </script>
  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <button type="submit" name="export" value="csv" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-download"></i> CSV
    </button>
    <div class="quick-ranges">
      <?php
      $ranges = [
        'This Year'  => [date('Y').'-01-01', date('Y-m-d')],
        'Last Year'  => [(date('Y')-1).'-01-01', (date('Y')-1).'-12-31'],
        'Last 12 Mo' => [date('Y-m-d', strtotime('-11 months first day of this month')), date('Y-m-d')],
        'This Month' => [date('Y-m-01'), date('Y-m-t')],
      ];
      foreach ($ranges as $rangeLabel => [$s, $e]):
      ?>
      <a href="?start=<?= $s ?>&end=<?= $e ?><?= $acctQs ?>"
         class="btn btn-sm btn-outline-secondary<?= ($startDate === $s && $endDate === $e) ? ' active' : '' ?>">
        <?= $rangeLabel ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</form>

<?php if (empty($catData)): ?>
<p class="text-muted mt-3">No income found for the selected period.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-income">
    <div class="tile-label">Total Income</div>
    <div class="tile-value"><?= formatMoney($grandTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Monthly Average</div>
    <div class="tile-value"><?= formatMoney($monthlyAvg) ?></div>
    <div class="tile-sub">over <?= $numMonths ?> month<?= $numMonths !== 1 ? 's' : '' ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Top Source</div>
    <div class="tile-value" style="font-size:1.1rem"><?= h($topSource) ?></div>
    <div class="tile-sub"><?= formatMoney($topAmt) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label"># of Months</div>
    <div class="tile-value"><?= $numMonths ?></div>
  </div>
</div>

<div class="report-chart-wrap">
  <canvas id="incomeChart" height="90"></canvas>
</div>

<div class="dash-section mt-4">
  <h6 class="mb-3">Income by Category</h6>
  <div class="table-responsive">
    <table class="table table-sm dash-table report-table">
      <thead>
        <tr>
          <th>Category</th>
          <?php foreach ($allMonths as $ym): ?>
          <th class="text-end"><?= date('M', strtotime($ym.'-01')) ?></th>
          <?php endforeach; ?>
          <th class="text-end">Total</th>
          <th class="text-end">Avg/Mo</th>
          <th class="text-end">%</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($catData as $cat):
          $pct = $grandTotal > 0 ? $cat['total'] / $grandTotal * 100 : 0;
        ?>
        <tr>
          <td>
            <?php if (!empty($cat['subs'])): ?>
            <details>
              <summary class="cursor-pointer"><?= h($cat['name']) ?></summary>
              <div class="ps-3 pt-1">
                <?php
                arsort($cat['subs']);
                foreach ($cat['subs'] as $sname => $samt):
                ?>
                <div class="d-flex justify-content-between small text-muted py-1 border-bottom">
                  <span><?= h($sname) ?></span>
                  <span class="amount-credit ms-3"><?= formatMoney($samt) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </details>
            <?php else: ?>
            <?= h($cat['name']) ?>
            <?php endif; ?>
          </td>
          <?php foreach ($allMonths as $ym):
            $moAmt = $cat['months'][$ym] ?? 0.0;
          ?>
          <td class="text-end <?= $moAmt > 0 ? 'amount-credit' : 'text-muted' ?>">
            <?= $moAmt > 0 ? formatMoney($moAmt) : '—' ?>
          </td>
          <?php endforeach; ?>
          <td class="text-end fw-semibold amount-credit"><?= formatMoney($cat['total']) ?></td>
          <td class="text-end"><?= formatMoney($cat['total'] / $numMonths) ?></td>
          <td class="text-end text-muted"><?= round($pct, 1) ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="fw-bold">
          <td>Total</td>
          <?php foreach ($allMonths as $ym):
            $mo = 0.0;
            foreach ($catData as $cat) $mo += $cat['months'][$ym] ?? 0.0;
          ?>
          <td class="text-end <?= $mo > 0 ? 'amount-credit' : '' ?>">
            <?= $mo > 0 ? formatMoney($mo) : '—' ?>
          </td>
          <?php endforeach; ?>
          <td class="text-end amount-credit"><?= formatMoney($grandTotal) ?></td>
          <td class="text-end"><?= formatMoney($monthlyAvg) ?></td>
          <td class="text-end">100%</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php if (!empty($payeeData)): ?>
<div class="dash-section mt-4">
  <h6 class="mb-3">Top Payees by Income</h6>
  <table class="table table-sm dash-table report-table">
    <thead>
      <tr>
        <th>Payee</th>
        <th class="text-end">Transactions</th>
        <th class="text-end">Total</th>
        <th class="text-end">% of Total</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $shown = 0;
      foreach ($payeeData as $payee => $pd):
        if (++$shown > $payeeLimit) break;
        $pct = $grandTotal > 0 ? $pd['total'] / $grandTotal * 100 : 0;
        if (($pd['kind'] ?? 'regular') === 'investment') {
          // Dividend/interest postings' real transactions.payee text is a raw
          // broker string that doesn't match this label, so link to the
          // security's own transaction history instead of a payee text search.
          $txnHref = BASE_PATH . '/portfolio/security/' . $pd['investment_id'];
          $txnTitle = 'View transaction history for ' . $payee;
        } else {
          $txnHref = BASE_PATH . '/transactions/search?q=' . urlencode($payee)
                   . '&start=' . urlencode($startDate) . '&end=' . urlencode($endDate)
                   . ($singleAcctId !== null ? '&account=' . $singleAcctId : '');
          $txnTitle = 'View transactions for ' . $payee . ' in a new window';
        }
      ?>
      <tr>
        <td>
          <a href="<?= h($txnHref) ?>" class="payee-name-link" target="_blank" rel="noopener noreferrer"
             title="<?= h($txnTitle) ?>"><?= h($payee) ?></a>
        </td>
        <td class="text-end text-muted"><?= $pd['count'] ?></td>
        <td class="text-end amount-credit"><?= formatMoney($pd['total']) ?></td>
        <td class="text-end text-muted"><?= round($pct, 1) ?>%</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$showAllPayees && count($payeeData) > 25): ?>
  <p class="text-muted small">
    Showing top 25 of <?= count($payeeData) ?> payees.
    <a href="<?= h($baseQs . '&show_payees=all') ?>">Show all</a>
  </p>
  <?php elseif ($showAllPayees && count($payeeData) > 25): ?>
  <p class="text-muted small">
    Showing all <?= count($payeeData) ?> payees.
    <a href="<?= h($baseQs) ?>">Show top 25</a>
  </p>
  <?php endif; ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels   = <?= json_encode($chartLabels) ?>;
  const datasets = <?= json_encode($chartDatasets) ?>;

  new Chart(document.getElementById('incomeChart'), {
    type: 'bar',
    data: { labels, datasets },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'top' } },
      scales: {
        x: { stacked: true },
        y: { stacked: true, ticks: { callback: v => '$' + v.toLocaleString() } }
      }
    }
  });
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
