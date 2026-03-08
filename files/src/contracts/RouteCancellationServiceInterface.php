<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Route Cancellation Service Interface
 *
 * Defines the contract for cancelling unselected P2P routes and releasing
 * reserved credit capacity, plus randomized hop budget generation.
 *
 * Patent Claim 16: "the payer node transmitting a cancellation RP2P message
 * along each unselected route, referencing the original discovery message
 * identifier, thereby releasing reserved credit capacity."
 *
 * Patent Claim 5: "the hop budget value is initialized using a randomization
 * algorithm producing values from a non-uniform distribution within a bounded
 * range, preventing traffic analysis attacks based on observed hop counts."
 */
interface RouteCancellationServiceInterface
{
    // =========================================================================
    // Claim 16: Route Cancellation
    // =========================================================================

    /**
     * Cancel all unselected routes for a discovery hash
     *
     * After the payer selects the best route, this method transmits a
     * cancellation RP2P message along each unselected route, referencing
     * the original discovery message identifier, thereby releasing reserved
     * credit capacity at each intermediary node.
     *
     * @param string $hash The discovery message hash identifier
     * @param string $selectedCandidateId The ID of the selected (winning) candidate
     * @return array {cancelled_count: int, routes: [{candidate_id, contact_address, status}...]}
     */
    public function cancelUnselectedRoutes(string $hash, string $selectedCandidateId): array;

    /**
     * Handle an incoming cancellation message at an intermediary node
     *
     * When an intermediary node receives a cancellation RP2P message, it
     * releases any locally reserved credit capacity, propagates the
     * cancellation downstream to any contacts it forwarded to, and marks
     * its local P2P record as cancelled.
     *
     * @param array $request The cancellation message payload containing hash and metadata
     * @return void
     */
    public function handleCancellationMessage(array $request): void;

    /**
     * Release credit capacity reserved for an unselected route
     *
     * Restores the reserved amount back to available credit in the
     * contact_credit table and removes the reservation record.
     *
     * @param string $hash The discovery message hash identifier
     * @param string $contactPubkeyHash The pubkey hash of the contact whose capacity to release
     * @return array {released_amount: int, new_available_credit: int}
     */
    public function releaseReservedCapacity(string $hash, string $contactPubkeyHash): array;

    /**
     * Get all cancelled routes for a discovery hash
     *
     * Returns the list of routes that were cancelled, including timestamps
     * and current status of each cancellation.
     *
     * @param string $hash The discovery message hash identifier
     * @return array List of cancelled routes with timestamps and status
     */
    public function getCancelledRoutes(string $hash): array;

    // =========================================================================
    // Claim 5: Randomized Hop Budget
    // =========================================================================

    /**
     * Generate a randomized hop budget from a non-uniform distribution
     *
     * Uses a geometric distribution (probability p=0.3 of stopping at each hop)
     * to produce values biased toward lower hops while still allowing higher
     * values. The bounded range [$minHops, $maxHops] prevents both too-short
     * routes (which reduce anonymity) and too-long routes (which increase
     * latency and fees). This non-uniform distribution prevents traffic
     * analysis attacks based on observed hop counts.
     *
     * @param int $minHops Minimum hop budget (lower bound of range)
     * @param int $maxHops Maximum hop budget (upper bound of range)
     * @return int The randomized hop budget value within [$minHops, $maxHops]
     */
    public function generateRandomizedHopBudget(int $minHops, int $maxHops): int;

    /**
     * Decrement the hop budget by one
     *
     * Returns the new budget value after decrementing. If the budget is
     * already exhausted (0), returns 0 to signal that the message should
     * not be forwarded further.
     *
     * @param int $currentBudget The current hop budget value
     * @return int The decremented budget value, minimum 0
     */
    public function decrementHopBudget(int $currentBudget): int;
}
