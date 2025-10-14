<?php
# Copyright 2025

use EIOU\Context\UserContext;

/**
 * Build send (Transaction/eIOU) payload
 *
 * @param array $data Transaction data
 * @param UserContext|null $userContext User context (optional, falls back to global)
 * @return array Payload array
 */
function buildSendPayload($data, ?UserContext $userContext = null): array {
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }

    output(outputBuildingTransactionPayload($data), 'SILENT');
    $userAddress = resolveUserAddressForTransport($data['receiverAddress'], $userContext);
    $memo = $data['memo'] ?? 'standard';

    return array(
        'type' => 'send', // send request type
        'time' => $data['time'],
        'senderPublicKey' => $userContext->getPublicKey(),
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

/**
 * Build send (Transaction/eIOU) payload from database information
 *
 * @param array $data Transaction data from database
 * @param UserContext|null $userContext User context (optional, falls back to global)
 * @return array Payload array
 */
function buildSendDatabasePayload($data, ?UserContext $userContext = null): array {
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }

    output(outputBuildingTransactionPayload($data), 'SILENT');
    $userAddress = resolveUserAddressForTransport($data['receiver_address'], $userContext);
    $memo = $data['memo'] ?? 'standard';

    return array(
        'type' => 'send', // send request type
        'time' => $data['time'],
        'senderPublicKey' => $userContext->getPublicKey(),
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

/**
 * Build forwarding transaction payload from database information
 *
 * @param array $message Message data
 * @param UserContext|null $userContext User context (optional, falls back to global)
 * @return array Forwarding payload data
 */
function buildForwardingTransactionPayload($message, ?UserContext $userContext = null): array {
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }

    $rp2p = checkRp2pExists($message['memo']);
    $data['time'] = $rp2p['time'];
    $data['receiver_address'] = $rp2p['sender_address']; // Send new transaction onwards to sender of rp2p
    $data['receiver_public_key'] = $rp2p['sender_public_key'];
    $data['amount'] = removeTransactionFee($message); // Remove my transaction fee
    $data['currency'] = $rp2p['currency'];
    $data['txid'] = createUniqueDatabaseTxid($message); // Create new txid for new Transaction
    $data['previous_txid'] = fixPreviousTxid($userContext->getPublicKey(), $message['receiver_public_key']);
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

/**
 * Build send transaction completed payload
 *
 * @param array $request Request data
 * @param UserContext|null $userContext User context (optional, falls back to global)
 * @return array Completed payload array
 */
function buildSendCompletedPayload($request, ?UserContext $userContext = null): array {
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }

    $receiver = resolveUserAddressForTransport($request['senderAddress'] ?? $request['sender_address'], $userContext);

    // for direct transaction hash is equivalent to txid, otherwise hash is equivalent to memo (only for initialisation)
    if (isset($request['memo'])) {
        if ($request['memo'] === 'standard') {
            $hash = $request['txid'];
            $hashType = 'txid';
        } else {
            $hash = $request['memo'];
            $hashType = 'memo';
        }
    } else {
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
        'senderPublicKey' => $userContext->getPublicKey(),
        "amount" => $request['amount'],
        "currency" => $request['currency'],
        "message" => "transaction for hash " . print_r($hash, true) . " was succesfully completed through intermediary"
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