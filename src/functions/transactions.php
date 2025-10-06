<?php
# Copyright 2025

function checkPreviousTxid($request){
    // If a previous transaction exists, verify the previousTxid matches
    if (isset($request['previousTxid']) && $previousTxResult = getPreviousTxid($request['senderPublicKey'], $request['receiverAddress'])) {
        if ($previousTxResult !== $request['previousTxid']) {
            echo buildInvalidTransactionIDPayload($previousTxResult,$request);
            return false;
        }
    }
    return true;
}

function checkAvailableFundsTransaction($request){
    // Check if there is enough funds to complete the transaction
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
        echo buildInsufficientBalancePayload($availableFunds, $requiredAmount, $creditLimit, 0,$request['currency']);
        return false;
    }   
}

function fixPreviousTxid($senderPubKey,$receiverPubKey){
    // Make sure that the previous transactions txid in the chain is not already being used as a previous_txid for another transaction
    $prevID = getPreviousTxid($senderPubKey, $receiverPubKey);
    while(getExistingPreviousTxid($prevID)){
        $prevID = getPreviousTxid($senderPubKey, $receiverPubKey);
        usleep(1000); // Sleep for 1ms
    }
    return $prevID;
}

function createUniqueTxid($data){
    // Create Txid for transactions
    global $user;

    $txid = hash('sha256', $user['public'] . $data['receiverPublicKey'] . $data['amount'] . $data['time']);
    return $txid;
}

function createUniqueDatabaseTxid($data){
    // Create unique Txid for transactions (from database values)
    global $user;
    $txid = hash('sha256', $user['public'] . $data['receiver_public_key'] . $data['amount'] . $data['time']);
    return $txid;
}

function prepareStandardTransactionData($request,$contactInfo) {
    // Prepare initial data payload for direct transaction
    global $user;

    output(outputPrepareSendData($request), 'SILENT');
    $data['txType'] = 'standard';
    $data['time'] = returnMicroTime();
    $data['amount'] = round($request[3] * 100); // Convert to cents
    $data['currency'] = $request[4] ?? 'USD'; // Get currency or default to USD
    $data['memo'] = 'standard';

    // Additional data preparation
    $data['receiverAddress'] = $contactInfo['receiverAddress'];
    $data['receiverPublicKey'] = $contactInfo['receiverPublicKey'];
    $data['txid'] = createUniqueTxid($data);
    $data['previousTxid'] = fixPreviousTxid($user['public'], $contactInfo['receiverPublicKey']);

    return $data;
}

function prepareP2pTransactionData($request) {
    // Prepare data for p2p transaction
    global $user;

    $data['time'] = $request['time'];

     // Send transaction back to rp2p sender
    $data['receiverAddress'] = $request['senderAddress'];
    $data['receiverPublicKey'] = $request['senderPublicKey'];

    $data['amount'] = $request['amount'];
    $data['currency'] = $request['currency'];
    $data['txid'] = createUniqueTxid($data);
    $data['previousTxid'] = fixPreviousTxid($user['public'], $request['senderPublicKey']);
    $data['memo'] = $request['hash'];
    return $data;
}

function processTransaction($request) {
    // Process incoming transactions
    global $user;
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
    // Process pending transactions in database
    // Select pending messages from the transaction table (with status pending)
    $pendingMessages = retrievePendingTransactionMessages();
    // Process each pending message
    foreach ($pendingMessages as $message) {   
        $memo = $message['memo'];  
        $txid = $message['txid'];
        // If direct transaction
        if($memo === 'standard'){
            if($message['sender_address'] == resolveUserAddressForTransport($message['sender_address'])){
                $payload = buildSendDatabasePayload($message);
                updateTransactionStatus($txid,'sent',true);  // Update transaction status to sent
                $response = json_decode(send($message['receiver_address'], $payload),true);
                output(outputTransactionInquiryResponse($response),'SILENT');
                if($response['status'] === 'accepted'){
                    updateTransactionStatus($txid,'accepted',true); // Update transaction status to accepted
                } elseif($response['status'] === 'rejected'){
                    updateTransactionStatus($txid,'rejected',true); // Update transaction status to rejected
                    output(outputIssueTransactionTryP2p($response),'SILENT'); 
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
                updateTransactionStatus($memo,'sent'); // Update transaction status to sent
                output(outputSendTransactionOnwards($message),'SILENT');
                $response = json_decode(send($message['receiver_address'], $payload),true);     
                if($response['status'] === 'accepted'){
                    updateTransactionStatus($txid,'accepted'); // Update transaction status to accepted
                } elseif($response['status'] === 'rejected' ){ //&& $response['reason'] != 'already exists'
                    updateP2pRequestStatus($memo,'cancelled'); // Update transaction status to cancelled
                    updateTransactionStatus($memo,'rejected'); // Update transaction status to rejected
                }
                output(outputTransactionResponse($response),'SILENT');
            } else{
                // If receiving transaction
                if(!matchYourselfTransaction($message,resolveUserAddressForTransport($message['sender_address']))) {
                    // If not end-recipient of transaction
                    updateTransactionStatus($memo,'accepted'); // Update received transaction status to accepted
                    updateIncomingP2pTxid($message['memo'], $message['txid']); // Update incoming_txid of p2p to match received transaction


                    // Create new transaction, from received prior transaction, for sending onwards to sender of rp2p
                    $data = buildForwardingTransactionPayload($message);
                    updateOutgoingP2pTxid($data['memo'], $data['txid']); // Update outgoing_txid of p2p to match transaction to be sent

                    $payload = buildSendDatabasePayload($data); 
                    $insertTransactionResponse = json_decode(insertTransaction($payload),true); // Insert to be sent onwards Transaction as pending     
                    output(outputTransactionInsertion($insertTransactionResponse));
                } else{
                    // If end-recipient of transaction
                    updateP2pRequestStatus($memo,'completed',true); // Update p2p status to completed
                    updateTransactionStatus($memo,'completed'); // Update transaction status to completed
                    updateIncomingP2pTxid($message['memo'], $message['txid']); // Update incoming_txid of p2p to match received transaction
                    output(outputTransactionAmountReceived($message),'SILENT');
                    $payloadTransactionCompleted = buildSendCompletedPayload($message);
                    output(outputSendTransactionCompletionMessageMemo($message),'SILENT');
                    $response = send($message['sender_address'],$payloadTransactionCompleted); 
                }
            }   
        }  
    }
}

function sendEiou($request = null) {
    // Handler for sending eIOU through user Input

    // If no request is provided, use $data as a fallback
    if ($request === null) {
        global $data;
        $request = $data;
    }
    //output(outputEiouSend($request), 'SILENT');
    # Check if request is correctly formatted
    if(!validateSendRequest($request)){
        exit(0);
    }
        
    // Check if any contacts for eIOU
    if(!retrieveContactAddresses()){
        output(outputNoContactsForTransaction($request));
        exit(0);
    }

    // If receiver's public key is in contacts, prepare a transaction to send directly to them
    if ($contactInfo = lookupContactInfo($request[2])) {
        output(outputLookedUpContactInfo($contactInfo), 'SILENT');
        // Data preparation for eIOU
        $data = prepareStandardTransactionData($request,$contactInfo);
     
        // Prepare transaction payload from data
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
    // Handler for sending transactions upon succesfully receiving route to end-recipient

    output(outputP2pEiouSend($request),'SILENT');

    // Create data to send back to rp2p sender
    $data = prepareP2pTransactionData($request);

    // Prepare transaction payload
    $payload = buildSendPayload($data);
    insertTransaction($payload); // Insert transaction as pending

    updateOutgoingP2pTxid($data['memo'], $data['txid']); // Update outgoing_txid of p2p to match transaction
}

