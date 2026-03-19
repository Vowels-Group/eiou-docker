<?php
/**
 * Unit Tests for Available Credit on Contact Acceptance
 *
 * Tests that available credit is correctly calculated and exchanged
 * when contacts are accepted, rather than defaulting to 0.
 *
 * Covers the following scenarios:
 * - acceptContact() stores actual available credit (not 0)
 * - buildContactIsAccepted() includes credit data in the payload
 * - buildContactAcceptanceAcknowledgment() includes credit data in the ack
 * - buildMutuallyAccepted() includes credit data in the inline response
 * - processContactMessage() saves credit from acceptance messages
 * - Wallet restore auto-accept calculates real credit
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\ContactManagementService;
use Eiou\Services\MessageService;
use Eiou\Services\MessageDeliveryService;
use Eiou\Schemas\Payloads\MessagePayload;
use Eiou\Schemas\Payloads\ContactPayload;
use Eiou\Database\ContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\P2pRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\TransactionContactRepository;
use Eiou\Database\RepositoryFactory;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\ValidationUtilityService;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Utils\InputValidator;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;

#[CoversClass(MessagePayload::class)]
#[CoversClass(ContactPayload::class)]
#[CoversClass(ContactManagementService::class)]
#[CoversClass(MessageService::class)]
class AvailableCreditOnAcceptanceTest extends TestCase
{
    // =========================================================================
    // SHARED CONSTANTS
    // =========================================================================

    private const TEST_PUBKEY = 'test-contact-public-key-xyz789';
    private const TEST_USER_PUBKEY = 'test-user-public-key-abc123';
    private const TEST_ADDRESS = 'http://192.168.1.100:8080';
    private const TEST_CURRENCY = 'USD';

    // =========================================================================
    // PAYLOAD TESTS: buildContactIsAccepted
    // =========================================================================

    /**
     * Test buildContactIsAccepted includes available credit when provided
     */
    public function testBuildContactIsAcceptedIncludesAvailableCredit(): void
    {
        $payload = $this->createMessagePayload();

        $creditByCurrency = ['USD' => 50000];
        $creditCalculatedAt = 17417499042270;

        $result = $payload->buildContactIsAccepted(
            self::TEST_ADDRESS,
            false,
            null,
            'USD',
            $creditByCurrency,
            $creditCalculatedAt
        );

        $this->assertIsArray($result);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $result['status']);
        $this->assertArrayHasKey('availableCreditByCurrency', $result);
        $this->assertEquals(['USD' => 50000], $result['availableCreditByCurrency']);
        $this->assertArrayHasKey('creditCalculatedAt', $result);
        $this->assertEquals(17417499042270, $result['creditCalculatedAt']);
        $this->assertEquals('USD', $result['currency']);
    }

    /**
     * Test buildContactIsAccepted omits credit fields when empty
     */
    public function testBuildContactIsAcceptedOmitsCreditWhenEmpty(): void
    {
        $payload = $this->createMessagePayload();

        $result = $payload->buildContactIsAccepted(
            self::TEST_ADDRESS,
            false,
            null,
            'USD'
        );

        $this->assertIsArray($result);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $result['status']);
        $this->assertArrayNotHasKey('availableCreditByCurrency', $result);
        $this->assertArrayNotHasKey('creditCalculatedAt', $result);
    }

    /**
     * Test buildContactIsAccepted with encode=true returns JSON with credit
     */
    public function testBuildContactIsAcceptedEncodedIncludesCredit(): void
    {
        $payload = $this->createMessagePayload();

        $creditByCurrency = ['USD' => 25000, 'EUR' => 30000];
        $creditCalculatedAt = 17417499042270;

        $result = $payload->buildContactIsAccepted(
            self::TEST_ADDRESS,
            true,
            'sig-123',
            'USD',
            $creditByCurrency,
            $creditCalculatedAt
        );

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertEquals(['USD' => 25000, 'EUR' => 30000], $decoded['availableCreditByCurrency']);
        $this->assertEquals(17417499042270, $decoded['creditCalculatedAt']);
        $this->assertEquals('sig-123', $decoded['recipientSignature']);
    }

    /**
     * Test buildContactIsAccepted preserves existing fields when credit is added
     */
    public function testBuildContactIsAcceptedPreservesExistingFields(): void
    {
        $payload = $this->createMessagePayload();

        $result = $payload->buildContactIsAccepted(
            self::TEST_ADDRESS,
            false,
            'recipient-sig',
            'EUR',
            ['EUR' => 10000],
            12345
        );

        $this->assertIsArray($result);
        $this->assertEquals('message', $result['type']);
        $this->assertEquals('contact', $result['typeMessage']);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $result['status']);
        $this->assertEquals('recipient-sig', $result['recipientSignature']);
        $this->assertEquals('EUR', $result['currency']);
        $this->assertArrayHasKey('senderAddress', $result);
        $this->assertArrayHasKey('senderPublicKey', $result);
        $this->assertArrayHasKey('availableCreditByCurrency', $result);
    }

    // =========================================================================
    // PAYLOAD TESTS: buildContactAcceptanceAcknowledgment
    // =========================================================================

    /**
     * Test buildContactAcceptanceAcknowledgment includes credit data
     */
    public function testBuildContactAcceptanceAcknowledgmentIncludesCredit(): void
    {
        $payload = $this->createMessagePayload();

        $result = $payload->buildContactAcceptanceAcknowledgment(
            self::TEST_ADDRESS,
            ['USD' => 75000],
            17417499042270
        );

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $decoded['status']);
        $this->assertArrayHasKey('availableCreditByCurrency', $decoded);
        $this->assertEquals(['USD' => 75000], $decoded['availableCreditByCurrency']);
        $this->assertEquals(17417499042270, $decoded['creditCalculatedAt']);
    }

    /**
     * Test buildContactAcceptanceAcknowledgment omits credit when empty
     */
    public function testBuildContactAcceptanceAcknowledgmentOmitsCreditWhenEmpty(): void
    {
        $payload = $this->createMessagePayload();

        $result = $payload->buildContactAcceptanceAcknowledgment(self::TEST_ADDRESS);

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $decoded['status']);
        $this->assertArrayNotHasKey('availableCreditByCurrency', $decoded);
        $this->assertArrayNotHasKey('creditCalculatedAt', $decoded);
    }

    // =========================================================================
    // PAYLOAD TESTS: buildMutuallyAccepted
    // =========================================================================

    /**
     * Test buildMutuallyAccepted includes credit data
     */
    public function testBuildMutuallyAcceptedIncludesCredit(): void
    {
        $payload = $this->createContactPayload();

        $result = $payload->buildMutuallyAccepted(
            self::TEST_ADDRESS,
            ['http' => 'http://my.node'],
            'txid-123',
            'sig-456',
            ['USD' => 100000],
            17417499042270
        );

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $decoded['status']);
        $this->assertArrayHasKey('availableCreditByCurrency', $decoded);
        $this->assertEquals(['USD' => 100000], $decoded['availableCreditByCurrency']);
        $this->assertEquals(17417499042270, $decoded['creditCalculatedAt']);
        $this->assertEquals('txid-123', $decoded['txid']);
        $this->assertEquals('sig-456', $decoded['recipientSignature']);
    }

    /**
     * Test buildMutuallyAccepted omits credit when empty
     */
    public function testBuildMutuallyAcceptedOmitsCreditWhenEmpty(): void
    {
        $payload = $this->createContactPayload();

        $result = $payload->buildMutuallyAccepted(
            self::TEST_ADDRESS,
            null,
            null,
            null
        );

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertArrayNotHasKey('availableCreditByCurrency', $decoded);
        $this->assertArrayNotHasKey('creditCalculatedAt', $decoded);
    }

    // =========================================================================
    // SERVICE TESTS: ContactManagementService::acceptContact
    // =========================================================================

    /**
     * Test acceptContact stores credit_limit as available credit for new contact
     */
    public function testAcceptContactStoresCreditLimitForNewContact(): void
    {
        [$service, $mocks] = $this->createContactManagementService();

        $pubkey = self::TEST_PUBKEY;
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);
        $creditLimit = 50000; // 500.00 USD in cents

        // Contact acceptance succeeds
        $mocks['contactRepo']->method('acceptContact')->willReturn(true);
        $mocks['balanceRepo']->method('insertInitialContactBalances')->willReturn(true);

        // No prior transactions — balances are 0
        $mocks['balanceRepo']->method('getContactSentBalance')->willReturn(0);
        $mocks['balanceRepo']->method('getContactReceivedBalance')->willReturn(0);

        // Credit limit is set
        $mocks['contactCurrencyRepo']->method('getCreditLimit')
            ->with($pubkeyHash, 'USD')
            ->willReturn($creditLimit);

        $mocks['contactCurrencyRepo']->method('hasCurrency')->willReturn(false);
        $mocks['contactCurrencyRepo']->method('insertCurrencyConfig')->willReturn(true);
        $mocks['syncTrigger']->method('syncContactBalance')->willReturn(['success' => true, 'currencies' => []]);

        // Key assertion: available credit should equal credit_limit (not 0)
        $mocks['contactCreditRepo']->expects($this->once())
            ->method('upsertAvailableCredit')
            ->with($pubkeyHash, $creditLimit, 'USD')
            ->willReturn(true);

        $result = $service->acceptContact($pubkey, 'Alice', 0.0, (float) $creditLimit, 'USD');
        $this->assertTrue($result);
    }

    /**
     * Test acceptContact calculates real credit for re-added contact with prior transactions
     */
    public function testAcceptContactCalculatesRealCreditForReaddedContact(): void
    {
        [$service, $mocks] = $this->createContactManagementService();

        $pubkey = self::TEST_PUBKEY;
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);
        $creditLimit = 50000; // 500.00 USD
        $sentBalance = 20000;   // They sent us 200.00
        $receivedBalance = 10000; // We sent them 100.00

        // Expected: (sent - received) + creditLimit = (20000 - 10000) + 50000 = 60000
        $expectedAvailableCredit = 60000;

        $mocks['contactRepo']->method('acceptContact')->willReturn(true);
        $mocks['balanceRepo']->method('insertInitialContactBalances')->willReturn(true);
        $mocks['balanceRepo']->method('getContactSentBalance')->willReturn($sentBalance);
        $mocks['balanceRepo']->method('getContactReceivedBalance')->willReturn($receivedBalance);
        $mocks['contactCurrencyRepo']->method('getCreditLimit')->willReturn($creditLimit);
        $mocks['contactCurrencyRepo']->method('hasCurrency')->willReturn(false);
        $mocks['contactCurrencyRepo']->method('insertCurrencyConfig')->willReturn(true);
        $mocks['syncTrigger']->method('syncContactBalance')->willReturn(['success' => true, 'currencies' => []]);

        $mocks['contactCreditRepo']->expects($this->once())
            ->method('upsertAvailableCredit')
            ->with($pubkeyHash, $expectedAvailableCredit, 'USD')
            ->willReturn(true);

        $result = $service->acceptContact($pubkey, 'Alice', 0.0, (float) $creditLimit, 'USD');
        $this->assertTrue($result);
    }

    /**
     * Test acceptContact with negative balance stores reduced credit
     */
    public function testAcceptContactWithNegativeBalanceStoresReducedCredit(): void
    {
        [$service, $mocks] = $this->createContactManagementService();

        $pubkey = self::TEST_PUBKEY;
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);
        $creditLimit = 50000;
        $sentBalance = 5000;    // They sent us 50.00
        $receivedBalance = 30000; // We sent them 300.00

        // Expected: (5000 - 30000) + 50000 = 25000
        $expectedAvailableCredit = 25000;

        $mocks['contactRepo']->method('acceptContact')->willReturn(true);
        $mocks['balanceRepo']->method('insertInitialContactBalances')->willReturn(true);
        $mocks['balanceRepo']->method('getContactSentBalance')->willReturn($sentBalance);
        $mocks['balanceRepo']->method('getContactReceivedBalance')->willReturn($receivedBalance);
        $mocks['contactCurrencyRepo']->method('getCreditLimit')->willReturn($creditLimit);
        $mocks['contactCurrencyRepo']->method('hasCurrency')->willReturn(false);
        $mocks['contactCurrencyRepo']->method('insertCurrencyConfig')->willReturn(true);
        $mocks['syncTrigger']->method('syncContactBalance')->willReturn(['success' => true, 'currencies' => []]);

        $mocks['contactCreditRepo']->expects($this->once())
            ->method('upsertAvailableCredit')
            ->with($pubkeyHash, $expectedAvailableCredit, 'USD')
            ->willReturn(true);

        $result = $service->acceptContact($pubkey, 'Alice', 0.0, (float) $creditLimit, 'USD');
        $this->assertTrue($result);
    }

    /**
     * Test acceptContact still succeeds even if credit calculation fails
     */
    public function testAcceptContactSucceedsEvenIfCreditCalculationFails(): void
    {
        [$service, $mocks] = $this->createContactManagementService();

        $mocks['contactRepo']->method('acceptContact')->willReturn(true);
        $mocks['balanceRepo']->method('insertInitialContactBalances')->willReturn(true);
        $mocks['contactCurrencyRepo']->method('hasCurrency')->willReturn(false);
        $mocks['contactCurrencyRepo']->method('insertCurrencyConfig')->willReturn(true);
        $mocks['syncTrigger']->method('syncContactBalance')->willReturn(['success' => true, 'currencies' => []]);

        // Balance query throws exception
        $mocks['balanceRepo']->method('getContactSentBalance')
            ->willThrowException(new \RuntimeException('DB error'));

        // acceptContact should still return true (credit failure is non-fatal)
        $result = $service->acceptContact(self::TEST_PUBKEY, 'Alice', 0.0, 50000.0, 'USD');
        $this->assertTrue($result);
    }

    // =========================================================================
    // SERVICE TESTS: MessageService processes credit from acceptance
    // =========================================================================

    /**
     * Test handleMessageRequest saves credit from acceptance message
     */
    public function testHandleMessageRequestSavesCreditFromAcceptance(): void
    {
        [$service, $mocks] = $this->createMessageService();

        $senderPubkey = self::TEST_PUBKEY;
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $senderPubkey);

        // Contact must exist for validity check
        $mocks['contactRepo']->method('contactExistsPubkey')->willReturn(true);
        $mocks['contactRepo']->method('updateStatus')->willReturn(true);
        $mocks['transactionContactRepo']->method('completeContactTransaction')->willReturn(true);
        $mocks['transactionContactRepo']->method('getContactTransactionByParties')->willReturn(null);
        $mocks['contactCurrencyRepo']->method('updateCurrencyStatus')->willReturn(true);

        // Set up balance/credit calculations for the ack response
        $mocks['balanceRepo']->method('getContactSentBalance')->willReturn(0);
        $mocks['balanceRepo']->method('getContactReceivedBalance')->willReturn(0);
        $mocks['contactCurrencyRepo']->method('getCreditLimit')->willReturn(0);
        $mocks['timeUtility']->method('getCurrentMicrotime')->willReturn(99999);

        // Key assertion: credit from the acceptance message should be saved
        $mocks['contactCreditRepo']->expects($this->atLeastOnce())
            ->method('upsertAvailableCreditIfNewer')
            ->with(
                $pubkeyHash,
                50000,
                'USD',
                17417499042270
            )
            ->willReturn(true);

        $request = [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => Constants::STATUS_ACCEPTED,
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => $senderPubkey,
            'currency' => 'USD',
            'availableCreditByCurrency' => ['USD' => 50000],
            'creditCalculatedAt' => 17417499042270,
        ];

        ob_start();
        $service->handleMessageRequest($request);
        $output = ob_get_clean();

        $ackDecoded = json_decode($output, true);
        $this->assertNotNull($ackDecoded);
        $this->assertEquals(Constants::STATUS_ACCEPTED, $ackDecoded['status']);
    }

    /**
     * Test handleMessageRequest includes our credit in the ack response
     */
    public function testHandleMessageRequestIncludesCreditInAck(): void
    {
        [$service, $mocks] = $this->createMessageService();

        $senderPubkey = self::TEST_PUBKEY;
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $senderPubkey);

        $mocks['contactRepo']->method('contactExistsPubkey')->willReturn(true);
        $mocks['contactRepo']->method('updateStatus')->willReturn(true);
        $mocks['transactionContactRepo']->method('completeContactTransaction')->willReturn(true);
        $mocks['transactionContactRepo']->method('getContactTransactionByParties')->willReturn(null);
        $mocks['contactCurrencyRepo']->method('updateCurrencyStatus')->willReturn(true);
        $mocks['contactCreditRepo']->method('upsertAvailableCreditIfNewer')->willReturn(true);

        // Our balance with the contact: we sent 10000, received 5000
        $mocks['balanceRepo']->method('getContactSentBalance')->willReturn(10000);
        $mocks['balanceRepo']->method('getContactReceivedBalance')->willReturn(5000);
        $mocks['contactCurrencyRepo']->method('getCreditLimit')
            ->with($pubkeyHash, 'USD')
            ->willReturn(30000);

        // Expected credit in ack: (10000 - 5000) + 30000 = 35000
        $mocks['timeUtility']->method('getCurrentMicrotime')->willReturn(99999);

        $request = [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => Constants::STATUS_ACCEPTED,
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => $senderPubkey,
            'currency' => 'USD',
            'availableCreditByCurrency' => ['USD' => 50000],
            'creditCalculatedAt' => 17417499042270,
        ];

        ob_start();
        $service->handleMessageRequest($request);
        $output = ob_get_clean();

        $ackDecoded = json_decode($output, true);
        $this->assertNotNull($ackDecoded);
        $this->assertArrayHasKey('availableCreditByCurrency', $ackDecoded);
        $this->assertEquals(35000, $ackDecoded['availableCreditByCurrency']['USD']);
        $this->assertEquals(99999, $ackDecoded['creditCalculatedAt']);
    }

    /**
     * Test handleMessageRequest handles acceptance without credit data gracefully
     */
    public function testHandleMessageRequestHandlesAcceptanceWithoutCredit(): void
    {
        [$service, $mocks] = $this->createMessageService();

        $mocks['contactRepo']->method('contactExistsPubkey')->willReturn(true);
        $mocks['contactRepo']->method('updateStatus')->willReturn(true);
        $mocks['transactionContactRepo']->method('completeContactTransaction')->willReturn(true);
        $mocks['transactionContactRepo']->method('getContactTransactionByParties')->willReturn(null);
        $mocks['balanceRepo']->method('getContactSentBalance')->willReturn(0);
        $mocks['balanceRepo']->method('getContactReceivedBalance')->willReturn(0);
        $mocks['contactCurrencyRepo']->method('getCreditLimit')->willReturn(0);
        $mocks['timeUtility']->method('getCurrentMicrotime')->willReturn(99999);

        // No credit data in message — should not call upsertAvailableCreditIfNewer
        $mocks['contactCreditRepo']->expects($this->never())
            ->method('upsertAvailableCreditIfNewer');

        $request = [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => Constants::STATUS_ACCEPTED,
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => self::TEST_PUBKEY,
            'currency' => 'USD',
            // No availableCreditByCurrency or creditCalculatedAt
        ];

        ob_start();
        $service->handleMessageRequest($request);
        ob_get_clean();
    }

    /**
     * Test handleMessageRequest saves credit without timestamp via upsertAvailableCredit
     */
    public function testHandleMessageRequestSavesCreditWithoutTimestamp(): void
    {
        [$service, $mocks] = $this->createMessageService();

        $senderPubkey = self::TEST_PUBKEY;
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $senderPubkey);

        $mocks['contactRepo']->method('contactExistsPubkey')->willReturn(true);
        $mocks['contactRepo']->method('updateStatus')->willReturn(true);
        $mocks['transactionContactRepo']->method('completeContactTransaction')->willReturn(true);
        $mocks['transactionContactRepo']->method('getContactTransactionByParties')->willReturn(null);
        $mocks['balanceRepo']->method('getContactSentBalance')->willReturn(0);
        $mocks['balanceRepo']->method('getContactReceivedBalance')->willReturn(0);
        $mocks['contactCurrencyRepo']->method('getCreditLimit')->willReturn(0);
        $mocks['timeUtility']->method('getCurrentMicrotime')->willReturn(99999);

        // When no timestamp, should use upsertAvailableCredit (not IfNewer)
        $mocks['contactCreditRepo']->expects($this->once())
            ->method('upsertAvailableCredit')
            ->with($pubkeyHash, 50000, 'USD')
            ->willReturn(true);

        $request = [
            'type' => 'message',
            'typeMessage' => 'contact',
            'status' => Constants::STATUS_ACCEPTED,
            'senderAddress' => self::TEST_ADDRESS,
            'senderPublicKey' => $senderPubkey,
            'currency' => 'USD',
            'availableCreditByCurrency' => ['USD' => 50000],
            // No creditCalculatedAt
        ];

        ob_start();
        $service->handleMessageRequest($request);
        ob_get_clean();
    }

    // =========================================================================
    // CREDIT FORMULA TESTS
    // =========================================================================

    /**
     * Test credit formula: (sent - received) + creditLimit = creditLimit when no txns
     */
    public function testCreditFormulaNewContactEqualsCreditLimit(): void
    {
        [$service, $mocks] = $this->createContactManagementService();

        $creditLimit = 100000;

        $mocks['contactRepo']->method('acceptContact')->willReturn(true);
        $mocks['balanceRepo']->method('insertInitialContactBalances')->willReturn(true);
        $mocks['balanceRepo']->method('getContactSentBalance')->willReturn(0);
        $mocks['balanceRepo']->method('getContactReceivedBalance')->willReturn(0);
        $mocks['contactCurrencyRepo']->method('getCreditLimit')->willReturn($creditLimit);
        $mocks['contactCurrencyRepo']->method('hasCurrency')->willReturn(false);
        $mocks['contactCurrencyRepo']->method('insertCurrencyConfig')->willReturn(true);
        $mocks['syncTrigger']->method('syncContactBalance')->willReturn(['success' => true, 'currencies' => []]);

        // For new contact: available = 0 - 0 + 100000 = 100000
        $mocks['contactCreditRepo']->expects($this->once())
            ->method('upsertAvailableCredit')
            ->with(
                $this->anything(),
                $creditLimit,
                'USD'
            );

        $service->acceptContact(self::TEST_PUBKEY, 'Bob', 0.0, (float) $creditLimit, 'USD');
    }

    /**
     * Test credit formula with fully utilized credit
     */
    public function testCreditFormulaFullyUtilizedCredit(): void
    {
        [$service, $mocks] = $this->createContactManagementService();

        $creditLimit = 50000;
        // They sent us 0, we sent them 50000 (credit fully used)
        // available = (0 - 50000) + 50000 = 0

        $mocks['contactRepo']->method('acceptContact')->willReturn(true);
        $mocks['balanceRepo']->method('insertInitialContactBalances')->willReturn(true);
        $mocks['balanceRepo']->method('getContactSentBalance')->willReturn(0);
        $mocks['balanceRepo']->method('getContactReceivedBalance')->willReturn(50000);
        $mocks['contactCurrencyRepo']->method('getCreditLimit')->willReturn($creditLimit);
        $mocks['contactCurrencyRepo']->method('hasCurrency')->willReturn(false);
        $mocks['contactCurrencyRepo']->method('insertCurrencyConfig')->willReturn(true);
        $mocks['syncTrigger']->method('syncContactBalance')->willReturn(['success' => true, 'currencies' => []]);

        $mocks['contactCreditRepo']->expects($this->once())
            ->method('upsertAvailableCredit')
            ->with(
                $this->anything(),
                0,
                'USD'
            );

        $service->acceptContact(self::TEST_PUBKEY, 'Bob', 0.0, (float) $creditLimit, 'USD');
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Create a MessagePayload with mocked dependencies
     */
    private function createMessagePayload(): MessagePayload
    {
        $userContext = $this->createMock(UserContext::class);
        $utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $transportUtility = $this->createMock(TransportUtilityService::class);

        $userContext->method('getPublicKey')->willReturn(self::TEST_USER_PUBKEY);
        $transportUtility->method('resolveUserAddressForTransport')->willReturnArgument(0);

        $utilityContainer->method('getTransportUtility')->willReturn($transportUtility);
        $utilityContainer->method('getCurrencyUtility')->willReturn($this->createMock(CurrencyUtilityService::class));
        $utilityContainer->method('getTimeUtility')->willReturn($this->createMock(TimeUtilityService::class));
        $utilityContainer->method('getValidationUtility')->willReturn($this->createMock(ValidationUtilityService::class));

        return new MessagePayload($userContext, $utilityContainer);
    }

    /**
     * Create a ContactPayload with mocked dependencies
     */
    private function createContactPayload(): ContactPayload
    {
        $userContext = $this->createMock(UserContext::class);
        $utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $transportUtility = $this->createMock(TransportUtilityService::class);

        $userContext->method('getPublicKey')->willReturn(self::TEST_USER_PUBKEY);
        $transportUtility->method('resolveUserAddressForTransport')->willReturnArgument(0);

        $utilityContainer->method('getTransportUtility')->willReturn($transportUtility);
        $utilityContainer->method('getCurrencyUtility')->willReturn($this->createMock(CurrencyUtilityService::class));
        $utilityContainer->method('getTimeUtility')->willReturn($this->createMock(TimeUtilityService::class));
        $utilityContainer->method('getValidationUtility')->willReturn($this->createMock(ValidationUtilityService::class));

        return new ContactPayload($userContext, $utilityContainer);
    }

    /**
     * Create a ContactManagementService with all mocked dependencies
     *
     * @return array{0: ContactManagementService, 1: array<string, MockObject>}
     */
    private function createContactManagementService(): array
    {
        $contactRepo = $this->createMock(ContactRepository::class);
        $addressRepo = $this->createMock(AddressRepository::class);
        $balanceRepo = $this->createMock(BalanceRepository::class);
        $utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $transportUtility = $this->createMock(TransportUtilityService::class);
        $currentUser = $this->createMock(UserContext::class);
        $syncTrigger = $this->createMock(SyncTriggerInterface::class);
        $contactCreditRepo = $this->createMock(ContactCreditRepository::class);
        $contactCurrencyRepo = $this->createMock(ContactCurrencyRepository::class);

        $utilityContainer->method('getTransportUtility')->willReturn($transportUtility);

        $repoFactory = $this->createMock(RepositoryFactory::class);
        $repoFactory->method('get')
            ->willReturnCallback(function (string $class) use ($contactCreditRepo, $contactCurrencyRepo) {
                if ($class === ContactCreditRepository::class) return $contactCreditRepo;
                if ($class === ContactCurrencyRepository::class) return $contactCurrencyRepo;
                return null;
            });

        $service = new ContactManagementService(
            $contactRepo,
            $addressRepo,
            $balanceRepo,
            $utilityContainer,
            new InputValidator(),
            $currentUser,
            $repoFactory,
            $syncTrigger
        );

        return [$service, [
            'contactRepo' => $contactRepo,
            'addressRepo' => $addressRepo,
            'balanceRepo' => $balanceRepo,
            'contactCreditRepo' => $contactCreditRepo,
            'contactCurrencyRepo' => $contactCurrencyRepo,
            'syncTrigger' => $syncTrigger,
            'currentUser' => $currentUser,
        ]];
    }

    /**
     * Create a MessageService with all mocked dependencies
     *
     * @return array{0: MessageService, 1: array<string, MockObject>}
     */
    private function createMessageService(): array
    {
        $contactRepo = $this->createMock(ContactRepository::class);
        $balanceRepo = $this->createMock(BalanceRepository::class);
        $p2pRepo = $this->createMock(P2pRepository::class);
        $transactionRepo = $this->createMock(TransactionRepository::class);
        $transactionContactRepo = $this->createMock(TransactionContactRepository::class);
        $utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $transportUtility = $this->createMock(TransportUtilityService::class);
        $timeUtility = $this->createMock(TimeUtilityService::class);
        $userContext = $this->createMock(UserContext::class);
        $messageDeliveryService = $this->createMock(MessageDeliveryService::class);
        $contactCreditRepo = $this->createMock(ContactCreditRepository::class);
        $contactCurrencyRepo = $this->createMock(ContactCurrencyRepository::class);

        $utilityContainer->method('getTransportUtility')->willReturn($transportUtility);
        $utilityContainer->method('getTimeUtility')->willReturn($timeUtility);
        $utilityContainer->method('getCurrencyUtility')->willReturn($this->createMock(CurrencyUtilityService::class));
        $utilityContainer->method('getValidationUtility')->willReturn($this->createMock(ValidationUtilityService::class));

        $transportUtility->method('resolveUserAddressForTransport')->willReturnArgument(0);
        $userContext->method('getPublicKey')->willReturn(self::TEST_USER_PUBKEY);

        $repoFactory = $this->createMock(RepositoryFactory::class);
        $repoFactory->method('get')
            ->willReturnCallback(function (string $class) use ($contactCreditRepo, $contactCurrencyRepo) {
                if ($class === ContactCreditRepository::class) return $contactCreditRepo;
                if ($class === ContactCurrencyRepository::class) return $contactCurrencyRepo;
                return null;
            });

        $service = new MessageService(
            $contactRepo,
            $balanceRepo,
            $p2pRepo,
            $transactionRepo,
            $transactionContactRepo,
            $utilityContainer,
            $userContext,
            $messageDeliveryService,
            null, // syncTrigger
            $repoFactory
        );

        return [$service, [
            'contactRepo' => $contactRepo,
            'balanceRepo' => $balanceRepo,
            'transactionRepo' => $transactionRepo,
            'transactionContactRepo' => $transactionContactRepo,
            'contactCreditRepo' => $contactCreditRepo,
            'contactCurrencyRepo' => $contactCurrencyRepo,
            'timeUtility' => $timeUtility,
            'userContext' => $userContext,
        ]];
    }
}
