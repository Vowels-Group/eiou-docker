<?php
# Copyright 2025

function buildP2pPayload(array $data, ?UserContext $userContext = null): array {
    // Build p2p payload for initial message
    $userContext = $userContext ?? UserContext::fromGlobal();
    output(outputBuildingP2pPayload($data),'SILENT');
    $userAddress = resolveUserAddressForTransport($data['receiverAddress']); // To a contact (initial sending)
    return [
        'type' => 'p2p', // Peer to peer request type
        'hash' => $data['hash'],
        'salt' => $data['salt'],
        'time' => $data['time'],
        'expiration' => $data['time'] + returnconvertedMicroTime($userContext->getP2pExpiration()), // Expiration time based on user's configuration
        'currency' => $data['currency'],
        'amount' => $data['amount'], // Nominal amount in cents recipient will receive
        'requestLevel' => $data['minRequestLevel'], // Initial request level
        'maxRequestLevel' => $data['maxRequestLevel'], // Maximum number of hops for p2p request
        'senderPublicKey' => $userContext->getPublicKey(),
        'senderAddress' => $userAddress
    ];
}

function buildP2pPayloadDatabase(array $data, ?UserContext $userContext = null): array {
    // Build p2p payload from database message
    $userContext = $userContext ?? UserContext::fromGlobal();
    output(outputBuildingP2pPayload($data),'SILENT');
    $userAddress = resolveUserAddressForTransport($data['sender_address']); // To a contact (sending p2p onwards to find end-recipient)
    return [
        'type' => 'p2p', // Peer to peer request type
        'hash' => $data['hash'],
        'salt' => $data['salt'],
        'time' => $data['time'],
        'expiration' => $data['expiration'], // Expiration time based on database
        'currency' => $data['currency'],
        'amount' => $data['amount'], // Nominal amount in cents recipient will receive
        'requestLevel' => $data['request_level'] + 1, // increment request level (of message)
        'maxRequestLevel' => $data['max_request_level'], // Maximum number of hops from database message
        'senderPublicKey' => $userContext->getPublicKey(),
        'senderAddress' => $userAddress
    ];
}

function buildP2pAcceptancePayload($request){
    // Build p2p was accepted (received) payload
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    echo json_encode([
        "status" => "received",
        "message" => "hash " .  print_r($request['hash'],true) . " for P2P received by " .  print_r($receiver,true)]);
}

function buildP2pRejectionPayload($request){
    // Build p2p was rejected payload (due to already existing in database)
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    return json_encode([
        "status" => "rejected",
        "reason" => 'already exists',
        "message" => "hash " . print_r($request['hash'],true) . " for P2P already exists in database of " .  print_r($receiver,true)
    ]);
}