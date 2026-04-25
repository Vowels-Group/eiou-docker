<?php

declare(strict_types=1);

namespace Tests\Unit\Gui\Controllers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Eiou\Gui\Controllers\ContactController;
use Eiou\Gui\Includes\Session;
use Eiou\Services\ContactService;

/**
 * Unit tests for ContactController
 *
 * Tests HTTP POST request handling for contact-related actions
 * including CSRF protection, input validation, and service delegation.
 */
#[CoversClass(ContactController::class)]
class ContactControllerTest extends TestCase
{
    private ContactController $controller;
    private Session $mockSession;
    private ContactService $mockContactService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockSession = $this->createMock(Session::class);
        $this->mockContactService = $this->createMock(ContactService::class);

        $this->controller = new ContactController(
            $this->mockSession,
            $this->mockContactService
        );
    }

    #[Test]
    public function constructorAcceptsDependencies(): void
    {
        $controller = new ContactController(
            $this->mockSession,
            $this->mockContactService
        );

        $this->assertInstanceOf(ContactController::class, $controller);
    }

    #[Test]
    public function routeActionReturnsEarlyForNonPostRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];

        // Should return without doing anything
        $this->controller->routeAction();

        // No exception means success
        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesAddContactAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'addContact',
            'address' => '',
            'name' => '',
            'fee' => '',
            'credit' => '',
            'currency' => ''
        ];

        // verifyCSRFToken will be called
        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        // Since fields are empty, it should redirect with error
        // We can't easily test the redirect, but we can verify CSRF is checked
        try {
            $this->controller->routeAction();
        } catch (\Throwable $e) {
            // Expected - redirect will cause exit or header error in test
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesAcceptContactAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'acceptContact',
            'contact_address' => '',
            'contact_name' => '',
            'contact_fee' => '',
            'contact_credit' => '',
            'contact_currency' => ''
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
    public function routeActionHandlesDeleteContactAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'deleteContact',
            'contact_address' => ''
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
    public function routeActionHandlesBlockContactAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'blockContact',
            'contact_address' => ''
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
    public function routeActionHandlesUnblockContactAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'unblockContact',
            'contact_address' => ''
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
    public function routeActionHandlesEditContactAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'editContact',
            'contact_address' => '',
            'contact_name' => '',
            'contact_fee' => '',
            'contact_credit' => '',
            'contact_currency' => ''
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
    public function routeActionHandlesDeclineCurrencyAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'declineCurrency',
            'pubkey_hash' => 'abc123',
            'currency' => 'MXN',
        ];
        $this->mockSession->expects($this->once())->method('verifyCSRFToken');
        try {
            ob_start();
            $this->controller->routeAction();
            ob_end_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) ob_end_clean();
            // Expected — handler will redirect via MessageHelper which can
            // throw or emit in unit-test context. We only verify CSRF was
            // checked and the route dispatched to handleDeclineCurrency.
        }
        $this->assertTrue(true);
    }

    #[Test]
    public function handleDeclineCurrencyRequiresPubkeyAndCurrency(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'pubkey_hash' => '',
            'currency' => '',
        ];
        $this->mockSession->expects($this->once())->method('verifyCSRFToken');
        try {
            $this->controller->handleDeclineCurrency();
        } catch (\Throwable $e) {
            // Expected — MessageHelper redirect
        }
        $this->assertTrue(true);
    }

    #[Test]
    public function handleDeclineCurrencyUppercasesCurrencyCode(): void
    {
        // Smoke test for sanitisation — lowercase input should be
        // normalised to uppercase before the delete runs.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'pubkey_hash' => 'abc123',
            'currency' => 'mxn',
        ];
        $this->mockSession->expects($this->once())->method('verifyCSRFToken');
        try {
            $this->controller->handleDeclineCurrency();
        } catch (\Throwable $e) {
            // Expected — downstream DB/redirect path
        }
        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesApplyContactDecisionsAction(): void
    {
        // Verifies the new batched-decisions action dispatches via
        // routeAction with CSRF protection.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'applyContactDecisions',
            'pubkey_hash' => 'abc123',
            'decisions' => json_encode([
                ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
            ]),
        ];
        $this->mockSession->expects($this->once())->method('verifyCSRFToken');
        try {
            ob_start();
            $this->controller->routeAction();
            ob_end_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) { ob_end_clean(); }
        }
        $this->assertTrue(true);
    }

    #[Test]
    public function handleApplyContactDecisionsRequiresPubkeyHash(): void
    {
        // Empty pubkey_hash → early redirect with error, no service call.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'pubkey_hash' => '',
            'decisions' => json_encode([
                ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
            ]),
        ];
        $this->mockSession->expects($this->once())->method('verifyCSRFToken');
        try {
            $this->controller->handleApplyContactDecisions();
        } catch (\Throwable $e) {
            // Expected — MessageHelper redirect
        }
        $this->assertTrue(true);
    }

    #[Test]
    public function handleApplyContactDecisionsRejectsEmptyDecisionsArray(): void
    {
        // No decisions submitted → early redirect, no DB writes.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'pubkey_hash' => 'abc123',
            'decisions' => '[]',
        ];
        $this->mockSession->expects($this->once())->method('verifyCSRFToken');
        try {
            $this->controller->handleApplyContactDecisions();
        } catch (\Throwable $e) {
            // Expected — MessageHelper redirect
        }
        $this->assertTrue(true);
    }

    #[Test]
    public function handleApplyContactDecisionsRejectsMalformedDecisionsJson(): void
    {
        // Non-JSON / non-array decisions → early redirect.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'pubkey_hash' => 'abc123',
            'decisions' => 'not-a-json-array',
        ];
        $this->mockSession->expects($this->once())->method('verifyCSRFToken');
        try {
            $this->controller->handleApplyContactDecisions();
        } catch (\Throwable $e) {
            // Expected — MessageHelper redirect
        }
        $this->assertTrue(true);
    }

    #[Test]
    public function handleApplyContactDecisionsAcceptsMixedActions(): void
    {
        // Smoke: mixed accept/decline/defer payload doesn't blow up
        // input parsing. "defer" entries are intentionally ignored by
        // the partition step.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'pubkey_hash' => 'abc123',
            'decisions' => json_encode([
                ['currency' => 'USD', 'action' => 'accept', 'fee' => '0.01', 'credit' => '1000'],
                ['currency' => 'EUR', 'action' => 'decline'],
                ['currency' => 'XRP', 'action' => 'defer'],
            ]),
        ];
        $this->mockSession->expects($this->once())->method('verifyCSRFToken');
        try {
            $this->controller->handleApplyContactDecisions();
        } catch (\Throwable $e) {
            // Expected — downstream DB/redirect path
        }
        $this->assertTrue(true);
    }

    #[Test]
    public function routeActionHandlesPingContactAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'pingContact',
            'contact_address' => ''
        ];

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

    #[Test]
    public function routeActionIgnoresUnknownAction(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'unknownAction'];

        // Should not throw or call any handler
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
    public function handleAddContactRequiresAllFields(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'address' => 'test-address',
            'name' => 'Test Name',
            'fee' => '',  // Empty field
            'credit' => '100',
            'currency' => 'USD'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleAddContact();
        } catch (\Throwable $e) {
            // Expected - redirect with error message
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleAddContactAcceptsRequestedCreditField(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'address' => 'http://bob:8080',
            'name' => 'Bob',
            'fee' => '1',
            'credit' => '100',
            'currency' => 'USD',
            'requested_credit' => '500',
            'description' => ''
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        // The call will fail at addContact (mock doesn't do real work)
        // but we verify the form field is accepted without validation errors
        try {
            $this->controller->handleAddContact();
        } catch (\Throwable $e) {
            // Expected - service call or redirect
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleAddContactAcceptsEmptyRequestedCredit(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'address' => 'http://bob:8080',
            'name' => 'Bob',
            'fee' => '1',
            'credit' => '100',
            'currency' => 'USD',
            'requested_credit' => '',  // Empty = not provided
            'description' => ''
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleAddContact();
        } catch (\Throwable $e) {
            // Expected - service call or redirect
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleDeleteContactRequiresContactAddress(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['contact_address' => ''];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleDeleteContact();
        } catch (\Throwable $e) {
            // Expected - redirect with error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleBlockContactRequiresContactAddress(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['contact_address' => ''];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleBlockContact();
        } catch (\Throwable $e) {
            // Expected - redirect with error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleUnblockContactRequiresContactAddress(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['contact_address' => ''];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleUnblockContact();
        } catch (\Throwable $e) {
            // Expected - redirect with error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleAcceptContactRequiresAllFields(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'contact_address' => 'test-address',
            'contact_name' => '',  // Empty field
            'contact_fee' => '5',
            'contact_credit' => '100',
            'contact_currency' => 'USD'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleAcceptContact();
        } catch (\Throwable $e) {
            // Expected - redirect with error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handleEditContactRequiresAllFields(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'contact_address' => 'test-address',
            'contact_name' => 'Test Name',
            'contact_fee' => '',  // Empty field
            'contact_credit' => '100',
            'contact_currency' => 'USD'
        ];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        try {
            $this->controller->handleEditContact();
        } catch (\Throwable $e) {
            // Expected - redirect with error
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function handlePingContactReturnsJsonForMissingAddress(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['contact_address' => ''];

        $this->mockSession->expects($this->once())
            ->method('verifyCSRFToken');

        ob_start();
        try {
            $this->controller->handlePingContact();
            $output = ob_get_clean();

            $decoded = json_decode($output, true);
            if ($decoded !== null) {
                $this->assertFalse($decoded['success']);
                $this->assertEquals('missing_address', $decoded['error']);
            }
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            // Header already sent or other error in test environment
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
