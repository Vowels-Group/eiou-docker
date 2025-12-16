<?php
# Copyright 2025

/**
 * Messages API Controller
 *
 * REST API endpoints for messaging operations
 *
 * @package Plugins\Messages\Api
 */

class MessagesApiController {
    /**
     * @var MessagesService Messages service
     */
    private MessagesService $service;

    /**
     * @var SecureLogger|null Logger
     */
    private ?SecureLogger $logger;

    /**
     * @var string|null Authenticated key ID
     */
    private ?string $authenticatedKeyId = null;

    /**
     * Constructor
     *
     * @param MessagesService $service Messages service
     * @param SecureLogger|null $logger Logger
     */
    public function __construct(MessagesService $service, ?SecureLogger $logger = null) {
        $this->service = $service;
        $this->logger = $logger;
    }

    /**
     * Set authenticated key ID
     *
     * @param string|null $keyId API key ID
     */
    public function setAuthenticatedKey(?string $keyId): void {
        $this->authenticatedKeyId = $keyId;
    }

    /**
     * Set user context
     *
     * @param UserContext $userContext User context
     */
    public function setUserContext(UserContext $userContext): void {
        $this->service->setUserContext($userContext);
    }

    /**
     * Handle a messages API request
     *
     * @param string $method HTTP method
     * @param string|null $action Action path segment
     * @param string|null $id Resource ID
     * @param array $params Query parameters
     * @param string $body Request body
     * @return array Response data
     */
    public function handleRequest(
        string $method,
        ?string $action,
        ?string $id,
        array $params,
        string $body
    ): array {
        try {
            return match (true) {
                // Conversations
                $method === 'GET' && $action === 'conversations' && !$id => $this->listConversations($params),
                $method === 'POST' && $action === 'conversations' => $this->createConversation($body),
                $method === 'GET' && $action === 'conversations' && $id && !isset($params['messages']) => $this->getConversation($id),
                $method === 'DELETE' && $action === 'conversations' && $id => $this->deleteConversation($id),
                $method === 'GET' && $action === 'conversations' && $id => $this->getMessages($id, $params),

                // Messages
                $method === 'POST' && $action === 'send' => $this->sendMessage($body),
                $method === 'POST' && $action === 'read' && $id => $this->markAsRead($id),
                $method === 'DELETE' && $action === null && $id => $this->deleteMessage($id),

                // Other
                $method === 'GET' && $action === 'unread' => $this->getUnreadCount(),
                $method === 'GET' && $action === 'search' => $this->searchMessages($params),

                default => $this->errorResponse('Unknown action', 404, 'unknown_action')
            };
        } catch (Exception $e) {
            $this->log('error', 'Messages API error', [
                'action' => $action,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse($e->getMessage(), 400, 'messages_error');
        }
    }

    /**
     * GET /api/v1/messages/conversations
     */
    private function listConversations(array $params): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $includeArchived = filter_var($params['archived'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $limit = min(100, max(1, (int) ($params['limit'] ?? 50)));
        $offset = max(0, (int) ($params['offset'] ?? 0));

        $result = $this->service->listConversations($includeArchived, $limit, $offset);

        return $this->successResponse($result);
    }

    /**
     * POST /api/v1/messages/conversations
     */
    private function createConversation(string $body): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['public_key'])) {
            return $this->errorResponse('public_key required', 400, 'missing_public_key');
        }

        $result = $this->service->createConversation($data['public_key']);

        return $this->successResponse($result, 201);
    }

    /**
     * GET /api/v1/messages/conversations/:id
     */
    private function getConversation(string $id): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->getConversation(urldecode($id));

        return $this->successResponse($result);
    }

    /**
     * DELETE /api/v1/messages/conversations/:id
     */
    private function deleteConversation(string $id): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->deleteConversation(urldecode($id));

        return $this->successResponse($result);
    }

    /**
     * GET /api/v1/messages/conversations/:id/messages
     */
    private function getMessages(string $conversationId, array $params): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $limit = min(100, max(1, (int) ($params['limit'] ?? 50)));
        $offset = max(0, (int) ($params['offset'] ?? 0));

        $result = $this->service->getMessages(urldecode($conversationId), $limit, $offset);

        return $this->successResponse($result);
    }

    /**
     * POST /api/v1/messages/send
     */
    private function sendMessage(string $body): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['recipient']) || empty($data['content'])) {
            return $this->errorResponse('recipient and content required', 400, 'missing_fields');
        }

        $result = $this->service->sendMessage(
            $data['recipient'],
            $data['content'],
            $data['type'] ?? Message::TYPE_TEXT,
            $data['attachments'] ?? null,
            $data['reply_to'] ?? null
        );

        return $this->successResponse($result, 201);
    }

    /**
     * POST /api/v1/messages/read/:id
     */
    private function markAsRead(string $id): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->markAsRead(urldecode($id));

        return $this->successResponse($result);
    }

    /**
     * DELETE /api/v1/messages/:id
     */
    private function deleteMessage(string $id): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->deleteMessage(urldecode($id));

        return $this->successResponse($result);
    }

    /**
     * GET /api/v1/messages/unread
     */
    private function getUnreadCount(): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->getUnreadCount();

        return $this->successResponse($result);
    }

    /**
     * GET /api/v1/messages/search
     */
    private function searchMessages(array $params): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $query = $params['q'] ?? $params['query'] ?? '';
        if (empty($query)) {
            return $this->errorResponse('Search query required', 400, 'missing_query');
        }

        $limit = min(100, max(1, (int) ($params['limit'] ?? 50)));
        $result = $this->service->searchMessages($query, $limit);

        return $this->successResponse($result);
    }

    /**
     * Build success response
     */
    private function successResponse(array $data, int $statusCode = 200): array {
        return [
            'success' => true,
            'data' => $data,
            'error' => null,
            'timestamp' => date('c'),
            'status_code' => $statusCode
        ];
    }

    /**
     * Build error response
     */
    private function errorResponse(string $message, int $statusCode, string $code): array {
        return [
            'success' => false,
            'data' => null,
            'error' => ['message' => $message, 'code' => $code],
            'timestamp' => date('c'),
            'status_code' => $statusCode
        ];
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->$level("[Messages API] $message", $context);
        }
    }
}
