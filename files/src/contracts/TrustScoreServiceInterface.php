<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Trust Score Service Interface (Patent Claims 35-40)
 *
 * Computes multi-dimensional trust scores from exclusively local bilateral data.
 * No global graph knowledge required. Each contact's scores are independent.
 *
 * Five dimensions:
 * 1. Payment Reliability — settlement success rate and timeliness
 * 2. Routing Performance — route success, latency, fee competitiveness, uptime
 * 3. Credit Utilization — peak balance patterns (moderate = healthy)
 * 4. Settlement Timeliness — delay metrics
 * 5. Compliance Standing — KYC/AML posture
 *
 * Trust stages (graduated escalation):
 * 0 = Probationary (new, restricted)
 * 1 = Establishing (30+ days, 5+ txns)
 * 2 = Established (90+ days, 20+ txns, no defaults)
 * 3 = Mature (180+ days, consistent activity)
 */
interface TrustScoreServiceInterface
{
    /**
     * Calculate composite trust score for a contact
     *
     * @param string $contactPubkeyHash Contact's public key hash
     * @return array ['composite' => float, 'confidence' => float, 'dimensions' => array, 'stage' => int]
     */
    public function calculateScore(string $contactPubkeyHash): array;

    /**
     * Get cached trust score (returns last computed, does not recalculate)
     *
     * @param string $contactPubkeyHash
     * @return array|null
     */
    public function getScore(string $contactPubkeyHash): ?array;

    /**
     * Recalculate scores for all contacts
     *
     * @return int Number of contacts scored
     */
    public function recalculateAll(): int;

    /**
     * Get trust stage for a contact (0-3)
     *
     * @param string $contactPubkeyHash
     * @return int
     */
    public function getStage(string $contactPubkeyHash): int;

    /**
     * Apply trust damage from an event (default, dispute, etc.)
     *
     * @param string $contactPubkeyHash
     * @param string $eventType 'default'|'dispute'|'suspicious_activity'
     * @return void
     */
    public function applyDamage(string $contactPubkeyHash, string $eventType): void;

    /**
     * Get network health metrics computed from local data
     *
     * @return array ['routing_reach' => int, 'gini' => float, 'hhi' => float, 'growth_rate' => float]
     */
    public function getNetworkHealth(): array;
}
