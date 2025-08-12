<?php
# Copyright 2025

function changeSettings($argv) {
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

function viewBalances($data) {
    // View balance information based on transactions
    global $pdo, $user;
    $query = "SELECT sender_address, receiver_address, amount, currency, timestamp FROM transactions";
    // Check if an address or name is provided
    if (isset($data[2])) {
        // Check if it's a HTTP or Tor address
        if (isHttpAddress($data[2]) || isTorAddress($data[2])) {
            $address = $data[2];
        } else{
             // Check if the name yields an address
            $contactResult = lookupContactByName($data[2]);
            $address = $contactResult['address'] ?? null;
        }
        // Add WHERE clause if a valid address is found
        if ($address) {
            $query .= " WHERE sender_address = :address OR receiver_address = :address ORDER BY timestamp DESC";
        } else{
            echo "Address/Name unknown, displaying all balances";
            $query .= " ORDER BY timestamp DESC";   
        }    
    }

    $balances = [];
    $stmt = $pdo->prepare($query);
    
    if (isset($data[2]) && $address) {
        $stmt->bindParam(':address', $address);
    }
    
    $stmt->execute();
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Calculate balance changes
        $senderAddress = $row['sender_address'];
        $receiverAddress = $row['receiver_address'];
        $amount = $row['amount'] / 100;
        
        // Adjust balances for sender and receiver
        $balances[$senderAddress] = ($balances[$senderAddress] ?? 0) - $amount;
        $balances[$receiverAddress] = ($balances[$receiverAddress] ?? 0) + $amount;
    }
    $otherBalances = [];
    
    // Pretty print balances
    foreach ($balances as $address => $balance) {
        // Check if the address is the user's own address
        if (isMe($address)) {
            $displayName = "me";
            $additionalAddresses = [];
            
            if (isset($user['hostname'])) {
                $additionalAddresses[] = $user['hostname'];
            }
            
            if (isset($user['torAddress'])) {
                $additionalAddresses[] = $user['torAddress'];
            }
            
            $additionalInfo = $additionalAddresses ? '(' . implode(', ', $additionalAddresses) . ')' : '';
            
            printf("%s %s, Balance: %.2f\n", $displayName, $additionalInfo, $balance);
        } else {
            // If it's not the user's own address, add to a list to be sorted
            // Lookup contact name for the address
            $contactResult = lookupContactByAddress($address);
            $contactName = $contactResult ? $contactResult['name'] : $address;
            
            $otherBalances[] = [
                'address' => $address, 
                'name' => $contactName, 
                'balance' => $balance
            ];
        }
    }

    // Sort and print other balances
    usort($otherBalances, function($a, $b) {
        return $b['balance'] <=> $a['balance'];
    });

    foreach ($otherBalances as $contact) {   
        printf("\t%s (%s), Balance: %.2f\n", $contact['name'], $contact['address'], $contact['balance']);
    }
}