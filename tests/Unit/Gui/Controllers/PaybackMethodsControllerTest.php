<?php

declare(strict_types=1);

namespace Tests\Unit\Gui\Controllers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Eiou\Gui\Controllers\PaybackMethodsController;
use Eiou\Gui\Includes\Session;
use Eiou\Services\GuiActionRegistry;
use Eiou\Services\PaybackMethodService;

/**
 * Unit tests for PaybackMethodsController — covers the
 * GuiActionRegistry registration. Behavior of the per-action handlers
 * is exercised live against a container.
 */
#[CoversClass(PaybackMethodsController::class)]
class PaybackMethodsControllerTest extends TestCase
{
    #[Test]
    public function registerActionsPopulatesRegistryWithCorrectTiers(): void
    {
        $session = $this->createMock(Session::class);
        $service = $this->createMock(PaybackMethodService::class);
        $controller = new PaybackMethodsController($session, $service);
        $registry = new GuiActionRegistry();

        $controller->registerActions($registry);

        // Every action registers a delegate-closure that calls
        // routeAction() and catches the ResponseSent sentinel locally.
        // Tier is TIER_AUTH because routeAction() does its own
        // non-rotating CSRF check internally — gating CSRF twice would
        // require fighting the controller's 403 envelope shape with
        // the registry's, and the controller's shape is what the JS
        // client expects.
        foreach ([
            'paybackMethodsList',
            'paybackMethodsGet',
            'paybackMethodsReveal',
            'paybackMethodsCreate',
            'paybackMethodsUpdate',
            'paybackMethodsDelete',
            'paybackMethodsSharePolicy',
            'paybackMethodsFetchFromContact',
        ] as $a) {
            $this->assertSame(GuiActionRegistry::TIER_AUTH, $registry->getTier($a), "{$a} should register at TIER_AUTH");
            $this->assertSame('core', $registry->getPluginId($a), "{$a} should be owned by 'core'");
            $this->assertNotNull($registry->getHandler($a), "{$a} should have a registered handler");
        }
    }
}
