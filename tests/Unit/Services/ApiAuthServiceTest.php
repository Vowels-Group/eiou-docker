<?php
/**
 * Unit Tests for ApiAuthService
 *
 * Tests HMAC signature generation and string-to-sign building.
 * Note: Full authentication tests require mocked ApiKeyRepository.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\ApiAuthService;

#[CoversClass(ApiAuthService::class)]
class ApiAuthServiceTest extends TestCase
{
    /**
     * Test buildStringToSign creates correct format
     */
    public function testBuildStringToSignCreatesCorrectFormat(): void
    {
        $mockRepo = $this->createMock(\Eiou\Database\ApiKeyRepository::class);
        $service = new ApiAuthService($mockRepo);

        $stringToSign = $service->buildStringToSign(
            'POST',
            '/api/v1/wallet/send',
            '1234567890',
            '{"amount":100}'
        );

        $expected = "POST\n/api/v1/wallet/send\n1234567890\n{\"amount\":100}";
        $this->assertEquals($expected, $stringToSign);
    }

    /**
     * Test buildStringToSign normalizes method to uppercase
     */
    public function testBuildStringToSignNormalizesMethod(): void
    {
        $mockRepo = $this->createMock(\Eiou\Database\ApiKeyRepository::class);
        $service = new ApiAuthService($mockRepo);

        $stringToSign = $service->buildStringToSign(
            'get',
            '/api/v1/wallet/balance',
            '1234567890',
            ''
        );

        $this->assertStringStartsWith('GET', $stringToSign);
    }

    /**
     * Test buildStringToSign handles empty body
     */
    public function testBuildStringToSignHandlesEmptyBody(): void
    {
        $mockRepo = $this->createMock(\Eiou\Database\ApiKeyRepository::class);
        $service = new ApiAuthService($mockRepo);

        $stringToSign = $service->buildStringToSign(
            'GET',
            '/api/v1/wallet/balance',
            '1234567890',
            ''
        );

        $expected = "GET\n/api/v1/wallet/balance\n1234567890\n";
        $this->assertEquals($expected, $stringToSign);
    }

    /**
     * Test generateSignature returns hex string
     */
    public function testGenerateSignatureReturnsHexString(): void
    {
        $signature = ApiAuthService::generateSignature(
            'my-secret-key',
            'POST',
            '/api/v1/test',
            '1234567890',
            '{"data":"test"}'
        );

        // HMAC-SHA256 produces 64 hex characters
        $this->assertEquals(64, strlen($signature));
        $this->assertTrue(ctype_xdigit($signature));
    }

    /**
     * Test generateSignature is deterministic
     */
    public function testGenerateSignatureIsDeterministic(): void
    {
        $sig1 = ApiAuthService::generateSignature(
            'secret',
            'POST',
            '/path',
            '12345',
            'body'
        );

        $sig2 = ApiAuthService::generateSignature(
            'secret',
            'POST',
            '/path',
            '12345',
            'body'
        );

        $this->assertEquals($sig1, $sig2);
    }

    /**
     * Test generateSignature differs with different secrets
     */
    public function testGenerateSignatureDiffersWithDifferentSecrets(): void
    {
        $sig1 = ApiAuthService::generateSignature(
            'secret1',
            'POST',
            '/path',
            '12345',
            'body'
        );

        $sig2 = ApiAuthService::generateSignature(
            'secret2',
            'POST',
            '/path',
            '12345',
            'body'
        );

        $this->assertNotEquals($sig1, $sig2);
    }

    /**
     * Test generateSignature differs with different methods
     */
    public function testGenerateSignatureDiffersWithDifferentMethods(): void
    {
        $sig1 = ApiAuthService::generateSignature(
            'secret',
            'GET',
            '/path',
            '12345',
            ''
        );

        $sig2 = ApiAuthService::generateSignature(
            'secret',
            'POST',
            '/path',
            '12345',
            ''
        );

        $this->assertNotEquals($sig1, $sig2);
    }

    /**
     * Test generateSignature differs with different paths
     */
    public function testGenerateSignatureDiffersWithDifferentPaths(): void
    {
        $sig1 = ApiAuthService::generateSignature(
            'secret',
            'GET',
            '/api/v1/balance',
            '12345',
            ''
        );

        $sig2 = ApiAuthService::generateSignature(
            'secret',
            'GET',
            '/api/v1/transactions',
            '12345',
            ''
        );

        $this->assertNotEquals($sig1, $sig2);
    }

    /**
     * Test generateSignature differs with different timestamps
     */
    public function testGenerateSignatureDiffersWithDifferentTimestamps(): void
    {
        $sig1 = ApiAuthService::generateSignature(
            'secret',
            'GET',
            '/path',
            '1000000000',
            ''
        );

        $sig2 = ApiAuthService::generateSignature(
            'secret',
            'GET',
            '/path',
            '1000000001',
            ''
        );

        $this->assertNotEquals($sig1, $sig2);
    }

    /**
     * Test generateSignature differs with different bodies
     */
    public function testGenerateSignatureDiffersWithDifferentBodies(): void
    {
        $sig1 = ApiAuthService::generateSignature(
            'secret',
            'POST',
            '/path',
            '12345',
            '{"amount":100}'
        );

        $sig2 = ApiAuthService::generateSignature(
            'secret',
            'POST',
            '/path',
            '12345',
            '{"amount":200}'
        );

        $this->assertNotEquals($sig1, $sig2);
    }

    /**
     * Test generateSignature handles empty body
     */
    public function testGenerateSignatureHandlesEmptyBody(): void
    {
        $signature = ApiAuthService::generateSignature(
            'secret',
            'GET',
            '/path',
            '12345'
            // No body parameter - defaults to empty string
        );

        $this->assertEquals(64, strlen($signature));
    }

    /**
     * Test getClientIp returns string
     */
    public function testGetClientIpReturnsString(): void
    {
        $ip = ApiAuthService::getClientIp();

        $this->assertIsString($ip);
    }

    /**
     * Test getClientIp returns default when no server vars
     */
    public function testGetClientIpReturnsDefaultWhenNoServerVars(): void
    {
        // Clear relevant $_SERVER variables
        $backup = $_SERVER;
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_CLIENT_IP']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['REMOTE_ADDR']);

        $ip = ApiAuthService::getClientIp();

        $_SERVER = $backup;

        $this->assertEquals('0.0.0.0', $ip);
    }

    /**
     * Test getRequestHeaders returns array
     */
    public function testGetRequestHeadersReturnsArray(): void
    {
        $headers = ApiAuthService::getRequestHeaders();

        $this->assertIsArray($headers);
    }
}
