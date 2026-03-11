<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Events;

/**
 * Delivery Events
 *
 * Event constants for message delivery lifecycle events.
 * These events enable loose coupling between the delivery system and
 * message-type-specific services that need to run post-delivery logic.
 *
 * Primary use case: Contact creation via async delivery.
 * When a contact create message is queued for retry (e.g., recipient in
 * maintenance mode) and the retry later succeeds, the RETRY_DELIVERY_COMPLETED
 * event allows ContactSyncService to run post-delivery logic (contact insertion,
 * balance initialization, etc.) without tight coupling to MessageDeliveryService.
 *
 * Usage:
 *   // Subscribe to retry delivery completion
 *   EventDispatcher::getInstance()->subscribe(DeliveryEvents::RETRY_DELIVERY_COMPLETED, function($data) {
 *       $messageType = $data['message_type'];
 *       $response = $data['response'];
 *       // Handle post-delivery logic...
 *   });
 */
class DeliveryEvents
{
    /**
     * Dispatched when a retried message delivery completes successfully
     *
     * This event fires only for messages processed through processRetryQueue(),
     * not for immediate successful deliveries (which are handled inline by the caller).
     *
     * Event data:
     *   - message_type: string - Type of message (e.g., 'contact', 'transaction')
     *   - message_id: string - Unique message identifier
     *   - recipient_address: string - Recipient's address
     *   - response: array - Decoded response from the recipient
     *   - signing_data: array|null - Signing data from the delivery attempt
     *   - stored_payload: array - The stored payload (may include _contact_params metadata)
     */
    public const RETRY_DELIVERY_COMPLETED = 'delivery.retry_completed';
}
