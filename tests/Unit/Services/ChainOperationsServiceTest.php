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
use Eiou\Utils\SecureLogger;
use Eiou\Contracts\SyncServiceInterface;
use Exception;

#[CoversClass(ChainOperationsService::class)]
class ChainOperationsServiceTest extends TestCase
{
    private ChainOperationsService $service;
    private TransactionChainRepository $mockChainRepo;
    private TransactionRepository $mockTxRepo;
    private UserContext $mockUserContext;
    private SecureLogger $mockLogger;
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
        $this->mockLogger = $this->createMock(SecureLogger::class);
        $this->mockSyncService = $this->createMock(SyncServiceInterface::class);

        $this->service = new ChainOperationsService(
            $this->mockChainRepo,
            $this->mockTxRepo,
            $this->mockUserContext,
            $this->mockLogger
        );
    }

    /**
     * Test verifyChainIntegrity returns valid chain status
     */
    public function testVerifyChainIntegrityReturnsValidChainStatus(): void
    {
        $expectedResult = [
            'valid' => true,
            'has_transactions' => true,
            'transaction_count' => 5,
            'gaps' => [],
            'broken_txids' => []
        ];

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn($expectedResult);

        $this->mockLogger->expects($this->once())
            ->method('debug')
            ->with(
                'Chain integrity verification completed',
                $this->callback(function ($context) {
                    return $context['valid'] === true
                        && $context['transaction_count'] === 5
                        && $context['gap_count'] === 0;
                })
            );

        $result = $this->service->verifyChainIntegrity(
            self::TEST_USER_PUBKEY,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['has_transactions']);
        $this->assertEquals(5, $result['transaction_count']);
        $this->assertEmpty($result['gaps']);
        $this->assertEmpty($result['broken_txids']);
    }

    /**
     * Test verifyChainIntegrity handles exceptions gracefully
     */
    public function testVerifyChainIntegrityHandlesExceptionsGracefully(): void
    {
        $exception = new Exception('Database connection failed');

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willThrowException($exception);

        $this->mockLogger->expects($this->once())
            ->method('logException')
            ->with(
                $exception,
                $this->callback(function ($context) {
                    return $context['method'] === 'verifyChainIntegrity'
                        && isset($context['user_pubkey_hash'])
                        && isset($context['contact_pubkey_hash']);
                })
            );

        $result = $this->service->verifyChainIntegrity(
            self::TEST_USER_PUBKEY,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['valid']);
        $this->assertFalse($result['has_transactions']);
        $this->assertEquals(0, $result['transaction_count']);
        $this->assertEmpty($result['gaps']);
        $this->assertEmpty($result['broken_txids']);
        $this->assertEquals('Database connection failed', $result['error']);
    }

    /**
     * Test getCorrectPreviousTxid returns txid when exists
     */
    public function testGetCorrectPreviousTxidReturnsTxidWhenExists(): void
    {
        $this->mockTxRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn(self::TEST_TXID);

        $this->mockLogger->expects($this->once())
            ->method('debug')
            ->with(
                'Previous txid lookup completed',
                $this->callback(function ($context) {
                    return $context['has_previous'] === true
                        && strpos($context['previous_txid'], 'abc123def4567890') === 0;
                })
            );

        $result = $this->service->getCorrectPreviousTxid(
            self::TEST_USER_PUBKEY,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertEquals(self::TEST_TXID, $result);
    }

    /**
     * Test getCorrectPreviousTxid returns null when first transaction
     */
    public function testGetCorrectPreviousTxidReturnsNullWhenFirstTransaction(): void
    {
        $this->mockTxRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn(null);

        $this->mockLogger->expects($this->once())
            ->method('debug')
            ->with(
                'Previous txid lookup completed',
                $this->callback(function ($context) {
                    return $context['has_previous'] === false
                        && $context['previous_txid'] === null;
                })
            );

        $result = $this->service->getCorrectPreviousTxid(
            self::TEST_USER_PUBKEY,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertNull($result);
    }

    /**
     * Test getCorrectPreviousTxid handles exceptions
     */
    public function testGetCorrectPreviousTxidHandlesExceptions(): void
    {
        $exception = new Exception('Query failed');

        $this->mockTxRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willThrowException($exception);

        $this->mockLogger->expects($this->once())
            ->method('logException')
            ->with(
                $exception,
                $this->callback(function ($context) {
                    return $context['method'] === 'getCorrectPreviousTxid'
                        && isset($context['user_pubkey_hash'])
                        && isset($context['contact_pubkey_hash']);
                })
            );

        $result = $this->service->getCorrectPreviousTxid(
            self::TEST_USER_PUBKEY,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertNull($result);
    }

    /**
     * Test repairChainIfNeeded when chain is already valid
     */
    public function testRepairChainIfNeededWhenChainIsAlreadyValid(): void
    {
        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                'valid' => true,
                'has_transactions' => true,
                'transaction_count' => 10,
                'gaps' => [],
                'broken_txids' => []
            ]);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('debug');

        $result = $this->service->repairChainIfNeeded(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['was_valid']);
        $this->assertFalse($result['repair_attempted']);
        $this->assertEquals(0, $result['synced_count']);
        $this->assertNull($result['error']);
    }

    /**
     * Test repairChainIfNeeded when sync service not available
     */
    public function testRepairChainIfNeededWhenSyncServiceNotAvailable(): void
    {
        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 5,
                'gaps' => ['missing-txid-1', 'missing-txid-2'],
                'broken_txids' => ['broken-txid-1']
            ]);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('warning');

        // Note: setSyncService is NOT called, so sync service is null
        $result = $this->service->repairChainIfNeeded(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['success']);
        $this->assertFalse($result['was_valid']);
        $this->assertFalse($result['repair_attempted']);
        $this->assertEquals(0, $result['synced_count']);
        $this->assertEquals('Sync service not available to repair chain', $result['error']);
    }

    /**
     * Test repairChainIfNeeded successful repair
     */
    public function testRepairChainIfNeededSuccessfulRepair(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // First verification shows gaps
        // Second verification after sync shows valid chain
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturnOnConsecutiveCalls(
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['missing-txid-1'],
                    'broken_txids' => ['broken-txid-1']
                ],
                [
                    'valid' => true,
                    'has_transactions' => true,
                    'transaction_count' => 6,
                    'gaps' => [],
                    'broken_txids' => []
                ]
            );

        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->with(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                'success' => true,
                'synced_count' => 1
            ]);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->service->repairChainIfNeeded(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['was_valid']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertEquals(1, $result['synced_count']);
        $this->assertNull($result['error']);
    }

    /**
     * Test repairChainIfNeeded when repair partially succeeds
     */
    public function testRepairChainIfNeededWhenRepairPartiallySucceeds(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // First verification shows gaps
        // Second verification after sync still shows gaps (incomplete repair)
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturnOnConsecutiveCalls(
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['missing-txid-1', 'missing-txid-2', 'missing-txid-3'],
                    'broken_txids' => ['broken-txid-1', 'broken-txid-2']
                ],
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 7,
                    'gaps' => ['missing-txid-3'],
                    'broken_txids' => ['broken-txid-2']
                ]
            );

        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn([
                'success' => true,
                'synced_count' => 2
            ]);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('warning');

        $result = $this->service->repairChainIfNeeded(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['success']);
        $this->assertFalse($result['was_valid']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertEquals(2, $result['synced_count']);
        $this->assertStringContainsString('gaps remaining', $result['error']);
    }

    /**
     * Test repairChainIfNeeded when sync fails but chain becomes valid
     */
    public function testRepairChainIfNeededWhenSyncFailsButChainBecomesValid(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // First verification shows gaps
        // Second verification after failed sync shows valid chain
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturnOnConsecutiveCalls(
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['missing-txid-1'],
                    'broken_txids' => ['broken-txid-1']
                ],
                [
                    'valid' => true,
                    'has_transactions' => true,
                    'transaction_count' => 6,
                    'gaps' => [],
                    'broken_txids' => []
                ]
            );

        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn([
                'success' => false,
                'synced_count' => 1,
                'error' => 'Connection timeout'
            ]);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->service->repairChainIfNeeded(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['was_valid']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertEquals(1, $result['synced_count']);
        $this->assertNull($result['error']);
    }

    /**
     * Test repairChainIfNeeded when sync completely fails
     */
    public function testRepairChainIfNeededWhenSyncCompletelyFails(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // First verification shows gaps
        // Second verification after failed sync still shows gaps
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturnOnConsecutiveCalls(
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['missing-txid-1'],
                    'broken_txids' => ['broken-txid-1']
                ],
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['missing-txid-1'],
                    'broken_txids' => ['broken-txid-1']
                ]
            );

        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn([
                'success' => false,
                'synced_count' => 0,
                'error' => 'Network unreachable'
            ]);

        $this->mockLogger->expects($this->atLeastOnce())
            ->method('warning');

        $result = $this->service->repairChainIfNeeded(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['success']);
        $this->assertFalse($result['was_valid']);
        $this->assertTrue($result['repair_attempted']);
        $this->assertEquals(0, $result['synced_count']);
        $this->assertStringContainsString('Network unreachable', $result['error']);
    }

    /**
     * Test repairChainIfNeeded handles exception during repair
     */
    public function testRepairChainIfNeededHandlesExceptionDuringRepair(): void
    {
        $this->service->setSyncService($this->mockSyncService);

        $exception = new Exception('Unexpected error during sync');

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 5,
                'gaps' => ['missing-txid-1'],
                'broken_txids' => ['broken-txid-1']
            ]);

        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willThrowException($exception);

        $this->mockLogger->expects($this->once())
            ->method('logException')
            ->with(
                $exception,
                $this->callback(function ($context) {
                    return $context['method'] === 'repairChainIfNeeded'
                        && $context['contact_address'] === self::TEST_CONTACT_ADDRESS;
                })
            );

        $result = $this->service->repairChainIfNeeded(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Exception during chain repair', $result['error']);
    }

    /**
     * Test setSyncService properly sets the sync service
     */
    public function testSetSyncServiceProperlySetsTheSyncService(): void
    {
        // Initially, sync service is null - repair should fail due to missing service
        $this->mockUserContext->expects($this->exactly(2))
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 5,
                'gaps' => ['missing-txid-1'],
                'broken_txids' => ['broken-txid-1']
            ]);

        // First call without sync service
        $resultBefore = $this->service->repairChainIfNeeded(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($resultBefore['repair_attempted']);
        $this->assertEquals('Sync service not available to repair chain', $resultBefore['error']);

        // Now set the sync service
        $this->service->setSyncService($this->mockSyncService);

        // Configure mock for second call
        $this->mockSyncService->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn([
                'success' => true,
                'synced_count' => 1
            ]);

        // Second call with sync service set
        $resultAfter = $this->service->repairChainIfNeeded(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($resultAfter['repair_attempted']);
    }

    /**
     * Test verifyChainIntegrity with chain containing gaps
     */
    public function testVerifyChainIntegrityWithChainContainingGaps(): void
    {
        $expectedResult = [
            'valid' => false,
            'has_transactions' => true,
            'transaction_count' => 10,
            'gaps' => ['missing-txid-1', 'missing-txid-2'],
            'broken_txids' => ['broken-txid-1', 'broken-txid-2']
        ];

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn($expectedResult);

        $this->mockLogger->expects($this->once())
            ->method('debug')
            ->with(
                'Chain integrity verification completed',
                $this->callback(function ($context) {
                    return $context['valid'] === false
                        && $context['transaction_count'] === 10
                        && $context['gap_count'] === 2;
                })
            );

        $result = $this->service->verifyChainIntegrity(
            self::TEST_USER_PUBKEY,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['has_transactions']);
        $this->assertEquals(10, $result['transaction_count']);
        $this->assertCount(2, $result['gaps']);
        $this->assertCount(2, $result['broken_txids']);
    }

    /**
     * Test verifyChainIntegrity with empty chain (no transactions)
     */
    public function testVerifyChainIntegrityWithEmptyChain(): void
    {
        $expectedResult = [
            'valid' => true,
            'has_transactions' => false,
            'transaction_count' => 0,
            'gaps' => [],
            'broken_txids' => []
        ];

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn($expectedResult);

        $result = $this->service->verifyChainIntegrity(
            self::TEST_USER_PUBKEY,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['has_transactions']);
        $this->assertEquals(0, $result['transaction_count']);
    }

    /**
     * Test repairChainIfNeeded with empty chain (no repair needed)
     */
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

        $result = $this->service->repairChainIfNeeded(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['was_valid']);
        $this->assertFalse($result['repair_attempted']);
    }
}
