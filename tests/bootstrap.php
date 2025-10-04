<?php
/**
 * Test Bootstrap File
 * Sets up the testing environment
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define test constants
define('TEST_ROOT', __DIR__);
define('APP_ROOT', dirname(__DIR__));
define('SRC_ROOT', APP_ROOT . '/src');

// Include the SimpleTest framework
require_once TEST_ROOT . '/SimpleTest.php';

// Include composer autoloader if available
if (file_exists(APP_ROOT . '/vendor/autoload.php')) {
    require_once APP_ROOT . '/vendor/autoload.php';
}

// Set environment to testing
putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');

// Create mock database connection for testing
class MockPDO extends PDO {
    public function __construct() {
        // Don't call parent constructor
    }

    public function prepare($statement, $options = []) {
        return new MockPDOStatement();
    }

    public function query($statement, $mode = PDO::ATTR_DEFAULT_FETCH_MODE, ...$params) {
        return new MockPDOStatement();
    }

    public function exec($statement) {
        return 0;
    }
}

class MockPDOStatement {
    public function execute($params = []) {
        return true;
    }

    public function fetch($mode = PDO::FETCH_ASSOC) {
        return [];
    }

    public function fetchAll($mode = PDO::FETCH_ASSOC) {
        return [];
    }

    public function fetchColumn($column = 0) {
        return null;
    }

    public function rowCount() {
        return 0;
    }

    public function bindParam($param, &$var, $type = PDO::PARAM_STR) {
        return true;
    }

    public function bindValue($param, $value, $type = PDO::PARAM_STR) {
        return true;
    }
}

/**
 * Test case base class
 */
abstract class TestCase {
    protected $mockPDO;

    public function setUp() {
        $this->mockPDO = new MockPDO();
    }

    public function tearDown() {
        $this->mockPDO = null;
    }

    protected function assertContains($needle, $haystack, $message = '') {
        SimpleTest::assertStringContains($needle, $haystack, $message);
    }

    protected function assertNotContains($needle, $haystack, $message = '') {
        SimpleTest::assertStringNotContains($needle, $haystack, $message);
    }

    protected function assertTrue($condition, $message = '') {
        SimpleTest::assertTrue($condition, $message);
    }

    protected function assertFalse($condition, $message = '') {
        SimpleTest::assertTrue(!$condition, $message);
    }

    protected function assertEquals($expected, $actual, $message = '') {
        SimpleTest::assertEquals($expected, $actual, $message);
    }

    protected function assertNotEquals($expected, $actual, $message = '') {
        SimpleTest::assertTrue($expected !== $actual, $message ?: "Values should not be equal");
    }

    protected function assertNull($value, $message = '') {
        SimpleTest::assertNull($value, $message);
    }

    protected function assertNotNull($value, $message = '') {
        SimpleTest::assertNotNull($value, $message);
    }
}

/**
 * Helper function to create test database
 */
function createTestDatabase() {
    try {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create test tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                password TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY,
                sender_id INTEGER,
                receiver_id INTEGER,
                amount INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS contacts (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                contact_id INTEGER,
                status TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        return $pdo;
    } catch (PDOException $e) {
        echo "Failed to create test database: " . $e->getMessage() . "\n";
        return null;
    }
}

/**
 * Helper function to load fixtures
 */
function loadFixtures($pdo, $fixture) {
    $fixtureFile = TEST_ROOT . '/fixtures/' . $fixture . '.php';
    if (file_exists($fixtureFile)) {
        require $fixtureFile;
    }
}

// Set up test environment
if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', false);
}