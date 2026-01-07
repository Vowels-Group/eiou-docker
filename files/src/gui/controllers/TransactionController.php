<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

/**
 * Transaction Controller
 *
 * Handles HTTP POST requests for transaction-related actions.
 */



class TransactionController
{
    /**
     * @var Session Session manager
     */
    private Session $session;

    /**
     * @var ContactService Contact service from ServiceContainer
     */
    private $contactService;

    /**
     * @var TransactionService Transaction service from ServiceContainer
     */
    private $transactionService;

    /**
     * Constructor
     *
     * @param Session $session
     * @param ContactService $contactService
     * @param TransactionService $transactionService
     */
    public function __construct(
        Session $session, 
        ContactService $contactService,
        TransactionService $transactionService
        )
    {
        $this->session = $session;
        $this->contactService = $contactService;
        $this->transactionService = $transactionService;
    }

    /**
     * Handle send eIOU form submission
     *
     * This method uses InputValidator and Security classes to validate and sanitize
     * all user input before processing the transaction.
     * Uses JSON output mode for proper message handling.
     *
     * @return void
     */
    public function handleSendEIOU(): void
    {
        // CSRF Protection: Verify token before processing
        $this->session->verifyCSRFToken();

        // Import validation and security classes
        require_once __DIR__ . '/../../utils/InputValidator.php';
        require_once __DIR__ . '/../../utils/Security.php';
        require_once __DIR__ . '/../../cli/CliOutputManager.php';

        // Sanitize input data
        $recipient = Security::sanitizeInput($_POST['recipient'] ?? '');
        $manualRecipient = Security::sanitizeInput($_POST['manual_recipient'] ?? '');
        $addressType = Security::sanitizeInput($_POST['address_type'] ?? '');
        $amount = $_POST['amount'] ?? '';
        $currency = $_POST['currency'] ?? '';

        // Determine final recipient based on input method
        if (!empty($manualRecipient)) {
            // Manual address entry - use as-is
            $finalRecipient = $manualRecipient;
        } elseif (!empty($recipient) && !empty($addressType)) {
            // Contact selected with address type - lookup and use specific address
            $contactInfo = $this->contactService->lookupByName($recipient);
            if ($contactInfo && isset($contactInfo[$addressType]) && !empty($contactInfo[$addressType])) {
                $finalRecipient = $contactInfo[$addressType];
            } else {
                MessageHelper::redirectMessage('Selected address type not available for contact', 'error');
                return;
            }
        } else {
            // Fallback to recipient name (will be resolved by backend)
            $finalRecipient = $recipient;
        }

        // Validate required fields
        if (empty($finalRecipient) || empty($amount) || empty($currency)) {
            $message = 'All fields are required';
            $messageType = 'error';
        } else {
            // Validate amount using InputValidator
            $amountValidation = InputValidator::validateAmount($amount, $currency);
            if (!$amountValidation['valid']) {
                MessageHelper::redirectMessage('Invalid amount: ' . $amountValidation['error'], 'error');
                return;
            }

            // Validate currency
            $currencyValidation = InputValidator::validateCurrency($currency);
            if (!$currencyValidation['valid']) {
                MessageHelper::redirectMessage('Invalid currency: ' . $currencyValidation['error'], 'error');
                return;
            }

            // Validate recipient address or contact name
            $addressValidation = InputValidator::validateAddress($finalRecipient);
            $contactNameValidation = InputValidator::validateContactName($finalRecipient);

            if (!$addressValidation['valid'] && !$contactNameValidation['valid']) {
                MessageHelper::redirectMessage('Invalid recipient: must be a valid address or contact name', 'error');
                return;
            }

            // Use sanitized values
            $amount = $amountValidation['value'];
            $currency = $currencyValidation['value'];

            // Get optional description (sanitized)
            $description = Security::sanitizeInput($_POST['description'] ?? '');
            $description = !empty($description) ? $description : null;

            // Create argv array with --json flag for structured output
            $argv = ['eiou', 'send', $finalRecipient, $amount, $currency, $description, '--json'];

            // Create CliOutputManager with JSON mode enabled
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture output
            ob_start();
            try {
                if (method_exists($this->transactionService, 'sendEiou')) {
                    $this->transactionService->sendEiou($argv, $outputManager);
                    $output = ob_get_clean();

                    // Parse JSON output using MessageHelper
                    $messageInfo = MessageHelper::parseCliJsonOutput($output);
                    $message = $messageInfo['message'];
                    $messageType = $messageInfo['type'];
                } else {
                    ob_end_clean();
                    $message = 'Transaction service not available';
                    $messageType = 'error';
                }
            } catch (\Throwable $e) {
                // Ensure output buffer is cleaned up
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                // Use SecureLogger for exception logging
                SecureLogger::logException($e, [
                    'controller' => 'TransactionController',
                    'action' => __FUNCTION__
                ]);
                $message = Constants::APP_ENV !== 'production'
                    ? 'Internal server error: ' . $e->getMessage()
                    : 'Internal server error';
                $messageType = 'error';
            }
        }
        MessageHelper::redirectMessage($message, $messageType);
    }

    /**
     * Handle update checking requests (for Tor Browser polling)
     *
     * @return void
     */
    public function handleCheckUpdates(): void
    {
        if (!isset($_GET['check_updates']) || $_GET['check_updates'] !== '1') {
            return;
        }

        $lastCheckTime = $_GET['last_check'] ?? 0;

        // We need to check for new transactions and contact requests
        if (function_exists('checkForNewTransactions') && function_exists('checkForNewContactRequests')) {
            $newTransactions = $this->transactionService->checkForNewTransactions($lastCheckTime);
            $newContactRequests = $this->contactService->checkForNewContactRequests($lastCheckTime);

            if ($newTransactions || $newContactRequests) {
                echo "new_transaction:" . ($newTransactions ? '1' : '0') . "\n";
                echo "new_contact_request:" . ($newContactRequests ? '1' : '0') . "\n";
                echo "timestamp:" . time() . "\n";
            } else {
                echo "no_updates\n";
            }
        } else {
            echo "no_updates\n";
        }
        exit;
    }

    /**
     * Route transaction actions based on POST data
     *
     * @return void
     */
    public function routeAction(): void
    {
        // Handle GET requests for update checking
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_updates'])) {
            $this->handleCheckUpdates();
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'sendEIOU':
                $this->handleSendEIOU();
                break;
        }
    }
}
