<?php
// Report account filter setup.
// Requires $db. Call before building queries.
// Sets: $allAccounts, $allAcctIds, $selectedAcctIds, $acctParam,
//       $acctWhere, $acctParams, $filteringAccts

$allAccounts = $db->query(
    "SELECT id, name, type FROM accounts
     WHERE is_active = 1 AND is_investment_cash = 0 AND type NOT IN ('Investment','Asset','Crypto')
     ORDER BY type, name"
)->fetchAll();

$allAcctIds = array_map('intval', array_column($allAccounts, 'id'));

$acctParam = trim($_GET['accts'] ?? '');
if ($acctParam === '' || $acctParam === 'all') {
    $selectedAcctIds = $allAcctIds;
    $filteringAccts  = false;
} else {
    $parsed = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $acctParam)),
        fn($id) => in_array($id, $allAcctIds, true)
    )));
    if (empty($parsed) || count($parsed) >= count($allAcctIds)) {
        $selectedAcctIds = $allAcctIds;
        $filteringAccts  = false;
    } else {
        $selectedAcctIds = $parsed;
        $filteringAccts  = true;
    }
}

$ph        = implode(',', array_fill(0, count($selectedAcctIds), '?'));
$acctWhere  = "AND t.account_id IN ($ph)";
$acctParams = $selectedAcctIds;
