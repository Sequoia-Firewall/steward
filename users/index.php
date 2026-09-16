<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

$db              = getDB();
$users           = $db->query('SELECT * FROM users ORDER BY role, username')->fetchAll();
$activeAdminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'administrator' AND is_active = 1")->fetchColumn();
$txnCounts = $db->query('SELECT created_by, COUNT(*) FROM transactions WHERE created_by IS NOT NULL GROUP BY created_by')
                 ->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle   = 'User Management';
$currentPage = 'users';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-people"></i> User Management</h2>
  <a href="<?= BASE_PATH ?>/users/create" class="btn btn-primary btn-sm">
    <i class="bi bi-person-plus"></i> New User
  </a>
</div>

<div class="form-card">
<table class="table dash-table">
  <thead>
    <tr>
      <th>Username</th>
      <th>Full Name</th>
      <th>Email</th>
      <th>Role</th>
      <th>Status</th>
      <th>Last Login</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($users as $user): ?>
    <tr>
      <td><strong><?= h($user['username']) ?></strong></td>
      <td><?= h($user['full_name']) ?></td>
      <td><?= h($user['email']) ?></td>
      <td>
        <span class="badge role-badge role-<?= h($user['role']) ?>"><?= h($user['role']) ?></span>
      </td>
      <td>
        <?php if ($user['is_active']): ?>
          <span class="badge bg-success">Active</span>
        <?php else: ?>
          <span class="badge bg-secondary">Inactive</span>
        <?php endif; ?>
      </td>
      <td><?= $user['last_login'] ? formatDate(substr($user['last_login'], 0, 10)) : 'Never' ?></td>
      <td>
        <a href="<?= BASE_PATH ?>/users/edit?id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-secondary">
          <i class="bi bi-pencil"></i> Edit
        </a>
        <button class="btn btn-sm btn-outline-secondary"
                onclick="confirmResetPrefs(<?= $user['id'] ?>, '<?= h(addslashes($user['username'])) ?>')"
                title="Reset dashboard and display preferences">
          <i class="bi bi-arrow-counterclockwise"></i> Reset Prefs
        </button>
        <?php
          $isLastAdmin = $user['role'] === 'administrator'
                      && (int)$user['is_active'] === 1
                      && $activeAdminCount <= 1;
          $hasTxns = ($txnCounts[$user['id']] ?? 0) > 0;
          $isSelf  = $user['id'] === currentUserId();
        ?>
        <?php if (!$isSelf && ($user['is_active'] || !$isLastAdmin)): ?>
          <?php if ($user['is_active']): ?>
          <button class="btn btn-sm btn-outline-secondary" <?= $isLastAdmin ? 'disabled title="Cannot deactivate — this is the last active administrator."' : '' ?>
                  onclick="confirmToggleActive(<?= $user['id'] ?>, '<?= h(addslashes($user['username'])) ?>', false)">
            <i class="bi bi-person-dash"></i> Deactivate
          </button>
          <?php else: ?>
          <button class="btn btn-sm btn-outline-success"
                  onclick="confirmToggleActive(<?= $user['id'] ?>, '<?= h(addslashes($user['username'])) ?>', true)">
            <i class="bi bi-person-check"></i> Activate
          </button>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($user['id'] !== currentUserId() && !$isLastAdmin && !$hasTxns): ?>
        <button class="btn btn-sm btn-outline-danger"
                onclick="confirmDeleteUser(<?= $user['id'] ?>, '<?= h(addslashes($user['username'])) ?>')">
          <i class="bi bi-trash"></i>
        </button>
        <?php elseif ($user['id'] !== currentUserId() && !$isLastAdmin && $hasTxns): ?>
        <button class="btn btn-sm btn-outline-secondary" disabled
                title="Has transaction history — deactivate instead of deleting.">
          <i class="bi bi-trash"></i>
        </button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<form id="deleteUserForm" method="post" action="<?= BASE_PATH ?>/users/delete" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="deleteUserId">
</form>
<form id="toggleActiveForm" method="post" action="<?= BASE_PATH ?>/users/toggle_active" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="toggleActiveUserId">
</form>
<form id="resetPrefsForm" method="post" action="<?= BASE_PATH ?>/users/reset_prefs" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="id" id="resetPrefsUserId">
</form>
<script>
function confirmDeleteUser(id, name) {
  appConfirm(
    'Delete User',
    'Delete user "' + name + '"?',
    'This cannot be undone.',
    () => {
      document.getElementById('deleteUserId').value = id;
      document.getElementById('deleteUserForm').submit();
    },
    'Delete'
  );
}
function confirmToggleActive(id, name, activating) {
  appConfirm(
    activating ? 'Activate User' : 'Deactivate User',
    (activating ? 'Activate' : 'Deactivate') + ' user "' + name + '"?',
    activating
      ? 'They will be able to log in again.'
      : 'They will no longer be able to log in. Their transaction history and settings are kept.',
    () => {
      document.getElementById('toggleActiveUserId').value = id;
      document.getElementById('toggleActiveForm').submit();
    },
    activating ? 'Activate' : 'Deactivate'
  );
}
function confirmResetPrefs(id, name) {
  appConfirm(
    'Reset Preferences',
    'Reset all dashboard and display preferences for "' + name + '"?',
    "The user's layout, widget order, and account filters will be cleared.",
    () => {
      document.getElementById('resetPrefsUserId').value = id;
      document.getElementById('resetPrefsForm').submit();
    },
    'Reset'
  );
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
