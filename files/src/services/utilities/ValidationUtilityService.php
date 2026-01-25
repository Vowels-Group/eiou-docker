<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Validation Utility Service
 *
 * Handles validation logic for requests and data structures.
 *
 * @package Services\Utilities
 */

require_once __DIR__ . '/../ServiceContainer.php';


class ValidationUtilityService implements ValidationUtilityServiceInterface
{
    /**
     * @var ServiceContainer Service container for accessing repositories
     */
    private ServiceContainer $container;

    /**
     * Constructor
     *
     * @param ServiceContainer $container Service container
     */
    public function __construct(
        ServiceContainer $container
        )
    {
        $this->container = $container;
    }

    /**
     * Validate P2P request level
     *
     * @param array $request Request data
     * @return bool True if valid
     */
    public function validateRequestLevel(array $request): bool
    {
        if (!isset($request['requestLevel'], $request['maxRequestLevel'])) {
            return false;
        }

        return $request['requestLevel'] <= $request['maxRequestLevel'];
    }

    /**
     * Verify cryptographic signature on request
     *
     * @param array $request Request data with signature
     * @return bool True if signature is valid
     */
    public function verifyRequestSignature(array $request): bool
    {
        if (!isset($request['senderPublicKey'], $request['message'], $request['signature'])) {
            return false;
        }

        $publicKeyResource = openssl_pkey_get_public($request['senderPublicKey']);
        if ($publicKeyResource === false) {
            return false;
        }

        $verified = openssl_verify(
            $request['message'],
            base64_decode($request['signature']),
            $publicKeyResource
        );

        return $verified === 1;
    }

    /**
     * Calculate available funds for user with contact
     *
     * @param array $request Request data with senderPublicKey
     * @return int Available funds
     */
    public function calculateAvailableFunds(array $request): int
    {
        $balanceRepository = $this->container->getBalanceRepository();
        $pubkey = $request['senderPublicKey'] ?? $request['sender_public_key'];

        $totalSent = $balanceRepository->getContactSentBalance($pubkey, $request['currency']);
        $totalReceived = $balanceRepository->getContactReceivedBalance($pubkey, $request['currency']);
        // Contact's available funds = what we've sent to them (they received) minus what they've sent to us (we received)
        // In the balances table: 'sent' is what WE sent TO contact, 'received' is what WE received FROM contact
        $currentBalance = $totalSent - $totalReceived;

        return $currentBalance;
    }
}
