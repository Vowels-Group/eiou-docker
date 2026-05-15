<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Core\AppConfig;
use Eiou\Core\UserContext;

/**
 * NodeInfoLookupService
 *
 * Read-only facade over node-level runtime config that sandboxed plugins
 * are allowed to inspect. Holds the small slice of AppConfig and
 * network-address fields that plugins legitimately need to construct
 * customer-facing URLs and adjust their own logging verbosity to the host
 * environment.
 *
 * Explicitly does NOT expose security-sensitive config (SSL cert paths,
 * trusted proxies, P2P verification flags) — a plugin that misbehaves with
 * those values would compromise the wallet's network posture, and there's
 * no plugin use case that justifies the risk.
 *
 * The HTTPS / Tor addresses returned here are the same identifiers the
 * wallet already broadcasts to peers, so exposing them to plugins doesn't
 * leak anything the network observer doesn't already learn.
 */
class NodeInfoLookupService
{
    private AppConfig $config;
    private UserContext $userContext;

    public function __construct(AppConfig $config, UserContext $userContext)
    {
        $this->config = $config;
        $this->userContext = $userContext;
    }

    #[PluginCallable(description: 'Return the runtime application environment string (e.g. "production", "development", "staging"). Plugins use this to gate verbose logging or sandbox-only behaviour.')]
    public function getAppEnv(): string
    {
        return $this->config->appEnv;
    }

    #[PluginCallable(description: 'Return whether the wallet is running with APP_DEBUG enabled. Plugins use this to flip detailed-error responses on the dev/staging path.')]
    public function isDebug(): bool
    {
        return $this->config->appDebug;
    }

    #[PluginCallable(description: 'Return the operator-configured HTTPS address (e.g. "https://wallet.example.com") for nodes that serve HTTPS. Null on Tor-only nodes. Plugins use this to construct customer-facing URLs that resolve to their own public_routes.')]
    public function getHttpsAddress(): ?string
    {
        return $this->userContext->getHttpsAddress();
    }

    #[PluginCallable(description: 'Return the node\'s Tor onion address (e.g. "abc...xyz.onion"). Null on nodes where Tor is unavailable or hasn\'t bootstrapped yet.')]
    public function getTorAddress(): ?string
    {
        return $this->userContext->getTorAddress();
    }
}
