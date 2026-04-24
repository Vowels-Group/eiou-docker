<?php
/**
 * Unit Tests for PaybackMethodReceivedRepository
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\PaybackMethodReceivedRepository;
use PDO;
use PDOStatement;

#[CoversClass(PaybackMethodReceivedRepository::class)]
class PaybackMethodReceivedRepositoryTest extends TestCase
{
    private PaybackMethodReceivedRepository $repository;
    private PDO $mockPdo;
    private PDOStatement $mockStmt;

    private const CONTACT = 'pubkeyhash0000000000000000000000000000000000000000000000000000fe';
    private const REMOTE_ID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->repository = new PaybackMethodReceivedRepository($this->mockPdo);
    }

    public function testTableName(): void
    {
        $ref = new \ReflectionClass($this->repository);
        $prop = $ref->getProperty('tableName');
        $prop->setAccessible(true);
        $this->assertEquals('payback_methods_received', $prop->getValue($this->repository));
    }

    public function testFindByPairReturnsRow(): void
    {
        $row = [
            'id' => 1,
            'contact_pubkey_hash' => self::CONTACT,
            'remote_method_id' => self::REMOTE_ID,
            'type' => 'btc', 'label' => 'Cold', 'currency' => 'BTC',
            'fields_json' => '{"address":"bc1q..."}',
        ];
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetch')->willReturn($row);

        $res = $this->repository->findByPair(self::CONTACT, self::REMOTE_ID);
        $this->assertEquals(self::REMOTE_ID, $res['remote_method_id']);
    }

    public function testFindByPairReturnsNullWhenMissing(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetch')->willReturn(false);

        $this->assertNull($this->repository->findByPair(self::CONTACT, 'nope'));
    }

    public function testListFreshExcludesExpiredAndRevoked(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('revoked_at IS NULL'),
                $this->stringContains('expires_at > CURRENT_TIMESTAMP(6)')
            ))
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetchAll')->willReturn([]);

        $this->repository->listFreshForContact(self::CONTACT);
    }

    public function testMarkRevokedWithEmptyListReturnsZero(): void
    {
        $this->mockPdo->expects($this->never())->method('prepare');
        $this->assertSame(0, $this->repository->markRevoked(self::CONTACT, []));
    }

    public function testMarkRevokedRunsUpdate(): void
    {
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('revoked_at = CURRENT_TIMESTAMP(6)'))
            ->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('rowCount')->willReturn(2);

        $this->assertSame(
            2,
            $this->repository->markRevoked(self::CONTACT, ['r1', 'r2'])
        );
    }

    public function testHasFreshTrue(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetch')->willReturn(['1' => 1]);

        $this->assertTrue($this->repository->hasFresh(self::CONTACT));
    }

    public function testHasFreshFalse(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockStmt->method('fetch')->willReturn(false);

        $this->assertFalse($this->repository->hasFresh(self::CONTACT));
    }
}
