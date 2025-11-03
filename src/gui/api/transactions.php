<?php
/**
 * Transaction API Endpoints
 *
 * Copyright 2025
 * Provides AJAX endpoints for transaction operations
 * Returns JSON responses for async JavaScript calls
 */

// Set JSON content type
header('Content-Type: application/json');

// Ensure this is an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request type',
        'error' => 'This endpoint only accepts AJAX requests'
    ]);
    exit;
}

// Initialize the application
require_once '/etc/eiou/functions.php';

if (!file_exists("/etc/eiou/userconfig.json")) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'System not initialized',
        'error' => 'eIOU has not yet been initiated. Please run from terminal to initialize the system.'
    ]);
    exit;
}

// Require session management
require_once '/etc/eiou/src/gui/includes/session.php';

// Start secure session
$secureSession = new Session();

// Check authentication
if (!$secureSession->isAuthenticated()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required',
        'error' => 'You must be logged in to perform this action'
    ]);
    exit;
}

// Verify session timeout
if (!$secureSession->checkSessionTimeout()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Session expired',
        'error' => 'Your session has expired. Please refresh the page and log in again.'
    ]);
    exit;
}

// Verify CSRF token for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $secureSession->verifyCSRFToken();
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Security validation failed',
            'error' => 'Invalid security token. Please refresh the page and try again.'
        ]);
        exit;
    }
}

// Load services
require_once '/etc/eiou/src/gui/helpers/MessageHelper.php';
$app = Application::getInstance();
$app->loadServiceContainer();
$serviceContainer = $app->services;
$transactionService = $serviceContainer->getTransactionService();

// Import validation and security classes
require_once __DIR__ . '/../../utils/InputValidator.php';
require_once __DIR__ . '/../../utils/Security.php';

/**
 * Helper function to send JSON response
 */
function sendJsonResponse($success, $message, $data = null, $error = null) {
    $response = [
        'success' => $success,
        'message' => $message
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    if ($error !== null) {
        $response['error'] = $error;
    }

    echo json_encode($response);
    exit;
}

/**
 * Handle different API actions
 */
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'send':
        handleSendTransaction($transactionService);
        break;

    case 'getBalance':
        handleGetBalance($transactionService);
        break;

    case 'getHistory':
        handleGetHistory($transactionService);
        break;

    default:
        http_response_code(400);
        sendJsonResponse(false, 'Invalid action', null, 'Unknown action: ' . $action);
}

/**
 * Send a transaction
 */
function handleSendTransaction($transactionService) {
    // Sanitize input data
    $recipient = Security::sanitizeInput($_POST['recipient'] ?? '');
    $manualRecipient = Security::sanitizeInput($_POST['manual_recipient'] ?? '');
    $amount = $_POST['amount'] ?? '';
    $currency = $_POST['currency'] ?? '';

    // Use manual recipient if provided, otherwise use selected recipient
    $finalRecipient = !empty($manualRecipient) ? $manualRecipient : $recipient;

    // Validate required fields
    if (empty($finalRecipient) || empty($amount) || empty($currency)) {
        http_response_code(400);
        sendJsonResponse(false, 'All fields are required', null, 'Missing required fields');
    }

    // Validate amount using InputValidator
    $amountValidation = InputValidator::validateAmount($amount, $currency);
    if (!$amountValidation['valid']) {
        http_response_code(400);
        sendJsonResponse(false, 'Invalid amount', null, $amountValidation['error']);
    }

    // Validate currency
    $currencyValidation = InputValidator::validateCurrency($currency);
    if (!$currencyValidation['valid']) {
        http_response_code(400);
        sendJsonResponse(false, 'Invalid currency', null, $currencyValidation['error']);
    }

    // Validate recipient address or contact name
    $addressValidation = InputValidator::validateAddress($finalRecipient);
    $contactNameValidation = InputValidator::validateContactName($finalRecipient);

    if (!$addressValidation['valid'] && !$contactNameValidation['valid']) {
        http_response_code(400);
        sendJsonResponse(false, 'Invalid recipient', null, 'Must be a valid address or contact name');
    }

    // Use sanitized values
    $amount = $amountValidation['value'];
    $currency = $currencyValidation['value'];

    // Create argv array for sendEiou function
    $argv = ['eiou', 'send', $finalRecipient, $amount, $currency];

    // Capture output
    ob_start();
    try {
        if (method_exists($transactionService, 'sendEiou')) {
            $transactionService->sendEiou($argv);
            $output = ob_get_clean();

            // Determine success based on output
            if (strpos($output, 'ERROR') !== false || strpos($output, 'Failed') !== false) {
                http_response_code(400);
                sendJsonResponse(false, 'Transaction failed', null, trim($output));
            } else {
                sendJsonResponse(true, 'Transaction sent successfully', [
                    'recipient' => $finalRecipient,
                    'amount' => $amount,
                    'currency' => $currency,
                    'output' => trim($output)
                ]);
            }
        } else {
            ob_end_clean();
            http_response_code(500);
            sendJsonResponse(false, 'Transaction service not available', null, 'Service method not found');
        }
    } catch (Exception $e) {
        ob_end_clean();
        http_response_code(500);
        sendJsonResponse(false, 'Failed to send transaction', null, $e->getMessage());
    }
}

/**
 * Get current balance
 */
function handleGetBalance($transactionService) {
    try {
        $balance = $transactionService->getUserTotalBalance();

        sendJsonResponse(true, 'Balance retrieved successfully', [
            'balance' => $balance
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        sendJsonResponse(false, 'Failed to retrieve balance', null, $e->getMessage());
    }
}

/**
 * Get transaction history
 */
function handleGetHistory($transactionService) {
    $limit = intval($_GET['limit'] ?? 10);

    if ($limit < 1 || $limit > 100) {
        $limit = 10;
    }

    try {
        $transactions = $transactionService->getTransactionHistory($limit);

        sendJsonResponse(true, 'Transaction history retrieved successfully', [
            'transactions' => $transactions,
            'count' => count($transactions)
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        sendJsonResponse(false, 'Failed to retrieve transaction history', null, $e->getMessage());
    }
}
