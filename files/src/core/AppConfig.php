<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Core;

/**
 * Typed value object for environment-driven runtime configuration.
 *
 * Centralizes the env vars that previously had inline `getenv()` calls
 * scattered across services — one read at construction, every consumer
 * accesses typed getters via DI. Tests build a frozen instance with
 * `AppConfig::withOverrides([...])` instead of mutating process env.
 *
 * SCOPE NOTE. This object intentionally does NOT (yet) own the env vars
 * already exposed via `Constants::is*()` / `UserContext::get*()` static
 * getters (EIOU_*, APP_ENV, APP_DEBUG). Migrating those is a separate
 * pass — every consumer would need DI plumbing for what today is a
 * static call. AppConfig owns the flags that had no centralized
 * accessor yet, plus APP_ENV / APP_DEBUG (which are referenced often
 * enough in test paths that having them on a swappable object is
 * worth doing now).
 *
 * EIOU_TEST_MODE is deliberately omitted: that bypass is now a
 * PHPUnit-bootstrap-only `define()`-time constant, and the surviving
 * `getenv('EIOU_TEST_MODE')` checks in `RateLimiterService` exist as
 * security telemetry (loud-warn when the legacy env var is set on a
 * non-test build) — those reads must stay raw and uncached.
 */
final class AppConfig
{
    public function __construct(
        public readonly bool $pluginHooksTrace,
        public readonly bool $p2pSslVerify,
        public readonly ?string $p2pCaCert,
        public readonly string $trustedProxies,
        public readonly ?string $sslExtraSans,
        public readonly string $appEnv,
        public readonly bool $appDebug,
        public readonly bool $publicPluginRoutes,
    ) {
    }

    /**
     * Build from process environment. Called once at bootstrap.
     */
    public static function fromEnvironment(): self
    {
        $p2pSslVerifyRaw = getenv('P2P_SSL_VERIFY');
        $caCert = getenv('P2P_CA_CERT');
        $extraSans = getenv('SSL_EXTRA_SANS');
        $appEnvRaw = getenv('APP_ENV');
        $appDebugRaw = getenv('APP_DEBUG');

        return new self(
            pluginHooksTrace: self::boolFromEnv(getenv('PLUGIN_HOOKS_TRACE'), false),
            p2pSslVerify: $p2pSslVerifyRaw === false ? true : ($p2pSslVerifyRaw !== 'false'),
            p2pCaCert: ($caCert !== false && $caCert !== '') ? $caCert : null,
            trustedProxies: getenv('TRUSTED_PROXIES') ?: '',
            sslExtraSans: ($extraSans !== false && $extraSans !== '') ? $extraSans : null,
            appEnv: ($appEnvRaw !== false && $appEnvRaw !== '') ? $appEnvRaw : Constants::APP_ENV,
            appDebug: self::boolFromEnv($appDebugRaw, Constants::APP_DEBUG),
            // Off by default — the public-route surface lets sandboxed
            // plugins expose non-admin HTTP endpoints under /p/<id>/.
            // Operators opt in once they're comfortable with their
            // plugins' bearer auth + rate-limit posture.
            publicPluginRoutes: self::boolFromEnv(getenv('EIOU_PUBLIC_PLUGIN_ROUTES'), false),
        );
    }

    /**
     * Build a copy with selected fields overridden. Useful for tests
     * that need to flip a single flag without touching process env.
     */
    public function withOverrides(array $overrides): self
    {
        return new self(
            pluginHooksTrace:    $overrides['pluginHooksTrace']    ?? $this->pluginHooksTrace,
            p2pSslVerify:        $overrides['p2pSslVerify']        ?? $this->p2pSslVerify,
            p2pCaCert:           array_key_exists('p2pCaCert', $overrides)  ? $overrides['p2pCaCert']  : $this->p2pCaCert,
            trustedProxies:      $overrides['trustedProxies']      ?? $this->trustedProxies,
            sslExtraSans:        array_key_exists('sslExtraSans', $overrides) ? $overrides['sslExtraSans'] : $this->sslExtraSans,
            appEnv:              $overrides['appEnv']              ?? $this->appEnv,
            appDebug:            $overrides['appDebug']            ?? $this->appDebug,
            publicPluginRoutes:  $overrides['publicPluginRoutes']  ?? $this->publicPluginRoutes,
        );
    }

    private static function boolFromEnv(string|false $raw, bool $default): bool
    {
        if ($raw === false) {
            return $default;
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
