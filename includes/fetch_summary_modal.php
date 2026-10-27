<?php
// Fetch Summary modal, shared by the Portfolio page and the dashboard. The last
// summary is kept in localStorage under one key, so a fetch run from either page
// can be recalled from Portfolio's "Last Fetch Summary" button. Pages call
// showFetchSummary(data, mode) with the JSON from portfolio/fetch_prices.
?>
<!-- Fetch Summary Modal -->
<div class="modal fade" id="fetchSummaryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content confirm-modal">
      <div class="modal-header confirm-modal-header">
        <h5 class="modal-title" id="fetchSummaryTitle">
          <i class="bi bi-cloud-check"></i> Fetch Summary
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <div id="fetchSummaryTotals" class="px-3 py-2 border-bottom small text-muted"></div>
        <table class="table table-sm mb-0" id="fetchSummaryTable">
          <thead>
            <tr>
              <th class="sortable" data-col="symbol">Symbol <i class="bi bi-arrow-down-up sort-icon"></i></th>
              <th class="sortable" data-col="name">Name <i class="bi bi-arrow-down-up sort-icon"></i></th>
              <th class="sortable" data-col="source">Source <i class="bi bi-arrow-down-up sort-icon"></i></th>
              <th class="text-end sortable fetch-summary-col-records" data-col="records">Records <i class="bi bi-arrow-down-up sort-icon"></i></th>
              <th class="text-end sortable" data-col="lastquote">Last Quote <i class="bi bi-arrow-down-up sort-icon"></i></th>
              <th class="text-end sortable" data-col="changepct">Change % <i class="bi bi-arrow-down-up sort-icon"></i></th>
              <th class="sortable" data-col="status">Status <i class="bi bi-arrow-down-up sort-icon"></i></th>
            </tr>
          </thead>
          <tbody id="fetchSummaryBody"></tbody>
        </table>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary btn-sm" id="fetchSummaryClose" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
const FETCH_SUMMARY_LS_KEY = 'portfolio_lastFetchSummary_v1';
let lastFetchSummaryPayload = null;
let lastFetchSummaryMode = 'latest';

function fsEsc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Shows/hides the page's recall buttons, if it has them (Portfolio does)
function syncFetchSummaryButtons() {
  const hasSummary = !!(lastFetchSummaryPayload && Array.isArray(lastFetchSummaryPayload.results));
  const summaryBtn = document.getElementById('btnLastFetchSummary');
  const historyBtn = document.getElementById('btnFetchHistory');
  if (summaryBtn) summaryBtn.style.display = hasSummary ? '' : 'none';
  if (historyBtn) historyBtn.style.display = hasSummary ? '' : 'none';
}

function loadSavedFetchSummary() {
  try {
    const raw = localStorage.getItem(FETCH_SUMMARY_LS_KEY);
    if (!raw) return;
    const saved = JSON.parse(raw);
    if (!saved || typeof saved !== 'object' || !saved.data || !Array.isArray(saved.data.results)) return;
    lastFetchSummaryPayload = saved.data;
    lastFetchSummaryMode = saved.mode === 'history' ? 'history' : 'latest';
  } catch (e) {
    // Ignore malformed or unavailable localStorage.
  }
}

function saveFetchSummary(data, mode) {
  try {
    localStorage.setItem(FETCH_SUMMARY_LS_KEY, JSON.stringify({
      mode: mode === 'history' ? 'history' : 'latest',
      data,
      saved_at: Date.now(),
    }));
  } catch (e) {
    // Ignore storage write failures.
  }
}

function openLastFetchSummary() {
  if (!lastFetchSummaryPayload) return;
  showFetchSummary(lastFetchSummaryPayload, lastFetchSummaryMode, false);
}

function showFetchSummary(data, mode, remember = true) {
  const results  = data.results || [];
  const fbCount  = results.filter(r => r.fallback).length;

  lastFetchSummaryPayload = data;
  lastFetchSummaryMode = mode === 'history' ? 'history' : 'latest';
  if (remember) saveFetchSummary(data, lastFetchSummaryMode);
  syncFetchSummaryButtons();

  // Totals line
  const totals = document.getElementById('fetchSummaryTotals');
  let totHtml = `<strong>${data.updated}</strong> price record${data.updated !== 1 ? 's' : ''} stored`;
  if (data.skipped) totHtml += `, <strong class="text-danger">${data.skipped}</strong> skipped`;
  if (fbCount)      totHtml += `, <strong class="text-warning">${fbCount}</strong> via Yahoo fallback`;
  totals.innerHTML = totHtml;

  // Per-symbol rows
  const tbody = document.getElementById('fetchSummaryBody');
  tbody.innerHTML = '';
  results.forEach(r => {
    const tr = document.createElement('tr');
    const srcHtml = r.source ? fsEsc(r.source) : '<span class="text-muted">—</span>';
    const countHtml = `<span class="text-muted">${r.count ?? 0}</span>`;
    const quote = (r.last_quote !== null && r.last_quote !== undefined) ? Number(r.last_quote) : null;
    const changePct = (r.change_pct !== null && r.change_pct !== undefined) ? Number(r.change_pct) : null;
    const quoteHtml = quote === null || Number.isNaN(quote)
      ? '<span class="text-muted">—</span>'
      : `<span class="text-nowrap">${quote.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 })}</span>`;
    const changeHtml = changePct === null || Number.isNaN(changePct)
      ? '<span class="text-muted">—</span>'
      : `<span class="inv-gain ${changePct >= 0 ? 'gain-pos' : 'gain-neg'}">${changePct >= 0 ? '+' : ''}${changePct.toFixed(2)}%</span>`;
    const statusHtml = r.status === 'ok'
      ? `<span class="text-success"><i class="bi bi-check-circle-fill"></i></span>`
      : `<span class="text-danger" title="${fsEsc(r.message)}"><i class="bi bi-x-circle-fill"></i> ${fsEsc(r.message)}</span>`;
    tr.dataset.symbol    = r.symbol || '';
    tr.dataset.name      = r.name || '';
    tr.dataset.source    = r.source || '';
    tr.dataset.records   = r.count ?? 0;
    tr.dataset.lastquote = quote === null || Number.isNaN(quote) ? '' : quote;
    tr.dataset.changepct = changePct === null || Number.isNaN(changePct) ? '' : changePct;
    tr.dataset.status    = r.status || '';
    tr.innerHTML =
      `<td><span class="inv-symbol">${fsEsc(r.symbol)}</span></td>` +
      `<td class="small text-muted">${fsEsc(r.name)}</td>` +
      `<td class="small">${srcHtml}</td>` +
      `<td class="text-end small fetch-summary-col-records">${countHtml}</td>` +
      `<td class="text-end small">${quoteHtml}</td>` +
      `<td class="text-end small">${changeHtml}</td>` +
      `<td class="small">${statusHtml}</td>`;
    tbody.appendChild(tr);
  });

  // Records column only applies to history fetches
  const recVisible = mode === 'history';
  document.querySelectorAll('#fetchSummaryTable .fetch-summary-col-records').forEach(el => {
    el.style.display = recVisible ? '' : 'none';
  });

  // Hide the history modal first if the page has one open, then show the summary
  const histEl = document.getElementById('historyModal');
  const histModal = histEl ? bootstrap.Modal.getInstance(histEl) : null;
  if (histModal) histModal.hide();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('fetchSummaryModal')).show();
}

loadSavedFetchSummary();

// Table sorting and the recall buttons are wired once the page is fully parsed:
// the including page's own markup (buttons, other tables) may come after this script.
document.addEventListener('DOMContentLoaded', function () {
  syncFetchSummaryButtons();

  const numCols = new Set(['records', 'lastquote', 'changepct']);
  let sortCol = null, sortDir = 'asc';

  function getVal(row, col) {
    const raw = row.dataset[col];
    if (raw === '' || raw === undefined || raw === null) return null;
    return numCols.has(col) ? parseFloat(raw) : raw;
  }

  const table = document.getElementById('fetchSummaryTable');
  if (!table) return;
  const tbody = document.getElementById('fetchSummaryBody');

  table.querySelectorAll('th.sortable').forEach(th => {
    th.style.cursor = 'pointer';
    th.addEventListener('click', () => {
      const col = th.dataset.col;
      sortDir = (sortCol === col && sortDir === 'asc') ? 'desc' : 'asc';
      sortCol = col;

      table.querySelectorAll('th.sortable').forEach(t => {
        const icon = t.querySelector('.sort-icon');
        if (!icon) return;
        icon.className = 'bi sort-icon ' + (t.dataset.col === col
          ? (sortDir === 'asc' ? 'bi-sort-up-alt' : 'bi-sort-down-alt')
          : 'bi-arrow-down-up');
      });

      const rows = [...tbody.querySelectorAll('tr')];
      const dir  = sortDir === 'asc' ? 1 : -1;
      rows.sort((a, b) => {
        const av = getVal(a, col), bv = getVal(b, col);
        if (av === bv)   return 0;
        if (av === null) return 1;
        if (bv === null) return -1;
        return typeof av === 'string' ? dir * av.localeCompare(bv) : dir * (av - bv);
      });
      rows.forEach(r => tbody.appendChild(r));
    });
  });
});
</script>
