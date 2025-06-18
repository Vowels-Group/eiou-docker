<?php
# Copyright 2025

function createContactPayload() {
    // Create payload for contact request
    global $user;
    return array(
        'type' => 'create', // create request type
        'senderPublicKey' => $user['public']
    );
}

function buildContactAlreadyExistsPayload() {
    // Build warning payload when contact already exists
    global $user;
    return json_encode([
        "status" => "warning",
        "message" => "Contact already exists",
        'myPublicKey' => $user['public']
    ]);
}

function buildInsufficientBalancePayload($availableFunds, $requestedAmount, $creditLimit, $fundsOnHold) {
    // Build rejection payload when balance is insufficient
    return json_encode([
        "status" => "rejected", 
        "message" => "Insufficient balance or credit", 
        "credit_limit" => number_format($creditLimit / 100, 2) . " USD",            // Convert back to dollars with 2 decimal places and USD
        "current_balance" => number_format($availableFunds / 100, 2) . " USD",      // Convert back to dollars with 2 decimal places and USD
        "funds_on_hold" => number_format($fundsOnHold / 100, 2) . " USD",           // Convert back to dollars with 2 decimal places and USD
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

function buildP2pPayload($data) {
    // Build p2p payload 
    global $user;
    $userAddress = resolveUserAddressForTransport($data['receiverAddress'] ?? $data['sender_address']); //To whom: either to a contact (initial sending) or return to contact based on found end-recipient)
    return array(
        'type' => 'p2p', // Peer to peer request type
        'hash' => $data['hash'],
        'salt' => $data['salt'],
        'time' => $data['time'],
        'expiration' => $data['time'] + returnconvertedMicroTime($user['p2pExpiration']) ?? $data['expiration'], // Expiration time based on user's configuration (or database version)
        'currency' => $data['currency'] ?? 'USD',
        'amount' => $data['amount'], // Nominal amount in cents recipient will receive
        'requestLevel' => $data['minRequestLevel'] ?? $data['request_level'] + 1, // Initial request level (or increment)
        'maxRequestLevel' => $data['maxRequestLevel'] ?? $data['max_request_level'], // Maximum number of hops for p2p request (or saved database version)
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress
    );
}

function buildP2pAcceptancePayload($request){
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    echo json_encode([
        "status" => "received",
        "message" => "hash " .  print_r($request['hash'],true) . " for P2P received by " .  print_r($receiver,true)]);
}
function buildP2pRejectionPayload($request){
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    return json_encode([
        "status" => "rejected",
        "message" => "hash " . print_r($request['hash'],true) . " for P2P already exists in database of " .  print_r($receiver,true)
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

function buildSendAcceptancePayload($request){
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    return json_encode([
        "status" => "accepted",
        "txid" => $request['txid'],
        "message" => "memo " .  print_r($request['memo'],true) . " for transaction received by " .  print_r($receiver,true)
    ]);  
}

function buildSendRejectionPayload($request){
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    return json_encode([
        "status" => "rejected",
        "message" => "hash " . print_r($request['hash'],true) . " for Transaction already exists in database of " .  print_r($receiver,true)
    ]);
}

function buildRp2pPayload($data) {
    // Build rp2p payload 
    global $user;
    output("Building rP2p payload: " . print_r($data, true),'SILENT');
    $userAddress = resolveUserAddressForTransport($data['senderAddress'] ?? $data['sender_address']);
    return array(
        'type' => 'rp2p', // Return Peer to peer request type
        'hash' => $data['hash'],
        'time' => $data['time'],
        'amount' => $data['amount'],
        'currency' => $data['currency'],
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress,
        'signature' => $data['signature']
    );
}

function buildRp2pAcceptancePayload($request){
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    echo json_encode([
        "status" => "received",
        "message" => "hash " .  print_r($request['hash'],true) . " for RP2P received by " .  print_r($receiver,true)]);
}
function buildRp2pRejectionPayload($request){
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    return json_encode([
        "status" => "rejected",
        "message" => "hash " . print_r($request['hash'],true) . " for RP2P already exists in database of " .  print_r($receiver,true)
    ]);
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
