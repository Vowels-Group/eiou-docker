<?php
# Copyright 2025

use EIOU\Context\UserContext;

// Contacts
function buildMessageContactIsAcceptedInquiryPayload(string $address, ?UserContext $userContext = null): array {
    // Build contact inquiry payload when user wants to inquire the status of the contact request
    $userContext = $userContext ?? UserContext::fromGlobal();
    $myAddress = resolveUserAddressForTransport($address);
    return [
        'type' => "message", // message request type
        'typeMessage' => "contact", // type of message
        'inquiry' => true, // request for information
        "senderAddress" => $myAddress,
        'senderPublicKey' => $userContext->getPublicKey(),
        "message" => $myAddress . " wants to know if we are contacts"
    ];
}

function buildMessageContactIsAcceptedPayload(string $address, ?UserContext $userContext = null): string {
    // Build contact accepted payload when user has accepted the contact request
    $userContext = $userContext ?? UserContext::fromGlobal();
    $myAddress = resolveUserAddressForTransport($address);
    return json_encode([
        'type' => "message", // message request type
        'typeMessage' => "contact", // type of message
        "status" => "accepted",
        "senderAddress" => $myAddress,
        'senderPublicKey' => $userContext->getPublicKey(),
        "message" => $myAddress . " confirms that we are contacts"
    ]);
}

function buildMessageContactIsNotYetAcceptedPayload(string $address, ?UserContext $userContext = null): string {
    // Build contact not yet accepted payload when user has not accepted the contact request yet
    $userContext = $userContext ?? UserContext::fromGlobal();
    $myAddress = resolveUserAddressForTransport($address);
    return json_encode([
        'type' => "message", // message request type
        'typeMessage' => "contact", // type of message
        "status" => "rejected",
        "reason" => "pending",
        "senderAddress" => $myAddress,
        'senderPublicKey' => $userContext->getPublicKey(),
        "message" => $myAddress . " has not yet accepted your contact request"
    ]);
}

function buildMessageContactIsUnknownPayload(string $address, ?UserContext $userContext = null): string {
    // Build contact is unknown payload when user no database record of the 'contact' in question
    $userContext = $userContext ?? UserContext::fromGlobal();
    $myAddress = resolveUserAddressForTransport($address);
    return json_encode([
        'type' => "message", // message request type
        'typeMessage' => "contact", // type of message
        "status" => "rejected",
        "reason" => "unknown",
        "senderAddress" => $myAddress,
        'senderPublicKey' => $userContext->getPublicKey(),
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

function buildMessageTransactionCompletedInquiryPayload(array $message, ?UserContext $userContext = null): array {
    // Build inquiry payload regarding the completion status of a transaction
    $userContext = $userContext ?? UserContext::fromGlobal();
    $hash = $message['hash'];
    $myAddress = resolveUserAddressForTransport($message['senderAddress']);
    return [
        'type' => "message", // message request type
        'typeMessage' => "transaction", // type of message
        'inquiry' => true, // request for information
        "status" => "completed",
        "hash" => $hash,
        "hashType" => $message['hashType'],
        "senderAddress" => $myAddress,
        'senderPublicKey' => $userContext->getPublicKey(),
        "message" => $myAddress . " is requesting information about transaction with memo " . $hash
    ];
}