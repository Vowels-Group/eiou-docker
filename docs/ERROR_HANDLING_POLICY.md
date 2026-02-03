# Error Handling Policy

Standardized error handling guidelines for the EIOU codebase.

## Table of Contents

1. [Overview](#overview)
2. [Core Principles](#core-principles)
3. [Exception Hierarchy](#exception-hierarchy)
4. [When to Use Each Exception Type](#when-to-use-each-exception-type)
5. [Required Context](#required-context)
6. [Integration with ErrorCodes](#integration-with-errorcodes)
7. [Entry Point Handling](#entry-point-handling)
8. [Code Examples](#code-examples)
9. [Testing Exceptions](#testing-exceptions)

---

## Overview

This document defines the standardized approach to error handling across the EIOU codebase. The goal is to provide:

- **Consistency**: All errors are handled the same way across CLI, API, and service layers
- **Rich Context**: Every error includes actionable information for debugging and user feedback
- **Testability**: Exception-based errors can be caught and verified in unit tests
- **Recoverability**: Clear distinction between fatal and recoverable errors enables proper retry logic

---

## Core Principles

### 1. Always Throw Exceptions, Never Return Error Codes

```php
// WRONG - Returns false for errors
public function findContact(string $identifier): array|false
{
    $contact = $this->contactRepo->lookupByName($identifier);
    if (!$contact) {
        return false;  // Caller doesn't know WHY it failed
    }
    return $contact;
}

// CORRECT - Throws exception with context
public function findContact(string $identifier): array
{
    $contact = $this->contactRepo->lookupByName($identifier);
    if (!$contact) {
        throw new RecoverableServiceException(
            "Contact not found: $identifier",
            ErrorCodes::CONTACT_NOT_FOUND,
            ['identifier' => $identifier]
        );
    }
    return $contact;
}
```

### 2. Use Appropriate Exception Type Based on Recoverability

Choose the exception type based on whether the error condition can potentially be resolved:

| Exception Type | Recoverable? | Exit Code | Use When |
|---------------|--------------|-----------|----------|
| `FatalServiceException` | No | 1 | System failures, security issues, missing config |
| `RecoverableServiceException` | Yes | 0 (configurable) | Network issues, temporary failures, lock conflicts |
| `ValidationServiceException` | No | 1 | Invalid user input, missing required fields |

### 3. Include Context with Every Exception

Every exception should include enough context to understand and debug the error:

```php
throw new FatalServiceException(
    "Failed to restore wallet from seed phrase",
    ErrorCodes::SEED_RESTORE_FAILED,
    [
        'word_count' => count($words),
        'expected_count' => 24,
        'reason' => 'Invalid checksum'
    ]
);
```

---

## Exception Hierarchy

All service exceptions extend from the abstract `ServiceException` base class.

```
Exception (PHP built-in)
    |
    +-- ServiceException (abstract)
            |
            +-- FatalServiceException
            |
            +-- RecoverableServiceException
            |
            +-- ValidationServiceException
```

### ServiceException (Abstract Base)

**Location**: `files/src/exceptions/ServiceException.php`

The base class provides common functionality for all service exceptions:

```php
abstract class ServiceException extends Exception
{
    protected string $errorCode;      // ErrorCodes constant
    protected int $httpStatus;        // HTTP status for API responses
    protected array $context;         // Additional debugging data

    public function getErrorCode(): string;
    public function getHttpStatus(): int;
    public function getContext(): array;
    abstract public function getExitCode(): int;
    public function toArray(): array;
    public function toJson(): string;
}
```

### FatalServiceException

**Location**: `files/src/exceptions/FatalServiceException.php`

For unrecoverable errors that should terminate the operation immediately.

**Characteristics**:
- Always returns exit code 1
- Default error code: `ErrorCodes::INTERNAL_ERROR`
- Should be logged at ERROR level

**Examples of fatal errors**:
- Missing wallet when one is required
- Corrupted database or data integrity failures
- Security violations or unauthorized access attempts
- Missing required configuration
- System-level failures (filesystem, memory)

### RecoverableServiceException

**Location**: `files/src/exceptions/RecoverableServiceException.php`

For errors that might succeed on retry or with user intervention.

**Characteristics**:
- Configurable exit code (default 0)
- Default error code: `ErrorCodes::GENERAL_ERROR`
- Should be logged at INFO or WARNING level

**Examples of recoverable errors**:
- Network timeouts or connection failures
- Temporary service unavailability
- Rate limiting
- Lock conflicts (resource in use)
- Contact unreachable

### ValidationServiceException

**Location**: `files/src/exceptions/ValidationServiceException.php`

For input validation errors with field-specific information.

**Characteristics**:
- Always returns exit code 1
- Default error code: `ErrorCodes::VALIDATION_ERROR`
- Includes optional `field` property for specific field errors
- Should be logged at WARNING level

**Examples of validation errors**:
- Invalid address format
- Invalid name (too long, invalid characters)
- Missing required fields
- Invalid amount or currency
- Parameter out of range

---

## When to Use Each Exception Type

### FatalServiceException

Use for errors where:
- The operation cannot possibly succeed without system changes
- Security has been compromised
- Data integrity is at risk
- Required resources are missing

```php
// System configuration missing
throw new FatalServiceException(
    "Database configuration not found",
    ErrorCodes::DB_CONFIG_NOT_FOUND,
    ['config_path' => '/etc/eiou/db.conf']
);

// Security violation
throw new FatalServiceException(
    "Unauthorized access attempt detected",
    ErrorCodes::PERMISSION_DENIED,
    ['attempted_action' => 'admin_operation', 'user_key' => $publicKeyHash]
);

// Missing required resource
throw new FatalServiceException(
    "Wallet not found - generate a wallet first",
    ErrorCodes::WALLET_NOT_FOUND
);

// Data integrity failure
throw new FatalServiceException(
    "Transaction chain integrity check failed",
    ErrorCodes::CHAIN_INTEGRITY_FAILED,
    ['txid' => $transactionId, 'expected_hash' => $expected, 'actual_hash' => $actual]
);
```

### RecoverableServiceException

Use for errors where:
- A retry might succeed
- External conditions might change
- User can take action to resolve

```php
// Network failure
throw new RecoverableServiceException(
    "Failed to connect to contact",
    ErrorCodes::CONNECTION_FAILED,
    ['address' => $contactAddress, 'timeout' => 30]
);

// Temporary unavailability
throw new RecoverableServiceException(
    "Contact is currently offline",
    ErrorCodes::CONTACT_UNREACHABLE,
    ['contact_name' => $name, 'last_seen' => $lastOnline]
);

// Rate limiting
throw new RecoverableServiceException(
    "Rate limit exceeded, try again later",
    ErrorCodes::RATE_LIMIT_EXCEEDED,
    ['retry_after' => $retrySeconds],
    429  // HTTP status
);

// Resource lock conflict
throw new RecoverableServiceException(
    "Transaction already in progress",
    ErrorCodes::TRANSACTION_IN_PROGRESS,
    ['contact' => $contactName]
);
```

### ValidationServiceException

Use for errors where:
- User input is invalid
- Required fields are missing
- Values are out of acceptable range

```php
// Invalid format
throw new ValidationServiceException(
    "Invalid address format",
    ErrorCodes::INVALID_ADDRESS,
    'address',  // field name
    400,        // HTTP status
    ['provided' => $address, 'expected_format' => 'hostname.onion or https://domain']
);

// Missing required field
throw new ValidationServiceException(
    "Contact name is required",
    ErrorCodes::MISSING_ARGUMENT,
    'name'
);

// Value out of range
throw new ValidationServiceException(
    "Fee percent must be between 0 and 100",
    ErrorCodes::INVALID_FEE,
    'fee_percent',
    400,
    ['provided' => $feePercent, 'min' => 0, 'max' => 100]
);

// Invalid combination
throw new ValidationServiceException(
    "Cannot send transaction to yourself",
    ErrorCodes::SELF_SEND,
    'recipient'
);
```

---

## Required Context

### Minimum Required Fields

Every exception must include:

1. **Message**: Human-readable error description
2. **Error Code**: Constant from `ErrorCodes` class
3. **HTTP Status**: Auto-detected from error code (can override)

### Recommended Context Data

Include relevant context to aid debugging:

| Error Type | Recommended Context |
|-----------|-------------------|
| Contact errors | `identifier`, `contact_name`, `pubkey_hash` |
| Transaction errors | `txid`, `amount`, `currency`, `recipient` |
| Validation errors | `field`, `provided_value`, `expected_format` |
| Network errors | `address`, `timeout`, `retry_after` |
| Auth errors | `key_id`, `permission`, `resource` |

### Context Guidelines

- **Do** include relevant identifiers and values
- **Do** include expected vs actual when applicable
- **Do NOT** include sensitive data (private keys, passwords, full seeds)
- **Do NOT** include excessively large data (full request bodies)

---

## Integration with ErrorCodes

The `ErrorCodes` class (`files/src/core/ErrorCodes.php`) provides:

1. **Standardized error code constants**
2. **Automatic HTTP status mapping**
3. **Human-readable error titles**

### Using Error Codes

```php
use Eiou\Core\ErrorCodes;

// Get HTTP status for an error code
$httpStatus = ErrorCodes::getHttpStatus(ErrorCodes::CONTACT_NOT_FOUND);  // Returns 404

// Get human-readable title
$title = ErrorCodes::getTitle(ErrorCodes::INSUFFICIENT_FUNDS);  // Returns "Insufficient Funds"

// Check if code is valid
$isValid = ErrorCodes::isValid('CUSTOM_ERROR');  // Returns false
```

### Error Code Categories

| Prefix | Category | HTTP Range |
|--------|----------|------------|
| `AUTH_*` | Authentication/Authorization | 401, 403 |
| `CONTACT_*` | Contact operations | 400, 403, 404, 409 |
| `TRANSACTION_*` | Transaction operations | 400, 403, 429, 500 |
| `INVALID_*`, `MISSING_*` | Validation | 400 |
| `*_NOT_FOUND` | Resource not found | 404 |
| `*_FAILED` | Operation failures | 500 |
| `*_EXISTS` | Conflict | 409 |

### Adding New Error Codes

When adding new error codes:

1. Add the constant to `ErrorCodes.php`
2. Add HTTP status mapping in `getHttpStatus()`
3. Add human-readable title in `getTitle()`

```php
// In ErrorCodes.php

// Add constant
public const CUSTOM_ERROR = 'CUSTOM_ERROR';

// Add to getHttpStatus() mapping
self::CUSTOM_ERROR => 400,

// Add to getTitle() mapping
self::CUSTOM_ERROR => 'Custom Error Title',
```

---

## Entry Point Handling

### CLI Entry Point (Eiou.php)

The CLI entry point (`files/root/cli/Eiou.php`) wraps all command dispatch in a try-catch block:

```php
try {
    // Command dispatch logic...

} catch (ValidationServiceException $e) {
    // Handle validation errors
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $e->getContext());
    $app->getLogger()->warning("Validation error", [
        'command' => $request,
        'field' => $e->getField(),
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode()
    ]);
    exit($e->getExitCode());

} catch (FatalServiceException $e) {
    // Handle fatal errors
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $e->getContext());
    $app->getLogger()->error("Fatal service error", [
        'command' => $request,
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext()
    ]);
    exit($e->getExitCode());

} catch (RecoverableServiceException $e) {
    // Handle recoverable errors
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $e->getContext());
    $app->getLogger()->info("Recoverable service error", [
        'command' => $request,
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode()
    ]);
    exit($e->getExitCode());

} catch (ServiceException $e) {
    // Catch-all for any ServiceException subclass
    $output->error($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus(), $e->getContext());
    $app->getLogger()->error("Service error", [
        'command' => $request,
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode()
    ]);
    exit($e->getExitCode());
}
```

### API Entry Point (ApiController.php)

The API controller (`files/src/api/ApiController.php`) catches exceptions and returns JSON error responses:

```php
try {
    $response = match ($resource) {
        'wallet' => $this->handleWallet($method, $action, $params, $body),
        'contacts' => $this->handleContacts($method, $action, $id, $params, $body),
        // ...
    };
} catch (ServiceException $e) {
    // Handle ServiceExceptions with their rich error context
    $this->log('warning', 'Service exception in API request', [
        'path' => $path,
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext()
    ]);
    $response = $this->errorResponse(
        $e->getMessage(),
        $e->getHttpStatus(),
        strtolower($e->getErrorCode())
    );
} catch (Exception $e) {
    // Generic fallback for unexpected exceptions
    $this->log('error', 'API request failed', [
        'path' => $path,
        'error' => $e->getMessage()
    ]);
    $response = $this->errorResponse('Internal server error', 500, 'internal_error');
}
```

---

## Code Examples

### Complete Service Method Example

```php
use Eiou\Core\ErrorCodes;
use Eiou\Exceptions\FatalServiceException;
use Eiou\Exceptions\RecoverableServiceException;
use Eiou\Exceptions\ValidationServiceException;

class ContactService
{
    /**
     * Block a contact
     *
     * @param string|null $identifier Contact name or address
     * @param CliOutputManager $output Output manager
     * @return bool True on success
     * @throws ValidationServiceException If identifier is missing or invalid
     * @throws RecoverableServiceException If contact not found
     * @throws FatalServiceException If database operation fails
     */
    public function blockContact(?string $identifier, CliOutputManager $output): bool
    {
        // Validate input
        if (empty($identifier)) {
            throw new ValidationServiceException(
                "Contact name or address required",
                ErrorCodes::MISSING_ARGUMENT,
                'identifier'
            );
        }

        // Find contact
        $contact = $this->findContact($identifier);
        if (!$contact) {
            throw new RecoverableServiceException(
                "Contact not found: $identifier",
                ErrorCodes::CONTACT_NOT_FOUND,
                ['identifier' => $identifier]
            );
        }

        // Check if already blocked
        if ($contact['status'] === Constants::CONTACT_STATUS_BLOCKED) {
            throw new ValidationServiceException(
                "Contact is already blocked",
                ErrorCodes::CONTACT_BLOCKED,
                'status',
                400,
                ['contact_name' => $contact['name'], 'current_status' => $contact['status']]
            );
        }

        // Attempt to block
        try {
            $success = $this->contactRepo->updateContactStatus(
                $contact['pubkey'],
                Constants::CONTACT_STATUS_BLOCKED
            );
        } catch (Exception $e) {
            throw new FatalServiceException(
                "Database error while blocking contact",
                ErrorCodes::BLOCK_FAILED,
                ['contact_name' => $contact['name'], 'db_error' => $e->getMessage()],
                500,
                $e  // Chain the original exception
            );
        }

        if (!$success) {
            throw new FatalServiceException(
                "Failed to block contact",
                ErrorCodes::BLOCK_FAILED,
                ['contact_name' => $contact['name']]
            );
        }

        $output->success(
            "Contact '{$contact['name']}' has been blocked",
            ['contact_name' => $contact['name'], 'status' => 'blocked']
        );

        return true;
    }
}
```

### Exception Chaining

When catching lower-level exceptions, chain them for full stack trace:

```php
try {
    $result = $this->networkClient->send($data);
} catch (NetworkException $e) {
    throw new RecoverableServiceException(
        "Failed to send transaction: " . $e->getMessage(),
        ErrorCodes::NETWORK_ERROR,
        ['address' => $address, 'timeout' => $timeout],
        503,
        0,      // Exit code
        $e      // Previous exception - enables full stack trace
    );
}
```

### JSON Output

Exceptions can be serialized for JSON APIs:

```php
try {
    // ... operation
} catch (ServiceException $e) {
    // toArray() returns structured error data
    $errorData = $e->toArray();
    // {
    //     "success": false,
    //     "error": {
    //         "code": "CONTACT_NOT_FOUND",
    //         "message": "Contact not found: alice",
    //         "title": "Contact Not Found",
    //         "httpStatus": 404,
    //         "context": {"identifier": "alice"}
    //     }
    // }

    // toJson() returns JSON string
    echo $e->toJson();
}
```

---

## Testing Exceptions

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;
use Eiou\Exceptions\ValidationServiceException;
use Eiou\Exceptions\RecoverableServiceException;
use Eiou\Core\ErrorCodes;

class ContactServiceTest extends TestCase
{
    public function testBlockContactThrowsOnMissingIdentifier(): void
    {
        $this->expectException(ValidationServiceException::class);
        $this->expectExceptionMessage("Contact name or address required");

        $service = new ContactService($this->mockRepo, $this->mockOutput);
        $service->blockContact(null, $this->mockOutput);
    }

    public function testBlockContactThrowsOnNotFound(): void
    {
        $this->mockRepo->method('lookupByName')->willReturn(null);

        try {
            $service = new ContactService($this->mockRepo, $this->mockOutput);
            $service->blockContact('unknown', $this->mockOutput);
            $this->fail('Expected RecoverableServiceException');
        } catch (RecoverableServiceException $e) {
            $this->assertEquals(ErrorCodes::CONTACT_NOT_FOUND, $e->getErrorCode());
            $this->assertEquals(404, $e->getHttpStatus());
            $this->assertArrayHasKey('identifier', $e->getContext());
        }
    }

    public function testExceptionContext(): void
    {
        try {
            throw new ValidationServiceException(
                "Invalid fee",
                ErrorCodes::INVALID_FEE,
                'fee_percent',
                400,
                ['provided' => 150, 'max' => 100]
            );
        } catch (ValidationServiceException $e) {
            $context = $e->getContext();
            $this->assertEquals('fee_percent', $e->getField());
            $this->assertEquals(150, $context['provided']);
            $this->assertEquals(100, $context['max']);
        }
    }
}
```

### Integration Test Example

```bash
#!/bin/bash
# Test exception handling in CLI

# Test validation error
result=$(docker exec alice php /app/eiou/Eiou.php block --json 2>&1)
echo "$result" | jq -e '.success == false' || exit 1
echo "$result" | jq -e '.error.code == "MISSING_ARGUMENT"' || exit 1

# Test contact not found
result=$(docker exec alice php /app/eiou/Eiou.php block nonexistent --json 2>&1)
echo "$result" | jq -e '.error.code == "CONTACT_NOT_FOUND"' || exit 1

echo "All exception tests passed"
```

---

## Related Documentation

- [ERROR_CODES.md](ERROR_CODES.md) - Complete list of error codes and HTTP mappings
- [API_REFERENCE.md](API_REFERENCE.md) - API error response formats
- [CLI_REFERENCE.md](CLI_REFERENCE.md) - CLI error output formats
- [ARCHITECTURE.md](ARCHITECTURE.md) - System architecture and service layer design
