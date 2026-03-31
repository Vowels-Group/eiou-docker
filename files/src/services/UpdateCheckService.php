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
}
