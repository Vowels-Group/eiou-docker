<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Database;

use PDO;

/**
 * Plugin Credential Repository
 *
 * Stores the encrypted MySQL password for each plugin's isolated DB user.
 * Encryption/decryption is the caller's responsibility (handled in
 * PluginCredentialService via KeyEncryption) — this repository stays a
 * thin PDO wrapper around the `plugin_credentials` table.
 *
 * See docs/PLUGINS.md (Database Isolation).
 */
class PluginCredentialRepository extends AbstractRepository
{
    protected $tableName = 'plugin_credentials';
    protected array $allowedColumns = [
        'plugin_id', 'encrypted_password', 'created_at', 'rotated_at',
    ];

    /**
     * Insert a new credential row for a plugin.
     *
     * @param string $pluginId Matches the plugin's manifest `name` field.
     * @param string $encryptedJson KeyEncryption envelope serialized as JSON.
     * @return bool True on success.
     */
    public function createCredential(string $pluginId, string $encryptedJson): bool
    {
        return (bool) $this->insert([
            'plugin_id' => $pluginId,
            'encrypted_password' => $encryptedJson,
        ]);
    }

    /**
     * Fetch a single plugin's credential row.
     *
     * @return array|null Full row, or null if the plugin has no credentials yet.
     */
    public function getByPluginId(string $pluginId): ?array
    {
        return $this->findByColumn('plugin_id', $pluginId);
    }

    /**
     * Check whether credentials exist for this plugin.
     */
    public function existsForPlugin(string $pluginId): bool
    {
        return $this->exists('plugin_id', $pluginId);
    }

    /**
     * Replace the encrypted password on an existing row and stamp rotated_at.
     *
     * @return int Affected row count (0 if plugin has no existing row).
     */
    public function rotatePassword(string $pluginId, string $encryptedJson): int
    {
        $sql = "UPDATE {$this->tableName}
                SET encrypted_password = :pw,
                    rotated_at = CURRENT_TIMESTAMP(6)
                WHERE plugin_id = :id";
        $stmt = $this->execute($sql, [
            ':pw' => $encryptedJson,
            ':id' => $pluginId,
        ]);
        return $stmt ? $stmt->rowCount() : 0;
    }

    /**
     * Remove a plugin's credential row entirely — used at uninstall, after the
     * MySQL user has been dropped. Returns affected row count (0 if absent).
     */
    public function deleteCredential(string $pluginId): int
    {
        return $this->delete('plugin_id', $pluginId);
    }

    /**
     * List every plugin that currently has credentials stored. Used by the
     * boot-time reconciler to replay CREATE USER / GRANT
     * for every enabled plugin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->execute("SELECT * FROM {$this->tableName}", []);
        if (!$stmt) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
