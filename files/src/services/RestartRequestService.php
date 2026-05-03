<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

/**
 * Restart Request Service
 *
 * GUI/API hook for `eiou restart`. The PHP-FPM workers serving HTTP requests
 * run as www-data and cannot signal the root-owned PHP-FPM master process,
 * so we can't call NodeRestartService directly from a web request — the
 * SIGUSR2 to FPM would silently fail.
 *
 * Instead, this service writes a request marker file that a tiny polling
 * loop in startup.sh (running as root, since it's spawned by PID 1) picks
 * up and turns into an `eiou restart` invocation. Same one-way pattern
 * already used for /tmp/tor-restart-requested.
 *
 * Marker schema:
 *   {
 *     "ts": <unix epoch>,         // when the request was written
 *     "source": "gui"|"api"|"cli",// who asked
 *     "requestor": "<id>"         // free-form (e.g. wallet pubkey hash)
 *   }
 *
 * The poller deletes the file as soon as it acts on it. A restart is rate
 * limited at the poller side, so repeated requests don't trigger thrashing.
 */
class RestartRequestService
{
    public const REQUEST_FILE = '/tmp/eiou_restart_requested';

    private const VALID_SOURCES = ['gui', 'api', 'cli'];

    private string $requestFile;

    /** @var callable(string $path, string $contents): bool */
    private $writer;

    public function __construct(
        ?string $requestFile = null,
        ?callable $writer = null
    ) {
        $this->requestFile = $requestFile ?? self::REQUEST_FILE;
        $this->writer = $writer ?? function (string $path, string $contents): bool {
            // World-readable so the watchdog (root) can read what www-data
            // wrote. Marker contents are non-sensitive metadata.
            $bytes = @file_put_contents($path, $contents);
            if ($bytes !== false) {
                @chmod($path, 0644);
            }
            return $bytes !== false;
        };
    }

    /**
     * Write the request marker. Returns true on success.
     *
     * @param string $source     'gui' | 'api' | 'cli' — caller category
     * @param string $requestor  Free-form identifier (logged for audit)
     */
    public function request(string $source, string $requestor = ''): bool
    {
        if (!in_array($source, self::VALID_SOURCES, true)) {
            return false;
        }

        $payload = json_encode([
            'ts' => time(),
            'source' => $source,
            'requestor' => $requestor,
        ]);

        return ($this->writer)($this->requestFile, (string) $payload);
    }

    /**
     * Whether a restart request is currently pending pickup.
     * Mostly useful for tests and status displays.
     */
    public function isRequested(): bool
    {
        return is_file($this->requestFile);
    }
}
