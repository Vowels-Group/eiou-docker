<?php
# Copyright 2025

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

function retrieveRp2pMessages($status = 'sent') {
    global $pdo;
    // Retrieve all p2p messages that are 'sent'
    $queuedStmt = $pdo->prepare("SELECT * FROM p2p WHERE status = :status ORDER BY created_at ASC LIMIT 5");
    $queuedStmt->bindParam(':status', $status);

    $queuedStmt->execute();
    $queuedMessages = $queuedStmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($queuedMessages) . " " . $status . " messages to process\n";
    return $queuedMessages;
}