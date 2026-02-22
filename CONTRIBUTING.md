# Contributing to EIOU Docker

**Note: EIOU Docker is not currently accepting external contributions.** This document is a template for future use when the project opens to community contributions.

---

Thank you for your interest in contributing to EIOU Docker. This project is in ALPHA status and we welcome contributions that improve reliability, expand test coverage, and strengthen the codebase.

This guide covers everything you need to get started: setting up your development environment, understanding the codebase, writing and running tests, and submitting pull requests.

## Table of Contents

1. [Code of Conduct](#code-of-conduct)
2. [Getting Started](#getting-started)
3. [Development Workflow](#development-workflow)
4. [Coding Standards](#coding-standards)
5. [Testing Requirements](#testing-requirements)
6. [Pull Request Process](#pull-request-process)
7. [Reporting Issues](#reporting-issues)
8. [Documentation](#documentation)
9. [License](#license)

---

## Code of Conduct

A formal Code of Conduct will be added to this repository. In the meantime, all contributors are expected to engage respectfully, provide constructive feedback, and collaborate in good faith.

---

## Getting Started

### Prerequisites

| Requirement | Version | Purpose |
|-------------|---------|---------|
| Docker | Latest stable | Container runtime |
| Docker Compose | v2+ | Multi-container orchestration |
| PHP | >= 8.1 | Local unit testing (optional if using Docker) |
| Composer | Latest | PHP dependency management |
| Git | Latest | Version control |

### PHP Extensions (for local testing)

| Extension | Required For |
|-----------|--------------|
| `dom` | PHPUnit XML parsing |
| `mbstring` | String handling |
| `sodium` | Cryptographic operations (Tor key derivation) |
| `openssl` | Key encryption, BIP39 tests |

**Ubuntu/Debian:**

```bash
sudo apt-get install php8.3-cli php8.3-xml php8.3-mbstring php8.3-sodium
```

**macOS (Homebrew):**

```bash
brew install php composer
```

### Repository Setup

```bash
# Clone the repository
git clone https://github.com/eiou-org/eiou-docker.git
cd eiou-docker

# Install PHP dependencies
cd files && composer install && cd ..

# Verify unit tests pass
cd files && composer test
```

### Running a Local Node

```bash
# Start a single node for development
docker-compose -f docker-compose-single.yml up -d --build

# Verify the container is running
sleep 10 && docker ps | grep eiou

# View logs
docker-compose -f docker-compose-single.yml logs -f

# Stop and remove data
docker-compose -f docker-compose-single.yml down -v
```

### Repository Structure

```
eiou-docker/
  files/src/              PHP application source
    api/                  REST API controllers
    cli/                  CLI interface (eiou command)
    core/                 Application, UserContext, Wallet, Constants
    config/               DI container configuration
    database/             PDO, schema, migrations, query builder
    exceptions/           ServiceException hierarchy
    events/               EventDispatcher, SyncEvents
    formatters/           Output formatting
    processors/           Background message processors
    security/             BIP39, encryption, Tor key derivation
    services/             Business logic (ServiceContainer, all services)
    gui/                  Web GUI controllers, views, helpers
    schemas/              Message payload schemas
    utils/                Input validation, logging, security utilities
  tests/
    Unit/                 PHPUnit tests (2900+ tests)
    testfiles/            Integration test scripts
    run-all-tests.sh      Integration test runner
    phpunit.xml.dist      PHPUnit configuration template
    bootstrap.php         Test environment setup
  startup.sh              Container entrypoint
  eiou.dockerfile          Docker build file
  docker-compose-*.yml    Network topology configurations
  docs/                   Technical documentation
  scripts/                Helper scripts
```

### Base Image Maintenance

The base image in `eiou.dockerfile` is pinned to a SHA256 digest rather than a mutable tag. This ensures reproducible builds and prevents supply chain attacks where a compromised upstream tag republish silently changes the image content.

**Checking the current digest:**

```bash
./scripts/check-base-image.sh
```

**Updating the digest manually:**

```bash
docker pull debian:12-slim
docker inspect --format='{{index .RepoDigests 0}}' debian:12-slim
# Update the FROM line in eiou.dockerfile with the new digest
```

CI checks the digest monthly and opens a GitHub issue when it becomes stale. When updating, keep the tag alongside the digest for readability:

```dockerfile
FROM debian:12-slim@sha256:<new-digest>
```

---

## Development Workflow

### Branching

Create a new branch for each change. Branch names should follow this format:

```
eiou-docker-<type>-<issue>-<description>
```

Include the issue number when the branch relates to a GitHub issue. The description is optional but recommended for clarity.

| Type | Use For | Example |
|------|---------|---------|
| `feature` | New functionality | `eiou-docker-feature-580-node-identity` |
| `fix` | Bug fixes | `eiou-docker-fix-432-sync-timeout` |
| `docs` | Documentation | `eiou-docker-docs-550-standard-files` |
| `refactor` | Code restructuring | `eiou-docker-refactor-562-dependency-injection` |
| `test` | Test additions | `eiou-docker-test-555-p2p-routing` |

```bash
# Feature branch for issue #580
git checkout -b eiou-docker-feature-580-node-identity

# Bug fix for issue #432
git checkout -b eiou-docker-fix-432-sync-timeout

# Documentation for issue #550
git checkout -b eiou-docker-docs-550-standard-files

# Branch without an issue (e.g., minor cleanup)
git checkout -b eiou-docker-refactor-service-split
```

### Committing

Use [Conventional Commits](https://www.conventionalcommits.org/) format for commit messages:

| Prefix | Use For |
|--------|---------|
| `feat:` | New features |
| `fix:` | Bug fixes |
| `refactor:` | Code restructuring without behavior change |
| `test:` | Adding or updating tests |
| `docs:` | Documentation changes |
| `chore:` | Build, tooling, or dependency updates |

Examples:

```
feat: Add rate limiting to P2P message processor
fix: Correct transaction chain validation for edge case
refactor: Extract balance calculation into BalanceService
test: Add unit tests for HeldTransactionService
docs: Update API reference for new endpoints
```

Commit regularly. Small, focused commits are easier to review and revert if needed.

### General Workflow

1. Create a branch from `main`.
2. Make your changes with regular commits.
3. Run the full test suite (see [Testing Requirements](#testing-requirements)).
4. Push your branch and open a pull request.
5. Address review feedback.
6. After merge, delete your branch.

Never push directly to `main`. All changes require a pull request.

---

## Coding Standards

### PHP Style

- PHP >= 8.1 syntax. Use typed properties, union types, and named arguments where appropriate.
- PSR-4 autoloading under the `Eiou\` namespace.
- Use `declare(strict_types=1);` in new files.
- All classes should have a namespace under `Eiou\`.
- Use PHPDoc blocks for public methods. Include `@param`, `@return`, and `@throws` tags.

### Naming Conventions

| Element | Convention | Example |
|---------|------------|---------|
| Classes | PascalCase | `TransactionService`, `BalanceRepository` |
| Methods | camelCase | `getAcceptedContacts()`, `processQueuedP2pMessages()` |
| Properties | camelCase | `$syncService`, `$contactRepository` |
| Constants | UPPER_SNAKE_CASE | `TRANSACTION_MAX_AMOUNT`, `P2P_DEFAULT_EXPIRATION_SECONDS` |
| Test methods | camelCase with `test` prefix | `testValidateAmountWithPositiveValue()` |
| Test classes | PascalCase with `Test` suffix | `BalanceServiceTest` |
| Interfaces | PascalCase with `Interface` suffix | `SyncTriggerInterface`, `ContactServiceInterface` |

### Architecture Patterns

**Singleton Access -- ServiceContainer:**

```php
// CORRECT: Use the singleton getter
$container = ServiceContainer::getInstance();

// WRONG: Do not instantiate directly
$container = new ServiceContainer();
```

**Constructor Injection for Required Dependencies:**

```php
class BalanceService
{
    public function __construct(
        BalanceRepository $balanceRepository,
        TransactionContactRepository $transactionContactRepository,
        AddressRepository $addressRepository,
        CurrencyUtilityService $currencyUtility
    ) {
        // All dependencies available immediately
    }
}
```

**Setter Injection for Circular Dependencies Only:**

```php
class TransactionService
{
    private ?SyncTriggerInterface $syncService = null;

    public function setSyncService(SyncTriggerInterface $syncService): void
    {
        $this->syncService = $syncService;
    }
}
```

Use constructor injection by default. Only use setter injection when resolving circular dependencies through `ServiceContainer::wireCircularDependencies()`.

**Interface Segregation:**

Services should depend on focused interfaces rather than concrete implementations. For example, depend on `SyncTriggerInterface` instead of `SyncService` directly.

**Initialization Order:**

UserContext MUST initialize BEFORE ServiceContainer. The correct startup sequence is:

```
startup.sh -> messageCheck.php -> PDO -> UserContext -> ServiceContainer
```

Violating this order causes runtime crashes. See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full initialization flow.

**Error Handling:**

Use the `ServiceException` hierarchy for business logic errors. Do not call `exit()` directly from service methods.

| Scenario | Exception Type |
|----------|----------------|
| Invalid user input | `ValidationServiceException` |
| Missing required resource | `FatalServiceException` |
| Unauthorized action | `FatalServiceException` |
| Network timeout | `RecoverableServiceException` |
| Rate limited | `RecoverableServiceException` |

```php
throw new ValidationServiceException(
    "Invalid name: " . $validation['error'],
    ErrorCodes::INVALID_NAME,
    'name',
    400
);
```

### Things to Avoid

- Do not leave `TODO` or `console.log` in submitted code.
- Do not introduce new circular dependencies. Run the circular dependency check before submitting:

```bash
cd tests/testfiles
./circularDependencyCheck.sh
```

- Do not bypass rate limiting or authentication in production code paths.
- Do not store secrets (private keys, mnemonics, auth codes) in plaintext.

---

## Testing Requirements

All code changes must include appropriate tests. Untested code will not be merged.

### Unit Tests (PHPUnit 11)

Unit tests validate individual classes and methods in isolation.

**Running unit tests:**

```bash
cd files

# Run all tests
composer test

# Verbose output with test names
composer test-verbose

# Stop on first failure (for debugging)
composer test-debug

# Run a specific test file
composer test -- tests/Unit/Services/BalanceServiceTest.php

# Run tests matching a pattern
composer test -- --filter=testValidateAmount

# Run with coverage report (requires Xdebug or PCOV)
composer test-coverage
```

**Running unit tests via Docker (no local PHP required):**

```bash
docker run --rm -v "$(pwd)":/app -w /app/files composer:latest install
docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli ./files/vendor/bin/phpunit --configuration tests/phpunit.xml
```

**Writing unit tests:**

- Place test files in `tests/Unit/` mirroring the source directory structure.
- Name test files `{ClassName}Test.php`.
- Name test methods `test{MethodName}{Scenario}`.
- Use the Arrange-Act-Assert pattern.
- Use PHPUnit attributes (`#[CoversClass(...)]`) for coverage tracking.

```php
<?php

declare(strict_types=1);

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\YourService;

#[CoversClass(YourService::class)]
class YourServiceTest extends TestCase
{
    public function testMethodWithValidInput(): void
    {
        // Arrange
        $service = new YourService(/* dependencies */);

        // Act
        $result = $service->process('valid-input');

        // Assert
        $this->assertTrue($result);
    }

    public function testMethodThrowsOnInvalidInput(): void
    {
        $this->expectException(ValidationServiceException::class);

        $service = new YourService(/* dependencies */);
        $service->process('invalid');
    }
}
```

### Integration Tests (Shell)

Integration tests validate system behavior using running Docker containers.

**Running integration tests:**

```bash
cd tests

# Run all integration tests against 4-node HTTP topology
./run-all-tests.sh http4

# View available test suites
./run-all-tests.sh --help
```

**Pass rate requirement:** 90% or higher.

### Docker Validation (Required for PHP Changes)

Any change to PHP source code must pass Docker container validation:

```bash
# 1. Build and start a single node
docker-compose -f docker-compose-single.yml up -d --build

# 2. Wait for startup and verify container is running
sleep 10 && docker ps | grep eiou  # Must show "Up"

# 3. Verify initialization order
docker-compose -f docker-compose-single.yml exec eiou php -r "
  require_once '/app/src/context/UserContext.php';
  require_once '/app/src/services/ServiceContainer.php';
  echo 'Initialization successful';
"

# 4. Container must remain stable for 60 seconds
sleep 60 && docker ps | grep eiou  # Still "Up"

# 5. Run full integration test suite
cd tests && ./run-all-tests.sh http4

# 6. Clean up
docker-compose -f docker-compose-single.yml down -v
```

### Coverage Requirements

- 80% or higher test coverage for new code.
- Every bug fix must include a regression test that would have caught the bug.
- Every new feature must include tests covering the primary path and error cases.

---

## Pull Request Process

### Before Opening a PR

1. All unit tests pass: `cd files && composer test`
2. Integration tests pass (for PHP changes): `cd tests && ./run-all-tests.sh http4`
3. Docker container starts and remains stable for 60 seconds.
4. No `TODO` comments or debug logging left in code.
5. `composer audit` reports no known vulnerabilities.
6. Circular dependency check passes: `cd tests/testfiles && ./circularDependencyCheck.sh`
7. PR is under 500 lines of changes. Split larger changes into multiple PRs.

### PR Description Template

Use this template when opening a pull request:

```markdown
## Summary

[1-2 sentences describing the change and linked issues]

## Testing Performed

- [ ] Unit tests: All passing
- [ ] Integration tests: All passing
- [ ] Manual testing: [Document steps]

## Docker Validation (if applicable)

- [ ] Container starts successfully
- [ ] Stable after 60 seconds
- [ ] Initialization order verified
```

### Review Criteria

| Criterion | Detail |
|-----------|--------|
| Build passes | CI checks must be green |
| Tests pass | All existing and new tests pass |
| No lint errors | Clean PHP code with no warnings |
| Coverage | 80%+ for new code |
| Security audit | `composer audit` clean |
| PR size | Under 500 lines (larger PRs have a 90% rejection rate) |
| Single purpose | One PR = one logical change |

### After Merge

```bash
git checkout main
git pull origin main
# Delete your feature branch
git branch -d your-branch-name
```

---

## Reporting Issues

Report bugs and request features through [GitHub Issues](https://github.com/eiou-org/eiou-docker/issues).

When filing a bug report, include:

- Steps to reproduce the issue.
- Expected behavior vs. actual behavior.
- Docker Compose configuration used (single, 4line, 10line, cluster).
- Relevant container logs (`docker-compose -f <config>.yml logs`).
- PHP version and operating system.

When requesting a feature, include:

- Description of the desired behavior.
- Use case and motivation.
- Any proposed implementation approach (optional).

---

## Documentation

Documentation lives in the `docs/` directory. When your changes affect user-facing behavior, update the relevant documentation:

| Document | When to Update |
|----------|----------------|
| [API Reference](docs/API_REFERENCE.md) | New or changed API endpoints |
| [CLI Reference](docs/CLI_REFERENCE.md) | New or changed CLI commands |
| [GUI Reference](docs/GUI_REFERENCE.md) | Web interface changes |
| [Architecture](docs/ARCHITECTURE.md) | New services, changed initialization order, dependency changes |
| [Error Codes](docs/ERROR_CODES.md) | New error codes or changed error handling |
| [Testing Guide](docs/TESTING.md) | New test patterns, changed test infrastructure |
| [Docker Configuration](docs/DOCKER_CONFIGURATION.md) | New environment variables, volume changes |

Documentation style:

- Use a semi-formal, technical, direct tone.
- Use tables and code blocks for structured content.
- Include a Table of Contents with anchor links for documents longer than three sections.
- Do not use emoji.
- Test all code examples before committing them.

---

## License

By contributing to EIOU Docker, you agree that your contributions will be licensed under the [Apache License 2.0](LICENSE).

Copyright 2025-2026 Vowels Group, LLC
