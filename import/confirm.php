<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/import_helpers.php';
requireLogin();
if (!canImport()) { http_response_code(403); setFlash("error", "Access denied."); header("Location: " . BASE_PATH . "/index"); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

verifyCsrf();

if (empty($_SESSION['import']['rows'])) {
    setFlash('error', 'No import session data. Please start over.');
    header('Location: ' . BASE_PATH . '/import/index');
    exit;
}

$import     = $_SESSION['import'];
$allRows    = $import['rows'];
$accountId  = (int)$import['account_id'];
$isInv      = $import['is_investment'];
$isMulti    = $import['is_multi_account'] ?? false;
$newAcct    = $import['new_account'] ?? null;
$totalRows = (int)($_POST['total_rows'] ?? 0);
$excluded  = array_flip(array_map('intval', $_POST['excluded'] ?? []));
$selected  = $totalRows > 0
    ? array_values(array_filter(range(0, $totalRows - 1), fn($i) => !isset($excluded[$i])))
    : array_map('intval', $_POST['rows'] ?? []);   // legacy fallback

if (empty($selected)) {
    setFlash('warning', 'No transactions were selected.');
    header('Location: ' . BASE_PATH . '/import/preview');
    exit;
}

if ($isMulti) {
    $accountName = 'Multi-account import';
} elseif ($newAcct) {
    $accountName = $newAcct['name'];
} else {
    $account = getAccount($accountId);
    if (!$account) {
        setFlash('error', 'Account no longer exists.');
        header('Location: ' . BASE_PATH . '/import/index');
        exit;
    }
    $accountName = $account['name'];
}

$db       = getDB();
$imported = 0;
$skipped  = 0;

// Resolve (and auto-create) categories before the main loop; collect created names for report.
$catMap                  = $import['cat_map'] ?? [];
$createdParents          = [];
$reportCreatedCategories = [];

foreach ($catMap as $catStr => &$entry) {
    if (!$entry['is_new']) continue;
    $parentName = $entry['parent_name'];
    $subName    = $entry['sub_name'];
    $parentKey  = strtolower($parentName);

    $reportCreatedCategories[] = $entry['display'] ?? ($parentName . ($subName ? ':' . $subName : ''));

    if ($entry['cat_id'] === null && isset($createdParents[$parentKey])) {
        $entry['cat_id'] = $createdParents[$parentKey];
    }
    if ($entry['cat_id'] === null) {
        $db->prepare('INSERT INTO categories (name, parent_id, type, created_by) VALUES (?, NULL, \'expense\', ?)')->execute([$parentName, currentUserId()]);
        $entry['cat_id'] = (int)$db->lastInsertId();
        $createdParents[$parentKey] = $entry['cat_id'];
    }
    if ($subName !== null && $entry['sub_id'] === null) {
        $db->prepare('INSERT INTO categories (name, parent_id, type, created_by) VALUES (?, ?, \'expense\', ?)')->execute([$subName, $entry['cat_id'], currentUserId()]);
        $entry['sub_id'] = (int)$db->lastInsertId();
    }
    $entry['is_new'] = false;
}
unset($entry);

$db->beginTransaction();
try {
    // ── Create new account if requested ───────────────────────────────────
    $linkedCashId   = 0;
    $linkedCashName = '';
    $xAccountCache  = [];

    if ($newAcct) {
        $db->prepare(
            'INSERT INTO accounts (name, type, institution, currency, opening_balance, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        )->execute([
            $newAcct['name'],
            $newAcct['type'],
            $newAcct['institution'] ?? '',
            $newAcct['currency']    ?? 'USD',
            (float)($newAcct['opening_balance'] ?? 0),
            currentUserId(),
        ]);
        $accountId = (int)$db->lastInsertId();

        if ($newAcct['type'] === 'Investment') {
            $cashName = $newAcct['name'] . ' Cash';
            $db->prepare(
                'INSERT INTO accounts (name, type, institution, currency, opening_balance,
                                      is_investment_cash, linked_account_id, is_active, created_by)
                 VALUES (?, \'investment-cash\', ?, ?, 0, 1, ?, 1, ?)'
            )->execute([
                $cashName,
                $newAcct['institution'] ?? '',
                $newAcct['currency']    ?? 'USD',
                $accountId,
                currentUserId(),
            ]);
            $linkedCashId   = (int)$db->lastInsertId();
            $linkedCashName = $cashName;
            $db->prepare('UPDATE accounts SET linked_account_id = ? WHERE id = ?')
               ->execute([$linkedCashId, $accountId]);
        }
    } elseif ($isInv) {
        $lcStmt = $db->prepare(
            'SELECT a1.linked_account_id, a2.name AS cash_name
             FROM accounts a1 LEFT JOIN accounts a2 ON a2.id = a1.linked_account_id
             WHERE a1.id = ? AND a1.is_active = 1'
        );
        $lcStmt->execute([$accountId]);
        $lcData         = $lcStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $linkedCashId   = (int)($lcData['linked_account_id'] ?? 0);
        $linkedCashName = $lcData['cash_name'] ?? '';
    }

    // ── Report accumulators ────────────────────────────────────────────────
    $reportRows            = [];
    $reportActionTypes     = [];   // actionType → count
    $reportSecCreated      = [];   // [['name'=>..,'symbol'=>..], ...]
    $reportSecMatchedCount = 0;
    $reportCashLegs        = 0;
    $reportedSecKeys       = [];   // deduplicate securities across rows

    $investmentCache      = [];
    $linkedCashPerAcct    = [];   // accountId → [linked_cash_id, linked_cash_name] for multi-account
    $insertedBankXfers    = [];   // for post-loop transfer-pair linking on multi-account imports

    // Merge DRIP dividend + reinvestment row pairs before the main insert loop
    // (see mergeDripReinvestmentPairs() for why — avoids understated cost
    // basis and double-counted income from split-line reinvestment reports).
    $dripResult = mergeDripReinvestmentPairs($allRows, $selected, $isMulti, $isInv, $accountId);
    $selected   = $dripResult['selected'];
    foreach ($dripResult['skipped_rows'] as $skipRow) {
        $skipped++;
        $reportRows[] = $skipRow;
    }

    foreach ($selected as $rawIdx) {
        $idx = (int)$rawIdx;
        if (!array_key_exists($idx, $allRows)) {
            $skipped++;
            $reportRows[] = ['date'=>'','payee'=>'','action_type'=>'','amount'=>0.0,
                             'status'=>'skipped','reason'=>'Invalid row index',
                             'cash_account'=>'','cash_amount'=>0.0];
            continue;
        }
        $row = $allRows[$idx];

        // Per-row account resolution (multi-account imports tag each row with account_id / is_investment)
        $rowAccountId = $isMulti ? (int)($row['account_id'] ?? $accountId) : $accountId;
        $rowIsInv     = $isMulti ? ($row['is_investment'] ?? $isInv) : $isInv;

        // Resolve linked cash for this row's account
        $rowLinkedCashId   = $linkedCashId;
        $rowLinkedCashName = $linkedCashName;
        if ($isMulti && $rowIsInv && $rowAccountId > 0) {
            if (!isset($linkedCashPerAcct[$rowAccountId])) {
                $lcQ = $db->prepare(
                    'SELECT a1.linked_account_id, a2.name
                     FROM accounts a1 LEFT JOIN accounts a2 ON a2.id = a1.linked_account_id
                     WHERE a1.id = ?'
                );
                $lcQ->execute([$rowAccountId]);
                $lcD = $lcQ->fetch(PDO::FETCH_ASSOC) ?: [];
                $linkedCashPerAcct[$rowAccountId] = [(int)($lcD['linked_account_id'] ?? 0), $lcD['name'] ?? ''];
            }
            [$rowLinkedCashId, $rowLinkedCashName] = $linkedCashPerAcct[$rowAccountId];
        }

        $reportRow = [
            'date'         => $row['date']         ?? '',
            'payee'        => $row['payee']        ?? '',
            'action_type'  => $row['action_type']  ?? '',
            'amount'       => (float)($row['amount'] ?? 0),
            'status'       => 'imported',
            'reason'       => '',
            'cash_account' => '',
            'cash_amount'  => 0.0,
            'account_name' => $isMulti ? ($row['account_name'] ?? '') : '',
        ];

        if ($rowIsInv) {
            // ── Classify ──────────────────────────────────────────────────
            $actionType   = $row['action_type'] ?? '';
            $xferAcctName = trim($row['transfer_account'] ?? '');
            $activity     = $row['activity'];
            $qty          = (float)$row['quantity'];
            $price        = (float)$row['price'];
            $comm         = (float)$row['commission'];
            $total        = (float)$row['amount'];
            $rowMemo      = $row['memo'] ?? '';
            $rowDate      = $row['date'];
            $rowFitid     = $row['fitid'] ?? null;

            if ($total == 0.0) {
                $total = match($activity) {
                    'buy', 'reinvest_div', 'reinvest_cap' => -(($qty * $price) + $comm),
                    'sell'                                 =>   ($qty * $price) - $comm,
                    default                                => 0.0,
                };
            }

            // The cash side of a buy/sell only has somewhere real to post to if
            // this row's account has an actual linked cash sub-account (or, for
            // external-transfer action types, a resolvable transfer account).
            // Without one, the investment leg should carry $0, not the estimated
            // cash cost — otherwise it phantom-books directly onto the
            // investment account's own balance.
            [$needsCashCheck, $useXAcctCheck, ] = investCashRouting($actionType);
            $hasCashTarget = true;
            if ($needsCashCheck && !in_array($actionType, ['ContribX', 'WithdrwX'])) {
                if ($useXAcctCheck && $xferAcctName !== '') {
                    if (!isset($xAccountCache[$xferAcctName])) {
                        $s = $db->prepare('SELECT id FROM accounts WHERE name = ? AND is_active = 1 LIMIT 1');
                        $s->execute([$xferAcctName]);
                        $xAccountCache[$xferAcctName] = (int)($s->fetchColumn() ?: 0);
                    }
                    $hasCashTarget = (bool)$xAccountCache[$xferAcctName];
                } else {
                    $hasCashTarget = (bool)$rowLinkedCashId;
                }
            }
            $investAmount = $hasCashTarget ? $total : 0.0;

            $reportRow['amount']      = $investAmount;
            $reportRow['action_type'] = $actionType;
            if ($actionType !== '') {
                $reportActionTypes[$actionType] = ($reportActionTypes[$actionType] ?? 0) + 1;
            }

            // Pure cash action types produce no investment account record
            static $cashOnlyTypes = ['Cash','XIn','XOut','ContribX','WithdrwX','MargInt','MargIntX'];
            $skipInvRecord = in_array($actionType, $cashOnlyTypes);

            $invTxnId = null;

            // ── Investment account record ──────────────────────────────────
            if (!$skipInvRecord) {
                $symbol   = trim($row['symbol'] ?? '');
                $cusip    = trim($row['cusip']  ?? '');
                $name     = trim($row['payee']);
                $cacheKey = $symbol !== '' ? strtolower($symbol) : strtolower($name);
                $isNewSec = false;

                if (isset($investmentCache[$cacheKey])) {
                    $investmentId = $investmentCache[$cacheKey];
                    if ($cusip !== '') {
                        $db->prepare('UPDATE investments SET cusip = ? WHERE id = ? AND (cusip IS NULL OR cusip = \'\')')->execute([$cusip, $investmentId]);
                    }
                } else {
                    $invRow = null;
                    if ($cusip !== '') {
                        $s = $db->prepare('SELECT id, is_active, symbol, cusip FROM investments WHERE cusip = ? ORDER BY is_active DESC, id LIMIT 1');
                        $s->execute([$cusip]);
                        $invRow = $s->fetch() ?: null;
                    }
                    if (!$invRow) {
                        if ($symbol !== '') {
                            $s = $db->prepare('SELECT id, is_active, symbol, cusip FROM investments WHERE symbol = ? OR (symbol = \'\' AND name = ?) ORDER BY is_active DESC, id LIMIT 1');
                            $s->execute([$symbol, $name]);
                        } else {
                            $s = $db->prepare('SELECT id, is_active, symbol, cusip FROM investments WHERE name = ? ORDER BY is_active DESC, id LIMIT 1');
                            $s->execute([$name]);
                        }
                        $invRow = $s->fetch() ?: null;
                    }

                    $investmentId = $invRow ? (int)$invRow['id'] : null;

                    if ($investmentId) {
                        $needsUpdate = !$invRow['is_active']
                            || ($symbol !== '' && ($invRow['symbol'] ?? '') === '')
                            || ($cusip  !== '' && ($invRow['cusip']  ?? '') === '');
                        if ($needsUpdate) {
                            $db->prepare('UPDATE investments SET is_active = 1, symbol = COALESCE(NULLIF(?, \'\'), symbol), cusip = COALESCE(NULLIF(?, \'\'), cusip) WHERE id = ?')
                               ->execute([$symbol, $cusip, $investmentId]);
                        }
                    } elseif ($name) {
                        $db->prepare('INSERT INTO investments (name, symbol, cusip, type, created_by) VALUES (?, ?, ?, \'Stock\', ?)')->execute([$name, $symbol, $cusip ?: null, currentUserId()]);
                        $investmentId = (int)$db->lastInsertId();
                        $isNewSec = true;
                    }
                    $investmentCache[$cacheKey] = $investmentId;
                }

                // Track each unique security once for the report
                if (!isset($reportedSecKeys[$cacheKey])) {
                    $reportedSecKeys[$cacheKey] = true;
                    if ($isNewSec) {
                        $reportSecCreated[] = ['name' => $name, 'symbol' => $symbol];
                    } else {
                        $reportSecMatchedCount++;
                    }
                }

                $iStmt = $db->prepare(
                    'INSERT IGNORE INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, fitid, created_by)
                     VALUES (?, ?, ?, \'investment\', ?, \'cleared\', ?, ?, ?)'
                );
                $iStmt->execute([$rowAccountId, $rowDate, $name, $investAmount, $rowMemo, $rowFitid, currentUserId()]);
                if ($iStmt->rowCount() === 0) {
                    $skipped++;
                    $reportRow['status'] = 'skipped';
                    $reportRow['reason'] = 'Duplicate';
                    $reportRows[] = $reportRow;
                    continue;
                }
                $invTxnId = (int)$db->lastInsertId();

                $db->prepare(
                    'INSERT INTO investment_transactions (transaction_id, investment_id, activity, action_type, quantity, price, commission)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$invTxnId, $investmentId, $activity, $actionType ?: null, $qty, $price, $comm]);
            }

            // ── Cash routing ───────────────────────────────────────────────
            [$needsCash, $useXAcct, $cashType] = investCashRouting($actionType);

            if ($needsCash && $total != 0.0) {
                $xAcctId = 0;
                if ($useXAcct && $xferAcctName !== '') {
                    if (!isset($xAccountCache[$xferAcctName])) {
                        $s = $db->prepare('SELECT id FROM accounts WHERE name = ? AND is_active = 1 LIMIT 1');
                        $s->execute([$xferAcctName]);
                        $xAccountCache[$xferAcctName] = (int)($s->fetchColumn() ?: 0);
                    }
                    $xAcctId = $xAccountCache[$xferAcctName];
                }

                $rowPayee = $skipInvRecord ? ($row['payee'] ?: $actionType) : $name;

                if (in_array($actionType, ['ContribX', 'WithdrwX'])) {
                    if ($rowLinkedCashId && $xAcctId) {
                        $f1 = 'GEN:' . sha1('bidir_L_' . ($rowFitid ?? ($rowDate . '|' . $total)));
                        $f2 = 'GEN:' . sha1('bidir_X_' . ($rowFitid ?? ($rowDate . '|' . $total)));

                        $s1 = $db->prepare(
                            'INSERT IGNORE INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, fitid, created_by)
                             VALUES (?, ?, ?, \'transfer\', ?, \'cleared\', ?, ?, ?)'
                        );
                        $s1->execute([$rowLinkedCashId, $rowDate, $rowPayee, $total, $rowMemo, $f1, currentUserId()]);
                        $lTxnId = $s1->rowCount() ? (int)$db->lastInsertId() : 0;

                        if ($lTxnId) {
                            $s2 = $db->prepare(
                                'INSERT IGNORE INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, fitid, transfer_pair_id, created_by)
                                 VALUES (?, ?, ?, \'transfer\', ?, \'cleared\', ?, ?, ?, ?)'
                            );
                            $s2->execute([$xAcctId, $rowDate, $rowPayee, -$total, $rowMemo, $f2, $lTxnId, currentUserId()]);
                            $xTxnId = $s2->rowCount() ? (int)$db->lastInsertId() : 0;
                            if ($xTxnId) {
                                $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?')->execute([$xTxnId, $lTxnId]);
                                $reportCashLegs += 2;
                                $reportRow['cash_account'] = $rowLinkedCashName . ' ↔ ' . $xferAcctName;
                                $reportRow['cash_amount']  = $total;
                            }
                        }
                    }
                } else {
                    $targetId = $useXAcct ? $xAcctId : $rowLinkedCashId;

                    if ($targetId) {
                        $resolvedType = $cashType ?? ($total >= 0 ? 'deposit' : 'withdrawal');
                        $cashFitid    = 'GEN:' . sha1('cash_' . ($rowFitid ?? '') . '_' . $actionType . '_' . $rowDate);

                        $cs = $db->prepare(
                            'INSERT IGNORE INTO transactions (account_id, transaction_date, payee, type, amount, cleared_status, memo, fitid, created_by)
                             VALUES (?, ?, ?, ?, ?, \'cleared\', ?, ?, ?)'
                        );
                        $cs->execute([$targetId, $rowDate, $rowPayee, $resolvedType, $total, $rowMemo, $cashFitid, currentUserId()]);
                        $cashTxnId = $cs->rowCount() ? (int)$db->lastInsertId() : 0;

                        if ($cashTxnId && $invTxnId) {
                            $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?')->execute([$cashTxnId, $invTxnId]);
                            $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?')->execute([$invTxnId, $cashTxnId]);
                        }
                        if ($cashTxnId) {
                            $reportCashLegs++;
                            $reportRow['cash_account'] = $useXAcct ? $xferAcctName : $rowLinkedCashName;
                            $reportRow['cash_amount']  = $total;
                        }
                    }
                }
            }

        } else {
            // ── Checking / Savings / Credit Card transaction ───────────────
            $amount  = (float)$row['amount'];
            $cleared = in_array($row['cleared'] ?? '', ['cleared', 'reconciled']) ? $row['cleared'] : '';

            if (!empty($row['is_transfer'])) {
                $type = 'transfer';
            } else {
                $type = ($amount >= 0) ? 'deposit' : 'withdrawal';
            }

            $qifSplits = $row['splits'] ?? [];
            if (!empty($qifSplits)) {
                $splitRows = [];
                foreach ($qifSplits as $sp) {
                    [$cid, $sid] = resolveCatIds($sp['category'] ?? null, $catMap);
                    $splitRows[] = [
                        'category_id'    => $cid,
                        'subcategory_id' => $sid,
                        'amount'         => (float)$sp['amount'],
                        'memo'           => $sp['memo'] ?? '',
                    ];
                }
            } elseif (!empty($row['category'])) {
                [$cid, $sid] = resolveCatIds($row['category'], $catMap);
                $splitRows = [['category_id' => $cid, 'subcategory_id' => $sid, 'amount' => $amount, 'memo' => '']];
            } else {
                $xferCatId = !empty($row['is_transfer']) ? getSystemCategoryId('{Cash Transfer}') : null;
                $splitRows = [['category_id' => $xferCatId, 'subcategory_id' => null, 'amount' => $amount, 'memo' => '']];
            }

            $isSplit = count($splitRows) > 1 ? 1 : 0;

            $bankStmt = $db->prepare(
                'INSERT IGNORE INTO transactions (account_id, num, transaction_date, payee, type, amount, cleared_status, memo, fitid, is_split, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $bankStmt->execute([
                $rowAccountId,
                $row['num']   ?? '',
                $row['date'],
                $row['payee'],
                $type,
                $amount,
                $cleared,
                $row['memo']  ?? '',
                $row['fitid'] ?? null,
                $isSplit,
                currentUserId(),
            ]);
            if ($bankStmt->rowCount() === 0) {
                $skipped++;
                $reportRow['status'] = 'skipped';
                $reportRow['reason'] = 'Duplicate';
                $reportRows[] = $reportRow;
                continue;
            }
            $txnId = (int)$db->lastInsertId();

            // Collect banking transfers for post-loop pair-linking (multi-account imports only)
            if ($isMulti && !empty($row['is_transfer']) && ($row['transfer_account'] ?? '') !== '') {
                $insertedBankXfers[] = [
                    'txn_id'    => $txnId,
                    'date'      => $row['date'],
                    'amount'    => $amount,
                    'acct'      => strtolower($row['account_name'] ?? ''),
                    'xfer_to'   => strtolower(trim($row['transfer_account'])),
                ];
            }

            foreach ($splitRows as $sp) {
                $db->prepare(
                    'INSERT INTO transaction_splits (transaction_id, category_id, subcategory_id, amount, memo) VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $txnId,
                    $sp['category_id']    ?: null,
                    $sp['subcategory_id'] ?: null,
                    $sp['amount'],
                    $sp['memo'],
                ]);
            }
        }

        $reportRows[] = $reportRow;
        $imported++;
    }

    // ── Link banking transfer pairs for multi-account imports ─────────────────
    // Each QIF transfer produces one row in each account with the other as transfer_account.
    // Match them by (date, mirror amounts, cross-pointing account names) and set transfer_pair_id.
    $reportTransferPairs = 0;
    if ($isMulti && !empty($insertedBankXfers)) {
        $byAcctDate = [];
        foreach ($insertedBankXfers as $x) {
            $byAcctDate[$x['date'] . '|' . $x['acct']][] = $x;
        }

        $paired   = [];
        $pairStmt = $db->prepare('UPDATE transactions SET transfer_pair_id = ? WHERE id = ?');

        foreach ($insertedBankXfers as $x) {
            if (isset($paired[$x['txn_id']])) continue;

            foreach ($byAcctDate[$x['date'] . '|' . $x['xfer_to']] ?? [] as $other) {
                if (isset($paired[$other['txn_id']])) continue;
                if ($other['txn_id'] === $x['txn_id']) continue;
                if ($other['xfer_to'] !== $x['acct']) continue;
                if (abs($x['amount'] + $other['amount']) > 0.005) continue;

                $pairStmt->execute([$other['txn_id'], $x['txn_id']]);
                $pairStmt->execute([$x['txn_id'], $other['txn_id']]);
                $paired[$x['txn_id']]     = true;
                $paired[$other['txn_id']] = true;
                $reportTransferPairs++;
                break;
            }
        }
    }

    $db->commit();
    unset($_SESSION['import'], $_SESSION['import_csv']);

    // Build skip-reason breakdown
    $skipReasons = [];
    foreach ($reportRows as $r) {
        if ($r['status'] === 'skipped') {
            $reason = $r['reason'] ?: 'Unknown';
            $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
        }
    }

    arsort($reportActionTypes);

    $_SESSION['import_report'] = [
        'account_id'       => $accountId,
        'account_name'     => $accountName,
        'is_investment'    => $isInv,
        'is_multi_account' => $isMulti,
        'stmt_type'        => $import['statement_type'] ?? 'transaction_history',
        'format'           => $import['format'],
        'imported_at'      => date('Y-m-d H:i:s'),
        'new_account'      => $newAcct !== null,
        'opening_balance'  => $newAcct ? (float)($newAcct['opening_balance'] ?? 0) : 0.0,
        'summary' => [
            'selected'        => count($selected),
            'imported'        => $imported,
            'skipped'         => $skipped,
            'cash_legs'       => $reportCashLegs,
            'transfer_pairs'  => $reportTransferPairs,
        ],
        'rows'          => $reportRows,
        'skip_reasons'  => $skipReasons,
        'action_types'  => $reportActionTypes,
        'securities'    => [
            'created'       => $reportSecCreated,
            'matched_count' => $reportSecMatchedCount,
        ],
        'categories'    => ['created' => $reportCreatedCategories],
    ];

    logActivity('import_complete', sprintf('Imported %d transaction%s into "%s"',
        $imported, $imported !== 1 ? 's' : '', $accountName));
    header('Location: ' . BASE_PATH . '/import/report');
    exit;

} catch (Exception $e) {
    $db->rollBack();
    setFlash('error', 'Import failed: ' . $e->getMessage());
    header('Location: ' . BASE_PATH . '/import/preview');
    exit;
}
