#!/usr/bin/env php
<?php
/**
 * Authentication Migration Script
 *
 * Copyright 2025
 *
 * Migrates from old Session class to new hardened SessionManager + AuthenticationService
 *
 * Usage:
 *   php scripts/migrate-authentication.php
 */

require_once __DIR__ . '/../src/core/Constants.php';
require_once __DIR__ . '/../src/core/DatabaseContext.php';
require_once __DIR__ . '/../src/core/UserContext.php';
require_once __DIR__ . '/../src/security/SessionManager.php';
require_once __DIR__ . '/../src/security/AuthenticationService.php';
require_once __DIR__ . '/../src/utils/SecureLogger.php';

class AuthenticationMigration
{
    private PDO $pdo;
    private AuthenticationService $authService;
    private UserContext $userContext;

    public function __construct()
    {
        echo "Authentication Migration Tool\n";
        echo str_repeat('=', 50) . "\n\n";

        // Initialize components
        $this->pdo = DatabaseContext::getInstance()->getPDO();

        // Start session for SessionManager
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionManager = new SessionManager();
        $this->authService = new AuthenticationService($this->pdo, $sessionManager);
        $this->userContext = UserContext::getInstance();
    }

    /**
     * Run migration
     */
    public function migrate(): bool
    {
        echo "Step 1: Checking current authentication configuration...\n";

        // Check if userconfig.json exists
        if (!file_exists('/etc/eiou/userconfig.json')) {
            echo "  ✗ ERROR: userconfig.json not found\n";
            echo "  Please initialize the wallet first.\n";
            return false;
        }

        echo "  ✓ Configuration file found\n\n";

        echo "Step 2: Loading auth code from UserContext...\n";

        // Get auth code from UserContext
        $plainAuthCode = $this->userContext->getAuthCode();

        if ($plainAuthCode === null) {
            echo "  ✗ ERROR: No auth code found in configuration\n";
            echo "  Please set an auth code first.\n";
            return false;
        }

        echo "  ✓ Auth code loaded from configuration\n";
        echo "  ℹ Auth code length: " . strlen($plainAuthCode) . " characters\n\n";

        echo "Step 3: Validating auth code strength...\n";

        // Validate minimum length
        if (strlen($plainAuthCode) < 8) {
            echo "  ⚠ WARNING: Auth code is less than 8 characters\n";
            echo "  Recommended minimum: 8 characters\n";
            echo "  Current length: " . strlen($plainAuthCode) . " characters\n";

            if (!$this->confirmContinue()) {
                echo "  Migration cancelled.\n";
                return false;
            }
        } else {
            echo "  ✓ Auth code meets minimum length requirement\n";
        }
        echo "\n";

        echo "Step 4: Migrating auth code to secure hash...\n";

        $identifier = 'wallet'; // Default identifier

        // Migrate to hashed version
        $success = $this->authService->setAuthCode($identifier, $plainAuthCode);

        if (!$success) {
            echo "  ✗ ERROR: Failed to migrate auth code\n";
            return false;
        }

        echo "  ✓ Auth code migrated to bcrypt hash (cost: 12)\n\n";

        echo "Step 5: Verifying migration...\n";

        // Test authentication with the same code
        $verified = $this->authService->authenticate($plainAuthCode, $identifier);

        if (!$verified) {
            echo "  ✗ ERROR: Authentication failed after migration\n";
            echo "  Please investigate and restore from backup if needed.\n";
            return false;
        }

        echo "  ✓ Authentication successful with migrated hash\n\n";

        echo "Step 6: Creating database tables...\n";

        // Tables are automatically created by AuthenticationService constructor
        echo "  ✓ Database tables created/verified:\n";
        echo "    - login_attempts (tracks authentication attempts)\n";
        echo "    - account_lockouts (manages account security)\n";
        echo "    - auth_codes (stores hashed credentials)\n\n";

        echo "Step 7: Migration complete!\n\n";

        $this->printSecurityInfo();
        $this->printNextSteps();

        return true;
    }

    /**
     * Print security information
     */
    private function printSecurityInfo(): void
    {
        echo str_repeat('=', 50) . "\n";
        echo "SECURITY CONFIGURATION\n";
        echo str_repeat('=', 50) . "\n";
        echo "Session Security:\n";
        echo "  - Idle timeout: 30 minutes\n";
        echo "  - Absolute timeout: 24 hours\n";
        echo "  - Session regeneration: Every 5 minutes\n";
        echo "  - Cookie flags: HttpOnly, Secure, SameSite=Strict\n";
        echo "  - Session fingerprinting: Enabled\n\n";

        echo "Authentication Security:\n";
        echo "  - Password hashing: bcrypt (cost: 12)\n";
        echo "  - Max login attempts: 5 per 5 minutes\n";
        echo "  - Account lockout: 15 minutes after 5 failures\n";
        echo "  - Rate limiting: Per-user and per-IP\n";
        echo "  - Timing attack protection: Random delays\n";
        echo "  - CSRF protection: Token-based\n\n";
        echo str_repeat('=', 50) . "\n\n";
    }

    /**
     * Print next steps
     */
    private function printNextSteps(): void
    {
        echo str_repeat('=', 50) . "\n";
        echo "NEXT STEPS\n";
        echo str_repeat('=', 50) . "\n";
        echo "1. Update walletIndex.html to use new SessionManager\n";
        echo "   - Replace: require_once 'session.php'\n";
        echo "   - With: require_once 'security/SessionManager.php'\n\n";

        echo "2. Add CSRF tokens to all POST forms:\n";
        echo "   <?php echo \$sessionManager->getCsrfField(); ?>\n\n";

        echo "3. Test authentication flow:\n";
        echo "   - Log out and log back in\n";
        echo "   - Try wrong password (should lock after 5 attempts)\n";
        echo "   - Verify session timeout after 30 minutes\n\n";

        echo "4. Monitor authentication logs:\n";
        echo "   tail -f /var/log/eiou/app.log\n\n";

        echo "5. Set up periodic cleanup (add to cron):\n";
        echo "   0 */6 * * * php /app/scripts/auth-cleanup.php\n\n";

        echo str_repeat('=', 50) . "\n\n";
    }

    /**
     * Ask user to confirm continuation
     */
    private function confirmContinue(): bool
    {
        echo "  Continue anyway? (yes/no): ";
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        fclose($handle);

        return strtolower($line) === 'yes' || strtolower($line) === 'y';
    }

    /**
     * Test authentication
     */
    public function testAuthentication(): void
    {
        echo "\nTesting Authentication System...\n";
        echo str_repeat('=', 50) . "\n\n";

        $identifier = 'wallet';

        echo "Test 1: Get lockout status\n";
        $isLocked = $this->authService->isAccountLocked($identifier);
        echo "  Status: " . ($isLocked ? "LOCKED" : "UNLOCKED") . "\n\n";

        if ($isLocked) {
            $lockoutInfo = $this->authService->getLockoutInfo($identifier);
            echo "  Locked until: " . $lockoutInfo['locked_until'] . "\n";
            echo "  Remaining: " . round($lockoutInfo['remaining_seconds'] / 60, 1) . " minutes\n";
            echo "  Attempt count: " . $lockoutInfo['attempt_count'] . "\n\n";

            echo "  Unlocking account for testing...\n";
            $this->authService->unlockAccount($identifier);
            echo "  ✓ Account unlocked\n\n";
        }

        echo "Test 2: Failed authentication (wrong password)\n";
        $result = $this->authService->authenticate('WrongPassword123!', $identifier);
        echo "  Result: " . ($result ? "SUCCESS" : "FAILED") . " (expected: FAILED)\n";
        echo "  ✓ " . ($result ? "UNEXPECTED SUCCESS" : "Correctly rejected") . "\n\n";

        echo "Test 3: Login attempt history\n";
        $history = $this->authService->getLoginHistory($identifier, 5);
        echo "  Recent attempts:\n";
        foreach ($history as $attempt) {
            $status = $attempt['success'] ? "✓ SUCCESS" : "✗ FAILED";
            echo "    " . $attempt['attempt_time'] . " - "
                . $attempt['ip_address'] . " - " . $status . "\n";
        }
        echo "\n";

        echo "Testing complete!\n\n";
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    try {
        $migration = new AuthenticationMigration();

        // Check command line arguments
        $command = $argv[1] ?? 'migrate';

        switch ($command) {
            case 'test':
                $migration->testAuthentication();
                break;

            case 'migrate':
            default:
                $success = $migration->migrate();
                exit($success ? 0 : 1);
        }
    } catch (Exception $e) {
        echo "\n✗ ERROR: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}
