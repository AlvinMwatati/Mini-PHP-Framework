<?php

/**
 * Front Controller — The single entry point for ALL HTTP requests.
 *
 * Apache's .htaccess rewrites every request here. This file's only job
 * is to bootstrap the application and send the response. No logic lives
 * here — it delegates immediately to the Application class.
 *
 * Why keep this file so thin?
 * - Testability: the Application class can be instantiated in tests
 *   without a real HTTP request.
 * - Separation of concerns: web-specific boot (this file) vs
 *   application logic (Application class) are cleanly separated.
 * - Flexibility: you could have a `cli/console.php` entry point later
 *   that boots the same Application differently.
 */

declare(strict_types=1);

use Core\Http\Request;

/*
|--------------------------------------------------------------------------
| Register The Composer Auto-Loader
|--------------------------------------------------------------------------
|
| Composer generates a class loader that maps every PSR-4 namespace
| to a directory. This one require() call makes every class in your
| app available without manual require() calls throughout your code.
|
| The __DIR__ constant gives us the directory of THIS file (/public),
| so we go up one level with .. to reach the project root.
|
*/
require_once __DIR__ . '/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| We bootstrap the application and ask it to handle the current HTTP
| request. The Application class lives in core/Application.php under
| the Core\ namespace — Composer's autoloader finds it automatically.
|
| Application::create() reads config, sets up error handling, and
| returns a ready-to-use app instance.
|
| handle() wraps $_SERVER, $_GET, $_POST, etc. into a Request object,
| runs it through the router + middleware pipeline, and returns a
| Response object.
|
| send() writes HTTP headers and echoes the response body.
|
*/
use Core\Application;

$app = Application::create(
    basePath: dirname(__DIR__) // The project root — one level up from /public
);

$response = $app->handle();

$response->send();