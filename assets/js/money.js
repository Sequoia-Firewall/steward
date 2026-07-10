/* ================================================================
   Steward — Main JavaScript
   ================================================================ */

'use strict';

// ── Form collapse ──────────────────────────────────────────────
(function () {
  const btn = document.getElementById('btnCollapseForm');
  const wrapper = document.getElementById('txnFormWrapper');
  if (!btn || !wrapper) return;

  const tabs = wrapper.querySelector('.txn-tabs');
  const form = wrapper.querySelector('#txnForm');
  let collapsed = false;

  btn.addEventListener('click', () => {
    collapsed = !collapsed;
    if (tabs) tabs.style.display = collapsed ? 'none' : '';
    if (form) form.style.display = collapsed ? 'none' : '';
    btn.classList.toggle('collapsed', collapsed);
  });
})();

// ── Tab switching ──────────────────────────────────────────────
function switchTab(tab) {
  // Carry shared fields from the current panel into the new one
  const typeField = document.getElementById('txnType');
  if (typeField && typeField.value !== tab) {
    const fromS = typeField.value === 'withdrawal' ? 'w' : typeField.value === 'deposit' ? 'd' : 't';
    const toS   = tab === 'withdrawal' ? 'w' : tab === 'deposit' ? 'd' : 't';
    ['date', 'payee', 'amount', 'memo', 'cleared', 'num'].forEach(f => {
      const src = document.getElementById(f + '_' + fromS);
      const dst = document.getElementById(f + '_' + toS);
      if (src && dst) dst.value = src.value;
    });
  }
  document.querySelectorAll('.txn-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.tab === tab);
  });
  document.querySelectorAll('.txn-panel').forEach(p => {
    p.classList.toggle('hidden', p.id !== 'panel-' + tab);
  });
  if (typeField) typeField.value = tab;
}

// ── Load subcategories dynamically ────────────────────────────
function loadSubcategories(suffix, selectedId) {
  const catSel    = document.getElementById('category_' + suffix);
  const subcatSel = document.getElementById('subcategory_' + suffix);
  if (!catSel || !subcatSel) return;

  const parentId = parseInt(catSel.value, 10);
  subcatSel.innerHTML = '<option value="">-- None --</option>';

  if (!parentId || typeof categoryData === 'undefined') return;

  const parent = categoryData.find(c => c.id === parentId);
  if (!parent || !parent.children || !parent.children.length) return;

  parent.children.forEach(sub => {
    const opt = document.createElement('option');
    opt.value = sub.id;
    opt.textContent = sub.name;
    if (selectedId && sub.id == selectedId) opt.selected = true;
    subcatSel.appendChild(opt);
  });
}

// ── Split rows ─────────────────────────────────────────────────
let splitCounters = { w: 0, d: 0 };

function buildCatOptions(selectedId) {
  if (typeof categoryData === 'undefined') return '';
  let html = '<option value="">-- Category --</option>';
  const groups = { expense: 'EXPENSES', income: 'INCOME', transfer: 'TRANSFERS' };
  Object.entries(groups).forEach(([type, label]) => {
    const cats = categoryData.filter(c => c.type === type);
    if (!cats.length) return;
    html += `<optgroup label="${label}">`;
    cats.forEach(cat => {
      html += `<option value="${cat.id}"${cat.id == selectedId ? ' selected' : ''}>${escHtml(cat.name)}</option>`;
    });
    html += '</optgroup>';
  });
  return html;
}

function buildSubcatOptions(parentId, selectedId) {
  if (typeof categoryData === 'undefined') return '<option value="">-- None --</option>';
  const parent = categoryData.find(c => c.id == parentId);
  let html = '<option value="">-- None --</option>';
  if (parent && parent.children) {
    parent.children.forEach(sub => {
      html += `<option value="${sub.id}"${sub.id == selectedId ? ' selected' : ''}>${escHtml(sub.name)}</option>`;
    });
  }
  return html;
}

function addSplitRow(suffix, catId, subcatId, amount, memo) {
  const container = document.getElementById('splitRows_' + suffix);
  if (!container) return;
  const i = splitCounters[suffix]++;
  const row = document.createElement('div');
  row.className = 'split-row';
  row.dataset.idx = i;
  row.innerHTML = `
    <select name="split_cat_${suffix}_${i}" class="form-select split-cat"
            onchange="updateSplitSubcat(this, '${suffix}', ${i})">${buildCatOptions(catId)}</select>
    <select name="split_subcat_${suffix}_${i}" class="form-select split-subcat">${buildSubcatOptions(catId, subcatId)}</select>
    <div class="input-group split-amt">
      <span class="input-group-text" style="padding:2px 6px">$</span>
      <input type="number" name="split_amount_${suffix}_${i}" class="form-control"
             step="0.01" min="0" placeholder="0.00" value="${(amount !== undefined && amount !== null && amount !== '') ? Math.abs(amount).toFixed(2) : ''}"
             oninput="updateSplitTotal('${suffix}')">
    </div>
    <input type="text" name="split_memo_${suffix}_${i}" class="form-control split-memo"
           placeholder="Memo" value="${escHtml(memo || '')}">
    <button type="button" class="btn-split-del" title="Remove"
            onclick="this.closest('.split-row').remove(); updateSplitTotal('${suffix}')">
      <i class="bi bi-x-circle-fill"></i>
    </button>`;
  container.appendChild(row);
  updateSplitTotal(suffix);
}

function updateSplitTotal(suffix) {
  const container = document.getElementById('splitRows_' + suffix);
  if (!container) return;
  let totalsEl = document.getElementById('splitTotals_' + suffix);

  const rows = container.querySelectorAll('.split-row');
  if (rows.length === 0) {
    if (totalsEl) totalsEl.style.display = 'none';
    return;
  }

  let splitTotal = 0;
  container.querySelectorAll('input[type="number"]').forEach(inp => {
    splitTotal += parseFloat(inp.value || '0') || 0;
  });

  const txnAmt   = parseFloat(document.getElementById('amount_' + suffix)?.value || '0') || 0;
  const remaining = Math.round((txnAmt - splitTotal) * 100) / 100;
  const ok        = Math.abs(remaining) < 0.005;
  const fmt       = n => '$' + Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

  if (!totalsEl) {
    totalsEl = document.createElement('div');
    totalsEl.id = 'splitTotals_' + suffix;
    totalsEl.className = 'split-totals';
    container.after(totalsEl);
  }
  totalsEl.style.display = '';
  totalsEl.innerHTML =
    `<span>Allocated: <strong>${fmt(splitTotal)}</strong></span>` +
    `<span style="color:var(--ms-border)">·</span>` +
    `<span class="split-remaining ${ok ? 'split-ok' : 'split-warn'}">` +
    `Remaining: <strong>${ok ? '$0.00' : fmt(remaining)}</strong></span>`;
}

function updateSplitSubcat(catSel, suffix, i) {
  const parentId  = parseInt(catSel.value, 10);
  const subcatSel = catSel.closest('.split-row').querySelector(`[name="split_subcat_${suffix}_${i}"]`);
  if (subcatSel) subcatSel.innerHTML = buildSubcatOptions(parentId, 0);
}

function clearSplits(suffix) {
  const container = document.getElementById('splitRows_' + suffix);
  if (container) { container.innerHTML = ''; splitCounters[suffix] = 0; }
}

// ── Submit Transaction ─────────────────────────────────────────
async function submitTransaction(afterSave = null) {
  const form   = document.getElementById('txnForm');
  const status = document.getElementById('txnStatus');
  if (!form) return;

  const type = document.getElementById('txnType')?.value || 'withdrawal';
  const suffix = type === 'withdrawal' ? 'w' : type === 'deposit' ? 'd' : 't';

  // Basic validation
  const dateField   = document.getElementById('date_'   + suffix);
  const amountField = document.getElementById('amount_' + suffix);

  if (dateField && !dateField.value) { showStatus('Please enter a date.', 'error'); dateField.focus(); return; }

  if (type !== 'transfer') {
    const amt = parseFloat(amountField?.value || '0');
    if (isNaN(amt) || amt < 0) { showStatus('Please enter a valid amount.', 'error'); amountField?.focus(); return; }

    // Validate split total when split rows are present
    const splitContainer = document.getElementById('splitRows_' + suffix);
    if (splitContainer && splitContainer.querySelectorAll('.split-row').length > 0) {
      let splitTotal = 0;
      splitContainer.querySelectorAll('input[type="number"]').forEach(inp => {
        splitTotal += parseFloat(inp.value || '0') || 0;
      });
      splitTotal = Math.round(splitTotal * 100) / 100;
      if (Math.abs(splitTotal - amt) > 0.005) {
        showStatus(
          'Split total ($' + splitTotal.toFixed(2) + ') must equal the transaction amount ($' + amt.toFixed(2) + ').',
          'error'
        );
        return;
      }
    }
  } else {
    const amt = parseFloat(document.getElementById('amount_t')?.value || '0');
    if (isNaN(amt) || amt < 0) { showStatus('Please enter a valid amount.', 'error'); return; }
    const fromAcc = document.getElementById('from_account')?.value;
    const toAcc   = document.getElementById('to_account')?.value;
    if (!fromAcc || !toAcc || fromAcc === toAcc) {
      showStatus('Transfer requires two different accounts.', 'error'); return;
    }
  }

  const data = new FormData(form);
  showStatus('<i class="bi bi-arrow-repeat spin"></i> Saving...', 'info');

  try {
    const resp = await fetch(BASE_PATH + '/transactions/save.php', { method: 'POST', body: data });
    const json = await resp.json();
    if (json.ok) {
      playCashRegister();
      if (afterSave) {
        afterSave();
      } else {
        showStatus('<i class="bi bi-check-circle-fill text-success"></i> Saved!', 'success');
        setTimeout(() => location.reload(), 600);
      }
    } else if (json.confirm_duplicate_num) {
      showDuplicateNumModal(json.confirm_duplicate_num, data, afterSave);
    } else if (json.confirm_bill) {
      showBillMatchModal(json.confirm_bill, data, afterSave);
    } else {
      showStatus('<i class="bi bi-exclamation-triangle-fill text-danger"></i> ' + escHtml(json.error || 'Error'), 'error');
    }
  } catch (e) {
    console.error(e);
    showStatus('Network error. Please try again.', 'error');
  }
}

function showBillMatchModal(bill, originalData, afterSave) {
  const modal = document.getElementById('billMatchModal');
  if (!modal) return;

  const dueDate = new Date(bill.next_due + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  const amt     = '$' + Math.abs(bill.amount).toFixed(2);
  const acct = bill.account ? '<span class="text-muted"> in ' + escHtml(bill.account) + '</span>' : '';
  document.getElementById('billMatchBody').innerHTML =
    'Is this transaction for the scheduled bill <strong>' + escHtml(bill.name) + '</strong>' + acct + ' (' + escHtml(amt) + ', due ' + escHtml(dueDate) + ')?<br>' +
    '<span class="text-muted small">Clicking "Yes" will mark the scheduled occurrence as paid and advance its due date.</span>';

  const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
  bsModal.show();

  const yesBtn = document.getElementById('billMatchYes');
  const noBtn  = document.getElementById('billMatchNo');

  const cleanup = () => { yesBtn.onclick = null; noBtn.onclick = null; };

  yesBtn.onclick = async () => {
    bsModal.hide();
    cleanup();
    originalData.set('skip_bill_id', bill.id);
    showStatus('<i class="bi bi-arrow-repeat spin"></i> Saving...', 'info');
    try {
      const resp2 = await fetch(BASE_PATH + '/transactions/save.php', { method: 'POST', body: originalData });
      const json2 = await resp2.json();
      if (json2.ok) {
        playCashRegister();
        if (afterSave) { afterSave(); } else {
          showStatus('<i class="bi bi-check-circle-fill text-success"></i> Saved!', 'success');
          setTimeout(() => location.reload(), 600);
        }
      } else {
        showStatus('<i class="bi bi-exclamation-triangle-fill text-danger"></i> ' + escHtml(json2.error || 'Error'), 'error');
      }
    } catch (e) {
      showStatus('Network error. Please try again.', 'error');
    }
  };

  noBtn.onclick = async () => {
    bsModal.hide();
    cleanup();
    originalData.set('skip_bill_id', '0');
    showStatus('<i class="bi bi-arrow-repeat spin"></i> Saving...', 'info');
    try {
      const resp2 = await fetch(BASE_PATH + '/transactions/save.php', { method: 'POST', body: originalData });
      const json2 = await resp2.json();
      if (json2.ok) {
        playCashRegister();
        if (afterSave) { afterSave(); } else {
          showStatus('<i class="bi bi-check-circle-fill text-success"></i> Saved!', 'success');
          setTimeout(() => location.reload(), 600);
        }
      } else {
        showStatus('<i class="bi bi-exclamation-triangle-fill text-danger"></i> ' + escHtml(json2.error || 'Error'), 'error');
      }
    } catch (e) {
      showStatus('Network error. Please try again.', 'error');
    }
  };
}

function showDuplicateNumModal(dup, originalData, afterSave) {
  const modal = document.getElementById('duplicateNumModal');
  if (!modal) return;

  document.getElementById('duplicateNumBody').innerHTML =
    'Check / reference number <strong>' + escHtml(dup.num) + '</strong> already exists in <strong>' + escHtml(dup.account) + '</strong>.<br>' +
    '<span class="text-muted small">Do you want to save this transaction anyway?</span>';

  const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
  bsModal.show();

  const yesBtn = document.getElementById('duplicateNumYes');
  const noBtn  = document.getElementById('duplicateNumNo');

  const cleanup = () => { yesBtn.onclick = null; noBtn.onclick = null; };

  yesBtn.onclick = async () => {
    bsModal.hide();
    cleanup();
    originalData.set('confirm_duplicate_num', '1');
    showStatus('<i class="bi bi-arrow-repeat spin"></i> Saving...', 'info');
    try {
      const resp2 = await fetch(BASE_PATH + '/transactions/save.php', { method: 'POST', body: originalData });
      const json2 = await resp2.json();
      if (json2.ok) {
        playCashRegister();
        if (afterSave) { afterSave(); } else {
          showStatus('<i class="bi bi-check-circle-fill text-success"></i> Saved!', 'success');
          setTimeout(() => location.reload(), 600);
        }
      } else if (json2.confirm_bill) {
        showBillMatchModal(json2.confirm_bill, originalData, afterSave);
      } else {
        showStatus('<i class="bi bi-exclamation-triangle-fill text-danger"></i> ' + escHtml(json2.error || 'Error'), 'error');
      }
    } catch (e) {
      showStatus('Network error. Please try again.', 'error');
    }
  };

  noBtn.onclick = () => {
    bsModal.hide();
    cleanup();
    showStatus('', '');
  };
}

function showStatus(html, type) {
  const el = document.getElementById('txnStatus');
  if (!el) return;
  el.innerHTML = html;
  el.style.color = type === 'error' ? '#b91c1c' : type === 'success' ? '#1a6b2e' : '#444';
  if (type === 'error') playErrorSound();
}

// ── Cancel Transaction (reset form) ───────────────────────────
function cancelTransaction() {
  const form = document.getElementById('txnForm');
  if (!form) return;
  // Deselect the highlighted row
  document.querySelectorAll('.register-row.selected').forEach(r => r.classList.remove('selected'));
  // Hide delete button — only relevant when editing an existing transaction
  const deleteBtn = document.getElementById('btnDeleteTxn');
  if (deleteBtn) deleteBtn.style.display = 'none';
  form.reset();
  document.getElementById('txnId').value = '';
  document.getElementById('txnFormTitle').textContent = 'New Transaction';
  // Reset selects to today's date
  ['w', 'd', 't'].forEach(s => {
    const d = document.getElementById('date_' + s);
    if (d) d.value = new Date().toISOString().split('T')[0];
  });
  // Clear splits
  clearSplits('w'); clearSplits('d');
  // Reset subcategories and clear any suggestion hints / mismatch warnings
  ['w', 'd'].forEach(s => {
    const sc = document.getElementById('subcategory_' + s);
    if (sc) sc.innerHTML = '<option value="">-- None --</option>';
    clearCatHint(s);
    hideCatMismatch(s);
  });
  document.getElementById('txnStatus').innerHTML = '';
  // switchTab restores Make Recurring button visibility
  switchTab('withdrawal');
}

// ── Select / highlight a register row ─────────────────────────
function selectTransaction(id) {
  // Deselect any previously selected row
  document.querySelectorAll('.register-row.selected').forEach(r => r.classList.remove('selected'));
  // Highlight the clicked row
  const row = document.querySelector(`.register-row[data-id="${id}"]`);
  if (row) row.classList.add('selected');
  // Load transaction into the form
  editTransaction(id, window.scrollY);
}

// ── Edit Transaction ───────────────────────────────────────────
async function editTransaction(id, savedScrollY) {
  try {
    const resp = await fetch(BASE_PATH + '/transactions/get.php?id=' + id);
    const json = await resp.json();
    if (!json.ok) { showToast('Error loading transaction.', 'error'); return; }

    if (json.is_investment_reciprocal) {
      // Cash-side leg of a buy/sell/div/int/reinvest — only editable from the security register.
      const row = document.querySelector(`.register-row[data-id="${id}"]`);
      if (row) row.classList.remove('selected');
      const link = json.investment_account_id
        ? ' <a href="' + BASE_PATH + '/accounts/register?id=' + json.investment_account_id
          + (json.investment_txn_id ? '&txn=' + json.investment_txn_id : '') + '">Go to security register &raquo;</a>'
        : '';
      showToast('This transaction is linked to a security-register entry and can\'t be edited here.' + link, 'info');
      return;
    }

    const txn     = json.txn;
    const splits  = json.splits;
    const type    = txn.type;
    const suffix  = type === 'withdrawal' ? 'w' : type === 'deposit' ? 'd' : 't';

    // Show delete button only when user is allowed to delete this transaction
    const deleteBtn = document.getElementById('btnDeleteTxn');
    if (deleteBtn) {
      const canDelete = IS_ADMIN || txn.cleared_status !== 'reconciled';
      deleteBtn.style.display = canDelete ? '' : 'none';
    }

    // Switch to correct tab
    switchTab(type);
    document.getElementById('txnId').value  = txn.id;
    document.getElementById('txnType').value = type;
    document.getElementById('txnFormTitle').textContent = 'Edit Transaction #' + txn.id;

    // Populate fields
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };

    if (type === 'transfer') {
      set('num_t',     txn.num);
      set('date_t',    txn.transaction_date);
      set('payee_t',   txn.payee);
      set('memo_t',    txn.memo || '');
      set('cleared_t', txn.cleared_status);
      // Set from/to accounts based on transaction direction
      if (txn.amount < 0) {
        set('from_account', txn.account_id);
        if (json.paired_account) set('to_account', json.paired_account);
      } else {
        set('to_account', txn.account_id);
        if (json.paired_account) set('from_account', json.paired_account);
      }
      set('amount_t', Math.abs(txn.amount).toFixed(2));
    } else {
      set('num_'    + suffix, txn.num);
      set('date_'   + suffix, txn.transaction_date);
      set('payee_'  + suffix, txn.payee);
      set('memo_'   + suffix, txn.memo || '');
      set('cleared_'+ suffix, txn.cleared_status);
      set('amount_' + suffix, Math.abs(txn.amount).toFixed(2));

      // Categories
      clearSplits(suffix);
      hideCatMismatch(suffix);
      if (splits.length > 1) {
        // Split transaction — no single category to check
        splits.forEach(sp => {
          addSplitRow(suffix, sp.category_id, sp.subcategory_id, sp.amount, sp.memo);
        });
      } else if (splits.length === 1) {
        const sp = splits[0];
        set('category_'    + suffix, sp.category_id || '');
        loadSubcategories(suffix, sp.subcategory_id);
        if (sp.subcategory_id) {
          setTimeout(() => { set('subcategory_' + suffix, sp.subcategory_id); }, 50);
        }
        checkCategoryMismatch(suffix);
      }
    }

    // Restore scroll position so the selected row stays in view
    if (savedScrollY !== undefined) {
      window.scrollTo({ top: savedScrollY, behavior: 'instant' });
    } else {
      const wrapper = document.getElementById('txnFormWrapper');
      if (wrapper) wrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

  } catch (e) {
    showToast('Error loading transaction: ' + e.message, 'error');
  }
}

// ── Custom confirmation modal ──────────────────────────────────
function showConfirm(payee, onConfirm) {
  const modal  = document.getElementById('confirmDeleteModal');
  if (!modal) { appConfirm('Delete Transaction', 'Delete "' + payee + '"?', 'This cannot be undone.', onConfirm, 'Delete'); return; }
  document.getElementById('confirmDeleteMsg').textContent = 'Delete transaction "' + payee + '"?';
  const btn    = document.getElementById('confirmDeleteBtn');
  const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
  // Replace listener to avoid stacking handlers from repeated calls
  const fresh  = btn.cloneNode(true);
  btn.parentNode.replaceChild(fresh, btn);
  fresh.addEventListener('click', () => { bsModal.hide(); onConfirm(); });
  bsModal.show();
}

// ── Delete Transaction (from row trash icon) ───────────────────
function deleteTransaction(id, payee) {
  showConfirm(payee, () => {
    document.getElementById('deleteTxnId').value = id;
    document.getElementById('deleteTxnForm').submit();
  });
}

// ── Delete Transaction (from form Delete button) ───────────────
function deleteCurrentTransaction() {
  const txnId = document.getElementById('txnId').value;
  if (!txnId) return;
  const row   = document.querySelector('.register-row.selected');
  const payee = row ? (row.querySelector('.payee-name')?.textContent?.trim() || 'this transaction') : 'this transaction';
  showConfirm(payee, () => {
    document.getElementById('deleteTxnId').value = txnId;
    document.getElementById('deleteTxnForm').submit();
  });
}

// ── Payee → Category Suggestion ───────────────────────────────
function applyPayeeSuggestion(suffix) {
  if (typeof PAYEE_CATEGORIES === 'undefined' || typeof categoryData === 'undefined') return;
  const payeeEl = document.getElementById('payee_' + suffix);
  const catEl   = document.getElementById('category_' + suffix);
  if (!payeeEl || !catEl) return;

  const payee      = payeeEl.value.trim();
  const suggestion = PAYEE_CATEGORIES[payee];

  // Only fill when category is still blank and we have a suggestion
  if (!suggestion || catEl.value !== '') return;

  catEl.value = suggestion.cat;
  loadSubcategories(suffix, suggestion.subcat);

  // Build display label
  const parent = categoryData.find(c => c.id === suggestion.cat);
  if (!parent) return;
  let label = parent.name;
  if (suggestion.subcat) {
    const sub = parent.children?.find(c => c.id === suggestion.subcat);
    if (sub) label += ' › ' + sub.name;
  }
  showCatHint(suffix, label);
}

function showCatHint(suffix, label) {
  clearCatHint(suffix);
  const catField = document.getElementById('category_' + suffix)?.closest('.txn-field');
  if (!catField) return;
  const hint = document.createElement('div');
  hint.id        = 'catHint_' + suffix;
  hint.className = 'cat-suggestion-hint';
  hint.innerHTML = `<i class="bi bi-magic"></i> Suggested: <strong>${escHtml(label)}</strong>`
    + `<button type="button" class="cat-hint-clear" title="Clear suggestion"
              onclick="clearCatSuggestion('${suffix}')"><i class="bi bi-x"></i></button>`;
  catField.appendChild(hint);
}

function clearCatHint(suffix) {
  document.getElementById('catHint_' + suffix)?.remove();
}

function clearCatSuggestion(suffix) {
  clearCatHint(suffix);
  const catEl    = document.getElementById('category_' + suffix);
  const subcatEl = document.getElementById('subcategory_' + suffix);
  if (catEl)    catEl.value = '';
  if (subcatEl) subcatEl.innerHTML = '<option value="">-- None --</option>';
}

// ── Category mismatch warning ──────────────────────────────────
function checkCategoryMismatch(suffix) {
  if (typeof categoryData === 'undefined') return;
  const catEl = document.getElementById('category_' + suffix);
  if (!catEl || !catEl.value) { hideCatMismatch(suffix); return; }
  const cat = categoryData.find(c => c.id === parseInt(catEl.value, 10));
  if (!cat) { hideCatMismatch(suffix); return; }
  const mismatch = (suffix === 'w' && cat.type === 'income') ||
                   (suffix === 'd' && cat.type === 'expense');
  if (mismatch) {
    const msg = suffix === 'w'
      ? '⚠ Income category on a payment — are you sure?'
      : '⚠ Expense category on a deposit — are you sure?';
    showCatMismatch(suffix, msg);
  } else {
    hideCatMismatch(suffix);
  }
}

function showCatMismatch(suffix, msg) {
  let warn = document.getElementById('catMismatch_' + suffix);
  const catEl = document.getElementById('category_' + suffix);
  if (!warn && catEl) {
    warn = document.createElement('div');
    warn.id        = 'catMismatch_' + suffix;
    warn.className = 'cat-mismatch-warn';
    catEl.closest('.txn-field').appendChild(warn);
  }
  if (warn) warn.textContent = msg;
  if (catEl) catEl.classList.add('cat-mismatch-field');
}

function hideCatMismatch(suffix) {
  const warn = document.getElementById('catMismatch_' + suffix);
  if (warn) warn.textContent = '';
  const catEl = document.getElementById('category_' + suffix);
  if (catEl) catEl.classList.remove('cat-mismatch-field');
}

// ── Utilities ──────────────────────────────────────────────────
function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ── Payee Combobox ─────────────────────────────────────────────
function initPayeeCombobox(input, payees) {
  // Disconnect native datalist; we handle the dropdown ourselves
  input.removeAttribute('list');

  // Wrap input + button + list
  const wrapper = document.createElement('div');
  wrapper.className = 'payee-combo';
  input.parentNode.insertBefore(wrapper, input);
  wrapper.appendChild(input);

  const btn = document.createElement('button');
  btn.type      = 'button';
  btn.className = 'payee-combo-btn';
  btn.tabIndex  = -1;
  btn.title     = 'Show payees';
  btn.innerHTML = '<i class="bi bi-chevron-down"></i>';
  wrapper.appendChild(btn);

  const list = document.createElement('ul');
  list.className = 'payee-combo-list';
  list.style.display = 'none';
  wrapper.appendChild(list);

  let activeIdx = -1;

  function filtered() {
    const q = input.value.toLowerCase().trim();
    if (!q) return payees;
    // Prefix-only: typing "am" should suggest "Amazon", not "Stamps.com".
    // payees[] arrives pre-ranked by frequency/recency, so order is preserved.
    return payees.filter(p => p.toLowerCase().startsWith(q));
  }

  function render(items) {
    list.innerHTML = '';
    activeIdx = -1;
    const visible = items.slice(0, 25);
    visible.forEach(p => {
      const li = document.createElement('li');
      li.textContent = p;
      li.addEventListener('mousedown', e => {
        e.preventDefault();
        input.value = p;
        hide();
        input.dispatchEvent(new Event('change'));
      });
      list.appendChild(li);
    });
    list.style.display = visible.length ? 'block' : 'none';
  }

  function show(all) {
    render(all ? payees : filtered());
  }

  function hide() {
    list.style.display = 'none';
    activeIdx = -1;
  }

  function setActive(idx) {
    const items = list.querySelectorAll('li');
    items.forEach((li, i) => li.classList.toggle('pc-active', i === idx));
    if (idx >= 0 && items[idx]) items[idx].scrollIntoView({ block: 'nearest' });
    activeIdx = idx;
  }

  input.addEventListener('focus', () => show(false));
  input.addEventListener('input', () => show(false));
  input.addEventListener('blur',  () => setTimeout(hide, 160));

  btn.addEventListener('mousedown', e => {
    e.preventDefault();
    if (list.style.display === 'none') { input.focus(); show(true); }
    else hide();
  });

  input.addEventListener('keydown', e => {
    if (list.style.display === 'none') return;
    const items = list.querySelectorAll('li');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActive(Math.min(activeIdx + 1, items.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActive(Math.max(activeIdx - 1, -1));
    } else if (e.key === 'Enter' && activeIdx >= 0) {
      e.preventDefault();
      input.value = items[activeIdx].textContent;
      hide();
      input.dispatchEvent(new Event('change'));
    } else if (e.key === 'Escape') {
      hide();
    }
  });
}

function initPayeeComboboxes() {
  const datalist = document.getElementById('payeeList');
  if (!datalist) return;
  const payees = Array.from(datalist.options).map(o => o.value).filter(Boolean);
  if (!payees.length) return;
  document.querySelectorAll('input[list="payeeList"]').forEach(input => {
    initPayeeCombobox(input, payees);
  });
}

// ── Wire payee suggestion events ──────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  initPayeeComboboxes();
  ['w', 'd'].forEach(suffix => {
    const payeeEl = document.getElementById('payee_' + suffix);
    const catEl   = document.getElementById('category_' + suffix);
    if (payeeEl) {
      payeeEl.addEventListener('change', () => applyPayeeSuggestion(suffix));
    }
    if (catEl) {
      catEl.addEventListener('change', () => {
        clearCatHint(suffix);
        checkCategoryMismatch(suffix);
      });
    }
  });
});

// ── Cash register sound (Web Audio API, no file required) ─────
function playCashRegister() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    const t   = ctx.currentTime;

    // "Cha" — short filtered noise burst (mechanical drawer)
    const buf = ctx.createBuffer(1, Math.ceil(ctx.sampleRate * 0.09), ctx.sampleRate);
    const nd  = buf.getChannelData(0);
    for (let i = 0; i < nd.length; i++) nd[i] = Math.random() * 2 - 1;
    const ns  = ctx.createBufferSource();
    ns.buffer = buf;
    const bf  = ctx.createBiquadFilter();
    bf.type            = 'bandpass';
    bf.frequency.value = 1100;
    bf.Q.value         = 1.8;
    const ng = ctx.createGain();
    ng.gain.setValueAtTime(0.22, t);
    ng.gain.exponentialRampToValueAtTime(0.001, t + 0.09);
    ns.connect(bf); bf.connect(ng); ng.connect(ctx.destination);
    ns.start(t); ns.stop(t + 0.09);

    // "Ching" — two bell tones (A5 then E6)
    [[880, t + 0.06, 0.55], [1318.5, t + 0.10, 0.45]].forEach(([freq, start, dur]) => {
      const osc = ctx.createOscillator();
      const g   = ctx.createGain();
      osc.type           = 'sine';
      osc.frequency.value = freq;
      g.gain.setValueAtTime(0.28, start);
      g.gain.exponentialRampToValueAtTime(0.001, start + dur);
      osc.connect(g); g.connect(ctx.destination);
      osc.start(start); osc.stop(start + dur);
    });

    setTimeout(() => ctx.close().catch(() => {}), 900);
  } catch (_) { /* silently ignore if audio unavailable */ }
}

// ── Error sound (descending two-tone buzz) ────────────────────
function playErrorSound() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    const t   = ctx.currentTime;

    // Two descending square-wave tones — clearly "wrong"
    [[380, t,       0.13],
     [260, t + 0.12, 0.20]].forEach(([freq, start, dur]) => {
      const osc = ctx.createOscillator();
      const g   = ctx.createGain();
      osc.type            = 'square';
      osc.frequency.value = freq;
      g.gain.setValueAtTime(0.12, start);
      g.gain.exponentialRampToValueAtTime(0.001, start + dur);
      osc.connect(g); g.connect(ctx.destination);
      osc.start(start); osc.stop(start + dur);
    });

    setTimeout(() => ctx.close().catch(() => {}), 500);
  } catch (_) { /* silently ignore if audio unavailable */ }
}

// ── CSS animation for spinner ──────────────────────────────────
const style = document.createElement('style');
style.textContent = `
  @keyframes spin { to { transform: rotate(360deg); } }
  .spin { display: inline-block; animation: spin .6s linear infinite; }
`;
document.head.appendChild(style);

// ── Toast notifications ────────────────────────────────────────
function showToast(html, type, options) {
  type = type || 'info';
  options = options || {};
  let container = document.getElementById('msToastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'msToastContainer';
    document.body.appendChild(container);
  }
  const toast = document.createElement('div');
  toast.className = 'ms-toast ms-toast-' + type;
  toast.innerHTML =
    '<div class="ms-toast-body">' + html + '</div>' +
    '<button class="ms-toast-close" title="Dismiss" ' +
    'onclick="this.closest(\'.ms-toast\').remove()">×</button>';
  container.appendChild(toast);

  let autoTimer = null;

  function scheduleAutoDismiss(ms) {
    clearTimeout(autoTimer);
    autoTimer = setTimeout(dismiss, ms);
  }

  function dismiss() {
    clearTimeout(autoTimer);
    toast.classList.add('ms-toast-dismiss');
    setTimeout(function() { toast.remove(); }, 280);
  }

  function update(newHtml, newType, newOptions) {
    newType    = newType    || 'info';
    newOptions = newOptions || {};
    clearTimeout(autoTimer);
    toast.className = 'ms-toast ms-toast-' + newType;
    toast.querySelector('.ms-toast-body').innerHTML = newHtml;
    if (newOptions.autoDismiss) scheduleAutoDismiss(newOptions.autoDismiss);
  }

  if (options.autoDismiss) scheduleAutoDismiss(options.autoDismiss);

  return { update: update, dismiss: dismiss };
}

// ── Global confirm modal ───────────────────────────────────────
var _appConfirmCb = null;

function appConfirm(title, message, warnText, onConfirm, confirmLabel) {
  document.getElementById('appConfirmTitle').textContent = title;
  document.getElementById('appConfirmMsg').textContent   = message;
  var warn = document.getElementById('appConfirmWarn');
  if (warnText) {
    document.getElementById('appConfirmWarnText').textContent = warnText;
    warn.style.display = 'block';
  } else {
    warn.style.display = 'none';
  }
  document.getElementById('appConfirmBtn').textContent = confirmLabel || 'Confirm';
  _appConfirmCb = onConfirm;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('appConfirmModal')).show();
}

document.getElementById('appConfirmBtn').addEventListener('click', function () {
  bootstrap.Modal.getInstance(document.getElementById('appConfirmModal')).hide();
  if (_appConfirmCb) _appConfirmCb();
  _appConfirmCb = null;
});

// ── Make Recurring checkbox ────────────────────────────────────
(function () {
  const chk = document.getElementById('chkMakeRecurring');
  if (!chk) return;
  chk.addEventListener('change', function () {
    if (!this.checked) return;
    this.checked = false; // reset in case user navigates back

    const type = document.getElementById('txnType')?.value || 'withdrawal';
    let params;

    if (type === 'transfer') {
      params = new URLSearchParams({
        prefill:          '1',
        pf_name:          (document.getElementById('payee_t')?.value   || '').trim(),
        pf_type:          'transfer',
        pf_account_id:    document.getElementById('from_account')?.value || '',
        pf_to_account_id: document.getElementById('to_account')?.value   || '',
        pf_amount:        Math.abs(parseFloat(document.getElementById('amount_t')?.value || '0')).toFixed(2),
        pf_notes:         (document.getElementById('memo_t')?.value    || '').trim(),
      });
    } else if (type === 'deposit') {
      params = new URLSearchParams({
        prefill:          '1',
        pf_name:          (document.getElementById('payee_d')?.value       || '').trim(),
        pf_type:          'deposit',
        pf_account_id:    typeof ACCOUNT_ID !== 'undefined' ? ACCOUNT_ID : '',
        pf_amount:        Math.abs(parseFloat(document.getElementById('amount_d')?.value || '0')).toFixed(2),
        pf_notes:         (document.getElementById('memo_d')?.value        || '').trim(),
        pf_category_id:   document.getElementById('category_d')?.value    || '',
        pf_subcategory_id:document.getElementById('subcategory_d')?.value  || '',
      });
    } else {
      params = new URLSearchParams({
        prefill:          '1',
        pf_name:          (document.getElementById('payee_w')?.value       || '').trim(),
        pf_type:          'bill',
        pf_account_id:    typeof ACCOUNT_ID !== 'undefined' ? ACCOUNT_ID : '',
        pf_amount:        Math.abs(parseFloat(document.getElementById('amount_w')?.value || '0')).toFixed(2),
        pf_notes:         (document.getElementById('memo_w')?.value        || '').trim(),
        pf_category_id:   document.getElementById('category_w')?.value    || '',
        pf_subcategory_id:document.getElementById('subcategory_w')?.value  || '',
      });
    }

    // Save the transaction first, then redirect to Bills with pre-filled data
    submitTransaction(() => {
      window.location.href = BASE_PATH + '/bills/index?' + params.toString();
    });
  });
})();
