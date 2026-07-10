<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');

$loans = $db->query(
    "SELECT ld.*, a.name AS account_name
     FROM loan_details ld
     JOIN accounts a ON a.id = ld.account_id
     WHERE a.is_active = 1
     ORDER BY a.name"
)->fetchAll();

$selectedLoan = null;
$schedule     = [];

if (!empty($loans)) {
    $acctParam = isset($_GET['acct']) ? (int)$_GET['acct'] : 0;
    foreach ($loans as $loan) {
        if ((int)$loan['account_id'] === $acctParam) {
            $selectedLoan = $loan;
            break;
        }
    }
    if (!$selectedLoan) {
        $selectedLoan = $loans[0];
    }

    $balance     = (float)$selectedLoan['original_amount'];
    $monthlyRate = (float)$selectedLoan['annual_rate'] / 100 / 12;
    $payment     = (float)$selectedLoan['payment_amount'];

    $dt  = new DateTime($selectedLoan['start_date']);
    $cap = 600;

    $payNum      = 0;
    $cumInterest = 0.0;

    while ($balance > 0.005 && $payNum < $cap) {
        $payNum++;
        $interest  = $monthlyRate > 0 ? round($balance * $monthlyRate, 2) : 0.0;
        $principal = min(round($payment - $interest, 2), $balance);
        if ($principal < 0) $principal = 0.0;
        $balance  -= $principal;
        if ($balance < 0.005) $balance = 0.0;
        $cumInterest += $interest;

        $schedule[] = [
            'num'          => $payNum,
            'date'         => $dt->format('Y-m-d'),
            'payment'      => round($interest + $principal, 2),
            'interest'     => $interest,
            'principal'    => $principal,
            'balance'      => $balance,
            'cum_interest' => $cumInterest,
        ];
        $dt->modify('+1 month');
    }

    $totalInterest = $cumInterest;
    $payoffDate    = !empty($schedule) ? end($schedule)['date'] : null;

    $monthsRemaining = 0;
    foreach ($schedule as $row) {
        if ($row['date'] >= $today) $monthsRemaining++;
    }

    $paidStmt = $db->prepare(
        "SELECT transaction_date, amount FROM transactions
         WHERE account_id = ? AND transaction_date BETWEEN ? AND ?
         ORDER BY transaction_date"
    );
    $paidStmt->execute([
        $selectedLoan['account_id'],
        $schedule[0]['date'] ?? $today,
        $payoffDate ?? $today,
    ]);
    $actualTxns = $paidStmt->fetchAll();

    $paidEntries = [];
    foreach ($actualTxns as $txn) {
        $paidEntries[] = [
            'date'   => $txn['transaction_date'],
            'amount' => abs((float)$txn['amount']),
        ];
    }

    foreach ($schedule as &$row) {
        $row['paid']  = false;
        $payDateTs    = strtotime($row['date']);
        foreach ($paidEntries as $pt) {
            $dayDiff = abs(strtotime($pt['date']) - $payDateTs);
            $amtDiff = abs($pt['amount'] - $row['payment']);
            if ($dayDiff <= 5 * 86400 && $amtDiff <= $row['payment'] * 0.10) {
                $row['paid'] = true;
                break;
            }
        }
    }
    unset($row);

    $sampleYearly = count($schedule) > 36;
    $chartRows    = [];
    if ($sampleYearly) {
        $lastYear = null;
        foreach ($schedule as $row) {
            $yr = substr($row['date'], 0, 4);
            if ($yr !== $lastYear) { $chartRows[] = $row; $lastYear = $yr; }
        }
        $last = end($schedule);
        if ($last['date'] !== end($chartRows)['date']) $chartRows[] = $last;
    } else {
        $chartRows = $schedule;
    }

    $chartLabels   = array_map(fn($r) => date('M Y', strtotime($r['date'])), $chartRows);
    $chartBalance  = array_map(fn($r) => round($r['balance'], 2), $chartRows);
    $chartInterest = array_map(fn($r) => round($r['cum_interest'], 2), $chartRows);
}

$pageTitle   = 'Loan Amortization';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Loan Amortization'; $reportFavIcon = 'bi-calculator'; ?>
<div class="page-header">
  <h2><i class="bi bi-calculator"></i> Loan Amortization</h2>
  <?php if (!empty($loans)): ?>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <?php endif; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<?php if (empty($loans)): ?>
<div class="mt-4 text-center text-muted">
  <i class="bi bi-calculator fs-1 d-block mb-2 opacity-50"></i>
  No loan accounts configured. Add loan details in account settings.
</div>
<?php include __DIR__ . '/../includes/footer.php'; exit; ?>
<?php endif; ?>

<form method="get" class="report-filters">
  <?php if (count($loans) > 1): ?>
  <div class="filter-group">
    <label>Loan Account</label>
    <select name="acct" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php foreach ($loans as $loan): ?>
      <option value="<?= (int)$loan['account_id'] ?>"
              <?= (int)$loan['account_id'] === (int)$selectedLoan['account_id'] ? 'selected' : '' ?>>
        <?= h($loan['account_name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php else: ?>
  <input type="hidden" name="acct" value="<?= (int)$selectedLoan['account_id'] ?>">
  <?php endif; ?>
  <div class="filter-group filter-group-btns">
    <?php if (count($loans) > 1): ?>
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    <?php endif; ?>
  </div>
</form>

<div class="report-tiles">
  <div class="report-tile">
    <div class="tile-label">Original Amount</div>
    <div class="tile-value"><?= formatMoney((float)$selectedLoan['original_amount']) ?></div>
    <div class="tile-sub"><?= h((int)$selectedLoan['term_months']) ?> month term</div>
  </div>
  <div class="report-tile">
    <div class="tile-label">Monthly Payment</div>
    <div class="tile-value"><?= formatMoney((float)$selectedLoan['payment_amount']) ?></div>
    <div class="tile-sub"><?= number_format((float)$selectedLoan['annual_rate'], 2) ?>% APR</div>
  </div>
  <div class="report-tile tile-negative">
    <div class="tile-label">Total Interest</div>
    <div class="tile-value"><?= formatMoney($totalInterest) ?></div>
  </div>
  <?php if ($payoffDate): ?>
  <div class="report-tile">
    <div class="tile-label">Payoff Date</div>
    <div class="tile-value" style="font-size:1.1rem"><?= formatDate($payoffDate) ?></div>
    <div class="tile-sub"><?= $monthsRemaining ?> payments remaining</div>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($chartRows)): ?>
<div class="report-chart-wrap mb-4">
  <canvas id="amorChart" height="90"></canvas>
</div>
<?php endif; ?>

<?php if (!empty($schedule)): ?>
<div style="max-height:520px;overflow-y:auto">
<table class="table table-sm report-table">
  <thead style="position:sticky;top:0;z-index:2;background:var(--bs-body-bg)">
    <tr>
      <th>#</th>
      <th>Date</th>
      <th class="text-end">Payment</th>
      <th class="text-end">Principal</th>
      <th class="text-end">Interest</th>
      <th class="text-end">Balance</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($schedule as $row): ?>
    <tr<?= $row['balance'] == 0 ? ' class="table-success"' : '' ?>>
      <td class="text-muted"><?= $row['num'] ?></td>
      <td><?= formatDate($row['date']) ?></td>
      <td class="text-end"><?= formatMoney($row['payment']) ?></td>
      <td class="text-end amount-credit"><?= formatMoney($row['principal']) ?></td>
      <td class="text-end amount-debit"><?= formatMoney($row['interest']) ?></td>
      <td class="text-end"><?= $row['balance'] > 0 ? formatMoney($row['balance']) : '—' ?></td>
      <td><?php if ($row['paid']): ?><span class="badge bg-success">Paid</span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php if (!empty($chartRows)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels   = <?= json_encode($chartLabels) ?>;
  const balance  = <?= json_encode($chartBalance) ?>;
  const interest = <?= json_encode($chartInterest) ?>;

  new Chart(document.getElementById('amorChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Remaining Balance',
          data: balance,
          borderColor: '#0d6efd',
          backgroundColor: 'rgba(13,110,253,0.08)',
          borderWidth: 2,
          tension: 0.3,
          pointRadius: labels.length <= 36 ? 3 : 1,
          fill: true,
          yAxisID: 'yLeft',
        },
        {
          label: 'Cumulative Interest',
          data: interest,
          borderColor: '#dc3545',
          backgroundColor: 'transparent',
          borderWidth: 2,
          tension: 0.3,
          pointRadius: labels.length <= 36 ? 3 : 1,
          fill: false,
          yAxisID: 'yRight',
        },
      ]
    },
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
        yLeft: {
          type: 'linear',
          position: 'left',
          min: 0,
          ticks: { callback: v => '$' + v.toLocaleString() },
        },
        yRight: {
          type: 'linear',
          position: 'right',
          min: 0,
          grid: { drawOnChartArea: false },
          ticks: { callback: v => '$' + v.toLocaleString() },
        },
      }
    }
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
