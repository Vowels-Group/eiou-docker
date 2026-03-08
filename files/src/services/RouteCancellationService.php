<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\RouteCancellationServiceInterface;
use PDO;
use PDOException;
use Exception;

/**
 * Route Cancellation Service
 *
 * Implements cancellation of unselected P2P routes to release reserved credit
 * capacity, and randomized hop budget generation for traffic analysis prevention.
 *
 * Patent Claim 16: After the payer selects the best route from RP2P candidates,
 * this service transmits cancellation RP2P messages along each unselected route,
 * referencing the original discovery message identifier. Each intermediary node
 * along the cancelled route releases its reserved credit capacity, restoring it
 * to available credit for future transactions.
 *
 * Patent Claim 5: Hop budget values are initialized using a geometric distribution
 * (non-uniform) within a bounded range, preventing traffic analysis attacks based
 * on observed hop counts. The distribution biases toward lower hops while allowing
 * higher values, balancing anonymity with routing efficiency.
 */
class RouteCancellationService implements RouteCancellationServiceInterface {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * @var Logger Logger instance
     */
    private Logger $logger;

    /**
     * @var float Geometric distribution stopping probability per hop
     *
     * At each hop beyond minHops, there is a 30% chance of stopping.
     * This produces a non-uniform distribution biased toward lower values.
     */
    private const HOP_STOP_PROBABILITY = 30;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     * @param Logger $logger Logger instance
     */
    public function __construct(PDO $pdo, Logger $logger) {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    // =========================================================================
    // Claim 16: Route Cancellation
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function cancelUnselectedRoutes(string $hash, string $selectedCandidateId): array {
        $cancelledRoutes = [];
        $cancelledCount = 0;

        try {
            // Query rp2p_candidates for this hash, excluding the selected candidate
            $stmt = $this->pdo->prepare(
                "SELECT id, sender_address, sender_public_key, amount, currency
                 FROM rp2p_candidates
                 WHERE hash = :hash AND id != :selected_id"
            );
            $stmt->execute([
                ':hash' => $hash,
                ':selected_id' => $selectedCandidateId,
            ]);
            $unselectedCandidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($unselectedCandidates)) {
                $this->logger->info('RouteCancellation: No unselected routes to cancel', [
                    'hash' => $hash,
                    'selected_candidate_id' => $selectedCandidateId,
                ]);
                return ['cancelled_count' => 0, 'routes' => []];
            }

            $this->pdo->beginTransaction();

            foreach ($unselectedCandidates as $candidate) {
                try {
                    // Build cancellation message referencing the original discovery hash
                    $cancellationMessage = [
                        'type' => 'rp2p_cancellation',
                        'hash' => $hash,
                        'candidate_id' => $candidate['id'],
                        'reason' => 'route_not_selected',
                    ];

                    // Store cancellation record
                    $insertStmt = $this->pdo->prepare(
                        "INSERT INTO route_cancellations
                         (hash, candidate_id, contact_pubkey_hash, contact_address, released_amount, currency, status)
                         VALUES (:hash, :candidate_id, :contact_pubkey_hash, :contact_address, :released_amount, :currency, 'sent')"
                    );

                    // Derive pubkey hash from the candidate's sender public key
                    $contactPubkeyHash = hash('sha256', $candidate['sender_public_key']);

                    $insertStmt->execute([
                        ':hash' => $hash,
                        ':candidate_id' => $candidate['id'],
                        ':contact_pubkey_hash' => $contactPubkeyHash,
                        ':contact_address' => $candidate['sender_address'],
                        ':released_amount' => $candidate['amount'],
                        ':currency' => $candidate['currency'],
                    ]);

                    $status = 'sent';
                    $cancelledCount++;

                    $cancelledRoutes[] = [
                        'candidate_id' => $candidate['id'],
                        'contact_address' => $candidate['sender_address'],
                        'status' => $status,
                    ];

                    $this->logger->info('RouteCancellation: Cancellation queued for unselected route', [
                        'hash' => $hash,
                        'candidate_id' => $candidate['id'],
                        'contact_address' => $candidate['sender_address'],
                    ]);
                } catch (Exception $e) {
                    $cancelledRoutes[] = [
                        'candidate_id' => $candidate['id'],
                        'contact_address' => $candidate['sender_address'],
                        'status' => 'failed',
                    ];

                    $this->logger->error('RouteCancellation: Failed to cancel route', [
                        'hash' => $hash,
                        'candidate_id' => $candidate['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger->error('RouteCancellation: Database error during route cancellation', [
                'hash' => $hash,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger->info('RouteCancellation: Completed cancellation of unselected routes', [
            'hash' => $hash,
            'selected_candidate_id' => $selectedCandidateId,
            'cancelled_count' => $cancelledCount,
            'total_unselected' => count($unselectedCandidates),
        ]);

        return [
            'cancelled_count' => $cancelledCount,
            'routes' => $cancelledRoutes,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function handleCancellationMessage(array $request): void {
        $hash = $request['hash'] ?? null;

        if ($hash === null) {
            $this->logger->error('RouteCancellation: Received cancellation message without hash');
            return;
        }

        try {
            $this->pdo->beginTransaction();

            // Look up local P2P record for this hash
            $stmt = $this->pdo->prepare(
                "SELECT id, status, sender_address, currency, amount
                 FROM p2p
                 WHERE hash = :hash"
            );
            $stmt->execute([':hash' => $hash]);
            $p2p = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$p2p) {
                $this->pdo->commit();
                $this->logger->warning('RouteCancellation: No local P2P record found for cancellation', [
                    'hash' => $hash,
                ]);
                return;
            }

            // Release any locally reserved capacity
            $reservationStmt = $this->pdo->prepare(
                "SELECT id, contact_pubkey_hash, reserved_amount, currency
                 FROM capacity_reservations
                 WHERE hash = :hash AND status = 'active'"
            );
            $reservationStmt->execute([':hash' => $hash]);
            $reservations = $reservationStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($reservations as $reservation) {
                // Restore reserved amount to available credit
                $updateCreditStmt = $this->pdo->prepare(
                    "UPDATE contact_credit
                     SET available_credit = available_credit + :amount
                     WHERE pubkey_hash = :pubkey_hash AND currency = :currency"
                );
                $updateCreditStmt->execute([
                    ':amount' => $reservation['reserved_amount'],
                    ':pubkey_hash' => $reservation['contact_pubkey_hash'],
                    ':currency' => $reservation['currency'],
                ]);

                // Mark reservation as released
                $releaseStmt = $this->pdo->prepare(
                    "UPDATE capacity_reservations
                     SET status = 'released', released_at = CURRENT_TIMESTAMP(6)
                     WHERE id = :id"
                );
                $releaseStmt->execute([':id' => $reservation['id']]);

                $this->logger->info('RouteCancellation: Released reserved capacity', [
                    'hash' => $hash,
                    'contact_pubkey_hash' => $reservation['contact_pubkey_hash'],
                    'released_amount' => $reservation['reserved_amount'],
                    'currency' => $reservation['currency'],
                ]);
            }

            // If this node forwarded the P2P to downstream contacts, propagate cancellation
            $downstreamStmt = $this->pdo->prepare(
                "SELECT contact_address
                 FROM p2p_relayed_contacts
                 WHERE hash = :hash"
            );
            $downstreamStmt->execute([':hash' => $hash]);
            $downstreamContacts = $downstreamStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($downstreamContacts)) {
                foreach ($downstreamContacts as $downstream) {
                    // Store downstream cancellation record for delivery
                    $insertStmt = $this->pdo->prepare(
                        "INSERT INTO route_cancellations
                         (hash, contact_pubkey_hash, contact_address, released_amount, currency, status)
                         VALUES (:hash, '', :contact_address, 0, :currency, 'sent')"
                    );
                    $insertStmt->execute([
                        ':hash' => $hash,
                        ':contact_address' => $downstream['contact_address'],
                        ':currency' => $p2p['currency'],
                    ]);

                    $this->logger->info('RouteCancellation: Propagating cancellation downstream', [
                        'hash' => $hash,
                        'downstream_address' => $downstream['contact_address'],
                    ]);
                }
            }

            // Mark local P2P record as cancelled
            $cancelStmt = $this->pdo->prepare(
                "UPDATE p2p SET status = 'cancelled' WHERE hash = :hash AND status NOT IN ('paid', 'completed')"
            );
            $cancelStmt->execute([':hash' => $hash]);

            $this->pdo->commit();

            $this->logger->info('RouteCancellation: Successfully handled cancellation message', [
                'hash' => $hash,
                'reservations_released' => count($reservations),
                'downstream_propagated' => count($downstreamContacts),
            ]);
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger->error('RouteCancellation: Database error handling cancellation message', [
                'hash' => $hash,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function releaseReservedCapacity(string $hash, string $contactPubkeyHash): array {
        try {
            $this->pdo->beginTransaction();

            // Look up the active reservation for this hash + contact
            $stmt = $this->pdo->prepare(
                "SELECT id, reserved_amount, currency
                 FROM capacity_reservations
                 WHERE hash = :hash AND contact_pubkey_hash = :pubkey_hash AND status = 'active'"
            );
            $stmt->execute([
                ':hash' => $hash,
                ':pubkey_hash' => $contactPubkeyHash,
            ]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reservation) {
                $this->pdo->commit();
                $this->logger->warning('RouteCancellation: No active reservation found to release', [
                    'hash' => $hash,
                    'contact_pubkey_hash' => $contactPubkeyHash,
                ]);
                return ['released_amount' => 0, 'new_available_credit' => 0];
            }

            $reservedAmount = (int) $reservation['reserved_amount'];
            $currency = $reservation['currency'];

            // Add reserved amount back to available_credit in contact_credit table
            $updateStmt = $this->pdo->prepare(
                "UPDATE contact_credit
                 SET available_credit = available_credit + :amount
                 WHERE pubkey_hash = :pubkey_hash AND currency = :currency"
            );
            $updateStmt->execute([
                ':amount' => $reservedAmount,
                ':pubkey_hash' => $contactPubkeyHash,
                ':currency' => $currency,
            ]);

            // Mark reservation as released
            $releaseStmt = $this->pdo->prepare(
                "UPDATE capacity_reservations
                 SET status = 'released', released_at = CURRENT_TIMESTAMP(6)
                 WHERE id = :id"
            );
            $releaseStmt->execute([':id' => $reservation['id']]);

            // Fetch the new available credit
            $creditStmt = $this->pdo->prepare(
                "SELECT available_credit
                 FROM contact_credit
                 WHERE pubkey_hash = :pubkey_hash AND currency = :currency"
            );
            $creditStmt->execute([
                ':pubkey_hash' => $contactPubkeyHash,
                ':currency' => $currency,
            ]);
            $credit = $creditStmt->fetch(PDO::FETCH_ASSOC);
            $newAvailableCredit = $credit ? (int) $credit['available_credit'] : 0;

            $this->pdo->commit();

            $this->logger->info('RouteCancellation: Released reserved capacity', [
                'hash' => $hash,
                'contact_pubkey_hash' => $contactPubkeyHash,
                'released_amount' => $reservedAmount,
                'new_available_credit' => $newAvailableCredit,
            ]);

            return [
                'released_amount' => $reservedAmount,
                'new_available_credit' => $newAvailableCredit,
            ];
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logger->error('RouteCancellation: Database error releasing reserved capacity', [
                'hash' => $hash,
                'contact_pubkey_hash' => $contactPubkeyHash,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getCancelledRoutes(string $hash): array {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, hash, candidate_id, contact_pubkey_hash, contact_address,
                        released_amount, currency, status, created_at, acknowledged_at
                 FROM route_cancellations
                 WHERE hash = :hash
                 ORDER BY created_at ASC"
            );
            $stmt->execute([':hash' => $hash]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logger->error('RouteCancellation: Database error fetching cancelled routes', [
                'hash' => $hash,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // =========================================================================
    // Claim 5: Randomized Hop Budget
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public function generateRandomizedHopBudget(int $minHops, int $maxHops): int {
        if ($minHops < 0) {
            $minHops = 0;
        }
        if ($maxHops < $minHops) {
            $maxHops = $minHops;
        }

        // Geometric distribution: each hop beyond minHops has probability p=0.3 of stopping.
        // This produces a non-uniform distribution biased toward lower hops but allowing higher,
        // preventing traffic analysis attacks based on observed hop counts.
        $budget = $minHops;
        while ($budget < $maxHops && (random_int(0, 99) >= self::HOP_STOP_PROBABILITY)) {
            $budget++;
        }

        return $budget;
    }

    /**
     * {@inheritdoc}
     */
    public function decrementHopBudget(int $currentBudget): int {
        return max(0, $currentBudget - 1);
    }
}
