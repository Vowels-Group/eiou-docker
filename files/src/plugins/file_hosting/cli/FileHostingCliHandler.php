<?php
# Copyright 2025

/**
 * File Hosting CLI Handler
 *
 * Command-line interface for file hosting operations
 *
 * Commands:
 * - eiou file-hosting upload <path> [--days=<days>] [--public] [--password=<pwd>]
 * - eiou file-hosting list [--expired]
 * - eiou file-hosting info <file-id>
 * - eiou file-hosting download <file-id> [--output=<path>] [--password=<pwd>]
 * - eiou file-hosting delete <file-id>
 * - eiou file-hosting extend <file-id> --days=<days>
 * - eiou file-hosting storage
 * - eiou file-hosting pricing
 * - eiou file-hosting payments [--limit=<n>]
 * - eiou file-hosting cleanup (admin)
 * - eiou file-hosting stats (admin)
 *
 * @package Plugins\FileHosting\Cli
 */

class FileHostingCliHandler {
    /**
     * @var FileHostingService File hosting service
     */
    private FileHostingService $service;

    /**
     * @var CliOutputManager Output manager
     */
    private $output;

    /**
     * Constructor
     *
     * @param FileHostingService $service File hosting service
     */
    public function __construct(FileHostingService $service) {
        $this->service = $service;
    }

    /**
     * Handle a file hosting CLI command
     *
     * @param array $argv Command arguments
     * @param CliOutputManager|null $output Output manager
     * @return int Exit code
     */
    public function handle(array $argv, $output = null): int {
        $this->output = $output ?? new CliOutputManager();

        if (count($argv) < 2) {
            $this->showHelp();
            return 1;
        }

        $subcommand = $argv[1] ?? '';
        $args = array_slice($argv, 2);
        $options = $this->parseOptions($args);

        try {
            return match ($subcommand) {
                'upload' => $this->upload($args, $options),
                'list', 'ls' => $this->listFiles($options),
                'info' => $this->info($args),
                'download', 'get' => $this->download($args, $options),
                'delete', 'rm' => $this->delete($args),
                'extend' => $this->extend($args, $options),
                'storage' => $this->storage(),
                'pricing' => $this->pricing(),
                'payments' => $this->payments($options),
                'cleanup' => $this->cleanup(),
                'stats' => $this->stats(),
                'help', '--help', '-h' => $this->showHelp(),
                default => $this->unknownCommand($subcommand)
            };
        } catch (Exception $e) {
            $this->output->error($e->getMessage());
            return 1;
        }
    }

    /**
     * Upload a file
     */
    private function upload(array $args, array $options): int {
        $filePath = $args[0] ?? null;
        if (!$filePath) {
            $this->output->error('File path required');
            return 1;
        }

        if (!file_exists($filePath)) {
            $this->output->error("File not found: {$filePath}");
            return 1;
        }

        $days = (int) ($options['days'] ?? 30);
        $isPublic = isset($options['public']);
        $password = $options['password'] ?? null;
        $description = $options['description'] ?? null;

        // Simulate file upload array
        $fileData = [
            'name' => basename($filePath),
            'type' => mime_content_type($filePath) ?: 'application/octet-stream',
            'size' => filesize($filePath),
            'tmp_name' => $filePath
        ];

        // For CLI, we need to copy to temp and use move_uploaded_file workaround
        $tempPath = sys_get_temp_dir() . '/' . uniqid('upload_');
        copy($filePath, $tempPath);
        $fileData['tmp_name'] = $tempPath;

        $this->output->info("Uploading {$fileData['name']} ({$this->formatBytes($fileData['size'])})...");

        // Calculate cost first
        $cost = $this->service->calculateCost($fileData['size'], $days);
        $this->output->info("Storage cost: {$cost['total_cost']} eIOU for {$days} days");

        // Confirm if cost > 0
        if ($cost['total_cost'] > 0) {
            $this->output->warning("This will charge {$cost['total_cost']} eIOUs from your balance.");
            // In production, add confirmation prompt
        }

        try {
            $result = $this->service->uploadFile($fileData, $days, $isPublic, $password, $description);

            $this->output->success("File uploaded successfully!");
            $this->output->info("File ID: {$result['file']['file_id']}");
            $this->output->info("Expires: {$result['file']['expires_at']}");

            if ($result['is_free']) {
                $this->output->info("Cost: FREE (within free tier)");
            } else {
                $this->output->info("Cost: {$result['cost']} eIOU");
            }

            if ($isPublic) {
                $this->output->info("Download URL: /api/v1/file-hosting/download/{$result['file']['file_id']}");
            }

            return 0;
        } finally {
            // Clean up temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * List user's files
     */
    private function listFiles(array $options): int {
        $includeExpired = isset($options['expired']);

        $result = $this->service->listUserFiles($includeExpired);

        if (empty($result['files'])) {
            $this->output->info($includeExpired ? 'No files found.' : 'No active files found.');
            return 0;
        }

        $this->output->info("Files ({$result['total']} total):\n");

        // Table header
        $this->output->writeLine(str_pad('FILE ID', 38) . str_pad('FILENAME', 30) . str_pad('SIZE', 12) . str_pad('EXPIRES', 20) . 'STATUS');
        $this->output->writeLine(str_repeat('-', 110));

        foreach ($result['files'] as $file) {
            $status = $file['is_expired'] ? 'EXPIRED' : "{$file['days_remaining']}d left";
            $filename = strlen($file['filename']) > 28 ? substr($file['filename'], 0, 25) . '...' : $file['filename'];

            $this->output->writeLine(
                str_pad($file['file_id'], 38) .
                str_pad($filename, 30) .
                str_pad($file['size_human'], 12) .
                str_pad(substr($file['expires_at'], 0, 19), 20) .
                $status
            );
        }

        $this->output->writeLine('');
        $this->output->info("Page {$result['page']} of {$result['total_pages']}");

        return 0;
    }

    /**
     * Show file information
     */
    private function info(array $args): int {
        $fileId = $args[0] ?? null;
        if (!$fileId) {
            $this->output->error('File ID required');
            return 1;
        }

        $file = $this->service->getFileInfo($fileId);
        if (!$file) {
            $this->output->error('File not found');
            return 1;
        }

        $this->output->info("File Information:\n");
        $this->output->writeLine("  File ID:     {$file['file_id']}");
        $this->output->writeLine("  Filename:    {$file['filename']}");
        $this->output->writeLine("  Size:        {$file['size_human']} ({$file['size_bytes']} bytes)");
        $this->output->writeLine("  MIME Type:   {$file['mime_type']}");
        $this->output->writeLine("  Public:      " . ($file['is_public'] ? 'Yes' : 'No'));
        $this->output->writeLine("  Password:    " . ($file['has_password'] ? 'Yes' : 'No'));
        $this->output->writeLine("  Downloads:   {$file['download_count']}");
        $this->output->writeLine("  Created:     {$file['created_at']}");
        $this->output->writeLine("  Expires:     {$file['expires_at']}");

        if (isset($file['days_remaining'])) {
            $this->output->writeLine("  Days Left:   {$file['days_remaining']}");
        }

        if ($file['is_expired']) {
            $this->output->warning("\n  STATUS: EXPIRED");
        }

        if (!empty($file['description'])) {
            $this->output->writeLine("\n  Description: {$file['description']}");
        }

        return 0;
    }

    /**
     * Download a file
     */
    private function download(array $args, array $options): int {
        $fileId = $args[0] ?? null;
        if (!$fileId) {
            $this->output->error('File ID required');
            return 1;
        }

        $password = $options['password'] ?? null;
        $outputPath = $options['output'] ?? null;

        $this->output->info("Downloading file {$fileId}...");

        try {
            $result = $this->service->downloadFile($fileId, $password);

            $targetPath = $outputPath ?? './' . $result['filename'];

            // Copy file to output location
            if (!copy($result['path'], $targetPath)) {
                throw new Exception("Failed to save file to {$targetPath}");
            }

            // Clean up temp file if needed
            if ($result['is_temp'] && file_exists($result['path'])) {
                unlink($result['path']);
            }

            $this->output->success("File downloaded: {$targetPath}");
            $this->output->info("Size: " . $this->formatBytes($result['size']));

            return 0;
        } catch (Exception $e) {
            $this->output->error("Download failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Delete a file
     */
    private function delete(array $args): int {
        $fileId = $args[0] ?? null;
        if (!$fileId) {
            $this->output->error('File ID required');
            return 1;
        }

        // Get file info first
        $file = $this->service->getFileInfo($fileId);
        if (!$file) {
            $this->output->error('File not found');
            return 1;
        }

        $this->output->warning("Deleting file: {$file['filename']}");
        // In production, add confirmation prompt

        $result = $this->service->deleteFile($fileId);

        $this->output->success("File deleted successfully!");
        $this->output->info("Freed storage: " . $this->formatBytes($result['freed_bytes']));

        return 0;
    }

    /**
     * Extend file storage
     */
    private function extend(array $args, array $options): int {
        $fileId = $args[0] ?? null;
        if (!$fileId) {
            $this->output->error('File ID required');
            return 1;
        }

        $days = (int) ($options['days'] ?? 0);
        if ($days <= 0) {
            $this->output->error('--days parameter required');
            return 1;
        }

        // Get file info first
        $file = $this->service->getFileInfo($fileId);
        if (!$file) {
            $this->output->error('File not found');
            return 1;
        }

        // Calculate cost
        $cost = $this->service->calculateCost($file['size_bytes'], $days);
        $this->output->info("Extension cost: {$cost['total_cost']} eIOU for {$days} additional days");

        // In production, add confirmation prompt

        $result = $this->service->extendStorage($fileId, $days);

        $this->output->success("Storage extended successfully!");
        $this->output->info("New expiration: {$result['file']['expires_at']}");
        $this->output->info("Cost: {$result['cost']} eIOU");

        return 0;
    }

    /**
     * Show storage usage
     */
    private function storage(): int {
        $storage = $this->service->getStorageInfo();

        $this->output->info("Storage Usage:\n");
        $this->output->writeLine("  Plan:        {$storage['plan_type']}");
        $this->output->writeLine("  Quota:       {$storage['quota_human']}");
        $this->output->writeLine("  Used:        {$storage['used_human']} ({$storage['usage_percentage']}%)");
        $this->output->writeLine("  Available:   {$storage['available_human']}");
        $this->output->writeLine("  Files:       {$storage['file_count']}");
        $this->output->writeLine("  Total Spent: {$storage['total_spent']} eIOU");

        // Progress bar
        $barWidth = 40;
        $filled = (int) ($storage['usage_percentage'] / 100 * $barWidth);
        $empty = $barWidth - $filled;
        $bar = str_repeat('=', $filled) . str_repeat('-', $empty);
        $this->output->writeLine("\n  [{$bar}] {$storage['usage_percentage']}%");

        return 0;
    }

    /**
     * Show pricing information
     */
    private function pricing(): int {
        $pricing = $this->service->getPricingInfo();

        $this->output->info("File Hosting Pricing:\n");
        $this->output->writeLine("  Price per MB/day:     {$pricing['price_per_mb_per_day']} eIOU");
        $this->output->writeLine("  Price per GB/day:     {$pricing['price_per_gb_per_day']} eIOU");
        $this->output->writeLine("  Price per GB/month:   {$pricing['price_per_gb_per_month']} eIOU");
        $this->output->writeLine("  Free storage:         {$pricing['free_storage_mb']} MB");
        $this->output->writeLine("  Max file size:        {$pricing['max_file_size_mb']} MB");
        $this->output->writeLine("  Min storage days:     {$pricing['min_storage_days']}");
        $this->output->writeLine("  Max storage days:     {$pricing['max_storage_days']}");

        $this->output->info("\nExample costs:");
        $this->output->writeLine("  100 MB for 30 days:   " . ($pricing['price_per_mb_per_day'] * 100 * 30) . " eIOU");
        $this->output->writeLine("  1 GB for 30 days:     " . ($pricing['price_per_gb_per_day'] * 30) . " eIOU");

        return 0;
    }

    /**
     * Show payment history
     */
    private function payments(array $options): int {
        $limit = (int) ($options['limit'] ?? 20);

        $result = $this->service->getPaymentHistory($limit);

        if (empty($result['payments'])) {
            $this->output->info('No payment history found.');
            return 0;
        }

        $this->output->info("Payment History (Total spent: {$result['total_spent']} eIOU):\n");

        // Table header
        $this->output->writeLine(str_pad('DATE', 20) . str_pad('TYPE', 15) . str_pad('AMOUNT', 12) . str_pad('FILE', 38) . 'STATUS');
        $this->output->writeLine(str_repeat('-', 100));

        foreach ($result['payments'] as $payment) {
            $this->output->writeLine(
                str_pad(substr($payment['created_at'], 0, 19), 20) .
                str_pad($payment['payment_type'], 15) .
                str_pad($payment['amount'] . ' eIOU', 12) .
                str_pad($payment['file_id'] ?? '-', 38) .
                $payment['status']
            );
        }

        return 0;
    }

    /**
     * Cleanup expired files (admin)
     */
    private function cleanup(): int {
        $this->output->info('Cleaning up expired files...');

        $result = $this->service->cleanupExpiredFiles();

        $this->output->success("Cleanup complete!");
        $this->output->info("Deleted: {$result['deleted_count']} files");
        $this->output->info("Freed: " . $this->formatBytes($result['freed_bytes']));

        return 0;
    }

    /**
     * Show node statistics (admin)
     */
    private function stats(): int {
        $stats = $this->service->getNodeStatistics();

        $this->output->info("Node Statistics:\n");
        $this->output->writeLine("  Total files:     {$stats['total_files']}");
        $this->output->writeLine("  Total storage:   " . $this->formatBytes($stats['total_storage_bytes']));
        $this->output->writeLine("  Total downloads: {$stats['total_downloads']}");
        $this->output->writeLine("  Total revenue:   {$stats['total_revenue']} eIOU");
        $this->output->writeLine("  Unique users:    {$stats['unique_users']}");

        return 0;
    }

    /**
     * Show help
     */
    private function showHelp(): int {
        $this->output->writeLine("File Hosting Plugin - Store and share files on eiou-docker nodes");
        $this->output->writeLine("");
        $this->output->writeLine("Usage: eiou file-hosting <command> [options]");
        $this->output->writeLine("");
        $this->output->writeLine("Commands:");
        $this->output->writeLine("  upload <path>              Upload a file");
        $this->output->writeLine("    --days=<n>               Storage duration (default: 30)");
        $this->output->writeLine("    --public                 Make file publicly accessible");
        $this->output->writeLine("    --password=<pwd>         Set access password");
        $this->output->writeLine("    --description=<text>     Add description");
        $this->output->writeLine("");
        $this->output->writeLine("  list [--expired]           List your files");
        $this->output->writeLine("  info <file-id>             Show file details");
        $this->output->writeLine("  download <file-id>         Download a file");
        $this->output->writeLine("    --output=<path>          Output path");
        $this->output->writeLine("    --password=<pwd>         Access password");
        $this->output->writeLine("");
        $this->output->writeLine("  delete <file-id>           Delete a file");
        $this->output->writeLine("  extend <file-id>           Extend storage duration");
        $this->output->writeLine("    --days=<n>               Additional days");
        $this->output->writeLine("");
        $this->output->writeLine("  storage                    Show storage usage");
        $this->output->writeLine("  pricing                    Show pricing information");
        $this->output->writeLine("  payments [--limit=<n>]     Show payment history");
        $this->output->writeLine("");
        $this->output->writeLine("Admin commands:");
        $this->output->writeLine("  cleanup                    Remove expired files");
        $this->output->writeLine("  stats                      Show node statistics");
        $this->output->writeLine("");
        $this->output->writeLine("Examples:");
        $this->output->writeLine("  eiou file-hosting upload myfile.pdf --days=60 --public");
        $this->output->writeLine("  eiou file-hosting download abc123 --output=./downloaded.pdf");
        $this->output->writeLine("  eiou file-hosting extend abc123 --days=30");

        return 0;
    }

    /**
     * Handle unknown command
     */
    private function unknownCommand(string $command): int {
        $this->output->error("Unknown command: {$command}");
        $this->output->info("Run 'eiou file-hosting help' for usage information.");
        return 1;
    }

    /**
     * Parse command options
     */
    private function parseOptions(array &$args): array {
        $options = [];
        $filtered = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $key = $parts[0];
                $value = $parts[1] ?? true;
                $options[$key] = $value;
            } else {
                $filtered[] = $arg;
            }
        }

        $args = $filtered;
        return $options;
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
