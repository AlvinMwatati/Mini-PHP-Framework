<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Represents an incoming HTTP request.
 *
 * PHP gives us request data through "superglobals" — magical global arrays
 * like $_GET, $_POST, $_SERVER, $_COOKIE, $_FILES. They work, but they
 * have serious problems:
 *
 *   - They're global state: any code anywhere can read or modify them.
 *   - They're hard to test: you can't inject fake request data in unit tests.
 *   - They have inconsistent structure: $_SERVER['REQUEST_METHOD'] vs $_GET['foo']
 *   - They're not typed: everything is string|array.
 *
 * The Request class wraps all of this into a clean, immutable value object.
 * Code that needs request data depends on this class, not on global state.
 *
 * Immutability note:
 * PHP 8.1+ readonly properties give us true immutability — once the object
 * is constructed, none of these values can change. This is a feature, not
 * a limitation. A request represents a single moment in time; it should
 * never change as it flows through your app.
 *
 * PSR-7 note:
 * The real PSR-7 standard (psr/http-message) defines interfaces like
 * ServerRequestInterface. We're inspired by PSR-7 here but building our
 * own simpler version so you can understand what PSR-7 is actually doing
 * under the hood. In Phase 2 we'll explore hooking into the real interfaces.
 */
class Request
{
    /**
     * @param string               $method   HTTP method: GET, POST, PUT, DELETE, PATCH…
     * @param string               $uri      The request path + query string, e.g. /users/123?sort=asc
     * @param array<string,string> $headers  Normalized HTTP headers, lowercased keys
     * @param array<string,mixed>  $query    Query string params ($_GET)
     * @param array<string,mixed>  $body     POST body params ($_POST)
     * @param array<string,mixed>  $server   Server/env data ($_SERVER)
     * @param array<string,mixed>  $cookies  Cookie values ($_COOKIE)
     * @param array<string,mixed>  $files    Uploaded files ($_FILES)
     * @param string               $rawBody  Raw request body (for JSON APIs)
     */
    public function __construct(
        protected readonly string $method,
        protected readonly string $uri,
        protected readonly array  $headers = [],
        protected readonly array  $query   = [],
        protected readonly array  $body    = [],
        protected readonly array  $server  = [],
        protected readonly array  $cookies = [],
        protected readonly array  $files   = [],
        protected readonly string $rawBody = '',
    ) {}

    /**
     * Create a Request by reading PHP's superglobals.
     *
     * This is a "named constructor" (static factory method). It's the
     * standard way to build a Request from a real HTTP request.
     *
     * Why not just do this in __construct()?
     * Because then your tests would have to set up the superglobals,
     * which is messy. With this pattern, tests can call:
     *   new Request(method: 'GET', uri: '/users')
     * and fromGlobals() is only called once in index.php.
     */
    public static function fromGlobals(): static
    {
        return new static(
            method:  static::resolveMethod(),
            uri:     static::resolveUri(),
            headers: static::resolveHeaders(),
            query:   $_GET,
            body:    $_POST,
            server:  $_SERVER,
            cookies: $_COOKIE,
            files:   $_FILES,
            rawBody: file_get_contents('php://input') ?: '',
        );
    }

    /**
     * The request method from $_SERVER['REQUEST_METHOD'].
     *
     * We uppercase it because HTTP methods are case-sensitive (RFC 7230)
     * and must be uppercase: GET, not get, not Get.
     *
     * HTML forms only support GET and POST. Browsers can't send DELETE or PUT.
     * Frameworks work around this with a hidden _method field — we handle
     * that here: if it's a POST with a _method override, use that instead.
     */
    protected static function resolveMethod(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Method override for HTML forms: <input type="hidden" name="_method" value="DELETE">
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper($_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], strict: true)) {
                return $override;
            }
        }

        return $method;
    }

    /**
     * Resolve the request URI.
     *
     * $_SERVER['REQUEST_URI'] gives us the full URI including query string,
     * e.g. /users/123?sort=asc&page=2
     *
     * We want the path only (/users/123) for routing, keeping the query
     * string accessible separately via $request->query().
     *
     * parse_url() breaks a URL into its components. We use PHP_URL_PATH
     * to extract just the path portion.
     */
    protected static function resolveUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // parse_url can return false on a malformed URI. We default to '/'
        // to avoid crashes on bad input — defense in depth.
        return parse_url($uri, PHP_URL_PATH) ?: '/';
    }

    /**
     * Extract HTTP headers from $_SERVER.
     *
     * PHP doesn't give us headers in a clean way. Instead, they're mixed
     * into $_SERVER with a 'HTTP_' prefix and underscores instead of dashes.
     * For example:
     *   Content-Type header → $_SERVER['CONTENT_TYPE']
     *   Accept header       → $_SERVER['HTTP_ACCEPT']
     *   X-Custom-Header     → $_SERVER['HTTP_X_CUSTOM_HEADER']
     *
     * We normalize them to lowercase with dashes:
     *   'HTTP_ACCEPT_LANGUAGE' → 'accept-language'
     *   'CONTENT_TYPE'         → 'content-type'
     *
     * This makes header access consistent:
     *   $request->header('content-type') always works,
     *   regardless of what case the client sent.
     */
    protected static function resolveHeaders(): array
    {
        // getallheaders() is available in Apache environments and is the
        // clean way to get headers. It returns them with proper casing.
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            return array_change_key_case($headers ?: [], CASE_LOWER);
        }

        // Fallback: manually extract from $_SERVER for nginx/CLI environments
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            // Most HTTP headers are prefixed with HTTP_
            if (str_starts_with($key, 'HTTP_')) {
                // Remove HTTP_ prefix, lowercase, replace underscores with dashes
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = $value;
            }

            // Some headers (Content-Type, Content-Length) aren't prefixed with HTTP_
            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], strict: true)) {
                $headerName = strtolower(str_replace('_', '-', $key));
                $headers[$headerName] = $value;
            }
        }

        return $headers;
    }

    // =========================================================================
    // Accessor methods — the public interface for reading request data
    // =========================================================================

    /**
     * Get the HTTP method (always uppercase).
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Check if the request uses a given method.
     * Case-insensitive for developer convenience.
     *
     * Usage: $request->isMethod('GET')
     */
    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Get the request URI path (no query string).
     *
     * Usage: $request->getUri() → '/users/123'
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Get a single header value by name (case-insensitive).
     *
     * Returns $default if the header isn't present.
     *
     * Usage: $request->header('accept') → 'application/json'
     *        $request->header('x-missing', 'fallback') → 'fallback'
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Get all headers as an associative array (lowercase keys).
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a query string parameter ($_GET).
     *
     * Usage: For /users?sort=asc → $request->query('sort') → 'asc'
     *        $request->query('missing', 'default') → 'default'
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get all query parameters.
     */
    public function allQuery(): array
    {
        return $this->query;
    }

    /**
     * Get a POST body parameter ($_POST).
     *
     * Usage: $request->input('email') → 'user@example.com'
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Get all POST body parameters.
     */
    public function allInput(): array
    {
        return $this->body;
    }

    /**
     * Decode and return the raw JSON body.
     *
     * For API endpoints that accept JSON:
     *   Content-Type: application/json
     *   Body: {"name":"Alice","email":"alice@example.com"}
     *
     * Usage: $data = $request->json(); → ['name' => 'Alice', ...]
     *        $request->json('name') → 'Alice'
     *
     * The ?string $key parameter lets you dig into the decoded array:
     *   $request->json() → the whole array
     *   $request->json('name') → just the 'name' field
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        $decoded = json_decode($this->rawBody, associative: true);

        // json_decode returns null on failure (malformed JSON or empty body)
        if (!is_array($decoded)) {
            return $key === null ? [] : $default;
        }

        if ($key === null) {
            return $decoded;
        }

        return $decoded[$key] ?? $default;
    }

    /**
     * Check if the request expects a JSON response.
     *
     * API clients send Accept: application/json to indicate they want
     * JSON back, not HTML. This is how you detect "is this an API call?".
     */
    public function expectsJson(): bool
    {
        $accept = $this->header('accept', '');

        return str_contains($accept, 'application/json')
            || str_contains($accept, 'application/ld+json');
    }

    /**
     * Check if the request body is JSON-encoded.
     */
    public function isJson(): bool
    {
        $contentType = $this->header('content-type', '');

        return str_contains($contentType, 'application/json');
    }

    /**
     * Get an uploaded file from $_FILES.
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Get a cookie value.
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Get a value from $_SERVER.
     *
     * Usage: $request->server('REMOTE_ADDR') → '127.0.0.1'
     */
    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Get the client's IP address.
     *
     * In production behind a load balancer or proxy, the real client IP
     * is often in HTTP_X_FORWARDED_FOR rather than REMOTE_ADDR.
     * Be careful: X_FORWARDED_FOR can be spoofed by clients if not
     * validated — only trust it if you know you're behind a trusted proxy.
     */
    public function ip(): string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['REMOTE_ADDR']
            ?? '127.0.0.1';
    }

    /**
     * Get the full URL of the current request.
     *
     * Reconstructed from $_SERVER parts since PHP doesn't give you
     * the full URL directly.
     */
    public function fullUrl(): string
    {
        $scheme = ($this->server['HTTPS'] ?? 'off') !== 'off' ? 'https' : 'http';
        $host   = $this->server['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host . $this->uri;
    }
}