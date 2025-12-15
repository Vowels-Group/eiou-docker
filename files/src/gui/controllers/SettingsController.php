<?php
/**
 * Settings Controller
 *
 * Copyright 2025
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
            if (in_array($value, ['http', 'tor'])) {
                $settings['defaultTransportMode'] = $value;
            } else {
                $errors[] = 'Invalid transport mode: must be http or tor';
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
            default:
                MessageHelper::redirectMessage('Unknown settings action', 'error');
        }
    }
}
