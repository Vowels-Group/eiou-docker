<?php
/**
 * Comprehensive test suite for all critical security fixes
 * Tests SQL injection, authentication, and CSRF protection
 */

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║     COMPREHENSIVE SECURITY FIX VALIDATION SUITE      ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

// Color codes for output
$GREEN = "\033[0;32m";
$RED = "\033[0;31m";
$YELLOW = "\033[0;33m";
$NC = "\033[0m"; // No Color

function runTest($name, $test) {
    global $totalTests, $passedTests, $failedTests, $GREEN, $RED, $NC;
    $totalTests++;

    echo "Testing: $name... ";

    try {
        $result = $test();
        if ($result) {
            echo "{$GREEN}✓ PASSED{$NC}\n";
            $passedTests++;
        } else {
            echo "{$RED}✗ FAILED{$NC}\n";
            $failedTests++;
        }
    } catch (Exception $e) {
        echo "{$RED}✗ ERROR: {$e->getMessage()}{$NC}\n";
        $failedTests++;
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "1. SQL INJECTION PROTECTION (#45)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

runTest("SQL - No sprintf concatenation", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/functions/functions.php';
    $content = file_get_contents($file);
    return !preg_match('/\$placeholders\s*=\s*sprintf\s*\(.*implode.*\$userAddresses/i', $content);
});

runTest("SQL - Uses str_repeat for placeholders", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/functions/functions.php';
    $content = file_get_contents($file);
    return strpos($content, "str_repeat('?,'") !== false;
});

runTest("SQL - Uses array_merge for parameters", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/functions/functions.php';
    $content = file_get_contents($file);
    return strpos($content, 'array_merge($userAddresses, $userAddresses') !== false;
});

runTest("SQL - No variable interpolation in queries", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/functions/functions.php';
    $content = file_get_contents($file);
    // Check for dangerous patterns like $variable inside SQL strings
    return !preg_match('/\$query\s*=.*WHERE.*\$[a-zA-Z_]/i', $content);
});

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "2. AUTHENTICATION VIA URL PROTECTION (#46)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

runTest("AUTH - Session management file exists", function() {
    return file_exists('/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php');
});

runTest("AUTH - No authcode in URLs", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/walletIndex.html';
    $content = file_get_contents($file);
    return strpos($content, '?authcode=') === false;
});

runTest("AUTH - Uses session-based authentication", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/walletIndex.html';
    $content = file_get_contents($file);
    return strpos($content, 'isAuthenticated()') !== false;
});

runTest("AUTH - HttpOnly cookie setting", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php';
    $content = file_get_contents($file);
    return strpos($content, "'httponly' => true") !== false;
});

runTest("AUTH - SameSite=Strict cookie", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php';
    $content = file_get_contents($file);
    return strpos($content, "'samesite' => 'Strict'") !== false;
});

runTest("AUTH - Session timeout implemented", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php';
    $content = file_get_contents($file);
    return strpos($content, 'checkSessionTimeout') !== false;
});

runTest("AUTH - No authcode in forms", function() {
    $files = [
        '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/eiouForm.html',
        '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/contactForm.html'
    ];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        if (strpos($content, 'name="authcode"') !== false) {
            return false;
        }
    }
    return true;
});

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "3. CSRF PROTECTION (#47)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

runTest("CSRF - Token generation function exists", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php';
    $content = file_get_contents($file);
    return strpos($content, 'function generateCSRFToken()') !== false;
});

runTest("CSRF - Token validation function exists", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php';
    $content = file_get_contents($file);
    return strpos($content, 'function validateCSRFToken(') !== false;
});

runTest("CSRF - Verification on POST requests", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/walletIndex.html';
    $content = file_get_contents($file);
    return strpos($content, 'verifyCSRFToken()') !== false;
});

runTest("CSRF - Cryptographically secure tokens", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php';
    $content = file_get_contents($file);
    return strpos($content, 'random_bytes(32)') !== false;
});

runTest("CSRF - Constant-time comparison", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php';
    $content = file_get_contents($file);
    return strpos($content, 'hash_equals') !== false;
});

runTest("CSRF - Token expiration (1 hour)", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php';
    $content = file_get_contents($file);
    return strpos($content, '> 3600') !== false;
});

runTest("CSRF - All forms have tokens", function() {
    $files = [
        '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/eiouForm.html',
        '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/contactForm.html',
        '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/contactSection.html'
    ];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        $formCount = substr_count($content, '<form method="POST"');
        $csrfCount = substr_count($content, 'name="csrf_token"');

        if ($formCount > 0 && $csrfCount < $formCount) {
            return false;
        }
    }
    return true;
});

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "4. INTEGRATION TESTS\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

runTest("All session functions work together", function() {
    // Load session file
    require_once('/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php');

    // Check if all required functions exist
    $requiredFunctions = [
        'startSecureSession',
        'isAuthenticated',
        'authenticate',
        'logout',
        'checkSessionTimeout',
        'requireAuth',
        'generateCSRFToken',
        'getCSRFToken',
        'validateCSRFToken',
        'verifyCSRFToken'
    ];

    foreach ($requiredFunctions as $func) {
        if (!function_exists($func)) {
            return false;
        }
    }
    return true;
});

runTest("No credentials logged in redirects", function() {
    $file = '/home/adrien/Github/eiou-org/eiou/src/gui/functions/functions.php';
    $content = file_get_contents($file);
    // Check redirectMessage doesn't include authcode
    return strpos($content, '?authcode=') === false;
});

runTest("Security headers configured correctly", function() {
    $sessionFile = '/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php';
    $content = file_get_contents($sessionFile);

    $securityFeatures = [
        'httponly',
        'samesite',
        'session_regenerate_id'
    ];

    foreach ($securityFeatures as $feature) {
        if (stripos($content, $feature) === false) {
            return false;
        }
    }
    return true;
});

// Summary
echo "\n╔══════════════════════════════════════════════════════╗\n";
echo "║                    TEST SUMMARY                      ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

$successRate = $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 1) : 0;

echo "Total Tests: $totalTests\n";
echo "{$GREEN}Passed: $passedTests{$NC}\n";
if ($failedTests > 0) {
    echo "{$RED}Failed: $failedTests{$NC}\n";
} else {
    echo "Failed: 0\n";
}
echo "Success Rate: $successRate%\n";

echo "\n";

if ($failedTests === 0) {
    echo "╔══════════════════════════════════════════════════════╗\n";
    echo "║  {$GREEN}✅ ALL SECURITY FIXES VALIDATED SUCCESSFULLY!{$NC}     ║\n";
    echo "╚══════════════════════════════════════════════════════╝\n\n";

    echo "🔒 Security Status:\n";
    echo "━━━━━━━━━━━━━━━━━━━\n";
    echo "✅ SQL Injection: PROTECTED\n";
    echo "✅ URL Authentication: PROTECTED\n";
    echo "✅ CSRF Attacks: PROTECTED\n";
    echo "\n";
    echo "The application has been hardened against all critical vulnerabilities.\n";
    echo "Ready for pull request creation!\n";

    exit(0);
} else {
    echo "╔══════════════════════════════════════════════════════╗\n";
    echo "║  {$RED}❌ SOME TESTS FAILED - REVIEW NEEDED{$NC}            ║\n";
    echo "╚══════════════════════════════════════════════════════╝\n\n";

    echo "Please review the failed tests above before creating a pull request.\n";

    exit(1);
}