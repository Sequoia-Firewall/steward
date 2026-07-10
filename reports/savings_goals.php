<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');

$goalsStmt = $db->query(
    "SELECT sg.*, a.name AS account_name, a.opening_balance
     FROM savings_goals sg
     LEFT JOIN accounts a ON a.id = sg.account_id
     WHERE sg.is_active = 1
     ORDER BY sg.target_date IS NULL ASC, sg.target_date ASC, sg.name ASC"
);
$rawGoals = $goalsStmt->fetchAll();

$goals = [];
foreach ($rawGoals as $g) {
    $current = (float)$g['current_amount'];

    if ($g['account_id']) {
        $balStmt = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE account_id = ? AND transaction_date <= ?"
        );
        $balStmt->execute([(int)$g['account_id'], $today]);
        $txnSum  = (float)$balStmt->fetchColumn();
        $current = (float)$g['opening_balance'] + $txnSum;
    }

    $target    = (float)$g['target_amount'];
    $progress  = $target > 0 ? min(100, ($current / $target) * 100) : ($current > 0 ? 100 : 0);
    $remaining = max(0.0, $target - $current);

    $daysLeft      = null;
    $monthsLeft    = null;
    $monthlyNeeded = null;
    $onTrack       = true;
    $daysOverdue   = null;
    $expectedPct   = 0.0;

    if ($g['target_date']) {
        $targetDt = new DateTime($g['target_date']);
        $todayDt  = new DateTime($today);
        $diff     = (int)$todayDt->diff($targetDt)->format('%r%a');

        if ($diff < 0) {
            $daysOverdue = abs($diff);
            $onTrack     = $progress >= 100;
        } else {
            $daysLeft   = $diff;
            $monthsLeft = $daysLeft / 30.44;
            if ($monthsLeft > 0 && $remaining > 0) {
                $monthlyNeeded = $remaining / $monthsLeft;
            }

            $createdDt   = new DateTime($g['created_at']);
            $totalDays   = (int)$createdDt->diff($targetDt)->format('%a');
            $elapsed     = (int)$createdDt->diff($todayDt)->format('%a');
            $expectedPct = $totalDays > 0 ? ($elapsed / $totalDays) * 100 : 0;
            $onTrack     = $progress >= $expectedPct;
        }
    }

    $barClass = 'bg-success';
    if ($daysOverdue !== null && $progress < 100) {
        $barClass = 'bg-danger';
    } elseif (!$onTrack) {
        $barClass = ($progress < ($expectedPct ?? 0) - 15) ? 'bg-danger' : 'bg-warning';
    }

    $goals[] = array_merge($g, [
        'current'       => $current,
        'target'        => $target,
        'progress'      => $progress,
        'remaining'     => $remaining,
        'daysLeft'      => $daysLeft,
        'daysOverdue'   => $daysOverdue,
        'monthlyNeeded' => $monthlyNeeded,
        'onTrack'       => $onTrack,
        'barClass'      => $barClass,
    ]);
}

$totalGoals     = count($goals);
$totalTarget    = array_sum(array_column($goals, 'target'));
$totalSaved     = array_sum(array_column($goals, 'current'));
$totalRemaining = array_sum(array_column($goals, 'remaining'));

$chartLabels    = [];
$chartCurrent   = [];
$chartRemaining = [];
foreach ($goals as $g) {
    $chartLabels[]    = $g['name'];
    $chartCurrent[]   = round($g['current'], 2);
    $chartRemaining[] = round($g['remaining'], 2);
}

$pageTitle   = 'Savings Goals';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Savings Goals'; $reportFavIcon = 'bi-piggy-bank'; ?>
<div class="page-header">
  <h2><i class="bi bi-piggy-bank"></i> Savings Goals</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<?php if (empty($goals)): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-piggy-bank" style="font-size:3rem;opacity:.4"></i>
  <p class="mt-3 mb-1 fs-5">No active savings goals</p>
  <p class="small">Create a savings goal to track your progress here.</p>
</div>
<?php else: ?>

<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Total Goals</div>
    <div class="tile-value"><?= $totalGoals ?></div>
  </div>
  <div class="report-tile tile-neutral">
    <div class="tile-label">Total Target</div>
    <div class="tile-value"><?= formatMoney($totalTarget) ?></div>
  </div>
  <div class="report-tile tile-income">
    <div class="tile-label">Total Saved</div>
    <div class="tile-value"><?= formatMoney($totalSaved) ?></div>
  </div>
  <div class="report-tile tile-expense">
    <div class="tile-label">Total Remaining</div>
    <div class="tile-value"><?= formatMoney($totalRemaining) ?></div>
  </div>
</div>

<div class="report-chart-wrap mb-4">
  <canvas id="goalsChart" height="<?= max(60, $totalGoals * 28) ?>"></canvas>
</div>

<div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mt-1">
  <?php foreach ($goals as $g): ?>
  <div class="col">
    <div class="card h-100 shadow-sm">
      <div class="card-body">
        <h5 class="card-title mb-3"><?= h($g['name']) ?></h5>

        <div class="d-flex justify-content-between small text-muted mb-1">
          <span><?= round($g['progress'], 1) ?>% complete</span>
          <span><?= formatMoney($g['current']) ?> / <?= formatMoney($g['target']) ?></span>
        </div>
        <div class="progress mb-3" style="height:12px">
          <div class="progress-bar <?= $g['barClass'] ?>"
               role="progressbar"
               style="width:<?= round($g['progress'], 2) ?>%"
               aria-valuenow="<?= round($g['progress'], 2) ?>"
               aria-valuemin="0" aria-valuemax="100"></div>
        </div>

        <dl class="row row-cols-2 g-1 small mb-0">
          <dt class="col text-muted">Remaining</dt>
          <dd class="col text-end mb-0">
            <?php if ($g['remaining'] > 0): ?>
              <?= formatMoney($g['remaining']) ?>
            <?php else: ?>
              <span class="text-success fw-semibold">Reached!</span>
            <?php endif; ?>
          </dd>

          <dt class="col text-muted">Target date</dt>
          <dd class="col text-end mb-0">
            <?php if ($g['target_date']): ?>
              <?= formatDate($g['target_date']) ?>
            <?php else: ?>
              <span class="text-muted">No deadline</span>
            <?php endif; ?>
          </dd>

          <dt class="col text-muted">Timeline</dt>
          <dd class="col text-end mb-0">
            <?php if ($g['daysOverdue'] !== null): ?>
              <span class="text-danger"><?= $g['daysOverdue'] ?> day<?= $g['daysOverdue'] !== 1 ? 's' : '' ?> overdue</span>
            <?php elseif ($g['daysLeft'] !== null): ?>
              <?= $g['daysLeft'] ?> day<?= $g['daysLeft'] !== 1 ? 's' : '' ?> left
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </dd>

          <?php if ($g['monthlyNeeded'] !== null): ?>
          <dt class="col text-muted">Needed/month</dt>
          <dd class="col text-end mb-0"><?= formatMoney($g['monthlyNeeded']) ?></dd>
          <?php endif; ?>

          <?php if ($g['account_name']): ?>
          <dt class="col text-muted">Account</dt>
          <dd class="col text-end mb-0"><?= h($g['account_name']) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labels    = <?= json_encode($chartLabels) ?>;
  const current   = <?= json_encode($chartCurrent) ?>;
  const remaining = <?= json_encode($chartRemaining) ?>;

  new Chart(document.getElementById('goalsChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Saved',     data: current,   backgroundColor: 'rgba(40,167,69,0.75)',  borderColor: 'rgba(40,167,69,1)',  borderWidth: 1 },
        { label: 'Remaining', data: remaining,  backgroundColor: 'rgba(220,53,69,0.2)',   borderColor: 'rgba(220,53,69,0.5)', borderWidth: 1 }
      ]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        tooltip: { callbacks: { label: ctx => ' ' + ctx.dataset.label + ': $' + ctx.parsed.x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
      },
      scales: {
        x: { stacked: true, ticks: { callback: v => '$' + v.toLocaleString() } },
        y: { stacked: true }
      }
    }
  });
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
