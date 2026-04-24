<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Tests\Services;

use Eiou\Contracts\PaybackMethodTypeContract;
use Eiou\Services\PaybackMethodTypeRegistry;
use Eiou\Services\SettlementPrecisionService;
use Eiou\Validators\PaybackMethodTypeValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the plugin-registration surface for payback-method rail types:
 *   - registry enforces id shape + no core-id shadowing + no duplicates
 *   - validator dispatches unknown types to a registered contract
 *   - validator catalog merges registered entries alongside core ones
 *   - precision service consults the contract before generic fallback
 */
class PaybackMethodTypeRegistryTest extends TestCase
{
    public function testRegisterRejectsMalformedId(): void
    {
        $reg = new PaybackMethodTypeRegistry();
        $this->expectException(InvalidArgumentException::class);
        $reg->register($this->stubType('Bad-Id'));
    }

    public function testRegisterRejectsCoreShadowing(): void
    {
        $reg = new PaybackMethodTypeRegistry();
        $this->expectException(InvalidArgumentException::class);
        $reg->register($this->stubType('bank_wire'));
    }

    public function testRegisterRejectsDuplicate(): void
    {
        $reg = new PaybackMethodTypeRegistry();
        $reg->register($this->stubType('btc'));
        $this->expectException(InvalidArgumentException::class);
        $reg->register($this->stubType('btc'));
    }

    public function testGetAndHasReturnRegisteredType(): void
    {
        $reg = new PaybackMethodTypeRegistry();
        $t = $this->stubType('btc');
        $reg->register($t);
        $this->assertTrue($reg->has('btc'));
        $this->assertSame($t, $reg->get('btc'));
        $this->assertFalse($reg->has('paypal'));
        $this->assertNull($reg->get('paypal'));
    }

    public function testValidatorDispatchesUnknownTypeToRegistry(): void
    {
        $reg = new PaybackMethodTypeRegistry();
        $reg->register(new class implements PaybackMethodTypeContract {
            public function getId(): string { return 'btc'; }
            public function getCatalogEntry(): array { return ['id' => 'btc']; }
            public function validate(string $currency, array $fields): array {
                return ($currency === 'BTC' && !empty($fields['address']))
                    ? []
                    : [['field' => 'address', 'code' => 'required', 'message' => 'address required']];
            }
            public function mask(array $fields): string { return 'bc1q…'; }
            public function defaultPrecision(string $currency): ?array { return null; }
        });

        $validator = new PaybackMethodTypeValidator($reg);
        $this->assertSame([], $validator->validate('btc', 'BTC', ['address' => 'bc1q...xyz']));
        $errs = $validator->validate('btc', 'BTC', []);
        $this->assertNotEmpty($errs);
        $this->assertSame('address', $errs[0]['field']);
    }

    public function testValidatorFallsBackToUnknownTypeWhenRegistryMisses(): void
    {
        $validator = new PaybackMethodTypeValidator(new PaybackMethodTypeRegistry());
        $errs = $validator->validate('btc', 'BTC', ['address' => 'x']);
        $this->assertCount(1, $errs);
        $this->assertSame('unknown_type', $errs[0]['code']);
    }

    public function testCatalogMergesRegisteredTypeAndInjectsGroup(): void
    {
        $reg = new PaybackMethodTypeRegistry();
        $reg->register(new class implements PaybackMethodTypeContract {
            public function getId(): string { return 'btc'; }
            public function getCatalogEntry(): array {
                return [
                    'id' => 'btc', 'label' => 'Bitcoin', 'group' => 'crypto',
                    'icon' => 'fab fa-bitcoin', 'description' => '',
                    'currencies' => ['BTC'],
                    'fields' => [['name' => 'address', 'label' => 'Address', 'type' => 'text', 'required' => true]],
                ];
            }
            public function validate(string $c, array $f): array { return []; }
            public function mask(array $f): string { return ''; }
            public function defaultPrecision(string $c): ?array { return null; }
        });

        $catalog = (new PaybackMethodTypeValidator($reg))->getCatalog();
        $typeIds = array_column($catalog['types'], 'id');
        $groupIds = array_column($catalog['groups'], 'id');

        // Core types still present.
        $this->assertContains('bank_wire', $typeIds);
        $this->assertContains('custom', $typeIds);
        // Registered type appears.
        $this->assertContains('btc', $typeIds);
        // New group id is added (core has bank + other).
        $this->assertContains('crypto', $groupIds);
    }

    public function testPrecisionServicePrefersContractOverride(): void
    {
        $reg = new PaybackMethodTypeRegistry();
        $reg->register(new class implements PaybackMethodTypeContract {
            public function getId(): string { return 'btc'; }
            public function getCatalogEntry(): array { return ['id' => 'btc']; }
            public function validate(string $c, array $f): array { return []; }
            public function mask(array $f): string { return ''; }
            public function defaultPrecision(string $currency): ?array {
                return $currency === 'BTC' ? [1, -8] : null;
            }
        });

        $svc = new SettlementPrecisionService($reg);
        $this->assertSame([1, -8], $svc->defaultFor('btc', 'BTC'));
        // Unknown currency on the same type → contract returns null → generic fallback.
        $this->assertSame([1, -2], $svc->defaultFor('btc', 'USD'));
    }

    /** Minimal stub for id-validation tests. Validate/mask aren't exercised. */
    private function stubType(string $id): PaybackMethodTypeContract
    {
        return new class($id) implements PaybackMethodTypeContract {
            public function __construct(private string $id) {}
            public function getId(): string { return $this->id; }
            public function getCatalogEntry(): array { return ['id' => $this->id]; }
            public function validate(string $c, array $f): array { return []; }
            public function mask(array $f): string { return ''; }
            public function defaultPrecision(string $c): ?array { return null; }
        };
    }
}
