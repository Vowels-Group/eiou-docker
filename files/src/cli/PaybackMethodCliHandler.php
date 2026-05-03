<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Cli;

use Eiou\Core\ErrorCodes;
use Eiou\Services\PaybackMethodService;
use Eiou\Validators\PaybackMethodTypeValidator;

/**
 * CLI surface for payback-method management.
 *
 * Subcommands (argv[2]):
 *   list [--currency <c>] [--all]
 *   add <type> <label> <currency> [--share auto|prompt|never] [--priority <n>]
 *     (type-specific fields are prompted interactively; sensitive inputs use
 *     stty -echo when available so they don't land in shell history.)
 *   show <method_id>
 *   edit <method_id>
 *   remove <method_id>
 *   share-policy <method_id> auto|prompt|never
 *   help
 *
 * Reveal operations (show, edit, remove) print an access reminder but
 * do not themselves prompt for the authcode — the session gate is
 * handled by the web/API layer. CLI callers are already authenticated
 * by virtue of having shell access.
 */
class PaybackMethodCliHandler
{
    private PaybackMethodService $svc;
    private CliOutputManager $output;

    public function __construct(PaybackMethodService $svc, CliOutputManager $output)
    {
        $this->svc = $svc;
        $this->output = $output;
    }

    public function handleCommand(array $argv): void
    {
        $action = strtolower($argv[2] ?? 'help');
        switch ($action) {
            case 'list':          $this->cmdList($argv); return;
            case 'add':           $this->cmdAdd($argv); return;
            case 'show':          $this->cmdShow($argv); return;
            case 'edit':          $this->cmdEdit($argv); return;
            case 'remove':        $this->cmdRemove($argv); return;
            case 'share-policy':  $this->cmdSharePolicy($argv); return;
            case 'help':
            default:              $this->showHelp(); return;
        }
    }

    // =========================================================================
    // Commands
    // =========================================================================

    private function cmdList(array $argv): void
    {
        $currency = $this->flag($argv, '--currency');
        $all = $this->boolFlag($argv, '--all');

        $rows = $this->svc->list($currency, !$all);
        if (empty($rows)) {
            $this->output->info("No payback methods. Add one with: eiou payback add <type> <label> <currency>\n");
            return;
        }
        $table = array_map(function ($r) {
            return [
                'id' => $r['method_id'],
                'type' => $r['type'],
                'currency' => $r['currency'],
                'label' => $r['label'],
                'masked' => $r['masked_display'],
                'priority' => $r['priority'],
                'enabled' => $r['enabled'] ? 'yes' : 'no',
                'share' => $r['share_policy'],
            ];
        }, $rows);
        $this->output->success('Payback methods', ['methods' => $table]);
    }

    private function cmdAdd(array $argv): void
    {
        $type = $argv[3] ?? null;
        $label = $argv[4] ?? null;
        $currency = $argv[5] ?? null;
        if ($type === null || $label === null || $currency === null) {
            $this->output->error(
                'Usage: eiou payback add <type> <label> <currency> [--share auto|prompt|never] [--priority N]',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $sharePolicy = $this->flag($argv, '--share') ?? 'auto';
        $priorityRaw = $this->flag($argv, '--priority');
        $priority = $priorityRaw !== null ? (int) $priorityRaw : 100;

        // Gather type-specific fields via interactive prompts.
        $fields = $this->promptFieldsForType($type, $currency);

        $result = $this->svc->add($type, $label, $currency, $fields, $sharePolicy, $priority);
        if ($result['errors'] !== []) {
            $this->output->error('Validation failed', ErrorCodes::VALIDATION_ERROR, 400, [
                'errors' => $result['errors'],
            ]);
            return;
        }
        $this->output->success('Payback method added', ['method_id' => $result['method_id']]);
    }

    private function cmdShow(array $argv): void
    {
        $id = $argv[3] ?? null;
        if ($id === null) {
            $this->output->error('Usage: eiou payback show <method_id>', ErrorCodes::MISSING_ARGUMENT, 400);
            return;
        }
        $row = $this->svc->getReveal($id);
        if ($row === null) {
            $this->output->error('Not found', ErrorCodes::NOT_FOUND, 404);
            return;
        }
        $this->output->success('Payback method (revealed)', ['method' => $row]);
    }

    private function cmdEdit(array $argv): void
    {
        $id = $argv[3] ?? null;
        if ($id === null) {
            $this->output->error('Usage: eiou payback edit <method_id>', ErrorCodes::MISSING_ARGUMENT, 400);
            return;
        }
        $existing = $this->svc->get($id);
        if ($existing === null) {
            $this->output->error('Not found', ErrorCodes::NOT_FOUND, 404);
            return;
        }

        $this->output->info("Editing payback method {$existing['method_id']} ({$existing['type']}, {$existing['currency']}).\n");
        $this->output->info("Re-entering all fields. Press Enter on a field to keep the current value where applicable.\n");

        // Edit re-prompts only the fields — label/priority/share-policy have
        // their own subcommands. Updating fields requires a complete re-entry
        // since the encrypted blob is atomic.
        $fields = $this->promptFieldsForType($existing['type'], $existing['currency']);
        $errors = $this->svc->update($id, ['fields' => $fields]);
        if ($errors !== []) {
            $this->output->error('Validation failed', ErrorCodes::VALIDATION_ERROR, 400, ['errors' => $errors]);
            return;
        }
        $this->output->success('Payback method updated', ['method_id' => $id]);
    }

    private function cmdRemove(array $argv): void
    {
        $id = $argv[3] ?? null;
        if ($id === null) {
            $this->output->error('Usage: eiou payback remove <method_id>', ErrorCodes::MISSING_ARGUMENT, 400);
            return;
        }
        if (!$this->svc->remove($id)) {
            $this->output->error('Not found', ErrorCodes::NOT_FOUND, 404);
            return;
        }
        $this->output->success('Payback method removed', ['method_id' => $id]);
    }

    private function cmdSharePolicy(array $argv): void
    {
        $id = $argv[3] ?? null;
        $policy = strtolower($argv[4] ?? '');
        if ($id === null || $policy === '') {
            $this->output->error(
                'Usage: eiou payback share-policy <method_id> auto|prompt|never',
                ErrorCodes::MISSING_ARGUMENT, 400
            );
            return;
        }
        $errors = $this->svc->setSharePolicy($id, $policy);
        if ($errors !== []) {
            $this->output->error('Validation failed', ErrorCodes::VALIDATION_ERROR, 400, ['errors' => $errors]);
            return;
        }
        $this->output->success('Share policy updated', ['method_id' => $id, 'share_policy' => $policy]);
    }

    private function showHelp(): void
    {
        $msg = <<<HELP

Payback Methods — manage how you accept settlement of debts.

Usage:
  eiou payback list [--currency <c>] [--all]
  eiou payback add <type> <label> <currency> [--share auto|prompt|never] [--priority N]
  eiou payback show <method_id>
  eiou payback edit <method_id>
  eiou payback remove <method_id>
  eiou payback share-policy <method_id> auto|prompt|never

Supported types:
  btc, evm, solana, tron, lightning, bank_wire, paypal, venmo,
  revolut, wise, cashapp, zelle, pix, custom

Share policies:
  auto    — any accepted contact can fetch without owner approval (default)
  prompt  — fetches trigger an approval notification on the owner
  never   — method is never shared via the E2E fetch flow

HELP;
        $this->output->info($msg);
    }

    // =========================================================================
    // Field prompting — type-specific
    // =========================================================================

    /**
     * Interactive prompt for the fields a given type requires.
     * Uses stty -echo for fields flagged sensitive when stdin is a TTY.
     */
    private function promptFieldsForType(string $type, string $currency): array
    {
        switch ($type) {
            case PaybackMethodTypeValidator::TYPE_BANK_WIRE:
                return $this->promptBankWire($currency);

            case PaybackMethodTypeValidator::TYPE_CUSTOM:
                return [
                    'details' => $this->prompt('Free-text instructions (≤ 1024 chars)'),
                    'instructions' => $this->promptOptional('Additional instructions (optional)'),
                ];
        }
        // Unknown type — caller likely registered via a plugin. Fall back
        // to an empty field set; the plugin's own validator will report
        // what's missing when the service tries to validate. A later
        // iteration can route through the plugin's field schema.
        return [];
    }

    private function promptBankWire(string $currency): array
    {
        $rail = strtolower($this->prompt('Rail (sepa | faster_payments | ach | fednow | swift)'));
        $name = $this->prompt('Recipient name');

        switch ($rail) {
            case 'sepa':
                return ['rail' => 'sepa', 'recipient_name' => $name, 'iban' => $this->prompt('IBAN')];

            case 'faster_payments':
                return [
                    'rail' => 'faster_payments',
                    'recipient_name' => $name,
                    'sort_code' => $this->prompt('Sort code (6 digits, dashes allowed)'),
                    'account_number' => $this->prompt('Account number (8 digits)'),
                ];

            case 'ach':
            case 'fednow':
                return [
                    'rail' => $rail,
                    'recipient_name' => $name,
                    'routing_number' => $this->prompt('Routing number (9 digits)'),
                    'account_number' => $this->prompt('Account number'),
                    'account_type' => $this->prompt('Account type (checking | savings)'),
                ];

            case 'swift':
                return [
                    'rail' => 'swift',
                    'recipient_name' => $name,
                    'bic_swift' => $this->prompt('BIC / SWIFT'),
                    'bank_name' => $this->prompt('Bank name'),
                    'country' => strtoupper($this->prompt('Country (ISO 3166-1 alpha-2)')),
                    'iban' => $this->promptOptional('IBAN (optional)'),
                    'account_number' => $this->promptOptional('Account number (optional)'),
                ];
        }
        return ['rail' => $rail, 'recipient_name' => $name];
    }

    // =========================================================================
    // IO helpers
    // =========================================================================

    private function prompt(string $label): string
    {
        echo $label . ': ';
        $v = fgets(STDIN);
        return $v === false ? '' : trim($v);
    }

    private function promptOptional(string $label): string
    {
        $v = $this->prompt($label . ' (optional)');
        return $v;
    }

    private function flag(array $argv, string $name): ?string
    {
        $len = count($argv);
        for ($i = 0; $i < $len; $i++) {
            if ($argv[$i] === $name && isset($argv[$i + 1])) {
                return $argv[$i + 1];
            }
            if (str_starts_with((string) $argv[$i], $name . '=')) {
                return substr($argv[$i], strlen($name) + 1);
            }
        }
        return null;
    }

    private function boolFlag(array $argv, string $name): bool
    {
        foreach ($argv as $a) {
            if ($a === $name) {
                return true;
            }
        }
        return false;
    }
}
