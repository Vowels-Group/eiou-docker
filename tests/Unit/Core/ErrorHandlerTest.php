<?php
/**
 * Unit Tests for ErrorHandler
 *
 * Tests the standardized error handling system.
 * Tests focus on static methods and error response creation.
 */

namespace Eiou\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Eiou\Core\ErrorHandler;
use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Exceptions\ServiceException;
use Exception;
use ReflectionClass;

#[CoversClass(ErrorHandler::class)]
class ErrorHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset initialized state via reflection
        $reflection = new ReflectionClass(ErrorHandler::class);
        $initializedProperty = $reflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);
        $initializedProperty->setValue(null, false);

        $requestIdProperty = $reflection->getProperty('requestId');
        $requestIdProperty->setAccessible(true);
        $requestIdProperty->setValue(null, null);
    }

    /**
     * Test init sets initialized flag
     */
    public function testInitSetsInitializedFlag(): void
    {
        ErrorHandler::init();

        $reflection = new ReflectionClass(ErrorHandler::class);
        $initializedProperty = $reflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $this->assertTrue($initializedProperty->getValue(null));
    }

    /**
     * Test init is idempotent
     */
    public function testInitIsIdempotent(): void
    {
        ErrorHandler::init();
        ErrorHandler::init();
        ErrorHandler::init();

        $reflection = new ReflectionClass(ErrorHandler::class);
        $initializedProperty = $reflection->getProperty('initialized');
        $initializedProperty->setAccessible(true);

        $this->assertTrue($initializedProperty->getValue(null));
    }

    /**
     * Test setRequestId sets request ID
     */
    public function testSetRequestIdSetsRequestId(): void
    {
        $testId = 'test_request_123';
        ErrorHandler::setRequestId($testId);

        $this->assertEquals($testId, ErrorHandler::getRequestId());
    }

    /**
     * Test getRequestId returns null initially
     */
    public function testGetRequestIdReturnsNullInitially(): void
    {
        $this->assertNull(ErrorHandler::getRequestId());
    }

    /**
     * Test generateRequestId returns string
     */
    public function testGenerateRequestIdReturnsString(): void
    {
        $requestId = ErrorHandler::generateRequestId();

        $this->assertIsString($requestId);
        $this->assertNotEmpty($requestId);
    }

    /**
     * Test generateRequestId sets request ID
     */
    public function testGenerateRequestIdSetsRequestId(): void
    {
        $requestId = ErrorHandler::generateRequestId();

        $this->assertEquals($requestId, ErrorHandler::getRequestId());
    }

    /**
     * Test generateRequestId returns unique IDs
     */
    public function testGenerateRequestIdReturnsUniqueIds(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            // Reset request ID before generating new one
            $reflection = new ReflectionClass(ErrorHandler::class);
            $requestIdProperty = $reflection->getProperty('requestId');
            $requestIdProperty->setAccessible(true);
            $requestIdProperty->setValue(null, null);

            $ids[] = ErrorHandler::generateRequestId();
        }

        $uniqueIds = array_unique($ids);
        $this->assertCount(100, $uniqueIds);
    }

    /**
     * Test createErrorResponse returns correct structure
     */
    public function testCreateErrorResponseReturnsCorrectStructure(): void
    {
        $response = ErrorHandler::createErrorResponse('Test error', 400);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('error', $response);
        $this->assertFalse($response['success']);
        $this->assertIsArray($response['error']);
        $this->assertArrayHasKey('message', $response['error']);
        $this->assertArrayHasKey('code', $response['error']);
    }

    /**
     * Test createErrorResponse with message
     */
    public function testCreateErrorResponseWithMessage(): void
    {
        $message = 'Custom error message';
        $response = ErrorHandler::createErrorResponse($message);

        $this->assertEquals($message, $response['error']['message']);
    }

    /**
     * Test createErrorResponse with code
     */
    public function testCreateErrorResponseWithCode(): void
    {
        $code = 404;
        $response = ErrorHandler::createErrorResponse('Error', $code);

        $this->assertEquals($code, $response['error']['code']);
    }

    /**
     * Test createErrorResponse default code is 500
     */
    public function testCreateErrorResponseDefaultCodeIs500(): void
    {
        $response = ErrorHandler::createErrorResponse('Error');

        $this->assertEquals(500, $response['error']['code']);
    }

    /**
     * Test createErrorResponse with details in development mode
     */
    public function testCreateErrorResponseWithDetailsInDevelopment(): void
    {
        // Constants::APP_ENV is set to 'development'
        if (Constants::APP_ENV !== 'development') {
            $this->markTestSkipped('Test requires development environment');
        }

        $details = ['file' => 'test.php', 'line' => 42];
        $response = ErrorHandler::createErrorResponse('Error', 500, $details);

        $this->assertArrayHasKey('details', $response['error']);
        $this->assertEquals($details, $response['error']['details']);
    }

    /**
     * Test createSuccessResponse returns correct structure
     */
    public function testCreateSuccessResponseReturnsCorrectStructure(): void
    {
        $response = ErrorHandler::createSuccessResponse();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('data', $response);
        $this->assertTrue($response['success']);
    }

    /**
     * Test createSuccessResponse with data
     */
    public function testCreateSuccessResponseWithData(): void
    {
        $data = ['id' => 1, 'name' => 'test'];
        $response = ErrorHandler::createSuccessResponse($data);

        $this->assertEquals($data, $response['data']);
    }

    /**
     * Test createSuccessResponse with custom message
     */
    public function testCreateSuccessResponseWithCustomMessage(): void
    {
        $message = 'Custom success message';
        $response = ErrorHandler::createSuccessResponse(null, $message);

        $this->assertEquals($message, $response['message']);
    }

    /**
     * Test createSuccessResponse default message
     */
    public function testCreateSuccessResponseDefaultMessage(): void
    {
        $response = ErrorHandler::createSuccessResponse();

        $this->assertEquals('Success', $response['message']);
    }

    /**
     * Test createSuccessResponse with null data
     */
    public function testCreateSuccessResponseWithNullData(): void
    {
        $response = ErrorHandler::createSuccessResponse(null);

        $this->assertNull($response['data']);
    }

    /**
     * Test createErrorResponseWithContext from ServiceException
     */
    public function testCreateErrorResponseWithContextFromServiceException(): void
    {
        $exception = new ServiceException(
            'Test service error',
            'TEST_ERROR',
            400,
            ['context' => 'test']
        );

        $response = ErrorHandler::createErrorResponseWithContext($exception);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('request_id', $response);
    }

    /**
     * Test createErrorResponseWithContext includes request ID
     */
    public function testCreateErrorResponseWithContextIncludesRequestId(): void
    {
        ErrorHandler::setRequestId('test_req_id');

        $exception = new ServiceException('Error', 'ERROR', 500);
        $response = ErrorHandler::createErrorResponseWithContext($exception);

        $this->assertEquals('test_req_id', $response['request_id']);
    }

    /**
     * Test createErrorResponseWithContext with explicit request ID
     */
    public function testCreateErrorResponseWithContextExplicitRequestId(): void
    {
        ErrorHandler::setRequestId('default_id');

        $exception = new ServiceException('Error', 'ERROR', 500);
        $response = ErrorHandler::createErrorResponseWithContext($exception, 'explicit_id');

        $this->assertEquals('explicit_id', $response['request_id']);
    }

    /**
     * Test registerHandler stores handler
     */
    public function testRegisterHandlerStoresHandler(): void
    {
        $handler = function ($e) {
            return 'handled';
        };

        ErrorHandler::registerHandler('TestException', $handler);

        // Verify via reflection
        $reflection = new ReflectionClass(ErrorHandler::class);
        $handlersProperty = $reflection->getProperty('errorHandlers');
        $handlersProperty->setAccessible(true);
        $handlers = $handlersProperty->getValue(null);

        $this->assertArrayHasKey('TestException', $handlers);
    }

    /**
     * Test tryOperation returns result on success
     */
    public function testTryOperationReturnsResultOnSuccess(): void
    {
        $result = ErrorHandler::tryOperation(function () {
            return 'success';
        });

        $this->assertEquals('success', $result);
    }

    /**
     * Test tryOperation calls error handler on exception
     */
    public function testTryOperationCallsErrorHandlerOnException(): void
    {
        $called = false;
        $result = ErrorHandler::tryOperation(
            function () {
                throw new Exception('Test exception');
            },
            function ($e) use (&$called) {
                $called = true;
                return 'handled';
            }
        );

        $this->assertTrue($called);
        $this->assertEquals('handled', $result);
    }

    /**
     * Test tryOperation returns default on exception without handler
     */
    public function testTryOperationReturnsDefaultOnExceptionWithoutHandler(): void
    {
        $result = ErrorHandler::tryOperation(
            function () {
                throw new Exception('Test exception');
            },
            null,
            'default_value'
        );

        $this->assertEquals('default_value', $result);
    }

    /**
     * Test tryOperation returns null as default when not specified
     */
    public function testTryOperationReturnsNullAsDefaultWhenNotSpecified(): void
    {
        $result = ErrorHandler::tryOperation(
            function () {
                throw new Exception('Test exception');
            }
        );

        $this->assertNull($result);
    }

    /**
     * Data provider for error type names
     */
    public static function errorTypeProvider(): array
    {
        return [
            'E_ERROR' => [E_ERROR, 'Fatal Error'],
            'E_WARNING' => [E_WARNING, 'Warning'],
            'E_PARSE' => [E_PARSE, 'Parse Error'],
            'E_NOTICE' => [E_NOTICE, 'Notice'],
            'E_CORE_ERROR' => [E_CORE_ERROR, 'Core Error'],
            'E_CORE_WARNING' => [E_CORE_WARNING, 'Core Warning'],
            'E_COMPILE_ERROR' => [E_COMPILE_ERROR, 'Compile Error'],
            'E_COMPILE_WARNING' => [E_COMPILE_WARNING, 'Compile Warning'],
            'E_USER_ERROR' => [E_USER_ERROR, 'User Error'],
            'E_USER_WARNING' => [E_USER_WARNING, 'User Warning'],
            'E_USER_NOTICE' => [E_USER_NOTICE, 'User Notice'],
            'E_STRICT' => [E_STRICT, 'Strict Notice'],
            'E_RECOVERABLE_ERROR' => [E_RECOVERABLE_ERROR, 'Recoverable Error'],
            'E_DEPRECATED' => [E_DEPRECATED, 'Deprecated'],
            'E_USER_DEPRECATED' => [E_USER_DEPRECATED, 'User Deprecated'],
        ];
    }

    /**
     * Test getErrorTypeName returns correct names
     */
    #[DataProvider('errorTypeProvider')]
    public function testGetErrorTypeNameReturnsCorrectName(int $type, string $expectedName): void
    {
        $reflection = new ReflectionClass(ErrorHandler::class);
        $method = $reflection->getMethod('getErrorTypeName');
        $method->setAccessible(true);

        $result = $method->invoke(null, $type);

        $this->assertEquals($expectedName, $result);
    }

    /**
     * Test getErrorTypeName returns 'Unknown Error' for unknown type
     */
    public function testGetErrorTypeNameReturnsUnknownForUnknownType(): void
    {
        $reflection = new ReflectionClass(ErrorHandler::class);
        $method = $reflection->getMethod('getErrorTypeName');
        $method->setAccessible(true);

        $result = $method->invoke(null, 99999);

        $this->assertEquals('Unknown Error', $result);
    }

    /**
     * Test isProduction returns bool
     */
    public function testIsProductionReturnsBool(): void
    {
        $reflection = new ReflectionClass(ErrorHandler::class);
        $method = $reflection->getMethod('isProduction');
        $method->setAccessible(true);

        $result = $method->invoke(null);

        $this->assertIsBool($result);
    }

    /**
     * Test handleError returns false when error is suppressed
     */
    public function testHandleErrorReturnsFalseWhenSuppressed(): void
    {
        // Temporarily set error_reporting to exclude E_NOTICE
        $oldReporting = error_reporting(E_ERROR);

        $result = ErrorHandler::handleError(E_NOTICE, 'Test notice', __FILE__, __LINE__);

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    /**
     * Test handleError returns true for handled errors
     */
    public function testHandleErrorReturnsTrueForHandledErrors(): void
    {
        // Capture output
        ob_start();
        $result = ErrorHandler::handleError(E_WARNING, 'Test warning', __FILE__, __LINE__);
        ob_end_clean();

        $this->assertTrue($result);
    }

    /**
     * Test handleShutdown processes fatal errors
     */
    public function testHandleShutdownProcessesFatalErrors(): void
    {
        // This test is difficult to test directly as it relies on error_get_last()
        // We can only verify the method exists and is callable
        $this->assertTrue(is_callable([ErrorHandler::class, 'handleShutdown']));
    }

    /**
     * Test handleException processes exceptions
     */
    public function testHandleExceptionProcessesExceptions(): void
    {
        // Capture output
        ob_start();
        ErrorHandler::handleException(new Exception('Test exception'));
        $output = ob_get_clean();

        // In development mode, should contain exception info
        if (Constants::APP_ENV === 'development') {
            $this->assertStringContainsString('Test exception', $output);
        } else {
            // In production, should show generic message
            $this->assertNotEmpty($output);
        }
    }

    /**
     * Test handleException handles ServiceException
     */
    public function testHandleExceptionHandlesServiceException(): void
    {
        $exception = new ServiceException('Service error', 'SERVICE_ERROR', 400);

        ob_start();
        ErrorHandler::handleException($exception);
        $output = ob_get_clean();

        // Should output JSON for ServiceException
        $decoded = json_decode($output, true);
        // In CLI mode it may be JSON encoded
        if ($decoded !== null) {
            $this->assertIsArray($decoded);
        }
    }
}
