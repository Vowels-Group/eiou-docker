<?php
/**
 * Unit Tests for RepositoryFactory
 *
 * Tests the centralized repository creation and caching logic.
 */

namespace Eiou\Tests\Database;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\RepositoryFactory;
use Eiou\Database\AbstractRepository;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\DebugRepository;
use Eiou\Database\ApiKeyRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use PDO;
use InvalidArgumentException;

#[CoversClass(RepositoryFactory::class)]
class RepositoryFactoryTest extends TestCase
{
    private PDO $mockPdo;
    private RepositoryFactory $factory;

    protected function setUp(): void
    {
        $this->mockPdo = $this->createMock(PDO::class);
        $this->factory = new RepositoryFactory($this->mockPdo);
    }

    // =========================================================================
    // Basic Creation Tests
    // =========================================================================

    public function testGetCreatesRepositoryInstance(): void
    {
        $repo = $this->factory->get(AddressRepository::class);
        $this->assertInstanceOf(AddressRepository::class, $repo);
    }

    public function testGetReturnsSameInstanceOnSubsequentCalls(): void
    {
        $repo1 = $this->factory->get(AddressRepository::class);
        $repo2 = $this->factory->get(AddressRepository::class);
        $this->assertSame($repo1, $repo2);
    }

    public function testGetCreatesDifferentInstancesForDifferentClasses(): void
    {
        $address = $this->factory->get(AddressRepository::class);
        $balance = $this->factory->get(BalanceRepository::class);
        $this->assertNotSame($address, $balance);
    }

    // =========================================================================
    // All Repository Types
    // =========================================================================

    public function testGetCreatesAllRepositoryTypes(): void
    {
        $classes = [
            AddressRepository::class,
            BalanceRepository::class,
            ContactRepository::class,
            TransactionRepository::class,
            DebugRepository::class,
            ApiKeyRepository::class,
            P2pRepository::class,
            Rp2pRepository::class,
        ];

        foreach ($classes as $class) {
            $repo = $this->factory->get($class);
            $this->assertInstanceOf($class, $repo, "Failed for $class");
            $this->assertInstanceOf(AbstractRepository::class, $repo);
        }
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function testGetThrowsForNonRepositoryClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a subclass of');
        $this->factory->get(\stdClass::class);
    }

    // =========================================================================
    // has() and set()
    // =========================================================================

    public function testHasReturnsFalseBeforeCreation(): void
    {
        $this->assertFalse($this->factory->has(AddressRepository::class));
    }

    public function testHasReturnsTrueAfterCreation(): void
    {
        $this->factory->get(AddressRepository::class);
        $this->assertTrue($this->factory->has(AddressRepository::class));
    }

    public function testSetOverridesCachedInstance(): void
    {
        $original = $this->factory->get(AddressRepository::class);
        $replacement = new AddressRepository($this->mockPdo);

        $this->factory->set(AddressRepository::class, $replacement);

        $this->assertSame($replacement, $this->factory->get(AddressRepository::class));
        $this->assertNotSame($original, $this->factory->get(AddressRepository::class));
    }

    // =========================================================================
    // PDO Accessor
    // =========================================================================

    public function testGetPdoReturnsInjectedConnection(): void
    {
        $this->assertSame($this->mockPdo, $this->factory->getPdo());
    }
}
