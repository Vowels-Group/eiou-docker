<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * eIOU CLI Entry Point
 *
 * Main command-line interface for interacting with an eIOU wallet.
 * This script processes user commands and delegates to appropriate services.
 *
 * Usage: php eiou.php <command> [arguments] [--json]
 *
 * Commands:
 *   generate [restore <seed>]  - Generate new wallet or restore from seed
 *   info [detail] [--show-auth] - Display wallet information
 *   add <address> <"name"> ... - Add a new contact (quote multi-word names)
 *   send <address|"name"> <amount> - Send an eIOU transaction
 *   viewbalances [contact]     - View balance(s)
 *   history [contact]          - View transaction history
 *   pending                    - View pending contact requests
 *   p2p [subcommand] [args]    - Manage P2P transactions awaiting approval
 *   dlq [list|retry|abandon]   - Manage dead letter queue (failed messages)
 *   overview [limit]           - View wallet overview dashboard
 *   report <type>              - Generate reports (debug [--send], etc.)
 *   request [subcommand] [args] - Manage payment requests
 *   help [command]             - Display help information
 *   sync [type]                - Synchronize data
 *   ping <contact>             - Check contact online status
 *   updatecheck                - Check for newer image versions
 *   shutdown                   - Graceful shutdown
 *   restart                    - Restart processors and PHP-FPM workers (in-place)
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
require_once '/app/eiou/Functions.php';

// Initialize security components for CLI
require_once '/app/eiou/SecurityInit.php';

use Eiou\Core\Application;
use Eiou\Core\ErrorCodes;
use Eiou\Cli\CliOutputManager;
use Eiou\Exceptions\ServiceException;
use Eiou\Exceptions\FatalServiceException;
use Eiou\Exceptions\RecoverableServiceException;
use Eiou\Exceptions\ValidationServiceException;

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

// Apply rate limiting for CLI commands (if database is available and not in test mode)
if ($app->currentPdoLoaded() && getenv('EIOU_TEST_MODE') !== 'true') {
    $rateLimiter = $app->getRateLimiter();

    // Get CLI identifier (user + command for more granular limiting)
    $cliIdentifier = 'cli_' . ($app->getPublicKeyHash() ?? 'anonymous') . '_' . $request;

    // Define rate limits for different CLI commands
    $cliRateLimits = [
        'send' => ['max' => 30, 'window' => 60, 'block' => 300],      // 30 transactions per minute
        'add' => ['max' => 20, 'window' => 60, 'block' => 300],       // 20 contact additions per minute
        'generate' => ['max' => 5, 'window' => 300, 'block' => 900],  // 5 wallet generations per 5 minutes
        'backup' => ['max' => 10, 'window' => 60, 'block' => 300],    // 10 backup operations per minute
        'chaindrop' => ['max' => 10, 'window' => 60, 'block' => 300], // 10 tx drop operations per minute
        'report' => ['max' => 10, 'window' => 60, 'block' => 300],    // 10 report generations per minute
        'p2p' => ['max' => 30, 'window' => 60, 'block' => 300],       // 30 P2P approval operations per minute
        'request' => ['max' => 20, 'window' => 60, 'block' => 300],   // 20 payment request operations per minute
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

// Wrap command dispatch in try-catch to handle ServiceExceptions
try {

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
elseif($request === "pending"){
  // View pending contact requests
  $debugService->output("Executing pending contacts request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->displayPendingContacts($cleanArgv, $output);
}
elseif($request === "overview"){
  // View wallet overview
  $debugService->output("Executing overview request", 'SILENT');
  $cliService = $app->services->getCliService();
  $cliService->displayOverview($cleanArgv, $output);
}
elseif($request === "p2p"){
  // P2P approval management
  $debugService->output("Executing P2P approval request", 'SILENT');
  $cliService = $app->services->getCliService();
  $subcommand = strtolower($cleanArgv[2] ?? 'list');
  if ($subcommand === 'candidates') {
    $cliService->displayP2pCandidates($cleanArgv, $output);
  } elseif ($subcommand === 'approve') {
    $cliService->approveP2p($cleanArgv, $output);
  } elseif ($subcommand === 'reject') {
    $cliService->rejectP2p($cleanArgv, $output);
  } else {
    // Default: list pending P2P transactions
    $cliService->displayPendingP2p($cleanArgv, $output);
  }
}
elseif($request === "dlq"){
  // Dead letter queue management
  $debugService->output("Executing DLQ request", 'SILENT');
  $cliService = $app->services->getCliService();
  $subcommand = strtolower($cleanArgv[2] ?? 'list');
  if ($subcommand === 'retry') {
    $cliService->retryDlqItem($cleanArgv, $output);
  } elseif ($subcommand === 'abandon') {
    $cliService->abandonDlqItem($cleanArgv, $output);
  } else {
    // Default: list DLQ items
    $cliService->displayDlqItems($cleanArgv, $output);
  }
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
elseif($request === "start"){
  // Start processors after a previous shutdown
  $debugService->output("Executing start request", 'SILENT');
  $app->start($output);
}
elseif($request === "restart"){
  // Full node restart: respawn processors AND PHP-FPM workers so freshly
  // enabled plugins (or any other startup-bound state) take effect without
  // a container reboot. Requires root inside the container to signal the
  // PHP-FPM master.
  $debugService->output("Executing restart request", 'SILENT');
  $app->restart($output);
}
// Plugin management — list/enable/disable. Does NOT restart the node;
// operator must run `eiou restart` (or hit the REST/GUI equivalent) for
// enable/disable to take effect, since event subscriptions bind during
// boot.
elseif($request === "plugin"){
  $debugService->output("Executing plugin request", 'SILENT');
  if ($app->pluginLoader === null) {
    $output->error('Plugin system not initialized', ErrorCodes::GENERAL_ERROR);
    exit(1);
  }
  $pluginCliService = new \Eiou\Services\CliPluginService($app->pluginLoader);
  $subcommand = strtolower($cleanArgv[2] ?? 'list');
  if ($subcommand === 'enable') {
    $pluginCliService->enablePlugin($cleanArgv, $output);
  } elseif ($subcommand === 'disable') {
    $pluginCliService->disablePlugin($cleanArgv, $output);
  } else {
    $pluginCliService->listPlugins($cleanArgv, $output);
  }
}
// API Key Management
elseif($request === "apikey"){
  // Manage API keys
  $debugService->output("Executing API key management request", 'SILENT');
  $apiKeyService = $app->services->getApiKeyService($output);
  $apiKeyService->handleCommand($cleanArgv);
}
// Backup Management
elseif($request === "backup"){
  $debugService->output("Executing backup request", 'SILENT');
  $backupService = $app->services->getBackupService();
  $backupService->handleCommand($cleanArgv, $output);
}
// Chain integrity audit — safety net for archived chains
// Walks every bilateral chain end-to-end (live + archive) and verifies
// each pair's archive hash against the stored checkpoint. Exits 1 if
// any pair has findings (gap or hash mismatch).
elseif($request === "verify-chain"){
  $debugService->output("Executing verify-chain request", 'SILENT');
  $chainAuditService = $app->services->getChainAuditService();
  $exitCode = $chainAuditService->handleCommand($cleanArgv, $output);
  exit($exitCode);
}
// Tx Drop Agreement
elseif($request === "chaindrop"){
  $debugService->output("Executing chain drop request", 'SILENT');
  $chainDropService = $app->services->getChainDropService();
  $chainDropService->handleCommand($cleanArgv, $output);
}
// Payment Requests
elseif($request === "request"){
  $debugService->output("Executing payment request command", 'SILENT');
  $prService = $app->services->getPaymentRequestService();
  $subcommand = strtolower($cleanArgv[2] ?? 'list');

  if ($subcommand === 'create') {
    // eiou request create <contact> <amount> <currency> [description]
    $contact = $cleanArgv[3] ?? '';
    $amount = $cleanArgv[4] ?? '';
    $currency = $cleanArgv[5] ?? '';
    $description = $cleanArgv[6] ?? null;

    if (empty($contact) || empty($amount) || empty($currency)) {
      $output->error("Usage: eiou request create <contact> <amount> <currency> [description]", ErrorCodes::MISSING_ARGUMENT);
      exit(1);
    }

    $result = $prService->create($contact, $amount, $currency, $description);
    if ($result['success']) {
      $output->success("Payment request sent", [
        'request_id' => $result['request_id'],
        'contact' => $contact,
        'amount' => $amount,
        'currency' => $currency,
      ]);
    } else {
      $output->error($result['error'], $result['code'] ?? ErrorCodes::GENERAL_ERROR);
      exit(1);
    }
  } elseif ($subcommand === 'approve') {
    // eiou request approve <request_id>
    $requestId = $cleanArgv[3] ?? '';
    if (empty($requestId)) {
      $output->error("Usage: eiou request approve <request_id>", ErrorCodes::MISSING_ARGUMENT);
      exit(1);
    }

    $result = $prService->approve($requestId);
    if ($result['success']) {
      $output->success($result['message'] ?? "Payment request approved", [
        'request_id' => $requestId,
        'txid' => $result['txid'] ?? null,
      ]);
    } else {
      $output->error($result['error'], ErrorCodes::GENERAL_ERROR);
      exit(1);
    }
  } elseif ($subcommand === 'decline') {
    // eiou request decline <request_id>
    $requestId = $cleanArgv[3] ?? '';
    if (empty($requestId)) {
      $output->error("Usage: eiou request decline <request_id>", ErrorCodes::MISSING_ARGUMENT);
      exit(1);
    }

    $result = $prService->decline($requestId);
    if ($result['success']) {
      $output->success($result['message'] ?? "Payment request declined", [
        'request_id' => $requestId,
      ]);
    } else {
      $output->error($result['error'], ErrorCodes::GENERAL_ERROR);
      exit(1);
    }
  } elseif ($subcommand === 'cancel') {
    // eiou request cancel <request_id>
    $requestId = $cleanArgv[3] ?? '';
    if (empty($requestId)) {
      $output->error("Usage: eiou request cancel <request_id>", ErrorCodes::MISSING_ARGUMENT);
      exit(1);
    }

    $result = $prService->cancel($requestId);
    if ($result['success']) {
      $output->success($result['message'] ?? "Payment request cancelled", [
        'request_id' => $requestId,
      ]);
    } else {
      $output->error($result['error'], ErrorCodes::GENERAL_ERROR);
      exit(1);
    }
  } elseif ($subcommand === 'list') {
    // eiou request list
    $requests = $prService->getAllForDisplay(50);
    $output->success("Payment requests", $requests);
  } else {
    echo "Usage: eiou request <subcommand> [args]\n\n";
    echo "Subcommands:\n";
    echo "  list                                     List all payment requests\n";
    echo "  create <contact> <amount> <currency> [desc]  Create a payment request\n";
    echo "  approve <request_id>                     Approve an incoming request (sends eIOU)\n";
    echo "  decline <request_id>                     Decline an incoming request\n";
    echo "  cancel <request_id>                      Cancel an outgoing request\n";
    echo "\nExamples:\n";
    echo "  eiou request list\n";
    echo "  eiou request create \"Alice\" 25.00 USD \"Dinner last week\"\n";
    echo "  eiou request approve req_abc123\n";
    echo "  eiou request decline req_abc123\n";
    echo "  eiou request cancel req_abc123\n";
  }
}
// Reports
elseif($request === "report"){
  $debugService->output("Executing report request", 'SILENT');
  $subcommand = $cleanArgv[2] ?? 'help';

  if ($subcommand === 'debug') {
    $description = $cleanArgv[3] ?? '';
    $full = in_array('--full', $cleanArgv, true);
    $send = in_array('--send', $cleanArgv, true);
    $reportService = $app->services->getDebugReportService();

    // Strip flags from description if user put them as positional arg
    if ($description === '--full' || $description === '--send') {
      $description = '';
    }

    try {
      if ($send) {
        // Generate and submit to support
        $report = $reportService->generateReport($description, $full);
        $result = \Eiou\Services\DebugReportService::submit($report, $description);

        if ($result['success']) {
          $output->success(
            "Debug report sent to support" . ($result['key'] ? " (ref: {$result['key']})" : ''),
            [
              'key' => $result['key'],
              'report_type' => $full ? 'full' : 'limited',
            ],
            'Debug report submitted'
          );
        } else {
          $output->error($result['error'] ?? 'Submission failed', ErrorCodes::GENERAL_ERROR, 500);
        }
      } else {
        // Generate and save to file (existing behavior)
        $result = $reportService->generateAndSave($description, $full);
        $sizeKb = round($result['size'] / 1024, 1);
        $output->success(
          "Debug report saved to {$result['path']} ({$sizeKb} KB)",
          [
            'path' => $result['path'],
            'size' => $result['size'],
            'size_human' => $sizeKb . ' KB',
            'report_type' => $full ? 'full' : 'limited',
            'debug_entries' => $result['report']['debug_entries_count'],
          ],
          'Debug report generated'
        );
      }
    } catch (\RuntimeException $e) {
      $output->error($e->getMessage(), ErrorCodes::INTERNAL_ERROR, 500);
    }
  } else {
    echo "Usage: eiou report <type>\n\n";
    echo "Available report types:\n";
    echo "  debug [description] [--full] [--send]   Generate a debug report\n";
    echo "\nOptions:\n";
    echo "  --full    Include full log history (default: last 50 lines)\n";
    echo "  --send    Send report to support (default: save to file)\n";
    echo "\nExamples:\n";
    echo "  eiou report debug\n";
    echo "  eiou report debug \"login page crash\"\n";
    echo "  eiou report debug --full\n";
    echo "  eiou report debug \"issue description\" --full\n";
    echo "  eiou report debug --send\n";
    echo "  eiou report debug \"login crash\" --full --send\n";
  }
}
// Update Check
elseif($request === "updatecheck"){
  $debugService->output("Executing update check request", 'SILENT');
  $result = \Eiou\Services\UpdateCheckService::check(true);
  if ($result === null) {
    $output->error("Update check failed — could not reach Docker Hub or GitHub", ErrorCodes::GENERAL_ERROR);
    exit(1);
  }
  if ($result['available']) {
    $output->success(
      "Update available: {$result['latest_version']} (current: {$result['current_version']})",
      $result,
      "Run: docker pull eiou/eiou:{$result['latest_version']}"
    );
  } else {
    $output->success("Up to date: {$result['current_version']}", $result);
  }
}
else{
  // If no known input, display commands possible for input
  $cliService = $app->services->getCliService();
  $cliService->displayHelp($cleanArgv, $output);
  $output->error("Command '$request' not found", ErrorCodes::COMMAND_NOT_FOUND, 404);
}

} catch (ValidationServiceException $e) {
    // Handle validation errors - input validation failures
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $e->getContext());
    $app->getLogger()->warning("Validation error", [
        'command' => $request,
        'field' => $e->getField(),
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode()
    ]);
    exit($e->getExitCode());

} catch (FatalServiceException $e) {
    // Handle fatal errors - unrecoverable service failures
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $e->getContext());
    $app->getLogger()->error("Fatal service error", [
        'command' => $request,
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext()
    ]);
    exit($e->getExitCode());

} catch (RecoverableServiceException $e) {
    // Handle recoverable errors - may succeed on retry
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $e->getContext());
    $app->getLogger()->info("Recoverable service error", [
        'command' => $request,
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode()
    ]);
    exit($e->getExitCode());

} catch (ServiceException $e) {
    // Catch-all for any ServiceException subclass not handled above
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $e->getContext());
    $app->getLogger()->error("Service error", [
        'command' => $request,
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode()
    ]);
    exit($e->getExitCode());
}