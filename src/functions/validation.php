<?php

function checkP2pForContact($request) {
    
}

function checkSingleInstance($lockfile = '/tmp/messages_lock.pid') {
    if (file_exists($lockfile)) {
        $pid = file_get_contents($lockfile);
        if (posix_kill($pid, 0)) {
            echo "Another instance is already running.\n";
            exit(1);
        }
    }
    // Create lockfile with current process ID
    file_put_contents($lockfile, getmypid());
    echo "Created lockfile at $lockfile with PID " . getmypid() . "\n";
}

function countTorAndHttpAddresses($data){
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
    return preg_match('/^https?:\/\//', $address) === 1;
}

function isMe($address){
    global $user;
    return (isset($user['torAddress']) && $user['torAddress'] === $address) || 
           (isset($user['hostname']) && $user['hostname'] === $address);
}

function isTorAddress($address) {
    return preg_match('/\.onion$/', $address) === 1;
}

function truncateInArray($data, $max_length = 50) {
    if (!is_array($data)) {
        return $data;
    }

    foreach ($data as $key => &$value) {
        if (is_string($value)) {
            // Truncate long strings
            if (strlen($value) > $max_length) {
                $value = substr($value, 0, $max_length) . '... [truncated]';
            }
        } elseif (is_array($value)) {
            // Recursively truncate nested arrays
            $value = truncateLargeStringsInArray($value, $max_length);
        }
    }

    return $data;
}

function validateRequestLevel($request){
    return $request['requestLevel'] < $request['maxRequestLevel'];
}

function validateSendRequest($data) {
    // Validate the send request
    if (count($data) < 4) {
        echo "Incorrect usage. Please use the following format:\n";
        echo "eiou send [recipient] [amount] [optional: currency]\n";
        echo "Example: eiou send Bob 50\n";
        echo "Example: eiou send 123abc.onion 100 USD\n";
        return false;
    }

    // Validate amount is a positive number
    $amount = $data[3];
    if (!is_numeric($amount) || floatval($amount) <= 0) {
        echo "Invalid amount. Please enter a positive number.\n";
        return false;
    }

    // Optional: Validate currency if provided
    if (isset($data[4])) {
        $currency = strtoupper($data[4]);
        // You can add more specific currency validation if needed
        if (strlen($currency) !== 3) {
            echo "Invalid currency. Please use a 3-letter currency code (e.g., USD).\n";
            return false;
        }
    }

    return true;
}