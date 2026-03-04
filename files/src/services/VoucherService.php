<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use Eiou\Utils\Logger;
use PDO;
use Exception;
use RuntimeException;

/**
 * Voucher Service — Trust-Only Onboarding
 *
 * Enables prepaid voucher redemption (gift cards, convenience store top-ups).
 * When a user redeems a voucher code:
 *   1. Auto-adds the issuer as a trusted contact
 *   2. Sets credit line = voucher amount
 *   3. Issuer sends the balance as an eIOU
 *
 * The user goes from zero to funded in one scan/code entry.
 *
 * Issuer flow:
 *   1. Issuer generates voucher codes (batch or on-demand)
 *   2. Codes are printed on cards / shown at POS
 *   3. User enters code in wallet app
 *   4. Wallet calls issuer's redeem endpoint
 *   5. Issuer verifies code, sends eIOU, marks code used
 *
 * This service handles BOTH sides:
 *   - Issuer: generate codes, verify, fulfill
 *   - Redeemer: validate code, auto-trust issuer, receive balance
 */
class VoucherService
{
    private PDO $pdo;
    private UserContext $user;

    // Code format: 16 chars alphanumeric, grouped for readability
    // e.g. EIOU-A3B7-K9M2-X4P1
    private const CODE_LENGTH = 16;
    private const CODE_PREFIX = 'EIOU';

    public function __construct(PDO $pdo, UserContext $user)
    {
        $this->pdo = $pdo;
        $this->user = $user;
    }

    // ========================================================================
    // ISSUER SIDE: Generate and manage voucher codes
    // ========================================================================

    /**
     * Generate a batch of voucher codes.
     *
     * @param float $amount Face value per voucher
     * @param string $currency Currency code (USD, JPY, etc.)
     * @param int $count Number of codes to generate
     * @param string|null $label Batch label (e.g. "7-Eleven Tokyo March 2026")
     * @param string|null $expiresAt Expiry date (ISO 8601), null = no expiry
     * @return array Generated voucher codes with metadata
     */
    public function generateBatch(
        float $amount,
        string $currency,
        int $count,
        ?string $label = null,
        ?string $expiresAt = null
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException("Amount must be positive");
        }
        if ($count < 1 || $count > 10000) {
            throw new RuntimeException("Count must be 1-10000");
        }

        $batchId = bin2hex(random_bytes(8));
        $codes = [];

        $stmt = $this->pdo->prepare("
            INSERT INTO voucher_codes 
                (code, batch_id, amount, currency, label, status, expires_at, created_at)
            VALUES 
                (:code, :batch, :amount, :currency, :label, 'active', :expires, NOW(6))
        ");

        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateCode();

            $stmt->execute([
                'code' => $code,
                'batch' => $batchId,
                'amount' => $amount,
                'currency' => $currency,
                'label' => $label,
                'expires' => $expiresAt,
            ]);

            $codes[] = [
                'code' => $this->formatCode($code),
                'voucher' => $this->formatVoucherString($code),
                'amount' => $amount,
                'currency' => $currency,
            ];
        }

        Logger::info("Generated {$count} vouchers, batch {$batchId}, {$amount} {$currency} each");

        return [
            'batch_id' => $batchId,
            'count' => $count,
            'amount' => $amount,
            'currency' => $currency,
            'label' => $label,
            'codes' => $codes,
        ];
    }

    /**
     * Verify and redeem a voucher code (issuer side).
     * Called when a redeemer's node hits the issuer's redeem endpoint.
     *
     * @param string $code The voucher code
     * @param string $redeemerAddress The redeemer's node address
     * @return array Redemption result
     */
    public function redeemAsIssuer(string $code, string $redeemerAddress): array
    {
        $code = $this->normalizeCode($code);

        $this->pdo->beginTransaction();
        try {
            // Lock the row
            $stmt = $this->pdo->prepare("
                SELECT * FROM voucher_codes 
                WHERE code = :code 
                FOR UPDATE
            ");
            $stmt->execute(['code' => $code]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$voucher) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Invalid voucher code'];
            }

            if ($voucher['status'] !== 'active') {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'Voucher already ' . $voucher['status']];
            }

            if ($voucher['expires_at'] && strtotime($voucher['expires_at']) < time()) {
                // Mark expired
                $this->pdo->prepare("UPDATE voucher_codes SET status = 'expired' WHERE code = :code")
                    ->execute(['code' => $code]);
                $this->pdo->commit();
                return ['success' => false, 'error' => 'Voucher expired'];
            }

            // Mark as redeemed
            $stmt2 = $this->pdo->prepare("
                UPDATE voucher_codes 
                SET status = 'redeemed', 
                    redeemed_by = :redeemer, 
                    redeemed_at = NOW(6) 
                WHERE code = :code
            ");
            $stmt2->execute([
                'redeemer' => $redeemerAddress,
                'code' => $code,
            ]);

            $this->pdo->commit();

            Logger::info("Voucher redeemed: {$code} by {$redeemerAddress} for {$voucher['amount']} {$voucher['currency']}");

            return [
                'success' => true,
                'amount' => (float)$voucher['amount'],
                'currency' => $voucher['currency'],
                'issuer_address' => $this->user->getHttpAddress() ?? $this->user->getTorAddress(),
                'issuer_name' => $this->user->getName(),
                'issuer_pubkey' => $this->user->getPublicKey(),
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ========================================================================
    // REDEEMER SIDE: Scan code, auto-trust, receive balance
    // ========================================================================

    /**
     * Parse a full voucher string: CODE@ISSUER_ADDRESS
     * e.g. EIOU-A3B7-K9M2-X4P1@573olmx5fax...onion
     *      EIOU-A3B7-K9M2-X4P1@http://88.99.69.172:3002
     *      EIOU-A3B7-K9M2-X4P1@issuer.eiou.org
     *
     * @param string $voucherString The full voucher string
     * @return array ['code' => ..., 'issuer_address' => ...]
     */
    public static function parseVoucherString(string $voucherString): array
    {
        $voucherString = trim($voucherString);

        // Split on @ — but be careful, issuer address might contain @
        // Format is always CODE@ADDRESS where CODE has no @
        $atPos = strpos($voucherString, '@');
        if ($atPos === false) {
            return ['code' => $voucherString, 'issuer_address' => null];
        }

        $code = substr($voucherString, 0, $atPos);
        $address = substr($voucherString, $atPos + 1);

        // If address is a bare .onion, make it a proper URL
        if (str_ends_with($address, '.onion') && !str_contains($address, '://')) {
            $address = 'http://' . $address;
        }

        // If address is a bare domain/IP without scheme, add https://
        if (!str_contains($address, '://') && !str_starts_with($address, '//')) {
            $address = 'https://' . $address;
        }

        return ['code' => $code, 'issuer_address' => $address];
    }

    /**
     * Redeem a voucher code (wallet/redeemer side).
     * This is the one-step onboarding flow:
     *   1. Validate the code with the issuer
     *   2. Auto-add issuer as trusted contact
     *   3. Set credit line = voucher amount
     *   4. Issuer sends the eIOU
     *
     * Accepts either:
     *   - Full string: "EIOU-A3B7-K9M2-X4P1@issuer.onion" (code + issuer in one)
     *   - Separate: code + issuerAddress params
     *
     * @param string $code Voucher code or full voucher string (CODE@ADDRESS)
     * @param string|null $issuerAddress Issuer address (optional if embedded in code)
     * @return array Result with balance info
     */
    public function redeemAsWallet(string $code, ?string $issuerAddress = null): array
    {
        // Parse CODE@ADDRESS format
        if ($issuerAddress === null || $issuerAddress === '') {
            $parsed = self::parseVoucherString($code);
            $code = $parsed['code'];
            $issuerAddress = $parsed['issuer_address'];
        }

        if (!$issuerAddress) {
            return [
                'success' => false,
                'error' => 'Issuer address required. Use format: CODE@ADDRESS or provide issuer_address separately.',
            ];
        }

        $code = $this->normalizeCode($code);

        // Step 1: Call issuer's redeem endpoint
        $redeemResult = $this->callIssuerRedeem($issuerAddress, $code);

        if (!$redeemResult || !($redeemResult['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $redeemResult['error'] ?? 'Failed to validate voucher with issuer',
            ];
        }

        $amount = $redeemResult['amount'];
        $currency = $redeemResult['currency'];
        $issuerName = $redeemResult['issuer_name'] ?? 'Voucher Issuer';

        // Step 2: Auto-add issuer as trusted contact (if not already)
        $autoTrustResult = $this->autoTrustIssuer(
            $issuerAddress,
            $issuerName,
            $amount,
            $currency
        );

        // Step 3: Record the redemption locally
        $this->recordRedemption($code, $issuerAddress, $amount, $currency);

        Logger::info("Voucher redeemed via wallet: {$code}, {$amount} {$currency} from {$issuerAddress}");

        return [
            'success' => true,
            'amount' => $amount,
            'currency' => $currency,
            'issuer' => $issuerName,
            'issuer_address' => $issuerAddress,
            'contact_status' => $autoTrustResult['status'],
            'message' => "Redeemed {$amount} {$currency} from {$issuerName}. Balance will appear after issuer confirms.",
        ];
    }

    /**
     * Auto-trust an issuer: add as contact with credit = voucher amount.
     * If contact already exists, increase credit by voucher amount.
     */
    private function autoTrustIssuer(
        string $issuerAddress,
        string $issuerName,
        float $amount,
        string $currency
    ): array {
        // Check if contact already exists
        $existing = $this->findContact($issuerAddress);

        if ($existing) {
            // Increase credit line by voucher amount
            $newCredit = (float)$existing['credit_limit'] + ($amount * $this->getConversionFactor($currency));
            $stmt = $this->pdo->prepare("
                UPDATE contact SET credit = :credit WHERE address = :address
            ");
            $stmt->execute([
                'credit' => $newCredit,
                'address' => $issuerAddress,
            ]);

            return ['status' => 'existing_contact_credit_increased', 'credit' => $newCredit];
        }

        // Add new contact with auto-trust
        // fee=0 (issuer doesn't charge routing fees on voucher redemptions)
        // credit = voucher face value
        try {
            $contactService = $this->getContactService();
            $argv = [
                'eiou', 'add',
                $issuerAddress,
                $issuerName,
                '0',                                    // fee: 0%
                (string)$amount,                        // credit: voucher amount
                $currency,
                '--json',
            ];

            ob_start();
            $contactService->addContact($argv, null);
            ob_get_clean();

            return ['status' => 'contact_added'];
        } catch (Exception $e) {
            Logger::warning("Auto-trust failed for {$issuerAddress}: " . $e->getMessage());
            return ['status' => 'auto_trust_failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * Call the issuer's /api/v1/voucher/redeem endpoint.
     */
    private function callIssuerRedeem(string $issuerAddress, string $code): ?array
    {
        $url = rtrim($issuerAddress, '/') . '/api/v1/voucher/redeem';
        $myAddress = $this->user->getHttpAddress() ?? $this->user->getTorAddress();

        $payload = json_encode([
            'code' => $code,
            'redeemer_address' => $myAddress,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        // Use Tor proxy for .onion addresses
        if (str_contains($issuerAddress, '.onion')) {
            curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:9050');
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 201) {
            Logger::warning("Issuer redeem failed: HTTP {$httpCode} from {$issuerAddress}");
            return null;
        }

        return json_decode($response, true);
    }

    private function recordRedemption(string $code, string $issuerAddress, float $amount, string $currency): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO voucher_redemptions 
                    (code, issuer_address, amount, currency, redeemed_at)
                VALUES 
                    (:code, :issuer, :amount, :currency, NOW(6))
            ");
            $stmt->execute([
                'code' => $code,
                'issuer' => $issuerAddress,
                'amount' => $amount,
                'currency' => $currency,
            ]);
        } catch (Exception $e) {
            Logger::warning("Failed to record redemption: " . $e->getMessage());
        }
    }

    // ========================================================================
    // Voucher lookup / management
    // ========================================================================

    /**
     * Get voucher status (issuer side).
     */
    public function getVoucherStatus(string $code): ?array
    {
        $code = $this->normalizeCode($code);
        $stmt = $this->pdo->prepare("SELECT * FROM voucher_codes WHERE code = :code");
        $stmt->execute(['code' => $code]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        return $voucher ?: null;
    }

    /**
     * List vouchers by batch (issuer side).
     */
    public function listBatch(string $batchId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT code, amount, currency, status, redeemed_by, redeemed_at, expires_at 
            FROM voucher_codes WHERE batch_id = :batch ORDER BY created_at
        ");
        $stmt->execute(['batch' => $batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get batch summary stats.
     */
    public function getBatchSummary(string $batchId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'redeemed' THEN 1 ELSE 0 END) as redeemed,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) as revoked,
                MIN(amount) as amount,
                MIN(currency) as currency,
                MIN(label) as label
            FROM voucher_codes WHERE batch_id = :batch
        ");
        $stmt->execute(['batch' => $batchId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Revoke an active voucher (issuer side).
     */
    public function revoke(string $code): bool
    {
        $code = $this->normalizeCode($code);
        $stmt = $this->pdo->prepare("
            UPDATE voucher_codes SET status = 'revoked' WHERE code = :code AND status = 'active'
        ");
        $stmt->execute(['code' => $code]);
        return $stmt->rowCount() > 0;
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function generateCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // No 0/O/1/I confusion
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    /**
     * Format code for display: EIOU-XXXX-XXXX-XXXX-XXXX
     */
    private function formatCode(string $code): string
    {
        return self::CODE_PREFIX . '-' . implode('-', str_split($code, 4));
    }

    /**
     * Format full voucher string: EIOU-XXXX-XXXX-XXXX-XXXX@issuer.address
     * This is what gets printed on cards / encoded in QR codes.
     */
    public function formatVoucherString(string $code): string
    {
        $formatted = $this->formatCode($code);
        $address = $this->user->getTorAddress()
            ?? $this->user->getHttpsAddress()
            ?? $this->user->getHttpAddress();

        if ($address) {
            // Strip protocol for cleaner display (user's wallet will re-add it)
            $clean = preg_replace('#^https?://#', '', $address);
            return $formatted . '@' . $clean;
        }

        return $formatted;
    }

    /**
     * Normalize code: strip prefix, dashes, spaces, uppercase
     */
    private function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = str_replace(['-', ' ', self::CODE_PREFIX], '', $code);
        return $code;
    }

    private function findContact(string $address): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM contact WHERE address = :addr LIMIT 1");
        $stmt->execute(['addr' => $address]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    private function getConversionFactor(string $currency): int
    {
        try {
            return Constants::getConversionFactor($currency);
        } catch (Exception $e) {
            return 100; // Default to cents
        }
    }

    private function getContactService(): ContactManagementService
    {
        $app = \Eiou\Core\Application::getInstance();
        return $app->getServiceContainer()->getContactService();
    }
}
