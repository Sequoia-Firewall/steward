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

// ── Account filter (any account that can carry cash interest: bank
//    accounts plus investment cash-sweep sub-accounts) ─────────────
$allAccounts = $db->query(
    "SELECT id, name, type, is_investment_cash
     FROM accounts
     WHERE is_active = 1 AND is_closed = 0
       AND (type IN ('Checking','Savings','Credit Card') OR is_investment_cash = 1)
     ORDER BY CASE WHEN is_investment_cash = 1 THEN 'Investment Cash' ELSE type END, name"
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

$bankIncCatId = (int)($db->query(
    "SELECT id FROM categories WHERE name = 'Banking Income' AND parent_id IS NULL LIMIT 1"
)->fetchColumn() ?: 0);

// ── Cash-coded splits under the Banking Income category ────────────
$rows = [];
if ($bankIncCatId && !empty($selectedAcctIds)) {
    $ph = implode(',', array_fill(0, count($selectedAcctIds), '?'));
    $stmt = $db->prepare(
        "SELECT t.transaction_date AS date, t.payee, a.name AS acct_name, a.id AS acct_id,
                sc.name AS sub_name, ts.amount
         FROM transaction_splits ts
         JOIN transactions t ON t.id = ts.transaction_id
         JOIN accounts     a ON a.id = t.account_id
         LEFT JOIN categories sc ON sc.id = ts.subcategory_id
         WHERE ts.category_id = ?
           AND t.transaction_date BETWEEN ? AND ?
           AND a.id IN ($ph)
         ORDER BY t.transaction_date"
    );
    $stmt->execute([$bankIncCatId, $startDate, $endDate, ...$selectedAcctIds]);
    $rows = $stmt->fetchAll();
}

function bankBucketLabel(?string $subName): string {
    return match ($subName) {
        'Bank Interests' => 'Bank Interest',
        'Cash Rewards'   => 'Cash Rewards',
        default          => 'Banking Income (Other)',
    };
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

// ── By account ───────────────────────────────────────────────────
$byAccount = [];
// ── By payee ─────────────────────────────────────────────────────
$byPayee = [];

foreach ($rows as $r) {
    $ym    = substr($r['date'], 0, 7);
    $amt   = (float)$r['amount'];
    $label = bankBucketLabel($r['sub_name']);
    $addToType($label, $ym, $amt);

    $acctKey = $r['acct_id'];
    if (!isset($byAccount[$acctKey])) {
        $byAccount[$acctKey] = ['name' => $r['acct_name'], 'total' => 0.0, 'count' => 0];
    }
    $byAccount[$acctKey]['total'] += $amt;
    $byAccount[$acctKey]['count']++;

    $payeeKey = $r['payee'] . '|' . $label;
    if (!isset($byPayee[$payeeKey])) {
        $byPayee[$payeeKey] = ['payee' => $r['payee'], 'type' => $label, 'accts' => [], 'total' => 0.0, 'count' => 0];
    }
    $byPayee[$payeeKey]['total'] += $amt;
    $byPayee[$payeeKey]['count']++;
    if (!in_array($r['acct_name'], $byPayee[$payeeKey]['accts'], true)) {
        $byPayee[$payeeKey]['accts'][] = $r['acct_name'];
    }
}

uasort($typeData, fn($a, $b) => $b['total'] <=> $a['total']);
uasort($byAccount, fn($a, $b) => $b['total'] <=> $a['total']);
uasort($byPayee, fn($a, $b) => $b['total'] <=> $a['total']);

$interestTotal = $typeData['Bank Interest']['total'] ?? 0;
$rewardsTotal  = $typeData['Cash Rewards']['total'] ?? 0;
$monthlyAvg    = $grandTotal / $numMonths;

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
    outputCsv('banking_income_' . $startDate . '_' . $endDate . '.csv', $headers, $csvRows);
}

$pageTitle   = 'Banking Income';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Banking Income'; $reportFavIcon = 'bi-piggy-bank'; ?>
<div class="page-header">
  <h2><i class="bi bi-piggy-bank"></i> Banking Income</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters" id="biForm">
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
    <input type="hidden" name="accts" id="biAcctHidden" value="<?= h($acctParam) ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown">
        <span id="biAcctLabel"><?= h($acctBtnLabel) ?></span>
      </button>
      <ul class="dropdown-menu acct-filter-menu p-2" style="max-height:320px;overflow-y:auto;min-width:220px">
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" id="biAcctAll" <?= !$filteringAccts ? 'checked' : '' ?>>
            <strong>All Accounts</strong>
          </label>
        </li>
        <li><hr class="dropdown-divider my-1"></li>
        <?php
        $prevDispType = '';
        foreach ($allAccounts as $a):
            $dispType = !empty($a['is_investment_cash']) ? 'Investment Cash' : $a['type'];
            if ($dispType !== $prevDispType):
                $prevDispType = $dispType;
        ?>
        <li class="px-3 pt-1 pb-0">
          <label class="d-flex gap-2 align-items-center" style="cursor:pointer;margin:0;padding:2px 0">
            <input type="checkbox" class="bi-type-chk" data-type="<?= h($dispType) ?>">
            <span class="text-uppercase fw-semibold" style="font-size:.68rem;letter-spacing:.04em;color:#555"><?= h($dispType) ?></span>
          </label>
        </li>
        <?php endif; ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center py-1">
            <input type="checkbox" class="bi-acct-chk" value="<?= (int)$a['id'] ?>"
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
  const allChk  = document.getElementById('biAcctAll');
  const chkList = Array.from(document.querySelectorAll('.bi-acct-chk'));
  const typChks = Array.from(document.querySelectorAll('.bi-type-chk'));
  const hidden  = document.getElementById('biAcctHidden');
  const lbl     = document.getElementById('biAcctLabel');

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
<p class="text-muted mt-3">No banking income found for the selected period.</p>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-income">
    <div class="tile-label">Total Banking Income</div>
    <div class="tile-value"><?= formatMoney($grandTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Bank Interest</div>
    <div class="tile-value"><?= formatMoney($interestTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Cash Rewards</div>
    <div class="tile-value"><?= formatMoney($rewardsTotal) ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Monthly Average</div>
    <div class="tile-value"><?= formatMoney($monthlyAvg) ?></div>
    <div class="tile-sub">over <?= $numMonths ?> month<?= $numMonths !== 1 ? 's' : '' ?></div>
  </div>
</div>

<div class="report-chart-wrap">
  <canvas id="bankIncomeChart" height="90"></canvas>
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

<?php if (!empty($byAccount)): ?>
<div class="dash-section mt-4">
  <h6 class="mb-3">Income by Account</h6>
  <table class="table table-sm dash-table report-table">
    <thead>
      <tr>
        <th>Account</th>
        <th class="text-end">Transactions</th>
        <th class="text-end">Total</th>
        <th class="text-end">%</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($byAccount as $a):
        $pct = $grandTotal > 0 ? $a['total'] / $grandTotal * 100 : 0;
      ?>
      <tr>
        <td><?= h($a['name']) ?></td>
        <td class="text-end text-muted"><?= $a['count'] ?></td>
        <td class="text-end amount-credit"><?= formatMoney($a['total']) ?></td>
        <td class="text-end text-muted"><?= round($pct, 1) ?>%</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if (!empty($byPayee)): ?>
<div class="dash-section mt-4">
  <h6 class="mb-3">Income by Payee</h6>
  <table class="table table-sm dash-table report-table">
    <thead>
      <tr>
        <th>Payee</th>
        <th>Type</th>
        <th>Account(s)</th>
        <th class="text-end">Transactions</th>
        <th class="text-end">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php $shown = 0; foreach ($byPayee as $p): if (++$shown > 30) break; ?>
      <tr>
        <td><?= h($p['payee']) ?></td>
        <td class="text-muted small"><?= h($p['type']) ?></td>
        <td class="text-muted small"><?= h(implode(', ', $p['accts'])) ?></td>
        <td class="text-end text-muted"><?= $p['count'] ?></td>
        <td class="text-end amount-credit"><?= formatMoney($p['total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($byPayee) > 30): ?>
  <p class="text-muted small">Showing top 30 of <?= count($byPayee) ?> payee/type combinations.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels   = <?= json_encode($chartLabels) ?>;
  const datasets = <?= json_encode($chartDatasets) ?>;

  new Chart(document.getElementById('bankIncomeChart'), {
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
