<?php
# Copyright 2025 The Vowels Company

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
            'status' => 'accepted',
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
            'status' => 'rejected',
            'reason' => 'pending',
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
            'status' => 'rejected',
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
            'status' => 'accepted',
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
            'status' => 'completed',
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
            'status' => 'completed',
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
}
