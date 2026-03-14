<?php
# Copyright 2025-2026 Vowels Group, LLC

use Eiou\Core\Constants;

// ============================================================================
// CONTACT SERVICE OUTPUT FUNCTIONS
// ============================================================================

function outputAddressContactIssue(string $address): string {
    return "[Contact] No contact with supplied address: " . $address." exists \n";
}

function outputAddressOrContactIssue(array $data): string {
    return "[Contact] Not an address nor existing contact with name: " . $data[2]."\n";
}

function outputCalculatedContactHash(string $contactHash): string {
    return "[Contact] Calculated contact hash: " . $contactHash."\n";
}

function outputContactMatched(string $contactHash): string {
    return "[Contact] Matched with hash: " . $contactHash."\n";
}

function outputContactSuccessfullySynced(string $address): string {
    return "[Contact] " . $address . " was successfully synced.\n";
}

function outputContactNoResponseSync(): string {
    return "[Contact] Did not respond to sync request immediately.\n";
}

function outputContactNoNeedSync(string $address): string {
    return "[Contact] " . $address . " has no need for syncing as it's already an accepted contact.\n";
}

function outputContactNotFoundTryP2p(array $request): string {
    return "[Contact] Not found, trying p2p with data: " . json_encode($request, JSON_UNESCAPED_SLASHES)."\n";
}

function outputContactRequestWasAccepted(string $address): string {
    return "[Contact] Request was accepted by " . $address ."\n";
}

function outputContactBlockedNoTransaction(): string {
    return "[Contact] Is blocked and transaction will not be sent.\n";
}

function outputContactUnblockedAndAdded(): string {
    return "[Contact] Was unblocked and name/credit/fee/currency information was added upon acceptance.\n";
}

function outputContactUnblockedAndAddedFailure(): string {
    return "[Contact] Could not be unblocked and no name/credit/fee/currency information was added.\n";
}

function outputContactUnblockedAndOverwritten(): string {
    return "[Contact] Was unblocked and name/credit/fee/currency information was overwritten.\n";
}

function outputContactUnblockedAndOverwrittenFailure(): string {
    return "[Contact] Could not be unblocked and no name/credit/fee/currency information was overwritten.\n";
}

function outputContactUpdatedAddress(): string {
    return "[Contact] Was updated succesfully with new address.\n";
}

function outputContactUpdatedAddressFailure(): string {
    return "[Contact] Could not be updated with new address.\n";
}

function outputFailedContactInteraction(): string {
    return "[Contact] Request address does not exist at the current time (or is unable to respond, due to downtime), Please try again later.\n";
}

function outputFailedContactRequest(array $payload): string {
    return "[Contact] Failed request payload: ". json_encode($payload, JSON_UNESCAPED_SLASHES)."\n";
}

function outputLookedUpContactInfo(array $contactInfo): string {
    return "[Contact] Looked up info: " . json_encode($contactInfo, JSON_UNESCAPED_SLASHES)."\n";
}

function outputSendContactAcceptedSuccesfullyMessage(string $address): string {
    return "[Contact] Sending acceptance message to " . $address."\n";
}

function outputSyncContactDueToPendingStatus(string $address): string {
    return "[Contact] " . $address . " is being synced due to pending contact request status\n";
}

// ============================================================================
// TRANSACTION SERVICE OUTPUT FUNCTIONS
// ============================================================================

function outputBuildingTransactionPayload(array $data): string {
    return "[Transaction] Building payload: " . json_encode($data, JSON_UNESCAPED_SLASHES)."\n";
}

function outputEiouSend(array $request): string {
    return "[Transaction] Getting ready to send eIOU with request: " . json_encode($request, JSON_UNESCAPED_SLASHES)."\n";
}

function outputFeeInformation(string $feePercent, array $request, string $maxFee): string {
    return "[Transaction] They want a fee of " . $feePercent . " percent, for transaction with hash " . $request['hash'] .  ", my max fee is " . $maxFee . " percent.\n";
}

function outputFeeRejection(): string {
    return "[Transaction] I reject the fee, transaction will be ignored and it will expire.\n";
}

function outputHandleTransactionMessageResponse(array $decodedMessage): string {
    return "[Transaction] Responding to inquiry from: " . $decodedMessage['senderAddress']."\n";
}

function outputInsertedTransactionMemo(array $request): string {
    return "[Transaction] Inserted with memo: " .json_encode($request['memo'], JSON_UNESCAPED_SLASHES)."\n";
}

function outputInsertedTransactionTxid(array $request): string {
    return "[Transaction] Inserted with txid: " .json_encode($request['txid'], JSON_UNESCAPED_SLASHES)."\n";
}

function outputIssueTransactionTryP2p(array $response): string {
    return "[Transaction] Direct not succesfull, trying P2P. Error: " . json_encode($response, JSON_UNESCAPED_SLASHES)."\n";
}

function outputNoViableTransportAddress(): string {
    return "[Transaction] No viable transport address for sending could be determined.\n";
}

function outputNoViableTransportMode(): string {
    return "[Transaction] No viable transport mode for sending could be determined.\n";
}

function outputNoContactsForTransaction(array $request): string {
    return "[Transaction] No contacts exist in database for transaction.\n";
}

function outputPrepareSendData(array $request): string {
    return "[Transaction] Prepare send data: " . json_encode($request, JSON_UNESCAPED_SLASHES)."\n";
}

function outputReceiverAddressNotSet(array $request): string {
    return "[Transaction] $request[2] (receiverAddress) is not set: " . json_encode($request, JSON_UNESCAPED_SLASHES)."\n";
}

function outputResponseTransactionTimes(int $httpExpectedResponseTime, int $torExpectedResponseTime): string {
    return "[Transaction] You should expect a response within " . $httpExpectedResponseTime . " seconds for http and " . $torExpectedResponseTime . " seconds for tor.\n";
}

function outputSendTransaction(array $payload): string {
    return "[Transaction] Sending " . $payload['amount']/Constants::getConversionFactor($payload['currency']) . " " . $payload['currency'] . " to " . $payload['receiverAddress']." via direct transaction!\n";
}

function outputSendTransactionCompletionMessageMemo(array $message): string {
    return "[Transaction] Sending completion of message with memo " . $message['memo'] . " to " . $message['sender_address']."\n";
}

function outputSendTransactionCompletionMessageOnwards(array $payloadTransactionCompleted, string $senderAddress): string {
    return "[Transaction] Sending completion message onwards " . json_encode($payloadTransactionCompleted, JSON_UNESCAPED_SLASHES) . " to " . $senderAddress."\n";
}

function outputSendTransactionCompletionMessageTxid(array $message): string {
    return "[Transaction] Sending completion of message with txid " . $message['txid'] . " to " . $message['sender_address']."\n";
}

function outputSendTransactionOnwards(array $message): string {
    return "[Transaction] Sending onwards to: " . $message['receiver_address']."\n";
}

function outputTransactionAmountReceived(array $message): string {
    return "[Transaction] Received " . $message['amount']/Constants::getConversionFactor($message['currency']) . " " . $message['currency'] . " from " . $message['sender_address']."\n";
}

function outputTransactionExpired(array $message): string {
    return "[Transaction] Request with hash: " . $message['hash'] . " has expired\n";
}

function outputTransactionInsertion(array $insertTransactionResponse): string {
    return "[Transaction] Inserting response: " . json_encode($insertTransactionResponse, JSON_UNESCAPED_SLASHES)."\n";
}

function outputTransactionInquiryResponse(array $response): string {
    return "[Transaction] Inquiry response: " . json_encode($response, JSON_UNESCAPED_SLASHES)."\n";
}

function outputTransactionP2pSentSuccesfully(array $p2p): string {
    return "[Transaction] Sent " . $p2p['amount']/Constants::getConversionFactor($p2p['currency']) . " " . $p2p['currency'] . " to " . $p2p['destination_address'] . " succesfully\n";
}

function outputTransactionDirectSentSuccesfully(array $data): string {
    return "[Transaction] Sent " . $data['amount']/Constants::getConversionFactor($data['currency']) . " " . $data['currency'] . " to " . $data['senderAddress'] . " succesfully\n";
}

function outputTransactionDescriptionUpdated(string $description, string $typeTransaction, string $memo): string {
    return "[Transaction] Updated description to '" . $description . "' for transaction of type $typeTransaction: " . $memo."\n";
}

function outputTransactionStatusUpdated(string $status, string $typeTransaction, string $memo): string {
    return "[Transaction] Updated status to '" . $status . "' for transaction of type $typeTransaction: " . $memo."\n";
}

function outputTransactionResponse(array $response): string {
    return "[Transaction] Received message response: " . json_encode($response, JSON_UNESCAPED_SLASHES)."\n";
}

// ============================================================================
// P2P SERVICE OUTPUT FUNCTIONS
// ============================================================================

function outputBuildingP2pPayload(array $data): string {
    return "[P2P] Building payload: " . json_encode($data, JSON_UNESCAPED_SLASHES)."\n";
}

function outputGeneratedP2pHash(string $hash): string {
    return "[P2P] Generated hash: " . $hash."\n";
}

function outputInsertedP2p(array $request): string {
    return "[P2P] Inserted with hash: " .json_encode($request['hash'], JSON_UNESCAPED_SLASHES)."\n";
}

function outputInsertingP2pRequest(string $address): string {
    return "[P2P] Inserting request with receiver address: " . $address."\n";
}

function outputNoViableRouteP2p(string $hash): string {
    return "[P2P] No viable route for forwarding message with hash " . $hash . "\n";
}

function outputPrepareP2pData(array $request): string {
    return "[P2P] Prepare send data: " . json_encode($request, JSON_UNESCAPED_SLASHES)."\n";
}

function outputP2pComponents(array $data): string {
    return "[P2P] Hash components: " . "receiverAddress: " . $data['receiverAddress'] . ", salt: " . $data['salt'] . ", time: " . $data['time']."\n";
}

function outputP2pEiouSend(array $request): string {
    return "[P2P] Getting ready to send eIOU with hash: " . json_encode($request['hash'], JSON_UNESCAPED_SLASHES)."\n";
}

function outputP2pExpired(array $message): string {
    return "[P2P] Request with hash: " . $message['hash'] . " has expired\n";
}

function outputP2pResponse(array $response): string {
    return "[P2P] Received message response: " . json_encode($response, JSON_UNESCAPED_SLASHES)."\n";
}

function outputP2pSendResult(array $response): string {
    return "[P2P] Send result for matched contact: " . json_encode($response, JSON_UNESCAPED_SLASHES)."\n";
}

function outputP2pStatusUpdated(string $status, string $hash): string {
    return "[P2P] Updated status to '" . $status . "' for hash: " . $hash."\n";
}

function outputP2pDescriptionUpdated(string $description, string $hash): string {
    return "[P2P] Updated description to '" . $description . "' for hash:" . $hash."\n";
}

function outputSendP2PToAmountContacts(int $contactsCount): string {
    return "[P2P] Sent peer to peer request to " . $contactsCount . " contacts.\n";
}

function outputSendP2p(array $request): string {
    return "[P2P] Sending " . $request[3] . " " . $request[4] . " to " . $request[2]." via routing through your network of contacts!\n";
}

// ============================================================================
// RP2P SERVICE OUTPUT FUNCTIONS
// ============================================================================

function outputBuildingRp2pPayload(array $data): string {
    return "[RP2P] Building payload: " . json_encode($data, JSON_UNESCAPED_SLASHES)."\n";
}

function outputFoundRp2pMatch(array $message): string {
    return "[RP2P] Found match for hash: " . $message['hash']."\n";
}

function outputInsertedRp2p(array $request): string {
    return "[RP2P] Inserted with hash: " .json_encode($request['hash'], JSON_UNESCAPED_SLASHES)."\n";
}

function outputP2pUnableToAffordRp2p(array $result, array $request): string {
    return "[RP2P] P2P sender cannot afford with " . $result['my_fee_amount'] . " " . $result['currency'] . " worth of fees added: " . json_encode($request, JSON_UNESCAPED_SLASHES)."\n";
}

function outputRp2pInsertionFailure(array $request): string {
    return "[RP2P] Failed to insert request: " . json_encode($request, JSON_UNESCAPED_SLASHES)."\n";
}

function outputRp2pTransactionResponse(array $response): string {
    return "[RP2P] Transaction send response: " . json_encode($response, JSON_UNESCAPED_SLASHES)."\n";
}

function outputRp2pResponse(array $response): string {
    return "[RP2P] Response: " . json_encode($response, JSON_UNESCAPED_SLASHES)."\n";
}

function outputUpdatedTxid(string $txid, string $which_txid, string $hash): string {
    return "[RP2P] Updated " . $which_txid . " to " . $txid . " for p2p with hash " . $hash ."\n";
}

// ============================================================================
// VALIDATION OUTPUT FUNCTIONS
// ============================================================================

function outputNoSuppliedAddress(): string {
    return "[Validation] No address was supplied.\n";
}

// ============================================================================
// MESSAGE DELIVERY OUTPUT FUNCTIONS
// ============================================================================

function outputMessageDeliveryCreated(string $messageType, string $messageId, string $recipientAddress): string {
    return "[MessageDelivery] Created: type=" . $messageType . ", id=" . $messageId . ", recipient=" . $recipientAddress . "\n";
}

function outputMessageDeliveryStageUpdated(string $messageType, string $messageId, string $previousStage, string $newStage): string {
    return "[MessageDelivery] Stage updated: type=" . $messageType . ", id=" . $messageId . ", stage=" . $previousStage . " -> " . $newStage . "\n";
}

function outputMessageDeliveryRetry(string $messageType, string $messageId, int $retryCount, int $maxRetries, int $delaySeconds): string {
    return "[MessageDelivery] Retry scheduled: type=" . $messageType . ", id=" . $messageId . ", attempt=" . $retryCount . "/" . $maxRetries . ", delay=" . $delaySeconds . "s\n";
}

function outputMessageDeliveryCompleted(string $messageType, string $messageId): string {
    return "[MessageDelivery] Completed: type=" . $messageType . ", id=" . $messageId . "\n";
}

function outputMessageDeliveryFailed(string $messageType, string $messageId, string $reason): string {
    return "[MessageDelivery] Failed: type=" . $messageType . ", id=" . $messageId . ", reason=" . $reason . "\n";
}

function outputMessageDeliveryMovedToDlq(string $messageType, string $messageId, int $retryCount): string {
    return "[MessageDelivery] Moved to DLQ: type=" . $messageType . ", id=" . $messageId . ", retries=" . $retryCount . "\n";
}

function outputMessageDeliveryQueuedForRetry(string $messageType, string $messageId, int $maxRetries): string {
    return "[MessageDelivery] Queued for background retry: type=" . $messageType . ", id=" . $messageId . ", max_retries=" . $maxRetries . "\n";
}

function outputDeadLetterQueueRetry(int $dlqId, string $messageType, string $messageId): string {
    return "[DLQ] Retrying: dlq_id=" . $dlqId . ", type=" . $messageType . ", message_id=" . $messageId . "\n";
}

function outputDeadLetterQueueResolved(int $dlqId, string $messageType, string $messageId): string {
    return "[DLQ] Resolved: dlq_id=" . $dlqId . ", type=" . $messageType . ", message_id=" . $messageId . "\n";
}

// ============================================================================
// SYNC SERVICE OUTPUT FUNCTIONS
// ============================================================================

function outputSyncChainIntegrityFailed(int $gapCount): string {
    return "[Sync] Chain integrity check failed: " . $gapCount . " missing transactions. Triggering sync...\n";
}

function outputSyncChainRepaired(): string {
    return "[Sync] Chain sync completed. Chain is now valid.\n";
}

function outputSyncChainRepairedBeforeSend(): string {
    return "[Sync] Chain was repaired via sync before sending.\n";
}

function outputSyncTransactionsSynced(int $count): string {
    return "[Sync] Synced " . $count . " missing transactions.\n";
}

function outputSyncInlineRetryAttempt(): string {
    return "[Sync] Transaction rejected due to invalid_previous_txid, attempting inline retry...\n";
}

function outputSyncInlineRetrySuccess(): string {
    return "[Sync] Transaction re-signed with corrected previous_txid, will retry on next cycle...\n";
}

function outputSyncInlineRetryFailed(): string {
    return "[Sync] Inline retry failed, falling back to hold/sync...\n";
}

function outputSyncHoldingForSync(): string {
    return "[Sync] Transaction rejected due to invalid_previous_txid, holding for sync...\n";
}

function outputSyncHeld(): string {
    return "[Sync] Transaction held pending sync completion.\n";
}

function outputSyncFallbackP2p(): string {
    return "[Sync] Sync failed or no transactions to sync, falling back to P2P.\n";
}

function outputSyncP2pInlineRetryAttempt(): string {
    return "[Sync] P2P transaction rejected due to invalid_previous_txid, attempting inline retry...\n";
}

function outputSyncP2pInlineRetrySuccess(): string {
    return "[Sync] P2P transaction re-signed with corrected previous_txid, will retry...\n";
}

function outputSyncP2pHoldingForSync(): string {
    return "[Sync] P2P transaction rejected due to invalid_previous_txid, holding for sync...\n";
}

function outputSyncLocalChainState(int $count): string {
    return "[Sync] Local chain state: " . $count . " transactions.\n";
}

function outputSyncBidirectionalFallback(): string {
    return "[Sync] Remote doesn't support bidirectional sync, falling back to standard sync.\n";
}

function outputSyncBidirectionalMissing(int $localMissing, int $remoteMissing): string {
    return "[Sync] Bidirectional sync: we're missing " . $localMissing . ", they're missing " . $remoteMissing . " transactions.\n";
}

function outputSyncBidirectionalCompleted(int $received, int $sent): string {
    return "[Sync] Bidirectional sync completed: received " . $received . ", sent " . $sent . " transactions.\n";
}
