<?php
// Copyright 2025

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
$totalBalance = $transactionService->getUserTotalBalance();
$transactions = $transactionService->getTransactionHistory(10);

// Contact data
$allContacts = $contactService->getAllContacts();
$pendingContacts = $contactService->getPendingContactRequests();
$pendingUserContacts = $transactionService->contactBalanceConversion($contactService->getUserPendingContactRequests());
$acceptedContacts = $transactionService->contactBalanceConversion($contactService->getAcceptedContacts());
$blockedContacts = $transactionService->contactBalanceConversion($contactService->getBlockedContacts());