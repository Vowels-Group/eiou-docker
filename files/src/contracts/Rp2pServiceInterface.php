<?php
# Copyright 2025-2026 Vowels Group, LLC

use MessageDeliveryService;
use TransactionService;

/**
 * RP2P Service Interface
 *
 * Defines the contract for RP2P (Relay Peer-to-Peer) payment routing services.
 * Handles business logic for R peer-to-peer payment routing with reliable
 * message delivery, tracking, retry logic, and dead letter queue support.
 *
 * @package Eiou\Contracts
 */
interface Rp2pServiceInterface
{
    /**
     * Set the transaction service (setter injection for circular dependency)
     *
     * @param TransactionService $service Transaction service
     * @return void
     */
    public function setTransactionService(TransactionService $service): void;

    /**
     * Set the message delivery service (for lazy initialization)
     *
     * @param MessageDeliveryService $service Message delivery service
     * @return void
     */
    public function setMessageDeliveryService(MessageDeliveryService $service): void;

    /**
     * Handle incoming RP2P request
     *
     * Processes an incoming RP2P request by checking for corresponding P2P,
     * validating funds, storing the request, and routing it appropriately.
     *
     * @param array $request The RP2P request data
     * @return void
     */
    public function handleRp2pRequest(array $request): void;

    /**
     * Check if RP2P is possible for the given request
     *
     * Validates whether an RP2P transaction can proceed by checking for
     * duplicates, processing the request, and returning acceptance/rejection status.
     *
     * @param array|null $request Request data containing hash and other RP2P details
     * @param bool $echo Whether to echo the response (default: true)
     * @return bool True if RP2P possible, False otherwise
     */
    public function checkRp2pPossible($request, $echo = true);

    /**
     * Calculate and return fee percent of request, output fee information into the log
     *
     * @param array $p2p The P2P request data from the database
     * @param array $request The transaction request data
     * @return float Fee percent of request
     */
    public function feeInformation(array $p2p, array $request): float;
}
