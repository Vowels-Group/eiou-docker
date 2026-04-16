<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Core\UserContext;
use Eiou\Database\ApiKeyRepository;
use Eiou\Gui\Includes\Session;
use Eiou\Services\ApiKeyService;
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
     * Permission groups shown in the create modal. Presented here so the
     * backend is the single source of truth for what the UI offers — the
     * frontend just renders these labels.
     */
    public const PERMISSION_GROUPS = [
        'Wallet' => [
            'wallet:read' => 'Read balance and transactions',
            'wallet:send' => 'Send transactions and manage chain drops',
        ],
        'Contacts' => [
            'contacts:read'  => 'List, view, search, ping contacts',
            'contacts:write' => 'Add, update, delete, block contacts',
        ],
        'System' => [
            'system:read' => 'View status, metrics, and settings',
        ],
        'Backup' => [
            'backup:read'  => 'Read backup status and verify',
            'backup:write' => 'Create, restore, delete backups',
        ],
        'Admin' => [
            'admin' => 'Full administrative access (settings, sync, keys)',
        ],
    ];

    /**
     * Preset permission bundles.
     */
    public const PERMISSION_PRESETS = [
        'read_only'   => ['wallet:read', 'contacts:read', 'system:read', 'backup:read'],
        'full_access' => ['admin'],
    ];

    public function __construct(Session $session, ApiKeyRepository $repository)
    {
        $this->session = $session;
        $this->repository = $repository;
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
                $this->respond(['success' => false, 'error' => 'csrf_error'], 403);
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
                    $this->respond(['success' => false, 'error' => 'unknown_action'], 400);
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
            $this->respond(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Verify the user's auth code and grant sensitive-action access.
     */
    private function verify(): void
    {
        $authCode = (string) ($_POST['authcode'] ?? '');
        if ($authCode === '') {
            $this->respond(['success' => false, 'error' => 'missing_authcode'], 400);
        }

        $user = UserContext::getInstance();
        $expected = $user->getAuthCode();

        if ($expected === null || !hash_equals($expected, $authCode)) {
            // Same generic failure message the login form uses — don't
            // confirm whether the user has an auth code set.
            $this->respond(['success' => false, 'error' => 'invalid_authcode'], 401);
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
            $this->respond(['success' => false, 'error' => 'invalid_name'], 400);
        }

        $rawPermissions = $_POST['permissions'] ?? [];
        if (!is_array($rawPermissions)) {
            // Accept comma-separated string too (matches CLI conventions).
            $rawPermissions = array_filter(array_map('trim', explode(',', (string) $rawPermissions)));
        }
        $permissions = array_values(array_unique(array_filter($rawPermissions, 'is_string')));

        if (empty($permissions)) {
            $this->respond(['success' => false, 'error' => 'no_permissions'], 400);
        }

        $validation = ApiKeyService::validatePermissions($permissions);
        if (!$validation['valid']) {
            $this->respond([
                'success' => false,
                'error' => 'invalid_permission',
                'invalid_permission' => $validation['invalid_permission'],
            ], 400);
        }

        $rateLimit = 100;
        if (isset($_POST['rate_limit_per_minute']) && $_POST['rate_limit_per_minute'] !== '') {
            $rateValidation = ApiKeyService::validateRateLimit($_POST['rate_limit_per_minute']);
            if (!$rateValidation['valid']) {
                $this->respond([
                    'success' => false,
                    'error' => 'invalid_rate_limit',
                    'message' => $rateValidation['error'],
                ], 400);
            }
            $rateLimit = $rateValidation['value'];
        }

        $expiresAt = null;
        $expiresInDays = $_POST['expires_in_days'] ?? '';
        if ($expiresInDays !== '' && $expiresInDays !== '0') {
            if (!ctype_digit((string) $expiresInDays) || (int) $expiresInDays < 1 || (int) $expiresInDays > 3650) {
                $this->respond(['success' => false, 'error' => 'invalid_expiration'], 400);
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
            $this->respond(['success' => false, 'error' => 'invalid_key_id'], 400);
        }

        $existing = $this->repository->getByKeyId($keyId);
        if ($existing === null) {
            $this->respond(['success' => false, 'error' => 'not_found'], 404);
        }

        $newName = null;
        if (array_key_exists('name', $_POST)) {
            $name = trim(Security::sanitizeInput((string) $_POST['name']));
            if ($name === '' || strlen($name) > 64) {
                $this->respond(['success' => false, 'error' => 'invalid_name'], 400);
            }
            $newName = $name;
        }

        $newRateLimit = null;
        if (array_key_exists('rate_limit_per_minute', $_POST) && $_POST['rate_limit_per_minute'] !== '') {
            $rateValidation = ApiKeyService::validateRateLimit($_POST['rate_limit_per_minute']);
            if (!$rateValidation['valid']) {
                $this->respond([
                    'success' => false,
                    'error' => 'invalid_rate_limit',
                    'message' => $rateValidation['error'],
                ], 400);
            }
            $newRateLimit = $rateValidation['value'];
        }

        $newExpiresAt = null;
        if (array_key_exists('expires_in_days', $_POST) && $_POST['expires_in_days'] !== '') {
            $days = $_POST['expires_in_days'];
            if (!ctype_digit((string) $days) || (int) $days < 1 || (int) $days > 3650) {
                $this->respond(['success' => false, 'error' => 'invalid_expiration'], 400);
            }
            $candidate = gmdate('Y-m-d H:i:s', time() + ((int) $days) * 86400);
            // Reject extension: if the key already has an expiry, the new
            // one must be earlier. A null current expiry ("Never") means
            // any finite expiry is a shortening, so that's always allowed.
            $currentExpiresAt = $existing['expires_at'] ?? null;
            if ($currentExpiresAt !== null && $candidate > $currentExpiresAt) {
                $this->respond([
                    'success' => false,
                    'error' => 'expiration_extension_not_allowed',
                    'message' => 'Expiry can only be shortened. Delete and recreate the key to extend it.',
                ], 400);
            }
            $newExpiresAt = $candidate;
        }

        if ($newName === null && $newRateLimit === null && $newExpiresAt === null) {
            $this->respond(['success' => false, 'error' => 'nothing_to_update'], 400);
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
            $this->respond(['success' => false, 'error' => 'invalid_key_id'], 400);
        }

        $ok = $enable
            ? $this->repository->enableKey($keyId)
            : $this->repository->disableKey($keyId);

        if (!$ok) {
            // Either not found, or already in the requested state. Re-check
            // existence to tell the two apart for a helpful error.
            if ($this->repository->getByKeyId($keyId) === null) {
                $this->respond(['success' => false, 'error' => 'not_found'], 404);
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
            $this->respond(['success' => false, 'error' => 'invalid_key_id'], 400);
        }

        $deleted = $this->repository->deleteKey($keyId);
        if (!$deleted) {
            $this->respond(['success' => false, 'error' => 'not_found'], 404);
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
            $this->respond([
                'success' => false,
                'error' => 'sensitive_access_required',
                'message' => 'Please re-enter your auth code to continue.',
            ], 401);
        }
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
