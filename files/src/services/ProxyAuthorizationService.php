<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\ProxyAuthorizationServiceInterface;
use Eiou\Core\UserContext;
use PDO;
use PDOException;
use Exception;

/**
 * Proxy Authorization Service
 *
 * Manages proxy node authorization for offline participation in the EIOU network.
 *
 * A principal (the account owner) delegates scoped transaction authority to one or
 * more proxy nodes. Each proxy maintains a shadow copy of the principal's bilateral
 * balances and may execute transactions within the authorized scope while the
 * principal is offline.
 *
 * Patent Claims implemented:
 * - Claim 3:  Proxy node for offline participation
 * - Claim 10: Multiple proxies + first-commit conflict resolution
 * - Claim 11: Dispute flagging for out-of-scope proxy transactions
 * - Claim 15: Shadow balance verification
 *
 * Key invariants:
 * - Only the principal can create, revoke, or integrate proxy authorizations
 * - Shadow balances are snapshotted at delegation time and adjusted per proxy tx
 * - Aggregate limits are enforced over rolling time windows
 * - First-commit-wins resolves conflicts when multiple proxies act on the same discovery
 */
class ProxyAuthorizationService implements ProxyAuthorizationServiceInterface
{
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * @var UserContext Current user context
     */
    private UserContext $currentUser;

    /**
     * @var Logger Logger instance
     */
    private Logger $secureLogger;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     * @param UserContext $currentUser Current user context
     * @param Logger $logger Logger instance
     */
    public function __construct(PDO $pdo, UserContext $currentUser, Logger $logger)
    {
        $this->pdo = $pdo;
        $this->currentUser = $currentUser;
        $this->secureLogger = $logger;
    }

    // ========================================================================
    // Claim 3 — Proxy node for offline participation
    // ========================================================================

    /**
     * {@inheritdoc}
     */
    public function createAuthorization(string $proxyPubkeyHash, array $scope): array
    {
        $result = ['success' => false, 'authorization_id' => null, 'signature' => null, 'error' => null];

        try {
            // Validate scope parameters
            $validationError = $this->validateScope($scope);
            if ($validationError !== null) {
                $result['error'] = $validationError;
                return $result;
            }

            $principalPubkeyHash = $this->currentUser->getPublicKeyHash();
            if ($principalPubkeyHash === null) {
                $result['error'] = 'Principal public key hash not available';
                return $result;
            }

            // Cannot authorize yourself
            if ($proxyPubkeyHash === $principalPubkeyHash) {
                $result['error'] = 'Cannot create proxy authorization for yourself';
                return $result;
            }

            // Generate authorization ID
            $authorizationId = $this->generateAuthorizationId($principalPubkeyHash, $proxyPubkeyHash);

            // Prepare scope JSON
            $scopeJson = json_encode([
                'action_types' => $scope['action_types'],
                'max_per_tx_amount' => (int) $scope['max_per_tx_amount'],
                'max_aggregate_amount' => (int) $scope['max_aggregate_amount'],
                'aggregate_period_seconds' => (int) $scope['aggregate_period_seconds'],
                'currencies' => $scope['currencies'],
            ]);

            // Sign the authorization record
            $signatureData = $authorizationId . $principalPubkeyHash . $proxyPubkeyHash . $scopeJson . $scope['expires_at'];
            $signature = $this->signData($signatureData);
            if ($signature === false) {
                $result['error'] = 'Failed to sign authorization record';
                return $result;
            }

            // Store in database
            $stmt = $this->pdo->prepare(
                "INSERT INTO proxy_authorizations
                    (authorization_id, principal_pubkey_hash, proxy_pubkey_hash, scope_json, signature, status, expires_at, created_at)
                 VALUES
                    (:authorization_id, :principal_pubkey_hash, :proxy_pubkey_hash, :scope_json, :signature, 'active', :expires_at, NOW(6))"
            );
            $stmt->execute([
                ':authorization_id' => $authorizationId,
                ':principal_pubkey_hash' => $principalPubkeyHash,
                ':proxy_pubkey_hash' => $proxyPubkeyHash,
                ':scope_json' => $scopeJson,
                ':signature' => $signature,
                ':expires_at' => $scope['expires_at'],
            ]);

            $this->secureLogger->info("Proxy authorization created", [
                'authorization_id' => $authorizationId,
                'proxy_pubkey_hash' => substr($proxyPubkeyHash, 0, 16) . '...',
            ]);

            $result['success'] = true;
            $result['authorization_id'] = $authorizationId;
            $result['signature'] = $signature;
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to create proxy authorization", [
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Database error creating proxy authorization';
        } catch (Exception $e) {
            $this->secureLogger->error("Unexpected error creating proxy authorization", [
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Unexpected error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthorization(string $authorizationId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM proxy_authorizations WHERE authorization_id = :authorization_id"
            );
            $stmt->execute([':authorization_id' => $authorizationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return null;
            }

            // Decode scope JSON for convenience
            $row['scope'] = json_decode($row['scope_json'], true);

            return $row;
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to get proxy authorization", [
                'authorization_id' => $authorizationId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function revokeAuthorization(string $authorizationId): array
    {
        $result = ['success' => false, 'error' => null];

        try {
            $auth = $this->getAuthorization($authorizationId);
            if ($auth === null) {
                $result['error'] = 'Authorization not found';
                return $result;
            }

            // Only the principal can revoke
            $principalPubkeyHash = $this->currentUser->getPublicKeyHash();
            if ($auth['principal_pubkey_hash'] !== $principalPubkeyHash) {
                $result['error'] = 'Only the principal can revoke this authorization';
                return $result;
            }

            if ($auth['status'] !== 'active') {
                $result['error'] = 'Authorization is not active (current status: ' . $auth['status'] . ')';
                return $result;
            }

            $stmt = $this->pdo->prepare(
                "UPDATE proxy_authorizations
                 SET status = 'revoked', revoked_at = NOW(6)
                 WHERE authorization_id = :authorization_id AND status = 'active'"
            );
            $stmt->execute([':authorization_id' => $authorizationId]);

            if ($stmt->rowCount() === 0) {
                $result['error'] = 'Failed to revoke authorization (may have already been revoked)';
                return $result;
            }

            $this->secureLogger->info("Proxy authorization revoked", [
                'authorization_id' => $authorizationId,
            ]);

            $result['success'] = true;
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to revoke proxy authorization", [
                'authorization_id' => $authorizationId,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Database error revoking proxy authorization';
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveAuthorizations(): array
    {
        try {
            $principalPubkeyHash = $this->currentUser->getPublicKeyHash();
            if ($principalPubkeyHash === null) {
                return [];
            }

            $stmt = $this->pdo->prepare(
                "SELECT * FROM proxy_authorizations
                 WHERE principal_pubkey_hash = :principal_pubkey_hash
                   AND status = 'active'
                   AND expires_at > NOW(6)
                 ORDER BY created_at DESC"
            );
            $stmt->execute([':principal_pubkey_hash' => $principalPubkeyHash]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $row['scope'] = json_decode($row['scope_json'], true);
            }

            return $rows;
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to get active authorizations", [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveProxyRoles(): array
    {
        try {
            $proxyPubkeyHash = $this->currentUser->getPublicKeyHash();
            if ($proxyPubkeyHash === null) {
                return [];
            }

            $stmt = $this->pdo->prepare(
                "SELECT * FROM proxy_authorizations
                 WHERE proxy_pubkey_hash = :proxy_pubkey_hash
                   AND status = 'active'
                   AND expires_at > NOW(6)
                 ORDER BY created_at DESC"
            );
            $stmt->execute([':proxy_pubkey_hash' => $proxyPubkeyHash]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $row['scope'] = json_decode($row['scope_json'], true);
            }

            return $rows;
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to get active proxy roles", [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function snapshotBalances(string $authorizationId): array
    {
        $result = ['success' => false, 'snapshot_count' => null, 'error' => null];

        try {
            $auth = $this->getAuthorization($authorizationId);
            if ($auth === null) {
                $result['error'] = 'Authorization not found';
                return $result;
            }

            if ($auth['status'] !== 'active') {
                $result['error'] = 'Authorization is not active';
                return $result;
            }

            // Only the principal can snapshot balances
            $principalPubkeyHash = $this->currentUser->getPublicKeyHash();
            if ($auth['principal_pubkey_hash'] !== $principalPubkeyHash) {
                $result['error'] = 'Only the principal can snapshot balances for this authorization';
                return $result;
            }

            // Query all bilateral balances for the principal's contacts
            // Joins balances with contact_currencies to get credit limits
            $stmt = $this->pdo->prepare(
                "SELECT b.pubkey_hash AS contact_pubkey_hash,
                        b.currency,
                        COALESCE(cc.credit_limit, 0) AS credit_limit,
                        (COALESCE(cc.credit_limit, 0) - (b.sent - b.received)) AS available_credit,
                        (b.sent - b.received) AS current_balance
                 FROM balances b
                 LEFT JOIN contact_currencies cc
                    ON cc.pubkey_hash = b.pubkey_hash
                   AND cc.currency = b.currency
                   AND cc.direction = 'outgoing'
                 WHERE b.pubkey_hash IN (
                    SELECT pubkey_hash FROM contacts WHERE status = 'accepted'
                 )"
            );
            $stmt->execute();
            $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Delete existing snapshots for this authorization (re-snapshot)
            $deleteStmt = $this->pdo->prepare(
                "DELETE FROM proxy_balance_snapshots WHERE authorization_id = :authorization_id"
            );
            $deleteStmt->execute([':authorization_id' => $authorizationId]);

            // Insert new snapshots
            $insertStmt = $this->pdo->prepare(
                "INSERT INTO proxy_balance_snapshots
                    (authorization_id, contact_pubkey_hash, currency, credit_limit, available_credit, current_balance, snapshot_at)
                 VALUES
                    (:authorization_id, :contact_pubkey_hash, :currency, :credit_limit, :available_credit, :current_balance, NOW(6))"
            );

            $count = 0;
            foreach ($balances as $balance) {
                $insertStmt->execute([
                    ':authorization_id' => $authorizationId,
                    ':contact_pubkey_hash' => $balance['contact_pubkey_hash'],
                    ':currency' => $balance['currency'],
                    ':credit_limit' => (int) $balance['credit_limit'],
                    ':available_credit' => (int) $balance['available_credit'],
                    ':current_balance' => (int) $balance['current_balance'],
                ]);
                $count++;
            }

            $this->secureLogger->info("Shadow balances snapshotted for proxy authorization", [
                'authorization_id' => $authorizationId,
                'snapshot_count' => $count,
            ]);

            $result['success'] = true;
            $result['snapshot_count'] = $count;
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to snapshot balances", [
                'authorization_id' => $authorizationId,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Database error snapshotting balances';
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function validateProxyAction(string $authorizationId, string $actionType, float $amount, string $currency): array
    {
        $result = ['valid' => false, 'reason' => ''];

        try {
            $auth = $this->getAuthorization($authorizationId);
            if ($auth === null) {
                $result['reason'] = 'Authorization not found';
                return $result;
            }

            // Check authorization is active
            if ($auth['status'] !== 'active') {
                $result['reason'] = 'Authorization is not active (status: ' . $auth['status'] . ')';
                return $result;
            }

            // Check not expired
            $expiresAt = strtotime($auth['expires_at']);
            if ($expiresAt !== false && $expiresAt <= time()) {
                // Auto-expire the authorization
                $this->expireAuthorization($authorizationId);
                $result['reason'] = 'Authorization has expired';
                return $result;
            }

            $scope = $auth['scope'];

            // Check action type is authorized
            if (!in_array($actionType, $scope['action_types'] ?? [], true)) {
                $result['reason'] = 'Action type "' . $actionType . '" is not authorized';
                return $result;
            }

            // Check currency is authorized
            if (!in_array($currency, $scope['currencies'] ?? [], true)) {
                $result['reason'] = 'Currency "' . $currency . '" is not authorized';
                return $result;
            }

            // Check per-transaction amount limit
            $maxPerTx = $scope['max_per_tx_amount'] ?? 0;
            if ($amount > $maxPerTx) {
                $result['reason'] = 'Amount ' . $amount . ' exceeds per-transaction limit of ' . $maxPerTx;
                return $result;
            }

            // Check aggregate amount within the rolling period
            $maxAggregate = $scope['max_aggregate_amount'] ?? 0;
            $periodSeconds = $scope['aggregate_period_seconds'] ?? 0;
            $aggregateUsed = $this->getAggregateUsed($authorizationId, $currency, $periodSeconds);

            if (($aggregateUsed + $amount) > $maxAggregate) {
                $result['reason'] = 'Amount would exceed aggregate limit of ' . $maxAggregate
                    . ' for period (used: ' . $aggregateUsed . ', requested: ' . $amount . ')';
                return $result;
            }

            $result['valid'] = true;
            $result['reason'] = 'Validation passed';
        } catch (Exception $e) {
            $this->secureLogger->error("Error validating proxy action", [
                'authorization_id' => $authorizationId,
                'error' => $e->getMessage(),
            ]);
            $result['reason'] = 'Validation error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function executeProxyTransaction(string $authorizationId, array $transactionData): array
    {
        $result = ['success' => false, 'proxy_transaction_id' => null, 'error' => null];

        try {
            $contactPubkeyHash = $transactionData['contact_pubkey_hash'] ?? '';
            $amount = (int) ($transactionData['amount'] ?? 0);
            $currency = $transactionData['currency'] ?? '';
            $actionType = $transactionData['action_type'] ?? '';
            $chainPreviousTxid = $transactionData['chain_previous_txid'] ?? null;

            if ($contactPubkeyHash === '' || $currency === '' || $actionType === '') {
                $result['error'] = 'Missing required transaction data (contact_pubkey_hash, currency, action_type)';
                return $result;
            }

            // Validate the action first
            $validation = $this->validateProxyAction($authorizationId, $actionType, $amount, $currency);
            if (!$validation['valid']) {
                $result['error'] = 'Validation failed: ' . $validation['reason'];
                return $result;
            }

            // Check shadow balance has sufficient credit
            $shadowBalance = $this->getShadowBalance($authorizationId, $contactPubkeyHash, $currency);
            if ($shadowBalance === null) {
                $result['error'] = 'No shadow balance found for contact ' . substr($contactPubkeyHash, 0, 16) . '... in currency ' . $currency;
                return $result;
            }

            if ($actionType === 'send' && $shadowBalance['adjusted_available_credit'] < $amount) {
                $result['error'] = 'Insufficient shadow balance (available: ' . $shadowBalance['adjusted_available_credit'] . ', requested: ' . $amount . ')';
                return $result;
            }

            // Generate proxy transaction ID
            $proxyTransactionId = $this->generateProxyTransactionId($authorizationId, $contactPubkeyHash);

            // Sign the proxy transaction
            $auth = $this->getAuthorization($authorizationId);
            $signatureData = $proxyTransactionId . $authorizationId . $contactPubkeyHash . $amount . $currency . $actionType;
            $proxySignature = $this->signData($signatureData);
            if ($proxySignature === false) {
                $result['error'] = 'Failed to sign proxy transaction';
                return $result;
            }

            // Build authorization reference
            $authorizationReference = json_encode([
                'authorization_id' => $authorizationId,
                'principal_pubkey_hash' => $auth['principal_pubkey_hash'],
                'signature' => $auth['signature'],
            ]);

            // Insert proxy transaction record
            $stmt = $this->pdo->prepare(
                "INSERT INTO proxy_transactions
                    (proxy_transaction_id, authorization_id, contact_pubkey_hash, amount, currency,
                     action_type, proxy_signature, authorization_reference, chain_previous_txid,
                     status, executed_at)
                 VALUES
                    (:proxy_transaction_id, :authorization_id, :contact_pubkey_hash, :amount, :currency,
                     :action_type, :proxy_signature, :authorization_reference, :chain_previous_txid,
                     'executed', NOW(6))"
            );
            $stmt->execute([
                ':proxy_transaction_id' => $proxyTransactionId,
                ':authorization_id' => $authorizationId,
                ':contact_pubkey_hash' => $contactPubkeyHash,
                ':amount' => $amount,
                ':currency' => $currency,
                ':action_type' => $actionType,
                ':proxy_signature' => $proxySignature,
                ':authorization_reference' => $authorizationReference,
                ':chain_previous_txid' => $chainPreviousTxid,
            ]);

            $this->secureLogger->info("Proxy transaction executed", [
                'proxy_transaction_id' => $proxyTransactionId,
                'authorization_id' => $authorizationId,
                'amount' => $amount,
                'currency' => $currency,
                'action_type' => $actionType,
            ]);

            $result['success'] = true;
            $result['proxy_transaction_id'] = $proxyTransactionId;
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to execute proxy transaction", [
                'authorization_id' => $authorizationId,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Database error executing proxy transaction';
        } catch (Exception $e) {
            $this->secureLogger->error("Unexpected error executing proxy transaction", [
                'authorization_id' => $authorizationId,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Unexpected error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function syncBackTransactions(string $authorizationId): array
    {
        $result = ['success' => false, 'transactions' => [], 'count' => 0, 'error' => null];

        try {
            $auth = $this->getAuthorization($authorizationId);
            if ($auth === null) {
                $result['error'] = 'Authorization not found';
                return $result;
            }

            // Query all proxy transactions for this authorization
            $stmt = $this->pdo->prepare(
                "SELECT * FROM proxy_transactions
                 WHERE authorization_id = :authorization_id
                 ORDER BY executed_at ASC"
            );
            $stmt->execute([':authorization_id' => $authorizationId]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Package each transaction with its authorization reference
            $packaged = [];
            foreach ($transactions as $tx) {
                $packaged[] = [
                    'proxy_transaction_id' => $tx['proxy_transaction_id'],
                    'authorization_id' => $tx['authorization_id'],
                    'contact_pubkey_hash' => $tx['contact_pubkey_hash'],
                    'amount' => (int) $tx['amount'],
                    'currency' => $tx['currency'],
                    'action_type' => $tx['action_type'],
                    'proxy_signature' => $tx['proxy_signature'],
                    'counterparty_signature' => $tx['counterparty_signature'],
                    'authorization_reference' => $tx['authorization_reference'],
                    'chain_previous_txid' => $tx['chain_previous_txid'],
                    'discovery_hash' => $tx['discovery_hash'],
                    'status' => $tx['status'],
                    'executed_at' => $tx['executed_at'],
                ];
            }

            $this->secureLogger->info("Proxy transactions synced back", [
                'authorization_id' => $authorizationId,
                'count' => count($packaged),
            ]);

            $result['success'] = true;
            $result['transactions'] = $packaged;
            $result['count'] = count($packaged);
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to sync back proxy transactions", [
                'authorization_id' => $authorizationId,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Database error syncing proxy transactions';
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function integratProxyTransactions(string $authorizationId, array $proxyTransactions): array
    {
        $result = ['success' => false, 'integrated_count' => 0, 'flagged_count' => 0, 'errors' => []];

        try {
            $auth = $this->getAuthorization($authorizationId);
            if ($auth === null) {
                $result['errors'][] = 'Authorization not found';
                return $result;
            }

            // Only the principal can integrate
            $principalPubkeyHash = $this->currentUser->getPublicKeyHash();
            if ($auth['principal_pubkey_hash'] !== $principalPubkeyHash) {
                $result['errors'][] = 'Only the principal can integrate proxy transactions';
                return $result;
            }

            $scope = $auth['scope'];
            $integratedCount = 0;
            $flaggedCount = 0;

            foreach ($proxyTransactions as $tx) {
                $proxyTxId = $tx['proxy_transaction_id'] ?? '';
                $actionType = $tx['action_type'] ?? '';
                $amount = (int) ($tx['amount'] ?? 0);
                $currency = $tx['currency'] ?? '';
                $contactPubkeyHash = $tx['contact_pubkey_hash'] ?? '';
                $chainPreviousTxid = $tx['chain_previous_txid'] ?? null;

                // Verify against authorization scope
                $scopeValid = true;
                $scopeReason = '';

                if (!in_array($actionType, $scope['action_types'] ?? [], true)) {
                    $scopeValid = false;
                    $scopeReason = 'Unauthorized action type: ' . $actionType;
                } elseif (!in_array($currency, $scope['currencies'] ?? [], true)) {
                    $scopeValid = false;
                    $scopeReason = 'Unauthorized currency: ' . $currency;
                } elseif ($amount > ($scope['max_per_tx_amount'] ?? 0)) {
                    $scopeValid = false;
                    $scopeReason = 'Amount exceeds per-transaction limit';
                }

                if (!$scopeValid) {
                    // Auto-flag out-of-scope transactions
                    $this->flagProxyTransactionInternal($proxyTxId, 'Auto-flagged during integration: ' . $scopeReason);
                    $flaggedCount++;
                    $result['errors'][] = 'Transaction ' . $proxyTxId . ' flagged: ' . $scopeReason;
                    continue;
                }

                // Verify counterparty signature if present
                if (!empty($tx['counterparty_signature'])) {
                    // Counterparty signature verification would use the contact's public key
                    // For now, record the signature for later verification
                    $this->secureLogger->debug("Counterparty signature recorded for integration", [
                        'proxy_transaction_id' => $proxyTxId,
                        'contact_pubkey_hash' => substr($contactPubkeyHash, 0, 16) . '...',
                    ]);
                }

                // Mark as synced in proxy_transactions
                $stmt = $this->pdo->prepare(
                    "UPDATE proxy_transactions
                     SET status = 'synced', synced_at = NOW(6)
                     WHERE proxy_transaction_id = :proxy_transaction_id"
                );
                $stmt->execute([':proxy_transaction_id' => $proxyTxId]);

                $integratedCount++;

                $this->secureLogger->debug("Proxy transaction integrated", [
                    'proxy_transaction_id' => $proxyTxId,
                    'amount' => $amount,
                    'currency' => $currency,
                ]);
            }

            $this->secureLogger->info("Proxy transactions integration complete", [
                'authorization_id' => $authorizationId,
                'integrated' => $integratedCount,
                'flagged' => $flaggedCount,
            ]);

            $result['success'] = true;
            $result['integrated_count'] = $integratedCount;
            $result['flagged_count'] = $flaggedCount;
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to integrate proxy transactions", [
                'authorization_id' => $authorizationId,
                'error' => $e->getMessage(),
            ]);
            $result['errors'][] = 'Database error integrating proxy transactions';
        } catch (Exception $e) {
            $this->secureLogger->error("Unexpected error integrating proxy transactions", [
                'authorization_id' => $authorizationId,
                'error' => $e->getMessage(),
            ]);
            $result['errors'][] = 'Unexpected error: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function flagProxyTransaction(string $proxyTransactionId, string $reason): array
    {
        $result = ['success' => false, 'status' => null, 'error' => null];

        try {
            // Verify the proxy transaction exists
            $stmt = $this->pdo->prepare(
                "SELECT pt.*, pa.principal_pubkey_hash
                 FROM proxy_transactions pt
                 JOIN proxy_authorizations pa ON pa.authorization_id = pt.authorization_id
                 WHERE pt.proxy_transaction_id = :proxy_transaction_id"
            );
            $stmt->execute([':proxy_transaction_id' => $proxyTransactionId]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($tx === false) {
                $result['error'] = 'Proxy transaction not found';
                return $result;
            }

            // Only the principal can flag transactions
            $principalPubkeyHash = $this->currentUser->getPublicKeyHash();
            if ($tx['principal_pubkey_hash'] !== $principalPubkeyHash) {
                $result['error'] = 'Only the principal can flag proxy transactions';
                return $result;
            }

            return $this->flagProxyTransactionInternal($proxyTransactionId, $reason);
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to flag proxy transaction", [
                'proxy_transaction_id' => $proxyTransactionId,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Database error flagging proxy transaction';
        }

        return $result;
    }

    // ========================================================================
    // Claim 10 — Multiple proxies + first-commit resolution
    // ========================================================================

    /**
     * {@inheritdoc}
     */
    public function resolveConflict(string $discoveryHash): array
    {
        $result = ['success' => false, 'winning_transaction_id' => null, 'conflicted_count' => 0, 'error' => null];

        try {
            // Query all proxy transactions for this discovery hash, ordered by execution time
            $stmt = $this->pdo->prepare(
                "SELECT proxy_transaction_id, authorization_id, executed_at, status
                 FROM proxy_transactions
                 WHERE discovery_hash = :discovery_hash
                   AND status IN ('executed', 'synced')
                 ORDER BY executed_at ASC"
            );
            $stmt->execute([':discovery_hash' => $discoveryHash]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($transactions) === 0) {
                $result['error'] = 'No proxy transactions found for discovery hash';
                return $result;
            }

            if (count($transactions) === 1) {
                // Only one transaction, no conflict
                $result['success'] = true;
                $result['winning_transaction_id'] = $transactions[0]['proxy_transaction_id'];
                $result['conflicted_count'] = 0;
                return $result;
            }

            // First-commit wins: the first transaction (earliest executed_at) is the winner
            $winner = $transactions[0];
            $conflictedCount = 0;

            // Mark all subsequent transactions as conflicted
            for ($i = 1; $i < count($transactions); $i++) {
                $conflictedTxId = $transactions[$i]['proxy_transaction_id'];
                $updateStmt = $this->pdo->prepare(
                    "UPDATE proxy_transactions
                     SET status = 'conflicted',
                         dispute_reason = :reason
                     WHERE proxy_transaction_id = :proxy_transaction_id"
                );
                $updateStmt->execute([
                    ':proxy_transaction_id' => $conflictedTxId,
                    ':reason' => 'First-commit conflict resolution: lost to ' . $winner['proxy_transaction_id'],
                ]);
                $conflictedCount++;
            }

            $this->secureLogger->info("Proxy conflict resolved by first-commit", [
                'discovery_hash' => substr($discoveryHash, 0, 32) . '...',
                'winner' => $winner['proxy_transaction_id'],
                'conflicted_count' => $conflictedCount,
            ]);

            $result['success'] = true;
            $result['winning_transaction_id'] = $winner['proxy_transaction_id'];
            $result['conflicted_count'] = $conflictedCount;
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to resolve proxy conflict", [
                'discovery_hash' => substr($discoveryHash, 0, 32) . '...',
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Database error resolving conflict';
        }

        return $result;
    }

    // ========================================================================
    // Claim 15 — Shadow balance verification
    // ========================================================================

    /**
     * {@inheritdoc}
     */
    public function getShadowBalance(string $authorizationId, string $contactPubkeyHash, string $currency): ?array
    {
        try {
            // Get the base snapshot
            $stmt = $this->pdo->prepare(
                "SELECT credit_limit, available_credit, current_balance, snapshot_at
                 FROM proxy_balance_snapshots
                 WHERE authorization_id = :authorization_id
                   AND contact_pubkey_hash = :contact_pubkey_hash
                   AND currency = :currency"
            );
            $stmt->execute([
                ':authorization_id' => $authorizationId,
                ':contact_pubkey_hash' => $contactPubkeyHash,
                ':currency' => $currency,
            ]);
            $snapshot = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($snapshot === false) {
                return null;
            }

            // Calculate adjustments from proxy transactions since snapshot
            $adjustStmt = $this->pdo->prepare(
                "SELECT action_type, SUM(amount) AS total_amount
                 FROM proxy_transactions
                 WHERE authorization_id = :authorization_id
                   AND contact_pubkey_hash = :contact_pubkey_hash
                   AND currency = :currency
                   AND status IN ('executed', 'synced')
                   AND executed_at >= :snapshot_at
                 GROUP BY action_type"
            );
            $adjustStmt->execute([
                ':authorization_id' => $authorizationId,
                ':contact_pubkey_hash' => $contactPubkeyHash,
                ':currency' => $currency,
                ':snapshot_at' => $snapshot['snapshot_at'],
            ]);
            $adjustments = $adjustStmt->fetchAll(PDO::FETCH_ASSOC);

            $totalSent = 0;
            $totalReceived = 0;
            foreach ($adjustments as $adj) {
                if ($adj['action_type'] === 'send') {
                    $totalSent += (int) $adj['total_amount'];
                } elseif ($adj['action_type'] === 'receive') {
                    $totalReceived += (int) $adj['total_amount'];
                }
            }

            // Adjust the shadow balance
            $adjustedBalance = (int) $snapshot['current_balance'] + $totalSent - $totalReceived;
            $adjustedAvailableCredit = (int) $snapshot['credit_limit'] - $adjustedBalance;

            return [
                'credit_limit' => (int) $snapshot['credit_limit'],
                'available_credit' => (int) $snapshot['available_credit'],
                'current_balance' => (int) $snapshot['current_balance'],
                'adjusted_available_credit' => max(0, $adjustedAvailableCredit),
                'total_proxy_sent' => $totalSent,
                'total_proxy_received' => $totalReceived,
                'adjusted_balance' => $adjustedBalance,
            ];
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to get shadow balance", [
                'authorization_id' => $authorizationId,
                'contact_pubkey_hash' => substr($contactPubkeyHash, 0, 16) . '...',
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ========================================================================
    // Private helper methods
    // ========================================================================

    /**
     * Validate the scope parameters for a new authorization
     *
     * @param array $scope The scope to validate
     * @return string|null Error message, or null if valid
     */
    private function validateScope(array $scope): ?string
    {
        if (!isset($scope['action_types']) || !is_array($scope['action_types']) || empty($scope['action_types'])) {
            return 'Scope must include non-empty action_types array';
        }

        $allowedActionTypes = ['send', 'receive', 'relay'];
        foreach ($scope['action_types'] as $actionType) {
            if (!in_array($actionType, $allowedActionTypes, true)) {
                return 'Invalid action type: ' . $actionType . '. Allowed: ' . implode(', ', $allowedActionTypes);
            }
        }

        if (!isset($scope['max_per_tx_amount']) || !is_numeric($scope['max_per_tx_amount']) || $scope['max_per_tx_amount'] <= 0) {
            return 'Scope must include positive max_per_tx_amount';
        }

        if (!isset($scope['max_aggregate_amount']) || !is_numeric($scope['max_aggregate_amount']) || $scope['max_aggregate_amount'] <= 0) {
            return 'Scope must include positive max_aggregate_amount';
        }

        if (!isset($scope['aggregate_period_seconds']) || !is_numeric($scope['aggregate_period_seconds']) || $scope['aggregate_period_seconds'] <= 0) {
            return 'Scope must include positive aggregate_period_seconds';
        }

        if (!isset($scope['currencies']) || !is_array($scope['currencies']) || empty($scope['currencies'])) {
            return 'Scope must include non-empty currencies array';
        }

        if (!isset($scope['expires_at']) || strtotime($scope['expires_at']) === false) {
            return 'Scope must include valid expires_at timestamp';
        }

        if (strtotime($scope['expires_at']) <= time()) {
            return 'Authorization expiration must be in the future';
        }

        if ($scope['max_per_tx_amount'] > $scope['max_aggregate_amount']) {
            return 'max_per_tx_amount cannot exceed max_aggregate_amount';
        }

        return null;
    }

    /**
     * Generate a unique authorization ID
     *
     * @param string $principalPubkeyHash Principal's public key hash
     * @param string $proxyPubkeyHash Proxy's public key hash
     * @return string UUID-format authorization ID
     */
    private function generateAuthorizationId(string $principalPubkeyHash, string $proxyPubkeyHash): string
    {
        $raw = hash('sha256', $principalPubkeyHash . $proxyPubkeyHash . random_bytes(16) . microtime(true));
        // Format as UUID: 8-4-4-4-12
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($raw, 0, 8),
            substr($raw, 8, 4),
            substr($raw, 12, 4),
            substr($raw, 16, 4),
            substr($raw, 20, 12)
        );
    }

    /**
     * Generate a unique proxy transaction ID
     *
     * @param string $authorizationId The authorization UUID
     * @param string $contactPubkeyHash The counterparty public key hash
     * @return string UUID-format proxy transaction ID
     */
    private function generateProxyTransactionId(string $authorizationId, string $contactPubkeyHash): string
    {
        $raw = hash('sha256', $authorizationId . $contactPubkeyHash . random_bytes(16) . microtime(true));
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($raw, 0, 8),
            substr($raw, 8, 4),
            substr($raw, 12, 4),
            substr($raw, 16, 4),
            substr($raw, 20, 12)
        );
    }

    /**
     * Sign data with the current user's private key
     *
     * @param string $data The data to sign
     * @return string|false The signature, or false on failure
     */
    private function signData(string $data): string|false
    {
        $privateKey = $this->currentUser->getPrivateKey();
        if ($privateKey === null) {
            return false;
        }

        $signature = '';
        $signed = openssl_sign($data, $signature, openssl_pkey_get_private($privateKey));
        if (!$signed) {
            return false;
        }

        return base64_encode($signature);
    }

    /**
     * Get the aggregate amount used within the rolling period for an authorization
     *
     * @param string $authorizationId The authorization UUID
     * @param string $currency The currency code
     * @param int $periodSeconds The rolling period in seconds
     * @return int Total amount used in the period
     */
    private function getAggregateUsed(string $authorizationId, string $currency, int $periodSeconds): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM proxy_transactions
             WHERE authorization_id = :authorization_id
               AND currency = :currency
               AND status IN ('executed', 'synced')
               AND executed_at >= DATE_SUB(NOW(6), INTERVAL :period_seconds SECOND)"
        );
        $stmt->execute([
            ':authorization_id' => $authorizationId,
            ':currency' => $currency,
            ':period_seconds' => $periodSeconds,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Expire an authorization that has passed its expiration time
     *
     * @param string $authorizationId The authorization UUID
     * @return void
     */
    private function expireAuthorization(string $authorizationId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE proxy_authorizations
                 SET status = 'expired'
                 WHERE authorization_id = :authorization_id AND status = 'active'"
            );
            $stmt->execute([':authorization_id' => $authorizationId]);

            $this->secureLogger->info("Proxy authorization auto-expired", [
                'authorization_id' => $authorizationId,
            ]);
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to expire authorization", [
                'authorization_id' => $authorizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Internal flag method (no principal check, used by both public and integration paths)
     *
     * @param string $proxyTransactionId The proxy transaction UUID
     * @param string $reason The dispute reason
     * @return array Result with keys: success (bool), status (string|null), error (string|null)
     */
    private function flagProxyTransactionInternal(string $proxyTransactionId, string $reason): array
    {
        $result = ['success' => false, 'status' => null, 'error' => null];

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE proxy_transactions
                 SET status = 'disputed', dispute_reason = :reason
                 WHERE proxy_transaction_id = :proxy_transaction_id"
            );
            $stmt->execute([
                ':proxy_transaction_id' => $proxyTransactionId,
                ':reason' => $reason,
            ]);

            if ($stmt->rowCount() === 0) {
                $result['error'] = 'Proxy transaction not found or already flagged';
                return $result;
            }

            $this->secureLogger->info("Proxy transaction flagged for dispute", [
                'proxy_transaction_id' => $proxyTransactionId,
                'reason' => $reason,
            ]);

            $result['success'] = true;
            $result['status'] = 'disputed';
        } catch (PDOException $e) {
            $this->secureLogger->error("Failed to flag proxy transaction internally", [
                'proxy_transaction_id' => $proxyTransactionId,
                'error' => $e->getMessage(),
            ]);
            $result['error'] = 'Database error flagging proxy transaction';
        }

        return $result;
    }
}
