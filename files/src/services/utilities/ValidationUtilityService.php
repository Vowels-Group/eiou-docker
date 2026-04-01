<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Utilities;

use Eiou\Contracts\ValidationUtilityServiceInterface;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Core\UserContext;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactRepository;
use Eiou\Services\ServiceContainer;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Logger;

/**
 * Validation Utility Service
 *
 * Handles validation logic for requests and data structures.
 */
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

        $requestLevel = (int) $request['requestLevel'];
        // Use server-side max to prevent client-supplied values from bypassing limits
        $serverMax = UserContext::getInstance()->getMaxP2pLevel();
        $maxRequestLevel = min((int) $request['maxRequestLevel'], $requestLevel + $serverMax);

        return $requestLevel >= 0 && $requestLevel <= $maxRequestLevel;
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
     * @return SplitAmount Available funds
     */
    public function calculateAvailableFunds(array $request): SplitAmount
    {
        $balanceRepository = $this->container->getRepositoryFactory()->get(BalanceRepository::class);
        $pubkey = $request['senderPublicKey'] ?? $request['sender_public_key'];

        $totalSent = $balanceRepository->getContactSentBalance($pubkey, $request['currency']);
        $totalReceived = $balanceRepository->getContactReceivedBalance($pubkey, $request['currency']);
        // Contact's available funds = what we've sent to them (they received) minus what they've sent to us (we received)
        // In the balances table: 'sent' is what WE sent TO contact, 'received' is what WE received FROM contact
        return $totalSent->subtract($totalReceived);
    }

    /**
     * Check version compatibility for an incoming message envelope.
     *
     * If the sender is a known contact, updates their stored remote_version
     * (only when it changes, to avoid unnecessary writes). Logs a warning
     * only on first detection of an incompatible version.
     *
     * @param array $envelope The transport envelope (senderPublicKey, version, senderAddress)
     * @param string $messageType The message type (for logging context)
     * @return array|null Null if compatible, or ['reason' => ..., 'action' => ...] if not
     */
    public function verifyVersionCompatibility(array $envelope, string $messageType): ?array
    {
        $incomingVersion = $envelope['version'] ?? null;
        $versionCheck = InputValidator::checkVersionCompatibility($incomingVersion);

        // Update stored remote_version for known contacts regardless of compatibility
        $senderPubkey = $envelope['senderPublicKey'] ?? null;
        if ($senderPubkey !== null) {
            $contactRepo = $this->container->getRepositoryFactory()->get(ContactRepository::class);
            $existingContact = $contactRepo->getContactByPubkey($senderPubkey);

            if ($existingContact !== null) {
                $storedVersion = $existingContact['remote_version'] ?? null;

                if ($incomingVersion !== $storedVersion) {
                    $contactRepo->updateContactFields($senderPubkey, [
                        'remote_version' => $incomingVersion,
                    ]);

                    // Log only on first detection (version changed) to avoid spam
                    if ($versionCheck !== null) {
                        Logger::getInstance()->warning('Incompatible node version detected', [
                            'sender_version' => $incomingVersion ?? 'unknown',
                            'min_required' => Constants::MIN_COMPATIBLE_VERSION,
                            'message_type' => $messageType,
                            'sender_address' => $envelope['senderAddress'] ?? 'unknown',
                            'action' => $versionCheck['action'],
                        ]);
                    }
                }
            }
        }

        return $versionCheck;
    }
}
