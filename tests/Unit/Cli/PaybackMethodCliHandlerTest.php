<?php
namespace Eiou\Tests\Cli;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Cli\CliOutputManager;
use Eiou\Cli\PaybackMethodCliHandler;
use Eiou\Services\PaybackMethodService;

/**
 * Covers argv dispatch, flag parsing, and the non-prompting subcommands
 * (list / show / remove / share-policy / help / error paths).
 *
 * The prompt-driven `add` / `edit` subcommands read stdin and are not
 * exercised here — they are covered by the integration test harness.
 */
#[CoversClass(PaybackMethodCliHandler::class)]
class PaybackMethodCliHandlerTest extends TestCase
{
    private PaybackMethodService $svc;
    private CliOutputManager $output;
    private PaybackMethodCliHandler $handler;

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    public array $outputCalls = [];

    protected function setUp(): void
    {
        $this->svc = $this->createMock(PaybackMethodService::class);
        $this->output = $this->createMock(CliOutputManager::class);
        // Record every output call so tests can assert what the user sees.
        $this->output->method('info')->willReturnCallback(function (...$a) {
            $this->outputCalls[] = ['info', $a[0] ?? '', $a[1] ?? null];
        });
        $this->output->method('success')->willReturnCallback(function (...$a) {
            $this->outputCalls[] = ['success', $a[0] ?? '', $a[1] ?? null];
        });
        $this->output->method('error')->willReturnCallback(function (...$a) {
            $this->outputCalls[] = ['error', $a[0] ?? '', $a[1] ?? null];
        });
        $this->handler = new PaybackMethodCliHandler($this->svc, $this->output);
    }

    // =========================================================================
    // Dispatch + help
    // =========================================================================

    public function testHelpIsDefault(): void
    {
        $this->handler->handleCommand(['eiou', 'payback']);
        $this->assertOutput('info', 'Payback Methods');
    }

    public function testHelpExplicit(): void
    {
        $this->handler->handleCommand(['eiou', 'payback', 'help']);
        $this->assertOutput('info', 'Usage');
    }

    public function testUnknownSubcommandFallsThroughToHelp(): void
    {
        $this->handler->handleCommand(['eiou', 'payback', 'teleport']);
        $this->assertOutput('info', 'Usage');
    }

    // =========================================================================
    // list
    // =========================================================================

    public function testListEmpty(): void
    {
        $this->svc->method('list')->willReturn([]);
        $this->handler->handleCommand(['eiou', 'payback', 'list']);
        $this->assertOutput('info', 'No payback methods');
    }

    public function testListRenders(): void
    {
        $this->svc->expects($this->once())
            ->method('list')
            ->with(null, true)
            ->willReturn([[
                'method_id' => 'm-1', 'type' => 'bank_wire', 'label' => 'Main',
                'currency' => 'EUR', 'masked_display' => '••••0000',
                'priority' => 10, 'enabled' => true, 'share_policy' => 'auto',
            ]]);
        $this->handler->handleCommand(['eiou', 'payback', 'list']);
        $this->assertOutputKey('success', 'Payback methods');
    }

    public function testListWithCurrencyFlag(): void
    {
        $this->svc->expects($this->once())
            ->method('list')
            ->with('USD', true)
            ->willReturn([]);
        $this->handler->handleCommand(['eiou', 'payback', 'list', '--currency', 'USD']);
    }

    public function testListWithAllFlagIncludesDisabled(): void
    {
        $this->svc->expects($this->once())
            ->method('list')
            ->with(null, false) // --all flips enabledOnly to false
            ->willReturn([]);
        $this->handler->handleCommand(['eiou', 'payback', 'list', '--all']);
    }

    // =========================================================================
    // show
    // =========================================================================

    public function testShowMissingId(): void
    {
        $this->handler->handleCommand(['eiou', 'payback', 'show']);
        $this->assertOutput('error', 'Usage');
    }

    public function testShowNotFound(): void
    {
        $this->svc->method('getReveal')->willReturn(null);
        $this->handler->handleCommand(['eiou', 'payback', 'show', 'nope']);
        $this->assertOutput('error', 'Not found');
    }

    public function testShowFound(): void
    {
        $this->svc->method('getReveal')->willReturn([
            'method_id' => 'm', 'type' => 'paypal',
            'fields' => ['email' => 'a@b.c'],
        ]);
        $this->handler->handleCommand(['eiou', 'payback', 'show', 'm']);
        $this->assertOutputKey('success', 'Payback method (revealed)');
    }

    // =========================================================================
    // remove
    // =========================================================================

    public function testRemoveSuccess(): void
    {
        $this->svc->expects($this->once())->method('remove')->with('m')->willReturn(true);
        $this->handler->handleCommand(['eiou', 'payback', 'remove', 'm']);
        $this->assertOutputKey('success', 'Payback method removed');
    }

    public function testRemoveNotFound(): void
    {
        $this->svc->method('remove')->willReturn(false);
        $this->handler->handleCommand(['eiou', 'payback', 'remove', 'nope']);
        $this->assertOutput('error', 'Not found');
    }

    // =========================================================================
    // share-policy
    // =========================================================================

    public function testSharePolicySuccess(): void
    {
        $this->svc->expects($this->once())
            ->method('setSharePolicy')
            ->with('m', 'prompt')
            ->willReturn([]);
        $this->handler->handleCommand(['eiou', 'payback', 'share-policy', 'm', 'prompt']);
        $this->assertOutputKey('success', 'Share policy updated');
    }

    public function testSharePolicyValidationFails(): void
    {
        $this->svc->method('setSharePolicy')->willReturn([
            ['field' => 'share_policy', 'code' => 'invalid_value', 'message' => 'bad'],
        ]);
        $this->handler->handleCommand(['eiou', 'payback', 'share-policy', 'm', 'yolo']);
        $this->assertOutput('error', 'Validation failed');
    }

    public function testSharePolicyMissingArgs(): void
    {
        $this->handler->handleCommand(['eiou', 'payback', 'share-policy']);
        $this->assertOutput('error', 'Usage');
    }

    // =========================================================================
    // Flag parsing
    // =========================================================================

    public function testFlagAcceptsEqualsForm(): void
    {
        $this->svc->expects($this->once())
            ->method('list')
            ->with('EUR', true)
            ->willReturn([]);
        $this->handler->handleCommand(['eiou', 'payback', 'list', '--currency=EUR']);
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
            . json_encode(array_map(fn($c) => [$c[0], $c[1]], $this->outputCalls))
        );
    }

    private function assertOutputKey(string $level, string $headline): void
    {
        foreach ($this->outputCalls as $call) {
            if ($call[0] === $level && $call[1] === $headline) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail("No $level output with headline '$headline'");
    }
}
