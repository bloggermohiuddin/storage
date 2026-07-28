<?php

declare(strict_types=1);

namespace StoragePlatform\API;

use StoragePlatform\Server\AuthService;
use StoragePlatform\Server\Database;

/**
 * Router — lightweight routing engine for APIs and front controller rendering.
 */
class Router
{
    protected array $routes = [];
    protected array $globalMiddlewares = [];

    public function get(string $path, $handler, array $middlewares = []): void
    {
        $this->add('GET', $path, $handler, $middlewares);
    }

    public function post(string $path, $handler, array $middlewares = []): void
    {
        $this->add('POST', $path, $handler, $middlewares);
    }

    public function put(string $path, $handler, array $middlewares = []): void
    {
        $this->add('PUT', $path, $handler, $middlewares);
    }

    public function delete(string $path, $handler, array $middlewares = []): void
    {
        $this->add('DELETE', $path, $handler, $middlewares);
    }

    protected function add(string $method, string $path, $handler, array $middlewares = []): void
    {
        // Convert route like /api/buckets/{id} to regex /api/buckets/([^/]+)
        $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([^/]+)', $path);
        $pattern = '~^' . $pattern . '$~';

        // Extract parameter names from route
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $path, $paramMatches);
        $paramNames = $paramMatches[1] ?? [];

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'paramNames' => $paramNames,
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
    }

    public function dispatch(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        
        // Strip trailing slash if present (except for root /)
        if ($requestUri !== '/' && str_ends_with($requestUri, '/')) {
            $requestUri = rtrim($requestUri, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            if (preg_match($route['pattern'], $requestUri, $matches)) {
                array_shift($matches); // remove full match

                // Build associative array of route params
                $params = [];
                foreach ($route['paramNames'] as $index => $name) {
                    if (isset($matches[$index])) {
                        $params[$name] = urldecode($matches[$index]);
                    }
                }

                // Initialize core dependencies
                $db = Database::getConnection();
                $authService = new AuthService($db);

                // Run Middlewares (e.g. auth validation)
                foreach ($route['middlewares'] as $middleware) {
                    if ($middleware === 'auth') {
                        $user = $authService->authenticateRequest();
                        if (!$user) {
                            $this->json(['error' => 'Unauthorized access denied.'], 401);
                            return;
                        }
                        // Save user in global request context if needed
                        $_REQUEST['user'] = $user;
                    }
                }

                // Execute handler
                $handler = $route['handler'];
                if (is_array($handler) && count($handler) === 2) {
                    [$class, $method] = $handler;
                    $controllerInstance = new $class($db);
                    call_user_func_array([$controllerInstance, $method], [$params]);
                } else {
                    call_user_func($handler, $params);
                }
                return;
            }
        }

        // Default: 404 Route Not Found
        if (str_starts_with($requestUri, '/api/')) {
            $this->json(['error' => 'API endpoint not found.'], 404);
        } else {
            // Render basic web index if no api route matches
            $this->renderDashboard();
        }
    }

    public function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    protected function renderDashboard(): void
    {
        // If user is authenticated, serve dashboard layout, else serve login page
        $db = Database::getConnection();
        $auth = new AuthService($db);
        $user = $auth->validateSession();

        if ($user) {
            require dirname(__DIR__, 2) . '/public/views/dashboard.php';
        } else {
            require dirname(__DIR__, 2) . '/public/views/login.php';
        }
        exit;
    }
}
