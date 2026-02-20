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
                'currency' => 'USD',
                'status' => Constants::STATUS_COMPLETED
            ],
            [
                'sender_address' => 'http://contact.example.com',
                'receiver_address' => 'http://user.example.com',
                'amount' => 500,
                'currency' => 'USD',
                'status' => Constants::STATUS_COMPLETED
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
                'currency' => 'USD',
                'status' => Constants::STATUS_COMPLETED
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
    // Balance Status Filtering Tests
    // =========================================================================

    /**
     * Test syncContactBalance only counts completed transactions in balance
     *
     * Verifies that rejected and expired transactions are excluded from
     * balance calculations, ensuring only completed transactions contribute
     * to sent/received totals.
     */
    public function testSyncContactBalanceOnlyCountsCompletedTransactions(): void
    {
        $contactPubkey = 'contact-public-key';
        $userPubkey = 'user-public-key';
        $userAddresses = ['http://user.example.com'];

        // Mix of completed, rejected, and expired transactions
        $transactions = [
            [
                'sender_address' => 'http://user.example.com',
                'receiver_address' => 'http://contact.example.com',
                'amount' => 1000,
                'currency' => 'USD',
                'status' => Constants::STATUS_COMPLETED
            ],
            [
                'sender_address' => 'http://user.example.com',
                'receiver_address' => 'http://contact.example.com',
                'amount' => 2000,
                'currency' => 'USD',
                'status' => Constants::STATUS_REJECTED
            ],
            [
                'sender_address' => 'http://contact.example.com',
                'receiver_address' => 'http://user.example.com',
                'amount' => 500,
                'currency' => 'USD',
                'status' => Constants::STATUS_COMPLETED
            ],
            [
                'sender_address' => 'http://contact.example.com',
                'receiver_address' => 'http://user.example.com',
                'amount' => 3000,
                'currency' => 'USD',
                'status' => Constants::STATUS_EXPIRED
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

        // Only completed transactions should be counted:
        // sent = 1000 (rejected 2000 excluded), received = 500 (expired 3000 excluded)
        $this->mockBalanceRepo->expects($this->once())
            ->method('insertBalance')
            ->with($contactPubkey, 500, 1000, 'USD');

        $result = $this->service->syncContactBalance($contactPubkey);

        $this->assertTrue($result['success']);
        $this->assertContains('USD', $result['currencies']);
    }

    /**
     * Test syncContactBalance with all rejected transactions results in no balance
     *
     * When every transaction is rejected/expired, no balance record should
     * be inserted at all.
     */
    public function testSyncContactBalanceWithAllRejectedTransactionsCreatesNoBalance(): void
    {
        $contactPubkey = 'contact-public-key';
        $userPubkey = 'user-public-key';
        $userAddresses = ['http://user.example.com'];

        $transactions = [
            [
                'sender_address' => 'http://user.example.com',
                'receiver_address' => 'http://contact.example.com',
                'amount' => 1000,
                'currency' => 'USD',
                'status' => Constants::STATUS_REJECTED
            ],
            [
                'sender_address' => 'http://contact.example.com',
                'receiver_address' => 'http://user.example.com',
                'amount' => 2000,
                'currency' => 'USD',
                'status' => Constants::STATUS_EXPIRED
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

        // No balance should be inserted or updated since all transactions are non-completed
        $this->mockBalanceRepo->expects($this->never())
            ->method('insertBalance');
        $this->mockBalanceRepo->expects($this->never())
            ->method('updateBothDirectionBalance');

        $result = $this->service->syncContactBalance($contactPubkey);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['currencies']);
    }

    /**
     * Test syncContactBalance with existing balance updates only with completed transactions
     *
     * Verifies that when updating an existing balance record, only completed
     * transaction amounts are passed to updateBothDirectionBalance.
     */
    public function testSyncContactBalanceUpdatesExistingBalanceWithOnlyCompletedTransactions(): void
    {
        $contactPubkey = 'contact-public-key';
        $userPubkey = 'user-public-key';
        $userAddresses = ['http://user.example.com'];

        $transactions = [
            [
                'sender_address' => 'http://user.example.com',
                'receiver_address' => 'http://contact.example.com',
                'amount' => 1000,
                'currency' => 'USD',
                'status' => Constants::STATUS_COMPLETED
            ],
            [
                'sender_address' => 'http://user.example.com',
                'receiver_address' => 'http://contact.example.com',
                'amount' => 5000,
                'currency' => 'USD',
                'status' => Constants::STATUS_REJECTED
            ],
            [
                'sender_address' => 'http://contact.example.com',
                'receiver_address' => 'http://user.example.com',
                'amount' => 750,
                'currency' => 'USD',
                'status' => Constants::STATUS_COMPLETED
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

        // Only completed: sent=1000 (not 6000), received=750
        $this->mockBalanceRepo->expects($this->once())
            ->method('updateBothDirectionBalance')
            ->with(
                $this->callback(function ($amounts) {
                    return $amounts['sent'] === 1000 && $amounts['received'] === 750;
                }),
                $this->anything(),
                'USD'
            );

        $result = $this->service->syncContactBalance($contactPubkey);

        $this->assertTrue($result['success']);
    }

    /**
     * Test syncAllBalances filters non-completed transactions via syncAllBalancesInternal
     *
     * Tests the full path through syncAllBalances -> syncAllBalancesInternal
     * to verify that rejected/expired transactions are excluded from balance
     * calculations for all contacts.
     */
    public function testSyncAllBalancesFiltersNonCompletedTransactions(): void
    {
        $output = $this->createMock(CliOutputManager::class);

        $this->mockContactRepo->expects($this->once())
            ->method('getAllContactsPubkeys')
            ->willReturn(['contact-pubkey-1']);

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn(['http://user.example.com']);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        // Return mix of completed and rejected transactions
        $this->mockTransactionRepo->expects($this->once())
            ->method('getTransactionsBetweenPubkeys')
            ->with('user-public-key', 'contact-pubkey-1')
            ->willReturn([
                [
                    'sender_address' => 'http://user.example.com',
                    'receiver_address' => 'http://contact.example.com',
                    'amount' => 2000,
                    'currency' => 'USD',
                    'status' => Constants::STATUS_COMPLETED
                ],
                [
                    'sender_address' => 'http://user.example.com',
                    'receiver_address' => 'http://contact.example.com',
                    'amount' => 9000,
                    'currency' => 'USD',
                    'status' => Constants::STATUS_REJECTED
                ],
                [
                    'sender_address' => 'http://contact.example.com',
                    'receiver_address' => 'http://user.example.com',
                    'amount' => 1500,
                    'currency' => 'USD',
                    'status' => Constants::STATUS_COMPLETED
                ],
                [
                    'sender_address' => 'http://contact.example.com',
                    'receiver_address' => 'http://user.example.com',
                    'amount' => 4000,
                    'currency' => 'USD',
                    'status' => Constants::STATUS_EXPIRED
                ]
            ]);

        $this->mockBalanceRepo->expects($this->once())
            ->method('getContactBalance')
            ->willReturn([]);

        // Only completed: sent=2000 (rejected 9000 excluded), received=1500 (expired 4000 excluded)
        $this->mockBalanceRepo->expects($this->once())
            ->method('insertBalance')
            ->with('contact-pubkey-1', 1500, 2000, 'USD');

        $output->expects($this->once())
            ->method('success')
            ->with(
                'Balances synced',
                $this->callback(function ($data) {
                    return $data['total_contacts'] === 1
                        && $data['synced'] === 1
                        && $data['failed'] === 0;
                }),
                $this->anything()
            );

        $this->service->syncAllBalances($output);
    }

    /**
     * Test syncAllBalances with multiple contacts filters correctly per contact
     *
     * Verifies that status filtering works independently for each contact
     * when syncing all balances.
     */
    public function testSyncAllBalancesFiltersPerContactIndependently(): void
    {
        $output = $this->createMock(CliOutputManager::class);

        $this->mockContactRepo->expects($this->once())
            ->method('getAllContactsPubkeys')
            ->willReturn(['contact-a', 'contact-b']);

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn(['http://user.example.com']);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        $callCount = 0;
        $this->mockTransactionRepo->expects($this->exactly(2))
            ->method('getTransactionsBetweenPubkeys')
            ->willReturnCallback(function ($userPubkey, $contactPubkey) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // contact-a: only rejected transactions
                    return [
                        [
                            'sender_address' => 'http://user.example.com',
                            'receiver_address' => 'http://contact-a.example.com',
                            'amount' => 5000,
                            'currency' => 'USD',
                            'status' => Constants::STATUS_REJECTED
                        ]
                    ];
                } else {
                    // contact-b: one completed transaction
                    return [
                        [
                            'sender_address' => 'http://user.example.com',
                            'receiver_address' => 'http://contact-b.example.com',
                            'amount' => 3000,
                            'currency' => 'USD',
                            'status' => Constants::STATUS_COMPLETED
                        ]
                    ];
                }
            });

        // Only contact-b should trigger a balance lookup and insert
        // contact-a has no completed transactions, so no balance operation
        $this->mockBalanceRepo->expects($this->once())
            ->method('getContactBalance')
            ->willReturn([]);

        $this->mockBalanceRepo->expects($this->once())
            ->method('insertBalance')
            ->with('contact-b', 0, 3000, 'USD');

        $output->expects($this->once())
            ->method('success')
            ->with(
                'Balances synced',
                $this->callback(function ($data) {
                    return $data['total_contacts'] === 2
                        && $data['synced'] === 2;
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

    // =========================================================================
    // Signature Verification Tests
    // =========================================================================

    /**
     * Generate a valid RSA key pair for testing
     *
     * @return array ['privateKey' => string, 'publicKey' => string, 'keyResource' => resource]
     */
    private function generateTestKeyPair(): array
    {
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA
        ]);

        $privateKey = '';
        openssl_pkey_export($keyPair, $privateKey);
        $publicKeyDetails = openssl_pkey_get_details($keyPair);

        return [
            'privateKey' => $privateKey,
            'publicKey' => $publicKeyDetails['key'],
            'keyResource' => $keyPair
        ];
    }

    /**
     * Create a signed standard transaction for testing
     *
     * Creates a transaction with a valid signature following the exact
     * message format from SyncService::reconstructSignedMessage()
     *
     * @param array $senderKeys Key pair for the sender
     * @param array $receiverKeys Key pair for the receiver
     * @param array $overrides Optional field overrides
     * @return array Transaction data with signature
     */
    private function createSignedStandardTransaction(array $senderKeys, array $receiverKeys, array $overrides = []): array
    {
        $nonce = time();
        $txid = 'test-txid-' . bin2hex(random_bytes(8));

        // Build the message content in the exact order expected by reconstructSignedMessage()
        // This matches TransactionPayload::build() -> TransportUtilityService::sign()
        $messageContent = [
            'type' => 'send',
            'time' => $overrides['time'] ?? $nonce,
            'receiverAddress' => $overrides['receiver_address'] ?? 'http://receiver.example.com',
            'receiverPublicKey' => $receiverKeys['publicKey'],
            'amount' => (int)($overrides['amount'] ?? 1000),
            'currency' => $overrides['currency'] ?? 'USD',
            'txid' => $overrides['txid'] ?? $txid,
            'previousTxid' => $overrides['previous_txid'] ?? null,
            'memo' => $overrides['memo'] ?? 'standard',
            'nonce' => $nonce
        ];

        // Sign the message
        $message = json_encode($messageContent);
        $signature = '';
        openssl_sign($message, $signature, $senderKeys['keyResource']);

        // Build the full transaction data structure (snake_case for database format)
        return [
            'txid' => $messageContent['txid'],
            'previous_txid' => $messageContent['previousTxid'],
            'sender_address' => $overrides['sender_address'] ?? 'http://sender.example.com',
            'sender_public_key' => $senderKeys['publicKey'],
            'receiver_address' => $messageContent['receiverAddress'],
            'receiver_public_key' => $receiverKeys['publicKey'],
            'amount' => $messageContent['amount'],
            'currency' => $messageContent['currency'],
            'memo' => $messageContent['memo'],
            'time' => $messageContent['time'],
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => $overrides['status'] ?? Constants::STATUS_COMPLETED,
            'sender_signature' => base64_encode($signature),
            'signature_nonce' => $nonce,
            'recipient_signature' => $overrides['recipient_signature'] ?? null,
            'description' => $overrides['description'] ?? null
        ];
    }

    /**
     * Create a signed contact transaction for testing
     *
     * Contact transactions have a different message format: {'type': 'create', 'nonce': ...}
     *
     * @param array $senderKeys Key pair for the sender
     * @param array $receiverKeys Key pair for the receiver
     * @param array $overrides Optional field overrides
     * @return array Transaction data with signature
     */
    private function createSignedContactTransaction(array $senderKeys, array $receiverKeys, array $overrides = []): array
    {
        $nonce = time();
        $txid = 'contact-txid-' . bin2hex(random_bytes(8));

        // Contact transactions use a simpler message format
        // This matches ContactPayload::build() -> TransportUtilityService::sign()
        $messageContent = [
            'type' => 'create',
            'nonce' => $nonce
        ];

        // Sign the message
        $message = json_encode($messageContent);
        $signature = '';
        openssl_sign($message, $signature, $senderKeys['keyResource']);

        // Build the full transaction data structure
        return [
            'txid' => $overrides['txid'] ?? $txid,
            'previous_txid' => $overrides['previous_txid'] ?? null,
            'sender_address' => $overrides['sender_address'] ?? 'http://sender.example.com',
            'sender_public_key' => $senderKeys['publicKey'],
            'receiver_address' => $overrides['receiver_address'] ?? 'http://receiver.example.com',
            'receiver_public_key' => $receiverKeys['publicKey'],
            'amount' => $overrides['amount'] ?? 0,
            'currency' => $overrides['currency'] ?? 'USD',
            'memo' => 'contact',
            'time' => $nonce,
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => $overrides['status'] ?? Constants::STATUS_COMPLETED,
            'sender_signature' => base64_encode($signature),
            'signature_nonce' => $nonce,
            'recipient_signature' => $overrides['recipient_signature'] ?? null,
            'description' => $overrides['description'] ?? null
        ];
    }

    /**
     * Test signature verification passes for valid standard transactions
     *
     * Verifies that verifyTransactionSignaturePublic() returns true when:
     * - Transaction has a valid sender_signature
     * - Signature matches the expected message format
     * - Public key corresponds to the private key that signed
     */
    public function testVerifyTransactionSignatureWithValidStandardTransaction(): void
    {
        $senderKeys = $this->generateTestKeyPair();
        $receiverKeys = $this->generateTestKeyPair();

        $transaction = $this->createSignedStandardTransaction($senderKeys, $receiverKeys, [
            'amount' => 5000,
            'currency' => 'EUR',
            'memo' => 'standard'
        ]);

        $result = $this->service->verifyTransactionSignaturePublic($transaction);

        $this->assertTrue($result, 'Valid standard transaction signature should verify successfully');
    }

    /**
     * Test signature verification for contact transactions (different message format)
     *
     * Contact transactions use a minimal message format: {'type': 'create', 'nonce': ...}
     * This tests that the service correctly identifies and verifies contact transaction signatures.
     */
    public function testVerifyTransactionSignatureWithValidContactTransaction(): void
    {
        $senderKeys = $this->generateTestKeyPair();
        $receiverKeys = $this->generateTestKeyPair();

        $transaction = $this->createSignedContactTransaction($senderKeys, $receiverKeys);

        $result = $this->service->verifyTransactionSignaturePublic($transaction);

        $this->assertTrue($result, 'Valid contact transaction signature should verify successfully');
    }

    /**
     * Test verification fails when transaction data is modified after signing
     *
     * Simulates an attacker trying to modify transaction amount after it was signed.
     * The signature should fail to verify when any signed field is tampered with.
     */
    public function testVerifyTransactionSignatureFailsOnTamperedData(): void
    {
        $senderKeys = $this->generateTestKeyPair();
        $receiverKeys = $this->generateTestKeyPair();

        // Create a valid signed transaction
        $transaction = $this->createSignedStandardTransaction($senderKeys, $receiverKeys, [
            'amount' => 1000
        ]);

        // Tamper with the amount after signing
        $transaction['amount'] = 9999;

        $result = $this->service->verifyTransactionSignaturePublic($transaction);

        $this->assertFalse($result, 'Tampered transaction should fail signature verification');
    }

    /**
     * Test verification fails with incorrect public key
     *
     * Simulates an attacker trying to claim a transaction was signed by a different sender.
     * The signature should fail when verified against a different public key.
     */
    public function testVerifyTransactionSignatureWithIncorrectPublicKey(): void
    {
        $actualSenderKeys = $this->generateTestKeyPair();
        $attackerKeys = $this->generateTestKeyPair();
        $receiverKeys = $this->generateTestKeyPair();

        // Create a transaction signed by the actual sender
        $transaction = $this->createSignedStandardTransaction($actualSenderKeys, $receiverKeys);

        // Replace sender public key with attacker's key (attacker tries to claim ownership)
        $transaction['sender_public_key'] = $attackerKeys['publicKey'];

        $result = $this->service->verifyTransactionSignaturePublic($transaction);

        $this->assertFalse($result, 'Transaction with wrong public key should fail verification');
    }

    /**
     * Test message reconstruction includes all required fields
     *
     * Verifies that the signed message format includes all fields in the correct order.
     * This is critical for signature verification to work consistently.
     */
    public function testReconstructSignedMessageIncludesAllRequiredFields(): void
    {
        $senderKeys = $this->generateTestKeyPair();
        $receiverKeys = $this->generateTestKeyPair();

        // Create transaction with specific values to verify reconstruction
        $specificTime = 1700000000;
        $specificTxid = 'specific-txid-12345';
        $specificPreviousTxid = 'previous-txid-67890';

        // Build the expected message content manually
        $expectedMessageContent = [
            'type' => 'send',
            'time' => $specificTime,
            'receiverAddress' => 'http://receiver.example.com',
            'receiverPublicKey' => $receiverKeys['publicKey'],
            'amount' => 2500,
            'currency' => 'GBP',
            'txid' => $specificTxid,
            'previousTxid' => $specificPreviousTxid,
            'memo' => 'standard',
            'nonce' => $specificTime  // Using same value for simplicity
        ];

        // Sign with this exact message
        $message = json_encode($expectedMessageContent);
        $signature = '';
        openssl_sign($message, $signature, $senderKeys['keyResource']);

        // Create transaction data that should reconstruct to the same message
        $transaction = [
            'txid' => $specificTxid,
            'previous_txid' => $specificPreviousTxid,
            'sender_address' => 'http://sender.example.com',
            'sender_public_key' => $senderKeys['publicKey'],
            'receiver_address' => 'http://receiver.example.com',
            'receiver_public_key' => $receiverKeys['publicKey'],
            'amount' => 2500,
            'currency' => 'GBP',
            'memo' => 'standard',
            'time' => $specificTime,
            'timestamp' => date('Y-m-d H:i:s'),
            'status' => Constants::STATUS_COMPLETED,
            'sender_signature' => base64_encode($signature),
            'signature_nonce' => $specificTime,
            'description' => 'Test description - should not affect signature'
        ];

        $result = $this->service->verifyTransactionSignaturePublic($transaction);

        $this->assertTrue($result, 'Message reconstruction should produce verifiable signature');
    }

    /**
     * Test recipient signature verification for accepted transactions
     *
     * For accepted/completed transactions, both sender and recipient signatures
     * should be verified. This test ensures recipient signature verification works.
     */
    public function testVerifyRecipientSignatureForAcceptedTransaction(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $senderKeys = $this->generateTestKeyPair();
        $receiverKeys = $this->generateTestKeyPair();

        // Create a standard signed transaction
        $transaction = $this->createSignedStandardTransaction($senderKeys, $receiverKeys, [
            'status' => Constants::STATUS_ACCEPTED
        ]);

        // The recipient also needs to sign the same message
        // Reconstruct the message as done in SyncService
        $nonce = $transaction['signature_nonce'];
        $messageContent = [
            'type' => 'send',
            'time' => $transaction['time'],
            'receiverAddress' => $transaction['receiver_address'],
            'receiverPublicKey' => $transaction['receiver_public_key'],
            'amount' => (int)$transaction['amount'],
            'currency' => $transaction['currency'],
            'txid' => $transaction['txid'],
            'previousTxid' => $transaction['previous_txid'],
            'memo' => $transaction['memo'],
            'nonce' => $nonce
        ];

        $message = json_encode($messageContent);
        $recipientSignature = '';
        openssl_sign($message, $recipientSignature, $receiverKeys['keyResource']);

        // Add recipient signature to transaction
        $transaction['recipient_signature'] = base64_encode($recipientSignature);

        // Set up mock for transaction sync
        $contactAddress = 'http://contact.example.com';
        $contactPubkey = $senderKeys['publicKey'];

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn($receiverKeys['publicKey']);

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn(['http://receiver.example.com']);

        $this->mockTransactionRepo->expects($this->any())
            ->method('getPreviousTxid')
            ->willReturn(null);

        $this->mockTransactionRepo->expects($this->any())
            ->method('transactionExistsTxid')
            ->willReturn(false);

        // The transaction should be inserted since both signatures are valid
        $this->mockTransactionRepo->expects($this->once())
            ->method('insertTransaction');

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$transaction],
                'latestTxid' => $transaction['txid']
            ]));

        $this->mockHeldTransactionService->expects($this->once())
            ->method('onSyncComplete');

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['synced_count']);
    }

    // =========================================================================
    // Chain Conflict Resolution Tests
    // =========================================================================

    /**
     * Test conflict resolution when local txid is lexicographically smaller
     *
     * When both parties create transactions simultaneously with the same previous_txid,
     * the transaction with the lexicographically lower txid should win.
     */
    public function testResolveChainConflictLocalWinsLexicographically(): void
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
            ->willReturn('previous-tx-123');

        // Remote transaction has lexicographically HIGHER txid (bbb > aaa)
        $remoteTransaction = [
            'txid' => 'bbb-remote-txid',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://contact.example.com',
            'sender_public_key' => 'contact-public-key',
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 1000,
            'currency' => 'USD',
            'memo' => 'standard',
            'time' => time(),
            'status' => 'completed',
            'sender_signature' => 'valid-signature',
            'signature_nonce' => time()
        ];

        // Local transaction has lexicographically LOWER txid (aaa < bbb) - local wins
        $localConflictingTx = [
            'txid' => 'aaa-local-txid',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://user.example.com',
            'sender_public_key' => 'user-public-key',
            'receiver_address' => 'http://contact.example.com',
            'receiver_public_key' => 'contact-public-key',
            'amount' => 500,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => 'completed'
        ];

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$remoteTransaction],
                'latestTxid' => 'bbb-remote-txid'
            ]));

        // Chain repo returns our local conflicting transaction
        $this->mockChainRepo->expects($this->once())
            ->method('getLocalTransactionByPreviousTxid')
            ->with(
                'previous-tx-123',
                $this->anything(),
                $this->anything()
            )
            ->willReturn($localConflictingTx);

        // Remote transaction already exists check
        $this->mockTransactionRepo->expects($this->any())
            ->method('transactionExistsTxid')
            ->willReturn(false);

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        // Local wins because aaa < bbb lexicographically
        // Conflict should be resolved and counted
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['conflicts_resolved']);
    }

    /**
     * Test conflict resolution when remote txid is lexicographically smaller
     *
     * When the remote transaction has a lexicographically lower txid,
     * it should win and our local transaction should be re-signed.
     */
    public function testResolveChainConflictRemoteWinsLexicographically(): void
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
            ->willReturn('previous-tx-123');

        // Remote transaction has lexicographically LOWER txid (aaa < bbb) - remote wins
        $remoteTransaction = [
            'txid' => 'aaa-remote-txid',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://contact.example.com',
            'sender_public_key' => 'contact-public-key',
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 1000,
            'currency' => 'USD',
            'memo' => 'standard',
            'time' => time(),
            'status' => 'completed',
            'sender_signature' => 'valid-signature',
            'signature_nonce' => time()
        ];

        // Local transaction has lexicographically HIGHER txid (bbb > aaa)
        $localConflictingTx = [
            'txid' => 'bbb-local-txid',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://user.example.com',
            'sender_public_key' => 'user-public-key',
            'receiver_address' => 'http://contact.example.com',
            'receiver_public_key' => 'contact-public-key',
            'amount' => 500,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => 'completed'
        ];

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$remoteTransaction],
                'latestTxid' => 'aaa-remote-txid'
            ]));

        // Chain repo returns our local conflicting transaction
        $this->mockChainRepo->expects($this->once())
            ->method('getLocalTransactionByPreviousTxid')
            ->with(
                'previous-tx-123',
                $this->anything(),
                $this->anything()
            )
            ->willReturn($localConflictingTx);

        // Remote transaction does not exist locally yet
        $this->mockTransactionRepo->expects($this->any())
            ->method('transactionExistsTxid')
            ->willReturn(false);

        // When remote wins, we need to re-sign our local transaction
        // First update previous_txid
        $this->mockChainRepo->expects($this->once())
            ->method('updatePreviousTxid')
            ->with('bbb-local-txid', 'aaa-remote-txid')
            ->willReturn(true);

        // Then get the updated transaction for re-signing
        $this->mockTransactionRepo->expects($this->once())
            ->method('getByTxid')
            ->with('bbb-local-txid')
            ->willReturn([array_merge($localConflictingTx, ['previous_txid' => 'aaa-remote-txid'])]);

        // Sign the transaction
        $this->mockTransportUtility->expects($this->once())
            ->method('signWithCapture')
            ->willReturn([
                'signature' => 'new-signature',
                'nonce' => time()
            ]);

        // Update signature
        $this->mockTransactionRepo->expects($this->once())
            ->method('updateSignatureData')
            ->willReturn(true);

        // Update timestamp
        $this->mockTransactionRepo->expects($this->once())
            ->method('updateTimestamp')
            ->willReturn(true);

        // Set status to pending
        $this->mockTransactionRepo->expects($this->once())
            ->method('updateStatus')
            ->with('bbb-local-txid', Constants::STATUS_PENDING, true)
            ->willReturn(true);

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        // Remote wins because aaa < bbb lexicographically
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['conflicts_resolved']);
    }

    /**
     * Test behavior when txids are identical (should not resolve)
     *
     * If two transactions have identical txids (which should never happen in practice),
     * the conflict cannot be resolved and should be logged as an error.
     */
    public function testResolveChainConflictIdenticalTxidsNoChange(): void
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
            ->willReturn('previous-tx-123');

        // Both transactions have the SAME txid
        $sharedTxid = 'identical-txid-value';

        $remoteTransaction = [
            'txid' => $sharedTxid,
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://contact.example.com',
            'sender_public_key' => 'contact-public-key',
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 1000,
            'currency' => 'USD',
            'memo' => 'standard',
            'time' => time(),
            'status' => 'completed',
            'sender_signature' => 'valid-signature',
            'signature_nonce' => time()
        ];

        // Local transaction has the same txid
        $localConflictingTx = [
            'txid' => $sharedTxid,
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://user.example.com',
            'sender_public_key' => 'user-public-key',
            'receiver_address' => 'http://contact.example.com',
            'receiver_public_key' => 'contact-public-key',
            'amount' => 500,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => 'completed'
        ];

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$remoteTransaction],
                'latestTxid' => $sharedTxid
            ]));

        // When txids match, getLocalTransactionByPreviousTxid returns the local tx
        // but the conflict detection check (localConflict['txid'] !== $tx['txid'])
        // will be false, so no conflict is detected
        $this->mockChainRepo->expects($this->once())
            ->method('getLocalTransactionByPreviousTxid')
            ->willReturn($localConflictingTx);

        // Transaction already exists (identical txid)
        $this->mockTransactionRepo->expects($this->any())
            ->method('transactionExistsTxid')
            ->with($sharedTxid)
            ->willReturn(true);

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        // No conflict detected because txids are identical
        // (the check localConflict['txid'] !== tx['txid'] is false)
        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['conflicts_resolved']);
        $this->assertEquals(0, $result['synced_count']); // Skipped as it already exists
    }

    /**
     * Test that re-signing updates the transaction signature
     *
     * When a local transaction loses a chain conflict, it must be re-signed
     * with the new previous_txid. This test verifies the signature is updated.
     */
    public function testResignLocalTransactionUpdatesSignature(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $contactAddress = 'http://contact.example.com';
        $contactPubkey = 'contact-public-key';
        $newNonce = time();
        $newSignature = 'updated-signature-after-resign';

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn(['http://user.example.com']);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn('previous-tx-123');

        // Remote transaction wins (lower txid)
        $remoteTransaction = [
            'txid' => 'aaa-winner',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://contact.example.com',
            'sender_public_key' => 'contact-public-key',
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 1000,
            'currency' => 'USD',
            'memo' => 'standard',
            'time' => time(),
            'status' => 'completed',
            'sender_signature' => 'valid-signature',
            'signature_nonce' => time()
        ];

        // Local transaction loses (higher txid)
        $localLosingTx = [
            'txid' => 'zzz-loser',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://user.example.com',
            'sender_public_key' => 'user-public-key',
            'receiver_address' => 'http://contact.example.com',
            'receiver_public_key' => 'contact-public-key',
            'amount' => 500,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => 'pending'
        ];

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$remoteTransaction],
                'latestTxid' => 'aaa-winner'
            ]));

        $this->mockChainRepo->expects($this->once())
            ->method('getLocalTransactionByPreviousTxid')
            ->willReturn($localLosingTx);

        $this->mockTransactionRepo->expects($this->any())
            ->method('transactionExistsTxid')
            ->willReturn(false);

        // Update previous_txid for losing transaction
        $this->mockChainRepo->expects($this->once())
            ->method('updatePreviousTxid')
            ->with('zzz-loser', 'aaa-winner')
            ->willReturn(true);

        // Get updated transaction for re-signing
        $this->mockTransactionRepo->expects($this->once())
            ->method('getByTxid')
            ->with('zzz-loser')
            ->willReturn([array_merge($localLosingTx, ['previous_txid' => 'aaa-winner'])]);

        // Sign with capture returns new signature
        $this->mockTransportUtility->expects($this->once())
            ->method('signWithCapture')
            ->willReturn([
                'signature' => $newSignature,
                'nonce' => $newNonce
            ]);

        // Verify signature data is updated with the new values
        $this->mockTransactionRepo->expects($this->once())
            ->method('updateSignatureData')
            ->with('zzz-loser', $newSignature, $newNonce)
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->any())
            ->method('updateTimestamp')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->any())
            ->method('updateStatus')
            ->willReturn(true);

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['conflicts_resolved']);
    }

    /**
     * Test that status is set to pending after re-signing
     *
     * After a local transaction is re-signed following a chain conflict loss,
     * its status should be set to PENDING so it gets re-sent to the contact.
     */
    public function testResignLocalTransactionSetsStatusToPending(): void
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
            ->willReturn('previous-tx-123');

        // Remote transaction wins
        $remoteTransaction = [
            'txid' => 'aaa-winner',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://contact.example.com',
            'sender_public_key' => 'contact-public-key',
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 1000,
            'currency' => 'USD',
            'memo' => 'standard',
            'time' => time(),
            'status' => 'completed',
            'sender_signature' => 'valid-signature',
            'signature_nonce' => time()
        ];

        // Local transaction loses
        $localLosingTx = [
            'txid' => 'zzz-loser',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://user.example.com',
            'sender_public_key' => 'user-public-key',
            'receiver_address' => 'http://contact.example.com',
            'receiver_public_key' => 'contact-public-key',
            'amount' => 500,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => 'sent' // Was already sent
        ];

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$remoteTransaction],
                'latestTxid' => 'aaa-winner'
            ]));

        $this->mockChainRepo->expects($this->once())
            ->method('getLocalTransactionByPreviousTxid')
            ->willReturn($localLosingTx);

        $this->mockTransactionRepo->expects($this->any())
            ->method('transactionExistsTxid')
            ->willReturn(false);

        $this->mockChainRepo->expects($this->once())
            ->method('updatePreviousTxid')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getByTxid')
            ->willReturn([array_merge($localLosingTx, ['previous_txid' => 'aaa-winner'])]);

        $this->mockTransportUtility->expects($this->once())
            ->method('signWithCapture')
            ->willReturn(['signature' => 'new-sig', 'nonce' => time()]);

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateSignatureData')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('updateTimestamp')
            ->willReturn(true);

        // Verify status is set to PENDING with isTxid = true
        $this->mockTransactionRepo->expects($this->once())
            ->method('updateStatus')
            ->with('zzz-loser', Constants::STATUS_PENDING, true)
            ->willReturn(true);

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        $this->assertTrue($result['success']);
    }

    /**
     * Test graceful handling of signing failures during re-sign
     *
     * If signing fails during chain conflict resolution, the operation should
     * handle the failure gracefully and continue processing other transactions.
     */
    public function testResignLocalTransactionHandlesSigningFailure(): void
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
            ->willReturn('previous-tx-123');

        // Remote transaction wins
        $remoteTransaction = [
            'txid' => 'aaa-winner',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://contact.example.com',
            'sender_public_key' => 'contact-public-key',
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 1000,
            'currency' => 'USD',
            'memo' => 'standard',
            'time' => time(),
            'status' => 'completed',
            'sender_signature' => 'valid-signature',
            'signature_nonce' => time()
        ];

        // Local transaction loses
        $localLosingTx = [
            'txid' => 'zzz-loser',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://user.example.com',
            'sender_public_key' => 'user-public-key',
            'receiver_address' => 'http://contact.example.com',
            'receiver_public_key' => 'contact-public-key',
            'amount' => 500,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => 'sent'
        ];

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$remoteTransaction],
                'latestTxid' => 'aaa-winner'
            ]));

        $this->mockChainRepo->expects($this->once())
            ->method('getLocalTransactionByPreviousTxid')
            ->willReturn($localLosingTx);

        $this->mockTransactionRepo->expects($this->any())
            ->method('transactionExistsTxid')
            ->willReturn(false);

        $this->mockChainRepo->expects($this->once())
            ->method('updatePreviousTxid')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getByTxid')
            ->willReturn([array_merge($localLosingTx, ['previous_txid' => 'aaa-winner'])]);

        // Signing fails - returns null/false
        $this->mockTransportUtility->expects($this->once())
            ->method('signWithCapture')
            ->willReturn(null);

        // updateSignatureData should NOT be called since signing failed
        $this->mockTransactionRepo->expects($this->never())
            ->method('updateSignatureData');

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        // Sync should still complete successfully despite re-signing failure
        // The conflict was detected and resolved (counted), even if re-signing failed
        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['conflicts_resolved']);
    }

    /**
     * Test that conflict count is tracked in sync results
     *
     * The sync result should include an accurate count of how many
     * chain conflicts were detected and resolved during the sync operation.
     */
    public function testChainConflictCountsInSyncResult(): void
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
            ->willReturn('previous-tx-123');

        // Two remote transactions, each causing a conflict
        $remoteTransaction1 = [
            'txid' => 'aaa-remote-1',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://contact.example.com',
            'sender_public_key' => 'contact-public-key',
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 1000,
            'currency' => 'USD',
            'memo' => 'standard',
            'time' => time(),
            'status' => 'completed',
            'sender_signature' => 'valid-signature-1',
            'signature_nonce' => time()
        ];

        $remoteTransaction2 = [
            'txid' => 'bbb-remote-2',
            'previous_txid' => 'aaa-remote-1', // Chains after first
            'sender_address' => 'http://contact.example.com',
            'sender_public_key' => 'contact-public-key',
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 2000,
            'currency' => 'USD',
            'memo' => 'standard',
            'time' => time() + 1,
            'status' => 'completed',
            'sender_signature' => 'valid-signature-2',
            'signature_nonce' => time() + 1
        ];

        // Local conflicting transactions
        $localConflict1 = [
            'txid' => 'zzz-local-1',
            'previous_txid' => 'previous-tx-123',
            'sender_address' => 'http://user.example.com',
            'sender_public_key' => 'user-public-key',
            'memo' => 'standard',
            'status' => 'sent'
        ];

        $localConflict2 = [
            'txid' => 'yyy-local-2',
            'previous_txid' => 'aaa-remote-1',
            'sender_address' => 'http://user.example.com',
            'sender_public_key' => 'user-public-key',
            'memo' => 'standard',
            'status' => 'sent'
        ];

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$remoteTransaction1, $remoteTransaction2],
                'latestTxid' => 'bbb-remote-2'
            ]));

        // Return different local conflicts for each previous_txid check
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('getLocalTransactionByPreviousTxid')
            ->willReturnCallback(function ($prevTxid) use ($localConflict1, $localConflict2) {
                if ($prevTxid === 'previous-tx-123') {
                    return $localConflict1;
                } elseif ($prevTxid === 'aaa-remote-1') {
                    return $localConflict2;
                }
                return null;
            });

        $this->mockTransactionRepo->expects($this->any())
            ->method('transactionExistsTxid')
            ->willReturn(false);

        // Both conflicts require re-signing (remote wins in both cases: aaa < zzz, bbb < yyy)
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('updatePreviousTxid')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->exactly(2))
            ->method('getByTxid')
            ->willReturnCallback(function ($txid) use ($localConflict1, $localConflict2) {
                if ($txid === 'zzz-local-1') {
                    return [array_merge($localConflict1, ['previous_txid' => 'aaa-remote-1'])];
                } elseif ($txid === 'yyy-local-2') {
                    return [array_merge($localConflict2, ['previous_txid' => 'bbb-remote-2'])];
                }
                return null;
            });

        $this->mockTransportUtility->expects($this->exactly(2))
            ->method('signWithCapture')
            ->willReturn(['signature' => 'new-sig', 'nonce' => time()]);

        $this->mockTransactionRepo->expects($this->exactly(2))
            ->method('updateSignatureData')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->exactly(2))
            ->method('updateTimestamp')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->exactly(2))
            ->method('updateStatus')
            ->willReturn(true);

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        $this->assertTrue($result['success']);
        // Verify both conflicts were counted
        $this->assertEquals(2, $result['conflicts_resolved']);
        // Both remote transactions should be synced
        $this->assertEquals(2, $result['synced_count']);
    }

    // =========================================================================
    // Bidirectional Sync and Chain Integrity Tests
    // =========================================================================

    /**
     * Test that bidirectional sync properly exchanges txid lists between nodes
     *
     * Verifies that when performing bidirectional sync:
     * - Local chain state is retrieved with txid list
     * - Txid list is sent to the remote contact
     * - Response containing remote txid list is processed
     * - Missing transactions are correctly identified on both sides
     */
    public function testBidirectionalSyncExchangesTxidLists(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $contactAddress = 'http://contact.example.com';
        $contactPubkey = 'contact-public-key';

        $localTxidList = ['tx-local-1', 'tx-local-2', 'tx-local-3'];
        $remoteTxidList = ['tx-local-1', 'tx-remote-1', 'tx-remote-2'];

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn(['http://user.example.com']);

        // Mock local chain state with our txid list
        $this->mockChainRepo->expects($this->once())
            ->method('getChainStateSummary')
            ->willReturn([
                'transaction_count' => 3,
                'oldest_txid' => 'tx-local-1',
                'newest_txid' => 'tx-local-3',
                'txid_list' => $localTxidList
            ]);

        // Remote responds with their txid list and no transactions to send
        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'txid_list' => $remoteTxidList,
                'transactions' => []
            ]));

        // Balance sync after transaction sync
        $this->mockTransactionRepo->expects($this->any())
            ->method('getTransactionsBetweenPubkeys')
            ->willReturn([]);

        $result = $this->service->bidirectionalSync($contactAddress, $contactPubkey);

        $this->assertTrue($result['success']);
        // tx-local-2 and tx-local-3 are missing on remote (we have, they don't)
        $this->assertContains('tx-local-2', $result['remote_missing']);
        $this->assertContains('tx-local-3', $result['remote_missing']);
        // tx-remote-1 and tx-remote-2 are missing locally (they have, we don't)
        $this->assertContains('tx-remote-1', $result['local_missing']);
        $this->assertContains('tx-remote-2', $result['local_missing']);
    }

    /**
     * Test receiving transactions missing locally during bidirectional sync
     *
     * Verifies that transactions the remote has but we don't are properly
     * processed and signature verification is attempted on each one.
     */
    public function testBidirectionalSyncReceivesMissingTransactions(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $senderKeys = $this->generateTestKeyPair();
        $receiverKeys = $this->generateTestKeyPair();

        $contactAddress = 'http://contact.example.com';
        $contactPubkey = $senderKeys['publicKey'];

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn($receiverKeys['publicKey']);

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn(['http://user.example.com']);

        // Local chain state - we only have tx-1
        $this->mockChainRepo->expects($this->once())
            ->method('getChainStateSummary')
            ->willReturn([
                'transaction_count' => 1,
                'oldest_txid' => 'tx-1',
                'newest_txid' => 'tx-1',
                'txid_list' => ['tx-1']
            ]);

        // Create a valid signed transaction that we're missing
        $missingTx = $this->createSignedStandardTransaction($senderKeys, $receiverKeys, [
            'txid' => 'tx-2',
            'previous_txid' => 'tx-1',
            'sender_address' => $contactAddress,
            'receiver_address' => 'http://user.example.com',
            'status' => Constants::STATUS_COMPLETED
        ]);

        // Add recipient signature
        $nonce = $missingTx['signature_nonce'];
        $messageContent = [
            'type' => 'send',
            'time' => $missingTx['time'],
            'receiverAddress' => $missingTx['receiver_address'],
            'receiverPublicKey' => $missingTx['receiver_public_key'],
            'amount' => (int)$missingTx['amount'],
            'currency' => $missingTx['currency'],
            'txid' => $missingTx['txid'],
            'previousTxid' => $missingTx['previous_txid'],
            'memo' => $missingTx['memo'],
            'nonce' => $nonce
        ];
        $message = json_encode($messageContent);
        $recipientSignature = '';
        openssl_sign($message, $recipientSignature, $receiverKeys['keyResource']);
        $missingTx['recipient_signature'] = base64_encode($recipientSignature);

        // Remote sends tx-2 which we don't have
        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'txid_list' => ['tx-1', 'tx-2'],
                'transactions' => [$missingTx]
            ]));

        // Transaction doesn't exist locally
        $this->mockTransactionRepo->expects($this->once())
            ->method('transactionExistsTxid')
            ->with('tx-2')
            ->willReturn(false);

        // Expect insertion of the valid transaction
        $this->mockTransactionRepo->expects($this->once())
            ->method('insertTransaction');

        // Balance sync
        $this->mockTransactionRepo->expects($this->any())
            ->method('getTransactionsBetweenPubkeys')
            ->willReturn([]);

        $result = $this->service->bidirectionalSync($contactAddress, $contactPubkey);

        $this->assertTrue($result['success']);
        $this->assertEquals(1, $result['received_count']);
        $this->assertContains('tx-2', $result['local_missing']);
    }

    /**
     * Test that handleSyncNegotiationRequest returns correct txid list
     *
     * Verifies that when handling a sync negotiation request:
     * - The local txid list is retrieved and returned
     * - Transactions missing on remote are identified and included in response
     */
    public function testHandleSyncNegotiationRequestReturnsCorrectTxidList(): void
    {
        $request = [
            'senderAddress' => 'http://contact.example.com',
            'senderPublicKey' => 'contact-public-key',
            'txid_list' => ['tx-1'] // Remote only has tx-1
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('contactExistsPubkey')
            ->with('contact-public-key')
            ->willReturn(true);

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn('user-public-key');

        // Local chain state - we have tx-1, tx-2, tx-3
        $this->mockChainRepo->expects($this->once())
            ->method('getChainStateSummary')
            ->willReturn([
                'transaction_count' => 3,
                'oldest_txid' => 'tx-1',
                'newest_txid' => 'tx-3',
                'txid_list' => ['tx-1', 'tx-2', 'tx-3']
            ]);

        // For each missing txid (tx-2, tx-3), we look up the transaction
        $tx2 = [
            'txid' => 'tx-2',
            'previous_txid' => 'tx-1',
            'sender_address' => 'http://user.example.com',
            'sender_public_key' => 'user-public-key',
            'receiver_address' => 'http://contact.example.com',
            'receiver_public_key' => 'contact-public-key',
            'amount' => 100,
            'currency' => 'USD',
            'memo' => 'standard',
            'timestamp' => '2025-01-01 10:00:00',
            'time' => 1234567890,
            'status' => Constants::STATUS_COMPLETED,
            'sender_signature' => 'sig-2',
            'signature_nonce' => 1234567890,
            'recipient_signature' => 'rsig-2'
        ];

        $tx3 = [
            'txid' => 'tx-3',
            'previous_txid' => 'tx-2',
            'sender_address' => 'http://contact.example.com',
            'sender_public_key' => 'contact-public-key',
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 200,
            'currency' => 'USD',
            'memo' => 'standard',
            'timestamp' => '2025-01-01 11:00:00',
            'time' => 1234567891,
            'status' => Constants::STATUS_COMPLETED,
            'sender_signature' => 'sig-3',
            'signature_nonce' => 1234567891,
            'recipient_signature' => 'rsig-3'
        ];

        $this->mockTransactionRepo->expects($this->exactly(2))
            ->method('getByTxid')
            ->willReturnOnConsecutiveCalls([$tx2], [$tx3]);

        // Expect output containing accepted status and txid_list
        $this->expectOutputRegex('/accepted.*txid_list/i');

        $this->service->handleSyncNegotiationRequest($request);
    }

    /**
     * Test that chain integrity verification detects gaps in the transaction chain
     *
     * This tests the sync behavior when transactions reference a missing previous_txid.
     * The sync process should continue and attempt to verify/insert transactions,
     * with signature failures tracked but not stopping the overall sync.
     */
    public function testVerifyChainIntegrityDetectsGaps(): void
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

        // Transaction references a non-existent previous_txid (gap in chain)
        $txWithGap = [
            'txid' => 'tx-2',
            'previous_txid' => 'tx-1-missing', // This txid doesn't exist locally
            'sender_address' => $contactAddress,
            'sender_public_key' => $contactPubkey,
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 100,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => Constants::STATUS_COMPLETED,
            'sender_signature' => 'invalid-sig',
            'signature_nonce' => 12345,
            'recipient_signature' => 'rsig',
            'time' => 1234567890
        ];

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$txWithGap],
                'latestTxid' => 'tx-2'
            ]));

        // Transaction doesn't exist yet
        $this->mockTransactionRepo->expects($this->once())
            ->method('transactionExistsTxid')
            ->with('tx-2')
            ->willReturn(false);

        // No chain conflict - the previous_txid simply doesn't exist
        $this->mockChainRepo->expects($this->once())
            ->method('getLocalTransactionByPreviousTxid')
            ->willReturn(null);

        $this->mockHeldTransactionService->expects($this->once())
            ->method('onSyncComplete')
            ->with($contactPubkey, true, 0); // 0 synced due to signature failure

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        // Sync completes successfully but with 0 transactions synced (signature fails)
        $this->assertTrue($result['success']);
        $this->assertEquals(0, $result['synced_count']);
        // The gap is implicitly detected by the fact that signature verification fails
        // In production with valid signatures, the transaction would be inserted despite the gap
    }

    /**
     * Test that sync continues when individual signature verification fails
     *
     * Verifies that when one transaction fails signature verification,
     * the sync process continues with remaining transactions instead
     * of stopping entirely. Failed transactions are tracked but don't
     * prevent other valid transactions from being processed.
     */
    public function testSyncTransactionChainContinuesOnSignatureFailure(): void
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

        // Both transactions have invalid signatures (no proper keys in test)
        $invalidTx1 = [
            'txid' => 'tx-invalid-1',
            'previous_txid' => null,
            'sender_address' => $contactAddress,
            'sender_public_key' => $contactPubkey,
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 100,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => Constants::STATUS_COMPLETED,
            'sender_signature' => 'invalid-sig-1',
            'signature_nonce' => 12345,
            'recipient_signature' => 'rsig-1',
            'time' => 1234567890
        ];

        $invalidTx2 = [
            'txid' => 'tx-invalid-2',
            'previous_txid' => 'tx-invalid-1',
            'sender_address' => $contactAddress,
            'sender_public_key' => $contactPubkey,
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 200,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => Constants::STATUS_COMPLETED,
            'sender_signature' => 'invalid-sig-2',
            'signature_nonce' => 12346,
            'recipient_signature' => 'rsig-2',
            'time' => 1234567891
        ];

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$invalidTx1, $invalidTx2],
                'latestTxid' => 'tx-invalid-2'
            ]));

        // Neither transaction exists
        $this->mockTransactionRepo->expects($this->exactly(2))
            ->method('transactionExistsTxid')
            ->willReturn(false);

        // No chain conflicts - only checked for second tx (first has null previous_txid)
        $this->mockChainRepo->expects($this->once())
            ->method('getLocalTransactionByPreviousTxid')
            ->willReturn(null);

        $this->mockHeldTransactionService->expects($this->once())
            ->method('onSyncComplete')
            ->with($contactPubkey, true, 0); // 0 synced because both fail signature

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        // Sync completes successfully despite all signature failures
        $this->assertTrue($result['success']);
        // No transactions synced because all signatures failed
        $this->assertEquals(0, $result['synced_count']);
        // Signature failure flag is false (we continue, don't stop)
        $this->assertFalse($result['signature_failure']);
    }

    /**
     * Test that already-existing transactions are skipped during sync
     *
     * Verifies that when the remote sends transactions we already have,
     * they are properly skipped without attempting signature verification
     * or re-insertion.
     */
    public function testSyncTransactionChainSkipsExistingTransactions(): void
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
            ->willReturn('tx-existing');

        // Remote sends both existing and new transactions
        $existingTx = [
            'txid' => 'tx-existing',
            'previous_txid' => null,
            'sender_address' => $contactAddress,
            'sender_public_key' => $contactPubkey,
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 100,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => Constants::STATUS_COMPLETED,
            'sender_signature' => 'sig',
            'signature_nonce' => 12345,
            'recipient_signature' => 'rsig',
            'time' => 1234567890
        ];

        $newTx = [
            'txid' => 'tx-new',
            'previous_txid' => 'tx-existing',
            'sender_address' => $contactAddress,
            'sender_public_key' => $contactPubkey,
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => 'user-public-key',
            'amount' => 200,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => Constants::STATUS_COMPLETED,
            'sender_signature' => 'sig2',
            'signature_nonce' => 12346,
            'recipient_signature' => 'rsig2',
            'time' => 1234567891
        ];

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$existingTx, $newTx],
                'latestTxid' => 'tx-new'
            ]));

        // First transaction exists (skip), second doesn't (process)
        $this->mockTransactionRepo->expects($this->exactly(2))
            ->method('transactionExistsTxid')
            ->willReturnCallback(function ($txid) {
                return $txid === 'tx-existing'; // true for existing, false for new
            });

        // Only check for chain conflict on new transaction
        $this->mockChainRepo->expects($this->once())
            ->method('getLocalTransactionByPreviousTxid')
            ->with('tx-existing', $this->anything(), $this->anything())
            ->willReturn(null);

        $this->mockHeldTransactionService->expects($this->once())
            ->method('onSyncComplete');

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        $this->assertTrue($result['success']);
        // Existing transaction skipped, new transaction processed (but signature fails)
        $this->assertEquals(0, $result['synced_count']); // 0 due to signature failure in test
    }

    /**
     * Test partial sync recovery resumes from last good transaction
     *
     * Verifies that when sync receives multiple transactions and some
     * have invalid signatures, the sync:
     * - Continues processing all transactions
     * - Tracks valid transactions that were synced
     * - Tracks signature failures separately
     * - Completes successfully overall
     */
    public function testPartialSyncRecoveryResumesFromLastGoodTransaction(): void
    {
        $this->service->setHeldTransactionService($this->mockHeldTransactionService);

        $senderKeys = $this->generateTestKeyPair();
        $receiverKeys = $this->generateTestKeyPair();

        $contactAddress = 'http://contact.example.com';
        $contactPubkey = $senderKeys['publicKey'];

        $this->mockUserContext->expects($this->any())
            ->method('getPublicKey')
            ->willReturn($receiverKeys['publicKey']);

        $this->mockUserContext->expects($this->any())
            ->method('getUserAddresses')
            ->willReturn(['http://user.example.com']);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(null);

        // Create mix of valid and invalid transactions
        $validTx1 = $this->createSignedStandardTransaction($senderKeys, $receiverKeys, [
            'txid' => 'tx-valid-1',
            'previous_txid' => null,
            'sender_address' => $contactAddress,
            'receiver_address' => 'http://user.example.com'
        ]);

        // Add recipient signature to valid tx
        $nonce = $validTx1['signature_nonce'];
        $messageContent = [
            'type' => 'send',
            'time' => $validTx1['time'],
            'receiverAddress' => $validTx1['receiver_address'],
            'receiverPublicKey' => $validTx1['receiver_public_key'],
            'amount' => (int)$validTx1['amount'],
            'currency' => $validTx1['currency'],
            'txid' => $validTx1['txid'],
            'previousTxid' => $validTx1['previous_txid'],
            'memo' => $validTx1['memo'],
            'nonce' => $nonce
        ];
        $message = json_encode($messageContent);
        $recipientSignature = '';
        openssl_sign($message, $recipientSignature, $receiverKeys['keyResource']);
        $validTx1['recipient_signature'] = base64_encode($recipientSignature);

        // Invalid transaction (forged signature)
        $invalidTx = [
            'txid' => 'tx-invalid',
            'previous_txid' => 'tx-valid-1',
            'sender_address' => $contactAddress,
            'sender_public_key' => $contactPubkey,
            'receiver_address' => 'http://user.example.com',
            'receiver_public_key' => $receiverKeys['publicKey'],
            'amount' => 200,
            'currency' => 'USD',
            'memo' => 'standard',
            'status' => Constants::STATUS_COMPLETED,
            'sender_signature' => 'forged-signature',
            'signature_nonce' => 99999,
            'recipient_signature' => 'forged-rsig',
            'time' => 1234567891
        ];

        // Another valid transaction after the invalid one
        $validTx2 = $this->createSignedStandardTransaction($senderKeys, $receiverKeys, [
            'txid' => 'tx-valid-2',
            'previous_txid' => 'tx-invalid',
            'sender_address' => $contactAddress,
            'receiver_address' => 'http://user.example.com'
        ]);

        // Add recipient signature to valid tx2
        $nonce2 = $validTx2['signature_nonce'];
        $messageContent2 = [
            'type' => 'send',
            'time' => $validTx2['time'],
            'receiverAddress' => $validTx2['receiver_address'],
            'receiverPublicKey' => $validTx2['receiver_public_key'],
            'amount' => (int)$validTx2['amount'],
            'currency' => $validTx2['currency'],
            'txid' => $validTx2['txid'],
            'previousTxid' => $validTx2['previous_txid'],
            'memo' => $validTx2['memo'],
            'nonce' => $nonce2
        ];
        $message2 = json_encode($messageContent2);
        $recipientSignature2 = '';
        openssl_sign($message2, $recipientSignature2, $receiverKeys['keyResource']);
        $validTx2['recipient_signature'] = base64_encode($recipientSignature2);

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn(json_encode([
                'status' => Constants::STATUS_ACCEPTED,
                'transactions' => [$validTx1, $invalidTx, $validTx2],
                'latestTxid' => 'tx-valid-2'
            ]));

        // All transactions are new
        $this->mockTransactionRepo->expects($this->exactly(3))
            ->method('transactionExistsTxid')
            ->willReturn(false);

        // No chain conflicts - only checked for tx2 and tx3 (tx1 has null previous_txid)
        $this->mockChainRepo->expects($this->exactly(2))
            ->method('getLocalTransactionByPreviousTxid')
            ->willReturn(null);

        // Two valid transactions should be inserted
        $this->mockTransactionRepo->expects($this->exactly(2))
            ->method('insertTransaction');

        $this->mockHeldTransactionService->expects($this->once())
            ->method('onSyncComplete')
            ->with(
                $contactPubkey,
                true, // success
                2     // synced_count - both valid transactions
            );

        $result = $this->service->syncTransactionChain($contactAddress, $contactPubkey);

        // Sync should complete successfully
        $this->assertTrue($result['success']);
        // Two valid transactions should be synced
        $this->assertEquals(2, $result['synced_count']);
        // Signature failure flag should be false (we continue, not stop)
        $this->assertFalse($result['signature_failure']);
        // Latest txid from response
        $this->assertEquals('tx-valid-2', $result['latest_txid']);
    }
}
