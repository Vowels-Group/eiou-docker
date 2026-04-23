<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Plugins\HelloEiou;

use Eiou\Contracts\PluginInterface;
use Eiou\Services\ServiceContainer;
use Eiou\Events\EventDispatcher;
use Eiou\Events\SyncEvents;
use Eiou\Utils\Logger;

/**
 * Hello eIOU
 *
 * In the spirit of Matt Mullenweg's Hello Dolly — the ~80-line WordPress plugin
 * that ships a random "Hello, Dolly!" lyric in the admin header — this plugin
 * exists to be the smallest possible demonstration of the eIOU plugin API.
 *
 * It does one thing: every time a sync completes, it logs a random eIOU
 * fortune-cookie line. No new core events, no new services, no extra config —
 * just discovery, lifecycle, and an event subscription.
 */
class HelloEiouPlugin implements PluginInterface
{
    /**
     * Fortune lines, in the spirit of Hello Dolly's lyrics.
     */
    private const FORTUNES = [
        "An eIOU paid is a friendship maintained.",
        "Trust travels in both directions on every chain.",
        "A balanced ledger is a quiet ledger.",
        "Today's IOU is tomorrow's reciprocation.",
        "Every sync brings two wallets closer to truth.",
        "The shortest path between two debts is forgiveness.",
        "A node that remembers is a node that pays back.",
        "No hash mismatch survives a careful conversation.",
        "Settle small, settle often, sleep well.",
        "An empty pending queue is a happy pending queue.",
        "The chain you keep honest keeps you honest.",
        "A handshake is just an IOU with better marketing.",
        "Some debts are paid in money. Better ones in trust.",
        "A reconciled balance is a small kind of peace.",
        "Hello, ledger! Well hello, ledger! It's so nice to have you back where you belong.",
    ];

    public function getName(): string
    {
        return 'hello-eiou';
    }

    public function getVersion(): string
    {
        return '1.1.0';
    }

    /**
     * Nothing to register — Hello eIOU adds no services or repositories.
     * Pure event consumer.
     */
    public function register(ServiceContainer $container): void
    {
        // intentionally empty
    }

    /**
     * Subscribe to sync.completed and log a fortune on each event.
     */
    public function boot(ServiceContainer $container): void
    {
        EventDispatcher::getInstance()->subscribe(
            SyncEvents::SYNC_COMPLETED,
            function (array $data): void {
                $fortune = self::FORTUNES[array_rand(self::FORTUNES)];
                Logger::getInstance()->info("[hello-eiou] {$fortune}", [
                    'plugin' => 'hello-eiou',
                    'event' => SyncEvents::SYNC_COMPLETED,
                    'contact_pubkey' => $data['contact_pubkey'] ?? null,
                ]);
            }
        );

        // Register a CLI subcommand `eiou hello-eiou [fortune]` so operators
        // can pull a fortune on demand. Demonstrates PluginCliRegistry — a
        // real plugin would parse $argv[2+] for richer subcommands.
        $container->getPluginCliRegistry()->register('hello-eiou',
            function (array $argv, \Eiou\Cli\CliOutputManager $output): void {
                $fortune = self::FORTUNES[array_rand(self::FORTUNES)];
                $output->success($fortune, ['fortune' => $fortune]);
            }
        );

        // Register a REST endpoint `GET /api/v1/plugins/hello-eiou/fortune`.
        // Demonstrates PluginApiRegistry — handler returns a plain array
        // and the registry wraps it in the standard successResponse shape.
        $container->getPluginApiRegistry()->register('hello-eiou', 'GET', 'fortune',
            function (string $method, array $params, string $body): array {
                $fortune = self::FORTUNES[array_rand(self::FORTUNES)];
                return ['fortune' => $fortune];
            }
        );
    }
}
