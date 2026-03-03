<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Compliance Service Interface (Patent Claims 28-34)
 *
 * Privacy-preserving regulatory compliance for decentralized trust networks.
 * All identity data stored locally. No centralized identity registry.
 *
 * Identity Tiers:
 * 0 = Unverified (public key only)
 * 1 = Self-Declared (user signs identity claim)
 * 2 = Peer-Attested (contacts vouch)
 * 3 = Document-Verified (government ID validated)
 * 4 = Institutional (licensed FI attests)
 */
interface ComplianceServiceInterface
{
    /**
     * Get my identity verification tier
     *
     * @return int Tier 0-4
     */
    public function getMyTier(): int;

    /**
     * Get a contact's identity verification tier
     *
     * @param string $contactPubkeyHash
     * @return int Tier 0-4
     */
    public function getContactTier(string $contactPubkeyHash): int;

    /**
     * Generate a Compliance Attestation Token (CAT) for RP2P attachment
     *
     * Contains: KYC tier, jurisdiction, sanctions status, timestamp, signature.
     * Does NOT contain raw identity data.
     *
     * @return array The CAT
     */
    public function generateCAT(): array;

    /**
     * Verify a received CAT signature
     *
     * @param array $cat The CAT to verify
     * @return bool
     */
    public function verifyCAT(array $cat): bool;

    /**
     * Evaluate a transaction against jurisdictional policy rules
     *
     * @param array $transaction Transaction data
     * @return array ['allowed' => bool, 'action' => string, 'rule' => string|null]
     */
    public function evaluateTransaction(array $transaction): array;

    /**
     * Check if a route's CAT chain meets compliance requirements
     *
     * @param array $catChain Array of CATs from route intermediaries
     * @param array $requirements ['min_kyc_tier' => int, 'sanctions_required' => bool]
     * @return bool
     */
    public function routeMeetsCompliance(array $catChain, array $requirements): bool;

    /**
     * Run behavioral analytics on a contact (structuring, cycling, velocity)
     *
     * @param string $contactPubkeyHash
     * @return array ['structuring' => float, 'cycling' => float, 'velocity' => float, 'flagged' => bool]
     */
    public function analyzeContact(string $contactPubkeyHash): array;

    /**
     * Create encrypted Travel Rule payload for a transaction
     *
     * @param array $originator Originator info
     * @param array $beneficiary Beneficiary info
     * @param string $recipientPubkey Recipient's public key (for encryption)
     * @return string Encrypted payload
     */
    public function createTravelRulePayload(array $originator, array $beneficiary, string $recipientPubkey): string;

    /**
     * Verify Travel Rule payload presence (intermediary check — cannot decrypt)
     *
     * @param string $encryptedPayload
     * @return bool
     */
    public function verifyTravelRulePresence(string $encryptedPayload): bool;
}
