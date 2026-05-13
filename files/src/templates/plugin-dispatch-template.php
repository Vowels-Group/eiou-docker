<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# Plugin __dispatch.php — stub template installed into
# /etc/eiou/plugins/<plugin-id>/__dispatch.php at plugin enable time.
# This file is the single HTTP entry point that nginx routes
# /gui/plugin/<plugin-id>/* requests to inside the per-plugin FPM pool.
# It receives a structured envelope from core, dispatches by type, and
# returns a structured envelope plus the plugin's log entries.
#
# Stub behaviour: every handler returns 501 / handler_not_found — this
# proves the routing and the contract shape without any real plugin
# code. A plugin author replaces each case in the switch with real
# handlers that call into the plugin's entry class.
#
# Security context: this file runs as the eiou-p-<hash> user inside the
# plugin's FPM pool, with open_basedir restricted to the plugin's own
# dir + scratch + /tmp/, with disable_functions blocking exec/eval/etc.
# It CANNOT read /etc/eiou/config/.master.key or userconfig.json.

declare(strict_types=1);

/**
 * PLUGIN_DISPATCH_VERSION — bumped every time core changes the wire
 * contract (request/response envelope, _log shape, core_call signature,
 * etc.). Plugins bundle their own __dispatch.php; at boot, core compares
 * the bundled version against this template's version. A bundled version
 * lower than the template triggers a deprecation warning so the plugin
 * author knows to re-sync their dispatcher.
 *
 * Equal or higher → no warning. Higher means the plugin shipped against
 * a newer core than ours; their author opted into the future contract.
 *
 * NEVER change this without (a) bumping the version, (b) updating the
 * minimum version every bundled plugin author needs, (c) documenting
 * the contract delta in CHANGELOG.md.
 */
const PLUGIN_DISPATCH_VERSION = 1;

header('Content-Type: application/json');

// =============================================================================
// PluginLog — buffer log entries until response time.
// Plugins write through this; the dispatcher serializes the entries
// into the response's `_log` field so core can write them to the
// wallet's central log under the plugin's name.
// =============================================================================
final class PluginLog
{
    /** @var array<int, array{level:string, message:string, context:array}> */
    public array $entries = [];

    public function info(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'info', 'message' => $message, 'context' => $context];
    }
    public function warning(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'warning', 'message' => $message, 'context' => $context];
    }
    public function error(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'error', 'message' => $message, 'context' => $context];
    }
    public function debug(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'debug', 'message' => $message, 'context' => $context];
    }
}

$log = new PluginLog();

// =============================================================================
// core_call($service, $method, $args) — service gateway client.
//
// Plugins call this from inside handlers to reach a whitelisted subset of
// core services. The gateway validates:
//   - Bearer token (read from .gateway-token in this plugin's dir)
//   - Plugin's manifest core_services allow-list
//   - Target method has #[PluginCallable]
//
// Returns:
//   - The method's return value on success
//   - null on transport / auth / dispatch failure (also logs to $log).
//
// Caller handles error-path by checking the buffer in $log->entries
// after the call — every failure mode emits a $log->error() entry
// with structured context.
// =============================================================================
function core_call(string $service, string $method, array $args, PluginLog $log)
{
    static $cachedToken = null;
    if ($cachedToken === null) {
        // The token file lives in this plugin's dir, mode 600 owned
        // by our pool's UID, so other plugin pools can't read it.
        $tokenPath = __DIR__ . '/.gateway-token';
        if (!is_file($tokenPath)) {
            $log->error('core_call: token file missing', ['path' => $tokenPath]);
            return null;
        }
        $cachedToken = trim((string) @file_get_contents($tokenPath));
        if ($cachedToken === '') {
            $log->error('core_call: token file empty');
            $cachedToken = null;
            return null;
        }
    }

    $body = json_encode([
        'service' => $service,
        'method'  => $method,
        'args'    => $args,
    ]);
    if ($body === false) {
        $log->error('core_call: failed to encode args', ['service' => $service, 'method' => $method]);
        return null;
    }

    // file_get_contents() is blocked under allow_url_fopen=0; use curl.
    // curl_init / curl_exec are NOT in disable_functions because the
    // sandbox needs an outbound HTTP mechanism for exactly this case.
    $ch = curl_init('http://127.0.0.1/__plugin_gateway');
    if ($ch === false) {
        $log->error('core_call: curl_init failed');
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cachedToken,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        $log->error('core_call: transport failed', [
            'service' => $service, 'method' => $method, 'error' => $curlError,
        ]);
        return null;
    }
    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        $log->error('core_call: gateway returned non-JSON', [
            'status' => $status, 'body_preview' => substr((string) $response, 0, 200),
        ]);
        return null;
    }
    if (empty($decoded['ok'])) {
        $log->error('core_call: gateway rejected', [
            'service' => $service, 'method' => $method,
            'status' => $status,
            'error' => $decoded['error'] ?? null,
        ]);
        return null;
    }
    return $decoded['result'] ?? null;
}

/**
 * Emit a structured response and exit. Always called via this helper
 * so a partial write never reaches the wire.
 */
function respond(int $status, array $body, PluginLog $log): void
{
    http_response_code($status);
    echo json_encode(array_merge($body, ['_log' => $log->entries]));
    exit;
}

// Parse the request envelope. Everything from this point is recoverable
// — malformed envelopes return a structured error, not a 500.
$rawBody = (string) @file_get_contents('php://input');
if ($rawBody === '') {
    respond(400, [
        'ok' => false,
        'error' => ['code' => 'bad_envelope', 'message' => 'empty request body'],
    ], $log);
}

$envelope = json_decode($rawBody, true);
if (!is_array($envelope)) {
    respond(400, [
        'ok' => false,
        'error' => ['code' => 'bad_envelope', 'message' => 'request body is not JSON'],
    ], $log);
}

$type    = (string) ($envelope['type']    ?? '');
$name    = (string) ($envelope['name']    ?? '');
$context = is_array($envelope['context'] ?? null) ? $envelope['context'] : [];

if ($type === '' || $name === '') {
    respond(400, [
        'ok' => false,
        'error' => ['code' => 'bad_envelope', 'message' => 'missing type or name'],
    ], $log);
}

$allowedTypes = ['event', 'filter', 'render', 'action', 'rest', 'cli'];
if (!in_array($type, $allowedTypes, true)) {
    respond(400, [
        'ok' => false,
        'error' => ['code' => 'bad_envelope', 'message' => "unknown type '{$type}'"],
    ], $log);
}

// Stub: every type returns 501. A plugin author replaces each case in
// this switch with calls into the plugin's entry class.
switch ($type) {
    case 'event':
    case 'filter':
    case 'render':
    case 'action':
    case 'rest':
    case 'cli':
        $log->debug(
            "Dispatch stub fired",
            ['type' => $type, 'name' => $name, 'request_id' => $_SERVER['HTTP_X_EIOU_REQUEST_ID'] ?? null]
        );
        respond(501, [
            'ok' => false,
            'error' => [
                'code' => 'handler_not_found',
                'message' => "no handler bound for {$type}:{$name} (dispatch stub)",
            ],
        ], $log);
        break;
}
