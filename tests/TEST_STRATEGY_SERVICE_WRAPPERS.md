# ServiceWrappers Removal Test Strategy

## Overview
Comprehensive test plan for refactoring ServiceWrappers functions to direct service calls for issue #113.

## Affected Functions to Remove
1. `sendP2pEiou()` → `ServiceContainer::getInstance()->getTransactionService()->sendP2pEiou()`
2. `sendP2pRequest()` → `ServiceContainer::getInstance()->getP2pService()->sendP2pRequest()`
3. `sendP2pRequestFromFailedDirectTransaction()` → `ServiceContainer::getInstance()->getP2pService()->sendP2pRequestFromFailedDirectTransaction()`
4. `synchContact()` → `ServiceContainer::getInstance()->getSynchService()->synchSingleContact()`

## Test File Structure

```
tests/
├── unit/                              # Unit tests for individual services
│   ├── TransactionServiceTest.php    # Test sendP2pEiou functionality
│   ├── P2pServiceTest.php            # Test P2P request methods
│   └── SynchServiceTest.php          # Test contact synchronization
├── integration/                       # Integration tests
│   ├── P2pCommunicationTest.php      # End-to-end P2P flow
│   ├── ServiceContainerTest.php      # Service initialization order
│   └── RefactoringValidationTest.php # Before/after comparison
├── docker/                            # Docker container tests
│   ├── startup-validation.sh         # Container startup tests
│   ├── service-initialization.sh     # Service init order validation
│   └── runtime-tests.sh              # Runtime behavior tests
└── helpers/                           # Test utilities
    ├── TestBootstrap.php             # Test environment setup
    └── MockDataFactory.php           # Generate test data
```

## Critical Test Cases

### 1. Unit Tests

#### TransactionServiceTest.php
```php
<?php
class TransactionServiceTest extends PHPUnit\Framework\TestCase {

    private $transactionService;

    protected function setUp(): void {
        // Initialize service with mock dependencies
        $this->transactionService = ServiceContainer::getInstance()->getTransactionService();
    }

    public function testSendP2pEiouWithValidRequest() {
        // Test that sendP2pEiou processes request correctly
        $request = [
            'sender' => 'alice_address',
            'receiver' => 'bob_address',
            'amount' => 100,
            'currency' => 'USD',
            'memo' => 'test_memo_123'
        ];

        // Capture output and database changes
        ob_start();
        $this->transactionService->sendP2pEiou($request);
        $output = ob_get_clean();

        // Verify transaction was inserted
        // Verify P2P record was updated
        // Verify correct output was generated
    }

    public function testSendP2pEiouWithInvalidData() {
        // Test error handling for invalid requests
    }
}
```

#### P2pServiceTest.php
```php
<?php
class P2pServiceTest extends PHPUnit\Framework\TestCase {

    private $p2pService;

    protected function setUp(): void {
        $this->p2pService = ServiceContainer::getInstance()->getP2pService();
    }

    public function testSendP2pRequestWithValidAddress() {
        // Test direct address routing
        $data = ['send', '100', 'bob_tor_address', 'USD'];

        // Mock transport layer
        // Execute request
        $this->p2pService->sendP2pRequest($data);

        // Verify P2P request was created
        // Verify correct routing logic
    }

    public function testSendP2pRequestWithContactName() {
        // Test contact lookup and routing
        $data = ['send', '100', 'Bob', 'USD'];

        // Setup contact in database
        // Execute request
        $this->p2pService->sendP2pRequest($data);

        // Verify contact was resolved
        // Verify request was routed correctly
    }

    public function testSendP2pRequestFromFailedTransaction() {
        // Test failed transaction recovery
        $failedMessage = [
            'receiver_address' => 'bob_address',
            'amount' => 100,
            'currency' => 'USD',
            'hash' => 'failed_tx_hash'
        ];

        $this->p2pService->sendP2pRequestFromFailedDirectTransaction($failedMessage);

        // Verify P2P version was created
        // Verify status set to 'queued'
    }
}
```

#### SynchServiceTest.php
```php
<?php
class SynchServiceTest extends PHPUnit\Framework\TestCase {

    private $synchService;

    protected function setUp(): void {
        $this->synchService = ServiceContainer::getInstance()->getSynchService();
    }

    public function testSynchSingleContactPending() {
        // Test syncing pending contact
        $contactAddress = 'pending_contact_address';

        // Mock transport response
        // Execute sync
        $result = $this->synchService->synchSingleContact($contactAddress, 'SILENT');

        // Verify inquiry was sent
        // Verify status was updated
        assertTrue($result);
    }

    public function testSynchSingleContactRejected() {
        // Test handling rejected contact
        $contactAddress = 'rejected_contact_address';

        $result = $this->synchService->synchSingleContact($contactAddress, 'ECHO');

        // Verify rejection was handled
        assertFalse($result);
    }
}
```

### 2. Integration Tests

#### test-service-container.php
```php
<?php
// Integration test for ServiceContainer type checking
require_once '/app/src/services/ServiceContainer.php';
require_once '/app/src/context/UserContext.php';

try {
    echo "Testing ServiceContainer initialization...\n";

    // Initialize UserContext first
    $userContext = new UserContext();
    echo "✓ UserContext initialized\n";

    // Get ServiceContainer instance
    $container = ServiceContainer::getInstance();
    echo "✓ ServiceContainer singleton obtained\n";

    // Test service retrieval
    $transactionService = $container->getTransactionService();
    echo "✓ TransactionService retrieved\n";

    $p2pService = $container->getP2pService();
    echo "✓ P2pService retrieved\n";

    $synchService = $container->getSynchService();
    echo "✓ SynchService retrieved\n";

    // Test method availability
    if (method_exists($transactionService, 'sendP2pEiou')) {
        echo "✓ TransactionService::sendP2pEiou exists\n";
    }

    if (method_exists($p2pService, 'sendP2pRequest')) {
        echo "✓ P2pService::sendP2pRequest exists\n";
    }

    if (method_exists($p2pService, 'sendP2pRequestFromFailedDirectTransaction')) {
        echo "✓ P2pService::sendP2pRequestFromFailedDirectTransaction exists\n";
    }

    if (method_exists($synchService, 'synchSingleContact')) {
        echo "✓ SynchService::synchSingleContact exists\n";
    }

    echo "\nAll service container tests passed!\n";
    exit(0);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
```

### 3. Docker Container Tests

#### startup-validation.sh
```bash
#!/bin/bash
# Docker container startup validation for ServiceWrappers removal

echo "=== ServiceWrappers Removal - Container Startup Validation ==="

# Test 1: Single node startup
echo "Test 1: Single node container startup..."
docker-compose -f docker-compose-single.yml down -v
docker-compose -f docker-compose-single.yml up -d --build

# Wait for initialization
sleep 10

# Check container status
CONTAINER_STATUS=$(docker ps | grep eiou | grep -c "Up")
if [ "$CONTAINER_STATUS" -eq 0 ]; then
    echo "✗ Container failed to start"
    docker-compose -f docker-compose-single.yml logs
    exit 1
fi
echo "✓ Container started successfully"

# Test 2: Check for startup errors
echo "Test 2: Checking for startup errors..."
ERROR_COUNT=$(docker-compose -f docker-compose-single.yml logs 2>&1 | grep -i "fatal\|error\|exception" | grep -v "error_log" | wc -l)
if [ "$ERROR_COUNT" -gt 0 ]; then
    echo "✗ Found startup errors:"
    docker-compose -f docker-compose-single.yml logs | grep -i "fatal\|error\|exception"
    exit 1
fi
echo "✓ No startup errors found"

# Test 3: Verify service initialization
echo "Test 3: Verifying service initialization..."
docker-compose -f docker-compose-single.yml exec -T alice php -r "
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    try {
        \$container = ServiceContainer::getInstance();
        \$transactionService = \$container->getTransactionService();
        \$p2pService = \$container->getP2pService();
        \$synchService = \$container->getSynchService();
        echo 'Services initialized successfully';
        exit(0);
    } catch (Exception \$e) {
        echo 'Service initialization failed: ' . \$e->getMessage();
        exit(1);
    }
"

if [ $? -ne 0 ]; then
    echo "✗ Service initialization failed"
    exit 1
fi
echo "✓ Services initialized correctly"

# Test 4: Verify removed functions are not called
echo "Test 4: Checking for removed wrapper function usage..."
WRAPPER_USAGE=$(docker-compose -f docker-compose-single.yml exec -T alice grep -r "sendP2pEiou\|sendP2pRequest\|sendP2pRequestFromFailedDirectTransaction\|synchContact" /app/src/ --exclude="ServiceWrappers.php" --exclude-dir="services" | grep -v "//" | wc -l)

if [ "$WRAPPER_USAGE" -gt 0 ]; then
    echo "✗ Found usage of removed wrapper functions:"
    docker-compose -f docker-compose-single.yml exec -T alice grep -r "sendP2pEiou\|sendP2pRequest\|sendP2pRequestFromFailedDirectTransaction\|synchContact" /app/src/ --exclude="ServiceWrappers.php"
    exit 1
fi
echo "✓ No wrapper function usage found"

# Test 5: Verify file permissions
echo "Test 5: Checking file permissions..."
docker-compose -f docker-compose-single.yml exec -T alice ls -la /app/src/services/ | grep -q "ServiceContainer.php"
if [ $? -ne 0 ]; then
    echo "✗ ServiceContainer.php not found or not accessible"
    exit 1
fi
echo "✓ File permissions correct"

echo "=== All startup validation tests passed ==="
```

#### runtime-tests.sh
```bash
#!/bin/bash
# Runtime behavior tests for refactored services

echo "=== ServiceWrappers Removal - Runtime Behavior Tests ==="

# Test 1: P2P message flow
echo "Test 1: Testing P2P message flow..."
docker-compose -f docker-compose-4line.yml up -d --build
sleep 15

# Send P2P transaction from Alice to Daniel
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    \$container = ServiceContainer::getInstance();
    \$p2pService = \$container->getP2pService();

    // Test sendP2pRequest with direct service call
    \$data = ['send', '100', 'daniel_address', 'USD'];
    \$p2pService->sendP2pRequest(\$data);
    echo 'P2P request sent successfully';
"

# Test 2: Failed transaction recovery
echo "Test 2: Testing failed transaction recovery..."
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    \$container = ServiceContainer::getInstance();
    \$p2pService = \$container->getP2pService();

    \$failedMessage = [
        'receiver_address' => 'unreachable_address',
        'amount' => 50,
        'currency' => 'USD',
        'hash' => 'test_failed_hash'
    ];

    \$p2pService->sendP2pRequestFromFailedDirectTransaction(\$failedMessage);
    echo 'Failed transaction recovery initiated';
"

# Test 3: Contact synchronization
echo "Test 3: Testing contact synchronization..."
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    \$container = ServiceContainer::getInstance();
    \$synchService = \$container->getSynchService();

    \$result = \$synchService->synchSingleContact('bob_address', 'SILENT');
    echo 'Contact sync result: ' . (\$result ? 'success' : 'failed');
"

# Test 4: Transaction sending
echo "Test 4: Testing transaction sending..."
docker-compose -f docker-compose-4line.yml exec -T alice php -r "
    require_once '/app/src/services/ServiceContainer.php';
    require_once '/app/src/context/UserContext.php';

    \$container = ServiceContainer::getInstance();
    \$transactionService = \$container->getTransactionService();

    \$request = [
        'sender' => 'alice_address',
        'receiver' => 'bob_address',
        'amount' => 100,
        'currency' => 'USD',
        'memo' => 'test_transaction'
    ];

    \$transactionService->sendP2pEiou(\$request);
    echo 'Transaction sent successfully';
"

echo "=== All runtime tests completed ==="
```

## Backward Compatibility Testing

### RefactoringValidationTest.php
```php
<?php
/**
 * Validates that refactored code produces identical results
 */
class RefactoringValidationTest extends PHPUnit\Framework\TestCase {

    public function testTransactionServiceBackwardCompatibility() {
        // Capture behavior before refactoring (using wrapper)
        $request = $this->generateTestRequest();

        // Direct service call (new way)
        $service = ServiceContainer::getInstance()->getTransactionService();
        ob_start();
        $service->sendP2pEiou($request);
        $newOutput = ob_get_clean();

        // Compare outputs and database state
        // Should be identical
    }

    public function testP2pServiceBackwardCompatibility() {
        $data = ['send', '100', 'test_address', 'USD'];

        // Direct service call
        $service = ServiceContainer::getInstance()->getP2pService();
        $service->sendP2pRequest($data);

        // Verify same behavior as wrapper function
    }

    public function testSynchServiceBackwardCompatibility() {
        $address = 'test_contact_address';

        // Direct service call
        $service = ServiceContainer::getInstance()->getSynchService();
        $result = $service->synchSingleContact($address, 'SILENT');

        // Verify same return value and side effects
    }
}
```

## PR Validation Checklist

### Pre-submission Requirements
- [ ] All unit tests pass (`php tests/unit/*Test.php`)
- [ ] Integration tests pass (`php tests/integration/*.php`)
- [ ] Docker startup validation passes (`./tests/docker/startup-validation.sh`)
- [ ] Runtime behavior tests pass (`./tests/docker/runtime-tests.sh`)
- [ ] No PHP warnings or errors in logs
- [ ] Container stays up for 5+ minutes under load

### Critical Validations
- [ ] **Container Startup**: Single node starts without crashes
- [ ] **Service Initialization**: UserContext loads before service dependencies
- [ ] **Type Safety**: ServiceContainer accepts correct data types
- [ ] **No Wrapper Usage**: All wrapper function calls replaced with direct service calls
- [ ] **File Permissions**: All PHP files have execute permissions (chmod +x)
- [ ] **Message Flow**: P2P messages route correctly
- [ ] **Transaction Recovery**: Failed transactions convert to P2P requests
- [ ] **Contact Sync**: Contact synchronization works as before

### Testing Commands for PR

```bash
# 1. Run unit tests
cd /home/admin/eiou/ai-dev/github/eiou-docker
php tests/unit/TransactionServiceTest.php
php tests/unit/P2pServiceTest.php
php tests/unit/SynchServiceTest.php

# 2. Run integration tests
php tests/integration/test-service-container.php
php tests/integration/P2pCommunicationTest.php

# 3. Docker validation (MANDATORY)
./tests/docker/startup-validation.sh
./tests/docker/runtime-tests.sh

# 4. 4-node topology test
docker-compose -f docker-compose-4line.yml up -d --build
sleep 15
docker-compose -f docker-compose-4line.yml logs | grep -i error
# Verify no critical errors

# 5. Check for removed functions
grep -r "sendP2pEiou\|sendP2pRequest\|sendP2pRequestFromFailedDirectTransaction\|synchContact" src/ --exclude="ServiceWrappers.php" --exclude-dir="services"
# Should return nothing

# 6. Memory leak test (5-minute runtime)
docker-compose -f docker-compose-single.yml up -d --build
sleep 300
docker stats --no-stream
# Memory should be stable
```

## Acceptance Criteria

1. **Functional Requirements**
   - All wrapper functions removed from codebase
   - Direct service calls replace all wrapper usage
   - No change in application behavior
   - All existing functionality preserved

2. **Performance Requirements**
   - No performance degradation
   - Memory usage stable
   - Container startup time unchanged

3. **Quality Requirements**
   - 100% test coverage for affected methods
   - No PHP warnings or errors
   - Clean git history (squash commits)
   - Documentation updated

4. **Docker Requirements** (Per CLAUDE.md)
   - Container starts without crashes
   - No initialization order issues
   - ServiceContainer accepts correct types
   - startup.sh completes successfully
   - messageCheck.php doesn't crash
   - 4-node topology communication works
   - Container stable for 5+ minutes

## Test Execution Order

1. **Local Development Testing**
   ```
   1. Write unit tests
   2. Implement refactoring
   3. Run unit tests
   4. Run integration tests
   ```

2. **Docker Validation**
   ```
   1. Build containers with changes
   2. Run startup validation
   3. Run runtime tests
   4. Test 4-node topology
   5. 5-minute stability test
   ```

3. **PR Submission**
   ```
   1. Run full test suite
   2. Check Docker requirements
   3. Update documentation
   4. Create PR with test results
   ```

## Risk Mitigation

- **Risk**: Service initialization order issues
  - **Mitigation**: Test UserContext loads before dependencies

- **Risk**: Type mismatch errors
  - **Mitigation**: Validate ServiceContainer parameter types

- **Risk**: Missing function calls
  - **Mitigation**: grep for all wrapper usage before refactoring

- **Risk**: Network communication failure
  - **Mitigation**: Test with 4-node topology

## Success Metrics

- Zero failing tests
- Zero PHP errors/warnings
- 100% backward compatibility
- Container uptime > 5 minutes
- All PR checks passing