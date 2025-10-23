<?php
# Copyright 2025

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
     * @var Constants Environment constants
     */
    private Constants $constants;

    /**
     * @var ServiceContainer Service container for accessing repositories
     */
    private ServiceContainer $container;

    /**
     * Constructor
     *
     * @param Constants $constants Environment constants
     * @param ServiceContainer $container Service container
     */
    public function __construct(
        Constants $constants,
        ServiceContainer $container
        )
    {
        $this->constants = $constants;
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
     * Validate send request structure
     *
     * @param array $data Send request data
     * @return array Validation result [valid => bool, error => string|null]
     */
    public function validateSendRequest(array $data): array
    {
        // Check minimum parameters
        if (count($data) < 4) {
            return [
                'valid' => false,
                'error' => 'Invalid send request: missing parameters'
            ];
        }

        // Validate amount
        $amount = $data[3];
        if (!is_numeric($amount) || floatval($amount) <= 0) {
            return [
                'valid' => false,
                'error' => 'Invalid amount: must be positive number'
            ];
        }

        // Validate currency if provided
        if (isset($data[4])) {
            $currency = strtoupper($data[4]);
            if (strlen($currency) !== 3) {
                return [
                    'valid' => false,
                    'error' => 'Invalid currency: must be 3-letter code'
                ];
            }
        }

        return ['valid' => true, 'error' => null];
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
        $contactService = $this->container->getContactService();
        $transactionService = $this->container->getTransactionService();

        $pubkey = $request['senderPublicKey'] ?? $request['sender_public_key'];

        $totalSent = $transactionService->calculateTotalSent($pubkey);
        $totalReceived = $transactionService->calculateTotalReceived($pubkey);
        $currentBalance = $totalSent - $totalReceived;

        $creditLimit = $contactService->getCreditLimit($pubkey);

        return $currentBalance + $creditLimit;
    }
}
