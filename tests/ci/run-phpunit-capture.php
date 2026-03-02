<?php
/**
 * Wrapper that runs PHPUnit and captures ALL stdout/stderr output reliably.
 *
 * Uses proc_open to read from process pipes, avoiding output buffering issues
 * where PHPUnit's output gets lost when redirecting to a file.
 *
 * Usage: php run-phpunit-capture.php <output-file> [phpunit-args...]
 * Exit code: PHPUnit's exit code
 */

if ($argc < 2) {
    fprintf(STDERR, "Usage: %s <output-file> [phpunit-args...]\n", $argv[0]);
    exit(1);
}

$outputFile = $argv[1];
$phpunitArgs = array_slice($argv, 2);

// Find PHPUnit binary
$phpunit = getcwd() . '/vendor/bin/phpunit';
if (!file_exists($phpunit)) {
    fprintf(STDERR, "PHPUnit not found at %s\n", $phpunit);
    exit(1);
}

// Build command
$cmd = PHP_BINARY . ' ' . escapeshellarg($phpunit);
foreach ($phpunitArgs as $arg) {
    $cmd .= ' ' . escapeshellarg($arg);
}

$descriptors = [
    0 => ['pipe', 'r'],       // stdin
    1 => ['pipe', 'w'],       // stdout
    2 => ['redirect', 1],     // stderr → stdout
];

$process = proc_open($cmd, $descriptors, $pipes, getcwd());
if (!is_resource($process)) {
    fprintf(STDERR, "Failed to start PHPUnit\n");
    exit(1);
}

fclose($pipes[0]); // close stdin

// Read all output
$output = '';
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 8192);
    if ($chunk !== false) {
        $output .= $chunk;
        // Echo to CI log in real-time
        fwrite(STDOUT, $chunk);
    }
}
fclose($pipes[1]);

$exitCode = proc_close($process);

// Write complete output to file
file_put_contents($outputFile, $output);

fprintf(STDERR, "\n[capture] Wrote %d bytes (%d lines) to %s\n",
    strlen($output), substr_count($output, "\n"), $outputFile);

exit($exitCode);
