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
    $result = getP2pByHash($request['hash']);
    if(!$result){
        throw new Exception('P2P request was not found for the given hash.');
    }else{
        if(isset($result['destination_address'])) {
            updateP2pRequestStatus($request['hash'], 'found');
        }
        // Add users fee to request
        $request['amount'] += $result['my_fee_amount'];


        //Check if intermediary sender of p2p can afford to send eIOU with fees
        if(!isset($result['destination_address'])) {
            $availableFunds = calculateAvailableFunds($result);
            if($availableFunds < $request['amount']){
                output(outputP2pUnableToAffordRp2p($result,$request), 'SILENT');
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
        if(isset($result['destination_address'])) {
            $p2pAmount = $result['amount'];
            $rP2pAmount = $request['amount'];
            $feeAmount = $rP2pAmount - $p2pAmount;
            $feePercent = ($feeAmount / $p2pAmount) * 100;
            output(outputFeeInformation($feePercent,$request,$user['maxFee']), 'SILENT');
            
            // Check if the fee percent is below the set maximum fee percent the user would pay
            if ($feePercent <= $user['maxFee']) {
                $result['amount'] = $request['amount'];
                $result['currency'] = $request['currency'];
                $result['senderPublicKey'] = $request['senderPublicKey'];
                $result['senderAddress'] = $request['senderAddress'];
                $result['memo'] = $request['hash'];
                sendP2pEiou($result); // Send transaction
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
        // Is message  'sent' or 'paid'
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
            // If no response after set amount of time, expire the p2p request
            expireMessage($message);
        }              
    }
}