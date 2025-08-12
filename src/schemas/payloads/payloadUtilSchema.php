<?php
# Copyright 2025

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

function buildInvalidTransactionIDPayload($previousTxResult,$request){
    // Build rejection payload when transaction txid is not matching
    return json_encode([
        "status" => "rejected", 
        "message" => "Previous transaction ID does not match. Expecting: " . $previousTxResult['txid'] . " Received: " . $request['previousTxid']
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
    // Build rejection payload when comming from unknown source
    $receiver = resolveUserAddressForTransport($message['senderAddress']);
    return json_encode([
        "status" => "rejected",
        "message" => "Message rejected due to being from unknown source to receiver " .  print_r($receiver,true)
    ]);
}