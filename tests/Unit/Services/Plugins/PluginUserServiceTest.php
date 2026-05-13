<?php
namespace Eiou\Tests\Services\Plugins;

use Eiou\Services\Plugins\PluginUserService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 of plugin sandboxing — see docs/PLUGIN_SANDBOXING.md.
 *
 * Strategy: PluginUserService talks to the OS in two places — checking
 * whether a username exists (posix_getpwnam) and asking the root-side
 * supervisor to add/remove a user (file-based RPC). Both are injected
 * via callable seams so tests run pure, no useradd / userdel invoked.
 */
#[CoversClass(PluginUserService::class)]
class PluginUserServiceTest extends TestCase
{
    /** @var array<int, array{action:string, systemUser:string}> */
    private array $actionLog = [];

    /** @var array<string, bool> systemUser => exists */
    private array $userTable = [];

    /** Pluggable result for the next action; lets a test simulate failure. */
    private array $nextActionResult = ['status' => 'ok'];

    private PluginUserService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actionLog = [];
        $this->userTable = [];
        $this->nextActionResult = ['status' => 'ok'];

        $executor = function (string $action, string $systemUser): array {
            $this->actionLog[] = ['action' => $action, 'systemUser' => $systemUser];
            // Mutate the in-memory user table the way the real supervisor
            // would mutate /etc/passwd, so userExists() seen by the next
            // call reflects the action's effect.
            if (($this->nextActionResult['status'] ?? '') === 'ok') {
                if ($action === 'create') $this->userTable[$systemUser] = true;
                if ($action === 'remove') unset($this->userTable[$systemUser]);
            }
            $result = $this->nextActionResult;
            $this->nextActionResult = ['status' => 'ok'];
            return $result;
        };

        $existsCheck = function (string $systemUser): bool {
            return !empty($this->userTable[$systemUser]);
        };

        $this->svc = new PluginUserService(null, $executor, $existsCheck);
    }

    // ===================================================================
    // systemUsername / id validation
    // ===================================================================

    #[Test]
    public function systemUsernameDerivesStablyFromPluginId(): void
    {
        $a = $this->svc->systemUsername('hello-eiou');
        $b = $this->svc->systemUsername('hello-eiou');
        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^eiou-p-[a-f0-9]{8}$/', $a);
        // 15 chars total — fits inside Linux LOGIN_NAME_MAX (32).
        $this->assertSame(15, strlen($a));
    }

    #[Test]
    public function systemUsernameDiffersForDifferentPlugins(): void
    {
        $a = $this->svc->systemUsername('plugin-a');
        $b = $this->svc->systemUsername('plugin-b');
        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function systemUsernameRejectsInvalidPluginId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->systemUsername('Has-Capitals');
    }

    #[Test]
    public function systemUsernameRejectsPathTraversalInId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->systemUsername('../escape');
    }

    // ===================================================================
    // ensureUser / dropUser idempotency
    // ===================================================================

    #[Test]
    public function ensureUserCreatesViaExecutorWhenMissing(): void
    {
        $this->assertTrue($this->svc->ensureUser('my-plugin'));
        $this->assertCount(1, $this->actionLog);
        $this->assertSame('create', $this->actionLog[0]['action']);
        $this->assertTrue($this->svc->userExists('my-plugin'));
    }

    #[Test]
    public function ensureUserIsIdempotentWhenUserAlreadyExists(): void
    {
        $username = $this->svc->systemUsername('idem-plugin');
        $this->userTable[$username] = true;

        $this->assertTrue($this->svc->ensureUser('idem-plugin'));
        $this->assertSame([], $this->actionLog, 'No supervisor call for already-existing user');
    }

    #[Test]
    public function ensureUserReturnsFalseOnExecutorFailure(): void
    {
        $this->nextActionResult = ['status' => 'failed', 'error' => 'supervisor said no'];
        $this->assertFalse($this->svc->ensureUser('bad-plugin'));
        // User table was not mutated since action failed.
        $this->assertFalse($this->svc->userExists('bad-plugin'));
    }

    #[Test]
    public function dropUserRemovesViaExecutorWhenPresent(): void
    {
        $username = $this->svc->systemUsername('drop-me');
        $this->userTable[$username] = true;

        $this->assertTrue($this->svc->dropUser('drop-me'));
        $this->assertCount(1, $this->actionLog);
        $this->assertSame('remove', $this->actionLog[0]['action']);
        $this->assertFalse($this->svc->userExists('drop-me'));
    }

    #[Test]
    public function dropUserIsIdempotentWhenUserAbsent(): void
    {
        $this->assertTrue($this->svc->dropUser('not-here'));
        $this->assertSame([], $this->actionLog);
    }

    // ===================================================================
    // reconcile()
    // ===================================================================

    #[Test]
    public function reconcileCreatesMissingUsersForInstalledPlugins(): void
    {
        $report = $this->svc->reconcile(['hello-eiou', 'fresh-plugin'], []);

        $this->assertCount(2, $report['created']);
        $this->assertSame([], $report['dropped']);
        $this->assertSame([], $report['errors']);
        $this->assertTrue($this->svc->userExists('hello-eiou'));
        $this->assertTrue($this->svc->userExists('fresh-plugin'));
    }

    #[Test]
    public function reconcileLeavesAlreadyCorrectUsersAlone(): void
    {
        $u = $this->svc->systemUsername('settled');
        $this->userTable[$u] = true;

        $report = $this->svc->reconcile(['settled'], [$u]);

        $this->assertSame([], $report['created']);
        $this->assertSame([], $report['dropped']);
        $this->assertSame([], $this->actionLog, 'No executor calls when state matches');
    }

    #[Test]
    public function reconcileDropsStrandedPluginUsers(): void
    {
        // 'orphan-user' exists on the system but no plugin claims it —
        // simulates partial-failure during a prior uninstall.
        $stranded = 'eiou-p-' . str_repeat('a', 8);
        $this->userTable[$stranded] = true;

        $report = $this->svc->reconcile([], [$stranded]);

        $this->assertSame([$stranded], $report['dropped']);
        $this->assertFalse($this->svc->userExists('does-not-matter'));
    }

    #[Test]
    public function reconcileIgnoresNonPluginPrefixUsernames(): void
    {
        // Even if the caller mistakenly passes "root" as an existing
        // username, reconcile MUST NOT pass it to the executor.
        $report = $this->svc->reconcile([], ['root', 'www-data', 'mysql']);

        $this->assertSame([], $report['dropped']);
        $this->assertSame([], $this->actionLog, 'Refused to touch non-eiou-p- usernames');
    }

    #[Test]
    public function reconcileRecordsErrorsForInvalidPluginIds(): void
    {
        $report = $this->svc->reconcile(['Bad-Capital', 'good-plugin'], []);

        $this->assertCount(1, $report['errors']);
        $this->assertSame('Bad-Capital', $report['errors'][0]['plugin_id']);
        // The valid sibling was still processed.
        $this->assertCount(1, $report['created']);
        $this->assertTrue($this->svc->userExists('good-plugin'));
    }

    #[Test]
    public function reconcileCarriesExecutorFailureIntoErrors(): void
    {
        // The supervisor refuses one action; the report surfaces it
        // without aborting the rest of the pass.
        $this->nextActionResult = ['status' => 'failed', 'error' => 'simulated'];
        $report = $this->svc->reconcile(['will-fail', 'will-pass'], []);

        $this->assertCount(1, $report['errors']);
        $this->assertCount(1, $report['created']);
    }

    // ===================================================================
    // Default executor protocol shape (sanity check, no real supervisor)
    // ===================================================================

    #[Test]
    public function defaultExecutorTimesOutWhenNoSupervisorAnswers(): void
    {
        // No supervisor is running in the test environment, so the
        // default executor must give up after RESULT_TIMEOUT_SECONDS and
        // not hang the test. We dial the timeout down via reflection so
        // the suite doesn't waste 5s on this one case.
        $svc = new PluginUserService(
            null,
            null,
            function () { return false; }
        );

        // Use reflection to set RESULT_TIMEOUT_SECONDS-equivalent local —
        // not possible since it's a constant. Instead, accept the 5s
        // worst-case here; one slow test is acceptable to prove the
        // timeout works.
        $start = microtime(true);
        $ok = $svc->ensureUser('no-supervisor-here');
        $elapsed = microtime(true) - $start;

        $this->assertFalse($ok);
        // Generous upper bound — the constant is 5s; allow up to 7s for
        // CI scheduler noise.
        $this->assertLessThan(7.0, $elapsed, 'Default executor should give up around 5s');

        // Clean up any leftover request file the timed-out call left behind.
        foreach (glob('/tmp/eiou-pluser-req-*.json') ?: [] as $stale) {
            @unlink($stale);
        }
    }
}
