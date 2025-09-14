<?php
# Copyright 2025

function returnContactAccepted() {
    return "Contact accepted successfully.\n";
}

function returnContactAcceptanceFailed() {
    return "Failed to accept contact.\n";
}

function returnContactAddInvalidInput() {
    return "Invalid input. Please provide a valid address, name, fee, credit, and currency.\n" .
           "Example command: eiou add [address] [name] [fee percent] [credit] [currency]\n";
}

function returnContactCreationSuccessful() {
    return "Contact created successfully.\n";
}

function returnContactCreationFailed() {
    return "Failed to create contact.\n";
}

function returnContactRequestAlreadyInserted() {
    return "Warning: Contact was previously added but no response has yet been received from other user.\n" .
            "Trying to resynch contact...\n";
}

function returnContactCreationWarning($message) {
    return "Warning: " . $message . "\n" .
           "Contact was previously deleted and will be added again.\n";
}

function returnContactDeletedSuccesfully(){
    return "Contact deleted successfully.\n";
}

function returnContactDetails($data) {
    // Return contact details in a nice format
    return "Contact Details:\n" .
           "---------------\n" .
           "Address: " . $data['address'] . "\n" .
           "Name: " . ($data['name'] ?? 'N/A') . "\n" .
           "Public Key: " . "..." . substr($data['pubkey'], 51, 25) . "...\n" .              
           "Fee: " . (($data['fee_percent'] ?? '0.00') / 100) . "%\n" .
           "Credit Limit: " . (($data['credit_limit'] ?? '0.00') / 100) . "\n" .
           "Currency: " . ($data['currency']) . "\n";
}

function returnContactExists() {
    return "This contact has already been added or accepted.\n";
}

function returnContactNotFound() {
    return "Contact not found.\n";
}

function returnContactNotFoundNoAction() {
    return "Contact not found, no action taken.\n";
}

function returnContactUpdate() {
    return "Contact updated successfully.\n";
}

function returnContactUpdateInvalidInput() {
    return "Incorrect field given. Please provide a valid field to update.\n" .
           "Example command: eiou update [type] [address/name] [(name)] [(fee)] [(credit)]\n" .
           "Valid fields include:\n" .
           "• 'name' - Update contact name (e.g., eiou update name 123abc.onion name John)\n" .
           "• 'fee' - Update fee percentage (e.g., eiou update 123abc.onion fee 1.5)\n" .
           "• 'credit' - Update credit limit (e.g., eiou update 123abc.onion credit 500)\n" .
           "• 'all' - Update all fields at once (e.g., eiou update 123abc.onion all NewName 1.5 500)\n";
}

function returnContactReadInvalidInput() {
    return "Invalid input. Example command: eiou viewcontact [address/name]\n";       
}

function returnContactRejected($data) {
    return "Contact creation was not accepted by the recipient ". print_r($data, TRUE) . "\n";
}

function returnContactSearchNoResults() {
    return "No contacts found matching your search.\n";
}

function returnContactSearchResults ($data) {
    // Return contact information in a nice format
    return "Search Results:\n" .
            "--------------------------------------------\n" .
            str_pad("Address", 56, ' ') . " | " . 
            str_pad("Name", 20, ' ') . " | " . 
            str_pad("Fee %", 10, ' ') . " | " . 
            str_pad("Credit Limit", 15, ' ') . " | " . 
            "Currency\n" .
            "--------------------------------------------\n" .
            implode("\n", array_map(function($contact) {
                return str_pad($contact['address'], 56, ' ') . " | " . 
                        str_pad($contact['name'] ?? 'N/A', 20, ' ') . " | " . 
                        str_pad(($contact['fee_percent'] !== null ? $contact['fee_percent'] / 100 : 'N/A'), 10, ' ') . " | " . 
                        str_pad(($contact['credit_limit'] !== null ? $contact['credit_limit'] / 100 : 'N/A'), 15, ' ') . " | " . 
                        ($contact['currency'] ?? 'N/A');
            }, $data)) . "\n" .
            "--------------------------------------------\n" .
            "Total contacts found: " . count($data) ."\n";
}

function returnHostnameSaved($hostname){
    return "Hostname saved: " . $hostname . "\n";
}

function returnInstanceAlreadyRunning(){
    return "Another instance is already running.\n";
}

function returnInvalidSendRequest(){
    return "Incorrect usage. Please use the following format:\n" .
           "eiou send [recipient] [amount] [optional: currency]\n" .
           "Example: eiou send Bob 50\n" .
           "Example: eiou send 123abc.onion 100 USD\n";
}

function returnInvalidAmountSendRequest(){
    return "Invalid amount. Please enter a positive number.\n";
}
function returnInvalidHostnameFormat(){
    return "Invalid hostname format. Please provide a valid URL.\n";
}

function returnInvalidCurrencySendRequest(){
    return "Invalid currency. Please use a 3-letter currency code (e.g., USD).\n";
}

function returnLockfileCreation($lockfile,$pid){
    return "Created lockfile at $lockfile with PID $pid\n";
}

function returnNotProvidedCurrencySendRequest(){
    return "Currency not provided. Please provide a 3-letter currency code (e.g., USD).\n";
}

function returnNoWalletExists(){
    return "No wallet found. Please generate a new wallet by running 'eiou generate' or restore an existing wallet by running 'eiou restore'.\n";
}

function returnOverwritingExistingWallet(){
    return "Wallet will now be overwritten...\n";
}

function returnOverwritingExistingWalletCancelled(){
    return "Wallet will not be overwritten.\n";
}

function returnUserInputRequestOverwritingWallet(){
    return "A wallet already exists, do you want to overwrite the existing wallet?\n" .
           "\tWARNING: this act is irreversible!\n" .
           "\tenter 'y' to continue overwriting, anything else to abort.\n";
}