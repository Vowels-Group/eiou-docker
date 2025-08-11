<?php
# Copyright 2025

function checkSingleInstance($lockfile = '/tmp/messages_lock.pid') {
    // Handle single instance of lockfile
    if (file_exists($lockfile)) {
        $pid = file_get_contents($lockfile);
        if (posix_kill($pid, 0)) {
            echo returnInstanceAlreadyRunning();
            exit(1);
        }
    }
    // Create lockfile with current process ID
    file_put_contents($lockfile, getmypid());
    echo returnLockfileCreation($lockfile,getmypid());
}

function countTorAndHttpAddresses($data){
    // Count how many tor and http addresses
    $result = [
        'tor' => count(array_filter($data, 'isTorAddress')),
        'http' => count(array_filter($data, 'isHttpAddress')),
        'total' => count($data)
    ];
    return $result;
}

function determineTransportType($address) {
    // Check if the address is a Tor (.onion) address
    if (isTorAddress($address)) {
        return 'tor';
    }
    
    // Check if the address is an HTTP/HTTPS address
    if (isHttpAddress($address)) {
        return 'http';
    }
    
    // If neither Tor nor HTTP, return null or a default type
    return null;
}

function isHttpAddress($address) {
    // Check if is http address
    return preg_match('/^https?:\/\//', $address) === 1;
}

function isMe($address){
    // Check if address is mine
    global $user;
    return (isset($user['torAddress']) && $user['torAddress'] === $address) || 
           (isset($user['hostname']) && $user['hostname'] === $address);
}

function isTorAddress($address) {
    // Check if is tor address
    return preg_match('/\.onion$/', $address) === 1;
}

function validateRequestLevel($request){
    // Check if request level is valid
    return $request['requestLevel'] <= $request['maxRequestLevel'];
}

function validateSendRequest($data) {
    // Validate the send request
    if (count($data) < 4) {
        echo returnInvalidSendRequest();
        return false;
    }

    // Validate amount is a positive number
    $amount = $data[3];
    if (!is_numeric($amount) || floatval($amount) <= 0) {
        echo returnInvalidAmountSendRequest();
        return false;
    }

    // Validate currency
    if (isset($data[4])) {
        $currency = strtoupper($data[4]);
        // You can add more specific currency validation if needed
        if (strlen($currency) !== 3) {
            echo returnInvalidCurrencySendRequest();
            return false;
        }
    } else{
        echo returnNotProvidedCurrencySendRequest();
    }
    return true;
}