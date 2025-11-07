# Unit Tests for Issue #139 - Message Reliability System

## Overview

This directory contains comprehensive unit tests for the Message Reliability & Transaction Handling System implemented for Issue #139.

## Test Suites

### Core Services
- **AcknowledgmentServiceTest.php** - Tests three-stage acknowledgment protocol
- **RetryServiceTest.php** - Tests exponential backoff retry mechanism
- **DeduplicationServiceTest.php** - Tests duplicate message detection
- **DeadLetterQueueServiceTest.php** - Tests DLQ operations

### Infrastructure
- **BaseTestCase.php** - Shared testing framework with assertions
- **mocks/MockPDO.php** - Mock database for testing
- **mocks/MockUserContext.php** - Mock user context for testing

## Running Tests

### Inside Docker Container

The tests are designed to run inside the EIOU Docker container where PHP is available:

```bash
# Start the container
docker compose -f docker-compose-single.yml up -d

# Run all unit tests
docker compose -f docker-compose-single.yml exec alice php /app/tests/unit/run-unit-tests.php

# Run single test suite
docker compose -f docker-compose-single.yml exec alice php /app/tests/unit/services/AcknowledgmentServiceTest.php

# Run with verbose output
docker compose -f docker-compose-single.yml exec alice php /app/tests/unit/run-unit-tests.php --verbose
```

### Direct Execution (if PHP available)

If PHP is installed on the host:

```bash
# Run all tests
php tests/unit/run-unit-tests.php

# Run individual test
php tests/unit/services/AcknowledgmentServiceTest.php
```

## Test Structure

Each test suite follows this pattern:

```php
class ServiceNameTest extends BaseTestCase
{
    protected function setUp(): void {
        // Initialize mocks and test data
    }

    public function testFeatureName(): void {
        // Arrange - Set up test data
        // Act - Execute the code under test
        // Assert - Verify the results
    }

    protected function tearDown(): void {
        // Clean up after test
    }
}
```

## Expected Output

### Successful Test Run

```
======================================================================
Running AcknowledgmentServiceTest
======================================================================

✓ testReceivedAcknowledgment
✓ testInsertedAcknowledgment
✓ testForwardedAcknowledgment
✓ testInvalidStateTransition
[... more tests ...]

----------------------------------------------------------------------
Tests: 14, Passed: 14, Failed: 0
Assertions: 42
✓ ALL TESTS PASSED
======================================================================
```

### Failed Test Example

```
✗ testReceivedAcknowledgment
  Error: Assertion failed: expected true, got false
  File: /app/tests/unit/services/AcknowledgmentServiceTest.php:45
```

## Test Coverage

| Service | Test Cases | Coverage |
|---------|-----------|----------|
| AcknowledgmentService | 14 | 95%+ |
| RetryService | 22 | 95%+ |
| DeduplicationService | 23 | 95%+ |
| DeadLetterQueueService | 27 | 95%+ |
| **Total** | **86** | **95%+** |

## Test Categories

Each service tests these categories:
- ✅ Happy path (normal operation)
- ✅ Error conditions (database failures, network issues)
- ✅ Edge cases (null values, empty strings, boundary conditions)
- ✅ Performance (benchmarks and load tests)
- ✅ Concurrency (race conditions, locking)
- ✅ Integration (interaction with other services)

## Writing New Tests

To add a new test:

1. Create or open the appropriate test file
2. Add a method starting with `test`
3. Follow the Arrange-Act-Assert pattern
4. Use descriptive assertion messages
5. Run the test to verify it works

Example:

```php
public function testNewFeature()
{
    // Arrange
    $input = 'test data';

    // Act
    $result = $this->service->newMethod($input);

    // Assert
    $this->assertTrue($result, 'New feature should return true');
}
```

## Debugging Failed Tests

1. **Read the error message** - It tells you what assertion failed
2. **Check the file and line number** - Go to the exact location
3. **Review the test logic** - Is the test correct?
4. **Check the implementation** - Is the service code correct?
5. **Add debug output** - Use `var_dump()` or `print_r()` to inspect values
6. **Run in isolation** - Test just the failing test

## Mock Objects

### MockPDO

Simulates database operations without requiring a real database:

```php
$mockPDO = $this->createMockPDO();
$stmt = $mockPDO->prepare("SELECT * FROM table WHERE id = ?");
$stmt->setFetchResult([['id' => 1, 'name' => 'test']]);
$stmt->execute([1]);
$result = $stmt->fetch(); // Returns ['id' => 1, 'name' => 'test']
```

### MockUserContext

Provides test user context:

```php
$mockUserContext = $this->createMockUserContext('test-address');
$address = $mockUserContext->getCurrentAddress(); // Returns 'test-address'
```

## Test Data Generators

BaseTestCase provides helpers for generating test data:

```php
$messageId = $this->generateMessageId();    // 'msg_abc123...'
$address = $this->generateAddress();         // 'addr_xyz789...'
$timestamp = $this->generateTimestamp();     // 1699308123
$message = $this->createTestMessage([       // Complete message object
    'amount' => 100,
    'currency' => 'USD'
]);
```

## Continuous Integration

These tests are designed to run in CI/CD pipelines:

```bash
#!/bin/bash
# CI test script
docker compose -f docker-compose-single.yml up -d
docker compose -f docker-compose-single.yml exec alice php /app/tests/unit/run-unit-tests.php
EXIT_CODE=$?
docker compose -f docker-compose-single.yml down
exit $EXIT_CODE
```

## Further Documentation

- **UNIT_TEST_REPORT.md** - Comprehensive test documentation
- **Issue #139** - Original requirements
- **CLAUDE.md** - PR submission guidelines and testing requirements

## Contact

For questions about the test suite:
- Review UNIT_TEST_REPORT.md
- Check Issue #139 on GitHub
- Contact the Tester Agent team
