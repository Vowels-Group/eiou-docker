<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\PaybackMethodRepository;
use Eiou\Services\PaybackMethodService;

/**
 * Test-only subclass that replaces the KeyEncryption dependency with a
 * deterministic no-op codec so the service's encryption boundary can be
 * exercised without a master key file on disk.
 */
class PaybackMethodServiceTestDouble extends PaybackMethodService
{
    public array $lastEncryptedForMethodId = [];

    protected function encryptFields(string $methodId, array $fields): array
    {
        $this->lastEncryptedForMethodId[$methodId] = $fields;
        // Return a shape that looks like KeyEncryption::encrypt() output.
        return [
            'ciphertext' => base64_encode(json_encode($fields)),
            'iv' => 'iv-fixture',
            'tag' => 'tag-fixture',
            'version' => 2,
            'aad' => "payback:$methodId",
        ];
    }

    protected function decryptFields(array $row): array
    {
        $raw = $row['encrypted_fields'] ?? null;
        $blob = is_array($raw) ? $raw : json_decode($raw ?? '', true);
        if (!is_array($blob) || !isset($blob['ciphertext'])) {
            return [];
        }
        $plain = base64_decode($blob['ciphertext'], true);
        $decoded = json_decode($plain ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }
}

#[CoversClass(PaybackMethodService::class)]
class PaybackMethodServiceTest extends TestCase
{
    private PaybackMethodRepository $repo;
    private PaybackMethodServiceTestDouble $svc;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(PaybackMethodRepository::class);
        $this->svc = new PaybackMethodServiceTestDouble($this->repo);
    }

    // =========================================================================
    // add()
    // =========================================================================

    public function testAddBankWireSuccess(): void
    {
        $this->repo->expects($this->once())
            ->method('createMethod')
            ->willReturnCallback(function (array $row) {
                // Ensure mandatory columns are set.
                $this->assertArrayHasKey('method_id', $row);
                $this->assertArrayHasKey('encrypted_fields', $row);
                $this->assertEquals('bank_wire', $row['type']);
                $this->assertEquals('EUR', $row['currency']);
                // Fiat → cent precision from the generic fallback.
                $this->assertEquals(1, $row['settlement_min_unit']);
                $this->assertEquals(-2, $row['settlement_min_unit_exponent']);
                $blob = json_decode($row['encrypted_fields'], true);
                $this->assertStringContainsString('payback:', $blob['aad']);
                return '1';
            });

        $res = $this->svc->add('bank_wire', 'Main SEPA account', 'EUR', [
            'rail' => 'sepa',
            'recipient_name' => 'Alice Smith',
            'iban' => 'DE89370400440532013000',
        ]);
        $this->assertSame([], $res['errors']);
        $this->assertNotEmpty($res['method_id']);
    }

    public function testAddRejectsInvalidCurrencyForType(): void
    {
        // SEPA on USD — the per-rail binding in the validator must reject
        // before anything touches the repository.
        $this->repo->expects($this->never())->method('createMethod');
        $res = $this->svc->add('bank_wire', 'x', 'USD', [
            'rail' => 'sepa',
            'recipient_name' => 'x',
            'iban' => 'DE89370400440532013000',
        ]);
        $this->assertNotEmpty($res['errors']);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains('invalid_currency_for_type', $codes);
    }

    public function testAddRejectsBadSharePolicy(): void
    {
        $res = $this->svc->add('custom', 'x', 'USD', ['details' => 'pay me'], 'maybe');
        $codes = array_column($res['errors'], 'code');
        $this->assertContains('invalid_value', $codes);
    }

    public function testAddRejectsEmptyLabel(): void
    {
        $res = $this->svc->add('custom', '', 'USD', ['details' => 'pay me']);
        $codes = array_column($res['errors'], 'code');
        $this->assertContains('invalid_value', $codes);
    }

    public function testAddCustomUsesFiatCentPrecision(): void
    {
        // Custom + ISO fiat → cent-level precision via genericFor().
        // Plugin-registered rails will override this by shipping their
        // own SettlementPrecisionService entries.
        $this->repo->expects($this->once())
            ->method('createMethod')
            ->willReturnCallback(function (array $row) {
                $this->assertEquals('custom', $row['type']);
                $this->assertEquals(-2, $row['settlement_min_unit_exponent']);
                return '1';
            });
        $this->svc->add('custom', 'Venmo cash handoff', 'USD', [
            'details' => 'DM me @alice on Venmo once you have the amount.',
        ]);
    }

    // =========================================================================
    // list / get / masking
    // =========================================================================

    public function testListMasksBankWireIban(): void
    {
        // bank_wire's mask shows `••••` + last 4 of the IBAN. Full IBAN
        // must never be present in the public-shape row.
        $this->repo->method('listMethods')->willReturn([[
            'method_id' => 'm-1',
            'type' => 'bank_wire',
            'label' => 'Main SEPA',
            'currency' => 'EUR',
            'priority' => 10,
            'enabled' => 1,
            'share_policy' => 'auto',
            'settlement_min_unit' => 1,
            'settlement_min_unit_exponent' => -2,
            'encrypted_fields' => json_encode([
                'ciphertext' => base64_encode(json_encode([
                    'rail' => 'sepa',
                    'recipient_name' => 'Alice Smith',
                    'iban' => 'DE89370400440532013000',
                ])),
                'iv' => '', 'tag' => '', 'version' => 2, 'aad' => 'payback:m-1',
            ]),
            'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
        ]]);

        $rows = $this->svc->list();
        $this->assertCount(1, $rows);
        $mask = $rows[0]['masked_display'];
        // Shows the last 4 of the IBAN, rest redacted.
        $this->assertStringContainsString('••••', $mask);
        $this->assertStringEndsWith('3000', $mask);
        // Full plaintext fields never appear in the public shape.
        $this->assertArrayNotHasKey('fields', $rows[0]);
    }

    public function testGetReturnsPublicShapeOnly(): void
    {
        // Custom type: mask shows first 8 chars of the details free text.
        $this->repo->method('getByMethodId')->willReturn([
            'method_id' => 'm-2', 'type' => 'custom', 'label' => 'personal',
            'currency' => 'EUR', 'priority' => 100, 'enabled' => 1,
            'share_policy' => 'auto',
            'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -2,
            'encrypted_fields' => json_encode([
                'ciphertext' => base64_encode(json_encode([
                    'details' => 'DM me first, I will share the QR code.',
                ])),
                'iv' => '', 'tag' => '', 'version' => 2, 'aad' => 'payback:m-2',
            ]),
            'created_at' => null, 'updated_at' => null,
        ]);
        $row = $this->svc->get('m-2');
        $this->assertArrayNotHasKey('fields', $row);
        // `custom` is user-authored free text — short details (≤ 80 chars)
        // render as-is; only longer ones get a trailing ellipsis.
        $this->assertSame('DM me first, I will share the QR code.', $row['masked_display']);
    }

    public function testGetRevealIncludesFullFields(): void
    {
        $this->repo->method('getByMethodId')->willReturn([
            'method_id' => 'm-3', 'type' => 'custom', 'label' => 'x',
            'currency' => 'USD', 'priority' => 100, 'enabled' => 1,
            'share_policy' => 'auto',
            'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -2,
            'encrypted_fields' => json_encode([
                'ciphertext' => base64_encode(json_encode([
                    'details' => 'Pay via cash in person.',
                ])),
                'iv' => '', 'tag' => '', 'version' => 2, 'aad' => 'payback:m-3',
            ]),
        ]);
        $row = $this->svc->getReveal('m-3');
        // Reveal returns the full plaintext fields — the whole point of
        // the reveal gate.
        $this->assertSame('Pay via cash in person.', $row['fields']['details']);
    }

    public function testGetMissingReturnsNull(): void
    {
        $this->repo->method('getByMethodId')->willReturn(null);
        $this->assertNull($this->svc->get('nope'));
    }

    // =========================================================================
    // update()
    // =========================================================================

    public function testUpdateLabelOnly(): void
    {
        $this->repo->method('getByMethodId')->willReturn([
            'method_id' => 'm', 'type' => 'paypal', 'currency' => 'USD',
        ]);
        $this->repo->expects($this->once())
            ->method('updateByMethodId')
            ->with('m', $this->callback(fn($d) => $d === ['label' => 'NewName']))
            ->willReturn(1);

        $errors = $this->svc->update('m', ['label' => 'NewName']);
        $this->assertSame([], $errors);
    }

    public function testUpdateFieldsRevalidates(): void
    {
        $this->repo->method('getByMethodId')->willReturn([
            'method_id' => 'm', 'type' => 'bank_wire', 'currency' => 'USD',
        ]);
        // Update tries to swap in a malformed ACH routing number — the
        // ABA-checksum check in the bank_wire validator must reject it and
        // no DB update may happen.
        $this->repo->expects($this->never())->method('updateByMethodId');

        $errors = $this->svc->update('m', [
            'fields' => [
                'rail' => 'ach',
                'recipient_name' => 'x',
                'routing_number' => '011000016', // checksum-invalid
                'account_number' => '12345',
                'account_type' => 'checking',
            ],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('invalid_checksum', $codes);
    }

    public function testUpdateSharePolicyNoop(): void
    {
        $this->repo->method('getByMethodId')->willReturn([
            'method_id' => 'm', 'type' => 'paypal', 'currency' => 'USD',
        ]);
        $this->repo->expects($this->once())
            ->method('updateByMethodId')
            ->with('m', $this->callback(fn($d) => $d === ['share_policy' => 'never']));
        $errors = $this->svc->update('m', ['share_policy' => 'never']);
        $this->assertSame([], $errors);
    }

    public function testUpdateMissingReturnsNotFound(): void
    {
        $this->repo->method('getByMethodId')->willReturn(null);
        $errors = $this->svc->update('nope', ['label' => 'x']);
        $codes = array_column($errors, 'code');
        $this->assertContains('not_found', $codes);
    }

    // =========================================================================
    // remove / setSharePolicy
    // =========================================================================

    public function testRemoveExisting(): void
    {
        $this->repo->method('getByMethodId')->willReturn([
            'method_id' => 'm', 'type' => 'btc', 'currency' => 'BTC',
        ]);
        $this->repo->method('deleteByMethodId')->willReturn(1);
        $this->assertTrue($this->svc->remove('m'));
    }

    public function testRemoveMissing(): void
    {
        $this->repo->method('getByMethodId')->willReturn(null);
        $this->assertFalse($this->svc->remove('nope'));
    }

    public function testSetSharePolicyValid(): void
    {
        $this->repo->method('getByMethodId')->willReturn([
            'method_id' => 'm', 'type' => 'paypal', 'currency' => 'USD',
        ]);
        $this->repo->expects($this->once())->method('updateByMethodId');
        $this->assertSame([], $this->svc->setSharePolicy('m', 'never'));
    }

    public function testSetSharePolicyRejectsInvalid(): void
    {
        $errors = $this->svc->setSharePolicy('m', 'bogus');
        $this->assertNotEmpty($errors);
        $this->assertEquals('invalid_value', $errors[0]['code']);
    }

    // =========================================================================
    // listShareable()
    // =========================================================================

    public function testListShareableWithCurrency(): void
    {
        $this->repo->expects($this->once())
            ->method('listShareableForCurrency')
            ->with('USD')
            ->willReturn([[
                'method_id' => 'm',
                'type' => 'paypal', 'label' => 'x', 'currency' => 'USD',
                'priority' => 100, 'share_policy' => 'auto',
                'settlement_min_unit' => 1, 'settlement_min_unit_exponent' => -2,
                'encrypted_fields' => json_encode([
                    'ciphertext' => base64_encode(json_encode(['email' => 'a@b.c'])),
                    'iv' => '', 'tag' => '', 'version' => 2, 'aad' => 'payback:m',
                ]),
            ]]);
        $rows = $this->svc->listShareable('USD');
        $this->assertCount(1, $rows);
        $this->assertSame('a@b.c', $rows[0]['fields']['email']);
    }

    public function testListShareableWithoutCurrencyCallsListAll(): void
    {
        $this->repo->expects($this->once())->method('listAllShareable')->willReturn([]);
        $this->svc->listShareable(null);
    }
}
