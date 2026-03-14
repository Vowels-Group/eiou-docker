<?php
# Copyright 2025-2026 Vowels Group, LLC

declare(strict_types=1);

namespace Eiou\Services;

use Eiou\Core\Constants;
use Eiou\Database\DebugRepository;
use PDO;

/**
 * Debug Report Service
 *
 * Generates debug reports for troubleshooting. Used by both the GUI
 * (SettingsController) and CLI (eiou debug) to produce identical reports.
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

        $bytes = file_put_contents($path, $json);
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
}
