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

        // Load DB settings to override file config
        Config::loadDbSettings();

        // Re-apply timezone in case it was overridden by a DB setting
        $dbTimezone = Config::getString('timezone', 'UTC');
        if ($dbTimezone !== 'UTC') {
            date_default_timezone_set($dbTimezone);
        }

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
        try {
            $match = $this->router->dispatch($request->method(), $request->uri());

            if ($match === null) {
                $controller = new \App\Templates\FrontController($this);
                $response = $controller->notFound($request);
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
        } catch (\Throwable $e) {
            Logger::error('Uncaught exception: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->renderErrorPage($request, $e);
        }
    }

    private function renderErrorPage(Request $request, \Throwable $e): void
    {
        $isAdmin = str_starts_with($request->uri(), '/admin');
        $debug = Config::getBool('debug', false);

        try {
            if ($isAdmin) {
                $body = '<h1>Error</h1>';
                $body .= '<p>An unexpected error occurred. The error has been logged.</p>';
                if ($debug) {
                    $body .= '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
                }
                $response = Response::html($body, 500);
            } else {
                $data = [
                    'title' => 'Error',
                    'errorCode' => '500',
                    'errorTitle' => 'Server Error',
                    'errorMessage' => 'Something went wrong. Please try again later.',
                ];
                if ($debug) {
                    $data['errorMessage'] = $e->getMessage();
                }
                $html = $this->template->render('public/error', $data);
                $response = Response::html($html, 500);
            }
        } catch (\Throwable $renderError) {
            Logger::error('Error page render failed: ' . $renderError->getMessage());
            $response = Response::html(
                '<h1>500 Internal Server Error</h1><p>An unexpected error occurred.</p>',
                500
            );
        }

        $response->send();
    }
}
