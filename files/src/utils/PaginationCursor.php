<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Utils;

/**
 * Opaque cursor codec for keyset pagination.
 *
 * Replaces `LIMIT ? OFFSET ?` for tables where deep pagination would be
 * a linear scan past N skipped rows (transaction history, payment-request
 * history, accepted-contacts list). The cursor encodes the last-seen
 * row's sort-key tuple — typically `(timestamp, txid)` or `(name, id)`
 * — so the next page is `WHERE keyset < cursor LIMIT N`, which uses the
 * existing ORDER BY index directly and runs in constant time regardless
 * of how deep into the history the user has paged.
 *
 * Wire format: base64url(json_encode($payload)). The payload is opaque
 * to the client; servers parse it with `decode()` before binding.
 *
 * Tor Browser compatibility: cursors travel in the POST form body
 * exactly like the legacy `offset` integer, so no new browser APIs,
 * storage, or fingerprinting surface is introduced.
 *
 * Note on scope: this is a codec, NOT a query builder. Callers compose
 * their own `(a, b, c) < (?, ?, ?)` predicate (or the OR-expanded form
 * for older MySQL versions) using the values returned from `decode()`.
 */
final class PaginationCursor
{
    /**
     * Encode a key-value array into the wire cursor string.
     *
     * @param array<string, scalar> $payload  e.g. `['ts' => 1714705421, 'txid' => 'abc...']`
     * @return string                         opaque base64url string
     */
    public static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * Decode a wire cursor back into its payload array.
     *
     * Returns null on any malformed input (bad base64, non-JSON, non-array,
     * or array with non-scalar values). Callers must treat null as "no
     * cursor" — a malformed cursor means the client tried to forge or
     * tampered with it; the safe fallback is the first page.
     *
     * @param string|null $cursor
     * @return array<string, scalar>|null
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $b64 = strtr($cursor, '-_', '+/');
        $padded = $b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $json = base64_decode($padded, true);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                return null;
            }
        }
        return $decoded;
    }
}
