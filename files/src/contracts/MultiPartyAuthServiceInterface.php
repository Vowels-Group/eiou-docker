<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Multi-Party Authorization Service Interface (Patent Claims 51-56)
 *
 * M-of-N threshold authorization for high-value or sensitive transactions.
 * Operates entirely within a single node — counterparties never know
 * multi-party auth was used. The auth layer sits between transaction
 * proposal and the signing step.
 *
 * Flow:
 * 1. Transaction proposed → policy engine checks if auth required
 * 2. If required → auth request created with transaction hash
 * 3. Request distributed to N authorized parties
 * 4. Each party reviews and submits signed approval/rejection
 * 5. Upon M approvals → transaction executed
 * 6. Upon expiration or enough rejections → transaction rejected
 */
interface MultiPartyAuthServiceInterface
{
    /**
     * Check if a transaction requires multi-party authorization
     *
     * @param array $transaction Transaction details (amount, currency, type, contact)
     * @return array|null Policy details if auth required, null if not
     */
    public function requiresAuthorization(array $transaction): ?array;

    /**
     * Create an authorization request for a transaction
     *
     * @param array $transaction Full transaction data
     * @param array $policy The matching policy
     * @return array ['request_id' => string, 'required_m' => int, 'expires_at' => string]
     */
    public function createRequest(array $transaction, array $policy): array;

    /**
     * Submit an approval or rejection for an auth request
     *
     * @param string $requestId UUID of the request
     * @param string $approverPubkey Public key of the approver
     * @param string $signature Signature over (request_id + transaction_hash)
     * @param bool $approved True = approve, false = reject
     * @param string|null $reason Optional reason for rejection
     * @return array ['status' => 'pending'|'approved'|'rejected', 'approvals' => int, 'required' => int]
     */
    public function submitApproval(string $requestId, string $approverPubkey, string $signature, bool $approved, ?string $reason = null): array;

    /**
     * Get pending authorization requests
     *
     * @return array List of pending requests
     */
    public function getPendingRequests(): array;

    /**
     * Get details of a specific request including approval status
     *
     * @param string $requestId
     * @return array|null
     */
    public function getRequest(string $requestId): ?array;

    /**
     * Emergency override — bypass normal threshold with emergency key (Claim 55)
     * Mandatory audit trail.
     *
     * @param string $requestId
     * @param string $emergencyPubkey Emergency key
     * @param string $signature
     * @return array ['status' => 'approved', 'override' => true]
     */
    public function emergencyOverride(string $requestId, string $emergencyPubkey, string $signature): array;

    /**
     * Initiate key replacement when an authorized party loses their key (Claim 54)
     * Requires (M-1) of remaining (N-1) to approve.
     *
     * @param int $policyId
     * @param string $lostPubkey Key to replace
     * @param string $newPubkey Replacement key
     * @return string Recovery request ID
     */
    public function initiateKeyReplacement(int $policyId, string $lostPubkey, string $newPubkey): string;

    /**
     * Expire stale authorization requests
     *
     * @return int Number of requests expired
     */
    public function expireStaleRequests(): int;

    // --- Policy Management ---

    /**
     * Create an authorization policy
     *
     * @param array $policy ['name', 'scope_type', 'scope_value', 'threshold_m', 'threshold_n']
     * @return int Policy ID
     */
    public function createPolicy(array $policy): int;

    /**
     * Add an authorized key to a policy
     *
     * @param int $policyId
     * @param string $publicKey
     * @param string $label e.g. 'CEO', 'CFO'
     * @param bool $isEmergency
     * @return int Key ID
     */
    public function addAuthorizedKey(int $policyId, string $publicKey, string $label, bool $isEmergency = false): int;

    /**
     * Remove an authorized key
     *
     * @param int $keyId
     * @return bool
     */
    public function removeAuthorizedKey(int $keyId): bool;
}
