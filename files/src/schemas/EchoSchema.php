<?php
# Copyright 2025 The Vowels Company

// ============================================================================
// CONTACT ECHO FUNCTIONS
// ============================================================================

function returnContactAccepted() {
    return "[Contact] Accepted successfully.\n";
}

function returnContactAcceptanceFailed() {
    return "[Contact] Failed to accept.\n";
}

function returnContactAddInvalidInput() {
    return "[Contact] Invalid input. Please provide a valid address, name, fee, credit, and currency.\n" .
           "Example command: eiou add [address] [name] [fee percent] [credit] [currency]\n";
}

function returnContactCreationSuccessful() {
    return "[Contact] Created successfully.\n";
}

function returnContactCreationFailed() {
    return "[Contact] Failed to create.\n";
}

function returnContactRequestAlreadyInserted() {
    return "[Contact] Warning: Previously added but no response has yet been received from other user.\n" .
            "Trying to resync contact...\n";
}

function returnContactCreationWarning($message) {
    return "[Contact] Warning: " . $message . "\n" .
           "Contact was previously deleted and will be added again.\n";
}

function returnContactDeletedSuccesfully(){
    return "[Contact] Deleted successfully.\n";
}

function returnContactDetails($data) {
    // Return contact details in a nice format
    $app = Application::getInstance();
    $currencyUtility = $app->utilityServices->getCurrencyUtility();
    return "[Contact] Details:\n" .
           "---------------\n" .
           "Http Address: " . ($data['http'] ?? 'Http address not available') . "\n" .
           "Tor Address: " . ($data['tor'] ?? 'Tor address not available'). "\n" .
           "Name: " . ($data['name'] ?? 'N/A') . "\n" .
           "Public Key: " . "..." . substr($data['pubkey'], 51, 25) . "...\n" .
           "Fee: " .  $currencyUtility->convertCentsToDollars(($data['fee_percent'] ?? '0.00')) . "%\n" .
           "Credit Limit: " . $currencyUtility->convertCentsToDollars(($data['credit_limit'] ?? '0.00')) . "\n" .
           "Currency: " . $data['currency'] . "\n";
}

function returnContactExists() {
    return "[Contact] Already added or accepted.\n";
}

function returnContactNotFound() {
    return "[Contact] Not found.\n";
}

function returnContactNotFoundNoAction() {
    return "[Contact] Not found, no action taken.\n";
}

function returnContactUpdate() {
    return "[Contact] Updated successfully.\n";
}

function returnContactUpdateInvalidInput() {
    return "[Contact] Incorrect field given. Please provide a valid field to update.\n" .
           "Example command: eiou update [type] [address/name] [(name)] [(fee)] [(credit)]\n" .
           "Valid fields include:\n" .
           "• 'name' - Update contact name (e.g., eiou update name 123abc.onion name John)\n" .
           "• 'fee' - Update fee percentage (e.g., eiou update 123abc.onion fee 1.5)\n" .
           "• 'credit' - Update credit limit (e.g., eiou update 123abc.onion credit 500)\n" .
           "• 'all' - Update all fields at once (e.g., eiou update 123abc.onion all NewName 1.5 500)\n";
}

function returnContactUpdateInvalidInputParameters() {
    return "[Contact] Incorrect amount of parameters given. Please provide a valid field & corresponding parameter amount to update.\n" .
           "Example command: eiou update [type] [address/name] [(name)] [(fee)] [(credit)]\n" .
           "Valid fields & parameter amounts include:\n" .
           "• 'name' - Update contact name -> 1 parameter (e.g., eiou update name 123abc.onion name John)\n" .
           "• 'fee' - Update fee percentage -> 1 parameter (e.g., eiou update 123abc.onion fee 1.5)\n" .
           "• 'credit' - Update credit limit -> 1 parameter (e.g., eiou update 123abc.onion credit 500)\n" .
           "• 'all' - Update all fields at once -> 3 parameters (e.g., eiou update 123abc.onion all NewName 1.5 500)\n";
}

function returnContactReadInvalidInput() {
    return "[Contact] Invalid input. Example command: eiou viewcontact [address/name]\n";
}

function returnContactRejected($data) {
    return "[Contact] Creation was not accepted by the recipient ". print_r($data, TRUE) . "\n";
}

function returnContactSearchNoResults() {
    return "[Contact] No contacts found matching your search.\n";
}

function returnContactSearchResults ($data) {
    // Return contact information in a nice format
    return "[Contact] Search Results:\n" .
            "--------------------------------------------\n" .
            str_pad("Http address", 56, ' ') . " | " .
            str_pad("Tor address", 56, ' ') . " | " .
            str_pad("Name", 20, ' ') . " | " .
            str_pad("Fee %", 10, ' ') . " | " .
            str_pad("Credit Limit", 15, ' ') . " | " .
            "Currency\n" .
            "--------------------------------------------\n" .
            implode("\n", array_map(function($contact) {
                return str_pad($contact['http'] ?? '', 56, ' ') . " | " .
                        str_pad($contact['tor'] ?? '', 56, ' ') . " | " .
                        str_pad($contact['name'] ?? 'N/A', 20, ' ') . " | " .
                        str_pad(($contact['fee_percent'] !== null ? Application::getInstance()->utilityServices->getCurrencyUtility()->convertCentsToDollars($contact['fee_percent']) : 'N/A'), 10, ' ') . " | " .
                        str_pad(($contact['credit_limit'] !== null ? Application::getInstance()->utilityServices->getCurrencyUtility()->convertCentsToDollars($contact['credit_limit']) : 'N/A'), 15, ' ') . " | " .
                        ($contact['currency'] ?? 'N/A');
            }, $data)) . "\n" .
            "--------------------------------------------\n" .
            "Total contacts found: " . count($data) ."\n";
}

// ============================================================================
// WALLET ECHO FUNCTIONS
// ============================================================================

function returnNoWalletExists(){
    return "[Wallet] No wallet found. Please generate a new wallet by running 'eiou generate' or restore an existing wallet by running 'eiou restore'.\n";
}

function returnOverwritingExistingWallet(){
    return "[Wallet] Will now be overwritten...\n";
}

function returnOverwritingExistingWalletCancelled(){
    return "[Wallet] Will not be overwritten.\n";
}

function returnUserInputRequestOverwritingWallet(){
    return "[Wallet] A wallet already exists, do you want to overwrite the existing wallet?\n" .
           "\tWARNING: this act is irreversible!\n" .
           "\tenter 'y' to continue overwriting, anything else to abort.\n";
}

function returnWalletAlreadyExists(){
    return "[Wallet] Already exists";
}

function returnWalletUpdatedSuccesfully($key){
    return "[Wallet] " . $key ." updated successfully.\n";;
}

// ============================================================================
// SYSTEM ECHO FUNCTIONS
// ============================================================================

function returnHostnameSaved($hostname){
    return "[System] Hostname saved: " . $hostname . "\n";
}

function returnInstanceAlreadyRunning(){
    return "[System] Another instance is already running.\n";
}

function returnInvalidHostnameFormat(){
    return "[System] Invalid hostname format. Please provide a valid URL.\n";
}

function returnLockfileCreation($lockfile,$pid){
    return "[System] Created lockfile at $lockfile with PID $pid\n";
}

function returnTorSaved($toraddress){
    return "[System] Tor saved: " . $toraddress . "\n";
}

// ============================================================================
// TRANSACTION ECHO FUNCTIONS
// ============================================================================

function returnInvalidSendRequest(){
    return "[Transaction] Incorrect usage. Please use the following format:\n" .
           "eiou send [recipient] [amount] [optional: currency]\n" .
           "Example: eiou send Bob 50\n" .
           "Example: eiou send 123abc.onion 100 USD\n";
}

function returnInvalidAmountSendRequest(){
    return "[Transaction] Invalid amount. Please enter a positive number.\n";
}

function returnInvalidCurrencySendRequest(){
    return "[Transaction] Invalid currency. Please use a 3-letter currency code (e.g., USD).\n";
}

function returnNotProvidedCurrencySendRequest(){
    return "[Transaction] Currency not provided. Please provide a 3-letter currency code (e.g., USD).\n";
}
