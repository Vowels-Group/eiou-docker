<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use Eiou\Contracts\PluginCallable;
use Eiou\Contracts\PluginCallerAware;
use Eiou\Utils\Logger;
use ReflectionMethod;
use Throwable;

// ServiceContainer is referenced loosely (object) so this class can be
// constructed with a fixture in tests; not imported.

/**
 * PluginGatewayController
 *
 * HTTP endpoint a sandboxed plugin's __dispatch.php calls to reach
 * core services. Lives behind nginx at `/__plugin_gateway`, served by
 * the wallet's www-data FPM pool — so the dispatched method runs with
 * the wallet's privileges (master key in scope, full DB access) but
 * only after every gate below has passed.
 *
 * Auth chain — every gate must pass:
 *
 *   1. Authorization: Bearer <token>  → PluginGatewayTokenService
 *      resolves to a plugin_id, or 401.
 *   2. The plugin's manifest names "<service>.<method>" in its
 *      "core_services" allow-list → 403 if not.
 *   3. The target method exists on the resolved service AND carries
 *      the #[PluginCallable] attribute → 403 if not.
 *   3b. If the #[PluginCallable] attribute carries a `permission:`
 *       key, the plugin's manifest also names that key in its
 *       top-level `permissions` list → 403 if not. Adds a second,
 *       louder-consent tier above `core_services` for surfaces the
 *       operator would meaningfully reconsider on a second read
 *       (bulk enumeration, broad-scope mutation). See
 *       PluginPermissionCatalog for catalogued keys.
 *   4. Argument values are scalar | array-of-scalars (no callables,
 *      objects, or resources) → 400 if not.
 *   5. Plugin's per-minute rate limit not exceeded → 429 if exceeded.
 *
 * Service resolution: "<ServiceName>" maps to
 * `ServiceContainer::get<ServiceName>()`. So "Logger" → getLogger(),
 * "ContactService" → getContactService(). Methods not present on the
 * container raise a 503 (server misconfiguration, not the plugin's
 * fault).
 *
 * Return values are JSON-encoded into the response body. An attributed
 * method returning anything that doesn't survive json_encode (objects,
 * resources) becomes a 500 — the attribute author broke the contract.
 *
 * See docs/PLUGINS.md (Sandboxing) for the broader trust model.
 */
class PluginGatewayController
{
    /**
     * Per-plugin call cap when a #[PluginCallable] doesn't override.
     * A single plugin under this default can sustain ~16 calls/sec,
     * which is plenty for declarative event/hook handlers and
     * still bounds a runaway plugin's blast radius.
     */
    public const DEFAULT_RATE_PER_MINUTE = 1000;

    private PluginGatewayTokenService $tokenService;
    private PluginLoader $loader;
    /**
     * Loose type so tests can supply a fixture container without
     * re-wiring ServiceContainer's private constructor. The contract
     * is "any object that responds to getXxx() per the convention";
     * method_exists is the runtime gate before dispatch.
     */
    private object $container;
    private ?Logger $logger;

    /**
     * Per-process call counts: $callCounts[pluginId][serviceMethod] = [{ts, count}, ...].
     * Reset on FPM worker restart — workers cycle every ~10s on ondemand
     * pool so this is per-burst rather than per-day. Coarse enough to
     * trip a runaway plugin while staying lock-free.
     *
     * @var array<string, array<string, list<array{ts:int, count:int}>>>
     */
    private array $callCounts = [];

    public function __construct(
        PluginGatewayTokenService $tokenService,
        PluginLoader $loader,
        object $container,
        ?Logger $logger = null
    ) {
        $this->tokenService = $tokenService;
        $this->loader = $loader;
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Handle one gateway request.
     *
     * @param string $rawBody The request body (php://input)
     * @param array<string, string> $headers Lower-case header names → value
     * @return array{status:int, body:array<string, mixed>}
     */
    public function handle(string $rawBody, array $headers): array
    {
        // Gate 1: auth header.
        $token = $this->extractBearerToken($headers);
        if ($token === null) {
            return $this->errorResponse(401, 'missing_token', 'Authorization: Bearer <token> required');
        }
        $pluginId = $this->tokenService->pluginIdForToken($token);
        if ($pluginId === null) {
            return $this->errorResponse(401, 'invalid_token', 'Token not recognized');
        }

        // Parse JSON body.
        if ($rawBody === '') {
            return $this->errorResponse(400, 'empty_body', 'request body required');
        }
        $envelope = json_decode($rawBody, true);
        if (!is_array($envelope)) {
            return $this->errorResponse(400, 'malformed_body', 'request body must be JSON object');
        }
        $service = (string) ($envelope['service'] ?? '');
        $method  = (string) ($envelope['method']  ?? '');
        $args    = is_array($envelope['args'] ?? null) ? $envelope['args'] : [];

        if ($service === '' || $method === '') {
            return $this->errorResponse(400, 'missing_fields', 'service and method are required');
        }
        // Validate names — defence against an attacker who got a token
        // but is trying to invoke arbitrary code via crafted service/
        // method strings. Service names follow PascalCase, methods
        // follow camelCase, and neither may contain backslash / null /
        // whitespace. Reflective access uses the strings verbatim.
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $service)) {
            return $this->errorResponse(400, 'invalid_service_name', "service name '{$service}' is invalid");
        }
        if (!preg_match('/^[a-z][A-Za-z0-9_]*$/', $method)) {
            return $this->errorResponse(400, 'invalid_method_name', "method name '{$method}' is invalid");
        }

        $allowKey = $service . '.' . $method;

        // Gate 2: plugin's manifest allow-list.
        $allowList = $this->pluginAllowList($pluginId);
        if (!in_array($allowKey, $allowList, true)) {
            return $this->errorResponse(
                403, 'method_not_in_manifest',
                "plugin '{$pluginId}' did not declare '{$allowKey}' in core_services"
            );
        }

        // Resolve service via the container — getXxx() convention.
        $getter = 'get' . $service;
        if (!method_exists($this->container, $getter)) {
            return $this->errorResponse(
                503, 'unknown_service',
                "service '{$service}' has no getter on ServiceContainer"
            );
        }
        try {
            $serviceInstance = $this->container->{$getter}();
        } catch (Throwable $e) {
            $this->log('error', 'plugin_gateway_service_resolve_failed', [
                'plugin' => $pluginId, 'service' => $service, 'error' => $e->getMessage(),
            ]);
            return $this->errorResponse(503, 'service_resolve_failed', $e->getMessage());
        }
        if (!is_object($serviceInstance) || !method_exists($serviceInstance, $method)) {
            return $this->errorResponse(
                403, 'method_not_found',
                "method '{$method}' not present on service '{$service}'"
            );
        }

        // Gate 3: #[PluginCallable] attribute on the method.
        try {
            $reflection = new ReflectionMethod($serviceInstance, $method);
        } catch (Throwable $e) {
            return $this->errorResponse(403, 'method_not_callable', $e->getMessage());
        }
        $attributes = $reflection->getAttributes(PluginCallable::class);
        if (count($attributes) === 0) {
            return $this->errorResponse(
                403, 'method_not_callable',
                "'{$allowKey}' lacks the #[PluginCallable] attribute — refused"
            );
        }
        /** @var PluginCallable $pluginCallable */
        $pluginCallable = $attributes[0]->newInstance();

        // Gate 3b: permission tier. When the attribute carries a
        // permission key, require the plugin's manifest to also
        // declare it in `permissions`. Two failure modes here:
        //
        //   - Unknown key: the attribute references a permission
        //     the host doesn't catalogue. Almost certainly a
        //     programmer error post-rename — fail closed with 503
        //     (server misconfiguration, not the plugin's fault).
        //   - Not granted: the plugin's manifest is missing the
        //     key. 403 with a message naming the key so the plugin
        //     author can add it.
        if ($pluginCallable->permission !== null) {
            $permKey = $pluginCallable->permission;
            if (!PluginPermissionCatalog::isKnown($permKey)) {
                $this->log('error', 'plugin_gateway_unknown_permission_attribute', [
                    'plugin' => $pluginId, 'call' => $allowKey, 'permission' => $permKey,
                ]);
                return $this->errorResponse(
                    503, 'unknown_permission_attribute',
                    "'{$allowKey}' carries permission '{$permKey}' which is not in the host catalog"
                );
            }
            $granted = $this->pluginPermissions($pluginId);
            if (!in_array($permKey, $granted, true)) {
                return $this->errorResponse(
                    403, 'permission_not_granted',
                    "plugin '{$pluginId}' did not declare permission '{$permKey}' "
                    . "in its manifest 'permissions' list (required for '{$allowKey}')"
                );
            }
        }

        // Gate 4: argument shape.
        if (!$this->argsAreSafe($args)) {
            return $this->errorResponse(400, 'unsafe_args', 'args must be scalar or array-of-scalars only');
        }

        // Gate 5: rate limit.
        $cap = $pluginCallable->ratePerMinute ?? self::DEFAULT_RATE_PER_MINUTE;
        if (!$this->underRateLimit($pluginId, $allowKey, $cap)) {
            return $this->errorResponse(429, 'rate_limited', "rate cap {$cap}/min exceeded");
        }

        // Plugin-caller context — services that implement
        // PluginCallerAware get the calling plugin id injected for the
        // duration of the call and cleared after, whether the call
        // returns or throws. Skipped for services that don't need it.
        $callerAware = $serviceInstance instanceof PluginCallerAware;
        if ($callerAware) {
            $serviceInstance->setCallingPluginId($pluginId);
        }

        // Dispatch.
        try {
            $result = $serviceInstance->{$method}(...$args);
        } catch (Throwable $e) {
            if ($callerAware) {
                $serviceInstance->setCallingPluginId(null);
            }
            $this->log('warning', 'plugin_gateway_call_threw', [
                'plugin' => $pluginId,
                'call' => $allowKey,
                'error_class' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse(500, 'call_threw', $e->getMessage(), [
                'error_class' => get_class($e),
            ]);
        }
        if ($callerAware) {
            $serviceInstance->setCallingPluginId(null);
        }
        if (!$this->resultIsMarshallable($result)) {
            $this->log('error', 'plugin_gateway_unmarshallable_return', [
                'plugin' => $pluginId, 'call' => $allowKey, 'type' => gettype($result),
            ]);
            return $this->errorResponse(
                500, 'unmarshallable_return',
                "method '{$allowKey}' returned a value that doesn't survive json_encode"
            );
        }

        return [
            'status' => 200,
            'body'   => ['ok' => true, 'result' => $result],
        ];
    }

    /**
     * @param array<string, string> $headers Lower-case header names.
     */
    private function extractBearerToken(array $headers): ?string
    {
        $auth = $headers['authorization'] ?? '';
        if (!preg_match('/^\s*Bearer\s+([a-f0-9]{64})\s*$/i', $auth, $m)) {
            return null;
        }
        return strtolower($m[1]);
    }

    /**
     * @return list<string> e.g. ["Logger.info", "ContactService.lookupByPubkey"]
     */
    private function pluginAllowList(string $pluginId): array
    {
        foreach ($this->loader->listAllPlugins() as $row) {
            if (($row['name'] ?? null) === $pluginId) {
                $allow = $row['core_services'] ?? [];
                if (is_array($allow)) {
                    return array_values(array_filter($allow, 'is_string'));
                }
            }
        }
        return [];
    }

    /**
     * @return list<string> permission keys the plugin's manifest declared
     */
    private function pluginPermissions(string $pluginId): array
    {
        foreach ($this->loader->listAllPlugins() as $row) {
            if (($row['name'] ?? null) === $pluginId) {
                $perms = $row['permissions'] ?? [];
                if (is_array($perms)) {
                    return array_values(array_filter($perms, 'is_string'));
                }
            }
        }
        return [];
    }

    /**
     * Recursively check args are scalar / array of scalars / null only.
     * No objects, callables, resources. Depth-limited to 4 so a
     * pathologically nested array can't lock the worker on validation.
     */
    private function argsAreSafe(array $args, int $depth = 0): bool
    {
        if ($depth > 4) {
            return false;
        }
        foreach ($args as $v) {
            if ($v === null || is_scalar($v)) continue;
            if (is_array($v) && $this->argsAreSafe($v, $depth + 1)) continue;
            return false;
        }
        return true;
    }

    private function resultIsMarshallable(mixed $value): bool
    {
        return @json_encode($value) !== false && !is_resource($value);
    }

    private function underRateLimit(string $pluginId, string $allowKey, int $cap): bool
    {
        $now = time();
        $windowStart = $now - 60;
        $bucket = &$this->callCounts[$pluginId][$allowKey];
        if (!isset($bucket)) {
            $bucket = [];
        }
        // Drop buckets older than the 60s window.
        $bucket = array_values(array_filter(
            $bucket,
            fn(array $b): bool => $b['ts'] > $windowStart
        ));
        $total = array_sum(array_column($bucket, 'count'));
        if ($total >= $cap) {
            return false;
        }
        // Coalesce same-second hits into one entry to keep the list short.
        if (!empty($bucket) && end($bucket)['ts'] === $now) {
            $bucket[count($bucket) - 1]['count']++;
        } else {
            $bucket[] = ['ts' => $now, 'count' => 1];
        }
        return true;
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    private function errorResponse(int $status, string $code, string $message, array $extras = []): array
    {
        return [
            'status' => $status,
            'body'   => array_merge([
                'ok'    => false,
                'error' => ['code' => $code, 'message' => $message],
            ], $extras),
        ];
    }

    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) return;
        $this->logger->{$level}($message, $context);
    }
}
