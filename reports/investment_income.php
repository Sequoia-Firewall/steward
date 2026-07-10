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

// ── Account filter (investment accounts only) ───────────────────
$allAccounts = $db->query(
    "SELECT id, name, type, is_retirement
     FROM accounts
     WHERE is_active = 1 AND is_closed = 0 AND is_investment_cash = 0
       AND type = 'Investment'
     ORDER BY CASE WHEN type = 'Investment' AND is_retirement = 1 THEN 'Retirement' ELSE type END, name"
)->fetchAll();

$allAcctIds = array_map('intval', array_column($allAccounts, 'id'));
$acctParam  = trim($_GET['accts'] ?? '');

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

if (!$filteringAccts) {
    $acctBtnLabel = 'All Accounts';
} elseif (count($selectedAcctIds) === 1) {
    $m = current(array_filter($allAccounts, fn($a) => (int)$a['id'] === $selectedAcctIds[0]));
    $acctBtnLabel = $m ? $m['name'] : '1 Account';
} else {
    $acctBtnLabel = count($selectedAcctIds) . ' Accounts';
}

// ── Reinvested dividends / cap-gain distributions + cash div/int ──
// Sourced entirely from investment_transactions.activity — no category
// needed; that's what keeps this separate from the Banking Income report.
$reinvestRows = [];
if (!empty($selectedAcctIds)) {
    $ph = implode(',', array_fill(0, count($selectedAcctIds), '?'));
    $stmt = $db->prepare(
        "SELECT t.transaction_date AS date, i.name AS inv_name, i.symbol, i.id AS inv_id,
                a.name AS acct_name, a.id AS acct_id, it.activity, it.quantity, it.price,
                t.amount AS txn_amount
         FROM investment_transactions it
         JOIN transactions t ON t.id = it.transaction_id
         JOIN investments  i ON i.id = it.investment_id
         JOIN accounts      a ON a.id = t.account_id
         WHERE it.activity IN ('reinvest_div', 'reinvest_cap', 'div', 'int')
           AND t.transaction_date BETWEEN ? AND ?
           AND a.id IN ($ph)
         ORDER BY t.transaction_date"
    );
    $stmt->execute([$startDate, $endDate, ...$selectedAcctIds]);
    $reinvestRows = $stmt->fetchAll();
}

// ── Build month axis ────────────────────────────────────────────
$allMonths = [];
$cursor = new DateTime(date('Y-m-01', strtotime($startDate)));
$endDt  = new DateTime(date('Y-m-01', strtotime($endDate)));
while ($cursor <= $endDt) {
    $allMonths[] = $cursor->format('Y-m');
    $cursor->modify('+1 month');
}
$numMonths = max(1, count($allMonths));

// ── Aggregate by type bucket (for tiles + chart + CSV) ─────────
$typeData   = [];
$grandTotal = 0.0;

$addToType = function (string $label, string $ym, float $amt) use (&$typeData, &$grandTotal) {
    if (!isset($typeData[$label])) {
        $typeData[$label] = ['total' => 0.0, 'count' => 0, 'months' => []];
    }
    $typeData[$label]['total'] += $amt;
    $typeData[$label]['count']++;
    $typeData[$label]['months'][$ym] = ($typeData[$label]['months'][$ym] ?? 0.0) + $amt;
    $grandTotal += $amt;
};

// ── By security (reinvested) ────────────────────────────────────
$bySecurity = [];
foreach ($reinvestRows as $r) {
    $ym  = substr($r['date'], 0, 7);
    $act = $r['activity'];
    $amt = in_array($act, ['div', 'int'])
        ? abs((float)$r['txn_amount'])
        : (float)$r['quantity'] * (float)$r['price'];
    $label = match($act) {
        'reinvest_cap' => 'Capital Gain Distributions (Reinvested)',
        'int'          => 'Interest',
        'div'          => 'Dividends (Cash)',
        default        => 'Dividends (Reinvested)',
    };
    $addToType($label, $ym, $amt);

    if (in_array($act, ['reinvest_div', 'reinvest_cap', 'div'])) {
        $key = $r['inv_id'] . ':' . $r['acct_id'];
        if (!isset($bySecurity[$key])) {
            $bySecurity[$key] = [
                'name' => $r['inv_name'], 'symbol' => $r['symbol'], 'acct_name' => $r['acct_name'],
                'dividends' => 0.0, 'capgains' => 0.0, 'count' => 0,
            ];
        }
        if ($act === 'reinvest_cap') $bySecurity[$key]['capgains']  += $amt;
        else                         $bySecurity[$key]['dividends'] += $amt;
        $bySecurity[$key]['count']++;
    }
}
foreach ($bySecurity as &$s) $s['total'] = $s['dividends'] + $s['capgains'];
unset($s);
uasort($bySecurity, fn($a, $b) => $b['total'] <=> $a['total']);

uasort($typeData, fn($a, $b) => $b['total'] <=> $a['total']);

$dividendTotal = ($typeData['Dividends (Cash)']['total'] ?? 0) + ($typeData['Dividends (Reinvested)']['total'] ?? 0);
$interestTotal = $typeData['Interest']['total'] ?? 0;
$capGainDistTotal = $typeData['Capital Gain Distributions (Reinvested)']['total'] ?? 0;

$palette = [
    '#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f',
    '#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac',
];
$chartDatasets = [];
$pi = 0;
foreach ($typeData as $label => $d) {
    $monthValues = [];
    foreach ($allMonths as $ym) $monthValues[] = round($d['months'][$ym] ?? 0.0, 2);
    $color = $palette[$pi % count($palette)];
    $chartDatasets[] = [
        'label' => $label, 'data' => $monthValues,
        'backgroundColor' => $color, 'borderColor' => $color, 'borderWidth' => 1,
    ];
    $pi++;
}
$chartLabels = array_map(fn($ym) => date('M Y', strtotime($ym . '-01')), $allMonths);

if (($_GET['export'] ?? '') === 'csv') {
    $headers = array_merge(['Type'], array_map(fn($ym) => date('M Y', strtotime($ym.'-01')), $allMonths), ['Total']);
    $csvRows = [];
    foreach ($typeData as $label => $d) {
        $row = [$label];
        foreach ($allMonths as $ym) {
            $row[] = ($d['months'][$ym] ?? 0) != 0 ? number_format($d['months'][$ym], 2, '.', '') : '';
        }
        $row[] = number_format($d['total'], 2, '.', '');
        $csvRows[] = $row;
    }
    $totRow = ['Total'];
    foreach ($allMonths as $ym) {
        $mo = 0.0;
        foreach ($typeData as $d) $mo += $d['months'][$ym] ?? 0.0;
        $totRow[] = number_format($mo, 2, '.', '');
    }
    $totRow[] = number_format($grandTotal, 2, '.', '');
    $csvRows[] = $totRow;
    outputCsv('investment_income_' . $startDate . '_' . $endDate . '.csv', $headers, $csvRows);
}

$pageTitle   = 'Investment Income';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Investment Income'; $reportFavIcon = 'bi-cash-coin'; ?>
<div class="page-header">
  <h2><i class="bi bi-cash-coin"></i> Investment Income</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters" id="invIncomeForm">
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
    <input type="hidden" name="accts" id="iiAcctHidden" value="<?= h($acctParam) ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown">
        <span id="iiAcctLabel"><?= h($acctBtnLabel) ?></span>
      </button>
      <ul class="dropdown-menu acct-filter-menu p-2" style="max-height:320px;overflow-y:auto;min-width:220px">
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" id="iiAcctAll" <?= !$filteringAccts ? 'checked' : '' ?>>
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
            <input type="checkbox" class="ii-type-chk" data-type="<?= h($dispType) ?>">
            <span class="text-uppercase fw-semibold" style="font-size:.68rem;letter-spacing:.04em;color:#555"><?= h($dispType) ?></span>
          </label>
        </li>
        <?php endif; ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center py-1">
            <input type="checkbox" class="ii-acct-chk" value="<?= (int)$a['id'] ?>"
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
      <a href="?start=<?= $s ?>&end=<?= $e ?>&accts=<?= h($acctParam) ?>"
         class="btn btn-sm btn-outline-secondary<?= ($startDate === $s && $endDate === $e) ? ' active' : '' ?>">
        <?= $rangeLabel ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</form>

<script>
(function () {
  const allChk  = document.getElementById('iiAcctAll');
  const chkList = Array.from(document.querySelectorAll('.ii-acct-chk'));
  const typChks = Array.from(document.querySelectorAll('.ii-type-chk'));
  const hidden  = document.getElementById('iiAcctHidden');
  const lbl     = document.getElementById('iiAcctLabel');

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

<?php if (empty($typeData)): ?>
<p class="text-muted mt-3">No investment income found for the selected period.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-income">
    <div class="tile-label">Total Investment Income</div>
    <div class="tile-value"><?= formatMoney($grandTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Dividends</div>
    <div class="tile-value"><?= formatMoney($dividendTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Interest</div>
    <div class="tile-value"><?= formatMoney($interestTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Cap Gain Distributions</div>
    <div class="tile-value"><?= formatMoney($capGainDistTotal) ?></div>
  </div>
</div>

<div class="report-chart-wrap">
  <canvas id="invIncomeChart" height="90"></canvas>
</div>

<div class="dash-section mt-4">
  <h6 class="mb-3">Income by Type</h6>
  <table class="table table-sm dash-table report-table">
    <thead>
      <tr>
        <th>Type</th>
        <th class="text-end">Transactions</th>
        <th class="text-end">Total</th>
        <th class="text-end">%</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($typeData as $label => $d):
        $pct = $grandTotal > 0 ? $d['total'] / $grandTotal * 100 : 0;
      ?>
      <tr>
        <td><?= h($label) ?></td>
        <td class="text-end text-muted"><?= $d['count'] ?></td>
        <td class="text-end amount-credit"><?= formatMoney($d['total']) ?></td>
        <td class="text-end text-muted"><?= round($pct, 1) ?>%</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="fw-bold">
        <td>Total</td>
        <td></td>
        <td class="text-end amount-credit"><?= formatMoney($grandTotal) ?></td>
        <td class="text-end">100%</td>
      </tr>
    </tfoot>
  </table>
</div>

<?php if (!empty($bySecurity)): ?>
<div class="dash-section mt-4">
  <h6 class="mb-3">Reinvested Activity by Security</h6>
  <p class="text-muted small">Dividends and capital gain distributions that were automatically reinvested into more shares.</p>
  <table class="table table-sm dash-table report-table">
    <thead>
      <tr>
        <th>Security</th>
        <th>Account</th>
        <th class="text-end">Transactions</th>
        <th class="text-end">Dividends</th>
        <th class="text-end">Cap Gain Dist.</th>
        <th class="text-end">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php $shown = 0; foreach ($bySecurity as $s): if (++$shown > 30) break; ?>
      <tr>
        <td>
          <?= h($s['name']) ?>
          <?php if ($s['symbol']): ?><span class="text-muted small ms-1"><?= h($s['symbol']) ?></span><?php endif; ?>
        </td>
        <td class="text-muted small"><?= h($s['acct_name']) ?></td>
        <td class="text-end text-muted"><?= $s['count'] ?></td>
        <td class="text-end"><?= $s['dividends'] > 0 ? formatMoney($s['dividends']) : '—' ?></td>
        <td class="text-end"><?= $s['capgains'] > 0 ? formatMoney($s['capgains']) : '—' ?></td>
        <td class="text-end amount-credit"><?= formatMoney($s['total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($bySecurity) > 30): ?>
  <p class="text-muted small">Showing top 30 of <?= count($bySecurity) ?> securities.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels   = <?= json_encode($chartLabels) ?>;
  const datasets = <?= json_encode($chartDatasets) ?>;

  new Chart(document.getElementById('invIncomeChart'), {
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
