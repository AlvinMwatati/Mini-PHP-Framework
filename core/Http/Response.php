<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Represents an outgoing HTTP response.
 *
 * PHP sends responses by calling header() for headers and echo for the body.
 * These are global side effects — once you call them, you can't take them back.
 * The Response class defers all output until you explicitly call send().
 *
 * This has big benefits:
 *   - Middleware can inspect or modify the response before it's sent.
 *   - You can return a Response from a controller without actually sending
 *     it to the browser — useful for testing.
 *   - You can replace the Response entirely (redirect, 404 page) without
 *     any headers already being flushed.
 *
 * Immutability and "wither" methods:
 * PHP 8.1 readonly properties mean you can't mutate this object after
 * construction. But sometimes middleware needs to "change" a response
 * (add a header, change the status code). We handle this with "wither"
 * methods (withHeader, withStatus, withBody) — they return a NEW Response
 * instance with the change applied, leaving the original untouched.
 *
 * This is the same pattern PSR-7 uses, and it's called immutability.
 * The benefit: no hidden mutation, no "who changed this header?" bugs.
 */
class Response
{
    /**
     * Common HTTP status codes and their reason phrases.
     * The reason phrase is the text after the code: "200 OK", "404 Not Found".
     */
    private const STATUS_TEXTS = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    /**
     * @param string               $body       The response body (HTML, JSON, etc.)
     * @param int                  $statusCode HTTP status code (200, 404, 500…)
     * @param array<string,string> $headers    HTTP headers to send
     */
    public function __construct(
        protected readonly string $body       = '',
        protected readonly int    $statusCode = 200,
        protected readonly array  $headers    = [],
    ) {}

    // =========================================================================
    // Named constructors — convenient ways to build common response types
    // =========================================================================

    /**
     * Create a plain HTML response.
     *
     * Usage: Response::html('<h1>Hello</h1>')
     *        Response::html('<h1>Not found</h1>', 404)
     */
    public static function html(string $body, int $status = 200): static
    {
        return new static(
            body:       $body,
            statusCode: $status,
            headers:    ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    /**
     * Create a JSON response.
     *
     * Automatically encodes a PHP array/object to JSON.
     * Sets the correct Content-Type header.
     *
     * Usage: Response::json(['users' => $users])
     *        Response::json(['error' => 'Not found'], 404)
     *
     * JSON_THROW_ON_ERROR makes json_encode throw a JsonException
     * instead of returning false on encoding failure — much safer.
     *
     * @throws \JsonException if $data cannot be encoded
     */
    public static function json(mixed $data, int $status = 200): static
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return new static(
            body:       $json,
            statusCode: $status,
            headers:    ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    /**
     * Create a redirect response.
     *
     * A redirect sends a Location header and a 3xx status code.
     * The browser then makes a new request to the Location URL.
     *
     * 302 = temporary redirect (the default, most common)
     * 301 = permanent redirect (browsers/search engines cache this)
     *
     * Usage: Response::redirect('/dashboard')
     *        Response::redirect('/old-page', 301)
     */
    public static function redirect(string $url, int $status = 302): static
    {
        return new static(
            body:       '',
            statusCode: $status,
            headers:    ['Location' => $url],
        );
    }

    /**
     * Create a "No Content" response (204).
     *
     * Used for successful operations that return no body:
     * DELETE /users/123 → 204 No Content
     */
    public static function noContent(): static
    {
        return new static(statusCode: 204);
    }

    // =========================================================================
    // "Wither" methods — return a new Response with one thing changed
    // =========================================================================

    /**
     * Return a new Response with an additional header.
     *
     * Because Response is immutable (readonly properties), we can't
     * modify $this->headers. Instead we create a brand-new Response
     * that is identical except for the added header.
     *
     * Usage: $response = $response->withHeader('X-Request-Id', 'abc-123');
     */
    public function withHeader(string $name, string $value): static
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new static($this->body, $this->statusCode, $headers);
    }

    /**
     * Return a new Response with a different status code.
     */
    public function withStatus(int $statusCode): static
    {
        return new static($this->body, $statusCode, $this->headers);
    }

    /**
     * Return a new Response with a different body.
     */
    public function withBody(string $body): static
    {
        return new static($body, $this->statusCode, $this->headers);
    }

    // =========================================================================
    // Getters
    // =========================================================================

    public function getBody(): string
    {
        return $this->body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    // =========================================================================
    // Sending the response
    // =========================================================================

    /**
     * Send this response to the browser.
     *
     * This is the only method that causes side effects (actually writes
     * to the HTTP connection). Everything else just builds the object.
     *
     * Order matters:
     *   1. Status line must come before any other headers.
     *   2. Headers must all come before the body.
     *   3. Body is echoed last.
     *
     * PHP's header() function sends HTTP headers. It must be called
     * before any output (echo, print, etc.) — once you start outputting,
     * PHP's output buffering usually handles it, but it's best practice
     * to always send headers first.
     */
    public function send(): void
    {
        // 1. Set the HTTP status code.
        // http_response_code() sets the status without sending a full header line.
        // We send the full status line ourselves for clarity.
        if (!headers_sent()) {
            $reasonPhrase = self::STATUS_TEXTS[$this->statusCode] ?? 'Unknown';

            // This sends the HTTP/1.1 status line: "HTTP/1.1 200 OK"
            header(
                header: sprintf('HTTP/1.1 %d %s', $this->statusCode, $reasonPhrase),
                replace: true,
                response_code: $this->statusCode
            );

            // 2. Send all headers.
            foreach ($this->headers as $name => $value) {
                // replace: true means if this header was already set, replace it.
                // This prevents duplicate headers (e.g. two Content-Type lines).
                header("{$name}: {$value}", replace: true);
            }

            // Automatically add Content-Length if we have a body.
            // This lets the browser know how much data to expect.
            if ($this->body !== '') {
                header('Content-Length: ' . strlen($this->body));
            }
        }

        // 3. Send the body.
        // No output for 204 No Content or redirects
        if ($this->statusCode !== 204 && !isset($this->headers['Location'])) {
            echo $this->body;
        }
    }
}