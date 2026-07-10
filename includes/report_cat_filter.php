<?php
// Income/expense category filter helpers, shared by reports/income_expense.php
// and reports/income_expense_detail.php (transaction drill-down).
//
// Categories are filtered at top-level granularity: selecting/deselecting a
// top-level category implicitly includes/excludes all of its descendants,
// regardless of nesting depth.

function loadCategoryFilterData(PDO $db, string $type): array {
    $rows = $db->prepare("SELECT id, name, parent_id FROM categories WHERE type = ? AND is_active = 1");
    $rows->execute([$type]);
    $all = $rows->fetchAll();

    $byId = [];
    foreach ($all as $r) $byId[(int)$r['id']] = $r;

    // Map every category id (top or sub, any depth) to its top-level ancestor id
    $topCats          = [];
    $descendantsByTop = [];
    foreach ($byId as $id => $row) {
        $cur = $id;
        while ($byId[$cur]['parent_id'] !== null) {
            $cur = (int)$byId[$cur]['parent_id'];
        }
        $descendantsByTop[$cur][] = $id;
        if ($row['parent_id'] === null) {
            $topCats[] = ['id' => $id, 'name' => $row['name']];
        }
    }
    usort($topCats, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return [$topCats, $descendantsByTop]; // descendantsByTop[topId] includes topId itself
}

function parseCatTopSelection(string $raw, array $allTopIds): array {
    $raw = trim($raw);
    if ($raw === '' || $raw === 'all') return [$allTopIds, false];
    $parsed = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $raw)),
        fn($id) => in_array($id, $allTopIds, true)
    )));
    if (empty($parsed) || count($parsed) >= count($allTopIds)) return [$allTopIds, false];
    return [$parsed, true];
}

function catTopBtnLabel(bool $filtering, array $selectedIds, array $topCats, string $allLabel): string {
    if (!$filtering) return $allLabel;
    if (count($selectedIds) === 1) {
        foreach ($topCats as $c) if ((int)$c['id'] === $selectedIds[0]) return $c['name'];
        return '1 Category';
    }
    return count($selectedIds) . ' Categories';
}

// Expands a list of selected top-level category ids into the full set of
// leaf category ids (top-level id + all descendants) for use in a
// `category_id IN (...)` SQL clause.
function expandCatTopSelection(array $selectedTopIds, array $descendantsByTop): array {
    $leafIds = [];
    foreach ($selectedTopIds as $tid) $leafIds = array_merge($leafIds, $descendantsByTop[$tid] ?? [$tid]);
    return $leafIds;
}

function renderTopCatFilterDropdown(
    string $prefix, string $fieldName, string $label,
    array $topCats, array $selectedIds, bool $filtering, string $btnLabel
): void {
?>
  <div class="filter-group">
    <label><?= h($label) ?></label>
    <input type="hidden" name="<?= h($fieldName) ?>" id="<?= $prefix ?>Hidden"
           value="<?= h($filtering ? implode(',', $selectedIds) : '') ?>">
    <div class="dropdown" data-bs-auto-close="outside">
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
              data-bs-toggle="dropdown">
        <span id="<?= $prefix ?>Label"><?= h($btnLabel) ?></span>
      </button>
      <ul class="dropdown-menu acct-filter-menu p-2" style="max-height:280px;overflow-y:auto;min-width:200px">
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" id="<?= $prefix ?>All" <?= !$filtering ? 'checked' : '' ?>>
            <strong>All Categories</strong>
          </label>
        </li>
        <li><hr class="dropdown-divider my-1"></li>
        <?php foreach ($topCats as $c): ?>
        <li>
          <label class="dropdown-item d-flex gap-2 align-items-center">
            <input type="checkbox" class="<?= $prefix ?>-chk" value="<?= (int)$c['id'] ?>"
                   data-name="<?= h($c['name']) ?>"
                   <?= in_array((int)$c['id'], $selectedIds, true) ? 'checked' : '' ?>>
            <span><?= h($c['name']) ?></span>
          </label>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <script>
  (function(){
    const allChk  = document.getElementById('<?= $prefix ?>All');
    const chkList = Array.from(document.querySelectorAll('.<?= $prefix ?>-chk'));
    const hidden  = document.getElementById('<?= $prefix ?>Hidden');
    const label   = document.getElementById('<?= $prefix ?>Label');
    function update() {
      const checked = chkList.filter(c => c.checked);
      const isAll   = checked.length === 0 || checked.length === chkList.length;
      hidden.value  = isAll ? '' : checked.map(c => c.value).join(',');
      label.textContent = isAll ? 'All Categories'
        : checked.length === 1 ? checked[0].dataset.name
        : checked.length + ' Categories';
      allChk.checked       = isAll || checked.length === chkList.length;
      allChk.indeterminate = !isAll && checked.length > 0;
    }
    allChk.addEventListener('change', function() {
      chkList.forEach(c => c.checked = this.checked);
      this.indeterminate = false;
      update();
    });
    chkList.forEach(c => c.addEventListener('change', update));
  })();
  </script>
<?php
}
