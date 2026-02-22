<?php
# Copyright 2025-2026 Vowels Group, LLC

use Eiou\Core\Constants;

// ============================================================================
// CONTACT SERVICE OUTPUT FUNCTIONS
// ============================================================================

function outputAdressContactIssue($address){
    return "[Contact] No contact with supplied address: " . $address." exists \n";
}

function outputAdressOrContactIssue($data){
    return "[Contact] Not an address nor existing contact with name: " . $data[2]."\n";
}

function outputCalculatedContactHash($contactHash){
    return "[Contact] Calculated contact hash: " . $contactHash."\n";
}

function outputContactMatched($contactHash){
    return "[Contact] Matched with hash: " . $contactHash."\n";
}

function outputContactSuccesfullysynced($address){
    return "[Contact] " . $address . " was successfully synced.\n";
}

function outputContactNoResponseSync(){
    return "[Contact] Did not respond to sync request immediately.\n";
}

function outputContactNoNeedSync($address){
    return "[Contact] " . $address . " has no need for syncing as it's already an accepted contact.\n";
}

function outputContactNotFoundTryP2p($request){
    return "[Contact] Not found, trying p2p with data: " . print_r($request, true)."\n";
}

function outputContactRequestWasAccepted($address){
    return "[Contact] Request was accepted by " . $address ."\n";
}

function outputContactBlockedNoTransaction(){
    return "[Contact] Is blocked and transaction will not be sent.\n";
}

function outputContactUnblockedAndAdded(){
    return "[Contact] Was unblocked and name/credit/fee/currency information was added upon acceptance.\n";
}

function outputContactUnblockedAndAddedFailure(){
    return "[Contact] Could not be unblocked and no name/credit/fee/currency information was added.\n";
}

function outputContactUnblockedAndOverwritten(){
    return "[Contact] Was unblocked and name/credit/fee/currency information was overwritten.\n";
}

function outputContactUnblockedAndOverwrittenFailure(){
    return "[Contact] Could not be unblocked and no name/credit/fee/currency information was overwritten.\n";
}

function outputContactUpdatedAddress(){
    return "[Contact] Was updated succesfully with new address.\n";
}

function outputContactUpdatedAddressFailure(){
    return "[Contact] Could not be updated with new address.\n";
}

function outputFailedContactInteraction(){
    return "[Contact] Request address does not exist at the current time (or is unable to respond, due to downtime), Please try again later.\n";
}

function outputFailedContactRequest($payload){
    return "[Contact] Failed request payload: ". print_r($payload, true)."\n";
}

function outputLookedUpContactInfo($contactInfo){
    return "[Contact] Looked up info: " . print_r($contactInfo, true)."\n";
}

function outputSendContactAcceptedSuccesfullyMessage($address){
    return "[Contact] Sending acceptance message to " . $address."\n";
}

function outputSyncContactDueToPendingStatus($address){
    return "[Contact] " . $address . " is being synced due to pending contact request status\n";
}

// ============================================================================
// TRANSACTION SERVICE OUTPUT FUNCTIONS
// ============================================================================

function outputBuildingTransactionPayload($data){
    return "[Transaction] Building payload: " . print_r($data, true)."\n";
}

function outputEiouSend($request){
    return "[Transaction] Getting ready to send eIOU with request: " . print_r($request, true)."\n";
}

function outputFeeInformation($feePercent,$request,$maxFee){
    return "[Transaction] They want a fee of " . $feePercent . " percent, for transaction with hash " . $request['hash'] .  ", my max fee is " . $maxFee . " percent.\n";
}

function outputFeeRejection(){
    return "[Transaction] I reject the fee, transaction will be ignored and it will expire.\n";
}

function outputHandleTransactionMessageResponse($decodedMessage){
    return "[Transaction] Responding to inquiry from: " . $decodedMessage['senderAddress']."\n";
}

function outputInsertedTransactionMemo($request){
    return "[Transaction] Inserted with memo: " .print_r($request['memo'],true)."\n";
}

function outputInsertedTransactionTxid($request){
    return "[Transaction] Inserted with txid: " .print_r($request['txid'],true)."\n";
}

function outputIssueTransactionTryP2p($response){
    return "[Transaction] Direct not succesfull, trying P2P. Error: " . print_r($response,true)."\n";
}

function outputNoViableTransportAddress(){
    return "[Transaction] No viable transport address for sending could be determined.\n";
}

function outputNoViableTransportMode(){
    return "[Transaction] No viable transport mode for sending could be determined.\n";
}

function outputNoContactsForTransaction($request){
    return "[Transaction] No contacts exist in database for transaction.\n";
}

function outputPrepareSendData($request){
    return "[Transaction] Prepare send data: " . print_r($request, true)."\n";
}

function outputReceiverAddressNotSet($request){
    return "[Transaction] $request[2] (receiverAddress) is not set: " . print_r($request, true)."\n";
}

function outputResponseTransactionTimes($httpExpectedResponseTime,$torExpectedResponseTime){
    return "[Transaction] You should expect a response within " . $httpExpectedResponseTime . " seconds for http and " . $torExpectedResponseTime . " seconds for tor.\n";
}

function outputSendTransaction($payload){
    return "[Transaction] Sending " . $payload['amount']/Constants::CONVERSION_FACTORS[$payload['currency']] . " " . $payload['currency'] . " to " . $payload['receiverAddress']." via direct transaction!\n";
}

function outputSendTransactionCompletionMessageMemo($message){
    return "[Transaction] Sending completion of message with memo " . $message['memo'] . " to " . $message['sender_address']."\n";
}

function outputSendTransactionCompletionMessageOnwards($payloadTransactionCompleted,$senderAddress){
    return "[Transaction] Sending completion message onwards " . print_r($payloadTransactionCompleted,true) . " to " . $senderAddress."\n";
}

function outputSendTransactionCompletionMessageTxid($message){
    return "[Transaction] Sending completion of message with txid " . $message['txid'] . " to " . $message['sender_address']."\n";
}

function outputSendTransactionOnwards($message){
    return "[Transaction] Sending onwards to: " . $message['receiver_address']."\n";
}

function outputTransactionAmountReceived($message){
    return "[Transaction] Received " . $message['amount']/Constants::CONVERSION_FACTORS[$message['currency']] . " " . $message['currency'] . " from " . $message['sender_address']."\n";
}

function outputTransactionExpired($message){
    return "[Transaction] Request with hash: " . $message['hash'] . " has expired\n";
}

function outputTransactionInsertion($insertTransactionResponse){
    return "[Transaction] Inserting response: " . print_r($insertTransactionResponse, true)."\n";
}

function outputTransactionInquiryResponse($response){
    return "[Transaction] Inquiry response: " . print_r($response, true)."\n";
}

function outputTransactionP2pSentSuccesfully($p2p){
    return "[Transaction] Sent " . $p2p['amount']/Constants::CONVERSION_FACTORS[$p2p['currency']] . " " . $p2p['currency'] . " to " . $p2p['destination_address'] . " succesfully\n";
}

function outputTransactionDirectSentSuccesfully($data){
    return "[Transaction] Sent " . $data['amount']/Constants::CONVERSION_FACTORS[$data['currency']] . " " . $data['currency'] . " to " . $data['senderAddress'] . " succesfully\n";
}

function outputTransactionDescriptionUpdated($description,$typeTransaction,$memo){
    return "[Transaction] Updated description to '" . $description . "' for transaction of type $typeTransaction: " . $memo."\n";
}

function outputTransactionStatusUpdated($status,$typeTransaction,$memo){
    return "[Transaction] Updated status to '" . $status . "' for transaction of type $typeTransaction: " . $memo."\n";
}

function outputTransactionResponse($response){
    return "[Transaction] Received message response: " . print_r($response,true)."\n";
}

// ============================================================================
// P2P SERVICE OUTPUT FUNCTIONS
// ============================================================================

function outputBuildingP2pPayload($data){
    return "[P2P] Building payload: " . print_r($data, true)."\n";
}

function outputGeneratedP2pHash($hash){
    return "[P2P] Generated hash: " . $hash."\n";
}

function outputInsertedP2p($request){
    return "[P2P] Inserted with hash: " .print_r($request['hash'],true)."\n";
}

function outputInsertingP2pRequest($address){
    return "[P2P] Inserting request with receiver address: " . $address."\n";
}

function outputNoViableRouteP2p($hash){
    return "[P2P] No viable route for forwarding message with hash " . $hash . "\n";
}

function outputPrepareP2pData($request){
    return "[P2P] Prepare send data: " . print_r($request, true)."\n";
}

function outputP2pComponents($data){
    return "[P2P] Hash components: " . "receiverAddress: " . $data['receiverAddress'] . ", salt: " . $data['salt'] . ", time: " . $data['time']."\n";
}

function outputP2pEiouSend($request){
    return "[P2P] Getting ready to send eIOU with hash: " . print_r($request['hash'], true)."\n";
}

function outputP2pExpired($message){
    return "[P2P] Request with hash: " . $message['hash'] . " has expired\n";
}

function outputP2pResponse($response){
    return "[P2P] Received message response: " . print_r($response,true)."\n";
}

function outputP2pSendResult($response){
    return "[P2P] Send result for matched contact: " . print_r($response,true)."\n";
}

function outputP2pStatusUpdated($status,$hash){
    return "[P2P] Updated status to '" . $status . "' for hash: " . $hash."\n";
}

function outputP2pDescriptionUpdated($description,$hash){
    return "[P2P] Updated description to '" . $description . "' for hash:" . $hash."\n";
}

function outputSendP2PToAmountContacts($contactsCount){
    return "[P2P] Sent peer to peer request to " . $contactsCount . " contacts.\n";
}

function outputSendP2p($request){
    return "[P2P] Sending " . $request[3] . " " . $request[4] . " to " . $request[2]." via routing through your network of contacts!\n";
}

// ============================================================================
// RP2P SERVICE OUTPUT FUNCTIONS
// ============================================================================

function outputBuildingRp2pPayload($data){
    return "[RP2P] Building payload: " . print_r($data, true)."\n";
}

function outputFoundRp2pMatch($message){
    return "[RP2P] Found match for hash: " . $message['hash']."\n";
}

function outputInsertedRp2p($request){
    return "[RP2P] Inserted with hash: " .print_r($request['hash'],true)."\n";
}

function outputP2pUnableToAffordRp2p($result,$request){
    return "[RP2P] P2P sender cannot afford with " . $result['my_fee_amount'] . " " . $result['currency'] . " worth of fees added: " . print_r($request, true)."\n";
}

function outputRp2pInsertionFailure($request){
    return "[RP2P] Failed to insert request: " . print_r($request, true)."\n";
}

function outputRp2pTransactionResponse($response){
    return "[RP2P] Transaction send response: " . print_r($response, true)."\n";
}

function outputRp2pResponse($response){
    return "[RP2P] Response: " . print_r($response,true)."\n";
}

function outputUpdatedTxid($txid,$which_txid,$hash){
    return "[RP2P] Updated " . $which_txid . " to " . $txid . " for p2p with hash " . $hash ."\n";
}

// ============================================================================
// VALIDATION OUTPUT FUNCTIONS
// ============================================================================

function outputNoSuppliedAddress(){
    return "[Validation] No address was supplied.\n";
}

// ============================================================================
// MESSAGE DELIVERY OUTPUT FUNCTIONS
// ============================================================================

function outputMessageDeliveryCreated($messageType, $messageId, $recipientAddress){
    return "[MessageDelivery] Created: type=" . $messageType . ", id=" . $messageId . ", recipient=" . $recipientAddress . "\n";
}

function outputMessageDeliveryStageUpdated($messageType, $messageId, $previousStage, $newStage){
    return "[MessageDelivery] Stage updated: type=" . $messageType . ", id=" . $messageId . ", stage=" . $previousStage . " -> " . $newStage . "\n";
}

function outputMessageDeliveryRetry($messageType, $messageId, $retryCount, $maxRetries, $delaySeconds){
    return "[MessageDelivery] Retry scheduled: type=" . $messageType . ", id=" . $messageId . ", attempt=" . $retryCount . "/" . $maxRetries . ", delay=" . $delaySeconds . "s\n";
}

function outputMessageDeliveryCompleted($messageType, $messageId){
    return "[MessageDelivery] Completed: type=" . $messageType . ", id=" . $messageId . "\n";
}

function outputMessageDeliveryFailed($messageType, $messageId, $reason){
    return "[MessageDelivery] Failed: type=" . $messageType . ", id=" . $messageId . ", reason=" . $reason . "\n";
}

function outputMessageDeliveryMovedToDlq($messageType, $messageId, $retryCount){
    return "[MessageDelivery] Moved to DLQ: type=" . $messageType . ", id=" . $messageId . ", retries=" . $retryCount . "\n";
}

function outputMessageDeliveryQueuedForRetry($messageType, $messageId, $maxRetries){
    return "[MessageDelivery] Queued for background retry: type=" . $messageType . ", id=" . $messageId . ", max_retries=" . $maxRetries . "\n";
}

function outputDeadLetterQueueRetry($dlqId, $messageType, $messageId){
    return "[DLQ] Retrying: dlq_id=" . $dlqId . ", type=" . $messageType . ", message_id=" . $messageId . "\n";
}

function outputDeadLetterQueueResolved($dlqId, $messageType, $messageId){
    return "[DLQ] Resolved: dlq_id=" . $dlqId . ", type=" . $messageType . ", message_id=" . $messageId . "\n";
}

// ============================================================================
// SYNC SERVICE OUTPUT FUNCTIONS
// ============================================================================

function outputSyncChainIntegrityFailed($gapCount){
    return "[Sync] Chain integrity check failed: " . $gapCount . " missing transactions. Triggering sync...\n";
}

function outputSyncChainRepaired(){
    return "[Sync] Chain sync completed. Chain is now valid.\n";
}

function outputSyncChainRepairedBeforeSend(){
    return "[Sync] Chain was repaired via sync before sending.\n";
}

function outputSyncTransactionsSynced($count){
    return "[Sync] Synced " . $count . " missing transactions.\n";
}

function outputSyncInlineRetryAttempt(){
    return "[Sync] Transaction rejected due to invalid_previous_txid, attempting inline retry...\n";
}

function outputSyncInlineRetrySuccess(){
    return "[Sync] Transaction re-signed with corrected previous_txid, will retry on next cycle...\n";
}

function outputSyncInlineRetryFailed(){
    return "[Sync] Inline retry failed, falling back to hold/sync...\n";
}

function outputSyncHoldingForSync(){
    return "[Sync] Transaction rejected due to invalid_previous_txid, holding for sync...\n";
}

function outputSyncHeld(){
    return "[Sync] Transaction held pending sync completion.\n";
}

function outputSyncFallbackP2p(){
    return "[Sync] Sync failed or no transactions to sync, falling back to P2P.\n";
}

function outputSyncP2pInlineRetryAttempt(){
    return "[Sync] P2P transaction rejected due to invalid_previous_txid, attempting inline retry...\n";
}

function outputSyncP2pInlineRetrySuccess(){
    return "[Sync] P2P transaction re-signed with corrected previous_txid, will retry...\n";
}

function outputSyncP2pHoldingForSync(){
    return "[Sync] P2P transaction rejected due to invalid_previous_txid, holding for sync...\n";
}

function outputSyncLocalChainState($count){
    return "[Sync] Local chain state: " . $count . " transactions.\n";
}

function outputSyncBidirectionalFallback(){
    return "[Sync] Remote doesn't support bidirectional sync, falling back to standard sync.\n";
}

function outputSyncBidirectionalMissing($localMissing, $remoteMissing){
    return "[Sync] Bidirectional sync: we're missing " . $localMissing . ", they're missing " . $remoteMissing . " transactions.\n";
}

function outputSyncBidirectionalCompleted($received, $sent){
    return "[Sync] Bidirectional sync completed: received " . $received . ", sent " . $sent . " transactions.\n";
}
