<?php
# Copyright 2025

/**
 * Service Wrappers
 *
 * Backward compatibility wrapper functions that bridge old procedural code
 * to new service-based architecture. These functions maintain the same
 * signatures as the original functions but delegate to service classes.
 *
 * This allows gradual migration without breaking existing code.
 *
 * @package Services
 */

require_once __DIR__ . '/ServiceContainer.php';

// ============================================================================
// CONTACT SERVICE WRAPPERS
// ============================================================================

/**
 * Add a contact (wrapper)
 *
 * @param array $data Command line arguments
 * @return void
 */
function addContact($data) {
    $service = ServiceContainer::getInstance()->getContactService();
    $service->addContact($data);
}

/**
 * Handle contact creation request (wrapper)
 *
 * @param array $request Request data
 * @return array Response payload
 */
function handleContactCreation($request) {
    $service = ServiceContainer::getInstance()->getContactService();
    return $service->handleContactCreation($request);
}

/**
 * Lookup contact information (wrapper)
 *
 * @param mixed $request Request data
 * @return array|null Contact information or null
 */
function lookupContactInfo($request) {
    $service = ServiceContainer::getInstance()->getContactService();
    return $service->lookupContactInfo($request);
}

/**
 * Search contacts (wrapper)
 *
 * @param array $data Command line arguments
 * @return void
 */
function searchContacts($data) {
    $service = ServiceContainer::getInstance()->getContactService();
    $service->searchContacts($data);
}

/**
 * View contact (wrapper)
 *
 * @param array $data Command line arguments
 * @return void
 */
function viewContact($data) {
    $service = ServiceContainer::getInstance()->getContactService();
    $service->viewContact($data);
}

// ============================================================================
// TRANSACTION SERVICE WRAPPERS
// ============================================================================

/**
 * Check previous transaction ID (wrapper)
 *
 * @param array $request Transaction request data
 * @return bool True if valid
 */
function checkPreviousTxid(array $request): bool {
    $service = ServiceContainer::getInstance()->getTransactionService();
    return $service->checkPreviousTxid($request);
}

/**
 * Check Transaction request prior existence (wrapper)
 *
 * @param array $request Transaction request data
 * @return bool True if found
 */
function checkPriorExistenceTransaction(array $request): bool {
    $service = ServiceContainer::getInstance()->getTransactionService();
    return $service->getPriorExistenceTransaction($request);
}


/**
 * Check available funds for transaction (wrapper)
 *
 * @param array $request Transaction request data
 * @return bool True if sufficient funds
 */
function checkAvailableFundsTransaction(array $request): bool {
    $service = ServiceContainer::getInstance()->getTransactionService();
    return $service->checkAvailableFundsTransaction($request);
}

/**
 * Fix previous transaction ID (wrapper)
 *
 * @param string $senderPubKey Sender's public key
 * @param string $receiverPubKey Receiver's public key
 * @return string|null Previous transaction ID
 */
function fixPreviousTxid($senderPubKey, $receiverPubKey) {
    $service = ServiceContainer::getInstance()->getTransactionService();
    return $service->fixPreviousTxid($senderPubKey, $receiverPubKey);
}

/**
 * Create unique transaction ID (wrapper)
 *
 * @param array $data Transaction data
 * @return string Transaction ID
 */
function createUniqueTxid(array $data): string {
    $service = ServiceContainer::getInstance()->getTransactionService();
    return $service->createUniqueTxid($data);
}

/**
 * Create unique database transaction ID (wrapper)
 *
 * @param array $data Database transaction data
 * @return string Transaction ID
 */
function createUniqueDatabaseTxid($data) {
    $service = ServiceContainer::getInstance()->getTransactionService();
    return $service->createUniqueDatabaseTxid($data);
}

/**
 * Prepare standard transaction data (wrapper)
 *
 * @param array $request Request data
 * @param array $contactInfo Contact information
 * @return array Prepared transaction data
 */
function prepareStandardTransactionData($request, $contactInfo) {
    $service = ServiceContainer::getInstance()->getTransactionService();
    return $service->prepareStandardTransactionData($request, $contactInfo);
}

/**
 * Prepare P2P transaction data (wrapper)
 *
 * @param array $request Request data
 * @return array Prepared transaction data
 */
function prepareP2pTransactionData($request) {
    $service = ServiceContainer::getInstance()->getTransactionService();
    return $service->prepareP2pTransactionData($request);
}

/**
 * Process transaction (wrapper)
 *
 * @param array $request Transaction request data
 * @return void
 */
function processTransaction(array $request): void {
    $service = ServiceContainer::getInstance()->getTransactionService();
    $service->processTransaction($request);
}

/**
 * Process pending transactions (wrapper)
 *
 * @return int Number of processed transactions
 */
function processPendingTransactions() {
    $service = ServiceContainer::getInstance()->getTransactionService();
    return $service->processPendingTransactions();
}

/**
 * Send eIOU (wrapper)
 *
 * @param array|null $request Request data
 * @return void
 */
function sendEiou($request = null) {
    $service = ServiceContainer::getInstance()->getTransactionService();
    $service->sendEiou($request);
}

/**
 * Send P2P eIOU (wrapper)
 *
 * @param array $request Request data
 * @return void
 */
function sendP2pEiou($request) {
    $service = ServiceContainer::getInstance()->getTransactionService();
    $service->sendP2pEiou($request);
}

// ============================================================================
// P2P SERVICE WRAPPERS
// ============================================================================

/**
 * Check P2P request level (wrapper)
 *
 * @param array $request P2P request data
 * @return bool True if valid
 */
function checkRequestLevel(array $request): bool {
    $service = ServiceContainer::getInstance()->getP2pService();
    return $service->checkRequestLevel($request);
}

/**
 * Check P2P request prior existence (wrapper)
 *
 * @param array $request P2P request data
 * @return bool True if found
 */
function checkPriorExistenceP2p(array $request): bool {
    $service = ServiceContainer::getInstance()->getP2pService();
    return $service->getPriorExistenceP2p($request);
}

/**
 * Check available funds for P2P (wrapper)
 *
 * @param array $request P2P request data
 * @return bool True if sufficient funds
 */
function checkAvailableFunds(array $request): bool {
    $service = ServiceContainer::getInstance()->getP2pService();
    return $service->checkAvailableFunds($request);
}

/**
 * Handle P2P request (wrapper)
 *
 * @param array $request P2P request data
 * @return void
 */
function handleP2pRequest(array $request): void {
    $service = ServiceContainer::getInstance()->getP2pService();
    $service->handleP2pRequest($request);
}

/**
 * Prepare P2P request data (wrapper)
 *
 * @param array $request Request data
 * @return array Prepared P2P data
 */
function prepareP2pRequestData(array $request): array {
    $service = ServiceContainer::getInstance()->getP2pService();
    return $service->prepareP2pRequestData($request);
}

/**
 * Prepare P2P request from failed transaction (wrapper)
 *
 * @param array $message Transaction message
 * @return array Prepared P2P data
 */
function prepareP2pRequestFromFailedTransactionData($message) {
    $service = ServiceContainer::getInstance()->getP2pService();
    return $service->prepareP2pRequestFromFailedTransactionData($message);
}

/**
 * Process queued P2P messages (wrapper)
 *
 * @return int Number of processed messages
 */
function processQueuedP2pMessages() {
    $service = ServiceContainer::getInstance()->getP2pService();
    return $service->processQueuedP2pMessages();
}

/**
 * Send P2P request (wrapper)
 *
 * @param array $data Request data
 * @return void
 */
function sendP2pRequest($data) {
    $service = ServiceContainer::getInstance()->getP2pService();
    $service->sendP2pRequest($data);
}

/**
 * Send P2P request from failed transaction (wrapper)
 *
 * @param array $message Transaction message
 * @return void
 */
function sendP2pRequestFromFailedDirectTransaction($message) {
    $service = ServiceContainer::getInstance()->getP2pService();
    $service->sendP2pRequestFromFailedDirectTransaction($message);
}

// ============================================================================
// RP2P SERVICE WRAPPERS
// ============================================================================

/**
 * Handle rp2p request (wrapper)
 *
 * @param array $request rp2p request
 * @return void
 */
function handleRp2pRequest($request) {
    $service = ServiceContainer::getInstance()->getRp2pService();
    $service->handleRp2pRequest($request);
}

// RP2P Repository Wrappers

/**
 * Check RP2P exists (wrapper)
 *
 * @param string $hash RP2P hash
 * @return array|null RP2P data or null
 */
function checkRp2pExists($hash) {
    $repo = ServiceContainer::getInstance()->getRp2pRepository();
    return $repo->getByHash($hash);
}

/**
 * Check RP2P request prior existence (wrapper)
 *
 * @param array $request RP2P request data
 * @return bool True if found
 */
function checkPriorExistenceRp2p(array $request): bool {
    $service = ServiceContainer::getInstance()->getRp2pService();
    return $service->getPriorExistenceRp2p($request);
}

/**
 * Insert RP2P request (wrapper)
 *
 * @param array $request RP2P request data
 * @return string JSON response
 */
function insertRp2pRequest($request) {
    $repo = ServiceContainer::getInstance()->getRp2pRepository();
    return $repo->insertRp2pRequest($request);
}



// ============================================================================
// WALLET SERVICE WRAPPERS
// ============================================================================

/**
 * Check if wallet exists (wrapper)
 *
 * @param array $user User data (deprecated - uses service container)
 * @param string $request Request type
 * @return void
 */
function checkWalletExists($user, $request) {
    $service = ServiceContainer::getInstance()->getWalletService();
    $service->checkWalletExists($request);
}

/**
 * Generate wallet (wrapper)
 *
 * @param array $argv Command line arguments
 * @return void
 */
function generateWallet($argv) {
    $service = ServiceContainer::getInstance()->getWalletService();
    $service->generateWallet($argv);
}

// ============================================================================
// MESSAGE SERVICE WRAPPERS
// ============================================================================

/**
 * Check message validity (wrapper)
 *
 * @param array $decodedMessage Decoded message data
 * @return bool True if valid
 */
function checkMessageValidity($decodedMessage) {
    $service = ServiceContainer::getInstance()->getMessageService();
    return $service->checkMessageValidity($decodedMessage);
}

/**
 * Handle message request (wrapper)
 *
 * @param array $message Message data
 * @return void
 */
function handleMessageRequest($message) {
    $service = ServiceContainer::getInstance()->getMessageService();
    $service->handleMessageRequest($message);
}

// ============================================================================
// REPOSITORY ACCESS HELPERS (for code that directly accesses repositories)
// ============================================================================

/**
 * Get contact by address (wrapper)
 *
 * @param string $address Contact address
 * @return array|null Contact data or null
 */
function retrieveContactQuery($address) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->getContactByAddress($address);
}

/**
 * Accept contact (wrapper)
 *
 * @param string $address Contact address
 * @param string $name Contact name
 * @param float $fee Fee percentage
 * @param float $credit Credit limit
 * @param string $currency Currency code
 * @return bool Success status
 */
function acceptContact($address, $name, $fee, $credit, $currency) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->acceptContact($address, $name, $fee, $credit, $currency);
}

/**
 * Insert contact (wrapper)
 *
 * @param string $address Contact address
 * @param string $contactPublicKey Contact's public key
 * @param string $name Contact name
 * @param float $fee Fee percentage
 * @param float $credit Credit limit
 * @param string $currency Currency code
 * @return bool Success status
 */
function insertContact($address, $contactPublicKey, $name, $fee, $credit, $currency) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->insertContact($address, $contactPublicKey, $name, $fee, $credit, $currency);
}

/**
 * Update contact status (wrapper)
 *
 * @param string $address Contact address
 * @param string $status New status
 * @return bool Success status
 */
function updateContactStatus($address, $status) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->updateStatus($address, $status);
}

/**
 * Update unblock contact (wrapper)
 *
 * @param string $address Contact address
 * @param string $name Contact name
 * @param float $fee Fee percentage
 * @param float $credit Credit limit
 * @param string $currency Currency code
 * @return bool Success status
 */
function updateUnblockContact($address, $name, $fee, $credit, $currency) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->updateUnblockContact($address, $name, $fee, $credit, $currency);
}

/**
 * Check if contact exists (wrapper)
 *
 * @param string $address Contact address
 * @return bool True if exists
 */
function checkContactExists($address) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->contactExists($address);
}

/**
 * Lookup contact by name (wrapper)
 *
 * @param string $name Contact name
 * @return array|null Contact data or null
 */
function lookupContactByName($name) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->lookupByName($name);
}

/**
 * Lookup contact by address (wrapper)
 *
 * @param string $address Contact address
 * @return array|null Contact data or null
 */
function lookupContactByAddress($address) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->lookupByAddress($address);
}

/**
 * Search contacts query (wrapper)
 *
 * @param string|null $searchTerm Search term
 * @return array Array of contacts
 */
function searchContactsQuery($searchTerm) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->searchContacts($searchTerm);
}

/**
 * Retrieve contact addresses (wrapper)
 *
 * @param string|null $exclude Address to exclude
 * @return array Array of addresses
 */
function retrieveContactAddresses($exclude = null) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->getAllAddresses($exclude);
}

/**
 * Retrieve contact addresses & pubkeys (wrapper)
 *
 * @param string|null $exclude Address to exclude
 * @return array Array of addresses
 */
function retrieveContactAddressesPubkeys() {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->getAllContacts();
}


/**
 * Get credit limit (wrapper)
 *
 * @param string $senderPublicKey Sender's public key
 * @return float Credit limit
 */
function getCreditLimit($senderPublicKey) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->getCreditLimit($senderPublicKey);
}

/**
 * Check accepted contact (wrapper)
 *
 * @param string $address Contact address
 * @return bool True if accepted
 */
function checkAcceptedContact($address) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->isAcceptedContact($address);
}

/**
 * Check pending contact (wrapper)
 *
 * @param string $address Contact address
 * @return bool True if pending
 */
function checkPendingContact($address) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->hasPendingContact($address);
}

/**
 * Get pending contacts (wrapper)
 *
 * @return array Array of contacts
 */
function getAllPendingContactRequests() {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->getPendingContactRequests();
}

/**
 * Add pending contact (wrapper)
 *
 * @param string $address Contact address
 * @param string $senderPublicKey Sender's public key
 * @return string JSON response
 */
function addPendingContact($address, $senderPublicKey) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->addPendingContact($address, $senderPublicKey);
}

/**
 * Lookup contact address by name (wrapper)
 *
 * @param string $name Contact name
 * @return string|null Contact address or null
 */
function lookupContactAddressByName($name) {
    $repo = ServiceContainer::getInstance()->getContactRepository();
    return $repo->lookupAddressByName($name);
}

// Transaction Repository Wrappers

/**
 * Get previous transaction ID (wrapper)
 *
 * @param string $senderPublicKey Sender's public key
 * @param string $receiverAddress Receiver's address
 * @return string|null Previous txid or null
 */
function getPreviousTxid($senderPublicKey, $receiverAddress) {
    $repo = ServiceContainer::getInstance()->getTransactionRepository();
    return $repo->getPreviousTxid($senderPublicKey, $receiverAddress);
}

/**
 * Get existing previous txid (wrapper)
 *
 * @param string $previousTxid Previous transaction ID
 * @return bool True if exists
 */
function getExistingPreviousTxid($previousTxid) {
    $repo = ServiceContainer::getInstance()->getTransactionRepository();
    return $repo->existingPreviousTxid($previousTxid);
}

/**
 * Calculate total sent (wrapper)
 *
 * @param string $publicKey Public key
 * @return float Total amount sent
 */
function calculateTotalSent($publicKey) {
    $repo = ServiceContainer::getInstance()->getTransactionRepository();
    return $repo->calculateTotalSentByUser($publicKey);
}

/**
 * Calculate total received (wrapper)
 *
 * @param string $publicKey Public key
 * @return float Total amount received
 */
function calculateTotalReceived($publicKey) {
    $repo = ServiceContainer::getInstance()->getTransactionRepository();
    return $repo->calculateTotalReceived($publicKey);
}

/**
 * Insert transaction (wrapper)
 *
 * @param array $request Transaction request data
 * @return string JSON response
 */
function insertTransaction($request) {
    $repo = ServiceContainer::getInstance()->getTransactionRepository();
    return $repo->insertTransaction($request);
}

/**
 * Retrieve pending transaction messages (wrapper)
 *
 * @return array Array of pending transactions
 */
function retrievePendingTransactionMessages() {
    $repo = ServiceContainer::getInstance()->getTransactionRepository();
    return $repo->getPendingTransactions();
}

/**
 * Update transaction status (wrapper)
 *
 * @param string $identifier Transaction memo or txid
 * @param string $status New status
 * @param bool $isTxid True if identifier is txid
 * @return bool Success status
 */
function updateTransactionStatus($identifier, $status, $isTxid = false) {
    $repo = ServiceContainer::getInstance()->getTransactionRepository();
    return $repo->updateStatus($identifier, $status, $isTxid);
}

/**
 * Get transaction by memo (wrapper)
 *
 * @param string $memo Transaction memo
 * @return array|null Transaction data or null
 */
function getTransactionByMemo($memo) {
    $repo = ServiceContainer::getInstance()->getTransactionRepository();
    return $repo->getByMemo($memo);
}

/**
 * Get transaction by txid (wrapper)
 *
 * @param string $txid Transaction ID
 * @return array|null Transaction data or null
 */
function getTransactionByTxid($txid) {
    $repo = ServiceContainer::getInstance()->getTransactionRepository();
    return $repo->getByTxid($txid);
}

/**
 * Get P2P by hash (wrapper)
 *
 * @param string $hash P2P hash
 * @return array|null P2P data or null
 */
function getP2pByHash($hash) {
    $repo = ServiceContainer::getInstance()->getP2pRepository();
    return $repo->getByHash($hash);
}

/**
 * Insert P2P request (wrapper)
 *
 * @param array $request P2P request data
 * @param string|null $destinationAddress Destination address
 * @return string JSON response
 */
function insertP2pRequest($request, $destinationAddress = null) {
    $repo = ServiceContainer::getInstance()->getP2pRepository();
    return $repo->insertP2pRequest($request, $destinationAddress);
}

/**
 * Retrieve queued P2P messages (wrapper)
 *
 * @return array Array of P2P messages
 */
function retrieveQueuedP2pMessages() {
    $repo = ServiceContainer::getInstance()->getP2pRepository();
    return $repo->getQueuedP2pMessages();
}

/**
 * Update P2P request status (wrapper)
 *
 * @param string $hash P2P hash
 * @param string $status New status
 * @param bool $completed Whether to set completed timestamp
 * @return bool Success status
 */
function updateP2pRequestStatus($hash, $status, $completed = false) {
    $repo = ServiceContainer::getInstance()->getP2pRepository();
    return $repo->updateStatus($hash, $status, $completed);
}

/**
 * Retrieve credit in P2P (wrapper)
 *
 * @param string $address Sender address
 * @return float Total amount on hold
 */
function retrieveCreditInP2p($address) {
    $repo = ServiceContainer::getInstance()->getP2pRepository();
    return $repo->getCreditInP2p($address);
}

/**
 * Update incoming P2P txid (wrapper)
 *
 * @param string $hash P2P hash
 * @param string $txid Transaction ID
 * @return bool Success status
 */
function updateIncomingP2pTxid($hash, $txid) {
    $repo = ServiceContainer::getInstance()->getP2pRepository();
    return $repo->updateIncomingTxid($hash, $txid);
}

/**
 * Update outgoing P2P txid (wrapper)
 *
 * @param string $hash P2P hash
 * @param string $txid Transaction ID
 * @return bool Success status
 */
function updateOutgoingP2pTxid($hash, $txid) {
    $repo = ServiceContainer::getInstance()->getP2pRepository();
    return $repo->updateOutgoingTxid($hash, $txid);
}
