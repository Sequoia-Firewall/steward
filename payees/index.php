<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// ── Load all payees: union of transaction payees + profile-only payees ─
// Left side: every distinct payee name seen in transactions
// Right side: profiles that may have been created for payees with 0 transactions
$payees = $db->query(
    "SELECT
         td.name,
         p.id            AS profile_id,
         p.address,
         p.phone,
         p.website,
         p.account_number,
         p.note,
         p.category_id,
         p.subcategory_id,
         COALESCE(c.name,'')  AS category_name,
         COALESCE(sc.name,'') AS subcategory_name,
         td.txn_count,
         td.last_used,
         td.total_spent,
         td.total_received
     FROM (
         SELECT t.payee AS name,
                COUNT(*)  AS txn_count,
                MAX(t.transaction_date) AS last_used,
                SUM(CASE WHEN t.amount < 0 THEN ABS(t.amount) ELSE 0 END) AS total_spent,
                SUM(CASE WHEN t.amount > 0 THEN t.amount     ELSE 0 END) AS total_received
         FROM transactions t
         JOIN accounts a ON a.id = t.account_id
         WHERE t.payee != '' AND a.type != 'Investment'
         GROUP BY t.payee

         UNION

         SELECT p2.name, 0, NULL, 0, 0
         FROM payees p2
         WHERE p2.name NOT IN (
             SELECT DISTINCT t2.payee FROM transactions t2
             JOIN accounts a2 ON a2.id = t2.account_id
             WHERE t2.payee != '' AND a2.type != 'Investment'
         )
     ) AS td
     LEFT JOIN payees p  ON p.name  = td.name
     LEFT JOIN categories c  ON c.id  = p.category_id
     LEFT JOIN categories sc ON sc.id = p.subcategory_id
     ORDER BY td.name"
)->fetchAll();

// Index by name for JS
$payeeIndex = [];
foreach ($payees as $p) {
    $payeeIndex[$p['name']] = $p;
}

$categoryHierarchy = getAllCategoriesHierarchy();
$allPayeeNames     = array_column($payees, 'name');

$pageTitle   = 'Payees';
$currentPage = 'payees';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
  <h2><i class="bi bi-person-lines-fill"></i> Payees <span class="text-muted fs-5 fw-normal">(<?= count($payees) ?>)</span></h2>
</div>

<!-- ── Search bar ─────────────────────────────────────────────── -->
<div class="payee-search-bar">
  <div class="input-group input-group-sm">
    <span class="input-group-text"><i class="bi bi-search"></i></span>
    <input type="text" id="payeeSearch" class="form-control"
           placeholder="Filter payees by name…" oninput="filterPayees(this.value)">
    <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('payeeSearch').value='';filterPayees('')">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
  <span class="payee-count-badge" id="payeeCountBadge"><?= count($payees) ?> shown</span>
</div>

<!-- ── Payee table ─────────────────────────────────────────────── -->
<div class="payee-table-wrap">
  <table class="table table-sm payee-table" id="payeeTable">
    <thead>
      <tr>
        <th style="width:26px"></th>
        <th>Payee</th>
        <th>Default Category</th>
        <th class="text-end">Transactions</th>
        <th>Last Used</th>
        <th class="text-end">Total Spent</th>
        <?php if (canEdit()): ?>
        <th style="width:160px"></th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($payees as $p):
      $hasProfile = $p['profile_id'] !== null;
      $catLabel   = $p['category_name']
          ? h($p['category_name']) . ($p['subcategory_name'] ? ' › ' . h($p['subcategory_name']) : '')
          : '<span class="text-muted">—</span>';
    ?>
    <!-- ── Data row ───────────────────────────────────────────── -->
    <tr class="payee-row" data-name="<?= h($p['name']) ?>">
      <td class="payee-profile-dot">
        <?php if ($hasProfile): ?>
        <span class="profile-dot" title="Has profile info"></span>
        <?php endif; ?>
      </td>
      <td class="payee-name-cell">
        <a href="<?= BASE_PATH ?>/transactions/search?q=<?= urlencode($p['name']) ?>" class="payee-name-link"><?= h($p['name']) ?></a>
      </td>
      <td class="payee-cat-cell"><?= $catLabel ?></td>
      <td class="text-end text-muted"><?= (int)$p['txn_count'] ?></td>
      <td class="text-muted"><?= $p['last_used'] ? formatDate($p['last_used']) : '—' ?></td>
      <td class="text-end"><?= $p['total_spent'] > 0 ? '<span class="amount-debit">'.formatMoney((float)$p['total_spent']).'</span>' : '<span class="text-muted">—</span>' ?></td>
      <?php if (canEdit()): ?>
      <td class="text-end payee-actions">
        <button class="btn btn-xs btn-outline-secondary" title="Edit profile"
                onclick="toggleEdit(<?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>)">
          <i class="bi bi-pencil"></i> Edit
        </button>
        <button class="btn btn-xs btn-outline-primary" title="Rename payee"
                onclick="openRename(<?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>, <?= (int)$p['txn_count'] ?>)">
          <i class="bi bi-pencil-square"></i> Rename
        </button>
        <button class="btn btn-xs btn-outline-warning" title="Merge into another payee"
                onclick="openMerge(<?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>, <?= (int)$p['txn_count'] ?>)">
          <i class="bi bi-arrow-left-right"></i> Merge
        </button>
      </td>
      <?php endif; ?>
    </tr>

    <!-- ── Edit row (hidden until expanded) ─────────────────────── -->
    <tr class="payee-edit-row" id="edit-<?= md5($p['name']) ?>" style="display:none">
      <td></td>
      <td colspan="<?= canEdit() ? 6 : 5 ?>">
        <div class="payee-edit-panel">
          <input type="hidden" class="pe-name" value="<?= h($p['name']) ?>">
          <div class="pe-grid">
            <div class="pe-field">
              <label>Phone</label>
              <input type="text" class="form-control form-control-sm pe-phone"
                     placeholder="e.g. (555) 123-4567"
                     value="<?= h($p['phone'] ?? '') ?>">
            </div>
            <div class="pe-field">
              <label>Website</label>
              <input type="text" class="form-control form-control-sm pe-website"
                     placeholder="e.g. example.com"
                     value="<?= h($p['website'] ?? '') ?>">
            </div>
            <div class="pe-field">
              <label>Account Number</label>
              <input type="text" class="form-control form-control-sm pe-acctnum"
                     placeholder="Your account # with this payee"
                     value="<?= h($p['account_number'] ?? '') ?>">
            </div>
            <div class="pe-field pe-field-wide">
              <label>Address</label>
              <textarea class="form-control form-control-sm pe-address" rows="2"
                        placeholder="Street, City, State ZIP"><?= h($p['address'] ?? '') ?></textarea>
            </div>
            <div class="pe-field pe-field-wide">
              <label>Note</label>
              <textarea class="form-control form-control-sm pe-note" rows="2"
                        placeholder="Any notes about this payee"><?= h($p['note'] ?? '') ?></textarea>
            </div>
            <div class="pe-field">
              <label>Default Category</label>
              <select class="form-select form-select-sm pe-cat" onchange="loadPeSubcats(this)">
                <option value="">— None —</option>
                <?php foreach ($categoryHierarchy as $cat): ?>
                <option value="<?= $cat['id'] ?>"
                  <?= $p['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                  <?= h($cat['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="pe-field">
              <label>Subcategory</label>
              <select class="form-select form-select-sm pe-subcat">
                <option value="">— None —</option>
                <?php
                // Pre-populate subcats for the selected category
                if ($p['category_id']) {
                    foreach ($categoryHierarchy as $cat) {
                        if ($cat['id'] == $p['category_id'] && !empty($cat['children'])) {
                            foreach ($cat['children'] as $ch) {
                                echo '<option value="' . $ch['id'] . '"'
                                   . ($p['subcategory_id'] == $ch['id'] ? ' selected' : '') . '>'
                                   . h($ch['name']) . '</option>';
                            }
                        }
                    }
                }
                ?>
              </select>
            </div>
          </div>
          <?php if (canEdit()): ?>
          <div class="pe-actions">
            <button class="btn btn-sm btn-primary" onclick="savePayee(this)">
              <i class="bi bi-check-lg"></i> Save Profile
            </button>
            <button class="btn btn-sm btn-outline-secondary ms-2"
                    onclick="toggleEdit(<?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>)">
              Cancel
            </button>
            <?php if ($hasProfile): ?>
            <button class="btn btn-sm btn-outline-danger ms-auto"
                    onclick="deleteProfile(<?= htmlspecialchars(json_encode($p['name']), ENT_QUOTES) ?>)">
              <i class="bi bi-trash"></i> Clear Profile
            </button>
            <?php endif; ?>
            <span class="pe-status ms-3" id="pe-status-<?= md5($p['name']) ?>"></span>
          </div>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="text-muted small" id="payeeNoResults" style="display:none">No payees match your search.</p>

<!-- ── Rename Modal ────────────────────────────────────────────── -->
<div class="modal fade" id="renameModal" tabindex="-1" aria-labelledby="renameMTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--ms-blue);color:#fff">
        <h5 class="modal-title" id="renameMTitle"><i class="bi bi-pencil-square"></i> Rename Payee</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3">
          Renaming <strong id="renameOldDisplay"></strong>
          — <span id="renameTxnCount" class="text-muted"></span>
        </p>
        <input type="hidden" id="renameOldName">
        <div class="mb-3">
          <label class="form-label fw-semibold">New Name</label>
          <input type="text" id="renameNewName" class="form-control" placeholder="New payee name">
        </div>
        <div id="renameError" class="alert alert-danger py-2" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnDoRename" onclick="submitRename()">
          <i class="bi bi-check-lg"></i> Rename
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Merge Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="mergeModal" tabindex="-1" aria-labelledby="mergeMTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--ms-blue);color:#fff">
        <h5 class="modal-title" id="mergeMTitle"><i class="bi bi-arrow-left-right"></i> Merge Payee</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">Merge all transactions from:</p>
        <p class="fw-bold fs-5 mb-3" id="mergeSourceDisplay"></p>
        <div class="mb-3">
          <label class="form-label fw-semibold">Into this payee</label>
          <input type="text" id="mergeTargetInput" class="form-control"
                 placeholder="Type target payee name…" list="mergeTargetList"
                 oninput="updateMergePreview()">
          <datalist id="mergeTargetList"></datalist>
        </div>
        <div id="mergePreview" class="alert alert-warning py-2" style="display:none"></div>
        <div id="mergeError" class="alert alert-danger py-2" style="display:none"></div>
        <p class="text-muted small mb-0">
          <i class="bi bi-exclamation-triangle"></i>
          All transactions from the source payee will be reassigned to the target.
          The source payee profile (if any) will be deleted. This cannot be undone.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning" id="btnDoMerge" onclick="submitMerge()" disabled>
          <i class="bi bi-arrow-left-right"></i> Merge
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Category data for subcategory loading -->
<script>
const CSRF_TOKEN   = <?= json_encode(csrfToken()) ?>;
const BASE_PATH    = <?= json_encode(BASE_PATH) ?>;
const CAN_EDIT     = <?= canEdit() ? 'true' : 'false' ?>;
const CATEGORY_DATA = <?= json_encode($categoryHierarchy, JSON_HEX_TAG) ?>;
const ALL_PAYEES   = <?= json_encode($allPayeeNames, JSON_HEX_TAG) ?>;

// Track which row is currently open
let _openPayee = null;

// ── Filter ────────────────────────────────────────────────────
function filterPayees(q) {
  q = q.trim().toLowerCase();
  let shown = 0;
  document.querySelectorAll('#payeeTable tbody tr.payee-row').forEach(row => {
    const name  = row.dataset.name.toLowerCase();
    const match = !q || name.includes(q);
    row.style.display = match ? '' : 'none';
    // Also hide its edit row if filtering hides the parent
    const editRow = document.getElementById('edit-' + md5name(row.dataset.name));
    if (editRow) editRow.style.display = 'none';
    if (match) shown++;
  });
  if (_openPayee && !document.querySelector(`[data-name="${CSS.escape(_openPayee)}"]`)?.offsetParent) {
    _openPayee = null;
  }
  document.getElementById('payeeCountBadge').textContent = shown + ' shown';
  document.getElementById('payeeNoResults').style.display = shown ? 'none' : '';
}

// Simple MD5-equivalent for row IDs — we just need a stable key
// Use the PHP-generated md5 class attributes already in the DOM
function rowId(name) {
  // The PHP already md5'd names for row IDs; we mirror this with a lookup
  // Instead, we use data-name to find the row then its edit sibling
  const row = document.querySelector(`tr.payee-row[data-name="${CSS.escape(name)}"]`);
  return row ? row.nextElementSibling : null;
}
function md5name(name) {
  // PHP used md5($p['name']) for element IDs; replicate by finding in DOM
  const row = document.querySelector(`tr.payee-row[data-name="${CSS.escape(name)}"]`);
  if (!row) return '';
  const editRow = row.nextElementSibling;
  return editRow ? editRow.id.replace('edit-', '') : '';
}

// ── Toggle edit row ────────────────────────────────────────────
function toggleEdit(name) {
  const editRow = rowId(name);
  if (!editRow) return;
  const isOpen = editRow.style.display !== 'none';

  // Close previously open row
  if (_openPayee && _openPayee !== name) {
    const prev = rowId(_openPayee);
    if (prev) prev.style.display = 'none';
  }

  editRow.style.display = isOpen ? 'none' : '';
  _openPayee = isOpen ? null : name;
}

// ── Load subcategories when category changes ───────────────────
function loadPeSubcats(catSel) {
  const panel  = catSel.closest('.payee-edit-panel');
  const subSel = panel.querySelector('.pe-subcat');
  const catId  = parseInt(catSel.value);
  subSel.innerHTML = '<option value="">— None —</option>';
  if (!catId) return;
  const cat = CATEGORY_DATA.find(c => c.id === catId);
  if (cat && cat.children) {
    cat.children.forEach(ch => {
      const o = document.createElement('option');
      o.value = ch.id; o.textContent = ch.name;
      subSel.appendChild(o);
    });
  }
}

// ── Save profile ───────────────────────────────────────────────
function savePayee(btn) {
  const panel   = btn.closest('.payee-edit-panel');
  const name    = panel.querySelector('.pe-name').value;
  const statusEl = document.getElementById('pe-status-' + md5name(name));

  const body = new URLSearchParams({
    csrf_token:     CSRF_TOKEN,
    action:         'save',
    name:           name,
    phone:          panel.querySelector('.pe-phone').value,
    website:        panel.querySelector('.pe-website').value,
    account_number: panel.querySelector('.pe-acctnum').value,
    address:        panel.querySelector('.pe-address').value,
    note:           panel.querySelector('.pe-note').value,
    category_id:    panel.querySelector('.pe-cat').value,
    subcategory_id: panel.querySelector('.pe-subcat').value,
  });

  btn.disabled = true;
  if (statusEl) statusEl.innerHTML = '<span class="text-muted"><i class="bi bi-arrow-repeat spin"></i> Saving…</span>';

  fetch(BASE_PATH + '/payees/save', { method:'POST', body })
    .then(r => r.json())
    .then(json => {
      btn.disabled = false;
      if (json.ok) {
        if (statusEl) statusEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Saved</span>';
        // Update the profile dot
        const dataRow = document.querySelector(`tr.payee-row[data-name="${CSS.escape(name)}"]`);
        if (dataRow) {
          dataRow.querySelector('.payee-profile-dot').innerHTML = '<span class="profile-dot" title="Has profile info"></span>';
        }
        // Update category display in data row
        const catSel  = panel.querySelector('.pe-cat');
        const subSel  = panel.querySelector('.pe-subcat');
        const catText = catSel.options[catSel.selectedIndex]?.text || '';
        const subText = subSel.value ? subSel.options[subSel.selectedIndex]?.text : '';
        if (dataRow) {
          const catCell = dataRow.querySelector('.payee-cat-cell');
          if (catCell) catCell.innerHTML = catText
            ? (subText ? escHtml(catText)+' &rsaquo; '+escHtml(subText) : escHtml(catText))
            : '<span class="text-muted">—</span>';
        }
        setTimeout(() => { if (statusEl) statusEl.innerHTML = ''; }, 3000);
      } else {
        if (statusEl) statusEl.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> ' + escHtml(json.error) + '</span>';
      }
    })
    .catch((e) => { console.error(e); btn.disabled = false; if (statusEl) statusEl.innerHTML = '<span class="text-danger">Network error</span>'; });
}

// ── Delete profile ─────────────────────────────────────────────
function deleteProfile(name) {
  appConfirm(
    'Clear Profile',
    'Clear profile info for "' + name + '"?',
    'Transactions are not affected.',
    () => {
      fetch(BASE_PATH + '/payees/save', {
        method:'POST',
        body: new URLSearchParams({ csrf_token:CSRF_TOKEN, action:'delete_profile', name })
      })
      .then(r => r.json())
      .then(json => {
        if (json.ok) location.reload();
        else showToast(json.error, 'error');
      });
    },
    'Clear'
  );
}

// ── Rename ─────────────────────────────────────────────────────
function openRename(name, count) {
  document.getElementById('renameOldDisplay').textContent = name;
  document.getElementById('renameOldName').value = name;
  document.getElementById('renameNewName').value = name;
  document.getElementById('renameTxnCount').textContent = count + ' transaction' + (count!==1?'s':'') + ' will be updated';
  document.getElementById('renameError').style.display = 'none';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('renameModal')).show();
  setTimeout(() => document.getElementById('renameNewName').select(), 300);
}

function submitRename() {
  const oldName = document.getElementById('renameOldName').value;
  const newName = document.getElementById('renameNewName').value.trim();
  const errEl   = document.getElementById('renameError');
  errEl.style.display = 'none';
  if (!newName) { errEl.textContent = 'Please enter a new name.'; errEl.style.display=''; return; }

  const btn = document.getElementById('btnDoRename');
  btn.disabled = true;
  fetch(BASE_PATH + '/payees/save', {
    method:'POST',
    body: new URLSearchParams({ csrf_token:CSRF_TOKEN, action:'rename', old_name:oldName, new_name:newName })
  })
  .then(r => r.json())
  .then(json => {
    btn.disabled = false;
    if (json.ok) {
      bootstrap.Modal.getInstance(document.getElementById('renameModal')).hide();
      location.reload();
    } else {
      errEl.textContent = json.error;
      errEl.style.display = '';
    }
  })
  .catch((e) => { console.error(e); btn.disabled = false; errEl.textContent = 'Network error.'; errEl.style.display=''; });
}

document.getElementById('renameNewName')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') submitRename();
});

// ── Merge ──────────────────────────────────────────────────────
let _mergeSource = '';
let _mergeSourceCount = 0;

function openMerge(name, count) {
  _mergeSource      = name;
  _mergeSourceCount = count;
  document.getElementById('mergeSourceDisplay').textContent = name + ' (' + count + ' transaction' + (count!==1?'s':'') + ')';
  document.getElementById('mergeTargetInput').value = '';
  document.getElementById('mergePreview').style.display = 'none';
  document.getElementById('mergeError').style.display = 'none';
  document.getElementById('btnDoMerge').disabled = true;

  // Populate datalist with all payees except this one
  const dl = document.getElementById('mergeTargetList');
  dl.innerHTML = '';
  ALL_PAYEES.filter(n => n !== name).forEach(n => {
    const o = document.createElement('option'); o.value = n; dl.appendChild(o);
  });

  bootstrap.Modal.getOrCreateInstance(document.getElementById('mergeModal')).show();
  setTimeout(() => document.getElementById('mergeTargetInput').focus(), 300);
}

function updateMergePreview() {
  const target  = document.getElementById('mergeTargetInput').value.trim();
  const preview = document.getElementById('mergePreview');
  const btn     = document.getElementById('btnDoMerge');
  if (!target || target === _mergeSource) {
    preview.style.display = 'none';
    btn.disabled = true;
    return;
  }
  const targetExists = ALL_PAYEES.includes(target);
  preview.style.display = '';
  preview.className = 'alert py-2 ' + (targetExists ? 'alert-warning' : 'alert-info');
  preview.innerHTML = targetExists
    ? '<i class="bi bi-arrow-left-right"></i> <strong>' + _mergeSourceCount + '</strong> transaction' + (_mergeSourceCount!==1?'s':'') + ' from <em>' + escHtml(_mergeSource) + '</em> will be moved to <em>' + escHtml(target) + '</em>.'
    : '<i class="bi bi-info-circle"></i> Target "<em>' + escHtml(target) + '</em>" does not exist yet — it will be created.';
  btn.disabled = false;
}

function submitMerge() {
  const target = document.getElementById('mergeTargetInput').value.trim();
  const errEl  = document.getElementById('mergeError');
  errEl.style.display = 'none';
  if (!target || target === _mergeSource) {
    errEl.textContent = 'Please select a valid target payee.'; errEl.style.display=''; return;
  }
  const btn = document.getElementById('btnDoMerge');
  btn.disabled = true;
  fetch(BASE_PATH + '/payees/save', {
    method:'POST',
    body: new URLSearchParams({ csrf_token:CSRF_TOKEN, action:'merge', source_name:_mergeSource, target_name:target })
  })
  .then(r => r.json())
  .then(json => {
    btn.disabled = false;
    if (json.ok) {
      bootstrap.Modal.getInstance(document.getElementById('mergeModal')).hide();
      location.reload();
    } else {
      errEl.textContent = json.error; errEl.style.display='';
    }
  })
  .catch((e) => { console.error(e); btn.disabled = false; errEl.textContent = 'Network error.'; errEl.style.display=''; });
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
