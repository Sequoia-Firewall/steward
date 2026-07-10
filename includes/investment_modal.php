<?php
// Shared investment create/edit modal.
// Requires: BASE_PATH JS const defined by the including page.
$invTypes = ['Stock','Bond','CD or Savings Bond','Money Market','Mutual Fund','ETF','Index','Cryptocurrency','Other'];
?>
<!-- ── Investment Modal ────────────────────────────────────── -->
<div class="modal fade" id="investmentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-md">
    <div class="modal-content invest-modal">
      <div class="modal-header invest-modal-header">
        <h5 class="modal-title" id="investmentModalTitle">
          <i class="bi bi-graph-up-arrow"></i> New Investment
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body invest-modal-body">
        <div class="row g-3">
          <div class="col-8">
            <label class="form-label required">Name</label>
            <input type="text" id="invName" class="form-control" placeholder="e.g. Apple Inc." maxlength="200">
          </div>
          <div class="col-4">
            <label class="form-label">Symbol / Ticker</label>
            <div class="input-group">
              <input type="text" id="invSymbol" class="form-control" placeholder="e.g. AAPL" maxlength="20"
                     autocomplete="off" autocapitalize="characters">
              <button type="button" class="btn btn-outline-secondary" id="invLookupBtn"
                      onclick="lookupTicker()" title="Look up name and type from Yahoo Finance">
                <span id="invLookupIcon"><i class="bi bi-search"></i></span>
              </button>
            </div>
            <div id="invLookupStatus" class="form-text" style="display:none"></div>
          </div>
          <div class="col-4">
            <label class="form-label">CUSIP</label>
            <input type="text" id="invCusip" class="form-control" placeholder="e.g. 037833100"
                   maxlength="9" autocomplete="off" style="font-family:monospace">
            <div class="form-text">9-character identifier</div>
          </div>
          <div class="col-4">
            <label class="form-label required">Type</label>
            <select id="invType" class="form-select">
              <?php foreach ($invTypes as $t): ?>
              <option value="<?= h($t) ?>"><?= h($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Country</label>
            <input type="text" id="invCountry" class="form-control" placeholder="e.g. USA" maxlength="100">
          </div>
          <div class="col-12">
            <label class="form-label">Memo</label>
            <textarea id="invMemo" class="form-control" rows="2" placeholder="Optional notes"></textarea>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="invDisableQuotes">
              <label class="form-check-label" for="invDisableQuotes">
                Disable online quote download for this investment
              </label>
            </div>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="invWatchlist">
              <label class="form-check-label" for="invWatchlist">
                Add to watchlist
              </label>
            </div>
          </div>
        </div>
        <div id="invModalError" class="text-danger mt-2 small" style="display:none"></div>
      </div>
      <div class="modal-footer invest-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveInvestmentModal()">
          <i class="bi bi-check-circle"></i> Save
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Callback set by the caller (e.g. register page) to receive the saved investment
window._invModalCallback = null;
// ID being edited (0 = new)
window._invModalId = 0;

function openInvestmentModal(inv, onSuccess) {
  window._invModalCallback = onSuccess || null;
  window._invModalId = inv ? (inv.id || 0) : 0;

  document.getElementById('invName').value          = inv ? (inv.name    || '') : '';
  document.getElementById('invSymbol').value        = inv ? (inv.symbol  || '') : '';
  document.getElementById('invCusip').value         = inv ? (inv.cusip   || '') : '';
  document.getElementById('invType').value          = inv ? (inv.type    || 'Stock') : 'Stock';
  document.getElementById('invCountry').value       = inv ? (inv.country || '') : '';
  document.getElementById('invMemo').value          = inv ? (inv.memo    || '') : '';
  document.getElementById('invDisableQuotes').checked = inv ? (!!inv.disable_quotes) : false;
  document.getElementById('invWatchlist').checked      = inv ? (!!inv.in_watchlist)    : false;
  document.getElementById('invModalError').style.display = 'none';
  document.getElementById('invLookupStatus').style.display = 'none';
  document.getElementById('invLookupIcon').innerHTML = '<i class="bi bi-search"></i>';

  document.getElementById('investmentModalTitle').innerHTML =
    '<i class="bi bi-graph-up-arrow"></i> ' + (window._invModalId ? 'Edit Investment' : 'New Investment');

  bootstrap.Modal.getOrCreateInstance(document.getElementById('investmentModal')).show();
  setTimeout(() => document.getElementById('invName').focus(), 300);
}

async function lookupTicker() {
  const symbol  = document.getElementById('invSymbol').value.trim().toUpperCase();
  const iconEl  = document.getElementById('invLookupIcon');
  const statusEl = document.getElementById('invLookupStatus');

  if (!symbol) return;

  iconEl.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
  statusEl.style.display = 'none';
  document.getElementById('invLookupBtn').disabled = true;

  try {
    const resp = await fetch(BASE_PATH + '/portfolio/ticker_lookup.php?symbol=' + encodeURIComponent(symbol));
    const json = await resp.json();

    if (json.ok) {
      const nameEl = document.getElementById('invName');
      if (!nameEl.value.trim()) nameEl.value = json.name;
      document.getElementById('invType').value = json.type;
      iconEl.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>';
      statusEl.className = 'form-text text-success';
      statusEl.textContent = json.name || 'Symbol recognised';
      statusEl.style.display = 'block';
    } else {
      iconEl.innerHTML = '<i class="bi bi-exclamation-circle text-warning"></i>';
      statusEl.className = 'form-text text-warning';
      statusEl.textContent = json.error || 'Symbol not found';
      statusEl.style.display = 'block';
    }
  } catch {
    iconEl.innerHTML = '<i class="bi bi-search"></i>';
    statusEl.style.display = 'none';
  } finally {
    document.getElementById('invLookupBtn').disabled = false;
  }
}

// Auto-lookup on blur when creating a new investment and name is still empty
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('invSymbol').addEventListener('blur', function () {
    if (window._invModalId === 0 && this.value.trim() && !document.getElementById('invName').value.trim()) {
      lookupTicker();
    }
  });
});

async function saveInvestmentModal() {
  const name          = document.getElementById('invName').value.trim();
  const symbol        = document.getElementById('invSymbol').value.trim();
  const cusip         = document.getElementById('invCusip').value.trim().toUpperCase();
  const type          = document.getElementById('invType').value;
  const country       = document.getElementById('invCountry').value.trim();
  const memo          = document.getElementById('invMemo').value.trim();
  const disableQuotes = document.getElementById('invDisableQuotes').checked;
  const inWatchlist   = document.getElementById('invWatchlist').checked;
  const errEl         = document.getElementById('invModalError');

  if (!name) {
    errEl.textContent = 'Investment name is required.';
    errEl.style.display = 'block';
    document.getElementById('invName').focus();
    return;
  }
  errEl.style.display = 'none';

  const data = new FormData();
  data.append('csrf_token', '<?= h(csrfToken()) ?>');
  data.append('id',      window._invModalId || '');
  data.append('name',    name);
  data.append('symbol',  symbol);
  data.append('cusip',   cusip);
  data.append('type',    type);
  data.append('country', country);
  data.append('memo',    memo);
  if (disableQuotes) data.append('disable_quotes', '1');
  if (inWatchlist)   data.append('in_watchlist',   '1');

  try {
    const resp = await fetch(BASE_PATH + '/portfolio/save.php', { method: 'POST', body: data });
    const json = await resp.json();
    if (json.ok) {
      bootstrap.Modal.getOrCreateInstance(document.getElementById('investmentModal')).hide();
      if (window._invModalCallback) {
        window._invModalCallback(json.investment);
      } else {
        location.reload();
      }
    } else {
      errEl.textContent = json.error || 'Save failed.';
      errEl.style.display = 'block';
    }
  } catch (e) {
    console.error(e);
    errEl.textContent = 'Network error.';
    errEl.style.display = 'block';
  }
}
</script>
