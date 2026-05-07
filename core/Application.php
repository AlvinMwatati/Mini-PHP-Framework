<?php

declare(strict_types=1);

namespace Core;

use Core\Http\Request;
use Core\Http\Response;

/**
 * The Application class is the heart of the framework.
 *
 * It is responsible for:
 *   1. Holding the base path (where the project root is on disk)
 *   2. Bootstrapping: setting up error handling, loading config
 *   3. Handling an incoming HTTP request → returning a Response
 *
 * In later phases, this class will also:
 *   - Own the service container (dependency injection)
 *   - Register route definitions
 *   - Run middleware pipelines
 *
 * Why a static factory method (create()) instead of new Application()?
 * It makes the construction intent readable:
 *   $app = Application::create(basePath: __DIR__);
 * vs
 *   $app = new Application(__DIR__);
 *
 * The named argument `basePath:` makes call sites self-documenting.
 */
class Application
{
    /**
     * The absolute path to the project root directory.
     * All other paths are derived from this.
     *
     * @var string
     */
    protected string $basePath;

    /**
     * Private constructor — force use of Application::create() factory.
     * This pattern lets the factory method do work before the object is
     * handed back to the caller.
     */
    private function __construct(string $basePath)
    {
        // rtrim removes any trailing slash the caller might have included,
        // so we always have a consistent path without a trailing slash.
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Factory method: create and bootstrap the Application.
     *
     * This is the public way to get an Application instance.
     * It creates the object and then calls bootstrap(), which sets
     * up PHP error handling and any early configuration.
     *
     * Using a named argument (basePath:) is a PHP 8.0+ feature that
     * makes call sites far more readable than positional arguments.
     */
    public static function create(string $basePath): static
    {
        $app = new static($basePath);
        $app->bootstrap();

        return $app;
    }

    /**
     * Bootstrap the application.
     *
     * This is called once at startup. Think of it as everything that
     * must happen before the first line of your application code runs.
     *
     * In later phases this will grow to include:
     *   - Loading the service container bindings
     *   - Registering event listeners
     *   - Loading .env configuration
     *
     * Why is this separate from the constructor?
     * It lets subclasses override bootstrap() to change startup behavior
     * without touching construction logic.
     */
    protected function bootstrap(): void
    {
        $this->registerErrorHandling();
    }

    /**
     * Handle an incoming HTTP request.
     *
     * This is the main lifecycle method. It:
     *   1. Builds a Request object from PHP's superglobals
     *   2. [Future] Runs middleware
     *   3. [Future] Dispatches to the Router
     *   4. Returns a Response
     *
     * Right now it returns a placeholder — we'll replace this
     * body in Phase 3 when the Router is built.
     *
     * The method signature tells the whole story:
     *   Request → (framework magic) → Response
     */
    public function handle(): Response
    {
        // Build a Request from PHP's superglobals.
        // In Phase 2 we'll flesh out this class fully.
        $request = Request::fromGlobals();

        // Placeholder: in Phase 3 this becomes:
        //   return $this->router->dispatch($request);
        return new Response(
            body: $this->getWelcomePage($request),
            statusCode: 200,
            headers: ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    /**
     * Register PHP error and exception handling.
     *
     * By default PHP prints ugly error messages and stack traces
     * directly to the browser — terrible for users and a security risk
     * (it leaks internal paths and logic). We take control of this here.
     *
     * set_error_handler() — catches PHP notices, warnings, and errors
     *   and converts them to exceptions so they flow through one path.
     *
     * set_exception_handler() — catches any uncaught exception and
     *   renders a controlled error page instead of PHP's default output.
     */
    protected function registerErrorHandling(): void
    {
        // Convert PHP errors into ErrorException so they can be caught
        // with try/catch like any other exception.
        set_error_handler(function (
            int $errno,
            string $errstr,
            string $errfile,
            int $errline
        ): bool {
            // Only throw for errors that match the current error_reporting level.
            // This respects the @ operator (suppress errors) in legacy code.
            if (!(error_reporting() & $errno)) {
                return false; // Let PHP handle it normally
            }

            throw new \ErrorException(
                message: $errstr,
                code: 0,
                severity: $errno,
                filename: $errfile,
                line: $errline
            );
        });

        // Catch any exception that bubbles up without being caught.
        // In production this would render a pretty 500 page.
        // In development it should show a detailed debug page (Phase 6+).
        set_exception_handler(function (\Throwable $e): void {
            // Ensure no output has been sent yet
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
            }

            echo $this->renderError($e);
        });
    }

    /**
     * Render an error page for uncaught exceptions.
     *
     * This is deliberately simple for now. In a real framework you'd
     * check an APP_DEBUG environment variable and show detailed info
     * only in development.
     */
    protected function renderError(\Throwable $e): string
    {
        return sprintf(
            '<h1>500 — Internal Server Error</h1><p>%s</p>',
            htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * A temporary welcome page so we can verify the framework boots.
     * Delete this in Phase 3 when the Router takes over.
     */
    protected function getWelcomePage(Request $request): string
    {
        $uri = htmlspecialchars($request->getUri(), ENT_QUOTES, 'UTF-8');
        $method = htmlspecialchars($request->getMethod(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>MiniFramework — It works!</title>
            <style>
                body { font-family: sans-serif; max-width: 640px; margin: 80px auto; color: #333; }
                code { background: #f4f4f4; padding: 2px 6px; border-radius: 4px; }
                .badge { display: inline-block; background: #22c55e; color: white; padding: 4px 10px; border-radius: 99px; font-size: 13px; }
            </style>
        </head>
        <body>
            <span class="badge">Phase 1 ✓</span>
            <h1>MiniFramework is running!</h1>
            <p>The front controller is working. Here's what we know about this request:</p>
            <ul>
                <li><strong>Method:</strong> <code>{$method}</code></li>
                <li><strong>URI:</strong> <code>{$uri}</code></li>
            </ul>
            <p>Next: build the <strong>Router</strong> in Phase 3 to handle this URI.</p>
        </body>
        </html>
        HTML;
    }

    /**
     * Helper to build paths relative to the project root.
     *
     * Usage: $app->path('config/app.php')
     * Returns: /var/www/myproject/config/app.php
     *
     * Having all path resolution go through this method means you only
     * set the base path once, and everything else is portable.
     */
    public function path(string $relative = ''): string
    {
        return $this->basePath . ($relative ? DIRECTORY_SEPARATOR . ltrim($relative, '/\\') : '');
    }

    /**
     * Return the base path (project root).
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
}