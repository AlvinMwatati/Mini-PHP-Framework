<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

/**
 * ThrottleMiddleware — simple rate limiting per IP address.
 *
 * Another pure "before" middleware. It counts how many times an IP
 * has hit a route within a time window, and blocks them with a
 * 429 Too Many Requests if they exceed the limit.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * Important note on storage:
 * ──────────────────────────────────────────────────────────────────────────
 * This implementation uses a static property as an in-memory store.
 * This means the counter resets every time PHP starts (every request
 * in the default FPM model), making it not production-suitable.
 *
 * A real rate limiter would use:
 *   - Redis (INCR + EXPIRE commands — atomic, fast, distributed)
 *   - Memcached
 *   - APCu (shared memory within one server process)
 *   - A database (slower but universal)
 *
 * The middleware STRUCTURE here is correct — only the storage backend
 * would change. That's the beauty of middleware: swap the internals,
 * keep the interface.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * Usage — applying middleware per-route in routes/web.php:
 * ──────────────────────────────────────────────────────────────────────────
 *
 *   // This is how the Router will wire per-route middleware (Phase 3 final step):
 *   $router->get('/api/data', [ApiController::class, 'data'])
 *          ->withMiddleware([ThrottleMiddleware::class]);
 */
class ThrottleMiddleware implements MiddlewareInterface
{
    /**
     * Maximum requests allowed per IP within the time window.
     */
    private int $maxRequests;

    /**
     * Time window in seconds.
     */
    private int $windowSeconds;

    /**
     * In-memory hit counter: ['ip:window_start' => count]
     *
     * static so it persists across multiple middleware instantiations
     * within the same PHP request (e.g. if used in multiple route groups).
     *
     * @var array<string, array{count: int, reset_at: int}>
     */
    private static array $hits = [];

    public function __construct(int $maxRequests = 60, int $windowSeconds = 60)
    {
        $this->maxRequests   = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    public function handle(Request $request, callable $next): Response
    {
        // ── BEFORE: check the rate limit ──────────────────────────────────
        $ip  = $request->ip();
        $key = $ip;
        $now = time();

        // Initialise or reset the window for this IP if expired
        if (
            !isset(self::$hits[$key]) ||
            self::$hits[$key]['reset_at'] <= $now
        ) {
            self::$hits[$key] = [
                'count'    => 0,
                'reset_at' => $now + $this->windowSeconds,
            ];
        }

        self::$hits[$key]['count']++;
        $count   = self::$hits[$key]['count'];
        $resetAt = self::$hits[$key]['reset_at'];

        if ($count > $this->maxRequests) {
            return $this->tooManyRequests($resetAt);
        }

        // ── PASS INWARD ───────────────────────────────────────────────────
        $response = $next($request);

        // ── AFTER: add rate-limit info headers to the response ────────────
        // These are standard headers API clients use to display rate limit
        // information to developers. You see them on GitHub's API, Twitter's, etc.
        return $response
            ->withHeader('X-RateLimit-Limit',     (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining',  (string) max(0, $this->maxRequests - $count))
            ->withHeader('X-RateLimit-Reset',      (string) $resetAt);
    }

    /**
     * Build a 429 Too Many Requests response.
     *
     * The Retry-After header tells the client how many seconds to wait.
     * RFC 6585 defines the 429 status code and recommends this header.
     */
    private function tooManyRequests(int $resetAt): Response
    {
        $retryAfter = max(0, $resetAt - time());

        return Response::json(
            data: [
                'error'       => 'Too Many Requests',
                'message'     => "Rate limit exceeded. Try again in {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ],
            status: 429,
        )->withHeader('Retry-After', (string) $retryAfter);
    }
}