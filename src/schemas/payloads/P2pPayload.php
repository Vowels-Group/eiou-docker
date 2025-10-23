<?php

require_once __DIR__ . '/BasePayload.php';

/**
 * P2P (Peer to Peer) payload builder
 *
 * Copyright 2025
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
            'senderPublicKey' => $this->currentUser->getPublicKey(),
            'senderAddress' => $userAddress,
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
            'senderPublicKey' => $this->currentUser->getPublicKey(),
            'senderAddress' => $userAddress,
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
            'status' => 'received',
            'message' => "hash {$request['hash']} for P2P received by {$receiver}",
        ]);
    }

    /**
     * Build P2P rejection payload
     *
     * @param array $request The P2P request data
     * @param string $reason Optional rejection reason
     * @return string JSON encoded rejection payload
     */
    public function buildRejection(array $request, string $reason = 'already exists'): string
    {
        $this->ensureRequiredFields($request, ['hash', 'senderAddress']);

        $receiver = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

        return json_encode([
            'status' => 'rejected',
            'reason' => $reason,
            'message' => "hash {$request['hash']} for P2P already exists in database of {$receiver}",
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
    //         'senderPublicKey' => $this->currentUser->getPublicKey(),
    //         'senderAddress' => $this->getUserAddress(),
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
    //         'senderPublicKey' => $this->currentUser->getPublicKey(),
    //         'senderAddress' => $this->getUserAddress(),
    //         'timestamp' => time(),
    //     ], $additionalData);
    // }
}