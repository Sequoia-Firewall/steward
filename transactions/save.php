<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('user', 'administrator');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

verifyCsrf();

$db         = getDB();
$accountId  = (int)($_POST['account_id'] ?? 0);
$txnId      = (int)($_POST['txn_id']     ?? 0);  // 0 = new
$type       = $_POST['type'] ?? '';

if (!in_array($type, ['withdrawal', 'deposit', 'transfer', 'investment'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid transaction type']);
    exit;
}

$account = getAccount($accountId);
if (!$account) {
    echo json_encode(['ok' => false, 'error' => 'Invalid account']);
    exit;
}

// ── Parse fields by type ────────────────────────────────────────
$suffix = match($type) { 'withdrawal' => 'w', 'deposit' => 'd', 'transfer' => 't', 'investment' => 'd' };

$num     = trim($_POST['num_'    . $suffix] ?? '');
$date    = trim($_POST['date_'   . $suffix] ?? '');
$payee   = trim($_POST['payee_'  . $suffix] ?? '');
$memo    = trim($_POST['memo_'   . $suffix] ?? '');
$cleared = $_POST['cleared_' . $suffix] ?? '';
$rawAmt  = (float)($_POST['amount_' . $suffix] ?? 0);


if (!$date || !strtotime($date)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date']);
    exit;
}

// Validate cleared status
if (!in_array($cleared, ['', 'cleared', 'reconciled'])) $cleared = '';
// Only admin can mark as reconciled
if ($cleared === 'reconciled' && !isAdmin()) $cleared = 'cleared';
// Non-admin cannot change reconciled transactions
if ($txnId) {
    $existing = $db->prepare('SELECT * FROM transactions WHERE id = ?');
    $existing->execute([$txnId]);
    $existingTxn = $existing->fetch();
    if ($existingTxn && $existingTxn['cleared_status'] === 'reconciled') {
        if (!isAdmin()) {
            echo json_encode(['ok' => false, 'error' => 'Cannot edit reconciled transactions']);
            exit;
        }
        if ($type !== 'investment' && abs(abs((float)$existingTxn['amount']) - $rawAmt) > 0.005) {
            echo json_encode(['ok' => false, 'error' => 'Cannot change the amount of a reconciled transaction']);
            exit;
        }
    }
    // The cash-side leg of a buy/sell/div/int/reinvest is a reciprocal of an investment-side
    // transaction — it must only be edited from the security register, not the cash register.
    if ($existingTxn && getInvestmentPairInfo($existingTxn['transfer_pair_id'])) {
        echo json_encode(['ok' => false, 'error' => 'This transaction is linked to a security-register entry — edit it from the investment account register instead.']);
        exit;
    }
}

// ── Duplicate check/reference number warning ───────────────────
$confirmDupNum = $_POST['confirm_duplicate_num'] ?? '';
if ($num !== '' && $type !== 'investment' && $confirmDupNum !== '1') {
    $dupStmt = $db->prepare(
        'SELECT id FROM transactions WHERE account_id = ? AND num = ? AND id != ? LIMIT 1'
    );
    $dupStmt->execute([$accountId, $num, $txnId ?: 0]);
    if ($dupStmt->fetchColumn()) {
        echo json_encode([
            'ok'                    => false,
            'confirm_duplicate_num' => [
                'num'     => $num,
                'account' => $account['name'],
            ],
        ]);
        exit;
    }
}

// ── Scheduled bill match (new transactions only) ───────────────
$skipBillId = (int)($_POST['skip_bill_id'] ?? -1); // -1 = not provided, 0 = user declined, >0 = confirm skip

if (!$txnId && $type !== 'investment' && $payee !== '' && $skipBillId === -1) {
    $billCheck = $db->prepare(
        'SELECT id, name, amount, next_due_date, frequency
         FROM scheduled_bills
         WHERE LOWER(name) = LOWER(?)
           AND account_id = ?
           AND is_active = 1
           AND next_due_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                                 AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY next_due_date ASC
         LIMIT 1'
    );
    $billCheck->execute([$payee, $accountId]);
    $matchedBill = $billCheck->fetch();
    if ($matchedBill) {
        echo json_encode([
            'ok'           => false,
            'confirm_bill' => [
                'id'       => (int)$matchedBill['id'],
                'name'     => $matchedBill['name'],
                'amount'   => (float)$matchedBill['amount'],
                'next_due' => $matchedBill['next_due_date'],
                'freq'     => $matchedBill['frequency'],
                'account'  => $account['name'],
            ],
        ]);
        exit;
    }
}

// ── Investment transaction (full handling) ─────────────────────
if ($type === 'investment') {
    $activity     = $_POST['inv_activity']          ?? 'buy';
    $qty          = abs((float)($_POST['inv_qty']          ?? 0));
    $price        = abs((float)($_POST['inv_price']        ?? 0));
    $commission   = abs((float)($_POST['inv_commission']   ?? 0));
    $costBasis    = abs((float)($_POST['inv_cost_basis']   ?? 0));
    $cashAcctId   = (int)($_POST['inv_cash_account_id']    ?? 0);
    $cashAcctToId = (int)($_POST['inv_cash_account_to_id'] ?? 0);

    // For "add", store per-share cost basis derived from total cost basis ÷ quantity
    if ($activity === 'add' && $qty > 0 && $costBasis > 0) {
        $price = $costBasis / $qty;
    }

    if (!in_array($activity, ['buy','sell','add','remove','split','reinvest_div','reinvest_cap','div','int'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid activity']); exit;
    }

    $incomeAmount = abs((float)($_POST['inv_income_amount'] ?? 0));

    // Buy/sell cash cost only has somewhere real to go if this account has an
    // actual linked cash sub-account — the "Transfer From/To Cash" dropdown
    // always submits *some* account id even when there's no real pairing, so
    // that POSTed id can't be trusted as a signal here.
    $hasLinkedCash = !empty($account['linked_account_id']);

    // Amount stored in the investment account.
    // add/remove only change share count — no cash, no cost basis amount.
    $invAmount = match($activity) {
        'buy'          =>  $hasLinkedCash ? ($qty * $price + $commission) : 0.0,
        'reinvest_div' =>  $qty * $price + $commission,
        'reinvest_cap' =>  $qty * $price + $commission,
        'sell'         =>  $hasLinkedCash ? -($qty * $price - $commission) : 0.0,
        'div', 'int'   =>  $incomeAmount,
        default        => 0.0,
    };

    // Resolve investment_id from payee name; prefer an active match but fall back to a
    // deactivated one so recording new activity revives it instead of creating a duplicate.
    $invLookup = $db->prepare('SELECT id, is_active FROM investments WHERE name = ? ORDER BY is_active DESC, id LIMIT 1');
    $invLookup->execute([$payee]);
    $investmentId = null;
    $reviveInvId  = null;
    if ($row = $invLookup->fetch()) {
        $investmentId = (int)$row['id'];
        if (!(int)$row['is_active']) $reviveInvId = $investmentId;
    }

    if ($investmentId === null && !in_array($activity, ['sell', 'remove'])) {
        $db->prepare('INSERT INTO investments (name, type, created_by) VALUES (?, \'Stock\', ?)')->execute([$payee, currentUserId()]);
        $investmentId = (int)$db->lastInsertId();
    }

    // Validate sell/remove quantity against current holdings
    if ($activity === 'sell' || $activity === 'remove') {
        if ($investmentId === null) {
            $verb = $activity === 'sell' ? 'sell' : 'remove';
            echo json_encode(['ok' => false, 'error' => 'Investment not found in portfolio. Cannot process ' . $verb . '.']);
            exit;
        }
        // Exclude the transaction being edited so its original qty doesn't double-count
        $holdStmt = $db->prepare(
            'SELECT COALESCE(SUM(
                CASE it.activity
                    WHEN \'buy\'          THEN  it.quantity
                    WHEN \'add\'          THEN  it.quantity
                    WHEN \'split\'        THEN  it.quantity
                    WHEN \'reinvest_div\' THEN  it.quantity
                    WHEN \'reinvest_cap\' THEN  it.quantity
                    WHEN \'sell\'         THEN -it.quantity
                    WHEN \'remove\'       THEN -it.quantity
                    ELSE 0
                END
             ), 0)
             FROM investment_transactions it
             JOIN transactions t ON t.id = it.transaction_id
             WHERE t.account_id = ? AND it.investment_id = ? AND t.id != ?'
        );
        $holdStmt->execute([$accountId, $investmentId, $txnId ?: 0]);
        $currentHolding = (float)$holdStmt->fetchColumn();
        if ($qty > $currentHolding + 0.000001) {
            $verb = $activity === 'sell' ? 'sell' : 'remove';
            echo json_encode(['ok' => false, 'error' => sprintf(
                'Cannot %s %s shares of "%s" — current holding: %s shares.',
                $verb,
                rtrim(rtrim(number_format($qty, 6), '0'), '.'),
                $payee,
                rtrim(rtrim(number_format($currentHolding, 6), '0'), '.')
            )]);
            exit;
        }
    }

    if ($txnId && isset($existingTxn) && $existingTxn && $existingTxn['cleared_status'] === 'reconciled') {
        if (abs((float)$existingTxn['amount'] - $invAmount) > 0.005) {
            echo json_encode(['ok' => false, 'error' => 'Cannot change the amount of a reconciled transaction']);
            exit;
        }
    }

    $db->beginTransaction();
    try {
        if ($reviveInvId) {
            $db->prepare('UPDATE investments SET is_active = 1 WHERE id = ?')->execute([$reviveInvId]);
        }

        if ($txnId) {
            // ── Edit ────────────────────────────────────────────
            $db->prepare(
                'UPDATE transactions SET transaction_date=?, payee=?, amount=?, cleared_status=?, memo=?, updated_at=NOW() WHERE id=?'
            )->execute([$date, $payee, $invAmount, $cleared, $memo, $txnId]);

            $existCheck = $db->prepare('SELECT id FROM investment_transactions WHERE transaction_id=?');
            $existCheck->execute([$txnId]);
            if ($existCheck->fetchColumn()) {
                $db->prepare(
                    'UPDATE investment_transactions SET investment_id=?, activity=?, quantity=?, price=?, commission=? WHERE transaction_id=?'
                )->execute([$investmentId, $activity, $qty, $price, $commission, $txnId]);
            } else {
                $db->prepare(
                    'INSERT INTO investment_transactions (transaction_id,investment_id,activity,quantity,price,commission) VALUES (?,?,?,?,?,?)'
                )->execute([$txnId, $investmentId, $activity, $qty, $price, $commission]);
            }

            // Update or create paired cash transaction
            $pairRow = $db->prepare('SELECT transfer_pair_id FROM transactions WHERE id=?');
            $pairRow->execute([$txnId]);
            $pairId = (int)($pairRow->fetchColumn() ?? 0);
            if ($pairId && in_array($activity, ['buy','sell'])) {
                $cashAmount = ($activity === 'buy') ? -abs($invAmount) : abs($invAmount);
                $db->prepare(
                    'UPDATE transactions SET transaction_date=?, payee=?, amount=?, cleared_status=?, memo=?, updated_at=NOW() WHERE id=?'
                )->execute([$date, $payee, $cashAmount, $cleared, $memo, $pairId]);
            } elseif ($pairId && in_array($activity, ['div', 'int'])) {
                $db->prepare(
                    'UPDATE transactions SET transaction_date=?, payee=?, amount=?, cleared_status=?, memo=?, updated_at=NOW() WHERE id=?'
                )->execute([$date, $payee, abs($invAmount), $cleared, $memo, $pairId]);
            } elseif (!$pairId && in_array($activity, ['div', 'int']) && $cashAcctId) {
                $db->prepare(
                    'INSERT INTO transactions (account_id,transaction_date,payee,type,amount,cleared_status,memo,transfer_pair_id,created_by)
                     VALUES (?,?,?,\'deposit\',?,?,?,?,?)'
                )->execute([$cashAcctId, $date, $payee, abs($invAmount), $cleared, $memo, $txnId, currentUserId()]);
                $cashTxnId = (int)$db->lastInsertId();
                $db->prepare('UPDATE transactions SET transfer_pair_id=? WHERE id=?')->execute([$cashTxnId, $txnId]);
            } elseif (!$pairId && $activity === 'buy' && $cashAcctId && $hasLinkedCash) {
                $db->prepare(
                    'INSERT INTO transactions (account_id,transaction_date,payee,type,amount,cleared_status,memo,transfer_pair_id,created_by)
                     VALUES (?,?,?,\'transfer\',?,?,?,?,?)'
                )->execute([$cashAcctId, $date, $payee, -abs($invAmount), $cleared, $memo, $txnId, currentUserId()]);
                $cashTxnId = (int)$db->lastInsertId();
                $db->prepare('UPDATE transactions SET transfer_pair_id=? WHERE id=?')->execute([$cashTxnId, $txnId]);
            } elseif (!$pairId && $activity === 'sell' && $cashAcctToId && $hasLinkedCash) {
                $db->prepare(
                    'INSERT INTO transactions (account_id,transaction_date,payee,type,amount,cleared_status,memo,transfer_pair_id,created_by)
                     VALUES (?,?,?,\'transfer\',?,?,?,?,?)'
                )->execute([$cashAcctToId, $date, $payee, abs($invAmount), $cleared, $memo, $txnId, currentUserId()]);
                $cashTxnId = (int)$db->lastInsertId();
                $db->prepare('UPDATE transactions SET transfer_pair_id=? WHERE id=?')->execute([$cashTxnId, $txnId]);
            }
        } else {
            // ── New ─────────────────────────────────────────────
            $manFitid = 'MAN:' . sha1(implode('|', [$accountId, $date, $payee, number_format($invAmount, 2, '.', ''), $activity, microtime(true)]));
            $db->prepare(
                'INSERT INTO transactions (account_id,transaction_date,payee,type,amount,cleared_status,memo,fitid,created_by)
                 VALUES (?,?,?,\'investment\',?,?,?,?,?)'
            )->execute([$accountId, $date, $payee, $invAmount, $cleared, $memo, $manFitid, currentUserId()]);
            $savedId = (int)$db->lastInsertId();

            $db->prepare(
                'INSERT INTO investment_transactions (transaction_id,investment_id,activity,quantity,price,commission) VALUES (?,?,?,?,?,?)'
            )->execute([$savedId, $investmentId, $activity, $qty, $price, $commission]);

            // Paired cash transaction for buy/sell/div/int
            if ($activity === 'buy' && $cashAcctId && $hasLinkedCash) {
                $db->prepare(
                    'INSERT INTO transactions (account_id,transaction_date,payee,type,amount,cleared_status,memo,transfer_pair_id,created_by)
                     VALUES (?,?,?,\'transfer\',?,?,?,?,?)'
                )->execute([$cashAcctId, $date, $payee, -abs($invAmount), $cleared, $memo, $savedId, currentUserId()]);
                $cashTxnId = (int)$db->lastInsertId();
                $db->prepare('UPDATE transactions SET transfer_pair_id=? WHERE id=?')->execute([$cashTxnId, $savedId]);
            } elseif ($activity === 'sell' && $cashAcctToId && $hasLinkedCash) {
                $db->prepare(
                    'INSERT INTO transactions (account_id,transaction_date,payee,type,amount,cleared_status,memo,transfer_pair_id,created_by)
                     VALUES (?,?,?,\'transfer\',?,?,?,?,?)'
                )->execute([$cashAcctToId, $date, $payee, abs($invAmount), $cleared, $memo, $savedId, currentUserId()]);
                $cashTxnId = (int)$db->lastInsertId();
                $db->prepare('UPDATE transactions SET transfer_pair_id=? WHERE id=?')->execute([$cashTxnId, $savedId]);
            } elseif (in_array($activity, ['div', 'int']) && $cashAcctId) {
                $db->prepare(
                    'INSERT INTO transactions (account_id,transaction_date,payee,type,amount,cleared_status,memo,transfer_pair_id,created_by)
                     VALUES (?,?,?,\'deposit\',?,?,?,?,?)'
                )->execute([$cashAcctId, $date, $payee, abs($invAmount), $cleared, $memo, $savedId, currentUserId()]);
                $cashTxnId = (int)$db->lastInsertId();
                $db->prepare('UPDATE transactions SET transfer_pair_id=? WHERE id=?')->execute([$cashTxnId, $savedId]);
            }
        }

        $db->commit();
        $logId  = $txnId ?: $savedId;
        $action = $txnId ? 'txn_edited' : 'txn_created';
        $desc   = sprintf('%s #%d — %s %s on %s in %s',
            $txnId ? 'Edited' : 'Created', $logId,
            ucfirst($activity), $payee, $date, $account['name']);
        logActivity($action, $desc);
        echo json_encode(['ok' => true, 'reload' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Signed amount ───────────────────────────────────────────────
$amount = match($type) {
    'withdrawal' => -abs($rawAmt),
    'deposit'    =>  abs($rawAmt),
    'transfer'   =>  abs($rawAmt), // set per-account below
    default      =>  0,
};

// ── Splits ──────────────────────────────────────────────────────
$splitsRaw   = [];
$isSplit     = false;
$catId       = (int)($_POST['category_'    . $suffix] ?? 0);
$subcatId    = (int)($_POST['subcategory_' . $suffix] ?? 0);

// Check for split rows
for ($i = 0; $i < 20; $i++) {
    $sc = $_POST["split_cat_{$suffix}_{$i}"] ?? null;
    if ($sc === null) break;
    $splitsRaw[] = [
        'category_id'    => (int)$sc,
        'subcategory_id' => (int)($_POST["split_subcat_{$suffix}_{$i}"] ?? 0),
        'amount'         => (float)($_POST["split_amount_{$suffix}_{$i}"] ?? 0),
        'memo'           => trim($_POST["split_memo_{$suffix}_{$i}"] ?? ''),
    ];
}
if (count($splitsRaw) > 1) $isSplit = true;

if ($isSplit) {
    $splitSum = round(array_sum(array_column($splitsRaw, 'amount')), 2);
    if (abs($splitSum - round(abs($rawAmt), 2)) > 0.005) {
        echo json_encode(['ok' => false, 'error' =>
            sprintf('Split amounts ($%.2f) must equal the transaction amount ($%.2f).', $splitSum, abs($rawAmt))
        ]);
        exit;
    }
}

$db->beginTransaction();
try {
    if ($type === 'transfer') {
        // ── Transfer ────────────────────────────────────────────
        $fromId = (int)($_POST['from_account'] ?? 0);
        $toId   = (int)($_POST['to_account']   ?? 0);

        if ($fromId === $toId) throw new Exception('From and To accounts must be different');
        $fromAcct = getAccount($fromId);
        $toAcct   = getAccount($toId);
        if (!$fromAcct || !$toAcct) throw new Exception('Invalid transfer accounts');
        if (!isCashAccount($fromAcct['type'])) throw new Exception('Transfers can only be made between cash accounts (Checking, Savings, Credit Card).');
        if (!isCashAccount($toAcct['type']))   throw new Exception('Transfers can only be made between cash accounts (Checking, Savings, Credit Card).');

        if ($txnId) {
            // Editing existing transfer — find the pair via transfers table first
            $existPair = $db->prepare(
                'SELECT tr.from_transaction_id, tr.to_transaction_id FROM transfers tr
                 WHERE tr.from_transaction_id = ? OR tr.to_transaction_id = ?'
            );
            $existPair->execute([$txnId, $txnId]);
            $pair = $existPair->fetch();
            if ($pair) {
                $fromTxnId = (int)$pair['from_transaction_id'];
                $toTxnId   = (int)$pair['to_transaction_id'];
            } else {
                // Fall back to transfer_pair_id for imported/legacy transfers
                $thisTxnStmt = $db->prepare('SELECT amount, transfer_pair_id FROM transactions WHERE id = ?');
                $thisTxnStmt->execute([$txnId]);
                $thisTxnRow = $thisTxnStmt->fetch();
                $pairTxnId  = $thisTxnRow ? (int)$thisTxnRow['transfer_pair_id'] : 0;
                if ((float)($thisTxnRow['amount'] ?? 0) <= 0) {
                    $fromTxnId = $txnId;
                    $toTxnId   = $pairTxnId;
                } else {
                    $fromTxnId = $pairTxnId;
                    $toTxnId   = $txnId;
                }
            }
            // Update from transaction (debit leg)
            if ($fromTxnId) {
                $db->prepare(
                    'UPDATE transactions SET account_id=?, num=?, transaction_date=?, payee=?, amount=?, cleared_status=?, memo=? WHERE id=?'
                )->execute([$fromId, $num, $date, $payee ?: 'Transfer Out', -abs($rawAmt), $cleared, $memo, $fromTxnId]);
            }
            // Update to transaction (credit leg)
            if ($toTxnId) {
                $db->prepare(
                    'UPDATE transactions SET account_id=?, num=?, transaction_date=?, payee=?, amount=?, cleared_status=?, memo=? WHERE id=?'
                )->execute([$toId, $num, $date, $payee ?: 'Transfer In', abs($rawAmt), $cleared, $memo, $toTxnId]);
            }
            // If one leg was missing (orphaned transfer), create it now
            if ($fromTxnId && !$toTxnId) {
                $fitid = 'MAN:' . sha1(implode('|', [$toId, $date, $payee, number_format($rawAmt, 2, '.', ''), 'pair-in', microtime(true)]));
                $db->prepare(
                    'INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, fitid, transfer_pair_id, created_by)
                     VALUES (?, ?, ?, ?, \'transfer\', ?, ?, ?, ?, ?, ?)'
                )->execute([$toId, $num, $date, $payee ?: 'Transfer In', abs($rawAmt), $cleared, $memo, $fitid, $fromTxnId, currentUserId()]);
                $toTxnId = (int)$db->lastInsertId();
                $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?')->execute([$toTxnId, $fromTxnId]);
            } elseif (!$fromTxnId && $toTxnId) {
                $fitid = 'MAN:' . sha1(implode('|', [$fromId, $date, $payee, number_format($rawAmt, 2, '.', ''), 'pair-out', microtime(true)]));
                $db->prepare(
                    'INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, fitid, transfer_pair_id, created_by)
                     VALUES (?, ?, ?, ?, \'transfer\', ?, ?, ?, ?, ?, ?)'
                )->execute([$fromId, $num, $date, $payee ?: 'Transfer Out', -abs($rawAmt), $cleared, $memo, $fitid, $toTxnId, currentUserId()]);
                $fromTxnId = (int)$db->lastInsertId();
                $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?')->execute([$fromTxnId, $toTxnId]);
            }
            // Keep the transfers table in sync
            if ($fromTxnId && $toTxnId) {
                $db->prepare('INSERT IGNORE INTO transfers (from_transaction_id, to_transaction_id) VALUES (?, ?)')->execute([$fromTxnId, $toTxnId]);
            }
        } else {
            // New transfer
            $tBase   = implode('|', [$date, $payee, number_format($rawAmt, 2, '.', ''), microtime(true)]);
            $fitidOut = 'MAN:' . sha1($fromId . '|' . $tBase . '|out');
            $fitidIn  = 'MAN:' . sha1($toId   . '|' . $tBase . '|in');
            $db->prepare(
                'INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, fitid, created_by)
                 VALUES (?, ?, ?, ?, \'transfer\', ?, ?, ?, ?, ?)'
            )->execute([$fromId, $num, $date, $payee ?: 'Transfer Out', -abs($rawAmt), $cleared, $memo, $fitidOut, currentUserId()]);
            $fromTxnId = (int)$db->lastInsertId();

            $db->prepare(
                'INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, fitid, transfer_pair_id, created_by)
                 VALUES (?, ?, ?, ?, \'transfer\', ?, ?, ?, ?, ?, ?)'
            )->execute([$toId, $num, $date, $payee ?: 'Transfer In', abs($rawAmt), $cleared, $memo, $fitidIn, $fromTxnId, currentUserId()]);
            $toTxnId = (int)$db->lastInsertId();

            // Update from transaction with pair id
            $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?')->execute([$toTxnId, $fromTxnId]);

            $db->prepare(
                'INSERT INTO transfers (from_transaction_id, to_transaction_id) VALUES (?, ?)'
            )->execute([$fromTxnId, $toTxnId]);
        }

        // Assign system category to both transfer legs
        $xferCatId = getSystemCategoryId('{Cash Transfer}');
        if ($xferCatId && isset($fromTxnId) && isset($toTxnId)) {
            $insSplit = $db->prepare(
                'INSERT INTO transaction_splits (transaction_id, category_id, amount, memo) VALUES (?, ?, ?, ?)'
            );
            if ($txnId) {
                // Edit: replace existing splits on both legs
                $db->prepare('DELETE FROM transaction_splits WHERE transaction_id IN (?, ?)')->execute([$fromTxnId, $toTxnId]);
            }
            $insSplit->execute([$fromTxnId, $xferCatId, -abs($rawAmt), $memo]);
            $insSplit->execute([$toTxnId,   $xferCatId,  abs($rawAmt), $memo]);
        }

        $db->commit();
        $logId  = $txnId ?: ($fromTxnId ?? 0);
        $action = $txnId ? 'txn_edited' : 'txn_created';
        $desc   = sprintf('%s #%d — Transfer %s on %s',
            $txnId ? 'Edited' : 'Created', $logId,
            $payee ? '"' . $payee . '"' : '', $date);
        logActivity($action, trim($desc));
        if ($skipBillId > 0) skipScheduledBill($db, $skipBillId, $logId);
        echo json_encode(['ok' => true, 'reload' => true]);
        exit;
    }

    // ── Withdrawal or Deposit ───────────────────────────────────
    if ($txnId) {
        $db->prepare(
            'UPDATE transactions SET num=?, transaction_date=?, payee=?, type=?, amount=?, cleared_status=?, memo=?, is_split=?, updated_at=NOW() WHERE id=?'
        )->execute([$num, $date, $payee, $type, $amount, $cleared, $memo, $isSplit ? 1 : 0, $txnId]);
        // Remove old splits
        $db->prepare('DELETE FROM transaction_splits WHERE transaction_id = ?')->execute([$txnId]);
        $savedId = $txnId;
    } else {
        $manFitid = 'MAN:' . sha1(implode('|', [$accountId, $date, $payee, number_format($amount, 2, '.', ''), microtime(true)]));
        $db->prepare(
            'INSERT INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, fitid, is_split, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$accountId, $num, $date, $payee, $type, $amount, $cleared, $memo, $manFitid, $isSplit ? 1 : 0, currentUserId()]);
        $savedId = (int)$db->lastInsertId();
    }

    // ── Insert splits ───────────────────────────────────────────
    if ($isSplit) {
        $insertSplit = $db->prepare(
            'INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount, memo) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($splitsRaw as $sp) {
            $insertSplit->execute([
                $savedId,
                $sp['category_id']    ?: null,
                $sp['subcategory_id'] ?: null,
                ($type === 'withdrawal') ? -abs($sp['amount']) : abs($sp['amount']),
                $sp['memo'],
            ]);
        }
    } else {
        // Single category
        $splitAmount = ($type === 'withdrawal') ? -abs($rawAmt) : abs($rawAmt);
        $db->prepare(
            'INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount, memo) VALUES (?, ?, ?, ?, ?)'
        )->execute([$savedId, $catId ?: null, $subcatId ?: null, $splitAmount, $memo]);
    }

    $db->commit();
    $action = $txnId ? 'txn_edited' : 'txn_created';
    $verb   = $txnId ? 'Edited' : 'Created';
    $desc   = sprintf('%s #%d — %s %s %s on %s in %s',
        $verb, $savedId, ucfirst($type),
        $payee ? '"' . $payee . '"' : '',
        formatMoney($amount), $date, $account['name']);
    logActivity($action, trim($desc));
    if ($skipBillId > 0) skipScheduledBill($db, $skipBillId, $savedId);
    echo json_encode(['ok' => true, 'reload' => true]);
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function skipScheduledBill(PDO $db, int $billId, int $txnId): void {
    $stmt = $db->prepare('SELECT * FROM scheduled_bills WHERE id = ? AND is_active = 1');
    $stmt->execute([$billId]);
    $bill = $stmt->fetch();
    if (!$bill) return;

    $nextDue = advanceDueDate($bill['next_due_date'], $bill['frequency']);
    if ($bill['frequency'] === 'once' || $nextDue === null) {
        $db->prepare('UPDATE scheduled_bills SET is_active = 0 WHERE id = ?')->execute([$billId]);
    } else {
        $db->prepare('UPDATE scheduled_bills SET next_due_date = ? WHERE id = ?')->execute([$nextDue, $billId]);
    }
    logActivity('bill_skipped', sprintf('Skipped "%s" via manual transaction #%d', $bill['name'], $txnId));
}
