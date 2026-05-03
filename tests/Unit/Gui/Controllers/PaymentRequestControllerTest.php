<?php

declare(strict_types=1);

namespace Tests\Unit\Gui\Controllers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Eiou\Gui\Controllers\PaymentRequestController;
use Eiou\Gui\Includes\Session;
use Eiou\Services\GuiActionRegistry;
use Eiou\Services\PaymentRequestService;

/**
 * Unit tests for PaymentRequestController — covers the
 * GuiActionRegistry registration. Behavior of the individual handlers
 * is exercised by integration tests against a live container; this
 * file just asserts the structural wiring: registerActions()
 * populates the registry with every owned action at the documented
 * tier and 'core' plugin id.
 */
#[CoversClass(PaymentRequestController::class)]
class PaymentRequestControllerTest extends TestCase
{
    #[Test]
    public function registerActionsPopulatesRegistryWithCorrectTiers(): void
    {
        $session = $this->createMock(Session::class);
        $service = $this->createMock(PaymentRequestService::class);
        $controller = new PaymentRequestController($session, $service);
        $registry = new GuiActionRegistry();

        $controller->registerActions($registry);

        // All payment-request actions are HTML-redirect, registered at
        // TIER_AUTH so the dispatcher's CSRF gate doesn't fire — the
        // handler's inline rotating verifyCSRFToken() does the gate.
        foreach ([
            'createPaymentRequest',
            'approvePaymentRequest',
            'declinePaymentRequest',
            'cancelPaymentRequest',
            'declineAllPaymentRequests',
            'cancelAllPaymentRequests',
        ] as $a) {
            $this->assertSame(GuiActionRegistry::TIER_AUTH, $registry->getTier($a), "{$a} should register at TIER_AUTH");
            $this->assertSame('core', $registry->getPluginId($a), "{$a} should be owned by 'core'");
            $this->assertNotNull($registry->getHandler($a), "{$a} should have a registered handler");
        }
    }
}
