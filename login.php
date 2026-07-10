<?php
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_PATH . '/index');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (login($username, $password)) {
        $next = trim($_POST['next'] ?? '');
        if ($next !== '' && str_starts_with($next, BASE_PATH . '/') && !str_contains($next, '..')) {
            header('Location: ' . $next);
        } else {
            header('Location: ' . BASE_PATH . '/index');
        }
        exit;
    }
    $error = 'Invalid username or password.';
    logActivity('login_failed', 'Failed sign-in attempt for username: ' . $username, null, $username);
}

$pageTitle    = 'Sign In';
$currentPage  = 'login';
include __DIR__ . '/includes/header.php';
?>
<div class="login-container">
  <div class="login-card">
    <div class="login-logo">
      <h1 class="steward-title"><span class="steward-dollar">$</span>teward</h1>
      <p>Personal Finance Manager</p>
      <?php $__loginInstance = getSetting('instance_name', ''); if ($__loginInstance !== ''): ?>
      <p class="login-instance-name"><?= h($__loginInstance) ?></p>
      <?php endif; ?>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
    <?php elseif (($_GET['reason'] ?? '') === 'timeout'): ?>
    <div class="alert alert-warning">Your session expired due to inactivity. Please sign in again.</div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
      <?php if (($__next = trim($_GET['next'] ?? '')) !== ''): ?>
      <input type="hidden" name="next" value="<?= h($__next) ?>">
      <?php endif; ?>
      <div class="mb-3">
        <label class="form-label">Username</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person"></i></span>
          <input type="text" name="username" class="form-control" required autofocus
                 value="<?= h($_POST['username'] ?? '') ?>">
        </div>
      </div>
      <div class="mb-4">
        <label class="form-label">Password</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock"></i></span>
          <input type="password" name="password" class="form-control" required>
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 btn-lg">
        <i class="bi bi-box-arrow-in-right"></i> Sign In
      </button>
    </form>

    <div class="login-hint mt-3">
      <small>Default accounts: <code>admin</code> / <code>Admin123!</code></small>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
