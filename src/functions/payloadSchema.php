<?php

function buildInsufficientBalancePayload($availableFunds, $requestedAmount) {
    return json_encode([
            "status" => "rejected", 
            "message" => "Insufficient balance or credit", 
            "availableFunds" => number_format($availableFunds / 100, 2) . " USD",           // Convert back to dollars with 2 decimal places and USD
            "requestedAmount" => number_format($requestedAmount / 100, 2) . " USD"     // Convert back to dollars with 2 decimal places and USD
        ]);
}

function buildInvalidRequestLevelPayload($request) {
    return json_encode([
            "status" => "rejected",
            "message" => "Invalid request level",
            "request_level" => $request['requestLevel'],
            "max_request_level" => $request['maxRequestLevel']
        ]);
}

function buildSendPayload($data) {
    global $user;
    $userAddress = resolveUserAddressForTransport($data['receiverAddress']);
    $memo = $data['memo'] ?? 'standard';
    return array(
        'type' => 'send',
        'time' => $data['time'],
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress,
        'receiverPublicKey' => $data['receiverPublicKey'],
        'receiverAddress' => $data['receiverAddress'],
        'amount' => $data['amount'], //convert to cents
        'currency' => $data['currency'],
        'txid' => $data['txid'],
        'previousTxid' => $data['previousTxid'],
        'memo' => $memo
    );
}

function buildP2pPayload($data) {
    global $user;
    $userAddress = resolveUserAddressForTransport($data['receiverAddress']);
    return array(
        'type' => 'p2p', // Peers of Peers request type
        'hash' => $data['p2pHash'],
        'salt' => $data['salt'],
        'time' => $data['time'],
        'expiration' => $data['time'] + $user['p2pExpiration'], // Expiration time based on user's configuration
        'currency' => 'USD',
        'amount' => $data['amount'], // Nominal amount in cents recipient will receive
        'requestLevel' => $data['randomNumber'], // Initial request level
        'maxRequestLevel' => $data['maxRequestLevel'], // Maximum number of hops for p2p request
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress
    );
}

function buildRP2pPayload($data) {
    global $user;
    output("Building rP2p payload: " . print_r($data, true));
    $userAddress = resolveUserAddressForTransport($data['senderAddress'] ?? $data['sender_address']);
    return array(
        'type' => 'rp2p',
        'hash' => $data['hash'],
        'time' => $data['time'],
        'amount' => $data['amount'],
        'currency' => $data['currency'],
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress,
        'signature' => $data['signature'],
        'p2p_array' => $data['p2p_array']
    );
}

function createForwardP2pPayload($data) {
    global $user;
    $userAddress = resolveUserAddressForTransport($data['sender_address']);
    return array(
        'type' => 'p2p', // Peers of Peers request type
        'hash' => $data['hash'],
        'salt' => $data['salt'],
        'time' => $data['time'],
        'expiration' => $data['expiration'],
        'currency' => $data['currency'],
        'amount' => $data['amount'], // Nominal amount recipient will receive
        'requestLevel' => $data['request_level'] + 1, // Increment request level
        'maxRequestLevel' => $data['max_request_level'], // Maximum number of hops for p2p request
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress
    );
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
