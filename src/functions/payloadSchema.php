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

function buildContactIsAcceptedInquiryPayload($address){
    // Build contact inquiry payload when user wants to inquire the status of the contact request
    global $user;
    $myAddress = resolveUserAddressForTransport($address);
    return array(
        'type' => "message", // message request type
        'typeMessage' => "contact", // type of message
        'inquiry' => true, // request for information
        "senderAddress" => $myAddress,
        'senderPublicKey' => $user['public'],
        "message" => $myAddress . " wants to know if we are contacts" 
    );
}

function buildContactIsAcceptedPayload($address){
    // Build contact accepted payload when user has accepted the contact request
    global $user;
    $myAddress = resolveUserAddressForTransport($address);
    return array(
        'type' => "message", // message request type
        'typeMessage' => "contact", // type of message
        "status" => "accepted",
        "senderAddress" => $myAddress,
        'senderPublicKey' => $user['public'],
        "message" => $myAddress . " confirms that we are contacts" 
    );
}

function buildEchoContactIsAcceptedPayload($address){
    // Build contact accepted payload when user has accepted the contact request
    global $user;
    $myAddress = resolveUserAddressForTransport($address);
    return json_encode([
        'type' => "message", // message request type
        'typeMessage' => "contact", // type of message
        "status" => "accepted",
        "senderAddress" => $myAddress,
        'senderPublicKey' => $user['public'],
        "message" => $myAddress . " confirms that we are contacts" 
    ]);
}

function buildContactIsNotYetAcceptedPayload($address){
    // Build contact not yet accepted payload when user has not accepted the contact request yet
    global $user;
    $myAddress = resolveUserAddressForTransport($address);
    return json_encode([
        'type' => "message", // message request type
        'typeMessage' => "contact", // type of message
        "status" => "rejected",
        "reason" => "pending",
        "senderAddress" => $myAddress,
        'senderPublicKey' => $user['public'],
        "message" => $myAddress . " has not yet accepted your contact request" 
    ]);
}

function buildContactIsUnknownPayload($address){
    // Build contact is unknown payload when user no database record of the 'contact' in question
    global $user;
    $myAddress = resolveUserAddressForTransport($address);
    return json_encode([
        'type' => "message", // message request type
        'typeMessage' => "contact", // type of message
        "status" => "rejected",
        "reason" => "unknown",
        "senderAddress" => $myAddress,
        'senderPublicKey' => $user['public'],
        "message" => $myAddress . " and you are not contacts" 
    ]);
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

function buildMessageInvalidSourcePayload($message){
    $receiver = resolveUserAddressForTransport($message['senderAddress']);
    return json_encode([
        "status" => "rejected",
        "message" => "message rejected due to being from unknown source to receiver " .  print_r($receiver,true)
    ]);
}

function buildP2pPayload($data) {
    // Build p2p payload 
    global $user;
    output(outputBuildingP2pPayload($data),'SILENT');
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
    output(outputBuildingTransactionPayload($data),'SILENT');
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

function buildSendDatabasePayload($data) {
    // Build send (Transaction/eIOU) payload (from database information)
    global $user;
    output(outputBuildingTransactionPayload($data),'SILENT');
    $userAddress = resolveUserAddressForTransport($data['receiver_address']);
    $memo = $data['memo'] ?? 'standard';
    return array(
        'type' => 'send', // send request type
        'time' => $data['time'],
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress,
        'receiverPublicKey' => $data['receiver_public_key'],
        'receiverAddress' => $data['receiver_address'],
        'amount' => $data['amount'],
        'currency' => $data['currency'],
        'txid' => $data['txid'],
        'previousTxid' => $data['previous_txid'],
        'memo' => $memo
    );
}

function buildSendAcceptancePayload($request){
    // Build send (Transaction/eIOU) was accepted payload 
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    if(isset($request['memo'])){
        if($request['memo'] === 'standard'){
            $hash = $request['txid'];
            $hashType = 'txid';
        } else{
            $hash = $request['memo'];
            $hashType = 'memo';
        }
    } else{
        $hash = $request['hash'];
        $hashType = 'memo';
    } 
    return json_encode([
        "status" => "accepted",
        "txid" => $request['txid'],
        'memo' => $request['memo'],
        "message" => print_r($hashType,true) . " " .  print_r($hash,true) . " for transaction received by " .  print_r($receiver,true)
    ]);  
}

function buildSendCompletedPayload($request){
    global $user;
    $receiver = resolveUserAddressForTransport($request['senderAddress'] ?? $request['sender_address']);
    // for direct transaction hash is equivalent to txid, otherwise hash is equivalent to memo (only for initialisation)
    if(isset($request['memo'])){
        if($request['memo'] === 'standard'){
            $hash = $request['txid'];
            $hashType = 'txid';
        } else{
            $hash = $request['memo'];
            $hashType = 'memo';
        }
    } else{
        $hash = $request['hash'];
        $hashType = 'memo';
    } 
    
    return array(
        'type' => "message", // message request type
        'typeMessage' => "transaction", // type of message
        'inquiry' => false, // request for information
        "status" => "completed",
        "hash" => $hash,
        "hashType" => $hashType,
        "senderAddress" => $receiver,
        'senderPublicKey' => $user['public'],
        "amount" => $request['amount'],
        "currency" => $request['currency'],
        "message" => "transaction for hash " . print_r($hash,true) . " was succesfully completed through intermediary"
    );
}

function buildSendCompletedCorrectlyPayload($message){
    $hash = $message['hash'];
    return json_encode([
        "status" => "completed",
        "hash" => $hash,
        "message" => "Transaction with hash " . print_r($hash,true) . " was received succesfully by end-recipient"
    ]);
}

function buildSendCompletedInquiryPayload($message){
    global $user;
    $hash = $message['hash'];
    $myAddress = resolveUserAddressForTransport($message['senderAddress']);
    return array(
        'type' => "message", // message request type
        'typeMessage' => "transaction", // type of message
        'inquiry' => true, // request for information
        "status" => "completed",
        "hash" => $hash,
        "hashType" => $message['hashType'],
        "senderAddress" => $myAddress,
        'senderPublicKey' => $user['public'],
        "message" => $myAddress . " is requesting information about transaction with memo " . $hash 
    );
}

function buildSendRejectionPayload($request){
    // Build send (Transaction/eIOU) was rejected payload 
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    if(isset($request['memo'])){
        if($request['memo'] === 'standard'){
            $hash = $request['txid'];
            $hashType = 'txid';
        } else{
            $hash = $request['memo'];
            $hashType = 'memo';
        }
    } else{
        $hash = $request['hash'];
        $hashType = 'memo';
    } 
    return json_encode([
        "status" => "rejected",
        "txid" => $request['txid'],
        'memo' => $request['memo'],
        "message" =>  print_r($hashType,true) . " ". print_r($hash,true) . " for Transaction already exists in database of " .  print_r($receiver,true)
    ]);
}

function buildRp2pPayload($data) {
    // Build rp2p payload 
    global $user;
    output(outputBuildingRp2pPayload($data),'SILENT');
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
    // Build rp2p was accepted payload 
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    echo json_encode([
        "status" => "received",
        "message" => "hash " .  print_r($request['hash'],true) . " for RP2P received by " .  print_r($receiver,true)]);
}

function buildRp2pRejectionPayload($request){
    // Build rp2p was rejected payload 
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
