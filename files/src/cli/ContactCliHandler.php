<?php
# Copyright 2025-2026 Vowels Group, LLC

declare(strict_types=1);

namespace Eiou\Cli;

use Eiou\Contracts\ContactServiceInterface;
use Eiou\Contracts\ContactManagementServiceInterface;
use Eiou\Contracts\ContactStatusServiceInterface;
use Eiou\Core\ErrorCodes;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\ContactRepository;
use Eiou\Services\CliService;
use Eiou\Services\ContactDecisionService;

/**
 * CLI surface for contact operations.
 *
 * Subcommand tree (argv[2] / argv[3]):
 *
 *   eiou contact add <address> <name> [--fee F --credit C --currency CCY] [--message M]
 *   eiou contact accept <pubkey-hash|address> --currency CCY --fee F --credit C
 *                                             [--currency CCY --fee F --credit C ...]
 *   eiou contact apply  <pubkey-hash|address> --from <file.json|->
 *   eiou contact apply  <pubkey-hash|address> [--accept CCY:fee:credit ...]
 *                                             [--decline CCY ...] [--defer CCY ...]
 *   eiou contact decline <pubkey-hash|address>
 *   eiou contact list   [--status accepted|pending|blocked]
 *   eiou contact pending [--incoming|--outgoing]
 *   eiou contact view   <name|address|pubkey-hash>
 *   eiou contact update <name|address> [--name N --fee F --credit C]
 *   eiou contact delete <name|address>
 *   eiou contact block  <name|address>
 *   eiou contact unblock <name|address>
 *   eiou contact ping   <name|address>
 *   eiou contact search <query>
 *
 *   eiou contact currency add     <contact> <currency> --fee F --credit C
 *   eiou contact currency accept  <contact> <currency> --fee F --credit C
 *   eiou contact currency decline <contact> <currency>
 *   eiou contact currency list    <contact>
 *   eiou contact currency remove  <contact> <currency>
 *
 * Identifier resolution: <contact> accepts a name, address, or pubkey-hash.
 * Name lookup falls back to interactive disambiguation already implemented in
 * ContactManagementService::lookupContactInfoWithDisambiguation.
 */
class ContactCliHandler
{
    public function __construct(
        private readonly ContactServiceInterface $contactService,
        private readonly ContactManagementServiceInterface $managementService,
        private readonly ContactDecisionService $decisionService,
        private readonly ContactStatusServiceInterface $statusService,
        private readonly CliService $cliService,
        private readonly ContactRepository $contactRepository,
        private readonly ContactCurrencyRepository $contactCurrencyRepository,
        private readonly CliOutputManager $output,
    ) {
    }

    public function handleCommand(array $argv): void
    {
        $action = strtolower((string) ($argv[2] ?? 'help'));
        switch ($action) {
            case 'add':       $this->cmdAdd($argv); return;
            case 'accept':    $this->cmdAccept($argv); return;
            case 'apply':     $this->cmdApply($argv); return;
            case 'decline':   $this->cmdDecline($argv); return;
            case 'list':      $this->cmdList($argv); return;
            case 'pending':   $this->cmdPending($argv); return;
            case 'view':      $this->cmdView($argv); return;
            case 'update':    $this->cmdUpdate($argv); return;
            case 'delete':    $this->cmdDelete($argv); return;
            case 'block':     $this->cmdBlock($argv); return;
            case 'unblock':   $this->cmdUnblock($argv); return;
            case 'ping':      $this->cmdPing($argv); return;
            case 'search':    $this->cmdSearch($argv); return;
            case 'currency':  $this->handleCurrencyCommand($argv); return;
            case 'help':
            default:          $this->showHelp(); return;
        }
    }

    // =========================================================================
    // contact add / accept / apply / decline
    // =========================================================================

    private function cmdAdd(array $argv): void
    {
        $address = $argv[3] ?? null;
        $name = $argv[4] ?? null;
        if ($address === null || $name === null) {
            $this->output->error(
                'Usage: eiou contact add <address> <name> [--fee F --credit C --currency CCY] [--message M]',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $fee = $this->flag($argv, '--fee') ?? '0';
        $credit = $this->flag($argv, '--credit') ?? '0';
        $currency = $this->flag($argv, '--currency') ?? 'USD';
        $message = $this->flag($argv, '--message');

        // Build the addContact-style argv for the underlying service. The
        // service uses positional args for backward compatibility:
        //   [0]=eiou [1]=add [2]=address [3]=name [4]=fee [5]=credit
        //   [6]=currency [7]=requested_credit (NULL placeholder) [8]=message
        $serviceArgv = ['eiou', 'add', $address, $name, $fee, $credit, $currency, 'NULL'];
        if ($message !== null && $message !== '') {
            $serviceArgv[] = $message;
        }
        $this->contactService->addContact($serviceArgv, $this->output);
    }

    private function cmdAccept(array $argv): void
    {
        $identifier = $argv[3] ?? null;
        if ($identifier === null) {
            $this->output->error(
                'Usage: eiou contact accept <pubkey-hash|address> --currency CCY --fee F --credit C [--currency …]',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $pubkeyHash = $this->resolvePubkeyHash($identifier);
        if ($pubkeyHash === null) {
            $this->output->error(
                "No contact matching '{$identifier}'",
                ErrorCodes::CONTACT_NOT_FOUND,
                404
            );
            return;
        }

        $decisions = $this->parseAcceptTriplets($argv);
        if ($decisions === null) {
            $this->output->error(
                'Each --currency requires matching --fee and --credit values',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }
        if (empty($decisions)) {
            $this->output->error(
                'At least one --currency CCY --fee F --credit C triplet required',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $isNewContact = $this->isPendingContact($pubkeyHash);
        $contactInfo = $this->lookupContactDetails($pubkeyHash);

        $result = $this->decisionService->apply(
            $pubkeyHash,
            $decisions,
            $isNewContact,
            $contactInfo['address'] ?? null,
            $contactInfo['name'] ?? $this->flag($argv, '--name'),
        );

        $this->emitApplyResult($result);
    }

    private function cmdApply(array $argv): void
    {
        $identifier = $argv[3] ?? null;
        if ($identifier === null) {
            $this->output->error(
                'Usage: eiou contact apply <pubkey-hash|address> --from <file.json|-> | [--accept CCY:fee:credit] [--decline CCY] [--defer CCY]',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $pubkeyHash = $this->resolvePubkeyHash($identifier);
        if ($pubkeyHash === null) {
            $this->output->error(
                "No contact matching '{$identifier}'",
                ErrorCodes::CONTACT_NOT_FOUND,
                404
            );
            return;
        }

        $fromPath = $this->flag($argv, '--from');
        if ($fromPath !== null) {
            $decisions = $this->loadDecisionsFromFile($fromPath);
            if ($decisions === null) {
                return; // error already emitted
            }
        } else {
            $decisions = $this->parseDecisionFlags($argv);
        }

        if (empty($decisions)) {
            $this->output->error(
                'No decisions to apply — provide --from <file|-> or per-currency flags',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $isNewContact = $this->isPendingContact($pubkeyHash);
        $contactInfo = $this->lookupContactDetails($pubkeyHash);

        $result = $this->decisionService->apply(
            $pubkeyHash,
            $decisions,
            $isNewContact,
            $contactInfo['address'] ?? null,
            $contactInfo['name'] ?? null,
        );

        $this->emitApplyResult($result);
    }

    private function cmdDecline(array $argv): void
    {
        $identifier = $argv[3] ?? null;
        if ($identifier === null) {
            $this->output->error(
                'Usage: eiou contact decline <pubkey-hash|address>',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $pubkeyHash = $this->resolvePubkeyHash($identifier);
        if ($pubkeyHash === null) {
            $this->output->error(
                "No contact matching '{$identifier}'",
                ErrorCodes::CONTACT_NOT_FOUND,
                404
            );
            return;
        }

        $pending = $this->contactCurrencyRepository->getPendingCurrencies($pubkeyHash, 'incoming');
        if (empty($pending)) {
            $this->output->info("No pending currencies to decline for this contact.\n");
            return;
        }

        $declined = [];
        $errors = [];
        foreach ($pending as $row) {
            $ccy = strtoupper((string) $row['currency']);
            try {
                $this->contactCurrencyRepository->declineIncomingCurrency($pubkeyHash, $ccy);
                $declined[] = $ccy;
            } catch (\Throwable $e) {
                $errors[] = "{$ccy}: " . $e->getMessage();
            }
        }

        if (empty($errors)) {
            $this->output->success(
                'Contact request declined',
                ['declined' => $declined]
            );
        } else {
            $this->output->error(
                'Some declines failed',
                ErrorCodes::GENERAL_ERROR,
                500,
                ['declined' => $declined, 'errors' => $errors]
            );
        }
    }

    // =========================================================================
    // Lookups, listings, status
    // =========================================================================

    private function cmdList(array $argv): void
    {
        $status = $this->flag($argv, '--status');
        $grouped = $this->managementService->getContactsGroupedByStatus();
        if ($status !== null) {
            $key = strtolower($status);
            $rows = $grouped[$key] ?? [];
            $this->output->success("Contacts ({$key})", ['contacts' => $rows]);
            return;
        }
        $this->output->success('Contacts', $grouped);
    }

    private function cmdPending(array $argv): void
    {
        // The CliService renderer already supports both incoming and outgoing
        // sections — the new --incoming / --outgoing flags filter the output
        // post-format (no separate code path needed).
        $this->cliService->displayPendingContacts($argv, $this->output);
    }

    private function cmdView(array $argv): void
    {
        $this->managementService->viewContact($argv, $this->output);
    }

    private function cmdUpdate(array $argv): void
    {
        $this->managementService->updateContact($argv, $this->output);
    }

    private function cmdDelete(array $argv): void
    {
        if (!$this->contactService->deleteContact($argv[3] ?? null, $this->output)) {
            // deleteContact emits its own error message via $output.
            return;
        }
    }

    private function cmdBlock(array $argv): void
    {
        $this->contactService->blockContact($argv[3] ?? null, $this->output);
    }

    private function cmdUnblock(array $argv): void
    {
        $this->contactService->unblockContact($argv[3] ?? null, $this->output);
    }

    private function cmdPing(array $argv): void
    {
        $identifier = $argv[3] ?? null;
        if ($identifier === null) {
            $this->output->error(
                'Usage: eiou contact ping <name|address>',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $result = $this->statusService->pingContact($identifier);
        if ($result['success']) {
            $this->output->success(
                "Ping complete: {$result['contact_name']} is {$result['online_status']}",
                [
                    'contact_name' => $result['contact_name'],
                    'online_status' => $result['online_status'],
                    'chain_valid' => $result['chain_valid'],
                    'message' => $result['message'],
                ]
            );
            return;
        }

        $code = ($result['error'] ?? '') === 'contact_not_found'
            ? ErrorCodes::CONTACT_NOT_FOUND
            : ErrorCodes::GENERAL_ERROR;
        $this->output->error($result['message'], $code);
    }

    private function cmdSearch(array $argv): void
    {
        $this->managementService->searchContacts($argv, $this->output);
    }

    // =========================================================================
    // contact currency …
    // =========================================================================

    private function handleCurrencyCommand(array $argv): void
    {
        $action = strtolower((string) ($argv[3] ?? 'help'));
        switch ($action) {
            case 'add':      $this->cmdCurrencyAdd($argv); return;
            case 'accept':   $this->cmdCurrencyAccept($argv); return;
            case 'decline':  $this->cmdCurrencyDecline($argv); return;
            case 'list':     $this->cmdCurrencyList($argv); return;
            case 'remove':   $this->cmdCurrencyRemove($argv); return;
            case 'help':
            default:         $this->showCurrencyHelp(); return;
        }
    }

    private function cmdCurrencyAdd(array $argv): void
    {
        $contact = $argv[4] ?? null;
        $currency = $argv[5] ?? null;
        $fee = $this->flag($argv, '--fee');
        $credit = $this->flag($argv, '--credit');
        if ($contact === null || $currency === null || $fee === null || $credit === null) {
            $this->output->error(
                'Usage: eiou contact currency add <contact> <currency> --fee F --credit C',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $address = $this->resolveAddress($contact);
        if ($address === null) {
            $this->output->error(
                "No contact matching '{$contact}'",
                ErrorCodes::CONTACT_NOT_FOUND,
                404
            );
            return;
        }

        // Reuse the addContact pipeline so all the validation + dispatch logic
        // (createOutgoing / acceptIncoming / addCurrencyToExisting) is applied
        // exactly once. Pass the contact's known name (or the same address
        // again if no name yet) so the validator is happy.
        $existing = $this->contactRepository->getContactByAddress(
            $this->guessTransportIndex($address),
            $address
        );
        $name = $existing['name'] ?? $contact;

        $serviceArgv = ['eiou', 'add', $address, $name, $fee, $credit, strtoupper($currency)];
        $this->contactService->addContact($serviceArgv, $this->output);
    }

    private function cmdCurrencyAccept(array $argv): void
    {
        $contact = $argv[4] ?? null;
        $currency = $argv[5] ?? null;
        $fee = $this->flag($argv, '--fee');
        $credit = $this->flag($argv, '--credit');
        if ($contact === null || $currency === null || $fee === null || $credit === null) {
            $this->output->error(
                'Usage: eiou contact currency accept <contact> <currency> --fee F --credit C',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $pubkeyHash = $this->resolvePubkeyHash($contact);
        if ($pubkeyHash === null) {
            $this->output->error(
                "No contact matching '{$contact}'",
                ErrorCodes::CONTACT_NOT_FOUND,
                404
            );
            return;
        }

        $isNewContact = $this->isPendingContact($pubkeyHash);
        $contactInfo = $this->lookupContactDetails($pubkeyHash);

        $result = $this->decisionService->apply(
            $pubkeyHash,
            [[
                'currency' => strtoupper($currency),
                'action' => 'accept',
                'fee' => $fee,
                'credit' => $credit,
            ]],
            $isNewContact,
            $contactInfo['address'] ?? null,
            $contactInfo['name'] ?? null,
        );
        $this->emitApplyResult($result);
    }

    private function cmdCurrencyDecline(array $argv): void
    {
        $contact = $argv[4] ?? null;
        $currency = $argv[5] ?? null;
        if ($contact === null || $currency === null) {
            $this->output->error(
                'Usage: eiou contact currency decline <contact> <currency>',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $pubkeyHash = $this->resolvePubkeyHash($contact);
        if ($pubkeyHash === null) {
            $this->output->error(
                "No contact matching '{$contact}'",
                ErrorCodes::CONTACT_NOT_FOUND,
                404
            );
            return;
        }

        $ccy = strtoupper($currency);
        $this->contactCurrencyRepository->declineIncomingCurrency($pubkeyHash, $ccy);
        $this->output->success(
            "Currency {$ccy} declined",
            ['contact' => $contact, 'currency' => $ccy]
        );
    }

    private function cmdCurrencyList(array $argv): void
    {
        $contact = $argv[4] ?? null;
        if ($contact === null) {
            $this->output->error(
                'Usage: eiou contact currency list <contact>',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $pubkeyHash = $this->resolvePubkeyHash($contact);
        if ($pubkeyHash === null) {
            $this->output->error(
                "No contact matching '{$contact}'",
                ErrorCodes::CONTACT_NOT_FOUND,
                404
            );
            return;
        }

        $rows = $this->contactCurrencyRepository->getContactCurrencies($pubkeyHash);
        $this->output->success(
            "Currencies for {$contact}",
            ['currencies' => $rows]
        );
    }

    private function cmdCurrencyRemove(array $argv): void
    {
        $contact = $argv[4] ?? null;
        $currency = $argv[5] ?? null;
        if ($contact === null || $currency === null) {
            $this->output->error(
                'Usage: eiou contact currency remove <contact> <currency>',
                ErrorCodes::MISSING_ARGUMENT,
                400
            );
            return;
        }

        $pubkeyHash = $this->resolvePubkeyHash($contact);
        if ($pubkeyHash === null) {
            $this->output->error(
                "No contact matching '{$contact}'",
                ErrorCodes::CONTACT_NOT_FOUND,
                404
            );
            return;
        }

        $ccy = strtoupper($currency);
        $this->contactCurrencyRepository->deleteCurrencyConfig($pubkeyHash, $ccy);
        $this->output->success(
            "Currency {$ccy} removed locally",
            ['contact' => $contact, 'currency' => $ccy]
        );
    }

    // =========================================================================
    // Help
    // =========================================================================

    private function showHelp(): void
    {
        $msg = <<<HELP

Contact management — manage contacts and per-currency relationships.

Usage:
  eiou contact add <address> <name> [--fee F --credit C --currency CCY] [--message M]
  eiou contact accept <pubkey-hash|address> --currency CCY --fee F --credit C
                                            [--currency CCY --fee F --credit C ...]
  eiou contact apply  <pubkey-hash|address> --from <file.json|->
  eiou contact apply  <pubkey-hash|address> [--accept CCY:fee:credit ...]
                                            [--decline CCY ...] [--defer CCY ...]
  eiou contact decline <pubkey-hash|address>
  eiou contact list   [--status accepted|pending|blocked]
  eiou contact pending [--incoming|--outgoing]
  eiou contact view   <name|address|pubkey-hash>
  eiou contact update <name|address> [--name N --fee F --credit C]
  eiou contact delete <name|address>
  eiou contact block  <name|address>
  eiou contact unblock <name|address>
  eiou contact ping   <name|address>
  eiou contact search <query>

  eiou contact currency add     <contact> <currency> --fee F --credit C
  eiou contact currency accept  <contact> <currency> --fee F --credit C
  eiou contact currency decline <contact> <currency>
  eiou contact currency list    <contact>
  eiou contact currency remove  <contact> <currency>

Identifiers:
  <contact> accepts a name, address, or pubkey-hash. Pipe scripted values
  from `eiou contact pending --json`.

HELP;
        $this->output->info($msg);
    }

    private function showCurrencyHelp(): void
    {
        $msg = <<<HELP

Per-currency contact operations.

Usage:
  eiou contact currency add     <contact> <currency> --fee F --credit C   Propose a new currency
  eiou contact currency accept  <contact> <currency> --fee F --credit C   Accept incoming pending
  eiou contact currency decline <contact> <currency>                      Decline incoming pending
  eiou contact currency list    <contact>                                 Show all currencies + state
  eiou contact currency remove  <contact> <currency>                      Local removal (not network)

HELP;
        $this->output->info($msg);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Resolve a CLI-supplied identifier to a pubkey-hash.
     *
     * Accepts: pubkey-hash directly, an address (any transport), or a name.
     * Returns null when nothing matches.
     */
    private function resolvePubkeyHash(string $identifier): ?string
    {
        // If it's already a pubkey-hash, the contact-by-hash lookup will
        // resolve to the original pubkey.
        if ($this->contactRepository->getContactPubkeyFromHash($identifier) !== null) {
            return $identifier;
        }

        $info = $this->managementService->lookupContactInfo($identifier);
        if ($info === null) {
            return null;
        }
        if (!empty($info['receiverPublicKeyHash'])) {
            return (string) $info['receiverPublicKeyHash'];
        }
        $pubkey = $info['receiverPublicKey'] ?? null;
        if ($pubkey === null) {
            return null;
        }
        return hash(\Eiou\Core\Constants::HASH_ALGORITHM, $pubkey);
    }

    /**
     * Resolve a CLI identifier to the contact's address (preferred transport).
     */
    private function resolveAddress(string $identifier): ?string
    {
        $info = $this->managementService->lookupContactInfo($identifier);
        if ($info !== null) {
            $address = $info['tor'] ?? $info['https'] ?? $info['http'] ?? null;
            if ($address !== null && $address !== '') {
                return $address;
            }
        }
        // Maybe the identifier IS an address.
        $tx = $this->guessTransportIndex($identifier);
        if ($tx !== null && $this->contactRepository->getContactByAddress($tx, $identifier) !== null) {
            return $identifier;
        }
        return null;
    }

    private function guessTransportIndex(string $address): ?string
    {
        if (str_starts_with($address, 'http://')) {
            return 'http';
        }
        if (str_starts_with($address, 'https://')) {
            return 'https';
        }
        if (str_ends_with($address, '.onion') || str_contains($address, '.onion:')) {
            return 'tor';
        }
        return null;
    }

    private function isPendingContact(string $pubkeyHash): bool
    {
        $pubkey = $this->contactRepository->getContactPubkeyFromHash($pubkeyHash);
        if ($pubkey === null) {
            return false;
        }
        $contact = $this->contactRepository->getContactByPubkey($pubkey);
        if ($contact === null) {
            return false;
        }
        return ($contact['status'] ?? null) !== \Eiou\Core\Constants::CONTACT_STATUS_ACCEPTED;
    }

    /**
     * Pull a contact's address+name from the repository, when available.
     * Used so the new-contact branch in ContactDecisionService::apply can
     * forward to the addContact CLI path with the right identifiers.
     *
     * @return array{address: ?string, name: ?string}
     */
    private function lookupContactDetails(string $pubkeyHash): array
    {
        $pubkey = $this->contactRepository->getContactPubkeyFromHash($pubkeyHash);
        if ($pubkey === null) {
            return ['address' => null, 'name' => null];
        }
        $contact = $this->contactRepository->getContactByPubkey($pubkey);
        if ($contact === null) {
            return ['address' => null, 'name' => null];
        }
        $address = $contact['tor'] ?? $contact['https'] ?? $contact['http'] ?? null;
        $name = $contact['name'] ?? null;
        return ['address' => $address, 'name' => $name];
    }

    /**
     * Parse repeated `--currency CCY --fee F --credit C` triplets for accept.
     * Returns null if a triplet is malformed.
     *
     * @return array<int, array{currency: string, action: string, fee: string, credit: string}>|null
     */
    private function parseAcceptTriplets(array $argv): ?array
    {
        $decisions = [];
        $count = count($argv);
        for ($i = 0; $i < $count; $i++) {
            if ($argv[$i] !== '--currency') {
                continue;
            }
            $currency = $argv[$i + 1] ?? null;
            $fee = null;
            $credit = null;
            // Look ahead for --fee / --credit before the next --currency.
            for ($j = $i + 2; $j < $count; $j++) {
                if ($argv[$j] === '--currency') {
                    break;
                }
                if ($argv[$j] === '--fee') {
                    $fee = $argv[$j + 1] ?? null;
                }
                if ($argv[$j] === '--credit') {
                    $credit = $argv[$j + 1] ?? null;
                }
            }
            if ($currency === null || $fee === null || $credit === null) {
                return null;
            }
            $decisions[] = [
                'currency' => strtoupper((string) $currency),
                'action' => 'accept',
                'fee' => (string) $fee,
                'credit' => (string) $credit,
            ];
        }
        return $decisions;
    }

    /**
     * Parse repeatable per-decision flags for `apply`:
     *   --accept CCY:fee:credit
     *   --decline CCY
     *   --defer CCY
     *
     * @return array<int, array{currency: string, action: string, fee?: string, credit?: string}>
     */
    private function parseDecisionFlags(array $argv): array
    {
        $decisions = [];
        $count = count($argv);
        for ($i = 0; $i < $count; $i++) {
            $token = $argv[$i];
            if ($token === '--accept' && isset($argv[$i + 1])) {
                $parts = explode(':', (string) $argv[$i + 1]);
                if (count($parts) === 3) {
                    $decisions[] = [
                        'currency' => strtoupper($parts[0]),
                        'action' => 'accept',
                        'fee' => $parts[1],
                        'credit' => $parts[2],
                    ];
                }
            } elseif ($token === '--decline' && isset($argv[$i + 1])) {
                $decisions[] = [
                    'currency' => strtoupper((string) $argv[$i + 1]),
                    'action' => 'decline',
                ];
            } elseif ($token === '--defer' && isset($argv[$i + 1])) {
                $decisions[] = [
                    'currency' => strtoupper((string) $argv[$i + 1]),
                    'action' => 'defer',
                ];
            }
        }
        return $decisions;
    }

    private function loadDecisionsFromFile(string $path): ?array
    {
        $raw = $path === '-' ? stream_get_contents(STDIN) : @file_get_contents($path);
        if ($raw === false || $raw === null) {
            $this->output->error(
                "Could not read decisions from '{$path}'",
                ErrorCodes::VALIDATION_ERROR,
                400
            );
            return null;
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            $this->output->error(
                'Decisions file must contain a JSON array',
                ErrorCodes::VALIDATION_ERROR,
                400
            );
            return null;
        }
        return $parsed;
    }

    /**
     * @param array{accepted: string[], declined: string[], errors: string[]} $result
     */
    private function emitApplyResult(array $result): void
    {
        if (!empty($result['errors']) && empty($result['accepted']) && empty($result['declined'])) {
            $this->output->error(
                'No decisions applied',
                ErrorCodes::GENERAL_ERROR,
                500,
                ['errors' => $result['errors']]
            );
            return;
        }
        $this->output->success(
            'Decisions applied',
            $result
        );
    }

    private function flag(array $argv, string $name): ?string
    {
        $len = count($argv);
        for ($i = 0; $i < $len; $i++) {
            if ($argv[$i] === $name && isset($argv[$i + 1])) {
                return (string) $argv[$i + 1];
            }
            if (is_string($argv[$i]) && str_starts_with($argv[$i], $name . '=')) {
                return substr($argv[$i], strlen($name) + 1);
            }
        }
        return null;
    }
}
