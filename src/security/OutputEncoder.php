<?php
/**
 * Output Encoder - XSS Protection
 *
 * Copyright 2025
 *
 * Provides comprehensive output encoding to prevent XSS attacks.
 * All user-controlled data must be encoded based on context before output.
 *
 * CRITICAL SECURITY RULES:
 * - NEVER output user data without encoding
 * - ALWAYS use the correct encoder for the context
 * - HTML context: Use html() or attribute()
 * - JavaScript context: Use javascript()
 * - URL context: Use url()
 * - CSS context: Use css()
 *
 * @see docs/issue-146/XSS_PROTECTION.md for usage guidelines
 */

class OutputEncoder
{
    /**
     * Encode for HTML body context
     * Use when outputting data between HTML tags: <div>DATA</div>
     *
     * Protects against: <script>, <img onerror>, and other HTML injection
     *
     * @param mixed $value Value to encode (will be converted to string)
     * @return string Safely encoded HTML
     *
     * @example
     * echo '<div>' . OutputEncoder::html($userInput) . '</div>';
     */
    public static function html($value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8',
            true // Double encode to prevent double-encoding attacks
        );
    }

    /**
     * Encode for HTML attribute context
     * Use when outputting data in HTML attributes: <div title="DATA">
     *
     * Protects against: Attribute breaking, event handler injection
     *
     * @param mixed $value Value to encode
     * @return string Safely encoded attribute value
     *
     * @example
     * echo '<div title="' . OutputEncoder::attribute($userInput) . '">';
     */
    public static function attribute($value): string
    {
        if ($value === null) {
            return '';
        }

        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            'UTF-8',
            true
        );
    }

    /**
     * Encode for JavaScript string context
     * Use when embedding data in JavaScript strings
     *
     * Protects against: JavaScript injection, string escaping attacks
     *
     * IMPORTANT: Data must still be inside quotes in JavaScript
     *
     * @param mixed $value Value to encode
     * @return string JSON-encoded value (safe for JavaScript)
     *
     * @example
     * echo '<script>var name = ' . OutputEncoder::javascript($userName) . ';</script>';
     * // Outputs: <script>var name = "John Doe";</script>
     */
    public static function javascript($value): string
    {
        if ($value === null) {
            return 'null';
        }

        // JSON encode provides proper JavaScript escaping
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Encode for JavaScript event handler context (onclick, onload, etc.)
     * Use when embedding data in inline event handlers
     *
     * Protects against: Event handler injection, quote escaping
     *
     * CRITICAL: Prefer data attributes + addEventListener instead of inline handlers
     *
     * @param mixed $value Value to encode
     * @return string JavaScript-safe string for event handlers
     *
     * @example
     * // AVOID: <div onclick="doSomething('<?php echo OutputEncoder::jsEvent($data); ?>')">
     * // PREFER: Use data attributes with addEventListener
     */
    public static function jsEvent($value): string
    {
        if ($value === null) {
            return '';
        }

        // Encode for JavaScript string context and then for HTML attribute
        $jsEncoded = json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Remove quotes added by json_encode (they'll be added in the context)
        $jsEncoded = substr($jsEncoded, 1, -1);

        return $jsEncoded;
    }

    /**
     * Encode for URL parameter context
     * Use when building URLs with user data
     *
     * Protects against: URL injection, parameter manipulation
     *
     * @param mixed $value Value to encode
     * @return string URL-encoded value
     *
     * @example
     * echo '<a href="/search?q=' . OutputEncoder::url($searchTerm) . '">Search</a>';
     */
    public static function url($value): string
    {
        if ($value === null) {
            return '';
        }

        return urlencode((string)$value);
    }

    /**
     * Encode complete URL
     * Use when outputting entire URLs (validates and encodes)
     *
     * Protects against: JavaScript URLs, data URLs, protocol-relative URLs
     *
     * @param string $url URL to validate and encode
     * @param array $allowedProtocols Allowed URL schemes (default: http, https)
     * @return string Safe URL or empty string if invalid
     *
     * @example
     * echo '<a href="' . OutputEncoder::fullUrl($userUrl) . '">Link</a>';
     */
    public static function fullUrl(string $url, array $allowedProtocols = ['http', 'https']): string
    {
        if (empty($url)) {
            return '';
        }

        // Parse URL
        $parts = parse_url($url);

        // Check for valid protocol
        if (isset($parts['scheme'])) {
            if (!in_array(strtolower($parts['scheme']), $allowedProtocols, true)) {
                // Reject dangerous protocols (javascript:, data:, file:, etc.)
                return '';
            }
        }

        // Reject protocol-relative URLs
        if (substr($url, 0, 2) === '//') {
            return '';
        }

        // Return HTML-encoded URL
        return self::attribute($url);
    }

    /**
     * Encode for CSS context
     * Use when embedding data in inline styles
     *
     * Protects against: CSS injection, expression() attacks
     *
     * IMPORTANT: Very limited use case - avoid inline styles
     *
     * @param mixed $value Value to encode
     * @return string CSS-safe value
     *
     * @example
     * echo '<div style="color: ' . OutputEncoder::css($userColor) . '">Text</div>';
     * // Better: Use predefined CSS classes instead
     */
    public static function css($value): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string)$value;

        // Only allow alphanumeric, space, and safe CSS characters
        // Remove anything that could break out of CSS context
        $value = preg_replace('/[^a-zA-Z0-9\s\-_#,.]/', '', $value);

        return $value;
    }

    /**
     * Encode array for safe JSON output
     * Use when outputting JSON data to JavaScript
     *
     * @param array $data Array to encode
     * @return string JSON string safe for embedding in HTML
     *
     * @example
     * echo '<script>var config = ' . OutputEncoder::json($configArray) . ';</script>';
     */
    public static function json(array $data): string
    {
        return json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Sanitize filename for safe output
     * Use when displaying uploaded filenames
     *
     * Protects against: Path traversal, special characters
     *
     * @param string $filename Filename to sanitize
     * @return string Safe filename
     *
     * @example
     * echo 'File: ' . OutputEncoder::filename($uploadedFile);
     */
    public static function filename(string $filename): string
    {
        // Remove path components
        $filename = basename($filename);

        // Remove dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        return self::html($filename);
    }

    /**
     * Encode for data attribute context
     * Use when setting HTML5 data attributes
     *
     * @param mixed $value Value to encode
     * @return string Safe value for data attributes
     *
     * @example
     * echo '<div data-user-id="' . OutputEncoder::dataAttribute($userId) . '">';
     */
    public static function dataAttribute($value): string
    {
        // Data attributes should use JSON encoding for complex data
        if (is_array($value) || is_object($value)) {
            return self::attribute(self::json((array)$value));
        }

        return self::attribute($value);
    }

    /**
     * Sanitize HTML (for trusted rich text only)
     * Use ONLY for content from trusted sources that needs HTML formatting
     *
     * WARNING: This should RARELY be used. Prefer markdown or plain text.
     *
     * @param string $html HTML content to sanitize
     * @param array $allowedTags Allowed HTML tags
     * @return string Sanitized HTML
     */
    public static function richText(string $html, array $allowedTags = ['p', 'br', 'strong', 'em', 'u', 'a']): string
    {
        // Strip all tags except allowed ones
        $allowedTagsStr = '<' . implode('><', $allowedTags) . '>';
        $html = strip_tags($html, $allowedTagsStr);

        // Remove dangerous attributes
        $html = preg_replace('/<([a-z]+)([^>]*)(on\w+)="[^"]*"([^>]*)>/i', '<$1$2$4>', $html);
        $html = preg_replace('/<([a-z]+)([^>]*)(on\w+)=\'[^\']*\'([^>]*)>/i', '<$1$2$4>', $html);

        return $html;
    }

    /**
     * Encode for textarea context
     * Use when outputting default values in textareas
     *
     * @param mixed $value Value to encode
     * @return string Safe textarea content
     *
     * @example
     * echo '<textarea>' . OutputEncoder::textarea($userContent) . '</textarea>';
     */
    public static function textarea($value): string
    {
        return self::html($value);
    }

    /**
     * Create safe onclick handler with data attributes
     * Use instead of inline onclick with dynamic data
     *
     * Returns HTML for data attributes that can be read in JavaScript
     *
     * @param array $data Data to pass to click handler
     * @return string HTML data attributes
     *
     * @example
     * // PHP:
     * echo '<button ' . OutputEncoder::clickData(['id' => $userId, 'name' => $name]) . '>Edit</button>';
     * // JavaScript:
     * element.addEventListener('click', function() {
     *     const data = JSON.parse(this.dataset.clickData);
     *     console.log(data.id, data.name);
     * });
     */
    public static function clickData(array $data): string
    {
        return 'data-click-data="' . self::attribute(json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) . '"';
    }
}
