<?php
# Copyright 2025

function changeSettings($argv) {
    // Change the default settings to user-input
    // Check if command line based or user input based
    if(isset($argv[2])){
        if(strtolower($argv[2]) === 'defaultfee'){
            $key = 'defaultFee';
            $value = floatval($argv[3]);
        }elseif(strtolower($argv[2]) === 'defaultcurrency'){
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
    $currentUser = UserContext::getInstance();
    echo "Current Settings:\n";
    echo "\tDefault fee: " . $currentUser->getDefaultFee() ."%\n";
    echo "\tDefault currency: " . $currentUser->getDefaultCurrency() . "\n";
    echo "\tAccess Mode: " . ($currentUser->isLocalhostOnly() ? "Local Access Only" : "Network Authorized") . "\n";
    echo "\tMaximum Fee: " . $currentUser->getMaxFee() . "%\n";
    echo "\tMaximum Peer to Peer Level: " .  $currentUser->getMaxP2pLevel() . "\n";
    echo "\tDefault Peer to Peer Expiration: " .  $currentUser->getP2pExpirationTime() . " seconds\n";
    echo "\tDefault Maximum lines of balance output: " .  $currentUser->getMaxOutput() . "\n";
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
    // Display user information
    $currentUser = UserContext::getInstance();
    $transactionService = ServiceContainer::getInstance()->getTransactionService();
    
    echo "User Information:\n";
    
    // Locators array
    $locators = $currentUser->getUserLocaters();
    
    // Output locators
    echo "\tLocators:\n";
    foreach ($locators as $type => $address) {
        printf("\t\t• %-5s: %s\n",$type,$address);
    }
    
    // Authentication code is from the config file
    echo "\tAuthentication Code: " . $currentUser->getAuthCode() . "\n";

    $pubkey = $currentUser->getPublicKey();
    // Public key is from the config file
    $readablePubKey = "\n\t\t" . str_replace("\n","\n\t\t",$pubkey);
    echo "\tPublic Key:" . $readablePubKey . "\n";

    // Calculate total sent and received
    $totalReceived = $transactionService->calculateTotalReceived($pubkey);
    $totalSent = $transactionService->calculateTotalSent($pubkey);
    $balance = convertQuantityCurrency(($totalReceived - $totalSent));
    
    echo "\tTotal Balance: " . number_format($balance, 2) . "\n";

    if (isset($argv[2]) && $argv[2] === 'detail') {
        // Define limit of output displayed
        if(isset($argv[3]) && ($argv[3] === 'all' || intval($argv[3]) > 0)){
            $limit = $argv[3];                   
        } else{
            $limit = $currentUser->getMaxOutput();
        }

        viewBalanceQuery("received","from",$transactionService->getReceivedUserTransactions(PHP_INT_MAX),$limit); // Received Balances
        viewBalanceQuery("sent","to",$transactionService->getSentUserTransactions(PHP_INT_MAX),$limit); // Sent Balances
    }
}

function viewBalanceQuery($direction, $where, $results, $limit){
     // View balance information based on transactions, either received or send by user
    $contactService = ServiceContainer::getInstance()->getContactService();
   
    $countResults = count($results);
    
    echo "\t\tBalance $direction $where:\n";
    $countrows = 1;
    foreach ($results as $res) {
        printf("\t\t\t%s (%s) %s, %.2f %s\n", 
                $contactService->lookupNameByAddress($res['counterparty']), 
                truncateAddress($res['counterparty']), 
                $res['date'], 
                $res['amount'], 
                $res['currency']);
        if($limit !== 'all' && ($countrows >= $limit)){
            break;
        } 
        $countrows += 1;
    }
    if ($limit === 'all' || $limit > $countResults) {
        $limit = $countResults;
    } 
    echo "\t\t\t----- Displaying $limit out of $countResults $direction balance(s) -----\n";
}

function viewBalances($data) {
    // View balance information based on transactions
    $currentUser = UserContext::getInstance();
    $contactService = ServiceContainer::getInstance()->getContactService();
    $transactionService = ServiceContainer::getInstance()->getTransactionService();

    $userBalance = $transactionService->getUserTotalBalance();
    $additionalAddresses = $currentUser->getUserAddresses();
    $additionalInfo = $additionalAddresses ? '(' . implode(', ', $additionalAddresses) . ')' : '';
    printf("%s %s, Balance: %.2f\n", 'me', $additionalInfo, $userBalance);

    // Check if an address or name is provided
    if (isset($data[2])) {
        // Check if it's a HTTP or Tor address
        if (isHttpAddress($data[2]) || isTorAddress($data[2])) {
            $address = $data[2];
            if($contactService->contactExists($address)){
                $contactResult = $contactService->lookupContactByAddress($address);
            }
        } else{
             // Check if the name yields an address
            $contactResult = $contactService->lookupContactByName($data[2]);
        }
        if ($contactResult) {
            $contactBalance = convertQuantityCurrency($transactionService->getContactBalance($currentUser->getPublicKey(),$contactResult['pubkey']));
            printf("\t%s (%s), Balance: %.2f\n", $contactResult['name'], $contactResult['address'], $contactBalance);
            return;
        } else{
            echo "Address/Name unknown, displaying all balances\n";
        }    
    }
    $contacts = $contactService->getAllContacts();
    $pubkeys = $contactService->getAllContactsPubkeys();
    $balances = $transactionService->getAllContactBalances($currentUser->getPublicKey(),$pubkeys);
    foreach($contacts as $contact){
        printf("\t%s (%s), Balance: %.2f\n", $contact['name'], $contact['address'], convertQuantityCurrency($balances[$contact['pubkey']]));
    }
    
}

function viewTransactionHistory($argv) {
    // View all transaction history in pretty print 'table'
    global $pdo;
    $currentUser = UserContext::getInstance();
    $contactService = ServiceContainer::getInstance()->getContactService();

    $query = "SELECT sender_address, receiver_address, amount, currency, timestamp FROM transactions";
    $address = null;
    $displayLimit = $currentUser->getMaxOutput();
    // Check if an address or name is provided
    if (isset($argv[2])) {
        // First if it's an HTTP or Tor address
        if (isHttpAddress($argv[2]) || isTorAddress($argv[2])) {
            $address = $argv[2];
        } else {
            // Check if the name yields an address
            $contactResult = $contactService->lookupContactByName($argv[2]);
            $address = $contactResult ? $contactResult['address'] : $argv[2];
        }
        // Add WHERE clause if a valid address is found
        if ($address) {
            $query .= " WHERE sender_address = :address OR receiver_address = :address";
        } 
    }
    // Add ordering
    $query .= " ORDER BY timestamp DESC";
    // Add limit depending on passed parameter
    if(isset($argv[3]) && ($argv[3] === 'all' || intval($argv[3]) > 0)){
        $displayLimit = $argv[3];
    }
    
    $stmt = $pdo->prepare($query);
    
    // Bind address param
    if ($address) {
        $stmt->bindParam(':address', $address);
    }
    
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pretty print results in 'table'
    if ($results) {
        echo "Transaction History:\n";
        echo "-------------------------------------------\n";
        echo str_pad("Sender name (Address)", 56, ' ') . " | " . 
             str_pad("Receiver name (Address)", 56, ' ') . " | " . 
             str_pad("Amount", 10, ' ') . " | " . 
             str_pad("Currency", 10, ' ') . " | " . 
             "Timestamp\n";
        echo "-------------------------------------------\n";
        $countResults = count($results);
        $countrows = 1;
        foreach ($results as $transaction) {
            // Lookup sender name
            $senderResult = $contactService->lookupContactByAddress($transaction['sender_address']);
            $senderName = $senderResult ? $senderResult['name'] : $transaction['sender_address'];
            
            // Lookup receiver name
            $receiverResult = $contactService->lookupContactByAddress($transaction['receiver_address']);
            $receiverName = $receiverResult ? $receiverResult['name'] : $transaction['receiver_address'];
            
            // Replace name with 'me' if the address is mine
            $senderName = isMe($transaction['sender_address']) ? 'me' : $senderName;
            $receiverName = isMe($transaction['receiver_address']) ? 'me' : $receiverName;
            
            echo str_pad($senderName . " (" . $transaction['sender_address'] . ")", 56, ' ') . " | " . 
                 str_pad($receiverName . " (" . $transaction['receiver_address'] . ")", 56, ' ') . " | " . 
                 str_pad(number_format(convertQuantityCurrency($transaction['amount']), 2), 10, ' ') . " | " . 
                 str_pad($transaction['currency'], 10, ' ') . " | " . 
                 $transaction['timestamp'] . "\n";
            if($displayLimit !== 'all' && ($countrows >= $displayLimit)){
                break;
            } 
            $countrows += 1;        
        }
        echo "-------------------------------------------\n";
        if($displayLimit === 'all'){
            $displayLimit = $countResults;
        } elseif($displayLimit > $countResults){
            $displayLimit = $countResults;
        }
        echo "Displaying " . $displayLimit .  " out of " . $countResults . " total transactions.\n";
    } else {
        echo "No transaction history found.\n";
    }
}