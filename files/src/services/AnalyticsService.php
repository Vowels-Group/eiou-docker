<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\Constants;
use Eiou\Core\UserContext;
use Eiou\Utils\Logger;

/**
 * Anonymous Analytics Service
 *
 * Collects and sends anonymous, aggregated usage statistics to help
 * improve eIOU. Opt-in only (default: off). The anonymous ID is a
 * one-way HMAC-SHA256 hash that cannot be reversed to the node's
 * identity. No personal data, transaction details, amounts, or
 * contact information is ever sent.
 */
class AnalyticsService
{
    /**
     * Analytics collection endpoint
     */
    private const ENDPOINT = 'https://analytics.eiou.org/v1/report';

    /**
     * HMAC salt for anonymous ID generation
     */
    private const HMAC_SALT = 'eiou-anonymous-analytics-v1';

    /**
     * Truncated ID length (128 bits = 32 hex chars)
     */
    private const ID_LENGTH = 32;

    /**
     * HTTP request timeout in seconds
     */
    private const REQUEST_TIMEOUT = 10;

    /**
     * Cache file for last submission timestamp
     */
    private const CACHE_FILE = '/etc/eiou/config/analytics-cache.json';

    /**
     * Generate the anonymous analytics ID for this node.
     *
     * Uses HMAC-SHA256 with an analytics-specific salt so the ID:
     * - Is stable (same node always produces the same ID)
     * - Cannot be reversed to the node's public key or network address
     * - Survives wallet restoration from seed phrase
     *
     * @return string 32-character hex string
     */
    public static function getAnonymousId(): string
    {
        $user = UserContext::getInstance();
        return substr(
            hash_hmac('sha256', $user->getPublicKeyHash(), self::HMAC_SALT),
            0,
            self::ID_LENGTH
        );
    }

    /**
     * Build the node_setup event payload.
     *
     * Sent once when analytics is first enabled. Just the fact that a node exists.
     *
     * @return array
     */
    public static function buildSetupPayload(): array
    {
        return [
            'event' => 'node_setup',
            'analytics_id' => self::getAnonymousId(),
            'version' => Constants::APP_VERSION,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Build the usage_heartbeat event payload.
     *
     * Aggregated transaction counts over the given period. No amounts,
     * currencies, counterparties, or identifiable information.
     *
     * @param \PDO $pdo Database connection
     * @param int $periodDays Number of days to aggregate
     * @return array
     */
    public static function buildHeartbeatPayload(\PDO $pdo, int $periodDays = 7): array
    {
        $statsRepo = new \Eiou\Database\TransactionStatisticsRepository($pdo);
        $contactRepo = new \Eiou\Database\ContactRepository($pdo);

        $typeStats = $statsRepo->getTypeStatistics();
        $dailyCounts = $statsRepo->getDailyTransactionCounts($periodDays);
        $daysActive = count(array_filter($dailyCounts, fn($d) => ($d['count'] ?? 0) > 0));

        // Index type stats by type name for easy lookup
        $byType = [];
        foreach ($typeStats as $stat) {
            $byType[$stat['type'] ?? ''] = $stat;
        }

        return [
            'event' => 'usage_heartbeat',
            'analytics_id' => self::getAnonymousId(),
            'version' => Constants::APP_VERSION,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'period_days' => $periodDays,
            'metrics' => [
                'tx_sent_count' => (int) ($byType['sent']['count'] ?? 0),
                'tx_received_count' => (int) ($byType['received']['count'] ?? 0),
                'tx_p2p_count' => (int) ($byType['relay']['count'] ?? 0),
                'contact_count' => $contactRepo->countAcceptedContacts(),
                'days_active' => $daysActive,
            ],
        ];
    }

    /**
     * Send a payload to the analytics endpoint.
     *
     * @param array $payload The event payload
     * @return bool True if the server accepted the payload
     */
    public static function send(array $payload): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::ENDPOINT,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: eiou-node/' . Constants::APP_VERSION,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $success = $httpCode >= 200 && $httpCode < 300;

        if ($success) {
            self::writeCache($payload['event']);
        } else {
            Logger::getInstance()->info('Analytics submission failed', [
                'event' => $payload['event'],
                'http_code' => $httpCode,
                'error' => $error ?: 'HTTP ' . $httpCode,
            ]);
        }

        return $success;
    }

    /**
     * Get analytics status for display (reads cache, never sends).
     *
     * @return array
     */
    public static function getStatus(): array
    {
        $user = UserContext::getInstance();
        $enabled = $user->getAnalyticsEnabled();

        $cached = self::readCache();

        return [
            'enabled' => $enabled,
            'last_submitted' => $cached['last_submitted'] ?? null,
            'last_event' => $cached['last_event'] ?? null,
        ];
    }

    /**
     * Write last submission info to cache.
     *
     * @param string $event The event type that was submitted
     */
    private static function writeCache(string $event): void
    {
        $data = [
            'last_submitted' => date('c'),
            'last_event' => $event,
        ];

        @file_put_contents(self::CACHE_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        @chmod(self::CACHE_FILE, 0640);
    }

    /**
     * Read cached submission info.
     *
     * @return array|null
     */
    private static function readCache(): ?array
    {
        if (!file_exists(self::CACHE_FILE)) {
            return null;
        }

        $raw = @file_get_contents(self::CACHE_FILE);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
