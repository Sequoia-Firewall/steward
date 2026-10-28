<?php
/**
 * Save one view preference for the current user (POST key, value).
 * Leaving out `value` clears the preference. Keys and their allowed values
 * are listed in VIEW_PREF_PATTERNS (includes/functions.php).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

$key   = $_POST['key'] ?? '';
$value = $_POST['value'] ?? null;

if (!is_string($key) || !isset(VIEW_PREF_PATTERNS[$key])) {
    echo json_encode(['ok' => false, 'error' => 'Unknown preference.']);
    exit;
}
if ($value !== null && (!is_string($value) || !preg_match(VIEW_PREF_PATTERNS[$key], $value))) {
    echo json_encode(['ok' => false, 'error' => 'Invalid value.']);
    exit;
}

setUserPref(currentUserId(), $key, $value);
echo json_encode(['ok' => true]);
