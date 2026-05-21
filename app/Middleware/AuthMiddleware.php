<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

/**
 * AuthMiddleware — blocks unauthenticated requests.
 *
 * This is a pure "before" middleware. It inspects the request before
 * passing it inward. If the request fails the check, it returns a
 * Response immediately — $next is never called. The controller and
 * all inner middleware layers are completely skipped.
 *
 * This is the middleware pattern at its most powerful: the ability to
 * short-circuit the entire inner pipeline without the controller
 * knowing anything about authentication at all.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * How Bearer token auth works:
 * ──────────────────────────────────────────────────────────────────────────
 * The client sends: Authorization: Bearer <token>
 * We extract the token, validate it, and either pass through or reject.
 *
 * In a real app the token would be a signed JWT (JSON Web Token) or
 * a database-backed session token. Here we keep it simple so the
 * middleware PATTERN stays front and centre.
 *
 * Usage in routes/web.php:
 *   use App\Middleware\AuthMiddleware;
 *
 *   $router->group(['middleware' => [AuthMiddleware::class]], function($router) {
 *       $router->get('/dashboard', [DashboardController::class, 'index']);
 *   });
 *
 *   // Or on a single route:
 *   $route = $router->get('/profile', [ProfileController::class, 'show']);
 *   // (Route-level middleware is wired in the dispatch() method)
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * Tokens we consider valid.
     * In a real app: verify a JWT signature or query the database.
     */
    private array $validTokens = [
        'secret-token-123',
        'secret-token-456',
    ];

    public function handle(Request $request, callable $next): Response
    {
        // ── BEFORE: check the token ───────────────────────────────────────
        $token = $this->extractToken($request);

        if ($token === null) {
            return $this->unauthorised('No token provided. Send: Authorization: Bearer <token>');
        }

        if (!$this->isValid($token)) {
            return $this->unauthorised('Invalid or expired token.');
        }

        // ── PASS INWARD ───────────────────────────────────────────────────
        // Token is valid. We could attach the authenticated user to the
        // request here, but since Request is immutable we'd need a
        // "wither" method. In Phase 6 (container) we'll see a better pattern.
        $response = $next($request);

        // ── AFTER: nothing to add ─────────────────────────────────────────
        // Auth middleware typically only acts on the way in.
        return $response;
    }

    /**
     * Extract the Bearer token from the Authorization header.
     *
     * The Authorization header looks like:
     *   Authorization: Bearer abc123xyz
     *
     * We split on the space and return the second part.
     * Returns null if the header is missing or not a Bearer token.
     */
    private function extractToken(Request $request): ?string
    {
        $header = $request->header('authorization');

        if ($header === null) {
            return null;
        }

        // Header must start with "Bearer " (case-insensitive)
        if (!str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        // Trim whitespace in case the client sent extra spaces
        $token = trim(substr($header, 7)); // "Bearer " is 7 chars

        return $token !== '' ? $token : null;
    }

    /**
     * Check whether a token is valid.
     *
     * Uses hash_equals() for constant-time comparison.
     * This prevents timing attacks — an attacker can't measure
     * how many characters of their guess were correct by timing
     * how long the comparison takes.
     *
     * === or strcmp() return early on the first mismatch — different
     * tokens take different amounts of time. hash_equals() always
     * takes the same time regardless of where the strings differ.
     */
    private function isValid(string $token): bool
    {
        foreach ($this->validTokens as $validToken) {
            if (hash_equals($validToken, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a 401 Unauthorized response.
     *
     * The WWW-Authenticate header tells the client what auth scheme
     * the server expects. It's part of the HTTP spec (RFC 7235).
     * API clients can read this to know they need to send a Bearer token.
     */
    private function unauthorised(string $message): Response
    {
        return Response::json(
            data:   ['error' => 'Unauthorised', 'message' => $message],
            status: 401,
        )->withHeader('WWW-Authenticate', 'Bearer realm="MiniFramework"');
    }
}