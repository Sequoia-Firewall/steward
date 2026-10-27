<?php
// Realized Sales section for one security: every FIFO-matched sale across all of its
// accounts (getRealizedSales()), oldest lots first. Each row expands to show the lots
// the sale consumed. Expects $invId; renders nothing when the security has no sales.
$_rsSales = array_values(array_filter(getRealizedSales(), fn($s) => $s['investment_id'] === (int)$invId));
if (!empty($_rsSales)):
    $_rsAcctNames = array_column(getDB()->query('SELECT id, name FROM accounts')->fetchAll(), 'name', 'id');
    $_rsTot = ['proceeds' => 0.0, 'basis' => 0.0, 'gain' => 0.0, 'short' => 0.0, 'long' => 0.0];
    foreach ($_rsSales as $_s) {
        $_rsTot['proceeds'] += $_s['proceeds'];
        $_rsTot['basis']    += $_s['basis'];
        $_rsTot['gain']     += $_s['gain'];
        $_rsTot['short']    += $_s['gain_short'];
        $_rsTot['long']     += $_s['gain_long'];
    }
    $_rsQty  = fn(float $q) => rtrim(rtrim(number_format($q, 6), '0'), '.');
    $_rsGain = fn(float $g) => '<span class="' . ($g >= 0 ? 'amount-credit' : 'amount-debit') . '">'
                             . ($g >= 0 ? '+' : '-') . formatMoney(abs($g)) . '</span>';
?>
<!-- Realized Sales -->
<div class="dash-section">
  <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
    <h4 class="section-title mb-0"><i class="bi bi-cash-coin"></i> Realized Sales</h4>
    <span class="text-muted small">
      Short-term <?= $_rsGain($_rsTot['short']) ?> · Long-term <?= $_rsGain($_rsTot['long']) ?>
    </span>
  </div>
  <table class="table table-sm report-table mb-1">
    <thead>
      <tr>
        <th>Date Sold</th><th>Account</th><th>Term</th>
        <th class="text-end">Shares</th><th class="text-end">Price</th><th class="text-end">Proceeds</th>
        <th class="text-end">Cost Basis</th><th class="text-end">Gain / Loss</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (array_reverse($_rsSales) as $_s):
        $_term = $_s['qty_long'] > 0.0000001
            ? ($_s['qty_short'] > 0.0000001 ? 'Mixed' : 'Long')
            : 'Short';
      ?>
      <tr>
        <td><?= formatDate($_s['date']) ?><?= $_s['activity'] === 'remove' ? ' <span class="text-muted small">(expired)</span>' : '' ?></td>
        <td class="text-muted small"><?= h($_rsAcctNames[$_s['account_id']] ?? '') ?></td>
        <td><?= $_term ?></td>
        <td class="text-end"><?= $_rsQty($_s['qty']) ?></td>
        <td class="text-end"><?= formatMoney($_s['price']) ?></td>
        <td class="text-end"><?= formatMoney($_s['proceeds']) ?></td>
        <td class="text-end"><?= formatMoney($_s['basis']) ?></td>
        <td class="text-end"><?= $_rsGain($_s['gain']) ?></td>
      </tr>
      <tr class="realized-lots-row">
        <td colspan="8" class="pt-0 border-top-0">
          <details class="small">
            <summary class="text-muted" style="cursor:pointer">
              <?= count($_s['lots']) ?> lot<?= count($_s['lots']) !== 1 ? 's' : '' ?> sold (FIFO)
            </summary>
            <table class="table table-sm mt-1 mb-1">
              <thead><tr><th>Acquired</th><th class="text-end">Shares</th><th class="text-end">Cost Basis</th><th class="text-end">Held</th><th>Term</th></tr></thead>
              <tbody>
                <?php foreach ($_s['lots'] as $_l):
                  $_days = $_l['acquired'] ? (int)round((strtotime($_s['date']) - strtotime($_l['acquired'])) / 86400) : null;
                ?>
                <tr>
                  <td><?= $_l['acquired'] ? formatDate($_l['acquired']) : '<span class="text-muted" title="More shares sold than were on record">No lot on record</span>' ?></td>
                  <td class="text-end"><?= $_rsQty($_l['qty']) ?></td>
                  <td class="text-end"><?= formatMoney($_l['cost']) ?></td>
                  <td class="text-end"><?= $_days === null ? '—' : ($_days < 365 ? $_days . ' d' : number_format($_days / 365.25, 1) . ' yr') ?></td>
                  <td><?= $_l['long_term'] ? 'Long' : 'Short' ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="5"><strong>Total</strong></td>
        <td class="text-end"><strong><?= formatMoney($_rsTot['proceeds']) ?></strong></td>
        <td class="text-end"><strong><?= formatMoney($_rsTot['basis']) ?></strong></td>
        <td class="text-end"><strong><?= $_rsGain($_rsTot['gain']) ?></strong></td>
      </tr>
    </tfoot>
  </table>
  <p class="text-muted small mb-0">
    Cost basis uses FIFO (oldest shares sold first) and is an approximation. Your broker's
    statements and Form 1099-B are the reference for taxes.
  </p>
</div>
<?php endif; ?>
