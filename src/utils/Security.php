<?php
/**
 * Security utility functions for eIOU application
 * Provides output encoding, rate limiting, and security headers
 */

class Security {

    /**
     * Encode output for safe HTML display (prevents XSS)
     *
     * @param string $string The string to encode
     * @param int $flags ENT_QUOTES by default
     * @param string $encoding UTF-8 by default
     * @return string HTML-encoded string
     */
    public static function htmlEncode($string, $flags = ENT_QUOTES, $encoding = 'UTF-8') {
        return htmlspecialchars($string, $flags, $encoding);
    }

    /**
     * Encode output for JavaScript context
     *
     * @param mixed $value Value to encode
     * @return string JSON-encoded string safe for JS
     */
    public static function jsEncode($value) {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS);
    }

    /**
     * Encode output for URL parameters
     *
     * @param string $string String to encode
     * @return string URL-encoded string
     */
    public static function urlEncode($string) {
        return urlencode($string);
    }

    /**
     * Sanitize user input for database queries (additional layer beyond prepared statements)
     *
     * @param string $input User input to sanitize
     * @return string Sanitized input
     */
    public static function sanitizeInput($input) {
        // Remove null bytes
        $input = str_replace(chr(0), '', $input);
        // Trim whitespace
        $input = trim($input);
        // Remove backslashes
        if (get_magic_quotes_gpc()) {
            $input = stripslashes($input);
        }
        return $input;
    }

    /**
     * Set security headers for HTTP responses
     */
    public static function setSecurityHeaders() {
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Enable XSS protection in browsers
        header('X-XSS-Protection: 1; mode=block');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'");

        // HSTS (HTTP Strict Transport Security) - only for HTTPS
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // Permissions Policy
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }

    /**
     * Mask sensitive data for logging
     *
     * @param mixed $data Data to mask
     * @param array $sensitiveKeys Keys to mask
     * @return mixed Data with sensitive values masked
     */
    public static function maskSensitiveData($data, $sensitiveKeys = ['password', 'private_key', 'secret', 'token', 'authcode']) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // Check if key contains sensitive word
                $lowerKey = strtolower($key);
                foreach ($sensitiveKeys as $sensitiveKey) {
                    if (strpos($lowerKey, $sensitiveKey) !== false) {
                        $data[$key] = '***MASKED***';
                        break;
                    }
                }
                // Recursively mask nested arrays
                if (is_array($value)) {
                    $data[$key] = self::maskSensitiveData($value, $sensitiveKeys);
                }
            }
        } elseif (is_object($data)) {
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (property_exists($data, $sensitiveKey)) {
                    $data->$sensitiveKey = '***MASKED***';
                }
            }
        }
        return $data;
    }

    /**
     * Safe error message that doesn't expose system details
     *
     * @param Exception $e The exception
     * @param bool $debug Whether to include details (only in dev)
     * @return string Safe error message
     */
    public static function getSafeErrorMessage($e, $debug = false) {
        // Log the full error internally
        error_log("Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

        // Return generic message to user
        if ($debug && (getenv('APP_ENV') === 'development' || getenv('APP_DEBUG') === 'true')) {
            return "Error: " . $e->getMessage();
        }

        return "An error occurred. Please try again later.";
    }

    /**
     * Validate CSRF token
     *
     * @param string $token Token to validate
     * @return bool True if valid
     */
    public static function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate CSRF token
     *
     * @return string CSRF token
     */
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}