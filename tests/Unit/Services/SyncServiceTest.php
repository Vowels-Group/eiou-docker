<?php
/**
 * Unit Tests for SyncService
 *
 * Tests synchronization functionality including:
 * - Contact sync operations
 * - Transaction chain sync
 * - Balance sync operations
 * - Sync request handling
 * - Signature verification
 * - Chain conflict resolution
 * - Bidirectional sync negotiation
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Eiou\Services\SyncService;
use Eiou\Services\HeldTransactionService;
use Eiou\Database\ContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\Rp2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionChainRepository;
use Eiou\Database\TransactionContactRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Cli\CliOutputManager;
use RuntimeException;

#[CoversClass(SyncService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SyncServiceTest extends TestCase
{
    private SyncService $service;
    private ContactRepository $mockContactRepo;
    private AddressRepository $mockAddressRepo;
    private P2pRepository $mockP2pRepo;
    private Rp2pRepository $mockRp2pRepo;
    private TransactionRepository $mockTransactionRepo;
    private TransactionChainRepository $mockChainRepo;
    private TransactionContactRepository $mockTxContactRepo;
    private BalanceRepository $mockBalanceRepo;
    private UtilityServiceContainer $mockUtilityContainer;
    private TransportUtilityService $mockTransportUtility;
    private UserContext $mockUserContext;
    private HeldTransactionService $mockHeldTransactionService;

    protected function setUp(): void
    {
        $this->mockContactRepo = $this->createMock(ContactRepository::class);
        $this->mockAddressRepo = $this->createMock(AddressRepository::class);
        $this->mockP2pRepo = $this->createMock(P2pRepository::class);
        $this->mockRp2pRepo = $this->createMock(Rp2pRepository::class);
        $this->mockTransactionRepo = $this->createMock(TransactionRepository::class);
        $this->mockChainRepo = $this->createMock(TransactionChainRepository::class);
        $this->mockTxContactRepo = $this->createMock(TransactionContactRepository::class);
        $this->mockBalanceRepo = $this->createMock(BalanceRepository::class);
        $this->mockUtilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->mockTransportUtility = $this->createMock(TransportUtilityService::class);
        $this->mockUserContext = $this->createMock(UserContext::class);
        $this->mockHeldTransactionService = $this->createMock(HeldTransactionService::class);

        $this->mockUtilityContainer->expects($this->any())
            ->method('getTransportUtility')
            ->willReturn($this->mockTransportUtility);

        $this->service = new SyncService(
            $this->mockContactRepo,
            $this->mockAddressRepo,
            $this->mockP2pRepo,
            $this->mockRp2pRepo,
            $this->mockTransactionRepo,
            $this->mockChainRepo,
            $this->mockTxContactRepo,
            $this->mockBalanceRepo,
            $this->mockUtilityContainer,
            $this->mockUserContext
        );
    }

    // =========================================================================
    // Constructor and Setter Tests
    // =========================================================================

    /**
     * Test service instantiation with all dependencies
     */
    public function testServiceInstantiationWithAllDependencies(): void
    {
        $service = new SyncService(
            $this->mockContactRepo,
            $this->mockAddressRepo,
            $this->mockP2pRepo,
            $this->mockRp2pRepo,
            $this->mockTransactionRepo,
            $this->mockChainRepo,
            $this->mockTxContactRepo,
            $this->mockBalanceRepo,
            $this->mockUtilityContainer,
            $this->mockUserContext
        );

        $this->assertInstanceOf(SyncService::class, $service);
    }

    /**
     * Test setHeldTransactionService sets the service
     */
    public function testSetHeldTransactionServiceSetsTheService(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        // Service should be set - we'll verify by checking it doesn't throw later
        $this->expectNotToPerformAssertions();
    }

    // =========================================================================
    // CLI Sync Entry Point Tests
    // =========================================================================

    /**
     * Test sync with contacts argument syncs contacts
     */
    public function testSyncWithContactsArgumentSyncsContacts(): void
    {
        $output = $this->createMock(CliOutputManager::class);

        $this->mockAddressRepo->expects($this->once())
            ->method('getAllAddresses')
            ->willReturn([]);

        $output->expects($this->once())
            ->method('success')
            ->with('Contacts synced', $this->anything(), $this->anything());

        $argv = ['eiou', 'sync', 'contacts'];
        $this->service->sync($argv, $output);
    }

    /**
     * Test sync with transactions argument syncs transactions
     */
    public function testSyncWithTransactionsArgumentSyncsTransactions(): void
    {
        $output = $this->createMock(CliOutputManager::class);

        $this->mockAddressRepo->expects($this->once())
            ->method('getAllAddresses')
            ->willReturn([]);

        $output->expects($this->once())
            ->method('success')
            ->with('Transactions synced', $this->anything(), $this->anything());

        $argv = ['eiou', 'sync', 'transactions'];
        $this->service->sync($argv, $output);
    }

    /**
     * Test sync with balances argument syncs balances
     */
    public function testSyncWithBalancesArgumentSyncsBalances(): void
    {
        $output = $this->createMock(CliOutputManager::class);

        $this->mockContactRepo->expects($this->once())
            ->method('getAllContactsPubkeys')
            ->willReturn([]);

        $output->expects($this->once())
            ->method('success')
            ->with('Balances synced', $this->anything(), $this->anything());

        $argv = ['eiou', 'sync', 'balances'];
        $this->service->sync($argv, $output);
    }

    /**
     * Test sync with invalid argument returns error
     */
    public function testSyncWithInvalidArgumentReturnsError(): void
    {
        $output = $this->createMock(CliOutputManager::class);

        $output->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Invalid sync type'),
                $this->anything(),
                400,
                $this->callback(function ($data) {
                    return isset($data['valid_types']);
                })
            );

        $argv = ['eiou', 'sync', 'invalid'];
        $this->service->sync($argv, $output);
    }

    /**
     * Test sync without argument syncs all
     */
    public function testSyncWithoutArgumentSyncsAll(): void
    {
        $output = $this->createMock(CliOutputManager::class);

        $this->mockAddressRepo->expects($this->atLeast(1))
            ->method('getAllAddresses')
            ->willReturn([]);

        $this->mockContactRepo->expects($this->once())
            ->method('getAllContactsPubkeys')
            ->willReturn([]);

        $output->expects($this->once())
            ->method('success')
            ->with('Sync completed', $this->anything(), $this->anything());

        $argv = ['eiou', 'sync'];
        $this->service->sync($argv, $output);
    }

    // =========================================================================
    // syncAllContacts Tests
    // =========================================================================

    /**
     * Test syncAllContacts with empty contacts
     */
    public function testSyncAllContactsWithEmptyContacts(): void
    {
        $output = $this->createMock(CliOutputManager::class);

        $this->mockAddressRepo->expects($this->once())
            ->method('getAllAddresses')
            ->willReturn([]);

        $output->expects($this->once())
            ->method('success')
            ->with(
                'Contacts synced',
                $this->callback(function ($data) {
                    return $data['total'] === 0 && $data['synced'] === 0;
                }),
                $this->anything()
            );

        $this->service->syncAllContacts($output);
    }

    /**
     * Test syncAllContacts processes contacts
     */
    public function testSyncAllContactsProcessesContacts(): void
    {
        $output = $this->createMock(CliOutputManager::class);

        $contacts = [
            ['tor' => 'contact1.onion', 'status' => Constants::CONTACT_STATUS_ACCEPTED],
            ['http' => 'http://contact2.example.com', 'status' => Constants::CONTACT_STATUS_ACCEPTED]
        ];

        $this->mockAddressRepo->expects($this->once())
            ->method('getAllAddresses')
            ->willReturn($contacts);

        // Contact lookup for sync
        $this->mockTransportUtility->expects($this->any())
            ->method('determineTransportType')
            ->willReturn('tor');

        $this->mockContactRepo->expects($this->any())
            ->method('getContactByAddress')
            ->willReturn(['status' => Constants::CONTACT_STATUS_ACCEPTED]);

        $output->expects($this->once())
            ->method('success')
            ->with(
                'Contacts synced',
                $this->callback(function ($data) {
                    return $data['total'] === 2;
                }),
                $this->anything()
            );

        $this->service->syncAllContacts($output);
    }

    // =========================================================================
    // syncSingleContact Tests
    // =========================================================================

    /**
     * Test syncSingleContact with invalid address returns false
     */
    public function testSyncSingleContactWithInvalidAddressReturnsFalse(): void
    {
        $this->mockTransportUtility->expects($this->once())
            ->method('determineTransportType')
            ->with('invalid-address')
            ->willReturn(null);

        $result = $this->service->syncSingleContact('invalid-address', 'SILENT');

        $this->assertFalse($result);
    }

    /**
     * Test syncSingleContact with accepted contact returns true
     */
    public function testSyncSingleContactWithAcceptedContactReturnsTrue(): void
    {
        $contactAddress = 'http://contact.example.com';

        $this->mockTransportUtility->expects($this->once())
            ->method('determineTransportType')
            ->with($contactAddress)
            ->willReturn('http');

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByAddress')
            ->with('http', $contactAddress)
            ->willReturn(['status' => Constants::CONTACT_STATUS_ACCEPTED]);

        $result = $this->service->syncSingleContact($contactAddress, 'SILENT');

        $this->assertTrue($result);
    }

    // =========================================================================
    // syncTransactionChain Tests
    // =========================================================================

    /**
     * Test syncTransactionChain returns error on invalid response
     */
    public function testSyncTransactionChainReturnsErrorOnInvalidResponse(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $contactAddress = 'http://contact.example.com';
        $contactPubkey = 'contact-public-key';

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(null);

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn('invalid json response');

        // Note: onSyncComplete is NOT called on invalid response - method returns early

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid sync response', $result['error']);
    }

    /**
     * Test syncTransactionChain returns error on rejected response
     */
    public function testSyncTransactionChainReturnsErrorOnRejectedResponse(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $contactAddress = 'http://contact.example.com';
        $contactPubkey = 'contact-public-key';

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(null);

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_REJECTED,
                'reason' => 'unknown_contact'
            ]));

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        $this->assertFalse($result['success']);
        $this->assertEquals('unknown_contact', $result['error']);
    }

    /**
     * Test syncTransactionChain with successful sync
     */
    public function testSyncTransactionChainWithSuccessfulSync(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $contactAddress = 'http://contact.example.com';
        $contactPubkey = 'contact-public-key';

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn(['http://user.example.com']);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(null);

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [],
                'latestTxid' => null
            ]));

        $this->mockHeldTransactionService->expects($this->once())
            ->method('onSyncComplete')
            ->with($contactPubkey, true, 0);

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['synced_count']);
    }

    // =========================================================================
    // syncContactBalance Tests
    // =========================================================================

    /**
     * Test syncContactBalance calculates balances correctly
     */
    public function testSyncContactBalanceCalculatesBalancesCorrectly(): void
    {
        $contactPubkey = 'contact-public-key';
        $userPubkey = 'user-public-key';
        $userAddresses = ['http://user.example.com'];

        $transactions = [
            [
                'sender_address' => 'http://user.example.com',
                'receiver_address' => 'http://contact.example.com',
                'amount' => 1000,
                'currency' => 'USD'
            ],
            [
                'sender_address' => 'http://contact.example.com',
                'receiver_address' => 'http://user.example.com',
                'amount' => 500,
                'currency' => 'USD'
            ]
        ];

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn($userAddresses);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn($userPubkey);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getTransactionsBetweenPubkeys')
            ->with($userPubkey, $contactPubkey)
            ->willReturn($transactions);

        $this->mockBalanceRepo->expects($this->once())
            ->method('getContactBalance')
            ->willReturn([]);

        $this->mockBalanceRepo->expects($this->once())
            ->method('insertBalance')
            ->with($contactPubkey, 500, 1000, 'USD');

        $result = $this->service->syncContactBalance($contactPubkey);

        $this->assertTrue($result['success']);
        $this->assertContains('USD', $result['currencies']);
    }

    /**
     * Test syncContactBalance with existing balance updates balance
     */
    public function testSyncContactBalanceWithExistingBalanceUpdatesBalance(): void
    {
        $contactPubkey = 'contact-public-key';
        $userPubkey = 'user-public-key';
        $userAddresses = ['http://user.example.com'];

        $transactions = [
            [
                'sender_address' => 'http://user.example.com',
                'receiver_address' => 'http://contact.example.com',
                'amount' => 1000,
                'currency' => 'USD'
            ]
        ];

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn($userAddresses);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn($userPubkey);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getTransactionsBetweenPubkeys')
            ->willReturn($transactions);

        $this->mockBalanceRepo->expects($this->once())
            ->method('getContactBalance')
            ->willReturn([['balance' => 500]]); // Existing balance

        $this->mockBalanceRepo->expects($this->once())
            ->method('updateBothDirectionBalance')
            ->with(
                $this->callback(function ($amounts) {
                    return $amounts['sent'] === 1000 && $amounts['received'] === 0;
                }),
                $this->anything(),
                'USD'
            );

        $result = $this->service->syncContactBalance($contactPubkey);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // handleTransactionSyncRequest Tests
    // =========================================================================

    /**
     * Test handleTransactionSyncRequest rejects unknown contact
     */
    public function testHandleTransactionSyncRequestRejectsUnknownContact(): void
    {
        $request = [
            'senderAddress' => 'http://unknown.example.com',
            'senderPublicKey' => 'unknown-public-key',
            'lastKnownTxid' => null
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('contactExistsPubkey')
            ->with('unknown-public-key')
            ->willReturn(false);

        $this->expectOutputRegex('/rejected.*unknown_contact/i');

        $this->service->handleTransactionSyncRequest($request);
    }

    /**
     * Test handleTransactionSyncRequest returns transactions for known contact
     */
    public function testHandleTransactionSyncRequestReturnsTransactionsForKnownContact(): void
    {
        $request = [
            'senderAddress' => 'http://contact.example.com',
            'senderPublicKey' => 'contact-public-key',
            'lastKnownTxid' => null
        ];

        $transactions = [
            [
                'txid' => 'txid-1',
                'previous_txid' => null,
                'sender_address' => 'http://user.example.com',
                'sender_public_key' => 'user-public-key',
                'receiver_address' => 'http://contact.example.com',
                'receiver_public_key' => 'contact-public-key',
                'amount' => 1000,
                'currency' => 'USD',
                'memo' => 'standard',
                'timestamp' => time(),
                'status' => 'completed'
            ]
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('contactExistsPubkey')
            ->willReturn(true);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        $this->mockTransactionRepo->expects($this->once())
            ->method('getTransactionsBetweenPubkeys')
            ->willReturn($transactions);

        $this->expectOutputRegex('/accepted.*transactions/i');

        $this->service->handleTransactionSyncRequest($request);
    }

    // =========================================================================
    // verifyTransactionSignaturePublic Tests
    // =========================================================================

    /**
     * Test verifyTransactionSignaturePublic returns false for missing signature
     */
    public function testVerifyTransactionSignaturePublicReturnsFalseForMissingSignature(): void
    {
        $tx = [
            'txid' => 'test-txid',
            'sender_public_key' => 'sender-key',
            // Missing sender_signature and signature_nonce
        ];

        $result = $this->service->verifyTransactionSignaturePublic($tx);

        $this->assertFalse($result);
    }

    /**
     * Test verifyTransactionSignaturePublic returns false for missing nonce
     */
    public function testVerifyTransactionSignaturePublicReturnsFalseForMissingNonce(): void
    {
        $tx = [
            'txid' => 'test-txid',
            'sender_public_key' => 'sender-key',
            'sender_signature' => 'some-signature',
            // Missing signature_nonce
        ];

        $result = $this->service->verifyTransactionSignaturePublic($tx);

        $this->assertFalse($result);
    }

    // =========================================================================
    // syncReaddedContact Tests
    // =========================================================================

    /**
     * Test syncReaddedContact performs full sync
     */
    public function testSyncReaddedContactPerformsFullSync(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $contactAddress = 'http://contact.example.com';
        $contactPubkey = 'contact-public-key';

        // Contact sync
        $this->mockTransportUtility->expects($this->any())
            ->method('determineTransportType')
            ->willReturn('http');

        $this->mockContactRepo->expects($this->any())
            ->method('getContactByAddress')
            ->willReturn(['status' => Constants::CONTACT_STATUS_ACCEPTED]);

        // Transaction sync
        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn(['http://user.example.com']);

        $this->mockTransactionRepo->expects($this->any())
            ->method('getPreviousTxid')
            ->willReturn(null);

        $this->mockTransportUtility->expects($this->any())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [],
                'latestTxid' => null
            ]));

        // Balance sync
        $this->mockTransactionRepo->expects($this->any())
            ->method('getTransactionsBetweenPubkeys')
            ->willReturn([]);

        $result = $this->service->syncReaddedContact($contactAddress, $contactPubkey);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['contact_synced']);
    }

    // =========================================================================
    // syncAllBalances Tests
    // =========================================================================

    /**
     * Test syncAllBalances with empty contacts
     */
    public function testSyncAllBalancesWithEmptyContacts(): void
    {
        $output = $this->createMock(CliOutputManager::class);

        $this->mockContactRepo->expects($this->once())
            ->method('getAllContactsPubkeys')
            ->willReturn([]);

        $output->expects($this->once())
            ->method('success')
            ->with(
                'Balances synced',
                $this->callback(function ($data) {
                    return $data['total_contacts'] === 0;
                }),
                $this->anything()
            );

        $this->service->syncAllBalances($output);
    }

    // =========================================================================
    // bidirectionalSync Tests
    // =========================================================================

    /**
     * Test bidirectionalSync falls back to standard sync on unsupported response
     */
    public function testBidirectionalSyncFallsBackToStandardSyncOnUnsupportedResponse(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $contactAddress = 'http://contact.example.com';
        $contactPubkey = 'contact-public-key';

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        $this->mockChainRepo->expects($this->once())
            ->method('getChainStateSummary')
            ->willReturn([
                'transaction_count' => 5,
                'txid_list' => ['tx1', 'tx2', 'tx3', 'tx4', 'tx5']
            ]);

        // First call for negotiation returns rejection
        // Second call for standard sync returns accepted
        $this->mockTransportUtility->expects($this->any())
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                json_encode(['status' => Constants::STATUS_REJECTED]),
                json_encode([
                    'status' => Constants::STATUS_ACCEPTED,
                    'transactions' => [],
                    'latestTxid' => null
                ])
            );

        $this->mockTransactionRepo->expects($this->any())
            ->method('getPreviousTxid')
            ->willReturn(null);

        $result = $this->service->bidirectionalSync($contactAddress, $contactPubkey);

        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // handleSyncNegotiationRequest Tests
    // =========================================================================

    /**
     * Test handleSyncNegotiationRequest rejects unknown contact
     */
    public function testHandleSyncNegotiationRequestRejectsUnknownContact(): void
    {
        $request = [
            'senderAddress' => 'http://unknown.example.com',
            'senderPublicKey' => 'unknown-public-key',
            'txid_list' => []
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('contactExistsPubkey')
            ->with('unknown-public-key')
            ->willReturn(false);

        $this->expectOutputRegex('/rejected.*unknown_contact/i');

        $this->service->handleSyncNegotiationRequest($request);
    }
}
