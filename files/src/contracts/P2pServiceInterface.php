<?php
namespace Eiou\Contracts;

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
     * @return float The total credit amount in P2P
     */
    public function getCreditInP2p(string $pubkey, ?string $currency = null): float;

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
}
