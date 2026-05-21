<?php

declare(strict_types=1);

use App\Controllers\UserController;
use App\Middleware\AuthMiddleware;
use App\Middleware\ThrottleMiddleware;
use Core\Http\Request;
use Core\Http\Response;
use Core\Routing\Router;

/** @var Router $router */

// ---------------------------------------------------------------------------
// Public routes — only global middleware (logging + security headers)
// ---------------------------------------------------------------------------

$router->get('/', function (Request $request): Response {
    return Response::html(<<<HTML
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>MiniFramework</title>
    <style>body{font-family:sans-serif;max-width:680px;margin:80px auto;color:#333}
    code{background:#f4f4f4;padding:2px 6px;border-radius:4px}
    .badge{display:inline-block;background:#22c55e;color:#fff;padding:4px 10px;border-radius:99px;font-size:13px}
    table{width:100%;border-collapse:collapse;margin:16px 0}th,td{padding:8px 12px;border:1px solid #e5e5e5;text-align:left;font-size:14px}th{background:#f9f9f9}
    </style></head><body>
    <span class="badge">Phase 3 ✓ — Middleware is live</span>
    <h1>MiniFramework</h1>
    <p>Every request now flows through the middleware pipeline. Check your PHP error log to see <code>LoggingMiddleware</code> output.</p>
    <h2>Routes</h2>
    <table>
      <tr><th>Method</th><th>URI</th><th>Middleware</th></tr>
      <tr><td>GET</td><td>/</td><td>global only</td></tr>
      <tr><td>GET</td><td>/users</td><td>global only</td></tr>
      <tr><td>GET</td><td>/users/{id}</td><td>global only</td></tr>
      <tr><td>GET</td><td>/api/ping</td><td>global + throttle</td></tr>
      <tr><td>GET</td><td>/api/status</td><td>global + throttle</td></tr>
      <tr><td>GET</td><td>/api/secure</td><td>global + auth + throttle</td></tr>
    </table>
    <p>Try <a href="/api/secure">/api/secure</a> without a token — you'll get a 401.<br>
    Try <a href="/api/ping">/api/ping</a> to see rate-limit headers in the response.</p>
    </body></html>
    HTML);
});

$router->get('/users',      [UserController::class, 'index']);
$router->get('/users/{id}', [UserController::class, 'show']);
$router->post('/users',     [UserController::class, 'store']);
$router->delete('/users/{id}', [UserController::class, 'destroy']);

// ---------------------------------------------------------------------------
// API routes — global middleware + per-group ThrottleMiddleware
// ---------------------------------------------------------------------------
// ThrottleMiddleware is route-level: it only runs for routes in this group.
// It runs INSIDE the global middleware (closer to the controller).

$router->group(['prefix' => '/api', 'middleware' => [ThrottleMiddleware::class]], function (Router $router): void {

    $router->get('/ping', function (Request $request): Response {
        return Response::json(['pong' => true, 'time' => time()]);
    });

    $router->get('/status', function (Request $request): Response {
        return Response::json(['status' => 'ok', 'framework' => 'MiniFramework', 'phase' => 3]);
    });

    // This route also has AuthMiddleware — stacked on top of ThrottleMiddleware.
    // Stack order: global → auth → throttle → controller
    // Auth runs first so we don't waste a rate-limit slot on an invalid token.
    $router->get('/secure', function (Request $request): Response {
        return Response::json([
            'message' => 'You are authenticated!',
            'hint'    => 'Send Authorization: Bearer secret-token-123 to reach this.',
        ]);
    })->withMiddleware([AuthMiddleware::class]);

});