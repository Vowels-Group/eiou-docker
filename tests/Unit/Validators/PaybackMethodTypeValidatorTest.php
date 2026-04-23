<?php
namespace Eiou\Tests\Validators;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Validators\PaybackMethodTypeValidator;

#[CoversClass(PaybackMethodTypeValidator::class)]
class PaybackMethodTypeValidatorTest extends TestCase
{
    private PaybackMethodTypeValidator $v;

    protected function setUp(): void
    {
        $this->v = new PaybackMethodTypeValidator();
    }

    /** Convenience: return array of error codes. */
    private function codes(array $errors): array
    {
        return array_column($errors, 'code');
    }

    // =========================================================================
    // Meta
    // =========================================================================

    public function testUnknownTypeIsRejected(): void
    {
        // Core only ships bank_wire and custom. Unknown types (including
        // what will eventually become plugin-registered rails) are
        // rejected here so nothing gets silently persisted with no
        // validator attached.
        $errors = $this->v->validate('turbopay', 'USD', []);
        $this->assertContains('unknown_type', $this->codes($errors));
    }

    public function testNonsenseCurrencyRejected(): void
    {
        // Lowercase violates the 3–5 uppercase alpha format — caught by
        // the dispatcher before type-specific rules run, so the per-type
        // branches don't have to re-check.
        $errors = $this->v->validate('custom', 'usd', ['details' => 'x']);
        $this->assertContains('invalid_currency', $this->codes($errors));
    }

    // =========================================================================
    // Bank wire — all rails
    // =========================================================================

    public function testBankWireSepa(): void
    {
        $errors = $this->v->validate('bank_wire', 'EUR', [
            'rail' => 'sepa',
            'recipient_name' => 'Alice Smith',
            'iban' => 'DE89370400440532013000',
        ]);
        $this->assertSame([], $errors, print_r($errors, true));
    }

    public function testBankWireSepaRejectsUsd(): void
    {
        $errors = $this->v->validate('bank_wire', 'USD', [
            'rail' => 'sepa',
            'recipient_name' => 'x',
            'iban' => 'DE89370400440532013000',
        ]);
        $this->assertContains('invalid_currency_for_type', $this->codes($errors));
    }

    public function testBankWireFasterPayments(): void
    {
        $errors = $this->v->validate('bank_wire', 'GBP', [
            'rail' => 'faster_payments',
            'recipient_name' => 'Bob',
            'sort_code' => '60-40-05',
            'account_number' => '12345678',
        ]);
        $this->assertSame([], $errors);
    }

    public function testBankWireAch(): void
    {
        $errors = $this->v->validate('bank_wire', 'USD', [
            'rail' => 'ach',
            'recipient_name' => 'Carol',
            'routing_number' => '011000015',
            'account_number' => '1234567890',
            'account_type' => 'checking',
        ]);
        $this->assertSame([], $errors);
    }

    public function testBankWireAchRejectsBadRouting(): void
    {
        $errors = $this->v->validate('bank_wire', 'USD', [
            'rail' => 'ach',
            'recipient_name' => 'x',
            'routing_number' => '011000016', // last digit flipped from a valid 011000015
            'account_number' => '12345',
            'account_type' => 'checking',
        ]);
        $this->assertContains('invalid_checksum', $this->codes($errors));
    }

    public function testBankWireSwift(): void
    {
        $errors = $this->v->validate('bank_wire', 'MXN', [
            'rail' => 'swift',
            'recipient_name' => 'Diego',
            'bic_swift' => 'BBVAMXMMXXX',
            'bank_name' => 'BBVA Mexico',
            'country' => 'MX',
            'account_number' => '001234567890',
        ]);
        $this->assertSame([], $errors);
    }

    public function testBankWireSwiftRequiresIbanOrAccount(): void
    {
        $errors = $this->v->validate('bank_wire', 'USD', [
            'rail' => 'swift',
            'recipient_name' => 'x',
            'bic_swift' => 'BOFAUS3NXXX',
            'bank_name' => 'BofA',
            'country' => 'US',
        ]);
        $this->assertContains('required', $this->codes($errors));
    }

    public function testBankWireUnknownRail(): void
    {
        $errors = $this->v->validate('bank_wire', 'USD', ['rail' => 'fastpay']);
        $this->assertContains('invalid_value', $this->codes($errors));
    }

    // =========================================================================
    // Custom
    // =========================================================================

    public function testCustomValid(): void
    {
        // Custom accepts user-declared currency codes outside the canonical
        // fiat/crypto set — the service layer trusts the user here because
        // the "rail" is an out-of-band arrangement.
        $errors = $this->v->validate('custom', 'XRP', [
            'details' => 'Send XRP to destination address shared via email.',
        ]);
        $this->assertSame([], $errors);
    }

    public function testCustomRequiresDetails(): void
    {
        $errors = $this->v->validate('custom', 'USD', []);
        $this->assertContains('required', $this->codes($errors));
    }

    public function testCustomRejectsDetailsOver1024Chars(): void
    {
        $errors = $this->v->validate('custom', 'USD', [
            'details' => str_repeat('x', 1025),
        ]);
        $this->assertContains('too_long', $this->codes($errors));
    }

    // =========================================================================
    // Catalog — UI metadata consumed by the GUI form renderer
    // =========================================================================

    public function testCatalogHasExpectedTopLevelShape(): void
    {
        $catalog = PaybackMethodTypeValidator::getCatalog();
        $this->assertIsArray($catalog);
        $this->assertArrayHasKey('groups',     $catalog);
        $this->assertArrayHasKey('types',      $catalog);
        $this->assertArrayHasKey('currencies', $catalog);
        $this->assertNotEmpty($catalog['groups']);
        $this->assertNotEmpty($catalog['types']);
        $this->assertNotEmpty($catalog['currencies']);
    }

    public function testCatalogCoversEveryDeclaredType(): void
    {
        $declared = [
            PaybackMethodTypeValidator::TYPE_BANK_WIRE,
            PaybackMethodTypeValidator::TYPE_CUSTOM,
        ];
        $catalog = PaybackMethodTypeValidator::getCatalog();
        $catalogIds = array_column($catalog['types'], 'id');
        foreach ($declared as $typeId) {
            $this->assertContains($typeId, $catalogIds, "Catalog missing entry for type '$typeId'");
        }
        $this->assertCount(count($declared), $catalog['types'],
            'Catalog has entries for types not declared as class constants');
    }

    public function testCatalogEveryTypeHasRequiredKeysAndValidGroup(): void
    {
        $catalog = PaybackMethodTypeValidator::getCatalog();
        $groupIds = array_column($catalog['groups'], 'id');
        foreach ($catalog['types'] as $type) {
            $this->assertArrayHasKey('id', $type);
            $this->assertArrayHasKey('label', $type);
            $this->assertArrayHasKey('group', $type);
            $this->assertArrayHasKey('fields', $type);
            $this->assertIsArray($type['fields']);
            $this->assertNotEmpty($type['fields'], "Type '{$type['id']}' has no fields");
            $this->assertContains($type['group'], $groupIds,
                "Type '{$type['id']}' references unknown group '{$type['group']}'");
        }
    }

    public function testCatalogEveryFieldHasRequiredKeys(): void
    {
        $catalog = PaybackMethodTypeValidator::getCatalog();
        $validFieldTypes = ['text', 'select', 'email', 'tel', 'number', 'textarea'];
        foreach ($catalog['types'] as $type) {
            foreach ($type['fields'] as $field) {
                $this->assertArrayHasKey('name',     $field);
                $this->assertArrayHasKey('label',    $field);
                $this->assertArrayHasKey('type',     $field);
                $this->assertArrayHasKey('required', $field);
                $this->assertIsBool($field['required']);
                $this->assertContains($field['type'], $validFieldTypes,
                    "Field '{$field['name']}' in type '{$type['id']}' has unknown type '{$field['type']}'");
                if ($field['type'] === 'select') {
                    $this->assertArrayHasKey('options', $field,
                        "Select field '{$field['name']}' in type '{$type['id']}' has no options");
                    $this->assertNotEmpty($field['options']);
                }
                if (isset($field['showWhen'])) {
                    $this->assertArrayHasKey('field', $field['showWhen']);
                    $this->assertArrayHasKey('in',    $field['showWhen']);
                    $this->assertIsArray($field['showWhen']['in']);
                }
            }
        }
    }

    public function testCatalogShowWhenFieldsReferenceSiblingFields(): void
    {
        $catalog = PaybackMethodTypeValidator::getCatalog();
        foreach ($catalog['types'] as $type) {
            $names = array_column($type['fields'], 'name');
            foreach ($type['fields'] as $field) {
                if (!isset($field['showWhen'])) {
                    continue;
                }
                $this->assertContains($field['showWhen']['field'], $names,
                    "Field '{$field['name']}' in type '{$type['id']}' references unknown sibling " .
                    "'{$field['showWhen']['field']}' in showWhen");
            }
        }
    }

    public function testCatalogCurrenciesForReferencesExistingField(): void
    {
        $catalog = PaybackMethodTypeValidator::getCatalog();
        foreach ($catalog['types'] as $type) {
            if (!isset($type['currenciesFor'])) {
                continue;
            }
            $this->assertArrayHasKey('field', $type['currenciesFor']);
            $this->assertArrayHasKey('map',   $type['currenciesFor']);
            $names = array_column($type['fields'], 'name');
            $this->assertContains($type['currenciesFor']['field'], $names,
                "Type '{$type['id']}' currenciesFor references non-existent field '{$type['currenciesFor']['field']}'");
        }
    }
}
