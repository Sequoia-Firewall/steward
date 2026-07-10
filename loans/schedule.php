<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/loan_utils.php';
requireLogin();

$id      = (int)($_GET['id'] ?? 0);
$account = getAccount($id);
if (!$account || $account['type'] !== 'Loan') {
    setFlash('error', 'Loan account not found.');
    header('Location: ' . BASE_PATH . '/loans/index');
    exit;
}

$loan = getLoanDetails($id);
if (!$loan) {
    setFlash('error', 'Loan details not found for this account.');
    header('Location: ' . BASE_PATH . '/loans/index');
    exit;
}

$firstPayDate = getLoanFirstPaymentDate($loan);
$schedule     = buildAmortizationSchedule($loan, $firstPayDate);

$db   = getDB();
$stmt = $db->prepare(
    'SELECT * FROM transactions WHERE account_id = ? ORDER BY transaction_date ASC, id ASC'
);
$stmt->execute([$id]);
$actualPayments = $stmt->fetchAll();
$paymentsMade   = count($actualPayments);

$currentBalance = getAccountBalance($id);
$outstanding    = max(0, -$currentBalance);

$today       = date('Y-m-d');
$pageTitle   = h($account['name']) . ' — Amortization Schedule';
$currentPage = 'loans';
$currentAccountId = $id;
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-table"></i> <?= h($account['name']) ?> — Amortization Schedule</h2>
  <div class="d-flex gap-2">
    <?php if (canEdit()): ?>
    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#payModal">
      <i class="bi bi-cash"></i> Record Payment
    </button>
    <?php endif; ?>
    <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $id ?>"
       class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-journal-text"></i> Register
    </a>
    <a href="<?= BASE_PATH ?>/loans/index" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> All Loans
    </a>
  </div>
</div>

<?= renderFlash() ?>

<!-- Summary tiles -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-2">
    <div class="report-kpi">
      <div class="kpi-label">Original Amount</div>
      <div class="kpi-value"><?= formatMoney((float)$loan['original_amount']) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="report-kpi">
      <div class="kpi-label">Outstanding</div>
      <div class="kpi-value <?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>">
        <?= formatMoney($outstanding) ?>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="report-kpi">
      <div class="kpi-label">Annual Rate</div>
      <div class="kpi-value"><?= number_format((float)$loan['annual_rate'], 3) ?>%</div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="report-kpi">
      <div class="kpi-label">Monthly Payment</div>
      <div class="kpi-value"><?= formatMoney((float)$loan['payment_amount']) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="report-kpi">
      <div class="kpi-label">Payments Made</div>
      <div class="kpi-value"><?= $paymentsMade ?> / <?= (int)$loan['term_months'] ?></div>
    </div>
  </div>
  <div class="col-6 col-md-2">
    <div class="report-kpi">
      <div class="kpi-label">Total Interest</div>
      <div class="kpi-value text-muted">
        <?= formatMoney(array_sum(array_column($schedule, 'interest'))) ?>
      </div>
    </div>
  </div>
</div>

<div class="table-responsive">
<table class="table table-sm table-hover align-middle">
  <thead class="table-light">
    <tr>
      <th class="text-muted">#</th>
      <th>Due Date</th>
      <th class="text-end">Payment</th>
      <th class="text-end">Principal</th>
      <th class="text-end">Interest</th>
      <th class="text-end">Balance</th>
      <th>Status</th>
      <th>Actual Date</th>
      <th class="text-end">Actual Amt</th>
    </tr>
  </thead>
  <tbody>
  <?php
  $totalPaidPrincipal = 0;
  $totalPaidInterest  = 0;
  foreach ($schedule as $i => $row):
    $actual    = $actualPayments[$i] ?? null;
    $isPaid    = $actual !== null;
    $isOverdue = !$isPaid && $row['date'] < $today;
    $isDueToday = !$isPaid && $row['date'] === $today;
    $rowClass  = $isPaid ? '' : ($isOverdue ? 'table-danger' : '');

    if ($isPaid) {
        $paidPrincipal       = (float)$actual['amount'];
        $totalPaidPrincipal += $paidPrincipal;
        $totalPaidInterest  += $row['interest'];
        $actualTotal         = $paidPrincipal + $row['interest'];
    }
  ?>
  <tr class="<?= $rowClass ?>">
    <td class="text-muted small"><?= $row['num'] ?></td>
    <td class="small"><?= formatDate($row['date']) ?></td>
    <td class="text-end"><?= formatMoney($row['payment']) ?></td>
    <td class="text-end"><?= formatMoney($row['principal']) ?></td>
    <td class="text-end text-muted"><?= formatMoney($row['interest']) ?></td>
    <td class="text-end"><?= formatMoney($row['balance']) ?></td>
    <td>
      <?php if ($isPaid): ?>
        <span class="badge bg-success">Paid</span>
      <?php elseif ($isOverdue): ?>
        <span class="badge bg-danger">Overdue</span>
      <?php elseif ($isDueToday): ?>
        <span class="badge bg-warning text-dark">Due Today</span>
      <?php else: ?>
        <span class="badge bg-secondary">Upcoming</span>
      <?php endif; ?>
    </td>
    <td class="text-muted small"><?= $isPaid ? formatDate($actual['transaction_date']) : '—' ?></td>
    <td class="text-end"><?= $isPaid ? formatMoney($actualTotal) : '<span class="text-muted">—</span>' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot class="table-light fw-semibold">
    <tr>
      <td colspan="2">Total</td>
      <td class="text-end"><?= formatMoney(array_sum(array_column($schedule, 'payment'))) ?></td>
      <td class="text-end"><?= formatMoney(array_sum(array_column($schedule, 'principal'))) ?></td>
      <td class="text-end text-muted"><?= formatMoney(array_sum(array_column($schedule, 'interest'))) ?></td>
      <td colspan="4"></td>
    </tr>
  </tfoot>
</table>
</div>

<?php if (canEdit()): ?>
<?php $nextSched = $schedule[$paymentsMade] ?? null; ?>
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cash"></i> Record Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="<?= BASE_PATH ?>/loans/pay">
        <?= csrfField() ?>
        <input type="hidden" name="account_id" value="<?= $id ?>">
        <input type="hidden" name="redirect" value="schedule">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label required">Payment Date</label>
              <input type="date" name="payment_date" class="form-control"
                     value="<?= $nextSched ? $nextSched['date'] : $today ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label required">Payment Amount</label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" name="payment_amount" class="form-control"
                       step="0.01" min="0.01"
                       value="<?= number_format((float)$loan['payment_amount'], 2) ?>" required>
              </div>
            </div>
          </div>
          <?php if ($nextSched): ?>
          <div class="alert alert-info mt-3 mb-0 small">
            <strong>Payment #<?= $nextSched['num'] ?></strong>
            scheduled for <?= formatDate($nextSched['date'] ) ?><br>
            Scheduled: <strong><?= formatMoney($nextSched['payment']) ?></strong>
            (Principal <?= formatMoney($nextSched['principal']) ?> +
            Interest <?= formatMoney($nextSched['interest']) ?>)<br>
            Balance after: <?= formatMoney($nextSched['balance']) ?>
          </div>
          <?php else: ?>
          <div class="alert alert-success mt-3 mb-0 small">
            All <?= (int)$loan['term_months'] ?> scheduled payments have been recorded.
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-circle"></i> Record Payment
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
