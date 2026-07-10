<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$db = getDB();

// Load goals with linked account balance when applicable
$goals = $db->query(
    "SELECT g.*,
            a.name AS account_name,
            CASE WHEN g.account_id IS NOT NULL
                 THEN a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.account_id = a.id), 0)
                 ELSE g.current_amount
            END AS effective_current
     FROM savings_goals g
     LEFT JOIN accounts a ON a.id = g.account_id
     WHERE g.is_active = 1
     ORDER BY g.target_date IS NULL, g.target_date ASC, g.name ASC"
)->fetchAll();

// Checking / Savings accounts for the linked-account selector
$bankAccounts = $db->query(
    "SELECT id, name, type FROM accounts WHERE type IN ('Checking','Savings','Asset') AND is_active = 1 AND is_investment_cash = 0 ORDER BY name"
)->fetchAll();

$today       = date('Y-m-d');
$pageTitle   = 'Savings Goals';
$currentPage = 'goals';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-piggy-bank"></i> Savings Goals</h2>
  <?php if (canEdit()): ?>
  <button class="btn btn-primary btn-sm" onclick="openGoalModal()">
    <i class="bi bi-plus-circle"></i> New Goal
  </button>
  <?php endif; ?>
</div>

<?= renderFlash() ?>

<?php if (empty($goals)): ?>
<div class="alert alert-info">
  No savings goals yet.
  <?php if (canEdit()): ?><a href="#" onclick="openGoalModal();return false;">Create your first goal.</a><?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($goals as $g):
  $target  = (float)$g['target_amount'];
  $current = max(0, (float)$g['effective_current']);
  $pct     = $target > 0 ? min(100, round($current / $target * 100, 1)) : 0;
  $remaining = max(0, $target - $current);
  $done    = $remaining < 0.01;

  $barCls = $done ? 'bg-success' : ($pct >= 75 ? 'bg-info' : ($pct >= 50 ? 'bg-primary' : 'bg-warning'));

  $dueLabel = '';
  $dueCls   = '';
  if ($g['target_date']) {
      $daysLeft = (int)round((strtotime($g['target_date']) - strtotime($today)) / 86400);
      if ($done) {
          $dueLabel = 'Goal reached!';
          $dueCls   = 'text-success fw-semibold';
      } elseif ($daysLeft < 0) {
          $dueLabel = abs($daysLeft) . 'd overdue';
          $dueCls   = 'text-danger';
      } elseif ($daysLeft === 0) {
          $dueLabel = 'Due today';
          $dueCls   = 'text-warning fw-semibold';
      } elseif ($daysLeft <= 30) {
          $dueLabel = 'Due in ' . $daysLeft . 'd';
          $dueCls   = 'text-warning';
      } else {
          $months = round($daysLeft / 30.44, 1);
          $dueLabel = 'Due ' . formatDate($g['target_date']) . ' (' . $months . ' mo)';
          $dueCls   = 'text-muted';
      }
  }

  // Monthly amount needed to reach goal
  $monthlyNeeded = '';
  if (!$done && $g['target_date'] && $remaining > 0) {
      $monthsLeft = max(1, (strtotime($g['target_date']) - strtotime($today)) / (86400 * 30.44));
      $monthlyNeeded = formatMoney($remaining / $monthsLeft) . '/mo needed';
  }
?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100 shadow-sm">
    <div class="card-body d-flex flex-column">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <h5 class="card-title mb-0">
          <i class="bi bi-piggy-bank text-primary me-1"></i><?= h($g['name']) ?>
        </h5>
        <?php if (canEdit()): ?>
        <div class="d-flex gap-1">
          <button class="btn btn-sm btn-outline-secondary" title="Edit"
                  onclick="editGoal(<?= h(json_encode($g)) ?>)">
            <i class="bi bi-pencil"></i>
          </button>
          <?php if (isAdmin()): ?>
          <button class="btn btn-sm btn-outline-danger" title="Delete"
                  onclick="deleteGoal(<?= $g['id'] ?>, <?= h(json_encode($g['name'])) ?>)">
            <i class="bi bi-trash"></i>
          </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="mb-3">
        <div class="d-flex justify-content-between small mb-1">
          <span class="text-muted">
            <?= formatMoney($current) ?> of <strong><?= formatMoney($target) ?></strong>
          </span>
          <span class="fw-semibold"><?= $pct ?>%</span>
        </div>
        <div class="progress" style="height:10px">
          <div class="progress-bar <?= $barCls ?>" style="width:<?= $pct ?>%"></div>
        </div>
      </div>

      <div class="small text-muted mb-1">
        <?php if ($done): ?>
          <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill"></i> Goal reached!</span>
        <?php else: ?>
          <span class="text-danger"><?= formatMoney($remaining) ?> remaining</span>
          <?php if ($monthlyNeeded): ?>&nbsp;·&nbsp;<span><?= $monthlyNeeded ?></span><?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if ($dueLabel && !$done): ?>
      <div class="small <?= $dueCls ?> mb-1">
        <i class="bi bi-calendar3 me-1"></i><?= h($dueLabel) ?>
      </div>
      <?php endif; ?>

      <?php if ($g['account_name']): ?>
      <div class="small text-muted mt-1">
        <i class="bi bi-link-45deg"></i> <?= h($g['account_name']) ?>
      </div>
      <?php endif; ?>

      <?php if ($g['notes']): ?>
      <div class="small text-muted mt-1 fst-italic"><?= h($g['notes']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div><!-- /.row -->

<?php if (canEdit()): ?>
<!-- ── Goal Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="goalModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="goalModalTitle">New Savings Goal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="goalFormEl" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="id" id="goal_id" value="">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label required">Goal Name</label>
              <input type="text" name="name" id="goal_name" class="form-control"
                     maxlength="100" required placeholder="e.g. Emergency Fund, Vacation">
            </div>
            <div class="col-6">
              <label class="form-label required">Target Amount</label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" name="target_amount" id="goal_target" class="form-control"
                       step="0.01" min="0.01" required>
              </div>
            </div>
            <div class="col-6">
              <label class="form-label">Target Date</label>
              <input type="date" name="target_date" id="goal_date" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Linked Account <small class="text-muted">(auto-tracks balance)</small></label>
              <select name="account_id" id="goal_account" class="form-select" onchange="onGoalAccountChange()">
                <option value="">— None (enter manually) —</option>
                <?php foreach ($bankAccounts as $acc): ?>
                <option value="<?= $acc['id'] ?>"><?= h($acc['name']) ?> (<?= h($acc['type']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6" id="manualAmountGroup">
              <label class="form-label">Current Amount Saved</label>
              <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" name="current_amount" id="goal_current" class="form-control"
                       step="0.01" min="0" value="0">
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <input type="text" name="notes" id="goal_notes" class="form-control"
                     maxlength="255" placeholder="Optional notes">
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <span id="goalStatus" class="me-auto small"></span>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="saveGoal()">
          <i class="bi bi-check-circle"></i> Save
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Delete form -->
<form id="deleteGoalForm" method="post" action="<?= BASE_PATH ?>/goals/delete" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="deleteGoalId">
</form>
<?php endif; ?>

<script>
function onGoalAccountChange() {
  const linked = !!document.getElementById('goal_account').value;
  document.getElementById('manualAmountGroup').style.display = linked ? 'none' : '';
}

function openGoalModal() {
  document.getElementById('goal_id').value      = '';
  document.getElementById('goal_name').value    = '';
  document.getElementById('goal_target').value  = '';
  document.getElementById('goal_date').value    = '';
  document.getElementById('goal_account').value = '';
  document.getElementById('goal_current').value = '0';
  document.getElementById('goal_notes').value   = '';
  document.getElementById('goalStatus').innerHTML = '';
  document.getElementById('goalModalTitle').textContent = 'New Savings Goal';
  onGoalAccountChange();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('goalModal')).show();
}

function editGoal(g) {
  document.getElementById('goal_id').value      = g.id;
  document.getElementById('goal_name').value    = g.name;
  document.getElementById('goal_target').value  = parseFloat(g.target_amount).toFixed(2);
  document.getElementById('goal_date').value    = g.target_date || '';
  document.getElementById('goal_account').value = g.account_id || '';
  document.getElementById('goal_current').value = parseFloat(g.current_amount || 0).toFixed(2);
  document.getElementById('goal_notes').value   = g.notes || '';
  document.getElementById('goalStatus').innerHTML = '';
  document.getElementById('goalModalTitle').textContent = 'Edit Goal';
  onGoalAccountChange();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('goalModal')).show();
}

async function saveGoal() {
  const status = document.getElementById('goalStatus');
  const data   = new FormData(document.getElementById('goalFormEl'));
  status.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Saving…';
  try {
    const res  = await fetch('<?= BASE_PATH ?>/goals/save', { method: 'POST', body: data });
    const json = await res.json();
    if (json.ok) {
      status.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Saved!</span>';
      setTimeout(() => location.reload(), 500);
    } else {
      status.innerHTML = '<span class="text-danger">' + esc(json.error || 'Save failed') + '</span>';
    }
  } catch (e) {
    console.error(e);
    status.innerHTML = '<span class="text-danger">Network error.</span>';
  }
}

function deleteGoal(id, name) {
  appConfirm(
    'Delete Goal',
    'Delete goal "' + name + '"?',
    'This cannot be undone.',
    () => {
      document.getElementById('deleteGoalId').value = id;
      document.getElementById('deleteGoalForm').submit();
    },
    'Delete'
  );
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
