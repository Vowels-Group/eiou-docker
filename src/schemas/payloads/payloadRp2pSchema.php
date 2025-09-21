<?php
# Copyright 2025

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
    // Build rp2p was accepted (received) payload 
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    echo json_encode([
        "status" => "received",
        "message" => "hash " .  print_r($request['hash'],true) . " for RP2P received by " .  print_r($receiver,true)]);
}

function buildRp2pRejectionPayload($request){
    // Build rp2p  was rejected payload (due to already existing in database) 
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    return json_encode([
        "status" => "rejected",
        "message" => "hash " . print_r($request['hash'],true) . " for RP2P already exists in database of " .  print_r($receiver,true)
    ]);
}