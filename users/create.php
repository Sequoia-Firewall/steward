<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

$errors = [];
$form   = ['username' => '', 'email' => '', 'full_name' => '', 'role' => 'user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $form = [
        'username'  => trim($_POST['username']  ?? ''),
        'email'     => trim($_POST['email']     ?? ''),
        'full_name' => trim($_POST['full_name'] ?? ''),
        'role'      => $_POST['role'] ?? 'user',
    ];
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($form['username'] === '')                          $errors[] = 'Username is required.';
    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $form['username'])) $errors[] = 'Username must be 3–50 alphanumeric characters.';
    if (strlen($password) < 8)                             $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $password2)                          $errors[] = 'Passwords do not match.';
    if (!in_array($form['role'], ['user','viewer','administrator'])) $errors[] = 'Invalid role.';

    if (empty($errors)) {
        $db = getDB();
        // Check uniqueness
        $chk = $db->prepare('SELECT id FROM users WHERE username = ?');
        $chk->execute([$form['username']]);
        if ($chk->fetch()) {
            $errors[] = 'Username already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare('INSERT INTO users (username, password_hash, email, full_name, role) VALUES (?, ?, ?, ?, ?)')
               ->execute([$form['username'], $hash, $form['email'], $form['full_name'], $form['role']]);
            setFlash('success', 'User "' . $form['username'] . '" created.');
            header('Location: ' . BASE_PATH . '/users/index');
            exit;
        }
    }
}

$pageTitle   = 'New User';
$currentPage = 'users';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h2><i class="bi bi-person-plus"></i> New User</h2>
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
      <input type="text" name="username" class="form-control" value="<?= h($form['username']) ?>" required autofocus autocomplete="off">
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
      <label class="form-label required">Password</label>
      <input type="password" name="password" class="form-control" required autocomplete="new-password">
    </div>
    <div class="col-md-4">
      <label class="form-label required">Confirm Password</label>
      <input type="password" name="password2" class="form-control" required autocomplete="new-password">
    </div>
    <div class="col-md-4">
      <label class="form-label required">Role</label>
      <select name="role" class="form-select">
        <option value="viewer"        <?= $form['role']==='viewer'        ? 'selected':'' ?>>Viewer</option>
        <option value="user"          <?= $form['role']==='user'          ? 'selected':'' ?>>User</option>
        <option value="administrator" <?= $form['role']==='administrator' ? 'selected':'' ?>>Administrator</option>
      </select>
      <div class="form-text">
        <b>Viewer</b>: read only. <b>User</b>: enter/edit transactions. <b>Admin</b>: full access.
      </div>
    </div>
  </div>
  <div class="form-actions mt-4">
    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Create User</button>
    <a href="<?= BASE_PATH ?>/users/index" class="btn btn-outline-secondary ms-2">Cancel</a>
  </div>
</form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
