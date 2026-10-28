<?php
/**
 * "Columns" dropdown that shows/hides optional table columns and remembers the
 * choice per user (api/save_pref.php).
 *
 * Expects:
 *   $colChooserPref     string  user_prefs key (listed in VIEW_PREF_PATTERNS)
 *   $colChooserCols     array   key => label, in display order
 *   $colChooserVisible  array   visible keys (from viewColumns())
 *
 * Cells of an optional column carry class "col-<key>". Hidden columns are
 * written into a <style> block here, so the page never flashes them.
 */
$_ccHidden = array_diff(array_keys($colChooserCols), $colChooserVisible);
?>
<style id="colChooserStyle"><?= implode('', array_map(fn($k) => '.col-' . $k . '{display:none}', $_ccHidden)) ?></style>
<div class="dropdown d-print-none">
  <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"
          data-bs-auto-close="outside" aria-expanded="false" title="Show or hide columns">
    <i class="bi bi-layout-three-columns"></i> Columns
  </button>
  <div class="dropdown-menu dropdown-menu-end p-2 col-chooser-menu">
    <?php foreach ($colChooserCols as $_ccKey => $_ccLabel): ?>
    <label class="dropdown-item d-flex gap-2 align-items-center small">
      <input type="checkbox" class="col-chooser-chk" value="<?= h($_ccKey) ?>"<?= in_array($_ccKey, $colChooserVisible, true) ? ' checked' : '' ?>>
      <?= h($_ccLabel) ?>
    </label>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function () {
  const PREF  = <?= json_encode($colChooserPref) ?>;
  const style = document.getElementById('colChooserStyle');
  const boxes = Array.from(document.querySelectorAll('.col-chooser-chk'));
  boxes.forEach(cb => cb.addEventListener('change', () => {
    style.textContent = boxes.filter(b => !b.checked).map(b => '.col-' + b.value + '{display:none}').join('');
    savePref(PREF, boxes.filter(b => b.checked).map(b => b.value).join(','));
  }));
})();
</script>
<?php unset($_ccHidden, $_ccKey, $_ccLabel); ?>
