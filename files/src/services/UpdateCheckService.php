<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Services;

use Eiou\Core\Constants;
use Eiou\Utils\Logger;

/**
 * Update Check Service
 *
 * Periodically checks Docker Hub for newer image tags and caches the result.
 * The check is non-blocking, cached for 24 hours, and respects the user's
 * updateCheckEnabled setting. Tor-only nodes silently skip the check since
 * they cannot reach Docker Hub directly.
 */
class UpdateCheckService
{
    /**
     * Docker Hub API endpoint for tag listing
     */
    private const DOCKER_HUB_TAGS_URL = 'https://hub.docker.com/v2/repositories/eiou/eiou/tags/';

    /**
     * GitHub Releases API endpoint (fallback).
     * Uses /releases (not /releases/latest) because /latest excludes pre-releases.
     */
    private const GITHUB_RELEASES_URL = 'https://api.github.com/repos/Vowels-Group/eiou-docker/releases?per_page=10';

    /**
     * Cache file location (persistent volume)
     */
    private const CACHE_FILE = '/etc/eiou/config/update-check.json';

    /**
     * Tracks which version's "What's New" the user has seen
     */
    private const WHATS_NEW_SEEN_FILE = '/etc/eiou/config/whats-new-seen.json';

    /**
     * Marker that the node has completed initial setup. Used to tell a
     * brand-new/pre-setup container apart from a set-up node that just
     * upgraded (the latter should see the banner).
     */
    private const USER_CONFIG_FILE = '/etc/eiou/config/userconfig.json';

    /**
     * Cached release notes from GitHub
     */
    private const RELEASE_NOTES_CACHE_FILE = '/etc/eiou/config/release-notes-cache.json';

    /**
     * Default cache TTL in seconds (24 hours)
     */
    private const DEFAULT_TTL = 86400;

    /**
     * HTTP request timeout in seconds
     */
    private const REQUEST_TIMEOUT = 10;

    /**
     * Check for available updates.
     *
     * Fetches the latest tag from Docker Hub, compares with the running
     * version, and caches the result. Returns the cached result if still
     * valid. Returns null if the check is disabled or fails.
     *
     * @param bool $forceRefresh Bypass cache and check now
     * @return array|null Update check result or null on failure
     */
    public static function check(bool $forceRefresh = false): ?array
    {
        // Return cached result if still valid
        if (!$forceRefresh) {
            $cached = self::getCached();
            if ($cached !== null) {
                return $cached;
            }
        }

        // Try Docker Hub first (180 req/hr unauthenticated)
        $latest = self::checkDockerHub();
        $source = 'docker-hub';

        // Fallback to GitHub Releases
        if ($latest === null) {
            $latest = self::checkGitHub();
            $source = 'github';
        }

        if ($latest === null) {
            // Both sources failed — cache the failure briefly (1 hour) to avoid hammering
            $result = [
                'available' => false,
                'current_version' => Constants::APP_VERSION,
                'latest_version' => null,
                'last_checked' => date('c'),
                'source' => null,
                'error' => 'Could not reach update servers',
            ];
            self::writeCache($result, 3600);
            return $result;
        }

        $isNewer = self::isNewerVersion($latest, Constants::APP_VERSION);

        $result = [
            'available' => $isNewer,
            'current_version' => Constants::APP_VERSION,
            'latest_version' => $latest,
            'last_checked' => date('c'),
            'source' => $source,
            'error' => null,
        ];

        self::writeCache($result, self::DEFAULT_TTL);
        return $result;
    }

    /**
     * Get cached update check result if still valid.
     *
     * @return array|null Cached result or null if expired/missing
     */
    public static function getCached(): ?array
    {
        if (!file_exists(self::CACHE_FILE)) {
            return null;
        }

        $raw = file_get_contents(self::CACHE_FILE);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['expires_at'])) {
            return null;
        }

        // Check if cache has expired
        if (time() > $data['expires_at']) {
            return null;
        }

        unset($data['expires_at']);
        return $data;
    }

    /**
     * Query Docker Hub for the latest tag.
     *
     * @return string|null Latest version string or null on failure
     */
    private static function checkDockerHub(): ?string
    {
        $url = self::DOCKER_HUB_TAGS_URL . '?page_size=25&ordering=-last_updated';

        $response = self::httpGet($url);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['results'])) {
            return null;
        }

        // Collect all version tags, then return the highest by semver.
        // We can't rely on push order alone — a re-pushed older tag would
        // sort first by last_updated.
        // Only consider tags that look like version numbers (e.g., 0.1.5, v0.1.5-alpha, 1.0.0-beta).
        $highest = null;
        foreach ($data['results'] as $tag) {
            $name = $tag['name'] ?? '';
            $version = ltrim($name, 'v');

            // Skip non-version tags (latest, nightly, test, sha hashes, etc.)
            if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $version)) {
                continue;
            }

            if ($highest === null || version_compare($version, $highest, '>')) {
                $highest = $version;
            }
        }

        return $highest;
    }

    /**
     * Query GitHub Releases for the latest release (including pre-releases).
     *
     * @return string|null Latest version string or null on failure
     */
    private static function checkGitHub(): ?string
    {
        $response = self::httpGet(self::GITHUB_RELEASES_URL);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || empty($data)) {
            return null;
        }

        // Pick the highest semver from the releases list
        $highest = null;
        foreach ($data as $release) {
            if (!isset($release['tag_name']) || ($release['draft'] ?? false)) {
                continue;
            }
            $version = ltrim($release['tag_name'], 'v');
            if (!preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $version)) {
                continue;
            }
            if ($highest === null || version_compare($version, $highest, '>')) {
                $highest = $version;
            }
        }

        return $highest;
    }

    /**
     * Compare two version strings.
     *
     * Handles prerelease suffixes like -alpha, -beta correctly:
     * 0.1.5-alpha < 0.1.5-beta < 0.1.5 < 0.2.0
     *
     * @param string $latest The latest available version
     * @param string $current The currently running version
     * @return bool True if $latest is newer than $current
     */
    public static function isNewerVersion(string $latest, string $current): bool
    {
        $latest = ltrim($latest, 'v');
        $current = ltrim($current, 'v');

        if ($latest === $current) {
            return false;
        }

        return version_compare($latest, $current, '>');
    }

    /**
     * Perform an HTTP GET request with curl.
     *
     * @param string $url The URL to fetch
     * @return string|null Response body or null on failure
     */
    private static function httpGet(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'eiou-node/' . Constants::APP_VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // Don't verify SSL in case of restricted environments
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            Logger::getInstance()->info("Update check failed", [
                'url' => $url,
                'http_code' => $httpCode,
                'error' => $error ?: 'HTTP ' . $httpCode,
            ]);
            return null;
        }

        return $response;
    }

    /**
     * Write result to cache file.
     *
     * @param array $result The update check result
     * @param int $ttl Cache TTL in seconds
     */
    private static function writeCache(array $result, int $ttl): void
    {
        $result['expires_at'] = time() + $ttl;

        $oldUmask = umask(0027);
        file_put_contents(self::CACHE_FILE, json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
        umask($oldUmask);

        chmod(self::CACHE_FILE, 0640);
        if (posix_getuid() === 0) {
            chgrp(self::CACHE_FILE, 'www-data');
        }
    }

    /**
     * Get status for diagnostics / API.
     *
     * @return array Status information (never triggers a new check)
     */
    public static function getStatus(): array
    {
        $cached = self::getCached();
        if ($cached !== null) {
            return $cached;
        }

        return [
            'available' => false,
            'current_version' => Constants::APP_VERSION,
            'latest_version' => null,
            'last_checked' => null,
            'source' => null,
            'error' => null,
        ];
    }

    /**
     * Check if the "What's New" notification should be shown.
     *
     * The seen-file didn't exist in versions prior to the one that
     * introduced this feature, so its absence alone can't be treated as
     * "fresh install". Gate on userconfig.json — if the node has completed
     * setup, show the banner (this covers both first upgrade to a feature-
     * bearing version, and freshly set-up nodes who also benefit from
     * seeing what's in their version). Suppress only for pre-setup
     * containers where userconfig.json doesn't yet exist.
     *
     * @return bool True if the current version has unseen release notes
     */
    public static function shouldShowWhatsNew(): bool
    {
        if (!file_exists(self::WHATS_NEW_SEEN_FILE)) {
            return file_exists(self::USER_CONFIG_FILE);
        }

        $raw = file_get_contents(self::WHATS_NEW_SEEN_FILE);
        if ($raw === false) {
            return false;
        }

        $data = json_decode($raw, true);
        return ($data['dismissed_version'] ?? '') !== Constants::APP_VERSION;
    }

    /**
     * Mark the current version's "What's New" as dismissed.
     */
    public static function dismissWhatsNew(): void
    {
        $data = [
            'dismissed_version' => Constants::APP_VERSION,
            'dismissed_at' => date('c'),
        ];

        $dir = dirname(self::WHATS_NEW_SEEN_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $oldUmask = umask(0027);
        file_put_contents(self::WHATS_NEW_SEEN_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        umask($oldUmask);

        @chmod(self::WHATS_NEW_SEEN_FILE, 0640);
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            @chgrp(self::WHATS_NEW_SEEN_FILE, 'www-data');
        }
    }

    /**
     * Fetch release notes for a version from GitHub Releases.
     *
     * Results are cached locally so repeated clicks don't hit the API.
     *
     * @param string $version Version string (without leading 'v')
     * @return array|null ['version', 'name', 'body_html', 'published_at', 'html_url'] or null on failure
     */
    public static function getReleaseNotes(string $version): ?array
    {
        $version = ltrim($version, 'v');

        // Check cache
        if (file_exists(self::RELEASE_NOTES_CACHE_FILE)) {
            $raw = file_get_contents(self::RELEASE_NOTES_CACHE_FILE);
            if ($raw !== false) {
                $cached = json_decode($raw, true);
                if (is_array($cached) && ($cached['version'] ?? '') === $version && !empty($cached['body_html'])) {
                    return $cached;
                }
            }
        }

        // Fetch from GitHub Releases API (try exact tag first)
        $url = 'https://api.github.com/repos/Vowels-Group/eiou-docker/releases/tags/v' . $version;
        $response = self::httpGet($url);

        $release = null;
        if ($response !== null) {
            $data = json_decode($response, true);
            if (is_array($data) && !empty($data['body'])) {
                $release = $data;
            }
        }

        // If exact tag not found, search through recent releases
        if ($release === null) {
            $response = self::httpGet(self::GITHUB_RELEASES_URL);
            if ($response !== null) {
                $releases = json_decode($response, true);
                if (is_array($releases)) {
                    foreach ($releases as $r) {
                        $tagVersion = ltrim($r['tag_name'] ?? '', 'v');
                        if ($tagVersion === $version && !empty($r['body'])) {
                            $release = $r;
                            break;
                        }
                    }
                }
            }
        }

        if ($release === null) {
            return null;
        }

        $result = [
            'version' => $version,
            'name' => $release['name'] ?? ('v' . $version),
            'body_html' => self::markdownToHtml($release['body']),
            'published_at' => $release['published_at'] ?? null,
            'html_url' => $release['html_url'] ?? null,
        ];

        // Cache the result
        $oldUmask = umask(0027);
        file_put_contents(self::RELEASE_NOTES_CACHE_FILE, json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
        umask($oldUmask);

        @chmod(self::RELEASE_NOTES_CACHE_FILE, 0640);
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            @chgrp(self::RELEASE_NOTES_CACHE_FILE, 'www-data');
        }

        return $result;
    }

    /**
     * Convert basic Markdown to HTML for release notes display.
     *
     * Handles: headings, bold, italic, inline code, code blocks, links,
     * unordered/ordered lists, horizontal rules, and paragraphs.
     * All text content is escaped for XSS safety.
     *
     * @param string $markdown Raw markdown text
     * @return string Sanitized HTML
     */
    public static function markdownToHtml(string $markdown): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $html = '';
        $inList = false;
        $listType = '';
        $inCodeBlock = false;
        $codeContent = '';

        foreach ($lines as $line) {
            // Fenced code blocks
            if (preg_match('/^```/', $line)) {
                if ($inCodeBlock) {
                    $html .= '<pre><code>' . htmlspecialchars($codeContent) . '</code></pre>';
                    $codeContent = '';
                    $inCodeBlock = false;
                } else {
                    if ($inList) {
                        $html .= ($listType === 'ul') ? '</ul>' : '</ol>';
                        $inList = false;
                    }
                    $inCodeBlock = true;
                }
                continue;
            }

            if ($inCodeBlock) {
                $codeContent .= $line . "\n";
                continue;
            }

            $trimmed = trim($line);

            // Empty line — close list if open
            if ($trimmed === '') {
                if ($inList) {
                    $html .= ($listType === 'ul') ? '</ul>' : '</ol>';
                    $inList = false;
                }
                continue;
            }

            // Horizontal rule
            if (preg_match('/^[-*_]{3,}$/', $trimmed)) {
                if ($inList) {
                    $html .= ($listType === 'ul') ? '</ul>' : '</ol>';
                    $inList = false;
                }
                $html .= '<hr>';
                continue;
            }

            // Headings
            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
                if ($inList) {
                    $html .= ($listType === 'ul') ? '</ul>' : '</ol>';
                    $inList = false;
                }
                $level = strlen($m[1]);
                $html .= '<h' . $level . '>' . self::inlineMarkdown(htmlspecialchars($m[2])) . '</h' . $level . '>';
                continue;
            }

            // Unordered list item
            if (preg_match('/^[-*+]\s+(.+)$/', $trimmed, $m)) {
                if (!$inList || $listType !== 'ul') {
                    if ($inList) {
                        $html .= ($listType === 'ul') ? '</ul>' : '</ol>';
                    }
                    $html .= '<ul>';
                    $inList = true;
                    $listType = 'ul';
                }
                $html .= '<li>' . self::inlineMarkdown(htmlspecialchars($m[1])) . '</li>';
                continue;
            }

            // Ordered list item
            if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
                if (!$inList || $listType !== 'ol') {
                    if ($inList) {
                        $html .= ($listType === 'ul') ? '</ul>' : '</ol>';
                    }
                    $html .= '<ol>';
                    $inList = true;
                    $listType = 'ol';
                }
                $html .= '<li>' . self::inlineMarkdown(htmlspecialchars($m[1])) . '</li>';
                continue;
            }

            // Paragraph
            if ($inList) {
                $html .= ($listType === 'ul') ? '</ul>' : '</ol>';
                $inList = false;
            }
            $html .= '<p>' . self::inlineMarkdown(htmlspecialchars($trimmed)) . '</p>';
        }

        // Close any open list
        if ($inList) {
            $html .= ($listType === 'ul') ? '</ul>' : '</ol>';
        }

        // Close unclosed code block
        if ($inCodeBlock) {
            $html .= '<pre><code>' . htmlspecialchars($codeContent) . '</code></pre>';
        }

        return $html;
    }

    /**
     * Apply inline Markdown formatting to already-escaped HTML text.
     *
     * @param string $text HTML-escaped text
     * @return string Text with inline formatting applied
     */
    private static function inlineMarkdown(string $text): string
    {
        // Inline code (before other patterns so backtick content is protected)
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        // Bold
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        // Italic
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        // Links — [text](url) — the URL was escaped, so unescape &amp; for href
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ($m) {
                $linkText = $m[1];
                $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
                // Only allow http/https URLs
                if (!preg_match('#^https?://#', $url)) {
                    return $linkText;
                }
                return '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer">' . $linkText . '</a>';
            },
            $text
        );
        return $text;
    }
}
