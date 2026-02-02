<?php
/**
 * Unit Tests for Service Exception Classes
 *
 * Tests all exception classes in the Eiou\Exceptions namespace:
 * - ServiceException (base class)
 * - FatalServiceException
 * - RecoverableServiceException
 * - ValidationServiceException
 */

namespace Eiou\Tests\Exceptions;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Exceptions\ServiceException;
use Eiou\Exceptions\FatalServiceException;
use Eiou\Exceptions\RecoverableServiceException;
use Eiou\Exceptions\ValidationServiceException;
use Eiou\Core\ErrorCodes;
use Exception;

#[CoversClass(ServiceException::class)]
#[CoversClass(FatalServiceException::class)]
#[CoversClass(RecoverableServiceException::class)]
#[CoversClass(ValidationServiceException::class)]
class ServiceExceptionTest extends TestCase
{
    // =========================================================================
    // FatalServiceException Tests
    // =========================================================================

    /**
     * Test FatalServiceException can be instantiated with message only
     */
    public function testFatalServiceExceptionWithMessageOnly(): void
    {
        $exception = new FatalServiceException('Fatal error occurred');

        $this->assertInstanceOf(ServiceException::class, $exception);
        $this->assertEquals('Fatal error occurred', $exception->getMessage());
        $this->assertEquals(ErrorCodes::INTERNAL_ERROR, $exception->getErrorCode());
        $this->assertEquals(500, $exception->getHttpStatus());
        $this->assertEquals([], $exception->getContext());
        $this->assertEquals(1, $exception->getExitCode());
    }

    /**
     * Test FatalServiceException with custom error code
     */
    public function testFatalServiceExceptionWithCustomErrorCode(): void
    {
        $exception = new FatalServiceException(
            'Wallet not found',
            ErrorCodes::WALLET_NOT_FOUND
        );

        $this->assertEquals(ErrorCodes::WALLET_NOT_FOUND, $exception->getErrorCode());
        $this->assertEquals(404, $exception->getHttpStatus());
    }

    /**
     * Test FatalServiceException with context data
     */
    public function testFatalServiceExceptionWithContext(): void
    {
        $context = ['wallet_id' => 'abc123', 'user_id' => 456];
        $exception = new FatalServiceException(
            'Critical failure',
            ErrorCodes::INTERNAL_ERROR,
            $context
        );

        $this->assertEquals($context, $exception->getContext());
    }

    /**
     * Test FatalServiceException with custom HTTP status
     */
    public function testFatalServiceExceptionWithCustomHttpStatus(): void
    {
        $exception = new FatalServiceException(
            'Server error',
            ErrorCodes::INTERNAL_ERROR,
            [],
            503
        );

        $this->assertEquals(503, $exception->getHttpStatus());
    }

    /**
     * Test FatalServiceException with previous exception
     */
    public function testFatalServiceExceptionWithPreviousException(): void
    {
        $previous = new Exception('Original error');
        $exception = new FatalServiceException(
            'Wrapped error',
            ErrorCodes::INTERNAL_ERROR,
            [],
            null,
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test FatalServiceException always returns exit code 1
     */
    public function testFatalServiceExceptionExitCodeIsAlwaysOne(): void
    {
        $exception = new FatalServiceException('Any fatal error');
        $this->assertEquals(1, $exception->getExitCode());
    }

    // =========================================================================
    // RecoverableServiceException Tests
    // =========================================================================

    /**
     * Test RecoverableServiceException can be instantiated with message only
     */
    public function testRecoverableServiceExceptionWithMessageOnly(): void
    {
        $exception = new RecoverableServiceException('Temporary failure');

        $this->assertInstanceOf(ServiceException::class, $exception);
        $this->assertEquals('Temporary failure', $exception->getMessage());
        $this->assertEquals(ErrorCodes::GENERAL_ERROR, $exception->getErrorCode());
        $this->assertEquals(500, $exception->getHttpStatus());
        $this->assertEquals([], $exception->getContext());
        $this->assertEquals(0, $exception->getExitCode());
    }

    /**
     * Test RecoverableServiceException with custom error code
     */
    public function testRecoverableServiceExceptionWithCustomErrorCode(): void
    {
        $exception = new RecoverableServiceException(
            'Rate limited',
            ErrorCodes::RATE_LIMIT_EXCEEDED
        );

        $this->assertEquals(ErrorCodes::RATE_LIMIT_EXCEEDED, $exception->getErrorCode());
        $this->assertEquals(429, $exception->getHttpStatus());
    }

    /**
     * Test RecoverableServiceException with context data
     */
    public function testRecoverableServiceExceptionWithContext(): void
    {
        $context = ['retry_after' => 60, 'limit' => 100];
        $exception = new RecoverableServiceException(
            'Rate limited',
            ErrorCodes::RATE_LIMIT_EXCEEDED,
            $context
        );

        $this->assertEquals($context, $exception->getContext());
    }

    /**
     * Test RecoverableServiceException with custom HTTP status
     */
    public function testRecoverableServiceExceptionWithCustomHttpStatus(): void
    {
        $exception = new RecoverableServiceException(
            'Service unavailable',
            ErrorCodes::TIMEOUT,
            [],
            503
        );

        $this->assertEquals(503, $exception->getHttpStatus());
    }

    /**
     * Test RecoverableServiceException with custom exit code
     */
    public function testRecoverableServiceExceptionWithCustomExitCode(): void
    {
        $exception = new RecoverableServiceException(
            'Partial failure',
            ErrorCodes::GENERAL_ERROR,
            [],
            null,
            2
        );

        $this->assertEquals(2, $exception->getExitCode());
    }

    /**
     * Test RecoverableServiceException default exit code is 0
     */
    public function testRecoverableServiceExceptionDefaultExitCodeIsZero(): void
    {
        $exception = new RecoverableServiceException('Any recoverable error');
        $this->assertEquals(0, $exception->getExitCode());
    }

    /**
     * Test RecoverableServiceException with previous exception
     */
    public function testRecoverableServiceExceptionWithPreviousException(): void
    {
        $previous = new Exception('Network timeout');
        $exception = new RecoverableServiceException(
            'Connection failed',
            ErrorCodes::TIMEOUT,
            [],
            null,
            0,
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    // =========================================================================
    // ValidationServiceException Tests
    // =========================================================================

    /**
     * Test ValidationServiceException can be instantiated with message only
     */
    public function testValidationServiceExceptionWithMessageOnly(): void
    {
        $exception = new ValidationServiceException('Validation failed');

        $this->assertInstanceOf(ServiceException::class, $exception);
        $this->assertEquals('Validation failed', $exception->getMessage());
        $this->assertEquals(ErrorCodes::VALIDATION_ERROR, $exception->getErrorCode());
        $this->assertEquals(400, $exception->getHttpStatus());
        $this->assertNull($exception->getField());
        $this->assertEquals([], $exception->getContext());
        $this->assertEquals(1, $exception->getExitCode());
    }

    /**
     * Test ValidationServiceException with custom error code
     */
    public function testValidationServiceExceptionWithCustomErrorCode(): void
    {
        $exception = new ValidationServiceException(
            'Invalid address format',
            ErrorCodes::INVALID_ADDRESS
        );

        $this->assertEquals(ErrorCodes::INVALID_ADDRESS, $exception->getErrorCode());
        $this->assertEquals(400, $exception->getHttpStatus());
    }

    /**
     * Test ValidationServiceException with field name
     */
    public function testValidationServiceExceptionWithField(): void
    {
        $exception = new ValidationServiceException(
            'Name is required',
            ErrorCodes::INVALID_NAME,
            'name'
        );

        $this->assertEquals('name', $exception->getField());
        $this->assertEquals(['field' => 'name'], $exception->getContext());
    }

    /**
     * Test ValidationServiceException field is added to context
     */
    public function testValidationServiceExceptionFieldAddedToContext(): void
    {
        $exception = new ValidationServiceException(
            'Email is invalid',
            ErrorCodes::VALIDATION_ERROR,
            'email',
            null,
            ['extra' => 'data']
        );

        $context = $exception->getContext();
        $this->assertEquals('email', $context['field']);
        $this->assertEquals('data', $context['extra']);
    }

    /**
     * Test ValidationServiceException with null field
     */
    public function testValidationServiceExceptionWithNullField(): void
    {
        $exception = new ValidationServiceException(
            'General validation error',
            ErrorCodes::VALIDATION_ERROR,
            null
        );

        $this->assertNull($exception->getField());
        $this->assertArrayNotHasKey('field', $exception->getContext());
    }

    /**
     * Test ValidationServiceException with custom HTTP status
     */
    public function testValidationServiceExceptionWithCustomHttpStatus(): void
    {
        $exception = new ValidationServiceException(
            'Validation error',
            ErrorCodes::VALIDATION_ERROR,
            'field',
            422
        );

        $this->assertEquals(422, $exception->getHttpStatus());
    }

    /**
     * Test ValidationServiceException always returns exit code 1
     */
    public function testValidationServiceExceptionExitCodeIsAlwaysOne(): void
    {
        $exception = new ValidationServiceException('Any validation error');
        $this->assertEquals(1, $exception->getExitCode());
    }

    /**
     * Test ValidationServiceException with previous exception
     */
    public function testValidationServiceExceptionWithPreviousException(): void
    {
        $previous = new Exception('Original validation error');
        $exception = new ValidationServiceException(
            'Wrapped validation error',
            ErrorCodes::VALIDATION_ERROR,
            'field',
            null,
            [],
            $previous
        );

        $this->assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test ValidationServiceException toArray includes field
     */
    public function testValidationServiceExceptionToArrayIncludesField(): void
    {
        $exception = new ValidationServiceException(
            'Invalid amount',
            ErrorCodes::INVALID_AMOUNT,
            'amount'
        );

        $array = $exception->toArray();

        $this->assertFalse($array['success']);
        $this->assertEquals(ErrorCodes::INVALID_AMOUNT, $array['error']['code']);
        $this->assertEquals('Invalid amount', $array['error']['message']);
        $this->assertEquals('amount', $array['error']['field']);
    }

    /**
     * Test ValidationServiceException toArray without field
     */
    public function testValidationServiceExceptionToArrayWithoutField(): void
    {
        $exception = new ValidationServiceException(
            'General error',
            ErrorCodes::VALIDATION_ERROR
        );

        $array = $exception->toArray();

        $this->assertArrayNotHasKey('field', $array['error']);
    }

    // =========================================================================
    // ServiceException Base Class Tests (via concrete implementations)
    // =========================================================================

    /**
     * Test toArray method returns proper structure
     */
    public function testToArrayReturnsProperStructure(): void
    {
        $context = ['key' => 'value'];
        $exception = new FatalServiceException(
            'Test error message',
            ErrorCodes::INTERNAL_ERROR,
            $context
        );

        $array = $exception->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('success', $array);
        $this->assertArrayHasKey('error', $array);
        $this->assertFalse($array['success']);

        $error = $array['error'];
        $this->assertEquals(ErrorCodes::INTERNAL_ERROR, $error['code']);
        $this->assertEquals('Test error message', $error['message']);
        $this->assertEquals('Internal Server Error', $error['title']);
        $this->assertEquals(500, $error['httpStatus']);
        $this->assertEquals($context, $error['context']);
    }

    /**
     * Test toJson method returns valid JSON
     */
    public function testToJsonReturnsValidJson(): void
    {
        $exception = new FatalServiceException(
            'JSON test error',
            ErrorCodes::NOT_FOUND
        );

        $json = $exception->toJson();

        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertFalse($decoded['success']);
        $this->assertEquals('JSON test error', $decoded['error']['message']);
    }

    /**
     * Test HTTP status auto-detection from error code
     */
    public function testHttpStatusAutoDetectionFromErrorCode(): void
    {
        // 400 - Bad Request
        $exception400 = new ValidationServiceException('Invalid', ErrorCodes::VALIDATION_ERROR);
        $this->assertEquals(400, $exception400->getHttpStatus());

        // 401 - Unauthorized
        $exception401 = new FatalServiceException('Auth error', ErrorCodes::AUTH_REQUIRED);
        $this->assertEquals(401, $exception401->getHttpStatus());

        // 403 - Forbidden
        $exception403 = new FatalServiceException('Forbidden', ErrorCodes::PERMISSION_DENIED);
        $this->assertEquals(403, $exception403->getHttpStatus());

        // 404 - Not Found
        $exception404 = new FatalServiceException('Not found', ErrorCodes::NOT_FOUND);
        $this->assertEquals(404, $exception404->getHttpStatus());

        // 409 - Conflict
        $exception409 = new FatalServiceException('Exists', ErrorCodes::WALLET_EXISTS);
        $this->assertEquals(409, $exception409->getHttpStatus());

        // 429 - Too Many Requests
        $exception429 = new RecoverableServiceException('Rate limited', ErrorCodes::RATE_LIMIT_EXCEEDED);
        $this->assertEquals(429, $exception429->getHttpStatus());

        // 500 - Internal Server Error
        $exception500 = new FatalServiceException('Internal', ErrorCodes::INTERNAL_ERROR);
        $this->assertEquals(500, $exception500->getHttpStatus());

        // 503 - Service Unavailable
        $exception503 = new RecoverableServiceException('Unavailable', ErrorCodes::CONTACT_UNREACHABLE);
        $this->assertEquals(503, $exception503->getHttpStatus());

        // 504 - Gateway Timeout
        $exception504 = new RecoverableServiceException('Timeout', ErrorCodes::TIMEOUT);
        $this->assertEquals(504, $exception504->getHttpStatus());
    }

    /**
     * Test custom HTTP status overrides auto-detection
     */
    public function testCustomHttpStatusOverridesAutoDetection(): void
    {
        // VALIDATION_ERROR normally maps to 400, but we override to 422
        $exception = new ValidationServiceException(
            'Custom status',
            ErrorCodes::VALIDATION_ERROR,
            null,
            422
        );

        $this->assertEquals(422, $exception->getHttpStatus());
    }

    /**
     * Test exception inherits from PHP Exception
     */
    public function testExceptionInheritsFromPhpException(): void
    {
        $exception = new FatalServiceException('Test');

        $this->assertInstanceOf(Exception::class, $exception);
    }

    /**
     * Test exception can be thrown and caught
     */
    public function testExceptionCanBeThrownAndCaught(): void
    {
        $this->expectException(FatalServiceException::class);
        $this->expectExceptionMessage('Thrown exception');

        throw new FatalServiceException('Thrown exception');
    }

    /**
     * Test exception can be caught as ServiceException
     */
    public function testExceptionCanBeCaughtAsServiceException(): void
    {
        $caught = false;

        try {
            throw new ValidationServiceException('Test');
        } catch (ServiceException $e) {
            $caught = true;
            $this->assertEquals('Test', $e->getMessage());
        }

        $this->assertTrue($caught);
    }

    /**
     * Test getErrorCode returns string type
     */
    public function testGetErrorCodeReturnsString(): void
    {
        $exception = new FatalServiceException('Test', ErrorCodes::INTERNAL_ERROR);

        $this->assertIsString($exception->getErrorCode());
    }

    /**
     * Test getHttpStatus returns int type
     */
    public function testGetHttpStatusReturnsInt(): void
    {
        $exception = new FatalServiceException('Test');

        $this->assertIsInt($exception->getHttpStatus());
    }

    /**
     * Test getContext returns array type
     */
    public function testGetContextReturnsArray(): void
    {
        $exception = new FatalServiceException('Test');

        $this->assertIsArray($exception->getContext());
    }

    /**
     * Test getExitCode returns int type
     */
    public function testGetExitCodeReturnsInt(): void
    {
        $fatalException = new FatalServiceException('Test');
        $recoverableException = new RecoverableServiceException('Test');
        $validationException = new ValidationServiceException('Test');

        $this->assertIsInt($fatalException->getExitCode());
        $this->assertIsInt($recoverableException->getExitCode());
        $this->assertIsInt($validationException->getExitCode());
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    /**
     * Test exception with empty message
     */
    public function testExceptionWithEmptyMessage(): void
    {
        $exception = new FatalServiceException('');

        $this->assertEquals('', $exception->getMessage());
    }

    /**
     * Test exception with special characters in message
     */
    public function testExceptionWithSpecialCharactersInMessage(): void
    {
        $message = "Error with special chars: <>&\"'";
        $exception = new FatalServiceException($message);

        $this->assertEquals($message, $exception->getMessage());
    }

    /**
     * Test exception with unicode in message
     */
    public function testExceptionWithUnicodeInMessage(): void
    {
        $message = "Error with unicode: ";
        $exception = new FatalServiceException($message);

        $this->assertEquals($message, $exception->getMessage());
    }

    /**
     * Test exception with complex context data
     */
    public function testExceptionWithComplexContextData(): void
    {
        $context = [
            'nested' => [
                'array' => [1, 2, 3],
                'object' => ['key' => 'value']
            ],
            'null_value' => null,
            'boolean' => true,
            'integer' => 42,
            'float' => 3.14
        ];

        $exception = new FatalServiceException(
            'Complex context test',
            ErrorCodes::INTERNAL_ERROR,
            $context
        );

        $this->assertEquals($context, $exception->getContext());

        // Verify JSON encoding works with complex data
        $json = $exception->toJson();
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals($context, $decoded['error']['context']);
    }

    /**
     * Test toArray includes correct title from ErrorCodes
     */
    public function testToArrayIncludesCorrectTitle(): void
    {
        $exception = new ValidationServiceException(
            'Address is invalid',
            ErrorCodes::INVALID_ADDRESS,
            'address'
        );

        $array = $exception->toArray();

        $this->assertEquals('Invalid Address', $array['error']['title']);
    }

    /**
     * Test exception chaining preserves stack trace
     */
    public function testExceptionChainingPreservesStackTrace(): void
    {
        $original = new Exception('Original error', 100);
        $wrapped = new FatalServiceException(
            'Wrapped error',
            ErrorCodes::INTERNAL_ERROR,
            [],
            null,
            $original
        );

        $this->assertSame($original, $wrapped->getPrevious());
        $this->assertEquals('Original error', $wrapped->getPrevious()->getMessage());
        $this->assertEquals(100, $wrapped->getPrevious()->getCode());
    }

    /**
     * Test different exception types have correct inheritance
     */
    public function testDifferentExceptionTypesHaveCorrectInheritance(): void
    {
        $fatal = new FatalServiceException('Fatal');
        $recoverable = new RecoverableServiceException('Recoverable');
        $validation = new ValidationServiceException('Validation');

        // All extend ServiceException
        $this->assertInstanceOf(ServiceException::class, $fatal);
        $this->assertInstanceOf(ServiceException::class, $recoverable);
        $this->assertInstanceOf(ServiceException::class, $validation);

        // All extend Exception
        $this->assertInstanceOf(Exception::class, $fatal);
        $this->assertInstanceOf(Exception::class, $recoverable);
        $this->assertInstanceOf(Exception::class, $validation);
    }
}
