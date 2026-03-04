<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Gui\Controllers;

use Eiou\Services\CrossDomainCookieService;

/**
 * Demo controller showing cross-subdomain cookie reading.
 * Deploy this on wns.eiou.org or gftn.eiou.org to prove the cookie works.
 */
class CrossDomainDemoController
{
    public function render(): string
    {
        $wallet = CrossDomainCookieService::readWalletCookieUntrusted();
        $hasWallet = $wallet !== null;
        $currentHost = $_SERVER['HTTP_HOST'] ?? 'unknown';
        $cookieRaw = $_COOKIE['eiou_wallet'] ?? '(not set)';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eIOU Cross-Subdomain Cookie Demo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0a0a; color: #e0e0e0;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
        }
        .container { max-width: 720px; width: 100%; padding: 2rem; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; color: #fff; }
        .subtitle { color: #888; margin-bottom: 2rem; font-size: 0.9rem; }
        .card {
            background: #161616; border: 1px solid #2a2a2a; border-radius: 12px;
            padding: 1.5rem; margin-bottom: 1.5rem;
        }
        .card h2 { font-size: 1.1rem; margin-bottom: 1rem; color: #ccc; }
        .status {
            display: inline-block; padding: 0.25rem 0.75rem; border-radius: 999px;
            font-size: 0.8rem; font-weight: 600;
        }
        .status.found { background: #0a3d1a; color: #4ade80; border: 1px solid #166534; }
        .status.missing { background: #3d0a0a; color: #f87171; border: 1px solid #7f1d1d; }
        .field { margin: 0.75rem 0; }
        .field label { display: block; font-size: 0.75rem; color: #666; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
        .field .value {
            font-family: 'SF Mono', 'Fira Code', monospace; font-size: 0.85rem;
            color: #e0e0e0; word-break: break-all;
            background: #0d0d0d; padding: 0.5rem 0.75rem; border-radius: 6px;
            border: 1px solid #222;
        }
        .field .value.highlight { color: #60a5fa; }
        .how-it-works { color: #888; font-size: 0.85rem; line-height: 1.6; }
        .how-it-works code { background: #1a1a1a; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.8rem; color: #a78bfa; }
        .arrow { text-align: center; font-size: 1.5rem; color: #444; margin: 1rem 0; }
        .try-it { margin-top: 1rem; }
        .try-it a {
            color: #60a5fa; text-decoration: none; font-size: 0.85rem;
        }
        .try-it a:hover { text-decoration: underline; }
        #js-result { margin-top: 1rem; }
    </style>
</head>
<body>
<div class="container">
    <h1>🍪 Cross-Subdomain Cookie Demo</h1>
    <p class="subtitle">
        This page is served from <strong><?= htmlspecialchars($currentHost) ?></strong>.
        It reads a cookie set by <strong>wallet.eiou.org</strong> on the <code>.eiou.org</code> domain.
    </p>

    <div class="card">
        <h2>Cookie Status</h2>
        <?php if ($hasWallet): ?>
            <span class="status found">✓ Cookie Found</span>
        <?php else: ?>
            <span class="status missing">✗ No Cookie</span>
            <p style="margin-top: 1rem; color: #888; font-size: 0.85rem;">
                Log into <a href="https://wallet.eiou.org/gui/" style="color: #60a5fa;">wallet.eiou.org</a> first.
                The login sets a cookie on <code>.eiou.org</code> that all subdomains can read.
            </p>
        <?php endif; ?>
    </div>

    <?php if ($hasWallet): ?>
    <div class="card">
        <h2>Wallet Info (from cookie)</h2>
        <div class="field">
            <label>Display Name</label>
            <div class="value highlight"><?= htmlspecialchars($wallet['name'] ?? 'unknown') ?></div>
        </div>
        <div class="field">
            <label>Public Key Hash</label>
            <div class="value"><?= htmlspecialchars($wallet['pubkey_hash'] ?? '') ?></div>
        </div>
        <div class="field">
            <label>HTTP Address</label>
            <div class="value"><?= htmlspecialchars($wallet['http'] ?? 'n/a') ?></div>
        </div>
        <div class="field">
            <label>HTTPS Address</label>
            <div class="value"><?= htmlspecialchars($wallet['https'] ?? 'n/a') ?></div>
        </div>
        <div class="field">
            <label>Tor Address</label>
            <div class="value"><?= htmlspecialchars($wallet['tor'] ?? 'n/a') ?></div>
        </div>
        <div class="field">
            <label>Authenticated At</label>
            <div class="value"><?= isset($wallet['auth_time']) ? date('Y-m-d H:i:s T', $wallet['auth_time']) : 'n/a' ?></div>
        </div>
    </div>

    <div class="arrow">↕</div>

    <div class="card">
        <h2>JavaScript Read (client-side proof)</h2>
        <p style="color: #888; font-size: 0.85rem; margin-bottom: 1rem;">
            Same cookie, read via JavaScript on this subdomain:
        </p>
        <div id="js-result">
            <div class="field">
                <label>Raw Cookie (JS)</label>
                <div class="value" id="js-raw">loading...</div>
            </div>
            <div class="field">
                <label>Decoded Payload (JS)</label>
                <div class="value" id="js-decoded">loading...</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>How It Works</h2>
        <div class="how-it-works">
            <p>1. User logs into <code>wallet.eiou.org</code> with their auth code</p>
            <p>2. On success, PHP sets a cookie with <code>domain=.eiou.org</code>:</p>
            <p style="margin: 0.5rem 0; padding-left: 1rem;">
                <code>setcookie('eiou_wallet', $payload, ['domain' => '.eiou.org', ...])</code>
            </p>
            <p>3. The cookie contains: name, public key hash, node addresses, auth timestamp</p>
            <p>4. It's HMAC-signed so the origin node can verify it wasn't tampered with</p>
            <p>5. Any subdomain (<code>wns.eiou.org</code>, <code>gftn.eiou.org</code>, etc.) can read it</p>
            <p style="margin-top: 1rem;">
                <strong>This page proves step 5.</strong> It's on a different subdomain and can see the wallet cookie.
            </p>
        </div>
    </div>

    <div class="card try-it">
        <h2>Try It</h2>
        <p style="color: #888; font-size: 0.85rem;">
            Visit these subdomains — they all see the same cookie:
        </p>
        <ul style="list-style: none; margin-top: 0.75rem;">
            <li style="margin: 0.5rem 0;"><a href="https://wallet.eiou.org/gui/">wallet.eiou.org/gui/</a> — Login here (sets the cookie)</li>
            <li style="margin: 0.5rem 0;"><a href="https://wns.eiou.org/cookie-demo">wns.eiou.org/cookie-demo</a> — This demo page</li>
            <li style="margin: 0.5rem 0;"><a href="https://gftn.eiou.org/cookie-demo">gftn.eiou.org/cookie-demo</a> — Same demo, different subdomain</li>
        </ul>
    </div>
</div>

<script>
(function() {
    try {
        var cookies = document.cookie.split(';').reduce(function(acc, c) {
            var parts = c.trim().split('=');
            acc[parts[0]] = decodeURIComponent(parts.slice(1).join('='));
            return acc;
        }, {});

        var raw = cookies['eiou_wallet'];
        var rawEl = document.getElementById('js-raw');
        var decodedEl = document.getElementById('js-decoded');

        if (!raw) {
            if (rawEl) rawEl.textContent = '(cookie not found)';
            if (decodedEl) decodedEl.textContent = '(n/a)';
            return;
        }

        if (rawEl) rawEl.textContent = raw.substring(0, 80) + '...';

        var parts = raw.split('.');
        var json = atob(parts[0]);
        var payload = JSON.parse(json);

        if (decodedEl) {
            decodedEl.textContent = JSON.stringify(payload, null, 2);
            decodedEl.style.whiteSpace = 'pre';
        }
    } catch(e) {
        var el = document.getElementById('js-decoded');
        if (el) el.textContent = 'Error: ' + e.message;
    }
})();
</script>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
