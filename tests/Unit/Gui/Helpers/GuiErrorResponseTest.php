<?php
/**
 * Unit tests for GuiErrorResponse — pins the canonical JSON-AJAX
 * error envelope shape so a typo or accidental field reorder doesn't
 * silently break frontend consumers that key off `error` or `code`.
 */

namespace Eiou\Tests\Gui\Helpers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Gui\Helpers\GuiErrorResponse;

#[CoversClass(GuiErrorResponse::class)]
class GuiErrorResponseTest extends TestCase
{
    public function testMakeReturnsCanonicalShape(): void
    {
        $result = GuiErrorResponse::make('csrf_invalid', 'Invalid CSRF token');

        $this->assertSame(
            ['success' => false, 'error' => 'Invalid CSRF token', 'code' => 'csrf_invalid'],
            $result
        );
    }

    public function testMakePreservesErrorAsHumanMessageNotCode(): void
    {
        // Back-compat invariant: legacy JS reads `response.error`
        // expecting a sentence. The helper must never overwrite that
        // field with the machine code.
        $result = GuiErrorResponse::make('dlq_not_found', 'DLQ item not found');

        $this->assertSame('DLQ item not found', $result['error']);
        $this->assertSame('dlq_not_found', $result['code']);
        $this->assertNotSame($result['error'], $result['code']);
    }

    public function testMakeDistinguishesBetweenSimilarSemanticCodes(): void
    {
        // Different codes for similar messages are intentional —
        // frontend can dispatch on `code` even when display text
        // is similar.
        $a = GuiErrorResponse::make('dlq_id_invalid', 'Invalid DLQ item ID');
        $b = GuiErrorResponse::make('dlq_not_found',  'DLQ item not found');

        $this->assertNotSame($a['code'], $b['code']);
        $this->assertNotSame($a['error'], $b['error']);
    }

    public function testMakeAlwaysSetsSuccessFalse(): void
    {
        // Sanity: no caller can accidentally produce a "success" envelope
        // through this helper.
        $result = GuiErrorResponse::make('any_code', 'any message');
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
    }

    public function testMakeAcceptsEmptyMessageWithoutThrowing(): void
    {
        // Defensive: an empty message is unusual but legal — the helper
        // doesn't enforce non-empty strings (the audit's recommendation
        // was to introduce the helper, not to add new validation gates).
        $result = GuiErrorResponse::make('unknown', '');
        $this->assertSame('', $result['error']);
    }
}
