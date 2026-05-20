<?php

declare(strict_types=1);

namespace Core\Routing;

use Core\Http\Request;
use Core\Http\Response;

/**
 * The Router — the framework's traffic director.
 *
 * The Router does two distinct jobs:
 *
 *   1. REGISTRATION: Accept route definitions from routes/web.php
 *      $router->get('/users', [UserController::class, 'index']);
 *      $router->post('/users', [UserController::class, 'store']);
 *
 *   2. DISPATCHING: Given a real HTTP request, find the matching route
 *      and call its handler, returning a Response.
 *
 * These two jobs happen at different times:
 *   - Registration happens at boot (index.php startup)
 *   - Dispatching happens per-request (once per HTTP request)
 *
 * The dispatch() method is where the request lifecycle really begins.
 * Everything before this is setup; this is where your actual app runs.
 *
 * Method-chaining API:
 * The registration methods (get, post, etc.) return $this, allowing
 * fluent usage but the cleaner pattern here is they return the Route
 * object so you can chain .name() or .middleware() on the route:
 *
 *   $router->get('/users', [UserController::class, 'index'])
 *          ->name('users.index');
 */
class Router
{
    /**
     * All registered routes.
     * @var Route[]
     */
    private array $routes = [];

    /**
     * Named routes for URL generation.
     * @var array<string, Route>
     */
    private array $namedRoutes = [];

    /**
     * The route that was matched for the current request.
     * Stored here so middleware can inspect it.
     */
    private ?Route $currentRoute = null;

    // =========================================================================
    // Route registration — the public API for routes/web.php
    // =========================================================================

    /**
     * Register a GET route. Also registers HEAD (HTTP spec says GET handlers
     * must respond to HEAD — same as GET but no body in the response).
     */
    public function get(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $pattern, $handler);
    }

    /**
     * Register a POST route. Used for creating resources or submitting forms.
     */
    public function post(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['POST'], $pattern, $handler);
    }

    /**
     * Register a PUT route. Used for full-update of a resource.
     */
    public function put(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['PUT'], $pattern, $handler);
    }

    /**
     * Register a PATCH route. Used for partial-update of a resource.
     */
    public function patch(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['PATCH'], $pattern, $handler);
    }

    /**
     * Register a DELETE route. Used for deleting resources.
     */
    public function delete(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['DELETE'], $pattern, $handler);
    }

    /**
     * Register a route that responds to any HTTP method.
     */
    public function any(string $pattern, mixed $handler): Route
    {
        return $this->addRoute(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], $pattern, $handler);
    }

    /**
     * Register a route for specific methods.
     *
     * Usage: $router->match(['GET', 'POST'], '/form', [FormController::class, 'handle']);
     */
    public function match(array $methods, string $pattern, mixed $handler): Route
    {
        return $this->addRoute(
            array_map('strtoupper', $methods),
            $pattern,
            $handler
        );
    }

    /**
     * Register a group of routes that share a prefix and/or middleware.
     *
     * Usage:
     *   $router->group(['prefix' => '/api', 'middleware' => ['auth']], function($router) {
     *       $router->get('/users', [UserController::class, 'index']);
     *       $router->post('/users', [UserController::class, 'store']);
     *   });
     *
     * The callback receives $this (the router), so nested registrations
     * apply the group's prefix and middleware automatically.
     *
     * @param array{prefix?: string, middleware?: string[]} $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $prefix     = $attributes['prefix']     ?? '';
        $middleware = $attributes['middleware'] ?? [];

        // We record routes added during $callback by counting before and after.
        $countBefore = count($this->routes);

        // Run the callback — all route registrations inside it happen now.
        $callback($this);

        // Apply the group's prefix and middleware to every route added
        // during the callback. We slice the routes array to get just the new ones.
        $newRoutes = array_slice($this->routes, $countBefore);

        foreach ($newRoutes as $index => $route) {
            $routeIndex = $countBefore + $index;

            // Prepend the prefix to each route's pattern.
            // rtrim/ltrim ensures no double slashes.
            $newPattern = rtrim($prefix, '/') . '/' . ltrim($route->pattern, '/');
            $newPattern = rtrim($newPattern, '/') ?: '/'; // Ensure root stays /

            // Build a new Route with the prefixed pattern and merged middleware.
            $this->routes[$routeIndex] = new Route(
                methods:    $route->methods,
                pattern:    $newPattern,
                handler:    $route->handler,
                middleware: array_merge($middleware, $route->middleware),
                name:       $route->name,
            );

            // Update named routes index if this route has a name.
            if ($route->name !== null) {
                $this->namedRoutes[$route->name] = $this->routes[$routeIndex];
            }
        }
    }

    // =========================================================================
    // Dispatching — the per-request lifecycle
    // =========================================================================

    /**
     * Dispatch an incoming request to the appropriate route handler.
     *
     * This is the core of the router. It:
     *   1. Loops through all registered routes
     *   2. Finds one whose pattern matches the request URI
     *   3. Checks the HTTP method is allowed
     *   4. Resolves and calls the handler
     *   5. Returns whatever the handler returned (must be a Response)
     *
     * If no route matches → 404 Not Found
     * If a route matches but wrong method → 405 Method Not Allowed
     *
     * @throws \RuntimeException if the handler returns something other than a Response
     */
    public function dispatch(Request $request): Response
    {
        $uri    = $request->getUri();
        $method = $request->getMethod();

        // Track whether any route matched the URI (regardless of method).
        // This lets us distinguish 404 (no URI match) from 405 (URI matched
        // but wrong method) — an important distinction for API clients.
        $uriMatched = false;

        foreach ($this->routes as $route) {
            // Try to match the URI pattern. Returns null if no match,
            // or an array of named captures if it matches.
            $params = $route->match($uri);

            if ($params === null) {
                continue; // This route's pattern doesn't match the URI
            }

            $uriMatched = true;

            // The URI matched — now check the HTTP method.
            if (!$route->acceptsMethod($method)) {
                continue; // Method not allowed — keep looking
            }

            // We have a full match! Store it and call the handler.
            $this->currentRoute = $route;

            return $this->callHandler($route->handler, $request, $params);
        }

        // No full match found. Return 404 or 405.
        if ($uriMatched) {
            // We found the URI but not the method — 405
            return $this->methodNotAllowed($uri, $method);
        }

        return $this->notFound($uri);
    }

    /**
     * Resolve and call a route handler.
     *
     * Handlers can be one of three formats:
     *
     *   1. A Closure:
     *      $router->get('/', function(Request $request) { return Response::html('Hello'); });
     *
     *   2. An array [ControllerClass, 'method']:
     *      $router->get('/users', [UserController::class, 'index']);
     *
     *   3. A string 'ControllerClass@method':
     *      $router->get('/users', 'UserController@index');
     *      (older Laravel-style syntax, less common with modern PHP)
     *
     * In all cases we inject the Request as the first argument, then
     * the named route parameters (e.g. ['id' => '42']).
     *
     * @param array<string, string> $params Named captures from the URI pattern
     * @throws \RuntimeException if the handler format is unrecognised
     * @throws \RuntimeException if the handler doesn't return a Response
     */
    private function callHandler(mixed $handler, Request $request, array $params): Response
    {
        $result = match (true) {
            // Closure / anonymous function
            $handler instanceof \Closure => $handler($request, $params),

            // [ControllerClass::class, 'method'] array
            is_array($handler) && count($handler) === 2 => $this->callControllerArray($handler, $request, $params),

            // 'ControllerClass@method' string
            is_string($handler) && str_contains($handler, '@') => $this->callControllerString($handler, $request, $params),

            default => throw new \RuntimeException(
                sprintf(
                    'Invalid route handler. Expected Closure, [Class, method] array, or "Class@method" string. Got: %s',
                    get_debug_type($handler)
                )
            )
        };

        // The handler MUST return a Response. If it returned something else
        // (a string, null, etc.) we wrap it or throw — fail loudly.
        if ($result instanceof Response) {
            return $result;
        }

        // Convenience: if a handler returns a plain string, wrap it in HTML.
        if (is_string($result)) {
            return Response::html($result);
        }

        // Convenience: if a handler returns an array, wrap it as JSON.
        if (is_array($result)) {
            return Response::json($result);
        }

        throw new \RuntimeException(sprintf(
            'Route handler must return a Response, string, or array. Got: %s',
            get_debug_type($result)
        ));
    }

    /**
     * Handle a [ControllerClass::class, 'method'] handler.
     *
     * We instantiate the controller and call the method.
     * In Phase 6 (service container), we'll replace `new $class()`
     * with container resolution so dependencies are auto-injected.
     */
    private function callControllerArray(array $handler, Request $request, array $params): mixed
    {
        [$class, $method] = $handler;

        if (!class_exists($class)) {
            throw new \RuntimeException("Controller class [{$class}] not found.");
        }

        $controller = new $class();

        if (!method_exists($controller, $method)) {
            throw new \RuntimeException("Method [{$method}] not found on [{$class}].");
        }

        return $controller->$method($request, $params);
    }

    /**
     * Handle a 'ControllerClass@method' string handler.
     */
    private function callControllerString(string $handler, Request $request, array $params): mixed
    {
        [$class, $method] = explode('@', $handler, 2);

        return $this->callControllerArray([$class, $method], $request, $params);
    }

    // =========================================================================
    // Error responses
    // =========================================================================

    private function notFound(string $uri): Response
    {
        return Response::html(
            body: sprintf(
                '<h1>404 — Not Found</h1><p>No route found for <code>%s</code></p>',
                htmlspecialchars($uri, ENT_QUOTES, 'UTF-8')
            ),
            status: 404
        );
    }

    private function methodNotAllowed(string $uri, string $method): Response
    {
        return Response::html(
            body: sprintf(
                '<h1>405 — Method Not Allowed</h1><p><code>%s %s</code> is not allowed.</p>',
                htmlspecialchars($method, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($uri, ENT_QUOTES, 'UTF-8')
            ),
            status: 405
        );
    }

    // =========================================================================
    // URL generation — build URLs for named routes
    // =========================================================================

    /**
     * Generate a URL for a named route.
     *
     * Usage:
     *   // Route registered as: $router->get('/users/{id}', ...)->name('users.show')
     *   $router->route('users.show', ['id' => 42]); // → '/users/42'
     *
     * This is how you avoid hardcoding URLs throughout your app. If the route
     * pattern changes, you only change it in routes/web.php — all generated
     * URLs update automatically.
     *
     * @param array<string, mixed> $params Values to fill in for {placeholder}s
     */
    public function route(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Named route [{$name}] is not defined.");
        }

        $pattern = $this->namedRoutes[$name]->pattern;

        // Replace each {placeholder} with the corresponding param value.
        foreach ($params as $key => $value) {
            $pattern = str_replace(
                ['{' . $key . '}', '{' . $key . '?}'],
                (string) $value,
                $pattern
            );
        }

        // If any optional placeholders remain unreplaced, remove them.
        $pattern = preg_replace('#/\{[^}]+\?\}#', '', $pattern);

        // If any required placeholders remain, the caller forgot a param.
        if (preg_match('#\{[^}]+\}#', $pattern)) {
            throw new \InvalidArgumentException(
                "Missing parameters for route [{$name}]. Pattern: {$pattern}"
            );
        }

        return $pattern;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * The actual addRoute implementation — all public methods call this.
     */
    private function addRoute(array $methods, string $pattern, mixed $handler): Route
    {
        $route = new Route(
            methods: $methods,
            pattern: $pattern,
            handler: $handler,
        );

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Register a named route.
     * Called internally when a Route's name is set.
     */
    public function registerNamedRoute(string $name, Route $route): void
    {
        $this->namedRoutes[$name] = $route;
    }

    /**
     * Get all registered routes (useful for debugging/testing).
     * @return Route[]
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get the currently matched route.
     */
    public function currentRoute(): ?Route
    {
        return $this->currentRoute;
    }
}