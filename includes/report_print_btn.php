<div class="btn-group d-print-none" role="group">
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
    <i class="bi bi-printer"></i> Print
  </button>
  <button type="button" id="btnPrintGraphs"
          class="btn btn-sm btn-outline-secondary active"
          title="Graphs included in print — click to exclude">
    <i class="bi bi-bar-chart-line"></i>
  </button>
</div>
<script>
(function () {
  const KEY = 'mm-print-no-graphs';
  const btn = document.getElementById('btnPrintGraphs');

  // ── Toggle state ────────────────────────────────────────────
  let noGraphs = localStorage.getItem(KEY) === '1';
  apply();

  btn.addEventListener('click', function () {
    noGraphs = !noGraphs;
    localStorage.setItem(KEY, noGraphs ? '1' : '0');
    apply();
  });

  function apply() {
    document.body.classList.toggle('print-no-graphs', noGraphs);
    btn.classList.toggle('active', !noGraphs);
    btn.title = noGraphs
      ? 'Graphs excluded from print — click to include'
      : 'Graphs included in print — click to exclude';
  }

  // ── Inject / remove print legends ──────────────────────────
  function buildLegends() {
    if (noGraphs) return;
    if (typeof Chart === 'undefined') return;

    document.querySelectorAll(
      '.report-chart-wrap canvas, .alloc-chart-wrap canvas'
    ).forEach(function (canvas) {
      const chart = Chart.getChart(canvas);
      if (!chart) return;
      const datasets = chart.data.datasets;
      if (!datasets || datasets.length === 0) return;

      const legend = document.createElement('div');
      legend.className = 'rpt-print-legend';

      datasets.forEach(function (ds) {
        if (!ds.label) return;
        const raw   = Array.isArray(ds.borderColor) ? ds.borderColor[0] : ds.borderColor;
        const color = raw || (Array.isArray(ds.backgroundColor) ? ds.backgroundColor[0] : ds.backgroundColor) || '#888';
        const dashed = ds.borderDash && ds.borderDash.length > 0;

        const item = document.createElement('span');
        item.className = 'rpt-print-legend-item';

        const swatch = document.createElement('span');
        swatch.className = 'rpt-print-legend-swatch';
        swatch.style.background = dashed ? 'transparent' : color;
        swatch.style.borderTop   = dashed ? '2px dashed ' + color : 'none';
        swatch.style.display     = 'inline-block';
        swatch.style.width       = '18px';
        swatch.style.height      = dashed ? '0' : '3px';
        swatch.style.borderRadius = '1px';
        swatch.style.verticalAlign = 'middle';
        swatch.style.marginRight  = '4px';

        const label = document.createElement('span');
        label.textContent = ds.label;

        item.appendChild(swatch);
        item.appendChild(label);
        legend.appendChild(item);
      });

      const wrap = canvas.closest('.report-chart-wrap, .alloc-chart-wrap');
      if (wrap) wrap.appendChild(legend);
    });
  }

  function cleanLegends() {
    document.querySelectorAll('.rpt-print-legend').forEach(function (el) { el.remove(); });
  }

  window.addEventListener('beforeprint', buildLegends);
  window.addEventListener('afterprint',  cleanLegends);
})();
</script>
