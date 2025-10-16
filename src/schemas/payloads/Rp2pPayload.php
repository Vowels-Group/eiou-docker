<?php
// Copyright 2025

/**
 * Return Peer-to-Peer (RP2P) payload builder
 *
 * This class handles building RP2P payloads for peer-to-peer transaction
 * requests, acceptances, and rejections.
 *
 * IMPORTANT: This codebase does NOT use namespaces.
 */

require_once __DIR__ . '/BasePayload.php';

class Rp2pPayload extends BasePayload
{
    /**
     * Build the main RP2P payload
     *
     * @param array $data Input data containing transaction details
     * @return array The built RP2P payload
     */
    public function build(array $data): array
    {
        output(outputBuildingRp2pPayload($data), 'SILENT');
        $userAddress = resolveUserAddressForTransport($data['senderAddress'] ?? $data['sender_address']);
        return [
            'type' => 'rp2p', // Return Peer to peer request type
            'hash' => $data['hash'],
            'time' => $data['time'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'senderPublicKey' => $this->getUserPublicKey(),
            'senderAddress' => $userAddress,
            'signature' => $data['signature']
        ];
    }

    /**
     * Build RP2P acceptance payload when request was received successfully
     *
     * @param array $request The RP2P request data
     * @return string JSON-encoded acceptance payload
     */
    public function buildAcceptance(array $request): string
    {
        $receiver = resolveUserAddressForTransport($request['senderAddress']);
        return json_encode([
            'status' => 'received',
            'message' => 'hash ' . print_r($request['hash'], true) . ' for RP2P received by ' . print_r($receiver, true)
        ]);
    }

    /**
     * Build RP2P rejection payload when request was rejected (duplicate in database)
     *
     * @param array $request The RP2P request data
     * @return string JSON-encoded rejection payload
     */
    public function buildRejection(array $request): string
    {
        $receiver = resolveUserAddressForTransport($request['senderAddress']);
        return json_encode([
            'status' => 'rejected',
            'message' => 'hash ' . print_r($request['hash'], true) . ' for RP2P already exists in database of ' . print_r($receiver, true)
        ]);
    }
}
