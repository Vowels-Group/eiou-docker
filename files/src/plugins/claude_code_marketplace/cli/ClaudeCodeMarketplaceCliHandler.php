<?php
# Copyright 2025

/**
 * Marketplace CLI Handler
 *
 * Command-line interface for marketplace operations
 *
 * Commands:
 * - eiou marketplace list [--category=<cat>] [--sort=<sort>]
 * - eiou marketplace search <query>
 * - eiou marketplace info <plugin-id>
 * - eiou marketplace install <plugin-id>
 * - eiou marketplace uninstall <plugin-id>
 * - eiou marketplace update [plugin-id]
 * - eiou marketplace installed
 * - eiou marketplace updates
 * - eiou marketplace publish <path>
 * - eiou marketplace repo list
 * - eiou marketplace repo add <url> [--name=<name>]
 * - eiou marketplace repo remove <id>
 * - eiou marketplace repo sync [id]
 *
 * @package Plugins\Marketplace\Cli
 */

class ClaudeCodeMarketplaceCliHandler {
    /**
     * @var ClaudeCodeMarketplaceService Marketplace service
     */
    private ClaudeCodeMarketplaceService $service;

    /**
     * @var CliOutputManager Output manager
     */
    private $output;

    /**
     * Constructor
     *
     * @param ClaudeCodeMarketplaceService $service Marketplace service
     */
    public function __construct(ClaudeCodeMarketplaceService $service) {
        $this->service = $service;
    }

    /**
     * Handle a marketplace CLI command
     *
     * @param array $argv Command arguments
     * @param CliOutputManager $output Output manager
     */
    public function handleCommand(array $argv, $output): void {
        $this->output = $output;

        // marketplace <subcommand> [args...]
        $subcommand = $argv[2] ?? 'help';
        $args = array_slice($argv, 3);

        try {
            match ($subcommand) {
                'list' => $this->listPlugins($args),
                'search' => $this->searchPlugins($args),
                'info' => $this->pluginInfo($args),
                'install' => $this->installPlugin($args),
                'uninstall' => $this->uninstallPlugin($args),
                'update' => $this->updatePlugin($args),
                'installed' => $this->listInstalled(),
                'updates' => $this->checkUpdates(),
                'publish' => $this->publishPlugin($args),
                'repo' => $this->handleRepoCommand($args),
                'help', '--help', '-h' => $this->showHelp(),
                default => $this->showHelp()
            };
        } catch (Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }

    /**
     * List available plugins
     *
     * @param array $args Command arguments
     */
    private function listPlugins(array $args): void {
        $filters = $this->parseOptions($args);

        $result = $this->service->listPlugins($filters, 1, 50);

        if (empty($result['plugins'])) {
            $this->output("No plugins found.");
            return;
        }

        $this->output("\n  Claude Code Marketplace - Available Plugins");
        $this->output("  " . str_repeat("=", 50) . "\n");

        foreach ($result['plugins'] as $plugin) {
            $installed = $plugin['installed'] ?? false;
            $status = $installed ? ' [INSTALLED]' : '';

            $this->output(sprintf(
                "  %-30s v%-8s  %s%s",
                $plugin['name'],
                $plugin['version'],
                $this->formatRating($plugin['rating']),
                $status
            ));
            $this->output("    ID: " . $plugin['id']);
            $this->output("    " . $this->truncate($plugin['description'], 60));
            $this->output("    Downloads: " . number_format($plugin['downloads']) . " | Category: " . $plugin['category']);
            $this->output("");
        }

        $pagination = $result['pagination'];
        $this->output("  Showing page {$pagination['page']} of {$pagination['total_pages']} ({$pagination['total']} total plugins)");
    }

    /**
     * Search for plugins
     *
     * @param array $args Command arguments
     */
    private function searchPlugins(array $args): void {
        if (empty($args)) {
            $this->error("Usage: eiou marketplace search <query>");
            return;
        }

        $query = implode(' ', $args);
        $result = $this->service->searchPlugins($query);

        $this->output("\n  Search Results for: \"{$query}\"");
        $this->output("  " . str_repeat("-", 40) . "\n");

        if (empty($result['results'])) {
            $this->output("  No plugins found matching your search.");
            return;
        }

        foreach ($result['results'] as $plugin) {
            $this->output(sprintf(
                "  %-30s v%s",
                $plugin['name'],
                $plugin['version']
            ));
            $this->output("    ID: " . $plugin['id']);
            $this->output("    " . $this->truncate($plugin['description'], 60));
            $this->output("");
        }

        $this->output("  Found {$result['count']} matching plugins.");
    }

    /**
     * Show plugin information
     *
     * @param array $args Command arguments
     */
    private function pluginInfo(array $args): void {
        if (empty($args)) {
            $this->error("Usage: eiou marketplace info <plugin-id>");
            return;
        }

        $pluginId = $args[0];
        $plugin = $this->service->getPlugin($pluginId);

        if (!$plugin) {
            $this->error("Plugin '{$pluginId}' not found.");
            return;
        }

        $this->output("\n  Plugin Information");
        $this->output("  " . str_repeat("=", 50) . "\n");
        $this->output("  Name:        " . $plugin['name']);
        $this->output("  ID:          " . $plugin['id']);
        $this->output("  Version:     " . $plugin['version']);
        $this->output("  Author:      " . ($plugin['author'] ?: 'Unknown'));
        $this->output("  License:     " . $plugin['license']);
        $this->output("  Category:    " . $plugin['category']);
        $this->output("  Downloads:   " . number_format($plugin['downloads']));
        $this->output("  Rating:      " . $this->formatRating($plugin['rating']) . " ({$plugin['rating_count']} reviews)");
        $this->output("  Signed:      " . ($plugin['signed'] ? 'Yes' : 'No'));
        $this->output("");
        $this->output("  Description:");
        $this->output("    " . wordwrap($plugin['description'], 60, "\n    "));

        if (!empty($plugin['tags'])) {
            $this->output("\n  Tags: " . implode(', ', $plugin['tags']));
        }

        if (!empty($plugin['homepage'])) {
            $this->output("  Homepage: " . $plugin['homepage']);
        }

        $this->output("");

        if ($plugin['installed']) {
            $this->output("  Status: INSTALLED (v{$plugin['installed_version']})");
            if ($plugin['has_update']) {
                $this->output("  Update available: v{$plugin['version']}");
            }
        } else {
            $this->output("  Status: Not installed");
            $this->output("\n  To install: eiou marketplace install {$pluginId}");
        }
    }

    /**
     * Install a plugin
     *
     * @param array $args Command arguments
     */
    private function installPlugin(array $args): void {
        if (empty($args)) {
            $this->error("Usage: eiou marketplace install <plugin-id>");
            return;
        }

        $pluginId = $args[0];

        $this->output("Installing {$pluginId}...");

        $result = $this->service->installPlugin($pluginId);

        $this->success($result['message']);
        $this->output("Installed to: " . $result['install_path']);
    }

    /**
     * Uninstall a plugin
     *
     * @param array $args Command arguments
     */
    private function uninstallPlugin(array $args): void {
        if (empty($args)) {
            $this->error("Usage: eiou marketplace uninstall <plugin-id>");
            return;
        }

        $pluginId = $args[0];

        $this->output("Uninstalling {$pluginId}...");

        $result = $this->service->uninstallPlugin($pluginId);

        $this->success($result['message']);
    }

    /**
     * Update plugins
     *
     * @param array $args Command arguments
     */
    private function updatePlugin(array $args): void {
        if (!empty($args)) {
            // Update specific plugin
            $pluginId = $args[0];
            $this->output("Updating {$pluginId}...");

            $result = $this->service->updatePlugin($pluginId);
            $this->success($result['message']);
        } else {
            // Update all plugins with updates
            $updates = $this->service->checkUpdates();

            if ($updates['updates_available'] === 0) {
                $this->output("All plugins are up to date.");
                return;
            }

            $this->output("Found {$updates['updates_available']} updates available.");

            foreach ($updates['updates'] as $update) {
                $this->output("\nUpdating {$update['plugin_id']} from v{$update['installed_version']} to v{$update['available_version']}...");

                try {
                    $result = $this->service->updatePlugin($update['plugin_id']);
                    $this->success($result['message']);
                } catch (Exception $e) {
                    $this->error("Failed: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * List installed plugins
     */
    private function listInstalled(): void {
        $installed = $this->service->getInstalledPlugins();

        if (empty($installed)) {
            $this->output("\n  No plugins installed.");
            $this->output("  Use 'eiou marketplace list' to browse available plugins.");
            return;
        }

        $this->output("\n  Installed Plugins");
        $this->output("  " . str_repeat("=", 50) . "\n");

        foreach ($installed as $plugin) {
            $status = $plugin['is_active'] ? 'Active' : 'Inactive';
            $update = $plugin['has_update'] ? " (Update: v{$plugin['available_version']})" : '';

            $this->output(sprintf(
                "  %-30s v%-8s [%s]%s",
                $plugin['plugin_id'],
                $plugin['installed_version'],
                $status,
                $update
            ));
            $this->output("    Installed: " . $plugin['installed_at']);
            $this->output("");
        }

        $this->output("  Total: " . count($installed) . " installed plugins");
    }

    /**
     * Check for updates
     */
    private function checkUpdates(): void {
        $this->output("Checking for updates...\n");

        $updates = $this->service->checkUpdates();

        if ($updates['updates_available'] === 0) {
            $this->success("All plugins are up to date!");
            return;
        }

        $this->output("  {$updates['updates_available']} update(s) available:\n");

        foreach ($updates['updates'] as $update) {
            $this->output(sprintf(
                "  %-30s %s -> %s",
                $update['name'],
                $update['installed_version'],
                $update['available_version']
            ));
        }

        $this->output("\n  Run 'eiou marketplace update' to update all plugins.");
    }

    /**
     * Publish a plugin
     *
     * @param array $args Command arguments
     */
    private function publishPlugin(array $args): void {
        if (empty($args)) {
            $this->error("Usage: eiou marketplace publish <path-to-plugin>");
            return;
        }

        $path = $args[0];

        if (!is_dir($path)) {
            $this->error("Directory not found: {$path}");
            return;
        }

        $manifestPath = rtrim($path, '/') . '/plugin.json';
        if (!file_exists($manifestPath)) {
            $this->error("No plugin.json found in {$path}");
            return;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest) {
            $this->error("Invalid plugin.json");
            return;
        }

        $this->output("Publishing {$manifest['name']} v{$manifest['version']}...");

        $result = $this->service->publishPlugin($manifest);

        $this->success($result['message']);
    }

    /**
     * Handle repository subcommands
     *
     * @param array $args Command arguments
     */
    private function handleRepoCommand(array $args): void {
        $subcommand = $args[0] ?? 'list';
        $subArgs = array_slice($args, 1);

        match ($subcommand) {
            'list' => $this->listRepositories(),
            'add' => $this->addRepository($subArgs),
            'remove' => $this->removeRepository($subArgs),
            'sync' => $this->syncRepositories($subArgs),
            default => $this->error("Unknown repo command: {$subcommand}")
        };
    }

    /**
     * List repositories
     */
    private function listRepositories(): void {
        $repositories = $this->service->getRepositories();

        if (empty($repositories)) {
            $this->output("\n  No repositories configured.");
            return;
        }

        $this->output("\n  Configured Repositories");
        $this->output("  " . str_repeat("=", 50) . "\n");

        foreach ($repositories as $repo) {
            $status = $repo['is_enabled'] ? 'Enabled' : 'Disabled';
            $official = $repo['is_official'] ? ' [Official]' : '';

            $this->output(sprintf(
                "  [%d] %s%s (%s)",
                $repo['id'],
                $repo['name'],
                $official,
                $status
            ));
            $this->output("      URL: " . $repo['url']);
            $this->output("      Plugins: " . $repo['plugin_count'] . " | Last sync: " . ($repo['last_sync'] ?: 'Never'));
            $this->output("");
        }
    }

    /**
     * Add a repository
     *
     * @param array $args Command arguments
     */
    private function addRepository(array $args): void {
        if (empty($args)) {
            $this->error("Usage: eiou marketplace repo add <url> [--name=<name>]");
            return;
        }

        $url = $args[0];
        $options = $this->parseOptions(array_slice($args, 1));
        $name = $options['name'] ?? parse_url($url, PHP_URL_HOST);

        $this->output("Adding repository {$name}...");

        $result = $this->service->addRepository($url, $name);

        $this->success($result['message']);
    }

    /**
     * Remove a repository
     *
     * @param array $args Command arguments
     */
    private function removeRepository(array $args): void {
        if (empty($args)) {
            $this->error("Usage: eiou marketplace repo remove <id>");
            return;
        }

        $id = (int) $args[0];

        $this->output("Removing repository...");

        $result = $this->service->removeRepository($id);

        $this->success($result['message']);
    }

    /**
     * Sync repositories
     *
     * @param array $args Command arguments
     */
    private function syncRepositories(array $args): void {
        $repositoryId = !empty($args) ? (int) $args[0] : null;

        $this->output("Syncing repositories...\n");

        $result = $this->service->syncRepository($repositoryId);

        foreach ($result['results'] as $repoResult) {
            if ($repoResult['success']) {
                $this->success("  {$repoResult['repository']}: {$repoResult['plugins_synced']} plugins synced");
            } else {
                $this->error("  {$repoResult['repository']}: {$repoResult['error']}");
            }
        }

        $this->output("\n  Total plugins synced: {$result['total_synced']}");
    }

    /**
     * Show help message
     */
    private function showHelp(): void {
        $this->output("
  Claude Code Marketplace CLI
  " . str_repeat("=", 50) . "

  Usage: eiou marketplace <command> [options]

  Plugin Commands:
    list                        List available plugins
      --category=<cat>          Filter by category
      --sort=<field>            Sort by: downloads, rating, name, updated
    search <query>              Search for plugins
    info <plugin-id>            Show plugin details
    install <plugin-id>         Install a plugin
    uninstall <plugin-id>       Uninstall a plugin
    update [plugin-id]          Update plugin(s)
    installed                   List installed plugins
    updates                     Check for available updates

  Publishing Commands:
    publish <path>              Publish a plugin from local directory

  Repository Commands:
    repo list                   List configured repositories
    repo add <url>              Add a plugin repository
      --name=<name>             Repository name
    repo remove <id>            Remove a repository
    repo sync [id]              Sync repository plugin lists

  Examples:
    eiou marketplace list --category=tools
    eiou marketplace search \"code formatter\"
    eiou marketplace install my-awesome-plugin
    eiou marketplace repo add https://plugins.example.com/api
");
    }

    // ==================== Helper Methods ====================

    /**
     * Parse command line options
     *
     * @param array $args Arguments
     * @return array Parsed options
     */
    private function parseOptions(array $args): array {
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([^=]+)=(.*)$/', $arg, $matches)) {
                $options[$matches[1]] = $matches[2];
            } elseif (preg_match('/^--([^=]+)$/', $arg, $matches)) {
                $options[$matches[1]] = true;
            }
        }
        return $options;
    }

    /**
     * Format rating as stars
     *
     * @param float $rating Rating value
     * @return string Formatted rating
     */
    private function formatRating(float $rating): string {
        $full = (int) $rating;
        $half = ($rating - $full) >= 0.5 ? 1 : 0;
        $empty = 5 - $full - $half;

        return str_repeat('*', $full) . ($half ? '+' : '') . str_repeat('-', $empty) .
               " ({$rating})";
    }

    /**
     * Truncate string
     *
     * @param string $text Text to truncate
     * @param int $length Max length
     * @return string Truncated text
     */
    private function truncate(string $text, int $length): string {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length - 3) . '...';
    }

    /**
     * Output a message
     *
     * @param string $message Message to output
     */
    private function output(string $message): void {
        echo $message . "\n";
    }

    /**
     * Output a success message
     *
     * @param string $message Message to output
     */
    private function success(string $message): void {
        echo "  [OK] " . $message . "\n";
    }

    /**
     * Output an error message
     *
     * @param string $message Message to output
     */
    private function error(string $message): void {
        echo "  [ERROR] " . $message . "\n";
    }
}
