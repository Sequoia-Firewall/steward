<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$id          = (int)($_GET['id']  ?? 0);
$txnAutoOpen = (int)($_GET['txn'] ?? 0);
$account = getAccount($id);
if (!$account) {
    setFlash('error', 'Account not found.');
    header('Location: ' . BASE_PATH . '/accounts/index');
    exit;
}

$isInvestAccount   = (isInvestLike($account['type']) && !$account['is_investment_cash']);
$isAssetAccount    = ($account['type'] === 'Asset');
$isLoanAccount     = ($account['type'] === 'Loan');
$isCreditCard      = ($account['type'] === 'Credit Card');

$validSortCols      = ['num', 'date', 'payee', 'category'];
$validInvSortCols   = ['date', 'payee', 'total'];
$sortCol            = in_array($_GET['sort'] ?? '', $validSortCols) ? $_GET['sort'] : 'date';
$invSortCol         = in_array($_GET['sort'] ?? '', $validInvSortCols) ? $_GET['sort'] : 'date';
$defaultSortDir     = getSetting('register_sort_desc') === '1' ? 'desc' : 'asc';
$sortDir            = ($_GET['dir'] ?? $defaultSortDir) === 'desc' ? 'desc' : 'asc';
$showAll            = !empty($_GET['all']);

$transactions      = $isInvestAccount ? getInvestmentRegisterTransactions($id, $invSortCol, $sortDir) : getRegisterTransactions($id, $sortCol, $sortDir);

// Limit displayed rows to the last 12 months unless ?all=1
// (skip the cutoff entirely if it would hide the transaction we're deep-linking to)
$hiddenCount = 0;
if (!$showAll && !$isInvestAccount) {
    $cutoff = date('Y-m-d', strtotime('-1 year'));
    $autoOpenHidden = false;
    if ($txnAutoOpen) {
        foreach ($transactions as $t) {
            if ((int)$t['id'] === $txnAutoOpen && $t['transaction_date'] < $cutoff) {
                $autoOpenHidden = true;
                break;
            }
        }
    }
    if (!$autoOpenHidden) {
        $visible = array_filter($transactions, fn($t) => $t['transaction_date'] >= $cutoff);
        $hiddenCount = count($transactions) - count($visible);
        if ($hiddenCount > 0) {
            $transactions = array_values($visible);
        }
    }
}
$isClosed          = !empty($account['is_closed']);
$allAccounts       = getAccounts(); // excludes closed accounts — correct for transfer dropdowns
$categoryHierarchy = $isInvestAccount ? [] : getAllCategoriesHierarchy();
$categoriesByType  = [];
foreach ($categoryHierarchy as $cat) $categoriesByType[$cat['type']][] = $cat;
$linkedAccount     = getLinkedAccount($account);
$allInvestments    = $isInvestAccount ? getAllInvestments() : [];

$pageTitle         = $account['name'] . ' — Register';
$currentPage       = 'accounts';
$currentAccountId  = $id;
$registerFormTop   = getSetting('register_form_top') === '1';

include __DIR__ . '/../includes/header.php';
?>
<div class="register-page">

  <!-- ── Account Header ─────────────────────────────────── -->
  <div class="register-header">
    <div class="register-title">
      <?php
      $typeIcon = ['Checking'=>'bi-bank','Savings'=>'bi-piggy-bank','Credit Card'=>'bi-credit-card','Investment'=>'bi-graph-up-arrow','Crypto'=>'bi-currency-bitcoin','Asset'=>'bi-safe2','Loan'=>'bi-cash-coin','investment-cash'=>'bi-cash-coin'][$account['type']] ?? 'bi-wallet2';
      ?>
      <i class="bi <?= $typeIcon ?>"></i>
      <div>
        <h2><?= h($account['name']) ?></h2>
        <span class="acct-meta">
          <?= h($account['institution']) ?>
          <?php if ($account['account_number']): ?> · <?= h($account['account_number']) ?><?php endif; ?>
          · <?= h($account['type']) ?>
          · <?= h($account['currency']) ?>
        </span>
      </div>
    </div>
    <div class="register-actions">
      <?php
      // Investment accounts: show market value, not the running transaction-amount sum
      if ($isInvestAccount) {
          $balance        = getAccountBalance($id);
          $currentBalance = null; // market value is always current; no separate today-balance
      } else {
          if (!empty($transactions)) {
              $lastTxn = $transactions[0];
              foreach ($transactions as $_t) {
                  if ($_t['transaction_date'] > $lastTxn['transaction_date'] ||
                      ($_t['transaction_date'] === $lastTxn['transaction_date'] && (int)$_t['id'] > (int)$lastTxn['id'])) {
                      $lastTxn = $_t;
                  }
              }
              $balance = (float)$lastTxn['balance'];
          } else {
              $balance = (float)$account['opening_balance'];
          }
          // Balance as of today: excludes future-dated transactions
          $_cbStmt = getDB()->prepare(
              'SELECT a.opening_balance + COALESCE(
                   (SELECT SUM(t.amount) FROM transactions t
                    WHERE t.account_id = a.id AND t.transaction_date <= CURDATE()), 0)
               FROM accounts a WHERE a.id = ?'
          );
          $_cbStmt->execute([$id]);
          $currentBalance = (float)$_cbStmt->fetchColumn();
      }
      $balCls    = round($balance, MONEY_DECIMALS) < 0 ? 'neg' : 'pos';
      $curBalCls = ($currentBalance !== null && round($currentBalance, MONEY_DECIMALS) < 0) ? 'neg' : 'pos';
      ?>
      <div class="register-balance">
        <?php if ($currentBalance !== null): ?>
        <div class="bal-current-block">
          <span class="bal-label">Current Balance</span>
          <span class="bal-amount bal-current-amount <?= $curBalCls ?>"><?= formatMoney($currentBalance) ?></span>
        </div>
        <?php endif; ?>
        <div class="bal-ending-block">
          <span class="bal-label">Ending Balance</span>
          <span class="bal-amount <?= $balCls ?>"><?= formatMoney($balance) ?></span>
        </div>
        <?php if ($linkedAccount): ?>
        <?php
          $linkedBal    = getAccountBalance((int)$linkedAccount['id']);
          $linkedBalCls = round($linkedBal, MONEY_DECIMALS) < 0 ? 'neg' : 'pos';
          $linkedLabel  = $account['is_investment_cash'] ? 'Investment Balance' : 'Cash Balance';
        ?>
        <span class="bal-label bal-label-linked"><?= $linkedLabel ?></span>
        <span class="bal-amount bal-amount-linked <?= $linkedBalCls ?>"><?= formatMoney($linkedBal) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($isInvestAccount): ?>
      <a href="<?= BASE_PATH ?>/accounts/holdings?id=<?= $id ?>" class="btn btn-outline-info btn-sm" title="View Holdings">
        <i class="bi bi-pie-chart"></i> Holdings
      </a>
      <?php endif; ?>
      <?php if ($isLoanAccount): ?>
      <a href="<?= BASE_PATH ?>/loans/schedule?id=<?= $id ?>" class="btn btn-outline-info btn-sm" title="Amortization Schedule">
        <i class="bi bi-table"></i> Schedule
      </a>
      <?php endif; ?>
      <?php if (canEdit() && !$isAssetAccount && !$isInvestAccount && !$isLoanAccount): ?>
      <a href="<?= BASE_PATH ?>/accounts/reconcile?id=<?= $id ?>" class="btn btn-outline-primary btn-sm" title="Reconcile Account">
        <i class="bi bi-check2-square"></i> Reconcile
      </a>
      <?php endif; ?>
      <?php if (canEdit()): ?>
      <a href="<?= BASE_PATH ?>/accounts/edit?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm" title="Edit Account">
        <i class="bi bi-pencil"></i> Edit
      </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($linkedAccount): ?>
  <div class="linked-account-bar">
    <?php if ($account['is_investment_cash']): ?>
      <i class="bi bi-graph-up-arrow"></i>
      Cash account linked to investment account:
      <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $linkedAccount['id'] ?>">
        <?= h($linkedAccount['name']) ?>
      </a>
    <?php else: ?>
      <i class="bi bi-bank"></i>
      Companion cash account:
      <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $linkedAccount['id'] ?>">
        <?= h($linkedAccount['name']) ?>
      </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="reg-history-bar">
    <?php if ($hiddenCount > 0 || $showAll): ?>
    <i class="bi bi-clock-history"></i>
    <?php if ($showAll): ?>
      <strong>Showing all <?= $hiddenCount + count($transactions) ?> transactions.</strong>
    <?php else: ?>
      <strong style="color:#c0392b;">Showing the last 12 months only.</strong>
    <?php endif; ?>
    <label class="reg-show-all-label">
      <input type="checkbox" id="showAllToggle" <?= $showAll ? 'checked' : '' ?>>
      Show all transactions
    </label>
    <?php endif; ?>
    <label class="reg-show-all-label">
      <input type="checkbox" id="showTxnIdToggle">
      Show Txn #
    </label>
  </div>

  <?php if ($isClosed): ?>
  <div class="alert alert-secondary d-flex align-items-center gap-2 mb-3" role="alert">
    <i class="bi bi-lock-fill"></i>
    <div>This account is <strong>closed</strong>. No new transactions can be entered.
      <?php if (isAdmin()): ?>
      <a href="<?= BASE_PATH ?>/accounts/edit?id=<?= $id ?>" class="ms-2">Edit account</a> to reopen it.
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($registerFormTop && !$isLoanAccount && canEdit() && !$isClosed): ?>
  <!-- ── Transaction Entry Form (top position) ──────────── -->
  <?php include __DIR__ . '/_register_txn_form.php'; ?>
  <?php endif; ?>

  <!-- ── Transaction Register Grid ─────────────────────── -->
  <div class="register-grid-wrapper">
    <table class="register-grid" id="registerGrid">
      <thead>
        <?php
          // Sort URL/icon helpers (used by both investment and standard headers)
          $activeSortCol = $isInvestAccount ? $invSortCol : $sortCol;
          $sortUrl = function(string $col) use ($id, $activeSortCol, $sortDir): string {
              $newDir = ($activeSortCol === $col && $sortDir === 'asc') ? 'desc' : 'asc';
              return BASE_PATH . '/accounts/register?id=' . $id . '&sort=' . $col . '&dir=' . $newDir;
          };
          $sortIcon = function(string $col) use ($activeSortCol, $sortDir): string {
              if ($activeSortCol !== $col) return '<i class="bi bi-arrow-down-up reg-sort-icon"></i>';
              return $sortDir === 'asc'
                  ? '<i class="bi bi-arrow-up reg-sort-icon active"></i>'
                  : '<i class="bi bi-arrow-down reg-sort-icon active"></i>';
          };
        ?>
        <?php if ($isInvestAccount): ?>
        <tr>
          <th class="col-date"><a href="<?= $sortUrl('date') ?>" class="reg-sort-link">Date <?= $sortIcon('date') ?></a></th>
          <th class="col-payee"><a href="<?= $sortUrl('payee') ?>" class="reg-sort-link">Investment <?= $sortIcon('payee') ?></a></th>
          <th class="col-cat">Activity</th>
          <th class="col-qty text-end">Qty</th>
          <th class="col-price text-end">Price</th>
          <th class="col-inv-total text-end"><a href="<?= $sortUrl('total') ?>" class="reg-sort-link">Total <?= $sortIcon('total') ?></a></th>
          <th class="col-c" title="Cleared Status">C</th>
          <th class="col-memo-inv">Memo</th>
          <?php if (canEdit()): ?>
          <th class="col-actions"></th>
          <?php endif; ?>
        </tr>
        <?php else: ?>
        <tr>
          <th class="col-num"><a href="<?= $sortUrl('num') ?>" class="reg-sort-link"><?= $isAssetAccount ? 'Reference' : 'Num' ?> <?= $sortIcon('num') ?></a></th>
          <th class="col-date"><a href="<?= $sortUrl('date') ?>" class="reg-sort-link">Date <?= $sortIcon('date') ?></a></th>
          <th class="col-payee"><a href="<?= $sortUrl('payee') ?>" class="reg-sort-link">Payee / Description <?= $sortIcon('payee') ?></a></th>
          <th class="col-cat"><a href="<?= $sortUrl('category') ?>" class="reg-sort-link">Category <?= $sortIcon('category') ?></a></th>
          <th class="col-c" title="Cleared Status">C</th>
          <th class="col-payment text-end"><?= $isAssetAccount ? 'Decrease' : ($isLoanAccount ? 'Draw' : 'Payment') ?></th>
          <th class="col-deposit text-end"><?= $isAssetAccount ? 'Increase' : ($isLoanAccount ? 'Payment' : 'Deposit') ?></th>
          <th class="col-balance text-end" title="Running balance in date order">Balance</th>
          <?php if (canEdit()): ?>
          <th class="col-actions"></th>
          <?php endif; ?>
        </tr>
        <?php endif; ?>
      </thead>
      <tbody id="registerBody">
        <?php
          $today          = date('Y-m-d');
          $separatorShown = false;
          $sortingByDate  = ($isInvestAccount ? $invSortCol : $sortCol) === 'date';
        ?>
        <?php foreach ($transactions as $txn):
          // Inject today/future separator when sorted by date
          if ($sortingByDate && !$separatorShown) {
              $txnDate  = $txn['transaction_date'];
              $isFuture = $txnDate > $today;
              $isPast   = $txnDate <= $today;
              if (($sortDir === 'asc' && $isFuture) || ($sortDir === 'desc' && $isPast)) {
                  $separatorShown = true;
                  $sepCols = canEdit() ? 9 : 8;
                  echo '<tr class="reg-today-sep"><td colspan="' . $sepCols . '"><div class="reg-today-sep-inner"><span class="reg-today-sep-label">Today &mdash; ' . date('M j, Y') . '</span></div></td></tr>';
              }
          }
          $clearedIcon = match($txn['cleared_status']) {
              'cleared'    => '<span class="cleared-c" title="Cleared">c</span>',
              'reconciled' => '<span class="cleared-r" title="Reconciled">R</span>',
              default      => '',
          };
          $canDeleteRow = isAdmin() || (canDelete() && $txn['cleared_status'] !== 'reconciled');
        ?>
        <?php if ($isInvestAccount):
          $invQty   = (float)($txn['quantity']   ?? 0);
          $invPrice = (float)($txn['inv_price']  ?? 0);
          $invComm  = (float)($txn['commission'] ?? 0);
          $activity = $txn['activity'] ?? '';
          $invTotal = 0.0;
          if ($activity === 'buy')                            $invTotal = $invQty * $invPrice + $invComm;
          elseif ($activity === 'sell')                       $invTotal = max(0.0, $invQty * $invPrice - $invComm);
          elseif ($activity === 'div' || $activity === 'int') $invTotal = abs((float)$txn['amount']);
        ?>
        <tr class="register-row <?= $txn['cleared_status'] ?>" data-id="<?= $txn['id'] ?>"
            onclick="selectTransaction(<?= $txn['id'] ?>)" title="Click to edit">
          <td class="col-date"><?= formatDate($txn['transaction_date']) ?><span class="reg-txnid">#<?= (int)$txn['id'] ?></span></td>
          <td class="col-payee"><div class="payee-name"><?= h($txn['payee']) ?></div></td>
          <td class="col-cat">
            <?php if ($activity): ?>
            <?php
            $actLabel = ['buy'=>'Buy','sell'=>'Sell','add'=>'Add','remove'=>'Remove','split'=>'Split',
                         'reinvest_div'=>'Reinvest Div','reinvest_cap'=>'Reinvest Cap',
                         'div'=>'Dividend','int'=>'Interest'][$activity] ?? ucfirst($activity);
            ?>
            <span class="inv-activity-badge act-<?= h($activity) ?>"><?= h($actLabel) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="col-qty text-end"><?= $invQty > 0 ? number_format($invQty, 4) : '<span class="text-muted">—</span>' ?></td>
          <td class="col-price text-end"><?= ($invPrice > 0 && !in_array($activity, ['add','remove','split'])) ? formatMoney($invPrice) : '<span class="text-muted">—</span>' ?></td>
          <td class="col-inv-total text-end"><?= $invTotal > 0 ? formatMoney($invTotal) : '<span class="text-muted">—</span>' ?></td>
          <td class="col-c"><?= $clearedIcon ?></td>
          <td class="col-memo-inv text-muted small"><?= h($txn['memo'] ?? '') ?></td>
          <?php if (canEdit()): ?>
          <td class="col-actions">
            <?php if ($canDeleteRow): ?>
            <button class="btn-row-delete" title="Delete transaction"
                    onclick="event.stopPropagation(); deleteTransaction(<?= $txn['id'] ?>, '<?= h(addslashes($txn['payee'])) ?>')">
              <i class="bi bi-trash-fill"></i>
            </button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php else: ?>
        <?php
          $amt     = (float)$txn['amount'];
          $payment = $amt < 0 ? abs($amt) : 0;
          $deposit = $amt >= 0 ? $amt : 0;
          $bal     = (float)$txn['balance'];
          $balCls  = round($bal, MONEY_DECIMALS) < 0 ? 'neg' : 'pos';
          if ($txn['is_split']) {
              $catDisplay = '<em>-- Split --</em>';
          } elseif ($txn['type'] === 'transfer' && $txn['paired_account_name']) {
              $catDisplay = '<span class="transfer-label"><i class="bi bi-arrow-left-right"></i> ' . h($txn['paired_account_name']) . '</span>';
          } elseif ($txn['subcategory_name']) {
              $catDisplay = h($txn['category_name'] ?? '') . ' &rsaquo; ' . h($txn['subcategory_name'] ?? '');
          } elseif ($txn['category_name']) {
              $catDisplay = h($txn['category_name']);
          } elseif ($txn['paired_activity']) {
              $catDisplay = '<span class="transfer-label"><i class="bi bi-graph-up-arrow"></i> '
                          . h(investmentActivityLabel($txn['paired_activity'])) . '</span>';
          } else {
              $catDisplay = '<span class="text-muted">—</span>';
          }
          // Cash-side leg of a buy/sell/div/int/reinvest — locked; edit/delete from the security register.
          $isInvReciprocal = !empty($txn['paired_activity']);
        ?>
        <tr class="register-row <?= $txn['cleared_status'] ?><?= $isInvReciprocal ? ' reg-row-locked' : '' ?>" data-id="<?= $txn['id'] ?>"
            data-type="<?= h($txn['type']) ?>" data-cleared="<?= h($txn['cleared_status']) ?>"
            data-payee="<?= h($txn['payee']) ?>"
            onclick="selectTransaction(<?= $txn['id'] ?>)"
            title="<?= $isInvReciprocal ? 'Linked to a security-register entry — click for details' : 'Click to edit' ?>">
          <td class="col-num"><?= h($txn['num']) ?><span class="reg-txnid">#<?= (int)$txn['id'] ?></span></td>
          <td class="col-date"><?= formatDate($txn['transaction_date']) ?></td>
          <td class="col-payee">
            <div class="payee-name"><?= $isInvReciprocal ? '<i class="bi bi-lock-fill text-muted small" title="Linked to security register"></i> ' : '' ?><?= h($txn['payee']) ?></div>
            <?php if ($txn['memo']): ?><div class="txn-memo"><?= h($txn['memo']) ?></div><?php endif; ?>
          </td>
          <td class="col-cat"><?= $catDisplay ?></td>
          <td class="col-c"><?= $clearedIcon ?></td>
          <td class="col-payment text-end"><?= $payment > 0 ? '<span class="amount-debit">' . formatMoney($payment) . '</span>' : '' ?></td>
          <td class="col-deposit text-end"><?= $deposit > 0 ? '<span class="amount-credit">' . formatMoney($deposit) . '</span>' : '' ?></td>
          <td class="col-balance text-end <?= $balCls ?>"><?= formatMoney($bal) ?></td>
          <?php if (canEdit()): ?>
          <td class="col-actions">
            <?php if ($canDeleteRow && !$isInvReciprocal): ?>
            <button class="btn-row-delete" title="Delete transaction"
                    onclick="event.stopPropagation(); deleteTransaction(<?= $txn['id'] ?>, '<?= h(addslashes($txn['payee'])) ?>')">
              <i class="bi bi-trash-fill"></i>
            </button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>

        <?php if (empty($transactions)): ?>
        <tr id="noTransactions">
          <td colspan="<?= canEdit() ? 9 : 8 ?>" class="text-center text-muted py-4">
            No transactions. Use the form below to enter your first transaction.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div><!-- /.register-grid-wrapper -->

  <?php if ($isLoanAccount): ?>
  <div class="alert alert-info d-flex align-items-center gap-3 mt-3">
    <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
    <div>
      Use <a href="<?= BASE_PATH ?>/loans/schedule?id=<?= $id ?>"><strong>Record Payment</strong></a>
      on the Schedule page for automatic principal/interest calculation.
    </div>
  </div>
  <?php elseif (canEdit() && !$registerFormTop && !$isClosed): ?>
  <!-- ── Transaction Entry Form (bottom position) ──────── -->
  <?php include __DIR__ . '/_register_txn_form.php'; ?>
  <?php if (false): ?>
  <div>

    <?php if ($isInvestAccount): ?>
    <!-- Investment: single tab -->
    <div class="txn-tabs" id="txnTabs">
      <button class="txn-tab active" data-tab="investment">
        <i class="bi bi-graph-up-arrow"></i> Transaction
      </button>
    </div>
    <?php elseif ($isAssetAccount): ?>
    <!-- Asset: increase, decrease, transfer tabs -->
    <div class="txn-tabs" id="txnTabs">
      <button class="txn-tab active" data-tab="deposit" onclick="switchTab('deposit')">
        <i class="bi bi-plus-circle"></i> Increase
      </button>
      <button class="txn-tab" data-tab="withdrawal" onclick="switchTab('withdrawal')">
        <i class="bi bi-dash-circle"></i> Decrease
      </button>
      <button class="txn-tab" data-tab="transfer" onclick="switchTab('transfer')">
        <i class="bi bi-arrow-left-right"></i> Transfer
      </button>
    </div>
    <?php else: ?>
    <!-- Standard: three tabs -->
    <div class="txn-tabs" id="txnTabs">
      <button class="txn-tab active" data-tab="withdrawal" onclick="switchTab('withdrawal')">
        <i class="bi bi-dash-circle"></i> <?= $isCreditCard ? 'Credit' : 'Withdrawal' ?>
      </button>
      <button class="txn-tab" data-tab="deposit" onclick="switchTab('deposit')">
        <i class="bi bi-plus-circle"></i> Deposit
      </button>
      <button class="txn-tab" data-tab="transfer" onclick="switchTab('transfer')">
        <i class="bi bi-arrow-left-right"></i> Transfer
      </button>
    </div>
    <?php endif; ?>

    <form id="txnForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="account_id" value="<?= $id ?>">
      <input type="hidden" name="txn_id" id="txnId" value="">
      <input type="hidden" name="type" id="txnType" value="<?= $isInvestAccount ? 'investment' : ($isAssetAccount ? 'deposit' : 'withdrawal') ?>">

      <?php if ($isInvestAccount): ?>
      <!-- ── INVESTMENT tab ────────────────────────────── -->
      <div class="txn-panel" id="panel-investment">
        <div class="inv-form-layout">

          <!-- Left: fixed fields stacked vertically -->
          <div class="inv-left-col">
            <div class="txn-field">
              <label>Date</label>
              <input type="date" name="date_d" id="date_d" class="form-control"
                     value="<?= date('Y-m-d') ?>">
            </div>
            <div class="txn-field">
              <label>Investment</label>
              <div class="input-group input-group-sm">
                <input type="text" name="payee_d" id="payee_d" class="form-control"
                       placeholder="Security or fund name" list="investmentList">
                <?php if (canEdit()): ?>
                <button type="button" class="btn btn-outline-secondary inv-add-btn"
                        title="New investment"
                        onclick="openInvestmentModal(null, function(inv){
                          document.getElementById('payee_d').value = inv.name;
                        })">
                  <i class="bi bi-plus"></i>
                </button>
                <?php endif; ?>
              </div>
            </div>
            <div class="txn-field">
              <label>Activity</label>
              <select name="inv_activity" id="inv_activity" class="form-select" onchange="onActivityChange()">
                <option value="buy">Buy</option>
                <option value="sell">Sell</option>
                <option value="add">Add</option>
                <option value="remove">Remove</option>
                <option value="split">Split</option>
                <option value="reinvest_div">Reinvest Dividend</option>
                <option value="reinvest_cap">Reinvest Cap Gain</option>
              </select>
            </div>
            <div class="txn-field">
              <label>Cleared</label>
              <select name="cleared_d" id="cleared_d" class="form-select">
                <option value="">Not cleared</option>
                <option value="cleared">Cleared (c)</option>
                <?php if (isAdmin()): ?>
                <option value="reconciled">Reconciled (R)</option>
                <?php endif; ?>
              </select>
            </div>
          </div><!-- /.inv-left-col -->

          <!-- Right: activity-specific fields + memo at bottom -->
          <div class="inv-right-col" id="invActivityFields">

            <div class="txn-field inv-detail-field" id="invFieldQty" style="display:none">
              <label>Quantity (Shares)</label>
              <input type="number" name="inv_qty" id="inv_qty" class="form-control"
                     step="0.000001" min="0" placeholder="0" oninput="updateInvTotal()">
            </div>

            <div class="txn-field inv-detail-field" id="invFieldCostBasis" style="display:none">
              <label>Total Cost Basis</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text"><?= MONEY_SYMBOL ?></span>
                <input type="number" name="inv_cost_basis" id="inv_cost_basis" class="form-control"
                       step="0.01" min="0" placeholder="0.00">
              </div>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldPrice" style="display:none">
              <label>Price per Share</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text"><?= MONEY_SYMBOL ?></span>
                <input type="number" name="inv_price" id="inv_price" class="form-control"
                       step="0.000001" min="0" placeholder="0.000000" oninput="updateInvTotal()">
              </div>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldCommission" style="display:none">
              <label>Commission</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text"><?= MONEY_SYMBOL ?></span>
                <input type="number" name="inv_commission" id="inv_commission" class="form-control"
                       step="0.01" min="0" placeholder="0.00" oninput="updateInvTotal()">
              </div>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldTotal" style="display:none">
              <label id="invTotalLabel">Total Cost</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text"><?= MONEY_SYMBOL ?></span>
                <input type="text" name="inv_total" id="inv_total" class="form-control inv-total-field"
                       placeholder="0.00" readonly tabindex="-1">
              </div>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldCashFrom" style="display:none">
              <label>Transfer From (Cash)</label>
              <select name="inv_cash_account_id" id="inv_cash_account_id" class="form-select">
                <?php foreach ($allAccounts as $acc): if ($acc['id'] === $id) continue; ?>
                <option value="<?= $acc['id'] ?>"
                        <?= ($linkedAccount && $acc['id'] == $linkedAccount['id']) ? 'selected' : '' ?>>
                  <?= h($acc['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldCashTo" style="display:none">
              <label>Transfer To (Cash)</label>
              <select name="inv_cash_account_to_id" id="inv_cash_account_to_id" class="form-select">
                <?php foreach ($allAccounts as $acc): if ($acc['id'] === $id) continue; ?>
                <option value="<?= $acc['id'] ?>"
                        <?= ($linkedAccount && $acc['id'] == $linkedAccount['id']) ? 'selected' : '' ?>>
                  <?= h($acc['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

          </div><!-- /.inv-right-col -->

        </div><!-- /.inv-form-layout -->

        <div class="txn-field inv-memo-field">
          <label>Memo</label>
          <input type="text" name="memo_d" id="memo_d" class="form-control"
                 placeholder="Optional note">
        </div>
      </div>
      <?php else: ?>
      <!-- ── WITHDRAWAL tab ─────────────────────────────── -->
      <div class="txn-panel <?= $isAssetAccount ? 'hidden' : '' ?>" id="panel-withdrawal">
        <div class="txn-fields">
          <div class="txn-field field-num">
            <label><?= $isAssetAccount ? 'Reference' : 'Number' ?></label>
            <input type="text" name="num_w" id="num_w" class="form-control" placeholder="e.g. 1001, EFT">
          </div>
          <div class="txn-field field-date">
            <label>Date</label>
            <input type="date" name="date_w" id="date_w" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="txn-field field-payee">
            <label>Pay To</label>
            <input type="text" name="payee_w" id="payee_w" class="form-control" placeholder="Payee name" list="payeeList">
          </div>
          <div class="txn-field field-amount">
            <label>Amount</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input type="number" name="amount_w" id="amount_w" class="form-control" step="0.01" min="0" placeholder="0.00" oninput="updateSplitTotal('w')">
            </div>
          </div>
        </div>
        <div class="txn-fields">
          <div class="txn-field field-cat">
            <label>Category</label>
            <select name="category_w" id="category_w" class="form-select" onchange="loadSubcategories('w')">
              <option value="">-- Select Category --</option>
              <?php foreach (['expense' => 'EXPENSES', 'income' => 'INCOME', 'transfer' => 'TRANSFERS'] as $ctype => $clabel): ?>
              <?php if (!empty($categoriesByType[$ctype])): ?>
              <optgroup label="<?= $clabel ?>">
                <?php foreach ($categoriesByType[$ctype] as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="txn-field field-subcat">
            <label>Subcategory</label>
            <select name="subcategory_w" id="subcategory_w" class="form-select">
              <option value="">-- None --</option>
            </select>
          </div>
          <div class="txn-field field-memo">
            <label>Memo</label>
            <input type="text" name="memo_w" id="memo_w" class="form-control" placeholder="Optional memo">
          </div>
          <div class="txn-field field-cleared">
            <label>Cleared</label>
            <select name="cleared_w" id="cleared_w" class="form-select">
              <option value="">Not cleared</option>
              <option value="cleared">Cleared (c)</option>
              <?php if (isAdmin()): ?>
              <option value="reconciled">Reconciled (R)</option>
              <?php endif; ?>
            </select>
          </div>
        </div>

        <!-- Split section -->
        <div class="split-section" id="splitSection_w">
          <div class="split-header">
            <span class="split-label"><i class="bi bi-diagram-3"></i> Split Categories</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addSplitRow('w')">
              <i class="bi bi-plus"></i> Add Split
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger ms-1" onclick="clearSplits('w')">
              <i class="bi bi-x"></i> Clear Splits
            </button>
          </div>
          <div id="splitRows_w"></div>
        </div>
      </div>

      <!-- ── DEPOSIT tab ─────────────────────────────────── -->
      <div class="txn-panel <?= $isAssetAccount ? '' : 'hidden' ?>" id="panel-deposit">
        <div class="txn-fields">
          <div class="txn-field field-num">
            <label><?= $isAssetAccount ? 'Reference' : 'Number' ?></label>
            <input type="text" name="num_d" id="num_d" class="form-control" placeholder="e.g. DEP">
          </div>
          <div class="txn-field field-date">
            <label>Date</label>
            <input type="date" name="date_d" id="date_d" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="txn-field field-payee">
            <label>From</label>
            <input type="text" name="payee_d" id="payee_d" class="form-control" placeholder="Source / Payer" list="payeeList">
          </div>
          <div class="txn-field field-amount">
            <label>Amount</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input type="number" name="amount_d" id="amount_d" class="form-control" step="0.01" min="0" placeholder="0.00" oninput="updateSplitTotal('d')">
            </div>
          </div>
        </div>
        <div class="txn-fields">
          <div class="txn-field field-cat">
            <label>Category</label>
            <select name="category_d" id="category_d" class="form-select" onchange="loadSubcategories('d')">
              <option value="">-- Select Category --</option>
              <?php foreach (['expense' => 'EXPENSES', 'income' => 'INCOME', 'transfer' => 'TRANSFERS'] as $ctype => $clabel): ?>
              <?php if (!empty($categoriesByType[$ctype])): ?>
              <optgroup label="<?= $clabel ?>">
                <?php foreach ($categoriesByType[$ctype] as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="txn-field field-subcat">
            <label>Subcategory</label>
            <select name="subcategory_d" id="subcategory_d" class="form-select">
              <option value="">-- None --</option>
            </select>
          </div>
          <div class="txn-field field-memo">
            <label>Memo</label>
            <input type="text" name="memo_d" id="memo_d" class="form-control" placeholder="Optional memo">
          </div>
          <div class="txn-field field-cleared">
            <label>Cleared</label>
            <select name="cleared_d" id="cleared_d" class="form-select">
              <option value="">Not cleared</option>
              <option value="cleared">Cleared (c)</option>
              <?php if (isAdmin()): ?>
              <option value="reconciled">Reconciled (R)</option>
              <?php endif; ?>
            </select>
          </div>
        </div>

        <!-- Split section deposit -->
        <div class="split-section" id="splitSection_d">
          <div class="split-header">
            <span class="split-label"><i class="bi bi-diagram-3"></i> Split Categories</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addSplitRow('d')">
              <i class="bi bi-plus"></i> Add Split
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger ms-1" onclick="clearSplits('d')">
              <i class="bi bi-x"></i> Clear Splits
            </button>
          </div>
          <div id="splitRows_d"></div>
        </div>
      </div>

      <!-- ── TRANSFER tab ────────────────────────────────── -->
      <div class="txn-panel hidden" id="panel-transfer">
        <div class="txn-fields">
          <div class="txn-field field-num">
            <label><?= $isAssetAccount ? 'Reference' : 'Number' ?></label>
            <input type="text" name="num_t" id="num_t" class="form-control" placeholder="e.g. EFT">
          </div>
          <div class="txn-field field-date">
            <label>Date</label>
            <input type="date" name="date_t" id="date_t" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="txn-field field-acct">
            <label>From Account</label>
            <select name="from_account" id="from_account" class="form-select">
              <?php foreach ($allAccounts as $acc): ?>
              <?php if (isInvestLike($acc['type']) && !$acc['is_investment_cash']) continue; ?>
              <option value="<?= $acc['id'] ?>" <?= $acc['id'] == $id ? 'selected' : '' ?>><?= h($acc['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="txn-field field-acct">
            <label>To Account</label>
            <select name="to_account" id="to_account" class="form-select">
              <?php foreach ($allAccounts as $acc): ?>
              <?php if (isInvestLike($acc['type']) && !$acc['is_investment_cash']) continue; ?>
              <option value="<?= $acc['id'] ?>" <?= $acc['id'] != $id ? 'selected' : '' ?>><?= h($acc['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="txn-fields">
          <div class="txn-field field-payee">
            <label>Pay To (optional)</label>
            <input type="text" name="payee_t" id="payee_t" class="form-control" placeholder="Payee (optional)" list="payeeList">
          </div>
          <div class="txn-field field-amount">
            <label>Amount</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input type="number" name="amount_t" id="amount_t" class="form-control" step="0.01" min="0" placeholder="0.00">
            </div>
          </div>
          <div class="txn-field field-memo">
            <label>Memo</label>
            <input type="text" name="memo_t" id="memo_t" class="form-control" placeholder="Optional memo">
          </div>
          <div class="txn-field field-cleared">
            <label>Cleared</label>
            <select name="cleared_t" id="cleared_t" class="form-select">
              <option value="">Not cleared</option>
              <option value="cleared">Cleared (c)</option>
              <?php if (isAdmin()): ?>
              <option value="reconciled">Reconciled (R)</option>
              <?php endif; ?>
            </select>
          </div>
        </div>
      </div>

      <?php endif; // end !$isInvestAccount ?>

      <!-- ── Form buttons ────────────────────────────────── -->
      <div class="txn-form-actions">
        <button type="button" class="btn btn-primary" onclick="submitTransaction()">
          <i class="bi bi-check-lg"></i> Enter
        </button>
        <button type="button" class="btn btn-outline-secondary ms-2" onclick="cancelTransaction()">
          <i class="bi bi-x-lg"></i> Cancel
        </button>
        <button type="button" class="btn btn-danger ms-2" id="btnDeleteTxn"
                style="display:none" onclick="deleteCurrentTransaction()">
          <i class="bi bi-trash"></i> Delete
        </button>
        <span class="txn-status ms-3" id="txnStatus"></span>
      </div>
    </form>
  </div>
  <?php endif; // end if (false) — dead code block ?>
  <?php endif; // isLoanAccount | (canEdit && !registerFormTop) ?>

</div><!-- /.register-page -->

<!-- Payee datalist (cross-account, for autocomplete + category suggestions) -->
<datalist id="payeeList">
  <?php
  $db = getDB();
  foreach ($db->query(
      'SELECT t.payee, COUNT(*) AS freq, MAX(t.transaction_date) AS last_used
       FROM transactions t JOIN accounts a ON a.id = t.account_id
       WHERE t.payee != "" AND a.type != "Investment"
       GROUP BY t.payee
       ORDER BY freq DESC, last_used DESC'
  )->fetchAll() as $p):
  ?>
  <option value="<?= h($p['payee']) ?>">
  <?php endforeach; ?>
</datalist>

<!-- Category data for JS -->
<script>
const ACCOUNT_ID   = <?= $id ?>;
const BASE_PATH    = '<?= BASE_PATH ?>';
const IS_ADMIN     = <?= isAdmin() ? 'true' : 'false' ?>;
const CAN_DELETE   = <?= canDelete() ? 'true' : 'false' ?>;
const CAN_EDIT     = <?= canEdit()   ? 'true' : 'false' ?>;
const CSRF_TOKEN   = '<?= h(csrfToken()) ?>';
const categoryData = <?= json_encode($categoryHierarchy, JSON_HEX_TAG) ?>;

// ── Sort + show-all preference persistence ────────────────────
(function () {
  const SORT_KEY    = 'reg_sort_'    + ACCOUNT_ID;
  const SHOW_ALL_KEY = 'reg_showAll_' + ACCOUNT_ID;
  const params      = new URLSearchParams(window.location.search);
  let needsRedirect = false;

  // Sort preference
  if (!params.has('sort')) {
    try {
      const saved = localStorage.getItem(SORT_KEY);
      if (saved) {
        const pref = JSON.parse(saved);
        params.set('sort', pref.col);
        params.set('dir', pref.dir);
        needsRedirect = true;
      }
    } catch (e) {}
  } else {
    try {
      localStorage.setItem(SORT_KEY, JSON.stringify({
        col: params.get('sort'),
        dir: params.get('dir') || 'asc'
      }));
    } catch (e) {}
  }

  // Show-all preference (only apply on initial page load without ?all param)
  if (!params.has('all')) {
    try {
      const showAll = localStorage.getItem(SHOW_ALL_KEY) === '1';
      if (showAll) {
        params.set('all', '1');
        needsRedirect = true;
      }
    } catch (e) {}
  } else {
    try {
      localStorage.setItem(SHOW_ALL_KEY, params.get('all') === '1' ? '1' : '0');
    } catch (e) {}
  }

  if (needsRedirect) {
    window.location.replace(window.location.pathname + '?' + params.toString());
    return;
  }

  // Wire up the show-all checkbox
  const toggle = document.getElementById('showAllToggle');
  if (toggle) {
    toggle.addEventListener('change', function () {
      try { localStorage.setItem(SHOW_ALL_KEY, this.checked ? '1' : '0'); } catch (e) {}
      const p = new URLSearchParams(window.location.search);
      if (this.checked) {
        p.set('all', '1');
      } else {
        p.delete('all');
      }
      window.location.href = window.location.pathname + '?' + p.toString();
    });
  }

  // Show Txn # preference (global, applies across all accounts — pure display toggle, no reload needed)
  const TXNID_KEY   = 'reg_showTxnId';
  const txnIdToggle = document.getElementById('showTxnIdToggle');
  const grid        = document.getElementById('registerGrid');
  let showTxnId     = false;
  try { showTxnId = localStorage.getItem(TXNID_KEY) === '1'; } catch (e) {}
  if (txnIdToggle) txnIdToggle.checked = showTxnId;
  if (grid) grid.classList.toggle('show-txnid', showTxnId);
  if (txnIdToggle) {
    txnIdToggle.addEventListener('change', function () {
      try { localStorage.setItem(TXNID_KEY, this.checked ? '1' : '0'); } catch (e) {}
      if (grid) grid.classList.toggle('show-txnid', this.checked);
    });
  }
})();
<?php if (!$isInvestAccount):
  // Most-recent category per payee across all accounts (non-split transactions only)
  $pcRows = $db->query(
      'SELECT t.payee, ts.category_id, ts.subcategory_id
       FROM transactions t
       JOIN transaction_splits ts ON ts.transaction_id = t.id
       JOIN accounts a ON a.id = t.account_id
       WHERE t.payee != \'\' AND t.is_split = 0 AND ts.category_id IS NOT NULL
         AND a.type != \'Investment\'
       ORDER BY t.transaction_date DESC, t.id DESC'
  )->fetchAll();
  $payeeCatMap = [];
  foreach ($pcRows as $pcRow) {
      if (!isset($payeeCatMap[$pcRow['payee']])) {
          $payeeCatMap[$pcRow['payee']] = [
              'cat'    => (int)$pcRow['category_id'],
              'subcat' => (int)$pcRow['subcategory_id'],
          ];
      }
  }
  // Payee profiles override transaction history when a default category is set
  foreach ($db->query('SELECT name, category_id, subcategory_id FROM payees WHERE category_id IS NOT NULL')->fetchAll() as $pp) {
      $payeeCatMap[$pp['name']] = ['cat' => (int)$pp['category_id'], 'subcat' => (int)$pp['subcategory_id']];
  }
?>
const PAYEE_CATEGORIES = <?= json_encode($payeeCatMap, JSON_HEX_TAG) ?>;
<?php else: ?>
const PAYEE_CATEGORIES = {};
<?php endif; ?>
</script>

<?php if ($isInvestAccount):
// Current share holdings keyed by investment_id (matches getInvestmentHoldings() logic)
$_holdings = [];
foreach ($transactions as $_t) {
    $iid = (int)($_t['investment_id'] ?? 0);
    if (!$iid) continue;
    $act = $_t['activity'] ?? '';
    $q   = (float)($_t['quantity'] ?? 0);
    if (!isset($_holdings[$iid])) $_holdings[$iid] = 0.0;
    if (in_array($act, ['buy','add','split','reinvest_div','reinvest_cap'])) $_holdings[$iid] += $q;
    if ($act === 'sell' || $act === 'remove')                                $_holdings[$iid] -= $q;
}
// Map investment name → id for JS lookup
$_invIdByName = array_column($allInvestments, 'id', 'name');
?>
<!-- Investment account: activity fields + overrides for money.js functions -->
<script>
const HOLDINGS = <?= json_encode($_holdings, JSON_HEX_TAG) ?>;
const INV_ID_BY_NAME = <?= json_encode($_invIdByName, JSON_HEX_TAG) ?>;
let _editingTxnId = 0, _editOrigActivity = '', _editOrigQty = 0, _editOrigInvId = 0;
function onActivityChange() {
  const activity  = document.getElementById('inv_activity').value;
  const showMap   = {
    buy:          ['invFieldQty','invFieldPrice','invFieldCommission','invFieldTotal','invFieldCashFrom'],
    sell:         ['invFieldQty','invFieldPrice','invFieldCommission','invFieldTotal','invFieldCashTo'],
    add:          ['invFieldQty','invFieldCostBasis'],
    remove:       ['invFieldQty'],
    split:        ['invFieldQty'],
    reinvest_div: ['invFieldQty','invFieldPrice','invFieldCommission','invFieldTotal'],
    reinvest_cap: ['invFieldQty','invFieldPrice','invFieldCommission','invFieldTotal'],
    div:          ['invFieldIncomeAmount','invFieldCashFrom'],
    int:          ['invFieldIncomeAmount','invFieldCashFrom'],
  };
  const allFields = ['invFieldQty','invFieldCostBasis','invFieldPrice','invFieldCommission','invFieldTotal','invFieldCashFrom','invFieldCashTo','invFieldIncomeAmount'];
  allFields.forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });
  (showMap[activity] || []).forEach(id => { const el = document.getElementById(id); if (el) el.style.display = ''; });
  const lbl = document.getElementById('invTotalLabel');
  if (lbl) lbl.textContent = activity === 'sell' ? 'Total Proceeds' : 'Total Cost';
  const cashFromLbl = document.getElementById('invCashFromLabel');
  if (cashFromLbl) cashFromLbl.textContent = (activity === 'div' || activity === 'int') ? 'Deposit To (Cash)' : 'Transfer From (Cash)';
  updateInvTotal();
}

function updateInvTotal() {
  const activity   = document.getElementById('inv_activity')?.value;
  const qty        = parseFloat(document.getElementById('inv_qty')?.value)        || 0;
  const price      = parseFloat(document.getElementById('inv_price')?.value)      || 0;
  const commission = parseFloat(document.getElementById('inv_commission')?.value) || 0;
  const totalEl    = document.getElementById('inv_total');
  if (!totalEl) return;
  let total = 0;
  if      (activity === 'buy'  || activity === 'reinvest_div' || activity === 'reinvest_cap') total = qty * price + commission;
  else if (activity === 'sell') total = Math.max(0, qty * price - commission);
  totalEl.value = total > 0
    ? total.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
    : '';
}

// onActivityChange / updateInvTotal are called from inline event handlers
// so must be defined immediately (not deferred). They don't conflict with money.js.

// editTransaction / cancelTransaction / submitTransaction must run AFTER
// money.js loads (footer.php), so wrap them in DOMContentLoaded.
document.addEventListener('DOMContentLoaded', function () {

  editTransaction = function (id, savedScrollY) {
    fetch(BASE_PATH + '/transactions/get?id=' + id)
      .then(r => r.json())
      .then(json => {
        if (!json.ok) { showToast('Error loading transaction.', 'error'); return; }
        const txn = json.txn;
        const inv = json.inv_detail;
        const deleteBtn = document.getElementById('btnDeleteTxn');
        if (deleteBtn) deleteBtn.style.display = (IS_ADMIN || (CAN_DELETE && txn.cleared_status !== 'reconciled')) ? '' : 'none';
        const set = (elId, val) => { const el = document.getElementById(elId); if (el) el.value = val ?? ''; };
        set('txnId',     txn.id);
        set('txnType',   'investment');
        set('date_d',    txn.transaction_date);
        set('payee_d',   txn.payee);
        set('memo_d',    txn.memo);
        set('cleared_d', txn.cleared_status);
        _editingTxnId     = txn.id;
        _editOrigActivity = inv ? (inv.activity || '') : '';
        _editOrigQty      = inv ? (parseFloat(inv.quantity) || 0) : 0;
        _editOrigInvId    = inv ? (parseInt(inv.investment_id) || 0) : 0;
        if (inv) {
          set('inv_activity',   inv.activity);
          onActivityChange();
          set('inv_qty',        inv.quantity);
          set('inv_price',      inv.price);
          set('inv_commission', inv.commission);
          if (inv.activity === 'add') {
            const cb = (parseFloat(inv.quantity) || 0) * (parseFloat(inv.price) || 0);
            set('inv_cost_basis', cb > 0 ? cb.toFixed(2) : '');
          }
          if (inv.activity === 'div' || inv.activity === 'int') {
            set('inv_income_amount', Math.abs(parseFloat(txn.amount) || 0).toFixed(2));
          }
          updateInvTotal();
          if (json.cash_account_id) {
            if (inv.activity === 'buy')  set('inv_cash_account_id',    json.cash_account_id);
            if (inv.activity === 'sell') set('inv_cash_account_to_id', json.cash_account_id);
            if (inv.activity === 'div' || inv.activity === 'int') set('inv_cash_account_id', json.cash_account_id);
          }
        } else {
          onActivityChange();
        }
        document.getElementById('txnFormTitle').textContent = 'Edit Transaction #' + txn.id;
        if (savedScrollY !== undefined) {
          window.scrollTo({ top: savedScrollY, behavior: 'instant' });
        } else {
          const wrapper = document.getElementById('txnFormWrapper');
          if (wrapper) wrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      })
      .catch(e => showToast('Error loading transaction: ' + e.message, 'error'));
  };

  cancelTransaction = function () {
    document.querySelectorAll('.register-row.selected').forEach(r => r.classList.remove('selected'));
    const deleteBtn = document.getElementById('btnDeleteTxn');
    if (deleteBtn) deleteBtn.style.display = 'none';
    document.getElementById('txnForm')?.reset();
    document.getElementById('txnId').value   = '';
    document.getElementById('txnType').value = 'investment';
    document.getElementById('date_d').value  = new Date().toISOString().split('T')[0];
    document.getElementById('txnFormTitle').textContent = 'New Transaction';
    document.getElementById('txnStatus').innerHTML = '';
    _editingTxnId = 0; _editOrigActivity = ''; _editOrigQty = 0; _editOrigInvId = 0;
    onActivityChange();
  };

  submitTransaction = function () {
    const dateVal    = document.getElementById('date_d')?.value;
    const invVal     = document.getElementById('payee_d')?.value?.trim();
    const activity   = document.getElementById('inv_activity')?.value;
    const needsQty   = ['buy','sell','add','remove','split','reinvest_div','reinvest_cap'].includes(activity);
    const needsPrice = ['buy','sell','reinvest_div','reinvest_cap'].includes(activity);
    if (!dateVal) { showStatus('Please enter a date.',       'error'); document.getElementById('date_d')?.focus();  return; }
    if (!invVal)  { showStatus('Please enter an investment.','error'); document.getElementById('payee_d')?.focus(); return; }
    if (activity === 'div' || activity === 'int') {
      const amt = parseFloat(document.getElementById('inv_income_amount')?.value || 0);
      if (amt <= 0) { showStatus('Please enter an income amount.', 'error'); document.getElementById('inv_income_amount')?.focus(); return; }
    }
    if (needsQty) {
      const qty = parseFloat(document.getElementById('inv_qty')?.value || 0);
      if (qty <= 0) { showStatus('Please enter a quantity.', 'error'); document.getElementById('inv_qty')?.focus(); return; }
      if (activity === 'sell' || activity === 'remove') {
        // Holdings in HOLDINGS already reflects the page's current state.
        // When editing a sell/remove, add back the original qty so we compare against
        // "what would be available if this transaction didn't exist yet".
        const addBack   = (_editingTxnId && (_editOrigActivity === 'sell' || _editOrigActivity === 'remove')) ? _editOrigQty : 0;
        const invId     = _editingTxnId ? _editOrigInvId : (INV_ID_BY_NAME[invVal] || 0);
        const available = (HOLDINGS[invId] || 0) + addBack;
        if (qty > available + 0.000001) {
          const fmt  = n => parseFloat(n.toFixed(6)).toString();
          const verb = activity === 'sell' ? 'sell' : 'remove';
          showStatus(
            'Cannot ' + verb + ' ' + fmt(qty) + ' shares — current holding: ' + fmt(available) + ' shares.',
            'error'
          );
          document.getElementById('inv_qty')?.focus();
          return;
        }
      }
    }
    if (needsPrice) {
      const price = parseFloat(document.getElementById('inv_price')?.value || 0);
      if (price <= 0) { showStatus('Please enter a price per share.','error'); document.getElementById('inv_price')?.focus(); return; }
    }
    showStatus('<i class="bi bi-arrow-repeat spin"></i> Saving…', 'info');
    fetch(BASE_PATH + '/transactions/save', { method: 'POST', body: new FormData(document.getElementById('txnForm')) })
      .then(r => r.json())
      .then(json => {
        if (json.ok) {
          playCashRegister();
          showStatus('<i class="bi bi-check-circle-fill text-success"></i> Saved!', 'success');
          setTimeout(() => location.reload(), 600);
        } else {
          showStatus('<i class="bi bi-exclamation-triangle-fill text-danger"></i> ' + escHtml(json.error || 'Error'), 'error');
        }
      })
      .catch((e) => { console.error(e); showStatus('Network error.', 'error'); });
  };

}); // DOMContentLoaded

onActivityChange(); // initialise right-column fields immediately (DOM already rendered)

// ── Old-date warning ───────────────────────────────────────────
(function () {
  const cutoff = new Date();
  cutoff.setFullYear(cutoff.getFullYear() - 1);

  function checkDate(input) {
    const txnId = document.getElementById('txnId');
    if (txnId && txnId.value) return; // editing existing — no warning
    const existing = input.parentElement.querySelector('.old-date-warn');
    if (existing) existing.remove();
    if (!input.value) return;
    const chosen = new Date(input.value + 'T00:00:00');
    if (chosen < cutoff) {
      const warn = document.createElement('div');
      warn.className = 'old-date-warn';
      warn.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Date is over a year old — is this correct?';
      input.parentElement.appendChild(warn);
    }
  }

  ['date_w', 'date_d', 'date_t'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', () => checkDate(el));
  });
})();
</script>
<?php endif; ?>

<!-- Delete transaction form -->
<form id="deleteTxnForm" method="post" action="<?= BASE_PATH ?>/transactions/delete" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="deleteTxnId">
  <input type="hidden" name="account_id" value="<?= $id ?>">
</form>

<!-- Delete confirmation modal -->
<div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content confirm-modal">
      <div class="modal-header confirm-modal-header">
        <h5 class="modal-title" id="confirmDeleteTitle">
          <i class="bi bi-exclamation-triangle-fill"></i> Delete Transaction
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body confirm-modal-body">
        <p id="confirmDeleteMsg"></p>
        <p class="confirm-warning">This action cannot be undone.</p>
      </div>
      <div class="modal-footer confirm-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-lg"></i> Cancel
        </button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
          <i class="bi bi-trash"></i> Delete
        </button>
      </div>
    </div>
  </div>
</div>

<?php if (!$isInvestAccount): ?>
<!-- Right-click context menu -->
<div id="txnContextMenu" class="txn-context-menu" style="display:none" role="menu">
  <button class="ctx-item" id="ctxMarkCleared">
    <i class="bi bi-check-circle"></i> Mark as Cleared
  </button>
  <button class="ctx-item" id="ctxMarkNotCleared">
    <i class="bi bi-circle"></i> Mark as Not Cleared
  </button>
  <div class="ctx-sep" id="ctxSep1"></div>
  <button class="ctx-item" id="ctxCopyTo">
    <i class="bi bi-copy"></i> Copy to Account&hellip;
  </button>
  <button class="ctx-item" id="ctxFlip">
    <i class="bi bi-arrow-left-right"></i> Flip Debit / Credit
  </button>
  <div class="ctx-sep" id="ctxSep2"></div>
  <button class="ctx-item ctx-item-danger" id="ctxDelete">
    <i class="bi bi-trash"></i> Delete
  </button>
</div>

<!-- Copy to account modal -->
<div class="modal fade" id="copyTxnModal" tabindex="-1" aria-labelledby="copyTxnModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="copyTxnModalTitle">
          <i class="bi bi-copy"></i> Copy Transaction
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-2">Copying: <strong id="copyTxnPayeeLabel"></strong></p>
        <label class="form-label" for="copyTargetAccount">Copy to account:</label>
        <select id="copyTargetAccount" class="form-select form-select-sm">
          <?php foreach ($allAccounts as $acc):
            if ($acc['id'] == $id) continue;
            if (isInvestLike($acc['type']) && !$acc['is_investment_cash']) continue;
          ?>
          <option value="<?= $acc['id'] ?>"><?= h($acc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" id="btnConfirmCopy">
          <i class="bi bi-copy"></i> Copy
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($isInvestAccount): ?>
<datalist id="investmentList">
  <?php foreach ($allInvestments as $inv): ?>
  <option value="<?= h($inv['name']) ?>"><?= $inv['symbol'] ? h($inv['symbol']) : '' ?></option>
  <?php endforeach; ?>
</datalist>
<?php include __DIR__ . '/../includes/investment_modal.php'; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('txnForm');
  if (!form) return;
  form.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || e.shiftKey) return;
    const tag = e.target.tagName;
    if (tag === 'SELECT' || tag === 'TEXTAREA' || tag === 'BUTTON') return;
    e.preventDefault();
    submitTransaction();
  });
});
<?php if ($txnAutoOpen): ?>
document.addEventListener('DOMContentLoaded', function () {
  const row = document.querySelector('.register-row[data-id="<?= $txnAutoOpen ?>"]');
  if (!row) return;
  selectTransaction(<?= $txnAutoOpen ?>);
  row.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
<?php endif; ?>
</script>

<?php if (!$isInvestAccount): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!CAN_EDIT) return;
  const menu = document.getElementById('txnContextMenu');
  if (!menu) return;

  let _ctxId = 0, _ctxType = '', _ctxCleared = '', _ctxPayee = '';

  // ── Separator visibility ─────────────────────────────────
  function refreshSeps() {
    ['ctxSep1', 'ctxSep2'].forEach(function (sepId) {
      const sep = document.getElementById(sepId);
      if (!sep) return;
      let before = false, after = false;
      let el = sep.previousElementSibling;
      while (el) { if (!el.classList.contains('ctx-sep') && el.style.display !== 'none') { before = true; break; } el = el.previousElementSibling; }
      el = sep.nextElementSibling;
      while (el) { if (!el.classList.contains('ctx-sep') && el.style.display !== 'none') { after = true; break; } el = el.nextElementSibling; }
      sep.style.display = (before && after) ? '' : 'none';
    });
  }

  // ── Show menu at cursor ──────────────────────────────────
  function showMenu(e, row) {
    e.preventDefault();
    _ctxId      = parseInt(row.dataset.id);
    _ctxType    = row.dataset.type    || '';
    _ctxCleared = row.dataset.cleared || '';
    _ctxPayee   = row.dataset.payee   || '';

    const isTransfer   = _ctxType === 'transfer';
    const isReconciled = _ctxCleared === 'reconciled';
    const isCleared    = _ctxCleared === 'cleared';
    const canDel       = CAN_DELETE && (IS_ADMIN || !isReconciled);

    const show = (id, visible) => { const el = document.getElementById(id); if (el) el.style.display = visible ? '' : 'none'; };
    show('ctxMarkCleared',    !isCleared && !isReconciled);
    show('ctxMarkNotCleared', isCleared || (isReconciled && IS_ADMIN));
    show('ctxCopyTo',         !isTransfer);
    show('ctxFlip',           !isTransfer && !isReconciled);
    show('ctxDelete',         canDel);
    refreshSeps();

    menu.style.left    = e.clientX + 'px';
    menu.style.top     = e.clientY + 'px';
    menu.style.display = 'block';
    requestAnimationFrame(function () {
      const r = menu.getBoundingClientRect();
      if (r.right  > window.innerWidth)  menu.style.left = (e.clientX - r.width)  + 'px';
      if (r.bottom > window.innerHeight) menu.style.top  = (e.clientY - r.height) + 'px';
    });
  }

  function hideMenu() { menu.style.display = 'none'; }

  const body = document.getElementById('registerBody');
  if (body) {
    body.addEventListener('contextmenu', function (e) {
      const row = e.target.closest('.register-row');
      if (!row) return;
      showMenu(e, row);
    });
  }
  document.addEventListener('click',   hideMenu);
  document.addEventListener('scroll',  hideMenu, true);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hideMenu(); });
  menu.addEventListener('click', function (e) { e.stopPropagation(); });

  // ── Quick AJAX helper ────────────────────────────────────
  function quickPost(action, extraFields, onSuccess) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('action',     action);
    fd.append('id',         _ctxId);
    for (const [k, v] of Object.entries(extraFields)) fd.append(k, v);
    fetch(BASE_PATH + '/transactions/quick_action', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.ok) { showToast(json.error || 'Error', 'error'); return; }
        onSuccess(json);
      })
      .catch(function () { showToast('Network error', 'error'); });
  }

  // ── Mark as Cleared ──────────────────────────────────────
  document.getElementById('ctxMarkCleared').addEventListener('click', function () {
    hideMenu();
    quickPost('set_cleared', { status: 'cleared' }, function () {
      updateRowCleared(_ctxId, 'cleared');
      showToast('Marked as cleared', 'success');
    });
  });

  // ── Mark as Not Cleared ──────────────────────────────────
  document.getElementById('ctxMarkNotCleared').addEventListener('click', function () {
    hideMenu();
    quickPost('set_cleared', { status: '' }, function () {
      updateRowCleared(_ctxId, '');
      showToast('Marked as not cleared', 'success');
    });
  });

  function updateRowCleared(id, status) {
    const row = document.querySelector('.register-row[data-id="' + id + '"]');
    if (!row) return;
    row.classList.remove('cleared', 'reconciled');
    if (status) row.classList.add(status);
    row.dataset.cleared = status;
    const cell = row.querySelector('.col-c');
    if (cell) {
      if (status === 'cleared')    cell.innerHTML = '<span class="cleared-c" title="Cleared">c</span>';
      else if (status === 'reconciled') cell.innerHTML = '<span class="cleared-r" title="Reconciled">R</span>';
      else                         cell.innerHTML = '';
    }
  }

  // ── Flip Debit / Credit ──────────────────────────────────
  document.getElementById('ctxFlip').addEventListener('click', function () {
    hideMenu();
    const fromLabel = _ctxType === 'withdrawal' ? 'withdrawal' : 'deposit';
    const toLabel   = _ctxType === 'withdrawal' ? 'deposit'    : 'withdrawal';
    appConfirm(
      'Flip Transaction',
      'Change "' + _ctxPayee + '" from ' + fromLabel + ' to ' + toLabel + '?',
      'The amount sign will be reversed.',
      function () {
        quickPost('flip_type', {}, function () { location.reload(); });
      },
      'Flip'
    );
  });

  // ── Delete ───────────────────────────────────────────────
  document.getElementById('ctxDelete').addEventListener('click', function () {
    hideMenu();
    deleteTransaction(_ctxId, _ctxPayee);
  });

  // ── Copy to Account ──────────────────────────────────────
  let _copyTxnData = null;

  document.getElementById('ctxCopyTo').addEventListener('click', function () {
    hideMenu();
    fetch(BASE_PATH + '/transactions/get.php?id=' + _ctxId)
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.ok) { showToast('Error loading transaction', 'error'); return; }
        _copyTxnData = json;
        document.getElementById('copyTxnPayeeLabel').textContent = json.txn.payee || '(no payee)';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('copyTxnModal')).show();
      })
      .catch(function () { showToast('Network error', 'error'); });
  });

  document.getElementById('btnConfirmCopy').addEventListener('click', function () {
    if (!_copyTxnData) return;
    const targetId = parseInt(document.getElementById('copyTargetAccount').value);
    if (!targetId) { showToast('Please select an account', 'error'); return; }

    const txn    = _copyTxnData.txn;
    const splits = _copyTxnData.splits || [];
    const type   = txn.type === 'deposit' ? 'deposit' : 'withdrawal';
    const sfx    = type === 'deposit' ? 'd' : 'w';

    const fd = new FormData();
    fd.append('csrf_token',      CSRF_TOKEN);
    fd.append('account_id',      targetId);
    fd.append('txn_id',          '');
    fd.append('type',            type);
    fd.append('date_'   + sfx,   txn.transaction_date);
    fd.append('payee_'  + sfx,   txn.payee   || '');
    fd.append('amount_' + sfx,   Math.abs(parseFloat(txn.amount)).toFixed(2));
    fd.append('memo_'   + sfx,   txn.memo    || '');
    fd.append('num_'    + sfx,   txn.num     || '');
    fd.append('cleared_'+ sfx,   '');

    if (splits.length > 1) {
      splits.forEach(function (sp, i) {
        fd.append('split_cat_'    + sfx + '_' + i, sp.category_id    || '');
        fd.append('split_subcat_' + sfx + '_' + i, sp.subcategory_id || '');
        fd.append('split_amount_' + sfx + '_' + i, Math.abs(parseFloat(sp.amount || 0)).toFixed(2));
        fd.append('split_memo_'   + sfx + '_' + i, sp.memo           || '');
      });
    } else if (splits.length === 1) {
      fd.append('category_'    + sfx, splits[0].category_id    || '');
      fd.append('subcategory_' + sfx, splits[0].subcategory_id || '');
    }

    const btn = document.getElementById('btnConfirmCopy');
    btn.disabled = true;
    fetch(BASE_PATH + '/transactions/save', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        btn.disabled = false;
        bootstrap.Modal.getInstance(document.getElementById('copyTxnModal'))?.hide();
        if (json.ok) {
          showToast('Transaction copied successfully.', 'success');
        } else {
          showToast(json.error || 'Error saving copy', 'error');
        }
      })
      .catch(function () { btn.disabled = false; showToast('Network error', 'error'); });
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
