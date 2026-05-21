<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

/**
 * LoggingMiddleware — logs every request and its response time.
 *
 * This is the textbook "before and after" middleware.
 * It demonstrates both sides of the $next($request) call clearly.
 *
 * Before $next: record the start time and log the incoming request.
 * After  $next: compute elapsed time, log the status code + duration.
 *
 * Notice how simple this is — the middleware has no idea what's inside
 * the rest of the pipeline. It just measures time around a black box.
 *
 * In a real app you'd write to a PSR-3 logger (Monolog etc.) instead
 * of error_log(), but the structure is identical.
 */
class LoggingMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // ── BEFORE ────────────────────────────────────────────────────────
        $startTime = hrtime(true); // Nanoseconds — more precise than microtime()

        $this->logRequest($request);

        // ── PASS INWARD ───────────────────────────────────────────────────
        // Everything inside this call — other middleware, the controller —
        // runs while we are "paused" on this line.
        $response = $next($request);

        // ── AFTER (response travels back out through us) ──────────────────
        $elapsedMs = (hrtime(true) - $startTime) / 1_000_000; // ns → ms

        $this->logResponse($request, $response, $elapsedMs);

        return $response;
    }

    private function logRequest(Request $request): void
    {
        error_log(sprintf(
            '[REQUEST]  %s %s',
            $request->getMethod(),
            $request->getUri(),
        ));
    }

    private function logResponse(Request $request, Response $response, float $elapsedMs): void
    {
        error_log(sprintf(
            '[RESPONSE] %s %s → %d (%sms)',
            $request->getMethod(),
            $request->getUri(),
            $response->getStatusCode(),
            number_format($elapsedMs, 2),
        ));
    }
}