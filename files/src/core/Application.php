<?php
# Copyright 2025-2026 Vowels Group, LLC

namespace Eiou\Core;

use Eiou\Utils\Logger;
use Eiou\Utils\InputValidator;
use Eiou\Utils\Security;
use Eiou\Security\KeyEncryption;
use Eiou\Cli\CliOutputManager;
use Eiou\Services\ServiceContainer;
use Eiou\Services\PluginLoader;
use Eiou\Services\NodeRestartService;
use Eiou\Services\RateLimiterService;
use Eiou\Processors\CleanupMessageProcessor;
use Eiou\Processors\P2pMessageProcessor;
use Eiou\Processors\TransactionMessageProcessor;
use Eiou\Processors\ContactStatusProcessor;
use PDO;
use Exception;
use RuntimeException;
use function Eiou\Database\createPDOConnection;
use function Eiou\Database\freshInstall;
use function Eiou\Database\runMigrations;

/**
 * Application singleton to manage global state
 * Replaces global variables with proper encapsulation
 */

class Application {
    private static ?Application $instance = null;

    /**
     * @var UserContext object of user data
     */
    protected $currentUser;

    /**
     * @var DbContext Database context instance
     */
    protected $currentDatabase;

    /**
     * @var PDO Database connection instance
     */
    protected $pdo;
    
    /**
     * @var ServiceContainer object of service container
     */
    public $services;

    /**
     * @var UtilityServiceContainer UtilityServiceContainer instance
     */
    public $utilityServices;

    /**
     * @var array Cached processor instances
     */
    public array $processors = [];

    /**
     * @var array Cached util instances
     */
    public array $utils = [];

    /**
     * @var PluginLoader|null Plugin discovery and lifecycle manager
     */
    public ?PluginLoader $pluginLoader = null;

    /**
     * Private constructor for singleton pattern
     *
     * @throws RuntimeException If database setup fails during initialization
     */
    private function __construct() {
        // Get logger wrapper
        $this->getLogger();

        // Fail fast if the linked OpenSSL lacks secp256k1. The wallet keypair,
        // every signature, and every encrypted payload depend on this curve —
        // a node without it cannot parse any peer's public key and is
        // effectively isolated. Rather than boot into a broken half-network
        // mode, refuse to start and point operators at the fix.
        \Eiou\Security\BIP39::getPreferredCurve();

        // Setup database
        if(!file_exists('/etc/eiou/config/dbconfig.json')){
            // Performs a fresh installation of the eIOU system by creating db configuration files, database, and necessary tables
            try {
                $this->constructDatabase();
                $this->loadCurrentDatabase();
            } catch (RuntimeException $e) {
                if ($this->loggerLoaded()) {
                    $this->getLogger()->critical("Application: Database setup failed", [
                        'error' => $e->getMessage()
                    ]);
                } else {
                    Logger::getInstance()->logException($e, [], 'CRITICAL');
                }
                // If database setup fails, we cannot continue initialization
                throw new RuntimeException("Failed to initialize application: " . $e->getMessage(), 0, $e);
            }
        } elseif(!$this->currentDatabaseLoaded()){
            // Get DatabaseContext instance
            $this->loadCurrentDatabase();
        }

        // Start PDO connection
        $this->getDatabase();

        // Encrypt plaintext dbPass if present (one-time migration)
        $this->migrateDbConfigEncryption();

        // Run migrations for existing databases to add new tables
        $this->runMigrations();

        // Migrate default config to add any new configurable keys
        $this->migrateDefaultConfig();

        // Setup user config
        if(file_exists('/etc/eiou/config/userconfig.json') && !$this->currentUserLoaded()){
            // Get UserContext instance
            $this->loadCurrentUser();
            // Get ServiceContainer instance
            $this->loadserviceContainer();
            $this->loadUtilityServiceContainer();
            // Discover plugins and run their register() phase BEFORE wireAllServices
            // so plugins can add services that participate in dependency wiring.
            $this->pluginLoader = new PluginLoader();
            // Wire isolation services before setEnabled() can be called from
            // CLI/REST/GUI. These fire the CREATE USER / GRANT / REVOKE DDL
            // on every enable/disable when the plugin's manifest declares
            // `database.user: true`. See docs/PLUGINS.md (Database Isolation).
            $this->pluginLoader->setIsolationServices(
                $this->services->getPluginCredentialService(),
                $this->services->getPluginDbUserService()
            );
            // Wire the signature verifier with the configured mode. Default
            // 'off' keeps backwards compat; operators opt in via Constants
            // (will move to userconfig.json in a follow-up once other
            // runtime-configurable plugin settings land).
            $this->pluginLoader->setSignatureVerifier(
                $this->services->getPluginSignatureVerifier(),
                \Eiou\Core\Constants::PLUGIN_SIGNATURE_MODE
            );
            $this->pluginLoader->discover();
            // Reconcile MySQL users / grants against the on-disk manifest +
            // plugin_credentials table. Self-heals after a mysql-data volume
            // rebuild, a manual DROP USER, or an operator db_limits edit in
            // plugins.json. Runs before registerAll() so plugins needing DB
            // access during register() find their user ready.
            $this->pluginLoader->reconcileIsolation();
            $this->pluginLoader->registerAll($this->services);
            // Wire circular dependencies between services
            $this->services->wireAllServices();
            // Plugin boot() runs after wiring so all core services are available
            // when plugins subscribe to events or decorate services.
            $this->pluginLoader->bootAll($this->services);

            // Run transaction recovery only for CLI/daemon processes (not HTTP API requests)
            // This prevents unnecessary recovery runs and potential race conditions on every API call
            // Recovery is only needed when message processor daemons start up after a crash
            if ($this->isCli()) {
                $this->runTransactionRecovery();
            }
        }
    }

    /**
     * Get singleton instance
     *
     * @return Application
     */
    public static function getInstance(): Application {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create Database
     */
    private function constructDatabase(): void {
        freshInstall();
    }

    /**
     * Get database connection (lazy loaded)
     *
     * @return void
     */
    private function getDatabase(): void {
        $maxAttempts = 5;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->pdo = createPDOConnection();
                return;
            } catch (RuntimeException $e) {
                // "Database configuration incomplete" — likely a race with another process
                // writing the encrypted dbconfig.json on first boot. Re-read the file and
                // update DatabaseContext before retrying.
                if ($attempt < $maxAttempts) {
                    usleep(250000 * $attempt); // 250ms, 500ms, 750ms, 1s linear back-off
                    $configPath = '/etc/eiou/config/dbconfig.json';
                    if (file_exists($configPath)) {
                        $raw = @file_get_contents($configPath);
                        $decoded = ($raw !== false) ? json_decode($raw, true) : null;
                        if (is_array($decoded) && !empty($decoded)) {
                            DatabaseContext::getInstance()->setdatabaseData($decoded);
                        }
                    }
                } else {
                    Logger::getInstance()->logException($e, [], 'ERROR');
                }
            } catch (Exception $e) {
                Logger::getInstance()->logException($e, [], 'ERROR');
                return;
            }
        }
    }

    /**
     * Migrate plaintext database credentials to encrypted format in dbconfig.json.
     *
     * freshInstall() writes plaintext to avoid master-key timing issues during
     * initial setup. This migration encrypts dbPass, dbUser, and dbName once
     * the master key is stable and the PDO connection has proven the credentials
     * work. Idempotent — skips fields that are already encrypted.
     */
    private function migrateDbConfigEncryption(): void {
        $configPath = '/etc/eiou/config/dbconfig.json';
        if (!file_exists($configPath)) {
            return;
        }

        $raw = file_get_contents($configPath);
        if ($raw === false) {
            return;
        }
        $config = json_decode($raw, true);
        if (!is_array($config)) {
            return;
        }

        // Check if there's anything to migrate
        $hasPlaintextPass = isset($config['dbPass']);
        $hasPlaintextUser = isset($config['dbUser']) && !isset($config['dbUserEncrypted']);
        $hasPlaintextName = isset($config['dbName']) && !isset($config['dbNameEncrypted']);

        if (!$hasPlaintextPass && !$hasPlaintextUser && !$hasPlaintextName) {
            return; // Already encrypted — nothing to do
        }

        try {
            // Encrypt dbPass (if still plaintext)
            if ($hasPlaintextPass) {
                $config['dbPassEncrypted'] = KeyEncryption::encrypt($config['dbPass']);
                unset($config['dbPass']);
            }

            // Encrypt dbUser
            if ($hasPlaintextUser) {
                $config['dbUserEncrypted'] = KeyEncryption::encrypt($config['dbUser'], 'db_user');
                unset($config['dbUser']);
            }

            // Encrypt dbName
            if ($hasPlaintextName) {
                $config['dbNameEncrypted'] = KeyEncryption::encrypt($config['dbName'], 'db_name');
                unset($config['dbName']);
            }

            // Write to a temp file then rename — atomic on Linux, so concurrent
            // readers always see either the old complete file or the new one, never a partial write.
            $tmpPath = $configPath . '.tmp.' . getmypid();
            $oldUmask = umask(0027);
            file_put_contents($tmpPath, json_encode($config));
            umask($oldUmask);
            chmod($tmpPath, 0640);
            // chgrp requires root; skip when already running as www-data
            if (posix_getuid() === 0) {
                chgrp($tmpPath, 'www-data');
            }
            rename($tmpPath, $configPath);

            // Reload DatabaseContext so subsequent reads use encrypted version
            DatabaseContext::getInstance()->setdatabaseData($config);

            if ($this->loggerLoaded()) {
                $this->getLogger()->info("Migrated dbconfig.json: database credentials encrypted");
            }
        } catch (Exception $e) {
            // Non-fatal on first boot: the master key doesn't exist yet because
            // the wallet hasn't been generated. Wallet::generateWallet() and
            // Wallet::restoreWallet() handle this migration immediately after
            // initMasterKeyFromSeed(), so the plaintext credentials are encrypted
            // before the container becomes operational.
            if ($this->loggerLoaded()) {
                $this->getLogger()->warning("dbconfig.json encryption migration deferred — will complete during wallet setup", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Run database migrations to add new tables
     * This is idempotent - safe to run on every startup
     */
    private function runMigrations(): void {
        if ($this->pdo === null) {
            return;
        }

        try {
            $results = runMigrations($this->pdo);

            // Log any newly created tables
            foreach ($results as $table => $status) {
                if ($status === 'created') {
                    if ($this->loggerLoaded()) {
                        $this->getLogger()->info("Migration: Created table $table");
                    }
                }
            }
        } catch (Exception $e) {
            if ($this->loggerLoaded()) {
                $this->getLogger()->warning("Migration failed", [
                    'error' => $e->getMessage()
                ]);
            }
            // Don't throw - migrations failing shouldn't prevent app startup
        }
    }

    /**
     * Migrate default config to add new configurable keys.
     * Adds any keys from getConfigurableDefaults() that are missing from
     * defaultconfig.json without overwriting existing user values. Idempotent.
     */
    private function migrateDefaultConfig(): void {
        $configFile = '/etc/eiou/config/defaultconfig.json';
        if (!file_exists($configFile)) {
            return;
        }

        $config = json_decode(file_get_contents($configFile), true);
        if (!is_array($config)) {
            return;
        }

        $defaults = UserContext::getConfigurableDefaults();
        $changed = false;
        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $config)) {
                $config[$key] = $defaultValue;
                $changed = true;
            }
        }

        if ($changed) {
            file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }

    /**
     * Run transaction recovery to handle stuck transactions from previous crashes
     *
     * This method recovers transactions that were in 'sending' status when the
     * process crashed. It resets them to 'pending' for retry or marks them for
     * manual review if they've exceeded max retry attempts.
     */
    private function runTransactionRecovery(): void {
        if (!isset($this->services)) {
            return;
        }

        try {
            $recoveryService = $this->services->getTransactionRecoveryService();
            $results = $recoveryService->recoverStuckTransactions();

            // Log recovery results
            if ($results['recovered'] > 0 || $results['needs_review'] > 0) {
                if ($this->loggerLoaded()) {
                    $this->getLogger()->info("Transaction recovery completed on startup", [
                        'recovered' => $results['recovered'],
                        'needs_review' => $results['needs_review'],
                        'errors' => $results['errors']
                    ]);
                }

                // Output to console for visibility during startup
                if (function_exists('output')) {
                    if ($results['recovered'] > 0) {
                        output("[Recovery] Recovered {$results['recovered']} stuck transactions", 'INFO');
                    }
                    if ($results['needs_review'] > 0) {
                        output("[Recovery] {$results['needs_review']} transactions need manual review", 'WARNING');
                    }
                }
            }
        } catch (Exception $e) {
            if ($this->loggerLoaded()) {
                $this->getLogger()->warning("Transaction recovery failed on startup", [
                    'error' => $e->getMessage()
                ]);
            }
            // Don't throw - recovery failing shouldn't prevent app startup
        }
    }

    /**
     * Check if database connection has been started
     *
     * @return bool
     */
    public function currentPdoLoaded(): bool {
        return(isset($this->pdo) && $this->pdo instanceof PDO);
    }

    /**
     * Set database connection (for testing)
     *
     * @param PDO $pdo
     * @return void
     */
    public function setDatabase($pdo): void {
        $this->pdo = $pdo;
    }

    /**
     * Check if DatabaseContext has been loaded
     *
     * @return bool
     */
    public function currentDatabaseLoaded(): bool {
        return(isset($this->currentDatabase) && $this->currentDatabase !== []);
    }

    /**
     * Load current database config
     *
     * @return void
     */
    private function loadCurrentDatabase(): void {
        $this->currentDatabase = DatabaseContext::getInstance();
    }


    /**
     * Check if userContext has been loaded
     *
     * @return bool
     */
    public function currentUserLoaded(): bool {
        return(isset($this->currentUser) && $this->currentUser !== []);
    }

    /**
     * Load current user config
     *
     * @return void
     */
    public function loadCurrentUser(): void {
        $this->currentUser = UserContext::getInstance();
    }

    /**
     * Get public key from loaded user
     *
     * @return string|null
     */
    public function getPublicKey(): ?string {
        if($this->currentUserLoaded()){
            return $this->currentUser->getPublicKey();
        }
        return null;
    }

    /**
     * Get public key hash from loaded user
     *
     * @return string|null
     */
    public function getPublicKeyHash(): ?string {
        if($this->currentUserLoaded()){
            return $this->currentUser->getPublicKeyHash();
        }
        return null;
    }

    /**
     * Generate Wallet from CLI input
     *
     * @param array $argv CLI input
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function generateWallet(array $argv, ?CliOutputManager $output = null): void {
        Wallet::generateWallet($argv, $output);
    }

    /**
     * Restore Wallet from CLI input
     *
     * @param array $argv CLI input
     * @param CliOutputManager|null $output Optional output manager for JSON support
     * @return void
     */
    public function restoreWallet(array $argv, ?CliOutputManager $output = null): void {
        Wallet::restoreWallet($argv, $output);
    }

    /**
     * Load services from serviceContainer
     *
     * @return void
     */
    public function loadserviceContainer(): void {
        $this->services = ServiceContainer::getInstance($this->currentUser, $this->pdo);
    }

    /**
     * Load utility services from ServiceContainer
     *
     * @return void
     */
    public function loadUtilityServiceContainer(): void {
        $this->utilityServices = $this->services->getUtilityContainer();
    }

    /**
     * Get rate limiter service instance
     *
     * @return RateLimiterService
     */
    public function getRateLimiter(): RateLimiterService {
        return $this->services->getRateLimiterService();
    }

    /**
     * Get InputValidator instance
     *
     *
     * @return InputValidator
     */
    public function getInputValidator(): InputValidator {
        if (!isset($this->utils['InputValidator'])) {
            $this->utils['InputValidator'] = new InputValidator();
        }
        return $this->utils['InputValidator'];
    }

    /**
     * Check if Logger has been loaded
     *
     * @return bool
     */
    public function loggerLoaded(): bool {
        return isset($this->utils['Logger']);
    }

    /**
     * Get logger instance
     *
     * @return Logger
     */
    public function getLogger(): Logger {
        if (!isset($this->utils['Logger'])) {
            Logger::init(Constants::LOG_FILE_APP, Constants::LOG_LEVEL);
            $this->utils['Logger'] = Logger::getInstance();
        }
        return $this->utils['Logger'];
    }

    /**
     * Get Security instance
     *
     * @return Security
     */
    public function getSecurity(): Security {
        if (!isset($this->utils['Security'])) {
            $this->utils['Security'] = new Security();
        }
        return $this->utils['Security'];
    }

    /**
     * Get CleanupMessageProcessor instance
     *
     * @return CleanupMessageProcessor
     */
    public function getCleanupMessageProcessor(): CleanupMessageProcessor {
         if (!isset($this->processors['cleanupProcessor'])) {
            $this->processors['cleanupProcessor'] = new CleanupMessageProcessor();
         }
         return $this->processors['cleanupProcessor'];
    }
    /**
     * Get P2pMessageProcessor instance
     *
     * @return P2pMessageProcessor
     */
    public function getP2pMessageProcessor(): P2pMessageProcessor {
         if (!isset($this->processors['p2pProcessor'])) {
            $this->processors['p2pProcessor'] = new P2pMessageProcessor();
         }
         return $this->processors['p2pProcessor'];
    }

    /**
     * Get TransactionMessageProcessor instance
     *
     * @return TransactionMessageProcessor
     */
    public function getTransactionMessageProcessor(): TransactionMessageProcessor {
          if (!isset($this->processors['transactionProcessor'])) {
            $this->processors['transactionProcessor'] = new TransactionMessageProcessor();
         }
         return $this->processors['transactionProcessor'];
    }

    /**
     * Get ContactStatusProcessor instance
     *
     * @return ContactStatusProcessor
     */
    public function getContactStatusProcessor(): ContactStatusProcessor {
         if (!isset($this->processors['contactStatusProcessor'])) {
            $this->processors['contactStatusProcessor'] = new ContactStatusProcessor();
         }
         return $this->processors['contactStatusProcessor'];
    }

    /**
     * Register a service
     *
     * @deprecated Use ServiceContainer::registerService() instead via $app->services->registerService()
     * @param string $name
     * @param object|callable $service
     * @return void
     */
    public function registerService($name, $service): void {
        // Delegate to ServiceContainer
        if ($this->services instanceof ServiceContainer) {
            $this->services->registerService($name, $service);
        }
    }

    /**
     * Get a service
     *
     * @deprecated Use ServiceContainer::getService() instead via $app->services->getService()
     * @param string $name
     * @return mixed
     */
    public function getService($name): mixed {
        // Delegate to ServiceContainer
        if ($this->services instanceof ServiceContainer) {
            return $this->services->getService($name);
        }
        return null;
    }

    /**
     * Check if running in CLI mode
     *
     * @return bool
     */
    public function isCli(): bool {
        return php_sapi_name() === 'cli';
    }

    /**
     * Check if in development mode
     *
     * @return bool
     */
    public function isDevelopment(): bool {
        return Constants::getAppEnv() === 'development';
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public function isDebug(): bool {
        return Constants::isDebug();
    }

    /**
     * Get application root path
     *
     * @return string
     */
    public function getRootPath(): string {
        return dirname(__DIR__, 2);
    }

    /**
     * Get config directory path
     *
     * @return string
     */
    public function getConfigPath(): string {
        return Constants::PATH_CONFIG_DIR ?: '/etc/eiou/config';
    }

    /**
     * Reload processor configs
     *
     * @return void
     */
    public function reloadProcessors(): void {
        $items = glob('/tmp/' . '*.pid');
        foreach ($items as $item) {
            if (is_file($item)) {
                posix_kill(trim(file_get_contents($item)), SIGHUP);
            }
        }
    }

    /**
     * Clean up resources
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function shutdown(?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        // Set shutdown flag to prevent watchdog from restarting processors
        $shutdownFlag = '/tmp/eiou_shutdown.flag';
        file_put_contents($shutdownFlag, (string)time());

        $pidFiles = glob('/tmp/' . '*.pid');
        $processesTerminated = 0;

        foreach ($pidFiles as $item) {
            if (is_file($item)) {
                $pid = trim(file_get_contents($item));
                if (posix_kill((int)$pid, SIGTERM)) {
                    $processesTerminated++;
                }
                unlink($item);
            }
        }

        $this->processors = [];
        if ($this->services) {
            $this->services->getUtilityContainer()->clearUtilities();
            $this->services->clearServices();
        }
        $this->utils = [];
        $this->currentUser = null;
        if ($this->pdo) {
            $this->pdo = null;
        }

        $output->success("Application shutdown complete", [
            'processes_terminated' => $processesTerminated,
            'pid_files_cleaned' => count($pidFiles),
            'resources_released' => true
        ], "All resources have been released");
    }

    /**
     * Full node restart: respawn PHP-FPM workers AND background processors so
     * startup-bound state (plugin subscriptions, freshly wired services, etc.)
     * picks up changes without a container reboot.
     *
     * Delegates to NodeRestartService for the actual signaling — see that
     * class for the wire-level details and its unit tests for coverage.
     *
     * Caller permissions: signaling the PHP-FPM master requires running as the
     * same UID as the master (root inside the container). The CLI runs as root,
     * so `eiou restart` works. Calling this from a PHP-FPM worker (GUI/API)
     * would fail to signal the master and is not currently supported.
     *
     * @param CliOutputManager|null   $output  Optional output manager for JSON support
     * @param NodeRestartService|null $service Inject for testing; defaults to a fresh instance
     * @return array{processors_terminated:int, fpm_reloaded:bool, fpm_master_pid:?int}
     */
    public function restart(?CliOutputManager $output = null, ?NodeRestartService $service = null): array {
        $output = $output ?? CliOutputManager::getInstance();
        $service = $service ?? new NodeRestartService();

        $result = $service->restart();

        if ($result['fpm_reloaded']) {
            $output->success(
                "Node restart initiated",
                $result,
                "Processors will respawn within ~30s. PHP-FPM workers are reloading gracefully."
            );
        } else {
            $output->success(
                "Partial restart: processors restarted, PHP-FPM reload skipped",
                $result,
                "Could not signal PHP-FPM master (PID lookup or permission failure). " .
                "Run as root inside the container, or restart the container manually."
            );
        }

        return $result;
    }

    /**
     * Start processors after a previous shutdown
     *
     * Removes the shutdown flag so the watchdog resumes monitoring and
     * restarts processors on its next cycle.
     *
     * @param CliOutputManager|null $output Optional output manager for JSON support
     */
    public function start(?CliOutputManager $output = null): void {
        $output = $output ?? CliOutputManager::getInstance();

        $shutdownFlag = '/tmp/eiou_shutdown.flag';
        $wasShutdown = file_exists($shutdownFlag);

        if (!$wasShutdown) {
            $output->success("Processors are already running", [
                'shutdown_flag_present' => false,
                'action' => 'none'
            ], "No shutdown flag found — processors are already active");
            return;
        }

        unlink($shutdownFlag);

        $output->success("Processor restart initiated", [
            'shutdown_flag_removed' => true,
            'action' => 'watchdog_will_restart'
        ], "Shutdown flag removed. The watchdog will restart all processors within 30 seconds");
    }

    /**
     * Prevent cloning (singleton pattern)
     *
     * @return void
     */
    private function __clone(): void {}

    /**
     * Prevent unserialization (singleton pattern)
     *
     * @throws Exception Always throws to prevent unserialization
     */
    public function __wakeup(): void {
        throw new Exception("Cannot unserialize singleton");
    }
}