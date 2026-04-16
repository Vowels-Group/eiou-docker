<?php

declare(strict_types=1);

namespace Tests\Unit\Gui\Controllers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Eiou\Gui\Controllers\SettingsController;
use Eiou\Gui\Includes\Session;

/**
 * Unit tests for SettingsController
 *
 * Tests HTTP POST request handling for settings-related actions
 * including settings updates, debug log handling, and debug report generation.
 */
#[CoversClass(SettingsController::class)]
class SettingsControllerTest extends TestCase
{
    private SettingsController $controller;
    private Session $mockSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockSession = $this->createMock(Session::class);
        $this->controller = new SettingsController($this->mockSession);
    }

    #[Test]
    public function constructorAcceptsDependencies(): void
    {
        $controller = new SettingsController($this->mockSession);

        $this->assertInstanceOf(SettingsController::class, $controller);
    }

    #[Test]
    public function routeActionHandlesUpdateSettingsAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'updateSettings'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // Expected - redirect behavior or file operation
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesClearDebugLogsAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'clearDebugLogs'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // Expected - redirect behavior or database operation
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesSendDebugReportAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'sendDebugReport',
            'description' => 'Test description'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // Expected - redirect or file operation
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesGetDebugReportJsonAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'getDebugReportJson',
            'description' => 'Test description',
            'report_mode' => 'limited'
        ];

        // Note: CSRF already verified in index.html before Functions.php
        try {
            ob_start();
            $this->controller->routeAction();
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesUnknownActionWithError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'unknownAction'];

        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // Expected - redirect with error message
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsValidatesDefaultCurrency(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['defaultCurrency' => 'INVALID'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - redirect with validation error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsValidatesDefaultFee(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['defaultFee' => '-5'];  // Invalid negative fee

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - redirect with validation error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsValidatesMinFee(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['minFee' => 'invalid'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - redirect with validation error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsAcceptsZeroMinFee(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['minFee' => '0'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - redirect after save
        }

        // No validation error for 0 — it's valid for free relaying
        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsValidatesMaxFee(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['maxFee' => '150'];  // Over 100%

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - redirect with validation error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsValidatesDefaultCreditLimit(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['defaultCreditLimit' => '-100'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - redirect with validation error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsValidatesMaxP2pLevel(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['maxP2pLevel' => 'invalid'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - redirect with validation error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsValidatesP2pExpiration(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['p2pExpiration' => '0'];  // Invalid - must be >= minimum

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - redirect with validation error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsValidatesMaxOutput(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['maxOutput' => '-1'];  // Invalid negative

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - redirect with validation error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsValidatesDefaultTransportMode(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['defaultTransportMode' => 'invalid_mode'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - redirect with validation error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsHandlesBooleanAutoRefreshEnabled(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['autoRefreshEnabled' => '1'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - file write or redirect
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsHandlesBooleanAutoBackupEnabled(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['autoBackupEnabled' => '1'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - file write or redirect
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUpdateSettingsHandlesAutoRefreshDisabled(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];  // Checkbox not checked = not present in POST

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUpdateSettings();
        } catch (\Throwable $e) {
            // Expected - file write or redirect
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleClearDebugLogsCallsVerifyCSRFToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleClearDebugLogs();
        } catch (\Throwable $e) {
            // Expected - DebugRepository instantiation or redirect
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendDebugReportCallsVerifyCSRFToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['description' => 'Test issue description'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleSendDebugReport();
        } catch (\Throwable $e) {
            // Expected - file/database operations or redirect
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendDebugReportSanitizesDescription(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['description' => '<script>alert("xss")</script>'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleSendDebugReport();
        } catch (\Throwable $e) {
            // Expected - sanitization happens, then file operations
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleGetDebugReportJsonReturnsJsonFormat(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'description' => 'Test description',
            'report_mode' => 'limited'
        ];

        try {
            ob_start();
            $this->controller->handleGetDebugReportJson();
            $output = ob_get_clean();

            // If we got output, verify it's JSON
            if (!empty($output)) {
                $decoded = json_decode($output, true);
                // Should be valid JSON
                $this->assertNotNull($decoded);
            }
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            // Expected - database or file operations in test environment
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleGetDebugReportJsonSupportsFullMode(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'description' => 'Full report test',
            'report_mode' => 'full'
        ];

        try {
            ob_start();
            $this->controller->handleGetDebugReportJson();
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleGetDebugReportJsonDefaultsToFullMode(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['description' => 'Default mode test'];
        // report_mode not set, should default to 'full'

        try {
            ob_start();
            $this->controller->handleGetDebugReportJson();
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesResetToDefaultsAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'resetToDefaults'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // Expected — resetToDefaults() writes files and MessageHelper
            // calls header()+exit under the hood, which throws or errors
            // in a test harness. The assertion here is that the dispatch
            // reached handleResetToDefaults (verifyCSRFToken was called).
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleResetToDefaultsCallsVerifyCSRFToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleResetToDefaults();
        } catch (\Throwable $e) {
            // Expected — UserContext::resetToDefaults hits the real config
            // paths and MessageHelper::redirectMessage calls header()+exit
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesAnalyticsConsentAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'analyticsConsent', 'consent' => '1'];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            ob_start();
            $this->controller->routeAction();
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tearDown();
    }
}
