#!/usr/bin/env php
<?php
/**
 * Test runner for the eIOU project
 * Usage: php tests/run_tests.php [test_file]
 */

// Include the SimpleTest framework
require_once __DIR__ . '/SimpleTest.php';

// Get command line arguments
$testFile = $argv[1] ?? null;

if ($testFile) {
    // Run a specific test file
    if (!file_exists($testFile)) {
        echo "Error: Test file '$testFile' not found\n";
        exit(1);
    }
    echo "Running test: $testFile\n";
    runTestFile($testFile);
} else {
    // Run all test files
    $testDir = __DIR__;
    $testFiles = glob("$testDir/test_*.php");

    if (empty($testFiles)) {
        echo "No test files found (looking for test_*.php)\n";
        exit(0);
    }

    $totalPassed = 0;
    $totalFailed = 0;
    $failedFiles = [];

    foreach ($testFiles as $file) {
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "Running: " . basename($file) . "\n";
        echo str_repeat('=', 60) . "\n";

        // Run test in subprocess to isolate failures
        $output = [];
        $returnCode = 0;
        exec("php '$file'", $output, $returnCode);

        echo implode("\n", $output) . "\n";

        if ($returnCode === 0) {
            $totalPassed++;
        } else {
            $totalFailed++;
            $failedFiles[] = basename($file);
        }
    }

    // Print overall summary
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "OVERALL TEST RESULTS\n";
    echo str_repeat('=', 60) . "\n";
    echo "Test files passed: $totalPassed\n";
    echo "Test files failed: $totalFailed\n";

    if ($totalFailed > 0) {
        echo "\nFailed test files:\n";
        foreach ($failedFiles as $file) {
            echo "  - $file\n";
        }
        echo "\n❌ SOME TESTS FAILED\n";
        exit(1);
    } else {
        echo "\n✅ ALL TESTS PASSED\n";
        exit(0);
    }
}