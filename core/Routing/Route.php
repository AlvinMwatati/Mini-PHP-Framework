<?php

declare(strict_types=1);

namespace Core\Routing;

/**
 * Represents a single registered route.
 *
 * A Route is a value object — it holds data, has no side effects,
 * and never changes after construction (readonly properties).
 *
 * It knows three things:
 *   1. Which HTTP methods it responds to (GET, POST…)
 *   2. Which URI pattern it matches (/users/{id})
 *   3. What to run when it matches (a controller, closure, or string)
 *
 * It also knows how to match itself against an incoming URI — that's
 * the regex compilation step, where /users/{id} becomes a real pattern.
 */
class Route
{
    /**
     * The compiled regex pattern built from $this->pattern.
     * Lazily built on the first match() call and cached here.
     *
     * Example: '/users/{id}' → '#^/users/(?P<id>[^/]+)$#'
     */
    private ?string $compiledPattern = null;

    /**
     * @param string[]     $methods  HTTP methods this route accepts, e.g. ['GET', 'HEAD']
     * @param string       $pattern  URI pattern, e.g. '/users/{id}'
     * @param mixed        $handler  What to invoke: a Closure, 'Controller@method', or [Controller::class, 'method']
     * @param string[]     $middleware  Middleware to run before the handler
     * @param string|null  $name     Optional name for URL generation
     */
    public function __construct(
        public readonly array   $methods,
        public readonly string  $pattern,
        public readonly mixed   $handler,
        public readonly array   $middleware = [],
        public readonly ?string $name = null,
    ) {}

    /**
     * Try to match this route against an incoming URI.
     *
     * Returns an array of captured parameters if it matches:
     *   Route: /users/{id}
     *   URI:   /users/42
     *   Returns: ['id' => '42']
     *
     * Returns null if it doesn't match.
     *
     * @return array<string,string>|null
     */
    public function match(string $uri): ?array
    {
        $pattern = $this->compile();

        // preg_match returns 1 on match, 0 on no match, false on error.
        // The $matches array is populated with named capture groups.
        if (!preg_match($pattern, $uri, $matches)) {
            return null;
        }

        // $matches contains both numeric and named keys. We only want the
        // named captures (the {id}, {slug} placeholders from the pattern).
        // array_filter removes empty strings from optional captures.
        return array_filter(
            $matches,
            fn($key) => is_string($key),  // Keep only string (named) keys
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Check if this route accepts a given HTTP method.
     */
    public function acceptsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, strict: true);
    }

    /**
     * Compile the URI pattern into a regex.
     *
     * This is where the magic happens. We convert a human-readable pattern
     * like /users/{id}/posts/{postId} into a regex the PHP engine can run.
     *
     * Step-by-step for /users/{id}/posts/{postId?}:
     *
     *   1. Escape everything that's regex-special except {}, e.g. '.' → '\.'
     *      Result: /users/\{id\}/posts/\{postId\?\}
     *      Wait — we don't escape {}, we handle them specially below.
     *
     *   2. Replace {param} → (?P<param>[^/]+)
     *      This creates a named capture group.
     *      [^/]+ means "one or more characters that aren't a slash"
     *      — so {id} captures a single path segment.
     *
     *   3. Replace {param?} → (?P<param>[^/]*)  (optional, with /? before it)
     *      The * instead of + allows zero characters (empty match).
     *
     *   4. Wrap in #^...$# — the ^ and $ anchor to the full string,
     *      so /users/123/extra does NOT match /users/{id}.
     *
     * Named capture groups (?P<name>...) are a PCRE feature. They put
     * the matched value into $matches['name'], not just $matches[1].
     * That's how we get ['id' => '42'] instead of [1 => '42'].
     */
    private function compile(): string
    {
        if ($this->compiledPattern !== null) {
            return $this->compiledPattern;
        }

        // Start with the raw pattern and escape regex special characters,
        // but exclude the characters we'll handle ourselves: { } ?
        $regex = preg_quote($this->pattern, '#');

        // preg_quote escapes { and } — undo that so we can process them.
        $regex = str_replace(['\{', '\}'], ['{', '}'], $regex);

        // Replace optional parameters: {param?} (must come before required)
        // The (?:/...)? makes the whole /segment optional.
        $regex = preg_replace(
            '#\{(\w+)\?\}#',
            '(?:/(?P<$1>[^/]*))?',
            $regex
        );

        // Replace required parameters: {param}
        $regex = preg_replace(
            '#\{(\w+)\}#',
            '(?P<$1>[^/]+)',
            $regex
        );

        // Anchor the pattern to the full string.
        // #...# is just the regex delimiter — we use # to avoid escaping /.
        $this->compiledPattern = '#^' . $regex . '$#';

        return $this->compiledPattern;
    }

    /**
     * Return a copy of this route with added middleware.
     * Useful for route groups: apply shared middleware to many routes at once.
     */
    public function withMiddleware(array $middleware): static
    {
        return new static(
            methods:    $this->methods,
            pattern:    $this->pattern,
            handler:    $this->handler,
            middleware: array_merge($this->middleware, $middleware),
            name:       $this->name,
        );
    }

    /**
     * Return a copy of this route with a name.
     */
    public function withName(string $name): static
    {
        return new static(
            methods:    $this->methods,
            pattern:    $this->pattern,
            handler:    $this->handler,
            middleware: $this->middleware,
            name:       $name,
        );
    }
}