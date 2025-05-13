<?php
# Copyright 2025

function buildInsufficientBalancePayload($availableFunds, $requestedAmount, $creditLimit) {
    // Build rejection payload when balance is insufficient
    return json_encode([
        "status" => "rejected", 
        "message" => "Insufficient balance or credit", 
        "current_balance" => number_format($availableFunds / 100, 2) . " USD",      // Convert back to dollars with 2 decimal places and USD
        "credit_limit" => number_format($creditLimit / 100, 2) . " USD",            // Convert back to dollars with 2 decimal places and USD
        "requested_amount" => number_format($requestedAmount / 100, 2) . " USD"     // Convert back to dollars with 2 decimal places and USD
    ]);
}

function buildInvalidRequestLevelPayload($request) {
    // Build rejection payload when request level is invalid
    return json_encode([
        "status" => "rejected",
        "message" => "Invalid request level",
        "request_level" => $request['requestLevel'],
        "max_request_level" => $request['maxRequestLevel']
    ]);
}

function buildSendPayload($data) {
    // Build send (Transaction/eIOU) payload 
    global $user;
    $userAddress = resolveUserAddressForTransport($data['receiverAddress']);
    $memo = $data['memo'] ?? 'standard';
    return array(
        'type' => 'send', // send request type
        'time' => $data['time'],
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress,
        'receiverPublicKey' => $data['receiverPublicKey'],
        'receiverAddress' => $data['receiverAddress'],
        'amount' => $data['amount'],
        'currency' => $data['currency'],
        'txid' => $data['txid'],
        'previousTxid' => $data['previousTxid'],
        'memo' => $memo
    );
}

function buildP2pPayload($data) {
    // Build p2p payload 
    global $user;
    $userAddress = resolveUserAddressForTransport($data['receiverAddress'] ?? $data['sender_address']); //To whom: either to a contact (initial sending) or return to contact based on found end-recipient)
    return array(
        'type' => 'p2p', // Peers of Peers request type
        'hash' => $data['hash'],
        'salt' => $data['salt'],
        'time' => $data['time'],
        'expiration' => $data['time'] + $user['p2pExpiration'] ?? $data['expiration'], // Expiration time based on user's configuration (or database version)
        'currency' => $data['currency'] ?? 'USD',
        'amount' => $data['amount'], // Nominal amount in cents recipient will receive
        'requestLevel' => $data['randomNumber'] ?? $data['request_level'] + 1, // Initial request level (or increment)
        'maxRequestLevel' => $data['maxRequestLevel'] ?? $data['max_request_level'], // Maximum number of hops for p2p request (or saved database version)
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress
    );
}

function buildRP2pPayload($data) {
    // Build rp2p payload 
    global $user;
    output("Building rP2p payload: " . print_r($data, true),'SILENT');
    $userAddress = resolveUserAddressForTransport($data['senderAddress'] ?? $data['sender_address']);
    return array(
        'type' => 'rp2p', // Return Peers of Peers request type
        'hash' => $data['hash'],
        'time' => $data['time'],
        'amount' => $data['amount'],
        'currency' => $data['currency'],
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress,
        'signature' => $data['signature']
    );
}

function createContactPayload() {
    // Create payload for contact request
    global $user;
    return array(
        'type' => 'create', // create request type
        'senderPublicKey' => $user['public']
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
