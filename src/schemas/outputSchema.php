<?php
# Copyright 2025

function outputAdressContactIssue($address){
    return "No contact with supplied address: " . $address." exists \n";
}

function outputAdressOrContactIssue($data){
    return "Not an address nor existing contact with name: " . $data[2]."\n";
}

function outputBuildingP2pPayload($data){
    return "Building P2p payload: " . print_r($data, true)."\n";
}

function outputBuildingRp2pPayload($data){
    return "Building rP2p payload: " . print_r($data, true)."\n";
}

function outputBuildingTransactionPayload($data){
    return "Building Transaction payload: " . print_r($data, true)."\n";
}

function outputCalculatedContactHash($contactHash){
    return "Calculated contact hash: " . $contactHash."\n";
}

function outputContactMatched($contactHash){
    return "Contact matched with hash: " . $contactHash."\n";
}

function outputContactSuccesfullySynched($address){
    return "Contact " . $address . " was succesfully synched.\n";
}

function outputContactNoResponseSynch(){
    return "Contact did not respond to synch request immediately.\n";
}

function outputContactNoNeedSynch($address){
    return "Contact " . $address . " has no need for synching as it's already an accepted contact.\n";
}

function outputContactNotFoundTryP2p($request){
    return "Contact not found, trying p2p with data: " . print_r($request, true)."\n";
}

function outputContactRequestWasAccepted($address){
    return "Contact request was accepted by " . $address ."\n";
}

function outputContactUnblockedAndAdded(){
    return "Contact was unblocked and name/credit/fee/currency information was added upon acceptance.\n";
}

function outputContactUnblockedAndAddedFailure(){
    return "Contact could not be unblocked and no name/credit/fee/currency information was added.\n";
}

function outputContactUnblockedAndOverwritten(){
    return "Contact was unblocked and name/credit/fee/currency information was overwritten.\n";
}

function outputContactUnblockedAndOverwrittenFailure(){
    return "Contact could not be unblocked and no name/credit/fee/currency information was overwritten.\n";
}

function outputEiouSend($request){
    return "Getting ready to send eIOU with request: " . print_r($request, true)."\n";
}

function outputFailedContactRequest($payload){
    return "Failed contact request payload: ". print_r($payload, true)."\n";
}

function outputFeeInformation($feePercent,$request,$maxFee){
    return "They want a fee of " . $feePercent . " percent, for transaction with hash " . $request['hash'] .  ", my max fee is " . $maxFee . " percent\n";
}

function outputFeeRejection(){
    return "I reject the fee, transaction will be ignored and it will expire.\n";
}

function outputFoundRp2pMatch($message){
    return "Found rp2p match for hash: " . $message['hash']."\n";
}

function outputGeneratedP2pHash($hash){
    return "Generated p2pHash: " . $hash."\n";
}

function outputHandleTransactionMessageResponse($decodedMessage){
    return "Responding to Transaction message inquiry from: " . $decodedMessage['senderAddress']."\n";
}

function outputInsertedP2p($request){
    return "Inserted P2P with hash: " .print_r($request['hash'],true)."\n";
}

function outputInsertedRp2p($request){
    return "Inserted RP2P with hash: " .print_r($request['hash'],true)."\n";
}

function outputInsertedTransactionMemo($request){
    return "Inserted Transaction with memo: " .print_r($request['memo'],true)."\n";
}

function outputInsertedTransactionTxid($request){
    return "Inserted Transaction with txid: " .print_r($request['txid'],true)."\n";
}

function outputInsertingP2pRequest($address){
    return "Inserting p2p request with receiver address: " . $address."\n";
}

function outputIssueTransactionTryP2p($response){
    return "Direct Transaction not succesfull, trying P2P. Error: " . print_r($response,true)."\n";
}

function outputLookedUpContactInfo($contactInfo){
    return "Looked up contact info: " . print_r($contactInfo, true)."\n";
}

function outputNoSuppliedAddress(){
    return "No address was supplied.\n";
}

function outputNoContactsForTransaction($request){
    return "No contacts exist in database for transaction.\n";
}

function outputPrepareP2pData($request){
    return "Prepare send p2p data: " . print_r($request, true)."\n";
}

function outputPrepareSendData($request){
    return "Prepare send data: " . print_r($request, true)."\n";
}
function outputReceiverAddressNotSet($request){
    return "$request[2] (receiverAddress) is not set: " . print_r($request, true)."\n";
}

function outputP2pComponents($data){
    return "p2pHash components: " . "receiverAddress: " . $data['receiverAddress'] . ", salt: " . $data['salt'] . ", time: " . $data['time']."\n";
}

function outputP2pEiouSend($request){
    return "Getting ready to send P2p eIOU with hash: " . print_r($request['hash'], true)."\n";
}

function outputP2pExpired($message){
    return "P2P request with hash: " . $message['hash'] . " has expired\n";
}

function outputP2pResponse($response){
    return "Received P2P message response: " . print_r($response,true)."\n";
}

function outputP2pSendResult($response){
    return "P2P send result for matched contact: " . print_r($response,true)."\n";
}

function outputP2pStatusUpdated($status,$hash){
    return "Updated status to '" . $status . "' for p2p hash: " . $hash."\n";
}

function outputSendP2PToAmountContacts($contactsCount){
    return "Sent Peer to peer request to " . $contactsCount['tor'] . " tor contacts and " . $contactsCount['http'] . " http(s) contacts.\n";
}

function outputP2pUnableToAffordRp2p($result,$request){
    return "P2P sender cannot afford RP2P with " . $result['my_fee_amount'] . " " . $result['currency'] . " worth of fees added: " . print_r($request, true)."\n";
}

function outputResponseTransactionTimes($httpExpectedResponseTime,$torExpectedResponseTime){
    return "You should expect a response within " . $httpExpectedResponseTime . " seconds for http and " . $torExpectedResponseTime . " seconds for tor.\n"; 
}

function outputRp2pInsertionFailure($request){
    return "Failed to insert rp2p request: " . print_r($request, true)."\n";
}

function outputRp2pTransactionResponse($response){
    return "Transaction (RP2P) send response: " . print_r($response, true)."\n";  
}

function outputRp2pResponse($response){
    return "RP2P response: " . print_r($response,true)."\n";
}

function outputSendContactAcceptedSuccesfullyMessage($address){
    return "Sending Contact accepted succesfully message to " . $address."\n";
}

function outputSendP2p($request){
    return "Sending " . $request[3] . " " . $request[4] . " to " . $request[2]." via routing through your network of contacts!\n";
}

function outputSendTransaction($payload){
    return "Sending " . $payload['amount']/Constants::TRANSACTION_USD_CONVERSION_FACTOR . " " . $payload['currency'] . " to " . $payload['receiverAddress']." via direct transaction!\n";
}

function outputSendTransactionCompletionMessageMemo($message){
    return "Sending Transaction completion of message with memo " . $message['memo'] . " to " . $message['sender_address']."\n";
}

function outputSendTransactionCompletionMessageOnwards($payloadTransactionCompleted,$senderAddress){
    return "Sending Transaction completion message onwards " . print_r($payloadTransactionCompleted,true) . " to " . $senderAddress."\n";
}

function outputSendTransactionCompletionMessageTxid($message){
    return "Sending Transaction completion of message with txid " . $message['txid'] . " to " . $message['sender_address']."\n";
}

function outputSendTransactionOnwards($message){
    return "Sending Transaction onwards to: " . $message['receiver_address']."\n";
}

function outputSynchContactDueToPendingStatus($address){
    return "Contact " . $address . " is being synched due to pending contact request status\n";
}

function outputTransactionAmountReceived($message){
    return "Received " . $message['amount']/Constants::TRANSACTION_USD_CONVERSION_FACTOR . " " . $message['currency'] . " from " . $message['sender_address']."\n";
}

function outputTransactionExpired($message){
    return "Transaction request with hash: " . $message['hash'] . " has expired\n";
}

function outputTransactionInsertion($insertTransactionResponse){
    return "Inserting Transaction response: " . print_r($insertTransactionResponse, true)."\n";
}

function outputTransactionInquiryResponse($response){
    return "Transaction Inquiry response: " . print_r($response, true)."\n";
}

function outputTransactionP2pSentSuccesfully($p2p){
    return "Sent " . $p2p['amount']/Constants::TRANSACTION_USD_CONVERSION_FACTOR . " " . $p2p['currency'] . " to " . $p2p['destination_address'] . " succesfully\n";
}

function outputTransactionDirectSentSuccesfully($data){
    return "Sent " . $data['amount']/Constants::TRANSACTION_USD_CONVERSION_FACTOR . " " . $data['currency'] . " to " . $data['senderAddress'] . " succesfully\n";
}

function outputTransactionStatusUpdated($status,$typeTransaction,$memo){
    return "Updated status to '" . $status . "' for transaction of type $typeTransaction: " . $memo."\n";
}

function outputTransactionResponse($response){
    return "Received transaction message response: " . print_r($response,true)."\n";
}

function outputUpdatedTxid($txid,$which_txid,$hash){
    return "Updated " . $which_txid . " to " . $txid . " for p2p with hash " . $hash ."\n";
}