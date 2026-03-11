<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\RouteCancellationServiceInterface;
use Eiou\Contracts\P2pServiceInterface;
use Eiou\Database\CapacityReservationRepository;
use Eiou\Database\RepositoryFactory;
use Eiou\Database\RouteCancellationRepository;
use Eiou\Database\P2pRepository;
use Eiou\Core\Constants;

class RouteCancellationService implements RouteCancellationServiceInterface {

    private const HOP_STOP_PROBABILITY = 30;

    private ?P2pServiceInterface $p2pService = null;
    private ?CapacityReservationRepository $capacityReservationRepository = null;
    private ?RouteCancellationRepository $routeCancellationRepository = null;
    private ?P2pRepository $p2pRepository = null;

    public function __construct(RepositoryFactory $repositoryFactory)
    {
        $this->capacityReservationRepository = $repositoryFactory->get(\Eiou\Database\CapacityReservationRepository::class);
        $this->routeCancellationRepository = $repositoryFactory->get(\Eiou\Database\RouteCancellationRepository::class);
        $this->p2pRepository = $repositoryFactory->get(\Eiou\Database\P2pRepository::class);
    }

    public function setP2pService(P2pServiceInterface $service): void {
        $this->p2pService = $service;
    }

    public function cancelUnselectedRoutes(string $hash, string $selectedCandidateId, array $unselectedCandidates): array {
        $cancelledRoutes = [];
        $cancelledCount = 0;

        if (empty($unselectedCandidates)) {
            return ['cancelled_count' => 0, 'routes' => []];
        }

        foreach ($unselectedCandidates as $candidate) {
            $candidateId = (int) ($candidate['id'] ?? 0);
            $contactAddress = $candidate['sender_address'] ?? '';

            // Record in audit trail
            $this->routeCancellationRepository?->insertCancellation($hash, $candidateId, $contactAddress);

            // Send cancellation message to the unselected candidate's contact
            if ($this->p2pService !== null && $contactAddress !== '') {
                $cancelPayload = [
                    'type' => 'route_cancel',
                    'hash' => $hash,
                    'cancelled' => true,
                ];
                $contactHash = substr(hash('sha256', $contactAddress), 0, 8);
                $messageId = 'route-cancel-' . $hash . '-' . $contactHash;

                $this->p2pService->sendP2pMessage('route_cancel', $contactAddress, $cancelPayload, $messageId);
                $cancelledCount++;

                $cancelledRoutes[] = [
                    'candidate_id' => $candidateId,
                    'contact_address' => $contactAddress,
                    'status' => 'sent',
                ];
            } else {
                $cancelledRoutes[] = [
                    'candidate_id' => $candidateId,
                    'contact_address' => $contactAddress,
                    'status' => 'failed',
                ];
            }
        }

        // Release capacity reservations for unselected routes
        // Each unselected candidate's sender had reserved capacity on this node
        // that is no longer needed
        foreach ($unselectedCandidates as $candidate) {
            $senderPubkeyHash = hash('sha256', $candidate['sender_public_key'] ?? '');
            $this->capacityReservationRepository?->releaseByHashAndContact($hash, $senderPubkeyHash, 'cancelled');
        }

        Logger::getInstance()->info('RouteCancellation: Cancelled unselected routes', [
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

    public function handleIncomingCancellation(array $request): void {
        $hash = $request['hash'] ?? null;
        $isFullCancel = !empty($request['full_cancel']);

        if ($hash === null) {
            Logger::getInstance()->warning('RouteCancellation: Received cancellation without hash');
            echo json_encode(['status' => 'rejected', 'message' => 'Missing hash']);
            return;
        }

        // Regular route_cancel (from best-fee selection): just acknowledge.
        // Do NOT cancel P2P or release reservation — this node may still be
        // part of the selected route in a diamond topology. Resources will be
        // freed by CleanupService TTL if unused.
        if (!$isFullCancel) {
            Logger::getInstance()->info('RouteCancellation: Acknowledged route_cancel (partial)', [
                'hash' => $hash,
            ]);
            echo json_encode(['status' => 'acknowledged', 'message' => 'Route cancel acknowledged']);
            return;
        }

        // Full cancel — originator cancelled the entire P2P transaction.
        // Cancel local P2P, release reservation, and propagate downstream.
        if ($this->p2pRepository !== null) {
            $p2p = $this->p2pRepository->getByHash($hash);

            if (!$p2p) {
                Logger::getInstance()->warning('RouteCancellation: No local P2P for incoming full cancel', [
                    'hash' => $hash,
                ]);
                echo json_encode(['status' => 'acknowledged', 'message' => 'No local P2P found']);
                return;
            }

            $currentStatus = $p2p['status'] ?? '';
            // Only cancel if not already in a terminal state
            if (!in_array($currentStatus, [Constants::STATUS_COMPLETED, Constants::STATUS_CANCELLED, Constants::STATUS_EXPIRED], true)) {
                $this->p2pRepository->updateStatus($hash, Constants::STATUS_CANCELLED);
            }
        }

        // Release capacity reservation
        $this->capacityReservationRepository?->releaseByHash($hash, 'cancelled');

        // Propagate full cancel downstream to this node's contacts
        $this->p2pService?->broadcastFullCancelForHash($hash);

        Logger::getInstance()->info('RouteCancellation: Handled full cancel', [
            'hash' => $hash,
        ]);

        echo json_encode(['status' => 'acknowledged', 'message' => 'Full cancel processed']);
    }

    /**
     * Compute a hop budget, optionally randomized via geometric distribution.
     *
     * Static so P2pService can call it without an instance (avoids circular dependency).
     * When EIOU_HOP_BUDGET_RANDOMIZED=false, returns maxHops for deterministic tests.
     *
     * @param int $minHops Minimum hop budget
     * @param int $maxHops Maximum hop budget
     * @return int Hop budget within [$minHops, $maxHops]
     */
    public static function computeHopBudget(int $minHops, int $maxHops): int {
        if ($minHops < 0) {
            $minHops = 0;
        }
        if ($maxHops < $minHops) {
            $maxHops = $minHops;
        }

        // When randomization is disabled (tests), use full hop range
        if (!Constants::isHopBudgetRandomized()) {
            return $maxHops;
        }

        // Geometric distribution: 30% chance of stopping at each hop beyond minHops.
        // Biases toward lower hops while allowing higher values.
        $budget = $minHops;
        while ($budget < $maxHops && (random_int(0, 99) >= self::HOP_STOP_PROBABILITY)) {
            $budget++;
        }

        return $budget;
    }

    public function generateRandomizedHopBudget(int $minHops, int $maxHops): int {
        return self::computeHopBudget($minHops, $maxHops);
    }

    public function decrementHopBudget(int $currentBudget): int {
        return max(0, $currentBudget - 1);
    }
}
