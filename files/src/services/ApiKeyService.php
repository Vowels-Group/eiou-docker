<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\ErrorCodes;
use Eiou\Contracts\ApiKeyServiceInterface;
use Eiou\Database\ApiKeyRepository;
use Eiou\Cli\CliOutputManager;
use Exception;

/**
 * API Key Service for CLI management
 *
 * Provides CLI commands for managing API keys:
 * - eiou apikey create <name> [permissions...]
 * - eiou apikey list
 * - eiou apikey delete <key_id>
 * - eiou apikey disable <key_id>
 * - eiou apikey enable <key_id>
 */

class ApiKeyService implements ApiKeyServiceInterface {
    private $repository;
    private $output;

    /**
     * Available permissions
     */
    private const PERMISSIONS = [
        'wallet:read',
        'wallet:send',
        'contacts:read',
        'contacts:write',
        'system:read',
        'backup:read',
        'backup:write',
        'admin',
        'all'
    ];

    /**
     * Constructor
     *
     * @param ApiKeyRepository $repository
     * @param CliOutputManager $output
     */
    public function __construct($repository, $output) {
        $this->repository = $repository;
        $this->output = $output;
    }

    /**
     * Handle CLI command
     *
     * @param array $argv Command line arguments
     */
    public function handleCommand(array $argv): void {
        $action = $argv[2] ?? 'help';

        switch (strtolower($action)) {
            case 'create':
                $this->createKey($argv);
                break;
            case 'list':
                $this->listKeys();
                break;
            case 'delete':
                $this->deleteKey($argv);
                break;
            case 'disable':
                $this->disableKey($argv);
                break;
            case 'enable':
                $this->enableKey($argv);
                break;
            case 'help':
            default:
                $this->showHelp();
                break;
        }
    }

    /**
     * Create a new API key
     *
     * Usage: eiou apikey create <name> [permission1,permission2,...]
     */
    private function createKey(array $argv): void {
        $name = $argv[3] ?? null;

        if (!$name) {
            $this->output->error('Missing required argument: name', ErrorCodes::MISSING_ARGUMENT, 400);
            $this->output->info("\nUsage: eiou apikey create <name> [permissions]\n");
            $this->output->info("Example: eiou apikey create \"My App\" wallet:read,contacts:read\n");
            return;
        }

        // Parse permissions (comma-separated)
        $permissionsArg = $argv[4] ?? 'wallet:read,contacts:read';
        $permissions = array_map('trim', explode(',', $permissionsArg));

        // Validate permissions
        foreach ($permissions as $perm) {
            if (!in_array($perm, self::PERMISSIONS) && !preg_match('/^[a-z]+:\*$/', $perm)) {
                $this->output->error("Invalid permission: $perm", ErrorCodes::INVALID_PERMISSION, 400);
                $this->output->info("\nValid permissions: " . implode(', ', self::PERMISSIONS) . "\n");
                return;
            }
        }

        try {
            $key = $this->repository->createKey($name, $permissions);

            $this->output->success("API key created successfully!", [
                'key_id' => $key['key_id'],
                'secret' => $key['secret'],
                'name' => $key['name'],
                'permissions' => $key['permissions']
            ], "Save this information securely!");

            // Display in CLI format
            echo "\n";
            echo "===== API KEY CREATED =====\n";
            echo "Key ID:      " . $key['key_id'] . "\n";
            echo "Secret:      " . $key['secret'] . "\n";
            echo "Name:        " . $key['name'] . "\n";
            echo "Permissions: " . implode(', ', $key['permissions']) . "\n";
            echo "===========================\n";
            echo "\n";
            echo "IMPORTANT: Save the secret now! It will not be shown again.\n";
            echo "\n";
            echo "To use this key, include these headers in your API requests:\n";
            echo "  X-API-Key: " . $key['key_id'] . "\n";
            echo "  X-API-Timestamp: <unix_timestamp>\n";
            echo "  X-API-Signature: <hmac_signature>\n";
            echo "\n";
            echo "The HMAC signature is computed as:\n";
            echo "  HMAC-SHA256(secret, METHOD + \"\\n\" + PATH + \"\\n\" + TIMESTAMP + \"\\n\" + BODY)\n";
            echo "\n";
            echo "NOTE: Never send the secret in requests - only the computed HMAC signature.\n";
            echo "\n";

        } catch (Exception $e) {
            $this->output->error('Failed to create API key: ' . $e->getMessage(), ErrorCodes::CREATE_FAILED, 500);
        }
    }

    /**
     * List all API keys
     */
    private function listKeys(): void {
        try {
            $keys = $this->repository->listKeys(true);

            if (empty($keys)) {
                $this->output->info("No API keys found.\n");
                $this->output->info("Create one with: eiou apikey create <name>\n");
                return;
            }

            $this->output->success("API Keys", ['keys' => $keys], count($keys) . " key(s) found");

            echo "\n";
            echo "===== API KEYS =====\n";
            echo str_pad("Key ID", 32) . " | " . str_pad("Name", 20) . " | Status    | Last Used\n";
            echo str_repeat("-", 85) . "\n";

            foreach ($keys as $key) {
                $status = $key['enabled'] ? 'Active' : 'Disabled';
                $lastUsed = $key['last_used_at'] ?? 'Never';

                echo str_pad($key['key_id'], 32) . " | ";
                echo str_pad(substr($key['name'], 0, 20), 20) . " | ";
                echo str_pad($status, 9) . " | ";
                echo $lastUsed . "\n";
            }

            echo str_repeat("=", 85) . "\n";
            echo "\n";

        } catch (Exception $e) {
            $this->output->error('Failed to list API keys: ' . $e->getMessage(), ErrorCodes::LIST_FAILED, 500);
        }
    }

    /**
     * Delete an API key
     *
     * Usage: eiou apikey delete <key_id>
     */
    private function deleteKey(array $argv): void {
        $keyId = $argv[3] ?? null;

        if (!$keyId) {
            $this->output->error('Missing required argument: key_id', ErrorCodes::MISSING_ARGUMENT, 400);
            $this->output->info("\nUsage: eiou apikey delete <key_id>\n");
            return;
        }

        try {
            $deleted = $this->repository->deleteKey($keyId);

            if ($deleted) {
                $this->output->success("API key deleted successfully", ['key_id' => $keyId]);
                echo "API key $keyId has been permanently deleted.\n";
            } else {
                $this->output->error("API key not found: $keyId", ErrorCodes::NOT_FOUND, 404);
            }
        } catch (Exception $e) {
            $this->output->error('Failed to delete API key: ' . $e->getMessage(), ErrorCodes::DELETE_FAILED, 500);
        }
    }

    /**
     * Disable an API key
     *
     * Usage: eiou apikey disable <key_id>
     */
    private function disableKey(array $argv): void {
        $keyId = $argv[3] ?? null;

        if (!$keyId) {
            $this->output->error('Missing required argument: key_id', ErrorCodes::MISSING_ARGUMENT, 400);
            $this->output->info("\nUsage: eiou apikey disable <key_id>\n");
            return;
        }

        try {
            $disabled = $this->repository->disableKey($keyId);

            if ($disabled) {
                $this->output->success("API key disabled", ['key_id' => $keyId]);
                echo "API key $keyId has been disabled. It can no longer be used.\n";
            } else {
                $this->output->error("API key not found: $keyId", ErrorCodes::NOT_FOUND, 404);
            }
        } catch (Exception $e) {
            $this->output->error('Failed to disable API key: ' . $e->getMessage(), ErrorCodes::DISABLE_FAILED, 500);
        }
    }

    /**
     * Enable an API key
     *
     * Usage: eiou apikey enable <key_id>
     */
    private function enableKey(array $argv): void {
        $keyId = $argv[3] ?? null;

        if (!$keyId) {
            $this->output->error('Missing required argument: key_id', ErrorCodes::MISSING_ARGUMENT, 400);
            $this->output->info("\nUsage: eiou apikey enable <key_id>\n");
            return;
        }

        try {
            $enabled = $this->repository->enableKey($keyId);

            if ($enabled) {
                $this->output->success("API key enabled", ['key_id' => $keyId]);
                echo "API key $keyId has been enabled and is now active.\n";
            } else {
                $this->output->error("API key not found: $keyId", ErrorCodes::NOT_FOUND, 404);
            }
        } catch (Exception $e) {
            $this->output->error('Failed to enable API key: ' . $e->getMessage(), ErrorCodes::ENABLE_FAILED, 500);
        }
    }

    /**
     * Show help for API key commands
     */
    private function showHelp(): void {
        $help = <<<HELP

API Key Management Commands
===========================

Create a new API key:
  eiou apikey create <name> [permissions]

  Example:
    eiou apikey create "My Application" wallet:read,contacts:read

  Available permissions:
    - wallet:read     Read wallet balance, info, and transactions
    - wallet:send     Send transactions, manage chain drops
    - contacts:read   List, view, search, and ping contacts
    - contacts:write  Add, update, delete, block/unblock contacts
    - system:read     View system status, metrics, and settings
    - backup:read     Read backup status/list, verify backups
    - backup:write    Create, restore, delete, enable/disable backups
    - admin           Full administrative access (settings, sync, shutdown, keys)
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

Available API endpoints:

  Wallet:
    GET    /api/v1/wallet/balance          - Get wallet balances
    GET    /api/v1/wallet/info             - Wallet public key, addresses, fee earnings
    GET    /api/v1/wallet/overview         - Wallet overview (balance + recent tx)
    POST   /api/v1/wallet/send             - Send transaction
    GET    /api/v1/wallet/transactions     - Transaction history

  Contacts:
    GET    /api/v1/contacts                - List contacts
    POST   /api/v1/contacts                - Add contact
    GET    /api/v1/contacts/pending        - Pending contact requests
    GET    /api/v1/contacts/search?q=      - Search contacts by name
    GET    /api/v1/contacts/:address       - Get contact details
    PUT    /api/v1/contacts/:address       - Update contact
    DELETE /api/v1/contacts/:address       - Delete contact
    POST   /api/v1/contacts/block/:addr    - Block contact
    POST   /api/v1/contacts/unblock/:addr  - Unblock contact
    POST   /api/v1/contacts/ping/:addr     - Ping contact

  System (admin):
    GET    /api/v1/system/status           - System status
    GET    /api/v1/system/metrics          - System metrics
    GET    /api/v1/system/settings         - System settings
    PUT    /api/v1/system/settings         - Update settings
    POST   /api/v1/system/sync             - Trigger sync
    POST   /api/v1/system/shutdown         - Shutdown processors
    POST   /api/v1/system/start            - Start processors

  Chain Drop:
    GET    /api/v1/chaindrop               - List proposals
    POST   /api/v1/chaindrop/propose       - Propose chain drop
    POST   /api/v1/chaindrop/accept        - Accept proposal
    POST   /api/v1/chaindrop/reject        - Reject proposal

  Backup:
    GET    /api/v1/backup/status           - Backup status
    GET    /api/v1/backup/list             - List backups
    POST   /api/v1/backup/create           - Create backup
    POST   /api/v1/backup/restore          - Restore from backup
    POST   /api/v1/backup/verify           - Verify backup integrity
    DELETE /api/v1/backup/:filename        - Delete backup
    POST   /api/v1/backup/enable           - Enable auto backups
    POST   /api/v1/backup/disable          - Disable auto backups
    POST   /api/v1/backup/cleanup          - Cleanup old backups

  API Keys (admin):
    GET    /api/v1/keys                    - List API keys
    POST   /api/v1/keys                    - Create API key
    DELETE /api/v1/keys/:key_id            - Delete API key
    POST   /api/v1/keys/enable/:key_id     - Enable API key
    POST   /api/v1/keys/disable/:key_id    - Disable API key

HELP;

        echo $help;
    }
}
