<?php
/**
 * Contact API Endpoint
 *
 * Copyright 2025
 * Handles AJAX requests for contact operations.
 * Returns JSON responses for asynchronous frontend operations.
 */

// Set JSON response headers
header('Content-Type: application/json');

// Prevent caching
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

try {
    // Initialize session and services
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../../services/ServiceContainer.php';
    require_once __DIR__ . '/../../utils/InputValidator.php';
    require_once __DIR__ . '/../../utils/Security.php';
    require_once __DIR__ . '/../helpers/MessageHelper.php';

    $session = new Session();

    // Get service container
    $serviceContainer = ServiceContainer::getInstance();
    $contactService = $serviceContainer->getContactService();

    // Verify this is a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed',
            'message' => 'Only POST requests are accepted'
        ]);
        exit;
    }

    // Get JSON payload or form data
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid JSON',
                'message' => 'Request body contains invalid JSON'
            ]);
            exit;
        }
    } else {
        $input = $_POST;
    }

    // Get the action
    $action = $input['action'] ?? '';

    // Route to appropriate handler
    switch ($action) {
        case 'addContact':
            handleAddContact($session, $contactService, $input);
            break;

        case 'deleteContact':
            handleDeleteContact($session, $contactService, $input);
            break;

        case 'listContacts':
            handleListContacts($contactService);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'message' => 'Action not specified or not recognized'
            ]);
            exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred: ' . $e->getMessage()
    ]);
    exit;
}

/**
 * Handle add contact request
 *
 * @param Session $session Session manager
 * @param ContactService $contactService Contact service
 * @param array $input Request input data
 * @return void
 */
function handleAddContact(Session $session, $contactService, array $input): void
{
    // CSRF Protection: Verify token
    if (!isset($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'CSRF token missing',
            'message' => 'Security token is required'
        ]);
        exit;
    }

    if (!$session->validateCSRFToken($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'CSRF token invalid',
            'message' => 'Security token is invalid or expired'
        ]);
        exit;
    }

    // Sanitize input data
    $address = Security::sanitizeInput($input['address'] ?? '');
    $name = Security::sanitizeInput($input['name'] ?? '');
    $fee = $input['fee'] ?? '';
    $credit = $input['credit'] ?? '';
    $currency = $input['currency'] ?? '';

    // Validate required fields
    if (empty($address) || empty($name) || $fee === '' || $credit === '' || empty($currency)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing fields',
            'message' => 'All fields are required'
        ]);
        exit;
    }

    // Validate address
    $addressValidation = InputValidator::validateAddress($address);
    if (!$addressValidation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid address',
            'message' => $addressValidation['error']
        ]);
        exit;
    }

    // Validate contact name
    $nameValidation = InputValidator::validateContactName($name);
    if (!$nameValidation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid name',
            'message' => $nameValidation['error']
        ]);
        exit;
    }

    // Validate fee percentage
    $feeValidation = InputValidator::validateFeePercent($fee);
    if (!$feeValidation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid fee',
            'message' => $feeValidation['error']
        ]);
        exit;
    }

    // Validate credit limit
    $creditValidation = InputValidator::validateCreditLimit($credit);
    if (!$creditValidation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid credit',
            'message' => $creditValidation['error']
        ]);
        exit;
    }

    // Validate currency
    $currencyValidation = InputValidator::validateCurrency($currency);
    if (!$currencyValidation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid currency',
            'message' => $currencyValidation['error']
        ]);
        exit;
    }

    // Use validated values
    $address = $addressValidation['value'];
    $name = $nameValidation['value'];
    $fee = $feeValidation['value'];
    $credit = $creditValidation['value'];
    $currency = $currencyValidation['value'];

    // Attempt to add contact
    try {
        $result = $contactService->addContact($address, $name, $fee, $credit, $currency);

        if ($result['success']) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Contact added successfully',
                'data' => [
                    'name' => $name,
                    'address' => $address
                ]
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Add contact failed',
                'message' => $result['message'] ?? 'Failed to add contact'
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
            'message' => 'Failed to add contact: ' . $e->getMessage()
        ]);
    }
}

/**
 * Handle delete contact request
 *
 * @param Session $session Session manager
 * @param ContactService $contactService Contact service
 * @param array $input Request input data
 * @return void
 */
function handleDeleteContact(Session $session, $contactService, array $input): void
{
    // CSRF Protection
    if (!isset($input['csrf_token']) || !$session->validateCSRFToken($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'CSRF token invalid',
            'message' => 'Security token is invalid or expired'
        ]);
        exit;
    }

    // Get contact ID
    $contactId = $input['contact_id'] ?? '';

    if (empty($contactId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing contact ID',
            'message' => 'Contact ID is required'
        ]);
        exit;
    }

    // Attempt to delete contact
    try {
        $result = $contactService->deleteContact($contactId);

        if ($result['success']) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Contact deleted successfully'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Delete failed',
                'message' => $result['message'] ?? 'Failed to delete contact'
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
            'message' => 'Failed to delete contact: ' . $e->getMessage()
        ]);
    }
}

/**
 * Handle list contacts request
 *
 * @param ContactService $contactService Contact service
 * @return void
 */
function handleListContacts($contactService): void
{
    try {
        $contacts = $contactService->getAllContacts();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $contacts
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
            'message' => 'Failed to retrieve contacts: ' . $e->getMessage()
        ]);
    }
}
