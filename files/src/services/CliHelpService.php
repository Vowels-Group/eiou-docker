<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Cli\CliOutputManager;

/**
 * Provides help display functionality for the eIOU CLI.
 *
 * Extracted from CliService as part of the ARCH-04 refactor to decompose the
 * monolithic CliService into focused, single-responsibility service classes.
 * Contains the displayHelp(), showDetailedHelp(), showApiKeyDetailedHelp(),
 * and showChainDropDetailedHelp() methods verbatim from CliService.
 */
class CliHelpService
{
    /**
     * Display available commands to user in the CLI
     *
     * @param array $argv The CLI input data
     * @param CliOutputManager|null $output Optional output manager for JSON support
    */
    public function displayHelp(array $argv, ?CliOutputManager $output = null) {
        $output = $output ?? CliOutputManager::getInstance();

        // Define all commands with their metadata
        $commands = [
            'info' => [
                'description' => 'Display wallet information including addresses, public key, fee earnings, and available credit',
                'usage' => 'info ([detail]) ([--show-auth])',
                'arguments' => [
                    'detail' => ['type' => 'optional', 'description' => 'Show detailed balance information with sent/received breakdown per currency'],
                    '--show-auth' => ['type' => 'optional', 'description' => 'Securely display auth code via temp file (never shown in logs)']
                ],
                'examples' => [
                    'info' => 'Basic wallet info (auth code redacted)',
                    'info detail' => 'Detailed info with balance breakdown',
                    'info --show-auth' => 'Show authentication code securely via temp file',
                    'info --show-auth --json' => 'JSON output with auth code file path'
                ],
                'note' => 'The auth code is never exposed in command output to prevent leaks via Docker logs, shell history, or screen sharing. With --show-auth, the code is stored in a memory-only temp file (/dev/shm/) that auto-deletes after 5 minutes.'
            ],
            'contact' => [
                'description' => 'Manage contacts and per-currency relationships (add, accept, apply, decline, list, view, update, delete, block/unblock, ping, search, currency …)',
                'usage' => 'contact <subcommand> [args...]',
                'arguments' => [
                    'subcommand' => ['type' => 'required', 'description' => 'add | accept | apply | decline | list | pending | view | update | delete | block | unblock | ping | search | currency'],
                ],
                'actions' => [
                    'add' => [
                        'usage' => 'contact add <address> <name> [--fee F --credit C --currency CCY] [--requested-credit RC] [--message M]',
                        'description' => 'Send an outbound contact request. Flags can appear in any order. --requested-credit RC asks the receiver to extend you a credit limit of RC in this currency (a suggestion sent over the wire — receiver chooses what to actually grant on accept).',
                    ],
                    'accept' => [
                        'usage' => 'contact accept <pubkey-hash|address|name> --currency CCY --fee F --credit C [--currency CCY --fee F --credit C ...]',
                        'description' => 'Accept an incoming contact request. Repeat the --currency/--fee/--credit triplet to accept several currencies in one call.',
                    ],
                    'apply' => [
                        'usage' => 'contact apply <pubkey-hash|address|name> --from <file.json|->  |  [--accept CCY:fee:credit ...] [--decline CCY ...] [--defer CCY ...]',
                        'description' => 'Apply a batched mix of accept / decline / defer decisions in one call. Mirrors the GUI batched-apply modal; pipe modal payload via --from -.',
                    ],
                    'decline' => [
                        'usage' => 'contact decline <pubkey-hash|address|name>',
                        'description' => 'Decline every pending currency on an incoming contact request.',
                    ],
                    'list' => [
                        'usage' => 'contact list [--status accepted|pending|blocked]',
                        'description' => 'List contacts grouped by status (omit --status to see all buckets).',
                    ],
                    'pending' => [
                        'usage' => 'contact pending [--incoming|--outgoing]',
                        'description' => 'Show pending contact requests. Each incoming row prints a paste-ready accept/decline command line.',
                    ],
                    'view' => [
                        'usage' => 'contact view <name|address|pubkey-hash>',
                        'description' => 'View detailed contact info: per-currency balances, fee/credit settings, your/their available credit (refreshed via ping/pong).',
                    ],
                    'update' => [
                        'usage' => 'contact update <name|address> <name|fee|credit|all> <values…>',
                        'description' => 'Local-only update. fee/credit require a trailing currency code; all takes <new-name> <fee> <credit> [<CCY>].',
                    ],
                    'delete' => [
                        'usage' => 'contact delete <name|address>',
                        'description' => 'Permanently remove the contact (transaction history is preserved).',
                    ],
                    'block' => [
                        'usage' => 'contact block <name|address>',
                        'description' => 'Block incoming transactions and P2P relay traffic from this contact.',
                    ],
                    'unblock' => [
                        'usage' => 'contact unblock <name|address>',
                        'description' => 'Restore the contact to its previous (accepted or pending) status.',
                    ],
                    'ping' => [
                        'usage' => 'contact ping <name|address>',
                        'description' => 'Check online status, exchange per-currency available credit, and verify chain heads. Mismatches trigger sync; unrecoverable gaps auto-propose a tx drop.',
                    ],
                    'search' => [
                        'usage' => 'contact search [query]',
                        'description' => 'Substring search by name (omit query to list all).',
                    ],
                    'currency' => [
                        'usage' => 'contact currency <add|accept|decline|list|remove> <contact> [<currency>] [--fee F --credit C]',
                        'description' => 'Per-currency operations on an already-accepted contact (add proposes a new currency, accept/decline/list/remove handle the remote-acked lifecycle).',
                    ],
                ],
                'examples' => [
                    'contact' => 'Show the full contact subcommand tree (same as `contact help`)',
                    'contact add http://bob Bob --fee 0.1 --credit 1000 --currency USD' => 'Send a contact request to Bob (USD)',
                    'contact add http://bob Bob --fee 0.1 --credit 1000 --currency USD --requested-credit 500' => 'Same request, asking Bob to extend you a 500 USD credit limit',
                    'contact pending --json | jq .data.incoming[0].pubkey_hash' => 'Pull the requester\'s pubkey-hash for accept',
                    'contact accept abc123hash --currency USD --fee 0.1 --credit 1000' => 'Accept a single-currency incoming request',
                    'contact accept abc123hash --currency USD --fee 0.1 --credit 1000 --currency EUR --fee 0.05 --credit 500' => 'Accept multiple currencies in one call',
                    'contact apply abc123hash --accept USD:0.1:1000 --decline EUR --defer XRP' => 'Batched accept/decline/defer',
                    'contact decline abc123hash' => 'Decline every pending currency on the request',
                    'contact list --status accepted' => 'Show only accepted contacts',
                    'contact view Bob' => 'View Bob\'s details by name',
                    'contact update Bob name Robert' => 'Rename Bob locally',
                    'contact update Bob fee 1.5 USD' => 'Change the fee percentage you charge to relay Bob\'s USD txs',
                    'contact ping Bob' => 'Check Bob\'s online status + chain validity',
                    'contact currency add Bob EUR --fee 0.05 --credit 500' => 'Propose a new currency on an accepted contact',
                    'contact currency accept Bob EUR --fee 0.05 --credit 500' => 'Accept Bob\'s incoming per-currency proposal',
                ],
                'note' => 'Subcommand-specific help is served by `eiou contact` (or `eiou contact help`) and `eiou contact currency`. There is no `eiou help contact <subcmd>` form. The legacy top-level verbs (eiou add / delete / block / unblock / viewcontact / update / search / ping / pending / accept) were dropped in v0.1.14 — every contact operation lives under this namespace now. Rate limited: 20 contact ops per minute (the `contact` rate-limit bucket).'
            ],
            'send' => [
                'description' => 'Send an eIOU transaction to a contact (direct or P2P relayed)',
                'usage' => 'send [address/"name"] [amount] [currency] ([description]) (--best)',
                'arguments' => [
                    'address/name' => ['type' => 'required', 'description' => 'Recipient address or name (use quotes for multi-word names: "John Doe")'],
                    'amount' => ['type' => 'required', 'description' => 'Amount to send (positive number)'],
                    'currency' => ['type' => 'required', 'description' => 'Currency code (e.g., USD)'],
                    'description' => ['type' => 'optional', 'description' => 'Optional description attached to the transaction (visible to recipient)'],
                    '--best' => ['type' => 'optional', 'description' => '[EXPERIMENTAL] Collect all route responses and select lowest fee. Slower but cheaper. Ignored for Tor recipients.']
                ],
                'examples' => [
                    'send Bob 50 USD' => 'Send by contact name (fast mode)',
                    'send http://bob:8080 100 USD' => 'Send by address',
                    'send Bob 50 USD "Coffee payment"' => 'Send with description',
                    'send Bob 50 USD --best' => 'Best-fee routing (experimental)',
                    'send Alice 25.50 USD --json' => 'JSON output'
                ],
                'note' => 'Direct contacts receive the transaction immediately. Non-contacts are reached via P2P relay through intermediaries. Default fast mode uses first available route. Chain integrity is verified before every send; gaps trigger auto-sync and chain drop proposal if needed. Rate limited: 30 per minute.'
            ],
            'viewbalances' => [
                'description' => 'View eIOU balances with all contacts or a specific contact',
                'usage' => 'viewbalances ([address/name])',
                'arguments' => [
                    'address/name' => ['type' => 'optional', 'description' => 'Filter by contact address or name. Omit to view all balances.']
                ],
                'examples' => [
                    'viewbalances' => 'View all balances',
                    'viewbalances Bob' => 'View balance with specific contact',
                    'viewbalances --json' => 'JSON output'
                ],
                'note' => 'Shows received, sent, and net balance per contact per currency. Balances are calculated from verified transaction chains. Use "all" as name to explicitly show all contacts.'
            ],
            'history' => [
                'description' => 'View transaction history with all contacts or a specific contact',
                'usage' => 'history ([address/name]) ([limit])',
                'arguments' => [
                    'address/name' => ['type' => 'optional', 'description' => 'Filter by contact address or name'],
                    'limit' => ['type' => 'optional', 'description' => 'Maximum transactions to display (0 = unlimited)']
                ],
                'examples' => [
                    'history' => 'View all transaction history',
                    'history Bob' => 'View history with specific contact',
                    'history Bob 0' => 'View all history with Bob (no limit)',
                    'history --json' => 'JSON output'
                ],
                'note' => 'Shows transaction ID, direction (sent/received), amount, currency, timestamp, and contact name. Default limit is controlled by the maxOutput setting. Transactions are shown newest first.'
            ],
            'p2p' => [
                'description' => 'Manage P2P transactions awaiting approval',
                'usage' => 'p2p ([subcommand]) ([args...])',
                'arguments' => [
                    'subcommand' => ['type' => 'optional', 'description' => 'Subcommand: list (default), candidates, approve, reject'],
                    'args' => ['type' => 'optional', 'description' => 'Arguments for the subcommand']
                ],
                'actions' => [
                    'list' => [
                        'usage' => 'p2p',
                        'description' => 'List all P2P transactions awaiting approval'
                    ],
                    'candidates' => [
                        'usage' => 'p2p candidates <hash>',
                        'description' => 'Show route candidates for a transaction'
                    ],
                    'approve' => [
                        'usage' => 'p2p approve <hash> [index]',
                        'description' => 'Approve and send a P2P transaction. Index (1-based) selects candidate in best-fee mode.'
                    ],
                    'reject' => [
                        'usage' => 'p2p reject <hash>',
                        'description' => 'Reject and cancel a P2P transaction'
                    ]
                ],
                'examples' => [
                    'p2p' => 'List pending P2P transactions',
                    'p2p candidates abc123' => 'View route candidates for hash abc123',
                    'p2p approve abc123' => 'Approve single-route P2P (fast mode)',
                    'p2p approve abc123 2' => 'Approve using candidate #2 (best-fee mode)',
                    'p2p reject abc123' => 'Reject and cancel P2P transaction',
                    'p2p --json' => 'JSON output'
                ],
                'note' => 'Used when autoAcceptTransaction is disabled. P2P transactions wait in awaiting_approval status until manually approved or rejected via this command.'
            ],
            'overview' => [
                'description' => 'Display wallet dashboard with balances and recent transactions',
                'usage' => 'overview ([limit])',
                'arguments' => [
                    'limit' => ['type' => 'optional', 'description' => 'Number of recent transactions to show (default: 5)']
                ],
                'examples' => [
                    'overview' => 'Default dashboard (5 recent transactions)',
                    'overview 10' => 'Show 10 recent transactions',
                    'overview --json' => 'JSON output'
                ],
                'note' => 'Combines balance summary across all contacts with recent transaction activity. Useful as a quick status check.'
            ],
            'help' => [
                'description' => 'Display help information for all commands or a specific command',
                'usage' => 'help ([command])',
                'arguments' => [
                    'command' => ['type' => 'optional', 'description' => 'Specific command to get detailed help for']
                ],
                'examples' => [
                    'help' => 'List all available commands',
                    'help send' => 'Detailed help for the send command',
                    'help --json' => 'JSON format help'
                ]
            ],
            'viewsettings' => [
                'description' => 'Display current wallet settings',
                'usage' => 'viewsettings',
                'arguments' => [],
                'examples' => [
                    'viewsettings' => 'View all settings',
                    'viewsettings --json' => 'JSON output'
                ],
                'note' => 'Shows default currency, fee settings (min/default/max), credit limit, P2P routing level and expiration, max output lines, transport mode, hostname, auto-refresh, and auto-backup status.'
            ],
            'changesettings' => [
                'description' => 'Change wallet settings (interactive or direct)',
                'usage' => 'changesettings ([setting] [value])',
                'arguments' => [
                    'setting' => ['type' => 'optional', 'description' => 'Setting name to change (interactive mode if omitted)'],
                    'value' => ['type' => 'optional', 'description' => 'New value for the setting']
                ],
                'available_settings' => [
                    // Transaction Settings
                    'defaultCurrency' => 'Default currency code (e.g., USD)',
                    'minFee' => 'Minimum fee amount (e.g., 0.00000001 for 1 satoshi)',
                    'defaultFee' => 'Default fee percentage for transactions (e.g., 1.0)',
                    'maxFee' => 'Maximum fee percentage (e.g., 5.0)',
                    'defaultCreditLimit' => 'Default credit limit for new contacts (e.g., 100)',
                    // P2P & Network
                    'maxP2pLevel' => 'Maximum peer-to-peer routing hops (e.g., 3)',
                    'p2pExpiration' => 'Peer-to-peer request expiration time in seconds (e.g., 300)',
                    'directTxExpiration' => 'Direct transaction delivery expiry in seconds; 0 = no expiry (default). P2P transactions use p2pExpiration + ' . Constants::DIRECT_TX_DELIVERY_EXPIRATION_SECONDS . 's automatically.',
                    'defaultTransportMode' => 'Default transport type: http, https, or tor',
                    'httpTransportTimeoutSeconds' => 'HTTP transport timeout in seconds (5-120)',
                    'torTransportTimeoutSeconds' => 'Tor transport timeout in seconds (10-300)',
                    'torCircuitMaxFailures' => 'Consecutive Tor failures before cooldown (1-10)',
                    'torCircuitCooldownSeconds' => 'Tor circuit cooldown duration in seconds (60-3600)',
                    'torFailureTransportFallback' => 'Fall back to HTTP/HTTPS when Tor fails (true/false)',
                    'torFallbackRequireEncrypted' => 'Restrict Tor fallback to HTTPS only, never plain HTTP (true/false)',
                    'hostname' => 'Node hostname (e.g., http://alice). Automatically derives HTTPS version and regenerates SSL cert',
                    'trustedProxies' => 'Trusted proxy IPs (comma-separated)',
                    'autoAcceptTransaction' => 'Auto-accept P2P transactions when route found (true/false)',
                    // Feature Toggles
                    'name' => 'Display name for this node (shown in local UI)',
                    'contactStatusEnabled' => 'Enable contact status pinging (true/false)',
                    'contactStatusSyncOnPing' => 'Enable contact status sync on ping (true/false)',
                    'autoChainDropPropose' => 'Enable auto chain drop propose (true/false)',
                    'autoChainDropAccept' => 'Enable auto chain drop accept (true/false)',
                    'autoChainDropAcceptGuard' => 'Enable auto chain drop accept balance guard (true/false)',
                    'autoAcceptRestoredContact' => 'Auto-accept restored contacts on wallet restore (true/false)',
                    'apiEnabled' => 'Enable API access (true/false)',
                    'apiCorsAllowedOrigins' => 'API CORS allowed origins',
                    'rateLimitEnabled' => 'Enable rate limiting (true/false)',
                    // Backup & Logging
                    'autoBackupEnabled' => 'Enable automatic daily database backups (true/false)',
                    'backupRetentionCount' => 'Number of backups to retain (minimum 1)',
                    'backupCronHour' => 'Backup schedule hour (0-23)',
                    'backupCronMinute' => 'Backup schedule minute (0-59)',
                    'logLevel' => 'Log level: debug, info, warning, error',
                    'logMaxEntries' => 'Maximum log entries to retain (minimum 10)',
                    // Data Retention
                    'cleanupDeliveryRetentionDays' => 'Delivery record retention in days (minimum 1)',
                    'cleanupDlqRetentionDays' => 'DLQ record retention in days (minimum 1)',
                    'cleanupHeldTxRetentionDays' => 'Held TX retention in days (minimum 1)',
                    'cleanupRp2pRetentionDays' => 'RP2P retention in days (minimum 1)',
                    'cleanupMetricsRetentionDays' => 'Metrics retention in days (minimum 1)',
                    // Rate Limiting
                    'p2pRateLimitPerMinute' => 'P2P rate limit per minute (minimum 1)',
                    'rateLimitMaxAttempts' => 'Rate limit max attempts (minimum 1)',
                    'rateLimitWindowSeconds' => 'Rate limit window in seconds (minimum 1)',
                    'rateLimitBlockSeconds' => 'Rate limit block duration in seconds (minimum 1)',
                    // Display
                    'maxOutput' => 'Maximum lines of output to display (0 = unlimited)',
                    'displayDateFormat' => 'Date format — must be one of: ' . implode(', ', Constants::VALID_DATE_FORMATS),
                    'displayDecimals' => 'Display decimal places for all currencies (0-8, default 4). Truncates (floors) — does not round, so displayed amounts never exceed actual value. Does not affect internal storage.',
                    // Currency Management
                    'allowedCurrencies' => 'Allowed currencies (comma-separated, e.g., USD,EUR)',
                    'autoRejectUnknownCurrency' => 'Auto-reject incoming contact requests with currencies not in your allowed list (true/false). When disabled, unknown currency requests arrive as pending; accepting them auto-adds the currency.',
                ],
                'examples' => [
                    'changesettings' => 'Interactive mode (prompts for setting)',
                    'changesettings defaultCurrency USD' => 'Change default currency',
                    'changesettings maxP2pLevel 5' => 'Change max P2P routing hops',
                    'changesettings autoBackupEnabled true' => 'Enable auto-backup',
                    'changesettings defaultFee 1.5 --json' => 'JSON output'
                ]
            ],
            'generate' => [
                'description' => 'Wallet generation and restoration (handled during container startup)',
                'usage' => 'generate',
                'arguments' => [],
                'examples' => [],
                'note' => 'Wallet creation is handled automatically by startup.sh during container initialization via Docker environment variables (QUICKSTART, EIOU_HOST, EIOU_NAME, RESTORE, RESTORE_FILE). This command cannot be used after the wallet has been created.'
            ],
            'sync' => [
                'description' => 'Synchronize data with contacts (contacts, transactions, balances)',
                'usage' => 'sync ([type])',
                'arguments' => [
                    'type' => ['type' => 'optional', 'description' => 'Sync type: contacts, transactions, or balances. Omit to sync all.']
                ],
                'examples' => [
                    'sync' => 'Sync all (contacts, transactions, and balances)',
                    'sync contacts' => 'Sync only contacts',
                    'sync transactions' => 'Sync only transactions (includes backup recovery)',
                    'sync balances' => 'Recalculate balances from transaction history'
                ],
                'note' => 'Transaction sync verifies chain integrity locally for each contact. If gaps are found, backup recovery is attempted on both sides. If gaps remain after recovery, the output reports the gap count and recommends using chaindrop to resolve.'
            ],
            'out' => [
                'description' => 'Process outgoing message queue (pending transactions)',
                'usage' => 'out',
                'arguments' => [],
                'examples' => [
                    'out' => 'Process all pending outgoing messages'
                ],
                'note' => 'Requires EIOU_TEST_MODE=true. Manually triggers the outgoing message processor. Used for testing and debugging.'
            ],
            'in' => [
                'description' => 'Process incoming/held transactions',
                'usage' => 'in',
                'arguments' => [],
                'examples' => [
                    'in' => 'Process all held incoming transactions'
                ],
                'note' => 'Requires EIOU_TEST_MODE=true. Processes held transactions that may have completed sync. Used for testing and debugging.'
            ],
            'apikey' => [
                'description' => 'Manage API keys for external API access',
                'usage' => 'apikey [action] ([args...])',
                'arguments' => [
                    'action' => ['type' => 'required', 'description' => 'Action: create, list, delete, disable, enable, help'],
                    'args' => ['type' => 'optional', 'description' => 'Arguments for the action']
                ],
                'actions' => [
                    'create' => [
                        'usage' => 'apikey create <name> [permissions]',
                        'description' => 'Create a new API key',
                        'arguments' => [
                            'name' => ['type' => 'required', 'description' => 'Name for the API key'],
                            'permissions' => ['type' => 'optional', 'description' => 'Comma-separated permissions (default: wallet:read,contacts:read)']
                        ]
                    ],
                    'list' => [
                        'usage' => 'apikey list',
                        'description' => 'List all API keys'
                    ],
                    'delete' => [
                        'usage' => 'apikey delete <key_id>',
                        'description' => 'Delete an API key permanently',
                        'arguments' => [
                            'key_id' => ['type' => 'required', 'description' => 'ID of the key to delete']
                        ]
                    ],
                    'disable' => [
                        'usage' => 'apikey disable <key_id>',
                        'description' => 'Disable an API key (can be re-enabled)',
                        'arguments' => [
                            'key_id' => ['type' => 'required', 'description' => 'ID of the key to disable']
                        ]
                    ],
                    'enable' => [
                        'usage' => 'apikey enable <key_id>',
                        'description' => 'Enable a disabled API key',
                        'arguments' => [
                            'key_id' => ['type' => 'required', 'description' => 'ID of the key to enable']
                        ]
                    ],
                    'help' => [
                        'usage' => 'apikey help',
                        'description' => 'Show detailed API key help'
                    ]
                ],
                'examples' => [
                    'apikey help' => 'Show detailed API key help',
                    'apikey create "My App"' => 'Create new API key with default permissions',
                    'apikey create "My App" wallet:read,contacts:read' => 'Create key with specific permissions',
                    'apikey list' => 'List all API keys',
                    'apikey delete <key_id>' => 'Delete an API key permanently',
                    'apikey disable <key_id>' => 'Disable an API key',
                    'apikey enable <key_id>' => 'Enable a disabled API key'
                ],
                'permissions' => [
                    'wallet:read' => 'Read wallet balance, info, and transactions',
                    'wallet:send' => 'Send transactions, manage chain drops',
                    'wallet:*' => 'Both wallet:read and wallet:send',
                    'contacts:read' => 'List, view, search, and ping contacts',
                    'contacts:write' => 'Add, update, delete, block/unblock contacts',
                    'contacts:*' => 'Both contacts:read and contacts:write',
                    'system:read' => 'View system status, metrics, and settings',
                    'backup:read' => 'Read backup status/list, verify backups',
                    'backup:write' => 'Create, restore, delete, enable/disable backups',
                    'backup:*' => 'Both backup:read and backup:write',
                    'payback:read' => 'List/read your own payback methods (sensitive fields redacted)',
                    'payback:write' => 'Create/edit/delete payback methods, AND reveal plaintext via /payback-methods/:id/reveal (write-class because it returns secrets)',
                    'payback:*' => 'Both payback:read and payback:write',
                    'admin' => 'Full administrative access (settings, sync, shutdown/start/restart, keys, plugins)',
                    'all' => 'All permissions (same as admin)'
                ],
                'api_usage' => [
                    'base_url' => 'http://your-node/api/v1/...',
                    'required_headers' => [
                        'X-API-Key' => '<key_id>',
                        'X-API-Timestamp' => '<unix_timestamp>',
                        'X-API-Signature' => '<hmac>'
                    ],
                    'signature_format' => 'HMAC-SHA256(secret, METHOD + "\\n" + PATH + "\\n" + TIMESTAMP + "\\n" + BODY)',
                    'example_endpoints' => [
                        'GET /api/v1/wallet/balance' => 'Get wallet balances',
                        'GET /api/v1/wallet/info' => 'Wallet public key, addresses, fee earnings',
                        'GET /api/v1/wallet/overview' => 'Wallet overview (balance + recent transactions)',
                        'POST /api/v1/wallet/send' => 'Send transaction',
                        'GET /api/v1/wallet/transactions' => 'Transaction history',
                        'GET /api/v1/contacts' => 'List contacts',
                        'POST /api/v1/contacts' => 'Add contact',
                        'GET /api/v1/contacts/pending' => 'Pending contact requests',
                        'GET /api/v1/contacts/search?q=' => 'Search contacts by name',
                        'GET /api/v1/contacts/:address' => 'Get contact details',
                        'PUT /api/v1/contacts/:address' => 'Update contact',
                        'DELETE /api/v1/contacts/:address' => 'Delete contact',
                        'POST /api/v1/contacts/block/:address' => 'Block contact',
                        'POST /api/v1/contacts/unblock/:address' => 'Unblock contact',
                        'POST /api/v1/contacts/ping/:address' => 'Ping contact',
                        'GET /api/v1/system/status' => 'System status',
                        'GET /api/v1/system/settings' => 'System settings',
                        'PUT /api/v1/system/settings' => 'Update settings (admin)',
                        'POST /api/v1/system/update-check' => 'Trigger update check',
                        'POST /api/v1/system/sync' => 'Trigger sync (admin)',
                        'POST /api/v1/system/shutdown' => 'Shutdown processors (admin)',
                        'POST /api/v1/system/start' => 'Start processors (admin)',
                        'GET /api/v1/chaindrop' => 'List chain drop proposals',
                        'POST /api/v1/chaindrop/propose' => 'Propose chain drop',
                        'POST /api/v1/chaindrop/accept' => 'Accept chain drop',
                        'POST /api/v1/chaindrop/reject' => 'Reject chain drop',
                        'GET /api/v1/backup/status' => 'Backup status',
                        'GET /api/v1/backup/list' => 'List backups',
                        'POST /api/v1/backup/create' => 'Create backup',
                        'POST /api/v1/backup/restore' => 'Restore from backup',
                        'POST /api/v1/backup/verify' => 'Verify backup integrity',
                        'DELETE /api/v1/backup/:filename' => 'Delete backup',
                        'POST /api/v1/backup/enable' => 'Enable auto backups',
                        'POST /api/v1/backup/disable' => 'Disable auto backups',
                        'POST /api/v1/backup/cleanup' => 'Cleanup old backups',
                        'GET /api/v1/keys' => 'List API keys (admin)',
                        'POST /api/v1/keys' => 'Create API key (admin)',
                        'DELETE /api/v1/keys/:key_id' => 'Delete API key (admin)',
                        'POST /api/v1/keys/enable/:key_id' => 'Enable API key (admin)',
                        'POST /api/v1/keys/disable/:key_id' => 'Disable API key (admin)'
                    ]
                ]
            ],
            'updatecheck' => [
                'description' => 'Check Docker Hub and GitHub for newer image versions',
                'usage' => 'updatecheck',
                'arguments' => [],
                'examples' => [
                    'updatecheck' => 'Check for updates now',
                    'updatecheck --json' => 'JSON output with full version details'
                ],
                'note' => 'Bypasses the 24-hour cache and checks Docker Hub (primary) and GitHub Releases (fallback) for newer versions. Reports the latest available version and whether an update is available. Respects the updateCheckEnabled setting.'
            ],
            'shutdown' => [
                'description' => 'Gracefully shutdown all processors (P2P, Transaction, Cleanup, ContactStatus)',
                'usage' => 'shutdown',
                'arguments' => [],
                'examples' => [
                    'shutdown' => 'Stop all background processors',
                    'shutdown --json' => 'JSON output'
                ],
                'note' => 'Sends SIGTERM to all running processors, removes PID/lockfiles, and creates a shutdown flag to prevent watchdog restarts. The node remains accessible via CLI and API but will not process incoming or outgoing messages. Use "eiou start" to resume.'
            ],
            'start' => [
                'description' => 'Resume processor operations after a previous shutdown',
                'usage' => 'start',
                'arguments' => [],
                'examples' => [
                    'start' => 'Resume all background processors',
                    'start --json' => 'JSON output'
                ],
                'note' => 'Removes the shutdown flag. The watchdog detects this and restarts all processors within 30 seconds. If no shutdown flag exists (processors already running), reports that and exits.'
            ],
            'restart' => [
                'description' => 'Restart processors AND PHP-FPM workers in-place (apply plugin/config changes without a container reboot)',
                'usage' => 'restart',
                'arguments' => [],
                'examples' => [
                    'restart' => 'Restart everything in-place',
                    'restart --json' => 'JSON output'
                ],
                'note' => 'SIGTERMs the processors (the watchdog respawns them within ~30s) and sends SIGUSR2 to the PHP-FPM master to gracefully recycle all worker processes. In-flight HTTP requests finish before workers exit. Required when toggling plugins, since event subscriptions bind during boot. Must run as root inside the container — the CLI does, calling from a PHP-FPM worker (GUI) does not.'
            ],
            'plugin' => [
                'description' => 'Manage plugins: list installed ones and toggle their enabled flag',
                'usage' => 'plugin [list|enable|disable] [name]',
                'arguments' => [
                    'subcommand' => ['type' => 'optional', 'description' => 'Subcommand: list (default), enable, disable'],
                    'name' => ['type' => 'conditional', 'description' => 'Plugin name — required for enable/disable']
                ],
                'actions' => [
                    'list' => [
                        'usage' => 'plugin [list]',
                        'description' => 'List every installed plugin with version, enabled flag, status, license',
                    ],
                    'enable' => [
                        'usage' => 'plugin enable <name>',
                        'description' => 'Persist the enabled flag as true for the named plugin',
                    ],
                    'disable' => [
                        'usage' => 'plugin disable <name>',
                        'description' => 'Persist the enabled flag as false for the named plugin',
                    ],
                ],
                'examples' => [
                    'plugin' => 'List all plugins (table)',
                    'plugin list --json' => 'List all plugins (JSON with full metadata)',
                    'plugin enable hello-eiou' => 'Enable hello-eiou',
                    'plugin disable hello-eiou' => 'Disable hello-eiou',
                ],
                'note' => 'Enable/disable persists to /etc/eiou/config/plugins.json immediately but does NOT take effect until the next restart — event subscriptions bind during boot. Run `eiou restart` (or hit POST /api/v1/system/restart, or use the GUI restart button) once you are done toggling.'
            ],
            'chaindrop' => [
                'description' => 'Manage chain drop agreements for resolving transaction chain gaps',
                'usage' => 'chaindrop [action] ([args...])',
                'arguments' => [
                    'action' => ['type' => 'required', 'description' => 'Action: propose, accept, reject, list, help'],
                    'args' => ['type' => 'optional', 'description' => 'Arguments for the action']
                ],
                'actions' => [
                    'propose' => [
                        'usage' => 'chaindrop propose <contact_address>',
                        'description' => 'Propose dropping a missing transaction from the chain (auto-detects the gap)',
                        'arguments' => [
                            'contact_address' => ['type' => 'required', 'description' => 'Address of the contact with the broken chain']
                        ]
                    ],
                    'accept' => [
                        'usage' => 'chaindrop accept <proposal_id>',
                        'description' => 'Accept an incoming chain drop proposal',
                        'arguments' => [
                            'proposal_id' => ['type' => 'required', 'description' => 'ID of the proposal to accept']
                        ]
                    ],
                    'reject' => [
                        'usage' => 'chaindrop reject <proposal_id>',
                        'description' => 'Reject an incoming chain drop proposal (transactions remain blocked)',
                        'arguments' => [
                            'proposal_id' => ['type' => 'required', 'description' => 'ID of the proposal to reject']
                        ]
                    ],
                    'list' => [
                        'usage' => 'chaindrop list [contact_address]',
                        'description' => 'List pending chain drop proposals',
                        'arguments' => [
                            'contact_address' => ['type' => 'optional', 'description' => 'Filter by contact address (omit to list all incoming)']
                        ]
                    ],
                    'help' => [
                        'usage' => 'chaindrop help',
                        'description' => 'Show chain drop help'
                    ]
                ],
                'examples' => [
                    'chaindrop propose https://bob' => 'Propose dropping a missing transaction with Bob',
                    'chaindrop accept cdp-abc123...' => 'Accept an incoming proposal',
                    'chaindrop reject cdp-abc123...' => 'Reject a proposal (chain stays broken)',
                    'chaindrop list' => 'List all incoming pending proposals',
                    'chaindrop list https://bob' => 'List proposals for a specific contact'
                ],
                'note' => 'While a chain gap exists, transactions with that contact are blocked. Rejecting a proposal leaves the gap unresolved.'
            ],
            'backup' => [
                'description' => 'Manage encrypted database backups',
                'usage' => 'backup <action> [arguments]',
                'arguments' => [
                    'action' => ['type' => 'required', 'description' => 'Action: create, restore, list, delete, verify, enable, disable, status, cleanup, help'],
                    'args' => ['type' => 'optional', 'description' => 'Arguments for the action']
                ],
                'actions' => [
                    'create' => [
                        'description' => 'Create a new encrypted backup',
                        'usage' => 'backup create [name]',
                        'arguments' => [
                            'name' => ['type' => 'optional', 'description' => 'Custom name for the backup file']
                        ]
                    ],
                    'restore' => [
                        'description' => 'Restore database from an encrypted backup',
                        'usage' => 'backup restore <filename> --confirm',
                        'arguments' => [
                            'filename' => ['type' => 'required', 'description' => 'Backup filename to restore from'],
                            '--confirm' => ['type' => 'required', 'description' => 'Required flag to confirm the destructive operation']
                        ]
                    ],
                    'list' => [
                        'description' => 'List all available backups',
                        'usage' => 'backup list'
                    ],
                    'delete' => [
                        'description' => 'Delete a backup file',
                        'usage' => 'backup delete <filename>',
                        'arguments' => [
                            'filename' => ['type' => 'required', 'description' => 'Backup filename to delete']
                        ]
                    ],
                    'verify' => [
                        'description' => 'Verify backup integrity',
                        'usage' => 'backup verify <filename>',
                        'arguments' => [
                            'filename' => ['type' => 'required', 'description' => 'Backup filename to verify']
                        ]
                    ],
                    'enable' => [
                        'description' => 'Enable automatic daily backups',
                        'usage' => 'backup enable'
                    ],
                    'disable' => [
                        'description' => 'Disable automatic daily backups',
                        'usage' => 'backup disable'
                    ],
                    'status' => [
                        'description' => 'Show backup status and settings',
                        'usage' => 'backup status'
                    ],
                    'cleanup' => [
                        'description' => 'Remove old backups (keeps 3 most recent)',
                        'usage' => 'backup cleanup'
                    ],
                    'help' => [
                        'description' => 'Show backup help',
                        'usage' => 'backup help'
                    ]
                ],
                'examples' => [
                    'backup create' => 'Create backup with auto-generated name',
                    'backup create pre_upgrade' => 'Create backup with custom name',
                    'backup list' => 'List all backups',
                    'backup restore backup_20260124.eiou.enc --confirm' => 'Restore from backup',
                    'backup verify backup_20260124.eiou.enc' => 'Verify backup integrity',
                    'backup status' => 'Show backup status',
                    'backup enable' => 'Enable automatic daily backups'
                ],
                'note' => 'Backups are AES-256-CBC encrypted using the node\'s master key. Automatic backups run daily at midnight when enabled. Cleanup keeps the 3 most recent backups.'
            ],
            'request' => [
                'description' => 'Manage payment requests (ask a contact to pay you)',
                'usage' => 'request [subcommand] [args]',
                'arguments' => [
                    'subcommand' => ['type' => 'optional', 'description' => 'Action: list, create, approve, decline, cancel (default: list)'],
                ],
                'actions' => [
                    'list' => [
                        'description' => 'List all incoming and outgoing payment requests',
                        'usage' => 'request list',
                    ],
                    'create' => [
                        'description' => 'Create a payment request (ask a contact to pay you)',
                        'usage' => 'request create <contact> <amount> <currency> [description]',
                        'arguments' => [
                            'contact' => ['type' => 'required', 'description' => 'Contact name or address'],
                            'amount' => ['type' => 'required', 'description' => 'Amount to request'],
                            'currency' => ['type' => 'required', 'description' => 'Currency code (e.g. USD)'],
                            'description' => ['type' => 'optional', 'description' => 'Memo or reason for the request']
                        ]
                    ],
                    'approve' => [
                        'description' => 'Approve an incoming request (sends the eIOU)',
                        'usage' => 'request approve <request_id> [note]',
                        'arguments' => [
                            'request_id' => ['type' => 'required', 'description' => 'The request ID to approve'],
                            'note' => ['type' => 'optional', 'description' => 'Free-form payer note appended to the on-chain description with " | " (e.g. "paid via coinbase txid abc"). Length is capped against whatever space the requester\'s description leaves under the 255-char ceiling; over-long notes are rejected.']
                        ]
                    ],
                    'decline' => [
                        'description' => 'Decline an incoming payment request',
                        'usage' => 'request decline <request_id>',
                        'arguments' => [
                            'request_id' => ['type' => 'required', 'description' => 'The request ID to decline']
                        ]
                    ],
                    'cancel' => [
                        'description' => 'Cancel an outgoing payment request you created',
                        'usage' => 'request cancel <request_id>',
                        'arguments' => [
                            'request_id' => ['type' => 'required', 'description' => 'The request ID to cancel']
                        ]
                    ],
                ],
                'examples' => [
                    'request list' => 'List all payment requests',
                    'request create "Alice" 25.00 USD "Dinner"' => 'Request 25 USD from Alice',
                    'request approve req_abc123' => 'Approve and pay the request',
                    'request approve req_abc123 "paid via coinbase txid abc"' => 'Approve and append a payer note to the on-chain description',
                    'request decline req_abc123' => 'Decline the request',
                    'request cancel req_abc123' => 'Cancel your outgoing request',
                    'request --json' => 'JSON output'
                ],
                'note' => 'Payment requests allow you to ask a contact to send you an eIOU. The recipient can approve (which sends the eIOU automatically) or decline. You can cancel your own outgoing requests.'
            ],
            'report' => [
                'description' => 'Generate reports for troubleshooting and analysis',
                'usage' => 'report <type> [description] [--full]',
                'arguments' => [
                    'type' => ['type' => 'required', 'description' => 'Report type: debug'],
                    'description' => ['type' => 'optional', 'description' => 'Issue description to include in the report'],
                    '--full' => ['type' => 'optional', 'description' => 'Include full log history (default: last 50 lines)']
                ],
                'actions' => [
                    'debug' => [
                        'description' => 'Generate a debug report with system info, debug entries, and logs',
                        'usage' => 'report debug [description] [--full]'
                    ]
                ],
                'examples' => [
                    'report debug' => 'Generate a limited debug report',
                    'report debug "login page crash"' => 'Include an issue description',
                    'report debug --full' => 'Include full log history',
                    'report debug "issue description" --full' => 'Full report with description'
                ],
                'note' => 'Reports are saved as JSON files in /tmp/. The debug report includes system info (PHP, MariaDB, OS), debug table entries, application logs, PHP errors, and nginx errors.'
            ],
            'dlq' => [
                'description' => 'Manage the dead letter queue — messages that failed delivery after all automatic retries',
                'usage' => 'dlq [list|retry|abandon] [id] [--status=pending|retrying|resolved|abandoned|all]',
                'arguments' => [
                    'subcommand' => ['type' => 'optional', 'description' => 'list (default), retry, or abandon'],
                    'id' => ['type' => 'optional', 'description' => 'DLQ item ID (required for retry and abandon)'],
                    '--status' => ['type' => 'optional', 'description' => 'Filter list by status (default: all active items)'],
                ],
                'examples' => [
                    'dlq' => 'List all pending and retrying DLQ items',
                    'dlq list --status=all' => 'List all items regardless of status',
                    'dlq retry 42' => 'Retry DLQ item #42 immediately',
                    'dlq abandon 42' => 'Abandon DLQ item #42 (no further retries)',
                    'dlq --json' => 'JSON output with statistics',
                ],
                'note' => 'The DLQ captures messages that could not be delivered after ' . Constants::DELIVERY_MAX_RETRIES . ' automatic attempts. All items originated from this node. Retry re-sends the original signed payload directly to the recipient.'
            ],
            'payback' => [
                'description' => 'Manage your payback methods (settlement rails — bank wire, custom free-text, plugin-provided types).',
                'usage' => 'payback <action> [args...]',
                'arguments' => [
                    'action' => ['type' => 'required', 'description' => 'list | add | show | edit | remove | share-policy | help'],
                ],
                'examples' => [
                    'payback' => 'Run `eiou payback help` for the full subcommand tree.',
                    'payback list' => 'List all enabled payback methods',
                    'payback list --currency USD --all' => 'Filter by currency, include disabled',
                    'payback add bank_wire "My Revolut" EUR' => 'Add a SEPA bank-wire method (prompts for type-specific fields)',
                    'payback show pbm_abc123' => 'Reveal a method\'s decrypted plaintext fields',
                ],
                'note' => 'Run `eiou payback help` for full details. Sensitive fields are encrypted at rest per row; the CLI is treated as already-authenticated by virtue of shell access. Plugins can register additional rail types (btc, paypal, lightning, …) — see docs/PLUGINS.md.'
            ],
            'verify-chain' => [
                'description' => 'Walk every bilateral chain end-to-end and verify each pair\'s archive hash against the stored checkpoint.',
                'usage' => 'verify-chain',
                'arguments' => [],
                'examples' => [
                    'verify-chain' => 'Audit every pair (exits 1 if any pair has a chain gap or hash mismatch)',
                    'verify-chain --json' => 'JSON output',
                ],
                'note' => 'O(all-history) per pair — bypasses the hot-path checkpoint optimization, so run deliberately. Useful after a restore or when investigating pairs_skipped_gap from the archival cron.'
            ],
            'global_options' => [
                'description' => 'Global options available for all commands',
                'options' => [
                    '--json, -j' => 'Output results in JSON format for scripting/automation',
                    '--no-metadata' => 'Exclude metadata (timestamp, node_id) from JSON output'
                ]
            ]
        ];

        $specificCommand = isset($argv[2]) ? strtolower($argv[2]) : null;

        if ($output->isJsonMode()) {
            if ($specificCommand !== null) {
                if (isset($commands[$specificCommand])) {
                    $output->help([$specificCommand => $commands[$specificCommand]], $specificCommand);
                } else {
                    $output->error("Command '$specificCommand' does not exist", ErrorCodes::COMMAND_NOT_FOUND, 404);
                }
            } else {
                $output->help($commands);
            }
        } else {
            if ($specificCommand !== null) {
                echo "Command:\n";
                if (isset($commands[$specificCommand])) {
                    echo "\t" . $commands[$specificCommand]['usage'] . " - " . $commands[$specificCommand]['description'] . "\n";

                    // Show detailed help for the specific command
                    $this->showDetailedHelp($specificCommand, $commands[$specificCommand]);
                } else {
                    echo "\tcommand does not exist.\n";
                }
            } else {
                echo "Available commands:\n";
                foreach ($commands as $name => $cmd) {
                    if (isset($cmd['usage'])) {
                        echo "\t" . $cmd['usage'] . " - " . $cmd['description'] . "\n";
                    }
                }
            }
        }
    }

    /**
     * Display detailed help for a specific command in TTY mode.
     *
     * For commands with dedicated detailed help methods (apikey, chaindrop),
     * delegates to those. For all other commands, renders a structured help
     * output from the command definition array (arguments, examples, notes).
     *
     * @param string $command The command name
     * @param array $definition The command definition from the $commands array
     */
    private function showDetailedHelp(string $command, array $definition): void {
        // Commands with dedicated detailed help methods
        if ($command === 'apikey') {
            $this->showApiKeyDetailedHelp();
            return;
        }
        if ($command === 'chaindrop') {
            $this->showChainDropDetailedHelp();
            return;
        }

        // Generic detailed help from command definition
        echo "\n";

        // Arguments
        if (!empty($definition['arguments'])) {
            echo "Arguments:\n";
            foreach ($definition['arguments'] as $argName => $argInfo) {
                $type = $argInfo['type'] ?? 'required';
                $desc = $argInfo['description'] ?? '';
                echo "  {$argName} ({$type})\n";
                echo "    {$desc}\n";
            }
            echo "\n";
        }

        // Available settings (changesettings)
        if (!empty($definition['available_settings'])) {
            echo "Available settings:\n";
            foreach ($definition['available_settings'] as $setting => $desc) {
                echo "  {$setting}\n";
                echo "    {$desc}\n";
            }
            echo "\n";
        }

        // Examples
        if (!empty($definition['examples'])) {
            echo "Examples:\n";
            foreach ($definition['examples'] as $example => $desc) {
                echo "  eiou {$example}\n";
                echo "    {$desc}\n";
            }
            echo "\n";
        }

        // Actions (sub-commands)
        if (!empty($definition['actions'])) {
            echo "Actions:\n";
            foreach ($definition['actions'] as $actionName => $actionInfo) {
                $actionUsage = $actionInfo['usage'] ?? $actionName;
                $actionDesc = $actionInfo['description'] ?? '';
                echo "  {$actionUsage}\n";
                echo "    {$actionDesc}\n";
            }
            echo "\n";
        }

        // Note
        if (!empty($definition['note'])) {
            echo "Note:\n";
            echo "  {$definition['note']}\n";
            echo "\n";
        }
    }

    /**
     * Display detailed help for API key management commands
     */
    private function showApiKeyDetailedHelp(): void {
        $help = <<<HELP

API Key Management Commands
===========================

Create a new API key:
  eiou apikey create <name> [permissions]

  Example:
    eiou apikey create "My Application" wallet:read,contacts:read

  Available permissions:
    - wallet:read     Read wallet balance and transactions
    - wallet:send     Send transactions
    - wallet:*        Both wallet:read and wallet:send
    - contacts:read   List and view contacts
    - contacts:write  Add, update, delete contacts
    - contacts:*      Both contacts:read and contacts:write
    - system:read     View system status, metrics, and settings
    - backup:read     Read backup status/list, verify backups
    - backup:write    Create, restore, delete, enable/disable backups
    - backup:*        Both backup:read and backup:write
    - payback:read    List/read your own payback methods (redacted)
    - payback:write   Create/edit/delete + reveal plaintext (write-class)
    - payback:*       Both payback:read and payback:write
    - admin           Full administrative access (settings, sync, shutdown/start/restart, keys, plugins)
    - all             All permissions (same as admin)

List all API keys:
  eiou apikey list

Delete an API key (permanent):
  eiou apikey delete <key_id>

Disable an API key (can be re-enabled):
  eiou apikey disable <key_id>

Enable a disabled API key:
  eiou apikey enable <key_id>

API Usage
=========

Once you have an API key, make requests to:
  http://your-node/api/v1/...

Required headers for each request:
  X-API-Key: <key_id>
  X-API-Timestamp: <unix_timestamp>
  X-API-Signature: <hmac>

The HMAC signature is calculated as:
  HMAC-SHA256(secret, METHOD + "\\n" + PATH + "\\n" + TIMESTAMP + "\\n" + BODY)

IMPORTANT: Never send the secret in requests - only the computed HMAC signature.
The server retrieves and decrypts your secret to verify the signature.

Example endpoints:
  GET  /api/v1/wallet/balance       - Get wallet balances
  GET  /api/v1/wallet/overview      - Wallet overview (balance + recent transactions)
  POST /api/v1/wallet/send          - Send transaction
  GET  /api/v1/wallet/transactions  - Transaction history
  GET  /api/v1/contacts             - List contacts
  GET  /api/v1/contacts/pending     - Pending contact requests
  GET  /api/v1/contacts/search?q=   - Search contacts by name
  POST /api/v1/contacts             - Add contact
  POST /api/v1/contacts/ping/:addr  - Ping contact
  GET  /api/v1/system/status        - System status
  GET  /api/v1/system/settings      - System settings

HELP;

        echo $help;
    }

    /**
     * Display detailed help for tx drop agreement commands
     */
    private function showChainDropDetailedHelp(): void {
        $help = <<<HELP

Chain Drop Agreement Commands
=============================

When both contacts are missing the same transaction in their shared chain,
the chain cannot be repaired via sync. Chain drop resolves this by mutually
agreeing to remove the missing transaction and relink the chain.

IMPORTANT: While a chain gap exists, transactions with that contact are
blocked. The send command verifies chain integrity before every transaction
and will halt if a gap is detected.

Propose dropping a missing transaction:
  eiou chaindrop propose <contact_address>

  Auto-detects the chain gap and sends a proposal to the contact.
  Example:
    eiou chaindrop propose https://bob

Accept an incoming proposal:
  eiou chaindrop accept <proposal_id>

  Executes the chain drop, re-signs affected transactions, and
  exchanges re-signed copies with the proposer.
  Example:
    eiou chaindrop accept cdp-2c3c26ba61ab4073...

Reject an incoming proposal:
  eiou chaindrop reject <proposal_id>

  WARNING: Rejecting leaves the chain gap unresolved. Transactions
  with this contact remain blocked until a new proposal is accepted.
  Example:
    eiou chaindrop reject cdp-2c3c26ba61ab4073...

List pending proposals:
  eiou chaindrop list [contact_address]

  Without an address, lists all incoming pending proposals.
  With an address, lists proposals for that specific contact.
  Examples:
    eiou chaindrop list
    eiou chaindrop list https://bob

Flow
====

1. Contact A runs: eiou chaindrop propose <contact_B_address>
2. Contact B receives the proposal (visible via: eiou chaindrop list)
3. Contact B runs: eiou chaindrop accept <proposal_id>
4. Both chains are repaired and transactions can resume

For multiple gaps, repeat the propose/accept cycle for each gap.

HELP;

        echo $help;
    }
}
