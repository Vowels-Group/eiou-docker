<?php
# Copyright 2025

function calculateAvailableFunds($request){
    // Calculate funds request's sender has available with user
    $pubkey = $request['senderPublicKey'] ?? $request['sender_public_key'];
    $totalSent = calculateTotalSent($pubkey);   // Calculate IOUs sent to sender
    $totalReceived = calculateTotalReceived($pubkey); // Calulcate IOUs received from sender
    $theirCurrentBalance = $totalSent - $totalReceived; 
    $creditLimit = getCreditLimit($pubkey);    // Get senders credit limit with user
    return $theirCurrentBalance + $creditLimit;
}


function feeInformation($p2p,$request){
    // Return fee percent and output fee information into the log
    $currentUser = UserContext::getInstance();
    $feeAmount = $request['amount'] - $p2p['amount'];
    $feePercent = round(($feeAmount / $p2p['amount']) * 100,2);
    output(outputFeeInformation($feePercent,$request,$currentUser->getMaxFee()), 'SILENT'); // output fee information into the log
    return $feePercent;
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

/**
 * Format currency from cents to dollars with USD suffix
 *
 * @param float $amountInCents Amount in cents
 * @param string $currency Currency code (default: USD)
 * @return string Formatted currency string
*/
function formatCurrency(float $amountInCents, string $currency = 'USD'): string{
    $amountInDollars = $amountInCents / Constants::TRANSACTION_USD_CONVERSION_FACTOR;
    return number_format($amountInDollars, 2) . ' ' . $currency;
}

/**
 * Convert amount from cent units to dollar units 
 *
 * @param float $amountInCents Amount in cents
 * @return float Converted amount
*/
function convertQuantityCurrency(float $amountInCents): float{
    $amountInDollars = $amountInCents / Constants::TRANSACTION_USD_CONVERSION_FACTOR;
    return $amountInDollars;
}

/**
 * Truncate address for easier display
 *
 * @param string $address the address
 * @param int $length point of truncation
 * @return string Truncated address
*/
function truncateAddress($address, $length = 10) {
    if (strlen($address) <= $length) {
        return $address;
    }
    return substr($address, 0, $length) . '...';
}
