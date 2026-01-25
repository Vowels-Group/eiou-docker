<?php

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
     * Sign a payload for transport.
     *
     * @param array $payload The payload to sign
     * @return array|false The signed payload data or false on failure
     */
    public function sign(array $payload): array|false;

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
