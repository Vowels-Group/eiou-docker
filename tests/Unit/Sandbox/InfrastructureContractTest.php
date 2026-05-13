<?php
namespace Eiou\Tests\Sandbox;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression assertions for the infrastructure pieces that aren't
 * PHP-unit-testable directly (bash supervisor, nginx config).
 *
 * Both items below were bugs found during the Phase 5 live-container
 * witness and fixed in commit d8bf97f2. The fixes live in startup.sh
 * (bash) and nginx/eiou.conf (nginx config) — neither runs inside
 * PHPUnit. What we *can* test is that the FILES contain the load-
 * bearing lines, so a future refactor that drops them gets caught
 * before someone re-runs an end-to-end container test 4 minutes deep
 * into a build.
 *
 * Each assertion below has a comment explaining what would break if
 * the line went missing — useful both for code review and for
 * understanding what a test failure means.
 */
#[CoversNothing]
class InfrastructureContractTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    // -------------------------------------------------------------------
    // Supervisor side — startup.sh
    // -------------------------------------------------------------------

    #[Test]
    public function supervisorChownsTheGatewayTokenToThePluginUser(): void
    {
        // If this chown gets dropped, the gateway token file stays
        // owned by www-data (the PHP-FPM user that minted it). The
        // plugin's pool, running as eiou-p-<hash>, then can't read
        // its own token (mode 600 + UID mismatch). __dispatch.php's
        // core_call() returns null with "token file empty" in the
        // log — the symptom the witness caught in d8bf97f2.
        $startup = (string) file_get_contents($this->repoRoot() . '/startup.sh');
        $this->assertNotEmpty($startup);
        $this->assertMatchesRegularExpression(
            '/token_file=.*\/\.gateway-token/',
            $startup,
            'plugin_routing_poller must reference the gateway-token file path'
        );
        $this->assertMatchesRegularExpression(
            '/chown\s+"\$system_user:\$system_user"\s+"\$token_file"/',
            $startup,
            'plugin_routing_poller must chown the gateway-token file to the plugin user'
        );
    }

    #[Test]
    public function supervisorGrepFallbackUnescapesJsonSlashes(): void
    {
        // PHP's default json_encode escapes / to \/. The supervisor
        // has a grep-based JSON parser fallback (used when jq isn't
        // installed). Without the un-escape step, pool_path arrives
        // as \/etc\/php\/... and fails the safety regex that
        // requires /etc/php/<v>/fpm/pool.d/. The PHP side now sets
        // JSON_UNESCAPED_SLASHES, but the supervisor's defence-in-
        // depth un-escape keeps a forgotten flag from breaking
        // routing apply silently.
        $startup = (string) file_get_contents($this->repoRoot() . '/startup.sh');
        $this->assertMatchesRegularExpression(
            '|sed.*\\\\\\\\/|',
            $startup,
            'startup.sh grep-fallback path must sed-replace \\/ → /'
        );
    }

    // -------------------------------------------------------------------
    // nginx side — eiou.conf
    // -------------------------------------------------------------------

    #[Test]
    public function nginxExemptsPluginGatewayFromHttpsRedirect(): void
    {
        // The plugin pool calls core via http://127.0.0.1/__plugin_gateway
        // over loopback (TLS overhead serves no purpose inside the
        // container). Without this exemption nginx 301-redirects to
        // HTTPS, the pool's curl call doesn't follow redirects, and
        // every core_call returns null. Same symptom-class as the
        // token-empty case but a wholly separate bug.
        $conf = (string) file_get_contents($this->repoRoot() . '/nginx/eiou.conf');
        $this->assertMatchesRegularExpression(
            '|\$request_uri\s*~\*\s*\^/__plugin_gateway|',
            $conf,
            'nginx config must exempt /__plugin_gateway from HTTPS redirect'
        );
    }

    #[Test]
    public function nginxExemptsPerPluginDispatchEndpointsFromHttpsRedirect(): void
    {
        // Same as above, but for the IpcForwarder's dispatch URLs at
        // /gui/plugin/<id>/__dispatch. Pre-fix the forwarder's curl
        // got 301 and the response wasn't JSON, breaking every event/
        // filter/render IPC.
        $conf = (string) file_get_contents($this->repoRoot() . '/nginx/eiou.conf');
        $this->assertMatchesRegularExpression(
            '|\$request_uri\s*~\*\s*\^/gui/plugin/|',
            $conf,
            'nginx config must exempt /gui/plugin/ from HTTPS redirect'
        );
    }

    // -------------------------------------------------------------------
    // Per-plugin pool config shape
    // -------------------------------------------------------------------

    #[Test]
    public function pluginPoolNginxSnippetHasTwoFastcgiCaptures(): void
    {
        // nginx requires fastcgi_split_path_info to have exactly two
        // captures (script_name + path_info). A single-capture form
        // makes `nginx -t` fail with "must have 2 captures" — and the
        // supervisor refuses the routing-apply, so the plugin's pool
        // never goes live. Test by rendering a snippet and asserting
        // shape.
        $svc = new \Eiou\Services\PluginNginxConfigService();
        $snippet = $svc->renderSnippet([
            ['plugin_id' => 'test-plugin', 'system_user' => 'eiou-p-deadbeef'],
        ]);
        $this->assertMatchesRegularExpression(
            '|fastcgi_split_path_info\s+\^\([^)]+\)\([^)]+\)\$|',
            $snippet,
            'fastcgi_split_path_info must have exactly two captures'
        );
    }

    // -------------------------------------------------------------------
    // Dockerfile sandbox prep
    // -------------------------------------------------------------------

    #[Test]
    public function dockerfileBakesEmptyPluginSnippetAndScratchRoot(): void
    {
        // Without the empty eiou-plugins.conf, `nginx -t` fails at
        // first boot because eiou-locations.conf includes a file that
        // doesn't exist. Without /var/lib/eiou/plugin-scratch the
        // first applyPool fails when supervisor tries to mkdir under
        // a parent that doesn't exist.
        $df = (string) file_get_contents($this->repoRoot() . '/eiou.dockerfile');
        $this->assertStringContainsString(
            '/etc/nginx/snippets/eiou-plugins.conf',
            $df,
            'Dockerfile must bake the empty plugin nginx snippet'
        );
        $this->assertStringContainsString(
            '/var/lib/eiou/plugin-scratch',
            $df,
            'Dockerfile must create the plugin-scratch root dir'
        );
    }
}
