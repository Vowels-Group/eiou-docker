<?php
/**
 * Unit Tests for ErrorCodes
 *
 * Tests error code constants and utility methods.
 */

namespace Eiou\Tests\Core;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Core\ErrorCodes;

#[CoversClass(ErrorCodes::class)]
class ErrorCodesTest extends TestCase
{
    /**
     * Test getHttpStatus returns valid HTTP codes
     */
    public function testGetHttpStatusReturnsValidHttpCodes(): void
    {
        $this->assertEquals(400, ErrorCodes::getHttpStatus(ErrorCodes::VALIDATION_ERROR));
        $this->assertEquals(401, ErrorCodes::getHttpStatus(ErrorCodes::AUTHENTICATION_ERROR));
        $this->assertEquals(403, ErrorCodes::getHttpStatus(ErrorCodes::PERMISSION_DENIED));
        $this->assertEquals(404, ErrorCodes::getHttpStatus(ErrorCodes::NOT_FOUND));
        $this->assertEquals(409, ErrorCodes::getHttpStatus(ErrorCodes::WALLET_EXISTS));
        $this->assertEquals(429, ErrorCodes::getHttpStatus(ErrorCodes::RATE_LIMIT_EXCEEDED));
        $this->assertEquals(500, ErrorCodes::getHttpStatus(ErrorCodes::INTERNAL_ERROR));
        $this->assertEquals(503, ErrorCodes::getHttpStatus(ErrorCodes::CONTACT_UNREACHABLE));
        $this->assertEquals(504, ErrorCodes::getHttpStatus(ErrorCodes::TIMEOUT));
    }

    /**
     * Test getHttpStatus returns 500 for unknown codes
     */
    public function testGetHttpStatusReturns500ForUnknownCodes(): void
    {
        $this->assertEquals(500, ErrorCodes::getHttpStatus('UNKNOWN_CODE_XYZ'));
    }

    /**
     * Test getTitle returns human-readable titles
     */
    public function testGetTitleReturnsHumanReadableTitles(): void
    {
        $this->assertEquals('Validation Error', ErrorCodes::getTitle(ErrorCodes::VALIDATION_ERROR));
        $this->assertEquals('Not Found', ErrorCodes::getTitle(ErrorCodes::NOT_FOUND));
        $this->assertEquals('Authentication Error', ErrorCodes::getTitle(ErrorCodes::AUTHENTICATION_ERROR));
        $this->assertEquals('Rate Limit Exceeded', ErrorCodes::getTitle(ErrorCodes::RATE_LIMIT_EXCEEDED));
    }

    /**
     * Test getTitle returns formatted string for unknown codes
     */
    public function testGetTitleReturnsFormattedStringForUnknownCodes(): void
    {
        // Should convert UNKNOWN_CODE to "Unknown Code"
        $title = ErrorCodes::getTitle('UNKNOWN_CODE');
        $this->assertStringContainsString('Unknown', $title);
    }

    /**
     * Test isValid returns true for valid codes
     */
    public function testIsValidReturnsTrueForValidCodes(): void
    {
        $this->assertTrue(ErrorCodes::isValid(ErrorCodes::GENERAL_ERROR));
        $this->assertTrue(ErrorCodes::isValid(ErrorCodes::NOT_FOUND));
        $this->assertTrue(ErrorCodes::isValid(ErrorCodes::VALIDATION_ERROR));
    }

    /**
     * Test isValid returns false for invalid codes
     */
    public function testIsValidReturnsFalseForInvalidCodes(): void
    {
        $this->assertFalse(ErrorCodes::isValid('FAKE_ERROR_CODE'));
        $this->assertFalse(ErrorCodes::isValid(''));
    }

    /**
     * Test all returns array of constants
     */
    public function testAllReturnsArrayOfConstants(): void
    {
        $all = ErrorCodes::all();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('GENERAL_ERROR', $all);
        $this->assertArrayHasKey('NOT_FOUND', $all);
        $this->assertArrayHasKey('VALIDATION_ERROR', $all);
    }

    /**
     * Test all contains expected error codes
     */
    public function testAllContainsExpectedErrorCodes(): void
    {
        $all = ErrorCodes::all();

        // Check a sample of error codes
        $expectedCodes = [
            'GENERAL_ERROR',
            'VALIDATION_ERROR',
            'NOT_FOUND',
            'AUTHENTICATION_ERROR',
            'PERMISSION_DENIED',
            'RATE_LIMIT_EXCEEDED',
            'WALLET_NOT_FOUND',
            'CONTACT_NOT_FOUND',
            'TRANSACTION_FAILED',
            'INSUFFICIENT_FUNDS'
        ];

        foreach ($expectedCodes as $code) {
            $this->assertArrayHasKey($code, $all, "Missing error code: $code");
        }
    }

    /**
     * Test HTTP status code constants
     */
    public function testHttpStatusCodeConstants(): void
    {
        $this->assertEquals(200, ErrorCodes::HTTP_OK);
        $this->assertEquals(400, ErrorCodes::HTTP_BAD_REQUEST);
        $this->assertEquals(401, ErrorCodes::HTTP_UNAUTHORIZED);
        $this->assertEquals(403, ErrorCodes::HTTP_FORBIDDEN);
        $this->assertEquals(404, ErrorCodes::HTTP_NOT_FOUND);
        $this->assertEquals(409, ErrorCodes::HTTP_CONFLICT);
        $this->assertEquals(429, ErrorCodes::HTTP_TOO_MANY_REQUESTS);
        $this->assertEquals(500, ErrorCodes::HTTP_INTERNAL_SERVER_ERROR);
        $this->assertEquals(503, ErrorCodes::HTTP_SERVICE_UNAVAILABLE);
        $this->assertEquals(504, ErrorCodes::HTTP_GATEWAY_TIMEOUT);
    }

    /**
     * Test auth error codes map to 401
     */
    public function testAuthErrorCodesMapTo401(): void
    {
        $authCodes = [
            ErrorCodes::AUTH_REQUIRED,
            ErrorCodes::AUTH_INVALID,
            ErrorCodes::AUTH_EXPIRED,
            ErrorCodes::AUTH_MISSING_KEY,
            ErrorCodes::AUTH_INVALID_KEY,
            ErrorCodes::AUTH_INVALID_SIGNATURE
        ];

        foreach ($authCodes as $code) {
            $this->assertEquals(401, ErrorCodes::getHttpStatus($code), "Code $code should map to 401");
        }
    }

    /**
     * Test validation error codes map to 400
     */
    public function testValidationErrorCodesMapTo400(): void
    {
        $validationCodes = [
            ErrorCodes::VALIDATION_ERROR,
            ErrorCodes::INVALID_ADDRESS,
            ErrorCodes::INVALID_NAME,
            ErrorCodes::INVALID_AMOUNT,
            ErrorCodes::MISSING_PARAMS
        ];

        foreach ($validationCodes as $code) {
            $this->assertEquals(400, ErrorCodes::getHttpStatus($code), "Code $code should map to 400");
        }
    }

    /**
     * Test not found error codes map to 404
     */
    public function testNotFoundErrorCodesMapTo404(): void
    {
        $notFoundCodes = [
            ErrorCodes::NOT_FOUND,
            ErrorCodes::WALLET_NOT_FOUND,
            ErrorCodes::CONTACT_NOT_FOUND,
            ErrorCodes::API_KEY_NOT_FOUND,
            ErrorCodes::FILE_NOT_FOUND
        ];

        foreach ($notFoundCodes as $code) {
            $this->assertEquals(404, ErrorCodes::getHttpStatus($code), "Code $code should map to 404");
        }
    }

    /**
     * Test conflict error codes map to 409
     */
    public function testConflictErrorCodesMapTo409(): void
    {
        $conflictCodes = [
            ErrorCodes::WALLET_EXISTS,
            ErrorCodes::CONTACT_EXISTS
        ];

        foreach ($conflictCodes as $code) {
            $this->assertEquals(409, ErrorCodes::getHttpStatus($code), "Code $code should map to 409");
        }
    }

    /**
     * Test user-facing message constants
     */
    public function testUserFacingMessageConstants(): void
    {
        $this->assertNotEmpty(ErrorCodes::MESSAGE_GENERIC);
        $this->assertNotEmpty(ErrorCodes::MESSAGE_INVALID_INPUT);
        $this->assertNotEmpty(ErrorCodes::MESSAGE_UNAUTHORIZED);
        $this->assertNotEmpty(ErrorCodes::MESSAGE_RATE_LIMITED);
    }

    /**
     * Test getTitle handles wallet-related errors
     */
    public function testGetTitleHandlesWalletErrors(): void
    {
        $this->assertEquals('Wallet Already Exists', ErrorCodes::getTitle(ErrorCodes::WALLET_EXISTS));
        $this->assertEquals('Wallet Not Found', ErrorCodes::getTitle(ErrorCodes::WALLET_NOT_FOUND));
        $this->assertEquals('Invalid Seed Phrase', ErrorCodes::getTitle(ErrorCodes::INVALID_SEED_PHRASE));
    }

    /**
     * Test getTitle handles transaction-related errors
     */
    public function testGetTitleHandlesTransactionErrors(): void
    {
        $this->assertEquals('Transaction Failed', ErrorCodes::getTitle(ErrorCodes::TRANSACTION_FAILED));
        $this->assertEquals('Insufficient Funds', ErrorCodes::getTitle(ErrorCodes::INSUFFICIENT_FUNDS));
        $this->assertEquals('Invalid Amount', ErrorCodes::getTitle(ErrorCodes::INVALID_AMOUNT));
    }

    /**
     * Test getTitle handles contact-related errors
     */
    public function testGetTitleHandlesContactErrors(): void
    {
        $this->assertEquals('Contact Not Found', ErrorCodes::getTitle(ErrorCodes::CONTACT_NOT_FOUND));
        $this->assertEquals('Contact Already Exists', ErrorCodes::getTitle(ErrorCodes::CONTACT_EXISTS));
        $this->assertEquals('Contact Blocked', ErrorCodes::getTitle(ErrorCodes::CONTACT_BLOCKED));
    }
}
