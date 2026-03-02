<?php
/**
 * Generates JUnit XML from PHPUnit progress dots + test list.
 *
 * Maps each character in PHPUnit's progress output (., S, F, E, etc.) to the
 * corresponding test name from --list-tests. Position N in the dot sequence
 * = test N in the list. This lets us identify exactly which tests were
 * skipped/failed even when PHPUnit's detailed output is truncated.
 *
 * Also parses PHPUnit's summary line and detail sections as supplementary data.
 *
 * Usage: php generate-junit-xml.php <phpunit-output> <test-list> <junit-output> <exit-code>
 *
 * <test-list> is the output of: phpunit --list-tests | grep '^ - '
 */

if ($argc < 5) {
    fprintf(STDERR, "Usage: %s <phpunit-output> <test-list> <junit-output> <exit-code>\n", $argv[0]);
    exit(1);
}

$outputFile = $argv[1];
$testListFile = $argv[2];
$junitFile = $argv[3];
$exitCode = (int) $argv[4];

$output = file_get_contents($outputFile);
if ($output === false) {
    fprintf(STDERR, "Cannot read %s\n", $outputFile);
    exit(1);
}

// Strip ANSI color codes
$clean = preg_replace('/\033\[[0-9;]*m/', '', $output);

// --- Load ordered test list ---
$testNames = [];
if (file_exists($testListFile) && filesize($testListFile) > 0) {
    $lines = file($testListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Each line: " - Namespace\Class::testMethod"
        $name = preg_replace('/^\s*-\s*/', '', $line);
        if ($name !== '') {
            $testNames[] = trim($name);
        }
    }
}
$totalFromList = count($testNames);
fprintf(STDOUT, "Loaded %d test names from list\n", $totalFromList);

// --- Extract progress characters using counter anchors ---
// PHPUnit progress lines: "...S..F..  183 / 3600 (  5%)"
// The counter at the end tells us the LAST test number on that line.
// We use this to map characters to exact test positions, even when
// log messages interleave and split progress across multiple lines.
// Only lines ending with "N / TOTAL (XX%)" are trusted — this avoids
// false positives from log output that happens to start with F, R, etc.
$mappedSkipped = [];
$mappedFailed = [];
$mappedErrors = [];
$mappedWarnings = [];
$mappedIncomplete = [];
$mappedRisky = [];
$mappedDeprecations = [];
$dotCount = 0;

if (preg_match_all('/^([.FEWIRSD]+)\s+(\d+)\s*\/\s*(\d+)\s*\(/m', $clean, $counterMatches, PREG_SET_ORDER)) {
    foreach ($counterMatches as $match) {
        $chars = $match[1];
        $endPos = (int) $match[2];  // e.g. 366 = this line ends at test 366
        $charCount = strlen($chars);

        // The characters on this line represent tests (endPos - charCount + 1) through endPos
        // Use 0-indexed: tests[endPos - charCount] through tests[endPos - 1]
        $startIdx = $endPos - $charCount; // 0-indexed start

        for ($j = 0; $j < $charCount; $j++) {
            $char = $chars[$j];
            $testIdx = $startIdx + $j; // 0-indexed position in test list
            $dotCount++;

            if ($char === '.') {
                continue;
            }

            $testName = ($testIdx < $totalFromList) ? $testNames[$testIdx] : "Unknown test #" . ($testIdx + 1);

            switch ($char) {
                case 'S': $mappedSkipped[] = $testName; break;
                case 'F': $mappedFailed[] = $testName; break;
                case 'E': $mappedErrors[] = $testName; break;
                case 'W': $mappedWarnings[] = $testName; break;
                case 'I': $mappedIncomplete[] = $testName; break;
                case 'R': $mappedRisky[] = $testName; break;
                case 'D': $mappedDeprecations[] = $testName; break;
            }
        }
    }
}
fprintf(STDOUT, "Captured %d progress characters out of %d tests\n", $dotCount, $totalFromList);

// --- Cross-reference with PHPUnit exit code ---
// PHPUnit exit 0 = all tests passed. Any F/E characters in the progress output
// are false positives from interleaved log messages (test code that writes to stdout
// can produce lines starting with F, R, etc. that look like progress characters).
// Only trust F/E from dots when the exit code confirms actual failures.
if ($exitCode === 0 && (!empty($mappedFailed) || !empty($mappedErrors))) {
    fprintf(STDOUT, "Note: PHPUnit exit code 0 (all passed) — %d apparent F/E chars are from interleaved output, ignoring\n",
        count($mappedFailed) + count($mappedErrors));
    $mappedFailed = [];
    $mappedErrors = [];
}

// --- Print identified tests to CI log ---
if (!empty($mappedFailed)) {
    fprintf(STDOUT, "\nFailed tests (from dot positions):\n");
    foreach ($mappedFailed as $idx => $name) {
        fprintf(STDOUT, "  %d) %s\n", $idx + 1, $name);
    }
}
if (!empty($mappedErrors)) {
    fprintf(STDOUT, "\nError tests (from dot positions):\n");
    foreach ($mappedErrors as $idx => $name) {
        fprintf(STDOUT, "  %d) %s\n", $idx + 1, $name);
    }
}
if (!empty($mappedSkipped)) {
    fprintf(STDOUT, "\nSkipped tests (from dot positions):\n");
    foreach ($mappedSkipped as $idx => $name) {
        fprintf(STDOUT, "  %d) %s\n", $idx + 1, $name);
    }
}
if (!empty($mappedIncomplete)) {
    fprintf(STDOUT, "\nIncomplete tests (from dot positions):\n");
    foreach ($mappedIncomplete as $idx => $name) {
        fprintf(STDOUT, "  %d) %s\n", $idx + 1, $name);
    }
}

// --- Parse summary line for authoritative counts ---
$tests = 0;
$assertions = 0;
$summaryFailures = 0;
$summaryErrors = 0;
$summarySkipped = 0;
$summaryIncomplete = 0;
$time = 0;

if (preg_match('/OK \((\d+) tests?, (\d+) assertions?\)/', $clean, $m)) {
    $tests = (int) $m[1];
    $assertions = (int) $m[2];
}
if (preg_match('/Tests:\s+(\d+)/', $clean, $m)) {
    $tests = (int) $m[1];
}
if (preg_match('/Assertions:\s+(\d+)/', $clean, $m)) {
    $assertions = (int) $m[1];
}
if (preg_match('/Failures:\s+(\d+)/', $clean, $m)) {
    $summaryFailures = (int) $m[1];
}
if (preg_match('/Errors:\s+(\d+)/', $clean, $m)) {
    $summaryErrors = (int) $m[1];
}
if (preg_match('/Skipped:\s+(\d+)/', $clean, $m)) {
    $summarySkipped = (int) $m[1];
}
if (preg_match('/Incomplete:\s+(\d+)/', $clean, $m)) {
    $summaryIncomplete = (int) $m[1];
}
if (preg_match('/Time:\s+(\d+):(\d+)\.(\d+)/', $clean, $m)) {
    $time = ((int) $m[1]) * 60 + (int) $m[2] + ((int) $m[3]) / pow(10, strlen($m[3]));
}

// Use test list count as authoritative total if summary not found
if ($tests === 0) {
    $tests = $totalFromList > 0 ? $totalFromList : max($dotCount, 1);
}

// --- Parse detail sections for failure/skip messages ---
// These appear as: "There was 1 failure:" / "There were 27 skipped tests:"
// followed by "N) ClassName::method\nMessage\n\n/path:line"
$detailMessages = []; // keyed by "ClassName::method" => message

$sections = ['failure', 'error', 'skipped test', 'incomplete test'];
foreach ($sections as $type) {
    $typePattern = preg_quote($type, '/');
    $pattern = '/There (?:was|were) \d+ ' . $typePattern . 's?:\s*\n(.*?)(?=\n(?:--|FAILURES!|ERRORS!|OK|There (?:was|were))|\z)/s';

    if (preg_match($pattern, $clean, $sectionMatch)) {
        $sectionBody = $sectionMatch[1];
        if (preg_match_all('/^\d+\)\s+(.+)$/m', $sectionBody, $entries, PREG_OFFSET_CAPTURE)) {
            for ($i = 0; $i < count($entries[1]); $i++) {
                $testName = trim($entries[1][$i][0]);
                $startPos = $entries[0][$i][1] + strlen($entries[0][$i][0]);
                $endPos = ($i + 1 < count($entries[0])) ? $entries[0][$i + 1][1] : strlen($sectionBody);
                $message = trim(substr($sectionBody, $startPos, $endPos - $startPos));
                $message = preg_replace('/\n\s*\/[^\n]+:\d+\s*$/', '', $message);
                $detailMessages[$testName] = trim($message);
            }
        }
    }
}

// --- Determine final counts ---
// Prefer summary counts when available, fall back to dot counts
$finalFailures = max($summaryFailures, count($mappedFailed));
$finalErrors = max($summaryErrors, count($mappedErrors));
$finalSkipped = max($summarySkipped, count($mappedSkipped));
$finalIncomplete = max($summaryIncomplete, count($mappedIncomplete));
$passed = $tests - $finalFailures - $finalErrors - $finalSkipped - $finalIncomplete;
if ($passed < 0) {
    $passed = 0;
}

// --- Generate JUnit XML ---
$doc = new DOMDocument('1.0', 'UTF-8');
$doc->formatOutput = true;

$testsuites = $doc->createElement('testsuites');
$doc->appendChild($testsuites);

$suite = $doc->createElement('testsuite');
$suite->setAttribute('name', 'PHPUnit');
$suite->setAttribute('tests', (string) $tests);
$suite->setAttribute('assertions', (string) $assertions);
$suite->setAttribute('errors', (string) $finalErrors);
$suite->setAttribute('failures', (string) $finalFailures);
$suite->setAttribute('skipped', (string) ($finalSkipped + $finalIncomplete));
$suite->setAttribute('time', sprintf('%.3f', $time));
$testsuites->appendChild($suite);

// Passed tests (single summary element)
if ($passed > 0) {
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', "{$passed} tests passed");
    $tc->setAttribute('classname', 'PHPUnit');
    $tc->setAttribute('time', sprintf('%.3f', $time));
    $suite->appendChild($tc);
}

// Helper to split "Namespace\Class::method" into classname + name
function splitTestName(string $fullName): array {
    $parts = explode('::', $fullName, 2);
    return [
        'classname' => $parts[0],
        'name' => $parts[1] ?? $fullName,
    ];
}

// Individual failed tests
foreach ($mappedFailed as $testName) {
    $info = splitTestName($testName);
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', $info['name']);
    $tc->setAttribute('classname', $info['classname']);
    $tc->setAttribute('time', '0');
    $msg = $detailMessages[$testName] ?? 'Test failed';
    $fail = $doc->createElement('failure');
    $fail->setAttribute('message', mb_substr($msg, 0, 200));
    $fail->appendChild($doc->createTextNode($msg));
    $tc->appendChild($fail);
    $suite->appendChild($tc);
}

// Individual error tests
foreach ($mappedErrors as $testName) {
    $info = splitTestName($testName);
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', $info['name']);
    $tc->setAttribute('classname', $info['classname']);
    $tc->setAttribute('time', '0');
    $msg = $detailMessages[$testName] ?? 'Test error';
    $err = $doc->createElement('error');
    $err->setAttribute('message', mb_substr($msg, 0, 200));
    $err->appendChild($doc->createTextNode($msg));
    $tc->appendChild($err);
    $suite->appendChild($tc);
}

// Individual skipped tests
foreach ($mappedSkipped as $testName) {
    $info = splitTestName($testName);
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', $info['name']);
    $tc->setAttribute('classname', $info['classname']);
    $tc->setAttribute('time', '0');
    $skip = $doc->createElement('skipped');
    $msg = $detailMessages[$testName] ?? '';
    if ($msg) {
        $skip->setAttribute('message', mb_substr($msg, 0, 200));
    }
    $tc->appendChild($skip);
    $suite->appendChild($tc);
}

// Individual incomplete tests (treated as skipped in JUnit)
foreach ($mappedIncomplete as $testName) {
    $info = splitTestName($testName);
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', $info['name']);
    $tc->setAttribute('classname', $info['classname']);
    $tc->setAttribute('time', '0');
    $skip = $doc->createElement('skipped');
    $msg = $detailMessages[$testName] ?? '';
    if ($msg) {
        $skip->setAttribute('message', 'Incomplete: ' . mb_substr($msg, 0, 200));
    }
    $tc->appendChild($skip);
    $suite->appendChild($tc);
}

// If we have summary counts but NO dot-mapped tests (output was fully truncated),
// add summary placeholders so the report still reflects the counts
if (empty($mappedFailed) && $summaryFailures > 0) {
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', "{$summaryFailures} tests failed (details in CI log)");
    $tc->setAttribute('classname', 'PHPUnit');
    $tc->setAttribute('time', '0');
    $fail = $doc->createElement('failure');
    $fail->setAttribute('message', "{$summaryFailures} test failures - see Run PHPUnit step log");
    $tc->appendChild($fail);
    $suite->appendChild($tc);
}

if (empty($mappedSkipped) && empty($mappedIncomplete) && ($summarySkipped + $summaryIncomplete) > 0) {
    $total = $summarySkipped + $summaryIncomplete;
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', "{$total} tests skipped (details in CI log)");
    $tc->setAttribute('classname', 'PHPUnit');
    $tc->setAttribute('time', '0');
    $skip = $doc->createElement('skipped');
    $tc->appendChild($skip);
    $suite->appendChild($tc);
}

$xml = $doc->saveXML();
file_put_contents($junitFile, $xml);

// --- Summary ---
fprintf(STDOUT, "\nGenerated JUnit XML: %d tests (%d passed, %d failed, %d errors, %d skipped)\n",
    $tests, $passed, $finalFailures, $finalErrors, $finalSkipped + $finalIncomplete);
if ($dotCount > 0 && $dotCount < $totalFromList) {
    fprintf(STDOUT, "Note: Only %d/%d progress chars captured (%.0f%%) - some tests may not be mapped\n",
        $dotCount, $totalFromList, ($dotCount / $totalFromList) * 100);
}
