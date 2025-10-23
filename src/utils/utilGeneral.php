<?php
# Copyright 2025

/**
 * Calculate the funds the request sender has available with user
 *
 * @param array $request The p2p request data
 * @return int The funds available to handle request
*/
function calculateAvailableFunds(array $request): int {
    $pubkey = $request['senderPublicKey'] ?? $request['sender_public_key'];
    $totalSent = calculateTotalSent($pubkey);   // Calculate IOUs sent to sender
    $totalReceived = calculateTotalReceived($pubkey); // Calulcate IOUs received from sender
    $theirCurrentBalance = $totalSent - $totalReceived; 
    $creditLimit = getCreditLimit($pubkey);    // Get senders credit limit with user
    return $theirCurrentBalance + $creditLimit;
}

/**
 * Return fee percent of request and output fee information into the log
 *
 * @param array $p2p The p2p request data from the database
 * @param array $request The transaction request data
 * @return float Fee percent of request
*/
function feeInformation(array $p2p, array $request): float {
    $currentUser = UserContext::getInstance();
    $feeAmount = $request['amount'] - $p2p['amount'];
    $feePercent = round(($feeAmount / $p2p['amount']) * 100,2);
    output(outputFeeInformation($feePercent,$request,$currentUser->getMaxFee()), 'SILENT'); // output fee information into the log
    return $feePercent;
}

/**
 * Convert float of micrtotime to int by moving values behind comma to in front of comma
 *
 * @param float $time Float of microtime
 * @return int converted microtime 
*/
function returnconvertedMicroTime(float $time): int {
    return $time*10000;
}

/**
 * Get current micro-time stamp in int-form
 *
 * @return int (micro)-time stamp
*/
function returnMicroTime(): int {
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