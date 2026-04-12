<?php
namespace Eiou\Contracts;

use Eiou\Core\SplitAmount;

/**
 * Interface for P2P (peer-to-peer) services.
 *
 * Defines the contract for handling peer-to-peer payment requests
 * and managing P2P transaction state.
 */
interface P2pServiceInterface
{
    /**
     * Check if a P2P transaction is possible.
     *
     * @param array $request The P2P request to validate
     * @param bool $echo Whether to output validation messages
     * @return bool True if the P2P transaction is possible
     */
    public function checkP2pPossible(array $request, bool $echo = true): bool;

    /**
     * Handle an incoming P2P request.
     *
     * @param array $request The P2P request data
     * @return void
     */
    public function handleP2pRequest(array $request): void;

    /**
     * Send a P2P payment request.
     *
     * @param array $data The P2P request data to send
     * @return void
     */
    public function sendP2pRequest(array $data): void;

    /**
     * Get a P2P request by its hash.
     *
     * @param string $hash The unique hash identifier
     * @return array|null The P2P request data or null if not found
     */
    public function getByHash(string $hash): ?array;

    /**
     * Update the status of a P2P request.
     *
     * @param string $hash The unique hash identifier
     * @param string $status The new status
     * @param bool $completed Whether the request is completed
     * @return bool True if the status was updated successfully
     */
    public function updateStatus(string $hash, string $status, bool $completed = false): bool;

    /**
     * Get the total credit in P2P for a public key.
     *
     * @param string $pubkey The public key to check
     * @return SplitAmount The total credit amount in P2P
     */
    public function getCreditInP2p(string $pubkey, ?string $currency = null): SplitAmount;

    /**
     * Get P2P statistics.
     *
     * @return array Statistics including counts, totals, and averages
     */
    public function getStatistics(): array;

    /**
     * Process a single P2P message in a worker process
     *
     * Atomically claims the P2P, processes it, and transitions to sent/cancelled.
     *
     * @param string $hash P2P hash to process
     * @param int $workerPid PID of this worker process
     * @return bool True if processed successfully
     */
    public function processSingleP2p(string $hash, int $workerPid): bool;

    /**
     * Send cancel notification upstream for a P2P hash
     *
     * Called when a node has no viable route (dead-end) or when a relay P2P
     * expires with no candidates. Notifies all upstream senders so they can
     * count this as a responded contact and trigger selection/cancellation.
     *
     * @param string $hash The P2P hash to cancel
     * @return void
     */
    public function sendCancelNotificationForHash(string $hash): void;

    /**
     * Send a P2P message to a contact
     *
     * @param string $messageType The message type (e.g. 'p2p', 'rp2p', 'route_cancel')
     * @param string $address Recipient address
     * @param array $payload Message payload
     * @param string|null $messageId Optional unique message ID for tracking
     * @return array Response with 'success', 'response', 'raw', and 'messageId' keys
     */
    public function sendP2pMessage(string $messageType, string $address, array $payload, ?string $messageId = null): array;

    /**
     * Send a cancel notification to a specific address
     *
     * Builds a properly formed rp2p cancel payload (with senderAddress,
     * senderPublicKey, etc.) and sends it to the given address.
     *
     * @param string $hash The P2P hash
     * @param string $address Recipient address to cancel
     * @return void
     */
    public function sendCancelToAddress(string $hash, string $address): void;

    /**
     * Broadcast a full cancellation downstream to all accepted contacts
     *
     * Called when the originator rejects a P2P or when a relay receives a
     * full_cancel message. Sends route_cancel with full_cancel flag to all
     * accepted contacts so they can cancel their P2P records, release capacity
     * reservations, and propagate further downstream.
     *
     * @param string $hash The P2P hash to cancel
     * @return void
     */
    public function broadcastFullCancelForHash(string $hash): void;
}
