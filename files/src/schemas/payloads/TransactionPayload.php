<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

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

        // NOTE: endRecipientAddress and initialSenderAddress are NOT included in the payload
        // These are local tracking fields that should NOT be signed. They are added to the
        // database via updateTrackingFields() after the transaction is inserted.
        // This prevents sync verification failures since the sync partner doesn't have this info.

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
        $this->ensureRequiredFields($data, [
            'receiver_address', 'receiver_public_key',
            'amount', 'currency', 'txid'
        ]);

        $userAddress = $this->transportUtility->resolveUserAddressForTransport($data['receiver_address']);
        $memo = 'standard';

        return [
            'type' => 'send',
            'time' => $data['time'] ?? null, // Include time for receiver to store
            'receiverAddress' => $data['receiver_address'],
            'receiverPublicKey' => $data['receiver_public_key'],
            'amount' => $this->sanitizeNumber($data['amount']),
            'currency' => $this->sanitizeString($data['currency']),
            'txid' => $data['txid'],
            'previousTxid' => $data['previous_txid'] ?? null,
            'memo' => $memo,
            // description is ALWAYS included for standard transactions (even if null)
            // This ensures signature reconstruction matches what was signed
            'description' => $data['description'] ?? null,
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
        $transactionRepository = Application::getInstance()->services->getTransactionRepository();

        // This method returns data array for further processing, not final payload
        return [
            'time' => $rp2pData['time'],
            'receiver_address' => $rp2pData['sender_address'] ?? null,
            'receiver_public_key' => $rp2pData['sender_public_key'] ?? null,
            'amount' => $transactionService->removeTransactionFee($message),
            'currency' => $rp2pData['currency'] ?? 'USD',
            'txid' => $transactionService->createUniqueDatabaseTxid($message, $rp2pData),
            // Include previous_txid for chain validation on receiver side
            'previous_txid' => $transactionRepository->getPreviousTxid(
                $this->currentUser->getPublicKey(),
                $rp2pData['sender_public_key'] ?? ''
            ),
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
            'status' => Constants::STATUS_ACCEPTED,
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
            'status' => Constants::STATUS_COMPLETED,
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

        // Include initialSenderAddress if present (for end-recipient tracking)
        if (isset($request['initialSenderAddress']) && $request['initialSenderAddress'] !== null) {
            $payload['initialSenderAddress'] = $this->sanitizeString($request['initialSenderAddress']);
        }

        return $payload;
    }

    /**
     * Build a transaction rejection payload
     *
     * @param array $request The transaction request data
     * @param string $reason Rejection reason code (duplicate, insufficient_funds, contact_blocked, invalid_previous_txid, etc.)
     * @param string|null $expectedTxid Optional expected previous txid (included for invalid_previous_txid rejections)
     * @return string JSON encoded rejection payload
     */
    public function buildRejection(array $request, string $reason = 'duplicate', ?string $expectedTxid = null): string
    {
        $userAddress = $this->transportUtility->resolveUserAddressForTransport($request['senderAddress'] ?? '');
        $hashInfo = $this->resolveHashInfo($request);

        $message = $this->buildRejectionMessage($hashInfo, $userAddress, $reason);

        $response = [
            'status' => Constants::STATUS_REJECTED,
            'reason' => $reason,
            'txid' => $request['txid'] ?? null,
            'memo' => $request['memo'] ?? null,
            'message' => $message,
            'senderAddress' => $userAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];

        // Include expected_txid for invalid_previous_txid rejections to help with resync
        if ($reason === 'invalid_previous_txid' && $expectedTxid !== null) {
            $response['expected_txid'] = $expectedTxid;
        }

        return json_encode($response);
    }

    /**
     * Build a human-readable rejection message based on the reason code
     *
     * @param array $hashInfo Array with 'type' and 'value' keys
     * @param string $userAddress The user address
     * @param string $reason The rejection reason code
     * @return string Human-readable rejection message
     */
    private function buildRejectionMessage(array $hashInfo, string $userAddress, string $reason): string
    {
        $identifier = "{$hashInfo['type']} {$hashInfo['value']}";

        $messages = [
            'duplicate' => "{$identifier} for Transaction already exists in database of {$userAddress}",
            'insufficient_funds' => "{$identifier} for Transaction rejected by {$userAddress}: insufficient funds",
            'contact_blocked' => "{$identifier} for Transaction rejected by {$userAddress}: contact is blocked",
            'credit_limit_exceeded' => "{$identifier} for Transaction rejected by {$userAddress}: credit limit exceeded",
            'invalid_previous_txid' => "{$identifier} for Transaction rejected by {$userAddress}: previous transaction ID mismatch",
        ];

        return $messages[$reason] ?? "{$identifier} for Transaction rejected by {$userAddress}: {$reason}";
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