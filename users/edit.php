<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

$db   = getDB();
$id   = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: ' . BASE_PATH . '/users/index');
    exit;
}

$errors = [];
$form   = $user;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $form = [
        'id'        => $id,
        'username'  => trim($_POST['username']  ?? ''),
        'email'     => trim($_POST['email']     ?? ''),
        'full_name' => trim($_POST['full_name'] ?? ''),
        'role'      => $_POST['role'] ?? $user['role'],
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($form['username'] === '') $errors[] = 'Username is required.';
    if ($password && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password && $password !== $password2) $errors[] = 'Passwords do not match.';

    // Prevent losing the last active administrator
    if ($user['role'] === 'administrator' && (int)$user['is_active'] === 1) {
        $demoted     = $form['role'] !== 'administrator';
        $deactivated = !$form['is_active'];
        if ($demoted || $deactivated) {
            $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE role = ? AND is_active = 1 AND id != ?');
            $chk->execute(['administrator', $id]);
            if ((int)$chk->fetchColumn() === 0) {
                $errors[] = $demoted
                    ? 'Cannot change role — this is the last active administrator.'
                    : 'Cannot deactivate — this is the last active administrator.';
            }
        }
    }

    // Admins cannot change their own role
    if ($id === currentUserId() && $form['role'] !== $user['role']) {
        $errors[] = 'You cannot change your own role.';
    }

    if (empty($errors)) {
        if ($password) {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('UPDATE users SET username=?, email=?, full_name=?, role=?, is_active=?, password_hash=? WHERE id=?')
               ->execute([$form['username'], $form['email'], $form['full_name'], $form['role'], $form['is_active'], $hash, $id]);
        } else {
            $db->prepare('UPDATE users SET username=?, email=?, full_name=?, role=?, is_active=? WHERE id=?')
               ->execute([$form['username'], $form['email'], $form['full_name'], $form['role'], $form['is_active'], $id]);
        }
        setFlash('success', 'User updated.');
        header('Location: ' . BASE_PATH . '/users/index');
        exit;
    }
}

$pageTitle   = 'Edit User';
$currentPage = 'users';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-pencil"></i> Edit User: <?= h($user['username']) ?></h2>
  <a href="<?= BASE_PATH ?>/users/index" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> Back
  </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
  <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="form-card">
<form method="post" novalidate autocomplete="off">
  <?= csrfField() ?>
  <div class="row g-3">
    <div class="col-md-4">
      <label class="form-label required">Username</label>
      <input type="text" name="username" class="form-control" value="<?= h($form['username']) ?>" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Full Name</label>
      <input type="text" name="full_name" class="form-control" value="<?= h($form['full_name']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-control" value="<?= h($form['email']) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">New Password <small class="text-muted">(leave blank to keep current)</small></label>
      <input type="password" name="password" class="form-control" autocomplete="new-password">
    </div>
    <div class="col-md-4">
      <label class="form-label">Confirm New Password</label>
      <input type="password" name="password2" class="form-control" autocomplete="new-password">
    </div>
    <div class="col-md-2">
      <label class="form-label required">Role</label>
      <select name="role" class="form-select" <?= $id === currentUserId() ? 'disabled' : '' ?>>
        <option value="viewer"        <?= $form['role']==='viewer'        ? 'selected':'' ?>>Viewer</option>
        <option value="user"          <?= $form['role']==='user'          ? 'selected':'' ?>>User</option>
        <option value="administrator" <?= $form['role']==='administrator' ? 'selected':'' ?>>Administrator</option>
      </select>
      <?php if ($id === currentUserId()): ?><input type="hidden" name="role" value="<?= h($form['role']) ?>"><?php endif; ?>
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
               <?= $form['is_active'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="isActive">Active</label>
      </div>
    </div>
  </div>
  <div class="form-actions mt-4">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Changes</button>
    <a href="<?= BASE_PATH ?>/users/index" class="btn btn-outline-secondary ms-2">Cancel</a>
  </div>
</form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
