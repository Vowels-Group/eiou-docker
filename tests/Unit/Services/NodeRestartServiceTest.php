<?php
/**
 * Unit Tests for NodeRestartService
 *
 * Tests are driven through injected callables so no real PIDs are signaled
 * and no real /tmp or /run paths are touched.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\NodeRestartService;

#[CoversClass(NodeRestartService::class)]
class NodeRestartServiceTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/eiou-restart-test-' . uniqid('', true);
        mkdir($this->tmpRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
    }

    public function testRestartReturnsZeroProcessorsWhenNoPidFiles(): void
    {
        $signaled = [];
        $service = $this->makeService(
            processorPidGlob: $this->tmpRoot . '/processors/*.pid',
            fpmCandidates: [],
            signaler: $this->recordingSignaler($signaled)
        );

        $result = $service->restart();

        $this->assertSame(0, $result['processors_terminated']);
        $this->assertFalse($result['fpm_reloaded']);
        $this->assertNull($result['fpm_master_pid']);
        $this->assertSame([], $signaled);
    }

    public function testRestartTerminatesEveryDiscoveredProcessorPid(): void
    {
        $procDir = $this->tmpRoot . '/processors';
        mkdir($procDir, 0777, true);
        file_put_contents($procDir . '/p2p.pid', "1234\n");
        file_put_contents($procDir . '/transaction.pid', "5678");
        file_put_contents($procDir . '/cleanup.pid', "  9999  ");

        $signaled = [];
        $service = $this->makeService(
            processorPidGlob: $procDir . '/*.pid',
            fpmCandidates: [],
            signaler: $this->recordingSignaler($signaled, returns: true)
        );

        $result = $service->restart();

        $this->assertSame(3, $result['processors_terminated']);
        $signaledPids = array_column(
            array_filter($signaled, fn(array $c): bool => $c['signal'] === SIGTERM),
            'pid'
        );
        sort($signaledPids);
        $this->assertSame([1234, 5678, 9999], $signaledPids);
    }

    public function testProcessorsCountedOnlyForSignalsThatSucceeded(): void
    {
        $procDir = $this->tmpRoot . '/processors';
        mkdir($procDir, 0777, true);
        file_put_contents($procDir . '/alive.pid', "100");
        file_put_contents($procDir . '/dead.pid', "200");

        // Signaler returns true only for PID 100; PID 200 looks like a stale
        // PID file (process already gone), so it shouldn't count.
        $signaled = [];
        $signaler = function (int $pid, int $signal) use (&$signaled): bool {
            $signaled[] = ['pid' => $pid, 'signal' => $signal];
            return $pid === 100;
        };

        $service = $this->makeService(
            processorPidGlob: $procDir . '/*.pid',
            fpmCandidates: [],
            signaler: $signaler
        );

        $result = $service->restart();

        $this->assertSame(1, $result['processors_terminated']);
    }

    public function testProcessorPidFilesAreNotDeletedAfterSignaling(): void
    {
        $procDir = $this->tmpRoot . '/processors';
        mkdir($procDir, 0777, true);
        $pidPath = $procDir . '/p2p.pid';
        file_put_contents($pidPath, "1234");

        $service = $this->makeService(
            processorPidGlob: $procDir . '/*.pid',
            fpmCandidates: [],
            signaler: $this->recordingSignaler($_)
        );

        $service->restart();

        // Critical: respawned processor needs to be free to overwrite its
        // PID file. We don't unlink to avoid racing with the new write.
        $this->assertFileExists($pidPath);
    }

    public function testFpmMasterFoundFromCandidatePidFile(): void
    {
        $fpmPidPath = $this->tmpRoot . '/run/php/php8.2-fpm.pid';
        @mkdir(dirname($fpmPidPath), 0777, true);
        file_put_contents($fpmPidPath, "777\n");

        // Signaler returns true for "is alive?" (signal 0) AND for SIGUSR2
        $signaled = [];
        $service = $this->makeService(
            processorPidGlob: $this->tmpRoot . '/none/*.pid',
            fpmCandidates: [$fpmPidPath],
            signaler: $this->recordingSignaler($signaled, returns: true)
        );

        $result = $service->restart();

        $this->assertSame(777, $result['fpm_master_pid']);
        $this->assertTrue($result['fpm_reloaded']);

        $signals = array_column($signaled, 'signal');
        $this->assertContains(SIGUSR2, $signals);
        // The is-alive probe (signal 0) must run before SIGUSR2
        $this->assertContains(0, $signals);
    }

    public function testFpmCandidateGlobIsExpanded(): void
    {
        $dir = $this->tmpRoot . '/run/php';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/php8.3-fpm.pid', "555");

        $service = $this->makeService(
            processorPidGlob: $this->tmpRoot . '/none/*.pid',
            fpmCandidates: [$this->tmpRoot . '/run/php/php*-fpm.pid'],
            signaler: $this->recordingSignaler($_, returns: true)
        );

        $this->assertSame(555, $service->findPhpFpmMasterPid());
    }

    public function testFpmStalePidFileIgnoredWhenProcessNotAlive(): void
    {
        $fpmPidPath = $this->tmpRoot . '/php-fpm.pid';
        file_put_contents($fpmPidPath, "999");

        // Stale PID: is-alive probe (signal 0) returns false → service must
        // not promote it to fpm_master_pid, even though the file exists.
        $service = $this->makeService(
            processorPidGlob: $this->tmpRoot . '/none/*.pid',
            fpmCandidates: [$fpmPidPath],
            procCmdlineGlob: $this->tmpRoot . '/proc/[0-9]*/cmdline',
            signaler: fn(int $pid, int $sig): bool => false
        );

        $result = $service->restart();

        $this->assertNull($result['fpm_master_pid']);
        $this->assertFalse($result['fpm_reloaded']);
    }

    public function testFpmFallbackScansProcCmdline(): void
    {
        // No PID file. Stub /proc/<pid>/cmdline files in tmp.
        $procDir = $this->tmpRoot . '/proc/4242';
        mkdir($procDir, 0777, true);
        file_put_contents($procDir . '/cmdline', "php-fpm: master process (/etc/php/8.2/fpm/php-fpm.conf)");

        // Decoy: a worker, must NOT match
        $workerDir = $this->tmpRoot . '/proc/4243';
        mkdir($workerDir, 0777, true);
        file_put_contents($workerDir . '/cmdline', "php-fpm: pool www");

        $signaled = [];
        $service = $this->makeService(
            processorPidGlob: $this->tmpRoot . '/none/*.pid',
            fpmCandidates: [$this->tmpRoot . '/no-such.pid'],
            procCmdlineGlob: $this->tmpRoot . '/proc/[0-9]*/cmdline',
            signaler: $this->recordingSignaler($signaled, returns: true)
        );

        $result = $service->restart();

        $this->assertSame(4242, $result['fpm_master_pid']);
        $this->assertTrue($result['fpm_reloaded']);
    }

    public function testFpmReloadFalseWhenSignalingFailsEvenIfPidFound(): void
    {
        $fpmPidPath = $this->tmpRoot . '/php-fpm.pid';
        file_put_contents($fpmPidPath, "777");

        // Signaler succeeds for the alive-probe (signal 0) but fails for SIGUSR2 —
        // simulates "PID file present, process exists, but we lack permission
        // to signal it". The PID still surfaces in the result so callers can
        // log it; fpm_reloaded reflects the reality that the kick didn't land.
        $signaler = function (int $pid, int $sig): bool {
            if ($sig === 0) return true;        // alive probe
            if ($sig === SIGUSR2) return false; // permission denied
            return true;
        };

        $service = $this->makeService(
            processorPidGlob: $this->tmpRoot . '/none/*.pid',
            fpmCandidates: [$fpmPidPath],
            signaler: $signaler
        );

        $result = $service->restart();

        $this->assertSame(777, $result['fpm_master_pid']);
        $this->assertFalse($result['fpm_reloaded']);
    }

    public function testReturnShapeIsAlwaysComplete(): void
    {
        $service = $this->makeService(
            processorPidGlob: $this->tmpRoot . '/none/*.pid',
            fpmCandidates: [],
            signaler: fn(int $pid, int $sig): bool => false
        );

        $result = $service->restart();

        // Stable contract for the GUI/CLI. Order doesn't matter; presence does.
        $this->assertArrayHasKey('processors_terminated', $result);
        $this->assertArrayHasKey('fpm_reloaded', $result);
        $this->assertArrayHasKey('fpm_master_pid', $result);
        $this->assertArrayHasKey('planned_flag_written', $result);
        $this->assertIsInt($result['processors_terminated']);
        $this->assertIsBool($result['fpm_reloaded']);
        $this->assertIsBool($result['planned_flag_written']);
        $this->assertTrue($result['fpm_master_pid'] === null || is_int($result['fpm_master_pid']));
    }

    public function testPlannedRestartFlagIsWrittenWithCurrentTimestamp(): void
    {
        $flagPath = $this->tmpRoot . '/planned-restart.flag';
        $service = $this->makeService(
            processorPidGlob: $this->tmpRoot . '/none/*.pid',
            fpmCandidates: [],
            plannedRestartFlag: $flagPath,
            signaler: fn(int $pid, int $sig): bool => false
        );

        $before = time();
        $result = $service->restart();
        $after = time();

        $this->assertTrue($result['planned_flag_written']);
        $this->assertFileExists($flagPath);
        $written = (int) file_get_contents($flagPath);
        // Stamp should be within the call window — watchdog uses this to
        // judge freshness, so it must be a recent epoch.
        $this->assertGreaterThanOrEqual($before, $written);
        $this->assertLessThanOrEqual($after, $written);
    }

    public function testPlannedRestartFlagIsWrittenBeforeProcessorsAreSignaled(): void
    {
        // Critical ordering requirement: the watchdog must see the flag
        // BEFORE it discovers the SIGTERM'd processors, otherwise it logs
        // them as "died" (the original bug). We capture the order of writes
        // and signals to confirm.
        $flagPath = $this->tmpRoot . '/planned-restart.flag';
        $procDir = $this->tmpRoot . '/processors';
        mkdir($procDir, 0777, true);
        file_put_contents($procDir . '/p2p.pid', "1234");

        $events = [];
        $writer = function (string $path, string $contents) use (&$events): bool {
            $events[] = ['type' => 'write', 'path' => $path];
            return @file_put_contents($path, $contents) !== false;
        };
        $signaler = function (int $pid, int $signal) use (&$events): bool {
            $events[] = ['type' => 'signal', 'pid' => $pid, 'signal' => $signal];
            return true;
        };

        $service = $this->makeService(
            processorPidGlob: $procDir . '/*.pid',
            fpmCandidates: [],
            plannedRestartFlag: $flagPath,
            signaler: $signaler,
            writer: $writer
        );

        $service->restart();

        $this->assertNotEmpty($events);
        $this->assertSame('write', $events[0]['type'], 'Planned-restart flag must be written before any signal');
        $this->assertSame($flagPath, $events[0]['path']);
    }

    public function testPlannedFlagWriteFailureDoesNotAbortRestart(): void
    {
        // If /tmp is read-only or the writer throws, we should still
        // SIGTERM processors and reload FPM — the friendly logging is a
        // nice-to-have, not a precondition for the restart itself.
        $procDir = $this->tmpRoot . '/processors';
        mkdir($procDir, 0777, true);
        file_put_contents($procDir . '/p2p.pid', "1234");

        $service = $this->makeService(
            processorPidGlob: $procDir . '/*.pid',
            fpmCandidates: [],
            plannedRestartFlag: $this->tmpRoot . '/cannot-write.flag',
            signaler: $this->recordingSignaler($_, returns: true),
            writer: fn(string $p, string $c): bool => false  // simulate write failure
        );

        $result = $service->restart();

        $this->assertFalse($result['planned_flag_written']);
        $this->assertSame(1, $result['processors_terminated']);
    }

    // -- Helpers ------------------------------------------------------------

    private function makeService(
        string $processorPidGlob,
        array $fpmCandidates,
        ?string $procCmdlineGlob = null,
        ?string $plannedRestartFlag = null,
        ?callable $signaler = null,
        ?callable $reader = null,
        ?callable $writer = null
    ): NodeRestartService {
        // Default the flag path into the test tmp dir so tests don't write
        // to /tmp/eiou_planned_restart.flag and pollute concurrent runs.
        return new NodeRestartService(
            $processorPidGlob,
            $fpmCandidates,
            $procCmdlineGlob ?? '/proc/[0-9]*/cmdline',
            $plannedRestartFlag ?? ($this->tmpRoot . '/planned-restart.flag'),
            $signaler,
            $reader,
            $writer
        );
    }

    /**
     * Returns a signaler that records every (pid, signal) call.
     *
     * @param array $sink     Reference parameter — call log appended here.
     * @param bool  $returns  Value to return from each call.
     */
    private function recordingSignaler(&$sink, bool $returns = true): callable
    {
        $sink = [];
        return function (int $pid, int $signal) use (&$sink, $returns): bool {
            $sink[] = ['pid' => $pid, 'signal' => $signal];
            return $returns;
        };
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) return;
        $items = array_diff(scandir($path), ['.', '..']);
        foreach ($items as $item) {
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
