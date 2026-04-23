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
use Eiou\Events\ContactEvents;
use Eiou\Events\EventDispatcher;
use Eiou\Database\RepositoryFactory;
use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Contracts\ContactSyncServiceInterface;
use Eiou\Core\SplitAmount;

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
    private RepositoryFactory $repoFactory;
    private SyncTriggerInterface $syncTrigger;
    private ContactManagementService $service;

    protected function setUp(): void
    {
        // Clean dispatcher each test so CONTACT_* subscriptions don't leak
        // across tests and pollute each other's assertions.
        EventDispatcher::resetInstance();
        $this->contactRepo = $this->createMock(ContactRepository::class);
        $this->addressRepo = $this->createMock(AddressRepository::class);
        $this->balanceRepo = $this->createMock(BalanceRepository::class);
        $this->utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $this->transportUtility = $this->createMock(TransportUtilityService::class);
        $this->inputValidator = new InputValidator();
        $this->currentUser = $this->createMock(UserContext::class);
        $this->syncTrigger = $this->createMock(SyncTriggerInterface::class);

        $this->utilityContainer->method('getTransportUtility')
            ->willReturn($this->transportUtility);

        $this->contactCreditRepo = $this->createMock(ContactCreditRepository::class);
        $this->contactCurrencyRepo = $this->createMock(ContactCurrencyRepository::class);

        $this->repoFactory = $this->createMock(RepositoryFactory::class);
        $this->repoFactory->method('get')
            ->willReturnCallback(function (string $class) {
                if ($class === ContactCreditRepository::class) return $this->contactCreditRepo;
                if ($class === ContactCurrencyRepository::class) return $this->contactCurrencyRepo;
                return null;
            });

        $this->service = new ContactManagementService(
            $this->contactRepo,
            $this->addressRepo,
            $this->balanceRepo,
            $this->utilityContainer,
            $this->inputValidator,
            $this->currentUser,
            $this->repoFactory,
            $this->syncTrigger
        );
    }

    protected function tearDown(): void
    {
        EventDispatcher::resetInstance();
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
            ->with($pubkeyHash, $currency, (int) $fee, $this->isInstanceOf(\Eiou\Core\SplitAmount::class))
            ->willReturn(true);

        $result = $this->service->acceptContact($pubkey, $name, $fee, $credit, $currency);

        $this->assertTrue($result);
    }

    public function testAcceptContactDispatchesContactAcceptedEvent(): void
    {
        $this->contactRepo->method('acceptContact')->willReturn(true);

        $fired = null;
        EventDispatcher::getInstance()->subscribe(ContactEvents::CONTACT_ACCEPTED, function (array $data) use (&$fired) {
            $fired = $data;
        });

        $this->service->acceptContact('pk', 'Name', 1.0, 100.0, 'USD');

        $this->assertNotNull($fired, 'CONTACT_ACCEPTED should fire after acceptContact commits');
        $this->assertSame('pk', $fired['pubkey']);
        $this->assertSame('Name', $fired['name']);
        $this->assertSame('USD', $fired['currency']);
    }

    public function testAcceptContactDoesNotDispatchOnFailure(): void
    {
        $this->contactRepo->method('acceptContact')->willReturn(false);

        $fired = false;
        EventDispatcher::getInstance()->subscribe(ContactEvents::CONTACT_ACCEPTED, function () use (&$fired) {
            $fired = true;
        });

        $this->service->acceptContact('pk', 'Name', 1.0, 100.0, 'USD');

        $this->assertFalse($fired, 'CONTACT_ACCEPTED must not fire when the DB-side accept fails');
    }

    public function testBlockContactDispatchesContactBlockedWithResolvedPubkey(): void
    {
        // Happy path: isAddress → true, contactExists → true, blockContact → true.
        // Event must carry the pubkey from lookupByAddress, not the address
        // (address can change per-node; pubkey is the stable ID).
        $this->transportUtility->method('isAddress')->willReturn(true);
        $this->transportUtility->method('determineTransportType')->willReturn('http');
        $this->contactRepo->method('contactExists')->willReturn(true);
        $this->contactRepo->method('blockContact')->willReturn(true);
        $this->contactRepo->method('lookupByAddress')->willReturn(['pubkey' => 'stable-pk-abc']);

        $output = $this->createMock(CliOutputManager::class);

        $fired = null;
        EventDispatcher::getInstance()->subscribe(ContactEvents::CONTACT_BLOCKED, function (array $data) use (&$fired) {
            $fired = $data;
        });

        $result = $this->service->blockContact('http://contact.example', $output);

        $this->assertTrue($result);
        $this->assertNotNull($fired, 'CONTACT_BLOCKED should fire on successful block');
        $this->assertSame('stable-pk-abc', $fired['pubkey']);
        $this->assertSame('http://contact.example', $fired['address']);
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
            ->willReturn(\Eiou\Core\SplitAmount::from(500));

        $result = $this->service->getCreditLimit('pubkey', 'EUR');

        $this->assertEquals(\Eiou\Core\SplitAmount::from(500), $result);
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
            ->with($pubkeyHash, $currency, $this->anything(), $this->isInstanceOf(\Eiou\Core\SplitAmount::class));

        $this->balanceRepo->expects($this->once())
            ->method('insertInitialContactBalances')
            ->with($pubkey, $currency);

        $this->balanceRepo->method('getContactSentBalance')
            ->willReturn(\Eiou\Core\SplitAmount::from(0));
        $this->balanceRepo->method('getContactReceivedBalance')
            ->willReturn(\Eiou\Core\SplitAmount::from(0));
        $this->contactCurrencyRepo->method('getCreditLimit')
            ->willReturn(\Eiou\Core\SplitAmount::from(0));

        $this->contactCreditRepo->expects($this->once())
            ->method('upsertAvailableCredit')
            ->with($pubkeyHash, $this->equalTo(\Eiou\Core\SplitAmount::from(0)), $currency);

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
    // addContact() Requested Credit Limit Tests
    // =========================================================================

    /**
     * Helper to create a service with a mock sync service injected
     */
    private function createServiceWithMockSync(): ContactSyncServiceInterface
    {
        $mockSync = $this->createMock(ContactSyncServiceInterface::class);
        $this->service->setContactSyncService($mockSync);
        return $mockSync;
    }

    /**
     * Test addContact passes requested credit limit when numeric arg after currency
     */
    public function testAddContactPassesRequestedCreditLimitToSyncService(): void
    {
        $mockSync = $this->createServiceWithMockSync();

        $this->currentUser->method('getUserAddresses')->willReturn([]);
        $this->currentUser->method('getAllowedCurrencies')->willReturn(['USD']);

        $this->transportUtility->method('determineTransportType')->willReturn('http');
        $this->contactRepo->method('getContactByAddress')->willReturn(null);

        // argv: eiou add address name fee credit currency requested_credit
        $argv = ['eiou', 'add', 'http://bob:8080', 'Bob', '1', '100', 'USD', '500'];

        $mockSync->expects($this->once())
            ->method('handleNewContact')
            ->with(
                'http://bob:8080',
                'Bob',
                $this->anything(),  // fee (scaled)
                $this->anything(),  // credit (SplitAmount)
                'USD',
                $this->anything(),  // output
                null,               // description (not provided)
                $this->callback(function ($val) {
                    return $val instanceof SplitAmount && $val->whole === 500 && $val->frac === 0;
                })
            );

        $output = $this->createMock(CliOutputManager::class);
        $this->service->addContact($argv, $output);
    }

    /**
     * Test addContact skips requested credit when arg 7 is NULL placeholder with description at arg 8
     */
    public function testAddContactSkipsRequestedCreditWithNullPlaceholder(): void
    {
        $mockSync = $this->createServiceWithMockSync();

        $this->currentUser->method('getUserAddresses')->willReturn([]);
        $this->currentUser->method('getAllowedCurrencies')->willReturn(['USD']);

        $this->transportUtility->method('determineTransportType')->willReturn('http');
        $this->contactRepo->method('getContactByAddress')->willReturn(null);

        // argv: eiou add address name fee credit currency NULL description
        // NULL placeholder allows a description (even numeric) at position 8
        $argv = ['eiou', 'add', 'http://bob:8080', 'Bob', '1', '100', 'USD', 'NULL', '12345'];

        $mockSync->expects($this->once())
            ->method('handleNewContact')
            ->with(
                'http://bob:8080',
                'Bob',
                $this->anything(),
                $this->anything(),
                'USD',
                $this->anything(),
                '12345',            // description from arg 8 (could be numeric reference)
                null                // no requested credit (NULL placeholder)
            );

        $output = $this->createMock(CliOutputManager::class);
        $this->service->addContact($argv, $output);
    }

    /**
     * Test addContact passes both requested credit and description when both provided
     */
    public function testAddContactPassesBothRequestedCreditAndDescription(): void
    {
        $mockSync = $this->createServiceWithMockSync();

        $this->currentUser->method('getUserAddresses')->willReturn([]);
        $this->currentUser->method('getAllowedCurrencies')->willReturn(['USD']);

        $this->transportUtility->method('determineTransportType')->willReturn('http');
        $this->contactRepo->method('getContactByAddress')->willReturn(null);

        // argv: eiou add address name fee credit currency requested_credit description
        $argv = ['eiou', 'add', 'http://bob:8080', 'Bob', '1', '100', 'USD', '250', 'Hello'];

        $mockSync->expects($this->once())
            ->method('handleNewContact')
            ->with(
                'http://bob:8080',
                'Bob',
                $this->anything(),
                $this->anything(),
                'USD',
                $this->anything(),
                'Hello',            // description from arg 8
                $this->callback(function ($val) {
                    return $val instanceof SplitAmount && $val->whole === 250;
                })
            );

        $output = $this->createMock(CliOutputManager::class);
        $this->service->addContact($argv, $output);
    }

    /**
     * Test addContact passes null requested credit when no arg 7 provided
     */
    public function testAddContactPassesNullRequestedCreditWhenNotProvided(): void
    {
        $mockSync = $this->createServiceWithMockSync();

        $this->currentUser->method('getUserAddresses')->willReturn([]);
        $this->currentUser->method('getAllowedCurrencies')->willReturn(['USD']);

        $this->transportUtility->method('determineTransportType')->willReturn('http');
        $this->contactRepo->method('getContactByAddress')->willReturn(null);

        // argv: eiou add address name fee credit currency (no requested credit, no description)
        $argv = ['eiou', 'add', 'http://bob:8080', 'Bob', '1', '100', 'USD'];

        $mockSync->expects($this->once())
            ->method('handleNewContact')
            ->with(
                'http://bob:8080',
                'Bob',
                $this->anything(),
                $this->anything(),
                'USD',
                $this->anything(),
                null,               // no description
                null                // no requested credit
            );

        $output = $this->createMock(CliOutputManager::class);
        $this->service->addContact($argv, $output);
    }

    /**
     * Test addContact passes requested credit with decimal value
     */
    public function testAddContactPassesDecimalRequestedCredit(): void
    {
        $mockSync = $this->createServiceWithMockSync();

        $this->currentUser->method('getUserAddresses')->willReturn([]);
        $this->currentUser->method('getAllowedCurrencies')->willReturn(['USD']);

        $this->transportUtility->method('determineTransportType')->willReturn('http');
        $this->contactRepo->method('getContactByAddress')->willReturn(null);

        // argv: eiou add address name fee credit currency requested_credit (decimal)
        $argv = ['eiou', 'add', 'http://bob:8080', 'Bob', '1', '100', 'USD', '99.50'];

        $mockSync->expects($this->once())
            ->method('handleNewContact')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'USD',
                $this->anything(),
                null,               // no description (arg 8 not provided)
                $this->callback(function ($val) {
                    return $val instanceof SplitAmount && $val->whole === 99;
                })
            );

        $output = $this->createMock(CliOutputManager::class);
        $this->service->addContact($argv, $output);
    }

    /**
     * Test addContact with empty string arg 7 passes null requested credit
     */
    public function testAddContactEmptyStringRequestedCreditPassesNull(): void
    {
        $mockSync = $this->createServiceWithMockSync();

        $this->currentUser->method('getUserAddresses')->willReturn([]);
        $this->currentUser->method('getAllowedCurrencies')->willReturn(['USD']);

        $this->transportUtility->method('determineTransportType')->willReturn('http');
        $this->contactRepo->method('getContactByAddress')->willReturn(null);

        // argv: eiou add address name fee credit currency "" "message"
        $argv = ['eiou', 'add', 'http://bob:8080', 'Bob', '1', '100', 'USD', '', 'Hello'];

        $mockSync->expects($this->once())
            ->method('handleNewContact')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'USD',
                $this->anything(),
                'Hello',            // description at arg 8
                null                // empty string = no requested credit
            );

        $output = $this->createMock(CliOutputManager::class);
        $this->service->addContact($argv, $output);
    }

    /**
     * Test addContact with NULL placeholder and numeric description does not confuse them
     */
    public function testAddContactNullPlaceholderWithNumericDescriptionNotConfused(): void
    {
        $mockSync = $this->createServiceWithMockSync();

        $this->currentUser->method('getUserAddresses')->willReturn([]);
        $this->currentUser->method('getAllowedCurrencies')->willReturn(['USD']);

        $this->transportUtility->method('determineTransportType')->willReturn('http');
        $this->contactRepo->method('getContactByAddress')->willReturn(null);

        // Scenario: no requested credit, but description is a numeric reference "99.50"
        // argv: eiou add address name fee credit currency NULL 99.50
        $argv = ['eiou', 'add', 'http://bob:8080', 'Bob', '1', '100', 'USD', 'NULL', '99.50'];

        $mockSync->expects($this->once())
            ->method('handleNewContact')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'USD',
                $this->anything(),
                '99.50',            // numeric description preserved correctly
                null                // no requested credit (NULL placeholder)
            );

        $output = $this->createMock(CliOutputManager::class);
        $this->service->addContact($argv, $output);
    }

    // =========================================================================
    // getAcceptedContactsPage() — pagination delegation
    // =========================================================================

    /**
     * Happy path: limit + offset forward verbatim to the repository.
     * Backs the loadMoreContacts GUI AJAX handler (Phase 2 load-older).
     */
    public function testGetAcceptedContactsPageDelegatesLimitAndOffset(): void
    {
        $rows = [
            ['pubkey_hash' => 'h1', 'name' => 'Alice',   'status' => 'accepted'],
            ['pubkey_hash' => 'h2', 'name' => 'Bob',     'status' => 'accepted'],
        ];

        $this->contactRepo->expects($this->once())
            ->method('getAcceptedContactsPage')
            ->with(25, 50)
            ->willReturn($rows);

        $result = $this->service->getAcceptedContactsPage(25, 50);

        $this->assertSame($rows, $result);
    }

    /**
     * Default offset is 0 — first-page semantics. A caller that forgets
     * to pass offset doesn't accidentally skip into the middle of the
     * contact list.
     */
    public function testGetAcceptedContactsPageDefaultsOffsetToZero(): void
    {
        $this->contactRepo->expects($this->once())
            ->method('getAcceptedContactsPage')
            ->with(10, 0)
            ->willReturn([]);

        $result = $this->service->getAcceptedContactsPage(10);

        $this->assertSame([], $result);
    }
}
