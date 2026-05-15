<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Contracts\RateLimiterServiceInterface;
use Eiou\Core\AppConfig;
use Eiou\Core\UserContext;
use Eiou\Gui\Helpers\GuiErrorResponse;
use Eiou\Gui\Includes\Session;
use Eiou\Services\GuiActionRegistry;
use Eiou\Utils\AltCodeValidator;
use Eiou\Utils\Logger;
use Eiou\Utils\Security;
use Throwable;

/**
 * GUI AJAX controller for managing the alternate authentication code.
 *
 * The alt code is a user-chosen credential (≥12 chars, mixed case, digits,
 * symbols) that the user can submit to the login form or sensitive-action
 * gate alongside the BIP39-derived primary auth code. It exists because
 * the primary is 20 hex characters derived from the seed and humans don't
 * memorize random hex; the alt is whatever passphrase the user can
 * actually remember.
 *
 * Every state-changing action here is gated on the PRIMARY auth code,
 * verified in-band on each request. The alt code may not rotate the alt
 * code, otherwise compromising the alt yields permanent takeover (the
 * legitimate user could be locked out via a forced rotation). The same
 * reasoning applies to clearing — an attacker who learns the alt should
 * not be able to remove the legitimate user's known credential.
 */
class AltCodeController
{
    /**
     * Rate-limit bucket name and shape for `altCodeSet` / `altCodeClear`.
     *
     * Tighter than `gui_login` (which is 10/60s, 5-min block) because
     * these actions verify the PRIMARY auth code in band — an attacker
     * with a stolen session cookie could otherwise probe primary
     * candidates here at unlimited rate. The primary is 80 bits so
     * brute-forcing is infeasible regardless, but defense in depth is
     * cheap. Aligned with the existing `cli_altcode` limit (5 per 5 min)
     * for consistency across surfaces.
     */
    private const RATE_LIMIT_BUCKET   = 'gui_altcode_modify';
    private const RATE_LIMIT_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW   = 300; // 5 minutes
    private const RATE_LIMIT_BLOCK    = 900; // 15 minutes after exceeding

    private Session $session;
    private RateLimiterServiceInterface $rateLimiter;
    private AppConfig $appConfig;

    public function __construct(
        Session $session,
        RateLimiterServiceInterface $rateLimiter,
        AppConfig $appConfig
    ) {
        $this->session = $session;
        $this->rateLimiter = $rateLimiter;
        $this->appConfig = $appConfig;
    }

    public function registerActions(GuiActionRegistry $registry): void
    {
        $delegate = function (array $request): void {
            try {
                $this->routeAction();
            } catch (AltCodeControllerResponseSent $sent) {
                // response already emitted
            }
        };
        foreach ([
            'altCodeStatus',
            'altCodeSet',
            'altCodeClear',
        ] as $action) {
            // TIER_AUTH: routeAction() runs its own non-rotating CSRF
            // check, matching the pattern used by ApiKeysController /
            // PaybackMethodsController so the failure envelope shape is
            // identical and JS can handle them uniformly.
            $registry->register($action, $delegate, GuiActionRegistry::TIER_AUTH, 'core');
        }
    }

    public function routeAction(): void
    {
        header('Content-Type: application/json');

        try {
            $action = $_POST['action'] ?? '';

            if (!$this->session->validateCSRFToken($_POST['csrf_token'] ?? '', false)) {
                $this->respondError('csrf_invalid', 'Invalid CSRF token', 403);
            }

            switch ($action) {
                case 'altCodeStatus':
                    $this->status();
                    break;
                case 'altCodeSet':
                    $this->setAlt();
                    break;
                case 'altCodeClear':
                    $this->clearAlt();
                    break;
                default:
                    $this->respondError('unknown_action', 'Unknown action', 400);
            }
        } catch (AltCodeControllerResponseSent $sent) {
            throw $sent;
        } catch (Throwable $e) {
            Logger::getInstance()->logException($e, [
                'context' => 'alt_code_controller',
                'action' => $_POST['action'] ?? '',
            ]);
            $this->respondError('server_error', $e->getMessage(), 500);
        }
    }

    /**
     * Cheap status probe — does the user have an alt code, and was the
     * current session itself authenticated via that alt code? The latter
     * lets the settings UI hide the rotate/clear forms for alt-only
     * sessions (the underlying handler enforces this server-side; the UI
     * flag is just to avoid showing a button that always 403s).
     */
    private function status(): void
    {
        $user = UserContext::getInstance();
        $this->respond([
            'success' => true,
            'has_alt_code' => $user->hasAltCode(),
            'authenticated_via_alt' => $this->session->authenticatedViaAlt(),
            'min_length' => AltCodeValidator::MIN_LENGTH,
        ]);
    }

    /**
     * Set or rotate the alternate auth code.
     *
     * Required fields:
     *   - primary_authcode: the user's primary auth code, re-entered.
     *     Validated *in-band* (not via session sensitive-access) so an
     *     alt-only session cannot piggyback on a prior sensitive grant.
     *   - new_alt_code:     the new alternate code. Validated for
     *     strength via AltCodeValidator.
     *
     * The alt code itself MAY NOT be used to authorize this action. An
     * alt-authenticated session is refused before the credentials are
     * even checked, with a deliberately specific error code so the UI
     * can render "use the primary code from your seed phrase" guidance.
     */
    private function setAlt(): void
    {
        if ($this->session->authenticatedViaAlt()) {
            $this->respondError(
                'alt_session_forbidden',
                'Setting or rotating the alt code requires logging in with the primary auth code.',
                403
            );
        }

        // Rate limit BEFORE the credential check so primary-code probing
        // burns the bucket the same way wrong submissions do. Validation
        // failures (missing fields, weak alt code) also count — keeps
        // the gate from being trivially bypassed by submitting malformed
        // requests in a loop.
        $this->enforceModifyRateLimit();

        $primaryCandidate = (string) ($_POST['primary_authcode'] ?? '');
        $newAltCode = (string) ($_POST['new_alt_code'] ?? '');

        if ($primaryCandidate === '') {
            $this->respondError('missing_primary', 'Primary auth code is required', 400);
        }
        if ($newAltCode === '') {
            $this->respondError('missing_new_alt_code', 'New alt code is required', 400);
        }

        $user = UserContext::getInstance();
        $expectedPrimary = $user->getAuthCode();
        if ($expectedPrimary === null || !hash_equals($expectedPrimary, $primaryCandidate)) {
            // Generic message — don't confirm which field was wrong.
            $this->respondError('invalid_primary', 'Primary auth code is invalid', 401);
        }

        // Forbid setting the alt code to the exact primary value — would
        // be an oddly destructive way to use this feature, and the
        // hash_equals check would not even compare the two on login
        // (different verification paths), so we surface it here.
        if (hash_equals($expectedPrimary, $newAltCode)) {
            $this->respondError(
                'alt_matches_primary',
                'Alt code must differ from the primary auth code.',
                400
            );
        }

        $validation = AltCodeValidator::validate($newAltCode);
        if (!$validation['valid']) {
            $this->respondError(
                'weak_alt_code',
                'Alt code does not meet strength requirements.',
                400,
                ['errors' => $validation['errors']]
            );
        }

        try {
            $user->setAltCode($newAltCode);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('alt_code_persist_failed', ['error' => $e->getMessage()]);
            $this->respondError('persist_failed', 'Could not save alt code.', 500);
        }

        Logger::getInstance()->info('alt_code_set_via_gui', [
            // intentionally no plaintext — just rotation telemetry
            'rotated' => true,
        ]);

        $this->respond([
            'success' => true,
            'has_alt_code' => true,
        ]);
    }

    /**
     * Remove the alt code. Same primary-required gating as setAlt() —
     * the alt code may not be used to clear itself.
     */
    private function clearAlt(): void
    {
        if ($this->session->authenticatedViaAlt()) {
            $this->respondError(
                'alt_session_forbidden',
                'Clearing the alt code requires logging in with the primary auth code.',
                403
            );
        }

        // Same bucket as setAlt — both verify the primary in band, and a
        // shared bucket means an attacker cannot launder attempts across
        // the two endpoints.
        $this->enforceModifyRateLimit();

        $primaryCandidate = (string) ($_POST['primary_authcode'] ?? '');
        if ($primaryCandidate === '') {
            $this->respondError('missing_primary', 'Primary auth code is required', 400);
        }

        $user = UserContext::getInstance();
        $expectedPrimary = $user->getAuthCode();
        if ($expectedPrimary === null || !hash_equals($expectedPrimary, $primaryCandidate)) {
            $this->respondError('invalid_primary', 'Primary auth code is invalid', 401);
        }

        try {
            $user->clearAltCode();
        } catch (\Throwable $e) {
            Logger::getInstance()->error('alt_code_clear_failed', ['error' => $e->getMessage()]);
            $this->respondError('persist_failed', 'Could not clear alt code.', 500);
        }

        Logger::getInstance()->info('alt_code_cleared_via_gui', []);
        $this->respond([
            'success' => true,
            'has_alt_code' => false,
        ]);
    }

    /**
     * Enforce the modify-rate-limit bucket. Per-IP, 5 attempts per
     * 5 minutes; 15-minute block on exceed. Calls respondError() (and
     * therefore unwinds the request) when the bucket is exhausted —
     * callers never see the false return path.
     *
     * The bucket is shared between setAlt() and clearAlt() so an
     * attacker cannot launder attempts by alternating endpoints.
     */
    private function enforceModifyRateLimit(): void
    {
        $clientIp = Security::getClientIp($this->appConfig);
        $result = $this->rateLimiter->checkLimit(
            $clientIp,
            self::RATE_LIMIT_BUCKET,
            self::RATE_LIMIT_ATTEMPTS,
            self::RATE_LIMIT_WINDOW,
            self::RATE_LIMIT_BLOCK
        );
        if (!$result['allowed']) {
            $retryAfter = $result['retry_after'] ?? self::RATE_LIMIT_BLOCK;
            $this->respondError(
                'rate_limited',
                'Too many alt-code modify attempts. Please try again later.',
                429,
                ['retry_after' => $retryAfter]
            );
        }
    }

    /**
     * Emit the JSON response and unwind via the sentinel exception.
     *
     * `protected` (not `private`) so the test seam can override it the
     * same way CapturingApiKeysController overrides ApiKeysController's
     * `respond()` — capture instead of echo, no production behavior
     * change.
     *
     * @return never
     */
    protected function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload);
        throw new AltCodeControllerResponseSent($status);
    }

    /**
     * @param array<string,mixed> $extras
     */
    private function respondError(string $code, string $message, int $status, array $extras = []): void
    {
        $payload = GuiErrorResponse::make($code, $message);
        if ($extras !== []) {
            $payload = array_merge($payload, $extras);
        }
        $this->respond($payload, $status);
    }
}

/**
 * Sentinel exception so routeAction can unwind after emitting a response.
 * Mirrors ApiKeysControllerResponseSent — accepts the HTTP status as a
 * typed constructor argument and passes a fixed message string to the
 * parent so strict-types callers can construct it without coercion.
 */
class AltCodeControllerResponseSent extends \RuntimeException
{
    public int $httpStatus;
    public function __construct(int $httpStatus)
    {
        parent::__construct('Alt code response already sent');
        $this->httpStatus = $httpStatus;
    }
}
