<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services\Plugins;

use Eiou\Events\EventDispatcher;
use Eiou\Events\PluginEvents;
use Eiou\Utils\Logger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * PluginInstallService
 *
 * Accepts an operator-uploaded zip and stages it under
 * /etc/eiou/plugins/<plugin_id>/ as a *disabled* plugin. The next node
 * restart's PluginLoader::discover() will pick it up — install does NOT
 * load, register, or boot the plugin. That matches the disabled-by-default
 * stance (a malformed or hostile plugin must not crash the node on first
 * boot just because someone uploaded it).
 *
 * Trust boundary — what this class assumes:
 *   - The caller has already validated CSRF + wallet auth (PluginController).
 *   - The zip is operator-supplied and untrusted bytes. Every validation
 *     below must run BEFORE any byte is written to the plugins volume, and
 *     extraction lands in a `.staging-*` directory next to (not inside) the
 *     plugins root so a half-extracted plugin is never visible to discover().
 *
 * Validations, in order — short-circuit on first failure:
 *
 *   1. File exists, readable, non-empty, under MAX_ZIP_BYTES.
 *   2. Magic bytes start with "PK\x03\x04" (no MIME trust from the client).
 *   3. ZipArchive opens cleanly.
 *   4. Walk every entry via statIndex():
 *        - Name rejects: empty, absolute, contains "..", contains "\",
 *          starts with "./".
 *        - Single top-level directory matching the kebab/underscore plugin
 *          ID pattern; all entries share that prefix.
 *        - Per-file uncompressed size ≤ MAX_FILE_BYTES.
 *        - Sum of uncompressed sizes ≤ MAX_UNCOMPRESSED_BYTES.
 *        - File count ≤ MAX_FILE_COUNT.
 *        - Compression ratio (uncompressed/compressed) ≤ MAX_COMPRESSION_RATIO
 *          (zip-bomb sentinel; ratios above this are practically always either
 *          a bomb or padding the operator didn't mean to ship).
 *        - File extension in the ALLOWED_EXTS allow-list. No executables,
 *          no .phar, no .so, no .htaccess (PHP-FPM serves the plugins volume's
 *          public assets through PluginAssetServer; arbitrary file types are
 *          not part of the contract).
 *   5. Target directory must not already exist — install is install, not
 *      update. Updates go through uninstall + install so the operator
 *      acknowledges the destructive step.
 *   6. Extract to staging, then walk the extracted tree and reject any
 *      symlinks (belt-and-suspenders: even if a hostile entry's external
 *      attrs slipped past the entry walk, extractTo() writes the link target
 *      as file content for normal entries — so any actual is_link() result
 *      is itself a red flag).
 *   7. Parse plugin.json — manifest "name" must match the directory name.
 *   8. If a PluginSignatureVerifier is wired in MODE_REQUIRE, the staged
 *      plugin must verify cleanly. Failures abort installation; the staged
 *      bytes are deleted.
 *   9. Atomic rename of the staging dir to /etc/eiou/plugins/<plugin_id>/.
 *      If the rename loses a race, both staging copies are cleaned up.
 *
 * On success, the plugin sits on disk *without* a state entry in
 * plugins.json — PluginLoader treats missing entries as enabled=false, so
 * the operator has to explicitly toggle the plugin on (and restart) for it
 * to take effect. The result envelope includes the signature status so the
 * GUI can surface trust state before the operator clicks Enable.
 *
 * See docs/PLUGINS.md (Installing from a zip) for the operator flow.
 */
class PluginInstallService
{
    public const PLUGIN_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** Compressed zip bytes — outer cap. Reject before opening. */
    public const MAX_ZIP_BYTES = 25 * 1024 * 1024;       // 25 MiB

    /** Total uncompressed bytes across all entries. Reject during walk. */
    public const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;  // 50 MiB

    /**
     * Any single entry above this is rejected. Sized to forgive heavy
     * single assets — a 4K splash image at high quality, an uncompressed
     * font, a bundled chart library — without raising the aggregate cap.
     */
    public const MAX_FILE_BYTES = 15 * 1024 * 1024;      // 15 MiB

    /** Maximum number of file entries (not directory entries). */
    public const MAX_FILE_COUNT = 500;

    /**
     * Max ratio of total uncompressed bytes to compressed bytes. Real-world
     * zips of plugin sources sit comfortably under 20:1; 100:1 leaves head-
     * room for legitimately compressible files (large JSON, repeated CSS)
     * while still tripping on zip bombs which exceed 1000:1 trivially.
     */
    public const MAX_COMPRESSION_RATIO = 100;

    /**
     * Allow-list of file extensions. Everything else is rejected before any
     * write to disk. Centralized here so PluginAssetServer's content-type
     * mapping and this allow-list stay alignable in code review.
     */
    public const ALLOWED_EXTS = [
        'php', 'json', 'md', 'txt',
        'css', 'js', 'map',
        'html', 'htm',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
    ];

    private string $pluginDir;
    private ?PluginSignatureVerifier $sigVerifier;
    private string $sigMode;
    private ?Logger $logger;

    public function __construct(
        string $pluginDir = '/etc/eiou/plugins',
        ?PluginSignatureVerifier $sigVerifier = null,
        string $sigMode = PluginSignatureVerifier::MODE_OFF,
        ?Logger $logger = null
    ) {
        $this->pluginDir = rtrim($pluginDir, '/');
        $this->sigVerifier = $sigVerifier;
        $validModes = [
            PluginSignatureVerifier::MODE_OFF,
            PluginSignatureVerifier::MODE_WARN,
            PluginSignatureVerifier::MODE_REQUIRE,
        ];
        $this->sigMode = in_array($sigMode, $validModes, true)
            ? $sigMode
            : PluginSignatureVerifier::MODE_OFF;
        $this->logger = $logger;
    }

    /**
     * Install a plugin from the given zip file.
     *
     * The zipPath must be a local readable file (typically the moved
     * upload from PHP's $_FILES). The original filename is logged for
     * audit; it does not affect validation.
     *
     * @return array{
     *     plugin_id: string,
     *     version: string,
     *     signature: array{
     *         status: string,
     *         key_fingerprint: ?string,
     *         enforced: bool
     *     },
     * }
     *
     * @throws InvalidArgumentException For malformed/hostile inputs (400-class).
     * @throws RuntimeException For filesystem / required-signature failures (500-class).
     */
    public function installFromZip(string $zipPath, ?string $originalFilename = null): array
    {
        $staged = $this->stageAndValidate($zipPath);
        $pluginId      = $staged['plugin_id'];
        $stagedDir     = $staged['staged_dir'];
        $stagingParent = $staged['staging_parent'];
        $manifest      = $staged['manifest'];
        $sigResult     = $staged['signature'];
        $targetDir     = $this->pluginDir . '/' . $pluginId;

        try {
            // Install (not upgrade): refuse if the plugin is already
            // present. Updates go through PluginUpgradeService so the
            // operator's existing plugin state (DB tables, credentials,
            // gateway token) is preserved across the version change.
            // Carry both versions on the exception so the GUI can
            // render a "Replace v{current} with v{new}?" confirmation
            // and route the same upload through pluginsUploadAsUpgrade.
            if (is_dir($targetDir)) {
                $currentVersion = '';
                $installedManifestPath = $targetDir . '/plugin.json';
                if (is_file($installedManifestPath)) {
                    $installedRaw = @file_get_contents($installedManifestPath);
                    if ($installedRaw !== false) {
                        $installedDecoded = json_decode($installedRaw, true);
                        if (is_array($installedDecoded)) {
                            $currentVersion = (string) ($installedDecoded['version'] ?? '');
                        }
                    }
                }
                throw new PluginAlreadyInstalledException(
                    $pluginId,
                    (string) ($manifest['version'] ?? ''),
                    $currentVersion
                );
            }
            if (!@rename($stagedDir, $targetDir)) {
                throw new RuntimeException("Could not move staged plugin into {$targetDir}");
            }

            $this->cleanupStaging($stagingParent);

            $logger = $this->logger ?? Logger::getInstance();
            $logger->info('plugin_installed_from_zip', [
                'plugin' => $pluginId,
                'version' => $manifest['version'] ?? '',
                'signature_status' => $sigResult['status'],
                'signature_enforced' => $this->sigMode === PluginSignatureVerifier::MODE_REQUIRE,
                'original_filename' => $originalFilename,
            ]);

            EventDispatcher::getInstance()->dispatch(PluginEvents::PLUGIN_INSTALLED, [
                'name' => $pluginId,
                'version' => $manifest['version'] ?? '',
                'source' => 'zip_upload',
            ]);

            return [
                'plugin_id' => $pluginId,
                'version' => (string) ($manifest['version'] ?? ''),
                'signature' => [
                    'status' => $sigResult['status'],
                    'key_fingerprint' => $sigResult['key_fingerprint'] ?? null,
                    'enforced' => $this->sigMode === PluginSignatureVerifier::MODE_REQUIRE,
                ],
            ];
        } catch (Throwable $e) {
            $this->cleanupStaging($stagingParent);
            throw $e;
        }
    }

    /**
     * Validate a plugin zip and stage its contents under a `.staging-<rand>/`
     * directory next to (not inside) the plugins root. Public so
     * PluginUpgradeService can reuse the entire validation pipeline
     * without duplicating it — the upgrade flow then performs its own
     * version-compare + backup + atomic swap, where installFromZip
     * performs its own existence-collision check + rename.
     *
     * On any failure, the staging directory is cleaned up before the
     * exception bubbles. On success, the caller is responsible for
     * either consuming the `staged_dir` (rename it into its final
     * canonical location) or calling `cleanupStaging($staging_parent)`
     * to discard it.
     *
     * @return array{
     *     plugin_id: string,
     *     staged_dir: string,
     *     staging_parent: string,
     *     manifest: array<string, mixed>,
     *     signature: array{status: string, key_fingerprint: ?string}
     * }
     *
     * @throws InvalidArgumentException For malformed/hostile inputs (400-class).
     * @throws RuntimeException For filesystem / required-signature failures (500-class).
     */
    public function stageAndValidate(string $zipPath): array
    {
        $this->ensureUploadIsReadable($zipPath);
        $this->ensureZipMagic($zipPath);

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath, ZipArchive::RDONLY);
        if ($openResult !== true) {
            throw new InvalidArgumentException("Zip could not be opened (code {$openResult})");
        }

        try {
            $pluginId = $this->validateEntries($zip);
        } catch (Throwable $e) {
            $zip->close();
            throw $e;
        }

        if (!is_dir($this->pluginDir)) {
            $zip->close();
            throw new RuntimeException("Plugin directory {$this->pluginDir} does not exist");
        }
        if (!is_writable($this->pluginDir)) {
            $zip->close();
            throw new RuntimeException("Plugin directory {$this->pluginDir} is not writable");
        }

        $stagingParent = $this->pluginDir . '/.staging-' . bin2hex(random_bytes(8));
        if (!mkdir($stagingParent, 0o755, false)) {
            $zip->close();
            throw new RuntimeException("Could not create staging directory");
        }

        try {
            if (!$zip->extractTo($stagingParent)) {
                throw new RuntimeException("Zip extraction failed");
            }
            $zip->close();

            $stagedPluginDir = $stagingParent . '/' . $pluginId;
            if (!is_dir($stagedPluginDir)) {
                throw new RuntimeException("Extracted tree missing expected '{$pluginId}/' root");
            }

            // Post-extraction defense: even if the entry walk approved every
            // name, refuse the install if any actual symlink landed on disk.
            // ZipArchive::extractTo() in PHP writes link-target text as file
            // content for normal entries, so a true is_link() hit is itself
            // anomalous and not worth processing further.
            $this->rejectSymlinksUnder($stagedPluginDir);

            $manifest  = $this->readAndValidateManifest($stagedPluginDir, $pluginId);
            $sigResult = $this->verifySignatureIfWired($stagedPluginDir);

            return [
                'plugin_id'      => $pluginId,
                'staged_dir'     => $stagedPluginDir,
                'staging_parent' => $stagingParent,
                'manifest'       => $manifest,
                'signature'      => $sigResult,
            ];
        } catch (Throwable $e) {
            $this->cleanupStaging($stagingParent);
            throw $e;
        }
    }

    /**
     * Public alias for the private staging-dir cleanup helper so
     * PluginUpgradeService can discard a staged bundle after a
     * successful swap without reaching into protected internals.
     */
    public function discardStaging(string $stagingParent): void
    {
        $this->cleanupStaging($stagingParent);
    }

    /**
     * Public so callers can present the limits in the UI without copying
     * the numbers. Single source of truth for the "what's allowed" view.
     *
     * @return array<string, int|string|array<int, string>>
     */
    public static function limits(): array
    {
        return [
            'max_zip_bytes' => self::MAX_ZIP_BYTES,
            'max_uncompressed_bytes' => self::MAX_UNCOMPRESSED_BYTES,
            'max_file_bytes' => self::MAX_FILE_BYTES,
            'max_file_count' => self::MAX_FILE_COUNT,
            'max_compression_ratio' => self::MAX_COMPRESSION_RATIO,
            'allowed_extensions' => self::ALLOWED_EXTS,
        ];
    }

    private function ensureUploadIsReadable(string $zipPath): void
    {
        if ($zipPath === '' || !is_file($zipPath) || !is_readable($zipPath)) {
            throw new InvalidArgumentException("Uploaded file is not readable");
        }
        $size = filesize($zipPath);
        if ($size === false || $size === 0) {
            throw new InvalidArgumentException("Uploaded file is empty");
        }
        if ($size > self::MAX_ZIP_BYTES) {
            throw new InvalidArgumentException(
                "Zip is too large: " . self::formatBytes($size)
                . " exceeds limit of " . self::formatBytes(self::MAX_ZIP_BYTES)
            );
        }
    }

    private function ensureZipMagic(string $zipPath): void
    {
        $fp = @fopen($zipPath, 'rb');
        if ($fp === false) {
            throw new InvalidArgumentException("Could not open upload for magic-byte check");
        }
        $head = fread($fp, 4);
        fclose($fp);
        // "PK\x03\x04" is the local-file-header signature. Empty/spanned
        // archives use different sigs and aren't useful as plugin packages.
        if ($head !== "PK\x03\x04") {
            throw new InvalidArgumentException("Uploaded file is not a zip archive");
        }
    }

    /**
     * Walk every zip entry without writing anything. Returns the plugin ID
     * derived from the (single) top-level directory.
     */
    private function validateEntries(ZipArchive $zip): string
    {
        $numEntries = $zip->numFiles;
        $fileCount = 0;
        $totalUncompressed = 0;
        $totalCompressed = 0;
        $rootDir = null;

        for ($i = 0; $i < $numEntries; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new InvalidArgumentException("Could not read zip entry #{$i}");
            }
            $name = (string) $stat['name'];
            if ($name === '') {
                throw new InvalidArgumentException("Empty entry name at index {$i}");
            }
            $this->rejectDangerousName($name);

            // Derive the top-level path component for prefix-consistency check.
            // Entries that ARE the top-level directory itself land here too;
            // ZipArchive reports them with a trailing slash.
            $firstSlash = strpos($name, '/');
            $top = $firstSlash === false ? $name : substr($name, 0, $firstSlash);
            if ($top === '' || $top === '.' || $top === '..') {
                throw new InvalidArgumentException("Entry '{$name}' has no valid top-level directory");
            }
            if ($rootDir === null) {
                if (!preg_match(self::PLUGIN_ID_PATTERN, $top)) {
                    throw new InvalidArgumentException(
                        "Top-level directory '{$top}' is not a valid plugin name"
                    );
                }
                $rootDir = $top;
            } elseif ($top !== $rootDir) {
                throw new InvalidArgumentException(
                    "Zip must contain a single top-level directory; saw both '{$rootDir}' and '{$top}'"
                );
            }

            $isDirEntry = substr($name, -1) === '/';
            if ($isDirEntry) {
                continue;
            }

            $fileCount++;
            if ($fileCount > self::MAX_FILE_COUNT) {
                throw new InvalidArgumentException(
                    "Zip contains more than " . self::MAX_FILE_COUNT . " files"
                );
            }

            $size = (int) ($stat['size'] ?? 0);
            $comp = (int) ($stat['comp_size'] ?? 0);
            if ($size > self::MAX_FILE_BYTES) {
                throw new InvalidArgumentException(
                    "Entry '{$name}' is " . self::formatBytes($size)
                    . " (exceeds " . self::formatBytes(self::MAX_FILE_BYTES) . " per-file limit)"
                );
            }

            $totalUncompressed += $size;
            $totalCompressed += $comp;
            if ($totalUncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException(
                    "Total uncompressed size exceeds " . self::formatBytes(self::MAX_UNCOMPRESSED_BYTES)
                );
            }

            $this->rejectDisallowedExtension($name);
        }

        if ($rootDir === null || $fileCount === 0) {
            throw new InvalidArgumentException("Zip is empty or contains no files");
        }

        // Compression-ratio sentinel — zip bombs only fire when extraction
        // is attempted, so this guard runs *before* we touch the disk. Use
        // max(1, ...) so a zero comp_size from a tiny entry can't divide.
        $ratio = $totalCompressed > 0
            ? $totalUncompressed / $totalCompressed
            : $totalUncompressed; // un-compressed files in a stored zip are fine
        if ($totalCompressed > 0 && $ratio > self::MAX_COMPRESSION_RATIO) {
            throw new InvalidArgumentException(
                "Suspicious compression ratio "
                . number_format($ratio, 1)
                . ":1 (limit " . self::MAX_COMPRESSION_RATIO . ":1)"
            );
        }

        return $rootDir;
    }

    private function rejectDangerousName(string $name): void
    {
        if (str_contains($name, "\0")) {
            throw new InvalidArgumentException("Entry name contains a null byte");
        }
        if ($name[0] === '/') {
            throw new InvalidArgumentException("Entry name is absolute: '{$name}'");
        }
        // Backslash-paths confuse PHP's path handling cross-platform and are
        // not legal in ZIP per spec. Reject outright rather than try to
        // normalize.
        if (str_contains($name, '\\')) {
            throw new InvalidArgumentException("Entry name contains backslash: '{$name}'");
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $name)) {
            throw new InvalidArgumentException("Entry name contains '..': '{$name}'");
        }
        // "./foo" can normalize to "foo" but the path is suspicious — reject
        // it so legitimate zips with clean names always pass and only oddly
        // constructed archives have to deal with the rule.
        if (strpos($name, './') === 0) {
            throw new InvalidArgumentException("Entry name starts with './': '{$name}'");
        }
    }

    private function rejectDisallowedExtension(string $name): void
    {
        // Strip the directory part first so we don't accidentally allow
        // ".php/" embedded inside a path.
        $basename = basename($name);
        if ($basename === '') {
            throw new InvalidArgumentException("Entry has empty basename: '{$name}'");
        }
        // Dotfiles other than plugin-side artefacts (CHANGELOG.md isn't a
        // dotfile; nothing legitimate inside a plugin zip starts with '.').
        if ($basename[0] === '.') {
            throw new InvalidArgumentException("Hidden file not allowed: '{$basename}'");
        }
        $dotPos = strrpos($basename, '.');
        if ($dotPos === false || $dotPos === 0) {
            throw new InvalidArgumentException("Entry has no extension: '{$basename}'");
        }
        $ext = strtolower(substr($basename, $dotPos + 1));
        if (!in_array($ext, self::ALLOWED_EXTS, true)) {
            throw new InvalidArgumentException(
                "Disallowed file extension '.{$ext}' in '{$basename}'"
            );
        }
    }

    /**
     * Walks the extracted plugin tree and throws if any path is a symlink
     * or escapes the extraction root via realpath.
     */
    private function rejectSymlinksUnder(string $root): void
    {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            throw new RuntimeException("Could not resolve extracted plugin path");
        }
        $rootReal = rtrim($rootReal, '/') . '/';

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            $path = $entry->getPathname();
            if (is_link($path)) {
                throw new InvalidArgumentException("Symlink detected in extracted tree: '{$entry->getFilename()}'");
            }
            $real = realpath($path);
            // SplFileInfo can iterate broken links; treat any unresolvable
            // path as a refusal to keep things simple.
            if ($real === false || strpos($real . '/', $rootReal) !== 0) {
                throw new InvalidArgumentException("Path escapes plugin root: '{$path}'");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readAndValidateManifest(string $stagedPluginDir, string $expectedName): array
    {
        $manifestPath = $stagedPluginDir . '/plugin.json';
        if (!is_file($manifestPath)) {
            throw new InvalidArgumentException("Missing plugin.json in zip");
        }
        $raw = @file_get_contents($manifestPath);
        if ($raw === false) {
            throw new RuntimeException("Could not read staged plugin.json");
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            throw new InvalidArgumentException("plugin.json is not a JSON object");
        }
        $name = $manifest['name'] ?? null;
        $version = $manifest['version'] ?? null;
        $entryClass = $manifest['entryClass'] ?? null;
        if (!is_string($name) || !preg_match(self::PLUGIN_ID_PATTERN, $name)) {
            throw new InvalidArgumentException("plugin.json 'name' is missing or invalid");
        }
        if ($name !== $expectedName) {
            throw new InvalidArgumentException(
                "plugin.json 'name' ('{$name}') does not match directory ('{$expectedName}')"
            );
        }
        if (!is_string($version) || $version === '') {
            throw new InvalidArgumentException("plugin.json 'version' is missing");
        }
        if (!is_string($entryClass) || $entryClass === '') {
            throw new InvalidArgumentException("plugin.json 'entryClass' is missing");
        }

        // Sandboxing is mandatory. The loader refuses to enable any
        // plugin without "sandboxed": true; rejecting at install time
        // means the operator learns the plugin can't run BEFORE its
        // files land on disk, instead of getting a silent failure
        // later when they try to enable it.
        if (empty($manifest['sandboxed'])) {
            throw new InvalidArgumentException(
                "plugin.json must declare \"sandboxed\": true — in-process plugins "
                . "are not supported. See docs/PLUGINS.md (Sandboxed Plugin Authoring)."
            );
        }

        // Validate the declarative-surface fields at install time so a
        // malformed manifest fails fast rather than slipping past install
        // and only erroring at enable. Same shape gates PluginLoader uses
        // when reading these fields in listAllPlugins().
        $this->validateDeclarativeFields($manifest);

        return $manifest;
    }

    /**
     * Shape-validate the optional declarative-surface fields. Anything
     * that's present must match the expected schema; missing fields are
     * fine (a plugin can be sandboxed without declaring any surface).
     */
    private function validateDeclarativeFields(array $manifest): void
    {
        $listOfStrings = static function (string $key, string $regex) use ($manifest): void {
            $val = $manifest[$key] ?? null;
            if ($val === null) return;
            if (!is_array($val)) {
                throw new InvalidArgumentException(
                    "plugin.json '{$key}' must be a list of strings"
                );
            }
            foreach ($val as $entry) {
                if (!is_string($entry) || !preg_match($regex, $entry)) {
                    throw new InvalidArgumentException(
                        "plugin.json '{$key}' contains invalid entry: "
                        . (is_string($entry) ? "'{$entry}'" : gettype($entry))
                    );
                }
            }
        };
        $listOfShape = static function (string $key, callable $validate) use ($manifest): void {
            $val = $manifest[$key] ?? null;
            if ($val === null) return;
            if (!is_array($val)) {
                throw new InvalidArgumentException(
                    "plugin.json '{$key}' must be a list of objects"
                );
            }
            foreach ($val as $i => $entry) {
                if (!is_array($entry) || !$validate($entry)) {
                    throw new InvalidArgumentException(
                        "plugin.json '{$key}' entry #{$i} is malformed"
                    );
                }
            }
        };

        $listOfStrings('subscribes_to', '/^[a-z][a-z0-9_.-]*$/');
        $listOfStrings('filter_hooks',  '/^[a-z][a-zA-Z0-9_.-]*$/');
        $listOfStrings('render_hooks',  '/^[a-z][a-zA-Z0-9_.-]*$/');
        $listOfStrings('core_services', '/^[A-Z][A-Za-z0-9]*\.[a-z][A-Za-z0-9_]*$/');
        // `permissions` are the louder-consent tier on top of
        // core_services — see PluginCallable's docblock. Shape gate
        // here is "lowercase snake_case key"; the *known-key* gate is
        // below so an unknown key fails with a message that names the
        // catalog rather than a regex.
        $listOfStrings('permissions',   '/^[a-z][a-z0-9_]*$/');
        $perms = $manifest['permissions'] ?? null;
        if (is_array($perms)) {
            foreach ($perms as $key) {
                if (is_string($key) && !PluginPermissionCatalog::isKnown($key)) {
                    throw new InvalidArgumentException(
                        "plugin.json 'permissions' contains unknown key '{$key}' — "
                        . "host does not catalogue this permission. Known keys: "
                        . implode(', ', PluginPermissionCatalog::knownKeys())
                    );
                }
            }
        }

        $listOfShape('gui_actions', fn($e): bool =>
            isset($e['name']) && is_string($e['name'])
            && preg_match('/^[a-z][a-zA-Z0-9_]*$/', $e['name']) === 1
        );
        $listOfShape('tabs', fn($e): bool =>
            isset($e['id'], $e['label'])
            && is_string($e['id']) && is_string($e['label'])
            && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $e['id']) === 1
        );
        $listOfShape('gui_assets', fn($e): bool =>
            isset($e['type'], $e['path'])
            && in_array($e['type'], ['css', 'js'], true)
            && is_string($e['path'])
            && strpos($e['path'], '..') === false
        );
        $listOfShape('api_routes', fn($e): bool =>
            isset($e['method'], $e['action'])
            && in_array($e['method'], ['GET','POST','PUT','PATCH','DELETE'], true)
            && is_string($e['action'])
            && preg_match('/^[a-z][a-z0-9-]{0,63}$/', $e['action']) === 1
        );
        $listOfShape('cli_commands', fn($e): bool =>
            isset($e['name']) && is_string($e['name'])
            && preg_match('/^[a-z][a-z0-9-]*$/', $e['name']) === 1
        );

        // plugin_tab_panel — a single object, not a list. Each plugin
        // gets at most one panel inside the host's Plugins tab.
        $panel = $manifest['plugin_tab_panel'] ?? null;
        if ($panel !== null) {
            if (!is_array($panel)) {
                throw new InvalidArgumentException(
                    "plugin.json 'plugin_tab_panel' must be an object"
                );
            }
            $label = $panel['label'] ?? null;
            if (!is_string($label) || $label === '' || strlen($label) > 64) {
                throw new InvalidArgumentException(
                    "plugin.json 'plugin_tab_panel.label' is required and must be a non-empty string up to 64 chars"
                );
            }
            if (isset($panel['icon']) && (!is_string($panel['icon']) || $panel['icon'] === '')) {
                throw new InvalidArgumentException(
                    "plugin.json 'plugin_tab_panel.icon' must be a non-empty string when set"
                );
            }
            if (isset($panel['order']) && !is_int($panel['order'])) {
                throw new InvalidArgumentException(
                    "plugin.json 'plugin_tab_panel.order' must be an integer when set"
                );
            }
        }
    }

    /**
     * @return array{status:string, key_fingerprint?:string, error?:string}
     */
    private function verifySignatureIfWired(string $stagedPluginDir): array
    {
        if ($this->sigVerifier === null) {
            return ['status' => 'not_checked'];
        }
        $result = $this->sigVerifier->verify($stagedPluginDir);
        if ($this->sigMode === PluginSignatureVerifier::MODE_REQUIRE && ($result['status'] ?? '') !== 'ok') {
            throw new RuntimeException(
                "Plugin signature required but verification returned: "
                . ($result['status'] ?? 'unknown')
            );
        }
        return $result;
    }

    private function cleanupStaging(string $stagingParent): void
    {
        if ($stagingParent === '' || !is_dir($stagingParent)) {
            return;
        }
        // Refuse to recurse anywhere outside the plugins root, even if the
        // caller hands us a weird path. realpath() pins both ends to absolute
        // paths so prefix comparison is reliable.
        $realStaging = realpath($stagingParent);
        $realRoot = realpath($this->pluginDir);
        if ($realStaging === false || $realRoot === false) {
            return;
        }
        if (strpos($realStaging . '/', rtrim($realRoot, '/') . '/') !== 0) {
            return;
        }
        $this->rrmdir($realStaging);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1) . ' MiB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KiB';
        }
        return $bytes . ' B';
    }
}
