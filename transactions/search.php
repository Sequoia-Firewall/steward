<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Filter inputs ───────────────────────────────────────────────
$q          = trim($_GET['q']        ?? '');   // payee or memo keyword
$accountId  = (int)($_GET['account'] ?? 0);
$startDate  = $_GET['start']   ?? '';
$endDate    = $_GET['end']     ?? '';
$amtMin     = $_GET['amt_min'] ?? '';
$amtMax     = $_GET['amt_max'] ?? '';
$categoryId = (int)($_GET['cat'] ?? 0);
$cleared    = $_GET['cleared'] ?? '';          // '', 'cleared', 'reconciled', 'uncleared'
$checkNum   = trim($_GET['num'] ?? '');
$txnType    = $_GET['txn_type'] ?? '';         // '', 'withdrawal', 'deposit', 'transfer'
$txnIds     = array_values(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? ''))));
$sortCol    = in_array($_GET['sort'] ?? '', ['date','payee','amount','account','category']) ? $_GET['sort'] : 'date';
$sortDir    = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

// Sanitise dates
if ($startDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = '';
if ($endDate   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = '';

$hasSearch = $q !== '' || $accountId || $startDate || $endDate
          || $amtMin !== '' || $amtMax !== '' || $categoryId
          || $cleared !== '' || $checkNum !== '' || $txnType !== ''
          || !empty($txnIds);

// ── Build query ─────────────────────────────────────────────────
$results = [];
$total   = 0;

if ($hasSearch) {
    $where  = ['1=1'];
    $params = [];

    if ($accountId) {
        $where[]  = 't.account_id = ?';
        $params[] = $accountId;
    }
    if ($q !== '') {
        $where[]  = '(t.payee LIKE ? OR t.memo LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if ($checkNum !== '') {
        $where[]  = 't.num LIKE ?';
        $params[] = '%' . $checkNum . '%';
    }
    if ($startDate) {
        $where[]  = 't.transaction_date >= ?';
        $params[] = $startDate;
    }
    if ($endDate) {
        $where[]  = 't.transaction_date <= ?';
        $params[] = $endDate;
    }
    if ($amtMin !== '' && is_numeric($amtMin)) {
        $where[]  = 'ABS(t.amount) >= ?';
        $params[] = (float)$amtMin;
    }
    if ($amtMax !== '' && is_numeric($amtMax)) {
        $where[]  = 'ABS(t.amount) <= ?';
        $params[] = (float)$amtMax;
    }
    if ($categoryId) {
        // match category or subcategory in splits
        $where[]  = 'EXISTS (
            SELECT 1 FROM transaction_splits ts2
            WHERE ts2.transaction_id = t.id
              AND (ts2.category_id = ? OR ts2.subcategory_id = ?)
        )';
        $params[] = $categoryId;
        $params[] = $categoryId;
    }
    if ($cleared === 'uncleared') {
        $where[]  = "t.cleared_status = ''";
    } elseif ($cleared !== '') {
        $where[]  = 't.cleared_status = ?';
        $params[] = $cleared;
    }
    if ($txnType !== '') {
        $where[]  = 't.type = ?';
        $params[] = $txnType;
    }
    if (!empty($txnIds)) {
        $placeholders = implode(',', array_fill(0, count($txnIds), '?'));
        $where[]      = "t.id IN ($placeholders)";
        $params       = array_merge($params, $txnIds);
    }

    $colMap = [
        'date'     => 't.transaction_date',
        'payee'    => 't.payee',
        'amount'   => 'ABS(t.amount)',
        'account'  => 'a.name',
        'category' => '(SELECT c2.name FROM transaction_splits ts2 JOIN categories c2 ON c2.id = ts2.category_id WHERE ts2.transaction_id = t.id ORDER BY ts2.id LIMIT 1)',
    ];
    $orderBy = ($colMap[$sortCol] ?? 't.transaction_date') . ' ' . $sortDir
             . ', t.id ' . $sortDir;

    $whereClause = implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT t.id, t.account_id, t.num, t.transaction_date, t.payee,
                t.type, t.amount, t.cleared_status, t.memo, t.is_split,
                a.name AS account_name,
                (SELECT c2.name FROM transaction_splits ts2
                 JOIN categories c2 ON c2.id = ts2.category_id
                 WHERE ts2.transaction_id = t.id ORDER BY ts2.id LIMIT 1) AS category_name,
                (SELECT sc2.name FROM transaction_splits ts3
                 JOIN categories sc2 ON sc2.id = ts3.subcategory_id
                 WHERE ts3.transaction_id = t.id AND ts3.subcategory_id IS NOT NULL
                 ORDER BY ts3.id LIMIT 1) AS subcategory_name,
                t.transfer_pair_id
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         WHERE $whereClause
         ORDER BY $orderBy
         LIMIT 500"
    );
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Totals
    $stmtT = $db->prepare(
        "SELECT COUNT(*) AS cnt,
                SUM(CASE WHEN t.amount < 0 THEN ABS(t.amount) ELSE 0 END) AS total_debit,
                SUM(CASE WHEN t.amount > 0 THEN t.amount     ELSE 0 END) AS total_credit
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         WHERE $whereClause"
    );
    $stmtT->execute($params);
    $totals = $stmtT->fetch();
}

// ── Transfer search inputs ──────────────────────────────────────
$xq        = trim($_GET['xq']    ?? '');
$xFrom     = (int)($_GET['xfrom']  ?? 0);
$xTo       = (int)($_GET['xto']    ?? 0);
$xStart    = $_GET['xstart'] ?? '';
$xEnd      = $_GET['xend']   ?? '';
$xMin      = $_GET['xmin']   ?? '';
$xMax      = $_GET['xmax']   ?? '';

if ($xStart && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $xStart)) $xStart = '';
if ($xEnd   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $xEnd))   $xEnd   = '';

$hasXfer = $xq !== '' || $xFrom || $xTo || $xStart || $xEnd
        || $xMin !== '' || $xMax !== '';

$xferResults = [];
$xferTotal   = 0;

if ($hasXfer) {
    $xWhere  = ['t1.type = \'transfer\'', 't1.amount < 0', 't1.transfer_pair_id IS NOT NULL'];
    $xParams = [];

    if ($xq !== '') {
        $xWhere[]  = '(t1.payee LIKE ? OR t1.memo LIKE ? OR t2.payee LIKE ? OR t2.memo LIKE ?)';
        $xParams[] = '%' . $xq . '%';
        $xParams[] = '%' . $xq . '%';
        $xParams[] = '%' . $xq . '%';
        $xParams[] = '%' . $xq . '%';
    }
    if ($xFrom) { $xWhere[] = 't1.account_id = ?'; $xParams[] = $xFrom; }
    if ($xTo)   { $xWhere[] = 't2.account_id = ?'; $xParams[] = $xTo; }
    if ($xStart) { $xWhere[] = 't1.transaction_date >= ?'; $xParams[] = $xStart; }
    if ($xEnd)   { $xWhere[] = 't1.transaction_date <= ?'; $xParams[] = $xEnd; }
    if ($xMin !== '' && is_numeric($xMin)) { $xWhere[] = 'ABS(t1.amount) >= ?'; $xParams[] = (float)$xMin; }
    if ($xMax !== '' && is_numeric($xMax)) { $xWhere[] = 'ABS(t1.amount) <= ?'; $xParams[] = (float)$xMax; }

    $xWc = implode(' AND ', $xWhere);

    $xStmt = $db->prepare(
        "SELECT t1.id, t1.transaction_date, t1.payee, t1.memo,
                ABS(t1.amount) AS amount, t1.cleared_status,
                a1.id AS from_account_id, a1.name AS from_account_name,
                a2.id AS to_account_id,   a2.name AS to_account_name
         FROM transactions t1
         JOIN transactions t2 ON t2.id = t1.transfer_pair_id
         JOIN accounts a1 ON a1.id = t1.account_id
         JOIN accounts a2 ON a2.id = t2.account_id
         WHERE $xWc
         ORDER BY t1.transaction_date DESC, t1.id DESC
         LIMIT 500"
    );
    $xStmt->execute($xParams);
    $xferResults = $xStmt->fetchAll();
    $xferTotal   = count($xferResults);
}

// ── JSON response for modal popups (bills page, etc.) ───────────
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => true,
        'count'   => count($results),
        'totals'  => $totals ?? null,
        'results' => $results,
    ]);
    exit;
}

// ── Sidebar data ────────────────────────────────────────────────
$allAccounts = getAccounts(true, true); // include closed for searching historical transactions
$allCats     = $db->query(
    "SELECT id, name, parent_id FROM categories WHERE is_active = 1 ORDER BY name"
)->fetchAll();

// Build flat category list for dropdown: parents first, then children indented
$catParents  = array_filter($allCats, fn($c) => $c['parent_id'] === null);
$catChildren = [];
foreach ($allCats as $c) {
    if ($c['parent_id'] !== null) $catChildren[(int)$c['parent_id']][] = $c;
}
$catOptions = [];
foreach ($catParents as $p) {
    $catOptions[] = ['id' => $p['id'], 'label' => $p['name'], 'indent' => false];
    foreach ($catChildren[(int)$p['id']] ?? [] as $ch) {
        $catOptions[] = ['id' => $ch['id'], 'label' => $p['name'] . ' › ' . $ch['name'], 'indent' => true];
    }
}

// ── Sort link helper ────────────────────────────────────────────
function sortUrl(string $col, string $currentCol, string $currentDir, array $get): string {
    $newDir = ($currentCol === $col && $currentDir === 'asc') ? 'desc' : 'asc';
    $p = $get;
    $p['sort'] = $col;
    $p['dir']  = $newDir;
    return '?' . http_build_query($p);
}
function sortIcon(string $col, string $currentCol, string $currentDir): string {
    if ($currentCol !== $col) return '<i class="bi bi-arrow-down-up reg-sort-icon"></i>';
    return $currentDir === 'asc'
        ? '<i class="bi bi-arrow-up reg-sort-icon active"></i>'
        : '<i class="bi bi-arrow-down reg-sort-icon active"></i>';
}

$getParams = $_GET;

$pageTitle   = 'Search';
$currentPage = 'search';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <h2><i class="bi bi-search"></i> Search</h2>
</div>

<!-- ── Transaction Search ──────────────────────────────────────── -->
<h5 class="search-group-title"><i class="bi bi-receipt"></i> Transaction Search</h5>
<form method="get" class="search-form" id="searchForm">

  <div class="search-row">
    <div class="search-field search-field-wide">
      <label for="sq">Payee / Memo</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" id="sq" class="form-control"
               placeholder="Search payee or memo…" value="<?= h($q) ?>" autofocus>
      </div>
    </div>
    <div class="search-field">
      <label for="sAccount">Account</label>
      <select name="account" id="sAccount" class="form-select form-select-sm">
        <option value="">All Accounts</option>
        <?php foreach ($allAccounts as $acc): ?>
        <option value="<?= $acc['id'] ?>" <?= $accountId == $acc['id'] ? 'selected' : '' ?>>
          <?= h(accountDisplayName($acc)) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="search-field">
      <label for="sCat">Category</label>
      <select name="cat" id="sCat" class="form-select form-select-sm">
        <option value="">Any Category</option>
        <?php foreach ($catOptions as $opt): ?>
        <option value="<?= $opt['id'] ?>" <?= $categoryId == $opt['id'] ? 'selected' : '' ?>>
          <?= $opt['indent'] ? "\u{00A0}\u{00A0}\u{00A0}" : '' ?><?= h($opt['label']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="search-row">
    <div class="search-field">
      <label>Date From</label>
      <input type="date" name="start" class="form-control form-control-sm" value="<?= h($startDate) ?>">
    </div>
    <div class="search-field">
      <label>Date To</label>
      <input type="date" name="end" class="form-control form-control-sm" value="<?= h($endDate) ?>">
    </div>
    <div class="search-field">
      <label>Min Amount ($)</label>
      <input type="number" name="amt_min" class="form-control form-control-sm"
             step="0.01" min="0" placeholder="0.00" value="<?= h($amtMin) ?>">
    </div>
    <div class="search-field">
      <label>Max Amount ($)</label>
      <input type="number" name="amt_max" class="form-control form-control-sm"
             step="0.01" min="0" placeholder="Any" value="<?= h($amtMax) ?>">
    </div>
    <div class="search-field">
      <label>Check / Ref #</label>
      <input type="text" name="num" class="form-control form-control-sm"
             placeholder="Number" value="<?= h($checkNum) ?>">
    </div>
    <div class="search-field">
      <label>Type</label>
      <select name="txn_type" class="form-select form-select-sm">
        <option value="">Any</option>
        <option value="withdrawal" <?= $txnType==='withdrawal'?'selected':'' ?>>Withdrawal</option>
        <option value="deposit"    <?= $txnType==='deposit'   ?'selected':'' ?>>Deposit</option>
        <option value="transfer"   <?= $txnType==='transfer'  ?'selected':'' ?>>Transfer</option>
      </select>
    </div>
    <div class="search-field">
      <label>Cleared</label>
      <select name="cleared" class="form-select form-select-sm">
        <option value="">Any</option>
        <option value="uncleared"  <?= $cleared==='uncleared' ?'selected':'' ?>>Uncleared</option>
        <option value="cleared"    <?= $cleared==='cleared'   ?'selected':'' ?>>Cleared (c)</option>
        <option value="reconciled" <?= $cleared==='reconciled'?'selected':'' ?>>Reconciled (R)</option>
      </select>
    </div>
  </div>

  <div class="search-row search-row-btns">
    <button type="submit" class="btn btn-primary btn-sm">
      <i class="bi bi-search"></i> Search
    </button>
    <a href="<?= BASE_PATH ?>/transactions/search" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-x-lg"></i> Clear
    </a>
    <?php if (!empty($results)): ?>
    <span class="search-result-count ms-3">
      <?= count($results) ?> result<?= count($results) !== 1 ? 's' : '' ?>
      <?= count($results) >= 500 ? ' <span class="text-muted">(limited to 500)</span>' : '' ?>
    </span>
    <?php endif; ?>
  </div>

  <!-- hidden sort fields carried through submissions -->
  <input type="hidden" name="sort" value="<?= h($sortCol) ?>">
  <input type="hidden" name="dir"  value="<?= h($sortDir) ?>">
  <!-- preserve transfer search state -->
  <?php if ($hasXfer): ?>
  <input type="hidden" name="xq"     value="<?= h($xq) ?>">
  <input type="hidden" name="xfrom"  value="<?= h($xFrom) ?>">
  <input type="hidden" name="xto"    value="<?= h($xTo) ?>">
  <input type="hidden" name="xstart" value="<?= h($xStart) ?>">
  <input type="hidden" name="xend"   value="<?= h($xEnd) ?>">
  <input type="hidden" name="xmin"   value="<?= h($xMin) ?>">
  <input type="hidden" name="xmax"   value="<?= h($xMax) ?>">
  <?php endif; ?>
</form>

<!-- ── Results ─────────────────────────────────────────────────── -->
<?php if ($hasSearch && empty($results)): ?>
  <div class="search-no-results">
    <i class="bi bi-inbox"></i>
    <p>No transactions matched your search.</p>
  </div>

<?php elseif (!empty($results)): ?>

  <!-- Summary strip -->
  <div class="search-summary-bar">
    <span><i class="bi bi-arrow-up-circle text-danger"></i> Payments:
      <strong class="amount-debit"><?= formatMoney((float)$totals['total_debit']) ?></strong></span>
    <span><i class="bi bi-arrow-down-circle text-success"></i> Deposits:
      <strong class="amount-credit"><?= formatMoney((float)$totals['total_credit']) ?></strong></span>
    <span>Net: <strong class="<?= ((float)$totals['total_credit'] - (float)$totals['total_debit']) >= 0 ? 'amount-credit' : 'amount-debit' ?>">
      <?= formatMoney((float)$totals['total_credit'] - (float)$totals['total_debit'], true) ?>
    </strong></span>
  </div>

  <div class="register-grid-wrapper">
  <table class="register-grid search-results-table" id="searchResultsTable">
    <thead>
      <tr>
        <th><a href="<?= sortUrl('date', $sortCol, $sortDir, $getParams) ?>" class="reg-sort-link">
          Date <?= sortIcon('date', $sortCol, $sortDir) ?></a></th>
        <th>Txn #</th>
        <th><a href="<?= sortUrl('account', $sortCol, $sortDir, $getParams) ?>" class="reg-sort-link">
          Account <?= sortIcon('account', $sortCol, $sortDir) ?></a></th>
        <th>Num</th>
        <th><a href="<?= sortUrl('payee', $sortCol, $sortDir, $getParams) ?>" class="reg-sort-link">
          Payee / Memo <?= sortIcon('payee', $sortCol, $sortDir) ?></a></th>
        <th><a href="<?= sortUrl('category', $sortCol, $sortDir, $getParams) ?>" class="reg-sort-link">
          Category <?= sortIcon('category', $sortCol, $sortDir) ?></a></th>
        <th>C</th>
        <th class="text-end">Payment</th>
        <th class="text-end">Deposit</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($results as $txn):
        $amt     = (float)$txn['amount'];
        $payment = $amt < 0 ? abs($amt) : 0;
        $deposit = $amt >= 0 ? $amt : 0;

        $clearedIcon = match($txn['cleared_status']) {
            'cleared'    => '<span class="cleared-c" title="Cleared">c</span>',
            'reconciled' => '<span class="cleared-r" title="Reconciled">R</span>',
            default      => '',
        };

        if ($txn['is_split']) {
            $catDisplay = '<em class="text-muted">-- Split --</em>';
        } elseif ($txn['category_name'] && $txn['subcategory_name']) {
            $catDisplay = h($txn['category_name']) . ' &rsaquo; ' . h($txn['subcategory_name']);
        } elseif ($txn['category_name']) {
            $catDisplay = h($txn['category_name']);
        } else {
            $catDisplay = '<span class="text-muted">—</span>';
        }

        $registerUrl = BASE_PATH . '/accounts/register?id=' . $txn['account_id'];
        $txnUrl      = $registerUrl . '&txn=' . $txn['id'];
      ?>
      <tr class="register-row search-result-row"
          onclick="window.location='<?= $txnUrl ?>'"
          title="Open in register">
        <td class="col-date text-nowrap"><?= formatDate($txn['transaction_date']) ?></td>
        <td class="col-txnid">
          <a href="<?= $txnUrl ?>" class="txn-id-link" onclick="event.stopPropagation()" title="Open this transaction in the register">
            <?= (int)$txn['id'] ?>
          </a>
        </td>
        <td class="col-acct-name">
          <a href="<?= $registerUrl ?>" class="search-acct-link" onclick="event.stopPropagation()">
            <?= h($txn['account_name']) ?>
          </a>
        </td>
        <td class="col-num"><?= h($txn['num']) ?></td>
        <td class="col-payee">
          <div class="payee-name"><?= h($txn['payee']) ?></div>
          <?php if ($txn['memo']): ?><div class="txn-memo"><?= h($txn['memo']) ?></div><?php endif; ?>
        </td>
        <td class="col-cat"><?= $catDisplay ?></td>
        <td class="col-c"><?= $clearedIcon ?></td>
        <td class="col-payment text-end">
          <?= $payment > 0 ? '<span class="amount-debit">' . formatMoney($payment) . '</span>' : '' ?>
        </td>
        <td class="col-deposit text-end">
          <?= $deposit > 0 ? '<span class="amount-credit">' . formatMoney($deposit) . '</span>' : '' ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

<?php endif; ?>

<!-- ── Transfer Search ─────────────────────────────────────────── -->
<h5 class="search-group-title mt-4"><i class="bi bi-arrow-left-right"></i> Transfer Search</h5>
<form method="get" class="search-form" id="xferForm">

  <div class="search-row">
    <div class="search-field search-field-wide">
      <label>Payee / Memo</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="xq" class="form-control"
               placeholder="Search payee or memo…" value="<?= h($xq) ?>">
      </div>
    </div>
    <div class="search-field">
      <label>From Account</label>
      <select name="xfrom" class="form-select form-select-sm">
        <option value="">Any Account</option>
        <?php foreach ($allAccounts as $acc): ?>
        <option value="<?= $acc['id'] ?>" <?= $xFrom == $acc['id'] ? 'selected' : '' ?>>
          <?= h(accountDisplayName($acc)) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="search-field">
      <label>To Account</label>
      <select name="xto" class="form-select form-select-sm">
        <option value="">Any Account</option>
        <?php foreach ($allAccounts as $acc): ?>
        <option value="<?= $acc['id'] ?>" <?= $xTo == $acc['id'] ? 'selected' : '' ?>>
          <?= h(accountDisplayName($acc)) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="search-row">
    <div class="search-field">
      <label>Date From</label>
      <input type="date" name="xstart" class="form-control form-control-sm" value="<?= h($xStart) ?>">
    </div>
    <div class="search-field">
      <label>Date To</label>
      <input type="date" name="xend" class="form-control form-control-sm" value="<?= h($xEnd) ?>">
    </div>
    <div class="search-field">
      <label>Min Amount ($)</label>
      <input type="number" name="xmin" class="form-control form-control-sm"
             step="0.01" min="0" placeholder="0.00" value="<?= h($xMin) ?>">
    </div>
    <div class="search-field">
      <label>Max Amount ($)</label>
      <input type="number" name="xmax" class="form-control form-control-sm"
             step="0.01" min="0" placeholder="Any" value="<?= h($xMax) ?>">
    </div>
  </div>

  <div class="search-row search-row-btns">
    <button type="submit" class="btn btn-primary btn-sm">
      <i class="bi bi-search"></i> Search
    </button>
    <a href="<?= BASE_PATH ?>/transactions/search?<?= $hasSearch ? http_build_query(array_intersect_key($_GET, array_flip(['q','account','start','end','amt_min','amt_max','cat','cleared','num','txn_type','sort','dir']))) : '' ?>"
       class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-x-lg"></i> Clear
    </a>
    <?php if ($hasXfer): ?>
    <span class="search-result-count ms-3">
      <?= $xferTotal ?> result<?= $xferTotal !== 1 ? 's' : '' ?>
      <?= $xferTotal >= 500 ? ' <span class="text-muted">(limited to 500)</span>' : '' ?>
    </span>
    <?php endif; ?>
  </div>

  <!-- preserve transaction search state -->
  <?php if ($hasSearch): ?>
  <input type="hidden" name="q"        value="<?= h($q) ?>">
  <input type="hidden" name="account"  value="<?= h($accountId) ?>">
  <input type="hidden" name="start"    value="<?= h($startDate) ?>">
  <input type="hidden" name="end"      value="<?= h($endDate) ?>">
  <input type="hidden" name="amt_min"  value="<?= h($amtMin) ?>">
  <input type="hidden" name="amt_max"  value="<?= h($amtMax) ?>">
  <input type="hidden" name="cat"      value="<?= h($categoryId) ?>">
  <input type="hidden" name="cleared"  value="<?= h($cleared) ?>">
  <input type="hidden" name="num"      value="<?= h($checkNum) ?>">
  <input type="hidden" name="txn_type" value="<?= h($txnType) ?>">
  <input type="hidden" name="sort"     value="<?= h($sortCol) ?>">
  <input type="hidden" name="dir"      value="<?= h($sortDir) ?>">
  <?php endif; ?>
</form>

<?php if ($hasXfer && empty($xferResults)): ?>
  <div class="search-no-results">
    <i class="bi bi-inbox"></i>
    <p>No transfers matched your search.</p>
  </div>

<?php elseif (!empty($xferResults)): ?>
  <div class="register-grid-wrapper mt-2">
  <table class="register-grid search-results-table">
    <thead>
      <tr>
        <th>Date</th>
        <th>Txn #</th>
        <th>From Account</th>
        <th>To Account</th>
        <th>Payee / Memo</th>
        <th>C</th>
        <th class="text-end">Amount</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($xferResults as $xfr):
        $clearedIcon = match($xfr['cleared_status']) {
            'cleared'    => '<span class="cleared-c" title="Cleared">c</span>',
            'reconciled' => '<span class="cleared-r" title="Reconciled">R</span>',
            default      => '',
        };
        $fromUrl = BASE_PATH . '/accounts/register?id=' . $xfr['from_account_id'];
        $toUrl   = BASE_PATH . '/accounts/register?id=' . $xfr['to_account_id'];
        $xfrTxnUrl = $fromUrl . '&txn=' . $xfr['id'];
      ?>
      <tr class="register-row search-result-row"
          onclick="window.location='<?= $xfrTxnUrl ?>'"
          title="Open in register">
        <td class="col-date text-nowrap"><?= formatDate($xfr['transaction_date']) ?></td>
        <td class="col-txnid">
          <a href="<?= $xfrTxnUrl ?>" class="txn-id-link" onclick="event.stopPropagation()" title="Open this transaction in the register">
            <?= (int)$xfr['id'] ?>
          </a>
        </td>
        <td class="col-acct-name">
          <a href="<?= $fromUrl ?>" class="search-acct-link" onclick="event.stopPropagation()">
            <?= h($xfr['from_account_name']) ?>
          </a>
        </td>
        <td class="col-acct-name">
          <a href="<?= $toUrl ?>" class="search-acct-link" onclick="event.stopPropagation()">
            <?= h($xfr['to_account_name']) ?>
          </a>
        </td>
        <td class="col-payee">
          <div class="payee-name"><?= h($xfr['payee']) ?></div>
          <?php if ($xfr['memo']): ?><div class="txn-memo"><?= h($xfr['memo']) ?></div><?php endif; ?>
        </td>
        <td class="col-c"><?= $clearedIcon ?></td>
        <td class="col-payment text-end">
          <span class="amount-debit"><?= formatMoney((float)$xfr['amount']) ?></span>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php if (!$hasSearch && !$hasXfer): ?>
  <div class="search-empty-state mt-4">
    <i class="bi bi-search"></i>
    <p>Enter search criteria above to find transactions or transfers across all accounts.</p>
    <div class="search-tips">
      <strong>Tips:</strong>
      Use payee/memo keyword alone for a quick search, or combine filters for precision.
      Results are limited to 500 rows.
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
