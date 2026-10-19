<?php

declare(strict_types=1);

namespace BookingApi\Http;

use Closure;

/**
 * Minimal router: "POST /bookings/{id}/cancel" style patterns, numeric {id} parameters.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: Closure(Request, array<string, string>): Response}> */
    private array $routes = [];

    /**
     * @param Closure(Request, array<string, string>): Response $handler
     */
    public function add(string $method, string $pattern, Closure $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = ['method' => strtoupper($method), 'regex' => $regex, 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return ($route['handler'])($request, $params);
        }

        if ($pathMatched) {
            throw new HttpException(405, 'Method not allowed.');
        }
        throw HttpException::notFound('Route not found.');
    }
}
