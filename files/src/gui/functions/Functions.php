<?php
# Copyright 2025-2026 Vowels Group, LLC

use Eiou\Gui\Helpers\ContactDataBuilder;
use Eiou\Gui\Includes\SessionKeys;

/**
 * GUI Request Router and View Data Initializer
 *
 * This file serves as the central routing layer for the GUI, responsible for:
 *
 * 1. POST Request Routing:
 *    - Contact actions (add, accept, delete, block, unblock, edit)
 *    - Transaction actions (sendEIOU)
 *    - Settings actions (updateSettings, clearDebugLogs, sendDebugReport)
 *    - AJAX endpoints (getDebugReportJson, pingContact) - return JSON and exit
 *
 * 2. GET Request Handling:
 *    - Transaction update checking for real-time UI updates
 *    - Message display from redirect parameters
 *
 * 3. View Data Initialization:
 *    - User balance and earnings data
 *    - Transaction history and in-progress transactions
 *    - Contact lists (all, pending, accepted, blocked)
 *    - Address types from database schema
 *
 * 4. Notification Tracking (via $_SESSION):
 *    - Completed transaction detection by comparing in-progress txids
 *    - Dead Letter Queue new item detection
 *
 * Dependencies: Expects $contactController, $transactionController, $settingsController,
 * $user, $transactionService, $currencyUtility, $p2pService, $contactService, and
 * $serviceContainer to be initialized before inclusion.
 */

// =========================================================================
// Template helpers — shorthand for FQN calls used in .html templates.
// Loaded early (before auth) by /app/eiou/src/gui/functions/TemplateHelpers.php
// so the unauthenticated login page can use them too. The require_once below
// is idempotent; the functions have already been defined by the time we get
// here on the authenticated path.
// =========================================================================
require_once __DIR__ . '/TemplateHelpers.php';

// Route controllers if POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Contact actions
    if (in_array($action, ['addContact', 'acceptContact', 'acceptCurrency', 'acceptAllCurrencies', 'deleteContact', 'blockContact', 'unblockContact', 'editContact'])) {
        $contactController->routeAction();
    }

    // Transaction actions
    if (in_array($action, ['sendEIOU'])) {
        $transactionController->routeAction();
    }

    // Payment request actions
    if (in_array($action, ['createPaymentRequest', 'approvePaymentRequest', 'declinePaymentRequest', 'cancelPaymentRequest'])) {
        $paymentRequestController->routeAction();
    }

    // Settings actions
    if (in_array($action, ['updateSettings', 'resetToDefaults', 'clearDebugLogs', 'sendDebugReport'])) {
        $settingsController->routeAction();
    }

    // AJAX-only analytics consent (returns JSON, exits immediately)
    if ($action === 'analyticsConsent') {
        header('Content-Type: application/json');
        try {
            $settingsController->routeAction();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // AJAX-only "What's New" actions (returns JSON, exits immediately)
    if ($action === 'whatsNewDismiss') {
        header('Content-Type: application/json');
        try {
            \Eiou\Services\UpdateCheckService::dismissWhatsNew();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    if ($action === 'whatsNewNotes') {
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
    }

    // AJAX-only settings actions (returns JSON, exits immediately)
    if ($action === 'getDebugReportJson' || $action === 'submitDebugReport') {
        // Set JSON header early to ensure clean response
        header('Content-Type: application/json');
        try {
            $settingsController->routeAction();
        } catch (Exception $e) {
            echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        }
        exit; // Ensure we don't continue to render HTML
    }

    // AJAX-only contact actions (returns JSON, exits immediately)
    if ($action === 'pingContact') {
        // contactController handles JSON header and response
        try {
            $contactController->routeAction();
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit; // Ensure we don't continue to render HTML
    }

    // AJAX-only tx drop actions (returns JSON, exits immediately)
    if (in_array($action, ['proposeChainDrop', 'acceptChainDrop', 'rejectChainDrop'])) {
        try {
            $contactController->routeAction();
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // AJAX-only P2P approval actions (returns JSON, exits immediately)
    if (in_array($action, ['approveP2pTransaction', 'rejectP2pTransaction', 'getP2pCandidates', 'getTransactionByTxid'])) {
        try {
            $transactionController->routeAction();
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // AJAX-only remember-me session management (returns JSON, exits immediately)
    if (in_array($action, ['revokeRememberSession', 'revokeAllRememberSessions'], true)) {
        header('Content-Type: application/json');
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
                'action' => $action
            ]);
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    // AJAX-only API keys actions (returns JSON, exits immediately)
    if (in_array($action, [
        'apiKeysStatus',
        'apiKeysVerify',
        'apiKeysClearAccess',
        'apiKeysList',
        'apiKeysCreate',
        'apiKeysToggle',
        'apiKeysDelete',
        'apiKeysUpdate',
        'apiKeysDisableAll',
        'apiKeysDeleteAll',
    ], true)) {
        try {
            $apiKeysController->routeAction();
        } catch (\Eiou\Gui\Controllers\ApiKeysControllerResponseSent $e) {
            // Response body + status were already written; unwind to here
            // and exit cleanly so the wallet.html template does not render.
        }
        exit;
    }

    // AJAX-only "Search entire database" handlers for Recent Transactions
    // and Payment Requests history. The client-side search + filter on
    // these tables only inspects already-rendered rows; this lets the
    // user ask the server to walk the full table for a match without
    // having to Load-older until the match shows up locally. Contacts
    // is intentionally omitted — that table is almost always fully
    // loaded on first render, so the local filter already covers it.
    if (in_array($action, ['searchTransactions', 'searchPaymentRequests'], true)) {
        header('Content-Type: application/json');
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
                // so indices restart at 0. `transactionData` on the
                // client is rewritten to the same shape the initial
                // render uses, which keeps openTransactionModal(index)
                // working against the replacement row set.
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
    }

    // AJAX-only "Load older" handlers for the three paginated tables
    // (Recent Transactions, Contacts, Payment Requests). Each renders the
    // next chunk of rows as an HTML fragment so the client can append
    // directly to the existing <tbody> without duplicating the row
    // template in JS.
    if (in_array($action, ['loadMoreTransactions', 'loadMoreContacts', 'loadMorePaymentRequests'], true)) {
        header('Content-Type: application/json');
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
    }

    // AJAX-only DLQ actions (returns JSON, exits immediately)
    if (in_array($action, ['dlqRetry', 'dlqAbandon', 'dlqRetryAll', 'dlqAbandonAll'])) {
        try {
            $dlqController->routeAction();
        } catch (\Throwable $e) {
            // Log the full trace so we can diagnose server-side. The JSON
            // response only carries the message so the client doesn't see
            // internals, but ops still needs a stack to investigate.
            \Eiou\Utils\Logger::getInstance()->logException($e, [
                'context' => 'dlq_action_handler',
                'action' => $action
            ]);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Handle GET requests for update checking
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_updates'])) {
    $transactionController->routeAction();
}

// Live-notifications poll endpoint — returns JSON deltas (no page reload).
// Distinct from check_updates (which is the Tor-Browser "anything changed?"
// probe that triggers a full page reload). This endpoint returns the
// actual new rows so the client can toast them individually and update tab
// badges without reloading.
//
// Shape:
//   GET ?check_incoming=1&since=<unix_ts>
//     → { now: <unix_ts>, settings: {...}, new: { payment_requests, contact_requests, transactions, dlq } }
//
// Session lock released early (session_write_close) so concurrent polls
// don't serialize behind the main request on a slow page render.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_incoming'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    // Snapshot user-scoped settings before releasing the session lock — we
    // need these for the response body, and once session_write_close()
    // fires the $user object's backing store may be shadowed by a stale
    // read on subsequent access. Cheap to capture up front.
    $liveEnabled = $user->getLiveNotificationsEnabled();
    $verbosity = $user->getLiveNotificationsVerbosity();
    $toastDuration = $user->getLiveNotificationsToastDurationMs();
    $pollIntervalMs = \Eiou\Core\Constants::LIVE_NOTIFICATIONS_POLL_INTERVAL_MS;
    $maxPerKind = \Eiou\Core\Constants::LIVE_NOTIFICATIONS_MAX_PER_KIND;

    // Release the session lock ASAP so a page render in the same tab
    // (or another poll from the same session) doesn't queue behind us.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $now = time();
    $since = isset($_GET['since']) && ctype_digit((string) $_GET['since']) ? (int) $_GET['since'] : 0;
    // Guard against unreasonable since values (e.g. client clock skew past
    // server). Clamp to a 24h look-back — older-than-that is effectively
    // "first poll" and we still bound by $maxPerKind anyway.
    if ($since <= 0 || $since > $now) {
        $since = $now - 86400;
    }

    // If the master toggle is off, return an empty delta but still echo
    // the current settings so the client can keep them in sync without a
    // page reload (user flips the toggle via a separate save → reload —
    // this is just defense-in-depth).
    $payload = [
        'now' => $now,
        'settings' => [
            'enabled' => $liveEnabled,
            'verbosity' => $verbosity,
            'toast_duration_ms' => $toastDuration,
            'poll_interval_ms' => $pollIntervalMs,
        ],
        'new' => [
            'payment_requests' => [],
            'contact_requests' => [],
            'transactions' => [],
            'dlq' => [],
        ],
    ];

    if ($liveEnabled) {
        try {
            // Payment requests — small working set, filter in PHP by created_at.
            $prService = $serviceContainer->getPaymentRequestService();
            $pendingPr = $prService->getPendingIncoming() ?: [];
            foreach ($pendingPr as $pr) {
                $createdAt = isset($pr['created_at']) ? strtotime((string) $pr['created_at']) : 0;
                if ($createdAt > $since) {
                    $payload['new']['payment_requests'][] = [
                        'id' => $pr['request_id'] ?? ($pr['id'] ?? null),
                        'amount' => $pr['amount'] ?? null,
                        'currency' => $pr['currency'] ?? null,
                        'requester_pubkey_hash' => $pr['requester_pubkey_hash'] ?? null,
                        'description' => $pr['description'] ?? null,
                        'created_at' => $createdAt,
                    ];
                }
                if (count($payload['new']['payment_requests']) >= $maxPerKind) {
                    break;
                }
            }

            // Contact requests — same pattern.
            $pendingContacts = $contactService->getPendingContactRequests() ?: [];
            foreach ($pendingContacts as $c) {
                $createdAt = isset($c['created_at']) ? strtotime((string) $c['created_at']) : 0;
                if ($createdAt > $since) {
                    $payload['new']['contact_requests'][] = [
                        'pubkey_hash' => $c['pubkey_hash'] ?? null,
                        'http_address' => $c['http_address'] ?? null,
                        'tor_address' => $c['tor_address'] ?? null,
                        'created_at' => $createdAt,
                    ];
                }
                if (count($payload['new']['contact_requests']) >= $maxPerKind) {
                    break;
                }
            }

            // Transactions — dedicated SQL time-filter, bounded.
            $txRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);
            $txRows = $txRepo->getIncomingSince($since, $maxPerKind);
            foreach ($txRows as $tx) {
                $tsEpoch = isset($tx['timestamp']) ? strtotime((string) $tx['timestamp']) : 0;
                $payload['new']['transactions'][] = [
                    'txid' => $tx['txid'] ?? null,
                    'type' => $tx['type'] ?? null,
                    'status' => $tx['status'] ?? null,
                    'amount' => $tx['amount'] ?? null,
                    'currency' => $tx['currency'] ?? null,
                    'sender_address' => $tx['sender_address'] ?? null,
                    'receiver_address' => $tx['receiver_address'] ?? null,
                    'description' => $tx['description'] ?? null,
                    'timestamp' => $tsEpoch,
                ];
            }

            // DLQ — dedicated SQL time-filter, bounded.
            $dlqRepoPoll = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\DeadLetterQueueRepository::class);
            $dlqRows = $dlqRepoPoll->getItemsSince($since, $maxPerKind);
            foreach ($dlqRows as $d) {
                $createdAt = isset($d['created_at']) ? strtotime((string) $d['created_at']) : 0;
                $payload['new']['dlq'][] = [
                    'id' => $d['id'] ?? null,
                    'message_type' => $d['message_type'] ?? null,
                    'message_id' => $d['message_id'] ?? null,
                    'status' => $d['status'] ?? null,
                    'created_at' => $createdAt,
                ];
            }
        } catch (\Throwable $e) {
            \Eiou\Utils\Logger::getInstance()->logException($e, ['context' => 'check_incoming_handler']);
            // Don't leak internals; return empty deltas so the client
            // backs off gracefully on transient server errors.
        }
    }

    echo json_encode($payload);
    exit;
}

// Get message from session flash messages (set by controllers, read-once)
// Flash messages are cleared after reading so they don't re-appear on refresh
if (isset($_SESSION[SessionKeys::MESSAGE])) {
    $messageForDisplay = $_SESSION[SessionKeys::MESSAGE];
    $messageTypeForDisplay = $_SESSION[SessionKeys::MESSAGE_TYPE] ?? 'info';
    unset($_SESSION[SessionKeys::MESSAGE], $_SESSION[SessionKeys::MESSAGE_TYPE]);
} else {
    $messageForDisplay = '';
    $messageTypeForDisplay = '';
}

// Get user based data
$maxDisplayLines = $user->getMaxOutput();
$totalBalance = $transactionService->getUserTotalBalance();
$totalEarningsSplit = $p2pService->getUserTotalEarnings();
$totalEarnings = ($totalEarningsSplit instanceof \Eiou\Core\SplitAmount) ? $currencyUtility->convertMinorToMajor($totalEarningsSplit) : 0;

// Per-currency balance data for future-proof dashboard display
$totalBalanceByCurrency = [];
$balancesRaw = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\BalanceRepository::class)->getUserBalance();
if (!empty($balancesRaw)) {
    foreach ($balancesRaw as $bal) {
        $totalBalanceByCurrency[] = [
            'currency' => $bal['currency'],
            'total' => number_format(
                ($bal['total_balance'] instanceof \Eiou\Core\SplitAmount) ? $currencyUtility->convertMinorToMajor($bal['total_balance'], $bal['currency']) : 0,
                displayDecimals()
            )
        ];
    }
}

// Per-currency earnings data for future-proof dashboard display
$totalEarningsByCurrency = [];
$earningsRaw = $p2pService->getUserTotalEarningsByCurrency();
if (!empty($earningsRaw)) {
    foreach ($earningsRaw as $earn) {
        $totalEarningsByCurrency[] = [
            'currency' => $earn['currency'],
            'total' => number_format(
                ($earn['total_amount'] instanceof \Eiou\Core\SplitAmount) ? $currencyUtility->convertMinorToMajor($earn['total_amount'], $earn['currency']) : 0,
                displayDecimals()
            )
        ];
    }
}
// Collect known currencies from all data sources for consistent fallback display
$knownCurrencies = [];
foreach ($totalBalanceByCurrency as $item) {
    $knownCurrencies[$item['currency']] = true;
}
foreach ($totalEarningsByCurrency as $item) {
    $knownCurrencies[$item['currency']] = true;
}
// totalAvailableCreditByCurrency is populated later, so also check contact_credit directly
try {
    $creditCurrencies = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCreditRepository::class)->getTotalAvailableCreditByCurrency();
    foreach ($creditCurrencies as $row) {
        $knownCurrencies[$row['currency']] = true;
    }
} catch (Exception $e) {
    // Non-critical
}
// Include currencies from accepted contact currency relationships
try {
    $acceptedCurrencies = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class)->getDistinctAcceptedCurrencies();
    foreach ($acceptedCurrencies as $cur) {
        $knownCurrencies[$cur] = true;
    }
} catch (Exception $e) {
    // Non-critical
}
$knownCurrencies = array_keys($knownCurrencies);
// Sort by allowed currencies order (first currency = first row, etc.)
$allowedCurrenciesOrder = $user->getAllowedCurrencies();
usort($knownCurrencies, function($a, $b) use ($allowedCurrenciesOrder) {
    $posA = array_search($a, $allowedCurrenciesOrder);
    $posB = array_search($b, $allowedCurrenciesOrder);
    if ($posA === false) $posA = PHP_INT_MAX;
    if ($posB === false) $posB = PHP_INT_MAX;
    return $posA - $posB;
});

$recentTransactionsLimit = $user->getDisplayRecentTransactionsLimit();
$transactions = $transactionService->getTransactionHistory($recentTransactionsLimit);
// Scope the In-Progress banner to the same user-configured row limit as
// the Recent Transactions view. Was hard-coded at 5, which undercounted
// whenever a bulk batch or chain-gap backlog produced more in-flight
// sends than that — users saw "5" on the badge with no way to see the
// rest without opening the DB. getMaxOutput() already caps the Recent
// Transactions table, the contact modal transactions list, and payment
// request history, so the three views stay consistent.
$inProgressTransactions = $transactionService->getInProgressTransactions($recentTransactionsLimit);

// Update check status (reads cache only — never triggers a new check on page load)
$updateCheckStatus = \Eiou\Services\UpdateCheckService::getStatus();

// "What's New" notification (shown after version upgrade until dismissed).
// `fresh`    — node is on the version it was first installed on
// `upgraded` — node has seen a previous version's banner and is now newer
// null       — no banner (already dismissed this version, or pre-setup)
$whatsNewVariant = \Eiou\Services\UpdateCheckService::getWhatsNewVariant();

// Analytics status (reads cache only — never triggers a new submission on page load)
try {
    $analyticsStatus = \Eiou\Services\AnalyticsService::getStatus();
} catch (\Throwable $e) {
    $analyticsStatus = [];
}

// Check Tor/SOCKS5 GUI status (written by TransportUtilityService and startup.sh watchdog)
$torGuiStatus = null;
$torGuiStatusFile = '/tmp/tor-gui-status';
if (file_exists($torGuiStatusFile)) {
    $torGuiRaw = file_get_contents($torGuiStatusFile);
    if ($torGuiRaw !== false) {
        $torGuiData = json_decode($torGuiRaw, true);
        if (is_array($torGuiData) && isset($torGuiData['status'], $torGuiData['timestamp'])) {
            $torGuiAge = time() - (int)$torGuiData['timestamp'];
            if ($torGuiData['status'] === 'recovered' && $torGuiAge > 300) {
                // Recovery older than 5 minutes — clean up
                unlink($torGuiStatusFile);
            } elseif ($torGuiAge > 600) {
                // Any status older than 10 minutes — stale, clean up
                @unlink($torGuiStatusFile);
            } else {
                $torGuiStatus = $torGuiData;
            }
        }
    }
}

// Track completed transactions for notifications (sent transactions)
// Get previously known in-progress txids from session
$prevInProgressTxids = $_SESSION[SessionKeys::IN_PROGRESS_TXIDS] ?? [];

// Get current in-progress transaction IDs
$currentInProgressTxids = array_column($inProgressTransactions ?? [], 'txid');

// Find completed txids (were in progress, now are not)
$completedTxids = array_diff($prevInProgressTxids, $currentInProgressTxids);

// Get details for completed transactions
$newlyCompletedTransactions = [];
foreach ($completedTxids as $txid) {
    // Check if the transaction is now completed
    foreach ($transactions ?? [] as $tx) {
        if (($tx['txid'] ?? '') === $txid && ($tx['status'] ?? '') === 'completed') {
            $newlyCompletedTransactions[] = $tx;
            break;
        }
    }
}

// Store current in-progress txids for next comparison
$_SESSION[SessionKeys::IN_PROGRESS_TXIDS] = $currentInProgressTxids;

// Track received transactions for notifications
// Received transactions bypass in-progress tracking (they arrive completed),
// so we detect them by comparing known txids across page loads
$currentTxids = array_column($transactions ?? [], 'txid');
$prevKnownTxids = $_SESSION[SessionKeys::KNOWN_TXIDS] ?? null;
$newlyReceivedTransactions = [];

// Only detect new transactions if we have a previous baseline (skip first page load)
if ($prevKnownTxids !== null) {
    $newTxids = array_diff($currentTxids, $prevKnownTxids);
    foreach ($newTxids as $txid) {
        foreach ($transactions as $tx) {
            if (($tx['txid'] ?? '') === $txid && ($tx['type'] ?? '') === 'received' && ($tx['tx_type'] ?? '') !== 'contact') {
                $newlyReceivedTransactions[] = $tx;
                break;
            }
        }
    }
}

$_SESSION[SessionKeys::KNOWN_TXIDS] = $currentTxids;

// Contact data
$allContacts = $contactService->getAllContacts();
$pendingContacts = $contactService->getPendingContactRequests();

// Check if pending contacts have prior transaction history (wallet restore scenario)
// and retrieve the description from the contact transaction (sent with the request)
if (!empty($pendingContacts) && $user->has('public')) {
    $txRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);
    $txContactRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\TransactionContactRepository::class);
    $myPubkey = $user->getPublicKey();
    foreach ($pendingContacts as &$pc) {
        // Check for prior non-contact transaction history
        $history = $txRepo->getNonContactTransactionsBetweenPubkeys($myPubkey, $pc['pubkey'], 1);
        $pc['has_prior_history'] = !empty($history);

        // Get the contact transaction description (message sent with the request)
        $contactTx = $txContactRepo->getContactTransactionByParties($pc['pubkey'], $myPubkey);
        $desc = $contactTx['description'] ?? null;
        if ($desc !== null && $desc !== 'Contact request' && $desc !== 'Contact request transaction') {
            $pc['contact_description'] = $desc;
        }
    }
    unset($pc);
}

// Enrich pending contacts with per-currency data from contact_currencies (direction-aware)
if (!empty($pendingContacts)) {
    try {
        $pendingCurrencyRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        foreach ($pendingContacts as &$pc) {
            $hash = $pc['pubkey_hash'] ?? '';
            if ($hash) {
                // Incoming: currencies THEY requested from us (we need to accept/reject)
                $pc['pending_currencies'] = $pendingCurrencyRepo->getPendingCurrencies($hash, 'incoming');
                // Outgoing: currencies WE requested from them (waiting for their acceptance)
                $pc['outgoing_currencies'] = $pendingCurrencyRepo->getPendingCurrencies($hash, 'outgoing');

                // Enrich pending currencies with descriptions from contact transactions
                $descByCurrency = $txContactRepo->getContactDescriptionsByCurrency($pc['pubkey'], $myPubkey);
                if (!empty($descByCurrency)) {
                    foreach ($pc['pending_currencies'] as &$pcur) {
                        $cur = $pcur['currency'] ?? '';
                        if (isset($descByCurrency[$cur])) {
                            $pcur['description'] = $descByCurrency[$cur];
                        }
                    }
                    unset($pcur);
                }
            }
        }
        unset($pc);
    } catch (Exception $e) {
        // Non-critical — pending contacts will show without currency data
    }
}

// Single knob: the user-editable "GUI/CLI Max Output Lines" setting drives
// both how many recent-tx rows we pre-fetch per contact (server side) AND how
// many the modal displays (via $maxDisplayLines). Keeps pre-fetch and display
// in lockstep so the modal never shows fewer rows than the user asked for
// because of a starved pre-load.
//
// Fetch all three status buckets in a single DB query (getContactsGroupedByStatus)
// and batch-fetch recent transactions for every contact in one ranked query
// (inside contactBalanceConversion). Prior pattern ran 3 × SELECT on contacts
// plus N × SELECT on transactions where N = total contact count across buckets.
$contactTxLimit = $user->getMaxOutput();
$contactsByStatus = $contactService->getContactsGroupedByStatus();
$pendingUserContacts = $transactionService->contactBalanceConversion($contactsByStatus['user_pending'], $contactTxLimit);
$acceptedContacts    = $transactionService->contactBalanceConversion($contactsByStatus['accepted'],     $contactTxLimit);
$blockedContacts     = $transactionService->contactBalanceConversion($contactsByStatus['blocked'],      $contactTxLimit);

// Count accepted contacts with a chain state that requires user attention, so
// the Contacts tab can show a count badge (like Activity's DLQ badge). Includes
// incoming tx drop proposals to accept/reject, rejected proposals still
// blocking the chain, and raw chain gaps that need resolution. Waiting
// (outgoing proposal not yet answered) is not counted — nothing for the user
// to do on their side while the peer deliberates.
$contactsNeedingChainActionCount = 0;
foreach ($acceptedContacts as $c) {
    $proposal = $c['chain_drop_proposal'] ?? null;
    $isIncoming = $proposal && ($proposal['direction'] ?? '') === 'incoming' && ($proposal['status'] ?? '') === 'pending';
    $isRejected = $proposal && ($proposal['status'] ?? '') === 'rejected';
    $validChain = $c['valid_chain'] ?? null;
    if ($isIncoming || $isRejected || $validChain === 0) {
        $contactsNeedingChainActionCount++;
    }
}

// Enrich pending user contacts (our outgoing requests) with direction-aware currency data
if (!empty($pendingUserContacts)) {
    try {
        $currencyRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        foreach ($pendingUserContacts as &$puc) {
            $hash = $puc['pubkey_hash'] ?? '';
            if ($hash) {
                $puc['pending_currencies'] = $currencyRepo->getPendingCurrencies($hash, 'incoming');
                $puc['outgoing_currencies'] = $currencyRepo->getPendingCurrencies($hash, 'outgoing');
            }
        }
        unset($puc);
    } catch (Exception $e) {}
}

// Enrich accepted contacts with pending incoming currency requests
if (!empty($acceptedContacts)) {
    try {
        $currencyRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        foreach ($acceptedContacts as &$ac) {
            $hash = $ac['pubkey_hash'] ?? '';
            if ($hash) {
                $ac['pending_currencies'] = $currencyRepo->getPendingCurrencies($hash, 'incoming');
                $ac['outgoing_currencies'] = $currencyRepo->getPendingCurrencies($hash, 'outgoing');
            }
        }
        unset($ac);
    } catch (Exception $e) {}
}

// Build lookup of contacts we already have (accepted or our outgoing pending)
$existingContactHashes = [];
foreach ($acceptedContacts as $ac) {
    if (!empty($ac['pubkey_hash'])) {
        $existingContactHashes[$ac['pubkey_hash']] = true;
    }
}
foreach ($pendingUserContacts as $puc) {
    if (!empty($puc['pubkey_hash'])) {
        $existingContactHashes[$puc['pubkey_hash']] = true;
    }
}

// Mark pending contact requests that already exist in our contact list
// so the template uses acceptCurrency instead of acceptContact
foreach ($pendingContacts as &$pc) {
    $hash = $pc['pubkey_hash'] ?? '';
    if ($hash && isset($existingContactHashes[$hash])) {
        $pc['is_existing_contact'] = true;
    }
}
unset($pc);

// Also show named pending contacts with incoming currencies in the standalone section
// Keep them in $pendingUserContacts too so they still appear in the contacts grid
$pendingContactHashes = [];
foreach ($pendingContacts as $pc) {
    if (!empty($pc['pubkey_hash'])) {
        $pendingContactHashes[$pc['pubkey_hash']] = true;
    }
}

foreach ($pendingUserContacts as $puc) {
    if (!empty($puc['pending_currencies']) && !isset($pendingContactHashes[$puc['pubkey_hash'] ?? ''])) {
        $puc['is_existing_contact'] = true;
        $pendingContacts[] = $puc;
    }
}

// Also add accepted contacts with pending incoming currencies to $pendingContacts
// (they stay in $acceptedContacts too — grid shows them as accepted, standalone section shows accept form)
foreach ($acceptedContacts as $c) {
    if (!empty($c['pending_currencies']) && !isset($pendingContactHashes[$c['pubkey_hash'] ?? ''])) {
        $c['is_existing_contact'] = true;
        $pendingContacts[] = $c;
    }
}
// $pendingCurrencyContacts no longer needed for separate notification
$pendingCurrencyContacts = [];

// Address types (dynamic from database schema)
$addressTypes = $contactService->getAllAddressTypes();

// Tx drop proposals - fetch both directions, index by contact hash
$chainDropProposalsByContact = [];
try {
    $chainDropService = $serviceContainer->getChainDropService();
    $chainDropProposalRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ChainDropProposalRepository::class);

    $incomingProposals = $chainDropService->getIncomingPendingProposals();
    $outgoingProposals = $chainDropProposalRepo->getOutgoingPending();
    $rejectedProposals = $chainDropProposalRepo->getRecentRejected();

    // Index by contact_pubkey_hash (incoming pending > outgoing pending > rejected)
    foreach ($incomingProposals as $proposal) {
        $hash = $proposal['contact_pubkey_hash'];
        if (!isset($chainDropProposalsByContact[$hash])) {
            $chainDropProposalsByContact[$hash] = $proposal;
        }
    }
    foreach ($outgoingProposals as $proposal) {
        $hash = $proposal['contact_pubkey_hash'];
        if (!isset($chainDropProposalsByContact[$hash])) {
            $chainDropProposalsByContact[$hash] = $proposal;
        }
    }
    foreach ($rejectedProposals as $proposal) {
        $hash = $proposal['contact_pubkey_hash'];
        if (!isset($chainDropProposalsByContact[$hash])) {
            $chainDropProposalsByContact[$hash] = $proposal;
        }
    }
} catch (Exception $e) {
    $chainDropProposalsByContact = [];
}

// Merge tx drop proposals into contact arrays by pubkey_hash
$contactArrays = [&$acceptedContacts, &$pendingUserContacts, &$blockedContacts];
foreach ($contactArrays as &$contacts) {
    foreach ($contacts as &$contact) {
        $hash = $contact['pubkey_hash'] ?? '';
        if ($hash && isset($chainDropProposalsByContact[$hash])) {
            $contact['chain_drop_proposal'] = $chainDropProposalsByContact[$hash];
        }
    }
    unset($contact);
}
unset($contacts);

// Compute chain gap details for contacts with invalid chains
// Shows which txids are valid before/after each gap so users can investigate
$tcRepo = null;
try {
    $tcRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\TransactionChainRepository::class);
} catch (Exception $e) {
    // Repository not available, skip gap details
}
if ($tcRepo && $user->has('public')) {
    $myPubkey = $user->getPublicKey();
    foreach ($acceptedContacts as &$contact) {
        // Compute gap details when the chain is known-invalid OR when there is an
        // active tx-drop proposal (covers the window between proposal creation and
        // the first "Check Status" ping that would set valid_chain = 0 in the DB).
        $hasInvalidChain = (int)($contact['valid_chain'] ?? -1) === 0;
        $hasActiveProposal = !empty($contact['chain_drop_proposal'])
            && in_array(
                $contact['chain_drop_proposal']['status'] ?? '',
                ['pending', 'awaiting_acceptance', 'rejected'],
                true
            );
        if (($hasInvalidChain || $hasActiveProposal) && !empty($contact['pubkey'])) {
            try {
                $integrity = $tcRepo->verifyChainIntegrity($myPubkey, $contact['pubkey']);
                if (!$integrity['valid'] && !empty($integrity['gap_context'])) {
                    $contact['chain_gap_details'] = $integrity['gap_context'];
                }
            } catch (Exception $e) {
                // Skip this contact's gap details on error
            }
        }
    }
    unset($contact);
}

// Dead Letter Queue - track newly added items for notification
$newlyAddedToDlq = [];
try {
    $dlqRepository = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\DeadLetterQueueRepository::class);
    $currentDlqItems = $dlqRepository->getPendingItems(50);

    // Get previously known DLQ item IDs from session
    $prevDlqIds = $_SESSION[SessionKeys::KNOWN_DLQ_IDS] ?? [];

    // Find items that are new (not previously seen)
    $currentDlqIds = array_column($currentDlqItems, 'id');
    foreach ($currentDlqItems as $item) {
        if (!in_array($item['id'], $prevDlqIds)) {
            $newlyAddedToDlq[] = $item;
        }
    }

    // Update session with current DLQ IDs
    $_SESSION[SessionKeys::KNOWN_DLQ_IDS] = $currentDlqIds;
} catch (Exception $e) {
    // Silently fail - DLQ notification is non-critical
    $newlyAddedToDlq = [];
}

// Dead Letter Queue - load items for the DLQ management section
$dlqItems = [];
$dlqStats = [];
$dlqPendingCount = 0;
try {
    $dlqRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\DeadLetterQueueRepository::class);
    // Always load all items — client-side JS handles tab filtering with no page reload
    $dlqItems = $dlqRepo->getItems(null, \Eiou\Core\Constants::DLQ_BATCH_SIZE);

    $dlqStats        = $dlqRepo->getStatistics();
    $dlqPendingCount = $dlqRepo->getPendingCount();

    // Collect message_ids for active (pending/retrying) transaction DLQ entries so
    // the transaction history can show a DLQ indicator on affected transactions.
    $dlqActiveTxMessageIds = [];
    $activeTxDlq = array_merge(
        $dlqRepo->getByMessageType('transaction', 'pending',  \Eiou\Core\Constants::DLQ_BATCH_SIZE),
        $dlqRepo->getByMessageType('transaction', 'retrying', \Eiou\Core\Constants::DLQ_BATCH_SIZE)
    );
    foreach ($activeTxDlq as $dlqEntry) {
        $dlqActiveTxMessageIds[] = $dlqEntry['message_id'];
    }
} catch (Exception $e) {
    // Silently fail - DLQ section is non-critical
    $dlqActiveTxMessageIds = [];
}

// Fetch available credit per contact and merge into contact arrays
$availableCreditByContact = [];
$totalAvailableCreditByCurrency = [];
try {
    $contactCreditRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCreditRepository::class);
    // Get per-contact credits for merging into contact cards
    foreach (array_merge($acceptedContacts, $pendingUserContacts, $blockedContacts) as $c) {
        $hash = $c['pubkey_hash'] ?? '';
        if ($hash && !isset($availableCreditByContact[$hash])) {
            $creditData = $contactCreditRepo->getAvailableCredit($hash);
            if ($creditData !== null) {
                $contactCurrency = $c['currency'] ?? \Eiou\Core\Constants::TRANSACTION_DEFAULT_CURRENCY;
                $availableCreditByContact[$hash] = $creditData['available_credit']->toMajorUnits();
            }
        }
    }
    // Get totals per currency for dashboard display
    $creditTotals = $contactCreditRepo->getTotalAvailableCreditByCurrency();
    foreach ($creditTotals as $row) {
        $totalAvailableCreditByCurrency[] = [
            'currency' => $row['currency'],
            'total' => number_format($row['total_available_credit']->toMajorUnits(), displayDecimals())
        ];
    }
} catch (Exception $e) {
    $availableCreditByContact = [];
    $totalAvailableCreditByCurrency = [];
}

// Fetch per-contact currency configs for multi-currency support
$contactCurrenciesByHash = [];
try {
    $contactCurrencyRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
    foreach (array_merge($acceptedContacts, $pendingUserContacts, $blockedContacts) as $c) {
        $hash = $c['pubkey_hash'] ?? '';
        if ($hash && !isset($contactCurrenciesByHash[$hash])) {
            $contactCurrenciesByHash[$hash] = $contactCurrencyRepo->getContactCurrencies($hash);
        }
    }
} catch (Exception $e) {
    $contactCurrenciesByHash = [];
}

// Fetch per-contact all-currency available credits
$availableCreditAllByHash = [];
try {
    foreach (array_merge($acceptedContacts, $pendingUserContacts, $blockedContacts) as $c) {
        $hash = $c['pubkey_hash'] ?? '';
        if ($hash && !isset($availableCreditAllByHash[$hash])) {
            $allCredits = $contactCreditRepo->getAvailableCreditAllCurrencies($hash);
            $creditMap = [];
            foreach ($allCredits as $cr) {
                $cur = $cr['currency'] ?? \Eiou\Core\Constants::TRANSACTION_DEFAULT_CURRENCY;
                $creditMap[$cur] = $cr['available_credit']->toMajorUnits();
            }
            $availableCreditAllByHash[$hash] = $creditMap;
        }
    }
} catch (Exception $e) {
    $availableCreditAllByHash = [];
}

// Merge available credit into contact arrays and calculate their available credit with me
$contactArraysForCredit = [&$acceptedContacts, &$pendingUserContacts, &$blockedContacts];
foreach ($contactArraysForCredit as &$contacts) {
    foreach ($contacts as &$contact) {
        $hash = $contact['pubkey_hash'] ?? '';
        // My available credit with them (from pong, stored in contact_credit)
        $contact['my_available_credit'] = $availableCreditByContact[$hash] ?? null;
        // Their available credit is now computed per-currency in the currencies array below
        $contact['their_available_credit'] = null;

        // Build multi-currency data (direction-aware)
        $currencyConfigs = $contactCurrenciesByHash[$hash] ?? [];
        $allCredits = $availableCreditAllByHash[$hash] ?? [];
        $acceptedCurrencies = [];
        $pendingIncoming = [];
        $pendingOutgoing = [];
        $contactBalancesByCurrency = $contact['balances_by_currency'] ?? [];
        foreach ($currencyConfigs as $cc) {
            $cur = $cc['currency'];
            $ccStatus = $cc['status'] ?? 'accepted';
            $ccDirection = $cc['direction'] ?? 'outgoing';
            $creditLimitMajor = ($cc['credit_limit'] instanceof \Eiou\Core\SplitAmount) ? $cc['credit_limit']->toMajorUnits() : 0;
            $balanceForCur = floatval($contactBalancesByCurrency[$cur] ?? 0);
            $entry = [
                'currency' => $cur,
                'fee' => ($cc['fee_percent'] ?? 0) / \Eiou\Core\Constants::FEE_CONVERSION_FACTOR,
                'credit_limit' => $creditLimitMajor,
                'my_available_credit' => $allCredits[$cur] ?? null,
                'their_available_credit' => ($creditLimitMajor > 0 || $balanceForCur != 0) ? round($creditLimitMajor - $balanceForCur, 2) : null,
                'status' => $ccStatus,
                'direction' => $ccDirection,
            ];
            if ($ccStatus === 'accepted') {
                // Deduplicate: keep one entry per currency (prefer outgoing — has our fee/credit)
                if (!isset($acceptedCurrencies[$cur]) || $ccDirection === 'outgoing') {
                    $acceptedCurrencies[$cur] = $entry;
                }
            } elseif ($ccStatus === 'pending') {
                if ($ccDirection === 'incoming') {
                    $pendingIncoming[] = $entry;
                } else {
                    $pendingOutgoing[] = $entry;
                }
            }
        }
        $contact['currencies'] = array_values($acceptedCurrencies);
        $contact['pending_currencies'] = $pendingIncoming;
        $contact['outgoing_currencies'] = $pendingOutgoing;
    }
    unset($contact);
}
unset($contacts);

// Load payment requests for display in the Send tab
$paymentRequests = ['incoming' => [], 'outgoing' => []];
$pendingPaymentRequestCount = 0;
try {
    $paymentRequestLimit = $recentTransactionsLimit;
    $paymentRequests = $serviceContainer->getPaymentRequestService()->getAllForDisplay($paymentRequestLimit);
    $pendingPaymentRequestCount = $serviceContainer->getPaymentRequestService()->countPendingIncoming();
} catch (Exception $e) {
    // Non-critical — payment requests section will be empty
}

// Initialize ContactDataBuilder helper
$contactDataBuilder = new ContactDataBuilder($addressTypes);