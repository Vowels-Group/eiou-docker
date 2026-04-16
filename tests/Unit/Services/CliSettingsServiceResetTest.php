<?php
/**
 * Unit Tests for CliSettingsService::changeSettings() "reset" branch
 *
 * Covers the `eiou settings reset` CLI path that landed in PR #851. The
 * other changeSettings branches (defaultFee, minFee, etc.) pre-date this
 * work and are covered elsewhere or through integration tests.
 */

namespace Eiou\Tests\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Eiou\Services\CliSettingsService;
use Eiou\Cli\CliOutputManager;
use Eiou\Core\UserContext;

#[CoversClass(CliSettingsService::class)]
class CliSettingsServiceResetTest extends TestCase
{
    private CliSettingsService $service;

    protected function setUp(): void
    {
        // UserContext is a singleton; the reset branch pulls the instance
        // via UserContext::getInstance(), not via the constructor-injected
        // one, so the specific mock here doesn't matter for these tests.
        $userContext = $this->createMock(UserContext::class);
        $this->service = new CliSettingsService($userContext);
    }

    /**
     * Test `eiou settings reset` (no --yes) prints the typed-confirmation
     * warning and returns without wiping anything. This is the guard that
     * prevents accidental resets.
     */
    public function testResetWithoutYesPrintsWarningAndAborts(): void
    {
        $output = $this->createMock(CliOutputManager::class);
        $output->expects($this->once())
            ->method('error')
            ->with($this->stringContains('--yes'));
        $output->expects($this->never())->method('success');

        $this->service->changeSettings(['eiou', 'settings', 'reset'], $output);
    }

    /**
     * Test `eiou settings reset -y` is accepted as equivalent to `--yes`.
     * The --yes gate should open — any error message we see will be from
     * the downstream `UserContext::resetToDefaults()` file write (expected
     * outside Docker), not the "requires --yes" guard.
     */
    public function testResetAcceptsShortYesFlag(): void
    {
        $errors = $this->captureErrors();
        $this->service->changeSettings(['eiou', 'settings', 'reset', '-y'], $errors['mock']);
        $this->assertGateOpened($errors['messages']);
    }

    /**
     * Test the --yes long form is also accepted (lowercase).
     */
    public function testResetAcceptsLongYesFlag(): void
    {
        $errors = $this->captureErrors();
        $this->service->changeSettings(['eiou', 'settings', 'reset', '--yes'], $errors['mock']);
        $this->assertGateOpened($errors['messages']);
    }

    /**
     * Test the --yes confirmation is case-insensitive (`--YES`, `--Yes`,
     * `-Y` should all work). The service calls strtolower() before
     * comparing.
     */
    public function testResetYesFlagIsCaseInsensitive(): void
    {
        $errors = $this->captureErrors();
        $this->service->changeSettings(['eiou', 'settings', 'reset', '--YES'], $errors['mock']);
        $this->assertGateOpened($errors['messages']);
    }

    /**
     * Build a CliOutputManager mock that records every error() message
     * into a shared array, so we can assert which errors were / weren't
     * raised after the call returns (instead of up-front expects() —
     * which don't compose well with the mixed "gate rejects" vs
     * "gate opens but downstream fails" paths).
     *
     * @return array{mock: CliOutputManager, messages: \ArrayObject<int,string>}
     */
    private function captureErrors(): array
    {
        $messages = new \ArrayObject();
        $mock = $this->createMock(CliOutputManager::class);
        $mock->method('error')->willReturnCallback(function ($msg) use ($messages) {
            $messages[] = $msg;
        });
        return ['mock' => $mock, 'messages' => $messages];
    }

    /**
     * Assert that the --yes gate opened (no "Re-run with --yes" error
     * was recorded). Downstream errors from resetToDefaults() hitting the
     * real config path are allowed — those come after the gate.
     */
    private function assertGateOpened(\ArrayObject $messages): void
    {
        foreach ($messages as $msg) {
            $this->assertStringNotContainsString(
                'Re-run with',
                $msg,
                '--yes gate should have opened, but got guard error: ' . $msg
            );
        }
    }

    /**
     * Test an unrecognized confirmation token (not --yes / -y) is treated
     * as "no confirmation" and produces the guard error.
     */
    public function testResetRejectsUnrecognizedConfirmation(): void
    {
        $output = $this->createMock(CliOutputManager::class);
        $output->expects($this->once())
            ->method('error')
            ->with($this->stringContains('--yes'));

        $this->service->changeSettings(['eiou', 'settings', 'reset', 'confirm'], $output);
    }
}
