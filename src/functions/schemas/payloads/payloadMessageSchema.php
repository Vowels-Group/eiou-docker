<?php
# Copyright 2025

// Contacts
function buildMessageContactIsAcceptedInquiryPayload($address){
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

function buildMessageContactIsAcceptedPayload($address){
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

function buildMessageContactIsNotYetAcceptedPayload($address){
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

function buildMessageContactIsUnknownPayload($address){
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

// Transactions
function buildMessageTransactionCompletedCorrectlyPayload($message){
    // Build payload regarding the succesfull completion of a transaction
    $hash = $message['hash'];
    return json_encode([
        "status" => "completed",
        "hash" => $hash,
        "message" => "Transaction with hash " . print_r($hash,true) . " was received succesfully by end-recipient"
    ]);
}

function buildMessageTransactionCompletedInquiryPayload($message){
    // Build inquiry payload regarding the completion status of a transaction
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