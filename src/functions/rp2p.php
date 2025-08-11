<?php
# Copyright 2025

function expireMessage($message){
    // Expire the p2p request
    updateP2pRequestStatus($message['hash'], 'expired');
    output(outputP2pExpired($message),'SILENT');
}

function handleRp2pRequest($request) {
    global $user;
    
    // Check if corresponding p2p exists 
    $p2p = getP2pByHash($request['hash']);
    if(!$p2p){
        throw new Exception('P2P request was not found for the given hash.');
    }else{
        if(isset($p2p['destination_address'])) {
            updateP2pRequestStatus($request['hash'], 'found');
        }
        // Add users fee to request
        $request['amount'] += $p2p['my_fee_amount'];

        //Check if intermediary sender of p2p can afford to send eIOU with fees
        if(!isset($p2p['destination_address'])) {
            $availableFunds = calculateAvailableFunds($p2p);
            if($availableFunds < $request['amount']){
                output(outputP2pUnableToAffordRp2p($p2p,$request), 'SILENT');
                return false;
            }
        }

        // Save rp2p response 
        $insertResult = insertRp2pRequest($request);
        if (!$insertResult) {
            output(outputRp2pInsertionFailure($request), 'SILENT');
            return false;
        }
        // Check if original p2p was sent by user
        if(isset($p2p['destination_address'])) {
            $feePercent = feeInformation($p2p,$request); // Get fee percent and output fee information in  log
            
            // Check if the fee percent is below the set maximum fee percent the user would pay
            if ($feePercent <= $user['maxFee']) {

                // TO DO: CREATE NEW PAYLOAD (overwriting will yield potential issues)
                $p2p['amount'] = $request['amount'];
                $p2p['currency'] = $request['currency'];
                $p2p['senderPublicKey'] = $request['senderPublicKey'];
                $p2p['senderAddress'] = $request['senderAddress'];
                $p2p['memo'] = $request['hash'];
                sendP2pEiou($p2p); // Send transaction
            } else {
                output(outputFeeRejection(), 'SILENT');
            }
        }
    }
}

function processQueuedRp2pMessages() {
    // Select queued messages from the p2p table with sent status
    $queuedMessages = retrieveQueuedP2pMessages($status = 'sent', $status2 = 'paid');

    // Process each 'sent' or 'paid' message
    foreach ($queuedMessages as $message) {
        // Is message 'sent' or 'paid'
        if($message['status'] === 'sent'){
            // Check if the message hash exists in the rp2p table
            $rP2pResult = checkRp2pExists($message['hash']);
            // If matching rp2p found for 'sent' message, echo forwarding message, otherwise check if p2p is expired
            if ($rP2pResult) {
                output(outputFoundRp2pMatch($message), 'SILENT');
                $rP2pPayload = buildRp2pPayload($rP2pResult); // Build rp2p payload
                updateP2pRequestStatus($message['hash'], 'found'); // Update the p2p request status to found
                $response = json_decode(send($message['sender_address'], $rP2pPayload),true);
                output( outputRp2pResponse($response),'SILENT');
            } elseif(time() > $message['expiration']){
                // If no response after set amount of time, expire the p2p request
                expireMessage($message);
            }              
        } elseif(time() > $message['expiration']){
            // If no response after set amount of time on messages with status 'paid', expire the p2p request
            expireMessage($message);
        }              
    }
}