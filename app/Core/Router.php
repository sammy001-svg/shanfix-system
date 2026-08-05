<?php
namespace App\Core;

/**
 * Small regex router with {param} placeholders and per-route middleware.
 *
 *   $r->get('/clients/{id}', [ClientController::class, 'show'], ['auth']);
 */
class Router
{
    private array $routes = [];
    private array $groupStack = [];

    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /**
     * Apply shared middleware to a batch of routes.
     */
    public function group(array $middleware, callable $fn): void
    {
        $this->groupStack[] = $middleware;
        $fn($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $path, array|callable $handler, array $middleware): void
    {
        $inherited = $this->groupStack === [] ? [] : array_merge(...$this->groupStack);

        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $this->compile($path),
            'path'       => $path,
            'handler'    => $handler,
            'middleware' => array_values(array_unique(array_merge($inherited, $middleware))),
        ];
    }

    /**
     * /clients/{id}/documents  ->  #^/clients/(?P<id>[^/]+)/documents$#
     *
     * A trailing * makes the placeholder greedy across slashes, for routes
     * like /files/{path*} that receive a whole relative file path.
     */
    private function compile(string $path): string
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(\*?)\}#',
            static fn(array $m): string => sprintf('(?P<%s>%s)', $m[1], $m[2] === '*' ? '.+' : '[^/]+'),
            $path
        );

        return '#^' . $regex . '$#';
    }

    /**
     * @return array{handler:array|callable, params:array<string,string>, middleware:array}|null
     */
    public function match(string $method, string $uri): ?array
    {
        $uri = '/' . trim(parse_url($uri, PHP_URL_PATH) ?? '/', '/');
        if ($uri === '/') {
            $uri = '/';
        }

        $methodMismatch = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $uri, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $methodMismatch = true;
                continue;
            }

            $params = [];
            foreach ($m as $key => $val) {
                if (!is_int($key)) {
                    $params[$key] = $val;
                }
            }

            return [
                'handler'    => $route['handler'],
                'params'     => $params,
                'middleware' => $route['middleware'],
            ];
        }

        if ($methodMismatch) {
            throw new HttpException(405, 'Method not allowed for this URL.');
        }

        return null;
    }
}
