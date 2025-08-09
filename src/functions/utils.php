<?php
# Copyright 2025

function calculateAvailableFunds($request){
    // Calculate funds request's sender has available with user
    $totalSent = calculateTotalSent($request['senderPublicKey'] ?? $request['sender_public_key']);   // Calculate IOUs sent to sender
    $totalReceived = calculateTotalReceived($request['senderPublicKey'] ?? $request['sender_public_key']); // Calulcate IOUs received from sender
    $theirCurrentBalance = $totalSent - $totalReceived; 
    $senderContact = lookupContactByAddress($request['senderAddress'] ?? $request['sender_address']);
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

function changeSettings($argv) {
    global $user;

    // Check if command line based or user input based
    if(isset($argv[2])){
        if(strtolower($argv[2]) === 'defaultfee'){
            $key = 'defaultFee';
            $value = floatval($argv[3]);
        }elseif(strtolower($argv[2]) === 'defaultcurrency'){
            // To do: when more currencies added, check if valid currency
            $key = 'defaultCurrency';
            $value = strtoupper($argv[3]);
        }elseif(strtolower($argv[2]) === 'localhostonly'){
            $key = 'localhostOnly';
            $value = ($argv[3] === '1');
        }elseif(strtolower($argv[2]) === 'maxfee'){
            $key = 'maxFee';
            $value = floatval($argv[3]);
        }elseif(strtolower($argv[2]) === 'maxp2pLevel'){
            $key = 'maxP2pLevel';
            $value = intval($argv[3]);
        }elseif(strtolower($argv[2]) === 'p2pexpiration'){
            $key = 'p2pExpiration';
            $value = intval($argv[3]);
        }elseif(strtolower($argv[2]) === 'maxoutput'){
            $key = 'maxOutput';
            if($argv[3] === 'all'){
                $value = 'all';
            } else{
                $value = intval($argv[3]);
            }    
        }else{
            echo "Setting provided does not exist. No changes made.\n";
            return;
        }        
    } else{

        // Display current settings
        displayCurrentSettings();
        
        // Prompt user for which setting they want to change
        echo "Select the setting you want to change:\n";
        echo "\t1. Default Fee\n";
        echo "\t2. Default Currency\n";
        echo "\t3. Access Mode\n";
        echo "\t4. Maximum Fee\n";
        echo "\t5. Maximum Peer to Peer Level\n";
        echo "\t6. Default Peer to Peer Expiration\n";
        echo "\t7. Maximum lines of Balance/Transaction output\n";
        echo "\t8. Cancel\n";

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
                $value = (trim(fgets(STDIN)) === '1');
                break;
            
            case '4':
                echo "Enter new maximum fee percentage: ";
                $key = 'maxFee';
                $value = floatval(trim(fgets(STDIN)));
                break;
            
            case '5':
                echo "Enter new Maximum Peer to Peer Level: ";
                $key = 'maxP2pLevel';
                $value = intval(trim(fgets(STDIN)));
                break;
            
            case '6':
                echo "Enter new Peer to Peer Expiration (in seconds): ";
                $key = 'p2pExpiration';
                $value = intval(trim(fgets(STDIN)));
                break;

            case '7':
                echo "Enter new Maximum of Balance/Transaction output lines to display: ";
                $key = 'maxOutput';
                $read = trim(fgets(STDIN));
                if($read === 'all'){
                    $value = 'all';
                } else{
                    $value = intval($read);
                } 
                break;
            
            case '8':
                echo "Setting change cancelled.\n";
                return;
            
            default:
                echo "Invalid selection. No changes made.\n";
                return;
        }
    }

    // Check for zero value due to typecasting actual text to number or using zero (or less than) where not possible
    if($value < 0 || ($value === 0 && $key != 'defaultFee')){
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
    echo "\tDefault fee: " . $user['defaultFee'] ."%\n";
    echo "\tDefault currency: " . $user['defaultCurrency'] . "\n";
    echo "\tAccess Mode: " . ($user['localhostOnly'] ? "Local Access Only" : "Network Authorized") . "\n";
    echo "\tMaximum Fee: " . $user['maxFee'] . "%\n";
    echo "\tMaximum Peer to Peer Level: " . $user['maxP2pLevel'] . "\n";
    echo "\tDefault Peer to Peer Expiration: " . $user['p2pExpiration'] . " seconds\n";
    echo "\tDefault Maximum lines of balance output: " . $user['maxOutput'] . "\n";
}

function displayHelp($argv) {
    // Display available commands to user in the CLI
    if(isset($argv[2])){
        echo "Command:\n";
        if(strtolower($argv[2]) === 'defaultfee'){
        } elseif(strtolower($argv[2]) === 'add'){
            echo "\tadd [address] [name] [fee] [credit] [currency] - Add a new contact.\n";
        } elseif(strtolower($argv[2]) === 'viewcontact'){
            echo "\tviewcontact [address/name] - View contact information.\n";
        } elseif(strtolower($argv[2]) === 'update'){
            echo "\tupdate [address/name] [all/name/fee/credit] ([name]) ([fee]) ([credit]) - Update a contact.\n";
        } elseif(strtolower($argv[2]) === 'block'){
            echo "\block [address/name] - Block a contact.\n";
        } elseif(strtolower($argv[2]) === 'delete'){
            echo "\unblock [address/name] - Unblock a contact.\n";
        } elseif(strtolower($argv[2]) === 'delete'){
            echo "\tdelete [address/name] - Delete a contact.\n";
        } elseif(strtolower($argv[2]) === 'send'){
            echo "\tsend [address/name] [amount] [currency] - Send an eIOU.\n";
        } elseif(strtolower($argv[2]) === 'viewbalances'){
            echo "\tviewbalances ([address/name]) - View eIOU balance(s).\n";
        } elseif(strtolower($argv[2]) === 'history'){
            echo "\thistory ([address/name]) - View transaction history for contacts, (default: all contacts).\n";
        } elseif(strtolower($argv[2]) === 'help'){
            echo "\thelp - Display this help information.\n";
        } elseif(strtolower($argv[2]) === 'viewsettings'){
            echo "\tviewsettings - View current settings.\n";
        } elseif(strtolower($argv[2]) === 'changesettings'){
            echo "\tchangesettings - Change settings.\n";
        } elseif(strtolower($argv[2]) === 'generate'){
            echo "\tgenerate - Generate a new wallet.\n";
        } else{
            echo "\tcommand does not exist.\n";
        }
    } else{
        echo "Available commands:\n";
        echo "\tadd [address] [name] [fee] [credit] [currency] - Add a new contact.\n";
        echo "\tviewcontact [address/name] - View contact information.\n";
        echo "\tupdate [address/name] [all/name/fee/credit] ([name]) ([fee]) ([credit]) - Update a contact.\n";
        echo "\tblock [address/name] - Block a contact.\n";
        echo "\tunblock [address/name] - Unblock a contact.\n";
        echo "\tdelete [address/name] - Delete a contact.\n";
        echo "\tsend [address/name] [amount] [currency] - Send an eIOU.\n";
        echo "\tviewbalances ([address/name]) - View eIOU balance(s).\n";
        echo "\thistory ([address/name]) - View transaction history for contacts, (default: all contacts).\n";
        echo "\thelp - Display this help information.\n";
        echo "\tviewsettings - View current settings.\n";
        echo "\tchangesettings - Change settings.\n";
        echo "\tgenerate - Generate a new wallet.\n";
    }
    
}

function displayUserInfo($argv) {
    global $user;
    
    // Display user information
    echo "User Information:\n";
    
    // Locators array
    $locators = array(
        'Tor' => $user['torAddress']
    );

    if(isset($user['hostname'])){
        $locators['Http'] = $user['hostname'];
        $userAddress = $user['hostname'];
    } else{
        $userAddress = $user['torAddress'];
    }
    
    // Output locators
    echo "\tLocators:\n";
    foreach ($locators as $type => $address) {
        printf("\t\t• %-5s: %s\n",$type,$address);
    }
    
    // Authentication code is from the config file
    echo "\tAuthentication Code: " . $user['authcode'] . "\n";

    // Public key is from the config file
    $readablePubKey = "\n\t\t" . str_replace("\n","\n\t\t",$user['public']);
    echo "\tPublic Key:" . $readablePubKey . "\n";

    // Calculate total sent and received
    $totalReceived = calculateTotalReceivedUser(); // Received by user
    $totalSent = calculateTotalSentUser(); // Sent by user
    $balance = ($totalReceived - $totalSent) / 100;
    
    echo "\tTotal Balance: " . number_format($balance, 2) . "\n";

    if(isset($argv[2]) && $argv[2] === 'detail'){
        // Define limit of output displayed
        if(isset($argv[3]) && ($argv[3] === 'all' || intval($argv[3]) > 0)){
            $limit = $argv[3];                   
        } else{
            $limit = $user['maxOutput'];
        }
        viewBalanceQuery("received",$userAddress,$limit); // Received Balances
        viewBalanceQuery("sent",$userAddress,$limit); // Sent Balances
    }
}

function feeInformation($p2p,$rp2p){
    // Output fee information into the log
    $feeAmount = $rp2p['amount'] - $p2p['amount'];
    $feePercent = ($feeAmount / $p2p['amount']) * 100;
    output(outputFeeInformation($feePercent,$request,$user['maxFee']), 'SILENT');
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
    $p2pRequest = lookupP2pRequest($request['memo']);
    if( hash('sha256', $address . $p2pRequest['salt'] . $p2pRequest['time']) === $request['memo']) {
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

function removeTransactionFee($request){
    // Remove users transaction fee from request
    $p2p = getP2pByHash($request['memo']);
    return $request['amount'] - $p2p['my_fee_amount'];
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

function returnconvertedMicroTime($time){
    return $time*10000;
}

function returnMicroTime(){
    return returnconvertedMicroTime(microtime(true));
}

function verifyRequest($request) {
    // Check if request is valid based on signature
    $publicKeyResource = openssl_pkey_get_public($request['senderPublicKey']);
    $verified = openssl_verify($request['message'], base64_decode($request['signature']), $publicKeyResource);
    
    // Step 3: Output the verification result
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