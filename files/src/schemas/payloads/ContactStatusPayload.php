<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

require_once __DIR__ . '/BasePayload.php';

/**
 * Contact Status (Ping) payload builder
 *
 * Handles building payloads for contact status ping operations.
 * Used for checking if contacts are online and validating transaction chains.
 */
class ContactStatusPayload extends BasePayload
{
    /**
     * Build the ping request payload
     *
     * @param array $data Ping data containing:
     *                    - receiverAddress: Address to ping
     *                    - prevTxid: Last transaction ID in the chain with this contact
     *                    - requestSync: Whether to request chain validation/sync
     * @return array The ping payload
     */
    public function build(array $data): array
    {
        $this->ensureRequiredFields($data, ['receiverAddress']);

        $userAddress = $this->transportUtility->resolveUserAddressForTransport($data['receiverAddress']);

        return [
            'type' => 'ping',
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
            'prevTxid' => $data['prevTxid'] ?? null,
            'requestSync' => $data['requestSync'] ?? false,
            'time' => $this->timeUtility->getCurrentMicrotime()
        ];
    }

    /**
     * Build ping response payload (pong)
     *
     * @param array $request The ping request data
     * @param string|null $localPrevTxid Our local prev_txid for comparison
     * @param bool $chainValid Whether the chains match
     * @return string JSON encoded pong response
     */
    public function buildResponse(array $request, ?string $localPrevTxid = null, bool $chainValid = true): string
    {
        $this->ensureRequiredFields($request, ['senderAddress']);

        $userAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

        return json_encode([
            'status' => 'pong',
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
            'prevTxid' => $localPrevTxid,
            'chainValid' => $chainValid,
            'time' => $this->timeUtility->getCurrentMicrotime()
        ]);
    }

    /**
     * Build ping rejection payload (when contact is blocked or feature disabled)
     *
     * @param array $request The ping request data
     * @param string $reason Rejection reason
     * @return string JSON encoded rejection payload
     */
    public function buildRejection(array $request, string $reason = 'blocked'): string
    {
        $this->ensureRequiredFields($request, ['senderAddress']);

        $userAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

        $messages = [
            'blocked' => 'Contact is blocked',
            'disabled' => 'Contact status feature is disabled',
            'unknown_contact' => 'Contact not found'
        ];

        return json_encode([
            'status' => 'rejected',
            'reason' => $reason,
            'message' => $messages[$reason] ?? "Ping rejected: {$reason}",
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey()
        ]);
    }
}
