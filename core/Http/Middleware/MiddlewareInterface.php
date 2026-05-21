<?php

declare(strict_types=1);

namespace Core\Http\Middleware;

use Core\Http\Request;
use Core\Http\Response;

/**
 * MiddlewareInterface — the contract every middleware must implement.
 *
 * This is inspired by PSR-15 (HTTP Server Middleware) but simplified so
 * you can see exactly what's happening without the PSR ceremony.
 *
 * The real PSR-15 splits this into two interfaces:
 *   - MiddlewareInterface::process(Request, RequestHandlerInterface): Response
 *   - RequestHandlerInterface::handle(Request): Response
 *
 * We collapse both into one interface where $next IS the handler:
 * a callable that takes a Request and returns a Response. This is
 * simpler to understand and is how Laravel, Slim, and many others work.
 *
 * The signature of $next is: function(Request): Response
 *
 * A minimal no-op middleware:
 *
 *   public function handle(Request $request, callable $next): Response
 *   {
 *       return $next($request); // Just pass through — do nothing
 *   }
 *
 * A middleware that adds a header to every response:
 *
 *   public function handle(Request $request, callable $next): Response
 *   {
 *       $response = $next($request); // Let the rest of the app run
 *       return $response->withHeader('X-Powered-By', 'MiniFramework');
 *   }
 *
 * A middleware that blocks the request before it reaches the controller:
 *
 *   public function handle(Request $request, callable $next): Response
 *   {
 *       if (!$this->isAuthorised($request)) {
 *           return Response::html('Forbidden', 403); // Never calls $next
 *       }
 *       return $next($request);
 *   }
 */
interface MiddlewareInterface
{
    /**
     * Handle a request and return a response.
     *
     * @param Request  $request  The incoming request
     * @param callable $next     The next layer — call this to pass inward.
     *                           Its signature is: fn(Request): Response
     *
     * @return Response
     */
    public function handle(Request $request, callable $next): Response;
}