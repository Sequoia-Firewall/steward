<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');

$accts = $db->query(
    "SELECT id, name, type, opening_balance, last_reconciled_date, last_reconciled_balance
     FROM accounts
     WHERE is_active = 1 AND is_closed = 0
       AND type NOT IN ('Investment','Asset','Crypto','Retirement')
     ORDER BY name"
)->fetchAll();

$accounts = [];
foreach ($accts as $a) {
    $id = (int)$a['id'];

    $balRow = $db->prepare(
        "SELECT
           SUM(amount) AS balance_all,
           SUM(CASE WHEN cleared_status IN ('cleared','reconciled') THEN amount ELSE 0 END) AS balance_cleared,
           COUNT(CASE WHEN cleared_status NOT IN ('cleared','reconciled') AND amount != 0 THEN 1 END) AS uncleared_count,
           SUM(CASE WHEN cleared_status NOT IN ('cleared','reconciled') AND amount != 0 THEN amount ELSE 0 END) AS uncleared_total
         FROM transactions
         WHERE account_id = ?"
    );
    $balRow->execute([$id]);
    $bal = $balRow->fetch();

    $currentBalance  = (float)$a['opening_balance'] + (float)($bal['balance_all'] ?? 0);
    $clearedBalance  = (float)$a['opening_balance'] + (float)($bal['balance_cleared'] ?? 0);
    $unclearedCount  = (int)($bal['uncleared_count'] ?? 0);
    $unclearedTotal  = (float)($bal['uncleared_total'] ?? 0);

    $lastRecDate   = $a['last_reconciled_date'];
    $lastRecBal    = $lastRecDate ? (float)$a['last_reconciled_balance'] : null;
    $daysSince     = null;
    $diffFromRec   = null;

    if ($lastRecDate) {
        $daysSince   = (int)(new DateTime($today))->diff(new DateTime($lastRecDate))->days;
        $diffFromRec = $currentBalance - $lastRecBal;
    }

    if (!$lastRecDate) {
        $status = 'never';
    } elseif ($daysSince <= 35) {
        $status = 'current';
    } elseif ($daysSince <= 90) {
        $status = 'warn';
    } else {
        $status = 'overdue';
    }

    $accounts[] = [
        'id'              => $id,
        'name'            => $a['name'],
        'type'            => $a['type'],
        'currentBalance'  => $currentBalance,
        'clearedBalance'  => $clearedBalance,
        'unclearedCount'  => $unclearedCount,
        'unclearedTotal'  => $unclearedTotal,
        'lastRecDate'     => $lastRecDate,
        'lastRecBal'      => $lastRecBal,
        'daysSince'       => $daysSince,
        'diffFromRec'     => $diffFromRec,
        'status'          => $status,
    ];
}

usort($accounts, function($a, $b) {
    $order = ['never' => 0, 'overdue' => 1, 'warn' => 2, 'current' => 3];
    $cmp = ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
    if ($cmp !== 0) return $cmp;
    if ($a['status'] === 'never' || $a['status'] === 'overdue') {
        return ($b['daysSince'] ?? PHP_INT_MAX) <=> ($a['daysSince'] ?? PHP_INT_MAX);
    }
    return ($b['daysSince'] ?? 0) <=> ($a['daysSince'] ?? 0);
});

$totalAccounts    = count($accounts);
$neverCount       = count(array_filter($accounts, fn($a) => $a['status'] === 'never'));
$overdueCount     = count(array_filter($accounts, fn($a) => $a['status'] === 'overdue'));
$totalUncleared   = array_sum(array_column($accounts, 'unclearedCount'));

$pageTitle   = 'Reconciliation Status';
$currentPage = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<?php $reportFavTitle = 'Reconciliation Status'; $reportFavIcon = 'bi-check-circle'; ?>
<div class="page-header">
  <h2><i class="bi bi-check-circle"></i> Reconciliation Status</h2>
  <?php include __DIR__ . '/../includes/report_fav_btn.php'; ?>
  <?php include __DIR__ . '/../includes/report_print_btn.php'; ?>
  <a href="<?= BASE_PATH ?>/reports/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Reports
  </a>
</div>

<div class="report-tiles">
  <div class="report-tile tile-neutral">
    <div class="tile-label">Total Accounts</div>
    <div class="tile-value"><?= $totalAccounts ?></div>
  </div>
  <div class="report-tile <?= $neverCount > 0 ? 'tile-negative' : 'tile-neutral' ?>">
    <div class="tile-label">Never Reconciled</div>
    <div class="tile-value"><?= $neverCount ?></div>
  </div>
  <div class="report-tile <?= $overdueCount > 0 ? 'tile-negative' : 'tile-neutral' ?>">
    <div class="tile-label">Overdue (&gt;90 days)</div>
    <div class="tile-value"><?= $overdueCount ?></div>
  </div>
  <div class="report-tile <?= $totalUncleared > 0 ? 'tile-warn' : 'tile-neutral' ?>">
    <div class="tile-label">Uncleared Items</div>
    <div class="tile-value"><?= $totalUncleared ?></div>
  </div>
</div>

<?php if (empty($accounts)): ?>
  <p class="text-muted mt-3">No accounts found.</p>
<?php else: ?>

<div class="table-responsive">
<table class="table table-sm report-table">
  <thead>
    <tr>
      <th>Account</th>
      <th>Type</th>
      <th class="text-end">Current Balance</th>
      <th class="text-end">Cleared Balance</th>
      <th class="text-end">Uncleared</th>
      <th>Last Reconciled</th>
      <th class="text-end">Days Since</th>
      <th>Status</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($accounts as $a): ?>
    <?php
      $rowClass = match($a['status']) {
          'overdue', 'never' => 'rec-row-overdue',
          'warn'             => 'rec-row-warn',
          default            => '',
      };
    ?>
    <tr class="<?= $rowClass ?>">
      <td class="fw-medium">
        <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $a['id'] ?>" class="text-decoration-none">
          <?= h($a['name']) ?>
        </a>
      </td>
      <td class="text-muted"><?= h($a['type']) ?></td>
      <td class="text-end <?= $a['currentBalance'] >= 0 ? 'amount-credit' : 'amount-debit' ?>">
        <?= formatMoney($a['currentBalance']) ?>
      </td>
      <td class="text-end <?= $a['clearedBalance'] >= 0 ? 'amount-credit' : 'amount-debit' ?>">
        <?= formatMoney($a['clearedBalance']) ?>
      </td>
      <td class="text-end">
        <?php if ($a['unclearedCount'] > 0): ?>
          <span class="text-warning fw-medium"><?= $a['unclearedCount'] ?></span>
          <span class="text-muted small">(<?= formatMoney($a['unclearedTotal'], true) ?>)</span>
        <?php else: ?>
          <span class="text-muted">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($a['lastRecDate']): ?>
          <?= formatDate($a['lastRecDate']) ?>
          <?php if ($a['diffFromRec'] !== null && abs($a['diffFromRec']) > 0.005): ?>
          <span class="text-muted small ms-1">(<?= formatMoney($a['diffFromRec'], true) ?> drift)</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-muted">Never</span>
        <?php endif; ?>
      </td>
      <td class="text-end">
        <?= $a['daysSince'] !== null ? $a['daysSince'] : '<span class="text-muted">—</span>' ?>
      </td>
      <td>
        <?php match($a['status']) {
          'current' => print('<span class="badge bg-success">Current</span>'),
          'warn'    => print('<span class="badge bg-warning text-dark">Due Soon</span>'),
          'overdue' => print('<span class="badge bg-danger">Overdue</span>'),
          'never'   => print('<span class="badge bg-secondary">Never</span>'),
        }; ?>
      </td>
      <td>
        <a href="<?= BASE_PATH ?>/accounts/reconcile?id=<?= $a['id'] ?>"
           class="btn btn-sm btn-outline-secondary py-0 px-2">Reconcile</a>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<style>
.rec-row-overdue td { background: rgba(220,53,69,.07); }
.rec-row-warn    td { background: rgba(255,193,7,.12); }
</style>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
