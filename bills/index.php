<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$pageTitle   = 'Bills Summary';
$currentPage = 'bills';
$accounts    = getAccounts();
$hierarchy        = getAllCategoriesHierarchy();
$categoriesByType = [];
foreach ($hierarchy as $cat) $categoriesByType[$cat['type']][] = $cat;
$bills       = getScheduledBills();

// Payee → category suggestion map (most-recent category per payee, payee profiles override)
$payeeCatMap = [];
$db = getDB();
foreach ($db->query(
    'SELECT t.payee, ts.category_id, ts.subcategory_id
     FROM transactions t
     JOIN transaction_splits ts ON ts.transaction_id = t.id
     JOIN accounts a ON a.id = t.account_id
     WHERE t.payee != \'\' AND t.is_split = 0 AND ts.category_id IS NOT NULL
       AND a.type != \'Investment\'
     ORDER BY t.transaction_date DESC, t.id DESC'
)->fetchAll() as $pcRow) {
    if (!isset($payeeCatMap[$pcRow['payee']])) {
        $payeeCatMap[$pcRow['payee']] = ['cat' => (int)$pcRow['category_id'], 'subcat' => (int)$pcRow['subcategory_id']];
    }
}
foreach ($db->query('SELECT name, category_id, subcategory_id FROM payees WHERE category_id IS NOT NULL')->fetchAll() as $pp) {
    $payeeCatMap[$pp['name']] = ['cat' => (int)$pp['category_id'], 'subcat' => (int)$pp['subcategory_id']];
}

// Pre-fill from "Make Recurring" redirect
$prefill = null;
if (!empty($_GET['prefill']) && canEdit()) {
    $pfType = in_array($_GET['pf_type'] ?? '', ['bill', 'deposit', 'transfer'], true) ? $_GET['pf_type'] : 'bill';
    $prefill = [
        'name'          => trim($_GET['pf_name']          ?? ''),
        'type'          => $pfType,
        'account_id'    => (int)($_GET['pf_account_id']    ?? 0),
        'to_account_id' => (int)($_GET['pf_to_account_id'] ?? 0),
        'amount'        => max(0.0, (float)($_GET['pf_amount'] ?? 0)),
        'notes'         => trim($_GET['pf_notes']          ?? ''),
        'category_id'   => (int)($_GET['pf_category_id']    ?? 0) ?: null,
        'subcategory_id'=> (int)($_GET['pf_subcategory_id'] ?? 0) ?: null,
    ];
}

include __DIR__ . '/../includes/header.php';

// Classify each bill
$today    = date('Y-m-d');
$soonDate = date('Y-m-d', strtotime('+7 days'));
?>
<div class="page-header">
  <h2><i class="bi bi-calendar-check"></i> Bills Summary</h2>
  <?php if (canEdit()): ?>
  <button class="btn btn-primary btn-sm" onclick="showBillForm()">
    <i class="bi bi-plus-circle"></i> New Scheduled Item
  </button>
  <?php endif; ?>
</div>

<?php if (canEdit()): ?>
<!-- ── Add / Edit Form ──────────────────────────────────────── -->
<div class="form-card mb-3" id="billForm" style="display:none">
  <div class="form-section-title" id="billFormTitle">New Scheduled Item</div>
  <form id="billFormEl" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="id"         id="bill_id" value="">
    <div class="row g-3">

      <div class="col-md-3">
        <label class="form-label required">Name / Payee</label>
        <input type="text" name="name" id="bill_name" class="form-control" required maxlength="100">
      </div>

      <div class="col-md-2">
        <label class="form-label required">Type</label>
        <select name="type" id="bill_type" class="form-select" onchange="billTypeChanged()">
          <option value="bill">Bill (withdrawal)</option>
          <option value="deposit">Deposit</option>
          <option value="transfer">Transfer</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label required">Account</label>
        <select name="account_id" id="bill_account" class="form-select" required>
          <option value="">— Select Account —</option>
          <?php foreach ($accounts as $acc): ?>
          <option value="<?= $acc['id'] ?>"><?= h($acc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3" id="bill_to_account_wrap" style="display:none">
        <label class="form-label required">To Account</label>
        <select name="to_account_id" id="bill_to_account" class="form-select">
          <option value="">— Select Account —</option>
          <?php foreach ($accounts as $acc): if (!isCashAccount($acc['type'])) continue; ?>
          <option value="<?= $acc['id'] ?>"><?= h($acc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label required">Amount</label>
        <div class="input-group">
          <span class="input-group-text">$</span>
          <input type="number" name="amount" id="bill_amount" class="form-control"
                 step="0.01" min="0.01" placeholder="0.00" required>
        </div>
        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" id="bill_estimated" name="is_estimated" value="1">
          <label class="form-check-label small text-muted" for="bill_estimated">Estimated</label>
        </div>
      </div>

      <div class="col-md-2">
        <label class="form-label required">Frequency</label>
        <select name="frequency" id="bill_frequency" class="form-select">
          <option value="once">Once</option>
          <option value="weekly">Weekly</option>
          <option value="biweekly">Biweekly</option>
          <option value="twice_monthly">Twice a Month (15th &amp; last)</option>
          <option value="monthly" selected>Monthly</option>
          <option value="bimonthly">Bimonthly (every 2 months)</option>
          <option value="quarterly">Quarterly</option>
          <option value="yearly">Yearly</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label required">Next Due Date</label>
        <input type="date" name="next_due_date" id="bill_due_date" class="form-control" required>
      </div>

      <div class="col-md-3" id="bill_category_wrap">
        <label class="form-label">Category</label>
        <select name="category_id" id="bill_category" class="form-select"
                onchange="billLoadSubcat()">
          <option value="">— None —</option>
          <?php foreach (['expense' => 'EXPENSES', 'income' => 'INCOME'] as $ctype => $clabel): ?>
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

      <div class="col-md-3" id="bill_subcategory_wrap">
        <label class="form-label">Subcategory</label>
        <select name="subcategory_id" id="bill_subcategory" class="form-select">
          <option value="">— None —</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Notes</label>
        <input type="text" name="notes" id="bill_notes" class="form-control" maxlength="255">
      </div>

      <div class="col-12 d-flex align-items-center gap-2 mt-1">
        <button type="button" class="btn btn-primary" onclick="saveBill()">
          <i class="bi bi-check-circle"></i> Save
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="hideBillForm()">Cancel</button>
        <span id="billStatus" class="ms-2"></span>
      </div>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ── Filter Bar ──────────────────────────────────────────────── -->
<div class="d-flex gap-2 mb-3 align-items-center">
  <span class="text-muted small">Show:</span>
  <button class="btn btn-sm btn-outline-secondary bill-filter active" data-filter="all">All</button>
  <button class="btn btn-sm btn-outline-danger   bill-filter"        data-filter="bill">Bills</button>
  <button class="btn btn-sm btn-outline-success  bill-filter"        data-filter="deposit">Deposits</button>
  <button class="btn btn-sm btn-outline-primary  bill-filter"        data-filter="transfer">Transfers</button>
  <button class="btn btn-sm btn-outline-warning  bill-filter"        data-filter="overdue">Overdue</button>
</div>

<!-- ── Bills Table ────────────────────────────────────────────── -->
<?php if (empty($bills)): ?>
<div class="dash-section">
  <p class="text-muted">No scheduled bills or deposits yet.
    <?php if (canEdit()): ?>
    <a href="#" onclick="showBillForm();return false;">Add one now.</a>
    <?php endif; ?>
  </p>
</div>
<?php else: ?>
<section class="dash-section">
  <table class="table dash-table" id="billsTable">
    <thead>
      <tr>
        <th class="sortable" data-col="0">Name</th>
        <th class="sortable" data-col="1">Type</th>
        <th class="sortable" data-col="2">Account</th>
        <th class="sortable" data-col="3">Category</th>
        <th class="sortable text-end" data-col="4">Amount</th>
        <th class="sortable" data-col="5">Frequency</th>
        <th class="sortable" data-col="6">Next Due</th>
        <th class="sortable" data-col="7">Status</th>
        <?php if (canEdit()): ?><th>Actions</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($bills as $bill):
        $due    = $bill['next_due_date'];
        $isOver = $due < $today;
        $isSoon = !$isOver && $due <= $soonDate;
        $statusCls  = $isOver ? 'bill-overdue' : ($isSoon ? 'bill-soon' : 'bill-ok');
        $statusLbl  = $isOver ? 'Overdue'      : ($isSoon ? 'Due Soon'  : 'Upcoming');
        $statusSort = $isOver ? 0              : ($isSoon ? 1           : 2);
        $rowFilter  = $bill['type'] . ($isOver ? ' overdue' : '');
        $amtCls     = $bill['type'] === 'deposit' ? 'amount-credit' : 'amount-debit';
        $freqSortOrder = ['once'=>0,'weekly'=>1,'biweekly'=>2,'twice_monthly'=>3,'monthly'=>4,'bimonthly'=>5,'quarterly'=>6,'yearly'=>7];
      ?>
      <tr class="bill-row <?= $bill['is_active'] ? '' : 'bill-inactive' ?>"
          data-filter="<?= h($rowFilter) ?>">
        <td class="fw-medium">
          <a href="<?= BASE_PATH ?>/transactions/search?q=<?= urlencode($bill['name']) ?>"
             class="bill-payee-link" title="View past transactions for this payee"
             onclick="return showPayeeTransactions(<?= h(json_encode($bill['name'])) ?>, event)">
            <?= h($bill['name']) ?>
          </a>
        </td>
        <td data-val="<?= h($bill['type']) ?>">
          <?php if ($bill['type'] === 'bill'): ?>
          <span class="badge bill-type-bill"><i class="bi bi-arrow-up-circle"></i> Bill</span>
          <?php elseif ($bill['type'] === 'deposit'): ?>
          <span class="badge bill-type-deposit"><i class="bi bi-arrow-down-circle"></i> Deposit</span>
          <?php else: ?>
          <span class="badge bill-type-transfer"><i class="bi bi-arrow-left-right"></i> Transfer</span>
          <?php endif; ?>
        </td>
        <td>
          <?= h($bill['account_name']) ?>
          <?php if ($bill['type'] === 'transfer' && $bill['to_account_name']): ?>
            <span class="text-muted"> &rarr; <?= h($bill['to_account_name']) ?></span>
          <?php endif; ?>
        </td>
        <td class="text-muted small">
          <?php if ($bill['category_name']): ?>
            <?= h($bill['category_name']) ?>
            <?php if ($bill['subcategory_name']): ?> &rsaquo; <?= h($bill['subcategory_name']) ?><?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="text-end" data-val="<?= (float)$bill['amount'] ?>">
          <span class="<?= $amtCls ?>"><?= formatMoney((float)$bill['amount']) ?></span><?php if (!empty($bill['is_estimated'])): ?><span class="bill-est" title="Estimated amount">~est</span><?php endif; ?>
        </td>
        <?php
          $freqLabels = ['once'=>'Once','weekly'=>'Weekly','biweekly'=>'Biweekly',
                         'twice_monthly'=>'Twice a Month','monthly'=>'Monthly',
                         'bimonthly'=>'Bimonthly','quarterly'=>'Quarterly','yearly'=>'Yearly'];
        ?>
        <td data-val="<?= $freqSortOrder[$bill['frequency']] ?? 4 ?>"><?= h($freqLabels[$bill['frequency']] ?? ucfirst($bill['frequency'])) ?></td>
        <td class="text-nowrap" data-val="<?= h($due) ?>"><?= formatDate($due) ?></td>
        <td data-val="<?= $statusSort ?>"><span class="bill-status <?= $statusCls ?>"><?= $statusLbl ?></span></td>
        <?php if (canEdit()): ?>
        <td class="text-nowrap">
          <button class="btn btn-sm btn-outline-success" title="Post Now"
                  onclick="postBill(<?= $bill['id'] ?>, <?= h(json_encode($bill['name'])) ?>, <?= h(json_encode((float)$bill['amount'])) ?>, <?= h(json_encode($bill['next_due_date'])) ?>)">
            <i class="bi bi-send-check"></i> Post
          </button>
          <button class="btn btn-sm btn-outline-secondary ms-1" title="Skip this period (already entered manually)"
                  onclick="skipBill(<?= $bill['id'] ?>, <?= h(json_encode($bill['name'])) ?>)">
            <i class="bi bi-skip-forward"></i> Skip
          </button>
          <button class="btn btn-sm btn-outline-secondary ms-1" title="Edit"
                  onclick="editBill(<?= h(json_encode($bill)) ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <?php if (isAdmin()): ?>
          <button class="btn btn-sm btn-outline-danger ms-1" title="Delete"
                  onclick="deleteBill(<?= $bill['id'] ?>, <?= h(json_encode($bill['name'])) ?>)">
            <i class="bi bi-trash"></i>
          </button>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- ── Hidden forms ───────────────────────────────────────────── -->
<form id="deleteBillForm" method="post" action="<?= BASE_PATH ?>/bills/delete" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="deleteBillId">
</form>
<form id="postBillForm" method="post" action="<?= BASE_PATH ?>/bills/post" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="postBillId">
  <input type="hidden" name="amount" id="postBillAmountHidden">
  <input type="hidden" name="transaction_date" id="postBillDateHidden">
</form>
<form id="skipBillForm" method="post" action="<?= BASE_PATH ?>/bills/skip" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="skipBillId">
</form>

<!-- ── Payee transaction history modal ────────────────────────── -->
<div class="modal fade" id="payeeHistoryModal" tabindex="-1" aria-labelledby="payeeHistoryTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--ms-blue);color:#fff;border-bottom:1px solid var(--ms-blue-mid)">
        <h5 class="modal-title" id="payeeHistoryTitle">
          <i class="bi bi-receipt"></i> <span id="payeeHistoryTitleText">Transactions</span>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0" id="payeeHistoryBody">
        <div class="d-flex justify-content-center align-items-center p-4 text-muted">
          <div class="spinner-border spinner-border-sm me-2"></div> Loading&hellip;
        </div>
      </div>
      <div class="modal-footer">
        <a href="#" id="payeeHistoryFullLink" class="btn btn-outline-primary btn-sm" target="_blank">
          <i class="bi bi-box-arrow-up-right"></i> Open in Search
        </a>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Confirm modal (reuses existing pattern) ────────────────── -->
<div class="modal fade" id="confirmBillModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content confirm-modal">
      <div class="modal-header confirm-modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill"></i> <span id="confirmBillTitle">Confirm</span></h5>
      </div>
      <div class="modal-body confirm-modal-body">
        <p id="confirmBillMsg"></p>
        <p class="confirm-warning" id="confirmBillWarn" style="display:none">This action cannot be undone.</p>
      </div>
      <div class="modal-footer confirm-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmBillBtn">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Post modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="postBillModal" tabindex="-1" aria-labelledby="postBillModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content confirm-modal">
      <div class="modal-header confirm-modal-header">
        <h5 class="modal-title"><i class="bi bi-send-check"></i> Post Transaction</h5>
      </div>
      <div class="modal-body confirm-modal-body">
        <p class="mb-3">Post <strong id="postBillModalName"></strong> as a transaction?</p>
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label required" for="postBillAmountInput">Amount</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input type="number" id="postBillAmountInput" class="form-control" step="0.01" min="0.01" required>
            </div>
          </div>
          <div class="col-6">
            <label class="form-label required" for="postBillDateInput">Date</label>
            <input type="date" id="postBillDateInput" class="form-control" required>
          </div>
        </div>
        <p class="text-muted small mt-3 mb-0">This will create a transaction in the register and advance the next due date.</p>
      </div>
      <div class="modal-footer confirm-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="postBillConfirmBtn">
          <i class="bi bi-send-check"></i> Post Transaction
        </button>
      </div>
    </div>
  </div>
</div>

<script>
const billCategoryData  = <?= json_encode($hierarchy) ?>;
const BILL_PAYEE_CATS   = <?= json_encode($payeeCatMap, JSON_HEX_TAG) ?>;

// ── Filter ─────────────────────────────────────────────────────
document.querySelectorAll('.bill-filter').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.bill-filter').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const f = btn.dataset.filter;
    document.querySelectorAll('.bill-row').forEach(row => {
      const rf = row.dataset.filter || '';
      row.style.display = (f === 'all' || rf.includes(f)) ? '' : 'none';
    });
  });
});

// ── Column sort ────────────────────────────────────────────────
(function () {
  const table  = document.getElementById('billsTable');
  if (!table) return;
  const tbody  = table.querySelector('tbody');
  let sortCol  = 6;  // default: Next Due
  let sortAsc  = true;

  function cellVal(row, col) {
    const td = row.cells[col];
    if (!td) return '';
    return td.dataset.val !== undefined ? td.dataset.val : td.textContent.trim();
  }

  function sortRows() {
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => {
      let va = cellVal(a, sortCol);
      let vb = cellVal(b, sortCol);
      const na = Number(va), nb = Number(vb);
      let cmp = (!isNaN(na) && !isNaN(nb))
        ? na - nb
        : va.localeCompare(vb, undefined, { sensitivity: 'base' });
      return sortAsc ? cmp : -cmp;
    });
    rows.forEach(r => tbody.appendChild(r));
  }

  function updateHeaders() {
    table.querySelectorAll('th.sortable').forEach(th => {
      const col = parseInt(th.dataset.col, 10);
      th.classList.toggle('sort-asc',  col === sortCol &&  sortAsc);
      th.classList.toggle('sort-desc', col === sortCol && !sortAsc);
    });
  }

  table.querySelectorAll('th.sortable').forEach(th => {
    th.addEventListener('click', () => {
      const col = parseInt(th.dataset.col, 10);
      if (col === sortCol) { sortAsc = !sortAsc; }
      else { sortCol = col; sortAsc = true; }
      sortRows();
      updateHeaders();
    });
  });

  sortRows();
  updateHeaders();
})();

// ── Type toggle ────────────────────────────────────────────────
function billTypeChanged() {
  const type       = document.getElementById('bill_type').value;
  const isTransfer = type === 'transfer';
  document.getElementById('bill_to_account_wrap').style.display    = isTransfer ? '' : 'none';
  document.getElementById('bill_category_wrap').style.display      = isTransfer ? 'none' : '';
  document.getElementById('bill_subcategory_wrap').style.display   = isTransfer ? 'none' : '';
  if (isTransfer) {
    document.getElementById('bill_category').value   = '';
    document.getElementById('bill_subcategory').innerHTML = '<option value="">— None —</option>';
  }
}

// ── Form show/hide ─────────────────────────────────────────────
function showBillForm() {
  document.getElementById('billForm').style.display = 'block';
  document.getElementById('bill_id').value = '';
  document.getElementById('billFormTitle').textContent = 'New Scheduled Item';
  document.getElementById('billFormEl').reset();
  document.getElementById('bill_due_date').value = new Date().toISOString().split('T')[0];
  document.getElementById('bill_subcategory').innerHTML = '<option value="">— None —</option>';
  document.getElementById('billStatus').innerHTML = '';
  billClearCatHint();
  billTypeChanged();
  document.getElementById('bill_name').focus();
}
function hideBillForm() {
  document.getElementById('billForm').style.display = 'none';
}

// ── Edit ───────────────────────────────────────────────────────
function editBill(bill) {
  document.getElementById('billForm').style.display = 'block';
  document.getElementById('billFormTitle').textContent = 'Edit Scheduled Item';
  document.getElementById('bill_id').value           = bill.id;
  document.getElementById('bill_name').value         = bill.name;
  document.getElementById('bill_type').value         = bill.type;
  document.getElementById('bill_account').value      = bill.account_id;
  document.getElementById('bill_to_account').value   = bill.to_account_id || '';
  document.getElementById('bill_amount').value       = parseFloat(bill.amount).toFixed(2);
  document.getElementById('bill_estimated').checked  = !!parseInt(bill.is_estimated);
  document.getElementById('bill_frequency').value    = bill.frequency;
  document.getElementById('bill_due_date').value     = bill.next_due_date;
  document.getElementById('bill_notes').value        = bill.notes || '';
  document.getElementById('bill_category').value     = bill.category_id || '';
  billClearCatHint();
  billTypeChanged();
  billLoadSubcat(bill.subcategory_id);
  document.getElementById('billStatus').innerHTML = '';
  document.getElementById('bill_name').focus();
  document.getElementById('billForm').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ── Subcategory loader ─────────────────────────────────────────
function billLoadSubcat(selectedId) {
  const catId  = parseInt(document.getElementById('bill_category').value, 10);
  const sel    = document.getElementById('bill_subcategory');
  sel.innerHTML = '<option value="">— None —</option>';
  if (!catId) return;
  const parent = billCategoryData.find(c => c.id === catId);
  if (!parent || !parent.children) return;
  parent.children.forEach(sub => {
    const o = document.createElement('option');
    o.value = sub.id;
    o.textContent = sub.name;
    if (selectedId && sub.id == selectedId) o.selected = true;
    sel.appendChild(o);
  });
}

// ── Payee → Category Suggestion ───────────────────────────────
function billApplyPayeeSuggestion() {
  const nameEl = document.getElementById('bill_name');
  const catEl  = document.getElementById('bill_category');
  if (!nameEl || !catEl || catEl.value !== '') return;

  const suggestion = BILL_PAYEE_CATS[nameEl.value.trim()];
  if (!suggestion) return;

  catEl.value = suggestion.cat;
  billLoadSubcat(suggestion.subcat);

  const parent = billCategoryData.find(c => c.id === suggestion.cat);
  if (!parent) return;
  let label = parent.name;
  if (suggestion.subcat) {
    const sub = parent.children?.find(c => c.id === suggestion.subcat);
    if (sub) label += ' › ' + sub.name;
  }

  // Show hint below category field
  billClearCatHint();
  const wrap = document.getElementById('bill_category_wrap');
  if (wrap) {
    const hint = document.createElement('div');
    hint.id        = 'billCatHint';
    hint.className = 'cat-suggestion-hint';
    hint.innerHTML = `<i class="bi bi-magic"></i> Suggested: <strong>${escHtml(label)}</strong>`
      + `<button type="button" class="cat-hint-clear" title="Clear suggestion"
                onclick="billClearCatSuggestion()"><i class="bi bi-x"></i></button>`;
    wrap.appendChild(hint);
  }
}

function billClearCatHint() {
  document.getElementById('billCatHint')?.remove();
}

function billClearCatSuggestion() {
  billClearCatHint();
  const catEl    = document.getElementById('bill_category');
  const subcatEl = document.getElementById('bill_subcategory');
  if (catEl)    catEl.value = '';
  if (subcatEl) subcatEl.innerHTML = '<option value="">— None —</option>';
}

document.addEventListener('DOMContentLoaded', function () {
  const nameEl = document.getElementById('bill_name');
  const catEl  = document.getElementById('bill_category');
  if (nameEl) nameEl.addEventListener('change', billApplyPayeeSuggestion);
  if (catEl)  catEl.addEventListener('change', billClearCatHint);
});

// ── Save ───────────────────────────────────────────────────────
async function saveBill() {
  const status = document.getElementById('billStatus');
  const data   = new FormData(document.getElementById('billFormEl'));
  status.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Saving…';
  try {
    const res  = await fetch('<?= BASE_PATH ?>/bills/save', { method: 'POST', body: data });
    const json = await res.json();
    if (json.ok) {
      status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Saved!</span>';
      setTimeout(() => location.reload(), 600);
    } else {
      status.innerHTML = '<span class="text-danger">' + escHtml(json.error || 'Save failed') + '</span>';
    }
  } catch (e) {
    console.error(e);
    status.innerHTML = '<span class="text-danger">Network error.</span>';
  }
}

// ── Confirm helper ─────────────────────────────────────────────
function showBillConfirm(title, msg, warnText, btnClass, onConfirm) {
  document.getElementById('confirmBillTitle').textContent = title;
  document.getElementById('confirmBillMsg').textContent   = msg;
  const warn = document.getElementById('confirmBillWarn');
  warn.textContent  = warnText || '';
  warn.style.display = warnText ? '' : 'none';
  const btn   = document.getElementById('confirmBillBtn');
  btn.className = 'btn ' + (btnClass || 'btn-danger');
  const fresh = btn.cloneNode(true);
  btn.parentNode.replaceChild(fresh, btn);
  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmBillModal'));
  fresh.addEventListener('click', () => { modal.hide(); onConfirm(); });
  modal.show();
}

// ── Post Now ───────────────────────────────────────────────────
function postBill(id, name, amount, date) {
  document.getElementById('postBillModalName').textContent = name;
  document.getElementById('postBillAmountInput').value = parseFloat(amount).toFixed(2);
  document.getElementById('postBillDateInput').value = date;

  const oldBtn = document.getElementById('postBillConfirmBtn');
  const btn = oldBtn.cloneNode(true);
  oldBtn.parentNode.replaceChild(btn, oldBtn);

  const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('postBillModal'));
  btn.addEventListener('click', () => {
    const amt = parseFloat(document.getElementById('postBillAmountInput').value);
    const dt  = document.getElementById('postBillDateInput').value;
    if (!amt || amt <= 0) { document.getElementById('postBillAmountInput').focus(); return; }
    if (!dt)              { document.getElementById('postBillDateInput').focus(); return; }
    document.getElementById('postBillId').value           = id;
    document.getElementById('postBillAmountHidden').value = amt.toFixed(2);
    document.getElementById('postBillDateHidden').value   = dt;
    modal.hide();
    document.getElementById('postBillForm').submit();
  });
  modal.show();
  document.getElementById('postBillAmountInput').select();
}

// ── Skip ───────────────────────────────────────────────────────
function skipBill(id, name) {
  showBillConfirm(
    'Skip Period',
    'Skip "' + name + '" this period?',
    'The due date will advance to the next occurrence without creating a transaction.',
    'btn-secondary',
    () => {
      document.getElementById('skipBillId').value = id;
      document.getElementById('skipBillForm').submit();
    }
  );
}

// ── Delete ─────────────────────────────────────────────────────
function deleteBill(id, name) {
  showBillConfirm(
    'Delete Scheduled Item',
    'Delete "' + name + '"?',
    'This action cannot be undone.',
    'btn-danger',
    () => {
      document.getElementById('deleteBillId').value = id;
      document.getElementById('deleteBillForm').submit();
    }
  );
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Payee transaction history popup ───────────────────────────
function showPayeeTransactions(payee, event) {
  event.preventDefault();
  const searchUrl = '<?= BASE_PATH ?>/transactions/search?q=' + encodeURIComponent(payee);

  document.getElementById('payeeHistoryTitleText').textContent = payee;
  document.getElementById('payeeHistoryFullLink').href = searchUrl;
  document.getElementById('payeeHistoryBody').innerHTML =
    '<div class="d-flex justify-content-center align-items-center p-4 text-muted">' +
    '<div class="spinner-border spinner-border-sm me-2"></div> Loading&hellip;</div>';

  bootstrap.Modal.getOrCreateInstance(document.getElementById('payeeHistoryModal')).show();

  fetch(searchUrl + '&ajax=1')
    .then(r => r.json())
    .then(json => {
      if (!json.ok || !json.results || !json.results.length) {
        document.getElementById('payeeHistoryBody').innerHTML =
          '<div class="text-center text-muted p-4"><i class="bi bi-inbox fs-2 d-block mb-2"></i>No transactions found for this payee.</div>';
        return;
      }

      const fmtAmt = v => {
        const n = Math.abs(parseFloat(v) || 0);
        return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      };
      const fmtDate = s => {
        if (!s) return '';
        const [y, m, d] = s.split('-');
        const mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return (mo[parseInt(m,10)-1] || m) + ' ' + parseInt(d,10) + ', ' + y;
      };

      let html = '';

      // Summary bar
      if (json.totals) {
        const debit  = parseFloat(json.totals.total_debit  || 0);
        const credit = parseFloat(json.totals.total_credit || 0);
        const net    = credit - debit;
        html += '<div class="search-summary-bar">';
        html += '<span><i class="bi bi-arrow-up-circle text-danger"></i> Payments: <strong class="amount-debit">' + fmtAmt(debit) + '</strong></span>';
        html += '<span><i class="bi bi-arrow-down-circle text-success"></i> Deposits: <strong class="amount-credit">' + fmtAmt(credit) + '</strong></span>';
        html += '<span>Net: <strong class="' + (net >= 0 ? 'amount-credit' : 'amount-debit') + '">' + (net < 0 ? '-' : '') + fmtAmt(net) + '</strong></span>';
        html += '<span class="ms-auto text-muted small">' + json.count + ' transaction' + (json.count !== 1 ? 's' : '') + (json.count >= 500 ? ' <span class="text-warning" title="Results are limited to 500 — there may be more"><i class="bi bi-exclamation-triangle-fill"></i> limited to 500</span>' : '') + '</span>';
        html += '</div>';
      }

      // Table
      html += '<div class="register-grid-wrapper"><table class="register-grid search-results-table"><thead><tr>';
      html += '<th>Date</th><th>Account</th><th>Num</th><th>Payee / Memo</th><th>Category</th><th>C</th>';
      html += '<th class="text-end">Payment</th><th class="text-end">Deposit</th>';
      html += '</tr></thead><tbody>';

      json.results.forEach(function (txn) {
        const amt     = parseFloat(txn.amount);
        const payment = amt < 0 ? Math.abs(amt) : 0;
        const deposit = amt >= 0 ? amt : 0;
        const regUrl  = '<?= BASE_PATH ?>/accounts/register?id=' + txn.account_id;

        let cleared = '';
        if (txn.cleared_status === 'cleared')    cleared = '<span class="cleared-c" title="Cleared">c</span>';
        else if (txn.cleared_status === 'reconciled') cleared = '<span class="cleared-r" title="Reconciled">R</span>';

        let cat = '<span class="text-muted">—</span>';
        if (txn.is_split)                                     cat = '<em class="text-muted">-- Split --</em>';
        else if (txn.category_name && txn.subcategory_name)   cat = escHtml(txn.category_name) + ' &rsaquo; ' + escHtml(txn.subcategory_name);
        else if (txn.category_name)                           cat = escHtml(txn.category_name);

        html += '<tr class="register-row search-result-row" onclick="window.open(\'' + regUrl + '\',\'_blank\')" title="Open in register">';
        html += '<td class="col-date text-nowrap">' + fmtDate(txn.transaction_date) + '</td>';
        html += '<td class="col-acct-name"><a href="' + regUrl + '" class="search-acct-link" onclick="event.stopPropagation()" target="_blank">' + escHtml(txn.account_name) + '</a></td>';
        html += '<td class="col-num">' + escHtml(txn.num || '') + '</td>';
        html += '<td class="col-payee"><div class="payee-name">' + escHtml(txn.payee) + '</div>' + (txn.memo ? '<div class="txn-memo">' + escHtml(txn.memo) + '</div>' : '') + '</td>';
        html += '<td class="col-cat">' + cat + '</td>';
        html += '<td class="col-c">' + cleared + '</td>';
        html += '<td class="col-payment text-end">' + (payment > 0 ? '<span class="amount-debit">' + fmtAmt(payment) + '</span>' : '') + '</td>';
        html += '<td class="col-deposit text-end">' + (deposit > 0 ? '<span class="amount-credit">' + fmtAmt(deposit) + '</span>' : '') + '</td>';
        html += '</tr>';
      });

      html += '</tbody></table></div>';
      document.getElementById('payeeHistoryBody').innerHTML = html;
    })
    .catch(function () {
      document.getElementById('payeeHistoryBody').innerHTML =
        '<div class="text-center text-danger p-4"><i class="bi bi-exclamation-triangle-fill me-2"></i>Failed to load transactions.</div>';
    });

  return false;
}

<?php if ($prefill): ?>
// Pre-populate from Make Recurring redirect
document.addEventListener('DOMContentLoaded', function () {
  showBillForm();
  const set = (id, val) => { const el = document.getElementById(id); if (el && val !== null && val !== '') el.value = val; };
  set('bill_type',       <?= json_encode($prefill['type']) ?>);
  set('bill_account',    <?= $prefill['account_id']    ?: 'null' ?>);
  set('bill_to_account', <?= $prefill['to_account_id'] ?: 'null' ?>);
  set('bill_amount',     <?= $prefill['amount'] > 0 ? number_format($prefill['amount'], 2, '.', '') : 'null' ?>);
  set('bill_notes',      <?= json_encode($prefill['notes']) ?>);
  set('bill_name',       <?= json_encode($prefill['name']) ?>);
  billTypeChanged();
  set('bill_category', <?= $prefill['category_id'] ?? 'null' ?>);
  billLoadSubcat(<?= (int)($prefill['subcategory_id'] ?? 0) ?>);
  billApplyPayeeSuggestion(); // fills category from history if not already set
  // Frequency is the one thing the user must pick — focus it
  document.getElementById('bill_frequency').focus();
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
