<?php

function checkWalletExists($user, $request) {
    if ((!isset($user['public']) || !isset($user['private'])) && $request != 'generate' && $request != 'restore') {
        echo "No wallet found. Please generate a new wallet by running 'eiou generate' or restore an existing wallet by running 'eiou restore'.\n";
        exit();
    }
}

function generateWallet($argv) {
  // Generate a private key
  $config = array(
      "private_key_bits" => 2048,
      "curve_name" => "secp256k1"
  );
  $res = openssl_pkey_new($config);
  openssl_pkey_export($res, $privateKey);

  // Extract public key from the private key
  $keyDetails = openssl_pkey_get_details($res);
  $publicKey = $keyDetails['key'];

  // Save the keys to config.php
  file_put_contents('/var/www/html/eiou/config.php', "\n" . '$user["public"]="' . addslashes($publicKey) . '";' . "\n" . '$user["private"]="' . addslashes($privateKey) . '";' . "\n", FILE_APPEND | LOCK_EX);

  // Output Tor address
  $torAddress = trim(file_get_contents('/var/lib/tor/hidden_service/hostname'));
  
  // Check if torAddressOnly flag is set
  if (isset($argv[2]) && $argv[2] === 'torAddressOnly') {
      echo $torAddress . "\n";
      return;
  }
  // Else argv2 is the (http/s) hostname of the container
  elseif (isset($argv[2])) {
    if (filter_var($argv[2], FILTER_VALIDATE_URL)) {
        // Save the hostname to the configuration
        $config_content = file_get_contents('/var/www/html/eiou/config.php');
        $config_content .= "\n" . '$user["hostname"]="' . addslashes($argv[2]) . '";' . "\n";
        file_put_contents('/var/www/html/eiou/config.php', $config_content, LOCK_EX);

        echo "Hostname saved: " . $argv[2] . "\n";
    } else {
        echo "Invalid hostname format. Please provide a valid URL.\n";
        exit(1);
    }
    return;
  }
  

  echo "Public key: $publicKey\n";
  echo "Private key: $privateKey\n";
  echo "Tor Address: $torAddress\n";
  echo "Please save these keys securely, or write the name of a file to output to (leave blank for none): \n";
  $privateKeyFile = trim(fgets(STDIN));
  if (!empty($privateKeyFile)) {
      // Save the private key to the specified file
      file_put_contents($privateKeyFile, $privateKey);
      echo "Private key saved to $privateKeyFile\n";
  }
}

function restoreWallet($argv) {
  echo "Enter the file name containing the private key: ";
  $privateKeyFile = trim(fgets(STDIN));
  $privateKey = trim(file_get_contents($privateKeyFile));
  
  // Verify the private key
  $privateKeyResource = openssl_pkey_get_private($privateKey);

  if ($privateKeyResource) {
      // Extract the public key from the private key
      $keyDetails = openssl_pkey_get_details($privateKeyResource);
      $publicKey = $keyDetails['key'];

      // Save the keys to the config file
      $publicKeyData = '$user["public"]="' . addslashes($publicKey) . '";' . "\n";
      $privateKeyData = '$user["private"]="' . addslashes($privateKey) . '";' . "\n";
      file_put_contents('/var/www/html/eiou/config.php', $publicKeyData, FILE_APPEND | LOCK_EX);
      file_put_contents('/var/www/html/eiou/config.php', $privateKeyData, FILE_APPEND | LOCK_EX);
      echo "Public and private keys verified and saved successfully.";
  } else {
      echo "Invalid private key provided.";
  }
}
