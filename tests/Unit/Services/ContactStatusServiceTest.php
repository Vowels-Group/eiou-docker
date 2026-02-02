<?php
/**
 * Unit Tests for ContactStatusService
 *
 * Tests contact status ping/pong handling including:
 * - Incoming ping request handling (validation, blocked contacts, accepted contacts)
 * - Pong response generation with chain state
 * - Manual ping with rate limiting
 * - Contact status updates
 * - Chain sync triggering
 * - Setter injection for circular dependencies
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\ContactStatusService;
use Eiou\Services\RateLimiterService;
use Eiou\Database\ContactRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Schemas\Payloads\ContactStatusPayload;
use RuntimeException;

#[CoversClass(ContactStatusService::class)]
class ContactStatusServiceTest extends TestCase
{
    private ContactStatusService $service;
    private ContactRepository $mockContactRepo;
    private TransactionRepository $mockTransactionRepo;
    private UtilityServiceContainer $mockUtilityContainer;
    private TransportUtilityService $mockTransportUtility;
    private CurrencyUtilityService $mockCurrencyUtility;
    private TimeUtilityService $mockTimeUtility;
    private ValidationUtilityService $mockValidationUtility;
    private UserContext $mockUserContext;
    private SyncTriggerInterface $mockSyncTrigger;
    private RateLimiterService $mockRateLimiter;

    private const TEST_USER_PUBKEY = 'test-user-public-key-abc123';
    private const TEST_USER_PUBKEY_HASH = 'test-user-pubkey-hash';
    private const TEST_CONTACT_PUBKEY = 'test-contact-public-key-xyz789';
    private const TEST_CONTACT_ADDRESS = 'http://192.168.1.100:8080';
    private const TEST_PREV_TXID = 'prev-txid-12345';

    protected function setUp(): void
    {
        $this->mockContactRepo = $this->createMock(ContactRepository::class);
        $this->mockTransactionRepo = $this->createMock(TransactionRepository::class);
        $this->mockUtilityContainer = $this->createMock(UtilityServiceContainer::class);

        // Mock all concrete utility services (required by BasePayload typed properties)
        $this->mockTransportUtility = $this->createMock(TransportUtilityService::class);
        $this->mockCurrencyUtility = $this->createMock(CurrencyUtilityService::class);
        $this->mockTimeUtility = $this->createMock(TimeUtilityService::class);
        $this->mockValidationUtility = $this->createMock(ValidationUtilityService::class);

        $this->mockUserContext = $this->createMock(UserContext::class);
        $this->mockSyncTrigger = $this->createMock(SyncTriggerInterface::class);
        $this->mockRateLimiter = $this->createMock(RateLimiterService::class);

        // Configure utility container to return all required concrete mock utilities
        $this->mockUtilityContainer->method('getTransportUtility')
            ->willReturn($this->mockTransportUtility);
        $this->mockUtilityContainer->method('getCurrencyUtility')
            ->willReturn($this->mockCurrencyUtility);
        $this->mockUtilityContainer->method('getTimeUtility')
            ->willReturn($this->mockTimeUtility);
        $this->mockUtilityContainer->method('getValidationUtility')
            ->willReturn($this->mockValidationUtility);

        // Default user context setup
        $this->mockUserContext->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);
        $this->mockUserContext->method('getPublicKeyHash')
            ->willReturn(self::TEST_USER_PUBKEY_HASH);

        // Default transport utility setup for address resolution
        $this->mockTransportUtility->method('resolveUserAddressForTransport')
            ->willReturnArgument(0);

        // Default time utility returns microtime (int format)
        $this->mockTimeUtility->method('getCurrentMicrotime')
            ->willReturn(17040672001234);

        $this->service = new ContactStatusService(
            $this->mockContactRepo,
            $this->mockTransactionRepo,
            $this->mockUtilityContainer,
            $this->mockUserContext
        );
    }

    // ============================================================
    // handlePingRequest Tests
    // ============================================================

    /**
     * Test handlePingRequest rejects when sender public key is missing
     */
    public function testHandlePingRequestRejectsWhenPublicKeyMissing(): void
    {
        $request = [
            'senderAddress' => self::TEST_CONTACT_ADDRESS
            // Missing 'senderPublicKey'
        ];

        ob_start();
        $this->service->handlePingRequest($request);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('rejected', $response['status']);
        $this->assertEquals('missing_public_key', $response['reason']);
    }

    /**
     * Test handlePingRequest rejects blocked contact
     */
    public function testHandlePingRequestRejectsBlockedContact(): void
    {
        $request = [
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('isAcceptedContactPubkey')
            ->with(self::TEST_CONTACT_PUBKEY)
            ->willReturn(false);

        $this->mockContactRepo->expects($this->once())
            ->method('isNotBlocked')
            ->with(self::TEST_CONTACT_PUBKEY)
            ->willReturn(false);

        ob_start();
        $this->service->handlePingRequest($request);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('rejected', $response['status']);
        $this->assertEquals('blocked', $response['reason']);
    }

    /**
     * Test handlePingRequest rejects unknown contact
     */
    public function testHandlePingRequestRejectsUnknownContact(): void
    {
        $request = [
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('isAcceptedContactPubkey')
            ->with(self::TEST_CONTACT_PUBKEY)
            ->willReturn(false);

        $this->mockContactRepo->expects($this->once())
            ->method('isNotBlocked')
            ->with(self::TEST_CONTACT_PUBKEY)
            ->willReturn(true);

        ob_start();
        $this->service->handlePingRequest($request);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('rejected', $response['status']);
        $this->assertEquals('unknown_contact', $response['reason']);
    }

    /**
     * Test handlePingRequest responds with pong for accepted contact
     */
    public function testHandlePingRequestRespondsPongForAcceptedContact(): void
    {
        $request = [
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS,
            'prevTxid' => self::TEST_PREV_TXID
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('isAcceptedContactPubkey')
            ->with(self::TEST_CONTACT_PUBKEY)
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->with(self::TEST_USER_PUBKEY, self::TEST_CONTACT_PUBKEY)
            ->willReturn(self::TEST_PREV_TXID);

        $this->mockContactRepo->expects($this->once())
            ->method('updateContactFields')
            ->with(self::TEST_CONTACT_PUBKEY, $this->callback(function ($fields) {
                return $fields['online_status'] === Constants::CONTACT_ONLINE_STATUS_ONLINE
                    && isset($fields['last_ping_at']);
            }))
            ->willReturn(true);

        ob_start();
        $this->service->handlePingRequest($request);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('pong', $response['status']);
        $this->assertEquals(self::TEST_PREV_TXID, $response['prevTxid']);
        $this->assertTrue($response['chainValid']);
    }

    /**
     * Test handlePingRequest marks chain as invalid when prev_txid mismatch
     */
    public function testHandlePingRequestMarksChainInvalidOnMismatch(): void
    {
        $localPrevTxid = 'local-prev-txid-111';
        $remotePrevTxid = 'remote-prev-txid-222';

        $request = [
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS,
            'prevTxid' => $remotePrevTxid
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('isAcceptedContactPubkey')
            ->with(self::TEST_CONTACT_PUBKEY)
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn($localPrevTxid);

        $this->mockContactRepo->expects($this->once())
            ->method('updateContactFields')
            ->willReturn(true);

        ob_start();
        $this->service->handlePingRequest($request);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('pong', $response['status']);
        $this->assertEquals($localPrevTxid, $response['prevTxid']);
        $this->assertFalse($response['chainValid']);
    }

    /**
     * Test handlePingRequest triggers sync when requestSync is true and chains mismatch
     */
    public function testHandlePingRequestTriggersSyncOnMismatchWithRequestSync(): void
    {
        $localPrevTxid = 'local-prev-txid-111';
        $remotePrevTxid = 'remote-prev-txid-222';

        $request = [
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS,
            'prevTxid' => $remotePrevTxid,
            'requestSync' => true
        ];

        $this->service->setSyncTrigger($this->mockSyncTrigger);

        $this->mockContactRepo->expects($this->once())
            ->method('isAcceptedContactPubkey')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn($localPrevTxid);

        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->with(self::TEST_CONTACT_ADDRESS, self::TEST_CONTACT_PUBKEY)
            ->willReturn(['success' => true, 'synced_count' => 1]);

        $this->mockContactRepo->expects($this->once())
            ->method('updateContactFields')
            ->willReturn(true);

        ob_start();
        $this->service->handlePingRequest($request);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('pong', $response['status']);
        $this->assertFalse($response['chainValid']);
    }

    /**
     * Test handlePingRequest does not trigger sync when requestSync is false
     */
    public function testHandlePingRequestDoesNotTriggerSyncWhenRequestSyncFalse(): void
    {
        $localPrevTxid = 'local-prev-txid-111';
        $remotePrevTxid = 'remote-prev-txid-222';

        $request = [
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS,
            'prevTxid' => $remotePrevTxid,
            'requestSync' => false
        ];

        $this->service->setSyncTrigger($this->mockSyncTrigger);

        $this->mockContactRepo->expects($this->once())
            ->method('isAcceptedContactPubkey')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn($localPrevTxid);

        // Sync should never be called
        $this->mockSyncTrigger->expects($this->never())
            ->method('syncTransactionChain');

        $this->mockContactRepo->expects($this->once())
            ->method('updateContactFields')
            ->willReturn(true);

        ob_start();
        $this->service->handlePingRequest($request);
        ob_get_clean();
    }

    /**
     * Test handlePingRequest chain is valid when both prev_txid are null
     */
    public function testHandlePingRequestChainValidWhenBothPrevTxidNull(): void
    {
        $request = [
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS,
            'prevTxid' => null
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('isAcceptedContactPubkey')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(null);

        $this->mockContactRepo->expects($this->once())
            ->method('updateContactFields')
            ->willReturn(true);

        ob_start();
        $this->service->handlePingRequest($request);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('pong', $response['status']);
        $this->assertTrue($response['chainValid']);
    }

    // ============================================================
    // pingContact Tests
    // ============================================================

    /**
     * Test pingContact returns rate limited error when rate limit exceeded
     */
    public function testPingContactReturnsRateLimitedWhenExceeded(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with(self::TEST_USER_PUBKEY_HASH, 'manual_ping', 3, 300, 300)
            ->willReturn([
                'allowed' => false,
                'retry_after' => 180
            ]);

        $result = $this->service->pingContact('test-contact');

        $this->assertFalse($result['success']);
        $this->assertEquals('rate_limited', $result['error']);
        $this->assertEquals(180, $result['retry_after']);
    }

    /**
     * Test pingContact returns contact not found error
     */
    public function testPingContactReturnsContactNotFound(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->with('unknown-contact')
            ->willReturn(null);

        $result = $this->service->pingContact('unknown-contact');

        $this->assertFalse($result['success']);
        $this->assertEquals('contact_not_found', $result['error']);
    }

    /**
     * Test pingContact returns contact not accepted error
     */
    public function testPingContactReturnsContactNotAccepted(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->with('pending-contact')
            ->willReturn([
                'name' => 'pending-contact',
                'pubkey' => self::TEST_CONTACT_PUBKEY,
                'status' => 'pending',
                'http' => self::TEST_CONTACT_ADDRESS
            ]);

        $result = $this->service->pingContact('pending-contact');

        $this->assertFalse($result['success']);
        $this->assertEquals('contact_not_accepted', $result['error']);
    }

    /**
     * Test pingContact returns no address error
     */
    public function testPingContactReturnsNoAddress(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->willReturn([
                'name' => 'no-address-contact',
                'pubkey' => self::TEST_CONTACT_PUBKEY,
                'status' => 'accepted',
                'tor' => null,
                'https' => null,
                'http' => null
            ]);

        $result = $this->service->pingContact('no-address-contact');

        $this->assertFalse($result['success']);
        $this->assertEquals('no_address', $result['error']);
    }

    /**
     * Test pingContact returns online with valid chain on successful pong
     */
    public function testPingContactReturnsOnlineWithValidChain(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $contact = [
            'name' => 'test-contact',
            'pubkey' => self::TEST_CONTACT_PUBKEY,
            'status' => 'accepted',
            'http' => self::TEST_CONTACT_ADDRESS
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->willReturn($contact);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(self::TEST_PREV_TXID);

        $pongResponse = json_encode([
            'status' => 'pong',
            'chainValid' => true,
            'prevTxid' => self::TEST_PREV_TXID
        ]);

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn($pongResponse);

        // Expect contact status to be updated to online
        $this->mockContactRepo->expects($this->exactly(2))
            ->method('updateContactFields')
            ->willReturn(true);

        $result = $this->service->pingContact('test-contact');

        $this->assertTrue($result['success']);
        $this->assertEquals('test-contact', $result['contact_name']);
        $this->assertEquals('online', $result['online_status']);
        $this->assertTrue($result['chain_valid']);
    }

    /**
     * Test pingContact returns online with chain needs sync when chains mismatch
     */
    public function testPingContactReturnsOnlineWithChainNeedsSync(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);
        $this->service->setSyncTrigger($this->mockSyncTrigger);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $contact = [
            'name' => 'test-contact',
            'pubkey' => self::TEST_CONTACT_PUBKEY,
            'status' => 'accepted',
            'http' => self::TEST_CONTACT_ADDRESS
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->willReturn($contact);

        $localPrevTxid = 'local-txid-111';
        $remotePrevTxid = 'remote-txid-222';

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn($localPrevTxid);

        $pongResponse = json_encode([
            'status' => 'pong',
            'chainValid' => false,
            'prevTxid' => $remotePrevTxid
        ]);

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn($pongResponse);

        $this->mockContactRepo->expects($this->atLeast(2))
            ->method('updateContactFields')
            ->willReturn(true);

        // Sync may be triggered depending on CONTACT_STATUS_SYNC_ON_PING constant
        $this->mockSyncTrigger->method('syncTransactionChain')
            ->willReturn(['success' => true, 'synced_count' => 1]);

        $result = $this->service->pingContact('test-contact');

        $this->assertTrue($result['success']);
        $this->assertEquals('online', $result['online_status']);
        $this->assertFalse($result['chain_valid']);
        $this->assertStringContainsString('sync', strtolower($result['message']));
    }

    /**
     * Test pingContact returns online when ping rejected but contact responds
     */
    public function testPingContactReturnsOnlineWhenPingRejected(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $contact = [
            'name' => 'test-contact',
            'pubkey' => self::TEST_CONTACT_PUBKEY,
            'status' => 'accepted',
            'http' => self::TEST_CONTACT_ADDRESS
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->willReturn($contact);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(self::TEST_PREV_TXID);

        $rejectedResponse = json_encode([
            'status' => 'rejected',
            'reason' => 'disabled'
        ]);

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn($rejectedResponse);

        $this->mockContactRepo->expects($this->once())
            ->method('updateContactFields')
            ->willReturn(true);

        $result = $this->service->pingContact('test-contact');

        $this->assertTrue($result['success']);
        $this->assertEquals('online', $result['online_status']);
        $this->assertNull($result['chain_valid']);
        $this->assertStringContainsString('rejected', $result['message']);
    }

    /**
     * Test pingContact returns offline when no valid response
     */
    public function testPingContactReturnsOfflineWhenNoValidResponse(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $contact = [
            'name' => 'test-contact',
            'pubkey' => self::TEST_CONTACT_PUBKEY,
            'status' => 'accepted',
            'http' => self::TEST_CONTACT_ADDRESS
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->willReturn($contact);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(self::TEST_PREV_TXID);

        // Return invalid/empty response
        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn('');

        $this->mockContactRepo->expects($this->once())
            ->method('updateContactFields')
            ->willReturn(true);

        $result = $this->service->pingContact('test-contact');

        $this->assertTrue($result['success']);
        $this->assertEquals('offline', $result['online_status']);
        $this->assertNull($result['chain_valid']);
    }

    /**
     * Test pingContact returns offline on connection exception
     */
    public function testPingContactReturnsOfflineOnConnectionException(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $contact = [
            'name' => 'test-contact',
            'pubkey' => self::TEST_CONTACT_PUBKEY,
            'status' => 'accepted',
            'http' => self::TEST_CONTACT_ADDRESS
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->willReturn($contact);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(self::TEST_PREV_TXID);

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willThrowException(new \Exception('Connection timeout'));

        $this->mockContactRepo->expects($this->once())
            ->method('updateContactFields')
            ->willReturn(true);

        $result = $this->service->pingContact('test-contact');

        $this->assertTrue($result['success']);
        $this->assertEquals('offline', $result['online_status']);
        $this->assertNull($result['chain_valid']);
        $this->assertStringContainsString('connection failed', strtolower($result['message']));
    }

    /**
     * Test pingContact prefers Tor address over HTTPS and HTTP
     */
    public function testPingContactPrefersTorAddress(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $torAddress = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.onion';
        $contact = [
            'name' => 'test-contact',
            'pubkey' => self::TEST_CONTACT_PUBKEY,
            'status' => 'accepted',
            'tor' => $torAddress,
            'https' => 'https://example.com',
            'http' => 'http://example.com'
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->willReturn($contact);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(self::TEST_PREV_TXID);

        // Verify the Tor address is used
        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->with($torAddress, $this->anything())
            ->willReturn(json_encode(['status' => 'pong', 'chainValid' => true, 'prevTxid' => self::TEST_PREV_TXID]));

        $this->mockContactRepo->expects($this->exactly(2))
            ->method('updateContactFields')
            ->willReturn(true);

        $result = $this->service->pingContact('test-contact');

        $this->assertTrue($result['success']);
    }

    /**
     * Test pingContact prefers HTTPS over HTTP when no Tor
     */
    public function testPingContactPrefersHttpsOverHttp(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $httpsAddress = 'https://secure.example.com';
        $contact = [
            'name' => 'test-contact',
            'pubkey' => self::TEST_CONTACT_PUBKEY,
            'status' => 'accepted',
            'tor' => null,
            'https' => $httpsAddress,
            'http' => 'http://insecure.example.com'
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->willReturn($contact);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(self::TEST_PREV_TXID);

        // Verify the HTTPS address is used
        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->with($httpsAddress, $this->anything())
            ->willReturn(json_encode(['status' => 'pong', 'chainValid' => true, 'prevTxid' => self::TEST_PREV_TXID]));

        $this->mockContactRepo->expects($this->exactly(2))
            ->method('updateContactFields')
            ->willReturn(true);

        $result = $this->service->pingContact('test-contact');

        $this->assertTrue($result['success']);
    }

    // ============================================================
    // Setter Injection Tests
    // ============================================================

    /**
     * Test setSyncTrigger properly sets the sync trigger
     */
    public function testSetSyncTriggerProperlySetsSyncTrigger(): void
    {
        // Without setting sync trigger, accessing it should throw when needed
        $this->service->setSyncTrigger($this->mockSyncTrigger);

        // Set up a scenario where sync would be triggered
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $contact = [
            'name' => 'test-contact',
            'pubkey' => self::TEST_CONTACT_PUBKEY,
            'status' => 'accepted',
            'http' => self::TEST_CONTACT_ADDRESS
        ];

        $this->mockContactRepo->method('getContactByNameOrAddress')
            ->willReturn($contact);

        $this->mockTransactionRepo->method('getPreviousTxid')
            ->willReturn('local-txid');

        // Mismatched chain to trigger sync
        $pongResponse = json_encode([
            'status' => 'pong',
            'chainValid' => false,
            'prevTxid' => 'remote-txid'
        ]);

        $this->mockTransportUtility->method('send')
            ->willReturn($pongResponse);

        $this->mockContactRepo->method('updateContactFields')
            ->willReturn(true);

        // Sync trigger should be called
        $this->mockSyncTrigger->expects($this->atMost(1))
            ->method('syncTransactionChain')
            ->willReturn(['success' => true, 'synced_count' => 1]);

        $result = $this->service->pingContact('test-contact');
        $this->assertTrue($result['success']);
    }

    /**
     * Test setRateLimiterService properly sets the rate limiter
     */
    public function testSetRateLimiterServiceProperlySetRateLimiter(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => false, 'retry_after' => 60]);

        $result = $this->service->pingContact('test-contact');

        $this->assertFalse($result['success']);
        $this->assertEquals('rate_limited', $result['error']);
    }

    /**
     * Test pingContact throws exception when rate limiter not injected
     */
    public function testPingContactThrowsExceptionWhenRateLimiterNotInjected(): void
    {
        // Do NOT call setRateLimiterService

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RateLimiterService not injected');

        $this->service->pingContact('test-contact');
    }

    // ============================================================
    // getRepository Tests
    // ============================================================

    /**
     * Test getRepository returns the contact repository
     */
    public function testGetRepositoryReturnsContactRepository(): void
    {
        $repository = $this->service->getRepository();

        $this->assertSame($this->mockContactRepo, $repository);
    }

    // ============================================================
    // Edge Case Tests
    // ============================================================

    /**
     * Test handlePingRequest handles update contact status exception gracefully
     */
    public function testHandlePingRequestHandlesUpdateContactStatusException(): void
    {
        $request = [
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS,
            'prevTxid' => self::TEST_PREV_TXID
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('isAcceptedContactPubkey')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(self::TEST_PREV_TXID);

        // Simulate exception when updating contact status
        $this->mockContactRepo->expects($this->once())
            ->method('updateContactFields')
            ->willThrowException(new \Exception('Database error'));

        // Should still return pong despite the exception
        ob_start();
        $this->service->handlePingRequest($request);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('pong', $response['status']);
    }

    /**
     * Test handlePingRequest handles sync trigger exception gracefully
     */
    public function testHandlePingRequestHandlesSyncTriggerException(): void
    {
        $request = [
            'senderPublicKey' => self::TEST_CONTACT_PUBKEY,
            'senderAddress' => self::TEST_CONTACT_ADDRESS,
            'prevTxid' => 'remote-txid',
            'requestSync' => true
        ];

        $this->service->setSyncTrigger($this->mockSyncTrigger);

        $this->mockContactRepo->expects($this->once())
            ->method('isAcceptedContactPubkey')
            ->willReturn(true);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn('local-txid');

        // Sync trigger throws exception
        $this->mockSyncTrigger->expects($this->once())
            ->method('syncTransactionChain')
            ->willThrowException(new \Exception('Sync failed'));

        $this->mockContactRepo->expects($this->once())
            ->method('updateContactFields')
            ->willReturn(true);

        // Should still return pong despite the sync exception
        ob_start();
        $this->service->handlePingRequest($request);
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertEquals('pong', $response['status']);
    }

    /**
     * Test pingContact uses anonymous identifier when user pubkey hash is null
     */
    public function testPingContactUsesAnonymousIdentifierWhenPubkeyHashNull(): void
    {
        // Create new mocks with null pubkey hash
        $mockUserContext = $this->createMock(UserContext::class);
        $mockUserContext->method('getPublicKey')
            ->willReturn(self::TEST_USER_PUBKEY);
        $mockUserContext->method('getPublicKeyHash')
            ->willReturn(null);

        $service = new ContactStatusService(
            $this->mockContactRepo,
            $this->mockTransactionRepo,
            $this->mockUtilityContainer,
            $mockUserContext
        );

        $service->setRateLimiterService($this->mockRateLimiter);

        // Verify rate limiter is called with 'anonymous'
        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->with('anonymous', 'manual_ping', 3, 300, 300)
            ->willReturn(['allowed' => false, 'retry_after' => 60]);

        $result = $service->pingContact('test-contact');

        $this->assertFalse($result['success']);
        $this->assertEquals('rate_limited', $result['error']);
    }

    /**
     * Test pingContact handles chain status update exception gracefully
     */
    public function testPingContactHandlesChainStatusUpdateException(): void
    {
        $this->service->setRateLimiterService($this->mockRateLimiter);

        $this->mockRateLimiter->expects($this->once())
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'remaining' => 2]);

        $contact = [
            'name' => 'test-contact',
            'pubkey' => self::TEST_CONTACT_PUBKEY,
            'status' => 'accepted',
            'http' => self::TEST_CONTACT_ADDRESS
        ];

        $this->mockContactRepo->expects($this->once())
            ->method('getContactByNameOrAddress')
            ->willReturn($contact);

        $this->mockTransactionRepo->expects($this->once())
            ->method('getPreviousTxid')
            ->willReturn(self::TEST_PREV_TXID);

        $pongResponse = json_encode([
            'status' => 'pong',
            'chainValid' => true,
            'prevTxid' => self::TEST_PREV_TXID
        ]);

        $this->mockTransportUtility->expects($this->once())
            ->method('send')
            ->willReturn($pongResponse);

        // First call for online status succeeds, second call for chain status throws
        $callCount = 0;
        $this->mockContactRepo->expects($this->exactly(2))
            ->method('updateContactFields')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    throw new \Exception('Database error on chain update');
                }
                return true;
            });

        // Should still return success despite chain update exception
        $result = $this->service->pingContact('test-contact');

        $this->assertTrue($result['success']);
        $this->assertEquals('online', $result['online_status']);
    }
}
