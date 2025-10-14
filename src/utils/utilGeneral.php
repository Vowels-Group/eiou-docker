<?php
# Copyright 2025

use EIOU\Context\UserContext;

function calculateAvailableFunds($request){
    // Calculate funds request's sender has available with user
    $totalSent = calculateTotalSent($request['senderPublicKey'] ?? $request['sender_public_key']);   // Calculate IOUs sent to sender
    $totalReceived = calculateTotalReceived($request['senderPublicKey'] ?? $request['sender_public_key']); // Calulcate IOUs received from sender
    $theirCurrentBalance = $totalSent - $totalReceived;
    $senderContact = lookupContactByAddress($request['senderAddress'] ?? $request['sender_address']);
    $creditLimit = getCreditLimit($senderContact['pubkey']);    // Get senders credit limit with user
    return $theirCurrentBalance + $creditLimit;
}

/**
 * Calculate total amount needed for P2P transaction through user
 *
 * @param array $request Request data
 * @param UserContext|null $userContext User context (optional, falls back to global)
 * @return int Total amount including fee
 */
function calculateRequestedAmount($request, ?UserContext $userContext = null): int {
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }

    $senderContact = lookupContactByAddress($request['senderAddress']);
    $fee = ($senderContact ? $senderContact['fee_percent'] : $userContext->getDefaultFee()) / 10000; //convert back to percent for math
    $request['feeAmount'] = round($request['amount'] * $fee);   // Caculate fee on the amount sender wants sent
    return $request['amount'] + $request['feeAmount'];
}

/**
 * Calculate fee information and output to log
 *
 * @param array $p2p P2P data
 * @param array $request Request data
 * @param UserContext|null $userContext User context (optional, falls back to global)
 * @return float Fee percentage
 */
function feeInformation($p2p, $request, ?UserContext $userContext = null): float {
    if ($userContext === null) {
        $userContext = UserContext::fromGlobal();
    }

    $feeAmount = $request['amount'] - $p2p['amount'];
    $feePercent = round(($feeAmount / $p2p['amount']) * 100, 2);
    output(outputFeeInformation($feePercent, $request, $userContext->getMaxFee()), 'SILENT'); // output fee information into the log
    return $feePercent;
}

function matchContact($request) {
    // Check if contact matches transactions end-recipient
    $contacts = retrieveContactAddressesPubkeys();
    // Check if end recipient of request in contacts
    foreach ($contacts as $contact) {
        $contactHash = hash('sha256', $contact['address'] . $request['salt'] . $request['time']);
        // output(outputCalculateContactHash($contact,$request), 'SILENT');
        // output(outputCalculatedContactHash($contactHash), 'SILENT');
        if ($contactHash === $request['hash']) {
            output(outputContactMatched($contactHash), 'SILENT');
            return $contact;
        }
    }
}

function matchYourselfP2P($request,$address){
    // Check if p2p end recipient is user
    if(hash('sha256', $address . $request['salt'] . $request['time']) === $request['hash']){
        return true;
    }
    return false;
}

function matchYourselfTransaction($request,$address){
    // Check if transaction end recipient is user
    $p2pRequest = getP2pByHash($request['memo']);
    if( hash('sha256', $address . $p2pRequest['salt'] . $p2pRequest['time']) === $request['memo']) {
        return true;
    }
    return false;
}

function removeTransactionFee($request){
    // Remove users transaction fee from request
    $p2p = getP2pByHash($request['memo']);
    return $request['amount'] - $p2p['my_fee_amount'];
}

function returnconvertedMicroTime($time){
    // Convert float of time to int by moving values behind comma to in front of comma
    return $time*10000;
}

function returnMicroTime(){
    // Create current micro-time stamp
    return returnconvertedMicroTime(microtime(true));
}