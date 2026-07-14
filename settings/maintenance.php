<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

$pageTitle   = 'Integrity / Maintenance';
$currentPage = 'maintenance';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-content">

  <div class="page-header d-flex align-items-center gap-3 mb-4">
    <div>
      <h1 class="mb-0"><i class="bi bi-tools"></i> Integrity / Maintenance</h1>
      <p class="text-muted mb-0 mt-1">Data integrity checks and database maintenance operations.</p>
    </div>
  </div>

  <?= renderFlash() ?>

  <!-- ── Data Integrity & Maintenance ──────────────────────── -->
  <div class="card mb-4" id="maintenanceCard">
    <div class="card-header d-flex align-items-center gap-2">
      <strong><i class="bi bi-tools"></i> Data Integrity &amp; Maintenance</strong>
      <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="runAllBtn" onclick="runAllChecks()">
        <i class="bi bi-play-circle"></i> Run All Checks
      </button>
    </div>
    <div class="card-body p-0">
      <div class="list-group list-group-flush" id="maintenanceChecks">

        <?php
        $checks = [
          ['duplicate_categories',     'bi-tags',                 'Duplicate Category Names',
           'Categories or subcategories sharing the same name, which can cause ambiguous categorization.'],
          ['duplicate_investments',     'bi-diagram-3',            'Duplicate Investments',
           'Securities with the same name or CUSIP — likely caused by multiple imports of the same security.'],
          ['uncategorized_transactions','bi-question-circle',      'Uncategorized Transactions',
           'Non-transfer transactions that have no category assigned to their splits. Paired legs (transfers, and investment-cash deposits linked to a Buy/Sell/Dividend/Interest activity) are excluded — they aren\'t meant to carry a category.'],
          ['securities_no_cusip',       'bi-upc-scan',             'Securities Without CUSIP',
           'Active investments lacking a CUSIP identifier, which prevents reliable deduplication during imports.'],
          ['duplicate_transactions',    'bi-copy',                 'Duplicate Transactions',
           'Unreconciled transactions with identical account, date, payee, and amount — likely imported more than once. Reconciled transactions are excluded, as are cleared investment-account transactions verified by holdings reconciliation.'],
          ['split_mismatch',            'bi-calculator',           'Split Amount Mismatch',
           'Transactions where the sum of split amounts does not equal the transaction total.'],
          ['orphaned_categories',       'bi-folder-x',             'Transactions With Inactive Category',
           'Splits that reference a category which has since been deactivated.'],
          ['unmatched_transfers',       'bi-arrow-left-right',     'Unmatched Transfers',
           'Transfer transactions not linked to a counterpart — may indicate a missing or deleted leg.'],
          ['link_orphaned_transfers',   'bi-link-45deg',           'Link Orphaned Transfer Pairs',
           'Pairs of unlinked transfer transactions with matching dates and amounts that can be automatically connected.'],
          ['orphaned_securities',       'bi-eraser',               'Orphaned Securities',
           'Securities with no investment transactions, not on the Watchlist or an Index/Money Market fund, that have leftover price history from before bulk fetching excluded them. Purging removes only the unused price rows — reports are unaffected, since they only load prices for securities with actual transaction history. Security records are kept; deactivate any that are truly junk from Portfolio → Show Unowned. Only flagged while there\'s price history to purge — for a general list of unowned securities regardless of price data, use Portfolio → Show Unowned any time.'],
          ['budget_inactive_categories','bi-bar-chart-line',       'Budget → Inactive Category',
           'Active budget items assigned to a category that has been deactivated.'],
          ['bills_inactive_accounts',   'bi-calendar-x',           'Bills → Inactive Account',
           'Active bills or deposits linked to a closed or inactive account.'],
          ['orphaned_investment_txns',  'bi-graph-up',             'Orphaned Investment Transactions',
           'Investment activity records with no linked security — often caused by partial imports.'],
          ['categorized_investment_income', 'bi-cash-coin',        'Categorized Income in Investment-Cash Accounts',
           'Cash-sweep transactions whose payee matches a known security but were entered as a plain categorized deposit instead of a Buy/Sell/Dividend/Interest activity — these bypass per-security income tracking. Crypto accounts are excluded, since they don\'t use the same buy/sell activity model.'],
        ];
        foreach ($checks as [$slug, $icon, $label, $desc]):
        ?>
        <div class="maintenance-check list-group-item list-group-item-action" data-check="<?= h($slug) ?>">
          <div class="mc-header">
            <div class="mc-icon"><i class="bi <?= $icon ?>"></i></div>
            <div class="mc-info">
              <div class="mc-name"><?= h($label) ?></div>
              <div class="mc-desc text-muted"><?= h($desc) ?></div>
            </div>
            <div class="mc-actions">
              <span class="mc-status"></span>
              <button type="button" class="btn btn-sm btn-outline-secondary mc-run-btn"
                      onclick="runCheck(this.closest('.maintenance-check'))">
                <i class="bi bi-play"></i> Run
              </button>
            </div>
          </div>
          <div class="mc-result d-none"></div>
        </div>
        <?php endforeach; ?>

      </div>
    </div>
  </div>

  <!-- ── Security Checks ──────────────────────────────────── -->
  <div class="card mb-4" id="securityCard">
    <div class="card-header d-flex align-items-center gap-2">
      <strong><i class="bi bi-shield-exclamation text-danger"></i> Security</strong>
      <button type="button" class="btn btn-sm btn-outline-danger ms-auto" id="runAllSecBtn" onclick="runAllSecChecks()">
        <i class="bi bi-play-circle"></i> Run All
      </button>
    </div>
    <div class="card-body p-0">
      <div class="list-group list-group-flush" id="securityChecks">

        <?php
        $secChecks = [
          ['sec_default_passwords', 'bi-key',              'Default Credentials In Use',
           'User accounts that still have factory-default passwords shipped with the sample data.'],
          ['sec_setup_dir',         'bi-folder-symlink',   'Setup Directory Present',
           'The setup/ wizard directory should be deleted after installation to prevent re-initialization of the app.'],
          ['sec_php_display_errors','bi-code-slash',       'PHP Error Display Enabled',
           'PHP is configured to show errors in the browser, which can leak file paths, credentials, and stack traces.'],
          ['sec_debug_files',       'bi-file-earmark-code','Debug / Test Files Present',
           'Common diagnostic files (phpinfo.php, .env, adminer.php, etc.) that should not exist in a production install.'],
          ['sec_session_timeout',   'bi-hourglass',        'Session Timeout Disabled',
           'Flags when the idle session timeout is set to Never — users stay signed in indefinitely without any inactivity check.'],
          ['sec_log_retention',     'bi-journal-text',     'Activity Log Retention',
           'Shows the current retention period for activity logs. Change this under Settings → Preferences.'],
        ];
        foreach ($secChecks as [$slug, $icon, $label, $desc]):
        ?>
        <div class="maintenance-check list-group-item list-group-item-action" data-check="<?= h($slug) ?>">
          <div class="mc-header">
            <div class="mc-icon"><i class="bi <?= $icon ?>"></i></div>
            <div class="mc-info">
              <div class="mc-name"><?= h($label) ?></div>
              <div class="mc-desc text-muted"><?= h($desc) ?></div>
            </div>
            <div class="mc-actions">
              <span class="mc-status"></span>
              <button type="button" class="btn btn-sm btn-outline-secondary mc-run-btn"
                      onclick="runCheck(this.closest('.maintenance-check'))">
                <i class="bi bi-play"></i> Run
              </button>
            </div>
          </div>
          <div class="mc-result d-none"></div>
        </div>
        <?php endforeach; ?>

      </div>
    </div>
  </div>

  <!-- ── Database Maintenance ──────────────────────────────── -->
  <div class="card mb-4" id="dbMaintenanceCard">
    <div class="card-header d-flex align-items-center gap-2">
      <strong><i class="bi bi-database-gear"></i> Database Maintenance</strong>
    </div>
    <div class="card-body">

      <div class="row g-3 mb-4">

        <?php
        $ops = [
          ['check',    'bi-shield-check',   'Check Tables',    'btn-outline-secondary',
           'Verifies the integrity of every table and reports any corruption or inconsistencies.'],
          ['analyze',  'bi-bar-chart-line',  'Analyze Tables',  'btn-outline-primary',
           'Updates index statistics so the query optimizer can choose the most efficient execution plans.'],
          ['optimize', 'bi-lightning-charge','Optimize Tables', 'btn-outline-success',
           'Reclaims unused space from deleted rows and defragments table data. InnoDB tables are rebuilt in-place.'],
        ];
        foreach ($ops as [$opKey, $icon, $label, $btnClass, $desc]):
        ?>
        <div class="col-md-4">
          <div class="card h-100 border-0 bg-light">
            <div class="card-body d-flex flex-column gap-2">
              <div class="d-flex align-items-center gap-2">
                <i class="bi <?= $icon ?> fs-5"></i>
                <strong><?= $label ?></strong>
              </div>
              <p class="text-muted small mb-0 flex-grow-1"><?= $desc ?></p>
              <button type="button"
                      class="btn btn-sm <?= $btnClass ?> dbm-btn mt-1"
                      data-op="<?= $opKey ?>"
                      onclick="runDbOp('<?= $opKey ?>', this)">
                <i class="bi bi-play"></i> Run
              </button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>

      </div>

      <!-- ── Purge Deleted Categories ──────────────────────── -->
      <div class="border-top pt-3 mt-2">
        <div class="d-flex align-items-start gap-3">
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 mb-1">
              <i class="bi bi-trash3 text-danger fs-5"></i>
              <strong>Purge Deleted Categories</strong>
            </div>
            <p class="text-muted small mb-2">
              Permanently removes soft-deleted categories and subcategories that have no transactions,
              no budget entries, and were previously deleted by a user. A preview is shown before anything is deleted.
            </p>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="runPurgePreview(this)">
              <i class="bi bi-search"></i> Preview
            </button>
          </div>
        </div>
        <div id="purgeResult" class="mt-3" style="display:none"></div>
      </div>

      <div id="dbmResult" style="display:none">
        <h6 class="mb-2" id="dbmResultTitle"></h6>
        <div class="table-responsive">
          <table class="table table-sm table-bordered mb-0">
            <thead class="table-light">
              <tr>
                <th>Table</th>
                <th>Status</th>
                <th>Message</th>
              </tr>
            </thead>
            <tbody id="dbmResultBody"></tbody>
          </table>
        </div>
        <p class="text-muted small mt-2 mb-0" id="dbmResultSummary"></p>
      </div>

    </div>
  </div>

</div>

<script>
const MC_CSRF = <?= json_encode(csrfToken()) ?>;
const MC_URL  = '<?= BASE_PATH ?>/settings/maintenance_check';
const MC_BASE = '<?= BASE_PATH ?>';

function mcEsc(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function runCheck(row) {
  const check    = row.dataset.check;
  const statusEl = row.querySelector('.mc-status');
  const resultEl = row.querySelector('.mc-result');
  const btn      = row.querySelector('.mc-run-btn');

  btn.disabled = true;
  statusEl.innerHTML = '<span class="badge bg-secondary"><i class="bi bi-hourglass-split"></i> Running…</span>';
  resultEl.classList.add('d-none');

  return fetch(MC_URL, {
    method: 'POST',
    body: new URLSearchParams({ csrf_token: MC_CSRF, check })
  })
  .then(r => r.json())
  .then(json => {
    btn.disabled = false;
    if (!json.ok) {
      statusEl.innerHTML = '<span class="badge bg-danger">Error</span>';
      resultEl.innerHTML = '<p class="text-danger p-3 mb-0">' + mcEsc(json.error || 'Unknown error') + '</p>';
      resultEl.classList.remove('d-none');
      return;
    }
    if (json.count === 0) {
      statusEl.innerHTML = '<span class="badge bg-success"><i class="bi bi-check-lg"></i> Clean</span>';
      resultEl.classList.add('d-none');
      return;
    }

    if (json.info_only) {
      statusEl.innerHTML = '<span class="badge bg-info text-dark"><i class="bi bi-info-circle"></i> Info</span>';
    } else {
      statusEl.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> '
                         + json.count + ' issue' + (json.count !== 1 ? 's' : '') + '</span>';
    }

    let html = '<div class="mc-result-inner">';
    if (json.fix_url) {
      html += '<a href="' + mcEsc(json.fix_url) + '" class="btn btn-sm btn-outline-primary mb-2">'
            + '<i class="bi bi-arrow-right-circle"></i> ' + mcEsc(json.fix_label || 'View') + '</a> ';
    }
    if (json.fix_action) {
      const fixIcon    = mcEsc(json.fix_icon || 'bi-link-45deg');
      const fixConfirm = mcEsc(json.fix_confirm || 'Apply this fix? This will modify database records.');
      html += '<button type="button" class="btn btn-sm btn-outline-success mb-2"'
            + ' onclick="applyFix(this)"'
            + ' data-check="' + mcEsc(row.dataset.check) + '"'
            + ' data-fix="' + mcEsc(json.fix_action) + '"'
            + ' data-confirm="' + fixConfirm + '">'
            + '<i class="bi ' + fixIcon + '"></i> ' + mcEsc(json.fix_label || 'Fix All') + '</button> ';
    }
    html += '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead><tr>';
    json.columns.forEach(c => { html += '<th>' + mcEsc(c) + '</th>'; });
    html += '</tr></thead><tbody>';
    json.items.forEach(cells => {
      html += '<tr>';
      cells.forEach((cell, idx) => {
        if (json.acct_id_col === idx || (json.txn_id_col === idx && json.acct_link_col != null)) {
          // hidden column — skip rendering
        } else if (json.txn_link_col === idx) {
          const links = String(cell ?? '').split(',').map(id => {
            id = id.trim();
            return '<a href="' + MC_BASE + '/transactions/search?ids=' + encodeURIComponent(id)
                 + '" target="_blank" rel="noopener">' + mcEsc(id) + '</a>';
          }).join(', ');
          html += '<td>' + links + '</td>';
        } else if (json.acct_link_col === idx) {
          const acctId = cells[json.acct_id_col] ?? '';
          const txnId  = json.txn_id_col != null ? (cells[json.txn_id_col] ?? '') : '';
          const url    = MC_BASE + '/accounts/register?id=' + encodeURIComponent(acctId)
                       + (txnId ? '&txn=' + encodeURIComponent(txnId) : '');
          html += '<td><a href="' + url + '" target="_blank" rel="noopener">'
                + mcEsc(String(cell ?? '')) + '</a></td>';
        } else {
          html += '<td>' + mcEsc(String(cell ?? '')) + '</td>';
        }
      });
      html += '</tr>';
    });
    html += '</tbody></table></div>';
    if (json.count > json.items.length) {
      html += '<p class="text-muted small mt-1 mb-0">Showing first '
            + json.items.length + ' of ' + json.count + ' results.</p>';
    }
    html += '</div>';

    resultEl.innerHTML = html;
    resultEl.classList.remove('d-none');
  })
  .catch(() => {
    btn.disabled = false;
    statusEl.innerHTML = '<span class="badge bg-danger">Network Error</span>';
  });
}

function runAllChecks() {
  const allBtn = document.getElementById('runAllBtn');
  allBtn.disabled = true;
  const rows     = [...document.querySelectorAll('#maintenanceChecks .maintenance-check')];
  const promises = rows.map(row => runCheck(row));
  Promise.all(promises).then(() => { allBtn.disabled = false; });
}

function runAllSecChecks() {
  const allBtn = document.getElementById('runAllSecBtn');
  allBtn.disabled = true;
  const rows     = [...document.querySelectorAll('#securityChecks .maintenance-check')];
  const promises = rows.map(row => runCheck(row));
  Promise.all(promises).then(() => { allBtn.disabled = false; });
}

function applyFix(btn) {
  const checkSlug    = btn.dataset.check;
  const fixAction    = btn.dataset.fix;
  const confirmMsg   = btn.dataset.confirm || 'Apply this fix? This will modify database records.';
  const originalHtml = btn.innerHTML;
  if (!confirm(confirmMsg)) return;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Applying…';
  fetch(MC_URL, {
    method: 'POST',
    body: new URLSearchParams({ csrf_token: MC_CSRF, check: fixAction })
  })
  .then(r => r.json())
  .then(json => {
    if (!json.ok) {
      showToast(json.error || 'Fix failed.', 'error');
      btn.disabled = false;
      btn.innerHTML = originalHtml;
      return;
    }
    showToast(json.message || 'Done.', 'success');
    const row = document.querySelector('.maintenance-check[data-check="' + checkSlug + '"]');
    if (row) runCheck(row);
  })
  .catch((e) => {
    console.error(e);
    showToast('Network error.', 'error');
    btn.disabled = false;
    btn.innerHTML = originalHtml;
  });
}

async function runPurgePreview(btn) {
  const resultEl = document.getElementById('purgeResult');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Scanning…';
  resultEl.style.display = 'none';

  try {
    const res  = await fetch('<?= BASE_PATH ?>/settings/purge_categories', {
      method: 'POST',
      body: new URLSearchParams({ csrf_token: MC_CSRF, action: 'preview' }),
    });
    const data = await res.json();

    if (!data.ok) {
      resultEl.innerHTML = '<p class="text-danger small mb-0">' + mcEsc(data.error || 'Error') + '</p>';
      resultEl.style.display = '';
      return;
    }

    if (data.items.length === 0) {
      resultEl.innerHTML = '<p class="text-success small mb-0"><i class="bi bi-check-circle"></i> Nothing to purge — no eligible deleted categories found.</p>';
      resultEl.style.display = '';
      return;
    }

    let rows = '';
    data.items.forEach(r => {
      rows += `<tr><td>${mcEsc(r.name)}</td><td><span class="badge bg-secondary">${mcEsc(r.type)}</span></td>`
            + `<td class="text-muted small">${r.parent ? mcEsc(r.parent) : '—'}</td></tr>`;
    });

    resultEl.innerHTML = `
      <p class="small mb-2"><strong>${data.items.length}</strong> categor${data.items.length === 1 ? 'y' : 'ies'} eligible for permanent deletion:</p>
      <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered mb-0">
          <thead class="table-light"><tr><th>Name</th><th>Type</th><th>Parent</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-danger" onclick="runPurgeConfirm(this)">
        <i class="bi bi-trash3"></i> Permanently Delete ${data.items.length} Categor${data.items.length === 1 ? 'y' : 'ies'}
      </button>`;
    resultEl.style.display = '';

  } catch (e) {
    console.error(e);
    resultEl.innerHTML = '<p class="text-danger small mb-0">Network error.</p>';
    resultEl.style.display = '';
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-search"></i> Preview';
  }
}

async function runPurgeConfirm(btn) {
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Purging…';

  try {
    const res  = await fetch('<?= BASE_PATH ?>/settings/purge_categories', {
      method: 'POST',
      body: new URLSearchParams({ csrf_token: MC_CSRF, action: 'purge' }),
    });
    const data = await res.json();

    const resultEl = document.getElementById('purgeResult');
    if (!data.ok) {
      resultEl.innerHTML = '<p class="text-danger small mb-0">' + mcEsc(data.error || 'Purge failed.') + '</p>';
      return;
    }
    resultEl.innerHTML = `<p class="text-success small mb-0"><i class="bi bi-check-circle"></i> `
      + `Permanently deleted <strong>${data.deleted}</strong> categor${data.deleted === 1 ? 'y' : 'ies'}.</p>`;
  } catch (e) {
    console.error(e);
    document.getElementById('purgeResult').innerHTML = '<p class="text-danger small mb-0">Network error.</p>';
  }
}

async function runDbOp(op, btn) {
  document.querySelectorAll('.dbm-btn').forEach(b => { b.disabled = true; });
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Running…';

  const resultEl  = document.getElementById('dbmResult');
  const titleEl   = document.getElementById('dbmResultTitle');
  const bodyEl    = document.getElementById('dbmResultBody');
  const summaryEl = document.getElementById('dbmResultSummary');
  resultEl.style.display = 'none';

  try {
    const res  = await fetch('<?= BASE_PATH ?>/settings/db_maintenance', {
      method: 'POST',
      body: new URLSearchParams({ csrf_token: MC_CSRF, action: op }),
    });
    const data = await res.json();

    if (!data.ok) {
      if (data.status === 'backup_dir_missing') {
        let msg = '<i class="bi bi-exclamation-triangle-fill" style="color:#e6a817"></i> '
                + '<strong>Backup location is not configured.</strong> Optimize was not run.';
        if (data.can_configure_backup_dir && data.backup_settings_url) {
          msg += ' <a href="' + mcEsc(data.backup_settings_url) + '">Configure backup location</a>';
        }
        showToast(msg, 'warning');
        return;
      }
      showToast(data.error || 'Operation failed.', 'error');
      return;
    }

    const opLabel = { CHECK: 'Check', ANALYZE: 'Analyze', OPTIMIZE: 'Optimize' }[data.op] ?? data.op;
    titleEl.textContent = opLabel + ' Tables — Results';
    bodyEl.innerHTML = '';

    if (data.backup_file) {
      showToast('Safety backup created (' + data.backup_file + ') before optimizing.', 'success');
    }

    let okCount = 0, warnCount = 0, errCount = 0;
    data.rows.forEach(r => {
      const tr     = document.createElement('tr');
      const isErr  = r.msg_type === 'error';
      const isWarn = r.msg_type === 'warning';
      const isOk   = r.msg_type === 'status' && (r.msg_text.toLowerCase() === 'ok' || r.msg_text.startsWith('Rebuilt'));
      if (isErr) errCount++;
      else if (isWarn) warnCount++;
      else okCount++;

      const badge = isErr  ? '<span class="badge bg-danger">error</span>'
                  : isWarn ? '<span class="badge bg-warning text-dark">warning</span>'
                  : isOk   ? '<span class="badge bg-success">ok</span>'
                           : '<span class="badge bg-secondary">' + mcEsc(r.msg_type) + '</span>';

      tr.innerHTML = `<td class="font-monospace small">${mcEsc(r.table)}</td>`
                   + `<td>${badge}</td>`
                   + `<td class="small text-muted">${mcEsc(r.msg_text)}</td>`;
      bodyEl.appendChild(tr);
    });

    const total = data.rows.length;
    const parts = [];
    if (okCount)   parts.push(okCount + ' ok');
    if (warnCount) parts.push(warnCount + ' warning' + (warnCount !== 1 ? 's' : ''));
    if (errCount)  parts.push(errCount + ' error' + (errCount !== 1 ? 's' : ''));
    summaryEl.textContent = total + ' table' + (total !== 1 ? 's' : '') + ' processed — ' + parts.join(', ') + '.';

    resultEl.style.display = '';
    resultEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

  } catch (e) {
    console.error(e);
    showToast('Network error — operation may not have completed.', 'error');
  } finally {
    document.querySelectorAll('.dbm-btn').forEach(b => { b.disabled = false; });
    btn.innerHTML = '<i class="bi bi-play"></i> Run';
  }
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
