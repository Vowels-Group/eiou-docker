<?php
// Copyright 2025

// Create PDO connection
$pdo = createPDOConnection();

// Helper function for redirecting
function redirectMessage($message,$messageType){
    // Redirect back to main page with message (no auth code in URL)
    $redirectUrl = $_SERVER['PHP_SELF'] . '?message=' . urlencode($message) . '&type=' . urlencode($messageType);
    header('Location: ' . $redirectUrl);
    exit;
}

// Get message from URL parameters (for redirects)
if (isset($_GET['message']) && isset($_GET['type'])) {
    $messageForDisplay = $_GET['message'];
    $messageTypeForDisplay = $_GET['type'];
} else {
    $messageForDisplay = '';
    $messageTypeForDisplay = '';
}


// Handle add contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'addContact') {
    $address = $_POST['address'] ?? '';
    $name = $_POST['name'] ?? '';
    $fee = $_POST['fee'] ?? '';
    $credit = $_POST['credit'] ?? '';
    $currency = $_POST['currency'] ?? '';
    
    if (empty($address) || empty($name) || empty($fee) || empty($credit) || empty($currency)) {
        $message = 'All fields are required';
        $messageType = 'error';
    } else {
        // Create argv array for addContact function
        $argv = ['eiou', 'add', $address, $name, $fee, $credit, $currency];
        
        // Capture output
        ob_start();
        try {
            addContact($argv);
            $output = ob_get_clean();
            
            // Parse the output to determine message type and content
            $messageInfo = parseContactOutput($output);
            $message = $messageInfo['message'];
            $messageType = $messageInfo['type'];
        } catch (Exception $e) {
            ob_end_clean();
            $message = 'Internal server error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    redirectMessage($message,$messageType);
}


// Handle send eIOU form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sendEIOU') {
    $recipient = $_POST['recipient'] ?? '';
    $manualRecipient = $_POST['manual_recipient'] ?? '';
    $amount = $_POST['amount'] ?? '';
    $currency = $_POST['currency'] ?? '';
    
    // Use manual recipient if provided, otherwise use selected recipient
    $finalRecipient = !empty($manualRecipient) ? $manualRecipient : $recipient;
    
    if (empty($finalRecipient) || empty($amount) || empty($currency)) {
        $message = 'All fields are required';
        $messageType = 'error';        
    } else {
        // Create argv array for sendEiou function
        $argv = ['eiou','send', $finalRecipient, $amount, $currency];

        // Capture output
        ob_start();
        try {
            sendEiou($argv);
            $output = ob_get_clean();
            $message = trim($output);
            if (strpos($output, 'ERROR') !== false || strpos($output, 'Failed') !== false) {
                $messageType = 'error';
            } else {
                $messageType = 'success';
            }
        } catch (Exception $e) {
            ob_end_clean();
            $message = 'Internal server error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    redirectMessage($message,$messageType);
}

// Handle contact request actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['acceptContact', 'deleteContact', 'blockContact','ublockContact', 'editContact'])) {
    $contactAddress = $_POST['contact_address'] ?? '';
    $contactPubkey = $_POST['contact_pubkey'] ?? '';
    $contactName = $_POST['contact_name'] ?? '';
    $contactFee = $_POST['contact_fee'] ?? '';
    $contactCredit = $_POST['contact_credit'] ?? '';
    $contactCurrency = $_POST['contact_currency'] ?? '';
    
    if (empty($contactAddress)) {
        $message = 'Contact address is required';
        $messageType = 'error';
        redirectMessage($message,$messageType);
    } else {
        switch ($_POST['action']) {
            case 'acceptContact':
                if (empty($contactName) || empty($contactFee) || empty($contactCredit) || empty($contactCurrency)) {
                    $message = 'All fields are required to accept a contact';
                    $messageType = 'error';
                } else {
                    // Create argv array for addContact function
                    $argv = ['eiou', 'add', $contactAddress, $contactName, $contactFee, $contactCredit, $contactCurrency];
                    
                    // Capture output
                    ob_start();
                    try {
                        addContact($argv);
                        $output = ob_get_clean();
                        
                        // Parse the output to determine message type and content
                        $messageInfo = parseContactOutput($output);
                        $message = $messageInfo['message'];
                        $messageType = $messageInfo['type'];
                    } catch (Exception $e) {
                        ob_end_clean();
                        $message = 'Internal server error: ' . $e->getMessage();
                        $messageType = 'error';
                    }
                }
                redirectMessage($message,$messageType);
                break;
                
            case 'deleteContact':
                // Create argv array for deleteContact function
                $argv = ['eiou', 'delete', $contactAddress];
                
                // Capture output
                ob_start();
                try {
                    deleteContact($argv);
                    $output = ob_get_clean();
                    
                    // Parse the output to determine message type and content
                    $messageInfo = parseContactOutput($output);
                    $message = $messageInfo['message'];
                    $messageType = $messageInfo['type'];
                } catch (Exception $e) {
                    ob_end_clean();
                    $message = 'Internal server error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                redirectMessage($message,$messageType);
                break;

            case 'blockContact':
                $argv = ['eiou', 'block', $contactAddress];
                
                // Capture output
                ob_start();
                try {
                    blockContact($argv);
                    $output = ob_get_clean();
                    
                    // Parse the output to determine message type and content
                    $messageInfo = parseContactOutput($output);
                    $message = $messageInfo['message'];
                    $messageType = $messageInfo['type'];
                } catch (Exception $e) {
                    ob_end_clean();
                    $message = 'Internal server error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                redirectMessage($message,$messageType);
                break;

            // THIS only functions if contact exists with full info
            case 'unblockContact':
                $argv = ['eiou', 'unblock', $contactAddress];
                
                // Capture output
                ob_start();
                try {
                    unblockContact($argv);
                    $output = ob_get_clean();
                    
                    // Parse the output to determine message type and content
                    $messageInfo = parseContactOutput($output);
                    $message = $messageInfo['message'];
                    $messageType = $messageInfo['type'];
                } catch (Exception $e) {
                    ob_end_clean();
                    $message = 'Internal server error: ' . $e->getMessage();
                    $messageType = 'error';
                }
                redirectMessage($message,$messageType);
                break;

            case 'editContact':
                if (empty($contactName) || empty($contactFee) || empty($contactCredit) || empty($contactCurrency)) {
                    $message = 'All fields are required to edit a contact';
                    $messageType = 'error';
                } else {
                    // Create argv array for updateContact function
                    $argv = ['eiou', 'update', $contactAddress, 'all', $contactName, $contactFee, $contactCredit];
                    
                    // Capture output
                    ob_start();
                    try {
                        updateContact($argv);
                        $output = ob_get_clean();
                        
                        // Parse the output to determine message type and content
                        $messageInfo = parseContactOutput($output);
                        $message = $messageInfo['message'];
                        $messageType = $messageInfo['type'];
                    } catch (Exception $e) {
                        ob_end_clean();
                        $message = 'Internal server error: ' . $e->getMessage();
                        $messageType = 'error';
                    }
                }
                redirectMessage($message,$messageType);
                break;
        }
    }
}

// Helper function to truncate address for display
function truncateAddress($address, $length = 10) {
    if (strlen($address) <= $length) {
        return $address;
    }
    return substr($address, 0, $length) . '...';
}

// Helper function to get user's total balance
function getUserTotalBalance() {
    global $pdo;
    
    try {
        $totalReceived = calculateTotalReceivedUser();
        $totalSent = calculateTotalSentUser();
        $balance = ($totalReceived - $totalSent) / 100; // Convert from cents
        return number_format($balance, 2);
    } catch (Exception $e) {
        return "0.00";
    }
}

// Helper function to get accepted contacts
function getAcceptedContacts() {
    global $pdo;
    
    try {
        // Get all accepted contacts
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE status = 'accepted'");
        $stmt->execute();
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $contacts;
    } catch (Exception $e) {
        return [];
    }
}

// Helper function to get pending contacts
function getPendingContacts() {
    global $pdo;
    
    try {
        // Get all pending contact requests (where name IS NULL and status = 'pending')
        $stmt = $pdo->prepare("SELECT address, pubkey, status FROM contacts WHERE name IS NULL AND status = 'pending'");
        $stmt->execute();
        $pendingContacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $pendingContacts;
    } catch (Exception $e) {
        return [];
    }
}

// Helper function to get pending contacts inserted requests from user
function getUserPendingContacts() {
    global $pdo;
    
    try {
        // Get all pending contact requests (where name IS NOT NULL and status = 'pending')
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE name IS NOT NULL AND status = 'pending'");
        $stmt->execute();
        $pendingUserContacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $pendingUserContacts;
    } catch (Exception $e) {
        return [];
    }
}

// Helper function to get blocked contacts
function getBlockedContacts() {
    global $pdo;
    
    try {
        // Get all blocked contacts 
        $stmt = $pdo->prepare("SELECT * FROM contacts WHERE status = 'blocked'");
        $stmt->execute();
        $blockedContacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $blockedContacts;
    } catch (Exception $e) {
        return [];
    }
}

// Helper function to get all contacts
function getAllContacts() {
    global $pdo;
    
    try {
        // Get all all contacts (regardless of status)
        $stmt = $pdo->prepare("SELECT * FROM contacts");
        $stmt->execute();
        $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $contacts;
    } catch (Exception $e) {
        return [];
    }
}

// Helper function to get contact balance
function getContactBalance($userPubkey, $contactPubkey) {
    global $pdo;
    
    try {
        // Calculate sent to this contact
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as sent FROM transactions WHERE sender_public_key_hash = ? AND receiver_public_key_hash = ?");
        $stmt->execute([hash('sha256', $userPubkey), hash('sha256', $contactPubkey)]);
        $sent = $stmt->fetch()['sent'];
        
        // Calculate received from this contact
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as received FROM transactions WHERE sender_public_key_hash = ? AND receiver_public_key_hash = ?");
        $stmt->execute([hash('sha256', $contactPubkey), hash('sha256', $userPubkey)]);
        $received = $stmt->fetch()['received'];
        
        return $received - $sent;
    } catch (Exception $e) {
        return 0;
    }
}

// Helper function to get transaction history
function getTransactionHistory($limit = 10) {
    global $pdo, $user;
    
    try {
        $userAddresses = [];
        if (isset($user['hostname'])) {
            $userAddresses[] = $user['hostname'];
        }
        if (isset($user['torAddress'])) {
            $userAddresses[] = $user['torAddress'];
        }
        
        if (empty($userAddresses)) {
            return [];
        }

        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT sender_address, receiver_address, amount, currency, timestamp FROM transactions
                  WHERE (sender_address IN ($placeholders) OR receiver_address IN ($placeholders))
                  ORDER BY timestamp DESC LIMIT ?";

        $stmt = $pdo->prepare($query);

        // Bind parameters - addresses twice for both IN clauses, then limit
        $params = array_merge($userAddresses, $userAddresses, [$limit]);
        $stmt->execute($params);

        
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $formattedTransactions = [];
        
        foreach ($transactions as $tx) {
            $isSent = in_array($tx['sender_address'], $userAddresses);
            $counterpartyAddress = $isSent ? $tx['receiver_address'] : $tx['sender_address'];
            
            // Get contact name for counterparty
            $contactName = getContactNameByAddress($counterpartyAddress);
            
            $formattedTransactions[] = [
                'date' => $tx['timestamp'],
                'type' => $isSent ? 'sent' : 'received',
                'amount' => $tx['amount'] / 100, // Convert from cents
                'currency' => $tx['currency'],
                'counterparty' => $contactName ?: $counterpartyAddress
            ];
        }
        
        return $formattedTransactions;
    } catch (Exception $e) {
        return [];
    }
}

// Helper function to get contact name by address
function getContactNameByAddress($address) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT name FROM contacts WHERE address = ?");
        $stmt->execute([$address]);
        $result = $stmt->fetch();
        return $result ? $result['name'] : null;
    } catch (Exception $e) {
        return null;
    }
}

// Helper function to check for new transactions since last check
function checkForNewTransactions($lastCheckTime) {
    global $pdo, $user;
    
    try {
        $userAddresses = [];
        if (isset($user['hostname'])) {
            $userAddresses[] = $user['hostname'];
        }
        if (isset($user['torAddress'])) {
            $userAddresses[] = $user['torAddress'];
        }
        
        if (empty($userAddresses)) {
            return false;
        }

        // Create placeholders for IN clause
        $placeholders = str_repeat('?,', count($userAddresses) - 1) . '?';

        $query = "SELECT COUNT(*) as count FROM transactions
                  WHERE (sender_address IN ($placeholders) OR receiver_address IN ($placeholders))
                  AND timestamp > ?";

        $stmt = $pdo->prepare($query);

        // Bind parameters - addresses twice for both IN clauses, then timestamp
        $params = array_merge($userAddresses, $userAddresses, [$lastCheckTime]);
        $stmt->execute($params);

        
        $result = $stmt->fetch();
        return $result['count'] > 0;
    } catch (Exception $e) {
        return false;
    }
}

// Helper function to check for new contact requests since last check
function checkForNewContactRequests($lastCheckTime) {
    global $pdo;
    
    try {
        $query = "SELECT COUNT(*) as count FROM contacts 
                  WHERE name IS NULL AND status = 'pending' 
                  AND created_at > ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$lastCheckTime]);
        
        $result = $stmt->fetch();
        return $result['count'] > 0;
    } catch (Exception $e) {
        return false;
    }
}


// Helper function to parse contact function output and determine message type
function parseContactOutput($output) {
    $output = trim($output);
    
    // Success messages
    if (str_contains(strtolower($output), 'contact accepted.') !== false) {
        return ['message' => $output, 'type' => 'contact-accepted'];
    }

    // General success message
    if(str_contains(strtolower($output), 'success') !== false) {
        return ['message' => $output, 'type' => 'success'];
    }
    
    // Warning messages
    if (str_contains(strtolower($output), 'already been added or accepted') !== false) {
        return ['message' => $output, 'type' => 'warning'];
    }
    if (str_contains(strtolower($output), 'warning:') !== false) {
        return ['message' => $output, 'type' => 'warning'];
    }
    
    // Error messages
    if (str_contains(strtolower($output), 'failed') !== false) {
        return ['message' => $output . ' Please try again.', 'type' => 'error'];
    }
    if (str_contains(strtolower($output), 'not accepted by the recipient') !== false) {
        return ['message' => $output . ' Please try again or contact the recipient directly.', 'type' => 'error'];
    }
    if (str_contains(strtolower($output), 'not found') !== false) {
        return ['message' => $output, 'type' => 'error'];
    }
    if (str_contains(strtolower($output), 'no results found.') !== false) {
        return ['message' => $output, 'type' => 'error'];
    }
    
    // Generic error detection
    if (str_contains(strtolower($output), 'error') !== false || str_contains(strtolower($output), 'failed') !== false) {
        return ['message' => $output, 'type' => 'error'];
    }
    
    // Default success case
    return ['message' => $output, 'type' => 'success'];
}

// Handle update checking requests (for Tor Browser polling)
if (isset($_GET['check_updates']) && $_GET['check_updates'] === '1') {
    // Check for new transactions or contact requests since last check
    $lastCheckTime = $_GET['last_check'] ?? 0;
    
    // Check for new transactions
    $newTransactions = checkForNewTransactions($lastCheckTime);
    $newContactRequests = checkForNewContactRequests($lastCheckTime);
    
    if ($newTransactions || $newContactRequests) {
        echo "new_transaction:" . ($newTransactions ? '1' : '0') . "\n";
        echo "new_contact_request:" . ($newContactRequests ? '1' : '0') . "\n";
        echo "timestamp:" . time() . "\n";
    } else {
        echo "no_updates\n";
    }
    exit;
}

// Helper Function to convert output based on currency cases
function currencyOutputConversion($value,$currency){
    if($currency === 'USD'){
        return $value ? ($value / 100) : $value;
    }
    return $value;
}


// Helper Function, conversion database values to output values
function contactConversion($contacts){
    global $user;
    $contactsWithBalances = [];
    foreach($contacts as $contact){
        // Calculate balance for this contact
        $balance = getContactBalance($user['public'], $contact['pubkey']);
        
        $contactsWithBalances[] = [
            'name' => $contact['name'],
            'address' => $contact['address'],
            'balance' =>  currencyOutputConversion($balance,$contact['currency']),
            'fee' =>  currencyOutputConversion($contact['fee_percent'],$contact['currency']),
            'credit_limit' =>  currencyOutputConversion($contact['credit_limit'],$contact['currency']),
            'currency' => $contact['currency']
        ];
       
    }
    return $contactsWithBalances;
}

// Get user based data
$totalBalance = getUserTotalBalance();
$transactions = getTransactionHistory(10);

// Contact data
$allContacts = getAllContacts();
$acceptedContacts = contactConversion(getAcceptedContacts());
$pendingContacts = getPendingContacts();
$pendingUserContacts = contactConversion(getUserPendingContacts());
$blockedContacts = contactConversion(getBlockedContacts());

