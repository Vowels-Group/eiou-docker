<?php
# Copyright 2025

function buildP2pPayload($data) {
    // Build p2p payload for initial message
    global $user;
    output(outputBuildingP2pPayload($data),'SILENT');
    $userAddress = resolveUserAddressForTransport($data['receiverAddress']); //To a contact (initial sending)
    return array(
        'type' => 'p2p', // Peer to peer request type
        'hash' => $data['hash'],
        'salt' => $data['salt'],
        'time' => $data['time'],
        'expiration' => $data['time'] + returnconvertedMicroTime($user['p2pExpiration']), // Expiration time based on user's configuration 
        'currency' => $data['currency'],
        'amount' => $data['amount'], // Nominal amount in cents recipient will receive
        'requestLevel' => $data['minRequestLevel'], // Initial request level
        'maxRequestLevel' => $data['maxRequestLevel'], // Maximum number of hops for p2p request
        'senderPublicKey' => $user['public'],
        'senderAddress' => $userAddress
    );
}

function buildP2pPayloadDatabase($data) {
    // Build p2p payload from database message
    global $user;
    output(outputBuildingP2pPayload($data),'SILENT');
    $userAddress = resolveUserAddressForTransport($data['sender_address']); //To a contact (sending p2p onwards to find end-recipient)
    return array(
        'type' => $data['type'], // Peer to peer request type
        'hash' => $data['hash'],
        'salt' => $data['salt'],
        'time' => $data['time'],
        'expiration' => $data['expiration'], // Expiration time based on database
        'currency' => $data['currency'],
        'amount' => $data['amount'], // Nominal amount in cents recipient will receive
        'requestLevel' => $data['request_level'] + 1, // increment request level (of message)
        'maxRequestLevel' => $data['max_request_level'], // Maximum number of hops from database message
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