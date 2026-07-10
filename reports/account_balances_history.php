<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');
$curY  = (int)date('Y');
$curM  = (int)date('m');

// ── Parameters ─────────────────────────────────────────────────
$group     = in_array($_GET['group'] ?? '', ['month', 'year']) ? $_GET['group'] : 'year';
$chartType = in_array($_GET['chart'] ?? '', ['line', 'stacked']) ? $_GET['chart'] : 'line';

$startYear  = isset($_GET['start_year'])  ? max(1990, min($curY, (int)$_GET['start_year']))  : $curY - 4;
$endYear    = isset($_GET['end_year'])    ? max(1990, min($curY, (int)$_GET['end_year']))    : $curY;
$startMonth = isset($_GET['start_month']) ? max(1, min(12, (int)$_GET['start_month']))       : 1;
$endMonth   = isset($_GET['end_month'])   ? max(1, min(12, (int)$_GET['end_month']))         : $curM;

// Enforce start ≤ end
if ($endYear < $startYear) $endYear = $startYear;
if ($group === 'month' && $endYear === $startYear && $endMonth < $startMonth) {
    $endMonth = $startMonth;
}

// ── Account filter (all types including investment) ────────────
$allAccounts = $db->query(
    "SELECT id, name, type, is_investment_cash, is_retirement, opening_balance
     FROM accounts
     WHERE is_active = 1 AND is_closed = 0 AND is_investment_cash = 0
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

$selectedAccounts = array_values(array_filter(
    $allAccounts, fn($a) => in_array((int)$a['id'], $selectedAcctIds, true)
));

// Account-filter button label
if (!$filteringAccts) {
    $acctBtnLabel = 'All Accounts';
} elseif (count($selectedAcctIds) === 1) {
    $m = reset(array_filter($allAccounts, fn($a) => (int)$a['id'] === $selectedAcctIds[0]));
    $acctBtnLabel = $m ? $m['name'] : '1 Account';
} else {
    $acctBtnLabel = count($selectedAcctIds) . ' Accounts';
}

// ── Build period end dates ──────────────────────────────────────
$periodPoints = []; // YYYY-MM-DD strings

if ($group === 'year') {
    for ($y = $startYear; $y <= $endYear; $y++) {
        $periodPoints[] = min($y . '-12-31', $today);
    }
} else {
    $cur = new DateTime($startYear . '-' . sprintf('%02d', $startMonth) . '-01');
    $end = new DateTime($endYear   . '-' . sprintf('%02d', $endMonth)   . '-01');
    while ($cur <= $end) {
        $periodPoints[] = min($cur->format('Y-m-t'), $today);
        $cur->modify('+1 month');
    }
}

// ── Period labels ───────────────────────────────────────────────
$periodLabels = [];
foreach ($periodPoints as $eop) {
    $periodLabels[$eop] = $group === 'year'
        ? substr($eop, 0, 4)
        : date('M Y', strtotime($eop));
}

// ── Fetch monthly transaction totals for selected accounts ──────
$periodBalances = []; // eop -> [acct_id -> balance]
$periodTotals   = []; // eop -> float

if (!empty($selectedAccounts) && !empty($periodPoints)) {
    $cutoff = end($periodPoints);
    $ph     = implode(',', array_fill(0, count($selectedAcctIds), '?'));
    $stmt   = $db->prepare(
        "SELECT account_id,
                DATE_FORMAT(transaction_date, '%Y-%m') AS ym,
                SUM(amount) AS month_total
         FROM transactions
         WHERE account_id IN ($ph)
           AND transaction_date <= ?
         GROUP BY account_id, ym
         ORDER BY account_id, ym"
    );
    $stmt->execute([...$selectedAcctIds, $cutoff]);
    $txnRows = $stmt->fetchAll();

    // Build per-account running total maps: aid -> [ym -> cumulative_sum]
    $cumulative = [];
    foreach ($txnRows as $r) {
        $aid = (int)$r['account_id'];
        $cumulative[$aid][$r['ym']] = ($cumulative[$aid][$r['ym']] ?? 0.0) + (float)$r['month_total'];
    }
    $accountRunning = [];
    foreach ($selectedAccounts as $acc) {
        $aid     = (int)$acc['id'];
        $running = 0.0;
        $runMap  = [];
        $sorted  = $cumulative[$aid] ?? [];
        ksort($sorted);
        foreach ($sorted as $ym => $tot) { $running += $tot; $runMap[$ym] = $running; }
        $accountRunning[$aid] = ['opening' => (float)$acc['opening_balance'], 'map' => $runMap];
    }

    // ── Historical market values for investment accounts ───────
    $investAccts   = array_filter($selectedAccounts, fn($a) => isInvestLike($a['type']));
    $investAcctIds = array_values(array_map(fn($a) => (int)$a['id'], $investAccts));
    $histMktValues = []; // eop -> [acct_id -> float]

    if (!empty($investAcctIds)) {
        $iph   = implode(',', array_fill(0, count($investAcctIds), '?'));
        $iStmt = $db->prepare(
            "SELECT t.account_id, it.investment_id, it.activity, it.quantity, it.price, t.transaction_date
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             WHERE t.account_id IN ($iph) AND t.transaction_date <= ?
             ORDER BY t.transaction_date"
        );
        $iStmt->execute([...$investAcctIds, $cutoff]);
        $iTxns  = $iStmt->fetchAll();
        $invIds = array_values(array_unique(array_column($iTxns, 'investment_id')));

        $priceAtEop = []; // inv_id -> [eop -> price]
        if (!empty($invIds)) {
            $pph   = implode(',', array_fill(0, count($invIds), '?'));
            $pStmt = $db->prepare(
                "SELECT investment_id, price_date, close_price
                 FROM investment_prices WHERE investment_id IN ($pph) AND price_date <= ?
                 ORDER BY investment_id, price_date"
            );
            $pStmt->execute([...$invIds, $cutoff]);
            $priceHist = [];
            foreach ($pStmt->fetchAll() as $p) {
                $priceHist[(int)$p['investment_id']][] = [$p['price_date'], (float)$p['close_price']];
            }
            foreach ($priceHist as $iid => $plist) {
                $latest = 0.0; $pi = 0; $np = count($plist);
                foreach ($periodPoints as $eop) {
                    while ($pi < $np && $plist[$pi][0] <= $eop) { $latest = $plist[$pi][1]; $pi++; }
                    $priceAtEop[$iid][$eop] = $latest;
                }
            }
        }

        // Fallback: for investments with no market price, forward-fill last known transaction price
        $noPriceSet = array_flip(array_diff($invIds, array_keys($priceHist)));
        if (!empty($noPriceSet)) {
            $txnPricesByInv = [];
            foreach ($iTxns as $row) {
                $iid = (int)$row['investment_id'];
                if (!isset($noPriceSet[$iid])) continue;
                $p = (float)$row['price'];
                if ($p > 0) $txnPricesByInv[$iid][] = [$row['transaction_date'], $p];
            }
            foreach ($txnPricesByInv as $iid => $plist) {
                $latest = 0.0; $pi = 0; $np = count($plist);
                foreach ($periodPoints as $eop) {
                    while ($pi < $np && $plist[$pi][0] <= $eop) { $latest = $plist[$pi][1]; $pi++; }
                    if ($latest > 0) $priceAtEop[$iid][$eop] = $latest;
                }
            }
        }

        // Accumulate holdings per period (single pass, incremental)
        $holdings = []; // acct_id -> [inv_id -> net_qty]
        $ti = 0; $nt = count($iTxns);
        foreach ($periodPoints as $eop) {
            while ($ti < $nt && $iTxns[$ti]['transaction_date'] <= $eop) {
                $row = $iTxns[$ti++];
                $aid = (int)$row['account_id'];
                $iid = (int)$row['investment_id'];
                $qty = (float)$row['quantity'];
                if (in_array($row['activity'], ['buy','add','split','reinvest_div','reinvest_cap'])) {
                    $holdings[$aid][$iid] = ($holdings[$aid][$iid] ?? 0.0) + $qty;
                } elseif (in_array($row['activity'], ['sell','remove'])) {
                    $holdings[$aid][$iid] = ($holdings[$aid][$iid] ?? 0.0) - $qty;
                }
            }
            foreach ($investAcctIds as $aid) {
                $mv = 0.0;
                foreach (($holdings[$aid] ?? []) as $iid => $qty) {
                    if ($qty < 0.000001) continue;
                    $mv += $qty * ($priceAtEop[$iid][$eop] ?? 0.0);
                }
                $histMktValues[$eop][$aid] = $mv;
            }
        }
    }

    // ── Balance per account per period ──────────────────────────
    foreach ($periodPoints as $eop) {
        $ym    = substr($eop, 0, 7);
        $total = 0.0;
        foreach ($selectedAccounts as $acc) {
            $aid = (int)$acc['id'];
            if (isInvestLike($acc['type'])) {
                $bal = $histMktValues[$eop][$aid] ?? 0.0;
            } else {
                $map    = $accountRunning[$aid]['map'];
                $txnTot = 0.0;
                foreach ($map as $tym => $tot) { if ($tym > $ym) break; $txnTot = $tot; }
                $bal = $accountRunning[$aid]['opening'] + $txnTot;
            }
            $periodBalances[$eop][$aid] = $bal;
            $total += $bal;
        }
        $periodTotals[$eop] = $total;
    }
}

// ── CSV export ─────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $hdrs = ['Period'];
    foreach ($selectedAccounts as $acc) $hdrs[] = $acc['name'];
    $hdrs[] = 'Total';
    $csvRows = [];
    foreach (array_reverse($periodPoints) as $eop) {
        $row = [$periodLabels[$eop]];
        foreach ($selectedAccounts as $acc) {
            $row[] = number_format($periodBalances[$eop][(int)$acc['id']] ?? 0.0, 2, '.', '');
        }
        $row[] = number_format($periodTotals[$eop] ?? 0.0, 2, '.', '');
        $csvRows[] = $row;
    }
    outputCsv('account_balances_history.csv', $hdrs, $csvRows);
}

// ── Chart datasets ─────────────────────────────────────────────
$chartLabels = array_values($periodLabels);
$palette = [
    '#0d6efd','#28a745','#dc3545','#fd7e14','#6f42c1',
    '#17a2b8','#e83e8c','#20c997','#ffc107','#6c757d',
    '#0dcaf0','#198754','#d63384','#ff6d00','#795548',
];

$chartDatasets = [];
foreach ($selectedAccounts as $i => $acc) {
    $aid    = (int)$acc['id'];
    $color  = $palette[$i % count($palette)];
    $pts    = count($periodPoints) <= 24 ? 3 : 2;
    $values = [];
    foreach ($periodPoints as $eop) $values[] = round($periodBalances[$eop][$aid] ?? 0.0, 2);
    if ($chartType === 'stacked') {
        $chartDatasets[] = [
            'label'           => $acc['name'],
            'data'            => $values,
            'borderColor'     => $color,
            'backgroundColor' => $color,
            'borderWidth'     => 1,
        ];
    } else {
        $chartDatasets[] = [
            'label'           => $acc['name'],
            'data'            => $values,
            'borderColor'     => $color,
            'backgroundColor' => 'transparent',
            'borderWidth'     => 2,
            'tension'         => 0.3,
            'pointRadius'     => $pts,
            'fill'            => false,
        ];
    }
}

// Total line (dashed, dark) — omitted in stacked mode since the bar stack already totals visually
if (count($selectedAccounts) > 1 && $chartType !== 'stacked') {
    $totValues = [];
    foreach ($periodPoints as $eop) $totValues[] = round($periodTotals[$eop] ?? 0.0, 2);
    $chartDatasets[] = [
        'label'           => 'Total',
        'data'            => $totValues,
        'borderColor'     => '#212529',
        'backgroundColor' => 'transparent',
        'borderWidth'     => 2.5,
        'borderDash'      => [6, 3],
        'tension'         => 0.3,
        'pointRadius'     => count($periodPoints) <= 24 ? 3 : 2,
        'fill'            => false,
    ];
}

$pageTitle   = 'Account Balances History';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<style>
.abh-table-wrap { overflow-x: auto; }
.abh-table th, .abh-table td { white-space: nowrap; }
.abh-table th:first-child, .abh-table td:first-child { position: sticky; left: 0; background: var(--bs-body-bg); z-index: 1; }
</style>

<?php $reportFavTitle = 'Account Balances History'; $reportFavIcon = 'bi-clock-history'; ?>
<div class="page-header">
  <h2><i class="bi bi-clock-history"></i> Account Balances History</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters" id="abhForm">

  <div class="filter-group">
    <label>Group By</label>
    <select name="group" class="form-select form-select-sm" id="abhGroup">
      <option value="year"  <?= $group === 'year'  ? 'selected' : '' ?>>Yearly (Dec 31)</option>
      <option value="month" <?= $group === 'month' ? 'selected' : '' ?>>Monthly</option>
    </select>
  </div>

  <div class="filter-group">
    <label>Chart Type</label>
    <select name="chart" class="form-select form-select-sm" id="abhChartType">
      <option value="line"    <?= $chartType === 'line'    ? 'selected' : '' ?>>Line</option>
      <option value="stacked" <?= $chartType === 'stacked' ? 'selected' : '' ?>>Stacked Bar</option>
    </select>
  </div>

  <div class="filter-group">
    <label>From</label>
    <div class="d-flex gap-1 align-items-center">
      <select name="start_month" class="form-select form-select-sm abh-month-sel" style="width:auto"
              <?= $group === 'year' ? 'hidden' : '' ?>>
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m === $startMonth ? 'selected' : '' ?>><?= date('M', mktime(0,0,0,$m,1)) ?></option>
        <?php endfor; ?>
      </select>
      <select name="start_year" class="form-select form-select-sm" style="width:auto">
        <?php for ($y = $curY; $y >= 1990; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $startYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
  </div>

  <div class="filter-group">
    <label>To</label>
    <div class="d-flex gap-1 align-items-center">
      <select name="end_month" class="form-select form-select-sm abh-month-sel" style="width:auto"
              <?= $group === 'year' ? 'hidden' : '' ?>>
        <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m === $endMonth ? 'selected' : '' ?>><?= date('M', mktime(0,0,0,$m,1)) ?></option>
        <?php endfor; ?>
      </select>
      <select name="end_year" class="form-select form-select-sm" style="width:auto">
        <?php for ($y = $curY; $y >= 1990; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $endYear ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </div>
  </div>

  <div class="filter-group">
    <label>Accounts</label>
    <input type="hidden" name="accts" id="abhAcctHidden" value="<?= h($acctParam) ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown">
        <span id="abhAcctLabel"><?= h($acctBtnLabel) ?></span>
      </button>
      <ul class="dropdown-menu acct-filter-menu p-2" style="max-height:320px;overflow-y:auto;min-width:220px">
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" id="abhAcctAll" <?= !$filteringAccts ? 'checked' : '' ?>>
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
            <input type="checkbox" class="abh-type-chk" data-type="<?= h($dispType) ?>">
            <span class="text-uppercase fw-semibold" style="font-size:.68rem;letter-spacing:.04em;color:#555"><?= h($dispType) ?></span>
          </label>
        </li>
        <?php endif; ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center py-1">
            <input type="checkbox" class="abh-acct-chk" value="<?= (int)$a['id'] ?>"
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
  </div>
</form>

<?php if (empty($selectedAccounts)): ?>
<p class="text-muted mt-3">No accounts selected.</p>
<?php elseif (empty($periodPoints)): ?>
<p class="text-muted mt-3">No periods in the selected range.</p>
<?php else: ?>

<div class="report-chart-wrap mb-4">
  <canvas id="abhChart" height="<?= count($selectedAccounts) > 6 ? 120 : 100 ?>"></canvas>
</div>

<div class="abh-table-wrap">
<table class="table table-sm report-table abh-table">
  <thead>
    <tr>
      <th>Period</th>
      <?php foreach ($selectedAccounts as $acc): ?>
      <th class="text-end"><?= h($acc['name']) ?></th>
      <?php endforeach; ?>
      <?php if (count($selectedAccounts) > 1): ?>
      <th class="text-end">Total</th>
      <?php endif; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach (array_reverse($periodPoints) as $eop):
      $tot = $periodTotals[$eop] ?? 0.0;
    ?>
    <tr>
      <td class="fw-medium"><?= h($periodLabels[$eop]) ?></td>
      <?php foreach ($selectedAccounts as $acc):
        $bal = $periodBalances[$eop][(int)$acc['id']] ?? 0.0;
      ?>
      <td class="text-end <?= $bal < 0 ? 'amount-debit' : '' ?>"><?= formatMoney($bal) ?></td>
      <?php endforeach; ?>
      <?php if (count($selectedAccounts) > 1): ?>
      <td class="text-end fw-semibold <?= $tot < 0 ? 'amount-debit' : 'amount-credit' ?>"><?= formatMoney($tot) ?></td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  const labels    = <?= json_encode($chartLabels) ?>;
  const datasets  = <?= json_encode($chartDatasets) ?>;
  const isStacked = <?= json_encode($chartType === 'stacked') ?>;

  new Chart(document.getElementById('abhChart'), {
    type: isStacked ? 'bar' : 'line',
    data: { labels, datasets },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          callbacks: {
            label: ctx => {
              const v = ctx.parsed.y;
              const sign = v < 0 ? '-' : '';
              return ctx.dataset.label + ': ' + sign + '$' + Math.abs(v).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
            },
          },
        },
      },
      scales: {
        x: { stacked: isStacked },
        y: {
          stacked: isStacked,
          ticks: {
            callback: v => {
              const sign = v < 0 ? '-' : '';
              return sign + '$' + Math.abs(v).toLocaleString();
            },
          },
        },
      },
    },
  });
})();
</script>

<?php endif; ?>

<script>
(function () {
  // Toggle month selects when group changes
  const groupSel  = document.getElementById('abhGroup');
  const monthSels = document.querySelectorAll('.abh-month-sel');

  groupSel.addEventListener('change', function () {
    const isMonth = this.value === 'month';
    monthSels.forEach(s => { s.hidden = !isMonth; });
    document.getElementById('abhForm').submit();
  });

  document.getElementById('abhChartType').addEventListener('change', function () {
    document.getElementById('abhForm').submit();
  });

  // Account filter
  const allChk  = document.getElementById('abhAcctAll');
  const chkList = Array.from(document.querySelectorAll('.abh-acct-chk'));
  const typChks = Array.from(document.querySelectorAll('.abh-type-chk'));
  const hidden  = document.getElementById('abhAcctHidden');
  const lbl     = document.getElementById('abhAcctLabel');

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

<?php include __DIR__ . '/../includes/footer.php'; ?>
