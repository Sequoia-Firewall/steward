<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}
verifyCsrf();

$check = $_POST['check'] ?? '';
$db    = getDB();

$out = ['ok' => true, 'count' => 0, 'columns' => [], 'items' => []];

switch ($check) {

    // ── 1. Duplicate category names ────────────────────────────
    case 'duplicate_categories':
        $rows = $db->query(
            "SELECT LOWER(name) AS lname,
                    COUNT(*) AS cnt,
                    GROUP_CONCAT(name ORDER BY id SEPARATOR '\x1f') AS names,
                    GROUP_CONCAT(CASE WHEN parent_id IS NULL OR parent_id = id THEN 'Category'
                                      ELSE 'Subcategory' END ORDER BY id SEPARATOR '\x1f') AS levels
             FROM categories
             WHERE is_active = 1
             GROUP BY LOWER(name)
             HAVING cnt > 1
             ORDER BY lname"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns'] = ['Name', 'Levels', 'Count'];
        foreach ($rows as $r) {
            $names  = explode("\x1f", $r['names']);
            $levels = array_unique(explode("\x1f", $r['levels']));
            $out['items'][] = [$names[0], implode(', ', $levels), (int)$r['cnt']];
        }
        $out['count']     = count($rows);
        $out['fix_label'] = 'Manage Categories';
        $out['fix_url']   = BASE_PATH . '/categories/index';
        break;

    // ── 2. Duplicate investments ───────────────────────────────
    case 'duplicate_investments':
        $byName = $db->query(
            "SELECT MIN(name) AS name, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids
             FROM investments WHERE is_active = 1
             GROUP BY LOWER(name) HAVING cnt > 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        $byCusip = $db->query(
            "SELECT cusip, COUNT(*) AS cnt,
                    GROUP_CONCAT(name ORDER BY id SEPARATOR '\x1f') AS names,
                    GROUP_CONCAT(id ORDER BY id) AS ids
             FROM investments
             WHERE is_active = 1 AND cusip IS NOT NULL AND cusip != ''
             GROUP BY cusip HAVING cnt > 1"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns'] = ['Investment', 'Reason', 'IDs'];
        foreach ($byName as $r) {
            $out['items'][] = [$r['name'], 'Duplicate name', $r['ids']];
        }
        foreach ($byCusip as $r) {
            $nameList = implode(', ', array_unique(explode("\x1f", $r['names'])));
            $out['items'][] = [$nameList, 'Duplicate CUSIP: ' . $r['cusip'], $r['ids']];
        }
        $out['count']     = count($out['items']);
        $out['fix_label'] = 'Manage Investments';
        $out['fix_url']   = BASE_PATH . '/portfolio/index';
        break;

    // ── 3. Uncategorized transactions ──────────────────────────
    case 'uncategorized_transactions':
        $rows = $db->query(
            "SELECT t.id, t.account_id, t.transaction_date, t.payee, t.amount, a.name AS account_name
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE t.type NOT IN ('transfer', 'investment')
               AND t.amount != 0
               AND (
                 t.transfer_pair_id IS NULL
                 OR NOT EXISTS (SELECT 1 FROM transactions pt WHERE pt.id = t.transfer_pair_id)
               )
               AND (
                 EXISTS (
                   SELECT 1 FROM transaction_splits ts
                   WHERE ts.transaction_id = t.id AND ts.category_id IS NULL
                 )
                 OR NOT EXISTS (
                   SELECT 1 FROM transaction_splits ts
                   WHERE ts.transaction_id = t.id
                 )
               )
             ORDER BY t.transaction_date DESC
             LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns']      = ['Date', 'Account', 'Payee', 'Amount'];
        $out['acct_link_col'] = 1;
        $out['acct_id_col']   = 4;
        $out['txn_id_col']    = 5;
        foreach ($rows as $r) {
            $out['items'][] = [
                $r['transaction_date'],
                $r['account_name'],
                $r['payee'],
                number_format((float)$r['amount'], 2),
                (int)$r['account_id'],  // hidden — used for register link
                (int)$r['id'],          // hidden — used for register link
            ];
        }
        $out['count'] = count($rows);
        break;

    // ── 4. Securities without CUSIP ────────────────────────────
    case 'securities_no_cusip':
        $rows = $db->query(
            "SELECT name, symbol, type FROM investments
             WHERE is_active = 1 AND (cusip IS NULL OR cusip = '')
             ORDER BY name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns'] = ['Name', 'Symbol', 'Type'];
        foreach ($rows as $r) {
            $out['items'][] = [$r['name'], $r['symbol'] ?: '—', $r['type']];
        }
        $out['count']     = count($rows);
        $out['fix_label'] = 'Manage Investments';
        $out['fix_url']   = BASE_PATH . '/portfolio/index';
        break;

    // ── 5. Duplicate transactions ──────────────────────────────
    case 'duplicate_transactions':
        $rows = $db->query(
            "SELECT a.name AS account_name, t.transaction_date, t.payee,
                    t.amount, COUNT(*) AS cnt,
                    GROUP_CONCAT(t.id ORDER BY t.id) AS ids
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE t.amount <> 0
               AND t.cleared_status != 'reconciled'
               AND NOT (t.cleared_status = 'cleared' AND a.type = 'Investment' AND a.is_investment_cash = 0)
             GROUP BY t.account_id, t.transaction_date, t.payee, t.amount, t.type
             HAVING cnt > 1
             ORDER BY cnt DESC, t.transaction_date DESC
             LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns']     = ['Date', 'Account', 'Payee', 'Amount', 'Copies', 'IDs'];
        $out['txn_link_col'] = 5;
        foreach ($rows as $r) {
            $out['items'][] = [
                $r['transaction_date'],
                $r['account_name'],
                $r['payee'],
                number_format((float)$r['amount'], 2),
                (int)$r['cnt'],
                $r['ids'],
            ];
        }
        $out['count'] = count($rows);
        break;

    // ── 6. Split amount mismatch ───────────────────────────────
    case 'split_mismatch':
        $rows = $db->query(
            "SELECT t.id, t.transaction_date, t.payee,
                    t.amount AS txn_amount, SUM(ts.amount) AS split_total,
                    a.name AS account_name
             FROM transactions t
             JOIN transaction_splits ts ON ts.transaction_id = t.id
             JOIN accounts a ON a.id = t.account_id
             WHERE t.type NOT IN ('transfer')
             GROUP BY t.id, t.transaction_date, t.payee, t.amount, a.name
             HAVING ABS(SUM(ts.amount) - t.amount) > 0.005
             ORDER BY t.transaction_date DESC
             LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns'] = ['Date', 'Account', 'Payee', 'Txn Amount', 'Split Total', 'Difference'];
        foreach ($rows as $r) {
            $diff = (float)$r['split_total'] - (float)$r['txn_amount'];
            $out['items'][] = [
                $r['transaction_date'],
                $r['account_name'],
                $r['payee'],
                number_format((float)$r['txn_amount'],   2),
                number_format((float)$r['split_total'],  2),
                number_format($diff, 2),
            ];
        }
        $out['count'] = count($rows);
        break;

    // ── 7. Transactions with inactive category ─────────────────
    case 'orphaned_categories':
        $rows = $db->query(
            "SELECT t.id, t.transaction_date, t.payee,
                    a.name AS account_name, c.name AS category_name
             FROM transaction_splits ts
             JOIN transactions t ON t.id = ts.transaction_id
             JOIN accounts a ON a.id = t.account_id
             JOIN categories c ON c.id = ts.category_id
             WHERE c.is_active = 0
             ORDER BY t.transaction_date DESC
             LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns'] = ['Date', 'Account', 'Payee', 'Inactive Category'];
        foreach ($rows as $r) {
            $out['items'][] = [
                $r['transaction_date'],
                $r['account_name'],
                $r['payee'],
                $r['category_name'],
            ];
        }
        $out['count']     = count($rows);
        $out['fix_label'] = 'Manage Categories';
        $out['fix_url']   = BASE_PATH . '/categories/index';
        break;

    // ── 8. Unmatched transfers ─────────────────────────────────
    case 'unmatched_transfers':
        $rows = $db->query(
            "SELECT t.id, t.transaction_date, t.payee, t.amount, a.name AS account_name
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE t.type = 'transfer'
               AND NOT EXISTS (
                 SELECT 1 FROM transfers tr
                 WHERE tr.from_transaction_id = t.id
                    OR tr.to_transaction_id   = t.id
               )
               AND (
                 t.transfer_pair_id IS NULL
                 OR NOT EXISTS (
                   SELECT 1 FROM transactions pt WHERE pt.id = t.transfer_pair_id
                 )
               )
             ORDER BY t.transaction_date DESC
             LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns'] = ['Date', 'Account', 'Payee', 'Amount'];
        foreach ($rows as $r) {
            $out['items'][] = [
                $r['transaction_date'],
                $r['account_name'],
                $r['payee'],
                number_format((float)$r['amount'], 2),
            ];
        }
        $out['count'] = count($rows);
        break;

    // ── 8b. Link orphaned transfer pairs (preview) ────────────
    case 'link_orphaned_transfers':
    case 'link_orphaned_transfers_apply':
        // Fetch all truly orphaned transfers
        $orphaned = $db->query(
            "SELECT t.id, t.transaction_date, t.amount, t.account_id, t.payee, a.name AS account_name
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE t.type = 'transfer'
               AND NOT EXISTS (
                 SELECT 1 FROM transfers tr
                 WHERE tr.from_transaction_id = t.id OR tr.to_transaction_id = t.id
               )
               AND (
                 t.transfer_pair_id IS NULL
                 OR NOT EXISTS (SELECT 1 FROM transactions pt WHERE pt.id = t.transfer_pair_id)
               )
             ORDER BY t.transaction_date DESC, ABS(t.amount) DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Group by date + abs(amount); find unambiguous pairs
        $groups = [];
        foreach ($orphaned as $row) {
            $key = $row['transaction_date'] . '|' . number_format(abs((float)$row['amount']), 2, '.', '');
            $groups[$key][] = $row;
        }
        $pairs = [];
        foreach ($groups as $group) {
            if (count($group) !== 2) continue;
            [$a, $b] = $group;
            if ($a['account_id'] === $b['account_id']) continue;
            if (abs((float)$a['amount'] + (float)$b['amount']) > 0.005) continue;
            $pairs[] = (float)$a['amount'] < 0
                ? ['debit' => $a, 'credit' => $b]
                : ['debit' => $b, 'credit' => $a];
        }

        if ($check === 'link_orphaned_transfers_apply') {
            // Perform the linking
            $db->beginTransaction();
            try {
                foreach ($pairs as $pair) {
                    $dId = (int)$pair['debit']['id'];
                    $cId = (int)$pair['credit']['id'];
                    $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?')->execute([$cId, $dId]);
                    $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?')->execute([$dId, $cId]);
                    $db->prepare('INSERT IGNORE INTO transfers (from_transaction_id, to_transaction_id) VALUES (?, ?)')->execute([$dId, $cId]);
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                logActivity('maintenance_error', 'Link orphaned transfers failed: ' . $e->getMessage());
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $n = count($pairs);
            logActivity('maintenance_apply', "Linked {$n} orphaned transfer pair" . ($n !== 1 ? 's' : ''));
            $out['count']   = $n;
            $out['message'] = "Linked {$n} transfer pair" . ($n !== 1 ? 's' : '') . " successfully.";
            break;
        }

        // Preview mode
        $out['columns']   = ['Date', 'Debit Account', 'Credit Account', 'Debit Payee', 'Credit Payee', 'Amount'];
        $out['count']     = count($pairs);
        $out['fix_action'] = 'link_orphaned_transfers_apply';
        $out['fix_label']  = 'Link All Pairs';
        foreach (array_slice($pairs, 0, 200) as $pair) {
            $out['items'][] = [
                $pair['debit']['transaction_date'],
                $pair['debit']['account_name'],
                $pair['credit']['account_name'],
                $pair['debit']['payee'],
                $pair['credit']['payee'],
                number_format(abs((float)$pair['debit']['amount']), 2),
            ];
        }
        $out['fix_confirm'] = 'Automatically link all matched transfer pairs? This will modify database records.';
        break;

    // ── 8c. Orphaned securities — leftover price history on zero-transaction,
    //        unwatchlisted securities (preview + purge) ──
    // Money markets are excluded: sweep cash is recorded as ordinary transactions in the
    // investment-cash sub-account ledger, so a money-market security record structurally
    // never has investment activity — flagging it would be pure noise.
    // Only securities that still have price rows to purge are reported here; a
    // zero-transaction security with no accumulated price history has nothing this check
    // can act on and is already visible any time via Portfolio → Show Unowned, so it's
    // deliberately left out to avoid reporting a permanent, unfixable "issue".
    case 'orphaned_securities':
    case 'orphaned_securities_apply':
        $orphans = $db->query(
            "SELECT * FROM (
                 SELECT i.id, i.symbol, i.name, i.type, i.is_active,
                        (SELECT COUNT(*) FROM investment_prices ip WHERE ip.investment_id = i.id) AS price_rows
                 FROM investments i
                 WHERE i.in_watchlist = 0
                   AND i.type NOT IN ('Index', 'Money Market')
                   AND NOT EXISTS (SELECT 1 FROM investment_transactions it WHERE it.investment_id = i.id)
             ) orphan_candidates
             WHERE price_rows > 0
             ORDER BY symbol"
        )->fetchAll(PDO::FETCH_ASSOC);

        $totalPriceRows = (int)array_sum(array_column($orphans, 'price_rows'));

        if ($check === 'orphaned_securities_apply') {
            // array_column() returns a fresh sequential array — safe to pass straight to
            // execute() (an array_filter()+array_map() pipeline here would preserve the
            // original keys and trip PDO's "Invalid parameter number" on a non-sequential array).
            $ids     = array_column($orphans, 'id');
            $deleted = 0;
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $del = $db->prepare("DELETE FROM investment_prices WHERE investment_id IN ($placeholders)");
                $del->execute($ids);
                $deleted = $del->rowCount();
            }
            logActivity('maintenance_apply', "Purged {$deleted} orphaned security price row" . ($deleted !== 1 ? 's' : ''));
            $out['count']   = $deleted;
            $out['message'] = 'Purged ' . number_format($deleted) . ' price row' . ($deleted !== 1 ? 's' : '')
                             . '. Security records were kept — deactivate any that are truly junk from Portfolio → Show Unowned.';
            break;
        }

        $out['columns'] = ['Security', 'Symbol', 'Type', 'Status', 'Price Rows'];
        foreach ($orphans as $r) {
            $out['items'][] = [
                $r['name'],
                $r['symbol'] ?: '—',
                $r['type'],
                $r['is_active'] ? 'Active' : 'Inactive',
                (int)$r['price_rows'],
            ];
        }
        $out['count'] = count($orphans);
        if ($totalPriceRows > 0) {
            $out['fix_action']  = 'orphaned_securities_apply';
            $out['fix_label']   = 'Purge ' . number_format($totalPriceRows) . ' Price Row' . ($totalPriceRows !== 1 ? 's' : '');
            $out['fix_icon']    = 'bi-eraser';
            $out['fix_confirm'] = 'Purge ' . number_format($totalPriceRows) . ' orphaned price row'
                                 . ($totalPriceRows !== 1 ? 's' : '') . '? Reports are unaffected — these securities '
                                 . 'have no transactions, so their price history is unreachable. Security records are kept.';
        }
        break;

    // ── 9. Budget items → inactive category ───────────────────
    case 'budget_inactive_categories':
        $rows = $db->query(
            "SELECT b.name AS budget_name, c.name AS category_name, bc.amount
             FROM budget_categories bc
             JOIN budgets b ON b.id = bc.budget_id
             JOIN categories c ON c.id = bc.category_id
             WHERE b.is_active = 1 AND c.is_active = 0
             ORDER BY c.name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns'] = ['Budget', 'Category', 'Budgeted Amount'];
        foreach ($rows as $r) {
            $out['items'][] = [
                $r['budget_name'],
                $r['category_name'],
                number_format((float)$r['amount'], 2),
            ];
        }
        $out['count']     = count($rows);
        $out['fix_label'] = 'Manage Budget';
        $out['fix_url']   = BASE_PATH . '/budget/index';
        break;

    // ── 10. Bills linked to inactive accounts ──────────────────
    case 'bills_inactive_accounts':
        $rows = $db->query(
            "SELECT sb.name AS bill_name, sb.type, a.name AS account_name
             FROM scheduled_bills sb
             JOIN accounts a ON a.id = sb.account_id
             WHERE sb.is_active = 1 AND a.is_active = 0
             ORDER BY sb.name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns'] = ['Bill / Deposit', 'Type', 'Inactive Account'];
        foreach ($rows as $r) {
            $out['items'][] = [$r['bill_name'], ucfirst($r['type']), $r['account_name']];
        }
        $out['count']     = count($rows);
        $out['fix_label'] = 'Manage Bills';
        $out['fix_url']   = BASE_PATH . '/bills/index';
        break;

    // ── 11. Orphaned investment transactions ───────────────────
    case 'orphaned_investment_txns':
        $rows = $db->query(
            "SELECT it.id, t.transaction_date, t.payee, it.activity,
                    t.amount, a.name AS account_name
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             JOIN accounts a ON a.id = t.account_id
             WHERE it.investment_id IS NULL
             ORDER BY t.transaction_date DESC
             LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns'] = ['Date', 'Account', 'Payee', 'Activity', 'Amount'];
        foreach ($rows as $r) {
            $out['items'][] = [
                $r['transaction_date'],
                $r['account_name'],
                $r['payee'],
                ucfirst($r['activity']),
                number_format((float)$r['amount'], 2),
            ];
        }
        $out['count'] = count($rows);
        break;

    // ── 12. Categorized income in investment-cash accounts ─────
    case 'categorized_investment_income':
        $rows = $db->query(
            "SELECT t.id, t.account_id, t.transaction_date, t.payee, c.name AS category_name,
                    t.amount, a.name AS account_name
             FROM transaction_splits ts
             JOIN transactions t ON t.id = ts.transaction_id
             JOIN accounts a ON a.id = t.account_id
             LEFT JOIN accounts la ON la.id = a.linked_account_id
             JOIN categories c ON c.id = ts.category_id
             WHERE a.is_investment_cash = 1
               AND (la.id IS NULL OR la.type != 'Crypto')
               AND EXISTS (
                 SELECT 1 FROM investments i WHERE i.name = t.payee AND i.is_active = 1
               )
             ORDER BY t.transaction_date DESC
             LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns']       = ['Date', 'Account', 'Payee', 'Category', 'Amount'];
        $out['acct_link_col'] = 1;
        $out['acct_id_col']   = 5;
        $out['txn_id_col']    = 6;
        foreach ($rows as $r) {
            $out['items'][] = [
                $r['transaction_date'],
                $r['account_name'],
                $r['payee'],
                $r['category_name'],
                number_format((float)$r['amount'], 2),
                (int)$r['account_id'], // hidden — used for register link
                (int)$r['id'],         // hidden — used for register link
            ];
        }
        $out['count'] = count($rows);
        break;

    // ── S1. Default credentials in use ────────────────────────
    case 'sec_default_passwords':
        $knownDefaults = ['Admin123!', 'John123!', 'View123!'];
        $users = $db->query(
            "SELECT username, role, password_hash FROM users WHERE is_active = 1 ORDER BY username"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out['columns'] = ['Username', 'Role'];
        foreach ($users as $u) {
            foreach ($knownDefaults as $pwd) {
                if (password_verify($pwd, $u['password_hash'] ?? '')) {
                    $out['items'][] = [$u['username'], $u['role']];
                    break;
                }
            }
        }
        $out['count']     = count($out['items']);
        $out['fix_label'] = 'Manage Users';
        $out['fix_url']   = BASE_PATH . '/users/index';
        break;

    // ── S2. Setup directory still present ─────────────────────
    case 'sec_setup_dir':
        $setupPath = __DIR__ . '/../setup';
        if (is_dir($setupPath)) {
            $out['count']   = 1;
            $out['columns'] = ['Path', 'Risk'];
            $out['items'][] = ['setup/', 'App can be re-initialized by anyone who can reach this URL'];
        }
        break;

    // ── S3. PHP display_errors enabled ────────────────────────
    case 'sec_php_display_errors':
        $val = ini_get('display_errors');
        $on  = $val !== '' && $val !== '0' && strtolower((string)$val) !== 'off';
        if ($on) {
            $out['count']   = 1;
            $out['columns'] = ['php.ini Setting', 'Current Value'];
            $out['items'][] = ['display_errors', (string)$val];
        }
        break;

    // ── S4. Debug / test files present ────────────────────────
    case 'sec_debug_files':
        $base      = realpath(__DIR__ . '/..');
        $dangerous = [
            'phpinfo.php', 'info.php', 'test.php', 'debug.php',
            'adminer.php', 'adminer/', 'phpmyadmin/', '.env',
        ];
        $out['columns'] = ['File', 'Type'];
        foreach ($dangerous as $f) {
            $full = $base . '/' . $f;
            if (file_exists($full)) {
                $out['items'][] = [$f, is_dir($full) ? 'directory' : 'file'];
            }
        }
        $out['count'] = count($out['items']);
        break;

    // ── S5. Session timeout set to Never ──────────────────────
    case 'sec_session_timeout':
        $minutes = (int)getSetting('session_timeout_minutes', '0');
        $timeoutLabels = [
            0 => 'Never (disabled)', 15 => '15 minutes', 30 => '30 minutes',
            60 => '1 hour', 120 => '2 hours', 240 => '4 hours',
            480 => '8 hours', 1440 => '24 hours',
        ];
        $label = $timeoutLabels[$minutes] ?? $minutes . ' minutes';
        if ($minutes === 0) {
            $out['count']     = 1;
            $out['columns']   = ['Setting', 'Current Value', 'Change At'];
            $out['items'][]   = ['Session Timeout', $label, 'Settings → Preferences'];
            $out['fix_label'] = 'Open Preferences';
            $out['fix_url']   = BASE_PATH . '/settings/preferences';
        }
        break;

    // ── S6. Activity log retention (informational) ─────────────
    case 'sec_log_retention':
        $days = (int)getSetting('log_retention_days', '90');
        $retentionLabels = [
            30 => '30 days', 90 => '90 days', 180 => '6 months',
            365 => '1 year', 0 => 'Forever',
        ];
        $label = $retentionLabels[$days] ?? $days . ' days';
        $out['count']     = 1;
        $out['info_only'] = true;
        $out['columns']   = ['Setting', 'Current Value', 'Change At'];
        $out['items'][]   = ['Activity Log Retention', $label, 'Settings → Preferences'];
        $out['fix_label'] = 'Open Preferences';
        $out['fix_url']   = BASE_PATH . '/settings/preferences';
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown check']);
        exit;
}

echo json_encode($out);
