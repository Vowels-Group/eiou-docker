<?php
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
  if ($request == "info") {
      output("Executing info request", 'SILENT');
      displayUserInfo($user);
  }
// Contacts
  elseif($request == "add"){
    // Add Contact
    output("Executing add contact request", 'SILENT');
    addContact($argv);
  }
  elseif($request == "read"){
    //Read Contact
    output("Executing read contact request", 'SILENT');
    readContact($argv);
  }
  elseif($request == "update"){
    //Update Contact
    output("Executing update contact request", 'SILENT');
    updateContact($argv);
  }
  elseif($request == "delete"){
    //Delete Contact
    output("Executing delete contact request", 'SILENT');
    deleteContact($argv);
  }
  elseif($request == "search"){
    //Search Contacts
    output("Executing search contacts request", 'SILENT');
    searchContacts($argv);
  }
//Transactions
  elseif($request == "send"){
    //Send eIOU
    output("Executing send eIOU request", 'SILENT');
    sendEiou($argv);
  }
  elseif($request == "view"){
    //View eIOUs
    output("Executing view balances request", 'SILENT');
    viewBalances($argv);
  }
  elseif($request == "history"){
    //View Transaction History
    output("Executing transaction history request", 'SILENT');
    viewTransactionHistory($argv);
  }
//Settings
  elseif($request == "help"){
    //Help
    output("Executing help request", 'SILENT');
    displayHelp();
  }
  elseif($request == "viewsettings"){
    //View Settings
    output("Executing view settings request", 'SILENT');
    displayCurrentSettings();
  }
  elseif($request == "changesettings"){
    //Change settings
    output("Executing change settings request", 'SILENT');
    changeSettings();
  }
  elseif($request == "generate"){
    //Generate Wallet
    output("Executing generate wallet request", 'SILENT');
    generateWallet($argv);
  }
  elseif($request == "restore"){
    //Restore Wallet
    output("Executing restore wallet request", 'SILENT');
    restoreWallet($argv);
  }
  elseif($request == "resign"){
    //Resign
    output("Executing resignation request", 'SILENT');
    echo "Initiating resignation process...\n";

    // Retrieve all contacts from the database
    $query = "SELECT address FROM contacts";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Current Unix timestamp
    $timestamp = time();

    // Message to be sent to all contacts, now including a timestamp and machine-readable format
    $resignationMessage = json_encode([
        'message' => 'I am resigning and no longer accept transactions from this address.',
        'timestamp' => $timestamp
    ]);

    // Loop through each contact and send the resignation message
    foreach ($contacts as $contact) {
        $contactAddress = $contact['address'];
        $payload = array(
            'type' => 'resign',
            'senderPublicKey' => $user['public'],
            'receiverAddress' => $contactAddress,
            'message' => $resignationMessage
        );

        // Sign the message
        $signature = null;
        openssl_sign($resignationMessage, $signature, $user['private'], OPENSSL_ALGO_SHA256);
        $payload['signature'] = base64_encode($signature);

        $signedPayload = sign($payload);

        send($signedPayload);

        echo "Resignation message sent to address: $contactAddress\n";
    }

    echo "Resignation process completed.\n";


  }
  elseif($request == "assignsuccession"){
    //Assign Succession
    output("Executing assign succession request", 'SILENT');
    if(exec('whoami') !== 'root'){
        echo "Please run 'sudo php /var/www/html/eiou/eiou.php assignsuccession' to assign succession.\n";
        exit;
    }

    echo "Enter the new wallet address to assign succession to: ";
    $newWalletAddress = trim(fgets(STDIN));

    // Verify the new wallet address
    if (!preg_match('/^[a-zA-Z0-9]{56}$/', $newWalletAddress)) {
        echo "Invalid wallet address format. Please provide a valid 56-character alphanumeric address.\n";
        exit;
    }

    // Retrieve all contacts from the database
    $query = "SELECT address FROM contacts";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Current Unix timestamp
    $timestamp = time();

    // Message to be sent to all contacts, including the new wallet address, timestamp, and machine-readable format
    $successionMessage = json_encode([
        'message' => 'I am assigning succession to the following wallet address:',
        'newWalletAddress' => $newWalletAddress,
        'timestamp' => $timestamp
    ]);

    // Loop through each contact and send the succession message
    foreach ($contacts as $contact) {
        $contactAddress = $contact['address'];
        $payload = array(
            'type' => 'assignsuccession',
            'senderPublicKey' => $user['public'],
            'receiverAddress' => $contactAddress,
            'message' => $successionMessage
        );

        // Sign the message
        $signature = null;
        openssl_sign($successionMessage, $signature, $user['private'], OPENSSL_ALGO_SHA256);
        $payload['signature'] = base64_encode($signature);

        send($payload, $user['private']);
        echo "Succession message sent to address: $contactAddress\n";
    }

    echo "Succession assignment completed. The new wallet address is: $newWalletAddress\n";
  }
  else{
    displayHelp();
    echo $request . " not found, displaying help above\n";
  }

// Check for pending contact requests for users with no default fee set
checkPendingContactRequests($pdo);