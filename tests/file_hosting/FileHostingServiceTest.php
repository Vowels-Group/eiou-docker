<?php
# Copyright 2025

/**
 * File Hosting Service Tests
 *
 * Unit tests for the FileHostingService
 *
 * @package Tests\FileHosting
 */

use PHPUnit\Framework\TestCase;

class FileHostingServiceTest extends TestCase {
    private $pdo;
    private $repository;
    private $service;
    private $mockUtilities;
    private $mockLogger;

    protected function setUp(): void {
        // Create in-memory SQLite database for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tables
        $this->createTables();

        // Create repository
        $this->repository = new FileHostingRepository($this->pdo);

        // Create mock utilities and logger
        $this->mockUtilities = $this->createMock(UtilityServiceContainer::class);
        $this->mockLogger = $this->createMock(SecureLogger::class);

        // Create service with test configuration
        $config = [
            'settings' => [
                'max_file_size_mb' => 100,
                'storage_price_per_mb_per_day' => 0.001,
                'min_storage_days' => 1,
                'max_storage_days' => 365,
                'free_storage_mb' => 10,
                'encryption_enabled' => false
            ]
        ];

        $this->service = new FileHostingService(
            $this->repository,
            $this->mockUtilities,
            $this->mockLogger,
            $config
        );
    }

    private function createTables(): void {
        // Create file_hosting_files table
        $this->pdo->exec("
            CREATE TABLE file_hosting_files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id VARCHAR(64) UNIQUE NOT NULL,
                owner_public_key VARCHAR(128) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                stored_filename VARCHAR(128) NOT NULL,
                mime_type VARCHAR(128) DEFAULT 'application/octet-stream',
                size_bytes BIGINT NOT NULL,
                checksum VARCHAR(64),
                is_encrypted BOOLEAN DEFAULT 0,
                is_public BOOLEAN DEFAULT 0,
                access_password_hash VARCHAR(255),
                download_count INTEGER DEFAULT 0,
                expires_at TIMESTAMP NOT NULL,
                description TEXT,
                metadata TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create file_hosting_plans table
        $this->pdo->exec("
            CREATE TABLE file_hosting_plans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_public_key VARCHAR(128) UNIQUE NOT NULL,
                plan_type VARCHAR(32) DEFAULT 'free',
                quota_bytes BIGINT DEFAULT 10485760,
                used_bytes BIGINT DEFAULT 0,
                file_count INTEGER DEFAULT 0,
                total_spent DECIMAL(20,8) DEFAULT 0,
                expires_at TIMESTAMP,
                auto_renew BOOLEAN DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create file_hosting_payments table
        $this->pdo->exec("
            CREATE TABLE file_hosting_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                payment_id VARCHAR(64) UNIQUE NOT NULL,
                file_id VARCHAR(64),
                payer_public_key VARCHAR(128) NOT NULL,
                node_public_key VARCHAR(128) NOT NULL,
                amount DECIMAL(20,8) NOT NULL,
                payment_type VARCHAR(32) NOT NULL,
                status VARCHAR(32) DEFAULT 'pending',
                transaction_id VARCHAR(128),
                storage_days INTEGER DEFAULT 0,
                storage_bytes BIGINT DEFAULT 0,
                price_per_mb_per_day DECIMAL(20,8),
                completed_at TIMESTAMP,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    // ==================== Model Tests ====================

    public function testHostedFileGeneration(): void {
        $fileId = HostedFile::generateFileId();

        $this->assertNotEmpty($fileId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $fileId);
    }

    public function testHostedFileFromRow(): void {
        $row = [
            'id' => 1,
            'file_id' => 'test-file-id',
            'owner_public_key' => 'owner123',
            'filename' => 'test.pdf',
            'stored_filename' => 'test-file-id.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024000,
            'checksum' => 'abc123',
            'is_encrypted' => 0,
            'is_public' => 1,
            'download_count' => 5,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $file = HostedFile::fromRow($row);

        $this->assertEquals('test-file-id', $file->fileId);
        $this->assertEquals('test.pdf', $file->filename);
        $this->assertEquals(1024000, $file->sizeBytes);
        $this->assertTrue($file->isPublic);
        $this->assertFalse($file->isExpired());
    }

    public function testHostedFileExpiration(): void {
        $file = new HostedFile();
        $file->expiresAt = date('Y-m-d H:i:s', strtotime('-1 day'));

        $this->assertTrue($file->isExpired());
        $this->assertEquals(0, $file->getDaysRemaining());
    }

    public function testHostedFileHumanSize(): void {
        $file = new HostedFile();

        $file->sizeBytes = 500;
        $this->assertEquals('500 B', $file->getHumanFileSize());

        $file->sizeBytes = 1536;
        $this->assertEquals('1.5 KB', $file->getHumanFileSize());

        $file->sizeBytes = 1048576;
        $this->assertEquals('1 MB', $file->getHumanFileSize());
    }

    public function testStoragePlanCreation(): void {
        $plan = StoragePlan::createFreePlan('user123');

        $this->assertEquals('user123', $plan->userPublicKey);
        $this->assertEquals(StoragePlan::PLAN_FREE, $plan->planType);
        $this->assertEquals(10 * 1024 * 1024, $plan->quotaBytes); // 10 MB
    }

    public function testStoragePlanUsage(): void {
        $plan = new StoragePlan();
        $plan->quotaBytes = 100 * 1024 * 1024; // 100 MB
        $plan->usedBytes = 25 * 1024 * 1024;   // 25 MB

        $this->assertEquals(25.0, $plan->getUsagePercentage());
        $this->assertFalse($plan->isFull());
        $this->assertTrue($plan->canStore(50 * 1024 * 1024)); // Can store 50 MB
        $this->assertFalse($plan->canStore(80 * 1024 * 1024)); // Cannot store 80 MB
    }

    public function testFilePaymentCalculation(): void {
        $sizeBytes = 10 * 1024 * 1024; // 10 MB
        $days = 30;
        $pricePerMbPerDay = 0.001;

        $cost = FilePayment::calculateCost($sizeBytes, $days, $pricePerMbPerDay);

        // 10 MB * 30 days * 0.001 = 0.3 eIOU
        $this->assertEquals(0.3, $cost);
    }

    public function testFilePaymentCreation(): void {
        $payment = FilePayment::createUploadPayment(
            'file123',
            'user123',
            'node123',
            10 * 1024 * 1024, // 10 MB
            30,               // 30 days
            0.001             // Price per MB per day
        );

        $this->assertStringStartsWith('pay_', $payment->paymentId);
        $this->assertEquals('file123', $payment->fileId);
        $this->assertEquals(FilePayment::TYPE_UPLOAD, $payment->paymentType);
        $this->assertEquals(FilePayment::STATUS_PENDING, $payment->status);
        $this->assertEquals(0.3, $payment->amount);
    }

    // ==================== Repository Tests ====================

    public function testSaveAndRetrieveFile(): void {
        $file = new HostedFile();
        $file->fileId = HostedFile::generateFileId();
        $file->ownerPublicKey = 'owner123';
        $file->filename = 'test.pdf';
        $file->storedFilename = $file->fileId . '.pdf';
        $file->mimeType = 'application/pdf';
        $file->sizeBytes = 1024000;
        $file->isPublic = true;
        $file->expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $this->assertTrue($this->repository->saveFile($file));

        $retrieved = $this->repository->getFileById($file->fileId);

        $this->assertNotNull($retrieved);
        $this->assertEquals($file->filename, $retrieved->filename);
        $this->assertEquals($file->sizeBytes, $retrieved->sizeBytes);
    }

    public function testGetFilesByOwner(): void {
        // Create multiple files for the same owner
        for ($i = 0; $i < 3; $i++) {
            $file = new HostedFile();
            $file->fileId = HostedFile::generateFileId();
            $file->ownerPublicKey = 'owner123';
            $file->filename = "test{$i}.pdf";
            $file->storedFilename = $file->fileId . '.pdf';
            $file->sizeBytes = 1024000;
            $file->expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
            $this->repository->saveFile($file);
        }

        $files = $this->repository->getFilesByOwner('owner123');

        $this->assertCount(3, $files);
    }

    public function testIncrementDownloads(): void {
        $file = new HostedFile();
        $file->fileId = 'download-test';
        $file->ownerPublicKey = 'owner123';
        $file->filename = 'test.pdf';
        $file->storedFilename = 'download-test.pdf';
        $file->sizeBytes = 1024000;
        $file->downloadCount = 0;
        $file->expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $this->repository->saveFile($file);

        $this->repository->incrementDownloads('download-test');
        $this->repository->incrementDownloads('download-test');

        $retrieved = $this->repository->getFileById('download-test');
        $this->assertEquals(2, $retrieved->downloadCount);
    }

    public function testSaveAndRetrieveStoragePlan(): void {
        $plan = StoragePlan::createFreePlan('user123');
        $plan->quotaBytes = 50 * 1024 * 1024;

        $this->assertTrue($this->repository->saveStoragePlan($plan));

        $retrieved = $this->repository->getStoragePlan('user123');

        $this->assertNotNull($retrieved);
        $this->assertEquals(50 * 1024 * 1024, $retrieved->quotaBytes);
    }

    public function testSaveAndRetrievePayment(): void {
        $payment = FilePayment::createUploadPayment(
            'file123',
            'user123',
            'node123',
            10 * 1024 * 1024,
            30,
            0.001
        );

        $this->assertTrue($this->repository->savePayment($payment));

        $retrieved = $this->repository->getPaymentById($payment->paymentId);

        $this->assertNotNull($retrieved);
        $this->assertEquals($payment->amount, $retrieved->amount);
    }

    public function testGetTotalStorageByOwner(): void {
        // Create files with known sizes
        $sizes = [1024000, 2048000, 512000]; // ~1MB, ~2MB, ~0.5MB

        foreach ($sizes as $i => $size) {
            $file = new HostedFile();
            $file->fileId = "size-test-{$i}";
            $file->ownerPublicKey = 'owner123';
            $file->filename = "test{$i}.pdf";
            $file->storedFilename = "size-test-{$i}.pdf";
            $file->sizeBytes = $size;
            $file->expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
            $this->repository->saveFile($file);
        }

        $totalStorage = $this->repository->getTotalStorageByOwner('owner123');

        $this->assertEquals(array_sum($sizes), $totalStorage);
    }

    // ==================== Service Tests ====================

    public function testGetPricingInfo(): void {
        $pricing = $this->service->getPricingInfo();

        $this->assertArrayHasKey('price_per_mb_per_day', $pricing);
        $this->assertArrayHasKey('free_storage_mb', $pricing);
        $this->assertArrayHasKey('max_file_size_mb', $pricing);
        $this->assertEquals(0.001, $pricing['price_per_mb_per_day']);
    }

    public function testCalculateCost(): void {
        $cost = $this->service->calculateCost(10 * 1024 * 1024, 30);

        $this->assertArrayHasKey('total_cost', $cost);
        $this->assertArrayHasKey('size_mb', $cost);
        $this->assertArrayHasKey('days', $cost);
        $this->assertEquals(0.3, $cost['total_cost']);
    }

    public function testGetOrCreateStoragePlan(): void {
        // Create mock user context
        $mockUserContext = $this->createMock(UserContext::class);
        $mockUserContext->method('getPublicKey')->willReturn('new-user-123');
        $this->service->setUserContext($mockUserContext);

        $plan = $this->service->getOrCreateStoragePlan('new-user-123');

        $this->assertInstanceOf(StoragePlan::class, $plan);
        $this->assertEquals('new-user-123', $plan->userPublicKey);
        $this->assertEquals(StoragePlan::PLAN_FREE, $plan->planType);
    }

    // ==================== Validation Tests ====================

    public function testHostedFileValidation(): void {
        $file = new HostedFile();

        // Should have validation errors when empty
        $errors = $file->validate();
        $this->assertNotEmpty($errors);

        // Fill required fields
        $file->fileId = HostedFile::generateFileId();
        $file->ownerPublicKey = 'owner123';
        $file->filename = 'test.pdf';
        $file->sizeBytes = 1024;
        $file->expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $errors = $file->validate();
        $this->assertEmpty($errors);
    }

    public function testFilePaymentValidation(): void {
        $payment = new FilePayment();

        // Should have validation errors when empty
        $errors = $payment->validate();
        $this->assertNotEmpty($errors);

        // Create valid payment
        $payment = FilePayment::createUploadPayment(
            'file123',
            'user123',
            'node123',
            1024,
            30,
            0.001
        );

        $errors = $payment->validate();
        $this->assertEmpty($errors);
    }

    public function testStoragePlanValidation(): void {
        $plan = new StoragePlan();

        // Should have validation errors when empty
        $errors = $plan->validate();
        $this->assertNotEmpty($errors);

        // Fill required fields
        $plan->userPublicKey = 'user123';

        $errors = $plan->validate();
        $this->assertEmpty($errors);
    }

    // ==================== Node Statistics Tests ====================

    public function testGetNodeStatistics(): void {
        // Create some test data
        $file = new HostedFile();
        $file->fileId = 'stats-test';
        $file->ownerPublicKey = 'owner123';
        $file->filename = 'test.pdf';
        $file->storedFilename = 'stats-test.pdf';
        $file->sizeBytes = 1024000;
        $file->downloadCount = 5;
        $file->expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $this->repository->saveFile($file);

        $payment = FilePayment::createUploadPayment(
            'stats-test',
            'owner123',
            'node123',
            1024000,
            30,
            0.001
        );
        $payment->markCompleted('txn123');
        $this->repository->savePayment($payment);

        $stats = $this->repository->getNodeStatistics();

        $this->assertEquals(1, $stats['total_files']);
        $this->assertEquals(1024000, $stats['total_storage_bytes']);
        $this->assertEquals(5, $stats['total_downloads']);
        $this->assertGreaterThan(0, $stats['total_revenue']);
    }
}
