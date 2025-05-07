<?php
function handleRp2pRequest($request) {
    global $user;
    //output("handleRp2pRequest() triggered", 'SILENT');
 
    $result = getP2pByHash($request['hash']);
    if(!$result){
        throw new Exception('P2P request was not found for the given hash.');
    }else{
        if(isset($result['destination_address'])) {
            //update p2p before saving rp2p otherwise it will try sending an rp2p (which will go to itself) before the transaction
            //output("Updated hash: " . $request['hash'] . " to status 'found'");
            updateP2pRequestStatus($request['hash'], 'found');
        }
        // Save rp2p response 
        $insertResult = insertRp2pRequest($request);
        if (!$insertResult) {
            output("Failed to insert rp2p request: " . print_r($request, true), 'SILENT');
            output("Failed rp2p insert result: " . print_r($insertResult, true), 'SILENT');
            return false;
        }

        if(isset($result['destination_address'])) {
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
                sendP2pEiou($result);
            } else {
                output("I reject the fee, ignore really. it will expire. or is there something else i can do?", 'SILENT');
            }
        }
    }
}


function processQueuedRP2pMessages() {
    global $user;
    // Select queued messages from the p2p table with sent status
    $queuedMessages = retrieveQueuedP2pMessages($status = 'sent');

    //echo "Found " . count($queuedMessages) . " sent messages to check\n";

    // Process each queued message
    foreach ($queuedMessages as $message) {
        // Check if the message hash exists in the rp2p table
        $rP2pResult = checkRP2pExists($message['hash']);
        // If matching rp2p found, echo forwarding message
        if ($rP2pResult) {
            output("Found rp2p match for hash: " . $message['hash'], 'SILENT');
            $rP2pPayload = buildRP2pPayload($rP2pResult);
            updateP2pRequestStatus($message['hash'], 'found');
            $response = json_decode(send($message['sender_address'], $rP2pPayload),true);
            output("RP2P response status: " . print_r($response,true),'SILENT');
            // Update the p2p request status to found

        }
    }
}