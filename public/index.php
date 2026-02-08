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
use App\Admin\DashboardController;
use App\Admin\ContentController;
use App\Admin\MediaController;
use App\Admin\UserController;
use App\Templates\FrontController;

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

// Public routes (order matters — specific routes before catch-all)
$router->get('/', [FrontController::class, 'homepage']);
$router->get('/blog', [FrontController::class, 'blogIndex']);
$router->get('/blog/{slug}', [FrontController::class, 'blogPost']);

// Auth routes
$router->get('/admin/login', [AuthController::class, 'showLogin']);
$router->post('/admin/login', [AuthController::class, 'handleLogin']);
$router->post('/admin/logout', [AuthController::class, 'logout']);

// Admin routes (protected by AuthMiddleware)
$router->group('/admin', function($router) use ($app) {
    // Dashboard
    $router->get('/dashboard', [DashboardController::class, 'index']);

    // Content CRUD routes
    $router->get('/content', [ContentController::class, 'index']);
    $router->get('/content/create', [ContentController::class, 'create']);
    $router->post('/content', [ContentController::class, 'store']);
    $router->get('/content/{id}/edit', [ContentController::class, 'edit']);
    $router->put('/content/{id}', [ContentController::class, 'update']);
    $router->delete('/content/{id}', [ContentController::class, 'delete']);
    $router->post('/content/bulk', [ContentController::class, 'bulk']);

    // Media management routes
    $router->get('/media', [MediaController::class, 'index']);
    $router->get('/media/browse', [MediaController::class, 'browse']);
    $router->post('/media/upload', [MediaController::class, 'upload']);
    $router->delete('/media/{id}', [MediaController::class, 'delete']);

    // User management routes
    $router->get('/users', [UserController::class, 'index']);
    $router->get('/users/create', [UserController::class, 'create']);
    $router->post('/users', [UserController::class, 'store']);
    $router->get('/users/{id}/edit', [UserController::class, 'edit']);
    $router->put('/users/{id}', [UserController::class, 'update']);
    $router->delete('/users/{id}', [UserController::class, 'delete']);

    // Placeholder routes for sidebar links (to be replaced in future chunks)
    $router->get('/settings', function($request) use ($app) {
        return new Response(
            $app->template()->render('admin/placeholder', [
                'title' => 'Settings',
                'activeNav' => 'settings',
                'message' => 'Settings panel is coming in Chunk 5.2.',
            ])
        );
    });
});

// Catch-all for pages by slug (MUST be last)
$router->get('/{slug}', [FrontController::class, 'page']);

// --- Run ---
$app->run($request);
