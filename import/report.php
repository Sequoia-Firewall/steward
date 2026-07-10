<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/import_helpers.php';
requireLogin();
if (!canImport()) { http_response_code(403); setFlash("error", "Access denied."); header("Location: " . BASE_PATH . "/index"); exit; }

if (empty($_SESSION['import_report'])) {
    setFlash('error', 'No import report available.');
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

$report     = $_SESSION['import_report'];
$summary    = $report['summary'];
$isInv      = $report['is_investment'];
$isMultiAcct= $report['is_multi_account'] ?? false;
$stmtType   = $report['stmt_type'] ?? 'transaction_history';
$isHoldings = ($stmtType === 'holdings') && $isInv;
$rows      = $report['rows']         ?? [];
$skipReasons  = $report['skip_reasons']  ?? [];
$actionTypes  = $report['action_types']  ?? [];
$secCreated   = $report['securities']['created']       ?? [];
$secMatched   = $report['securities']['matched_count'] ?? 0;
$catsCreated  = $report['categories']['created']       ?? [];
$accountId    = (int)$report['account_id'];

// ── CSV download ──────────────────────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="import-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    $isMultiAcctCsv = $report['is_multi_account'] ?? false;
    $headers = $isMultiAcctCsv
        ? ['Account', 'Date', 'Payee / Security', 'Action Type', 'Amount', 'Status', 'Reason', 'Cash Account', 'Cash Amount']
        : ['Date', 'Payee / Security', 'Action Type', 'Amount', 'Status', 'Reason', 'Cash Account', 'Cash Amount'];
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        $line = [
            $r['date'],
            $r['payee'],
            $r['action_type'],
            number_format((float)$r['amount'], 2, '.', ''),
            $r['status'],
            $r['reason'],
            $r['cash_account'],
            $r['cash_amount'] != 0.0 ? number_format((float)$r['cash_amount'], 2, '.', '') : '',
        ];
        if ($isMultiAcctCsv) array_unshift($line, $r['account_name'] ?? '');
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

$pageTitle   = 'Import Report — ' . $report['account_name'];
$currentPage = 'import';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-content">

  <div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div>
      <h1 class="mb-0"><i class="bi bi-clipboard-check"></i> Import Report</h1>
      <p class="text-muted mb-0 mt-1">
        <strong><?= h($report['account_name']) ?></strong> ·
        <?= h($report['format']) ?> ·
        <?= $isHoldings ? 'Holdings Reconciliation' : 'Transaction History' ?> ·
        <?= h($report['imported_at']) ?>
      </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($accountId): ?>
      <a href="<?= BASE_PATH ?>/accounts/register?id=<?= $accountId ?>" class="btn btn-primary">
        <i class="bi bi-journal-text"></i> View Register
      </a>
      <?php endif; ?>
      <a href="<?= BASE_PATH ?>/import/report?download=csv" class="btn btn-outline-secondary">
        <i class="bi bi-download"></i> Download CSV
      </a>
      <a href="<?= BASE_PATH ?>/import/index" class="btn btn-outline-secondary">
        <i class="bi bi-upload"></i> New Import
      </a>
    </div>
  </div>

  <?php if ($report['new_account']): ?>
  <div class="alert alert-success py-2">
    <i class="bi bi-plus-circle-fill me-1"></i>
    New account <strong><?= h($report['account_name']) ?></strong> was created
    <?php if ($report['opening_balance'] > 0): ?>
    with an opening balance of <strong><?= formatMoney($report['opening_balance']) ?></strong>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── Summary stat cards ─────────────────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card text-center h-100">
        <div class="card-body py-3">
          <div class="display-6 fw-bold"><?= $summary['selected'] ?></div>
          <div class="text-muted small">Selected</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card text-center h-100 border-success">
        <div class="card-body py-3">
          <div class="display-6 fw-bold text-success"><?= $summary['imported'] ?></div>
          <div class="text-muted small">Imported</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card text-center h-100 <?= $summary['skipped'] > 0 ? 'border-warning' : '' ?>">
        <div class="card-body py-3">
          <div class="display-6 fw-bold <?= $summary['skipped'] > 0 ? 'text-warning' : '' ?>"><?= $summary['skipped'] ?></div>
          <div class="text-muted small">Skipped</div>
        </div>
      </div>
    </div>
    <?php if ($isInv && !$isHoldings): ?>
    <div class="col-6 col-md-3">
      <div class="card text-center h-100">
        <div class="card-body py-3">
          <div class="display-6 fw-bold"><?= $summary['cash_legs'] ?></div>
          <div class="text-muted small">Cash Entries</div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if (($summary['transfer_pairs'] ?? 0) > 0): ?>
    <div class="col-6 col-md-3">
      <div class="card text-center h-100">
        <div class="card-body py-3">
          <div class="display-6 fw-bold text-info"><?= $summary['transfer_pairs'] ?></div>
          <div class="text-muted small">Transfer Pairs Linked</div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($skipReasons)): ?>
  <!-- ── Skip reasons ────────────────────────────────────────────────────────── -->
  <div class="card mb-4 border-warning">
    <div class="card-header bg-warning bg-opacity-10">
      <strong><i class="bi bi-exclamation-triangle me-1"></i>Skipped Rows</strong>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr><th>Reason</th><th class="text-end">Count</th></tr>
        </thead>
        <tbody>
          <?php foreach ($skipReasons as $reason => $count): ?>
          <tr>
            <td><?= h($reason) ?></td>
            <td class="text-end"><?= $count ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-4 mb-4">

    <?php if ($isInv && !$isHoldings && !empty($actionTypes)): ?>
    <!-- ── Action type breakdown ──────────────────────────────────────────────── -->
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header"><strong><i class="bi bi-bar-chart me-1"></i>Action Types</strong></div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr><th>Action</th><th>Code</th><th class="text-end">Count</th></tr>
            </thead>
            <tbody>
              <?php foreach ($actionTypes as $at => $cnt): ?>
              <tr>
                <td><?= h(actionTypeLabel($at)) ?></td>
                <td class="text-muted font-monospace small"><?= h($at) ?></td>
                <td class="text-end"><?= $cnt ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isInv && !$isHoldings && (!empty($secCreated) || $secMatched > 0 || !empty($catsCreated))): ?>
    <!-- ── Securities & categories ──────────────────────────────────────────── -->
    <div class="col-md-6">
      <?php if (!empty($secCreated) || $secMatched > 0): ?>
      <div class="card mb-3">
        <div class="card-header"><strong><i class="bi bi-graph-up me-1"></i>Securities</strong></div>
        <div class="card-body">
          <?php if ($secMatched > 0): ?>
          <p class="mb-2 small"><i class="bi bi-check-circle text-success me-1"></i>
            Matched <strong><?= $secMatched ?></strong> existing <?= $secMatched === 1 ? 'security' : 'securities' ?></p>
          <?php endif; ?>
          <?php if (!empty($secCreated)): ?>
          <p class="mb-2 small"><i class="bi bi-plus-circle text-info me-1"></i>
            Created <strong><?= count($secCreated) ?></strong> new <?= count($secCreated) === 1 ? 'security' : 'securities' ?>:</p>
          <ul class="list-unstyled mb-0 ms-3 small">
            <?php foreach ($secCreated as $sec): ?>
            <li>
              <?= h($sec['name']) ?>
              <?php if (!empty($sec['symbol'])): ?>
              <span class="text-muted font-monospace">(<?= h($sec['symbol']) ?>)</span>
              <?php endif; ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($catsCreated)): ?>
      <div class="card">
        <div class="card-header"><strong><i class="bi bi-tags me-1"></i>Categories Created</strong></div>
        <div class="card-body">
          <ul class="list-unstyled mb-0 small">
            <?php foreach ($catsCreated as $cat): ?>
            <li><i class="bi bi-plus-circle text-info me-1"></i><?= h($cat) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$isInv && !empty($catsCreated)): ?>
    <div class="col-md-6">
      <div class="card">
        <div class="card-header"><strong><i class="bi bi-tags me-1"></i>Categories Created</strong></div>
        <div class="card-body">
          <ul class="list-unstyled mb-0 small">
            <?php foreach ($catsCreated as $cat): ?>
            <li><i class="bi bi-plus-circle text-info me-1"></i><?= h($cat) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- ── Row detail (collapsible) ──────────────────────────────────────────── -->
  <?php if (!empty($rows)): ?>
  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <strong><i class="bi bi-list-ul me-1"></i>Row Detail</strong>
      <button class="btn btn-sm btn-outline-secondary" type="button"
              data-bs-toggle="collapse" data-bs-target="#rowDetail" aria-expanded="false">
        <i class="bi bi-chevron-down" id="rowDetailIcon"></i> Show
      </button>
    </div>
    <div class="collapse" id="rowDetail">
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 small">
          <thead class="table-dark">
            <tr>
              <?php if ($isMultiAcct): ?><th>Account</th><?php endif; ?>
              <th>Date</th>
              <th>Payee / Security</th>
              <?php if ($isInv && !$isHoldings): ?>
              <th>Action</th>
              <th class="text-end">Amount</th>
              <th>Cash Account</th>
              <th class="text-end">Cash Amount</th>
              <?php elseif ($isHoldings): ?>
              <th>Action</th>
              <?php else: ?>
              <th class="text-end">Amount</th>
              <?php endif; ?>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['status'] === 'skipped' ? 'table-warning' : '' ?>">
              <?php if ($isMultiAcct): ?><td class="text-nowrap text-muted small"><?= h($r['account_name'] ?? '') ?></td><?php endif; ?>
              <td class="text-nowrap"><?= h($r['date'] ? date('m/d/Y', strtotime($r['date'])) : '') ?></td>
              <td><?= h($r['payee']) ?></td>
              <?php if ($isInv && !$isHoldings): ?>
              <td class="text-nowrap">
                <?php if ($r['action_type'] !== ''): ?>
                <?= h(actionTypeLabel($r['action_type'])) ?>
                <?php if (actionTypeLabel($r['action_type']) !== $r['action_type']): ?>
                <br><span class="text-muted font-monospace" style="font-size:.75em"><?= h($r['action_type']) ?></span>
                <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="text-end font-monospace <?= round((float)$r['amount'], MONEY_DECIMALS) < 0 ? 'neg' : 'pos' ?>">
                <?php if ($r['amount'] != 0.0): ?>
                <?= (float)$r['amount'] < 0 ? '−' : '+' ?><?= formatMoney(abs((float)$r['amount'])) ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td class="text-muted"><?= h($r['cash_account']) ?></td>
              <td class="text-end font-monospace">
                <?php if ($r['cash_amount'] != 0.0): ?>
                <?= (float)$r['cash_amount'] < 0 ? '−' : '+' ?><?= formatMoney(abs((float)$r['cash_amount'])) ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <?php elseif ($isHoldings): ?>
              <td>
                <?php if ($r['action_type'] !== ''): ?>
                <span class="badge <?= $r['action_type'] === 'ShrsIn' ? 'bg-success' : 'bg-danger' ?>">
                  <?= $r['action_type'] === 'ShrsIn' ? 'Add' : 'Remove' ?>
                </span>
                <?php endif; ?>
              </td>
              <?php else: ?>
              <td class="text-end font-monospace <?= round((float)$r['amount'], MONEY_DECIMALS) < 0 ? 'neg' : 'pos' ?>">
                <?= (float)$r['amount'] < 0 ? '−' : '+' ?><?= formatMoney(abs((float)$r['amount'])) ?>
              </td>
              <?php endif; ?>
              <td>
                <?php if ($r['status'] === 'skipped'): ?>
                <span class="badge bg-warning text-dark" title="<?= h($r['reason']) ?>">Skipped</span>
                <?php else: ?>
                <span class="badge bg-success">Imported</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
(function () {
    const el = document.getElementById('rowDetail');
    const icon = document.getElementById('rowDetailIcon');
    if (!el) return;
    el.addEventListener('show.bs.collapse',  () => { icon.className = 'bi bi-chevron-up'; });
    el.addEventListener('hide.bs.collapse',  () => { icon.className = 'bi bi-chevron-down'; });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
