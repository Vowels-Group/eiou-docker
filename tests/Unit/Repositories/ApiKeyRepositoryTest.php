<?php
/**
 * Unit Tests for ApiKeyRepository
 *
 * Tests API key repository functionality including key creation, retrieval,
 * management operations, request logging, and permission checking.
 * Uses mocked PDO and PDOStatement to isolate database operations.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\ApiKeyRepository;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(ApiKeyRepository::class)]
class ApiKeyRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private ApiKeyRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);

        // Create repository with mocked PDO using anonymous class to bypass constructor dependencies
        $this->repository = $this->createRepositoryWithMockedPdo($this->pdo);
    }

    /**
     * Create repository instance with mocked PDO, bypassing constructor dependencies
     */
    private function createRepositoryWithMockedPdo(PDO $pdo): ApiKeyRepository
    {
        $repository = new class($pdo) extends ApiKeyRepository {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->tableName = 'api_keys';
                $this->primaryKey = 'id';
                $this->allowedColumns = [
                    'id', 'key_id', 'encrypted_secret', 'name', 'permissions',
                    'rate_limit_per_minute', 'enabled', 'created_at', 'last_used_at', 'expires_at'
                ];
            }
        };

        return $repository;
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test repository sets correct table name
     */
    public function testRepositorySetsCorrectTableName(): void
    {
        $this->assertEquals('api_keys', $this->repository->getTableName());
    }

    /**
     * Test allowed columns include expected fields
     */
    public function testAllowedColumnsIncludeExpectedFields(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('allowedColumns');
        $property->setAccessible(true);
        $allowedColumns = $property->getValue($this->repository);

        $this->assertContains('id', $allowedColumns);
        $this->assertContains('key_id', $allowedColumns);
        $this->assertContains('encrypted_secret', $allowedColumns);
        $this->assertContains('name', $allowedColumns);
        $this->assertContains('permissions', $allowedColumns);
        $this->assertContains('rate_limit_per_minute', $allowedColumns);
        $this->assertContains('enabled', $allowedColumns);
        $this->assertContains('created_at', $allowedColumns);
        $this->assertContains('last_used_at', $allowedColumns);
        $this->assertContains('expires_at', $allowedColumns);
    }

    // =========================================================================
    // getByKeyId Tests
    // =========================================================================

    /**
     * Test getByKeyId returns key details when found
     */
    public function testGetByKeyIdReturnsKeyDetailsWhenFound(): void
    {
        $keyId = 'eiou_test123abc';
        $expectedData = [
            'id' => 1,
            'key_id' => $keyId,
            'name' => 'Test API Key',
            'permissions' => '["wallet:read", "contacts:read"]',
            'rate_limit_per_minute' => 100,
            'enabled' => 1,
            'created_at' => '2025-01-01 00:00:00',
            'last_used_at' => null,
            'expires_at' => null
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedData);

        $result = $this->repository->getByKeyId($keyId);

        $this->assertIsArray($result);
        $this->assertEquals($keyId, $result['key_id']);
        $this->assertEquals('Test API Key', $result['name']);
        $this->assertIsArray($result['permissions']);
        $this->assertContains('wallet:read', $result['permissions']);
    }

    /**
     * Test getByKeyId returns null when not found
     */
    public function testGetByKeyIdReturnsNullWhenNotFound(): void
    {
        $keyId = 'eiou_nonexistent';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getByKeyId($keyId);

        $this->assertNull($result);
    }

    // =========================================================================
    // getSecretByKeyId Tests
    // =========================================================================

    /**
     * Test getSecretByKeyId returns null when key not found
     */
    public function testGetSecretByKeyIdReturnsNullWhenKeyNotFound(): void
    {
        $keyId = 'eiou_nonexistent';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->getSecretByKeyId($keyId);

        $this->assertNull($result);
    }

    /**
     * Test getSecretByKeyId returns null for empty encrypted_secret
     */
    public function testGetSecretByKeyIdReturnsNullForEmptyEncryptedSecret(): void
    {
        $keyId = 'eiou_test123';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['encrypted_secret' => '']);

        $result = $this->repository->getSecretByKeyId($keyId);

        $this->assertNull($result);
    }

    /**
     * Test getSecretByKeyId returns null for invalid JSON
     */
    public function testGetSecretByKeyIdReturnsNullForInvalidJson(): void
    {
        $keyId = 'eiou_test123';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['encrypted_secret' => 'not-valid-json']);

        $result = $this->repository->getSecretByKeyId($keyId);

        $this->assertNull($result);
    }

    // =========================================================================
    // listKeys Tests
    // =========================================================================

    /**
     * Test listKeys returns all keys including disabled
     */
    public function testListKeysReturnsAllKeysIncludingDisabled(): void
    {
        $expectedKeys = [
            [
                'id' => 1,
                'key_id' => 'eiou_key1',
                'name' => 'Key 1',
                'permissions' => '["wallet:read"]',
                'enabled' => 1
            ],
            [
                'id' => 2,
                'key_id' => 'eiou_key2',
                'name' => 'Key 2',
                'permissions' => '["admin"]',
                'enabled' => 0
            ]
        ];

        $this->pdo->expects($this->once())
            ->method('query')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedKeys);

        $result = $this->repository->listKeys(true);

        $this->assertCount(2, $result);
        $this->assertIsArray($result[0]['permissions']);
        $this->assertIsArray($result[1]['permissions']);
    }

    /**
     * Test listKeys filters disabled keys when includeDisabled is false
     */
    public function testListKeysFiltersDisabledKeysWhenExcluded(): void
    {
        $expectedKeys = [
            [
                'id' => 1,
                'key_id' => 'eiou_key1',
                'name' => 'Key 1',
                'permissions' => '["wallet:read"]',
                'enabled' => 1
            ]
        ];

        $this->pdo->expects($this->once())
            ->method('query')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedKeys);

        $result = $this->repository->listKeys(false);

        $this->assertCount(1, $result);
        $this->assertEquals('eiou_key1', $result[0]['key_id']);
    }

    /**
     * Test listKeys returns empty array when no keys
     */
    public function testListKeysReturnsEmptyArrayWhenNoKeys(): void
    {
        $this->pdo->expects($this->once())
            ->method('query')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn([]);

        $result = $this->repository->listKeys();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // updateLastUsed Tests
    // =========================================================================

    /**
     * Test updateLastUsed updates timestamp
     */
    public function testUpdateLastUsedUpdatesTimestamp(): void
    {
        $keyId = 'eiou_test123';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        // Method returns void, just verify no exception thrown
        $this->repository->updateLastUsed($keyId);

        $this->assertTrue(true);
    }

    // =========================================================================
    // disableKey Tests
    // =========================================================================

    /**
     * Test disableKey returns true when key disabled successfully
     */
    public function testDisableKeyReturnsTrueWhenSuccessful(): void
    {
        $keyId = 'eiou_test123';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->disableKey($keyId);

        $this->assertTrue($result);
    }

    /**
     * Test disableKey returns false when key not found
     */
    public function testDisableKeyReturnsFalseWhenKeyNotFound(): void
    {
        $keyId = 'eiou_nonexistent';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->disableKey($keyId);

        $this->assertFalse($result);
    }

    // =========================================================================
    // enableKey Tests
    // =========================================================================

    /**
     * Test enableKey returns true when key enabled successfully
     */
    public function testEnableKeyReturnsTrueWhenSuccessful(): void
    {
        $keyId = 'eiou_test123';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->enableKey($keyId);

        $this->assertTrue($result);
    }

    /**
     * Test enableKey returns false when key not found
     */
    public function testEnableKeyReturnsFalseWhenKeyNotFound(): void
    {
        $keyId = 'eiou_nonexistent';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->enableKey($keyId);

        $this->assertFalse($result);
    }

    // =========================================================================
    // deleteKey Tests
    // =========================================================================

    /**
     * Test deleteKey returns true when key deleted successfully
     */
    public function testDeleteKeyReturnsTrueWhenSuccessful(): void
    {
        $keyId = 'eiou_test123';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->deleteKey($keyId);

        $this->assertTrue($result);
    }

    /**
     * Test deleteKey returns false when key not found
     */
    public function testDeleteKeyReturnsFalseWhenKeyNotFound(): void
    {
        $keyId = 'eiou_nonexistent';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([':key_id' => $keyId]);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->deleteKey($keyId);

        $this->assertFalse($result);
    }

    // =========================================================================
    // updatePermissions Tests
    // =========================================================================

    /**
     * Test updatePermissions updates permissions successfully
     */
    public function testUpdatePermissionsUpdatesSuccessfully(): void
    {
        $keyId = 'eiou_test123';
        $permissions = ['wallet:read', 'wallet:send', 'contacts:read'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([
                ':key_id' => $keyId,
                ':permissions' => json_encode($permissions)
            ]);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updatePermissions($keyId, $permissions);

        $this->assertTrue($result);
    }

    /**
     * Test updatePermissions returns false when key not found
     */
    public function testUpdatePermissionsReturnsFalseWhenKeyNotFound(): void
    {
        $keyId = 'eiou_nonexistent';
        $permissions = ['wallet:read'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->updatePermissions($keyId, $permissions);

        $this->assertFalse($result);
    }

    // =========================================================================
    // updateKey Tests
    // =========================================================================

    /**
     * Test updateKey updates all three fields when all are non-null.
     */
    public function testUpdateKeyUpdatesAllFields(): void
    {
        $keyId = 'eiou_test123';
        $name = 'new label';
        $rateLimit = 250;
        $expiresAt = '2030-01-01 00:00:00';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('name = :name'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([
                ':key_id' => $keyId,
                ':name' => $name,
                ':rate' => $rateLimit,
                ':expires' => $expiresAt,
            ]);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $this->assertTrue($this->repository->updateKey($keyId, $name, $rateLimit, $expiresAt));
    }

    /**
     * Test updateKey skips columns whose parameter is null and only
     * includes the non-null ones in the generated SET clause.
     */
    public function testUpdateKeyOnlyUpdatesNonNullFields(): void
    {
        $keyId = 'eiou_test123';

        $capturedSql = null;
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return $this->stmt;
            });

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([
                ':key_id' => $keyId,
                ':rate' => 500,
            ]);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $this->assertTrue($this->repository->updateKey($keyId, null, 500, null));

        $this->assertStringContainsString('rate_limit_per_minute = :rate', $capturedSql);
        $this->assertStringNotContainsString(':name', $capturedSql);
        $this->assertStringNotContainsString(':expires', $capturedSql);
    }

    /**
     * Test updateKey returns false without hitting the database when every
     * mutable field is null (no-op update — guard against a malformed
     * `UPDATE api_keys SET  WHERE …`).
     */
    public function testUpdateKeyReturnsFalseWhenAllFieldsNull(): void
    {
        $this->pdo->expects($this->never())->method('prepare');
        $this->assertFalse($this->repository->updateKey('eiou_test123', null, null, null));
    }

    /**
     * Test updateKey returns false when no row matched the key_id.
     */
    public function testUpdateKeyReturnsFalseWhenKeyNotFound(): void
    {
        $this->pdo->expects($this->once())->method('prepare')->willReturn($this->stmt);
        $this->stmt->expects($this->once())->method('execute');
        $this->stmt->expects($this->once())->method('rowCount')->willReturn(0);

        $this->assertFalse($this->repository->updateKey('eiou_nonexistent', 'x', null, null));
    }

    // =========================================================================
    // disableAllKeys Tests
    // =========================================================================

    /**
     * Test disableAllKeys returns the affected row count.
     */
    public function testDisableAllKeysReturnsAffectedCount(): void
    {
        $this->pdo->expects($this->once())
            ->method('query')
            ->with($this->stringContains('UPDATE api_keys SET enabled = 0 WHERE enabled = 1'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())->method('rowCount')->willReturn(4);

        $this->assertSame(4, $this->repository->disableAllKeys());
    }

    /**
     * Test disableAllKeys returns 0 when no keys were enabled.
     */
    public function testDisableAllKeysReturnsZeroWhenNoneEnabled(): void
    {
        $this->pdo->expects($this->once())->method('query')->willReturn($this->stmt);
        $this->stmt->expects($this->once())->method('rowCount')->willReturn(0);

        $this->assertSame(0, $this->repository->disableAllKeys());
    }

    // =========================================================================
    // deleteAllKeys Tests
    // =========================================================================

    /**
     * Test deleteAllKeys returns the affected row count.
     */
    public function testDeleteAllKeysReturnsAffectedCount(): void
    {
        $this->pdo->expects($this->once())
            ->method('query')
            ->with($this->stringContains('DELETE FROM api_keys'))
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())->method('rowCount')->willReturn(7);

        $this->assertSame(7, $this->repository->deleteAllKeys());
    }

    /**
     * Test deleteAllKeys returns 0 when the table was already empty.
     */
    public function testDeleteAllKeysReturnsZeroWhenEmpty(): void
    {
        $this->pdo->expects($this->once())->method('query')->willReturn($this->stmt);
        $this->stmt->expects($this->once())->method('rowCount')->willReturn(0);

        $this->assertSame(0, $this->repository->deleteAllKeys());
    }

    // =========================================================================
    // logRequest Tests
    // =========================================================================

    /**
     * Test logRequest inserts request log entry
     */
    public function testLogRequestInsertsLogEntry(): void
    {
        $keyId = 'eiou_test123';
        $endpoint = '/api/wallet/balance';
        $method = 'GET';
        $ipAddress = '192.168.1.100';
        $responseCode = 200;
        $responseTimeMs = 45;

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([
                ':key_id' => $keyId,
                ':endpoint' => $endpoint,
                ':method' => $method,
                ':ip_address' => $ipAddress,
                ':response_code' => $responseCode,
                ':response_time_ms' => $responseTimeMs
            ]);

        // Method returns void
        $this->repository->logRequest($keyId, $endpoint, $method, $ipAddress, $responseCode, $responseTimeMs);

        $this->assertTrue(true);
    }

    /**
     * Test logRequest with null response time
     */
    public function testLogRequestWithNullResponseTime(): void
    {
        $keyId = 'eiou_test123';
        $endpoint = '/api/wallet/balance';
        $method = 'GET';
        $ipAddress = '192.168.1.100';
        $responseCode = 200;

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([
                ':key_id' => $keyId,
                ':endpoint' => $endpoint,
                ':method' => $method,
                ':ip_address' => $ipAddress,
                ':response_code' => $responseCode,
                ':response_time_ms' => null
            ]);

        $this->repository->logRequest($keyId, $endpoint, $method, $ipAddress, $responseCode);

        $this->assertTrue(true);
    }

    // =========================================================================
    // getRequestCount Tests
    // =========================================================================

    /**
     * Test getRequestCount returns count within time window
     */
    public function testGetRequestCountReturnsCountWithinTimeWindow(): void
    {
        $keyId = 'eiou_test123';
        $windowSeconds = 60;

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([
                ':key_id' => $keyId,
                ':window' => $windowSeconds
            ]);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 25]);

        $result = $this->repository->getRequestCount($keyId, $windowSeconds);

        $this->assertEquals(25, $result);
    }

    /**
     * Test getRequestCount uses default 60 second window
     */
    public function testGetRequestCountUsesDefaultWindow(): void
    {
        $keyId = 'eiou_test123';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute')
            ->with([
                ':key_id' => $keyId,
                ':window' => 60
            ]);

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 10]);

        $result = $this->repository->getRequestCount($keyId);

        $this->assertEquals(10, $result);
    }

    /**
     * Test getRequestCount returns zero when no requests
     */
    public function testGetRequestCountReturnsZeroWhenNoRequests(): void
    {
        $keyId = 'eiou_test123';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(['count' => 0]);

        $result = $this->repository->getRequestCount($keyId);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // hasPermission Tests (Static Method)
    // =========================================================================

    /**
     * Test hasPermission returns true for exact match
     */
    public function testHasPermissionReturnsTrueForExactMatch(): void
    {
        $permissions = ['wallet:read', 'contacts:read'];

        $result = ApiKeyRepository::hasPermission($permissions, 'wallet:read');

        $this->assertTrue($result);
    }

    /**
     * Test hasPermission returns false for non-matching permission
     */
    public function testHasPermissionReturnsFalseForNonMatch(): void
    {
        $permissions = ['wallet:read', 'contacts:read'];

        $result = ApiKeyRepository::hasPermission($permissions, 'wallet:send');

        $this->assertFalse($result);
    }

    /**
     * Test hasPermission returns true for 'all' permission
     */
    public function testHasPermissionReturnsTrueForAllPermission(): void
    {
        $permissions = ['all'];

        $result = ApiKeyRepository::hasPermission($permissions, 'wallet:read');
        $this->assertTrue($result);

        $result = ApiKeyRepository::hasPermission($permissions, 'admin');
        $this->assertTrue($result);

        $result = ApiKeyRepository::hasPermission($permissions, 'any:permission');
        $this->assertTrue($result);
    }

    /**
     * Test hasPermission returns true for legacy '*' permission
     */
    public function testHasPermissionReturnsTrueForLegacyStarPermission(): void
    {
        $permissions = ['*'];

        $result = ApiKeyRepository::hasPermission($permissions, 'wallet:read');

        $this->assertTrue($result);
    }

    /**
     * Test hasPermission returns true for 'admin' permission
     */
    public function testHasPermissionReturnsTrueForAdminPermission(): void
    {
        $permissions = ['admin'];

        $result = ApiKeyRepository::hasPermission($permissions, 'wallet:read');
        $this->assertTrue($result);

        $result = ApiKeyRepository::hasPermission($permissions, 'system:write');
        $this->assertTrue($result);
    }

    /**
     * Test hasPermission returns true for category wildcard
     */
    public function testHasPermissionReturnsTrueForCategoryWildcard(): void
    {
        $permissions = ['wallet:*'];

        $result = ApiKeyRepository::hasPermission($permissions, 'wallet:read');
        $this->assertTrue($result);

        $result = ApiKeyRepository::hasPermission($permissions, 'wallet:send');
        $this->assertTrue($result);

        // Should not match different category
        $result = ApiKeyRepository::hasPermission($permissions, 'contacts:read');
        $this->assertFalse($result);
    }

    /**
     * Test hasPermission with multiple permissions
     */
    public function testHasPermissionWithMultiplePermissions(): void
    {
        $permissions = ['wallet:read', 'contacts:*', 'system:read'];

        $this->assertTrue(ApiKeyRepository::hasPermission($permissions, 'wallet:read'));
        $this->assertTrue(ApiKeyRepository::hasPermission($permissions, 'contacts:read'));
        $this->assertTrue(ApiKeyRepository::hasPermission($permissions, 'contacts:write'));
        $this->assertTrue(ApiKeyRepository::hasPermission($permissions, 'system:read'));

        $this->assertFalse(ApiKeyRepository::hasPermission($permissions, 'wallet:send'));
        $this->assertFalse(ApiKeyRepository::hasPermission($permissions, 'system:write'));
    }

    /**
     * Test hasPermission with empty permissions array
     */
    public function testHasPermissionWithEmptyPermissionsArray(): void
    {
        $permissions = [];

        $result = ApiKeyRepository::hasPermission($permissions, 'wallet:read');

        $this->assertFalse($result);
    }

    /**
     * Test hasPermission with single-part permission (no colon)
     */
    public function testHasPermissionWithSinglePartPermission(): void
    {
        $permissions = ['admin'];

        // 'admin' grants all through the admin check
        $result = ApiKeyRepository::hasPermission($permissions, 'admin');
        $this->assertTrue($result);

        // Single-part required permission should not match wildcard pattern
        $permissions = ['wallet:*'];
        $result = ApiKeyRepository::hasPermission($permissions, 'admin');
        $this->assertFalse($result);
    }
}
