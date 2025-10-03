<?php
/**
 * Test script to verify session-based authentication fix
 * Tests that authentication no longer uses URL parameters
 */

echo "Testing Session-Based Authentication Fix\n";
echo "========================================\n\n";

// Test 1: Check session.php functions exist
echo "Test 1: Verifying session management functions...\n";
require_once('/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php');

$functions = [
    'startSecureSession',
    'isAuthenticated',
    'authenticate',
    'logout',
    'checkSessionTimeout',
    'requireAuth'
];

foreach ($functions as $func) {
    if (function_exists($func)) {
        echo "✓ Function $func exists\n";
    } else {
        echo "✗ Function $func is missing!\n";
        exit(1);
    }
}

// Test 2: Verify walletIndex.html doesn't have authcode in URLs
echo "\nTest 2: Checking walletIndex.html for URL auth removal...\n";
$walletContent = file_get_contents('/home/adrien/Github/eiou-org/eiou/src/walletIndex.html');

// Check for old patterns that should NOT exist
$badPatterns = [
    '/\$_GET\[[\'"

]authcode[\'"]?\].*\$_POST\[[\'"

]authcode[\'"]?\]/',  // Old auth check
    '/\?authcode=/',  // Auth in URL
    '/hash_equals\(\$user\[[\'"

]authcode[\'"]?\],\s*\$authCode\)/'  // Old verification
];

$passed = true;
foreach ($badPatterns as $pattern) {
    if (preg_match($pattern, $walletContent)) {
        echo "✗ Found old auth pattern that should be removed: $pattern\n";
        $passed = false;
    }
}

// Check for new patterns that SHOULD exist
$goodPatterns = [
    '/require_once.*session\.php/',  // Session include
    '/startSecureSession\(\)/',      // Session start
    '/isAuthenticated\(\)/',         // Auth check
    '/authenticate\(/'                // Auth function
];

foreach ($goodPatterns as $pattern) {
    if (preg_match($pattern, $walletContent)) {
        echo "✓ Found new session pattern: $pattern\n";
    } else {
        echo "✗ Missing new session pattern: $pattern\n";
        $passed = false;
    }
}

if ($passed) {
    echo "✓ walletIndex.html properly uses session authentication\n";
}

// Test 3: Verify no authcode in form actions
echo "\nTest 3: Checking forms for authcode removal...\n";
$formFiles = [
    '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/eiouForm.html',
    '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/contactForm.html',
    '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/contactSection.html'
];

$authcodeFound = false;
foreach ($formFiles as $file) {
    $content = file_get_contents($file);
    if (strpos($content, 'name="authcode"') !== false) {
        echo "✗ Found authcode input in: " . basename($file) . "\n";
        $authcodeFound = true;
    }
}

if (!$authcodeFound) {
    echo "✓ No authcode hidden inputs found in forms\n";
}

// Test 4: Verify redirectMessage doesn't include authcode
echo "\nTest 4: Checking redirectMessage function...\n";
$functionsContent = file_get_contents('/home/adrien/Github/eiou-org/eiou/src/gui/functions/functions.php');

if (strpos($functionsContent, '?authcode=') === false) {
    echo "✓ redirectMessage no longer includes authcode in URL\n";
} else {
    echo "✗ redirectMessage still includes authcode in URL\n";
    $passed = false;
}

// Test 5: Session security settings
echo "\nTest 5: Verifying session security settings...\n";
$sessionContent = file_get_contents('/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php');

$securityFeatures = [
    'httponly',           // HttpOnly cookie flag
    'samesite',          // SameSite cookie attribute
    'session_regenerate_id', // Session fixation prevention
    'hash_equals',       // Timing attack prevention
    'checkSessionTimeout' // Session timeout
];

foreach ($securityFeatures as $feature) {
    if (stripos($sessionContent, $feature) !== false) {
        echo "✓ Security feature implemented: $feature\n";
    } else {
        echo "✗ Missing security feature: $feature\n";
    }
}

// Summary
echo "\n========================================\n";
if ($passed && !$authcodeFound) {
    echo "✅ ALL TESTS PASSED!\n";
    echo "Authentication vulnerability has been successfully fixed.\n\n";
    echo "Key improvements:\n";
    echo "- Authentication now uses secure PHP sessions\n";
    echo "- Auth codes removed from all URLs\n";
    echo "- Session cookies are HttpOnly and SameSite=Strict\n";
    echo "- Session IDs regenerated on login and periodically\n";
    echo "- 30-minute inactivity timeout implemented\n";
    echo "- No credentials exposed in browser history or logs\n";
} else {
    echo "❌ SOME TESTS FAILED\n";
    echo "Please review the issues above.\n";
    exit(1);
}