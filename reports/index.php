<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$pageTitle   = 'Reports';
$currentPage = 'reports';

$savedReports = getSavedCustomReports();
$csrfTok = csrfToken();

include __DIR__ . '/../includes/header.php';
?>
<style>
.sr-card-wrap { position: relative; }
.sr-remove-btn {
  position: absolute; top: 6px; right: 6px; z-index: 2;
  background: rgba(255,255,255,.92); border: 1px solid #d0d8e4;
  border-radius: 4px; cursor: pointer; color: #888;
  padding: 1px 7px; font-size: .75rem; line-height: 1.6;
  transition: background .15s, color .15s;
}
.sr-remove-btn:hover { background: #fff0f0; color: #c0392b; border-color: #f5c6c6; }
</style>

<div class="page-header">
  <h2><i class="bi bi-file-earmark-bar-graph"></i> Reports</h2>
</div>

<a href="<?= BASE_PATH ?>/reports/custom" class="report-card report-card-featured">
  <div class="report-icon"><i class="bi bi-sliders2"></i></div>
  <div class="report-info">
    <div class="report-title">Custom Report</div>
    <div class="report-desc">Build any report: choose row/column dimensions, metrics, filters, and chart type. Export as CSV or XLSX.</div>
  </div>
</a>

<p class="reports-section-heading">Investments</p>
<div class="reports-grid">

  <a href="<?= BASE_PATH ?>/reports/portfolio_performance" class="report-card">
    <div class="report-icon"><i class="bi bi-briefcase"></i></div>
    <div class="report-info">
      <div class="report-title">Portfolio Performance</div>
      <div class="report-desc">Unrealized gains, cost basis, and return % for all current holdings.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/asset_allocation" class="report-card">
    <div class="report-icon"><i class="bi bi-pie-chart-fill"></i></div>
    <div class="report-info">
      <div class="report-title">Asset Allocation</div>
      <div class="report-desc">Portfolio breakdown by asset type and account with donut charts.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/portfolio_snapshot" class="report-card">
    <div class="report-icon"><i class="bi bi-bar-chart-fill"></i></div>
    <div class="report-info">
      <div class="report-title">Portfolio Snapshot</div>
      <div class="report-desc">Top 10 holdings by market value as a donut chart, with account and type filters.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/capital_gains" class="report-card">
    <div class="report-icon"><i class="bi bi-cash-coin"></i></div>
    <div class="report-info">
      <div class="report-title">Capital Gains</div>
      <div class="report-desc">Realized gains and losses from sold securities using average cost method.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/portfolio_value_history" class="report-card">
    <div class="report-icon"><i class="bi bi-graph-up-arrow"></i></div>
    <div class="report-info">
      <div class="report-title">Portfolio Value History</div>
      <div class="report-desc">Portfolio value vs. amount invested over time, with annualized return per holding.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/investment_performance" class="report-card">
    <div class="report-icon"><i class="bi bi-activity"></i></div>
    <div class="report-info">
      <div class="report-title">Investment Performance</div>
      <div class="report-desc">Compare price performance of selected securities and indexes over a chosen date range.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/stock_exposure" class="report-card">
    <div class="report-icon"><i class="bi bi-layers"></i></div>
    <div class="report-info">
      <div class="report-title">Stock Exposure</div>
      <div class="report-desc">True effective exposure per stock — combines direct holdings with weighted ETF and fund constituents.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/sector_exposure" class="report-card">
    <div class="report-icon"><i class="bi bi-pie-chart-fill"></i></div>
    <div class="report-info">
      <div class="report-title">Sector Exposure</div>
      <div class="report-desc">GICS sector breakdown of your effective stock exposure — including holdings inside ETFs and funds.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/investment_income" class="report-card">
    <div class="report-icon"><i class="bi bi-cash-coin"></i></div>
    <div class="report-info">
      <div class="report-title">Investment Income</div>
      <div class="report-desc">Dividends, interest, and capital gain distributions — cash and reinvested — by type and by security.</div>
    </div>
  </a>

</div>

<p class="reports-section-heading">Net Worth</p>
<div class="reports-grid">

  <a href="<?= BASE_PATH ?>/reports/account_balances" class="report-card">
    <div class="report-icon"><i class="bi bi-wallet2"></i></div>
    <div class="report-info">
      <div class="report-title">Account Balances</div>
      <div class="report-desc">All account balances grouped by type — banking, credit, investments, retirement, and assets.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/account_balances_history" class="report-card">
    <div class="report-icon"><i class="bi bi-clock-history"></i></div>
    <div class="report-info">
      <div class="report-title">Account Balances History</div>
      <div class="report-desc">Year-end or month-end balances for selected accounts over a date range.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/net_worth" class="report-card">
    <div class="report-icon"><i class="bi bi-graph-up"></i></div>
    <div class="report-info">
      <div class="report-title">Net Worth Over Time</div>
      <div class="report-desc">Month-by-month net worth chart — assets minus liabilities.</div>
    </div>
  </a>

</div>

<p class="reports-section-heading">Debt</p>
<div class="reports-grid">

  <a href="<?= BASE_PATH ?>/reports/credit_card_debt" class="report-card">
    <div class="report-icon"><i class="bi bi-credit-card-2-back"></i></div>
    <div class="report-info">
      <div class="report-title">Credit Card Debt</div>
      <div class="report-desc">Current balances and monthly trend for selected credit card accounts.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/loan_amortization" class="report-card">
    <div class="report-icon"><i class="bi bi-calculator"></i></div>
    <div class="report-info">
      <div class="report-title">Loan Amortization</div>
      <div class="report-desc">Full payment schedule for any loan — principal vs. interest breakdown, payoff date, and matched payment history.</div>
    </div>
  </a>

</div>

<p class="reports-section-heading">Tax</p>
<div class="reports-grid">

  <a href="<?= BASE_PATH ?>/reports/tax_summary" class="report-card">
    <div class="report-icon"><i class="bi bi-receipt"></i></div>
    <div class="report-info">
      <div class="report-title">Tax Summary</div>
      <div class="report-desc">Income and deductible expenses for categories marked tax-related, by tax year.</div>
    </div>
  </a>

</div>

<p class="reports-section-heading">Goals &amp; Planning</p>
<div class="reports-grid">

  <a href="<?= BASE_PATH ?>/reports/savings_goals" class="report-card">
    <div class="report-icon"><i class="bi bi-piggy-bank"></i></div>
    <div class="report-info">
      <div class="report-title">Savings Goals</div>
      <div class="report-desc">Track progress toward each savings goal with progress bars, timelines, and monthly contribution targets.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/cash_flow_forecast" class="report-card">
    <div class="report-icon"><i class="bi bi-graph-up-arrow"></i></div>
    <div class="report-info">
      <div class="report-title">Cash Flow Forecast</div>
      <div class="report-desc">Project account balances forward based on scheduled bills and deposits — spot shortfalls before they happen.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/income_analysis" class="report-card">
    <div class="report-icon"><i class="bi bi-cash-stack"></i></div>
    <div class="report-info">
      <div class="report-title">Income Analysis</div>
      <div class="report-desc">Income breakdown by category and month — stacked chart, payee ranking, and monthly pivot table.</div>
    </div>
  </a>

</div>

<p class="reports-section-heading">Spending &amp; Cash Flow</p>
<div class="reports-grid">

  <a href="<?= BASE_PATH ?>/reports/income_expense" class="report-card">
    <div class="report-icon"><i class="bi bi-arrow-left-right"></i></div>
    <div class="report-info">
      <div class="report-title">Income vs. Expense</div>
      <div class="report-desc">Monthly and annual income/expense comparison with net cash flow.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/banking_income" class="report-card">
    <div class="report-icon"><i class="bi bi-piggy-bank"></i></div>
    <div class="report-info">
      <div class="report-title">Banking Income</div>
      <div class="report-desc">Interest and cash rewards from checking, savings, and CDs — by type, account, and payee.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/spending_category" class="report-card">
    <div class="report-icon"><i class="bi bi-pie-chart"></i></div>
    <div class="report-info">
      <div class="report-title">Spending by Category</div>
      <div class="report-desc">Category breakdown for any date range with trend bars.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/spending_history" class="report-card">
    <div class="report-icon"><i class="bi bi-clock-history"></i></div>
    <div class="report-info">
      <div class="report-title">Spending History</div>
      <div class="report-desc">Category spending over time — pivot table by month, quarter, or year with stacked bar chart.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/spending_trends" class="report-card">
    <div class="report-icon"><i class="bi bi-grid-3x3"></i></div>
    <div class="report-info">
      <div class="report-title">Spending Trends</div>
      <div class="report-desc">Category spending heatmap by month — spot seasonal patterns and outliers at a glance.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/cash_flow" class="report-card">
    <div class="report-icon"><i class="bi bi-water"></i></div>
    <div class="report-info">
      <div class="report-title">Cash Flow</div>
      <div class="report-desc">Monthly income, expenses, and net flow summary table.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/budget_vs_actual" class="report-card">
    <div class="report-icon"><i class="bi bi-bar-chart-line"></i></div>
    <div class="report-info">
      <div class="report-title">Budget vs. Actual</div>
      <div class="report-desc">Compare budgeted amounts to actual spending by category.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/payee_summary" class="report-card">
    <div class="report-icon"><i class="bi bi-shop"></i></div>
    <div class="report-info">
      <div class="report-title">Payee Summary</div>
      <div class="report-desc">Total spending per payee for a date range — see where money goes.</div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/reports/account_flow" class="report-card">
    <div class="report-icon"><i class="bi bi-arrow-down-up"></i></div>
    <div class="report-info">
      <div class="report-title">Account Flow</div>
      <div class="report-desc">Revenue, expenses, and transfers for a single account — see exactly where money enters and leaves.</div>
    </div>
  </a>

</div>

<p class="reports-section-heading">Accounts</p>
<div class="reports-grid">

  <a href="<?= BASE_PATH ?>/reports/reconciliation_status" class="report-card">
    <div class="report-icon"><i class="bi bi-check-circle"></i></div>
    <div class="report-info">
      <div class="report-title">Reconciliation Status</div>
      <div class="report-desc">Reconciliation health for all accounts — last reconciled date, uncleared items, and overdue alerts.</div>
    </div>
  </a>

</div>

<?php if (!empty($savedReports)): ?>
<p class="reports-section-heading">Saved Reports</p>
<div class="reports-grid" id="srGrid">
  <?php foreach ($savedReports as $sr): ?>
  <div class="sr-card-wrap" id="sr-wrap-<?= (int)$sr['id'] ?>">
    <a href="<?= BASE_PATH ?>/reports/saved/<?= (int)$sr['id'] ?>" class="report-card">
      <div class="report-icon"><i class="bi <?= h($sr['icon']) ?>"></i></div>
      <div class="report-info">
        <div class="report-title"><?= h($sr['title']) ?></div>
        <div class="report-desc text-muted" style="font-size:.78rem">Saved <?= date('M j, Y', strtotime($sr['created_at'])) ?></div>
      </div>
    </a>
    <button class="sr-remove-btn" title="Remove from saved reports"
            onclick="removeSavedReport(<?= (int)$sr['id'] ?>, this)">
      <i class="bi bi-x"></i>
    </button>
  </div>
  <?php endforeach; ?>
</div>
<script>
const SR_CSRF = <?= json_encode($csrfTok) ?>;
function removeSavedReport(id, btn) {
  appConfirm('Remove Report', 'Remove this report from your saved list?', null, () => {
    btn.disabled = true;
    fetch('<?= BASE_PATH ?>/reports/favorite_save', {
      method: 'POST',
      body: new URLSearchParams({ csrf_token: SR_CSRF, action: 'remove', id })
    })
    .then(r => r.json())
    .then(json => {
      if (!json.ok) { showToast(json.error || 'Error removing report.', 'error'); btn.disabled = false; return; }
      const wrap = document.getElementById('sr-wrap-' + id);
      if (wrap) wrap.remove();
      const grid = document.getElementById('srGrid');
      if (grid && !grid.querySelector('.sr-card-wrap')) {
        grid.previousElementSibling?.remove();
        grid.remove();
      }
    })
    .catch((e) => { console.error(e); showToast('Network error.', 'error'); btn.disabled = false; });
  }, 'Remove');
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
