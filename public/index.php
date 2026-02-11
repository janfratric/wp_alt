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
use App\AIAssistant\AIController;
use App\Admin\SettingsController;
use App\Admin\ContentTypeController;
use App\AIAssistant\PageGeneratorController;
use App\Admin\ElementController;
use App\Admin\StyleController;
use App\Admin\LayoutController;
use App\AIAssistant\ElementAIController;
use App\Admin\DesignController;
use App\PageBuilder\SeedElements;

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

// --- Seed native elements (idempotent — skips existing slugs) ---
try {
    SeedElements::seed();
} catch (\Throwable $e) {
    // Table may not exist yet on first run — ignore
}

// --- Seed homepage content if it doesn't exist ---
try {
    $homeExists = QueryBuilder::query('content')
        ->select('id')
        ->where('slug', 'home')
        ->where('type', 'page')
        ->first();
    if ($homeExists === null) {
        $adminUser = QueryBuilder::query('users')
            ->select('id')
            ->where('role', 'admin')
            ->first();
        $authorId = $adminUser ? (string) $adminUser['id'] : '1';
        QueryBuilder::query('content')->insert([
            'type'        => 'page',
            'title'       => 'Home',
            'slug'        => 'home',
            'body'        => '',
            'excerpt'     => '',
            'status'      => 'published',
            'author_id'   => $authorId,
            'sort_order'  => '-1',
            'editor_mode' => 'elements',
        ]);
    }
} catch (\Throwable $e) {
    // Table may not exist yet — ignore
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
$router->get('/contact', [FrontController::class, 'contactPage']);
$router->post('/contact', [FrontController::class, 'contactSubmit']);

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

    // Settings routes
    $router->get('/settings', [SettingsController::class, 'index']);
    $router->put('/settings', [SettingsController::class, 'update']);

    // Master Style routes
    $router->get('/style', [StyleController::class, 'index']);
    $router->put('/style', [StyleController::class, 'update']);

    // Layout template routes
    $router->get('/layouts', [LayoutController::class, 'index']);
    $router->get('/layouts/create', [LayoutController::class, 'create']);
    $router->post('/layouts', [LayoutController::class, 'store']);
    $router->get('/layouts/{id}/edit', [LayoutController::class, 'edit']);
    $router->put('/layouts/{id}', [LayoutController::class, 'update']);
    $router->delete('/layouts/{id}', [LayoutController::class, 'delete']);

    // Content type management routes
    $router->get('/content-types', [ContentTypeController::class, 'index']);
    $router->get('/content-types/create', [ContentTypeController::class, 'create']);
    $router->post('/content-types', [ContentTypeController::class, 'store']);
    $router->get('/content-types/{id}/edit', [ContentTypeController::class, 'edit']);
    $router->put('/content-types/{id}', [ContentTypeController::class, 'update']);
    $router->delete('/content-types/{id}', [ContentTypeController::class, 'delete']);

    // Element catalogue routes
    $router->get('/elements', [ElementController::class, 'index']);
    $router->get('/elements/api/list', [ElementController::class, 'apiList']);
    $router->get('/elements/create', [ElementController::class, 'create']);
    $router->post('/elements', [ElementController::class, 'store']);
    $router->get('/elements/{id}/edit', [ElementController::class, 'edit']);
    $router->get('/elements/{id}/preview', [ElementController::class, 'preview']);
    $router->post('/elements/{id}/preview', [ElementController::class, 'preview']);
    $router->put('/elements/{id}', [ElementController::class, 'update']);
    $router->delete('/elements/{id}', [ElementController::class, 'delete']);

    // AI Assistant routes
    $router->post('/ai/chat', [AIController::class, 'chat']);
    $router->get('/ai/conversations', [AIController::class, 'conversations']);
    $router->post('/ai/compact', [AIController::class, 'compact']);
    $router->get('/ai/models/enabled', [AIController::class, 'enabledModels']);
    $router->post('/ai/models/fetch', [AIController::class, 'fetchModels']);
    $router->post('/ai/models/enable', [AIController::class, 'saveEnabledModels']);
    $router->post('/ai/transcribe', [AIController::class, 'transcribe']);

    // Element AI Assistant
    $router->post('/ai/element/chat', [ElementAIController::class, 'chat']);
    $router->get('/ai/element/conversations', [ElementAIController::class, 'conversations']);

    // Element proposals
    $router->get('/element-proposals', [ElementController::class, 'proposals']);
    $router->post('/element-proposals/{id}/approve', [ElementController::class, 'approveProposal']);
    $router->post('/element-proposals/{id}/reject', [ElementController::class, 'rejectProposal']);

    // AI Page Generator
    $router->get('/generator', [PageGeneratorController::class, 'index']);
    $router->post('/generator/chat', [PageGeneratorController::class, 'chat']);
    $router->post('/generator/create', [PageGeneratorController::class, 'create']);

    // Design Editor (Chunk 7.1)
    $router->get('/design/editor', [DesignController::class, 'editor']);
    $router->get('/design/load', [DesignController::class, 'load']);
    $router->post('/design/save', [DesignController::class, 'save']);
    $router->post('/design/import-file', [DesignController::class, 'importFile']);
    $router->get('/design/list', [DesignController::class, 'list']);

    // Design Converter (Chunk 7.2)
    $router->post('/design/convert', [DesignController::class, 'convert']);
    $router->get('/design/preview', [DesignController::class, 'preview']);
});

// Dynamic routes for custom content type archives and single items
try {
    $customTypes = QueryBuilder::query('content_types')
        ->select('slug', 'has_archive')
        ->get();

    foreach ($customTypes as $ct) {
        if ((int)$ct['has_archive'] === 1) {
            $router->get('/' . $ct['slug'], [FrontController::class, 'archive']);
        }
        // Single custom type item: /type-slug/item-slug
        $router->get('/' . $ct['slug'] . '/{slug}', [FrontController::class, 'page']);
    }
} catch (\Throwable $e) {
    // Table might not exist yet during first migration run — silently skip
}

// Catch-all for pages by slug (MUST be last)
$router->get('/{slug}', [FrontController::class, 'page']);

// --- Run ---
$app->run($request);
