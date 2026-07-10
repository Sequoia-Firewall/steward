<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$pageTitle      = 'Accounts';
$currentPage    = 'accounts';
$showClosed     = !empty($_GET['show_closed']);
$accounts       = getAllAccountsWithBalance(null, $showClosed);

$reconRows = getDB()->query(
    "SELECT account_id, MAX(transaction_date) AS last_reconciled
     FROM transactions WHERE cleared_status = 'reconciled'
     GROUP BY account_id"
)->fetchAll(PDO::FETCH_KEY_PAIR);  // account_id => last_reconciled

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-wallet2"></i> Accounts</h2>
  <div class="d-flex align-items-center gap-2">
    <a href="<?= BASE_PATH ?>/accounts/index?<?= $showClosed ? '' : 'show_closed=1' ?>"
       class="btn btn-sm <?= $showClosed ? 'btn-secondary' : 'btn-outline-secondary' ?>">
      <i class="bi bi-<?= $showClosed ? 'eye-slash' : 'eye' ?>"></i>
      <?= $showClosed ? 'Hide Closed' : 'Show Closed' ?>
    </a>
    <?php if (canImport()): ?>
    <a href="<?= BASE_PATH ?>/accounts/create" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-circle"></i> New Account
    </a>
    <?php endif; ?>
  </div>
</div>

<?php
$grouped  = [];
$cashById = []; // investment account id => cash account row
foreach ($accounts as $acc) {
    if ($acc['type'] === 'investment-cash') {
        if ($acc['linked_account_id']) {
            $cashById[(int)$acc['linked_account_id']] = $acc;
        }
        continue;
    }
    $grouped[$acc['type']][] = $acc;
}
$icons = ['Checking'=>'bi-bank','Savings'=>'bi-piggy-bank','Credit Card'=>'bi-credit-card','Investment'=>'bi-graph-up-arrow','Crypto'=>'bi-currency-bitcoin','Asset'=>'bi-safe2','Loan'=>'bi-cash-coin'];
?>

<?php foreach ($grouped as $type => $accs): ?>
<?php
  $typeTotal = 0.0;
  foreach ($accs as $_a) {
      $typeTotal += (float)$_a['current_balance'];
      if ($type === 'Investment' && isset($cashById[(int)$_a['id']])) {
          $typeTotal += (float)$cashById[(int)$_a['id']]['current_balance'];
      }
  }
  $typeTotalCls = round($typeTotal, MONEY_DECIMALS) < 0 ? 'amount-debit' : 'amount-credit';
?>
<section class="dash-section">
  <h4 class="section-title">
    <i class="bi <?= $icons[$type] ?? 'bi-wallet2' ?>"></i> <?= h($type) ?>
  </h4>
  <table class="table dash-table acct-index-table">
    <thead>
      <tr>
        <th></th>
        <th>Account Name</th>
        <th>Institution</th>
        <th>Account #</th>
        <th>Currency</th>
        <th class="text-end">Last Reconciled</th>
        <th class="text-end acct-bal-sortable" style="cursor:pointer;user-select:none" title="Click to sort">
          Current Balance <i class="bi bi-arrow-down-up sort-icon"></i>
        </th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($accs as $acc):
        $bal    = (float)$acc['current_balance'];
        $balCls = round($bal, MONEY_DECIMALS) < 0 ? 'amount-debit' : 'amount-credit';
        // For investment accounts include cash balance in sort value
        $sortBal = $bal + (isset($cashById[(int)$acc['id']]) ? (float)$cashById[(int)$acc['id']]['current_balance'] : 0.0);
      ?>
      <tr data-balance="<?= $sortBal ?>"<?= !empty($acc['is_closed']) ? ' class="text-muted"' : '' ?>>
        <td>
          <?php if ($acc['is_favorite']): ?><i class="bi bi-star-fill text-warning" title="Favorite"></i><?php endif; ?>
          <?php if (!empty($acc['hide_from_sidebar'])): ?><i class="bi bi-eye-slash text-secondary" title="Hidden from menu"></i><?php endif; ?>
        </td>
        <td>
          <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $acc['id'] ?>">
            <?= h($acc['name']) ?>
          </a>
          <?php if (!empty($acc['is_closed'])): ?>
          <span class="badge bg-secondary ms-1" style="font-size:.7rem">CLOSED</span>
          <?php endif; ?>
        </td>
        <td><?= h($acc['institution']) ?></td>
        <td><?= $acc['account_number'] ? '****' . substr($acc['account_number'], -4) : '—' ?></td>
        <td><?= h($acc['currency']) ?></td>
        <td class="text-end text-nowrap small text-muted">
          <?php $lastRecon = $reconRows[$acc['id']] ?? $acc['last_reconciled_date'] ?? null; ?>
          <?= $lastRecon ? formatDate($lastRecon) : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end"><span class="<?= $balCls ?>"><?= formatMoney($bal) ?></span></td>
        <td>
          <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $acc['id'] ?>" class="btn btn-sm btn-outline-primary" title="Open Register">
            <i class="bi bi-list-ul"></i>
          </a>
          <?php if (canEdit()): ?>
          <a href="<?= BASE_PATH ?>/accounts/edit?id=<?= $acc['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
            <i class="bi bi-pencil"></i>
          </a>
          <?php endif; ?>
          <?php if (isAdmin()): ?>
          <button class="btn btn-sm btn-outline-danger" title="Delete"
                  onclick="confirmDelete(<?= $acc['id'] ?>, '<?= h(addslashes($acc['name'])) ?>')">
            <i class="bi bi-trash"></i>
          </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php if ($type === 'Investment' && isset($cashById[(int)$acc['id']])):
        $cash    = $cashById[(int)$acc['id']];
        $cashBal = (float)$cash['current_balance'];
        $cashCls = round($cashBal, MONEY_DECIMALS) < 0 ? 'amount-debit' : 'amount-credit';
      ?>
      <tr class="acct-index-cash-row">
        <td></td>
        <td>
          <span class="acct-index-cash-indent">
            <i class="bi bi-cash-coin text-muted"></i>
            <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $cash['id'] ?>">
              <?= h($cash['name']) ?>
            </a>
          </span>
        </td>
        <td><?= h($cash['institution']) ?></td>
        <td><?= $cash['account_number'] ? '****' . substr($cash['account_number'], -4) : '—' ?></td>
        <td><?= h($cash['currency']) ?></td>
        <td class="text-end text-nowrap small text-muted">
          <?php $cashLastRecon = $reconRows[$cash['id']] ?? $cash['last_reconciled_date'] ?? null; ?>
          <?= $cashLastRecon ? formatDate($cashLastRecon) : '<span class="text-muted">—</span>' ?>
        </td>
        <td class="text-end"><span class="<?= $cashCls ?>"><?= formatMoney($cashBal) ?></span></td>
        <td>
          <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $cash['id'] ?>" class="btn btn-sm btn-outline-primary" title="Open Register">
            <i class="bi bi-list-ul"></i>
          </a>
          <?php if (canEdit()): ?>
          <a href="<?= BASE_PATH ?>/accounts/edit?id=<?= $cash['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
            <i class="bi bi-pencil"></i>
          </a>
          <?php endif; ?>
          <?php if (isAdmin()): ?>
          <button class="btn btn-sm btn-outline-danger" title="Delete"
                  onclick="confirmDelete(<?= $cash['id'] ?>, '<?= h(addslashes($cash['name'])) ?>')">
            <i class="bi bi-trash"></i>
          </button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="6" class="text-end fw-semibold">Total</td>
        <td class="text-end fw-semibold"><span class="<?= $typeTotalCls ?>"><?= formatMoney($typeTotal) ?></span></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
</section>
<?php endforeach; ?>

<?php if (isAdmin()): ?>
<form id="deleteForm" method="post" action="<?= BASE_PATH ?>/accounts/delete" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="deleteId">
</form>
<script>
function confirmDelete(id, name) {
  appConfirm(
    'Delete Account',
    'Delete account "' + name + '"?',
    'This will also delete ALL transactions in this account. This cannot be undone.',
    () => {
      document.getElementById('deleteId').value = id;
      document.getElementById('deleteForm').submit();
    },
    'Delete'
  );
}
</script>
<?php endif; ?>

<script>
document.querySelectorAll('.acct-bal-sortable').forEach(th => {
  let dir = null; // null | 'asc' | 'desc'
  const icon = th.querySelector('.sort-icon');

  th.addEventListener('click', () => {
    dir = dir === 'asc' ? 'desc' : 'asc';
    icon.className = 'bi sort-icon ' + (dir === 'asc' ? 'bi-sort-up-alt' : 'bi-sort-down-alt');

    const tbody = th.closest('table').querySelector('tbody');

    // Collect primary rows (not cash sub-rows), each optionally followed by a cash sub-row
    const units = [];
    let i = 0;
    const rows = [...tbody.querySelectorAll('tr')];
    while (i < rows.length) {
      const row = rows[i];
      if (row.classList.contains('acct-index-cash-row')) { i++; continue; }
      const next = rows[i + 1];
      const hasCash = next && next.classList.contains('acct-index-cash-row');
      units.push({ primary: row, cash: hasCash ? next : null });
      i += hasCash ? 2 : 1;
    }

    const sign = dir === 'asc' ? 1 : -1;
    units.sort((a, b) => sign * (parseFloat(a.primary.dataset.balance) - parseFloat(b.primary.dataset.balance)));

    units.forEach(u => {
      tbody.appendChild(u.primary);
      if (u.cash) tbody.appendChild(u.cash);
    });
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
