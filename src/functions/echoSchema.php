<?php
function returnContactAccepted() {
    return "Contact accepted.";
}

function returnContactAcceptanceFailed() {
    return "Failed to accept contact.";
}

function returnContactCreationSuccessful() {
    return "Contact created successfully.";
}

function returnContactCreationFailed() {
    return "Failed to create contact.";
}

function returnContactCreationWarning($message) {
    return "Warning: " . $message . "\n" .
           "Contact will be added despite the warning.";
}

function returnContactDetails($data) {
    return "Contact Details:\n" .
           "---------------\n" .
           "Address: " . $data['address'] . "\n" .
           "Name: " . ($data['name'] ?? 'N/A') . "\n" .
           "Public Key: " . substr($data['pubkey'], 0, 50) . "...\n" .
           "Fee: " . (($data['fee_percent'] ?? '0.00') / 100) . "%\n" .
           "Credit Limit: " . (($data['credit_limit'] ?? '0.00') / 100) . "\n" .
           "Currency: " . ($data['currency'] ?? 'USD');
}

function returnContactExists() {
    return "This contact has already been added or accepted.";
}

function returnContactAddInvalidInput() {
    return "Invalid input. Please provide a valid address, name, fee, credit, and currency.\n" .
           "Example command: eiou add [address] [name] [fee percent] [credit] [currency]";
}

function returnContactNotFound() {
    return "Contact not found.";
}

function returnContactUpdate() {
    return "Contact updated successfully.";
}

function returnContactUpdateInvalidInput() {
    return "Incorrect field given. Please provide a valid field to update.\n" .
           "Example command: eiou update [type] [address/name] [(name)] [(fee)] [(credit)]\n" .
           "Valid fields include:\n" .
           "• 'name' - Update contact name (e.g., eiou update name 123abc.onion John)\n" .
           "• 'fee' - Update fee percentage (e.g., eiou update 123abc.onion 1.5)\n" .
           "• 'credit' - Update credit limit (e.g., eiou update 123abc.onion 500)\n" .
           "• 'all' - Update all fields at once (e.g., eiou update 123abc.onion NewName 1.5 500)";
}

function returnContactReadInvalidInput() {
    return "Invalid input. Example command: eiou read [address]";       
}

function returnContactRejected($data) {
    return "Contact creation was not accepted by the recipient ". print_r($data, TRUE) . "";
}

function returnContactSearchNoResults() {
    return "No results found.";
}

function returnContactSearchResults ($data) {
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
            "Total contacts found: " . count($data) ;
}