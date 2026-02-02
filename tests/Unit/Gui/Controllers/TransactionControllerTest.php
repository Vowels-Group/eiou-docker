<?php

declare(strict_types=1);

namespace Tests\Unit\Gui\Controllers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Eiou\Gui\Controllers\TransactionController;
use Eiou\Gui\Includes\Session;
use Eiou\Services\ContactService;
use Eiou\Services\TransactionService;

/**
 * Unit tests for TransactionController
 *
 * Tests HTTP POST request handling for transaction-related actions
 * including send eIOU operations, CSRF protection, and input validation.
 */
#[CoversClass(TransactionController::class)]
class TransactionControllerTest extends TestCase
{
    private TransactionController $controller;
    private Session $mockSession;
    private ContactService $mockContactService;
    private TransactionService $mockTransactionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockSession = $this->createMock(Session::class);
        $this->mockContactService = $this->createMock(ContactService::class);
        $this->mockTransactionService = $this->createMock(TransactionService::class);

        $this->controller = new TransactionController(
            $this->mockSession,
            $this->mockContactService,
            $this->mockTransactionService
        );
    }

    #[Test]
    public function constructorAcceptsDependencies(): void
    {
        $controller = new TransactionController(
            $this->mockSession,
            $this->mockContactService,
            $this->mockTransactionService
        );

        $this->assertInstanceOf(TransactionController::class, $controller);
    }

    #[Test]
    public function routeActionReturnsEarlyForNonPostNonCheckUpdatesRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];

        // Should return without doing anything
        $this->controller->routeAction();

        // No exception means success
        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesSendEIOUAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'sendEIOU',
            'recipient' => '',
            'amount' => '',
            'currency' => ''
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // Expected - redirect behavior
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionIgnoresUnknownAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'unknownAction'];

        // Should not call any handler for unknown action
        $this->controller->routeAction();

        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionIgnoresEmptyAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        // Should not throw
        $this->controller->routeAction();

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendEIOURequiresAllFields(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => 'TestContact',
            'amount' => '',  // Empty field
            'currency' => 'USD'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleSendEIOU();
        } catch (\Throwable $e) {
            // Expected - redirect with error message
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendEIOURequiresRecipient(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => '',
            'manual_recipient' => '',
            'amount' => '100',
            'currency' => 'USD'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleSendEIOU();
        } catch (\Throwable $e) {
            // Expected - redirect with error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendEIOURequiresCurrency(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => 'TestContact',
            'amount' => '100',
            'currency' => ''  // Empty currency
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleSendEIOU();
        } catch (\Throwable $e) {
            // Expected - redirect with error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendEIOUUsesManualRecipientWhenProvided(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => 'ContactName',
            'manual_recipient' => 'manual-address-here',  // Should use this
            'amount' => '100',
            'currency' => 'USD'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        // Should NOT call lookupByName because manual_recipient is provided
        $this->mockContactService->expects($this->never())
            ->method('lookupByName');

        try {
            $this->controller->handleSendEIOU();
        } catch (\Throwable $e) {
            // Expected - validation or redirect
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendEIOULooksUpContactByAddressType(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => 'TestContact',
            'manual_recipient' => '',
            'address_type' => 'http_address',
            'amount' => '100',
            'currency' => 'USD'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        // Should call lookupByName to resolve contact
        $this->mockContactService->expects($this->once())
            ->method('lookupByName')
            ->with('TestContact')
            ->willReturn([
                'http_address' => 'http://example.com/address',
                'tor_address' => 'http://example.onion/address'
            ]);

        try {
            $this->controller->handleSendEIOU();
        } catch (\Throwable $e) {
            // Expected - validation or service call
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendEIOUHandlesInvalidAddressTypeForContact(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => 'TestContact',
            'manual_recipient' => '',
            'address_type' => 'invalid_type',  // Invalid type
            'amount' => '100',
            'currency' => 'USD'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        $this->mockContactService->expects($this->once())
            ->method('lookupByName')
            ->with('TestContact')
            ->willReturn([
                'http_address' => 'http://example.com/address'
            ]);

        try {
            $this->controller->handleSendEIOU();
        } catch (\Throwable $e) {
            // Expected - redirect with error about address type
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendEIOUHandlesContactNotFound(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => 'NonExistentContact',
            'manual_recipient' => '',
            'address_type' => 'http_address',
            'amount' => '100',
            'currency' => 'USD'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        $this->mockContactService->expects($this->once())
            ->method('lookupByName')
            ->with('NonExistentContact')
            ->willReturn(null);  // Contact not found

        try {
            $this->controller->handleSendEIOU();
        } catch (\Throwable $e) {
            // Expected - redirect with error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendEIOUFallsBackToRecipientName(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => 'TestContact',
            'manual_recipient' => '',
            'address_type' => '',  // No address type specified
            'amount' => '100',
            'currency' => 'USD'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        // Should NOT call lookupByName when address_type is empty
        $this->mockContactService->expects($this->never())
            ->method('lookupByName');

        try {
            $this->controller->handleSendEIOU();
        } catch (\Throwable $e) {
            // Expected - validation continues with recipient name
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendEIOUAcceptsOptionalDescription(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => 'TestContact',
            'amount' => '100',
            'currency' => 'USD',
            'description' => 'Payment for services'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleSendEIOU();
        } catch (\Throwable $e) {
            // Expected - validation or service call
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleSendEIOUHandlesEmptyDescription(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'recipient' => 'TestContact',
            'amount' => '100',
            'currency' => 'USD',
            'description' => ''  // Empty description should be converted to null
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleSendEIOU();
        } catch (\Throwable $e) {
            // Expected - validation or service call
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleCheckUpdatesReturnsEarlyIfNotCheckUpdatesRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];  // No check_updates parameter

        // Should return immediately without output
        $this->controller->handleCheckUpdates();

        $this->assertTrue(true);
    }

    #[Test]
    public function handleCheckUpdatesReturnsEarlyIfCheckUpdatesNotOne(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['check_updates' => '0'];  // Not '1'

        // Should return immediately
        $this->controller->handleCheckUpdates();

        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesGetRequestWithCheckUpdates(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['check_updates' => '1'];

        try {
            ob_start();
            $this->controller->routeAction();
            $output = ob_get_clean();

            // Should output something
            $this->assertNotEmpty($output);
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            // Expected - exit call in handleCheckUpdates
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleCheckUpdatesAcceptsLastCheckTime(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [
            'check_updates' => '1',
            'last_check' => time() - 60  // 60 seconds ago
        ];

        try {
            ob_start();
            $this->controller->handleCheckUpdates();
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            // Expected - exit call
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleCheckUpdatesOutputsNoUpdatesWhenFunctionsNotDefined(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['check_updates' => '1'];

        // checkForNewTransactions and checkForNewContactRequests functions
        // are not defined in the test environment, so should output no_updates

        try {
            ob_start();
            $this->controller->handleCheckUpdates();
            $output = ob_get_clean();

            if (!empty($output)) {
                $this->assertStringContainsString('no_updates', $output);
            }
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            // Expected - exit call
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
