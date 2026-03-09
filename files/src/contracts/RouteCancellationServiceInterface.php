<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Route Cancellation Service Interface
 *
 * Cancels unselected P2P routes to immediately release reserved credit capacity,
 * and generates randomized hop budgets for traffic analysis prevention.
 *
 * Patent Claim 16: After the payer selects the best route, transmit cancellation
 * messages along each unselected route to release reserved credit capacity.
 *
 * Patent Claim 5: Hop budget values use a non-uniform (geometric) distribution
 * to prevent traffic analysis attacks based on observed hop counts.
 */
interface RouteCancellationServiceInterface
{
    /**
     * Cancel all unselected routes after best route selection
     *
     * Sends cancellation messages to each unselected candidate's contact,
     * logs the cancellation for audit, and releases local capacity reservations.
     *
     * @param string $hash The P2P discovery hash
     * @param string $selectedCandidateId The ID of the selected (winning) candidate
     * @param array $unselectedCandidates Array of candidate records that were not selected
     * @return array {cancelled_count: int, routes: [{candidate_id, contact_address, status}...]}
     */
    public function cancelUnselectedRoutes(string $hash, string $selectedCandidateId, array $unselectedCandidates): array;

    /**
     * Handle an incoming route cancellation message
     *
     * When a relay node receives a cancellation for a P2P it was relaying:
     * 1. Marks its local P2P record as cancelled
     * 2. Releases its capacity reservation
     * 3. Downstream nodes expire naturally via CleanupService
     *
     * @param array $request The cancellation message containing hash
     * @return void
     */
    public function handleIncomingCancellation(array $request): void;

    /**
     * Generate a randomized hop budget from a geometric distribution
     *
     * Uses a non-uniform distribution biased toward lower hops while allowing
     * higher values, preventing traffic analysis based on observed hop counts.
     *
     * @param int $minHops Minimum hop budget
     * @param int $maxHops Maximum hop budget
     * @return int Randomized hop budget within [$minHops, $maxHops]
     */
    public function generateRandomizedHopBudget(int $minHops, int $maxHops): int;

    /**
     * Decrement the hop budget by one
     *
     * @param int $currentBudget Current hop budget value
     * @return int Decremented budget, minimum 0
     */
    public function decrementHopBudget(int $currentBudget): int;
}
