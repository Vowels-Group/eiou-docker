<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Api;

use Exception;
use Eiou\Core\Constants;
use Eiou\Cli\CliOutputManager;
use Eiou\Services\ServiceContainer;
use Eiou\Services\ApiAuthService;
use Eiou\Database\ApiKeyRepository;
use Eiou\Utils\Logger;
use Eiou\Exceptions\ServiceException;
use Eiou\Processors\AbstractMessageProcessor;
use Eiou\Utils\InputValidator;

/**
 * API Controller
 *
 * Handles all REST API endpoints for external application integration
 *
 * Endpoints:
 * - GET  /api/v1/wallet/balance              - Get wallet balances
 * - POST /api/v1/wallet/send                 - Send transaction
 * - GET  /api/v1/wallet/transactions         - Get transaction history
 * - GET  /api/v1/wallet/info                 - Get wallet info
 * - GET  /api/v1/wallet/overview             - Get wallet overview (balance + recent transactions)
 *
 * - GET    /api/v1/contacts                  - List all contacts
 * - POST   /api/v1/contacts                  - Add new contact
 * - GET    /api/v1/contacts/pending          - Get pending contact requests
 * - GET    /api/v1/contacts/search           - Search contacts by name
 * - POST   /api/v1/contacts/ping/:address    - Ping a contact to check online status
 * - GET    /api/v1/contacts/:address         - Get contact details
 * - DELETE /api/v1/contacts/:address         - Delete contact
 * - PUT    /api/v1/contacts/:address         - Update contact
 * - POST   /api/v1/contacts/block/:address   - Block contact
 * - POST   /api/v1/contacts/unblock/:address - Unblock contact
 *
 * - GET  /api/v1/system/status               - Get system status
 * - GET  /api/v1/system/metrics              - Get system metrics
 * - GET  /api/v1/system/settings             - Get system settings
 * - PUT  /api/v1/system/settings             - Update system settings
 * - POST /api/v1/system/sync                 - Trigger sync operation
 * - POST /api/v1/system/shutdown             - Shutdown processors
 * - POST /api/v1/system/start               - Start processors
 *
 * - POST /api/v1/chaindrop/propose           - Propose chain drop
 * - POST /api/v1/chaindrop/accept            - Accept chain drop proposal
 * - POST /api/v1/chaindrop/reject            - Reject chain drop proposal
 * - GET  /api/v1/chaindrop                   - List chain drop proposals
 *
 * - POST /api/v1/keys/enable/:key_id        - Enable an API key
 * - POST /api/v1/keys/disable/:key_id       - Disable an API key
 *
 * - GET    /api/v1/backup/status             - Get backup status and settings
 * - GET    /api/v1/backup/list               - List all backups
 * - POST   /api/v1/backup/create             - Create a new backup
 * - POST   /api/v1/backup/restore            - Restore from backup (requires confirm: true)
 * - POST   /api/v1/backup/verify             - Verify backup integrity
 * - DELETE /api/v1/backup/:filename          - Delete a backup
 * - POST   /api/v1/backup/enable             - Enable automatic backups
 * - POST   /api/v1/backup/disable            - Disable automatic backups
 * - POST   /api/v1/backup/cleanup            - Cleanup old backups
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
     * @param Logger|null $logger
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
            $this->logRequest($authResult['code'] ?? 'unknown', $path, $method, 401);
            return $this->errorResponse(
                $authResult['error'],
                401,
                $authResult['code']
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
                'system' => $this->handleSystem($method, $action, $params, $body),
                'keys' => $this->handleKeys($method, $action, $id, $params, $body),
                'chaindrop' => $this->handleChainDrop($method, $action, $params, $body),
                'backup' => $this->handleBackup($method, $action, $params, $body),
                default => $this->errorResponse('Unknown resource: ' . $resource, 404, 'unknown_resource')
            };
        } catch (ServiceException $e) {
            // Handle ServiceExceptions with their rich error context
            $this->log('warning', 'Service exception in API request', [
                'path' => $path,
                'error' => $e->getMessage(),
                'code' => $e->getErrorCode(),
                'context' => $e->getContext()
            ]);
            $response = $this->errorResponse(
                $e->getMessage(),
                $e->getHttpStatus(),
                strtolower($e->getErrorCode())
            );
        } catch (Exception $e) {
            // Generic fallback for unexpected exceptions
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
            $method === 'GET' && $action === 'overview' => $this->getWalletOverview($params),
            default => $this->errorResponse('Unknown wallet action: ' . $action, 404, 'unknown_action')
        };
    }

    /**
     * Handle contacts endpoints
     *
     * Routes:
     * - GET    /api/v1/contacts                  - List all contacts
     * - POST   /api/v1/contacts                  - Add new contact
     * - GET    /api/v1/contacts/pending          - Get pending contact requests
     * - GET    /api/v1/contacts/search           - Search contacts by name
     * - POST   /api/v1/contacts/ping/:address    - Ping a contact
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
            $method === 'GET' && $action === 'pending' => $this->listPendingContacts(),
            $method === 'GET' && $action === 'search' => $this->searchContacts($params),
            $method === 'POST' && $action === 'ping' && $id => $this->pingContact($id),
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
    private function handleSystem(string $method, ?string $action, array $params, string $body = ''): array {
        return match (true) {
            $method === 'GET' && $action === 'status' => $this->getSystemStatus(),
            $method === 'GET' && $action === 'metrics' => $this->getSystemMetrics(),
            $method === 'GET' && $action === 'settings' => $this->getSystemSettings(),
            $method === 'PUT' && $action === 'settings' => $this->updateSettings($body),
            $method === 'POST' && $action === 'sync' => $this->triggerSync($body),
            $method === 'POST' && $action === 'shutdown' => $this->shutdownProcessors(),
            $method === 'POST' && $action === 'start' => $this->startProcessors(),
            default => $this->errorResponse('Unknown system action: ' . $action, 404, 'unknown_action')
        };
    }

    /**
     * Handle API key management endpoints (admin only)
     */
    private function handleKeys(string $method, ?string $action, ?string $id, array $params, string $body): array {
        // Require admin permission for key management
        if (!$this->hasPermission('admin')) {
            return $this->errorResponse('Admin permission required', 403, 'permission_denied');
        }

        return match (true) {
            $method === 'GET' && !$action => $this->listApiKeys(),
            $method === 'POST' && !$action => $this->createApiKey($body),
            $method === 'POST' && $action === 'enable' && $id !== null => $this->enableApiKey($id),
            $method === 'POST' && $action === 'disable' && $id !== null => $this->disableApiKey($id),
            $method === 'DELETE' && $action => $this->deleteApiKey($action),
            default => $this->errorResponse('Unknown keys action', 404, 'unknown_action')
        };
    }

    /**
     * Handle backup endpoints
     *
     * Routes:
     * - GET    /api/v1/backup/status             - Get backup status
     * - GET    /api/v1/backup/list               - List all backups
     * - POST   /api/v1/backup/create             - Create a new backup
     * - POST   /api/v1/backup/restore            - Restore from backup
     * - POST   /api/v1/backup/verify             - Verify backup integrity
     * - DELETE /api/v1/backup/:filename          - Delete a backup
     * - POST   /api/v1/backup/enable             - Enable automatic backups
     * - POST   /api/v1/backup/disable            - Disable automatic backups
     * - POST   /api/v1/backup/cleanup            - Cleanup old backups
     */
    private function handleBackup(string $method, ?string $action, array $params, string $body): array {
        return match (true) {
            $method === 'GET' && $action === 'status' => $this->getBackupStatus(),
            $method === 'GET' && $action === 'list' => $this->listBackups(),
            $method === 'POST' && $action === 'create' => $this->createBackup($body),
            $method === 'POST' && $action === 'restore' => $this->restoreBackup($body),
            $method === 'POST' && $action === 'verify' => $this->verifyBackup($body),
            $method === 'DELETE' && $action => $this->deleteBackup($action),
            $method === 'POST' && $action === 'enable' => $this->enableAutoBackup(),
            $method === 'POST' && $action === 'disable' => $this->disableAutoBackup(),
            $method === 'POST' && $action === 'cleanup' => $this->cleanupBackups(),
            default => $this->errorResponse('Unknown backup action: ' . $action, 404, 'unknown_action')
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
                'address' => $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? null,
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
                    'status' => $cliResponse['data']['status'] ?? Constants::STATUS_SENT,
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
        $contactFilter = $params['contact'] ?? null;

        $transactionRepo = $this->services->getTransactionRepository();
        $transactionStatsRepo = $this->services->getTransactionStatisticsRepository();

        // If contact filter provided, resolve to addresses and filter
        if ($contactFilter) {
            $contactRepo = $this->services->getContactRepository();
            $addressRepo = $this->services->getAddressRepository();

            // Resolve contact by name or address
            $contact = $contactRepo->lookupByName($contactFilter);
            if (!$contact) {
                $addressTypes = $addressRepo->getAllAddressTypes();
                foreach ($addressTypes as $transportIndex) {
                    $contact = $contactRepo->lookupByAddress($transportIndex, $contactFilter);
                    if ($contact) {
                        break;
                    }
                }
            }

            if (!$contact) {
                return $this->errorResponse('Contact not found', 404, 'contact_not_found');
            }

            // Get all transport addresses for this contact
            $contactAddresses = $addressRepo->lookupByPubkeyHash($contact['pubkey_hash']);
            $addresses = [];
            $addressTypes = $addressRepo->getAllAddressTypes();
            foreach ($addressTypes as $addrType) {
                if (!empty($contactAddresses[$addrType])) {
                    $addresses[] = $contactAddresses[$addrType];
                }
            }

            if (empty($addresses)) {
                return $this->successResponse([
                    'transactions' => [],
                    'pagination' => ['total' => 0, 'limit' => $limit, 'offset' => $offset],
                    'contact' => $contactFilter
                ]);
            }

            // Get all transactions then filter by contact addresses
            $allTransactions = $transactionRepo->getTransactions($limit * 10);
            $transactions = [];
            foreach ($allTransactions as $tx) {
                $matchesSender = in_array($tx['sender_address'] ?? '', $addresses, true);
                $matchesReceiver = in_array($tx['receiver_address'] ?? '', $addresses, true);
                if ($matchesSender || $matchesReceiver) {
                    if ($type && ($tx['type'] ?? '') !== $type) {
                        continue;
                    }
                    $transactions[] = $tx;
                }
            }

            // Apply offset and limit
            $total = count($transactions);
            $transactions = array_slice($transactions, $offset, $limit);
        } else {
            if ($type) {
                $transactions = $transactionRepo->getTransactionsByType($type, $limit, $offset);
            } else {
                $transactions = $transactionRepo->getTransactions($limit, $offset);
            }
            $total = $transactionStatsRepo->getTotalCount();
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

        $responseData = [
            'transactions' => $result,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]
        ];

        if ($contactFilter) {
            $responseData['contact'] = $contactFilter;
        }

        return $this->successResponse($responseData);
    }

    /**
     * GET /api/v1/wallet/info
     */
    private function getWalletInfo(): array {
        if (!$this->hasPermission('wallet:read')) {
            return $this->permissionDenied('wallet:read');
        }

        $currentUser = $this->services->getCurrentUser();
        $userAddresses = $currentUser->getUserLocaters();
        $addressRepo = $this->services->getAddressRepository();

        // Use getAllAddressTypes() to dynamically get all address types
        $addressTypes = $addressRepo->getAllAddressTypes();
        $addresses = [];
        foreach ($addressTypes as $type) {
            $addresses[$type] = $userAddresses[$type] ?? null;
        }

        // Fee earnings from P2P relay transactions
        $p2pRepo = $this->services->getP2pRepository();
        $feeEarnings = [];
        $earningsRows = $p2pRepo->getUserTotalEarningsByCurrency();
        foreach ($earningsRows as $row) {
            $feeEarnings[] = [
                'currency' => $row['currency'],
                'total_amount' => $row['total_amount'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR
            ];
        }

        // Total available credit from all contacts
        $creditRepo = $this->services->getContactCreditRepository();
        $availableCredit = [];
        $creditRows = $creditRepo->getTotalAvailableCreditByCurrency();
        foreach ($creditRows as $row) {
            $availableCredit[] = [
                'currency' => $row['currency'],
                'total_available_credit' => $row['total_available_credit'] / Constants::CREDIT_CONVERSION_FACTOR
            ];
        }

        return $this->successResponse([
            'public_key_hash' => $currentUser->getPublicKeyHash(),
            'addresses' => $addresses,
            'fee_earnings' => $feeEarnings,
            'available_credit' => $availableCredit
        ]);
    }

    /**
     * GET /api/v1/wallet/overview
     *
     * Returns wallet overview for dashboard display:
     * - Overall balance by currency
     * - Most recent transactions
     */
    private function getWalletOverview(array $params): array {
        if (!$this->hasPermission('wallet:read')) {
            return $this->permissionDenied('wallet:read');
        }

        $balanceRepo = $this->services->getBalanceRepository();
        $transactionRepo = $this->services->getTransactionRepository();

        // Get transaction limit from params (default 5, max 20 for overview)
        $transactionLimit = min((int) ($params['transaction_limit'] ?? 5), 20);

        // Get overall balances by currency
        $balances = $balanceRepo->getUserBalance();
        $balanceResult = [];

        if ($balances) {
            foreach ($balances as $balance) {
                $balanceResult[] = [
                    'currency' => $balance['currency'],
                    'total_balance' => $balance['total_balance'] / Constants::TRANSACTION_USD_CONVERSION_FACTOR
                ];
            }
        }

        // Get recent transactions
        $transactions = $transactionRepo->getRecentTransactions($transactionLimit);
        $transactionResult = [];

        foreach ($transactions as $tx) {
            $transactionResult[] = [
                'txid' => $tx['txid'] ?? null,
                'type' => $tx['direction'] ?? $tx['type'] ?? null,
                'tx_type' => $tx['tx_type'] ?? null,
                'status' => $tx['status'] ?? null,
                'amount' => is_numeric($tx['amount'] ?? null) ? $tx['amount'] : 0,
                'currency' => $tx['currency'] ?? null,
                'counterparty_name' => $tx['counterparty_name'] ?? null,
                'sender_address' => $tx['sender_address'] ?? null,
                'receiver_address' => $tx['receiver_address'] ?? null,
                'memo' => $tx['memo'] ?? null,
                'timestamp' => $tx['timestamp'] ?? $tx['date'] ?? null
            ];
        }

        // Get total available credit per currency
        $creditRepo = $this->services->getContactCreditRepository();
        $totalAvailableCredit = [];
        $creditRows = $creditRepo->getTotalAvailableCreditByCurrency();
        foreach ($creditRows as $row) {
            $totalAvailableCredit[] = [
                'currency' => $row['currency'],
                'total_available_credit' => $row['total_available_credit'] / Constants::CREDIT_CONVERSION_FACTOR
            ];
        }

        return $this->successResponse([
            'balances' => $balanceResult,
            'total_available_credit' => $totalAvailableCredit,
            'recent_transactions' => $transactionResult,
            'transaction_count' => count($transactionResult)
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

        $status = $params['status'] ?? Constants::CONTACT_STATUS_ACCEPTED;
        $contactRepo = $this->services->getContactRepository();
        $addressRepo = $this->services->getAddressRepository();

        // Get all address types dynamically for future-proofing
        $addressTypes = $addressRepo->getAllAddressTypes();

        $contacts = $contactRepo->getContactsByStatus($status);
        $creditRepo = $this->services->getContactCreditRepository();
        $balanceRepo = $this->services->getBalanceRepository();
        $result = [];

        foreach ($contacts as $contact) {
            $contactAddresses = $addressRepo->lookupByPubkeyHash($contact['pubkey_hash']);

            // Build addresses array dynamically based on available address types
            $addresses = [];
            foreach ($addressTypes as $type) {
                $addresses[$type] = $contactAddresses[$type] ?? null;
            }

            // My available credit with them (from contact_credit table, received via pong)
            $myAvailableCredit = null;
            $creditData = $creditRepo->getAvailableCredit($contact['pubkey_hash']);
            if ($creditData !== null) {
                $myAvailableCredit = $creditData['available_credit'] / Constants::CREDIT_CONVERSION_FACTOR;
            }

            // Their available credit with me (calculated: sent - received + credit_limit)
            $theirAvailableCredit = null;
            $currency = $contact['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
            $balanceData = $balanceRepo->getContactBalanceByPubkeyHash($contact['pubkey_hash'], $currency);
            if ($balanceData && count($balanceData) > 0) {
                $b = $balanceData[0];
                $theirCents = ((int)($b['sent'] ?? 0)) - ((int)($b['received'] ?? 0)) + ((int)($contact['credit_limit'] ?? 0));
                $theirAvailableCredit = $theirCents / Constants::CREDIT_CONVERSION_FACTOR;
            }

            $result[] = [
                'name' => $contact['name'],
                'pubkey_hash' => $contact['pubkey_hash'],
                'status' => $contact['status'],
                'currency' => $contact['currency'],
                'fee_percent' => $contact['fee_percent'],
                'credit_limit' => $contact['credit_limit'],
                'my_available_credit' => $myAvailableCredit,
                'their_available_credit' => $theirAvailableCredit,
                'addresses' => $addresses,
                'created_at' => $contact['created_at']
            ];
        }

        return $this->successResponse(['contacts' => $result, 'count' => count($result)]);
    }

    /**
     * GET /api/v1/contacts/pending
     *
     * Returns all pending contact requests:
     * - incoming: Requests from others waiting for user to accept
     * - outgoing: Requests user sent waiting for others to accept
     */
    private function listPendingContacts(): array {
        if (!$this->hasPermission('contacts:read')) {
            return $this->permissionDenied('contacts:read');
        }

        $contactRepo = $this->services->getContactRepository();
        $addressRepo = $this->services->getAddressRepository();

        // Get all address types dynamically for future-proofing
        $addressTypes = $addressRepo->getAllAddressTypes();

        // Get incoming pending requests (from others, name IS NULL)
        $incomingPending = $contactRepo->getPendingContactRequests();
        $incoming = [];

        foreach ($incomingPending as $contact) {
            // Build addresses array dynamically based on available address types
            $addresses = [];
            foreach ($addressTypes as $type) {
                $addresses[$type] = $contact[$type] ?? null;
            }

            $incoming[] = [
                'pubkey_hash' => $contact['pubkey_hash'] ?? null,
                'status' => $contact['status'] ?? null,
                'addresses' => $addresses,
                'created_at' => $contact['created_at'] ?? null
            ];
        }

        // Get outgoing pending requests (user initiated, name IS NOT NULL)
        $outgoingPending = $contactRepo->getUserPendingContactRequests();
        $outgoing = [];

        foreach ($outgoingPending as $contact) {
            // Build addresses array dynamically based on available address types
            $addresses = [];
            foreach ($addressTypes as $type) {
                $addresses[$type] = $contact[$type] ?? null;
            }

            $outgoing[] = [
                'name' => $contact['name'] ?? null,
                'pubkey_hash' => $contact['pubkey_hash'] ?? null,
                'status' => $contact['status'] ?? null,
                'currency' => $contact['currency'] ?? null,
                'fee_percent' => $contact['fee_percent'] ?? null,
                'credit_limit' => $contact['credit_limit'] ?? null,
                'addresses' => $addresses,
                'created_at' => $contact['created_at'] ?? null
            ];
        }

        return $this->successResponse([
            'pending' => [
                'incoming' => $incoming,
                'outgoing' => $outgoing
            ],
            'counts' => [
                'incoming' => count($incoming),
                'outgoing' => count($outgoing),
                'total' => count($incoming) + count($outgoing)
            ]
        ]);
    }

    /**
     * GET /api/v1/contacts/search
     *
     * Search contacts by name
     */
    private function searchContacts(array $params): array {
        if (!$this->hasPermission('contacts:read')) {
            return $this->permissionDenied('contacts:read');
        }

        $searchTerm = $params['q'] ?? $params['query'] ?? null;
        $contactRepo = $this->services->getContactRepository();
        $addressRepo = $this->services->getAddressRepository();
        $balanceRepo = $this->services->getBalanceRepository();
        $creditRepo = $this->services->getContactCreditRepository();

        // Get all address types dynamically
        $addressTypes = $addressRepo->getAllAddressTypes();

        $contacts = $contactRepo->searchContacts($searchTerm);
        $result = [];

        if ($contacts) {
            foreach ($contacts as $contact) {
                // Build addresses array dynamically
                $addresses = [];
                foreach ($addressTypes as $type) {
                    $addresses[$type] = $contact[$type] ?? null;
                }

                // My available credit (from contact_credit table, received via pong)
                $myAvailableCredit = null;
                $hash = $contact['pubkey_hash'] ?? '';
                if ($hash) {
                    $creditData = $creditRepo->getAvailableCredit($hash);
                    if ($creditData !== null) {
                        $myAvailableCredit = $creditData['available_credit'] / Constants::CREDIT_CONVERSION_FACTOR;
                    }
                }

                // Their available credit (calculated: sent - received + credit_limit)
                $theirAvailableCredit = null;
                if ($hash) {
                    $currency = $contact['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                    $balance = $balanceRepo->getContactBalanceByPubkeyHash($hash, $currency);
                    if ($balance && count($balance) > 0) {
                        $b = $balance[0];
                        $theirCents = ((int)($b['sent'] ?? 0)) - ((int)($b['received'] ?? 0)) + ((int)($contact['credit_limit'] ?? 0));
                        $theirAvailableCredit = $theirCents / Constants::CREDIT_CONVERSION_FACTOR;
                    }
                }

                $result[] = [
                    'name' => $contact['name'] ?? null,
                    'pubkey_hash' => $hash,
                    'status' => $contact['status'] ?? null,
                    'addresses' => $addresses,
                    'fee_percent' => isset($contact['fee_percent']) ? $contact['fee_percent'] / Constants::FEE_CONVERSION_FACTOR : null,
                    'credit_limit' => isset($contact['credit_limit']) ? $contact['credit_limit'] / Constants::CREDIT_CONVERSION_FACTOR : null,
                    'my_available_credit' => $myAvailableCredit,
                    'their_available_credit' => $theirAvailableCredit,
                    'currency' => $contact['currency'] ?? null
                ];
            }
        }

        return $this->successResponse([
            'search_term' => $searchTerm,
            'contacts' => $result,
            'count' => count($result)
        ]);
    }

    /**
     * POST /api/v1/contacts/ping/:address
     *
     * Ping a contact to check their online status
     */
    private function pingContact(string $address): array {
        if (!$this->hasPermission('contacts:read')) {
            return $this->permissionDenied('contacts:read');
        }

        $address = urldecode($address);

        try {
            $contactStatusService = $this->services->getContactStatusService();
            $result = $contactStatusService->pingContact($address);

            if ($result['success']) {
                return $this->successResponse([
                    'contact_name' => $result['contact_name'] ?? null,
                    'online_status' => $result['online_status'] ?? 'unknown',
                    'chain_valid' => $result['chain_valid'] ?? null,
                    'message' => $result['message'] ?? 'Ping complete'
                ]);
            } else {
                return $this->errorResponse(
                    $result['message'] ?? 'Ping failed',
                    $result['error'] === 'contact_not_found' ? 404 : 400,
                    $result['error'] ?? 'ping_failed'
                );
            }
        } catch (Exception $e) {
            return $this->errorResponse('Ping failed: ' . $e->getMessage(), 500, 'ping_error');
        }
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
                    'status' => $cliResponse['data']['status'] ?? Constants::CONTACT_STATUS_PENDING,
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

        $contactAddresses = $addressRepo->lookupByPubkeyHash($contact['pubkey_hash']);

        // Build addresses array dynamically based on available address types
        $addresses = [];
        foreach ($addressTypes as $type) {
            $addresses[$type] = $contactAddresses[$type] ?? null;
        }

        // getContactBalanceByPubkeyHash returns an array or null, handle properly
        $balanceResult = $balanceRepo->getContactBalanceByPubkeyHash($contact['pubkey_hash']);
        $balance = $balanceResult && count($balanceResult) > 0 ? $balanceResult[0] : null;

        // My available credit with them (from contact_credit table, received via pong)
        $creditRepo = $this->services->getContactCreditRepository();
        $myAvailableCredit = null;
        $creditData = $creditRepo->getAvailableCredit($contact['pubkey_hash']);
        if ($creditData !== null) {
            $myAvailableCredit = $creditData['available_credit'] / Constants::CREDIT_CONVERSION_FACTOR;
        }

        // Their available credit with me (calculated: sent - received + credit_limit)
        $theirAvailableCredit = null;
        if ($balance) {
            $theirCents = ((int)($balance['sent'] ?? 0)) - ((int)($balance['received'] ?? 0)) + ((int)($contact['credit_limit'] ?? 0));
            $theirAvailableCredit = $theirCents / Constants::CREDIT_CONVERSION_FACTOR;
        }

        return $this->successResponse([
            'contact' => [
                'name' => $contact['name'],
                'pubkey_hash' => $contact['pubkey_hash'],
                'status' => $contact['status'],
                'currency' => $contact['currency'],
                'fee_percent' => $contact['fee_percent'],
                'credit_limit' => $contact['credit_limit'],
                'my_available_credit' => $myAvailableCredit,
                'their_available_credit' => $theirAvailableCredit,
                'addresses' => $addresses,
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
                    'status' => Constants::CONTACT_STATUS_BLOCKED
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
                    'status' => Constants::CONTACT_STATUS_ACCEPTED
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

        // Check if processors are running (validate PID files, not just existence)
        $processors = [
            'p2p' => AbstractMessageProcessor::isProcessorRunning('/tmp/p2pmessages_lock.pid'),
            'transaction' => AbstractMessageProcessor::isProcessorRunning('/tmp/transactionmessages_lock.pid'),
            'cleanup' => AbstractMessageProcessor::isProcessorRunning('/tmp/cleanupmessages_lock.pid'),
            'contact_status' => AbstractMessageProcessor::isProcessorRunning('/tmp/contact_status.pid'),
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

        $transactionStatsRepo = $this->services->getTransactionStatisticsRepository();
        $contactRepo = $this->services->getContactRepository();
        $p2pRepo = $this->services->getP2pRepository();

        // Get statistics
        $txStats = $transactionStatsRepo->getTypeStatistics();
        $contactCount = $contactRepo->countAcceptedContacts();
        $queuedP2p = $p2pRepo->getCountP2pMessagesWithStatus(Constants::STATUS_QUEUED);

        $txByType = [];
        foreach ($txStats as $stat) {
            $txByType[$stat['type']] = (int) $stat['count'];
        }

        return $this->successResponse([
            'transactions' => [
                'total' => $transactionStatsRepo->getTotalCount(),
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

    /**
     * GET /api/v1/system/settings
     *
     * Returns current system settings (read-only)
     */
    private function getSystemSettings(): array {
        if (!$this->hasPermission('system:read')) {
            return $this->permissionDenied('system:read');
        }

        $currentUser = $this->services->getCurrentUser();

        return $this->successResponse([
            'settings' => [
                'default_currency' => $currentUser->getDefaultCurrency(),
                'minimum_fee_amount' => $currentUser->getMinimumFee(),
                'default_fee_percent' => $currentUser->getDefaultFee(),
                'maximum_fee_percent' => $currentUser->getMaxFee(),
                'default_credit_limit' => $currentUser->getDefaultCreditLimit(),
                'max_p2p_level' => $currentUser->getMaxP2pLevel(),
                'p2p_expiration_seconds' => $currentUser->getP2pExpirationTime(),
                'max_output_lines' => $currentUser->getMaxOutput(),
                'default_transport_mode' => $currentUser->getDefaultTransportMode(),
                'hostname' => $currentUser->getHttpAddress(),
                'hostname_secure' => $currentUser->getHttpsAddress(),
                'auto_refresh_enabled' => $currentUser->getAutoRefreshEnabled(),
                'auto_backup_enabled' => $currentUser->getAutoBackupEnabled()
            ]
        ]);
    }

    /**
     * PUT /api/v1/system/settings
     *
     * Update system settings (one at a time)
     */
    private function updateSettings(string $body): array {
        if (!$this->hasPermission('admin')) {
            return $this->permissionDenied('admin');
        }

        $data = json_decode($body, true);
        if (!$data) {
            return $this->errorResponse('Invalid JSON body', 400, 'invalid_json');
        }

        // Map API field names to internal config keys with their validation
        $settingsMap = [
            'default_fee' => ['key' => 'defaultFee', 'validate' => 'validateFeePercent', 'config' => 'defaultconfig.json'],
            'default_credit_limit' => ['key' => 'defaultCreditLimit', 'validate' => 'validateAmountFee', 'config' => 'defaultconfig.json'],
            'default_currency' => ['key' => 'defaultCurrency', 'validate' => 'validateCurrency', 'config' => 'defaultconfig.json'],
            'min_fee' => ['key' => 'minFee', 'validate' => 'validateAmountFee', 'config' => 'defaultconfig.json'],
            'max_fee' => ['key' => 'maxFee', 'validate' => 'validateFeePercent', 'config' => 'defaultconfig.json'],
            'max_p2p_level' => ['key' => 'maxP2pLevel', 'validate' => 'validateRequestLevel', 'config' => 'defaultconfig.json'],
            'p2p_expiration' => ['key' => 'p2pExpiration', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'max_output' => ['key' => 'maxOutput', 'validate' => null, 'config' => 'defaultconfig.json'],
            'default_transport_mode' => ['key' => 'defaultTransportMode', 'validate' => null, 'config' => 'defaultconfig.json'],
            'auto_refresh_enabled' => ['key' => 'autoRefreshEnabled', 'validate' => null, 'config' => 'defaultconfig.json'],
            'auto_backup_enabled' => ['key' => 'autoBackupEnabled', 'validate' => null, 'config' => 'defaultconfig.json'],
            'hostname' => ['key' => 'hostname', 'validate' => 'validateHostname', 'config' => 'userconfig.json'],
            'name' => ['key' => 'name', 'validate' => null, 'config' => 'userconfig.json'],
        ];

        $updated = [];
        $errors = [];

        foreach ($data as $apiKey => $rawValue) {
            if (!isset($settingsMap[$apiKey])) {
                $errors[] = "Unknown setting: $apiKey";
                continue;
            }

            $mapping = $settingsMap[$apiKey];
            $configKey = $mapping['key'];
            $validateMethod = $mapping['validate'];
            $configFile = $mapping['config'];

            // Validate the value
            if ($validateMethod) {
                if ($validateMethod === 'validatePositiveInteger') {
                    $validation = InputValidator::$validateMethod($rawValue, Constants::P2P_MIN_EXPIRATION_SECONDS);
                } else {
                    $validation = InputValidator::$validateMethod($rawValue);
                }
                if (!$validation['valid']) {
                    $errors[] = "$apiKey: " . $validation['error'];
                    continue;
                }
                $value = $validation['value'];
            } else {
                // Custom validation for non-InputValidator fields
                if ($configKey === 'maxOutput') {
                    if (!is_numeric($rawValue) || intval($rawValue) < 0) {
                        $errors[] = "max_output: Must be a non-negative integer (0 = unlimited)";
                        continue;
                    }
                    $value = intval($rawValue);
                } elseif ($configKey === 'defaultTransportMode') {
                    $value = strtolower((string) $rawValue);
                } elseif ($configKey === 'autoRefreshEnabled' || $configKey === 'autoBackupEnabled') {
                    if (is_bool($rawValue)) {
                        $value = $rawValue;
                    } elseif (is_string($rawValue)) {
                        $lower = strtolower($rawValue);
                        if (in_array($lower, ['true', '1', 'on', 'yes'])) {
                            $value = true;
                        } elseif (in_array($lower, ['false', '0', 'off', 'no'])) {
                            $value = false;
                        } else {
                            $errors[] = "$apiKey: Value must be true/false";
                            continue;
                        }
                    } else {
                        $value = (bool) $rawValue;
                    }
                } elseif ($configKey === 'name') {
                    if (empty(trim((string) $rawValue))) {
                        $errors[] = "name: Display name cannot be empty";
                        continue;
                    }
                    $value = trim((string) $rawValue);
                } else {
                    $value = $rawValue;
                }
            }

            // Handle hostname special case (derive hostname_secure)
            $hostnameSecure = null;
            if ($configKey === 'hostname') {
                if (strpos($value, 'http://') === 0) {
                    $hostnameSecure = 'https://' . substr($value, 7);
                } elseif (strpos($value, 'https://') === 0) {
                    $hostnameSecure = $value;
                    $value = 'http://' . substr($value, 8);
                } else {
                    $hostnameSecure = 'https://' . $value;
                    $value = 'http://' . $value;
                }
            }

            // Write to config file
            $configPath = '/etc/eiou/config/' . $configFile;
            $configContent = json_decode(file_get_contents($configPath), true) ?? [];
            $configContent[$configKey] = $value;
            if ($hostnameSecure !== null) {
                $configContent['hostname_secure'] = $hostnameSecure;
            }
            file_put_contents($configPath, json_encode($configContent, true), LOCK_EX);

            // Regenerate SSL certificate when hostname changes
            if ($configKey === 'hostname') {
                $this->regenerateSslForHostname($value);
            }

            $updated[$apiKey] = $value;
            if ($hostnameSecure !== null) {
                $updated['hostname_secure'] = $hostnameSecure;
            }
        }

        if (!empty($errors) && empty($updated)) {
            return $this->errorResponse(implode('; ', $errors), 400, 'validation_error');
        }

        $response = [
            'message' => 'Settings updated successfully',
            'updated' => $updated
        ];

        if (!empty($errors)) {
            $response['warnings'] = $errors;
        }

        return $this->successResponse($response);
    }

    /**
     * POST /api/v1/system/sync
     *
     * Trigger sync operation
     */
    private function triggerSync(string $body): array {
        if (!$this->hasPermission('admin')) {
            return $this->permissionDenied('admin');
        }

        try {
            $data = json_decode($body, true) ?? [];
            $type = $data['type'] ?? null;
            $syncService = $this->services->getSyncService();

            // Create a JSON-mode output manager to capture results
            $argv = ['eiou', 'sync', '--json'];
            CliOutputManager::resetInstance();
            $outputManager = new CliOutputManager($argv);

            ob_start();
            if ($type === 'contacts') {
                $syncService->syncAllContacts($outputManager);
            } elseif ($type === 'transactions') {
                $syncService->syncAllTransactions($outputManager);
            } elseif ($type === 'balances') {
                $syncService->syncAllBalances($outputManager);
            } else {
                $syncService->syncAll($outputManager);
            }
            $output = ob_get_clean();

            $cliResponse = $this->parseCliJsonResponse($output);

            return $this->successResponse([
                'message' => 'Sync completed',
                'type' => $type ?? 'all',
                'results' => $cliResponse['data'] ?? null
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Sync failed: ' . $e->getMessage(), 500, 'sync_error');
        }
    }

    /**
     * POST /api/v1/system/shutdown
     *
     * Shutdown processors (flag + signal only, API stays responsive)
     */
    private function shutdownProcessors(): array {
        if (!$this->hasPermission('admin')) {
            return $this->permissionDenied('admin');
        }

        try {
            // Set shutdown flag to prevent watchdog from restarting processors
            $shutdownFlag = '/tmp/eiou_shutdown.flag';
            file_put_contents($shutdownFlag, (string) time());

            $pidFiles = glob('/tmp/*.pid') ?: [];
            $processesTerminated = 0;
            $pidFilesCleaned = 0;

            foreach ($pidFiles as $item) {
                if (is_file($item)) {
                    $pid = trim(file_get_contents($item));
                    if (is_numeric($pid) && function_exists('posix_kill') && posix_kill((int) $pid, SIGTERM)) {
                        $processesTerminated++;
                    }
                    unlink($item);
                    $pidFilesCleaned++;
                }
            }

            return $this->successResponse([
                'message' => 'Processors shutdown initiated',
                'processes_terminated' => $processesTerminated,
                'pid_files_cleaned' => $pidFilesCleaned
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Shutdown failed: ' . $e->getMessage(), 500, 'shutdown_error');
        }
    }

    /**
     * POST /api/v1/system/start
     *
     * Start processors by removing shutdown flag
     */
    private function startProcessors(): array {
        if (!$this->hasPermission('admin')) {
            return $this->permissionDenied('admin');
        }

        try {
            $shutdownFlag = '/tmp/eiou_shutdown.flag';
            $wasShutdown = file_exists($shutdownFlag);

            if (!$wasShutdown) {
                return $this->successResponse([
                    'message' => 'Processors are already running',
                    'shutdown_flag_removed' => false,
                    'action' => 'none'
                ]);
            }

            unlink($shutdownFlag);

            return $this->successResponse([
                'message' => 'Processor restart initiated',
                'shutdown_flag_removed' => true,
                'action' => 'watchdog_will_restart'
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Start failed: ' . $e->getMessage(), 500, 'start_error');
        }
    }

    // ==================== Backup Endpoints ====================

    /**
     * GET /api/v1/backup/status
     */
    private function getBackupStatus(): array {
        if (!$this->hasPermission('backup:read') && !$this->hasPermission('admin')) {
            return $this->permissionDenied('backup:read');
        }

        try {
            $backupService = $this->services->getBackupService();
            $status = $backupService->getBackupStatus();

            return $this->successResponse([
                'enabled' => $status['enabled'],
                'backup_count' => $status['backup_count'],
                'retention_count' => $status['retention_count'],
                'last_backup' => $status['last_backup'],
                'last_backup_file' => $status['last_backup_file'],
                'backup_directory' => $status['backup_directory'],
                'next_scheduled' => $status['next_scheduled']
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get backup status: ' . $e->getMessage(), 500, 'backup_error');
        }
    }

    /**
     * GET /api/v1/backup/list
     */
    private function listBackups(): array {
        if (!$this->hasPermission('backup:read') && !$this->hasPermission('admin')) {
            return $this->permissionDenied('backup:read');
        }

        try {
            $backupService = $this->services->getBackupService();
            $backups = $backupService->listBackups();

            return $this->successResponse([
                'backups' => $backups,
                'count' => count($backups)
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to list backups: ' . $e->getMessage(), 500, 'backup_error');
        }
    }

    /**
     * POST /api/v1/backup/create
     */
    private function createBackup(string $body): array {
        if (!$this->hasPermission('backup:write') && !$this->hasPermission('admin')) {
            return $this->permissionDenied('backup:write');
        }

        try {
            $data = json_decode($body, true) ?? [];
            $customName = $data['name'] ?? null;

            $backupService = $this->services->getBackupService();
            $result = $backupService->createBackup($customName);

            if ($result['success']) {
                return $this->successResponse([
                    'message' => 'Backup created successfully',
                    'filename' => $result['filename'],
                    'size' => $result['size'],
                    'path' => $result['path']
                ], 201);
            } else {
                return $this->errorResponse($result['error'], 400, 'backup_failed');
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create backup: ' . $e->getMessage(), 500, 'backup_error');
        }
    }

    /**
     * POST /api/v1/backup/restore
     */
    private function restoreBackup(string $body): array {
        if (!$this->hasPermission('backup:write') && !$this->hasPermission('admin')) {
            return $this->permissionDenied('backup:write');
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['filename'])) {
            return $this->errorResponse('Missing required field: filename', 400, 'missing_field');
        }

        if (empty($data['confirm']) || $data['confirm'] !== true) {
            return $this->errorResponse(
                'Must set confirm: true to restore backup. This will overwrite all current database data!',
                400,
                'confirmation_required'
            );
        }

        try {
            $backupService = $this->services->getBackupService();
            $result = $backupService->restoreBackup($data['filename'], true);

            if ($result['success']) {
                return $this->successResponse([
                    'message' => 'Backup restored successfully',
                    'filename' => $result['filename'],
                    'restored_at' => $result['restored_at']
                ]);
            } else {
                return $this->errorResponse($result['error'], 400, 'restore_failed');
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to restore backup: ' . $e->getMessage(), 500, 'backup_error');
        }
    }

    /**
     * POST /api/v1/backup/verify
     */
    private function verifyBackup(string $body): array {
        if (!$this->hasPermission('backup:read') && !$this->hasPermission('admin')) {
            return $this->permissionDenied('backup:read');
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['filename'])) {
            return $this->errorResponse('Missing required field: filename', 400, 'missing_field');
        }

        try {
            $backupService = $this->services->getBackupService();
            $result = $backupService->verifyBackup($data['filename']);

            if ($result['success']) {
                return $this->successResponse([
                    'filename' => $data['filename'],
                    'valid' => $result['valid'],
                    'version' => $result['version'],
                    'created_at' => $result['created_at']
                ]);
            } else {
                return $this->errorResponse($result['error'], 400, 'verify_failed');
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to verify backup: ' . $e->getMessage(), 500, 'backup_error');
        }
    }

    /**
     * DELETE /api/v1/backup/:filename
     */
    private function deleteBackup(string $filename): array {
        if (!$this->hasPermission('backup:write') && !$this->hasPermission('admin')) {
            return $this->permissionDenied('backup:write');
        }

        $filename = urldecode($filename);

        try {
            $backupService = $this->services->getBackupService();
            $result = $backupService->deleteBackup($filename);

            if ($result['success']) {
                return $this->successResponse([
                    'message' => 'Backup deleted successfully',
                    'filename' => $filename
                ]);
            } else {
                return $this->errorResponse($result['error'], 404, 'backup_not_found');
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete backup: ' . $e->getMessage(), 500, 'backup_error');
        }
    }

    /**
     * POST /api/v1/backup/enable
     */
    private function enableAutoBackup(): array {
        if (!$this->hasPermission('backup:write') && !$this->hasPermission('admin')) {
            return $this->permissionDenied('backup:write');
        }

        try {
            $backupService = $this->services->getBackupService();
            $result = $backupService->setAutoBackupEnabled(true);

            if ($result['success']) {
                return $this->successResponse([
                    'message' => 'Automatic backups enabled',
                    'enabled' => true
                ]);
            } else {
                return $this->errorResponse($result['error'], 400, 'enable_failed');
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to enable auto backup: ' . $e->getMessage(), 500, 'backup_error');
        }
    }

    /**
     * POST /api/v1/backup/disable
     */
    private function disableAutoBackup(): array {
        if (!$this->hasPermission('backup:write') && !$this->hasPermission('admin')) {
            return $this->permissionDenied('backup:write');
        }

        try {
            $backupService = $this->services->getBackupService();
            $result = $backupService->setAutoBackupEnabled(false);

            if ($result['success']) {
                return $this->successResponse([
                    'message' => 'Automatic backups disabled',
                    'enabled' => false
                ]);
            } else {
                return $this->errorResponse($result['error'], 400, 'disable_failed');
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to disable auto backup: ' . $e->getMessage(), 500, 'backup_error');
        }
    }

    /**
     * POST /api/v1/backup/cleanup
     */
    private function cleanupBackups(): array {
        if (!$this->hasPermission('backup:write') && !$this->hasPermission('admin')) {
            return $this->permissionDenied('backup:write');
        }

        try {
            $backupService = $this->services->getBackupService();
            $result = $backupService->cleanupOldBackups();

            return $this->successResponse([
                'message' => 'Backup cleanup completed',
                'deleted_count' => $result['deleted_count'],
                'deleted_files' => $result['deleted_files']
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to cleanup backups: ' . $e->getMessage(), 500, 'backup_error');
        }
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

    /**
     * POST /api/v1/keys/enable/:key_id
     */
    private function enableApiKey(string $keyId): array {
        $enabled = $this->apiKeyRepository->enableKey($keyId);

        if (!$enabled) {
            return $this->errorResponse('API key not found', 404, 'key_not_found');
        }

        return $this->successResponse(['message' => 'API key enabled successfully', 'key_id' => $keyId]);
    }

    /**
     * POST /api/v1/keys/disable/:key_id
     */
    private function disableApiKey(string $keyId): array {
        $disabled = $this->apiKeyRepository->disableKey($keyId);

        if (!$disabled) {
            return $this->errorResponse('API key not found', 404, 'key_not_found');
        }

        return $this->successResponse(['message' => 'API key disabled successfully', 'key_id' => $keyId]);
    }

    // ==================== Chain Drop Endpoints ====================

    /**
     * Handle chain drop endpoints
     *
     * Routes:
     * - POST /api/v1/chaindrop/propose  - Propose chain drop
     * - POST /api/v1/chaindrop/accept   - Accept chain drop proposal
     * - POST /api/v1/chaindrop/reject   - Reject chain drop proposal
     * - GET  /api/v1/chaindrop          - List chain drop proposals
     */
    private function handleChainDrop(string $method, ?string $action, array $params, string $body): array {
        return match (true) {
            $method === 'GET' && !$action => $this->listChainDrops($params),
            $method === 'POST' && $action === 'propose' => $this->proposeChainDrop($body),
            $method === 'POST' && $action === 'accept' => $this->acceptChainDrop($body),
            $method === 'POST' && $action === 'reject' => $this->rejectChainDrop($body),
            default => $this->errorResponse('Unknown chaindrop action', 404, 'unknown_action')
        };
    }

    /**
     * GET /api/v1/chaindrop
     */
    private function listChainDrops(array $params): array {
        if (!$this->hasPermission('wallet:read')) {
            return $this->permissionDenied('wallet:read');
        }

        try {
            $chainDropService = $this->services->getChainDropService();
            $contactFilter = $params['contact'] ?? null;

            if ($contactFilter) {
                $contactRepo = $this->services->getContactRepository();
                $addressRepo = $this->services->getAddressRepository();

                // Resolve contact by name or address
                $contact = $contactRepo->lookupByName($contactFilter);
                if (!$contact) {
                    $addressTypes = $addressRepo->getAllAddressTypes();
                    foreach ($addressTypes as $transportIndex) {
                        $contact = $contactRepo->lookupByAddress($transportIndex, $contactFilter);
                        if ($contact) {
                            break;
                        }
                    }
                }

                if (!$contact) {
                    return $this->errorResponse('Contact not found', 404, 'contact_not_found');
                }

                $proposals = $chainDropService->getProposalsForContact($contact['pubkey_hash']);
            } else {
                $proposals = $chainDropService->getIncomingPendingProposals();
            }

            return $this->successResponse([
                'proposals' => $proposals,
                'count' => count($proposals)
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to list chain drop proposals: ' . $e->getMessage(), 500, 'chaindrop_error');
        }
    }

    /**
     * POST /api/v1/chaindrop/propose
     */
    private function proposeChainDrop(string $body): array {
        if (!$this->hasPermission('wallet:send')) {
            return $this->permissionDenied('wallet:send');
        }

        $data = json_decode($body, true);
        if (!$data) {
            return $this->errorResponse('Invalid JSON body', 400, 'invalid_json');
        }

        $contactAddress = $data['contact'] ?? $data['address'] ?? null;
        if (!$contactAddress) {
            return $this->errorResponse('Missing required field: contact', 400, 'missing_field');
        }

        try {
            $contactRepo = $this->services->getContactRepository();
            $addressRepo = $this->services->getAddressRepository();

            // Resolve contact to pubkey_hash
            $contact = $contactRepo->lookupByName($contactAddress);
            if (!$contact) {
                $addressTypes = $addressRepo->getAllAddressTypes();
                foreach ($addressTypes as $transportIndex) {
                    $contact = $contactRepo->lookupByAddress($transportIndex, $contactAddress);
                    if ($contact) {
                        break;
                    }
                }
            }

            if (!$contact) {
                return $this->errorResponse('Contact not found', 404, 'contact_not_found');
            }

            $chainDropService = $this->services->getChainDropService();
            $result = $chainDropService->proposeChainDrop($contact['pubkey_hash']);

            if ($result['success']) {
                return $this->successResponse([
                    'message' => 'Chain drop proposed successfully',
                    'proposal_id' => $result['proposal_id'],
                    'missing_txid' => $result['missing_txid'] ?? null,
                    'broken_txid' => $result['broken_txid'] ?? null
                ], 201);
            } else {
                return $this->errorResponse($result['error'] ?? 'Failed to propose chain drop', 400, 'chaindrop_failed');
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to propose chain drop: ' . $e->getMessage(), 500, 'chaindrop_error');
        }
    }

    /**
     * POST /api/v1/chaindrop/accept
     */
    private function acceptChainDrop(string $body): array {
        if (!$this->hasPermission('wallet:send')) {
            return $this->permissionDenied('wallet:send');
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['proposal_id'])) {
            return $this->errorResponse('Missing required field: proposal_id', 400, 'missing_field');
        }

        try {
            $chainDropService = $this->services->getChainDropService();
            $result = $chainDropService->acceptProposal($data['proposal_id']);

            if ($result['success']) {
                return $this->successResponse([
                    'message' => 'Chain drop proposal accepted',
                    'proposal_id' => $data['proposal_id']
                ]);
            } else {
                return $this->errorResponse($result['error'] ?? 'Failed to accept proposal', 400, 'chaindrop_failed');
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to accept chain drop: ' . $e->getMessage(), 500, 'chaindrop_error');
        }
    }

    /**
     * POST /api/v1/chaindrop/reject
     */
    private function rejectChainDrop(string $body): array {
        if (!$this->hasPermission('wallet:send')) {
            return $this->permissionDenied('wallet:send');
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['proposal_id'])) {
            return $this->errorResponse('Missing required field: proposal_id', 400, 'missing_field');
        }

        try {
            $chainDropService = $this->services->getChainDropService();
            $result = $chainDropService->rejectProposal($data['proposal_id']);

            if ($result['success']) {
                return $this->successResponse([
                    'message' => 'Chain drop proposal rejected',
                    'proposal_id' => $data['proposal_id']
                ]);
            } else {
                return $this->errorResponse($result['error'] ?? 'Failed to reject proposal', 400, 'chaindrop_failed');
            }
        } catch (Exception $e) {
            return $this->errorResponse('Failed to reject chain drop: ' . $e->getMessage(), 500, 'chaindrop_error');
        }
    }

    // ==================== Helper Methods ====================

    /**
     * Regenerate SSL certificate for a new hostname
     */
    private function regenerateSslForHostname(string $newHostname): void {
        $sslCertPath = '/etc/apache2/ssl/server.crt';
        $sslKeyPath = '/etc/apache2/ssl/server.key';

        // Check if we're using externally provided certificates (don't regenerate those)
        if (file_exists('/ssl-certs/server.crt')) {
            return;
        }

        // Remove existing certificate to trigger regeneration
        if (file_exists($sslCertPath)) {
            unlink($sslCertPath);
        }
        if (file_exists($sslKeyPath)) {
            unlink($sslKeyPath);
        }

        // Extract domain from hostname URL
        $domain = preg_replace('#^https?://#', '', $newHostname);
        $domain = rtrim($domain, '/');

        $sanList = "DNS:localhost,DNS:{$domain}";

        $containerHostname = gethostname();
        if ($containerHostname && $containerHostname !== $domain) {
            $sanList .= ",DNS:{$containerHostname}";
        }

        // Add IP addresses
        $sanList .= ",IP:127.0.0.1";
        $containerIp = gethostbyname($containerHostname ?: 'localhost');
        if ($containerIp && $containerIp !== '127.0.0.1' && $containerIp !== $containerHostname) {
            $sanList .= ",IP:{$containerIp}";
        }

        $opensslConf = tempnam('/tmp', 'ssl_');
        file_put_contents($opensslConf, "[req]\ndefault_bits=2048\nprompt=no\ndefault_md=sha256\nx509_extensions=v3_ca\ndistinguished_name=dn\n[dn]\nCN={$domain}\n[v3_ca]\nsubjectAltName={$sanList}\n");

        $sslDir = dirname($sslCertPath);
        if (!is_dir($sslDir)) {
            mkdir($sslDir, 0755, true);
        }

        exec("openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout {$sslKeyPath} -out {$sslCertPath} -config {$opensslConf} 2>/dev/null");

        if (file_exists($opensslConf)) {
            unlink($opensslConf);
        }
    }

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
