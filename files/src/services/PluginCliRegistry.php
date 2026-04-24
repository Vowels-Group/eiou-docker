<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Cli\CliOutputManager;
use Eiou\Core\ErrorCodes;
use InvalidArgumentException;
use Throwable;

/**
 * Plugin CLI Registry
 *
 * Lets plugins register their own top-level `eiou` subcommand (e.g.
 * `eiou myplugin status`). The CLI entry (root/cli/Eiou.php) walks its
 * own match chain for core commands first; if none match, it consults
 * this registry. Plugins register during boot() via
 * `$container->getPluginCliRegistry()->register('myplugin', fn(...) => ...)`.
 *
 * Reserved-name guard: plugin commands that collide with a core command
 * would be silently shadowed by the CLI's elseif chain (core always wins).
 * To surface the mistake loudly, register() rejects reserved names at the
 * time of registration with an InvalidArgumentException — the plugin's
 * boot() throws, PluginLoader disables it, and the operator sees the real
 * reason in the failed-plugin modal instead of a ghost subcommand.
 *
 * Handler contract:
 *   fn(array $argv, CliOutputManager $output): void
 * where $argv is the full `eiou <cmd> [args...]` argv. The plugin is
 * responsible for parsing any further subcommands out of $argv[2+].
 */
class PluginCliRegistry
{
    /**
     * Core commands that plugins cannot shadow. Kept in sync with the
     * match chain in `files/root/cli/Eiou.php`. Adding a new core
     * command? Add it here too so plugin authors get a clear error if
     * they pick a colliding name.
     */
    private const RESERVED = [
        'generate', 'info', 'add', 'viewcontact', 'update', 'block',
        'unblock', 'delete', 'search', 'ping', 'send', 'viewbalances',
        'history', 'pending', 'overview', 'p2p', 'dlq', 'help',
        'viewsettings', 'changesettings', 'sync', 'out', 'in',
        'shutdown', 'start', 'restart', 'plugin', 'apikey', 'backup',
        'verify-chain', 'chaindrop', 'request', 'report', 'updatecheck',
        'payback',
    ];

    /** @var array<string, callable> */
    private array $handlers = [];

    /**
     * Register a plugin-owned CLI subcommand.
     *
     * Throws when the name collides with a core command or has already
     * been registered — both are programmer errors that should surface
     * immediately, not at dispatch time.
     */
    public function register(string $name, callable $handler): void
    {
        $name = strtolower(trim($name));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9-]{0,31}$/', $name)) {
            throw new InvalidArgumentException(
                "Plugin CLI command name must be kebab-case, 1-32 chars, start with a letter: got '{$name}'"
            );
        }
        if (in_array($name, self::RESERVED, true)) {
            throw new InvalidArgumentException(
                "Plugin CLI command '{$name}' collides with a core command. Pick a different name."
            );
        }
        if (isset($this->handlers[$name])) {
            throw new InvalidArgumentException(
                "Plugin CLI command '{$name}' is already registered — each name can only be taken once."
            );
        }
        $this->handlers[$name] = $handler;
    }

    public function has(string $name): bool
    {
        return isset($this->handlers[strtolower($name)]);
    }

    /**
     * Dispatch a registered plugin command. Catches handler exceptions so
     * a buggy plugin can't tear down the CLI process; the operator sees
     * a clean error instead of a stack trace.
     */
    public function dispatch(string $name, array $argv, CliOutputManager $output): void
    {
        $name = strtolower($name);
        if (!isset($this->handlers[$name])) {
            $output->error("Unknown command: {$name}", ErrorCodes::COMMAND_NOT_FOUND, 404);
            return;
        }
        try {
            ($this->handlers[$name])($argv, $output);
        } catch (Throwable $e) {
            $output->error(
                "Plugin command '{$name}' failed: " . $e->getMessage(),
                ErrorCodes::GENERAL_ERROR,
                500
            );
        }
    }

    /**
     * @return list<string> names in registration order
     */
    public function listRegistered(): array
    {
        return array_keys($this->handlers);
    }
}
