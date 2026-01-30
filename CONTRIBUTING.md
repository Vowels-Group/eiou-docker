# Contributing to EIOU Docker

Thank you for your interest in contributing to the EIOU Docker project! This document provides guidelines and instructions for contributing.

## Table of Contents

1. [Code of Conduct](#code-of-conduct)
2. [Getting Started](#getting-started)
3. [Development Workflow](#development-workflow)
4. [Pull Request Process](#pull-request-process)
5. [Coding Standards](#coding-standards)
6. [Testing Requirements](#testing-requirements)
7. [Documentation](#documentation)

## Code of Conduct

By participating in this project, you agree to maintain a respectful and inclusive environment. Please be kind and constructive in all interactions.

## Getting Started

### Prerequisites

- Docker and Docker Compose
- PHP 8.1+ (for local development)
- Git

### Setting Up Development Environment

1. Fork the repository on GitHub
2. Clone your fork:
   ```bash
   git clone https://github.com/YOUR-USERNAME/eiou-docker.git
   cd eiou-docker
   ```

3. Build and run the development container:
   ```bash
   docker-compose -f docker-compose-single.yml up -d --build
   ```

4. Verify the container is running:
   ```bash
   docker ps | grep eiou
   ```

## Development Workflow

### Branch Naming

Use the following format for branch names:
```
claudeflow-ai-dev-YYMMDD-HHmm
```

Or for feature/fix branches:
```
feature/short-description
fix/issue-number-short-description
```

### Making Changes

1. Create a new branch from `main`:
   ```bash
   git checkout main
   git pull origin main
   git checkout -b feature/your-feature-name
   ```

2. Make your changes following the [Coding Standards](#coding-standards)

3. Test your changes (see [Testing Requirements](#testing-requirements))

4. Commit with clear, descriptive messages:
   ```bash
   git add specific-files.php
   git commit -m "Add feature: description of what was added"
   ```

## Pull Request Process

### Before Submitting

1. **Run all tests**:
   ```bash
   cd tests && ./run-all-tests.sh http4
   ```

2. **Verify Docker startup**:
   ```bash
   docker-compose -f docker-compose-single.yml up -d --build
   sleep 10
   docker ps | grep eiou  # Should show "Up"
   ```

3. **Check for PHP errors**:
   ```bash
   docker-compose exec <container> php -l /path/to/changed/file.php
   ```

### PR Requirements

- **Under 500 lines**: Large PRs have a 90% rejection rate
- **Single purpose**: One logical change per PR
- **Tests included**: New code requires tests
- **Documentation updated**: Update relevant docs
- **No console.log/TODO**: Clean up debug code

### PR Description Template

```markdown
## Summary
Brief description of changes and linked issues.

## Testing Performed
- [ ] Unit tests: All passing
- [ ] Integration tests: All passing
- [ ] Manual testing: [Document steps]

## Docker Validation (if applicable)
- [ ] Container starts successfully
- [ ] Stable after 60 seconds
- [ ] Initialization successful
```

## Coding Standards

### PHP Style

- PSR-12 coding standard
- PHP 8.1+ features where appropriate
- Type hints on all new code
- PHPDoc blocks for public methods

```php
/**
 * Brief description of method.
 *
 * @param string $param Description of parameter
 * @return array Description of return value
 * @throws ExceptionType When condition occurs
 */
public function methodName(string $param): array
{
    // Implementation
}
```

### Security Requirements

- **Input Validation**: Validate all user input using InputValidator
- **Shell Commands**: Always use `escapeshellarg()` for variables in shell commands
- **SQL**: Use prepared statements (PDO) - never concatenate SQL
- **Output**: Use `htmlspecialchars()` for HTML output
- **CSRF**: Include CSRF tokens in all forms

### File Organization

```
files/
├── src/
│   ├── core/           # Core classes (Constants, ErrorCodes)
│   ├── services/       # Business logic services
│   ├── database/       # Repository classes
│   ├── utils/          # Utility classes
│   └── gui/            # Web interface components
tests/
├── testfiles/          # Integration test scripts
└── run-all-tests.sh    # Test runner
```

## Testing Requirements

### Integration Tests

All PHP changes must pass Docker integration tests:

```bash
# Start test environment
docker-compose -f docker-compose-4line.yml up -d --build

# Run test suite
cd tests && ./run-all-tests.sh http4
```

### Test Coverage

- New features require test coverage
- Bug fixes should include regression tests
- Aim for 80%+ coverage on new code

### Test File Naming

```
tests/testfiles/
├── <feature>Test.sh          # Feature tests
├── negative<Feature>Test.sh  # Negative/error path tests
└── serviceExceptionTest.sh   # Exception handling tests
```

## Documentation

### Code Documentation

- PHPDoc blocks for all public methods
- Inline comments for complex logic
- Update relevant markdown docs

### User Documentation

- Update CLI help text in `CliService.php`
- Update GUI help in relevant templates
- Add entries to CHANGELOG.md

### README Updates

For significant features, update:
- Feature list in main README
- Usage examples
- Configuration options

## Questions?

If you have questions about contributing:

1. Check existing documentation in `docs/`
2. Review closed PRs for similar changes
3. Open an issue for discussion

Thank you for contributing to EIOU!
