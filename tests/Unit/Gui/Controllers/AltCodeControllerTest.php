<?php
# Copyright 2025-2026 Vowels Group, LLC

declare(strict_types=1);

namespace Tests\Unit\Gui\Controllers;

use Eiou\Core\UserContext;
use Eiou\Gui\Controllers\AltCodeController;
use Eiou\Gui\Controllers\AltCodeControllerResponseSent;
use Eiou\Gui\Includes\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test subclass that captures responses instead of echoing them.
 * Mirrors CapturingApiKeysController.
 */
class CapturingAltCodeController extends AltCodeController
{
    /** @var array<int, array{status:int, payload:array<string,mixed>}> */
    public array $responses = [];

    protected function respond(array $payload, int $status = 200): void
    {
        $this->responses[] = ['status' => $status, 'payload' => $payload];
        throw new AltCodeControllerResponseSent($status);
    }
}

#[CoversClass(AltCodeController::class)]
class AltCodeControllerTest extends TestCase
{
    private Session $session;
    private CapturingAltCodeController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
        $this->resetUserContextSingleton();
        $this->session = $this->createMock(Session::class);
        $this->controller = new CapturingAltCodeController($this->session);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        $this->resetUserContextSingleton();
        parent::tearDown();
    }

    private function resetUserContextSingleton(): void
    {
        $r = new ReflectionClass(UserContext::class);
        $p = $r->getProperty('instance');
        $p->setAccessible(true);
        $p->setValue(null, null);
    }

    private function dispatch(): array
    {
        try {
            $this->controller->routeAction();
        } catch (AltCodeControllerResponseSent) {
            // Expected — response was recorded
        }
        $this->assertNotEmpty($this->controller->responses, 'Controller produced no response');
        return $this->controller->responses[0];
    }

    /**
     * Seed the UserContext singleton with a controlled fixture. Uses
     * setUserData() so we sidestep both the encrypted-authcode field
     * (would require crypto setup) and the userconfig.json write path.
     * The handlers we exercise never decrypt — they call getAuthCode()
     * which returns the plaintext primary; we monkey-patch that by
     * pre-loading a fake plaintext into a known slot.
     *
     * Since getAuthCode() decrypts authcode_encrypted, we can't fake the
     * primary without crypto plumbing. Instead, each test that needs the
     * primary stubs the singleton with a child mock.
     */
    private function seedUserContextWithPrimary(string $primary, ?string $altHash = null): void
    {
        // We need UserContext::getInstance()->getAuthCode() to return
        // $primary deterministically. Easiest path: a tiny mock that
        // overrides getInstance to return a stub. Use reflection to
        // inject a mock subclass.
        $this->resetUserContextSingleton();
        $stub = new class($primary, $altHash) extends UserContext {
            private string $fakePrimary;
            private ?string $fakeAltHash;
            public function __construct(string $primary, ?string $altHash)
            {
                // Skip parent constructor — we don't want it loading config.
                $this->fakePrimary = $primary;
                $this->fakeAltHash = $altHash;
            }
            public function getAuthCode(): ?string
            {
                return $this->fakePrimary;
            }
            public function hasAltCode(): bool
            {
                return $this->fakeAltHash !== null && $this->fakeAltHash !== '';
            }
            public function getAltCodeHash(): ?string
            {
                return $this->fakeAltHash;
            }
            public function setAltCode(string $plaintext): void
            {
                $this->fakeAltHash = password_hash($plaintext, PASSWORD_ARGON2ID);
            }
            public function clearAltCode(): void
            {
                $this->fakeAltHash = null;
            }
        };
        $r = new ReflectionClass(UserContext::class);
        $p = $r->getProperty('instance');
        $p->setAccessible(true);
        $p->setValue(null, $stub);
    }

    // =========================================================================
    // CSRF and routing
    // =========================================================================

    #[Test]
    public function invalidCsrfReturns403(): void
    {
        $_POST = ['action' => 'altCodeStatus', 'csrf_token' => 'bad'];
        $this->session->method('validateCSRFToken')->willReturn(false);

        $result = $this->dispatch();

        $this->assertSame(403, $result['status']);
        $this->assertSame('csrf_invalid', $result['payload']['code']);
    }

    #[Test]
    public function unknownActionReturns400(): void
    {
        $_POST = ['action' => 'altCodeBogus', 'csrf_token' => 't'];
        $this->session->method('validateCSRFToken')->willReturn(true);

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('unknown_action', $result['payload']['code']);
    }

    // =========================================================================
    // status
    // =========================================================================

    #[Test]
    public function statusReportsHasAltCode(): void
    {
        $_POST = ['action' => 'altCodeStatus', 'csrf_token' => 't'];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('authenticatedViaAlt')->willReturn(false);
        $this->seedUserContextWithPrimary('primary_xyz', password_hash('SomeAlt12!Safe', PASSWORD_ARGON2ID));

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['has_alt_code']);
        $this->assertFalse($result['payload']['authenticated_via_alt']);
        $this->assertSame(12, $result['payload']['min_length']);
    }

    // =========================================================================
    // set / rotate
    // =========================================================================

    #[Test]
    public function setRefusesWhenSessionAuthenticatedViaAlt(): void
    {
        $_POST = [
            'action' => 'altCodeSet',
            'csrf_token' => 't',
            'primary_authcode' => 'primary_xyz',
            'new_alt_code' => 'AnotherAlt12!Ok',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('authenticatedViaAlt')->willReturn(true);
        $this->seedUserContextWithPrimary('primary_xyz', null);

        $result = $this->dispatch();

        $this->assertSame(403, $result['status']);
        $this->assertSame('alt_session_forbidden', $result['payload']['code']);
    }

    #[Test]
    public function setRejectsMissingPrimary(): void
    {
        $_POST = [
            'action' => 'altCodeSet',
            'csrf_token' => 't',
            'primary_authcode' => '',
            'new_alt_code' => 'AnotherAlt12!Ok',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('authenticatedViaAlt')->willReturn(false);
        $this->seedUserContextWithPrimary('primary_xyz', null);

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('missing_primary', $result['payload']['code']);
    }

    #[Test]
    public function setRejectsInvalidPrimary(): void
    {
        $_POST = [
            'action' => 'altCodeSet',
            'csrf_token' => 't',
            'primary_authcode' => 'wrong_primary',
            'new_alt_code' => 'AnotherAlt12!Ok',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('authenticatedViaAlt')->willReturn(false);
        $this->seedUserContextWithPrimary('primary_xyz', null);

        $result = $this->dispatch();

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_primary', $result['payload']['code']);
    }

    #[Test]
    public function setRejectsAltMatchingPrimary(): void
    {
        $_POST = [
            'action' => 'altCodeSet',
            'csrf_token' => 't',
            'primary_authcode' => 'primary_xyz',
            'new_alt_code' => 'primary_xyz',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('authenticatedViaAlt')->willReturn(false);
        $this->seedUserContextWithPrimary('primary_xyz', null);

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('alt_matches_primary', $result['payload']['code']);
    }

    #[Test]
    public function setRejectsWeakAltCode(): void
    {
        $_POST = [
            'action' => 'altCodeSet',
            'csrf_token' => 't',
            'primary_authcode' => 'primary_xyz',
            'new_alt_code' => 'shortweak',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('authenticatedViaAlt')->willReturn(false);
        $this->seedUserContextWithPrimary('primary_xyz', null);

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('weak_alt_code', $result['payload']['code']);
        $this->assertIsArray($result['payload']['errors']);
        $this->assertNotEmpty($result['payload']['errors']);
    }

    #[Test]
    public function setSucceedsWithValidInputs(): void
    {
        $_POST = [
            'action' => 'altCodeSet',
            'csrf_token' => 't',
            'primary_authcode' => 'primary_xyz',
            'new_alt_code' => 'BrandNewAlt12!ok',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('authenticatedViaAlt')->willReturn(false);
        $this->seedUserContextWithPrimary('primary_xyz', null);

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertTrue($result['payload']['has_alt_code']);
    }

    // =========================================================================
    // clear
    // =========================================================================

    #[Test]
    public function clearRefusesWhenSessionAuthenticatedViaAlt(): void
    {
        $_POST = [
            'action' => 'altCodeClear',
            'csrf_token' => 't',
            'primary_authcode' => 'primary_xyz',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('authenticatedViaAlt')->willReturn(true);
        $this->seedUserContextWithPrimary('primary_xyz', password_hash('SomeAlt12!Safe', PASSWORD_ARGON2ID));

        $result = $this->dispatch();

        $this->assertSame(403, $result['status']);
        $this->assertSame('alt_session_forbidden', $result['payload']['code']);
    }

    #[Test]
    public function clearRejectsInvalidPrimary(): void
    {
        $_POST = [
            'action' => 'altCodeClear',
            'csrf_token' => 't',
            'primary_authcode' => 'nope',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('authenticatedViaAlt')->willReturn(false);
        $this->seedUserContextWithPrimary('primary_xyz', password_hash('SomeAlt12!Safe', PASSWORD_ARGON2ID));

        $result = $this->dispatch();

        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_primary', $result['payload']['code']);
    }

    #[Test]
    public function clearSucceedsWithValidPrimary(): void
    {
        $_POST = [
            'action' => 'altCodeClear',
            'csrf_token' => 't',
            'primary_authcode' => 'primary_xyz',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('authenticatedViaAlt')->willReturn(false);
        $this->seedUserContextWithPrimary('primary_xyz', password_hash('SomeAlt12!Safe', PASSWORD_ARGON2ID));

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertFalse($result['payload']['has_alt_code']);
    }
}
