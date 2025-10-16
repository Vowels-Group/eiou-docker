<?php

require_once __DIR__ . '/../../core/UserContext.php';
require_once __DIR__ . '/BasePayload.php';

/**
 * P2P (Peer to Peer) payload builder
 *
 * Handles building payloads for P2P operations including initial requests,
 * forwarding, acceptance, and rejection.
 */
class P2pPayload extends BasePayload
{
    /**
     * Build the main P2P payload
     *
     * @param array $data P2P data
     * @return array The P2P payload
     */
    public function build(array $data): array
    {
        $this->ensureRequiredFields($data, [
            'hash', 'salt', 'time', 'currency',
            'amount', 'minRequestLevel', 'maxRequestLevel', 'receiverAddress'
        ]);

        $user = $this->userContext->getUser();
        $userAddress = $this->resolveUserAddressForTransport($data['receiverAddress']);

        return [
            'type' => 'p2p',
            'hash' => $data['hash'],
            'salt' => $data['salt'],
            'time' => $data['time'],
            'expiration' => $data['time'] + $this->getP2pExpirationTime(),
            'currency' => $this->sanitizeString($data['currency']),
            'amount' => $this->sanitizeNumber($data['amount']),
            'requestLevel' => (int) $data['minRequestLevel'],
            'maxRequestLevel' => (int) $data['maxRequestLevel'],
            'senderPublicKey' => $user ? $user->getPublicKey() : null,
            'senderAddress' => $userAddress,
        ];
    }

    /**
     * Build P2P payload from database data
     *
     * @param array $data Database P2P data with snake_case keys
     * @return array The P2P payload for forwarding
     */
    public function buildFromDatabase(array $data): array
    {
        $this->ensureRequiredFields($data, [
            'hash', 'salt', 'time', 'expiration', 'currency',
            'amount', 'request_level', 'max_request_level', 'sender_address'
        ]);

        $user = $this->userContext->getUser();
        $userAddress = $this->resolveUserAddressForTransport($data['sender_address']);

        return [
            'type' => 'p2p',
            'hash' => $data['hash'],
            'salt' => $data['salt'],
            'time' => $data['time'],
            'expiration' => $data['expiration'],
            'currency' => $this->sanitizeString($data['currency']),
            'amount' => $this->sanitizeNumber($data['amount']),
            'requestLevel' => ((int) $data['request_level']) + 1, // Increment request level for forwarding
            'maxRequestLevel' => (int) $data['max_request_level'],
            'senderPublicKey' => $user ? $user->getPublicKey() : null,
            'senderAddress' => $userAddress,
        ];
    }

    /**
     * Build P2P acceptance (received) payload
     *
     * @param array $request The P2P request data
     * @return array The acceptance payload
     */
    public function buildAcceptance(array $request): array
    {
        $this->ensureRequiredFields($request, ['hash', 'senderAddress']);

        $receiver = $this->resolveUserAddressForTransport($request['senderAddress']);

        return [
            'status' => 'received',
            'message' => "hash {$request['hash']} for P2P received by {$receiver}",
        ];
    }

    /**
     * Build P2P rejection payload
     *
     * @param array $request The P2P request data
     * @param string $reason Optional rejection reason
     * @return array The rejection payload
     */
    public function buildRejection(array $request, string $reason = 'already exists'): array
    {
        $this->ensureRequiredFields($request, ['hash', 'senderAddress']);

        $receiver = $this->resolveUserAddressForTransport($request['senderAddress']);

        return [
            'status' => 'rejected',
            'reason' => $reason,
            'message' => "hash {$request['hash']} for P2P already exists in database of {$receiver}",
        ];
    }

    /**
     * Build P2P inquiry payload
     *
     * @param string $hash The P2P hash to inquire about
     * @return array The inquiry payload
     */
    public function buildInquiry(string $hash): array
    {
        $user = $this->userContext->getUser();

        return [
            'type' => 'p2p',
            'inquiry' => true,
            'hash' => $hash,
            'senderPublicKey' => $user ? $user->getPublicKey() : null,
            'senderAddress' => $this->getUserAddress(),
        ];
    }

    /**
     * Build P2P status update payload
     *
     * @param string $hash The P2P hash
     * @param string $status The status update
     * @param array $additionalData Optional additional data
     * @return array The status update payload
     */
    public function buildStatusUpdate(string $hash, string $status, array $additionalData = []): array
    {
        $user = $this->userContext->getUser();

        return array_merge([
            'type' => 'p2p',
            'statusUpdate' => true,
            'hash' => $hash,
            'status' => $status,
            'senderPublicKey' => $user ? $user->getPublicKey() : null,
            'senderAddress' => $this->getUserAddress(),
            'timestamp' => time(),
        ], $additionalData);
    }

    /**
     * Get P2P expiration time in microseconds
     *
     * @return int The expiration time
     */
    private function getP2pExpirationTime(): int
    {
        $user = $this->userContext->getUser();

        // Default to 24 hours if not set
        $expirationHours = 24;

        if ($user && method_exists($user, 'getP2pExpiration')) {
            $expirationHours = $user->getP2pExpiration();
        }

        // Convert hours to microseconds (assuming the time unit)
        return $expirationHours * 3600 * 1000000;
    }

    /**
     * Resolve user address for transport
     *
     * @param string $address The address to resolve
     * @return string The resolved address
     */
    private function resolveUserAddressForTransport(string $address): string
    {
        // TODO: This should be moved to a service or utility class
        $userAddress = $this->getUserAddress();
        return $userAddress ?? $address;
    }
}