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
- **Total**: 492 tests, 1000+ assertions

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
| **HeldTransactionServiceTest.php** | 23 | Transaction hold/resume lifecycle, sync status tracking, previous txid updates, statistics, event handling, chain integrity checks |

### Repositories Tests (`tests/Unit/Repositories/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **TransactionRepositoryTest.php** | 4 | Transaction status constants, type constants, hash length validation |

### Core Tests (`tests/Unit/Core/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ErrorCodesTest.php** | 20 | HTTP status mapping, error titles, code validation, constant verification |

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

### Utility Services Tests (`tests/Unit/Services/Utilities/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **CurrencyUtilityServiceTest.php** | 15 | Cents/dollars conversion, currency formatting, fee percent calculations, rounding, large amounts |
| **TimeUtilityServiceTest.php** | 11 | Microtime conversion, expiration checking, TTL calculations, timestamp precision |

### GUI Helpers Tests (`tests/Unit/Gui/Helpers/`)

| Test File | Tests | Coverage |
|-----------|-------|----------|
| **ContactDataBuilderTest.php** | 20 | Contact data building, address type handling, primary address priority (Tor > HTTPS > HTTP), JSON encoding, HTML-safe output, status handling, Unicode support |

## Running Unit Tests

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
├── bootstrap.php              # PHPUnit bootstrap (autoloader setup)
├── phpunit.xml                # PHPUnit configuration
├── run-all-tests.sh           # Integration test runner
├── Unit/                      # PHPUnit unit tests
│   ├── Cli/
│   │   ├── CliJsonResponseTest.php
│   │   └── CliOutputManagerTest.php
│   ├── Core/
│   │   └── ErrorCodesTest.php
│   ├── Events/
│   │   ├── EventDispatcherTest.php
│   │   └── SyncEventsTest.php
│   ├── Formatters/
│   │   └── TransactionFormatterTest.php
│   ├── Gui/
│   │   └── Helpers/
│   │       └── ContactDataBuilderTest.php
│   ├── Repositories/
│   │   └── TransactionRepositoryTest.php
│   ├── Security/
│   │   ├── BIP39Test.php
│   │   ├── KeyEncryptionTest.php
│   │   └── TorKeyDerivationTest.php
│   ├── Services/
│   │   ├── ApiAuthServiceTest.php
│   │   ├── BalanceServiceTest.php
│   │   ├── ChainOperationsServiceTest.php
│   │   ├── ContactServiceTest.php
│   │   ├── DatabaseLockingServiceTest.php
│   │   ├── HeldTransactionServiceTest.php
│   │   ├── RateLimiterServiceTest.php
│   │   ├── TransactionServiceTest.php
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
