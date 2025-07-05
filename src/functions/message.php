<?php
# Copyright 2025


function checkMessageValidity($decodedMessage){
    // Check if message from a valid source
    if(retrieveContactQuery($decodedMessage['senderAddress'])){
        // The source is a contact
        return true;
    } elseif(isset($decodedMessage['hash'])){
            $hash = $decodedMessage['hash'];
            $p2p = getP2pByHash($hash);
            if($p2p){
                // Check if source is original sender for any messages related to transactions
                if($hash === hash('sha256', resolveUserAddressForTransport($decodedMessage['senderAddress']) . $p2p['salt'] . $p2p['time'])){
                    return true;
                } 
                return false;
            } 
            // Potential Spam (hash is unknown)
            // TO DO: handle different message types in future versions
            return false;
    }
    // Not a contact nor able to match source
    return false;
}

function handleMessageRequest($message){
    // TO DO: build a lock that cannot be sent more than x messages in a span of time, rest ignore untill handled previous (counter potential spam / overload)

    $decodedMessage = json_decode($message['message'],true);
    // Check if message is from a known or logical source
    if(!checkMessageValidity($decodedMessage)){
        echo buildMessageInvalidSourcePayload($message);
        exit();
    }

    if($decodedMessage['typeMessage'] === "transaction"){
        if(isset($decodedMessage['inquiry']) && $decodedMessage['inquiry']){
            handleTransactionMessageInquiryRequest($decodedMessage);
        } else{
            handleTransactionMessageRequest($decodedMessage);
        }    
    }   
}

function handleTransactionMessageInquiryRequest($decodedMessage){
    // Handle inquiry about transaction status
    echo buildSendCompletedCorrectlyPayload($decodedMessage);
}


// TO DO: Check what happens if someone says txid when it's not txid
// odd thought if say txid is actually the hash but it's not for this transaction (since lucky guess)

function handleTransactionMessageRequest($decodedMessage){
    // Handle incoming transaction messages
    $hash = $decodedMessage['hash']; // for direct transaction is equivalent to txid, otherwise equivalent to memo
    if($decodedMessage['status'] === 'completed'){
        // check if hash exists for p2p and check if hash exists for transaction
        if($decodedMessage['hashType'] === 'memo'){
            $p2p = getP2pByHash($hash);
            $transaction = getTransactionByMemo($hash);

            if($p2p && $transaction){
                // Check if user was original sender of transaction
                if(isset($p2p['destination_address'])){
                    // Send direct message inquiry to end recipient double checking if completion of transaction correct 
                    $completedTransactionInquiry = buildSendCompletedInquiryPayload($decodedMessage);
                    $response = json_decode(send($p2p['destination_address'],$completedTransactionInquiry),true);
                    output(outputTransactionInquiryRepsonse($repsone),'SILENT');
                    if($response['status'] === 'completed'){
                        updateP2pRequestStatus($hash,'completed',true); // Update p2p status to completed
                        updateTransactionStatus($hash,'completed'); // Update transaction status to completed
                        echo outputTransactionSentSuccesfully($p2p);
                    }
                } else{
                    updateP2pRequestStatus($hash,'completed',true); // Update p2p status to completed
                    updateTransactionStatus($hash,'completed'); // Update transaction status to completed
                    // Send transaction completion message onwards
                    $payloadTransactionCompleted = buildSendCompletedPayload($decodedMessage);
                    output(outputSendTransactionCompletionMessageOnwards($payloadTransactionCompleted,$p2p['sender_address']),'SILENT');
                    $response = send($p2p['sender_address'],$payloadTransactionCompleted);
                }
            }
        } elseif($decodedMessage['hashType'] === 'txid'){
            // End recipient (contact) sent us direct confirmation, thus transaction completed succesfully
            $transaction = getTransactionByTxid($hash);
            if($transaction){
                updateTransactionStatus($hash,'completed',true); // Update transaction status to completed
                echo outputTransactionSentSuccesfully($decodedMessage);
            }
        }        
    } 
}