<?php
# Copyright 2025

function expireMessage($message){
    // Expire the p2p request
    if(time() > $message['expiration']){
        updateP2pRequestStatus($message['hash'], 'expired');
        output("P2P request with hash: " . print_r($message['hash'],true) . " has expired",'SILENT');
    }
}

function handleRp2pRequest($request) {
    global $user;
    
    // Check if corresponding p2p exists 
    $result = getP2pByHash($request['hash']);
    if(!$result){
        throw new Exception('P2P request was not found for the given hash.');
    }else{
        if(isset($result['destination_address'])) {
            updateP2pRequestStatus($request['hash'], 'found');
        }
        // Save rp2p response 
        $insertResult = insertRp2pRequest($request);
        if (!$insertResult) {
            output("Failed to insert rp2p request: " . print_r($request, true), 'SILENT');
            return false;
        }

        // Check if original p2p was sent by user
        if(isset($result['destination_address'])) {
            $p2pAmount = $result['amount'];
            $rP2pAmount = $request['amount'];
            $feeAmount = $rP2pAmount - $p2pAmount;
            $feePercent = ($feeAmount / $p2pAmount) * 100;
            output("They want a fee of " . $feePercent . " percent, for transaction with hash " . $request['hash'] .  ", my max fee is " . $user['maxFee'], 'SILENT');
            
            // Check if the fee percent is below the set maximum fee percent the user would pay
            if ($feePercent <= $user['maxFee']) {
                $result['amount'] = $request['amount'];
                $result['currency'] = $request['currency'];
                $result['senderPublicKey'] = $request['senderPublicKey'];
                $result['senderAddress'] = $request['senderAddress'];
                $result['memo'] = $request['hash'];
                sendP2pEiou($result); // Send transaction
            } else {
                output("I reject the fee, ignore really. it will expire. or is there something else i can do?", 'SILENT');
            }
        }
    }
}


function processQueuedRP2pMessages() {
    global $user;
    // Select queued messages from the p2p table with sent status
    $queuedMessages = retrieveQueuedP2pMessages($status = 'sent', $status2 = 'paid');

    //echo "Found " . count($queuedMessages) . "p2p 'sent' messages to check\n";

    // Process each 'sent' or 'paid' message
    foreach ($queuedMessages as $message) {
        // Is message  'sent' or 'paid'
        if($message['status'] == 'sent'){
            // Check if the message hash exists in the rp2p table
            $rP2pResult = checkRP2pExists($message['hash']);
            // If matching rp2p found for 'sent' message, echo forwarding message, otherwise check if p2p is expired
            if ($rP2pResult) {
                output("Found rp2p match for hash: " . $message['hash'], 'SILENT');
                $rP2pPayload = buildRP2pPayload($rP2pResult); // Build rp2p payload
                updateP2pRequestStatus($message['hash'], 'found'); // Update the p2p request status to found
                $response = json_decode(send($message['sender_address'], $rP2pPayload),true);
                output("RP2P response: " . print_r($response,true),'SILENT');
            } elseif(time() > $message['expiration']){
                // If no response after set amount of time, expire the p2p request
                expireMessage($message);
            }              
        } elseif(time() > $message['expiration']){
            // If no response after set amount of time, expire the p2p request
            expireMessage($message);
        }              
    }
}