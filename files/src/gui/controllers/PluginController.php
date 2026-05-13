<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Gui\Helpers\GuiErrorResponse;
use Eiou\Gui\Includes\Session;
use Eiou\Core\UserContext;
use Eiou\Services\GuiActionRegistry;
use Eiou\Services\Plugins\PluginInstallService;
use Eiou\Services\Plugins\PluginUpgradeService;
use Eiou\Services\Plugins\PluginLoader;
use Eiou\Services\Plugins\PluginUninstallService;
use Eiou\Services\RestartRequestService;
use Eiou\Services\UpdateCheckService;
use Eiou\Utils\Logger;
use Throwable;

/**
 * Plugin Controller
 *
 * Handles AJAX actions for the GUI plugins table:
 *
 *   pluginsList    — return all discovered plugins with status + enabled flag
 *   pluginsToggle  — set enabled=true|false for a named plugin (CSRF required)
 *
 * Toggling does NOT take effect in the running process — event subscriptions
 * and registered services bind during boot. The response includes
 * restart_required: true so the GUI can surface that to the user.
 */
class PluginController
{
    private Session $session;
    private PluginLoader $loader;
    private RestartRequestService $restartRequester;
    private ?PluginUninstallService $uninstallService;
    private ?PluginInstallService $installService;
    private ?PluginUpgradeService $upgradeService;

    public function __construct(
        Session $session,
        PluginLoader $loader,
        ?RestartRequestService $restartRequester = null,
        ?PluginUninstallService $uninstallService = null,
        ?PluginInstallService $installService = null,
        ?PluginUpgradeService $upgradeService = null
    ) {
        $this->session = $session;
        $this->loader = $loader;
        $this->restartRequester = $restartRequester ?? new RestartRequestService();
        $this->uninstallService = $uninstallService;
        $this->installService = $installService;
        $this->upgradeService = $upgradeService;
    }

    /**
     * Action names this controller owns. Single source of truth so
     * registerActions() (live path) and registerUnavailableStubs()
     * (loader-not-ready path) can't drift.
     */
    private const OWNED_ACTIONS = [
        'pluginsList',
        'pluginsToggle',
        'pluginsRequestRestart',
        'pluginChangelog',
        'pluginsUninstall',
        'pluginsUpload',
        'pluginsUploadAsUpgrade',
        'pluginsUpgrade',
        'pluginsUploadLimits',
    ];

    /**
     * Register every owned action with the shared GuiActionRegistry.
     *
     * routeAction() centralizes CSRF + the sentinel-exception unwind,
     * and per-action handlers are private. Each entry registers a
     * delegate closure that calls routeAction() and catches the
     * PluginControllerResponseSent sentinel locally — same as the
     * legacy Functions.php try/catch did. Tier is TIER_AUTH because
     * routeAction() does its own non-rotating CSRF check; gating CSRF
     * twice would mean fighting the controller's 403 envelope shape
     * with the registry's, and the controller's shape is what the JS
     * client expects.
     */
    public function registerActions(GuiActionRegistry $registry): void
    {
        $delegate = function (array $request): void {
            try {
                $this->routeAction();
            } catch (PluginControllerResponseSent $sent) {
                // Response already emitted by the controller via respond().
            }
        };
        foreach (self::OWNED_ACTIONS as $action) {
            $registry->register($action, $delegate, GuiActionRegistry::TIER_AUTH, 'core');
        }
    }

    /**
     * Register stub handlers that emit the legacy
     * `{"success":false,"error":"plugin_loader_unavailable",...}`
     * envelope. Called from the bootstrap when Application's
     * PluginLoader hasn't discovered plugins yet (early-boot /
     * no-wallet state) so a real PluginController can't be
     * constructed. Without this, the registry would have no entries
     * for the plugin* actions and POSTs would silently fall through
     * to the wallet HTML render — the legacy if-branch's null check
     * always emitted JSON.
     */
    public static function registerUnavailableStubs(GuiActionRegistry $registry): void
    {
        $stub = function (): void {
            GuiErrorResponse::send(
                'plugin_loader_unavailable',
                'Plugin system is not initialized.',
                500
            );
        };
        foreach (self::OWNED_ACTIONS as $action) {
            $registry->register($action, $stub, GuiActionRegistry::TIER_AUTH, 'core');
        }
    }

    /**
     * Route one of the plugins* AJAX actions. Always writes JSON and exits.
     */
    public function routeAction(): void
    {
        header('Content-Type: application/json');

        try {
            $action = $_POST['action'] ?? '';

            // Validate CSRF on every call. Don't rotate — the GUI may make
            // multiple AJAX calls from the same page load.
            if (!$this->session->validateCSRFToken($_POST['csrf_token'] ?? '', false)) {
                $this->respondError('csrf_invalid', 'Invalid CSRF token', 403);
            }

            switch ($action) {
                case 'pluginsList':
                    $this->listPlugins();
                    break;
                case 'pluginsToggle':
                    $this->togglePlugin();
                    break;
                case 'pluginsRequestRestart':
                    $this->requestRestart();
                    break;
                case 'pluginChangelog':
                    $this->showChangelog();
                    break;
                case 'pluginsUninstall':
                    $this->uninstallPlugin();
                    break;
                case 'pluginsUpload':
                    $this->uploadPlugin();
                    break;
                case 'pluginsUploadAsUpgrade':
                    $this->uploadAsUpgrade();
                    break;
                case 'pluginsUpgrade':
                    $this->upgradeBundled();
                    break;
                case 'pluginsUploadLimits':
                    $this->reportUploadLimits();
                    break;
                default:
                    $this->respondError('unknown_action', 'Unknown action', 400);
            }
        } catch (PluginControllerResponseSent $sent) {
            throw $sent;
        } catch (Throwable $e) {
            Logger::getInstance()->logException($e, [
                'context' => 'plugin_controller',
                'action' => $_POST['action'] ?? '',
            ]);
            $this->respondError('server_error', $e->getMessage(), 500);
        }
    }

    private function listPlugins(): void
    {
        $plugins = $this->loader->listAllPlugins();

        // Merge bundled-upgrade availability into each row when the
        // upgrade service is wired. Operators see an "Upgrade" affordance
        // next to plugins whose image-baked version is newer than what's
        // installed in the volume. The merge is additive — rows without
        // a bundled-newer counterpart are untouched.
        if ($this->upgradeService !== null) {
            $available = $this->upgradeService->availableBundledUpgrades();
            foreach ($plugins as &$row) {
                $name = $row['name'] ?? null;
                if (is_string($name) && isset($available[$name])) {
                    $row['upgrade_available'] = [
                        'installed_version' => $available[$name]['installed_version'],
                        'bundled_version'   => $available[$name]['bundled_version'],
                    ];
                }
            }
            unset($row);
        }

        $this->respond([
            'success' => true,
            'plugins' => $plugins,
            // True when the on-disk state differs from what's actually
            // running in this PHP-FPM worker. Drives the GUI's "Restart
            // node" banner. Survives page reloads because it's recomputed
            // from authoritative state, not held in JS memory.
            'restart_required' => $this->computeRestartRequired($plugins),
            'restart_requested' => $this->restartRequester->isRequested(),
        ]);
    }

    /**
     * Compare each plugin's persisted enabled flag to whether it's loaded
     * in this worker. Any mismatch means a restart is needed for state to
     * catch up.
     *
     * @param list<array{name:string,enabled:bool,status:string}> $plugins
     */
    private function computeRestartRequired(array $plugins): bool
    {
        // status values from PluginLoader: discovered, registered, booted,
        // failed, disabled, sandboxed, not_loaded. Anything that ran
        // register/boot is "actually loaded" from the worker's perspective.
        $loadedStatuses = ['discovered', 'registered', 'booted'];

        foreach ($plugins as $p) {
            // Sandboxed plugins never load in-process — their pool is
            // a separate FPM process. Their enabled flag took effect on
            // applyPool / dropPool, not at PHP-FPM master startup, so a
            // state vs in-process divergence here is expected and
            // doesn't mean a restart is needed.
            if (!empty($p['sandboxed'])) {
                continue;
            }
            $isLoaded = in_array($p['status'] ?? '', $loadedStatuses, true);
            if (($p['enabled'] ?? false) !== $isLoaded) {
                return true;
            }
        }
        return false;
    }

    private function requestRestart(): void
    {
        // Best-effort audit field — only safe to derive when a wallet is
        // loaded. Skipping it never blocks the restart; it just leaves the
        // requestor empty in the audit log.
        $pubkeyHash = '';
        try {
            $user = UserContext::getInstance();
            if ($user->getPublicKey() !== null) {
                $pubkeyHash = (string) $user->getPublicKeyHash();
            }
        } catch (Throwable $e) {
            // missing wallet / uninitialized state — leave hash empty
        }

        if (!$this->restartRequester->request('gui', $pubkeyHash)) {
            $this->respondError(
                'request_failed',
                'Could not write the restart request file.',
                500
            );
        }

        Logger::getInstance()->info('node_restart_requested_via_gui', [
            'requestor' => $pubkeyHash,
        ]);

        $this->respond([
            'success' => true,
            // The poller in startup.sh runs every ~2s. Tell the client what
            // to expect so the UI can size its loading overlay accordingly.
            'expected_restart_within_seconds' => 5,
            'message' => 'Restart requested. The node will respawn its workers within a few seconds.',
        ]);
    }

    private function togglePlugin(): void
    {
        $name = (string) ($_POST['name'] ?? '');
        $enabled = !empty($_POST['enabled']) && $_POST['enabled'] !== '0' && $_POST['enabled'] !== 'false';

        // Plugin names from manifests are kebab-case alphanumerics. Reject
        // anything else to keep arbitrary keys out of the state file.
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-_]{0,63}$/i', $name)) {
            $this->respondError('invalid_name', 'Invalid plugin name', 400);
        }

        // Refuse to toggle a plugin that doesn't exist on disk — otherwise
        // the state file accumulates ghost entries.
        $known = array_column($this->loader->listAllPlugins(), 'name');
        if (!in_array($name, $known, true)) {
            $this->respondError('unknown_plugin', 'Plugin not found', 404);
        }

        if (!$this->loader->setEnabled($name, $enabled)) {
            $failure = $this->loader->getLastSetEnabledFailure();
            $message = $failure['message'] ?? 'Could not persist the new state';
            $code = isset($failure['stage']) ? ('plugin_' . $failure['stage'] . '_failed') : 'persist_failed';
            $this->respondError($code, $message, 500);
        }

        // Sandboxed plugins took effect immediately (applyPool reloaded
        // FPM + nginx). In-process plugins need a full node restart so
        // PluginLoader's register()/boot() can re-run with the new
        // state. Surface the distinction so the GUI doesn't raise a
        // "restart required" banner when nothing actually requires it.
        $isSandboxed = false;
        foreach ($this->loader->listAllPlugins() as $row) {
            if (($row['name'] ?? null) === $name) {
                $isSandboxed = !empty($row['sandboxed']);
                break;
            }
        }

        Logger::getInstance()->info('plugin_toggled_via_gui', [
            'plugin' => $name,
            'enabled' => $enabled,
            'sandboxed' => $isSandboxed,
        ]);

        $this->respond([
            'success' => true,
            'plugin' => $name,
            'enabled' => $enabled,
            'sandboxed' => $isSandboxed,
            'restart_required' => !$isSandboxed,
        ]);
    }

    /**
     * Uninstall a plugin. Requires the plugin to be already disabled —
     * the GUI's two-step flow (disable, confirm) matches this.
     *
     * Returns the per-step status map so the modal can show which parts
     * succeeded and which didn't.
     */
    private function uninstallPlugin(): void
    {
        $name = (string) ($_POST['name'] ?? '');
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-_]{0,63}$/i', $name)) {
            $this->respondError('invalid_name', 'Invalid plugin name', 400);
        }

        if ($this->uninstallService === null) {
            $this->respondError(
                'uninstall_unavailable',
                'Plugin uninstall service is not wired in this context.',
                500
            );
        }

        try {
            $result = $this->uninstallService->uninstall($name);
        } catch (\InvalidArgumentException $e) {
            $this->respondError('unknown_plugin', $e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            // "Cannot uninstall enabled plugin" lands here.
            $this->respondError('plugin_still_enabled', $e->getMessage(), 409);
        }

        Logger::getInstance()->info('plugin_uninstalled_via_gui', [
            'plugin' => $name,
            'success' => $result['success'],
        ]);

        $this->respond([
            'success' => $result['success'],
            'plugin_id' => $result['plugin_id'],
            'steps' => $result['steps'],
            'message' => $result['success']
                ? 'Plugin uninstalled.'
                : 'Uninstall completed with errors. Check the step list for details.',
        ], $result['success'] ? 200 : 500);
    }

    private function showChangelog(): void
    {
        $name = (string) ($_POST['name'] ?? '');
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9-_]{0,63}$/i', $name)) {
            $this->respondError('invalid_name', 'Invalid plugin name', 400);
        }

        $markdown = $this->loader->readChangelog($name);
        if ($markdown === null) {
            $this->respondError('not_found', 'Changelog not found', 404);
        }

        $this->respond([
            'success' => true,
            'plugin' => $name,
            'html' => UpdateCheckService::markdownToHtml($markdown),
        ]);
    }

    /**
     * Install a plugin from an uploaded zip. The plugin lands on disk
     * DISABLED — install never auto-enables. The client is expected to
     * call pluginsList afterwards to refresh the table and then either
     * pluginsToggle + pluginsRequestRestart to activate it, or
     * pluginsUninstall if the operator changes their mind after seeing
     * the signature status.
     *
     * Error envelopes:
     *   - 400 invalid_upload        — no file, partial upload, PHP UPLOAD_ERR_*
     *   - 400 invalid_zip           — bad magic / zip-slip / oversize / bad ext
     *   - 409 already_installed     — target dir already exists
     *   - 500 install_unavailable   — service not wired (shouldn't normally happen)
     *   - 500 install_failed        — filesystem / signature-required failure
     */
    private function uploadPlugin(): void
    {
        if ($this->installService === null) {
            $this->respondError(
                'install_unavailable',
                'Plugin install service is not wired in this context.',
                500
            );
        }

        $file = $_FILES['plugin_zip'] ?? null;
        if (!is_array($file)) {
            $this->respondError('invalid_upload', 'No file uploaded (expected field: plugin_zip)', 400);
        }

        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $this->respondError(
                'invalid_upload',
                'Upload failed: ' . $this->describeUploadError($err),
                400
            );
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !$this->isUploadedFile($tmpPath)) {
            // is_uploaded_file() is the canonical "this came from the same
            // request" check — guards against a caller passing /etc/passwd
            // as tmp_name in a forged $_FILES array (which can't happen via
            // the wire but is the documented safety check).
            $this->respondError('invalid_upload', 'Upload did not come from this request', 400);
        }

        $originalName = (string) ($file['name'] ?? '');
        try {
            $result = $this->installService->installFromZip($tmpPath, $originalName);
        } catch (\InvalidArgumentException $e) {
            // Two of the InvalidArgument cases ("already installed",
            // "appeared during install") map to 409 — both mean a clash
            // with existing on-disk state rather than malformed input.
            $msg = $e->getMessage();
            $code = (stripos($msg, 'already installed') !== false || stripos($msg, 'appeared during install') !== false)
                ? 'already_installed'
                : 'invalid_zip';
            $status = $code === 'already_installed' ? 409 : 400;
            $this->respondError($code, $msg, $status);
        } catch (\RuntimeException $e) {
            $this->respondError('install_failed', $e->getMessage(), 500);
        } catch (Throwable $e) {
            Logger::getInstance()->logException($e, [
                'context' => 'plugin_install',
                'original_filename' => $originalName,
            ]);
            $this->respondError('install_failed', 'Unexpected error during install', 500);
        }

        Logger::getInstance()->info('plugin_uploaded_via_gui', [
            'plugin' => $result['plugin_id'],
            'version' => $result['version'],
            'signature_status' => $result['signature']['status'],
        ]);

        $this->respond([
            'success' => true,
            'plugin_id' => $result['plugin_id'],
            'version' => $result['version'],
            'signature' => $result['signature'],
            // The plugin is staged DISABLED. Installing does not require a
            // restart by itself; *enabling* does. Surface that here so the
            // GUI doesn't immediately raise the restart banner.
            'enabled' => false,
            'restart_required' => false,
            'message' => 'Plugin uploaded and staged as disabled. Enable it and restart the node to activate.',
        ]);
    }

    /**
     * Expose the install service's limits so the upload UI can present them
     * without copying constants out of PHP. Cheap, idempotent, CSRF-checked.
     */
    private function reportUploadLimits(): void
    {
        // Limits live in the service even when no service instance is wired
        // — they're static. Callers can use them to pre-validate before
        // shipping the bytes over the wire.
        $this->respond([
            'success' => true,
            'limits' => PluginInstallService::limits(),
            'install_available' => $this->installService !== null,
        ]);
    }

    /**
     * Thin wrapper around PHP's is_uploaded_file() so tests can override
     * the check without spinning up a real multipart request. Production
     * code path is the genuine PHP-internal check.
     */
    protected function isUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    private function describeUploadError(int $err): string
    {
        // PHP's upload_err_* constants — translate to operator-friendly text.
        // INI_SIZE / FORM_SIZE both surface the same way to the user: the
        // file was too big.
        switch ($err) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'file exceeded the upload size limit';
            case UPLOAD_ERR_PARTIAL:
                return 'upload was interrupted';
            case UPLOAD_ERR_NO_FILE:
                return 'no file was sent';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'server is missing its tmp directory';
            case UPLOAD_ERR_CANT_WRITE:
                return 'server could not write the upload to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'a PHP extension blocked the upload';
            default:
                return "PHP upload error code {$err}";
        }
    }

    /**
     * Emit a JSON response and unwind. Same test-seam pattern as
     * ApiKeysController::respond().
     *
     * @param array<string,mixed> $payload
     */
    protected function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload);
        throw new PluginControllerResponseSent($status);
    }

    /**
     * Emit a canonical GUI error envelope through the test-seam `respond()`.
     * Mirrors the helper in ApiKeysController / PaybackMethodsController.
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

    // =========================================================================
    // Upgrade actions
    // =========================================================================

    /**
     * AJAX action: `pluginsUploadAsUpgrade`. Same upload surface as
     * `pluginsUpload`, but routed through the upgrade flow instead of
     * the install flow. The GUI calls this when the user explicitly
     * confirms "Replace 1.2 with 1.3" after a regular `pluginsUpload`
     * returned 409 already_installed with new_version/current_version
     * context — a two-step confirmation so operators can't accidentally
     * blow away a stateful plugin by re-uploading.
     *
     * Error envelopes mirror the install path's set, plus:
     *   - 400 not_installed          — no installed plugin with that id
     *   - 400 same_version           — incoming version equals installed
     *   - 400 downgrade_refused      — incoming version is older
     *   - 400 min_upgradable_violation — installed version too old per
     *                                    the new manifest's
     *                                    min_upgradable_from declaration
     *   - 500 upgrade_unavailable    — upgrade service not wired
     *   - 500 upgrade_failed         — filesystem / hook / supervisor failure
     */
    private function uploadAsUpgrade(): void
    {
        if ($this->upgradeService === null) {
            $this->respondError(
                'upgrade_unavailable',
                'Plugin upgrade service is not wired in this context.',
                500
            );
        }

        $file = $_FILES['plugin_zip'] ?? null;
        if (!is_array($file)) {
            $this->respondError('invalid_upload', 'No file uploaded (expected field: plugin_zip)', 400);
        }
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $this->respondError(
                'invalid_upload',
                'Upload failed: ' . $this->describeUploadError($err),
                400
            );
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !$this->isUploadedFile($tmpPath)) {
            $this->respondError('invalid_upload', 'Upload did not come from this request', 400);
        }
        $originalName = (string) ($file['name'] ?? '');

        try {
            $result = $this->upgradeService->upgradeFromZip($tmpPath, $originalName);
        } catch (\InvalidArgumentException $e) {
            // Map specific upgrade-refused messages to dedicated error
            // codes so the GUI can render the right "why" without
            // parsing free-text.
            $msg = $e->getMessage();
            $code = $this->classifyUpgradeRefusal($msg);
            $this->respondError($code, $msg, 400);
        } catch (\RuntimeException $e) {
            $this->respondError('upgrade_failed', $e->getMessage(), 500);
        } catch (Throwable $e) {
            Logger::getInstance()->logException($e, [
                'context' => 'plugin_upload_as_upgrade',
                'original_filename' => $originalName,
            ]);
            $this->respondError('upgrade_failed', 'Unexpected error during upgrade', 500);
        }

        Logger::getInstance()->info('plugin_upgraded_via_gui_zip', [
            'plugin' => $result['plugin_id'],
            'old_version' => $result['old_version'],
            'new_version' => $result['new_version'],
            'backup_dir' => $result['backup_dir'],
        ]);

        $this->respond([
            'success' => true,
            'plugin_id' => $result['plugin_id'],
            'old_version' => $result['old_version'],
            'new_version' => $result['new_version'],
            'backup_dir' => $result['backup_dir'],
            'steps' => $result['steps'],
            // The new plugin code is on disk and (if enabled) the FPM
            // pool was reloaded. Enable-flag flips persist immediately
            // for plugins that were already enabled; the wallet pool's
            // own service graph might still reference cached state, so
            // a restart picks up changes to register()/boot() bindings
            // the new version may have altered.
            'restart_required' => true,
            'message' => "Plugin upgraded: {$result['plugin_id']} ({$result['old_version']} → {$result['new_version']}). Old version preserved at {$result['backup_dir']} for rollback.",
        ]);
    }

    /**
     * AJAX action: `pluginsUpgrade`. Operator clicks the "Upgrade
     * available" affordance on a plugin row in the GUI; the row already
     * surfaces the bundled-newer fact via the listPlugins enrichment, so
     * by the time this action fires the operator has already seen the
     * version delta.
     *
     * Request body: `plugin=<id>`. Same error-code shape as
     * uploadAsUpgrade plus a couple bundle-specific cases.
     */
    private function upgradeBundled(): void
    {
        if ($this->upgradeService === null) {
            $this->respondError(
                'upgrade_unavailable',
                'Plugin upgrade service is not wired in this context.',
                500
            );
        }

        $name = $_POST['plugin'] ?? '';
        if (!is_string($name) || !preg_match('/^[a-z0-9][a-z0-9-_]{0,63}$/i', $name)) {
            $this->respondError(
                'invalid_plugin_name',
                'plugin must be kebab-case, 1-64 chars',
                400
            );
        }

        try {
            $result = $this->upgradeService->upgradeFromBundle($name);
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            $code = $this->classifyUpgradeRefusal($msg);
            $this->respondError($code, $msg, 400);
        } catch (\RuntimeException $e) {
            $this->respondError('upgrade_failed', $e->getMessage(), 500);
        } catch (Throwable $e) {
            Logger::getInstance()->logException($e, [
                'context' => 'plugin_upgrade_bundled',
                'plugin' => $name,
            ]);
            $this->respondError('upgrade_failed', 'Unexpected error during upgrade', 500);
        }

        Logger::getInstance()->info('plugin_upgraded_via_gui_bundle', [
            'plugin' => $result['plugin_id'],
            'old_version' => $result['old_version'],
            'new_version' => $result['new_version'],
            'backup_dir' => $result['backup_dir'],
        ]);

        $this->respond([
            'success' => true,
            'plugin_id' => $result['plugin_id'],
            'old_version' => $result['old_version'],
            'new_version' => $result['new_version'],
            'backup_dir' => $result['backup_dir'],
            'steps' => $result['steps'],
            'restart_required' => true,
            'message' => "Plugin upgraded: {$result['plugin_id']} ({$result['old_version']} → {$result['new_version']}). Old version preserved at {$result['backup_dir']} for rollback.",
        ]);
    }

    /**
     * Map an InvalidArgumentException message from PluginUpgradeService
     * to a stable error code the GUI's JS can pivot on. The service's
     * messages are stable strings; we match on substrings to avoid
     * locking the controller to verbatim text.
     */
    private function classifyUpgradeRefusal(string $msg): string
    {
        if (stripos($msg, 'not installed') !== false)          return 'not_installed';
        if (stripos($msg, 'already at version') !== false)     return 'same_version';
        if (stripos($msg, 'Refusing downgrade') !== false)     return 'downgrade_refused';
        if (stripos($msg, 'min_upgradable_from') !== false)    return 'min_upgradable_violation';
        if (stripos($msg, 'No bundled version') !== false)     return 'no_bundle';
        return 'upgrade_refused';
    }
}

/**
 * Internal control-flow exception used only to unwind the stack after a
 * JSON response has been emitted.
 */
class PluginControllerResponseSent extends \RuntimeException
{
    public int $httpStatus;
    public function __construct(int $httpStatus)
    {
        parent::__construct('Plugin controller response already sent');
        $this->httpStatus = $httpStatus;
    }
}
