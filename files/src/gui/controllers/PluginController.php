<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Gui\Includes\Session;
use Eiou\Services\PluginLoader;
use Eiou\Services\RestartRequestService;
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

    public function __construct(
        Session $session,
        PluginLoader $loader,
        ?RestartRequestService $restartRequester = null
    ) {
        $this->session = $session;
        $this->loader = $loader;
        $this->restartRequester = $restartRequester ?? new RestartRequestService();
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
                $this->respond(['success' => false, 'error' => 'csrf_error'], 403);
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
                default:
                    $this->respond(['success' => false, 'error' => 'unknown_action'], 400);
            }
        } catch (PluginControllerResponseSent $sent) {
            throw $sent;
        } catch (Throwable $e) {
            Logger::getInstance()->logException($e, [
                'context' => 'plugin_controller',
                'action' => $_POST['action'] ?? '',
            ]);
            $this->respond([
                'success' => false,
                'error' => 'server_error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function listPlugins(): void
    {
        $plugins = $this->loader->listAllPlugins();
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
        // failed, disabled, not_loaded. Anything that ran register/boot is
        // "actually loaded" from the worker's perspective.
        $loadedStatuses = ['discovered', 'registered', 'booted'];

        foreach ($plugins as $p) {
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
            $user = \Eiou\Core\UserContext::getInstance();
            if ($user->getPublicKey() !== null) {
                $pubkeyHash = (string) $user->getPublicKeyHash();
            }
        } catch (Throwable $e) {
            // missing wallet / uninitialized state — leave hash empty
        }

        if (!$this->restartRequester->request('gui', $pubkeyHash)) {
            $this->respond([
                'success' => false,
                'error' => 'request_failed',
                'message' => 'Could not write the restart request file.',
            ], 500);
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
            $this->respond(['success' => false, 'error' => 'invalid_name'], 400);
        }

        // Refuse to toggle a plugin that doesn't exist on disk — otherwise
        // the state file accumulates ghost entries.
        $known = array_column($this->loader->listAllPlugins(), 'name');
        if (!in_array($name, $known, true)) {
            $this->respond(['success' => false, 'error' => 'unknown_plugin'], 404);
        }

        if (!$this->loader->setEnabled($name, $enabled)) {
            $this->respond(['success' => false, 'error' => 'persist_failed'], 500);
        }

        Logger::getInstance()->info('plugin_toggled_via_gui', [
            'plugin' => $name,
            'enabled' => $enabled,
        ]);

        $this->respond([
            'success' => true,
            'plugin' => $name,
            'enabled' => $enabled,
            'restart_required' => true,
        ]);
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
