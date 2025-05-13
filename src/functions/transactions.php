<?php

function prepareSendData($request) {
    output("Prepare send data: " . print_r($request, true), 'SILENT');

    $data['txType'] = 'standard';
    $data['time'] = time();
    $data['amount'] = round($request[3] * 100); // Convert to cents
    $data['currency'] = 'USD';
    $data['memo'] = 'standard';
    return $data;
}

function processTransaction($request) {
    global $user;
    // Calculate current balance
    $totalSent = calculateTotalSent($request['senderPublicKey']);
    $totalReceived = calculateTotalReceived($request['senderPublicKey']);
    $currentBalance = $totalReceived - $totalSent; 
    
    // If a previous transaction exists, verify the previousTxid matches
    if (isset($request['previousTxid']) && $previousTxResult = getPreviousTxid($request['senderPublicKey'], $request['receiverAddress'])) {
        if ($previousTxResult !== $request['previousTxid']) {
            return json_encode([
                "status" => "rejected", 
                "message" => "Previous transaction ID does not match. Expecting: " . $previousTxResult['txid'] . " Received: " . $request['previousTxid']
            ]);
        }
    }

    // Get credit limit of sender
    $creditLimit = getCreditLimit($request['senderPublicKey']);

    // Check if sender has sufficient balance or credit limit
    $requiredAmount = $request['amount']; 
    $availableFunds = $currentBalance + $creditLimit;  
    if ($availableFunds < $requiredAmount) {
        return buildInsufficientBalancePayload($availableFunds, $requiredAmount, $creditLimit);
    } else {
        $memo = $request['memo'];
        $rP2pResult  = checkRP2pExists($memo);
        if(isset($rP2pResult) && $memo === $rP2pResult['hash']){  
            $request['receiverAddress'] = $rP2pResult['sender_address'];
            $request['receiverPublicKey'] = $rP2pResult['sender_public_key'];
            $request['txid'] = hash('sha256', $user['public'] . $request['receiverPublicKey'] . $request['amount'] . $request['time']); 
            //remove my transaction fee and send remainder onwards
            $request['amount'] = removeTransactionFee($request); 
            $request['previousTxid'] = getPreviousTxid($user['public'], $request['receiverPublicKey']);
            
            $payload = buildSendPayload($request);
            updateP2pRequestStatus($memo,'paid');
            output("Sending Transaction onwards to: " . $request['receiverAddress'],'SILENT');
            $response = json_decode(send($request['receiverAddress'], $payload),true);         
            output("Received Transaction response Transaction with status: " . $response['status'],'SILENT');
            //output("Accepting Transaction as Intermediate (RP2P) : " .  print_r($request,true),'SILENT'); 
            if (isset($response['status']) && $response['status'] === 'accepted') {
                updateP2pRequestStatus($memo,'completed');
                $insertTransactionResponse = insertTransaction($request);
                updateTransactionStatus($memo,'completed');
                return $insertTransactionResponse;
            }
        } elseif(matchYourselfTransaction($request,resolveUserAddressForTransport($request['senderAddress']))){  
            output("Transaction for me, inserting",'SILENT');
            $request['previousTxid'] = getPreviousTxid($request['senderPublicKey'], $request['receiverPublicKey']); 
            updateP2pRequestStatus($memo,'completed');
            $insertTransactionResponse = insertTransaction($request);
            updateTransactionStatus($memo,'completed');
            return $insertTransactionResponse;
        }
    }
}

function send($recipient, $payload){
   
    // Encode the payload as JSON
    $signedPayload = json_encode(sign($payload));
    // Determine if tor address, else send by http
    if (preg_match('/\.onion$/', $recipient)) {
        return sendByTor($recipient, $signedPayload);
    } else {
        return sendByHttp($recipient, $signedPayload);
    }
}

function sendByHttp ($recipient, $signedPayload) {
    $ch = curl_init();
    
    // Determine the protocol based on the recipient format
    $protocol = preg_match('/^https?:\/\//', $recipient) ? '' : 'http://';
    
    curl_setopt($ch, CURLOPT_URL, $protocol . $recipient . "/eiou?payload=" . urlencode($signedPayload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POST, true);
    $response = curl_exec($ch);
    curl_close($ch);
    // Return the response from the recipient
    return $response;
}

function sendByTor ($recipient, $signedPayload) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://$recipient/eiou?payload=" . urlencode($signedPayload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_PROXY, "127.0.0.1:9050");
    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_POST, true);
    $response = curl_exec($ch);
    curl_close($ch);
    // Return the response from the recipient
    return $response;
}

function sendEiou($request = null) {
    global $user;
    // If no request is provided, use $data as a fallback
    if ($request === null) {
        global $data;
        $request = $data;
    }
    //output("Getting ready to send eIOU with request: " . print_r($request, true), 'SILENT');
    validateSendRequest($request);
        
    // If receiver's public key is in contacts, prepare a transaction to send directly to them
    if ($contactInfo = lookupContactInfo ($request[2])) {
        output("Looked up contact info: " . print_r($contactInfo, true), 'SILENT');
        $data = prepareSendData($request);
        $data['receiverAddress'] = $contactInfo['receiverAddress'];
        $data['receiverPublicKey'] = $contactInfo['receiverPublicKey'];
        $data['txid'] = hash('sha256', $user['public'] . $contactInfo['receiverPublicKey'] . $data['amount'] . $data['time']);
        $data['previousTxid'] = getPreviousTxid($user['public'], $contactInfo['receiverPublicKey']);
        
        // Prepare transaction payload
        $payload = buildSendPayload($data);
        $response = json_decode(send($data['receiverAddress'], $payload), true);
        output("SendEiou response status: " . print_r($response,true),'SILENT');
        if (isset($response['status']) && $response['status'] === 'accepted' && (isset($response['txid']) && $response['txid'] === $data['txid'])) {
            // Transaction accepted, now insert into database
            insertTransaction($payload);
            updateTransactionStatus($payload['memo'],'completed');
        } else {
            output ("Not enough credit with this user, trying p2p with data: " . print_r($request, true),'SILENT');
            sendP2pRequest($request);
        }
    } else {
        output ("Contact not found, trying p2p with data: " . print_r($request, true), 'SILENT');
        sendP2pRequest($request);
    }
}

function sendP2pEiou($request) {
    global $user;
    output("Getting ready to send P2p eIOU with memo: " . print_r($request['memo'], true),'SILENT');

    //Add some validation to make sure is a valid rp2p
    // TODO



    $request['receiverAddress'] = $request['senderAddress'];
    $request['receiverPublicKey'] = $request['senderPublicKey'];

    $request['txid'] = hash('sha256', $user['public'] . $request['receiverPublicKey'] . $request['amount'] . $request['time']);
    $request['previousTxid'] = getPreviousTxid($user['public'], $request['receiverPublicKey']);

    // Prepare transaction payload
    $payload = buildSendPayload($request);
    updateP2pRequestStatus($payload['memo'],'paid');
    $response = json_decode(send($request['receiverAddress'], $payload),true);
    output("SendP2PEiou response status: " . print_r($response,true),'SILENT');
    if (isset($response['status']) && $response['status'] === 'accepted') {
        // Transaction accepted, now insert into database
        output("Inserting Transaction",'SILENT');
        insertTransaction($payload);
        updateP2pRequestStatus($payload['memo'],'completed');
        // 'memo' here is by definition "standard", so we need the 'hash'
        updateTransactionStatus($payload['memo'],'completed');
    } 
    else{
        // maybe not 'cancelled' if no response, try again?
        updateP2pRequestStatus($payload['memo'],'cancelled');
        updateTransactionStatus($payload['memo'],'rejected');
    }
}




function viewBalances($data) {
    global $pdo, $user;
    $query = "SELECT sender_address, receiver_address, amount, currency, timestamp FROM transactions";
    if (isset($data[2])) {
        $address = lookup($data[2]);
        $query .= " WHERE sender_address = :address OR receiver_address = :address";
    }
    
    $balances = [];
    $stmt = $pdo->prepare($query);
    
    if (isset($data[2])) {
        $stmt->bindParam(':address', $address);
    }
    
    $stmt->execute();
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Calculate balance changes
        $senderAddress = $row['sender_address'];
        $receiverAddress = $row['receiver_address'];
        $amount = $row['amount'] / 100;
        
        // Adjust balances for sender and receiver
        $balances[$senderAddress] = ($balances[$senderAddress] ?? 0) - $amount;
        $balances[$receiverAddress] = ($balances[$receiverAddress] ?? 0) + $amount;
    }
    $otherBalances = [];
    
    // Pretty print balances
    foreach ($balances as $address => $balance) {
        // Check if the address is the user's own address
        if (isMe($address)) {
            $displayName = "me";
            $additionalAddresses = [];
            
            if (isset($user['hostname'])) {
                $additionalAddresses[] = $user['hostname'];
            }
            
            if (isset($user['torAddress'])) {
                $additionalAddresses[] = $user['torAddress'];
            }
            
            $additionalInfo = $additionalAddresses ? '(' . implode(', ', $additionalAddresses) . ')' : '';
            
            printf("%s %s, Balance: %.2f\n", $displayName, $additionalInfo, $balance);
        } else {
            // If it's not the user's own address, add to a list to be sorted
            // Lookup contact name for the address
            $contactResult = lookupContactByAddress($address);
            $contactName = $contactResult ? $contactResult['name'] : $address;
            
            $otherBalances[] = [
                'address' => $address, 
                'name' => $contactName, 
                'balance' => $balance
            ];
        }
    }

    // Sort and print other balances
    usort($otherBalances, function($a, $b) {
        return $b['balance'] <=> $a['balance'];
    });

    foreach ($otherBalances as $contact) {   
        printf("%s (%s), Balance: %.2f\n", $contact['name'], $contact['address'], $contact['balance']);
    }
}
