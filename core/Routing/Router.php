<?php

declare(strict_types=1);

namespace Core\Routing;

use Core\Http\Middleware\Pipeline;
use Core\Http\Request;
use Core\Http\Response;

/**
 * The Router — matches incoming requests to route handlers and
 * runs them through any registered middleware.
 */
class Router
{
    /** @var Route[] */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $namedRoutes = [];

    private ?Route $currentRoute = null;

    /**
     * Global middleware applied to EVERY route.
     * @var string[]
     */
    private array $globalMiddleware = [];

    // =========================================================================
    // Route registration
    // =========================================================================

    public function get(string $pattern, mixed $handler): PendingRoute
    {
        return $this->addRoute(['GET', 'HEAD'], $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): PendingRoute
    {
        return $this->addRoute(['POST'], $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): PendingRoute
    {
        return $this->addRoute(['PUT'], $pattern, $handler);
    }

    public function patch(string $pattern, mixed $handler): PendingRoute
    {
        return $this->addRoute(['PATCH'], $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): PendingRoute
    {
        return $this->addRoute(['DELETE'], $pattern, $handler);
    }

    public function any(string $pattern, mixed $handler): PendingRoute
    {
        return $this->addRoute(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], $pattern, $handler);
    }

    public function match(array $methods, string $pattern, mixed $handler): PendingRoute
    {
        return $this->addRoute(array_map('strtoupper', $methods), $pattern, $handler);
    }

    /**
     * Register a group of routes that share a prefix and/or middleware.
     *
     * @param array{prefix?: string, middleware?: string[]} $attributes
     */
    public function group(array $attributes, callable $callback): void
    {
        $prefix     = $attributes['prefix']     ?? '';
        $middleware = $attributes['middleware'] ?? [];

        $countBefore = count($this->routes);
        $callback($this);
        $newRoutes = array_slice($this->routes, $countBefore);

        foreach ($newRoutes as $index => $route) {
            $routeIndex = $countBefore + $index;

            $newPattern = rtrim($prefix, '/') . '/' . ltrim($route->pattern, '/');
            $newPattern = rtrim($newPattern, '/') ?: '/';

            $this->routes[$routeIndex] = new Route(
                methods:    $route->methods,
                pattern:    $newPattern,
                handler:    $route->handler,
                middleware: array_merge($middleware, $route->middleware),
                name:       $route->name,
            );

            if ($route->name !== null) {
                $this->namedRoutes[$route->name] = $this->routes[$routeIndex];
            }
        }
    }

    // =========================================================================
    // Middleware registration
    // =========================================================================

    /**
     * Register a middleware class to run on every single request.
     *
     * Call this from Application::bootstrap() to apply framework-wide
     * middleware (logging, security headers, etc.).
     *
     * Order matters: the first middleware added is the OUTERMOST layer
     * (first to receive the request, last to see the response).
     */
    public function addGlobalMiddleware(string $middlewareClass): void
    {
        $this->globalMiddleware[] = $middlewareClass;
    }

    // =========================================================================
    // Dispatching — the per-request lifecycle
    // =========================================================================

    /**
     * Dispatch an incoming request through middleware to the route handler.
     *
     * The pipeline is:
     *   global middleware → route middleware → controller
     *
     * Both layers use the same Pipeline class. We just pass different
     * middleware arrays to it.
     */
    public function dispatch(Request $request): Response
    {
        $uri    = $request->getUri();
        $method = $request->getMethod();

        $uriMatched = false;

        foreach ($this->routes as $route) {
            $params = $route->match($uri);

            if ($params === null) {
                continue;
            }

            $uriMatched = true;

            if (!$route->acceptsMethod($method)) {
                continue;
            }

            $this->currentRoute = $route;

            // The "destination" — the innermost callable at the centre of the onion.
            // It receives the final $request and calls the actual route handler.
            $destination = fn(Request $req): Response => $this->callHandler(
                $route->handler,
                $req,
                $params
            );

            // Build the full middleware stack:
            //   global middleware + route-specific middleware
            // Route middleware runs INSIDE global middleware (closer to the controller).
            $middlewareStack = array_merge($this->globalMiddleware, $route->middleware);

            // Run the request through the pipeline.
            return (new Pipeline())
                ->through($middlewareStack)
                ->run($request, $destination);
        }

        if ($uriMatched) {
            return $this->methodNotAllowed($uri, $method);
        }

        return $this->notFound($uri);
    }

    // =========================================================================
    // Handler resolution
    // =========================================================================

    private function callHandler(mixed $handler, Request $request, array $params): Response
    {
        $result = match (true) {
            $handler instanceof \Closure
                => $handler($request, $params),

            is_array($handler) && count($handler) === 2
                => $this->callControllerArray($handler, $request, $params),

            is_string($handler) && str_contains($handler, '@')
                => $this->callControllerString($handler, $request, $params),

            default => throw new \RuntimeException(
                sprintf('Invalid route handler: %s', get_debug_type($handler))
            )
        };

        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        throw new \RuntimeException(sprintf(
            'Route handler must return a Response, string, or array. Got: %s',
            get_debug_type($result)
        ));
    }

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
    // URL generation
    // =========================================================================

    public function route(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \InvalidArgumentException("Named route [{$name}] is not defined.");
        }

        $pattern = $this->namedRoutes[$name]->pattern;

        foreach ($params as $key => $value) {
            $pattern = str_replace(
                ['{' . $key . '}', '{' . $key . '?}'],
                (string) $value,
                $pattern
            );
        }

        $pattern = preg_replace('#/\{[^}]+\?\}#', '', $pattern);

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

    private function addRoute(array $methods, string $pattern, mixed $handler): PendingRoute
    {
        $route = new Route(
            methods: $methods,
            pattern: $pattern,
            handler: $handler,
        );

        $index          = count($this->routes);
        $this->routes[] = $route;

        return new PendingRoute($this, $index);
    }

    public function registerNamedRoute(string $name, Route $route): void
    {
        $this->namedRoutes[$name] = $route;
    }

    /**
     * Get the Route stored at a specific index (used by PendingRoute).
     */
    public function getRouteAt(int $index): Route
    {
        return $this->routes[$index];
    }

    /**
     * Replace the Route stored at a specific index (used by PendingRoute).
     */
    public function replaceRouteAt(int $index, Route $route): void
    {
        $this->routes[$index] = $route;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function currentRoute(): ?Route
    {
        return $this->currentRoute;
    }

    public function getGlobalMiddleware(): array
    {
        return $this->globalMiddleware;
    }
}