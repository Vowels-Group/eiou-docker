<?php
/**
 * Unit Tests for ChainVerificationService
 *
 * Tests chain verification and sync coordination including:
 * - Sender chain verification with valid chains
 * - Sender chain verification with gaps triggering sync
 * - Sync trigger injection via setter
 * - Exception handling when sync trigger not injected
 * - Chain repair scenarios (successful, failed, partial)
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\ChainVerificationService;
use Eiou\Database\TransactionChainRepository;
use Eiou\Core\UserContext;
use Eiou\Utils\Logger;
use Eiou\Contracts\SyncTriggerInterface;
use RuntimeException;

#[CoversClass(ChainVerificationService::class)]
class ChainVerificationServiceTest extends TestCase
{
    private ChainVerificationService $service;
    private TransactionChainRepository $mockChainRepo;
    private UserContext $mockUserContext;
    private Logger $logger;
    private SyncTriggerInterface $mockSyncTrigger;

    private const TEST_USER_PUBKEY = 'test-user-public-key-12345';
    private const TEST_CONTACT_PUBKEY = 'test-contact-public-key-67890';
    private const TEST_CONTACT_ADDRESS = '192.168.1.100:8080';

    protected function setUp(): void
    {
        $this->mockChainRepo = $this->createMock(TransactionChainRepository::class);
        $this->mockUserContext = $this->createMock(UserContext::class);
        // Logger is injected into services via injected instance
        $this->logger = $this->createMock(Logger::class);
        $this->mockSyncTrigger = $this->createMock(SyncTriggerInterface::class);

        $this->service = new ChainVerificationService(
            $this->mockChainRepo,
            $this->mockUserContext,
            $this->logger,
            $this->mockSyncTrigger
        );
    }

    /**
     * Test verifySenderChainAndSync returns success when chain is valid
     */
    public function testVerifySenderChainAndSyncReturnsSuccessWhenChainIsValid(): void
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

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['synced']);
        $this->assertNull($result['error']);
    }

    /**
     * Test verifySenderChainAndSync returns success when chain is empty (first transaction)
     */
    public function testVerifySenderChainAndSyncReturnsSuccessWhenChainIsEmpty(): void
    {
        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                'valid' => true,
                'has_transactions' => false,
                'transaction_count' => 0,
                'gaps' => [],
                'broken_txids' => []
            ]);

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['synced']);
        $this->assertNull($result['error']);
    }

    /**
     * Test verifySenderChainAndSync triggers sync when gaps detected and sync succeeds
     */
    public function testVerifySenderChainAndSyncTriggersSyncWhenGapsDetected(): void
    {

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 5,
                'gaps' => ['missing-txid-1', 'missing-txid-2'],
                'broken_txids' => ['broken-txid-1']
            ]);

        // Logger methods are called via injected instance

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->with(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                'success' => true,
                'synced_count' => 2
            ]);

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['synced']);
        $this->assertNull($result['error']);
    }

    /**
     * Test verifySenderChainAndSync handles sync failure with chain still invalid
     */
    public function testVerifySenderChainAndSyncHandlesSyncFailureWithChainStillInvalid(): void
    {

        // getPublicKey is called twice: once for initial check, once for recheck after sync failure
        $this->mockUserContext->expects($this->exactly(2))
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // First verification shows gaps
        // After sync failure, second verification still shows gaps
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturnOnConsecutiveCalls(
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['missing-txid-1'],
                    'broken_txids' => []
                ],
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['missing-txid-1'],
                    'broken_txids' => []
                ]
            );

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->with(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY)
            ->willReturn([
                'success' => false,
                'synced_count' => 0,
                'error' => 'Network timeout'
            ]);

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['success']);
        $this->assertTrue($result['synced']);
        $this->assertStringContainsString('Failed to repair transaction chain', $result['error']);
        $this->assertStringContainsString('Network timeout', $result['error']);
    }

    /**
     * Test verifySenderChainAndSync succeeds when sync fails but chain becomes valid
     */
    public function testVerifySenderChainAndSyncSucceedsWhenSyncFailsButChainBecomesValid(): void
    {

        // getPublicKey is called twice: once for initial check, once for recheck after sync failure
        $this->mockUserContext->expects($this->exactly(2))
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // First verification shows gaps
        // After sync failure, second verification shows valid chain (race condition recovery)
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturnOnConsecutiveCalls(
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['missing-txid-1'],
                    'broken_txids' => []
                ],
                [
                    'valid' => true,
                    'has_transactions' => true,
                    'transaction_count' => 6,
                    'gaps' => [],
                    'broken_txids' => []
                ]
            );

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn([
                'success' => false,
                'synced_count' => 1,
                'error' => 'Partial sync completed'
            ]);

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['synced']);
        $this->assertNull($result['error']);
    }

    /**
     * Test verifySenderChainAndSync handles unknown sync error
     */
    public function testVerifySenderChainAndSyncHandlesUnknownSyncError(): void
    {

        // getPublicKey is called twice: once for initial check, once for recheck after sync failure
        $this->mockUserContext->expects($this->exactly(2))
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // First verification shows gaps
        // After sync failure with no error message, second verification still invalid
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturnOnConsecutiveCalls(
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['missing-txid-1'],
                    'broken_txids' => []
                ],
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['missing-txid-1'],
                    'broken_txids' => []
                ]
            );

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn([
                'success' => false,
                'synced_count' => 0
                // No 'error' key
            ]);

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['success']);
        $this->assertTrue($result['synced']);
        $this->assertStringContainsString('unknown error', $result['error']);
    }

    /**
     * Test constructor requires SyncTriggerInterface (TypeError if omitted)
     */
    public function testConstructorRequiresSyncTrigger(): void
    {
        $this->expectException(\TypeError::class);

        // @phpstan-ignore-next-line - intentionally passing wrong arg count
        new ChainVerificationService(
            $this->mockChainRepo,
            $this->mockUserContext,
            $this->logger
        );
    }

    /**
     * Test constructor injection of sync trigger enables sync operations
     */
    public function testConstructorInjectedSyncTriggerEnablesSyncOperations(): void
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
                'gaps' => ['missing-txid-1'],
                'broken_txids' => []
            ]);

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn([
                'success' => true,
                'synced_count' => 1
            ]);

        // Sync trigger was injected via constructor in setUp — should work
        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['synced']);
    }

    /**
     * Test verifySenderChainAndSync triggers sync when gaps are detected (verifies sync is called)
     */
    public function testVerifySenderChainAndSyncTriggersWhenGapsDetected(): void
    {

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 8,
                'gaps' => ['gap1', 'gap2', 'gap3'],
                'broken_txids' => []
            ]);

        // Logger methods are called via injected instance with context containing gap_count and transaction_count
        // We verify sync is triggered instead

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn(['success' => true, 'synced_count' => 3]);

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['synced']);
    }

    /**
     * Test verifySenderChainAndSync does not sync when chain is already valid
     */
    public function testVerifySenderChainAndSyncDoesNotSyncWhenChainIsAlreadyValid(): void
    {

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => true,
                'has_transactions' => true,
                'transaction_count' => 20,
                'gaps' => [],
                'broken_txids' => []
            ]);

        // Sync trigger should never be called when chain is valid
        $this->mockSyncTrigger->expects($this->never())
            ->method('syncTransactionChain');

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['synced']);
    }

    /**
     * Test verifySenderChainAndSync uses correct public keys for verification
     */
    public function testVerifySenderChainAndSyncUsesCorrectPublicKeysForVerification(): void
    {
        $customUserPubkey = 'custom-user-pubkey-abc123';
        $customContactPubkey = 'custom-contact-pubkey-xyz789';
        $customContactAddress = '10.0.0.50:9000';

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($customUserPubkey);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->with($customUserPubkey, $customContactPubkey)
            ->willReturn([
                'valid' => true,
                'has_transactions' => true,
                'transaction_count' => 5,
                'gaps' => [],
                'broken_txids' => []
            ]);

        $result = $this->service->verifySenderChainAndSync(
            $customContactAddress,
            $customContactPubkey
        );

        $this->assertTrue($result['success']);
    }

    /**
     * Test verifySenderChainAndSync passes correct parameters to syncTransactionChain
     */
    public function testVerifySenderChainAndSyncPassesCorrectParametersToSync(): void
    {

        $customContactAddress = '172.16.0.1:7777';
        $customContactPubkey = 'specific-contact-pubkey-for-sync';

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 3,
                'gaps' => ['gap1'],
                'broken_txids' => []
            ]);

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->with($customContactAddress, $customContactPubkey)
            ->willReturn(['success' => true, 'synced_count' => 1]);

        $this->service->verifySenderChainAndSync(
            $customContactAddress,
            $customContactPubkey
        );
    }

    /**
     * Test verifySenderChainAndSync result structure
     */
    public function testVerifySenderChainAndSyncResultStructure(): void
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

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        // Verify result structure contains all expected keys
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('synced', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertIsBool($result['success']);
        $this->assertIsBool($result['synced']);
    }

    /**
     * Test verifySenderChainAndSync with multiple gaps triggers sync
     */
    public function testVerifySenderChainAndSyncWithMultipleGapsTriggerSync(): void
    {

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        $gaps = ['gap1', 'gap2', 'gap3', 'gap4', 'gap5'];
        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 15,
                'gaps' => $gaps,
                'broken_txids' => []
            ]);

        // Logger methods are called via injected instance, we verify sync instead

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn(['success' => true, 'synced_count' => 5]);

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['synced']);
    }

    /**
     * Test verifySenderChainAndSync only rechecks chain when sync reports failure
     */
    public function testVerifySenderChainAndSyncOnlyRechecksChainWhenSyncReportsFailure(): void
    {

        $this->mockUserContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // When sync succeeds, verifyChainIntegrity should only be called once
        $this->mockChainRepo->expects($this->once())
            ->method('verifyChainIntegrity')
            ->willReturn([
                'valid' => false,
                'has_transactions' => true,
                'transaction_count' => 5,
                'gaps' => ['gap1'],
                'broken_txids' => []
            ]);

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn(['success' => true, 'synced_count' => 1]);

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        // Sync succeeded, so we trust it repaired the chain
        $this->assertTrue($result['success']);
    }

    /**
     * Test verifySenderChainAndSync rechecks chain when sync fails
     */
    public function testVerifySenderChainAndSyncRechecksChainWhenSyncFails(): void
    {

        // getPublicKey is called twice: once for initial check, once for recheck after sync failure
        $this->mockUserContext->expects($this->exactly(2))
            ->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);

        // When sync fails, verifyChainIntegrity should be called twice
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('verifyChainIntegrity')
            ->willReturnOnConsecutiveCalls(
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['gap1'],
                    'broken_txids' => []
                ],
                [
                    'valid' => false,
                    'has_transactions' => true,
                    'transaction_count' => 5,
                    'gaps' => ['gap1'],
                    'broken_txids' => []
                ]
            );

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->willReturn(['success' => false, 'synced_count' => 0, 'error' => 'Sync failed']);

        $result = $this->service->verifySenderChainAndSync(
            self::TEST_CONTACT_ADDRESS,
            self::TEST_CONTACT_PUBKEY
        );

        $this->assertFalse($result['success']);
    }
}
