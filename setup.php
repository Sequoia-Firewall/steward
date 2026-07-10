<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireRole('administrator');

// Already configured — nothing to do here
if (getSetting('instance_name') !== null && getSetting('instance_name') !== '') {
    header('Location: ' . BASE_PATH . '/index');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name = trim($_POST['instance_name'] ?? '');
    if ($name === '') {
        $error = 'Please enter a name for this instance.';
    } else {
        setSetting('instance_name', $name);
        header('Location: ' . BASE_PATH . '/index');
        exit;
    }
}

$pageTitle   = 'Welcome — Setup';
$currentPage = 'setup';
include __DIR__ . '/includes/header.php';
?>

<div class="setup-wrap">
  <div class="setup-card">
    <div class="setup-icon-wrap">
      <i class="bi bi-house-heart-fill"></i>
    </div>
    <h2 class="setup-title">Welcome to <?= h(APP_NAME) ?></h2>
    <p class="setup-sub">Give this installation a name so you can identify it at a glance.</p>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
      <?= csrfField() ?>
      <div class="mb-4">
        <label class="form-label fw-semibold" for="instance_name">Instance Name</label>
        <input type="text" class="form-control form-control-lg" id="instance_name"
               name="instance_name"
               value="<?= h($_POST['instance_name'] ?? '') ?>"
               placeholder="e.g. Smith Family Finances"
               maxlength="100" autofocus required>
        <div class="form-text mt-1">
          Shown beneath "<?= h(APP_NAME) ?>" in the navigation bar. You can change it later in
          <strong>Settings → Preferences</strong>.
        </div>
      </div>
      <button type="submit" class="btn btn-primary w-100 btn-lg">
        <i class="bi bi-arrow-right-circle"></i> Get Started
      </button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
