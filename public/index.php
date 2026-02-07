<?php declare(strict_types=1);

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\Connection;
use App\Database\Migrator;
use App\Database\QueryBuilder;
use App\Auth\Session;
use App\Auth\CsrfMiddleware;
use App\Auth\AuthMiddleware;
use App\Auth\AuthController;

// Bootstrap
$app = new App();
$request = new Request();

// --- Database bootstrap ---
$db = Connection::getInstance();
$app->register('db', $db);

$migrator = new Migrator($db);
$migrator->migrate();

// --- Session bootstrap ---
Session::start();

// --- First-run admin bootstrap ---
// If no users exist, create the default admin account.
$userCount = QueryBuilder::query('users')->select()->count();
if ($userCount === 0) {
    QueryBuilder::query('users')->insert([
        'username'      => 'admin',
        'email'         => 'admin@localhost',
        'password_hash' => password_hash('admin', PASSWORD_BCRYPT),
        'role'          => 'admin',
    ]);
}

// --- Register global middleware ---
// Order matters: first added = first executed (outermost).
// 1. CSRF: validates tokens on POST/PUT/DELETE for ALL routes
// 2. Auth: protects /admin/* routes (except /admin/login)
$app->addMiddleware([CsrfMiddleware::class, 'handle']);
$app->addMiddleware([AuthMiddleware::class, 'handle']);

// --- Register routes ---

$router = $app->router();

// Public routes
$router->get('/', function($request) use ($app) {
    return new Response(
        $app->template()->render('public/home', [
            'title' => Config::getString('site_name'),
        ])
    );
});

// Auth routes
$router->get('/admin/login', [AuthController::class, 'showLogin']);
$router->post('/admin/login', [AuthController::class, 'handleLogin']);
$router->post('/admin/logout', [AuthController::class, 'logout']);

// Admin routes (protected by AuthMiddleware)
$router->group('/admin', function($router) use ($app) {
    $router->get('/dashboard', function($request) use ($app) {
        return new Response(
            $app->template()->render('admin/dashboard', [
                'title' => 'Dashboard',
            ])
        );
    });
});

// --- Run ---
$app->run($request);
