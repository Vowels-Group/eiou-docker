<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Processors;

use Eiou\Core\Application;
use Eiou\Core\Constants;
use Eiou\Database\P2pRepository;
use Eiou\Utils\AddressValidator;
use Eiou\Utils\Logger;

/**
 * P2P Message Processor (Coordinator)
 *
 * Coordinator daemon that polls for queued P2Ps and spawns independent worker
 * processes (P2pWorker.php) for each one. Workers are fully isolated with their
 * own Application singleton, PDO connection, and curl_multi handle.
 *
 * Worker limits are per-transport: HTTP/HTTPS get a higher ceiling than Tor,
 * because Tor circuits are the bottleneck (each hidden service connection
 * creates 6 Tor hops). The coordinator tracks active workers by transport
 * and enforces limits independently.
 *
 * Responsibilities:
 * - Dispatch: fetch queued P2Ps, spawn workers up to per-transport limits
 * - Reap: check proc_get_status() on active workers, remove finished ones
 * - Recover: find stuck 'sending' P2Ps with dead worker PIDs, reset to 'queued'
 * - Shutdown: SIGTERM to all workers, wait up to 10s, force-kill
 */
class P2pMessageProcessor extends AbstractMessageProcessor {
    private $p2pService;

    /**
     * @var P2pRepository P2P repository for querying queued messages
     */
    private P2pRepository $p2pRepository;

    /**
     * @var array Active workers: pid => ['hash' => string, 'process' => resource, 'started_at' => float, 'pipes' => array, 'transport' => string]
     */
    private array $activeWorkers = [];

    /**
     * @var string Path to the P2pWorker.php entry point
     */
    private string $workerScript;

    /**
     * @var float Last time stuck-sending recovery was performed
     */
    private float $lastRecoveryTime = 0;

    /**
     * @var int Seconds between stuck-sending recovery checks
     */
    private int $recoveryInterval = 60;

    /**
     * @var array|null Override max workers per transport (for testing)
     */
    private ?array $maxWorkersOverride = null;

    /**
     * Constructor
     *
     * @param array $pollerConfig Configuration for adaptive polling
     * @param string $lockfile Path to lockfile (default: /tmp/p2pmessages_lock.pid)
     */
    public function __construct(array $pollerConfig = null, string $lockfile = null) {
        // Default configuration for P2P (fast polling)
        if ($pollerConfig === null) {
            $pollerConfig = [
                'min_interval_ms' => Constants::P2P_MIN_INTERVAL_MS ?: 100,  // 100ms
                'max_interval_ms' => Constants::P2P_MAX_INTERVAL_MS ?: 5000, // 5 seconds
                'idle_interval_ms' => Constants::P2P_IDLE_INTERVAL_MS ?: 2000, // 2 seconds
                'adaptive' => Constants::P2P_ADAPTIVE_POLLING !== 'false',
            ];
        }

        if ($lockfile === null) {
            $lockfile = '/tmp/p2pmessages_lock.pid';
        }

        // P2P logs every minute (60 seconds)
        parent::__construct($pollerConfig, $lockfile, 60);

        $app = Application::getInstance();
        $this->p2pService = $app->services->getP2pService();
        $this->p2pRepository = new P2pRepository();
        $this->workerScript = '/app/eiou/processors/P2pWorker.php';
        $this->lastRecoveryTime = microtime(true);
    }

    /**
     * Set the P2P repository (for testing)
     *
     * @param P2pRepository $repository
     */
    public function setP2pRepository(P2pRepository $repository): void {
        $this->p2pRepository = $repository;
    }

    /**
     * Set the worker script path (for testing)
     *
     * @param string $path
     */
    public function setWorkerScript(string $path): void {
        $this->workerScript = $path;
    }

    /**
     * Set max workers per transport (for testing)
     *
     * @param array $limits ['http' => int, 'https' => int, 'tor' => int]
     */
    public function setMaxWorkers(array $limits): void {
        $this->maxWorkersOverride = $limits;
    }

    /**
     * Get active workers count (for testing/monitoring)
     *
     * @return int Total active workers across all transports
     */
    public function getActiveWorkerCount(): int {
        return count($this->activeWorkers);
    }

    /**
     * Get active worker count for a specific transport (for testing/monitoring)
     *
     * @param string $transport Transport protocol ('http', 'https', 'tor')
     * @return int Active workers for that transport
     */
    public function getActiveWorkerCountByTransport(string $transport): int {
        $count = 0;
        foreach ($this->activeWorkers as $worker) {
            if ($worker['transport'] === $transport) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get the max workers limit for a transport
     *
     * @param string $transport Transport protocol
     * @return int Max workers allowed
     */
    protected function getMaxWorkersForTransport(string $transport): int {
        if ($this->maxWorkersOverride !== null && isset($this->maxWorkersOverride[$transport])) {
            return $this->maxWorkersOverride[$transport];
        }
        return Constants::getMaxP2pWorkers($transport);
    }

    /**
     * Determine the transport type for a P2P message
     *
     * @param array $message P2P message row
     * @return string Transport type ('http', 'https', 'tor')
     */
    protected function getMessageTransport(array $message): string {
        $address = $message['sender_address'] ?? '';
        $transport = AddressValidator::getTransportType($address);
        return $transport ?? Constants::getDefaultTransportMode();
    }

    /**
     * Process queued P2P messages by spawning workers
     *
     * Called each poll cycle by the AbstractMessageProcessor run loop.
     *
     * @return int Number of workers spawned this cycle
     */
    protected function processMessages(): int {
        // Step 1: Reap finished workers
        $this->reapWorkers();

        // Step 2: Recover stuck sending P2Ps (every recoveryInterval seconds)
        $now = microtime(true);
        if ($now - $this->lastRecoveryTime >= $this->recoveryInterval) {
            $this->recoverStuckP2ps();
            $this->lastRecoveryTime = $now;
        }

        // Step 3: Dispatch new workers for queued P2Ps
        return $this->dispatchWorkers();
    }

    /**
     * Check active workers and remove finished ones
     */
    protected function reapWorkers(): void {
        foreach ($this->activeWorkers as $pid => $worker) {
            $status = proc_get_status($worker['process']);
            if (!$status['running']) {
                // Close pipes
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($worker['process']);
                unset($this->activeWorkers[$pid]);

                $exitCode = $status['exitcode'];
                $duration = round(microtime(true) - $worker['started_at'], 2);

                if ($exitCode !== 0) {
                    Logger::getInstance()->warning("P2P worker exited with error", [
                        'pid' => $pid,
                        'hash' => $worker['hash'],
                        'transport' => $worker['transport'],
                        'exit_code' => $exitCode,
                        'duration_s' => $duration,
                    ]);
                }
            }
        }
    }

    /**
     * Find P2Ps stuck in 'sending' with dead workers and reset to 'queued'
     */
    protected function recoverStuckP2ps(): void {
        $stuckP2ps = $this->p2pRepository->getStuckSendingP2ps();
        foreach ($stuckP2ps as $p2p) {
            $recovered = $this->p2pRepository->recoverStuckP2p($p2p['hash']);
            if ($recovered) {
                Logger::getInstance()->info("Recovered stuck P2P", [
                    'hash' => $p2p['hash'],
                    'worker_pid' => $p2p['sending_worker_pid'] ?? 'unknown',
                ]);
            }
        }
    }

    /**
     * Fetch queued P2Ps and spawn workers for each (respecting per-transport limits)
     *
     * @return int Number of workers spawned
     */
    protected function dispatchWorkers(): int {
        // Fetch a generous batch — we'll filter by transport capacity
        $queuedMessages = $this->p2pRepository->getQueuedP2pMessages(
            Constants::STATUS_QUEUED,
            Constants::P2P_QUEUE_BATCH_SIZE
        );

        $spawned = 0;
        foreach ($queuedMessages as $message) {
            $transport = $this->getMessageTransport($message);
            $maxForTransport = $this->getMaxWorkersForTransport($transport);
            $activeForTransport = $this->getActiveWorkerCountByTransport($transport);

            if ($activeForTransport >= $maxForTransport) {
                continue; // This transport is at capacity, skip to next message
            }

            $hash = $message['hash'];
            if ($this->spawnWorker($hash, $transport)) {
                $spawned++;
            }
        }

        return $spawned;
    }

    /**
     * Spawn a worker process for a single P2P hash
     *
     * @param string $hash P2P hash to process
     * @param string $transport Transport type for this P2P
     * @return bool True if worker spawned successfully
     */
    protected function spawnWorker(string $hash, string $transport): bool {
        // exec replaces the shell with PHP directly (1 PID per worker instead of 2)
        $cmd = 'exec php ' . escapeshellarg($this->workerScript) . ' ' . escapeshellarg($hash);

        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            Logger::getInstance()->error("Failed to spawn P2P worker", ['hash' => $hash]);
            return false;
        }

        // Make stdout/stderr non-blocking so we don't hang on reap
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $status = proc_get_status($process);
        $pid = $status['pid'];

        $this->activeWorkers[$pid] = [
            'hash' => $hash,
            'process' => $process,
            'started_at' => microtime(true),
            'pipes' => $pipes,
            'transport' => $transport,
        ];

        return true;
    }

    /**
     * Shutdown hook — terminate all active workers
     */
    protected function onShutdown(): void {
        if (empty($this->activeWorkers)) {
            return;
        }

        $workerCount = count($this->activeWorkers);
        echo "[" . date(Constants::DISPLAY_DATE_FORMAT) . "] ";
        echo "Terminating {$workerCount} active P2P workers...\n";

        // Send SIGTERM to all workers
        foreach ($this->activeWorkers as $pid => $worker) {
            posix_kill($pid, SIGTERM);
        }

        // Wait up to 10 seconds for workers to exit
        $deadline = microtime(true) + 10;
        while (!empty($this->activeWorkers) && microtime(true) < $deadline) {
            $this->reapWorkers();
            if (!empty($this->activeWorkers)) {
                usleep(100000); // 100ms
            }
        }

        // Force-kill any remaining workers
        foreach ($this->activeWorkers as $pid => $worker) {
            posix_kill($pid, SIGKILL);
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($worker['process']);

            Logger::getInstance()->warning("Force-killed P2P worker", [
                'pid' => $pid,
                'hash' => $worker['hash'],
            ]);
        }
        $this->activeWorkers = [];
    }

    /**
     * Get the processor name for logging
     *
     * @return string "P2P"
     */
    protected function getProcessorName(): string {
        return 'P2P';
    }
}
