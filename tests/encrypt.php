<?php

function encryptWithPublicKey($message) {
    global $user;
    
    // Check if public key exists
    if (!isset($user['public'])) {
        echo "No public key found. Please generate a wallet first.\n";
        return false;
    }
    
    // Get the public key resource
    $publicKeyResource = openssl_pkey_get_public($user['public']);
    
    if (!$publicKeyResource) {
        echo "Failed to load public key.\n";
        return false;
    }
    
    // Encrypt the message
    $encryptedData = '';
    $result = openssl_public_encrypt($message, $encryptedData, $publicKeyResource);
    
    if ($result === false) {
        echo "Encryption failed.\n";
        return false;
    }
    
    // Return base64 encoded encrypted data
    return base64_encode($encryptedData);
}

// Example usage
$message = "Hello, this is a secret message!";
$encryptedMessage = encryptWithPublicKey($message);
if ($encryptedMessage) {
    echo "Encrypted message: " . $encryptedMessage . "\n";
}
