<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Core "inline" GUI actions — POST handlers that historically lived as
 * raw `if ($action === ...)` branches in Functions.php and have no
 * controller home. Pulling them out here lets them go through
 * GuiActionRegistry alongside the controller-owned actions while
 * keeping the closure bodies physically close to where they grew up.
 *
 * Every entry registers at TIER_AUTH so the registry's CSRF gate does
 * not fire — the closure body keeps its existing inline non-rotating
 * `validateCSRFToken($t, false)` check (where present) and its
 * existing JSON envelope shape on failure. The migration is structural
 * routing, not a policy change.
 *
 * Required scope variables (injected by Functions.php's `require`):
 *   $serviceContainer, $secureSession, $user, $contactService,
 *   $transactionService, $contactController, $transactionController.
 *
 * Helpers required by the closures (buildTxContactLookupMaps,
 * fetchDlqActiveTxMessageIds, formatTimestamp) live in Functions.php
 * itself; PHP resolves them at call-time so the closures can reference
 * them even though they're defined further down in the file.
 */

$registry = $serviceContainer->getActionRegistry();

// =============================================================================
// What's New
// =============================================================================

$registry->register('whatsNewDismiss', function (): void {
    header('Content-Type: application/json');
    try {
        \Eiou\Services\UpdateCheckService::dismissWhatsNew();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');

$registry->register('whatsNewNotes', function (): void {
    header('Content-Type: application/json');
    try {
        $version = $_POST['version'] ?? \Eiou\Core\Constants::APP_VERSION;
        $notes = \Eiou\Services\UpdateCheckService::getReleaseNotes($version);
        if ($notes !== null) {
            echo json_encode(['success' => true, 'data' => $notes]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Release notes not available']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');

// =============================================================================
// Remember-me session management
// =============================================================================

$rememberSessionHandler = function (array $request) use ($serviceContainer, $secureSession, $user): void {
    header('Content-Type: application/json');
    $action = $request['action'] ?? '';
    try {
        // CSRF is required — this is a state-changing action.
        if (empty($_POST['csrf_token']) || !$secureSession->validateCSRFToken($_POST['csrf_token'], false)) {
            echo json_encode(['success' => false, 'error' => 'csrf_error', 'message' => 'Invalid CSRF token']);
            exit;
        }

        $pubkeyHashForAction = hash(\Eiou\Core\Constants::HASH_ALGORITHM, $user->getPublicKey());
        $rememberService = $serviceContainer->getRememberTokenService();

        if ($action === 'revokeRememberSession') {
            $id = (int) ($_POST['session_id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'invalid_id']);
                exit;
            }

            // Detect "am I revoking my OWN row?" BEFORE the revoke so the
            // is_current flag still reads true. If so, we also need to
            // tear down the PHP session — revoking the remember-me row
            // alone only invalidates future cookie-based auto-logins; the
            // already-authenticated session would persist otherwise.
            $currentRaw = $secureSession->getRememberCookie();
            $revokingCurrentDevice = false;
            if ($currentRaw !== null) {
                foreach ($rememberService->listForUser($pubkeyHashForAction, $currentRaw) as $row) {
                    if ($row['id'] === (int)$id && !empty($row['is_current'])) {
                        $revokingCurrentDevice = true;
                        break;
                    }
                }
            }

            $ok = $rememberService->revokeTokenById($id, $pubkeyHashForAction);

            if ($revokingCurrentDevice) {
                $secureSession->clearRememberCookie();
                $secureSession->logout();
                echo json_encode(['success' => $ok, 'logged_out' => true]);
                exit;
            }

            echo json_encode(['success' => $ok]);
            exit;
        }

        if ($action === 'revokeAllRememberSessions') {
            // "Sign out everywhere" includes THIS browser by definition,
            // so always tear down the session + cookie.
            $count = $rememberService->revokeAllForUser($pubkeyHashForAction);
            $secureSession->clearRememberCookie();
            $secureSession->logout();
            echo json_encode(['success' => true, 'revoked' => $count, 'logged_out' => true]);
            exit;
        }
    } catch (\Throwable $e) {
        \Eiou\Utils\Logger::getInstance()->logException($e, [
            'context' => 'remember_session_action',
            'action'  => $action,
        ]);
        echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        exit;
    }
};
$registry->register('revokeRememberSession',     $rememberSessionHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');
$registry->register('revokeAllRememberSessions', $rememberSessionHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');

// =============================================================================
// "Search entire database" handlers for Recent Transactions and
// Payment Requests history. The client-side search + filter on these
// tables only inspects already-rendered rows; this lets the user ask
// the server to walk the full table for a match without having to
// Load-older until the match shows up locally. Contacts is intentionally
// omitted — that table is almost always fully loaded on first render.
// =============================================================================

$searchHandler = function (array $request) use ($serviceContainer, $secureSession, $user, $contactService, $transactionService): void {
    header('Content-Type: application/json');
    $action = $request['action'] ?? '';
    try {
        if (empty($_POST['csrf_token']) || !$secureSession->validateCSRFToken($_POST['csrf_token'], false)) {
            echo json_encode(['success' => false, 'error' => 'csrf_error']);
            exit;
        }
        $term = isset($_POST['q']) ? (string)$_POST['q'] : '';
        if (trim($term) === '') {
            echo json_encode(['success' => false, 'error' => 'empty_term', 'message' => 'Enter a search term first.']);
            exit;
        }

        $dirFilter    = isset($_POST['direction']) ? trim((string)$_POST['direction']) : '';
        $statusFilter = isset($_POST['status'])    ? trim((string)$_POST['status'])    : '';
        $typeFilter   = isset($_POST['tx_type'])   ? trim((string)$_POST['tx_type'])   : '';
        // Ceiling configurable via user setting later; 500 is enough
        // to comfortably cover a search result with client-side
        // paginator slicing on top.
        $maxResults = 500;

        if ($action === 'searchTransactions') {
            $transactions = $transactionService->searchTransactions(
                $term,
                $dirFilter !== '' ? $dirFilter : null,
                $typeFilter !== '' ? $typeFilter : null,
                $statusFilter !== '' ? $statusFilter : null,
                $maxResults
            );
            $capped = count($transactions) >= $maxResults;

            // Lookup maps + dlq ids so the row partial renders with
            // avatar resolution identical to the initial page render.
            $contactsByStatus = $contactService->getContactsGroupedByStatus();
            $acceptedEnriched    = $transactionService->contactBalanceConversion($contactsByStatus['accepted']     ?? [], 0);
            $pendingUserEnriched = $transactionService->contactBalanceConversion($contactsByStatus['user_pending'] ?? [], 0);
            $blockedEnriched     = $transactionService->contactBalanceConversion($contactsByStatus['blocked']      ?? [], 0);
            $lookups = buildTxContactLookupMaps($acceptedEnriched, $pendingUserEnriched, $blockedEnriched);
            $txContactsByAddress   = $lookups['byAddress'];
            $txContactsByName      = $lookups['byName'];
            $txContactsByHash      = $lookups['byHash'];
            $txAvatarStyle         = $user->getContactAvatarStyle();
            $dlqActiveTxMessageIds = fetchDlqActiveTxMessageIds($serviceContainer);
            $statusIcons = [
                'pending'   => 'fa-hourglass-half',
                'sending'   => 'fa-paper-plane',
                'sent'      => 'fa-check',
                'accepted'  => 'fa-check',
                'completed' => 'fa-check-double',
                'rejected'  => 'fa-times',
                'cancelled' => 'fa-ban',
            ];

            // Search results replace (not append to) the table body,
            // so indices restart at 0. `transactionData` on the client
            // is rewritten to the same shape the initial render uses,
            // which keeps openTransactionModal(index) working against
            // the replacement row set.
            $rowDataOut = [];
            ob_start();
            foreach ($transactions as $i => $tx) {
                $index = $i;
                require __DIR__ . '/../layout/walletSubParts/_transactionHistoryRow.html';

                $inDlq = false;
                if (!empty($tx['txid']) && !empty($dlqActiveTxMessageIds)) {
                    foreach ($dlqActiveTxMessageIds as $dlqMsgId) {
                        if (strpos($dlqMsgId, $tx['txid']) !== false) { $inDlq = true; break; }
                    }
                }
                $isTxSent = (($tx['type'] ?? '') === 'sent');
                $cpAddr2 = $isTxSent ? ($tx['receiver_address'] ?? '') : ($tx['sender_address'] ?? '');
                $cpHash2 = $isTxSent ? ($tx['receiver_public_key_hash'] ?? '') : ($tx['sender_public_key_hash'] ?? '');
                $cpName2 = $tx['counterparty_name'] ?? '';
                $cpContact2 = null;
                if ($cpName2 && isset($txContactsByName[strtolower($cpName2)])) {
                    $cpContact2 = $txContactsByName[strtolower($cpName2)];
                } elseif ($cpAddr2 && isset($txContactsByAddress[$cpAddr2])) {
                    $cpContact2 = $txContactsByAddress[$cpAddr2];
                } elseif ($cpHash2 && isset($txContactsByHash[$cpHash2])) {
                    $cpContact2 = $txContactsByHash[$cpHash2];
                }
                $endAddr2 = $isTxSent ? ($tx['end_recipient_address'] ?? '') : ($tx['initial_sender_address'] ?? '');
                $endContact2 = ($endAddr2 && isset($txContactsByAddress[$endAddr2])) ? $txContactsByAddress[$endAddr2] : null;

                $rowDataOut[] = [
                    'txid' => $tx['txid'] ?? '',
                    'tx_type' => $tx['tx_type'] ?? 'standard',
                    'direction' => $tx['direction'] ?? $tx['type'],
                    'status' => $tx['status'] ?? 'completed',
                    'date' => !empty($tx['date']) ? formatTimestamp($tx['date']) : '',
                    'type' => $tx['type'] ?? '',
                    'amount' => $tx['amount'] ?? 0,
                    'currency' => $tx['currency'] ?? 'USD',
                    'counterparty' => $tx['counterparty'] ?? '',
                    'counterparty_address' => $tx['counterparty_address'] ?? '',
                    'counterparty_name' => $tx['counterparty_name'] ?? '',
                    'counterparty_contact_id' => $cpContact2['contact_id'] ?? null,
                    'sender_address' => $tx['sender_address'] ?? '',
                    'receiver_address' => $tx['receiver_address'] ?? '',
                    'memo' => $tx['memo'] ?? '',
                    'description' => $tx['description'] ?? '',
                    'previous_txid' => $tx['previous_txid'] ?? '',
                    'initial_sender_address' => $tx['initial_sender_address'] ?? null,
                    'end_recipient_address' => $tx['end_recipient_address'] ?? null,
                    'p2p_destination' => $tx['p2p_destination'] ?? null,
                    'p2p_destination_contact_id' => $endContact2['contact_id'] ?? null,
                    'p2p_destination_contact_name' => $endContact2['name'] ?? null,
                    'p2p_amount' => $tx['p2p_amount'] ?? null,
                    'p2p_fee' => $tx['p2p_fee'] ?? null,
                    'in_dlq' => $inDlq,
                ];
            }
            $html = ob_get_clean();

            echo json_encode([
                'success' => true,
                'html'    => $html,
                'rows'    => $rowDataOut,
                'total'   => count($transactions),
                'capped'  => $capped,
                'cap'     => $maxResults,
            ]);
            exit;
        }

        if ($action === 'searchPaymentRequests') {
            $prService = $serviceContainer->getPaymentRequestService();
            $rows = $prService->searchResolvedHistory(
                $term,
                $dirFilter !== '' ? $dirFilter : null,
                $statusFilter !== '' ? $statusFilter : null,
                $maxResults
            );
            $capped = count($rows) >= $maxResults;

            $contactsByStatus = $contactService->getContactsGroupedByStatus();
            $prContactsByHash = [];
            foreach ([$contactsByStatus['blocked'] ?? [], $contactsByStatus['user_pending'] ?? [], $contactsByStatus['accepted'] ?? []] as $bucket) {
                foreach ($bucket as $tc) {
                    if (!empty($tc['pubkey_hash'])) {
                        $prContactsByHash[$tc['pubkey_hash']] = $tc;
                    }
                }
            }
            $prAvatarStyle = $user->getContactAvatarStyle();
            $prStatusIcons = [
                'approved'  => 'fa-check',
                'declined'  => 'fa-times-circle',
                'cancelled' => 'fa-ban',
                'pending'   => 'fa-clock',
            ];

            ob_start();
            foreach ($rows as $row) {
                $row['_direction'] = $row['direction'] ?? '';
                $req = $row;
                require __DIR__ . '/../layout/walletSubParts/_paymentRequestRow.html';
            }
            $html = ob_get_clean();

            echo json_encode([
                'success' => true,
                'html'    => $html,
                'total'   => count($rows),
                'capped'  => $capped,
                'cap'     => $maxResults,
            ]);
            exit;
        }
    } catch (\Throwable $e) {
        \Eiou\Utils\Logger::getInstance()->logException($e, [
            'context' => 'search_handler',
            'action'  => $action,
        ]);
        echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        exit;
    }
};
$registry->register('searchTransactions',    $searchHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');
$registry->register('searchPaymentRequests', $searchHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');

// =============================================================================
// "Load older" handlers for the three paginated tables (Recent
// Transactions, Contacts, Payment Requests). Each renders the next
// chunk of rows as an HTML fragment so the client can append directly
// to the existing <tbody> without duplicating the row template in JS.
// =============================================================================

$loadMoreHandler = function (array $request) use ($serviceContainer, $secureSession, $user, $contactService, $transactionService): void {
    header('Content-Type: application/json');
    $action = $request['action'] ?? '';
    try {
        if (empty($_POST['csrf_token']) || !$secureSession->validateCSRFToken($_POST['csrf_token'], false)) {
            echo json_encode(['success' => false, 'error' => 'csrf_error']);
            exit;
        }
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $limit  = (int) $user->getDisplayRecentTransactionsLimit();
        if ($limit <= 0) { $limit = 10; }

        if ($action === 'loadMoreTransactions') {
            $transactions = $transactionService->getTransactionHistory($limit, $offset);
            $exhausted = count($transactions) < $limit;

            // Rebuild the same lookup maps the full template uses so
            // avatar resolution, contact names, and counterparty links
            // match the initial server render 1:1. Must pass the
            // *enriched* arrays (after contactBalanceConversion) — the
            // raw contacts table has no http/https/tor columns (those
            // live in the addresses table and are merged in by
            // contactBalanceConversion), so passing the raw arrays
            // would leave byAddress empty and break avatar lookup for
            // any tx whose counterparty_name is NULL.
            $contactsByStatus = $contactService->getContactsGroupedByStatus();
            $contactTxLimit   = 0; // Don't need transactions here; set 0 to avoid batch fetching.
            $acceptedEnriched     = $transactionService->contactBalanceConversion($contactsByStatus['accepted']     ?? [], $contactTxLimit);
            $pendingUserEnriched  = $transactionService->contactBalanceConversion($contactsByStatus['user_pending'] ?? [], $contactTxLimit);
            $blockedEnriched      = $transactionService->contactBalanceConversion($contactsByStatus['blocked']      ?? [], $contactTxLimit);
            $lookups = buildTxContactLookupMaps(
                $acceptedEnriched,
                $pendingUserEnriched,
                $blockedEnriched
            );
            $txContactsByAddress   = $lookups['byAddress'];
            $txContactsByName      = $lookups['byName'];
            $txContactsByHash      = $lookups['byHash'];
            $txAvatarStyle         = $user->getContactAvatarStyle();
            $dlqActiveTxMessageIds = fetchDlqActiveTxMessageIds($serviceContainer);
            $statusIcons = [
                'pending'   => 'fa-hourglass-half',
                'sending'   => 'fa-paper-plane',
                'sent'      => 'fa-check',
                'accepted'  => 'fa-check',
                'completed' => 'fa-check-double',
                'rejected'  => 'fa-times',
                'cancelled' => 'fa-ban',
            ];

            // Build the JSON row array that matches transactionData[] —
            // the client will concat() this onto the in-memory array so
            // openTransactionModal(index) keeps working for newly
            // appended rows.
            $rowDataOut = [];
            ob_start();
            foreach ($transactions as $i => $tx) {
                $index = $offset + $i;
                require __DIR__ . '/../layout/walletSubParts/_transactionHistoryRow.html';

                $inDlq = false;
                if (!empty($tx['txid']) && !empty($dlqActiveTxMessageIds)) {
                    foreach ($dlqActiveTxMessageIds as $dlqMsgId) {
                        if (strpos($dlqMsgId, $tx['txid']) !== false) { $inDlq = true; break; }
                    }
                }
                $isTxSent = (($tx['type'] ?? '') === 'sent');
                $cpAddr2 = $isTxSent ? ($tx['receiver_address'] ?? '') : ($tx['sender_address'] ?? '');
                $cpHash2 = $isTxSent ? ($tx['receiver_public_key_hash'] ?? '') : ($tx['sender_public_key_hash'] ?? '');
                $cpName2 = $tx['counterparty_name'] ?? '';
                $cpContact2 = null;
                if ($cpName2 && isset($txContactsByName[strtolower($cpName2)])) {
                    $cpContact2 = $txContactsByName[strtolower($cpName2)];
                } elseif ($cpAddr2 && isset($txContactsByAddress[$cpAddr2])) {
                    $cpContact2 = $txContactsByAddress[$cpAddr2];
                } elseif ($cpHash2 && isset($txContactsByHash[$cpHash2])) {
                    $cpContact2 = $txContactsByHash[$cpHash2];
                }
                $endAddr2 = $isTxSent ? ($tx['end_recipient_address'] ?? '') : ($tx['initial_sender_address'] ?? '');
                $endContact2 = ($endAddr2 && isset($txContactsByAddress[$endAddr2])) ? $txContactsByAddress[$endAddr2] : null;

                $rowDataOut[] = [
                    'txid' => $tx['txid'] ?? '',
                    'tx_type' => $tx['tx_type'] ?? 'standard',
                    'direction' => $tx['direction'] ?? $tx['type'],
                    'status' => $tx['status'] ?? 'completed',
                    'date' => !empty($tx['date']) ? formatTimestamp($tx['date']) : '',
                    'type' => $tx['type'] ?? '',
                    'amount' => $tx['amount'] ?? 0,
                    'currency' => $tx['currency'] ?? 'USD',
                    'counterparty' => $tx['counterparty'] ?? '',
                    'counterparty_address' => $tx['counterparty_address'] ?? '',
                    'counterparty_name' => $tx['counterparty_name'] ?? '',
                    'counterparty_contact_id' => $cpContact2['contact_id'] ?? null,
                    'sender_address' => $tx['sender_address'] ?? '',
                    'receiver_address' => $tx['receiver_address'] ?? '',
                    'memo' => $tx['memo'] ?? '',
                    'description' => $tx['description'] ?? '',
                    'previous_txid' => $tx['previous_txid'] ?? '',
                    'initial_sender_address' => $tx['initial_sender_address'] ?? null,
                    'end_recipient_address' => $tx['end_recipient_address'] ?? null,
                    'p2p_destination' => $tx['p2p_destination'] ?? null,
                    'p2p_destination_contact_id' => $endContact2['contact_id'] ?? null,
                    'p2p_destination_contact_name' => $endContact2['name'] ?? null,
                    'p2p_amount' => $tx['p2p_amount'] ?? null,
                    'p2p_fee' => $tx['p2p_fee'] ?? null,
                    'in_dlq' => $inDlq,
                ];
            }
            $html = ob_get_clean();

            echo json_encode([
                'success'   => true,
                'html'      => $html,
                'rows'      => $rowDataOut,
                'exhausted' => $exhausted,
            ]);
            exit;
        }

        if ($action === 'loadMorePaymentRequests') {
            $prService = $serviceContainer->getPaymentRequestService();
            $more = $prService->getResolvedHistoryPage($limit, $offset);
            // `exhausted` lets the client hide the Load older button
            // once it's done. Short-page → exhausted; exact-fit page →
            // not exhausted yet (next click will return 0 rows +
            // exhausted=true). Matches the Recent Transactions handler.
            $exhausted = count($more) < $limit;

            // Same lookup maps the full template builds so avatars /
            // display names resolve identically between initial render
            // and appended rows. Prefer enriched contacts (with
            // transport columns merged in via contactBalanceConversion)
            // so both hash-based lookup (primary for PRs) and future
            // address-based lookups succeed.
            $contactsByStatus = $contactService->getContactsGroupedByStatus();
            $acceptedEnriched    = $transactionService->contactBalanceConversion($contactsByStatus['accepted']     ?? [], 0);
            $pendingUserEnriched = $transactionService->contactBalanceConversion($contactsByStatus['user_pending'] ?? [], 0);
            $blockedEnriched     = $transactionService->contactBalanceConversion($contactsByStatus['blocked']      ?? [], 0);
            $prContactsByHash = [];
            // Accepted last so it wins on key collisions (restored /
            // re-accepted contacts keep their current display state).
            foreach ([$blockedEnriched, $pendingUserEnriched, $acceptedEnriched] as $bucket) {
                foreach ($bucket as $tc) {
                    if (!empty($tc['pubkey_hash'])) {
                        $prContactsByHash[$tc['pubkey_hash']] = $tc;
                    }
                }
            }
            $prAvatarStyle = $user->getContactAvatarStyle();
            $prStatusIcons = [
                'approved'  => 'fa-check',
                'declined'  => 'fa-times-circle',
                'cancelled' => 'fa-ban',
                'pending'   => 'fa-clock',
            ];

            ob_start();
            foreach ($more as $row) {
                // Tag direction onto `_direction` — the partial reads
                // that field (the usort block in the full template
                // does the same tagging).
                $row['_direction'] = $row['direction'] ?? '';
                $req = $row;
                require __DIR__ . '/../layout/walletSubParts/_paymentRequestRow.html';
            }
            $html = ob_get_clean();

            echo json_encode([
                'success'   => true,
                'html'      => $html,
                'exhausted' => $exhausted,
            ]);
            exit;
        }

        if ($action === 'loadMoreContacts') {
            // Only accepted contacts paginate — pending + blocked
            // are bounded and always rendered up-front in the
            // initial template, so the paginator append path stays
            // pure "more accepted rows".
            $rawAccepted = $contactService->getAcceptedContactsPage($limit, $offset);
            $exhausted = count($rawAccepted) < $limit;

            if (empty($rawAccepted)) {
                echo json_encode([
                    'success'   => true,
                    'html'      => '',
                    'exhausted' => true,
                ]);
                exit;
            }

            // Enrich: same pipeline as the main Functions.php view-
            // data initialization, scoped to just this page's rows
            // so we don't re-fetch per-contact credit for contacts
            // we already showed.
            $moreWithBalances = $transactionService->contactBalanceConversion(
                $rawAccepted,
                (int) $user->getMaxOutput()
            );

            // Credit + currency enrichment (mirrors the
            // $contactArraysForCredit loop further down in this file).
            $contactCreditRepo   = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCreditRepository::class);
            $contactCurrencyRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);

            foreach ($moreWithBalances as &$contact) {
                $hash = $contact['pubkey_hash'] ?? '';
                $contact['my_available_credit']    = null;
                $contact['their_available_credit'] = null;

                try {
                    $creditData = $hash ? $contactCreditRepo->getAvailableCredit($hash) : null;
                    if ($creditData !== null) {
                        $contact['my_available_credit'] = $creditData['available_credit']->toMajorUnits();
                    }

                    $allCredits = $hash ? $contactCreditRepo->getAvailableCreditAllCurrencies($hash) : [];
                    $creditMap = [];
                    foreach ($allCredits as $cr) {
                        $cur = $cr['currency'] ?? \Eiou\Core\Constants::TRANSACTION_DEFAULT_CURRENCY;
                        $creditMap[$cur] = $cr['available_credit']->toMajorUnits();
                    }

                    $currencyConfigs = $hash ? $contactCurrencyRepo->getContactCurrencies($hash) : [];
                    $contactBalancesByCurrency = $contact['balances_by_currency'] ?? [];
                    $acceptedCurrencies = [];
                    foreach ($currencyConfigs as $cc) {
                        if (($cc['status'] ?? 'accepted') !== 'accepted') { continue; }
                        $cur = $cc['currency'];
                        $ccDirection = $cc['direction'] ?? 'outgoing';
                        $creditLimitMajor = ($cc['credit_limit'] instanceof \Eiou\Core\SplitAmount) ? $cc['credit_limit']->toMajorUnits() : 0;
                        $balanceForCur = floatval($contactBalancesByCurrency[$cur] ?? 0);
                        $entry = [
                            'currency' => $cur,
                            'fee' => ($cc['fee_percent'] ?? 0) / \Eiou\Core\Constants::FEE_CONVERSION_FACTOR,
                            'credit_limit' => $creditLimitMajor,
                            'my_available_credit' => $creditMap[$cur] ?? null,
                            'their_available_credit' => ($creditLimitMajor > 0 || $balanceForCur != 0) ? round($creditLimitMajor - $balanceForCur, 2) : null,
                            'status' => 'accepted',
                            'direction' => $ccDirection,
                        ];
                        // Deduplicate per currency (prefer outgoing — has our fee/credit)
                        if (!isset($acceptedCurrencies[$cur]) || $ccDirection === 'outgoing') {
                            $acceptedCurrencies[$cur] = $entry;
                        }
                    }
                    $contact['currencies'] = array_values($acceptedCurrencies);
                } catch (\Throwable $e) {
                    // Non-critical — row renders with empty currencies,
                    // which the partial handles (falls back to `USD`).
                    $contact['currencies'] = [];
                }
            }
            unset($contact);

            // Partial dependencies the template pulls from scope.
            // $addressTypes is set further down in Functions.php for
            // the main render; duplicate the lookup here so we can
            // construct ContactDataBuilder at handler time.
            $addressTypesForBuilder = $serviceContainer->getRepositoryFactory()
                ->get(\Eiou\Database\AddressRepository::class)
                ->getAllAddressTypes();
            $contactDataBuilder = new \Eiou\Gui\Helpers\ContactDataBuilder($addressTypesForBuilder);
            $contactAvatarStyle = $user->getContactAvatarStyle();

            ob_start();
            foreach ($moreWithBalances as $contact) {
                require __DIR__ . '/../layout/walletSubParts/_contactRow.html';
            }
            $html = ob_get_clean();

            echo json_encode([
                'success'   => true,
                'html'      => $html,
                'exhausted' => $exhausted,
            ]);
            exit;
        }
    } catch (\Throwable $e) {
        \Eiou\Utils\Logger::getInstance()->logException($e, [
            'context' => 'load_more_handler',
            'action'  => $action,
        ]);
        echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        exit;
    }
};
$registry->register('loadMoreTransactions',    $loadMoreHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');
$registry->register('loadMoreContacts',        $loadMoreHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');
$registry->register('loadMorePaymentRequests', $loadMoreHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');
