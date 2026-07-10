<?php
/**
 * Steward CSV fast-import path.
 * Accepts Steward-native CSV from the Statement Converter and routes directly to preview,
 * bypassing the interactive column-mapping step (map_csv.php).
 *
 * Step 1  (POST step=upload)   — parse file, show account matching form
 * Step 2  (POST step=confirm)  — convert rows, build import session, redirect to preview
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/import_helpers.php';

// Validate pickup_token from URL before auth check so we can preserve it across login
$pickupToken = preg_replace('/[^a-f0-9]/i', '', $_GET['pickup_token'] ?? '');
if (strlen($pickupToken) !== 32) $pickupToken = '';

// If the user is not logged in and a pickup_token is present, redirect to login
// with a ?next= parameter so they land back here after authenticating
if ($pickupToken !== '' && !isLoggedIn()) {
    $nextUrl = BASE_PATH . '/import/fast_import?pickup_token=' . $pickupToken;
    header('Location: ' . BASE_PATH . '/login?next=' . urlencode($nextUrl));
    exit;
}

requireLogin();
if (!canImport()) {
    http_response_code(403);
    setFlash('error', 'Access denied.');
    header('Location: ' . BASE_PATH . '/index');
    exit;
}

$pageTitle           = 'Fast Import — Steward CSV / Broker CSV';
$currentPage         = 'import';
$error               = '';
$ctx                 = null;   // parsed CSV context shown in step-1 form
$autoConvertedLabel  = '';     // set when a broker CSV was auto-detected and converted

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Parse a Steward-native CSV and return context array.
 * @throws RuntimeException on format mismatch
 */
function parseStewardCsv(string $content): array
{
    $lines = preg_split('/\r?\n|\r/', trim($content));
    $lines = array_values(array_filter($lines, static fn($l) => trim($l) !== ''));

    if (count($lines) < 2) {
        throw new RuntimeException('File must have a header row and at least one data row.');
    }

    $headers = array_map('trim', str_getcsv(array_shift($lines)));

    // Detect statement type: positions have as_of_date + market_value
    $isPositions = in_array('as_of_date', $headers, true) && in_array('market_value', $headers, true);
    $required    = $isPositions
        ? ['account', 'as_of_date', 'symbol', 'quantity']
        : ['account', 'date', 'action_type', 'amount'];

    foreach ($required as $req) {
        if (!in_array($req, $headers, true)) {
            throw new RuntimeException(
                'Missing column "' . $req . '". ' .
                'Upload a Steward CSV exported from the Statement Converter ' .
                'using the "Download Steward History CSV" button.'
            );
        }
    }

    $rows = [];
    foreach ($lines as $i => $line) {
        if (trim($line) === '') continue;
        $cols = str_getcsv($line);
        while (count($cols) < count($headers)) $cols[] = '';
        $row = array_combine($headers, array_map('trim', array_slice($cols, 0, count($headers))));
        $row['_source_row'] = $i + 1;
        $rows[] = $row;
    }

    // Count rows per unique account value
    $sourceAccounts = [];
    foreach ($rows as $row) {
        $ma = $row['account'] ?? '';
        $sourceAccounts[$ma] = ($sourceAccounts[$ma] ?? 0) + 1;
    }

    return [
        'statement_type'   => $isPositions ? 'positions' : 'transactions',
        'rows'             => $rows,
        'source_accounts'  => $sourceAccounts,  // [account_value => row_count]
    ];
}

/**
 * Try to auto-resolve an account string to an account row.
 * Tries: numeric ID → exact name → trailing-digit suffix.
 */
function resolveSourceAccount(string $sourceAccount, array $accounts): ?array
{
    if ($sourceAccount === '') return null;

    if (ctype_digit($sourceAccount)) {
        $id = (int)$sourceAccount;
        foreach ($accounts as $a) {
            if ((int)$a['id'] === $id) return $a;
        }
    }

    $lower = strtolower($sourceAccount);
    foreach ($accounts as $a) {
        if (strtolower($a['name']) === $lower) return $a;
    }

    if (preg_match('/(\d{4,})$/', $sourceAccount, $m)) {
        foreach ($accounts as $a) {
            if (str_ends_with($a['name'], $m[1])
                || str_ends_with((string)($a['institution'] ?? ''), $m[1])) {
                return $a;
            }
        }
    }

    return null;
}

/**
 * Convert a raw Steward CSV transaction row to the import-pipeline row format.
 */
function convertCsvTxRow(array $csvRow, bool $isInv, int $srcRow): array
{
    $actionType = qifCanonicalAction($csvRow['action_type'] ?? '');
    $date       = $csvRow['date'] ?? '';

    if ($isInv) {
        $symbol = strtoupper(preg_replace('/[^A-Z0-9.]/i', '', $csvRow['symbol'] ?? ''));
        $cusip  = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $csvRow['cusip']  ?? ''));
        $payee  = $csvRow['description'] !== '' ? $csvRow['description']
                : ($symbol !== '' ? $symbol : 'Unknown');
        return [
            'date'             => $date,
            'payee'            => $payee,
            'symbol'           => $symbol,
            'cusip'            => strlen($cusip) === 9 ? $cusip : '',
            'activity'         => actionTypeToActivity($actionType),
            'action_type'      => $actionType,
            'quantity'         => abs((float)($csvRow['quantity'] ?? 0)),
            'price'            => (float)($csvRow['price'] ?? 0),
            'commission'       => abs((float)($csvRow['fees'] ?? 0)),
            'amount'           => (float)($csvRow['amount'] ?? 0),
            'memo'             => $csvRow['memo'] ?? '',
            'fitid'            => ($csvRow['fitid'] ?? '') !== '' ? $csvRow['fitid'] : null,
            'transfer_account' => null,
            'source_row'       => $srcRow,
        ];
    }

    return [
        'date'       => $date,
        'payee'      => $csvRow['description'] ?? '',
        'amount'     => (float)($csvRow['amount'] ?? 0),
        'memo'       => $csvRow['memo'] ?? '',
        'num'        => '',
        'cleared'    => '',
        'category'   => $csvRow['category'] ?? '',
        'source_row' => $srcRow,
    ];
}

/**
 * Try to auto-detect a broker-native CSV (Fidelity, Merrill) and convert it
 * to Steward-native format so it can be parsed by parseStewardCsv() without manual column mapping.
 * Returns ['csv' => string, 'label' => string] on success, or null if unrecognized.
 */
function tryAutoConvertBrokerCsv(string $content): ?array
{
    $src = __DIR__ . '/../converter/src/';
    require_once $src . 'CsvReader.php';
    require_once $src . 'BrokerDetector.php';
    require_once $src . 'ValueCleaner.php';
    require_once $src . 'ActionTypeMapper.php';
    require_once $src . 'parsers/MerrillHoldingsParser.php';
    require_once $src . 'parsers/MerrillHistoryParser.php';
    require_once $src . 'parsers/FidelityHistoryParser.php';
    require_once $src . 'parsers/FidelityHoldingsParser.php';
    require_once $src . 'StewardCsvExporter.php';

    $rows      = CsvReader::parseContent($content);
    $detection = BrokerDetector::detect($rows);
    $broker    = $detection['broker'];
    $type      = $detection['type'];

    if ($broker === 'unknown' || $type === 'unknown') {
        return null;
    }

    $parsed = match (true) {
        $broker === 'fidelity' && $type === 'holdings' => (new FidelityHoldingsParser())->parse($rows),
        $broker === 'fidelity' && $type === 'history'  => (new FidelityHistoryParser())->parse($rows),
        $broker === 'merrill'  && $type === 'holdings' => (new MerrillHoldingsParser())->parse($rows),
        $broker === 'merrill'  && $type === 'history'  => (new MerrillHistoryParser())->parse($rows),
        default => null,
    };

    if ($parsed === null) {
        return null;
    }

    $brokerLabels = ['fidelity' => 'Fidelity', 'merrill' => 'Merrill Edge'];
    $typeLabels   = ['history' => 'Transaction History', 'holdings' => 'Holdings'];
    $label = ($brokerLabels[$broker] ?? $broker) . ' ' . ($typeLabels[$type] ?? $type);

    if ($type === 'history') {
        $txns = $parsed['transactions'] ?? [];
        if (empty($txns)) return null;
        return ['csv' => StewardCsvExporter::generateHistoryCsv($txns), 'label' => $label];
    }

    $date  = $parsed['date'] ?? date('Y-m-d');
    $holds = $parsed['holdings'] ?? [];
    if (empty($holds)) return null;
    return ['csv' => StewardCsvExporter::generate($date, $holds), 'label' => $label];
}

// ── Request handling ──────────────────────────────────────────────────────────

$step = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $step = trim($_POST['step'] ?? '');
}

// ── Converter pickup: load Steward CSV written by the Statement Converter ──────────
// Triggered by GET ?pickup_token=... (from the "Send to Steward" button)
if ($pickupToken !== '' && $step === '') {
    $converterTmpDir = realpath(__DIR__ . '/../converter/storage/tmp');
    $pickupFile      = $converterTmpDir !== false
        ? $converterTmpDir . '/' . $pickupToken . '.csv'
        : '';

    // Verify the resolved path stays inside the expected directory (no traversal)
    $realFile = $pickupFile !== '' ? realpath($pickupFile) : false;
    if ($converterTmpDir !== false && $realFile !== false
        && str_starts_with($realFile, $converterTmpDir . DIRECTORY_SEPARATOR)) {
        try {
            $pickupContent = file_get_contents($realFile);
            unlink($realFile); // consume immediately
            if ($pickupContent !== false && trim($pickupContent) !== '') {
                $ctx = parseStewardCsv($pickupContent);
                $_SESSION['fast_import_ctx'] = $ctx;
            } else {
                $error = 'The handoff file was empty. Please try Send to Steward again.';
            }
        } catch (Throwable $e) {
            $error = 'Could not load file from converter: ' . $e->getMessage();
        }
    } else {
        $error = 'The pickup token is invalid or has expired. Please click "Send to Steward" again.';
    }
}

try {
    // ── Step 1: parse uploaded file ───────────────────────────────────────────
    if ($step === 'upload') {

        $fileError = $_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($fileError !== UPLOAD_ERR_OK) {
            $errCodes = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server size limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was selected.',
            ];
            throw new RuntimeException($errCodes[$fileError] ?? 'Upload failed (error ' . $fileError . ').');
        }

        $content = file_get_contents($_FILES['import_file']['tmp_name']);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Uploaded file is empty or unreadable.');
        }

        // Try Steward-native format first; fall back to broker auto-conversion
        try {
            $ctx = parseStewardCsv($content);
        } catch (RuntimeException $parseErr) {
            $autoResult = tryAutoConvertBrokerCsv($content);
            if ($autoResult !== null) {
                $ctx = parseStewardCsv($autoResult['csv']);
                $autoConvertedLabel = $autoResult['label'];
            } else {
                throw $parseErr;
            }
        }
        $_SESSION['fast_import_ctx'] = $ctx;

    // ── Step 2: confirm account assignments, build import session ─────────────
    } elseif ($step === 'confirm') {

        if (empty($_SESSION['fast_import_ctx'])) {
            throw new RuntimeException('Session expired — please upload the file again.');
        }

        $ctx         = $_SESSION['fast_import_ctx'];
        $isPositions = ($ctx['statement_type'] === 'positions');
        $allCsvRows  = $ctx['rows'];
        $assigned    = $_POST['accounts'] ?? [];   // [form_key => account_id]

        $allAccts = getAccounts();
        $acctById = [];
        foreach ($allAccts as $a) $acctById[(int)$a['id']] = $a;

        // Group CSV rows by account value
        $groups = [];
        foreach ($allCsvRows as $csvRow) {
            $ma            = $csvRow['account'] ?? '';
            $groups[$ma][] = $csvRow;
        }

        $isMulti    = count($groups) > 1;
        $importRows = [];
        $primary    = null;

        foreach ($groups as $ma => $csvGroup) {
            // Form key: use '__single' when account is '' and there is only one group
            $formKey   = (!$isMulti && $ma === '') ? '__single' : $ma;
            $accountId = (int)($assigned[$formKey] ?? 0);

            if ($accountId <= 0) {
                $label = $ma !== '' ? '"' . $ma . '"' : 'all rows';
                throw new RuntimeException('Please select a Steward account for ' . $label . '.');
            }

            $account = $acctById[$accountId] ?? null;
            if (!$account) {
                throw new RuntimeException('Selected account (ID ' . $accountId . ') not found.');
            }

            $isInv = ($account['type'] === 'Investment');

            if ($primary === null) {
                $primary = [
                    'account_id'   => $accountId,
                    'account_name' => $account['name'],
                    'account_type' => $account['type'],
                    'is_investment' => $isInv,
                ];
            }

            if ($isPositions) {
                $snapshot = [];
                $asOfDate = date('Y-m-d');

                foreach ($csvGroup as $csvRow) {
                    if ($csvRow['as_of_date'] !== '') {
                        $asOfDate = $csvRow['as_of_date'];
                    }
                    $symbol  = strtoupper(preg_replace('/[^A-Z0-9.]/i', '', $csvRow['symbol'] ?? ''));
                    $name    = $csvRow['security_name'] ?? '';
                    $qty     = (float)($csvRow['quantity'] ?? 0);
                    $price   = (float)($csvRow['price']    ?? 0);
                    $mktVal  = (float)($csvRow['market_value'] ?? 0);
                    // Derive effective price from market value when available
                    if ($mktVal > 0.0 && $qty > 0.0) {
                        $price = $mktVal / $qty;
                    }
                    if ($qty <= 0.0 || $name === '') continue;
                    $symNorm    = strtolower(preg_replace('/[^A-Z0-9]/i', '', $symbol));
                    $key        = $symNorm !== '' ? $symNorm : strtolower($name);
                    $snapshot[$key] = ['qty' => $qty, 'name' => $name, 'symbol' => $symbol, 'price' => $price, 'date' => $asOfDate];
                }

                if (empty($snapshot)) {
                    $label = $ma !== '' ? '"' . $ma . '"' : 'this file';
                    throw new RuntimeException('No valid position rows found for ' . $label . '.');
                }

                $current    = getAccountHoldings($accountId);
                $reconciled = reconcileHoldings($snapshot, $current, $asOfDate);

                foreach ($reconciled as &$row) {
                    $row['source_row'] = count($importRows) + 1;
                    if ($isMulti) {
                        $row['account_id']    = $accountId;
                        $row['is_investment'] = true;
                        $row['account_name']  = $account['name'];
                    }
                }
                unset($row);

                assignFitids($reconciled);
                $importRows = array_merge($importRows, $reconciled);

            } else {
                $groupRows = [];
                foreach ($csvGroup as $csvRow) {
                    $converted = convertCsvTxRow($csvRow, $isInv, (int)($csvRow['_source_row'] ?? 0));
                    if ($converted['date'] === '') continue;
                    if (!$isInv && $converted['payee'] === '') continue;
                    if (!$isInv && (float)$converted['amount'] == 0.0) continue;
                    if ($isMulti) {
                        $converted['account_id']    = $accountId;
                        $converted['is_investment'] = $isInv;
                        $converted['account_name']  = $account['name'];
                    }
                    $groupRows[] = $converted;
                }
                assignFitids($groupRows);
                markDuplicates($groupRows, $accountId, $isInv);
                $importRows = array_merge($importRows, $groupRows);
            }
        }

        if (empty($importRows)) {
            throw new RuntimeException('No valid rows could be imported from this file.');
        }

        // Build category map for single-account banking imports (mirrors QIF path)
        $catMap = [];
        if (!$isPositions && !$primary['is_investment'] && !$isMulti) {
            $catMap = buildCategoryMap($importRows);
        }

        $_SESSION['import'] = [
            'account_id'       => $primary['account_id'],
            'account_name'     => $isMulti ? 'Multi-account import' : $primary['account_name'],
            'account_type'     => $primary['account_type'],
            'is_investment'    => $primary['is_investment'],
            'is_multi_account' => $isMulti,
            'statement_type'   => $isPositions ? 'holdings' : 'transaction_history',
            'format'           => 'Steward CSV',
            'rows'             => $importRows,
            'cat_map'          => $catMap,
            'new_account'      => null,
        ];

        unset($_SESSION['fast_import_ctx']);
        header('Location: ' . BASE_PATH . '/import/preview');
        exit;

    }

} catch (Throwable $e) {
    $error = $e->getMessage();
    // Restore context from session so user can retry confirmation without re-uploading
    if ($step === 'confirm' && $ctx === null) {
        $ctx = $_SESSION['fast_import_ctx'] ?? null;
    }
}

// ── Render ────────────────────────────────────────────────────────────────────

// Pre-compute resolved account suggestions for the account matching form
$importableAccts = [];
$resolved        = [];
if ($ctx !== null) {
    $allAccts        = getAccounts();
    $importableAccts = array_values(array_filter(
        $allAccts,
        static fn($a) => in_array($a['type'], ['Checking', 'Savings', 'Credit Card', 'Investment'])
            && !$a['is_investment_cash']
    ));
    foreach ($ctx['source_accounts'] as $ma => $_) {
        $resolved[$ma] = resolveSourceAccount($ma, $importableAccts);
    }
}

$stmtTypeLabel = match ($ctx['statement_type'] ?? '') {
    'positions'    => 'Positions / Holdings',
    'transactions' => 'Transaction History',
    default        => '',
};

$isSingle      = $ctx !== null && count($ctx['source_accounts']) <= 1;
$singleMa      = $isSingle ? (string)array_key_first($ctx['source_accounts'] ?? []) : '';
$singleFormKey = ($singleMa === '') ? '__single' : $singleMa;

include __DIR__ . '/../includes/header.php';
?>
<div class="page-content">

  <div class="page-header d-flex align-items-center gap-3 mb-4">
    <div>
      <h1 class="mb-0"><i class="bi bi-lightning-charge"></i> Fast Import</h1>
      <p class="text-muted mb-0 mt-1">
        Upload a Steward CSV or a broker CSV (Fidelity, Merrill Edge). Column mapping is skipped — it goes straight to preview.
      </p>
    </div>
  </div>

  <?php if ($error !== ''): ?>
  <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($ctx === null): ?>
  <!-- ── Upload form (shown on GET or after parse error) ──────────────────── -->
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><strong>Upload CSV</strong></div>
        <div class="card-body">
          <form method="post" action="<?= BASE_PATH ?>/import/fast_import" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="upload">
            <div class="mb-4">
              <label class="form-label fw-semibold">File</label>
              <input type="file" name="import_file" class="form-control" accept=".csv" required>
              <div class="form-text">
                Accepts a <strong>Steward CSV</strong> (from the Statement Converter)
                or a <strong>broker CSV</strong> directly (Fidelity or Merrill Edge — holdings or transaction history).
              </div>
            </div>
            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-upload"></i> Upload
              </button>
              <a href="<?= BASE_PATH ?>/import/index" class="btn btn-outline-secondary">Back</a>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><strong>How it works</strong></div>
        <div class="card-body">
          <p class="small fw-semibold mb-1">Option A — Upload a broker CSV directly</p>
          <ol class="mb-3 ps-3 small">
            <li class="mb-1">Download your transaction history or holdings CSV from Fidelity or Merrill Edge.</li>
            <li>Upload it here — it's auto-detected and converted.</li>
          </ol>
          <p class="small fw-semibold mb-1">Option B — Use the Statement Converter first</p>
          <ol class="mb-0 ps-3 small">
            <li class="mb-1">Open the <strong>Statement Converter</strong>, upload the broker CSV, review, then click <strong>Download Steward CSV</strong>.</li>
            <li>Upload that Steward CSV here.</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <?php else: ?>
  <!-- ── Account matching form (shown after successful parse) ─────────────── -->
  <?php if ($autoConvertedLabel !== ''): ?>
  <div class="alert alert-success mb-3">
    <i class="bi bi-check-circle-fill me-2"></i>
    <strong><?= h($autoConvertedLabel) ?></strong> file detected and converted automatically.
  </div>
  <?php endif; ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
          <strong>Assign Accounts</strong>
          <span class="badge bg-secondary"><?= h($stmtTypeLabel) ?> · <?= count($ctx['rows']) ?> rows</span>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3">
            Match each source identifier from the file to a Steward account.
            <?php if (count($ctx['source_accounts']) > 1): ?>
            All accounts will be imported together in a single preview.
            <?php endif; ?>
          </p>

          <form method="post" action="<?= BASE_PATH ?>/import/fast_import">
            <?= csrfField() ?>
            <input type="hidden" name="step" value="confirm">

            <?php if ($isSingle): ?>
            <!-- Single source — show one account selector -->
            <div class="mb-4">
              <label class="form-label fw-semibold">
                Steward Account
                <?php if ($singleMa !== ''): ?>
                <span class="text-muted fw-normal">— source: <code><?= h($singleMa) ?></code> (<?= $ctx['source_accounts'][$singleMa] ?> rows)</span>
                <?php else: ?>
                <span class="text-muted fw-normal">(<?= array_sum($ctx['source_accounts']) ?> rows)</span>
                <?php endif; ?>
              </label>
              <select name="accounts[<?= h($singleFormKey) ?>]" class="form-select" required>
                <option value="">— Select account —</option>
                <?php
                $grouped = [];
                foreach ($importableAccts as $a) $grouped[$a['type']][] = $a;
                foreach ($grouped as $type => $accs):
                ?>
                <optgroup label="<?= h($type) ?>">
                  <?php foreach ($accs as $a):
                    $autoSel = ($resolved[$singleMa] !== null && (int)$resolved[$singleMa]['id'] === (int)$a['id']) ? ' selected' : '';
                  ?>
                  <option value="<?= $a['id'] ?>"<?= $autoSel ?>><?= h($a['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
              </select>
              <?php if ($resolved[$singleMa] !== null): ?>
              <div class="form-text text-success">
                <i class="bi bi-check-circle-fill"></i>
                Auto-matched to <strong><?= h($resolved[$singleMa]['name']) ?></strong> — confirm or change.
              </div>
              <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- Multiple sources — show table -->
            <table class="table table-sm table-bordered mb-4">
              <thead class="table-light">
                <tr>
                  <th>Source Account</th>
                  <th>Rows</th>
                  <th>Steward Account</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ctx['source_accounts'] as $ma => $rowCount):
                    $autoResolved = $resolved[$ma] ?? null;
                ?>
                <tr>
                  <td><code><?= h($ma !== '' ? $ma : '(none)') ?></code></td>
                  <td class="text-muted"><?= $rowCount ?></td>
                  <td>
                    <select name="accounts[<?= h($ma) ?>]" class="form-select form-select-sm" required>
                      <option value="">— Select account —</option>
                      <?php
                      $grouped = [];
                      foreach ($importableAccts as $a) $grouped[$a['type']][] = $a;
                      foreach ($grouped as $type => $accs):
                      ?>
                      <optgroup label="<?= h($type) ?>">
                        <?php foreach ($accs as $a):
                          $autoSel = ($autoResolved !== null && (int)$autoResolved['id'] === (int)$a['id']) ? ' selected' : '';
                        ?>
                        <option value="<?= $a['id'] ?>"<?= $autoSel ?>><?= h($a['name']) ?></option>
                        <?php endforeach; ?>
                      </optgroup>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($autoResolved !== null): ?>
                    <div class="form-text text-success mt-1">
                      <i class="bi bi-check-circle-fill"></i> Auto-matched to <strong><?= h($autoResolved['name']) ?></strong>
                    </div>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>

            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-arrow-right-circle"></i> Continue to Preview
              </button>
              <a href="<?= BASE_PATH ?>/import/fast_import" class="btn btn-outline-secondary">
                <i class="bi bi-upload"></i> Upload Different File
              </a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><strong>File Summary</strong></div>
        <div class="card-body">
          <dl class="row mb-0 small">
            <dt class="col-sm-5">Type</dt>
            <dd class="col-sm-7"><?= h($stmtTypeLabel) ?></dd>
            <dt class="col-sm-5">Total Rows</dt>
            <dd class="col-sm-7"><?= array_sum($ctx['source_accounts']) ?></dd>
            <dt class="col-sm-5">Accounts</dt>
            <dd class="col-sm-7"><?= count($ctx['source_accounts']) ?></dd>
          </dl>
          <?php if (count($ctx['source_accounts']) > 1): ?>
          <hr class="my-2">
          <p class="small text-muted mb-0">Multiple account identifiers were detected in the <code>account</code> column. Assign each to a Steward account above.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
