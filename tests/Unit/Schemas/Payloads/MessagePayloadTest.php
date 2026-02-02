<?php
/**
 * Unit Tests for MessagePayload
 *
 * Tests message payload building functionality for contact and transaction messages.
 */

namespace Eiou\Tests\Schemas\Payloads;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Schemas\Payloads\MessagePayload;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;

#[CoversClass(MessagePayload::class)]
class MessagePayloadTest extends TestCase
{
    private UserContext $userContext;
    private UtilityServiceContainer $utilityContainer;
    private TransportUtilityService $transportUtility;
    private MessagePayload $messagePayload;

    protected function setUp(): void
    {
        // Create mock objects for dependencies
        $this->userContext = $this->createMock(UserContext::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);

        // Create mocks for other utility services (required by BasePayload constructor)
        $currencyUtility = $this->createMock(CurrencyUtilityService::class);
        $timeUtility = $this->createMock(TimeUtilityService::class);
        $validationUtility = $this->createMock(ValidationUtilityService::class);

        // Configure utility container to return mock services
        $this->utilityContainer->expects($this->any())
            ->method('getCurrencyUtility')
            ->willReturn($currencyUtility);

        $this->utilityContainer->expects($this->any())
            ->method('getTimeUtility')
            ->willReturn($timeUtility);

        $this->utilityContainer->expects($this->any())
            ->method('getValidationUtility')
            ->willReturn($validationUtility);

        $this->utilityContainer->expects($this->any())
            ->method('getTransportUtility')
            ->willReturn($this->transportUtility);

        // Create the MessagePayload instance with mocked dependencies
        $this->messagePayload = new MessagePayload(
            $this->userContext,
            $this->utilityContainer
        );
    }

    // =========================================================================
    // buildContactIsAcceptedInquiry() Tests
    // =========================================================================

    /**
     * Test buildContactIsAcceptedInquiry returns array with inquiry=true
     */
    public function testBuildContactIsAcceptedInquiryReturnsArrayWithInquiryTrue(): void
    {
        $address = 'http://test.example.com';
        $resolvedAddress = 'http://myaddress.example.com';
        $publicKey = 'test-public-key-123';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($address)
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($publicKey);

        $result = $this->messagePayload->buildContactIsAcceptedInquiry($address);

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('contact', $result['typeMessage']);
        $this->assertTrue($result['inquiry']);
        $this->assertStringContainsString('wants to know if we are contacts', $result['message']);
        $this->assertEquals($resolvedAddress, $result['senderAddress']);
        $this->assertEquals($publicKey, $result['senderPublicKey']);
    }

    /**
     * Test buildContactIsAcceptedInquiry includes correct message format
     */
    public function testBuildContactIsAcceptedInquiryHasCorrectMessageFormat(): void
    {
        $address = 'http://recipient.example.com';
        $resolvedAddress = 'http://sender.example.com';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('pubkey');

        $result = $this->messagePayload->buildContactIsAcceptedInquiry($address);

        $this->assertEquals($resolvedAddress . ' wants to know if we are contacts', $result['message']);
    }

    // =========================================================================
    // buildContactIsAccepted() Tests
    // =========================================================================

    /**
     * Test buildContactIsAccepted returns array when encode=false
     */
    public function testBuildContactIsAcceptedReturnsArrayWhenEncodeIsFalse(): void
    {
        $address = 'http://test.example.com';
        $resolvedAddress = 'http://myaddress.example.com';
        $publicKey = 'test-public-key-456';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($address)
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($publicKey);

        $result = $this->messagePayload->buildContactIsAccepted($address, false);

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('contact', $result['typeMessage']);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $result['status']);
        $this->assertStringContainsString('confirms that we are contacts', $result['message']);
        $this->assertEquals($resolvedAddress, $result['senderAddress']);
        $this->assertEquals($publicKey, $result['senderPublicKey']);
    }

    /**
     * Test buildContactIsAccepted returns JSON when encode=true
     */
    public function testBuildContactIsAcceptedReturnsJsonWhenEncodeIsTrue(): void
    {
        $address = 'http://test.example.com';
        $resolvedAddress = 'http://myaddress.example.com';
        $publicKey = 'test-public-key-789';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($address)
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($publicKey);

        $result = $this->messagePayload->buildContactIsAccepted($address, true);

        $this->assertIsString($result);

        // Decode and verify JSON structure
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('message', $decoded['type']);
        $this->assertEquals('contact', $decoded['typeMessage']);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $decoded['status']);
        $this->assertEquals($resolvedAddress, $decoded['senderAddress']);
        $this->assertEquals($publicKey, $decoded['senderPublicKey']);
    }

    /**
     * Test buildContactIsAccepted defaults to encode=false
     */
    public function testBuildContactIsAcceptedDefaultsToEncodeFalse(): void
    {
        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://address.example.com');

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('pubkey');

        $result = $this->messagePayload->buildContactIsAccepted('http://test.example.com');

        $this->assertIsArray($result);
    }

    // =========================================================================
    // buildContactIsNotYetAccepted() Tests
    // =========================================================================

    /**
     * Test buildContactIsNotYetAccepted returns JSON with REJECTED status and PENDING reason
     */
    public function testBuildContactIsNotYetAcceptedReturnsJsonWithRejectedStatusAndPendingReason(): void
    {
        $address = 'http://test.example.com';
        $resolvedAddress = 'http://myaddress.example.com';
        $publicKey = 'test-public-key-abc';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($address)
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($publicKey);

        $result = $this->messagePayload->buildContactIsNotYetAccepted($address);

        $this->assertIsString($result);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('message', $decoded['type']);
        $this->assertEquals('contact', $decoded['typeMessage']);
        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
        $this->assertEquals(Constants::STATUS_PENDING, $decoded['reason']);
        $this->assertStringContainsString('has not yet accepted your contact request', $decoded['message']);
        $this->assertEquals($resolvedAddress, $decoded['senderAddress']);
        $this->assertEquals($publicKey, $decoded['senderPublicKey']);
    }

    // =========================================================================
    // buildContactIsUnknown() Tests
    // =========================================================================

    /**
     * Test buildContactIsUnknown returns JSON with 'unknown' reason
     */
    public function testBuildContactIsUnknownReturnsJsonWithUnknownReason(): void
    {
        $address = 'http://test.example.com';
        $resolvedAddress = 'http://myaddress.example.com';
        $publicKey = 'test-public-key-def';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($address)
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($publicKey);

        $result = $this->messagePayload->buildContactIsUnknown($address);

        $this->assertIsString($result);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('message', $decoded['type']);
        $this->assertEquals('contact', $decoded['typeMessage']);
        $this->assertEquals(Constants::STATUS_REJECTED, $decoded['status']);
        $this->assertEquals('unknown', $decoded['reason']);
        $this->assertStringContainsString('and you are not contacts', $decoded['message']);
        $this->assertEquals($resolvedAddress, $decoded['senderAddress']);
        $this->assertEquals($publicKey, $decoded['senderPublicKey']);
    }

    // =========================================================================
    // buildTransactionStatusResponse() Tests
    // =========================================================================

    /**
     * Test buildTransactionStatusResponse includes hash, hashType, status
     */
    public function testBuildTransactionStatusResponseIncludesHashHashTypeAndStatus(): void
    {
        $message = [
            'senderAddress' => 'http://sender.example.com',
            'hash' => 'abc123hash',
            'hashType' => 'memo'
        ];
        $status = Constants::STATUS_COMPLETED;
        $resolvedAddress = 'http://myaddress.example.com';
        $publicKey = 'test-public-key-ghi';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($message['senderAddress'])
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($publicKey);

        $result = $this->messagePayload->buildTransactionStatusResponse($message, $status);

        $this->assertIsString($result);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('message', $decoded['type']);
        $this->assertEquals('transaction', $decoded['typeMessage']);
        $this->assertEquals($status, $decoded['status']);
        $this->assertEquals('abc123hash', $decoded['hash']);
        $this->assertEquals('memo', $decoded['hashType']);
        $this->assertStringContainsString('Transaction status is:', $decoded['message']);
        $this->assertEquals($resolvedAddress, $decoded['senderAddress']);
        $this->assertEquals($publicKey, $decoded['senderPublicKey']);
    }

    /**
     * Test buildTransactionStatusResponse with missing hash defaults to 'unknown'
     */
    public function testBuildTransactionStatusResponseDefaultsToUnknownWhenHashMissing(): void
    {
        $message = [
            'senderAddress' => 'http://sender.example.com'
        ];

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://myaddress.example.com');

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('pubkey');

        $result = $this->messagePayload->buildTransactionStatusResponse($message, 'pending');

        $decoded = json_decode($result, true);
        $this->assertEquals('unknown', $decoded['hash']);
        $this->assertEquals('unknown', $decoded['hashType']);
    }

    // =========================================================================
    // buildTransactionNotFound() Tests
    // =========================================================================

    /**
     * Test buildTransactionNotFound returns status 'not_found'
     */
    public function testBuildTransactionNotFoundReturnsStatusNotFound(): void
    {
        $message = [
            'senderAddress' => 'http://sender.example.com',
            'hash' => 'nonexistent-hash',
            'hashType' => 'txid'
        ];
        $resolvedAddress = 'http://myaddress.example.com';
        $publicKey = 'test-public-key-jkl';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($message['senderAddress'])
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($publicKey);

        $result = $this->messagePayload->buildTransactionNotFound($message);

        $this->assertIsString($result);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('message', $decoded['type']);
        $this->assertEquals('transaction', $decoded['typeMessage']);
        $this->assertEquals('not_found', $decoded['status']);
        $this->assertEquals('nonexistent-hash', $decoded['hash']);
        $this->assertEquals('txid', $decoded['hashType']);
        $this->assertStringContainsString('not found', $decoded['message']);
        $this->assertEquals($resolvedAddress, $decoded['senderAddress']);
        $this->assertEquals($publicKey, $decoded['senderPublicKey']);
    }

    /**
     * Test buildTransactionNotFound with missing hash defaults to 'unknown'
     */
    public function testBuildTransactionNotFoundDefaultsToUnknownWhenHashMissing(): void
    {
        $message = [
            'senderAddress' => 'http://sender.example.com'
        ];

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://myaddress.example.com');

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('pubkey');

        $result = $this->messagePayload->buildTransactionNotFound($message);

        $decoded = json_decode($result, true);
        $this->assertEquals('unknown', $decoded['hash']);
        $this->assertEquals('unknown', $decoded['hashType']);
    }

    // =========================================================================
    // buildTransactionSyncRequest() Tests
    // =========================================================================

    /**
     * Test buildTransactionSyncRequest includes lastKnownTxid and contactPublicKey
     */
    public function testBuildTransactionSyncRequestIncludesLastKnownTxidAndContactPublicKey(): void
    {
        $contactAddress = 'http://contact.example.com';
        $contactPublicKey = 'contact-public-key-123';
        $lastKnownTxid = 'txid-abc-123';
        $resolvedAddress = 'http://myaddress.example.com';
        $userPublicKey = 'user-public-key-456';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($contactAddress)
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($userPublicKey);

        $result = $this->messagePayload->buildTransactionSyncRequest(
            $contactAddress,
            $contactPublicKey,
            $lastKnownTxid
        );

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('sync', $result['typeMessage']);
        $this->assertEquals('transaction_chain', $result['syncType']);
        $this->assertTrue($result['inquiry']);
        $this->assertEquals($contactPublicKey, $result['contactPublicKey']);
        $this->assertEquals($lastKnownTxid, $result['lastKnownTxid']);
        $this->assertStringContainsString('requesting transaction chain sync', $result['message']);
        $this->assertEquals($resolvedAddress, $result['senderAddress']);
        $this->assertEquals($userPublicKey, $result['senderPublicKey']);
    }

    /**
     * Test buildTransactionSyncRequest with null lastKnownTxid
     */
    public function testBuildTransactionSyncRequestWithNullLastKnownTxid(): void
    {
        $contactAddress = 'http://contact.example.com';
        $contactPublicKey = 'contact-public-key';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://myaddress.example.com');

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('pubkey');

        $result = $this->messagePayload->buildTransactionSyncRequest(
            $contactAddress,
            $contactPublicKey,
            null
        );

        $this->assertNull($result['lastKnownTxid']);
    }

    // =========================================================================
    // buildTransactionSyncResponse() Tests
    // =========================================================================

    /**
     * Test buildTransactionSyncResponse includes transactions array and count
     */
    public function testBuildTransactionSyncResponseIncludesTransactionsArrayAndCount(): void
    {
        $address = 'http://requester.example.com';
        $transactions = [
            ['txid' => 'tx1', 'amount' => 100],
            ['txid' => 'tx2', 'amount' => 200],
            ['txid' => 'tx3', 'amount' => 300]
        ];
        $latestTxid = 'tx3';
        $resolvedAddress = 'http://myaddress.example.com';
        $publicKey = 'test-public-key-mno';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($address)
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($publicKey);

        $result = $this->messagePayload->buildTransactionSyncResponse($address, $transactions, $latestTxid);

        $this->assertIsString($result);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('message', $decoded['type']);
        $this->assertEquals('sync', $decoded['typeMessage']);
        $this->assertEquals('transaction_chain', $decoded['syncType']);
        $this->assertFalse($decoded['inquiry']);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $decoded['status']);
        $this->assertIsArray($decoded['transactions']);
        $this->assertCount(3, $decoded['transactions']);
        $this->assertEquals(3, $decoded['transactionCount']);
        $this->assertEquals('tx3', $decoded['latestTxid']);
        $this->assertEquals($resolvedAddress, $decoded['senderAddress']);
        $this->assertEquals($publicKey, $decoded['senderPublicKey']);
    }

    /**
     * Test buildTransactionSyncResponse with empty transactions array
     */
    public function testBuildTransactionSyncResponseWithEmptyTransactionsArray(): void
    {
        $address = 'http://requester.example.com';
        $transactions = [];
        $latestTxid = null;

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://myaddress.example.com');

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('pubkey');

        $result = $this->messagePayload->buildTransactionSyncResponse($address, $transactions, $latestTxid);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded['transactions']);
        $this->assertEmpty($decoded['transactions']);
        $this->assertEquals(0, $decoded['transactionCount']);
        $this->assertNull($decoded['latestTxid']);
    }

    // =========================================================================
    // buildP2pStatusInquiry() Tests
    // =========================================================================

    /**
     * Test buildP2pStatusInquiry includes hash and inquiry=true
     */
    public function testBuildP2pStatusInquiryIncludesHashAndInquiryTrue(): void
    {
        $address = 'http://p2p-sender.example.com';
        $hash = 'p2p-hash-xyz-789';
        $resolvedAddress = 'http://myaddress.example.com';
        $publicKey = 'test-public-key-pqr';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($address)
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($publicKey);

        $result = $this->messagePayload->buildP2pStatusInquiry($address, $hash);

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('p2p', $result['typeMessage']);
        $this->assertTrue($result['inquiry']);
        $this->assertEquals($hash, $result['hash']);
        $this->assertStringContainsString('inquiring about P2P status', $result['message']);
        $this->assertStringContainsString($hash, $result['message']);
        $this->assertEquals($resolvedAddress, $result['senderAddress']);
        $this->assertEquals($publicKey, $result['senderPublicKey']);
    }

    // =========================================================================
    // buildP2pStatusResponse() Tests
    // =========================================================================

    /**
     * Test buildP2pStatusResponse includes hash and status
     */
    public function testBuildP2pStatusResponseIncludesHashAndStatus(): void
    {
        $address = 'http://requester.example.com';
        $hash = 'p2p-hash-abc-123';
        $status = Constants::STATUS_COMPLETED;
        $resolvedAddress = 'http://myaddress.example.com';
        $publicKey = 'test-public-key-stu';

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->with($address)
            ->willReturn($resolvedAddress);

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn($publicKey);

        $result = $this->messagePayload->buildP2pStatusResponse($address, $hash, $status);

        $this->assertIsString($result);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertEquals('message', $decoded['type']);
        $this->assertEquals('p2p', $decoded['typeMessage']);
        $this->assertFalse($decoded['inquiry']);
        $this->assertEquals($hash, $decoded['hash']);
        $this->assertEquals($status, $decoded['status']);
        $this->assertStringContainsString('P2P status for hash', $decoded['message']);
        $this->assertStringContainsString($hash, $decoded['message']);
        $this->assertStringContainsString($status, $decoded['message']);
        $this->assertEquals($resolvedAddress, $decoded['senderAddress']);
        $this->assertEquals($publicKey, $decoded['senderPublicKey']);
    }

    /**
     * Test buildP2pStatusResponse with expired status
     */
    public function testBuildP2pStatusResponseWithExpiredStatus(): void
    {
        $address = 'http://requester.example.com';
        $hash = 'p2p-hash-expired';
        $status = Constants::STATUS_EXPIRED;

        $this->transportUtility->expects($this->once())
            ->method('resolveUserAddressForTransport')
            ->willReturn('http://myaddress.example.com');

        $this->userContext->expects($this->once())
            ->method('getPublicKey')
            ->willReturn('pubkey');

        $result = $this->messagePayload->buildP2pStatusResponse($address, $hash, $status);

        $decoded = json_decode($result, true);
        $this->assertEquals(Constants::STATUS_EXPIRED, $decoded['status']);
        $this->assertStringContainsString(Constants::STATUS_EXPIRED, $decoded['message']);
    }

    // =========================================================================
    // build() Tests (BasePayload required method)
    // =========================================================================

    /**
     * Test build method returns empty array (default implementation)
     */
    public function testBuildReturnsEmptyArray(): void
    {
        $result = $this->messagePayload->build([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test build method with data still returns empty array
     */
    public function testBuildWithDataReturnsEmptyArray(): void
    {
        $result = $this->messagePayload->build(['key' => 'value']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
