<?php
/**
 * Transaction Controller
 *
 * Copyright 2025
 * Handles HTTP POST requests for transaction-related actions using MVC pattern.
 */

namespace Eiou\Gui\Controllers;

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../models/Transaction.php';
require_once __DIR__ . '/../helpers/MessageHelper.php';
require_once __DIR__ . '/../../utils/InputValidator.php';
require_once __DIR__ . '/../../utils/Security.php';

use Session;
use Eiou\Gui\Models\Transaction as TransactionModel;
use MessageHelper;
use InputValidator;
use Security;

class TransactionController
{
    /**
     * @var Session Session manager
     */
    private Session $session;

    /**
     * @var TransactionModel Transaction model
     */
    private TransactionModel $transactionModel;

    /**
     * @var mixed ContactService Contact service from ServiceContainer (legacy)
     */
    private $contactService;

    /**
     * @var mixed TransactionService Transaction service from ServiceContainer (legacy)
     */
    private $transactionService;

    /**
     * Constructor
     *
     * @param Session $session
     * @param mixed $contactService
     * @param mixed $transactionService
     */
    public function __construct(
        Session $session,
        $contactService,
        $transactionService
    )
    {
        $this->session = $session;
        $this->contactService = $contactService;
        $this->transactionService = $transactionService;

        // Initialize Transaction Model
        require_once __DIR__ . '/../../services/ServiceContainer.php';
        $serviceContainer = \ServiceContainer::getInstance();
        $this->transactionModel = new TransactionModel($serviceContainer);
    }

    /**
     * Handle send eIOU form submission
     *
     * This method uses InputValidator and Security classes to validate and sanitize
     * all user input before processing the transaction using the Transaction Model.
     *
     * @return void
     */
    public function handleSendEIOU(): void
    {
        // Sanitize input data
        $recipient = Security::sanitizeInput($_POST['recipient'] ?? '');
        $manualRecipient = Security::sanitizeInput($_POST['manual_recipient'] ?? '');
        $amount = $_POST['amount'] ?? '';
        $currency = $_POST['currency'] ?? '';

        // Use manual recipient if provided, otherwise use selected recipient
        $finalRecipient = !empty($manualRecipient) ? $manualRecipient : $recipient;

        // Validate using Transaction Model
        $validation = $this->transactionModel->validate([
            'recipient' => $finalRecipient,
            'amount' => $amount,
            'currency' => $currency
        ]);

        if (!$validation['valid']) {
            $errors = $validation['errors'];
            $message = 'Validation failed: ' . implode(', ', $errors);
            MessageHelper::redirectMessage($message, 'error');
            return;
        }

        // Send transaction using Model
        $result = $this->transactionModel->send(
            $finalRecipient,
            (float)$amount,
            $currency
        );

        if ($result['success']) {
            MessageHelper::redirectMessage($result['message'], 'success');
        } else {
            MessageHelper::redirectMessage($result['message'], 'error');
        }
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
