<?php
namespace Eiou\Tests\Services;

use Eiou\Events\EventDispatcher;
use Eiou\Services\PluginInstallService;
use Eiou\Services\PluginSignatureVerifier;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Covers the zip-upload validation pipeline end to end.
 *
 * Strategy: build real zip files in a tmp dir, hand them to the service,
 * and inspect the staged result. Validation rules are the security
 * contract; each gate gets its own test so regressions are pinpointable.
 */
#[CoversClass(PluginInstallService::class)]
class PluginInstallServiceTest extends TestCase
{
    private string $tmpRoot;
    private string $pluginDir;
    private PluginInstallService $svc;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/inst-test-' . uniqid('', true);
        $this->pluginDir = $this->tmpRoot . '/plugins';
        mkdir($this->pluginDir, 0777, true);
        EventDispatcher::resetInstance();
        $this->svc = new PluginInstallService($this->pluginDir);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        EventDispatcher::resetInstance();
    }

    // ===================================================================
    // Happy path
    // ===================================================================

    #[Test]
    public function installsValidPluginZip(): void
    {
        $zip = $this->buildZip([
            'my-plugin/plugin.json' => json_encode([
                'name' => 'my-plugin',
                'version' => '1.0.0',
                'entryClass' => 'My\\Plugin\\Entry',
                'sandboxed' => true,
            ]),
            'my-plugin/src/Entry.php' => "<?php\nclass Entry {}\n",
            'my-plugin/CHANGELOG.md' => "## 1.0.0\n- Initial release\n",
        ]);

        $result = $this->svc->installFromZip($zip, 'my-plugin.zip');

        $this->assertSame('my-plugin', $result['plugin_id']);
        $this->assertSame('1.0.0', $result['version']);
        $this->assertSame('not_checked', $result['signature']['status']);
        $this->assertFalse($result['signature']['enforced']);
        $this->assertDirectoryExists($this->pluginDir . '/my-plugin');
        $this->assertFileExists($this->pluginDir . '/my-plugin/plugin.json');
        $this->assertFileExists($this->pluginDir . '/my-plugin/src/Entry.php');
        // Staging dirs always cleaned up on success.
        $this->assertEmpty(
            glob($this->pluginDir . '/.staging-*') ?: [],
            'Staging directory should be cleaned up after success'
        );
    }

    #[Test]
    public function installDispatchesPluginInstalledEvent(): void
    {
        $heard = [];
        EventDispatcher::getInstance()->subscribe(
            \Eiou\Events\PluginEvents::PLUGIN_INSTALLED,
            function (array $payload) use (&$heard): void {
                $heard[] = $payload;
            }
        );

        $zip = $this->buildValidZip('event-plugin', '2.3.4');
        $this->svc->installFromZip($zip);

        $this->assertCount(1, $heard);
        $this->assertSame('event-plugin', $heard[0]['name']);
        $this->assertSame('2.3.4', $heard[0]['version']);
        $this->assertSame('zip_upload', $heard[0]['source']);
    }

    // ===================================================================
    // Input-shape rejections
    // ===================================================================

    #[Test]
    public function rejectsMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not readable/');
        $this->svc->installFromZip('/nonexistent/file.zip');
    }

    #[Test]
    public function rejectsEmptyFile(): void
    {
        $empty = $this->tmpRoot . '/empty.zip';
        touch($empty);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/empty/');
        $this->svc->installFromZip($empty);
    }

    #[Test]
    public function rejectsNonZipMagicBytes(): void
    {
        $bogus = $this->tmpRoot . '/bogus.zip';
        file_put_contents($bogus, "Not a zip, just bytes");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a zip/');
        $this->svc->installFromZip($bogus);
    }

    #[Test]
    public function rejectsZipExceedingMaxBytes(): void
    {
        // Build a tiny valid zip then claim it's huge by patching MAX —
        // simpler than allocating 25 MiB in the test. We use a custom
        // service with a lower cap via a subclass-style harness: actually
        // the simplest path is a real big-enough payload. 25 MiB is too
        // much, so override by writing into a sparse file and then sniff.
        // For a quick, reliable test, build a zip and assert the cap is
        // honored by feeding the service a file whose size is above cap.
        $oversize = $this->tmpRoot . '/oversize.zip';
        $fp = fopen($oversize, 'wb');
        // Real zip magic so the magic-bytes check is past, then fill.
        fwrite($fp, "PK\x03\x04");
        // Sparse seek + write final byte: file size = MAX+1 without
        // actually allocating 25 MiB of disk.
        fseek($fp, PluginInstallService::MAX_ZIP_BYTES);
        fwrite($fp, "\0");
        fclose($fp);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/too large|exceeds/i');
        $this->svc->installFromZip($oversize);
    }

    // ===================================================================
    // Entry-walk rejections (path traversal, names, sizes, ratios)
    // ===================================================================

    #[Test]
    public function rejectsZipSlipDotDotInName(): void
    {
        $zip = $this->buildZip([
            '../escape.txt' => 'pwned',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'\\.\\.'/");
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsAbsoluteEntryName(): void
    {
        $zip = $this->buildZip([
            '/etc/eiou/x.php' => '<?php',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute/');
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsMultipleTopLevelDirectories(): void
    {
        $zip = $this->buildZip([
            'plugin-a/plugin.json' => '{}',
            'plugin-b/plugin.json' => '{}',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/single top-level directory/');
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsInvalidTopLevelDirectoryName(): void
    {
        $zip = $this->buildZip([
            'MyPlugin/plugin.json' => '{}', // uppercase rejected
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid plugin name/');
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsDisallowedExtension(): void
    {
        $zip = $this->buildZip([
            'good-plugin/plugin.json' => json_encode([
                'name' => 'good-plugin',
                'version' => '1.0.0',
                'entryClass' => 'X',
            ]),
            'good-plugin/payload.phar' => 'arbitrary bytes',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Disallowed file extension '\\.phar'/");
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsHiddenFile(): void
    {
        $zip = $this->buildZip([
            'plug/.htaccess' => 'Deny from all',
            'plug/plugin.json' => '{}',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Hidden file not allowed/');
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsFileCountAboveLimit(): void
    {
        $entries = [];
        $manifest = json_encode([
            'name' => 'many-files',
            'version' => '1.0.0',
            'entryClass' => 'X',
        ]);
        $entries['many-files/plugin.json'] = $manifest;
        // MAX_FILE_COUNT + 1 to trip the gate. Use .txt — allow-listed.
        for ($i = 0; $i <= PluginInstallService::MAX_FILE_COUNT; $i++) {
            $entries["many-files/f{$i}.txt"] = (string) $i;
        }
        $zip = $this->buildZip($entries);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/more than \d+ files/');
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsZipBombByCompressionRatio(): void
    {
        // A long, highly compressible string trips the ratio check. The
        // zip lib defaults to DEFLATE for this content, which yields a
        // ratio well above 100:1 — exactly the bomb fingerprint we want
        // the gate to catch.
        $repeated = str_repeat('A', 10 * 1024 * 1024); // 10 MiB of 'A'
        $zip = $this->buildZip([
            'bomby/plugin.json' => json_encode([
                'name' => 'bomby',
                'version' => '1.0.0',
                'entryClass' => 'X',
            ]),
            'bomby/payload.txt' => $repeated,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/compression ratio/');
        $this->svc->installFromZip($zip);
    }

    // ===================================================================
    // Post-extraction rejections
    // ===================================================================

    #[Test]
    public function rejectsZipWithoutManifest(): void
    {
        $zip = $this->buildZip([
            'no-manifest/README.md' => '# nothing here',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Missing plugin\.json/');
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsManifestNameMismatchingDirectory(): void
    {
        $zip = $this->buildZip([
            'on-disk-name/plugin.json' => json_encode([
                'name' => 'manifest-name', // intentionally different
                'version' => '1.0.0',
                'entryClass' => 'X',
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/does not match directory/");
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsManifestMissingSandboxedFlag(): void
    {
        $zip = $this->buildZip([
            'no-sandbox/plugin.json' => json_encode([
                'name' => 'no-sandbox',
                'version' => '1.0.0',
                'entryClass' => 'X',
                // Note: no "sandboxed" field.
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"sandboxed": true/');
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsManifestSandboxedFalseExplicitly(): void
    {
        $zip = $this->buildZip([
            'legacy/plugin.json' => json_encode([
                'name' => 'legacy',
                'version' => '1.0.0',
                'entryClass' => 'X',
                'sandboxed' => false,
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"sandboxed": true/');
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsMalformedDeclarativeFieldShape(): void
    {
        $zip = $this->buildZip([
            'bad-field/plugin.json' => json_encode([
                'name' => 'bad-field',
                'version' => '1.0.0',
                'entryClass' => 'X',
                'sandboxed' => true,
                // Each entry should be a string; passing a non-array
                // here forces the validator's "must be a list" branch.
                'subscribes_to' => 'not-a-list',
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/subscribes_to.*must be a list/');
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsMalformedDeclarativeFieldEntry(): void
    {
        $zip = $this->buildZip([
            'bad-entry/plugin.json' => json_encode([
                'name' => 'bad-entry',
                'version' => '1.0.0',
                'entryClass' => 'X',
                'sandboxed' => true,
                // Wrong format for a core_services entry.
                'core_services' => ['NotDotSeparated'],
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/core_services.*invalid entry/');
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsManifestMissingVersion(): void
    {
        $zip = $this->buildZip([
            'no-version/plugin.json' => json_encode([
                'name' => 'no-version',
                'entryClass' => 'X',
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'version' is missing/");
        $this->svc->installFromZip($zip);
    }

    #[Test]
    public function rejectsReinstallingExistingPlugin(): void
    {
        $zip1 = $this->buildValidZip('duplicate', '1.0.0');
        $this->svc->installFromZip($zip1);
        $this->assertDirectoryExists($this->pluginDir . '/duplicate');

        // Build a second zip with the same name. Should be rejected with
        // already_installed semantics — install is not update.
        $zip2 = $this->buildValidZip('duplicate', '2.0.0');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already installed/');
        $this->svc->installFromZip($zip2);
    }

    // ===================================================================
    // Signature verification — require mode
    // ===================================================================

    #[Test]
    public function rejectsUnsignedPluginWhenSignatureModeRequire(): void
    {
        $verifier = $this->createMock(PluginSignatureVerifier::class);
        $verifier->method('verify')->willReturn(['status' => 'unsigned']);
        $svc = new PluginInstallService(
            $this->pluginDir,
            $verifier,
            PluginSignatureVerifier::MODE_REQUIRE
        );

        $zip = $this->buildValidZip('needs-sig', '1.0.0');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/signature required/');
        $svc->installFromZip($zip);

        // No staging crumbs left behind after the rejected install.
        $this->assertDirectoryDoesNotExist($this->pluginDir . '/needs-sig');
    }

    #[Test]
    public function acceptsPluginInWarnModeEvenIfUnsigned(): void
    {
        $verifier = $this->createMock(PluginSignatureVerifier::class);
        $verifier->method('verify')->willReturn(['status' => 'unsigned']);
        $svc = new PluginInstallService(
            $this->pluginDir,
            $verifier,
            PluginSignatureVerifier::MODE_WARN
        );

        $zip = $this->buildValidZip('warn-mode', '1.0.0');
        $result = $svc->installFromZip($zip);

        $this->assertSame('warn-mode', $result['plugin_id']);
        $this->assertSame('unsigned', $result['signature']['status']);
        $this->assertFalse($result['signature']['enforced']);
    }

    #[Test]
    public function reportsSignatureStatusInRequireModeOnSuccess(): void
    {
        $verifier = $this->createMock(PluginSignatureVerifier::class);
        $verifier->method('verify')->willReturn([
            'status' => 'ok',
            'key_fingerprint' => 'sha256:abc123',
        ]);
        $svc = new PluginInstallService(
            $this->pluginDir,
            $verifier,
            PluginSignatureVerifier::MODE_REQUIRE
        );

        $zip = $this->buildValidZip('signed', '1.0.0');
        $result = $svc->installFromZip($zip);

        $this->assertSame('ok', $result['signature']['status']);
        $this->assertSame('sha256:abc123', $result['signature']['key_fingerprint']);
        $this->assertTrue($result['signature']['enforced']);
    }

    // ===================================================================
    // Static utility
    // ===================================================================

    #[Test]
    public function limitsExposesCurrentConfiguration(): void
    {
        $limits = PluginInstallService::limits();
        $this->assertSame(PluginInstallService::MAX_ZIP_BYTES, $limits['max_zip_bytes']);
        $this->assertSame(PluginInstallService::MAX_UNCOMPRESSED_BYTES, $limits['max_uncompressed_bytes']);
        $this->assertSame(PluginInstallService::MAX_FILE_BYTES, $limits['max_file_bytes']);
        $this->assertSame(PluginInstallService::MAX_FILE_COUNT, $limits['max_file_count']);
        $this->assertSame(PluginInstallService::MAX_COMPRESSION_RATIO, $limits['max_compression_ratio']);
        $this->assertIsArray($limits['allowed_extensions']);
        $this->assertContains('php', $limits['allowed_extensions']);
        $this->assertNotContains('phar', $limits['allowed_extensions']);
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    /**
     * @param array<string, string> $entries name => content
     */
    private function buildZip(array $entries): string
    {
        $path = $this->tmpRoot . '/u-' . uniqid('', true) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create test zip at {$path}");
        }
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    private function buildValidZip(string $pluginName, string $version): string
    {
        return $this->buildZip([
            $pluginName . '/plugin.json' => json_encode([
                'name' => $pluginName,
                'version' => $version,
                'entryClass' => 'X\\Y\\Z',
                'sandboxed' => true,
            ]),
            $pluginName . '/src/Entry.php' => "<?php\n",
        ]);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            if (is_dir($p) && !is_link($p)) {
                $this->rrmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
