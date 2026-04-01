<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Gui\Includes\Session;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Security;
use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Database\DebugRepository;
use Eiou\Services\DebugReportService;
use PDO;
use Exception;
use Eiou\Gui\Helpers\MessageHelper;

/**
 * Settings Controller
 *
 * Handles HTTP POST requests for settings-related actions.
 *
 * This controller uses Dependency Injection. All dependencies must be provided
 * via the constructor.
 */

class SettingsController
{
    /**
     * @var Session Session manager
     */
    private Session $session;

    /**
     * @var PDO|null Database connection
     */
    private ?PDO $pdo;

    /**
     * Constructor
     *
     * @param Session $session Session manager (required)
     * @param PDO|null $pdo Database connection (optional, some methods may fail without it)
     */
    public function __construct(Session $session, ?PDO $pdo = null)
    {
        $this->session = $session;
        $this->pdo = $pdo;
    }

    /**
     * Get the PDO database connection
     *
     * @return PDO|null Database connection or null if not provided
     */
    private function getPdoConnection(): ?PDO
    {
        return $this->pdo;
    }

    /**
     * Read the current p2pExpiration value from the saved config file.
     * Falls back to the Constants default if the file or key is absent.
     *
     * @return int Current P2P expiration in seconds
     */
    private function getP2pExpirationFromConfig(): int
    {
        $configFile = '/etc/eiou/config/defaultconfig.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if (is_array($config) && isset($config['p2pExpiration'])) {
                return (int) $config['p2pExpiration'];
            }
        }
        return Constants::P2P_DEFAULT_EXPIRATION_SECONDS;
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

        // Minimum Fee (0 allowed for free relaying)
        if (isset($_POST['minFee'])) {
            $validation = InputValidator::validateFeeAmount($_POST['minFee']);
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

        // Direct Transaction Delivery Expiration (seconds, 0 = no expiry)
        if (isset($_POST['directTxExpiration'])) {
            $value = $_POST['directTxExpiration'];
            if (is_numeric($value) && intval($value) >= 0) {
                $settings['directTxExpiration'] = intval($value);
            } else {
                $errors[] = 'Invalid direct transaction expiration: must be a non-negative integer (0 = no expiry)';
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
                $errors[] = 'Invalid transport mode: must be in ' . implode(', ', Constants::VALID_TRANSPORT_INDICES);
            }
        }

        // Auto-Refresh Enabled (boolean toggle, default: false/off)
        // Checkbox only posts value when checked, so we need to handle both cases
        $settings['autoRefreshEnabled'] = isset($_POST['autoRefreshEnabled']) && $_POST['autoRefreshEnabled'] === '1';

        // Auto-Backup Enabled (boolean toggle, default: true/on)
        // Checkbox only posts value when checked, so we need to handle both cases
        $settings['autoBackupEnabled'] = isset($_POST['autoBackupEnabled']) && $_POST['autoBackupEnabled'] === '1';

        // Auto-Accept Transaction (boolean toggle, default: true/on)
        $settings['autoAcceptTransaction'] = isset($_POST['autoAcceptTransaction']) && $_POST['autoAcceptTransaction'] === '1';

        // Feature toggles (boolean checkboxes)
        $settings['contactStatusEnabled'] = isset($_POST['contactStatusEnabled']) && $_POST['contactStatusEnabled'] === '1';
        $settings['contactStatusSyncOnPing'] = isset($_POST['contactStatusSyncOnPing']) && $_POST['contactStatusSyncOnPing'] === '1';
        $settings['autoChainDropPropose'] = isset($_POST['autoChainDropPropose']) && $_POST['autoChainDropPropose'] === '1';
        $settings['autoChainDropAccept'] = isset($_POST['autoChainDropAccept']) && $_POST['autoChainDropAccept'] === '1';
        $settings['autoChainDropAcceptGuard'] = isset($_POST['autoChainDropAcceptGuard']) && $_POST['autoChainDropAcceptGuard'] === '1';
        $settings['autoAcceptRestoredContact'] = isset($_POST['autoAcceptRestoredContact']) && $_POST['autoAcceptRestoredContact'] === '1';
        $settings['hopBudgetRandomized'] = isset($_POST['hopBudgetRandomized']) && $_POST['hopBudgetRandomized'] === '1';
        $settings['apiEnabled'] = isset($_POST['apiEnabled']) && $_POST['apiEnabled'] === '1';
        $settings['autoRejectUnknownCurrency'] = isset($_POST['autoRejectUnknownCurrency']) && $_POST['autoRejectUnknownCurrency'] === '1';
        $settings['updateCheckEnabled'] = isset($_POST['updateCheckEnabled']) && $_POST['updateCheckEnabled'] === '1';
        $settings['analyticsEnabled'] = isset($_POST['analyticsEnabled']) && $_POST['analyticsEnabled'] === '1';

        // API CORS — textarea value (newline-separated), normalize to comma-separated
        if (isset($_POST['apiCorsAllowedOrigins'])) {
            $rawOrigins = preg_split('/[\r\n,]+/', $_POST['apiCorsAllowedOrigins'], -1, PREG_SPLIT_NO_EMPTY);
            $sanitizedOrigins = [];
            foreach ($rawOrigins as $origin) {
                $sanitized = trim(Security::sanitizeInput($origin));
                if ($sanitized !== '') {
                    $sanitizedOrigins[] = $sanitized;
                }
            }
            $settings['apiCorsAllowedOrigins'] = implode(',', $sanitizedOrigins);
        }

        // Display Decimals — simple integer 0-8
        if (isset($_POST['displayDecimals'])) {
            $dec = trim($_POST['displayDecimals']);
            if ($dec !== '' && ctype_digit($dec) && (int) $dec >= 0 && (int) $dec <= Constants::INTERNAL_PRECISION) {
                $settings['displayDecimals'] = (int) $dec;
            } elseif ($dec !== '') {
                $errors[] = "Display decimal places must be 0-" . Constants::INTERNAL_PRECISION;
            }
        }

        // Allowed Currencies — cross-validate against submitted display decimals (not saved values)
        if (isset($_POST['allowedCurrencies'])) {
            $rawCurrencies = preg_split('/[\r\n,]+/', strtoupper($_POST['allowedCurrencies']), -1, PREG_SPLIT_NO_EMPTY);
            $currencies = array_filter(array_map('trim', $rawCurrencies));
            $currencyErrors = [];

            foreach ($currencies as $c) {
                if (!preg_match('/^[A-Z0-9]{' . Constants::VALIDATION_CURRENCY_CODE_MIN_LENGTH . ',' . Constants::VALIDATION_CURRENCY_CODE_MAX_LENGTH . '}$/', $c)) {
                    $currencyErrors[] = "Invalid currency code format: {$c}";
                    continue;
                }
                // No display decimals check — currencies without explicit display
                // decimals default to INTERNAL_PRECISION (8 decimal places)
            }
            if (!empty($currencyErrors)) {
                $errors = array_merge($errors, $currencyErrors);
            } else {
                $settings['allowedCurrencies'] = implode(',', $currencies);
            }
        }

        // Backup & logging
        if (isset($_POST['backupRetentionCount'])) {
            $validation = InputValidator::validatePositiveInteger($_POST['backupRetentionCount'], 1);
            if ($validation['valid']) { $settings['backupRetentionCount'] = $validation['value']; }
            else { $errors[] = 'Invalid backup retention count: ' . $validation['error']; }
        }
        // Backup time — single HH:MM input replaces separate hour/minute fields
        if (isset($_POST['backupCronTime']) && preg_match('/^(\d{1,2}):(\d{2})$/', trim($_POST['backupCronTime']), $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                $settings['backupCronHour'] = $hour;
                $settings['backupCronMinute'] = $minute;
            } else {
                $errors[] = 'Invalid backup time: hour must be 0-23 and minute must be 0-59';
            }
        }
        if (isset($_POST['logLevel'])) {
            $validation = InputValidator::validateLogLevel($_POST['logLevel']);
            if ($validation['valid']) { $settings['logLevel'] = $validation['value']; }
            else { $errors[] = 'Invalid log level: ' . $validation['error']; }
        }
        if (isset($_POST['logMaxEntries'])) {
            $validation = InputValidator::validatePositiveInteger($_POST['logMaxEntries'], 10);
            if ($validation['valid']) { $settings['logMaxEntries'] = $validation['value']; }
            else { $errors[] = 'Invalid log max entries: ' . $validation['error']; }
        }

        // Data retention
        $retentionFields = [
            'cleanupDeliveryRetentionDays' => 'delivery retention',
            'cleanupDlqRetentionDays' => 'DLQ retention',
            'cleanupHeldTxRetentionDays' => 'held TX retention',
            'cleanupRp2pRetentionDays' => 'RP2P retention',
            'cleanupMetricsRetentionDays' => 'metrics retention',
        ];
        foreach ($retentionFields as $field => $label) {
            if (isset($_POST[$field])) {
                $validation = InputValidator::validatePositiveInteger($_POST[$field], 1);
                if ($validation['valid']) { $settings[$field] = $validation['value']; }
                else { $errors[] = "Invalid $label: " . $validation['error']; }
            }
        }

        // Rate limiting
        $rateLimitFields = [
            'p2pRateLimitPerMinute' => 'P2P rate limit',
            'rateLimitMaxAttempts' => 'rate limit max attempts',
            'rateLimitWindowSeconds' => 'rate limit window',
            'rateLimitBlockSeconds' => 'rate limit block',
        ];
        foreach ($rateLimitFields as $field => $label) {
            if (isset($_POST[$field])) {
                $validation = InputValidator::validatePositiveInteger($_POST[$field], 1);
                if ($validation['valid']) { $settings[$field] = $validation['value']; }
                else { $errors[] = "Invalid $label: " . $validation['error']; }
            }
        }

        // Network timeouts
        if (isset($_POST['httpTransportTimeoutSeconds'])) {
            $validation = InputValidator::validateIntRange($_POST['httpTransportTimeoutSeconds'], 5, 120, 'HTTP timeout');
            if ($validation['valid']) { $settings['httpTransportTimeoutSeconds'] = $validation['value']; }
            else { $errors[] = 'Invalid HTTP timeout: ' . $validation['error']; }
        }
        if (isset($_POST['torTransportTimeoutSeconds'])) {
            $validation = InputValidator::validateIntRange($_POST['torTransportTimeoutSeconds'], 10, 300, 'Tor timeout');
            if ($validation['valid']) { $settings['torTransportTimeoutSeconds'] = $validation['value']; }
            else { $errors[] = 'Invalid Tor timeout: ' . $validation['error']; }
        }
        // Tor circuit health
        if (isset($_POST['torCircuitMaxFailures'])) {
            $validation = InputValidator::validateIntRange($_POST['torCircuitMaxFailures'], 1, 10, 'Tor circuit max failures');
            if ($validation['valid']) { $settings['torCircuitMaxFailures'] = $validation['value']; }
            else { $errors[] = 'Invalid Tor circuit max failures: ' . $validation['error']; }
        }
        if (isset($_POST['torCircuitCooldownSeconds'])) {
            $validation = InputValidator::validateIntRange($_POST['torCircuitCooldownSeconds'], 60, 3600, 'Tor circuit cooldown');
            if ($validation['valid']) { $settings['torCircuitCooldownSeconds'] = $validation['value']; }
            else { $errors[] = 'Invalid Tor circuit cooldown: ' . $validation['error']; }
        }
        if (isset($_POST['torFailureTransportFallback'])) {
            $validation = InputValidator::validateBoolean($_POST['torFailureTransportFallback']);
            if ($validation['valid']) { $settings['torFailureTransportFallback'] = $validation['value']; }
            else { $errors[] = 'Invalid Tor failure fallback: ' . $validation['error']; }
        }
        if (isset($_POST['torFallbackRequireEncrypted'])) {
            $validation = InputValidator::validateBoolean($_POST['torFallbackRequireEncrypted']);
            if ($validation['valid']) { $settings['torFallbackRequireEncrypted'] = $validation['value']; }
            else { $errors[] = 'Invalid Tor fallback require encrypted: ' . $validation['error']; }
        }

        // Sync settings
        if (isset($_POST['syncChunkSize'])) {
            $validation = InputValidator::validateIntRange($_POST['syncChunkSize'], 10, 500, 'Sync chunk size');
            if ($validation['valid']) { $settings['syncChunkSize'] = $validation['value']; }
            else { $errors[] = 'Invalid sync chunk size: ' . $validation['error']; }
        }
        if (isset($_POST['syncMaxChunks'])) {
            $validation = InputValidator::validateIntRange($_POST['syncMaxChunks'], 10, 1000, 'Sync max chunks');
            if ($validation['valid']) { $settings['syncMaxChunks'] = $validation['value']; }
            else { $errors[] = 'Invalid sync max chunks: ' . $validation['error']; }
        }
        if (isset($_POST['heldTxSyncTimeoutSeconds'])) {
            // Max is p2pExpiration - 1 (use submitted value if valid, else saved value)
            $p2pExpForMax = isset($settings['p2pExpiration']) ? (int) $settings['p2pExpiration'] : $this->getP2pExpirationFromConfig();
            $syncTimeoutMax = max(30, $p2pExpForMax - 1);
            $validation = InputValidator::validateIntRange($_POST['heldTxSyncTimeoutSeconds'], 30, $syncTimeoutMax, 'Held TX sync timeout');
            if ($validation['valid']) { $settings['heldTxSyncTimeoutSeconds'] = $validation['value']; }
            else { $errors[] = 'Invalid held TX sync timeout: ' . $validation['error']; }
        }

        // Display settings
        if (isset($_POST['displayDateFormat'])) {
            $validation = InputValidator::validateDateFormat($_POST['displayDateFormat']);
            if ($validation['valid']) { $settings['displayDateFormat'] = $validation['value']; }
            else { $errors[] = 'Invalid date format: ' . $validation['error']; }
        }
        if (isset($_POST['displayRecentTransactionsLimit'])) {
            $validation = InputValidator::validatePositiveInteger($_POST['displayRecentTransactionsLimit'], 1);
            if ($validation['valid']) { $settings['displayRecentTransactionsLimit'] = $validation['value']; }
            else { $errors[] = 'Invalid recent transactions limit: ' . $validation['error']; }
        }

        // Check for errors
        if (!empty($errors)) {
            MessageHelper::redirectMessage(implode('; ', $errors), 'error');
            return;
        }

        // Save settings to config file
        try {
            $configFile = '/etc/eiou/config/defaultconfig.json';
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
            $debugRepo = new DebugRepository($this->getPdoConnection());

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

        $description = Security::sanitizeInput($_POST['description'] ?? '');

        try {
            $reportService = new DebugReportService(
                new DebugRepository($this->getPdoConnection()),
                $this->getPdoConnection()
            );
            $result = $reportService->generateAndSave($description, false);
            $sizeKb = round($result['size'] / 1024, 1);

            MessageHelper::redirectMessage(
                'Debug report saved to ' . $result['path'] . ' (' . $sizeKb . ' KB). Email functionality coming soon.',
                'success'
            );
        } catch (Exception $e) {
            MessageHelper::redirectMessage('Failed to generate debug report: ' . $e->getMessage(), 'error');
        }
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
        // Note: CSRF already verified in index.html before Functions.php is included
        // JSON header already set in Functions.php for clean error handling

        $description = Security::sanitizeInput($_POST['description'] ?? '');
        $reportMode = Security::sanitizeInput($_POST['report_mode'] ?? 'full');
        $isFullReport = ($reportMode !== 'limited');

        try {
            $reportService = new DebugReportService(
                new DebugRepository($this->getPdoConnection()),
                $this->getPdoConnection()
            );
            $report = $reportService->generateReport($description, $isFullReport);

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
            case 'analyticsConsent':
                $this->handleAnalyticsConsent();
                break;
            default:
                MessageHelper::redirectMessage('Unknown settings action', 'error');
        }
    }

    /**
     * Handle one-time analytics consent from the post-login modal.
     * Sets analyticsConsentAsked=true and optionally analyticsEnabled=true.
     * Returns JSON response (AJAX endpoint).
     *
     * @return void
     */
    private function handleAnalyticsConsent(): void
    {
        $this->session->verifyCSRFToken();

        $enable = isset($_POST['consent']) && $_POST['consent'] === '1';

        $configFile = '/etc/eiou/config/defaultconfig.json';
        $config = [];

        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true) ?? [];
        }

        $config['analyticsConsentAsked'] = true;
        if ($enable) {
            $config['analyticsEnabled'] = true;
        }

        header('Content-Type: application/json');

        if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to save preference']);
            return;
        }

        echo json_encode(['success' => true, 'analyticsEnabled' => $enable]);
    }
}
