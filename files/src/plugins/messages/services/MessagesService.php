<?php
# Copyright 2025

/**
 * Messages Service
 *
 * Business logic for messaging operations
 *
 * @package Plugins\Messages\Services
 */

class MessagesService {
    /**
     * @var MessagesRepository Data repository
     */
    private MessagesRepository $repository;

    /**
     * @var SecureLogger|null Logger
     */
    private ?SecureLogger $logger;

    /**
     * @var array Plugin configuration
     */
    private array $config;

    /**
     * @var UserContext|null Current user context
     */
    private ?UserContext $userContext;

    /**
     * Constructor
     *
     * @param MessagesRepository $repository Data repository
     * @param SecureLogger|null $logger Logger
     * @param array $config Plugin configuration
     */
    public function __construct(
        MessagesRepository $repository,
        ?SecureLogger $logger = null,
        array $config = []
    ) {
        $this->repository = $repository;
        $this->logger = $logger;
        $this->config = $config;
        $this->userContext = null;
    }

    /**
     * Set user context
     *
     * @param UserContext $userContext User context
     */
    public function setUserContext(UserContext $userContext): void {
        $this->userContext = $userContext;
    }

    // ==================== Message Operations ====================

    /**
     * Send a message
     *
     * @param string $recipientPublicKey Recipient's public key
     * @param string $content Message content
     * @param string $messageType Message type (text, image, file)
     * @param array|null $attachments Attachments metadata
     * @param string|null $replyToId Reply to message ID
     * @return array Result with message data
     * @throws Exception If sending fails
     */
    public function sendMessage(
        string $recipientPublicKey,
        string $content,
        string $messageType = Message::TYPE_TEXT,
        ?array $attachments = null,
        ?string $replyToId = null
    ): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $senderPublicKey = $this->userContext->getPublicKey();

        if ($senderPublicKey === $recipientPublicKey) {
            throw new Exception('Cannot send message to yourself');
        }

        // Validate content length
        $maxLength = $this->getSetting('max_message_length', 10000);
        if (strlen($content) > $maxLength) {
            throw new Exception("Message exceeds maximum length of {$maxLength} characters");
        }

        // Get or create conversation
        $conversation = $this->repository->getOrCreateConversation($senderPublicKey, $recipientPublicKey);

        // Create message
        $message = new Message();
        $message->messageId = Message::generateMessageId();
        $message->conversationId = $conversation->conversationId;
        $message->senderPublicKey = $senderPublicKey;
        $message->recipientPublicKey = $recipientPublicKey;
        $message->content = $content;
        $message->messageType = $messageType;
        $message->attachments = $attachments;
        $message->replyToId = $replyToId;

        // Encrypt if enabled
        if ($this->getSetting('encryption_enabled', true)) {
            $message->content = $this->encryptContent($content, $recipientPublicKey);
            $message->isEncrypted = true;
        }

        // Validate
        $errors = $message->validate();
        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode(', ', $errors));
        }

        // Save message
        $this->repository->saveMessage($message);

        // Update conversation
        $preview = $messageType === Message::TYPE_TEXT ? $content : "[{$messageType}]";
        $this->repository->updateConversationWithMessage(
            $conversation->conversationId,
            $preview,
            $recipientPublicKey
        );

        $this->log('info', 'Message sent', [
            'message_id' => $message->messageId,
            'conversation_id' => $conversation->conversationId
        ]);

        // Return decrypted content for response
        $message->content = $content;

        return [
            'message' => $message->toApiArray($senderPublicKey),
            'conversation_id' => $conversation->conversationId
        ];
    }

    /**
     * Get messages in a conversation
     *
     * @param string $conversationId Conversation ID
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     * @throws Exception If access denied
     */
    public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $userPublicKey = $this->userContext->getPublicKey();

        // Verify user is participant
        $conversation = $this->repository->getConversationById($conversationId);
        if (!$conversation || !$conversation->isParticipant($userPublicKey)) {
            throw new Exception('Conversation not found');
        }

        $messages = $this->repository->getMessages($conversationId, $userPublicKey, $limit, $offset);

        // Decrypt messages and convert to API format
        $result = [];
        foreach ($messages as $message) {
            if ($message->isEncrypted) {
                $message->content = $this->decryptContent($message->content, $userPublicKey);
            }
            $result[] = $message->toApiArray($userPublicKey);
        }

        // Mark as read
        $this->repository->markConversationAsRead($conversationId, $userPublicKey);
        $this->repository->resetUnreadCount($conversationId, $userPublicKey);

        return [
            'messages' => $result,
            'conversation' => $conversation->toApiArray($userPublicKey),
            'has_more' => count($messages) === $limit
        ];
    }

    /**
     * Mark a message as read
     *
     * @param string $messageId Message ID
     * @return array Result
     * @throws Exception If marking fails
     */
    public function markAsRead(string $messageId): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $message = $this->repository->getMessageById($messageId);
        if (!$message) {
            throw new Exception('Message not found');
        }

        // Only recipient can mark as read
        if ($message->recipientPublicKey !== $this->userContext->getPublicKey()) {
            throw new Exception('Cannot mark this message as read');
        }

        $this->repository->markAsRead($messageId);

        return ['success' => true, 'message_id' => $messageId];
    }

    /**
     * Delete a message
     *
     * @param string $messageId Message ID
     * @return array Result
     * @throws Exception If deletion fails
     */
    public function deleteMessage(string $messageId): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $userPublicKey = $this->userContext->getPublicKey();
        $message = $this->repository->getMessageById($messageId);

        if (!$message) {
            throw new Exception('Message not found');
        }

        if ($message->senderPublicKey !== $userPublicKey && $message->recipientPublicKey !== $userPublicKey) {
            throw new Exception('Access denied');
        }

        $this->repository->deleteMessage($messageId, $userPublicKey);

        $this->log('info', 'Message deleted', ['message_id' => $messageId]);

        return ['success' => true, 'message_id' => $messageId];
    }

    /**
     * Search messages
     *
     * @param string $query Search query
     * @param int $limit Result limit
     * @return array
     */
    public function searchMessages(string $query, int $limit = 50): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $userPublicKey = $this->userContext->getPublicKey();
        $messages = $this->repository->searchMessages($userPublicKey, $query, $limit);

        $result = [];
        foreach ($messages as $message) {
            if ($message->isEncrypted) {
                $message->content = $this->decryptContent($message->content, $userPublicKey);
            }
            $result[] = $message->toApiArray($userPublicKey);
        }

        return ['messages' => $result, 'query' => $query, 'count' => count($result)];
    }

    // ==================== Conversation Operations ====================

    /**
     * List conversations
     *
     * @param bool $includeArchived Include archived conversations
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     */
    public function listConversations(bool $includeArchived = false, int $limit = 50, int $offset = 0): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $userPublicKey = $this->userContext->getPublicKey();
        $conversations = $this->repository->getConversations($userPublicKey, $includeArchived, $limit, $offset);

        $result = [];
        foreach ($conversations as $conv) {
            $result[] = $conv->toApiArray($userPublicKey);
        }

        return [
            'conversations' => $result,
            'total_unread' => $this->repository->getTotalUnreadCount($userPublicKey)
        ];
    }

    /**
     * Get a conversation
     *
     * @param string $conversationId Conversation ID
     * @return array
     * @throws Exception If not found or access denied
     */
    public function getConversation(string $conversationId): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $userPublicKey = $this->userContext->getPublicKey();
        $conversation = $this->repository->getConversationById($conversationId);

        if (!$conversation || !$conversation->isParticipant($userPublicKey)) {
            throw new Exception('Conversation not found');
        }

        return ['conversation' => $conversation->toApiArray($userPublicKey)];
    }

    /**
     * Create or get a conversation with a user
     *
     * @param string $otherPublicKey Other user's public key
     * @return array
     */
    public function createConversation(string $otherPublicKey): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $userPublicKey = $this->userContext->getPublicKey();

        if ($userPublicKey === $otherPublicKey) {
            throw new Exception('Cannot create conversation with yourself');
        }

        $conversation = $this->repository->getOrCreateConversation($userPublicKey, $otherPublicKey);

        return ['conversation' => $conversation->toApiArray($userPublicKey)];
    }

    /**
     * Delete a conversation (archive for user)
     *
     * @param string $conversationId Conversation ID
     * @return array
     */
    public function deleteConversation(string $conversationId): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $userPublicKey = $this->userContext->getPublicKey();
        $conversation = $this->repository->getConversationById($conversationId);

        if (!$conversation || !$conversation->isParticipant($userPublicKey)) {
            throw new Exception('Conversation not found');
        }

        $this->repository->setConversationArchived($conversationId, $userPublicKey, true);

        $this->log('info', 'Conversation archived', ['conversation_id' => $conversationId]);

        return ['success' => true, 'conversation_id' => $conversationId];
    }

    /**
     * Get unread count
     *
     * @return array
     */
    public function getUnreadCount(): array {
        if (!$this->userContext) {
            throw new Exception('Authentication required');
        }

        $count = $this->repository->getTotalUnreadCount($this->userContext->getPublicKey());

        return ['unread_count' => $count];
    }

    // ==================== Encryption ====================

    /**
     * Encrypt message content
     *
     * @param string $content Plain text content
     * @param string $recipientPublicKey Recipient's public key
     * @return string Encrypted content
     */
    private function encryptContent(string $content, string $recipientPublicKey): string {
        // Simple encryption - in production use asymmetric encryption with recipient's public key
        $key = hash('sha256', $recipientPublicKey . $this->getEncryptionSecret(), true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($content, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt message content
     *
     * @param string $encryptedContent Encrypted content
     * @param string $userPublicKey User's public key
     * @return string Decrypted content
     */
    private function decryptContent(string $encryptedContent, string $userPublicKey): string {
        try {
            $data = base64_decode($encryptedContent);
            $iv = substr($data, 0, 16);
            $encrypted = substr($data, 16);
            $key = hash('sha256', $userPublicKey . $this->getEncryptionSecret(), true);
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            return $decrypted !== false ? $decrypted : '[Unable to decrypt]';
        } catch (Exception $e) {
            return '[Unable to decrypt]';
        }
    }

    /**
     * Get encryption secret
     *
     * @return string
     */
    private function getEncryptionSecret(): string {
        return $this->config['encryption_secret'] ?? 'default-messages-secret';
    }

    // ==================== Helpers ====================

    /**
     * Get a setting value
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    private function getSetting(string $key, $default = null) {
        return $this->config['settings'][$key] ?? $default;
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Context
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->$level("[Messages] $message", $context);
        }
    }
}
