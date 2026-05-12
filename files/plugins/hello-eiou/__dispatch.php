<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# hello-eiou — sandboxed dispatcher (Phase 5 migration).
#
# Runs inside the per-plugin FPM pool as eiou-p-<hash>. Cannot read the
# wallet's master key or seed (kernel EACCES + open_basedir). Reaches
# core services only through the Phase 4 gateway via core_call().
#
# Surfaces handled here (declared in plugin.json):
#   subscribes_to:  sync.completed     → log a fortune via Logger.info
#   render_hooks:   gui.dashboard.after → fortune widget HTML
#   filter_hooks:   gui.dashboard.widgets → contribute a mini-tip widget
#   filter_hooks:   gui.contact.actions   → add a Fortune action button
#
# Surfaces NOT yet migrated to sandboxed mode (still served by the
# entry class in src/HelloEiouPlugin.php while sandboxed=false):
#   - Tab register with render → blocked on Phase 5d tab IPC
#   - GUI action helloEiouFortune → blocked on Phase 5c action routing
#   - REST endpoint /v1/plugins/hello-eiou/fortune → blocked on Phase 5e
#   - CLI subcommand → blocked on Phase 5f
#
# See docs/PLUGIN_SANDBOXING.md (Phase 5) and the contract spec
# docs/PLUGIN_SANDBOX_DISPATCH_CONTRACT.md.

declare(strict_types=1);

/** Wire-contract version this dispatcher targets. See
 *  files/src/templates/plugin-dispatch-template.php for the canonical
 *  current version; bump in lockstep when the wire contract evolves. */
const PLUGIN_DISPATCH_VERSION = 1;

header('Content-Type: application/json');

// =============================================================================
// PluginLog — buffer logs into the response's _log array. Same shape
// as the dispatcher template's PluginLog; duplicated rather than
// shared because open_basedir restricts this pool to its own dir.
// =============================================================================
final class PluginLog
{
    /** @var array<int, array{level:string, message:string, context:array}> */
    public array $entries = [];
    public function info(string $m, array $c = []): void    { $this->entries[] = ['level' => 'info', 'message' => $m, 'context' => $c]; }
    public function warning(string $m, array $c = []): void { $this->entries[] = ['level' => 'warning', 'message' => $m, 'context' => $c]; }
    public function error(string $m, array $c = []): void   { $this->entries[] = ['level' => 'error', 'message' => $m, 'context' => $c]; }
    public function debug(string $m, array $c = []): void   { $this->entries[] = ['level' => 'debug', 'message' => $m, 'context' => $c]; }
}

$log = new PluginLog();

function respond(int $status, array $body, PluginLog $log): void
{
    http_response_code($status);
    echo json_encode(array_merge($body, ['_log' => $log->entries]));
    exit;
}

// =============================================================================
// core_call($service, $method, $args, $log) — Phase 4 gateway client.
// =============================================================================
function core_call(string $service, string $method, array $args, PluginLog $log)
{
    static $cachedToken = null;
    if ($cachedToken === null) {
        $tokenPath = __DIR__ . '/.gateway-token';
        if (!is_file($tokenPath)) {
            $log->error('core_call: token file missing');
            return null;
        }
        $cachedToken = trim((string) @file_get_contents($tokenPath));
        if ($cachedToken === '') {
            $cachedToken = null;
            $log->error('core_call: token file empty');
            return null;
        }
    }
    $body = json_encode(['service' => $service, 'method' => $method, 'args' => $args]);
    if ($body === false) {
        $log->error('core_call: encode failed', ['service' => $service, 'method' => $method]);
        return null;
    }
    $ch = curl_init('http://127.0.0.1/__plugin_gateway');
    if ($ch === false) return null;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cachedToken,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 5,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        $log->error('core_call: transport failed', ['service' => $service, 'method' => $method, 'error' => $err]);
        return null;
    }
    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        $log->error('core_call: gateway rejected', [
            'service' => $service, 'method' => $method,
            'error'   => $decoded['error'] ?? null,
        ]);
        return null;
    }
    return $decoded['result'] ?? null;
}

// =============================================================================
// hello-eiou fortunes — kept verbatim from the in-process entry class.
// =============================================================================
final class Fortunes
{
    public const LINES = [
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
    public static function pick(): string
    {
        return self::LINES[array_rand(self::LINES)];
    }
}

// =============================================================================
// Parse + dispatch the request envelope.
// =============================================================================
$rawBody = (string) @file_get_contents('php://input');
if ($rawBody === '') {
    respond(400, ['ok' => false, 'error' => ['code' => 'bad_envelope', 'message' => 'empty body']], $log);
}
$envelope = json_decode($rawBody, true);
if (!is_array($envelope)) {
    respond(400, ['ok' => false, 'error' => ['code' => 'bad_envelope', 'message' => 'not JSON']], $log);
}

$type = (string) ($envelope['type'] ?? '');
$name = (string) ($envelope['name'] ?? '');
$context = is_array($envelope['context'] ?? null) ? $envelope['context'] : [];

switch ($type) {
    case 'event':
        if ($name === 'sync.completed') {
            $fortune = Fortunes::pick();
            $contactPubkey = $context['data']['contact_pubkey'] ?? null;
            // Log via the central wallet logger so the fortune lands in
            // the same log file as in-process behaviour did. Logger.info
            // is tagged #[PluginCallable] (Phase 4) and declared in this
            // plugin's core_services manifest entry.
            core_call('Logger', 'info', [
                "[hello-eiou] {$fortune}",
                ['plugin' => 'hello-eiou', 'event' => 'sync.completed', 'contact_pubkey' => $contactPubkey],
            ], $log);
            // Also push into _log so the forwarder writes a trace line
            // under [hello-eiou] in the central log — handy when an
            // operator is reading both surfaces side-by-side.
            $log->debug('fortune dispatched on sync.completed', ['fortune' => $fortune]);
        }
        respond(200, ['ok' => true, 'result' => null], $log);

    case 'render':
        if ($name === 'gui.dashboard.after') {
            $fortune = htmlspecialchars(Fortunes::pick(), ENT_QUOTES);
            $html = '<section class="plugin-hello-eiou-widget">'
                  . '<h3><i class="fas fa-cookie-bite"></i> Fortune</h3>'
                  . '<p>' . $fortune . '</p>'
                  . '</section>';
            respond(200, ['ok' => true, 'result' => $html], $log);
        }
        // Tab render — forwarder POSTs name="tab:<id>" to indicate the
        // request is for a registered tab's body. The dispatcher
        // returns the HTML the tab pane should display.
        if ($name === 'tab:hello-eiou-fortunes') {
            $items = '';
            foreach (Fortunes::LINES as $f) {
                $items .= '<li>' . htmlspecialchars($f, ENT_QUOTES) . '</li>';
            }
            // renderSection() is a host helper not reachable from the
            // sandboxed pool (it lives in the wallet's PHP namespace),
            // so we hand-roll the same chrome. Matches the legacy
            // entry-class output close enough that the styling carries.
            $html = '<div class="form-container fade-in-up" id="hello-eiou-fortunes">'
                  . '<div class="section-header">'
                  . '<h2><i class="fas fa-cookie-bite"></i> Fortunes</h2>'
                  . '</div>'
                  . '<details class="section-intro text-muted">'
                  . '<summary><i class="fas fa-info-circle"></i> <span>About these fortunes</span></summary>'
                  . '<div class="section-intro-body">A demo of the sandboxed-plugin tab IPC. '
                  . 'This list is rendered by hello-eiou\'s __dispatch.php inside its own '
                  . 'FPM pool, then forwarded to core via a render hook.</div>'
                  . '</details>'
                  . '<div class="plugin-hello-eiou-tab"><ul>' . $items . '</ul></div>'
                  . '</div>';
            respond(200, ['ok' => true, 'result' => $html], $log);
        }
        respond(501, ['ok' => false, 'error' => ['code' => 'handler_not_found', 'message' => "no render handler for {$name}"]], $log);

    case 'filter':
        if ($name === 'gui.dashboard.widgets') {
            $widgets = is_array($context['value'] ?? null) ? $context['value'] : [];
            $widgets[] = [
                'id'    => 'hello-eiou',
                'order' => 200,
                'html'  => '<section class="plugin-hello-eiou-mini">'
                         . '<small>Tip: <em>' . htmlspecialchars(Fortunes::pick(), ENT_QUOTES) . '</em></small>'
                         . '</section>',
            ];
            respond(200, ['ok' => true, 'result' => $widgets], $log);
        }
        if ($name === 'gui.contact.actions') {
            $actions = is_array($context['value'] ?? null) ? $context['value'] : [];
            $actions[] = [
                'label'  => 'Fortune',
                'icon'   => 'fas fa-cookie-bite',
                'action' => 'helloEiouFortune',
            ];
            respond(200, ['ok' => true, 'result' => $actions], $log);
        }
        respond(501, ['ok' => false, 'error' => ['code' => 'handler_not_found', 'message' => "no filter handler for {$name}"]], $log);

    case 'action':
        if ($name === 'helloEiouFortune') {
            respond(200, [
                'ok' => true,
                'result' => [
                    'success' => true,
                    'fortune' => Fortunes::pick(),
                ],
            ], $log);
        }
        respond(501, [
            'ok' => false,
            'error' => [
                'code' => 'handler_not_found',
                'message' => "no action handler for '{$name}'",
            ],
        ], $log);

    case 'rest':
        if ($name === 'fortune') {
            respond(200, [
                'ok' => true,
                'result' => ['fortune' => Fortunes::pick()],
            ], $log);
        }
        respond(501, [
            'ok' => false,
            'error' => [
                'code' => 'handler_not_found',
                'message' => "no REST handler for '{$name}'",
            ],
        ], $log);

    case 'cli':
        if ($name === 'hello-eiou') {
            $fortune = Fortunes::pick();
            respond(200, [
                'ok' => true,
                'result' => [
                    'exit_code' => 0,
                    'stdout' => $fortune,
                    // CliOutputManager::success() will use this as the
                    // structured-output payload when --json is passed.
                    'fortune' => $fortune,
                ],
            ], $log);
        }
        respond(501, [
            'ok' => false,
            'error' => [
                'code' => 'handler_not_found',
                'message' => "no CLI handler for '{$name}'",
            ],
        ], $log);

    default:
        respond(400, ['ok' => false, 'error' => ['code' => 'bad_envelope', 'message' => "unknown type '{$type}'"]], $log);
}
