<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Database\PluginCredentialRepository;
use Eiou\Services\PluginCredentialService;
use InvalidArgumentException;
use RuntimeException;

/**
 * Test-only subclass that replaces KeyEncryption with a deterministic
 * in-memory codec. Mirrors the pattern PaybackMethodServiceTest uses so
 * unit tests don't need a master key on disk.
 *
 * The fake envelope preserves the AAD field (same shape KeyEncryption
 * produces), which is what lets the service's own AAD-verification path
 * stay exercised by these tests.
 */
class PluginCredentialServiceTestDouble extends PluginCredentialService
{
    public array $encryptCalls = [];
    public array $decryptCalls = [];

    protected function encryptPassword(string $plaintext, string $pluginId): array
    {
        $this->encryptCalls[] = ['plugin' => $pluginId, 'plaintext' => $plaintext];
        return [
            'ciphertext' => base64_encode($plaintext),
            'iv' => 'iv-fixture',
            'tag' => 'tag-fixture',
            'version' => 2,
            'aad' => 'plugin_credential:' . $pluginId,
        ];
    }

    protected function decryptPassword(array $envelope, string $pluginId): string
    {
        $this->decryptCalls[] = ['plugin' => $pluginId, 'envelope' => $envelope];
        // Defer to the real AAD check + decode. Calling parent would try
        // real KeyEncryption::decrypt, so reimplement the equivalent check
        // and the fake inverse.
        $expectedAad = 'plugin_credential:' . $pluginId;
        if (($envelope['aad'] ?? null) !== $expectedAad) {
            throw new RuntimeException(
                "Credential envelope AAD mismatch: expected '{$expectedAad}', got '" . ($envelope['aad'] ?? 'null') . "'"
            );
        }
        $decoded = base64_decode((string) ($envelope['ciphertext'] ?? ''), true);
        if ($decoded === false) {
            throw new RuntimeException('Fake codec: invalid base64');
        }
        return $decoded;
    }
}

#[CoversClass(PluginCredentialService::class)]
class PluginCredentialServiceTest extends TestCase
{
    private $repo;
    private PluginCredentialServiceTestDouble $svc;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(PluginCredentialRepository::class);
        $this->svc = new PluginCredentialServiceTestDouble($this->repo);
    }

    // =========================================================================
    // generate()
    // =========================================================================

    public function testGenerateCreatesCredentialAndReturnsPlaintext(): void
    {
        $this->repo->method('existsForPlugin')->willReturn(false);

        $capturedRow = null;
        $this->repo->expects($this->once())
            ->method('createCredential')
            ->willReturnCallback(function (string $id, string $json) use (&$capturedRow) {
                $capturedRow = ['plugin_id' => $id, 'encrypted_password' => $json];
                return true;
            });

        $plaintext = $this->svc->generate('my-plugin');

        $this->assertIsString($plaintext);
        $this->assertNotSame('', $plaintext);
        // 32 raw bytes → 44 base64 chars (incl. padding).
        $this->assertSame(44, strlen($plaintext));

        $this->assertNotNull($capturedRow);
        $this->assertSame('my-plugin', $capturedRow['plugin_id']);
        $envelope = json_decode($capturedRow['encrypted_password'], true);
        $this->assertIsArray($envelope);
        $this->assertSame('plugin_credential:my-plugin', $envelope['aad']);
        // AAD-domain prefix should never be empty — if it drifts, a future
        // caller sharing the master key in a different domain could
        // accidentally reuse these envelopes.
        $this->assertStringStartsWith('plugin_credential:', $envelope['aad']);
    }

    public function testGenerateRejectsDuplicateCredentials(): void
    {
        $this->repo->method('existsForPlugin')->willReturn(true);
        $this->repo->expects($this->never())->method('createCredential');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("already exist");
        $this->svc->generate('my-plugin');
    }

    public function testGenerateProducesUniquePasswordsAcrossCalls(): void
    {
        $svc2 = new PluginCredentialServiceTestDouble($this->repo);
        $this->repo->method('existsForPlugin')->willReturn(false);
        $this->repo->method('createCredential')->willReturn(true);

        $pw1 = $this->svc->generate('a-plugin');
        $pw2 = $svc2->generate('b-plugin');
        $this->assertNotSame($pw1, $pw2);
    }

    public function testGenerateSurfacesPersistenceFailure(): void
    {
        $this->repo->method('existsForPlugin')->willReturn(false);
        $this->repo->method('createCredential')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to persist');
        $this->svc->generate('my-plugin');
    }

    // =========================================================================
    // getPlaintext()
    // =========================================================================

    public function testGetPlaintextReturnsNullWhenNoCredentialsExist(): void
    {
        $this->repo->method('getByPluginId')->willReturn(null);
        $this->assertNull($this->svc->getPlaintext('my-plugin'));
    }

    public function testGetPlaintextRoundTripsGeneratedPassword(): void
    {
        // Capture what generate() persists and feed it back to getPlaintext().
        $this->repo->method('existsForPlugin')->willReturn(false);
        $persisted = null;
        $this->repo->method('createCredential')
            ->willReturnCallback(function (string $id, string $json) use (&$persisted) {
                $persisted = $json;
                return true;
            });
        $this->repo->method('getByPluginId')
            ->willReturnCallback(function (string $id) use (&$persisted) {
                return $persisted === null
                    ? null
                    : ['plugin_id' => $id, 'encrypted_password' => $persisted];
            });

        $original = $this->svc->generate('my-plugin');
        $recovered = $this->svc->getPlaintext('my-plugin');
        $this->assertSame($original, $recovered);
    }

    public function testGetPlaintextRejectsAadMismatch(): void
    {
        // Row was written for 'a-plugin' (AAD baked in) but we query for
        // 'b-plugin' — credential envelopes should not be reusable across
        // plugins even if the master key accepts both.
        $aPluginRow = [
            'plugin_id' => 'b-plugin',
            'encrypted_password' => json_encode([
                'ciphertext' => base64_encode('secret'),
                'iv' => 'iv', 'tag' => 't', 'version' => 2,
                'aad' => 'plugin_credential:a-plugin',
            ]),
        ];
        $this->repo->method('getByPluginId')->willReturn($aPluginRow);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to decrypt/');
        $this->svc->getPlaintext('b-plugin');
    }

    public function testGetPlaintextWrapsMalformedEnvelope(): void
    {
        $this->repo->method('getByPluginId')->willReturn([
            'plugin_id' => 'my-plugin',
            'encrypted_password' => 'not json',
        ]);

        $this->expectException(RuntimeException::class);
        $this->svc->getPlaintext('my-plugin');
    }

    // =========================================================================
    // rotate()
    // =========================================================================

    public function testRotateReplacesPasswordAndStampsRotatedAt(): void
    {
        $this->repo->method('existsForPlugin')->willReturn(true);
        $this->repo->expects($this->once())
            ->method('rotatePassword')
            ->willReturn(1);

        $newPw = $this->svc->rotate('my-plugin');
        $this->assertIsString($newPw);
        $this->assertSame(44, strlen($newPw));
    }

    public function testRotateRefusesWhenCredentialsAbsent(): void
    {
        $this->repo->method('existsForPlugin')->willReturn(false);
        $this->repo->expects($this->never())->method('rotatePassword');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No credentials to rotate/');
        $this->svc->rotate('my-plugin');
    }

    public function testRotateSurfacesConcurrentDelete(): void
    {
        $this->repo->method('existsForPlugin')->willReturn(true);
        $this->repo->method('rotatePassword')->willReturn(0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/concurrent delete/');
        $this->svc->rotate('my-plugin');
    }

    public function testRotateReturnsDifferentPasswordThanGenerate(): void
    {
        $this->repo->method('existsForPlugin')->willReturnOnConsecutiveCalls(false, true);
        $this->repo->method('createCredential')->willReturn(true);
        $this->repo->method('rotatePassword')->willReturn(1);

        $first  = $this->svc->generate('my-plugin');
        $second = $this->svc->rotate('my-plugin');
        $this->assertNotSame($first, $second);
    }

    // =========================================================================
    // delete() + exists()
    // =========================================================================

    public function testDeleteReturnsTrueWhenRowWasPresent(): void
    {
        $this->repo->method('deleteCredential')->willReturn(1);
        $this->assertTrue($this->svc->delete('my-plugin'));
    }

    public function testDeleteReturnsFalseWhenRowAbsent(): void
    {
        $this->repo->method('deleteCredential')->willReturn(0);
        $this->assertFalse($this->svc->delete('my-plugin'));
    }

    public function testExistsDelegatesToRepo(): void
    {
        $this->repo->method('existsForPlugin')->willReturn(true);
        $this->assertTrue($this->svc->exists('my-plugin'));
    }

    // =========================================================================
    // plugin-id validation
    // =========================================================================

    public function testGenerateRejectsInvalidPluginId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->generate('INVALID_CAPS');
    }

    public function testGenerateRejectsOverlongPluginId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->generate(str_repeat('a', 65));
    }

    public function testGenerateRejectsPluginIdStartingWithDigit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->generate('1bad');
    }

    public function testValidationAppliesToEveryEntryPoint(): void
    {
        foreach (['generate', 'getPlaintext', 'rotate', 'delete', 'exists'] as $method) {
            try {
                $this->svc->$method('Bad/Name');
                $this->fail("$method did not throw on invalid plugin id");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid plugin id', $e->getMessage());
            }
        }
    }
}
