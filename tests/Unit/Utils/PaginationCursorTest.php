<?php
/**
 * Unit Tests for PaginationCursor
 *
 * Covers the encode/decode round-trip, the "malformed cursor decodes to
 * null" contract (so tampered cursors fall back to first-page behavior),
 * and the URL-safe alphabet (no `+` or `/` in the wire form).
 */

namespace Eiou\Tests\Utils;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Utils\PaginationCursor;

#[CoversClass(PaginationCursor::class)]
class PaginationCursorTest extends TestCase
{
    public function testRoundTripPreservesScalarPayload(): void
    {
        $payload = ['time' => 1714705421, 'timestamp' => '2026-04-30 12:34:56', 'txid' => 'abc123def'];
        $encoded = PaginationCursor::encode($payload);
        $this->assertNotSame('', $encoded);
        $this->assertSame($payload, PaginationCursor::decode($encoded));
    }

    public function testEncodedFormIsBase64UrlSafe(): void
    {
        // base64 alphabet contains `+` and `/`; URL-safe variant uses
        // `-` and `_`. Verify the wire form has neither of the unsafe
        // chars, since cursors travel in form-encoded POST bodies and
        // pollute as `+` is decoded back to space.
        $encoded = PaginationCursor::encode(['payload' => str_repeat('a', 100) . '?+/=']);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
        $this->assertStringNotContainsString('=', $encoded);
    }

    public function testDecodeEmptyOrNullReturnsNull(): void
    {
        $this->assertNull(PaginationCursor::decode(null));
        $this->assertNull(PaginationCursor::decode(''));
    }

    public function testDecodeMalformedBase64ReturnsNull(): void
    {
        // Garbage that isn't valid base64 must NOT throw and must NOT
        // partially-decode — clients tampering with the cursor get a
        // safe fallback (null = first page).
        $this->assertNull(PaginationCursor::decode('@@@not-base64@@@'));
    }

    public function testDecodeValidBase64ButNotJsonReturnsNull(): void
    {
        $notJson = rtrim(strtr(base64_encode('hello world'), '+/', '-_'), '=');
        $this->assertNull(PaginationCursor::decode($notJson));
    }

    public function testDecodeValidJsonButNotArrayReturnsNull(): void
    {
        // A bare scalar at the top level is technically valid JSON
        // (`"foo"`) but isn't a payload shape we accept.
        $scalarJson = rtrim(strtr(base64_encode('"just a string"'), '+/', '-_'), '=');
        $this->assertNull(PaginationCursor::decode($scalarJson));
    }

    public function testDecodeRejectsNonStringKeys(): void
    {
        // PHP's json_decode($_, true) on `[1,2,3]` returns a list with
        // integer keys. Cursor payloads must be a string-keyed map so a
        // forged list can't mascarade as one.
        $listJson = rtrim(strtr(base64_encode('[1,2,3]'), '+/', '-_'), '=');
        $this->assertNull(PaginationCursor::decode($listJson));
    }

    public function testDecodeRejectsNonScalarValues(): void
    {
        // Nested object/array values are not allowed — the codec is
        // designed for flat scalar tuples only.
        $nestedJson = rtrim(strtr(base64_encode('{"a":{"b":1}}'), '+/', '-_'), '=');
        $this->assertNull(PaginationCursor::decode($nestedJson));
    }

    public function testNullValuesAreAllowed(): void
    {
        // `time` may be null in the transactions cursor (the column is
        // nullable). Make sure null survives the round-trip.
        $payload = ['time' => null, 'timestamp' => '2026-01-01 00:00:00', 'txid' => 't1'];
        $encoded = PaginationCursor::encode($payload);
        $this->assertSame($payload, PaginationCursor::decode($encoded));
    }
}
