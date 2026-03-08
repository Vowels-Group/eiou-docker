<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

/**
 * Split Payment Service Interface
 *
 * Defines the contract for split payment routing services per Patent Claim 9:
 * "the payer node receives RP2P confirmations for multiple routes, determines
 * that no single route has sufficient confirmed credit capacity, divides the
 * transaction amount across two or more routes based on confirmed capacity,
 * and initiates parallel sequential commitments such that partial amounts sum
 * to the original transaction amount."
 *
 * When no single route can carry a full P2P payment, this service evaluates
 * all available RP2P candidates, creates an optimal split plan distributing
 * the amount proportionally across routes, and orchestrates parallel execution
 * of partial commitments.
 */
interface SplitPaymentServiceInterface
{
    /**
     * Evaluate all RP2P candidates for a P2P hash to determine routing strategy.
     *
     * Checks whether any single route has sufficient confirmed credit capacity
     * to carry the full transaction amount. If not, determines whether splitting
     * across multiple routes is feasible and produces a split plan.
     *
     * @param string $hash The P2P discovery hash
     * @param int $totalAmount The total transaction amount to route
     * @param string $currency The transaction currency
     * @return array {
     *     split_needed: bool - Whether the amount must be split across routes,
     *     sufficient: bool - Whether total capacity across all routes is enough,
     *     best_route?: array - Best single route (when split not needed),
     *     plan?: array - Split plan (when split is needed and sufficient),
     *     total_available?: int - Total capacity across all routes (when insufficient)
     * }
     */
    public function evaluateRoutes(string $hash, int $totalAmount, string $currency): array;

    /**
     * Create an optimal split plan allocating the transaction amount across routes.
     *
     * Uses greedy allocation: sorts candidates by confirmed capacity descending,
     * assigns each route min(remaining_amount, route_capacity) until the total
     * amount is fully allocated. Stores the plan in split_payments and
     * split_payment_routes tables.
     *
     * @param array $candidates Array of RP2P candidates with confirmed capacities
     * @param int $totalAmount The total transaction amount to allocate
     * @return array {
     *     split_id: string - UUID identifying this split plan,
     *     routes: array[] - Each containing {candidate_id, allocated_amount, confirmed_capacity},
     *     total_amount: int - The original total amount
     * }
     */
    public function createSplitPlan(array $candidates, int $totalAmount): array;

    /**
     * Execute all route commitments in parallel for a split plan.
     *
     * Loads the split plan from the database and creates a bilateral IOU for
     * each route's allocated amount. Tracks completion status: when all routes
     * succeed the split is marked 'completed'; if any fail it is marked 'partial'.
     *
     * @param string $splitId The UUID of the split plan to execute
     * @return array {
     *     split_id: string,
     *     status: string - 'completed'|'partial'|'failed',
     *     routes_completed: int,
     *     routes_failed: int
     * }
     */
    public function executeSplit(string $splitId): array;

    /**
     * Get the current status of a split payment including all partial routes.
     *
     * @param string $splitId The UUID of the split payment
     * @return array|null The split payment status with route details, or null if not found
     */
    public function getSplitStatus(string $splitId): ?array;

    /**
     * Reconcile a completed split payment to confirm full settlement.
     *
     * Verifies all routes completed successfully and the sum of confirmed
     * delivered amounts equals the original transaction amount. Marks the
     * split as 'reconciled' on success.
     *
     * @param string $splitId The UUID of the split payment to reconcile
     * @return array {
     *     split_id: string,
     *     reconciled: bool,
     *     total_delivered: int,
     *     total_expected: int,
     *     status: string
     * }
     */
    public function reconcileSplit(string $splitId): array;

    /**
     * Cancel an in-progress split payment.
     *
     * Sends cancellation for all uncommitted partial routes and updates
     * the split status accordingly.
     *
     * @param string $splitId The UUID of the split payment to cancel
     * @return array {
     *     split_id: string,
     *     status: string,
     *     routes_cancelled: int,
     *     routes_already_completed: int
     * }
     */
    public function cancelSplit(string $splitId): array;
}
