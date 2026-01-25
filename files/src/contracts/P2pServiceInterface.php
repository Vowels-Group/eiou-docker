<?php

declare(strict_types=1);

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
    public function getCreditInP2p(string $pubkey): float;

    /**
     * Get P2P statistics.
     *
     * @return array Statistics including counts, totals, and averages
     */
    public function getStatistics(): array;
}
