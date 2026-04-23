<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Contracts\P2pServiceInterface;
use Eiou\Contracts\P2pTransactionSenderInterface;
use Eiou\Core\Constants;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pCandidateRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Events\EventDispatcher;
use Eiou\Events\P2pEvents;
use Throwable;

/**
 * P2P Approval Service
 *
 * Single service-level commit point for operator-triggered approve/reject
 * on P2P transactions. Used by CliP2pApprovalService, ApiController, and
 * the GUI TransactionController so the side effects (DB update, broadcast,
 * candidate cleanup, event dispatch) and the validation rules (exists,
 * is-originator) live in one place.
 *
 * Returns structured result arrays rather than throwing so the three
 * callers can translate them into their native output shapes (CLI stdout,
 * HTTP response, AJAX JSON) without wrapping layers of try/catch.
 *
 * Result shape:
 *   Success: ['success' => true, 'hash' => ..., 'mode' => 'candidate'|'fast',
 *             'candidate_index' => int|null, 'sender_address' => string|null]
 *   Error:   ['success' => false, 'code' => string, 'message' => string, 'status' => int]
 */
class P2pApprovalService
{
    public function __construct(
        private readonly P2pRepository $p2pRepository,
        private readonly Rp2pRepository $rp2pRepository,
        private readonly Rp2pCandidateRepository $candidateRepository,
        private readonly P2pTransactionSenderInterface $sender,
        private readonly P2pServiceInterface $p2pService,
    ) {
    }

    /**
     * Approve a P2P transaction. Candidate selection accepts either a
     * 1-based index (CLI vocabulary) or a primary-key id (API vocabulary);
     * if both are null, fast-mode resolves the single unambiguous route.
     */
    public function approve(string $hash, ?int $candidateIndex = null, ?int $candidateId = null): array
    {
        $gate = $this->gateOriginator($hash);
        if ($gate !== null) {
            return $gate;
        }

        $resolved = $this->resolveApprovalRequest($hash, $candidateIndex, $candidateId);
        if (!$resolved['success']) {
            return $resolved;
        }
        $request = $resolved['request'];
        $mode = $resolved['mode'];

        try {
            if ($mode === 'candidate') {
                $this->rp2pRepository->insertRp2pRequest($request);
            }
            $this->p2pRepository->updateStatus($hash, 'found');
            $this->sender->sendP2pEiou($request);
            $this->candidateRepository->deleteCandidatesByHash($hash);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'code' => 'send_failed',
                'message' => 'Failed to send transaction: ' . $e->getMessage(),
                'status' => 500,
            ];
        }

        EventDispatcher::getInstance()->dispatch(P2pEvents::P2P_APPROVED, [
            'p2p_id' => $hash,
            'amount' => $request['amount'] ?? null,
            'currency' => $request['currency'] ?? null,
            'sender_address' => $request['senderAddress'] ?? null,
            'mode' => $mode,
        ]);

        return [
            'success' => true,
            'hash' => $hash,
            'mode' => $mode,
            'candidate_index' => $candidateIndex,
            'sender_address' => $request['senderAddress'] ?? null,
        ];
    }

    /**
     * Reject a P2P transaction. Marks it cancelled, broadcasts cancel
     * downstream (originator-side cancel, which the API and GUI used to
     * mis-route through `sendCancelNotificationForHash` — a no-op for
     * originators — now uses the correct `broadcastFullCancelForHash`).
     */
    public function reject(string $hash): array
    {
        $gate = $this->gateOriginator($hash);
        if ($gate !== null) {
            return $gate;
        }

        try {
            $this->p2pRepository->updateStatus($hash, Constants::STATUS_CANCELLED);
            $this->p2pService->broadcastFullCancelForHash($hash);
            $this->candidateRepository->deleteCandidatesByHash($hash);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'code' => 'reject_failed',
                'message' => 'Failed to reject transaction: ' . $e->getMessage(),
                'status' => 500,
            ];
        }

        EventDispatcher::getInstance()->dispatch(P2pEvents::P2P_REJECTED, [
            'p2p_id' => $hash,
        ]);

        return [
            'success' => true,
            'hash' => $hash,
        ];
    }

    /**
     * Shared guard: transaction must exist AND the caller must be the
     * originator (the only role that can approve or reject). Returns
     * null when the gate passes; an error-shaped array when it fails.
     */
    private function gateOriginator(string $hash): ?array
    {
        $p2p = $this->p2pRepository->getAwaitingApproval($hash);
        if (!$p2p) {
            return [
                'success' => false,
                'code' => 'not_found',
                'message' => 'Transaction not found or not awaiting approval',
                'status' => 404,
            ];
        }
        if (empty($p2p['destination_address'])) {
            return [
                'success' => false,
                'code' => 'not_originator',
                'message' => 'Only the transaction originator can approve or reject',
                'status' => 403,
            ];
        }
        return null;
    }

    /**
     * Figure out which route to send based on a caller-supplied selector
     * ($candidateIndex OR $candidateId) or fast-mode fallback. Returns
     * either a success row carrying the built request + mode, or an error
     * row ready to forward to the caller.
     */
    private function resolveApprovalRequest(string $hash, ?int $candidateIndex, ?int $candidateId): array
    {
        if ($candidateId !== null && $candidateId > 0) {
            $candidate = $this->candidateRepository->getCandidateById($candidateId);
            if (!$candidate) {
                return [
                    'success' => false,
                    'code' => 'candidate_not_found',
                    'message' => 'Selected route candidate not found',
                    'status' => 404,
                ];
            }
            if ($candidate['hash'] !== $hash) {
                return [
                    'success' => false,
                    'code' => 'candidate_mismatch',
                    'message' => 'Candidate does not belong to this transaction',
                    'status' => 400,
                ];
            }
            return [
                'success' => true,
                'request' => $this->candidateToRequest($candidate),
                'mode' => 'candidate',
            ];
        }

        if ($candidateIndex !== null && $candidateIndex > 0) {
            $candidates = $this->candidateRepository->getCandidatesByHash($hash);
            if (empty($candidates)) {
                return [
                    'success' => false,
                    'code' => 'no_candidates',
                    'message' => 'No candidates available for this transaction',
                    'status' => 404,
                ];
            }
            if ($candidateIndex < 1 || $candidateIndex > count($candidates)) {
                return [
                    'success' => false,
                    'code' => 'invalid_candidate_index',
                    'message' => "Invalid candidate index. Choose between 1 and " . count($candidates),
                    'status' => 400,
                ];
            }
            return [
                'success' => true,
                'request' => $this->candidateToRequest($candidates[$candidateIndex - 1]),
                'mode' => 'candidate',
            ];
        }

        $candidates = $this->candidateRepository->getCandidatesByHash($hash);
        if (!empty($candidates) && count($candidates) > 1) {
            return [
                'success' => false,
                'code' => 'candidate_selection_required',
                'message' => 'Multiple route candidates available. Specify an index between 1 and ' . count($candidates),
                'status' => 400,
            ];
        }
        if (!empty($candidates) && count($candidates) === 1) {
            return [
                'success' => true,
                'request' => $this->candidateToRequest($candidates[0]),
                'mode' => 'candidate',
            ];
        }

        // No candidates — fall back to the single rp2p fast-mode row.
        $rp2p = $this->rp2pRepository->getByHash($hash);
        if ($rp2p) {
            return [
                'success' => true,
                'request' => $this->candidateToRequest($rp2p),
                'mode' => 'fast',
            ];
        }

        return [
            'success' => false,
            'code' => 'no_route',
            'message' => 'No route available for this transaction',
            'status' => 404,
        ];
    }

    /**
     * Candidate and rp2p rows share the same column layout — map both
     * through the same projection to the request shape `sendP2pEiou`
     * expects.
     */
    private function candidateToRequest(array $row): array
    {
        return [
            'hash' => $row['hash'],
            'time' => $row['time'],
            'amount' => $row['amount'],
            'currency' => $row['currency'],
            'senderPublicKey' => $row['sender_public_key'],
            'senderAddress' => $row['sender_address'],
            'signature' => $row['sender_signature'],
        ];
    }
}
