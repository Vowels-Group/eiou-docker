<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

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
    if (in_array($action, ['updateSettings', 'clearDebugLogs', 'sendDebugReport', 'getDebugReportJson'])) {
        $settingsController->routeAction();
    }
}

// Handle GET requests for update checking
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_updates'])) {
    $transactionController->routeAction();
}

// Get message from URL parameters (for redirects)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $messageForDisplay = $_GET['message'];
    $messageTypeForDisplay = $_GET['type'];
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

// Track completed transactions for notifications
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

// Contact data
$allContacts = $contactService->getAllContacts();
$pendingContacts = $contactService->getPendingContactRequests();
$pendingUserContacts = $transactionService->contactBalanceConversion($contactService->getUserPendingContactRequests(), $maxDisplayLines);
$acceptedContacts = $transactionService->contactBalanceConversion($contactService->getAcceptedContacts(), $maxDisplayLines);
$blockedContacts = $transactionService->contactBalanceConversion($contactService->getBlockedContacts(), $maxDisplayLines);

// Address types (dynamic from database schema)
$addressTypes = $contactService->getAllAddressTypes();

// Initialize ContactDataBuilder helper
require_once __DIR__ . '/../helpers/ContactDataBuilder.php';
$contactDataBuilder = new ContactDataBuilder($addressTypes);