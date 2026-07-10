<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/loan_utils.php';
requireLogin();

$db    = getDB();
$loans = $db->query(
    "SELECT a.*,
            ld.original_amount, ld.annual_rate, ld.term_months, ld.start_date, ld.payment_amount,
            a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id), 0) AS current_balance,
            (SELECT COUNT(*) FROM transactions t2 WHERE t2.account_id = a.id) AS payments_made
     FROM accounts a
     JOIN loan_details ld ON ld.account_id = a.id
     WHERE a.type = 'Loan' AND a.is_active = 1
     ORDER BY a.name"
)->fetchAll();

$today       = date('Y-m-d');
$pageTitle   = 'Loans';
$currentPage = 'loans';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-cash-coin"></i> Loans</h2>
  <?php if (canEdit()): ?>
  <a href="<?= BASE_PATH ?>/accounts/create" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-circle"></i> New Loan Account
  </a>
  <?php endif; ?>
</div>

<?= renderFlash() ?>

<?php if (empty($loans)): ?>
<div class="alert alert-info">
  No loan accounts found.
  <?php if (canEdit()): ?> <a href="<?= BASE_PATH ?>/accounts/create">Create a loan account</a> to get started.<?php endif; ?>
</div>
<?php endif; ?>

<?php foreach ($loans as $loan):
  $outstanding  = -(float)$loan['current_balance'];
  $original     = (float)$loan['original_amount'];
  $paidPct      = $original > 0 ? max(0, min(100, round(($original - max(0, $outstanding)) / $original * 100, 1))) : 0;
  $rate         = (float)$loan['annual_rate'];
  $term         = (int)$loan['term_months'];
  $payment      = (float)$loan['payment_amount'];
  $paymentsMade = (int)$loan['payments_made'];

  $firstPayDate = getLoanFirstPaymentDate($loan);
  $nextDue      = date('Y-m-d', strtotime('+' . $paymentsMade . ' months', strtotime($firstPayDate)));
  $daysUntil    = (int)round((strtotime($nextDue) - strtotime($today)) / 86400);
  $remaining    = $term - $paymentsMade;
  $projPayoff   = $remaining > 0
      ? date('M Y', strtotime('+' . ($remaining - 1) . ' months', strtotime($nextDue)))
      : 'Paid off';
?>
<div class="card mb-3 shadow-sm">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
      <div>
        <h5 class="mb-0">
          <i class="bi bi-cash-coin text-primary me-1"></i><?= h($loan['name']) ?>
        </h5>
        <?php if ($loan['institution']): ?>
          <small class="text-muted"><?= h($loan['institution']) ?></small>
        <?php endif; ?>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <?php if (canEdit()): ?>
        <button class="btn btn-success btn-sm" data-bs-toggle="modal"
                data-bs-target="#payModal<?= $loan['id'] ?>">
          <i class="bi bi-cash"></i> Record Payment
        </button>
        <?php endif; ?>
        <a href="<?= BASE_PATH ?>/loans/schedule?id=<?= $loan['id'] ?>"
           class="btn btn-outline-primary btn-sm">
          <i class="bi bi-table"></i> Schedule
        </a>
        <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $loan['id'] ?>"
           class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-journal-text"></i> Register
        </a>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-6 col-md-2">
        <div class="text-muted small">Original Amount</div>
        <div class="fw-semibold"><?= formatMoney($original) ?></div>
      </div>
      <div class="col-6 col-md-2">
        <div class="text-muted small">Outstanding</div>
        <div class="fw-semibold <?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>">
          <?= formatMoney(max(0, $outstanding)) ?>
        </div>
      </div>
      <div class="col-6 col-md-2">
        <div class="text-muted small">Annual Rate</div>
        <div class="fw-semibold"><?= number_format($rate, 3) ?>%</div>
      </div>
      <div class="col-6 col-md-2">
        <div class="text-muted small">Term</div>
        <div class="fw-semibold"><?= $term ?> mo (<?= number_format($term / 12, 1) ?> yr)</div>
      </div>
      <div class="col-6 col-md-2">
        <div class="text-muted small">Monthly Payment</div>
        <div class="fw-semibold"><?= formatMoney($payment) ?></div>
      </div>
      <div class="col-6 col-md-2">
        <div class="text-muted small">Next Due</div>
        <div class="fw-semibold <?= $daysUntil < 0 ? 'text-danger' : ($daysUntil <= 7 ? 'text-warning' : '') ?>">
          <?= formatDate($nextDue) ?>
          <?php if ($daysUntil < 0): ?>
            <span class="badge bg-danger ms-1"><?= abs($daysUntil) ?>d overdue</span>
          <?php elseif ($daysUntil === 0): ?>
            <span class="badge bg-warning text-dark ms-1">Due today</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div>
      <div class="d-flex justify-content-between small text-muted mb-1">
        <span><?= $paidPct ?>% paid off (<?= $paymentsMade ?> of <?= $term ?> payments)</span>
        <span>Projected payoff: <strong><?= h($projPayoff) ?></strong></span>
      </div>
      <div class="progress" style="height:8px">
        <div class="progress-bar bg-success" style="width:<?= $paidPct ?>%"></div>
      </div>
    </div>
  </div>
</div>

<?php if (canEdit()): ?>
<div class="modal fade" id="payModal<?= $loan['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cash"></i> Record Payment — <?= h($loan['name']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="<?= BASE_PATH ?>/loans/pay">
        <?= csrfField() ?>
        <input type="hidden" name="account_id" value="<?= $loan['id'] ?>">
        <input type="hidden" name="redirect" value="index">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label required">Payment Date</label>
              <input type="date" name="payment_date" class="form-control"
                     value="<?= $today ?>" required>
            </div>
            <div class="col-6">
              <label class="form-label required">Payment Amount</label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" name="payment_amount" class="form-control"
                       step="0.01" min="0.01"
                       value="<?= number_format($payment, 2) ?>" required>
              </div>
            </div>
          </div>
          <div class="alert alert-info mt-3 mb-0 small">
            <strong>Outstanding balance:</strong> <?= formatMoney(max(0, $outstanding)) ?><br>
            Principal and interest split will be calculated automatically.
          </div>
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

<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
