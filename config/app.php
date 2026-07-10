<?php
if (!file_exists(__DIR__ . '/database.php') && !defined('MONEY_SETUP_MODE') && php_sapi_name() !== 'cli') {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
    $webBase = ($docRoot && str_starts_with($appRoot, $docRoot)) ? substr($appRoot, strlen($docRoot)) : '';
    header('Location: ' . $scheme . '://' . $host . $webBase . '/setup/');
    exit;
}
define('APP_NAME',           'Steward');
define('APP_VERSION',        '2026R1');
define('APP_SCHEMA_VERSION', 1);
define('BASE_PATH',          '/steward');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_name('STEWARD_SESSION');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['path' => BASE_PATH === '' ? '/' : BASE_PATH . '/']);
    session_start();
}
date_default_timezone_set('America/New_York');

ini_set('display_errors', 0);
error_reporting(E_ALL);

define('MONEY_SYMBOL',   '$');
define('MONEY_DECIMALS', 2);

// ── HTTPS enforcement ───────────────────────────────────────────────────────
// Runs on every request before any output. Skipped during setup (no DB yet) and CLI.
if (file_exists(__DIR__ . '/database.php') && php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/database.php';
    require_once __DIR__ . '/../includes/functions.php';
    if (getSetting('enforce_https') === '1') {
        $_isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                 || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        if (!$_isHttps) {
            header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
    }
}