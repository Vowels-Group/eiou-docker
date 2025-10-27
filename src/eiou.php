<?php
# Copyright 2025

// This file is how users interact with eiou
require_once '/etc/eiou/functions.php';

$app = Application::getInstance();

// Convert request to lowercase
$request = strtolower($argv[1]);

if (!$app->currentUserLoaded()) {
  // Generate Wallet
  if($request === "generate"){
    $app->generateWallet($argv);
    die();
  } else{
    echo "Wallet does not exist, Please run the 'generate' command from the terminal.\n"; 
    die();
  }
} 

// Get Debug Service Instance
$debugService = $app->services->getDebugService();

// Info
if ($request === "info") {
  // Show user info (with/without details)
  $debugService->output("Executing info request",  'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->displayUserInfo($argv);
}
// Contacts
elseif($request === "add"){
  // Add Contact
  $debugService->output("Executing add contact request", 'SILENT');
  $contactService = $app->services->getContactService();
  $contactService->addContact($argv);
}
elseif($request === "viewcontact"){
  // View Contact
  $debugService->output("Executing read contact request", 'SILENT');
  $contactService = $app->services->getContactService();
  $contactService->viewContact($argv);
}
elseif($request === "update"){
  // Update Contact
  $debugService->output("Executing update contact request", 'SILENT');
  $contactService = $app->services->getContactService();
  $contactService->updateContact($argv);
}
elseif($request === "block"){
  // Block Contact
  $debugService->output("Executing block contact request", 'SILENT');
  // no check on argv[2] what is
  $contactService = $app->services->getContactService();
  $contactService->blockContact($argv[2]);
}
elseif($request === "unblock"){
  // Unblock Contact
  $debugService->output("Executing unblock contact request", 'SILENT');
  // no check on argv[2] what is
  $contactService = $app->services->getContactService();
  $contactService->unblockContact($argv[2]);
}
elseif($request === "delete"){
  // Delete Contact
  $debugService->output("Executing delete contact request", 'SILENT');
  // no check on argv[2] what is
  $contactService = $app->services->getContactService();
  $contactService->deleteContact($argv[2]);
}
elseif($request === "search"){
  // Search Contacts
  $debugService->output("Executing search contacts request", 'SILENT');
  $contactService = $app->services->getContactService();
  $contactService->searchContacts($argv);
}
// Transactions
elseif($request === "send"){
  // Send eIOU
  $debugService->output("Executing send eIOU request", 'SILENT');
  $transactionService = $app->services->getTransactionService();
  $transactionService->sendEiou($argv);
}
elseif($request === "viewbalances"){
  // View eIOUs
  $debugService->output("Executing view balances request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->viewBalances($argv);
}
elseif($request === "history"){
  // View Transaction History
  $debugService->output("Executing transaction history request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->viewTransactionHistory($argv);
}
// Settings
elseif($request === "help"){
  // Help
  $debugService->output("Executing help request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->displayHelp($argv);
}
elseif($request === "viewsettings"){
  // View Settings
  $debugService->output("Executing view settings request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->displayCurrentSettings();
}
elseif($request === "changesettings"){
  // Change settings
  $debugService->output("Executing change settings request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->changeSettings($argv);
}
elseif($request === "synch"){
  // Synch
  $debugService->output("Executing synch request", 'SILENT');
  $synchService = $app->services->getSynchService();
  $synchService->sych($argv);
} 
elseif($request === "shutdown"){
  // Shutdown application
  $debugService->output("Executing shutdown request", 'SILENT');
  $app->shutdown();
}
else{
  // If no known input, display commands possible for input
  $cliService = $app->services->getCliService();
  $cliService->displayHelp($argv);
  echo $request . " not found, displaying help above\n";
}

// // TO DO REMOVE?
// // Check for pending contact requests for users with no default fee set
// $contactService = $app->services->getContactService();
// $pending = $contactService->getPendingContactRequests();
// $pending_count = count($pending);
// // If there are pending contacts without a default fee, provide guidance
// if ($pending_count > 0) {
//     echo "\n\nYou have {$pending_count} contact request(s) pending acceptance.\n";
//     foreach ($pending as $contact) {
//         echo "Pending contact request from: " . $contact['address'] . "\n";
//         echo "To accept this contact request, use the command:\n";
//         echo "eiou add " . $contact['address'] . " [name] [fee percent] [credit] [currency]\n";
//         echo "Example: eiou add " . $contact['address'] . " Bob 0.1 100 USD\n\n";
//     }
// }