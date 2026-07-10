<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

// ── Helper: advance a date by frequency ──────────────────────────
function forecastAdvanceDate(string $date, string $frequency): ?string {
    $ts = strtotime($date);
    return match ($frequency) {
        'weekly'    => date('Y-m-d', strtotime('+1 week',   $ts)),
        'biweekly'  => date('Y-m-d', strtotime('+2 weeks',  $ts)),
        'monthly'   => date('Y-m-d', strtotime('+1 month',  $ts)),
        'quarterly' => date('Y-m-d', strtotime('+3 months', $ts)),
        'yearly'    => date('Y-m-d', strtotime('+1 year',   $ts)),
        default     => null,
    };
}

// ── Parameters ──────────────────────────────────────────────────
$db           = getDB();
$horizon      = in_array((int)($_GET['days'] ?? 30), [30, 60, 90]) ? (int)$_GET['days'] : 30;

// Checking / Savings / Credit Card accounts only
$bankAccounts = $db->query(
    "SELECT id, name, type,
            opening_balance + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id), 0) AS current_balance
     FROM accounts a
     WHERE type IN ('Checking','Savings','Credit Card') AND is_active = 1 AND is_investment_cash = 0
     ORDER BY type, name"
)->fetchAll();

$accountId = (int)($_GET['account_id'] ?? ($bankAccounts[0]['id'] ?? 0));
$account   = null;
foreach ($bankAccounts as $a) {
    if ($a['id'] === $accountId) { $account = $a; break; }
}

// ── Build forecast if an account is selected ────────────────────
$today    = date('Y-m-d');
$toDate   = date('Y-m-d', strtotime("+$horizon days"));
$events   = [];
$chartLabels   = [];
$chartBalances = [];
$chartEvents   = [];   // per-day event markers for tooltip

if ($account) {
    $startBalance = (float)$account['current_balance'];

    // Get all scheduled bills/deposits for this account
    $bills = $db->prepare(
        'SELECT * FROM scheduled_bills WHERE account_id = ? AND is_active = 1 ORDER BY next_due_date'
    );
    $bills->execute([$accountId]);
    $bills = $bills->fetchAll();

    // Expand each bill over the horizon
    foreach ($bills as $bill) {
        $date = $bill['next_due_date'];
        while ($date <= $toDate) {
            if ($date >= $today) {
                $signed = ($bill['type'] === 'bill' || $bill['type'] === 'transfer')
                    ? -(float)$bill['amount']
                    :  (float)$bill['amount'];
                $events[] = [
                    'date'   => $date,
                    'name'   => $bill['name'],
                    'type'   => $bill['type'],
                    'amount' => $signed,
                ];
            }
            if ($bill['frequency'] === 'once') break;
            $next = forecastAdvanceDate($date, $bill['frequency']);
            if (!$next || $next === $date) break;
            $date = $next;
        }
    }

    // Sort events by date
    usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

    // Build day-by-day balance array for chart (every day in range)
    // Index events by date for quick lookup
    $eventsByDate = [];
    foreach ($events as $ev) {
        $eventsByDate[$ev['date']][] = $ev;
    }

    $balance   = $startBalance;
    $d         = new DateTime($today);
    $end       = new DateTime($toDate);
    $end->modify('+1 day');

    while ($d < $end) {
        $dateStr = $d->format('Y-m-d');
        $dayEvs  = $eventsByDate[$dateStr] ?? [];
        foreach ($dayEvs as $ev) {
            $balance += $ev['amount'];
        }
        $chartLabels[]   = $dateStr;
        $chartBalances[] = round($balance, 2);
        $chartEvents[]   = $dayEvs;
        $d->modify('+1 day');
    }

    // Attach running balance to events list
    $running = $startBalance;
    foreach ($events as &$ev) {
        $running       += $ev['amount'];
        $ev['balance']  = round($running, MONEY_DECIMALS);
    }
    unset($ev);
}

// ── Summary stats ────────────────────────────────────────────────
$totalBills    = array_sum(array_map(fn($e) => $e['type'] === 'bill'    ? abs($e['amount']) : 0, $events));
$totalDeposits = array_sum(array_map(fn($e) => $e['type'] === 'deposit' ? $e['amount']      : 0, $events));
$minBalance    = $chartBalances ? min($chartBalances) : ($account ? (float)$account['current_balance'] : 0);
$eventCount    = count($events);

$pageTitle   = 'Projected Balance';
$currentPage = 'forecast';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-graph-up"></i> Projected Balance</h2>
</div>

<!-- ── Controls ─────────────────────────────────────────────────── -->
<form method="get" class="d-flex flex-wrap gap-2 align-items-center mb-4">
  <select name="account_id" class="form-select form-select-sm" style="max-width:220px"
          onchange="this.form.submit()">
    <?php foreach ($bankAccounts as $a): ?>
    <option value="<?= $a['id'] ?>" <?= $a['id'] === $accountId ? 'selected' : '' ?>>
      <?= h($a['name']) ?> (<?= h($a['type']) ?>)
    </option>
    <?php endforeach; ?>
  </select>
  <div class="btn-group btn-group-sm" role="group">
    <?php foreach ([30, 60, 90] as $d): ?>
    <a href="?account_id=<?= $accountId ?>&days=<?= $d ?>"
       class="btn btn-outline-secondary <?= $horizon === $d ? 'active' : '' ?>">
      <?= $d ?> days
    </a>
    <?php endforeach; ?>
  </div>
  <?php if ($account): ?>
  <span class="text-muted small">
    Current balance: <strong><?= formatMoney((float)$account['current_balance']) ?></strong>
    &nbsp;·&nbsp; through <?= formatDate($toDate) ?>
  </span>
  <?php endif; ?>
</form>

<?php if (!$account): ?>
<div class="alert alert-info">No Checking, Savings, or Credit Card accounts found.</div>
<?php else: ?>

<!-- ── KPI tiles ─────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="report-kpi">
      <div class="kpi-label">Current Balance</div>
      <div class="kpi-value <?= round((float)$account['current_balance'], MONEY_DECIMALS) < 0 ? 'text-danger' : '' ?>">
        <?= formatMoney((float)$account['current_balance']) ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="report-kpi">
      <div class="kpi-label">Projected Bills</div>
      <div class="kpi-value text-danger"><?= formatMoney($totalBills) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="report-kpi">
      <div class="kpi-label">Projected Deposits</div>
      <div class="kpi-value text-success"><?= formatMoney($totalDeposits) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="report-kpi">
      <div class="kpi-label">Lowest Projected Balance</div>
      <div class="kpi-value <?= $minBalance < 0 ? 'text-danger' : '' ?>">
        <?= formatMoney($minBalance) ?>
        <?php if ($minBalance < 0): ?>
          <small class="d-block text-danger fs-6"><i class="bi bi-exclamation-triangle-fill"></i> Overdraft risk</small>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Chart ─────────────────────────────────────────────────────── -->
<div class="card mb-4">
  <div class="card-body">
    <canvas id="forecastChart" height="90"></canvas>
  </div>
</div>

<!-- ── Events table ──────────────────────────────────────────────── -->
<?php if (empty($events)): ?>
<div class="alert alert-info">
  No scheduled bills or deposits for this account in the next <?= $horizon ?> days.
  <a href="<?= BASE_PATH ?>/bills/index">Add scheduled items</a> to see a forecast.
</div>
<?php else: ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-list-ul me-1"></i>Upcoming Events (<?= $eventCount ?>)</strong>
  </div>
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0 align-middle">
    <thead class="table-light">
      <tr>
        <th>Date</th>
        <th>Name</th>
        <th>Type</th>
        <th class="text-end">Amount</th>
        <th class="text-end">Balance After</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($events as $ev): ?>
    <tr class="<?= $ev['balance'] < 0 ? 'table-danger' : '' ?>">
      <td class="small"><?= formatDate($ev['date']) ?></td>
      <td><?= h($ev['name']) ?></td>
      <td>
        <?php if ($ev['type'] === 'bill'): ?>
          <span class="badge bill-type-bill"><i class="bi bi-arrow-up-circle"></i> Bill</span>
        <?php else: ?>
          <span class="badge bill-type-deposit"><i class="bi bi-arrow-down-circle"></i> Deposit</span>
        <?php endif; ?>
      </td>
      <td class="text-end">
        <span class="<?= $ev['amount'] < 0 ? 'amount-debit' : 'amount-credit' ?>">
          <?= ($ev['amount'] >= 0 ? '+' : '') . formatMoney(abs($ev['amount'])) ?>
        </span>
      </td>
      <td class="text-end fw-semibold <?= $ev['balance'] < 0 ? 'text-danger' : '' ?>">
        <?= formatMoney($ev['balance']) ?>
        <?php if ($ev['balance'] < 0): ?>
          <i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="Overdraft risk"></i>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
  const labels   = <?= json_encode($chartLabels) ?>;
  const balances = <?= json_encode($chartBalances) ?>;
  const eventsPerDay = <?= json_encode(array_map(fn($dayEvs) =>
      array_map(fn($e) => ['name' => $e['name'], 'amount' => $e['amount']], $dayEvs),
      $chartEvents
  )) ?>;

  // Format labels as short date (mm/dd)
  const shortLabels = labels.map(d => {
    const [y,m,day] = d.split('-');
    return m + '/' + day;
  });

  // Only show every Nth label to avoid crowding
  const step  = Math.max(1, Math.ceil(labels.length / 20));
  const displayLabels = shortLabels.map((l, i) => i % step === 0 ? l : '');

  // Determine if any balance goes negative for red fill zone
  const hasNegative = balances.some(b => b < 0);
  const zeroLine    = new Array(balances.length).fill(0);

  const ctx = document.getElementById('forecastChart').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: displayLabels,
      datasets: [
        {
          label: 'Projected Balance',
          data: balances,
          borderColor: '#0d6efd',
          backgroundColor: 'rgba(13,110,253,0.08)',
          fill: true,
          stepped: 'before',
          tension: 0,
          pointRadius: balances.map((_, i) => eventsPerDay[i]?.length ? 5 : 0),
          pointBackgroundColor: balances.map((b, i) => {
            if (!eventsPerDay[i]?.length) return 'transparent';
            return b < 0 ? '#dc3545' : (eventsPerDay[i].some(e => e.amount > 0) ? '#198754' : '#dc3545');
          }),
          borderWidth: 2,
        }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            title: (items) => labels[items[0].dataIndex] || '',
            afterBody: (items) => {
              const idx = items[0].dataIndex;
              const evs = eventsPerDay[idx] || [];
              if (!evs.length) return '';
              return evs.map(e => (e.amount >= 0 ? '+' : '') +
                new Intl.NumberFormat('en-US', {style:'currency',currency:'USD'}).format(e.amount)
                + '  ' + e.name
              ).join('\n');
            }
          }
        }
      },
      scales: {
        x: { grid: { display: false } },
        y: {
          ticks: {
            callback: v => '$' + new Intl.NumberFormat('en-US').format(v)
          },
          grid: {
            color: ctx2 => ctx2.tick.value === 0 ? 'rgba(220,53,69,0.5)' : 'rgba(0,0,0,0.05)'
          }
        }
      }
    }
  });
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
