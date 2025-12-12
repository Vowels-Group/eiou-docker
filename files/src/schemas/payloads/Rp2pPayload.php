<?php
/**
 * Return Peer-to-Peer (RP2P) payload builder
 *
 * Copyright 2025
 * This class handles building RP2P payloads for peer-to-peer transaction
 * requests, acceptances, and rejections.
 *
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
        //output(outputBuildingRp2pPayload($data), 'SILENT');
        $userAddress = $this->transportUtility->resolveUserAddressForTransport($data['senderAddress']);
        return [
            'type' => 'rp2p', // Return Peer to peer request type
            'hash' => $data['hash'],
            'time' => $data['time'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'signature' => $data['signature'],
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }

    /**
     * Build a RP2P transaction payload from database data
     *
     * @param array $data Database RP2P data with snake_case keys
     * @return array The built RP2P payload
     */
    public function buildFromDatabase(array $data): array
    {
        //output(outputBuildingRp2pPayload($data), 'SILENT');
        $userAddress = $this->transportUtility->resolveUserAddressForTransport($data['sender_address']);
        return [
            'type' => 'rp2p', // Return Peer to peer request type
            'hash' => $data['hash'],
            'time' => $data['time'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'signature' => $data['signature'],
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }

    /**
     * Build RP2P accepted payload when request was received successfully
     *
     * @param array $request The RP2P request data
     * @return string JSON-encoded received payload
     */
    public function buildAcceptance(array $request): string
    {
        $receiver = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);
        return json_encode([
            'status' => 'received',
            'message' => 'hash ' . print_r($request['hash'], true) . ' for RP2P received by ' . print_r($receiver, true),
            'senderAddress' => $receiver,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build RP2P rejection payload when request was rejected
     *
     * @param array $request The RP2P request data
     * @param string $reason Rejection reason code (duplicate, insufficient_funds, contact_blocked, etc.)
     * @return string JSON-encoded rejection payload
     */
    public function buildRejection(array $request, string $reason = 'duplicate'): string
    {
        $receiver = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);
        $hash = $request['hash'];
        $message = $this->buildRejectionMessage($hash, $receiver, $reason);

        return json_encode([
            'status' => 'rejected',
            'reason' => $reason,
            'message' => $message,
            'senderAddress' => $receiver,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build a human-readable rejection message based on the reason code
     *
     * @param string $hash The RP2P hash
     * @param string $receiver The receiver address
     * @param string $reason The rejection reason code
     * @return string Human-readable rejection message
     */
    private function buildRejectionMessage(string $hash, string $receiver, string $reason): string
    {
        $messages = [
            'duplicate' => "hash {$hash} for RP2P already exists in database of {$receiver}"    
        ];

        return $messages[$reason] ?? "hash {$hash} for RP2P rejected by {$receiver}: {$reason}";
    }

    /**
     * Build RP2P forwarded payload when request is being forwarded to next hop
     *
     * @param array $request The RP2P request data
     * @param string|null $nextHop Optional next hop address
     * @return string JSON-encoded forwarded payload
     */
    public function buildForwarded(array $request, ?string $nextHop = null): string
    {
        $receiver = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);
        $message = 'hash ' . $request['hash'] . ' for RP2P forwarded by ' . $receiver;
        if ($nextHop !== null) {
            $message .= ' to next hop';
        }

        return json_encode([
            'status' => 'forwarded',
            'message' => $message,
            'senderAddress' => $receiver,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build RP2P inserted payload when request has been stored in database
     *
     * @param array $request The RP2P request data
     * @return string JSON-encoded inserted payload
     */
    public function buildInserted(array $request): string
    {
        $receiver = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);
        return json_encode([
            'status' => 'inserted',
            'message' => 'hash ' . $request['hash'] . ' for RP2P stored in database of ' . $receiver,
            'senderAddress' => $receiver,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }
}
