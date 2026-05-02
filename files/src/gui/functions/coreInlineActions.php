<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Core "inline" GUI actions — POST handlers that historically lived
 * as raw `if ($action === ...)` branches in Functions.php and have no
 * controller home. Pulling them out here lets them go through
 * GuiActionRegistry alongside the controller-owned actions while
 * keeping the closure bodies physically close to where they grew up.
 *
 * Every entry registers at TIER_AUTH so the registry's CSRF gate does
 * not fire — the closure body keeps its existing inline non-rotating
 * `validateCSRFToken($t, false)` check (where present) and its
 * existing JSON envelope shape on failure. The migration is
 * structural routing, not a policy change.
 *
 * Required scope variables (injected by Functions.php's `require`):
 *   $serviceContainer, $secureSession, $user, $contactService,
 *   $transactionService.
 *
 * Helpers required by the closures (renderTransactionRowsForAjax,
 * renderPaymentRequestRowsForAjax) live in TemplateHelpers.php which
 * index.html loads before Functions.php.
 */

$registry = $serviceContainer->getActionRegistry();

// =============================================================================
// What's New
// =============================================================================

$registry->register('whatsNewDismiss', function (): void {
    header('Content-Type: application/json');
    try {
        \Eiou\Services\UpdateCheckService::dismissWhatsNew();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');

$registry->register('whatsNewNotes', function (): void {
    header('Content-Type: application/json');
    try {
        $version = $_POST['version'] ?? \Eiou\Core\Constants::APP_VERSION;
        $notes = \Eiou\Services\UpdateCheckService::getReleaseNotes($version);
        if ($notes !== null) {
            echo json_encode(['success' => true, 'data' => $notes]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Release notes not available']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');

// =============================================================================
// Remember-me session management
// =============================================================================

$rememberSessionHandler = function (array $request) use ($serviceContainer, $secureSession, $user): void {
    header('Content-Type: application/json');
    $action = $request['action'] ?? '';
    try {
        // CSRF is required — this is a state-changing action.
        if (empty($_POST['csrf_token']) || !$secureSession->validateCSRFToken($_POST['csrf_token'], false)) {
            echo json_encode(['success' => false, 'error' => 'csrf_error', 'message' => 'Invalid CSRF token']);
            exit;
        }

        $pubkeyHashForAction = hash(\Eiou\Core\Constants::HASH_ALGORITHM, $user->getPublicKey());
        $rememberService = $serviceContainer->getRememberTokenService();

        if ($action === 'revokeRememberSession') {
            $id = (int) ($_POST['session_id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'invalid_id']);
                exit;
            }

            // Detect "am I revoking my OWN row?" BEFORE the revoke so
            // the is_current flag still reads true. If so, we also
            // need to tear down the PHP session — revoking the
            // remember-me row alone only invalidates future cookie-
            // based auto-logins; the already-authenticated session
            // would persist otherwise.
            $currentRaw = $secureSession->getRememberCookie();
            $revokingCurrentDevice = false;
            if ($currentRaw !== null) {
                foreach ($rememberService->listForUser($pubkeyHashForAction, $currentRaw) as $row) {
                    if ($row['id'] === (int)$id && !empty($row['is_current'])) {
                        $revokingCurrentDevice = true;
                        break;
                    }
                }
            }

            $ok = $rememberService->revokeTokenById($id, $pubkeyHashForAction);

            if ($revokingCurrentDevice) {
                $secureSession->clearRememberCookie();
                $secureSession->logout();
                echo json_encode(['success' => $ok, 'logged_out' => true]);
                exit;
            }

            echo json_encode(['success' => $ok]);
            exit;
        }

        if ($action === 'revokeAllRememberSessions') {
            // "Sign out everywhere" includes THIS browser by definition,
            // so always tear down the session + cookie.
            $count = $rememberService->revokeAllForUser($pubkeyHashForAction);
            $secureSession->clearRememberCookie();
            $secureSession->logout();
            echo json_encode(['success' => true, 'revoked' => $count, 'logged_out' => true]);
            exit;
        }
    } catch (\Throwable $e) {
        \Eiou\Utils\Logger::getInstance()->logException($e, [
            'context' => 'remember_session_action',
            'action'  => $action,
        ]);
        echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        exit;
    }
};
$registry->register('revokeRememberSession',     $rememberSessionHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');
$registry->register('revokeAllRememberSessions', $rememberSessionHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');

// =============================================================================
// "Search entire database" handlers for Recent Transactions and
// Payment Requests history. The client-side search + filter on these
// tables only inspects already-rendered rows; this lets the user ask
// the server to walk the full table for a match without having to
// Load-older until the match shows up locally. Contacts is
// intentionally omitted — that table is almost always fully loaded on
// first render.
// =============================================================================

$searchHandler = function (array $request) use ($serviceContainer, $secureSession, $user, $contactService, $transactionService): void {
    header('Content-Type: application/json');
    $action = $request['action'] ?? '';
    try {
        if (empty($_POST['csrf_token']) || !$secureSession->validateCSRFToken($_POST['csrf_token'], false)) {
            echo json_encode(['success' => false, 'error' => 'csrf_error']);
            exit;
        }
        $term = isset($_POST['q']) ? (string)$_POST['q'] : '';
        if (trim($term) === '') {
            echo json_encode(['success' => false, 'error' => 'empty_term', 'message' => 'Enter a search term first.']);
            exit;
        }

        $dirFilter    = isset($_POST['direction']) ? trim((string)$_POST['direction']) : '';
        $statusFilter = isset($_POST['status'])    ? trim((string)$_POST['status'])    : '';
        $typeFilter   = isset($_POST['tx_type'])   ? trim((string)$_POST['tx_type'])   : '';
        // Ceiling configurable via user setting later; 500 is enough
        // to comfortably cover a search result with client-side
        // paginator slicing on top.
        $maxResults = 500;

        if ($action === 'searchTransactions') {
            $transactions = $transactionService->searchTransactions(
                $term,
                $dirFilter !== '' ? $dirFilter : null,
                $typeFilter !== '' ? $typeFilter : null,
                $statusFilter !== '' ? $statusFilter : null,
                $maxResults
            );
            // Search results replace (not append to) the table body,
            // so indices restart at 0. transactionData on the client
            // is rewritten to the same shape the initial render uses.
            $rendered = renderTransactionRowsForAjax(
                $transactions, 0,
                $contactService, $transactionService, $serviceContainer, $user
            );
            echo json_encode([
                'success' => true,
                'html'    => $rendered['html'],
                'rows'    => $rendered['rows'],
                'total'   => count($transactions),
                'capped'  => count($transactions) >= $maxResults,
                'cap'     => $maxResults,
            ]);
            exit;
        }

        if ($action === 'searchPaymentRequests') {
            $rows = $serviceContainer->getPaymentRequestService()->searchResolvedHistory(
                $term,
                $dirFilter !== '' ? $dirFilter : null,
                $statusFilter !== '' ? $statusFilter : null,
                $maxResults
            );
            $html = renderPaymentRequestRowsForAjax(
                $rows, $contactService, $transactionService, $user
            );
            echo json_encode([
                'success' => true,
                'html'    => $html,
                'total'   => count($rows),
                'capped'  => count($rows) >= $maxResults,
                'cap'     => $maxResults,
            ]);
            exit;
        }
    } catch (\Throwable $e) {
        \Eiou\Utils\Logger::getInstance()->logException($e, [
            'context' => 'search_handler',
            'action'  => $action,
        ]);
        echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        exit;
    }
};
$registry->register('searchTransactions',    $searchHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');
$registry->register('searchPaymentRequests', $searchHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');

// =============================================================================
// "Load older" handlers for the three paginated tables (Recent
// Transactions, Contacts, Payment Requests). Each renders the next
// chunk of rows as an HTML fragment so the client can append directly
// to the existing <tbody> without duplicating the row template in JS.
// =============================================================================

$loadMoreHandler = function (array $request) use ($serviceContainer, $secureSession, $user, $contactService, $transactionService): void {
    header('Content-Type: application/json');
    $action = $request['action'] ?? '';
    try {
        if (empty($_POST['csrf_token']) || !$secureSession->validateCSRFToken($_POST['csrf_token'], false)) {
            echo json_encode(['success' => false, 'error' => 'csrf_error']);
            exit;
        }
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $limit  = (int) $user->getDisplayRecentTransactionsLimit();
        if ($limit <= 0) { $limit = 10; }
        // Cursor wins over offset when both are present (or offset is the
        // first-page sentinel 0). Decoded once here so each branch below
        // can pass it down without re-parsing. A malformed cursor decodes
        // to null — the safe fallback is page one.
        $cursor = \Eiou\Utils\PaginationCursor::decode($_POST['cursor'] ?? null);

        if ($action === 'loadMoreTransactions') {
            $transactions = $transactionService->getTransactionHistory($limit, $offset, $cursor);
            $rendered = renderTransactionRowsForAjax(
                $transactions, $offset,
                $contactService, $transactionService, $serviceContainer, $user
            );
            // Build the next-page cursor from the last row of THIS page so
            // the client can post it back verbatim. Cursor key matches the
            // ORDER BY tuple in TransactionRepository::getTransactionHistory:
            // (COALESCE(time, 0), timestamp, txid).
            $nextCursor = null;
            if (!empty($transactions) && count($transactions) >= $limit) {
                $last = end($transactions);
                $nextCursor = \Eiou\Utils\PaginationCursor::encode([
                    'time'      => (int) ($last['time'] ?? 0),
                    'timestamp' => (string) ($last['timestamp'] ?? ''),
                    'txid'      => (string) ($last['txid'] ?? ''),
                ]);
            }
            echo json_encode([
                'success'     => true,
                'html'        => $rendered['html'],
                'rows'        => $rendered['rows'],
                'exhausted'   => count($transactions) < $limit,
                'next_cursor' => $nextCursor,
            ]);
            exit;
        }

        if ($action === 'loadMorePaymentRequests') {
            $more = $serviceContainer->getPaymentRequestService()->getResolvedHistoryPage($limit, $offset, $cursor);
            $html = renderPaymentRequestRowsForAjax(
                $more, $contactService, $transactionService, $user
            );
            // Cursor for the next page mirrors the repository ORDER BY:
            // (COALESCE(responded_at, created_at), id). Build only when
            // the page came back full — a short page means we're at the
            // tail and the next click should fall back to exhausted.
            $nextCursor = null;
            if (!empty($more) && count($more) >= $limit) {
                $last = end($more);
                $nextCursor = \Eiou\Utils\PaginationCursor::encode([
                    'ts' => (string) ($last['responded_at'] ?? $last['created_at'] ?? ''),
                    'id' => (int) ($last['id'] ?? 0),
                ]);
            }
            echo json_encode([
                'success'     => true,
                'html'        => $html,
                // exhausted lets the client hide the Load older button
                // once it's done. Short-page → exhausted; exact-fit
                // page → not exhausted yet (next click will return 0
                // rows + exhausted=true).
                'exhausted'   => count($more) < $limit,
                'next_cursor' => $nextCursor,
            ]);
            exit;
        }

        if ($action === 'loadMoreContacts') {
            // Only accepted contacts paginate — pending + blocked are
            // bounded and always rendered up-front in the initial
            // template, so the paginator append path stays pure "more
            // accepted rows".
            $rawAccepted = $contactService->getAcceptedContactsPage($limit, $offset, $cursor);
            $exhausted = count($rawAccepted) < $limit;

            if (empty($rawAccepted)) {
                echo json_encode([
                    'success'   => true,
                    'html'      => '',
                    'exhausted' => true,
                ]);
                exit;
            }

            // Enrich: same pipeline as the main view-data
            // initialization, scoped to just this page's rows so we
            // don't re-fetch per-contact credit for contacts we
            // already showed.
            $moreWithBalances = $transactionService->contactBalanceConversion(
                $rawAccepted,
                (int) $user->getMaxOutput()
            );

            // Credit + currency enrichment (mirrors the
            // $contactArraysForCredit loop further down in
            // Functions.php).
            $contactCreditRepo   = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCreditRepository::class);
            $contactCurrencyRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);

            foreach ($moreWithBalances as &$contact) {
                $hash = $contact['pubkey_hash'] ?? '';
                $contact['my_available_credit']    = null;
                $contact['their_available_credit'] = null;

                try {
                    $creditData = $hash ? $contactCreditRepo->getAvailableCredit($hash) : null;
                    if ($creditData !== null) {
                        $contact['my_available_credit'] = $creditData['available_credit']->toMajorUnits();
                    }

                    $allCredits = $hash ? $contactCreditRepo->getAvailableCreditAllCurrencies($hash) : [];
                    $creditMap = [];
                    foreach ($allCredits as $cr) {
                        $cur = $cr['currency'] ?? \Eiou\Core\Constants::TRANSACTION_DEFAULT_CURRENCY;
                        $creditMap[$cur] = $cr['available_credit']->toMajorUnits();
                    }

                    $currencyConfigs = $hash ? $contactCurrencyRepo->getContactCurrencies($hash) : [];
                    $contactBalancesByCurrency = $contact['balances_by_currency'] ?? [];
                    $acceptedCurrencies = [];
                    foreach ($currencyConfigs as $cc) {
                        if (($cc['status'] ?? 'accepted') !== 'accepted') { continue; }
                        $cur = $cc['currency'];
                        $ccDirection = $cc['direction'] ?? 'outgoing';
                        $creditLimitMajor = ($cc['credit_limit'] instanceof \Eiou\Core\SplitAmount) ? $cc['credit_limit']->toMajorUnits() : 0;
                        $balanceForCur = floatval($contactBalancesByCurrency[$cur] ?? 0);
                        $entry = [
                            'currency' => $cur,
                            'fee' => ($cc['fee_percent'] ?? 0) / \Eiou\Core\Constants::FEE_CONVERSION_FACTOR,
                            'credit_limit' => $creditLimitMajor,
                            'my_available_credit' => $creditMap[$cur] ?? null,
                            'their_available_credit' => ($creditLimitMajor > 0 || $balanceForCur != 0) ? round($creditLimitMajor - $balanceForCur, 2) : null,
                            'status' => 'accepted',
                            'direction' => $ccDirection,
                        ];
                        // Deduplicate per currency (prefer outgoing — has our fee/credit)
                        if (!isset($acceptedCurrencies[$cur]) || $ccDirection === 'outgoing') {
                            $acceptedCurrencies[$cur] = $entry;
                        }
                    }
                    $contact['currencies'] = array_values($acceptedCurrencies);
                } catch (\Throwable $e) {
                    // Non-critical — row renders with empty currencies,
                    // which the partial handles (falls back to USD).
                    $contact['currencies'] = [];
                }
            }
            unset($contact);

            // Partial dependencies the template pulls from scope.
            $addressTypesForBuilder = $serviceContainer->getRepositoryFactory()
                ->get(\Eiou\Database\AddressRepository::class)
                ->getAllAddressTypes();
            $contactDataBuilder = new \Eiou\Gui\Helpers\ContactDataBuilder($addressTypesForBuilder);
            $contactAvatarStyle = $user->getContactAvatarStyle();

            ob_start();
            foreach ($moreWithBalances as $contact) {
                require __DIR__ . '/../layout/walletSubParts/_contactRow.html';
            }
            $html = ob_get_clean();

            // Cursor for the next contacts page mirrors the repository
            // ORDER BY: (c.name ASC, c.id ASC). Names may be null in the
            // table; cursor encodes whatever the row has so the next
            // request lines up byte-for-byte.
            $nextCursor = null;
            if (!empty($rawAccepted) && count($rawAccepted) >= $limit) {
                $last = end($rawAccepted);
                $nextCursor = \Eiou\Utils\PaginationCursor::encode([
                    'name' => (string) ($last['name'] ?? ''),
                    'id'   => (int) ($last['id'] ?? 0),
                ]);
            }
            echo json_encode([
                'success'     => true,
                'html'        => $html,
                'exhausted'   => $exhausted,
                'next_cursor' => $nextCursor,
            ]);
            exit;
        }
    } catch (\Throwable $e) {
        \Eiou\Utils\Logger::getInstance()->logException($e, [
            'context' => 'load_more_handler',
            'action'  => $action,
        ]);
        echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        exit;
    }
};
$registry->register('loadMoreTransactions',    $loadMoreHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');
$registry->register('loadMoreContacts',        $loadMoreHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');
$registry->register('loadMorePaymentRequests', $loadMoreHandler, \Eiou\Services\GuiActionRegistry::TIER_AUTH, 'core');
