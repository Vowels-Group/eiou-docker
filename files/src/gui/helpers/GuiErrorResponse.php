<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Helpers;

/**
 * GuiErrorResponse — canonical JSON-AJAX error envelope for GUI POST
 * handlers (DlqController, ApiKeysController, the inline core actions
 * in `coreInlineActions.php`, etc).
 *
 * BACKGROUND. Pre-helper, ~188 sites across the GUI layer hand-rolled
 * `['success' => false, 'error' => ...]` literals at every guard. Two
 * incompatible conventions had grown organically:
 *
 *   - "error is a human message"  — `'error' => 'Invalid DLQ item ID'`
 *   - "error is a machine code"   — `'error' => 'csrf_error'`
 *
 * Frontend AJAX handlers reading `response.error` therefore got either
 * a sentence or a tag depending on which controller they hit, and JS
 * had to discriminate via `indexOf('CSRF')` substring sniffs and other
 * fragile heuristics.
 *
 * CANONICAL SHAPE. The helper emits BOTH at the top level so frontend
 * code can migrate incrementally:
 *
 *   {
 *     "success": false,
 *     "error":   "<human-readable message>",   // unchanged contract
 *     "code":    "<machine_code>"               // new — opt-in
 *   }
 *
 * Existing JS that reads `response.error` keeps working — the helper
 * preserves the human-readable message at that field. New JS reads
 * `response.code` for switch/case dispatch (e.g. session-expired vs
 * validation-failed branches) and `response.error` only for display.
 *
 * SCOPE. This helper covers GUI/JSON-AJAX endpoints only. Three other
 * envelope shapes exist for distinct audiences and are deliberately
 * NOT consolidated here:
 *
 *   - `Eiou\Api\ApiController::errorResponse()` — REST envelope with
 *     `request_id`, `status_code`, `data:null`. External clients
 *     depend on this shape.
 *   - `Eiou\Cli\CliJsonResponse::error()` — RFC 9457 problem-detail
 *     envelope with `error.type`, `error.title`, `error.detail`,
 *     plus a CLI metadata block. CLI consumers depend on this shape.
 *   - `Eiou\Core\ErrorHandler::createErrorResponse()` — internal
 *     handler envelope used by the global error handler.
 *
 * Don't unify them. Each serves a different audience with intentional
 * shape differences; "consolidation" via a mode flag would either
 * preserve three branches behind a router (fictional) or unify the
 * wire format (breaks every existing client).
 */
final class GuiErrorResponse
{
    /**
     * Build the canonical error array.
     *
     * @param string $code    Machine-readable code, snake_case.
     *                        Examples: `csrf_invalid`, `dlq_not_found`,
     *                        `validation_failed`. Stable across releases
     *                        — never localized.
     * @param string $message Human-readable message safe to display.
     *                        Localized later as needed.
     */
    public static function make(string $code, string $message): array
    {
        return [
            'success' => false,
            'error'   => $message,
            'code'    => $code,
        ];
    }

    /**
     * Send the envelope as a JSON-AJAX response and exit.
     *
     * Sets `Content-Type: application/json` (only if headers not yet
     * sent) and the given HTTP status code (default 400). Replaces the
     * `header(...) + echo json_encode(...) + exit` triple at every call
     * site.
     */
    public static function send(string $code, string $message, int $httpStatus = 400): never
    {
        if (!headers_sent()) {
            http_response_code($httpStatus);
            header('Content-Type: application/json');
        }
        echo json_encode(self::make($code, $message));
        exit;
    }
}
