# Testing Guide

This document describes how to run tests for the EIOU Docker node.

## Test Types

### Unit Tests (PHPUnit)

Unit tests validate individual PHP classes and methods in isolation.

- **Location**: `tests/Unit/`
- **Framework**: PHPUnit 11
- **Coverage**: InputValidator, TransactionService, ContactService, TransactionRepository

### Integration Tests (Shell)

Integration tests validate the complete system behavior using Docker containers.

- **Location**: `tests/`
- **Runner**: `./run-all-tests.sh`
- **Coverage**: API endpoints, multi-node communication, transaction flows

## Running Unit Tests

### Recommended: Using Docker

No local PHP setup required. Uses the same PHP version as production.

```bash
cd eiou-docker

# Install dependencies (first time only)
docker run --rm -v "$(pwd)":/app -w /app/files composer:latest install

# Run all unit tests
docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli ./files/vendor/bin/phpunit --configuration tests/phpunit.xml

# Run specific test file
docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli ./files/vendor/bin/phpunit tests/Unit/Utils/InputValidatorTest.php

# Run with verbose output
docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli ./files/vendor/bin/phpunit --configuration tests/phpunit.xml --testdox
```

### Alternative: Local PHP

Requires PHP 8.1+ with required extensions.

#### Prerequisites

**Ubuntu/Debian:**
```bash
sudo apt-get install php8.3-cli php8.3-xml php8.3-mbstring
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

#### Running Tests Locally

```bash
cd eiou-docker/files

# Install dependencies
composer install

# Run tests
composer test

# Run with coverage report (requires Xdebug or PCOV)
composer test-coverage
```

## Running Integration Tests

Integration tests require Docker to spin up test containers.

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
├── bootstrap.php           # PHPUnit bootstrap (autoloader setup)
├── phpunit.xml             # PHPUnit configuration
├── run-all-tests.sh        # Integration test runner
├── Unit/                   # PHPUnit unit tests
│   ├── Utils/
│   │   └── InputValidatorTest.php
│   ├── Services/
│   │   ├── TransactionServiceTest.php
│   │   └── ContactServiceTest.php
│   └── Repositories/
│       └── TransactionRepositoryTest.php
└── ...                     # Integration test scripts
```

## Writing New Tests

### Unit Test Example

```php
<?php
namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\YourClass;

#[CoversClass(YourClass::class)]
class YourClassTest extends TestCase
{
    public function testSomething(): void
    {
        $result = YourClass::doSomething('input');
        $this->assertEquals('expected', $result);
    }
}
```

### Test Naming Conventions

- Test classes: `{ClassName}Test.php`
- Test methods: `test{MethodName}{Scenario}` (e.g., `testValidateAmountWithNegativeValue`)
- Use descriptive names that explain what is being tested

## Continuous Integration

Unit tests run automatically on:
- Pull request creation
- Push to feature branches
- Merge to main branch

Ensure all tests pass before creating a pull request.

## Troubleshooting

### "Composer autoloader not found"

Run `composer install` in the `files/` directory first.

### "ext-dom is missing"

Install the PHP XML extension:
- Ubuntu/Debian: `sudo apt-get install php8.3-xml`
- macOS: `brew reinstall php`

### Tests fail with "Class not found"

Ensure the autoloader is regenerated:
```bash
cd files
composer dump-autoload
```

### Docker permission errors

On Linux, you may need to run Docker commands with proper permissions or add your user to the docker group.
