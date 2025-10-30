<?php
/**
 * Cached Transaction Service
 *
 * Extends TransactionService with caching for expensive operations
 * like balance calculations and transaction history queries.
 *
 * @package Services
 * @copyright 2025
 */

require_once __DIR__ . '/TransactionService.php';
require_once __DIR__ . '/../cache/DockerCache.php';

class CachedTransactionService extends TransactionService {
    /**
     * @var DockerCache Cache instance
     */
    private DockerCache $cache;

    /**
     * Constructor - initializes cache
     */
    public function __construct(
        P2pRepository $p2pRepository,
        Rp2pRepository $rp2pRepository,
        TransactionRepository $transactionRepository,
        ContactRepository $contactRepository,
        UtilityServiceContainer $utilityContainer,
        UserContext $currentUser
    ) {
        parent::__construct(
            $p2pRepository,
            $rp2pRepository,
            $transactionRepository,
            $contactRepository,
            $utilityContainer,
            $currentUser
        );

        $this->cache = DockerCache::getInstance();
    }

    /**
     * Check if sender has sufficient available funds with caching
     *
     * @param array $request The transaction request data
     * @return bool True if sufficient funds are available, false otherwise
     */
    public function checkAvailableFundsTransaction(array $request): bool {
        try {
            // Validate required fields
            if (!isset($request['senderPublicKey'], $request['amount'], $request['currency'])) {
                error_log("Missing required fields for funds check");
                return false;
            }

            // Validate amount is numeric and positive
            if (!is_numeric($request['amount']) || $request['amount'] <= 0) {
                error_log("Invalid amount in transaction request: " . $request['amount']);
                return false;
            }

            // Get cached balance if available
            $balanceCacheKey = 'balance_' . md5($request['senderPublicKey']);
            $cachedBalance = $this->cache->get($balanceCacheKey);

            if ($cachedBalance !== null) {
                $currentBalance = $cachedBalance;
            } else {
                // Calculate balance if not cached
                $totalSent = $this->getCachedTotalSent($request['senderPublicKey']);
                $totalReceived = $this->getCachedTotalReceived($request['senderPublicKey']);
                $currentBalance = $totalReceived - $totalSent;

                // Cache the balance for 10 seconds
                $this->cache->set($balanceCacheKey, $currentBalance, 10, 'wallet_balance');
            }

            // Get cached credit limit
            $creditCacheKey = 'credit_' . md5($request['senderPublicKey']);
            $creditLimit = $this->cache->get($creditCacheKey);

            if ($creditLimit === null) {
                $creditLimit = $this->contactRepository->getCreditLimit($request['senderPublicKey']);
                // Cache credit limit for 30 seconds
                $this->cache->set($creditCacheKey, $creditLimit, 30, 'contact_data');
            }

            // Check if sender has sufficient balance or credit limit
            $requiredAmount = $request['amount'];
            $availableFunds = $currentBalance + $creditLimit;

            if ($availableFunds > $requiredAmount) {
                return true;
            } else {
                echo $this->utilPayload->buildInsufficientBalance(
                    $availableFunds,
                    $requiredAmount,
                    $creditLimit,
                    0,
                    $request['currency']
                );
                return false;
            }
        } catch (PDOException $e) {
            error_log("Database error in checkAvailableFundsTransaction: " . $e->getMessage());
            // Invalidate cache on database error
            $this->cache->delete('balance_' . md5($request['senderPublicKey']));
            throw $e;
        }
    }

    /**
     * Get cached total sent amount
     *
     * @param string $publicKey User's public key
     * @return float Total sent amount
     */
    private function getCachedTotalSent(string $publicKey): float {
        $cacheKey = 'total_sent_' . md5($publicKey);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $total = $this->transactionRepository->calculateTotalSentByUser($publicKey);

        // Cache for 20 seconds
        $this->cache->set($cacheKey, $total, 20, 'transaction_history');

        return $total;
    }

    /**
     * Get cached total received amount
     *
     * @param string $publicKey User's public key
     * @return float Total received amount
     */
    private function getCachedTotalReceived(string $publicKey): float {
        $cacheKey = 'total_received_' . md5($publicKey);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $total = $this->transactionRepository->calculateTotalReceived($publicKey);

        // Cache for 20 seconds
        $this->cache->set($cacheKey, $total, 20, 'transaction_history');

        return $total;
    }

    /**
     * Get transaction history with caching
     *
     * @param string $publicKey User's public key
     * @param int $limit Maximum number of transactions
     * @param int $offset Starting offset
     * @return array Transaction history
     */
    public function getTransactionHistory(string $publicKey, int $limit = 50, int $offset = 0): array {
        $cacheKey = 'tx_history_' . md5($publicKey . '_' . $limit . '_' . $offset);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Get transactions from repository
        $transactions = $this->transactionRepository->getTransactionsByUser(
            $publicKey,
            $limit,
            $offset
        );

        // Cache for 20 seconds
        $this->cache->set($cacheKey, $transactions, 20, 'transaction_history');

        return $transactions;
    }

    /**
     * Get balance summary with caching
     *
     * @param string $publicKey User's public key
     * @return array Balance summary
     */
    public function getBalanceSummary(string $publicKey): array {
        $cacheKey = 'balance_summary_' . md5($publicKey);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $totalSent = $this->getCachedTotalSent($publicKey);
        $totalReceived = $this->getCachedTotalReceived($publicKey);
        $balance = $totalReceived - $totalSent;

        // Get additional metrics
        $pendingIncoming = $this->getPendingIncoming($publicKey);
        $pendingOutgoing = $this->getPendingOutgoing($publicKey);

        $summary = [
            'balance' => $balance,
            'total_sent' => $totalSent,
            'total_received' => $totalReceived,
            'pending_incoming' => $pendingIncoming,
            'pending_outgoing' => $pendingOutgoing,
            'available_balance' => $balance - $pendingOutgoing,
            'timestamp' => time()
        ];

        // Cache for 10 seconds
        $this->cache->set($cacheKey, $summary, 10, 'wallet_balance');

        return $summary;
    }

    /**
     * Get pending incoming amount
     *
     * @param string $publicKey User's public key
     * @return float Pending incoming amount
     */
    private function getPendingIncoming(string $publicKey): float {
        $cacheKey = 'pending_in_' . md5($publicKey);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Calculate pending incoming from p2p messages
        $pending = $this->p2pRepository->getPendingIncomingAmount($publicKey);

        // Cache for 5 seconds
        $this->cache->set($cacheKey, $pending, 5, 'wallet_balance');

        return $pending;
    }

    /**
     * Get pending outgoing amount
     *
     * @param string $publicKey User's public key
     * @return float Pending outgoing amount
     */
    private function getPendingOutgoing(string $publicKey): float {
        $cacheKey = 'pending_out_' . md5($publicKey);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Calculate pending outgoing from p2p messages
        $pending = $this->p2pRepository->getPendingOutgoingAmount($publicKey);

        // Cache for 5 seconds
        $this->cache->set($cacheKey, $pending, 5, 'wallet_balance');

        return $pending;
    }

    /**
     * Process transaction with cache invalidation
     *
     * @param array $request Transaction request data
     * @return bool Success status
     */
    public function processTransaction(array $request): bool {
        // Process the transaction
        $result = parent::processTransaction($request);

        if ($result) {
            // Invalidate relevant caches on successful transaction
            $this->invalidateTransactionCaches($request);
        }

        return $result;
    }

    /**
     * Invalidate caches after transaction
     *
     * @param array $request Transaction request data
     * @return void
     */
    private function invalidateTransactionCaches(array $request): void {
        // Trigger cache invalidation hooks
        $this->cache->triggerInvalidation('transaction_created', [
            'sender' => $request['senderPublicKey'] ?? null,
            'receiver' => $request['receiverAddress'] ?? null
        ]);

        // Additionally invalidate specific caches
        if (isset($request['senderPublicKey'])) {
            $senderKey = md5($request['senderPublicKey']);
            $this->cache->delete('balance_' . $senderKey);
            $this->cache->delete('balance_summary_' . $senderKey);
            $this->cache->delete('total_sent_' . $senderKey);
            $this->cache->delete('pending_out_' . $senderKey);
            $this->cache->invalidateByTag('user_' . $senderKey);
        }

        if (isset($request['receiverAddress'])) {
            // Hash the receiver address to get their public key cache key
            $receiverKey = md5($request['receiverAddress']);
            $this->cache->delete('balance_' . $receiverKey);
            $this->cache->delete('balance_summary_' . $receiverKey);
            $this->cache->delete('total_received_' . $receiverKey);
            $this->cache->delete('pending_in_' . $receiverKey);
            $this->cache->invalidateByTag('user_' . $receiverKey);
        }

        // Invalidate transaction history caches
        $this->cache->invalidateByType('transaction_history');
    }

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public function getCacheStats(): array {
        return $this->cache->getStats();
    }

    /**
     * Warmup cache with frequently accessed data
     *
     * @param array $publicKeys Array of public keys to warmup
     * @return void
     */
    public function warmupCache(array $publicKeys): void {
        foreach ($publicKeys as $publicKey) {
            // Pre-load balance data
            $this->getCachedTotalSent($publicKey);
            $this->getCachedTotalReceived($publicKey);
            $this->getBalanceSummary($publicKey);

            // Pre-load recent transactions
            $this->getTransactionHistory($publicKey, 10, 0);
        }
    }

    /**
     * Clear all transaction-related caches
     *
     * @return void
     */
    public function clearTransactionCaches(): void {
        $this->cache->invalidateByType('wallet_balance');
        $this->cache->invalidateByType('transaction_history');
    }
}