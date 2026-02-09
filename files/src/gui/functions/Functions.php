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
    if (in_array($action, ['addContact', 'acceptContact', 'deleteContact', 'blockContact', 'unblockContact', 'editContact'])) {
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
}

// Handle GET requests for update checking
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_updates'])) {
    $transactionController->routeAction();
}

// Get message from session flash messages (set by controllers, read-once)
// Flash messages are cleared after reading so they don't re-appear on refresh
if (isset($_SESSION['message'])) {
    $messageForDisplay = htmlspecialchars($_SESSION['message'], ENT_QUOTES, 'UTF-8');
    $messageTypeForDisplay = htmlspecialchars($_SESSION['message_type'] ?? 'info', ENT_QUOTES, 'UTF-8');
    unset($_SESSION['message'], $_SESSION['message_type']);
} else {
    $messageForDisplay = '';
    $messageTypeForDisplay = '';
}

// Get user based data
$maxDisplayLines = $user->getMaxOutput();
$totalBalance = $transactionService->getUserTotalBalance();
$totalEarnings = $currencyUtility->convertCentsToDollars($p2pService->getUserTotalEarnings());
$transactions = $transactionService->getTransactionHistory($maxDisplayLines);
$inProgressTransactions = $transactionService->getInProgressTransactions(5);

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
if (!empty($pendingContacts) && $user->has('public')) {
    $txRepo = $serviceContainer->getTransactionRepository();
    $myPubkey = $user->getPublicKey();
    foreach ($pendingContacts as &$pc) {
        $history = $txRepo->getTransactionsBetweenPubkeys($myPubkey, $pc['pubkey'], 1);
        $pc['has_prior_history'] = !empty($history);
    }
    unset($pc);
}

$pendingUserContacts = $transactionService->contactBalanceConversion($contactService->getUserPendingContactRequests(), $maxDisplayLines);
$acceptedContacts = $transactionService->contactBalanceConversion($contactService->getAcceptedContacts(), $maxDisplayLines);
$blockedContacts = $transactionService->contactBalanceConversion($contactService->getBlockedContacts(), $maxDisplayLines);

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

// Initialize ContactDataBuilder helper
$contactDataBuilder = new ContactDataBuilder($addressTypes);