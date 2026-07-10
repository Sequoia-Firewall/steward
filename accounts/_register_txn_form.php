  <div class="txn-form-wrapper" id="txnFormWrapper">
    <div class="txn-form-header">
      <span id="txnFormTitle">New Transaction</span>
      <button class="btn-form-collapse" id="btnCollapseForm" title="Collapse form">
        <i class="bi bi-chevron-down"></i>
      </button>
    </div>

    <?php if ($isInvestAccount): ?>
    <!-- Investment: single tab -->
    <div class="txn-tabs" id="txnTabs">
      <button class="txn-tab active" data-tab="investment">
        <i class="bi bi-graph-up-arrow"></i> Transaction
      </button>
    </div>
    <?php elseif ($isAssetAccount): ?>
    <!-- Asset: increase, decrease, transfer tabs -->
    <div class="txn-tabs" id="txnTabs">
      <button class="txn-tab tab-deposit active" data-tab="deposit" onclick="switchTab('deposit')">
        <i class="bi bi-plus-circle"></i> Increase
      </button>
      <button class="txn-tab tab-withdrawal" data-tab="withdrawal" onclick="switchTab('withdrawal')">
        <i class="bi bi-dash-circle"></i> Decrease
      </button>
      <button class="txn-tab" data-tab="transfer" onclick="switchTab('transfer')">
        <i class="bi bi-arrow-left-right"></i> Transfer
      </button>
    </div>
    <?php else: ?>
    <!-- Standard: three tabs -->
    <div class="txn-tabs" id="txnTabs">
      <button class="txn-tab tab-withdrawal active" data-tab="withdrawal" onclick="switchTab('withdrawal')">
        <i class="bi bi-dash-circle"></i> <?= $isCreditCard ? 'Credit' : 'Withdrawal' ?>
      </button>
      <button class="txn-tab tab-deposit" data-tab="deposit" onclick="switchTab('deposit')">
        <i class="bi bi-plus-circle"></i> Deposit
      </button>
      <button class="txn-tab" data-tab="transfer" onclick="switchTab('transfer')">
        <i class="bi bi-arrow-left-right"></i> Transfer
      </button>
    </div>
    <?php endif; ?>

    <form id="txnForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="account_id" value="<?= $id ?>">
      <input type="hidden" name="txn_id" id="txnId" value="">
      <input type="hidden" name="type" id="txnType" value="<?= $isInvestAccount ? 'investment' : ($isAssetAccount ? 'deposit' : 'withdrawal') ?>">

      <?php if ($isInvestAccount): ?>
      <!-- ── INVESTMENT tab ────────────────────────────── -->
      <div class="txn-panel" id="panel-investment">
        <div class="inv-form-layout">

          <!-- Left: fixed fields stacked vertically -->
          <div class="inv-left-col">
            <div class="txn-field">
              <label>Date</label>
              <input type="date" name="date_d" id="date_d" class="form-control"
                     value="<?= date('Y-m-d') ?>">
            </div>
            <div class="txn-field">
              <label>Investment</label>
              <div class="input-group input-group-sm">
                <input type="text" name="payee_d" id="payee_d" class="form-control"
                       placeholder="Security or fund name" list="investmentList">
                <?php if (canEdit()): ?>
                <button type="button" class="btn btn-outline-secondary inv-add-btn"
                        title="New investment"
                        onclick="openInvestmentModal(null, function(inv){
                          document.getElementById('payee_d').value = inv.name;
                        })">
                  <i class="bi bi-plus"></i>
                </button>
                <?php endif; ?>
              </div>
            </div>
            <div class="txn-field">
              <label>Activity</label>
              <select name="inv_activity" id="inv_activity" class="form-select" onchange="onActivityChange()">
                <option value="buy">Buy</option>
                <option value="sell">Sell</option>
                <option value="add">Add</option>
                <option value="remove">Remove</option>
                <option value="split">Split</option>
                <option value="reinvest_div">Reinvest Dividend</option>
                <option value="reinvest_cap">Reinvest Cap Gain</option>
                <option value="div">Dividend (Cash)</option>
                <option value="int">Interest (Cash)</option>
              </select>
            </div>
            <div class="txn-field">
              <label>Cleared</label>
              <select name="cleared_d" id="cleared_d" class="form-select">
                <option value="">Not cleared</option>
                <option value="cleared">Cleared (c)</option>
                <?php if (isAdmin()): ?>
                <option value="reconciled">Reconciled (R)</option>
                <?php endif; ?>
              </select>
            </div>
          </div><!-- /.inv-left-col -->

          <!-- Right: activity-specific fields + memo at bottom -->
          <div class="inv-right-col" id="invActivityFields">

            <div class="txn-field inv-detail-field" id="invFieldQty" style="display:none">
              <label>Quantity (Shares)</label>
              <input type="number" name="inv_qty" id="inv_qty" class="form-control"
                     step="0.000001" min="0" placeholder="0" oninput="updateInvTotal()">
            </div>

            <div class="txn-field inv-detail-field" id="invFieldIncomeAmount" style="display:none">
              <label>Amount</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text"><?= MONEY_SYMBOL ?></span>
                <input type="number" name="inv_income_amount" id="inv_income_amount" class="form-control"
                       step="0.01" min="0" placeholder="0.00">
              </div>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldCostBasis" style="display:none">
              <label>Total Cost Basis</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text"><?= MONEY_SYMBOL ?></span>
                <input type="number" name="inv_cost_basis" id="inv_cost_basis" class="form-control"
                       step="0.01" min="0" placeholder="0.00">
              </div>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldPrice" style="display:none">
              <label>Price per Share</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text"><?= MONEY_SYMBOL ?></span>
                <input type="number" name="inv_price" id="inv_price" class="form-control"
                       step="0.000001" min="0" placeholder="0.000000" oninput="updateInvTotal()">
              </div>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldCommission" style="display:none">
              <label>Commission</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text"><?= MONEY_SYMBOL ?></span>
                <input type="number" name="inv_commission" id="inv_commission" class="form-control"
                       step="0.01" min="0" placeholder="0.00" oninput="updateInvTotal()">
              </div>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldTotal" style="display:none">
              <label id="invTotalLabel">Total Cost</label>
              <div class="input-group input-group-sm">
                <span class="input-group-text"><?= MONEY_SYMBOL ?></span>
                <input type="text" name="inv_total" id="inv_total" class="form-control inv-total-field"
                       placeholder="0.00" readonly tabindex="-1">
              </div>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldCashFrom" style="display:none">
              <label id="invCashFromLabel">Transfer From (Cash)</label>
              <select name="inv_cash_account_id" id="inv_cash_account_id" class="form-select">
                <?php foreach ($allAccounts as $acc): if ($acc['id'] === $id) continue; ?>
                <option value="<?= $acc['id'] ?>"
                        <?= ($linkedAccount && $acc['id'] == $linkedAccount['id']) ? 'selected' : '' ?>>
                  <?= h($acc['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="txn-field inv-detail-field" id="invFieldCashTo" style="display:none">
              <label>Transfer To (Cash)</label>
              <select name="inv_cash_account_to_id" id="inv_cash_account_to_id" class="form-select">
                <?php foreach ($allAccounts as $acc): if ($acc['id'] === $id) continue; ?>
                <option value="<?= $acc['id'] ?>"
                        <?= ($linkedAccount && $acc['id'] == $linkedAccount['id']) ? 'selected' : '' ?>>
                  <?= h($acc['name']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

          </div><!-- /.inv-right-col -->

        </div><!-- /.inv-form-layout -->

        <div class="txn-field inv-memo-field">
          <label>Memo</label>
          <input type="text" name="memo_d" id="memo_d" class="form-control"
                 placeholder="Optional note">
        </div>
      </div>
      <?php else: ?>
      <!-- ── WITHDRAWAL tab ─────────────────────────────── -->
      <div class="txn-panel <?= $isAssetAccount ? 'hidden' : '' ?>" id="panel-withdrawal">
        <div class="txn-fields">
          <div class="txn-field field-num">
            <label><?= $isAssetAccount ? 'Reference' : 'Number' ?></label>
            <input type="text" name="num_w" id="num_w" class="form-control" placeholder="e.g. 1001, EFT">
          </div>
          <div class="txn-field field-date">
            <label>Date</label>
            <input type="date" name="date_w" id="date_w" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="txn-field field-payee">
            <label>Pay To</label>
            <input type="text" name="payee_w" id="payee_w" class="form-control" placeholder="Payee name" list="payeeList">
          </div>
          <div class="txn-field field-amount">
            <label>Amount</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input type="number" name="amount_w" id="amount_w" class="form-control" step="0.01" min="0" placeholder="0.00">
            </div>
          </div>
        </div>
        <div class="txn-fields">
          <div class="txn-field field-cat">
            <label>Category</label>
            <select name="category_w" id="category_w" class="form-select" onchange="loadSubcategories('w')">
              <option value="">-- Select Category --</option>
              <?php foreach (['expense' => 'EXPENSES', 'income' => 'INCOME'] as $ctype => $clabel): ?>
              <?php if (!empty($categoriesByType[$ctype])): ?>
              <optgroup label="<?= $clabel ?>">
                <?php foreach ($categoriesByType[$ctype] as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="txn-field field-subcat">
            <label>Subcategory</label>
            <select name="subcategory_w" id="subcategory_w" class="form-select">
              <option value="">-- None --</option>
            </select>
          </div>
          <div class="txn-field field-memo">
            <label>Memo</label>
            <input type="text" name="memo_w" id="memo_w" class="form-control" placeholder="Optional memo">
          </div>
          <div class="txn-field field-cleared">
            <label>Cleared</label>
            <select name="cleared_w" id="cleared_w" class="form-select">
              <option value="">Not cleared</option>
              <option value="cleared">Cleared (c)</option>
              <?php if (isAdmin()): ?>
              <option value="reconciled">Reconciled (R)</option>
              <?php endif; ?>
            </select>
          </div>
        </div>

        <!-- Split section -->
        <div class="split-section" id="splitSection_w">
          <div class="split-header">
            <span class="split-label"><i class="bi bi-diagram-3"></i> Split Categories</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addSplitRow('w')">
              <i class="bi bi-plus"></i> Add Split
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger ms-1" onclick="clearSplits('w')">
              <i class="bi bi-x"></i> Clear Splits
            </button>
          </div>
          <div id="splitRows_w"></div>
        </div>
      </div>

      <!-- ── DEPOSIT tab ─────────────────────────────────── -->
      <div class="txn-panel <?= $isAssetAccount ? '' : 'hidden' ?>" id="panel-deposit">
        <div class="txn-fields">
          <div class="txn-field field-num">
            <label><?= $isAssetAccount ? 'Reference' : 'Number' ?></label>
            <input type="text" name="num_d" id="num_d" class="form-control" placeholder="e.g. DEP">
          </div>
          <div class="txn-field field-date">
            <label>Date</label>
            <input type="date" name="date_d" id="date_d" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="txn-field field-payee">
            <label>From</label>
            <input type="text" name="payee_d" id="payee_d" class="form-control" placeholder="Source / Payer" list="payeeList">
          </div>
          <div class="txn-field field-amount">
            <label>Amount</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input type="number" name="amount_d" id="amount_d" class="form-control" step="0.01" min="0" placeholder="0.00">
            </div>
          </div>
        </div>
        <div class="txn-fields">
          <div class="txn-field field-cat">
            <label>Category</label>
            <select name="category_d" id="category_d" class="form-select" onchange="loadSubcategories('d')">
              <option value="">-- Select Category --</option>
              <?php foreach (['expense' => 'EXPENSES', 'income' => 'INCOME'] as $ctype => $clabel): ?>
              <?php if (!empty($categoriesByType[$ctype])): ?>
              <optgroup label="<?= $clabel ?>">
                <?php foreach ($categoriesByType[$ctype] as $cat): ?>
                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="txn-field field-subcat">
            <label>Subcategory</label>
            <select name="subcategory_d" id="subcategory_d" class="form-select">
              <option value="">-- None --</option>
            </select>
          </div>
          <div class="txn-field field-memo">
            <label>Memo</label>
            <input type="text" name="memo_d" id="memo_d" class="form-control" placeholder="Optional memo">
          </div>
          <div class="txn-field field-cleared">
            <label>Cleared</label>
            <select name="cleared_d" id="cleared_d" class="form-select">
              <option value="">Not cleared</option>
              <option value="cleared">Cleared (c)</option>
              <?php if (isAdmin()): ?>
              <option value="reconciled">Reconciled (R)</option>
              <?php endif; ?>
            </select>
          </div>
        </div>

        <!-- Split section deposit -->
        <div class="split-section" id="splitSection_d">
          <div class="split-header">
            <span class="split-label"><i class="bi bi-diagram-3"></i> Split Categories</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addSplitRow('d')">
              <i class="bi bi-plus"></i> Add Split
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger ms-1" onclick="clearSplits('d')">
              <i class="bi bi-x"></i> Clear Splits
            </button>
          </div>
          <div id="splitRows_d"></div>
        </div>
      </div>

      <!-- ── TRANSFER tab ────────────────────────────────── -->
      <div class="txn-panel hidden" id="panel-transfer">
        <div class="txn-fields">
          <div class="txn-field field-num">
            <label><?= $isAssetAccount ? 'Reference' : 'Number' ?></label>
            <input type="text" name="num_t" id="num_t" class="form-control" placeholder="e.g. EFT">
          </div>
          <div class="txn-field field-date">
            <label>Date</label>
            <input type="date" name="date_t" id="date_t" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="txn-field field-acct">
            <label>From Account</label>
            <select name="from_account" id="from_account" class="form-select">
              <?php foreach ($allAccounts as $acc): if (!isCashAccount($acc['type'])) continue; ?>
              <option value="<?= $acc['id'] ?>" <?= $acc['id'] == $id ? 'selected' : '' ?>><?= h($acc['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="txn-field field-acct">
            <label>To Account</label>
            <select name="to_account" id="to_account" class="form-select">
              <?php foreach ($allAccounts as $acc): if (!isCashAccount($acc['type'])) continue; ?>
              <option value="<?= $acc['id'] ?>" <?= $acc['id'] != $id ? 'selected' : '' ?>><?= h($acc['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="txn-fields">
          <div class="txn-field field-payee">
            <label>Pay To (optional)</label>
            <input type="text" name="payee_t" id="payee_t" class="form-control" placeholder="Payee (optional)" list="payeeList">
          </div>
          <div class="txn-field field-amount">
            <label>Amount</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input type="number" name="amount_t" id="amount_t" class="form-control" step="0.01" min="0" placeholder="0.00">
            </div>
          </div>
          <div class="txn-field field-memo">
            <label>Memo</label>
            <input type="text" name="memo_t" id="memo_t" class="form-control" placeholder="Optional memo">
          </div>
          <div class="txn-field field-cleared">
            <label>Cleared</label>
            <select name="cleared_t" id="cleared_t" class="form-select">
              <option value="">Not cleared</option>
              <option value="cleared">Cleared (c)</option>
              <?php if (isAdmin()): ?>
              <option value="reconciled">Reconciled (R)</option>
              <?php endif; ?>
            </select>
          </div>
        </div>
      </div>

      <?php endif; // end !$isInvestAccount ?>

      <!-- ── Form buttons ────────────────────────────────── -->
      <div class="txn-form-actions">
        <button type="button" class="btn btn-primary" onclick="submitTransaction()">
          <i class="bi bi-check-lg"></i> Enter
        </button>
        <button type="button" class="btn btn-outline-secondary ms-2" onclick="cancelTransaction()">
          <i class="bi bi-x-lg"></i> Cancel
        </button>
        <?php if (!$isInvestAccount && canEdit()): ?>
        <div class="form-check form-check-inline ms-3" id="makeRecurringWrap">
          <input class="form-check-input" type="checkbox" id="chkMakeRecurring">
          <label class="form-check-label small text-muted" for="chkMakeRecurring"
                 style="cursor:pointer; user-select:none">
            <i class="bi bi-arrow-repeat"></i> Make Recurring
          </label>
        </div>
        <?php endif; ?>
        <button type="button" class="btn btn-danger ms-2" id="btnDeleteTxn"
                style="display:none" onclick="deleteCurrentTransaction()">
          <i class="bi bi-trash"></i> Delete
        </button>
        <span class="txn-status ms-3" id="txnStatus"></span>
      </div>
    </form>
  </div><!-- /.txn-form-wrapper -->

<!-- Scheduled bill confirmation modal -->
<div class="modal fade" id="billMatchModal" tabindex="-1" aria-labelledby="billMatchModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="billMatchModalLabel">
          <i class="bi bi-calendar-check"></i> Scheduled Transaction Found
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="billMatchBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="billMatchYes">
          <i class="bi bi-check-lg"></i> Yes, mark as paid
        </button>
        <button type="button" class="btn btn-outline-secondary" id="billMatchNo">
          <i class="bi bi-x-lg"></i> No, keep scheduled
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Duplicate check/reference number warning modal -->
<div class="modal fade" id="duplicateNumModal" tabindex="-1" aria-labelledby="duplicateNumModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="duplicateNumModalLabel">
          <i class="bi bi-exclamation-triangle"></i> Duplicate Number
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="duplicateNumBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="duplicateNumYes">
          <i class="bi bi-check-lg"></i> Save Anyway
        </button>
        <button type="button" class="btn btn-outline-secondary" id="duplicateNumNo" data-bs-dismiss="modal">
          <i class="bi bi-x-lg"></i> Cancel
        </button>
      </div>
    </div>
  </div>
</div>
