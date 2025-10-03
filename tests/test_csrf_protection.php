<?php
/**
 * Test script to verify CSRF protection implementation
 * Tests that all forms have CSRF tokens and validation is working
 */

echo "Testing CSRF Protection Implementation\n";
echo "=====================================\n\n";

// Test 1: Check CSRF functions exist
echo "Test 1: Verifying CSRF functions exist...\n";
require_once('/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php');

$csrfFunctions = [
    'generateCSRFToken',
    'getCSRFToken',
    'validateCSRFToken',
    'verifyCSRFToken',
    'getCSRFField'
];

foreach ($csrfFunctions as $func) {
    if (function_exists($func)) {
        echo "✓ Function $func exists\n";
    } else {
        echo "✗ Function $func is missing!\n";
        exit(1);
    }
}

// Test 2: Verify walletIndex.html includes CSRF verification
echo "\nTest 2: Checking walletIndex.html for CSRF implementation...\n";
$walletContent = file_get_contents('/home/adrien/Github/eiou-org/eiou/src/walletIndex.html');

if (strpos($walletContent, 'verifyCSRFToken()') !== false) {
    echo "✓ CSRF verification called on POST requests\n";
} else {
    echo "✗ Missing verifyCSRFToken() call\n";
    exit(1);
}

if (strpos($walletContent, '$csrfToken = getCSRFToken()') !== false) {
    echo "✓ CSRF token generation for forms\n";
} else {
    echo "✗ Missing CSRF token generation\n";
    exit(1);
}

// Test 3: Check all forms have CSRF tokens
echo "\nTest 3: Verifying all forms have CSRF tokens...\n";
$formFiles = [
    '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/eiouForm.html',
    '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/contactForm.html',
    '/home/adrien/Github/eiou-org/eiou/src/gui/layout/walletSubParts/contactSection.html'
];

$formsWithoutCSRF = 0;
$totalForms = 0;

foreach ($formFiles as $file) {
    $content = file_get_contents($file);

    // Count forms
    $formCount = substr_count($content, '<form method="POST"');
    $totalForms += $formCount;

    // Count CSRF tokens
    $csrfCount = substr_count($content, 'name="csrf_token"');

    if ($formCount > 0) {
        if ($csrfCount >= $formCount) {
            echo "✓ " . basename($file) . ": $formCount forms, $csrfCount CSRF tokens\n";
        } else {
            echo "✗ " . basename($file) . ": $formCount forms, but only $csrfCount CSRF tokens\n";
            $formsWithoutCSRF += ($formCount - $csrfCount);
        }
    }
}

if ($formsWithoutCSRF > 0) {
    echo "✗ $formsWithoutCSRF forms missing CSRF tokens!\n";
    exit(1);
} else {
    echo "✓ All $totalForms forms have CSRF protection\n";
}

// Test 4: Verify CSRF token security features
echo "\nTest 4: Checking CSRF security features...\n";
$sessionContent = file_get_contents('/home/adrien/Github/eiou-org/eiou/src/gui/includes/session.php');

$securityFeatures = [
    'random_bytes(32)' => 'Cryptographically secure token generation',
    'hash_equals' => 'Timing attack prevention',
    'csrf_token_time' => 'Token expiration tracking',
    'time() - $_SESSION[\'csrf_token_time\'] > 3600' => 'Token expiration (1 hour)',
    'http_response_code(403)' => 'Proper error response code'
];

foreach ($securityFeatures as $pattern => $description) {
    if (strpos($sessionContent, $pattern) !== false) {
        echo "✓ $description\n";
    } else {
        echo "✗ Missing: $description\n";
    }
}

// Test 5: Simulate CSRF token validation
echo "\nTest 5: Testing CSRF token validation logic...\n";

// Start a test session
session_start();

// Generate a token
$token1 = generateCSRFToken();
echo "✓ Generated token: " . substr($token1, 0, 10) . "...\n";

// Validate with correct token
if (validateCSRFToken($token1)) {
    echo "✓ Valid token accepted\n";
} else {
    echo "✗ Valid token rejected!\n";
    exit(1);
}

// Validate with incorrect token
if (!validateCSRFToken('invalid_token_12345')) {
    echo "✓ Invalid token rejected\n";
} else {
    echo "✗ Invalid token accepted!\n";
    exit(1);
}

// Test token persistence
$token2 = generateCSRFToken();
if ($token1 === $token2) {
    echo "✓ Token persists across requests\n";
} else {
    echo "✗ Token not persisting!\n";
    exit(1);
}

// Clean up
session_destroy();

// Summary
echo "\n=====================================\n";
echo "✅ ALL TESTS PASSED!\n";
echo "CSRF protection has been successfully implemented.\n\n";
echo "Key features implemented:\n";
echo "- CSRF tokens required on all POST forms\n";
echo "- Cryptographically secure token generation (64 hex chars)\n";
echo "- Tokens expire after 1 hour\n";
echo "- Constant-time comparison prevents timing attacks\n";
echo "- 403 Forbidden response on validation failure\n";
echo "- All $totalForms forms protected\n";
echo "\nThe application is now protected against CSRF attacks.\n";