<?php
namespace Eiou\Contracts;

/**
 * Interface for transport utility services.
 *
 * Defines the contract for handling various transport mechanisms
 * including HTTP, HTTPS, and Tor communications.
 */
interface TransportServiceInterface
{
    /**
     * Determine the transport type for a given address.
     *
     * @param string $address The address to analyze
     * @return string|null The transport type (http, https, tor) or null if unknown
     */
    public function determineTransportType(string $address): ?string;

    /**
     * Check if the address uses HTTPS protocol.
     *
     * @param string $address The address to check
     * @return bool True if the address is HTTPS
     */
    public function isHttpsAddress(string $address): bool;

    /**
     * Check if the address uses HTTP protocol.
     *
     * @param string $address The address to check
     * @return bool True if the address is HTTP
     */
    public function isHttpAddress(string $address): bool;

    /**
     * Check if the address is a Tor (.onion) address.
     *
     * @param string $address The address to check
     * @return bool True if the address is a Tor address
     */
    public function isTorAddress(string $address): bool;

    /**
     * Check if the string is a valid address.
     *
     * @param string $address The address to validate
     * @return bool True if the address is valid
     */
    public function isAddress(string $address): bool;

    /**
     * Send a payload to a recipient.
     *
     * @param string $recipient The recipient address
     * @param array $payload The data payload to send
     * @param bool $returnSigningData Whether to return signing data instead of sending
     * @return string|array The response string or signing data array
     */
    public function send(string $recipient, array $payload, bool $returnSigningData = false): string|array;

    /**
     * Send a signed payload via HTTP/HTTPS.
     *
     * @param string $recipient The recipient address
     * @param string $signedPayload The signed payload to send
     * @return string The response from the recipient
     */
    public function sendByHttp(string $recipient, string $signedPayload): string;

    /**
     * Send a signed payload via Tor network.
     *
     * @param string $recipient The recipient Tor address
     * @param string $signedPayload The signed payload to send
     * @return string The response from the recipient
     */
    public function sendByTor(string $recipient, string $signedPayload): string;

    /**
     * Send a payload to multiple recipients in parallel using curl_multi.
     *
     * Signs the payload separately for each recipient (unique nonce/signature per send),
     * creates curl handles, and executes all in parallel.
     *
     * @param array $recipients Array of recipient addresses
     * @param array $payload The data payload to send (will be signed per-recipient)
     * @return array<string, array{response: string, signature: string, nonce: string}> Results keyed by recipient address
     */
    public function sendBatch(array $recipients, array $payload): array;

    /**
     * Send different payloads to multiple recipients in parallel using curl_multi.
     *
     * Unlike sendBatch() which sends the same payload to all recipients, this method
     * accepts per-send payloads — used when broadcasting multiple P2P messages in one
     * curl_multi call (each P2P has a different hash/amount/etc).
     *
     * @param array $sends Array of ['key' => string, 'recipient' => string, 'payload' => array]
     * @return array<string, array{response: string, signature: string, nonce: string}> Results keyed by send key
     */
    public function sendMultiBatch(array $sends): array;

    /**
     * Sign a payload for transport with optional E2E encryption.
     *
     * @param array $payload The payload to sign
     * @param string|null $recipientAddress Optional recipient address for E2E encryption key lookup
     * @return array|false The signed payload data or false on failure
     */
    public function sign(array $payload, ?string $recipientAddress = null): array|false;

    /**
     * Resolve a user address for transport purposes.
     *
     * @param string $address The user address to resolve
     * @return string The resolved transport address
     */
    public function resolveUserAddressForTransport(string $address): string;

    /**
     * Get all supported address types.
     *
     * @return array List of supported address types
     */
    public function getAllAddressTypes(): array;
}
