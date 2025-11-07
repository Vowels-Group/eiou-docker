<?php
/**
 * MVC Bootstrap
 *
 * Initializes the MVC architecture with dependency injection container
 *
 * Copyright 2025
 */

namespace Eiou\Gui;

require_once __DIR__ . '/../core/Application.php';
require_once __DIR__ . '/../services/ServiceContainer.php';
require_once __DIR__ . '/../services/utilities/UtilityServiceContainer.php';
require_once __DIR__ . '/../core/UserContext.php';
require_once __DIR__ . '/includes/session.php';

use Application;
use ServiceContainer;
use UtilityServiceContainer;
use UserContext;
use Session;

/**
 * Bootstrap class for MVC initialization
 *
 * Provides dependency injection container and service initialization
 */
class Bootstrap
{
    /**
     * @var Bootstrap|null Singleton instance
     */
    private static ?Bootstrap $instance = null;

    /**
     * @var Application Application instance
     */
    private Application $app;

    /**
     * @var ServiceContainer Service container
     */
    private ServiceContainer $serviceContainer;

    /**
     * @var UtilityServiceContainer Utility service container
     */
    private UtilityServiceContainer $utilityContainer;

    /**
     * @var UserContext User context
     */
    private UserContext $user;

    /**
     * @var Session Session manager
     */
    private Session $session;

    /**
     * @var array Controller instances cache
     */
    private array $controllers = [];

    /**
     * @var array Model instances cache
     */
    private array $models = [];

    /**
     * Private constructor for singleton pattern
     */
    private function __construct()
    {
        $this->initializeServices();
    }

    /**
     * Get singleton instance
     *
     * @return Bootstrap
     */
    public static function getInstance(): Bootstrap
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize all services
     *
     * @return void
     */
    private function initializeServices(): void
    {
        // Initialize Application
        $this->app = Application::getInstance();
        $this->app->loadserviceContainer();

        // Initialize ServiceContainer
        $this->serviceContainer = $this->app->services;

        // Initialize UtilityServiceContainer
        $this->utilityContainer = UtilityServiceContainer::getInstance($this->serviceContainer);

        // Initialize UserContext
        $this->user = UserContext::getInstance();

        // Initialize Session
        $this->session = new Session();
    }

    /**
     * Get Application instance
     *
     * @return Application
     */
    public function getApp(): Application
    {
        return $this->app;
    }

    /**
     * Get ServiceContainer instance
     *
     * @return ServiceContainer
     */
    public function getServiceContainer(): ServiceContainer
    {
        return $this->serviceContainer;
    }

    /**
     * Get UtilityServiceContainer instance
     *
     * @return UtilityServiceContainer
     */
    public function getUtilityContainer(): UtilityServiceContainer
    {
        return $this->utilityContainer;
    }

    /**
     * Get UserContext instance
     *
     * @return UserContext
     */
    public function getUser(): UserContext
    {
        return $this->user;
    }

    /**
     * Get Session instance
     *
     * @return Session
     */
    public function getSession(): Session
    {
        return $this->session;
    }

    /**
     * Get or create controller instance
     *
     * @param string $controllerName Controller class name (without namespace)
     * @return object Controller instance
     * @throws \RuntimeException If controller class doesn't exist
     */
    public function getController(string $controllerName): object
    {
        // Check cache first
        if (isset($this->controllers[$controllerName])) {
            return $this->controllers[$controllerName];
        }

        // Build full class name
        $controllerClass = "Eiou\\Gui\\Controllers\\{$controllerName}";

        // Check if class exists (without namespace for backward compatibility)
        if (!class_exists($controllerClass)) {
            $controllerFile = __DIR__ . "/controllers/{$controllerName}.php";
            if (file_exists($controllerFile)) {
                require_once $controllerFile;
            }
        }

        // Instantiate controller with dependency injection
        $controller = $this->createControllerInstance($controllerName);

        // Cache the instance
        $this->controllers[$controllerName] = $controller;

        return $controller;
    }

    /**
     * Create controller instance with dependency injection
     *
     * @param string $controllerName Controller class name
     * @return object Controller instance
     * @throws \RuntimeException If controller class doesn't exist
     */
    private function createControllerInstance(string $controllerName): object
    {
        // Map controller names to their dependencies
        $dependencies = [
            'ContactController' => [
                $this->session,
                $this->serviceContainer->getContactService()
            ],
            'TransactionController' => [
                $this->session,
                $this->serviceContainer->getContactService(),
                $this->serviceContainer->getTransactionService()
            ],
            'WalletController' => [
                $this->session,
                $this->serviceContainer,
                $this->utilityContainer,
                $this->user
            ]
        ];

        if (!isset($dependencies[$controllerName])) {
            throw new \RuntimeException("Unknown controller: {$controllerName}");
        }

        // Check if class exists in global namespace (backward compatibility)
        if (class_exists($controllerName)) {
            return new $controllerName(...$dependencies[$controllerName]);
        }

        // Try with namespace
        $controllerClass = "Eiou\\Gui\\Controllers\\{$controllerName}";
        if (class_exists($controllerClass)) {
            return new $controllerClass(...$dependencies[$controllerName]);
        }

        throw new \RuntimeException("Controller class not found: {$controllerName}");
    }

    /**
     * Get or create model instance
     *
     * @param string $modelName Model class name (without namespace)
     * @return object Model instance
     * @throws \RuntimeException If model class doesn't exist
     */
    public function getModel(string $modelName): object
    {
        // Check cache first
        if (isset($this->models[$modelName])) {
            return $this->models[$modelName];
        }

        // Build full class name
        $modelClass = "Eiou\\Gui\\Models\\{$modelName}";

        // Load model file
        $modelFile = __DIR__ . "/models/{$modelName}.php";
        if (file_exists($modelFile)) {
            require_once $modelFile;
        }

        if (!class_exists($modelClass)) {
            throw new \RuntimeException("Model class not found: {$modelName}");
        }

        // Instantiate model with service container
        $model = new $modelClass($this->serviceContainer);

        // Cache the instance
        $this->models[$modelName] = $model;

        return $model;
    }

    /**
     * Handle authentication check
     *
     * @return bool True if authenticated, false otherwise
     */
    public function isAuthenticated(): bool
    {
        return $this->session->isAuthenticated();
    }

    /**
     * Handle authentication POST request
     *
     * @return bool True if authentication successful
     */
    public function handleAuthentication(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['authcode'])) {
            $submittedAuthCode = $_POST['authcode'];

            if ($this->user->has('authcode') &&
                $this->session->authenticate($submittedAuthCode, $this->user->getAuthCode())) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle logout request
     *
     * @return void
     */
    public function handleLogout(): void
    {
        if (isset($_GET['logout'])) {
            $this->session->logout();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    /**
     * Check session timeout
     *
     * @return bool True if session is valid
     */
    public function checkSessionTimeout(): bool
    {
        return $this->session->checkSessionTimeout();
    }

    /**
     * Get CSRF token
     *
     * @return string CSRF token
     */
    public function getCSRFToken(): string
    {
        return $this->session->getCSRFToken();
    }

    /**
     * Verify CSRF token for POST requests
     *
     * @return void
     */
    public function verifyCSRFToken(): void
    {
        $this->session->verifyCSRFToken();
    }
}
