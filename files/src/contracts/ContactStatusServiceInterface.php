<?php
# Copyright 2025-2026 Vowels Group, LLC


/**
 * Contact Status Service Interface
 *
 * Defines the contract for handling contact status operations including
 * incoming ping requests from other nodes and outgoing manual pings.
 * Responds with pong containing local chain state for comparison.
 */
interface ContactStatusServiceInterface
{
    /**
     * Set the sync service (setter injection for circular dependency)
     *
     * @param SyncService $service Sync service
     * @return void
     */
    public function setSyncService(SyncService $service): void;

    /**
     * Set the rate limiter service (setter injection for circular dependency)
     *
     * @param RateLimiterService $service Rate limiter service
     * @return void
     */
    public function setRateLimiterService(RateLimiterService $service): void;

    /**
     * Handle incoming ping request
     *
     * Processes an incoming ping from another node. Validates the sender,
     * compares chain states, triggers sync if needed, and responds with pong.
     *
     * @param array $request The ping request data containing:
     *                       - 'senderPublicKey' (string): Sender's public key
     *                       - 'senderAddress' (string): Sender's address
     *                       - 'prevTxid' (string|null): Sender's last transaction ID
     *                       - 'requestSync' (bool): Whether to trigger sync on mismatch
     * @return void Echoes JSON response
     */
    public function handlePingRequest(array $request): void;

    /**
     * Get the contact repository (for processor access)
     *
     * @return ContactRepository
     */
    public function getRepository(): ContactRepository;

    /**
     * Manually ping a specific contact and update their status
     *
     * Rate limited to 3 pings per 5 minutes per user to prevent abuse.
     * Aligns with the automatic ping processor's minimum interval.
     *
     * @param string $identifier Contact name or address to ping
     * @return array Result containing:
     *               - 'success' (bool): Whether the operation succeeded
     *               - 'contact_name' (string): Name of the pinged contact
     *               - 'online_status' (string): 'online' or 'offline'
     *               - 'chain_valid' (bool|null): Whether transaction chains match
     *               - 'message' (string): Human-readable status message
     *               - 'error' (string): Error code if failed
     *               - 'retry_after' (int): Seconds to wait if rate limited
     */
    public function pingContact(string $identifier): array;
}
