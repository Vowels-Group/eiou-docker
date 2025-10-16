<?php

require_once __DIR__ . '/../../core/UserContext.php';
require_once __DIR__ . '/BasePayload.php';

/**
 * Transaction payload builder
 *
 * Handles building payloads for transaction-related operations including
 * sending, accepting, rejecting, and completing transactions.
 */
class TransactionPayload extends BasePayload
{
    /**
     * Build the main transaction send payload
     *
     * @param array $data Transaction data
     * @return array The transaction payload
     */
    public function build(array $data): array
    {
        return $this->buildSend($data);
    }

    /**
     * Build a send transaction payload
     *
     * @param array $data Transaction data with keys: time, receiverAddress, receiverPublicKey,
     *                    amount, currency, txid, previousTxid, memo (optional)
     * @return array The send transaction payload
     */
    public function buildSend(array $data): array
    {
        $this->ensureRequiredFields($data, [
            'time', 'receiverAddress', 'receiverPublicKey',
            'amount', 'currency', 'txid', 'previousTxid'
        ]);

        $user = $this->userContext->getUser();
        $userAddress = $this->resolveUserAddressForTransport($data['receiverAddress']);
        $memo = $data['memo'] ?? 'standard';

        return [
            'type' => 'send',
            'time' => $data['time'],
            'senderPublicKey' => $user ? $user->getPublicKey() : null,
            'senderAddress' => $userAddress,
            'receiverPublicKey' => $data['receiverPublicKey'],
            'receiverAddress' => $data['receiverAddress'],
            'amount' => $this->sanitizeNumber($data['amount']),
            'currency' => $this->sanitizeString($data['currency']),
            'txid' => $data['txid'],
            'previousTxid' => $data['previousTxid'],
            'memo' => $memo,
        ];
    }

    /**
     * Build a send transaction payload from database data
     *
     * @param array $data Database transaction data with snake_case keys
     * @return array The send transaction payload
     */
    public function buildSendFromDatabase(array $data): array
    {
        $this->ensureRequiredFields($data, [
            'time', 'receiver_address', 'receiver_public_key',
            'amount', 'currency', 'txid', 'previous_txid'
        ]);

        $user = $this->userContext->getUser();
        $userAddress = $this->resolveUserAddressForTransport($data['receiver_address']);
        $memo = $data['memo'] ?? 'standard';

        return [
            'type' => 'send',
            'time' => $data['time'],
            'senderPublicKey' => $user ? $user->getPublicKey() : null,
            'senderAddress' => $userAddress,
            'receiverPublicKey' => $data['receiver_public_key'],
            'receiverAddress' => $data['receiver_address'],
            'amount' => $this->sanitizeNumber($data['amount']),
            'currency' => $this->sanitizeString($data['currency']),
            'txid' => $data['txid'],
            'previousTxid' => $data['previous_txid'],
            'memo' => $memo,
        ];
    }

    /**
     * Build a forwarding transaction payload
     *
     * @param array $message Original transaction message
     * @param array $rp2pData RP2P data for forwarding
     * @return array The forwarding transaction data
     */
    public function buildForwarding(array $message, array $rp2pData): array
    {
        $user = $this->userContext->getUser();

        // This method returns data array for further processing, not final payload
        // The actual implementation would need the helper functions from the original code
        return [
            'time' => $rp2pData['time'] ?? time(),
            'receiver_address' => $rp2pData['sender_address'] ?? null,
            'receiver_public_key' => $rp2pData['sender_public_key'] ?? null,
            'amount' => $this->calculateAmountAfterFee($message),
            'currency' => $rp2pData['currency'] ?? 'EIOU',
            'txid' => $this->generateUniqueTxid($message),
            'previous_txid' => $this->resolvePreviousTxid($user, $message),
            'memo' => $rp2pData['hash'] ?? null,
        ];
    }

    /**
     * Build a transaction acceptance payload
     *
     * @param array $request The transaction request data
     * @return array The acceptance payload
     */
    public function buildAcceptance(array $request): array
    {
        $receiver = $this->resolveUserAddressForTransport($request['senderAddress'] ?? '');
        $hashInfo = $this->resolveHashInfo($request);

        return [
            'status' => 'accepted',
            'txid' => $request['txid'] ?? null,
            'memo' => $request['memo'] ?? null,
            'message' => "{$hashInfo['type']} {$hashInfo['value']} for transaction received by {$receiver}",
        ];
    }

    /**
     * Build a transaction completed payload
     *
     * @param array $request The transaction request data
     * @return array The completed payload
     */
    public function buildCompleted(array $request): array
    {
        $user = $this->userContext->getUser();
        $receiver = $this->resolveUserAddressForTransport(
            $request['senderAddress'] ?? $request['sender_address'] ?? ''
        );
        $hashInfo = $this->resolveHashInfo($request);

        return [
            'type' => 'message',
            'typeMessage' => 'transaction',
            'inquiry' => false,
            'status' => 'completed',
            'hash' => $hashInfo['value'],
            'hashType' => $hashInfo['type'],
            'senderAddress' => $receiver,
            'senderPublicKey' => $user ? $user->getPublicKey() : null,
            'amount' => $this->sanitizeNumber($request['amount'] ?? 0),
            'currency' => $this->sanitizeString($request['currency'] ?? 'EIOU'),
            'message' => "transaction for hash {$hashInfo['value']} was successfully completed through intermediary",
        ];
    }

    /**
     * Build a transaction rejection payload
     *
     * @param array $request The transaction request data
     * @param string $reason Optional rejection reason
     * @return array The rejection payload
     */
    public function buildRejection(array $request, string $reason = null): array
    {
        $receiver = $this->resolveUserAddressForTransport($request['senderAddress'] ?? '');
        $hashInfo = $this->resolveHashInfo($request);

        $defaultReason = "{$hashInfo['type']} {$hashInfo['value']} for Transaction already exists in database of {$receiver}";

        return [
            'status' => 'rejected',
            'txid' => $request['txid'] ?? null,
            'memo' => $request['memo'] ?? null,
            'message' => $reason ?? $defaultReason,
        ];
    }

    /**
     * Resolve hash information from request
     *
     * @param array $request The request data
     * @return array Array with 'type' and 'value' keys
     */
    private function resolveHashInfo(array $request): array
    {
        if (isset($request['memo'])) {
            if ($request['memo'] === 'standard') {
                return [
                    'type' => 'txid',
                    'value' => $request['txid'] ?? '',
                ];
            } else {
                return [
                    'type' => 'memo',
                    'value' => $request['memo'],
                ];
            }
        } else {
            return [
                'type' => 'memo',
                'value' => $request['hash'] ?? '',
            ];
        }
    }

    /**
     * Calculate amount after transaction fee
     *
     * @param array $message Transaction message
     * @return float The amount after fee
     */
    private function calculateAmountAfterFee(array $message): float
    {
        // TODO: Implement fee calculation logic
        // This would need to be moved from the removeTransactionFee function
        $amount = (float) ($message['amount'] ?? 0);
        $fee = 0.0; // Fee calculation would go here
        return $amount - $fee;
    }

    /**
     * Generate unique transaction ID
     *
     * @param array $message Transaction message
     * @return string The unique transaction ID
     */
    private function generateUniqueTxid(array $message): string
    {
        // TODO: Implement proper unique ID generation
        // This would need to be moved from the createUniqueDatabaseTxid function
        return hash('sha256', json_encode($message) . microtime(true));
    }

    /**
     * Resolve previous transaction ID
     *
     * @param object|null $user User object
     * @param array $message Transaction message
     * @return string|null The previous transaction ID
     */
    private function resolvePreviousTxid(?object $user, array $message): ?string
    {
        // TODO: Implement proper previous txid resolution
        // This would need to be moved from the fixPreviousTxid function
        return $message['previousTxid'] ?? null;
    }

    /**
     * Resolve user address for transport
     *
     * @param string $address The address to resolve
     * @return string The resolved address
     */
    private function resolveUserAddressForTransport(string $address): string
    {
        // TODO: This should be moved to a service or utility class
        $userAddress = $this->getUserAddress();
        return $userAddress ?? $address;
    }
}