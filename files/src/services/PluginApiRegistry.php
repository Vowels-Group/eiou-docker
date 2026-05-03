<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use InvalidArgumentException;
use Throwable;

/**
 * Plugin API Registry
 *
 * Lets plugins register REST endpoints under `/api/v1/plugins/{plugin}/{action}`.
 * Plugins register during boot() via
 * `$container->getPluginApiRegistry()->register('myplugin', 'GET', 'status', fn(...) => ...)`.
 *
 * `ApiController::handlePlugins()` routes to this registry whenever the
 * action/id combo doesn't match the core `enable` / `disable` operations,
 * so `GET /api/v1/plugins/myplugin/status` lands here. The core `plugins`
 * resource stays admin-scoped end-to-end — the registry inherits that
 * enforcement from the caller, which means plugin authors don't have to
 * re-implement auth on each endpoint for v1.
 *
 * Handler contract:
 *   fn(string $method, array $params, string $body): array
 * Return value is the response payload array; the controller wraps it in
 * `successResponse()` / serialises to JSON. Throw on error (the registry
 * catches and translates to a 500 response).
 *
 * Plugin names and actions must be kebab-case. Single-level path only —
 * nested routes (e.g. `/api/v1/plugins/myplugin/users/123`) are not
 * supported in v1 because the API router's path parsing stops at the
 * fifth path segment.
 */
class PluginApiRegistry
{
    /** @var array<string, array<string, callable>> plugin => { "GET status" => callable, ... } */
    private array $handlers = [];

    /**
     * Register an endpoint for a plugin.
     *
     * Collision guard: re-registering the same (plugin, method, action)
     * tuple throws, because silent overwrites would make plugin-conflict
     * debugging nearly impossible.
     */
    public function register(string $plugin, string $method, string $action, callable $handler): void
    {
        $plugin = strtolower(trim($plugin));
        $method = strtoupper(trim($method));
        $action = strtolower(trim($action));

        if ($plugin === '' || !preg_match('/^[a-z][a-z0-9-]{0,31}$/', $plugin)) {
            throw new InvalidArgumentException(
                "Plugin name must be kebab-case, 1-32 chars, start with a letter: got '{$plugin}'"
            );
        }
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            throw new InvalidArgumentException(
                "HTTP method must be one of GET/POST/PUT/DELETE/PATCH: got '{$method}'"
            );
        }
        if ($action === '' || !preg_match('/^[a-z][a-z0-9-]{0,63}$/', $action)) {
            throw new InvalidArgumentException(
                "Plugin API action must be kebab-case, 1-64 chars: got '{$action}'"
            );
        }
        // 'enable' and 'disable' are reserved by the core plugins resource —
        // a plugin endpoint named 'enable' would shadow the toggle.
        if (in_array($action, ['enable', 'disable'], true)) {
            throw new InvalidArgumentException(
                "Plugin API action '{$action}' is reserved for core plugin toggling."
            );
        }

        $key = $method . ' ' . $action;
        if (isset($this->handlers[$plugin][$key])) {
            throw new InvalidArgumentException(
                "Plugin '{$plugin}' already has a handler for {$method} {$action}."
            );
        }
        $this->handlers[$plugin][$key] = $handler;
    }

    public function has(string $plugin, string $method, string $action): bool
    {
        $key = strtoupper($method) . ' ' . strtolower($action);
        return isset($this->handlers[strtolower($plugin)][$key]);
    }

    /**
     * Dispatch a plugin endpoint. Returns the caller's response payload or
     * a structured error array. Exceptions thrown by the handler are
     * caught and turned into 500 responses so a misbehaving plugin can't
     * leak stack traces to API clients.
     *
     * @return array{payload:array<string,mixed>, status:int}
     */
    public function dispatch(string $plugin, string $method, string $action, array $params, string $body): array
    {
        $plugin = strtolower($plugin);
        $method = strtoupper($method);
        $action = strtolower($action);
        $key = $method . ' ' . $action;

        if (!isset($this->handlers[$plugin][$key])) {
            return [
                'payload' => [
                    'success' => false,
                    'error' => 'plugin_route_not_found',
                    'message' => "No handler registered for {$method} /api/v1/plugins/{$plugin}/{$action}",
                ],
                'status' => 404,
            ];
        }

        try {
            $result = ($this->handlers[$plugin][$key])($method, $params, $body);
            if (!is_array($result)) {
                $result = ['result' => $result];
            }
            return ['payload' => $result, 'status' => 200];
        } catch (Throwable $e) {
            return [
                'payload' => [
                    'success' => false,
                    'error' => 'plugin_handler_error',
                    'message' => $e->getMessage(),
                    'plugin' => $plugin,
                ],
                'status' => 500,
            ];
        }
    }

    /**
     * Flat list of registered endpoints for debugging / introspection.
     *
     * @return list<array{plugin:string, method:string, action:string}>
     */
    public function listRegistered(): array
    {
        $out = [];
        foreach ($this->handlers as $plugin => $routes) {
            foreach (array_keys($routes) as $key) {
                [$method, $action] = explode(' ', $key, 2);
                $out[] = ['plugin' => $plugin, 'method' => $method, 'action' => $action];
            }
        }
        return $out;
    }
}
