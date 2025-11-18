<?php
/**
 * API Key Management CLI Tool
 *
 * Copyright 2025
 * Command-line tool for managing API keys
 *
 * Usage:
 *   php manage-keys.php generate --name="My App" [--expires="2026-01-01"]
 *   php manage-keys.php list
 *   php manage-keys.php revoke --key-hash="abc123..."
 *   php manage-keys.php activate --key-hash="abc123..."
 */

// Load dependencies
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/ApiAuth.php';

$config = require __DIR__ . '/../config.php';
$auth = new ApiAuth($config);

// Parse command-line arguments
$command = $argv[1] ?? 'help';
$options = parseArguments($argv);

switch ($command) {
    case 'generate':
        generateKey($auth, $options);
        break;

    case 'list':
        listKeys($config);
        break;

    case 'revoke':
        revokeKey($auth, $options);
        break;

    case 'activate':
        activateKey($auth, $options);
        break;

    case 'help':
    default:
        showHelp();
        break;
}

/**
 * Generate a new API key
 */
function generateKey(ApiAuth $auth, array $options): void
{
    $name = $options['name'] ?? null;
    $expires = $options['expires'] ?? null;

    if (!$name) {
        echo "Error: --name is required\n";
        exit(1);
    }

    // Validate expiration date
    if ($expires && strtotime($expires) === false) {
        echo "Error: Invalid expiration date format. Use ISO 8601 (YYYY-MM-DD)\n";
        exit(1);
    }

    $expiresAt = $expires ? date('c', strtotime($expires)) : null;

    // Generate key
    $result = ApiAuth::generateApiKey($name, [], $expiresAt);

    // Load existing keys
    $keysFile = require __DIR__ . '/../config.php';
    $keysFile = $keysFile['auth']['keys_file'];

    if (file_exists($keysFile)) {
        $existingKeys = json_decode(file_get_contents($keysFile), true) ?? [];
    } else {
        $existingKeys = [];
    }

    // Add new key
    $existingKeys[] = $result['info'];

    // Save keys
    if ($auth->saveApiKeys($existingKeys)) {
        echo "✓ API Key generated successfully!\n\n";
        echo "API Key: " . $result['key'] . "\n";
        echo "Name: $name\n";
        echo "Created: " . date('Y-m-d H:i:s') . "\n";
        if ($expiresAt) {
            echo "Expires: " . date('Y-m-d H:i:s', strtotime($expiresAt)) . "\n";
        } else {
            echo "Expires: Never\n";
        }
        echo "\n⚠️  IMPORTANT: Save this key now. You won't be able to see it again!\n";
    } else {
        echo "✗ Failed to save API key\n";
        exit(1);
    }
}

/**
 * List all API keys
 */
function listKeys(array $config): void
{
    $keysFile = $config['auth']['keys_file'];

    if (!file_exists($keysFile)) {
        echo "No API keys found.\n";
        return;
    }

    $keys = json_decode(file_get_contents($keysFile), true) ?? [];

    if (empty($keys)) {
        echo "No API keys found.\n";
        return;
    }

    echo "\n";
    echo str_pad("NAME", 30) . str_pad("STATUS", 10) . str_pad("CREATED", 20) . str_pad("EXPIRES", 20) . "USAGE\n";
    echo str_repeat("-", 100) . "\n";

    foreach ($keys as $key) {
        $name = str_pad($key['name'], 30);
        $status = str_pad($key['active'] ? '✓ Active' : '✗ Revoked', 10);
        $created = str_pad(date('Y-m-d H:i', strtotime($key['created_at'])), 20);
        $expires = $key['expires_at'] ? date('Y-m-d H:i', strtotime($key['expires_at'])) : 'Never';
        $expires = str_pad($expires, 20);
        $usage = ($key['usage_count'] ?? 0) . ' requests';

        echo $name . $status . $created . $expires . $usage . "\n";
    }

    echo "\n";
}

/**
 * Revoke (deactivate) an API key
 */
function revokeKey(ApiAuth $auth, array $options): void
{
    $keyHash = $options['key-hash'] ?? $options['hash'] ?? null;

    if (!$keyHash) {
        echo "Error: --key-hash is required\n";
        exit(1);
    }

    $config = require __DIR__ . '/../config.php';
    $keysFile = $config['auth']['keys_file'];

    if (!file_exists($keysFile)) {
        echo "No API keys found.\n";
        exit(1);
    }

    $keys = json_decode(file_get_contents($keysFile), true) ?? [];
    $found = false;

    foreach ($keys as &$key) {
        if ($key['key_hash'] === $keyHash) {
            $key['active'] = false;
            $found = true;
            break;
        }
    }

    if ($found) {
        $auth->saveApiKeys($keys);
        echo "✓ API key revoked successfully\n";
    } else {
        echo "✗ API key not found\n";
        exit(1);
    }
}

/**
 * Activate a previously revoked API key
 */
function activateKey(ApiAuth $auth, array $options): void
{
    $keyHash = $options['key-hash'] ?? $options['hash'] ?? null;

    if (!$keyHash) {
        echo "Error: --key-hash is required\n";
        exit(1);
    }

    $config = require __DIR__ . '/../config.php';
    $keysFile = $config['auth']['keys_file'];

    if (!file_exists($keysFile)) {
        echo "No API keys found.\n";
        exit(1);
    }

    $keys = json_decode(file_get_contents($keysFile), true) ?? [];
    $found = false;

    foreach ($keys as &$key) {
        if ($key['key_hash'] === $keyHash) {
            $key['active'] = true;
            $found = true;
            break;
        }
    }

    if ($found) {
        $auth->saveApiKeys($keys);
        echo "✓ API key activated successfully\n";
    } else {
        echo "✗ API key not found\n";
        exit(1);
    }
}

/**
 * Show help message
 */
function showHelp(): void
{
    echo <<<HELP

EIOU API Key Management Tool
============================

Commands:
  generate    Generate a new API key
  list        List all API keys
  revoke      Revoke (deactivate) an API key
  activate    Activate a revoked API key
  help        Show this help message

Usage:
  php manage-keys.php generate --name="My App" [--expires="2026-01-01"]
  php manage-keys.php list
  php manage-keys.php revoke --key-hash="abc123..."
  php manage-keys.php activate --key-hash="abc123..."

Options:
  --name         Name/description for the API key
  --expires      Expiration date (YYYY-MM-DD format)
  --key-hash     Hash of the API key to revoke/activate

Examples:
  # Generate a new API key
  php manage-keys.php generate --name="Mobile App"

  # Generate a key that expires in 1 year
  php manage-keys.php generate --name="Test Key" --expires="2026-01-01"

  # List all keys
  php manage-keys.php list

  # Revoke a key
  php manage-keys.php revoke --key-hash="a1b2c3d4..."


HELP;
}

/**
 * Parse command-line arguments
 *
 * @param array $argv
 * @return array
 */
function parseArguments(array $argv): array
{
    $options = [];

    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            $arg = substr($arg, 2);
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', $arg, 2);
                $options[$key] = trim($value, '"\'');
            } else {
                $options[$arg] = true;
            }
        }
    }

    return $options;
}
