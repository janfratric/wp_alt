<?php declare(strict_types=1);

namespace App\Core;

use App\Templates\TemplateEngine;
use RuntimeException;

class App
{
    private Router $router;
    private TemplateEngine $template;
    private array $middlewares = [];
    private array $services = [];

    public function __construct()
    {
        date_default_timezone_set(Config::getString('timezone', 'UTC'));
        $this->router = new Router();
        $this->template = new TemplateEngine(dirname(__DIR__, 2) . '/templates');
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function template(): TemplateEngine
    {
        return $this->template;
    }

    public function register(string $key, mixed $value): void
    {
        $this->services[$key] = $value;
    }

    public function resolve(string $key): mixed
    {
        if (!isset($this->services[$key])) {
            throw new RuntimeException("Service not found: {$key}");
        }
        return $this->services[$key];
    }

    public function addMiddleware(callable $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    public function run(Request $request): void
    {
        $match = $this->router->dispatch($request->method(), $request->uri());

        if ($match === null) {
            $response = Response::html('<h1>404 Not Found</h1>', 404);
            $response->send();
            return;
        }

        $handler = $match['handler'];
        $params = $match['params'];

        // Build the final handler callable
        $finalHandler = function (Request $req) use ($handler, $params): Response {
            if (is_array($handler)) {
                // [ControllerClass, 'method'] — instantiate with App instance
                $controller = new $handler[0]($this);
                return $controller->{$handler[1]}($req, ...$params);
            }

            // Closure handler
            return $handler($req, ...$params);
        };

        $response = Middleware::run($request, $this->middlewares, $finalHandler);
        $response->send();
    }
}
