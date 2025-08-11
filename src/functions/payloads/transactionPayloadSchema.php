<?php
# Copyright 2025

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

function buildForwardingTransactionPayload($message){
    // Build forwarding payload (from database information)
    global $user;
    $rp2p = checkRp2pExists($message['memo']);
    $data['time'] = $rp2p['time'];
    $data['receiver_address'] = $rp2p['sender_address']; // Send new transaction onwards to sender of rp2p
    $data['receiver_public_key'] = $rp2p['sender_public_key'];
    $data['amount'] = removeTransactionFee($message); // Remove my transaction fee
    $data['currency'] = $rp2p['currency'];
    $data['txid'] = createUniqueDatabaseTxid($message); // Create new txid for new Transaction
    $data['previous_txid'] = fixPreviousTxid($user['public'], $message['receiver_public_key']);
    $data['memo'] = $rp2p['hash'];
    return $data;
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
    // Build send (Transaction/eIOU) was completed payload 
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