<?php
# Copyright 2025 Adrien Hubert (adrien@eiou.org)

/**
 * Settings Controller
 *
 * Handles HTTP POST requests for settings-related actions.
 */

class SettingsController
{
    /**
     * @var Session Session manager
     */
    private Session $session;

    /**
     * Constructor
     *
     * @param Session $session
     */
    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Handle settings update form submission
     *
     * @return void
     */
    public function handleUpdateSettings(): void
    {
        // CSRF Protection: Verify token before processing
        $this->session->verifyCSRFToken();

        // Import validation and security classes
        require_once __DIR__ . '/../../utils/InputValidator.php';
        require_once __DIR__ . '/../../utils/Security.php';

        // Collect and validate settings
        $settings = [];
        $errors = [];

        // Default Currency
        if (isset($_POST['defaultCurrency'])) {
            $validation = InputValidator::validateCurrency($_POST['defaultCurrency']);
            if ($validation['valid']) {
                $settings['defaultCurrency'] = $validation['value'];
            } else {
                $errors[] = 'Invalid currency: ' . $validation['error'];
            }
        }

        // Default Fee
        if (isset($_POST['defaultFee'])) {
            $validation = InputValidator::validateFeePercent($_POST['defaultFee']);
            if ($validation['valid']) {
                $settings['defaultFee'] = $validation['value'];
            } else {
                $errors[] = 'Invalid default fee: ' . $validation['error'];
            }
        }

        // Minimum Fee
        if (isset($_POST['minFee'])) {
            $validation = InputValidator::validateAmountFee($_POST['minFee']);
            if ($validation['valid']) {
                $settings['minFee'] = $validation['value'];
            } else {
                $errors[] = 'Invalid minimum fee: ' . $validation['error'];
            }
        }

        // Maximum Fee
        if (isset($_POST['maxFee'])) {
            $validation = InputValidator::validateFeePercent($_POST['maxFee']);
            if ($validation['valid']) {
                $settings['maxFee'] = $validation['value'];
            } else {
                $errors[] = 'Invalid maximum fee: ' . $validation['error'];
            }
        }

        // Default Credit Limit
        if (isset($_POST['defaultCreditLimit'])) {
            $validation = InputValidator::validateAmountFee($_POST['defaultCreditLimit']);
            if ($validation['valid']) {
                $settings['defaultCreditLimit'] = $validation['value'];
            } else {
                $errors[] = 'Invalid credit limit: ' . $validation['error'];
            }
        }

        // Max P2P Level
        if (isset($_POST['maxP2pLevel'])) {
            $validation = InputValidator::validateRequestLevel($_POST['maxP2pLevel']);
            if ($validation['valid']) {
                $settings['maxP2pLevel'] = $validation['value'];
            } else {
                $errors[] = 'Invalid P2P level: ' . $validation['error'];
            }
        }

        // P2P Expiration (seconds, must be positive integer >= minimum)
        if (isset($_POST['p2pExpiration'])) {
            $validation = InputValidator::validatePositiveInteger($_POST['p2pExpiration'], Constants::P2P_MIN_EXPIRATION_SECONDS);
            if ($validation['valid']) {
                $settings['p2pExpiration'] = $validation['value'];
            } else {
                $errors[] = 'Invalid P2P expiration: ' . $validation['error'];
            }
        }

        // Max Output
        if (isset($_POST['maxOutput'])) {
            $value = $_POST['maxOutput'];
            if (is_numeric($value) && intval($value) > 0) {
                $settings['maxOutput'] = intval($value);
            } else {
                $errors[] = 'Invalid max output: must be a positive integer';
            }
        }

        // Default Transport Mode
        if (isset($_POST['defaultTransportMode'])) {
            $value = strtolower(Security::sanitizeInput($_POST['defaultTransportMode']));
            if (in_array($value, Constants::VALID_TRANSPORT_INDICES)) {
                $settings['defaultTransportMode'] = $value;
            } else {
                $errors[] = 'Invalid transport mode: must be in ' . Constants::VALID_TRANSPORT_INDICES;
            }
        }

        // Check for errors
        if (!empty($errors)) {
            MessageHelper::redirectMessage(implode('; ', $errors), 'error');
            return;
        }

        // Save settings to config file
        try {
            $configFile = '/etc/eiou/defaultconfig.json';
            $config = [];

            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true) ?? [];
            }

            // Merge new settings
            $config = array_merge($config, $settings);

            // Write back to file
            if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX) === false) {
                throw new Exception('Failed to write configuration file');
            }

            MessageHelper::redirectMessage('Settings updated successfully', 'success');
        } catch (Exception $e) {
            MessageHelper::redirectMessage('Failed to save settings: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Handle clear debug logs action
     *
     * @return void
     */
    public function handleClearDebugLogs(): void
    {
        // CSRF Protection
        $this->session->verifyCSRFToken();

        try {
            require_once __DIR__ . '/../../database/DebugRepository.php';
            $debugRepo = new DebugRepository();

            if ($debugRepo->clearDebugEntries()) {
                MessageHelper::redirectMessage('Debug logs cleared successfully', 'success');
            } else {
                MessageHelper::redirectMessage('Failed to clear debug logs', 'error');
            }
        } catch (Exception $e) {
            MessageHelper::redirectMessage('Error clearing logs: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Handle send debug report action
     *
     * @return void
     */
    public function handleSendDebugReport(): void
    {
        // CSRF Protection
        $this->session->verifyCSRFToken();

        require_once __DIR__ . '/../../utils/Security.php';

        $description = Security::sanitizeInput($_POST['description'] ?? '');

        try {
            // Collect all debug information
            require_once __DIR__ . '/../../database/DebugRepository.php';
            $debugRepo = new DebugRepository();
            $debugEntries = $debugRepo->getRecentDebugEntries(100);

            // Collect system info
            $systemInfo = [
                'php_version' => phpversion(),
                'sapi' => php_sapi_name(),
                'os' => PHP_OS,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A',
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Get MySQL/MariaDB version
            try {
                require_once __DIR__ . '/../../services/ServiceContainer.php';
                $serviceContainer = ServiceContainer::getInstance(null, null);
                $pdo = $serviceContainer->getPdo();
                if ($pdo) {
                    $stmt = $pdo->query('SELECT VERSION() as version');
                    $result = $stmt->fetch();
                    $systemInfo['mysql_version'] = $result['version'] ?? 'N/A';
                }
            } catch (Exception $e) {
                $systemInfo['mysql_version'] = 'Error: ' . $e->getMessage();
            }

            // Get Debian version
            $systemInfo['debian_version'] = 'N/A';
            if (file_exists('/etc/debian_version') && is_readable('/etc/debian_version')) {
                $systemInfo['debian_version'] = trim(file_get_contents('/etc/debian_version'));
            }

            // Get OS release info
            $systemInfo['os_release'] = 'N/A';
            if (file_exists('/etc/os-release') && is_readable('/etc/os-release')) {
                $osInfo = parse_ini_file('/etc/os-release');
                $systemInfo['os_release'] = $osInfo['PRETTY_NAME'] ?? 'N/A';
            }

            // Get PHP config path and contents
            $phpIniPath = php_ini_loaded_file() ?: 'Not found';
            $systemInfo['php_ini_path'] = $phpIniPath;
            $systemInfo['php_ini_content'] = 'N/A';
            if ($phpIniPath && $phpIniPath !== 'Not found' && file_exists($phpIniPath) && is_readable($phpIniPath)) {
                $fileSize = filesize($phpIniPath);
                if ($fileSize > 51200) { // 50KB
                    $content = file_get_contents($phpIniPath, false, null, 0, 51200);
                    $content .= "\n\n[TRUNCATED - Original size: " . round($fileSize/1024, 1) . "KB]";
                    $systemInfo['php_ini_content'] = $content;
                } else {
                    $systemInfo['php_ini_content'] = file_get_contents($phpIniPath);
                }
            }

            // Get Apache config path and contents
            $apacheConfigPath = '/etc/apache2/apache2.conf';
            $systemInfo['apache_config_path'] = file_exists($apacheConfigPath) ? $apacheConfigPath : 'N/A';
            $systemInfo['apache_config_content'] = 'N/A';
            if (file_exists($apacheConfigPath) && is_readable($apacheConfigPath)) {
                $fileSize = filesize($apacheConfigPath);
                if ($fileSize > 51200) { // 50KB
                    $content = file_get_contents($apacheConfigPath, false, null, 0, 51200);
                    $content .= "\n\n[TRUNCATED - Original size: " . round($fileSize/1024, 1) . "KB]";
                    $systemInfo['apache_config_content'] = $content;
                } else {
                    $systemInfo['apache_config_content'] = file_get_contents($apacheConfigPath);
                }
            }

            // Get PHP extensions with versions
            $phpExtensions = get_loaded_extensions();
            sort($phpExtensions);
            $phpExtensionsWithVersions = [];
            foreach ($phpExtensions as $ext) {
                $version = phpversion($ext);
                $phpExtensionsWithVersions[$ext] = $version ?: 'N/A';
            }
            $systemInfo['php_extensions_count'] = count($phpExtensions);
            $systemInfo['php_extensions'] = $phpExtensionsWithVersions;

            // Get Constants.php values
            require_once __DIR__ . '/../../core/Constants.php';
            $systemInfo['constants'] = Constants::all();

            // Get defaultconfig.json values
            $defaultConfigPath = '/etc/eiou/defaultconfig.json';
            $systemInfo['user_config'] = [];
            if (file_exists($defaultConfigPath) && is_readable($defaultConfigPath)) {
                $defaultConfigData = json_decode(file_get_contents($defaultConfigPath), true);
                if ($defaultConfigData) {
                    $systemInfo['user_config'] = $defaultConfigData;
                }
            }

            // Collect PHP error log (last 50 lines)
            $phpLogContent = '';
            $phpLogPaths = ['/var/log/php_errors.log', '/var/log/eiou/eiou-php-error.log'];
            foreach ($phpLogPaths as $logPath) {
                if (file_exists($logPath) && is_readable($logPath)) {
                    $phpLogContent = shell_exec("tail -50 " . escapeshellarg($logPath));
                    break;
                }
            }

            // Collect Apache error log (last 50 lines)
            $apacheLogContent = '';
            $apacheLogPath = '/var/log/apache2/error.log';
            if (file_exists($apacheLogPath) && is_readable($apacheLogPath)) {
                $apacheLogContent = shell_exec("tail -50 " . escapeshellarg($apacheLogPath));
            }

            // Collect EIOU app log (last 50 lines)
            $eiouLogContent = '';
            $eiouLogPath = '/var/log/eiou/app.log';
            if (file_exists($eiouLogPath) && is_readable($eiouLogPath)) {
                $eiouLogContent = shell_exec("tail -50 " . escapeshellarg($eiouLogPath));
            }

            // Build report
            $report = [
                'description' => $description,
                'system_info' => $systemInfo,
                'debug_entries' => $debugEntries,
                'php_errors' => $phpLogContent,
                'apache_errors' => $apacheLogContent,
                'eiou_app_log' => $eiouLogContent
            ];

            // Sanitize log content to ensure valid UTF-8
            // Use iconv as fallback if mbstring is not available
            $sanitizeUtf8 = function($str) {
                if (empty($str)) return '';
                if (function_exists('mb_convert_encoding')) {
                    return mb_convert_encoding($str, 'UTF-8', 'UTF-8');
                } elseif (function_exists('iconv')) {
                    return iconv('UTF-8', 'UTF-8//IGNORE', $str);
                }
                // Last resort: strip non-UTF-8 characters with regex
                return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str);
            };

            if (isset($report['php_errors'])) {
                $report['php_errors'] = $sanitizeUtf8($report['php_errors'] ?? '');
            }
            if (isset($report['apache_errors'])) {
                $report['apache_errors'] = $sanitizeUtf8($report['apache_errors'] ?? '');
            }
            if (isset($report['eiou_app_log'])) {
                $report['eiou_app_log'] = $sanitizeUtf8($report['eiou_app_log'] ?? '');
            }

            // For now, save the report to a file (email integration would be added later)
            $reportPath = '/tmp/eiou-debug-report-' . date('YmdHis') . '.json';

            $jsonReport = json_encode($report, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($jsonReport === false) {
                $jsonError = json_last_error_msg();
                MessageHelper::redirectMessage('Failed to encode debug report: ' . $jsonError, 'error');
                return;
            }

            $bytesWritten = file_put_contents($reportPath, $jsonReport);
            if ($bytesWritten === false) {
                MessageHelper::redirectMessage('Failed to write debug report to file', 'error');
                return;
            }

            MessageHelper::redirectMessage('Debug report saved to ' . $reportPath . ' (' . round($bytesWritten/1024, 1) . ' KB). Email functionality coming soon.', 'success');

        } catch (Exception $e) {
            MessageHelper::redirectMessage('Failed to generate debug report: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Read full log file contents with size limit
     * Returns entire file content up to maxSize bytes
     *
     * @param string $filePath Path to log file
     * @param int $maxSize Maximum bytes to read (default 5MB)
     * @return string File contents or empty string if not readable
     */
    private function readFullLogFile(string $filePath, int $maxSize = 5242880): string {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return '';
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            return '';
        }

        // If file is within size limit, read entire file
        if ($fileSize <= $maxSize) {
            return file_get_contents($filePath) ?: '';
        }

        // File exceeds limit - read the last maxSize bytes (most recent logs)
        $content = file_get_contents($filePath, false, null, $fileSize - $maxSize, $maxSize);
        if ($content === false) {
            return '';
        }

        // Find first newline to start at a complete line
        $firstNewline = strpos($content, "\n");
        if ($firstNewline !== false && $firstNewline < 1000) {
            $content = substr($content, $firstNewline + 1);
        }

        return "[TRUNCATED - Showing last " . round($maxSize / 1024 / 1024, 1) . "MB of " . round($fileSize / 1024 / 1024, 1) . "MB]\n\n" . $content;
    }

    /**
     * Handle get debug report JSON action (AJAX)
     * Returns JSON debug data for client-side download
     * Supports both full logs (default) and limited logs (same as GUI display)
     *
     * @return void
     */
    public function handleGetDebugReportJson(): void
    {
        // Note: CSRF already verified in walletIndex.html before Functions.php is included
        // JSON header already set in Functions.php for clean error handling

        require_once __DIR__ . '/../../utils/Security.php';

        $description = Security::sanitizeInput($_POST['description'] ?? '');
        // Check if limited mode requested (same data as GUI display)
        $reportMode = Security::sanitizeInput($_POST['report_mode'] ?? 'full');
        $isFullReport = ($reportMode !== 'limited');

        try {
            // Collect debug information based on mode
            require_once __DIR__ . '/../../database/DebugRepository.php';
            $debugRepo = new DebugRepository();

            if ($isFullReport) {
                // Full mode: get complete debug history
                $debugEntries = $debugRepo->getAllDebugEntries();
            } else {
                // Limited mode: same as GUI display (100 entries)
                $debugEntries = $debugRepo->getRecentDebugEntries(100);
            }

            // Collect system info
            $systemInfo = [
                'php_version' => phpversion(),
                'sapi' => php_sapi_name(),
                'os' => PHP_OS,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'N/A',
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Get MySQL/MariaDB version
            try {
                require_once __DIR__ . '/../../services/ServiceContainer.php';
                $serviceContainer = ServiceContainer::getInstance(null, null);
                $pdo = $serviceContainer->getPdo();
                if ($pdo) {
                    $stmt = $pdo->query('SELECT VERSION() as version');
                    $result = $stmt->fetch();
                    $systemInfo['mysql_version'] = $result['version'] ?? 'N/A';
                }
            } catch (Exception $e) {
                $systemInfo['mysql_version'] = 'Error: ' . $e->getMessage();
            }

            // Get Debian version
            $systemInfo['debian_version'] = 'N/A';
            if (file_exists('/etc/debian_version') && is_readable('/etc/debian_version')) {
                $systemInfo['debian_version'] = trim(file_get_contents('/etc/debian_version'));
            }

            // Get OS release info
            $systemInfo['os_release'] = 'N/A';
            if (file_exists('/etc/os-release') && is_readable('/etc/os-release')) {
                $osInfo = parse_ini_file('/etc/os-release');
                $systemInfo['os_release'] = $osInfo['PRETTY_NAME'] ?? 'N/A';
            }

            // Get PHP config path and contents
            $phpIniPath = php_ini_loaded_file() ?: 'Not found';
            $systemInfo['php_ini_path'] = $phpIniPath;
            $systemInfo['php_ini_content'] = 'N/A';
            if ($phpIniPath && $phpIniPath !== 'Not found' && file_exists($phpIniPath) && is_readable($phpIniPath)) {
                $fileSize = filesize($phpIniPath);
                if ($fileSize > 51200) { // 50KB
                    $content = file_get_contents($phpIniPath, false, null, 0, 51200);
                    $content .= "\n\n[TRUNCATED - Original size: " . round($fileSize/1024, 1) . "KB]";
                    $systemInfo['php_ini_content'] = $content;
                } else {
                    $systemInfo['php_ini_content'] = file_get_contents($phpIniPath);
                }
            }

            // Get Apache config path and contents
            $apacheConfigPath = '/etc/apache2/apache2.conf';
            $systemInfo['apache_config_path'] = file_exists($apacheConfigPath) ? $apacheConfigPath : 'N/A';
            $systemInfo['apache_config_content'] = 'N/A';
            if (file_exists($apacheConfigPath) && is_readable($apacheConfigPath)) {
                $fileSize = filesize($apacheConfigPath);
                if ($fileSize > 51200) { // 50KB
                    $content = file_get_contents($apacheConfigPath, false, null, 0, 51200);
                    $content .= "\n\n[TRUNCATED - Original size: " . round($fileSize/1024, 1) . "KB]";
                    $systemInfo['apache_config_content'] = $content;
                } else {
                    $systemInfo['apache_config_content'] = file_get_contents($apacheConfigPath);
                }
            }

            // Get PHP extensions with versions
            $phpExtensions = get_loaded_extensions();
            sort($phpExtensions);
            $phpExtensionsWithVersions = [];
            foreach ($phpExtensions as $ext) {
                $version = phpversion($ext);
                $phpExtensionsWithVersions[$ext] = $version ?: 'N/A';
            }
            $systemInfo['php_extensions_count'] = count($phpExtensions);
            $systemInfo['php_extensions'] = $phpExtensionsWithVersions;

            // Get Constants.php values
            require_once __DIR__ . '/../../core/Constants.php';
            $systemInfo['constants'] = Constants::all();

            // Get defaultconfig.json values
            $defaultConfigPath = '/etc/eiou/defaultconfig.json';
            $systemInfo['user_config'] = [];
            if (file_exists($defaultConfigPath) && is_readable($defaultConfigPath)) {
                $defaultConfigData = json_decode(file_get_contents($defaultConfigPath), true);
                if ($defaultConfigData) {
                    $systemInfo['user_config'] = $defaultConfigData;
                }
            }

            // Collect log files based on report mode
            $phpLogContent = '';
            $phpLogPaths = ['/var/log/php_errors.log', '/var/log/eiou/eiou-php-error.log'];

            if ($isFullReport) {
                // Full mode: read entire log files (up to 5MB each)
                foreach ($phpLogPaths as $logPath) {
                    $content = $this->readFullLogFile($logPath);
                    if (!empty($content)) {
                        $phpLogContent = $content;
                        break;
                    }
                }
                $apacheLogContent = $this->readFullLogFile('/var/log/apache2/error.log');
                $eiouLogContent = $this->readFullLogFile('/var/log/eiou/app.log');
            } else {
                // Limited mode: same as GUI display (last 50 lines)
                foreach ($phpLogPaths as $logPath) {
                    if (file_exists($logPath) && is_readable($logPath)) {
                        $phpLogContent = shell_exec("tail -50 " . escapeshellarg($logPath));
                        break;
                    }
                }
                $apacheLogPath = '/var/log/apache2/error.log';
                $apacheLogContent = '';
                if (file_exists($apacheLogPath) && is_readable($apacheLogPath)) {
                    $apacheLogContent = shell_exec("tail -50 " . escapeshellarg($apacheLogPath));
                }
                $eiouLogPath = '/var/log/eiou/app.log';
                $eiouLogContent = '';
                if (file_exists($eiouLogPath) && is_readable($eiouLogPath)) {
                    $eiouLogContent = shell_exec("tail -50 " . escapeshellarg($eiouLogPath));
                }
            }

            // Build report with metadata about log completeness
            $report = [
                'description' => $description,
                'system_info' => $systemInfo,
                'debug_entries' => $debugEntries,
                'debug_entries_count' => count($debugEntries),
                'php_errors' => $phpLogContent,
                'apache_errors' => $apacheLogContent,
                'eiou_app_log' => $eiouLogContent,
                'report_type' => $isFullReport ? 'full' : 'limited'
            ];

            // Sanitize log content to ensure valid UTF-8
            $sanitizeUtf8 = function($str) {
                if (empty($str)) return '';
                if (function_exists('mb_convert_encoding')) {
                    return mb_convert_encoding($str, 'UTF-8', 'UTF-8');
                } elseif (function_exists('iconv')) {
                    return iconv('UTF-8', 'UTF-8//IGNORE', $str);
                }
                return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str);
            };

            if (isset($report['php_errors'])) {
                $report['php_errors'] = $sanitizeUtf8($report['php_errors'] ?? '');
            }
            if (isset($report['apache_errors'])) {
                $report['apache_errors'] = $sanitizeUtf8($report['apache_errors'] ?? '');
            }
            if (isset($report['eiou_app_log'])) {
                $report['eiou_app_log'] = $sanitizeUtf8($report['eiou_app_log'] ?? '');
            }

            // Return JSON response
            header('Content-Type: application/json');
            $jsonReport = json_encode($report, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($jsonReport === false) {
                echo json_encode(['error' => 'Failed to encode debug report: ' . json_last_error_msg()]);
            } else {
                echo $jsonReport;
            }
            exit;

        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Failed to generate debug report: ' . $e->getMessage()]);
            exit;
        }
    }

    /**
     * Route POST actions to appropriate handlers
     *
     * @return void
     */
    public function routeAction(): void
    {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'updateSettings':
                $this->handleUpdateSettings();
                break;
            case 'clearDebugLogs':
                $this->handleClearDebugLogs();
                break;
            case 'sendDebugReport':
                $this->handleSendDebugReport();
                break;
            case 'getDebugReportJson':
                $this->handleGetDebugReportJson();
                break;
            default:
                MessageHelper::redirectMessage('Unknown settings action', 'error');
        }
    }
}
