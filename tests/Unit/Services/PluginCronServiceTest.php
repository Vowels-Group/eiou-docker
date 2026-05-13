<?php
namespace Eiou\Tests\Services;

use Eiou\Services\PluginCronService;
use Eiou\Services\PluginIpcForwarder;
use Eiou\Services\PluginLoader;
use Eiou\Utils\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PluginCronService::class)]
class PluginCronServiceTest extends TestCase
{
    private string $tmpStateFile;
    /** @var PluginLoader&\PHPUnit\Framework\MockObject\MockObject */
    private $loader;
    /** @var PluginIpcForwarder&\PHPUnit\Framework\MockObject\MockObject */
    private $forwarder;
    private PluginCronService $svc;

    protected function setUp(): void
    {
        $this->tmpStateFile = tempnam(sys_get_temp_dir(), 'eiou-cron-test-');
        @unlink($this->tmpStateFile);
        $this->loader = $this->createMock(PluginLoader::class);
        $this->forwarder = $this->createMock(PluginIpcForwarder::class);
        $this->svc = new PluginCronService(
            $this->loader,
            $this->forwarder,
            $this->createMock(Logger::class),
            $this->tmpStateFile
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpStateFile);
    }

    private function pluginRow(string $name, array $cron, bool $enabled = true, bool $sandboxed = true): array
    {
        return [
            'name' => $name,
            'enabled' => $enabled,
            'sandboxed' => $sandboxed,
            'cron' => $cron,
        ];
    }

    // ===================================================================
    // Eligibility
    // ===================================================================

    #[Test]
    public function firesEntryOnFirstTickWhenStateIsEmpty(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('p1', [['interval_minutes' => 60, 'action' => 'flush']]),
        ]);
        $this->forwarder->expects($this->once())
            ->method('dispatchCron')
            ->with('p1', 'flush', 1_000, 60)
            ->willReturn(['ok' => true]);

        $report = $this->svc->tick(1_000);
        $this->assertCount(1, $report['fired']);
        $this->assertSame('p1', $report['fired'][0]['plugin']);
        $this->assertSame('flush', $report['fired'][0]['action']);
        $this->assertEmpty($report['skipped']);
    }

    #[Test]
    public function skipsEntryWhenIntervalNotElapsed(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('p1', [['interval_minutes' => 60, 'action' => 'flush']]),
        ]);
        $this->forwarder->expects($this->once())
            ->method('dispatchCron')->willReturn(['ok' => true]);

        // First tick fires; second tick 10 minutes later (well below the
        // 60-minute interval) must skip.
        $this->svc->tick(1_000);
        $report = $this->svc->tick(1_000 + 10 * 60);

        $this->assertEmpty($report['fired']);
        $this->assertCount(1, $report['skipped']);
        $this->assertSame('not_due', $report['skipped'][0]['reason']);
    }

    #[Test]
    public function firesAgainAfterIntervalElapses(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('p1', [['interval_minutes' => 60, 'action' => 'flush']]),
        ]);
        $this->forwarder->expects($this->exactly(2))
            ->method('dispatchCron')->willReturn(['ok' => true]);

        $this->svc->tick(1_000);
        $report = $this->svc->tick(1_000 + 61 * 60);  // 61 minutes later

        $this->assertCount(1, $report['fired']);
    }

    #[Test]
    public function skipsDisabledPlugins(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('p1', [['interval_minutes' => 1, 'action' => 'flush']], enabled: false),
        ]);
        $this->forwarder->expects($this->never())->method('dispatchCron');

        $report = $this->svc->tick(1_000);
        $this->assertEmpty($report['fired']);
    }

    #[Test]
    public function skipsNonSandboxedPlugins(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('p1', [['interval_minutes' => 1, 'action' => 'flush']], sandboxed: false),
        ]);
        $this->forwarder->expects($this->never())->method('dispatchCron');

        $report = $this->svc->tick(1_000);
        $this->assertEmpty($report['fired']);
    }

    #[Test]
    public function skipsPluginsWithoutCronEntries(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('p1', []),
            ['name' => 'p2', 'enabled' => true, 'sandboxed' => true], // cron key missing entirely
        ]);
        $this->forwarder->expects($this->never())->method('dispatchCron');

        $report = $this->svc->tick(1_000);
        $this->assertEmpty($report['fired']);
    }

    // ===================================================================
    // Dispatch failure handling
    // ===================================================================

    #[Test]
    public function dispatchFailureRecordsErrorAndDoesNotAdvanceLastFiredWindow(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('p1', [['interval_minutes' => 60, 'action' => 'flush']]),
        ]);
        // First call returns null (transport failure), second returns ok.
        $this->forwarder->expects($this->exactly(2))
            ->method('dispatchCron')
            ->willReturnOnConsecutiveCalls(null, ['ok' => true]);

        $report1 = $this->svc->tick(1_000);
        $this->assertEmpty($report1['fired']);
        $this->assertCount(1, $report1['errors']);
        $this->assertSame('dispatch_failed', $report1['errors'][0]['reason']);

        // Next tick — same `now` — must retry since the last-fired
        // window was NOT advanced on the failed dispatch.
        $report2 = $this->svc->tick(1_000);
        $this->assertCount(1, $report2['fired']);
    }

    #[Test]
    public function thrownExceptionInDispatcherDoesNotCorruptState(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('p1', [['interval_minutes' => 60, 'action' => 'flush']]),
        ]);
        $this->forwarder->method('dispatchCron')
            ->willThrowException(new \RuntimeException('boom'));

        $report = $this->svc->tick(1_000);
        $this->assertEmpty($report['fired']);
        $this->assertCount(1, $report['errors']);
        $this->assertStringContainsString('boom', $report['errors'][0]['reason']);
        // State file must still be writable / no half-written corruption.
        $this->assertFileDoesNotExist($this->tmpStateFile,
            'no state file should be written when nothing succeeded');
    }

    // ===================================================================
    // State pruning + file format
    // ===================================================================

    #[Test]
    public function prunesStateEntriesForUninstalledPluginsOrRemovedActions(): void
    {
        // Round 1: p1 has 'old-action' scheduled, fires once → state
        // gets the entry.
        $this->loader->method('listAllPlugins')->willReturnOnConsecutiveCalls(
            [$this->pluginRow('p1', [['interval_minutes' => 60, 'action' => 'old-action']])],
            // Second listAllPlugins call inside pruneState — same as the iteration.
            [$this->pluginRow('p1', [['interval_minutes' => 60, 'action' => 'old-action']])],
            // Round 2 iteration — the action has been renamed/removed.
            [$this->pluginRow('p1', [['interval_minutes' => 60, 'action' => 'new-action']])],
            // Round 2 pruneState call.
            [$this->pluginRow('p1', [['interval_minutes' => 60, 'action' => 'new-action']])]
        );
        $this->forwarder->method('dispatchCron')->willReturn(['ok' => true]);

        $this->svc->tick(1_000);
        $state1 = json_decode(file_get_contents($this->tmpStateFile), true);
        $this->assertArrayHasKey('p1|old-action', $state1);

        // Round 2: 'old-action' no longer declared, 'new-action' is.
        $this->svc->tick(1_000 + 120 * 60);
        $state2 = json_decode(file_get_contents($this->tmpStateFile), true);
        $this->assertArrayNotHasKey('p1|old-action', $state2,
            'pruning must drop state entries for actions that are no longer declared');
        $this->assertArrayHasKey('p1|new-action', $state2);
    }

    #[Test]
    public function noStateFileIsWrittenWhenNothingFires(): void
    {
        $this->loader->method('listAllPlugins')->willReturn([
            $this->pluginRow('p1', []),
        ]);

        $this->svc->tick(1_000);
        $this->assertFileDoesNotExist($this->tmpStateFile);
    }
}
