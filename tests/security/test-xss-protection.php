#!/usr/bin/env php
<?php
/**
 * XSS Protection Test Suite
 *
 * Tests OutputEncoder class to ensure proper XSS protection.
 * Run this script to verify all encoding methods work correctly.
 *
 * Usage: php tests/security/test-xss-protection.php
 */

require_once __DIR__ . '/../../src/security/OutputEncoder.php';

class XSSProtectionTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): void
    {
        echo "\n=== XSS Protection Test Suite ===\n\n";

        $this->testHtmlEncoding();
        $this->testAttributeEncoding();
        $this->testJavascriptEncoding();
        $this->testUrlEncoding();
        $this->testFullUrlValidation();
        $this->testCssEncoding();
        $this->testJsonEncoding();
        $this->testClickDataEncoding();
        $this->testXSSPayloads();

        $this->printResults();
    }

    private function testHtmlEncoding(): void
    {
        echo "Testing HTML Context Encoding...\n";

        $tests = [
            ['<script>alert("XSS")</script>', '&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;'],
            ['<img src=x onerror="alert(1)">', '&lt;img src=x onerror=&quot;alert(1)&quot;&gt;'],
            ['Normal text', 'Normal text'],
            ["O'Reilly & Associates", "O&#039;Reilly &amp; Associates"],
        ];

        foreach ($tests as [$input, $expected]) {
            $result = OutputEncoder::html($input);
            $this->assert($result === $expected, "html($input)", $expected, $result);
        }

        echo "\n";
    }

    private function testAttributeEncoding(): void
    {
        echo "Testing Attribute Context Encoding...\n";

        $tests = [
            ['" onload="alert(1)', '&quot; onload=&quot;alert(1)'],
            ["' onclick='alert(1)'", '&#039; onclick=&#039;alert(1)&#039;'],
            ['Safe Value', 'Safe Value'],
        ];

        foreach ($tests as [$input, $expected]) {
            $result = OutputEncoder::attribute($input);
            $this->assert($result === $expected, "attribute($input)", $expected, $result);
        }

        echo "\n";
    }

    private function testJavascriptEncoding(): void
    {
        echo "Testing JavaScript Context Encoding...\n";

        $tests = [
            ['Simple Text', '"Simple Text"'],
            ["O'Reilly", '"O\\u0027Reilly"'],
            ['<script>alert(1)</script>', '"\u003Cscript\u003Ealert(1)\u003C\/script\u003E"'],
        ];

        foreach ($tests as [$input, $expected]) {
            $result = OutputEncoder::javascript($input);
            $this->assert($result === $expected, "javascript($input)", $expected, $result);
        }

        echo "\n";
    }

    private function testUrlEncoding(): void
    {
        echo "Testing URL Context Encoding...\n";

        $tests = [
            ['hello world', 'hello+world'],
            ['test@example.com', 'test%40example.com'],
            ['<script>', '%3Cscript%3E'],
        ];

        foreach ($tests as [$input, $expected]) {
            $result = OutputEncoder::url($input);
            $this->assert($result === $expected, "url($input)", $expected, $result);
        }

        echo "\n";
    }

    private function testFullUrlValidation(): void
    {
        echo "Testing Full URL Validation...\n";

        // Safe URLs
        $safeUrls = [
            'http://example.com',
            'https://example.com/page',
            'https://example.com/page?q=test',
        ];

        foreach ($safeUrls as $url) {
            $result = OutputEncoder::fullUrl($url);
            $this->assert($result !== '', "fullUrl($url) should pass", "non-empty", $result);
        }

        // Dangerous URLs (should be rejected)
        $dangerousUrls = [
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            '//evil.com/steal',
        ];

        foreach ($dangerousUrls as $url) {
            $result = OutputEncoder::fullUrl($url);
            $this->assert($result === '', "fullUrl($url) should reject", "empty", $result);
        }

        echo "\n";
    }

    private function testCssEncoding(): void
    {
        echo "Testing CSS Context Encoding...\n";

        $tests = [
            ['red', 'red'],
            ['#ff0000', '#ff0000'],
            ['expression(alert(1))', 'expressionalert1'],  // Dangerous characters removed
            ['color: red; background: blue;', 'color red background blue'], // Only safe chars
        ];

        foreach ($tests as [$input, $expected]) {
            $result = OutputEncoder::css($input);
            $this->assert($result === $expected, "css($input)", $expected, $result);
        }

        echo "\n";
    }

    private function testJsonEncoding(): void
    {
        echo "Testing JSON Encoding...\n";

        $data = [
            'name' => "O'Reilly",
            'script' => '<script>alert(1)</script>',
            'number' => 42,
        ];

        $result = OutputEncoder::json($data);
        $decoded = json_decode($result, true);

        $this->assert($decoded['name'] === "O'Reilly", "JSON preserves apostrophe", "O'Reilly", $decoded['name']);
        $this->assert($decoded['script'] === '<script>alert(1)</script>', "JSON preserves script tag as string", '<script>alert(1)</script>', $decoded['script']);
        $this->assert($decoded['number'] === 42, "JSON preserves number", 42, $decoded['number']);

        // Verify proper escaping for HTML context
        $this->assert(strpos($result, '\u003C') !== false, "JSON escapes < for HTML safety", "contains \\u003C", $result);

        echo "\n";
    }

    private function testClickDataEncoding(): void
    {
        echo "Testing Click Data Encoding...\n";

        $data = [
            'id' => 123,
            'name' => "Test User",
            'script' => '<script>alert(1)</script>',
        ];

        $result = OutputEncoder::clickData($data);

        // Should start with data-click-data="
        $this->assert(strpos($result, 'data-click-data="') === 0, "clickData format", "starts with data-click-data=", $result);

        // Extract JSON from attribute
        preg_match('/data-click-data="([^"]+)"/', $result, $matches);
        if (isset($matches[1])) {
            $jsonStr = html_entity_decode($matches[1]);
            $decoded = json_decode($jsonStr, true);

            $this->assert($decoded['id'] === 123, "clickData preserves ID", 123, $decoded['id'] ?? 'missing');
            $this->assert($decoded['name'] === "Test User", "clickData preserves name", "Test User", $decoded['name'] ?? 'missing');
        } else {
            $this->assert(false, "clickData attribute extraction", "valid JSON", "extraction failed");
        }

        echo "\n";
    }

    private function testXSSPayloads(): void
    {
        echo "Testing Common XSS Payloads...\n";

        $payloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror="alert(1)">',
            '<svg/onload=alert(1)>',
            'javascript:alert(1)',
            '" onload="alert(1)',
            "' onclick='alert(1)",
            '<iframe src="javascript:alert(1)">',
        ];

        foreach ($payloads as $payload) {
            $encoded = OutputEncoder::html($payload);

            // Verify no script execution possible
            $dangerous = ['<script', 'javascript:', 'onerror=', 'onload=', 'onclick=', '<iframe'];
            $safe = true;

            foreach ($dangerous as $pattern) {
                if (stripos($encoded, $pattern) !== false) {
                    $safe = false;
                    break;
                }
            }

            $this->assert($safe, "XSS payload neutralized: $payload", "no dangerous patterns", $encoded);
        }

        echo "\n";
    }

    private function assert(bool $condition, string $test, string $expected, string $actual): void
    {
        if ($condition) {
            $this->passed++;
            echo "  ✓ $test\n";
        } else {
            $this->failed++;
            $this->failures[] = [
                'test' => $test,
                'expected' => $expected,
                'actual' => $actual,
            ];
            echo "  ✗ $test\n";
            echo "    Expected: $expected\n";
            echo "    Actual: $actual\n";
        }
    }

    private function printResults(): void
    {
        echo "\n=== Test Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        if ($this->failed > 0) {
            echo "\nFailed Tests:\n";
            foreach ($this->failures as $failure) {
                echo "  - {$failure['test']}\n";
                echo "    Expected: {$failure['expected']}\n";
                echo "    Actual: {$failure['actual']}\n";
            }
            exit(1);
        } else {
            echo "\n✓ All tests passed!\n";
            exit(0);
        }
    }
}

// Run tests
$test = new XSSProtectionTest();
$test->run();
