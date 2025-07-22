<?php
# Copyright 2025

function acceptContact($address, $name, $fee, $credit, $currency) {
    global $pdo;
    // Accept Contact request
    $insertStmt = $pdo->prepare("UPDATE contacts SET name = :name, status = 'accepted', fee_percent = :fee, credit_limit = :credit, currency = :currency WHERE address = :address");
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
        // Handle database error
        error_log("Error updating contact: " . $e->getMessage());
        return false;
    }
}

function addPendingContact($address, $senderPublicKey) {
    global $pdo, $user;
    // Add Contact that was pending
    $myPublicKey = $user['public'];
    $pubkey_hash = hash('sha256', $senderPublicKey);

    $insertStmt = $pdo->prepare("INSERT INTO contacts (address, pubkey, pubkey_hash, name, status, fee_percent, credit_limit, currency) VALUES (:address, :pubkey, :pubkey_hash, NULL, 'pending', NULL, NULL, NULL)");
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
        // Handle database error
        error_log("Error adding contact: " . $e->getMessage());
        return json_encode(["status" => "error", "message" => "Failed to add contact due to a database error"]);
    }
}

function blockContact($data) {
    global $pdo;
    // Block a contact
    $address = $data[2];
    $query = "UPDATE contacts SET status = 'blocked' WHERE address = :address";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':address', $address);

    if ($stmt->execute() && $stmt->rowCount() > 0) {
        echo "Contact blocked successfully.\n";
        return true;
    } else {
        echo "Contact not found, no action taken.\n";
        return false;
    }
}

function calculateTotalSent($publicKey) {
    global $pdo;
    // Calculate total amount sent through transcations to publickey 
    try {
        $publicKeyHash = hash('sha256', $publicKey);
        $receivedStmt = $pdo->prepare("SELECT SUM(amount) as total_sent FROM transactions WHERE receiver_public_key_hash = :publicKeyHash");
        $receivedStmt->bindParam(':publicKeyHash', $publicKeyHash);
        $receivedStmt->execute();
        $result = $receivedStmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_sent'] ?: 0;
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error calculating total received: " . $e->getMessage());
        return 0;
    }
}

function calculateTotalSentUser() {
    global $pdo, $user;
    // Calculate total amount received through transactions based on public key hash
    try {
        $publicKeyHash = hash('sha256', $user['public']);
        $balanceStmt = $pdo->prepare("SELECT SUM(amount) as total_sent FROM transactions WHERE sender_public_key_hash = :publicKeyHash");  
        $balanceStmt->bindParam(':publicKeyHash', $publicKeyHash);
        $balanceStmt->execute();
        $result = $balanceStmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_sent'] ?: 0;
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error calculating total sent: " . $e->getMessage());
        return 0;
    }
}

function calculateTotalReceived($publicKey) {
    global $pdo;
    // Calculate total amount received through transactions based on public key hash
    try {
        $publicKeyHash = hash('sha256', $publicKey);
        $balanceStmt = $pdo->prepare("SELECT SUM(amount) as total_received FROM transactions WHERE sender_public_key_hash = :publicKeyHash");  
        $balanceStmt->bindParam(':publicKeyHash', $publicKeyHash);
        $balanceStmt->execute();
        $result = $balanceStmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_received'] ?: 0;
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error calculating total sent: " . $e->getMessage());
        return 0;
    }
}

function calculateTotalReceivedUser() {
    global $pdo, $user;
    // Calculate total amount received through transactions based on public key hash
    try {
        $publicKeyHash = hash('sha256', $user['public']);
        $balanceStmt = $pdo->prepare("SELECT SUM(amount) as total_received FROM transactions WHERE sender_public_key_hash != :publicKeyHash");  
        $balanceStmt->bindParam(':publicKeyHash', $publicKeyHash);
        $balanceStmt->execute();
        $result = $balanceStmt->fetch(PDO::FETCH_ASSOC);
        return $result['total_received'] ?: 0;
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error calculating total sent: " . $e->getMessage());
        return 0;
    }
}

function checkAcceptedContact($address, $name) {
    global $pdo;
    // Check if contact is already an accepted contact in the database
    $checkQuery = "SELECT * FROM contacts WHERE (address = :address OR name = :name) AND status = 'accepted'";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->bindParam(':address', $address);
    $stmt->bindParam(':name', $name);
    $stmt->execute();

    return $stmt->rowCount() > 0;
}

function checkContactExists($address) {
    global $pdo;
    // Check if contact exists in database
    $checkStmt = $pdo->prepare("SELECT * FROM contacts WHERE address = :address");
    $checkStmt->bindParam(':address', $address);
    $checkStmt->execute();
    
    return $checkStmt->rowCount() > 0;
}

function checkContactBlockedStatus($request){
    global $pdo;
    // Check if contact has no bad status i.e. blocked
    $checkStmt = $pdo->prepare("SELECT * FROM contacts WHERE address = :address AND status = 'blocked'");
    $checkStmt->bindParam(':address', $request['senderAddress']);
    $checkStmt->execute();
    if($checkStmt->rowCount() > 0){
        return false;
    } else{
        return true;
    }
}

function checkContactStatus($request){
    global $pdo;
    // Check if contact has no bad status i.e. blocked
    $checkStmt = $pdo->prepare("SELECT * FROM contacts WHERE address = :address AND status != 'accepted'");
    $checkStmt->bindParam(':address', $request['senderAddress']);
    $checkStmt->execute();
    if($checkStmt->rowCount() > 0){
        return true;
    } else{
        return false;
    }
}



function checkPendingContact($address) {
    global $pdo;
    // Check if contact already exists in the database but is not yet accepted
    $checkQuery = "SELECT * FROM contacts WHERE address = :address AND name IS NULL AND status = 'pending'";
    $stmt = $pdo->prepare($checkQuery);
    $stmt->bindParam(':address', $address);
    $stmt->execute();
    
    return $stmt->rowCount() > 0;
}

function checkPendingContactRequests() {
    global $pdo;
    try {
        // Get all contacts with null default fee
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE name IS NULL AND status = 'pending'");
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
        // Handle database error
        error_log("Error checking pending contact requests: " . $e->getMessage());
    }
}

function checkCompletionP2pByHash($hash){
    // Check if P2P was already completed
    $results = getP2pByHash($hash);
    if($results['status'] === 'completed'){
        return true;
    } 
    return false;
}

function checkCompletionTransactionByMemo($memo){
    // Check if transaction was already completed
    $results = getTransactionByMemo($memo);
    if($results['status'] === 'completed'){
        return true;
    } 
    return false;
}

function checkCompletionTransactionByTxid($txid){
     // Check if transaction was already completed
    $results =  getTransactionByTxid($txid);
    if($results['status'] === 'completed'){
        return true;
    } 
    return false;
}

function checkExistenceP2p($request, $echo = true){
    // Check if P2P already exists for hash in database, is valid and can be completed
    // Check if P2P is valid and can be completed given credit of user requesting
    if(!checkContactBlockedStatus($request) || !checkRequestLevel($request) || !checkAvailableFunds($request)){
        return true; 
    }
    // Check if P2P already exists for hash in database
    try{
        $results = getP2pByHash($request['hash']);
        if(!$results){
            if($echo){
                echo buildP2pAcceptancePayload($request);
            }
            return false;  
        } else{
            if($echo){
                echo buildP2pRejectionPayload($request);
            }
            return true;
        }
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error retrieving existence of P2P by hash" . $e->getMessage());
        if($echo){
            echo json_encode([
                "status" => "rejected",
                "message" => "Could not retrieve existence of P2P with receiver"
            ]);
        }
        return true;
    }
}

function checkExistenceRp2p($request, $echo = true){
    // Check if RP2P already exists for hash in database
    global $pdo;
    try{
        $Stmt = $pdo->prepare("SELECT * FROM rp2p WHERE hash = :hash");
        $hash = $request['hash'];
        $Stmt->bindParam(':hash', $hash);
        $Stmt->execute();
        $results = $Stmt->fetch(PDO::FETCH_ASSOC);
        if(!$results){
            if($echo){
                echo buildRp2pAcceptancePayload($request);
            }
            return false;  
        } else{
            if($echo){
                echo buildRp2pRejectionPayload($request);
            }
            return true;
        }
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error retrieving existence of RP2P by hash" . $e->getMessage());
        if($echo){
            echo json_encode([
                "status" => "rejected",
                "message" => "Could not retrieve existence of RP2P with receiver"
            ]);
        }
        return true;
    }
    
}

function checkExistenceTransaction($request, $echo = true){
    // Check if Transaction already exists for memo in database and is a valid successor of previous txids
    // Check if Transaction is a valid successor of previous txids
    if(!checkContactBlockedStatus($request) || !checkPreviousTxid($request) || !checkAvailableFundsTransaction($request)){
        return true;
    }
    // Check if Transaction already exists for txid or memo in database
    try{
        $memo = $request['memo'];
        if($memo = "standard"){
            // If direct transaction
            $results = getTransactionByTxid($request['txid']);
        } else{
            // If p2p based transaction
            $results = getTransactionByMemo($memo);
        }
        if(!$results){
            if($echo){
                echo buildSendAcceptancePayload($request);            
            }
            return false;  
        } else{
            if($echo){
                echo buildSendRejectionPayload($request);
            }
            return true;
        }
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error retrieving existence of Transaction by memo" . $e->getMessage());
        if($echo){
            echo json_encode([
                "status" => "rejected",
                "message" => "Could not retrieve existence of Transaction with receiver"
            ]);
        }
        return true;
    }
}

function checkRp2pExists($hash) {
    global $pdo;
    // Get rp2p request from database based on hash
    try {
        $rP2pCheckStmt = $pdo->prepare("SELECT * FROM rp2p WHERE hash = :hash");
        $rP2pCheckStmt->bindParam(':hash', $hash);
        $rP2pCheckStmt->execute();
        return $rP2pCheckStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error checking rp2p request by hash: " . $e->getMessage());
        return false;
    }
}

function deleteContact($data) {
    global $pdo;
    // Delete a contact
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
        $defaultConfig .= "\$user['defaultFee'] = 0.1; // Default transaction fee in percent\n";
        $defaultConfig .= "\$user['defaultCurrency'] = 'USD'; // Default currency\n";
        $defaultConfig .= "\$user['localhostOnly'] = true; // Network connection limited to localhost only\n";
        $defaultConfig .= "\$user['maxFee'] = 5; // Maximum total fee for a transaction in percent\n";
        $defaultConfig .= "\$user['maxP2pLevel'] = 6; // Default maximum level for Peer to Peer propagation\n";
        $defaultConfig .= "\$user['p2pExpiration'] = 300; // Default expiration time for Peer to Peer requests in seconds\n";
        $defaultConfig .= "\$user['debug'] = true; // Enable debug mode\n";
        $defaultConfig .= "\$user['maxOutput'] = 5; // Maximum lines of output for multi-line output\n";

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
            $dbConn->exec(getRp2pTableSchema());

            // Append database configuration to the config file
            $defaultConfig .= "\$user['dbHost'] = '$dbHost';\n";
            $defaultConfig .= "\$user['dbName'] = '$dbName';\n";
            $defaultConfig .= "\$user['dbUser'] = '$dbUser';\n";
            $defaultConfig .= "\$user['dbPass'] = '$dbPass';\n";

        } catch (PDOException $e) {
            // Handle database error
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

function getExistingPreviousTxid($previousTxid){
    global $pdo;
    $prevTxStmt = $pdo->prepare("SELECT txid FROM transactions WHERE previous_txid = :previous_txid ORDER BY timestamp DESC LIMIT 1");
    $prevTxStmt->bindParam(':previous_txid', $previousTxid);
    $prevTxStmt->execute();
    $result = $prevTxStmt->fetch(PDO::FETCH_ASSOC);
    return $result ? true : false;
}

function getExistingTxid($txid){
    global $pdo;
    $prevTxStmt = $pdo->prepare("SELECT txid FROM transactions WHERE txid = :txid ORDER BY timestamp DESC LIMIT 1");
    $prevTxStmt->bindParam(':txid', $txid);
    $prevTxStmt->execute();
    $result = $prevTxStmt->fetch(PDO::FETCH_ASSOC);
    return $result ? true : false;
}

function getPreviousTxid($senderPublicKey, $receiverPublicKey) {
    global $pdo;
    // Check if there's a previous transaction with the same sender and receiver public key hashes
    $senderPublicKeyHash = hash('sha256', $senderPublicKey);
    $receiverPublicKeyHash = hash('sha256', $receiverPublicKey);
    
    $prevTxStmt = $pdo->prepare("SELECT txid FROM transactions WHERE (sender_public_key_hash = :sender_public_key_hash AND receiver_public_key_hash = :receiver_public_key_hash) OR (sender_public_key_hash = :receiver_public_key_hash AND receiver_public_key_hash = :sender_public_key_hash) ORDER BY timestamp DESC LIMIT 1");
    $prevTxStmt->bindParam(':sender_public_key_hash', $senderPublicKeyHash);
    $prevTxStmt->bindParam(':receiver_public_key_hash', $receiverPublicKeyHash);
    $prevTxStmt->execute();
    $result = $prevTxStmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['txid'] : null;
}

function getP2pByHash($hash){
    global $pdo;
    // Get p2p from database based on hash
    try {
        $p2pStmt = $pdo->prepare("SELECT * FROM p2p WHERE hash = :hash");
        $p2pStmt->bindParam(':hash', $hash);
        $p2pStmt->execute();
        return $p2pStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error retrieving p2p request by hash: " . $e->getMessage());
        return false;
    }
}

function getTransactionByMemo($memo){
    global $pdo;
    // Get transaction from database based on memo
    try {
        $Stmt = $pdo->prepare("SELECT * FROM transactions WHERE memo = :memo");
        $Stmt->bindParam(':memo', $memo);
        $Stmt->execute();
        return $Stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error retrieving transaction request by memo: " . $e->getMessage());
        return false;
    }
}

function getTransactionByTxid($txid){
    global $pdo;
    // Get transaction from database based on txid
    try {
        $Stmt = $pdo->prepare("SELECT * FROM transactions WHERE txid = :txid");
        $Stmt->bindParam(':txid', $txid);
        $Stmt->execute();
        return $Stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error retrieving transaction request by txid: " . $e->getMessage());
        return false;
    }
}

function insertContact($address, $contactPublicKey, $name, $fee, $credit, $currency) {
    global $pdo;
    // Insert a contact into database (status 'pending' awaiting acceptance from contact in question)
    $pubkey_hash = hash('sha256', $contactPublicKey);
    
    $insertStmt = $pdo->prepare("INSERT INTO contacts (address, pubkey, pubkey_hash, name, status, fee_percent, credit_limit, currency) VALUES (:address, :pubkey, :pubkey_hash, :name, 'pending', :fee, :credit, :currency)");
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
        // Handle database error
        error_log("Error adding contact: " . $e->getMessage());
        return false;
    }
}

function insertDebug($data) {
    global $pdo;
    // Add debug messages to database

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
    // Insert p2p request in database
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

        // Bind parameters
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
        // Execute the insert
        $stmt->execute();
        // Respond with received status
        output(outputInsertedP2p($request),'SILENT');
        return json_encode(["status" => "received", "message" => "p2p recorded successfully"]);
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error inserting p2p request: " . $e->getMessage());
        return json_encode(["status" => "rejected", "message" => "Failed to record p2p: " . $e->getMessage()]);
    }
}

function insertRp2pRequest ($request){
    global $pdo;
    // Insert rp2p request in database
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
        // Bind parameters
        $stmt->bindParam(':hash', $request['hash']);
        $stmt->bindParam(':time', $request['time']);
        $stmt->bindParam(':amount', $request['amount']);
        $stmt->bindParam(':currency', $request['currency']);
        $stmt->bindParam(':sender_public_key', $request['senderPublicKey']);
        $stmt->bindParam(':sender_address', $request['senderAddress']);
        $stmt->bindParam(':sender_signature', $request['signature']);
        // Execute the insert
        $stmt->execute();
        // Respond with received status
        output(outputInsertedRp2p($request),'SILENT');
        return json_encode(["status" => "received", "message" => "rp2p recorded successfully"]);
    } catch (PDOException $e) {
        // Handle database error
        output("Error inserting rp2p request: " . print_r($e->getMessage(), true));
        return json_encode(["status" => "rejected", "message" => "Failed to record rp2p: " . $e->getMessage()]);
    }

}

function insertTransaction($request) {  
    global $pdo;
    // Insert transaction request in database
    usleep(500000); // wait a little to prevent tight access (Sleep for 500ms)
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
        if($request['memo'] != "standard"){
            output(outputInsertedTransactionMemo($request),'SILENT');
        } else{
            output(outputInsertedTransactionTxid($request),'SILENT');
        }
        return json_encode(["status" => "accepted", "message" => "Transaction recorded successfully","txid" => $request['txid']]);
    } catch (PDOException $e) {
        // Handle database error
        error_log("Error inserting transaction: " . $e->getMessage());
        return json_encode(["status" => "rejected", "message" => "Failed to record transaction: " . $e->getMessage()
        ]);
    }
}

function lookupContactByName($name) {
    global $pdo;
    // Lookup general contact information based on name
    $nameStmt = $pdo->prepare("SELECT name, address, pubkey FROM contacts WHERE LOWER(name) = LOWER(:name)");
    $nameStmt->bindParam(':name', $name);
    $nameStmt->execute();
    $result = $nameStmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result : null;
}

function lookupContactByAddress($address) {
    global $pdo;
    // Lookup contact information for messaging based on address
    $addressStmt = $pdo->prepare("SELECT name, address, pubkey, fee_percent FROM contacts WHERE address = :address");
    $addressStmt->bindParam(':address', $address);
    $addressStmt->execute();
    $result = $addressStmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result : null;
}

function lookupContactAddressByName($name){
     global $pdo;
    // Lookup contact address based on name
    $nameStmt = $pdo->prepare("SELECT address FROM contacts WHERE LOWER(name) = LOWER(:name)");
    $nameStmt->bindParam(':name', $name);
    $nameStmt->execute();
    $result = $nameStmt->fetchColumn();
    return $result ? $result : null;
}

function lookupContactNameByAddress($address){
     global $pdo;
    // Lookup contact name based on address
    $nameStmt = $pdo->prepare("SELECT name FROM contacts WHERE address = :address");
    $nameStmt->bindParam(':address', $address);
    $nameStmt->execute();
    $result = $nameStmt->fetchColumn();
    return $result ? $result : null;
}

function lookupP2pRequest($hash) {
    global $pdo;
    // Lookup p2p request based on hash
    $p2pRequest = $pdo->prepare("SELECT * FROM p2p WHERE hash = :hash");
    $p2pRequest->bindParam(':hash', $hash);
    $p2pRequest->execute();
    return $p2pRequest->fetch(PDO::FETCH_ASSOC);
}

function retrieveContactAddresses($exclude = null) {
    global $pdo;
    // Retrieve all contact addresses
    if ($exclude) {
        $contactsStmt = $pdo->prepare("SELECT address FROM contacts WHERE address != :exclude");
        $contactsStmt->bindParam(':exclude', $exclude);
    } else {
        $contactsStmt = $pdo->prepare("SELECT address FROM contacts");
    }
    $contactsStmt->execute();
    return $contactsStmt->fetchAll(PDO::FETCH_COLUMN);
}

function retrieveContactQuery($address) {
    global $pdo;
    // Retrieve all contact information based on address
    $query = "SELECT * FROM contacts WHERE address = :address";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':address', $address, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function retrieveContactPubkey($address){
    global $pdo;
    // Retrieve all contact information based on address
    $query = "SELECT pubkey FROM contacts WHERE address = :address";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':address', $address, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function retrieveContacts() {
    global $pdo;
    // Retrieve all contacts
    $contactsStmt = $pdo->prepare("SELECT address, pubkey FROM contacts");
    $contactsStmt->execute();
    return $contactsStmt->fetchAll(PDO::FETCH_ASSOC);
}

function retrieveQueuedP2pMessages($status = 'queued', $status2 = '') {
    global $pdo;
    // Retrieve all p2p messages that are queued (default) or by specific status(es) 
    if($status2 != ''){
        $queuedStmt = $pdo->prepare("SELECT * FROM p2p WHERE status = :status OR status = :status2 ORDER BY created_at ASC LIMIT 5");
        $queuedStmt->bindParam(':status', $status);
        $queuedStmt->bindParam(':status2', $status2);
    }else{
        $queuedStmt = $pdo->prepare("SELECT * FROM p2p WHERE status = :status ORDER BY created_at ASC LIMIT 5");
        $queuedStmt->bindParam(':status', $status);
    }
    
    $queuedStmt->execute();
    $queuedMessages = $queuedStmt->fetchAll(PDO::FETCH_ASSOC);

    if($status2 === ''){
        echo "Found " . count($queuedMessages) . " " . $status . " messages to process\n";
    } else{
        echo "Found " . count($queuedMessages) . " " . $status . " & " . $status2 . " messages to process\n";
    }
    return $queuedMessages;
}

function retrievePendingTransactionMessages(){
    global $pdo;
    // Retrieve all transaction messages that are pending (default)
    $queuedStmt = $pdo->prepare("SELECT * FROM transactions WHERE status = 'pending' ORDER BY timestamp ASC LIMIT 5");
    $queuedStmt->execute();
    $queuedMessages = $queuedStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($queuedMessages) . " pending transaction messages to process\n";
    return $queuedMessages;
}

function retrieveCreditInP2p($address){
    global $pdo;
    // Calculate how much credit is on hold in current processing p2p
    $P2pStmt = $pdo->prepare("SELECT SUM(amount) as total_amount FROM p2p WHERE sender_address = :address AND status IN ('initial','queued','sent','found')");
    $P2pStmt->bindParam(':address', $address);
    $P2pStmt->execute();
    $result = $P2pStmt->fetch(PDO::FETCH_ASSOC);
    return $result['total_amount'] ?? 0;
}

function searchContactsQuery($name = null) {
    global $pdo;
    // Search for possible contacts based on name, return all (possible) changeable information if found
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

function unblockContact($data) {
    global $pdo;
    // Block a contact
    $address = $data[2];
    $query = "UPDATE contacts SET status = 'accepted' WHERE address = :address";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':address', $address);

    if ($stmt->execute() && $stmt->rowCount() > 0) {
        echo "Contact unblocked successfully.\n";
        return true;
    } else {
        echo "Contact not found, no action taken.\n";
        return false;
    }
}

function updateContact($data) {
    global $pdo;
    // Update contact information
    $address = $data[2];
    $field = strtolower($data[3]);
    $value = $data[4];
    $value2 = isset($data[5]) ? $data[5] : null;
    $value3 = isset($data[6]) ? $data[6] : null;
    $query = "UPDATE contacts SET ";
    
    $params = [];
    
    // Depending on supplied argument update specific (or all) items
    if($field === 'name'){
        $query .= "name = :name";
        $params[':name'] = $value;
    }
    elseif($field === 'fee'){
        $query .= "fee_percent = :fee";
        $params[':fee'] = $value * 100; // Convert percentage
    }
    elseif($field === 'credit'){
        $query .= "credit_limit = :credit, currency = :currency";
        $params[':credit'] = $value * 100; // Convert to cents
        $params[':currency'] = 'USD';
    }
    elseif($field === 'all'){
        $query .= "name = :name, fee_percent = :fee, credit_limit = :credit, currency = :currency";
        $params[':name'] = $value;
        $params[':fee'] = $value2 * 100; // Convert percentage
        $params[':credit'] = $value3 * 100; // Convert to cents
        $params[':currency'] = 'USD';
    }
    else{
        // If no proper field update parameter
        output(returnContactUpdateInvalidInput());
    }
    
    $query .= " WHERE address = :address";
    $params[':address'] = $address;
    
    $stmt = $pdo->prepare($query);
    if ($stmt->execute($params)) {
        // If succesful update, respond of success
        output(returnContactUpdate());
    } else{
        // If unsuccesful update with correct parameters, implies not an existing contact, respond of this fact
        output(returnContactNotFound());
    }
}

function updateContactStatus($address,$status) {
    global $pdo;
    // Update contact request status
    try {     
        $updateStmt = $pdo->prepare("UPDATE contacts SET status = :status WHERE address = :address");     
        $updateStmt->bindParam(':status', $status);
        $updateStmt->bindParam(':address', $address);
        $updateStmt->execute();
    } catch (PDOException $e) {
        // Log or handle the error if updating status fails
        error_log("Error updating contact request status: " . $e->getMessage());
    }
}

function updateP2pRequestStatus($hash, $status, $completed = false) {
    global $pdo;
    // Update p2p request status
    try {
        // Add completed time if needed
        if($completed){
            $updateStmt = $pdo->prepare("UPDATE p2p SET status = :status, completed_at = CURRENT_TIMESTAMP WHERE hash = :hash");
        } else {
            $updateStmt = $pdo->prepare("UPDATE p2p SET status = :status WHERE hash = :hash");
        }       
        $updateStmt->bindParam(':hash', $hash);
        $updateStmt->bindParam(':status', $status);
        $updateStmt->execute();
        output(outputP2pStatusUpdated($status,$hash),'SILENT');
    } catch (PDOException $e) {
        // Log or handle the error if updating status fails
        error_log("Error updating p2p request status: " . $e->getMessage());
    }
}

function updateP2pTxid($hash, $txid, $incoming = false){
    global $pdo;
    // Update p2p txid
    try{
        $what = 'incoming_txid';
        if($incoming){
            $updateStmt = $pdo->prepare("UPDATE p2p SET incoming_txid = :txid WHERE hash = :hash");
        } else{
            $updateStmt = $pdo->prepare("UPDATE p2p SET outgoing_txid = :txid WHERE hash = :hash");
            $what = 'outgoing_txid';
        }
        $updateStmt->bindParam(':hash', $hash);
        $updateStmt->bindParam(':txid', $txid);
        $updateStmt->execute();
        //output(outputUpdatedTxid($txid,$what,$hash),'SILENT');
    } catch (PDOException $e) {
        // Log or handle the error if updating txid fails
        error_log("Error updating p2p txid : " . $e->getMessage());
    }
}

function updateTransactionStatus($memo, $status, $txid=false) {
    global $pdo;
    // Update transaction request status
    try {
        $what = "hash";
        if($txid){
            // Update only for direct transactions (no p2p)
            $updateStmt = $pdo->prepare("UPDATE transactions SET status = :status WHERE txid = :memo");   
            $what = "txid";  
        } else{
            $updateStmt = $pdo->prepare("UPDATE transactions SET status = :status WHERE memo = :memo");     
        }
        $updateStmt->bindParam(':memo', $memo);
        $updateStmt->bindParam(':status', $status);
        $updateStmt->execute();
        output(outputTransactionStatusUpdated($status,$what,$memo),'SILENT');
    } catch (PDOException $e) {
        // Log or handle the error if updating status fails
        error_log("Error updating transaction status: " . $e->getMessage());
    }
}

function viewBalanceQuery($direction, $userAddress, $limit){
    global $pdo;
    // View balance information based on transactions
    if($direction === "received"){
         $query = "SELECT sender_address, amount, currency, timestamp FROM transactions WHERE receiver_address = :userAddress ORDER BY timestamp DESC";
         $address = "sender_address";
         $where = "from";
    } else{
         $query = "SELECT receiver_address, amount, currency, timestamp FROM transactions WHERE sender_address = :userAddress ORDER BY timestamp DESC";
         $address = "receiver_address";
         $where = "to";
    }
   
    $stmt = $pdo->prepare($query);  
    $stmt->bindParam(':userAddress', $userAddress);
    $stmt->execute();

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $countResults = count($results);
    
    echo "\t\tBalance $direction $where:\n";
    $countrows = 1;
    foreach ($results as $res) {
        $amount = $res['amount'] / 100;
        printf("\t\t\t%s (%s) %s, %.2f %s\n", lookupContactNameByAddress($res[$address]), $res[$address], $res['timestamp'], $amount, $res['currency']);
        if($limit !== 'all' && ($countrows >= $limit)){
            break;
        } 
        $countrows += 1;
    }
    if($limit === 'all' || $limit > $countResults){
        $limit = $countResults;
    } 
    echo "\t\t\t----- Displaying $limit out of $countResults $direction balance(s) -----\n";
}

function viewTransactionHistory($argv) {
    global $pdo, $user;
    // View all transaction history in pretty print 'table'
    $query = "SELECT sender_address, receiver_address, amount, currency, timestamp FROM transactions";
    $address = null;
    $displayLimit = $user['maxOutput'];
    // Check if an address or name is provided
    if (isset($argv[2])) {
        // First if it's an HTTP or Tor address
        if (isHttpAddress($argv[2]) || isTorAddress($argv[2])) {
            $address = $argv[2];
        } else {
            // Check if the name yields an address
            $contactResult = lookupContactByName($argv[2]);
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