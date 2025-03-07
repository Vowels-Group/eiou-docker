<?php
// This file accepts incoming messages from the eIOU network
require_once("/var/www/html/eiou/functions.php");
if (!file_exists("/var/www/html/eiou/config.php")) {
  echo "eIOU has not yet been initiated. Please run from terminal to initialize the system.";
  die;
}
require_once("/var/www/html/eiou/config.php");


// Accept incoming connection and decode request
$request = json_decode($_GET['payload'], true);
//output("index file hit with request data: " . print_r($request, true), 'SILENT');

// Verify the request signature before processing
if (!verifyRequest($request)) {
    exit();
}

// Create PDO (db) connection
$pdo = createPDOConnection();

if ($request['type'] == "create") {
  // Handle contact request
  output("Processing create request", 'SILENT');
  echo handleContactCreation($request);  
}
elseif ($request['type'] == "send") {
  // Handle eIOU
  output("Processing send request", 'SILENT');
  echo processTransaction($request);
}
elseif ($request['type'] == "p2p") {
  // Handle Peers of Peers Request
  output("Processing p2p request: " . print_r($request, TRUE), 'SILENT');
  handleP2pRequest($request);
}
elseif ($request['type'] == "rp2p") {
  // Handle Peers of Peers Response
  output("Processing rp2p request: " . print_r($request, TRUE), 'SILENT');
  handleRp2pRequest($request);
}
else {
  output("Processing nonstandard request", 'SILENT');
  echo json_encode([
      'error' => 2,
      'message' => 'nonstandard request',
      'details' => $request
  ]);
}

?>