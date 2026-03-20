<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Schemas\Payloads;

use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;

/**
 * P2P (Peer to Peer) payload builder
 *
 * Handles building payloads for P2P operations including initial requests,
 * forwarding, acceptance, and rejection.
 */
class P2pPayload extends BasePayload
{
    /**
     * Build the main P2P payload
     *
     * @param array $data P2P data
     * @return array The P2P payload
     */
    public function build(array $data): array
    {
        $this->ensureRequiredFields($data, [
            'hash', 'salt', 'time', 'currency',
            'amount', 'minRequestLevel', 'maxRequestLevel', 'receiverAddress'
        ]);
        //output(outputBuildingP2pPayload($data),'SILENT');
        $userAddress = $this->transportUtility->resolveUserAddressForTransport($data['receiverAddress']);

        // Force fast mode if our resolved address is Tor — transport index cascading
        // means the entire P2P chain will propagate over Tor, making best-fee mode
        // impractical due to per-hop Tor latency (~5s × 6 Tor relays per eIOU hop).
        // Disabled when EIOU_TOR_FORCE_FAST=false (for testing best-fee over Tor).
        $isTorRoute = $this->transportUtility->isTorAddress($data['receiverAddress'])
            || $this->transportUtility->isTorAddress($userAddress);

        if (Constants::isTorForceFast() && $isTorRoute && !($data['fast'] ?? true)) {
            $data['fast'] = 1;
        }

        // Calculate per-hop wait time for best-fee mode relay nodes
        // Formula: floor(expiration / hop_wait_divisor) - processing_buffer, clamped to minimum
        // Uses HOP_WAIT_DIVISOR (fixed, not actual max level) so all P2Ps produce the same
        // hopWait regardless of the user's maxP2pLevel setting (prevents topology inference).
        $expirationSeconds = $this->currentUser->getP2pExpirationTime();

        // Tor hidden services add significant per-hop latency (each eIOU hop = 6 Tor relay hops).
        // Scale expiration so multi-hop Tor chains don't expire prematurely.
        if ($isTorRoute) {
            $expirationSeconds *= Constants::P2P_TOR_EXPIRATION_MULTIPLIER;
        }

        $hopWait = max(
            (int) floor($expirationSeconds / Constants::P2P_HOP_WAIT_DIVISOR) - Constants::P2P_HOP_PROCESSING_BUFFER_SECONDS,
            Constants::P2P_MIN_HOP_WAIT_SECONDS
        );

        $payload = [
            'type' => 'p2p',
            'hash' => $data['hash'],
            'salt' => $data['salt'],
            'time' => $data['time'],
            'expiration' => $data['time'] + $this->timeUtility->convertMicrotimeToInt($expirationSeconds),
            'currency' => $this->sanitizeString($data['currency']),
            'amount' => $this->serializeAmount($data['amount']),
            'requestLevel' => (int) $data['minRequestLevel'],
            'maxRequestLevel' => (int) $data['maxRequestLevel'],
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
            'fast' => (bool) ($data['fast'] ?? true),
            'hopWait' => $hopWait,
        ];

        // Include inquiry token (hash commitment) — propagates through relay chain.
        // Only the original sender knows the pre-image (inquiry_secret).
        if (isset($data['inquiryToken'])) {
            $payload['inquiryToken'] = $data['inquiryToken'];
        }

        return $payload;
    }

    /**
     * Build P2P payload from database data
     *
     * @param array $data Database P2P data with snake_case keys
     * @return array The P2P payload for forwarding
     */
    public function buildFromDatabase(array $data): array
    {
        $this->ensureRequiredFields($data, [
            'hash', 'salt', 'time', 'expiration', 'currency',
            'amount', 'request_level', 'max_request_level', 'sender_address'
        ]);
        //output(outputBuildingP2pPayload($data),'SILENT');
        $userAddress = $this->transportUtility->resolveUserAddressForTransport($data['sender_address']);

        $payload = [
            'type' => 'p2p',
            'hash' => $data['hash'],
            'salt' => $data['salt'],
            'time' => $data['time'],
            'expiration' => $data['expiration'],
            'currency' => $this->sanitizeString($data['currency']),
            'amount' => $this->serializeAmount($data['amount']),
            'requestLevel' => ((int) $data['request_level']) + 1, // Increment request level for forwarding
            'maxRequestLevel' => (int) $data['max_request_level'],
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
            'fast' => (bool) ($data['fast'] ?? true),
            'hopWait' => (int) ($data['hop_wait'] ?? 0),
        ];

        if (!empty($data['inquiry_token'])) {
            $payload['inquiryToken'] = $data['inquiry_token'];
        }

        return $payload;
    }

    /**
     * Build P2P "already relayed" payload when this node already has the P2P from another route
     *
     * Used in best-fee mode: instead of rejecting as duplicate, inform sender
     * that this node is already relaying the same P2P hash via a different path.
     *
     * @param array $request The P2P request data
     * @return string JSON encoded already_relayed payload
     */
    public function buildAlreadyRelayed(array $request): string
    {
        $this->ensureRequiredFields($request, ['hash', 'senderAddress']);

        $receiver = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

        return json_encode([
            'status' => 'already_relayed',
            'message' => "hash {$request['hash']} for P2P already being relayed by {$receiver}",
            'senderAddress' => $receiver,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build P2P accepted payload when request was received successfully
     *
     * @param array $request The P2P request data
     * @return string JSON encoded received payload
     */
    public function buildAcceptance(array $request): string
    {
        $this->ensureRequiredFields($request, ['hash', 'senderAddress']);

        $receiver = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

        return json_encode([
            'status' => Constants::DELIVERY_RECEIVED,
            'message' => "hash {$request['hash']} for P2P received by {$receiver}",
            'senderAddress' => $receiver,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build P2P rejection payload
     *
     * @param array $request The P2P request data
     * @param string $reason Rejection reason code (duplicate, insufficient_funds, contact_blocked, etc.)
     * @return string JSON encoded rejection payload
     */
    public function buildRejection(array $request, string $reason = 'duplicate'): string
    {
        $this->ensureRequiredFields($request, ['hash', 'senderAddress']);

        $receiver = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

        $message = $this->buildRejectionMessage($request['hash'], $receiver, $reason);

        return json_encode([
            'status' => Constants::STATUS_REJECTED,
            'reason' => $reason,
            'message' => $message,
            'senderAddress' => $receiver,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build a human-readable rejection message based on the reason code
     *
     * @param string $hash The P2P hash
     * @param string $receiver The receiver address
     * @param string $reason The rejection reason code
     * @return string Human-readable rejection message
     */
    private function buildRejectionMessage(string $hash, string $receiver, string $reason): string
    {
        $messages = [
            'duplicate' => "hash {$hash} for P2P already exists in database of {$receiver}",
            'insufficient_funds' => "hash {$hash} for P2P rejected by {$receiver}: insufficient funds",
            'contact_blocked' => "hash {$hash} for P2P rejected by {$receiver}: contact is blocked",
            'credit_limit_exceeded' => "hash {$hash} for P2P rejected by {$receiver}: credit limit exceeded",
        ];

        return $messages[$reason] ?? "hash {$hash} for P2P rejected by {$receiver}: {$reason}";
    }

    /**
     * Build P2P forwarded payload when request is being forwarded to next hop
     *
     * @param array $request The P2P request data
     * @param string|null $nextHop Optional next hop address
     * @return string JSON encoded forwarded payload
     */
    public function buildForwarded(array $request, ?string $nextHop = null): string
    {
        $this->ensureRequiredFields($request, ['hash', 'senderAddress']);

        $receiver = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);
        $message = "hash {$request['hash']} for P2P forwarded by {$receiver}";
        if ($nextHop !== null) {
            $message .= " to next hop";
        }

        return json_encode([
            'status' => Constants::DELIVERY_FORWARDED,
            'message' => $message,
            'senderAddress' => $receiver,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build P2P inserted payload when request has been stored in database
     *
     * @param array $request The P2P request data
     * @return string JSON encoded inserted payload
     */
    public function buildInserted(array $request): string
    {
        $this->ensureRequiredFields($request, ['hash', 'senderAddress']);

        $receiver = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

        return json_encode([
            'status' => Constants::DELIVERY_INSERTED,
            'message' => "hash {$request['hash']} for P2P stored in database of {$receiver}",
            'senderAddress' => $receiver,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build a P2P cancelled notification payload
     *
     * Sent upstream when a node cancels a P2P (dead-end, no viable contacts).
     * Uses type 'rp2p' so it routes through the RP2P handler on the receiving node,
     * where the cancelled flag is detected and handled as a response.
     *
     * @param string $hash The P2P hash being cancelled
     * @param string $recipientAddress The address of the recipient (used to resolve
     *        this node's address for the matching transport type)
     * @return array The cancel notification payload
     */
    public function buildCancelled(string $hash, string $recipientAddress): array
    {
        return [
            'type' => 'rp2p',
            'hash' => $hash,
            'cancelled' => true,
            'amount' => SplitAmount::zero()->toArray(),
            'time' => $this->timeUtility->getCurrentMicrotime(),
            'currency' => Constants::TRANSACTION_DEFAULT_CURRENCY,
            'senderAddress' => $this->transportUtility->resolveUserAddressForTransport($recipientAddress),
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }

    // /**
    //  * Build P2P inquiry payload
    //  *
    //  * @param string $hash The P2P hash to inquire about
    //  * @return array The inquiry payload
    //  */
    // public function buildInquiry(string $hash): array
    // {
       

    //     return [
    //         'type' => 'p2p',
    //         'inquiry' => true,
    //         'hash' => $hash,
    //         'senderAddress' => $this->getUserAddress(),
    //         'senderPublicKey' => $this->currentUser->getPublicKey(),
    //     ];
    // }

    // /**
    //  * Build P2P status update payload
    //  *
    //  * @param string $hash The P2P hash
    //  * @param string $status The status update
    //  * @param array $additionalData Optional additional data
    //  * @return array The status update payload
    //  */
    // public function buildStatusUpdate(string $hash, string $status, array $additionalData = []): array
    // {

    //     return array_merge([
    //         'type' => 'p2p',
    //         'statusUpdate' => true,
    //         'hash' => $hash,
    //         'status' => $status,        
    //         'senderAddress' => $this->getUserAddress(),
    //          'senderPublicKey' => $this->currentUser->getPublicKey(),
    //         'timestamp' => time(),
    //     ], $additionalData);
    // }
}