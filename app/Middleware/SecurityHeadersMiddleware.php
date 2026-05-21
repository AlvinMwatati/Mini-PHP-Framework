<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Middleware\MiddlewareInterface;

/**
 * SecurityHeadersMiddleware — adds HTTP security headers to every response.
 *
 * This is a pure "after" middleware. It does nothing before $next —
 * it just lets the request pass through untouched, then decorates
 * the response on the way back out.
 *
 * These headers are real-world best practice. They cost nothing to add
 * and defend against a broad range of common web attacks.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * Header explanations:
 * ──────────────────────────────────────────────────────────────────────────
 *
 * X-Content-Type-Options: nosniff
 *   Prevents the browser from guessing a file's MIME type. Without this,
 *   a browser might execute a .txt file that happens to look like JS.
 *   Attack: MIME-sniffing content injection.
 *
 * X-Frame-Options: SAMEORIGIN
 *   Prevents your page from being embedded in an <iframe> on another site.
 *   Attack: Clickjacking — the attacker overlays your page invisibly and
 *   tricks users into clicking buttons they can't see.
 *
 * X-XSS-Protection: 1; mode=block
 *   Activates the browser's built-in XSS filter (older browsers).
 *   Modern browsers rely on CSP instead, but this is cheap to add.
 *
 * Referrer-Policy: strict-origin-when-cross-origin
 *   Controls how much of the current URL is sent in the Referer header
 *   when the user follows a link. Prevents leaking sensitive path info
 *   (e.g. /users/123/reset-password) to third-party sites.
 *
 * Permissions-Policy
 *   Restricts which browser features (camera, microphone, geolocation)
 *   your page can use. Defense-in-depth if your site is XSS'd.
 *
 * X-Powered-By: MiniFramework
 *   Replaces (or adds) the default PHP header that reveals version info.
 *   Attackers use "X-Powered-By: PHP/8.1.0" to target known CVEs.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * Headers to attach to every response.
     * Keys are header names, values are header values.
     *
     * @var array<string, string>
     */
    private array $headers = [
        'X-Content-Type-Options'  => 'nosniff',
        'X-Frame-Options'         => 'SAMEORIGIN',
        'X-XSS-Protection'        => '1; mode=block',
        'Referrer-Policy'         => 'strict-origin-when-cross-origin',
        'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=()',
        'X-Powered-By'            => 'MiniFramework',
    ];

    public function handle(Request $request, callable $next): Response
    {
        // ── BEFORE: nothing to do ─────────────────────────────────────────
        // This middleware only acts on responses, not requests.

        // ── PASS INWARD ───────────────────────────────────────────────────
        $response = $next($request);

        // ── AFTER: attach security headers ───────────────────────────────
        // withHeader() returns a NEW Response (immutability), so we chain
        // all the additions into a single $response variable.
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}