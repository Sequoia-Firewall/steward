<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!canManageBudgets()) {
    setFlash('error', 'You do not have permission to create or edit budgets.');
    header('Location: ' . BASE_PATH . '/budget/index'); exit;
}

$db = getDB();
$editId  = (int)($_GET['id'] ?? 0);
$isEdit  = $editId > 0;
$budget  = null;
$selAccts   = [];
$selCats    = []; // [category_id => ['type'=>'monthly','amount'=>0,'months'=>[],'dashboard'=>0]]

if ($isEdit) {
    $bStmt = $db->prepare("SELECT * FROM budgets WHERE id = ?");
    $bStmt->execute([$editId]);
    $budget = $bStmt->fetch();
    if (!$budget) {
        setFlash('error', 'Budget not found.');
        header('Location: ' . BASE_PATH . '/budget/index'); exit;
    }

    // Load selected accounts
    $aStmt = $db->prepare("SELECT account_id FROM budget_accounts WHERE budget_id = ?");
    $aStmt->execute([$editId]);
    $selAccts = array_column($aStmt->fetchAll(), 'account_id');

    // Load selected categories + monthly amounts
    $cStmt = $db->prepare(
        "SELECT bc.*, bma.month, bma.amount AS month_amount
         FROM budget_categories bc
         LEFT JOIN budget_monthly_amounts bma ON bma.budget_category_id = bc.id
         WHERE bc.budget_id = ?"
    );
    $cStmt->execute([$editId]);
    foreach ($cStmt->fetchAll() as $r) {
        $cid = (int)$r['category_id'];
        if (!isset($selCats[$cid])) {
            $selCats[$cid] = [
                'type'      => $r['entry_type'],
                'amount'    => (float)$r['amount'],
                'months'    => [],
                'dashboard' => (int)$r['show_on_dashboard'],
            ];
        }
        if ($r['month']) {
            $selCats[$cid]['months'][(int)$r['month']] = (float)$r['month_amount'];
        }
    }
}

// Load all active non-investment-cash accounts grouped by type
$allAccounts = $db->query(
    "SELECT id, name, type FROM accounts
     WHERE is_active = 1 AND is_investment_cash = 0
     ORDER BY FIELD(type,'Checking','Savings','Credit Card','Investment','Asset','Loan'), name"
)->fetchAll();
$acctByType = [];
foreach ($allAccounts as $a) {
    $acctByType[$a['type']][] = $a;
}

// Load all income and expense categories, build parent→children tree
$allCats = $db->query(
    "SELECT id, name, parent_id, type FROM categories
     WHERE is_active = 1 AND type IN ('income','expense') AND name != '--Split--'
     ORDER BY name"
)->fetchAll();

$catById   = [];
$incomeTree = [];
$expenseTree = [];
foreach ($allCats as $c) {
    $catById[(int)$c['id']] = $c;
}
foreach ($allCats as $c) {
    if ($c['parent_id']) continue; // process children separately
    if ($c['type'] === 'income') {
        $incomeTree[(int)$c['id']] = ['cat' => $c, 'children' => []];
    } else {
        $expenseTree[(int)$c['id']] = ['cat' => $c, 'children' => []];
    }
}
foreach ($allCats as $c) {
    if (!$c['parent_id']) continue;
    $pid = (int)$c['parent_id'];
    if ($c['type'] === 'income' && isset($incomeTree[$pid])) {
        $incomeTree[$pid]['children'][] = $c;
    } elseif ($c['type'] === 'expense' && isset($expenseTree[$pid])) {
        $expenseTree[$pid]['children'][] = $c;
    }
}

$monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

// Historical spending per category for reference in the wizard
$histToday      = new DateTime();
$prevYear       = (int)$histToday->format('Y') - 1;
$prevYearStart  = $prevYear . '-01-01';
$prevYearEnd    = $prevYear . '-12-31';
$last12Start    = (clone $histToday)->modify('-12 months')->format('Y-m-d');
$last12End      = $histToday->format('Y-m-d');
$lastMonObj     = (clone $histToday)->modify('first day of last month');
$lastMonStart   = $lastMonObj->format('Y-m-01');
$lastMonEnd     = $lastMonObj->format('Y-m-t');
$histLabels     = [
    'prev_year'  => 'Last Year (' . $prevYear . ')',
    'last_12mo'  => 'Last 12 Months',
    'last_month' => 'Last Month (' . $lastMonObj->format('M Y') . ')',
];
$histRanges     = [
    'last_year'  => ['start' => $prevYearStart, 'end' => $prevYearEnd],
    'last_12mo'  => ['start' => $last12Start,   'end' => $last12End],
    'last_month' => ['start' => $lastMonStart,   'end' => $lastMonEnd],
];
$histStmt = $db->prepare(
    "SELECT COALESCE(ts.subcategory_id, ts.category_id) AS category_id,
            SUM(CASE WHEN t.transaction_date BETWEEN ? AND ? THEN ts.amount ELSE 0 END) AS last_year,
            SUM(CASE WHEN t.transaction_date BETWEEN ? AND ? THEN ts.amount ELSE 0 END) AS last_12mo,
            SUM(CASE WHEN t.transaction_date BETWEEN ? AND ? THEN ts.amount ELSE 0 END) AS last_month
     FROM transaction_splits ts
     JOIN transactions t ON t.id = ts.transaction_id
     JOIN categories c ON c.id = ts.category_id
     WHERE c.type IN ('income','expense')
       AND c.name != '--Split--'
       AND t.transaction_date >= ?
     GROUP BY COALESCE(ts.subcategory_id, ts.category_id)"
);
$histStmt->execute([$prevYearStart, $prevYearEnd, $last12Start, $last12End, $lastMonStart, $lastMonEnd, $prevYearStart]);
$histSpend = [];
foreach ($histStmt->fetchAll() as $r) {
    $histSpend[(int)$r['category_id']] = [
        'last_year'  => (float)$r['last_year'],
        'last_12mo'  => (float)$r['last_12mo'],
        'last_month' => (float)$r['last_month'],
    ];
}

$pageTitle   = $isEdit ? 'Edit Budget' : 'New Budget';
$currentPage = 'budget';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-bar-chart-line"></i> <?= $isEdit ? 'Edit Budget' : 'New Budget' ?></h2>
  <a href="<?= BASE_PATH ?>/budget/index" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-chevron-left"></i> All Budgets
  </a>
</div>

<!-- Wizard step indicators -->
<div class="wiz-steps">
  <div class="wiz-step-item active" id="stepInd1">
    <div class="wiz-step-circle" id="stepCircle1">1</div>
    <div class="wiz-step-label">Name</div>
  </div>
  <div class="wiz-step-conn" id="stepConn1"></div>
  <div class="wiz-step-item" id="stepInd2">
    <div class="wiz-step-circle" id="stepCircle2">2</div>
    <div class="wiz-step-label">Accounts</div>
  </div>
  <div class="wiz-step-conn" id="stepConn2"></div>
  <div class="wiz-step-item" id="stepInd3">
    <div class="wiz-step-circle" id="stepCircle3">3</div>
    <div class="wiz-step-label">Income</div>
  </div>
  <div class="wiz-step-conn" id="stepConn3"></div>
  <div class="wiz-step-item" id="stepInd4">
    <div class="wiz-step-circle" id="stepCircle4">4</div>
    <div class="wiz-step-label">Expenses</div>
  </div>
</div>

<form method="post" action="<?= BASE_PATH ?>/budget/save" id="budgetWizForm">
  <?= csrfField() ?>
  <input type="hidden" name="budget_id" value="<?= $editId ?>">

  <!-- ── Step 1: Name ───────────────────────────────────────── -->
  <div class="wiz-panel" id="wizStep1">
    <h5 class="wiz-panel-title">Budget Name</h5>
    <div class="mb-3" style="max-width:400px">
      <label class="form-label">Name <span class="text-danger">*</span></label>
      <input type="text" name="name" id="budgetName" class="form-control"
             value="<?= h($budget['name'] ?? '') ?>" placeholder="e.g. Household, Personal">
    </div>
    <div class="mb-3">
      <div class="form-check">
        <input type="checkbox" class="form-check-input" name="show_on_dashboard" id="showOnDash"
               value="1" <?= !empty($budget['show_on_dashboard']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="showOnDash">
          Use this budget for the dashboard widget
        </label>
      </div>
    </div>
    <div class="mb-3">
      <div class="form-check">
        <input type="checkbox" class="form-check-input" name="is_active" id="isActive"
               value="1" <?= ($budget === null || !empty($budget['is_active'])) ? 'checked' : '' ?>>
        <label class="form-check-label" for="isActive">Active</label>
      </div>
    </div>
    <div class="wiz-nav">
      <button type="button" class="btn btn-primary" onclick="goStep(2)">Next <i class="bi bi-chevron-right"></i></button>
    </div>
  </div>

  <!-- ── Step 2: Accounts ───────────────────────────────────── -->
  <div class="wiz-panel d-none" id="wizStep2">
    <h5 class="wiz-panel-title">Select Accounts</h5>
    <p class="text-muted small">Only transactions from these accounts will count toward actuals.</p>
    <?php foreach ($acctByType as $type => $accounts): ?>
    <div class="wiz-acct-group">
      <div class="wiz-acct-group-label">
        <span><?= h($type) ?></span>
        <button type="button" class="wiz-acct-selall-btn" onclick="toggleAcctGroup(this)">Select all</button>
      </div>
      <?php foreach ($accounts as $a): ?>
      <label class="wiz-acct-row">
        <input type="checkbox" name="account_ids[]" value="<?= $a['id'] ?>"
               <?= in_array($a['id'], $selAccts) ? 'checked' : '' ?>>
        <?= h($a['name']) ?>
      </label>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="wiz-nav">
      <button type="button" class="btn btn-outline-secondary" onclick="goStep(1)"><i class="bi bi-chevron-left"></i> Back</button>
      <button type="button" class="btn btn-primary" onclick="goStep(3)">Next <i class="bi bi-chevron-right"></i></button>
    </div>
  </div>

  <!-- ── Step 3: Income Categories ─────────────────────────── -->
  <div class="wiz-panel d-none" id="wizStep3">
    <h5 class="wiz-panel-title">Income Categories</h5>
    <p class="text-muted small">Select income categories to track in this budget.</p>
    <?php include __DIR__ . '/_cat_rows.php'; renderCatTree($incomeTree, $selCats, $monthNames, $histSpend, $histLabels, $histRanges); ?>
    <div class="wiz-nav">
      <button type="button" class="btn btn-outline-secondary" onclick="goStep(2)"><i class="bi bi-chevron-left"></i> Back</button>
      <button type="button" class="btn btn-primary" onclick="goStep(4)">Next <i class="bi bi-chevron-right"></i></button>
    </div>
  </div>

  <!-- ── Step 4: Expense Categories ────────────────────────── -->
  <div class="wiz-panel d-none" id="wizStep4">
    <h5 class="wiz-panel-title">Expense Categories</h5>
    <p class="text-muted small">Select expense categories to track in this budget.</p>
    <?php renderCatTree($expenseTree, $selCats, $monthNames, $histSpend, $histLabels, $histRanges); ?>
    <div class="wiz-nav">
      <button type="button" class="btn btn-outline-secondary" onclick="goStep(3)"><i class="bi bi-chevron-left"></i> Back</button>
      <button type="submit" class="btn btn-success">
        <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Save Changes' : 'Create Budget' ?>
      </button>
    </div>
  </div>
</form>

<script>
let currentStep = 1;

function goStep(n) {
  if (n === 2) {
    const nameEl = document.getElementById('budgetName');
    if (!nameEl.value.trim()) {
      nameEl.classList.add('is-invalid');
      nameEl.focus();
      return;
    }
    nameEl.classList.remove('is-invalid');
  }

  document.getElementById('wizStep' + currentStep).classList.add('d-none');
  document.getElementById('wizStep' + n).classList.remove('d-none');

  for (let i = 1; i <= 4; i++) {
    const item   = document.getElementById('stepInd' + i);
    const circle = document.getElementById('stepCircle' + i);
    const isDone = i < n;
    item.classList.toggle('active', i === n);
    item.classList.toggle('done',   isDone);
    circle.innerHTML = isDone ? '<i class="bi bi-check-lg"></i>' : i;
  }
  for (let i = 1; i <= 3; i++) {
    document.getElementById('stepConn' + i)?.classList.toggle('done', i < n);
  }

  currentStep = n;
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Toggle entry-type fields when checkbox or type changes
document.addEventListener('change', function (e) {
  const el = e.target;
  if (el.matches('.wiz-cat-include')) {
    const row = el.closest('.wiz-cat-row');
    row.querySelector('.wiz-cat-options').classList.toggle('d-none', !el.checked);
    if (!el.checked) row.querySelector('.wiz-cat-variable')?.classList.add('d-none');
    row.querySelectorAll('select, input:not(.wiz-cat-include)').forEach(inp => {
      inp.disabled = !el.checked;
    });
    if (el.checked) {
      const typeEl = row.querySelector('.wiz-entry-type');
      if (typeEl) updateEntryType(typeEl);
      // Newly-included categories default to shown-on-dashboard when the budget
      // itself is set to feed the dashboard widget.
      const dashMaster = document.getElementById('showOnDash');
      const dashCb = row.querySelector('.wiz-dash-check');
      if (dashMaster && dashMaster.checked && dashCb) dashCb.checked = true;
    }
  }
  if (el.matches('.wiz-entry-type')) {
    updateEntryType(el);
  }
  // Update "select all" button text when individual account checkboxes change
  if (el.type === 'checkbox' && el.closest('.wiz-acct-group')) {
    syncAcctGroupBtn(el.closest('.wiz-acct-group'));
  }
  // Turning on "use this budget for the dashboard widget" defaults every
  // currently-included category to shown-on-dashboard too.
  if (el.id === 'showOnDash' && el.checked) {
    document.querySelectorAll('.wiz-dash-check:not(:disabled)').forEach(cb => { cb.checked = true; });
  }
});

function toggleAcctGroup(btn) {
  const group = btn.closest('.wiz-acct-group');
  const boxes = [...group.querySelectorAll('input[type="checkbox"]')];
  const allOn = boxes.every(cb => cb.checked);
  boxes.forEach(cb => { cb.checked = !allOn; });
  btn.textContent = allOn ? 'Select all' : 'Deselect all';
}

function syncAcctGroupBtn(group) {
  const btn = group.querySelector('.wiz-acct-selall-btn');
  if (!btn) return;
  const boxes = [...group.querySelectorAll('input[type="checkbox"]')];
  btn.textContent = boxes.every(cb => cb.checked) ? 'Deselect all' : 'Select all';
}

function updateEntryType(sel) {
  const row   = sel.closest('.wiz-cat-row');
  const isVar = sel.value === 'variable';
  row.querySelector('.wiz-cat-amount-wrap').classList.toggle('d-none', isVar);
  row.querySelector('.wiz-cat-variable').classList.toggle('d-none', !isVar);
  if (isVar) updateVarTotal(row); else updateEquivHint(row);
}

function updateEquivHint(row) {
  const sel   = row.querySelector('.wiz-entry-type');
  const input = row.querySelector('.wiz-cat-amount');
  const hint  = row.querySelector('.wiz-equiv-hint');
  if (!sel || !input || !hint) return;
  const val = parseFloat(input.value) || 0;
  if (val <= 0) { hint.textContent = ''; return; }
  if (sel.value === 'annual')  hint.textContent = '= $' + Math.round(val / 12).toLocaleString() + '/mo';
  if (sel.value === 'monthly') hint.textContent = '= $' + Math.round(val * 12).toLocaleString() + '/yr';
}

function updateVarTotal(row) {
  const inputs = row.querySelectorAll('.wiz-months-grid input');
  let sum = 0;
  inputs.forEach(inp => { sum += parseFloat(inp.value) || 0; });
  const el = row.querySelector('.wiz-var-total-val');
  if (el) el.textContent = '$' + sum.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

document.addEventListener('input', function (e) {
  const row = e.target.closest('.wiz-cat-row');
  if (!row) return;
  if (e.target.matches('.wiz-cat-amount'))           updateEquivHint(row);
  if (e.target.closest('.wiz-months-grid'))           updateVarTotal(row);
});

// On load, apply initial states for pre-filled edit mode
document.querySelectorAll('.wiz-cat-include:checked').forEach(el => {
  el.closest('.wiz-cat-row').querySelector('.wiz-cat-options').classList.remove('d-none');
});
document.querySelectorAll('.wiz-acct-group').forEach(syncAcctGroupBtn);
document.querySelectorAll('.wiz-entry-type').forEach(updateEntryType);
document.querySelectorAll('.wiz-cat-row').forEach(row => {
  if (row.querySelector('.wiz-entry-type')?.value === 'variable') updateVarTotal(row);
  updateEquivHint(row);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
