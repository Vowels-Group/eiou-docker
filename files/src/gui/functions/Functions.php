<?php
# Copyright 2025-2026 Vowels Group, LLC

use Eiou\Gui\Helpers\ContactDataBuilder;

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

// Route controllers if POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Contact actions
    if (in_array($action, ['addContact', 'acceptContact', 'acceptCurrency', 'deleteContact', 'blockContact', 'unblockContact', 'editContact'])) {
        $contactController->routeAction();
    }

    // Transaction actions
    if (in_array($action, ['sendEIOU'])) {
        $transactionController->routeAction();
    }

    // Settings actions
    if (in_array($action, ['updateSettings', 'clearDebugLogs', 'sendDebugReport'])) {
        $settingsController->routeAction();
    }

    // AJAX-only settings actions (returns JSON, exits immediately)
    if ($action === 'getDebugReportJson') {
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

    // AJAX-only chain drop actions (returns JSON, exits immediately)
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
    if (in_array($action, ['approveP2pTransaction', 'rejectP2pTransaction', 'getP2pCandidates'])) {
        try {
            $transactionController->routeAction();
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // AJAX-only DLQ actions (returns JSON, exits immediately)
    if (in_array($action, ['dlqRetry', 'dlqAbandon', 'dlqRetryAll', 'dlqAbandonAll'])) {
        try {
            $dlqController->routeAction();
        } catch (\Throwable $e) {
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

// Get message from session flash messages (set by controllers, read-once)
// Flash messages are cleared after reading so they don't re-appear on refresh
if (isset($_SESSION['message'])) {
    $messageForDisplay = $_SESSION['message'];
    $messageTypeForDisplay = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message'], $_SESSION['message_type']);
} else {
    $messageForDisplay = '';
    $messageTypeForDisplay = '';
}

// Get user based data
$maxDisplayLines = $user->getMaxOutput();
$totalBalance = $transactionService->getUserTotalBalance();
$totalEarnings = $currencyUtility->convertMinorToMajor($p2pService->getUserTotalEarnings());

// Per-currency balance data for future-proof dashboard display
$totalBalanceByCurrency = [];
$balancesRaw = $serviceContainer->getBalanceRepository()->getUserBalance();
if (!empty($balancesRaw)) {
    foreach ($balancesRaw as $bal) {
        $totalBalanceByCurrency[] = [
            'currency' => $bal['currency'],
            'total' => number_format($currencyUtility->convertMinorToMajor((int)($bal['total_balance'] ?? 0)), 2)
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
            'total' => number_format($currencyUtility->convertMinorToMajor((int)($earn['total_amount'] ?? 0)), 2)
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
    $creditCurrencies = $serviceContainer->getContactCreditRepository()->getTotalAvailableCreditByCurrency();
    foreach ($creditCurrencies as $row) {
        $knownCurrencies[$row['currency']] = true;
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

$transactions = $transactionService->getTransactionHistory($maxDisplayLines);
$inProgressTransactions = $transactionService->getInProgressTransactions(5);

// Check Tor/SOCKS5 GUI status (written by TransportUtilityService and startup.sh watchdog)
$torGuiStatus = null;
$torGuiStatusFile = '/tmp/tor-gui-status';
if (file_exists($torGuiStatusFile)) {
    $torGuiRaw = @file_get_contents($torGuiStatusFile);
    if ($torGuiRaw !== false) {
        $torGuiData = json_decode($torGuiRaw, true);
        if (is_array($torGuiData) && isset($torGuiData['status'], $torGuiData['timestamp'])) {
            $torGuiAge = time() - (int)$torGuiData['timestamp'];
            if ($torGuiData['status'] === 'recovered' && $torGuiAge > 300) {
                // Recovery older than 5 minutes — clean up
                @unlink($torGuiStatusFile);
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
$prevInProgressTxids = $_SESSION['in_progress_txids'] ?? [];

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
$_SESSION['in_progress_txids'] = $currentInProgressTxids;

// Track received transactions for notifications
// Received transactions bypass in-progress tracking (they arrive completed),
// so we detect them by comparing known txids across page loads
$currentTxids = array_column($transactions ?? [], 'txid');
$prevKnownTxids = $_SESSION['known_txids'] ?? null;
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

$_SESSION['known_txids'] = $currentTxids;

// Contact data
$allContacts = $contactService->getAllContacts();
$pendingContacts = $contactService->getPendingContactRequests();

// Check if pending contacts have prior transaction history (wallet restore scenario)
// Contacts created via auto-restore from ping will have synced transactions
// Exclude contact transactions (tx_type='contact') since those are created as part of
// the contact request itself — only real transactions indicate a prior relationship
if (!empty($pendingContacts) && $user->has('public')) {
    $txRepo = $serviceContainer->getTransactionRepository();
    $myPubkey = $user->getPublicKey();
    foreach ($pendingContacts as &$pc) {
        $history = $txRepo->getNonContactTransactionsBetweenPubkeys($myPubkey, $pc['pubkey'], 1);
        $pc['has_prior_history'] = !empty($history);
    }
    unset($pc);
}

// Enrich pending contacts with per-currency data from contact_currencies (direction-aware)
if (!empty($pendingContacts)) {
    try {
        $pendingCurrencyRepo = $serviceContainer->getContactCurrencyRepository();
        foreach ($pendingContacts as &$pc) {
            $hash = $pc['pubkey_hash'] ?? '';
            if ($hash) {
                // Incoming: currencies THEY requested from us (we need to accept/reject)
                $pc['pending_currencies'] = $pendingCurrencyRepo->getPendingCurrencies($hash, 'incoming');
                // Outgoing: currencies WE requested from them (waiting for their acceptance)
                $pc['outgoing_currencies'] = $pendingCurrencyRepo->getPendingCurrencies($hash, 'outgoing');
            }
        }
        unset($pc);
    } catch (Exception $e) {
        // Non-critical — pending contacts will show without currency data
    }
}

$pendingUserContacts = $transactionService->contactBalanceConversion($contactService->getUserPendingContactRequests(), $maxDisplayLines);
$acceptedContacts = $transactionService->contactBalanceConversion($contactService->getAcceptedContacts(), $maxDisplayLines);
$blockedContacts = $transactionService->contactBalanceConversion($contactService->getBlockedContacts(), $maxDisplayLines);

// Enrich pending user contacts (our outgoing requests) with direction-aware currency data
if (!empty($pendingUserContacts)) {
    try {
        $currencyRepo = $serviceContainer->getContactCurrencyRepository();
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
        $currencyRepo = $serviceContainer->getContactCurrencyRepository();
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

// Count contacts with pending incoming currency requests (for notifications)
$pendingCurrencyContacts = [];
foreach (array_merge($pendingUserContacts, $acceptedContacts) as $c) {
    if (!empty($c['pending_currencies'])) {
        $pendingCurrencyContacts[] = $c;
    }
}

// Address types (dynamic from database schema)
$addressTypes = $contactService->getAllAddressTypes();

// Chain drop proposals - fetch both directions, index by contact hash
$chainDropProposalsByContact = [];
try {
    $chainDropService = $serviceContainer->getChainDropService();
    $chainDropProposalRepo = $serviceContainer->getChainDropProposalRepository();

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

// Merge chain drop proposals into contact arrays by pubkey_hash
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
    $tcRepo = $serviceContainer->getTransactionChainRepository();
} catch (Exception $e) {
    // Repository not available, skip gap details
}
if ($tcRepo && $user->has('public')) {
    $myPubkey = $user->getPublicKey();
    foreach ($acceptedContacts as &$contact) {
        // Compute gap details when the chain is known-invalid OR when there is an
        // active chain-drop proposal (covers the window between proposal creation and
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
    $dlqRepository = $serviceContainer->getDeadLetterQueueRepository();
    $currentDlqItems = $dlqRepository->getPendingItems(50);

    // Get previously known DLQ item IDs from session
    $prevDlqIds = $_SESSION['known_dlq_ids'] ?? [];

    // Find items that are new (not previously seen)
    $currentDlqIds = array_column($currentDlqItems, 'id');
    foreach ($currentDlqItems as $item) {
        if (!in_array($item['id'], $prevDlqIds)) {
            $newlyAddedToDlq[] = $item;
        }
    }

    // Update session with current DLQ IDs
    $_SESSION['known_dlq_ids'] = $currentDlqIds;
} catch (Exception $e) {
    // Silently fail - DLQ notification is non-critical
    $newlyAddedToDlq = [];
}

// Dead Letter Queue - load items for the DLQ management section
$dlqItems = [];
$dlqStats = [];
$dlqPendingCount = 0;
try {
    $dlqRepo = $serviceContainer->getDeadLetterQueueRepository();
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
    $contactCreditRepo = $serviceContainer->getContactCreditRepository();
    // Get per-contact credits for merging into contact cards
    foreach (array_merge($acceptedContacts, $pendingUserContacts, $blockedContacts) as $c) {
        $hash = $c['pubkey_hash'] ?? '';
        if ($hash && !isset($availableCreditByContact[$hash])) {
            $creditData = $contactCreditRepo->getAvailableCredit($hash);
            if ($creditData !== null) {
                $contactCurrency = $c['currency'] ?? \Eiou\Core\Constants::TRANSACTION_DEFAULT_CURRENCY;
                $availableCreditByContact[$hash] = $creditData['available_credit'] / \Eiou\Core\Constants::CONVERSION_FACTORS[$contactCurrency];
            }
        }
    }
    // Get totals per currency for dashboard display
    $creditTotals = $contactCreditRepo->getTotalAvailableCreditByCurrency();
    foreach ($creditTotals as $row) {
        $totalAvailableCreditByCurrency[] = [
            'currency' => $row['currency'],
            'total' => number_format($row['total_available_credit'] / \Eiou\Core\Constants::CONVERSION_FACTORS[$row['currency']], 2)
        ];
    }
} catch (Exception $e) {
    $availableCreditByContact = [];
    $totalAvailableCreditByCurrency = [];
}

// Fetch per-contact currency configs for multi-currency support
$contactCurrenciesByHash = [];
try {
    $contactCurrencyRepo = $serviceContainer->getContactCurrencyRepository();
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
                $creditMap[$cur] = $cr['available_credit'] / \Eiou\Core\Constants::CONVERSION_FACTORS[$cur];
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
        // Their available credit with me: credit_limit - balance
        // Uses the same balance data as the displayed contact balance (from transactions table)
        // Formula: credit_limit - balance, where balance = received - sent
        // When balance is negative (I owe them), their credit increases
        // When balance is positive (they owe me), their credit decreases
        $contact['their_available_credit'] = null;
        $balanceValue = floatval($contact['balance'] ?? 0);
        $creditLimitValue = floatval($contact['credit_limit'] ?? 0);
        if ($creditLimitValue > 0 || $balanceValue != 0) {
            $contact['their_available_credit'] = round($creditLimitValue - $balanceValue, 2);
        }

        // Build multi-currency data
        $currencyConfigs = $contactCurrenciesByHash[$hash] ?? [];
        $allCredits = $availableCreditAllByHash[$hash] ?? [];
        $currencies = [];
        $pendingCurrencies = [];
        foreach ($currencyConfigs as $cc) {
            $cur = $cc['currency'];
            $ccStatus = $cc['status'] ?? 'accepted';
            $entry = [
                'currency' => $cur,
                'fee' => ($cc['fee_percent'] ?? 0) / \Eiou\Core\Constants::FEE_CONVERSION_FACTOR,
                'credit_limit' => ($cc['credit_limit'] ?? 0) / \Eiou\Core\Constants::CONVERSION_FACTORS[$cur],
                'my_available_credit' => $allCredits[$cur] ?? null,
                'status' => $ccStatus,
            ];
            if ($ccStatus === 'pending') {
                $pendingCurrencies[] = $entry;
            }
            $currencies[] = $entry;
        }
        $contact['currencies'] = $currencies;
        $contact['pending_currencies'] = $pendingCurrencies;
    }
    unset($contact);
}
unset($contacts);

// Initialize ContactDataBuilder helper
$contactDataBuilder = new ContactDataBuilder($addressTypes);