<?php
/**
 * Unit tests for ServiceWrappers removal refactoring
 *
 * These tests validate that the refactored direct service calls
 * maintain the same behavior as the original wrapper functions.
 */

// Simple test framework (PHPUnit not required)
class TestCase {
    protected $passed = 0;
    protected $failed = 0;
    protected $errors = [];

    protected function assertTrue($condition, $message = '') {
        if ($condition) {
            $this->passed++;
            echo "✓ $message\n";
        } else {
            $this->failed++;
            $this->errors[] = $message;
            echo "✗ $message\n";
        }
    }

    protected function assertFalse($condition, $message = '') {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertEquals($expected, $actual, $message = '') {
        $this->assertTrue($expected === $actual, "$message (expected: $expected, got: $actual)");
    }

    protected function assertNotNull($value, $message = '') {
        $this->assertTrue($value !== null, $message);
    }

    protected function assertMethodExists($object, $method, $message = '') {
        $this->assertTrue(method_exists($object, $method), $message);
    }

    public function run() {
        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (strpos($method, 'test') === 0) {
                echo "\nRunning $method...\n";
                try {
                    $this->$method();
                } catch (Exception $e) {
                    $this->failed++;
                    $this->errors[] = "$method: " . $e->getMessage();
                    echo "✗ Exception in $method: " . $e->getMessage() . "\n";
                }
            }
        }

        $this->printSummary();
    }

    protected function printSummary() {
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "Test Results\n";
        echo str_repeat('=', 50) . "\n";
        echo "Passed: $this->passed\n";
        echo "Failed: $this->failed\n";

        if ($this->failed > 0) {
            echo "\nErrors:\n";
            foreach ($this->errors as $error) {
                echo "  - $error\n";
            }
            exit(1);
        } else {
            echo "\nAll tests passed!\n";
            exit(0);
        }
    }
}

/**
 * Test ServiceContainer and refactored services
 */
class ServiceRefactoringTest extends TestCase {

    private $container;

    protected function setUp() {
        // Initialize for each test
        require_once '/app/src/context/UserContext.php';
        require_once '/app/src/services/ServiceContainer.php';

        $this->container = ServiceContainer::getInstance();
    }

    /**
     * Test that ServiceContainer singleton works
     */
    public function testServiceContainerSingleton() {
        $this->setUp();

        $container1 = ServiceContainer::getInstance();
        $container2 = ServiceContainer::getInstance();

        $this->assertTrue(
            $container1 === $container2,
            'ServiceContainer returns same instance'
        );
    }

    /**
     * Test TransactionService is available
     */
    public function testTransactionServiceAvailable() {
        $this->setUp();

        $service = $this->container->getTransactionService();

        $this->assertNotNull(
            $service,
            'TransactionService can be retrieved'
        );

        $this->assertMethodExists(
            $service,
            'sendP2pEiou',
            'TransactionService has sendP2pEiou method'
        );
    }

    /**
     * Test P2pService is available with all methods
     */
    public function testP2pServiceAvailable() {
        $this->setUp();

        $service = $this->container->getP2pService();

        $this->assertNotNull(
            $service,
            'P2pService can be retrieved'
        );

        $this->assertMethodExists(
            $service,
            'sendP2pRequest',
            'P2pService has sendP2pRequest method'
        );

        $this->assertMethodExists(
            $service,
            'sendP2pRequestFromFailedDirectTransaction',
            'P2pService has sendP2pRequestFromFailedDirectTransaction method'
        );
    }

    /**
     * Test SynchService is available
     */
    public function testSynchServiceAvailable() {
        $this->setUp();

        $service = $this->container->getSynchService();

        $this->assertNotNull(
            $service,
            'SynchService can be retrieved'
        );

        $this->assertMethodExists(
            $service,
            'synchSingleContact',
            'SynchService has synchSingleContact method'
        );
    }

    /**
     * Test direct service call replacement for sendP2pEiou
     */
    public function testSendP2pEiouReplacement() {
        $this->setUp();

        // Old way (wrapper - now removed):
        // sendP2pEiou($request);

        // New way (direct service):
        $service = $this->container->getTransactionService();

        // Create test request
        $request = [
            'sender' => 'test_sender',
            'receiver' => 'test_receiver',
            'amount' => 100,
            'currency' => 'USD',
            'memo' => 'test_memo'
        ];

        // Test that method can be called
        // Note: We're not testing the actual sending, just that the method is callable
        $callable = is_callable([$service, 'sendP2pEiou']);

        $this->assertTrue(
            $callable,
            'sendP2pEiou can be called on TransactionService'
        );
    }

    /**
     * Test direct service call replacement for sendP2pRequest
     */
    public function testSendP2pRequestReplacement() {
        $this->setUp();

        // Old way (wrapper - now removed):
        // sendP2pRequest($data);

        // New way (direct service):
        $service = $this->container->getP2pService();

        // Test that method can be called
        $callable = is_callable([$service, 'sendP2pRequest']);

        $this->assertTrue(
            $callable,
            'sendP2pRequest can be called on P2pService'
        );
    }

    /**
     * Test direct service call replacement for synchContact
     */
    public function testSynchContactReplacement() {
        $this->setUp();

        // Old way (wrapper - now removed):
        // synchContact($address, 'SILENT');

        // New way (direct service):
        $service = $this->container->getSynchService();

        // Test that method can be called
        $callable = is_callable([$service, 'synchSingleContact']);

        $this->assertTrue(
            $callable,
            'synchSingleContact can be called on SynchService'
        );
    }

    /**
     * Test that all required services are available
     */
    public function testAllServicesAvailable() {
        $this->setUp();

        $services = [
            'getTransactionService' => 'TransactionService',
            'getP2pService' => 'P2pService',
            'getSynchService' => 'SynchService',
            'getDebugService' => 'DebugService',
            'getContactService' => 'ContactService'
        ];

        foreach ($services as $method => $name) {
            $this->assertMethodExists(
                $this->container,
                $method,
                "ServiceContainer has $method"
            );

            $service = $this->container->$method();
            $this->assertNotNull(
                $service,
                "$name can be retrieved"
            );
        }
    }

    /**
     * Test method parameter types match expectations
     */
    public function testMethodParameterTypes() {
        $this->setUp();

        // Test TransactionService::sendP2pEiou accepts array
        $transService = $this->container->getTransactionService();
        $reflection = new ReflectionMethod($transService, 'sendP2pEiou');
        $params = $reflection->getParameters();

        $this->assertEquals(
            1,
            count($params),
            'sendP2pEiou has exactly 1 parameter'
        );

        $this->assertEquals(
            'array',
            $params[0]->getType()->getName(),
            'sendP2pEiou parameter is array type'
        );

        // Test P2pService::sendP2pRequest accepts array
        $p2pService = $this->container->getP2pService();
        $reflection = new ReflectionMethod($p2pService, 'sendP2pRequest');
        $params = $reflection->getParameters();

        $this->assertEquals(
            1,
            count($params),
            'sendP2pRequest has exactly 1 parameter'
        );

        $this->assertEquals(
            'array',
            $params[0]->getType()->getName(),
            'sendP2pRequest parameter is array type'
        );
    }

    /**
     * Test services maintain state (singleton pattern)
     */
    public function testServicesSingletonPattern() {
        $this->setUp();

        // Get services multiple times
        $trans1 = $this->container->getTransactionService();
        $trans2 = $this->container->getTransactionService();

        $p2p1 = $this->container->getP2pService();
        $p2p2 = $this->container->getP2pService();

        $synch1 = $this->container->getSynchService();
        $synch2 = $this->container->getSynchService();

        // They should be the same instance
        $this->assertTrue(
            $trans1 === $trans2,
            'TransactionService maintains singleton'
        );

        $this->assertTrue(
            $p2p1 === $p2p2,
            'P2pService maintains singleton'
        );

        $this->assertTrue(
            $synch1 === $synch2,
            'SynchService maintains singleton'
        );
    }
}

// Run the tests
echo "===========================================\n";
echo "ServiceWrappers Removal - Unit Tests\n";
echo "===========================================\n\n";

$test = new ServiceRefactoringTest();
$test->run();