<?php
# Copyright 2025

function acceptContact($address, $name, $fee, $credit, $currency) {
    global $pdo;
    $insertStmt = $pdo->prepare("UPDATE contacts SET name = :name, fee_percent = :fee, credit_limit = :credit, currency = :currency WHERE address = :address");
    $insertStmt->bindParam(':address', $address);
    $insertStmt->bindParam(':name', $name);
    $insertStmt->bindParam(':fee', $fee);
    $insertStmt->bindParam(':credit', $credit);
    $insertStmt->bindParam(':currency', $currency);
    
    try {
        if ($insertStmt->execute()) {
            return true;
        } else {
            return false;
        }
    } catch (PDOException $e) {
        error_log("Error updating contact: " . $e->getMessage());
        return false;
    }
}

function addPendingContact($address, $senderPublicKey) {
    global $pdo, $user;
    $myPublicKey = $user['public'];
    $pubkey_hash = hash('sha256', $senderPublicKey);
    $insertStmt = $pdo->prepare("INSERT INTO contacts (address, pubkey, pubkey_hash, name, fee_percent, credit_limit, currency) VALUES (:address, :pubkey, :pubkey_hash, NULL, NULL, NULL, NULL)");
    $insertStmt->bindParam(':address', $address);
    $insertStmt->bindParam(':pubkey', $senderPublicKey);
    $insertStmt->bindParam(':pubkey_hash', $pubkey_hash);

    try {
        if ($insertStmt->execute()) {
            return json_encode(["status" => "accepted", "message" => "Contact request received successfully", "myPublicKey" => $myPublicKey]);
        } else {
            return json_encode(["status" => "rejected", "message" => "Failed to add contact to database"]);
        }
    } catch (PDOException $e) {
        error_log("Error adding contact: " . $e->getMessage());
        return json_encode(["status" => "error", "message" => "Failed to add contact due to a database error"]);
    }
}

function calculateTotalSent($publicKey) {
    global $pdo;
    try {
        $publicKeyHash = hash('sha256', $publicKey);
        $receivedStmt = $pdo->prepare("SELECT SUM(amount) as total_sent 
            FROM transactions 
            WHERE receiver_public_key_hash = :publicKeyHash");
        $receivedStmt->bindParam(':publicKeyHash', $publicKeyHash);
        $receivedStmt->execute();
        $result = $receivedStmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_sent'] ?: 0;
    } catch (PDOException $e) {
        error_log("Error calculating total received: " . $e->getMessage());
        return 0;
    }
}

function calculateTotalReceived($publicKey) {
    global $pdo;
    try {
        $publicKeyHash = hash('sha256', $publicKey);
        $balanceStmt = $pdo->prepare("SELECT SUM(amount) as total_received 
            FROM transactions 
            WHERE sender_public_key_hash = :publicKeyHash");  
        $balanceStmt->bindParam(':publicKeyHash', $publicKeyHash);
        $balanceStmt->execute();
        $result = $balanceStmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_received'] ?: 0;
    } catch (PDOException $e) {
        error_log("Error calculating total sent: " . $e->getMessage());
        return 0;
    }
}

function checkAcceptedContact($address, $name) {
    global $pdo;
    // Check if contact is already an accepted contact in the database
    $checkQuery = "SELECT * FROM contacts WHERE (address = :address OR name = :name) AND name IS NOT NULL";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->bindParam(':address', $address);
    $stmt->bindParam(':name', $name);
    $stmt->execute();
    
    return $stmt->rowCount() > 0;
}

function checkContactExists($address) {
    global $pdo;
    $checkStmt = $pdo->prepare("SELECT * FROM contacts WHERE address = :address");
    $checkStmt->bindParam(':address', $address);
    $checkStmt->execute();
    
    return $checkStmt->rowCount() > 0;
}

function checkPendingContact($address) {
    global $pdo;
    // Check if contact already exists in the database with a pending request
    $checkQuery = "SELECT * FROM contacts WHERE address = :address AND name IS NULL";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->bindParam(':address', $address);
    $stmt->execute();
    
    return $stmt->rowCount() > 0;
}

function checkPendingContactRequests() {
    global $pdo;
    try {
        // Get all contacts with null default fee
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE name IS NULL");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pending_count = count($results);
        
        // If there are pending contacts without a default fee, provide guidance
        if ($pending_count > 0) {
            echo "\n\nYou have {$pending_count} contact request(s) pending acceptance.\n";
            foreach ($results as $contact) {
                echo "Pending contact request from: " . $contact['address'] . "\n";
                echo "To accept this contact request, use the command:\n";
                echo "eiou add " . $contact['address'] . " [name] [fee percent] [credit] [currency]\n";
                echo "Example: eiou add " . $contact['address'] . " Bob 0.1 100 USD\n\n";
            }
        }
    } catch (PDOException $e) {
        error_log("Error checking pending contact requests: " . $e->getMessage());
    }
}

function getP2pByHash($hash){
    global $pdo;
    try {
        $p2pStmt = $pdo->prepare("SELECT * FROM p2p WHERE hash = :hash");
        $p2pStmt->bindParam(':hash', $hash);
        $p2pStmt->execute();
        return $p2pStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error retrieving p2p request by hash: " . $e->getMessage());
        return false;
    }
}


function checkExistence($request, $echo = true){
    global $pdo;
    $type = $request['type'];
    $receiver = resolveUserAddressForTransport($request['senderAddress']);
    try {
        if($type == 'send'){
            $Stmt = $pdo->prepare("SELECT * FROM transactions WHERE memo = :hash");
            $hash = $request['memo'];
        } else{           
            $Stmt = $pdo->prepare("SELECT * FROM $type WHERE hash = :hash");
            $hash = $request['hash'];
        }  
        $Stmt->bindParam(':hash', $hash);
        $Stmt->execute();
        $results = $Stmt->fetch(PDO::FETCH_ASSOC);
        if(!$results){
            if($echo){
                if($type == 'send'){
                    echo json_encode(["status" => "accepted", "txid" => $request['txid'], "message" => "hash/memo " .  print_r($hash,true) . " for transaction received by " .  print_r($receiver,true)]);            
                } else{
                    echo json_encode(["status" => "received", "message" => "hash/memo " .  print_r($hash,true) . " for " .  print_r($type,true) ." received by " .  print_r($receiver,true)]);
                }
            }    
            return false;           
        } else{
            if($echo){
                echo json_encode(["status" => "rejected", "message" => "hash/memo " . print_r($hash,true) . " for " .  print_r($type,true) ." already exists in database of " .  print_r($receiver,true)]);
            }
            return true;
        }
    } catch (PDOException $e) {
        error_log("Error retrieving existence of " .  print_r($type,true) .  " by hash/memo" . $e->getMessage());
        if($echo){
            echo json_encode(["status" => "rejected", "message" => "Could not access database of " .  print_r($receiver,true) . ", error: "  . $e->getMessage()]);
        }
        return true;
    }
}

function checkRP2pExists($hash) {
    global $pdo;
    try {
        $rP2pCheckStmt = $pdo->prepare("SELECT * FROM rp2p WHERE hash = :hash");
        $rP2pCheckStmt->bindParam(':hash', $hash);
        $rP2pCheckStmt->execute();
        return $rP2pCheckStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error checking rp2p request by hash: " . $e->getMessage());
        return false;
    }
}

function checkTransactionExists($memo) {
    global $pdo;
    try {
        $TransactionCheckStmt = $pdo->prepare("SELECT * FROM transactions WHERE memo = :memo");
        $TransactionCheckStmt->bindParam(':memo', $memo);
        $TransactionCheckStmt->execute();
        return $TransactionCheckStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error checking rp2p request by hash: " . $e->getMessage());
        return false;
    }
}
function deleteContact($data) {
    global $pdo;
    $address = $data[2];
    $query = "DELETE FROM contacts WHERE address = :address";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':address', $address);
    
    if ($stmt->execute() && $stmt->rowCount() > 0) {
        echo "Contact deleted successfully.\n";
        return true;
    } else {
        echo "Contact not found, no action taken.\n";
        return false;
    }
}

function freshInstall(){
    // Check if the configuration file exists
    if (!file_exists('/etc/eiou/config.php')) {
        // Create the directory if it doesn't exist
        if (!file_exists('/etc/eiou')) {
            mkdir('/etc/eiou', 0755, true);
        }
        
        // Create a default configuration file
        $defaultConfig = "<?php\n";
        $defaultConfig .= "\$user['defaultFee'] = 1; // Default transaction fee in percent\n";
        $defaultConfig .= "\$user['defaultCurrency'] = 'USD'; // Default currency\n";
        $defaultConfig .= "\$user['localhostOnly'] = true; // Network connection limited to localhost only\n";
        $defaultConfig .= "\$user['maxFee'] = 5; // Maximum total fee for a transaction in percent\n";
        $defaultConfig .= "\$user['maxP2pLevel'] = 6; // Default maximum level for Peer of Peer propagation\n";
        $defaultConfig .= "\$user['p2pExpiration'] = 300; // Default expiration time for Peer of Peer requests in seconds\n";
        $defaultConfig .= "\$user['debug'] = true; // Enable debug mode\n";

        // Create MySQL user, database, and tables
        $dbHost = 'localhost';
        $dbRootUser = 'root';
        $dbRootPass = ''; // You may want to prompt for this or use a secure method

        // Connect as root to create database and user
        try {
            $rootConn = new PDO("mysql:host=$dbHost;unix_socket=/var/run/mysqld/mysqld.sock", $dbRootUser, $dbRootPass);         $rootConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Generate random database and username
            $dbName = 'eiou';
            $dbUser = 'eiou_user_' . bin2hex(random_bytes(8));
            $dbPass = bin2hex(random_bytes(16));

            // Create database
            $rootConn->exec("CREATE DATABASE `$dbName`");

            // Create user with limited privileges
            $rootConn->exec("CREATE USER '$dbUser'@'$dbHost' IDENTIFIED BY '$dbPass'");
            $rootConn->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO '$dbUser'@'$dbHost'");
            $rootConn->exec("FLUSH PRIVILEGES");

            // Connect to new database and create tables
            $dbConn = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
            $dbConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $dbConn->exec(getDebugTableSchema());
            $dbConn->exec(getContactsTableSchema());
            $dbConn->exec(getTransactionsTableSchema());
            $dbConn->exec(getP2pTableSchema());
            $dbConn->exec(getRP2pTableSchema());

            // Append database configuration to the config file
            $defaultConfig .= "\$user['dbHost'] = '$dbHost';\n";
            $defaultConfig .= "\$user['dbName'] = '$dbName';\n";
            $defaultConfig .= "\$user['dbUser'] = '$dbUser';\n";
            $defaultConfig .= "\$user['dbPass'] = '$dbPass';\n";

        } catch (PDOException $e) {
            error_log("Database setup error: " . $e->getMessage());
            echo "An error occurred during database setup. Please check the error log for details.\n";
            echo "Database setup error: " . $e->getMessage();
            exit(1);
        }

        
        // Write the default configuration
        file_put_contents('/etc/eiou/config.php', $defaultConfig, LOCK_EX);
        // Retrieve the Tor hidden service hostname
        $torAddress = trim(file_get_contents('/var/lib/tor/hidden_service/hostname'));        
        // Append the Tor address to the config file
        file_put_contents('/etc/eiou/config.php', "\n" . '$user["torAddress"]="' . addslashes($torAddress) . '";' . "\n", FILE_APPEND | LOCK_EX);
        
        //echo "Created default configuration file at /etc/eiou/\n";
    }
}

function getCreditLimit($senderPublicKey) {
    global $pdo;
    // Check credit limit for the sender by public key hash
    $pubkey_hash = hash('sha256', $senderPublicKey);
    $creditStmt = $pdo->prepare("SELECT credit_limit FROM contacts WHERE pubkey_hash = :sender_pubkey_hash");
    $creditStmt->bindParam(':sender_pubkey_hash', $pubkey_hash);
    $creditStmt->execute();
    $creditResult = $creditStmt->fetch(PDO::FETCH_ASSOC);
    
    return $creditResult['credit_limit'] ?? 0;
}

function getPreviousTxid($senderPublicKey, $receiverPublicKey) {
    global $pdo;
    // Check if there's a previous transaction with the same sender and receiver public key hashes
    $senderPublicKeyHash = hash('sha256', $senderPublicKey);
    $receiverPublicKeyHash = hash('sha256', $receiverPublicKey);
    
    $prevTxStmt = $pdo->prepare("SELECT txid FROM transactions WHERE sender_public_key_hash = :sender_public_key_hash AND receiver_public_key_hash = :receiver_public_key_hash ORDER BY timestamp DESC LIMIT 1");
    $prevTxStmt->bindParam(':sender_public_key_hash', $senderPublicKeyHash);
    $prevTxStmt->bindParam(':receiver_public_key_hash', $receiverPublicKeyHash);
    $prevTxStmt->execute();
    $result = $prevTxStmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['txid'] : null;
}
function getTransactionByMemo($memo){
    global $pdo;
    $getTxStmt = $pdo->prepare("SELECT * FROM transactions WHERE memo = :memo");
    $getTxStmt->bindParam(':memo', $memo);
    return $getTxStmt->fetch(PDO::FETCH_ASSOC);
}


function insertContact($address, $contactPublicKey, $name, $fee, $credit, $currency) {
    global $pdo;
    $pubkey_hash = hash('sha256', $contactPublicKey);
    
    $insertStmt = $pdo->prepare("INSERT INTO contacts (address, pubkey, pubkey_hash, name, fee_percent, credit_limit, currency) VALUES (:address, :pubkey, :pubkey_hash, :name, :fee, :credit, :currency)");
    $insertStmt->bindParam(':address', $address);
    $insertStmt->bindParam(':pubkey', $contactPublicKey);
    $insertStmt->bindParam(':pubkey_hash', $pubkey_hash);
    $insertStmt->bindParam(':name', $name);
    $insertStmt->bindParam(':fee', $fee);
    $insertStmt->bindParam(':credit', $credit);
    $insertStmt->bindParam(':currency', $currency);
    try {
        if ($insertStmt->execute()) {
            return true;
        } else {
            return false;
        }
    } catch (PDOException $e) {
        error_log("Error adding contact: " . $e->getMessage());
        return false;
    }
}

function insertDebug($data) {
    global $pdo;
    
    // If PDO connection is not established, use error_log as fallback
    if (!$pdo) {
        $errorMessage = "Debug: " . ($data['message'] ?? 'No message');
        $errorContext = $data['context'] ? " Context: " . json_encode($data['context']) : '';
        $errorLocation = $data['file'] ? " File: {$data['file']} Line: {$data['line']}" : '';
        
        error_log($errorMessage . $errorContext . $errorLocation);
        return;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO debug (level, message, context, file, line, trace) VALUES (:level, :message, :context, :file, :line, :trace)");
        $stmt->bindValue(':level', $data['level'] ?? 'INFO');
        $stmt->bindValue(':message', $data['message'] ?? '');
        $stmt->bindValue(':context', json_encode($data['context'] ?? null), PDO::PARAM_STR);
        $stmt->bindValue(':file', $data['file'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':line', $data['line'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':trace', $data['trace'] ?? null, PDO::PARAM_STR);
        $stmt->execute();
    } catch (PDOException $e) {
        // Fallback error logging if debug table insertion fails
        error_log("Debug logging failed: " . $e->getMessage());
        
        // Also log the original debug message
        $originalMessage = "Original Debug - Level: " . ($data['level'] ?? 'INFO') 
            . ", Message: " . ($data['message'] ?? '')
            . ", File: " . ($data['file'] ?? 'Unknown')
            . ", Line: " . ($data['line'] ?? 'Unknown');
        error_log($originalMessage);
    }
}

function insertP2pRequest($request, $destinationAddress = null) {
    global $pdo;
    // Output and potentially trim the destination address
    //output("Destination Address: " . ($destinationAddress ?? 'None') . "\n");
    try {
        $stmt = $pdo->prepare("INSERT INTO p2p (
            hash, 
            salt, 
            time, 
            expiration,
            currency, 
            amount, 
            my_fee_amount,
            request_level, 
            max_request_level, 
            sender_public_key, 
            sender_address,
            sender_signature,
            destination_address,
            incoming_txid,
            outgoing_txid,
            status
        ) VALUES (
            :hash, 
            :salt, 
            :time, 
            :expiration,
            :currency, 
            :amount, 
            :my_fee_amount,
            :request_level, 
            :max_request_level, 
            :sender_public_key, 
            :sender_address,
            :sender_signature,
            :destination_address,
            :incoming_txid,
            :outgoing_txid,
            :status
        )");

        $my_fee_amount = $request['feeAmount'] ?? null;
        $status = $request['status'] ?? 'initial';
        $stmt->bindParam(':hash', $request['hash']);
        $stmt->bindParam(':salt', $request['salt']);
        $stmt->bindParam(':time', $request['time']);
        $stmt->bindParam(':expiration', $request['expiration']);
        $stmt->bindParam(':currency', $request['currency']);
        $stmt->bindParam(':amount', $request['amount']);
        $stmt->bindParam(':my_fee_amount', $my_fee_amount);
        $stmt->bindParam(':request_level', $request['requestLevel']);
        $stmt->bindParam(':max_request_level', $request['maxRequestLevel']);
        $stmt->bindParam(':sender_public_key', $request['senderPublicKey']);
        $stmt->bindParam(':sender_address', $request['senderAddress']);
        $stmt->bindParam(':sender_signature', $request['signature']);
        $stmt->bindParam(':destination_address', $destinationAddress);
        $stmt->bindParam(':incoming_txid', $request['incoming_txid']);
        $stmt->bindParam(':outgoing_txid', $request['outgoing_txid']);
        $stmt->bindParam(':status', $status);
        $stmt->execute();
        output("Iserted P2P with hash: " .print_r($request['hash'],true),'SILENT');
        return json_encode(["status" => "received", "message" => "p2p sent & received successfully"]);
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error inserting p2p request: " . $e->getMessage());
        //echo "Failed to insert p2p request: " . $e->getMessage() . "\n";
        return json_encode(["status" => "rejected", "message" => "Failed to have p2p be received: " . $e->getMessage()]);
    }
}

function insertRp2pRequest ($request){
    global $pdo;
    // if(!checkExistence($request,false)){

    // }
    try {
        $stmt = $pdo->prepare("INSERT INTO rp2p (
            hash, 
            time, 
            amount, 
            currency, 
            sender_public_key, 
            sender_address,
            sender_signature
        ) VALUES (
            :hash, 
            :time, 
            :amount, 
            :currency, 
            :sender_public_key, 
            :sender_address,
            :sender_signature
        )");

        $stmt->bindParam(':hash', $request['hash']);
        $stmt->bindParam(':time', $request['time']);
        $stmt->bindParam(':amount', $request['amount']);
        $stmt->bindParam(':currency', $request['currency']);
        $stmt->bindParam(':sender_public_key', $request['senderPublicKey']);
        $stmt->bindParam(':sender_address', $request['senderAddress']);
        $stmt->bindParam(':sender_signature', $request['signature']);
        $stmt->execute();
        //echo "Successfully inserted rp2p request.\n";
        output("Iserted RP2P with hash: " .print_r($request['hash'],true),'SILENT');
        return json_encode(["status" => "received", "message" => "rp2p sent & received successfully"]);
    } catch (PDOException $e) {
        // Handle database error
        output("Error inserting rp2p request: " . print_r($e->getMessage(), true));
        return json_encode(["status" => "rejected", "message" => "Failed to have rp2p be received: " . $e->getMessage()]);
    }

}

function insertTransaction($request) {
    //wait a little to prevent tight access, removes issue duplicate previousTxid
    usleep(500000);
    global $pdo;
    // Calculate public key hashes
    $senderPublicKeyHash = hash('sha256', $request['senderPublicKey']);
    $receiverPublicKeyHash = hash('sha256', $request['receiverPublicKey']);

    $request['txType'] = ($request['memo'] === 'standard') ? 'standard' : 'p2p';

    $insertStmt = $pdo->prepare("INSERT INTO transactions (
        tx_type,
        sender_address, 
        sender_public_key,
        sender_public_key_hash,
        receiver_address, 
        receiver_public_key,
        receiver_public_key_hash,
        amount, 
        currency, 
        txid,
        previous_txid,
        sender_signature,
        memo
    ) VALUES (
        :tx_type,
        :sender_address, 
        :sender_public_key,
        :sender_public_key_hash,
        :receiver_address, 
        :receiver_public_key,
        :receiver_public_key_hash,
        :amount, 
        :currency, 
        :txid,
        :previous_txid,
        :sender_signature,
        :memo
    )");

    // Bind parameters
    $insertStmt->bindParam(':tx_type', $request['txType']);
    $insertStmt->bindParam(':sender_address', $request['senderAddress']);
    $insertStmt->bindParam(':sender_public_key', $request['senderPublicKey']);
    $insertStmt->bindParam(':sender_public_key_hash', $senderPublicKeyHash);
    $insertStmt->bindParam(':receiver_address', $request['receiverAddress']);
    $insertStmt->bindParam(':receiver_public_key', $request['receiverPublicKey']);
    $insertStmt->bindParam(':receiver_public_key_hash', $receiverPublicKeyHash);
    $insertStmt->bindParam(':amount', $request['amount']);
    $insertStmt->bindParam(':currency', $request['currency']);
    $insertStmt->bindParam(':txid', $request['txid']);
    $insertStmt->bindParam(':previous_txid', $request['previousTxid']);
    $insertStmt->bindParam(':sender_signature', $request['signature']);
    $insertStmt->bindParam(':memo', $request['memo']);

    try {
        // Execute the insert
        $insertStmt->execute();
        // Respond with accepted status
        output("Iserted Transaction with memo: " .print_r($request['memo'],true),'SILENT');
        return json_encode(["status" => "accepted", "message" => "Transaction recorded successfully","txid" => $request['txid']]);
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error inserting transaction: " . $e->getMessage());
        return json_encode(["status" => "rejected", "message" => "Failed to record transaction: " . $e->getMessage()
        ]);
    }
}

function lookup($name) {
    global $pdo;
    $nameStmt = $pdo->prepare("SELECT address FROM contacts WHERE LOWER(name) = LOWER(:name)");
    $nameStmt->bindParam(':name', $name);
    $nameStmt->execute();
    $result = $nameStmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result : null;
}

function lookupContactByName($receiverInput) {
    global $pdo;
    $nameStmt = $pdo->prepare("SELECT name, address, pubkey FROM contacts WHERE LOWER(name) = LOWER(:name)");
    $nameStmt->bindParam(':name', $receiverInput);
    $nameStmt->execute();
    $result = $nameStmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result : null;
}

function lookupContactByAddress($address) {
    global $pdo;
    $addressStmt = $pdo->prepare("SELECT name, address, pubkey, fee_percent FROM contacts WHERE address = :address");
    $addressStmt->bindParam(':address', $address);
    $addressStmt->execute();
    $result = $addressStmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result : null;
}

function lookupP2pRequest($hash) {
    global $pdo;
    $p2pRequest = $pdo->prepare("SELECT * FROM p2p WHERE hash = :hash");
    $p2pRequest->bindParam(':hash', $hash);
    $p2pRequest->execute();
    return $p2pRequest->fetch(PDO::FETCH_ASSOC);
}

function readContactQuery($address) {
    global $pdo;
    $query = "SELECT * FROM contacts WHERE address = :address";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':address', $address, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function retrieveContactAddresses($exclude = null) {
    global $pdo;
    if ($exclude) {
        $contactsStmt = $pdo->prepare("SELECT address FROM contacts WHERE address != :exclude");
        $contactsStmt->bindParam(':exclude', $exclude);
    } else {
        $contactsStmt = $pdo->prepare("SELECT address FROM contacts");
    }
    $contactsStmt->execute();
    return $contactsStmt->fetchAll(PDO::FETCH_COLUMN);
}

function retrieveContacts() {
    global $pdo;
    $contactsStmt = $pdo->prepare("SELECT address, pubkey FROM contacts");
    $contactsStmt->execute();
    return $contactsStmt->fetchAll(PDO::FETCH_ASSOC);
}

function retrieveQueuedP2pMessages($status = 'queued') {
    global $pdo;
    $queuedStmt = $pdo->prepare("SELECT * FROM p2p WHERE status = :status ORDER BY created_at ASC LIMIT 5");
    $queuedStmt->bindParam(':status', $status);
    $queuedStmt->execute();
    $queuedMessages = $queuedStmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($queuedMessages) . " queued messages to process\n";

    return $queuedMessages;
}

function searchContactsQuery($name = null) {
    global $pdo;
    $query = "SELECT address, name, fee_percent, credit_limit, currency FROM contacts";
    if($name !== null){
        $query .= " WHERE LOWER(name) LIKE LOWER(:name)";
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':name', '%' . $name . '%', PDO::PARAM_STR);
    } else {
        $stmt = $pdo->prepare($query);
    }
    
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function updateContact($data) {
    global $pdo;
    $address = $data[2];
    $field = strtolower($data[3]);
    $value = $data[4];
    $value2 = isset($data[5]) ? $data[5] : null;
    $value3 = isset($data[6]) ? $data[6] : null;
    $query = "UPDATE contacts SET ";
    
    $params = [];
    
    if($field == 'name'){
        $query .= "name = :name";
        $params[':name'] = $value;
    }
    elseif($field == 'fee'){
        $query .= "fee_percent = :fee";
        $params[':fee'] = $value * 100; // Convert percentage
    }
    elseif($field == 'credit'){
        $query .= "credit_limit = :credit, currency = :currency";
        $params[':credit'] = $value * 100; // Convert to cents
        $params[':currency'] = 'USD';
    }
    elseif($field == 'all'){
        $query .= "name = :name, fee_percent = :fee, credit_limit = :credit, currency = :currency";
        $params[':name'] = $value;
        $params[':fee'] = $value2 * 100; // Convert percentage
        $params[':credit'] = $value3 * 100; // Convert to cents
        $params[':currency'] = 'USD';
    }
    else{
        output(returnContactUpdateInvalidInput());
    }
    
    $query .= " WHERE address = :address";
    $params[':address'] = $address;
    
    $stmt = $pdo->prepare($query);
    if ($stmt->execute($params)) {
        output(returnContactUpdate());
    }
    else{
        output(returnContactNotFound());
    }
}

function updateP2pRequestStatus($hash, $status, $completed = false) {
    global $pdo;
    try {
        if($completed){
            $updateStmt = $pdo->prepare("UPDATE p2p SET status = :status, completed_at = CURRENT_TIMESTAMP WHERE hash = :hash");
        } else {
            $updateStmt = $pdo->prepare("UPDATE p2p SET status = :status WHERE hash = :hash");
        }       
        $updateStmt->bindParam(':hash', $hash);
        $updateStmt->bindParam(':status', $status);
        $updateStmt->execute();
        output("Updated status to '" . $status . "' for message hash: " . $hash,'SILENT');
    } catch (PDOException $e) {
        // Log or handle the error if updating status fails
        error_log("Error updating p2p request status: " . $e->getMessage());
    }
}

function viewTransactionHistory($data) {
    global $pdo, $user;
    $query = "SELECT sender_address, receiver_address, amount, currency, timestamp FROM transactions";
    $address = null;

    // Check if an address or name is provided
    if (isset($data[2])) {
        // First check if it's an HTTP address
        if (isHttpAddress($data[2])) {
            $address = $data[2];
        } 
        // Then check if it's a Tor address
        elseif (isTorAddress($data[2])) {
            $address = $data[2];
        }
        // If not, try to look up by name
        else {
            $contactResult = lookupContactByName($data[2]);
            $address = $contactResult ? $contactResult['address'] : $data[2];
        }

        // Add WHERE clause if a valid address is found
        if ($address) {
            $query .= " WHERE sender_address = :address OR receiver_address = :address";
        }
    }

    $stmt = $pdo->prepare($query);
    
    if ($address) {
        $stmt->bindParam(':address', $address);
    }
    
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($results) {
        echo "Transaction History:\n";
        echo "-------------------------------------------\n";
        echo str_pad("Sender name (Address)", 56, ' ') . " | " . 
             str_pad("Receiver name (Address)", 56, ' ') . " | " . 
             str_pad("Amount", 10, ' ') . " | " . 
             str_pad("Currency", 10, ' ') . " | " . 
             "Timestamp\n";
        echo "-------------------------------------------\n";
        
        foreach ($results as $transaction) {
            // Lookup sender name
            $senderResult = lookupContactByAddress($transaction['sender_address']);
            $senderName = $senderResult ? $senderResult['name'] : $transaction['sender_address'];
            
            // Lookup receiver name
            $receiverResult = lookupContactByAddress($transaction['receiver_address']);
            $receiverName = $receiverResult ? $receiverResult['name'] : $transaction['receiver_address'];
            
            // Replace name with 'me' if the address is mine
            $senderName = isMe($transaction['sender_address']) ? 'me' : $senderName;
            $receiverName = isMe($transaction['receiver_address']) ? 'me' : $receiverName;
            
            echo str_pad($senderName . " (" . $transaction['sender_address'] . ")", 56, ' ') . " | " . 
                 str_pad($receiverName . " (" . $transaction['receiver_address'] . ")", 56, ' ') . " | " . 
                 str_pad(number_format($transaction['amount'] / 100, 2), 10, ' ') . " | " . 
                 str_pad($transaction['currency'], 10, ' ') . " | " . 
                 $transaction['timestamp'] . "\n";
        }
        
        echo "-------------------------------------------\n";
        echo "Total transactions: " . count($results) . "\n";
    } else {
        echo "No transaction history found.\n";
    }
}