<?php
# Copyright 2025-2026 Vowels Group, LLC
namespace Eiou\Contracts;

use Eiou\Services\MessageDeliveryService;
use Eiou\Exceptions\FatalServiceException;

/**
 * Message Service Interface
 *
 * Defines the contract for message processing and validation.
 * Handles all business logic for message processing including
 * transaction messages, contact messages, P2P status messages, and sync messages.
 */
interface MessageServiceInterface
{
    /**
     * Set the message delivery service (for lazy initialization)
     *
     * @param MessageDeliveryService $service Message delivery service instance
     * @return void
     */
    public function setMessageDeliveryService(MessageDeliveryService $service): void;

    /**
     * Check if message is from a valid source
     *
     * Validates that the message sender is either:
     * - An existing contact (by public key)
     * - The original sender of a transaction (by hash verification)
     *
     * @param array $decodedMessage Decoded message data containing senderPublicKey, senderAddress, and typeMessage
     * @return bool True if the message is from a valid source, false otherwise
     */
    public function checkMessageValidity(array $decodedMessage): bool;

    /**
     * Handle incoming message request
     *
     * Routes the message to the appropriate handler based on typeMessage:
     * - transaction: Handles transaction messages (inquiry or completion)
     * - contact: Handles contact messages (inquiry or status update)
     * - p2p: Handles P2P status messages
     * - sync: Handles sync messages
     *
     * Note: With the new payload structure, the message content is already decoded
     * by index.html before being passed here. The $request parameter contains
     * the merged content (message fields + senderAddress/senderPublicKey).
     *
     * @param array $request Request data (already decoded)
     * @return void
     * @throws FatalServiceException If message is from an invalid source
     */
    public function handleMessageRequest(array $request): void;

    /**
     * Validate message structure
     *
     * Checks that the required fields are present in the message:
     * - typeMessage: The type of message
     * - senderAddress: The sender's address
     *
     * Note: With the new payload structure, the message content is already decoded.
     * This method validates the merged request structure.
     *
     * @param array $request Request data (already decoded)
     * @return bool True if the message structure is valid, false otherwise
     */
    public function validateMessageStructure(array $request): bool;

    /**
     * Build message response
     *
     * Creates a JSON-encoded response with status, message, and optional additional data.
     *
     * @param string $status Response status (e.g., 'accepted', 'rejected', 'completed')
     * @param string $message Response message describing the result
     * @param array $additionalData Additional data to include in the response
     * @return string JSON-encoded response string
     */
    public function buildMessageResponse(string $status, string $message, array $additionalData = []): string;
}
