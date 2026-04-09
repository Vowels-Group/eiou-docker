<?php
# Copyright 2025-2026 Vowels Group, LLC

declare(strict_types=1);

namespace Eiou\Services;

use Eiou\Core\Constants;
use Eiou\Database\DebugRepository;
use Eiou\Utils\Logger;
use PDO;

/**
 * Debug Report Service
 *
 * Generates debug reports for troubleshooting and submits them to the
 * support endpoint. Used by both the GUI (SettingsController) and CLI
 * (eiou debug) to produce identical reports.
 */
class DebugReportService
{
    private DebugRepository $debugRepository;
    private ?PDO $pdo;

    public function __construct(DebugRepository $debugRepository, ?PDO $pdo = null)
    {
        $this->debugRepository = $debugRepository;
        $this->pdo = $pdo;
    }

    /**
     * Generate a debug report
     *
     * @param string $description Optional issue description
     * @param bool $full True for full logs, false for limited (last 50 lines)
     * @return array The report data
     */
    public function generateReport(string $description = '', bool $full = false): array
    {
        // Collect debug entries
        $debugEntries = $full
            ? $this->debugRepository->getAllDebugEntries()
            : $this->debugRepository->getRecentDebugEntries(100);

        // Collect system info
        $systemInfo = $this->collectSystemInfo();

        // Collect log files
        $phpLogContent = $this->collectPhpLog($full);
        $nginxLogContent = $this->collectLogFile('/var/log/nginx/error.log', $full);
        $eiouLogContent = $this->collectLogFile('/var/log/eiou/app.log', $full);

        // Build report
        $report = [
            'description' => $description,
            'system_info' => $systemInfo,
            'debug_entries' => $debugEntries,
            'debug_entries_count' => count($debugEntries),
            'php_errors' => $this->sanitizeUtf8($phpLogContent),
            'nginx_errors' => $this->sanitizeUtf8($nginxLogContent),
            'eiou_app_log' => $this->sanitizeUtf8($eiouLogContent),
            'report_type' => $full ? 'full' : 'limited',
        ];

        return $report;
    }

    /**
     * Generate a report and save it to a JSON file
     *
     * @param string $description Optional issue description
     * @param bool $full True for full logs
     * @param string|null $outputPath Custom output path (default: /tmp)
     * @return array{path: string, size: int, report: array}
     */
    public function generateAndSave(string $description = '', bool $full = false, ?string $outputPath = null): array
    {
        $report = $this->generateReport($description, $full);

        $path = $outputPath ?? '/tmp/eiou-debug-report-' . date('YmdHis') . '.json';
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode debug report: ' . json_last_error_msg());
        }

        $bytes = @file_put_contents($path, $json);
        if ($bytes === false) {
            throw new \RuntimeException('Failed to write debug report to: ' . $path);
        }

        return ['path' => $path, 'size' => $bytes, 'report' => $report];
    }

    /**
     * Collect system information
     */
    private function collectSystemInfo(): array
    {
        $systemInfo = [
            'php_version' => phpversion(),
            'sapi' => php_sapi_name(),
            'os' => PHP_OS,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A',
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        // MySQL/MariaDB version
        try {
            if ($this->pdo) {
                $stmt = $this->pdo->query('SELECT VERSION() as version');
                $result = $stmt->fetch();
                $systemInfo['mysql_version'] = $result['version'] ?? 'N/A';
            } else {
                $systemInfo['mysql_version'] = 'N/A (no connection)';
            }
        } catch (\Throwable $e) {
            $systemInfo['mysql_version'] = 'Error: ' . $e->getMessage();
        }

        // Debian version
        $systemInfo['debian_version'] = 'N/A';
        if (file_exists('/etc/debian_version') && is_readable('/etc/debian_version')) {
            $systemInfo['debian_version'] = trim(file_get_contents('/etc/debian_version'));
        }

        // OS release
        $systemInfo['os_release'] = 'N/A';
        if (file_exists('/etc/os-release') && is_readable('/etc/os-release')) {
            $osInfo = parse_ini_file('/etc/os-release');
            $systemInfo['os_release'] = $osInfo['PRETTY_NAME'] ?? 'N/A';
        }

        // PHP config
        $phpIniPath = php_ini_loaded_file() ?: 'Not found';
        $systemInfo['php_ini_path'] = $phpIniPath;
        $systemInfo['php_ini_content'] = $this->readFileWithLimit($phpIniPath);

        // nginx config
        $nginxConfigPath = '/etc/nginx/nginx.conf';
        $systemInfo['nginx_config_path'] = file_exists($nginxConfigPath) ? $nginxConfigPath : 'N/A';
        $systemInfo['nginx_config_content'] = $this->readFileWithLimit($nginxConfigPath);

        // PHP extensions
        $phpExtensions = get_loaded_extensions();
        sort($phpExtensions);
        $phpExtensionsWithVersions = [];
        foreach ($phpExtensions as $ext) {
            $version = phpversion($ext);
            $phpExtensionsWithVersions[$ext] = $version ?: 'N/A';
        }
        $systemInfo['php_extensions_count'] = count($phpExtensions);
        $systemInfo['php_extensions'] = $phpExtensionsWithVersions;

        // Constants
        $systemInfo['constants'] = Constants::all();

        // User config
        $defaultConfigPath = '/etc/eiou/config/defaultconfig.json';
        $systemInfo['user_config'] = [];
        if (file_exists($defaultConfigPath) && is_readable($defaultConfigPath)) {
            $data = json_decode(file_get_contents($defaultConfigPath), true);
            if ($data) {
                $systemInfo['user_config'] = $data;
            }
        }

        return $systemInfo;
    }

    /**
     * Collect PHP error log from known paths
     */
    private function collectPhpLog(bool $full): string
    {
        $paths = ['/var/log/php_errors.log', '/var/log/eiou/eiou-php-error.log'];
        foreach ($paths as $path) {
            $content = $full ? $this->readFullLogFile($path) : $this->readTailLogFile($path);
            if (!empty($content)) {
                return $content;
            }
        }
        return '';
    }

    /**
     * Collect a log file (full or last 50 lines)
     */
    private function collectLogFile(string $path, bool $full): string
    {
        return $full ? $this->readFullLogFile($path) : $this->readTailLogFile($path);
    }

    /**
     * Read last 50 lines of a log file
     */
    private function readTailLogFile(string $path): string
    {
        if (!file_exists($path) || !is_readable($path)) {
            return '';
        }
        return shell_exec("tail -50 " . escapeshellarg($path)) ?? '';
    }

    /**
     * Read full log file with size limit (5MB)
     */
    private function readFullLogFile(string $path, int $maxSize = 5242880): string
    {
        if (!file_exists($path) || !is_readable($path)) {
            return '';
        }

        $fileSize = filesize($path);
        if ($fileSize === 0) {
            return '';
        }

        if ($fileSize <= $maxSize) {
            return file_get_contents($path) ?: '';
        }

        $content = file_get_contents($path, false, null, $fileSize - $maxSize, $maxSize);
        if ($content === false) {
            return '';
        }

        $firstNewline = strpos($content, "\n");
        if ($firstNewline !== false && $firstNewline < 1000) {
            $content = substr($content, $firstNewline + 1);
        }

        return "[TRUNCATED - Showing last " . round($maxSize / 1024 / 1024, 1) . "MB of " . round($fileSize / 1024 / 1024, 1) . "MB]\n\n" . $content;
    }

    /**
     * Read a file with a 50KB size limit
     */
    private function readFileWithLimit(string $path, int $maxSize = 51200): string
    {
        if (!$path || $path === 'Not found' || !file_exists($path) || !is_readable($path)) {
            return 'N/A';
        }

        $fileSize = filesize($path);
        if ($fileSize > $maxSize) {
            $content = file_get_contents($path, false, null, 0, $maxSize);
            return $content . "\n\n[TRUNCATED - Original size: " . round($fileSize / 1024, 1) . "KB]";
        }

        return file_get_contents($path) ?: 'N/A';
    }

    /**
     * Sanitize string to valid UTF-8
     */
    private function sanitizeUtf8(string $str): string
    {
        if (empty($str)) {
            return '';
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        }
        if (function_exists('iconv')) {
            return iconv('UTF-8', 'UTF-8//IGNORE', $str);
        }
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str);
    }

    // =========================================================================
    // Remote Submission (sends report to support endpoint via Tor)
    // =========================================================================

    private const SUBMIT_ENDPOINT = 'https://debug-reports.eiou.org/v1/report';
    private const TOR_PROXY = '127.0.0.1:9050';
    private const CONNECT_TIMEOUT = 30;
    private const SUBMIT_TIMEOUT = 120;
    private const RATE_LIMIT_FILE = '/tmp/debug-report-submissions.json';
    private const MAX_SUBMISSIONS_PER_DAY = 3;

    /**
     * Submit a debug report to the support endpoint via Tor.
     *
     * @param array $report The debug report data (from generateReport())
     * @param string $description User's issue description
     * @return array{success: bool, key: string|null, error: string|null}
     */
    public static function submit(array $report, string $description = ''): array
    {
        // Client-side rate limit: max 3 submissions per day
        $rateCheck = self::checkRateLimit();
        if (!$rateCheck['allowed']) {
            return ['success' => false, 'key' => null, 'error' => $rateCheck['error']];
        }

        if (!function_exists('curl_init')) {
            return ['success' => false, 'key' => null, 'error' => 'curl extension not available'];
        }

        // Scrub sensitive data (addresses, pubkeys) before remote submission
        $scrubbed = self::scrubReport($report);

        $payload = [
            'report' => $scrubbed,
            'version' => Constants::APP_VERSION,
            'description' => substr($description, 0, 500),
            // Safe to use analytics_id since report content is scrubbed
            // (no addresses/keys to correlate with). Needed for server-side rate limiting.
            'analytics_id' => AnalyticsService::getAnonymousId(),
        ];

        $jsonPayload = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonPayload === false) {
            return ['success' => false, 'key' => null, 'error' => 'Failed to encode report'];
        }

        // Trim debug entries if payload exceeds 4.5MB (leave headroom for 5MB server limit)
        $maxSize = 4.5 * 1024 * 1024;
        if (strlen($jsonPayload) > $maxSize && !empty($scrubbed['debug_entries'])) {
            // Remove oldest entries first until under limit
            while (strlen($jsonPayload) > $maxSize && count($payload['report']['debug_entries']) > 10) {
                array_shift($payload['report']['debug_entries']);
                $payload['report']['debug_entries_trimmed'] = true;
                $jsonPayload = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }

        // If still too large after trimming, truncate log content
        if (strlen($jsonPayload) > $maxSize) {
            foreach (['eiou_app_log', 'php_errors', 'nginx_errors'] as $logField) {
                if (isset($payload['report'][$logField]) && strlen($payload['report'][$logField]) > 50000) {
                    $payload['report'][$logField] = '[truncated for size] ' . substr($payload['report'][$logField], -50000);
                }
            }
            $jsonPayload = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if (strlen($jsonPayload) > 5 * 1024 * 1024) {
            return ['success' => false, 'key' => null, 'error' => 'Report too large even after trimming. Please download and email it instead.'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::SUBMIT_ENDPOINT,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: eiou-node/' . Constants::APP_VERSION,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::SUBMIT_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_PROXY => self::TOR_PROXY,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($response, true);
            $key = $decoded['key'] ?? null;
            self::recordSubmission();
            Logger::getInstance()->info('Debug report submitted', ['key' => $key]);
            return ['success' => true, 'key' => $key, 'error' => null];
        }

        $errorMsg = $error ?: 'HTTP ' . $httpCode;
        Logger::getInstance()->info('Debug report submission failed', [
            'http_code' => $httpCode,
            'error' => $errorMsg,
        ]);
        return ['success' => false, 'key' => null, 'error' => $errorMsg];
    }

    /**
     * Scrub sensitive data from a report before remote submission.
     *
     * Replaces addresses, public keys, and other identifiable data while
     * preserving protocol indicators (http/https/tor) for debugging.
     *
     * @param array $report The raw report data
     * @return array The scrubbed report
     */
    private static function scrubReport(array $report): array
    {
        // Patterns to scrub — keeps protocol prefix, redacts the rest
        $patterns = [
            // .onion addresses (56-char base32 + .onion)
            '/[a-z2-7]{56}\.onion/' => '[redacted].onion',
            // http:// and https:// URLs (keep protocol, redact host)
            '#(https?://)[^\s<>"\']+#i' => '$1[redacted]',
            // Public keys (64+ hex chars that look like keys)
            '/\b[0-9a-f]{64,}\b/i' => '[redacted-key]',
            // IP addresses (preserve as [redacted-ip] to show it was an IP)
            '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/' => '[redacted-ip]',
        ];

        $json = json_encode($report, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return $report;
        }

        foreach ($patterns as $pattern => $replacement) {
            $json = preg_replace($pattern, $replacement, $json);
        }

        $scrubbed = json_decode($json, true);
        return is_array($scrubbed) ? $scrubbed : $report;
    }

    /**
     * Check if submission is allowed (max 3 per day).
     *
     * @return array{allowed: bool, error: string|null}
     */
    private static function checkRateLimit(): array
    {
        $today = date('Y-m-d');
        $data = self::readSubmissionLog();

        // Filter to today's submissions only
        $todayCount = 0;
        foreach ($data['submissions'] ?? [] as $timestamp) {
            if (str_starts_with($timestamp, $today)) {
                $todayCount++;
            }
        }

        if ($todayCount >= self::MAX_SUBMISSIONS_PER_DAY) {
            $remaining = self::MAX_SUBMISSIONS_PER_DAY;
            return [
                'allowed' => false,
                'error' => "Daily limit reached ({$remaining} reports per day). Please try again tomorrow or download the report instead.",
            ];
        }

        return ['allowed' => true, 'error' => null];
    }

    /**
     * Record a successful submission for rate limiting.
     */
    private static function recordSubmission(): void
    {
        $data = self::readSubmissionLog();
        $today = date('Y-m-d');

        // Keep only today's entries (prune old ones)
        $data['submissions'] = array_filter(
            $data['submissions'] ?? [],
            fn($ts) => str_starts_with($ts, $today)
        );
        $data['submissions'][] = date('c');

        @file_put_contents(self::RATE_LIMIT_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Read the submission log file.
     */
    private static function readSubmissionLog(): array
    {
        if (!file_exists(self::RATE_LIMIT_FILE)) {
            return ['submissions' => []];
        }
        $json = @file_get_contents(self::RATE_LIMIT_FILE);
        if ($json === false) {
            return ['submissions' => []];
        }
        return json_decode($json, true) ?: ['submissions' => []];
    }
}
