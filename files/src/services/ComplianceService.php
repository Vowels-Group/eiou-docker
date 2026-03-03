<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\ComplianceServiceInterface;
use Eiou\Core\UserContext;
use PDO;
use Exception;

/**
 * Compliance Service (Patent Claims 28-34)
 *
 * Privacy-preserving regulatory compliance. All identity data stored locally.
 * Compliance Attestation Tokens (CATs) travel with RP2P messages.
 * Jurisdictional policy engine enforces local rules.
 * Behavioral analytics detect suspicious patterns from bilateral data only.
 */
class ComplianceService implements ComplianceServiceInterface
{
    private PDO $pdo;
    private UserContext $currentUser;

    // Jurisdictional policy profiles (Claim 33)
    private array $jurisdictionProfiles = [
        'US' => [
            ['rule_type' => 'reporting_threshold', 'threshold_amount' => 10000, 'threshold_currency' => 'USD', 'action' => 'flag'],
            ['rule_type' => 'kyc_required', 'threshold_amount' => 3000, 'required_kyc_tier' => 2, 'action' => 'require_upgrade'],
            ['rule_type' => 'travel_rule', 'threshold_amount' => 3000, 'action' => 'require_travel_rule'],
        ],
        'JP' => [
            ['rule_type' => 'reporting_threshold', 'threshold_amount' => 2000000, 'threshold_currency' => 'JPY', 'action' => 'flag'],
            ['rule_type' => 'kyc_required', 'threshold_amount' => 100000, 'required_kyc_tier' => 3, 'action' => 'require_upgrade'],
        ],
        'EU' => [
            ['rule_type' => 'reporting_threshold', 'threshold_amount' => 10000, 'threshold_currency' => 'EUR', 'action' => 'flag'],
            ['rule_type' => 'travel_rule', 'threshold_amount' => 1000, 'action' => 'require_travel_rule'],
        ],
        'SG' => [
            ['rule_type' => 'reporting_threshold', 'threshold_amount' => 20000, 'threshold_currency' => 'SGD', 'action' => 'flag'],
        ],
    ];

    public function __construct(PDO $pdo, UserContext $currentUser)
    {
        $this->pdo = $pdo;
        $this->currentUser = $currentUser;
    }

    /**
     * Get my identity tier
     */
    public function getMyTier(): int
    {
        try {
            $stmt = $this->pdo->prepare("SELECT MAX(tier) as max_tier FROM my_identity");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? (int)$result['max_tier'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Get contact's identity tier
     */
    public function getContactTier(string $contactPubkeyHash): int
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT MAX(tier) as max_tier 
                FROM identity_verifications 
                WHERE contact_pubkey_hash = :pubkey AND is_active = 1
            ");
            $stmt->execute(['pubkey' => $contactPubkeyHash]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result && $result['max_tier'] !== null ? (int)$result['max_tier'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Generate Compliance Attestation Token (Claim 28)
     * Contains compliance claims, NOT raw identity data.
     */
    public function generateCAT(): array
    {
        $tier = $this->getMyTier();
        $jurisdiction = $this->currentUser->getJurisdiction() ?? 'US';

        $catData = [
            'issuer_pubkey' => $this->currentUser->getPublicKey(),
            'kyc_tier' => $tier,
            'jurisdiction' => $jurisdiction,
            'sanctions_screened' => $this->isSanctionsScreened(),
            'sanctions_date' => date('Y-m-d'),
            'licensed' => false,
            'timestamp' => microtime(true),
        ];

        // Sign the CAT
        $dataToSign = json_encode($catData);
        $signature = $this->sign($dataToSign);
        $catData['signature'] = $signature;

        return $catData;
    }

    /**
     * Verify a CAT signature
     */
    public function verifyCAT(array $cat): bool
    {
        $signature = $cat['signature'] ?? null;
        if (!$signature) return false;

        $catCopy = $cat;
        unset($catCopy['signature']);
        $dataToVerify = json_encode($catCopy);

        // TODO: Verify signature using issuer's public key
        // For now, basic structure validation
        return isset($cat['issuer_pubkey']) 
            && isset($cat['kyc_tier']) 
            && isset($cat['jurisdiction'])
            && isset($cat['timestamp']);
    }

    /**
     * Evaluate transaction against jurisdictional policy (Claim 29)
     */
    public function evaluateTransaction(array $transaction): array
    {
        $jurisdiction = $this->currentUser->getJurisdiction() ?? 'US';
        $amount = (float)($transaction['amount'] ?? 0);
        $currency = $transaction['currency'] ?? 'USD';
        $contactPubkey = $transaction['contact_pubkey'] ?? null;

        // Load policies from DB first, fall back to built-in profiles
        $policies = $this->getActivePolicies($jurisdiction);
        if (empty($policies) && isset($this->jurisdictionProfiles[$jurisdiction])) {
            $policies = $this->jurisdictionProfiles[$jurisdiction];
        }

        foreach ($policies as $policy) {
            $threshold = (float)($policy['threshold_amount'] ?? 0);
            $policyCurrency = $policy['threshold_currency'] ?? $currency;

            // Only match same currency (or no currency specified)
            if ($policyCurrency !== $currency && !empty($policyCurrency)) {
                continue;
            }

            if ($amount >= $threshold) {
                $action = $policy['action'];

                switch ($action) {
                    case 'flag':
                        $this->flagTransaction($transaction, $policy);
                        return ['allowed' => true, 'action' => 'flagged', 'rule' => $policy['rule_type']];

                    case 'require_upgrade':
                        $contactTier = $contactPubkey ? $this->getContactTier($contactPubkey) : 0;
                        $requiredTier = (int)($policy['required_kyc_tier'] ?? 2);
                        if ($contactTier < $requiredTier) {
                            return ['allowed' => false, 'action' => 'require_upgrade', 'rule' => $policy['rule_type'],
                                    'current_tier' => $contactTier, 'required_tier' => $requiredTier];
                        }
                        break;

                    case 'require_travel_rule':
                        return ['allowed' => true, 'action' => 'require_travel_rule', 'rule' => $policy['rule_type']];

                    case 'block':
                        return ['allowed' => false, 'action' => 'blocked', 'rule' => $policy['rule_type']];
                }
            }
        }

        return ['allowed' => true, 'action' => 'none', 'rule' => null];
    }

    /**
     * Check if route CAT chain meets requirements (Claim 28)
     */
    public function routeMeetsCompliance(array $catChain, array $requirements): bool
    {
        $minTier = $requirements['min_kyc_tier'] ?? 0;
        $sanctionsRequired = $requirements['sanctions_required'] ?? false;

        foreach ($catChain as $cat) {
            if (!$this->verifyCAT($cat)) return false;
            if (($cat['kyc_tier'] ?? 0) < $minTier) return false;
            if ($sanctionsRequired && !($cat['sanctions_screened'] ?? false)) return false;
        }

        return true;
    }

    /**
     * Behavioral analytics (Claims 30, 34)
     * Detect suspicious patterns from local bilateral data only.
     */
    public function analyzeContact(string $contactPubkeyHash): array
    {
        $structuringScore = $this->detectStructuring($contactPubkeyHash);
        $cyclingScore = $this->detectCycling($contactPubkeyHash);
        $velocityScore = $this->detectVelocityAnomaly($contactPubkeyHash);

        $flagged = $structuringScore > 3.0 || $cyclingScore > 5.0 || $velocityScore > 3.0;

        if ($flagged) {
            $this->createSAR($contactPubkeyHash, [
                'structuring' => $structuringScore,
                'cycling' => $cyclingScore,
                'velocity' => $velocityScore,
            ]);
        }

        return [
            'structuring' => round($structuringScore, 4),
            'cycling' => round($cyclingScore, 4),
            'velocity' => round($velocityScore, 4),
            'flagged' => $flagged,
        ];
    }

    /**
     * Structuring detection (Claim 34)
     * Score = (sum/threshold) × (1/variance) × (count/expected)
     */
    private function detectStructuring(string $contactPubkeyHash, int $windowDays = 30): float
    {
        $stmt = $this->pdo->prepare("
            SELECT amount FROM transactions 
            WHERE pubkey_hash = :pubkey 
            AND created_at > DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->execute(['pubkey' => $contactPubkeyHash, 'days' => $windowDays]);
        $amounts = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($amounts) < 3) return 0.0;

        $jurisdiction = $this->currentUser->getJurisdiction() ?? 'US';
        $threshold = $this->getReportingThreshold($jurisdiction);
        if ($threshold <= 0) return 0.0;

        $sum = array_sum($amounts);
        $count = count($amounts);
        $mean = $sum / $count;
        $variance = 0;
        foreach ($amounts as $a) {
            $variance += ($a - $mean) ** 2;
        }
        $variance = $variance / $count;
        if ($variance < 1) $variance = 1;

        $expectedCount = max(1, $windowDays / 7); // ~1 tx per week expected

        return ($sum / $threshold) * (1 / $variance) * ($count / $expectedCount);
    }

    /**
     * Cycling detection (Claim 34)
     * Score = gross_volume / (|net_flow| + epsilon)
     */
    private function detectCycling(string $contactPubkeyHash, int $windowDays = 30): float
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                SUM(CASE WHEN is_sender = 1 THEN amount ELSE 0 END) as total_sent,
                SUM(CASE WHEN is_sender = 0 THEN amount ELSE 0 END) as total_received
            FROM transactions 
            WHERE pubkey_hash = :pubkey 
            AND created_at > DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->execute(['pubkey' => $contactPubkeyHash, 'days' => $windowDays]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) return 0.0;

        $sent = (float)($result['total_sent'] ?? 0);
        $received = (float)($result['total_received'] ?? 0);
        $grossVolume = $sent + $received;
        $netFlow = abs($sent - $received);

        if ($grossVolume == 0) return 0.0;

        return $grossVolume / ($netFlow + 0.01);
    }

    /**
     * Velocity anomaly detection
     * Compare recent activity to baseline.
     */
    private function detectVelocityAnomaly(string $contactPubkeyHash): float
    {
        // Recent 7 days vs previous 30 days
        $stmt = $this->pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM transactions WHERE pubkey_hash = :p1 
                 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) as recent_count,
                (SELECT COUNT(*) FROM transactions WHERE pubkey_hash = :p2 
                 AND created_at BETWEEN DATE_SUB(NOW(), INTERVAL 37 DAY) AND DATE_SUB(NOW(), INTERVAL 7 DAY)) as baseline_count
            FROM dual
        ");
        $stmt->execute(['p1' => $contactPubkeyHash, 'p2' => $contactPubkeyHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) return 0.0;

        $recent = (float)$result['recent_count'];
        $baselineWeekly = (float)$result['baseline_count'] / 4.0; // 30 days / ~4 weeks

        if ($baselineWeekly < 1) return $recent > 5 ? $recent : 0.0;

        return $recent / $baselineWeekly;
    }

    /**
     * Create encrypted Travel Rule payload (Claim 31)
     */
    public function createTravelRulePayload(array $originator, array $beneficiary, string $recipientPubkey): string
    {
        $payload = json_encode([
            'originator' => $originator,
            'beneficiary' => $beneficiary,
            'timestamp' => time(),
        ]);

        // Encrypt with recipient's public key — only they can decrypt
        // TODO: Use proper asymmetric encryption (RSA/ECIES)
        $key = hash('sha256', $recipientPubkey, true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return base64_encode($iv . $encrypted);
    }

    /**
     * Verify Travel Rule payload presence (intermediary check)
     * Cannot decrypt — just verifies it exists and has valid structure.
     */
    public function verifyTravelRulePresence(string $encryptedPayload): bool
    {
        if (empty($encryptedPayload)) return false;
        $decoded = base64_decode($encryptedPayload, true);
        // Must be at least IV (16 bytes) + some encrypted data
        return $decoded !== false && strlen($decoded) > 32;
    }

    // --- Private helpers ---

    private function getActivePolicies(string $jurisdiction): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM compliance_policies 
                WHERE jurisdiction = :j AND is_active = 1
            ");
            $stmt->execute(['j' => $jurisdiction]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }

    private function getReportingThreshold(string $jurisdiction): float
    {
        $thresholds = [
            'US' => 10000,
            'JP' => 2000000,
            'EU' => 10000,
            'SG' => 20000,
        ];
        return $thresholds[$jurisdiction] ?? 10000;
    }

    private function isSanctionsScreened(): bool
    {
        // TODO: Implement actual sanctions screening integration
        return false;
    }

    private function flagTransaction(array $transaction, array $policy): void
    {
        Logger::info("Transaction flagged by compliance policy: {$policy['rule_type']}");
    }

    private function createSAR(string $contactPubkeyHash, array $scores): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO suspicious_activity_reports 
                    (contact_pubkey_hash, detection_type, score, details, created_at)
                VALUES (:pubkey, 'automated', :score, :details, NOW(6))
            ");
            $stmt->execute([
                'pubkey' => $contactPubkeyHash,
                'score' => max($scores['structuring'], $scores['cycling'], $scores['velocity']),
                'details' => json_encode($scores),
            ]);
            Logger::warning("SAR created for contact {$contactPubkeyHash}: " . json_encode($scores));
        } catch (Exception $e) {
            Logger::error("Failed to create SAR: " . $e->getMessage());
        }
    }

    private function sign(string $data): string
    {
        // TODO: Use proper ECDSA signing with node's private key
        return hash_hmac('sha256', $data, $this->currentUser->getPrivateKey() ?? 'default');
    }

    /**
     * Load jurisdictional profile into database
     */
    public function loadProfile(string $jurisdiction): int
    {
        if (!isset($this->jurisdictionProfiles[$jurisdiction])) {
            throw new Exception("Unknown jurisdiction: {$jurisdiction}");
        }

        $count = 0;
        foreach ($this->jurisdictionProfiles[$jurisdiction] as $rule) {
            $stmt = $this->pdo->prepare("
                INSERT INTO compliance_policies 
                    (jurisdiction, rule_type, threshold_amount, threshold_currency, required_kyc_tier, action)
                VALUES (:j, :type, :amount, :currency, :tier, :action)
            ");
            $stmt->execute([
                'j' => $jurisdiction,
                'type' => $rule['rule_type'],
                'amount' => $rule['threshold_amount'] ?? null,
                'currency' => $rule['threshold_currency'] ?? null,
                'tier' => $rule['required_kyc_tier'] ?? null,
                'action' => $rule['action'],
            ]);
            $count++;
        }
        return $count;
    }
}
