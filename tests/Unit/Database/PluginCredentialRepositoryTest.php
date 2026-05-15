<?php
/**
 * Unit Tests for PluginCredentialRepository
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\PluginCredentialRepository;
use PDO;
use PDOStatement;

#[CoversClass(PluginCredentialRepository::class)]
class PluginCredentialRepositoryTest extends TestCase
{
    private PluginCredentialRepository $repository;
    private PDO $mockPdo;
    private PDOStatement $mockStmt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockStmt = $this->createMock(PDOStatement::class);
        $this->repository = new PluginCredentialRepository($this->mockPdo);
    }

    public function testCreateCredentialReturnsTrueOnSuccessDespiteZeroLastInsertId(): void
    {
        // Regression: plugin_credentials has no AUTO_INCREMENT column, so
        // PDO::lastInsertId() returns the string "0" on a successful insert.
        // The previous (bool) cast returned false here, falsely reporting
        // every successful insert as a failure.
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(true);
        $this->mockPdo->method('lastInsertId')->willReturn('0');

        $this->assertTrue($this->repository->createCredential('my-plugin', '{"ciphertext":"x"}'));
    }

    public function testCreateCredentialReturnsFalseOnInsertFailure(): void
    {
        $this->mockPdo->method('prepare')->willReturn($this->mockStmt);
        $this->mockStmt->method('execute')->willReturn(false);

        $this->assertFalse($this->repository->createCredential('my-plugin', '{"ciphertext":"x"}'));
    }
}
