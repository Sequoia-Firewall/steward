<!-- Price History Modal -->
<div class="modal fade" id="priceHistoryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content ph-modal">
      <div class="modal-header ph-modal-header">
        <div>
          <h5 class="modal-title" id="phModalTitle">Price History</h5>
          <div class="ph-modal-sub" id="phModalSub"></div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body ph-modal-body">
        <div id="phLoading" class="text-center py-4"><span class="spinner-border spinner-border-sm"></span> Loading…</div>
        <div id="phContent" style="display:none">
          <!-- Stats bar -->
          <div class="ph-stats-bar" id="phStats"></div>
          <!-- Controls row: range + chart type -->
          <div class="ph-controls-row">
            <div class="ph-range-btns" id="phRangeBtns">
              <button class="ph-range-btn" data-range="1M">1M</button>
              <button class="ph-range-btn" data-range="3M">3M</button>
              <button class="ph-range-btn" data-range="6M">6M</button>
              <button class="ph-range-btn ph-range-active" data-range="1Y">1Y</button>
              <button class="ph-range-btn" data-range="ALL">All</button>
            </div>
            <div class="ph-chart-type-btns" id="phChartTypeBtns" style="display:none">
              <button class="ph-chart-type-btn ph-chart-type-active" data-type="line">Line</button>
              <button class="ph-chart-type-btn" data-type="candle">Candle</button>
            </div>
          </div>
          <!-- Chart + compare panel -->
          <div class="ph-chart-area">
            <div class="ph-chart-wrap">
              <canvas id="phChart"></canvas>
            </div>
            <div class="ph-compare-wrap" id="phCompareWrap">
              <div class="ph-compare-label">Compare index</div>
              <select id="phIndexSelect" class="ph-index-select">
                <option value="">— None —</option>
              </select>
              <div class="ph-holding-info" id="phHoldingInfo"></div>
            </div>
          </div>
          <!-- Table -->
          <div class="ph-table-wrap">
            <table class="table table-sm ph-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th class="text-end">Open</th>
                  <th class="text-end">High</th>
                  <th class="text-end">Low</th>
                  <th class="text-end">Close</th>
                  <th class="text-end">Volume</th>
                  <th class="text-end">Chg%</th>
                  <th class="text-muted">Src</th>
                </tr>
              </thead>
              <tbody id="phTableBody"></tbody>
            </table>
          </div>
        </div>
        <div id="phEmpty" style="display:none" class="text-center text-muted py-4">
          <i class="bi bi-graph-up" style="font-size:2rem"></i>
          <p class="mt-2 mb-0">No price history yet.<br>
          Use <strong>Fetch History</strong> or <strong>Edit Prices</strong> to add prices.</p>
        </div>
      </div>
      <div class="modal-footer py-2 ph-modal-footer">
        <button type="button" class="btn btn-outline-light btn-sm" id="phEditPricesBtn">
          <i class="bi bi-pencil-square"></i> Edit Prices
        </button>
        <button type="button" class="btn btn-outline-light btn-sm" id="phRefreshPriceBtn"
                title="Fetch latest price from online provider" style="display:none">
          <i class="bi bi-cloud-download"></i> Refresh Price
        </button>
        <button type="button" class="btn btn-outline-light btn-sm" id="phDownloadCsvBtn" title="Download price history as CSV">
          <i class="bi bi-download"></i> CSV
        </button>
        <button type="button" class="btn btn-outline-light btn-sm" id="phUploadCsvBtn" title="Upload price history from CSV">
          <i class="bi bi-upload"></i> CSV
        </button>
        <input type="file" id="phCsvFileInput" accept=".csv" style="display:none">
        <span id="phCsvStatus" class="small ms-1" style="display:none"></span>
        <span id="phRefreshStatus" class="small ms-1" style="display:none"></span>
        <?php if (canEdit()): ?>
        <button type="button" class="btn btn-outline-danger btn-sm" id="phDeleteHistoryBtn"
                title="Delete all price history for this security" style="display:none">
          <i class="bi bi-trash"></i> Clear History
        </button>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary btn-sm ms-auto" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
  let phChart    = null;
  let allPrices  = [];
  let allCompare = [];
  let activeRange = '1Y';
  let chartMode   = 'line';   // 'line' | 'candle'
  let curInvId    = null;
  let curInvName  = '';
  let curInvRawName   = '';
  let curInvRawSymbol = '';
  let compareId   = null;
  let compareName = '';
  let phModalShown  = false;  // true once Bootstrap's shown.bs.modal has fired
  let phDataReady   = false;  // true once price data is loaded and ready to render

  // ── Holding info panel ───────────────────────────────────────
  function renderHoldingInfo(inv) {
    const el = document.getElementById('phHoldingInfo');
    if (!el) return;
    const qty = inv.shares_owned || 0;
    const qtyFmt = qty > 0
      ? parseFloat(qty.toFixed(6)).toLocaleString('en-US', { maximumFractionDigits: 6 })
      : null;
    const dateStr = inv.last_purchase ? fmtDate(inv.last_purchase) : null;
    let html = '';
    if (qtyFmt) {
      html += `<div class="ph-hold-row"><span class="ph-hold-label">Shares owned</span><span class="ph-hold-val">${qtyFmt}</span></div>`;
    } else {
      html += `<div class="ph-hold-row"><span class="ph-hold-label">Shares owned</span><span class="ph-hold-val ph-hold-none">—</span></div>`;
    }
    if (dateStr) {
      html += `<div class="ph-hold-row"><span class="ph-hold-label">Last purchase</span><span class="ph-hold-val">${dateStr}</span></div>`;
    }
    const accounts = inv.accounts || [];
    if (accounts.length) {
      html += `<div class="ph-hold-accounts">`;
      accounts.forEach(a => {
        const q = parseFloat(a.quantity.toFixed(6)).toLocaleString('en-US', { maximumFractionDigits: 6 });
        html += `<div class="ph-hold-acct-row"><span class="ph-hold-acct-name">${esc(a.name)}</span><span class="ph-hold-acct-qty">${q}</span></div>`;
      });
      html += `</div>`;
    }
    el.innerHTML = html;
  }

  // ── Populate index select ─────────────────────────────────────
  function populateIndexSelect(excludeId) {
    const wrap = document.getElementById('phCompareWrap');
    const sel  = document.getElementById('phIndexSelect');
    const opts = PH_INDICES.filter(i => i.id !== excludeId);
    wrap.style.display = opts.length ? '' : 'none';
    sel.innerHTML = '<option value="">Compare index…</option>';
    opts.forEach(idx => {
      const o = document.createElement('option');
      o.value = idx.id;
      o.textContent = idx.symbol ? idx.name + ' (' + idx.symbol + ')' : idx.name;
      sel.appendChild(o);
    });
    sel.value = '';
  }

  // ── Open modal ────────────────────────────────────────────────
  document.addEventListener('click', e => {
    const btn = e.target.closest('.inv-price[data-id]');
    if (!btn) return;
    openPriceHistory(btn.dataset.id, btn.dataset.name, btn.dataset.symbol);
  });

  // Render only once both the modal is visible AND data is loaded
  function phMaybeRender() {
    if (phModalShown && phDataReady) renderPriceHistory();
  }

  document.getElementById('priceHistoryModal').addEventListener('shown.bs.modal', () => {
    phModalShown = true;
    phMaybeRender();
  });
  document.getElementById('priceHistoryModal').addEventListener('hide.bs.modal', () => {
    phModalShown = false;
    phDataReady  = false;
  });

  window.openPriceHistory = async function(id, name, symbol) {
    curInvId         = parseInt(id);
    curInvRawName    = name;
    curInvRawSymbol  = symbol || '';
    curInvName       = name + (symbol ? ' (' + symbol + ')' : '');
    compareId   = null;
    compareName = '';
    allCompare  = [];
    phModalShown = false;
    phDataReady  = false;

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('priceHistoryModal'));
    document.getElementById('phModalTitle').textContent = curInvName;
    document.getElementById('phModalSub').textContent   = '';
    document.getElementById('phLoading').style.display  = '';
    document.getElementById('phContent').style.display  = 'none';
    document.getElementById('phEmpty').style.display    = 'none';
    document.getElementById('phRefreshPriceBtn').style.display = curInvRawSymbol ? '' : 'none';
    document.getElementById('phRefreshStatus').style.display   = 'none';
    modal.show();

    await loadPhPrices();
  };

  async function loadPhPrices() {
    try {
      const res  = await fetch(BASE_PATH + '/portfolio/price_history?investment_id=' + encodeURIComponent(curInvId));
      const data = await res.json();
      document.getElementById('phLoading').style.display = 'none';
      if (!data.ok || !data.prices?.length) {
        document.getElementById('phContent').style.display = 'none';
        document.getElementById('phEmpty').style.display   = '';
        return;
      }
      allPrices = data.prices;
      activeRange = '1Y';
      document.getElementById('phContent').style.display = '';
      document.getElementById('phEmpty').style.display   = 'none';
      const delBtn = document.getElementById('phDeleteHistoryBtn');
      if (delBtn) delBtn.style.display = '';
      populateIndexSelect(curInvId);
      renderHoldingInfo(data.investment);
      setActiveRangeBtn('1Y');
      phDataReady = true;
      phMaybeRender();
    } catch (e) {
      document.getElementById('phLoading').innerHTML = '<span class="text-danger">Failed to load price history.</span>';
    }
  }

  // Called by manual price modal after edits to refresh chart in-place
  window.refreshCurrentPriceHistory = async function() {
    if (!curInvId) return;
    phDataReady = false;
    document.getElementById('phLoading').style.display  = '';
    document.getElementById('phContent').style.display  = 'none';
    document.getElementById('phEmpty').style.display    = 'none';
    await loadPhPrices();
  };

  // Edit Prices button in price history modal footer
  document.getElementById('phEditPricesBtn').addEventListener('click', () => {
    if (window.openManualPriceModal) {
      openManualPriceModal(curInvId, curInvRawName || curInvName);
    }
  });

  // ── Clear all price history ───────────────────────────────────
  const phDeleteHistBtn = document.getElementById('phDeleteHistoryBtn');
  if (phDeleteHistBtn) {
    phDeleteHistBtn.addEventListener('click', () => {
      if (!curInvId) return;
      const count = allPrices.length;
      appConfirm(
        'Clear Price History',
        `Delete all ${count} price record${count !== 1 ? 's' : ''} for "${esc(curInvRawName)}"? This cannot be undone.`,
        null,
        async () => {
          try {
            const body = new URLSearchParams({ csrf_token: CSRF_TOKEN, investment_id: curInvId });
            const res  = await fetch(BASE_PATH + '/portfolio/delete_price_history', { method: 'POST', body });
            const data = await res.json();
            if (data.ok) {
              allPrices = [];
              if (phChart) { phChart.destroy(); phChart = null; }
              document.getElementById('phContent').style.display = 'none';
              document.getElementById('phEmpty').style.display   = '';
              phDeleteHistBtn.style.display = 'none';
            } else {
              showToast(data.error || 'Delete failed.', 'error');
            }
          } catch (e) { console.error(e); showToast('Network error.', 'error'); }
        },
        'Delete All'
      );
    });
  }

  // ── Index comparison ──────────────────────────────────────────
  document.getElementById('phIndexSelect').addEventListener('change', async function () {
    const selId = parseInt(this.value) || null;
    if (!selId) {
      compareId = null; compareName = ''; allCompare = [];
      renderPriceHistory();
      return;
    }
    this.disabled = true;
    try {
      const res  = await fetch(BASE_PATH + '/portfolio/price_history?investment_id=' + encodeURIComponent(selId));
      const data = await res.json();
      if (data.ok && data.prices?.length) {
        compareId   = selId;
        compareName = data.investment.name + (data.investment.symbol ? ' (' + data.investment.symbol + ')' : '');
        allCompare  = data.prices;
      } else {
        this.value = '';
        compareId = null; compareName = ''; allCompare = [];
      }
    } catch (e) {
      this.value = '';
    } finally {
      this.disabled = false;
    }
    renderPriceHistory();
  });

  // ── Range buttons ─────────────────────────────────────────────
  document.getElementById('phRangeBtns').addEventListener('click', e => {
    const btn = e.target.closest('.ph-range-btn');
    if (!btn) return;
    activeRange = btn.dataset.range;
    setActiveRangeBtn(activeRange);
    renderPriceHistory();
  });

  // ── Chart type toggle ─────────────────────────────────────────
  document.getElementById('phChartTypeBtns').addEventListener('click', e => {
    const btn = e.target.closest('.ph-chart-type-btn');
    if (!btn) return;
    chartMode = btn.dataset.type;
    document.querySelectorAll('.ph-chart-type-btn').forEach(b =>
      b.classList.toggle('ph-chart-type-active', b.dataset.type === chartMode));
    renderPriceHistory();
  });

  function setActiveRangeBtn(range) {
    document.querySelectorAll('.ph-range-btn').forEach(b => {
      b.classList.toggle('ph-range-active', b.dataset.range === range);
    });
  }

  function filterByRange(prices, range) {
    if (range === 'ALL' || !prices.length) return prices;
    const months = { '1M': 1, '3M': 3, '6M': 6, '1Y': 12 }[range] || 12;
    const cutoff = new Date();
    cutoff.setMonth(cutoff.getMonth() - months);
    return prices.filter(p => p.date >= cutoff.toISOString().slice(0, 10));
  }

  // ── Main render ───────────────────────────────────────────────
  function renderPriceHistory() {
    const mainF = filterByRange(allPrices,  activeRange);
    const cmpF  = allCompare.length ? filterByRange(allCompare, activeRange) : [];
    renderStats(mainF, cmpF);
    renderChart(mainF, cmpF);
    renderTable(mainF);
  }

  // ── Stats bar ─────────────────────────────────────────────────
  function renderStats(prices, cmpPrices) {
    const el = document.getElementById('phStats');
    if (!prices.length) { el.innerHTML = ''; return; }

    if (!cmpPrices.length) {
      const first  = prices[0].close, last = prices[prices.length - 1].close;
      const high   = Math.max(...prices.map(p => p.high ?? p.close));
      const low    = Math.min(...prices.map(p => p.low  ?? p.close));
      const chg    = last - first;
      const pct    = first > 0 ? (chg / first) * 100 : 0;
      const cls    = chg >= 0 ? 'gain-pos' : 'gain-neg';
      const sign   = chg >= 0 ? '+' : '';
      const vols   = prices.map(p => p.volume).filter(v => v !== null);
      const avgVol = vols.length ? vols.reduce((a, b) => a + b, 0) / vols.length : null;
      el.innerHTML =
        stat('High',   fmtUSD(high)) +
        stat('Low',    fmtUSD(low))  +
        stat('Change', `<span class="${cls}">${sign}${fmtUSD(Math.abs(chg))} (${sign}${pct.toFixed(2)}%)</span>`) +
        (avgVol !== null ? stat('Avg Vol', fmtVol(avgVol)) : '');
    } else {
      const mainPct = periodReturn(prices);
      const cmpPct  = periodReturn(cmpPrices);
      const alpha   = mainPct - cmpPct;
      const s = n => `<span class="${n >= 0 ? 'gain-pos' : 'gain-neg'}">${n >= 0 ? '+' : ''}${n.toFixed(2)}%</span>`;
      el.innerHTML =
        `<div class="ph-stat"><span class="ph-stat-label ph-swatch" style="--sw:#1a5fb4">${esc(curInvName)}</span><span class="ph-stat-val">${s(mainPct)}</span></div>` +
        `<div class="ph-stat"><span class="ph-stat-label ph-swatch" style="--sw:#e66000">${esc(compareName)}</span><span class="ph-stat-val">${s(cmpPct)}</span></div>` +
        `<div class="ph-stat"><span class="ph-stat-label">Alpha</span><span class="ph-stat-val">${s(alpha)}</span></div>`;
    }
  }

  function periodReturn(prices) {
    if (prices.length < 2) return 0;
    const base = prices[0].close;
    return base > 0 ? (prices[prices.length - 1].close / base - 1) * 100 : 0;
  }

  function stat(label, val) {
    return `<div class="ph-stat"><span class="ph-stat-label">${label}</span><span class="ph-stat-val">${val}</span></div>`;
  }

  function fmtUSD(n) {
    return '$' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function fmtVol(v) {
    if (v === null || v === undefined) return '—';
    if (v >= 1e9) return (v / 1e9).toFixed(2) + 'B';
    if (v >= 1e6) return (v / 1e6).toFixed(2) + 'M';
    if (v >= 1e3) return (v / 1e3).toFixed(0) + 'K';
    return Math.round(v).toLocaleString();
  }

  function esc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  // ── Chart ─────────────────────────────────────────────────────
  function renderChart(prices, cmpPrices) {
    const ctx      = document.getElementById('phChart').getContext('2d');
    if (phChart) phChart.destroy();

    const comparing  = cmpPrices && cmpPrices.length > 0;
    const hasOHLCV   = !comparing && prices.some(p => p.open !== null);
    const typeBtns   = document.getElementById('phChartTypeBtns');
    typeBtns.style.display = hasOHLCV ? '' : 'none';
    if (!hasOHLCV) chartMode = 'line';

    if (comparing) {
      // ── Normalized % comparison ──────────────────────────────
      const { allDates, mainVals, cmpVals } = buildUnion(prices, cmpPrices);
      const n = allDates.length;
      phChart = new Chart(ctx, {
        type: 'line',
        data: { labels: allDates, datasets: [
          { label: curInvName, data: mainVals, borderColor: '#1a5fb4', borderWidth: 1.5,
            pointRadius: n > 90 ? 0 : 2, pointHoverRadius: 4, tension: 0.2, fill: false, spanGaps: true },
          { label: compareName, data: cmpVals, borderColor: '#e66000', borderWidth: 1.5,
            pointRadius: n > 90 ? 0 : 2, pointHoverRadius: 4, tension: 0.2, fill: false, spanGaps: true },
        ]},
        options: baseOptions(true,
          v    => (v >= 0 ? '+' : '') + v.toFixed(1) + '%',
          ctx2 => (ctx2.parsed.y >= 0 ? '+' : '') + ctx2.parsed.y.toFixed(2) + '%'),
      });
    } else if (chartMode === 'candle' && hasOHLCV) {
      // ── Candlestick ──────────────────────────────────────────
      const maxVol   = Math.max(...prices.map(p => p.volume ?? 0));
      const bullClr  = 'rgba(26,122,60,0.85)';
      const bearClr  = 'rgba(192,57,43,0.85)';
      const barColor = p => (p.close >= (p.open ?? p.close)) ? bullClr : bearClr;
      phChart = new Chart(ctx, {
        type: 'bar',
        data: { labels: prices.map(p => p.date), datasets: [
          { // Volume background
            type: 'bar', order: 3,
            data: prices.map(p => p.volume),
            backgroundColor: prices.map(p => (p.close >= (p.open ?? p.close))
              ? 'rgba(26,122,60,0.12)' : 'rgba(192,57,43,0.12)'),
            yAxisID: 'y2', barPercentage: 1.0, categoryPercentage: 1.0,
          },
          { // Wicks (low→high)
            type: 'bar', order: 2,
            data: prices.map(p => [p.low ?? p.close, p.high ?? p.close]),
            backgroundColor: prices.map(barColor),
            barThickness: 1, yAxisID: 'y',
          },
          { // Bodies (open→close)
            type: 'bar', order: 1,
            data: prices.map(p => [
              Math.min(p.open ?? p.close, p.close),
              Math.max(p.open ?? p.close, p.close),
            ]),
            backgroundColor: prices.map(barColor),
            barPercentage: 0.6, yAxisID: 'y',
          },
        ]},
        options: {
          animation: false, responsive: true,
          plugins: {
            legend: { display: false },
            tooltip: {
              filter: item => item.datasetIndex === 2,
              callbacks: {
                title: items => fmtDate(items[0].label),
                label: ctx2 => {
                  const p = prices[ctx2.dataIndex];
                  if (!p) return '';
                  return [
                    ` O: ${p.open !== null ? fmtUSD(p.open) : '—'}   H: ${p.high !== null ? fmtUSD(p.high) : '—'}`,
                    ` L: ${p.low  !== null ? fmtUSD(p.low)  : '—'}   C: ${fmtUSD(p.close)}`,
                    ` Vol: ${fmtVol(p.volume)}`,
                  ];
                },
              },
            },
          },
          scales: {
            x: { ticks: { maxTicksLimit: 8, font: { size: 10 }, color: '#888' }, grid: { color: '#eee' } },
            y: { ticks: { font: { size: 10 }, color: '#888', callback: v => '$' + v.toFixed(2) }, grid: { color: '#eee' } },
            y2: { display: false, min: 0, max: maxVol * 5, grid: { display: false } },
          },
        },
      });
    } else {
      // ── Line + volume overlay ────────────────────────────────
      const isDown = prices.length > 1 && prices[prices.length - 1].close < prices[0].close;
      const color  = isDown ? '#c0392b' : '#1a7a3c';
      const maxVol = Math.max(...prices.map(p => p.volume ?? 0));
      const hasVol = prices.some(p => p.volume !== null);
      const datasets = [
        { type: 'line', label: curInvName,
          data: prices.map(p => p.close), borderColor: color, borderWidth: 1.5,
          pointRadius: prices.length > 90 ? 0 : 2, pointHoverRadius: 4,
          tension: 0.2, fill: false, yAxisID: 'y', order: 1 },
      ];
      if (hasVol) {
        datasets.push({
          type: 'bar', label: 'Volume',
          data: prices.map(p => p.volume),
          backgroundColor: 'rgba(100,140,200,0.2)',
          borderWidth: 0, yAxisID: 'y2',
          barPercentage: 1.0, categoryPercentage: 1.0, order: 2,
        });
      }
      phChart = new Chart(ctx, {
        type: 'line',
        data: { labels: prices.map(p => p.date), datasets },
        options: {
          ...baseOptions(false, v => '$' + v.toFixed(2), ctx2 => '$' + ctx2.parsed.y.toFixed(2)),
          scales: {
            x: { ticks: { maxTicksLimit: 8, font: { size: 10 }, color: '#888' }, grid: { color: '#eee' } },
            y: { ticks: { font: { size: 10 }, color: '#888', callback: v => '$' + v.toFixed(2) }, grid: { color: '#eee' } },
            ...(hasVol ? { y2: { display: false, min: 0, max: maxVol * 5, grid: { display: false } } } : {}),
          },
        },
      });
    }
  }

  function baseOptions(showLegend, yFmt, tipFmt) {
    return {
      animation: false, responsive: true,
      plugins: {
        legend: { display: showLegend, position: 'top',
          labels: { font: { size: 11 }, usePointStyle: true, boxWidth: 10 } },
        tooltip: { callbacks: { label: tipFmt } },
      },
      scales: {
        x: { ticks: { maxTicksLimit: 8, font: { size: 10 }, color: '#888' }, grid: { color: '#eee' } },
        y: { ticks: { font: { size: 10 }, color: '#888', callback: yFmt }, grid: { color: '#eee' } },
      },
    };
  }

  function buildUnion(mainPrices, cmpPrices) {
    const dateSet  = new Set([...mainPrices.map(p => p.date), ...cmpPrices.map(p => p.date)]);
    const allDates = [...dateSet].sort();
    const mainMap  = Object.fromEntries(mainPrices.map(p => [p.date, p.close]));
    const cmpMap   = Object.fromEntries(cmpPrices.map(p => [p.date, p.close]));
    const mainBase = mainPrices[0]?.close || 1;
    const cmpBase  = cmpPrices[0]?.close  || 1;
    const mainVals = allDates.map(d => mainMap[d] != null ? (mainMap[d] / mainBase - 1) * 100 : null);
    const cmpVals  = allDates.map(d => cmpMap[d]  != null ? (cmpMap[d]  / cmpBase  - 1) * 100 : null);
    return { allDates, mainVals, cmpVals };
  }

  // ── Table ─────────────────────────────────────────────────────
  function renderTable(prices) {
    const tbody = document.getElementById('phTableBody');
    tbody.innerHTML = '';
    const reversed = [...prices].reverse();
    reversed.forEach((p, i) => {
      const prev = reversed[i + 1];
      const pct  = (prev && prev.close > 0) ? (p.close / prev.close - 1) * 100 : null;
      const cls  = pct === null ? '' : pct >= 0 ? 'gain-pos' : 'gain-neg';
      const sign = pct !== null && pct >= 0 ? '+' : '';
      const n    = v => v !== null ? fmtUSD(v) : '<span class="text-muted">—</span>';
      const tr   = document.createElement('tr');
      tr.innerHTML =
        `<td>${fmtDate(p.date)}</td>` +
        `<td class="text-end">${n(p.open)}</td>` +
        `<td class="text-end">${n(p.high)}</td>` +
        `<td class="text-end">${n(p.low)}</td>` +
        `<td class="text-end">${fmtUSD(p.close)}</td>` +
        `<td class="text-end text-muted small">${fmtVol(p.volume)}</td>` +
        `<td class="text-end ${cls}">${pct !== null ? sign + pct.toFixed(2) + '%' : '—'}</td>` +
        `<td class="text-muted small">${p.source}</td>`;
      tbody.appendChild(tr);
    });
  }

  function fmtDate(iso) {
    const [y, m, d] = iso.split('-');
    return m + '/' + d + '/' + y;
  }

  // ── Refresh Price (fetch latest from online provider) ─────────
  document.getElementById('phRefreshPriceBtn').addEventListener('click', async () => {
    const btn      = document.getElementById('phRefreshPriceBtn');
    const status   = document.getElementById('phRefreshStatus');
    const origHtml = btn.innerHTML;
    btn.disabled   = true;
    btn.innerHTML  = '<span class="spinner-border spinner-border-sm"></span>';
    status.style.display = 'none';
    try {
      const body = new URLSearchParams({
        csrf_token:    CSRF_TOKEN,
        mode:          'latest',
        investment_id: curInvId,
      });
      const res  = await fetch(BASE_PATH + '/portfolio/fetch_prices', { method: 'POST', body });
      const data = await res.json();
      if (data.ok) {
        const result = data.results?.[0];
        if (result?.status === 'ok') {
          status.className    = 'small ms-1 text-success';
          status.textContent  = '✓ Price updated (' + (result.source || 'online') + ')';
          status.style.display = '';
          setTimeout(() => { status.style.display = 'none'; }, 5000);
          window.refreshCurrentPriceHistory();
        } else {
          status.className    = 'small ms-1 text-danger';
          status.textContent  = result?.message || data.errors?.[0] || 'No price returned.';
          status.style.display = '';
        }
      } else {
        status.className    = 'small ms-1 text-danger';
        status.textContent  = data.error || 'Fetch failed.';
        status.style.display = '';
      }
    } catch (e) {
      console.error(e);
      status.className    = 'small ms-1 text-danger';
      status.textContent  = 'Network error.';
      status.style.display = '';
    } finally {
      btn.disabled  = false;
      btn.innerHTML = origHtml;
    }
  });

  // ── CSV Download ──────────────────────────────────────────────
  document.getElementById('phDownloadCsvBtn').addEventListener('click', () => {
    if (!allPrices.length) return;
    const lines = ['date,open,high,low,close,volume,source'];
    [...allPrices].sort((a, b) => a.date.localeCompare(b.date)).forEach(p => {
      lines.push([p.date, p.open ?? '', p.high ?? '', p.low ?? '',
                  p.close, p.volume ?? '', p.source].join(','));
    });
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = (curInvRawName || 'investment').replace(/[^a-z0-9]/gi, '_') + '_prices.csv';
    a.click();
    URL.revokeObjectURL(url);
  });

  // ── CSV Upload ────────────────────────────────────────────────
  document.getElementById('phUploadCsvBtn').addEventListener('click', () => {
    document.getElementById('phCsvFileInput').value = '';
    document.getElementById('phCsvFileInput').click();
  });

  document.getElementById('phCsvFileInput').addEventListener('change', async function () {
    const file   = this.files[0];
    if (!file) return;
    const status = document.getElementById('phCsvStatus');
    status.className    = 'small ms-1 text-muted';
    status.textContent  = 'Parsing…';
    status.style.display = '';

    try {
      const rows = parsePriceCsv(await file.text());
      if (!rows.length) throw new Error('No valid rows found in file.');

      status.textContent = `Uploading ${rows.length} row(s)…`;
      const body = new URLSearchParams({
        csrf_token:    CSRF_TOKEN,
        action:        'upload_csv',
        investment_id: curInvId,
        rows:          JSON.stringify(rows),
      });
      const res  = await fetch(BASE_PATH + '/portfolio/save_manual_price', { method: 'POST', body });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Upload failed.');

      status.className = 'small ms-1 text-success';
      status.textContent = `✓ ${data.saved} row(s) saved` +
        (data.skipped ? `, ${data.skipped} skipped` : '') + '.';
      setTimeout(() => { status.style.display = 'none'; }, 5000);
      window.refreshCurrentPriceHistory();
    } catch (e) {
      status.className = 'small ms-1 text-danger';
      status.textContent = e.message;
    }
  });

  function parsePriceCsv(text) {
    // Strip UTF-8 BOM if present
    if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
    const lines = text.trim().split(/\r?\n/);
    if (lines.length < 2) throw new Error('CSV file is empty.');

    // Properly parse a CSV row, handling quoted fields containing commas
    function parseCsvRow(line) {
      const fields = [];
      let field = '', inQuotes = false;
      for (let i = 0; i < line.length; i++) {
        const ch = line[i];
        if (inQuotes) {
          if (ch === '"' && line[i + 1] === '"') { field += '"'; i++; }
          else if (ch === '"') { inQuotes = false; }
          else { field += ch; }
        } else {
          if (ch === '"') { inQuotes = true; }
          else if (ch === ',') { fields.push(field); field = ''; }
          else { field += ch; }
        }
      }
      fields.push(field);
      return fields;
    }

    const headers = parseCsvRow(lines[0]).map(h => h.trim().toLowerCase());
    const col = h => { const i = headers.indexOf(h); return i === -1 ? null : i; };
    const dateIdx  = col('date');
    const closeIdx = col('close');
    if (dateIdx === null || closeIdx === null) {
      throw new Error('CSV must have "date" and "close" column headers.');
    }
    const openIdx = col('open'), highIdx = col('high'), lowIdx = col('low');
    const volIdx  = col('volume'), srcIdx = col('source');

    function normalizeDate(s) {
      s = (s || '').trim();
      if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
      const m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
      if (m) return m[3] + '-' + m[1].padStart(2, '0') + '-' + m[2].padStart(2, '0');
      return s;
    }
    function normalizePrice(s) { return (s || '').trim().replace(/[$,]/g, ''); }

    const rows = [];
    for (let i = 1; i < lines.length; i++) {
      const c     = parseCsvRow(lines[i]);
      const date  = normalizeDate(c[dateIdx]  ?? '');
      const close = normalizePrice(c[closeIdx] ?? '');
      if (!date || !close) continue;
      rows.push({
        date,  close,
        open:   openIdx !== null ? normalizePrice(c[openIdx]  ?? '') : '',
        high:   highIdx !== null ? normalizePrice(c[highIdx]  ?? '') : '',
        low:    lowIdx  !== null ? normalizePrice(c[lowIdx]   ?? '') : '',
        volume: volIdx  !== null ? (c[volIdx]?.trim()         ?? '') : '',
        source: srcIdx  !== null ? (c[srcIdx]?.trim()         ?? '') : '',
      });
    }
    return rows;
  }
})();
</script>

<!-- Manual Price History Modal -->
<div class="modal fade" id="manualPriceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width:520px">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--ms-blue);color:#fff;padding:.75rem 1rem">
        <div>
          <h5 class="modal-title mb-0"><i class="bi bi-pencil-square"></i> Price History</h5>
          <div class="small opacity-75 mt-1" id="manualPriceName"></div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <!-- Add / Edit form -->
      <div class="px-3 pt-3 pb-2 border-bottom bg-light">
        <div class="row g-2 align-items-end">
          <div class="col-auto">
            <label class="form-label small mb-1">Date</label>
            <input type="date" class="form-control form-control-sm" id="manualPriceDate" style="width:145px">
          </div>
          <div class="col-auto">
            <label class="form-label small mb-1">Close Price</label>
            <div class="input-group input-group-sm" style="width:130px">
              <span class="input-group-text">$</span>
              <input type="number" class="form-control" id="manualPriceVal"
                     step="0.000001" min="0.000001" placeholder="0.00">
            </div>
          </div>
          <div class="col-auto d-flex gap-1">
            <button type="button" class="btn btn-primary btn-sm" id="manualPriceSaveBtn">
              <i class="bi bi-plus-circle"></i> Add
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="manualPriceCancelBtn">
              Cancel
            </button>
          </div>
        </div>
        <div id="manualPriceError" class="text-danger small mt-2" style="display:none"></div>
      </div>

      <!-- History table -->
      <div class="modal-body p-0" style="min-height:120px;max-height:380px;overflow-y:auto">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light" style="position:sticky;top:0;z-index:1">
            <tr>
              <th class="ps-3">Date</th>
              <th class="text-end">Price</th>
              <th>Source</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="manualPriceBody">
            <tr><td colspan="4" class="text-center text-muted py-3 small">
              <span class="spinner-border spinner-border-sm"></span> Loading…
            </td></tr>
          </tbody>
        </table>
      </div>

      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  let pendingInvId = null;
  let editingDate  = null;   // null = add mode; 'YYYY-MM-DD' = edit mode
  let dirty        = false;  // reload portfolio page on close if true

  const modal       = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('manualPriceModal'));
  const $date       = () => document.getElementById('manualPriceDate');
  const $price      = () => document.getElementById('manualPriceVal');
  const $err        = () => document.getElementById('manualPriceError');
  const $saveBtn    = () => document.getElementById('manualPriceSaveBtn');
  const $cancelBtn  = () => document.getElementById('manualPriceCancelBtn');

  // On close: refresh the price history chart if it's open, else reload the page
  document.getElementById('manualPriceModal').addEventListener('hidden.bs.modal', () => {
    if (!dirty) return;
    const phModal = document.getElementById('priceHistoryModal');
    if (phModal && phModal.classList.contains('show') && window.refreshCurrentPriceHistory) {
      window.refreshCurrentPriceHistory();
    } else {
      location.reload();
    }
  });

  // Expose so the price history modal can open it
  window.openManualPriceModal = function(id, name) {
    pendingInvId = id;
    dirty = false;
    document.getElementById('manualPriceName').textContent = name;
    resetForm();
    modal().show();
    loadHistory();
  };

  // Open from pencil buttons in the portfolio table
  document.addEventListener('click', e => {
    const btn = e.target.closest('.inv-manual-price');
    if (!btn) return;
    openManualPriceModal(btn.dataset.id, btn.dataset.name);
  });

  function resetForm() {
    editingDate = null;
    $date().value         = new Date().toISOString().slice(0, 10);
    $date().readOnly      = false;
    $price().value        = '';
    $err().style.display  = 'none';
    $saveBtn().innerHTML  = '<i class="bi bi-plus-circle"></i> Add';
    $cancelBtn().classList.add('d-none');
  }

  function enterEditMode(date, price) {
    editingDate = date;
    $date().value         = date;
    $date().readOnly      = true;
    $price().value        = price;
    $err().style.display  = 'none';
    $saveBtn().innerHTML  = '<i class="bi bi-check-circle"></i> Update';
    $cancelBtn().classList.remove('d-none');
    $price().focus();
    $price().select();
  }

  $cancelBtn().addEventListener('click', resetForm);

  // ── Load & render history ────────────────────────────────────
  async function loadHistory() {
    const tbody = document.getElementById('manualPriceBody');
    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small"><span class="spinner-border spinner-border-sm"></span> Loading…</td></tr>';
    try {
      const res  = await fetch(BASE_PATH + '/portfolio/price_history?investment_id=' + encodeURIComponent(pendingInvId));
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Load failed');
      renderHistory(data.prices);
    } catch (e) {
      tbody.innerHTML = `<tr><td colspan="4" class="text-danger small px-3 py-2">${mpEsc(e.message)}</td></tr>`;
    }
  }

  function renderHistory(prices) {
    const tbody = document.getElementById('manualPriceBody');
    if (!prices.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3 small">No price entries yet.</td></tr>';
      return;
    }
    const rows = [...prices].sort((a, b) => b.date.localeCompare(a.date));
    tbody.innerHTML = rows.map(p => {
      const srcBadge = p.source === 'manual'
        ? '<span class="badge bg-secondary" style="font-size:.62rem;font-weight:500">manual</span>'
        : `<span class="text-muted small">${mpEsc(p.source)}</span>`;
      const editing = editingDate === p.date ? ' class="table-active"' : '';
      return `<tr${editing}>
        <td class="ps-3 text-nowrap">${mpFmtDate(p.date)}</td>
        <td class="text-end text-nowrap">${mpFmtUSD(p.close)}</td>
        <td>${srcBadge}</td>
        <td class="text-end pe-2 text-nowrap">
          <button class="btn btn-sm btn-link p-0 me-2 mp-edit-btn"
                  data-date="${mpEsc(p.date)}" data-price="${p.close}" title="Edit">
            <i class="bi bi-pencil"></i>
          </button>
          <button class="btn btn-sm btn-link p-0 text-danger mp-del-btn"
                  data-date="${mpEsc(p.date)}" title="Delete">
            <i class="bi bi-trash"></i>
          </button>
        </td>
      </tr>`;
    }).join('');
  }

  // Edit / Delete row actions
  document.getElementById('manualPriceBody').addEventListener('click', e => {
    const editBtn = e.target.closest('.mp-edit-btn');
    if (editBtn) { enterEditMode(editBtn.dataset.date, editBtn.dataset.price); return; }
    const delBtn  = e.target.closest('.mp-del-btn');
    if (delBtn)  { deleteEntry(delBtn.dataset.date); }
  });

  function deleteEntry(date) {
    appConfirm(
      'Delete Price Entry',
      `Delete price entry for ${mpFmtDate(date)}?`,
      null,
      async () => {
        try {
          const body = new URLSearchParams({
            csrf_token: CSRF_TOKEN, action: 'delete',
            investment_id: pendingInvId, price_date: date,
          });
          const res  = await fetch(BASE_PATH + '/portfolio/save_manual_price', { method: 'POST', body });
          const data = await res.json();
          if (data.ok) {
            dirty = true;
            if (editingDate === date) resetForm();
            loadHistory();
          } else {
            showToast(data.error || 'Delete failed.', 'error');
          }
        } catch (e) { console.error(e); showToast('Network error.', 'error'); }
      },
      'Delete'
    );
  }

  // ── Save / Update ────────────────────────────────────────────
  $saveBtn().addEventListener('click', async () => {
    const date  = $date().value.trim();
    const price = $price().value.trim();
    if (!date || !price || parseFloat(price) <= 0) {
      $err().textContent    = 'Please enter a valid date and price.';
      $err().style.display  = '';
      return;
    }
    $err().style.display = 'none';

    const btn      = $saveBtn();
    const origHtml = btn.innerHTML;
    btn.disabled   = true;
    btn.innerHTML  = '<span class="spinner-border spinner-border-sm"></span>';

    try {
      const body = new URLSearchParams({
        csrf_token: CSRF_TOKEN, action: 'save',
        investment_id: pendingInvId, price_date: date, price,
      });
      const res  = await fetch(BASE_PATH + '/portfolio/save_manual_price', { method: 'POST', body });
      const data = await res.json();
      if (data.ok) {
        dirty = true;
        resetForm();
        loadHistory();
      } else {
        $err().textContent   = data.error || 'Save failed.';
        $err().style.display = '';
      }
    } catch (e) {
      console.error(e);
      $err().textContent   = 'Network error.';
      $err().style.display = '';
    } finally {
      btn.disabled  = false;
      btn.innerHTML = origHtml;
    }
  });

  function mpFmtDate(iso) {
    const [y, m, d] = iso.split('-');
    return `${m}/${d}/${y}`;
  }
  function mpFmtUSD(n) {
    const v = parseFloat(n);
    return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 6 });
  }
  function mpEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
})();
</script>
