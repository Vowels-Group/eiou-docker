<?php
# Copyright 2025-2026 Vowels Group, LLC

require_once __DIR__ . '/BasePayload.php';

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

        return [
            'type' => 'p2p',
            'hash' => $data['hash'],
            'salt' => $data['salt'],
            'time' => $data['time'],
            'expiration' => $data['time'] + $this->timeUtility->convertMicrotimeToInt($this->currentUser->getP2pExpirationTime()),
            'currency' => $this->sanitizeString($data['currency']),
            'amount' => $this->sanitizeNumber($data['amount']),
            'requestLevel' => (int) $data['minRequestLevel'],
            'maxRequestLevel' => (int) $data['maxRequestLevel'],
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
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

        return [
            'type' => 'p2p',
            'hash' => $data['hash'],
            'salt' => $data['salt'],
            'time' => $data['time'],
            'expiration' => $data['expiration'],
            'currency' => $this->sanitizeString($data['currency']),
            'amount' => $this->sanitizeNumber($data['amount']),
            'requestLevel' => ((int) $data['request_level']) + 1, // Increment request level for forwarding
            'maxRequestLevel' => (int) $data['max_request_level'],
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
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
            'status' => 'forwarded',
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
            'status' => 'inserted',
            'message' => "hash {$request['hash']} for P2P stored in database of {$receiver}",
            'senderAddress' => $receiver,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
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