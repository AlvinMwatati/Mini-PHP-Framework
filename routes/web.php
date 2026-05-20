<?php

declare(strict_types=1);

/**
 * Web Routes
 *
 * This is where you define all the routes for your application.
 * The $router variable is injected by Application::loadRoutes().
 *
 * Every route maps a URI pattern + HTTP method → a handler.
 * The handler can be a Closure (for quick routes) or a controller.
 */

use Core\Http\Request;
use Core\Http\Response;
use Core\Routing\Router;

/** @var Router $router */

// ---------------------------------------------------------------------------
// Simple closure routes — great for quick pages or prototyping
// ---------------------------------------------------------------------------

$router->get('/', function (Request $request): Response {
    return Response::html(<<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>MiniFramework</title>
        <style>
            body { font-family: sans-serif; max-width: 640px; margin: 80px auto; color: #333; }
            code { background: #f4f4f4; padding: 2px 6px; border-radius: 4px; }
            .badge { display:inline-block; background: #22c55e; color: #fff; padding: 4px 10px; border-radius: 99px; font-size: 13px; }
            ul li { margin: 6px 0; }
        </style>
    </head>
    <body>
        <span class="badge">Phase 2 ✓ — Router is live</span>
        <h1>Welcome to MiniFramework</h1>
        <p>The router matched this route. Try the links below:</p>
        <ul>
            <li><a href="/hello/World">GET /hello/{name}</a></li>
            <li><a href="/users">GET /users (JSON)</a></li>
            <li><a href="/users/42">GET /users/42 (JSON)</a></li>
            <li><a href="/api/ping">GET /api/ping (grouped route)</a></li>
        </ul>
    </body>
    </html>
    HTML);
});

// Route with a named parameter — {name} becomes available in $params
$router->get('/hello/{name}', function (Request $request, array $params): Response {
    $name = htmlspecialchars($params['name'], ENT_QUOTES, 'UTF-8');
    return Response::html("<h1>Hello, {$name}!</h1><p><a href='/'>← Back</a></p>");
})->withName('hello');

// ---------------------------------------------------------------------------
// Controller-based routes
// ---------------------------------------------------------------------------

$router->get('/users', [App\Controllers\UserController::class, 'index'])->withName('users.index');
$router->get('/users/{id}', [App\Controllers\UserController::class, 'show'])->withName('users.show');
$router->post('/users', [App\Controllers\UserController::class, 'store'])->withName('users.store');
$router->delete('/users/{id}', [App\Controllers\UserController::class, 'destroy'])->withName('users.destroy');

// ---------------------------------------------------------------------------
// Route groups — shared prefix and/or middleware
// ---------------------------------------------------------------------------

$router->group(['prefix' => '/api'], function (Router $router): void {
    $router->get('/ping', function (Request $request): Response {
        return Response::json(['pong' => true, 'time' => time()]);
    });

    $router->get('/status', function (Request $request): Response {
        return Response::json(['status' => 'ok', 'framework' => 'MiniFramework']);
    });
});