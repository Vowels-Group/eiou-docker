<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Utils\Logger;
use Eiou\Contracts\TrustScoreServiceInterface;
use Eiou\Database\ContactRepository;
use Eiou\Database\TransactionRepository;
use Eiou\Database\BalanceRepository;
use Eiou\Database\P2pRepository;
use Eiou\Core\UserContext;
use Eiou\Core\Constants;
use PDO;
use Exception;

/**
 * Trust Score Service (Patent Claims 35-40)
 *
 * Computes multi-dimensional trust scores using ONLY local bilateral data.
 * No global graph knowledge. Scores influence route selection and credit decisions.
 */
class TrustScoreService implements TrustScoreServiceInterface
{
    private PDO $pdo;
    private ContactRepository $contactRepository;
    private TransactionRepository $transactionRepository;
    private BalanceRepository $balanceRepository;
    private UserContext $currentUser;

    // Trust stage thresholds
    const STAGE_PROBATIONARY = 0;
    const STAGE_ESTABLISHING = 1;
    const STAGE_ESTABLISHED = 2;
    const STAGE_MATURE = 3;

    // Decay constant (~1% per day without activity)
    const DECAY_LAMBDA = 0.01;

    // Dimension weights (configurable)
    private array $weights = [
        'payment_reliability' => 0.30,
        'routing_performance' => 0.25,
        'credit_utilization' => 0.15,
        'settlement_timeliness' => 0.15,
        'compliance_standing' => 0.15,
    ];

    // Damage severity by event type
    private array $damageSeverity = [
        'default' => 0.50,
        'dispute' => 0.30,
        'suspicious_activity' => 0.20,
        'timeout' => 0.10,
    ];

    public function __construct(
        PDO $pdo,
        ContactRepository $contactRepository,
        TransactionRepository $transactionRepository,
        BalanceRepository $balanceRepository,
        UserContext $currentUser
    ) {
        $this->pdo = $pdo;
        $this->contactRepository = $contactRepository;
        $this->transactionRepository = $transactionRepository;
        $this->balanceRepository = $balanceRepository;
        $this->currentUser = $currentUser;
    }

    /**
     * Calculate composite trust score for a contact
     */
    public function calculateScore(string $contactPubkeyHash): array
    {
        $dimensions = [
            'payment_reliability' => $this->calculatePaymentReliability($contactPubkeyHash),
            'routing_performance' => $this->calculateRoutingPerformance($contactPubkeyHash),
            'credit_utilization' => $this->calculateCreditUtilization($contactPubkeyHash),
            'settlement_timeliness' => $this->calculateSettlementTimeliness($contactPubkeyHash),
            'compliance_standing' => $this->calculateComplianceStanding($contactPubkeyHash),
        ];

        $composite = 0.0;
        foreach ($dimensions as $key => $value) {
            $composite += $value * $this->weights[$key];
        }

        // Apply time-weighted decay (Claim 39)
        $daysSinceActivity = $this->getDaysSinceLastActivity($contactPubkeyHash);
        $decayFactor = exp(-self::DECAY_LAMBDA * $daysSinceActivity);
        $floor = $this->calculateTrustFloor($contactPubkeyHash);
        $composite = max($composite * $decayFactor, $floor);

        $confidence = $this->calculateConfidence($contactPubkeyHash);
        $stage = $this->evaluateStage($contactPubkeyHash);

        // Persist
        $this->saveScore($contactPubkeyHash, $dimensions, $composite, $confidence, $stage);

        return [
            'composite' => round($composite, 4),
            'confidence' => round($confidence, 4),
            'dimensions' => array_map(fn($v) => round($v, 4), $dimensions),
            'stage' => $stage,
            'decay_factor' => round($decayFactor, 4),
            'days_inactive' => $daysSinceActivity,
        ];
    }

    /**
     * Dimension 1: Payment Reliability (0-1)
     * How often do their IOUs settle successfully?
     */
    private function calculatePaymentReliability(string $contactPubkeyHash): float
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'failed' OR status = 'timeout' THEN 1 ELSE 0 END) as failed
            FROM transactions 
            WHERE pubkey_hash = :pubkey
        ");
        $stmt->execute(['pubkey' => $contactPubkeyHash]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stats || $stats['total'] == 0) {
            return 0.5; // Neutral for no data
        }

        $successRate = $stats['successful'] / $stats['total'];
        return $successRate;
    }

    /**
     * Dimension 2: Routing Performance (0-1)
     * How reliable are they as a routing intermediary?
     */
    private function calculateRoutingPerformance(string $contactPubkeyHash): float
    {
        // Check P2P forwarding success via this contact
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_routes,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_routes
            FROM p2p 
            WHERE sender_pubkey_hash = :pubkey
        ");
        $stmt->execute(['pubkey' => $contactPubkeyHash]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$stats || $stats['total_routes'] == 0) {
            return 0.5;
        }

        $routeSuccessRate = $stats['successful_routes'] / $stats['total_routes'];

        // Factor in uptime from contact status
        $contact = $this->contactRepository->findByPubkeyHash($contactPubkeyHash);
        $uptimeFactor = ($contact && $contact['online_status'] === 'online') ? 1.0 : 0.5;

        return ($routeSuccessRate * 0.7) + ($uptimeFactor * 0.3);
    }

    /**
     * Dimension 3: Credit Utilization (0-1)
     * Moderate utilization (30-70%) is healthiest
     */
    private function calculateCreditUtilization(string $contactPubkeyHash): float
    {
        $contact = $this->contactRepository->findByPubkeyHash($contactPubkeyHash);
        if (!$contact || !$contact['credit_limit'] || $contact['credit_limit'] == 0) {
            return 0.5;
        }

        $balance = $this->balanceRepository->getBalance($contactPubkeyHash);
        if (!$balance) {
            return 0.5;
        }

        $netBalance = abs($balance['received'] - $balance['sent']);
        $utilizationRatio = $netBalance / $contact['credit_limit'];
        $utilizationRatio = min($utilizationRatio, 1.0);

        // Bell curve around 0.5 — moderate utilization = healthy
        return 1.0 - abs($utilizationRatio - 0.5) * 2;
    }

    /**
     * Dimension 4: Settlement Timeliness (0-1)
     */
    private function calculateSettlementTimeliness(string $contactPubkeyHash): float
    {
        // Based on average response time for RP2P confirmations
        $stmt = $this->pdo->prepare("
            SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_response_seconds
            FROM transactions
            WHERE pubkey_hash = :pubkey AND status = 'completed'
        ");
        $stmt->execute(['pubkey' => $contactPubkeyHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || !$result['avg_response_seconds']) {
            return 0.5;
        }

        // Score: 1.0 for instant, decreasing with delay
        // 30 seconds = 0.9, 5 minutes = 0.5, 1 hour = 0.1
        $seconds = (float)$result['avg_response_seconds'];
        return max(0.1, 1.0 / (1.0 + $seconds / 300.0));
    }

    /**
     * Dimension 5: Compliance Standing (0-1)
     */
    private function calculateComplianceStanding(string $contactPubkeyHash): float
    {
        // Check identity verification tier
        $stmt = $this->pdo->prepare("
            SELECT MAX(tier) as max_tier 
            FROM identity_verifications 
            WHERE contact_pubkey_hash = :pubkey AND is_active = 1
        ");
        
        try {
            $stmt->execute(['pubkey' => $contactPubkeyHash]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $tier = $result ? (int)$result['max_tier'] : 0;
        } catch (Exception $e) {
            // Table may not exist yet
            $tier = 0;
        }

        // Check for suspicious activity flags
        $hasSAR = false;
        try {
            $stmt2 = $this->pdo->prepare("
                SELECT COUNT(*) as cnt 
                FROM suspicious_activity_reports 
                WHERE contact_pubkey_hash = :pubkey AND requires_review = 1
            ");
            $stmt2->execute(['pubkey' => $contactPubkeyHash]);
            $sarResult = $stmt2->fetch(PDO::FETCH_ASSOC);
            $hasSAR = $sarResult && $sarResult['cnt'] > 0;
        } catch (Exception $e) {
            // Table may not exist yet
        }

        $tierScore = $tier / 4.0;
        return $tierScore * ($hasSAR ? 0.5 : 1.0);
    }

    /**
     * Calculate confidence based on data volume
     */
    private function calculateConfidence(string $contactPubkeyHash): float
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as tx_count 
            FROM transactions 
            WHERE pubkey_hash = :pubkey
        ");
        $stmt->execute(['pubkey' => $contactPubkeyHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $result ? (int)$result['tx_count'] : 0;

        // Confidence scales: 0 txns = 0, 10 = 0.5, 50 = 0.9, 100+ = ~1.0
        return min(1.0, 1.0 - exp(-0.03 * $count));
    }

    /**
     * Evaluate trust stage (graduated escalation, Claim 37)
     */
    private function evaluateStage(string $contactPubkeyHash): int
    {
        $contact = $this->contactRepository->findByPubkeyHash($contactPubkeyHash);
        if (!$contact) return self::STAGE_PROBATIONARY;

        $ageDays = $this->getRelationshipAgeDays($contactPubkeyHash);
        $txCount = $this->getTransactionCount($contactPubkeyHash);
        $hasDefaults = $this->hasDefaults($contactPubkeyHash);

        if ($hasDefaults) return self::STAGE_PROBATIONARY;
        if ($ageDays >= 180 && $txCount >= 50) return self::STAGE_MATURE;
        if ($ageDays >= 90 && $txCount >= 20) return self::STAGE_ESTABLISHED;
        if ($ageDays >= 30 && $txCount >= 5) return self::STAGE_ESTABLISHING;
        return self::STAGE_PROBATIONARY;
    }

    /**
     * Get stage privileges (what each stage allows)
     */
    public function getStagePrivileges(int $stage): array
    {
        return match($stage) {
            self::STAGE_PROBATIONARY => ['max_credit_pct' => 0.10, 'can_route' => false, 'can_introduce' => false],
            self::STAGE_ESTABLISHING => ['max_credit_pct' => 0.25, 'can_route' => true, 'can_introduce' => false],
            self::STAGE_ESTABLISHED => ['max_credit_pct' => 0.75, 'can_route' => true, 'can_introduce' => true],
            self::STAGE_MATURE => ['max_credit_pct' => 1.00, 'can_route' => true, 'can_introduce' => true],
            default => ['max_credit_pct' => 0.10, 'can_route' => false, 'can_introduce' => false],
        };
    }

    /**
     * Apply trust damage from an event (Claim 39)
     */
    public function applyDamage(string $contactPubkeyHash, string $eventType): void
    {
        $severity = $this->damageSeverity[$eventType] ?? 0.10;

        // Record damage event
        $stmt = $this->pdo->prepare("
            INSERT INTO trust_damage_events (contact_pubkey_hash, event_type, severity, created_at)
            VALUES (:pubkey, :type, :severity, NOW(6))
        ");
        $stmt->execute([
            'pubkey' => $contactPubkeyHash,
            'type' => $eventType,
            'severity' => $severity,
        ]);

        // Reduce current score
        $stmt2 = $this->pdo->prepare("
            UPDATE trust_scores 
            SET composite_score = GREATEST(0, composite_score * (1 - :severity)),
                updated_at = NOW(6)
            WHERE contact_pubkey_hash = :pubkey
        ");
        $stmt2->execute([
            'severity' => $severity,
            'pubkey' => $contactPubkeyHash,
        ]);

        Logger::info("Trust damage applied: {$eventType} (-{$severity}) for {$contactPubkeyHash}");
    }

    /**
     * Trust floor for long-standing relationships (Claim 39)
     * Prevents complete trust erosion from inactivity alone.
     */
    private function calculateTrustFloor(string $contactPubkeyHash): float
    {
        if ($this->hasDefaults($contactPubkeyHash)) {
            return 0.0; // No floor during active defaults
        }

        $ageDays = $this->getRelationshipAgeDays($contactPubkeyHash);
        return min($ageDays / 365 * 0.2, 0.3); // Max floor of 0.3
    }

    /**
     * Get cached score
     */
    public function getScore(string $contactPubkeyHash): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM trust_scores WHERE contact_pubkey_hash = :pubkey");
        $stmt->execute(['pubkey' => $contactPubkeyHash]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Recalculate all contact scores
     */
    public function recalculateAll(): int
    {
        $contacts = $this->contactRepository->getAcceptedContacts();
        $count = 0;
        foreach ($contacts as $contact) {
            $this->calculateScore($contact['pubkey_hash']);
            $count++;
        }
        return $count;
    }

    /**
     * Get trust stage
     */
    public function getStage(string $contactPubkeyHash): int
    {
        return $this->evaluateStage($contactPubkeyHash);
    }

    /**
     * Network health metrics from local data (Claim 40)
     */
    public function getNetworkHealth(): array
    {
        $contacts = $this->contactRepository->getAcceptedContacts();
        $scores = [];
        $creditLimits = [];

        foreach ($contacts as $contact) {
            $score = $this->getScore($contact['pubkey_hash']);
            if ($score) {
                $scores[] = (float)$score['composite_score'];
            }
            if ($contact['credit_limit']) {
                $creditLimits[] = (float)$contact['credit_limit'];
            }
        }

        return [
            'total_contacts' => count($contacts),
            'routing_reach' => count(array_filter($scores, fn($s) => $s > 0.3)),
            'avg_trust' => count($scores) ? round(array_sum($scores) / count($scores), 4) : 0,
            'gini' => $this->calculateGini($creditLimits),
            'hhi' => $this->calculateHHI($creditLimits),
        ];
    }

    // --- Private helpers ---

    private function saveScore(string $pubkey, array $dimensions, float $composite, float $confidence, int $stage): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO trust_scores 
                (contact_pubkey_hash, payment_reliability, routing_performance, credit_utilization, 
                 settlement_timeliness, compliance_standing, composite_score, confidence, trust_stage, 
                 data_points, last_computed, updated_at)
            VALUES 
                (:pubkey, :pr, :rp, :cu, :st, :cs, :composite, :confidence, :stage, 
                 :datapoints, NOW(6), NOW(6))
            ON DUPLICATE KEY UPDATE
                payment_reliability = :pr2, routing_performance = :rp2, credit_utilization = :cu2,
                settlement_timeliness = :st2, compliance_standing = :cs2, composite_score = :composite2,
                confidence = :confidence2, trust_stage = :stage2, data_points = :datapoints2,
                last_computed = NOW(6), updated_at = NOW(6)
        ");
        $stmt->execute([
            'pubkey' => $pubkey,
            'pr' => $dimensions['payment_reliability'], 'pr2' => $dimensions['payment_reliability'],
            'rp' => $dimensions['routing_performance'], 'rp2' => $dimensions['routing_performance'],
            'cu' => $dimensions['credit_utilization'], 'cu2' => $dimensions['credit_utilization'],
            'st' => $dimensions['settlement_timeliness'], 'st2' => $dimensions['settlement_timeliness'],
            'cs' => $dimensions['compliance_standing'], 'cs2' => $dimensions['compliance_standing'],
            'composite' => $composite, 'composite2' => $composite,
            'confidence' => $confidence, 'confidence2' => $confidence,
            'stage' => $stage, 'stage2' => $stage,
            'datapoints' => $this->getTransactionCount($pubkey), 'datapoints2' => $this->getTransactionCount($pubkey),
        ]);

        // Save history
        $stmt2 = $this->pdo->prepare("
            INSERT INTO trust_score_history (contact_pubkey_hash, composite_score, computed_at)
            VALUES (:pubkey, :score, NOW(6))
        ");
        $stmt2->execute(['pubkey' => $pubkey, 'score' => $composite]);
    }

    private function getRelationshipAgeDays(string $pubkey): int
    {
        $contact = $this->contactRepository->findByPubkeyHash($pubkey);
        if (!$contact || !$contact['created_at']) return 0;
        $created = strtotime($contact['created_at']);
        return (int)((time() - $created) / 86400);
    }

    private function getTransactionCount(string $pubkey): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as cnt FROM transactions WHERE pubkey_hash = :pubkey");
        $stmt->execute(['pubkey' => $pubkey]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? (int)$r['cnt'] : 0;
    }

    private function getDaysSinceLastActivity(string $pubkey): int
    {
        $stmt = $this->pdo->prepare("
            SELECT MAX(created_at) as last_activity FROM transactions WHERE pubkey_hash = :pubkey
        ");
        $stmt->execute(['pubkey' => $pubkey]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r || !$r['last_activity']) return 365;
        return (int)((time() - strtotime($r['last_activity'])) / 86400);
    }

    private function hasDefaults(string $pubkey): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as cnt FROM trust_damage_events 
            WHERE contact_pubkey_hash = :pubkey AND event_type = 'default' AND recovered = 0
        ");
        try {
            $stmt->execute(['pubkey' => $pubkey]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r && $r['cnt'] > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    private function calculateGini(array $values): float
    {
        if (count($values) < 2) return 0;
        sort($values);
        $n = count($values);
        $sum = array_sum($values);
        if ($sum == 0) return 0;
        $numerator = 0;
        for ($i = 0; $i < $n; $i++) {
            $numerator += (2 * ($i + 1) - $n - 1) * $values[$i];
        }
        return round($numerator / ($n * $sum), 4);
    }

    private function calculateHHI(array $values): float
    {
        $total = array_sum($values);
        if ($total == 0) return 0;
        $hhi = 0;
        foreach ($values as $v) {
            $share = $v / $total;
            $hhi += $share * $share;
        }
        return round($hhi, 4);
    }
}
