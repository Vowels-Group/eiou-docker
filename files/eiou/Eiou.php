<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * EIOU CLI Entry Point
 *
 * Main command-line interface for interacting with an EIOU wallet.
 * This script processes user commands and delegates to appropriate services.
 *
 * Usage: php eiou.php <command> [arguments] [--json]
 *
 * Commands:
 *   generate [restore <seed>]  - Generate new wallet or restore from seed
 *   info [detail]              - Display wallet information
 *   add <address> <name> ...   - Add a new contact
 *   send <address> <amount>    - Send an eIOU transaction
 *   viewbalances [contact]     - View balance(s)
 *   history [contact]          - View transaction history
 *   help [command]             - Display help information
 *   sync [type]                - Synchronize data
 *   ping <contact>             - Check contact online status
 *   shutdown                   - Graceful shutdown
 *
 * Output Flags:
 *   --json, -j     - Output in JSON format
 *   --no-metadata  - Exclude metadata from JSON output
 *
 * Rate Limiting:
 *   Commands are rate-limited per wallet to prevent abuse.
 *   Limits vary by command type (see $cliRateLimits below).
 */

// This file is how users interact with eiou
require_once '/etc/eiou/Functions.php';

// Initialize security components for CLI
require_once '/etc/eiou/SecurityInit.php';

// Load CLI output manager for JSON support
require_once '/etc/eiou/src/cli/CliOutputManager.php';

// Get application instance (if first time build database)
$app = Application::getInstance();

// Initialize CLI output manager with argv
$output = CliOutputManager::getInstance($argv, $app->getPublicKeyHash() ?? 'anonymous');

// Clean argv to remove output flags (--json, etc.) for command processing
$cleanArgv = CliOutputManager::cleanArgv($argv);

// Convert request to lowercase
$request = isset($cleanArgv[1]) ? strtolower($cleanArgv[1]) : '';

// Set command in output manager
$output->setCommand($request);

if (!$app->currentUserLoaded()) {
  // Generate Wallet
  if($request === "generate"){
    if(isset($cleanArgv[2]) && $cleanArgv[2] === "restore"){
      $app->restoreWallet($cleanArgv, $output);
      exit(0); // Normal termination after wallet restoration
    }
    $app->generateWallet($cleanArgv, $output);
    exit(0); // Normal termination after wallet generation
  } else{
    $app->getLogger()->warning("Attempted to run command without wallet", ['command' => $request]);
    $output->walletRequired();
    exit(1); // Exit with error code
  }
} 

// Get Debug Service Instance
$debugService = $app->services->getDebugService();

// Apply rate limiting for CLI commands (if database is available)
if ($app->currentPdoLoaded()) {
    $rateLimiter = $app->getRateLimiter();

    // Get CLI identifier (user + command for more granular limiting)
    $cliIdentifier = 'cli_' . ($app->getPublicKeyHash() ?? 'anonymous') . '_' . $request;

    // Define rate limits for different CLI commands
    $cliRateLimits = [
        'send' => ['max' => 30, 'window' => 60, 'block' => 300],      // 30 transactions per minute
        'add' => ['max' => 20, 'window' => 60, 'block' => 300],       // 20 contact additions per minute
        'generate' => ['max' => 5, 'window' => 300, 'block' => 900],  // 5 wallet generations per 5 minutes
        'default' => ['max' => 100, 'window' => 60, 'block' => 300]   // Default for other commands
    ];

    $limits = $cliRateLimits[$request] ?? $cliRateLimits['default'];
    $rateLimitResult = $rateLimiter->checkLimit($cliIdentifier, 'cli_' . $request, $limits['max'], $limits['window'], $limits['block']);

    if (!$rateLimitResult['allowed']) {
        $app->getLogger()->warning("CLI command rate limited", [
            'command' => $request,
            'identifier' => $cliIdentifier,
            'retry_after' => $rateLimitResult['retry_after']
        ]);
        $output->rateLimitExceeded($rateLimitResult['retry_after'], $request);
        exit(1);
    }
}

// Info
if ($request === "info") {
  // Show user info (with/without details)
  $debugService->output("Executing info request",  'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->displayUserInfo($cleanArgv, $output);
}
// Contacts
elseif($request === "add"){
  // Add Contact - validate input before processing
  $debugService->output("Executing add contact request", 'SILENT');
  $contactService = $app->services->getContactService();
  $contactService->addContact($cleanArgv, $output);
}
elseif($request === "viewcontact"){
  // View Contact
  $debugService->output("Executing read contact request", 'SILENT');
  $contactService = $app->services->getContactService();
  $contactService->viewContact($cleanArgv, $output);
}
elseif($request === "update"){
  // Update Contact
  $debugService->output("Executing update contact request", 'SILENT');
  $contactService = $app->services->getContactService();
  $contactService->updateContact($cleanArgv, $output);
}
elseif($request === "block"){
  // Block Contact
  $debugService->output("Executing block contact request", 'SILENT');
  $contactService = $app->services->getContactService();
  if (!$contactService->blockContact($cleanArgv[2] ?? null, $output)) {
    exit(1);
  }
}
elseif($request === "unblock"){
  // Unblock Contact
  $debugService->output("Executing unblock contact request", 'SILENT');
  $contactService = $app->services->getContactService();
  if (!$contactService->unblockContact($cleanArgv[2] ?? null, $output)) {
    exit(1);
  }
}
elseif($request === "delete"){
  // Delete Contact
  $debugService->output("Executing delete contact request", 'SILENT');
  $contactService = $app->services->getContactService();
  if (!$contactService->deleteContact($cleanArgv[2] ?? null, $output)) {
    exit(1);
  }
}
elseif($request === "search"){
  // Search Contacts
  $debugService->output("Executing search contacts request", 'SILENT');
  $contactService = $app->services->getContactService();
  $contactService->searchContacts($cleanArgv, $output);
}
elseif($request === "ping"){
  // Ping a contact to check online status
  $debugService->output("Executing ping contact request", 'SILENT');

  $identifier = $cleanArgv[2] ?? null;
  if (!$identifier) {
    $output->error("Contact name or address required", ErrorCodes::MISSING_ARGUMENT);
    exit(1);
  }

  $contactStatusService = $app->services->getContactStatusService();
  $result = $contactStatusService->pingContact($identifier);

  if ($result['success']) {
    $output->success("Ping complete: {$result['contact_name']} is {$result['online_status']}", [
      'contact_name' => $result['contact_name'],
      'online_status' => $result['online_status'],
      'chain_valid' => $result['chain_valid'],
      'message' => $result['message']
    ]);
  } else {
    $output->error($result['message'], $result['error'] === 'contact_not_found' ? ErrorCodes::CONTACT_NOT_FOUND : ErrorCodes::GENERAL_ERROR);
    exit(1);
  }
}
// Transactions
elseif($request === "send"){
  // Send eIOU
  $debugService->output("Executing send eIOU request", 'SILENT');
  $transactionService = $app->services->getTransactionService();
  $transactionService->sendEiou($cleanArgv, $output);
}
elseif($request === "viewbalances"){
  // View eIOUs
  $debugService->output("Executing view balances request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->viewBalances($cleanArgv, $output);
}
elseif($request === "history"){
  // View Transaction History
  $debugService->output("Executing transaction history request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->viewTransactionHistory($cleanArgv, $output);
}
// Settings
elseif($request === "help"){
  // Help
  $debugService->output("Executing help request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->displayHelp($cleanArgv, $output);
}
elseif($request === "viewsettings"){
  // View Settings
  $debugService->output("Executing view settings request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->displayCurrentSettings($output);
}
elseif($request === "changesettings"){
  // Change settings
  $debugService->output("Executing change settings request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->changeSettings($cleanArgv, $output);
}
elseif($request === "sync"){
  // Sync
  $debugService->output("Executing sync request", 'SILENT');
  $syncService = $app->services->getSyncService();
  $syncService->sync($cleanArgv, $output);
}
// Message queue processing commands for testing (requires EIOU_TEST_MODE=true)
elseif($request === "out"){
  if (getenv('EIOU_TEST_MODE') !== 'true') {
    $output->error("This command is only available in test mode", ErrorCodes::PERMISSION_DENIED);
    exit(1);
  }
  // Process outgoing message queue (pending transactions)
  $debugService->output("Processing outgoing message queue", 'SILENT');
  $transactionService = $app->services->getTransactionService();
  $txCount = $transactionService->processPendingTransactions();

  $output->success("Processed outgoing queue", [
    'transactions_processed' => $txCount
  ], "Outgoing queue processing complete");
}
elseif($request === "in"){
  if (getenv('EIOU_TEST_MODE') !== 'true') {
    $output->error("This command is only available in test mode", ErrorCodes::PERMISSION_DENIED);
    exit(1);
  }
  // Process incoming/held transactions
  $debugService->output("Processing incoming message queue", 'SILENT');

  // Process any held transactions that may have completed sync
  $heldService = $app->services->getHeldTransactionService();
  $result = ['processed_count' => 0, 'resumed_count' => 0, 'failed_count' => 0];
  if ($heldService !== null) {
    $result = $heldService->processHeldTransactions();
  }

  $output->success("Processed incoming queue", $result, "Incoming queue processing complete");
}
elseif($request === "generate"){
  // Handle for Wallet request after wallet has been created
  $output->walletExists();
}
elseif($request === "shutdown"){
  // Shutdown application
  $debugService->output("Executing shutdown request", 'SILENT');
  $app->shutdown($output);
}
// API Key Management
elseif($request === "apikey"){
  // Manage API keys
  $debugService->output("Executing API key management request", 'SILENT');
  $apiKeyService = $app->services->getApiKeyService($output);
  $apiKeyService->handleCommand($cleanArgv);
}
else{
  // If no known input, display commands possible for input
  $cliService = $app->services->getCliService();
  $cliService->displayHelp($cleanArgv, $output);
  $output->error("Command '$request' not found", ErrorCodes::COMMAND_NOT_FOUND, 404);
}