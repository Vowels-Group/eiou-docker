<?php
# Copyright 2025

function checkPreviousTxid($request){
    // If a previous transaction exists, verify the previousTxid matches
    if (isset($request['previousTxid']) && $previousTxResult = getPreviousTxid($request['senderPublicKey'], $request['receiverAddress'])) {
        if ($previousTxResult !== $request['previousTxid']) {
            echo json_encode([
                "status" => "rejected", 
                "message" => "Previous transaction ID does not match. Expecting: " . $previousTxResult['txid'] . " Received: " . $request['previousTxid']
            ]);
            return false;
        }
    }
    return true;
}

function checkAvailableFundsTransaction($request){
    $totalSent = calculateTotalSent($request['senderPublicKey']);
    $totalReceived = calculateTotalReceived($request['senderPublicKey']);
    $currentBalance = $totalReceived - $totalSent; 
    // Get credit limit of sender
    $creditLimit = getCreditLimit($request['senderPublicKey']);

    // Check if sender has sufficient balance or credit limit
    $requiredAmount = $request['amount']; 
    $availableFunds = $currentBalance + $creditLimit;  

    if ($availableFunds > $requiredAmount) {
        return true;
    } else{
        echo buildInsufficientBalancePayload($availableFunds, $requiredAmount, $creditLimit, 0);
        return false;
    }   
}

function fixPreviousTxid($senderPubKey,$receiverPubKey){
    $prevID = getPreviousTxid($senderPubKey, $receiverPubKey);
    while(getExistingPreviousTxid($prevID)){
        $prevID = getPreviousTxid($senderPubKey, $receiverPubKey);
        usleep(1000); // Sleep for 1ms
    }
    return $prevID;
}

function createUniqueTxid($data){
    global $user;
    // Create Txid for transactions
    $txid = hash('sha256', $user['public'] . $data['receiverPublicKey'] . $data['amount'] . $data['time']);
    return $txid;
}

function createUniqueDatabaseTxid($data){
    global $user;
    // Create unique Txid for transactions (from database)
    $txid = hash('sha256', $user['public'] . $data['receiver_public_key'] . $data['amount'] . $data['time']);
    return $txid;
}

function prepareSendData($request) {
    // Prepare initial request payload for direct transaction
    output("Prepare send data: " . print_r($request, true), 'SILENT');
    $data['txType'] = 'standard';
    $data['time'] = returnMicroTime();
    $data['amount'] = round($request[3] * 100); // Convert to cents
    $data['currency'] = 'USD';
    $data['memo'] = 'standard';
    return $data;
}

function processTransaction($request) {
    global $user;
    // Process incoming transactions
    if($request['memo'] === 'standard'){
        // If direct transaction
        $insertTransactionResponse = insertTransaction($request);    
    } else{
        // If p2p type transaction
        $memo = $request['memo'];
        $rP2pResult  = checkRp2pExists($memo);
        // Check if precursors to transactions exist and correspond
        if(isset($rP2pResult) && $memo === $rP2pResult['hash']){  
            $request['txid'] = createUniqueTxid($request);
            $request['previousTxid'] = fixPreviousTxid($user['public'], $request['senderPublicKey']);
            $insertTransactionResponse = json_decode(insertTransaction($request),true); // Insert Transaction as pending
            output(outputTransactionInsertion($insertTransactionResponse));
        } elseif(matchYourselfTransaction($request,resolveUserAddressForTransport($request['senderAddress']))){  
            // If Transaction is for end-recipient
            $request['previousTxid'] = fixPreviousTxid($request['senderPublicKey'], $request['receiverPublicKey']); 
            $insertTransactionResponse = json_decode(insertTransaction($request),true); // Insert Transaction as pending
            output(outputTransactionInsertion($insertTransactionResponse));
        }
    }
}
   
function processPendingTransactions(){
    global $user;
    // Select pending messages from the transaction table (with status pending)
    $pendingMessages = retrievePendingTransactionMessages();
    // Process each pending message
    foreach ($pendingMessages as $message) {   
        $memo = $message['memo'];  
        $txid = $message['txid'];
        // If direct transaction
        if($memo == 'standard'){
            if($message['sender_address'] == resolveUserAddressForTransport($message['sender_address'])){
                $payload = buildSendDatabasePayload($message);
                updateTransactionStatus($txid,'sent',true);  // Update transaction status to sent
                $response = json_decode(send($message['receiver_address'], $payload),true);
                output(outputTransactionInquiryResponse($response),'SILENT');
                if($response['status'] === 'accepted'){
                    updateTransactionStatus($txid,'accepted',true); // Update transaction status to accepted
                } elseif($response['status'] === 'rejected'){
                    updateTransactionStatus($txid,'rejected',true); // Update transaction status to rejected
                    output(outputIssueTransactionTryP2p($response),'SILENT'); // TO DO also not silent for people?
                    sendP2pRequestFromFailedDirectTransaction($message);
                }
            } else{
                updateTransactionStatus($txid,'completed',true); // Update transaction status to completed
                output(outputTransactionAmountReceived($message),'SILENT');
                $payloadTransactionCompleted = buildSendCompletedPayload($message);
                output(outputSendTransactionCompletionMessageTxid($message),'SILENT');
                $response = send($message['sender_address'],$payloadTransactionCompleted);          
            }      
        } else{
            // If p2p transaction
            if($message['sender_address'] == resolveUserAddressForTransport($message['sender_address'])){
                $rp2p = checkRp2pExists($memo);
                $message['time'] = $rp2p['time'];
                // If sending transaction forwards
                $payload = buildSendDatabasePayload($message);
                updateP2pRequestStatus($memo,'paid'); // Update p2p status to paid
                updateTransactionStatus($txid,'sent',true); // Update transaction status to sent
                output(outputSendTransactionOnwards($message),'SILENT');
                $response = json_decode(send($message['receiver_address'], $payload),true);     
                if($response['status'] === 'accepted'){
                    updateTransactionStatus($txid,'accepted',true); // Update transaction status to accepted
                } elseif($response['status'] === 'rejected'){
                    updateP2pRequestStatus($memo,'cancelled'); // Update transaction status to cancelled
                    updateTransactionStatus($txid,'rejected',true); // Update transaction status to rejected
                }
                output(outputTransactionResponse($response),'SILENT');
            } else{
                // If receiving transaction
                if(!matchYourselfTransaction($message,resolveUserAddressForTransport($message['sender_address']))) {
                    // If not end-recipient of transaction
                    updateTransactionStatus($memo,'accepted'); // Update received transaction status to accepted
                    
                    // Splice old transaction into new transaction for sending onwards
                    $message['amount'] = removeTransactionFee($message); // Remove my transaction fee
                    $rp2p = checkRp2pExists($memo);
                    $message['time'] = $rp2p['time'];
                    $message['txid'] = createUniqueDatabaseTxid($message); // Create new txid for new Transaction
                    $message['receiver_address'] = $rp2p['sender_address']; // Send new transaction onwards to sender of rp2p
                    $message['receiver_public_key'] = $rp2p['sender_public_key'];
                    $message['previous_txid'] = fixPreviousTxid($user['public'], $message['receiver_public_key']);
                    $payload = buildSendDatabasePayload($message); 
                    $insertTransactionResponse = json_decode(insertTransaction($payload),true); // Insert to be sent onwards Transaction as pending     
                    output(outputTransactionInsertion($insertTransactionResponse));
                } else{
                    // If end-recipient of transaction
                    updateP2pRequestStatus($memo,'completed',true); // Update p2p status to completed
                    updateTransactionStatus($memo,'completed'); // Update transaction status to completed
                    output(outputTransactionAmountReceived($message),'SILENT');
                    $payloadTransactionCompleted = buildSendCompletedPayload($message);
                    output(outputSendTransactionCompletionMessageMemo($message),'SILENT');
                    $response = send($message['sender_address'],$payloadTransactionCompleted); 
                }
            }   
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
        
    // Check if any contacts for eIOU
    if(!retrieveContactAddresses()){
        output(outputNoContactsForTransaction($request));
        exit(0);
    }

    // If receiver's public key is in contacts, prepare a transaction to send directly to them
    if ($contactInfo = lookupContactInfo($request[2])) {
        output(outputLookedUpContactInfo($contactInfo), 'SILENT');
        $data = prepareSendData($request);
        $data['receiverAddress'] = $contactInfo['receiverAddress'];
        $data['receiverPublicKey'] = $contactInfo['receiverPublicKey'];
        
        $data['txid'] = createUniqueTxid($data);
        $data['previousTxid'] = fixPreviousTxid($user['public'], $contactInfo['receiverPublicKey']);
        
        // Prepare transaction payload
        $payload = buildSendPayload($data);
        
        insertTransaction($payload); // Insert transaction as pending       
        output(outputSendTransaction($payload));
    } else {
        output(outputContactNotFoundTryP2p($request), 'SILENT');
        sendP2pRequest($request);
        output(outputSendP2p($request));
    }
}

function sendP2pEiou($request) {
    global $user;
    output("Getting ready to send P2p eIOU with memo: " . print_r($request['memo'], true),'SILENT');

    // Send transaction back to rp2p sender
    $request['receiverAddress'] = $request['senderAddress'];
    $request['receiverPublicKey'] = $request['senderPublicKey'];

    $request['txid'] = hash('sha256', $user['public'] . $request['receiverPublicKey'] . $request['amount'] . $request['time']);
    $request['previousTxid'] = getPreviousTxid($user['public'], $request['receiverPublicKey']);

    // Prepare transaction payload
    $payload = buildSendPayload($request);
    insertTransaction($payload); // Insert transaction as pending
}

function viewBalances($data) {
    // View balance information based on transactions
    global $pdo, $user;
    $query = "SELECT sender_address, receiver_address, amount, currency, timestamp FROM transactions";
    // Check if an address or name is provided
    if (isset($data[2])) {
        // Check if it's a HTTP or Tor address
        if (isHttpAddress($data[2]) || isTorAddress($data[2])) {
            $address = $data[2];
        } else{
             // Check if the name yields an address
            $contactResult = lookupContactByName($data[2]);
            $address = $contactResult['address'] ?? null;
        }
        // Add WHERE clause if a valid address is found
        if ($address) {
            $query .= " WHERE sender_address = :address OR receiver_address = :address ORDER BY timestamp DESC";
        } else{
            echo "Address/Name unknown, displaying all balances";
            $query .= " ORDER BY timestamp DESC";   
        }    
    }

    $balances = [];
    $stmt = $pdo->prepare($query);
    
    if (isset($data[2]) && $address) {
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
        printf("\t%s (%s), Balance: %.2f\n", $contact['name'], $contact['address'], $contact['balance']);
    }
}
