<?php
/**
 * Unit Tests for PaybackMethodRepository
 *
 * Covers CRUD and listing behaviours for the payback_methods table.
 * Encryption of `encrypted_fields` is the service's responsibility; this
 * repository only sees the JSON string, so tests pass placeholder JSON.
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\PaybackMethodRepository;
use PDO;
use PDOStatement;

#[CoversClass(PaybackMethodRepository::class)]
class PaybackMethodRepositoryTest extends TestCase
{
    private PaybackMethodRepository $repository;
    private PDO $mockPdo;
    private PDOStatement $mockStmt;

    private const METHOD_ID = '11111111-2222-3333-4444-555555555555';
    private const PLACEHOLDER_ENC = '{"ciphertext":"aaa","iv":"bbb","tag":"ccc","aad":"payback:11111111-2222-3333-4444-555555555555","version":1}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->repository = new PaybackMethodRepository($this->mockPdo);
    }

    // ========================================================================
    // Configuration
    // ========================================================================

    public function testConstructorSetsTableName(): void
    {
        $ref = new \ReflectionClass($this->repository);
        $prop = $ref->getProperty('tableName');
        $prop->setAccessible(true);
        $this->assertEquals('payback_methods', $prop->getValue($this->repository));
    }

    public function testAllowedColumnsIncludeKeyFields(): void
    {
        $ref = new \ReflectionClass($this->repository);
        $prop = $ref->getProperty('allowedColumns');
        $prop->setAccessible(true);
        $allowed = $prop->getValue($this->repository);

        foreach ([
            'method_id', 'type', 'label', 'currency',
            'encrypted_fields', 'fields_version',
            'settlement_min_unit', 'settlement_min_unit_exponent',
            'priority', 'enabled', 'share_policy',
        ] as $col) {
            $this->assertContains($col, $allowed, "Missing column: $col");
        }
    }

    // ========================================================================
    // createMethod()
    // ========================================================================

    public function testCreateMethodInserts(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockPdo->method('lastInsertId')->willReturn('17');

        $result = $this->repository->createMethod([
            'method_id' => self::METHOD_ID,
            'type' => 'btc',
            'label' => 'Cold wallet',
            'currency' => 'BTC',
            'encrypted_fields' => self::PLACEHOLDER_ENC,
            'fields_version' => 1,
            'settlement_min_unit' => 1,
            'settlement_min_unit_exponent' => -8,
            'priority' => 100,
            'enabled' => 1,
            'share_policy' => 'auto',
        ]);

        $this->assertNotFalse($result);
    }

    public function testCreateMethodRejectsUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->createMethod([
            'method_id' => self::METHOD_ID,
            'not_a_real_column' => 'boom',
        ]);
    }

    // ========================================================================
    // getByMethodId()
    // ========================================================================

    public function testGetByMethodIdReturnsRow(): void
    {
        $expected = [
            'id' => 1,
            'method_id' => self::METHOD_ID,
            'type' => 'btc',
            'label' => 'Cold wallet',
            'currency' => 'BTC',
            'encrypted_fields' => self::PLACEHOLDER_ENC,
            'enabled' => 1,
            'share_policy' => 'auto',
            'priority' => 100,
        ];
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetch')->willReturn($expected);

        $result = $this->repository->getByMethodId(self::METHOD_ID);
        $this->assertNotNull($result);
        $this->assertEquals(self::METHOD_ID, $result['method_id']);
        $this->assertEquals('btc', $result['type']);
    }

    public function testGetByMethodIdReturnsNullWhenMissing(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetch')->willReturn(false);

        $this->assertNull($this->repository->getByMethodId('missing'));
    }

    // ========================================================================
    // listMethods()
    // ========================================================================

    public function testListMethodsNoFiltersDefaultsToEnabledOnly(): void
    {
        $rows = [
            ['id' => 1, 'method_id' => self::METHOD_ID, 'currency' => 'USD', 'priority' => 10, 'enabled' => 1],
            ['id' => 2, 'method_id' => 'm2', 'currency' => 'BTC', 'priority' => 20, 'enabled' => 1],
        ];

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('enabled = 1'))
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetchAll')->willReturn($rows);

        $result = $this->repository->listMethods();
        $this->assertCount(2, $result);
    }

    public function testListMethodsWithCurrencyFilter(): void
    {
        $rows = [['id' => 1, 'currency' => 'EUR', 'enabled' => 1, 'priority' => 5]];
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('currency = :currency'))
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetchAll')->willReturn($rows);

        $result = $this->repository->listMethods('EUR');
        $this->assertCount(1, $result);
        $this->assertEquals('EUR', $result[0]['currency']);
    }

    public function testListMethodsIncludingDisabled(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalNot($this->stringContains('enabled = 1')))
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->repository->listMethods(null, false));
    }

    // ========================================================================
    // listShareableForCurrency()
    // ========================================================================

    public function testListShareableExcludesNeverPolicy(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains("share_policy != 'never'"))
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetchAll')->willReturn([]);

        $this->repository->listShareableForCurrency('USD');
    }

    // ========================================================================
    // updateByMethodId()
    // ========================================================================

    public function testUpdateByMethodIdRunsUpdate(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('rowCount')->willReturn(1);

        $affected = $this->repository->updateByMethodId(self::METHOD_ID, [
            'label' => 'New Label',
            'priority' => 1,
        ]);
        $this->assertSame(1, $affected);
    }

    public function testUpdateByMethodIdNoopWhenChangesEmpty(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');
        $this->assertSame(0, $this->repository->updateByMethodId(self::METHOD_ID, []));
    }

    // ========================================================================
    // deleteByMethodId()
    // ========================================================================

    public function testDeleteByMethodId(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('rowCount')->willReturn(1);

        $this->assertSame(1, $this->repository->deleteByMethodId(self::METHOD_ID));
    }

    // ========================================================================
    // countEnabled()
    // ========================================================================

    public function testCountEnabled(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetch')->willReturn(['c' => 3]);

        $this->assertSame(3, $this->repository->countEnabled());
    }
}
