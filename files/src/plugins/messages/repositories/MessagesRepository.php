<?php
# Copyright 2025

/**
 * Messages Repository
 *
 * Data access layer for messages database operations
 *
 * @package Plugins\Messages\Repositories
 */

class MessagesRepository {
    /**
     * @var PDO Database connection
     */
    private PDO $pdo;

    /**
     * Constructor
     *
     * @param PDO $pdo Database connection
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ==================== Message Operations ====================

    /**
     * Get messages in a conversation
     *
     * @param string $conversationId Conversation ID
     * @param string $viewerPublicKey Viewer's public key (to filter deleted)
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of Message objects
     */
    public function getMessages(string $conversationId, string $viewerPublicKey, int $limit = 50, int $offset = 0): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM plugin_messages
            WHERE conversation_id = :conversation_id
            AND (
                (sender_public_key = :viewer1 AND deleted_by_sender = 0)
                OR (recipient_public_key = :viewer2 AND deleted_by_recipient = 0)
            )
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':conversation_id', $conversationId);
        $stmt->bindValue(':viewer1', $viewerPublicKey);
        $stmt->bindValue(':viewer2', $viewerPublicKey);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $messages = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messages[] = Message::fromRow($row);
        }

        return array_reverse($messages); // Return in chronological order
    }

    /**
     * Get a message by ID
     *
     * @param string $messageId Message identifier
     * @return Message|null
     */
    public function getMessageById(string $messageId): ?Message {
        $stmt = $this->pdo->prepare("SELECT * FROM plugin_messages WHERE message_id = :message_id");
        $stmt->execute([':message_id' => $messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Message::fromRow($row) : null;
    }

    /**
     * Save a message
     *
     * @param Message $message Message to save
     * @return bool Success
     */
    public function saveMessage(Message $message): bool {
        $data = $message->toArray();

        if ($this->getMessageById($message->messageId)) {
            // Update existing message
            $sets = [];
            $params = [];
            foreach ($data as $key => $value) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $params[':id'] = $message->messageId;

            $sql = "UPDATE plugin_messages SET " . implode(', ', $sets) . " WHERE message_id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } else {
            // Insert new message
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $params = [];
            foreach ($data as $key => $value) {
                $params[":{$key}"] = $value;
            }

            $sql = "INSERT INTO plugin_messages ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        }
    }

    /**
     * Mark a message as read
     *
     * @param string $messageId Message ID
     * @return bool Success
     */
    public function markAsRead(string $messageId): bool {
        $stmt = $this->pdo->prepare("UPDATE plugin_messages SET is_read = 1, read_at = NOW() WHERE message_id = :message_id AND is_read = 0");
        return $stmt->execute([':message_id' => $messageId]);
    }

    /**
     * Mark all messages in conversation as read for a user
     *
     * @param string $conversationId Conversation ID
     * @param string $recipientPublicKey Recipient's public key
     * @return int Number of messages marked
     */
    public function markConversationAsRead(string $conversationId, string $recipientPublicKey): int {
        $stmt = $this->pdo->prepare("
            UPDATE plugin_messages
            SET is_read = 1, read_at = NOW()
            WHERE conversation_id = :conversation_id
            AND recipient_public_key = :recipient
            AND is_read = 0
        ");
        $stmt->execute([':conversation_id' => $conversationId, ':recipient' => $recipientPublicKey]);
        return $stmt->rowCount();
    }

    /**
     * Delete a message (soft delete for user)
     *
     * @param string $messageId Message ID
     * @param string $userPublicKey User's public key
     * @return bool Success
     */
    public function deleteMessage(string $messageId, string $userPublicKey): bool {
        $message = $this->getMessageById($messageId);
        if (!$message) {
            return false;
        }

        if ($message->senderPublicKey === $userPublicKey) {
            $stmt = $this->pdo->prepare("UPDATE plugin_messages SET deleted_by_sender = 1 WHERE message_id = :id");
        } elseif ($message->recipientPublicKey === $userPublicKey) {
            $stmt = $this->pdo->prepare("UPDATE plugin_messages SET deleted_by_recipient = 1 WHERE message_id = :id");
        } else {
            return false;
        }

        return $stmt->execute([':id' => $messageId]);
    }

    /**
     * Get unread count for a user
     *
     * @param string $publicKey User's public key
     * @return int Unread count
     */
    public function getUnreadCount(string $publicKey): int {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM plugin_messages
            WHERE recipient_public_key = :recipient
            AND is_read = 0
            AND deleted_by_recipient = 0
        ");
        $stmt->execute([':recipient' => $publicKey]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Search messages
     *
     * @param string $userPublicKey User's public key
     * @param string $query Search query
     * @param int $limit Result limit
     * @return array Array of Message objects
     */
    public function searchMessages(string $userPublicKey, string $query, int $limit = 50): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM plugin_messages
            WHERE (sender_public_key = :user1 OR recipient_public_key = :user2)
            AND content LIKE :query
            AND (
                (sender_public_key = :user3 AND deleted_by_sender = 0)
                OR (recipient_public_key = :user4 AND deleted_by_recipient = 0)
            )
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':user1', $userPublicKey);
        $stmt->bindValue(':user2', $userPublicKey);
        $stmt->bindValue(':user3', $userPublicKey);
        $stmt->bindValue(':user4', $userPublicKey);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $messages = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $messages[] = Message::fromRow($row);
        }

        return $messages;
    }

    // ==================== Conversation Operations ====================

    /**
     * Get conversations for a user
     *
     * @param string $userPublicKey User's public key
     * @param bool $includeArchived Include archived conversations
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of Conversation objects
     */
    public function getConversations(string $userPublicKey, bool $includeArchived = false, int $limit = 50, int $offset = 0): array {
        $archiveFilter = '';
        if (!$includeArchived) {
            $archiveFilter = "AND (
                (participant1_public_key = :user3 AND archived_by_1 = 0)
                OR (participant2_public_key = :user4 AND archived_by_2 = 0)
            )";
        }

        $sql = "
            SELECT * FROM plugin_conversations
            WHERE (participant1_public_key = :user1 OR participant2_public_key = :user2)
            {$archiveFilter}
            ORDER BY last_message_at DESC NULLS LAST, created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user1', $userPublicKey);
        $stmt->bindValue(':user2', $userPublicKey);
        if (!$includeArchived) {
            $stmt->bindValue(':user3', $userPublicKey);
            $stmt->bindValue(':user4', $userPublicKey);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $conversations = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $conversations[] = Conversation::fromRow($row);
        }

        return $conversations;
    }

    /**
     * Get a conversation by ID
     *
     * @param string $conversationId Conversation identifier
     * @return Conversation|null
     */
    public function getConversationById(string $conversationId): ?Conversation {
        $stmt = $this->pdo->prepare("SELECT * FROM plugin_conversations WHERE conversation_id = :conversation_id");
        $stmt->execute([':conversation_id' => $conversationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? Conversation::fromRow($row) : null;
    }

    /**
     * Get or create a conversation between two users
     *
     * @param string $publicKey1 First user's public key
     * @param string $publicKey2 Second user's public key
     * @return Conversation
     */
    public function getOrCreateConversation(string $publicKey1, string $publicKey2): Conversation {
        $conversationId = Message::generateConversationId($publicKey1, $publicKey2);

        $existing = $this->getConversationById($conversationId);
        if ($existing) {
            return $existing;
        }

        $conversation = Conversation::create($publicKey1, $publicKey2);
        $this->saveConversation($conversation);

        return $conversation;
    }

    /**
     * Save a conversation
     *
     * @param Conversation $conversation Conversation to save
     * @return bool Success
     */
    public function saveConversation(Conversation $conversation): bool {
        $data = $conversation->toArray();

        if ($this->getConversationById($conversation->conversationId)) {
            // Update existing conversation
            $sets = [];
            $params = [];
            foreach ($data as $key => $value) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $params[':id'] = $conversation->conversationId;

            $sql = "UPDATE plugin_conversations SET " . implode(', ', $sets) . " WHERE conversation_id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } else {
            // Insert new conversation
            $columns = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $params = [];
            foreach ($data as $key => $value) {
                $params[":{$key}"] = $value;
            }

            $sql = "INSERT INTO plugin_conversations ({$columns}) VALUES ({$placeholders})";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        }
    }

    /**
     * Update conversation after new message
     *
     * @param string $conversationId Conversation ID
     * @param string $preview Message preview
     * @param string $recipientPublicKey Recipient to increment unread for
     * @return bool Success
     */
    public function updateConversationWithMessage(string $conversationId, string $preview, string $recipientPublicKey): bool {
        $conversation = $this->getConversationById($conversationId);
        if (!$conversation) {
            return false;
        }

        $unreadField = $conversation->participant1PublicKey === $recipientPublicKey
            ? 'unread_count_1'
            : 'unread_count_2';

        $stmt = $this->pdo->prepare("
            UPDATE plugin_conversations
            SET last_message_preview = :preview,
                last_message_at = NOW(),
                {$unreadField} = {$unreadField} + 1
            WHERE conversation_id = :id
        ");

        return $stmt->execute([
            ':preview' => strlen($preview) > 100 ? substr($preview, 0, 97) . '...' : $preview,
            ':id' => $conversationId
        ]);
    }

    /**
     * Reset unread count for a user in a conversation
     *
     * @param string $conversationId Conversation ID
     * @param string $userPublicKey User's public key
     * @return bool Success
     */
    public function resetUnreadCount(string $conversationId, string $userPublicKey): bool {
        $conversation = $this->getConversationById($conversationId);
        if (!$conversation) {
            return false;
        }

        $unreadField = $conversation->participant1PublicKey === $userPublicKey
            ? 'unread_count_1'
            : 'unread_count_2';

        $stmt = $this->pdo->prepare("UPDATE plugin_conversations SET {$unreadField} = 0 WHERE conversation_id = :id");
        return $stmt->execute([':id' => $conversationId]);
    }

    /**
     * Archive/unarchive a conversation for a user
     *
     * @param string $conversationId Conversation ID
     * @param string $userPublicKey User's public key
     * @param bool $archived Archive status
     * @return bool Success
     */
    public function setConversationArchived(string $conversationId, string $userPublicKey, bool $archived): bool {
        $conversation = $this->getConversationById($conversationId);
        if (!$conversation) {
            return false;
        }

        $field = $conversation->participant1PublicKey === $userPublicKey
            ? 'archived_by_1'
            : 'archived_by_2';

        $stmt = $this->pdo->prepare("UPDATE plugin_conversations SET {$field} = :archived WHERE conversation_id = :id");
        return $stmt->execute([':archived' => $archived ? 1 : 0, ':id' => $conversationId]);
    }

    /**
     * Get total unread count across all conversations for a user
     *
     * @param string $userPublicKey User's public key
     * @return int Total unread count
     */
    public function getTotalUnreadCount(string $userPublicKey): int {
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN participant1_public_key = :user1 THEN unread_count_1 ELSE 0 END), 0) +
                COALESCE(SUM(CASE WHEN participant2_public_key = :user2 THEN unread_count_2 ELSE 0 END), 0) as total
            FROM plugin_conversations
            WHERE participant1_public_key = :user3 OR participant2_public_key = :user4
        ");
        $stmt->execute([
            ':user1' => $userPublicKey,
            ':user2' => $userPublicKey,
            ':user3' => $userPublicKey,
            ':user4' => $userPublicKey
        ]);
        return (int) $stmt->fetchColumn();
    }
}
