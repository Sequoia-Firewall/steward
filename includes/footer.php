<?php if (isLoggedIn()): ?>
  </main><!-- /.main-content -->
</div><!-- /.page-wrapper -->
<?php else: ?>
</main>
<?php endif; ?>

<!-- Global confirm modal (used by appConfirm() in money.js) -->
<div class="modal fade" id="appConfirmModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content confirm-modal">
      <div class="modal-header confirm-modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill"></i> <span id="appConfirmTitle"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body confirm-modal-body">
        <p class="mb-2" id="appConfirmMsg"></p>
        <div id="appConfirmWarn" class="alert alert-warning py-2 small mb-0" style="display:none">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span id="appConfirmWarnText"></span>
        </div>
      </div>
      <div class="modal-footer confirm-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="appConfirmBtn">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_PATH ?>/assets/js/money.js?v=<?= @filemtime(__DIR__ . '/../assets/js/money.js') ?: time() ?>"></script>
<?php if (function_exists('currentDateRangePreset')): ?>
<script>
// Relative date ranges (includes/date_presets.php). When the page was loaded with
// dr=<token>, keep that token — instead of the literal dates it resolved to — on
// filter submits and same-page links, as long as the dates weren't changed.
// Also drops as_of when it is today, so "as of today" links stay current.
(function () {
  const P     = <?= json_encode(currentDateRangePreset()) ?>;
  const TODAY = <?= json_encode(date('Y-m-d')) ?>;

  function keepsPreset(get) {
    const range = get('range');
    return P && get('start') === P.start && get('end') === P.end && !get('dr')
        && (range === null || range === 'custom');
  }

  document.querySelectorAll('form').forEach(function (f) {
    if ((f.getAttribute('method') || 'get').toLowerCase() !== 'get') return;
    f.addEventListener('submit', function () {
      const renamed = [];
      const field = n => f.querySelector('[name="' + n + '"]:not(:disabled)');
      const val   = n => { const el = field(n); return el ? el.value : null; };
      const asOf = field('as_of');
      if (asOf && asOf.value === TODAY) { asOf.removeAttribute('name'); renamed.push([asOf, 'as_of']); }
      if (!f.querySelector('[name="dr"]') && field('start') && field('end') && keepsPreset(val)) {
        ['start', 'end'].forEach(n => { const el = field(n); el.removeAttribute('name'); renamed.push([el, n]); });
        const dr = document.createElement('input');
        dr.type = 'hidden'; dr.name = 'dr'; dr.value = P.token; dr.dataset.presetAdded = '1';
        f.appendChild(dr);
      }
      // Restore the form if the user comes back to this page via the back button
      if (renamed.length) {
        window.addEventListener('pageshow', function restore() {
          renamed.forEach(([el, n]) => el.setAttribute('name', n));
          f.querySelectorAll('[data-preset-added]').forEach(el => el.remove());
          window.removeEventListener('pageshow', restore);
        });
      }
    });
  });

  if (!P) return;
  function rewrite(a) {
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    let u;
    try { u = new URL(href, location.href); } catch (e) { return; }
    if (u.origin !== location.origin || u.pathname !== location.pathname) return;
    if (!keepsPreset(n => u.searchParams.get(n))) return;
    u.searchParams.delete('start');
    u.searchParams.delete('end');
    u.searchParams.set('dr', P.token);
    a.setAttribute('href', u.pathname + u.search + u.hash);
  }
  document.querySelectorAll('a[href]').forEach(rewrite);
  // Links added after load (tables re-rendered by JS, etc.)
  document.addEventListener('click', function (e) {
    const a = e.target.closest && e.target.closest('a[href]');
    if (a) rewrite(a);
  }, true);
})();
</script>
<?php endif; ?>
</body>
</html>
