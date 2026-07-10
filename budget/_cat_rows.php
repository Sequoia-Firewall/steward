<?php
// Renders category rows for the budget wizard.
// Called by create.php with $incomeTree or $expenseTree.

function renderCatTree(array $tree, array $selCats, array $monthNames, array $histSpend = [], array $histLabels = [], array $histRanges = []): void {
    foreach ($tree as $node) {
        echo '<div class="wiz-cat-group">';
        renderCatRow($node['cat'], $selCats, $monthNames, false, $histSpend, $histLabels, $histRanges, $node['children']);
        foreach ($node['children'] as $child) {
            renderCatRow($child, $selCats, $monthNames, true, $histSpend, $histLabels, $histRanges, []);
        }
        echo '</div>';
    }
}

// Returns a linked amount cell, or a dash if zero.
function histAmt(float $amt, int $cid, string $start, string $end): string {
    if (abs($amt) < 0.005) return '<span class="wiz-hist-zero">—</span>';
    $url = h(BASE_PATH . '/transactions/search?cat=' . $cid . '&start=' . $start . '&end=' . $end);
    return '<a href="' . $url . '" target="_blank" rel="noopener" class="wiz-hist-link">'
         . formatMoney(abs($amt)) . '</a>';
}

function renderCatRow(array $cat, array $selCats, array $monthNames, bool $isChild,
                      array $histSpend = [], array $histLabels = [],
                      array $histRanges = [], array $children = []): void {
    $cid     = (int)$cat['id'];
    $sel     = $selCats[$cid] ?? null;
    $checked = $sel !== null;
    $type    = $sel['type']      ?? 'monthly';
    $amount  = $sel['amount']    ?? 0;
    $months  = $sel['months']    ?? [];
    $dash    = $sel['dashboard'] ?? 0;
    $isVar   = $type === 'variable';
    $hist    = $histSpend[$cid] ?? null;
    // Disable inputs for unchecked rows so they are excluded from POST,
    // preventing max_input_vars truncation with large category lists.
    $dis     = $checked ? '' : 'disabled';

    // Determine what to render in the history section
    $hasOwnData = $hist && (abs($hist['last_year']) >= 0.005
                         || abs($hist['last_12mo'])  >= 0.005
                         || abs($hist['last_month']) >= 0.005);
    $hasChildData = false;
    foreach ($children as $ch) {
        $d = $histSpend[(int)$ch['id']] ?? null;
        if ($d && (abs($d['last_year']) >= 0.005 || abs($d['last_12mo']) >= 0.005 || abs($d['last_month']) >= 0.005)) {
            $hasChildData = true;
            break;
        }
    }
    $haveRanges      = !empty($histRanges);
    $showParentTable = $haveRanges && !$isChild && !empty($children) && ($hasOwnData || $hasChildData);
    $showSimpleTable = $haveRanges && !$isChild && empty($children) && $hasOwnData;
    ?>
<div class="wiz-cat-row <?= $isChild ? 'wiz-cat-child' : 'wiz-cat-parent' ?>" data-cid="<?= $cid ?>">
  <div class="wiz-cat-main">
    <label class="wiz-cat-check-label">
      <input type="checkbox" class="wiz-cat-include" name="cats[<?= $cid ?>][include]" value="1"
             <?= $checked ? 'checked' : '' ?>>
      <span class="wiz-cat-name"><?= h($cat['name']) ?></span>
    </label>
    <div class="wiz-cat-options <?= $checked ? '' : 'd-none' ?>">
      <select class="form-select form-select-sm wiz-entry-type" name="cats[<?= $cid ?>][type]" <?= $dis ?>>
        <option value="monthly"  <?= $type === 'monthly'  ? 'selected' : '' ?>>Monthly</option>
        <option value="annual"   <?= $type === 'annual'   ? 'selected' : '' ?>>Annual</option>
        <option value="variable" <?= $type === 'variable' ? 'selected' : '' ?>>Variable</option>
      </select>
      <div class="wiz-cat-amount-wrap <?= $isVar ? 'd-none' : '' ?>">
        <div class="input-group input-group-sm">
          <span class="input-group-text">$</span>
          <input type="number" class="form-control wiz-cat-amount" name="cats[<?= $cid ?>][amount]"
                 value="<?= $amount > 0 ? number_format($amount, 2, '.', '') : '' ?>"
                 min="0" step="0.01" placeholder="0.00" style="width:90px" <?= $dis ?>>
        </div>
      </div>
      <span class="wiz-equiv-hint text-muted small"></span>
      <label class="wiz-dash-label" title="Show this category on the dashboard budget widget">
        <input type="checkbox" class="wiz-dash-check" name="cats[<?= $cid ?>][dashboard]" value="1"
               <?= $dash ? 'checked' : '' ?> <?= $dis ?>>
        <i class="bi bi-speedometer2"></i> Dashboard
      </label>
    </div>
  </div>

  <?php if ($showParentTable):
    $lyR  = $histRanges['last_year'];
    $l12R = $histRanges['last_12mo'];
    $lmR  = $histRanges['last_month'];
    // Compute totals across direct + all children
    $totLy  = $hist ? abs($hist['last_year'])  : 0;
    $totL12 = $hist ? abs($hist['last_12mo'])  : 0;
    $totLm  = $hist ? abs($hist['last_month']) : 0;
    foreach ($children as $ch) {
        $d = $histSpend[(int)$ch['id']] ?? null;
        if ($d) { $totLy += abs($d['last_year']); $totL12 += abs($d['last_12mo']); $totLm += abs($d['last_month']); }
    }
  ?>
  <div class="wiz-hist-wrap">
    <table class="wiz-hist-table">
      <thead>
        <tr>
          <th class="wiz-hist-cat-col">Subcategory</th>
          <th><?= h($histLabels['prev_year'] ?? 'Last Year') ?></th>
          <th><?= h($histLabels['last_12mo']  ?? 'Last 12 Mo') ?></th>
          <th><?= h($histLabels['last_month'] ?? 'Last Month') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($hasOwnData): ?>
        <tr>
          <td class="wiz-hist-cat-col wiz-hist-direct">(direct)</td>
          <td><?= histAmt($hist['last_year'],  $cid, $lyR['start'],  $lyR['end'])  ?></td>
          <td><?= histAmt($hist['last_12mo'],  $cid, $l12R['start'], $l12R['end']) ?></td>
          <td><?= histAmt($hist['last_month'], $cid, $lmR['start'],  $lmR['end'])  ?></td>
        </tr>
        <?php endif; ?>
        <?php foreach ($children as $ch):
            $chid = (int)$ch['id'];
            $d    = $histSpend[$chid] ?? null;
        ?>
        <tr>
          <td class="wiz-hist-cat-col"><?= h($ch['name']) ?></td>
          <td><?= $d ? histAmt($d['last_year'],  $chid, $lyR['start'],  $lyR['end'])  : '<span class="wiz-hist-zero">—</span>' ?></td>
          <td><?= $d ? histAmt($d['last_12mo'],  $chid, $l12R['start'], $l12R['end']) : '<span class="wiz-hist-zero">—</span>' ?></td>
          <td><?= $d ? histAmt($d['last_month'], $chid, $lmR['start'],  $lmR['end'])  : '<span class="wiz-hist-zero">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if ($totLy >= 0.005 || $totL12 >= 0.005 || $totLm >= 0.005): ?>
      <tfoot>
        <tr>
          <td class="wiz-hist-cat-col">Total</td>
          <td><?= $totLy  >= 0.005 ? formatMoney($totLy)  : '<span class="wiz-hist-zero">—</span>' ?></td>
          <td><?= $totL12 >= 0.005 ? formatMoney($totL12) : '<span class="wiz-hist-zero">—</span>' ?></td>
          <td><?= $totLm  >= 0.005 ? formatMoney($totLm)  : '<span class="wiz-hist-zero">—</span>' ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>

  <?php elseif ($showSimpleTable):
    $lyR  = $histRanges['last_year'];
    $l12R = $histRanges['last_12mo'];
    $lmR  = $histRanges['last_month'];
  ?>
  <div class="wiz-hist-wrap">
    <table class="wiz-hist-table wiz-hist-simple">
      <thead>
        <tr>
          <th><?= h($histLabels['prev_year'] ?? 'Last Year') ?></th>
          <th><?= h($histLabels['last_12mo']  ?? 'Last 12 Mo') ?></th>
          <th><?= h($histLabels['last_month'] ?? 'Last Month') ?></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><?= histAmt($hist['last_year'],  $cid, $lyR['start'],  $lyR['end'])  ?></td>
          <td><?= histAmt($hist['last_12mo'],  $cid, $l12R['start'], $l12R['end']) ?></td>
          <td><?= histAmt($hist['last_month'], $cid, $lmR['start'],  $lmR['end'])  ?></td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="wiz-cat-variable <?= $isVar ? '' : 'd-none' ?>">
    <div class="wiz-months-grid">
      <?php foreach ($monthNames as $mi => $mLabel): $mn = $mi + 1; ?>
      <div class="wiz-month-cell">
        <label><?= $mLabel ?></label>
        <input type="number" class="form-control form-control-sm"
               name="cats[<?= $cid ?>][months][<?= $mn ?>]"
               value="<?= isset($months[$mn]) && $months[$mn] > 0 ? number_format($months[$mn], 2, '.', '') : '' ?>"
               min="0" step="0.01" placeholder="0" <?= $dis ?>>
      </div>
      <?php endforeach; ?>
    </div>
    <?php $annualTotal = array_sum($months); ?>
    <div class="wiz-var-total">Annual total: <strong class="wiz-var-total-val"><?= $annualTotal > 0 ? '$' . number_format($annualTotal, 2) : '$0.00' ?></strong></div>
  </div>
</div>
<?php }
