<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Lookup;

use Eiou\Contracts\PluginCallable;
use Eiou\Core\UserContext;

/**
 * IdentityLookupService
 *
 * Read-only facade over UserContext for the small slice of node identity
 * that sandboxed plugins are allowed to inspect. Exposes the node's public
 * key, its hash, and operator-chosen display name. The private key, mnemonic,
 * encrypted-secret fields, and anything else from UserContext stay
 * unreachable.
 *
 * A plugin opts in by allow-listing `"IdentityLookupService.getPublicKey"`
 * (or another decorated method) in its manifest `core_services` list; the
 * gateway still gates on the allow-list, so the attribute alone does not
 * make the method callable.
 *
 * Use cases the surface unblocks:
 *
 *   - Co-sign / affiliate flows where a sibling service needs the node's
 *     pubkey to address the wallet (currently the only path is reading
 *     `userconfig.json`, which open_basedir blocks for plugin pools).
 *   - Display strings on a plugin's own customer-facing page that show
 *     "served by <node name>" without the plugin having to duplicate the
 *     operator's display name in its manifest.
 *   - Receipt / signature payloads where the plugin wants to attach the
 *     node identity it's running under for auditability.
 */
class IdentityLookupService
{
    private UserContext $userContext;

    public function __construct(UserContext $userContext)
    {
        $this->userContext = $userContext;
    }

    #[PluginCallable(description: 'Return the node\'s public key (hex). Null if the wallet has not been generated/restored yet.')]
    public function getPublicKey(): ?string
    {
        return $this->userContext->getPublicKey();
    }

    #[PluginCallable(description: 'Return the SHA-256 hash of the node\'s public key. Null if the wallet has not been generated/restored yet.')]
    public function getPublicKeyHash(): ?string
    {
        return $this->userContext->getPublicKeyHash();
    }

    #[PluginCallable(description: 'Return the operator-chosen display name for this node. Null if no name has been configured.')]
    public function getName(): ?string
    {
        return $this->userContext->getName();
    }
}
