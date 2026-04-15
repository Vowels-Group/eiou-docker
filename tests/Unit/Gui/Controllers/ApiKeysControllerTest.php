<?php

declare(strict_types=1);

namespace Tests\Unit\Gui\Controllers;

use Eiou\Database\ApiKeyRepository;
use Eiou\Gui\Controllers\ApiKeysController;
use Eiou\Gui\Controllers\ApiKeysControllerResponseSent;
use Eiou\Gui\Includes\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test subclass that captures responses instead of echoing them. The
 * production controller throws a sentinel after writing; here we skip the
 * echo and just record payload + status so we can assert on them.
 */
class CapturingApiKeysController extends ApiKeysController
{
    /** @var array<int, array{status:int, payload:array<string,mixed>}> */
    public array $responses = [];

    protected function respond(array $payload, int $status = 200): void
    {
        $this->responses[] = ['status' => $status, 'payload' => $payload];
        throw new ApiKeysControllerResponseSent($status);
    }
}

#[CoversClass(ApiKeysController::class)]
class ApiKeysControllerTest extends TestCase
{
    private Session $session;
    private ApiKeyRepository $repository;
    private CapturingApiKeysController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
        $this->session = $this->createMock(Session::class);
        $this->repository = $this->createMock(ApiKeyRepository::class);
        $this->controller = new CapturingApiKeysController($this->session, $this->repository);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
        parent::tearDown();
    }

    /**
     * Run the controller and return the first captured response.
     *
     * @return array{status:int, payload:array<string,mixed>}
     */
    private function dispatch(): array
    {
        try {
            $this->controller->routeAction();
        } catch (ApiKeysControllerResponseSent) {
            // Expected — response was recorded on the controller
        }
        $this->assertNotEmpty($this->controller->responses, 'Controller produced no response');
        return $this->controller->responses[0];
    }

    #[Test]
    public function invalidCsrfReturns403(): void
    {
        $_POST = ['action' => 'apiKeysList', 'csrf_token' => 'bad'];
        $this->session->method('validateCSRFToken')->willReturn(false);

        $result = $this->dispatch();

        $this->assertSame(403, $result['status']);
        $this->assertFalse($result['payload']['success']);
        $this->assertSame('csrf_error', $result['payload']['error']);
    }

    #[Test]
    public function unknownActionReturns400(): void
    {
        $_POST = ['action' => 'apiKeysBogus', 'csrf_token' => 't'];
        $this->session->method('validateCSRFToken')->willReturn(true);

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('unknown_action', $result['payload']['error']);
    }

    #[Test]
    public function listReturnsNormalizedKeys(): void
    {
        $_POST = ['action' => 'apiKeysList', 'csrf_token' => 't'];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('hasSensitiveAccess')->willReturn(false);
        $this->session->method('sensitiveAccessSecondsRemaining')->willReturn(0);

        $this->repository->expects($this->once())
            ->method('listKeys')
            ->with(true)
            ->willReturn([
                [
                    'key_id' => 'eiou_abc',
                    'name' => 'Test',
                    'permissions' => ['wallet:read'],
                    'rate_limit_per_minute' => 100,
                    'enabled' => 1,
                    'created_at' => '2026-01-01 00:00:00',
                    'last_used_at' => null,
                    'expires_at' => null,
                ]
            ]);

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertCount(1, $result['payload']['keys']);
        $this->assertTrue($result['payload']['keys'][0]['enabled']);
    }

    #[Test]
    public function mutatingActionWithoutSensitiveAccessReturns401(): void
    {
        $_POST = [
            'action' => 'apiKeysCreate',
            'csrf_token' => 't',
            'name' => 'New key',
            'permissions' => ['wallet:read'],
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('hasSensitiveAccess')->willReturn(false);

        $this->repository->expects($this->never())->method('createKey');

        $result = $this->dispatch();

        $this->assertSame(401, $result['status']);
        $this->assertSame('sensitive_access_required', $result['payload']['error']);
    }

    #[Test]
    public function createReturnsSecretOnce(): void
    {
        $_POST = [
            'action' => 'apiKeysCreate',
            'csrf_token' => 't',
            'name' => 'New key',
            'permissions' => ['wallet:read', 'system:read'],
            'rate_limit_per_minute' => '50',
            'expires_in_days' => '0',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('hasSensitiveAccess')->willReturn(true);

        $this->repository->expects($this->once())
            ->method('createKey')
            ->with('New key', ['wallet:read', 'system:read'], 50, null)
            ->willReturn([
                'key_id' => 'eiou_' . str_repeat('a', 24),
                'secret' => str_repeat('s', 64),
                'name' => 'New key',
                'permissions' => ['wallet:read', 'system:read'],
                'rate_limit_per_minute' => 50,
                'expires_at' => null,
            ]);

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
        $this->assertSame(str_repeat('s', 64), $result['payload']['key']['secret']);
    }

    #[Test]
    public function createRejectsInvalidPermission(): void
    {
        $_POST = [
            'action' => 'apiKeysCreate',
            'csrf_token' => 't',
            'name' => 'Bad perms',
            'permissions' => ['not:a:real:permission'],
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('hasSensitiveAccess')->willReturn(true);

        $this->repository->expects($this->never())->method('createKey');

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_permission', $result['payload']['error']);
    }

    #[Test]
    public function createRejectsEmptyName(): void
    {
        $_POST = [
            'action' => 'apiKeysCreate',
            'csrf_token' => 't',
            'name' => '   ',
            'permissions' => ['wallet:read'],
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('hasSensitiveAccess')->willReturn(true);

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_name', $result['payload']['error']);
    }

    #[Test]
    public function deleteRejectsMalformedKeyId(): void
    {
        $_POST = [
            'action' => 'apiKeysDelete',
            'csrf_token' => 't',
            'key_id' => "eiou_' OR 1=1 --",
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('hasSensitiveAccess')->willReturn(true);

        $this->repository->expects($this->never())->method('deleteKey');

        $result = $this->dispatch();

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_key_id', $result['payload']['error']);
    }

    #[Test]
    public function deleteSucceedsOnKnownKey(): void
    {
        $keyId = 'eiou_' . str_repeat('b', 24);
        $_POST = [
            'action' => 'apiKeysDelete',
            'csrf_token' => 't',
            'key_id' => $keyId,
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('hasSensitiveAccess')->willReturn(true);

        $this->repository->expects($this->once())
            ->method('deleteKey')
            ->with($keyId)
            ->willReturn(true);

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['success']);
    }

    #[Test]
    public function toggleCallsDisableWhenFlagZero(): void
    {
        $keyId = 'eiou_' . str_repeat('c', 24);
        $_POST = [
            'action' => 'apiKeysToggle',
            'csrf_token' => 't',
            'key_id' => $keyId,
            'enable' => '0',
        ];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('hasSensitiveAccess')->willReturn(true);

        $this->repository->expects($this->once())
            ->method('disableKey')
            ->with($keyId)
            ->willReturn(true);

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['payload']['enabled']);
    }

    #[Test]
    public function statusReturnsSensitiveAccessSnapshot(): void
    {
        $_POST = ['action' => 'apiKeysStatus', 'csrf_token' => 't'];
        $this->session->method('validateCSRFToken')->willReturn(true);
        $this->session->method('hasSensitiveAccess')->willReturn(true);
        $this->session->method('sensitiveAccessSecondsRemaining')->willReturn(123);

        $result = $this->dispatch();

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['payload']['sensitive_access']);
        $this->assertSame(123, $result['payload']['seconds_remaining']);
    }
}
