<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function any(string $pattern, callable $handler): void
    {
        $this->routes[] = ['ANY', $pattern, $handler];
    }

    public function dispatch(string $method, string $path, callable $notFound): mixed
    {
        $allowed = false;
        foreach ($this->routes as [$m, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $mm)) {
                continue;
            }
            if ($m !== 'ANY' && $m !== $method) {
                $allowed = true;
                continue;
            }
            $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler(...$params);
        }
        return $notFound($allowed);
    }
}
