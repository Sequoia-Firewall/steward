<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

$pageTitle   = 'Database Migrations';
$currentPage = 'migrations';

$allFiles      = getMigrationFiles();
$applied       = array_flip(getAppliedMigrationVersions());
$schemaVersion = getCurrentSchemaVersion();
$schemaTarget  = getAppSchemaVersion();
$pending       = array_diff_key($allFiles, $applied);

include __DIR__ . '/../includes/header.php';
?>
<div class="page-content">

  <div class="page-header d-flex align-items-center gap-3 mb-4">
    <div>
      <h1 class="mb-0"><i class="bi bi-database-gear"></i> Database Migrations</h1>
      <p class="text-muted mb-0 mt-1">Track and apply incremental schema changes.</p>
    </div>
    <a href="<?= BASE_PATH ?>/settings/backup" class="btn btn-sm btn-outline-secondary ms-auto">
      <i class="bi bi-chevron-left"></i> Backup / Restore
    </a>
  </div>

  <?= renderFlash() ?>

  <div class="row g-3 mb-4">
    <div class="col-sm-4">
      <div class="card text-center py-3">
        <div class="fs-2 fw-bold text-primary"><?= h(APP_VERSION) ?></div>
        <div class="text-muted small mt-1">App Version</div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="card text-center py-3">
        <div class="fs-2 fw-bold <?= $schemaVersion < $schemaTarget ? 'text-warning' : 'text-success' ?>">
          v<?= str_pad((string)$schemaVersion, 3, '0', STR_PAD_LEFT) ?>
        </div>
        <div class="text-muted small mt-1">Schema Version</div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="card text-center py-3">
        <div class="fs-2 fw-bold <?= count($pending) > 0 ? 'text-danger' : 'text-success' ?>">
          <?= count($pending) ?>
        </div>
        <div class="text-muted small mt-1">Pending Migration<?= count($pending) !== 1 ? 's' : '' ?></div>
      </div>
    </div>
  </div>

  <?php if (!empty($pending)): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-5"></i>
    <div>
      <strong><?= count($pending) ?> pending migration<?= count($pending) !== 1 ? 's' : '' ?>.</strong>
      The database schema is behind the latest version. Apply all pending migrations to restore full functionality.
    </div>
    <button type="button" class="btn btn-warning ms-auto" id="btnRunAll" onclick="runAllMigrations()">
      <i class="bi bi-play-circle"></i> Apply All
    </button>
  </div>
  <?php else: ?>
  <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-check-circle-fill fs-5"></i>
    <strong>Database is up to date.</strong>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><strong>Migration History</strong></div>
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:70px">Version</th>
          <th>File</th>
          <th style="width:180px">Applied At</th>
          <th style="width:90px">Status</th>
        </tr>
      </thead>
      <tbody id="migrationRows">
        <?php
        // Fetch applied_at for display
        $appliedRows = [];
        try {
            $rows = getDB()->query('SELECT version, filename, applied_at FROM schema_migrations ORDER BY version')
                           ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $appliedRows[(int)$r['version']] = $r;
        } catch (Throwable $e) {}

        foreach ($allFiles as $ver => $path):
            $isApplied = isset($applied[$ver]);
            $appliedAt = $appliedRows[$ver]['applied_at'] ?? null;
        ?>
        <tr id="mig-row-<?= $ver ?>">
          <td class="font-monospace">v<?= str_pad($ver, 3, '0', STR_PAD_LEFT) ?></td>
          <td class="font-monospace small"><?= h(basename($path)) ?></td>
          <td class="small text-muted"><?= $appliedAt ? h($appliedAt) : '—' ?></td>
          <td>
            <?php if ($isApplied): ?>
            <span class="badge bg-success">Applied</span>
            <?php else: ?>
            <span class="badge bg-warning text-dark" id="mig-badge-<?= $ver ?>">Pending</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<script>
const MIG_BASE = '<?= BASE_PATH ?>';
const MIG_CSRF = <?= json_encode(csrfToken()) ?>;

async function runAllMigrations() {
  const btn = document.getElementById('btnRunAll');
  if (!btn) return;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Applying…';

  try {
    const res  = await fetch(MIG_BASE + '/settings/run_migrations', {
      method: 'POST',
      body: new URLSearchParams({ csrf_token: MIG_CSRF })
    });
    const data = await res.json();

    if (!data.ok) {
      showToast(data.error || 'An error occurred.', 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-play-circle"></i> Apply All';
      return;
    }

    // Update UI for each applied migration
    (data.applied || []).forEach(ver => {
      const badge = document.getElementById('mig-badge-' + ver);
      if (badge) {
        badge.className = 'badge bg-success';
        badge.textContent = 'Applied';
      }
      const row = document.getElementById('mig-row-' + ver);
      if (row) {
        const td = row.querySelector('td:nth-child(3)');
        if (td) td.textContent = data.timestamp || '';
      }
    });

    // Reload to refresh tiles and alert banner
    window.location.reload();
  } catch (e) {
    console.error(e);
    showToast('Network error.', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-play-circle"></i> Apply All';
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
