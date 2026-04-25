<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Eiou\Contracts\ContactServiceInterface;
use Eiou\Contracts\ContactSyncServiceInterface;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Core\UserContext;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\ContactRepository;
use Eiou\Services\ContactDecisionService;

/**
 * Unit tests for ContactDecisionService — the shared batched-apply
 * implementation lifted out of ContactController::handleApplyContactDecisions
 * during the contact-CLI rework. Mirrors the historical controller-level
 * coverage and adds direct assertions on partition order, declines-first
 * sequencing, and the first-accept-via-add path used for new contacts.
 */
#[CoversClass(ContactDecisionService::class)]
class ContactDecisionServiceTest extends TestCase
{
    /** @var ContactRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $contactRepository;
    /** @var ContactCurrencyRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $contactCurrencyRepository;
    /** @var ContactCreditRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $contactCreditRepository;
    /** @var BalanceRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $balanceRepository;
    /** @var ContactSyncServiceInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $contactSyncService;
    /** @var ContactServiceInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $contactService;
    /** @var UserContext&\PHPUnit\Framework\MockObject\MockObject */
    private $currentUser;

    private ContactDecisionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contactRepository = $this->createMock(ContactRepository::class);
        $this->contactCurrencyRepository = $this->createMock(ContactCurrencyRepository::class);
        $this->contactCreditRepository = $this->createMock(ContactCreditRepository::class);
        $this->balanceRepository = $this->createMock(BalanceRepository::class);
        $this->contactSyncService = $this->createMock(ContactSyncServiceInterface::class);
        $this->contactService = $this->createMock(ContactServiceInterface::class);
        $this->currentUser = $this->createMock(UserContext::class);

        // Default: currency already in allowed list — autoAddAllowedCurrency is a no-op
        $this->currentUser->method('getAllowedCurrencies')->willReturn(['USD', 'EUR', 'XRP', 'GBP']);

        $this->service = new ContactDecisionService(
            $this->contactRepository,
            $this->contactCurrencyRepository,
            $this->contactCreditRepository,
            $this->balanceRepository,
            $this->contactSyncService,
            $this->contactService,
            $this->currentUser,
        );
    }

    /** Skip tests that hit InputValidator::validateAmount when bcmath is unavailable. */
    private function requireBcmath(): void
    {
        if (!extension_loaded('bcmath')) {
            $this->markTestSkipped('bcmath extension required for amount validation');
        }
    }

    #[Test]
    public function applyReturnsEmptyResultForEmptyPubkeyHash(): void
    {
        // Guard against pre-validation slip-ups: empty hash is a no-op.
        $this->contactRepository->expects($this->never())->method('getContactPubkeyFromHash');
        $this->contactCurrencyRepository->expects($this->never())->method('declineIncomingCurrency');

        $result = $this->service->apply('', [
            ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
        ]);

        $this->assertSame(['accepted' => [], 'declined' => [], 'errors' => []], $result);
    }

    #[Test]
    public function applyReturnsEmptyResultForEmptyDecisions(): void
    {
        $this->contactRepository->expects($this->never())->method('getContactPubkeyFromHash');

        $result = $this->service->apply('hash123', []);

        $this->assertSame(['accepted' => [], 'declined' => [], 'errors' => []], $result);
    }

    #[Test]
    public function applyRunsDeclinesBeforeAccepts(): void
    {
        $this->requireBcmath();
        // Sequencing matters: a decline-then-accept payload that runs
        // accepts first risks the decline being clobbered by an
        // auto-currency-add side effect on the accept path. Pin order.
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn(null);

        $callOrder = [];
        $this->contactCurrencyRepository->method('declineIncomingCurrency')
            ->willReturnCallback(function ($_hash, $ccy) use (&$callOrder) {
                $callOrder[] = "decline:{$ccy}";
                return true;
            });
        $this->contactCurrencyRepository->method('updateCurrencyConfig')
            ->willReturnCallback(function ($_hash, $ccy) use (&$callOrder) {
                $callOrder[] = "accept:{$ccy}";
                return true;
            });
        $this->contactCurrencyRepository->method('hasCurrency')->willReturn(false);
        $this->contactSyncService->method('sendCurrencyAcceptanceNotification')->willReturn(true);

        $result = $this->service->apply('hash123', [
            ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
            ['currency' => 'EUR', 'action' => 'decline'],
        ]);

        $this->assertSame(['decline:EUR', 'accept:USD'], $callOrder);
        $this->assertSame(['USD'], $result['accepted']);
        $this->assertSame(['EUR'], $result['declined']);
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function applyIgnoresDeferredEntries(): void
    {
        // "defer" rows should be no-ops — neither accepted nor declined.
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn(null);
        $this->contactCurrencyRepository->expects($this->never())->method('declineIncomingCurrency');
        $this->contactCurrencyRepository->expects($this->never())->method('updateCurrencyConfig');

        $result = $this->service->apply('hash123', [
            ['currency' => 'XRP', 'action' => 'defer'],
        ]);

        $this->assertSame([], $result['accepted']);
        $this->assertSame([], $result['declined']);
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function applyRecordsErrorWhenDeclineRepositoryThrows(): void
    {
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn(null);
        $this->contactCurrencyRepository->method('declineIncomingCurrency')
            ->willThrowException(new \RuntimeException('boom'));

        $result = $this->service->apply('hash123', [
            ['currency' => 'EUR', 'action' => 'decline'],
        ]);

        $this->assertSame([], $result['declined']);
        $this->assertSame(['EUR (decline): boom'], $result['errors']);
    }

    #[Test]
    public function applyUppercasesCurrencyOnDecline(): void
    {
        // GUI form may submit lowercase ccy codes — service must normalise.
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn(null);
        $this->contactCurrencyRepository->expects($this->once())
            ->method('declineIncomingCurrency')
            ->with('hash123', 'MXN')
            ->willReturn(true);

        $result = $this->service->apply('hash123', [
            ['currency' => 'mxn', 'action' => 'decline'],
        ]);

        $this->assertSame(['MXN'], $result['declined']);
    }

    #[Test]
    public function applySkipsDeclineWithEmptyCurrency(): void
    {
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn(null);
        $this->contactCurrencyRepository->expects($this->never())->method('declineIncomingCurrency');

        $result = $this->service->apply('hash123', [
            ['currency' => '', 'action' => 'decline'],
        ]);

        $this->assertSame([], $result['declined']);
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function applyReportsMissingFieldsErrorOnAccept(): void
    {
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn(null);

        $result = $this->service->apply('hash123', [
            ['currency' => 'USD', 'action' => 'accept', 'fee' => '', 'credit' => '1000'],
        ]);

        $this->assertSame([], $result['accepted']);
        $this->assertSame(['USD: missing fields'], $result['errors']);
    }

    #[Test]
    public function applyReportsInvalidFee(): void
    {
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn(null);

        $result = $this->service->apply('hash123', [
            ['currency' => 'USD', 'action' => 'accept', 'fee' => 'not-a-number', 'credit' => '1000'],
        ]);

        $this->assertSame([], $result['accepted']);
        $this->assertSame(['USD: invalid fee'], $result['errors']);
    }

    #[Test]
    public function applyReportsInvalidCredit(): void
    {
        $this->requireBcmath();
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn(null);

        $result = $this->service->apply('hash123', [
            ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => 'nope'],
        ]);

        $this->assertSame([], $result['accepted']);
        $this->assertSame(['USD: invalid credit'], $result['errors']);
    }

    #[Test]
    public function applyAcceptsPersistsCurrencyConfigAndSendsNotification(): void
    {
        $this->requireBcmath();
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn('pubkey-abc');

        $this->contactCurrencyRepository->expects($this->once())
            ->method('updateCurrencyConfig')
            ->with(
                'hash123',
                'USD',
                $this->callback(fn (array $fields) =>
                    isset($fields['fee_percent'], $fields['credit_limit'], $fields['status'])
                    && $fields['status'] === 'accepted'
                    && $fields['credit_limit'] instanceof SplitAmount
                ),
                'incoming'
            )
            ->willReturn(true);

        $this->contactCurrencyRepository->method('hasCurrency')->willReturn(false);
        $this->contactCurrencyRepository->method('getCreditLimit')->willReturn(SplitAmount::zero());

        $this->balanceRepository->expects($this->once())
            ->method('insertInitialContactBalances')
            ->with('pubkey-abc', 'USD');
        $this->balanceRepository->method('getContactSentBalance')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactReceivedBalance')->willReturn(SplitAmount::zero());

        $this->contactCreditRepository->expects($this->once())
            ->method('upsertAvailableCredit')
            ->with('hash123', $this->isInstanceOf(SplitAmount::class), 'USD')
            ->willReturn(true);

        $this->contactSyncService->expects($this->once())
            ->method('sendCurrencyAcceptanceNotification')
            ->with('hash123', 'USD')
            ->willReturn(true);

        $result = $this->service->apply('hash123', [
            ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
        ]);

        $this->assertSame(['USD'], $result['accepted']);
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function applyMirrorsOutgoingStatusWhenOutgoingExists(): void
    {
        $this->requireBcmath();
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn('pubkey-abc');
        $this->contactCurrencyRepository->method('hasCurrency')
            ->willReturnMap([
                ['hash123', 'USD', null, false],
                ['hash123', 'USD', 'outgoing', true],
            ]);
        $this->contactCurrencyRepository->method('updateCurrencyConfig')->willReturn(true);
        $this->contactCurrencyRepository->method('getCreditLimit')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactSentBalance')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactReceivedBalance')->willReturn(SplitAmount::zero());

        $this->contactCurrencyRepository->expects($this->once())
            ->method('updateCurrencyStatus')
            ->with('hash123', 'USD', 'accepted', 'outgoing')
            ->willReturn(true);

        $this->service->apply('hash123', [
            ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
        ]);
    }

    #[Test]
    public function applyTreatsCreditWriteFailureAsNonFatal(): void
    {
        $this->requireBcmath();
        // Failure to upsert available_credit is logged and recovered later via
        // ping/pong — must not abort the overall accept flow.
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn('pubkey-abc');
        $this->contactCurrencyRepository->method('updateCurrencyConfig')->willReturn(true);
        $this->contactCurrencyRepository->method('hasCurrency')->willReturn(false);
        $this->contactCurrencyRepository->method('getCreditLimit')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactSentBalance')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactReceivedBalance')->willReturn(SplitAmount::zero());
        $this->contactCreditRepository->method('upsertAvailableCredit')
            ->willThrowException(new \RuntimeException('db locked'));
        $this->contactSyncService->expects($this->once())
            ->method('sendCurrencyAcceptanceNotification')
            ->willReturn(true);

        $result = $this->service->apply('hash123', [
            ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
        ]);

        $this->assertSame(['USD'], $result['accepted']);
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function applyForNewContactDelegatesFirstAcceptToAddContact(): void
    {
        $this->requireBcmath();
        // For a new (pending) contact, the very first accept must go through
        // the addContact CLI path — that's what materialises the contact row
        // server-side. Subsequent accepts use the standard currency path.
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn('pubkey-abc');
        $this->contactRepository->method('getContactByPubkey')
            ->with('pubkey-abc')
            ->willReturn(['pubkey' => 'pubkey-abc', 'status' => Constants::CONTACT_STATUS_PENDING]);

        $this->contactService->expects($this->once())
            ->method('addContact')
            ->with(
                $this->callback(fn (array $argv) =>
                    $argv[0] === 'eiou'
                    && $argv[1] === 'add'
                    && $argv[2] === 'http://bob:8080'
                    && $argv[3] === 'Bob'
                    && $argv[4] === '0.01'
                    && $argv[5] === '1000'
                    && $argv[6] === 'USD'
                    && $argv[7] === '--json'
                ),
                $this->anything()
            );

        $this->contactCurrencyRepository->method('hasCurrency')->willReturn(false);
        $this->contactCurrencyRepository->method('getCreditLimit')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactSentBalance')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactReceivedBalance')->willReturn(SplitAmount::zero());

        // Second accept should use updateCurrencyConfig — not addContact again.
        $this->contactCurrencyRepository->expects($this->once())
            ->method('updateCurrencyConfig')
            ->with('hash123', 'EUR');

        $result = $this->service->apply(
            'hash123',
            [
                ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
                ['currency' => 'EUR', 'action' => 'accept', 'fee' => '0.02', 'credit' => '500'],
            ],
            isNewContact: true,
            contactAddress: 'http://bob:8080',
            contactName: 'Bob',
        );

        $this->assertSame(['USD', 'EUR'], $result['accepted']);
        $this->assertSame([], $result['errors']);
    }

    #[Test]
    public function applyForExistingContactSkipsFirstAcceptViaAdd(): void
    {
        $this->requireBcmath();
        // Already-accepted contact: ALL accepts go through the standard
        // currency path; addContact CLI must not be called.
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn('pubkey-abc');
        $this->contactRepository->method('getContactByPubkey')
            ->willReturn(['pubkey' => 'pubkey-abc', 'status' => Constants::CONTACT_STATUS_ACCEPTED]);

        $this->contactService->expects($this->never())->method('addContact');

        $this->contactCurrencyRepository->method('hasCurrency')->willReturn(false);
        $this->contactCurrencyRepository->method('getCreditLimit')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactSentBalance')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactReceivedBalance')->willReturn(SplitAmount::zero());

        $this->contactCurrencyRepository->expects($this->once())
            ->method('updateCurrencyConfig')
            ->with('hash123', 'USD');

        $result = $this->service->apply(
            'hash123',
            [['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000']],
            isNewContact: true,
            contactAddress: 'http://bob:8080',
            contactName: 'Bob',
        );

        $this->assertSame(['USD'], $result['accepted']);
    }

    #[Test]
    public function applyForNewContactRequiresAddressAndName(): void
    {
        $this->requireBcmath();
        // New-contact branch needs the address+name from the modal — without
        // them the first-accept-via-add step is skipped and the regular
        // currency path runs (which won't establish the contact, but the
        // service must not crash).
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn('pubkey-abc');
        $this->contactRepository->method('getContactByPubkey')
            ->willReturn(['pubkey' => 'pubkey-abc', 'status' => Constants::CONTACT_STATUS_PENDING]);

        $this->contactService->expects($this->never())->method('addContact');
        $this->contactCurrencyRepository->method('hasCurrency')->willReturn(false);
        $this->contactCurrencyRepository->method('getCreditLimit')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactSentBalance')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactReceivedBalance')->willReturn(SplitAmount::zero());

        $this->service->apply(
            'hash123',
            [['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000']],
            isNewContact: true,
            contactAddress: null,
            contactName: null,
        );
    }

    #[Test]
    public function applyForNewContactCapturesAddContactException(): void
    {
        $this->requireBcmath();
        // If the addContact CLI path throws, the failure is logged in the
        // errors bucket — not propagated — so the rest of the batch can still
        // run. The first entry stays in $acceptList and gets retried via the
        // standard currency path (mirroring the original controller flow).
        $this->contactRepository->method('getContactPubkeyFromHash')->willReturn('pubkey-abc');
        $this->contactRepository->method('getContactByPubkey')
            ->willReturn(['pubkey' => 'pubkey-abc', 'status' => Constants::CONTACT_STATUS_PENDING]);

        $this->contactService->method('addContact')
            ->willThrowException(new \RuntimeException('addContact blew up'));

        $this->contactCurrencyRepository->method('hasCurrency')->willReturn(false);
        $this->contactCurrencyRepository->method('updateCurrencyConfig')->willReturn(true);
        $this->contactCurrencyRepository->method('getCreditLimit')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactSentBalance')->willReturn(SplitAmount::zero());
        $this->balanceRepository->method('getContactReceivedBalance')->willReturn(SplitAmount::zero());

        $result = $this->service->apply(
            'hash123',
            [['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000']],
            isNewContact: true,
            contactAddress: 'http://bob:8080',
            contactName: 'Bob',
        );

        $this->assertContains('USD: addContact blew up', $result['errors']);
    }
}
