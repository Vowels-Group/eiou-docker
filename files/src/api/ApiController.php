<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Api;

use Exception;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Cli\CliOutputManager;
use Eiou\Services\AnalyticsService;
use Eiou\Services\ServiceContainer;
use Eiou\Services\ApiAuthService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Database\AddressRepository;
use Eiou\Database\ApiKeyRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionStatisticsRepository;
use Eiou\Utils\Logger;
use Eiou\Exceptions\ServiceException;
use Eiou\Processors\AbstractMessageProcessor;
use Eiou\Utils\InputValidator;
use Eiou\Services\ApiKeyService;

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
 * - POST /api/v1/system/update-check          - Trigger manual update check
 * - POST /api/v1/system/sync                 - Trigger sync operation
 * - POST /api/v1/system/shutdown             - Shutdown processors
 * - POST /api/v1/system/start               - Start processors
 * - GET  /api/v1/system/debug-report        - Download debug report (JSON)
 * - POST /api/v1/system/debug-report        - Submit debug report to support
 *
 * - POST /api/v1/chaindrop/propose           - Propose tx drop
 * - POST /api/v1/chaindrop/accept            - Accept tx drop proposal
 * - POST /api/v1/chaindrop/reject            - Reject tx drop proposal
 * - GET  /api/v1/chaindrop                   - List tx drop proposals
 *
 * - GET  /api/v1/p2p                         - List P2P transactions awaiting approval
 * - GET  /api/v1/p2p/candidates/:hash       - Get route candidates for a P2P transaction
 * - POST /api/v1/p2p/approve                - Approve a P2P transaction
 * - POST /api/v1/p2p/reject                 - Reject a P2P transaction
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
                'p2p' => $this->handleP2p($method, $action, $id, $params, $body),
                'backup' => $this->handleBackup($method, $action, $params, $body),
                'requests' => $this->handleRequests($method, $action, $id, $params, $body),
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
            $method === 'POST' && $action === 'update-check' => $this->triggerUpdateCheck(),
            $method === 'POST' && $action === 'sync' => $this->triggerSync($body),
            $method === 'POST' && $action === 'shutdown' => $this->shutdownProcessors(),
            $method === 'POST' && $action === 'start' => $this->startProcessors(),
            $method === 'GET' && $action === 'debug-report' => $this->getDebugReport($params),
            $method === 'POST' && $action === 'debug-report' => $this->submitDebugReport($body),
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

        $balanceRepo = $this->services->getRepositoryFactory()->get(BalanceRepository::class);
        $contactRepo = $this->services->getRepositoryFactory()->get(ContactRepository::class);

        $balances = $balanceRepo->getAllBalances();
        $result = [];

        foreach ($balances as $balance) {
            $contact = $contactRepo->lookupByPubkeyHash($balance['pubkey_hash']);
            $result[] = [
                'contact_name' => $contact['name'] ?? 'Unknown',
                'address' => $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? null,
                'currency' => $balance['currency'],
                'received' => (new \Eiou\Core\SplitAmount((int)($balance['received_whole'] ?? 0), (int)($balance['received_frac'] ?? 0)))->toMajorUnits(),
                'sent' => (new \Eiou\Core\SplitAmount((int)($balance['sent_whole'] ?? 0), (int)($balance['sent_frac'] ?? 0)))->toMajorUnits(),
                'net_balance' => (new \Eiou\Core\SplitAmount((int)($balance['received_whole'] ?? 0), (int)($balance['received_frac'] ?? 0)))
                    ->subtract(new \Eiou\Core\SplitAmount((int)($balance['sent_whole'] ?? 0), (int)($balance['sent_frac'] ?? 0)))->toMajorUnits()
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

        // Validate recipient (address or contact name)
        $addressValidation = InputValidator::validateAddress($data['address']);
        $nameValidation = InputValidator::validateContactName($data['address']);
        if (!$addressValidation['valid'] && !$nameValidation['valid']) {
            return $this->errorResponse('Invalid recipient: ' . $addressValidation['error'], 400, 'invalid_address');
        }

        // Prevent self-send
        if ($addressValidation['valid']) {
            $selfSendValidation = InputValidator::validateNotSelfSend($data['address'], $this->services->getCurrentUser());
            if (!$selfSendValidation['valid']) {
                return $this->errorResponse($selfSendValidation['error'], 400, 'self_send');
            }
        }

        // Validate currency
        $currencyValidation = InputValidator::validateCurrency($data['currency']);
        if (!$currencyValidation['valid']) {
            return $this->errorResponse($currencyValidation['error'], 400, 'invalid_currency');
        }
        $data['currency'] = $currencyValidation['value'];

        // Validate amount (currency-aware: checks minimum unit, e.g. 0.01 for USD)
        $amountValidation = InputValidator::validateAmount($data['amount'], $data['currency']);
        if (!$amountValidation['valid']) {
            return $this->errorResponse($amountValidation['error'], 400, 'invalid_amount');
        }
        $data['amount'] = $amountValidation['value'];

        // Validate description if provided
        if (!empty($data['description'])) {
            $descValidation = InputValidator::validateMemo($data['description']);
            if (!$descValidation['valid']) {
                return $this->errorResponse($descValidation['error'], 400, 'invalid_description');
            }
            $data['description'] = $descValidation['value'];
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
                $data['description'] ?? null, // $request[5] - optional description
                '--json'                    // Enable JSON output mode
            ];

            if (!empty($data['best_fee'])) {
                $argv[] = '--best';
            }

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

        $transactionRepo = $this->services->getRepositoryFactory()->get(TransactionRepository::class);
        $transactionStatsRepo = $this->services->getRepositoryFactory()->get(TransactionStatisticsRepository::class);

        // If contact filter provided, resolve to addresses and filter
        if ($contactFilter) {
            $contactRepo = $this->services->getRepositoryFactory()->get(ContactRepository::class);
            $addressRepo = $this->services->getRepositoryFactory()->get(AddressRepository::class);

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
                'amount' => (new \Eiou\Core\SplitAmount((int)($tx['amount_whole'] ?? 0), (int)($tx['amount_frac'] ?? 0)))->toMajorUnits(),
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
        $addressRepo = $this->services->getRepositoryFactory()->get(AddressRepository::class);

        // Use getAllAddressTypes() to dynamically get all address types
        $addressTypes = $addressRepo->getAllAddressTypes();
        $addresses = [];
        foreach ($addressTypes as $type) {
            $addresses[$type] = $userAddresses[$type] ?? null;
        }

        // Fee earnings from P2P relay transactions
        $p2pRepo = $this->services->getRepositoryFactory()->get(P2pRepository::class);
        $feeEarnings = [];
        $earningsRows = $p2pRepo->getUserTotalEarningsByCurrency();
        foreach ($earningsRows as $row) {
            $feeEarnings[] = [
                'currency' => $row['currency'],
                'total_amount' => ($row['total_amount'] instanceof \Eiou\Core\SplitAmount) ? $row['total_amount']->toMajorUnits() : 0
            ];
        }

        // Total available credit from all contacts
        $creditRepo = $this->services->getRepositoryFactory()->get(ContactCreditRepository::class);
        $availableCredit = [];
        $creditRows = $creditRepo->getTotalAvailableCreditByCurrency();
        foreach ($creditRows as $row) {
            $availableCredit[] = [
                'currency' => $row['currency'],
                'total_available_credit' => ($row['total_available_credit'] instanceof \Eiou\Core\SplitAmount) ? $row['total_available_credit']->toMajorUnits() : 0
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

        $balanceRepo = $this->services->getRepositoryFactory()->get(BalanceRepository::class);
        $transactionRepo = $this->services->getRepositoryFactory()->get(TransactionRepository::class);

        // Get transaction limit from params (default 5, max 20 for overview)
        $transactionLimit = min((int) ($params['transaction_limit'] ?? 5), 20);

        // Get overall balances by currency
        $balances = $balanceRepo->getUserBalance();
        $balanceResult = [];

        if ($balances) {
            foreach ($balances as $balance) {
                $balanceResult[] = [
                    'currency' => $balance['currency'],
                    'total_balance' => ($balance['total_balance'] instanceof \Eiou\Core\SplitAmount) ? $balance['total_balance']->toMajorUnits() : 0
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
                'amount' => (new \Eiou\Core\SplitAmount((int)($tx['amount_whole'] ?? 0), (int)($tx['amount_frac'] ?? 0)))->toMajorUnits(),
                'currency' => $tx['currency'] ?? null,
                'counterparty_name' => $tx['counterparty_name'] ?? null,
                'sender_address' => $tx['sender_address'] ?? null,
                'receiver_address' => $tx['receiver_address'] ?? null,
                'memo' => $tx['memo'] ?? null,
                'timestamp' => $tx['timestamp'] ?? $tx['date'] ?? null
            ];
        }

        // Get total available credit per currency
        $creditRepo = $this->services->getRepositoryFactory()->get(ContactCreditRepository::class);
        $totalAvailableCredit = [];
        $creditRows = $creditRepo->getTotalAvailableCreditByCurrency();
        foreach ($creditRows as $row) {
            $totalAvailableCredit[] = [
                'currency' => $row['currency'],
                'total_available_credit' => ($row['total_available_credit'] instanceof \Eiou\Core\SplitAmount) ? $row['total_available_credit']->toMajorUnits() : 0
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
        $contactRepo = $this->services->getRepositoryFactory()->get(ContactRepository::class);
        $addressRepo = $this->services->getRepositoryFactory()->get(AddressRepository::class);

        // Get all address types dynamically for future-proofing
        $addressTypes = $addressRepo->getAllAddressTypes();

        $contacts = $contactRepo->getContactsByStatus($status);
        $creditRepo = $this->services->getRepositoryFactory()->get(ContactCreditRepository::class);
        $balanceRepo = $this->services->getRepositoryFactory()->get(BalanceRepository::class);
        $result = [];

        foreach ($contacts as $contact) {
            $contactAddresses = $addressRepo->lookupByPubkeyHash($contact['pubkey_hash']);

            // Build addresses array dynamically based on available address types
            $addresses = [];
            foreach ($addressTypes as $type) {
                $addresses[$type] = $contactAddresses[$type] ?? null;
            }

            // Get per-currency config
            $contactCurrencyRepo = $this->services->getRepositoryFactory()->get(ContactCurrencyRepository::class);
            $currencies = $contactCurrencyRepo->getContactCurrencies($contact['pubkey_hash']);

            // My available credit with them (from contact_credit table, received via pong)
            $myAvailableCredit = null;
            $creditData = $creditRepo->getAvailableCredit($contact['pubkey_hash']);
            if ($creditData !== null) {
                $creditCurrency = $creditData['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                $myAvailableCredit = ($creditData['available_credit'] instanceof \Eiou\Core\SplitAmount) ? $creditData['available_credit']->toMajorUnits() : 0;
            }

            $result[] = [
                'name' => $contact['name'],
                'pubkey_hash' => $contact['pubkey_hash'],
                'status' => $contact['status'],
                'currencies' => array_map(function ($c) {
                    return [
                        'currency' => $c['currency'],
                        'fee_percent' => $c['fee_percent'] / Constants::FEE_CONVERSION_FACTOR,
                        'credit_limit' => ($c['credit_limit'] instanceof \Eiou\Core\SplitAmount) ? $c['credit_limit']->toMajorUnits() : 0,
                        'status' => $c['status'] ?? null,
                        'direction' => $c['direction'] ?? null,
                    ];
                }, $currencies),
                'my_available_credit' => $myAvailableCredit,
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

        $contactRepo = $this->services->getRepositoryFactory()->get(ContactRepository::class);
        $addressRepo = $this->services->getRepositoryFactory()->get(AddressRepository::class);
        $contactCurrencyRepo = $this->services->getRepositoryFactory()->get(ContactCurrencyRepository::class);

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

            $entry = [
                'pubkey_hash' => $contact['pubkey_hash'] ?? null,
                'status' => $contact['status'] ?? null,
                'addresses' => $addresses,
                'created_at' => $contact['created_at'] ?? null
            ];

            // Include pending currencies with requested credit limits
            if (!empty($contact['pubkey_hash'])) {
                $pendingCurrencies = $contactCurrencyRepo->getPendingCurrencies($contact['pubkey_hash'], 'incoming');
                if (!empty($pendingCurrencies)) {
                    $entry['currencies'] = array_map(function ($pc) {
                        $curr = [
                            'currency' => $pc['currency'],
                        ];
                        if ($pc['credit_limit'] !== null && $pc['credit_limit'] instanceof SplitAmount) {
                            $curr['requested_credit_limit'] = $pc['credit_limit']->toMajorUnits();
                        }
                        return $curr;
                    }, $pendingCurrencies);
                }
            }

            $incoming[] = $entry;
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
        $contactRepo = $this->services->getRepositoryFactory()->get(ContactRepository::class);
        $addressRepo = $this->services->getRepositoryFactory()->get(AddressRepository::class);
        $balanceRepo = $this->services->getRepositoryFactory()->get(BalanceRepository::class);
        $creditRepo = $this->services->getRepositoryFactory()->get(ContactCreditRepository::class);

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
                        $creditCurrency = $creditData['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                        $myAvailableCredit = ($creditData['available_credit'] instanceof \Eiou\Core\SplitAmount) ? $creditData['available_credit']->toMajorUnits() : 0;
                    }
                }

                $result[] = [
                    'name' => $contact['name'] ?? null,
                    'pubkey_hash' => $hash,
                    'status' => $contact['status'] ?? null,
                    'addresses' => $addresses,
                    'my_available_credit' => $myAvailableCredit,
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

        // Validate address
        $addressValidation = InputValidator::validateAddress($data['address']);
        if (!$addressValidation['valid']) {
            return $this->errorResponse($addressValidation['error'], 400, 'invalid_address');
        }

        // Validate contact name
        $nameValidation = InputValidator::validateContactName($data['name']);
        if (!$nameValidation['valid']) {
            return $this->errorResponse($nameValidation['error'], 400, 'invalid_name');
        }

        // Validate currency (needed before credit limit validation)
        $currency = $data['currency'] ?? 'USD';
        $currencyValidation = InputValidator::validateCurrency($currency);
        if (!$currencyValidation['valid']) {
            return $this->errorResponse($currencyValidation['error'], 400, 'invalid_currency');
        }
        $currency = $currencyValidation['value'];

        // Validate fee percentage
        $feeValidation = InputValidator::validateFeePercent($data['fee_percent'] ?? 1);
        if (!$feeValidation['valid']) {
            return $this->errorResponse($feeValidation['error'], 400, 'invalid_fee');
        }

        // Validate credit limit
        $creditValidation = InputValidator::validateCreditLimit($data['credit_limit'] ?? 100, $currency);
        if (!$creditValidation['valid']) {
            return $this->errorResponse($creditValidation['error'], 400, 'invalid_credit');
        }

        // Validate requested credit limit if provided (what sender wants receiver to set)
        $requestedCreditValidated = null;
        if (isset($data['requested_credit_limit']) && $data['requested_credit_limit'] !== '' && $data['requested_credit_limit'] !== null) {
            $reqCreditValidation = InputValidator::validateCreditLimit($data['requested_credit_limit'], $currency);
            if (!$reqCreditValidation['valid']) {
                return $this->errorResponse($reqCreditValidation['error'], 400, 'invalid_requested_credit');
            }
            $requestedCreditValidated = (string) $reqCreditValidation['value'];
        }

        // Validate description if provided
        if (!empty($data['description'])) {
            $descValidation = InputValidator::validateMemo($data['description']);
            if (!$descValidation['valid']) {
                return $this->errorResponse($descValidation['error'], 400, 'invalid_description');
            }
            $data['description'] = $descValidation['value'];
        }

        try {
            $contactService = $this->services->getContactService();

            // Build argv-style array with --json flag for JSON output
            $argv = [
                'eiou',                                      // $data[0] - command name
                'add',                                       // $data[1] - subcommand
                $addressValidation['value'],                 // $data[2] - contact address
                $nameValidation['value'],                    // $data[3] - contact name
                (string) $feeValidation['value'],            // $data[4] - fee percent
                (string) $creditValidation['value'],         // $data[5] - credit limit
                $currency,                                   // $data[6] - currency
            ];
            // $data[7] = requested credit limit (or NULL placeholder), $data[8] = description
            if ($requestedCreditValidated !== null || !empty($data['description'])) {
                $argv[] = $requestedCreditValidated ?? 'NULL';   // $data[7] - requested credit limit or NULL
            }
            if (!empty($data['description'])) {
                $argv[] = $data['description'];                  // $data[8] - description
            }
            $argv[] = '--json';                          // Enable JSON output mode

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
        $contactRepo = $this->services->getRepositoryFactory()->get(ContactRepository::class);
        $addressRepo = $this->services->getRepositoryFactory()->get(AddressRepository::class);
        $balanceRepo = $this->services->getRepositoryFactory()->get(BalanceRepository::class);

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
        $creditRepo = $this->services->getRepositoryFactory()->get(ContactCreditRepository::class);
        $myAvailableCredit = null;
        $creditData = $creditRepo->getAvailableCredit($contact['pubkey_hash']);
        if ($creditData !== null) {
            $creditCurrency = $creditData['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
            $myAvailableCredit = ($creditData['available_credit'] instanceof \Eiou\Core\SplitAmount) ? $creditData['available_credit']->toMajorUnits() : 0;
        }

        // Fetch all currencies for this contact from contact_currencies table
        $currencyConfigs = [];
        try {
            $contactCurrencyRepo = $this->services->getRepositoryFactory()->get(ContactCurrencyRepository::class);
            $currencyConfigs = $contactCurrencyRepo->getContactCurrencies($contact['pubkey_hash']);
        } catch (\Exception $e) {
            // Non-critical - continue without multi-currency data
        }

        // Balance per first available currency
        $balanceCurrency = !empty($currencyConfigs) ? $currencyConfigs[0]['currency'] : Constants::TRANSACTION_DEFAULT_CURRENCY;

        return $this->successResponse([
            'contact' => [
                'name' => $contact['name'],
                'pubkey_hash' => $contact['pubkey_hash'],
                'status' => $contact['status'],
                'my_available_credit' => $myAvailableCredit,
                'addresses' => $addresses,
                'balance' => $balance ? [
                    'received' => $balance['received']->toMajorUnits(),
                    'sent' => $balance['sent']->toMajorUnits(),
                    'net' => $balance['received']->subtract($balance['sent'])->toMajorUnits()
                ] : null,
                'currencies' => array_map(function ($c) {
                    return [
                        'currency' => $c['currency'],
                        'fee_percent' => $c['fee_percent'] / Constants::FEE_CONVERSION_FACTOR,
                        'credit_limit' => ($c['credit_limit'] instanceof \Eiou\Core\SplitAmount) ? $c['credit_limit']->toMajorUnits() : 0,
                        'status' => $c['status'] ?? null,
                        'direction' => $c['direction'] ?? null,
                    ];
                }, $currencyConfigs),
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
        $contactRepo = $this->services->getRepositoryFactory()->get(ContactRepository::class);
        $addressRepo = $this->services->getRepositoryFactory()->get(AddressRepository::class);

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
                $nameValidation = InputValidator::validateContactName($data['name']);
                if (!$nameValidation['valid']) {
                    return $this->errorResponse($nameValidation['error'], 400, 'validation_error');
                }
                $updateFields['name'] = $nameValidation['value'];
                $updatedData['name'] = $nameValidation['value'];
            }
            if (isset($data['fee_percent'])) {
                $updatedData['fee_percent'] = $data['fee_percent'];
            }
            if (isset($data['credit_limit'])) {
                $updatedData['credit_limit'] = $data['credit_limit'];
            }
            if (isset($data['currency'])) {
                $updatedData['currency'] = $data['currency'];
            }

            if (empty($updateFields) && !isset($data['fee_percent']) && !isset($data['credit_limit'])) {
                return $this->errorResponse('No fields to update', 400, 'no_fields');
            }

            // Require currency when updating fee or credit
            if ((isset($data['fee_percent']) || isset($data['credit_limit'])) && !isset($data['currency'])) {
                return $this->errorResponse('Currency is required when updating fee_percent or credit_limit', 400, 'missing_currency');
            }

            // Validate currency, fee_percent, and credit_limit when provided
            if (isset($data['currency'])) {
                $currencyValidation = InputValidator::validateCurrency($data['currency']);
                if (!$currencyValidation['valid']) {
                    return $this->errorResponse($currencyValidation['error'], 400, 'validation_error');
                }
                $data['currency'] = $currencyValidation['value'];
                $updatedData['currency'] = $currencyValidation['value'];
            }
            if (isset($data['fee_percent'])) {
                $feeValidation = InputValidator::validateFeePercent($data['fee_percent']);
                if (!$feeValidation['valid']) {
                    return $this->errorResponse($feeValidation['error'], 400, 'validation_error');
                }
                $data['fee_percent'] = $feeValidation['value'];
                $updatedData['fee_percent'] = $feeValidation['value'];
            }
            if (isset($data['credit_limit'])) {
                $currency = $data['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
                $creditValidation = InputValidator::validateCreditLimit($data['credit_limit'], $currency);
                if (!$creditValidation['valid']) {
                    return $this->errorResponse($creditValidation['error'], 400, 'validation_error');
                }
                $data['credit_limit'] = $creditValidation['value'];
                $updatedData['credit_limit'] = $creditValidation['value'];
            }

            // Update name in contacts table if provided
            $contactUpdateOk = true;
            if (!empty($updateFields)) {
                $contactUpdateOk = $contactRepo->updateContactFields($contact['pubkey'], $updateFields);
            }

            if ($contactUpdateOk) {
                // Update fee/credit in contact_currencies table
                if (isset($data['currency']) && (isset($data['fee_percent']) || isset($data['credit_limit']))) {
                    $contactCurrencyRepo = $this->services->getRepositoryFactory()->get(ContactCurrencyRepository::class);
                    $pubkeyHash = hash(Constants::HASH_ALGORITHM, $contact['pubkey']);
                    $currencyFields = [];
                    if (isset($data['fee_percent'])) {
                        $currencyFields['fee_percent'] = CurrencyUtilityService::exactMajorToMinor($data['fee_percent'], Constants::FEE_CONVERSION_FACTOR);
                    }
                    if (isset($data['credit_limit'])) {
                        $currencyFields['credit_limit'] = \Eiou\Core\SplitAmount::from($data['credit_limit']);
                    }
                    if (!empty($currencyFields)) {
                        $contactCurrencyRepo->updateCurrencyConfig($pubkeyHash, $data['currency'], $currencyFields);
                    }
                }
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

        $user = \Eiou\Core\UserContext::getInstance();
        $analyticsStatus = \Eiou\Services\AnalyticsService::getStatus();

        return $this->successResponse([
            'status' => 'operational',
            'version' => Constants::APP_VERSION ?? '1.0.0',
            'environment' => Constants::getAppEnv(),
            'database' => $dbStatus,
            'processors' => $processors,
            'update' => \Eiou\Services\UpdateCheckService::getStatus(),
            'analytics' => [
                'enabled' => $analyticsStatus['enabled'],
                'consent_pending' => !$user->getAnalyticsConsentAsked(),
                'last_submitted' => $analyticsStatus['last_submitted'],
                'opt_in_at' => $user->getAnalyticsOptInAt(),
            ],
            'timestamp' => date('c')
        ]);
    }

    /**
     * POST /api/v1/system/update-check
     *
     * Trigger a manual update check against Docker Hub / GitHub Releases.
     */
    private function triggerUpdateCheck(): array {
        if (!$this->hasPermission('system:read')) {
            return $this->permissionDenied('system:read');
        }

        $result = \Eiou\Services\UpdateCheckService::check(true);

        if ($result === null) {
            return $this->errorResponse('Update check failed', 502, 'update_check_failed');
        }

        return $this->successResponse($result);
    }

    /**
     * GET /api/v1/system/metrics
     */
    private function getSystemMetrics(): array {
        if (!$this->hasPermission('system:read')) {
            return $this->permissionDenied('system:read');
        }

        $transactionStatsRepo = $this->services->getRepositoryFactory()->get(TransactionStatisticsRepository::class);
        $contactRepo = $this->services->getRepositoryFactory()->get(ContactRepository::class);
        $p2pRepo = $this->services->getRepositoryFactory()->get(P2pRepository::class);

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
                'name' => $currentUser->getName(),
                'default_currency' => $currentUser->getDefaultCurrency(),
                'minimum_fee_amount' => $currentUser->getMinimumFee(),
                'default_fee_percent' => $currentUser->getDefaultFee(),
                'maximum_fee_percent' => $currentUser->getMaxFee(),
                'default_credit_limit' => $currentUser->getDefaultCreditLimit(),
                'max_p2p_level' => $currentUser->getMaxP2pLevel(),
                'p2p_expiration_seconds' => $currentUser->getP2pExpirationTime(),
                'direct_tx_expiration' => $currentUser->getDirectTxExpirationTime(),
                'max_output_lines' => $currentUser->getMaxOutput(),
                'default_transport_mode' => $currentUser->getDefaultTransportMode(),
                'hostname' => $currentUser->getHttpAddress(),
                'hostname_secure' => $currentUser->getHttpsAddress(),
                'trusted_proxies' => $currentUser->getTrustedProxies(),
                'auto_backup_enabled' => $currentUser->getAutoBackupEnabled(),
                'auto_accept_transaction' => $currentUser->getAutoAcceptTransaction(),
                // Feature toggles
                'hop_budget_randomized' => $currentUser->getHopBudgetRandomized(),
                'contact_status_enabled' => $currentUser->getContactStatusEnabled(),
                'contact_status_sync_on_ping' => $currentUser->getContactStatusSyncOnPing(),
                'auto_chain_drop_propose' => $currentUser->getAutoChainDropPropose(),
                'auto_chain_drop_accept' => $currentUser->getAutoChainDropAccept(),
                'auto_chain_drop_accept_guard' => $currentUser->getAutoChainDropAcceptGuard(),
                'auto_accept_restored_contact' => $currentUser->getAutoAcceptRestoredContact(),
                'api_enabled' => $currentUser->getApiEnabled(),
                'api_cors_allowed_origins' => $currentUser->getApiCorsAllowedOrigins(),
                'rate_limit_enabled' => $currentUser->getRateLimitEnabled(),
                // Backup & logging
                'backup_retention_count' => $currentUser->getBackupRetentionCount(),
                'backup_cron_hour' => $currentUser->getBackupCronHour(),
                'backup_cron_minute' => $currentUser->getBackupCronMinute(),
                'log_level' => $currentUser->getLogLevel(),
                'log_max_entries' => $currentUser->getLogMaxEntries(),
                // Data retention
                'cleanup_delivery_retention_days' => $currentUser->getCleanupDeliveryRetentionDays(),
                'cleanup_dlq_retention_days' => $currentUser->getCleanupDlqRetentionDays(),
                'cleanup_held_tx_retention_days' => $currentUser->getCleanupHeldTxRetentionDays(),
                'cleanup_rp2p_retention_days' => $currentUser->getCleanupRp2pRetentionDays(),
                'cleanup_metrics_retention_days' => $currentUser->getCleanupMetricsRetentionDays(),
                // Rate limiting
                'p2p_rate_limit_per_minute' => $currentUser->getP2pRateLimitPerMinute(),
                'rate_limit_max_attempts' => $currentUser->getRateLimitMaxAttempts(),
                'rate_limit_window_seconds' => $currentUser->getRateLimitWindowSeconds(),
                'rate_limit_block_seconds' => $currentUser->getRateLimitBlockSeconds(),
                // Network
                'http_transport_timeout_seconds' => $currentUser->getHttpTransportTimeoutSeconds(),
                'tor_transport_timeout_seconds' => $currentUser->getTorTransportTimeoutSeconds(),
                // Tor circuit health
                'tor_circuit_max_failures' => $currentUser->getTorCircuitMaxFailures(),
                'tor_circuit_cooldown_seconds' => $currentUser->getTorCircuitCooldownSeconds(),
                'tor_failure_transport_fallback' => $currentUser->isTorFailureTransportFallback(),
                'tor_fallback_require_encrypted' => $currentUser->isTorFallbackRequireEncrypted(),
                // Display
                'display_date_format' => $currentUser->getDisplayDateFormat(),
                // Currency management
                'allowed_currencies' => $currentUser->getAllowedCurrencies(),
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
            'min_fee' => ['key' => 'minFee', 'validate' => 'validateFeeAmount', 'config' => 'defaultconfig.json'],
            'max_fee' => ['key' => 'maxFee', 'validate' => 'validateFeePercent', 'config' => 'defaultconfig.json'],
            'max_p2p_level' => ['key' => 'maxP2pLevel', 'validate' => 'validateRequestLevel', 'config' => 'defaultconfig.json'],
            'p2p_expiration' => ['key' => 'p2pExpiration', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'max_output' => ['key' => 'maxOutput', 'validate' => null, 'config' => 'defaultconfig.json'],
            'default_transport_mode' => ['key' => 'defaultTransportMode', 'validate' => null, 'config' => 'defaultconfig.json'],
            'auto_backup_enabled' => ['key' => 'autoBackupEnabled', 'validate' => null, 'config' => 'defaultconfig.json'],
            'auto_accept_transaction' => ['key' => 'autoAcceptTransaction', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'direct_tx_expiration' => ['key' => 'directTxExpiration', 'validate' => null, 'config' => 'defaultconfig.json', 'intMin' => 0],
            'trusted_proxies' => ['key' => 'trustedProxies', 'validate' => 'validateTrustedProxies', 'config' => 'defaultconfig.json'],
            'hostname' => ['key' => 'hostname', 'validate' => 'validateHostname', 'config' => 'userconfig.json'],
            'name' => ['key' => 'name', 'validate' => null, 'config' => 'userconfig.json'],
            // Feature toggles
            'hop_budget_randomized' => ['key' => 'hopBudgetRandomized', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'contact_status_enabled' => ['key' => 'contactStatusEnabled', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'contact_status_sync_on_ping' => ['key' => 'contactStatusSyncOnPing', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'auto_chain_drop_propose' => ['key' => 'autoChainDropPropose', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'auto_chain_drop_accept' => ['key' => 'autoChainDropAccept', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'auto_chain_drop_accept_guard' => ['key' => 'autoChainDropAcceptGuard', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'auto_accept_restored_contact' => ['key' => 'autoAcceptRestoredContact', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'api_enabled' => ['key' => 'apiEnabled', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'api_cors_allowed_origins' => ['key' => 'apiCorsAllowedOrigins', 'validate' => null, 'config' => 'defaultconfig.json'],
            'allowed_currencies' => ['key' => 'allowedCurrencies', 'validate' => null, 'config' => 'defaultconfig.json'],
            'auto_reject_unknown_currency' => ['key' => 'autoRejectUnknownCurrency', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'analytics_enabled' => ['key' => 'analyticsEnabled', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'rate_limit_enabled' => ['key' => 'rateLimitEnabled', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            // Backup & logging
            'backup_retention_count' => ['key' => 'backupRetentionCount', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'backup_cron_hour' => ['key' => 'backupCronHour', 'validate' => null, 'config' => 'defaultconfig.json', 'intRange' => [0, 23]],
            'backup_cron_minute' => ['key' => 'backupCronMinute', 'validate' => null, 'config' => 'defaultconfig.json', 'intRange' => [0, 59]],
            'log_level' => ['key' => 'logLevel', 'validate' => 'validateLogLevel', 'config' => 'defaultconfig.json'],
            'log_max_entries' => ['key' => 'logMaxEntries', 'validate' => null, 'config' => 'defaultconfig.json', 'intMin' => 10],
            // Data retention
            'cleanup_delivery_retention_days' => ['key' => 'cleanupDeliveryRetentionDays', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'cleanup_dlq_retention_days' => ['key' => 'cleanupDlqRetentionDays', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'cleanup_held_tx_retention_days' => ['key' => 'cleanupHeldTxRetentionDays', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'cleanup_rp2p_retention_days' => ['key' => 'cleanupRp2pRetentionDays', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'cleanup_metrics_retention_days' => ['key' => 'cleanupMetricsRetentionDays', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'payment_requests_archive_retention_days' => ['key' => 'paymentRequestsArchiveRetentionDays', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            // Rate limiting
            'p2p_rate_limit_per_minute' => ['key' => 'p2pRateLimitPerMinute', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'rate_limit_max_attempts' => ['key' => 'rateLimitMaxAttempts', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'rate_limit_window_seconds' => ['key' => 'rateLimitWindowSeconds', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            'rate_limit_block_seconds' => ['key' => 'rateLimitBlockSeconds', 'validate' => 'validatePositiveInteger', 'config' => 'defaultconfig.json'],
            // Network
            'http_transport_timeout_seconds' => ['key' => 'httpTransportTimeoutSeconds', 'validate' => null, 'config' => 'defaultconfig.json', 'intRange' => [5, 120]],
            'tor_transport_timeout_seconds' => ['key' => 'torTransportTimeoutSeconds', 'validate' => null, 'config' => 'defaultconfig.json', 'intRange' => [10, 300]],
            // Tor circuit health
            'tor_circuit_max_failures' => ['key' => 'torCircuitMaxFailures', 'validate' => null, 'config' => 'defaultconfig.json', 'intRange' => [1, 10]],
            'tor_circuit_cooldown_seconds' => ['key' => 'torCircuitCooldownSeconds', 'validate' => null, 'config' => 'defaultconfig.json', 'intRange' => [60, 3600]],
            'tor_failure_transport_fallback' => ['key' => 'torFailureTransportFallback', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            'tor_fallback_require_encrypted' => ['key' => 'torFallbackRequireEncrypted', 'validate' => 'validateBoolean', 'config' => 'defaultconfig.json'],
            // Sync
            'sync_chunk_size' => ['key' => 'syncChunkSize', 'validate' => null, 'config' => 'defaultconfig.json', 'intRange' => [10, 500]],
            'sync_max_chunks' => ['key' => 'syncMaxChunks', 'validate' => null, 'config' => 'defaultconfig.json', 'intRange' => [10, 1000]],
            'held_tx_sync_timeout_seconds' => ['key' => 'heldTxSyncTimeoutSeconds', 'validate' => null, 'config' => 'defaultconfig.json', 'intRange' => [30, 299]],
            // Display
            'display_date_format' => ['key' => 'displayDateFormat', 'validate' => 'validateDateFormat', 'config' => 'defaultconfig.json'],
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
                    // Use p2pExpiration-specific minimum for that key, 1 for everything else
                    $minVal = ($configKey === 'p2pExpiration') ? Constants::P2P_MIN_EXPIRATION_SECONDS : 1;
                    $validation = InputValidator::$validateMethod($rawValue, $minVal);
                } else {
                    $validation = InputValidator::$validateMethod($rawValue);
                }
                if (!$validation['valid']) {
                    $errors[] = "$apiKey: " . $validation['error'];
                    continue;
                }
                $value = $validation['value'];
            } elseif (isset($mapping['intRange'])) {
                // Integer range validation shortcut
                $validation = InputValidator::validateIntRange($rawValue, $mapping['intRange'][0], $mapping['intRange'][1], $apiKey);
                if (!$validation['valid']) {
                    $errors[] = "$apiKey: " . $validation['error'];
                    continue;
                }
                $value = $validation['value'];
            } elseif (isset($mapping['intMin'])) {
                // Positive integer with custom minimum
                $validation = InputValidator::validatePositiveInteger($rawValue, $mapping['intMin']);
                if (!$validation['valid']) {
                    $errors[] = "$apiKey: " . $validation['error'];
                    continue;
                }
                $value = $validation['value'];
            } elseif (isset($mapping['enum'])) {
                // Value must be one of the allowed options
                $intVal = (int) $rawValue;
                if (!in_array($intVal, $mapping['enum'])) {
                    $errors[] = "$apiKey: Must be one of: " . implode(', ', $mapping['enum']);
                    continue;
                }
                $value = $intVal;
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
                } elseif ($configKey === 'autoBackupEnabled') {
                    $validation = InputValidator::validateBoolean($rawValue);
                    if (!$validation['valid']) {
                        $errors[] = "$apiKey: " . $validation['error'];
                        continue;
                    }
                    $value = $validation['value'];
                } elseif ($configKey === 'apiCorsAllowedOrigins') {
                    $value = trim((string) $rawValue);
                } elseif ($configKey === 'allowedCurrencies') {
                    $currencies = array_filter(array_map('trim', explode(',', strtoupper((string) $rawValue))));
                    foreach ($currencies as $c) {
                        $validation = InputValidator::validateAllowedCurrency($c);
                        if (!$validation['valid']) {
                            $errors[] = "allowed_currencies: Currency {$c}: " . $validation['error'];
                            continue 2;
                        }
                    }
                    $value = implode(',', $currencies);
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
            $wasAnalyticsEnabled = (bool) ($configContent['analyticsEnabled'] ?? false);
            $configContent[$configKey] = $value;
            if ($hostnameSecure !== null) {
                $configContent['hostname_secure'] = $hostnameSecure;
            }
            // Stamp opt-in timestamp on off->on transition (bounds the analytics
            // rollup window to post-consent)
            if ($configKey === 'analyticsEnabled') {
                $configContent = AnalyticsService::applyOptInAtTransition(
                    $configContent,
                    $wasAnalyticsEnabled,
                    (bool) $value
                );
            }
            file_put_contents($configPath, json_encode($configContent, true), LOCK_EX);

            // Regenerate SSL certificate when hostname changes
            if ($configKey === 'hostname') {
                $this->regenerateSslForHostname($value);
            }

            // Trigger initial analytics node_setup event when toggled on
            if ($configKey === 'analyticsEnabled' && $value === true && !$wasAnalyticsEnabled) {
                $script = '/app/eiou/scripts/analytics-cron.php';
                if (file_exists($script)) {
                    $cmd = '/usr/bin/php ' . escapeshellarg($script) . ' --event=node_setup >> /var/log/eiou/analytics.log 2>&1 &';
                    if (posix_getuid() === 0) {
                        $cmd = Constants::BIN_RUNUSER . ' -u www-data -- ' . $cmd;
                    }
                    @exec($cmd);
                }
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

    // ==================== Debug Report Endpoints ====================

    /**
     * GET /api/v1/system/debug-report
     *
     * Download a debug report as JSON.
     * Query params: full=1 (optional), description (optional)
     */
    private function getDebugReport(array $params): array {
        if (!$this->hasPermission('system:read')) {
            return $this->permissionDenied('system:read');
        }

        try {
            $reportService = $this->services->getDebugReportService();
            $full = !empty($params['full']);
            $description = $params['description'] ?? '';

            $report = $reportService->generateReport($description, $full);

            return $this->successResponse([
                'report' => $report,
                'report_type' => $full ? 'full' : 'limited',
                'debug_entries_count' => $report['debug_entries_count'],
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to generate debug report: ' . $e->getMessage(), 500, 'debug_report_error');
        }
    }

    /**
     * POST /api/v1/system/debug-report
     *
     * Generate and submit a debug report to the support endpoint.
     * Body JSON: { "description": "...", "full": true/false }
     */
    private function submitDebugReport(string $body): array {
        if (!$this->hasPermission('system:read')) {
            return $this->permissionDenied('system:read');
        }

        try {
            $data = json_decode($body, true) ?? [];
            $full = !empty($data['full']);
            $description = $data['description'] ?? '';

            $reportService = $this->services->getDebugReportService();
            $report = $reportService->generateReport($description, $full);
            $result = \Eiou\Services\DebugReportService::submit($report, $description);

            if ($result['success']) {
                return $this->successResponse([
                    'submitted' => true,
                    'key' => $result['key'],
                    'report_type' => $full ? 'full' : 'limited',
                ]);
            }

            return $this->errorResponse(
                $result['error'] ?? 'Submission failed',
                502,
                'debug_report_submit_failed'
            );
        } catch (Exception $e) {
            return $this->errorResponse('Failed to submit debug report: ' . $e->getMessage(), 500, 'debug_report_error');
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

        $filename = \Eiou\Utils\Security::sanitizeFilename(urldecode($filename));

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

        // Validate permissions against whitelist (H-5)
        $permValidation = ApiKeyService::validatePermissions($permissions);
        if (!$permValidation['valid']) {
            return $this->errorResponse(
                'Invalid permission: ' . $permValidation['invalid_permission'] .
                '. Valid permissions: ' . implode(', ', ApiKeyService::PERMISSIONS),
                400,
                'invalid_permission'
            );
        }

        // Validate rate limit (H-5)
        $rateValidation = ApiKeyService::validateRateLimit($rateLimit);
        if (!$rateValidation['valid']) {
            return $this->errorResponse($rateValidation['error'], 400, 'invalid_rate_limit');
        }
        $rateLimit = $rateValidation['value'];

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
        // Prevent deleting the API key used to authenticate this request
        if ($keyId === $this->authenticatedKey['key_id']) {
            return $this->errorResponse('Cannot delete the API key used for this request', 409, 'self_deletion_not_allowed');
        }

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

    // ==================== Tx Drop Endpoints ====================

    /**
     * Handle tx drop endpoints
     *
     * Routes:
     * - POST /api/v1/chaindrop/propose  - Propose tx drop
     * - POST /api/v1/chaindrop/accept   - Accept tx drop proposal
     * - POST /api/v1/chaindrop/reject   - Reject tx drop proposal
     * - GET  /api/v1/chaindrop          - List tx drop proposals
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
                $contactRepo = $this->services->getRepositoryFactory()->get(ContactRepository::class);
                $addressRepo = $this->services->getRepositoryFactory()->get(AddressRepository::class);

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
            $contactRepo = $this->services->getRepositoryFactory()->get(ContactRepository::class);
            $addressRepo = $this->services->getRepositoryFactory()->get(AddressRepository::class);

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

    // ==================== P2P Approval Endpoints ====================

    /**
     * Handle P2P approval endpoints
     */
    private function handleP2p(string $method, ?string $action, ?string $id, array $params, string $body): array {
        return match (true) {
            $method === 'GET' && !$action => $this->listPendingP2p($params),
            $method === 'GET' && $action === 'candidates' && $id !== null => $this->getP2pCandidates($id),
            $method === 'POST' && $action === 'approve' => $this->approveP2pApi($body),
            $method === 'POST' && $action === 'reject' => $this->rejectP2pApi($body),
            default => $this->errorResponse('Unknown P2P action', 404, 'unknown_action')
        };
    }

    /**
     * GET /api/v1/p2p
     */
    private function listPendingP2p(array $params): array {
        if (!$this->hasPermission('wallet:read')) {
            return $this->permissionDenied('wallet:read');
        }

        try {
            $p2pRepo = $this->services->getRepositoryFactory()->get(P2pRepository::class);
            $awaitingList = $p2pRepo->getAwaitingApprovalList();

            $rp2pCandidateRepo = $this->services->getRepositoryFactory()->get(Rp2pCandidateRepository::class);

            $transactions = [];
            foreach ($awaitingList as $p2p) {
                $candidateCount = $rp2pCandidateRepo->getCandidateCount($p2p['hash']);
                $transactions[] = [
                    'hash' => $p2p['hash'],
                    'amount' => $p2p['amount'],
                    'currency' => $p2p['currency'],
                    'destination_address' => $p2p['destination_address'],
                    'my_fee_amount' => (int) ($p2p['my_fee_amount'] ?? 0),
                    'rp2p_amount' => $p2p['rp2p_amount'] !== null ? (int) $p2p['rp2p_amount'] : null,
                    'fast' => (int) $p2p['fast'],
                    'candidate_count' => $candidateCount,
                    'created_at' => $p2p['created_at'],
                ];
            }

            return $this->successResponse([
                'transactions' => $transactions,
                'count' => count($transactions),
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to list pending P2P transactions: ' . $e->getMessage(), 500, 'p2p_error');
        }
    }

    /**
     * GET /api/v1/p2p/candidates/{hash}
     */
    private function getP2pCandidates(string $hash): array {
        if (!$this->hasPermission('wallet:read')) {
            return $this->permissionDenied('wallet:read');
        }

        $hashValidation = InputValidator::validateTxid($hash);
        if (!$hashValidation['valid']) {
            return $this->errorResponse($hashValidation['error'], 400, 'invalid_hash');
        }
        $hash = $hashValidation['value'];

        try {
            $p2pRepo = $this->services->getRepositoryFactory()->get(P2pRepository::class);
            $p2p = $p2pRepo->getAwaitingApproval($hash);
            if (!$p2p) {
                return $this->errorResponse('Transaction not found or not awaiting approval', 404, 'not_found');
            }

            $rp2pCandidateRepo = $this->services->getRepositoryFactory()->get(Rp2pCandidateRepository::class);
            $candidates = $rp2pCandidateRepo->getCandidatesByHash($hash);

            $rp2pRepo = $this->services->getRepositoryFactory()->get(Rp2pRepository::class);
            $rp2p = $rp2pRepo->getByHash($hash);

            return $this->successResponse([
                'hash' => $hash,
                'amount' => $p2p['amount'],
                'currency' => $p2p['currency'],
                'fast' => (int) $p2p['fast'],
                'candidates' => $candidates,
                'rp2p' => $rp2p,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to get P2P candidates: ' . $e->getMessage(), 500, 'p2p_error');
        }
    }

    /**
     * POST /api/v1/p2p/approve
     */
    private function approveP2pApi(string $body): array {
        if (!$this->hasPermission('wallet:send')) {
            return $this->permissionDenied('wallet:send');
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['hash'])) {
            return $this->errorResponse('Missing required field: hash', 400, 'missing_field');
        }

        $hashValidation = InputValidator::validateTxid($data['hash']);
        if (!$hashValidation['valid']) {
            return $this->errorResponse($hashValidation['error'], 400, 'invalid_hash');
        }
        $hash = $hashValidation['value'];
        $candidateId = isset($data['candidate_id']) ? (int) $data['candidate_id'] : 0;

        try {
            $p2pRepo = $this->services->getRepositoryFactory()->get(P2pRepository::class);
            $p2p = $p2pRepo->getAwaitingApproval($hash);
            if (!$p2p) {
                return $this->errorResponse('Transaction not found or not awaiting approval', 404, 'not_found');
            }

            if (empty($p2p['destination_address'])) {
                return $this->errorResponse('Only the transaction originator can approve', 403, 'not_originator');
            }

            $rp2pCandidateRepo = $this->services->getRepositoryFactory()->get(Rp2pCandidateRepository::class);
            $sendService = $this->services->getSendOperationService();

            if ($candidateId > 0) {
                // Candidate selected by ID
                $candidate = $rp2pCandidateRepo->getCandidateById($candidateId);
                if (!$candidate) {
                    return $this->errorResponse('Selected route candidate not found', 404, 'candidate_not_found');
                }

                if ($candidate['hash'] !== $hash) {
                    return $this->errorResponse('Candidate does not belong to this transaction', 400, 'candidate_mismatch');
                }

                $request = [
                    'hash' => $candidate['hash'],
                    'time' => $candidate['time'],
                    'amount' => $candidate['amount'],
                    'currency' => $candidate['currency'],
                    'senderPublicKey' => $candidate['sender_public_key'],
                    'senderAddress' => $candidate['sender_address'],
                    'signature' => $candidate['sender_signature'],
                ];

                $rp2pRepo = $this->services->getRepositoryFactory()->get(Rp2pRepository::class);
                $rp2pRepo->insertRp2pRequest($request);
                $p2pRepo->updateStatus($hash, 'found');
                $sendService->sendP2pEiou($request);
                $rp2pCandidateRepo->deleteCandidatesByHash($hash);

                return $this->successResponse([
                    'message' => 'P2P transaction approved and sent',
                    'hash' => $hash,
                    'candidate_id' => $candidateId,
                ]);
            }

            // No candidate_id - try fast mode (single rp2p)
            $candidates = $rp2pCandidateRepo->getCandidatesByHash($hash);

            if (!empty($candidates) && count($candidates) > 1) {
                // Multiple candidates - caller must pick
                return $this->errorResponse(
                    'Multiple route candidates available. Provide candidate_id to select one.',
                    400,
                    'candidate_selection_required',
                );
            }

            if (!empty($candidates) && count($candidates) === 1) {
                $candidate = $candidates[0];
                $request = [
                    'hash' => $candidate['hash'],
                    'time' => $candidate['time'],
                    'amount' => $candidate['amount'],
                    'currency' => $candidate['currency'],
                    'senderPublicKey' => $candidate['sender_public_key'],
                    'senderAddress' => $candidate['sender_address'],
                    'signature' => $candidate['sender_signature'],
                ];

                $rp2pRepo = $this->services->getRepositoryFactory()->get(Rp2pRepository::class);
                $rp2pRepo->insertRp2pRequest($request);
                $p2pRepo->updateStatus($hash, 'found');
                $sendService->sendP2pEiou($request);
                $rp2pCandidateRepo->deleteCandidatesByHash($hash);

                return $this->successResponse([
                    'message' => 'P2P transaction approved and sent',
                    'hash' => $hash,
                ]);
            }

            // No candidates - check for single rp2p (fast mode)
            $rp2pRepo = $this->services->getRepositoryFactory()->get(Rp2pRepository::class);
            $rp2p = $rp2pRepo->getByHash($hash);

            if ($rp2p) {
                $request = [
                    'hash' => $rp2p['hash'],
                    'time' => $rp2p['time'],
                    'amount' => $rp2p['amount'],
                    'currency' => $rp2p['currency'],
                    'senderPublicKey' => $rp2p['sender_public_key'],
                    'senderAddress' => $rp2p['sender_address'],
                    'signature' => $rp2p['sender_signature'],
                ];

                $p2pRepo->updateStatus($hash, 'found');
                $sendService->sendP2pEiou($request);

                return $this->successResponse([
                    'message' => 'P2P transaction approved and sent (fast mode)',
                    'hash' => $hash,
                ]);
            }

            return $this->errorResponse('No route available for this transaction', 404, 'no_route');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to approve P2P transaction: ' . $e->getMessage(), 500, 'p2p_error');
        }
    }

    /**
     * POST /api/v1/p2p/reject
     */
    private function rejectP2pApi(string $body): array {
        if (!$this->hasPermission('wallet:send')) {
            return $this->permissionDenied('wallet:send');
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['hash'])) {
            return $this->errorResponse('Missing required field: hash', 400, 'missing_field');
        }

        $hashValidation = InputValidator::validateTxid($data['hash']);
        if (!$hashValidation['valid']) {
            return $this->errorResponse($hashValidation['error'], 400, 'invalid_hash');
        }
        $hash = $hashValidation['value'];

        try {
            $p2pRepo = $this->services->getRepositoryFactory()->get(P2pRepository::class);
            $p2p = $p2pRepo->getAwaitingApproval($hash);
            if (!$p2p) {
                return $this->errorResponse('Transaction not found or not awaiting approval', 404, 'not_found');
            }

            if (empty($p2p['destination_address'])) {
                return $this->errorResponse('Only the transaction originator can reject', 403, 'not_originator');
            }

            $p2pRepo->updateStatus($hash, Constants::STATUS_CANCELLED);

            // Propagate cancel upstream
            $p2pService = $this->services->getP2pService();
            $p2pService->sendCancelNotificationForHash($hash);

            // Clean up any remaining candidates
            $rp2pCandidateRepo = $this->services->getRepositoryFactory()->get(Rp2pCandidateRepository::class);
            $rp2pCandidateRepo->deleteCandidatesByHash($hash);

            return $this->successResponse([
                'message' => 'P2P transaction rejected and cancelled',
                'hash' => $hash,
            ]);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to reject P2P transaction: ' . $e->getMessage(), 500, 'p2p_error');
        }
    }

    // ==================== Helper Methods ====================

    /**
     * Regenerate SSL certificate for a new hostname
     */
    private function regenerateSslForHostname(string $newHostname): void {
        $sslCertPath = '/etc/nginx/ssl/server.crt';
        $sslKeyPath = '/etc/nginx/ssl/server.key';

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

        // Extract and validate domain from hostname URL
        $domain = preg_replace('#^https?://#', '', $newHostname);
        $domain = rtrim($domain, '/');

        // Strict hostname validation: only allow valid domain characters
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $domain)) {
            throw new \InvalidArgumentException("Invalid hostname: contains disallowed characters");
        }

        $sslDir = dirname($sslCertPath);
        if (!is_dir($sslDir)) {
            mkdir($sslDir, 0755, true);
        }

        // Build Subject Alternative Names array
        $sanDns = ["DNS:localhost", "DNS:{$domain}"];
        $sanIp = ["IP:127.0.0.1"];

        $containerHostname = gethostname();
        if ($containerHostname && $containerHostname !== $domain) {
            $sanDns[] = "DNS:{$containerHostname}";
        }

        $containerIp = gethostbyname($containerHostname ?: 'localhost');
        if ($containerIp && $containerIp !== '127.0.0.1' && $containerIp !== $containerHostname) {
            $sanIp[] = "IP:{$containerIp}";
        }

        $sanList = implode(',', array_merge($sanDns, $sanIp));

        // Use PHP openssl functions instead of exec() to avoid command injection
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            throw new \RuntimeException("Failed to generate SSL private key");
        }

        $csr = openssl_csr_new(
            ['commonName' => $domain],
            $privateKey,
            [
                'digest_alg' => 'sha256',
                'x509_extensions' => 'v3_ca',
                'config' => $this->createSslConfig($sanList),
            ]
        );

        if ($csr === false) {
            throw new \RuntimeException("Failed to generate SSL CSR");
        }

        $x509 = openssl_csr_sign($csr, null, $privateKey, 365, [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'v3_ca',
            'config' => $this->createSslConfig($sanList),
        ]);

        if ($x509 === false) {
            throw new \RuntimeException("Failed to sign SSL certificate");
        }

        openssl_x509_export_to_file($x509, $sslCertPath);
        openssl_pkey_export_to_file($privateKey, $sslKeyPath);

        chmod($sslCertPath, 0644);
        chmod($sslKeyPath, 0600);
    }

    /**
     * Create a temporary OpenSSL config file for SAN support
     *
     * @param string $sanList Comma-separated SAN entries (already validated)
     * @return string Path to temporary config file
     */
    private function createSslConfig(string $sanList): string {
        $tmpDir = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        $configPath = tempnam($tmpDir, 'ssl_');
        chmod($configPath, 0600);

        $config = "[req]\n"
            . "default_bits = 2048\n"
            . "prompt = no\n"
            . "default_md = sha256\n"
            . "x509_extensions = v3_ca\n"
            . "distinguished_name = dn\n"
            . "[dn]\n"
            . "CN = localhost\n"
            . "[v3_ca]\n"
            . "subjectAltName = {$sanList}\n";

        file_put_contents($configPath, $config);

        // Register cleanup to ensure temp file is always removed
        register_shutdown_function(function () use ($configPath) {
            if (file_exists($configPath)) {
                unlink($configPath);
            }
        });

        return $configPath;
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
     * Handle /api/v1/requests endpoints
     *
     * GET    /api/v1/requests              → list all requests
     * POST   /api/v1/requests              → create a payment request
     * POST   /api/v1/requests/approve      → approve an incoming request
     * POST   /api/v1/requests/decline      → decline an incoming request
     * DELETE /api/v1/requests/{id}         → cancel an outgoing request
     */
    private function handleRequests(string $method, ?string $action, ?string $id, array $params, string $body): array
    {
        $prService = $this->services->getPaymentRequestService();

        return match (true) {
            $method === 'GET' && $action === null => $this->listPaymentRequests($prService, $params),
            $method === 'POST' && $action === null => $this->createPaymentRequest($prService, $body),
            $method === 'POST' && $action === 'approve' => $this->approvePaymentRequest($prService, $body),
            $method === 'POST' && $action === 'decline' => $this->declinePaymentRequest($prService, $body),
            $method === 'DELETE' && $action !== null => $this->cancelPaymentRequest($prService, $action),
            default => $this->errorResponse('Unknown requests action: ' . $action, 404, 'unknown_action')
        };
    }

    private function listPaymentRequests($prService, array $params): array
    {
        $limit = min((int)($params['limit'] ?? 50), 200);
        $requests = $prService->getAllForDisplay($limit);
        return [
            'success' => true,
            'data'    => $requests,
            'status_code' => 200,
        ];
    }

    private function createPaymentRequest($prService, string $body): array
    {
        $data = json_decode($body, true) ?? [];
        $contact  = trim($data['contact']     ?? '');
        $amount   = trim((string)($data['amount']   ?? ''));
        $currency = trim($data['currency']    ?? '');
        $desc     = $data['description']      ?? null;
        $addrType = $data['address_type']     ?? null;

        if (empty($contact) || empty($amount) || empty($currency)) {
            return $this->errorResponse('contact, amount, and currency are required', 400, 'missing_fields');
        }

        $result = $prService->create($contact, $amount, $currency, $desc, $addrType);
        if (!$result['success']) {
            return $this->errorResponse($result['error'], 400, 'request_failed');
        }

        return [
            'success'     => true,
            'data'        => ['request_id' => $result['request_id']],
            'message'     => 'Payment request sent',
            'status_code' => 201,
        ];
    }

    private function approvePaymentRequest($prService, string $body): array
    {
        $data      = json_decode($body, true) ?? [];
        $requestId = trim($data['request_id'] ?? '');

        if (empty($requestId)) {
            return $this->errorResponse('request_id is required', 400, 'missing_fields');
        }

        $result = $prService->approve($requestId);
        if (!$result['success']) {
            return $this->errorResponse($result['error'], 400, 'approve_failed');
        }

        return [
            'success'     => true,
            'data'        => ['txid' => $result['txid'] ?? null],
            'message'     => $result['message'] ?? 'Payment sent',
            'status_code' => 200,
        ];
    }

    private function declinePaymentRequest($prService, string $body): array
    {
        $data      = json_decode($body, true) ?? [];
        $requestId = trim($data['request_id'] ?? '');

        if (empty($requestId)) {
            return $this->errorResponse('request_id is required', 400, 'missing_fields');
        }

        $result = $prService->decline($requestId);
        if (!$result['success']) {
            return $this->errorResponse($result['error'], 400, 'decline_failed');
        }

        return [
            'success'     => true,
            'data'        => null,
            'message'     => 'Payment request declined',
            'status_code' => 200,
        ];
    }

    private function cancelPaymentRequest($prService, string $requestId): array
    {
        $result = $prService->cancel($requestId);
        if (!$result['success']) {
            return $this->errorResponse($result['error'], 400, 'cancel_failed');
        }

        return [
            'success'     => true,
            'data'        => null,
            'message'     => 'Payment request cancelled',
            'status_code' => 200,
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
