<?php
# Copyright 2025

function calculateAvailableFunds($request){
    // Calculate funds request's sender has available with user
    $totalSent = calculateTotalSent($request['senderPublicKey']);   // Calculate IOUs sent to sender
    $totalReceived = calculateTotalReceived($request['senderPublicKey']); // Calulcate IOUs received from sender
    $theirCurrentBalance = $totalSent - $totalReceived; 
    $senderContact = lookupContactByAddress($request['senderAddress']);
    $creditLimit = getCreditLimit($senderContact['pubkey']);    // Get senders credit limit with user
    return $theirCurrentBalance + $creditLimit;
}

function calculateRequestedAmount($request) {
    // Calculate total amount needed for p2p through user
    global $user;
    $senderContact = lookupContactByAddress($request['senderAddress']);
    $fee = ($senderContact ? $senderContact['fee_percent'] : $user['defaultFee']) / 10000; //convert back to percent for math
    $request['feeAmount'] = round($request['amount'] * $fee);   // Caculate fee on the amount sender wants sent
    return $request['amount'] + $request['feeAmount'];
}

function removeTransactionFee($request){
    // Remove users transaction fee from request
    $p2p = getP2pByHash($request['memo']);
    return $request['amount'] - $p2p['my_fee_amount'];
}

function changeSettings($argv) {
    global $user;

    // Check if command line based or user input based
    if(isset($argv[2])){
        if(strtolower($argv[2]) == 'defaultfee'){
            $key = 'defaultFee';
            $value = floatval($argv[3]);
        }elseif(strtolower($argv[2]) == 'defaultcurrency'){
            // To do: when more currencies added, check if valid currency
            $key = 'defaultCurrency';
            $value = strtoupper($argv[3]);
        }elseif(strtolower($argv[2]) == 'localhostonly'){
            $key = 'localhostOnly';
            $value = ($argv[3] == '1');
        }elseif(strtolower($argv[2]) == 'maxfee'){
            $key = 'maxFee';
            $value = floatval($argv[3]);

        }elseif(strtolower($argv[2]) == 'maxp2pLevel'){
            $key = 'maxP2pLevel';
            $value = intval($argv[3]);
        }elseif(strtolower($argv[2]) == 'p2pexpiration'){
            $key = 'p2pExpiration';
            $value = intval($argv[3]);
        }else{
            echo "Setting provided does not exist. No changes made.\n";
            return;
        }        
    } else{

        // Display current settings
        displayCurrentSettings();
        
        // Prompt user for which setting they want to change
        echo "Select the setting you want to change:\n";
        echo "\t1. Default Fees\n";
        echo "\t2. Default Currency\n";
        echo "\t3. Access Mode\n";
        echo "\t4. Maximum Fee\n";
        echo "\t5. Maximum Peer of Peer Level\n";
        echo "\t6. Default Peer of Peer Expiration\n";
        echo "\t7. Cancel\n";

        // Read user input
        $setting_choice = trim(fgets(STDIN));
        
        switch($setting_choice) {
            case '1':
                echo "Enter new default fee percentage: ";
                $key = 'defaultFee';
                $value = floatval(trim(fgets(STDIN)));
                break;
            
            case '2':
                echo "Enter new default currency (e.g., USD): ";
                $key = 'defaultCurrency';
                $value = strtoupper(trim(fgets(STDIN)));
                break;
            
            case '3':
                echo "Enter access mode (0 for Network Enabled, 1 for LocalHost Only): ";
                $key = 'localhostOnly';
                $value = (trim(fgets(STDIN)) == '1');
                break;
            
            case '4':
                echo "Enter new maximum fee percentage: ";
                $key = 'maxFee';
                $value = floatval(trim(fgets(STDIN)));
                break;
            
            case '5':
                echo "Enter new Maximum Peer of Peer Level: ";
                $key = 'maxP2pLevel';
                $value = intval(trim(fgets(STDIN)));
                break;
            
            case '6':
                echo "Enter new Peer of Peer Expiration (in seconds): ";
                $key = 'p2pExpiration';
                $value = intval(trim(fgets(STDIN)));
                break;
            
            case '7':
                echo "Setting change cancelled.\n";
                return;
            
            default:
                echo "Invalid selection. No changes made.\n";
                return;
        }
    }

    // Check for zero value due to typecasting actual text to number or using zero where not possible
    if($value < 0 || ($value == 0 && $key != 'defaultFee')){
        echo "Value is invalid for setting. No changes made.\n";
        return;
    }

    // Save changes to config file
    $config_content = file_get_contents('/etc/eiou/config.php');
    $config_content = preg_replace("/\['" . $key . "'\]\s*=\s*[^;]+;/", "['" . $key . "'] = " . (is_bool($value) ? ($value ? 'true' : 'false') : (is_string($value) ? "'" . $value . "'" : $value)) . ";", $config_content);
    file_put_contents('/etc/eiou/config.php', $config_content);
    require_once("/etc/eiou/config.php"); // reload config 
    echo "Setting updated successfully.\n";
    
}

function displayCurrentSettings() {
    // Display current settings of user
    global $user;
    echo "Current Settings:\n";
    echo "\tDefault fees: " . $user['defaultFee'] ."%\n";
    echo "\tDefault currency: " . $user['defaultCurrency'] . "\n";
    echo "\tAccess Mode: " . ($user['localhostOnly'] ? "Local Access Only" : "Network Authorized") . "\n";
    echo "\tMaximum Fee: " . $user['maxFee'] . "%\n";
    echo "\tMaximum Peer of Peer Level: " . $user['maxP2pLevel'] . "\n";
    echo "\tDefault Peer of Peer Expiration: " . $user['p2pExpiration'] . " seconds\n";
}

function displayHelp() {
    // Display available commands to user in the CLI
    echo "\n\nAvailable commands:\n";
    echo "add [address] [name] [fee] [credit] [currency] - Add a new contact.\n";
    echo "viewcontact [address/name] - Read contact information.\n";
    echo "update [type] [address/name] [(name)] [(fee)] [(credit)] - Update a contact.\n";
    echo "delete [address/name] - Delete a contact.\n";
    echo "send [address/name] [amount] [currency] - Send an eIOU.\n";
    echo "viewbalances ([address/name]) - View eIOU balance(s).\n";
    echo "history ([address/name]) - View transaction history for contacts, (default: all contacts).\n";
    echo "help - Display this help information.\n";
    echo "viewsettings - View current settings.\n";
    echo "changesettings - Change settings.\n";
    echo "generate - Generate a new wallet.\n";
}

function displayUserInfo($user) {
    // Display user information
    echo "User Information:\n";
    
    // Locators array
    $locators = array(
        'Tor' => $user['torAddress']
    );

    if(isset($user['hostname'])){
        $locators['Http'] = $user['hostname'];
    }
    
    // Output locators
    echo "Locators:\n";
    foreach ($locators as $type => $address) {
        echo "• $type: $address\n";
    }
    
    // Public key is from the config file
    echo "Public Key: " . $user['public'] . "\n";
    
    // Calculate total sent and received
    $totalSent = calculateTotalSent($user['public']);
    $totalReceived = calculateTotalReceived($user['public']);
    $balance = ($totalReceived - $totalSent) / 100;
    
    echo "Balance: " . number_format($balance, 2) . "\n";
}

function getContext(){
    $context = [];

    // Collect global variables
    global $argv, $pdo, $user;

    // Add command line arguments
    if (isset($argv)) {
        $context['argv'] = $argv;
    }

    // Add server request information
    if (isset($_SERVER['REQUEST_URI'])) {
        $context['request_uri'] = $_SERVER['REQUEST_URI'];
    }

    // Add user information if available
    if (isset($user)) {
        $context['user'] = [
            'id' => $user['id'] ?? null,
            'public_key' => $user['public'] ?? null,
            'tor_address' => $user['torAddress'] ?? null,
            'hostname' => $user['hostname'] ?? null,
            'debug' => $user['debug'] ?? null
        ];
    }

    // Add database connection information
    if (isset($pdo)) {
        $context['database'] = [
            'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)
        ];
    }

    // Add PHP environment details
    $context['php'] = [
        'version' => PHP_VERSION,
        'sapi' => php_sapi_name(),
        'os' => PHP_OS
    ];

    // Add current script details
    $context['script'] = [
        'file' => $_SERVER['SCRIPT_FILENAME'] ?? null,
        'dir' => __DIR__
    ];

    return json_encode($context, JSON_PRETTY_PRINT);
}

function matchContact($request) {
    $contacts = retrieveContacts();
    // Check if end recipient of request in contacts
    foreach ($contacts as $contact) {
        $contactHash = hash('sha256', $contact['address'] . $request['salt'] . $request['time']);
        // output("Calculating contact hash: address=" . $contact['address'] . ", salt=" . $request['salt'] . ", time=" . $request['time'], 'SILENT');
        // output("Calculated contact hash: " . $contactHash, 'SILENT');
        if ($contactHash === $request['hash']) {
            output("Contact matched with hash: " . $contactHash, 'SILENT');
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
    $p2pRequest = lookupP2pRequest($request['memo']);
    if( hash('sha256', $address . $p2pRequest['salt'] . $request['time']) === $request['memo']) {
        return true;
    }
    return false;
}

function output($message, $level = 'ECHO') {
    global $user;
    // Check if debug mode is enabled
    if (isset($user['debug']) && $user['debug'] === true) {
        $data = [
            'level' => $level,
            'message' => trim($message),
            'context' => getContext(),
            'file' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'],
            'line' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['line'],
            'trace' => json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
        ];
        insertDebug($data);
    }
    if ($level !== 'SILENT') {
        echo $message;
    }
}

function setupErrorLogging() {
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);

    // Ensure the log directory exists and is writable
    $log_dir = '/var/log/eiou';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }

    $log_file = $log_dir . '/eiou-php-error.log';
    ini_set('error_log', $log_file);

    // Optional: Verify log file is writable
    if (!is_writable($log_file)) {
        // Fallback to system temp directory if needed
        $log_file = sys_get_temp_dir() . '/eiou-php-error.log';
        ini_set('error_log', $log_file);
    }
}

function sign($payload){
  // Add signature to payload
  global $user;
  $privateKey = $user['private'];
  // Step 1: Get the private key resource
  $privateKeyResource = openssl_pkey_get_private($privateKey);
  // Step 2: Sign the message
  $payload['nonce'] = time();
  $message = json_encode($payload);
  $payload['message'] = $message;
  $signature = '';
  if (!openssl_sign($message, $signature, $privateKeyResource)) {
      echo "Failed to sign the message.\n";
      return false;
  }
  $payload['signature'] = base64_encode($signature);
  return $payload;
}

function verifyRequest($request) {
    // Check if request is valid based on signature
    $publicKeyResource = openssl_pkey_get_public($request['senderPublicKey']);
    $verified = openssl_verify($request['message'], base64_decode($request['signature']), $publicKeyResource);
    
    // Step 3: Output the verification result
    if ($verified == 1) {
        return true; // continue
    } elseif ($verified == 0) {
        echo json_encode(["status" => "rejected", "message" => "Signature is invalid"]);
        return false;
    } else {
        echo json_encode(["status" => "error", "message" => "Error occurred during verification"]);
        return false;
    }
}
