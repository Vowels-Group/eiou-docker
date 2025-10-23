<?php

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
     * Build the main send transaction payload
     *
     * @param array $data Transaction data with keys: time, receiverAddress, receiverPublicKey,
     *                    amount, currency, txid, previousTxid, memo (optional)
     * @return array The send transaction payload
     */
    public function build(array $data): array
    {
        $this->ensureRequiredFields($data, [
            'receiverAddress', 'receiverPublicKey',
            'amount', 'currency', 'txid'
        ]);

        $userAddress = resolveUserAddressForTransport($data['receiverAddress']);
        $memo = $data['memo'] ?? 'standard';

        return [
            'type' => 'send',
            'time' => $data['time'],
            'senderPublicKey' => $this->currentUser->getPublicKey(),
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
    public function buildFromDatabase(array $data): array
    {
        $this->ensureRequiredFields($data, [
            'receiver_address', 'receiver_public_key',
            'amount', 'currency', 'txid'
        ]);
       
        $userAddress = resolveUserAddressForTransport($data['receiver_address']);
        $memo = $data['memo'] ?? 'standard';

        return [
            'type' => 'send',
            'time' => $data['time'],
            'senderPublicKey' => $this->currentUser->getPublicKey(),
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
        $transactionService = ServiceContainer::getInstance()->getTransactionService();
        // This method returns data array for further processing, not final payload
        return [
            'time' => $rp2pData['time'] ?? time(),
            'receiver_address' => $rp2pData['sender_address'] ?? null,
            'receiver_public_key' => $rp2pData['sender_public_key'] ?? null,
            'amount' => $transactionService->removeTransactionFee($message),
            'currency' => $rp2pData['currency'] ?? 'EIOU',
            'txid' => $transactionService->createUniqueDatabaseTxid($message),
            'previous_txid' => $transactionService->fixPreviousTxid($this->currentUser->getPublicKey(), $message['receiver_public_key']),
            'memo' => $rp2pData['hash'] ?? null,
        ];
    }

    /**
     * Build a transaction received payload when request was accepted successfully
     *
     * @param array $request The transaction request data
     * @return string JSON encoded received payload
     */
    public function buildAcceptance(array $request): string
    {
        $receiver = resolveUserAddressForTransport($request['senderAddress'] ?? '');
        $hashInfo = $this->resolveHashInfo($request);

        return json_encode([
            'status' => 'accepted',
            'txid' => $request['txid'] ?? null,
            'memo' => $request['memo'] ?? null,
            'message' => "{$hashInfo['type']} {$hashInfo['value']} for transaction received by {$receiver}",
        ]);
    }

    /**
     * Build a transaction completed payload
     *
     * @param array $request The transaction request data
     * @return array The completed payload
     */
    public function buildCompleted(array $request): array
    {
        $receiver = resolveUserAddressForTransport(
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
            'senderPublicKey' => $this->currentUser->getPublicKey(),
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
     * @return string JSON encoded rejection payload
     */
    public function buildRejection(array $request, string $reason = null): string
    {
        $receiver = resolveUserAddressForTransport($request['senderAddress'] ?? '');
        $hashInfo = $this->resolveHashInfo($request);

        $defaultReason = "{$hashInfo['type']} {$hashInfo['value']} for Transaction already exists in database of {$receiver}";

        return json_encode([
            'status' => 'rejected',
            'txid' => $request['txid'] ?? null,
            'memo' => $request['memo'] ?? null,
            'message' => $reason ?? $defaultReason,
        ]);
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
}