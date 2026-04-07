<?php
/**
 * Verify that vendored JavaScript libraries exist and are non-empty.
 *
 * These libraries are bundled (not loaded from a CDN) because nodes
 * may be TOR-only with no internet access. If a file is missing after
 * a build or checkout, the QR code features silently break.
 */

namespace Eiou\Tests\Gui;

use PHPUnit\Framework\TestCase;

class VendorAssetsTest extends TestCase
{
    private const ASSETS_DIR = EIOU_PROJECT_ROOT . '/files/src/gui/assets/js/vendor';

    /**
     * QR code generator library (qrcode-generator) must exist and be non-empty.
     * Used for rendering QR codes of wallet addresses.
     */
    public function testQrCodeGeneratorLibraryExists(): void
    {
        $path = self::ASSETS_DIR . '/qrcode-generator.js';
        $this->assertFileExists($path, 'qrcode-generator.js vendor library is missing');
        $this->assertGreaterThan(0, filesize($path), 'qrcode-generator.js is empty');
    }

    /**
     * QR code scanner library (html5-qrcode) must exist and be non-empty.
     * Used for scanning QR codes via camera in the Add Contact form.
     */
    public function testQrCodeScannerLibraryExists(): void
    {
        $path = self::ASSETS_DIR . '/html5-qrcode.min.js';
        $this->assertFileExists($path, 'html5-qrcode.min.js vendor library is missing');
        $this->assertGreaterThan(0, filesize($path), 'html5-qrcode.min.js is empty');
    }

    /**
     * The QR generator library should contain the expected module signature.
     */
    public function testQrCodeGeneratorIsValid(): void
    {
        $path = self::ASSETS_DIR . '/qrcode-generator.js';
        $content = file_get_contents($path);
        $this->assertStringContainsString('QR Code Generator', $content, 'qrcode-generator.js does not contain expected header');
    }

    /**
     * The QR scanner library should contain the Html5Qrcode class reference.
     */
    public function testQrCodeScannerIsValid(): void
    {
        $path = self::ASSETS_DIR . '/html5-qrcode.min.js';
        $content = file_get_contents($path);
        $this->assertStringContainsString('Html5Qrcode', $content, 'html5-qrcode.min.js does not contain expected Html5Qrcode reference');
    }
}
