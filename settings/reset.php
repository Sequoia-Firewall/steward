<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/settings/backup');
    exit;
}
verifyCsrf();

if (trim($_POST['confirm'] ?? '') !== 'I understand') {
    setFlash('error', 'Reset cancelled: confirmation phrase did not match.');
    header('Location: ' . BASE_PATH . '/settings/backup');
    exit;
}

$db = getDB();

try {
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    // Full truncates — resets AUTO_INCREMENT and clears all rows
    foreach ([
        'transaction_splits', 'investment_transactions', 'transfers', 'transactions',
        'loan_details', 'savings_goals', 'scheduled_bills',
        'paycheck_template_lines', 'paycheck_templates',
        'accounts', 'budgets', 'payees', 'categories', 'favorite_reports',
    ] as $table) {
        $db->exec("TRUNCATE TABLE `{$table}`");
    }

    // Partial deletes: keep Index investments and their price history
    $db->exec("DELETE FROM investment_prices
               WHERE investment_id IN (SELECT id FROM investments WHERE type != 'Index')");
    $db->exec("DELETE FROM investments WHERE type != 'Index'");

    $db->exec('SET FOREIGN_KEY_CHECKS = 1');

    setFlash('success', 'Database reset complete. All financial data has been removed. Users, settings, and market indices have been preserved.');
} catch (Exception $e) {
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    setFlash('error', 'Reset failed: ' . $e->getMessage());
}

header('Location: ' . BASE_PATH . '/settings/backup');
exit;
