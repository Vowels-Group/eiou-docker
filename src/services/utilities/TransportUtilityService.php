<?php
# Copyright 2025

/**
 * Transport Utility Service
 *
 * Handles Transport
 *
 * @package Services\Utilities
 */

require_once __DIR__ . '/../../core/Constants.php';

class TransportUtilityService
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
     * @var UserContext Current user data
     */
    private UserContext $currentUser;

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
        $this->currentUser = $this->container->getCurrentUser();
    }

    /**
     * Truncate address for easier display
     *
     * @param string $address The address
     * @param int $length Point of truncation
     * @return string Truncated address
     */
    public function truncateAddress(string $address, int $length = 10): string
    {
        if (strlen($address) <= $length) {
            return $address;
        }
        return substr($address, 0, $length) . '...';
    }

    /**
     * Return a count of all the addresses in the contact data
     *
     * @param array $data The Contacts data
     * @return array Counts of contacts addresses
    */
    public function countTorAndHttpAddresses(array $data): array {
        $result = [
            'tor' => count(array_filter($data, 'isTorAddress')),
            'http' => count(array_filter($data, 'isHttpAddress')),
            'total' => count($data)
        ];
        return $result;
    }

    /**
     * Return the determined transport type from an address
     *
     * @param string $address The address of the sender
     * @return string|null The type of transport used
    */
    public function determineTransportType(array $address): ?string {
        // Check if the address is a Tor (.onion) address
        if ($this->isTorAddress($address)) {
            return 'tor';
        }
        
        // Check if the address is an HTTP/HTTPS address
        if ($this->isHttpAddress($address)) {
            return 'http';
        }
        
        // If neither Tor nor HTTP, return null or a default type
        return null;
    }

    /**
     * Determine if adress is HTTP/HTTPS
     *
     * @param string $address The address of the sender
     * @return bool True if HTTP(S) address, False otherwise
    */
    public function isHttpAddress($address): bool {
        return preg_match('/^https?:\/\//', $address) === 1;
    }

    /**
     * Determine if adress is valid HTTP or TOR
     *
     * @param string $address The address of the sender
     * @return bool True if HTTP(S)/TOR address, False otherwise
    */
    public function isAddress($address): bool {
        return ($this->isHttpAddress($address) || $this->isTorAddress($address));
    }

    /**
     * Determine if adress is TOR
     *
     * @param string $address The address of the sender
     * @return bool True if Tor address, False otherwise
    */
    public function isTorAddress($address): bool {
        return preg_match('/\.onion$/', $address) === 1;
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
     * @param string $address The address of the sender
     * @return string The address of the user ofequivalent type
    */
    public function resolveUserAddressForTransport(string $address): string {
        // Check if the address is a Tor (.onion) address
        if ($this->isTorAddress($address)) {
            return $this->currentUser->getTorAddress();
        }
        // Check if the address is an HTTP/HTTPS address
        elseif ($this->isHttpAddress($address)) {
            return $this->currentUser->getHttpAddress();
        }
        // If no specific transport type is detected, return the original address
        return $address;
    }

    /**
     * Send payload to recipient
     *
     * @param string $recipient The address of the recipient
     * @param array $payload The payload to send
     * @return string The response from the recipient
    */
    public function send(string $recipient, array $payload){
        $signedPayload = json_encode($this->sign($payload)); // Encode the payload as JSON
        // Determine if tor address, else send by http
        if ($this->isTorAddress($recipient)) {
            return $this->sendByTor($recipient, $signedPayload);
        } else {
            return $this->sendByHttp($recipient, $signedPayload);
        }
    }

    /**
     * Send payload to recipient through HTTP(S)
     *
     * @param string $recipient The address of the recipient
     * @param string $signedPayload The JSON encoded signed payload to send
     * @return string The response from the recipient
    */
    public function sendByHttp (string $recipient, string $signedPayload): string {
        // Send payload through HTTP
        $ch = curl_init();
        
        // Determine the protocol based on the recipient format
        $protocol = preg_match('/^https?:\/\//', $recipient) ? '' : 'http://';

        curl_setopt($ch, CURLOPT_URL, $protocol . $recipient . "/eiou?payload=" . urlencode($signedPayload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POST, true);
        $response = curl_exec($ch);
        curl_close($ch);
        // Return the response from the recipient
        return $response;
    }

    /**
     * Send payload to recipient through TOR
     *
     * @param string $recipient The address of the recipient
     * @param string $signedPayload The JSON encoded signed payload to send
     * @return string The response from the recipient
    */
    public function sendByTor (string $recipient, string $signedPayload): string {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://$recipient/eiou?payload=" . urlencode($signedPayload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_PROXY, "127.0.0.1:9050");
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POST, true);
        $response = curl_exec($ch);
        curl_close($ch);
        // Return the response from the recipient
        return $response;
    }

    /**
     * Sign a payload
     *
     * @param array $payload The payload to sign
    */ 
    public function sign(array $payload) {
        // Add signature to payload
        $privateKey = $this->currentUser->getPrivateKey();
        // Get the private key resource
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        // Sign the message
        $payload['nonce'] = time();
        $message = json_encode($payload);
        $payload['message'] = $message;
        $signature = '';
        if (!openssl_sign($message, $signature, $privateKeyResource)) {
            echo "Failed to sign the message.\n";
            return false;
        }
        $payload['signature'] = base64_encode($signature);
        return $payload;
    }
}
