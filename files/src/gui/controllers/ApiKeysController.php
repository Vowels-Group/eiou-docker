<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Core\UserContext;
use Eiou\Database\ApiKeyRepository;
use Eiou\Gui\Helpers\GuiErrorResponse;
use Eiou\Gui\Includes\Session;
use Eiou\Services\ApiKeyService;
use Eiou\Services\GuiActionRegistry;
use Eiou\Utils\AltCodeVerifier;
use Eiou\Utils\Logger;
use Eiou\Utils\Security;
use Exception;
use Throwable;

/**
 * GUI controller for API key management.
 *
 * All responses are JSON. Mutations (create/enable/disable/delete) require
 * a short-lived sensitive-action grant on top of the normal session — this
 * is separate from remember-me so a remembered device still has to re-prompt
 * for the auth code before it can mint or revoke keys.
 */
class ApiKeysController
{
    private Session $session;
    private ApiKeyRepository $repository;

    /**
     * Permission groups shown in the create modal — derived from the
     * canonical whitelist in `ApiKeyService::PERMISSIONS` so the GUI and
     * the validator can never drift. Adding a new scope to ApiKeyService
     * is enough; the modal picks it up automatically.
     */
    public static function permissionGroups(): array
    {
        return ApiKeyService::permissionGroupsForDisplay();
    }

    /**
     * Preset permission bundles. Read-only mirrors every `<cat>:read`
     * scope in PERMISSIONS so a new read-class scope auto-extends the
     * preset; full-access stays a single `admin` row.
     */
    public static function permissionPresets(): array
    {
        $readOnly = array_values(array_filter(
            ApiKeyService::PERMISSIONS,
            static fn (string $p) => str_ends_with($p, ':read')
        ));
        return [
            'read_only'   => $readOnly,
            'full_access' => ['admin'],
        ];
    }

    public function __construct(Session $session, ApiKeyRepository $repository)
    {
        $this->session = $session;
        $this->repository = $repository;
    }

    /**
     * Register every owned action with the shared GuiActionRegistry.
     *
     * routeAction() centralizes CSRF + sensitive-access gates and the
     * sentinel-exception unwind, and per-action handlers are private.
     * Each entry registers a delegate closure that calls routeAction()
     * and catches the ApiKeysControllerResponseSent sentinel locally —
     * same as the legacy Functions.php try/catch did. Tier is TIER_AUTH
     * because routeAction() does its own non-rotating CSRF check;
     * gating CSRF twice would mean fighting the controller's 403
     * envelope shape with the registry's, and the controller's shape
     * is what the JS client expects.
     */
    public function registerActions(GuiActionRegistry $registry): void
    {
        $delegate = function (array $request): void {
            try {
                $this->routeAction();
            } catch (ApiKeysControllerResponseSent $sent) {
                // Response already emitted by the controller via respond().
            }
        };
        foreach ([
            'apiKeysStatus',
            'apiKeysVerify',
            'apiKeysClearAccess',
            'apiKeysList',
            'apiKeysCreate',
            'apiKeysToggle',
            'apiKeysDelete',
            'apiKeysUpdate',
            'apiKeysDisableAll',
            'apiKeysDeleteAll',
        ] as $action) {
            $registry->register($action, $delegate, GuiActionRegistry::TIER_AUTH, 'core');
        }
    }

    /**
     * Route one of the apiKeys* AJAX actions. Always writes JSON and exits.
     */
    public function routeAction(): void
    {
        header('Content-Type: application/json');

        try {
            $action = $_POST['action'] ?? '';

            // CSRF for every state-changing call. Don't rotate — the GUI
            // sends many AJAX calls from the same page load.
            if (!$this->session->validateCSRFToken($_POST['csrf_token'] ?? '', false)) {
                $this->respondError('csrf_invalid', 'Invalid CSRF token', 403);
            }

            switch ($action) {
                case 'apiKeysStatus':
                    $this->respond([
                        'success' => true,
                        'sensitive_access' => $this->session->hasSensitiveAccess(),
                        'seconds_remaining' => $this->session->sensitiveAccessSecondsRemaining(),
                        'ttl_seconds' => Session::SENSITIVE_ACCESS_TTL_SECONDS,
                    ]);
                    break;
                case 'apiKeysVerify':
                    $this->verify();
                    break;
                case 'apiKeysClearAccess':
                    $this->session->clearSensitiveAccess();
                    $this->respond(['success' => true]);
                    break;
                case 'apiKeysList':
                    $this->listKeys();
                    break;
                case 'apiKeysCreate':
                    $this->requireSensitive();
                    $this->createKey();
                    break;
                case 'apiKeysToggle':
                    $this->requireSensitive();
                    $this->toggleKey();
                    break;
                case 'apiKeysUpdate':
                    $this->requireSensitive();
                    $this->updateKey();
                    break;
                case 'apiKeysDelete':
                    $this->requireSensitive();
                    $this->deleteKey();
                    break;
                case 'apiKeysDisableAll':
                    $this->requireSensitive();
                    $count = $this->repository->disableAllKeys();
                    Logger::getInstance()->info('api_keys_disable_all_via_gui', ['count' => $count]);
                    $this->respond(['success' => true, 'count' => $count]);
                    break;
                case 'apiKeysDeleteAll':
                    $this->requireSensitive();
                    $count = $this->repository->deleteAllKeys();
                    Logger::getInstance()->info('api_keys_delete_all_via_gui', ['count' => $count]);
                    $this->respond(['success' => true, 'count' => $count]);
                    break;
                default:
                    $this->respondError('unknown_action', 'Unknown action', 400);
            }
        } catch (ApiKeysControllerResponseSent $responseSent) {
            // A handler already wrote the JSON response — rethrow so the
            // router in Functions.php ends the request cleanly in prod, and
            // test doubles can observe the emission.
            throw $responseSent;
        } catch (Throwable $e) {
            Logger::getInstance()->logException($e, [
                'context' => 'api_keys_controller',
                'action' => $_POST['action'] ?? '',
            ]);
            $this->respondError('server_error', $e->getMessage(), 500);
        }
    }

    /**
     * Verify the user's auth code and grant sensitive-action access.
     *
     * Either the primary BIP39-derived auth code or, when configured, the
     * user-chosen alternate code is accepted. Both are evaluated even on
     * primary match so timing observers cannot tell which credential was
     * presented (or whether an alt code is set at all).
     */
    private function verify(): void
    {
        $authCode = (string) ($_POST['authcode'] ?? '');
        if ($authCode === '') {
            $this->respondError('missing_authcode', 'Auth code is required', 400);
        }

        $user = UserContext::getInstance();
        $expected = $user->getAuthCode();
        $altHash = $user->getAltCodeHash();

        $primaryOk = $expected !== null && hash_equals($expected, $authCode);
        // Constant-time alt check — AltCodeVerifier always runs Argon2id
        // work (against a placeholder when no hash is configured) so the
        // re-auth path doesn't reveal alt-code presence via latency.
        $altOk = AltCodeVerifier::verify($authCode, $altHash);

        if (!$primaryOk && !$altOk) {
            // Same generic failure message the login form uses — don't
            // confirm whether the user has an auth code set.
            $this->respondError('invalid_authcode', 'Auth code is invalid', 401);
        }

        $this->session->grantSensitiveAccess();
        $this->respond([
            'success' => true,
            'seconds_remaining' => $this->session->sensitiveAccessSecondsRemaining(),
        ]);
    }

    /**
     * List keys. Does not require sensitive access — key_ids are public
     * identifiers and listing them is not destructive. Secrets are never
     * returned.
     */
    private function listKeys(): void
    {
        $keys = $this->repository->listKeys(true);
        $normalized = array_map(function (array $k): array {
            return [
                'key_id' => $k['key_id'],
                'name' => $k['name'],
                'permissions' => is_array($k['permissions']) ? $k['permissions'] : [],
                'rate_limit_per_minute' => (int) ($k['rate_limit_per_minute'] ?? 0),
                'enabled' => (bool) $k['enabled'],
                'created_at' => $k['created_at'] ?? null,
                'last_used_at' => $k['last_used_at'] ?? null,
                'expires_at' => $k['expires_at'] ?? null,
            ];
        }, $keys);

        $this->respond([
            'success' => true,
            'keys' => $normalized,
            'sensitive_access' => $this->session->hasSensitiveAccess(),
            'seconds_remaining' => $this->session->sensitiveAccessSecondsRemaining(),
        ]);
    }

    /**
     * Create a new key. Returns the secret exactly once — it is never
     * retrievable again from the server.
     */
    private function createKey(): void
    {
        $name = trim(Security::sanitizeInput((string) ($_POST['name'] ?? '')));
        if ($name === '' || strlen($name) > 64) {
            $this->respondError('invalid_name', 'Name must be 1–64 characters', 400);
        }

        $rawPermissions = $_POST['permissions'] ?? [];
        if (!is_array($rawPermissions)) {
            // Accept comma-separated string too (matches CLI conventions).
            $rawPermissions = array_filter(array_map('trim', explode(',', (string) $rawPermissions)));
        }
        $permissions = array_values(array_unique(array_filter($rawPermissions, 'is_string')));

        if (empty($permissions)) {
            $this->respondError('no_permissions', 'At least one permission is required', 400);
        }

        $validation = ApiKeyService::validatePermissions($permissions);
        if (!$validation['valid']) {
            $this->respondError(
                'invalid_permission',
                'One of the requested permissions is not recognised',
                400,
                ['invalid_permission' => $validation['invalid_permission']]
            );
        }

        $rateLimit = 100;
        if (isset($_POST['rate_limit_per_minute']) && $_POST['rate_limit_per_minute'] !== '') {
            $rateValidation = ApiKeyService::validateRateLimit($_POST['rate_limit_per_minute']);
            if (!$rateValidation['valid']) {
                $this->respondError('invalid_rate_limit', $rateValidation['error'], 400);
            }
            $rateLimit = $rateValidation['value'];
        }

        $expiresAt = null;
        $expiresInDays = $_POST['expires_in_days'] ?? '';
        if ($expiresInDays !== '' && $expiresInDays !== '0') {
            if (!ctype_digit((string) $expiresInDays) || (int) $expiresInDays < 1 || (int) $expiresInDays > 3650) {
                $this->respondError('invalid_expiration', 'Expiration must be 1–3650 days', 400);
            }
            $expiresAt = gmdate('Y-m-d H:i:s', time() + ((int) $expiresInDays) * 86400);
        }

        $result = $this->repository->createKey($name, $permissions, $rateLimit, $expiresAt);

        // Log creation via the normal logger — SecureLogger masks the
        // secret pattern so this does not leak to disk.
        Logger::getInstance()->info('api_key_created_via_gui', [
            'key_id' => $result['key_id'],
            'name' => $result['name'],
            'permissions' => $result['permissions'],
        ]);

        $this->respond([
            'success' => true,
            'key' => [
                'key_id' => $result['key_id'],
                'secret' => $result['secret'],
                'name' => $result['name'],
                'permissions' => $result['permissions'],
                'rate_limit_per_minute' => $result['rate_limit_per_minute'],
                'expires_at' => $result['expires_at'],
            ],
        ]);
    }

    /**
     * Edit mutable fields on an existing key. Only label, rate limit, and
     * expiry are editable; expiry can only be SHORTENED, never extended —
     * that keeps forgotten keys from silently outliving their usefulness,
     * and there's no legitimate need to extend a key the operator can
     * already delete + reissue. Permissions are deliberately read-only
     * after creation (revoke + re-issue to change scope).
     */
    private function updateKey(): void
    {
        $keyId = (string) ($_POST['key_id'] ?? '');
        if (!$this->isValidKeyId($keyId)) {
            $this->respondError('invalid_key_id', 'Invalid key id', 400);
        }

        $existing = $this->repository->getByKeyId($keyId);
        if ($existing === null) {
            $this->respondError('not_found', 'API key not found', 404);
        }

        $newName = null;
        if (array_key_exists('name', $_POST)) {
            $name = trim(Security::sanitizeInput((string) $_POST['name']));
            if ($name === '' || strlen($name) > 64) {
                $this->respondError('invalid_name', 'Name must be 1–64 characters', 400);
            }
            $newName = $name;
        }

        $newRateLimit = null;
        if (array_key_exists('rate_limit_per_minute', $_POST) && $_POST['rate_limit_per_minute'] !== '') {
            $rateValidation = ApiKeyService::validateRateLimit($_POST['rate_limit_per_minute']);
            if (!$rateValidation['valid']) {
                $this->respondError('invalid_rate_limit', $rateValidation['error'], 400);
            }
            $newRateLimit = $rateValidation['value'];
        }

        $newExpiresAt = null;
        if (array_key_exists('expires_in_days', $_POST) && $_POST['expires_in_days'] !== '') {
            $days = $_POST['expires_in_days'];
            if (!ctype_digit((string) $days) || (int) $days < 1 || (int) $days > 3650) {
                $this->respondError('invalid_expiration', 'Expiration must be 1–3650 days', 400);
            }
            $candidate = gmdate('Y-m-d H:i:s', time() + ((int) $days) * 86400);
            // Reject extension: if the key already has an expiry, the new
            // one must be earlier. A null current expiry ("Never") means
            // any finite expiry is a shortening, so that's always allowed.
            $currentExpiresAt = $existing['expires_at'] ?? null;
            if ($currentExpiresAt !== null && $candidate > $currentExpiresAt) {
                $this->respondError(
                    'expiration_extension_not_allowed',
                    'Expiry can only be shortened. Delete and recreate the key to extend it.',
                    400
                );
            }
            $newExpiresAt = $candidate;
        }

        if ($newName === null && $newRateLimit === null && $newExpiresAt === null) {
            $this->respondError('nothing_to_update', 'Nothing to update', 400);
        }

        $this->repository->updateKey($keyId, $newName, $newRateLimit, $newExpiresAt);

        $changed = [];
        if ($newName !== null)      $changed[] = 'name';
        if ($newRateLimit !== null) $changed[] = 'rate_limit_per_minute';
        if ($newExpiresAt !== null) $changed[] = 'expires_at';

        Logger::getInstance()->info('api_key_updated_via_gui', [
            'key_id' => $keyId,
            'changed' => $changed,
        ]);

        $this->respond(['success' => true, 'key_id' => $keyId, 'changed' => $changed]);
    }

    /**
     * Enable or disable a key.
     */
    private function toggleKey(): void
    {
        $keyId = (string) ($_POST['key_id'] ?? '');
        $enable = isset($_POST['enable']) && $_POST['enable'] === '1';

        if (!$this->isValidKeyId($keyId)) {
            $this->respondError('invalid_key_id', 'Invalid key id', 400);
        }

        $ok = $enable
            ? $this->repository->enableKey($keyId)
            : $this->repository->disableKey($keyId);

        if (!$ok) {
            // Either not found, or already in the requested state. Re-check
            // existence to tell the two apart for a helpful error.
            if ($this->repository->getByKeyId($keyId) === null) {
                $this->respondError('not_found', 'API key not found', 404);
            }
            // Already in requested state — treat as success (idempotent).
        }

        Logger::getInstance()->info('api_key_toggled_via_gui', [
            'key_id' => $keyId,
            'enabled' => $enable,
        ]);

        $this->respond(['success' => true, 'enabled' => $enable]);
    }

    /**
     * Permanently delete a key.
     */
    private function deleteKey(): void
    {
        $keyId = (string) ($_POST['key_id'] ?? '');
        if (!$this->isValidKeyId($keyId)) {
            $this->respondError('invalid_key_id', 'Invalid key id', 400);
        }

        $deleted = $this->repository->deleteKey($keyId);
        if (!$deleted) {
            $this->respondError('not_found', 'API key not found', 404);
        }

        Logger::getInstance()->info('api_key_deleted_via_gui', [
            'key_id' => $keyId,
        ]);

        $this->respond(['success' => true]);
    }

    /**
     * Gate mutating actions on a live sensitive-access grant. 401 triggers
     * the client to show the re-prompt and retry.
     */
    private function requireSensitive(): void
    {
        if (!$this->session->hasSensitiveAccess()) {
            $this->respondError(
                'sensitive_access_required',
                'Please re-enter your auth code to continue.',
                401
            );
        }
    }

    /**
     * Emit a canonical GUI error envelope through the test-seam `respond()`
     * method. Routes through `GuiErrorResponse::make()` so the wire shape
     * matches every other migrated GUI controller; the throw of
     * `ApiKeysControllerResponseSent` (from `respond()`) means tests can
     * still capture without `exit()`.
     *
     * Optional extras get merged on top — for the rare cases where the
     * legacy envelope shipped extra fields (e.g. `invalid_permission` →
     * which permission failed) that JS readers still consume.
     *
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

    /**
     * Validate the shape of a key_id without hitting the DB. key_ids are
     * minted as 'eiou_' + 24 hex chars; accept that exact shape.
     */
    private function isValidKeyId(string $keyId): bool
    {
        return (bool) preg_match('/^eiou_[a-f0-9]{24}$/', $keyId);
    }

    /**
     * Emit a JSON response and halt processing.
     *
     * Protected + non-exiting in the test seam so unit tests can override
     * this method to capture responses without terminating the PHPUnit
     * process. Production callers get `exit;` behaviour below.
     *
     * @param array<string,mixed> $payload
     */
    protected function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload);
        // Throw a sentinel control-flow exception instead of calling exit()
        // directly — the outer routeAction() handler catches Throwable only
        // around the action dispatch, not this response emission, so the
        // exception propagates out of routeAction(). In production the
        // outermost code (Functions.php include) is the last thing running,
        // so the uncaught exception is effectively identical to exit; tests
        // catch it cleanly.
        throw new ApiKeysControllerResponseSent($status);
    }
}

/**
 * Internal control-flow exception used only to unwind the stack after a
 * JSON response has been emitted. Not part of the public API.
 */
class ApiKeysControllerResponseSent extends \RuntimeException
{
    public int $httpStatus;
    public function __construct(int $httpStatus)
    {
        parent::__construct('API keys response already sent');
        $this->httpStatus = $httpStatus;
    }
}
