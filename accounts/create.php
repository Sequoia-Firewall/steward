<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('user', 'administrator');

$errors = [];
$form   = [
    'name'                => '',
    'type'                => 'Checking',
    'institution'         => '',
    'account_number'      => '',
    'routing_number'      => '',
    'comment'             => '',
    'is_favorite'         => '0',
    'is_retirement'       => '0',
    'min_balance'         => '0.00',
    'currency'            => 'USD',
    'opening_balance'     => '0.00',
    'cash_opening_balance'=> '0.00',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $form = [
        'name'                => trim($_POST['name'] ?? ''),
        'type'                => $_POST['type'] ?? 'Checking',
        'institution'         => trim($_POST['institution'] ?? ''),
        'account_number'      => trim($_POST['account_number'] ?? ''),
        'routing_number'      => trim($_POST['routing_number'] ?? ''),
        'comment'             => trim($_POST['comment'] ?? ''),
        'is_favorite'            => isset($_POST['is_favorite']) ? '1' : '0',
        'is_retirement'          => isset($_POST['is_retirement']) ? '1' : '0',
        'exclude_from_net_worth' => isset($_POST['exclude_from_net_worth']) ? '1' : '0',
        'min_balance'         => $_POST['min_balance'] ?? '0.00',
        'currency'            => trim($_POST['currency'] ?? 'USD'),
        'opening_balance'     => $_POST['opening_balance'] ?? '0.00',
        'cash_opening_balance'=> $_POST['cash_opening_balance'] ?? '0.00',
        'create_cash'         => isset($_POST['create_cash']) ? '1' : '0',
    ];

    // Loan-specific fields
    $form['original_amount'] = $_POST['original_amount'] ?? '';
    $form['annual_rate']     = $_POST['annual_rate']     ?? '';
    $form['term_months']     = $_POST['term_months']     ?? '';
    $form['loan_start_date'] = $_POST['loan_start_date'] ?? '';
    $form['payment_amount']  = $_POST['payment_amount']  ?? '';

    if ($form['name'] === '') $errors[] = 'Account name is required.';
    if (!in_array($form['type'], ['Checking', 'Savings', 'Credit Card', 'Investment', 'Crypto', 'Asset', 'Loan'])) $errors[] = 'Invalid account type.';

    if ($form['type'] === 'Loan') {
        if ((float)$form['original_amount'] <= 0) $errors[] = 'Original loan amount is required.';
        if ((float)$form['annual_rate'] < 0)      $errors[] = 'Annual interest rate is required.';
        if ((int)$form['term_months'] <= 0)        $errors[] = 'Loan term is required.';
        if (empty($form['loan_start_date']))        $errors[] = 'Loan start date is required.';
    }

    if (empty($errors)) {
        $db = getDB();

        if (isInvestLike($form['type'])) {
            $db->beginTransaction();
            try {
                // 1. Investment / Crypto account
                $stmt = $db->prepare(
                    'INSERT INTO accounts (name, type, institution, account_number, routing_number, comment,
                                          is_favorite, is_retirement, min_balance, currency, opening_balance, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $form['name'], $form['type'], $form['institution'],
                    $form['account_number'], $form['routing_number'], $form['comment'],
                    $form['is_favorite'], $form['is_retirement'], (float)$form['min_balance'], $form['currency'],
                    (float)$form['opening_balance'], currentUserId(),
                ]);
                $investId = (int)$db->lastInsertId();

                if ($form['create_cash'] === '1') {
                    // 2. Cash sub-account
                    $cashName = $form['name'] . ' Cash';
                    $stmt2 = $db->prepare(
                        'INSERT INTO accounts (name, type, institution, account_number, routing_number, comment,
                                              is_favorite, min_balance, currency, opening_balance,
                                              is_investment_cash, linked_account_id, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
                    );
                    $stmt2->execute([
                        $cashName, 'investment-cash', $form['institution'],
                        $form['account_number'], $form['routing_number'], $form['comment'],
                        0, 0.00, $form['currency'],
                        (float)$form['cash_opening_balance'],
                        $investId, currentUserId(),
                    ]);
                    $cashId = (int)$db->lastInsertId();

                    // 3. Link investment account back to its cash account
                    $db->prepare('UPDATE accounts SET linked_account_id = ? WHERE id = ?')
                       ->execute([$cashId, $investId]);

                    $successMsg = $form['type'] . ' account "' . $form['name'] . '" and cash account "' . $cashName . '" created successfully.';
                } else {
                    $successMsg = $form['type'] . ' account "' . $form['name'] . '" created successfully.';
                }

                $db->commit();
                logActivity('account_created', 'Created ' . $form['type'] . ' account "' . $form['name'] . '"');
                setFlash('success', $successMsg);
                header('Location: ' . BASE_PATH . '/accounts/register?id=' . $investId);
                exit;

            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Failed to create accounts. Please try again.';
            }

        } elseif ($form['type'] === 'Loan') {
            $origAmount = abs((float)$form['original_amount']);
            $openBal    = -$origAmount;

            // Auto-compute payment if not provided or zero
            $payAmt = (float)$form['payment_amount'];
            if ($payAmt <= 0) {
                require_once __DIR__ . '/../loans/loan_utils.php';
                $payAmt = calcMonthlyPayment($origAmount, (float)$form['annual_rate'], (int)$form['term_months']);
            }

            $db->beginTransaction();
            try {
                $db->prepare(
                    'INSERT INTO accounts (name, type, institution, account_number, routing_number, comment,
                                          is_favorite, min_balance, currency, opening_balance, created_by)
                     VALUES (?, \'Loan\', ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $form['name'], $form['institution'],
                    $form['account_number'], $form['routing_number'], $form['comment'],
                    $form['is_favorite'], (float)$form['min_balance'], $form['currency'],
                    $openBal, currentUserId(),
                ]);
                $newId = (int)$db->lastInsertId();

                $db->prepare(
                    'INSERT INTO loan_details (account_id, original_amount, annual_rate, term_months, start_date, payment_amount)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $newId, $origAmount, (float)$form['annual_rate'],
                    (int)$form['term_months'], $form['loan_start_date'], $payAmt,
                ]);

                $db->commit();
                logActivity('account_created', 'Created Loan account "' . $form['name'] . '"');
                setFlash('success', 'Loan account "' . $form['name'] . '" created successfully.');
                header('Location: ' . BASE_PATH . '/loans/schedule?id=' . $newId);
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Failed to create loan account. Please try again.';
            }

        } else {
            // Standard single-account creation
            $stmt = $db->prepare(
                'INSERT INTO accounts (name, type, institution, account_number, routing_number, comment,
                                      is_favorite, min_balance, currency, opening_balance,
                                      exclude_from_net_worth, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $form['name'], $form['type'], $form['institution'],
                $form['account_number'], $form['routing_number'], $form['comment'],
                $form['is_favorite'], (float)$form['min_balance'], $form['currency'],
                (float)$form['opening_balance'],
                $form['exclude_from_net_worth'], currentUserId(),
            ]);
            $newId = $db->lastInsertId();
            logActivity('account_created', 'Created ' . $form['type'] . ' account "' . $form['name'] . '"');
            setFlash('success', 'Account "' . $form['name'] . '" created successfully.');
            header('Location: ' . BASE_PATH . '/accounts/register?id=' . $newId);
            exit;
        }
    }
}

$pageTitle   = 'New Account';
$currentPage = 'accounts';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-plus-circle"></i> New Account</h2>
  <a href="<?= BASE_PATH ?>/accounts/index" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Back
  </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Creation method toggle -->
<div class="form-card mb-3">
  <div class="d-flex gap-4">
    <div class="form-check">
      <input class="form-check-input" type="radio" name="create_method" id="methodManual"
             value="manual" checked onchange="toggleCreateMethod()">
      <label class="form-check-label fw-semibold" for="methodManual">
        <i class="bi bi-pencil-square"></i> Enter Manually
      </label>
    </div>
    <div class="form-check">
      <input class="form-check-input" type="radio" name="create_method" id="methodImport"
             value="import" onchange="toggleCreateMethod()">
      <label class="form-check-label fw-semibold" for="methodImport">
        <i class="bi bi-upload"></i> Import from File
      </label>
    </div>
  </div>
</div>


<!-- Manual entry section -->
<div class="form-card" id="sectionManual">
<form method="post" novalidate>
  <?= csrfField() ?>

  <div class="form-section-title">Account Information</div>

  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label required">Account Name</label>
      <input type="text" name="name" id="acctName" class="form-control"
             value="<?= h($form['name']) ?>" required autofocus>
    </div>
    <div class="col-md-3">
      <label class="form-label required">Account Type</label>
      <select name="type" id="acctType" class="form-select" onchange="onTypeChange()">
        <?php foreach (['Checking', 'Savings', 'Credit Card', 'Investment', 'Crypto', 'Asset', 'Loan'] as $t): ?>
        <option value="<?= $t ?>" <?= $form['type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Currency</label>
      <select name="currency" class="form-select">
        <?php foreach (['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF'] as $cur): ?>
        <option value="<?= $cur ?>" <?= $form['currency'] === $cur ? 'selected' : '' ?>><?= $cur ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Investment notice -->
    <div class="col-12" id="investmentNotice" style="display:none">
      <div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-0">
        <i class="bi bi-info-circle-fill mt-1 flex-shrink-0"></i>
        <div class="flex-fill">
          <div id="noticeTextWith">
            <strong>Two accounts will be created:</strong>
            an <em id="noticeInvestName">investment</em> account for tracking holdings,
            and a companion <em id="noticeCashName">cash</em> account for cash transactions.
          </div>
          <div id="noticeTextWithout" style="display:none">
            An <em id="noticeInvestNameOnly">investment</em> account will be created for tracking holdings.
          </div>
          <div class="form-check mt-2 mb-0">
            <input class="form-check-input" type="checkbox" name="create_cash" id="createCash"
                   value="1" checked onchange="onCreateCashChange()">
            <label class="form-check-label" for="createCash">Create companion cash account</label>
          </div>
        </div>
      </div>
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

    <!-- Loan fields -->
    <div class="col-12" id="loanFields" style="display:none">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label required">Original Loan Amount</label>
          <div class="input-group">
            <span class="input-group-text">$</span>
            <input type="number" name="original_amount" id="loanOriginal" class="form-control"
                   step="0.01" min="0.01"
                   value="<?= h($form['original_amount'] ?? '') ?>"
                   oninput="calcPayment()">
          </div>
        </div>
        <div class="col-md-2">
          <label class="form-label required">Annual Rate (%)</label>
          <div class="input-group">
            <input type="number" name="annual_rate" id="loanRate" class="form-control"
                   step="0.001" min="0" max="100"
                   value="<?= h($form['annual_rate'] ?? '') ?>"
                   oninput="calcPayment()">
            <span class="input-group-text">%</span>
          </div>
        </div>
        <div class="col-md-2">
          <label class="form-label required">Term (months)</label>
          <input type="number" name="term_months" id="loanTerm" class="form-control"
                 step="1" min="1"
                 value="<?= h($form['term_months'] ?? '') ?>"
                 oninput="calcPayment()">
          <div class="form-text" id="termYears"></div>
        </div>
        <div class="col-md-2">
          <label class="form-label required">Start Date</label>
          <input type="date" name="loan_start_date" id="loanStartDate" class="form-control"
                 value="<?= h($form['loan_start_date'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Monthly Payment</label>
          <div class="input-group">
            <span class="input-group-text">$</span>
            <input type="number" name="payment_amount" id="loanPayment" class="form-control"
                   step="0.01" min="0"
                   value="<?= h($form['payment_amount'] ?? '') ?>"
                   placeholder="Auto-calculated">
          </div>
          <div class="form-text">Leave blank to auto-calculate.</div>
        </div>
      </div>
    </div>

    <!-- Standard opening balance -->
    <div class="col-md-4" id="openingBalanceGroup">
      <label class="form-label" id="openingBalanceLabel">Opening Balance</label>
      <div class="input-group">
        <span class="input-group-text">$</span>
        <input type="number" name="opening_balance" class="form-control" step="0.01"
               value="<?= h($form['opening_balance']) ?>">
      </div>
      <div class="form-text" id="openingBalanceHint">Enter a negative value for initial debt.</div>
    </div>

    <!-- Cash opening balance — Investment only -->
    <div class="col-md-4" id="cashOpeningBalanceGroup" style="display:none">
      <label class="form-label">Cash Opening Balance</label>
      <div class="input-group">
        <span class="input-group-text">$</span>
        <input type="number" name="cash_opening_balance" class="form-control" step="0.01"
               value="<?= h($form['cash_opening_balance']) ?>">
      </div>
      <div class="form-text">Starting cash balance in the companion cash account.</div>
    </div>

    <div class="col-md-4" id="minBalanceGroup">
      <label class="form-label">Minimum Balance</label>
      <div class="input-group">
        <span class="input-group-text">$</span>
        <input type="number" name="min_balance" class="form-control" step="0.01"
               value="<?= h($form['min_balance']) ?>">
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
      <div class="form-check mb-2" id="retirementToggle" style="display:none">
        <input class="form-check-input" type="checkbox" name="is_retirement" id="isRetirement"
               <?= $form['is_retirement'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="isRetirement">
          <i class="bi bi-piggy-bank-fill text-success"></i> Retirement Account
        </label>
      </div>
      <div class="form-check mb-2" id="excludeNWToggle" style="display:none">
        <input class="form-check-input" type="checkbox" name="exclude_from_net_worth" id="excludeFromNW">
        <label class="form-check-label" for="excludeFromNW">
          Exclude from Net Worth
        </label>
      </div>
    </div>

    <div class="col-12">
      <label class="form-label">Comment / Notes</label>
      <textarea name="comment" class="form-control" rows="2"><?= h($form['comment']) ?></textarea>
    </div>
  </div>

  <div class="form-actions mt-4">
    <button type="submit" class="btn btn-primary" id="submitBtn">
      <i class="bi bi-check-circle"></i> Create Account
    </button>
    <a href="<?= BASE_PATH ?>/accounts/index" class="btn btn-outline-secondary ms-2">Cancel</a>
  </div>
</form>
</div><!-- /sectionManual -->

<script>
function onTypeChange() {
  const type     = document.getElementById('acctType').value;
  const isInvest = type === 'Investment' || type === 'Crypto';
  const isAsset  = type === 'Asset';
  const isLoan   = type === 'Loan';

  document.getElementById('investmentNotice').style.display  = type === 'Investment' ? '' : 'none';
  document.getElementById('retirementToggle').style.display  = isInvest && type !== 'Crypto' ? '' : 'none';
  document.getElementById('excludeNWToggle').style.display   = isAsset  ? '' : 'none';
  document.getElementById('openingBalanceHint').style.display = (isInvest || isLoan) ? 'none' : '';
  document.getElementById('openingBalanceLabel').textContent  = isInvest ? 'Investment Opening Value' : 'Opening Balance';
  document.getElementById('openingBalanceGroup').style.display = isLoan ? 'none' : '';
  document.getElementById('minBalanceGroup').style.display    = isLoan ? 'none' : '';
  document.getElementById('loanFields').style.display         = isLoan ? '' : 'none';

  document.getElementById('acctInstitution').disabled   = isAsset;
  document.getElementById('acctAccountNumber').disabled = isAsset;
  document.getElementById('acctRoutingNumber').disabled = isAsset;

  ['loanOriginal','loanRate','loanTerm','loanStartDate'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.required = isLoan;
  });

  if (type === 'Investment') {
    document.getElementById('createCash').checked = true;
  }
  onCreateCashChange();
  updateNoticeNames();
}

function onCreateCashChange() {
  const type      = document.getElementById('acctType').value;
  const isInvest  = type === 'Investment' || type === 'Crypto';
  const withCash  = type === 'Investment' && document.getElementById('createCash').checked;

  document.getElementById('cashOpeningBalanceGroup').style.display = withCash ? '' : 'none';
  document.getElementById('noticeTextWith').style.display    = withCash ? '' : 'none';
  document.getElementById('noticeTextWithout').style.display = withCash ? 'none' : '';
  document.getElementById('submitBtn').textContent = withCash ? '  Create Both Accounts' : '  Create Account';
}

function calcPayment() {
  const P = parseFloat(document.getElementById('loanOriginal').value) || 0;
  const r = (parseFloat(document.getElementById('loanRate').value) || 0) / 100 / 12;
  const n = parseInt(document.getElementById('loanTerm').value)  || 0;
  const termYearsEl = document.getElementById('termYears');
  if (n > 0) termYearsEl.textContent = (n / 12).toFixed(1) + ' years';
  else termYearsEl.textContent = '';
  if (P > 0 && n > 0) {
    const pmt = r > 0 ? (P * r / (1 - Math.pow(1 + r, -n))) : (P / n);
    const el = document.getElementById('loanPayment');
    if (!el.dataset.manuallyEdited) el.value = pmt.toFixed(2);
  }
}

// Don't overwrite payment field if user manually typed in it
document.addEventListener('DOMContentLoaded', () => {
  const pmtEl = document.getElementById('loanPayment');
  if (pmtEl) {
    pmtEl.addEventListener('input', () => { pmtEl.dataset.manuallyEdited = '1'; });
  }
});

function updateNoticeNames() {
  const name = document.getElementById('acctName').value.trim() || 'Investment';
  document.getElementById('noticeInvestName').textContent     = '"' + name + '"';
  document.getElementById('noticeCashName').textContent       = '"' + name + ' Cash"';
  document.getElementById('noticeInvestNameOnly').textContent = '"' + name + '"';
}

document.getElementById('acctName').addEventListener('input', updateNoticeNames);
document.getElementById('acctType').addEventListener('change', onTypeChange);

// Run on page load in case form was re-submitted with Asset/Investment selected
onTypeChange();

function toggleCreateMethod() {
  if (document.getElementById('methodImport').checked) {
    window.location.href = '<?= BASE_PATH ?>/import/index?mode=new';
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
