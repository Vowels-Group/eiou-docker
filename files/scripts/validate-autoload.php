<?php
/**
 * Validates that the Composer autoloader can load all critical classes.
 * Run this after any namespace changes to verify everything works.
 *
 * Usage: php /app/eiou/scripts/validate-autoload.php
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

$classesToTest = [
    // Core
    'Eiou\\Core\\Constants',
    'Eiou\\Core\\ErrorCodes',
    'Eiou\\Core\\Application',
    'Eiou\\Core\\UserContext',
    'Eiou\\Core\\DatabaseContext',
    'Eiou\\Core\\ErrorHandler',
    'Eiou\\Core\\Wallet',

    // Utils
    'Eiou\\Utils\\SecureLogger',
    'Eiou\\Utils\\InputValidator',

    // Services
    'Eiou\\Services\\ServiceContainer',
    'Eiou\\Services\\ContactService',
    'Eiou\\Services\\TransactionService',
    'Eiou\\Services\\SyncService',
    'Eiou\\Services\\MessageService',

    // Events
    'Eiou\\Events\\EventDispatcher',
    'Eiou\\Events\\SyncEvents',
];

$failed = [];
$passed = [];

foreach ($classesToTest as $class) {
    if (class_exists($class) || interface_exists($class)) {
        $passed[] = $class;
        echo "✓ $class\n";
    } else {
        $failed[] = $class;
        echo "✗ $class - NOT FOUND\n";
    }
}

echo "\n";
echo "Passed: " . count($passed) . "\n";
echo "Failed: " . count($failed) . "\n";

if (count($failed) > 0) {
    echo "\nFAILED CLASSES:\n";
    foreach ($failed as $class) {
        echo "  - $class\n";
    }
    exit(1);
}

echo "\nAll classes loaded successfully!\n";
exit(0);
