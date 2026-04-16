<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Maintenance Mode Check
 *
 * Include this at the top of HTTP entry points (API, GUI, P2P) to return
 * 503 Service Unavailable while the node is in maintenance mode (during
 * startup source file sync, database migrations, and autoloader rebuild).
 *
 * The lockfile is created by startup.sh before source sync and removed
 * after all initialization is complete.
 *
 * The response body is content-negotiated from the request's Accept
 * header: browsers (Accept: text/html) get a styled auto-refreshing HTML
 * page so a user who opens the wallet during an upgrade sees "Under
 * Maintenance — check back shortly" instead of raw JSON. API clients
 * (Accept: application/json, *\/* or missing) keep the existing JSON
 * response. Both paths set HTTP 503 and Retry-After: 30.
 *
 * The HTML page is fully inlined (no external CSS/JS/fonts) because
 * maintenance mode can run while `/app/eiou/` is mid-rebuild — none of
 * the app's own assets are guaranteed resolvable.
 *
 * CLI entry points and background processors should NOT include this file.
 */

/**
 * Decide whether to serve HTML or JSON based on the client's Accept
 * header. Returns true iff the client explicitly accepts text/html —
 * browsers always send it, API clients never do. A missing or wildcard-
 * only Accept header (curl default `*`/`*`) falls through to JSON, since
 * JSON was the historical default and machine callers rely on it.
 */
if (!function_exists('eiou_maintenance_prefers_html')) {
    function eiou_maintenance_prefers_html(string $acceptHeader): bool
    {
        if ($acceptHeader === '') {
            return false;
        }
        return stripos($acceptHeader, 'text/html') !== false;
    }
}

/**
 * Render the HTML maintenance page. Self-contained: no external CSS, JS,
 * or webfonts. The <meta http-equiv="refresh"> gives the user automatic
 * recovery without needing JS (important since Tor Browser users often
 * run with JS disabled). 15 s matches the server's Retry-After of 30 —
 * we poll at twice the rate so the user sees recovery promptly.
 */
if (!function_exists('eiou_maintenance_html_page')) {
    function eiou_maintenance_html_page(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="15">
    <title>eIOU — Under Maintenance</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f6f8;
            color: #212529;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        main {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 2rem 2rem 1.5rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
        }
        .spinner {
            width: 44px;
            height: 44px;
            margin: 0 auto 1rem;
            border: 4px solid #e9ecef;
            border-top-color: #0d6efd;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) {
            .spinner { animation: none; }
        }
        h1 {
            margin: 0 0 0.5rem;
            font-size: 1.4rem;
            color: #212529;
        }
        p {
            margin: 0.5rem 0;
            color: #6c757d;
            line-height: 1.5;
            font-size: 0.95rem;
        }
        .meta {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
            font-size: 0.8rem;
            color: #adb5bd;
        }
    </style>
</head>
<body>
    <main role="main" aria-labelledby="title">
        <div class="spinner" aria-hidden="true"></div>
        <h1 id="title">Under Maintenance</h1>
        <p>Your eIOU node is starting up or going through an update.</p>
        <p>Check back in a little bit &mdash; this page refreshes automatically.</p>
        <p class="meta">Auto-refresh every 15 seconds.</p>
    </main>
</body>
</html>
HTML;
    }
}

if (file_exists('/tmp/eiou_maintenance.lock')) {
    http_response_code(503);
    header('Retry-After: 30');

    if (eiou_maintenance_prefers_html($_SERVER['HTTP_ACCEPT'] ?? '')) {
        header('Content-Type: text/html; charset=utf-8');
        echo eiou_maintenance_html_page();
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'status' => 'maintenance',
            'error' => [
                'message' => 'Node is starting up or upgrading. Please try again shortly.',
                'code' => 'maintenance_mode',
            ],
        ]);
    }
    exit;
}
