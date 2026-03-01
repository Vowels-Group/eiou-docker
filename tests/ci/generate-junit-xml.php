<?php
/**
 * Generates JUnit XML from PHPUnit console output.
 *
 * Workaround for PHPUnit 11's --log-junit producing 0-byte files in CI.
 * Parses PHPUnit's stdout (with --display-skipped) to extract test counts
 * and individual failed/skipped test details, then generates valid JUnit XML
 * for dorny/test-reporter.
 *
 * Usage: php generate-junit-xml.php <phpunit-output-file> <junit-output-file> <exit-code>
 */

if ($argc < 4) {
    fprintf(STDERR, "Usage: %s <phpunit-output> <junit-output> <exit-code>\n", $argv[0]);
    exit(1);
}

$outputFile = $argv[1];
$junitFile = $argv[2];
$exitCode = (int) $argv[3];

$output = file_get_contents($outputFile);
if ($output === false) {
    fprintf(STDERR, "Cannot read %s\n", $outputFile);
    exit(1);
}

// Strip ANSI color codes
$clean = preg_replace('/\033\[[0-9;]*m/', '', $output);

$tests = 0;
$assertions = 0;
$failures = 0;
$errors = 0;
$warnings = 0;
$skipped = 0;
$incomplete = 0;
$time = 0;

// Individual test details parsed from output
$failedTests = [];
$errorTests = [];
$skippedTests = [];
$incompleteTests = [];

// 1. Best source: PHPUnit summary line
if (preg_match('/OK \((\d+) tests?, (\d+) assertions?\)/', $clean, $m)) {
    $tests = (int) $m[1];
    $assertions = (int) $m[2];
}

// Parse detailed summary: "Tests: 3415, Assertions: 12345, Skipped: 27."
if (preg_match('/Tests:\s+(\d+)/', $clean, $m)) {
    $tests = (int) $m[1];
}
if (preg_match('/Assertions:\s+(\d+)/', $clean, $m)) {
    $assertions = (int) $m[1];
}
if (preg_match('/Failures:\s+(\d+)/', $clean, $m)) {
    $failures = (int) $m[1];
}
if (preg_match('/Errors:\s+(\d+)/', $clean, $m)) {
    $errors = (int) $m[1];
}
if (preg_match('/Skipped:\s+(\d+)/', $clean, $m)) {
    $skipped = (int) $m[1];
}
if (preg_match('/Incomplete:\s+(\d+)/', $clean, $m)) {
    $incomplete = (int) $m[1];
}

// 2. Parse time from "Time: 00:02.345"
if (preg_match('/Time:\s+(\d+):(\d+)\.(\d+)/', $clean, $m)) {
    $time = ((int) $m[1]) * 60 + (int) $m[2] + ((int) $m[3]) / pow(10, strlen($m[3]));
}

// 3. Always count progress dot characters for status breakdown (S=skipped, F=failure, E=error)
//    This works even when output is truncated, since skipped tests tend to appear early
if (preg_match_all('/^[.FEWIRSD]+/m', $clean, $dotMatches)) {
    $allDots = implode('', $dotMatches[0]);
    $dotTotal = strlen($allDots);
    if ($skipped === 0) {
        $skipped = substr_count($allDots, 'S');
    }
    if ($failures === 0) {
        $failures = substr_count($allDots, 'F');
    }
    if ($errors === 0) {
        $errors = substr_count($allDots, 'E');
    }
    // Use dot count as test total only if we don't have a better source
    if ($tests === 0) {
        $tests = $dotTotal;
    }
}

// 4. Fallback: progress counter total "N / TOTAL (" — more accurate than dot count
if ($tests === 0 && preg_match_all('/(\d+)\s*\/\s*(\d+)\s*\(/', $clean, $m)) {
    $tests = (int) end($m[2]);
} elseif (preg_match_all('/(\d+)\s*\/\s*(\d+)\s*\(/', $clean, $m)) {
    // Even if we already have a test count, prefer the progress counter total (it's the real total)
    $progressTotal = (int) end($m[2]);
    if ($progressTotal > $tests) {
        $tests = $progressTotal;
    }
}

if ($tests === 0) {
    $tests = 1;
}

// 5. Parse individual test details from sections like:
//    "There was 1 failure:" / "There were 27 skipped tests:"
//    Entries: "N) ClassName::methodName\nMessage\n\n/path:line"
$sections = [
    'failure'   => &$failedTests,
    'error'     => &$errorTests,
    'skipped test' => &$skippedTests,
    'incomplete test' => &$incompleteTests,
];

foreach ($sections as $type => $ref) {
    // Match "There was 1 failure:" or "There were 27 skipped tests:"
    $typePattern = preg_quote($type, '/');
    $pattern = '/There (?:was|were) \d+ ' . $typePattern . 's?:\s*\n(.*?)(?=\n(?:--|FAILURES!|ERRORS!|OK|There (?:was|were))|\z)/s';

    if (preg_match($pattern, $clean, $sectionMatch)) {
        $sectionBody = $sectionMatch[1];

        // Parse entries: "N) ClassName::methodName" followed by message lines
        if (preg_match_all('/^\d+\)\s+(.+)$/m', $sectionBody, $entries, PREG_OFFSET_CAPTURE)) {
            for ($i = 0; $i < count($entries[1]); $i++) {
                $testName = trim($entries[1][$i][0]);
                $startPos = $entries[0][$i][1] + strlen($entries[0][$i][0]);

                // Get message: text between this entry and the next (or end of section)
                if ($i + 1 < count($entries[0])) {
                    $endPos = $entries[0][$i + 1][1];
                } else {
                    $endPos = strlen($sectionBody);
                }

                $message = trim(substr($sectionBody, $startPos, $endPos - $startPos));
                // Remove trailing file:line reference
                $message = preg_replace('/\n\s*\/[^\n]+:\d+\s*$/', '', $message);
                $message = trim($message);

                // Split "ClassName::methodName" for classname attribute
                $parts = explode('::', $testName, 2);
                $className = $parts[0];
                $methodName = $parts[1] ?? $testName;

                $sections[$type][] = [
                    'name'      => $methodName,
                    'classname' => $className,
                    'message'   => $message,
                ];
            }
        }
    }
}
// Fix references (foreach with & above doesn't propagate back properly via $sections)
// Re-read from sections array
$failedTests = $sections['failure'];
$errorTests = $sections['error'];
$skippedTests = $sections['skipped test'];
$incompleteTests = $sections['incomplete test'];

// Calculate passed count
$detailedSkipped = count($skippedTests) + count($incompleteTests);
$detailedFailed = count($failedTests);
$detailedErrors = count($errorTests);
$passed = $tests - max($failures, $detailedFailed) - max($errors, $detailedErrors)
         - max($skipped + $incomplete, $detailedSkipped);
if ($passed < 0) {
    $passed = $tests - $detailedFailed - $detailedErrors - $detailedSkipped;
}
if ($passed < 0) {
    $passed = 0;
}

// Generate JUnit XML
$doc = new DOMDocument('1.0', 'UTF-8');
$doc->formatOutput = true;

$testsuites = $doc->createElement('testsuites');
$doc->appendChild($testsuites);

$suite = $doc->createElement('testsuite');
$suite->setAttribute('name', 'PHPUnit');
$suite->setAttribute('tests', (string) $tests);
$suite->setAttribute('assertions', (string) $assertions);
$suite->setAttribute('errors', (string) max($errors, $detailedErrors));
$suite->setAttribute('failures', (string) max($failures, $detailedFailed));
$suite->setAttribute('skipped', (string) max($skipped + $incomplete, $detailedSkipped));
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

// Individual failed tests
foreach ($failedTests as $test) {
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', $test['name']);
    $tc->setAttribute('classname', $test['classname']);
    $tc->setAttribute('time', '0');
    $fail = $doc->createElement('failure');
    $fail->setAttribute('message', mb_substr($test['message'], 0, 200));
    $fail->appendChild($doc->createTextNode($test['message']));
    $tc->appendChild($fail);
    $suite->appendChild($tc);
}

// Individual error tests
foreach ($errorTests as $test) {
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', $test['name']);
    $tc->setAttribute('classname', $test['classname']);
    $tc->setAttribute('time', '0');
    $err = $doc->createElement('error');
    $err->setAttribute('message', mb_substr($test['message'], 0, 200));
    $err->appendChild($doc->createTextNode($test['message']));
    $tc->appendChild($err);
    $suite->appendChild($tc);
}

// Individual skipped tests
foreach ($skippedTests as $test) {
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', $test['name']);
    $tc->setAttribute('classname', $test['classname']);
    $tc->setAttribute('time', '0');
    $skip = $doc->createElement('skipped');
    if ($test['message']) {
        $skip->setAttribute('message', mb_substr($test['message'], 0, 200));
    }
    $tc->appendChild($skip);
    $suite->appendChild($tc);
}

// Individual incomplete tests (treated as skipped in JUnit)
foreach ($incompleteTests as $test) {
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', $test['name']);
    $tc->setAttribute('classname', $test['classname']);
    $tc->setAttribute('time', '0');
    $skip = $doc->createElement('skipped');
    if ($test['message']) {
        $skip->setAttribute('message', 'Incomplete: ' . mb_substr($test['message'], 0, 200));
    }
    $tc->appendChild($skip);
    $suite->appendChild($tc);
}

// If no individual details were parsed but we have failure/skip counts, add summary elements
if (empty($failedTests) && $failures > 0) {
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', "{$failures} tests failed");
    $tc->setAttribute('classname', 'PHPUnit');
    $tc->setAttribute('time', '0');
    $fail = $doc->createElement('failure');
    $fail->setAttribute('message', "{$failures} test failures");
    $tc->appendChild($fail);
    $suite->appendChild($tc);
}

if (empty($skippedTests) && empty($incompleteTests) && ($skipped + $incomplete) > 0) {
    $tc = $doc->createElement('testcase');
    $tc->setAttribute('name', ($skipped + $incomplete) . " tests skipped");
    $tc->setAttribute('classname', 'PHPUnit');
    $tc->setAttribute('time', '0');
    $skip = $doc->createElement('skipped');
    $tc->appendChild($skip);
    $suite->appendChild($tc);
}

$xml = $doc->saveXML();
file_put_contents($junitFile, $xml);

$totalDetailed = $detailedFailed + $detailedErrors + $detailedSkipped;
fprintf(STDOUT, "Generated JUnit XML: %d tests (%d passed, %d failed, %d errors, %d skipped) in %.1fs\n",
    $tests, $passed, max($failures, $detailedFailed), max($errors, $detailedErrors),
    max($skipped + $incomplete, $detailedSkipped), $time);
if ($totalDetailed > 0) {
    fprintf(STDOUT, "  Parsed %d individual test details (%d failed, %d errors, %d skipped/incomplete)\n",
        $totalDetailed, $detailedFailed, $detailedErrors, $detailedSkipped);
}
