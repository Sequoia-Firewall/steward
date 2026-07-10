<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/import_helpers.php';
requireLogin();
if (!canImport()) { http_response_code(403); setFlash("error", "Access denied."); header("Location: " . BASE_PATH . "/index"); exit; }

if (empty($_SESSION['import']['rows'])) {
    setFlash('error', 'No import data. Please upload a file first.');
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

$import      = $_SESSION['import'];
$rows        = $import['rows'];
$isInv       = $import['is_investment'];
$isMulti     = $import['is_multi_account'] ?? false;
$stmtType    = $import['statement_type'] ?? 'transaction_history';
$isHoldings  = ($stmtType === 'holdings') && $isInv;
$catMap      = $isHoldings ? [] : ($import['cat_map'] ?? []);
$dupCount    = $isHoldings ? 0 : count(array_filter($rows, fn($r) => $r['is_dup']));
$newCount    = count($rows) - $dupCount;

$newCats  = array_filter($catMap, fn($c) => $c['is_new']);
$showCats = !$isInv && !empty($catMap);

// Linked cash account info for single-account investment imports
$linkedCashName = '';
$hasLinkedCash  = false;
if ($isInv && !$isHoldings) {
    $acctId = (int)$import['account_id'];
    if ($acctId > 0) {
        $lcStmt = getDB()->prepare(
            'SELECT a2.name FROM accounts a1
             JOIN accounts a2 ON a2.id = a1.linked_account_id
             WHERE a1.id = ? AND a1.is_active = 1'
        );
        $lcStmt->execute([$acctId]);
        $lcName = $lcStmt->fetchColumn();
        if ($lcName) { $linkedCashName = $lcName; $hasLinkedCash = true; }
    } elseif (!empty($import['new_account']) && ($import['new_account']['type'] ?? '') === 'Investment') {
        $linkedCashName = ($import['new_account']['name'] ?? '') . ' Cash';
        $hasLinkedCash  = true;
    }
}

// Linked cash account info keyed by account_id, for multi-account investment rows
$linkedCashByAcct = [];
if ($isMulti) {
    foreach ($rows as $row) {
        $acctId = (int)($row['account_id'] ?? 0);
        if (($row['is_investment'] ?? false) && $acctId > 0 && !isset($linkedCashByAcct[$acctId])) {
            $lcQ = getDB()->prepare(
                'SELECT a2.name FROM accounts a1
                 JOIN accounts a2 ON a2.id = a1.linked_account_id
                 WHERE a1.id = ? AND a1.is_active = 1'
            );
            $lcQ->execute([$acctId]);
            $linkedCashByAcct[$acctId] = $lcQ->fetchColumn() ?: '';
        }
    }
}

function investPreviewRouting(array $row, string $linkedCashName, bool $hasLinkedCash): string {
    $actionType = $row['action_type'] ?? '';
    $amount     = (float)($row['amount'] ?? 0);
    $xferAcct   = trim($row['transfer_account'] ?? '');

    [$needsCash, $useXAcct] = investCashRouting($actionType);

    if (!$needsCash) {
        static $reinvest = ['ReinvDiv','ReinvInt','ReinvLg','ReinvMd','ReinvSh'];
        if (in_array($actionType, $reinvest, true)) {
            return '<span class="text-muted small fst-italic">reinvested in fund</span>';
        }
        return '<span class="text-muted small">—</span>';
    }

    if (!$hasLinkedCash) {
        return '<span class="badge bg-warning text-dark">'
             . '<i class="bi bi-exclamation-triangle-fill me-1"></i>No linked cash account</span>';
    }

    if ($useXAcct && $xferAcct === '') {
        return '<span class="badge bg-warning text-dark">'
             . '<i class="bi bi-exclamation-triangle-fill me-1"></i>Transfer account missing</span>';
    }

    $targetAcct = $useXAcct ? $xferAcct : $linkedCashName;
    $amtStr = '';
    if ($amount != 0.0) {
        $cls    = $amount >= 0 ? 'pos' : 'neg';
        $sign   = $amount >= 0 ? '+' : '−';
        $amtStr = ' <span class="font-monospace ' . $cls . '">'
                . $sign . formatMoney(abs($amount)) . '</span>';
    }

    if (in_array($actionType, ['ContribX', 'WithdrwX'], true)) {
        return '<span class="small">' . h($linkedCashName) . $amtStr . '</span>'
             . '<br><span class="text-muted small">↔ ' . h($xferAcct) . '</span>';
    }

    return '<span class="small">' . h($targetAcct) . $amtStr . '</span>';
}

$pageTitle   = 'Preview Import — ' . $import['account_name'];
$currentPage = 'import';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-content">

  <div class="page-header d-flex align-items-center gap-3 mb-3">
    <div>
      <h1 class="mb-0"><i class="bi bi-eye"></i> Preview Import</h1>
      <p class="text-muted mb-0 mt-1">
        <?php if ($isMulti): ?>
        Multi-account import ·
        Format: <strong><?= h($import['format']) ?></strong> ·
        <?= count($rows) ?> total transaction<?= count($rows) !== 1 ? 's' : '' ?>
        <?php if ($dupCount): ?> · <span class="text-warning"><?= $dupCount ?> possible duplicate<?= $dupCount !== 1 ? 's' : '' ?></span><?php endif; ?>
        <?php elseif ($isHoldings): ?>
        Account: <strong><?= h($import['account_name']) ?></strong> ·
        Format: <strong><?= h($import['format']) ?></strong> ·
        <?= count($rows) ?> reconciliation row<?= count($rows) !== 1 ? 's' : '' ?> to apply
        <?php else: ?>
        Account: <strong><?= h($import['account_name']) ?></strong> ·
        Format: <strong><?= h($import['format']) ?></strong> ·
        <?= count($rows) ?> transaction<?= count($rows) !== 1 ? 's' : '' ?> found
        <?php if ($dupCount): ?> · <span class="text-warning"><?= $dupCount ?> possible duplicate<?= $dupCount !== 1 ? 's' : '' ?></span><?php endif; ?>
        <?php endif; ?>
      </p>
    </div>
  </div>

  <?php if (!empty($import['new_account'])): $na = $import['new_account']; ?>
  <div class="alert alert-info py-2">
    <i class="bi bi-plus-circle"></i>
    New account to be created: <strong><?= h($na['name']) ?></strong>
    (<?= h($na['type']) ?><?= $na['institution'] ? ' · ' . h($na['institution']) : '' ?>)
    <?php if (($na['opening_balance'] ?? 0) > 0): ?>
    · Opening balance: <strong><?= '$' . number_format((float)$na['opening_balance'], 2) ?></strong>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($dupCount): ?>
  <div class="alert alert-warning py-2">
    <i class="bi bi-exclamation-triangle"></i>
    <?= $dupCount ?> transaction<?= $dupCount !== 1 ? 's match' : ' matches' ?> an existing record by date, amount, and payee and <?= $dupCount !== 1 ? 'have' : 'has' ?> been pre-deselected. Review and re-check any you still want to import.
  </div>
  <?php endif; ?>

  <?php if (!empty($newCats)): ?>
  <div class="alert alert-info py-2 small">
    <i class="bi bi-tag"></i>
    <strong><?= count($newCats) ?> new <?= count($newCats) === 1 ? 'category' : 'categories' ?> will be created:</strong>
    <?= implode(', ', array_map(fn($c) => '<em>' . h($c['display']) . '</em>', $newCats)) ?>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= BASE_PATH ?>/import/confirm" id="importForm">
    <?= csrfField() ?>
    <input type="hidden" name="total_rows" value="<?= count($rows) ?>">

    <div class="mb-3 d-flex gap-2 align-items-center flex-wrap">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-circle"></i> Import Selected
      </button>
      <a href="<?= BASE_PATH ?>/import/index" class="btn btn-outline-secondary">Cancel</a>
      <?php if ($isMulti): ?>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="btnSelectAll">Select All</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="btnSelectNone">Select None</button>
      <?php else: ?>
      <?php endif; ?>
      <span class="text-muted small ms-1" id="selCount"></span>
    </div>

    <?php if ($isHoldings): ?>
    <!-- ── Holdings reconciliation table ────────────────────────────────────── -->
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle import-preview-table">
        <thead class="table-dark">
          <tr>
            <th style="width:2rem">
              <input type="checkbox" id="checkAll" class="form-check-input" title="Select / deselect all">
            </th>
            <th>Security</th>
            <th class="text-end">Current Qty</th>
            <th class="text-end">Statement Qty</th>
            <th class="text-end">Difference</th>
            <th>Action</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $row): ?>
          <tr>
            <td>
              <input type="checkbox" value="<?= $i ?>"
                     class="form-check-input row-check" checked>
            </td>
            <td>
              <?= h($row['payee']) ?>
              <?php if (!empty($row['symbol'])): ?>
              <span class="text-muted small">(<?= h($row['symbol']) ?>)</span>
              <?php endif; ?>
            </td>
            <td class="text-end font-monospace"><?= number_format((float)($row['holdings_current'] ?? 0), 4) ?></td>
            <td class="text-end font-monospace"><?= number_format((float)($row['holdings_snapshot'] ?? 0), 4) ?></td>
            <td class="text-end font-monospace <?= $row['activity'] === 'add' ? 'pos' : 'neg' ?>">
              <?= $row['activity'] === 'add' ? '+' : '−' ?><?= number_format((float)$row['quantity'], 4) ?>
            </td>
            <td>
              <span class="badge <?= $row['action_type'] === 'ShrsIn' ? 'bg-success' : 'bg-danger' ?>">
                <?= $row['action_type'] === 'ShrsIn' ? 'Add' : 'Remove' ?>
              </span>
              <?php if (!empty($row['price'])): ?>
              <span class="text-muted small ms-1">@ <?= formatMoney($row['price']) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-nowrap"><span class="badge bg-success">New</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php elseif ($isMulti): ?>
    <!-- ── Multi-account grouped tables ─────────────────────────────────────── -->
    <?php
    // Group row indices by account_id
    $acctGroups = [];
    foreach ($rows as $i => $row) {
        $gId = $row['account_id'];
        if (!isset($acctGroups[$gId])) {
            $acctGroups[$gId] = [
                'name'          => $row['account_name'] ?? '',
                'is_investment' => $row['is_investment'] ?? false,
                'indices'       => [],
            ];
        }
        $acctGroups[$gId]['indices'][] = $i;
    }
    ?>
    <?php foreach ($acctGroups as $gId => $group):
        $gIsInv  = $group['is_investment'];
        $gDups   = count(array_filter($group['indices'], fn($i) => $rows[$i]['is_dup']));
        $gLCName = $gIsInv ? ($linkedCashByAcct[$gId] ?? '') : '';
        $gHasLC  = $gLCName !== '';
    ?>
    <h5 class="mt-4 mb-2 fw-semibold border-bottom pb-1">
      <i class="bi bi-<?= $gIsInv ? 'graph-up' : 'bank2' ?>"></i>
      <?= h($group['name']) ?>
      <span class="fw-normal text-muted small ms-2">
        <?= count($group['indices']) ?> transaction<?= count($group['indices']) !== 1 ? 's' : '' ?>
        <?php if ($gDups): ?>· <span class="text-warning"><?= $gDups ?> duplicate<?= $gDups !== 1 ? 's' : '' ?></span><?php endif; ?>
      </span>
    </h5>
    <div class="table-responsive mb-2">
      <table class="table table-sm table-hover align-middle import-preview-table">
        <thead class="table-dark">
          <tr>
            <th style="width:2rem"></th>
            <th>Date</th>
            <?php if ($gIsInv): ?>
            <th>Security</th>
            <th>Action Type</th>
            <th class="text-end">Qty</th>
            <th class="text-end">Price</th>
            <th class="text-end">Commission</th>
            <th>Cash Routing</th>
            <?php else: ?>
            <th>Payee</th>
            <th>Memo</th>
            <th>#</th>
            <?php if ($showCats): ?><th>Category</th><?php endif; ?>
            <th class="text-end">Amount</th>
            <?php endif; ?>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($group['indices'] as $i):
              $row = $rows[$i];
          ?>
          <tr class="<?= $row['is_dup'] ? 'import-dup' : '' ?>">
            <td>
              <input type="checkbox" value="<?= $i ?>"
                     class="form-check-input row-check"<?= $row['is_dup'] ? '' : ' checked' ?>>
            </td>
            <td class="text-nowrap"><?= h(date('m/d/Y', strtotime($row['date']))) ?></td>
            <?php if ($gIsInv): ?>
            <td>
              <?= h($row['payee']) ?>
              <?php if (!empty($row['symbol'])): ?><span class="text-muted small">(<?= h($row['symbol']) ?>)</span><?php endif; ?>
            </td>
            <td>
              <?php $at = $row['action_type'] ?? ''; $lbl = $at !== '' ? actionTypeLabel($at) : ''; ?>
              <?php if ($at === ''): ?>
                <span class="badge bg-warning text-dark">Unknown</span>
              <?php else: ?>
                <?= h($lbl) ?>
                <?php if ($lbl !== $at): ?><br><span class="text-muted font-monospace" style="font-size:.75em"><?= h($at) ?></span><?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="text-end font-monospace"><?= $row['quantity'] ? number_format($row['quantity'], 4) : '—' ?></td>
            <td class="text-end font-monospace"><?= $row['price'] ? formatMoney($row['price']) : '—' ?></td>
            <td class="text-end font-monospace"><?= $row['commission'] ? formatMoney($row['commission']) : '—' ?></td>
            <td style="white-space:normal;min-width:12rem"><?= investPreviewRouting($row, $gLCName, $gHasLC) ?></td>
            <?php else: ?>
            <td><?= h($row['payee']) ?></td>
            <td class="text-muted small"><?= h($row['memo']) ?></td>
            <td class="text-muted small"><?= h($row['num'] ?? '') ?></td>
            <?php if ($showCats): ?>
            <td class="text-muted small">
              <?php
              $splits = $row['splits'] ?? [];
              if (!empty($splits) && count($splits) > 1): ?>
                <span class="badge bg-secondary">Split (<?= count($splits) ?>)</span>
              <?php elseif (!empty($row['is_transfer'])): ?>
                <span class="text-info"><i class="bi bi-arrow-left-right"></i> <?= h($row['transfer_account'] ?? 'Transfer') ?></span>
              <?php elseif (!empty($row['category']) && isset($catMap[$row['category']])): ?>
                <?= h($catMap[$row['category']]['display']) ?>
                <?php if ($catMap[$row['category']]['is_new']): ?>
                <span class="badge bg-info text-dark ms-1" title="New — will be created">new</span>
                <?php endif; ?>
              <?php elseif (!empty($splits) && !empty($splits[0]['category'])): ?>
                <?php $c0 = $catMap[$splits[0]['category']] ?? null; ?>
                <?= $c0 ? h($c0['display']) : h($splits[0]['category']) ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="text-end font-monospace <?= round((float)$row['amount'], MONEY_DECIMALS) < 0 ? 'neg' : 'pos' ?>">
              <?= (float)$row['amount'] < 0 ? '−' : '+' ?><?= formatMoney(abs((float)$row['amount'])) ?>
            </td>
            <?php endif; ?>
            <td class="text-nowrap">
              <?php if ($row['is_dup']): ?>
              <span class="badge bg-warning text-dark">Duplicate</span>
              <?php else: ?>
              <span class="badge bg-success">New</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <!-- ── Single-account transaction table ─────────────────────────────────── -->
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle import-preview-table">
        <thead class="table-dark">
          <tr>
            <th style="width:2rem">
              <input type="checkbox" id="checkAll" class="form-check-input" title="Select / deselect all">
            </th>
            <th>Date</th>
            <?php if ($isInv): ?>
            <th>Security</th>
            <th>Action Type</th>
            <th class="text-end">Qty</th>
            <th class="text-end">Price</th>
            <th class="text-end">Commission</th>
            <?php else: ?>
            <th>Payee</th>
            <th>Memo</th>
            <th>#</th>
            <?php if ($showCats): ?><th>Category</th><?php endif; ?>
            <?php endif; ?>
            <?php if ($isInv): ?><th>Cash Routing</th><?php else: ?><th class="text-end">Amount</th><?php endif; ?>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $i => $row): ?>
          <tr class="<?= $row['is_dup'] ? 'import-dup' : '' ?>">
            <td>
              <input type="checkbox" value="<?= $i ?>"
                     class="form-check-input row-check"<?= $row['is_dup'] ? '' : ' checked' ?>>
            </td>
            <td class="text-nowrap"><?= h(date('m/d/Y', strtotime($row['date']))) ?></td>
            <?php if ($isInv): ?>
            <td>
              <?= h($row['payee']) ?>
              <?php if (!empty($row['symbol'])): ?>
              <span class="text-muted small">(<?= h($row['symbol']) ?>)</span>
              <?php endif; ?>
            </td>
            <td>
              <?php
                $at  = $row['action_type'] ?? '';
                $lbl = $at !== '' ? actionTypeLabel($at) : '';
              ?>
              <?php if ($at === ''): ?>
                <span class="badge bg-warning text-dark">Unknown</span>
              <?php else: ?>
                <?= h($lbl) ?>
                <?php if ($lbl !== $at): ?>
                <br><span class="text-muted font-monospace" style="font-size:.75em"><?= h($at) ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td class="text-end font-monospace"><?= $row['quantity'] ? number_format($row['quantity'], 4) : '—' ?></td>
            <td class="text-end font-monospace"><?= $row['price'] ? formatMoney($row['price']) : '—' ?></td>
            <td class="text-end font-monospace"><?= $row['commission'] ? formatMoney($row['commission']) : '—' ?></td>
            <?php else: ?>
            <td><?= h($row['payee']) ?></td>
            <td class="text-muted small"><?= h($row['memo']) ?></td>
            <td class="text-muted small"><?= h($row['num']) ?></td>
            <?php if ($showCats): ?>
            <td class="text-muted small">
              <?php
              $splits = $row['splits'] ?? [];
              if (!empty($splits) && count($splits) > 1): ?>
                <span class="badge bg-secondary">Split (<?= count($splits) ?>)</span>
              <?php elseif (!empty($row['is_transfer'])): ?>
                <span class="text-info"><i class="bi bi-arrow-left-right"></i> <?= h($row['transfer_account'] ?? 'Transfer') ?></span>
              <?php elseif (!empty($row['category']) && isset($catMap[$row['category']])): ?>
                <?= h($catMap[$row['category']]['display']) ?>
                <?php if ($catMap[$row['category']]['is_new']): ?>
                <span class="badge bg-info text-dark ms-1" title="New — will be created">new</span>
                <?php endif; ?>
              <?php elseif (!empty($splits) && !empty($splits[0]['category'])): ?>
                <?php $c0 = $catMap[$splits[0]['category']] ?? null; ?>
                <?= $c0 ? h($c0['display']) : h($splits[0]['category']) ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($isInv): ?>
            <td style="white-space:normal;min-width:12rem"><?= investPreviewRouting($row, $linkedCashName, $hasLinkedCash) ?></td>
            <?php else: ?>
            <td class="text-end font-monospace <?= round((float)$row['amount'], MONEY_DECIMALS) < 0 ? 'neg' : 'pos' ?>">
              <?= (float)$row['amount'] < 0 ? '−' : '+' ?><?= formatMoney(abs((float)$row['amount'])) ?>
            </td>
            <?php endif; ?>
            <td class="text-nowrap">
              <?php if ($row['is_dup']): ?>
              <span class="badge bg-warning text-dark">Duplicate</span>
              <?php else: ?>
              <span class="badge bg-success">New</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-circle"></i> Import Selected
      </button>
      <a href="<?= BASE_PATH ?>/import/index" class="btn btn-outline-secondary">Cancel</a>
      <span class="text-muted small ms-1" id="selCount2"></span>
    </div>
  </form>
</div>

<style>
.import-dup td { opacity: .65; }
.import-preview-table th, .import-preview-table td { white-space: nowrap; }
</style>

<script>
(function () {
    const checkAll = document.getElementById('checkAll');
    const checks   = Array.from(document.querySelectorAll('.row-check'));
    const c1 = document.getElementById('selCount');
    const c2 = document.getElementById('selCount2');

    function update() {
        const n   = checks.filter(c => c.checked).length;
        const msg = n + ' of <?= count($rows) ?> selected';
        if (c1) c1.textContent = msg;
        if (c2) c2.textContent = msg;
        if (checkAll) {
            checkAll.indeterminate = n > 0 && n < checks.length;
            checkAll.checked       = n === checks.length;
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            checks.forEach(c => c.checked = checkAll.checked);
            update();
        });
    }

    const btnAll  = document.getElementById('btnSelectAll');
    const btnNone = document.getElementById('btnSelectNone');
    if (btnAll)  btnAll.addEventListener('click',  () => { checks.forEach(c => c.checked = true);  update(); });
    if (btnNone) btnNone.addEventListener('click', () => { checks.forEach(c => c.checked = false); update(); });

    checks.forEach(c => c.addEventListener('change', update));
    update();

    // Submit only excluded (unchecked) indices to stay well under max_input_vars
    document.getElementById('importForm').addEventListener('submit', function () {
        checks.forEach(function (c) {
            if (!c.checked) {
                var h = document.createElement('input');
                h.type  = 'hidden';
                h.name  = 'excluded[]';
                h.value = c.value;
                document.getElementById('importForm').appendChild(h);
            }
        });
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
