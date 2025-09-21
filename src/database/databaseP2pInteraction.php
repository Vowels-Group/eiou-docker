<?php
# Copyright 2025

function checkCompletionP2pByHash($hash){
    // Check if P2P was already completed
    $results = getP2pByHash($hash);
    if($results['status'] === 'completed'){
        return true;
    } 
    return false;
}

function checkExistenceP2p($request, $echo = true){
    // Check if P2P already exists for hash in database, is valid and can be completed
    // & Check if P2P is valid and can be completed given credit of user requesting
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

function retrieveExpiringP2pMessages($status = 'completed', $status2 = 'expired', $status3 = 'cancelled') {
    global $pdo;
    // Retrieve all (max 5) p2p messages that are potentially not updating  
    $queuedStmt = $pdo->prepare("SELECT * FROM p2p WHERE status NOT IN (:status, :status2, :status3) ORDER BY created_at ASC LIMIT 5");
    $queuedStmt->bindParam(':status', $status);
    $queuedStmt->bindParam(':status2', $status2);
    $queuedStmt->bindParam(':status3', $status3);
    $queuedStmt->execute();
    $queuedMessages = $queuedStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($queuedMessages) . " " . $status . " messages to process\n";
    return $queuedMessages;
}


function retrieveQueuedP2pMessages($status = 'queued') {
    global $pdo;
    // Retrieve all (max 5) p2p messages that are queued (default) or by specific status(es) 
    $queuedStmt = $pdo->prepare("SELECT * FROM p2p WHERE status = :status ORDER BY created_at ASC LIMIT 5");
    $queuedStmt->bindParam(':status', $status);
    $queuedStmt->execute();
    $queuedMessages = $queuedStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($queuedMessages) . " " . $status . " messages to process\n";
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

function updateIncomingP2pTxid($hash, $txid){
    global $pdo;
    // Update p2p incoming_txid
    try{
        $updateStmt = $pdo->prepare("UPDATE p2p SET incoming_txid = :txid WHERE hash = :hash");
        $updateStmt->bindParam(':hash', $hash);
        $updateStmt->bindParam(':txid', $txid);
        $updateStmt->execute();
        output(outputUpdatedTxid($txid,'incoming_txid',$hash),'SILENT');
    } catch (PDOException $e) {
        // Log or handle the error if updating incoming_txid fails
        error_log("Error updating p2p txid : " . $e->getMessage());
    }
}

function updateOutgoingP2pTxid($hash, $txid){
    global $pdo;
    // Update p2p outgoing_txid
    try{
        $updateStmt = $pdo->prepare("UPDATE p2p SET outgoing_txid = :txid WHERE hash = :hash");
        $updateStmt->bindParam(':hash', $hash);
        $updateStmt->bindParam(':txid', $txid);
        $updateStmt->execute();
        output(outputUpdatedTxid($txid,'outgoing_txid',$hash),'SILENT');
    } catch (PDOException $e) {
        // Log or handle the error if updating txid fails
        error_log("Error updating p2p outgoing_txid : " . $e->getMessage());
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