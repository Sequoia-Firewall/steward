<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/import_helpers.php';
requireLogin();
if (!canImport()) { http_response_code(403); setFlash("error", "Access denied."); header("Location: " . BASE_PATH . "/index"); exit; }

if (empty($_SESSION['import_qif_multi'])) {
    setFlash('error', 'No multi-account import data. Please upload a file first.');
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

$qifData     = $_SESSION['import_qif_multi'];
$qifAccounts = $qifData['accounts'];         // key → [name, qif_type, is_investment]
$rowsByAcct  = $qifData['rows_by_account'];  // key → [rows]

$db         = getDB();
$dbAccounts = $db->query(
    'SELECT id, name, type, account_number
     FROM accounts
     WHERE is_active = 1 AND is_investment_cash = 0
     ORDER BY type, name'
)->fetchAll(PDO::FETCH_ASSOC);

$dbById = [];
foreach ($dbAccounts as $a) { $dbById[(int)$a['id']] = $a; }

function autoMatchAccount(string $qifName, array $dbAccounts): int {
    $qifLower = strtolower($qifName);
    foreach ($dbAccounts as $a) {
        if (strtolower($a['name']) === $qifLower) return (int)$a['id'];
    }
    foreach ($dbAccounts as $a) {
        $dbLower = strtolower($a['name']);
        if (str_contains($dbLower, $qifLower) || str_contains($qifLower, $dbLower)) return (int)$a['id'];
    }
    return 0;
}

// Map QIF account type code to the closest MM account type for the create-new pre-fill
function qifTypeToAccountType(string $qifType): string {
    $lower = strtolower($qifType);
    if ($lower === 'ccard') return 'Credit Card';
    if (in_array($lower, ['invst', 'port', '401(k)', 'ira', 'roth'])) return 'Investment';
    if ($lower === 'oth l') return 'Credit Card';
    return 'Checking';
}

$autoMatches = [];
foreach ($qifAccounts as $key => $qa) {
    $autoMatches[$key] = autoMatchAccount($qa['name'], $dbAccounts);
}

$error    = '';
$warnings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $mappings    = $_POST['mapping']           ?? [];
    $createNames = $_POST['create_name']       ?? [];
    $createTypes = $_POST['create_type']       ?? [];
    $createInsts = $_POST['create_institution'] ?? [];

    $allRows        = [];
    $bankingRows    = [];
    $anyMapped      = false;
    $nameAlias      = [];  // strtolower(qif_name) → actual db_name for all mapped accounts
    $newlyCreatedId = [];  // qifKey → new account_id

    // ── Phase 1: Create new accounts (must run before row processing) ─────────
    foreach ($qifAccounts as $qifKey => $qa) {
        if (trim($mappings[$qifKey] ?? 'skip') !== 'create') continue;

        $createName = trim($createNames[$qifKey] ?? $qa['name']);
        $createType = $createTypes[$qifKey] ?? 'Checking';
        $createInst = trim($createInsts[$qifKey] ?? '');

        if ($createName === '') {
            $warnings[] = 'Account name is required for "' . $qa['name'] . '" — skipped.';
            continue;
        }
        if (!in_array($createType, ['Checking', 'Savings', 'Credit Card', 'Investment'])) {
            $createType = 'Checking';
        }

        $db->prepare(
            'INSERT INTO accounts (name, type, institution, currency, opening_balance, is_active, created_by)
             VALUES (?, ?, ?, \'USD\', 0, 1, ?)'
        )->execute([$createName, $createType, $createInst, currentUserId()]);
        $newId = (int)$db->lastInsertId();

        if ($createType === 'Investment') {
            $cashName = $createName . ' Cash';
            $db->prepare(
                'INSERT INTO accounts (name, type, institution, currency, opening_balance,
                                      is_investment_cash, linked_account_id, is_active, created_by)
                 VALUES (?, \'investment-cash\', ?, \'USD\', 0, 1, ?, 1, ?)'
            )->execute([$cashName, $createInst, $newId, currentUserId()]);
            $cashId = (int)$db->lastInsertId();
            $db->prepare('UPDATE accounts SET linked_account_id = ? WHERE id = ?')->execute([$cashId, $newId]);
        }

        $dbById[$newId] = ['id' => $newId, 'name' => $createName, 'type' => $createType, 'account_number' => ''];
        $newlyCreatedId[$qifKey] = $newId;
        $nameAlias[strtolower($qa['name'])] = $createName;
    }

    // ── Phase 2: Process rows for all mapped accounts ─────────────────────────
    foreach ($qifAccounts as $qifKey => $qa) {
        $mappedVal = trim($mappings[$qifKey] ?? 'skip');
        if ($mappedVal === 'skip' || $mappedVal === '') continue;

        $mappedId = ($mappedVal === 'create') ? ($newlyCreatedId[$qifKey] ?? 0) : (int)$mappedVal;
        if ($mappedId <= 0) {
            if ($mappedVal === 'create') {
                $warnings[] = '"' . $qa['name'] . '" could not be created — skipped.';
            }
            continue;
        }

        $dbAcct = $dbById[$mappedId] ?? null;
        if (!$dbAcct) { $warnings[] = 'Unknown account for "' . $qa['name'] . '" — skipped.'; continue; }

        $isInv = ($dbAcct['type'] === 'Investment');

        // For existing investment accounts, require a linked cash account
        if ($isInv && $mappedVal !== 'create') {
            $lcCheck = $db->prepare('SELECT linked_account_id FROM accounts WHERE id = ? AND is_active = 1');
            $lcCheck->execute([$mappedId]);
            if (!(int)($lcCheck->fetchColumn() ?: 0)) {
                $warnings[] = '"' . $dbAcct['name'] . '" has no linked cash account and was skipped. Configure one in Account Settings first.';
                continue;
            }
        }

        $accountRows = $rowsByAcct[$qifKey] ?? [];
        if (empty($accountRows)) { $warnings[] = '"' . $qa['name'] . '" had no parseable transactions — skipped.'; continue; }

        // Record alias for existing accounts so transfer_account resolution works across the import
        if ($mappedVal !== 'create') {
            $nameAlias[strtolower($qa['name'])] = $dbAcct['name'];
        }

        foreach ($accountRows as $i => &$row) {
            $row['source_row']    = $i + 1;
            $row['account_id']    = $mappedId;
            $row['account_name']  = $dbAcct['name'];
            $row['is_investment'] = $isInv;
            $row['account_type']  = $dbAcct['type'];
        }
        unset($row);

        assignFitids($accountRows);
        markDuplicates($accountRows, $mappedId, $isInv);

        $allRows = array_merge($allRows, $accountRows);
        if (!$isInv) $bankingRows = array_merge($bankingRows, $accountRows);
        $anyMapped = true;
    }

    if (!$anyMapped || empty($allRows)) {
        $error = 'No accounts were mapped, or all mapped accounts had no transactions. Map at least one account to continue.';
    } else {
        // Rewrite transfer_account in all rows using the alias map so confirm.php resolves by DB name
        if (!empty($nameAlias)) {
            foreach ($allRows as &$row) {
                $ta = $row['transfer_account'] ?? null;
                if ($ta !== null && $ta !== '') {
                    $resolved = $nameAlias[strtolower(trim($ta))] ?? null;
                    if ($resolved !== null) $row['transfer_account'] = $resolved;
                }
            }
            unset($row);
        }

        $catMap  = buildCategoryMap($bankingRows);
        $allRows = array_values($allRows);

        $_SESSION['import'] = [
            'account_id'       => 0,
            'account_name'     => 'Multi-account import',
            'account_type'     => 'mixed',
            'is_investment'    => false,
            'is_multi_account' => true,
            'statement_type'   => 'transaction_history',
            'format'           => 'QIF',
            'rows'             => $allRows,
            'cat_map'          => $catMap,
            'new_account'      => null,
        ];
        unset($_SESSION['import_qif_multi']);

        header('Location: ' . BASE_PATH . '/import/preview');
        exit;
    }
}

$dbByType = [];
foreach ($dbAccounts as $a) {
    $dbByType[$a['type']][] = $a;
}

$pageTitle   = 'Map Accounts — Multi-Account Import';
$currentPage = 'import';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-content">

  <div class="page-header d-flex align-items-center gap-3 mb-4">
    <div>
      <h1 class="mb-0"><i class="bi bi-diagram-3"></i> Map Accounts</h1>
      <p class="text-muted mb-0 mt-1">
        This QIF file contains <strong><?= count($qifAccounts) ?></strong> accounts with transactions.
        Map each to a local account, create a new one, or skip it.
      </p>
    </div>
  </div>

  <?php foreach ($warnings as $w): ?>
  <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h($w) ?></div>
  <?php endforeach; ?>
  <?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" action="">
    <?= csrfField() ?>

    <div class="card mb-3">
      <div class="card-header"><strong>Account Mapping</strong></div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>QIF Account Name</th>
              <th>Type in File</th>
              <th class="text-end">Rows</th>
              <th style="min-width:300px">Map to Local Account</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($qifAccounts as $key => $qa):
                $rowCount    = count($rowsByAcct[$key] ?? []);
                $suggested   = $autoMatches[$key] ?? 0;
                $prefillType = qifTypeToAccountType($qa['qif_type'] ?? '');
            ?>
            <tr>
              <td class="fw-semibold"><?= h($qa['name']) ?></td>
              <td>
                <span class="badge bg-secondary"><?= h($qa['qif_type'] ?: '—') ?></span>
                <?php if ($qa['is_investment']): ?>
                <i class="bi bi-graph-up text-success ms-1" title="Investment"></i>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= $rowCount ?></td>
              <td>
                <select name="mapping[<?= h($key) ?>]" class="form-select form-select-sm js-acct-map">
                  <option value="skip"<?= $suggested === 0 ? ' selected' : '' ?>>— Skip this account —</option>
                  <option value="create">— Create new account —</option>
                  <?php foreach ($dbByType as $typeName => $typeAccts): ?>
                  <optgroup label="<?= h($typeName) ?>">
                    <?php foreach ($typeAccts as $dba): ?>
                    <option value="<?= $dba['id'] ?>"<?= (int)$dba['id'] === $suggested ? ' selected' : '' ?>>
                      <?= h($dba['name']) ?><?php if ($dba['account_number']): ?> (<?= h($dba['account_number']) ?>)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                  </optgroup>
                  <?php endforeach; ?>
                </select>

                <!-- Inline create-new fields, shown only when "Create new account" is selected -->
                <div class="create-fields mt-2 d-none border rounded p-2 bg-light">
                  <div class="mb-1">
                    <input type="text"
                           name="create_name[<?= h($key) ?>]"
                           class="form-control form-control-sm"
                           placeholder="Account name"
                           value="<?= h($qa['name']) ?>">
                  </div>
                  <div class="mb-1">
                    <select name="create_type[<?= h($key) ?>]" class="form-select form-select-sm">
                      <option value="Checking"    <?= $prefillType === 'Checking'     ? 'selected' : '' ?>>Checking</option>
                      <option value="Savings"     <?= $prefillType === 'Savings'      ? 'selected' : '' ?>>Savings</option>
                      <option value="Credit Card" <?= $prefillType === 'Credit Card'  ? 'selected' : '' ?>>Credit Card</option>
                      <option value="Investment"  <?= $prefillType === 'Investment'   ? 'selected' : '' ?>>Investment</option>
                    </select>
                  </div>
                  <div>
                    <input type="text"
                           name="create_institution[<?= h($key) ?>]"
                           class="form-control form-control-sm"
                           placeholder="Institution (optional)">
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-arrow-right-circle"></i> Continue to Preview
      </button>
      <a href="<?= BASE_PATH ?>/import/index" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>

</div>

<script>
document.querySelectorAll('.js-acct-map').forEach(sel => {
    sel.addEventListener('change', function () {
        const fields = this.closest('td').querySelector('.create-fields');
        fields.classList.toggle('d-none', this.value !== 'create');
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
