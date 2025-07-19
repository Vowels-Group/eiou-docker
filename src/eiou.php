<?php
# Copyright 2025

// This file is how users interact with eiou
require_once("/etc/eiou/functions.php");
// Check if config.php exists, if not run freshInstall()
if (!file_exists('/etc/eiou/config.php')) {
  // Performs a fresh installation of the eIOU system by creating configuration files, database, and necessary tables
  freshInstall();
}
require_once("/etc/eiou/config.php");

// Convert request to lowercase
$request = strtolower($argv[1]);

// Create PDO (db) connection
$pdo = createPDOConnection();

// Check if user has a key set in config
checkWalletExists($user, $request);

// Info
  // Call the function in the info section
  if ($request === "info") {
      output("Executing info request",  'SILENT');
      displayUserInfo($argv);
  }
// Contacts
  elseif($request === "add"){
    // Add Contact
    output("Executing add contact request", 'SILENT');
    addContact($argv);
  }
  elseif($request === "block"){
    // Block Contact
    output("Executing block contact request", 'SILENT');
    blockContact($argv);
  }
  elseif($request === "unblock"){
    // Unblock Contact
    output("Executing unblock contact request", 'SILENT');
    unblockContact($argv);
  }
  elseif($request === "viewcontact"){
    // View Contact
    output("Executing read contact request", 'SILENT');
    viewContact($argv);
  }
  elseif($request === "update"){
    // Update Contact
    output("Executing update contact request", 'SILENT');
    updateContact($argv);
  }
  elseif($request === "delete"){
    // Delete Contact
    output("Executing delete contact request", 'SILENT');
    deleteContact($argv);
  }
  elseif($request === "search"){
    // Search Contacts
    output("Executing search contacts request", 'SILENT');
    searchContacts($argv);
  }
// Transactions
  elseif($request === "send"){
    // Send eIOU
    output("Executing send eIOU request", 'SILENT');
    sendEiou($argv);
  }
  elseif($request === "viewbalances"){
    // View eIOUs
    output("Executing view balances request", 'SILENT');
    viewBalances($argv);
  }
  elseif($request === "history"){
    // View Transaction History
    output("Executing transaction history request", 'SILENT');
    viewTransactionHistory($argv);
  }
// Settings
  elseif($request === "help"){
    // Help
    output("Executing help request", 'SILENT');
    displayHelp($argv);
  }
  elseif($request === "viewsettings"){
    // View Settings
    output("Executing view settings request", 'SILENT');
    displayCurrentSettings();
  }
  elseif($request === "changesettings"){
    // Change settings
    output("Executing change settings request", 'SILENT');
    changeSettings($argv);
  }
  elseif($request === "generate"){
    // Generate Wallet
    output("Executing generate wallet request", 'SILENT');
    generateWallet($argv);
  }
  // For Future version
  // elseif($request === "restore"){
  //   //Restore Wallet
  //   output("Executing restore wallet request", 'SILENT');
  //   restoreWallet($argv);
  // }
  else{
    displayHelp($argv);
    echo $request . " not found, displaying help above\n";
  }

// Check for pending contact requests for users with no default fee set
checkPendingContactRequests();