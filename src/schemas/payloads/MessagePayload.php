<?php
/**
 * Message payload builder for contact and transaction messages
 *
 * Copyright 2025
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
     * @return string JSON-encoded contact accepted payload
     */
    public function buildContactIsAccepted(string $address): string
    {
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($address);
        return json_encode([
            'type' => 'message', // message request type
            'typeMessage' => 'contact', // type of message
            'status' => 'accepted',
            'message' => $myAddress . ' confirms that we are contacts',
            'senderAddress' => $myAddress,
            'senderPublicKey' => $this->currentUser->getPublicKey(),
        ]);
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
     * @param array $message Message data containing transaction details
     * @return array The transaction inquiry payload
     */
    public function buildTransactionCompletedInquiry(array $message): array
    {
        $hash = $message['hash'];
        $myAddress = $this->transportUtility->resolveUserAddressForTransport($message['senderAddress']);
        return [
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
    }
}
