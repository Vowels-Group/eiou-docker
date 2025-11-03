<?php
/**
 * Contact API Endpoints
 *
 * Copyright 2025
 * Provides AJAX endpoints for contact management operations
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
$contactService = $serviceContainer->getContactService();

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
    case 'add':
        handleAddContact($contactService);
        break;

    case 'edit':
        handleEditContact($contactService);
        break;

    case 'delete':
        handleDeleteContact($contactService);
        break;

    case 'block':
        handleBlockContact($contactService);
        break;

    case 'unblock':
        handleUnblockContact($contactService);
        break;

    case 'accept':
        handleAcceptContact($contactService);
        break;

    case 'list':
        handleListContacts($contactService);
        break;

    default:
        http_response_code(400);
        sendJsonResponse(false, 'Invalid action', null, 'Unknown action: ' . $action);
}

/**
 * Add a new contact
 */
function handleAddContact($contactService) {
    // Sanitize input data
    $address = Security::sanitizeInput($_POST['address'] ?? '');
    $name = Security::sanitizeInput($_POST['name'] ?? '');
    $fee = $_POST['fee'] ?? '';
    $credit = $_POST['credit'] ?? '';
    $currency = $_POST['currency'] ?? '';

    // Validate required fields
    if (empty($address) || empty($name) || $fee === '' || $credit === '' || empty($currency)) {
        http_response_code(400);
        sendJsonResponse(false, 'All fields are required', null, 'Missing required fields');
    }

    // Validate address
    $addressValidation = InputValidator::validateAddress($address);
    if (!$addressValidation['valid']) {
        http_response_code(400);
        sendJsonResponse(false, 'Invalid address', null, $addressValidation['error']);
    }

    // Validate contact name
    $nameValidation = InputValidator::validateContactName($name);
    if (!$nameValidation['valid']) {
        http_response_code(400);
        sendJsonResponse(false, 'Invalid contact name', null, $nameValidation['error']);
    }

    // Validate fee percentage
    $feeValidation = InputValidator::validateFeePercent($fee);
    if (!$feeValidation['valid']) {
        http_response_code(400);
        sendJsonResponse(false, 'Invalid fee', null, $feeValidation['error']);
    }

    // Validate credit limit
    $creditValidation = InputValidator::validateCreditLimit($credit);
    if (!$creditValidation['valid']) {
        http_response_code(400);
        sendJsonResponse(false, 'Invalid credit limit', null, $creditValidation['error']);
    }

    // Validate currency
    $currencyValidation = InputValidator::validateCurrency($currency);
    if (!$currencyValidation['valid']) {
        http_response_code(400);
        sendJsonResponse(false, 'Invalid currency', null, $currencyValidation['error']);
    }

    // Use sanitized and validated values
    $address = $addressValidation['value'];
    $name = $nameValidation['value'];
    $fee = $feeValidation['value'];
    $credit = $creditValidation['value'];
    $currency = $currencyValidation['value'];

    // Create argv array for addContact function
    $argv = ['eiou', 'add', $address, $name, $fee, $credit, $currency];

    // Capture output
    ob_start();
    try {
        $contactService->addContact($argv);
        $output = ob_get_clean();

        // Parse the output to determine success
        $messageInfo = MessageHelper::parseContactOutput($output);

        if ($messageInfo['type'] === 'success') {
            sendJsonResponse(true, $messageInfo['message'], [
                'contact' => [
                    'address' => $address,
                    'name' => $name,
                    'fee' => $fee,
                    'credit' => $credit,
                    'currency' => $currency
                ]
            ]);
        } else {
            http_response_code(400);
            sendJsonResponse(false, $messageInfo['message'], null, $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        http_response_code(500);
        sendJsonResponse(false, 'Failed to add contact', null, $e->getMessage());
    }
}

/**
 * Edit an existing contact
 */
function handleEditContact($contactService) {
    $contactAddress = $_POST['contact_address'] ?? '';
    $contactName = $_POST['contact_name'] ?? '';
    $contactFee = $_POST['contact_fee'] ?? '';
    $contactCredit = $_POST['contact_credit'] ?? '';
    $contactCurrency = $_POST['contact_currency'] ?? '';

    if (empty($contactAddress) || empty($contactName) || empty($contactFee) || empty($contactCredit) || empty($contactCurrency)) {
        http_response_code(400);
        sendJsonResponse(false, 'All fields are required', null, 'Missing required fields');
    }

    // Create argv array for updateContact function
    $argv = ['eiou', 'update', $contactAddress, 'all', $contactName, $contactFee, $contactCredit];

    // Capture output
    ob_start();
    try {
        $contactService->updateContact($argv);
        $output = ob_get_clean();

        // Parse the output to determine success
        $messageInfo = MessageHelper::parseContactOutput($output);

        if ($messageInfo['type'] === 'success') {
            sendJsonResponse(true, $messageInfo['message'], [
                'contact' => [
                    'address' => $contactAddress,
                    'name' => $contactName,
                    'fee' => $contactFee,
                    'credit' => $contactCredit,
                    'currency' => $contactCurrency
                ]
            ]);
        } else {
            http_response_code(400);
            sendJsonResponse(false, $messageInfo['message'], null, $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        http_response_code(500);
        sendJsonResponse(false, 'Failed to edit contact', null, $e->getMessage());
    }
}

/**
 * Delete a contact
 */
function handleDeleteContact($contactService) {
    $contactAddress = $_POST['contact_address'] ?? '';

    if (empty($contactAddress)) {
        http_response_code(400);
        sendJsonResponse(false, 'Contact address is required', null, 'Missing contact address');
    }

    // Capture output
    ob_start();
    try {
        $contactService->deleteContact($contactAddress);
        $output = ob_get_clean();

        // Parse the output to determine success
        $messageInfo = MessageHelper::parseContactOutput($output);

        if ($messageInfo['type'] === 'success') {
            sendJsonResponse(true, $messageInfo['message'], [
                'address' => $contactAddress
            ]);
        } else {
            http_response_code(400);
            sendJsonResponse(false, $messageInfo['message'], null, $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        http_response_code(500);
        sendJsonResponse(false, 'Failed to delete contact', null, $e->getMessage());
    }
}

/**
 * Block a contact
 */
function handleBlockContact($contactService) {
    $contactAddress = $_POST['contact_address'] ?? '';

    if (empty($contactAddress)) {
        http_response_code(400);
        sendJsonResponse(false, 'Contact address is required', null, 'Missing contact address');
    }

    // Capture output
    ob_start();
    try {
        $contactService->blockContact($contactAddress);
        $output = ob_get_clean();

        // Parse the output to determine success
        $messageInfo = MessageHelper::parseContactOutput($output);

        if ($messageInfo['type'] === 'success') {
            sendJsonResponse(true, $messageInfo['message'], [
                'address' => $contactAddress
            ]);
        } else {
            http_response_code(400);
            sendJsonResponse(false, $messageInfo['message'], null, $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        http_response_code(500);
        sendJsonResponse(false, 'Failed to block contact', null, $e->getMessage());
    }
}

/**
 * Unblock a contact
 */
function handleUnblockContact($contactService) {
    $contactAddress = $_POST['contact_address'] ?? '';

    if (empty($contactAddress)) {
        http_response_code(400);
        sendJsonResponse(false, 'Contact address is required', null, 'Missing contact address');
    }

    // Capture output
    ob_start();
    try {
        $contactService->unblockContact($contactAddress);
        $output = ob_get_clean();

        // Parse the output to determine success
        $messageInfo = MessageHelper::parseContactOutput($output);

        if ($messageInfo['type'] === 'success') {
            sendJsonResponse(true, $messageInfo['message'], [
                'address' => $contactAddress
            ]);
        } else {
            http_response_code(400);
            sendJsonResponse(false, $messageInfo['message'], null, $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        http_response_code(500);
        sendJsonResponse(false, 'Failed to unblock contact', null, $e->getMessage());
    }
}

/**
 * Accept a contact request
 */
function handleAcceptContact($contactService) {
    $contactAddress = $_POST['contact_address'] ?? '';
    $contactName = $_POST['contact_name'] ?? '';
    $contactFee = $_POST['contact_fee'] ?? '';
    $contactCredit = $_POST['contact_credit'] ?? '';
    $contactCurrency = $_POST['contact_currency'] ?? '';

    if (empty($contactAddress) || empty($contactName) || empty($contactFee) || empty($contactCredit) || empty($contactCurrency)) {
        http_response_code(400);
        sendJsonResponse(false, 'All fields are required', null, 'Missing required fields');
    }

    // Create argv array for addContact function
    $argv = ['eiou', 'add', $contactAddress, $contactName, $contactFee, $contactCredit, $contactCurrency];

    // Capture output
    ob_start();
    try {
        $contactService->addContact($argv);
        $output = ob_get_clean();

        // Parse the output to determine success
        $messageInfo = MessageHelper::parseContactOutput($output);

        if ($messageInfo['type'] === 'success') {
            sendJsonResponse(true, $messageInfo['message'], [
                'contact' => [
                    'address' => $contactAddress,
                    'name' => $contactName,
                    'fee' => $contactFee,
                    'credit' => $contactCredit,
                    'currency' => $contactCurrency
                ]
            ]);
        } else {
            http_response_code(400);
            sendJsonResponse(false, $messageInfo['message'], null, $output);
        }
    } catch (Exception $e) {
        ob_end_clean();
        http_response_code(500);
        sendJsonResponse(false, 'Failed to accept contact', null, $e->getMessage());
    }
}

/**
 * List all contacts (for refresh)
 */
function handleListContacts($contactService) {
    try {
        $allContacts = $contactService->getAllContacts();
        $pendingContacts = $contactService->getPendingContactRequests();
        $acceptedContacts = $contactService->getAcceptedContacts();
        $blockedContacts = $contactService->getBlockedContacts();

        sendJsonResponse(true, 'Contacts retrieved successfully', [
            'all' => $allContacts,
            'pending' => $pendingContacts,
            'accepted' => $acceptedContacts,
            'blocked' => $blockedContacts
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        sendJsonResponse(false, 'Failed to retrieve contacts', null, $e->getMessage());
    }
}
