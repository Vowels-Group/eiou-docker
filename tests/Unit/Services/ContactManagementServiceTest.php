<?php
/**
 * Unit Tests for ContactManagementService
 *
 * Tests contact disambiguation and update validation.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\ContactManagementService;
use Eiou\Cli\CliOutputManager;
use Eiou\Database\ContactRepository;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Services\Utilities\UtilityServiceContainer;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Utils\InputValidator;
use Eiou\Core\UserContext;
use Eiou\Core\ErrorCodes;
use Eiou\Core\Constants;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\RepositoryFactory;
use Eiou\Contracts\SyncTriggerInterface;

#[CoversClass(ContactManagementService::class)]
class ContactManagementServiceTest extends TestCase
{
    private ContactRepository $contactRepo;
    private AddressRepository $addressRepo;
    private BalanceRepository $balanceRepo;
    private UtilityServiceContainer $utilityContainer;
    private TransportUtilityService $transportUtility;
    private InputValidator $inputValidator;
    private UserContext $currentUser;
    private ContactCreditRepository $contactCreditRepo;
    private ContactCurrencyRepository $contactCurrencyRepo;
    private RepositoryFactory $repositoryFactory;
    private SyncTriggerInterface $syncTrigger;
    private ContactManagementService $service;

    protected function setUp(): void
    {
        $this->contactRepo = $this->createMock(ContactRepository::class);
        $this->addressRepo = $this->createMock(AddressRepository::class);
        $this->balanceRepo = $this->createMock(BalanceRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->inputValidator = new InputValidator();
        $this->currentUser = $this->createMock(UserContext::class);
        $this->repositoryFactory = $this->createMock(RepositoryFactory::class);
        $this->syncTrigger = $this->createMock(SyncTriggerInterface::class);

        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);

        $this->contactCreditRepo = $this->createMock(ContactCreditRepository::class);
        $this->contactCurrencyRepo = $this->createMock(ContactCurrencyRepository::class);

        $this->repositoryFactory->method('get')
            ->willReturnCallback(function (string $class) {
                if ($class === ContactCreditRepository::class) {
                    return $this->contactCreditRepo;
                }
                if ($class === ContactCurrencyRepository::class) {
                    return $this->contactCurrencyRepo;
                }
                return $this->createMock($class);
            });

        $this->service = new ContactManagementService(
            $this->contactRepo,
            $this->addressRepo,
            $this->balanceRepo,
            $this->utilityContainer,
            $this->inputValidator,
            $this->currentUser,
            $this->repositoryFactory,
            $this->syncTrigger
        );
    }

    // =========================================================================
    // lookupContactInfoWithDisambiguation() Tests
    // =========================================================================

    /**
     * Test disambiguation returns null and outputs error in JSON mode when multiple matches
     */
    public function testDisambiguationReturnsErrorInJsonModeWithMultipleMatches(): void
    {
        $matches = [
            ['pubkey' => 'key1', 'name' => 'John', 'status' => 'accepted', 'http' => 'http://node1'],
            ['pubkey' => 'key2', 'name' => 'John', 'status' => 'accepted', 'http' => 'http://node2'],
        ];

        $this->contactRepo->method('lookupAllByName')
            ->with('John')
            ->willReturn($matches);

        $this->addressRepo->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $output = $this->createMock(CliOutputManager::class);
        $output->method('isJsonMode')->willReturn(true);

        $output->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Multiple contacts found'),
                ErrorCodes::MULTIPLE_MATCHES,
                409,
                $this->callback(function ($data) {
                    return isset($data['multiple_matches'])
                        && isset($data['count'])
                        && $data['count'] === 2;
                })
            );

        $result = $this->service->lookupContactInfoWithDisambiguation('John', $output);

        $this->assertNull($result);
    }

    /**
     * Test disambiguation with single match returns contact info normally
     */
    public function testDisambiguationWithSingleMatchReturnsContactInfo(): void
    {
        $match = [
            ['pubkey' => 'key1', 'name' => 'Alice', 'pubkey_hash' => 'hash1',
             'status' => 'accepted', 'http' => 'http://alice.example.com'],
        ];

        $this->contactRepo->method('lookupAllByName')
            ->with('Alice')
            ->willReturn($match);

        // lookupContactInfo will be called - mock lookupByName for the internal call
        $this->contactRepo->method('lookupByName')
            ->with('Alice')
            ->willReturn($match[0]);

        $this->addressRepo->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $output = $this->createMock(CliOutputManager::class);

        $result = $this->service->lookupContactInfoWithDisambiguation('Alice', $output);

        $this->assertNotNull($result);
        $this->assertEquals('Alice', $result['receiverName']);
        $this->assertEquals('key1', $result['receiverPublicKey']);
    }

    /**
     * Test disambiguation with no name matches falls through to address lookup
     */
    public function testDisambiguationWithNoNameMatchesFallsThrough(): void
    {
        $this->contactRepo->method('lookupAllByName')
            ->willReturn([]);

        $this->contactRepo->method('lookupByName')
            ->willReturn(null);

        $this->transportUtility->method('determineTransportType')
            ->with('http://unknown.example.com')
            ->willReturn('http');

        $this->contactRepo->method('lookupByAddress')
            ->willReturn(null);

        $this->addressRepo->method('getAllAddressTypes')
            ->willReturn(['http', 'https', 'tor']);

        $output = $this->createMock(CliOutputManager::class);

        $result = $this->service->lookupContactInfoWithDisambiguation('http://unknown.example.com', $output);

        $this->assertNull($result);
    }

    // =========================================================================
    // updateContact() Name Validation Tests
    // =========================================================================

    /**
     * Test updateContact rejects invalid name characters
     */
    public function testUpdateContactRejectsInvalidNameCharacters(): void
    {
        $contact = [
            'pubkey' => 'test-key',
            'name' => 'OldName',
            'status' => 'accepted',
        ];

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactRepo->method('lookupByAddress')
            ->willReturn($contact);

        $output = $this->createMock(CliOutputManager::class);
        $output->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Invalid name'),
                ErrorCodes::INVALID_NAME,
                400
            );

        // argv: [0]=eiou, [1]=update, [2]=address, [3]=name, [4]=value
        $argv = ['eiou', 'update', 'http://test.example.com', 'name', '!!invalid!!'];

        $this->service->updateContact($argv, $output);
    }

    /**
     * Test updateContact accepts valid name with spaces
     */
    public function testUpdateContactAcceptsValidNameWithSpaces(): void
    {
        $contact = [
            'pubkey' => 'test-key',
            'name' => 'OldName',
            'status' => 'accepted',
        ];

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactRepo->method('lookupByAddress')
            ->willReturn($contact);

        $this->contactRepo->method('updateContactFields')
            ->willReturn(true);

        $output = $this->createMock(CliOutputManager::class);
        $output->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('updated'),
                $this->callback(function ($data) {
                    return $data['name'] === 'John Doe';
                })
            );

        $argv = ['eiou', 'update', 'http://test.example.com', 'name', 'John Doe'];

        $this->service->updateContact($argv, $output);
    }

    /**
     * Test updateContact validates name in 'all' field mode
     */
    public function testUpdateContactValidatesNameInAllFieldMode(): void
    {
        $contact = [
            'pubkey' => 'test-key',
            'name' => 'OldName',
            'status' => 'accepted',
        ];

        $this->transportUtility->method('determineTransportType')
            ->willReturn('http');

        $this->contactRepo->method('lookupByAddress')
            ->willReturn($contact);

        $output = $this->createMock(CliOutputManager::class);
        $output->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Invalid name'),
                ErrorCodes::INVALID_NAME,
                400
            );

        // argv: [0]=eiou, [1]=update, [2]=address, [3]=all, [4]=name, [5]=fee, [6]=credit
        $argv = ['eiou', 'update', 'http://test.example.com', 'all', '<script>alert(1)</script>', '0.5', '100'];

        $this->service->updateContact($argv, $output);
    }

    // =========================================================================
    // acceptContact() Currency Tests
    // =========================================================================

    /**
     * Test acceptContact creates a contact currency row via upsertCurrencyConfig
     */
    public function testAcceptContactCreatesContactCurrencyRow(): void
    {
        $pubkey = 'test-pubkey-123';
        $name = 'TestContact';
        $fee = 1.0;
        $credit = 100.0;
        $currency = 'USD';

        $this->contactRepo->method('acceptContact')
            ->with($pubkey, $name, $fee, $credit, $currency)
            ->willReturn(true);

        $this->balanceRepo->method('insertInitialContactBalances')
            ->with($pubkey, $currency);

        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);

        $this->contactCurrencyRepo->expects($this->once())
            ->method('upsertCurrencyConfig')
            ->with($pubkeyHash, $currency, (int) $fee, (int) $credit)
            ->willReturn(true);

        $result = $this->service->acceptContact($pubkey, $name, $fee, $credit, $currency);

        $this->assertTrue($result);
    }

    // =========================================================================
    // getCreditLimit() Tests
    // =========================================================================

    /**
     * Test getCreditLimit passes currency parameter to the repository
     */
    public function testGetCreditLimitPassesCurrencyToRepository(): void
    {
        $this->contactRepo->expects($this->once())
            ->method('getCreditLimit')
            ->with('pubkey', 'EUR')
            ->willReturn(500.0);

        $result = $this->service->getCreditLimit('pubkey', 'EUR');

        $this->assertEquals(500.0, $result);
    }

    // =========================================================================
    // addCurrencyToContact() Tests
    // =========================================================================

    /**
     * Test addCurrencyToContact succeeds for an accepted contact with a new currency
     */
    public function testAddCurrencyToContactSuccess(): void
    {
        $pubkey = 'accepted-pubkey';
        $currency = 'EUR';
        $fee = 2.0;
        $credit = 200.0;
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);

        $this->contactRepo->method('isAcceptedContactPubkey')
            ->with($pubkey)
            ->willReturn(true);

        $this->contactCurrencyRepo->method('hasCurrency')
            ->with($pubkeyHash, $currency)
            ->willReturn(false);

        $this->contactCurrencyRepo->expects($this->once())
            ->method('insertCurrencyConfig')
            ->with($pubkeyHash, $currency, (int) $fee, (int) $credit);

        $this->balanceRepo->expects($this->once())
            ->method('insertInitialContactBalances')
            ->with($pubkey, $currency);

        $this->contactCreditRepo->expects($this->once())
            ->method('createInitialCredit')
            ->with($pubkey, $currency);

        $result = $this->service->addCurrencyToContact($pubkey, $currency, $fee, $credit);

        $this->assertTrue($result);
    }

    /**
     * Test addCurrencyToContact fails for a non-accepted contact
     */
    public function testAddCurrencyToContactFailsForNonAcceptedContact(): void
    {
        $this->contactRepo->method('isAcceptedContactPubkey')
            ->with('non-accepted-pubkey')
            ->willReturn(false);

        $result = $this->service->addCurrencyToContact('non-accepted-pubkey', 'EUR', 1.0, 100.0);

        $this->assertFalse($result);
    }

    /**
     * Test addCurrencyToContact fails when the currency already exists
     */
    public function testAddCurrencyToContactFailsForDuplicateCurrency(): void
    {
        $pubkey = 'accepted-pubkey';
        $currency = 'USD';
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);

        $this->contactRepo->method('isAcceptedContactPubkey')
            ->with($pubkey)
            ->willReturn(true);

        $this->contactCurrencyRepo->method('hasCurrency')
            ->with($pubkeyHash, $currency)
            ->willReturn(true);

        $result = $this->service->addCurrencyToContact($pubkey, $currency, 1.0, 100.0);

        $this->assertFalse($result);
    }

    // =========================================================================
    // acceptContact() with minFeeAmount Tests
    // =========================================================================

    /**
     * Test acceptContact passes minFeeAmount to upsertCurrencyConfig
     */
    public function testAcceptContactPassesMinFeeAmount(): void
    {
        $pubkey = 'test-pubkey-minfee';
        $name = 'MinFeeContact';
        $fee = 1.0;
        $credit = 100.0;
        $currency = 'USD';
        $minFeeAmount = 500; // 500 cents = $5.00

        $this->contactRepo->method('acceptContact')
            ->with($pubkey, $name, $fee, $credit, $currency)
            ->willReturn(true);

        $this->balanceRepo->method('insertInitialContactBalances')
            ->with($pubkey, $currency);

        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);

        $this->contactCurrencyRepo->expects($this->once())
            ->method('upsertCurrencyConfig')
            ->with($pubkeyHash, $currency, (int) $fee, (int) $credit, $this->anything(), $minFeeAmount)
            ->willReturn(true);

        $result = $this->service->acceptContact($pubkey, $name, $fee, $credit, $currency, $minFeeAmount);

        $this->assertTrue($result);
    }

    /**
     * Test acceptContact with null minFeeAmount passes null
     */
    public function testAcceptContactWithNullMinFeeAmount(): void
    {
        $pubkey = 'test-pubkey-nominfee';
        $name = 'NoMinFeeContact';
        $fee = 2.0;
        $credit = 200.0;
        $currency = 'EUR';

        $this->contactRepo->method('acceptContact')
            ->with($pubkey, $name, $fee, $credit, $currency)
            ->willReturn(true);

        $this->balanceRepo->method('insertInitialContactBalances')
            ->with($pubkey, $currency);

        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);

        $this->contactCurrencyRepo->expects($this->once())
            ->method('upsertCurrencyConfig')
            ->with($pubkeyHash, $currency, (int) $fee, (int) $credit, $this->anything(), null)
            ->willReturn(true);

        $result = $this->service->acceptContact($pubkey, $name, $fee, $credit, $currency);

        $this->assertTrue($result);
    }

    // =========================================================================
    // addCurrencyToContact() with minFeeAmount Tests
    // =========================================================================

    /**
     * Test addCurrencyToContact passes minFeeAmount to insertCurrencyConfig
     */
    public function testAddCurrencyToContactWithMinFeeAmount(): void
    {
        $pubkey = 'accepted-pubkey-minfee';
        $currency = 'GBP';
        $fee = 1.5;
        $credit = 150.0;
        $minFeeAmount = 200; // 200 pence = £2.00
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);

        $this->contactRepo->method('isAcceptedContactPubkey')
            ->with($pubkey)
            ->willReturn(true);

        $this->contactCurrencyRepo->method('hasCurrency')
            ->with($pubkeyHash, $currency)
            ->willReturn(false);

        $this->contactCurrencyRepo->expects($this->once())
            ->method('insertCurrencyConfig')
            ->with($pubkeyHash, $currency, (int) $fee, (int) $credit, $this->anything(), $this->anything(), $minFeeAmount);

        $this->balanceRepo->expects($this->once())
            ->method('insertInitialContactBalances')
            ->with($pubkey, $currency);

        $this->contactCreditRepo->expects($this->once())
            ->method('createInitialCredit')
            ->with($pubkey, $currency);

        $result = $this->service->addCurrencyToContact($pubkey, $currency, $fee, $credit, $minFeeAmount);

        $this->assertTrue($result);
    }

    /**
     * Test addCurrencyToContact defaults minFeeAmount to null
     */
    public function testAddCurrencyToContactDefaultsMinFeeAmountToNull(): void
    {
        $pubkey = 'accepted-pubkey-default';
        $currency = 'JPY';
        $fee = 3.0;
        $credit = 500.0;
        $pubkeyHash = hash(Constants::HASH_ALGORITHM, $pubkey);

        $this->contactRepo->method('isAcceptedContactPubkey')
            ->with($pubkey)
            ->willReturn(true);

        $this->contactCurrencyRepo->method('hasCurrency')
            ->with($pubkeyHash, $currency)
            ->willReturn(false);

        $this->contactCurrencyRepo->expects($this->once())
            ->method('insertCurrencyConfig')
            ->with($pubkeyHash, $currency, (int) $fee, (int) $credit, $this->anything(), $this->anything(), null);

        $this->balanceRepo->method('insertInitialContactBalances');
        $this->contactCreditRepo->method('createInitialCredit');

        $result = $this->service->addCurrencyToContact($pubkey, $currency, $fee, $credit);

        $this->assertTrue($result);
    }
}
