<?php
# Copyright 2025

/**
 * API Controller
 *
 * Handles all API endpoint logic using existing services.
 *
 * @package API
 */
class ApiController {
    /**
     * @var ServiceContainer Service container
     */
    private ServiceContainer $container;

    /**
     * @var UserContext Current user
     */
    private UserContext $currentUser;

    /**
     * @var ContactService Contact service
     */
    private ContactService $contactService;

    /**
     * @var TransactionService Transaction service
     */
    private TransactionService $transactionService;

    /**
     * @var WalletService Wallet service
     */
    private WalletService $walletService;

    /**
     * Constructor
     */
    public function __construct() {
        $this->container = ServiceContainer::getInstance();
        $this->currentUser = $this->container->getCurrentUser();
        $this->contactService = $this->container->getContactService();
        $this->transactionService = $this->container->getTransactionService();
        $this->walletService = $this->container->getWalletService();
    }

    /**
     * Get request body as JSON
     *
     * @return array|null Decoded JSON or null
     */
    private function getJsonBody(): ?array {
        $body = file_get_contents('php://input');
        if (empty($body)) {
            return null;
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ResponseFormatter::badRequest('Invalid JSON in request body');
            return null;
        }

        return $data;
    }

    /**
     * Health check endpoint
     *
     * @param array $params Route parameters
     * @return void
     */
    public function healthCheck(array $params): void {
        ResponseFormatter::success([
            'status' => 'healthy',
            'timestamp' => time(),
            'wallet_initialized' => $this->currentUser->hasKeys()
        ], 'API is operational');
    }

    /**
     * Authenticate and get session token
     * POST /api/auth
     *
     * @param array $params Route parameters
     * @return void
     */
    public function authenticate(array $params): void {
        $body = $this->getJsonBody();

        if (!isset($body['authcode'])) {
            ResponseFormatter::badRequest('authcode is required');
            return;
        }

        $authMiddleware = new AuthMiddleware($this->currentUser);
        $token = $authMiddleware->generateSessionToken($body['authcode']);

        if (!$token) {
            ResponseFormatter::unauthorized('Invalid authentication code');
            return;
        }

        ResponseFormatter::success([
            'token' => $token,
            'expires_in' => 3600,
            'token_type' => 'Bearer'
        ], 'Authentication successful');
    }

    /**
     * Get wallet information
     * GET /api/wallet/info
     *
     * @param array $params Route parameters
     * @return void
     */
    public function getWalletInfo(array $params): void {
        $publicKey = $this->currentUser->getPublicKey();
        $torAddress = $this->currentUser->getTorAddress();
        $httpAddress = $this->currentUser->getHttpAddress();

        ResponseFormatter::success([
            'public_key' => $publicKey,
            'tor_address' => $torAddress,
            'http_address' => $httpAddress,
            'addresses' => $this->currentUser->getUserAddresses(),
            'has_keys' => $this->currentUser->hasKeys()
        ], 'Wallet information retrieved');
    }

    /**
     * Get wallet balance
     * GET /api/wallet/balance
     *
     * @param array $params Route parameters
     * @return void
     */
    public function getBalance(array $params): void {
        $userPubkey = $this->currentUser->getPublicKey();

        // Get total balance
        $totalBalance = $this->transactionService->getUserTotalBalance();

        // Get all contacts
        $contacts = $this->contactService->getAllContacts();
        $contactPubkeys = array_column($contacts, 'pubkey');

        // Get balances with each contact
        $contactBalances = [];
        if (!empty($contactPubkeys)) {
            $balances = $this->transactionService->getAllContactBalances($userPubkey, $contactPubkeys);

            // Format contact balances with names
            foreach ($contacts as $contact) {
                if (isset($balances[$contact['pubkey']])) {
                    $contactBalances[] = [
                        'address' => $contact['address'],
                        'name' => $contact['name'] ?? 'Unknown',
                        'balance' => $balances[$contact['pubkey']] / 100, // Convert from cents to dollars
                        'currency' => $contact['currency'] ?? 'USD'
                    ];
                }
            }
        }

        ResponseFormatter::success([
            'total_balance' => $totalBalance / 100, // Convert from cents to dollars
            'currency' => $this->currentUser->getDefaultCurrency(),
            'contact_balances' => $contactBalances
        ], 'Balance retrieved successfully');
    }

    /**
     * Send transaction
     * POST /api/wallet/send
     *
     * @param array $params Route parameters
     * @return void
     */
    public function sendTransaction(array $params): void {
        $body = $this->getJsonBody();

        // Validate required fields
        $requiredFields = ['recipient', 'amount'];
        foreach ($requiredFields as $field) {
            if (!isset($body[$field])) {
                ResponseFormatter::badRequest("Missing required field: $field");
                return;
            }
        }

        // Prepare request in CLI format for service
        $request = [
            0 => 'eiou',
            1 => 'send',
            2 => $body['recipient'], // Name or address
            3 => $body['amount'],
            4 => $body['currency'] ?? 'USD'
        ];

        try {
            // Capture output
            ob_start();
            $this->transactionService->sendEiou($request);
            $output = ob_get_clean();

            ResponseFormatter::success([
                'message' => 'Transaction initiated',
                'recipient' => $body['recipient'],
                'amount' => $body['amount'],
                'currency' => $body['currency'] ?? 'USD'
            ], 'Transaction sent successfully');

        } catch (Exception $e) {
            error_log('Send transaction error: ' . $e->getMessage());
            ResponseFormatter::serverError('Failed to send transaction', $e);
        }
    }

    /**
     * List all contacts
     * GET /api/contacts
     *
     * @param array $params Route parameters
     * @return void
     */
    public function listContacts(array $params): void {
        $contacts = $this->contactService->getAllContacts();

        // Format contacts for API response
        $formattedContacts = array_map(function($contact) {
            return [
                'address' => $contact['address'],
                'name' => $contact['name'] ?? null,
                'status' => $contact['status'],
                'fee' => isset($contact['fee']) ? $contact['fee'] / 100 : null,
                'credit' => isset($contact['credit']) ? $contact['credit'] / 100 : null,
                'currency' => $contact['currency'] ?? 'USD'
            ];
        }, $contacts);

        ResponseFormatter::success([
            'contacts' => $formattedContacts,
            'count' => count($formattedContacts)
        ], 'Contacts retrieved successfully');
    }

    /**
     * Add new contact
     * POST /api/contacts
     *
     * @param array $params Route parameters
     * @return void
     */
    public function addContact(array $params): void {
        $body = $this->getJsonBody();

        // Validate required fields
        $requiredFields = ['address', 'name', 'fee', 'credit', 'currency'];
        foreach ($requiredFields as $field) {
            if (!isset($body[$field])) {
                ResponseFormatter::badRequest("Missing required field: $field");
                return;
            }
        }

        // Prepare request in CLI format
        $request = [
            0 => 'eiou',
            1 => 'add',
            2 => $body['address'],
            3 => $body['name'],
            4 => $body['fee'],
            5 => $body['credit'],
            6 => $body['currency']
        ];

        try {
            // Capture output
            ob_start();
            $this->contactService->addContact($request);
            $output = ob_get_clean();

            ResponseFormatter::created([
                'address' => $body['address'],
                'name' => $body['name']
            ], 'Contact added successfully');

        } catch (Exception $e) {
            error_log('Add contact error: ' . $e->getMessage());
            ResponseFormatter::serverError('Failed to add contact', $e);
        }
    }

    /**
     * Get contact by address
     * GET /api/contacts/:address
     *
     * @param array $params Route parameters
     * @return void
     */
    public function getContact(array $params): void {
        $address = $params['address'] ?? null;

        if (!$address) {
            ResponseFormatter::badRequest('Address parameter is required');
            return;
        }

        $contact = $this->contactService->lookupContactByAddress($address);

        if (!$contact) {
            ResponseFormatter::notFound('Contact not found');
            return;
        }

        ResponseFormatter::success([
            'address' => $contact['address'],
            'name' => $contact['name'] ?? null,
            'status' => $contact['status'] ?? 'unknown',
            'fee' => isset($contact['fee']) ? $contact['fee'] / 100 : null,
            'credit' => isset($contact['credit']) ? $contact['credit'] / 100 : null,
            'currency' => $contact['currency'] ?? 'USD',
            'pubkey' => $contact['pubkey'] ?? null
        ], 'Contact retrieved successfully');
    }

    /**
     * Update contact
     * PUT /api/contacts/:address
     *
     * @param array $params Route parameters
     * @return void
     */
    public function updateContact(array $params): void {
        $address = $params['address'] ?? null;

        if (!$address) {
            ResponseFormatter::badRequest('Address parameter is required');
            return;
        }

        $body = $this->getJsonBody();

        // Check if contact exists
        if (!$this->contactService->contactExists($address)) {
            ResponseFormatter::notFound('Contact not found');
            return;
        }

        // Prepare request for update
        $request = [
            0 => 'eiou',
            1 => 'update',
            2 => $address
        ];

        try {
            // Note: The updateContact method uses interactive input
            // For API, we need a different approach
            // This is a simplified version - you may need to enhance this

            ResponseFormatter::success([
                'address' => $address,
                'message' => 'Contact update initiated (interactive updates not yet supported via API)'
            ], 'Contact update request processed');

        } catch (Exception $e) {
            error_log('Update contact error: ' . $e->getMessage());
            ResponseFormatter::serverError('Failed to update contact', $e);
        }
    }

    /**
     * Delete contact
     * DELETE /api/contacts/:address
     *
     * @param array $params Route parameters
     * @return void
     */
    public function deleteContact(array $params): void {
        $address = $params['address'] ?? null;

        if (!$address) {
            ResponseFormatter::badRequest('Address parameter is required');
            return;
        }

        try {
            $result = $this->contactService->deleteContact($address);

            if ($result) {
                ResponseFormatter::success([
                    'address' => $address
                ], 'Contact deleted successfully');
            } else {
                ResponseFormatter::notFound('Contact not found or could not be deleted');
            }

        } catch (Exception $e) {
            error_log('Delete contact error: ' . $e->getMessage());
            ResponseFormatter::serverError('Failed to delete contact', $e);
        }
    }

    /**
     * List transactions
     * GET /api/transactions
     *
     * @param array $params Route parameters
     * @return void
     */
    public function listTransactions(array $params): void {
        // Get limit from query parameter (default 50)
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $limit = min($limit, 500); // Max 500

        // Get sent and received transactions
        $sentTransactions = $this->transactionService->getSentUserTransactions($limit);
        $receivedTransactions = $this->transactionService->getReceivedUserTransactions($limit);

        // Format transactions
        $formattedSent = array_map(function($tx) {
            return $this->formatTransaction($tx, 'sent');
        }, $sentTransactions);

        $formattedReceived = array_map(function($tx) {
            return $this->formatTransaction($tx, 'received');
        }, $receivedTransactions);

        // Combine and sort by timestamp
        $allTransactions = array_merge($formattedSent, $formattedReceived);
        usort($allTransactions, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        ResponseFormatter::success([
            'transactions' => array_slice($allTransactions, 0, $limit),
            'count' => count($allTransactions),
            'limit' => $limit
        ], 'Transactions retrieved successfully');
    }

    /**
     * Get transaction history
     * GET /api/transactions/history
     *
     * @param array $params Route parameters
     * @return void
     */
    public function getTransactionHistory(array $params): void {
        // Get statistics
        $stats = $this->transactionService->getStatistics();

        ResponseFormatter::success([
            'statistics' => $stats
        ], 'Transaction history retrieved successfully');
    }

    /**
     * Get sent transactions
     * GET /api/transactions/sent
     *
     * @param array $params Route parameters
     * @return void
     */
    public function getSentTransactions(array $params): void {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $limit = min($limit, 500);

        $transactions = $this->transactionService->getSentUserTransactions($limit);

        $formatted = array_map(function($tx) {
            return $this->formatTransaction($tx, 'sent');
        }, $transactions);

        ResponseFormatter::success([
            'transactions' => $formatted,
            'count' => count($formatted)
        ], 'Sent transactions retrieved successfully');
    }

    /**
     * Get received transactions
     * GET /api/transactions/received
     *
     * @param array $params Route parameters
     * @return void
     */
    public function getReceivedTransactions(array $params): void {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $limit = min($limit, 500);

        $transactions = $this->transactionService->getReceivedUserTransactions($limit);

        $formatted = array_map(function($tx) {
            return $this->formatTransaction($tx, 'received');
        }, $transactions);

        ResponseFormatter::success([
            'transactions' => $formatted,
            'count' => count($formatted)
        ], 'Received transactions retrieved successfully');
    }

    /**
     * Get transaction by txid
     * GET /api/transactions/:txid
     *
     * @param array $params Route parameters
     * @return void
     */
    public function getTransaction(array $params): void {
        $txid = $params['txid'] ?? null;

        if (!$txid) {
            ResponseFormatter::badRequest('Transaction ID is required');
            return;
        }

        $transaction = $this->transactionService->getByTxid($txid);

        if (!$transaction) {
            ResponseFormatter::notFound('Transaction not found');
            return;
        }

        // Determine direction
        $direction = ($transaction['sender_address'] == $this->currentUser->getHttpAddress()
                   || $transaction['sender_address'] == $this->currentUser->getTorAddress())
                   ? 'sent' : 'received';

        ResponseFormatter::success(
            $this->formatTransaction($transaction, $direction),
            'Transaction retrieved successfully'
        );
    }

    /**
     * Format transaction for API response
     *
     * @param array $tx Transaction data
     * @param string $direction 'sent' or 'received'
     * @return array Formatted transaction
     */
    private function formatTransaction(array $tx, string $direction): array {
        return [
            'txid' => $tx['txid'] ?? null,
            'direction' => $direction,
            'amount' => isset($tx['amount']) ? $tx['amount'] / 100 : 0,
            'currency' => $tx['currency'] ?? 'USD',
            'status' => $tx['status'] ?? 'unknown',
            'timestamp' => $tx['time'] ?? null,
            'sender_address' => $tx['sender_address'] ?? null,
            'receiver_address' => $tx['receiver_address'] ?? null,
            'memo' => $tx['memo'] ?? null,
            'previous_txid' => $tx['previous_txid'] ?? null
        ];
    }
}
