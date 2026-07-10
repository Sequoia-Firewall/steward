<?php
// Account filter dropdown UI for report filter forms.
// Requires: $allAccounts, $selectedAcctIds, $acctParam, $filteringAccts

if (!$filteringAccts) {
    $acctBtnLabel = 'All Accounts';
} elseif (count($selectedAcctIds) === 1) {
    $match = array_filter($allAccounts, fn($a) => (int)$a['id'] === $selectedAcctIds[0]);
    $match = reset($match);
    $acctBtnLabel = $match ? $match['name'] : '1 Account';
} else {
    $acctBtnLabel = count($selectedAcctIds) . ' Accounts';
}
?>
<div class="filter-group">
  <label>Accounts</label>
  <input type="hidden" name="accts" id="acctHidden" value="<?= h($acctParam) ?>">
  <div class="dropdown" data-bs-auto-close="outside">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle acct-filter-btn"
            data-bs-toggle="dropdown">
      <span id="acctFilterLabel"><?= h($acctBtnLabel) ?></span>
    </button>
    <ul class="dropdown-menu acct-filter-menu p-2">
      <li>
        <label class="dropdown-item d-flex gap-2 align-items-center">
          <input type="checkbox" id="acctAll" <?= !$filteringAccts ? 'checked' : '' ?>>
          <strong>All Accounts</strong>
        </label>
      </li>
      <li><hr class="dropdown-divider my-1"></li>
      <?php foreach ($allAccounts as $a): ?>
      <li>
        <label class="dropdown-item d-flex gap-2 align-items-center">
          <input type="checkbox" class="acct-chk" value="<?= (int)$a['id'] ?>"
                 data-name="<?= h($a['name']) ?>"
                 <?= in_array((int)$a['id'], $selectedAcctIds, true) ? 'checked' : '' ?>>
          <span><?= h($a['name']) ?></span>
          <span class="ms-auto text-muted small text-nowrap"><?= h($a['type']) ?></span>
        </label>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<script>
(function(){
  const allChk  = document.getElementById('acctAll');
  const chkList = Array.from(document.querySelectorAll('.acct-chk'));
  const hidden  = document.getElementById('acctHidden');
  const label   = document.getElementById('acctFilterLabel');

  function update() {
    const checked = chkList.filter(c => c.checked);
    const isAll   = checked.length === 0 || checked.length === chkList.length;
    hidden.value  = isAll ? '' : checked.map(c => c.value).join(',');
    label.textContent = isAll ? 'All Accounts'
      : checked.length === 1 ? checked[0].dataset.name
      : checked.length + ' Accounts';
    if (checked.length === chkList.length) {
      allChk.checked = true; allChk.indeterminate = false;
    } else if (checked.length === 0) {
      allChk.checked = false; allChk.indeterminate = false;
    } else {
      allChk.checked = false; allChk.indeterminate = true;
    }
  }

  allChk.addEventListener('change', function() {
    chkList.forEach(c => c.checked = this.checked);
    this.indeterminate = false;
    update();
  });

  chkList.forEach(c => c.addEventListener('change', update));
})();
</script>
