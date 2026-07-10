<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('user', 'administrator');

$id      = (int)($_GET['id'] ?? 0);
$account = getAccount($id);
if (!$account) {
    setFlash('error', 'Account not found.');
    header('Location: ' . BASE_PATH . '/accounts/index');
    exit;
}

$errors = [];
$form   = $account;
$loanDetails = $account['type'] === 'Loan' ? getLoanDetails($id) : false;

// Check for linked cash account (Investment accounts only)
$linkedCash = null;
if (isInvestLike($account['type'])) {
    $db = getDB();
    if (!empty($account['linked_account_id'])) {
        $cs = $db->prepare('SELECT * FROM accounts WHERE id = ? AND is_investment_cash = 1');
        $cs->execute([$account['linked_account_id']]);
        $linkedCash = $cs->fetch() ?: null;
    }
}
// Populate loan edit fields from existing details
if ($loanDetails) {
    $form['original_amount'] = $loanDetails['original_amount'];
    $form['annual_rate']     = $loanDetails['annual_rate'];
    $form['term_months']     = $loanDetails['term_months'];
    $form['loan_start_date'] = $loanDetails['start_date'];
    $form['payment_amount']  = $loanDetails['payment_amount'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Handle cash account creation separately (create or re-create after deletion)
    if (isset($_POST['create_cash']) && isInvestLike($account['type']) && ($linkedCash === null || !$linkedCash['is_active'])) {
        $db = $db ?? getDB();
        $cashName = $account['name'] . ' Cash';
        $db->prepare(
            'INSERT INTO accounts (name, type, institution, currency, opening_balance,
                                   is_investment_cash, linked_account_id, is_active, created_by)
             VALUES (?, \'investment-cash\', ?, ?, 0, 1, ?, 1, ?)'
        )->execute([
            $cashName, $account['institution'] ?? '', $account['currency'] ?? 'USD',
            $id, currentUserId(),
        ]);
        $cashId = (int)$db->lastInsertId();
        $db->prepare('UPDATE accounts SET linked_account_id = ? WHERE id = ?')->execute([$cashId, $id]);
        setFlash('success', 'Cash account "' . $cashName . '" created.');
        header('Location: ' . BASE_PATH . '/accounts/edit?id=' . $id);
        exit;
    }
    $form = [
        'name'           => trim($_POST['name'] ?? ''),
        'type'           => $_POST['type'] ?? $account['type'],
        'institution'    => trim($_POST['institution'] ?? ''),
        'account_number' => trim($_POST['account_number'] ?? ''),
        'routing_number' => trim($_POST['routing_number'] ?? ''),
        'comment'        => trim($_POST['comment'] ?? ''),
        'is_favorite'            => isset($_POST['is_favorite']) ? '1' : '0',
        'is_retirement'          => isset($_POST['is_retirement']) ? '1' : '0',
        'exclude_from_net_worth' => isset($_POST['exclude_from_net_worth']) ? '1' : '0',
        'hide_from_sidebar'      => isset($_POST['hide_from_sidebar']) ? '1' : '0',
        'is_closed'      => $account['is_closed'], // default: keep current state
        'min_balance'    => $_POST['min_balance'] ?? '0.00',
        'currency'       => trim($_POST['currency'] ?? 'USD'),
        'opening_balance'=> $_POST['opening_balance'] ?? '0.00',
        // Loan fields
        'original_amount' => $_POST['original_amount'] ?? '',
        'annual_rate'     => $_POST['annual_rate']     ?? '',
        'term_months'     => $_POST['term_months']     ?? '',
        'loan_start_date' => $_POST['loan_start_date'] ?? '',
        'payment_amount'  => $_POST['payment_amount']  ?? '',
    ];

    // Only admin can change is_closed
    if (isAdmin()) {
        $requestingClose  = isset($_POST['is_closed']) && !$account['is_closed'];
        $requestingReopen = !isset($_POST['is_closed']) && $account['is_closed'];
        $form['is_closed'] = isset($_POST['is_closed']) ? '1' : '0';

        if ($requestingClose) {
            $currentBalance = getAccountBalance($id);
            if (abs($currentBalance) >= 0.005) {
                $errors[] = 'Account cannot be closed until its balance is $0.00 (current: ' . formatMoney($currentBalance) . ').';
            }
            if (empty($errors) && !empty($account['linked_account_id'])) {
                $cashBalance = getAccountBalance((int)$account['linked_account_id']);
                if (abs($cashBalance) >= 0.005) {
                    $errors[] = 'Account cannot be closed until its linked cash account balance is $0.00 (current: ' . formatMoney($cashBalance) . ').';
                }
            }
        }
    }

    if ($form['name'] === '') $errors[] = 'Account name is required.';

    // Only admin can change opening balance
    if (!isAdmin()) $form['opening_balance'] = $account['opening_balance'];

    if ($account['type'] === 'Loan') {
        if ((float)$form['annual_rate'] < 0) $errors[] = 'Annual interest rate must be 0 or greater.';
        if ((int)$form['term_months'] <= 0)  $errors[] = 'Loan term is required.';
        if (empty($form['loan_start_date']))  $errors[] = 'Loan start date is required.';
    }

    if (empty($errors)) {
        $db = getDB();
        $db->prepare(
            'UPDATE accounts SET name=?, type=?, institution=?, account_number=?, routing_number=?,
             comment=?, is_favorite=?, is_retirement=?, min_balance=?, currency=?, opening_balance=?,
             exclude_from_net_worth=?, hide_from_sidebar=?, is_closed=? WHERE id=?'
        )->execute([
            $form['name'], $form['type'], $form['institution'],
            $form['account_number'], $form['routing_number'], $form['comment'],
            $form['is_favorite'], $form['is_retirement'], (float)$form['min_balance'], $form['currency'],
            (float)$form['opening_balance'], $form['exclude_from_net_worth'], $form['hide_from_sidebar'],
            $form['is_closed'], $id,
        ]);

        // Keep linked cash account name in sync when the investment account is renamed
        if (isInvestLike($account['type']) && $form['name'] !== $account['name'] && !empty($account['linked_account_id'])) {
            $db->prepare('UPDATE accounts SET name = ? WHERE id = ? AND is_investment_cash = 1')
               ->execute([$form['name'] . ' Cash', $account['linked_account_id']]);
        }

        // When an account is renamed, update the payee on any transfer transactions
        // that stored the old account name as the payee (common with investment imports).
        // Match transactions where:
        //   • the partner leg lives in this account (or its linked cash sub-account), OR
        //   • the transaction itself lives in this account (or its linked cash sub-account).
        // Both cases arise with investment accounts: the outgoing leg in a cash account has
        // the investment account name as payee, as does the incoming leg in the cash account.
        if ($form['name'] !== $account['name']) {
            $linkedId   = (int)($account['linked_account_id'] ?? 0);
            $relatedIds = array_values(array_filter(array_unique([$id, $linkedId])));
            $ph         = implode(',', array_fill(0, count($relatedIds), '?'));
            $db->prepare(
                "UPDATE transactions t
                   JOIN transactions pt ON pt.id = t.transfer_pair_id
                    SET t.payee = ?
                  WHERE t.type  = 'transfer'
                    AND t.payee = ?
                    AND (pt.account_id IN ($ph) OR t.account_id IN ($ph))"
            )->execute(array_merge([$form['name'], $account['name']], $relatedIds, $relatedIds));
        }

        // When closing: auto-close linked cash account and warn about active bills
        if (isAdmin() && $form['is_closed'] && !$account['is_closed']) {
            if (!empty($account['linked_account_id'])) {
                $db->prepare('UPDATE accounts SET is_closed = 1 WHERE id = ?')
                   ->execute([$account['linked_account_id']]);
            }
            $bStmt = $db->prepare('SELECT COUNT(*) FROM scheduled_bills WHERE account_id = ? AND is_active = 1');
            $bStmt->execute([$id]);
            $billCount = (int)$bStmt->fetchColumn();
            if ($billCount > 0) {
                setFlash('warning', 'Account closed. ' . $billCount . ' active bill' . ($billCount !== 1 ? 's are' : ' is') . ' still assigned to this account — update them in Bills.');
                header('Location: ' . BASE_PATH . '/accounts/register?id=' . $id);
                exit;
            }
        }

        // When reopening: also reopen linked cash account
        if (isAdmin() && !$form['is_closed'] && $account['is_closed']) {
            if (!empty($account['linked_account_id'])) {
                $db->prepare('UPDATE accounts SET is_closed = 0 WHERE id = ?')
                   ->execute([$account['linked_account_id']]);
            }
        }

        if ($account['type'] === 'Loan') {
            $payAmt = (float)$form['payment_amount'];
            if ($payAmt <= 0) {
                require_once __DIR__ . '/../loans/loan_utils.php';
                $payAmt = calcMonthlyPayment(
                    (float)($loanDetails['original_amount'] ?? $form['original_amount']),
                    (float)$form['annual_rate'],
                    (int)$form['term_months']
                );
            }
            $db->prepare(
                'INSERT INTO loan_details (account_id, original_amount, annual_rate, term_months, start_date, payment_amount)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE annual_rate=VALUES(annual_rate), term_months=VALUES(term_months),
                   start_date=VALUES(start_date), payment_amount=VALUES(payment_amount)'
            )->execute([
                $id,
                (float)($loanDetails['original_amount'] ?? $form['original_amount']),
                (float)$form['annual_rate'],
                (int)$form['term_months'],
                $form['loan_start_date'],
                $payAmt,
            ]);
        }

        setFlash('success', 'Account updated successfully.');
        header('Location: ' . BASE_PATH . '/accounts/register?id=' . $id);
        exit;
    }
}

$pageTitle   = 'Edit Account';
$currentPage = 'accounts';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-pencil"></i> Edit Account: <?= h($account['name']) ?></h2>
  <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Back to Register
  </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="form-card">
<form id="editAccountForm" method="post" novalidate>
  <?= csrfField() ?>

  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label required">Account Name</label>
      <input type="text" name="name" class="form-control" value="<?= h($form['name']) ?>" required>
    </div>
    <div class="col-md-3">
      <label class="form-label required">Account Type</label>
      <?php if ($account['type'] === 'investment-cash'): ?>
      <input type="text" class="form-control" value="Investment Cash" disabled>
      <input type="hidden" name="type" value="investment-cash">
      <?php else: ?>
      <select name="type" id="acctType" class="form-select" <?= !isAdmin() ? 'disabled' : '' ?> onchange="onTypeChange()">
        <?php foreach (['Checking', 'Savings', 'Credit Card', 'Investment', 'Crypto', 'Asset', 'Loan'] as $t): ?>
        <option value="<?= $t ?>" <?= $form['type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!isAdmin()): ?><input type="hidden" name="type" value="<?= h($form['type']) ?>"><?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="col-md-3">
      <label class="form-label">Currency</label>
      <select name="currency" class="form-select">
        <?php foreach (['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF'] as $cur): ?>
        <option value="<?= $cur ?>" <?= $form['currency'] === $cur ? 'selected' : '' ?>><?= $cur ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-6">
      <label class="form-label">Financial Institution</label>
      <input type="text" name="institution" id="acctInstitution" class="form-control" value="<?= h($form['institution']) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Account Number</label>
      <input type="text" name="account_number" id="acctAccountNumber" class="form-control" value="<?= h($form['account_number']) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Routing Number</label>
      <input type="text" name="routing_number" id="acctRoutingNumber" class="form-control" value="<?= h($form['routing_number']) ?>">
    </div>

    <?php if ($account['type'] === 'Loan'): ?>
    <!-- Loan detail fields -->
    <div class="col-12">
      <div class="row g-3">
        <div class="col-md-2">
          <label class="form-label">Annual Rate (%)</label>
          <div class="input-group">
            <input type="number" name="annual_rate" class="form-control"
                   step="0.001" min="0" max="100"
                   value="<?= h($form['annual_rate'] ?? '') ?>">
            <span class="input-group-text">%</span>
          </div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Term (months)</label>
          <input type="number" name="term_months" class="form-control"
                 step="1" min="1"
                 value="<?= h($form['term_months'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Start Date</label>
          <input type="date" name="loan_start_date" class="form-control"
                 value="<?= h($form['loan_start_date'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Monthly Payment</label>
          <div class="input-group">
            <span class="input-group-text">$</span>
            <input type="number" name="payment_amount" class="form-control"
                   step="0.01" min="0"
                   value="<?= h($form['payment_amount'] ?? '') ?>">
          </div>
          <div class="form-text">Leave 0 to auto-recalculate.</div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="col-md-4">
      <label class="form-label">Opening Balance <?= isAdmin() ? '' : '<small class="text-muted">(admin only)</small>' ?></label>
      <div class="input-group">
        <span class="input-group-text">$</span>
        <input type="number" name="opening_balance" class="form-control" step="0.01"
               value="<?= h($form['opening_balance']) ?>" <?= !isAdmin() ? 'readonly' : '' ?>>
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label">Minimum Balance</label>
      <div class="input-group">
        <span class="input-group-text">$</span>
        <input type="number" name="min_balance" class="form-control" step="0.01" value="<?= h($form['min_balance']) ?>">
      </div>
    </div>
    <div class="col-md-4 d-flex align-items-end gap-3 flex-wrap">
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="is_favorite" id="isFavorite"
               <?= $form['is_favorite'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="isFavorite">
          <i class="bi bi-star-fill text-warning"></i> Favorite Account
        </label>
      </div>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="hide_from_sidebar" id="hideFromSidebar"
               <?= !empty($form['hide_from_sidebar']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="hideFromSidebar">
          <i class="bi bi-eye-slash text-secondary"></i> Hide from Menu
        </label>
      </div>
      <?php if (isAdmin()): ?>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="is_closed" id="isClosed"
               <?= $form['is_closed'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="isClosed">
          <i class="bi bi-lock-fill text-secondary"></i> Closed Account
        </label>
      </div>
      <?php endif; ?>
      <?php if (isInvestLike($account['type'])): ?>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="is_retirement" id="isRetirement"
               <?= !empty($form['is_retirement']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="isRetirement">
          <i class="bi bi-piggy-bank-fill text-success"></i> Retirement Account
        </label>
      </div>
      <?php endif; ?>
      <?php if ($account['type'] === 'Asset'): ?>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="exclude_from_net_worth" id="excludeFromNW"
               <?= !empty($form['exclude_from_net_worth']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="excludeFromNW">
          Exclude from Net Worth
        </label>
      </div>
      <?php endif; ?>
    </div>

    <?php if (isInvestLike($account['type'])): ?>
    <div class="col-12">
      <div class="card border-secondary">
        <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
          <i class="bi bi-cash-coin text-secondary fs-5"></i>
          <?php if (!empty($linkedCash) && $linkedCash['is_active']): ?>
          <span class="small">
            Linked cash account: <strong><?= h($linkedCash['name']) ?></strong>
            <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $linkedCash['id'] ?>" class="ms-2 small">(view register)</a>
          </span>
          <?php elseif (!empty($linkedCash) && !$linkedCash['is_active']): ?>
          <span class="small text-muted">Cash account was deleted.</span>
          <button type="submit" form="createCashForm" name="create_cash" value="1"
                  class="btn btn-sm btn-outline-primary ms-auto">
            <i class="bi bi-plus-circle"></i> Re-create Cash Account
          </button>
          <?php else: ?>
          <span class="small text-muted">No linked cash account.</span>
          <button type="submit" form="createCashForm" name="create_cash" value="1"
                  class="btn btn-sm btn-outline-primary ms-auto">
            <i class="bi bi-plus-circle"></i> Create Cash Account
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="col-12">
      <label class="form-label">Comment / Notes</label>
      <textarea name="comment" class="form-control" rows="2"><?= h($form['comment']) ?></textarea>
    </div>
  </div>

  <div class="form-actions mt-4">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Changes</button>
    <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $id ?>" class="btn btn-outline-secondary ms-2">Cancel</a>
  </div>
</form>
<?php if (isInvestLike($account['type'])): ?>
<form id="createCashForm" method="post">
  <?= csrfField() ?>
</form>
<?php endif; ?>
</div>

<script>
function onTypeChange() {
  const isAsset = document.getElementById('acctType').value === 'Asset';
  document.getElementById('acctInstitution').disabled   = isAsset;
  document.getElementById('acctAccountNumber').disabled = isAsset;
  document.getElementById('acctRoutingNumber').disabled = isAsset;
}
onTypeChange();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
