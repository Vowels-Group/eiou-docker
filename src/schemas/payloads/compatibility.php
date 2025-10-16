<?php
/**
 * Backward Compatibility Layer for Payload Builders
 *
 * This file provides wrapper functions that maintain the old procedural API
 * while using the new class-based payload builders internally.
 *
 * @deprecated These functions are deprecated. Use the payload classes directly.
 *
 * Migration Path:
 * Old: $payload = createContactPayload();
 * New: $userContext = UserContext::getInstance();
 *      $builder = new ContactPayload($userContext);
 *      $payload = $builder->createContact();
 */

require_once __DIR__ . '/../../core/UserContext.php';
require_once __DIR__ . '/BasePayload.php';
require_once __DIR__ . '/ContactPayload.php';
require_once __DIR__ . '/MessagePayload.php';
require_once __DIR__ . '/P2pPayload.php';
require_once __DIR__ . '/Rp2pPayload.php';
require_once __DIR__ . '/TransactionPayload.php';
require_once __DIR__ . '/UtilPayload.php';

// ====================================================================================
// CONTACT PAYLOAD WRAPPERS (3 functions)
// ====================================================================================

/**
 * @deprecated Use ContactPayload::createContact() directly
 */
function createContactPayload() {
    $userContext = UserContext::getInstance();
    $builder = new ContactPayload($userContext);
    return $builder->createContact();
}

/**
 * @deprecated Use ContactPayload::buildAccepted() directly
 */
function buildContactIsAcceptedPayload($address) {
    $userContext = UserContext::getInstance();
    $builder = new ContactPayload($userContext);
    return $builder->buildAccepted($address);
}

/**
 * @deprecated Use ContactPayload::buildAlreadyExists() directly
 */
function buildContactAlreadyExistsPayload() {
    $userContext = UserContext::getInstance();
    $builder = new ContactPayload($userContext);
    return $builder->buildAlreadyExists();
}

// ====================================================================================
// MESSAGE PAYLOAD WRAPPERS (6 functions)
// ====================================================================================

/**
 * @deprecated Use MessagePayload::buildContactAcceptedInquiry() directly
 */
function buildMessageContactIsAcceptedInquiryPayload($address) {
    $userContext = UserContext::getInstance();
    $builder = new MessagePayload($userContext);
    return $builder->buildContactAcceptedInquiry($address);
}

/**
 * @deprecated Use MessagePayload::buildContactAccepted() directly
 */
function buildMessageContactIsAcceptedPayload($address) {
    $userContext = UserContext::getInstance();
    $builder = new MessagePayload($userContext);
    return $builder->buildContactAccepted($address);
}

/**
 * @deprecated Use MessagePayload::buildContactNotYetAccepted() directly
 */
function buildMessageContactIsNotYetAcceptedPayload($address) {
    $userContext = UserContext::getInstance();
    $builder = new MessagePayload($userContext);
    return $builder->buildContactNotYetAccepted($address);
}

/**
 * @deprecated Use MessagePayload::buildContactUnknown() directly
 */
function buildMessageContactIsUnknownPayload($address) {
    $userContext = UserContext::getInstance();
    $builder = new MessagePayload($userContext);
    return $builder->buildContactUnknown($address);
}

/**
 * @deprecated Use MessagePayload::buildTransactionCompletedCorrectly() directly
 */
function buildMessageTransactionCompletedCorrectlyPayload($message) {
    $userContext = UserContext::getInstance();
    $builder = new MessagePayload($userContext);
    return $builder->buildTransactionCompletedCorrectly($message);
}

/**
 * @deprecated Use MessagePayload::buildTransactionCompletedInquiry() directly
 */
function buildMessageTransactionCompletedInquiryPayload($message) {
    $userContext = UserContext::getInstance();
    $builder = new MessagePayload($userContext);
    return $builder->buildTransactionCompletedInquiry($message);
}

// ====================================================================================
// P2P PAYLOAD WRAPPERS (5 functions)
// ====================================================================================

/**
 * @deprecated Use P2pPayload::buildP2p() directly
 */
function buildP2pPayload($data) {
    $userContext = UserContext::getInstance();
    $builder = new P2pPayload($userContext);
    return $builder->buildP2p($data);
}

/**
 * @deprecated Use P2pPayload::buildP2pFromDatabase() directly
 */
function buildP2pPayloadDatabase($data) {
    $userContext = UserContext::getInstance();
    $builder = new P2pPayload($userContext);
    return $builder->buildP2pFromDatabase($data);
}

/**
 * @deprecated Use P2pPayload::buildAcceptance() directly
 */
function buildP2pAcceptancePayload($request) {
    $userContext = UserContext::getInstance();
    $builder = new P2pPayload($userContext);
    return $builder->buildAcceptance($request);
}

/**
 * @deprecated Use P2pPayload::buildRejection() directly
 */
function buildP2pRejectionPayload($request) {
    $userContext = UserContext::getInstance();
    $builder = new P2pPayload($userContext);
    return $builder->buildRejection($request);
}

// ====================================================================================
// RP2P PAYLOAD WRAPPERS (3 functions)
// ====================================================================================

/**
 * @deprecated Use Rp2pPayload::buildRp2p() directly
 */
function buildRp2pPayload($data) {
    $userContext = UserContext::getInstance();
    $builder = new Rp2pPayload($userContext);
    return $builder->buildRp2p($data);
}

/**
 * @deprecated Use Rp2pPayload::buildAcceptance() directly
 */
function buildRp2pAcceptancePayload($request) {
    $userContext = UserContext::getInstance();
    $builder = new Rp2pPayload($userContext);
    return $builder->buildAcceptance($request);
}

/**
 * @deprecated Use Rp2pPayload::buildRejection() directly
 */
function buildRp2pRejectionPayload($request) {
    $userContext = UserContext::getInstance();
    $builder = new Rp2pPayload($userContext);
    return $builder->buildRejection($request);
}

// ====================================================================================
// TRANSACTION PAYLOAD WRAPPERS (8 functions)
// ====================================================================================

/**
 * @deprecated Use TransactionPayload::buildSend() directly
 */
function buildSendPayload($data) {
    $userContext = UserContext::getInstance();
    $builder = new TransactionPayload($userContext);
    return $builder->buildSend($data);
}

/**
 * @deprecated Use TransactionPayload::buildSendFromDatabase() directly
 */
function buildSendDatabasePayload($data) {
    $userContext = UserContext::getInstance();
    $builder = new TransactionPayload($userContext);
    return $builder->buildSendFromDatabase($data);
}

/**
 * @deprecated Use TransactionPayload::buildForwardingTransaction() directly
 */
function buildForwardingTransactionPayload($message) {
    $userContext = UserContext::getInstance();
    $builder = new TransactionPayload($userContext);
    return $builder->buildForwardingTransaction($message);
}

/**
 * @deprecated Use TransactionPayload::buildAcceptance() directly
 */
function buildSendAcceptancePayload($request) {
    $userContext = UserContext::getInstance();
    $builder = new TransactionPayload($userContext);
    return $builder->buildAcceptance($request);
}

/**
 * @deprecated Use TransactionPayload::buildCompleted() directly
 */
function buildSendCompletedPayload($request) {
    $userContext = UserContext::getInstance();
    $builder = new TransactionPayload($userContext);
    return $builder->buildCompleted($request);
}

/**
 * @deprecated Use TransactionPayload::buildRejection() directly
 */
function buildSendRejectionPayload($request) {
    $userContext = UserContext::getInstance();
    $builder = new TransactionPayload($userContext);
    return $builder->buildRejection($request);
}

// ====================================================================================
// UTILITY PAYLOAD WRAPPERS (4 functions)
// ====================================================================================

/**
 * @deprecated Use UtilPayload::buildInsufficientBalance() directly
 */
function buildInsufficientBalancePayload($availableFunds, $requestedAmount, $creditLimit, $fundsOnHold) {
    $userContext = UserContext::getInstance();
    $builder = new UtilPayload($userContext);
    return $builder->buildInsufficientBalance($availableFunds, $requestedAmount, $creditLimit, $fundsOnHold);
}

/**
 * @deprecated Use UtilPayload::buildInvalidTransactionID() directly
 */
function buildInvalidTransactionIDPayload($previousTxResult, $request) {
    $userContext = UserContext::getInstance();
    $builder = new UtilPayload($userContext);
    return $builder->buildInvalidTransactionID($previousTxResult, $request);
}

/**
 * @deprecated Use UtilPayload::buildInvalidRequestLevel() directly
 */
function buildInvalidRequestLevelPayload($request) {
    $userContext = UserContext::getInstance();
    $builder = new UtilPayload($userContext);
    return $builder->buildInvalidRequestLevel($request);
}

/**
 * @deprecated Use UtilPayload::buildMessageInvalidSource() directly
 */
function buildMessageInvalidSourcePayload($message) {
    $userContext = UserContext::getInstance();
    $builder = new UtilPayload($userContext);
    return $builder->buildMessageInvalidSource($message);
}
