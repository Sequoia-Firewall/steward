<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

$pageTitle   = 'Activity Log';
$currentPage = 'activity_log';

$db = getDB();

// ── Filters ─────────────────────────────────────────────────────
$filterUser  = (int)($_GET['user_id'] ?? 0);
$filterEvent = trim($_GET['event'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 50;
$offset      = ($page - 1) * $perPage;

// ── Event label/badge map ────────────────────────────────────────
$eventMeta = [
    'login'           => ['label' => 'Login',           'badge' => 'bg-success'],
    'login_failed'    => ['label' => 'Failed Login',     'badge' => 'bg-danger'],
    'logout'          => ['label' => 'Logout',           'badge' => 'bg-secondary'],
    'session_timeout' => ['label' => 'Session Timeout',  'badge' => 'bg-warning text-dark'],
    'txn_created'     => ['label' => 'Transaction Added','badge' => 'bg-primary'],
    'txn_edited'      => ['label' => 'Transaction Edited','badge' => 'bg-info text-dark'],
    'txn_deleted'     => ['label' => 'Transaction Deleted','badge' => 'bg-danger'],
    'account_created' => ['label' => 'Account Created',  'badge' => 'bg-primary'],
    'account_deleted' => ['label' => 'Account Deleted',  'badge' => 'bg-danger'],
    'import_complete' => ['label' => 'Import',           'badge' => 'bg-success'],
    'activity_log_purged' => ['label' => 'Log Purged',   'badge' => 'bg-warning text-dark'],
];

// ── Users list for filter dropdown ──────────────────────────────
$users = $db->query('SELECT DISTINCT user_id, user_name FROM activity_log WHERE user_id IS NOT NULL ORDER BY user_name')->fetchAll();

// ── Build WHERE ──────────────────────────────────────────────────
$where  = [];
$params = [];
if ($filterUser) {
    $where[]  = 'user_id = ?';
    $params[] = $filterUser;
}
if ($filterEvent !== '') {
    $where[]  = 'event = ?';
    $params[] = $filterEvent;
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Total count ──────────────────────────────────────────────────
$totalStmt = $db->prepare("SELECT COUNT(*) FROM activity_log $whereClause");
$totalStmt->execute($params);
$total     = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

// ── Rows ─────────────────────────────────────────────────────────
$rowStmt = $db->prepare("SELECT * FROM activity_log $whereClause ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$rowStmt->execute($params);
$rows = $rowStmt->fetchAll();

// ── Pagination URL helper ────────────────────────────────────────
function pagUrl(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    return '?' . http_build_query($q);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-content">

  <div class="page-header d-flex align-items-center gap-3 mb-4">
    <div>
      <h1 class="mb-0"><i class="bi bi-clock-history"></i> Activity Log</h1>
      <p class="text-muted mb-0 mt-1">Audit trail of sign-ins, transactions, and account changes.</p>
    </div>
    <div class="ms-auto dropdown">
      <button class="btn btn-sm btn-outline-danger dropdown-toggle" type="button" id="deleteLogBtn"
              data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-trash3"></i> Delete Entries
      </button>
      <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="deleteLogBtn">
        <li><a class="dropdown-item" href="#" onclick="deleteLogEntries('30', this); return false;">Older than 30 days</a></li>
        <li><a class="dropdown-item" href="#" onclick="deleteLogEntries('60', this); return false;">Older than 60 days</a></li>
        <li><a class="dropdown-item" href="#" onclick="deleteLogEntries('365', this); return false;">Older than 1 year</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="#" onclick="deleteLogEntries('all', this); return false;">All entries</a></li>
      </ul>
    </div>
  </div>

  <?= renderFlash() ?>

  <!-- ── Filters ──────────────────────────────────────────────── -->
  <form method="get" class="d-flex flex-wrap gap-2 mb-3 align-items-end">
    <div>
      <label class="form-label small mb-1">User</label>
      <select name="user_id" class="form-select form-select-sm" style="min-width:160px" onchange="this.form.submit()">
        <option value="0">All users</option>
        <?php foreach ($users as $u): ?>
        <option value="<?= $u['user_id'] ?>" <?= $filterUser === (int)$u['user_id'] ? 'selected' : '' ?>>
          <?= h($u['user_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label small mb-1">Event</label>
      <select name="event" class="form-select form-select-sm" style="min-width:180px" onchange="this.form.submit()">
        <option value="">All events</option>
        <?php foreach ($eventMeta as $key => $meta): ?>
        <option value="<?= $key ?>" <?= $filterEvent === $key ? 'selected' : '' ?>><?= $meta['label'] ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($filterUser || $filterEvent): ?>
    <div>
      <a href="<?= BASE_PATH ?>/settings/activity_log" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-x"></i> Clear
      </a>
    </div>
    <?php endif; ?>
    <div class="ms-auto text-muted small align-self-center">
      <?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?>
    </div>
  </form>

  <!-- ── Log table ────────────────────────────────────────────── -->
  <?php if (empty($rows)): ?>
  <div class="card">
    <div class="card-body text-muted text-center py-5">
      <i class="bi bi-clock-history fs-2 d-block mb-2"></i>
      No activity recorded yet.
    </div>
  </div>
  <?php else: ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:160px">Date / Time</th>
            <th style="width:140px">User</th>
            <th style="width:160px">Event</th>
            <th>Details</th>
            <th style="width:120px" class="text-muted">IP Address</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $meta = $eventMeta[$row['event']] ?? ['label' => $row['event'], 'badge' => 'bg-secondary'];
            $tz   = getSetting('timezone', 'America/New_York');
            $dt   = new DateTime($row['created_at'], new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($tz));
          ?>
          <tr>
            <td class="text-muted small text-nowrap"><?= $dt->format('M j, Y g:i a') ?></td>
            <td class="small"><?= h($row['user_name']) ?: '<span class="text-muted">—</span>' ?></td>
            <td><span class="badge <?= $meta['badge'] ?> fw-normal"><?= $meta['label'] ?></span></td>
            <td class="small"><?= h($row['description']) ?></td>
            <td class="text-muted small font-monospace"><?= h($row['ip_address'] ?? '') ?: '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($totalPages > 1): ?>
  <nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center mb-0">
      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= pagUrl($page - 1) ?>"><i class="bi bi-chevron-left"></i></a>
      </li>
      <?php
      $start = max(1, $page - 3);
      $end   = min($totalPages, $page + 3);
      if ($start > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
      for ($p = $start; $p <= $end; $p++): ?>
      <li class="page-item <?= $p === $page ? 'active' : '' ?>">
        <a class="page-link" href="<?= pagUrl($p) ?>"><?= $p ?></a>
      </li>
      <?php endfor;
      if ($end < $totalPages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
        <a class="page-link" href="<?= pagUrl($page + 1) ?>"><i class="bi bi-chevron-right"></i></a>
      </li>
    </ul>
  </nav>
  <?php endif; ?>
  <?php endif; ?>

</div>

<script>
const ALOG_CSRF = <?= json_encode(csrfToken()) ?>;
const ALOG_URL  = '<?= BASE_PATH ?>/settings/activity_log_delete';

function deleteLogEntries(range, link) {
  const labels = {
    '30':  'entries older than 30 days',
    '60':  'entries older than 60 days',
    '365': 'entries older than 1 year',
    'all': 'ALL activity log entries',
  };
  if (!confirm('Permanently delete ' + labels[range] + '? This cannot be undone.')) return;

  fetch(ALOG_URL, {
    method: 'POST',
    body: new URLSearchParams({ csrf_token: ALOG_CSRF, range }),
  })
  .then(r => r.json())
  .then(json => {
    if (!json.ok) {
      alert(json.error || 'Delete failed.');
      return;
    }
    window.location.reload();
  })
  .catch(() => alert('Network error.'));
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
