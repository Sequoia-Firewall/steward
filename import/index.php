<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!canImport()) { http_response_code(403); setFlash("error", "Access denied."); header("Location: " . BASE_PATH . "/index"); exit; }

$pageTitle   = 'Import Transactions';
$currentPage = 'import';

$all      = getAccounts();
$accounts = array_values(array_filter($all, fn($a) =>
    in_array($a['type'], ['Checking', 'Savings', 'Credit Card', 'Investment']) && !$a['is_investment_cash']
));

$preSelectNew = ($_GET['mode'] ?? '') === 'new';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-content">

  <div class="page-header d-flex align-items-center gap-3 mb-4">
    <div>
      <h1 class="mb-0"><i class="bi bi-upload"></i> Import Transactions</h1>
      <p class="text-muted mb-0 mt-1">Upload a file exported from your bank or financial software.</p>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Upload File</strong></div>
        <div class="card-body">
          <form method="post" action="<?= BASE_PATH ?>/import/parse" enctype="multipart/form-data" id="importForm">
            <?= csrfField() ?>

            <!-- ── Import mode ───────────────────────────── -->
            <div class="mb-3">
              <label class="form-label fw-semibold">Import Into</label>
              <div class="d-flex gap-4">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="import_mode" id="modeExisting"
                         value="existing" <?= $preSelectNew ? '' : 'checked' ?> onchange="toggleImportMode()">
                  <label class="form-check-label" for="modeExisting">Existing Account</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="import_mode" id="modeNew"
                         value="new" <?= $preSelectNew ? 'checked' : '' ?> onchange="toggleImportMode()">
                  <label class="form-check-label" for="modeNew">Create New Account</label>
                </div>
              </div>
            </div>

            <!-- ── Existing account section ─────────────── -->
            <div id="sectionExisting" class="mb-3">
              <label class="form-label fw-semibold">Account</label>
              <?php if (empty($accounts)): ?>
              <div class="alert alert-warning py-2 mb-0">No importable accounts found. Create one first or use "Create New Account".</div>
              <?php else: ?>
              <select name="account_id" class="form-select" id="accountSelect" onchange="updateStatementType()">
                <option value="">— Select account —</option>
                <?php
                $grouped = [];
                foreach ($accounts as $a) $grouped[$a['type']][] = $a;
                foreach ($grouped as $type => $accs):
                ?>
                <optgroup label="<?= h($type) ?>">
                  <?php foreach ($accs as $a): ?>
                  <option value="<?= $a['id'] ?>" data-type="<?= h($a['type']) ?>"><?= h($a['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </div>

            <!-- ── Statement Type (investment accounts only) ─ -->
            <div id="sectionStatementType" class="mb-3 d-none">
              <label class="form-label fw-semibold">Statement Type</label>
              <div class="d-flex gap-4">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="statement_type" id="stmtTxn"
                         value="transaction_history" checked>
                  <label class="form-check-label" for="stmtTxn">Transaction History</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="statement_type" id="stmtHoldings"
                         value="holdings">
                  <label class="form-check-label" for="stmtHoldings">Positions / Holdings</label>
                </div>
              </div>
              <div class="form-text mt-1" id="stmtNote">
                Requires a linked cash account on this investment account.
              </div>
            </div>

            <!-- ── New account section ──────────────────── -->
            <div id="sectionNew" class="mb-3 d-none">
              <div class="row g-2">
                <div class="col-12">
                  <label class="form-label fw-semibold">Account Name <span class="text-danger">*</span></label>
                  <input type="text" name="new_account_name" class="form-control"
                         placeholder="e.g. Bank of America Checking">
                </div>
                <div class="col-sm-6">
                  <label class="form-label fw-semibold">Account Type <span class="text-danger">*</span></label>
                  <select name="new_account_type" class="form-select" id="newAccountType" onchange="updateStatementType()">
                    <option value="Checking">Checking</option>
                    <option value="Savings">Savings</option>
                    <option value="Credit Card">Credit Card</option>
                    <option value="Investment">Investment</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label fw-semibold">Institution</label>
                  <input type="text" name="new_account_institution" class="form-control"
                         placeholder="e.g. Bank of America">
                </div>
                <div class="col-sm-4">
                  <label class="form-label fw-semibold">Currency</label>
                  <input type="text" name="new_account_currency" class="form-control"
                         value="USD" maxlength="3">
                </div>
              </div>
              <div class="form-text mt-1">
                <i class="bi bi-info-circle"></i>
                If the file contains a "Beginning balance" row, the opening balance will be set automatically.
              </div>
            </div>

            <!-- ── File ─────────────────────────────────── -->
            <div class="mb-4">
              <label class="form-label fw-semibold">File</label>
              <input type="file" name="import_file" class="form-control" accept=".qif,.ofx,.qfx,.csv" required>
              <div class="form-text">Supported formats: QIF, OFX/QFX, CSV</div>
            </div>

            <button type="submit" class="btn btn-primary">
              <i class="bi bi-upload"></i> Upload &amp; Preview
            </button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><strong>Format Notes</strong></div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <tbody>
              <tr>
                <td class="fw-semibold text-nowrap ps-3 py-3">QIF</td>
                <td class="py-3">Quicken Interchange Format — exported from Quicken, Microsoft Money, and most personal finance apps. Supports banking and investment transactions.</td>
              </tr>
              <tr>
                <td class="fw-semibold text-nowrap ps-3 py-3">OFX / QFX</td>
                <td class="py-3">Open Financial Exchange — downloaded directly from banks and brokerages. Best format; includes transaction IDs for accurate duplicate detection.</td>
              </tr>
              <tr class="border-0">
                <td class="fw-semibold text-nowrap ps-3 py-3">CSV</td>
                <td class="py-3">Comma-separated values — exported from banks and spreadsheets. Requires column mapping after upload.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Steward CSV fast-import ────────────────────────────────────── -->
  <div class="row g-4 mt-1">
    <div class="col-12">
      <div class="card border-primary border-opacity-25">
        <div class="card-header bg-primary bg-opacity-10">
          <strong><i class="bi bi-lightning-charge-fill text-primary me-1"></i> Fast Import — Steward CSV</strong>
          <span class="text-muted fw-normal ms-2 small">Skips column mapping — goes straight to preview</span>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3">
            Use this path when you have a <strong>Steward CSV</strong> file exported from the
            <a href="<?= BASE_PATH ?>/converter" class="text-decoration-none">Statement Converter</a>.
            Account matching is automatic when the <code>account</code> column matches a known account name or ID.
          </p>
          <a href="<?= BASE_PATH ?>/import/fast_import" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-lightning-charge"></i> Open Fast Import
          </a>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
function isInvestmentSelected() {
  if (document.getElementById('modeNew').checked) {
    var typeEl = document.getElementById('newAccountType');
    return typeEl && typeEl.value === 'Investment';
  }
  var sel = document.getElementById('accountSelect');
  if (!sel || !sel.value) return false;
  var opt = sel.options[sel.selectedIndex];
  return opt && opt.getAttribute('data-type') === 'Investment';
}

function updateStatementType() {
  var showStmt = isInvestmentSelected();
  var section  = document.getElementById('sectionStatementType');
  var note     = document.getElementById('stmtNote');
  section.classList.toggle('d-none', !showStmt);
  if (!showStmt) {
    document.getElementById('stmtTxn').checked = true;
  }
  if (note) {
    var isHoldings = document.getElementById('stmtHoldings').checked;
    note.textContent = isHoldings
      ? 'Reconciles share quantities only — no cash transactions are generated.'
      : 'Requires a linked cash account on this investment account.';
  }
  document.querySelectorAll('input[name="statement_type"]').forEach(function(r) {
    r.onchange = updateStatementType;
  });
}

function toggleImportMode() {
  var isNew = document.getElementById('modeNew').checked;
  document.getElementById('sectionExisting').classList.toggle('d-none', isNew);
  document.getElementById('sectionNew').classList.toggle('d-none', !isNew);
  var sel = document.getElementById('accountSelect');
  if (sel) sel.required = !isNew;
  updateStatementType();
}

document.addEventListener('DOMContentLoaded', function() {
  toggleImportMode();
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
