<?php
/**
 * Unit Tests for ChainOperationsService
 *
 * Tests chain verification and repair functionality including:
 * - Chain integrity verification between parties
 * - Previous txid resolution for new transactions
 * - Chain repair coordination through SyncService
 * - Setter injection for sync service
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\ChainOperationsService;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Core\UserContext;
use Eiou\Utils\Logger;
use Eiou\Contracts\SyncServiceInterface;
use Exception;

#[CoversClass(ChainOperationsService::class)]
class ChainOperationsServiceTest extends TestCase
{
    private ChainOperationsService $service;
    private TransactionChainRepository $mockChainRepo;
    private TransactionRepository $mockTxRepo;
    private UserContext $mockUserContext;
    private Logger $mockLogger;
    private SyncServiceInterface $mockSyncService;

    private const TEST_USER_PUBKEY = 'test-user-public-key-12345';
    private const TEST_CONTACT_PUBKEY = 'test-contact-public-key-67890';
    private const TEST_CONTACT_ADDRESS = '192.168.1.100:8080';
    private const TEST_TXID = 'abc123def456789012345678901234567890123456789012345678901234abcd';

    protected function setUp(): void
    {
        $this->mockChainRepo = $this->createMock(TransactionChainRepository::class);
        $this->mockTxRepo = $this->createMock(TransactionRepository::class);
        $this->mockUserContext = $this->createMock(UserContext::class);
        $this->mockLogger = $this->createMock(Logger::class);
        $this->mockSyncService = $this->createMock(SyncServiceInterface::class);

        $this->service = new ChainOperationsService(
            $this->mockChainRepo,
            $this->mockTxRepo,
            $this->mockUserContext,
            $this->mockLogger
        );
    }

    // =========================================================================
    // verifyChainIntegrity Tests
    // =========================================================================

    public function testVerifyChainIntegrityReturnsValidChainStatus(): void
    {
        $expectedStatus = [
            'valid' => true,
            'has_transactions' => true,
            'transaction_count' => 5,
            'gaps' => [],
            'broken_txids' => []
        ];

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn($expectedStatus);

        $this->mockLogger->expects($this->once())
            ->method('debug')
            ->with('Chain integrity verification completed', $this->anything());

        $result = $this->service->verifyChainIntegrity(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY);

        $this->assertTrue($result['valid']);
        $this->assertEquals(5, $result['transaction_count']);
        $this->assertEmpty($result['gaps']);
    }

    public function testVerifyChainIntegrityHandlesExceptionsGracefully(): void
    {
        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willThrowException(new Exception('Database connection lost'));

        $this->mockLogger->expects($this->once())
            ->method('logException');

        $result = $this->service->verifyChainIntegrity(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY);

        $this->assertFalse($result['valid']);
        $this->assertFalse($result['has_transactions']);
        $this->assertEquals(0, $result['transaction_count']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Database connection lost', $result['error']);
    }

    public function testVerifyChainIntegrityWithChainContainingGaps(): void
    {
        $expectedStatus = [
            'valid' => false,
            'has_transactions' => true,
            'transaction_count' => 10,
            'gaps' => ['missing-txid-1', 'missing-txid-2'],
            'broken_txids' => ['broken-1', 'broken-2']
        ];

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn($expectedStatus);

        $result = $this->service->verifyChainIntegrity(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY);

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['has_transactions']);
        $this->assertCount(2, $result['gaps']);
        $this->assertCount(2, $result['broken_txids']);
    }

    public function testVerifyChainIntegrityWithEmptyChain(): void
    {
        $expectedStatus = [
            'valid' => true,
            'has_transactions' => false,
            'transaction_count' => 0,
            'gaps' => [],
            'broken_txids' => []
        ];

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn($expectedStatus);

        $result = $this->service->verifyChainIntegrity(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY);

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['has_transactions']);
        $this->assertEquals(0, $result['transaction_count']);
    }

    // =========================================================================
    // getCorrectPreviousTxid Tests
    // =========================================================================

    public function testGetCorrectPreviousTxidReturnsTxidWhenExists(): void
    {
        $this->mockTxRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn(self::TEST_TXID);

        $this->mockLogger->expects($this->once())
            ->method('debug')
            ->with('Previous txid lookup completed', $this->anything());

        $result = $this->service->getCorrectPreviousTxid(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY);

        $this->assertEquals(self::TEST_TXID, $result);
    }

    public function testGetCorrectPreviousTxidReturnsNullWhenFirstTransaction(): void
    {
        $this->mockTxRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(null);

        $result = $this->service->getCorrectPreviousTxid(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY);

        $this->assertNull($result);
    }

    public function testGetCorrectPreviousTxidHandlesExceptions(): void
    {
        $this->mockTxRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willThrowException(new Exception('Query failed'));

        $this->mockLogger->expects($this->once())
            ->method('logException');

        $result = $this->service->getCorrectPreviousTxid(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY);

        $this->assertNull($result);
    }

    // =========================================================================
    // setSyncService Tests
    // =========================================================================

    public function testSetSyncServiceProperlySetsTheSyncService(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        // Verify sync service is set by triggering a repair that requires it
        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // Chain has gaps (not valid) - will need sync service
        $this->mockChainRepo->expects($this->atLeastOnce())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 3,
                'gaps' => ['missing-1'],
                'broken_txids' => ['broken-1']
            ]);

        // Sync service should be called (proves it was set)
        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn(['success' => false, 'synced_count' => 0, 'error' => 'test']);

        $result = $this->service->repairChainIfNeeded(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY);

        $this->assertTrue($result['repair_attempted']);
    }

    // =========================================================================
    // repairChainIfNeeded Tests
    // =========================================================================

    public function testRepairChainIfNeededWhenChainIsAlreadyValid(): void
    {
        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => true,
                'has_transactions' => true,
                'transaction_count' => 5,
                'gaps' => [],
                'broken_txids' => []
            ]);

        $result = $this->service->repairChainIfNeeded(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['was_valid']);
        $this->assertFalse($result['repair_attempted']);
    }

    public function testRepairChainIfNeededWhenSyncServiceNotAvailable(): void
    {
        // Do NOT call setSyncService - syncService stays null
        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 3,
                'gaps' => ['missing-1'],
                'broken_txids' => ['broken-1']
            ]);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('warning');

        $result = $this->service->repairChainIfNeeded(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['was_valid']);
        $this->assertFalse($result['repair_attempted']);
        $this->assertEquals('Sync service not available to repair chain', $result['error']);
    }

    public function testRepairChainIfNeededSuccessfulRepair(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $callCount = 0;
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // First call: chain has gaps
                    return [
                        'valid' => false,
                        'has_transactions' => true,
                        'transaction_count' => 3,
                        'gaps' => ['missing-1'],
                        'broken_txids' => ['broken-1']
                    ];
                }
                // Second call: chain repaired
                return [
                    'valid' => true,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => [],
                    'broken_txids' => []
                ];
            });

        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->with(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY)
            ->willReturn(['success' => true, 'synced_count' => 2]);

        $result = $this->service->repairChainIfNeeded(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['was_valid']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertEquals(2, $result['synced_count']);
    }

    public function testRepairChainIfNeededWhenRepairPartiallySucceeds(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 3,
                'gaps' => ['missing-1'],
                'broken_txids' => ['broken-1']
            ]);

        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn(['success' => true, 'synced_count' => 1]);

        $result = $this->service->repairChainIfNeeded(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertStringContainsString('gaps remaining', $result['error']);
    }

    public function testRepairChainIfNeededWhenSyncFailsButChainBecomesValid(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $callCount = 0;
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return [
                        'valid' => false,
                        'has_transactions' => true,
                        'transaction_count' => 3,
                        'gaps' => ['missing-1'],
                        'broken_txids' => ['broken-1']
                    ];
                }
                // Chain became valid despite sync failure
                return [
                    'valid' => true,
                    'has_transactions' => true,
                    'transaction_count' => 4,
                    'gaps' => [],
                    'broken_txids' => []
                ];
            });

        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn(['success' => false, 'synced_count' => 0, 'error' => 'timeout']);

        $result = $this->service->repairChainIfNeeded(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['repair_attempted']);
    }

    public function testRepairChainIfNeededWhenSyncCompletelyFails(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 3,
                'gaps' => ['missing-1'],
                'broken_txids' => ['broken-1']
            ]);

        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn(['success' => false, 'synced_count' => 0, 'error' => 'connection refused']);

        $result = $this->service->repairChainIfNeeded(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertStringContainsString('connection refused', $result['error']);
    }

    public function testRepairChainIfNeededHandlesExceptionDuringRepair(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 3,
                'gaps' => ['missing-1'],
                'broken_txids' => ['broken-1']
            ]);

        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willThrowException(new Exception('Unexpected error'));

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('logException');

        $result = $this->service->repairChainIfNeeded(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unexpected error', $result['error']);
    }

    public function testRepairChainIfNeededWithEmptyChain(): void
    {
        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => true,
                'has_transactions' => false,
                'transaction_count' => 0,
                'gaps' => [],
                'broken_txids' => []
            ]);

        $result = $this->service->repairChainIfNeeded(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['was_valid']);
        $this->assertFalse($result['repair_attempted']);
    }
}
