<?php
/**
 * Unit Tests for DebugReportService
 *
 * Tests debug report generation including:
 * - Report generation with/without description
 * - Full vs limited mode
 * - System info collection
 * - File save functionality
 * - Error handling
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Eiou\Services\DebugReportService;
use Eiou\Database\DebugRepository;
use PDO;

#[CoversClass(DebugReportService::class)]
class DebugReportServiceTest extends TestCase
{
    private MockObject|DebugRepository $debugRepository;
    private MockObject|PDO $pdo;
    private DebugReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debugRepository = $this->createMock(DebugRepository::class);
        $this->pdo = $this->createMock(PDO::class);

        $this->service = new DebugReportService(
            $this->debugRepository,
            $this->pdo
        );
    }

    // =========================================================================
    // generateReport() Tests
    // =========================================================================

    public function testGenerateReportReturnsArrayWithExpectedKeys(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $report = $this->service->generateReport();

        $this->assertIsArray($report);
        $this->assertArrayHasKey('description', $report);
        $this->assertArrayHasKey('system_info', $report);
        $this->assertArrayHasKey('debug_entries', $report);
        $this->assertArrayHasKey('debug_entries_count', $report);
        $this->assertArrayHasKey('php_errors', $report);
        $this->assertArrayHasKey('nginx_errors', $report);
        $this->assertArrayHasKey('eiou_app_log', $report);
        $this->assertArrayHasKey('report_type', $report);
    }

    public function testGenerateReportIncludesDescription(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $report = $this->service->generateReport('test issue description');

        $this->assertEquals('test issue description', $report['description']);
    }

    public function testGenerateReportEmptyDescriptionByDefault(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $report = $this->service->generateReport();

        $this->assertEquals('', $report['description']);
    }

    public function testGenerateReportLimitedModeByDefault(): void
    {
        $this->debugRepository->expects($this->once())
            ->method('getRecentDebugEntries')
            ->with(100)
            ->willReturn([]);

        $report = $this->service->generateReport();

        $this->assertEquals('limited', $report['report_type']);
    }

    public function testGenerateReportFullModeUsesAllEntries(): void
    {
        $this->debugRepository->expects($this->once())
            ->method('getAllDebugEntries')
            ->willReturn([]);

        $report = $this->service->generateReport('', true);

        $this->assertEquals('full', $report['report_type']);
    }

    public function testGenerateReportCountsDebugEntries(): void
    {
        $entries = [
            ['id' => 1, 'message' => 'test1'],
            ['id' => 2, 'message' => 'test2'],
            ['id' => 3, 'message' => 'test3'],
        ];
        $this->debugRepository->method('getRecentDebugEntries')->willReturn($entries);

        $report = $this->service->generateReport();

        $this->assertEquals(3, $report['debug_entries_count']);
        $this->assertCount(3, $report['debug_entries']);
    }

    public function testGenerateReportSystemInfoContainsPhpVersion(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $report = $this->service->generateReport();

        $this->assertArrayHasKey('php_version', $report['system_info']);
        $this->assertEquals(phpversion(), $report['system_info']['php_version']);
    }

    public function testGenerateReportSystemInfoContainsTimestamp(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $report = $this->service->generateReport();

        $this->assertArrayHasKey('timestamp', $report['system_info']);
        $this->assertNotEmpty($report['system_info']['timestamp']);
    }

    public function testGenerateReportSystemInfoContainsPhpExtensions(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $report = $this->service->generateReport();

        $this->assertArrayHasKey('php_extensions', $report['system_info']);
        $this->assertArrayHasKey('php_extensions_count', $report['system_info']);
        $this->assertGreaterThan(0, $report['system_info']['php_extensions_count']);
    }

    public function testGenerateReportSystemInfoContainsSapi(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $report = $this->service->generateReport();

        $this->assertEquals('cli', $report['system_info']['sapi']);
    }

    public function testGenerateReportHandlesNullPdo(): void
    {
        $service = new DebugReportService($this->debugRepository, null);
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $report = $service->generateReport();

        $this->assertArrayHasKey('mysql_version', $report['system_info']);
        $this->assertEquals('N/A (no connection)', $report['system_info']['mysql_version']);
    }

    public function testGenerateReportLogFieldsAreStrings(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $report = $this->service->generateReport();

        $this->assertIsString($report['php_errors']);
        $this->assertIsString($report['nginx_errors']);
        $this->assertIsString($report['eiou_app_log']);
    }

    // =========================================================================
    // generateAndSave() Tests
    // =========================================================================

    public function testGenerateAndSaveCreatesFile(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'eiou-test-');
        try {
            $result = $this->service->generateAndSave('test', false, $tmpFile);

            $this->assertFileExists($tmpFile);
            $this->assertArrayHasKey('path', $result);
            $this->assertArrayHasKey('size', $result);
            $this->assertArrayHasKey('report', $result);
            $this->assertEquals($tmpFile, $result['path']);
            $this->assertGreaterThan(0, $result['size']);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testGenerateAndSaveWritesValidJson(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'eiou-test-');
        try {
            $this->service->generateAndSave('test', false, $tmpFile);

            $content = file_get_contents($tmpFile);
            $decoded = json_decode($content, true);
            $this->assertNotNull($decoded);
            $this->assertArrayHasKey('system_info', $decoded);
            $this->assertArrayHasKey('debug_entries', $decoded);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testGenerateAndSaveReturnsReportData(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'eiou-test-');
        try {
            $result = $this->service->generateAndSave('my description', false, $tmpFile);

            $this->assertEquals('my description', $result['report']['description']);
            $this->assertEquals('limited', $result['report']['report_type']);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testGenerateAndSaveThrowsOnInvalidPath(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to write debug report to');

        $this->service->generateAndSave('', false, '/nonexistent/directory/file.json');
    }

    public function testGenerateAndSaveDefaultPathInTmp(): void
    {
        $this->debugRepository->method('getRecentDebugEntries')->willReturn([]);

        $result = $this->service->generateAndSave();

        try {
            $this->assertStringStartsWith('/tmp/eiou-debug-report-', $result['path']);
            $this->assertStringEndsWith('.json', $result['path']);
        } finally {
            @unlink($result['path']);
        }
    }

    // =========================================================================
    // scrubReport() Tests (via reflection — private static)
    // =========================================================================

    private static function invokeScrubReport(array $report): array
    {
        $method = new \ReflectionMethod(DebugReportService::class, 'scrubReport');
        return $method->invoke(null, $report);
    }

    public function testScrubReportRedactsOnionAddresses(): void
    {
        $onion = str_repeat('a', 56) . '.onion';
        $report = ['peer' => "connected to {$onion}"];

        $scrubbed = self::invokeScrubReport($report);

        $this->assertStringNotContainsString($onion, $scrubbed['peer']);
        $this->assertStringContainsString('[redacted].onion', $scrubbed['peer']);
    }

    public function testScrubReportRedactsHttpUrls(): void
    {
        $report = ['url' => 'connected to https://example.com/api/v1'];

        $scrubbed = self::invokeScrubReport($report);

        $this->assertStringNotContainsString('example.com', $scrubbed['url']);
        $this->assertStringContainsString('https://[redacted]', $scrubbed['url']);
    }

    public function testScrubReportRedactsPublicKeys(): void
    {
        $key = str_repeat('ab', 32); // 64 hex chars
        $report = ['key' => "pubkey: {$key}"];

        $scrubbed = self::invokeScrubReport($report);

        $this->assertStringNotContainsString($key, $scrubbed['key']);
        $this->assertStringContainsString('[redacted-key]', $scrubbed['key']);
    }

    public function testScrubReportRedactsIpAddresses(): void
    {
        $report = ['log' => 'connection from 192.168.1.100 accepted'];

        $scrubbed = self::invokeScrubReport($report);

        $this->assertStringNotContainsString('192.168.1.100', $scrubbed['log']);
        $this->assertStringContainsString('[redacted-ip]', $scrubbed['log']);
    }

    public function testScrubReportPreservesNonSensitiveData(): void
    {
        $report = [
            'description' => 'login page crashed',
            'report_type' => 'limited',
            'debug_entries_count' => 42,
        ];

        $scrubbed = self::invokeScrubReport($report);

        $this->assertEquals('login page crashed', $scrubbed['description']);
        $this->assertEquals('limited', $scrubbed['report_type']);
        $this->assertEquals(42, $scrubbed['debug_entries_count']);
    }

    public function testScrubReportHandlesNestedData(): void
    {
        $report = [
            'system_info' => [
                'hostname' => 'https://mynode.example.com:8080/status',
            ],
        ];

        $scrubbed = self::invokeScrubReport($report);

        $this->assertStringNotContainsString('mynode.example.com', $scrubbed['system_info']['hostname']);
        $this->assertStringContainsString('https://[redacted]', $scrubbed['system_info']['hostname']);
    }

    public function testScrubReportHandlesEmptyReport(): void
    {
        $scrubbed = self::invokeScrubReport([]);

        $this->assertIsArray($scrubbed);
        $this->assertEmpty($scrubbed);
    }

    // =========================================================================
    // Rate Limiting Tests (via reflection + temp file)
    // =========================================================================

    private static function invokeCheckRateLimit(): array
    {
        $method = new \ReflectionMethod(DebugReportService::class, 'checkRateLimit');
        return $method->invoke(null);
    }

    private static function invokeRecordSubmission(): void
    {
        $method = new \ReflectionMethod(DebugReportService::class, 'recordSubmission');
        $method->invoke(null);
    }

    private static function getRateLimitFile(): string
    {
        $prop = new \ReflectionClassConstant(DebugReportService::class, 'RATE_LIMIT_FILE');
        return $prop->getValue();
    }

    protected function tearDown(): void
    {
        // Clean up rate limit file after each test
        @unlink(self::getRateLimitFile());
        parent::tearDown();
    }

    public function testCheckRateLimitAllowsWhenNoFile(): void
    {
        @unlink(self::getRateLimitFile());

        $result = self::invokeCheckRateLimit();

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['error']);
    }

    public function testCheckRateLimitAllowsUnderLimit(): void
    {
        $today = date('Y-m-d');
        $data = ['submissions' => [
            $today . 'T10:00:00+00:00',
            $today . 'T11:00:00+00:00',
        ]];
        file_put_contents(self::getRateLimitFile(), json_encode($data));

        $result = self::invokeCheckRateLimit();

        $this->assertTrue($result['allowed']);
    }

    public function testCheckRateLimitDeniesAtLimit(): void
    {
        $today = date('Y-m-d');
        $data = ['submissions' => [
            $today . 'T10:00:00+00:00',
            $today . 'T11:00:00+00:00',
            $today . 'T12:00:00+00:00',
        ]];
        file_put_contents(self::getRateLimitFile(), json_encode($data));

        $result = self::invokeCheckRateLimit();

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Daily limit reached', $result['error']);
    }

    public function testCheckRateLimitIgnoresYesterdaySubmissions(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $data = ['submissions' => [
            $yesterday . 'T10:00:00+00:00',
            $yesterday . 'T11:00:00+00:00',
            $yesterday . 'T12:00:00+00:00',
        ]];
        file_put_contents(self::getRateLimitFile(), json_encode($data));

        $result = self::invokeCheckRateLimit();

        $this->assertTrue($result['allowed']);
    }

    public function testRecordSubmissionCreatesFile(): void
    {
        @unlink(self::getRateLimitFile());

        self::invokeRecordSubmission();

        $this->assertFileExists(self::getRateLimitFile());
        $data = json_decode(file_get_contents(self::getRateLimitFile()), true);
        $this->assertCount(1, $data['submissions']);
    }

    public function testRecordSubmissionAppendsToExisting(): void
    {
        $today = date('Y-m-d');
        $data = ['submissions' => [$today . 'T10:00:00+00:00']];
        file_put_contents(self::getRateLimitFile(), json_encode($data));

        self::invokeRecordSubmission();

        $data = json_decode(file_get_contents(self::getRateLimitFile()), true);
        $this->assertCount(2, $data['submissions']);
    }

    public function testRecordSubmissionPrunesOldEntries(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $data = ['submissions' => [
            $yesterday . 'T10:00:00+00:00',
            $yesterday . 'T11:00:00+00:00',
        ]];
        file_put_contents(self::getRateLimitFile(), json_encode($data));

        self::invokeRecordSubmission();

        $data = json_decode(file_get_contents(self::getRateLimitFile()), true);
        // Old entries pruned, only today's new one remains
        $this->assertCount(1, $data['submissions']);
        $this->assertStringStartsWith(date('Y-m-d'), $data['submissions'][0]);
    }
}
