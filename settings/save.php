<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/settings/index');
    exit;
}
verifyCsrf();

$validProviders = ['massive', 'alphavantage', 'yahoo', 'manual'];
$provider = $_POST['price_provider'] ?? 'manual';
if (!in_array($provider, $validProviders, true)) $provider = 'manual';

setSetting('price_provider', $provider);
setSetting('massive_api_key',       trim($_POST['massive_api_key']       ?? ''));
setSetting('alphavantage_api_key',  trim($_POST['alphavantage_api_key']  ?? ''));

// SEC EDGAR contact (sent in the User-Agent of SEC requests); blank disables SEC lookups
$secEmail = trim($_POST['sec_contact_email'] ?? '');
if ($secEmail !== '' && !filter_var($secEmail, FILTER_VALIDATE_EMAIL)) {
    setFlash('error', 'Other settings were saved, but the SEC contact email is not a valid address, so it was not changed.');
    header('Location: ' . BASE_PATH . '/settings/index');
    exit;
}
setSetting('sec_contact_email', $secEmail);

setFlash('success', 'Settings saved.');
header('Location: ' . BASE_PATH . '/settings/index');
exit;
