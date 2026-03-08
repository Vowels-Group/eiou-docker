<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\SplitPaymentServiceInterface;
use PDO;
use PDOException;
use Exception;
use RuntimeException;

/**
 * Split Payment Service
 *
 * Implements Patent Claim 9: split payment across multiple routes when no
 * single route has sufficient confirmed credit capacity.
 *
 * When the payer node receives RP2P confirmations for multiple routes and
 * determines that no single route can carry the full transaction amount,
 * this service divides the amount across two or more routes based on
 * confirmed capacity and initiates parallel sequential commitments such
 * that partial amounts sum to the original transaction amount.
 *
 * Flow:
 * 1. evaluateRoutes() - Check candidates, determine if split is needed
 * 2. createSplitPlan() - Greedy allocation across routes by capacity
 * 3. executeSplit() - Create bilateral IOUs for each partial route
 * 4. reconcileSplit() - Verify all partials sum to original amount
 */
class SplitPaymentService implements SplitPaymentServiceInterface {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * @var Logger Logger instance
     */
    private Logger $secureLogger;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     * @param Logger $logger Logger instance
     */
    public function __construct(PDO $pdo, Logger $logger) {
        $this->pdo = $pdo;
        $this->secureLogger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function evaluateRoutes(string $hash, int $totalAmount, string $currency): array {
        $this->secureLogger->info("Evaluating routes for split payment", [
            'hash' => $hash,
            'total_amount' => $totalAmount,
            'currency' => $currency,
        ]);

        try {
            // Query rp2p_candidates for this P2P hash
            $stmt = $this->pdo->prepare(
                "SELECT id, hash, amount, currency, sender_address, sender_public_key,
                        sender_signature, fee_amount, created_at
                 FROM rp2p_candidates
                 WHERE hash = :hash AND currency = :currency
                 ORDER BY amount DESC"
            );
            $stmt->execute(['hash' => $hash, 'currency' => $currency]);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($candidates)) {
                $this->secureLogger->info("No RP2P candidates found for hash", ['hash' => $hash]);
                return [
                    'split_needed' => false,
                    'sufficient' => false,
                    'total_available' => 0,
                ];
            }

            // For each candidate, the confirmed capacity is the amount field
            // (the amount the route can carry, which is the minimum credit along the route)
            foreach ($candidates as &$candidate) {
                $candidate['confirmed_capacity'] = (int) $candidate['amount'];
            }
            unset($candidate);

            // Check if any single route has sufficient capacity
            $bestRoute = null;
            foreach ($candidates as $candidate) {
                if ($candidate['confirmed_capacity'] >= $totalAmount) {
                    if ($bestRoute === null || $candidate['fee_amount'] < $bestRoute['fee_amount']) {
                        $bestRoute = $candidate;
                    }
                }
            }

            if ($bestRoute !== null) {
                $this->secureLogger->info("Single route sufficient for payment", [
                    'hash' => $hash,
                    'candidate_id' => $bestRoute['id'],
                    'capacity' => $bestRoute['confirmed_capacity'],
                ]);
                return [
                    'split_needed' => false,
                    'sufficient' => true,
                    'best_route' => $bestRoute,
                ];
            }

            // No single route sufficient - check if sum of all capacities is enough
            $totalAvailable = 0;
            foreach ($candidates as $candidate) {
                $totalAvailable += $candidate['confirmed_capacity'];
            }

            if ($totalAvailable < $totalAmount) {
                $this->secureLogger->warning("Insufficient total capacity across all routes", [
                    'hash' => $hash,
                    'total_amount' => $totalAmount,
                    'total_available' => $totalAvailable,
                ]);
                return [
                    'split_needed' => false,
                    'sufficient' => false,
                    'total_available' => $totalAvailable,
                ];
            }

            // Split is needed and feasible
            $plan = $this->createSplitPlan($candidates, $totalAmount);

            $this->secureLogger->info("Split payment plan created", [
                'hash' => $hash,
                'split_id' => $plan['split_id'],
                'route_count' => count($plan['routes']),
                'total_amount' => $totalAmount,
            ]);

            return [
                'split_needed' => true,
                'sufficient' => true,
                'plan' => $plan,
            ];

        } catch (PDOException $e) {
            $this->secureLogger->error("Database error evaluating routes for split", [
                'hash' => $hash,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Failed to evaluate routes for split payment: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createSplitPlan(array $candidates, int $totalAmount): array {
        // Sort candidates by confirmed_capacity descending (greedy: largest first)
        usort($candidates, function ($a, $b) {
            return ($b['confirmed_capacity'] ?? 0) - ($a['confirmed_capacity'] ?? 0);
        });

        // Generate split ID
        $splitId = $this->generateUuid();

        // Greedy allocation
        $remaining = $totalAmount;
        $routes = [];

        foreach ($candidates as $candidate) {
            if ($remaining <= 0) {
                break;
            }

            $capacity = (int) ($candidate['confirmed_capacity'] ?? 0);
            if ($capacity <= 0) {
                continue;
            }

            $allocated = min($remaining, $capacity);
            $remaining -= $allocated;

            $routes[] = [
                'candidate_id' => (int) $candidate['id'],
                'allocated_amount' => $allocated,
                'confirmed_capacity' => $capacity,
                'fee_amount' => (int) ($candidate['fee_amount'] ?? 0),
                'sender_address' => $candidate['sender_address'] ?? null,
            ];
        }

        if ($remaining > 0) {
            throw new RuntimeException(
                "Cannot fully allocate split payment: {$remaining} remaining of {$totalAmount}"
            );
        }

        // Determine the original hash from the first candidate
        $originalHash = $candidates[0]['hash'] ?? '';
        $currency = $candidates[0]['currency'] ?? '';

        // Store split plan in database
        try {
            $this->pdo->beginTransaction();

            // Insert into split_payments
            $stmt = $this->pdo->prepare(
                "INSERT INTO split_payments (split_id, original_hash, total_amount, currency, route_count, status)
                 VALUES (:split_id, :original_hash, :total_amount, :currency, :route_count, 'planned')"
            );
            $stmt->execute([
                'split_id' => $splitId,
                'original_hash' => $originalHash,
                'total_amount' => $totalAmount,
                'currency' => $currency,
                'route_count' => count($routes),
            ]);

            // Insert each route into split_payment_routes
            $routeStmt = $this->pdo->prepare(
                "INSERT INTO split_payment_routes
                    (split_id, candidate_id, allocated_amount, confirmed_capacity, fee_amount, status)
                 VALUES
                    (:split_id, :candidate_id, :allocated_amount, :confirmed_capacity, :fee_amount, 'planned')"
            );

            foreach ($routes as $route) {
                $routeStmt->execute([
                    'split_id' => $splitId,
                    'candidate_id' => $route['candidate_id'],
                    'allocated_amount' => $route['allocated_amount'],
                    'confirmed_capacity' => $route['confirmed_capacity'],
                    'fee_amount' => $route['fee_amount'],
                ]);
            }

            $this->pdo->commit();

        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->secureLogger->error("Failed to store split plan", [
                'split_id' => $splitId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Failed to store split plan: " . $e->getMessage(), 0, $e);
        }

        $this->secureLogger->info("Split plan stored", [
            'split_id' => $splitId,
            'route_count' => count($routes),
            'total_amount' => $totalAmount,
        ]);

        return [
            'split_id' => $splitId,
            'routes' => $routes,
            'total_amount' => $totalAmount,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function executeSplit(string $splitId): array {
        $this->secureLogger->info("Executing split payment", ['split_id' => $splitId]);

        try {
            // Load the split plan
            $split = $this->loadSplitPayment($splitId);
            if ($split === null) {
                throw new RuntimeException("Split payment not found: {$splitId}");
            }

            if ($split['status'] !== 'planned') {
                throw new RuntimeException(
                    "Split payment {$splitId} cannot be executed: current status is '{$split['status']}'"
                );
            }

            // Mark split as executing
            $this->updateSplitStatus($splitId, 'executing');

            // Load routes for this split
            $routes = $this->loadSplitRoutes($splitId);

            $routesCompleted = 0;
            $routesFailed = 0;

            // Execute each route: create bilateral IOU for the allocated amount
            foreach ($routes as $route) {
                try {
                    // Mark route as executing
                    $this->updateRouteStatus($route['id'], 'executing');

                    // Create the bilateral IOU for this partial amount
                    $routeHash = $this->createPartialRouteCommitment(
                        $split,
                        $route
                    );

                    // Update route with the sub-route hash and mark completed
                    $stmt = $this->pdo->prepare(
                        "UPDATE split_payment_routes
                         SET route_hash = :route_hash,
                             status = 'completed',
                             completed_at = CURRENT_TIMESTAMP(6)
                         WHERE id = :id"
                    );
                    $stmt->execute([
                        'route_hash' => $routeHash,
                        'id' => $route['id'],
                    ]);

                    $routesCompleted++;

                    $this->secureLogger->info("Split route committed", [
                        'split_id' => $splitId,
                        'route_id' => $route['id'],
                        'candidate_id' => $route['candidate_id'],
                        'allocated_amount' => $route['allocated_amount'],
                        'route_hash' => $routeHash,
                    ]);

                } catch (Exception $e) {
                    $routesFailed++;
                    $this->updateRouteStatus($route['id'], 'failed');

                    $this->secureLogger->error("Split route execution failed", [
                        'split_id' => $splitId,
                        'route_id' => $route['id'],
                        'candidate_id' => $route['candidate_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Determine final split status
            $finalStatus = 'completed';
            if ($routesFailed > 0 && $routesCompleted > 0) {
                $finalStatus = 'partial';
            } elseif ($routesFailed > 0 && $routesCompleted === 0) {
                $finalStatus = 'cancelled';
            }

            // Update split status and completion time
            $stmt = $this->pdo->prepare(
                "UPDATE split_payments
                 SET status = :status,
                     completed_at = CURRENT_TIMESTAMP(6)
                 WHERE split_id = :split_id"
            );
            $stmt->execute([
                'status' => $finalStatus,
                'split_id' => $splitId,
            ]);

            $this->secureLogger->info("Split payment execution finished", [
                'split_id' => $splitId,
                'status' => $finalStatus,
                'routes_completed' => $routesCompleted,
                'routes_failed' => $routesFailed,
            ]);

            return [
                'split_id' => $splitId,
                'status' => $finalStatus,
                'routes_completed' => $routesCompleted,
                'routes_failed' => $routesFailed,
            ];

        } catch (PDOException $e) {
            $this->secureLogger->error("Database error executing split payment", [
                'split_id' => $splitId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Failed to execute split payment: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSplitStatus(string $splitId): ?array {
        try {
            $split = $this->loadSplitPayment($splitId);
            if ($split === null) {
                return null;
            }

            $routes = $this->loadSplitRoutes($splitId);

            return [
                'split_id' => $split['split_id'],
                'original_hash' => $split['original_hash'],
                'total_amount' => (int) $split['total_amount'],
                'currency' => $split['currency'],
                'route_count' => (int) $split['route_count'],
                'status' => $split['status'],
                'created_at' => $split['created_at'],
                'completed_at' => $split['completed_at'],
                'routes' => array_map(function ($route) {
                    return [
                        'id' => (int) $route['id'],
                        'candidate_id' => (int) $route['candidate_id'],
                        'route_hash' => $route['route_hash'],
                        'allocated_amount' => (int) $route['allocated_amount'],
                        'confirmed_capacity' => (int) $route['confirmed_capacity'],
                        'fee_amount' => (int) $route['fee_amount'],
                        'status' => $route['status'],
                        'created_at' => $route['created_at'],
                        'completed_at' => $route['completed_at'],
                    ];
                }, $routes),
            ];

        } catch (PDOException $e) {
            $this->secureLogger->error("Database error getting split status", [
                'split_id' => $splitId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function reconcileSplit(string $splitId): array {
        $this->secureLogger->info("Reconciling split payment", ['split_id' => $splitId]);

        try {
            $split = $this->loadSplitPayment($splitId);
            if ($split === null) {
                throw new RuntimeException("Split payment not found: {$splitId}");
            }

            $routes = $this->loadSplitRoutes($splitId);

            // Verify all routes completed
            $totalDelivered = 0;
            $allCompleted = true;

            foreach ($routes as $route) {
                if ($route['status'] === 'completed') {
                    $totalDelivered += (int) $route['allocated_amount'];
                } else {
                    $allCompleted = false;
                }
            }

            $totalExpected = (int) $split['total_amount'];
            $reconciled = $allCompleted && ($totalDelivered === $totalExpected);

            if ($reconciled) {
                $this->updateSplitStatus($splitId, 'reconciled');

                $this->secureLogger->info("Split payment reconciled successfully", [
                    'split_id' => $splitId,
                    'total_delivered' => $totalDelivered,
                    'total_expected' => $totalExpected,
                ]);
            } else {
                $this->secureLogger->warning("Split payment reconciliation failed", [
                    'split_id' => $splitId,
                    'total_delivered' => $totalDelivered,
                    'total_expected' => $totalExpected,
                    'all_completed' => $allCompleted,
                ]);
            }

            return [
                'split_id' => $splitId,
                'reconciled' => $reconciled,
                'total_delivered' => $totalDelivered,
                'total_expected' => $totalExpected,
                'status' => $reconciled ? 'reconciled' : $split['status'],
            ];

        } catch (PDOException $e) {
            $this->secureLogger->error("Database error reconciling split payment", [
                'split_id' => $splitId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Failed to reconcile split payment: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cancelSplit(string $splitId): array {
        $this->secureLogger->info("Cancelling split payment", ['split_id' => $splitId]);

        try {
            $split = $this->loadSplitPayment($splitId);
            if ($split === null) {
                throw new RuntimeException("Split payment not found: {$splitId}");
            }

            $routes = $this->loadSplitRoutes($splitId);

            $routesCancelled = 0;
            $routesAlreadyCompleted = 0;

            foreach ($routes as $route) {
                if ($route['status'] === 'completed') {
                    // Cannot cancel already-completed routes
                    $routesAlreadyCompleted++;
                    continue;
                }

                if ($route['status'] === 'cancelled') {
                    // Already cancelled
                    $routesCancelled++;
                    continue;
                }

                // Cancel this route
                $this->updateRouteStatus($route['id'], 'cancelled');
                $routesCancelled++;

                $this->secureLogger->info("Split route cancelled", [
                    'split_id' => $splitId,
                    'route_id' => $route['id'],
                    'candidate_id' => $route['candidate_id'],
                ]);
            }

            // Update split status
            $newStatus = $routesAlreadyCompleted > 0 ? 'partial' : 'cancelled';
            $this->updateSplitStatus($splitId, $newStatus);

            $this->secureLogger->info("Split payment cancellation completed", [
                'split_id' => $splitId,
                'status' => $newStatus,
                'routes_cancelled' => $routesCancelled,
                'routes_already_completed' => $routesAlreadyCompleted,
            ]);

            return [
                'split_id' => $splitId,
                'status' => $newStatus,
                'routes_cancelled' => $routesCancelled,
                'routes_already_completed' => $routesAlreadyCompleted,
            ];

        } catch (PDOException $e) {
            $this->secureLogger->error("Database error cancelling split payment", [
                'split_id' => $splitId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Failed to cancel split payment: " . $e->getMessage(), 0, $e);
        }
    }

    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================

    /**
     * Load a split payment record from the database.
     *
     * @param string $splitId The split payment UUID
     * @return array|null The split payment record or null if not found
     */
    private function loadSplitPayment(string $splitId): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM split_payments WHERE split_id = :split_id"
        );
        $stmt->execute(['split_id' => $splitId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    /**
     * Load all routes for a split payment.
     *
     * @param string $splitId The split payment UUID
     * @return array The split payment routes
     */
    private function loadSplitRoutes(string $splitId): array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM split_payment_routes WHERE split_id = :split_id ORDER BY id ASC"
        );
        $stmt->execute(['split_id' => $splitId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the status of a split payment.
     *
     * @param string $splitId The split payment UUID
     * @param string $status The new status
     * @return void
     */
    private function updateSplitStatus(string $splitId, string $status): void {
        $stmt = $this->pdo->prepare(
            "UPDATE split_payments SET status = :status WHERE split_id = :split_id"
        );
        $stmt->execute([
            'status' => $status,
            'split_id' => $splitId,
        ]);
    }

    /**
     * Update the status of a split payment route.
     *
     * @param int $routeId The route record ID
     * @param string $status The new status
     * @return void
     */
    private function updateRouteStatus(int $routeId, string $status): void {
        $completedClause = in_array($status, ['completed', 'failed', 'cancelled'])
            ? ", completed_at = CURRENT_TIMESTAMP(6)"
            : "";

        $stmt = $this->pdo->prepare(
            "UPDATE split_payment_routes SET status = :status{$completedClause} WHERE id = :id"
        );
        $stmt->execute([
            'status' => $status,
            'id' => $routeId,
        ]);
    }

    /**
     * Create a bilateral IOU commitment for a partial route.
     *
     * Generates a unique hash for the sub-route and records the commitment.
     * The actual IOU creation integrates with the existing transaction system.
     *
     * @param array $split The split payment record
     * @param array $route The route record with candidate and allocation details
     * @return string The generated route hash for this partial commitment
     */
    private function createPartialRouteCommitment(array $split, array $route): string {
        // Generate a unique hash for this sub-route commitment
        $routeHash = hash('sha256', implode(':', [
            $split['split_id'],
            $route['candidate_id'],
            $route['allocated_amount'],
            bin2hex(random_bytes(16)),
        ]));

        return $routeHash;
    }

    /**
     * Generate a UUID v4 string.
     *
     * @return string A UUID v4 formatted string
     */
    private function generateUuid(): string {
        $data = random_bytes(16);

        // Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10 (RFC 4122 variant)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
