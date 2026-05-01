<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Plugins\HelloEiou;

use Eiou\Contracts\PluginInterface;
use Eiou\Services\ServiceContainer;
use Eiou\Services\GuiActionRegistry;
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
        return '1.2.0';
    }

    /**
     * Pure helper — pulled out so every surface (CLI, REST, GUI
     * action, render hook) calls into the same source instead of
     * each one re-implementing FORTUNES selection.
     */
    private function pickFortune(): string
    {
        return self::FORTUNES[array_rand(self::FORTUNES)];
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
                return ['fortune' => $this->pickFortune()];
            }
        );

        // ---------------------------------------------------------------------
        // GUI hook surface — demonstrates each registry added in the
        // plugin-GUI-hooks branch. See docs/PLUGIN_GUI_HOOKS.md.
        // ---------------------------------------------------------------------
        $hooks   = $container->getHooks();
        $assets  = $container->getAssetRegistry();
        $tabs    = $container->getTabRegistry();
        $actions = $container->getActionRegistry();

        // Phase 2 — own CSS for the dashboard widget. Lives next to the
        // plugin's PHP under assets/. Inline-rendered with the page CSP
        // nonce because it's smaller than the URL-mode threshold.
        $assets->enqueueStyle('hello-eiou', 'assets/styles.css');

        // Phase 1 — render a fortune widget on the dashboard. Uses
        // `gui.dashboard.after` (pure render hook). Each request picks
        // a fresh fortune so reloads change the line.
        $hooks->onRender('gui.dashboard.after', function (): string {
            $fortune = htmlspecialchars($this->pickFortune(), ENT_QUOTES);
            return '<section class="plugin-hello-eiou-widget">'
                 . '<h3><i class="fas fa-cookie-bite"></i> Fortune</h3>'
                 . '<p>' . $fortune . '</p>'
                 . '</section>';
        });

        // Phase 3 — register a top-level "Fortunes" tab. Uses a render
        // callable instead of an include path so the plugin doesn't
        // need a separate template file. The tab slots between
        // Activity (40) and Settings (50).
        //
        // The render callback wraps its body in renderSection() so
        // the tab visually matches every core section (form-container
        // chrome, h2 underline, About-this-section disclosure) and
        // automatically gains the gui.section.before.hello-eiou-fortunes
        // / gui.section.after.hello-eiou-fortunes hooks. The cream-
        // card list-item styling lives in assets/styles.css under the
        // .plugin-hello-eiou-tab namespace and stays where it is.
        $tabs->register([
            'id'     => 'hello-eiou-fortunes',
            'label'  => 'Fortunes',
            'icon'   => 'fas fa-cookie-bite',
            'order'  => 45,
            'render' => function (): string {
                $list = '';
                foreach (self::FORTUNES as $f) {
                    $list .= '<li>' . htmlspecialchars($f, ENT_QUOTES) . '</li>';
                }
                return renderSection([
                    'id'    => 'hello-eiou-fortunes',
                    'icon'  => 'fas fa-cookie-bite',
                    'title' => 'Fortunes',
                    'introTitle' => 'About these fortunes',
                    'intro' =>
                          'A demo of the eIOU plugin GUI surface. The cream-card list '
                        . 'items are styled by <code>assets/styles.css</code> (enqueued '
                        . 'via the plugin asset registry); the wrapper, underline, and '
                        . 'disclosure come from the host\'s <code>renderSection()</code> '
                        . 'helper, so this tab visually matches every core section. '
                        . 'See <code>files/plugins/hello-eiou/src/HelloEiouPlugin.php</code> '
                        . 'for the full reference.',
                    'body'  => '<div class="plugin-hello-eiou-tab"><ul>' . $list . '</ul></div>',
                ]);
            },
        ]);

        // Phase 4 — register a POST action plugins / GUI buttons can
        // hit. Returns JSON; tier is `csrf` so Functions.php enforces
        // a valid CSRF token before invoking the handler.
        $actions->register('helloEiouFortune', function (array $request): void {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'fortune' => $this->pickFortune(),
            ]);
        }, GuiActionRegistry::TIER_CSRF, 'hello-eiou');

        // Phase 5 — contribute to two filter slots:
        //   * gui.dashboard.widgets — adds an extra widget after the
        //     core widgets, sorted by order.
        //   * gui.contact.actions   — adds a "Fortune" button to the
        //     contact-modal settings tab that posts to the action
        //     registered above.
        $hooks->onFilter('gui.dashboard.widgets', function (array $widgets): array {
            $widgets[] = [
                'id'    => 'hello-eiou',
                'order' => 200,
                'html'  => '<section class="plugin-hello-eiou-mini">'
                         . '<small>Tip: <em>' . htmlspecialchars($this->pickFortune(), ENT_QUOTES) . '</em></small>'
                         . '</section>',
            ];
            return $widgets;
        });

        $hooks->onFilter('gui.contact.actions', function (array $a): array {
            $a[] = [
                'label'  => 'Fortune',
                'icon'   => 'fas fa-cookie-bite',
                'action' => 'helloEiouFortune',
            ];
            return $a;
        });
    }
}
