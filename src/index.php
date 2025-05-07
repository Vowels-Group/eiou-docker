<?php
// This file accepts incoming messages from the eIOU network
require_once("/etc/eiou/functions.php");
if (!file_exists("/etc/eiou/config.php")) {
  echo "eIOU has not yet been initiated. Please run from terminal to initialize the system.";
  die;
}
require_once("/etc/eiou/config.php");


// Accept incoming connection and decode request

//output("TYPE: " . print_r($_GET['type']), 'SILENT');

$request = json_decode($_GET['payload'], true);

// Verify the request signature before processing
if (!verifyRequest($request)) {
    exit();
}

// Create PDO (db) connection
$pdo = createPDOConnection();

if ($request['type'] == "create") {
  // Handle contact request
  #output("Processing create request " . print_r($request, TRUE), 'SILENT');
  output("Processing create request", 'SILENT');
  echo handleContactCreation($request);  
}
elseif ($request['type'] == "send") {
  // Handle eIOU
  #output("Processing send request " . print_r($request, TRUE), 'SILENT');
  output("Processing send request from " . print_r($request['senderAddress'],true) . " with hash " . print_r($request['hash'], TRUE), 'SILENT');
  if(!checkExistence($request)){
    processTransaction($request);
  }
}
elseif ($request['type'] == "p2p") {
  // Handle Peers of Peers Request
  output("Processing p2p request from " . print_r($request['senderAddress'],true) . " with hash " . print_r($request['hash'], TRUE), 'SILENT');
  //output("Processing p2p request: " . print_r($request, TRUE), 'SILENT');
  if(!checkExistence($request)){
    handleP2pRequest($request);
  }
}
elseif ($request['type'] == "rp2p") {
  // Handle Peers of Peers Response
  output("Processing rp2p request from " . print_r($request['senderAddress'],true) . " with hash " . print_r($request['hash'], TRUE), 'SILENT');
  if(!checkExistence($request)){
    handleRp2pRequest($request);
  }
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