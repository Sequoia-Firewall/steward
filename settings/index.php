<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

$pageTitle   = 'Online Services';
$currentPage = 'settings';

$provider          = getSetting('price_provider', 'manual');
$massiveKey        = getSetting('massive_api_key', '');
$alphaKey          = getSetting('alphavantage_api_key', '');
$lastFetched       = getSetting('price_last_fetched');

include __DIR__ . '/../includes/header.php';
?>
<script>const BASE_PATH = '<?= BASE_PATH ?>';</script>

<div class="page-header">
  <h2><i class="bi bi-gear"></i> Online Services</h2>
</div>

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
  <?= h($flash['message']) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="dash-section">
  <h4 class="section-title"><i class="bi bi-graph-up"></i> Price Data</h4>

  <form method="post" action="<?= BASE_PATH ?>/settings/save" id="settingsForm">
    <?= csrfField() ?>

    <div class="settings-group">
      <label class="settings-label">Price Provider</label>
      <div class="settings-providers">
        <?php
        $providers = [
            'massive'      => ['label' => 'Massive',       'icon' => 'bi-lightning-charge-fill', 'note' => 'Recommended — full historical data, dividends & splits'],
            'alphavantage' => ['label' => 'Alpha Vantage', 'icon' => 'bi-bar-chart-steps',       'note' => 'Free tier: 25 requests/day'],
            'yahoo'        => ['label' => 'Yahoo Finance', 'icon' => 'bi-yahoo',                 'note' => 'No API key required — unofficial, may be unstable'],
            'manual'       => ['label' => 'Manual only',   'icon' => 'bi-pencil-square',         'note' => 'Enter prices by hand; no automatic fetching'],
        ];
        foreach ($providers as $key => $p):
        ?>
        <label class="provider-option <?= $provider === $key ? 'selected' : '' ?>">
          <input type="radio" name="price_provider" value="<?= $key ?>" <?= $provider === $key ? 'checked' : '' ?>>
          <i class="bi <?= $p['icon'] ?>"></i>
          <span class="provider-name"><?= $p['label'] ?></span>
          <span class="provider-note"><?= $p['note'] ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="settings-group mt-4" id="apiKeySection">
      <label class="settings-label">API Keys</label>
      <div class="settings-api-keys">

        <div class="api-key-row" id="massiveKeyRow">
          <label for="massive_api_key">
            <i class="bi bi-lightning-charge-fill text-warning"></i> Massive API Key
          </label>
          <div class="input-group api-key-input">
            <input type="password" class="form-control form-control-sm font-monospace"
                   id="massive_api_key" name="massive_api_key"
                   value="<?= h($massiveKey ?? '') ?>"
                   placeholder="Enter your Massive API key"
                   autocomplete="off">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleKey(this)" title="Show/hide">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <div class="api-key-row" id="alphaKeyRow">
          <label for="alphavantage_api_key">
            <i class="bi bi-bar-chart-steps text-primary"></i> Alpha Vantage API Key
          </label>
          <div class="input-group api-key-input">
            <input type="password" class="form-control form-control-sm font-monospace"
                   id="alphavantage_api_key" name="alphavantage_api_key"
                   value="<?= h($alphaKey ?? '') ?>"
                   placeholder="Enter your Alpha Vantage API key"
                   autocomplete="off">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleKey(this)" title="Show/hide">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

      </div>
    </div>

    <?php if ($lastFetched): ?>
    <p class="text-muted small mt-3 mb-0">
      <i class="bi bi-clock"></i> Prices last fetched: <?= h($lastFetched) ?>
    </p>
    <?php endif; ?>

    <div class="mt-4">
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg"></i> Save Settings
      </button>
    </div>
  </form>
</div>

<script>
function toggleKey(btn) {
  const input = btn.closest('.input-group').querySelector('input');
  const icon  = btn.querySelector('i');
  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'bi bi-eye-slash';
  } else {
    input.type = 'password';
    icon.className = 'bi bi-eye';
  }
}

// Highlight selected provider card
document.querySelectorAll('.provider-option input').forEach(radio => {
  radio.addEventListener('change', () => {
    document.querySelectorAll('.provider-option').forEach(el => el.classList.remove('selected'));
    radio.closest('.provider-option').classList.add('selected');
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
