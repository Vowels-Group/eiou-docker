<?php
/**
 * Unit Tests for Low-Priority Security Findings (L-1 through L-35)
 *
 * Tests security hardening applied across the codebase to address
 * low-priority findings from the security audit.
 */

namespace Eiou\Tests\Security;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

// L-1, L-2: Column whitelists
use Eiou\Database\TransactionStatisticsRepository;
use Eiou\Database\TransactionChainRepository;

// L-3, L-4: QueryBuilder
use Eiou\Database\Traits\QueryBuilder;

// L-9: API key self-deletion
use Eiou\Services\ApiAuthService;

// L-17: Signature max length
use Eiou\Utils\InputValidator;
use Eiou\Core\Constants;

// L-18: Tor regex
use Eiou\Utils\AddressValidator;

// L-19: sanitizeString
use Eiou\Schemas\Payloads\BasePayload;

// L-21, L-22: BIP39 crypto
use Eiou\Security\BIP39;

// L-22: TorKeyDerivation
use Eiou\Security\TorKeyDerivation;

// L-25: UserContext sensitive keys
use Eiou\Core\UserContext;

// L-35: Lock name hashing
use Eiou\Services\DatabaseLockingService;

#[CoversClass(TransactionStatisticsRepository::class)]
#[CoversClass(TransactionChainRepository::class)]
#[CoversClass(InputValidator::class)]
#[CoversClass(AddressValidator::class)]
#[CoversClass(BIP39::class)]
#[CoversClass(TorKeyDerivation::class)]
#[CoversClass(UserContext::class)]
#[CoversClass(DatabaseLockingService::class)]
class LowFindingsTest extends TestCase
{
    // =========================================================================
    // L-1: TransactionStatisticsRepository column whitelist
    // =========================================================================

    public function testTransactionStatisticsRepositoryHasAllowedColumns(): void
    {
        $reflection = new ReflectionClass(TransactionStatisticsRepository::class);
        $this->assertTrue(
            $reflection->hasProperty('allowedColumns'),
            'TransactionStatisticsRepository must define $allowedColumns'
        );

        $property = $reflection->getProperty('allowedColumns');
        $property->setAccessible(true);

        // Create instance without invoking constructor (needs PDO)
        $instance = $reflection->newInstanceWithoutConstructor();
        $columns = $property->getValue($instance);

        $this->assertIsArray($columns);
        $this->assertNotEmpty($columns, 'allowedColumns must not be empty');
        $this->assertContains('txid', $columns);
        $this->assertContains('amount', $columns);
        $this->assertContains('timestamp', $columns);
    }

    // =========================================================================
    // L-2: TransactionChainRepository column whitelist
    // =========================================================================

    public function testTransactionChainRepositoryHasAllowedColumns(): void
    {
        $reflection = new ReflectionClass(TransactionChainRepository::class);
        $this->assertTrue(
            $reflection->hasProperty('allowedColumns'),
            'TransactionChainRepository must define $allowedColumns'
        );

        $property = $reflection->getProperty('allowedColumns');
        $property->setAccessible(true);

        $instance = $reflection->newInstanceWithoutConstructor();
        $columns = $property->getValue($instance);

        $this->assertIsArray($columns);
        $this->assertNotEmpty($columns, 'allowedColumns must not be empty');
        $this->assertContains('txid', $columns);
        $this->assertContains('amount', $columns);
    }

    // =========================================================================
    // L-3: QueryBuilder blocks numeric key raw SQL
    // =========================================================================

    public function testQueryBuilderBlocksNumericKeyRawSql(): void
    {
        $builder = $this->createQueryBuilderTestClass();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Raw SQL conditions via numeric keys are not allowed');

        $method = new ReflectionMethod($builder, 'buildWhereClause');
        $method->setAccessible(true);
        // Numeric key with raw SQL string - should be blocked
        $method->invoke($builder, [0 => "1=1; DROP TABLE transactions"]);
    }

    public function testQueryBuilderAllowsAssociativeKeyConditions(): void
    {
        $builder = $this->createQueryBuilderTestClass();

        $method = new ReflectionMethod($builder, 'buildWhereClause');
        $method->setAccessible(true);
        $result = $method->invoke($builder, ['status' => 'active', 'amount >' => 100]);

        $this->assertStringContainsString('status = ?', $result);
        $this->assertStringContainsString('amount > ?', $result);
    }

    // =========================================================================
    // L-4: QueryBuilder ORDER BY column validation
    // =========================================================================

    public function testQueryBuilderOrderBySkipsInvalidColumns(): void
    {
        $builder = $this->createQueryBuilderTestClass(['txid', 'amount', 'timestamp']);

        $method = new ReflectionMethod($builder, 'buildOrderByClause');
        $method->setAccessible(true);

        // Mix of valid and invalid columns
        $result = $method->invoke($builder, ['txid', 'injected_column']);

        $this->assertStringContainsString('txid', $result);
        $this->assertStringNotContainsString('injected_column', $result);
    }

    public function testQueryBuilderOrderByValidatesDirectionColumns(): void
    {
        $builder = $this->createQueryBuilderTestClass(['txid', 'amount']);

        $method = new ReflectionMethod($builder, 'buildOrderByClause');
        $method->setAccessible(true);

        $result = $method->invoke($builder, ['txid' => 'DESC', 'evil_col' => 'ASC']);

        $this->assertStringContainsString('txid DESC', $result);
        $this->assertStringNotContainsString('evil_col', $result);
    }

    // =========================================================================
    // L-17: Signature max length validation
    // =========================================================================

    public function testSignatureMaxLengthConstantExists(): void
    {
        $this->assertTrue(
            defined('Eiou\Core\Constants::VALIDATION_SIGNATURE_MAX_LENGTH'),
            'VALIDATION_SIGNATURE_MAX_LENGTH constant must exist'
        );
        $this->assertEquals(1024, Constants::VALIDATION_SIGNATURE_MAX_LENGTH);
    }

    public function testSignatureExceedingMaxLengthIsRejected(): void
    {
        // Generate a signature that exceeds the max length
        $longSignature = str_repeat('A', Constants::VALIDATION_SIGNATURE_MAX_LENGTH + 1);

        $result = InputValidator::validateSignature($longSignature);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('maximum', strtolower($result['error']));
    }

    public function testSignatureAtMaxLengthIsAccepted(): void
    {
        // Valid base64 chars at exactly max length
        $signature = str_repeat('A', Constants::VALIDATION_SIGNATURE_MAX_LENGTH);

        $result = InputValidator::validateSignature($signature);

        // Should pass the length check (may fail other checks like min length, but not max)
        $this->assertStringNotContainsString('maximum', strtolower($result['error'] ?? ''));
    }

    // =========================================================================
    // L-18: Tor v3 address validation
    // =========================================================================

    public function testTorV3AddressAccepted(): void
    {
        // Valid v3 onion address: 56 base32 chars + .onion
        $validV3 = str_repeat('a', 56) . '.onion';
        $this->assertTrue(AddressValidator::isTorAddress($validV3));
    }

    public function testTorV3AddressWithPortAccepted(): void
    {
        $validV3WithPort = str_repeat('b', 56) . '.onion:8080';
        $this->assertTrue(AddressValidator::isTorAddress($validV3WithPort));
    }

    public function testTorV2AddressRejected(): void
    {
        // V2 onion addresses were 16 chars - should be rejected
        $v2Address = str_repeat('a', 16) . '.onion';
        $this->assertFalse(AddressValidator::isTorAddress($v2Address));
    }

    public function testTorAddressWithInvalidCharsRejected(): void
    {
        // Base32 only uses a-z and 2-7, not 0, 1, 8, 9
        $invalidChars = str_repeat('a', 55) . '0' . '.onion';
        $this->assertFalse(AddressValidator::isTorAddress($invalidChars));
    }

    public function testTorAddressWithPrefixRejected(): void
    {
        // Should not accept arbitrary prefixes
        $prefixed = 'http://' . str_repeat('a', 56) . '.onion';
        $this->assertFalse(AddressValidator::isTorAddress($prefixed));
    }

    public function testTorAddressTooShortRejected(): void
    {
        $short = str_repeat('a', 55) . '.onion';
        $this->assertFalse(AddressValidator::isTorAddress($short));
    }

    public function testTorAddressTooLongRejected(): void
    {
        $long = str_repeat('a', 57) . '.onion';
        $this->assertFalse(AddressValidator::isTorAddress($long));
    }

    public function testTorAddressUppercaseRejected(): void
    {
        // Base32 in v3 onion should be lowercase
        $upper = str_repeat('A', 56) . '.onion';
        $this->assertFalse(AddressValidator::isTorAddress($upper));
    }

    // =========================================================================
    // L-19: sanitizeString strips null bytes and control characters
    // =========================================================================

    public function testSanitizeStringStripsNullBytes(): void
    {
        $payload = $this->createBasePayloadTestClass();
        $method = new ReflectionMethod($payload, 'sanitizeString');
        $method->setAccessible(true);

        $result = $method->invoke($payload, "hello\0world");
        $this->assertEquals('helloworld', $result);
    }

    public function testSanitizeStringStripsControlCharacters(): void
    {
        $payload = $this->createBasePayloadTestClass();
        $method = new ReflectionMethod($payload, 'sanitizeString');
        $method->setAccessible(true);

        // \x01 (SOH), \x08 (backspace), \x0E (shift out) should be stripped
        $result = $method->invoke($payload, "test\x01\x08\x0Evalue");
        $this->assertEquals('testvalue', $result);
    }

    public function testSanitizeStringPreservesNewlinesAndTabs(): void
    {
        $payload = $this->createBasePayloadTestClass();
        $method = new ReflectionMethod($payload, 'sanitizeString');
        $method->setAccessible(true);

        // \n (0x0A), \r (0x0D), \t (0x09) should be preserved
        $result = $method->invoke($payload, "line1\nline2\ttab\rcarriage");
        $this->assertStringContainsString("\n", $result);
        $this->assertStringContainsString("\t", $result);
    }

    public function testSanitizeStringTrims(): void
    {
        $payload = $this->createBasePayloadTestClass();
        $method = new ReflectionMethod($payload, 'sanitizeString');
        $method->setAccessible(true);

        $result = $method->invoke($payload, "  hello  ");
        $this->assertEquals('hello', $result);
    }

    // =========================================================================
    // L-21: BIP39 constant-time checksum comparison
    // =========================================================================

    public function testBip39ValidatesMnemonicCorrectly(): void
    {
        // Generate a valid mnemonic and verify it validates
        $mnemonic = BIP39::generateMnemonic(12);
        $this->assertTrue(BIP39::validateMnemonic($mnemonic));
    }

    public function testBip39RejectsInvalidChecksum(): void
    {
        // Generate valid mnemonic and swap the last word to break checksum
        $mnemonic = BIP39::generateMnemonic(12);
        $words = explode(' ', $mnemonic);
        // Swap last word with a different valid BIP39 word
        $words[11] = ($words[11] === 'abandon') ? 'about' : 'abandon';
        $modified = implode(' ', $words);

        // Checksum should fail (using hash_equals internally now)
        $this->assertFalse(BIP39::validateMnemonic($modified));
    }

    // =========================================================================
    // L-22: HMAC context constants
    // =========================================================================

    public function testBip39HmacContextConstantsExist(): void
    {
        $reflection = new ReflectionClass(BIP39::class);

        $this->assertTrue($reflection->hasConstant('HMAC_CONTEXT_EC_KEY'));
        $this->assertTrue($reflection->hasConstant('HMAC_CONTEXT_AUTH_CODE'));
        $this->assertEquals('eiou-ec-key', $reflection->getConstant('HMAC_CONTEXT_EC_KEY'));
        $this->assertEquals('eiou-auth-code', $reflection->getConstant('HMAC_CONTEXT_AUTH_CODE'));
    }

    public function testTorKeyDerivationHmacContextConstantExists(): void
    {
        $reflection = new ReflectionClass(TorKeyDerivation::class);

        $this->assertTrue($reflection->hasConstant('HMAC_CONTEXT_TOR'));
        $this->assertEquals('eiou-tor-hidden-service', $reflection->getConstant('HMAC_CONTEXT_TOR'));
    }

    // =========================================================================
    // L-25: UserContext getAll/toArray filter sensitive keys
    // =========================================================================

    public function testUserContextGetAllFiltersSensitiveKeys(): void
    {
        $reflection = new ReflectionClass(UserContext::class);

        // Verify SENSITIVE_KEYS constant exists
        $this->assertTrue($reflection->hasConstant('SENSITIVE_KEYS'));
        $sensitiveKeys = $reflection->getConstant('SENSITIVE_KEYS');

        $this->assertContains('private_encrypted', $sensitiveKeys);
        $this->assertContains('authcode_encrypted', $sensitiveKeys);
        $this->assertContains('mnemonic_encrypted', $sensitiveKeys);
    }

    public function testUserContextGetAllOmitsSensitiveData(): void
    {
        $this->resetUserContextSingleton();

        $instance = UserContext::getInstance();

        // Set test data including sensitive keys via reflection
        $reflection = new ReflectionClass(UserContext::class);
        $userDataProp = $reflection->getProperty('userData');
        $userDataProp->setAccessible(true);
        $userDataProp->setValue($instance, [
            'public' => 'test_public_key',
            'username' => 'testuser',
            'private_encrypted' => 'SHOULD_NOT_APPEAR',
            'authcode_encrypted' => 'SHOULD_NOT_APPEAR',
            'mnemonic_encrypted' => 'SHOULD_NOT_APPEAR',
        ]);

        $result = $instance->getAll();

        $this->assertArrayHasKey('public', $result);
        $this->assertArrayHasKey('username', $result);
        $this->assertArrayNotHasKey('private_encrypted', $result);
        $this->assertArrayNotHasKey('authcode_encrypted', $result);
        $this->assertArrayNotHasKey('mnemonic_encrypted', $result);

        $this->resetUserContextSingleton();
    }

    public function testUserContextToArrayOmitsSensitiveData(): void
    {
        $this->resetUserContextSingleton();

        $instance = UserContext::getInstance();

        $reflection = new ReflectionClass(UserContext::class);
        $userDataProp = $reflection->getProperty('userData');
        $userDataProp->setAccessible(true);
        $userDataProp->setValue($instance, [
            'public' => 'test_public_key',
            'private_encrypted' => 'SECRET',
        ]);

        $result = $instance->toArray();

        $this->assertArrayHasKey('public', $result);
        $this->assertArrayNotHasKey('private_encrypted', $result);

        $this->resetUserContextSingleton();
    }

    // =========================================================================
    // L-35: Lock name hashing for long names
    // =========================================================================

    public function testLockNameHashingProducesDistinctNames(): void
    {
        $service = $this->createDatabaseLockingServiceForTest();

        $method = new ReflectionMethod(DatabaseLockingService::class, 'sanitizeLockName');
        $method->setAccessible(true);

        // Two long names with same prefix but different suffixes
        $name1 = str_repeat('a', 80) . '_suffix1';
        $name2 = str_repeat('a', 80) . '_suffix2';

        $sanitized1 = $method->invoke($service, $name1);
        $sanitized2 = $method->invoke($service, $name2);

        // Both should be within 64 chars
        $this->assertLessThanOrEqual(64, strlen($sanitized1));
        $this->assertLessThanOrEqual(64, strlen($sanitized2));

        // They should be DIFFERENT (hash prevents collision from truncation)
        $this->assertNotEquals($sanitized1, $sanitized2);
    }

    public function testLockNameHashingPreservesPrefix(): void
    {
        $service = $this->createDatabaseLockingServiceForTest();

        $method = new ReflectionMethod(DatabaseLockingService::class, 'sanitizeLockName');
        $method->setAccessible(true);

        $longName = str_repeat('x', 100);
        $result = $method->invoke($service, $longName);

        $this->assertStringStartsWith('eiou_', $result);
    }

    public function testLockNameShortNamesUnchanged(): void
    {
        $service = $this->createDatabaseLockingServiceForTest();

        $method = new ReflectionMethod(DatabaseLockingService::class, 'sanitizeLockName');
        $method->setAccessible(true);

        $shortName = 'my_lock';
        $result = $method->invoke($service, $shortName);

        $this->assertEquals('eiou_my_lock', $result);
        $this->assertLessThanOrEqual(64, strlen($result));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a concrete test class that uses the QueryBuilder trait
     */
    private function createQueryBuilderTestClass(array $allowedColumns = []): object
    {
        return new class($allowedColumns) {
            use \Eiou\Database\Traits\QueryBuilder;

            protected array $allowedColumns;

            public function __construct(array $allowedColumns)
            {
                $this->allowedColumns = $allowedColumns;
            }

            protected function isValidColumn(string $column): bool
            {
                if (empty($this->allowedColumns)) {
                    return true;
                }
                return in_array(strtolower($column), array_map('strtolower', $this->allowedColumns), true);
            }
        };
    }

    /**
     * Create a concrete test class extending BasePayload
     */
    private function createBasePayloadTestClass(): object
    {
        return new class extends BasePayload {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function build(array $data): array
            {
                return $data;
            }
        };
    }

    /**
     * Create DatabaseLockingService instance without DB connection
     */
    private function createDatabaseLockingServiceForTest(): DatabaseLockingService
    {
        $reflection = new ReflectionClass(DatabaseLockingService::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * Reset UserContext singleton
     */
    private function resetUserContextSingleton(): void
    {
        $reflection = new ReflectionClass(UserContext::class);
        $instanceProperty = $reflection->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }
}
