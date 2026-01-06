<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

/**
 * Validation Utility Service
 *
 * Handles validation logic for requests and data structures.
 *
 * @package Services\Utilities
 */

require_once __DIR__ . '/../ServiceContainer.php';

class ValidationUtilityService
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
        $balaceRepository = $this->container->getBalanceRepository();
        $pubkey = $request['senderPublicKey'] ?? $request['sender_public_key'];
 
        $totalSent = $balaceRepository->getContactSentBalance($pubkey,$request['currency']);
        $totalReceived = $balaceRepository->getContactReceivedBalance($pubkey,$request['currency']);
        $currentBalance = $totalSent - $totalReceived;

        return $currentBalance;
    }
}
