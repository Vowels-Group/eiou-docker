<?php
# Copyright 2025

use EIOU\Context\UserContext;

function countTorAndHttpAddresses($data){
    // Count how many tor and http addresses
    $result = [
        'tor' => count(array_filter($data, 'isTorAddress')),
        'http' => count(array_filter($data, 'isHttpAddress')),
        'total' => count($data)
    ];
    return $result;
}

function determineTransportType($address) {
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

function isHttpAddress($address) {
    // Check if is http address
    return preg_match('/^https?:\/\//', $address) === 1;
}

/**
 * Check if address belongs to the current user
 *
 * @param string $address Address to check
 * @param UserContext|null $userContext User context (optional, falls back to global)
 * @return bool True if address belongs to user
 */
function isMe($address, ?UserContext $userContext = null): bool {
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }
    return $userContext->isMyAddress($address);
}

function isTorAddress($address) {
    // Check if is tor address
    return preg_match('/\.onion$/', $address) === 1;
}

function jitter($value){
    // Add random number to value (either 0 or 1)
    return $value + random_int(0,1);
}

/**
 * Resolve user's address based on recipient transport type
 *
 * @param string $address Recipient address
 * @param UserContext|null $userContext User context (optional, falls back to global)
 * @return string|false User's address matching transport type, or false
 */
function resolveUserAddressForTransport($address, ?UserContext $userContext = null) {
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }

    // Check if the address is a Tor (.onion) address
    if (isTorAddress($address)) {
        return $userContext->getTorAddress();
    }
    // Check if the address is an HTTP/HTTPS address
    elseif (isHttpAddress($address)) {
        return $userContext->getHostname();
    }
    // If no specific transport type is detected, return false
    return false;
}

/**
 * Adjust remaining P2P chain length based on user's maxP2pLevel config
 *
 * @param array $request P2P request data
 * @param UserContext|null $userContext User context (optional, falls back to global)
 * @return int Adjusted P2P level
 */
function reAdjustP2pLevel($request, ?UserContext $userContext = null): int {
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }

    $maxUserLevel = $request['requestLevel'] + $userContext->getMaxP2pLevel();

    if ($request['maxRequestLevel'] > $maxUserLevel) {
        return $maxUserLevel;
    } else {
        return $request['maxRequestLevel'];
    }
}

function send($recipient, $payload){
    // Send payload to recipient 
    $signedPayload = json_encode(sign($payload)); // Encode the payload as JSON
    // Determine if tor address, else send by http
    if (preg_match('/\.onion$/', $recipient)) {
        return sendByTor($recipient, $signedPayload);
    } else {
        return sendByHttp($recipient, $signedPayload);
    }
}

function sendByHttp ($recipient, $signedPayload) {
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

function sendByTor ($recipient, $signedPayload) {
    // Send payload through TOR
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
 * Sign a payload with user's private key
 *
 * @param array $payload Payload data to sign
 * @param UserContext|null $userContext User context (optional, falls back to global)
 * @return array|false Signed payload with signature, or false on failure
 */
function sign($payload, ?UserContext $userContext = null) {
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }

    $privateKey = $userContext->getPrivateKey();
    if (!$privateKey) {
        echo "No private key available for signing.\n";
        return false;
    }

    // Get the private key resource
    $privateKeyResource = openssl_pkey_get_private($privateKey);
    if ($privateKeyResource === false) {
        echo "Failed to load private key.\n";
        return false;
    }

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