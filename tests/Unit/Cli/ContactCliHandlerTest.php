<?php

declare(strict_types=1);

namespace Eiou\Tests\Cli;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Cli\CliOutputManager;
use Eiou\Cli\ContactCliHandler;
use Eiou\Contracts\ContactManagementServiceInterface;
use Eiou\Contracts\ContactServiceInterface;
use Eiou\Contracts\ContactStatusServiceInterface;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\ContactRepository;
use Eiou\Services\CliService;
use Eiou\Services\ContactDecisionService;

/**
 * Unit tests for ContactCliHandler — argv dispatch, flag parsing, and
 * delegation to the underlying services.
 *
 * The pattern mirrors PaybackMethodCliHandlerTest: every output call is
 * captured in $outputCalls and tests assert on level + substring.
 */
#[CoversClass(ContactCliHandler::class)]
class ContactCliHandlerTest extends TestCase
{
    /** @var ContactServiceInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $contactService;
    /** @var ContactManagementServiceInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $managementService;
    /** @var ContactDecisionService&\PHPUnit\Framework\MockObject\MockObject */
    private $decisionService;
    /** @var ContactStatusServiceInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $statusService;
    /** @var CliService&\PHPUnit\Framework\MockObject\MockObject */
    private $cliService;
    /** @var ContactRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $contactRepo;
    /** @var ContactCurrencyRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $contactCurrencyRepo;

    private CliOutputManager $output;
    private ContactCliHandler $handler;

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    public array $outputCalls = [];

    protected function setUp(): void
    {
        $this->contactService = $this->createMock(ContactServiceInterface::class);
        $this->managementService = $this->createMock(ContactManagementServiceInterface::class);
        $this->decisionService = $this->createMock(ContactDecisionService::class);
        $this->statusService = $this->createMock(ContactStatusServiceInterface::class);
        $this->cliService = $this->createMock(CliService::class);
        $this->contactRepo = $this->createMock(ContactRepository::class);
        $this->contactCurrencyRepo = $this->createMock(ContactCurrencyRepository::class);

        $this->output = $this->createMock(CliOutputManager::class);
        $this->output->method('info')->willReturnCallback(function (...$a) {
            $this->outputCalls[] = ['info', $a[0] ?? '', $a[1] ?? null];
        });
        $this->output->method('success')->willReturnCallback(function (...$a) {
            $this->outputCalls[] = ['success', $a[0] ?? '', $a[1] ?? null];
        });
        $this->output->method('error')->willReturnCallback(function (...$a) {
            $this->outputCalls[] = ['error', $a[0] ?? '', $a[1] ?? null];
        });

        $this->handler = new ContactCliHandler(
            $this->contactService,
            $this->managementService,
            $this->decisionService,
            $this->statusService,
            $this->cliService,
            $this->contactRepo,
            $this->contactCurrencyRepo,
            $this->output,
        );
    }

    // =========================================================================
    // Dispatch + help
    // =========================================================================

    public function testHelpIsDefault(): void
    {
        $this->handler->handleCommand(['eiou', 'contact']);
        $this->assertOutput('info', 'Contact management');
    }

    public function testHelpExplicit(): void
    {
        $this->handler->handleCommand(['eiou', 'contact', 'help']);
        $this->assertOutput('info', 'Usage');
    }

    public function testUnknownSubcommandFallsThroughToHelp(): void
    {
        $this->handler->handleCommand(['eiou', 'contact', 'teleport']);
        $this->assertOutput('info', 'Usage');
    }

    public function testCurrencyHelpIsDefault(): void
    {
        $this->handler->handleCommand(['eiou', 'contact', 'currency']);
        $this->assertOutput('info', 'Per-currency contact operations');
    }

    // =========================================================================
    // contact add — forwards to ContactService::addContact with built argv
    // =========================================================================

    public function testAddRequiresAddressAndName(): void
    {
        $this->handler->handleCommand(['eiou', 'contact', 'add']);
        $this->assertOutput('error', 'Usage');
    }

    public function testAddBuildsServiceArgvWithFlags(): void
    {
        $this->contactService->expects($this->once())
            ->method('addContact')
            ->with(
                $this->callback(function (array $argv) {
                    return $argv[0] === 'eiou'
                        && $argv[1] === 'add'
                        && $argv[2] === 'http://bob:8080'
                        && $argv[3] === 'Bob'
                        && $argv[4] === '0.01'
                        && $argv[5] === '500'
                        && $argv[6] === 'EUR'
                        && $argv[7] === 'NULL'
                        && $argv[8] === 'Hello there';
                }),
                $this->anything()
            );

        $this->handler->handleCommand([
            'eiou', 'contact', 'add',
            'http://bob:8080', 'Bob',
            '--fee', '0.01',
            '--credit', '500',
            '--currency', 'EUR',
            '--message', 'Hello there',
        ]);
    }

    public function testAddDefaultsCurrencyAndFeeAndCredit(): void
    {
        // Sensible defaults — match the legacy `eiou add` positional shape so
        // the underlying service path is unchanged when a caller supplies the
        // bare minimum identifiers.
        $this->contactService->expects($this->once())
            ->method('addContact')
            ->with(
                $this->callback(function (array $argv) {
                    return $argv[4] === '0' && $argv[5] === '0' && $argv[6] === 'USD';
                }),
                $this->anything()
            );

        $this->handler->handleCommand([
            'eiou', 'contact', 'add',
            'http://bob:8080', 'Bob',
        ]);
    }

    // =========================================================================
    // contact accept — multi-currency triplet form
    // =========================================================================

    public function testAcceptRequiresIdentifier(): void
    {
        $this->handler->handleCommand(['eiou', 'contact', 'accept']);
        $this->assertOutput('error', 'Usage');
    }

    public function testAcceptUnknownContact(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn(null);
        $this->managementService->method('lookupContactInfo')->willReturn(null);

        $this->handler->handleCommand([
            'eiou', 'contact', 'accept', 'unknown',
            '--currency', 'USD', '--fee', '0.01', '--credit', '1000',
        ]);
        $this->assertOutput('error', "No contact matching 'unknown'");
    }

    public function testAcceptForwardsTripletsToDecisionService(): void
    {
        // Pubkey-hash is recognised directly — short-circuit the name lookup.
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturnMap([
            ['hash123', 'pubkey-bob'],
        ]);
        $this->contactRepo->method('getContactByPubkey')->willReturn([
            'pubkey' => 'pubkey-bob',
            'status' => 'pending',
            'name' => 'Bob',
            'http' => 'http://bob:8080',
        ]);

        $this->decisionService->expects($this->once())
            ->method('apply')
            ->with(
                'hash123',
                $this->callback(function (array $decisions) {
                    return count($decisions) === 2
                        && $decisions[0]['currency'] === 'USD'
                        && $decisions[0]['action'] === 'accept'
                        && $decisions[0]['fee'] === '0.01'
                        && $decisions[0]['credit'] === '1000'
                        && $decisions[1]['currency'] === 'EUR'
                        && $decisions[1]['action'] === 'accept'
                        && $decisions[1]['fee'] === '0.02'
                        && $decisions[1]['credit'] === '500';
                }),
                true,                  // isNewContact (status was pending)
                'http://bob:8080',
                'Bob',
            )
            ->willReturn(['accepted' => ['USD', 'EUR'], 'declined' => [], 'errors' => []]);

        $this->handler->handleCommand([
            'eiou', 'contact', 'accept', 'hash123',
            '--currency', 'USD', '--fee', '0.01', '--credit', '1000',
            '--currency', 'EUR', '--fee', '0.02', '--credit', '500',
        ]);

        $this->assertOutputKey('success', 'Decisions applied');
    }

    public function testAcceptRejectsMissingFeeOrCredit(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');
        $this->contactRepo->method('getContactByPubkey')->willReturn([
            'pubkey' => 'pubkey-bob', 'status' => 'pending',
        ]);

        $this->handler->handleCommand([
            'eiou', 'contact', 'accept', 'hash123',
            '--currency', 'USD', '--fee', '0.01',  // missing --credit
        ]);
        $this->assertOutput('error', 'matching --fee and --credit');
    }

    // =========================================================================
    // contact apply — flag form + JSON file
    // =========================================================================

    public function testApplyParsesAcceptDeclineDeferFlags(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');
        $this->contactRepo->method('getContactByPubkey')->willReturn([
            'pubkey' => 'pubkey-bob', 'status' => 'accepted', 'name' => 'Bob',
        ]);

        $this->decisionService->expects($this->once())
            ->method('apply')
            ->with(
                'hash123',
                $this->callback(function (array $decisions) {
                    return count($decisions) === 3
                        && $decisions[0]['action'] === 'accept'
                        && $decisions[0]['currency'] === 'USD'
                        && $decisions[0]['fee'] === '0.01'
                        && $decisions[0]['credit'] === '1000'
                        && $decisions[1]['action'] === 'decline'
                        && $decisions[1]['currency'] === 'EUR'
                        && $decisions[2]['action'] === 'defer'
                        && $decisions[2]['currency'] === 'XRP';
                }),
                false,                 // isNewContact (status accepted)
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['accepted' => ['USD'], 'declined' => ['EUR'], 'errors' => []]);

        $this->handler->handleCommand([
            'eiou', 'contact', 'apply', 'hash123',
            '--accept', 'USD:0.01:1000',
            '--decline', 'EUR',
            '--defer', 'XRP',
        ]);

        $this->assertOutputKey('success', 'Decisions applied');
    }

    public function testApplyLoadsFromFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'decisions');
        file_put_contents($tmp, json_encode([
            ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
        ]));

        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');
        $this->contactRepo->method('getContactByPubkey')->willReturn([
            'pubkey' => 'pubkey-bob', 'status' => 'accepted',
        ]);

        $this->decisionService->expects($this->once())
            ->method('apply')
            ->with(
                'hash123',
                $this->callback(fn (array $d) =>
                    count($d) === 1 && $d[0]['currency'] === 'USD' && $d[0]['action'] === 'accept'
                ),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['accepted' => ['USD'], 'declined' => [], 'errors' => []]);

        $this->handler->handleCommand([
            'eiou', 'contact', 'apply', 'hash123',
            '--from', $tmp,
        ]);

        unlink($tmp);
        $this->assertOutputKey('success', 'Decisions applied');
    }

    public function testApplyErrorsForMalformedJsonFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'decisions');
        file_put_contents($tmp, '{not-json');

        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');

        $this->handler->handleCommand([
            'eiou', 'contact', 'apply', 'hash123',
            '--from', $tmp,
        ]);

        unlink($tmp);
        $this->assertOutput('error', 'JSON array');
    }

    public function testApplyRejectsEmptyDecisionSet(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');

        $this->handler->handleCommand([
            'eiou', 'contact', 'apply', 'hash123',
            // no flags, no --from
        ]);

        $this->assertOutput('error', 'No decisions to apply');
    }

    public function testApplyReportsServiceErrorsBucket(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');
        $this->contactRepo->method('getContactByPubkey')->willReturn([
            'pubkey' => 'pubkey-bob', 'status' => 'accepted',
        ]);
        $this->decisionService->method('apply')->willReturn([
            'accepted' => [],
            'declined' => [],
            'errors' => ['EUR (decline): repo blew up'],
        ]);

        $this->handler->handleCommand([
            'eiou', 'contact', 'apply', 'hash123',
            '--decline', 'EUR',
        ]);

        $this->assertOutput('error', 'No decisions applied');
    }

    // =========================================================================
    // contact decline — bulk decline
    // =========================================================================

    public function testDeclineWithNoPendingCurrenciesEmitsInfo(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');
        $this->contactCurrencyRepo->method('getPendingCurrencies')->willReturn([]);

        $this->handler->handleCommand(['eiou', 'contact', 'decline', 'hash123']);
        $this->assertOutput('info', 'No pending currencies');
    }

    public function testDeclineLoopsOverPendingCurrencies(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');
        $this->contactCurrencyRepo->method('getPendingCurrencies')
            ->with('hash123', 'incoming')
            ->willReturn([
                ['currency' => 'USD'],
                ['currency' => 'EUR'],
            ]);

        $declined = [];
        $this->contactCurrencyRepo->expects($this->exactly(2))
            ->method('declineIncomingCurrency')
            ->willReturnCallback(function ($_h, $ccy) use (&$declined) {
                $declined[] = $ccy;
                return true;
            });

        $this->handler->handleCommand(['eiou', 'contact', 'decline', 'hash123']);

        $this->assertSame(['USD', 'EUR'], $declined);
        $this->assertOutputKey('success', 'Contact request declined');
    }

    // =========================================================================
    // contact ping
    // =========================================================================

    public function testPingMissingIdentifier(): void
    {
        $this->handler->handleCommand(['eiou', 'contact', 'ping']);
        $this->assertOutput('error', 'Usage');
    }

    public function testPingSuccess(): void
    {
        $this->statusService->method('pingContact')->willReturn([
            'success' => true,
            'contact_name' => 'Bob',
            'online_status' => 'online',
            'chain_valid' => true,
            'message' => 'OK',
        ]);

        $this->handler->handleCommand(['eiou', 'contact', 'ping', 'Bob']);
        $this->assertOutput('success', 'Bob is online');
    }

    public function testPingNotFound(): void
    {
        $this->statusService->method('pingContact')->willReturn([
            'success' => false,
            'error' => 'contact_not_found',
            'message' => 'No such contact',
        ]);

        $this->handler->handleCommand(['eiou', 'contact', 'ping', 'Mystery']);
        $this->assertOutput('error', 'No such contact');
    }

    // =========================================================================
    // contact pending — delegates to CliService
    // =========================================================================

    public function testPendingDelegatesToCliService(): void
    {
        $this->cliService->expects($this->once())
            ->method('displayPendingContacts')
            ->with($this->anything(), $this->output);

        $this->handler->handleCommand(['eiou', 'contact', 'pending']);
    }

    // =========================================================================
    // contact list
    // =========================================================================

    public function testListAllReturnsGrouped(): void
    {
        $grouped = [
            'accepted' => [['name' => 'Alice']],
            'pending' => [],
            'blocked' => [],
        ];
        $this->managementService->method('getContactsGroupedByStatus')->willReturn($grouped);

        $this->handler->handleCommand(['eiou', 'contact', 'list']);
        $this->assertOutputKey('success', 'Contacts');
    }

    public function testListWithStatusFilter(): void
    {
        $this->managementService->method('getContactsGroupedByStatus')->willReturn([
            'accepted' => [['name' => 'Alice']],
            'pending' => [['name' => 'Pending']],
        ]);

        $this->handler->handleCommand(['eiou', 'contact', 'list', '--status', 'pending']);
        $this->assertOutputKey('success', 'Contacts (pending)');
    }

    // =========================================================================
    // contact currency …
    // =========================================================================

    public function testCurrencyAcceptForwardsToDecisionService(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');
        $this->contactRepo->method('getContactByPubkey')->willReturn([
            'pubkey' => 'pubkey-bob', 'status' => 'accepted', 'name' => 'Bob',
        ]);

        $this->decisionService->expects($this->once())
            ->method('apply')
            ->with(
                'hash123',
                $this->callback(fn (array $d) =>
                    count($d) === 1
                    && $d[0]['currency'] === 'EUR'
                    && $d[0]['action'] === 'accept'
                    && $d[0]['fee'] === '0.02'
                    && $d[0]['credit'] === '500'
                ),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['accepted' => ['EUR'], 'declined' => [], 'errors' => []]);

        $this->handler->handleCommand([
            'eiou', 'contact', 'currency', 'accept', 'hash123', 'EUR',
            '--fee', '0.02', '--credit', '500',
        ]);
    }

    public function testCurrencyDeclineForwardsToRepository(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');

        $this->contactCurrencyRepo->expects($this->once())
            ->method('declineIncomingCurrency')
            ->with('hash123', 'EUR')
            ->willReturn(true);

        $this->handler->handleCommand([
            'eiou', 'contact', 'currency', 'decline', 'hash123', 'EUR',
        ]);
        $this->assertOutputKey('success', 'Currency EUR declined');
    }

    public function testCurrencyListReturnsRows(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');
        $this->contactCurrencyRepo->expects($this->once())
            ->method('getContactCurrencies')
            ->with('hash123')
            ->willReturn([
                ['currency' => 'USD', 'status' => 'accepted', 'direction' => 'incoming'],
                ['currency' => 'EUR', 'status' => 'pending',  'direction' => 'incoming'],
            ]);

        $this->handler->handleCommand([
            'eiou', 'contact', 'currency', 'list', 'hash123',
        ]);
        $this->assertOutputKey('success', 'Currencies for hash123');
    }

    public function testCurrencyRemoveDeletesConfig(): void
    {
        $this->contactRepo->method('getContactPubkeyFromHash')->willReturn('pubkey-bob');
        $this->contactCurrencyRepo->expects($this->once())
            ->method('deleteCurrencyConfig')
            ->with('hash123', 'EUR')
            ->willReturn(true);

        $this->handler->handleCommand([
            'eiou', 'contact', 'currency', 'remove', 'hash123', 'EUR',
        ]);
        $this->assertOutputKey('success', 'Currency EUR removed locally');
    }

    public function testCurrencyAddRequiresFlags(): void
    {
        $this->handler->handleCommand([
            'eiou', 'contact', 'currency', 'add', 'Bob', 'EUR',
            // missing --fee and --credit
        ]);
        $this->assertOutput('error', 'Usage');
    }

    // =========================================================================
    // contact view / update / delete / block / unblock / search
    // — pure delegation; assert only that the right service method is called.
    // =========================================================================

    public function testViewDelegates(): void
    {
        // viewContact lives on ContactManagementServiceInterface, not the
        // ContactService facade — the handler routes accordingly.
        $this->managementService->expects($this->once())
            ->method('viewContact')
            ->with($this->anything(), $this->output);

        $this->handler->handleCommand(['eiou', 'contact', 'view', 'Bob']);
    }

    public function testUpdateDelegates(): void
    {
        $this->managementService->expects($this->once())
            ->method('updateContact')
            ->with($this->anything(), $this->output);

        $this->handler->handleCommand(['eiou', 'contact', 'update', 'Bob', '--name', 'Robert']);
    }

    public function testDeleteDelegates(): void
    {
        $this->contactService->expects($this->once())
            ->method('deleteContact')
            ->with('Bob', $this->output)
            ->willReturn(true);

        $this->handler->handleCommand(['eiou', 'contact', 'delete', 'Bob']);
    }

    public function testBlockDelegates(): void
    {
        $this->contactService->expects($this->once())
            ->method('blockContact')
            ->with('Bob', $this->output);

        $this->handler->handleCommand(['eiou', 'contact', 'block', 'Bob']);
    }

    public function testUnblockDelegates(): void
    {
        $this->contactService->expects($this->once())
            ->method('unblockContact')
            ->with('Bob', $this->output);

        $this->handler->handleCommand(['eiou', 'contact', 'unblock', 'Bob']);
    }

    public function testSearchDelegates(): void
    {
        $this->managementService->expects($this->once())
            ->method('searchContacts')
            ->with($this->anything(), $this->output);

        $this->handler->handleCommand(['eiou', 'contact', 'search', 'alice']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function assertOutput(string $level, string $substring): void
    {
        foreach ($this->outputCalls as $call) {
            if ($call[0] === $level && str_contains((string) $call[1], $substring)) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail(
            "No $level output containing '$substring'. Got: "
            . json_encode(array_map(fn ($c) => [$c[0], $c[1]], $this->outputCalls))
        );
    }

    private function assertOutputKey(string $level, string $headline): void
    {
        foreach ($this->outputCalls as $call) {
            if ($call[0] === $level && str_contains((string) $call[1], $headline)) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail("No $level output with headline '$headline'");
    }
}
