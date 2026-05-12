<?php
# Copyright 2025-2026 Vowels Group, LLC
#
# Plugin __dispatch.php — Phase 3a stub template.
#
# Installed into /etc/eiou/plugins/<plugin-id>/__dispatch.php at plugin
# enable time (Phase 3a wire-in, follow-up). This file is the single
# HTTP entry point that nginx routes /gui/plugin/<plugin-id>/* requests
# to inside the per-plugin FPM pool. It receives a structured envelope
# from core, dispatches by type, and returns a structured envelope plus
# the plugin's log entries.
#
# In Phase 3a, every handler returns 501 / handler_not_found — this
# proves the routing and the contract shape without any real plugin
# code yet. Phase 3b+ replaces each case in the switch with real
# handlers that call into the plugin's entry class.
#
# Contract spec: docs/PLUGIN_SANDBOX_DISPATCH_CONTRACT.md (untracked).
#
# Security context: this file runs as the eiou-p-<hash> user inside the
# plugin's FPM pool, with open_basedir restricted to the plugin's own
# dir + scratch + /tmp/, with disable_functions blocking exec/eval/etc.
# It CANNOT read /etc/eiou/config/.master.key or userconfig.json.

declare(strict_types=1);

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

// Phase 3a stub: every type returns 501. Phase 3b+ replaces each case
// in this switch with calls into the plugin's entry class.
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
                'message' => "no handler bound for {$type}:{$name} (Phase 3a stub)",
            ],
        ], $log);
        break;
}
