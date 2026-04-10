<?php
# Copyright 2025-2026 Vowels Group, LLC

use Eiou\Gui\Helpers\ContactDataBuilder;
use Eiou\Gui\Includes\SessionKeys;

/**
 * GUI Request Router and View Data Initializer
 *
 * This file serves as the central routing layer for the GUI, responsible for:
 *
 * 1. POST Request Routing:
 *    - Contact actions (add, accept, delete, block, unblock, edit)
 *    - Transaction actions (sendEIOU)
 *    - Settings actions (updateSettings, clearDebugLogs, sendDebugReport)
 *    - AJAX endpoints (getDebugReportJson, pingContact) - return JSON and exit
 *
 * 2. GET Request Handling:
 *    - Transaction update checking for real-time UI updates
 *    - Message display from redirect parameters
 *
 * 3. View Data Initialization:
 *    - User balance and earnings data
 *    - Transaction history and in-progress transactions
 *    - Contact lists (all, pending, accepted, blocked)
 *    - Address types from database schema
 *
 * 4. Notification Tracking (via $_SESSION):
 *    - Completed transaction detection by comparing in-progress txids
 *    - Dead Letter Queue new item detection
 *
 * Dependencies: Expects $contactController, $transactionController, $settingsController,
 * $user, $transactionService, $currencyUtility, $p2pService, $contactService, and
 * $serviceContainer to be initialized before inclusion.
 */

// =========================================================================
// Template helper functions — shorthand for FQN calls used in .html templates
// =========================================================================

function appVersion(): string {
    return \Eiou\Core\Constants::APP_VERSION;
}

function displayDecimals(): int {
    return \Eiou\Core\Constants::getDisplayDecimals();
}

function cspNonce(): string {
    return \Eiou\Utils\Security::getCspNonce();
}

function internalPrecision(): int {
    return \Eiou\Core\Constants::INTERNAL_PRECISION;
}

function conversionFactor(): int {
    return \Eiou\Core\Constants::INTERNAL_CONVERSION_FACTOR;
}

function isSplitAmount($value): bool {
    return $value instanceof \Eiou\Core\SplitAmount;
}

function transactionMinimumFee(): float {
    return \Eiou\Core\Constants::TRANSACTION_MINIMUM_FEE;
}

function validTransportIndices(): array {
    return \Eiou\Core\Constants::VALID_TRANSPORT_INDICES;
}

function p2pMaxRoutingLevel(): int {
    return \Eiou\Core\Constants::P2P_MAX_ROUTING_LEVEL;
}

function p2pMinExpirationSeconds(): int {
    return \Eiou\Core\Constants::P2P_MIN_EXPIRATION_SECONDS;
}

function isDebugMode(): bool {
    return \Eiou\Core\Constants::isDebug();
}

function allConstants(): array {
    return \Eiou\Core\Constants::all();
}

function deliveryMaxRetries(): int {
    return \Eiou\Core\Constants::DELIVERY_MAX_RETRIES;
}

function contactTransactionsLimit(): int {
    return \Eiou\Core\Constants::CONTACT_TRANSACTIONS_LIMIT;
}

function p2pDefaultExpirationSeconds(): int {
    return \Eiou\Core\Constants::P2P_DEFAULT_EXPIRATION_SECONDS;
}

function cleanupDlqRetentionDays(): int {
    return \Eiou\Core\Constants::CLEANUP_DLQ_RETENTION_DAYS;
}

function displayDateFormat(): string {
    return \Eiou\Core\UserContext::getInstance()->getDisplayDateFormat();
}

function formatTimestamp(string $timestamp): string {
    $fmt = displayDateFormat();
    $dt = \DateTime::createFromFormat('Y-m-d H:i:s.u', $timestamp)
       ?: \DateTime::createFromFormat('Y-m-d H:i:s', $timestamp)
       ?: new \DateTime($timestamp);
    return $dt->format($fmt);
}

// =========================================================================
// Contact avatar generation
//
// Three styles, picked per-user via the contactAvatarStyle setting:
//   gradient (default) — two-color linear gradient with the contact's letter
//   tile               — Don Park-style 9-block geometric pattern
//   pixel              — GitHub-style 5x5 mirrored pixel grid
// =========================================================================

function _contactAvatarPalette(): array {
    // Hand-curated 16 colors, all WCAG AA against white text.
    return [
        '#e53935', '#d81b60', '#8e24aa', '#5e35b1',
        '#3949ab', '#1e88e5', '#039be5', '#00897b',
        '#43a047', '#7cb342', '#fb8c00', '#f4511e',
        '#6d4c41', '#546e7a', '#c0ca33', '#00acc1',
    ];
}

function _contactAvatarSeedBytes(string $seedHex): string {
    $hex = $seedHex !== '' ? $seedHex : str_repeat('0', 64);
    return hex2bin(substr($hex, 0, 16));
}

/**
 * 16 polygon patches for the tile-style avatar, inspired by Don Park's 2007
 * identicon. Each patch is a polygon in a 5×5 unit cell. Patch 15 is empty.
 */
function _contactAvatarTilePatches(): array {
    return [
        '0,0 5,0 5,5',                                  // 0: triangle ◣
        '0,0 5,0 0,5',                                  // 1: triangle ◤
        '0,0 5,0 5,5 0,5',                              // 2: full square
        '0,0 5,0 5,2.5 0,2.5',                          // 3: top half rectangle
        '0,0 2.5,0 2.5,5 0,5',                          // 4: left half rectangle
        '2.5,0 5,2.5 2.5,5 0,2.5',                      // 5: diamond
        '0,0 2.5,0 2.5,2.5 0,2.5',                      // 6: top-left quarter
        '2.5,2.5 5,2.5 5,5 2.5,5',                      // 7: bottom-right quarter
        '1.25,0 3.75,0 5,2.5 3.75,5 1.25,5 0,2.5',      // 8: hexagon
        '0,5 2.5,0 5,5',                                // 9: triangle ▲
        '0,0 5,2.5 0,5',                                // 10: triangle ▶
        '0,0 5,0 5,1.5 0,1.5',                          // 11: top stripe
        '0,5 5,5 3.75,2.5 1.25,2.5',                    // 12: trapezoid
        '1.5,1.5 3.5,1.5 3.5,3.5 1.5,3.5',              // 13: small centered square
        '2.5,1 4,2.5 2.5,4 1,2.5',                      // 14: small centered diamond
        '',                                              // 15: empty
    ];
}

/**
 * Tile avatar — 3×3 grid of polygon patches with rotational symmetry.
 * Corner cells share one patch rotated 0/90/180/270°. Edge cells likewise.
 * Center cell has its own patch. Background color is also drawn from palette.
 */
function renderContactTileAvatar(string $seedHex): string {
    $bytes = _contactAvatarSeedBytes($seedHex);
    $palette = _contactAvatarPalette();
    $patches = _contactAvatarTilePatches();

    $fg = $palette[ord($bytes[0]) % count($palette)];
    // Pick a contrasting background — offset by half the palette so it doesn't match fg
    $bgIdx = (ord($bytes[3]) + 8) % count($palette);
    if ($palette[$bgIdx] === $fg) {
        $bgIdx = ($bgIdx + 1) % count($palette);
    }
    $bg = $palette[$bgIdx];

    $centerPatch = ord($bytes[1]) & 0x0F;
    $cornerPatch = (ord($bytes[1]) >> 4) & 0x0F;
    $edgePatch   = ord($bytes[2]) & 0x0F;
    $cornerRot   = (ord($bytes[2]) >> 4) & 0x03;
    $edgeRot     = (ord($bytes[2]) >> 6) & 0x03;

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 15 15">';
    $svg .= '<rect width="15" height="15" fill="' . $bg . '"/>';

    $drawCell = function (int $col, int $row, int $patchIdx, int $rot) use (&$svg, $patches, $fg): void {
        if ($patchIdx === 15 || $patches[$patchIdx] === '') {
            return;
        }
        $x = $col * 5;
        $y = $row * 5;
        $svg .= '<g transform="translate(' . $x . ',' . $y . ') rotate(' . ($rot * 90) . ' 2.5 2.5)">';
        $svg .= '<polygon points="' . $patches[$patchIdx] . '" fill="' . $fg . '"/>';
        $svg .= '</g>';
    };

    // Corners: TL, TR, BR, BL with rotation offset
    $drawCell(0, 0, $cornerPatch, ($cornerRot + 0) % 4);
    $drawCell(2, 0, $cornerPatch, ($cornerRot + 1) % 4);
    $drawCell(2, 2, $cornerPatch, ($cornerRot + 2) % 4);
    $drawCell(0, 2, $cornerPatch, ($cornerRot + 3) % 4);

    // Edges: T, R, B, L with rotation offset
    $drawCell(1, 0, $edgePatch, ($edgeRot + 0) % 4);
    $drawCell(2, 1, $edgePatch, ($edgeRot + 1) % 4);
    $drawCell(1, 2, $edgePatch, ($edgeRot + 2) % 4);
    $drawCell(0, 1, $edgePatch, ($edgeRot + 3) % 4);

    // Center
    $drawCell(1, 1, $centerPatch, 0);

    $svg .= '</svg>';

    return '<div class="contact-avatar-sm contact-avatar-identicon">' . $svg . '</div>';
}

/**
 * Pixel avatar — 5×5 mirrored grid in a single palette color.
 * Returns a div with inline SVG. No CSS variables — colors live in SVG fill
 * attributes (not CSS), so this works under strict CSP.
 */
function renderContactPixelAvatar(string $seedHex): string {
    $bytes = _contactAvatarSeedBytes($seedHex);
    $palette = _contactAvatarPalette();
    $color = $palette[ord($bytes[0]) % count($palette)];

    // 15 cells: 3 left columns × 5 rows, mirrored to right
    $bits = (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 5 5" preserveAspectRatio="xMidYMid meet" shape-rendering="crispEdges">';
    for ($row = 0; $row < 5; $row++) {
        for ($col = 0; $col < 3; $col++) {
            $bitIdx = ($row * 3) + $col;
            if (($bits >> $bitIdx) & 1) {
                $svg .= '<rect x="' . $col . '" y="' . $row . '" width="1" height="1" fill="' . $color . '"/>';
                if ($col < 2) {
                    $svg .= '<rect x="' . (4 - $col) . '" y="' . $row . '" width="1" height="1" fill="' . $color . '"/>';
                }
            }
        }
    }
    $svg .= '</svg>';

    return '<div class="contact-avatar-sm contact-avatar-identicon">' . $svg . '</div>';
}

/**
 * Gradient avatar — two-color linear-gradient circle with the contact's
 * first letter overlaid in white. Renders entirely as inline SVG so all
 * colors are SVG attributes, not CSS (CSP-safe).
 */
function renderContactGradientAvatar(string $seedHex, string $name): string {
    $bytes = _contactAvatarSeedBytes($seedHex);
    $palette = _contactAvatarPalette();
    $idx1 = ord($bytes[0]) % count($palette);
    $idx2 = ord($bytes[1]) % count($palette);
    if ($idx2 === $idx1) {
        $idx2 = ($idx2 + 1) % count($palette);
    }
    $c1 = $palette[$idx1];
    $c2 = $palette[$idx2];

    // 4 gradient directions
    $dirs = [
        ['0', '0', '100', '100'], // diagonal TL → BR
        ['100', '0', '0', '100'], // diagonal TR → BL
        ['0', '0', '100', '0'],   // horizontal L → R
        ['0', '0', '0', '100'],   // vertical T → B
    ];
    $d = $dirs[ord($bytes[2]) % 4];

    $letter = htmlspecialchars(strtoupper(mb_substr($name !== '' ? $name : '?', 0, 1)));
    // Unique gradient ID per contact so multiple gradients on the page don't collide
    $gid = 'cag' . substr(bin2hex($bytes), 0, 12);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid meet">'
         . '<defs><linearGradient id="' . $gid . '" x1="' . $d[0] . '%" y1="' . $d[1] . '%" x2="' . $d[2] . '%" y2="' . $d[3] . '%">'
         . '<stop offset="0%" stop-color="' . $c1 . '"/>'
         . '<stop offset="100%" stop-color="' . $c2 . '"/>'
         . '</linearGradient></defs>'
         . '<circle cx="50" cy="50" r="50" fill="url(#' . $gid . ')"/>'
         . '<text x="50" y="50" text-anchor="middle" dy="0.35em" font-size="48" font-weight="700" fill="#fff" font-family="sans-serif">' . $letter . '</text>'
         . '</svg>';

    return '<div class="contact-avatar-sm contact-avatar-hybrid">' . $svg . '</div>';
}

/**
 * Router — picks the avatar style based on the user's contactAvatarStyle setting.
 * Falls back to gradient if the setting value is unknown.
 */
function renderContactAvatar(string $seedHex, string $name, string $style): string {
    return match ($style) {
        'tile'  => renderContactTileAvatar($seedHex),
        'pixel' => renderContactPixelAvatar($seedHex),
        default => renderContactGradientAvatar($seedHex, $name),
    };
}

// =========================================================================

// Route controllers if POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Contact actions
    if (in_array($action, ['addContact', 'acceptContact', 'acceptCurrency', 'acceptAllCurrencies', 'deleteContact', 'blockContact', 'unblockContact', 'editContact'])) {
        $contactController->routeAction();
    }

    // Transaction actions
    if (in_array($action, ['sendEIOU'])) {
        $transactionController->routeAction();
    }

    // Payment request actions
    if (in_array($action, ['createPaymentRequest', 'approvePaymentRequest', 'declinePaymentRequest', 'cancelPaymentRequest'])) {
        $paymentRequestController->routeAction();
    }

    // Settings actions
    if (in_array($action, ['updateSettings', 'clearDebugLogs', 'sendDebugReport'])) {
        $settingsController->routeAction();
    }

    // AJAX-only analytics consent (returns JSON, exits immediately)
    if ($action === 'analyticsConsent') {
        header('Content-Type: application/json');
        try {
            $settingsController->routeAction();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // AJAX-only settings actions (returns JSON, exits immediately)
    if ($action === 'getDebugReportJson' || $action === 'submitDebugReport') {
        // Set JSON header early to ensure clean response
        header('Content-Type: application/json');
        try {
            $settingsController->routeAction();
        } catch (Exception $e) {
            echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        }
        exit; // Ensure we don't continue to render HTML
    }

    // AJAX-only contact actions (returns JSON, exits immediately)
    if ($action === 'pingContact') {
        // contactController handles JSON header and response
        try {
            $contactController->routeAction();
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit; // Ensure we don't continue to render HTML
    }

    // AJAX-only chain drop actions (returns JSON, exits immediately)
    if (in_array($action, ['proposeChainDrop', 'acceptChainDrop', 'rejectChainDrop'])) {
        try {
            $contactController->routeAction();
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => 'Server error: ' . $e->getMessage()]);
        }
        exit;
    }

    // AJAX-only P2P approval actions (returns JSON, exits immediately)
    if (in_array($action, ['approveP2pTransaction', 'rejectP2pTransaction', 'getP2pCandidates', 'getTransactionByTxid'])) {
        try {
            $transactionController->routeAction();
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // AJAX-only DLQ actions (returns JSON, exits immediately)
    if (in_array($action, ['dlqRetry', 'dlqAbandon', 'dlqRetryAll', 'dlqAbandonAll'])) {
        try {
            $dlqController->routeAction();
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Handle GET requests for update checking
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check_updates'])) {
    $transactionController->routeAction();
}

// Get message from session flash messages (set by controllers, read-once)
// Flash messages are cleared after reading so they don't re-appear on refresh
if (isset($_SESSION[SessionKeys::MESSAGE])) {
    $messageForDisplay = $_SESSION[SessionKeys::MESSAGE];
    $messageTypeForDisplay = $_SESSION[SessionKeys::MESSAGE_TYPE] ?? 'info';
    unset($_SESSION[SessionKeys::MESSAGE], $_SESSION[SessionKeys::MESSAGE_TYPE]);
} else {
    $messageForDisplay = '';
    $messageTypeForDisplay = '';
}

// Get user based data
$maxDisplayLines = $user->getMaxOutput();
$totalBalance = $transactionService->getUserTotalBalance();
$totalEarningsSplit = $p2pService->getUserTotalEarnings();
$totalEarnings = ($totalEarningsSplit instanceof \Eiou\Core\SplitAmount) ? $currencyUtility->convertMinorToMajor($totalEarningsSplit) : 0;

// Per-currency balance data for future-proof dashboard display
$totalBalanceByCurrency = [];
$balancesRaw = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\BalanceRepository::class)->getUserBalance();
if (!empty($balancesRaw)) {
    foreach ($balancesRaw as $bal) {
        $totalBalanceByCurrency[] = [
            'currency' => $bal['currency'],
            'total' => number_format(
                ($bal['total_balance'] instanceof \Eiou\Core\SplitAmount) ? $currencyUtility->convertMinorToMajor($bal['total_balance'], $bal['currency']) : 0,
                displayDecimals()
            )
        ];
    }
}

// Per-currency earnings data for future-proof dashboard display
$totalEarningsByCurrency = [];
$earningsRaw = $p2pService->getUserTotalEarningsByCurrency();
if (!empty($earningsRaw)) {
    foreach ($earningsRaw as $earn) {
        $totalEarningsByCurrency[] = [
            'currency' => $earn['currency'],
            'total' => number_format(
                ($earn['total_amount'] instanceof \Eiou\Core\SplitAmount) ? $currencyUtility->convertMinorToMajor($earn['total_amount'], $earn['currency']) : 0,
                displayDecimals()
            )
        ];
    }
}
// Collect known currencies from all data sources for consistent fallback display
$knownCurrencies = [];
foreach ($totalBalanceByCurrency as $item) {
    $knownCurrencies[$item['currency']] = true;
}
foreach ($totalEarningsByCurrency as $item) {
    $knownCurrencies[$item['currency']] = true;
}
// totalAvailableCreditByCurrency is populated later, so also check contact_credit directly
try {
    $creditCurrencies = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCreditRepository::class)->getTotalAvailableCreditByCurrency();
    foreach ($creditCurrencies as $row) {
        $knownCurrencies[$row['currency']] = true;
    }
} catch (Exception $e) {
    // Non-critical
}
// Include currencies from accepted contact currency relationships
try {
    $acceptedCurrencies = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class)->getDistinctAcceptedCurrencies();
    foreach ($acceptedCurrencies as $cur) {
        $knownCurrencies[$cur] = true;
    }
} catch (Exception $e) {
    // Non-critical
}
$knownCurrencies = array_keys($knownCurrencies);
// Sort by allowed currencies order (first currency = first row, etc.)
$allowedCurrenciesOrder = $user->getAllowedCurrencies();
usort($knownCurrencies, function($a, $b) use ($allowedCurrenciesOrder) {
    $posA = array_search($a, $allowedCurrenciesOrder);
    $posB = array_search($b, $allowedCurrenciesOrder);
    if ($posA === false) $posA = PHP_INT_MAX;
    if ($posB === false) $posB = PHP_INT_MAX;
    return $posA - $posB;
});

$recentTransactionsLimit = $user->getDisplayRecentTransactionsLimit();
$transactions = $transactionService->getTransactionHistory($recentTransactionsLimit);
$inProgressTransactions = $transactionService->getInProgressTransactions(5);

// Update check status (reads cache only — never triggers a new check on page load)
$updateCheckStatus = \Eiou\Services\UpdateCheckService::getStatus();

// Analytics status (reads cache only — never triggers a new submission on page load)
try {
    $analyticsStatus = \Eiou\Services\AnalyticsService::getStatus();
} catch (\Throwable $e) {
    $analyticsStatus = [];
}

// Check Tor/SOCKS5 GUI status (written by TransportUtilityService and startup.sh watchdog)
$torGuiStatus = null;
$torGuiStatusFile = '/tmp/tor-gui-status';
if (file_exists($torGuiStatusFile)) {
    $torGuiRaw = file_get_contents($torGuiStatusFile);
    if ($torGuiRaw !== false) {
        $torGuiData = json_decode($torGuiRaw, true);
        if (is_array($torGuiData) && isset($torGuiData['status'], $torGuiData['timestamp'])) {
            $torGuiAge = time() - (int)$torGuiData['timestamp'];
            if ($torGuiData['status'] === 'recovered' && $torGuiAge > 300) {
                // Recovery older than 5 minutes — clean up
                unlink($torGuiStatusFile);
            } elseif ($torGuiAge > 600) {
                // Any status older than 10 minutes — stale, clean up
                @unlink($torGuiStatusFile);
            } else {
                $torGuiStatus = $torGuiData;
            }
        }
    }
}

// Track completed transactions for notifications (sent transactions)
// Get previously known in-progress txids from session
$prevInProgressTxids = $_SESSION[SessionKeys::IN_PROGRESS_TXIDS] ?? [];

// Get current in-progress transaction IDs
$currentInProgressTxids = array_column($inProgressTransactions ?? [], 'txid');

// Find completed txids (were in progress, now are not)
$completedTxids = array_diff($prevInProgressTxids, $currentInProgressTxids);

// Get details for completed transactions
$newlyCompletedTransactions = [];
foreach ($completedTxids as $txid) {
    // Check if the transaction is now completed
    foreach ($transactions ?? [] as $tx) {
        if (($tx['txid'] ?? '') === $txid && ($tx['status'] ?? '') === 'completed') {
            $newlyCompletedTransactions[] = $tx;
            break;
        }
    }
}

// Store current in-progress txids for next comparison
$_SESSION[SessionKeys::IN_PROGRESS_TXIDS] = $currentInProgressTxids;

// Track received transactions for notifications
// Received transactions bypass in-progress tracking (they arrive completed),
// so we detect them by comparing known txids across page loads
$currentTxids = array_column($transactions ?? [], 'txid');
$prevKnownTxids = $_SESSION[SessionKeys::KNOWN_TXIDS] ?? null;
$newlyReceivedTransactions = [];

// Only detect new transactions if we have a previous baseline (skip first page load)
if ($prevKnownTxids !== null) {
    $newTxids = array_diff($currentTxids, $prevKnownTxids);
    foreach ($newTxids as $txid) {
        foreach ($transactions as $tx) {
            if (($tx['txid'] ?? '') === $txid && ($tx['type'] ?? '') === 'received' && ($tx['tx_type'] ?? '') !== 'contact') {
                $newlyReceivedTransactions[] = $tx;
                break;
            }
        }
    }
}

$_SESSION[SessionKeys::KNOWN_TXIDS] = $currentTxids;

// Contact data
$allContacts = $contactService->getAllContacts();
$pendingContacts = $contactService->getPendingContactRequests();

// Check if pending contacts have prior transaction history (wallet restore scenario)
// and retrieve the description from the contact transaction (sent with the request)
if (!empty($pendingContacts) && $user->has('public')) {
    $txRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\TransactionRepository::class);
    $txContactRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\TransactionContactRepository::class);
    $myPubkey = $user->getPublicKey();
    foreach ($pendingContacts as &$pc) {
        // Check for prior non-contact transaction history
        $history = $txRepo->getNonContactTransactionsBetweenPubkeys($myPubkey, $pc['pubkey'], 1);
        $pc['has_prior_history'] = !empty($history);

        // Get the contact transaction description (message sent with the request)
        $contactTx = $txContactRepo->getContactTransactionByParties($pc['pubkey'], $myPubkey);
        $desc = $contactTx['description'] ?? null;
        if ($desc !== null && $desc !== 'Contact request transaction') {
            $pc['contact_description'] = $desc;
        }
    }
    unset($pc);
}

// Enrich pending contacts with per-currency data from contact_currencies (direction-aware)
if (!empty($pendingContacts)) {
    try {
        $pendingCurrencyRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        foreach ($pendingContacts as &$pc) {
            $hash = $pc['pubkey_hash'] ?? '';
            if ($hash) {
                // Incoming: currencies THEY requested from us (we need to accept/reject)
                $pc['pending_currencies'] = $pendingCurrencyRepo->getPendingCurrencies($hash, 'incoming');
                // Outgoing: currencies WE requested from them (waiting for their acceptance)
                $pc['outgoing_currencies'] = $pendingCurrencyRepo->getPendingCurrencies($hash, 'outgoing');

                // Enrich pending currencies with descriptions from contact transactions
                $descByCurrency = $txContactRepo->getContactDescriptionsByCurrency($pc['pubkey'], $myPubkey);
                if (!empty($descByCurrency)) {
                    foreach ($pc['pending_currencies'] as &$pcur) {
                        $cur = $pcur['currency'] ?? '';
                        if (isset($descByCurrency[$cur])) {
                            $pcur['description'] = $descByCurrency[$cur];
                        }
                    }
                    unset($pcur);
                }
            }
        }
        unset($pc);
    } catch (Exception $e) {
        // Non-critical — pending contacts will show without currency data
    }
}

$contactTxLimit = contactTransactionsLimit();
$pendingUserContacts = $transactionService->contactBalanceConversion($contactService->getUserPendingContactRequests(), $contactTxLimit);
$acceptedContacts = $transactionService->contactBalanceConversion($contactService->getAcceptedContacts(), $contactTxLimit);
$blockedContacts = $transactionService->contactBalanceConversion($contactService->getBlockedContacts(), $contactTxLimit);

// Enrich pending user contacts (our outgoing requests) with direction-aware currency data
if (!empty($pendingUserContacts)) {
    try {
        $currencyRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        foreach ($pendingUserContacts as &$puc) {
            $hash = $puc['pubkey_hash'] ?? '';
            if ($hash) {
                $puc['pending_currencies'] = $currencyRepo->getPendingCurrencies($hash, 'incoming');
                $puc['outgoing_currencies'] = $currencyRepo->getPendingCurrencies($hash, 'outgoing');
            }
        }
        unset($puc);
    } catch (Exception $e) {}
}

// Enrich accepted contacts with pending incoming currency requests
if (!empty($acceptedContacts)) {
    try {
        $currencyRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
        foreach ($acceptedContacts as &$ac) {
            $hash = $ac['pubkey_hash'] ?? '';
            if ($hash) {
                $ac['pending_currencies'] = $currencyRepo->getPendingCurrencies($hash, 'incoming');
                $ac['outgoing_currencies'] = $currencyRepo->getPendingCurrencies($hash, 'outgoing');
            }
        }
        unset($ac);
    } catch (Exception $e) {}
}

// Build lookup of contacts we already have (accepted or our outgoing pending)
$existingContactHashes = [];
foreach ($acceptedContacts as $ac) {
    if (!empty($ac['pubkey_hash'])) {
        $existingContactHashes[$ac['pubkey_hash']] = true;
    }
}
foreach ($pendingUserContacts as $puc) {
    if (!empty($puc['pubkey_hash'])) {
        $existingContactHashes[$puc['pubkey_hash']] = true;
    }
}

// Mark pending contact requests that already exist in our contact list
// so the template uses acceptCurrency instead of acceptContact
foreach ($pendingContacts as &$pc) {
    $hash = $pc['pubkey_hash'] ?? '';
    if ($hash && isset($existingContactHashes[$hash])) {
        $pc['is_existing_contact'] = true;
    }
}
unset($pc);

// Also show named pending contacts with incoming currencies in the standalone section
// Keep them in $pendingUserContacts too so they still appear in the contacts grid
$pendingContactHashes = [];
foreach ($pendingContacts as $pc) {
    if (!empty($pc['pubkey_hash'])) {
        $pendingContactHashes[$pc['pubkey_hash']] = true;
    }
}

foreach ($pendingUserContacts as $puc) {
    if (!empty($puc['pending_currencies']) && !isset($pendingContactHashes[$puc['pubkey_hash'] ?? ''])) {
        $puc['is_existing_contact'] = true;
        $pendingContacts[] = $puc;
    }
}

// Also add accepted contacts with pending incoming currencies to $pendingContacts
// (they stay in $acceptedContacts too — grid shows them as accepted, standalone section shows accept form)
foreach ($acceptedContacts as $c) {
    if (!empty($c['pending_currencies']) && !isset($pendingContactHashes[$c['pubkey_hash'] ?? ''])) {
        $c['is_existing_contact'] = true;
        $pendingContacts[] = $c;
    }
}
// $pendingCurrencyContacts no longer needed for separate notification
$pendingCurrencyContacts = [];

// Address types (dynamic from database schema)
$addressTypes = $contactService->getAllAddressTypes();

// Chain drop proposals - fetch both directions, index by contact hash
$chainDropProposalsByContact = [];
try {
    $chainDropService = $serviceContainer->getChainDropService();
    $chainDropProposalRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ChainDropProposalRepository::class);

    $incomingProposals = $chainDropService->getIncomingPendingProposals();
    $outgoingProposals = $chainDropProposalRepo->getOutgoingPending();
    $rejectedProposals = $chainDropProposalRepo->getRecentRejected();

    // Index by contact_pubkey_hash (incoming pending > outgoing pending > rejected)
    foreach ($incomingProposals as $proposal) {
        $hash = $proposal['contact_pubkey_hash'];
        if (!isset($chainDropProposalsByContact[$hash])) {
            $chainDropProposalsByContact[$hash] = $proposal;
        }
    }
    foreach ($outgoingProposals as $proposal) {
        $hash = $proposal['contact_pubkey_hash'];
        if (!isset($chainDropProposalsByContact[$hash])) {
            $chainDropProposalsByContact[$hash] = $proposal;
        }
    }
    foreach ($rejectedProposals as $proposal) {
        $hash = $proposal['contact_pubkey_hash'];
        if (!isset($chainDropProposalsByContact[$hash])) {
            $chainDropProposalsByContact[$hash] = $proposal;
        }
    }
} catch (Exception $e) {
    $chainDropProposalsByContact = [];
}

// Merge chain drop proposals into contact arrays by pubkey_hash
$contactArrays = [&$acceptedContacts, &$pendingUserContacts, &$blockedContacts];
foreach ($contactArrays as &$contacts) {
    foreach ($contacts as &$contact) {
        $hash = $contact['pubkey_hash'] ?? '';
        if ($hash && isset($chainDropProposalsByContact[$hash])) {
            $contact['chain_drop_proposal'] = $chainDropProposalsByContact[$hash];
        }
    }
    unset($contact);
}
unset($contacts);

// Compute chain gap details for contacts with invalid chains
// Shows which txids are valid before/after each gap so users can investigate
$tcRepo = null;
try {
    $tcRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\TransactionChainRepository::class);
} catch (Exception $e) {
    // Repository not available, skip gap details
}
if ($tcRepo && $user->has('public')) {
    $myPubkey = $user->getPublicKey();
    foreach ($acceptedContacts as &$contact) {
        // Compute gap details when the chain is known-invalid OR when there is an
        // active chain-drop proposal (covers the window between proposal creation and
        // the first "Check Status" ping that would set valid_chain = 0 in the DB).
        $hasInvalidChain = (int)($contact['valid_chain'] ?? -1) === 0;
        $hasActiveProposal = !empty($contact['chain_drop_proposal'])
            && in_array(
                $contact['chain_drop_proposal']['status'] ?? '',
                ['pending', 'awaiting_acceptance', 'rejected'],
                true
            );
        if (($hasInvalidChain || $hasActiveProposal) && !empty($contact['pubkey'])) {
            try {
                $integrity = $tcRepo->verifyChainIntegrity($myPubkey, $contact['pubkey']);
                if (!$integrity['valid'] && !empty($integrity['gap_context'])) {
                    $contact['chain_gap_details'] = $integrity['gap_context'];
                }
            } catch (Exception $e) {
                // Skip this contact's gap details on error
            }
        }
    }
    unset($contact);
}

// Dead Letter Queue - track newly added items for notification
$newlyAddedToDlq = [];
try {
    $dlqRepository = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\DeadLetterQueueRepository::class);
    $currentDlqItems = $dlqRepository->getPendingItems(50);

    // Get previously known DLQ item IDs from session
    $prevDlqIds = $_SESSION[SessionKeys::KNOWN_DLQ_IDS] ?? [];

    // Find items that are new (not previously seen)
    $currentDlqIds = array_column($currentDlqItems, 'id');
    foreach ($currentDlqItems as $item) {
        if (!in_array($item['id'], $prevDlqIds)) {
            $newlyAddedToDlq[] = $item;
        }
    }

    // Update session with current DLQ IDs
    $_SESSION[SessionKeys::KNOWN_DLQ_IDS] = $currentDlqIds;
} catch (Exception $e) {
    // Silently fail - DLQ notification is non-critical
    $newlyAddedToDlq = [];
}

// Dead Letter Queue - load items for the DLQ management section
$dlqItems = [];
$dlqStats = [];
$dlqPendingCount = 0;
try {
    $dlqRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\DeadLetterQueueRepository::class);
    // Always load all items — client-side JS handles tab filtering with no page reload
    $dlqItems = $dlqRepo->getItems(null, \Eiou\Core\Constants::DLQ_BATCH_SIZE);

    $dlqStats        = $dlqRepo->getStatistics();
    $dlqPendingCount = $dlqRepo->getPendingCount();

    // Collect message_ids for active (pending/retrying) transaction DLQ entries so
    // the transaction history can show a DLQ indicator on affected transactions.
    $dlqActiveTxMessageIds = [];
    $activeTxDlq = array_merge(
        $dlqRepo->getByMessageType('transaction', 'pending',  \Eiou\Core\Constants::DLQ_BATCH_SIZE),
        $dlqRepo->getByMessageType('transaction', 'retrying', \Eiou\Core\Constants::DLQ_BATCH_SIZE)
    );
    foreach ($activeTxDlq as $dlqEntry) {
        $dlqActiveTxMessageIds[] = $dlqEntry['message_id'];
    }
} catch (Exception $e) {
    // Silently fail - DLQ section is non-critical
    $dlqActiveTxMessageIds = [];
}

// Fetch available credit per contact and merge into contact arrays
$availableCreditByContact = [];
$totalAvailableCreditByCurrency = [];
try {
    $contactCreditRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCreditRepository::class);
    // Get per-contact credits for merging into contact cards
    foreach (array_merge($acceptedContacts, $pendingUserContacts, $blockedContacts) as $c) {
        $hash = $c['pubkey_hash'] ?? '';
        if ($hash && !isset($availableCreditByContact[$hash])) {
            $creditData = $contactCreditRepo->getAvailableCredit($hash);
            if ($creditData !== null) {
                $contactCurrency = $c['currency'] ?? \Eiou\Core\Constants::TRANSACTION_DEFAULT_CURRENCY;
                $availableCreditByContact[$hash] = $creditData['available_credit']->toMajorUnits();
            }
        }
    }
    // Get totals per currency for dashboard display
    $creditTotals = $contactCreditRepo->getTotalAvailableCreditByCurrency();
    foreach ($creditTotals as $row) {
        $totalAvailableCreditByCurrency[] = [
            'currency' => $row['currency'],
            'total' => number_format($row['total_available_credit']->toMajorUnits(), displayDecimals())
        ];
    }
} catch (Exception $e) {
    $availableCreditByContact = [];
    $totalAvailableCreditByCurrency = [];
}

// Fetch per-contact currency configs for multi-currency support
$contactCurrenciesByHash = [];
try {
    $contactCurrencyRepo = $serviceContainer->getRepositoryFactory()->get(\Eiou\Database\ContactCurrencyRepository::class);
    foreach (array_merge($acceptedContacts, $pendingUserContacts, $blockedContacts) as $c) {
        $hash = $c['pubkey_hash'] ?? '';
        if ($hash && !isset($contactCurrenciesByHash[$hash])) {
            $contactCurrenciesByHash[$hash] = $contactCurrencyRepo->getContactCurrencies($hash);
        }
    }
} catch (Exception $e) {
    $contactCurrenciesByHash = [];
}

// Fetch per-contact all-currency available credits
$availableCreditAllByHash = [];
try {
    foreach (array_merge($acceptedContacts, $pendingUserContacts, $blockedContacts) as $c) {
        $hash = $c['pubkey_hash'] ?? '';
        if ($hash && !isset($availableCreditAllByHash[$hash])) {
            $allCredits = $contactCreditRepo->getAvailableCreditAllCurrencies($hash);
            $creditMap = [];
            foreach ($allCredits as $cr) {
                $cur = $cr['currency'] ?? \Eiou\Core\Constants::TRANSACTION_DEFAULT_CURRENCY;
                $creditMap[$cur] = $cr['available_credit']->toMajorUnits();
            }
            $availableCreditAllByHash[$hash] = $creditMap;
        }
    }
} catch (Exception $e) {
    $availableCreditAllByHash = [];
}

// Merge available credit into contact arrays and calculate their available credit with me
$contactArraysForCredit = [&$acceptedContacts, &$pendingUserContacts, &$blockedContacts];
foreach ($contactArraysForCredit as &$contacts) {
    foreach ($contacts as &$contact) {
        $hash = $contact['pubkey_hash'] ?? '';
        // My available credit with them (from pong, stored in contact_credit)
        $contact['my_available_credit'] = $availableCreditByContact[$hash] ?? null;
        // Their available credit is now computed per-currency in the currencies array below
        $contact['their_available_credit'] = null;

        // Build multi-currency data (direction-aware)
        $currencyConfigs = $contactCurrenciesByHash[$hash] ?? [];
        $allCredits = $availableCreditAllByHash[$hash] ?? [];
        $acceptedCurrencies = [];
        $pendingIncoming = [];
        $pendingOutgoing = [];
        $contactBalancesByCurrency = $contact['balances_by_currency'] ?? [];
        foreach ($currencyConfigs as $cc) {
            $cur = $cc['currency'];
            $ccStatus = $cc['status'] ?? 'accepted';
            $ccDirection = $cc['direction'] ?? 'outgoing';
            $creditLimitMajor = ($cc['credit_limit'] instanceof \Eiou\Core\SplitAmount) ? $cc['credit_limit']->toMajorUnits() : 0;
            $balanceForCur = floatval($contactBalancesByCurrency[$cur] ?? 0);
            $entry = [
                'currency' => $cur,
                'fee' => ($cc['fee_percent'] ?? 0) / \Eiou\Core\Constants::FEE_CONVERSION_FACTOR,
                'credit_limit' => $creditLimitMajor,
                'my_available_credit' => $allCredits[$cur] ?? null,
                'their_available_credit' => ($creditLimitMajor > 0 || $balanceForCur != 0) ? round($creditLimitMajor - $balanceForCur, 2) : null,
                'status' => $ccStatus,
                'direction' => $ccDirection,
            ];
            if ($ccStatus === 'accepted') {
                // Deduplicate: keep one entry per currency (prefer outgoing — has our fee/credit)
                if (!isset($acceptedCurrencies[$cur]) || $ccDirection === 'outgoing') {
                    $acceptedCurrencies[$cur] = $entry;
                }
            } elseif ($ccStatus === 'pending') {
                if ($ccDirection === 'incoming') {
                    $pendingIncoming[] = $entry;
                } else {
                    $pendingOutgoing[] = $entry;
                }
            }
        }
        $contact['currencies'] = array_values($acceptedCurrencies);
        $contact['pending_currencies'] = $pendingIncoming;
        $contact['outgoing_currencies'] = $pendingOutgoing;
    }
    unset($contact);
}
unset($contacts);

// Load payment requests for display in the Send tab
$paymentRequests = ['incoming' => [], 'outgoing' => []];
$pendingPaymentRequestCount = 0;
try {
    $paymentRequests = $serviceContainer->getPaymentRequestService()->getAllForDisplay(50);
    $pendingPaymentRequestCount = $serviceContainer->getPaymentRequestService()->countPendingIncoming();
} catch (Exception $e) {
    // Non-critical — payment requests section will be empty
}

// Initialize ContactDataBuilder helper
$contactDataBuilder = new ContactDataBuilder($addressTypes);