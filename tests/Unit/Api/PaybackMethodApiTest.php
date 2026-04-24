<?php
namespace Eiou\Tests\Api;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Api\ApiController;
use Eiou\Services\PaybackMethodService;

/**
 * Covers the payback-methods resource handler inside ApiController.
 *
 * Auth is bypassed by directly calling the private `handlePaybackMethods`
 * method via reflection with a pre-populated `authenticatedKey` — unit
 * tests would otherwise need a full ApiAuthService fixture.
 */
#[CoversClass(ApiController::class)]
class PaybackMethodApiTest extends TestCase
{
    private ApiController $controller;
    private PaybackMethodService $svc;

    protected function setUp(): void
    {
        $this->svc = $this->createMock(PaybackMethodService::class);

        $services = new class ($this->svc) {
            public function __construct(public $svc) {}
            public function getPaybackMethodService() { return $this->svc; }
        };

        // Minimal authService that matches the hasPermission($key, $permission)
        // signature the real ApiAuthService exposes.
        $authService = new class {
            public function hasPermission(array $key, string $permission): bool
            {
                return in_array($permission, $key['permissions'] ?? [], true)
                    || in_array('admin', $key['permissions'] ?? [], true)
                    || in_array('all', $key['permissions'] ?? [], true);
            }
        };

        $this->controller = new ApiController(
            /* authService */ $authService,
            /* apiKeyRepository */ new \stdClass(),
            /* services */ $services,
            /* logger */ null
        );
    }

    /** Seed an authenticated key with the given permissions. */
    private function authAs(array $permissions): void
    {
        $ref = new \ReflectionObject($this->controller);
        $prop = $ref->getProperty('authenticatedKey');
        $prop->setAccessible(true);
        $prop->setValue($this->controller, ['key_id' => 'test', 'permissions' => $permissions]);
    }

    /** Invoke the private dispatcher. */
    private function dispatch(string $method, ?string $action, ?string $id, array $params = [], string $body = ''): array
    {
        $ref = new \ReflectionObject($this->controller);
        $m = $ref->getMethod('handlePaybackMethods');
        $m->setAccessible(true);
        return $m->invoke($this->controller, $method, $action, $id, $params, $body);
    }

    // =========================================================================
    // GET /api/v1/payback-methods
    // =========================================================================

    public function testListRequiresReadPermission(): void
    {
        $this->authAs(['contacts:read']);
        $res = $this->dispatch('GET', null, null);
        $this->assertFalse($res['success']);
        $this->assertEquals(403, $res['status_code']);
    }

    public function testListWithReadPermission(): void
    {
        $this->authAs(['payback:read']);
        $this->svc->method('list')->willReturn([[
            'method_id' => 'm-1', 'type' => 'btc', 'currency' => 'BTC',
        ]]);
        $res = $this->dispatch('GET', null, null);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $res['data']['methods']);
    }

    public function testListFiltersByCurrencyParam(): void
    {
        $this->authAs(['payback:read']);
        $this->svc->expects($this->once())
            ->method('list')
            ->with('EUR', true)
            ->willReturn([]);
        $this->dispatch('GET', null, null, ['currency' => 'eur']);
    }

    public function testListIncludesDisabledWhenAllEquals1(): void
    {
        $this->authAs(['payback:read']);
        $this->svc->expects($this->once())
            ->method('list')
            ->with(null, false)
            ->willReturn([]);
        $this->dispatch('GET', null, null, ['all' => '1']);
    }

    // =========================================================================
    // POST /api/v1/payback-methods
    // =========================================================================

    public function testCreateRequiresWritePermission(): void
    {
        $this->authAs(['payback:read']); // read but not write
        $res = $this->dispatch('POST', null, null);
        $this->assertFalse($res['success']);
        $this->assertEquals(403, $res['status_code']);
    }

    public function testCreateInvalidJsonBody(): void
    {
        $this->authAs(['payback:write']);
        $res = $this->dispatch('POST', null, null, [], 'not-json');
        $this->assertEquals(400, $res['status_code']);
        $this->assertEquals('invalid_json', $res['error']['code'] ?? null);
    }

    public function testCreateMissingRequiredField(): void
    {
        $this->authAs(['payback:write']);
        $res = $this->dispatch('POST', null, null, [], json_encode(['type' => 'btc']));
        $this->assertEquals(400, $res['status_code']);
        $this->assertEquals('missing_field', $res['error']['code'] ?? null);
    }

    public function testCreateHappyPath(): void
    {
        $this->authAs(['payback:write']);
        $this->svc->expects($this->once())
            ->method('add')
            ->with('btc', 'Cold', 'BTC', $this->hasFieldKey('address'))
            ->willReturn(['method_id' => 'new-id', 'errors' => []]);
        $res = $this->dispatch('POST', null, null, [], json_encode([
            'type' => 'btc', 'label' => 'Cold', 'currency' => 'btc',
            'fields' => ['address' => 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq'],
        ]));
        $this->assertTrue($res['success']);
        $this->assertEquals(201, $res['status_code']);
    }

    public function testCreatePropagatesValidationErrors(): void
    {
        $this->authAs(['payback:write']);
        $this->svc->method('add')->willReturn([
            'method_id' => '',
            'errors' => [['field' => 'address', 'code' => 'required', 'message' => 'x']],
        ]);
        $res = $this->dispatch('POST', null, null, [], json_encode([
            'type' => 'btc', 'label' => 'x', 'currency' => 'BTC', 'fields' => [],
        ]));
        $this->assertEquals(400, $res['status_code']);
    }

    // =========================================================================
    // GET /api/v1/payback-methods/{id}
    // =========================================================================

    public function testGetSingleRequiresReadPermission(): void
    {
        $this->authAs(['system:read']);
        $res = $this->dispatch('GET', 'some-id', null);
        $this->assertEquals(403, $res['status_code']);
    }

    public function testGetSingleNotFound(): void
    {
        $this->authAs(['payback:read']);
        $this->svc->method('get')->willReturn(null);
        $res = $this->dispatch('GET', 'missing', null);
        $this->assertEquals(404, $res['status_code']);
    }

    public function testGetSingleFound(): void
    {
        $this->authAs(['payback:read']);
        $this->svc->method('get')->willReturn([
            'method_id' => 'm', 'type' => 'btc', 'masked_display' => '•••',
        ]);
        $res = $this->dispatch('GET', 'm', null);
        $this->assertTrue($res['success']);
    }

    // =========================================================================
    // GET /api/v1/payback-methods/{id}/reveal
    // =========================================================================

    public function testRevealRequiresWritePermission(): void
    {
        $this->authAs(['payback:read']); // read is insufficient for reveal
        $res = $this->dispatch('GET', 'm', 'reveal');
        $this->assertEquals(403, $res['status_code']);
    }

    public function testRevealReturnsFullFields(): void
    {
        $this->authAs(['payback:write']);
        $this->svc->method('getReveal')->willReturn([
            'method_id' => 'm', 'type' => 'paypal',
            'fields' => ['email' => 'alice@example.com'],
        ]);
        $res = $this->dispatch('GET', 'm', 'reveal');
        $this->assertTrue($res['success']);
        $this->assertEquals('alice@example.com', $res['data']['method']['fields']['email']);
    }

    public function testRevealNotFound(): void
    {
        $this->authAs(['payback:write']);
        $this->svc->method('getReveal')->willReturn(null);
        $res = $this->dispatch('GET', 'missing', 'reveal');
        $this->assertEquals(404, $res['status_code']);
    }

    // =========================================================================
    // PUT /api/v1/payback-methods/{id}
    // =========================================================================

    public function testUpdateHappyPath(): void
    {
        $this->authAs(['payback:write']);
        $this->svc->expects($this->once())
            ->method('update')
            ->with('m', ['label' => 'NewName', 'priority' => 1])
            ->willReturn([]);
        $res = $this->dispatch('PUT', 'm', null, [], json_encode([
            'label' => 'NewName', 'priority' => 1,
        ]));
        $this->assertTrue($res['success']);
    }

    public function testUpdatePropagatesNotFound(): void
    {
        $this->authAs(['payback:write']);
        $this->svc->method('update')->willReturn([
            ['field' => 'method_id', 'code' => 'not_found', 'message' => 'no'],
        ]);
        $res = $this->dispatch('PUT', 'nope', null, [], json_encode(['label' => 'x']));
        $this->assertEquals(404, $res['status_code']);
    }

    // =========================================================================
    // DELETE /api/v1/payback-methods/{id}
    // =========================================================================

    public function testDeleteHappyPath(): void
    {
        $this->authAs(['payback:write']);
        $this->svc->method('remove')->willReturn(true);
        $res = $this->dispatch('DELETE', 'm', null);
        $this->assertTrue($res['success']);
        $this->assertTrue($res['data']['deleted']);
    }

    public function testDeleteNotFound(): void
    {
        $this->authAs(['payback:write']);
        $this->svc->method('remove')->willReturn(false);
        $res = $this->dispatch('DELETE', 'nope', null);
        $this->assertEquals(404, $res['status_code']);
    }

    // =========================================================================
    // PUT /api/v1/payback-methods/{id}/share-policy
    // =========================================================================

    public function testSetSharePolicyHappyPath(): void
    {
        $this->authAs(['payback:write']);
        $this->svc->expects($this->once())
            ->method('setSharePolicy')
            ->with('m', 'never')
            ->willReturn([]);
        $res = $this->dispatch('PUT', 'm', 'share-policy', [], json_encode(['share_policy' => 'never']));
        $this->assertTrue($res['success']);
    }

    public function testSetSharePolicyMissingField(): void
    {
        $this->authAs(['payback:write']);
        $res = $this->dispatch('PUT', 'm', 'share-policy', [], '{}');
        $this->assertEquals(400, $res['status_code']);
    }

    // =========================================================================
    // Unknown routes
    // =========================================================================

    public function testUnknownSubActionReturns404(): void
    {
        $this->authAs(['payback:write']);
        $res = $this->dispatch('POST', 'm', 'detonate');
        $this->assertEquals(404, $res['status_code']);
    }

    public function testBareResourceWithNonGetPostReturns405(): void
    {
        $this->authAs(['payback:write']);
        $res = $this->dispatch('DELETE', null, null);
        $this->assertEquals(405, $res['status_code']);
    }

    /** Helper to match any array with a given key (name avoids the final Assert::arrayHasKey). */
    private function hasFieldKey(string $key): \PHPUnit\Framework\Constraint\Callback
    {
        return $this->callback(fn($arg) => is_array($arg) && array_key_exists($key, $arg));
    }
}
