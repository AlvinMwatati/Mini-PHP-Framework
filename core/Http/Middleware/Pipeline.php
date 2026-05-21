<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;

/**
 * Pipeline — the middleware chain runner.
 *
 * This is the most conceptually interesting class in the framework.
 * Understanding it deeply will teach you a lot about PHP closures,
 * higher-order functions, and the "onion" execution model.
 *
 * -------------------------------------------------------------------------
 * What it does:
 * -------------------------------------------------------------------------
 * Given a list of middleware classes and a "core" handler (the controller),
 * the Pipeline builds a chain of nested callables and runs the request
 * through it. Each layer can run code before AND after the next layer.
 *
 * -------------------------------------------------------------------------
 * The key technique: array_reduce with closures
 * -------------------------------------------------------------------------
 * We start with the innermost callable (the controller dispatcher) and
 * wrap it, one middleware at a time, from the INSIDE OUT.
 *
 * Imagine three middleware [A, B, C] and a controller:
 *
 *   After building the chain:
 *   A( B( C( controller ) ) )
 *
 *   When we call run($request), execution is:
 *   1. A::handle() runs its "before" code
 *   2. A calls $next($request) → enters B
 *   3. B::handle() runs its "before" code
 *   4. B calls $next($request) → enters C
 *   5. C::handle() runs its "before" code
 *   6. C calls $next($request) → enters controller
 *   7. Controller returns a Response
 *   8. C runs its "after" code, returns Response
 *   9. B runs its "after" code, returns Response
 *  10. A runs its "after" code, returns Response
 *
 * The result: A wraps B wraps C wraps the controller. Like an onion.
 *
 * -------------------------------------------------------------------------
 * Why array_reduce in reverse?
 * -------------------------------------------------------------------------
 * array_reduce collapses an array into a single value, left-to-right:
 *   [A, B, C] processed left-to-right means A is applied LAST (outermost).
 *
 * But we want A to run FIRST (outermost), so we reverse the array:
 *   array_reverse([A, B, C]) = [C, B, A]
 *   Then reducing [C, B, A] wraps in order: C(controller), B(C(...)), A(B(...))
 *   So A is the outermost layer — runs first on request, last on response.
 *
 * This is pure functional composition. The same pattern exists in
 * Haskell (function composition), JavaScript (Redux middleware),
 * and Python (decorators).
 */
class Pipeline
{
    /**
     * The list of middleware to run, as class name strings.
     * They will be resolved (instantiated) when the pipeline runs.
     *
     * @var string[]
     */
    private array $middleware = [];

    /**
     * Set the middleware stack.
     *
     * @param  string[] $middleware  Array of fully-qualified class names
     * @return static
     */
    public function through(array $middleware): static
    {
        $this->middleware = $middleware;

        return $this;
    }

    /**
     * Run the request through the pipeline to a destination callable.
     *
     * $destination is the innermost handler — the thing at the centre of
     * the onion. In our case it's a closure that calls the route handler.
     *
     * @param  Request  $request      The incoming HTTP request
     * @param  callable $destination  fn(Request): Response — the core handler
     * @return Response
     */
    public function run(Request $request, callable $destination): Response
    {
        // Build the onion from the inside out.
        $chain = $this->buildChain($destination);

        // Now run the request through the outermost layer.
        return $chain($request);
    }

    /**
     * Build the nested callable chain from the middleware stack.
     *
     * This is where the magic happens. We use array_reduce to fold the
     * middleware array into a single callable that represents the full chain.
     *
     * The "carry" (accumulator) starts as $destination (the controller).
     * Each iteration wraps the current carry in a new layer.
     *
     * After reduction, the final value is a callable that, when called
     * with a Request, runs the entire onion and returns a Response.
     */
    private function buildChain(callable $destination): callable
    {
        // We reverse so that the first middleware in the array ends up
        // as the outermost wrapper (runs first on request in, last on response out).
        $middleware = array_reverse($this->middleware);

        // array_reduce signature: (array, callable(carry, item): carry, initialValue)
        //
        // $carry  = the chain built so far (starts as $destination, i.e. the controller)
        // $class  = the current middleware class name string
        //
        // Each iteration: wrap the existing $carry in a new closure that,
        // when called, instantiates the middleware and calls handle() with
        // the request and the existing $carry as $next.
        return array_reduce(
            $middleware,
            fn(callable $carry, string $class): callable => $this->wrap($carry, $class),
            $destination
        );
    }

    /**
     * Wrap a callable ($next) inside a middleware layer.
     *
     * Returns a new callable. When called with a Request:
     *   1. Instantiates the middleware class
     *   2. Calls middleware->handle($request, $next)
     *   3. Returns whatever handle() returns
     *
     * This returned callable IS the $next for the layer outside it.
     *
     * Why instantiate here (lazily) instead of in buildChain?
     * Because if an outer middleware short-circuits (returns early),
     * inner middleware classes are never instantiated at all — zero wasted work.
     *
     * In Phase 6 (service container), `new $class()` will become
     * `$this->container->make($class)` so middleware can have dependencies
     * injected automatically.
     */
    private function wrap(callable $next, string $class): callable
    {
        return function (Request $request) use ($next, $class): Response {
            if (!class_exists($class)) {
                throw new \RuntimeException("Middleware class [{$class}] not found.");
            }

            $middleware = new $class();

            if (!$middleware instanceof MiddlewareInterface) {
                throw new \RuntimeException(
                    "Middleware [{$class}] must implement " . MiddlewareInterface::class
                );
            }

            return $middleware->handle($request, $next);
        };
    }
}