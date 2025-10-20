<?php
// Copyright 2025


require_once("/etc/eiou/src/services/ServiceContainer.php");
$guiService = ServiceContainer::getInstance()->getGuiService();

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
            $guiService->addContact($argv);
            $output = ob_get_clean();
            
            // Parse the output to determine message type and content
            $messageInfo = $guiService->parseContactOutput($output);
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
                        $guiService->addContact($argv);
                        $output = ob_get_clean();
                        
                        // Parse the output to determine message type and content
                        $messageInfo = $guiService->parseContactOutput($output);
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
                    $guiService->deleteContact($argv[2]);
                    $output = ob_get_clean();
                    
                    // Parse the output to determine message type and content
                    $messageInfo = $guiService->parseContactOutput($output);
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
                    $guiService->blockContact($argv[2]);
                    $output = ob_get_clean();
                    
                    // Parse the output to determine message type and content
                    $messageInfo = $guiService->parseContactOutput($output);
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
                    $guiService->unblockContact($argv[2]);
                    $output = ob_get_clean();
                    
                    // Parse the output to determine message type and content
                    $messageInfo = $guiService->parseContactOutput($output);
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
                        $guiService->updateContact($argv);
                        $output = ob_get_clean();
                        
                        // Parse the output to determine message type and content
                        $messageInfo = $guiService->parseContactOutput($output);
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

// Handle update checking requests (for Tor Browser polling)
if (isset($_GET['check_updates']) && $_GET['check_updates'] === '1') {
    // Check for new transactions or contact requests since last check
    $lastCheckTime = $_GET['last_check'] ?? 0;
    
    // Check for new transactions
    $newTransactions = $this->guiService->checkForNewTransactions($lastCheckTime);
    $newContactRequests = $this->guiService->checkForNewContactRequests($lastCheckTime);
    
    if ($newTransactions || $newContactRequests) {
        echo "new_transaction:" . ($newTransactions ? '1' : '0') . "\n";
        echo "new_contact_request:" . ($newContactRequests ? '1' : '0') . "\n";
        echo "timestamp:" . time() . "\n";
    } else {
        echo "no_updates\n";
    }
    exit;
}

// Get user based data
$totalBalance = $guiService->getUserTotalBalance();
$transactions = $guiService->getTransactionHistory(10);

// Contact data
$allContacts = $guiService->getAllContacts();
$acceptedContacts = $guiService->contactConversion($guiService->getAcceptedContacts());
$pendingContacts = $guiService->getPendingContacts();
$pendingUserContacts = $guiService->contactConversion($guiService->getUserPendingContacts());
$blockedContacts = $guiService->contactConversion($guiService->getBlockedContacts());