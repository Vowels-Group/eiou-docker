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
    $addressFromName = lookupContactAddressByName($data[2]);
    if(isset($addressFromName)){
        $address = $addressFromName;
    } else{
        $address = $data[2];
    }
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
    return $checkStmt->rowCount() <= 0; // Note > 0 = false, we check if contacts are blocked
}

function checkContactStatus($address){
    global $pdo;
    // Check if contact has no bad status i.e. blocked
    $checkStmt = $pdo->prepare("SELECT * FROM contacts WHERE address = :address AND status != 'accepted'");
    $checkStmt->bindParam(':address', $address);
    $checkStmt->execute();
    return $checkStmt->rowCount() > 0;
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

function checkPendingContactInserted($address) {
    global $pdo;
    // Check if contact already exists in the database but is not yet accepted
    $checkQuery = "SELECT * FROM contacts WHERE address = :address AND name IS NOT NULL AND status = 'pending'";
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

function deleteContact($data) {
    global $pdo;
    // Delete a contact
    $addressFromName = lookupContactAddressByName($data[2]);
    if(isset($addressFromName)){
        $address = $addressFromName;
    } else{
        $address = $data[2];
    }
    $query = "DELETE FROM contacts WHERE address = :address";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':address', $address);
    if ($stmt->execute() && $stmt->rowCount() > 0) {
        echo returnContactDeletedSuccesfully();
        return true;
    } else {
        echo returnContactNotFoundNoAction();
        return false;
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

function lookupContactByName($name) {
    global $pdo;
    // Lookup general contact information based on name
    $nameStmt = $pdo->prepare("SELECT name, address, pubkey, fee_percent FROM contacts WHERE LOWER(name) = LOWER(:name)");
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
    $addressFromName = lookupContactAddressByName($data[2]);
    if(isset($addressFromName)){
        $address = $addressFromName;
    } else{
        $address = $data[2];
    }
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

function updateUnblockContact($address,$name,$fee,$credit,$currency){
    global $pdo;
    try { 
        $updateStmt = $pdo->prepare("UPDATE contacts SET name = :name, status = 'accepted', fee_percent = :fee, credit_limit = :credit, currency = :currency WHERE address = :address"); 
        $updateStmt->bindParam(':address', $address);
        $updateStmt->bindParam(':name', $name);
        $updateStmt->bindParam(':fee', $fee);
        $updateStmt->bindParam(':credit', $credit);
        $updateStmt->bindParam(':currency', $currency);
        if($updateStmt->execute()){
            return true;
        } else{
            return false;            
        }
    } catch (PDOException $e) {
        // Log or handle the error if updating status fails
        error_log("Error unblocking contact and adding new values: " . $e->getMessage());
        return false;
    }

}