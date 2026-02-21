<?php
/**
 * Unit Tests for AddressRepository
 *
 * Tests address repository functionality including address management,
 * transport index validation, and contact lookups.
 * Uses mocked PDO and PDOStatement to isolate database operations.
 */

namespace Eiou\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\AddressRepository;
use Eiou\Core\Constants;
use PDO;
use PDOStatement;
use PDOException;

#[CoversClass(AddressRepository::class)]
class AddressRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PDOStatement $stmt;
    private AddressRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->stmt = $this->createMock(PDOStatement::class);

        // Create repository with mocked PDO using reflection to bypass UserContext
        $this->repository = $this->createRepositoryWithMockedPdo($this->pdo);
    }

    /**
     * Create repository instance with mocked PDO, bypassing constructor dependencies
     */
    private function createRepositoryWithMockedPdo(PDO $pdo): AddressRepository
    {
        return new TestableAddressRepository($pdo);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    /**
     * Test repository sets correct table name
     */
    public function testRepositorySetsCorrectTableName(): void
    {
        $this->assertEquals('addresses', $this->repository->getTableName());
    }

    /**
     * Test repository uses pubkey_hash as primary key
     */
    public function testRepositoryUsesPubkeyHashAsPrimaryKey(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $property = $reflection->getProperty('primaryKey');
        $property->setAccessible(true);

        $this->assertEquals('pubkey_hash', $property->getValue($this->repository));
    }

    // =========================================================================
    // insertAddress Tests
    // =========================================================================

    /**
     * Test insertAddress creates new address record
     */
    public function testInsertAddressCreatesNewAddressRecord(): void
    {
        $contactPublicKey = 'test-public-key-123';
        $addresses = ['http' => 'http://test.example.com'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->insertAddress($contactPublicKey, $addresses);

        $this->assertTrue($result);
    }

    /**
     * Test insertAddress hashes the public key
     */
    public function testInsertAddressHashesPublicKey(): void
    {
        $contactPublicKey = 'test-public-key-123';
        $expectedHash = hash(Constants::HASH_ALGORITHM, $contactPublicKey);
        $addresses = ['http' => 'http://test.example.com'];

        $boundValues = [];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(2))
            ->method('bindValue')
            ->willReturnCallback(function ($key, $value) use (&$boundValues) {
                $boundValues[$key] = $value;
                return true;
            });

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $this->repository->insertAddress($contactPublicKey, $addresses);

        $this->assertEquals($expectedHash, $boundValues[':pubkey_hash']);
    }

    /**
     * Test insertAddress with multiple address types
     */
    public function testInsertAddressWithMultipleAddressTypes(): void
    {
        $contactPublicKey = 'test-public-key';
        $addresses = [
            'http' => 'http://test.example.com',
            'https' => 'https://test.example.com',
            'tor' => 'testaddress.onion'
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->exactly(4)) // 3 addresses + pubkey_hash
            ->method('bindValue');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->pdo->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('1');

        $result = $this->repository->insertAddress($contactPublicKey, $addresses);

        $this->assertTrue($result);
    }

    /**
     * Test insertAddress returns false on failure
     */
    public function testInsertAddressReturnsFalseOnFailure(): void
    {
        $contactPublicKey = 'test-public-key';
        $addresses = ['http' => 'http://test.example.com'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Insert failed'));

        $result = $this->repository->insertAddress($contactPublicKey, $addresses);

        $this->assertFalse($result);
    }

    // =========================================================================
    // updateContactFields Tests
    // =========================================================================

    /**
     * Test updateContactFields updates address fields
     */
    public function testUpdateContactFieldsUpdatesAddressFields(): void
    {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, 'test-key');
        $fields = ['http' => 'http://new-address.example.com'];

        // getAllAddressTypes() and update() each call prepare()
        $this->pdo->expects($this->atLeastOnce())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->atLeastOnce())
            ->method('execute');

        // getAllAddressTypes() calls fetchAll
        $this->stmt->expects($this->any())
            ->method('fetchAll')
            ->willReturn(['http', 'https', 'tor']);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->updateContactFields($pubkeyHash, $fields);

        $this->assertTrue($result);
    }

    /**
     * Test updateContactFields returns false for empty fields
     */
    public function testUpdateContactFieldsReturnsFalseForEmptyFields(): void
    {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, 'test-key');
        $fields = [];

        $result = $this->repository->updateContactFields($pubkeyHash, $fields);

        $this->assertFalse($result);
    }

    /**
     * Test updateContactFields returns true when no rows affected but query succeeded
     */
    public function testUpdateContactFieldsReturnsTrueWhenNoRowsAffected(): void
    {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, 'test-key');
        $fields = ['http' => 'http://same-address.example.com'];

        // getAllAddressTypes() and update() each call prepare()
        $this->pdo->expects($this->atLeastOnce())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->atLeastOnce())
            ->method('bindValue');

        $this->stmt->expects($this->atLeastOnce())
            ->method('execute');

        // getAllAddressTypes() calls fetchAll
        $this->stmt->expects($this->any())
            ->method('fetchAll')
            ->willReturn(['http', 'https', 'tor']);

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0); // No rows affected

        $result = $this->repository->updateContactFields($pubkeyHash, $fields);

        $this->assertTrue($result); // Returns true because >= 0
    }

    // =========================================================================
    // lookupByPubkeyHash Tests
    // =========================================================================

    /**
     * Test lookupByPubkeyHash returns address data when found
     */
    public function testLookupByPubkeyHashReturnsAddressDataWhenFound(): void
    {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, 'test-key');
        $expectedData = [
            'pubkey_hash' => $pubkeyHash,
            'http' => 'http://test.example.com',
            'https' => 'https://test.example.com',
            'tor' => ''
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':pubkey_hash', $pubkeyHash);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedData);

        $result = $this->repository->lookupByPubkeyHash($pubkeyHash);

        $this->assertEquals($expectedData, $result);
    }

    /**
     * Test lookupByPubkeyHash returns empty array when not found
     */
    public function testLookupByPubkeyHashReturnsEmptyArrayWhenNotFound(): void
    {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, 'nonexistent-key');

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = $this->repository->lookupByPubkeyHash($pubkeyHash);

        $this->assertEquals([], $result);
    }

    /**
     * Test lookupByPubkeyHash returns empty array on query failure
     */
    public function testLookupByPubkeyHashReturnsEmptyArrayOnQueryFailure(): void
    {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, 'test-key');

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->lookupByPubkeyHash($pubkeyHash);

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // deleteByPubkey Tests
    // =========================================================================

    /**
     * Test deleteByPubkey removes address and returns true
     */
    public function testDeleteByPubkeyRemovesAddressAndReturnsTrue(): void
    {
        $pubkey = 'test-public-key';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':value', hash(Constants::HASH_ALGORITHM, $pubkey));

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->deleteByPubkey($pubkey);

        $this->assertTrue($result);
    }

    /**
     * Test deleteByPubkey returns false when no rows deleted
     */
    public function testDeleteByPubkeyReturnsFalseWhenNoRowsDeleted(): void
    {
        $pubkey = 'nonexistent-key';

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->deleteByPubkey($pubkey);

        $this->assertFalse($result);
    }

    // =========================================================================
    // deleteByPubkeyHash Tests
    // =========================================================================

    /**
     * Test deleteByPubkeyHash removes address and returns true
     */
    public function testDeleteByPubkeyHashRemovesAddressAndReturnsTrue(): void
    {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, 'test-key');

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':value', $pubkeyHash);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(1);

        $result = $this->repository->deleteByPubkeyHash($pubkeyHash);

        $this->assertTrue($result);
    }

    /**
     * Test deleteByPubkeyHash returns false when no rows deleted
     */
    public function testDeleteByPubkeyHashReturnsFalseWhenNoRowsDeleted(): void
    {
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, 'nonexistent-key');

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(0);

        $result = $this->repository->deleteByPubkeyHash($pubkeyHash);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getAllAddresses Tests
    // =========================================================================

    /**
     * Test getAllAddresses returns all addresses without filter
     */
    public function testGetAllAddressesReturnsAllAddressesWithoutFilter(): void
    {
        $expectedAddresses = [
            ['pubkey_hash' => 'hash1', 'http' => 'http://one.example.com'],
            ['pubkey_hash' => 'hash2', 'http' => 'http://two.example.com'],
        ];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedAddresses);

        $result = $this->repository->getAllAddresses();

        $this->assertEquals($expectedAddresses, $result);
    }

    /**
     * Test getAllAddresses with transport index and exclude filter
     */
    public function testGetAllAddressesWithTransportIndexAndExcludeFilter(): void
    {
        $expectedAddresses = [
            ['pubkey_hash' => 'hash1', 'http' => 'http://one.example.com'],
        ];

        // Need to mock getAllAddressTypes for transport validation
        $repository = $this->getMockBuilder(TestableAddressRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getAllAddressTypes'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':toExclude', 'http://exclude.example.com');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($expectedAddresses);

        $result = $repository->getAllAddresses('http', 'http://exclude.example.com');

        $this->assertEquals($expectedAddresses, $result);
    }

    /**
     * Test getAllAddresses returns empty array on query failure
     */
    public function testGetAllAddressesReturnsEmptyArrayOnQueryFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getAllAddresses();

        $this->assertEquals([], $result);
    }

    /**
     * Test getAllAddresses returns empty array for invalid transport index
     */
    public function testGetAllAddressesReturnsEmptyArrayForInvalidTransportIndex(): void
    {
        $repository = $this->getMockBuilder(TestableAddressRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getAllAddressTypes'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $result = $repository->getAllAddresses('invalid_transport', 'http://test.example.com');

        $this->assertEquals([], $result);
    }

    // =========================================================================
    // getContactPubkeyHash Tests
    // =========================================================================

    /**
     * Test getContactPubkeyHash returns pubkey hash when found
     */
    public function testGetContactPubkeyHashReturnsPubkeyHashWhenFound(): void
    {
        $expectedHash = hash(Constants::HASH_ALGORITHM, 'test-key');

        $repository = $this->getMockBuilder(TestableAddressRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getAllAddressTypes'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':address', 'http://test.example.com');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn($expectedHash);

        $result = $repository->getContactPubkeyHash('http', 'http://test.example.com');

        $this->assertEquals($expectedHash, $result);
    }

    /**
     * Test getContactPubkeyHash returns null when not found
     */
    public function testGetContactPubkeyHashReturnsNullWhenNotFound(): void
    {
        $repository = $this->getMockBuilder(TestableAddressRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getAllAddressTypes'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn(false);

        $result = $repository->getContactPubkeyHash('http', 'http://nonexistent.example.com');

        $this->assertNull($result);
    }

    /**
     * Test getContactPubkeyHash returns null for invalid transport index
     */
    public function testGetContactPubkeyHashReturnsNullForInvalidTransportIndex(): void
    {
        $repository = $this->getMockBuilder(TestableAddressRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getAllAddressTypes'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $result = $repository->getContactPubkeyHash('invalid', 'http://test.example.com');

        $this->assertNull($result);
    }

    /**
     * Test getContactPubkeyHash validates transport index case-insensitively
     */
    public function testGetContactPubkeyHashValidatesTransportIndexCaseInsensitively(): void
    {
        $expectedHash = hash(Constants::HASH_ALGORITHM, 'test-key');

        $repository = $this->getMockBuilder(TestableAddressRepository::class)
            ->setConstructorArgs([$this->pdo])
            ->onlyMethods(['getAllAddressTypes'])
            ->getMock();

        $repository->expects($this->once())
            ->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchColumn')
            ->willReturn($expectedHash);

        // Use uppercase transport index
        $result = $repository->getContactPubkeyHash('HTTP', 'http://test.example.com');

        $this->assertEquals($expectedHash, $result);
    }

    // =========================================================================
    // getAllAddressTypes Tests
    // =========================================================================

    /**
     * Test getAllAddressTypes returns address types from database
     */
    public function testGetAllAddressTypesReturnsAddressTypesFromDatabase(): void
    {
        $expectedTypes = ['http', 'https', 'tor'];

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('bindValue')
            ->with(':table_name', 'addresses');

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_COLUMN)
            ->willReturn($expectedTypes);

        $result = $this->repository->getAllAddressTypes();

        $this->assertEquals($expectedTypes, $result);
    }

    /**
     * Test getAllAddressTypes returns fallback on query failure
     */
    public function testGetAllAddressTypesReturnsFallbackOnQueryFailure(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new PDOException('Query failed'));

        $result = $this->repository->getAllAddressTypes();

        $this->assertEquals(Constants::VALID_TRANSPORT_INDICES, $result);
    }

    /**
     * Test getAllAddressTypes returns fallback on empty result
     */
    public function testGetAllAddressTypesReturnsFallbackOnEmptyResult(): void
    {
        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($this->stmt);

        $this->stmt->expects($this->once())
            ->method('execute');

        $this->stmt->expects($this->once())
            ->method('fetchAll')
            ->with(PDO::FETCH_COLUMN)
            ->willReturn([]);

        $result = $this->repository->getAllAddressTypes();

        $this->assertEquals(Constants::VALID_TRANSPORT_INDICES, $result);
    }

    // =========================================================================
    // Allowed Columns Tests
    // =========================================================================

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
        $this->assertContains('pubkey_hash', $allowedColumns);
        $this->assertContains('http', $allowedColumns);
        $this->assertContains('https', $allowedColumns);
        $this->assertContains('tor', $allowedColumns);
    }
}

/**
 * Named testable subclass to bypass AbstractRepository constructor dependencies.
 * Anonymous classes cannot be used with getMockBuilder due to '@' in class names.
 */
class TestableAddressRepository extends AddressRepository
{
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->tableName = 'addresses';
        $this->primaryKey = 'pubkey_hash';
        $this->allowedColumns = ['id', 'pubkey_hash', 'http', 'https', 'tor'];
    }
}
