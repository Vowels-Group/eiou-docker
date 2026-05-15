<?php
# Copyright 2025-2026 Vowels Group, LLC

declare(strict_types=1);

namespace Eiou\Cli;

use Eiou\Core\ErrorCodes;
use Eiou\Core\UserContext;
use Eiou\Utils\AltCodeValidator;
use Eiou\Utils\Logger;

/**
 * CLI surface for the alternate authentication code.
 *
 * Subcommand tree (argv[2]):
 *
 *   eiou altcode status
 *   eiou altcode set            # interactively prompts (stty -echo if a TTY)
 *   eiou altcode clear          # interactively prompts for primary code
 *
 * Set/clear both gate on the primary auth code, mirroring the GUI
 * controller. Reading via stty -echo when stdin is a TTY keeps the
 * plaintext out of shell history; piped input (eiou altcode set <<<...)
 * still works for scripted operators.
 */
class AltCodeCliHandler
{
    public function __construct(private readonly CliOutputManager $output)
    {
    }

    public function handleCommand(array $argv): void
    {
        $action = strtolower((string) ($argv[2] ?? 'help'));
        switch ($action) {
            case 'status':  $this->cmdStatus(); return;
            case 'set':     $this->cmdSet(); return;
            case 'clear':   $this->cmdClear(); return;
            case 'help':
            default:        $this->showHelp(); return;
        }
    }

    private function cmdStatus(): void
    {
        $user = UserContext::getInstance();
        $has = $user->hasAltCode();
        $this->output->success(
            $has ? 'Alt code is set.' : 'Alt code is not set.',
            [
                'has_alt_code' => $has,
                'min_length' => AltCodeValidator::MIN_LENGTH,
            ]
        );
    }

    private function cmdSet(): void
    {
        $user = UserContext::getInstance();
        $expectedPrimary = $user->getAuthCode();
        if ($expectedPrimary === null) {
            $this->output->error(
                'No primary auth code is configured — initialize the wallet first.',
                ErrorCodes::GENERAL_ERROR,
                500
            );
            return;
        }

        $primary = $this->promptSecret('Primary auth code: ');
        if ($primary === '' || !hash_equals($expectedPrimary, $primary)) {
            $this->output->error(
                'Primary auth code is invalid.',
                ErrorCodes::AUTH_INVALID,
                401
            );
            return;
        }

        $newAlt = $this->promptSecret('New alt code (min ' . AltCodeValidator::MIN_LENGTH . ' chars, mixed case + digit + symbol): ');
        if ($newAlt === '') {
            $this->output->error('New alt code is required.', ErrorCodes::VALIDATION_ERROR, 400);
            return;
        }
        $confirm = $this->promptSecret('Confirm new alt code: ');
        if ($newAlt !== $confirm) {
            $this->output->error('Alt codes do not match.', ErrorCodes::VALIDATION_ERROR, 400);
            return;
        }
        if (hash_equals($expectedPrimary, $newAlt)) {
            $this->output->error(
                'Alt code must differ from the primary auth code.',
                ErrorCodes::VALIDATION_ERROR,
                400
            );
            return;
        }

        $result = AltCodeValidator::validate($newAlt);
        if (!$result['valid']) {
            $this->output->error(
                'Alt code does not meet strength requirements: ' . implode(' ', $result['errors']),
                ErrorCodes::VALIDATION_ERROR,
                400
            );
            return;
        }

        try {
            $user->setAltCode($newAlt);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('alt_code_persist_failed_cli', ['error' => $e->getMessage()]);
            $this->output->error('Could not save alt code.', ErrorCodes::INTERNAL_ERROR, 500);
            return;
        }

        Logger::getInstance()->info('alt_code_set_via_cli', ['rotated' => true]);
        $this->output->success('Alt code set.', ['has_alt_code' => true]);
    }

    private function cmdClear(): void
    {
        $user = UserContext::getInstance();
        $expectedPrimary = $user->getAuthCode();
        if ($expectedPrimary === null) {
            $this->output->error(
                'No primary auth code is configured.',
                ErrorCodes::GENERAL_ERROR,
                500
            );
            return;
        }
        if (!$user->hasAltCode()) {
            $this->output->success('No alt code is set; nothing to clear.', ['has_alt_code' => false]);
            return;
        }

        $primary = $this->promptSecret('Primary auth code: ');
        if ($primary === '' || !hash_equals($expectedPrimary, $primary)) {
            $this->output->error(
                'Primary auth code is invalid.',
                ErrorCodes::AUTH_INVALID,
                401
            );
            return;
        }

        try {
            $user->clearAltCode();
        } catch (\Throwable $e) {
            Logger::getInstance()->error('alt_code_clear_failed_cli', ['error' => $e->getMessage()]);
            $this->output->error('Could not clear alt code.', ErrorCodes::INTERNAL_ERROR, 500);
            return;
        }

        Logger::getInstance()->info('alt_code_cleared_via_cli', []);
        $this->output->success('Alt code cleared.', ['has_alt_code' => false]);
    }

    private function showHelp(): void
    {
        $this->output->info(
            "eiou altcode — manage the alternate authentication code\n"
            . "\n"
            . "Subcommands:\n"
            . "  status   Show whether an alt code is currently set\n"
            . "  set      Set or rotate the alt code (prompts for primary first)\n"
            . "  clear    Remove the alt code (prompts for primary first)\n"
            . "\n"
            . "Strength rules: min " . AltCodeValidator::MIN_LENGTH . " characters, must include at\n"
            . "least one uppercase letter, one lowercase letter, one digit, and one\n"
            . "symbol. The alt code cannot equal the primary auth code."
        );
    }

    /**
     * Read a secret from stdin with terminal echo disabled when possible.
     * Piped input (no TTY) works without stty manipulation. Returns the
     * line without the trailing newline.
     */
    private function promptSecret(string $prompt): string
    {
        $isTty = function_exists('posix_isatty') && @posix_isatty(STDIN);

        if ($isTty) {
            fwrite(STDERR, $prompt);
            // stty -echo isn't portable to every container, so guard.
            $haveStty = @shell_exec('command -v stty 2>/dev/null') !== null;
            if ($haveStty) {
                @shell_exec('stty -echo 2>/dev/null');
            }
            $line = fgets(STDIN);
            if ($haveStty) {
                @shell_exec('stty echo 2>/dev/null');
            }
            fwrite(STDERR, "\n");
        } else {
            $line = fgets(STDIN);
        }

        if ($line === false) {
            return '';
        }
        return rtrim($line, "\r\n");
    }
}
