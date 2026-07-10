<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statement Converter — Steward</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f5f6fa; }
        .wizard-card { max-width: 980px; margin: 0 auto; }

        /* Step indicators */
        .steps { display: flex; gap: 0; margin-bottom: 2rem; }
        .step-item { flex: 1; text-align: center; position: relative; }
        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 18px; left: 60%; width: 80%;
            height: 2px; background: #dee2e6; z-index: 0;
        }
        .step-item.done::after  { background: #0d6efd; }
        .step-circle {
            width: 36px; height: 36px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 600; font-size: .85rem;
            background: #dee2e6; color: #6c757d;
            position: relative; z-index: 1;
            transition: background .2s, color .2s;
        }
        .step-item.active .step-circle { background: #0d6efd; color: #fff; }
        .step-item.done   .step-circle { background: #0d6efd; color: #fff; }
        .step-label { font-size: .78rem; color: #6c757d; margin-top: .3rem; }
        .step-item.active .step-label { color: #0d6efd; font-weight: 600; }

        /* Drop zone */
        #drop-zone {
            border: 2px dashed #0d6efd; border-radius: 12px;
            padding: 3rem 2rem; text-align: center; cursor: pointer;
            transition: background .15s;
        }
        #drop-zone.drag-over { background: #e7f1ff; }
        #drop-zone .bi { font-size: 2.5rem; color: #0d6efd; }

        /* Preview table */
        .preview-table-wrap { max-height: 460px; overflow-y: auto; }
        .table-sm td, .table-sm th { font-size: .82rem; }
        .row-issue    { background: #fff3cd !important; }
        .row-excluded { opacity: .4; }
        .badge-issue  { font-size: .7rem; }
        .desc-cell { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* Action mapping table */
        .action-map-table td, .action-map-table th { font-size: .82rem; }

        /* Summary chips */
        .summary-chip {
            display: inline-flex; align-items: center; gap: .4rem;
            background: #fff; border: 1px solid #dee2e6; border-radius: 8px;
            padding: .4rem .8rem; font-size: .85rem;
        }
        .summary-chip .val { font-weight: 700; font-size: 1.1rem; }

        /* Broker/type badges */
        .broker-badge { font-size: 1rem; font-weight: 600; }
    </style>
</head>
<body>
<div class="container py-4 py-md-5 wizard-card">

    <!-- Header -->
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-file-earmark-bar-graph fs-3 text-primary"></i>
        <div>
            <h5 class="mb-0 fw-bold">Statement Converter</h5>
            <small class="text-muted">Convert broker statements to Steward format</small>
        </div>
        <button class="btn btn-sm btn-outline-secondary ms-auto" id="btn-restart" style="display:none">
            <i class="bi bi-arrow-counterclockwise"></i> Start Over
        </button>
    </div>

    <!-- Step indicators -->
    <div class="steps mb-4">
        <div class="step-item active" id="si-1">
            <div class="step-circle">1</div>
            <div class="step-label">Upload</div>
        </div>
        <div class="step-item" id="si-2">
            <div class="step-circle">2</div>
            <div class="step-label">Review</div>
        </div>
        <div class="step-item" id="si-3">
            <div class="step-circle">3</div>
            <div class="step-label">Preview</div>
        </div>
        <div class="step-item" id="si-4">
            <div class="step-circle">4</div>
            <div class="step-label">Export</div>
        </div>
    </div>

    <!-- ── STEP 1: Upload ──────────────────────────────────────────────────── -->
    <div id="step-1" class="card shadow-sm">
        <div class="card-body p-4">
            <div id="drop-zone" role="button" tabindex="0">
                <i class="bi bi-cloud-upload d-block mb-2"></i>
                <div class="fw-semibold fs-5 mb-1">Drop your CSV file here</div>
                <div class="text-muted small mb-3">or click to browse</div>
                <input type="file" id="file-input" accept=".csv,text/csv" class="d-none">
                <div class="text-muted" style="font-size:.8rem">
                    Supported: <strong>Fidelity</strong>, <strong>Merrill Edge</strong>
                    &nbsp;·&nbsp; Holdings &amp; Transaction History
                </div>
            </div>
            <div id="upload-error" class="alert alert-danger mt-3 d-none"></div>
            <div id="upload-progress" class="mt-3 d-none">
                <div class="d-flex align-items-center gap-2 text-muted">
                    <div class="spinner-border spinner-border-sm"></div> Parsing file…
                </div>
            </div>
        </div>
    </div>

    <!-- ── STEP 2: Review ─────────────────────────────────────────────────── -->
    <div id="step-2" class="card shadow-sm d-none">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-3">Review Detection</h6>

            <div class="row g-3 mb-3">
                <div class="col-sm-4">
                    <label class="form-label small text-muted mb-1">Broker detected</label>
                    <div id="broker-display" class="broker-badge"></div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label small text-muted mb-1">Statement type</label>
                    <div id="type-display"></div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label small text-muted mb-1">
                        Statement date
                        <span id="date-source-badge" class="badge bg-secondary ms-1" style="font-size:.7rem"></span>
                    </label>
                    <input type="date" id="date-input" class="form-control form-control-sm">
                </div>
            </div>

            <label class="form-label small text-muted mb-1">Accounts found</label>
            <div id="accounts-list" class="mb-3"></div>

            <!-- Account override — single-account history files only -->
            <div id="account-override-section" class="d-none mb-3">
                <div class="alert alert-info py-2 mb-2 small">
                    <i class="bi bi-info-circle me-1"></i>
                    Single-account file — no account number detected. Optionally enter one to pre-fill all transactions.
                </div>
                <div class="row g-2">
                    <div class="col-sm-4">
                        <label class="form-label small mb-1">Account Number</label>
                        <input type="text" id="override-acct-number" class="form-control form-control-sm" placeholder="e.g. Z12345678">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small mb-1">Account Name</label>
                        <input type="text" id="override-acct-name" class="form-control form-control-sm" placeholder="e.g. Individual">
                    </div>
                </div>
            </div>

            <!-- Action type mappings — history only -->
            <div id="action-mappings-section" class="d-none mb-3">
                <label class="form-label small text-muted mb-1">Action Type Mappings</label>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered action-map-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Source Action</th>
                                <th style="width:60px" class="text-center">Count</th>
                                <th style="width:200px">Steward Code</th>
                            </tr>
                        </thead>
                        <tbody id="action-map-tbody"></tbody>
                    </table>
                </div>
            </div>

            <div id="review-warnings" class="d-none mb-3"></div>

            <button class="btn btn-primary" id="btn-to-preview">
                <span id="btn-preview-label">Preview</span> <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </div>
    </div>

    <!-- ── STEP 3: Preview ────────────────────────────────────────────────── -->
    <div id="step-3" class="card shadow-sm d-none">
        <div class="card-body p-4">
            <div class="d-flex align-items-center mb-3 gap-3 flex-wrap">
                <h6 class="fw-bold mb-0" id="preview-title">Preview</h6>
                <div id="preview-chips" class="d-flex gap-2 flex-wrap"></div>
                <button class="btn btn-sm btn-outline-secondary ms-auto" id="btn-back-review">
                    <i class="bi bi-arrow-left"></i> Back
                </button>
            </div>

            <div id="issues-banner" class="alert alert-warning py-2 d-none mb-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <span id="issues-text"></span>
                <span class="text-muted small ms-2">Highlighted rows have issues. Uncheck to exclude from export.</span>
            </div>

            <!-- Holdings table -->
            <div id="holdings-preview-wrap" class="preview-table-wrap d-none">
                <table class="table table-sm table-bordered table-hover align-middle mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th style="width:30px"><input type="checkbox" id="chk-all-h" title="Select all" checked></th>
                            <th>Account</th>
                            <th>Symbol</th>
                            <th>CUSIP</th>
                            <th>Description</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Last Price</th>
                            <th class="text-end">Avg Cost</th>
                            <th class="text-end">Total Cost</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="holdings-tbody"></tbody>
                </table>
            </div>

            <!-- History / Transactions table -->
            <div id="history-preview-wrap" class="preview-table-wrap d-none">
                <table class="table table-sm table-bordered table-hover align-middle mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th style="width:30px"><input type="checkbox" id="chk-all-t" title="Select all" checked></th>
                            <th style="white-space:nowrap">Date</th>
                            <th>Account</th>
                            <th>Action</th>
                            <th>Symbol</th>
                            <th>Description</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Price</th>
                            <th class="text-end">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="history-tbody"></tbody>
                </table>
            </div>

            <div class="mt-3 d-flex justify-content-end">
                <button class="btn btn-primary" id="btn-to-export">
                    Proceed to Export <i class="bi bi-arrow-right ms-1"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ── STEP 4: Export ─────────────────────────────────────────────────── -->
    <div id="step-4" class="card shadow-sm d-none">
        <div class="card-body p-4 text-center">
            <i class="bi bi-check-circle-fill text-success fs-1 mb-3 d-block"></i>
            <h5 class="fw-bold mb-1">Ready to Export</h5>
            <p class="text-muted mb-4" id="export-summary"></p>

            <div class="d-flex gap-2 justify-content-center flex-wrap">
                <button class="btn btn-success btn-lg" id="btn-download">
                    <i class="bi bi-download me-2"></i>
                    <span id="btn-download-label">Download CSV</span>
                </button>
                <button class="btn btn-primary btn-lg" id="btn-send-to-steward">
                    <i class="bi bi-box-arrow-in-right me-2"></i>
                    <span id="btn-send-label">Send to Steward</span>
                </button>
                <button class="btn btn-outline-secondary btn-lg" id="btn-restart-2">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Convert Another
                </button>
            </div>

            <div id="export-error" class="alert alert-danger mt-3 d-none"></div>
        </div>
    </div>

</div><!-- /container -->

<script>
// ─────────────────────────────────────────────────────────────────────────────
// Constants
// ─────────────────────────────────────────────────────────────────────────────
const MM_CODES = [
    'Buy','Sell','Div','ReinvDiv','IntInc',
    'CGLong','CGShort','ShrsIn','ShrsOut',
    'Cash','StkSplit','MiscInc','MiscExp',
];

const ACTION_BADGE = {
    Buy:     ['success', false],
    Sell:    ['danger',  false],
    Div:     ['primary', false],
    ReinvDiv:['info',    false],
    IntInc:  ['primary', false],
    CGLong:  ['secondary', false],
    CGShort: ['secondary', false],
    ShrsIn:  ['secondary', false],
    ShrsOut: ['secondary', false],
    Cash:    ['warning', true],
    StkSplit:['secondary', false],
    MiscInc: ['primary', false],
    MiscExp: ['danger',  false],
};

function actionBadgeHtml(code, rawAction) {
    if (!code) {
        return `<span class="badge bg-warning text-dark" title="${esc(rawAction || '')}">?</span>`;
    }
    const [bg, dark] = ACTION_BADGE[code] || ['secondary', false];
    return `<span class="badge bg-${bg}${dark ? ' text-dark' : ''}">${esc(code)}</span>`;
}

// ─────────────────────────────────────────────────────────────────────────────
// State
// ─────────────────────────────────────────────────────────────────────────────
const state = {
    step: 1,
    broker: '',
    stmtType: 'holdings',   // 'holdings' | 'history'
    date: '',
    dateSource: '',
    accounts: [],
    holdings: [],           // [{ ...fields, included: true }]
    transactions: [],       // [{ ...fields, included: true }]
    actionMappings: {},     // { rawAction: mmCode }
    accountOverride: { number: '', name: '' },
    warnings: [],
};

// ─────────────────────────────────────────────────────────────────────────────
// Step navigation
// ─────────────────────────────────────────────────────────────────────────────
function goStep(n) {
    state.step = n;
    for (let i = 1; i <= 4; i++) {
        document.getElementById(`step-${i}`).classList.toggle('d-none', i !== n);
        const si = document.getElementById(`si-${i}`);
        si.classList.toggle('active', i === n);
        si.classList.toggle('done', i < n);
    }
    document.getElementById('btn-restart').style.display = n > 1 ? '' : 'none';
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 1 — Upload
// ─────────────────────────────────────────────────────────────────────────────
const dropZone  = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');

dropZone.addEventListener('click',    () => fileInput.click());
dropZone.addEventListener('keydown',  e => { if (e.key === 'Enter' || e.key === ' ') fileInput.click(); });
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave',() => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop',     e => {
    e.preventDefault(); dropZone.classList.remove('drag-over');
    handleFile(e.dataTransfer.files[0]);
});
fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));

function handleFile(file) {
    if (!file) return;
    document.getElementById('upload-error').classList.add('d-none');
    document.getElementById('upload-progress').classList.remove('d-none');

    const fd = new FormData();
    fd.append('file', file);

    fetch('api/upload.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            document.getElementById('upload-progress').classList.add('d-none');
            if (!data.success) throw new Error(data.error || 'Parse failed.');
            applyUploadResult(data);
        })
        .catch(err => {
            document.getElementById('upload-progress').classList.add('d-none');
            showUploadError(err.message);
        });
}

function showUploadError(msg) {
    const el = document.getElementById('upload-error');
    el.textContent = msg;
    el.classList.remove('d-none');
}

function applyUploadResult(data) {
    state.broker          = data.broker;
    state.stmtType        = data.type;
    state.date            = data.date;
    state.dateSource      = data.dateSource;
    state.accounts        = data.accounts;
    state.warnings        = data.warnings ?? [];
    state.accountOverride = { number: '', name: '' };

    if (data.type === 'history') {
        state.transactions = (data.transactions ?? []).map(t => ({ ...t, included: true }));
        state.holdings     = [];
        state.actionMappings = {};
        for (const t of state.transactions) {
            const key = t.raw_action || '';
            if (!(key in state.actionMappings)) {
                state.actionMappings[key] = t.action_type || '';
            }
        }
    } else {
        state.holdings     = (data.holdings ?? []).map(h => ({ ...h, included: true }));
        state.transactions = [];
        state.actionMappings = {};
    }

    renderReview();
    goStep(2);
}

// ─────────────────────────────────────────────────────────────────────────────
// Step 2 — Review
// ─────────────────────────────────────────────────────────────────────────────
function renderReview() {
    const isHistory = state.stmtType === 'history';

    // Broker
    const brokerLabels = { fidelity: 'Fidelity', merrill: 'Merrill Edge' };
    const brokerIcons  = { fidelity: 'bi-building', merrill: 'bi-building-check' };
    document.getElementById('broker-display').innerHTML =
        `<span class="badge bg-primary fs-6">` +
        `<i class="bi ${brokerIcons[state.broker] || 'bi-question'} me-1"></i>` +
        `${esc(brokerLabels[state.broker] || state.broker)}</span>`;

    // Statement type
    document.getElementById('type-display').innerHTML = isHistory
        ? '<span class="badge bg-info text-dark fs-6"><i class="bi bi-clock-history me-1"></i>Transaction History</span>'
        : '<span class="badge bg-secondary fs-6"><i class="bi bi-bar-chart me-1"></i>Holdings</span>';

    // Date
    document.getElementById('date-input').value = state.date;
    const srcBadge = document.getElementById('date-source-badge');
    if (state.dateSource === 'file') {
        srcBadge.textContent = 'from file';
        srcBadge.className   = 'badge bg-success ms-1';
    } else {
        srcBadge.textContent = 'today (not in file)';
        srcBadge.className   = 'badge bg-warning text-dark ms-1';
    }

    // Accounts
    const acctList = document.getElementById('accounts-list');
    if (state.accounts.length === 0) {
        acctList.innerHTML = isHistory
            ? '<span class="text-muted small">No accounts detected — see override below.</span>'
            : '<span class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>No accounts detected.</span>';
    } else {
        acctList.innerHTML = state.accounts.map(a =>
            `<div class="d-inline-flex align-items-center gap-2 me-2 mb-2 summary-chip">
                <i class="bi bi-person-circle text-primary"></i>
                <span><strong>${esc(a.number)}</strong>${a.name && a.name !== a.number ? ' — ' + esc(a.name) : ''}</span>
            </div>`
        ).join('');
    }

    // Account override (single-account history only)
    document.getElementById('account-override-section')
        .classList.toggle('d-none', !(isHistory && state.accounts.length === 0));

    // Action mappings (history only)
    const mappingsSec = document.getElementById('action-mappings-section');
    mappingsSec.classList.toggle('d-none', !isHistory);
    if (isHistory) renderActionMappings();

    // Warnings
    const warnEl = document.getElementById('review-warnings');
    const allWarnings = [...state.warnings];
    if (!isHistory) {
        const issueCount = state.holdings.filter(h => h.issues && h.issues.length > 0).length;
        if (issueCount > 0) {
            allWarnings.push(
                `${issueCount} holding${issueCount !== 1 ? 's' : ''} have missing data ` +
                `(quantity or price). Review and exclude them in the next step.`
            );
        }
    }
    if (allWarnings.length > 0) {
        warnEl.className = 'alert alert-warning mb-3';
        warnEl.innerHTML = allWarnings
            .map(w => `<div><i class="bi bi-exclamation-triangle me-1"></i>${esc(w)}</div>`)
            .join('');
    } else {
        warnEl.className = 'd-none';
        warnEl.innerHTML = '';
    }

    // Button label
    document.getElementById('btn-preview-label').textContent =
        isHistory ? 'Preview Transactions' : 'Preview Holdings';
}

function renderActionMappings() {
    const tbody = document.getElementById('action-map-tbody');
    tbody.innerHTML = '';

    const counts = {};
    for (const t of state.transactions) {
        const k = t.raw_action || '';
        counts[k] = (counts[k] || 0) + 1;
    }

    const rawActions = Object.keys(counts);

    rawActions.forEach((rawAction, idx) => {
        const count       = counts[rawAction];
        const currentCode = state.actionMappings[rawAction] ?? '';
        const isUnmapped  = currentCode === '';

        const opts = MM_CODES.map(c =>
            `<option value="${esc(c)}"${c === currentCode ? ' selected' : ''}>${esc(c)}</option>`
        ).join('');

        const tr = document.createElement('tr');
        if (isUnmapped) tr.classList.add('table-warning');
        tr.innerHTML = `
            <td class="small">
                ${esc(rawAction || '(empty)')}
                ${isUnmapped ? ' <span class="badge bg-warning text-dark unmapped-badge" style="font-size:.65rem">unmapped</span>' : ''}
            </td>
            <td class="text-center small">${count}</td>
            <td>
                <select class="form-select form-select-sm" data-idx="${idx}">
                    <option value="">(skip / exclude)</option>
                    ${opts}
                </select>
            </td>
        `;
        tbody.appendChild(tr);

        tr.querySelector('select').addEventListener('change', function () {
            state.actionMappings[rawActions[parseInt(this.dataset.idx)]] = this.value;
            this.closest('tr').classList.toggle('table-warning', this.value === '');
            const badge = this.closest('tr').querySelector('.unmapped-badge');
            if (badge) badge.style.display = this.value === '' ? '' : 'none';
        });
    });
}

document.getElementById('btn-to-preview').addEventListener('click', () => {
    state.date = document.getElementById('date-input').value;

    // Save account override for single-account history
    if (state.stmtType === 'history' && state.accounts.length === 0) {
        state.accountOverride = {
            number: document.getElementById('override-acct-number').value.trim(),
            name:   document.getElementById('override-acct-name').value.trim(),
        };
    }

    if (state.stmtType === 'history') {
        renderPreviewHistory();
    } else {
        renderPreviewHoldings();
    }
    goStep(3);
});

// ─────────────────────────────────────────────────────────────────────────────
// Step 3 — Preview
// ─────────────────────────────────────────────────────────────────────────────
document.getElementById('btn-back-review').addEventListener('click', () => goStep(2));

function renderPreviewHoldings() {
    document.getElementById('preview-title').textContent = 'Holdings Preview';
    document.getElementById('holdings-preview-wrap').classList.remove('d-none');
    document.getElementById('history-preview-wrap').classList.add('d-none');

    const tbody  = document.getElementById('holdings-tbody');
    tbody.innerHTML = '';

    const total    = state.holdings.length;
    const issues   = state.holdings.filter(h => h.issues && h.issues.length > 0).length;
    const accounts = new Set(state.holdings.map(h => h.account_number)).size;
    const noCost   = state.holdings.filter(h => !h.avg_cost_basis && !h.total_cost_basis).length;

    document.getElementById('preview-chips').innerHTML = [
        chip(total, 'holdings', 'bi-list-ul', 'primary'),
        chip(accounts, accounts !== 1 ? 'accounts' : 'account', 'bi-person', 'info'),
        issues > 0 ? chip(issues, 'with issues',   'bi-exclamation-triangle', 'warning') : '',
        noCost  > 0 ? chip(noCost,  'no cost basis', 'bi-dash-circle', 'secondary') : '',
    ].join('');

    const banner = document.getElementById('issues-banner');
    if (issues > 0) {
        document.getElementById('issues-text').textContent = `${issues} row${issues !== 1 ? 's' : ''} flagged.`;
        banner.classList.remove('d-none');
    } else {
        banner.classList.add('d-none');
    }

    state.holdings.forEach((h, idx) => {
        const hasIssue = h.issues && h.issues.length > 0;
        const tr = document.createElement('tr');
        tr.dataset.idx = idx;
        if (hasIssue)    tr.classList.add('row-issue');
        if (!h.included) tr.classList.add('row-excluded');

        const fmt    = v => v ? esc(v) : '<span class="text-muted">—</span>';
        const fmtNum = v => v ? `<span class="font-monospace">${esc(v)}</span>` : '<span class="text-muted">—</span>';

        tr.innerHTML = `
            <td><input type="checkbox" class="row-chk-h" data-idx="${idx}" ${h.included ? 'checked' : ''}></td>
            <td class="text-nowrap">${fmt(h.account_number)}</td>
            <td class="text-nowrap fw-semibold">${fmt(h.symbol)}</td>
            <td class="text-nowrap text-muted small">${fmt(h.cusip)}</td>
            <td class="small">${fmt(h.description)}</td>
            <td class="text-end">${fmtNum(h.quantity)}</td>
            <td class="text-end">${fmtNum(h.last_price)}</td>
            <td class="text-end">${fmtNum(h.avg_cost_basis)}</td>
            <td class="text-end">${fmtNum(h.total_cost_basis)}</td>
            <td>${hasIssue ? `<span class="badge bg-warning text-dark badge-issue" title="${esc(h.issues.join(', '))}">!</span>` : ''}</td>
        `;
        tbody.appendChild(tr);
    });

    tbody.querySelectorAll('.row-chk-h').forEach(chk => {
        chk.addEventListener('change', () => {
            const idx = parseInt(chk.dataset.idx);
            state.holdings[idx].included = chk.checked;
            chk.closest('tr').classList.toggle('row-excluded', !chk.checked);
            syncSelectAll();
        });
    });

    syncSelectAll();
}

function renderPreviewHistory() {
    document.getElementById('preview-title').textContent = 'Transactions Preview';
    document.getElementById('history-preview-wrap').classList.remove('d-none');
    document.getElementById('holdings-preview-wrap').classList.add('d-none');

    const tbody = document.getElementById('history-tbody');
    tbody.innerHTML = '';

    let issueCount = 0;
    const acctSet  = new Set();
    const ov = state.accountOverride;

    state.transactions.forEach((t, idx) => {
        const effectiveAction = state.actionMappings[t.raw_action] !== undefined
            ? state.actionMappings[t.raw_action]
            : t.action_type;

        const acctNum  = t.account_number || ov.number || ov.name || '';
        const hasIssue = (t.issues && t.issues.length > 0) || effectiveAction === '';
        if (hasIssue) issueCount++;
        if (acctNum) acctSet.add(acctNum);

        const tr = document.createElement('tr');
        tr.dataset.idx = idx;
        if (hasIssue)    tr.classList.add('row-issue');
        if (!t.included) tr.classList.add('row-excluded');

        const fmt    = v => v ? esc(v) : '<span class="text-muted">—</span>';
        const fmtNum = v => v ? `<span class="font-monospace">${esc(v)}</span>` : '<span class="text-muted">—</span>';

        const issueTitle = hasIssue
            ? esc([...(t.issues || []), effectiveAction === '' ? `unrecognized action: ${t.raw_action}` : ''].filter(Boolean).join(', '))
            : '';

        tr.innerHTML = `
            <td><input type="checkbox" class="row-chk-t" data-idx="${idx}" ${t.included ? 'checked' : ''}></td>
            <td class="text-nowrap small">${fmt(t.date)}</td>
            <td class="small text-nowrap">${fmt(acctNum)}</td>
            <td>${actionBadgeHtml(effectiveAction, t.raw_action)}</td>
            <td class="text-nowrap fw-semibold small">${fmt(t.symbol)}</td>
            <td class="small desc-cell" title="${esc(t.description)}">${fmt(t.description)}</td>
            <td class="text-end">${fmtNum(t.quantity)}</td>
            <td class="text-end">${fmtNum(t.price)}</td>
            <td class="text-end">${fmtNum(t.amount)}</td>
            <td>${hasIssue ? `<span class="badge bg-warning text-dark badge-issue" title="${issueTitle}">!</span>` : ''}</td>
        `;
        tbody.appendChild(tr);
    });

    const total = state.transactions.length;
    document.getElementById('preview-chips').innerHTML = [
        chip(total, total !== 1 ? 'transactions' : 'transaction', 'bi-list-ul', 'primary'),
        acctSet.size > 0 ? chip(acctSet.size, acctSet.size !== 1 ? 'accounts' : 'account', 'bi-person', 'info') : '',
        issueCount > 0 ? chip(issueCount, 'with issues', 'bi-exclamation-triangle', 'warning') : '',
    ].join('');

    const banner = document.getElementById('issues-banner');
    if (issueCount > 0) {
        document.getElementById('issues-text').textContent = `${issueCount} row${issueCount !== 1 ? 's' : ''} flagged.`;
        banner.classList.remove('d-none');
    } else {
        banner.classList.add('d-none');
    }

    tbody.querySelectorAll('.row-chk-t').forEach(chk => {
        chk.addEventListener('change', () => {
            const idx = parseInt(chk.dataset.idx);
            state.transactions[idx].included = chk.checked;
            chk.closest('tr').classList.toggle('row-excluded', !chk.checked);
            syncSelectAll();
        });
    });

    syncSelectAll();
}

document.getElementById('chk-all-h').addEventListener('change', function () {
    state.holdings.forEach(h => h.included = this.checked);
    document.querySelectorAll('.row-chk-h').forEach(chk => chk.checked = this.checked);
    document.querySelectorAll('#holdings-tbody tr').forEach(tr => tr.classList.toggle('row-excluded', !this.checked));
});

document.getElementById('chk-all-t').addEventListener('change', function () {
    state.transactions.forEach(t => t.included = this.checked);
    document.querySelectorAll('.row-chk-t').forEach(chk => chk.checked = this.checked);
    document.querySelectorAll('#history-tbody tr').forEach(tr => tr.classList.toggle('row-excluded', !this.checked));
});

function syncSelectAll() {
    if (state.stmtType === 'history') {
        const all = state.transactions.length;
        const on  = state.transactions.filter(t => t.included).length;
        const el  = document.getElementById('chk-all-t');
        el.checked       = on === all;
        el.indeterminate = on > 0 && on < all;
    } else {
        const all = state.holdings.length;
        const on  = state.holdings.filter(h => h.included).length;
        const el  = document.getElementById('chk-all-h');
        el.checked       = on === all;
        el.indeterminate = on > 0 && on < all;
    }
}

document.getElementById('btn-to-export').addEventListener('click', () => {
    if (state.stmtType === 'history') {
        if (!state.transactions.some(t => t.included)) {
            alert('No transactions selected for export. Please check at least one row.');
            return;
        }
    } else {
        if (!state.holdings.some(h => h.included)) {
            alert('No holdings selected for export. Please check at least one row.');
            return;
        }
    }
    renderExport();
    goStep(4);
});

// ─────────────────────────────────────────────────────────────────────────────
// Step 4 — Export
// ─────────────────────────────────────────────────────────────────────────────
function renderExport() {
    const ov = state.accountOverride;
    if (state.stmtType === 'history') {
        const included = state.transactions.filter(t => t.included);
        const accts    = new Set(included.map(t => t.account_number || ov.number || ov.name || '').filter(Boolean)).size;
        document.getElementById('export-summary').textContent =
            `${included.length} transaction${included.length !== 1 ? 's' : ''} across ` +
            `${accts || '?'} account${accts !== 1 ? 's' : ''} — statement date ${state.date}`;
        document.getElementById('btn-download-label').textContent = 'Download Steward History CSV';
    } else {
        const included = state.holdings.filter(h => h.included);
        const accts    = new Set(included.map(h => h.account_number)).size;
        document.getElementById('export-summary').textContent =
            `${included.length} holding${included.length !== 1 ? 's' : ''} across ` +
            `${accts} account${accts !== 1 ? 's' : ''} — statement date ${state.date}`;
        document.getElementById('btn-download-label').textContent = 'Download Steward Holdings CSV';
    }
}

function buildExportPayload() {
    const ov = state.accountOverride;
    if (state.stmtType === 'history') {
        const transactions = state.transactions.filter(t => t.included).map(t => {
            const effectiveAction = state.actionMappings[t.raw_action] !== undefined
                ? state.actionMappings[t.raw_action]
                : t.action_type;
            return { ...t,
                action_type:    effectiveAction,
                account_number: t.account_number || ov.number || ov.name || '',
                account_name:   t.account_name   || ov.name   || ov.number || '',
            };
        });
        return { type: 'history', transactions };
    }
    return { type: 'holdings', date: state.date, holdings: state.holdings.filter(h => h.included) };
}

document.getElementById('btn-download').addEventListener('click', () => {
    const errEl = document.getElementById('export-error');
    errEl.classList.add('d-none');

    const payload  = buildExportPayload();
    const filename = payload.type === 'history'
        ? `mm_history_${state.date.replace(/-/g, '')}.csv`
        : `mm_holdings_${state.date.replace(/-/g, '')}.csv`;

    fetch('api/export.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
    })
    .then(async res => {
        if (!res.ok) {
            const j = await res.json().catch(() => ({}));
            throw new Error(j.error || 'Export failed.');
        }
        return res.blob();
    })
    .then(blob => {
        const url = URL.createObjectURL(blob);
        const a   = document.createElement('a');
        a.href     = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    })
    .catch(err => {
        errEl.textContent = err.message;
        errEl.classList.remove('d-none');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Send to Steward
// ─────────────────────────────────────────────────────────────────────────────
document.getElementById('btn-send-to-steward').addEventListener('click', () => {
    const errEl  = document.getElementById('export-error');
    const btnEl  = document.getElementById('btn-send-to-steward');
    const label  = document.getElementById('btn-send-label');
    errEl.classList.add('d-none');
    btnEl.disabled = true;
    label.textContent = 'Sending…';

    const payload = buildExportPayload();

    fetch('api/send_to_steward.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
    })
    .then(async res => {
        const j = await res.json();
        if (!res.ok || !j.success) throw new Error(j.error || 'Handoff failed.');
        window.location.href = j.url;
    })
    .catch(err => {
        errEl.textContent = err.message;
        errEl.classList.remove('d-none');
        btnEl.disabled = false;
        label.textContent = 'Send to Steward';
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Restart
// ─────────────────────────────────────────────────────────────────────────────
function restart() {
    Object.assign(state, {
        step: 1, broker: '', stmtType: 'holdings',
        date: '', dateSource: '', accounts: [],
        holdings: [], transactions: [], actionMappings: {},
        accountOverride: { number: '', name: '' },
        warnings: [],
    });
    fileInput.value = '';
    document.getElementById('upload-error').classList.add('d-none');
    document.getElementById('export-error').classList.add('d-none');
    document.getElementById('override-acct-number').value = '';
    document.getElementById('override-acct-name').value   = '';
    goStep(1);
}
document.getElementById('btn-restart').addEventListener('click', restart);
document.getElementById('btn-restart-2').addEventListener('click', restart);

// ─────────────────────────────────────────────────────────────────────────────
// Utility
// ─────────────────────────────────────────────────────────────────────────────
function chip(val, label, icon, color) {
    return `<span class="summary-chip">
        <i class="bi ${icon} text-${color}"></i>
        <span class="val">${val}</span> <span class="text-muted">${label}</span>
    </span>`;
}

function esc(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
</script>
</body>
</html>
