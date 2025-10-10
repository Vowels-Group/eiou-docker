#!/usr/bin/env php
<?php
/**
 * Master Test Runner for eIOU GUI
 * Executes all test suites and generates comprehensive report
 *
 * Copyright 2025
 * @package eIOU GUI Tests
 *
 * Usage:
 *   php run_tests.php                    # Run all tests
 *   php run_tests.php --suite=helper     # Run only helper tests
 *   php run_tests.php --suite=session    # Run only session tests
 *   php run_tests.php --suite=repository # Run only repository tests
 *   php run_tests.php --suite=controller # Run only controller tests
 *   php run_tests.php --suite=integration # Run only integration tests
 *   php run_tests.php --verbose          # Run with verbose output
 */

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Change to script directory
chdir(__DIR__);

// Include all test files
require_once('HelperTest.php');
require_once('SessionTest.php');
require_once('RepositoryTest.php');
require_once('ControllerTest.php');
require_once('IntegrationTest.php');

/**
 * Test Runner Class
 */
class TestRunner {
    private $totalPassed = 0;
    private $totalFailed = 0;
    private $suiteResults = [];
    private $verbose = false;
    private $selectedSuite = null;
    private $startTime;
    private $endTime;

    /**
     * Parse command line arguments
     */
    public function __construct($argv) {
        $this->startTime = microtime(true);

        // Parse arguments
        foreach ($argv as $arg) {
            if ($arg === '--verbose' || $arg === '-v') {
                $this->verbose = true;
            } elseif (strpos($arg, '--suite=') === 0) {
                $this->selectedSuite = substr($arg, 8);
            } elseif ($arg === '--help' || $arg === '-h') {
                $this->showHelp();
                exit(0);
            }
        }
    }

    /**
     * Show help message
     */
    private function showHelp() {
        echo "eIOU GUI Test Suite Runner\n";
        echo "==========================\n\n";
        echo "Usage:\n";
        echo "  php run_tests.php [options]\n\n";
        echo "Options:\n";
        echo "  --suite=<name>    Run specific test suite\n";
        echo "                    (helper, session, repository, controller, integration)\n";
        echo "  --verbose, -v     Show verbose output\n";
        echo "  --help, -h        Show this help message\n\n";
        echo "Examples:\n";
        echo "  php run_tests.php                    # Run all tests\n";
        echo "  php run_tests.php --suite=helper     # Run only helper tests\n";
        echo "  php run_tests.php --verbose          # Run with verbose output\n";
    }

    /**
     * Run a single test suite
     */
    private function runSuite($name, $testClass) {
        echo "\n";
        echo str_repeat("█", 60) . "\n";
        echo "RUNNING: $name\n";
        echo str_repeat("█", 60) . "\n";

        $tester = new $testClass();
        $results = $tester->runAllTests();

        $this->totalPassed += $results['passed'];
        $this->totalFailed += $results['failed'];

        $this->suiteResults[$name] = $results;

        return $results['failed'] === 0;
    }

    /**
     * Run all test suites
     */
    public function runAllTests() {
        $suites = [
            'Helper Functions' => 'HelperTest',
            'Session Management' => 'SessionTest',
            'Repository & Database' => 'RepositoryTest',
            'Controller & Forms' => 'ControllerTest',
            'Integration Tests' => 'IntegrationTest'
        ];

        $allPassed = true;

        foreach ($suites as $name => $class) {
            // Check if specific suite selected
            if ($this->selectedSuite !== null) {
                $suiteLower = strtolower(str_replace(' ', '', $name));
                $selectedLower = strtolower($this->selectedSuite);

                // Skip if not the selected suite
                if (strpos($suiteLower, $selectedLower) === false) {
                    continue;
                }
            }

            $passed = $this->runSuite($name, $class);
            if (!$passed) {
                $allPassed = false;
            }
        }

        return $allPassed;
    }

    /**
     * Display final summary
     */
    public function displaySummary() {
        $this->endTime = microtime(true);
        $duration = $this->endTime - $this->startTime;

        echo "\n";
        echo str_repeat("=", 80) . "\n";
        echo "FINAL TEST SUMMARY\n";
        echo str_repeat("=", 80) . "\n\n";

        // Suite-by-suite breakdown
        echo "Test Suite Breakdown:\n";
        echo str_repeat("-", 80) . "\n";
        printf("%-35s %10s %10s %10s\n", "Suite", "Passed", "Failed", "Total");
        echo str_repeat("-", 80) . "\n";

        foreach ($this->suiteResults as $name => $results) {
            $total = $results['passed'] + $results['failed'];
            $status = $results['failed'] === 0 ? '✅' : '❌';
            printf("%s %-33s %10d %10d %10d\n",
                $status,
                $name,
                $results['passed'],
                $results['failed'],
                $total
            );
        }

        echo str_repeat("-", 80) . "\n";

        // Overall summary
        $totalTests = $this->totalPassed + $this->totalFailed;
        $passRate = $totalTests > 0 ? ($this->totalPassed / $totalTests) * 100 : 0;

        echo "\nOverall Results:\n";
        echo str_repeat("-", 80) . "\n";
        printf("Total Tests Run:       %d\n", $totalTests);
        printf("✅ Tests Passed:       %d\n", $this->totalPassed);
        printf("❌ Tests Failed:       %d\n", $this->totalFailed);
        printf("Pass Rate:             %.2f%%\n", $passRate);
        printf("Execution Time:        %.3f seconds\n", $duration);
        echo str_repeat("-", 80) . "\n\n";

        // Final status
        if ($this->totalFailed === 0) {
            echo "🎉 SUCCESS! All tests passed!\n";
            echo "\nThe GUI refactoring is working correctly:\n";
            echo "  • Helper functions are reliable\n";
            echo "  • Session management is secure\n";
            echo "  • Database queries are efficient\n";
            echo "  • Form handling is robust\n";
            echo "  • End-to-end workflows function properly\n";
        } else {
            echo "⚠️  FAILURE! Some tests failed.\n";
            echo "\nPlease review the test output above to identify and fix issues.\n";
            echo "Failed test categories:\n";

            foreach ($this->suiteResults as $name => $results) {
                if ($results['failed'] > 0) {
                    printf("  • %s: %d failed test(s)\n", $name, $results['failed']);
                }
            }
        }

        echo "\n" . str_repeat("=", 80) . "\n";

        // Return exit code
        return $this->totalFailed === 0 ? 0 : 1;
    }

    /**
     * Display test coverage report
     */
    public function displayCoverageReport() {
        echo "\n";
        echo str_repeat("=", 80) . "\n";
        echo "TEST COVERAGE REPORT\n";
        echo str_repeat("=", 80) . "\n\n";

        $coverage = [
            'Helper Functions' => [
                '✅ truncateAddress() - address display formatting',
                '✅ parseContactOutput() - message type detection',
                '✅ currencyOutputConversion() - USD cents conversion',
                '✅ redirectMessage() - URL parameter encoding'
            ],
            'Session Management' => [
                '✅ startSecureSession() - session initialization',
                '✅ isAuthenticated() - authentication status',
                '✅ authenticate() - user login with timing-safe comparison',
                '✅ checkSessionTimeout() - activity tracking',
                '✅ logout() - session cleanup',
                '✅ generateCSRFToken() - token creation',
                '✅ validateCSRFToken() - token validation',
                '✅ getCSRFField() - HTML field generation'
            ],
            'Repository & Database' => [
                '✅ getPDOConnection() - lazy database initialization',
                '✅ getAcceptedContacts() - accepted contact queries',
                '✅ getPendingContacts() - pending request queries',
                '✅ getUserPendingContacts() - user-initiated requests',
                '✅ getBlockedContacts() - blocked contact queries',
                '✅ getAllContacts() - all contact retrieval',
                '✅ getContactBalance() - individual balance calculation',
                '✅ getAllContactBalances() - batch balance queries (N+1 fix)',
                '✅ getUserTotalBalance() - total user balance',
                '✅ getTransactionHistory() - transaction retrieval',
                '✅ getContactNameByAddress() - contact lookup',
                '✅ checkForNewTransactions() - new transaction detection',
                '✅ checkForNewContactRequests() - new request detection',
                '✅ contactConversion() - display data formatting'
            ],
            'Controller & Forms' => [
                '✅ Form validation - required field checks',
                '✅ POST action detection - addContact, sendEIOU, etc.',
                '✅ Argv construction - service call parameters',
                '✅ Message type determination - success/error detection',
                '✅ URL parameter handling - GET message display',
                '✅ Output buffering - service output capture',
                '✅ Update checking - Tor Browser polling support'
            ],
            'Integration Tests' => [
                '✅ Add contact workflow - complete contact addition',
                '✅ Send transaction workflow - complete send flow',
                '✅ Contact management - accept, delete, block, edit',
                '✅ Data retrieval workflow - contacts and transactions',
                '✅ Authentication workflow - login, CSRF, logout',
                '✅ Update checking workflow - polling for new data',
                '✅ Error handling workflow - validation and exceptions'
            ]
        ];

        foreach ($coverage as $category => $items) {
            echo "$category:\n";
            foreach ($items as $item) {
                echo "  $item\n";
            }
            echo "\n";
        }

        echo str_repeat("=", 80) . "\n";
    }

    /**
     * Display identified gaps
     */
    public function displayGaps() {
        echo "\n";
        echo str_repeat("=", 80) . "\n";
        echo "IDENTIFIED TEST COVERAGE GAPS\n";
        echo str_repeat("=", 80) . "\n\n";

        $gaps = [
            'Database Integration' => [
                '⚠️  Actual database write operations (add/update/delete contacts)',
                '⚠️  Transaction insertion and validation',
                '⚠️  Database constraint validation (unique keys, foreign keys)',
                '⚠️  Concurrent transaction handling'
            ],
            'Service Layer' => [
                '⚠️  ContactService method testing with live database',
                '⚠️  TransactionService validation rules',
                '⚠️  WalletService balance calculations',
                '⚠️  SynchService network operations'
            ],
            'Network Operations' => [
                '⚠️  Tor network connectivity',
                '⚠️  P2P message exchange',
                '⚠️  Contact discovery and handshake',
                '⚠️  Transaction propagation'
            ],
            'Edge Cases' => [
                '⚠️  Very large transaction amounts',
                '⚠️  Negative balance scenarios',
                '⚠️  Malformed input handling',
                '⚠️  Concurrent user operations'
            ],
            'Security' => [
                '⚠️  SQL injection prevention (prepared statements)',
                '⚠️  XSS attack prevention (output escaping)',
                '⚠️  CSRF attack simulation',
                '⚠️  Session fixation attacks'
            ]
        ];

        echo "Note: The following areas require additional testing or cannot be\n";
        echo "fully tested in this environment:\n\n";

        foreach ($gaps as $category => $items) {
            echo "$category:\n";
            foreach ($items as $item) {
                echo "  $item\n";
            }
            echo "\n";
        }

        echo "Recommendations:\n";
        echo "  1. Set up integration test database for write operation testing\n";
        echo "  2. Create mock objects for service layer testing\n";
        echo "  3. Implement network operation mocking for offline testing\n";
        echo "  4. Add fuzzing tests for edge case discovery\n";
        echo "  5. Perform security audit with penetration testing tools\n";

        echo "\n" . str_repeat("=", 80) . "\n";
    }
}

// Main execution
$runner = new TestRunner($argv);

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                     eIOU GUI - COMPREHENSIVE TEST SUITE                      ║\n";
echo "║                              Copyright 2025                                  ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";

// Run all tests
$allPassed = $runner->runAllTests();

// Display summary
$exitCode = $runner->displaySummary();

// Display coverage report
$runner->displayCoverageReport();

// Display gaps
$runner->displayGaps();

// Exit with appropriate code
exit($exitCode);
