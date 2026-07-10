<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db    = getDB();
$today = new DateTime();
$mon   = (int)$today->format('n');
$start = $today->format('Y-m-01');
$end   = $today->format('Y-m-t');

$budgets = $db->query(
    "SELECT b.*,
            COUNT(DISTINCT ba.account_id) AS acct_count,
            COUNT(DISTINCT bc.id)         AS cat_count
     FROM budgets b
     LEFT JOIN budget_accounts   ba ON ba.budget_id = b.id
     LEFT JOIN budget_categories bc ON bc.budget_id = b.id
     GROUP BY b.id
     ORDER BY b.name"
)->fetchAll();

// Safety net: normal saves now enforce a single dashboard budget, but flag it
// if more than one is still marked (e.g. leftover from before that was enforced).
$dashBudgets = array_values(array_filter($budgets, fn($b) => $b['show_on_dashboard'] && $b['is_active']));
$dashConflict = null;
if (count($dashBudgets) > 1) {
    usort($dashBudgets, fn($a, $b2) => $a['id'] <=> $b2['id']);
    $winner = array_shift($dashBudgets);
    $dashConflict = [
        'winner' => $winner['name'],
        'others' => array_column($dashBudgets, 'name'),
    ];
}

// Current-month summary per budget
$summaries = [];
foreach ($budgets as $b) {
    $bid = (int)$b['id'];

    $cats = $db->prepare(
        "SELECT bc.id, bc.category_id, bc.entry_type, bc.amount
         FROM budget_categories bc WHERE bc.budget_id = ?"
    );
    $cats->execute([$bid]);
    $catRows = $cats->fetchAll();

    if (empty($catRows)) { $summaries[$bid] = ['budgeted' => 0, 'actual' => 0]; continue; }

    $bcIds = array_column($catRows, 'id');
    $phs   = implode(',', array_fill(0, count($bcIds), '?'));
    $mStmt = $db->prepare(
        "SELECT budget_category_id, amount FROM budget_monthly_amounts
         WHERE budget_category_id IN ($phs) AND month = ?"
    );
    $mStmt->execute([...$bcIds, $mon]);
    $mMap = [];
    foreach ($mStmt->fetchAll() as $r) {
        $mMap[(int)$r['budget_category_id']][$mon] = (float)$r['amount'];
    }

    $totBudgeted = 0;
    foreach ($catRows as $bc) {
        $totBudgeted += getBudgetMonthlyAmount($bc, $mon, $mMap);
    }

    $acctStmt = $db->prepare("SELECT account_id FROM budget_accounts WHERE budget_id = ?");
    $acctStmt->execute([$bid]);
    $acctIds = array_column($acctStmt->fetchAll(), 'account_id');

    $totActual = 0;
    if (!empty($acctIds)) {
        $catIds = array_column($catRows, 'category_id');
        $aPhs   = implode(',', array_fill(0, count($acctIds), '?'));
        $cPhs   = implode(',', array_fill(0, count($catIds), '?'));
        $aStmt  = $db->prepare(
            "SELECT ABS(SUM(ts.amount)) AS actual
             FROM transaction_splits ts
             JOIN transactions t ON t.id = ts.transaction_id
             WHERE t.transaction_date BETWEEN ? AND ?
               AND t.account_id IN ($aPhs)
               AND ts.category_id IN ($cPhs)"
        );
        $aStmt->execute([$start, $end, ...$acctIds, ...$catIds]);
        $totActual = (float)($aStmt->fetchColumn() ?? 0);
    }

    $summaries[$bid] = ['budgeted' => $totBudgeted, 'actual' => $totActual];
}

$pageTitle   = 'Budgets';
$currentPage = 'budget';

// Labels for auto-generate modal
$_prevMonObj   = (new DateTime())->modify('first day of last month');
$_prevMonLabel = $_prevMonObj->format('F Y');

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-bar-chart-line"></i> Budgets</h2>
  <?php if (canManageBudgets()): ?>
  <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#autoGenModal">
    <i class="bi bi-stars"></i> Auto-Generate
  </button>
  <a href="<?= BASE_PATH ?>/budget/create" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg"></i> New Budget
  </a>
  <?php endif; ?>
</div>

<?php if ($dashConflict): ?>
<div class="alert alert-warning d-flex align-items-start gap-2">
  <i class="bi bi-exclamation-triangle-fill mt-1"></i>
  <div>
    <strong>Multiple budgets are set to feed the dashboard widget.</strong>
    Only <strong><?= h($dashConflict['winner']) ?></strong> (the oldest one) is actually used —
    <?= h(implode(', ', $dashConflict['others'])) ?> <?= count($dashConflict['others']) === 1 ? 'is' : 'are' ?> ignored.
    Edit those budgets and turn off "Use this budget for the dashboard widget" if that wasn't intended.
  </div>
</div>
<?php endif; ?>

<?php if (empty($budgets)): ?>
<div class="text-center text-muted mt-5">
  <i class="bi bi-bar-chart-line" style="font-size:3rem;opacity:.25"></i>
  <p class="mt-3">No budgets yet.<br>
    <a href="<?= BASE_PATH ?>/budget/create">Create your first budget</a> to start tracking spending.
  </p>
</div>
<?php else: ?>
<div class="budget-list">
  <?php foreach ($budgets as $b):
    $bid  = (int)$b['id'];
    $sum  = $summaries[$bid];
    $pct  = $sum['budgeted'] > 0 ? min($sum['actual'] / $sum['budgeted'] * 100, 100) : null;
    $over = $sum['budgeted'] > 0 && $sum['actual'] > $sum['budgeted'];
  ?>
  <div class="budget-card">
    <div class="budget-card-header">
      <div class="budget-card-name">
        <i class="bi bi-bar-chart-line"></i>
        <?= h($b['name']) ?>
        <?php if ($b['show_on_dashboard']): ?>
        <i class="bi bi-house-door ms-1" title="Dashboard budget" style="font-size:.75rem;color:var(--ms-blue-lt)"></i>
        <?php endif; ?>
        <?php if (!$b['is_active']): ?>
        <span class="badge bg-secondary ms-1" style="font-size:.65rem">Inactive</span>
        <?php endif; ?>
      </div>
      <div class="d-flex gap-1">
        <a href="<?= BASE_PATH ?>/budget/view?id=<?= $bid ?>" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-eye"></i> View
        </a>
        <?php if (canManageBudgets()): ?>
        <a href="<?= BASE_PATH ?>/budget/create?id=<?= $bid ?>" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-pencil"></i> Edit
        </a>
        <button class="btn btn-sm btn-outline-secondary" onclick="copyBudget(<?= $bid ?>)">
          <i class="bi bi-copy"></i> Copy
        </button>
        <?php if (isAdmin()): ?>
        <button class="btn btn-sm btn-outline-danger"
                onclick="confirmDelete(<?= $bid ?>, '<?= h(addslashes($b['name'])) ?>')">
          <i class="bi bi-trash"></i>
        </button>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="budget-card-meta">
      <span><i class="bi bi-wallet2"></i> <?= $b['acct_count'] ?> account<?= $b['acct_count'] != 1 ? 's' : '' ?></span>
      <span><i class="bi bi-tags"></i> <?= $b['cat_count'] ?> categor<?= $b['cat_count'] != 1 ? 'ies' : 'y' ?></span>
    </div>
    <?php if ($sum['budgeted'] > 0 || $sum['actual'] > 0): ?>
    <div class="budget-card-perf">
      <div class="bcp-row">
        <span class="text-muted" style="font-size:.78rem"><?= $today->format('F Y') ?></span>
        <span class="<?= $over ? 'amount-debit' : '' ?>" style="font-size:.85rem">
          <?= formatMoney($sum['actual']) ?> / <?= formatMoney($sum['budgeted']) ?>
        </span>
      </div>
      <?php if ($pct !== null): ?>
      <div class="budget-bar-track mt-1">
        <div class="budget-bar-fill <?= $over ? 'bar-over' : ($pct >= 80 ? 'bar-warn' : 'bar-ok') ?>"
             style="width:<?= round($pct, 1) ?>%"></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content confirm-modal">
      <div class="modal-header confirm-modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill"></i> Delete Budget</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body confirm-modal-body">
        <p id="deleteMsg"></p>
        <p class="confirm-warning">This cannot be undone.</p>
      </div>
      <div class="modal-footer confirm-modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
      </div>
    </div>
  </div>
</div>
<form id="deleteForm" method="post" action="<?= BASE_PATH ?>/budget/delete" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="deleteId">
</form>
<form id="copyForm" method="post" action="<?= BASE_PATH ?>/budget/copy" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="copyId">
</form>

<?php if (canManageBudgets()): ?>
<!-- ── Auto-Generate Modal ───────────────────────────────────── -->
<div class="modal fade" id="autoGenModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-stars"></i> Auto-Generate Budget</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="<?= BASE_PATH ?>/budget/auto_generate">
        <?= csrfField() ?>
        <div class="modal-body">
          <p class="text-muted small mb-3">
            We'll analyse your transaction history and pre-fill a budget with your typical
            income and expenses. You can rename it and adjust any amounts before saving.
          </p>
          <div class="mb-1 fw-semibold small">Base amounts on:</div>
          <div class="d-flex flex-column gap-2 mt-2">
            <label class="autogen-period-option">
              <input type="radio" name="period" value="last_month" checked>
              <div class="autogen-period-body">
                <div class="autogen-period-title">Previous Month</div>
                <div class="autogen-period-sub"><?= h($_prevMonLabel) ?> actuals</div>
              </div>
            </label>
            <label class="autogen-period-option">
              <input type="radio" name="period" value="last_3mo">
              <div class="autogen-period-body">
                <div class="autogen-period-title">3-Month Average</div>
                <div class="autogen-period-sub">Average of the last 3 complete months</div>
              </div>
            </label>
            <label class="autogen-period-option">
              <input type="radio" name="period" value="last_6mo">
              <div class="autogen-period-body">
                <div class="autogen-period-title">6-Month Average</div>
                <div class="autogen-period-sub">Average of the last 6 complete months</div>
              </div>
            </label>
            <label class="autogen-period-option">
              <input type="radio" name="period" value="last_12mo">
              <div class="autogen-period-body">
                <div class="autogen-period-title">12-Month Average</div>
                <div class="autogen-period-sub">Average of the last 12 complete months</div>
              </div>
            </label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-stars"></i> Generate Budget
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function confirmDelete(id, name) {
  document.getElementById('deleteMsg').textContent = 'Delete budget "' + name + '"?';
  document.getElementById('deleteId').value = id;
  const m = new bootstrap.Modal(document.getElementById('deleteModal'));
  m.show();
  document.getElementById('confirmDeleteBtn').onclick = () => document.getElementById('deleteForm').submit();
}

function copyBudget(id) {
  document.getElementById('copyId').value = id;
  document.getElementById('copyForm').submit();
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
