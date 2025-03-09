<?php
function acceptP2p($request) {
    output("Begin processing send p2p request: " . print_r($request, true), 'SILENT');
    updateP2pRequestStatus($request['memo'], 'sent');
    sendP2pEiou($request);
}

function checkP2p($request) { 
    output("Check p2p function triggered");
}

function handleP2pRequest($request) {
    global $user;
    if(!validateRequestLevel($request)){
        return buildInvalidRequestLevelPayload($request);
    }

    $requestedAmount = calculateRequestedAmount($request);
    $availableFunds = calculateAvailableFunds($request);  

    if ($availableFunds < $requestedAmount) {
        return buildInsufficientBalancePayload($availableFunds, $requestedAmount);
    } 

    // Save request 
    output("Inserting p2p request"); 
    $request['feeAmount'] = $requestedAmount - $request['amount'];
    $request['amount'] = $requestedAmount;
    insertP2pRequest($request, NULL);

    if ($matchedContact = matchContact($request)) {
        // Contact found, report back to sender
        output("Contact found, report back to sender");
        $myAddress = resolveUserAddressForTransport($request['senderAddress']);
        $myPubkey = $user['public'];
        $request['amount'] = $requestedAmount;
        $request['p2p_array'] = [
            0 => $matchedContact, 
            1 => [
                'address' => $myAddress,
                'pubkey' => $myPubkey
            ]
        ];
        $rP2pPayload = buildRP2pPayload($request);
        output("rP2p payload: " . print_r($rP2pPayload, true));
        $result = send($request['senderAddress'], $rP2pPayload);
        output("rP2p send result: " . print_r($result, true));
        // Update the p2p request status to processed
        updateP2pRequestStatus($request['hash'], 'found');
        
        // Save payload to output
    } else {
        // No matching contact, queue p2p request to be forwarded on
        output("No matching contact, queue p2p request to be forwarded on");
        updateP2pRequest($request, 'queued');
    }
}

function handleRp2pRequest($request) {
    global $user;
    //output("handleRp2pRequest() triggered", 'SILENT');

    // Save rp2p response 
    $insertResult = insertRp2pRequest($request);
    
    if (!$insertResult) {
        output("Failed to insert rp2p request: " . print_r($request, true), 'SILENT');
        output("Failed rp2p insert result: " . print_r($insertResult, true), 'SILENT');
        return false;
    }
    $result = getP2pByHash($request['hash']);
    if(!$result){
        throw new Exception('P2P request not found for the given hash.');
    }
    elseif(isset($result['destination_address'])) {
        //output("I initiated this request", 'SILENT');
        //output("My p2p details: " . print_r($result, true), 'SILENT');
        //I sent this request, let's check if i am willing to pay the fee
        $p2pAmount = $result['amount'];
        $rP2pAmount = $request['amount'];
        $feeAmount = $rP2pAmount - $p2pAmount;
        $feePercent = ($feeAmount / $p2pAmount) * 100;
        //output("They want a fee of " . $feePercent . " percent, my max fee is " . $user['maxFee'], 'SILENT');

        if ($feePercent <= $user['maxFee']) {
            $result['amount'] = $request['amount'];
            $result['currency'] = $request['currency'];
            $result['senderPublicKey'] = $request['senderPublicKey'];
            $result['senderAddress'] = $request['senderAddress'];
            $result['memo'] = $request['hash'];
            acceptP2p($result);
        } else {
            output("I reject the fee, ignore really. it will expire. or is there something else i can do?", 'SILENT');
        }
    }
    else{
        // Forward back to the person who sent it to me
        output("Got a rp2p and it's not for me", "SILENT");
        // Forward rp2p back to original sender
        $rp2pPayload = [
            'type' => 'rp2p',
            'hash' => $request['hash'],
            'time' => $request['time'],
            'amount' => $request['amount'],
            'currency' => $request['currency'],
            'senderPublicKey' => $request['senderPublicKey'],
            'senderAddress' => $request['senderAddress'],
            'signature' => $request['signature']
        ];
        send($result['sender_address'], $rp2pPayload);
    }

}

function prepareP2pRequestData($request) {
    global $user;
    output("Prepare send p2p data: " . print_r($request, true), 'SILENT');
    
    if (!isset($request[2])) {
        output("$request[2] (receiverAddress) is not set: " . print_r($request, true));
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
    $data['p2pHash'] = hash('sha256', $data['receiverAddress'] . $data['salt'] . $data['time']); // Create hash
    output("Generated p2pHash: " . $data['p2pHash'], 'SILENT'); // Added verbose output
    output("p2pHash components: " . ", receiverAddress: " . $data['receiverAddress'] . ", salt: " . $data['salt'] . ", time: " . $data['time'], 'SILENT'); // Detailed verbose output
    $data['randomNumber'] = abs(rand(300, 700) - rand(200, 500)) + rand(1, 10); // todo: lower bound should be private (generated in frehs install and put in config file) or generated for each contact
    $data['maxRequestLevel'] = $data['randomNumber'] + $user['maxP2pLevel'] - 1; // Handle off by 1 in request calculation

    return $data;
}

function processQueuedP2pMessages() {
    global $user;
    // Select queued messages from the p2p table
    foreach (retrieveQueuedP2pMessages() as $message) {
        echo "Processing p2p message with unique hash: " . $message['hash'] . "\n";
        echo "Message details - Currency: " . $message['currency'] . ", Amount: " . $message['amount'] . "\n";
        echo "Current request level: " . $message['request_level'] . " of max " . $message['max_request_level'] . "\n";
        $p2pPayload = createForwardP2pPayload($message);

        // Retrieve contacts to send p2p request, excluding the sender
        $contacts = retrieveContactAddresses($message['sender_address']);
        $contactsCount = countTorAndHttpAddresses($contacts);
        echo "Found " . count($contacts) . " contacts to send p2p request\n";
        //echo "Tor addresses: " . $contactsCount['tor'] . ", HTTP addresses: " . $contactsCount['http'] . "\n";

        // Send p2p request to all contacts
        foreach ($contacts as $contactAddress) {
            echo "Sending p2p request to contact: $contactAddress\n";
            send($contactAddress, $p2pPayload);
        }
        
        // Update the p2p request status to sent
        updateP2pRequest($message, 'sent');
    }
}

function processQueuedRP2pMessages() {
    global $user;
    // Select queued messages from the p2p table with sent status
    $queuedMessages = retrieveQueuedP2pMessages($status = 'sent');

    echo "Found " . count($queuedMessages) . " sent messages to check\n";

    // Process each queued message
    foreach ($queuedMessages as $message) {
        // Check if the message hash exists in the rp2p table
        $rP2pResult = checkRP2pExists($message['hash']);
        // If matching rp2p found, echo forwarding message
        if ($rP2pResult) {
            output("Found rp2p match for hash: " . $message['hash'], 'SILENT');
            output("rp2p result: " . print_r ($rP2pResult['p2p_array'], true));  

            $originalRequest = lookupP2pRequest($message['hash']);

            // Add my info as the next array index
            $p2pArray = json_decode($rP2pResult['p2p_array'], true);
            $myAddress = resolveUserAddressForTransport($originalRequest['sender_address']);
            $myPubkey = $user['public'];
            var_dump($p2pArray);
            array_push($p2pArray, [
                'address' => $myAddress,
                'pubkey' => $myPubkey
            ]);
            $rP2pResult['p2p_array'] = $p2pArray;
            $rP2pPayload = buildRP2pPayload($rP2pResult);
            //output("rP2p payload: " . print_r ($rP2pPayload['p2p_array'], true));
            send($message['sender_address'], $rP2pPayload);
            // Update the p2p request status to found
            updateP2pRequestStatus($message['hash'], 'found');
        }
    }
}

function sendP2pRequest($data) {
    global $user;
    //output("I want to send " . ($data['amount'] / 100) . " USD to " . $data['receiverAddress'], 'SILENT');
    //output("Full sendP2pRequest data: " . print_r($data, true));
    // Save the p2p request as a pending p2p transaction
    
    // Prepare p2p request payload
    $p2pPayload = buildP2pPayload(prepareP2pRequestData($data));
    //output("Trying to insert p2p request: " . print_r($data['receiverAddress'], true));
    //output("Full P2p request payload: " . print_r($p2pPayload, true));
    output("Inserting p2p request with receiverAddress: " . print_r($data[2], TRUE), 'SILENT');
    insertP2pRequest($p2pPayload, $data[2]);

    // Retrieve all contacts to send p2p request
    $contacts = retrieveContactAddresses();

    // Send p2p request to all contacts
    $totalSent = ['tor' => 0, 'http' => 0];
    foreach ($contacts as $contactAddress) {
        // Log each send attempt
        //output("Sending p2p to: " . $contactAddress, 'SILENT');
        send($contactAddress, $p2pPayload);
        if(isTorAddress($contactAddress)) {
            $totalSent['tor']++;
        }
        elseif(isHttpAddress($contactAddress)){
            $totalSent['http']++;
        }
    }

    //output("Sent Peers of Peers request to " . $totalSent['tor'] . " tor contacts and " . $totalSent['http'] . " http(s) contacts.", 'SILENT');
    // Inform user about expected response time
    
    $httpExpectedResponseTime = $user['maxP2pLevel']; // Use maxP2pLevel seconds for http
    $torExpectedResponseTime = 5 * 2 * $user['maxP2pLevel']; //5 seconds for a tor request, 2 times for a round trip, multiplied by maxP2pLevel
    //output("You should expect a response within " . $httpExpectedResponseTime . " seconds for http and " . $torExpectedResponseTime . " seconds for tor.", 'SILENT');

    return $p2pPayload;
}