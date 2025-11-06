<?php
/**
 * AJAX Handler
 *
 * Copyright 2025
 * Handles AJAX requests and returns JSON responses instead of redirects.
 * This provides a better user experience with loading indicators and
 * no page reloads.
 */

// Set JSON content type
header('Content-Type: application/json');

// Ensure this is an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'This endpoint only accepts AJAX requests'
    ]);
    exit;
}

// Start session and initialize services
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../controllers/ContactController.php';
require_once __DIR__ . '/../controllers/TransactionController.php';
require_once __DIR__ . '/../helpers/MessageHelper.php';

// Get the ServiceContainer instance
$serviceContainer = ServiceContainer::getInstance();

// Initialize controllers
$contactController = new ContactController(
    $session,
    $serviceContainer->getContactService()
);

$transactionController = new TransactionController(
    $session,
    $serviceContainer->getContactService(),
    $serviceContainer->getTransactionService()
);

/**
 * Send JSON response and exit
 *
 * @param bool $success Whether the operation succeeded
 * @param string $message Message to display to user
 * @param array $data Optional additional data
 */
function sendJsonResponse(bool $success, string $message, array $data = []): void {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

/**
 * Capture output from controller method and parse it
 *
 * @param callable $callback The controller method to call
 * @return array ['success' => bool, 'message' => string]
 */
function captureControllerOutput(callable $callback): array {
    ob_start();
    try {
        $callback();
        $output = ob_get_clean();

        // If we got here, the controller didn't exit with a redirect
        // Parse the output to extract success/failure
        if (strpos($output, 'ERROR') !== false ||
            strpos($output, 'Failed') !== false ||
            strpos($output, 'error') !== false) {
            return [
                'success' => false,
                'message' => trim($output) ?: 'Operation failed'
            ];
        } else {
            return [
                'success' => true,
                'message' => trim($output) ?: 'Operation completed successfully'
            ];
        }
    } catch (\Exception $e) {
        ob_end_clean();
        return [
            'success' => false,
            'message' => 'Internal server error: ' . $e->getMessage()
        ];
    }
}

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(false, 'Method not allowed');
}

// Get the action from POST data
$action = $_POST['action'] ?? '';

if (empty($action)) {
    sendJsonResponse(false, 'No action specified');
}

// Route to appropriate controller based on action
switch ($action) {
    // Transaction actions
    case 'sendEIOU':
        // Modify MessageHelper to not redirect when in AJAX mode
        // We'll capture the output instead
        $_SERVER['AJAX_MODE'] = true;

        ob_start();
        try {
            $transactionController->handleSendEIOU();
            $output = ob_get_clean();

            // Parse output to determine success
            if (strpos($output, 'ERROR') !== false ||
                strpos($output, 'Failed') !== false) {
                sendJsonResponse(false, trim($output));
            } else {
                sendJsonResponse(true, 'Transaction sent successfully');
            }
        } catch (\Exception $e) {
            ob_end_clean();
            sendJsonResponse(false, 'Error: ' . $e->getMessage());
        }
        break;

    // Contact actions
    case 'addContact':
        $_SERVER['AJAX_MODE'] = true;

        ob_start();
        try {
            $contactController->handleAddContact();
            $output = ob_get_clean();

            // Parse output to determine success
            $messageInfo = MessageHelper::parseContactOutput($output);
            sendJsonResponse(
                $messageInfo['type'] === 'success',
                $messageInfo['message']
            );
        } catch (\Exception $e) {
            ob_end_clean();
            sendJsonResponse(false, 'Error: ' . $e->getMessage());
        }
        break;

    case 'acceptContact':
        $_SERVER['AJAX_MODE'] = true;

        ob_start();
        try {
            $contactController->handleAcceptContact();
            $output = ob_get_clean();

            $messageInfo = MessageHelper::parseContactOutput($output);
            sendJsonResponse(
                $messageInfo['type'] === 'success',
                $messageInfo['message']
            );
        } catch (\Exception $e) {
            ob_end_clean();
            sendJsonResponse(false, 'Error: ' . $e->getMessage());
        }
        break;

    case 'deleteContact':
        $_SERVER['AJAX_MODE'] = true;

        ob_start();
        try {
            $contactController->handleDeleteContact();
            $output = ob_get_clean();

            $messageInfo = MessageHelper::parseContactOutput($output);
            sendJsonResponse(
                $messageInfo['type'] === 'success',
                $messageInfo['message']
            );
        } catch (\Exception $e) {
            ob_end_clean();
            sendJsonResponse(false, 'Error: ' . $e->getMessage());
        }
        break;

    case 'blockContact':
        $_SERVER['AJAX_MODE'] = true;

        ob_start();
        try {
            $contactController->handleBlockContact();
            $output = ob_get_clean();

            $messageInfo = MessageHelper::parseContactOutput($output);
            sendJsonResponse(
                $messageInfo['type'] === 'success',
                $messageInfo['message']
            );
        } catch (\Exception $e) {
            ob_end_clean();
            sendJsonResponse(false, 'Error: ' . $e->getMessage());
        }
        break;

    case 'unblockContact':
        $_SERVER['AJAX_MODE'] = true;

        ob_start();
        try {
            $contactController->handleUnblockContact();
            $output = ob_get_clean();

            $messageInfo = MessageHelper::parseContactOutput($output);
            sendJsonResponse(
                $messageInfo['type'] === 'success',
                $messageInfo['message']
            );
        } catch (\Exception $e) {
            ob_end_clean();
            sendJsonResponse(false, 'Error: ' . $e->getMessage());
        }
        break;

    case 'editContact':
        $_SERVER['AJAX_MODE'] = true;

        ob_start();
        try {
            $contactController->handleEditContact();
            $output = ob_get_clean();

            $messageInfo = MessageHelper::parseContactOutput($output);
            sendJsonResponse(
                $messageInfo['type'] === 'success',
                $messageInfo['message']
            );
        } catch (\Exception $e) {
            ob_end_clean();
            sendJsonResponse(false, 'Error: ' . $e->getMessage());
        }
        break;

    default:
        sendJsonResponse(false, 'Unknown action: ' . $action);
}
