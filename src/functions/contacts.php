<?php

function addContact($data) {
    global $user;
    //Get sender address
    $senderPublicKey = $user['public'];
    // Assign command line arguments to variables

    $address = filter_var($data[2], FILTER_VALIDATE_REGEXP, array("options"=>array("regexp"=>"/^[a-zA-Z0-9]{56}$|^[a-z2-7]{56}\.onion$|^https?:\/\/[a-zA-Z0-9.-]+/")));
    $name = htmlspecialchars(trim($data[3]), ENT_QUOTES, 'UTF-8');
    $fee = filter_var($data[4], FILTER_VALIDATE_FLOAT) * 100;
    $credit = filter_var($data[5], FILTER_VALIDATE_FLOAT) * 100;
    $currency = htmlspecialchars(trim($data[6]), ENT_QUOTES, 'UTF-8');

    // Validate input
    if(!$address || !$name || !is_numeric($fee) || !is_numeric($credit) || !$currency) {
        output(returnContactAddInvalidInput(), 'ERROR');
        exit(1);
    }

    if (checkAcceptedContact($address, $name)) {
        // Check if contact is already an accepted contact
        output(returnContactExists(), 'WARNING');
        exit(1);
    }
    elseif (checkPendingContact($address)) {
        // If contact already exists with an address, it's a contact request, skip sending a message
        if (acceptContact($address, $name, $fee, $credit, $currency)) {
            output(returnContactAccepted());
            exit(0);    
        }
        else {
            output(returnContactAcceptanceFailed(), 'ERROR');            
            exit(1);
        }
    }
    else{
        // Build the payload array
        $payload = array(
            'type' => 'create',
            'senderPublicKey' => $senderPublicKey,
            'name' => $name
        );
        // Determine if tor, else add http hostname
        if (preg_match('/\.onion$/', $address)) {
            $payload['senderAddress'] = $user['torAddress'];
        }
        else {
            $payload['senderAddress'] = $user['hostname'];
        }

        // Send the message and get the response
        $response = send($address, $payload);
        
        // Check if the response indicates successful acceptance
        $responseData = json_decode($response, true);
        if (isset($responseData['status']) && ($responseData['status'] === 'accepted' || $responseData['status'] === 'warning')) {
            // Check if the response status is a warning
            if ($responseData['status'] === 'warning') {
                output(returnContactCreationWarning($responseData['message']));
            }
            // Insert into database
            if (insertContact($address, $responseData['myPublicKey'], $name, $fee, $credit, $currency)) {
                output(returnContactCreationSuccessful());
            }
            else{
                output(returnContactCreationFailed());
            }
        }else {
            // If not accepted, show error and display the response
            output(returnContactRejected($responseData));
            output("Failed contact request payload: ". print_r($payload, true), 'SILENT');
            exit(1);
        }
    }
}

function handleContactCreation($request) {
    $address = $request['senderAddress'];
    $senderPublicKey = $request['senderPublicKey'];

    // Check if contact already exists
    if (checkContactExists($address)) {
        return json_encode(["status" => "warning", "message" => "Contact already exists"]);
    }
    else{
        return addPendingContact($address, $senderPublicKey);
    }
}

function lookupContactInfo ($request) {
    // Lookup information
    $lookupResultByName = lookupContactByName($request);
    $lookupResultByAddress = lookupContactByAddress($request);
    $lookupResult = $lookupResultByName ?? $lookupResultByAddress;
    if (isset($lookupResult['name'])) {
        $data['receiverName'] = $lookupResult['name'];
    }
    if (isset($lookupResult['pubkey'])) {
        $data['receiverPublicKey'] = $lookupResult['pubkey'];
    }
    if (isset($lookupResult['pubkey_hash'])) {
        $data['receiverPublicKeyHash'] = $lookupResult['pubkey_hash'];
    }
    if (isset($lookupResult['address'])){
        $data['receiverAddress'] = $lookupResult['address'];
    }
    return isset($data) ? $data : null;
}

function readContact($data) {
    if (count($data) >= 3) {
        if ($result = readContactQuery($data[2])) {
            output(returnContactDetails($result));
        } else {
            output(returnContactNotFound());
        }
    } else {
        output(returnContactReadInvalidInput());
        exit(1);
    }
}

function searchContacts($data) {
    $searchTerm = $data[2] ?? null;
    if ($results = searchContactsQuery($searchTerm)) {
        output(returnContactSearchResults());
    }
    else{
        output(returnContactSearchNoResults());
    }
}