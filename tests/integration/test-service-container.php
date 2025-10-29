<?php
/**
 * Integration test for ServiceContainer type checking
 * Tests that services can be retrieved and methods are available
 * Critical for validating ServiceWrappers removal
 */

// Set error reporting to catch all issues
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Required files
require_once '/app/src/services/ServiceContainer.php';
require_once '/app/src/context/UserContext.php';

// Test results tracking
$tests_passed = 0;
$tests_failed = 0;
$errors = [];

function test($description, $callback) {
    global $tests_passed, $tests_failed, $errors;

    try {
        $result = $callback();
        if ($result === true) {
            echo "✓ $description\n";
            $tests_passed++;
        } else {
            echo "✗ $description\n";
            $errors[] = "Test failed: $description";
            $tests_failed++;
        }
    } catch (Exception $e) {
        echo "✗ $description - Exception: " . $e->getMessage() . "\n";
        $errors[] = "Exception in: $description - " . $e->getMessage();
        $tests_failed++;
    } catch (Error $e) {
        echo "✗ $description - Error: " . $e->getMessage() . "\n";
        $errors[] = "Error in: $description - " . $e->getMessage();
        $tests_failed++;
    }
}

echo "=== ServiceContainer Integration Tests ===\n";
echo "Testing ServiceWrappers removal compatibility...\n\n";

// Test 1: UserContext initialization
test("UserContext can be initialized", function() {
    $userContext = new UserContext();
    return $userContext !== null;
});

// Test 2: ServiceContainer singleton
test("ServiceContainer singleton can be obtained", function() {
    $container = ServiceContainer::getInstance();
    return $container !== null && $container instanceof ServiceContainer;
});

// Test 3: ServiceContainer returns same instance
test("ServiceContainer returns same instance", function() {
    $container1 = ServiceContainer::getInstance();
    $container2 = ServiceContainer::getInstance();
    return $container1 === $container2;
});

// Test 4: TransactionService retrieval
test("TransactionService can be retrieved", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getTransactionService();
    return $service !== null && is_object($service);
});

// Test 5: TransactionService has sendP2pEiou method
test("TransactionService::sendP2pEiou method exists", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getTransactionService();
    return method_exists($service, 'sendP2pEiou');
});

// Test 6: P2pService retrieval
test("P2pService can be retrieved", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getP2pService();
    return $service !== null && is_object($service);
});

// Test 7: P2pService has sendP2pRequest method
test("P2pService::sendP2pRequest method exists", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getP2pService();
    return method_exists($service, 'sendP2pRequest');
});

// Test 8: P2pService has sendP2pRequestFromFailedDirectTransaction method
test("P2pService::sendP2pRequestFromFailedDirectTransaction method exists", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getP2pService();
    return method_exists($service, 'sendP2pRequestFromFailedDirectTransaction');
});

// Test 9: SynchService retrieval
test("SynchService can be retrieved", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getSynchService();
    return $service !== null && is_object($service);
});

// Test 10: SynchService has synchSingleContact method
test("SynchService::synchSingleContact method exists", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getSynchService();
    return method_exists($service, 'synchSingleContact');
});

// Test 11: Method signatures match expected parameters
test("TransactionService::sendP2pEiou accepts array parameter", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getTransactionService();
    $reflection = new ReflectionMethod($service, 'sendP2pEiou');
    $params = $reflection->getParameters();
    return count($params) === 1 && $params[0]->getType()->getName() === 'array';
});

test("P2pService::sendP2pRequest accepts array parameter", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getP2pService();
    $reflection = new ReflectionMethod($service, 'sendP2pRequest');
    $params = $reflection->getParameters();
    return count($params) === 1 && $params[0]->getType()->getName() === 'array';
});

test("P2pService::sendP2pRequestFromFailedDirectTransaction accepts array parameter", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getP2pService();
    $reflection = new ReflectionMethod($service, 'sendP2pRequestFromFailedDirectTransaction');
    $params = $reflection->getParameters();
    return count($params) === 1 && $params[0]->getType()->getName() === 'array';
});

test("SynchService::synchSingleContact accepts correct parameters", function() {
    $container = ServiceContainer::getInstance();
    $service = $container->getSynchService();
    $reflection = new ReflectionMethod($service, 'synchSingleContact');
    $params = $reflection->getParameters();
    // Should have 2 parameters: address (string) and echo (string with default)
    return count($params) === 2 && $params[1]->isDefaultValueAvailable();
});

// Test 12: Services maintain singleton pattern
test("Services maintain singleton instances", function() {
    $container = ServiceContainer::getInstance();
    $service1 = $container->getTransactionService();
    $service2 = $container->getTransactionService();
    return $service1 === $service2;
});

// Test 13: All required services are available
test("All required services are available", function() {
    $container = ServiceContainer::getInstance();
    $required = [
        'getTransactionService',
        'getP2pService',
        'getSynchService',
        'getDebugService',
        'getContactService'
    ];

    foreach ($required as $method) {
        if (!method_exists($container, $method)) {
            return false;
        }
        $service = $container->$method();
        if ($service === null) {
            return false;
        }
    }
    return true;
});

// Summary
echo "\n=== Test Summary ===\n";
echo "Tests passed: $tests_passed\n";
echo "Tests failed: $tests_failed\n";

if ($tests_failed > 0) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
    echo "\nSome tests failed. Service container may have issues.\n";
    exit(1);
} else {
    echo "\nAll service container tests passed successfully!\n";
    echo "Services are ready for refactoring.\n";
    exit(0);
}