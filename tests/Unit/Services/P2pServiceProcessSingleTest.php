<?php
/**
 * Unit Tests for P2pService::processSingleP2p()
 *
 * Tests the single-P2P worker processing method including:
 * - Atomic claim failure
 * - Hash lookup failure
 * - Direct contact match path (Path A)
 * - Broadcast path (Path B) with sendMultiBatch
 * - Cancellation when no contacts accept
 * - Contact count tracking (accepted + relayed)
 * - Sending metadata cleanup on success and failure
 * - Sender address filtering during broadcast
 * - Destination address filtering during broadcast
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\P2pService;
use Eiou\Services\MessageDeliveryService;
use Eiou\Contracts\ContactServiceInterface;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\P2pSenderRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;

#[CoversClass(P2pService::class)]
class P2pServiceProcessSingleTest extends TestCase
{
    private MockObject|ContactServiceInterface $contactService;
    private MockObject|BalanceRepository $balanceRepository;
    private MockObject|P2pRepository $p2pRepository;
    private MockObject|TransactionRepository $transactionRepository;
    private MockObject|UtilityServiceContainer $utilityContainer;
    private MockObject|ValidationUtilityService $validationUtility;
    private MockObject|TransportUtilityService $transportUtility;
    private MockObject|TimeUtilityService $timeUtility;
    private MockObject|CurrencyUtilityService $currencyUtility;
    private MockObject|UserContext $userContext;
    private MockObject|MessageDeliveryService $messageDeliveryService;
    private MockObject|P2pSenderRepository $p2pSenderRepository;
    private P2pService $service;

    private const TEST_ADDRESS = 'http://test.example.com';
    private const TEST_PUBLIC_KEY = 'test-public-key-1234567890';
    private const TEST_HASH = 'abc123def456789012345678901234567890123456789012345678901234abcd';
    private const TEST_AMOUNT = 10000;
    private const TEST_PID = 12345;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contactService = $this->createMock(ContactServiceInterface::class);
        $this->balanceRepository = $this->createMock(BalanceRepository::class);
        $this->p2pRepository = $this->createMock(P2pRepository::class);
        $this->transactionRepository = $this->createMock(TransactionRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->validationUtility = $this->createMock(ValidationUtilityService::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->timeUtility = $this->createMock(TimeUtilityService::class);
        $this->currencyUtility = $this->createMock(CurrencyUtilityService::class);
        $this->userContext = $this->createMock(UserContext::class);
        $this->messageDeliveryService = $this->createMock(MessageDeliveryService::class);
        $this->p2pSenderRepository = $this->createMock(P2pSenderRepository::class);

        $this->utilityContainer->method('getValidationUtility')->willReturn($this->validationUtility);
        $this->utilityContainer->method('getTransportUtility')->willReturn($this->transportUtility);
        $this->utilityContainer->method('getCurrencyUtility')->willReturn($this->currencyUtility);
        $this->utilityContainer->method('getTimeUtility')->willReturn($this->timeUtility);

        $this->userContext->method('getDefaultFee')->willReturn(1.0);
        $this->userContext->method('getMinimumFee')->willReturn(10.0);
        $this->userContext->method('getMaxP2pLevel')->willReturn(3);
        $this->userContext->method('getMaxFee')->willReturn(5.0);
        $this->userContext->method('getPublicKey')->willReturn(self::TEST_PUBLIC_KEY);

        $this->service = new P2pService(
            $this->contactService,
            $this->balanceRepository,
            $this->p2pRepository,
            $this->transactionRepository,
            $this->utilityContainer,
            $this->userContext,
            $this->messageDeliveryService,
            $this->p2pSenderRepository
        );
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Build a standard queued P2P message array for broadcast path (with destination_address)
     */
    private function buildBroadcastMessage(string $hash = self::TEST_HASH): array
    {
        return [
            'hash' => $hash,
            'salt' => 'test-salt',
            'time' => '1234567890',
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_SENDING,
            'destination_address' => 'http://destination.test',
        ];
    }

    /**
     * Build a standard set of contacts for broadcast tests
     */
    private function buildContacts(): array
    {
        return [
            ['http' => 'http://contact1.test', 'pubkey' => 'pubkey1'],
            ['http' => 'http://contact2.test', 'pubkey' => 'pubkey2'],
            ['http' => 'http://contact3.test', 'pubkey' => 'pubkey3'],
        ];
    }

    /**
     * Configure mocks for a standard broadcast scenario where all contacts accept
     */
    private function setupBroadcastMocks(array $message, array $contacts): void
    {
        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        // sendMultiBatch returns 'inserted' for all sends
        $this->transportUtility->method('sendMultiBatch')
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $results[$send['key']] = [
                        'response' => '{"status":"inserted"}',
                        'signature' => 'sig',
                        'nonce' => 'nonce',
                    ];
                }
                return $results;
            });
    }

    // =========================================================================
    // Claim Failure Tests
    // =========================================================================

    /**
     * Test processSingleP2p returns false when claimQueuedP2p fails
     *
     * When another worker has already claimed the hash, claimQueuedP2p returns
     * false and the method should bail out immediately without any further calls.
     */
    public function testProcessSingleP2pReturnsFalseWhenClaimFails(): void
    {
        $this->p2pRepository->expects($this->once())
            ->method('claimQueuedP2p')
            ->with(self::TEST_HASH, self::TEST_PID)
            ->willReturn(false);

        // Should not call getByHash if claim failed
        $this->p2pRepository->expects($this->never())
            ->method('getByHash');

        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Hash Lookup Failure Tests
    // =========================================================================

    /**
     * Test processSingleP2p returns false when hash not found in database
     *
     * Claim succeeds but getByHash returns null (race condition, deleted row, etc.).
     */
    public function testProcessSingleP2pReturnsFalseWhenHashNotFound(): void
    {
        $this->p2pRepository->expects($this->once())
            ->method('claimQueuedP2p')
            ->with(self::TEST_HASH, self::TEST_PID)
            ->willReturn(true);

        $this->p2pRepository->expects($this->once())
            ->method('getByHash')
            ->with(self::TEST_HASH)
            ->willReturn(null);

        // Should not attempt broadcast or direct match
        $this->transportUtility->expects($this->never())
            ->method('sendMultiBatch');

        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);

        $this->assertFalse($result);
    }

    // =========================================================================
    // Path A: Direct Contact Match Tests
    // =========================================================================

    /**
     * Test processSingleP2p direct match transitions status to sent
     *
     * When the message has no destination_address and matchContact() finds a
     * matching contact, the service sends directly and transitions to 'sent'.
     */
    public function testProcessSingleP2pDirectMatchTransitionsToSent(): void
    {
        $contactAddress = 'http://direct-contact.test';
        $salt = 'test-salt';
        $time = '1234567890';
        $hash = hash(Constants::HASH_ALGORITHM, $contactAddress . $salt . $time);

        $message = [
            'hash' => $hash,
            'salt' => $salt,
            'time' => $time,
            'sender_address' => self::TEST_ADDRESS,
            'sender_public_key' => self::TEST_PUBLIC_KEY,
            'amount' => self::TEST_AMOUNT,
            'currency' => 'USD',
            'request_level' => 1,
            'max_request_level' => 5,
            'fee_amount' => 100,
            'expiration' => '1234567890000000',
            'status' => Constants::STATUS_SENDING,
            // No destination_address - triggers matchContact path
        ];

        $matchingContact = [
            'name' => 'DirectContact',
            'http' => $contactAddress,
            'pubkey' => 'direct-pubkey',
        ];

        $this->p2pRepository->method('claimQueuedP2p')
            ->willReturn(true);

        $this->p2pRepository->method('getByHash')
            ->willReturn($message);

        $this->contactService->method('getAllContacts')
            ->willReturn([$matchingContact]);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        // Direct match sends via messageDeliveryService
        $this->messageDeliveryService->expects($this->once())
            ->method('sendMessage')
            ->with(
                'p2p',
                $contactAddress,
                $this->anything(),
                $this->stringContains('direct-'),
                true
            )
            ->willReturn([
                'success' => true,
                'response' => ['status' => 'inserted'],
                'raw' => '{"status":"inserted"}',
                'messageId' => 'direct-' . $hash . '-abc12345',
            ]);

        $this->messageDeliveryService->expects($this->once())
            ->method('updateStageToForwarded')
            ->with('p2p', $this->anything(), $contactAddress);

        $this->p2pRepository->expects($this->once())
            ->method('updateContactsSentCount')
            ->with($hash, 1);

        // Expect status transition to sent and metadata cleanup
        $statusCalls = [];
        $this->p2pRepository->method('updateStatus')
            ->willReturnCallback(function ($h, $s) use (&$statusCalls) {
                $statusCalls[] = [$h, $s];
                return true;
            });

        $this->p2pRepository->expects($this->once())
            ->method('clearSendingMetadata')
            ->with($hash);

        ob_start();
        $result = $this->service->processSingleP2p($hash, self::TEST_PID);
        ob_end_clean();

        $this->assertTrue($result);
        // Verify status was set to 'sent'
        $this->assertContains([$hash, Constants::STATUS_SENT], $statusCalls);
    }

    // =========================================================================
    // Path B: Broadcast Tests
    // =========================================================================

    /**
     * Test processSingleP2p broadcast sends to all contacts via sendMultiBatch
     *
     * When the message has destination_address set, it takes the broadcast path,
     * collects eligible contacts, and fires sendMultiBatch.
     */
    public function testProcessSingleP2pBroadcastSendsToAllContacts(): void
    {
        $message = $this->buildBroadcastMessage();
        $contacts = $this->buildContacts();

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);

        // getByHash is called multiple times: once for initial fetch, once after broadcast
        $this->p2pRepository->method('getByHash')
            ->willReturn($message);

        $this->setupBroadcastMocks($message, $contacts);

        // Verify sendMultiBatch is called with 3 sends (all contacts eligible)
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->with($this->callback(function ($sends) {
                return count($sends) === 3;
            }))
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $results[$send['key']] = [
                        'response' => '{"status":"inserted"}',
                        'signature' => 'sig',
                        'nonce' => 'nonce',
                    ];
                }
                return $results;
            });

        ob_start();
        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        $this->assertTrue($result);
    }

    /**
     * Test processSingleP2p broadcast cancels when no contacts accept
     *
     * When sendMultiBatch returns all rejections (or empty), sentMessages is 0
     * and the P2P should be cancelled.
     */
    public function testProcessSingleP2pBroadcastCancelsWhenNoContactsAccept(): void
    {
        $message = $this->buildBroadcastMessage();
        $contacts = $this->buildContacts();

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);

        $this->p2pRepository->method('getByHash')
            ->willReturn($message);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        // All contacts reject
        $this->transportUtility->method('sendMultiBatch')
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $results[$send['key']] = [
                        'response' => '{"status":"rejected","message":"insufficient_funds"}',
                        'signature' => 'sig',
                        'nonce' => 'nonce',
                    ];
                }
                return $results;
            });

        // Expect cancellation status
        $statusCalls = [];
        $this->p2pRepository->method('updateStatus')
            ->willReturnCallback(function ($h, $s) use (&$statusCalls) {
                $statusCalls[] = [$h, $s];
                return true;
            });

        $this->p2pRepository->expects($this->once())
            ->method('clearSendingMetadata')
            ->with(self::TEST_HASH);

        ob_start();
        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        $this->assertTrue($result);
        $this->assertContains([self::TEST_HASH, Constants::STATUS_CANCELLED], $statusCalls);
    }

    /**
     * Test processSingleP2p broadcast updates contact counts correctly
     *
     * Verifies that accepted and relayed contacts are tracked separately:
     * - acceptedContacts: contacts that returned 'inserted'
     * - relayedContacts: contacts that returned 'already_relayed'
     */
    public function testProcessSingleP2pBroadcastUpdatesContactCounts(): void
    {
        $message = $this->buildBroadcastMessage();
        $contacts = $this->buildContacts();

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);

        $this->p2pRepository->method('getByHash')
            ->willReturn($message);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        // Mixed results: 2 inserted, 1 already_relayed
        $callCount = 0;
        $this->transportUtility->method('sendMultiBatch')
            ->willReturnCallback(function ($sends) {
                $results = [];
                $i = 0;
                foreach ($sends as $send) {
                    $status = ($i < 2) ? 'inserted' : 'already_relayed';
                    $results[$send['key']] = [
                        'response' => '{"status":"' . $status . '"}',
                        'signature' => 'sig',
                        'nonce' => 'nonce',
                    ];
                    $i++;
                }
                return $results;
            });

        // Track updateContactsSentCount calls
        $sentCountCalls = [];
        $this->p2pRepository->method('updateContactsSentCount')
            ->willReturnCallback(function ($hash, $count) use (&$sentCountCalls) {
                $sentCountCalls[] = $count;
                return true;
            });

        $this->p2pRepository->expects($this->once())
            ->method('updateContactsRelayedCount')
            ->with(self::TEST_HASH, 1);

        $this->p2pRepository->method('updateStatus')->willReturn(true);
        $this->p2pRepository->method('clearSendingMetadata')->willReturn(true);

        ob_start();
        $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        // First call: ceiling (3 contacts), second call: actual accepted (2 inserted)
        $this->assertEquals(3, $sentCountCalls[0]);
        $this->assertEquals(2, $sentCountCalls[1]);
    }

    /**
     * Test processSingleP2p clears sending metadata on success
     *
     * After successful broadcast processing, clearSendingMetadata must be called
     * to release the atomic claim so recovery processes don't pick it up.
     */
    public function testProcessSingleP2pClearsSendingMetadataOnSuccess(): void
    {
        $message = $this->buildBroadcastMessage();
        $contacts = $this->buildContacts();

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);
        $this->p2pRepository->method('getByHash')->willReturn($message);
        $this->setupBroadcastMocks($message, $contacts);
        $this->p2pRepository->method('updateStatus')->willReturn(true);
        $this->p2pRepository->method('updateContactsSentCount')->willReturn(true);

        $this->p2pRepository->expects($this->once())
            ->method('clearSendingMetadata')
            ->with(self::TEST_HASH);

        ob_start();
        $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();
    }

    /**
     * Test processSingleP2p clears sending metadata on exception
     *
     * When an exception is thrown during processing, the method catches it,
     * logs it, and still calls clearSendingMetadata before returning false.
     */
    public function testProcessSingleP2pClearsSendingMetadataOnException(): void
    {
        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);

        // getByHash throws an exception
        $this->p2pRepository->method('getByHash')
            ->willThrowException(new \RuntimeException('Database connection lost'));

        $this->p2pRepository->expects($this->once())
            ->method('clearSendingMetadata')
            ->with(self::TEST_HASH);

        ob_start();
        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        $this->assertFalse($result);
    }

    /**
     * Test processSingleP2p returns true on full success
     *
     * End-to-end success path: claim -> fetch -> broadcast -> transitions -> cleanup.
     */
    public function testProcessSingleP2pReturnsTrueOnSuccess(): void
    {
        $message = $this->buildBroadcastMessage();
        $contacts = $this->buildContacts();

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);
        $this->p2pRepository->method('getByHash')->willReturn($message);
        $this->setupBroadcastMocks($message, $contacts);
        $this->p2pRepository->method('updateStatus')->willReturn(true);
        $this->p2pRepository->method('updateContactsSentCount')->willReturn(true);
        $this->p2pRepository->method('clearSendingMetadata')->willReturn(true);

        ob_start();
        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        $this->assertTrue($result);
    }

    /**
     * Test processSingleP2p broadcast skips the sender's own address
     *
     * The sender_address in the message should be excluded from the broadcast
     * to prevent echoing the P2P back to the node that sent it.
     */
    public function testProcessSingleP2pSkipsSenderAddress(): void
    {
        $message = $this->buildBroadcastMessage();

        // Include sender's address as one of the contacts
        $contacts = [
            ['http' => 'http://contact1.test', 'pubkey' => 'pubkey1'],
            ['http' => self::TEST_ADDRESS, 'pubkey' => 'sender-pubkey'], // Same as sender_address
            ['http' => 'http://contact3.test', 'pubkey' => 'pubkey3'],
        ];

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);
        $this->p2pRepository->method('getByHash')->willReturn($message);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        // sendMultiBatch should only receive 2 sends (sender address filtered out)
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->with($this->callback(function ($sends) {
                if (count($sends) !== 2) {
                    return false;
                }
                foreach ($sends as $send) {
                    if ($send['recipient'] === self::TEST_ADDRESS) {
                        return false; // Sender address should be filtered out
                    }
                }
                return true;
            }))
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $results[$send['key']] = [
                        'response' => '{"status":"inserted"}',
                        'signature' => 'sig',
                        'nonce' => 'nonce',
                    ];
                }
                return $results;
            });

        $this->p2pRepository->method('updateStatus')->willReturn(true);
        $this->p2pRepository->method('updateContactsSentCount')->willReturn(true);
        $this->p2pRepository->method('clearSendingMetadata')->willReturn(true);

        ob_start();
        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        $this->assertTrue($result);
    }

    /**
     * Test processSingleP2p broadcast skips the destination address
     *
     * When the message has a destination_address set, that address should also
     * be excluded from the broadcast (it is the original receiver that already
     * failed direct delivery).
     */
    public function testProcessSingleP2pSkipsDestinationAddress(): void
    {
        $destinationAddress = 'http://destination.test';
        $message = $this->buildBroadcastMessage();
        $message['destination_address'] = $destinationAddress;

        // Include destination address as one of the contacts
        $contacts = [
            ['http' => 'http://contact1.test', 'pubkey' => 'pubkey1'],
            ['http' => $destinationAddress, 'pubkey' => 'dest-pubkey'], // Same as destination_address
            ['http' => 'http://contact3.test', 'pubkey' => 'pubkey3'],
        ];

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);
        $this->p2pRepository->method('getByHash')->willReturn($message);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        // sendMultiBatch should only receive 2 sends (destination address filtered out)
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->with($this->callback(function ($sends) use ($destinationAddress) {
                if (count($sends) !== 2) {
                    return false;
                }
                foreach ($sends as $send) {
                    if ($send['recipient'] === $destinationAddress) {
                        return false; // Destination address should be filtered out
                    }
                }
                return true;
            }))
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $results[$send['key']] = [
                        'response' => '{"status":"inserted"}',
                        'signature' => 'sig',
                        'nonce' => 'nonce',
                    ];
                }
                return $results;
            });

        $this->p2pRepository->method('updateStatus')->willReturn(true);
        $this->p2pRepository->method('updateContactsSentCount')->willReturn(true);
        $this->p2pRepository->method('clearSendingMetadata')->willReturn(true);

        ob_start();
        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        $this->assertTrue($result);
    }

    /**
     * Test processSingleP2p transitions from sending to sent after broadcast
     *
     * After broadcast completes, the method checks if the current status is
     * STATUS_SENDING and transitions to STATUS_SENT.
     */
    public function testProcessSingleP2pTransitionsFromSendingToSent(): void
    {
        $message = $this->buildBroadcastMessage();
        $contacts = $this->buildContacts();

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);

        // getByHash returns STATUS_SENDING for the post-broadcast check
        $this->p2pRepository->method('getByHash')
            ->willReturn(array_merge($message, ['status' => Constants::STATUS_SENDING]));

        $this->setupBroadcastMocks($message, $contacts);
        $this->p2pRepository->method('updateContactsSentCount')->willReturn(true);
        $this->p2pRepository->method('clearSendingMetadata')->willReturn(true);

        // Track status updates to verify the sending->sent transition
        $statusCalls = [];
        $this->p2pRepository->method('updateStatus')
            ->willReturnCallback(function ($h, $s) use (&$statusCalls) {
                $statusCalls[] = [$h, $s];
                return true;
            });

        ob_start();
        $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        $this->assertContains([self::TEST_HASH, Constants::STATUS_SENT], $statusCalls);
    }

    /**
     * Test processSingleP2p broadcast with empty contact list
     *
     * When getAllAcceptedAddresses returns an empty array, no sends are made
     * and the P2P should be cancelled (sentMessages === 0).
     */
    public function testProcessSingleP2pBroadcastWithNoContacts(): void
    {
        $message = $this->buildBroadcastMessage();

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);
        $this->p2pRepository->method('getByHash')->willReturn($message);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn([]);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        // No batch should be sent since contact list is empty
        $this->transportUtility->expects($this->never())
            ->method('sendMultiBatch');

        // Should be cancelled
        $statusCalls = [];
        $this->p2pRepository->method('updateStatus')
            ->willReturnCallback(function ($h, $s) use (&$statusCalls) {
                $statusCalls[] = [$h, $s];
                return true;
            });

        $this->p2pRepository->method('updateContactsSentCount')->willReturn(true);
        $this->p2pRepository->method('clearSendingMetadata')->willReturn(true);

        ob_start();
        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        $this->assertTrue($result);
        $this->assertContains([self::TEST_HASH, Constants::STATUS_CANCELLED], $statusCalls);
    }

    /**
     * Test processSingleP2p exception during broadcast still clears metadata
     *
     * When sendMultiBatch throws an exception, the catch block should log it,
     * clear sending metadata, and return false.
     */
    public function testProcessSingleP2pExceptionDuringBroadcastClearsMetadata(): void
    {
        $message = $this->buildBroadcastMessage();
        $contacts = $this->buildContacts();

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);
        $this->p2pRepository->method('getByHash')->willReturn($message);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        // sendMultiBatch throws an exception
        $this->transportUtility->method('sendMultiBatch')
            ->willThrowException(new \RuntimeException('curl_multi error'));

        $this->p2pRepository->method('updateContactsSentCount')->willReturn(true);

        $this->p2pRepository->expects($this->once())
            ->method('clearSendingMetadata')
            ->with(self::TEST_HASH);

        ob_start();
        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        $this->assertFalse($result);
    }

    /**
     * Test processSingleP2p broadcast filters contacts with null transport address
     *
     * Contacts that have no address for the determined transport type should be
     * filtered out of the broadcast batch.
     */
    public function testProcessSingleP2pBroadcastFiltersNullTransportContacts(): void
    {
        $message = $this->buildBroadcastMessage();

        $contacts = [
            ['http' => 'http://contact1.test', 'pubkey' => 'pubkey1'],
            ['http' => null, 'pubkey' => 'pubkey2'],          // No http address
            ['pubkey' => 'pubkey3'],                           // Missing http key entirely
            ['http' => 'http://contact4.test', 'pubkey' => 'pubkey4'],
        ];

        $this->p2pRepository->method('claimQueuedP2p')->willReturn(true);
        $this->p2pRepository->method('getByHash')->willReturn($message);

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->transportUtility->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $this->contactService->method('getAllAcceptedAddresses')
            ->willReturn($contacts);

        $this->contactService->method('getAllContacts')
            ->willReturn([]);

        // Only 2 contacts have valid http addresses
        $this->transportUtility->expects($this->once())
            ->method('sendMultiBatch')
            ->with($this->callback(function ($sends) {
                return count($sends) === 2;
            }))
            ->willReturnCallback(function ($sends) {
                $results = [];
                foreach ($sends as $send) {
                    $results[$send['key']] = [
                        'response' => '{"status":"inserted"}',
                        'signature' => 'sig',
                        'nonce' => 'nonce',
                    ];
                }
                return $results;
            });

        $this->p2pRepository->method('updateStatus')->willReturn(true);
        $this->p2pRepository->method('updateContactsSentCount')->willReturn(true);
        $this->p2pRepository->method('clearSendingMetadata')->willReturn(true);

        ob_start();
        $result = $this->service->processSingleP2p(self::TEST_HASH, self::TEST_PID);
        ob_end_clean();

        $this->assertTrue($result);
    }
}
