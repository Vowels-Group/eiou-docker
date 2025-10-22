<?php
/**
 * Transaction Controller
 *
 * Copyright 2025
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
     * @return void
     */
    public function handleSendEIOU(): void
    {
        $recipient = $_POST['recipient'] ?? '';
        $manualRecipient = $_POST['manual_recipient'] ?? '';
        $amount = $_POST['amount'] ?? '';
        $currency = $_POST['currency'] ?? '';

        // Use manual recipient if provided, otherwise use selected recipient
        $finalRecipient = !empty($manualRecipient) ? $manualRecipient : $recipient;

        if (empty($finalRecipient) || empty($amount) || empty($currency)) {
            $message = 'All fields are required';
            $messageType = 'error';
        } else {
            // Create argv array for sendEiou function
            $argv = ['eiou', 'send', $finalRecipient, $amount, $currency];

            // Capture output
            ob_start();
            try {
                if (method_exists($this->transactionService,'sendEiou')) {
                    $this->transactionService->sendEiou($argv);
                    $output = ob_get_clean();
                    $message = trim($output);
                    if (strpos($output, 'ERROR') !== false || strpos($output, 'Failed') !== false) {
                        $messageType = 'error';
                    } else {
                        $messageType = 'success';
                    }
                } else {
                    ob_end_clean();
                    $message = 'Transaction service not available';
                    $messageType = 'error';
                }
            } catch (\Exception $e) {
                ob_end_clean();
                $message = 'Internal server error: ' . $e->getMessage();
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
