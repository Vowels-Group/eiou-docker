<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Eiou\Contracts\SyncTriggerInterface;
use Eiou\Core\UserContext;
use Eiou\Database\AddressRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\ContactRepository;
use Eiou\Database\RepositoryFactory;
use Eiou\Database\TransactionContactRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Events\ContactEvents;
use Eiou\Events\EventDispatcher;
use Eiou\Services\ContactSyncService;
use Eiou\Services\Utilities\TimeUtilityService;
use Eiou\Services\Utilities\TransportUtilityService;
use Eiou\Services\Utilities\UtilityServiceContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Focused unit tests for ContactSyncService's event-emitting private
 * helpers. The public surface of ContactSyncService is broad and
 * hard-to-mock (it `new`s `ContactPayload`/`MessagePayload` internally and
 * depends on concrete UserContext), so these tests use reflection to
 * exercise the two private dispatch helpers directly:
 *
 *   - insertContactWithEvent()     → fires CONTACT_ADDED on insert success
 *   - addPendingContactWithEvent() → fires CONTACT_ADDED on pending-insert success
 *
 * Verifying the helpers covers every CONTACT_ADDED emit site in this
 * service, because every `insertContact` / `addPendingContact` call site
 * was refactored to route through exactly one of these helpers.
 */
#[CoversClass(ContactSyncService::class)]
class ContactSyncServiceTest extends TestCase
{
    private ContactRepository $contactRepo;
    private ContactSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        EventDispatcher::resetInstance();

        $this->contactRepo = $this->createMock(ContactRepository::class);
        $addressRepo = $this->createMock(AddressRepository::class);
        $balanceRepo = $this->createMock(BalanceRepository::class);
        $transactionRepo = $this->createMock(TransactionRepository::class);
        $transactionContactRepo = $this->createMock(TransactionContactRepository::class);
        $utilityContainer = $this->createMock(UtilityServiceContainer::class);
        $currentUser = $this->createMock(UserContext::class);
        $syncTrigger = $this->createMock(SyncTriggerInterface::class);

        // The service constructor asks the utility container for transport
        // and time utilities — stub with mocks so no real transport I/O.
        $utilityContainer->method('getTransportUtility')
            ->willReturn($this->createMock(TransportUtilityService::class));
        $utilityContainer->method('getTimeUtility')
            ->willReturn($this->createMock(TimeUtilityService::class));

        // Repository-factory lookups for credit + currency repos. The
        // service's constructor looks each one up by class name and
        // stores them under typed properties, so the mock has to hand
        // back an instance that satisfies each requested type.
        $creditRepo = $this->createMock(ContactCreditRepository::class);
        $currencyRepo = $this->createMock(ContactCurrencyRepository::class);
        $repoFactory = $this->createMock(RepositoryFactory::class);
        $repoFactory->method('get')->willReturnCallback(
            fn(string $class) => $class === ContactCurrencyRepository::class ? $currencyRepo : $creditRepo
        );

        // currentUser is used by ContactPayload constructor; a bare mock is
        // enough — we never exercise the methods that touch it.
        $currentUser->method('getUserAddresses')->willReturn([]);

        $this->service = new ContactSyncService(
            $this->contactRepo,
            $addressRepo,
            $balanceRepo,
            $transactionRepo,
            $transactionContactRepo,
            $utilityContainer,
            $currentUser,
            $repoFactory,
            $syncTrigger,
        );
    }

    protected function tearDown(): void
    {
        EventDispatcher::resetInstance();
        parent::tearDown();
    }

    private function invoke(string $method, array $args): bool
    {
        $ref = new ReflectionMethod($this->service, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->service, $args);
    }

    public function testInsertContactWithEventFiresContactAddedOnSuccess(): void
    {
        $this->contactRepo->method('insertContact')->willReturn(true);

        $fired = null;
        EventDispatcher::getInstance()->subscribe(ContactEvents::CONTACT_ADDED,
            function (array $data) use (&$fired) { $fired = $data; });

        $ok = $this->invoke('insertContactWithEvent', ['pk', 'Name', 0, 0.0, 'USD']);

        $this->assertTrue($ok);
        $this->assertNotNull($fired);
        $this->assertSame('pk', $fired['pubkey']);
        $this->assertSame('Name', $fired['name']);
        $this->assertSame('USD', $fired['currency']);
    }

    public function testInsertContactWithEventDoesNotFireOnFailure(): void
    {
        // When the repository's insert fails we must stay silent — subscribers
        // should see only committed state.
        $this->contactRepo->method('insertContact')->willReturn(false);

        $fired = false;
        EventDispatcher::getInstance()->subscribe(ContactEvents::CONTACT_ADDED,
            function () use (&$fired) { $fired = true; });

        $ok = $this->invoke('insertContactWithEvent', ['pk', 'Name', 0, 0.0, 'USD']);

        $this->assertFalse($ok);
        $this->assertFalse($fired);
    }

    public function testAddPendingContactWithEventFiresContactAddedWithNullFields(): void
    {
        // Pending rows start with no name / currency; subscribers see nulls
        // and must re-query after CONTACT_ACCEPTED for the enriched record.
        // `addPendingContact` returns the generated contact_id — any
        // non-empty string is a truthy "success" signal.
        $this->contactRepo->method('addPendingContact')->willReturn('contact-id-xyz');

        $fired = null;
        EventDispatcher::getInstance()->subscribe(ContactEvents::CONTACT_ADDED,
            function (array $data) use (&$fired) { $fired = $data; });

        $ok = $this->invoke('addPendingContactWithEvent', ['pk-pending', null]);

        $this->assertTrue($ok);
        $this->assertNotNull($fired);
        $this->assertSame('pk-pending', $fired['pubkey']);
        $this->assertNull($fired['name']);
        $this->assertNull($fired['currency']);
    }

    public function testAddPendingContactWithEventDoesNotFireOnFailure(): void
    {
        // Empty-string return = insert failed. The helper treats it as
        // falsy and should skip the dispatch.
        $this->contactRepo->method('addPendingContact')->willReturn('');

        $fired = false;
        EventDispatcher::getInstance()->subscribe(ContactEvents::CONTACT_ADDED,
            function () use (&$fired) { $fired = true; });

        $ok = $this->invoke('addPendingContactWithEvent', ['pk-pending', null]);

        $this->assertFalse($ok);
        $this->assertFalse($fired);
    }

    /**
     * CONTACT_REJECTED is a public-method emit — fires when
     * handleContactCreation's auto-reject currency guard trips. Configure
     * the mocked UserContext to declare USD-only + auto-reject-on and
     * send a request with EUR; the method should short-circuit at the
     * reject branch and fire the event before attempting anything
     * downstream.
     */
    public function testHandleContactCreationAutoRejectFiresContactRejected(): void
    {
        // Rebuild the service with a UserContext that answers the
        // auto-reject branch deterministically. setUp's default mock
        // returns null for getAllowedCurrencies, which is falsy for
        // in_array, but autoRejectUnknownCurrency must be true.
        $cu = $this->createMock(UserContext::class);
        $cu->method('getUserAddresses')->willReturn([]);
        $cu->method('getAllowedCurrencies')->willReturn(['USD']);
        $cu->method('getAutoRejectUnknownCurrency')->willReturn(true);
        $cu->method('getDefaultCurrency')->willReturn('USD');
        $cu->method('getUserLocaters')->willReturn([]);

        $utility = $this->createMock(UtilityServiceContainer::class);
        $utility->method('getTransportUtility')
            ->willReturn($this->createMock(TransportUtilityService::class));
        $utility->method('getTimeUtility')
            ->willReturn($this->createMock(TimeUtilityService::class));

        $repoFactory = $this->createMock(RepositoryFactory::class);
        $repoFactory->method('get')->willReturnCallback(function (string $class) {
            return $class === ContactCurrencyRepository::class
                ? $this->createMock(ContactCurrencyRepository::class)
                : $this->createMock(ContactCreditRepository::class);
        });

        $service = new ContactSyncService(
            $this->contactRepo,
            $this->createMock(AddressRepository::class),
            $this->createMock(BalanceRepository::class),
            $this->createMock(TransactionRepository::class),
            $this->createMock(TransactionContactRepository::class),
            $utility,
            $cu,
            $repoFactory,
            $this->createMock(SyncTriggerInterface::class),
        );

        $fired = null;
        EventDispatcher::getInstance()->subscribe(ContactEvents::CONTACT_REJECTED,
            function (array $data) use (&$fired) { $fired = $data; });

        // EUR is not in allowed list → auto-reject branch fires event and
        // returns the rejection payload string. We don't assert on the
        // returned string (that's ContactPayload's contract); we just
        // verify the event.
        try {
            $service->handleContactCreation([
                'senderAddress' => 'http://mallory.example',
                'senderPublicKey' => 'mallory-pk',
                'currency' => 'EUR',
                'name' => 'Mallory',
                'fee' => 0,
                'credit' => 0,
            ]);
        } catch (\Throwable $e) {
            // ContactPayload::buildRejection may throw with the minimal
            // mocks here — that's downstream of the dispatch we care
            // about, so we swallow and validate the event instead.
        }

        $this->assertNotNull($fired, 'CONTACT_REJECTED should fire when an unsupported currency hits the auto-reject gate');
        $this->assertSame('mallory-pk', $fired['pubkey']);
        $this->assertSame('http://mallory.example', $fired['address']);
        $this->assertSame('currency_not_accepted', $fired['reason']);
        $this->assertSame('EUR', $fired['currency']);
    }
}
