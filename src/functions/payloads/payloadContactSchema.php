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

function buildContactAlreadyExistsPayload() {
    // Build warning payload when contact already exists
    global $user;
    return json_encode([
        "status" => "warning",
        "message" => "Contact already exists",
        'myPublicKey' => $user['public']
    ]);
}