<?php
# Copyright 2025

/**
 * Return a count of all the addresses in the contact data
 *
 * @param array $data The Contacts data
 * @return array Counts of contacts addresses
*/
function countTorAndHttpAddresses(array $data): array {
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
function determineTransportType(array $address): ?string {
    // Check if the address is a Tor (.onion) address
    if (isTorAddress($address)) {
        return 'tor';
    }
    
    // Check if the address is an HTTP/HTTPS address
    if (isHttpAddress($address)) {
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
function isHttpAddress($address): bool {
    return preg_match('/^https?:\/\//', $address) === 1;
}

/**
 * Determine if adress is TOR
 *
 * @param string $address The address of the sender
 * @return bool True if Tor address, False otherwise
*/
function isTorAddress($address): bool {
    return preg_match('/\.onion$/', $address) === 1;
}

/**
 *  Add random number to value (either 0 or 1)
 *
 * @param int A number
 * @return int The original number incremented by 0 or 1
*/
function jitter(int $value): int{
    return $value + random_int(0,1);
}

/**
 * Figure out the determined transport type for the payload from an address
 *
 * @param string $address The address of the sender
 * @return string The address of the user ofequivalent type
*/
function resolveUserAddressForTransport(string $address): string {
     $currentUser = UserContext::getInstance();
    // Check if the address is a Tor (.onion) address
    if (isTorAddress($address)) {
        return $currentUser->getTorAddress();
    }
    // Check if the address is an HTTP/HTTPS address
    elseif (isHttpAddress($address)) {
        return $currentUser->getHttpAddress();
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
function send(string $recipient, array $payload){
    $signedPayload = json_encode(sign($payload)); // Encode the payload as JSON
    // Determine if tor address, else send by http
    if (isTorAddress($recipient)) {
        return sendByTor($recipient, $signedPayload);
    } else {
        return sendByHttp($recipient, $signedPayload);
    }
}

/**
 * Send payload to recipient through HTTP(S)
 *
 * @param string $recipient The address of the recipient
 * @param string $signedPayload The JSON encoded signed payload to send
 * @return string The response from the recipient
*/
function sendByHttp (string $recipient, string $signedPayload): string {
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
function sendByTor (string $recipient, string $signedPayload): string {
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
function sign(array $payload) {
  // Add signature to payload
  $currentUser = UserContext::getInstance();
  $privateKey = $currentUser->getPrivateKey();
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