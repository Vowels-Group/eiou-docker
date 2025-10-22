<?php
# Copyright 2025

/**
 * Make sure only one lockfile exists at a time, if none exists create a new lockfile
 *
 * @param string $lockfile The path of the lockfile
*/
function checkSingleInstance(string $lockfile = '/tmp/messages_lock.pid') {
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

/**
 * Check if request level is valid
 *
 * @param array $request The request data
 * @return bool True if requestlevel is valid, False otherwise
*/
function validateRequestLevel(array $request): bool {
    return $request['requestLevel'] <= $request['maxRequestLevel'];
}

/**
 * Validate the CLI send parameters
 *
 * @param array $data The CLI send parameters
 * @return bool True if all parts of the request are valid, False otherwise
*/
function validateSendRequest(array $data): bool {
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

    // Optional: Validate currency if provided
    if (isset($data[4])) {
        $currency = strtoupper($data[4]);
        // You can add more specific currency validation if needed
        if (strlen($currency) !== 3) {
            echo returnInvalidCurrencySendRequest();
            return false;
        }
    } 
    return true;
}

/**
 * Check if request is valid based on signature
 *
 * @param array $request The request data
 * @return bool True if signature could be verified, False otherwise
*/
function verifyRequest(array $request): bool {
    $publicKeyResource = openssl_pkey_get_public($request['senderPublicKey']);
    $verified = openssl_verify($request['message'], base64_decode($request['signature']), $publicKeyResource);
    
    // Output the verification result
    if ($verified === 1) {
        return true; // continue
    } elseif ($verified === 0) {
        echo json_encode(["status" => "rejected", "message" => "Signature is invalid"]);
        return false;
    } else {
        echo json_encode(["status" => "error", "message" => "Error occurred during verification"]);
        return false;
    }
}