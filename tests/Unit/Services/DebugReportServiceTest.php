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
}
