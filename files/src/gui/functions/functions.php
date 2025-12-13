<?php
// Copyright 2025

// Handle retry status polling (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['retry_status'])) {
    require_once '/etc/eiou/src/utils/RetryStatusTracker.php';
    header('Content-Type: application/json');

    $requestId = $_GET['retry_status'];
    $status = RetryStatusTracker::getStatus($requestId);

    if ($status) {
        echo json_encode($status);
    } else {
        echo json_encode(['status' => 'not_found']);
    }
    exit;
}

// Route controllers if POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Initialize retry status tracker for AJAX contact operations
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($isAjax && in_array($action, ['addContact', 'acceptContact'])) {
        require_once '/etc/eiou/src/utils/RetryStatusTracker.php';
        $requestId = $_POST['retry_request_id'] ?? null;
        if ($requestId) {
            RetryStatusTracker::init($requestId);
        }
    }

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
$totalEarnings = $currencyUtility->convertCentsToDollars($p2pService->getUserTotalEarnings());
$transactions = $transactionService->getTransactionHistory(10);

// Contact data
$allContacts = $contactService->getAllContacts();
$pendingContacts = $contactService->getPendingContactRequests();
$pendingUserContacts = $transactionService->contactBalanceConversion($contactService->getUserPendingContactRequests());
$acceptedContacts = $transactionService->contactBalanceConversion($contactService->getAcceptedContacts());
$blockedContacts = $transactionService->contactBalanceConversion($contactService->getBlockedContacts());

// Address types (dynamic from database schema)
$addressTypes = $contactService->getAllAddressTypes();