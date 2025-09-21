<?php
# Copyright 2025

function handleRp2pRequest($request) {
    // Handler for incoming rp2p messages
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
                sendP2pEiou($request); // Send transaction through rp2p chain
            } else {
                output(outputFeeRejection(), 'SILENT');
            }
        } else{
            // Send rp2p messages onwards to sender of p2p
            $rP2pPayload = buildRp2pPayload($request); // Build rp2p payload
            updateP2pRequestStatus($request['hash'], 'found'); // Update the p2p request status to found
            $response = json_decode(send($p2p['sender_address'], $rP2pPayload),true);
            output(outputRp2pResponse($response),'SILENT');
        }
    }
}