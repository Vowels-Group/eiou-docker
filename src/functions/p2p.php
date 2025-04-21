<?php
function checkP2p($request) { 
    output("Check p2p function triggered",'SILENT');
}

function handleP2pRequest($request) {
    global $user;
    if(!validateRequestLevel($request)){
        return buildInvalidRequestLevelPayload($request);
    }
    $myAddress = resolveUserAddressForTransport($request['senderAddress']);  
    if(matchYourselfP2P($request,$myAddress)){
        //output("P2P request is for me, starting RP2P",'SILENT'); 
        insertP2pRequest($request, $myAddress);
        updateP2pRequest($request, 'found');
        // $request['amount'] = $requestedAmount;
        $p2pArray = [
            'address' => $myAddress,
            'pubkey' => $user['public']
        ];
        $request['p2p_array'] = $p2pArray;
        $rP2pPayload = buildRP2pPayload($request);
        $response = json_decode(send($request['senderAddress'], $rP2pPayload),true);
        output("rP2p send result: " . print_r($response, true),'SILENT');
    } else{
        $requestedAmount = calculateRequestedAmount($request);
        $availableFunds = calculateAvailableFunds($request);  

        if ($availableFunds < $requestedAmount) {
            return buildInsufficientBalancePayload($availableFunds, $requestedAmount);
        } 
        // Save request 
        $request['feeAmount'] = $requestedAmount - $request['amount'];
        $request['amount'] = $requestedAmount;
        //$request['outgoing_txid'] = hash('sha256', $user['public'] . $request['receiverPublicKey'] . $request['amount'] . $request['time']); 
        //$request['incoming_txid'] = $request['outgoing_txid'] ?? NULL;
        insertP2pRequest($request, NULL);
        updateP2pRequest($request, 'queued');
    }
}


function prepareP2pRequestData($request) {
    global $user;
    output("Prepare send p2p data: " . print_r($request, true), 'SILENT');
    
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
    $data['p2pHash'] = hash('sha256', $data['receiverAddress'] . $data['salt'] . $data['time']); // Create hash
    output("Generated p2pHash: " . $data['p2pHash'], 'SILENT'); // Added verbose output
    output("p2pHash components: " . ", receiverAddress: " . $data['receiverAddress'] . ", salt: " . $data['salt'] . ", time: " . $data['time'], 'SILENT'); // Detailed verbose output
    $data['randomNumber'] = abs(rand(300, 700) - rand(200, 500)) + rand(1, 10); // todo: lower bound should be private (generated in fresh install and put in config file) or generated for each contact
    $data['maxRequestLevel'] = 1000 + $user['maxP2pLevel'] - 1; //fix 
    //$data['maxRequestLevel'] = $data['randomNumber'] + $user['maxP2pLevel'] - 1; // Handle off by 1 in request calculation
    
    return $data;
}

function processQueuedP2pMessages() {
    global $user;
    // Select queued messages from the p2p table
    foreach (retrieveQueuedP2pMessages() as $message) {
        //echo "Processing p2p message with unique hash: " . $message['hash'] . "\n";
        //echo "Message details - Currency: " . $message['currency'] . ", Amount: " . $message['amount'] . "\n";
        //echo "Current request level: " . $message['request_level'] . " of max " . $message['max_request_level'] . "\n";
        $p2pPayload = createForwardP2pPayload($message);

        if($matchedContact = matchContact($message)){
            //echo "Sending p2p request to final recipient:" . $matchedContact['address'] ."\n";
            $response = json_decode(send($matchedContact['address'], $p2pPayload),true);
            output("P2P send result for matched contact: " . print_r($response,true),'SILENT');            
        }else{
            // Retrieve contacts to send p2p request, excluding the sender
            $contacts = retrieveContactAddresses($message['sender_address']);
            $contactsCount = countTorAndHttpAddresses($contacts);
            //echo "Found " . count($contacts) . " contacts to send p2p request\n";

            // Send p2p request to all contacts
            foreach ($contacts as $contactAddress) {
                //echo "Sending p2p request to contact: $contactAddress\n";
                $response = json_decode(send($contactAddress, $p2pPayload),true);
                //output("P2P send result: " . print_r($response,true) . " for contact: " . print_r($contactAddress,true),'SILENT');
            }
            // if(isset($result['destination_address'])){
            //     output("Sent Peers of Peers request to " . $contactsCount['tor'] . " tor contacts and " . $contactsCount['http'] . " http(s) contacts.", 'SILENT');
            //     //Inform user about expected response time
            //     $httpExpectedResponseTime = $user['maxP2pLevel']; // Use maxP2pLevel seconds for http
            //     $torExpectedResponseTime = 5 * 2 * $user['maxP2pLevel']; //5 seconds for a tor request, 2 times for a round trip, multiplied by maxP2pLevel
            //     output("You should expect a response within " . $httpExpectedResponseTime . " seconds for http and " . $torExpectedResponseTime . " seconds for tor.", 'SILENT');
            // }
        }
        // Update the p2p request status to sent
        updateP2pRequest($message, 'sent');
    }
}

function sendP2pRequest($data) {
    global $user;
    //output("I want to send " . ($data['amount'] / 100) . " USD to " . $data['receiverAddress'], 'SILENT');
    //output("Full sendP2pRequest data: " . print_r($data, true),'SILENT');
    // Save the p2p request as a pending p2p transaction
    
    // Prepare p2p request payload
    $p2pPayload = buildP2pPayload(prepareP2pRequestData($data));
    //output("Trying to insert p2p request: " . print_r($data['receiverAddress'], true),'SILENT');
    //output("Full P2p request payload: " . print_r($p2pPayload, true),'SILENT');
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