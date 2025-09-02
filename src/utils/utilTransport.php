<?php
# Copyright 2025

function countTorAndHttpAddresses($data){
    // Count how many tor and http addresses
    $result = [
        'tor' => count(array_filter($data, 'isTorAddress')),
        'http' => count(array_filter($data, 'isHttpAddress')),
        'total' => count($data)
    ];
    return $result;
}

function jitter($value){
    // Add random number to value (either 0 or 1)
    return $value + random_int(0,1);
}

function resolveUserAddressForTransport($address) {
    global $user;
    // Check if the address is a Tor (.onion) address
    if (preg_match('/\.onion$/', $address)) {
        return $user["torAddress"];
    }
    // Check if the address is an HTTP/HTTPS address
    elseif (preg_match('/^https?:\/\//', $address)) {
        return $user["hostname"];
    }
    // If no specific transport type is detected, return the original address
    return false;
}

function readjustP2pLevel($request){
    // Adjust remaining p2p chain length based on intermediary contact's config of maxP2pLevel 
    global $user;
    if($request['maxRequestLevel'] > $request['requestLevel'] + $user['maxP2pLevel']){
        return $request['requestLevel'] + $user['maxP2pLevel'];
    } else{
        return $request['maxRequestLevel'];
    }
}

function send($recipient, $payload){
    // Encode the payload as JSON
    $signedPayload = json_encode(sign($payload));
    // Determine if tor address, else send by http
    if (preg_match('/\.onion$/', $recipient)) {
        return sendByTor($recipient, $signedPayload);
    } else {
        return sendByHttp($recipient, $signedPayload);
    }
}

function sendByHttp ($recipient, $signedPayload) {
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

function sign($payload){
  // Add signature to payload
  global $user;
  $privateKey = $user['private'];
  // Step 1: Get the private key resource
  $privateKeyResource = openssl_pkey_get_private($privateKey);
  // Step 2: Sign the message
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