<?php

require_once __DIR__ . '/BasePayload.php';

/**
 * Transaction payload builder
 *
 * Copyright 2025
 * Handles building payloads for transaction-related operations including
 * sending, accepting, rejecting, and completing transactions.
 */
class TransactionPayload extends BasePayload
{
    /**
     * Build the main send transaction payload (used for saving into database)
     *
     * @param array $data Transaction data with keys: time, receiverAddress, receiverPublicKey,
     *                    amount, currency, txid, previousTxid, memo (optional), description (optional)
     * @return array The send transaction payload
     */
    public function build(array $data): array
    {
        $this->ensureRequiredFields($data, [
            'time', 'receiverAddress', 'receiverPublicKey',
            'amount', 'currency', 'txid'
        ]);

        $userAddress = $this->transportUtility->resolveUserAddressForTransport($data['receiverAddress']);
        $memo = $data['memo'] ?? 'standard';

        $payload = [
            'type' => 'send',
            'time' => $data['time'],
            'receiverAddress' => $data['receiverAddress'],
            'receiverPublicKey' => $data['receiverPublicKey'],
            'amount' => $this->sanitizeNumber($data['amount']),
            'currency' => $this->sanitizeString($data['currency']),
            'txid' => $data['txid'],
            'previousTxid' => $data['previousTxid'] ?? null,
            'memo' => $memo,
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];

        // Include description if provided (only for final recipient in P2P)
        if (isset($data['description']) && $data['description'] !== null) {
            $payload['description'] = $this->sanitizeString($data['description']);
        }

        return $payload;
    }

    /**
     * Build a send (P2P) transaction payload from database data
     *
     * @param array $data Database transaction data with snake_case keys
     * @return array The send transaction payload
     */
    public function buildFromDatabase(array $data): array
    {
        $this->ensureRequiredFields($data, [
            'time', 'receiver_address', 'receiver_public_key',
            'amount', 'currency', 'txid'
        ]);
       
        $userAddress = $this->transportUtility->resolveUserAddressForTransport($data['receiver_address']);
        $memo = $data['memo'];

        return [
            'type' => 'send',
            'time' => $data['time'],   
            'receiverAddress' => $data['receiver_address'],
            'receiverPublicKey' => $data['receiver_public_key'],
            'amount' => $this->sanitizeNumber($data['amount']),
            'currency' => $this->sanitizeString($data['currency']),
            'txid' => $data['txid'],
            'previousTxid' => $data['previous_txid'] ?? null,
            'memo' => $memo,
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }

    /**
     * Build a send (Standard/Direct) transaction payload from database data
     *
     * @param array $data Database transaction data with snake_case keys
     * @return array The send transaction payload
     */
    public function buildStandardFromDatabase(array $data): array
    {
        // Note no 'time' component
        $this->ensureRequiredFields($data, [
            'receiver_address', 'receiver_public_key',
            'amount', 'currency', 'txid'
        ]);
       
        $userAddress = $this->transportUtility->resolveUserAddressForTransport($data['receiver_address']);
        $memo = 'standard';

        return [
            'type' => 'send', 
            'receiverAddress' => $data['receiver_address'],
            'receiverPublicKey' => $data['receiver_public_key'],
            'amount' => $this->sanitizeNumber($data['amount']),
            'currency' => $this->sanitizeString($data['currency']),
            'txid' => $data['txid'],
            'previousTxid' => $data['previous_txid'] ?? null,
            'memo' => $memo,
            'description' => $data['description'],
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
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
        $userAddress = $this->transportUtility->resolveUserAddressForTransport($rp2pData['sender_address']);
        $transactionService = Application::getInstance()->services->getTransactionService();
        // This method returns data array for further processing, not final payload
        return [
            'time' => $rp2pData['time'],
            'receiver_address' => $rp2pData['sender_address'] ?? null,
            'receiver_public_key' => $rp2pData['sender_public_key'] ?? null,
            'amount' => $transactionService->removeTransactionFee($message),
            'currency' => $rp2pData['currency'] ?? 'USD',
            'txid' => $transactionService->createUniqueDatabaseTxid($message, $rp2pData),
            //'previous_txid' => $transactionService->fixPreviousTxid($this->currentUser->getPublicKey(), $rp2pData['sender_public_key']),
            'memo' => $rp2pData['hash'] ?? null,
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
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
        $userAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress'] ?? '');
        $hashInfo = $this->resolveHashInfo($request);

        return json_encode([
            'status' => 'accepted',
            'txid' => $request['txid'] ?? null,
            'memo' => $request['memo'] ?? null,
            'message' => "{$hashInfo['type']} {$hashInfo['value']} for transaction received by {$userAddress}",
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
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
        $userAddress = $this->transportUtility->resolveUserAddressForTransport(
            $request['senderAddress'] ?? $request['sender_address'] ?? ''
        );
        $hashInfo = $this->resolveHashInfo($request);

        $payload = [
            'type' => 'message',
            'typeMessage' => 'transaction',
            'inquiry' => false,
            'status' => 'completed',
            'hash' => $hashInfo['value'],
            'hashType' => $hashInfo['type'],
            'amount' => $this->sanitizeNumber($request['amount'] ?? 0),
            'currency' => $this->sanitizeString($request['currency'] ?? 'EIOU'),
            'message' => "transaction for hash {$hashInfo['value']} was successfully completed through intermediary",
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];

        // Include description if present (for end-recipient to store)
        if (isset($request['description']) && $request['description'] !== null) {
            $payload['description'] = $this->sanitizeString($request['description']);
        }

        return $payload;
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
        $userAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress'] ?? '');
        $hashInfo = $this->resolveHashInfo($request);

        $defaultReason = "{$hashInfo['type']} {$hashInfo['value']} for Transaction already exists in database of {$userAddress}";

        return json_encode([
            'status' => 'rejected',
            'txid' => $request['txid'] ?? null,
            'memo' => $request['memo'] ?? null,
            'message' => $reason ?? $defaultReason,
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
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