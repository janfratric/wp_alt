<?php declare(strict_types=1);

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Database\Migrator;

// Bootstrap
$app = new App();
$request = new Request();

// --- Database bootstrap ---
$db = Connection::getInstance();
$app->register('db', $db);

$migrator = new Migrator($db);
$migrator->migrate();

// --- Register routes ---
// (For Chunk 1.1, register demo routes to prove the framework works)

$router = $app->router();

$router->get('/', function($request) use ($app) {
    return new Response(
        $app->template()->render('public/home', [
            'title' => Config::getString('site_name'),
        ])
    );
});

// Demo: admin group with placeholder
$router->group('/admin', function($router) use ($app) {
    $router->get('/dashboard', function($request) use ($app) {
        return new Response(
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
