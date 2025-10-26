<?php
# Copyright 2025

// This file is how users interact with eiou
require_once '/etc/eiou/functions.php';

// Check if config.php exists, if not run freshInstall() through the Application
if (!file_exists('/etc/eiou/dbconfig.php')) {
  // Performs a fresh installation of the eIOU system by creating db configuration files, database, and necessary tables
  $app = Application::getInstance();
}
if(!isset($app)){
  $app = Application::getInstance();
}

// Convert request to lowercase
$request = strtolower($argv[1]);

if (!$app->currentUserLoaded()) {
  // Generate Wallet
  if($request === "generate"){
    //output("Executing generate wallet request", 'SILENT');
    $app->generateWallet($argv);
    //$app->loadCurrentDb();
    //$app->loadCurrentUser();
  } else{
    /// BLA BLA FIX YOUR SHITE FIRST
    die();
  }
} 
die();
$app->loadserviceContainer();
$cliService = $app->services->getCliService();
$contactService = $app->services->getContactService();
$transactionService = $app->services->getTransactionService();
$synchService = $app->services->getSynchService();

// Info
  // Call the function in the info section
  if ($request === "info") {
      output("Executing info request",  'SILENT');
      $cliService->displayUserInfo($argv);
  }
// Contacts
  elseif($request === "add"){
    // Add Contact
    output("Executing add contact request", 'SILENT');
    $contactService->addContact($argv);
  }
  elseif($request === "viewcontact"){
    // View Contact
    output("Executing read contact request", 'SILENT');
    $contactService->viewContact($argv);
  }
  elseif($request === "update"){
    // Update Contact
    output("Executing update contact request", 'SILENT');
    $contactService->updateContact($argv);
  }
  elseif($request === "block"){
    // Block Contact
    output("Executing block contact request", 'SILENT');
    // no check on argv[2] what is
    $contactService->blockContact($argv[2]);
  }
  elseif($request === "unblock"){
    // Unblock Contact
    output("Executing unblock contact request", 'SILENT');
    // no check on argv[2] what is
    $contactService->unblockContact($argv[2]);
  }
  elseif($request === "delete"){
    // Delete Contact
    output("Executing delete contact request", 'SILENT');
    // no check on argv[2] what is
    $contactService->deleteContact($argv[2]);
  }
  elseif($request === "search"){
    // Search Contacts
    output("Executing search contacts request", 'SILENT');
    $contactService->searchContacts($argv);
  }
// Transactions
  elseif($request === "send"){
    // Send eIOU
    output("Executing send eIOU request", 'SILENT');
    $transactionService->sendEiou($argv);
  }
  elseif($request === "viewbalances"){
    // View eIOUs
    output("Executing view balances request", 'SILENT');
    $cliService->viewBalances($argv);
  }
  elseif($request === "history"){
    // View Transaction History
    output("Executing transaction history request", 'SILENT');
    $cliService->viewTransactionHistory($argv);
  }
// Settings
  elseif($request === "help"){
    // Help
    output("Executing help request", 'SILENT');
    $cliService->displayHelp($argv);
  }
  elseif($request === "viewsettings"){
    // View Settings
    output("Executing view settings request", 'SILENT');
    $cliService->displayCurrentSettings();
  }
  elseif($request === "changesettings"){
    // Change settings
    output("Executing change settings request", 'SILENT');
    $cliService->changeSettings($argv);
  }
  elseif($request === "synch"){
    // Synch
    output("Executing synch request", 'SILENT');
    $synchService->sych($argv);
  } 
  elseif($request === "shutdown"){
    // Shutdown application
    output("Executing shutdown request", 'SILENT');
    $app->shutdown();
  }
  else{
    // If no known input, display commands possible for input
    $cliService->displayHelp($argv);
    echo $request . " not found, displaying help above\n";
  }

// Check for pending contact requests for users with no default fee set
$pending = $contactService->getPendingContactRequests();
$pending_count = count($pending);
// If there are pending contacts without a default fee, provide guidance
if ($pending_count > 0) {
    echo "\n\nYou have {$pending_count} contact request(s) pending acceptance.\n";
    foreach ($pending as $contact) {
        echo "Pending contact request from: " . $contact['address'] . "\n";
        echo "To accept this contact request, use the command:\n";
        echo "eiou add " . $contact['address'] . " [name] [fee percent] [credit] [currency]\n";
        echo "Example: eiou add " . $contact['address'] . " Bob 0.1 100 USD\n\n";
    }
}