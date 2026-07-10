<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/import_helpers.php';
requireLogin();
if (!canImport()) { http_response_code(403); setFlash("error", "Access denied."); header("Location: " . BASE_PATH . "/index"); exit; }

if (empty($_SESSION['import_csv'])) {
    setFlash('error', 'No CSV data found. Please upload a file first.');
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

$csvData    = $_SESSION['import_csv'];
$isInv      = $csvData['is_investment'];
$stmtType   = $csvData['statement_type'] ?? 'transaction_history';
$isHoldings = ($stmtType === 'holdings') && $isInv;
$content    = $csvData['content'];

// Parse CSV into rows (handle \r\n, \r, \n)
$lines = preg_split('/\r?\n|\r/', trim($content));
$lines = array_filter($lines, fn($l) => trim($l) !== '');
$lines = array_values($lines);

if (count($lines) < 2) {
    setFlash('error', 'CSV file must have at least a header row and one data row.');
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

// Scan ALL lines (before preamble skip) for an opening/beginning balance row.
// Returns the first positive numeric value found on a line whose description
// matches "beginning balance" or "opening balance".
function detectOpeningBalance(array $lines): float {
    foreach ($lines as $line) {
        $cells = str_getcsv($line);
        $descIdx = -1;
        foreach ($cells as $j => $cell) {
            if (preg_match('/\b(?:beginning|opening)\s+balance\b/i', $cell)) {
                $descIdx = $j;
                break;
            }
        }
        if ($descIdx < 0) continue;
        foreach ($cells as $k => $val) {
            if ($k === $descIdx) continue;
            $clean = str_replace([',', '$', '"', ' '], '', trim($val));
            if (is_numeric($clean) && (float)$clean > 0) {
                return (float)$clean;
            }
        }
    }
    return 0.0;
}

$detectedOpeningBalance = detectOpeningBalance($lines);

// For holdings CSVs, extract the statement/export date from the preamble before we discard it.
// Matches patterns like "Exported on: 05/06/2026" or "As of: 05/06/2026".
$holdingsDate = date('Y-m-d');
if ($isHoldings) {
    foreach ($lines as $line) {
        if (preg_match('/(?:exported\s+on|as\s+of|statement\s+date)[:\s]+(\d{1,2}\/\d{1,2}\/\d{4})/i', $line, $m)) {
            $ts = strtotime($m[1]);
            if ($ts) { $holdingsDate = date('Y-m-d', $ts); break; }
        }
    }
}

// Skip preamble rows until the real header row.
// Transaction CSVs: look for a "date" column.
// Holdings CSVs: require both a quantity-type keyword AND an identifier-type keyword in the same
// row — much more specific than matching either alone, avoids false-positives in summary rows.
if ($isHoldings) {
    $qtyWords = ['quantity', 'shares', 'qty', 'units'];
    $idWords  = ['symbol', 'ticker', 'cusip', 'description', 'security name', 'security'];
    foreach ($lines as $i => $line) {
        $cells = array_map(fn($c) => strtolower(trim($c)), str_getcsv($line));
        if (!empty(array_intersect($cells, $qtyWords)) && !empty(array_intersect($cells, $idWords))) {
            $lines = array_values(array_slice($lines, $i));
            break;
        }
    }
} else {
    foreach ($lines as $i => $line) {
        foreach (str_getcsv($line) as $cell) {
            if (strtolower(trim($cell)) === 'date') {
                $lines = array_values(array_slice($lines, $i));
                break 2;
            }
        }
    }
}

$headers = str_getcsv(array_shift($lines));
$headers = array_map('trim', $headers);
$preview = [];
foreach (array_slice($lines, 0, 5) as $line) {
    $cols = str_getcsv($line);
    while (count($cols) < count($headers)) $cols[] = '';
    $preview[] = $cols;
}

// Field definitions for mapping
if ($isHoldings) {
    $fields = [
        'payee'    => ['Security Name',          true],
        'symbol'   => ['Ticker / Symbol',        false],
        'quantity' => ['Quantity / Shares',       true],
        'price'    => ['Price per Share',         false],
        'value'    => ['Market Value (preferred)', false],
    ];
} elseif ($isInv) {
    $fields = [
        'date'             => ['Date',                      true],
        'payee'            => ['Security Name',             true],
        'symbol'           => ['Ticker/Symbol',             false],
        'cusip'            => ['CUSIP',                     false],
        'activity'         => ['Activity (buy/sell/…)',     true],
        'quantity'         => ['Quantity / Shares',         false],
        'price'            => ['Price per Share',           false],
        'commission'       => ['Commission / Fees',         false],
        'amount'           => ['Total Amount',              false],
        'memo'             => ['Memo / Description',        false],
        'fitid'            => ['FIT ID / Confirmation #',   false],
        'transfer_account' => ['Transfer Account (X-type)', false],
    ];
} else {
    $fields = [
        'date'   => ['Date',                             true],
        'payee'  => ['Payee / Description',              true],
        'amount' => ['Amount (signed +/-)',              false],
        'debit'  => ['Debit / Withdrawal (positive #)', false],
        'credit' => ['Credit / Deposit (positive #)',   false],
        'memo'   => ['Memo',                             false],
        'num'    => ['Check / Reference Number',        false],
    ];
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $mapping = $_POST['mapping'] ?? [];

    // Validate required fields
    $missing = [];
    foreach ($fields as $fkey => [$label, $required]) {
        if ($required && empty($mapping[$fkey])) $missing[] = $label;
    }

    if (!$isInv) {
        $hasAmount = !empty($mapping['amount']) || (!empty($mapping['debit']) && !empty($mapping['credit']));
        if (!$hasAmount) $missing[] = 'Amount (or both Debit and Credit)';
    }

    if ($missing) {
        $error = 'Required fields not mapped: ' . implode(', ', $missing);
    } else {
        // Map column names to 0-based indices
        $colIndex = array_flip($headers); // name → index

        $getCol = function(string $fkey) use ($mapping, $colIndex, $headers): int {
            $colName = $mapping[$fkey] ?? '';
            return isset($colIndex[$colName]) ? $colIndex[$colName] : -1;
        };

        $rows = [];
        if ($isHoldings) {
            // Build snapshot from CSV: security name, symbol, quantity, price
            $snapshot        = [];
            $holdingsCashBal = 0.0;
            $csvRowNum       = 0;
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $csvRowNum++;
                $cols = str_getcsv($line);
                while (count($cols) < count($headers)) $cols[] = '';
                $cell = fn(string $fkey) => trim($cols[$getCol($fkey)] ?? '');

                // Detect the "Cash balance" row (label in symbol column, amount elsewhere).
                if ($holdingsCashBal === 0.0 && preg_match('/^cash\s+balance$/i', $cell('symbol'))) {
                    foreach ($cols as $c) {
                        $val = (float)str_replace([',', '$', ' '], '', trim($c));
                        if ($val > 0.0) { $holdingsCashBal = $val; break; }
                    }
                    continue;
                }

                $secName = $cell('payee');
                if ($secName === '') continue;
                $qty = (float)str_replace([',', '$'], '', $cell('quantity'));
                if ($qty <= 0) continue;

                $symbol = strtoupper(preg_replace('/[^A-Z0-9.]/i', '', $cell('symbol')));
                // Category labels (e.g. "Money accounts") clean to strings longer than a
                // valid ticker (≤5 chars) or CUSIP (9 chars) — treat them as no symbol so
                // the key falls back to the security name and rows don't overwrite each other.
                if (strlen($symbol) > 9) $symbol = '';
                $price = (float)str_replace([',', '$'], '', $cell('price'));
                // If a Value column is mapped, derive effective price = value/qty.
                // This corrects bond/CD pricing where Price is per-$100 of face value
                // and Quantity is the face-value amount, making raw price×qty wildly wrong.
                $mktVal = (float)str_replace([',', '$'], '', $cell('value'));
                if ($mktVal > 0.0 && $qty > 0.0) {
                    $price = $mktVal / $qty;
                }
                // Normalize key: strip non-alphanumeric (including dots) so BRK.B / BRK-B /
                // BRKB all resolve to the same key as the DB entry regardless of import source.
                $symNorm = strtolower(preg_replace('/[^A-Z0-9]/i', '', $symbol));
                $key = $symNorm !== '' ? $symNorm : strtolower($secName);

                $snapshot[$key] = [
                    'qty'    => $qty,
                    'name'   => $secName,
                    'symbol' => $symbol,
                    'price'  => $price,
                    'date'   => $holdingsDate,
                ];
            }

            if (empty($snapshot)) {
                $error = 'No valid position rows found in the CSV.';
            } else {
                $current = getAccountHoldings($csvData['account_id']);
                $rows    = reconcileHoldings($snapshot, $current, $holdingsDate);
                if ($holdingsCashBal > 0.0) {
                    $rows[] = [
                        'date'             => $holdingsDate,
                        'payee'            => 'Cash Balance',
                        'symbol'           => '',
                        'activity'         => 'add',
                        'action_type'      => 'Cash',
                        'quantity'         => 0.0,
                        'price'            => 0.0,
                        'commission'       => 0.0,
                        'amount'           => $holdingsCashBal,
                        'memo'             => 'Cash balance from holdings statement',
                        'transfer_account' => null,
                        'is_dup'           => false,
                    ];
                }
                if (empty($rows)) {
                    $error = 'Holdings are already up to date — no reconciliation needed.';
                } else {
                    foreach ($rows as $i => &$row) { $row['source_row'] = $i + 1; }
                    unset($row);
                }
            }
        } else {
            $csvRowNum = 0;
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $csvRowNum++;
                $cols = str_getcsv($line);
                while (count($cols) < count($headers)) $cols[] = '';

                $cell = fn(string $fkey) => trim($cols[$getCol($fkey)] ?? '');

                $dateRaw = $cell('date');
                if ($dateRaw === '') continue;
                $ts = strtotime($dateRaw);
                $date = $ts ? date('Y-m-d', $ts) : '';
                if (!$date) continue;

                if ($isInv) {
                    $security = $cell('payee');
                    if ($security === '') continue;
                    [$activity, $actionType] = csvActivityToActionType($cell('activity'));
                    $qty      = (float)str_replace([',', '$'], '', $cell('quantity'));
                    $price    = (float)str_replace([',', '$'], '', $cell('price'));
                    $comm     = (float)str_replace([',', '$'], '', $cell('commission'));
                    $amt      = (float)str_replace([',', '$'], '', $cell('amount'));
                    $rawCusip = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $cell('cusip')));
                    $rawFitid    = trim($cell('fitid'));
                    $xferAcctRaw = trim($cell('transfer_account'));
                    $rows[] = [
                        'date'             => $date,
                        'payee'            => $security,
                        'symbol'           => $cell('symbol'),
                        'cusip'            => strlen($rawCusip) === 9 ? $rawCusip : '',
                        'activity'         => $activity,
                        'action_type'      => $actionType,
                        'quantity'         => $qty,
                        'price'            => $price,
                        'commission'       => $comm,
                        'amount'           => $amt,
                        'memo'             => $cell('memo'),
                        'fitid'            => $rawFitid !== '' ? $rawFitid : null,
                        'transfer_account' => $xferAcctRaw !== '' ? $xferAcctRaw : null,
                        'source_row'       => $csvRowNum,
                    ];
                } else {
                    $payee = $cell('payee');
                    if ($payee === '') continue;
                    $amtIdx = $getCol('amount');
                    if ($amtIdx >= 0) {
                        $amt = (float)str_replace([',', '$'], '', $cols[$amtIdx] ?? '');
                    } else {
                        $debit  = abs((float)str_replace([',', '$'], '', $cell('debit')));
                        $credit = abs((float)str_replace([',', '$'], '', $cell('credit')));
                        $amt = $credit - $debit; // positive = deposit, negative = withdrawal
                    }
                    if ($amt == 0.0) continue;
                    // Extract check number from description if not in a dedicated column
                    $num = $cell('num');
                    if ($num === '' && preg_match('/^(?:check|chk|ck)\s*#?\s*(\d{3,6})\b/i', $payee, $ckm)) {
                        $num = $ckm[1];
                    }
                    $rows[] = [
                        'date'       => $date,
                        'payee'      => $payee,
                        'amount'     => $amt,
                        'memo'       => $cell('memo'),
                        'num'        => $num,
                        'cleared'    => '',
                        'source_row' => $csvRowNum,
                    ];
                }
            }
        }

        if (empty($rows)) {
            if (empty($error)) {
                $error = 'No valid rows could be parsed from the CSV with the selected mapping.';
            }
        } else {
            if ($isHoldings) {
                assignFitids($rows);
            } else {
                assignFitids($rows);
                markDuplicates($rows, $csvData['account_id'], $isInv);
            }

            $newAccount = $csvData['new_account'] ?? null;
            if (!$isHoldings && $newAccount !== null && $detectedOpeningBalance > 0) {
                $newAccount['opening_balance'] = $detectedOpeningBalance;
            }

            $_SESSION['import'] = [
                'account_id'     => $csvData['account_id'],
                'account_name'   => $csvData['account_name'],
                'account_type'   => $csvData['account_type'],
                'is_investment'  => $isInv,
                'statement_type' => $stmtType,
                'format'         => 'CSV',
                'rows'           => $rows,
                'new_account'    => $newAccount,
            ];

            header('Location: ' . BASE_PATH . '/import/preview');
            exit;
        }
    }
}


$pageTitle   = 'Map CSV Columns — ' . $csvData['account_name'];
$currentPage = 'import';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-content">

  <div class="page-header d-flex align-items-center gap-3 mb-4">
    <div>
      <h1 class="mb-0"><i class="bi bi-table"></i> Map CSV Columns</h1>
      <p class="text-muted mb-0 mt-1">
        Account: <strong><?= h($csvData['account_name']) ?></strong> ·
        <?= count($headers) ?> columns · <?= count($lines) ?> rows
      </p>
    </div>
  </div>

  <?php if (!empty($csvData['new_account'])): ?>
  <div class="alert alert-info py-2 mb-3">
    <i class="bi bi-plus-circle"></i>
    A new <strong><?= h($csvData['new_account']['type']) ?></strong> account
    "<strong><?= h($csvData['new_account']['name']) ?></strong>" will be created
    <?php if ($detectedOpeningBalance > 0): ?>
    with an opening balance of <strong><?= '$' . number_format($detectedOpeningBalance, 2) ?></strong>
    (detected from file).
    <?php else: ?>
    with no opening balance detected — you can set it after import via Account Settings.
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-xl-5">
      <div class="card">
        <div class="card-header"><strong>Column Mapping</strong></div>
        <div class="card-body">
          <form method="post" action="">
            <?= csrfField() ?>
            <p class="text-muted small mb-3">
              Map each field to the corresponding column in your CSV.
              Required fields are marked <span class="text-danger">*</span>.
            </p>
            <?php foreach ($fields as $fkey => [$label, $required]): ?>
            <div class="mb-3">
              <label class="form-label">
                <?= h($label) ?><?php if ($required): ?> <span class="text-danger">*</span><?php endif; ?>
              </label>
              <select name="mapping[<?= h($fkey) ?>]" class="form-select form-select-sm">
                <option value="">— not mapped —</option>
                <?php foreach ($headers as $h_col):
                    $sel = '';
                    // Auto-suggest by common header names
                    $hl = strtolower($h_col);
                    $autoMap = [
                        'date'       => ['date', 'trans date', 'transaction date', 'posted date', 'post date'],
                        'payee'      => ['payee', 'description', 'name', 'merchant', 'security', 'security name', 'symbol description'],
                        'symbol'     => ['symbol', 'ticker'],
                        'cusip'      => ['cusip', 'cusip#', 'cusip #'],
                        'activity'   => ['activity', 'action', 'type', 'transaction type'],
                        'quantity'   => ['quantity', 'shares', 'units', 'qty'],
                        'price'      => ['price', 'unit price', 'price/share'],
                        'commission' => ['commission', 'fees', 'fee'],
                        'value'            => ['value', 'market value', 'mkt value', 'total value'],
                        'amount'           => ['amount', 'total', 'net amount'],
                        'debit'            => ['debit', 'withdrawal', 'debit amount'],
                        'credit'           => ['credit', 'deposit', 'credit amount'],
                        'memo'             => ['memo', 'notes', 'note', 'details'],
                        'num'              => ['check', 'check number', 'ref', 'reference', 'chk #'],
                        'fitid'            => ['fitid', 'fit id', 'confirmation', 'confirmation #', 'transaction id', 'transaction #'],
                        'transfer_account' => ['transfer account', 'transfer to', 'transfer from'],
                    ];
                    if (in_array($hl, $autoMap[$fkey] ?? [])) $sel = ' selected';
                ?>
                <option value="<?= h($h_col) ?>"<?= $sel ?>><?= h($h_col) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endforeach; ?>
            <div class="d-flex gap-2 mt-4">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-arrow-right-circle"></i> Continue to Preview
              </button>
              <a href="<?= BASE_PATH ?>/import/index" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-xl-7">
      <div class="card">
        <div class="card-header"><strong>File Preview</strong> <span class="text-muted small">(first <?= count($preview) ?> rows)</span></div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 small">
              <thead>
                <tr>
                  <?php foreach ($headers as $h_col): ?>
                  <th class="text-nowrap"><?= h($h_col) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($preview as $row): ?>
                <tr>
                  <?php foreach ($row as $cell): ?>
                  <td class="text-nowrap"><?= h($cell) ?></td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
