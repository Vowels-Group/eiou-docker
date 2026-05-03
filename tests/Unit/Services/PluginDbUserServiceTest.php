<?php
namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\PluginDbUserService;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

#[CoversClass(PluginDbUserService::class)]
class PluginDbUserServiceTest extends TestCase
{
    /** @var PDO&\PHPUnit\Framework\MockObject\MockObject */
    private $pdo;
    private PluginDbUserService $svc;

    /** @var string[] Captured DDL statements (one per exec()) */
    private array $execCalls = [];
    /** @var PDOException|null Next exec() throws this, then clears */
    private ?PDOException $execThrows = null;

    protected function setUp(): void
    {
        // PHPUnit mock uses reflection to construct without calling the
        // real PDO constructor — so we don't need an actual SQLite/MySQL
        // driver in the test environment.
        $this->pdo = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['exec', 'prepare'])
            ->getMock();

        $this->execCalls = [];
        $this->execThrows = null;

        $this->pdo->method('exec')->willReturnCallback(function (string $sql) {
            $this->execCalls[] = $sql;
            if ($this->execThrows !== null) {
                $toThrow = $this->execThrows;
                $this->execThrows = null;
                throw $toThrow;
            }
            return 0;
        });

        $this->pdo->method('prepare')->willReturnCallback(function () {
            // userExists() is the only caller; return a stmt whose
            // fetchColumn() returns false so the method reports "absent".
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchColumn')->willReturn(false);
            return $stmt;
        });

        $this->svc = new PluginDbUserService($this->pdo);
    }

    // =========================================================================
    // ensureUser()
    // =========================================================================

    public function testEnsureUserEmitsCreateAndAlter(): void
    {
        $this->svc->ensureUser('my-plugin', 'pw_plaintext', [
            'max_queries_per_hour' => 50000,
            'max_user_connections' => 25,
        ]);

        $this->assertCount(2, $this->execCalls);
        [$create, $alter] = $this->execCalls;

        // CREATE USER IF NOT EXISTS with IDENTIFIED BY + WITH clause
        $this->assertStringContainsString('CREATE USER IF NOT EXISTS', $create);
        $this->assertStringContainsString("`plugin_my_plugin`@'localhost'", $create);
        $this->assertStringContainsString("IDENTIFIED BY 'pw_plaintext'", $create);
        $this->assertStringContainsString('MAX_QUERIES_PER_HOUR 50000', $create);
        $this->assertStringContainsString('MAX_USER_CONNECTIONS 25', $create);
        // Unsupplied limits fall back to defaults.
        $this->assertStringContainsString('MAX_UPDATES_PER_HOUR 5000', $create);
        $this->assertStringContainsString('MAX_CONNECTIONS_PER_HOUR 500', $create);

        // ALTER USER follow-up — propagates rotation / limit changes on
        // every call even if the OS-level user row already existed.
        $this->assertStringContainsString('ALTER USER', $alter);
        $this->assertStringContainsString("`plugin_my_plugin`@'localhost'", $alter);
        $this->assertStringContainsString("IDENTIFIED BY 'pw_plaintext'", $alter);
    }

    public function testEnsureUserPropagatesPdoFailure(): void
    {
        $this->execThrows = new PDOException('denied');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/create_user failed.*denied/');
        $this->svc->ensureUser('my-plugin', 'pw', []);
    }

    public function testEnsureUserEscapesPasswordWithEmbeddedQuote(): void
    {
        // Real passwords come from base64 — no special chars — but defence-
        // in-depth escaping must handle interior quotes and backslashes.
        $this->svc->ensureUser('my-plugin', "pw'with\\quotes", []);

        $create = $this->execCalls[0];
        // Interior ' is escaped as \' — not a loose quote breaking out of
        // the string literal.
        $this->assertStringContainsString("IDENTIFIED BY 'pw\\'with\\\\quotes'", $create);
    }

    public function testEnsureUserIgnoresStrayLimitKeys(): void
    {
        $this->svc->ensureUser('my-plugin', 'pw', [
            'max_queries_per_hour' => 20000,
            'bogus_key' => 'wat', // not a known limit — must be dropped
        ]);

        $create = $this->execCalls[0];
        $this->assertStringNotContainsString('bogus_key', $create);
        $this->assertStringContainsString('MAX_QUERIES_PER_HOUR 20000', $create);
    }

    public function testEnsureUserFallsBackOnInvalidLimitValues(): void
    {
        $this->svc->ensureUser('my-plugin', 'pw', [
            'max_queries_per_hour' => 'not-a-number',
            'max_user_connections' => -5,
        ]);

        $create = $this->execCalls[0];
        // Defaults, not the supplied garbage.
        $this->assertStringContainsString('MAX_QUERIES_PER_HOUR 10000', $create);
        $this->assertStringContainsString('MAX_USER_CONNECTIONS 10', $create);
    }

    // =========================================================================
    // grant() / revoke() / dropUser()
    // =========================================================================

    public function testGrantEmitsExpectedStatement(): void
    {
        $this->svc->grant('my-plugin', ['plugin_my_plugin_subs']);

        $this->assertCount(1, $this->execCalls);
        $sql = $this->execCalls[0];

        $this->assertStringStartsWith('GRANT ', $sql);
        $this->assertStringContainsString('CREATE, ALTER, DROP, INDEX, SELECT, INSERT, UPDATE, DELETE', $sql);
        $this->assertStringContainsString('ON `eiou`.`plugin_my_plugin_%`', $sql);
        $this->assertStringContainsString("TO `plugin_my_plugin`@'localhost'", $sql);

        // CRITICAL: REFERENCES and GRANT OPTION must never appear —
        // documented security invariants.
        $this->assertStringNotContainsString('REFERENCES', $sql);
        $this->assertStringNotContainsString('GRANT OPTION', $sql);
    }

    public function testRevokeEmitsRevokeAllStatement(): void
    {
        $this->svc->revoke('my-plugin');

        $sql = $this->execCalls[0];
        $this->assertStringContainsString('REVOKE ALL PRIVILEGES', $sql);
        $this->assertStringContainsString('ON `eiou`.`plugin_my_plugin_%`', $sql);
        $this->assertStringContainsString("FROM `plugin_my_plugin`@'localhost'", $sql);
    }

    public function testRevokeToleratesErrorsFromAlreadyEmptyGrant(): void
    {
        // MySQL raises an error when REVOKing nothing — the service must
        // treat that as idempotent success.
        $this->execThrows = new PDOException('there is no such grant');
        // No exception expected:
        $this->svc->revoke('my-plugin');
        $this->assertCount(1, $this->execCalls);
    }

    public function testDropUserEmitsDropUserIfExists(): void
    {
        $this->svc->dropUser('my-plugin');
        $sql = $this->execCalls[0];
        $this->assertStringContainsString('DROP USER IF EXISTS', $sql);
        $this->assertStringContainsString("`plugin_my_plugin`@'localhost'", $sql);
    }

    // =========================================================================
    // Identifier helpers
    // =========================================================================

    public function testMysqlUsernameForKebabCasePluginName(): void
    {
        $this->assertSame(
            "`plugin_my_awesome_plugin`@'localhost'",
            $this->svc->mysqlUsernameFor('my-awesome-plugin')
        );
    }

    public function testGrantPatternForKebabCasePluginName(): void
    {
        $this->assertSame(
            '`eiou`.`plugin_my_awesome_plugin_%`',
            $this->svc->grantPatternFor('my-awesome-plugin')
        );
    }

    public function testHostBindingIsAlwaysLocalhostNeverWildcard(): void
    {
        // This is a correctness + security invariant — never emit
        // @'%' (wildcard host) for a plugin user.
        $this->svc->ensureUser('my-plugin', 'pw', []);
        $this->svc->grant('my-plugin', []);
        $this->svc->revoke('my-plugin');
        $this->svc->dropUser('my-plugin');

        foreach ($this->execCalls as $sql) {
            $this->assertStringNotContainsString("@'%'", $sql);
            $this->assertStringContainsString("@'localhost'", $sql);
        }
    }

    // =========================================================================
    // plugin-id validation
    // =========================================================================

    public function testEveryEntryPointValidatesPluginId(): void
    {
        $methods = [
            ['ensureUser', ['BAD', 'pw', []]],
            ['grant', ['BAD', []]],
            ['revoke', ['BAD']],
            ['dropUser', ['BAD']],
            ['userExists', ['BAD']],
            ['mysqlUsernameFor', ['BAD']],
            ['grantPatternFor', ['BAD']],
        ];
        foreach ($methods as [$method, $args]) {
            try {
                $this->svc->$method(...$args);
                $this->fail("$method did not validate plugin id");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid plugin id', $e->getMessage());
            }
        }
        // None of those calls should have issued any SQL.
        $this->assertEmpty($this->execCalls);
    }
}
