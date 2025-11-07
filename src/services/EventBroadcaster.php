<?php
/**
 * Event Broadcaster Service
 *
 * Copyright 2025
 * Centralized event broadcasting service for real-time updates.
 * Uses file-based message queue (lightweight, no Redis required).
 *
 * Features:
 * - Event broadcasting to all connected SSE clients
 * - Event deduplication
 * - Rate limiting
 * - File-based message queue (privacy-focused, no external services)
 *
 * @package Services
 */

class EventBroadcaster
{
    /**
     * @var string Event queue directory
     */
    private string $queueDir;

    /**
     * @var string Event state file
     */
    private string $stateFile;

    /**
     * @var int Maximum events to keep in queue
     */
    private const MAX_QUEUE_SIZE = 100;

    /**
     * @var int Event TTL in seconds (1 hour)
     */
    private const EVENT_TTL = 3600;

    /**
     * @var array Rate limiting configuration (events per minute per type)
     */
    private const RATE_LIMITS = [
        'balance_update' => 10,
        'transaction_new' => 20,
        'transaction_update' => 20,
        'status_change' => 5,
        'contact_update' => 10
    ];

    /**
     * Constructor
     *
     * @param string $homeDirectory User home directory
     */
    public function __construct(string $homeDirectory)
    {
        $this->queueDir = $homeDirectory . '/.eiou/event-queue';
        $this->stateFile = $homeDirectory . '/.eiou/event-broadcaster-state.json';

        // Create queue directory if it doesn't exist
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0700, true);
        }
    }

    /**
     * Broadcast an event to all connected clients
     *
     * @param string $eventType Event type
     * @param array $data Event data
     * @return bool True if event was broadcast, false if rate limited or duplicate
     */
    public function broadcast(string $eventType, array $data): bool
    {
        // Check rate limiting
        if (!$this->checkRateLimit($eventType)) {
            error_log("EventBroadcaster: Rate limit exceeded for event type: $eventType");
            return false;
        }

        // Check for duplicate events
        if ($this->isDuplicate($eventType, $data)) {
            error_log("EventBroadcaster: Duplicate event detected: $eventType");
            return false;
        }

        // Create event
        $event = [
            'id' => uniqid('event_', true),
            'type' => $eventType,
            'data' => $data,
            'timestamp' => time(),
            'delivered' => false
        ];

        // Save event to queue
        $eventFile = $this->queueDir . '/' . $event['id'] . '.json';
        file_put_contents($eventFile, json_encode($event));

        // Update state
        $this->updateState($eventType, $event);

        // Clean up old events
        $this->cleanupOldEvents();

        return true;
    }

    /**
     * Broadcast balance update event
     *
     * @param float $oldBalance Previous balance
     * @param float $newBalance New balance
     * @return bool
     */
    public function broadcastBalanceUpdate(float $oldBalance, float $newBalance): bool
    {
        return $this->broadcast('balance_update', [
            'old_balance' => $oldBalance,
            'new_balance' => $newBalance,
            'change' => $newBalance - $oldBalance,
            'timestamp' => time()
        ]);
    }

    /**
     * Broadcast new transaction event
     *
     * @param array $transaction Transaction data
     * @return bool
     */
    public function broadcastNewTransaction(array $transaction): bool
    {
        return $this->broadcast('transaction_new', [
            'transaction_id' => $transaction['id'] ?? null,
            'type' => $transaction['type'] ?? 'unknown',
            'amount' => $transaction['amount'] ?? 0,
            'timestamp' => time()
        ]);
    }

    /**
     * Broadcast transaction update event
     *
     * @param string $transactionId Transaction ID
     * @param string $status New status
     * @return bool
     */
    public function broadcastTransactionUpdate(string $transactionId, string $status): bool
    {
        return $this->broadcast('transaction_update', [
            'transaction_id' => $transactionId,
            'status' => $status,
            'timestamp' => time()
        ]);
    }

    /**
     * Broadcast status change event
     *
     * @param string $oldStatus Previous status
     * @param string $newStatus New status
     * @return bool
     */
    public function broadcastStatusChange(string $oldStatus, string $newStatus): bool
    {
        return $this->broadcast('status_change', [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'timestamp' => time()
        ]);
    }

    /**
     * Broadcast contact update event
     *
     * @param array $contact Contact data
     * @return bool
     */
    public function broadcastContactUpdate(array $contact): bool
    {
        return $this->broadcast('contact_update', [
            'contact_id' => $contact['id'] ?? null,
            'name' => $contact['name'] ?? 'Unknown',
            'status' => $contact['status'] ?? 'unknown',
            'timestamp' => time()
        ]);
    }

    /**
     * Get all pending events (for SSE clients)
     *
     * @return array Array of pending events
     */
    public function getPendingEvents(): array
    {
        $events = [];
        $files = glob($this->queueDir . '/*.json');

        foreach ($files as $file) {
            $event = json_decode(file_get_contents($file), true);
            if ($event && !$event['delivered']) {
                $events[] = $event;
            }
        }

        // Sort by timestamp
        usort($events, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $events;
    }

    /**
     * Mark event as delivered
     *
     * @param string $eventId Event ID
     */
    public function markAsDelivered(string $eventId): void
    {
        $eventFile = $this->queueDir . '/' . $eventId . '.json';

        if (file_exists($eventFile)) {
            $event = json_decode(file_get_contents($eventFile), true);
            $event['delivered'] = true;
            file_put_contents($eventFile, json_encode($event));
        }
    }

    /**
     * Check rate limiting for event type
     *
     * @param string $eventType Event type
     * @return bool True if within rate limit
     */
    private function checkRateLimit(string $eventType): bool
    {
        $state = $this->getState();
        $now = time();
        $limit = self::RATE_LIMITS[$eventType] ?? 10;

        // Initialize rate limit tracking for this event type
        if (!isset($state['rate_limits'][$eventType])) {
            $state['rate_limits'][$eventType] = [
                'count' => 0,
                'window_start' => $now
            ];
        }

        $rateLimit = $state['rate_limits'][$eventType];

        // Reset window if more than 1 minute has passed
        if ($now - $rateLimit['window_start'] >= 60) {
            $state['rate_limits'][$eventType] = [
                'count' => 1,
                'window_start' => $now
            ];
            $this->saveState($state);
            return true;
        }

        // Check if within limit
        if ($rateLimit['count'] >= $limit) {
            return false;
        }

        // Increment counter
        $state['rate_limits'][$eventType]['count']++;
        $this->saveState($state);
        return true;
    }

    /**
     * Check if event is duplicate
     *
     * @param string $eventType Event type
     * @param array $data Event data
     * @return bool True if duplicate
     */
    private function isDuplicate(string $eventType, array $data): bool
    {
        $state = $this->getState();
        $hash = $this->calculateEventHash($eventType, $data);

        if (!isset($state['recent_events'])) {
            $state['recent_events'] = [];
        }

        // Check if this hash exists in recent events (within last 60 seconds)
        $now = time();
        foreach ($state['recent_events'] as $idx => $recentEvent) {
            // Clean up old events
            if ($now - $recentEvent['timestamp'] > 60) {
                unset($state['recent_events'][$idx]);
                continue;
            }

            // Check for duplicate
            if ($recentEvent['hash'] === $hash) {
                return true;
            }
        }

        // Not a duplicate, add to recent events
        $state['recent_events'][] = [
            'hash' => $hash,
            'timestamp' => $now
        ];

        // Keep only last 100 events
        if (count($state['recent_events']) > 100) {
            $state['recent_events'] = array_slice($state['recent_events'], -100);
        }

        $this->saveState($state);
        return false;
    }

    /**
     * Calculate hash for event deduplication
     *
     * @param string $eventType Event type
     * @param array $data Event data
     * @return string Event hash
     */
    private function calculateEventHash(string $eventType, array $data): string
    {
        // Remove timestamp from hash calculation
        $hashData = $data;
        unset($hashData['timestamp']);

        return md5($eventType . json_encode($hashData));
    }

    /**
     * Update broadcaster state
     *
     * @param string $eventType Event type
     * @param array $event Event data
     */
    private function updateState(string $eventType, array $event): void
    {
        $state = $this->getState();

        if (!isset($state['last_events'])) {
            $state['last_events'] = [];
        }

        $state['last_events'][$eventType] = $event;
        $this->saveState($state);
    }

    /**
     * Get current broadcaster state
     *
     * @return array State data
     */
    private function getState(): array
    {
        if (file_exists($this->stateFile)) {
            return json_decode(file_get_contents($this->stateFile), true) ?? [];
        }
        return [];
    }

    /**
     * Save broadcaster state
     *
     * @param array $state State data
     */
    private function saveState(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state));
    }

    /**
     * Clean up old events from queue
     */
    private function cleanupOldEvents(): void
    {
        $files = glob($this->queueDir . '/*.json');
        $now = time();

        // Remove events older than TTL
        foreach ($files as $file) {
            $event = json_decode(file_get_contents($file), true);
            if ($event && ($now - $event['timestamp']) > self::EVENT_TTL) {
                unlink($file);
            }
        }

        // Remove oldest events if queue is too large
        $files = glob($this->queueDir . '/*.json');
        if (count($files) > self::MAX_QUEUE_SIZE) {
            // Sort by modification time
            usort($files, function ($a, $b) {
                return filemtime($a) <=> filemtime($b);
            });

            // Remove oldest files
            $toRemove = count($files) - self::MAX_QUEUE_SIZE;
            for ($i = 0; $i < $toRemove; $i++) {
                unlink($files[$i]);
            }
        }
    }

    /**
     * Clear all events from queue
     */
    public function clearQueue(): void
    {
        $files = glob($this->queueDir . '/*.json');
        foreach ($files as $file) {
            unlink($file);
        }
    }

    /**
     * Get queue statistics
     *
     * @return array Queue statistics
     */
    public function getStatistics(): array
    {
        $files = glob($this->queueDir . '/*.json');
        $totalEvents = count($files);
        $deliveredEvents = 0;
        $pendingEvents = 0;

        foreach ($files as $file) {
            $event = json_decode(file_get_contents($file), true);
            if ($event) {
                if ($event['delivered']) {
                    $deliveredEvents++;
                } else {
                    $pendingEvents++;
                }
            }
        }

        return [
            'total' => $totalEvents,
            'delivered' => $deliveredEvents,
            'pending' => $pendingEvents,
            'queue_size' => self::MAX_QUEUE_SIZE,
            'ttl' => self::EVENT_TTL
        ];
    }
}
