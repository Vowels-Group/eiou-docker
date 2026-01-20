<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Message payload builder for contact and transaction messages
 *
 * This class handles building various message payloads for contact
 * status inquiries and transaction completion confirmations.
 *
 * IMPORTANT: This codebase does NOT use namespaces.
 */

require_once __DIR__ . '/BasePayload.php';

class MessagePayload extends BasePayload
{
    /**
     * Build the main payload (required by BasePayload)
     *
     * @param array $data Input data for building the payload
     * @return array The built payload
     */
    public function build(array $data): array
    {
        // This method can be implemented based on specific needs
        // For now, return empty array as Message payloads use specific methods
        return [];
    }

    /**
     * Build contact inquiry payload when user wants to inquire the status of the contact request
     *
     * @param string $address The recipient address
     * @return array The contact inquiry payload
     */
    public function buildContactIsAcceptedInquiry(string $address): array
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return [
            'type' => 'message', // message request type
            'typeMessage' => 'contact', // type of message
            'inquiry' => true, // request for information
            'message' => $myAddress . ' wants to know if we are contacts',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }

    /**
     * Build contact accepted payload when user has accepted the contact request
     *
     * @param string $address The recipient address
     * @param bool Encode payload in JSON
     * @return array|string Contact accepted payload (array if not encode, JSON otherwise)
     */
    public function buildContactIsAccepted(string $address, $encode = false): array|string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        $data = [
            'type' => 'message', // message request type
            'typeMessage' => 'contact', // type of message
            'status' => Constants::STATUS_ACCEPTED,
            'message' => $myAddress . ' confirms that we are contacts',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
        if($encode){
            return json_encode($data);
        }
        return $data;
    }

    /**
     * Build contact not yet accepted payload when user has not accepted the contact request yet
     *
     * @param string $address The recipient address
     * @return string JSON-encoded contact pending payload
     */
    public function buildContactIsNotYetAccepted(string $address): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'type' => 'message', // message request type
            'typeMessage' => 'contact', // type of message
            'status' => Constants::STATUS_REJECTED,
            'reason' => Constants::STATUS_PENDING,
            'message' => $myAddress . ' has not yet accepted your contact request',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build contact is unknown payload when user has no database record of the contact
     *
     * @param string $address The recipient address
     * @return string JSON-encoded contact unknown payload
     */
    public function buildContactIsUnknown(string $address): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'type' => 'message', // message request type
            'typeMessage' => 'contact', // type of message
            'status' => Constants::STATUS_REJECTED,
            'reason' => 'unknown',
            'message' => $myAddress . ' and you are not contacts',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build acknowledgment for contact acceptance message
     *
     * Returns an acknowledgment to the sender who notified us of accepting our contact request.
     * This enables proper delivery tracking stages (received -> inserted -> completed).
     *
     * @param string $address The recipient address (the one who sent the acceptance)
     * @return string JSON-encoded acknowledgment payload
     */
    public function buildContactAcceptanceAcknowledgment(string $address): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'status' => Constants::STATUS_ACCEPTED,
            'message' => $myAddress . ' confirms contact acceptance was received and processed',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build payload regarding the successful completion of a transaction
     *
     * @param array $message Message data containing transaction hash
     * @return string JSON-encoded transaction completion payload
     */
    public function buildTransactionCompletedCorrectly(array $message): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($message['senderAddress']);
        $hash = $message['hash'];
        return json_encode([
            'type' => 'message', // message request type
            'typeMessage' => 'transaction', // type of message
            'status' => Constants::STATUS_COMPLETED,
            'hash' => $hash,
            'message' => 'Transaction with hash ' . print_r($hash, true) . ' was received succesfully by end-recipient',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build inquiry payload regarding the completion status of a transaction
     *
     * @param array $message Message data containing transaction details and optional description
     * @return array The transaction inquiry payload
     */
    public function buildTransactionCompletedInquiry(array $message): array
    {
        $hash = $message['hash'];
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($message['senderAddress']);
        $payload = [
            'type' => 'message', // message request type
            'typeMessage' => 'transaction', // type of message
            'inquiry' => true, // request for information
            'status' => Constants::STATUS_COMPLETED,
            'hash' => $hash,
            'hashType' => $message['hashType'],
            'message' => $myAddress . ' is requesting information about transaction with memo ' . $hash,
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];

        // Include description if present (for end-recipient to store)
        if (isset($message['description']) && $message['description'] !== null) {
            $payload['description'] = $this->sanitizeString($message['description']);
        }

        // Include initialSenderAddress if present (for end-recipient tracking)
        if (isset($message['initialSenderAddress']) && $message['initialSenderAddress'] !== null) {
            $payload['initialSenderAddress'] = $this->sanitizeString($message['initialSenderAddress']);
        }

        return $payload;
    }

    /**
     * Build acknowledgment for transaction completion message
     *
     * Returns an acknowledgment to the sender who notified us of a completed transaction.
     * This enables proper delivery tracking stages (received -> inserted -> completed).
     *
     * @param array $message Message data containing transaction hash and hashType
     * @return string JSON-encoded acknowledgment payload
     */
    public function buildTransactionCompletionAcknowledgment(array $message): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($message['senderAddress']);
        $hash = $message['hash'] ?? 'unknown';
        $hashType = $message['hashType'] ?? 'unknown';
        return json_encode([
            'status' => 'acknowledged',
            'hash' => $hash,
            'hashType' => $hashType,
            'message' => $myAddress . ' confirms transaction completion was received and processed',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build transaction status response for inquiry requests
     *
     * Returns the actual transaction status to the inquiring sender.
     * Used by end-recipients to respond to original senders checking if transaction completed.
     *
     * @param array $message Message data containing transaction hash and hashType
     * @param string $status The actual transaction status (completed, pending, etc.)
     * @return string JSON-encoded status response payload
     */
    public function buildTransactionStatusResponse(array $message, string $status): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($message['senderAddress']);
        $hash = $message['hash'] ?? 'unknown';
        $hashType = $message['hashType'] ?? 'unknown';
        return json_encode([
            'type' => 'message',
            'typeMessage' => 'transaction',
            'status' => $status,
            'hash' => $hash,
            'hashType' => $hashType,
            'message' => 'Transaction status is: ' . $status,
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build transaction not found response for inquiry requests
     *
     * Returns a 'not_found' response when the inquired transaction does not exist.
     *
     * @param array $message Message data containing transaction hash and hashType
     * @return string JSON-encoded not found response payload
     */
    public function buildTransactionNotFound(array $message): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($message['senderAddress']);
        $hash = $message['hash'] ?? 'unknown';
        $hashType = $message['hashType'] ?? 'unknown';
        return json_encode([
            'type' => 'message',
            'typeMessage' => 'transaction',
            'status' => 'not_found',
            'hash' => $hash,
            'hashType' => $hashType,
            'message' => 'Transaction with hash ' . $hash . ' not found',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build transaction chain sync request payload
     *
     * Sent when a contact needs to sync their transaction chain due to previousTxid mismatch.
     *
     * @param string $contactAddress The contact's address
     * @param string $contactPublicKey The contact's public key
     * @param string|null $lastKnownTxid The last known txid in the mutual chain (or null)
     * @return array The sync request payload
     */
    public function buildTransactionSyncRequest(string $contactAddress, string $contactPublicKey, ?string $lastKnownTxid = null): array
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($contactAddress);
        return [
            'type' => 'message',
            'typeMessage' => 'sync',
            'syncType' => 'transaction_chain',
            'inquiry' => true,
            'contactPublicKey' => $contactPublicKey,
            'lastKnownTxid' => $lastKnownTxid,
            'message' => $myAddress . ' is requesting transaction chain sync',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }

    /**
     * Build transaction chain sync response payload
     *
     * Returns transactions that the requester is missing from their chain.
     *
     * @param string $address The requester's address
     * @param array $transactions Array of transaction data to sync
     * @param string|null $latestTxid The latest txid in the chain
     * @return string JSON-encoded sync response payload
     */
    public function buildTransactionSyncResponse(string $address, array $transactions, ?string $latestTxid): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'type' => 'message',
            'typeMessage' => 'sync',
            'syncType' => 'transaction_chain',
            'inquiry' => false,
            'status' => Constants::STATUS_ACCEPTED,
            'transactions' => $transactions,
            'latestTxid' => $latestTxid,
            'transactionCount' => count($transactions),
            'message' => 'Transaction chain sync data provided',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build transaction sync acknowledgment payload
     *
     * Sent after successfully processing sync data.
     *
     * @param string $address The sync responder's address
     * @param int $processedCount Number of transactions processed
     * @return string JSON-encoded acknowledgment payload
     */
    public function buildTransactionSyncAcknowledgment(string $address, int $processedCount): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'status' => 'acknowledged',
            'processedCount' => $processedCount,
            'message' => $myAddress . ' has processed ' . $processedCount . ' transactions from sync',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build transaction sync rejection payload
     *
     * Sent when sync request cannot be fulfilled.
     *
     * @param string $address The requester's address
     * @param string $reason Rejection reason
     * @return string JSON-encoded rejection payload
     */
    public function buildTransactionSyncRejection(string $address, string $reason): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'type' => 'message',
            'typeMessage' => 'sync',
            'syncType' => 'transaction_chain',
            'inquiry' => false,
            'status' => Constants::STATUS_REJECTED,
            'reason' => $reason,
            'message' => 'Transaction chain sync rejected: ' . $reason,
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build P2P status inquiry payload
     *
     * Sent when checking the completion status of a P2P transaction before expiring.
     * Used to verify if the P2P chain was completed but the completion message was lost.
     *
     * @param string $address The recipient address (P2P sender to query)
     * @param string $hash The P2P hash to inquire about
     * @return array The P2P status inquiry payload
     */
    public function buildP2pStatusInquiry(string $address, string $hash): array
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return [
            'type' => 'message',
            'typeMessage' => 'p2p',
            'inquiry' => true,
            'hash' => $hash,
            'message' => $myAddress . ' is inquiring about P2P status for hash ' . $hash,
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }

    /**
     * Build P2P status response payload
     *
     * Returns the P2P status to the inquiring party.
     *
     * @param string $address The requester's address
     * @param string $hash The P2P hash
     * @param string $status The P2P status (completed, expired, etc.)
     * @return string JSON-encoded status response payload
     */
    public function buildP2pStatusResponse(string $address, string $hash, string $status): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'type' => 'message',
            'typeMessage' => 'p2p',
            'inquiry' => false,
            'hash' => $hash,
            'status' => $status,
            'message' => 'P2P status for hash ' . $hash . ' is: ' . $status,
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build sync negotiation request payload
     *
     * Request bidirectional sync by sharing our txid list.
     * The recipient will compare and return transactions we're missing.
     *
     * @param string $address The recipient's address
     * @param string $recipientPublicKey The recipient's public key
     * @param array $txidList Our local list of transaction IDs
     * @return array The sync negotiation request payload
     */
    public function buildSyncNegotiationRequest(string $address, string $recipientPublicKey, array $txidList): array
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return [
            'type' => 'message',
            'typeMessage' => 'sync_negotiation',
            'inquiry' => true,
            'txid_list' => $txidList,
            'message' => 'Bidirectional sync negotiation request',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ];
    }

    /**
     * Build sync negotiation response payload
     *
     * Respond to sync negotiation with our txid list
     * and any transactions the requester is missing.
     *
     * @param string $address The requester's address
     * @param array $txidList Our local list of transaction IDs
     * @param array $transactions Transactions the requester is missing
     * @return string JSON-encoded sync negotiation response payload
     */
    public function buildSyncNegotiationResponse(string $address, array $txidList, array $transactions): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'type' => 'message',
            'typeMessage' => 'sync_negotiation',
            'inquiry' => false,
            'status' => Constants::STATUS_ACCEPTED,
            'txid_list' => $txidList,
            'transactions' => $transactions,
            'message' => 'Bidirectional sync negotiation response',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }

    /**
     * Build sync negotiation rejection payload
     *
     * Reject sync negotiation request.
     *
     * @param string $address The requester's address
     * @param string $reason The rejection reason
     * @return string JSON-encoded rejection payload
     */
    public function buildSyncNegotiationRejection(string $address, string $reason): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'type' => 'message',
            'typeMessage' => 'sync_negotiation',
            'inquiry' => false,
            'status' => Constants::STATUS_REJECTED,
            'reason' => $reason,
            'message' => 'Sync negotiation rejected: ' . $reason,
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
    }
}
