<?php
/**
 * Input Validation Helper Class
 * Provides comprehensive input validation and sanitization for the eIOU application
 * Copyright 2025
 */

class InputValidator {

    /**
     * Validate CLI input
     *
     * @param array $argv user CLI input
     * @param array $quantityParameters Amount of arguments needed inside argv
     * @return array ['valid' => bool, 'value' => int|null, 'error' => string|null]
     */
    public static function validateArgvAmount(array $argv, int $quantityParameters): array {
        // Check if amount parameters is correct
        if (count($argv) < $quantityParameters) {
            return ['valid' => false,
                    'value' => null, 
                    'error' => 'CLI ' . $argv[1] . ' request should constitute ' . $quantityParameters . ' parameters.'];
        } 
        return ['valid' => true, 'value' => null, 'error' => null];
    }

    /**
     * Validate transaction amount
     *
     * @param mixed $amount Amount to validate
     * @param string $currency Currency code (default: USD)
     * @return array ['valid' => bool, 'value' => float|null, 'error' => string|null]
     */
    public static function validateAmount($amount, $currency = 'USD'): array {
        // Check if amount is numeric
        if (!is_numeric($amount)) {
            return ['valid' => false, 'value' => null, 'error' => 'Amount must be a number'];
        }

        $amount = floatval($amount);

        // Check if amount is positive
        if ($amount <= 0) {
            return ['valid' => false, 'value' => null, 'error' => 'Amount must be greater than zero'];
        }
        // Check maximum amount (prevent overflow)
        if ($amount > Constants::TRANSACTION_MAX_AMOUNT) {
            return ['valid' => false, 'value' => null, 'error' => 'Amount exceeds maximum allowed value'];
        }

        // Round to currency decimal precision
        $amount = round($amount, Constants::DISPLAY_CURRENCY_DECIMALS);

        return ['valid' => true, 'value' => $amount, 'error' => null];
    }

    /**
     * Validate Fee amount
     *
     * @param mixed $amount Amount to validate
     * @return array ['valid' => bool, 'value' => float|null, 'error' => string|null]
     */
    public static function validateAmountFee($amount): array {
        // Check if amount is numeric
        if (!is_numeric($amount)) {
            return ['valid' => false, 'value' => null, 'error' => 'Amount must be a number'];
        }

        $amount = floatval($amount);

        // Check if amount is positive
        if ($amount <= 0) {
            return ['valid' => false, 'value' => null, 'error' => 'Amount must be greater than zero'];
        }

        // Round to currency decimal precision
        $amount = round($amount, Constants::DISPLAY_CURRENCY_DECIMALS);

        return ['valid' => true, 'value' => $amount, 'error' => null];
    }


    /**
     * Validate currency code
     *
     * @param string $currency Currency code to validate
     * @return array ['valid' => bool, 'value' => string|null, 'error' => string|null]
     */
    public static function validateCurrency($currency): array {
        // Allowed currencies (ISO 4217)
        $allowedCurrencies = ['USD'];

        $currency = strtoupper(trim($currency));

        if (strlen($currency) !== Constants::VALIDATION_CURRENCY_CODE_LENGTH) {
            return ['valid' => false, 'value' => null, 'error' => 'Currency code must be ' . Constants::VALIDATION_CURRENCY_CODE_LENGTH . ' characters'];
        }

        if (!in_array($currency, $allowedCurrencies)) {
            return ['valid' => false, 'value' => null, 'error' => 'Unsupported currency code'];
        }

        return ['valid' => true, 'value' => $currency, 'error' => null];
    }

    /**
     * Validate public key format
     *
     * @param string $publicKey Public key to validate
     * @return array ['valid' => bool, 'value' => string|null, 'error' => string|null]
     */
    public static function validatePublicKey($publicKey): array {
        if (!is_string($publicKey) || empty($publicKey)) {
            return ['valid' => false, 'value' => null, 'error' => 'Public key cannot be empty'];
        }

        // Check minimum length
        if (strlen($publicKey) < Constants::VALIDATION_PUBLIC_KEY_MIN_LENGTH) {
            return ['valid' => false, 'value' => null, 'error' => 'Public key is too short'];
        }

        // Check if it starts with expected format (PEM)
        if (strpos($publicKey, '-----BEGIN PUBLIC KEY-----') === false) {
            return ['valid' => false, 'value' => null, 'error' => 'Invalid public key format'];
        }

        return ['valid' => true, 'value' => $publicKey, 'error' => null];
    }

    /**
     * Validate address format (HTTP or Tor)
     *
     * @param string $address Address to validate
     * @return array ['valid' => bool, 'value' => string|null, 'error' => string|null, 'type' => string|null]
     */
    public static function validateAddress($address): array {
        if (!is_string($address) || empty($address)) {
            return ['valid' => false, 'value' => null, 'error' => 'Address cannot be empty', 'type' => null];
        }

        $address = trim($address);

        // Check for Tor address (v2 or v3)
        $torV2Pattern = '/^[a-z2-7]{' . Constants::VALIDATION_TOR_V2_ADDRESS_LENGTH . '}\.onion(:\d+)?(\/.*)?$/i';
        $torV3Pattern = '/^[a-z2-7]{' . Constants::VALIDATION_TOR_V3_ADDRESS_LENGTH . '}\.onion(:\d+)?(\/.*)?$/i';
        if (preg_match($torV2Pattern, $address) || preg_match($torV3Pattern, $address)) {
            return ['valid' => true, 'value' => $address, 'error' => null, 'type' => 'tor'];
        }

        // Check for HTTP/HTTPS address
        if (filter_var($address, FILTER_VALIDATE_URL) !== false) {
            $scheme = parse_url($address, PHP_URL_SCHEME);
            if (in_array($scheme, ['http', 'https'])) {
                return ['valid' => true, 'value' => $address, 'error' => null, 'type' => 'http'];
            }
        }

        return ['valid' => false, 'value' => null, 'error' => 'Invalid address format', 'type' => null];
    }

    /**
     * Validate address format og hostname
     *
     * @param string $address Address to validate
     * @return array ['valid' => bool, 'value' => string|null, 'error' => string|null, 'type' => string|null]
     */
    public static function validateHostname($address){
        if (!is_string($address) || empty($address)) {
            return ['valid' => false, 'value' => null, 'error' => 'Address cannot be empty', 'type' => null];
        }

        // Check for HTTP/HTTPS address
        if (filter_var($address, FILTER_VALIDATE_URL) !== false) {
            $scheme = parse_url($address, PHP_URL_SCHEME);
            if (in_array($scheme, ['http', 'https'])) {
                return ['valid' => true, 'value' => $address, 'error' => null, 'type' => 'http'];
            }
        }

        return ['valid' => false, 'value' => null, 'error' => 'Invalid address format', 'type' => null];
    }

    /**
     * Validate transaction ID (hash)
     *
     * @param string $txid Transaction ID to validate
     * @return array ['valid' => bool, 'value' => string|null, 'error' => string|null]
     */
    public static function validateTxid($txid): array {
        if (!is_string($txid) || empty($txid)) {
            return ['valid' => false, 'value' => null, 'error' => 'Transaction ID cannot be empty'];
        }

        // SHA-256 hash length check
        if (strlen($txid) !== Constants::VALIDATION_HASH_LENGTH_SHA256) {
            return ['valid' => false, 'value' => null, 'error' => 'Invalid transaction ID length'];
        }

        // Check if it's a valid hex string
        if (!ctype_xdigit($txid)) {
            return ['valid' => false, 'value' => null, 'error' => 'Transaction ID must be hexadecimal'];
        }

        return ['valid' => true, 'value' => strtolower($txid), 'error' => null];
    }

    /**
     * Validate contact name
     *
     * @param string $name Contact name to validate
     * @return array ['valid' => bool, 'value' => string|null, 'error' => string|null]
     */
    public static function validateContactName($name): array {
        if (!is_string($name) || empty(trim($name))) {
            return ['valid' => false, 'value' => null, 'error' => 'Contact name cannot be empty'];
        }

        $name = trim($name);

        // Length check
        if (strlen($name) < Constants::CONTACT_MIN_NAME_LENGTH || strlen($name) > Constants::CONTACT_MAX_NAME_LENGTH) {
            return ['valid' => false, 'value' => null, 'error' => 'Contact name must be between ' . Constants::CONTACT_MIN_NAME_LENGTH . ' and ' . Constants::CONTACT_MAX_NAME_LENGTH . ' characters'];
        }

        // Alphanumeric, spaces, dashes, and underscores only
        if (!preg_match('/^[a-zA-Z0-9_\s-]+$/', $name)) {
            return ['valid' => false, 'value' => null, 'error' => 'Contact name contains invalid characters'];
        }

        return ['valid' => true, 'value' => $name, 'error' => null];
    }

    /**
     * Validate fee percentage
     *
     * @param mixed $fee Fee percentage to validate
     * @return array ['valid' => bool, 'value' => float|null, 'error' => string|null]
     */
    public static function validateFeePercent($fee): array {
        if (!is_numeric($fee)) {
            return ['valid' => false, 'value' => null, 'error' => 'Fee must be a number'];
        }

        $fee = floatval($fee);

        // Fee must be within valid percentage range
        if ($fee < Constants::VALIDATION_FEE_MIN_PERCENT || $fee > Constants::VALIDATION_FEE_MAX_PERCENT) {
            return ['valid' => false, 'value' => null, 'error' => 'Fee must be between ' . Constants::VALIDATION_FEE_MIN_PERCENT . ' and ' . Constants::VALIDATION_FEE_MAX_PERCENT . ' percent'];
        }

        // Round to fee decimal precision
        $fee = round($fee, Constants::FEE_PERCENT_DECIMAL_PRECISION + 2);

        return ['valid' => true, 'value' => $fee, 'error' => null];
    }

    /**
     * Validate credit limit
     *
     * @param mixed $credit Credit limit to validate
     * @return array ['valid' => bool, 'value' => float|null, 'error' => string|null]
     */
    public static function validateCreditLimit($credit): array {
        if (!is_numeric($credit)) {
            return ['valid' => false, 'value' => null, 'error' => 'Credit limit must be a number'];
        }

        $credit = floatval($credit);

        // Credit must be non-negative
        if ($credit < 0) {
            return ['valid' => false, 'value' => null, 'error' => 'Credit limit cannot be negative'];
        }

        // Check maximum credit limit
        if ($credit > Constants::TRANSACTION_MAX_AMOUNT) {
            return ['valid' => false, 'value' => null, 'error' => 'Credit limit exceeds maximum allowed value'];
        }

        // Round to currency decimal precision
        $credit = round($credit, Constants::DISPLAY_CURRENCY_DECIMALS);

        return ['valid' => true, 'value' => $credit, 'error' => null];
    }

    /**
     * Validate timestamp
     *
     * @param mixed $timestamp Timestamp to validate
     * @return array ['valid' => bool, 'value' => int|null, 'error' => string|null]
     */
    public static function validateTimestamp($timestamp): array {
        if (!is_numeric($timestamp)) {
            return ['valid' => false, 'value' => null, 'error' => 'Timestamp must be numeric'];
        }

        $timestamp = intval($timestamp);

        // Check if timestamp is reasonable (not too far in past or future)
        $now = time();
        $oneYear = Constants::TIME_HOURS_PER_DAY * Constants::TIME_MINUTES_PER_HOUR * Constants::TIME_SECONDS_PER_MINUTE * 365;
        $oneYearAgo = $now - $oneYear;
        $oneYearFromNow = $now + $oneYear;

        if ($timestamp < $oneYearAgo || $timestamp > $oneYearFromNow) {
            return ['valid' => false, 'value' => null, 'error' => 'Timestamp is outside acceptable range'];
        }

        return ['valid' => true, 'value' => $timestamp, 'error' => null];
    }

    /**
     * Validate request level for P2P
     *
     * @param mixed $level Request level to validate
     * @param int $maxLevel Maximum allowed level
     * @return array ['valid' => bool, 'value' => int|null, 'error' => string|null]
     */
    public static function validateRequestLevel($level, $maxLevel = null): array {
        if ($maxLevel === null) {
            $maxLevel = Constants::P2P_REQUEST_LEVEL_VALIDATION_MAX;
        }
        $sanitized = Security::sanitizeInt($level, 0, $maxLevel);

        if ($sanitized === null) {
            return ['valid' => false, 'value' => null, 'error' => "Request level must be between 0 and $maxLevel"];
        }

        return ['valid' => true, 'value' => $sanitized, 'error' => null];
    }

    /**
     * Validate signature format
     *
     * @param string $signature Signature to validate
     * @return array ['valid' => bool, 'value' => string|null, 'error' => string|null]
     */
    public static function validateSignature($signature): array {
        if (!is_string($signature) || empty($signature)) {
            return ['valid' => false, 'value' => null, 'error' => 'Signature cannot be empty'];
        }

        // Base64 encoded signatures should only contain valid characters
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $signature)) {
            return ['valid' => false, 'value' => null, 'error' => 'Invalid signature format'];
        }

        // Check minimum length (signatures should be reasonably long)
        if (strlen($signature) < Constants::VALIDATION_SIGNATURE_MIN_LENGTH) {
            return ['valid' => false, 'value' => null, 'error' => 'Signature is too short'];
        }

        return ['valid' => true, 'value' => $signature, 'error' => null];
    }

    /**
     * Validate memo field
     *
     * @param string $memo Memo to validate
     * @param int $maxLength Maximum length
     * @return array ['valid' => bool, 'value' => string|null, 'error' => string|null]
     */
    public static function validateMemo($memo, $maxLength = null): array {
        if ($maxLength === null) {
            $maxLength = Constants::VALIDATION_MEMO_MAX_LENGTH;
        }
        if (!is_string($memo)) {
            return ['valid' => false, 'value' => null, 'error' => 'Memo must be a string'];
        }

        $memo = trim($memo);

        if (strlen($memo) > $maxLength) {
            return ['valid' => false, 'value' => null, 'error' => "Memo exceeds maximum length of $maxLength characters"];
        }

        // Remove potentially dangerous characters
        $memo = Security::sanitizeInput($memo);

        return ['valid' => true, 'value' => $memo, 'error' => null];
    }

    /**
     * Validate complete transaction request
     *
     * @param array $request Transaction request data
     * @return array ['valid' => bool, 'errors' => array, 'sanitized' => array|null]
     */
    public static function validateTransactionRequest(array $request): array {
        $errors = [];
        $sanitized = [];

        // Validate sender address
        if (isset($request['senderAddress'])) {
            $result = self::validateAddress($request['senderAddress']);
            if (!$result['valid']) {
                $errors['senderAddress'] = $result['error'];
            } else {
                $sanitized['senderAddress'] = $result['value'];
            }
        } else {
            $errors['senderAddress'] = 'Sender address is required';
        }

        // Validate receiver address
        if (isset($request['receiverAddress'])) {
            $result = self::validateAddress($request['receiverAddress']);
            if (!$result['valid']) {
                $errors['receiverAddress'] = $result['error'];
            } else {
                $sanitized['receiverAddress'] = $result['value'];
            }
        } else {
            $errors['receiverAddress'] = 'Receiver address is required';
        }

        // Validate amount
        if (isset($request['amount'])) {
            $result = self::validateAmount($request['amount']);
            if (!$result['valid']) {
                $errors['amount'] = $result['error'];
            } else {
                $sanitized['amount'] = $result['value'];
            }
        } else {
            $errors['amount'] = 'Amount is required';
        }

        // Validate currency
        $currency = $request['currency'] ?? Constants::TRANSACTION_DEFAULT_CURRENCY;
        $result = self::validateCurrency($currency);
        if (!$result['valid']) {
            $errors['currency'] = $result['error'];
        } else {
            $sanitized['currency'] = $result['value'];
        }

        // Validate public keys
        if (isset($request['senderPublicKey'])) {
            $result = self::validatePublicKey($request['senderPublicKey']);
            if (!$result['valid']) {
                $errors['senderPublicKey'] = $result['error'];
            } else {
                $sanitized['senderPublicKey'] = $result['value'];
            }
        } else {
            $errors['senderPublicKey'] = 'Sender public key is required';
        }

        if (isset($request['receiverPublicKey'])) {
            $result = self::validatePublicKey($request['receiverPublicKey']);
            if (!$result['valid']) {
                $errors['receiverPublicKey'] = $result['error'];
            } else {
                $sanitized['receiverPublicKey'] = $result['value'];
            }
        } else {
            $errors['receiverPublicKey'] = 'Receiver public key is required';
        }

        // Validate signature
        if (isset($request['signature'])) {
            $result = self::validateSignature($request['signature']);
            if (!$result['valid']) {
                $errors['signature'] = $result['error'];
            } else {
                $sanitized['signature'] = $result['value'];
            }
        } else {
            $errors['signature'] = 'Signature is required';
        }

        // Validate memo if present
        if (isset($request['memo'])) {
            $result = self::validateMemo($request['memo']);
            if (!$result['valid']) {
                $errors['memo'] = $result['error'];
            } else {
                $sanitized['memo'] = $result['value'];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized' => empty($errors) ? $sanitized : null
        ];
    }
}
