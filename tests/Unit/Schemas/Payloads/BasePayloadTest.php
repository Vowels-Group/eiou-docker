<?php
/**
 * Unit Tests for BasePayload
 *
 * Tests the abstract base payload class functionality including field validation,
 * string sanitization, number sanitization, and payload validation.
 *
 * Uses a ConcreteTestPayload class to test protected methods of the abstract base.
 */

namespace Eiou\Tests\Schemas\Payloads;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Schemas\Payloads\BasePayload;
use Eiou\Core\UserContext;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;

/**
 * Concrete implementation of BasePayload for testing protected methods
 */
class ConcreteTestPayload extends BasePayload
{
    /**
     * Implementation of abstract build method
     */
    public function build(array $data): array
    {
        return $data;
    }

    /**
     * Expose ensureRequiredFields for testing
     */
    public function testEnsureRequiredFields(array $data, array $requiredFields): void
    {
        $this->ensureRequiredFields($data, $requiredFields);
    }

    /**
     * Expose sanitizeString for testing
     */
    public function testSanitizeString($value): string
    {
        return $this->sanitizeString($value);
    }

    /**
     * Expose sanitizeNumber for testing
     */
    public function testSanitizeNumber($value)
    {
        return $this->sanitizeNumber($value);
    }

    /**
     * Expose validate for testing
     */
    public function testValidate(array $payload): void
    {
        $this->validate($payload);
    }
}

#[CoversClass(BasePayload::class)]
class BasePayloadTest extends TestCase
{
    private ConcreteTestPayload $payload;
    private UserContext $mockUserContext;
    private UtilityServiceContainer $mockUtilityContainer;

    protected function setUp(): void
    {
        // Create mock UserContext
        $this->mockUserContext = $this->createMock(UserContext::class);

        // Create mock utility services
        $mockCurrencyUtility = $this->createMock(CurrencyUtilityService::class);
        $mockTimeUtility = $this->createMock(TimeUtilityService::class);
        $mockValidationUtility = $this->createMock(ValidationUtilityService::class);
        $mockTransportUtility = $this->createMock(TransportUtilityService::class);

        // Create mock UtilityServiceContainer
        $this->mockUtilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->mockUtilityContainer->method('getCurrencyUtility')->willReturn($mockCurrencyUtility);
        $this->mockUtilityContainer->method('getTimeUtility')->willReturn($mockTimeUtility);
        $this->mockUtilityContainer->method('getValidationUtility')->willReturn($mockValidationUtility);
        $this->mockUtilityContainer->method('getTransportUtility')->willReturn($mockTransportUtility);

        // Create concrete test payload instance
        $this->payload = new ConcreteTestPayload(
            $this->mockUserContext,
            $this->mockUtilityContainer
        );
    }

    // =========================================================================
    // ensureRequiredFields() Tests
    // =========================================================================

    /**
     * Test ensureRequiredFields passes when all required fields are present
     */
    public function testEnsureRequiredFieldsPassesWithAllFieldsPresent(): void
    {
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'amount' => 100
        ];
        $requiredFields = ['name', 'email', 'amount'];

        // Should not throw an exception
        $this->payload->testEnsureRequiredFields($data, $requiredFields);

        // If we get here, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test ensureRequiredFields passes with subset of required fields
     */
    public function testEnsureRequiredFieldsPassesWithSubsetRequired(): void
    {
        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'amount' => 100,
            'extra' => 'field'
        ];
        $requiredFields = ['name', 'email'];

        // Should not throw an exception
        $this->payload->testEnsureRequiredFields($data, $requiredFields);

        $this->assertTrue(true);
    }

    /**
     * Test ensureRequiredFields passes with empty required fields array
     */
    public function testEnsureRequiredFieldsPassesWithEmptyRequirements(): void
    {
        $data = ['anything' => 'value'];
        $requiredFields = [];

        // Should not throw an exception
        $this->payload->testEnsureRequiredFields($data, $requiredFields);

        $this->assertTrue(true);
    }

    /**
     * Test ensureRequiredFields throws InvalidArgumentException for missing field
     */
    public function testEnsureRequiredFieldsThrowsForMissingField(): void
    {
        $data = [
            'name' => 'John Doe'
        ];
        $requiredFields = ['name', 'email'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'email' is missing from payload data");

        $this->payload->testEnsureRequiredFields($data, $requiredFields);
    }

    /**
     * Test ensureRequiredFields throws for first missing field when multiple missing
     */
    public function testEnsureRequiredFieldsThrowsForFirstMissingField(): void
    {
        $data = [];
        $requiredFields = ['field1', 'field2', 'field3'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'field1' is missing from payload data");

        $this->payload->testEnsureRequiredFields($data, $requiredFields);
    }

    /**
     * Test ensureRequiredFields treats null values as missing
     */
    public function testEnsureRequiredFieldsTreatsNullAsMissing(): void
    {
        $data = [
            'name' => 'John Doe',
            'email' => null
        ];
        $requiredFields = ['name', 'email'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required field 'email' is missing from payload data");

        $this->payload->testEnsureRequiredFields($data, $requiredFields);
    }

    /**
     * Test ensureRequiredFields allows empty string values (they are set)
     */
    public function testEnsureRequiredFieldsAllowsEmptyStringValues(): void
    {
        $data = [
            'name' => '',
            'email' => 'john@example.com'
        ];
        $requiredFields = ['name', 'email'];

        // Empty string is still "set", so should not throw
        $this->payload->testEnsureRequiredFields($data, $requiredFields);

        $this->assertTrue(true);
    }

    /**
     * Test ensureRequiredFields allows zero values (they are set)
     */
    public function testEnsureRequiredFieldsAllowsZeroValues(): void
    {
        $data = [
            'name' => 'John',
            'amount' => 0
        ];
        $requiredFields = ['name', 'amount'];

        // Zero is still "set", so should not throw
        $this->payload->testEnsureRequiredFields($data, $requiredFields);

        $this->assertTrue(true);
    }

    /**
     * Test ensureRequiredFields allows false values (they are set)
     */
    public function testEnsureRequiredFieldsAllowsFalseValues(): void
    {
        $data = [
            'name' => 'John',
            'active' => false
        ];
        $requiredFields = ['name', 'active'];

        // False is still "set", so should not throw
        $this->payload->testEnsureRequiredFields($data, $requiredFields);

        $this->assertTrue(true);
    }

    // =========================================================================
    // sanitizeString() Tests
    // =========================================================================

    /**
     * Test sanitizeString trims leading whitespace
     */
    public function testSanitizeStringTrimsLeadingWhitespace(): void
    {
        $result = $this->payload->testSanitizeString('   hello');

        $this->assertEquals('hello', $result);
    }

    /**
     * Test sanitizeString trims trailing whitespace
     */
    public function testSanitizeStringTrimsTrailingWhitespace(): void
    {
        $result = $this->payload->testSanitizeString('hello   ');

        $this->assertEquals('hello', $result);
    }

    /**
     * Test sanitizeString trims both leading and trailing whitespace
     */
    public function testSanitizeStringTrimsBothWhitespace(): void
    {
        $result = $this->payload->testSanitizeString('   hello world   ');

        $this->assertEquals('hello world', $result);
    }

    /**
     * Test sanitizeString preserves internal whitespace
     */
    public function testSanitizeStringPreservesInternalWhitespace(): void
    {
        $result = $this->payload->testSanitizeString('hello   world');

        $this->assertEquals('hello   world', $result);
    }

    /**
     * Test sanitizeString converts integer to string
     */
    public function testSanitizeStringConvertsIntegerToString(): void
    {
        $result = $this->payload->testSanitizeString(123);

        $this->assertIsString($result);
        $this->assertEquals('123', $result);
    }

    /**
     * Test sanitizeString converts float to string
     */
    public function testSanitizeStringConvertsFloatToString(): void
    {
        $result = $this->payload->testSanitizeString(123.456);

        $this->assertIsString($result);
        $this->assertEquals('123.456', $result);
    }

    /**
     * Test sanitizeString converts boolean true to string
     */
    public function testSanitizeStringConvertsBooleanTrueToString(): void
    {
        $result = $this->payload->testSanitizeString(true);

        $this->assertIsString($result);
        $this->assertEquals('1', $result);
    }

    /**
     * Test sanitizeString converts boolean false to empty string
     */
    public function testSanitizeStringConvertsBooleanFalseToEmptyString(): void
    {
        $result = $this->payload->testSanitizeString(false);

        $this->assertIsString($result);
        $this->assertEquals('', $result);
    }

    /**
     * Test sanitizeString handles empty string
     */
    public function testSanitizeStringHandlesEmptyString(): void
    {
        $result = $this->payload->testSanitizeString('');

        $this->assertIsString($result);
        $this->assertEquals('', $result);
    }

    /**
     * Test sanitizeString handles whitespace-only string
     */
    public function testSanitizeStringHandlesWhitespaceOnlyString(): void
    {
        $result = $this->payload->testSanitizeString('   ');

        $this->assertEquals('', $result);
    }

    /**
     * Test sanitizeString handles newlines and tabs
     */
    public function testSanitizeStringTrimsNewlinesAndTabs(): void
    {
        $result = $this->payload->testSanitizeString("\n\t  hello  \t\n");

        $this->assertEquals('hello', $result);
    }

    /**
     * Test sanitizeString handles zero as integer
     */
    public function testSanitizeStringConvertsZeroToString(): void
    {
        $result = $this->payload->testSanitizeString(0);

        $this->assertIsString($result);
        $this->assertEquals('0', $result);
    }

    /**
     * Test sanitizeString handles negative numbers
     */
    public function testSanitizeStringConvertsNegativeNumberToString(): void
    {
        $result = $this->payload->testSanitizeString(-42);

        $this->assertIsString($result);
        $this->assertEquals('-42', $result);
    }

    // =========================================================================
    // sanitizeNumber() Tests
    // =========================================================================

    /**
     * Test sanitizeNumber returns int for integer input
     */
    public function testSanitizeNumberReturnsIntForInteger(): void
    {
        $result = $this->payload->testSanitizeNumber(42);

        $this->assertIsInt($result);
        $this->assertEquals(42, $result);
    }

    /**
     * Test sanitizeNumber returns int for zero
     */
    public function testSanitizeNumberReturnsIntForZero(): void
    {
        $result = $this->payload->testSanitizeNumber(0);

        $this->assertIsInt($result);
        $this->assertEquals(0, $result);
    }

    /**
     * Test sanitizeNumber returns int for negative integer
     */
    public function testSanitizeNumberReturnsIntForNegativeInteger(): void
    {
        $result = $this->payload->testSanitizeNumber(-100);

        $this->assertIsInt($result);
        $this->assertEquals(-100, $result);
    }

    /**
     * Test sanitizeNumber returns float for float input
     */
    public function testSanitizeNumberReturnsFloatForFloat(): void
    {
        $result = $this->payload->testSanitizeNumber(3.14);

        $this->assertIsFloat($result);
        $this->assertEquals(3.14, $result);
    }

    /**
     * Test sanitizeNumber returns float for negative float
     */
    public function testSanitizeNumberReturnsFloatForNegativeFloat(): void
    {
        $result = $this->payload->testSanitizeNumber(-2.5);

        $this->assertIsFloat($result);
        $this->assertEquals(-2.5, $result);
    }

    /**
     * Test sanitizeNumber returns float for zero as float
     */
    public function testSanitizeNumberReturnsFloatForZeroFloat(): void
    {
        $result = $this->payload->testSanitizeNumber(0.0);

        $this->assertIsFloat($result);
        $this->assertEquals(0.0, $result);
    }

    /**
     * Test sanitizeNumber handles numeric string as float
     */
    public function testSanitizeNumberHandlesNumericString(): void
    {
        $result = $this->payload->testSanitizeNumber('42.5');

        // Numeric strings are not integers, so they become floats
        $this->assertIsFloat($result);
        $this->assertEquals(42.5, $result);
    }

    /**
     * Test sanitizeNumber handles integer-like numeric string as float
     */
    public function testSanitizeNumberHandlesIntegerLikeNumericString(): void
    {
        $result = $this->payload->testSanitizeNumber('100');

        // Numeric strings are not true integers (is_int returns false)
        $this->assertIsFloat($result);
        $this->assertEquals(100.0, $result);
    }

    /**
     * Test sanitizeNumber throws for non-numeric string
     */
    public function testSanitizeNumberThrowsForNonNumericString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be numeric, got: string');

        $this->payload->testSanitizeNumber('hello');
    }

    /**
     * Test sanitizeNumber throws for empty string
     */
    public function testSanitizeNumberThrowsForEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be numeric, got: string');

        $this->payload->testSanitizeNumber('');
    }

    /**
     * Test sanitizeNumber throws for boolean true
     */
    public function testSanitizeNumberThrowsForBooleanTrue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be numeric, got: boolean');

        $this->payload->testSanitizeNumber(true);
    }

    /**
     * Test sanitizeNumber throws for boolean false
     */
    public function testSanitizeNumberThrowsForBooleanFalse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be numeric, got: boolean');

        $this->payload->testSanitizeNumber(false);
    }

    /**
     * Test sanitizeNumber throws for array
     */
    public function testSanitizeNumberThrowsForArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be numeric, got: array');

        $this->payload->testSanitizeNumber([1, 2, 3]);
    }

    /**
     * Test sanitizeNumber throws for null
     */
    public function testSanitizeNumberThrowsForNull(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Value must be numeric, got: NULL');

        $this->payload->testSanitizeNumber(null);
    }

    /**
     * Test sanitizeNumber handles large integers
     */
    public function testSanitizeNumberHandlesLargeIntegers(): void
    {
        $largeInt = PHP_INT_MAX;
        $result = $this->payload->testSanitizeNumber($largeInt);

        $this->assertIsInt($result);
        $this->assertEquals(PHP_INT_MAX, $result);
    }

    /**
     * Test sanitizeNumber handles very small floats
     */
    public function testSanitizeNumberHandlesVerySmallFloats(): void
    {
        $smallFloat = 0.000001;
        $result = $this->payload->testSanitizeNumber($smallFloat);

        $this->assertIsFloat($result);
        $this->assertEquals(0.000001, $result);
    }

    /**
     * Test sanitizeNumber handles scientific notation string
     */
    public function testSanitizeNumberHandlesScientificNotationString(): void
    {
        $result = $this->payload->testSanitizeNumber('1.5e3');

        $this->assertIsFloat($result);
        $this->assertEquals(1500.0, $result);
    }

    // =========================================================================
    // validate() Tests
    // =========================================================================

    /**
     * Test validate throws for empty payload
     */
    public function testValidateThrowsForEmptyPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Payload cannot be empty');

        $this->payload->testValidate([]);
    }

    /**
     * Test validate passes for non-empty payload
     */
    public function testValidatePassesForNonEmptyPayload(): void
    {
        // Should not throw an exception
        $this->payload->testValidate(['key' => 'value']);

        $this->assertTrue(true);
    }

    /**
     * Test validate passes for payload with multiple keys
     */
    public function testValidatePassesForPayloadWithMultipleKeys(): void
    {
        $payload = [
            'name' => 'John',
            'email' => 'john@example.com',
            'amount' => 100
        ];

        // Should not throw an exception
        $this->payload->testValidate($payload);

        $this->assertTrue(true);
    }

    /**
     * Test validate passes for payload with nested arrays
     */
    public function testValidatePassesForPayloadWithNestedArrays(): void
    {
        $payload = [
            'user' => [
                'name' => 'John',
                'address' => [
                    'city' => 'New York'
                ]
            ]
        ];

        // Should not throw an exception
        $this->payload->testValidate($payload);

        $this->assertTrue(true);
    }

    /**
     * Test validate passes for payload with null value
     */
    public function testValidatePassesForPayloadWithNullValue(): void
    {
        $payload = ['key' => null];

        // Payload has a key, so it's not empty
        $this->payload->testValidate($payload);

        $this->assertTrue(true);
    }

    /**
     * Test validate passes for payload with false value
     */
    public function testValidatePassesForPayloadWithFalseValue(): void
    {
        $payload = ['active' => false];

        // Payload has a key, so it's not empty
        $this->payload->testValidate($payload);

        $this->assertTrue(true);
    }

    /**
     * Test validate passes for payload with zero value
     */
    public function testValidatePassesForPayloadWithZeroValue(): void
    {
        $payload = ['count' => 0];

        // Payload has a key, so it's not empty
        $this->payload->testValidate($payload);

        $this->assertTrue(true);
    }

    /**
     * Test validate passes for payload with empty string value
     */
    public function testValidatePassesForPayloadWithEmptyStringValue(): void
    {
        $payload = ['name' => ''];

        // Payload has a key, so it's not empty
        $this->payload->testValidate($payload);

        $this->assertTrue(true);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test constructor properly initializes dependencies
     */
    public function testConstructorInitializesDependencies(): void
    {
        // If we get here without exceptions, constructor worked
        $this->assertInstanceOf(ConcreteTestPayload::class, $this->payload);
    }

    /**
     * Test build method is callable on concrete implementation
     */
    public function testBuildMethodIsCallable(): void
    {
        $data = ['test' => 'data'];
        $result = $this->payload->build($data);

        $this->assertEquals($data, $result);
    }
}
