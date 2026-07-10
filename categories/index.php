<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$pageTitle   = 'Categories';
$currentPage = 'categories';
$hierarchy   = getAllCategoriesHierarchy();

// Transaction count per category (as category_id OR subcategory_id in splits)
$db = getDB();
$txnCounts = array_map('intval', $db->query(
    'SELECT cat_id, SUM(cnt) FROM (
         SELECT category_id    AS cat_id, COUNT(*) AS cnt FROM transaction_splits WHERE category_id    IS NOT NULL GROUP BY category_id
         UNION ALL
         SELECT subcategory_id AS cat_id, COUNT(*) AS cnt FROM transaction_splits WHERE subcategory_id IS NOT NULL GROUP BY subcategory_id
     ) x GROUP BY cat_id'
)->fetchAll(PDO::FETCH_KEY_PAIR));

// Flat list of all active categories for the merge target dropdown
$mergeTargets = [];
foreach ($hierarchy as $cat) {
    $mergeTargets[] = ['id' => (int)$cat['id'], 'label' => $cat['name'],
                       'parent_id' => 0, 'type' => $cat['type']];
    foreach ($cat['children'] as $sub) {
        $mergeTargets[] = ['id' => (int)$sub['id'],
                           'label' => $cat['name'] . ' → ' . $sub['name'],
                           'parent_id' => (int)$cat['id'], 'type' => $sub['type']];
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-tags"></i> Categories</h2>
  <?php if (canEdit()): ?>
  <button class="btn btn-primary btn-sm" onclick="showCategoryForm()">
    <i class="bi bi-plus-circle"></i> New Category
  </button>
  <?php endif; ?>
</div>

<?php if (isAdmin()): ?>
<div id="bulkBar" class="d-none alert alert-secondary py-2 mb-3 d-flex align-items-center gap-3">
  <span id="bulkCount" class="fw-semibold"></span>
  <button class="btn btn-sm btn-danger" onclick="bulkDelete()">
    <i class="bi bi-trash"></i> Delete Selected
  </button>
  <button class="btn btn-sm btn-outline-secondary" onclick="clearAll()">Clear selection</button>
</div>
<?php endif; ?>

<?php if (canEdit()): ?>
<div class="form-card mb-3" id="categoryForm" style="display:none">
  <div class="form-section-title" id="catFormTitle">New Category</div>
  <div id="catEditWarning" class="alert alert-warning py-2 mb-2 small" style="display:none">
    <i class="bi bi-exclamation-triangle-fill"></i> <span id="catEditWarningText"></span>
  </div>
  <form id="catForm" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="cat_id" id="cat_id" value="">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label required">Category Name</label>
        <input type="text" name="name" id="catName" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Parent Category</label>
        <select name="parent_id" id="catParent" class="form-select">
          <option value="">— Top Level —</option>
          <?php foreach ($hierarchy as $cat): ?>
          <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Type</label>
        <select name="type" id="catType" class="form-select">
          <option value="expense">Expense</option>
          <option value="income">Income</option>
        </select>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-primary me-1" onclick="saveCategory()">
          <i class="bi bi-check-circle"></i> Save
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="hideCategoryForm()">Cancel</button>
      </div>
    </div>
    <div class="form-check mt-2">
      <input type="checkbox" class="form-check-input" name="tax_related" id="catTaxRelated" value="1">
      <label class="form-check-label small" for="catTaxRelated">
        Tax-related — include in the Tax Summary report
      </label>
    </div>
    <div id="catError" class="text-danger mt-2" style="display:none"></div>
  </form>
</div>
<?php endif; ?>

<!-- Category table -->
<?php foreach (['expense' => 'Expenses', 'income' => 'Income'] as $type => $label):
  $cats = array_filter($hierarchy, fn($c) => $c['type'] === $type);
  if (empty($cats)) continue;
?>
<section class="dash-section mb-3">
  <h4 class="section-title section-title-<?= $type ?>"><?= $label ?></h4>
  <table class="table table-sm dash-table">
    <thead>
      <tr>
        <?php if (isAdmin()): ?><th style="width:2rem">
          <input type="checkbox" class="form-check-input section-check-all" data-section="<?= $type ?>" title="Select all">
        </th><?php endif; ?>
        <th>Category</th>
        <th>Type</th>
        <th>Subcategories</th>
        <?php if (canEdit()): ?><th>Actions</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($cats as $cat):
        $catCount = $txnCounts[$cat['id']] ?? 0;
      ?>
      <tr>
        <?php if (isAdmin()): ?>
        <td>
          <input type="checkbox" class="form-check-input row-check" data-section="<?= $type ?>"
                 value="<?= $cat['id'] ?>" title="Select <?= h($cat['name']) ?>">
        </td>
        <?php endif; ?>
        <td>
          <strong><a href="<?= BASE_PATH ?>/transactions/search?cat=<?= $cat['id'] ?>" class="text-decoration-none cat-link-<?= $type ?>"><?= h($cat['name']) ?></a></strong>
          <?php if ($catCount > 0): ?>
          <span class="cat-txn-count">(<?= $catCount ?>)</span>
          <?php endif; ?>
          <?php if (!empty($cat['tax_related'])): ?>
          <span class="badge bg-info-subtle text-info-emphasis cat-tax-badge" title="Included in Tax Summary report">Tax</span>
          <?php endif; ?>
        </td>
        <?php $badgeClass = $type === 'expense' ? 'bg-danger' : ($type === 'income' ? 'bg-success' : 'bg-secondary'); ?>
        <td><span class="badge <?= $badgeClass ?>"><?= h($cat['type']) ?></span></td>
        <td>
          <?php if (!empty($cat['children'])): ?>
          <div class="subcat-list">
            <?php foreach ($cat['children'] as $sub):
              $subCount = $txnCounts[$sub['id']] ?? 0;
            ?>
            <span class="subcat-chip">
              <?php if (isAdmin()): ?>
              <input type="checkbox" class="form-check-input row-check subcat-check" data-section="<?= $type ?>"
                     value="<?= $sub['id'] ?>" title="Select <?= h($sub['name']) ?>">
              <?php endif; ?>
              <a href="<?= BASE_PATH ?>/transactions/search?cat=<?= $sub['id'] ?>" class="text-decoration-none cat-link-<?= $type ?>"><?= h($sub['name']) ?></a>
              <?php if ($subCount > 0): ?>
              <span class="subcat-count"><?= $subCount ?></span>
              <?php endif; ?>
              <?php if (!empty($sub['tax_related'])): ?>
              <span class="badge bg-info-subtle text-info-emphasis cat-tax-badge" title="Included in Tax Summary report">Tax</span>
              <?php endif; ?>
              <?php if (canEdit()): ?>
              <button class="subcat-action" title="Edit subcategory"
                      onclick="editCat(<?= $sub['id'] ?>, '<?= h(addslashes($sub['name'])) ?>', <?= $sub['parent_id'] ?>, '<?= h($sub['type']) ?>', <?= $subCount ?>, <?= !empty($sub['tax_related']) ? 'true' : 'false' ?>)">
                <i class="bi bi-pencil"></i>
              </button>
              <?php endif; ?>
              <?php if (isAdmin()): ?>
              <button class="subcat-action" title="Merge subcategory"
                      onclick="mergeCat(<?= $sub['id'] ?>, '<?= h(addslashes($sub['name'])) ?>', <?= $sub['parent_id'] ?>, <?= $subCount ?>)">
                <i class="bi bi-arrow-left-right"></i>
              </button>
              <button class="subcat-action subcat-delete" title="Delete subcategory"
                      onclick="deleteCat(<?= $sub['id'] ?>, '<?= h(addslashes($sub['name'])) ?>', <?= $subCount ?>)">
                <i class="bi bi-x"></i>
              </button>
              <?php endif; ?>
            </span>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <span class="text-muted">—</span>
          <?php endif; ?>
          <?php if (canEdit()): ?>
          <button class="btn btn-sm p-0 ms-1 cat-addsub-<?= $type ?>" onclick="showCategoryForm(0, <?= $cat['id'] ?>, '<?= h(addslashes($cat['type'])) ?>')">
            <i class="bi bi-plus"></i> Add sub
          </button>
          <?php endif; ?>
        </td>
        <?php if (canEdit()): ?>
        <td class="text-nowrap">
          <button class="btn btn-sm btn-outline-secondary" title="Edit"
                  onclick="editCat(<?= $cat['id'] ?>, '<?= h(addslashes($cat['name'])) ?>', '', '<?= h($cat['type']) ?>', <?= $catCount ?>, <?= !empty($cat['tax_related']) ? 'true' : 'false' ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <?php if (isAdmin()): ?>
          <button class="btn btn-sm btn-outline-secondary" title="Merge into another category"
                  onclick="mergeCat(<?= $cat['id'] ?>, '<?= h(addslashes($cat['name'])) ?>', 0, <?= $catCount ?>)">
            <i class="bi bi-arrow-left-right"></i>
          </button>
          <button class="btn btn-sm btn-outline-danger" title="Delete"
                  onclick="deleteCat(<?= $cat['id'] ?>, '<?= h(addslashes($cat['name'])) ?>', <?= $catCount ?>)">
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
<?php endforeach; ?>

<!-- System categories (read-only) -->
<?php
$sysCats = array_filter($hierarchy, fn($c) => !empty($c['is_system']));
if (!empty($sysCats)):
?>
<section class="dash-section mb-3">
  <h4 class="section-title" style="color:var(--text-muted,#6c757d)">
    <i class="bi bi-shield-lock"></i> System Categories
    <span class="small fw-normal ms-2" style="font-size:.8rem">Auto-generated — cannot be renamed or deleted</span>
  </h4>
  <table class="table table-sm dash-table">
    <thead>
      <tr>
        <th>Category</th>
        <th>Type</th>
        <th>Subcategories</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($sysCats as $cat): ?>
      <tr class="text-muted">
        <td><strong><?= h($cat['name']) ?></strong></td>
        <td><span class="badge bg-secondary"><?= h($cat['type']) ?></span></td>
        <td><?php if (!empty($cat['children'])): ?>
          <?php foreach ($cat['children'] as $sub): ?>
          <span class="subcat-chip"><?= h($sub['name']) ?></span>
          <?php endforeach; ?>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<form id="deleteCatForm" method="post" action="<?= BASE_PATH ?>/categories/delete" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="deleteCatId">
  <div id="bulkIds"></div>
</form>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger"><i class="bi bi-trash"></i> <span id="deleteConfirmTitle">Delete Category</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2" id="deleteConfirmMsg"></p>
        <div id="deleteConfirmWarning" class="alert alert-warning py-2 small mb-0" style="display:none">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span id="deleteConfirmWarnText"></span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="deleteConfirmBtn">
          <i class="bi bi-trash"></i> Delete
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Merge Category Modal -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="mergeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-arrow-left-right"></i> Merge Category</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">Merging: <strong id="mergeSourceName"></strong></p>
        <p class="text-muted small mb-3" id="mergeSourceInfo"></p>
        <div class="mb-3">
          <label class="form-label fw-semibold">Merge into</label>
          <select id="mergeTargetSelect" class="form-select">
            <option value="">— select target category —</option>
          </select>
        </div>
        <div id="mergeWarning" class="alert alert-warning py-2 small mb-0" style="display:none">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span id="mergeWarningText"></span>
        </div>
      </div>
      <div class="modal-footer">
        <form id="mergeForm" method="post" action="<?= BASE_PATH ?>/categories/merge">
          <?= csrfField() ?>
          <input type="hidden" name="source_id" id="mergeSourceId">
          <input type="hidden" name="target_id" id="mergeTargetId">
          <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger" id="mergeSubmitBtn" disabled onclick="doMerge()">
            <i class="bi bi-arrow-left-right"></i> Merge &amp; Delete Source
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<style>
.subcat-chip { display:inline-flex; align-items:center; gap:3px; }
.subcat-chip .subcat-check { width:.85rem; height:.85rem; cursor:pointer; flex-shrink:0; }
.subcat-count { font-size:.8rem; color:#495057; background:#e9ecef;
                border-radius:8px; padding:0 6px; line-height:1.6; font-weight:500; }
.subcat-action { border:none; background:none; padding:0 2px; color:#6c757d; cursor:pointer;
                 font-size:.8rem; line-height:1; opacity:.7; }
.subcat-action:hover { opacity:1; color:#495057; }
.subcat-action.subcat-delete:hover { color:#dc3545; }

/* Transaction count in parentheses */
.cat-txn-count { font-size:.85rem; color:#6c757d; margin-left:.3rem; }

/* Tax-related badge */
.cat-tax-badge { font-size:.65rem; font-weight:500; vertical-align:middle; margin-left:.3rem; }

/* Type-colored section titles */
.section-title-expense { color:#c82333; border-bottom-color:#dc3545; }
.section-title-income  { color:#157347; border-bottom-color:#198754; }

/* Type-colored category links */
.cat-link-expense { color:#dc3545 !important; }
.cat-link-expense:hover { color:#a71d2a !important; }
.cat-link-income  { color:#198754 !important; }
.cat-link-income:hover  { color:#0f5132 !important; }
/* "Add sub" button per type */
.cat-addsub-expense { background:none; border:none; color:#dc3545; font-size:.8rem; cursor:pointer; }
.cat-addsub-expense:hover { color:#a71d2a; }
.cat-addsub-income  { background:none; border:none; color:#198754; font-size:.8rem; cursor:pointer; }
.cat-addsub-income:hover  { color:#0f5132; }
</style>

<script>
const allCats   = <?= json_encode($mergeTargets, JSON_HEX_TAG) ?>;
const txnCounts = <?= json_encode($txnCounts, JSON_HEX_TAG) ?>;
let mergeSourceId = 0;

function showCategoryForm(catId = 0, parentId = 0, type = 'expense') {
    document.getElementById('categoryForm').style.display = 'block';
    document.getElementById('cat_id').value    = catId || '';
    document.getElementById('catParent').value = parentId || '';
    document.getElementById('catType').value   = type || 'expense';
    document.getElementById('catFormTitle').textContent = catId ? 'Edit Category' : 'New Category';
    document.getElementById('catEditWarning').style.display = 'none';
    if (!catId) {
        document.getElementById('catName').value = '';
        document.getElementById('catTaxRelated').checked = false;
    }
    document.getElementById('catName').focus();
}
function hideCategoryForm() {
    document.getElementById('categoryForm').style.display = 'none';
}
function editCat(id, name, parentId, type, txnCount, taxRelated) {
    document.getElementById('cat_id').value    = id;
    document.getElementById('catName').value   = name;
    document.getElementById('catParent').value = parentId || '';
    document.getElementById('catType').value   = type;
    document.getElementById('catTaxRelated').checked = !!taxRelated;
    document.getElementById('catFormTitle').textContent = 'Edit Category';
    document.getElementById('categoryForm').style.display = 'block';
    document.getElementById('catName').focus();

    const warn = document.getElementById('catEditWarning');
    if (txnCount > 0) {
        document.getElementById('catEditWarningText').textContent =
            txnCount + ' transaction' + (txnCount !== 1 ? 's' : '') +
            ' use this category. Any name or hierarchy change will be applied to all of them.';
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
}
let pendingDelete = null;

function showDeleteModal(title, msg, warnText, onConfirm) {
    document.getElementById('deleteConfirmTitle').textContent = title;
    document.getElementById('deleteConfirmMsg').textContent   = msg;
    const warn = document.getElementById('deleteConfirmWarning');
    if (warnText) {
        document.getElementById('deleteConfirmWarnText').textContent = warnText;
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
    pendingDelete = onConfirm;
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}

document.getElementById('deleteConfirmBtn').addEventListener('click', function () {
    bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal')).hide();
    if (pendingDelete) pendingDelete();
    pendingDelete = null;
});

function deleteCat(id, name, txnCount) {
    const warn = txnCount > 0
        ? txnCount + ' transaction' + (txnCount !== 1 ? 's' : '') +
          ' use this category and will lose their category assignment.'
        : null;
    showDeleteModal(
        'Delete Category',
        'Delete category "' + name + '"?',
        warn,
        () => {
            document.getElementById('deleteCatId').value = id;
            document.getElementById('bulkIds').innerHTML = '';
            document.getElementById('deleteCatForm').submit();
        }
    );
}
async function saveCategory() {
    const form = document.getElementById('catForm');
    const data = new FormData(form);
    const err  = document.getElementById('catError');
    err.style.display = 'none';
    const res  = await fetch('<?= BASE_PATH ?>/categories/save', { method: 'POST', body: data });
    const json = await res.json();
    if (json.ok) {
        location.reload();
    } else {
        err.textContent   = json.error || 'Save failed';
        err.style.display = 'block';
    }
}

// ── Merge ─────────────────────────────────────────────────────────
function mergeCat(sourceId, sourceName, sourceParentId, txnCount) {
    mergeSourceId = sourceId;
    document.getElementById('mergeSourceId').value = sourceId;
    document.getElementById('mergeSourceName').textContent = sourceName;
    document.getElementById('mergeSourceInfo').textContent = txnCount > 0
        ? txnCount + ' transaction' + (txnCount !== 1 ? 's' : '') + ' will be reassigned to the target.'
        : 'No transactions currently use this category.';

    const sel = document.getElementById('mergeTargetSelect');
    sel.innerHTML = '<option value="">— select target category —</option>';
    allCats.forEach(c => {
        if (c.id === sourceId) return;
        if (c.parent_id === sourceId) return; // skip children of source
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.label + ' [' + c.type + ']';
        sel.appendChild(opt);
    });

    document.getElementById('mergeTargetId').value = '';
    document.getElementById('mergeWarning').style.display = 'none';
    document.getElementById('mergeSubmitBtn').disabled = true;

    new bootstrap.Modal(document.getElementById('mergeModal')).show();
}

document.getElementById('mergeTargetSelect').addEventListener('change', function () {
    const targetId = parseInt(this.value) || 0;
    document.getElementById('mergeTargetId').value = targetId || '';
    document.getElementById('mergeSubmitBtn').disabled = !targetId;

    if (!targetId) { document.getElementById('mergeWarning').style.display = 'none'; return; }

    const target   = allCats.find(c => c.id === targetId);
    const srcCount = txnCounts[mergeSourceId] || 0;
    const children = allCats.filter(c => c.parent_id === mergeSourceId);
    const lines    = [];

    if (srcCount > 0)
        lines.push(srcCount + ' transaction' + (srcCount !== 1 ? 's' : '') + ' will be reassigned to <strong>' + target.label + '</strong>.');
    if (children.length > 0)
        lines.push(children.length + ' subcategor' + (children.length !== 1 ? 'ies' : 'y') + ' will be re-parented to the target.');
    lines.push('The source category will be permanently deleted.');

    document.getElementById('mergeWarningText').innerHTML = lines.join('<br>');
    document.getElementById('mergeWarning').style.display = 'block';
});

function doMerge() {
    document.getElementById('mergeForm').submit();
}

// ── Bulk selection ────────────────────────────────────────────────
const checks    = () => Array.from(document.querySelectorAll('.row-check'));
const checked   = () => checks().filter(c => c.checked);
const bulkBar   = document.getElementById('bulkBar');
const bulkCount = document.getElementById('bulkCount');

function updateBulkBar() {
    const n = checked().length;
    if (bulkBar) {
        bulkBar.classList.toggle('d-none', n === 0);
        bulkCount.textContent = n + ' item' + (n !== 1 ? 's' : '') + ' selected';
    }
    document.querySelectorAll('.section-check-all').forEach(allBox => {
        const sec      = allBox.dataset.section;
        const secChecks = checks().filter(c => c.dataset.section === sec);
        const n2       = secChecks.filter(c => c.checked).length;
        allBox.indeterminate = n2 > 0 && n2 < secChecks.length;
        allBox.checked       = n2 > 0 && n2 === secChecks.length;
    });
}
document.addEventListener('change', e => {
    if (e.target.classList.contains('section-check-all')) {
        const sec = e.target.dataset.section;
        checks().filter(c => c.dataset.section === sec).forEach(c => c.checked = e.target.checked);
    }
    updateBulkBar();
});
function clearAll() {
    checks().forEach(c => c.checked = false);
    document.querySelectorAll('.section-check-all').forEach(c => { c.checked = false; c.indeterminate = false; });
    updateBulkBar();
}
function bulkDelete() {
    const ids = checked().map(c => c.value);
    if (!ids.length) return;
    const totalTxns = ids.reduce((sum, id) => sum + (txnCounts[parseInt(id)] || 0), 0);
    const warn = totalTxns > 0
        ? totalTxns + ' transaction' + (totalTxns !== 1 ? 's' : '') + ' will lose their category assignment.'
        : null;
    showDeleteModal(
        'Delete Selected',
        'Delete ' + ids.length + ' selected item' + (ids.length !== 1 ? 's' : '') + '?',
        warn,
        () => {
            const container = document.getElementById('bulkIds');
            container.innerHTML = '';
            ids.forEach(id => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
                container.appendChild(inp);
            });
            document.getElementById('deleteCatId').value = '';
            document.getElementById('deleteCatForm').submit();
        }
    );
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
