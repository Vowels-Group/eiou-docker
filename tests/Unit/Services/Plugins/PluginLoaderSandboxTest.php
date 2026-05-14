<?php
namespace Eiou\Tests\Services\Plugins;

use Eiou\Services\Plugins\PluginLoader;
use Eiou\Services\Plugins\PluginGatewayTokenService;
use Eiou\Services\Plugins\PluginNginxConfigService;
use Eiou\Services\Plugins\PluginPoolService;
use Eiou\Services\Plugins\PluginUserService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2.5 of plugin sandboxing — exercises the wire-in between
 * PluginLoader::setEnabled() and the sandbox services
 * (PluginUserService, PluginPoolService, PluginNginxConfigService).
 *
 * Strategy: a real PluginLoader on a tmp plugin dir, real
 * PluginNginxConfigService (pure renderer, no IPC), but the user +
 * pool services are mocked via their test seams so no real useradd
 * / nginx reload happens. The point is to verify the LOADER calls
 * the right sequence of sandbox operations, not to re-test the
 * services' own contracts.
 *
 * See docs/PLUGIN_SANDBOXING.md.
 */
#[CoversClass(PluginLoader::class)]
class PluginLoaderSandboxTest extends TestCase
{
    private string $tmpRoot;
    private string $pluginDir;
    private string $stateFile;
    private PluginLoader $loader;

    /** Mock-mutable Unix-user table. */
    private array $userTable = [];

    /** @var array<int, array{action:string, system_user:string}> */
    private array $userActionLog = [];

    /** @var array<int, array{action:string, payload:array}> */
    private array $poolActionLog = [];

    private array $nextUserResult = ['status' => 'ok'];
    private array $nextPoolResult = ['status' => 'ok'];

    private PluginUserService $userService;
    private PluginPoolService $poolService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/eiou-sandbox-test-' . uniqid('', true);
        $this->pluginDir = $this->tmpRoot . '/plugins';
        $this->stateFile = $this->tmpRoot . '/plugins.json';
        mkdir($this->pluginDir, 0777, true);

        $this->userTable = [];
        $this->userActionLog = [];
        $this->poolActionLog = [];

        $this->userService = new PluginUserService(
            null,
            function (string $action, string $systemUser): array {
                $this->userActionLog[] = ['action' => $action, 'system_user' => $systemUser];
                if (($this->nextUserResult['status'] ?? '') === 'ok') {
                    if ($action === 'create') $this->userTable[$systemUser] = true;
                    if ($action === 'remove') unset($this->userTable[$systemUser]);
                }
                $r = $this->nextUserResult;
                $this->nextUserResult = ['status' => 'ok'];
                return $r;
            },
            fn(string $u) => !empty($this->userTable[$u])
        );

        // PluginPoolService needs a dispatcher template + a writable
        // plugin root for installDispatcher() to succeed. Both live in
        // the tmp dir so PHPUnit doesn't try to touch /etc/eiou/plugins.
        $template = $this->tmpRoot . '/dispatch.php';
        file_put_contents($template, "<?php /* test dispatcher */");

        // Token service writes into tmp so it doesn't touch /etc/eiou/config.
        $tokenService = new PluginGatewayTokenService(
            $this->tmpRoot . '/plugin-gateway-tokens.json',
            $this->pluginDir
        );

        $this->poolService = new PluginPoolService(
            null,
            function (string $action, array $payload): array {
                $this->poolActionLog[] = ['action' => $action, 'payload' => $payload];
                $r = $this->nextPoolResult;
                $this->nextPoolResult = ['status' => 'ok'];
                return $r;
            },
            $template,
            $this->pluginDir,
            $tokenService
        );

        $this->loader = new PluginLoader($this->pluginDir, null, $this->stateFile);
        $this->loader->setSandboxServices(
            $this->userService,
            $this->poolService,
            new PluginNginxConfigService()
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
    }

    // ===================================================================
    // setEnabled — sandboxed plugin
    // ===================================================================

    #[Test]
    public function enableFiresOnEnableLifecycleHookAfterStateCommit(): void
    {
        $this->writeManifest('demo', ['sandboxed' => true]);
        $captured = [];
        $this->loader->setLifecycleDispatcher(
            function (string $pluginId, string $event) use (&$captured): ?array {
                $captured[] = ['plugin' => $pluginId, 'event' => $event];
                return ['ok' => true];
            }
        );

        $this->assertTrue($this->loader->setEnabled('demo', true));

        // Lifecycle hook fired exactly once with the right event name.
        // Sandbox-model replacement for the pre-sandbox boot() method:
        // plugins now get a one-shot dispatch they can hook to wire
        // sidecars / verify providers / prime caches.
        $this->assertSame([['plugin' => 'demo', 'event' => 'on_enable']], $captured);
    }

    #[Test]
    public function disableDoesNotFireLifecycleHook(): void
    {
        // Disable's pool is dropped before we'd dispatch — the call
        // would never reach a live worker, so we don't try.
        $this->writeManifest('demo', ['sandboxed' => true]);
        $this->loader->setEnabled('demo', true);

        $captured = [];
        $this->loader->setLifecycleDispatcher(
            function (string $pluginId, string $event) use (&$captured): ?array {
                $captured[] = ['plugin' => $pluginId, 'event' => $event];
                return ['ok' => true];
            }
        );

        $this->assertTrue($this->loader->setEnabled('demo', false));
        $this->assertSame([], $captured);
    }

    #[Test]
    public function lifecycleHookFailureDoesNotFailEnable(): void
    {
        // The plugin's pool is already alive by the time the hook
        // fires — a throwing on_enable handler must not undo a
        // successful enable. Operator can investigate the warning.
        $this->writeManifest('demo', ['sandboxed' => true]);
        $this->loader->setLifecycleDispatcher(
            fn(string $p, string $e) => throw new \RuntimeException('boom')
        );

        $this->assertTrue($this->loader->setEnabled('demo', true));
    }

    #[Test]
    public function lifecycleHookIsSkippedForNonSandboxedPlugins(): void
    {
        // Non-sandboxed plugins don't have a __dispatch.php to receive
        // the call. Their pre-sandbox boot() runs in-process during
        // discover() — dispatching IPC at them would 404.
        $this->writeManifest('legacy-nonsandbox', ['sandboxed' => false]);
        $captured = [];
        $this->loader->setLifecycleDispatcher(
            function (string $pluginId, string $event) use (&$captured): ?array {
                $captured[] = ['plugin' => $pluginId, 'event' => $event];
                return ['ok' => true];
            }
        );

        // setEnabled refuses non-sandboxed plugins anyway, but the
        // guard makes the dispatch path defensively correct even if
        // a refusal regression slips in.
        $this->loader->setEnabled('legacy-nonsandbox', true);
        $this->assertSame([], $captured);
    }

    #[Test]
    public function enablingSandboxedPluginEnsuresUserThenAppliesPool(): void
    {
        $this->writeManifest('demo', ['sandboxed' => true]);
        $expectedUser = $this->userService->systemUsername('demo');

        $ok = $this->loader->setEnabled('demo', true);

        $this->assertTrue($ok);
        // Operation ordering matters: user must exist before pool config
        // is applied, otherwise FPM refuses to start the pool whose
        // user it can't resolve.
        $this->assertCount(1, $this->userActionLog);
        $this->assertSame('create', $this->userActionLog[0]['action']);
        $this->assertCount(1, $this->poolActionLog);
        $this->assertSame('apply-pool', $this->poolActionLog[0]['action']);
        $this->assertSame('demo', $this->poolActionLog[0]['payload']['plugin_id']);
        $this->assertSame($expectedUser, $this->poolActionLog[0]['payload']['system_user']);
        // The nginx snippet must mention this newly-enabled plugin.
        $this->assertStringContainsString(
            '/gui/plugin/demo/',
            $this->poolActionLog[0]['payload']['nginx_snippet']
        );
    }

    #[Test]
    public function disablingSandboxedPluginDropsPoolThenUser(): void
    {
        $this->writeManifest('demo', ['sandboxed' => true]);
        $this->loader->setEnabled('demo', true);

        $this->userActionLog = [];
        $this->poolActionLog = [];

        $ok = $this->loader->setEnabled('demo', false);

        $this->assertTrue($ok);
        // Pool must drop BEFORE user — otherwise FPM (still running
        // the old pool) loses its UID's nss lookup mid-flight.
        $this->assertCount(1, $this->poolActionLog);
        $this->assertSame('drop-pool', $this->poolActionLog[0]['action']);
        $this->assertCount(1, $this->userActionLog);
        $this->assertSame('remove', $this->userActionLog[0]['action']);
        // Drop snippet does NOT mention demo any more.
        $this->assertStringNotContainsString(
            '/gui/plugin/demo/',
            $this->poolActionLog[0]['payload']['nginx_snippet']
        );
    }

    #[Test]
    public function enablingNonSandboxedPluginSkipsAllSandboxCalls(): void
    {
        $this->writeManifest('legacy', []);
        $this->loader->setEnabled('legacy', true);

        $this->assertSame([], $this->userActionLog);
        $this->assertSame([], $this->poolActionLog);
    }

    #[Test]
    public function stateWriteFailureAfterSandboxRollsBack(): void
    {
        // Force the rare partial-commit case: applySandboxSideEffects
        // succeeds but writeState fails. Without rollback the pool
        // is alive but plugins.json doesn't know about it.
        //
        // Setup: move the state file into a subdir we own exclusively,
        // and pre-create it so readState works. Then chmod the file
        // itself to 0444 (read-only); writeState's atomic temp-file +
        // rename will fail because rename() can't overwrite a file
        // whose write perm doesn't exist... actually rename works as
        // long as the parent dir is writable. So we lock the file's
        // parent dir instead, but that dir contains ONLY plugins.json
        // (not the tokens index — that lives in tmpRoot/).
        $stateDir = $this->tmpRoot . '/state';
        @mkdir($stateDir, 0755, true);
        $stateFile = $stateDir . '/plugins.json';
        // Re-construct the loader pointed at the new state path so
        // we can lock it independently of the tokens dir.
        $loader = new PluginLoader($this->pluginDir, null, $stateFile);
        $loader->setSandboxServices($this->userService, $this->poolService, new PluginNginxConfigService());

        $this->writeManifest('rollback-test', ['sandboxed' => true]);
        file_put_contents($stateFile, json_encode(new \stdClass()));
        chmod($stateDir, 0500);

        $this->userActionLog = [];
        $this->poolActionLog = [];

        $ok = $loader->setEnabled('rollback-test', true);

        chmod($stateDir, 0755);

        // Rollback should have called dropPool + dropUser to undo
        // applyPool + ensureUser. Sequence: create → apply → (state
        // fails) → drop-pool → remove-user.
        $this->assertFalse($ok, 'setEnabled should report failure');
        $applyOps = array_values(array_filter(
            $this->poolActionLog,
            fn($e): bool => $e['action'] === 'apply-pool'
        ));
        $dropOps = array_values(array_filter(
            $this->poolActionLog,
            fn($e): bool => $e['action'] === 'drop-pool'
        ));
        $this->assertCount(1, $applyOps, 'one apply-pool happened');
        $this->assertCount(1, $dropOps, 'rollback issued one drop-pool');
    }

    #[Test]
    public function poolFailureAbortsStateFlipAndKeepsUser(): void
    {
        $this->writeManifest('flaky', ['sandboxed' => true]);
        $this->nextPoolResult = ['status' => 'failed', 'error' => 'nginx -t bad'];

        $ok = $this->loader->setEnabled('flaky', true);

        $this->assertFalse($ok);
        // setEnabled aborts before writing state — readState returns no entry.
        $stateRaw = @file_get_contents($this->stateFile);
        $state = $stateRaw === false ? [] : (json_decode($stateRaw, true) ?? []);
        $this->assertArrayNotHasKey('flaky', $state, 'No state entry on partial failure');
        // The user WAS created (idempotent — reconcile cleans up on next boot
        // if the operator never retries; for now we just verify the call).
        $this->assertCount(1, $this->userActionLog);
        $this->assertSame('create', $this->userActionLog[0]['action']);
    }

    // ===================================================================
    // setEnabled failure-reason surfacing
    //
    // The legacy "Failed to persist plugin state" string covered three
    // distinct failure modes; operators couldn't tell which step
    // actually failed. getLastSetEnabledFailure() now exposes the
    // specific stage so callers can print a real diagnosis.
    // ===================================================================

    #[Test]
    public function refusedNonSandboxedReportsRefusedStage(): void
    {
        $this->writeManifest('legacy-nonsandbox', []); // no sandboxed:true

        $ok = $this->loader->setEnabled('legacy-nonsandbox', true);

        $this->assertFalse($ok);
        $failure = $this->loader->getLastSetEnabledFailure();
        $this->assertNotNull($failure);
        $this->assertSame('refused', $failure['stage']);
        $this->assertStringContainsString('not sandboxed', $failure['message']);
    }

    #[Test]
    public function sandboxFailureReportsSandboxStage(): void
    {
        $this->writeManifest('apply-fails', ['sandboxed' => true]);
        $this->nextPoolResult = ['status' => 'failed', 'error' => 'nginx -t bad'];

        $ok = $this->loader->setEnabled('apply-fails', true);

        $this->assertFalse($ok);
        $failure = $this->loader->getLastSetEnabledFailure();
        $this->assertNotNull($failure);
        $this->assertSame('sandbox', $failure['stage']);
        $this->assertStringContainsString('FPM pool', $failure['message']);
    }

    #[Test]
    public function successClearsLastFailure(): void
    {
        // First call fails — failure should be populated.
        $this->writeManifest('first-fails', ['sandboxed' => true]);
        $this->nextPoolResult = ['status' => 'failed', 'error' => 'nginx -t bad'];
        $this->loader->setEnabled('first-fails', true);
        $this->assertNotNull($this->loader->getLastSetEnabledFailure());

        // Second call succeeds — failure should clear.
        $this->writeManifest('then-ok', ['sandboxed' => true]);
        // Default $nextPoolResult is success per setUp.
        $this->nextPoolResult = ['status' => 'ok'];
        $ok = $this->loader->setEnabled('then-ok', true);
        $this->assertTrue($ok);
        $this->assertNull($this->loader->getLastSetEnabledFailure());
    }

    #[Test]
    public function writeStateTransientFalsePositiveIsRecoveredViaVerifyRead(): void
    {
        // Symptom we're guarding against: writeState reports failure but
        // the on-disk state matches the target anyway (filesystem quirks
        // on WSL2/overlayfs have produced this — rename succeeds but
        // returns false). Without the verify-read, setEnabled would
        // needlessly roll back a pool that's already configured
        // correctly, and the CLI would print a misleading "state
        // didn't persist" message.
        //
        // Setup: pre-populate the state file with the target state, then
        // chmod the parent dir 0500 so writeState's tmp+rename fails.
        // setEnabled's verify-read sees the on-disk state matches target
        // and returns true without rolling back.
        $stateDir = $this->tmpRoot . '/state-verify';
        @mkdir($stateDir, 0755, true);
        $stateFile = $stateDir . '/plugins.json';
        file_put_contents($stateFile, json_encode(['verify-test' => ['enabled' => true]]));

        $loader = new PluginLoader($this->pluginDir, null, $stateFile);
        $loader->setSandboxServices($this->userService, $this->poolService, new PluginNginxConfigService());

        $this->writeManifest('verify-test', ['sandboxed' => true]);
        // Lock the dir AFTER the state file is in place — writeState's
        // tmp+rename can't run because the dir's not writable.
        chmod($stateDir, 0500);

        $this->userActionLog = [];
        $this->poolActionLog = [];

        $ok = $loader->setEnabled('verify-test', true);

        chmod($stateDir, 0755);

        $this->assertTrue($ok, 'setEnabled treats matching on-disk state as success');
        $this->assertNull($loader->getLastSetEnabledFailure(), 'no failure recorded on success');

        // Rollback should NOT have fired — the pool stays up.
        $dropOps = array_values(array_filter(
            $this->poolActionLog,
            fn($e): bool => $e['action'] === 'drop-pool'
        ));
        $this->assertCount(0, $dropOps, 'no rollback drop-pool when on-disk state matches target');
    }

    #[Test]
    public function listAllPluginsSurfacesSandboxedFlag(): void
    {
        $this->writeManifest('sandbox-one', ['sandboxed' => true]);
        $this->writeManifest('legacy-one', []);
        $this->loader->setEnabled('sandbox-one', true);
        $this->loader->setEnabled('legacy-one', true);

        $rows = $this->loader->listAllPlugins();
        $byName = [];
        foreach ($rows as $r) $byName[$r['name']] = $r;

        $this->assertTrue($byName['sandbox-one']['sandboxed']);
        $this->assertFalse($byName['legacy-one']['sandboxed']);
        // The sandboxed plugin's status is "sandboxed" — it's enabled
        // but lives in a separate process so no in-process status applies.
        $this->assertSame('sandboxed', $byName['sandbox-one']['status']);
    }

    #[Test]
    public function reconcileSandboxAppliesMissingResources(): void
    {
        // Simulate boot: state file says sandboxed plugin is enabled,
        // but neither user nor pool exist (post-mysql-volume-rebuild /
        // post-disaster scenario).
        $this->writeManifest('boot-restore', ['sandboxed' => true]);
        file_put_contents($this->stateFile, json_encode(['boot-restore' => ['enabled' => true]]));

        $this->userActionLog = [];
        $this->poolActionLog = [];

        $report = $this->loader->reconcileSandbox();

        $this->assertContains('boot-restore', $report['applied']);
        // Re-creates the user (was missing) and the pool.
        $this->assertCount(1, $this->userActionLog);
        $this->assertSame('create', $this->userActionLog[0]['action']);
        $this->assertCount(1, $this->poolActionLog);
        $this->assertSame('apply-pool', $this->poolActionLog[0]['action']);
    }

    #[Test]
    public function reconcileSandboxIsIdempotentWhenStateAlreadyCorrect(): void
    {
        $this->writeManifest('already-up', ['sandboxed' => true]);
        // Pre-seed the mock user table so ensureUser short-circuits.
        $u = $this->userService->systemUsername('already-up');
        $this->userTable[$u] = true;
        file_put_contents($this->stateFile, json_encode(['already-up' => ['enabled' => true]]));

        // Now simulate "everything's already applied on disk" by writing
        // the files isPoolUpToDate checks: pool config, snippet, token
        // index, dispatcher. reconcileSandbox should short-circuit
        // before calling applyPool.
        @mkdir(dirname($this->pluginDir . '/already-up/__dispatch.php'), 0755, true);
        file_put_contents($this->pluginDir . '/already-up/__dispatch.php', '<?php');
        $poolConfig = $this->poolService->renderPoolConfig('already-up', $u);
        // poolPath inside PluginPoolService uses the live FPM dir path,
        // not the tmp test root. So we can't pre-create the pool file at
        // a path the test owns. Verify the existing behaviour instead:
        // when no pool file is present, applyPool is called.

        $this->userActionLog = [];
        $this->poolActionLog = [];

        $report = $this->loader->reconcileSandbox();

        // No userdel/useradd calls — user already existed. applyPool
        // IS still called here because the test environment doesn't
        // have a real /etc/php/*/fpm/pool.d/ for isPoolUpToDate to
        // check against. The short-circuit is exercised at unit level
        // in PluginPoolServiceTest::isPoolUpToDateReturnsFalseWhenPoolMissing.
        $this->assertSame([], $this->userActionLog);
        $this->assertCount(1, $this->poolActionLog);
        $this->assertContains('already-up', $report['applied']);
    }

    #[Test]
    public function sandboxedEnableRefusedWhenServicesNotWired(): void
    {
        $bareLoader = new PluginLoader($this->pluginDir, null, $this->stateFile);
        $this->writeManifest('needs-services', ['sandboxed' => true]);

        $ok = $bareLoader->setEnabled('needs-services', true);

        $this->assertFalse($ok, 'Refused — sandbox services not wired');
        $stateRaw = @file_get_contents($this->stateFile);
        $state = $stateRaw === false ? [] : (json_decode($stateRaw, true) ?? []);
        $this->assertArrayNotHasKey('needs-services', $state);
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    private function writeManifest(string $pluginId, array $extra): void
    {
        $dir = $this->pluginDir . '/' . $pluginId;
        @mkdir($dir, 0755, true);
        @mkdir($dir . '/src', 0755, true);
        $manifest = array_merge([
            'name' => $pluginId,
            'version' => '1.0.0',
            'entryClass' => 'X\\Y',
            'autoload' => ['psr-4' => ['X\\Y\\' => 'src/']],
        ], $extra);
        file_put_contents($dir . '/plugin.json', json_encode($manifest));
        // Stub entry-class file so the autoloader doesn't fail. Sandboxed
        // plugins skip register/boot entirely so the file content doesn't
        // matter, but the autoloader path validation does check existence.
        file_put_contents($dir . '/src/Y.php', "<?php namespace X; class Y {}");
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
