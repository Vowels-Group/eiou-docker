<?php
# Copyright 2025

/**
 * API Controller
 *
 * Handles all REST API endpoints for external application integration
 *
 * Endpoints:
 * - GET  /api/v1/wallet/balance        - Get wallet balances
 * - POST /api/v1/wallet/send           - Send transaction
 * - GET  /api/v1/wallet/transactions   - Get transaction history
 * - GET  /api/v1/wallet/info           - Get wallet info
 *
 * - GET    /api/v1/contacts            - List all contacts
 * - POST   /api/v1/contacts            - Add new contact
 * - GET    /api/v1/contacts/:address   - Get contact details
 * - DELETE /api/v1/contacts/:address   - Delete contact
 *
 * - GET  /api/v1/system/status         - Get system status
 * - GET  /api/v1/system/metrics        - Get system metrics
 */

class ApiController {
    private $authService;
    private $apiKeyRepository;
    private $services;
    private $logger;
    private $requestStartTime;
    private $authenticatedKey;

    /**
     * Constructor
     *
     * @param ApiAuthService $authService
     * @param ApiKeyRepository $apiKeyRepository
     * @param ServiceContainer $services
     * @param SecureLogger|null $logger
     */
    public function __construct(
        $authService,
        $apiKeyRepository,
        $services,
        $logger = null
    ) {
        $this->authService = $authService;
        $this->apiKeyRepository = $apiKeyRepository;
        $this->services = $services;
        $this->logger = $logger;
        $this->requestStartTime = microtime(true);
    }

    /**
     * Handle an API request
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @param array $params Query parameters
     * @param string $body Request body
     * @param array $headers Request headers
     * @return array Response data
     */
    public function handleRequest(
        string $method,
        string $path,
        array $params,
        string $body,
        array $headers
    ): array {
        // Authenticate the request
        $authResult = $this->authService->authenticate($method, $path, $body, $headers);

        if (!$authResult['success']) {
            $this->logRequest($authResult['error_code'] ?? 'unknown', $path, $method, 401);
            return $this->errorResponse(
                $authResult['error'],
                401,
                $authResult['error_code']
            );
        }

        $this->authenticatedKey = $authResult['key'];

        // Parse the path to determine the endpoint
        $pathParts = array_values(array_filter(explode('/', $path)));

        // Expected: api, v1, resource, [action/id]
        if (count($pathParts) < 3 || $pathParts[0] !== 'api' || $pathParts[1] !== 'v1') {
            $this->logRequest($this->authenticatedKey['key_id'], $path, $method, 404);
            return $this->errorResponse('Invalid API path', 404, 'invalid_path');
        }

        $resource = $pathParts[2];
        $action = $pathParts[3] ?? null;
        $id = $pathParts[4] ?? null;

        // Route to appropriate handler
        try {
            $response = match ($resource) {
                'wallet' => $this->handleWallet($method, $action, $params, $body),
                'contacts' => $this->handleContacts($method, $action, $id, $params, $body),
                'system' => $this->handleSystem($method, $action, $params),
                'keys' => $this->handleKeys($method, $action, $params, $body),
                default => $this->errorResponse('Unknown resource: ' . $resource, 404, 'unknown_resource')
            };
        } catch (Exception $e) {
            $this->log('error', 'API request failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
            $response = $this->errorResponse('Internal server error', 500, 'internal_error');
        }

        // Log the request
        $this->logRequest(
            $this->authenticatedKey['key_id'],
            $path,
            $method,
            $response['status_code'] ?? 200
        );

        return $response;
    }

    /**
     * Handle wallet endpoints
     */
    private function handleWallet(string $method, ?string $action, array $params, string $body): array {
        return match (true) {
            $method === 'GET' && $action === 'balance' => $this->getBalance($params),
            $method === 'GET' && $action === 'balances' => $this->getBalance($params),
            $method === 'POST' && $action === 'send' => $this->sendTransaction($body),
            $method === 'GET' && $action === 'transactions' => $this->getTransactions($params),
            $method === 'GET' && $action === 'info' => $this->getWalletInfo(),
            default => $this->errorResponse('Unknown wallet action: ' . $action, 404, 'unknown_action')
        };
    }

    /**
     * Handle contacts endpoints
     *
     * Routes:
     * - GET    /api/v1/contacts                  - List all contacts
     * - POST   /api/v1/contacts                  - Add new contact
     * - GET    /api/v1/contacts/:address         - Get contact details
     * - DELETE /api/v1/contacts/:address         - Delete contact
     * - PUT    /api/v1/contacts/:address         - Update contact
     * - POST   /api/v1/contacts/block/:address   - Block contact
     * - POST   /api/v1/contacts/unblock/:address - Unblock contact
     */
    private function handleContacts(string $method, ?string $action, ?string $id, array $params, string $body): array {
        return match (true) {
            $method === 'GET' && !$action => $this->listContacts($params),
            $method === 'POST' && !$action => $this->addContact($body),
            $method === 'POST' && $action === 'block' && $id => $this->blockContact($id),
            $method === 'POST' && $action === 'unblock' && $id => $this->unblockContact($id),
            $method === 'GET' && $action => $this->getContact($action),
            $method === 'DELETE' && $action => $this->deleteContact($action),
            $method === 'PUT' && $action => $this->updateContact($action, $body),
            default => $this->errorResponse('Unknown contacts action', 404, 'unknown_action')
        };
    }

    /**
     * Handle system endpoints
     */
    private function handleSystem(string $method, ?string $action, array $params): array {
        return match (true) {
            $method === 'GET' && $action === 'status' => $this->getSystemStatus(),
            $method === 'GET' && $action === 'metrics' => $this->getSystemMetrics(),
            default => $this->errorResponse('Unknown system action: ' . $action, 404, 'unknown_action')
        };
    }

    /**
     * Handle API key management endpoints (admin only)
     */
    private function handleKeys(string $method, ?string $action, array $params, string $body): array {
        // Require admin permission for key management
        if (!$this->hasPermission('admin')) {
            return $this->errorResponse('Admin permission required', 403, 'permission_denied');
        }

        return match (true) {
            $method === 'GET' && !$action => $this->listApiKeys(),
            $method === 'POST' && !$action => $this->createApiKey($body),
            $method === 'DELETE' && $action => $this->deleteApiKey($action),
            default => $this->errorResponse('Unknown keys action', 404, 'unknown_action')
        };
    }

    // ==================== Wallet Endpoints ====================

    /**
     * GET /api/v1/wallet/balance
     */
    private function getBalance(array $params): array {
        if (!$this->hasPermission('wallet:read')) {
            return $this->permissionDenied('wallet:read');
        }

        $balanceRepo = $this->services->getBalanceRepository();
        $contactRepo = $this->services->getContactRepository();

        $balances = $balanceRepo->getAllBalances();
        $result = [];

        foreach ($balances as $balance) {
            $contact = $contactRepo->lookupByPubkeyHash($balance['pubkey_hash']);
            $result[] = [
                'contact_name' => $contact['name'] ?? 'Unknown',
                'address' => $contact['http'] ?? $contact['tor'] ?? null,
                'currency' => $balance['currency'],
                'received' => $balance['received'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR,
                'sent' => $balance['sent'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR,
                'net_balance' => ($balance['received'] - $balance['sent']) / Constants::TRANSACTION_USD_CONVERSION_FACTOR
            ];
        }

        return $this->successResponse(['balances' => $result]);
    }

    /**
     * POST /api/v1/wallet/send
     */
    private function sendTransaction(string $body): array {
        if (!$this->hasPermission('wallet:send')) {
            return $this->permissionDenied('wallet:send');
        }

        $data = json_decode($body, true);
        if (!$data) {
            return $this->errorResponse('Invalid JSON body', 400, 'invalid_json');
        }

        // Validate required fields
        $required = ['address', 'amount', 'currency'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->errorResponse("Missing required field: $field", 400, 'missing_field');
            }
        }

        // Validate amount
        if (!is_numeric($data['amount']) || $data['amount'] <= 0) {
            return $this->errorResponse('Invalid amount', 400, 'invalid_amount');
        }

        try {
            $transactionService = $this->services->getTransactionService();

            // Build argv-style array with --json flag for JSON output
            $argv = [
                'eiou',                     // $request[0] - command name
                'send',                     // $request[1] - subcommand
                $data['address'],           // $request[2] - recipient address
                (string) $data['amount'],   // $request[3] - amount
                $data['currency'],          // $request[4] - currency
                '--json'                    // Enable JSON output mode
            ];

            // Create a fresh CliOutputManager instance with JSON mode
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture JSON output from the CLI function
            ob_start();
            $transactionService->sendEiou($argv, $outputManager);
            $output = ob_get_clean();

            // Parse the CLI JSON response
            $cliResponse = $this->parseCliJsonResponse($output);

            if ($cliResponse && $cliResponse['success']) {
                return $this->successResponse([
                    'status' => $cliResponse['data']['status'] ?? 'sent',
                    'message' => $cliResponse['message'] ?? 'Transaction sent successfully',
                    'recipient' => $cliResponse['data']['recipient'] ?? $data['address'],
                    'recipient_address' => $cliResponse['data']['recipient_address'] ?? null,
                    'amount' => $cliResponse['data']['amount'] ?? (float) $data['amount'],
                    'currency' => $cliResponse['data']['currency'] ?? $data['currency'],
                    'txid' => $cliResponse['data']['txid'] ?? null,
                    'type' => $cliResponse['data']['type'] ?? 'direct'
                ]);
            } else {
                $errorMsg = $cliResponse['error']['message'] ?? 'Transaction failed';
                $errorCode = $cliResponse['error']['code'] ?? 'transaction_failed';
                return $this->errorResponse($errorMsg, 400, strtolower($errorCode));
            }
        } catch (Exception $e) {
            return $this->errorResponse('Transaction failed: ' . $e->getMessage(), 500, 'transaction_error');
        }
    }

    /**
     * GET /api/v1/wallet/transactions
     */
    private function getTransactions(array $params): array {
        if (!$this->hasPermission('wallet:read')) {
            return $this->permissionDenied('wallet:read');
        }

        $limit = min((int) ($params['limit'] ?? 50), 100);
        $offset = (int) ($params['offset'] ?? 0);
        $type = $params['type'] ?? null; // sent, received, relay

        $transactionRepo = $this->services->getTransactionRepository();

        if ($type) {
            $transactions = $transactionRepo->getTransactionsByType($type, $limit, $offset);
        } else {
            $transactions = $transactionRepo->getTransactions($limit, $offset);
        }

        $result = [];
        foreach ($transactions as $tx) {
            $result[] = [
                'txid' => $tx['txid'],
                'type' => $tx['type'],
                'tx_type' => $tx['tx_type'],
                'status' => $tx['status'],
                'amount' => $tx['amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR,
                'currency' => $tx['currency'],
                'sender_address' => $tx['sender_address'],
                'receiver_address' => $tx['receiver_address'],
                'description' => $tx['description'] ?? null,
                'memo' => $tx['memo'] ?? null,
                'timestamp' => $tx['timestamp']
            ];
        }

        $total = $transactionRepo->getTotalCountTransactions();

        return $this->successResponse([
            'transactions' => $result,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]
        ]);
    }

    /**
     * GET /api/v1/wallet/info
     */
    private function getWalletInfo(): array {
        if (!$this->hasPermission('wallet:read')) {
            return $this->permissionDenied('wallet:read');
        }

        $currentUser = $this->services->getCurrentUser();
        $addresses = $currentUser->getUserLocaters();

        return $this->successResponse([
            'public_key_hash' => $currentUser->getPublicKeyHash(),
            'addresses' => [
                'http' => $addresses['http'] ?? null,
                'tor' => $addresses['tor'] ?? null
            ]
        ]);
    }

    // ==================== Contact Endpoints ====================

    /**
     * GET /api/v1/contacts
     */
    private function listContacts(array $params): array {
        if (!$this->hasPermission('contacts:read')) {
            return $this->permissionDenied('contacts:read');
        }

        $status = $params['status'] ?? 'accepted';
        $contactRepo = $this->services->getContactRepository();
        $addressRepo = $this->services->getAddressRepository();

        $contacts = $contactRepo->getContactsByStatus($status);
        $result = [];

        foreach ($contacts as $contact) {
            $addresses = $addressRepo->lookupByPubkeyHash($contact['pubkey_hash']);
            $result[] = [
                'name' => $contact['name'],
                'pubkey_hash' => $contact['pubkey_hash'],
                'status' => $contact['status'],
                'currency' => $contact['currency'],
                'fee_percent' => $contact['fee_percent'],
                'credit_limit' => $contact['credit_limit'],
                'addresses' => [
                    'http' => $addresses['http'] ?? null,
                    'tor' => $addresses['tor'] ?? null
                ],
                'created_at' => $contact['created_at']
            ];
        }

        return $this->successResponse(['contacts' => $result, 'count' => count($result)]);
    }

    /**
     * POST /api/v1/contacts
     */
    private function addContact(string $body): array {
        if (!$this->hasPermission('contacts:write')) {
            return $this->permissionDenied('contacts:write');
        }

        $data = json_decode($body, true);
        if (!$data) {
            return $this->errorResponse('Invalid JSON body', 400, 'invalid_json');
        }

        $required = ['address', 'name'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->errorResponse("Missing required field: $field", 400, 'missing_field');
            }
        }

        try {
            $contactService = $this->services->getContactService();

            // Build argv-style array with --json flag for JSON output
            $argv = [
                'eiou',                                  // $data[0] - command name
                'add',                                   // $data[1] - subcommand
                $data['address'],                        // $data[2] - contact address
                $data['name'],                           // $data[3] - contact name
                (string) ($data['fee_percent'] ?? 1),    // $data[4] - fee percent
                (string) ($data['credit_limit'] ?? 100), // $data[5] - credit limit
                $data['currency'] ?? 'USD',              // $data[6] - currency
                '--json'                                 // Enable JSON output mode
            ];

            // Create a fresh CliOutputManager instance with JSON mode
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture JSON output from the CLI function
            ob_start();
            $contactService->addContact($argv, $outputManager);
            $output = ob_get_clean();

            // Parse the CLI JSON response
            $cliResponse = $this->parseCliJsonResponse($output);

            if ($cliResponse && $cliResponse['success']) {
                return $this->successResponse([
                    'message' => $cliResponse['message'] ?? 'Contact request sent successfully',
                    'status' => $cliResponse['data']['status'] ?? 'pending',
                    'address' => $cliResponse['data']['address'] ?? $data['address'],
                    'name' => $cliResponse['data']['name'] ?? $data['name']
                ], 201);
            } else {
                $errorMsg = $cliResponse['error']['message'] ?? 'Failed to add contact';
                $errorCode = $cliResponse['error']['code'] ?? 'contact_add_failed';
                return $this->errorResponse($errorMsg, 400, strtolower($errorCode));
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to add contact: ' . $e->getMessage(), 500, 'contact_error');
        }
    }

    /**
     * GET /api/v1/contacts/:address
     */
    private function getContact(string $address): array {
        if (!$this->hasPermission('contacts:read')) {
            return $this->permissionDenied('contacts:read');
        }

        $address = urldecode($address);
        $contactRepo = $this->services->getContactRepository();
        $addressRepo = $this->services->getAddressRepository();
        $balanceRepo = $this->services->getBalanceRepository();

        // Try to find contact by any address type using getAllAddressTypes()
        $contact = null;
        $addressTypes = $addressRepo->getAllAddressTypes();
        foreach ($addressTypes as $transportIndex) {
            $contact = $contactRepo->getContactByAddress($transportIndex, $address);
            if ($contact) {
                break;
            }
        }

        if (!$contact) {
            // Also try lookup by name as fallback
            $contact = $contactRepo->lookupByName($address);
        }

        if (!$contact) {
            return $this->errorResponse('Contact not found', 404, 'contact_not_found');
        }

        $addresses = $addressRepo->lookupByPubkeyHash($contact['pubkey_hash']);
        // getContactBalanceByPubkeyHash returns an array or null, handle properly
        $balanceResult = $balanceRepo->getContactBalanceByPubkeyHash($contact['pubkey_hash']);
        $balance = $balanceResult && count($balanceResult) > 0 ? $balanceResult[0] : null;

        return $this->successResponse([
            'contact' => [
                'name' => $contact['name'],
                'pubkey_hash' => $contact['pubkey_hash'],
                'status' => $contact['status'],
                'currency' => $contact['currency'],
                'fee_percent' => $contact['fee_percent'],
                'credit_limit' => $contact['credit_limit'],
                'addresses' => [
                    'http' => $addresses['http'] ?? null,
                    'tor' => $addresses['tor'] ?? null
                ],
                'balance' => $balance ? [
                    'received' => $balance['received'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR,
                    'sent' => $balance['sent'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR,
                    'net' => ($balance['received'] - $balance['sent']) / Constants::TRANSACTION_USD_CONVERSION_FACTOR
                ] : null,
                'created_at' => $contact['created_at']
            ]
        ]);
    }

    /**
     * DELETE /api/v1/contacts/:address
     */
    private function deleteContact(string $address): array {
        if (!$this->hasPermission('contacts:write')) {
            return $this->permissionDenied('contacts:write');
        }

        $address = urldecode($address);
        $contactService = $this->services->getContactService();

        try {
            // Build argv-style array with --json flag for JSON output
            $argv = [
                'eiou',
                'delete',
                $address,
                '--json'
            ];

            // Create a fresh CliOutputManager instance with JSON mode
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture JSON output from the CLI function
            ob_start();
            $contactService->deleteContact($address, $outputManager);
            $output = ob_get_clean();

            // Parse the CLI JSON response
            $cliResponse = $this->parseCliJsonResponse($output);

            if ($cliResponse && $cliResponse['success']) {
                return $this->successResponse([
                    'message' => $cliResponse['message'] ?? 'Contact deleted successfully',
                    'address' => $address
                ]);
            } else {
                $errorMsg = $cliResponse['error']['message'] ?? 'Failed to delete contact';
                $errorCode = $cliResponse['error']['code'] ?? 'delete_failed';
                return $this->errorResponse($errorMsg, 400, strtolower($errorCode));
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete contact: ' . $e->getMessage(), 500, 'delete_error');
        }
    }

    /**
     * PUT /api/v1/contacts/:address
     */
    private function updateContact(string $address, string $body): array {
        if (!$this->hasPermission('contacts:write')) {
            return $this->permissionDenied('contacts:write');
        }

        $data = json_decode($body, true);
        if (!$data) {
            return $this->errorResponse('Invalid JSON body', 400, 'invalid_json');
        }

        $address = urldecode($address);
        $contactRepo = $this->services->getContactRepository();
        $addressRepo = $this->services->getAddressRepository();

        try {
            // First find the contact to get pubkey
            $contact = null;
            $addressTypes = $addressRepo->getAllAddressTypes();
            foreach ($addressTypes as $transportIndex) {
                $contact = $contactRepo->lookupByAddress($transportIndex, $address);
                if ($contact) {
                    break;
                }
            }

            if (!$contact) {
                // Try by name
                $contact = $contactRepo->lookupByName($address);
            }

            if (!$contact) {
                return $this->errorResponse('Contact not found', 404, 'contact_not_found');
            }

            // Build update fields - use ContactRepository::updateContactFields directly
            $updateFields = [];
            $updatedData = ['address' => $address];

            if (isset($data['name'])) {
                $updateFields['name'] = $data['name'];
                $updatedData['name'] = $data['name'];
            }
            if (isset($data['fee_percent'])) {
                $updateFields['fee_percent'] = $data['fee_percent'] * Constants::FEE_CONVERSION_FACTOR;
                $updatedData['fee_percent'] = $data['fee_percent'];
            }
            if (isset($data['credit_limit'])) {
                $updateFields['credit_limit'] = $data['credit_limit'] * Constants::CREDIT_CONVERSION_FACTOR;
                $updatedData['credit_limit'] = $data['credit_limit'];
            }
            if (isset($data['currency'])) {
                $updateFields['currency'] = $data['currency'];
                $updatedData['currency'] = $data['currency'];
            }

            if (empty($updateFields)) {
                return $this->errorResponse('No fields to update', 400, 'no_fields');
            }

            if ($contactRepo->updateContactFields($contact['pubkey'], $updateFields)) {
                return $this->successResponse([
                    'message' => 'Contact updated successfully',
                    'updated' => $updatedData
                ]);
            } else {
                return $this->errorResponse('Failed to update contact', 400, 'update_failed');
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update contact: ' . $e->getMessage(), 500, 'update_error');
        }
    }

    /**
     * POST /api/v1/contacts/block/:address
     */
    private function blockContact(string $address): array {
        if (!$this->hasPermission('contacts:write')) {
            return $this->permissionDenied('contacts:write');
        }

        $address = urldecode($address);
        $contactService = $this->services->getContactService();

        try {
            // Build argv-style array with --json flag for JSON output
            $argv = [
                'eiou',
                'block',
                $address,
                '--json'
            ];

            // Create a fresh CliOutputManager instance with JSON mode
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture JSON output from the CLI function
            ob_start();
            $contactService->blockContact($address, $outputManager);
            $output = ob_get_clean();

            // Parse the CLI JSON response
            $cliResponse = $this->parseCliJsonResponse($output);

            if ($cliResponse && $cliResponse['success']) {
                return $this->successResponse([
                    'message' => $cliResponse['message'] ?? 'Contact blocked successfully',
                    'address' => $address,
                    'status' => 'blocked'
                ]);
            } else {
                $errorMsg = $cliResponse['error']['message'] ?? 'Failed to block contact';
                $errorCode = $cliResponse['error']['code'] ?? 'block_failed';
                return $this->errorResponse($errorMsg, 400, strtolower($errorCode));
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to block contact: ' . $e->getMessage(), 500, 'block_error');
        }
    }

    /**
     * POST /api/v1/contacts/unblock/:address
     */
    private function unblockContact(string $address): array {
        if (!$this->hasPermission('contacts:write')) {
            return $this->permissionDenied('contacts:write');
        }

        $address = urldecode($address);
        $contactService = $this->services->getContactService();

        try {
            // Build argv-style array with --json flag for JSON output
            $argv = [
                'eiou',
                'unblock',
                $address,
                '--json'
            ];

            // Create a fresh CliOutputManager instance with JSON mode
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            // Capture JSON output from the CLI function
            ob_start();
            $contactService->unblockContact($address, $outputManager);
            $output = ob_get_clean();

            // Parse the CLI JSON response
            $cliResponse = $this->parseCliJsonResponse($output);

            if ($cliResponse && $cliResponse['success']) {
                return $this->successResponse([
                    'message' => $cliResponse['message'] ?? 'Contact unblocked successfully',
                    'address' => $address,
                    'status' => 'accepted'
                ]);
            } else {
                $errorMsg = $cliResponse['error']['message'] ?? 'Failed to unblock contact';
                $errorCode = $cliResponse['error']['code'] ?? 'unblock_failed';
                return $this->errorResponse($errorMsg, 400, strtolower($errorCode));
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to unblock contact: ' . $e->getMessage(), 500, 'unblock_error');
        }
    }

    // ==================== System Endpoints ====================

    /**
     * GET /api/v1/system/status
     */
    private function getSystemStatus(): array {
        if (!$this->hasPermission('system:read')) {
            return $this->permissionDenied('system:read');
        }

        // Check database connection
        $dbStatus = 'healthy';
        try {
            $this->services->getPdo()->query('SELECT 1');
        } catch (Exception $e) {
            $dbStatus = 'unhealthy';
        }

        // Check if processors are running
        $processors = [
            'p2p' => file_exists('/tmp/p2p_processor.pid'),
            'transaction' => file_exists('/tmp/transaction_processor.pid'),
            'cleanup' => file_exists('/tmp/cleanup_processor.pid')
        ];

        return $this->successResponse([
            'status' => 'operational',
            'version' => Constants::APP_VERSION ?? '1.0.0',
            'environment' => Constants::APP_ENV ?? 'production',
            'database' => $dbStatus,
            'processors' => $processors,
            'timestamp' => date('c')
        ]);
    }

    /**
     * GET /api/v1/system/metrics
     */
    private function getSystemMetrics(): array {
        if (!$this->hasPermission('system:read')) {
            return $this->permissionDenied('system:read');
        }

        $transactionRepo = $this->services->getTransactionRepository();
        $contactRepo = $this->services->getContactRepository();
        $p2pRepo = $this->services->getP2pRepository();

        // Get statistics
        $txStats = $transactionRepo->getTransactionsTypeStatistics();
        $contactCount = $contactRepo->countAcceptedContacts();
        $queuedP2p = $p2pRepo->getCountP2pMessagesWithStatus('queued');

        $txByType = [];
        foreach ($txStats as $stat) {
            $txByType[$stat['type']] = (int) $stat['count'];
        }

        return $this->successResponse([
            'transactions' => [
                'total' => $transactionRepo->getTotalCountTransactions(),
                'by_type' => $txByType
            ],
            'contacts' => [
                'total_accepted' => $contactCount
            ],
            'p2p' => [
                'queued' => $queuedP2p
            ],
            'uptime' => $this->getUptime(),
            'memory_usage' => memory_get_usage(true),
            'timestamp' => date('c')
        ]);
    }

    // ==================== API Key Management ====================

    /**
     * GET /api/v1/keys
     */
    private function listApiKeys(): array {
        $keys = $this->apiKeyRepository->listKeys(true);
        return $this->successResponse(['keys' => $keys]);
    }

    /**
     * POST /api/v1/keys
     */
    private function createApiKey(string $body): array {
        $data = json_decode($body, true);
        if (!$data || empty($data['name'])) {
            return $this->errorResponse('Missing required field: name', 400, 'missing_field');
        }

        $permissions = $data['permissions'] ?? ['wallet:read', 'contacts:read'];
        $rateLimit = $data['rate_limit_per_minute'] ?? 100;
        $expiresAt = $data['expires_at'] ?? null;

        $key = $this->apiKeyRepository->createKey($data['name'], $permissions, $rateLimit, $expiresAt);

        return $this->successResponse([
            'key_id' => $key['key_id'],
            'secret' => $key['secret'],
            'name' => $key['name'],
            'permissions' => $key['permissions'],
            'rate_limit_per_minute' => $key['rate_limit_per_minute'],
            'warning' => 'Save this secret now! It will not be shown again.'
        ], 201);
    }

    /**
     * DELETE /api/v1/keys/:key_id
     */
    private function deleteApiKey(string $keyId): array {
        $deleted = $this->apiKeyRepository->deleteKey($keyId);

        if (!$deleted) {
            return $this->errorResponse('API key not found', 404, 'key_not_found');
        }

        return $this->successResponse(['message' => 'API key deleted successfully']);
    }

    // ==================== Helper Methods ====================

    /**
     * Check if authenticated key has a permission
     */
    private function hasPermission(string $permission): bool {
        return $this->authService->hasPermission($this->authenticatedKey, $permission);
    }

    /**
     * Return permission denied error
     */
    private function permissionDenied(string $permission): array {
        return $this->errorResponse(
            "Permission denied. Required: $permission",
            403,
            'permission_denied'
        );
    }

    /**
     * Parse CLI JSON response from captured output
     *
     * The CLI functions output JSON when using the --json flag.
     * This method parses that output and returns a structured array.
     *
     * @param string $output The captured output from CLI function
     * @return array|null Parsed JSON response or null if parsing fails
     */
    private function parseCliJsonResponse(string $output): ?array {
        // Trim whitespace and newlines
        $output = trim($output);

        // Handle empty output
        if (empty($output)) {
            return null;
        }

        // Try to parse as JSON
        $decoded = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Log parsing error
            $this->log('warning', 'Failed to parse CLI JSON response', [
                'output' => substr($output, 0, 500),
                'json_error' => json_last_error_msg()
            ]);
            return null;
        }

        return $decoded;
    }

    /**
     * Build success response
     */
    private function successResponse(array $data, int $statusCode = 200): array {
        return [
            'success' => true,
            'data' => $data,
            'error' => null,
            'timestamp' => date('c'),
            'request_id' => $this->generateRequestId(),
            'status_code' => $statusCode
        ];
    }

    /**
     * Build error response
     */
    private function errorResponse(string $message, int $statusCode, string $code): array {
        return [
            'success' => false,
            'data' => null,
            'error' => [
                'message' => $message,
                'code' => $code
            ],
            'timestamp' => date('c'),
            'request_id' => $this->generateRequestId(),
            'status_code' => $statusCode
        ];
    }

    /**
     * Generate unique request ID
     */
    private function generateRequestId(): string {
        return 'req_' . bin2hex(random_bytes(8));
    }

    /**
     * Log an API request
     */
    private function logRequest(string $keyId, string $path, string $method, int $statusCode): void {
        $responseTimeMs = (int) ((microtime(true) - $this->requestStartTime) * 1000);

        $this->apiKeyRepository->logRequest(
            $keyId,
            $path,
            $method,
            ApiAuthService::getClientIp(),
            $statusCode,
            $responseTimeMs
        );
    }

    /**
     * Get system uptime
     */
    private function getUptime(): ?string {
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $seconds = (int) explode(' ', $uptime)[0];

            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            $minutes = floor(($seconds % 3600) / 60);

            return "{$days}d {$hours}h {$minutes}m";
        }
        return null;
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->$level($message, $context);
        }
    }
}
