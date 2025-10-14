<?php
# Copyright 2025

use EIOU\Context\UserContext;

function createContactPayload(?UserContext $userContext = null): array {
    // Create payload for contact request
    $userContext = $userContext ?? UserContext::fromGlobal();
    return [
        'type' => 'create', // create request type
        'senderPublicKey' => $userContext->getPublicKey()
    ];
}

function buildContactIsAcceptedPayload(string $address, ?UserContext $userContext = null): array {
    // Build contact accepted payload when user has accepted the contact request
    $userContext = $userContext ?? UserContext::fromGlobal();
    $myAddress = resolveUserAddressForTransport($address);
    return [
        'type' => "message", // message request type
        'typeMessage' => "contact", // type of message
        "status" => "accepted",
        "senderAddress" => $myAddress,
        'senderPublicKey' => $userContext->getPublicKey(),
        "message" => $myAddress . " confirms that we are contacts"
    ];
}

function buildContactAlreadyExistsPayload(?UserContext $userContext = null): string {
    // Build warning payload when contact already exists
    $userContext = $userContext ?? UserContext::fromGlobal();
    return json_encode([
        "status" => "warning",
        "message" => "Contact already exists",
        'myPublicKey' => $userContext->getPublicKey()
    ]);
}