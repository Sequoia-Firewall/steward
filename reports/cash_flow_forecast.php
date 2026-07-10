<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');

$days = isset($_GET['days']) ? (int)$_GET['days'] : 90;
$allowedDays = [30, 60, 90, 180, 365];
if (!in_array($days, $allowedDays, true)) $days = 90;

$endDate = date('Y-m-d', strtotime("+{$days} days"));

$cashTypes = "'Checking','Savings','Credit Card','Cash','Money Market','CD'";

$allAccounts = $db->query(
    "SELECT id, name, type, opening_balance
     FROM accounts
     WHERE is_active = 1 AND is_closed = 0
       AND type IN ($cashTypes)
     ORDER BY type, name"
)->fetchAll();

$allAcctIds = array_map('intval', array_column($allAccounts, 'id'));

$acctParam = trim($_GET['accts'] ?? '');
if ($acctParam === '' || $acctParam === 'all') {
    $selectedIds    = $allAcctIds;
    $filteringAccts = false;
} else {
    $parsed = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $acctParam)),
        fn($id) => in_array($id, $allAcctIds, true)
    )));
    if (empty($parsed) || count($parsed) >= count($allAcctIds)) {
        $selectedIds    = $allAcctIds;
        $filteringAccts = false;
    } else {
        $selectedIds    = $parsed;
        $filteringAccts = true;
    }
}

$selectedAccounts = array_values(array_filter(
    $allAccounts, fn($a) => in_array((int)$a['id'], $selectedIds, true)
));

if (!$filteringAccts) {
    $acctBtnLabel = 'All Accounts';
} elseif (count($selectedIds) === 1) {
    $m = reset(array_filter($allAccounts, fn($a) => (int)$a['id'] === $selectedIds[0]));
    $acctBtnLabel = $m ? $m['name'] : '1 Account';
} else {
    $acctBtnLabel = count($selectedIds) . ' Accounts';
}

$currentBalances = [];
if (!empty($selectedAccounts)) {
    $ph   = implode(',', array_fill(0, count($selectedIds), '?'));
    $stmt = $db->prepare(
        "SELECT a.id, a.opening_balance + COALESCE(SUM(t.amount), 0) AS balance
         FROM accounts a
         LEFT JOIN transactions t ON t.account_id = a.id AND t.transaction_date <= ?
         WHERE a.id IN ($ph)
         GROUP BY a.id, a.opening_balance"
    );
    $stmt->execute([$today, ...$selectedIds]);
    foreach ($stmt->fetchAll() as $r) {
        $currentBalances[(int)$r['id']] = (float)$r['balance'];
    }
}

$scheduledBills = $db->query(
    "SELECT sb.*, a.name AS account_name, ta.name AS to_account_name
     FROM scheduled_bills sb
     JOIN accounts a ON a.id = sb.account_id
     LEFT JOIN accounts ta ON ta.id = sb.to_account_id
     WHERE sb.is_active = 1
     ORDER BY sb.next_due_date ASC"
)->fetchAll();

$events = [];

foreach ($scheduledBills as $bill) {
    $srcId  = (int)$bill['account_id'];
    $dstId  = $bill['to_account_id'] ? (int)$bill['to_account_id'] : null;
    $srcSel = in_array($srcId, $selectedIds, true);
    $dstSel = $dstId !== null && in_array($dstId, $selectedIds, true);

    if (!$srcSel && !$dstSel) continue;

    $freq       = $bill['frequency'];
    $dueDate    = $bill['next_due_date'];
    $amount     = (float)$bill['amount'];
    $isTransfer = ($bill['type'] === 'transfer' && $dstId !== null);

    $cur         = $dueDate;
    $occurrences = 0;

    while ($cur <= $endDate && $occurrences < 400) {
        if ($cur >= $today) {
            if ($isTransfer) {
                if ($srcSel) {
                    $events[] = [
                        'date'       => $cur,
                        'desc'       => $bill['name'],
                        'type'       => 'transfer-out',
                        'amount'     => -$amount,
                        'account_id' => $srcId,
                        'account'    => $bill['account_name'],
                    ];
                }
                if ($dstSel) {
                    $events[] = [
                        'date'       => $cur,
                        'desc'       => $bill['name'],
                        'type'       => 'transfer-in',
                        'amount'     => $amount,
                        'account_id' => $dstId,
                        'account'    => $bill['to_account_name'],
                    ];
                }
            } else {
                $type = $bill['type'] === 'deposit' ? 'deposit' : 'bill';
                $sign = $type === 'deposit' ? $amount : -$amount;
                if ($srcSel) {
                    $events[] = [
                        'date'       => $cur,
                        'desc'       => $bill['name'],
                        'type'       => $type,
                        'amount'     => $sign,
                        'account_id' => $srcId,
                        'account'    => $bill['account_name'],
                    ];
                }
            }
        }

        if ($freq === 'once') break;
        $next = advanceDueDate($cur, $freq);
        if ($next === null || $next === $cur) break;
        $cur = $next;
        $occurrences++;
    }
}

usort($events, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['account'], $b['account']));

$runningBalances = $currentBalances;
foreach ($events as &$ev) {
    $aid = $ev['account_id'];
    $runningBalances[$aid] = ($runningBalances[$aid] ?? 0.0) + $ev['amount'];
    $ev['running_balance'] = $runningBalances[$aid];
}
unset($ev);

$projectedBalances = [];
foreach ($selectedAccounts as $acct) {
    $aid = (int)$acct['id'];
    $projectedBalances[$aid] = $runningBalances[$aid] ?? ($currentBalances[$aid] ?? 0.0);
}

$palette = [
    '#0d6efd','#20c997','#fd7e14','#6f42c1','#dc3545',
    '#17a2b8','#28a745','#e83e8c','#ffc107','#6c757d',
];

$chartLabels   = [];
$chartDatasets = [];

if (!empty($selectedAccounts)) {
    $dateSet = [$today];
    foreach ($events as $ev) { $dateSet[] = $ev['date']; }
    $dateSet = array_values(array_unique($dateSet));
    sort($dateSet);

    $chartLabels = array_map(fn($d) => date('M j', strtotime($d)), $dateSet);

    $acctRunning = [];
    foreach ($selectedAccounts as $acct) {
        $aid = (int)$acct['id'];
        $acctRunning[$aid] = $currentBalances[$aid] ?? 0.0;
    }

    $acctSeriesMap = [];
    foreach ($selectedAccounts as $acct) {
        $aid = (int)$acct['id'];
        $acctSeriesMap[$aid] = [];
    }

    $evIdx   = 0;
    $evCount = count($events);

    foreach ($dateSet as $dt) {
        while ($evIdx < $evCount && $events[$evIdx]['date'] <= $dt) {
            $aid = $events[$evIdx]['account_id'];
            if (array_key_exists($aid, $acctRunning)) {
                $acctRunning[$aid] += $events[$evIdx]['amount'];
            }
            $evIdx++;
        }
        foreach ($selectedAccounts as $acct) {
            $aid = (int)$acct['id'];
            $acctSeriesMap[$aid][] = round($acctRunning[$aid], 2);
        }
    }

    foreach ($selectedAccounts as $i => $acct) {
        $aid   = (int)$acct['id'];
        $color = $palette[$i % count($palette)];
        $chartDatasets[] = [
            'label'           => $acct['name'],
            'data'            => $acctSeriesMap[$aid],
            'borderColor'     => $color,
            'backgroundColor' => $color . '22',
            'borderWidth'     => 2,
            'tension'         => 0.3,
            'pointRadius'     => count($dateSet) <= 60 ? 2 : 0,
            'fill'            => count($selectedAccounts) === 1,
        ];
    }
}

$pageTitle   = 'Cash Flow Forecast';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<style>
.cff-date-sep td { font-size: .8rem; color: var(--bs-secondary); padding-top: .6rem !important; border-top: 1px solid var(--bs-border-color); font-weight: 500; }
</style>

<?php $reportFavTitle = 'Cash Flow Forecast'; $reportFavIcon = 'bi-graph-up-arrow'; ?>
<div class="page-header">
  <h2><i class="bi bi-graph-up-arrow"></i> Cash Flow Forecast</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<form method="get" class="report-filters">
  <div class="filter-group">
    <label>Forecast</label>
    <div class="quick-ranges">
      <?php foreach ([30=>'30 days',60=>'60 days',90=>'90 days',180=>'6 months',365=>'1 year'] as $d=>$lbl): ?>
      <a href="?days=<?= $d ?><?= $filteringAccts ? '&accts=' . urlencode($acctParam) : '' ?>"
         class="btn btn-sm btn-outline-secondary<?= $days===$d?' active':'' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="filter-group">
    <label>Accounts</label>
    <input type="hidden" name="accts" id="cffAcctHidden" value="<?= h($acctParam) ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown">
        <span id="cffAcctLabel"><?= h($acctBtnLabel) ?></span>
      </button>
      <ul class="dropdown-menu p-2" style="min-width:220px">
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" id="cffAcctAll" <?= !$filteringAccts ? 'checked' : '' ?>>
            <strong>All Accounts</strong>
          </label>
        </li>
        <li><hr class="dropdown-divider my-1"></li>
        <?php foreach ($allAccounts as $acct): ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center py-1">
            <input type="checkbox" class="cff-acct-chk" value="<?= (int)$acct['id'] ?>"
                   data-name="<?= h($acct['name']) ?>"
                   <?= in_array((int)$acct['id'], $selectedIds, true) ? 'checked' : '' ?>>
            <span>
              <?= h($acct['name']) ?>
              <small class="text-muted d-block" style="font-size:.75em"><?= h($acct['type']) ?></small>
            </span>
          </label>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="filter-group filter-group-btns">
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <input type="hidden" name="days" value="<?= $days ?>">
  </div>
</form>

<?php if (empty($selectedAccounts)): ?>
<p class="text-muted mt-3">No accounts selected.</p>
<?php else: ?>

<div class="report-tiles">
  <?php foreach ($selectedAccounts as $acct):
    $aid  = (int)$acct['id'];
    $cur  = $currentBalances[$aid] ?? 0.0;
    $proj = $projectedBalances[$aid] ?? $cur;
  ?>
  <div class="report-tile <?= $proj < 0 ? 'tile-negative' : '' ?>">
    <div class="tile-label"><?= h($acct['name']) ?></div>
    <div class="tile-value"><?= formatMoney($cur) ?></div>
    <div class="tile-sub">
      projected: <span class="<?= $proj < 0 ? 'text-danger fw-semibold' : '' ?>"><?= formatMoney($proj) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($chartLabels)): ?>
<div class="report-chart-wrap mb-4">
  <canvas id="cffChart" height="100"></canvas>
</div>
<?php endif; ?>

<?php if (empty($events)): ?>
<p class="text-muted mt-3">No scheduled bills or deposits found in the next <?= $days ?> days.</p>
<?php else: ?>

<div style="max-height:600px;overflow-y:auto">
<table class="table table-sm report-table">
  <thead style="position:sticky;top:0;z-index:2;background:var(--bs-body-bg)">
    <tr>
      <th>Date</th>
      <th>Description</th>
      <th>Type</th>
      <th class="text-end">Amount</th>
      <th>Account</th>
      <th class="text-end">Projected Balance</th>
    </tr>
  </thead>
  <tbody>
  <?php
  $lastDate = null;
  foreach ($events as $ev):
    $isNewDate = ($ev['date'] !== $lastDate);
    $lastDate  = $ev['date'];
  ?>
    <?php if ($isNewDate): ?>
    <tr class="cff-date-sep">
      <td colspan="6"><?= date('l, F j, Y', strtotime($ev['date'])) ?></td>
    </tr>
    <?php endif; ?>
    <tr>
      <td></td>
      <td><?= h($ev['desc']) ?></td>
      <td>
        <?php if ($ev['type'] === 'bill'): ?>
        <span class="badge bg-danger">Bill</span>
        <?php elseif ($ev['type'] === 'deposit'): ?>
        <span class="badge bg-success">Deposit</span>
        <?php elseif ($ev['type'] === 'transfer-out'): ?>
        <span class="badge bg-secondary">Transfer Out</span>
        <?php elseif ($ev['type'] === 'transfer-in'): ?>
        <span class="badge bg-info text-dark">Transfer In</span>
        <?php endif; ?>
      </td>
      <td class="text-end <?= $ev['amount'] >= 0 ? 'amount-credit' : 'amount-debit' ?>">
        <?= formatMoney($ev['amount'], true) ?>
      </td>
      <td class="text-muted small"><?= h($ev['account']) ?></td>
      <td class="text-end <?= $ev['running_balance'] < 0 ? 'amount-debit fw-semibold' : '' ?>">
        <?php if ($ev['running_balance'] < 0): ?>
        <i class="bi bi-exclamation-triangle-fill text-danger me-1" title="Negative balance"></i>
        <?php endif; ?>
        <?= formatMoney($ev['running_balance']) ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php endif; ?>

<?php if (!empty($chartLabels)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels   = <?= json_encode($chartLabels) ?>;
  const datasets = <?= json_encode($chartDatasets) ?>;

  new Chart(document.getElementById('cffChart'), {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        tooltip: {
          callbacks: {
            label: ctx => ctx.dataset.label + ': $' +
              ctx.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 }),
          }
        }
      },
      scales: {
        y: {
          ticks: { callback: v => '$' + v.toLocaleString() },
        },
      }
    }
  });
})();
</script>
<?php endif; ?>

<?php endif; ?>

<script>
(function(){
  const allChk  = document.getElementById('cffAcctAll');
  const chkList = Array.from(document.querySelectorAll('.cff-acct-chk'));
  const hidden  = document.getElementById('cffAcctHidden');
  const lbl     = document.getElementById('cffAcctLabel');

  function update() {
    const checked = chkList.filter(c => c.checked);
    const isAll   = checked.length === 0 || checked.length === chkList.length;
    hidden.value  = isAll ? '' : checked.map(c => c.value).join(',');
    lbl.textContent = isAll ? 'All Accounts'
      : checked.length === 1 ? checked[0].dataset.name
      : checked.length + ' Accounts';
    allChk.indeterminate = !isAll && checked.length > 0;
    if (isAll) allChk.checked = checked.length === chkList.length;
  }

  allChk.addEventListener('change', function() {
    chkList.forEach(c => c.checked = this.checked);
    this.indeterminate = false;
    update();
  });
  chkList.forEach(c => c.addEventListener('change', update));
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
