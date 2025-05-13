<?php

function checkRequestLevel($request){
    // Check validatity of p2p request
    if(!validateRequestLevel($request)){
        echo buildInvalidRequestLevelPayload($request);
        return false;   
    } 
    return true;
}

function handleP2pRequest($request) {
    global $user;
    $myAddress = resolveUserAddressForTransport($request['senderAddress']);  
    // Check if p2p's destination is to user
    if(matchYourselfP2P($request,$myAddress)){
        $request['status'] = 'found';
        insertP2pRequest($request, $myAddress); // Insert p2p request
        // Build and send corresponding rp2p request payload to sender of p2p
        $rP2pPayload = buildRP2pPayload($request);
        $response = json_decode(send($request['senderAddress'], $rP2pPayload),true);
        output("Transaction (RP2P) send result: " . print_r($response, true),'SILENT');
    } else{
        // Check if sender has enough 'credit' to facilitate eIOU
        $requestedAmount = calculateRequestedAmount($request);
        $availableFunds = calculateAvailableFunds($request);  
        $creditLimit = getCreditLimit($request['senderPublicKey']);
        if ($availableFunds < $requestedAmount) {
            return buildInsufficientBalancePayload($availableFunds, $requestedAmount, $creditLimit);
        } 
        $request['feeAmount'] = $requestedAmount - $request['amount'];
        $request['amount'] = $requestedAmount; 
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
    $data['receiverAddress'] = $request[2];
    $data['txType'] = 'p2p';
    $data['time'] = time();
    $data['amount'] = round($request[3] * 100); // Convert to cents
    $data['currency'] = 'USD';

    // Additional data preparation
    $data['salt'] = bin2hex(random_bytes(16)); // Generate a random salt
    $data['hash'] = hash('sha256', $data['receiverAddress'] . $data['salt'] . $data['time']); // Create hash
    output("Generated p2pHash: " . $data['hash'], 'SILENT'); // Added verbose output
    output("p2pHash components: " . ", receiverAddress: " . $data['receiverAddress'] . ", salt: " . $data['salt'] . ", time: " . $data['time'], 'SILENT'); // Detailed verbose output
    $data['randomNumber'] = abs(rand(300, 700) - rand(200, 500)) + rand(1, 10); // TODO: lower bound should be private (generated in fresh install and put in config file) or generated for each contact
    $data['maxRequestLevel'] = $data['randomNumber'] + $user['maxP2pLevel'] - 1; // Handle off by 1 in request calculation
    return $data;
}

function processQueuedP2pMessages() {
    global $user;
    // Select queued messages from the p2p table (with status queued)
    $queuedMessages = retrieveQueuedP2pMessages();

    //echo "Found " . count($queuedMessages) . "p2p 'sent' messages to check\n";

    // Process each queued message
    foreach ($queuedMessages as $message) {     
        $p2pPayload = buildP2pPayload($message); // Build p2p request payload
        // If recipient is a contact send p2p directly
        if($matchedContact = matchContact($message)){  
            $response = json_decode(send($matchedContact['address'], $p2pPayload),true);
            output("P2P send result for matched contact: " . print_r($response,true),'SILENT');            
        }else{
            // Retrieve contacts to send p2p request, excluding the sender
            $contacts = retrieveContactAddresses($message['sender_address']);
            
            // Count amount of contacts to send p2p request
            $contactsCount = countTorAndHttpAddresses($contacts);

            // Send p2p request to all contacts
            foreach ($contacts as $contactAddress) {
                $response = json_decode(send($contactAddress, $p2pPayload),true);
                output("P2P response status: " . print_r($response['status'],true) . " for contact " . print_r($contactAddress,true),'SILENT');
            }
            if(isset($message['destination_address'])){
                output("Sent Peers of Peers request to " . $contactsCount['tor'] . " tor contacts and " . $contactsCount['http'] . " http(s) contacts.", 'SILENT');
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
    global $user;
    $p2pPayload = buildP2pPayload(prepareP2pRequestData($data));     // Prepare p2p request payload
    output("Inserting p2p request with receiverAddress: " . print_r($data[2], TRUE), 'SILENT');
    insertP2pRequest($p2pPayload, $data[2]); // Save the p2p request 
    updateP2pRequestStatus($p2pPayload['hash'], 'queued'); // Update the p2p request status to queued
}