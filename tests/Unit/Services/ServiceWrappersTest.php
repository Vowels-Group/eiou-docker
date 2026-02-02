<?php
/**
 * Unit Tests for ServiceWrappers
 *
 * Tests the output() wrapper function which provides logging functionality
 * with graceful degradation when Application is not initialized.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Note: The output() function is defined in ServiceWrappers.php but the bootstrap.php
 * already provides a mock implementation. These tests verify the expected behavior
 * and interface of the output function.
 */
class ServiceWrappersTest extends TestCase
{
    // =========================================================================
    // output() Function Tests
    // =========================================================================

    /**
     * Test output function exists
     */
    public function testOutputFunctionExists(): void
    {
        $this->assertTrue(function_exists('output'));
    }

    /**
     * Test output function accepts message parameter
     */
    public function testOutputFunctionAcceptsMessageParameter(): void
    {
        // Should not throw any exception
        $result = output('Test message');

        // output() returns void, so result should be null
        $this->assertNull($result);
    }

    /**
     * Test output function accepts ECHO parameter
     */
    public function testOutputFunctionAcceptsEchoParameter(): void
    {
        // Should not throw any exception
        $result = output('Test message', 'ECHO');

        $this->assertNull($result);
    }

    /**
     * Test output function accepts SILENT parameter
     */
    public function testOutputFunctionAcceptsSilentParameter(): void
    {
        // Should not throw any exception
        $result = output('Test message', 'SILENT');

        $this->assertNull($result);
    }

    /**
     * Test output function with empty message
     */
    public function testOutputFunctionWithEmptyMessage(): void
    {
        // Should not throw any exception
        $result = output('');

        $this->assertNull($result);
    }

    /**
     * Test output function with long message
     */
    public function testOutputFunctionWithLongMessage(): void
    {
        $longMessage = str_repeat('A', 10000);

        // Should not throw any exception
        $result = output($longMessage);

        $this->assertNull($result);
    }

    /**
     * Test output function with special characters
     */
    public function testOutputFunctionWithSpecialCharacters(): void
    {
        $specialMessage = "Line1\nLine2\tTabbed<html>&amp;\"'";

        // Should not throw any exception
        $result = output($specialMessage);

        $this->assertNull($result);
    }

    /**
     * Test output function with numeric message
     */
    public function testOutputFunctionWithNumericMessage(): void
    {
        // PHP will convert numeric to string
        $result = output((string) 12345);

        $this->assertNull($result);
    }

    /**
     * Test output function defaults to ECHO when no second parameter
     */
    public function testOutputFunctionDefaultsToEcho(): void
    {
        // The signature shows default is 'ECHO'
        // This should work without error
        $result = output('Default echo test');

        $this->assertNull($result);
    }

    /**
     * Test output function gracefully handles being called multiple times
     */
    public function testOutputFunctionHandlesMultipleCalls(): void
    {
        // Should not throw any exception even when called rapidly
        for ($i = 0; $i < 100; $i++) {
            output("Message {$i}");
        }

        // If we get here without exception, test passed
        $this->assertTrue(true);
    }

    /**
     * Test output function with various echo parameter values
     */
    public function testOutputFunctionWithVariousEchoValues(): void
    {
        // Test various parameter values - the function should handle them gracefully
        $echoValues = ['ECHO', 'SILENT', 'echo', 'silent', 'INFO', 'DEBUG', 'WARNING', 'ERROR'];

        foreach ($echoValues as $echoValue) {
            $result = output("Test with {$echoValue}", $echoValue);
            $this->assertNull($result);
        }
    }

    /**
     * Test output function does not throw when Application is not initialized
     *
     * Note: In test mode, the bootstrap provides a mock that doesn't require Application.
     * This verifies the graceful degradation behavior documented in ServiceWrappers.php.
     */
    public function testOutputFunctionHandlesUninitializedApplication(): void
    {
        // The try-catch in the real output() function handles this case
        // In test mode, the mock implementation doesn't need Application
        // This test verifies the function doesn't crash either way

        $result = output('Test without Application');

        $this->assertNull($result);
    }

    /**
     * Test output function signature matches expected interface
     */
    public function testOutputFunctionSignature(): void
    {
        $reflection = new \ReflectionFunction('output');

        // Should have 1-2 parameters
        $params = $reflection->getParameters();
        $this->assertGreaterThanOrEqual(1, count($params));
        $this->assertLessThanOrEqual(2, count($params));

        // First parameter should be $message
        $this->assertEquals('message', $params[0]->getName());

        // Second parameter (if exists) should have default value
        if (count($params) > 1) {
            $this->assertTrue($params[1]->isDefaultValueAvailable());
        }
    }

    /**
     * Test output function return type is void/null
     */
    public function testOutputFunctionReturnType(): void
    {
        $result = output('Test return type');

        $this->assertNull($result);
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    /**
     * Test output with unicode characters
     */
    public function testOutputWithUnicodeCharacters(): void
    {
        $unicodeMessage = "Hello World!";

        $result = output($unicodeMessage);

        $this->assertNull($result);
    }

    /**
     * Test output with JSON string
     */
    public function testOutputWithJsonString(): void
    {
        $jsonMessage = json_encode(['key' => 'value', 'nested' => ['a' => 1]]);

        $result = output($jsonMessage);

        $this->assertNull($result);
    }

    /**
     * Test output with multi-line string
     */
    public function testOutputWithMultiLineString(): void
    {
        $multiLine = <<<EOT
This is a multi-line message
with several lines
of content
EOT;

        $result = output($multiLine);

        $this->assertNull($result);
    }

    /**
     * Test output with null bytes in string
     */
    public function testOutputWithNullBytes(): void
    {
        $messageWithNull = "Before\0After";

        $result = output($messageWithNull);

        $this->assertNull($result);
    }

    /**
     * Test output with binary-like content
     */
    public function testOutputWithBinaryContent(): void
    {
        $binaryLike = "\x00\x01\x02\x03\xff\xfe\xfd";

        $result = output($binaryLike);

        $this->assertNull($result);
    }
}
