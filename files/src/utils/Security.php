<?php

# Copyright 2025-2026 Vowels Group, LLC
/**
 * Security utility functions for eIOU application
 * Provides output encoding, rate limiting, and security headers
 */

namespace Eiou\Utils;

use Exception;
use RuntimeException;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;

class Security {
    /** @var string|null Per-request CSP nonce (base64, 128-bit) */
    private static $cspNonce = null;

    /**
     * Generate and cache a per-request CSP nonce.
     *
     * @return string Base64-encoded 128-bit random nonce
     */
    public static function generateCspNonce(): string {
        if (self::$cspNonce === null) {
            self::$cspNonce = base64_encode(random_bytes(16));
        }
        return self::$cspNonce;
    }

    /**
     * Get the current request's CSP nonce (generates if not yet created).
     *
     * @return string Base64-encoded nonce for use in script tags
     */
    public static function getCspNonce(): string {
        return self::generateCspNonce();
    }

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
     * Strip null bytes and trim whitespace from input
     *
     * @param string $input User input to sanitize
     * @return string Sanitized input
     */
    public static function stripNullBytes($input) {
        // Remove null bytes
        $input = str_replace(chr(0), '', $input);
        // Trim whitespace
        $input = trim($input);
        return $input;
    }

    /**
     * @deprecated Use stripNullBytes() instead
     */
    public static function sanitizeInput($input) {
        return self::stripNullBytes($input);
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

        // Content Security Policy — nonce-based script-src (L-32)
        $nonce = self::getCspNonce();
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'");

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
        Logger::getInstance()->logException($e, [], 'ERROR');

        // Return generic message to user
        if ($debug && (Constants::get('APP_ENV') === 'development' || Constants::get('APP_DEBUG') === 'true')) {
            return "Error: " . $e->getMessage();
        }

        return "An error occurred. Please try again later.";
    }

    /**
     * Validate email address format
     *
     * @param string $email Email address to validate
     * @return bool True if valid, false otherwise
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate URL format
     *
     * @param string $url URL to validate
     * @return bool True if valid, false otherwise
     */
    public static function validateUrl($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate IP address
     *
     * @param string $ip IP address to validate
     * @return bool True if valid, false otherwise
     */
    public static function validateIp($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Sanitize filename to prevent directory traversal attacks
     *
     * @param string $filename Filename to sanitize
     * @return string Sanitized filename
     */
    public static function sanitizeFilename($filename) {
        // Remove directory separators and special characters
        $filename = basename($filename);
        $filename = str_replace(['..', '/', '\\', "\0"], '', $filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        return $filename;
    }

    /**
     * Generate secure random token
     *
     * @param int $length Length in bytes (output will be hex, so double the length)
     * @return string Random token
     * @throws Exception If secure random generation fails
     */
    public static function generateSecureToken($length = 32) {
        try {
            return bin2hex(random_bytes($length));
        } catch (Exception $e) {
            Logger::getInstance()->error("Failed to generate secure token", [
                'error' => $e->getMessage()
            ]);
            throw new RuntimeException("Failed to generate secure random token");
        }
    }

    /**
     * Hash password using secure algorithm
     *
     * @param string $password Password to hash
     * @return string Hashed password
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verify password against hash
     *
     * @param string $password Password to verify
     * @param string $hash Hash to verify against
     * @return bool True if password matches, false otherwise
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }

    /**
     * Check if password needs rehashing (algorithm updated)
     *
     * @param string $hash Password hash
     * @return bool True if needs rehashing, false otherwise
     */
    public static function needsRehash($hash) {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Validate string length
     *
     * @param string $string String to validate
     * @param int $min Minimum length
     * @param int $max Maximum length
     * @return bool True if valid, false otherwise
     */
    public static function validateLength($string, $min = 1, $max = 255) {
        $length = mb_strlen($string, 'UTF-8');
        return $length >= $min && $length <= $max;
    }

    /**
     * Sanitize array recursively
     *
     * @param array $array Array to sanitize
     * @return array Sanitized array
     */
    public static function sanitizeArray($array) {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = self::sanitizeArray($value);
            } elseif (is_string($value)) {
                $array[$key] = self::stripNullBytes($value);
            }
        }
        return $array;
    }

    /**
     * Constant-time string comparison to prevent timing attacks
     *
     * @param string $known Known string
     * @param string $user User-provided string
     * @return bool True if strings match, false otherwise
     */
    public static function timingSafeEquals($known, $user) {
        return hash_equals($known, $user);
    }

    /**
     * Validate and sanitize integer input
     *
     * @param mixed $value Value to validate
     * @param int $min Minimum value
     * @param int $max Maximum value
     * @return int|null Sanitized integer or null if invalid
     */
    public static function sanitizeInt($value, $min = PHP_INT_MIN, $max = PHP_INT_MAX) {
        $filtered = filter_var($value, FILTER_VALIDATE_INT);
        if ($filtered === false) {
            return null;
        }
        if ($filtered < $min || $filtered > $max) {
            return null;
        }
        return $filtered;
    }

    /**
     * Validate and sanitize float input
     *
     * @param mixed $value Value to validate
     * @param float $min Minimum value
     * @param float $max Maximum value
     * @return float|null Sanitized float or null if invalid
     */
    public static function sanitizeFloat($value, $min = -PHP_FLOAT_MAX, $max = PHP_FLOAT_MAX) {
        $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($filtered === false) {
            return null;
        }
        if ($filtered < $min || $filtered > $max) {
            return null;
        }
        return $filtered;
    }

    /**
     * Prevent clickjacking by setting appropriate headers
     */
    public static function preventClickjacking() {
        header('X-Frame-Options: DENY');
        header('Content-Security-Policy: frame-ancestors \'none\'');
    }

    /**
     * Get client IP address, only trusting proxy headers from trusted proxies
     *
     * If REMOTE_ADDR is in the trusted proxies list, checks proxy headers
     * (CF-Connecting-IP, X-Forwarded-For). Otherwise returns REMOTE_ADDR directly.
     *
     * @return string Client IP address
     */
    public static function getClientIp(): string {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Parse trusted proxies from: env var > persisted config > constant
        $trustedProxiesStr = getenv('TRUSTED_PROXIES') ?: '';
        if ($trustedProxiesStr === '') {
            $trustedProxiesStr = UserContext::getInstance()->getTrustedProxies();
        }
        $trustedProxies = array_filter(array_map('trim', explode(',', $trustedProxiesStr)));

        // Only check proxy headers if REMOTE_ADDR is a trusted proxy
        if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
            // Check proxy headers in priority order
            $proxyHeaders = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'];
            foreach ($proxyHeaders as $header) {
                if (!empty($_SERVER[$header])) {
                    $ip = $_SERVER[$header];
                    // X-Forwarded-For can contain multiple IPs; take the first (client IP)
                    if (strpos($ip, ',') !== false) {
                        $ip = explode(',', $ip)[0];
                    }
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return $remoteAddr;
    }

    /**
     * Log security event
     *
     * @param string $event Event description
     * @param array $context Additional context
     */
    public static function logSecurityEvent($event, $context = []) {
        $maskedContext = self::maskSensitiveData($context);
        Logger::getInstance()->warning("SECURITY EVENT: $event", $maskedContext);
    }
}