<?php

declare(strict_types=1);

namespace Core\Routing;

/**
 * PendingRoute — a fluent wrapper returned from router registration methods.
 *
 * The problem it solves:
 * When you call $router->get('/path', $handler), the Router adds a Route
 * to its internal array and returns it. If the Route is immutable (readonly
 * properties), calling $route->withMiddleware([...]) returns a NEW Route
 * object — but the Router still holds the OLD one.
 *
 * PendingRoute fixes this by holding a reference to both:
 *   - The Router itself (so it can update the stored route)
 *   - The index of the Route in the Router's array
 *
 * When you chain ->withMiddleware() or ->name(), PendingRoute calls back
 * into the Router to replace the stored route with the updated version.
 *
 * This is how Laravel's RouteRegistrar works under the hood.
 *
 * Usage (unchanged from the developer's perspective):
 *   $router->get('/users/{id}', [UserController::class, 'show'])
 *          ->name('users.show')
 *          ->withMiddleware([AuthMiddleware::class]);
 */
class PendingRoute
{
    public function __construct(
        private Router $router,
        private int    $routeIndex,
    ) {}

    /**
     * Add middleware to this route.
     * Updates the stored Route in the Router's array.
     */
    public function withMiddleware(array $middleware): static
    {
        $current = $this->router->getRouteAt($this->routeIndex);
        $updated = $current->withMiddleware($middleware);
        $this->router->replaceRouteAt($this->routeIndex, $updated);

        return $this;
    }

    /**
     * Give this route a name for URL generation.
     */
    public function name(string $name): static
    {
        $current = $this->router->getRouteAt($this->routeIndex);
        $updated = $current->withName($name);
        $this->router->replaceRouteAt($this->routeIndex, $updated);
        $this->router->registerNamedRoute($name, $updated);

        return $this;
    }

    /**
     * Forward any unknown method calls to the underlying Route.
     * This makes PendingRoute transparent — it behaves like a Route
     * for read operations (accessing properties, calling methods).
     */
    public function __get(string $name): mixed
    {
        return $this->router->getRouteAt($this->routeIndex)->$name;
    }
}