<?php
/**
 * Wallet API Controller
 *
 * Copyright 2025
 * Handles wallet-related API endpoints
 */

class WalletController
{
    private $serviceContainer;
    private ApiResponse $response;
    private array $keyInfo;

    public function __construct($serviceContainer, ApiResponse $response, array $keyInfo)
    {
        $this->serviceContainer = $serviceContainer;
        $this->response = $response;
        $this->keyInfo = $keyInfo;
    }

    /**
     * GET /api/v1/wallet/balance
     * Get wallet balance
     */
    public function getBalance(): void
    {
        try {
            require_once __DIR__ . '/../../../services/ServiceContainer.php';
            $container = ServiceContainer::getInstance();
            $balanceService = $container->getBalanceService();

            // Get all balances
            $balances = $balanceService->getAllBalances();

            // Format balances for API response
            $formattedBalances = [];
            foreach ($balances as $balance) {
                $formattedBalances[] = [
                    'contact_name' => $balance['name'] ?? 'Unknown',
                    'contact_address' => $balance['address'] ?? null,
                    'balance' => (float)$balance['balance'],
                    'currency' => $balance['currency'] ?? 'USD',
                    'received' => (float)($balance['received'] ?? 0),
                    'sent' => (float)($balance['sent'] ?? 0)
                ];
            }

            $this->response->success([
                'balances' => $formattedBalances,
                'total_contacts' => count($formattedBalances)
            ]);

        } catch (Exception $e) {
            $this->response->error(
                'Failed to retrieve balance: ' . $e->getMessage(),
                500,
                'BALANCE_ERROR'
            );
        }
    }

    /**
     * POST /api/v1/wallet/send
     * Send transaction
     *
     * Body: { "recipient": "address", "amount": 10.50, "currency": "USD" }
     */
    public function send(): void
    {
        try {
            // Get request body
            $input = $this->getJsonInput();

            // Validate required fields
            $required = ['recipient', 'amount'];
            foreach ($required as $field) {
                if (!isset($input[$field]) || $input[$field] === '') {
                    $this->response->error(
                        "Missing required field: $field",
                        400,
                        'MISSING_FIELD'
                    );
                    return;
                }
            }

            $recipient = $input['recipient'];
            $amount = (float)$input['amount'];
            $currency = $input['currency'] ?? 'USD';

            // Validate amount
            if ($amount <= 0) {
                $this->response->error(
                    'Amount must be greater than 0',
                    400,
                    'INVALID_AMOUNT'
                );
                return;
            }

            // Validate address format
            require_once __DIR__ . '/../../../utils/InputValidator.php';
            $addressValidation = InputValidator::validateAddress($recipient);
            if (!$addressValidation['valid']) {
                $this->response->error(
                    'Invalid recipient address: ' . $addressValidation['error'],
                    400,
                    'INVALID_ADDRESS'
                );
                return;
            }

            // Send transaction via CLI
            $amountInCents = (int)round($amount * 100);
            $escapedRecipient = escapeshellarg($addressValidation['value']);
            $command = "eiou send $escapedRecipient $amountInCents $currency";

            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                $this->response->success([
                    'message' => 'Transaction initiated successfully',
                    'recipient' => $recipient,
                    'amount' => $amount,
                    'currency' => $currency,
                    'output' => implode("\n", $output)
                ], 201);
            } else {
                $this->response->error(
                    'Transaction failed: ' . implode("\n", $output),
                    500,
                    'TRANSACTION_FAILED'
                );
            }

        } catch (Exception $e) {
            $this->response->error(
                'Failed to send transaction: ' . $e->getMessage(),
                500,
                'SEND_ERROR'
            );
        }
    }

    /**
     * GET /api/v1/wallet/address
     * Get wallet receiving address(es)
     */
    public function getAddress(): void
    {
        try {
            require_once __DIR__ . '/../../../core/UserContext.php';
            $userContext = UserContext::getInstance();

            $addresses = [];

            // Get HTTP address
            if ($userContext->has('http_address')) {
                $addresses[] = [
                    'type' => 'http',
                    'address' => $userContext->getHttpAddress(),
                    'hostname' => $userContext->getHostname()
                ];
            }

            // Get Tor address if available
            if ($userContext->has('tor_address')) {
                $addresses[] = [
                    'type' => 'tor',
                    'address' => $userContext->getTorAddress()
                ];
            }

            $this->response->success([
                'addresses' => $addresses
            ]);

        } catch (Exception $e) {
            $this->response->error(
                'Failed to retrieve address: ' . $e->getMessage(),
                500,
                'ADDRESS_ERROR'
            );
        }
    }

    /**
     * GET /api/v1/wallet/transactions
     * Get transaction history
     *
     * Query params: ?limit=20&offset=0&type=sent|received|relay
     */
    public function getTransactions(): void
    {
        try {
            // Get query parameters
            $limit = min((int)($_GET['limit'] ?? 20), 100);
            $offset = (int)($_GET['offset'] ?? 0);
            $type = $_GET['type'] ?? null; // 'sent', 'received', 'relay'

            require_once __DIR__ . '/../../../services/ServiceContainer.php';
            $container = ServiceContainer::getInstance();
            $transactionService = $container->getTransactionService();

            // Get transactions
            $transactions = $transactionService->getTransactionHistory($limit, $offset, $type);
            $total = $transactionService->getTotalTransactionCount($type);

            // Format transactions
            $formattedTransactions = [];
            foreach ($transactions as $tx) {
                $formattedTransactions[] = [
                    'id' => $tx['id'] ?? null,
                    'type' => $tx['type'] ?? 'unknown',
                    'amount' => (float)($tx['amount'] ?? 0),
                    'currency' => $tx['currency'] ?? 'USD',
                    'sender' => $tx['sender'] ?? null,
                    'receiver' => $tx['receiver'] ?? null,
                    'timestamp' => $tx['timestamp'] ?? null,
                    'status' => $tx['status'] ?? 'unknown'
                ];
            }

            $page = (int)ceil(($offset + 1) / $limit);
            $this->response->paginated($formattedTransactions, $total, $page, $limit);

        } catch (Exception $e) {
            $this->response->error(
                'Failed to retrieve transactions: ' . $e->getMessage(),
                500,
                'TRANSACTION_ERROR'
            );
        }
    }

    /**
     * Get JSON input from request body
     *
     * @return array
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        $decoded = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->response->error(
                'Invalid JSON in request body',
                400,
                'INVALID_JSON'
            );
        }

        return $decoded ?? [];
    }
}
