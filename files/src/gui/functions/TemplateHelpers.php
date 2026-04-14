<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Template helper functions — shorthand for FQN calls used in .html templates.
 *
 * This file is loaded BEFORE authentication in index.html so that the
 * unauthenticated login page (authenticationForm.html) and any shared
 * sub-templates (banner.html) can call these helpers too. It must stay pure:
 * no POST routing, no view-data initialization, no session state — all of
 * that lives in Functions.php and runs only after authentication.
 *
 * Depends on: the Composer autoloader (loaded in /app/eiou/Functions.php),
 * and UserContext::getInstance() for displayDateFormat() / formatTimestamp().
 */

// =========================================================================
// Constants wrappers
// =========================================================================

function appVersion(): string {
    return \Eiou\Core\Constants::APP_VERSION;
}

function appVersionDisplay(): string {
    // Strip pre-release suffix (-alpha, -beta, -rcN) and prefix with 'v'
    return 'v' . preg_replace('/-(alpha|beta|rc\d*)$/i', '', \Eiou\Core\Constants::APP_VERSION);
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

function p2pDefaultExpirationSeconds(): int {
    return \Eiou\Core\Constants::P2P_DEFAULT_EXPIRATION_SECONDS;
}

function cleanupDlqRetentionDays(): int {
    return \Eiou\Core\Constants::CLEANUP_DLQ_RETENTION_DAYS;
}

function sessionTimeoutOptions(): array {
    return \Eiou\Core\Constants::SESSION_TIMEOUT_OPTIONS;
}

function contactAvatarStyleOptions(): array {
    return \Eiou\Core\Constants::CONTACT_AVATAR_STYLE_OPTIONS;
}

function validDateFormats(): array {
    return \Eiou\Core\Constants::VALID_DATE_FORMATS;
}

function amountColorSchemeOptions(): array {
    return \Eiou\Core\Constants::AMOUNT_COLOR_SCHEME_OPTIONS;
}

function statusColorSchemeOptions(): array {
    return \Eiou\Core\Constants::STATUS_COLOR_SCHEME_OPTIONS;
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

/**
 * Human-readable display label for a transaction counterparty. Prefers the
 * contact name when known, falls back to a truncated address (first 20 chars
 * + ellipsis), and finally to "a contact" if neither is available. Used by
 * toast notifications so users see "Bob" instead of "https://bob.onion/…".
 */
function counterpartyDisplay(?string $name, ?string $address): string {
    if (!empty($name)) {
        return $name;
    }
    if (!empty($address)) {
        return strlen($address) > 20 ? substr($address, 0, 20) . '…' : $address;
    }
    return 'a contact';
}

// =========================================================================
// Text + template-content helpers
// =========================================================================

/**
 * Auto-link bare URLs and domain-like tokens inside already-escaped text.
 * Runs AFTER htmlspecialchars — do not pass raw user input.
 */
function autoLinkUrls(string $escaped): string {
    return preg_replace_callback(
        '~(https?://[^\s<]+|(?:[\w-]+\.)+(?:com|org|net|io|dev)(?:/[^\s<]*)?)~i',
        function ($m) {
            $url = $m[1];
            $href = preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
            return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
        },
        $escaped
    );
}

/**
 * Scan /app/eiou/src/gui/assets/banners/ for banner image files.
 * Returns a sorted list of filenames (no path). Dotfiles are skipped.
 */
function getBanners(): array {
    $dir = '/app/eiou/src/gui/assets/banners';
    $exts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
    if (!is_dir($dir)) {
        return [];
    }
    $banners = [];
    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..' || $file[0] === '.') {
            continue;
        }
        if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $exts, true)) {
            $banners[] = $file;
        }
    }
    sort($banners);
    return $banners;
}

/**
 * Parse the alpha-warning text file into title + intro paragraphs + detail
 * paragraphs. Returns null if the file does not exist.
 *
 * The file format: first line is the title, paragraphs are separated by
 * blank lines, and everything after the first paragraph starting with
 * "IMPORTANT:" goes into the collapsible details section.
 */
function getAlphaWarning(): ?array {
    $warningFile = '/app/scripts/banners/alpha-warning.txt';
    if (!file_exists($warningFile)) {
        return null;
    }
    $warnText = trim(file_get_contents($warningFile));
    $paragraphs = preg_split('/\n\s*\n/', $warnText);
    $title = htmlspecialchars(trim($paragraphs[0]));
    $intro = [];
    $details = [];
    $foundImportant = false;
    for ($i = 1; $i < count($paragraphs); $i++) {
        $para = trim($paragraphs[$i]);
        if (!$foundImportant && preg_match('/^IMPORTANT/i', $para)) {
            $foundImportant = true;
        }
        if ($foundImportant) {
            // Strip "IMPORTANT:" prefix, join hard-wrapped lines into flowing text
            $para = preg_replace('/^IMPORTANT:\s*/i', '', $para);
            if (trim($para) === '') continue;
            $para = preg_replace('/\s*\n\s*/', ' ', trim($para));
            $details[] = $para;
        } else {
            $intro[] = $para;
        }
    }
    return [
        'title'   => $title,
        'intro'   => $intro,
        'details' => $details,
    ];
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
    // Unique gradient ID per SVG instance — same contact can appear in multiple
    // tables (contacts + transactions), so a per-hash ID causes duplicate-ID
    // collisions that break fill="url(#…)" resolution in inline SVGs.
    static $cagCounter = 0;
    $gid = 'cag' . substr(bin2hex($bytes), 0, 8) . dechex(++$cagCounter);

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
