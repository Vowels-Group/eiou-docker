<?php
# Copyright 2025-2026 Vowels Group, LLC

declare(strict_types=1);

namespace Eiou\Services;

use Eiou\Cli\CliOutputManager;
use Eiou\Contracts\ContactServiceInterface;
use Eiou\Contracts\ContactSyncServiceInterface;
use Eiou\Core\Constants;
use Eiou\Core\SplitAmount;
use Eiou\Core\UserContext;
use Eiou\Database\BalanceRepository;
use Eiou\Database\ContactCreditRepository;
use Eiou\Database\ContactCurrencyRepository;
use Eiou\Database\ContactRepository;
use Eiou\Services\Utilities\CurrencyUtilityService;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Security;

/**
 * ContactDecisionService
 *
 * Shared implementation of the batched contact-currency decision flow used by
 * the GUI batched-apply modal, the `eiou contact apply` CLI, and
 * POST /api/v1/contacts/:hash/decisions.
 *
 * The service partitions decisions, runs declines first (so a "decline EUR +
 * accept USD" payload doesn't risk EUR getting auto-added by the addContact
 * CLI path), then for new contacts uses the addContact CLI path for the very
 * first accept (which establishes the contact) and the standard
 * currency-acceptance path for the rest.
 */
class ContactDecisionService
{
    public function __construct(
        private readonly ContactRepository $contactRepository,
        private readonly ContactCurrencyRepository $contactCurrencyRepository,
        private readonly ContactCreditRepository $contactCreditRepository,
        private readonly BalanceRepository $balanceRepository,
        private readonly ContactSyncServiceInterface $contactSyncService,
        private readonly ContactServiceInterface $contactService,
        private readonly UserContext $currentUser,
    ) {
    }

    /**
     * Apply a batched set of contact-currency decisions.
     *
     * @param string $pubkeyHash      Hash of the contact pubkey.
     * @param array  $decisions       List of ['currency' => CCY, 'action' => accept|decline|defer, 'fee' => F, 'credit' => C].
     *                                'defer' entries are intentional no-ops.
     * @param bool   $isNewContact    When true, the first accept establishes the contact via the addContact CLI path.
     * @param string|null $contactAddress  Required when $isNewContact is true.
     * @param string|null $contactName     Required when $isNewContact is true.
     *
     * @return array{accepted: string[], declined: string[], errors: string[]}
     */
    public function apply(
        string $pubkeyHash,
        array $decisions,
        bool $isNewContact = false,
        ?string $contactAddress = null,
        ?string $contactName = null,
    ): array {
        $accepted = [];
        $declined = [];
        $errors = [];

        if ($pubkeyHash === '' || empty($decisions)) {
            return ['accepted' => $accepted, 'declined' => $declined, 'errors' => $errors];
        }

        $contactPubkey = $this->contactRepository->getContactPubkeyFromHash($pubkeyHash);

        [$acceptList, $declineList] = $this->partitionDecisions($decisions);

        $declined = $this->runDeclines($pubkeyHash, $declineList, $errors);

        if ($isNewContact && !empty($contactPubkey) && !empty($acceptList)) {
            $firstHandled = $this->runFirstAcceptViaAddContact(
                $contactPubkey,
                $acceptList,
                $contactAddress,
                $contactName,
                $accepted,
                $errors,
            );
            if ($firstHandled) {
                array_shift($acceptList);
            }
        }

        $this->runAccepts($pubkeyHash, $contactPubkey, $acceptList, $accepted, $errors);

        return ['accepted' => $accepted, 'declined' => $declined, 'errors' => $errors];
    }

    /**
     * @param array $decisions
     * @return array{0: array, 1: array} [$acceptList, $declineList], preserving order
     */
    private function partitionDecisions(array $decisions): array
    {
        $acceptList = [];
        $declineList = [];
        foreach ($decisions as $d) {
            $action = $d['action'] ?? '';
            if ($action === 'accept') {
                $acceptList[] = $d;
            } elseif ($action === 'decline') {
                $declineList[] = $d;
            }
        }
        return [$acceptList, $declineList];
    }

    /**
     * @param array $declineList
     * @param array $errors
     * @return string[] declined currencies
     */
    private function runDeclines(string $pubkeyHash, array $declineList, array &$errors): array
    {
        $declined = [];
        foreach ($declineList as $entry) {
            $currency = strtoupper(Security::sanitizeInput($entry['currency'] ?? ''));
            if ($currency === '') {
                continue;
            }
            try {
                $this->contactCurrencyRepository->declineIncomingCurrency($pubkeyHash, $currency);
                $declined[] = $currency;
            } catch (\Throwable $e) {
                $errors[] = "{$currency} (decline): " . $e->getMessage();
            }
        }
        return $declined;
    }

    /**
     * For new contacts: the first accept establishes the contact via the
     * addContact CLI path. Returns true if the first entry was consumed.
     */
    private function runFirstAcceptViaAddContact(
        string $contactPubkey,
        array $acceptList,
        ?string $contactAddress,
        ?string $contactName,
        array &$accepted,
        array &$errors,
    ): bool {
        $contact = $this->contactRepository->getContactByPubkey($contactPubkey);
        if (!$contact || $contact['status'] === Constants::CONTACT_STATUS_ACCEPTED) {
            return false;
        }

        if (empty($contactAddress) || empty($contactName)) {
            return false;
        }

        $firstEntry = $acceptList[0];
        $firstCurrency = strtoupper(Security::sanitizeInput($firstEntry['currency'] ?? ''));
        $firstFee = Security::sanitizeInput($firstEntry['fee'] ?? '');
        $firstCredit = Security::sanitizeInput($firstEntry['credit'] ?? '');

        if ($firstCurrency === '' || $firstFee === '' || $firstCredit === '') {
            return false;
        }

        $this->autoAddAllowedCurrency($firstCurrency);

        $argv = ['eiou', 'add', $contactAddress, $contactName, $firstFee, $firstCredit, $firstCurrency, '--json'];
        CliOutputManager::resetInstance();
        $outputManager = new CliOutputManager($argv);

        ob_start();
        try {
            $this->contactService->addContact($argv, $outputManager);
            ob_end_clean();
            $accepted[] = $firstCurrency;
            return true;
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            $errors[] = "{$firstCurrency}: " . $e->getMessage();
            return false;
        }
    }

    private function runAccepts(
        string $pubkeyHash,
        ?string $contactPubkey,
        array $acceptList,
        array &$accepted,
        array &$errors,
    ): void {
        $acceptedAny = false;
        foreach ($acceptList as $entry) {
            $currency = strtoupper(Security::sanitizeInput($entry['currency'] ?? ''));
            $fee = Security::sanitizeInput($entry['fee'] ?? '');
            $credit = Security::sanitizeInput($entry['credit'] ?? '');

            if ($currency === '' || $fee === '' || $credit === '') {
                $errors[] = "{$currency}: missing fields";
                continue;
            }

            $feeValidation = InputValidator::validateFeePercent($fee);
            if (!$feeValidation['valid']) {
                $errors[] = "{$currency}: invalid fee";
                continue;
            }

            $creditValidation = InputValidator::validateAmount($credit, $currency);
            if (!$creditValidation['valid']) {
                $errors[] = "{$currency}: invalid credit";
                continue;
            }

            $this->autoAddAllowedCurrency($currency);

            $creditMinor = SplitAmount::from($creditValidation['value']);
            $feeMinor = CurrencyUtilityService::exactMajorToMinor(
                $feeValidation['value'],
                Constants::FEE_CONVERSION_FACTOR
            );

            $this->contactCurrencyRepository->updateCurrencyConfig(
                $pubkeyHash,
                $currency,
                [
                    'fee_percent' => $feeMinor,
                    'credit_limit' => $creditMinor,
                    'status' => 'accepted',
                ],
                'incoming'
            );

            if ($this->contactCurrencyRepository->hasCurrency($pubkeyHash, $currency, 'outgoing')) {
                $this->contactCurrencyRepository->updateCurrencyStatus(
                    $pubkeyHash,
                    $currency,
                    'accepted',
                    'outgoing'
                );
            }

            $currentPubkey = $contactPubkey ?: $this->contactRepository->getContactPubkeyFromHash($pubkeyHash);
            if ($currentPubkey) {
                $this->balanceRepository->insertInitialContactBalances($currentPubkey, $currency);
                try {
                    $sentBalance = $this->balanceRepository->getContactSentBalance($currentPubkey, $currency);
                    $receivedBalance = $this->balanceRepository->getContactReceivedBalance($currentPubkey, $currency);
                    $balance = $sentBalance->subtract($receivedBalance);
                    $creditLimit = $this->contactCurrencyRepository->getCreditLimit($pubkeyHash, $currency)
                        ?? SplitAmount::zero();
                    $this->contactCreditRepository->upsertAvailableCredit(
                        $pubkeyHash,
                        $balance->add($creditLimit),
                        $currency
                    );
                } catch (\Exception $e) {
                    // Non-fatal — credit will be corrected on next ping/pong
                }
            }

            $this->contactSyncService->sendCurrencyAcceptanceNotification($pubkeyHash, $currency);
            $accepted[] = $currency;
            $acceptedAny = true;
        }

        // Flip any 'accepted'-status contact-request transactions for this
        // sender to 'completed'. The first-accept-via-add path does this via
        // ContactSyncService::acceptContact(), but per-currency accepts on an
        // already-established contact otherwise leave the contact-request tx
        // row stuck on 'accepted' on the receiver side.
        if ($acceptedAny) {
            $pubkey = $contactPubkey ?: $this->contactRepository->getContactPubkeyFromHash($pubkeyHash);
            if ($pubkey) {
                $this->contactSyncService->completeReceivedContactTransaction($pubkey);
            }
        }
    }

    private function autoAddAllowedCurrency(string $currency): void
    {
        $allowed = $this->currentUser->getAllowedCurrencies();
        if (in_array($currency, $allowed, true)) {
            return;
        }

        $allowed[] = $currency;
        $newValue = implode(',', $allowed);
        $this->currentUser->set('allowedCurrencies', $newValue);

        $configFile = '/etc/eiou/config/defaultconfig.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true) ?? [];
            $config['allowedCurrencies'] = $newValue;
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
}
