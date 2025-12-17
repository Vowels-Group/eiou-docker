<?php
# Copyright 2025

require_once __DIR__ . '/../core/ErrorCodes.php';

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

class ApiKeyService {
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
        'admin',
        '*'
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
    - wallet:read     Read wallet balance and transactions
    - wallet:send     Send transactions
    - contacts:read   List and view contacts
    - contacts:write  Add, update, delete contacts
    - system:read     View system status and metrics
    - admin           Full administrative access
    - *               All permissions (same as admin)

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
  GET  /api/v1/wallet/balance      - Get wallet balances
  POST /api/v1/wallet/send         - Send transaction
  GET  /api/v1/wallet/transactions - Transaction history
  GET  /api/v1/contacts            - List contacts
  POST /api/v1/contacts            - Add contact
  GET  /api/v1/system/status       - System status

HELP;

        echo $help;
    }
}
