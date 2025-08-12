<?php
# Copyright 2025

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

function checkExistenceTransaction($request, $echo = true){
    // Check if Transaction already exists for memo in database and is a valid successor of previous txids
    // Check if Transaction is a valid successor of previous txids
    if(!checkContactBlockedStatus($request) || !checkPreviousTxid($request) || !checkAvailableFundsTransaction($request)){
        return true;
    }
    // Check if Transaction already exists for txid or memo in database
    try{
        $memo = $request['memo'];
        if($memo === "standard"){
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
        if($request['memo'] !== "standard"){
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

function retrievePendingTransactionMessages(){
    global $pdo;
    // Retrieve all transaction messages that are pending (default)
    $queuedStmt = $pdo->prepare("SELECT * FROM transactions WHERE status = 'pending' ORDER BY timestamp ASC LIMIT 5");
    $queuedStmt->execute();
    $queuedMessages = $queuedStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($queuedMessages) . " pending transaction messages to process\n";
    return $queuedMessages;
}

function updateTransactionStatus($memo, $status, $txid=false) {
    global $pdo;
    // Update transaction request status
    try {
        $typeTransaction = "hash";
        if($txid){
            // Update only for direct transactions (no p2p)
            $updateStmt = $pdo->prepare("UPDATE transactions SET status = :status WHERE txid = :memo");   
            $typeTransaction = "txid";  
        } else{
            $updateStmt = $pdo->prepare("UPDATE transactions SET status = :status WHERE memo = :memo");     
        }
        $updateStmt->bindParam(':memo', $memo);
        $updateStmt->bindParam(':status', $status);
        $updateStmt->execute();
        output(outputTransactionStatusUpdated($status,$typeTransaction,$memo),'SILENT');
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