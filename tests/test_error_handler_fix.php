#!/usr/bin/env php
<?php
/**
 * Test script to verify ErrorHandler and UserContext fixes
 */

echo "Testing ErrorHandler and UserContext fixes...\n";
echo "==============================================\n\n";

// Test 1: ErrorHandler can load Constants.php
echo "Test 1: Loading ErrorHandler with fixed Constants path... ";
try {
    require_once dirname(__DIR__) . '/src/core/ErrorHandler.php';
    echo "✓ PASSED\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Constants class is available
echo "Test 2: Verifying Constants class is available... ";
if (class_exists('Constants')) {
    echo "✓ PASSED\n";
} else {
    echo "✗ FAILED: Constants class not found\n";
    exit(1);
}

// Test 3: UserContext class loads correctly
echo "Test 3: Loading UserContext class... ";
try {
    require_once dirname(__DIR__) . '/src/core/UserContext.php';
    echo "✓ PASSED\n";
} catch (Exception $e) {
    echo "✗ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: UserContext singleton works
echo "Test 4: Testing UserContext singleton pattern... ";
$context1 = UserContext::getInstance();
$context2 = UserContext::getInstance();
if ($context1 === $context2) {
    echo "✓ PASSED\n";
} else {
    echo "✗ FAILED: Singleton instances do not match\n";
    exit(1);
}

// Test 5: UserContext can store and retrieve data
echo "Test 5: Testing UserContext data storage... ";
$testData = ['testKey' => 'testValue', 'myPublicKey' => 'test_public_key'];
$context1->setUserData($testData);
if ($context1->get('testKey') === 'testValue' &&
    $context1->getPublicKey() === 'test_public_key') {
    echo "✓ PASSED\n";
} else {
    echo "✗ FAILED: Data storage/retrieval failed\n";
    exit(1);
}

// Test 6: UserContext backward compatibility with global $user
echo "Test 6: Testing backward compatibility with global \$user... ";
$global_user = ['globalKey' => 'globalValue', 'myTorAddress' => 'test.onion'];
$GLOBALS['user'] = $global_user;
$context3 = UserContext::getInstance();
$context3->clear(); // Clear previous data
$context3->initFromGlobal();
if ($context3->get('globalKey') === 'globalValue' &&
    $context3->getTorAddress() === 'test.onion') {
    echo "✓ PASSED\n";
} else {
    echo "✗ FAILED: Global compatibility failed\n";
    exit(1);
}

// Test 7: Constants can be accessed
echo "Test 7: Testing Constants access... ";
if (Constants::ERROR_GENERIC === "An error occurred. Please try again later." &&
    Constants::TRANSACTION_DEFAULT_CURRENCY === 'USD') {
    echo "✓ PASSED\n";
} else {
    echo "✗ FAILED: Constants values incorrect\n";
    exit(1);
}

echo "\n==============================================\n";
echo "All tests passed successfully! ✓\n";
echo "ErrorHandler path fix and UserContext implementation are working correctly.\n";