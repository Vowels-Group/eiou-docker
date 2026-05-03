<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

/**
 * Node Restart Service
 *
 * Encapsulates the in-place restart flow used by `eiou restart` (and any
 * future GUI/API hook). Three side effects, in order:
 *
 *   1. Touch /tmp/eiou_planned_restart.flag with the current timestamp.
 *      The watchdog (in startup.sh) reads this flag so its log line for
 *      the about-to-die processors says "respawning after planned restart"
 *      instead of "died (...)" with an alarming attempt counter. Without
 *      the flag, a deliberate restart looks like a crash in `docker logs`.
 *
 *   2. SIGTERM each background processor PID. The watchdog respawns them
 *      within ~30s with fresh state — fresh service container, fresh
 *      EventDispatcher listener list, fresh PluginLoader.
 *
 *   3. SIGUSR2 the PHP-FPM master, which gracefully recycles all worker
 *      processes. In-flight HTTP requests finish before the worker exits.
 *
 * All filesystem paths and the `posix_kill` callable are injected so tests
 * can drive the logic without sending real signals or touching /tmp.
 */
class NodeRestartService
{
    public const PLANNED_RESTART_FLAG = '/tmp/eiou_planned_restart.flag';

    /** Glob pattern matching processor PID files. */
    private string $processorPidGlob;

    /** Ordered list of paths to check for the PHP-FPM master PID file. */
    private array $fpmPidFileCandidates;

    /** Glob pattern for the cmdline files under /proc (for the FPM fallback scan). */
    private string $procCmdlineGlob;

    /** Path to the planned-restart marker the watchdog reads. */
    private string $plannedRestartFlag;

    /** @var callable(int $pid, int $signal): bool */
    private $signaler;

    /** @var callable(string $path): (string|false) */
    private $reader;

    /** @var callable(string $path, string $contents): bool */
    private $writer;

    /**
     * @param string $processorPidGlob       Default '/tmp/*.pid'
     * @param array  $fpmPidFileCandidates   Default Debian/Ubuntu PHP-FPM paths
     * @param string $procCmdlineGlob        Default scans /proc cmdline files
     * @param string|null $plannedRestartFlag Default self::PLANNED_RESTART_FLAG
     * @param callable|null $signaler        (int,int)→bool, default posix_kill
     * @param callable|null $reader          (string)→string|false, default file_get_contents
     * @param callable|null $writer          (string,string)→bool, default file_put_contents
     */
    public function __construct(
        string $processorPidGlob = '/tmp/*.pid',
        ?array $fpmPidFileCandidates = null,
        string $procCmdlineGlob = '/proc/[0-9]*/cmdline',
        ?string $plannedRestartFlag = null,
        ?callable $signaler = null,
        ?callable $reader = null,
        ?callable $writer = null
    ) {
        $this->processorPidGlob = $processorPidGlob;
        $this->fpmPidFileCandidates = $fpmPidFileCandidates ?? [
            '/run/php/php*-fpm.pid',      // Debian/Ubuntu, version-suffixed
            '/var/run/php/php*-fpm.pid',  // older symlink path
            '/run/php-fpm.pid',           // generic
            '/var/run/php-fpm.pid',       // generic, older path
        ];
        $this->procCmdlineGlob = $procCmdlineGlob;
        $this->plannedRestartFlag = $plannedRestartFlag ?? self::PLANNED_RESTART_FLAG;
        $this->signaler = $signaler ?? function (int $pid, int $signal): bool {
            return @posix_kill($pid, $signal);
        };
        $this->reader = $reader ?? function (string $path) {
            return @file_get_contents($path);
        };
        $this->writer = $writer ?? function (string $path, string $contents): bool {
            return @file_put_contents($path, $contents) !== false;
        };
    }

    /**
     * Run the restart sequence.
     *
     * @return array{processors_terminated:int, fpm_reloaded:bool, fpm_master_pid:?int, planned_flag_written:bool}
     */
    public function restart(): array
    {
        // Write the planned-restart flag FIRST so the watchdog sees it before
        // it discovers the SIGTERM'd processors and starts logging "died".
        // The watchdog clears the flag once it's used so the marker doesn't
        // mask a real crash on the next monitoring cycle.
        $plannedFlagWritten = ($this->writer)(
            $this->plannedRestartFlag,
            (string) time()
        );

        $processorsTerminated = $this->terminateProcessors();
        $fpmPid = $this->findPhpFpmMasterPid();
        $fpmReloaded = $fpmPid !== null && ($this->signaler)($fpmPid, SIGUSR2);

        return [
            'processors_terminated' => $processorsTerminated,
            'fpm_reloaded' => $fpmReloaded,
            'fpm_master_pid' => $fpmPid,
            'planned_flag_written' => $plannedFlagWritten,
        ];
    }

    private function terminateProcessors(): int
    {
        $count = 0;
        $pidFiles = glob($this->processorPidGlob) ?: [];
        foreach ($pidFiles as $pidFile) {
            if (!is_file($pidFile)) {
                continue;
            }
            $contents = ($this->reader)($pidFile);
            if ($contents === false) {
                continue;
            }
            $pid = (int) trim($contents);
            // Don't unlink the PID file — the respawned processor will
            // overwrite it. Removing it can race with the new process's
            // own PID-file write.
            if ($pid > 0 && ($this->signaler)($pid, SIGTERM)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Locate the PHP-FPM master process PID. Tries each candidate path (which
     * may contain glob wildcards), then falls back to a /proc cmdline scan.
     * Returns null if nothing was found or the located PID is dead.
     */
    public function findPhpFpmMasterPid(): ?int
    {
        $expanded = [];
        foreach ($this->fpmPidFileCandidates as $candidate) {
            if (strpbrk($candidate, '*?[') !== false) {
                $expanded = array_merge($expanded, glob($candidate) ?: []);
            } else {
                $expanded[] = $candidate;
            }
        }

        foreach ($expanded as $path) {
            if (!is_file($path)) {
                continue;
            }
            $contents = ($this->reader)($path);
            if ($contents === false) {
                continue;
            }
            $pid = (int) trim($contents);
            if ($pid > 0 && $this->isProcessAlive($pid)) {
                return $pid;
            }
        }

        // Fallback: scan /proc cmdline for "php-fpm: master process"
        $procs = glob($this->procCmdlineGlob) ?: [];
        foreach ($procs as $procPath) {
            $cmdline = ($this->reader)($procPath);
            if ($cmdline !== false && strpos($cmdline, 'php-fpm: master process') !== false) {
                if (preg_match('#/(\d+)/cmdline$#', $procPath, $m)) {
                    return (int) $m[1];
                }
            }
        }

        return null;
    }

    /**
     * posix_kill with signal 0 doesn't actually send a signal — it just
     * checks whether the process exists and we can signal it. Standard
     * POSIX idiom for "is this PID alive".
     */
    private function isProcessAlive(int $pid): bool
    {
        return $pid > 0 && ($this->signaler)($pid, 0);
    }
}
