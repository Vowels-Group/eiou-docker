<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Proxy Authorization Service Interface
 *
 * Defines the contract for proxy node authorization in the EIOU network,
 * enabling offline participation through delegated transaction authority.
 *
 * Patent Claims implemented:
 * - Claim 3:  Proxy node for offline participation — a principal delegates
 *             scoped authority to a proxy node, which maintains shadow balances
 *             and executes transactions on the principal's behalf while offline.
 * - Claim 10: Multiple proxies + first-commit resolution — when multiple
 *             proxies execute for the same discovery, the earliest commit wins.
 * - Claim 11: Dispute flagging — principal can flag out-of-scope proxy
 *             transactions for dispute resolution after reconnection.
 * - Claim 15: Shadow balance verification — proxy maintains a snapshot of the
 *             principal's bilateral balances and adjusts them per proxy transaction.
 *
 * Flow:
 * 1. Principal creates authorization with scoped limits (createAuthorization)
 * 2. Shadow balances are snapshotted for proxy use (snapshotBalances)
 * 3. Proxy validates and executes transactions within scope (validateProxyAction, executeProxyTransaction)
 * 4. On reconnection, principal syncs and integrates proxy transactions (syncBackTransactions, integratProxyTransactions)
 * 5. Principal may flag out-of-scope transactions for dispute (flagProxyTransaction)
 * 6. Conflicts from multiple proxies resolved by first-commit (resolveConflict)
 */
interface ProxyAuthorizationServiceInterface
{
    // ========================================================================
    // Claim 3 — Proxy node for offline participation
    // ========================================================================

    /**
     * Create a proxy authorization record for a proxy node
     *
     * The principal delegates scoped authority to a proxy node. The scope
     * defines what the proxy is allowed to do on the principal's behalf.
     *
     * @param string $proxyPubkeyHash Public key hash of the proxy node
     * @param array $scope Authorization scope with keys:
     *   - action_types: string[] — authorized action types (e.g. ['send', 'receive'])
     *   - max_per_tx_amount: int — maximum amount per individual transaction
     *   - max_aggregate_amount: int — maximum aggregate amount per time period
     *   - aggregate_period_seconds: int — time period for aggregate limit
     *   - currencies: string[] — authorized currencies (e.g. ['USD', 'EUR'])
     *   - expires_at: string — ISO 8601 expiration timestamp
     * @return array Result with keys: success (bool), authorization_id (string|null), signature (string|null), error (string|null)
     */
    public function createAuthorization(string $proxyPubkeyHash, array $scope): array;

    /**
     * Get an authorization record by ID
     *
     * @param string $authorizationId The authorization UUID
     * @return array|null The authorization record, or null if not found
     */
    public function getAuthorization(string $authorizationId): ?array;

    /**
     * Revoke a proxy authorization
     *
     * Only the principal who created the authorization can revoke it.
     * Revocation is immediate; any in-flight proxy transactions become invalid.
     *
     * @param string $authorizationId The authorization UUID to revoke
     * @return array Result with keys: success (bool), error (string|null)
     */
    public function revokeAuthorization(string $authorizationId): array;

    /**
     * List all active proxy authorizations where this node is the principal
     *
     * @return array List of active authorization records
     */
    public function getActiveAuthorizations(): array;

    /**
     * List all active proxy roles where this node serves as the proxy
     *
     * @return array List of active authorization records where this node is the proxy
     */
    public function getActiveProxyRoles(): array;

    /**
     * Create a shadow copy of the principal's bilateral balances for proxy use
     *
     * Snapshots the principal's current bilateral balances (per contact, per currency)
     * so the proxy can operate against them while the principal is offline.
     *
     * @param string $authorizationId The authorization UUID
     * @return array Result with keys: success (bool), snapshot_count (int|null), error (string|null)
     */
    public function snapshotBalances(string $authorizationId): array;

    /**
     * Validate a proposed action against the authorization scope and shadow balance
     *
     * Checks: authorization active/not expired, action type permitted, amount within
     * per-tx limit, aggregate within period limit, currency authorized, shadow balance
     * has sufficient credit.
     *
     * @param string $authorizationId The authorization UUID
     * @param string $actionType The action type (e.g. 'send')
     * @param float $amount The transaction amount
     * @param string $currency The currency code
     * @return array Result with keys: valid (bool), reason (string)
     */
    public function validateProxyAction(string $authorizationId, string $actionType, float $amount, string $currency): array;

    /**
     * Execute a transaction on behalf of the principal within authorization scope
     *
     * Validates the action first, then creates a proxy transaction record with
     * the proxy's signature and a reference to the authorization. Updates the
     * shadow balance accordingly.
     *
     * @param string $authorizationId The authorization UUID
     * @param array $transactionData Transaction data with keys:
     *   - contact_pubkey_hash: string — counterparty public key hash
     *   - amount: int — transaction amount
     *   - currency: string — currency code
     *   - action_type: string — action type (e.g. 'send')
     *   - chain_previous_txid: string|null — previous transaction ID for chain linkage
     * @return array Result with keys: success (bool), proxy_transaction_id (string|null), error (string|null)
     */
    public function executeProxyTransaction(string $authorizationId, array $transactionData): array;

    /**
     * Sync proxy-executed transactions back to the principal on reconnection
     *
     * Packages all proxy transactions for this authorization with their signatures,
     * authorization references, and chain linkage for the principal to integrate.
     *
     * @param string $authorizationId The authorization UUID
     * @return array Result with keys: success (bool), transactions (array), count (int), error (string|null)
     */
    public function syncBackTransactions(string $authorizationId): array;

    /**
     * Principal integrates proxy transactions into their local transaction chain
     *
     * For each proxy transaction: verifies it against the authorization scope,
     * inserts into the principal's local chain with proper previous_txid linkage,
     * and verifies counterparty signatures.
     *
     * @param string $authorizationId The authorization UUID
     * @param array $proxyTransactions Array of proxy transaction records to integrate
     * @return array Result with keys: success (bool), integrated_count (int), flagged_count (int), errors (array)
     */
    public function integratProxyTransactions(string $authorizationId, array $proxyTransactions): array;

    /**
     * Flag an out-of-scope proxy transaction for dispute (Claim 11)
     *
     * When the principal reviews proxy transactions after reconnection and finds
     * one that exceeds the authorized scope, they can flag it for dispute.
     *
     * @param string $proxyTransactionId The proxy transaction UUID
     * @param string $reason Human-readable dispute reason
     * @return array Result with keys: success (bool), status (string|null), error (string|null)
     */
    public function flagProxyTransaction(string $proxyTransactionId, string $reason): array;

    // ========================================================================
    // Claim 10 — Multiple proxies + first-commit resolution
    // ========================================================================

    /**
     * Resolve a conflict when multiple proxies execute for the same discovery
     *
     * Uses first-commit-wins: the proxy transaction with the earliest executed_at
     * timestamp wins. Later transactions for the same discovery hash are marked
     * as conflicted.
     *
     * @param string $discoveryHash The P2P routing discovery hash
     * @return array Result with keys: success (bool), winning_transaction_id (string|null), conflicted_count (int), error (string|null)
     */
    public function resolveConflict(string $discoveryHash): array;

    // ========================================================================
    // Claim 15 — Shadow balance verification
    // ========================================================================

    /**
     * Get the current shadow balance for a specific contact and currency
     *
     * Returns the snapshot balance adjusted for any proxy transactions executed
     * since the snapshot was taken.
     *
     * @param string $authorizationId The authorization UUID
     * @param string $contactPubkeyHash The contact's public key hash
     * @param string $currency The currency code
     * @return array|null Shadow balance with keys: credit_limit, available_credit, current_balance, adjusted_available_credit; or null if not found
     */
    public function getShadowBalance(string $authorizationId, string $contactPubkeyHash, string $currency): ?array;
}
