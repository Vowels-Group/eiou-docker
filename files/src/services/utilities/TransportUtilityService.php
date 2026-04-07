<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Utilities;

use Eiou\Contracts\TransportServiceInterface;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Utils\Logger;
use Eiou\Utils\AddressValidator;
use Eiou\Utils\TorCircuitHealth;
use Eiou\Services\ServiceContainer;
use Eiou\Database\AddressRepository;
use Eiou\Database\ContactRepository;
use Eiou\Security\PayloadEncryption;

/**
 * Transport Utility Service
 *
 * Handles Transport
 */
class TransportUtilityService implements TransportServiceInterface
{
    /**
     * @var ServiceContainer Service container for accessing repositories
     */
    private ServiceContainer $container;

    /**
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

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
        $this->currentUser = $this->container->getCurrentUser();
    }

    /**
     * Return a count of all the addresses in the contact data
     *
     * @param array $data The Contacts data
     * @return array Counts of contacts addresses
    */
    public function countTorAndHttpAddresses(array $data): array {
        $result = [
            'tor' => count(array_filter($data, [$this, 'isTorAddress'])),
            'https' => count(array_filter($data, [$this, 'isHttpsAddress'])),
            'http' => count(array_filter($data, [$this, 'isHttpAddress'])),
            'total' => count($data)
        ];
        return $result;
    }

    /**
     * Return the determined transport type from an address
     *
     * @param string $address The address of the sender
     * @return string|null The type of transport used ('tor', 'https', 'http', or null)
     */
    public function determineTransportType(string $address): ?string {
        return AddressValidator::getTransportType($address);
    }

    /**
     * Return an associative array of the determined address
     *
     * @param string $address The address of the sender
     * @return array|null The associative array of the transport type (e.g., ['tor' => $address])
     */
    public function determineTransportTypeAssociative(string $address): ?array {
        return AddressValidator::categorizeAddress($address);
    }

    /**
     * Determine a possible fallback transport type
     *
     * @param string $info The address/name of the receiver
     * @param array $contactInfo The Contact Info
     * @return string|null The type of database index
     */
    public function fallbackTransportType(string $info, array $contactInfo): ?string {
        $transportIndex = $this->determineTransportType($info) ?? Constants::getDefaultTransportMode();
        if(isset($contactInfo[$transportIndex])){
            return $transportIndex;
        } 
        // If provided address/name did not result in a viable transport type 
        //  and default transport mode did not work to compensate, try finding the next possible
        $transportModes = $this->container->getRepositoryFactory()->get(AddressRepository::class)->getAllAddressTypes();
        unset($transportModes[array_search($transportIndex,$transportModes)]);
        $transportModes = array_values($transportModes);
        while($transportModes !== []){
            $transportIndex = array_shift($transportModes);
            if(isset($contactInfo[$transportIndex])){
                return $transportIndex;
            }
        }
        output(outputNoViableTransportMode(), 'SILENT');
        return null;
    }

    /**
     * Determine a possible fallback contact address
     *
     * @param array $contactInfo The Contact Info (with addresses)
     * @return string|null The fallback address
     */
    public function fallbackTransportAddress(array $contactInfo): ?string {
        $transportModes = $this->container->getRepositoryFactory()->get(AddressRepository::class)->getAllAddressTypes();
        if($transportModes){
            while($transportModes !== []){
                $transportIndex = array_shift($transportModes);
                if(isset($contactInfo[$transportIndex])){
                    return $contactInfo[$transportIndex];
                }
            }
        }
        output(outputNoViableTransportAddress(), 'SILENT');
        return null;
    }

    /**
     * Get all available address types from the database schema
     *
     * @return array Array of address type names (e.g., ['http', 'tor'])
     */
    public function getAllAddressTypes(): array {
        return $this->container->getRepositoryFactory()->get(AddressRepository::class)->getAllAddressTypes();
    }

    /**
     * Check if address is HTTPS
     *
     * @param string $address The address to check
     * @return bool True if HTTPS address, false otherwise
     */
    public function isHttpsAddress(string $address): bool {
        return AddressValidator::isHttpsAddress($address);
    }

    /**
     * Determine if address is HTTP only (not HTTPS)
     *
     * @param string $address The address of the sender
     * @return bool True if HTTP address, false otherwise
     */
    public function isHttpAddress(string $address): bool {
        return AddressValidator::isHttpAddress($address);
    }

    /**
     * Determine if address is TOR
     *
     * @param string $address The address of the sender
     * @return bool True if Tor address, false otherwise
     */
    public function isTorAddress(string $address): bool {
        return AddressValidator::isTorAddress($address);
    }

    /**
     * Determine if address is valid HTTP, HTTPS, or TOR
     *
     * @param string $address The address of the sender
     * @return bool True if HTTP/HTTPS/TOR address, false otherwise
     */
    public function isAddress(string $address): bool {
        return AddressValidator::isAddress($address);
    }

    /**
     * Get the curl_multi concurrency limit for a set of recipient addresses.
     *
     * Looks up CURL_MULTI_MAX_CONCURRENT by protocol. When the batch contains
     * mixed protocols, returns the most restrictive (lowest) limit.
     * Unknown protocols fall back to the lowest configured value.
     *
     * @param string[] $addresses Recipient addresses in this batch
     * @return int Max concurrent connections to use
     */
    public function getConcurrencyLimit(array $addresses): int {
        $limits = Constants::CURL_MULTI_MAX_CONCURRENT;
        $fallback = min($limits);
        $min = PHP_INT_MAX;

        foreach ($addresses as $address) {
            $protocol = $this->determineTransportType($address) ?? '';
            $min = min($min, $limits[$protocol] ?? $fallback);
        }

        return $min === PHP_INT_MAX ? $fallback : $min;
    }

    /**
     *  Add random number to value (either 0 or 1)
     *
     * @param int A number
     * @return int The original number incremented by 0 or 1
    */
    public function jitter(int $value): int{
        return $value + random_int(0,1);
    }

    /**
     * Figure out the determined transport type for the payload from an address
     *
     * Security priority for fallback (most secure to least secure):
     * 1. Tor - End-to-end encrypted, anonymized routing
     * 2. HTTPS - Encrypted transport layer
     * 3. HTTP - Unencrypted (least secure, used only as last resort)
     *
     * @param string $address The address of the sender
     * @return string The address of the user of equivalent type
    */
    public function resolveUserAddressForTransport(string $address): string {
        // Check if the address is a Tor (.onion) address
        if ($this->isTorAddress($address)) {
            $torAddress = $this->currentUser->getTorAddress();
            // Return Tor address or fall back to original address (never downgrade)
            return $torAddress ?? $address;
        }
        // Check if the address is an HTTPS address
        elseif ($this->isHttpsAddress($address)) {
            $httpsAddress = $this->currentUser->getHttpsAddress();
            if ($httpsAddress !== null) {
                return $httpsAddress;
            }
            // Fallback priority: Tor (more secure) > HTTP (less secure)
            $torAddress = $this->currentUser->getTorAddress();
            if ($torAddress !== null) {
                return $torAddress;
            }
            $httpAddress = $this->currentUser->getHttpAddress();
            return $httpAddress ?? $address;
        }
        // Check if the address is an HTTP address
        elseif ($this->isHttpAddress($address)) {
            $httpAddress = $this->currentUser->getHttpAddress();
            if ($httpAddress !== null) {
                return $httpAddress;
            }
            // Fallback priority: Tor (more secure) > HTTPS (more secure than HTTP)
            $torAddress = $this->currentUser->getTorAddress();
            if ($torAddress !== null) {
                return $torAddress;
            }
            $httpsAddress = $this->currentUser->getHttpsAddress();
            return $httpsAddress ?? $address;
        }
        // If no specific transport type is detected, return the original address
        return $address;
    }

    /**
     * Send payload to recipient
     *
     * @param string $recipient The address of the recipient
     * @param array $payload The payload to send
     * @param bool $returnSigningData If true, returns array with response and signing data
     * @param bool $allowTransportFallback If true, TOR failures will attempt HTTP/HTTPS fallback (use only for contact requests to preserve privacy)
     * @return string|array The response from the recipient, or array with response and signing data if $returnSigningData is true
     */
    public function send(string $recipient, array $payload, bool $returnSigningData = false, bool $allowTransportFallback = false): string|array {
        $signingResult = $this->signWithCapture($payload, $recipient);
        $signedPayload = json_encode($signingResult['envelope']);

        // Determine if tor address, else send by http
        if ($this->isTorAddress($recipient)) {
            $response = $this->sendByTor($recipient, $signedPayload);

            // If TOR delivery failed, attempt HTTP/HTTPS fallback when:
            // 1. Caller explicitly allows it ($allowTransportFallback, e.g. contact requests), OR
            // 2. User has the torFailureTransportFallback setting enabled (default: true)
            if ($this->isTorFailure($response)) {
                $shouldFallback = $allowTransportFallback
                    || $this->currentUser->isTorFailureTransportFallback();

                if ($shouldFallback) {
                    $fallbackResponse = $this->attemptFallbackDelivery($recipient, $signedPayload);
                    if ($fallbackResponse !== null) {
                        $response = $fallbackResponse;
                    }
                }
            }
        } else {
            $response = $this->sendByHttp($recipient, $signedPayload);
        }

        if ($returnSigningData) {
            return [
                'response' => $response,
                'signature' => $signingResult['signature'],
                'nonce' => $signingResult['nonce'],
                'signed_message' => $signingResult['signed_message'] ?? null
            ];
        }

        return $response;
    }

    /**
     * Check if a response indicates a TOR delivery failure
     *
     * @param string $response The JSON response from sendByTor
     * @return bool True if the response indicates a TOR failure
     */
    private function isTorFailure(string $response): bool {
        $decoded = json_decode($response, true);
        return isset($decoded['status']) && $decoded['status'] === 'error'
            && isset($decoded['message']) && str_contains($decoded['message'], 'TOR request failed');
    }

    /**
     * Attempt to deliver a payload via HTTP/HTTPS fallback when TOR fails
     *
     * Looks up the recipient's known addresses and tries the first available
     * non-TOR address as a fallback transport.
     *
     * @param string $torAddress The TOR address that failed
     * @param string $signedPayload The already-signed payload to deliver
     * @return string|null The HTTP response on success, or null if no fallback available
     */
    private function attemptFallbackDelivery(string $torAddress, string $signedPayload): ?string {
        $addressRepo = $this->container->getRepositoryFactory()->get(AddressRepository::class);

        // Look up the recipient's pubkey hash from their TOR address
        $pubkeyHash = $addressRepo->getContactPubkeyHash('tor', $torAddress);
        if ($pubkeyHash === null) {
            return null;
        }

        // Get all known addresses for this contact
        $contactAddresses = $addressRepo->lookupByPubkeyHash($pubkeyHash);
        if (empty($contactAddresses)) {
            return null;
        }

        // Remove TOR from candidates so we only try HTTP/HTTPS
        unset($contactAddresses['tor']);

        // If torFallbackRequireEncrypted is enabled, also remove HTTP to preserve privacy
        if (UserContext::getInstance()->isTorFallbackRequireEncrypted()) {
            unset($contactAddresses['http']);
        }

        $fallbackAddress = $this->fallbackTransportAddress($contactAddresses);
        if ($fallbackAddress === null) {
            return null;
        }

        Logger::getInstance()->info("TOR delivery failed, attempting HTTP/HTTPS fallback", [
            'tor_address' => $torAddress,
            'fallback_address' => $fallbackAddress
        ]);

        return $this->sendByHttp($fallbackAddress, $signedPayload);
    }

    /**
     * Send payload to recipient through HTTP(S)
     *
     * @param string $recipient The address of the recipient
     * @param string $signedPayload The JSON encoded signed payload to send
     * @return string The response from the recipient, or JSON error on failure
    */
    public function sendByHttp (string $recipient, string $signedPayload): string {
        // Send payload through HTTP(S)
        $ch = curl_init();

        // Determine the protocol based on the recipient format
        // Default to https:// for secure P2P communication
        $protocol = preg_match('/^https?:\/\//', $recipient) ? '' : 'https://';

        $url = $protocol . $recipient . "/eiou/";
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->currentUser->getHttpTransportTimeoutSeconds());
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Prevent payload leakage on redirects
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $signedPayload);

        // SSL options for HTTPS connections
        // SSL peer verification is enabled by default (H-8 security remediation).
        // Self-signed certificates (e.g. Docker mesh nodes using QUICKSTART) will
        // be rejected unless one of the following is configured:
        //   - P2P_SSL_VERIFY=false      → disables verification (development only)
        //   - P2P_CA_CERT=/path/to/ca   → custom CA for verification
        //   - EIOU_TEST_MODE=true        → disables verification (test suites)
        if (preg_match('/^https:\/\//', $url) || preg_match('/^https:\/\//', $protocol . $recipient)) {
            $testMode = getenv('EIOU_TEST_MODE') === 'true';
            $verifySsl = !$testMode && getenv('P2P_SSL_VERIFY') !== 'false';
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

            // If a CA certificate is provided, use it for verification
            $caCertPath = getenv('P2P_CA_CERT');
            if ($caCertPath && file_exists($caCertPath)) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_CAINFO, $caCertPath);
            }
        }

        $response = curl_exec($ch);

        // Check for curl errors and log them
        if ($response === false) {
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            // Log the error for debugging
            Logger::getInstance()->warning("HTTP request failed", [
                'recipient' => $recipient,
                'curl_error' => $curlError,
                'curl_errno' => $curlErrno
            ]);

            // Return a structured error response that can be parsed
            return json_encode([
                'status' => 'error',
                'message' => 'HTTP request failed: ' . $curlError,
                'error_code' => $curlErrno
            ]);
        }

        curl_close($ch);
        // Return the response from the recipient
        return $response;
    }

    /**
     * Send payload to recipient through TOR
     *
     * @param string $recipient The address of the recipient
     * @param string $signedPayload The JSON encoded signed payload to send
     * @return string The response from the recipient, or JSON error on failure
    */
    public function sendByTor (string $recipient, string $signedPayload): string {
        // Check if this .onion address is in cooldown from repeated failures
        if (!TorCircuitHealth::isAvailable($recipient)) {
            Logger::getInstance()->info("Tor address in cooldown, skipping delivery", [
                'recipient' => $recipient,
            ]);
            return json_encode([
                'status' => 'error',
                'message' => 'TOR request failed: address in cooldown after repeated failures',
                'error_code' => 'TOR_COOLDOWN'
            ]);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://$recipient/eiou/");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->currentUser->getTorTransportTimeoutSeconds());
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, Constants::TOR_CONNECT_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_PROXY, "127.0.0.1:9050");
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $signedPayload);
        $response = curl_exec($ch);

        // Check for curl errors and log them
        if ($response === false) {
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            // Track per-address failure for circuit health cooldown
            TorCircuitHealth::recordFailure($recipient, $curlError);

            // Log the error for debugging
            Logger::getInstance()->warning("TOR request failed", [
                'recipient' => $recipient,
                'curl_error' => $curlError,
                'curl_errno' => $curlErrno
            ]);

            // Signal watchdog to restart Tor immediately on SOCKS5 proxy failure
            // errno 7 = CURLE_COULDNT_CONNECT (proxy unreachable)
            if (str_contains($curlError, 'SOCKS5') || $curlErrno === 7) {
                $signalFile = '/tmp/tor-restart-requested';
                if (!file_exists($signalFile)) {
                    file_put_contents($signalFile, (string)time());
                    Logger::getInstance()->warning("SOCKS5 proxy failure detected, signaling watchdog for immediate Tor restart");
                }

                // Write GUI-readable status so the wallet UI can display a notification
                // Use 0666 permissions so both www-data (PHP) and root (watchdog) can update
                $statusFile = '/tmp/tor-gui-status';
                file_put_contents($statusFile, json_encode([
                    'status' => 'issue',
                    'timestamp' => time(),
                    'message' => 'Tor connectivity issue detected — automatic restart in progress'
                ]));
                chmod($statusFile, 0666);
            }

            // Return a structured error response that can be parsed
            return json_encode([
                'status' => 'error',
                'message' => 'TOR request failed: ' . $curlError,
                'error_code' => $curlErrno
            ]);
        }

        curl_close($ch);

        // Successful delivery — clear any failure state for this address
        TorCircuitHealth::recordSuccess($recipient);

        // Return the response from the recipient
        return $response;
    }

    /**
     * Create a configured but un-executed curl handle for a recipient.
     *
     * Extracts curl setup logic from sendByHttp() and sendByTor() into a shared helper.
     * Auto-detects Tor vs HTTP based on address.
     *
     * @param string $recipient The recipient address
     * @param string $signedPayload The JSON-encoded signed payload
     * @return \CurlHandle The configured curl handle
     */
    public function createCurlHandle(string $recipient, string $signedPayload): \CurlHandle {
        $ch = curl_init();

        if ($this->isTorAddress($recipient)) {
            curl_setopt($ch, CURLOPT_URL, "http://$recipient/eiou/");
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->currentUser->getTorTransportTimeoutSeconds());
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, Constants::TOR_CONNECT_TIMEOUT_SECONDS);
            curl_setopt($ch, CURLOPT_PROXY, "127.0.0.1:9050");
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        } else {
            $protocol = preg_match('/^https?:\/\//', $recipient) ? '' : 'https://';
            $url = $protocol . $recipient . "/eiou/";
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->currentUser->getHttpTransportTimeoutSeconds());
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

            // SSL options for HTTPS connections (see sendByHttp for full documentation)
            if (preg_match('/^https:\/\//', $url) || preg_match('/^https:\/\//', $protocol . $recipient)) {
                $testMode = getenv('EIOU_TEST_MODE') === 'true';
                $verifySsl = !$testMode && getenv('P2P_SSL_VERIFY') !== 'false';
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

                $caCertPath = getenv('P2P_CA_CERT');
                if ($caCertPath && file_exists($caCertPath)) {
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                    curl_setopt($ch, CURLOPT_CAINFO, $caCertPath);
                }
            }
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $signedPayload);

        return $ch;
    }

    /**
     * Send a payload to multiple recipients in parallel using curl_multi.
     *
     * Signs the payload separately for each recipient (unique nonce/signature per send),
     * creates curl handles via createCurlHandle(), and executes all in parallel.
     *
     * @param array $recipients Array of recipient addresses
     * @param array $payload The data payload to send (will be signed per-recipient)
     * @return array<string, array{response: string, signature: string, nonce: string}> Results keyed by recipient address
     */
    public function sendBatch(array $recipients, array $payload): array {
        if (empty($recipients)) {
            return [];
        }

        $handles = [];     // recipient => CurlHandle
        $signingData = []; // recipient => ['signature' => ..., 'nonce' => ...]

        // Sign and create handles for each recipient (each gets uniquely encrypted content)
        foreach ($recipients as $recipient) {
            $signingResult = $this->signWithCapture($payload, $recipient);
            if ($signingResult === false) {
                Logger::getInstance()->warning("Failed to sign payload for batch recipient", [
                    'recipient' => $recipient
                ]);
                continue;
            }

            $signedPayload = json_encode($signingResult['envelope']);
            $signingData[$recipient] = [
                'signature' => $signingResult['signature'],
                'nonce' => $signingResult['nonce'],
                'signed_message' => $signingResult['signed_message'] ?? null
            ];

            $handles[$recipient] = $this->createCurlHandle($recipient, $signedPayload);
        }

        $responses = $this->executeWithConcurrencyLimit(
            $handles,
            $this->getConcurrencyLimit($recipients)
        );

        // Build results with signing data
        $results = [];
        foreach ($handles as $recipient => $ch) {
            $response = $responses[$recipient] ?? null;

            if ($response === false || $response === null || $response === '') {
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);

                $transportType = $this->isTorAddress($recipient) ? 'TOR' : 'HTTP';
                Logger::getInstance()->warning("$transportType batch request failed", [
                    'recipient' => $recipient,
                    'curl_error' => $curlError,
                    'curl_errno' => $curlErrno
                ]);

                $response = json_encode([
                    'status' => 'error',
                    'message' => "$transportType request failed: " . $curlError,
                    'error_code' => $curlErrno
                ]);
            }

            $results[$recipient] = [
                'response' => $response,
                'signature' => $signingData[$recipient]['signature'] ?? '',
                'nonce' => $signingData[$recipient]['nonce'] ?? '',
                'signed_message' => $signingData[$recipient]['signed_message'] ?? null
            ];

            curl_close($ch);
        }

        return $results;
    }

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
    public function sendMultiBatch(array $sends): array {
        if (empty($sends)) {
            return [];
        }

        $handles = [];     // key => CurlHandle
        $signingData = []; // key => ['signature' => ..., 'nonce' => ...]

        foreach ($sends as $send) {
            $key = $send['key'];
            $recipient = $send['recipient'];
            $payload = $send['payload'];

            $signingResult = $this->signWithCapture($payload, $recipient);
            if ($signingResult === false) {
                Logger::getInstance()->warning("Failed to sign payload for multi-batch send", [
                    'key' => $key,
                    'recipient' => $recipient
                ]);
                continue;
            }

            $signedPayload = json_encode($signingResult['envelope']);
            $signingData[$key] = [
                'signature' => $signingResult['signature'],
                'nonce' => $signingResult['nonce'],
                'signed_message' => $signingResult['signed_message'] ?? null
            ];

            $handles[$key] = $this->createCurlHandle($recipient, $signedPayload);
        }

        $responses = $this->executeWithConcurrencyLimit(
            $handles,
            $this->getConcurrencyLimit(array_column($sends, 'recipient'))
        );

        // Build results with signing data
        $results = [];
        foreach ($handles as $key => $ch) {
            $response = $responses[$key] ?? null;

            if ($response === false || $response === null || $response === '') {
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);

                Logger::getInstance()->warning("Multi-batch request failed", [
                    'key' => $key,
                    'curl_error' => $curlError,
                    'curl_errno' => $curlErrno
                ]);

                $response = json_encode([
                    'status' => 'error',
                    'message' => "Request failed: " . $curlError,
                    'error_code' => $curlErrno
                ]);
            }

            $results[$key] = [
                'response' => $response,
                'signature' => $signingData[$key]['signature'] ?? '',
                'nonce' => $signingData[$key]['nonce'] ?? '',
                'signed_message' => $signingData[$key]['signed_message'] ?? null
            ];

            curl_close($ch);
        }

        return $results;
    }

    /**
     * Execute curl handles with a sliding-window concurrency limit.
     *
     * Instead of firing all handles simultaneously (which overwhelms Tor circuits
     * when broadcasting to many contacts), this processes handles in a sliding window:
     * up to $maxConcurrent run at once, and as each completes, the next is added.
     *
     * @param array<string, \CurlHandle> $handles All curl handles keyed by identifier
     * @param int $maxConcurrent Max simultaneous connections (defaults to HTTP limit)
     * @return array<string, string|null> Responses keyed by the same identifiers
     */
    private function executeWithConcurrencyLimit(array $handles, int $maxConcurrent = 0): array {
        if (empty($handles)) {
            return [];
        }

        if ($maxConcurrent <= 0) {
            $maxConcurrent = min(Constants::CURL_MULTI_MAX_CONCURRENT);
        }

        $mh = curl_multi_init();
        $responses = [];
        $pendingKeys = array_keys($handles);
        $activeMap = []; // spl_object_id => key (for fast lookup of completed handles)

        // Seed the initial window
        while (!empty($pendingKeys) && count($activeMap) < $maxConcurrent) {
            $key = array_shift($pendingKeys);
            curl_multi_add_handle($mh, $handles[$key]);
            $activeMap[spl_object_id($handles[$key])] = $key;
        }

        // Process with sliding window
        do {
            curl_multi_exec($mh, $running);

            // Check for completed handles and swap in pending ones
            while ($info = curl_multi_info_read($mh)) {
                if ($info['msg'] !== CURLMSG_DONE) {
                    continue;
                }

                $doneCh = $info['handle'];
                $objectId = spl_object_id($doneCh);
                $doneKey = $activeMap[$objectId] ?? null;

                if ($doneKey !== null) {
                    $responses[$doneKey] = curl_multi_getcontent($doneCh);
                    curl_multi_remove_handle($mh, $doneCh);
                    unset($activeMap[$objectId]);

                    // Add next pending handle
                    if (!empty($pendingKeys)) {
                        $nextKey = array_shift($pendingKeys);
                        curl_multi_add_handle($mh, $handles[$nextKey]);
                        $activeMap[spl_object_id($handles[$nextKey])] = $nextKey;
                    }
                }
            }

            if ($running > 0) {
                curl_multi_select($mh);
            }
        } while ($running > 0 || !empty($activeMap));

        curl_multi_close($mh);

        return $responses;
    }

    /**
     * Sign a payload and capture the signing data
     *
     * Creates a clean payload structure and returns both the envelope
     * and the signature/nonce used for storage purposes.
     *
     * @param array $payload The payload to sign
     * @return array|false Array with 'envelope', 'signature', and 'nonce' keys, or false on failure
     */
    public function signWithCapture(array $payload, ?string $recipientAddress = null): array|false {
        // Remove transport metadata from payload content (will be at top level)
        $messageContent = $payload;
        unset($messageContent['senderAddress']);
        unset($messageContent['senderAddresses']);
        unset($messageContent['senderPublicKey']);
        unset($messageContent['signature']);

        // Description handling:
        // - Contact requests (type=create): keep in signed content (sent directly)
        // - Direct sends (type=send, memo=standard): keep in signed content (sent directly)
        // - Completion inquiries (type=message, inquiry=true): keep — carries description to end-recipient
        // - Contact description (type=message, status=contact_description): keep — E2E encrypted follow-up
        // - Payment requests (type=message, typeMessage=payment_request): keep — user-provided note for the recipient
        // - P2P relay (type=send, memo=hash): strip — delivered via completion inquiry
        // - All other types: strip
        $messageType = $messageContent['type'] ?? '';
        $memo = $messageContent['memo'] ?? '';
        $isDirectSend = $messageType === 'send' && $memo === 'standard';
        $isContactRequest = $messageType === 'create';
        $isCompletionInquiry = $messageType === 'message' && !empty($messageContent['inquiry']);
        $isContactDescription = $messageType === 'message' && ($messageContent['status'] ?? '') === 'contact_description';
        $isPaymentRequest = $messageType === 'message' && ($messageContent['typeMessage'] ?? '') === 'payment_request';
        if (!$isDirectSend && !$isContactRequest && !$isCompletionInquiry && !$isContactDescription && !$isPaymentRequest) {
            unset($messageContent['description']);
        }

        // Capture debug info before encryption hides the fields
        $messageType = $messageContent['type'] ?? '';
        $txidForLog = $messageContent['txid'] ?? 'unknown';
        $wasEncrypted = false;

        // E2E encrypt ALL message fields for contact messages
        // Excluded: types in TYPES_EXCLUDED_FROM_ENCRYPTION (e.g., 'create' — recipient not a contact)
        // The signed message becomes: {encrypted: {...}, nonce: "..."} — all types look identical on wire
        if (!in_array($messageType, PayloadEncryption::TYPES_EXCLUDED_FROM_ENCRYPTION, true)
            && PayloadEncryption::isAvailable()
        ) {
            // Resolve recipient's public key:
            // 1. From payload (direct transactions include receiverPublicKey)
            // 2. From contact database lookup by recipient address
            $recipientPublicKey = $messageContent['receiverPublicKey'] ?? null;

            if ($recipientPublicKey === null && $recipientAddress !== null) {
                $transportType = $this->determineTransportType($recipientAddress);
                if ($transportType !== null) {
                    $recipientPublicKey = $this->container->getRepositoryFactory()->get(ContactRepository::class)
                        ->getPublicKeyFromAddress($transportType, $recipientAddress);
                }
            }

            if ($recipientPublicKey !== null) {
                // Encrypt ALL message content fields into the encrypted block
                // After this, $messageContent is just {encrypted: {...}} — type, amount,
                // hash, currency etc. are all inside the ciphertext
                $messageContent = [
                    'encrypted' => PayloadEncryption::encryptForRecipient(
                        $messageContent,
                        $recipientPublicKey
                    ),
                ];
                $wasEncrypted = true;
            }
        }

        // Add cryptographic nonce for replay protection (128-bit hex string)
        // Added AFTER encryption so it stays in cleartext (not sensitive)
        $nonce = bin2hex(random_bytes(16));
        $messageContent['nonce'] = $nonce;

        // JSON encode the message content (no duplication)
        $message = json_encode($messageContent);

        // Debug: Log the message being signed for sync verification troubleshooting
        if ($messageType === 'send') {
            Logger::getInstance()->debug("Signing transaction message", [
                'txid' => $txidForLog,
                'signed_message' => $message,
                'nonce' => $nonce
            ]);
        }

        // Sign the message
        $signature = '';
        if (!openssl_sign($message, $signature, openssl_pkey_get_private($this->currentUser->getPrivateKey()))) {
            Logger::getInstance()->error("Failed to sign message", [
                'txid' => $txidForLog
            ]);
            return false;
        }

        $base64Signature = base64_encode($signature);

        // Build clean payload structure:
        // - Transport metadata at top level
        // - Message content in 'message' field (includes description for send/create)
        // - Description is NOT added to envelope — for P2P it's sent via inquiry,
        //   for direct sends/contact requests it's inside the signed message content
        $envelope = [
            'senderAddress' => $payload['senderAddress'],
            'senderPublicKey' => $payload['senderPublicKey'],
            'message' => $message,
            'signature' => $base64Signature
        ];

        // Include version in envelope for all types except contact creation requests.
        // Contact requests go to untrusted nodes (no established relationship yet),
        // so exposing the version would let any node fingerprint us. Version is
        // exchanged later via acceptance responses, ping/pong, and message envelopes.
        if ($messageType !== 'create') {
            $envelope['version'] = Constants::APP_VERSION;
        }

        return [
            'envelope' => $envelope,
            'signature' => $base64Signature,
            'nonce' => $nonce,
            // For E2E encrypted messages, include the signed message so the sender
            // can store it for future sync signature verification. Without this,
            // signature reconstruction from plaintext DB fields would fail because
            // the signature was over the encrypted content.
            'signed_message' => $wasEncrypted ? $message : null
        ];
    }

    /**
     * Sign a payload
     *
     * Creates a clean payload structure where:
     * - senderAddress, senderPublicKey, signature are at top level (transport metadata)
     * - message contains only the signed content (no duplication)
     *
     * @param array $payload The payload to sign
     * @return array|false The signed payload with clean structure, or false on failure
     */
    public function sign(array $payload, ?string $recipientAddress = null): array|false {
        $result = $this->signWithCapture($payload, $recipientAddress);
        return $result ? $result['envelope'] : false;
    }
}
