# Testing Guide

This document describes how to run tests for the EIOU Docker node.

## Quick Start

```bash
cd eiou-docker/files

# Install dependencies (first time)
composer install

# Run all tests
composer test

# Run with verbose output (shows test names)
composer test-verbose

# Debug mode (stops on first failure)
composer test-debug
```

## Test Types

### Unit Tests (PHPUnit)

Unit tests validate individual PHP classes and methods in isolation.

- **Location**: `tests/Unit/`
- **Framework**: PHPUnit 11
- **Total**: 2900+ tests, 5500+ assertions

### Integration Tests (Shell)

Integration tests validate the complete system behavior using Docker containers.

- **Location**: `tests/`
- **Runner**: `./run-all-tests.sh`
- **Coverage**: API endpoints, multi-node communication, transaction flows

## Unit Test Inventory

### Security Tests (`tests/Unit/Security/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **BIP39Test.php** | 22 | Mnemonic generation (12/24 words), validation, seed derivation, key pair generation, auth code derivation |
| **KeyEncryptionTest.php** | 9 | AES-256-GCM encryption availability, info, secure clear, error handling |
| **TorKeyDerivationTest.php** | 10 | Ed25519 key derivation, .onion address generation, deterministic keys |

### Utils Tests (`tests/Unit/Utils/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **InputValidatorTest.php** | 40+ | All 18 validation methods: amount, currency, address, txid, contact name, fee percent, credit limit, public key, signature, memo, etc. |
| **SecurityTest.php** | 30 | XSS prevention (htmlEncode, jsEncode), input sanitization, password hashing/verification, CSRF tokens, email/URL/IP validation, filename sanitization, timing-safe comparison |
| **AddressValidatorTest.php** | 20 | HTTP/HTTPS/Tor address detection, transport type identification, address categorization |
| **SecureLoggerTest.php** | 18 | Sensitive data masking (passwords, authcodes, API keys, emails, credit cards, SSN, mnemonics), log levels, file rotation |
| **AdaptivePollerTest.php** | 17 | Polling interval calculation, state management, reset, force interval bounds clamping |
| **SecureSeedphraseDisplayTest.php** | 30+ | Secure file display, availability check, TTL, cleanup |

### API Tests (`tests/Unit/Api/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ApiControllerTest.php** | 41 | API endpoint routing, authentication, error handling, all wallet/contacts/system endpoints |

### Core Tests (`tests/Unit/Core/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ErrorCodesTest.php** | 20 | HTTP status mapping, error titles, code validation, constant verification |
| **ConstantsTest.php** | 43 | Application constants validation, hash algorithms, transport modes, status codes |
| **ApplicationTest.php** | 26 | Singleton pattern, service delegation, path getters, CLI mode |
| **DatabaseContextTest.php** | 29 | Config management, DB credentials, initialization state |
| **ErrorHandlerTest.php** | 30 | Error/exception handling, responses, request ID management |
| **UserContextTest.php** | 46 | User data, addresses, wallet validation, config defaults |
| **WalletTest.php** | 19 | Seed extraction, config defaults, hostname validation |

### Exceptions Tests (`tests/Unit/Exceptions/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ServiceExceptionTest.php** | 41 | Service exception hierarchy (ServiceException, FatalServiceException, RecoverableServiceException, ValidationServiceException) |

### Database Tests (`tests/Unit/Database/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **DatabaseSchemaTest.php** | 67 | Schema validation for all 14 tables, column types, constraints, indexes |
| **DatabaseSetupTest.php** | 15+ | Migration execution, column migrations, idempotency |
| **PdoConnectionTest.php** | 10+ | Connection creation, DSN format, PDO options |

### Processors Tests (`tests/Unit/Processors/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **AbstractMessageProcessorTest.php** | 30+ | Base processor, signal handling, lockfile management, shutdown |
| **CleanupMessageProcessorTest.php** | 15+ | Cleanup message handling, log intervals, polling config |
| **ContactStatusProcessorTest.php** | 35+ | Ping/pong, address priority (Tor > HTTPS > HTTP), chain validation |
| **P2pMessageProcessorTest.php** | 20+ | P2P message queue processing, fast polling config |
| **TransactionMessageProcessorTest.php** | 20+ | Transaction processing, lockfile paths |

### Repositories Tests (`tests/Unit/Repositories/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **TransactionRepositoryTest.php** | 4 | Transaction status constants, type constants, hash length validation |
| **AbstractRepositoryTest.php** | 30+ | Base CRUD operations, column validation, transactions, JSON decoding |
| **AddressRepositoryTest.php** | 25+ | Address management, lookups, pubkey hashing, transport types |
| **ApiKeyRepositoryTest.php** | 25+ | API key CRUD, permission checks, rate limit logging |
| **BalanceRepositoryTest.php** | 30+ | Balance operations, sent/received tracking, currency grouping |
| **ContactRepositoryTest.php** | 40+ | Contact management, status transitions, lookups |
| **DeadLetterQueueRepositoryTest.php** | 25+ | DLQ operations, status transitions, statistics |
| **DebugRepositoryTest.php** | 20+ | Debug logging, pruning, log levels |
| **DeliveryMetricsRepositoryTest.php** | 25+ | Delivery metrics tracking, aggregation, cleanup |
| **HeldTransactionRepositoryTest.php** | 44 | Held transaction lifecycle, sync status, retry management |
| **MessageDeliveryRepositoryTest.php** | 38 | Message delivery tracking, retry queue, statistics |
| **P2pRepositoryTest.php** | 48 | P2P request management, status updates, statistics |
| **RateLimiterRepositoryTest.php** | 30 | Rate limiting operations, blocking, cleanup |
| **Rp2pRepositoryTest.php** | 25+ | RP2P request management, queries, cleanup |
| **TransactionChainRepositoryTest.php** | 20+ | Chain integrity verification, gap detection |
| **TransactionContactRepositoryTest.php** | 15+ | Contact transaction queries, balance calculation |
| **TransactionRecoveryRepositoryTest.php** | 20+ | Recovery operations, stuck transactions, claiming |
| **TransactionStatisticsRepositoryTest.php** | 20+ | Transaction statistics, daily counts, type grouping |

### Services Tests (`tests/Unit/Services/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **TransactionServiceTest.php** | 4 | Txid generation algorithm, SHA-256 hashing, determinism |
| **ContactServiceTest.php** | 4 | Contact status constants, name length limits, default settings, online status |
| **ApiAuthServiceTest.php** | 14 | HMAC-SHA256 signature generation, string-to-sign building, request header parsing, client IP detection |
| **RateLimiterServiceTest.php** | 7 | Rate limiting logic, client IP detection (Cloudflare, X-Forwarded-For), test mode bypass |
| **BalanceServiceTest.php** | 23 | Contact balance conversion, user total balance, contact balance retrieval, batch balance operations, currency conversion, edge cases |
| **DatabaseLockingServiceTest.php** | 40 | MySQL advisory locks (GET_LOCK/RELEASE_LOCK/IS_FREE_LOCK), lock acquisition/release, timeout handling, lock name sanitization, held locks tracking |
| **ChainOperationsServiceTest.php** | 16 | Chain integrity verification, previous txid lookup, chain repair coordination, sync service injection, exception handling |
| **ChainVerificationServiceTest.php** | 16 | Chain verification logic, gap detection, conflict resolution |
| **HeldTransactionServiceTest.php** | 23 | Transaction hold/resume lifecycle, sync status tracking, previous txid updates, statistics, event handling, chain integrity checks |
| **TransactionRecoveryServiceTest.php** | 28 | Stuck transaction recovery, manual resolution (retry/cancel/complete), recovery statistics, exception handling |
| **TransactionValidationServiceTest.php** | 35 | Transaction validation logic, required fields, amount validation |
| **BackupServiceTest.php** | 22 | formatBytes utility, getNextScheduledBackup date logic, boundary conditions |
| **ApiKeyServiceTest.php** | 44 | CLI API key management, permission validation |
| **CleanupServiceTest.php** | 23 | Expired message processing, cleanup scheduling |
| **ContactStatusServiceTest.php** | 27 | Ping/pong handling, contact status updates |
| **MessageDeliveryServiceTest.php** | 55 | Message delivery with retries, dead letter queue |
| **WalletServiceTest.php** | 22 | Wallet key operations, key detection |
| **CliServiceTest.php** | 25+ | CLI command handling, output formatting |
| **DebugServiceTest.php** | 15+ | Debug context, error logging setup |
| **MessageServiceTest.php** | 25+ | Message processing, validation, routing |
| **P2pServiceTest.php** | 30+ | P2P routing logic, fund availability, matching |
| **Rp2pServiceTest.php** | 25+ | RP2P relay logic, fee calculation |
| **SendOperationServiceTest.php** | 20+ | Send operations with locking, message delivery |
| **ServiceContainerTest.php** | 20+ | Singleton pattern, dependency management, lazy loading |
| **SyncServiceTest.php** | 20+ | Synchronization operations, contact/transaction sync |
| **TransactionProcessingServiceTest.php** | 20+ | Transaction processing, claiming, P2P handling |

### Services Proxies Tests (`tests/Unit/Services/Proxies/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **SyncServiceProxyTest.php** | 15+ | Lazy proxy pattern, deferred initialization |

### Services Utilities Tests (`tests/Unit/Services/Utilities/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **CurrencyUtilityServiceTest.php** | 15 | Cents/dollars conversion, currency formatting, fee percent calculations, rounding, large amounts |
| **TimeUtilityServiceTest.php** | 11 | Microtime conversion, expiration checking, TTL calculations, timestamp precision |
| **GeneralUtilityServiceTest.php** | 15+ | Address truncation, string manipulation |
| **TransportUtilityServiceTest.php** | 25+ | Transport detection, address types, jitter function |
| **UtilityServiceContainerTest.php** | 15+ | Lazy loading container, utility caching |
| **ValidationUtilityServiceTest.php** | 20+ | Request validation, signature verification, funds calculation |

### Service Wrappers Tests (`tests/Unit/Services/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ServiceWrappersTest.php** | 10+ | Output wrapper function, message handling |

### Startup Tests (`tests/Unit/Startup/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ConfigCheckTest.php** | 15+ | Userconfig validation, public key detection |
| **MessageCheckTest.php** | 15+ | Database prerequisite checks, PDO availability |

### CLI Tests (`tests/Unit/Cli/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **CliJsonResponseTest.php** | 24 | RFC 9457 compliant JSON responses, success/error structure, validation errors, pagination, table formatting, transaction responses |
| **CliOutputManagerTest.php** | 20 | Singleton pattern, JSON mode flag parsing, cleanArgv argument filtering, command parsing, fluent interface |

### Events Tests (`tests/Unit/Events/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **EventDispatcherTest.php** | 20 | Singleton pattern, event subscription/unsubscription, listener invocation order, exception handling, listener management |
| **SyncEventsTest.php** | 18 | Sync event constants verification, naming convention compliance, string type validation, reflection-based constant enumeration |

### Formatters Tests (`tests/Unit/Formatters/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **TransactionFormatterTest.php** | 14 | Amount conversion (cents to dollars), transaction history formatting, counterparty detection, contact formatting |

### GUI Tests (`tests/Unit/Gui/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **FunctionsTest.php** | 20+ | Router, view data initialization, XSS prevention, action routing |
| **Includes/SessionTest.php** | 40+ | Authentication, CSRF tokens, flash messages, session timeout |

### GUI Helpers Tests (`tests/Unit/Gui/Helpers/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ContactDataBuilderTest.php** | 20 | Contact data building, address type handling, primary address priority (Tor > HTTPS > HTTP), JSON encoding, HTML-safe output, status handling, Unicode support |
| **MessageHelperTest.php** | 62 | Message parsing, formatting, HTML encoding |
| **ViewHelperTest.php** | 54 | View rendering helpers, template processing |

### GUI Controllers Tests (`tests/Unit/Gui/Controllers/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ContactControllerTest.php** | 25+ | Contact CRUD actions, CSRF verification, validation |
| **SettingsControllerTest.php** | 25+ | Settings management, input validation, JSON export |
| **TransactionControllerTest.php** | 20+ | Transaction actions, recipient handling |

### Schema Tests (`tests/Unit/Schemas/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **OutputSchemaTest.php** | 25+ | Debug/logging output for all message types |

### Schema/Payload Tests (`tests/Unit/Schemas/Payloads/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **BasePayloadTest.php** | 53 | ensureRequiredFields validation, sanitizeString, sanitizeNumber type handling, validate empty check, edge cases |
| **ContactPayloadTest.php** | 26 | Contact creation/received/updated/rejection/pending/mutually-accepted payloads, filterAddresses, JSON encoding |
| **ContactStatusPayloadTest.php** | 53 | Ping/pong payloads, status responses |
| **MessagePayloadTest.php** | 20 | Contact inquiry/accepted/unknown payloads, transaction status/sync responses, P2P status inquiry/response |
| **P2pPayloadTest.php** | 50+ | P2P request payloads, validation |
| **Rp2pPayloadTest.php** | 54 | Return P2P payloads, relay routing |
| **TransactionPayloadTest.php** | 77 | Transaction payloads, all transaction types |
| **UtilPayloadTest.php** | 77 | Utility/error payloads, acknowledgments |

## Running Unit Tests

### Configuration Files

- `tests/phpunit.xml.dist` - PHPUnit 11 configuration template (tracked in git)
- `tests/phpunit.xml` - Local configuration override (in .gitignore)

The `.dist` file is the tracked template. Copy it to `phpunit.xml` for local customization:
```bash
cp tests/phpunit.xml.dist tests/phpunit.xml
```

### Composer Commands

```bash
cd eiou-docker/files

# Run all tests
composer test

# Verbose output with readable test names
composer test-verbose

# Debug mode - stops on first failure
composer test-debug

# With coverage report (requires Xdebug/PCOV)
composer test-coverage

# Pass custom PHPUnit flags
composer test -- --filter=BIP39
composer test -- --stop-on-failure -v
composer test -- --testdox --group=security
```

### Using Docker (No Local PHP Required)

```bash
cd eiou-docker

# Install dependencies
docker run --rm -v "$(pwd)":/app -w /app/files composer:latest install

# Run all tests
docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli ./files/vendor/bin/phpunit --configuration tests/phpunit.xml

# Run specific test file
docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli ./files/vendor/bin/phpunit tests/Unit/Security/BIP39Test.php

# Run with verbose output
docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli ./files/vendor/bin/phpunit --configuration tests/phpunit.xml --testdox
```

### Running Specific Tests

```bash
# Run a single test file
composer test -- tests/Unit/Utils/InputValidatorTest.php

# Run tests matching a pattern
composer test -- --filter=testValidateAmount

# Run a specific test class
composer test -- --filter=BIP39Test

# Run tests in a directory
composer test -- tests/Unit/Security/
```

## Running Integration Tests

```bash
cd eiou-docker/tests

# Run all integration tests against 4-node topology
./run-all-tests.sh http4

# Run specific test suite
./run-all-tests.sh http4 transactions

# View available test suites
./run-all-tests.sh --help
```

## Test Structure

```
tests/
├── bootstrap.php              # PHPUnit bootstrap - sets up test environment:
│   │                          #   - EIOU_TEST_MODE constant (true during tests)
│   │                          #   - PSR-4 namespace Eiou\Tests\ autoloading
│   │                          #   - Mocked output() function for suppressing logs
├── phpunit.xml.dist           # PHPUnit configuration template (tracked in git)
├── run-all-tests.sh           # Integration test runner
├── Unit/                      # PHPUnit unit tests
│   ├── Cli/
│   │   ├── CliJsonResponseTest.php
│   │   └── CliOutputManagerTest.php
│   ├── Core/
│   │   ├── ConstantsTest.php
│   │   └── ErrorCodesTest.php
│   ├── Database/
│   │   └── DatabaseSchemaTest.php
│   ├── Events/
│   │   ├── EventDispatcherTest.php
│   │   └── SyncEventsTest.php
│   ├── Exceptions/
│   │   └── ServiceExceptionTest.php
│   ├── Formatters/
│   │   └── TransactionFormatterTest.php
│   ├── Gui/
│   │   ├── Controllers/
│   │   │   ├── ContactControllerTest.php
│   │   │   ├── SettingsControllerTest.php
│   │   │   └── TransactionControllerTest.php
│   │   └── Helpers/
│   │       ├── ContactDataBuilderTest.php
│   │       ├── MessageHelperTest.php
│   │       └── ViewHelperTest.php
│   ├── Repositories/
│   │   ├── AbstractRepositoryTest.php
│   │   ├── AddressRepositoryTest.php
│   │   ├── ApiKeyRepositoryTest.php
│   │   ├── BalanceRepositoryTest.php
│   │   ├── ContactRepositoryTest.php
│   │   ├── DeadLetterQueueRepositoryTest.php
│   │   ├── DebugRepositoryTest.php
│   │   ├── DeliveryMetricsRepositoryTest.php
│   │   ├── HeldTransactionRepositoryTest.php
│   │   ├── MessageDeliveryRepositoryTest.php
│   │   ├── P2pRepositoryTest.php
│   │   ├── RateLimiterRepositoryTest.php
│   │   ├── Rp2pRepositoryTest.php
│   │   ├── TransactionChainRepositoryTest.php
│   │   ├── TransactionContactRepositoryTest.php
│   │   ├── TransactionRecoveryRepositoryTest.php
│   │   ├── TransactionRepositoryTest.php
│   │   └── TransactionStatisticsRepositoryTest.php
│   ├── Schemas/
│   │   └── Payloads/
│   │       ├── BasePayloadTest.php
│   │       ├── ContactPayloadTest.php
│   │       ├── ContactStatusPayloadTest.php
│   │       ├── MessagePayloadTest.php
│   │       ├── P2pPayloadTest.php
│   │       ├── Rp2pPayloadTest.php
│   │       ├── TransactionPayloadTest.php
│   │       └── UtilPayloadTest.php
│   ├── Security/
│   │   ├── BIP39Test.php
│   │   ├── KeyEncryptionTest.php
│   │   └── TorKeyDerivationTest.php
│   ├── Services/
│   │   ├── ApiAuthServiceTest.php
│   │   ├── ApiKeyServiceTest.php
│   │   ├── BackupServiceTest.php
│   │   ├── BalanceServiceTest.php
│   │   ├── ChainOperationsServiceTest.php
│   │   ├── ChainVerificationServiceTest.php
│   │   ├── CleanupServiceTest.php
│   │   ├── CliServiceTest.php
│   │   ├── ContactServiceTest.php
│   │   ├── ContactStatusServiceTest.php
│   │   ├── DatabaseLockingServiceTest.php
│   │   ├── DebugServiceTest.php
│   │   ├── HeldTransactionServiceTest.php
│   │   ├── MessageDeliveryServiceTest.php
│   │   ├── MessageServiceTest.php
│   │   ├── P2pServiceTest.php
│   │   ├── RateLimiterServiceTest.php
│   │   ├── Rp2pServiceTest.php
│   │   ├── SendOperationServiceTest.php
│   │   ├── ServiceContainerTest.php
│   │   ├── SyncServiceTest.php
│   │   ├── TransactionProcessingServiceTest.php
│   │   ├── TransactionRecoveryServiceTest.php
│   │   ├── TransactionServiceTest.php
│   │   ├── TransactionValidationServiceTest.php
│   │   ├── WalletServiceTest.php
│   │   ├── Proxies/
│   │   │   └── SyncServiceProxyTest.php
│   │   └── Utilities/
│   │       ├── CurrencyUtilityServiceTest.php
│   │       └── TimeUtilityServiceTest.php
│   └── Utils/
│       ├── AddressValidatorTest.php
│       ├── AdaptivePollerTest.php
│       ├── InputValidatorTest.php
│       ├── SecureLoggerTest.php
│       └── SecurityTest.php
└── ...                        # Integration test scripts
```

## Writing New Tests

### Unit Test Template

```php
<?php
/**
 * Unit Tests for YourClass
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\YourClass;

#[CoversClass(YourClass::class)]
class YourClassTest extends TestCase
{
    /**
     * Test description of what is being tested
     */
    public function testMethodNameWithScenario(): void
    {
        // Arrange
        $input = 'test-input';

        // Act
        $result = YourClass::doSomething($input);

        // Assert
        $this->assertEquals('expected', $result);
    }

    /**
     * Test error handling
     */
    public function testMethodThrowsOnInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected error message');

        YourClass::doSomething('invalid');
    }
}
```

### Test Naming Conventions

- **Test classes**: `{ClassName}Test.php`
- **Test methods**: `test{MethodName}{Scenario}`
  - `testValidateAmountWithPositiveValue`
  - `testValidateAmountWithNegativeValue`
  - `testValidateAmountThrowsOnInvalidInput`
- Use descriptive names that explain what is being tested

### Common Assertions

```php
// Equality
$this->assertEquals($expected, $actual);
$this->assertSame($expected, $actual);  // Strict type comparison
$this->assertNotEquals($a, $b);

// Boolean
$this->assertTrue($value);
$this->assertFalse($value);

// Types
$this->assertIsString($value);
$this->assertIsArray($value);
$this->assertIsInt($value);
$this->assertIsBool($value);

// Strings
$this->assertStringContainsString('needle', $haystack);
$this->assertStringStartsWith('prefix', $string);
$this->assertMatchesRegularExpression('/pattern/', $string);

// Arrays
$this->assertArrayHasKey('key', $array);
$this->assertCount(3, $array);
$this->assertContains($value, $array);

// Null
$this->assertNull($value);
$this->assertNotNull($value);

// Exceptions
$this->expectException(ExceptionClass::class);
$this->expectExceptionMessage('message');
```

## Prerequisites

### Local PHP Setup

**Ubuntu/Debian:**
```bash
sudo apt-get install php8.3-cli php8.3-xml php8.3-mbstring php8.3-sodium
```

**macOS (Homebrew):**
```bash
brew install php  # Includes all required extensions
```

**Windows:**
Ensure these extensions are enabled in `php.ini`:
- `extension=dom`
- `extension=mbstring`
- `extension=xml`
- `extension=sodium`

### Required Extensions

| Extension | Required For |
|-----------|--------------|
| `dom` | PHPUnit XML parsing |
| `mbstring` | String handling |
| `sodium` | TorKeyDerivation tests |
| `openssl` | KeyEncryption, BIP39 tests |

## Troubleshooting

### "Composer autoloader not found"

```bash
cd files
composer install
```

### "ext-dom is missing"

```bash
# Ubuntu/Debian
sudo apt-get install php8.3-xml

# macOS
brew reinstall php
```

### "Class not found" errors

```bash
cd files
composer dump-autoload
```

### Tests fail with "Sodium extension required"

```bash
# Ubuntu/Debian
sudo apt-get install php8.3-sodium

# macOS (usually included)
brew reinstall php
```

### Docker permission errors

```bash
# Add user to docker group
sudo usermod -aG docker $USER
# Log out and back in
```

### See detailed error output

```bash
# Show full error details
composer test-debug

# Or with PHPUnit flags
composer test -- --stop-on-failure -v
```

## Continuous Integration

Unit tests run automatically on:
- Pull request creation
- Push to feature branches
- Merge to main branch

**Requirement**: All tests must pass before merging PRs.
