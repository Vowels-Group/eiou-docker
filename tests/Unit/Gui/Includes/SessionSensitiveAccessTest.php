<?php

declare(strict_types=1);

namespace Tests\Unit\Gui\Includes;

use Eiou\Gui\Includes\Session;
use Eiou\Gui\Includes\SessionKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the short-lived sensitive-action grant on top of the normal
 * session. Independent of remember-me by design: a remembered device still
 * has to pass this gate before touching API keys.
 */
#[CoversClass(Session::class)]
class SessionSensitiveAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Sessions are not actually started in test context; we operate on
        // the $_SESSION superglobal directly, which is what Session uses.
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    #[Test]
    public function grantSensitiveAccessMakesHasReturnTrue(): void
    {
        $_SESSION[SessionKeys::AUTH_TIME] = time() - 10;
        $session = new Session();

        $this->assertFalse($session->hasSensitiveAccess());

        $session->grantSensitiveAccess();

        $this->assertTrue($session->hasSensitiveAccess());
        $this->assertGreaterThan(0, $session->sensitiveAccessSecondsRemaining());
    }

    #[Test]
    public function grantExpiresAfterTtl(): void
    {
        $_SESSION[SessionKeys::AUTH_TIME] = time() - 10;
        $session = new Session();
        $session->grantSensitiveAccess(1);

        $_SESSION[SessionKeys::SENSITIVE_ACCESS_UNTIL] = time() - 1;

        $this->assertFalse($session->hasSensitiveAccess());
        $this->assertSame(0, $session->sensitiveAccessSecondsRemaining());
    }

    #[Test]
    public function grantIsInvalidatedWhenUnderlyingAuthChanges(): void
    {
        $_SESSION[SessionKeys::AUTH_TIME] = 1000;
        $session = new Session();
        $session->grantSensitiveAccess();

        $this->assertTrue($session->hasSensitiveAccess());

        // Simulate logout+login: auth_time advances, stale grant must not
        // carry over to the new auth session.
        $_SESSION[SessionKeys::AUTH_TIME] = 2000;

        $this->assertFalse($session->hasSensitiveAccess());
    }

    #[Test]
    public function clearSensitiveAccessRemovesGrant(): void
    {
        $_SESSION[SessionKeys::AUTH_TIME] = time();
        $session = new Session();
        $session->grantSensitiveAccess();
        $session->clearSensitiveAccess();

        $this->assertFalse($session->hasSensitiveAccess());
        $this->assertArrayNotHasKey(SessionKeys::SENSITIVE_ACCESS_UNTIL, $_SESSION);
        $this->assertArrayNotHasKey(SessionKeys::SENSITIVE_ACCESS_AUTH_TIME, $_SESSION);
    }
}
