<?php
namespace Eiou\Tests\Services\Proxies;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\Proxies\IpcPaybackMethodTypeProxy;

#[CoversClass(IpcPaybackMethodTypeProxy::class)]
class IpcPaybackMethodTypeProxyTest extends TestCase
{
    /**
     * Stub dispatcher: takes (pluginId, envelope), returns a fixed
     * response. Tests configure $this->lastEnvelope / $this->response
     * before each call so assertions can inspect what the proxy
     * sent and pin the return shape.
     */
    private function makeProxy(?array $response, array &$captured): IpcPaybackMethodTypeProxy
    {
        return new IpcPaybackMethodTypeProxy(
            'my-plugin',
            'btc',
            [
                'id'    => 'btc',
                'label' => 'Bitcoin',
                'group' => 'crypto',
                'icon'  => 'fab fa-bitcoin',
            ],
            function (string $pluginId, array $envelope) use ($response, &$captured): ?array {
                $captured[] = ['plugin' => $pluginId, 'envelope' => $envelope];
                return $response;
            }
        );
    }

    // =========================================================================
    // Static methods — no IPC
    // =========================================================================

    public function testGetIdReturnsTypeIdWithoutDispatching(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(null, $captured);
        $this->assertSame('btc', $proxy->getId());
        $this->assertCount(0, $captured); // no IPC for getId
    }

    public function testGetCatalogEntryReturnsManifestRowWithoutDispatching(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(null, $captured);
        $catalog = $proxy->getCatalogEntry();
        $this->assertSame('btc', $catalog['id']);
        $this->assertSame('Bitcoin', $catalog['label']);
        $this->assertCount(0, $captured);
    }

    // =========================================================================
    // validate() — happy + failure paths
    // =========================================================================

    public function testValidateForwardsCallAndReturnsPluginErrors(): void
    {
        $captured = [];
        $proxy = $this->makeProxy([
            'ok' => true,
            'result' => [
                ['field' => 'address', 'code' => 'invalid_format', 'message' => 'Bad address'],
            ],
        ], $captured);

        $errors = $proxy->validate('BTC', ['address' => 'not-a-real-btc-address']);

        $this->assertCount(1, $captured);
        $this->assertSame('my-plugin', $captured[0]['plugin']);
        $this->assertSame('payback_method', $captured[0]['envelope']['type']);
        $this->assertSame('validate', $captured[0]['envelope']['name']);
        $this->assertSame('btc', $captured[0]['envelope']['context']['type_id']);
        $this->assertSame('BTC', $captured[0]['envelope']['context']['currency']);
        $this->assertSame(['address' => 'not-a-real-btc-address'], $captured[0]['envelope']['context']['fields']);

        $this->assertCount(1, $errors);
        $this->assertSame('address', $errors[0]['field']);
        $this->assertSame('invalid_format', $errors[0]['code']);
    }

    public function testValidateReturnsEmptyOnPluginSuccess(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(['ok' => true, 'result' => []], $captured);
        $this->assertSame([], $proxy->validate('BTC', ['address' => 'bc1q…']));
    }

    public function testValidateReturnsIpcFailureRecordOnTransportFailure(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(null, $captured); // null = IPC failed

        $errors = $proxy->validate('BTC', ['address' => 'bc1q…']);

        $this->assertCount(1, $errors);
        $this->assertNull($errors[0]['field']);
        $this->assertSame('plugin_ipc_failed', $errors[0]['code']);
        $this->assertStringContainsString("my-plugin", $errors[0]['message']);
    }

    public function testValidateReturnsMalformedRecordOnNonArrayResult(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(['ok' => true, 'result' => 'not an array'], $captured);

        $errors = $proxy->validate('BTC', []);

        $this->assertCount(1, $errors);
        $this->assertSame('plugin_response_malformed', $errors[0]['code']);
    }

    // =========================================================================
    // mask() — happy + failure paths
    // =========================================================================

    public function testMaskForwardsCallAndReturnsPluginString(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(['ok' => true, 'result' => 'bc1q…x8f2'], $captured);

        $masked = $proxy->mask(['address' => 'bc1q-very-long-address']);

        $this->assertCount(1, $captured);
        $this->assertSame('mask', $captured[0]['envelope']['name']);
        $this->assertSame(['address' => 'bc1q-very-long-address'], $captured[0]['envelope']['context']['fields']);
        $this->assertSame('bc1q…x8f2', $masked);
    }

    public function testMaskReturnsThreeDotFallbackOnIpcFailure(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(null, $captured);
        $this->assertSame('•••', $proxy->mask(['address' => 'whatever']));
    }

    public function testMaskReturnsThreeDotFallbackOnNonStringResult(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(['ok' => true, 'result' => ['weird' => 'shape']], $captured);
        $this->assertSame('•••', $proxy->mask(['address' => 'x']));
    }

    // =========================================================================
    // defaultPrecision() — happy + failure paths
    // =========================================================================

    public function testDefaultPrecisionForwardsCallAndReturnsPluginTuple(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(['ok' => true, 'result' => [1, -8]], $captured);

        $precision = $proxy->defaultPrecision('BTC');

        $this->assertSame([1, -8], $precision);
        $this->assertSame('defaultPrecision', $captured[0]['envelope']['name']);
        $this->assertSame('BTC', $captured[0]['envelope']['context']['currency']);
    }

    public function testDefaultPrecisionReturnsNullOnIpcFailure(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(null, $captured);
        $this->assertNull($proxy->defaultPrecision('BTC'));
    }

    public function testDefaultPrecisionReturnsNullOnWrongShape(): void
    {
        $captured = [];
        // Three elements instead of two.
        $proxy = $this->makeProxy(['ok' => true, 'result' => [1, -8, 'extra']], $captured);
        $this->assertNull($proxy->defaultPrecision('BTC'));
    }

    public function testDefaultPrecisionReturnsNullOnNonIntElements(): void
    {
        $captured = [];
        $proxy = $this->makeProxy(['ok' => true, 'result' => [1, '-8']], $captured);
        $this->assertNull($proxy->defaultPrecision('BTC'));
    }
}
