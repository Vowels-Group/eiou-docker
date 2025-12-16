<?php
# Copyright 2025

/**
 * Conversation Model
 *
 * Represents a conversation between two users
 *
 * @package Plugins\Messages\Models
 */

class Conversation {
    /**
     * @var int|null Database ID
     */
    public ?int $id;

    /**
     * @var string Unique conversation identifier
     */
    public string $conversationId;

    /**
     * @var string First participant's public key
     */
    public string $participant1PublicKey;

    /**
     * @var string Second participant's public key
     */
    public string $participant2PublicKey;

    /**
     * @var string|null Last message preview
     */
    public ?string $lastMessagePreview;

    /**
     * @var string|null Last message timestamp
     */
    public ?string $lastMessageAt;

    /**
     * @var int Unread count for participant 1
     */
    public int $unreadCount1;

    /**
     * @var int Unread count for participant 2
     */
    public int $unreadCount2;

    /**
     * @var bool Whether participant 1 has archived
     */
    public bool $archivedBy1;

    /**
     * @var bool Whether participant 2 has archived
     */
    public bool $archivedBy2;

    /**
     * @var bool Whether participant 1 has muted
     */
    public bool $mutedBy1;

    /**
     * @var bool Whether participant 2 has muted
     */
    public bool $mutedBy2;

    /**
     * @var string Created timestamp
     */
    public string $createdAt;

    /**
     * @var string Updated timestamp
     */
    public string $updatedAt;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = null;
        $this->conversationId = '';
        $this->participant1PublicKey = '';
        $this->participant2PublicKey = '';
        $this->lastMessagePreview = null;
        $this->lastMessageAt = null;
        $this->unreadCount1 = 0;
        $this->unreadCount2 = 0;
        $this->archivedBy1 = false;
        $this->archivedBy2 = false;
        $this->mutedBy1 = false;
        $this->mutedBy2 = false;
        $this->createdAt = '';
        $this->updatedAt = '';
    }

    /**
     * Create from database row
     *
     * @param array $row Database row
     * @return Conversation
     */
    public static function fromRow(array $row): Conversation {
        $conv = new self();
        $conv->id = isset($row['id']) ? (int) $row['id'] : null;
        $conv->conversationId = $row['conversation_id'] ?? '';
        $conv->participant1PublicKey = $row['participant1_public_key'] ?? '';
        $conv->participant2PublicKey = $row['participant2_public_key'] ?? '';
        $conv->lastMessagePreview = $row['last_message_preview'] ?? null;
        $conv->lastMessageAt = $row['last_message_at'] ?? null;
        $conv->unreadCount1 = (int) ($row['unread_count_1'] ?? 0);
        $conv->unreadCount2 = (int) ($row['unread_count_2'] ?? 0);
        $conv->archivedBy1 = (bool) ($row['archived_by_1'] ?? false);
        $conv->archivedBy2 = (bool) ($row['archived_by_2'] ?? false);
        $conv->mutedBy1 = (bool) ($row['muted_by_1'] ?? false);
        $conv->mutedBy2 = (bool) ($row['muted_by_2'] ?? false);
        $conv->createdAt = $row['created_at'] ?? '';
        $conv->updatedAt = $row['updated_at'] ?? '';

        return $conv;
    }

    /**
     * Convert to array for database storage
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'conversation_id' => $this->conversationId,
            'participant1_public_key' => $this->participant1PublicKey,
            'participant2_public_key' => $this->participant2PublicKey,
            'last_message_preview' => $this->lastMessagePreview,
            'last_message_at' => $this->lastMessageAt,
            'unread_count_1' => $this->unreadCount1,
            'unread_count_2' => $this->unreadCount2,
            'archived_by_1' => $this->archivedBy1 ? 1 : 0,
            'archived_by_2' => $this->archivedBy2 ? 1 : 0,
            'muted_by_1' => $this->mutedBy1 ? 1 : 0,
            'muted_by_2' => $this->mutedBy2 ? 1 : 0
        ];
    }

    /**
     * Convert to API response for a specific user
     *
     * @param string $viewerPublicKey Public key of user viewing
     * @param string|null $otherPartyName Optional display name of other party
     * @return array
     */
    public function toApiArray(string $viewerPublicKey, ?string $otherPartyName = null): array {
        $isParticipant1 = $this->participant1PublicKey === $viewerPublicKey;
        $otherPartyKey = $isParticipant1 ? $this->participant2PublicKey : $this->participant1PublicKey;
        $unreadCount = $isParticipant1 ? $this->unreadCount1 : $this->unreadCount2;
        $isArchived = $isParticipant1 ? $this->archivedBy1 : $this->archivedBy2;
        $isMuted = $isParticipant1 ? $this->mutedBy1 : $this->mutedBy2;

        return [
            'conversation_id' => $this->conversationId,
            'other_party_public_key' => $otherPartyKey,
            'other_party_name' => $otherPartyName ?? $this->truncateKey($otherPartyKey),
            'last_message_preview' => $this->lastMessagePreview,
            'last_message_at' => $this->lastMessageAt,
            'last_message_time_ago' => $this->getTimeAgo($this->lastMessageAt),
            'unread_count' => $unreadCount,
            'is_archived' => $isArchived,
            'is_muted' => $isMuted,
            'created_at' => $this->createdAt
        ];
    }

    /**
     * Get the other participant's public key
     *
     * @param string $myPublicKey Current user's public key
     * @return string Other participant's public key
     */
    public function getOtherParticipant(string $myPublicKey): string {
        return $this->participant1PublicKey === $myPublicKey
            ? $this->participant2PublicKey
            : $this->participant1PublicKey;
    }

    /**
     * Check if a public key is a participant
     *
     * @param string $publicKey Public key to check
     * @return bool
     */
    public function isParticipant(string $publicKey): bool {
        return $this->participant1PublicKey === $publicKey
            || $this->participant2PublicKey === $publicKey;
    }

    /**
     * Get unread count for a participant
     *
     * @param string $publicKey Participant's public key
     * @return int
     */
    public function getUnreadCount(string $publicKey): int {
        return $this->participant1PublicKey === $publicKey
            ? $this->unreadCount1
            : $this->unreadCount2;
    }

    /**
     * Increment unread count for a participant
     *
     * @param string $publicKey Recipient's public key
     */
    public function incrementUnread(string $publicKey): void {
        if ($this->participant1PublicKey === $publicKey) {
            $this->unreadCount1++;
        } else {
            $this->unreadCount2++;
        }
    }

    /**
     * Reset unread count for a participant
     *
     * @param string $publicKey Participant's public key
     */
    public function resetUnread(string $publicKey): void {
        if ($this->participant1PublicKey === $publicKey) {
            $this->unreadCount1 = 0;
        } else {
            $this->unreadCount2 = 0;
        }
    }

    /**
     * Update last message info
     *
     * @param string $preview Message preview
     * @param string $timestamp Message timestamp
     */
    public function updateLastMessage(string $preview, string $timestamp): void {
        $this->lastMessagePreview = strlen($preview) > 100
            ? substr($preview, 0, 97) . '...'
            : $preview;
        $this->lastMessageAt = $timestamp;
    }

    /**
     * Create a new conversation between two users
     *
     * @param string $publicKey1 First user's public key
     * @param string $publicKey2 Second user's public key
     * @return Conversation
     */
    public static function create(string $publicKey1, string $publicKey2): Conversation {
        $conv = new self();
        $conv->conversationId = Message::generateConversationId($publicKey1, $publicKey2);

        // Sort keys for consistent ordering
        $keys = [$publicKey1, $publicKey2];
        sort($keys);
        $conv->participant1PublicKey = $keys[0];
        $conv->participant2PublicKey = $keys[1];

        return $conv;
    }

    /**
     * Get human-readable time ago
     *
     * @param string|null $timestamp Timestamp
     * @return string
     */
    private function getTimeAgo(?string $timestamp): string {
        if (empty($timestamp)) {
            return '';
        }

        $time = strtotime($timestamp);
        $diff = time() - $time;

        if ($diff < 60) {
            return 'now';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . 'm';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . 'h';
        } elseif ($diff < 604800) {
            return floor($diff / 86400) . 'd';
        } else {
            return date('M j', $time);
        }
    }

    /**
     * Truncate a public key for display
     *
     * @param string $key Public key
     * @return string Truncated key
     */
    private function truncateKey(string $key): string {
        if (strlen($key) <= 16) {
            return $key;
        }
        return substr($key, 0, 8) . '...' . substr($key, -4);
    }

    /**
     * Validate the conversation
     *
     * @return array List of validation errors
     */
    public function validate(): array {
        $errors = [];

        if (empty($this->conversationId)) {
            $errors[] = 'Conversation ID is required';
        }

        if (empty($this->participant1PublicKey)) {
            $errors[] = 'Participant 1 public key is required';
        }

        if (empty($this->participant2PublicKey)) {
            $errors[] = 'Participant 2 public key is required';
        }

        if ($this->participant1PublicKey === $this->participant2PublicKey) {
            $errors[] = 'Participants must be different';
        }

        return $errors;
    }
}
