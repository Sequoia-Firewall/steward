<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('user', 'administrator');

$id      = (int)($_GET['id'] ?? 0);
$account = getAccount($id);

if (!$account || $account['type'] === 'Asset') {
    setFlash('error', 'Reconciliation is not available for this account.');
    header('Location: ' . BASE_PATH . '/accounts/index');
    exit;
}
$isInvestAccount = ($account['type'] === 'Investment' && !$account['is_investment_cash']);
if ($isInvestAccount) {
    setFlash('error', 'Reconciliation is not available for investment accounts.');
    header('Location: ' . BASE_PATH . '/accounts/register?id=' . $id);
    exit;
}

$db = getDB();

// Opening balance = account opening_balance + all previously reconciled transactions
$stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account_id = ? AND cleared_status = ?');
$stmt->execute([$id, 'reconciled']);
$reconciledSum  = (float)$stmt->fetchColumn();
$openingBalance = (float)$account['opening_balance'] + $reconciledSum;
$isCreditCard   = ($account['type'] === 'Credit Card');
// Credit cards: balances are stored negative (charges reduce the balance).
// For display, flip sign so users see the amount they owe as a positive number.
$displayOpeningBalance = $isCreditCard ? -$openingBalance : $openingBalance;

// All unreconciled transactions (cleared and uncleared) sorted by date
$stmt2 = $db->prepare(
    'SELECT t.*,
            c.name  AS category_name,
            sc.name AS subcategory_name,
            pa.name AS paired_account_name
     FROM transactions t
     LEFT JOIN transaction_splits ts ON ts.transaction_id = t.id AND t.is_split = 0
                                    AND ts.id = (SELECT MIN(s2.id) FROM transaction_splits s2 WHERE s2.transaction_id = t.id)
     LEFT JOIN categories c  ON c.id  = ts.category_id
     LEFT JOIN categories sc ON sc.id = ts.subcategory_id
     LEFT JOIN transactions pt ON pt.id = t.transfer_pair_id
     LEFT JOIN accounts pa     ON pa.id = pt.account_id
     WHERE t.account_id = ? AND t.cleared_status != ?
     ORDER BY t.transaction_date ASC, t.id ASC'
);
$stmt2->execute([$id, 'reconciled']);
$transactions = $stmt2->fetchAll();

$pageTitle        = 'Reconcile — ' . $account['name'];
$currentPage      = 'accounts';
$currentAccountId = $id;
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <h2><i class="bi bi-check2-square"></i> Reconcile: <?= h($account['name']) ?></h2>
  <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Back to Register
  </a>
</div>

<!-- Statement info -->
<div class="recon-setup">
  <div class="recon-setup-field">
    <label><?= $isCreditCard ? 'Balance Owed' : 'Opening Balance' ?></label>
    <span class="recon-opening-val"><?= formatMoney($displayOpeningBalance) ?></span>
    <small><?= $isCreditCard ? 'Amount owed as of last reconciliation' : 'Balance as of last reconciliation' ?></small>
    <?php if ($account['last_reconciled_date']): ?>
    <small class="recon-last-date">Last reconciled <?= formatDate($account['last_reconciled_date']) ?></small>
    <?php endif; ?>
  </div>
  <div class="recon-setup-field">
    <label for="statementDate">Statement Date</label>
    <input type="date" id="statementDate" class="form-control form-control-sm"
           value="<?= date('Y-m-d') ?>">
  </div>
  <div class="recon-setup-field">
    <label for="statementBalance">Statement Ending Balance<?= $isCreditCard ? ' (Amount Owed)' : '' ?></label>
    <div class="input-group input-group-sm recon-balance-input">
      <span class="input-group-text">$</span>
      <input type="number" id="statementBalance" class="form-control" step="0.01"
             placeholder="0.00" autofocus>
    </div>
    <?php if ($isCreditCard): ?>
    <small class="text-muted">Enter the amount you owe from your statement (positive number)</small>
    <?php endif; ?>
  </div>
  <div class="recon-setup-field recon-setup-tip">
    <i class="bi bi-info-circle"></i>
    Check off each transaction that appears on your bank statement.
    When the difference reaches <strong>$0.00</strong>, click Finish.
  </div>
</div>

<!-- Transaction table -->
<form method="post" action="<?= BASE_PATH ?>/accounts/reconcile_save" id="reconcileForm">
  <?= csrfField() ?>
  <input type="hidden" name="account_id" value="<?= $id ?>">
  <input type="hidden" name="statement_date"    id="hiddenDate"    value="<?= date('Y-m-d') ?>">
  <input type="hidden" name="statement_balance" id="hiddenBalance" value="">

  <div class="recon-grid-wrapper">
    <table class="recon-grid" id="reconGrid">
      <thead>
        <tr>
          <th class="recon-col-check">
            <input type="checkbox" id="checkAll" title="Select all" class="form-check-input">
          </th>
          <th class="recon-col-date">Date</th>
          <th class="recon-col-num">Ref</th>
          <th class="recon-col-payee">Payee / Description</th>
          <th class="recon-col-cat">Category</th>
          <th class="recon-col-amt text-end">Payment</th>
          <th class="recon-col-amt text-end">Deposit</th>
          <th class="recon-col-status text-center">C</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($transactions)): ?>
        <tr>
          <td colspan="8" class="text-center text-muted py-4">
            All transactions are already reconciled.
          </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($transactions as $txn):
          $amt     = (float)$txn['amount'];
          $payment = $amt < 0 ? abs($amt) : 0;
          $deposit = $amt >= 0 ? $amt : 0;
          $preChecked = ($txn['cleared_status'] === 'cleared');
          if ($txn['is_split']) {
              $catDisplay = '— Split —';
          } elseif ($txn['type'] === 'transfer' && $txn['paired_account_name']) {
              $catDisplay = '⇄ ' . $txn['paired_account_name'];
          } elseif ($txn['subcategory_name']) {
              $catDisplay = $txn['category_name'] . ' › ' . $txn['subcategory_name'];
          } else {
              $catDisplay = $txn['category_name'] ?? '';
          }
        ?>
        <tr class="recon-row <?= $preChecked ? 'pre-cleared' : '' ?>"
            data-amount="<?= $amt ?>">
          <td class="recon-col-check" onclick="event.stopPropagation()">
            <input type="checkbox" name="txn_ids[]" value="<?= $txn['id'] ?>"
                   class="recon-check form-check-input"
                   data-amount="<?= $amt ?>"
                   <?= $preChecked ? 'checked' : '' ?>
                   onchange="updateTotals()">
          </td>
          <td class="recon-col-date"><?= formatDate($txn['transaction_date']) ?></td>
          <td class="recon-col-num"><?= h($txn['num']) ?></td>
          <td class="recon-col-payee">
            <div class="payee-name"><?= h($txn['payee']) ?></div>
            <?php if ($txn['memo']): ?><div class="txn-memo"><?= h($txn['memo']) ?></div><?php endif; ?>
          </td>
          <td class="recon-col-cat"><?= h($catDisplay) ?></td>
          <td class="recon-col-amt text-end"><?= $payment > 0 ? '<span class="amount-debit">' . formatMoney($payment) . '</span>' : '' ?></td>
          <td class="recon-col-amt text-end"><?= $deposit > 0 ? '<span class="amount-credit">' . formatMoney($deposit) . '</span>' : '' ?></td>
          <td class="recon-col-status text-center">
            <?php if ($txn['cleared_status'] === 'cleared'): ?>
              <span class="cleared-c" title="Cleared">c</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Sticky summary footer -->
  <div class="recon-footer" id="reconFooter">
    <div class="recon-footer-inner">
      <div class="recon-summary">
        <div class="recon-sum-item">
          <span class="recon-sum-label"><?= $isCreditCard ? 'Balance Owed' : 'Opening Balance' ?></span>
          <span class="recon-sum-val"><?= formatMoney($displayOpeningBalance) ?></span>
        </div>
        <span class="recon-sum-op"><?= $isCreditCard ? '+' : '+' ?></span>
        <div class="recon-sum-item">
          <span class="recon-sum-label"><?= $isCreditCard ? 'New Charges (net)' : 'Cleared Transactions' ?></span>
          <span class="recon-sum-val" id="clearedAmt">$0.00</span>
        </div>
        <span class="recon-sum-op">=</span>
        <div class="recon-sum-item">
          <span class="recon-sum-label"><?= $isCreditCard ? 'Total Owed' : 'Cleared Balance' ?></span>
          <span class="recon-sum-val" id="clearedBalance"><?= formatMoney($displayOpeningBalance) ?></span>
        </div>
        <div class="recon-sum-divider"></div>
        <div class="recon-sum-item">
          <span class="recon-sum-label">Statement Balance</span>
          <span class="recon-sum-val" id="stmtBalDisplay">—</span>
        </div>
        <div class="recon-sum-item recon-diff-item">
          <span class="recon-sum-label">Difference</span>
          <span class="recon-sum-val recon-diff" id="diffAmt">—</span>
        </div>
      </div>
      <div class="recon-footer-actions">
        <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $id ?>"
           class="btn btn-outline-secondary btn-sm">Cancel</a>
        <button type="submit" class="btn btn-success btn-sm" id="btnFinish" disabled>
          <i class="bi bi-check2-all"></i> Finish Reconciliation
        </button>
      </div>
    </div>
  </div>
</form>

<script>
const OPENING_BALANCE = <?= json_encode($openingBalance) ?>;
const IS_CREDIT_CARD  = <?= json_encode($isCreditCard) ?>;

function fmt(n) {
  const neg = n < 0;
  const s = Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return (neg ? '-$' : '$') + s;
}

function updateTotals() {
  let cleared = 0;
  document.querySelectorAll('.recon-check:checked').forEach(cb => {
    cleared += parseFloat(cb.dataset.amount);
  });
  const clearedBalance = OPENING_BALANCE + cleared;
  const stmtInput      = parseFloat(document.getElementById('statementBalance').value);
  const hasStmt        = !isNaN(stmtInput);

  // Credit cards: user enters the amount they owe (positive). Internally balances
  // are negative (charges reduce balance). Flip sign so the math works correctly.
  const stmtInternal = IS_CREDIT_CARD ? -stmtInput : stmtInput;
  const diff         = hasStmt ? stmtInternal - clearedBalance : null;
  const balanced     = diff !== null && Math.abs(diff) < 0.005;

  // For display, credit card shows amounts as positive (what you owe)
  const displayCleared    = IS_CREDIT_CARD ? -cleared    : cleared;
  const displayClearedBal = IS_CREDIT_CARD ? -clearedBalance : clearedBalance;
  const displayDiff       = IS_CREDIT_CARD ? -diff : diff;

  document.getElementById('clearedAmt').textContent     = fmt(displayCleared);
  document.getElementById('clearedBalance').textContent = fmt(displayClearedBal);
  document.getElementById('stmtBalDisplay').textContent = hasStmt ? fmt(stmtInput) : '—';

  const diffEl = document.getElementById('diffAmt');
  diffEl.textContent = diff !== null ? fmt(displayDiff) : '—';
  diffEl.className   = 'recon-sum-val recon-diff' + (diff === null ? '' : (balanced ? ' diff-ok' : ' diff-off'));

  document.getElementById('btnFinish').disabled = !balanced;

  // Highlight checked rows
  document.querySelectorAll('.recon-row').forEach(row => {
    const cb = row.querySelector('.recon-check');
    row.classList.toggle('row-checked', cb && cb.checked);
  });
}

// Statement balance input
document.getElementById('statementBalance').addEventListener('input', function () {
  document.getElementById('hiddenBalance').value = this.value;
  updateTotals();
});
document.getElementById('statementDate').addEventListener('change', function () {
  document.getElementById('hiddenDate').value = this.value;
});

// Click row to toggle
document.querySelectorAll('.recon-row').forEach(row => {
  row.addEventListener('click', function () {
    const cb = this.querySelector('.recon-check');
    if (cb) { cb.checked = !cb.checked; updateTotals(); }
  });
});

// Check-all
document.getElementById('checkAll').addEventListener('change', function () {
  document.querySelectorAll('.recon-check').forEach(cb => cb.checked = this.checked);
  updateTotals();
});

// Sync hidden balance on submit
document.getElementById('reconcileForm').addEventListener('submit', function () {
  document.getElementById('hiddenBalance').value =
    document.getElementById('statementBalance').value;
  document.getElementById('hiddenDate').value =
    document.getElementById('statementDate').value;
});

// Init
updateTotals();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
