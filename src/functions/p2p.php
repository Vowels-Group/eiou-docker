<?php
# Copyright 2025

function checkRequestLevel($request){
    // Check validatity of p2p request
    if(!validateRequestLevel($request)){
        echo buildInvalidRequestLevelPayload($request);
        return false;   
    } 
    return true;
}

function checkAvailableFunds($request){
    // Check if p2p's destination is to user
    if(!matchYourselfP2P($request, resolveUserAddressForTransport($request['senderAddress']))){
        // Check if sender has enough 'credit' to facilitate eIOU
        $requestedAmount = calculateRequestedAmount($request);
        $availableFunds = calculateAvailableFunds($request);  
        $fundsOnHold =  retrieveCreditInP2p($request['senderAddress']);
        $creditLimit = getCreditLimit($request['senderPublicKey']);
        if ($availableFunds < ($requestedAmount + $fundsOnHold)) {
            echo buildInsufficientBalancePayload($availableFunds, $requestedAmount, $creditLimit, $fundsOnHold);
            return false;
        } 
    }
    return true;
}

function handleP2pRequest($request) {
    $myAddress = resolveUserAddressForTransport($request['senderAddress']);  
    // Check if p2p's destination is to user
    if(matchYourselfP2P($request,$myAddress)){
        $request['status'] = 'found';
        insertP2pRequest($request, $myAddress); // Insert p2p request
        // Build and send corresponding rp2p request payload to sender of p2p
        $rP2pPayload = buildRp2pPayload($request);
        $response = json_decode(send($request['senderAddress'], $rP2pPayload),true);
        output("Transaction (RP2P) send response: " . print_r($response, true),'SILENT');
    } else{
        // Calculate fees
        $requestedAmount = calculateRequestedAmount($request);
        $request['feeAmount'] = $requestedAmount - $request['amount'];
        insertP2pRequest($request, NULL);  // Insert p2p request
        updateP2pRequestStatus($request['hash'], 'queued'); // Update the p2p request status to queued
    }
}


function prepareP2pRequestData($request) {
    // Build initial p2p request payload
    global $user;
    output("Prepare send p2p data: " . print_r($request, true), 'SILENT');
    
    // Check if the address of the recipient was supplied
    if (!isset($request[2])) {
        output("$request[2] (receiverAddress) is not set: " . print_r($request, true),'SILENT');
        die;
    }

    $data = $request;

    // TO DO if address = name?
    $data['receiverAddress'] = $request[2];
    $data['txType'] = 'p2p';
    $data['time'] = returnMicroTime();
    $data['amount'] = round($request[3] * 100); // Convert to cents 100 
    $data['currency'] = 'USD';

    // Additional data preparation
    $data['salt'] = bin2hex(random_bytes(16)); // Generate a random salt
    $data['hash'] = hash('sha256', $data['receiverAddress'] . $data['salt'] . $data['time']); // Create hash
    output("Generated p2pHash: " . $data['hash'], 'SILENT'); // Added verbose output
    output("p2pHash components: " . "receiverAddress: " . $data['receiverAddress'] . ", salt: " . $data['salt'] . ", time: " . $data['time'], 'SILENT'); // Detailed verbose output
    $data['minRequestLevel'] = abs(rand(300, 700) - rand(200, 500)) + rand(1, 10); // Caculate 'random' lower bound for request level
    $data['maxRequestLevel'] = $data['minRequestLevel'] + $user['maxP2pLevel'];    // Add upper bound to request level, using users max 
    return $data;
}

function processQueuedP2pMessages() {
    global $user;
    // Select queued messages from the p2p table (with status queued)
    $queuedMessages = retrieveQueuedP2pMessages();

    // Process each queued message
    foreach ($queuedMessages as $message) {     
        $p2pPayload = buildP2pPayload($message); // Build p2p request payload


        // Check if user is NOT the original sender of the p2p and has a direct contact link to end-recipient
        // If this is the case then send p2p directly
        if(!isset($message['destination_address']) && $matchedContact = matchContact($message)){ 
            $response = json_decode(send($matchedContact['address'], $p2pPayload),true);
            output("P2P send result for matched contact: " . print_r($response,true),'SILENT');            
        }else{
            // Retrieve contacts to send p2p request, excluding the sender
            $contacts = retrieveContactAddresses($message['sender_address']);
            // Count amount of contacts to send p2p request
            $contactsCount = countTorAndHttpAddresses($contacts);

            // Send p2p request to all contacts
            foreach ($contacts as $contact) {
                // Do not send p2p to contact (end-recipient), if direct transaction failed due to insufficient funds
                if(isset($message['destination_address']) && $contact === $message['destination_address']){
                    if(isTorAddress($message['destination_address'])){
                        $contactsCount['tor'] -= 1;
                    } else{
                        $contactsCount['http'] -= 1;
                    }
                    continue;
                }

                $response = json_decode(send($contact, $p2pPayload),true);
                output("P2P response: " . print_r($response,true),'SILENT');
            }
            if(isset($message['destination_address'])){
                output("Sent Peer to peer request to " . $contactsCount['tor'] . " tor contacts and " . $contactsCount['http'] . " http(s) contacts.", 'SILENT');
                //Inform user about expected response time
                $httpExpectedResponseTime = $user['maxP2pLevel']; // Use maxP2pLevel seconds for http
                $torExpectedResponseTime = 5 * 2 * $user['maxP2pLevel']; //5 seconds for a tor request, 2 times for a round trip, multiplied by maxP2pLevel
                output("You should expect a response within " . $httpExpectedResponseTime . " seconds for http and " . $torExpectedResponseTime . " seconds for tor.", 'SILENT');
            }
        }
        updateP2pRequestStatus($message['hash'], 'sent'); // Update the p2p request status to sent
    }
}

function sendP2pRequest($data) {
    // Check if a valid address format was supplied, if not look up the address in the case of a contact re-routing
    if (isHttpAddress($data[2]) || isTorAddress($data[2])) {
        $address = $data[2];
    } else{
        // This is only necessary in the case that a direct transaction to a contact (send NAME) was rejected based on insufficient credit
        $contactAddress = lookupContactAddressByName($data[2]);
        if($contactAddress){
            $address = $contactAddress;
            $data[2] = $address; // check if fixed issue with blank sender_address
        } else{
            output("Not an address nor existing contact with name: " . $data[2],'SILENT');
            die;
        }
    }   
    $p2pPayload = buildP2pPayload(prepareP2pRequestData($data)); // Prepare p2p request payload
    output("Inserting p2p request with receiverAddress: " . $address, 'SILENT');
    insertP2pRequest($p2pPayload, $address); // Save the p2p request 
    updateP2pRequestStatus($p2pPayload['hash'], 'queued'); // Update the p2p request status to queued
}