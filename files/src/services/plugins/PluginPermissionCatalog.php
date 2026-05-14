<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

/**
 * PluginPermissionCatalog
 *
 * Single source of truth for permission keys a plugin can request in
 * its manifest's `permissions: [...]` list. Each entry carries:
 *
 *   - label: short human title shown in the install/enable GUI
 *   - description: one-paragraph explanation an operator can read to
 *     decide whether they're comfortable granting the permission
 *
 * Permission keys are referenced from #[PluginCallable(permission: ...)]
 * attributes; adding a key in the attribute without registering it
 * here is a programmer error — the catalog is the operator-facing
 * surface, so an un-catalogued key would gate a method on a key the
 * GUI can't describe.
 *
 * Why a catalog rather than per-method copy: the same permission can
 * gate several methods on the same service (or, eventually, across
 * services), and the operator's mental model is "this plugin can
 * enumerate my address book" — not "this plugin can call methods A
 * and B." Keeping the human copy on the permission key, not on the
 * method attribute, lets the GUI render one entry per permission
 * regardless of how many gated methods share it.
 *
 * Naming convention: lowercase, words separated by underscores,
 * `<noun_subject>_<verb>` so the key reads as a capability ("the
 * plugin may <verb> <noun>"). The shape is enforced by
 * PluginInstallService when validating the manifest field.
 */
final class PluginPermissionCatalog
{
    /**
     * @var array<string, array{label:string, description:string}>
     */
    private const ENTRIES = [
        'contact_address_book_enumerate' => [
            'label' => 'Enumerate your full contact address book',
            'description'
                => 'Lets the plugin list every accepted contact on this '
                . 'wallet — operator-chosen labels and all transport '
                . 'addresses, including .onion. Distinct from per-hash '
                . 'lookups (which only reveal contacts the plugin '
                . 'already knows about from event traffic). Grant only '
                . 'if the plugin has a stated reason to walk the full '
                . 'address book.',
        ],

        'transaction_history_enumerate' => [
            'label' => 'Read your transaction history',
            'description'
                => 'Lets the plugin walk the wallet\'s received- and '
                . 'sent-transaction lists, including amounts, '
                . 'currencies, descriptions, and counterparty pubkey '
                . 'hashes. Distinct from per-txid lookups (which only '
                . 'reveal transactions the plugin has already learned '
                . 'about through events). Reconciliation, accounting-'
                . 'export, and dashboard plugins typically need this; '
                . 'event-driven auto-settle plugins typically do not.',
        ],

        'wallet_balance_read' => [
            'label' => 'Read your wallet balance',
            'description'
                => 'Lets the plugin read the wallet\'s current balance '
                . 'totals (overall and per-currency) and per-contact '
                . 'balances. Useful for plugins that gate their '
                . 'behaviour on available funds — auto-settle, '
                . 'send-throttling, balance-on-dashboard. Discloses the '
                . 'operator\'s net financial position, so grant only '
                . 'when the plugin has a stated reason for it.',
        ],

        'wallet_outbound_send' => [
            'label' => 'Send payments on your behalf',
            'description'
                => 'Lets the plugin spend funds from the wallet by '
                . 'calling the outbound-send surface (same path as the '
                . 'eiou send CLI). Every plugin call is attributable in '
                . 'the wallet\'s transaction log and rate-capped, but '
                . 'within the cap the plugin can move money to '
                . 'addresses or named contacts. The most consequential '
                . 'permission in the catalog — grant only to plugins '
                . 'whose stated purpose involves spending.',
        ],
    ];

    /**
     * Whether the given key is a recognised permission. The gateway
     * uses this to refuse calls whose attribute references a key the
     * host doesn't catalogue (likely a programmer error after a key
     * rename); the install validator uses it to reject manifests that
     * request unknown permissions.
     */
    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::ENTRIES);
    }

    /**
     * @return array{label:string, description:string}|null
     */
    public static function get(string $key): ?array
    {
        return self::ENTRIES[$key] ?? null;
    }

    /**
     * Materialise the catalog rows for a plugin's granted-permission
     * list. Unknown keys are skipped silently — the install validator
     * is the upstream gate that prevents them from landing in a row
     * in the first place. Used by the plugin list endpoint to merge
     * human copy into what the GUI renders.
     *
     * @param list<string> $keys
     * @return list<array{key:string, label:string, description:string}>
     */
    public static function describe(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (!is_string($key)) continue;
            $entry = self::get($key);
            if ($entry === null) continue;
            $out[] = [
                'key'         => $key,
                'label'       => $entry['label'],
                'description' => $entry['description'],
            ];
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public static function knownKeys(): array
    {
        return array_keys(self::ENTRIES);
    }
}
