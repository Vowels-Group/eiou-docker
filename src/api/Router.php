<?php
# Copyright 2025

/**
 * API Router
 *
 * Handles HTTP request routing to appropriate controller methods.
 *
 * @package API
 */
class Router {
    /**
     * @var array Registered routes
     */
    private array $routes = [];

    /**
     * @var ApiController Controller instance
     */
    private ApiController $controller;

    /**
     * Constructor
     *
     * @param ApiController $controller Controller instance
     */
    public function __construct(ApiController $controller) {
        $this->controller = $controller;
        $this->registerRoutes();
    }

    /**
     * Register API routes
     *
     * @return void
     */
    private function registerRoutes(): void {
        // Authentication
        $this->post('/auth', 'authenticate');

        // Wallet endpoints (require auth)
        $this->get('/wallet/info', 'getWalletInfo', true);
        $this->get('/wallet/balance', 'getBalance', true);
        $this->post('/wallet/send', 'sendTransaction', true);

        // Contact endpoints (require auth)
        $this->get('/contacts', 'listContacts', true);
        $this->post('/contacts', 'addContact', true);
        $this->get('/contacts/:address', 'getContact', true);
        $this->put('/contacts/:address', 'updateContact', true);
        $this->delete('/contacts/:address', 'deleteContact', true);

        // Transaction endpoints (require auth)
        $this->get('/transactions', 'listTransactions', true);
        $this->get('/transactions/history', 'getTransactionHistory', true);
        $this->get('/transactions/sent', 'getSentTransactions', true);
        $this->get('/transactions/received', 'getReceivedTransactions', true);
        $this->get('/transactions/:txid', 'getTransaction', true);

        // Health check (no auth required)
        $this->get('/health', 'healthCheck', false);
    }

    /**
     * Register GET route
     *
     * @param string $path Route path
     * @param string $method Controller method
     * @param bool $requireAuth Whether authentication is required
     * @return void
     */
    private function get(string $path, string $method, bool $requireAuth = false): void {
        $this->addRoute('GET', $path, $method, $requireAuth);
    }

    /**
     * Register POST route
     *
     * @param string $path Route path
     * @param string $method Controller method
     * @param bool $requireAuth Whether authentication is required
     * @return void
     */
    private function post(string $path, string $method, bool $requireAuth = false): void {
        $this->addRoute('POST', $path, $method, $requireAuth);
    }

    /**
     * Register PUT route
     *
     * @param string $path Route path
     * @param string $method Controller method
     * @param bool $requireAuth Whether authentication is required
     * @return void
     */
    private function put(string $path, string $method, bool $requireAuth = false): void {
        $this->addRoute('PUT', $path, $method, $requireAuth);
    }

    /**
     * Register DELETE route
     *
     * @param string $path Route path
     * @param string $method Controller method
     * @param bool $requireAuth Whether authentication is required
     * @return void
     */
    private function delete(string $path, string $method, bool $requireAuth = false): void {
        $this->addRoute('DELETE', $path, $method, $requireAuth);
    }

    /**
     * Add route to registry
     *
     * @param string $httpMethod HTTP method
     * @param string $path Route path
     * @param string $controllerMethod Controller method
     * @param bool $requireAuth Whether authentication is required
     * @return void
     */
    private function addRoute(string $httpMethod, string $path, string $controllerMethod, bool $requireAuth): void {
        $this->routes[] = [
            'method' => $httpMethod,
            'path' => $path,
            'controller_method' => $controllerMethod,
            'require_auth' => $requireAuth
        ];
    }

    /**
     * Match route against registered routes
     *
     * @param string $method HTTP method
     * @param string $path Request path
     * @return array|null Matched route with params or null
     */
    private function matchRoute(string $method, string $path): ?array {
        // Normalize path
        $path = '/' . trim($path, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            // Convert route pattern to regex
            $pattern = $this->routeToRegex($route['path']);

            // Try to match
            if (preg_match($pattern, $path, $matches)) {
                // Extract route parameters
                $params = $this->extractParams($route['path'], $matches);

                return [
                    'controller_method' => $route['controller_method'],
                    'require_auth' => $route['require_auth'],
                    'params' => $params
                ];
            }
        }

        return null;
    }

    /**
     * Convert route path to regex pattern
     *
     * @param string $path Route path
     * @return string Regex pattern
     */
    private function routeToRegex(string $path): string {
        // Escape forward slashes
        $pattern = preg_quote($path, '/');

        // Replace :param with named capture group
        $pattern = preg_replace('/\\\\:([a-zA-Z0-9_]+)/', '(?P<$1>[^/]+)', $pattern);

        return '/^' . $pattern . '$/';
    }

    /**
     * Extract route parameters from matches
     *
     * @param string $routePath Original route path
     * @param array $matches Regex matches
     * @return array Parameters
     */
    private function extractParams(string $routePath, array $matches): array {
        $params = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Dispatch request to appropriate controller
     *
     * @return void
     */
    public function dispatch(): void {
        // Get request method
        $method = $_SERVER['REQUEST_METHOD'];

        // Get request path (remove /api prefix and query string)
        $requestUri = $_SERVER['REQUEST_URI'];
        $path = parse_url($requestUri, PHP_URL_PATH);

        // Remove /api prefix if present
        $path = preg_replace('/^\/api/', '', $path);

        // Match route
        $route = $this->matchRoute($method, $path);

        if (!$route) {
            ResponseFormatter::notFound('API endpoint not found');
            return;
        }

        // Check authentication if required
        if ($route['require_auth']) {
            $authMiddleware = new AuthMiddleware(ServiceContainer::getInstance()->getCurrentUser());
            if (!$authMiddleware->requireAuth()) {
                // requireAuth() already sent the response
                return;
            }
        }

        // Call controller method
        $controllerMethod = $route['controller_method'];
        $params = $route['params'];

        try {
            if (!method_exists($this->controller, $controllerMethod)) {
                ResponseFormatter::serverError('Controller method not found: ' . $controllerMethod);
                return;
            }

            // Call the controller method with params
            call_user_func([$this->controller, $controllerMethod], $params);

        } catch (Exception $e) {
            error_log('API Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            ResponseFormatter::serverError('An error occurred while processing your request', $e);
        }
    }

    /**
     * Get registered routes (for debugging)
     *
     * @return array All registered routes
     */
    public function getRoutes(): array {
        return $this->routes;
    }
}
