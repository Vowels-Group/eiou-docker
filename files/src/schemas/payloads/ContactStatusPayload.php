<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Schemas\Payloads;

use Eiou\Core\Constants;

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

        $payload = [
            'type' => 'ping',
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
            'prevTxid' => $data['prevTxid'] ?? null,
            'requestSync' => $data['requestSync'] ?? false,
            'time' => $this->timeUtility->getCurrentMicrotime()
        ];

        // Include per-currency chain heads for multi-currency chain validation
        if (!empty($data['prevTxidsByCurrency'])) {
            $payload['prevTxidsByCurrency'] = $data['prevTxidsByCurrency'];
        }

        return $payload;
    }

    /**
     * Build ping response payload (pong)
     *
     * @param array $request The ping request data
     * @param string|null $localPrevTxid Our local prev_txid for comparison
     * @param bool $chainValid Whether the chains match
     * @param int|null $availableCredit Available credit for the pinging contact (in cents)
     * @param string|null $currency Currency code for the available credit
     * @param int|null $processorsRunning Number of message processors currently running
     * @param int|null $processorsTotal Total expected message processors
     * @return string JSON encoded pong response
     */
    public function buildResponse(array $request, ?string $localPrevTxid = null, bool $chainValid = true, ?int $availableCredit = null, ?string $currency = null, ?int $processorsRunning = null, ?int $processorsTotal = null, array $chainStatusByCurrency = []): string
    {
        $this->ensureRequiredFields($request, ['senderAddress']);

        $userAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress']);

        $response = [
            'status' => 'pong',
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
            'prevTxid' => $localPrevTxid,
            'chainValid' => $chainValid,
            'availableCredit' => $availableCredit,
            'currency' => $currency,
            'time' => $this->timeUtility->getCurrentMicrotime()
        ];

        // Include per-currency chain status for multi-currency validation
        if (!empty($chainStatusByCurrency)) {
            $response['chainStatusByCurrency'] = $chainStatusByCurrency;
        }

        if ($processorsRunning !== null) {
            $response['processorsRunning'] = $processorsRunning;
        }
        if ($processorsTotal !== null) {
            $response['processorsTotal'] = $processorsTotal;
        }

        return json_encode($response);
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
            'status' => Constants::DELIVERY_REJECTED,
            'reason' => $reason,
            'message' => $messages[$reason] ?? "Ping rejected: {$reason}",
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey()
        ]);
    }
}
