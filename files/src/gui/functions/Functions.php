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
//
// WalletTemplateHelpers is the post-auth counterpart — helpers that reach
// into `Application::getInstance()` / the repository factory and therefore
// can't safely run from the login page. Kept split so the purity contract
// on TemplateHelpers.php stays honest.
// =========================================================================
require_once __DIR__ . '/TemplateHelpers.php';
require_once __DIR__ . '/WalletTemplateHelpers.php';

// Register inline (no-controller) POST handlers with the shared
// GuiActionRegistry so the dispatcher below routes them. This file
// captures the file-scope variables (serviceContainer, secureSession,
// user, contactService, transactionService) into the closures via
// `use(...)`. Required BEFORE the dispatcher so registrations are in
// place when the dispatcher inspects the registry.
require_once __DIR__ . '/coreInlineActions.php';

// Route controllers if POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Plugin / registry-dispatched POST actions. The action registry is
    // populated by plugins' boot() (in Application::bootAll, which runs
    // long before this file is included). Checked first so a plugin can
    // also override a core action by registering one with the matching
    // name — last-write-wins is the documented contract.
    //
    // The registry enforces tier gates here (CSRF + sensitive-access),
    // then hands the request to the plugin handler. Handlers emit their
    // own response (JSON or redirect) and exit; the trailing exit below
    // is defensive in case a handler forgets.
    //
    // Failures emit a JSON envelope and exit so an XHR submit gets a
    // structured error instead of an HTML page. See
    // docs/PLUGIN_GUI_HOOKS.md.
    $actionRegistry = $serviceContainer->getActionRegistry();
    if ($actionRegistry->has($action)) {
        if ($actionRegistry->requiresCsrf($action)) {
            if (empty($_POST['csrf_token']) || !$secureSession->validateCSRFToken($_POST['csrf_token'], false)) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'csrf_error', 'message' => 'Invalid CSRF token']);
                exit;
            }
        }
        if ($actionRegistry->requiresSensitiveAccess($action) && !$secureSession->hasSensitiveAccess()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'sensitive_access_required', 'message' => 'Sensitive access required']);
            exit;
        }
        try {
            ($actionRegistry->getHandler($action))($_POST);
        } catch (\Throwable $e) {
            \Eiou\Utils\Logger::getInstance()->logException($e, [
                'context' => 'gui_action_registry_dispatch',
                'action' => $action,
                'plugin' => $actionRegistry->getPluginId($action),
            ]);
            if (!headers_sent()) {
                header('Content-Type: application/json');
                http_response_code(500);
            }
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Contact actions migrated to GuiActionRegistry. Registered in
    // index.html via ContactController::registerActions(); covers
    // addContact, acceptContact, addCurrency, acceptCurrency,
    // acceptAllCurrencies, applyContactDecisions, declineCurrency,
    // declineContact, deleteContact, blockContact, unblockContact,
    // editContact, pingContact, proposeChainDrop, acceptChainDrop,
    // rejectChainDrop. (`addCurrency` was unreachable here pre-
    // migration; the handler exists but no GUI form posts it today.)

    // Transaction actions
    if (in_array($action, ['sendEIOU'])) {
        $transactionController->routeAction();
    }

    // Payment request actions migrated to GuiActionRegistry. See
    // Phase B in CORE_ACTION_MIGRATION.md. Registered in index.html via
    // PaymentRequestController::registerActions(); the dispatcher at
    // the top of this file routes them before reaching the if-ladder.
    // (declineAllPaymentRequests + cancelAllPaymentRequests were
    // unreachable here pre-migration even though their GUI buttons
    // existed — registering them now makes those buttons work.)

    // Settings actions (updateSettings, resetToDefaults, clearDebugLogs,
    // sendDebugReport, analyticsConsent, getDebugReportJson,
    // submitDebugReport) migrated to GuiActionRegistry. Registered in
    // index.html via SettingsController::registerActions(); the
    // dispatcher at the top of this file routes them before reaching
    // this if-ladder. See CORE_ACTION_MIGRATION.md.

    // AJAX-only plugin actions (returns JSON, exits immediately)
    if (in_array($action, ['pluginsList', 'pluginsToggle', 'pluginsRequestRestart', 'pluginChangelog', 'pluginsUninstall'], true)) {
        if ($pluginController === null) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'plugin_loader_unavailable',
                'message' => 'Plugin system is not initialized.'
            ]);
            exit;
        }
        try {
            $pluginController->routeAction();
        } catch (\Eiou\Gui\Controllers\PluginControllerResponseSent $sent) {
            // Response already emitted by the controller — fall through to exit.
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // (whatsNewDismiss + whatsNewNotes + getDebugReportJson +
    // submitDebugReport migrated to GuiActionRegistry — see
    // coreInlineActions.php for whatsNew*, the SettingsController
    // pointer above for the debug-report endpoints.)

    // (pingContact + proposeChainDrop + acceptChainDrop +
    // rejectChainDrop migrated to GuiActionRegistry alongside the
    // other ContactController actions — see the consolidated note
    // above.)

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

    // (revokeRememberSession + revokeAllRememberSessions migrated to
    // GuiActionRegistry — see coreInlineActions.php.)

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

    // (searchTransactions, searchPaymentRequests, loadMoreTransactions,
    // loadMoreContacts, loadMorePaymentRequests migrated to GuiActionRegistry —
    // see coreInlineActions.php. paybackMethods* migrated via
    // PaybackMethodsController::registerActions(). dlq* migrated via
    // DlqController::registerActions().)
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

    // Known limitation — burst overflow: if >$maxPerKind events of the
    // same kind arrive inside one poll window, the surplus is silently
    // dropped from the toast stream (the data is still in the tables,
    // just not notified). A complete fix would need (a) deterministic
    // ASC ordering across all four kinds — getPendingIncoming and
    // getPendingContactRequests currently rely on MySQL's undefined row
    // order — and (b) an effective_now cursor of min($now, newest_returned)
    // when any kind hits its cap, so the next poll backfills. Scope
    // judged not worth it for ALPHA: bursts >25 events/10s are unusual
    // for a wallet, and the UI tables remain complete.
    //
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
            // getPendingIncoming() lives on the repository, not the service
            // (the service only exposes countPendingIncoming). Pulling the
            // repo directly is the existing pattern for DLQ access below.
            $prRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\PaymentRequestRepository::class);
            $pendingPr = $prRepo->getPendingIncoming() ?: [];
            foreach ($pendingPr as $pr) {
                $createdAt = isset($pr['created_at']) ? strtotime((string) $pr['created_at']) : 0;
                if ($createdAt > $since) {
                    // $pr['amount'] is hydrated by PaymentRequestRepository's
                    // splitAmountColumns → a SplitAmount object. Collapse to a
                    // display float here; serializing the object directly would
                    // ship `{whole, frac}` to the client, which the JS toast
                    // template string-concats into "[object Object] USD".
                    $prAmount = $pr['amount'] ?? null;
                    if ($prAmount instanceof \Eiou\Core\SplitAmount) {
                        $prAmount = $prAmount->toMajorUnits();
                    }
                    $payload['new']['payment_requests'][] = [
                        'id' => $pr['request_id'] ?? ($pr['id'] ?? null),
                        'amount' => $prAmount,
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

            // Contact requests. Address types are discovered dynamically from
            // the `addresses` schema via AddressRepository::getAllAddressTypes()
            // — the same INFORMATION_SCHEMA-backed helper ApiController already
            // uses throughout. Adding a future transport column (say `i2p`) to
            // the addresses table flows through to the live-notif payload with
            // zero changes here, and the client iterates whatever keys arrive
            // under `addresses`.
            $addressRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\AddressRepository::class);
            $addressTypes = $addressRepo->getAllAddressTypes();
            $pendingContacts = $contactService->getPendingContactRequests() ?: [];
            foreach ($pendingContacts as $c) {
                $createdAt = isset($c['created_at']) ? strtotime((string) $c['created_at']) : 0;
                if ($createdAt > $since) {
                    $addresses = [];
                    foreach ($addressTypes as $type) {
                        if (!empty($c[$type])) {
                            $addresses[$type] = $c[$type];
                        }
                    }
                    $payload['new']['contact_requests'][] = [
                        'pubkey_hash' => $c['pubkey_hash'] ?? null,
                        'addresses' => $addresses,
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
        if ($desc !== null
            && $desc !== 'Contact request'
            && $desc !== 'Contact request transaction'
            && preg_match('/^Contact request \([A-Z0-9]{3,9}\)$/', $desc) !== 1) {
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

// "Accepted contacts with pending incoming currencies get re-added to
// $pendingContacts" — deferred until after the $acceptedContacts entries
// have had $contact['currencies'] populated (that happens in the
// merge-credit loop ~250 lines below). Doing it here would capture
// entries with no `currencies` key, which would make the
// has_no_active_currencies detection always think the contact has no
// active lines — wrong for a partially-accepted contact.
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

// Re-add accepted contacts with pending incoming currencies to
// $pendingContacts so the standalone section shows the accept form.
// Placed here (after the merge-credit loop populates $c['currencies']
// with accepted-status-only rows) so has_no_active_currencies is
// computed against the true count of active currency lines — not
// against a null/unset field.
foreach ($acceptedContacts as $c) {
    if (!empty($c['pending_currencies']) && !isset($pendingContactHashes[$c['pubkey_hash'] ?? ''])) {
        $c['is_existing_contact'] = true;
        // Flag: contact.status='accepted' but ZERO rows in
        // contact_currencies are status='accepted'. Most common after
        // unblockContact() (which only flips contact status, not
        // currency rows). The UI shows a louder yellow warning in
        // this case — a contact with no active currency lines can't
        // send, receive, or P2P-relay until at least one is accepted.
        // `currencies` here is the accepted-only list set above.
        $c['has_no_active_currencies'] = empty($c['currencies']);
        $pendingContacts[] = $c;
        $pendingContactHashes[$c['pubkey_hash'] ?? ''] = true;
    }
}

// GUI hook registry — exposed to every template partial below so
// plugins can inject HTML at named fire sites. doRender('foo', $ctx)
// returns '' when no plugin has subscribed, so all hook fires are
// safe to scatter through the templates without per-call guards.
// See docs/PLUGIN_GUI_HOOKS.md.
$hooks = $serviceContainer->getHooks();

// Drain the plugin asset registry into the three asset hook slots.
// This is the host's contribution to gui.head.styles /
// gui.head.scripts / gui.footer.scripts — plugins enqueue files; the
// host renders them inline with the page's CSP nonce. Listeners
// register at priority 5 so they emit before any plugin's own
// render listener at the default priority 10. Functions.php is
// require_once'd so this registers exactly once per request.
$assetRegistry = $serviceContainer->getAssetRegistry();
$hooks->onRender('gui.head.styles', function () use ($assetRegistry) {
    return $assetRegistry->renderStyles(cspNonce());
}, 5);
$hooks->onRender('gui.head.scripts', function () use ($assetRegistry) {
    return $assetRegistry->renderScripts(cspNonce(), true);
}, 5);
$hooks->onRender('gui.footer.scripts', function () use ($assetRegistry) {
    return $assetRegistry->renderScripts(cspNonce(), false);
}, 5);

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

// =========================================================================
// Register the 5 core tabs in the TabRegistry. wallet.html iterates the
// registry to build the desktop nav + mobile nav + tab panels, instead
// of hardcoding the 5 tabs in three places. Plugins register their own
// tabs in their boot(); they appear automatically alongside the core
// tabs sorted by `order`. See docs/PLUGIN_GUI_HOOKS.md.
//
// `include` paths are resolved by wallet.html with require_once at
// render time, so each partial sees Functions.php's scope ($user,
// $paymentRequests, etc.). `badge` is captured by-value here — counts
// are computed earlier in Functions.php and stay live for the request.
// =========================================================================
$tabRegistry = $serviceContainer->getTabRegistry();
$tabRegistry->register([
    'id'       => 'dashboard',
    'label'    => 'Dashboard',
    'mobileLabel' => 'Home',
    'icon'     => 'fas fa-home',
    'order'    => 10,
    'include'  => '/app/eiou/src/gui/layout/walletSubParts/dashboardTab.html',
]);
$tabRegistry->register([
    'id'         => 'send',
    'label'      => 'Payment',
    'icon'       => 'fas fa-paper-plane',
    'order'      => 20,
    'include'    => '/app/eiou/src/gui/layout/walletSubParts/sendTab.html',
    'badge'      => $pendingPaymentRequestCount ?? 0,
]);
$tabRegistry->register([
    'id'         => 'contacts',
    'label'      => 'Contacts',
    'icon'       => 'fas fa-address-book',
    'order'      => 30,
    'include'    => '/app/eiou/src/gui/layout/walletSubParts/contactsTab.html',
    'badge'      => $contactsNeedingChainActionCount ?? 0,
    'badgeTitle' => 'Contacts with chain gaps requiring action',
]);
$tabRegistry->register([
    'id'         => 'activity',
    'label'      => 'Activity',
    'icon'       => 'fas fa-history',
    'order'      => 40,
    'include'    => '/app/eiou/src/gui/layout/walletSubParts/activityTab.html',
    'badge'      => $dlqPendingCount ?? 0,
]);
$tabRegistry->register([
    'id'      => 'settings',
    'label'   => 'Settings',
    'icon'    => 'fas fa-cog',
    'order'   => 50,
    'include' => '/app/eiou/src/gui/layout/walletSubParts/settingsTab.html',
]);
