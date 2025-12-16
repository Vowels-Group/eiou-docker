<?php
# Copyright 2025

/**
 * File Hosting API Controller
 *
 * REST API endpoints for file hosting operations
 *
 * Endpoints:
 * - POST   /api/v1/file-hosting/upload          - Upload a file
 * - GET    /api/v1/file-hosting/files           - List user's files
 * - GET    /api/v1/file-hosting/files/:id       - Get file metadata
 * - GET    /api/v1/file-hosting/download/:id    - Download a file
 * - DELETE /api/v1/file-hosting/files/:id       - Delete a file
 * - POST   /api/v1/file-hosting/extend/:id      - Extend storage duration
 * - GET    /api/v1/file-hosting/storage         - Get storage usage info
 * - GET    /api/v1/file-hosting/pricing         - Get current pricing
 * - POST   /api/v1/file-hosting/calculate       - Calculate storage cost
 * - GET    /api/v1/file-hosting/payments        - Get payment history
 *
 * @package Plugins\FileHosting\Api
 */

class FileHostingApiController {
    /**
     * @var FileHostingService File hosting service
     */
    private FileHostingService $service;

    /**
     * @var SecureLogger|null Logger
     */
    private ?SecureLogger $logger;

    /**
     * @var string|null Authenticated key ID
     */
    private ?string $authenticatedKeyId = null;

    /**
     * @var UserContext|null User context
     */
    private ?UserContext $userContext = null;

    /**
     * Constructor
     *
     * @param FileHostingService $service File hosting service
     * @param SecureLogger|null $logger Logger
     */
    public function __construct(FileHostingService $service, ?SecureLogger $logger = null) {
        $this->service = $service;
        $this->logger = $logger;
    }

    /**
     * Set authenticated key ID for authorization
     *
     * @param string|null $keyId API key ID
     */
    public function setAuthenticatedKey(?string $keyId): void {
        $this->authenticatedKeyId = $keyId;
    }

    /**
     * Set user context for authenticated operations
     *
     * @param UserContext $userContext User context
     */
    public function setUserContext(UserContext $userContext): void {
        $this->userContext = $userContext;
        $this->service->setUserContext($userContext);
    }

    /**
     * Handle a file hosting API request
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
                // File operations
                $method === 'POST' && $action === 'upload' => $this->uploadFile($params),
                $method === 'GET' && $action === 'files' && !$id => $this->listFiles($params),
                $method === 'GET' && $action === 'files' && $id => $this->getFile($id),
                $method === 'GET' && $action === 'download' && $id => $this->downloadFile($id, $params),
                $method === 'DELETE' && $action === 'files' && $id => $this->deleteFile($id),
                $method === 'POST' && $action === 'extend' && $id => $this->extendStorage($id, $body),

                // Storage and pricing
                $method === 'GET' && $action === 'storage' => $this->getStorageInfo(),
                $method === 'GET' && $action === 'pricing' => $this->getPricing(),
                $method === 'POST' && $action === 'calculate' => $this->calculateCost($body),

                // Payments
                $method === 'GET' && $action === 'payments' => $this->getPayments($params),

                // Statistics (admin)
                $method === 'GET' && $action === 'stats' => $this->getStatistics(),

                default => $this->errorResponse('Unknown file hosting action', 404, 'unknown_action')
            };
        } catch (Exception $e) {
            $this->log('error', 'File hosting API error', [
                'action' => $action,
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            return $this->errorResponse($e->getMessage(), 400, 'file_hosting_error');
        }
    }

    // ==================== File Operations ====================

    /**
     * POST /api/v1/file-hosting/upload
     * Upload a file
     */
    private function uploadFile(array $params): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        if (empty($_FILES['file'])) {
            return $this->errorResponse('No file provided', 400, 'no_file');
        }

        $storageDays = (int) ($params['days'] ?? 30);
        $isPublic = filter_var($params['public'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $password = $params['password'] ?? null;
        $description = $params['description'] ?? null;

        $result = $this->service->uploadFile(
            $_FILES['file'],
            $storageDays,
            $isPublic,
            $password,
            $description
        );

        return $this->successResponse($result, 201);
    }

    /**
     * GET /api/v1/file-hosting/files
     * List user's files
     */
    private function listFiles(array $params): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $includeExpired = filter_var($params['expired'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));

        $result = $this->service->listUserFiles($includeExpired, $page, $perPage);

        return $this->successResponse($result);
    }

    /**
     * GET /api/v1/file-hosting/files/:id
     * Get file metadata
     */
    private function getFile(string $id): array {
        $file = $this->service->getFileInfo(urldecode($id));

        if (!$file) {
            return $this->errorResponse('File not found', 404, 'file_not_found');
        }

        return $this->successResponse(['file' => $file]);
    }

    /**
     * GET /api/v1/file-hosting/download/:id
     * Download a file
     */
    private function downloadFile(string $id, array $params): array {
        $password = $params['password'] ?? null;

        try {
            $result = $this->service->downloadFile(urldecode($id), $password);

            // Return download info - actual file streaming handled by caller
            return [
                'success' => true,
                'download' => true,
                'path' => $result['path'],
                'filename' => $result['filename'],
                'mime_type' => $result['mime_type'],
                'size' => $result['size'],
                'is_temp' => $result['is_temp'] ?? false,
                'status_code' => 200
            ];
        } catch (Exception $e) {
            return $this->errorResponse($e->getMessage(), 403, 'download_error');
        }
    }

    /**
     * DELETE /api/v1/file-hosting/files/:id
     * Delete a file
     */
    private function deleteFile(string $id): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $result = $this->service->deleteFile(urldecode($id));

        return $this->successResponse($result);
    }

    /**
     * POST /api/v1/file-hosting/extend/:id
     * Extend storage duration
     */
    private function extendStorage(string $id, string $body): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $data = json_decode($body, true);
        if (!$data || !isset($data['days'])) {
            return $this->errorResponse('Days parameter required', 400, 'missing_days');
        }

        $additionalDays = (int) $data['days'];
        $result = $this->service->extendStorage(urldecode($id), $additionalDays);

        return $this->successResponse($result);
    }

    // ==================== Storage and Pricing ====================

    /**
     * GET /api/v1/file-hosting/storage
     * Get storage usage info
     */
    private function getStorageInfo(): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $storage = $this->service->getStorageInfo();

        return $this->successResponse(['storage' => $storage]);
    }

    /**
     * GET /api/v1/file-hosting/pricing
     * Get current pricing
     */
    private function getPricing(): array {
        $pricing = $this->service->getPricingInfo();

        return $this->successResponse(['pricing' => $pricing]);
    }

    /**
     * POST /api/v1/file-hosting/calculate
     * Calculate storage cost
     */
    private function calculateCost(string $body): array {
        $data = json_decode($body, true);
        if (!$data || !isset($data['size_bytes']) || !isset($data['days'])) {
            return $this->errorResponse('size_bytes and days parameters required', 400, 'missing_params');
        }

        $sizeBytes = (int) $data['size_bytes'];
        $days = (int) $data['days'];

        $cost = $this->service->calculateCost($sizeBytes, $days);

        return $this->successResponse(['cost' => $cost]);
    }

    // ==================== Payments ====================

    /**
     * GET /api/v1/file-hosting/payments
     * Get payment history
     */
    private function getPayments(array $params): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        $limit = min(100, max(1, (int) ($params['limit'] ?? 50)));
        $payments = $this->service->getPaymentHistory($limit);

        return $this->successResponse($payments);
    }

    // ==================== Statistics ====================

    /**
     * GET /api/v1/file-hosting/stats
     * Get node statistics (admin only)
     */
    private function getStatistics(): array {
        if (!$this->authenticatedKeyId) {
            return $this->errorResponse('Authentication required', 401, 'auth_required');
        }

        // TODO: Add admin check here

        $stats = $this->service->getNodeStatistics();

        return $this->successResponse(['statistics' => $stats]);
    }

    // ==================== Response Helpers ====================

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
            'error' => [
                'message' => $message,
                'code' => $code
            ],
            'timestamp' => date('c'),
            'status_code' => $statusCode
        ];
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message, array $context = []): void {
        if ($this->logger) {
            $this->logger->$level("[FileHosting API] $message", $context);
        }
    }
}
