<?php
# Copyright 2025

/**
 * Message Model
 *
 * Represents a single message between users
 *
 * @package Plugins\Messages\Models
 */

class Message {
    /**
     * @var int|null Database ID
     */
    public ?int $id;

    /**
     * @var string Unique message identifier
     */
    public string $messageId;

    /**
     * @var string Conversation ID
     */
    public string $conversationId;

    /**
     * @var string Sender's public key
     */
    public string $senderPublicKey;

    /**
     * @var string Recipient's public key
     */
    public string $recipientPublicKey;

    /**
     * @var string Message content (may be encrypted)
     */
    public string $content;

    /**
     * @var bool Whether content is encrypted
     */
    public bool $isEncrypted;

    /**
     * @var string Message type (text, image, file)
     */
    public string $messageType;

    /**
     * @var array|null Attachments metadata
     */
    public ?array $attachments;

    /**
     * @var string|null Reply to message ID
     */
    public ?string $replyToId;

    /**
     * @var bool Whether message has been read
     */
    public bool $isRead;

    /**
     * @var string|null Read timestamp
     */
    public ?string $readAt;

    /**
     * @var bool Whether message is deleted by sender
     */
    public bool $deletedBySender;

    /**
     * @var bool Whether message is deleted by recipient
     */
    public bool $deletedByRecipient;

    /**
     * @var string Created timestamp
     */
    public string $createdAt;

    /**
     * @var string Updated timestamp
     */
    public string $updatedAt;

    /**
     * Message type constants
     */
    const TYPE_TEXT = 'text';
    const TYPE_IMAGE = 'image';
    const TYPE_FILE = 'file';

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = null;
        $this->messageId = '';
        $this->conversationId = '';
        $this->senderPublicKey = '';
        $this->recipientPublicKey = '';
        $this->content = '';
        $this->isEncrypted = false;
        $this->messageType = self::TYPE_TEXT;
        $this->attachments = null;
        $this->replyToId = null;
        $this->isRead = false;
        $this->readAt = null;
        $this->deletedBySender = false;
        $this->deletedByRecipient = false;
        $this->createdAt = '';
        $this->updatedAt = '';
    }

    /**
     * Create from database row
     *
     * @param array $row Database row
     * @return Message
     */
    public static function fromRow(array $row): Message {
        $message = new self();
        $message->id = isset($row['id']) ? (int) $row['id'] : null;
        $message->messageId = $row['message_id'] ?? '';
        $message->conversationId = $row['conversation_id'] ?? '';
        $message->senderPublicKey = $row['sender_public_key'] ?? '';
        $message->recipientPublicKey = $row['recipient_public_key'] ?? '';
        $message->content = $row['content'] ?? '';
        $message->isEncrypted = (bool) ($row['is_encrypted'] ?? false);
        $message->messageType = $row['message_type'] ?? self::TYPE_TEXT;
        $message->attachments = isset($row['attachments']) ? json_decode($row['attachments'], true) : null;
        $message->replyToId = $row['reply_to_id'] ?? null;
        $message->isRead = (bool) ($row['is_read'] ?? false);
        $message->readAt = $row['read_at'] ?? null;
        $message->deletedBySender = (bool) ($row['deleted_by_sender'] ?? false);
        $message->deletedByRecipient = (bool) ($row['deleted_by_recipient'] ?? false);
        $message->createdAt = $row['created_at'] ?? '';
        $message->updatedAt = $row['updated_at'] ?? '';

        return $message;
    }

    /**
     * Convert to array for database storage
     *
     * @return array
     */
    public function toArray(): array {
        return [
            'message_id' => $this->messageId,
            'conversation_id' => $this->conversationId,
            'sender_public_key' => $this->senderPublicKey,
            'recipient_public_key' => $this->recipientPublicKey,
            'content' => $this->content,
            'is_encrypted' => $this->isEncrypted ? 1 : 0,
            'message_type' => $this->messageType,
            'attachments' => $this->attachments ? json_encode($this->attachments) : null,
            'reply_to_id' => $this->replyToId,
            'is_read' => $this->isRead ? 1 : 0,
            'read_at' => $this->readAt,
            'deleted_by_sender' => $this->deletedBySender ? 1 : 0,
            'deleted_by_recipient' => $this->deletedByRecipient ? 1 : 0
        ];
    }

    /**
     * Convert to API response
     *
     * @param string $viewerPublicKey Public key of user viewing the message
     * @return array
     */
    public function toApiArray(string $viewerPublicKey): array {
        $isSender = $this->senderPublicKey === $viewerPublicKey;

        return [
            'message_id' => $this->messageId,
            'conversation_id' => $this->conversationId,
            'sender_public_key' => $this->senderPublicKey,
            'recipient_public_key' => $this->recipientPublicKey,
            'content' => $this->content,
            'message_type' => $this->messageType,
            'attachments' => $this->attachments,
            'reply_to_id' => $this->replyToId,
            'is_read' => $this->isRead,
            'read_at' => $this->readAt,
            'is_own_message' => $isSender,
            'created_at' => $this->createdAt,
            'time_ago' => $this->getTimeAgo()
        ];
    }

    /**
     * Get human-readable time ago
     *
     * @return string
     */
    public function getTimeAgo(): string {
        if (empty($this->createdAt)) {
            return '';
        }

        $time = strtotime($this->createdAt);
        $diff = time() - $time;

        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . 'm ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . 'h ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . 'd ago';
        } else {
            return date('M j', $time);
        }
    }

    /**
     * Generate a new message ID
     *
     * @return string
     */
    public static function generateMessageId(): string {
        return 'msg_' . bin2hex(random_bytes(16));
    }

    /**
     * Generate a conversation ID from two public keys
     *
     * @param string $publicKey1 First public key
     * @param string $publicKey2 Second public key
     * @return string Deterministic conversation ID
     */
    public static function generateConversationId(string $publicKey1, string $publicKey2): string {
        // Sort keys to ensure same conversation ID regardless of order
        $keys = [$publicKey1, $publicKey2];
        sort($keys);
        return 'conv_' . substr(hash('sha256', implode(':', $keys)), 0, 32);
    }

    /**
     * Validate the message
     *
     * @return array List of validation errors
     */
    public function validate(): array {
        $errors = [];

        if (empty($this->messageId)) {
            $errors[] = 'Message ID is required';
        }

        if (empty($this->senderPublicKey)) {
            $errors[] = 'Sender public key is required';
        }

        if (empty($this->recipientPublicKey)) {
            $errors[] = 'Recipient public key is required';
        }

        if ($this->senderPublicKey === $this->recipientPublicKey) {
            $errors[] = 'Cannot send message to yourself';
        }

        if (empty($this->content) && empty($this->attachments)) {
            $errors[] = 'Message content or attachment is required';
        }

        if (strlen($this->content) > 10000) {
            $errors[] = 'Message content exceeds maximum length';
        }

        return $errors;
    }
}
