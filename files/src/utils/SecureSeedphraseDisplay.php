<?php
# Copyright 2025-2026 Vowels Group, LLC

/**
 * Secure Seedphrase Display
 *
 * Displays seedphrases and authentication codes securely without persisting them in Docker logs.
 *
 * Security Design:
 * - Primary: TTY output bypasses Docker's stdout/stderr capture
 * - Fallback: Memory-only temp file in /dev/shm (tmpfs) with auto-deletion
 *
 * The key insight is that Docker captures stdout/stderr but NOT:
 * 1. Direct writes to /dev/tty (interactive terminal)
 * 2. File contents in /dev/shm (user must explicitly read)
 *
 * @package Utils
 */

class SecureSeedphraseDisplay
{
    /** @var string Memory-only filesystem path */
    private const TEMP_DIR = '/dev/shm';

    /** @var int Seconds before automatic file deletion */
    private const FILE_TTL = 300; // 5 minutes

    /** @var string Prefix for temp files */
    private const FILE_PREFIX = 'eiou_wallet_info_';

    /**
     * Display a seedphrase and optionally an authcode securely
     *
     * Attempts TTY display first (not logged by Docker), falls back to
     * secure temp file if no TTY is available.
     *
     * @param string $seedphrase The 24-word mnemonic to display
     * @param bool $waitForAcknowledgment Whether to wait for user to press Enter (TTY only)
     * @param string|null $authcode Optional authentication code to display alongside seedphrase
     * @return array Result with method used and any instructions
     */
    public static function display(string $seedphrase, bool $waitForAcknowledgment = true, ?string $authcode = null): array
    {
        // Try TTY first (most secure - not logged by Docker)
        $ttyResult = self::displayViaTTY($seedphrase, $waitForAcknowledgment, $authcode);
        if ($ttyResult['success']) {
            return $ttyResult;
        }

        // Fallback to secure temp file
        return self::displayViaSecureFile($seedphrase, $authcode);
    }

    /**
     * Display seedphrase via TTY
     *
     * Writing directly to /dev/tty bypasses Docker's stdout/stderr capture.
     * This output will NOT appear in docker logs.
     *
     * @param string $seedphrase The seedphrase to display
     * @param bool $waitForAcknowledgment Whether to wait for Enter key
     * @param string|null $authcode Optional authentication code to display
     * @return array Result array
     */
    private static function displayViaTTY(string $seedphrase, bool $waitForAcknowledgment, ?string $authcode = null): array
    {
        // Check if we have a TTY
        if (!function_exists('posix_isatty') || !posix_isatty(STDOUT)) {
            return ['success' => false, 'method' => 'tty', 'reason' => 'No TTY available'];
        }

        // Try to open /dev/tty directly
        $tty = @fopen('/dev/tty', 'w');
        if (!$tty) {
            return ['success' => false, 'method' => 'tty', 'reason' => 'Cannot open /dev/tty'];
        }

        // Format the seedphrase for display
        $formatted = self::formatSeedphrase($seedphrase);

        // Clear screen and display (ANSI escape codes)
        fwrite($tty, "\033[2J\033[H"); // Clear screen, move cursor to top
        fwrite($tty, "\n");
        fwrite($tty, "\033[1;33m"); // Bold yellow
        fwrite($tty, "╔═══════════════════════════════════════════════════════════════╗\n");
        fwrite($tty, "║     IMPORTANT: WRITE DOWN YOUR SEED PHRASE AND STORE SAFELY   ║\n");
        fwrite($tty, "╠═══════════════════════════════════════════════════════════════╣\n");
        fwrite($tty, "\033[0m"); // Reset color
        fwrite($tty, "║ This is the ONLY way to restore your wallet if lost.          ║\n");
        fwrite($tty, "║ Never share it. Never store it digitally.                     ║\n");
        fwrite($tty, "\033[1;31m"); // Bold red
        fwrite($tty, "║                                                               ║\n");
        fwrite($tty, "║ THIS MESSAGE WILL NOT APPEAR IN DOCKER LOGS FOR SECURITY      ║\n");
        fwrite($tty, "║                                                               ║\n");
        fwrite($tty, "\033[0m"); // Reset
        fwrite($tty, "╠═══════════════════════════════════════════════════════════════╣\n");

        // Display formatted seedphrase
        $lines = explode("\n", $formatted);
        foreach ($lines as $line) {
            $padded = str_pad($line, 61);
            fwrite($tty, "║ " . $padded . " ║\n");
        }

        // Display authcode if provided
        if ($authcode !== null) {
            fwrite($tty, "╠═══════════════════════════════════════════════════════════════╣\n");
            fwrite($tty, "║                                                               ║\n");
            fwrite($tty, "\033[1;36m"); // Bold cyan
            $authcodeLine = "Authentication Code: " . $authcode;
            $padded = str_pad($authcodeLine, 61);
            fwrite($tty, "║ " . $padded . " ║\n");
            fwrite($tty, "\033[0m"); // Reset
            fwrite($tty, "║                                                               ║\n");
        }

        // Display restore instructions with copy-paste ready command
        fwrite($tty, "╠═══════════════════════════════════════════════════════════════╣\n");
        fwrite($tty, "║                     WALLET RESTORATION                        ║\n");
        fwrite($tty, "╠═══════════════════════════════════════════════════════════════╣\n");
        fwrite($tty, "║ To restore, use the RESTORE environment variable:            ║\n");
        fwrite($tty, "║                                                               ║\n");
        fwrite($tty, "\033[1;32m"); // Bold green
        fwrite($tty, "║ docker run -e RESTORE=\"<seedphrase>\" eioud                    ║\n");
        fwrite($tty, "\033[0m"); // Reset
        fwrite($tty, "║                                                               ║\n");
        fwrite($tty, "║ Your copy-paste ready command:                                ║\n");
        fwrite($tty, "\033[1;32m"); // Bold green
        $restoreCmd = 'docker run -e RESTORE="' . $seedphrase . '" eioud';
        // Wrap long command across multiple lines if needed
        $cmdChunks = str_split($restoreCmd, 59);
        foreach ($cmdChunks as $chunk) {
            $padded = str_pad($chunk, 61);
            fwrite($tty, "║ " . $padded . " ║\n");
        }
        fwrite($tty, "\033[0m"); // Reset
        fwrite($tty, "║                                                               ║\n");
        fwrite($tty, "║ For better security, use RESTORE_FILE instead:                ║\n");
        fwrite($tty, "║ docker run -v /path/seed.txt:/seed:ro -e RESTORE_FILE=/seed   ║\n");

        fwrite($tty, "╚═══════════════════════════════════════════════════════════════╝\n");
        fwrite($tty, "\n");

        if ($waitForAcknowledgment) {
            fwrite($tty, "\033[1mPress ENTER after you have securely saved this information...\033[0m");
            fclose($tty);

            // Wait for acknowledgment via stdin
            if (function_exists('readline')) {
                readline();
            } else {
                fgets(STDIN);
            }

            // Clear the screen after acknowledgment
            $tty = @fopen('/dev/tty', 'w');
            if ($tty) {
                fwrite($tty, "\033[2J\033[H"); // Clear screen
                fwrite($tty, "Secure information display cleared for security.\n\n");
                fclose($tty);
            }
        } else {
            fclose($tty);
        }

        return [
            'success' => true,
            'method' => 'tty',
            'message' => 'Seedphrase and authcode displayed securely via TTY (not logged)'
        ];
    }

    /**
     * Display seedphrase via secure temp file
     *
     * Writes seedphrase to /dev/shm (memory-only tmpfs) with auto-deletion.
     * The file path is logged but NOT the contents.
     *
     * @param string $seedphrase The seedphrase to store
     * @param string|null $authcode Optional authentication code to include
     * @return array Result with instructions for retrieval
     */
    private static function displayViaSecureFile(string $seedphrase, ?string $authcode = null): array
    {
        // Verify tmpfs is available
        $dir = self::TEMP_DIR;
        if (!is_dir($dir) || !is_writable($dir)) {
            // Fall back to /tmp but warn about reduced security
            $dir = '/tmp';
            $warning = 'WARNING: /dev/shm not available, using /tmp (less secure)';
        } else {
            $warning = null;
        }

        // Generate unique filename
        $filename = $dir . '/' . self::FILE_PREFIX . bin2hex(random_bytes(16));

        // Format seedphrase for the file
        $formatted = self::formatSeedphrase($seedphrase);
        $content = "═══════════════════════════════════════════════════════════════\n";
        $content .= "     IMPORTANT: WRITE DOWN YOUR SEED PHRASE AND STORE SAFELY\n";
        $content .= "═══════════════════════════════════════════════════════════════\n";
        $content .= " This is the ONLY way to restore your wallet if lost.\n";
        $content .= " Never share it. Never store it digitally.\n";
        $content .= "═══════════════════════════════════════════════════════════════\n\n";
        $content .= $formatted . "\n\n";

        // Include authcode if provided
        if ($authcode !== null) {
            $content .= "═══════════════════════════════════════════════════════════════\n";
            $content .= "                    AUTHENTICATION CODE\n";
            $content .= "═══════════════════════════════════════════════════════════════\n\n";
            $content .= "  " . $authcode . "\n\n";
        }

        // Include restore instructions with copy-paste ready command
        $content .= "═══════════════════════════════════════════════════════════════\n";
        $content .= "                    WALLET RESTORATION\n";
        $content .= "═══════════════════════════════════════════════════════════════\n\n";
        $content .= " To restore this wallet, use the RESTORE environment variable:\n\n";
        $content .= "   docker run -e RESTORE=\"<seedphrase>\" eioud\n\n";
        $content .= " Your copy-paste ready command:\n\n";
        $content .= "   docker run -e RESTORE=\"" . $seedphrase . "\" eioud\n\n";
        $content .= " For better security, save seedphrase to a file and use:\n\n";
        $content .= "   docker run -v /path/seed.txt:/seed:ro -e RESTORE_FILE=/seed eioud\n\n";

        $content .= "═══════════════════════════════════════════════════════════════\n";
        $content .= " This file will be automatically deleted in " . self::FILE_TTL . " seconds.\n";
        $content .= " Delete it immediately after saving: rm $filename\n";
        $content .= "═══════════════════════════════════════════════════════════════\n";

        // Write with restrictive permissions
        $oldUmask = umask(0077);
        $written = @file_put_contents($filename, $content, LOCK_EX);
        umask($oldUmask);

        if ($written === false) {
            return [
                'success' => false,
                'method' => 'file',
                'message' => 'Failed to write secure temp file'
            ];
        }

        // Set restrictive permissions (owner read only)
        chmod($filename, 0400);

        // Schedule deletion (background process survives PHP exit)
        self::scheduleFileDeletion($filename, self::FILE_TTL);

        // Get container name for instructions
        $containerName = gethostname() ?: '<container>';

        $instructions = [
            "Your seedphrase" . ($authcode !== null ? " and authentication code have" : " has") . " been stored securely in a temporary file.",
            "",
            "To view it, run:",
            "  docker exec $containerName cat $filename",
            "",
            "The file will be automatically deleted in " . self::FILE_TTL . " seconds.",
            "For security, view and delete it immediately after saving.",
            "",
            "To delete manually:",
            "  docker exec $containerName rm $filename"
        ];

        return [
            'success' => true,
            'method' => 'file',
            'message' => 'Seedphrase' . ($authcode !== null ? ' and authcode' : '') . ' stored in secure temp file',
            'instructions' => $instructions,
            'filepath' => $filename,
            'ttl' => self::FILE_TTL,
            'warning' => $warning
        ];
    }

    /**
     * Format seedphrase for display (groups of 4 words per line)
     *
     * @param string $seedphrase Space-separated mnemonic
     * @return string Formatted display
     */
    private static function formatSeedphrase(string $seedphrase): string
    {
        $words = explode(' ', $seedphrase);
        $lines = [];

        // Find longest word for consistent column width
        $maxWordLen = 0;
        foreach ($words as $word) {
            $maxWordLen = max($maxWordLen, strlen($word));
        }

        // Format as numbered list, 4 words per line
        for ($i = 0; $i < count($words); $i += 4) {
            $lineWords = array_slice($words, $i, 4);
            $numberedWords = [];
            foreach ($lineWords as $j => $word) {
                $num = $i + $j + 1;
                $entry = str_pad($num, 2, ' ', STR_PAD_LEFT) . '. ' . str_pad($word, $maxWordLen);
                $numberedWords[] = $entry;
            }
            $lines[] = implode('  ', $numberedWords);
        }

        return implode("\n", $lines);
    }

    /**
     * Schedule file deletion after TTL
     *
     * Uses nohup to ensure deletion survives PHP process exit.
     * The background shell process will delete the file after the TTL expires.
     *
     * NOTE: We intentionally do NOT use register_shutdown_function here because
     * that would delete the file immediately when PHP exits (which happens right
     * after wallet generation), defeating the purpose of giving the user time
     * to retrieve the seedphrase.
     *
     * @param string $filepath Path to file
     * @param int $seconds Delay before deletion
     */
    private static function scheduleFileDeletion(string $filepath, int $seconds): void
    {
        $escaped = escapeshellarg($filepath);

        // Use nohup for deletion that survives PHP exit
        // The & runs it in background, nohup prevents SIGHUP from killing it
        // The file will be deleted after $seconds delay
        exec("nohup sh -c 'sleep $seconds && rm -f $escaped' > /dev/null 2>&1 &");
    }

    /**
     * Check if secure display is available
     *
     * @return array Information about available display methods
     */
    public static function checkAvailability(): array
    {
        $ttyAvailable = function_exists('posix_isatty') && posix_isatty(STDOUT);
        $shmAvailable = is_dir(self::TEMP_DIR) && is_writable(self::TEMP_DIR);

        return [
            'tty_available' => $ttyAvailable,
            'shm_available' => $shmAvailable,
            'preferred_method' => $ttyAvailable ? 'tty' : ($shmAvailable ? 'shm_file' : 'tmp_file'),
            'security_level' => $ttyAvailable ? 'high' : ($shmAvailable ? 'medium' : 'low')
        ];
    }

    /**
     * Clean up any orphaned seedphrase files
     *
     * Call this periodically or on startup to remove any files that
     * weren't properly cleaned up.
     *
     * @return int Number of files cleaned up
     */
    public static function cleanup(): int
    {
        $count = 0;
        $dirs = [self::TEMP_DIR, '/tmp'];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;

            $pattern = $dir . '/' . self::FILE_PREFIX . '*';
            $files = glob($pattern);

            foreach ($files as $file) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
