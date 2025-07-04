<?php

function outputFoundRp2pMatch($message){
    return "Found rp2p match for hash: " . $message['hash'];
}

function outputGeneratedP2pHash($data){
    return "Generated p2pHash: " . $data['hash'];
}

function outputInsertingP2pRequest($address){
    return "Inserting p2p request with receiver address: " . $address;
}

function outputLookedUpContactInfo($contactInfo){
    return "Looked up contact info: " . print_r($contactInfo, true);
}

function outputPrepareP2pData($request){
    return "Prepare send p2p data: " . print_r($request, true);
}

function outputReceiverAddressNotSet($request){
    return "$request[2] (receiverAddress) is not set: " . print_r($request, true);
}

function outputP2pComponents($data){
    return "p2pHash components: " . "receiverAddress: " . $data['receiverAddress'] . ", salt: " . $data['salt'] . ", time: " . $data['time'];
}

function outputP2pResponse($response){
    return "Received P2P message response: " . print_r($response,true);
}

function outputP2pSendResult($response){
    return "P2P send result for matched contact: " . print_r($response,true);
}

function outputRp2pInsertionFailure($request){
    return "Failed to insert rp2p request: " . print_r($request, true);
}

function outputRp2pTransactionResponse($request){
    return "Transaction (RP2P) send response: " . print_r($response, true);  
}

function outputRp2pResponse($response){
    return "RP2P response: " . print_r($response,true);
}

function outputSendTransactionCompletionMessageMemo($message){
    return "Sending Transaction completion of message with memo " . $message['memo'] . " to " . $message['sender_address'];
}

function outputSendTransactionCompletionMessageTxid($message){
    return "Sending Transaction completion of message with txid " . $message['txid'] . " to " . $message['sender_address'];
}

function outputSendTransactionOnwards($message){
    return "Sending Transaction onwards to: " . $message['receiver_address'];
}
function outputTransactionResponse($response){
    return "Received transaction message response: " . print_r($response,true);
}






function outputContactMatched($contactHash){
    return "Contact matched with hash: " . $contactHash;
}

function outputP2pExpired($message){
    return "P2P request with hash: " . $message['hash'] . " has expired";
}

function outputIssueTransactionTryP2p($response){
    return "Direct Transaction not succesfull, trying P2P. Error: " . print_r($reponse,true);
}

function outputP2pUnableToAffordRp2p($result,$request){
    return "P2P sender cannot afford RP2P with " . $result['my_fee_amount'] . " " . $result['currency'] . " worth of fees added: " . print_r($request, true);
}

function outputFeeInformation($feePercent,$request,$maxFee){
    return "They want a fee of " . $feePercent . " percent, for transaction with hash " . $request['hash'] .  ", my max fee is " . $maxFee . " percent";
}

function outputFeeRejection(){
    return "I reject the fee, ignore really. it will expire. or is there something else i can do?";
}

function outputContactNotFoundTryP2p($request){
    return "Contact not found, trying p2p with data: " . print_r($request, true);
}



function outputAdressOrContactIssue($data){
    return "Not an address nor existing contact with name: " . $data[2];
}

function outputTransactionSentSuccesfully($data){
    return "Sent " . $data['amount'] . " to " . $data['receiver_address'];
}

function outputSendP2PToAmountContacts($contactsCount){
    return "Sent Peer to peer request to " . $contactsCount['tor'] . " tor contacts and " . $contactsCount['http'] . " http(s) contacts.";
}

function outputResponseTransactionTimes($httpExpectedResponseTime,$torExpectedResponseTime){
    return "You should expect a response within " . $httpExpectedResponseTime . " seconds for http and " . $torExpectedResponseTime . " seconds for tor."; 
}

function outputBuildingRp2pPayload($data){
    return "Building rP2p payload: " . print_r($data, true);
}

function outputBuildingP2pPayload($data){
    return "Building P2p payload: " . print_r($data, true);
}

function outputBuildingTransactionPayload($data){
    return "Building Transaction payload: " . print_r($data, true);
}

function outputCalculateContactHash($contact,$request){
    return "Calculating contact hash: address=" . $contact['address'] . ", salt=" . $request['salt'] . ", time=" . $request['time'];
}

function outputCalculatedContactHash($contactHash){
    return "Calculated contact hash: " . $contactHash;
}

function outputInsertedP2p($request){
    return "Inserted P2P with hash: " .print_r($request['hash'],true);
}

function outputInsertedRp2p($request){
    return "Inserted RP2P with hash: " .print_r($request['hash'],true);
}

function outputInsertedTransactionMemo($request){
    return "Inserted Transaction with memo: " .print_r($request['memo'],true);
}

function outputInsertedTransactionTxid($request){
    return "Inserted Transaction with txid: " .print_r($request['txid'],true);
}

function outputP2pStatusUpdated($status,$hash){
    return "Updated status to '" . $status . "' for p2p hash: " . $hash;
}

function outputUpdatedTxid($txid,$what,$hash){
    return "Updated " . $what . " to " . $txid . " for hash " . $hash;
}

function outputTransactionStatusUpdated($status,$what,$memo){
    return "Updated status to '" . $status . "' for transaction $what: " . $memo;
}

