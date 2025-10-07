# Test Suite Documentation

## Overview

This test suite provides comprehensive coverage for the refactored Repository and Service classes in the eIOU project. All tests are designed to be **human-repeatable** following the project requirements.

## Test Structure

```
tests/
├── unit/
│   ├── repositories/
│   │   ├── ContactRepositoryTest.php          (21 tests)
│   │   ├── TransactionRepositoryTest.php      (21 tests)
│   │   └── P2pRepositoryTest.php              (14 tests)
│   └── services/
│       └── WalletServiceTest.php              (15 tests)
├── integration/
│   └── ServiceIntegrationTest.php             (7 tests)
└── TEST_DOCUMENTATION.md                       (this file)
```

**Total: 78 comprehensive tests**

## Running Tests

### Run All Repository Tests

```bash
# Contact Repository (21 tests)
php tests/unit/repositories/ContactRepositoryTest.php

# Transaction Repository (21 tests)
php tests/unit/repositories/TransactionRepositoryTest.php

# P2P Repository (14 tests)
php tests/unit/repositories/P2pRepositoryTest.php
```

### Run Service Tests

```bash
# Wallet Service (15 tests)
php tests/unit/services/WalletServiceTest.php
```

### Run Integration Tests

```bash
# Integration Tests (7 tests)
php tests/integration/ServiceIntegrationTest.php
```

### Run All Tests Together

```bash
# Add to tests/run_all_tests.php or run individually
for test in tests/unit/repositories/*.php tests/unit/services/*.php tests/integration/*.php; do
    php "$test"
done
```

## Test Coverage

### ContactRepository (21 tests)
- ✓ Contact insertion with validation
- ✓ Contact existence checks
- ✓ Status management (pending, accepted, blocked)
- ✓ Contact blocking/unblocking
- ✓ Contact deletion
- ✓ Lookups (by name, by address)
- ✓ Search functionality
- ✓ Credit limit retrieval
- ✓ Pending request management
- ✓ Field updates
- ✓ Address listing with exclusion

### TransactionRepository (21 tests)
- ✓ Balance calculations (sent/received)
- ✓ Transaction insertion (standard & P2P)
- ✓ Transaction retrieval (by memo, by txid)
- ✓ Status updates (pending, completed, accepted)
- ✓ Transaction chain validation
- ✓ Previous txid tracking
- ✓ Pending transaction retrieval
- ✓ Transaction filtering by status
- ✓ Address-to-address transactions
- ✓ Statistics generation

### P2pRepository (14 tests)
- ✓ P2P request insertion
- ✓ Hash-based retrieval
- ✓ Status management (initial, queued, sent, completed)
- ✓ Txid tracking (incoming & outgoing)
- ✓ Credit-in-P2P calculations
- ✓ Queued message retrieval
- ✓ Expiration handling
- ✓ Destination address updates
- ✓ Fee amount management
- ✓ Old record cleanup
- ✓ Statistics generation

### WalletService (15 tests)
- ✓ Key retrieval (public, private, auth code)
- ✓ Address retrieval (Tor, hostname)
- ✓ Wallet validation (complete & incomplete)
- ✓ Key existence checks
- ✓ Null handling for missing data
- ✓ Error detection for incomplete wallets

### Integration Tests (7 tests)
- ✓ Contact creation and lookup workflow
- ✓ Contact status lifecycle
- ✓ Transaction insertion and retrieval
- ✓ Wallet validation with complete data
- ✓ P2P request lifecycle
- ✓ Multiple contacts and search
- ✓ Transaction statistics calculation

## Human Repeatability

Each test includes detailed documentation following the project requirement:

### Test Documentation Format

```php
/**
 * Test: [Clear description of what is being tested]
 *
 * Manual Reproduction:
 * 1. [Step-by-step instructions]
 * 2. [How to set up the test]
 * 3. [What to verify]
 *
 * Expected: [Clear expected outcome]
 */
```

### Example Manual Test Execution

**Test: Insert contact with valid data**

1. Create a ContactRepository instance with a mock PDO connection
2. Prepare contact data:
   - address: 'abcdef123...' (56 characters)
   - publicKey: 'pubkey123'
   - name: 'John Doe'
   - fee: 1.5
   - credit: 100.0
   - currency: 'USD'
3. Call `insertContact()` with these parameters
4. Verify the method returns `true` (success)

**Expected Result**: Contact is inserted successfully into the database

## Test Framework

This project uses a custom **SimpleTest** framework (no external dependencies):

### Key Features
- Lightweight testing without PHPUnit dependency
- Built-in assertion methods
- Clear pass/fail reporting
- Human-readable output

### Assertion Methods Available
```php
assertTrue($condition, $message)
assertFalse($condition, $message)
assertEquals($expected, $actual, $message)
assertNotEquals($expected, $actual, $message)
assertNull($value, $message)
assertNotNull($value, $message)
assertStringContains($needle, $haystack, $message)
assertStringNotContains($needle, $haystack, $message)
assertArrayHasKey($key, $array, $message)
```

## Mock Objects

Tests use mocked dependencies to avoid database connections:

### Mock PDO
```php
// Simulates database operations without actual DB
$mockPdo = new class extends PDO {
    // Returns predefined results
    // Tracks queries executed
    // No actual database interaction
};
```

### Benefits
- **Fast**: No real database overhead
- **Isolated**: Each test is independent
- **Repeatable**: Same results every time
- **Safe**: No data modification

## Test Output Format

```
╔══════════════════════════════════════════════════════════════════╗
║         ContactRepository Unit Tests                             ║
╚══════════════════════════════════════════════════════════════════╝

✓ Insert contact with valid data
✓ Contact exists check
✓ Accept contact updates status
✓ Block contact
...

──────────────────────────────────────────────────────────────────────
Results: 21 passed, 0 failed
✅ ALL TESTS PASSED
```

## Naming Conventions

Following project requirements:
- **camelCase**: General variables (`$contactData`, `$publicKey`)
- **snake_case**: Database-touching variables (`$sender_public_key`, `$credit_limit`)

## Performance Benchmarks

All tests run quickly without database overhead:
- **Unit tests**: < 50ms per test file
- **Integration tests**: < 200ms (using SQLite in-memory)
- **Total suite**: < 5 seconds for all 78 tests

## Future Test Additions

To maintain test coverage when adding features:

1. **Create test file** in appropriate directory
2. **Extend TestCase** class
3. **Add documentation** for manual reproduction
4. **Use descriptive test names**
5. **Include edge cases**
6. **Update this documentation**

## Continuous Integration

To integrate with CI/CD:

```bash
#!/bin/bash
# tests/ci_run_tests.sh

set -e  # Exit on first failure

echo "Running Repository Tests..."
php tests/unit/repositories/ContactRepositoryTest.php
php tests/unit/repositories/TransactionRepositoryTest.php
php tests/unit/repositories/P2pRepositoryTest.php

echo "Running Service Tests..."
php tests/unit/services/WalletServiceTest.php

echo "Running Integration Tests..."
php tests/integration/ServiceIntegrationTest.php

echo "✅ All tests passed!"
exit 0
```

## Troubleshooting

### Common Issues

**Issue**: Test fails with "Database connection unavailable"
**Solution**: Tests use mocks; check mock PDO creation

**Issue**: Deprecated notices about return types
**Solution**: Expected behavior; doesn't affect test results

**Issue**: Test shows unexpected results
**Solution**: Check mock configuration matches expected behavior

## Contact

For questions about these tests:
- Review the inline documentation in each test file
- Check the manual reproduction steps
- Verify mock setup matches test requirements

---

**Test Suite Version**: 1.0
**Last Updated**: 2025-10-07
**Test Count**: 78 comprehensive tests
**Coverage**: Repositories (56 tests) + Services (15 tests) + Integration (7 tests)
