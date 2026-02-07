<?php declare(strict_types=1);

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap
$app = new App\Core\App();
$request = new App\Core\Request();

// --- Register routes ---
// (For Chunk 1.1, register demo routes to prove the framework works)

$router = $app->router();

$router->get('/', function($request) use ($app) {
    return new App\Core\Response(
        $app->template()->render('public/home', [
            'title' => App\Core\Config::getString('site_name'),
        ])
    );
});

// Demo: admin group with placeholder
$router->group('/admin', function($router) use ($app) {
    $router->get('/dashboard', function($request) use ($app) {
        return new App\Core\Response(
            $app->template()->render('admin/dashboard', [
                'title' => 'Dashboard',
            ])
        );
    });
});

// --- Register global middleware ---
// (Example: a simple timing/logging middleware for testing)

// --- Run ---
$app->run($request);
