<?php declare(strict_types=1);

/**
 * Chunk 1.1 — Project Scaffolding & Core Framework
 * Automated Verification Tests
 *
 * Tests:
 *   1. Composer autoloading works (classes are loadable)
 *   2. Config system reads values with type safety
 *   3. Request class parses method, URI, and input
 *   4. Response class builds HTML, JSON, and redirect responses
 *   5. Router dispatches routes with parameters
 *   6. Router returns null for undefined routes (404)
 *   7. Middleware pipeline executes in order
 *   8. TemplateEngine renders templates with layout support
 *   9. App class orchestrates routing and returns responses
 *
 * Smoke mode (LITECMS_TEST_SMOKE=1): runs only tests 1-3
 */

$rootDir = dirname(__DIR__);
$isSmoke = (getenv('LITECMS_TEST_SMOKE') === '1');

$pass = 0;
$fail = 0;

function test_pass(string $description): void {
    global $pass;
    $pass++;
    echo "[PASS] {$description}\n";
}

function test_fail(string $description, string $reason = ''): void {
    global $fail;
    $fail++;
    $detail = $reason ? " — {$reason}" : '';
    echo "[FAIL] {$description}{$detail}\n";
}

function test_skip(string $description): void {
    echo "[SKIP] {$description}\n";
}

// ---------------------------------------------------------------------------
// Test 1: Composer autoloading works
// ---------------------------------------------------------------------------
$autoloadPath = $rootDir . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found — run composer install');
    // Cannot continue without autoloader
    echo "\n[FAIL] Cannot continue — autoloader missing\n";
    exit(1);
}

require_once $autoloadPath;
test_pass('Composer autoload exists');

$requiredClasses = [
    'App\\Core\\Config',
    'App\\Core\\Request',
    'App\\Core\\Response',
    'App\\Core\\Router',
    'App\\Core\\Middleware',
    'App\\Core\\App',
    'App\\Templates\\TemplateEngine',
];

$allClassesFound = true;
foreach ($requiredClasses as $class) {
    if (!class_exists($class)) {
        test_fail("Class {$class} is autoloadable", 'class not found');
        $allClassesFound = false;
    }
}
if ($allClassesFound) {
    test_pass('All core classes are autoloadable (Config, Request, Response, Router, Middleware, App, TemplateEngine)');
}

// ---------------------------------------------------------------------------
// Test 2: Config system reads values with type safety
// ---------------------------------------------------------------------------
$configPath = $rootDir . '/config/app.php';

if (!file_exists($configPath)) {
    test_fail('Config file exists', 'config/app.php not found');
} else {
    test_pass('Config file exists');

    try {
        $siteName = \App\Core\Config::getString('site_name');
        if (!empty($siteName)) {
            test_pass("Config::getString('site_name') returns non-empty string: \"{$siteName}\"");
        } else {
            test_fail("Config::getString('site_name') returns non-empty string", 'got empty string');
        }

        $itemsPerPage = \App\Core\Config::getInt('items_per_page');
        if (is_int($itemsPerPage) && $itemsPerPage > 0) {
            test_pass("Config::getInt('items_per_page') returns positive int: {$itemsPerPage}");
        } else {
            test_fail("Config::getInt('items_per_page') returns positive int", "got: " . var_export($itemsPerPage, true));
        }

        $defaultVal = \App\Core\Config::get('nonexistent_key', 'fallback');
        if ($defaultVal === 'fallback') {
            test_pass('Config::get() returns default for missing key');
        } else {
            test_fail('Config::get() returns default for missing key', "got: " . var_export($defaultVal, true));
        }
    } catch (\Throwable $e) {
        test_fail('Config system works without errors', $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Test 3: Request class parses method, URI, and input
// ---------------------------------------------------------------------------
try {
    // Simulate a GET request by setting superglobals
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/admin/dashboard?page=1';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_GET = ['page' => '1'];
    $_POST = [];
    $_COOKIE = [];

    $request = new \App\Core\Request();

    if ($request->method() === 'GET') {
        test_pass('Request::method() returns GET');
    } else {
        test_fail('Request::method() returns GET', "got: {$request->method()}");
    }

    $uri = $request->uri();
    if ($uri === '/admin/dashboard') {
        test_pass('Request::uri() strips query string: /admin/dashboard');
    } else {
        test_fail('Request::uri() strips query string', "expected /admin/dashboard, got: {$uri}");
    }

    $page = $request->query('page');
    if ($page === '1') {
        test_pass('Request::query() reads GET params');
    } else {
        test_fail('Request::query() reads GET params', "expected '1', got: " . var_export($page, true));
    }

    // Test _method override for PUT
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['_method' => 'PUT', 'title' => 'Test'];
    $request2 = new \App\Core\Request();

    if ($request2->method() === 'PUT') {
        test_pass('Request supports _method override for PUT');
    } else {
        test_fail('Request supports _method override for PUT', "got: {$request2->method()}");
    }
} catch (\Throwable $e) {
    test_fail('Request class works without errors', $e->getMessage());
}

if ($isSmoke) {
    // Smoke mode — stop here
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: Response class builds HTML, JSON, and redirect responses
// ---------------------------------------------------------------------------
try {
    $htmlResp = \App\Core\Response::html('<h1>Hello</h1>', 200);
    if ($htmlResp->getStatus() === 200 && str_contains($htmlResp->getBody(), '<h1>Hello</h1>')) {
        test_pass('Response::html() builds 200 HTML response');
    } else {
        test_fail('Response::html() builds 200 HTML response');
    }

    $jsonResp = \App\Core\Response::json(['ok' => true], 201);
    $decoded = json_decode($jsonResp->getBody(), true);
    if ($jsonResp->getStatus() === 201 && $decoded === ['ok' => true]) {
        test_pass('Response::json() builds JSON response with correct status');
    } else {
        test_fail('Response::json() builds JSON response with correct status');
    }

    $redirect = \App\Core\Response::redirect('/login', 302);
    $headers = $redirect->getHeaders();
    if ($redirect->getStatus() === 302 && ($headers['Location'] ?? '') === '/login') {
        test_pass('Response::redirect() sets Location header and 302 status');
    } else {
        test_fail('Response::redirect() sets Location header and 302 status');
    }

    $fluent = (new \App\Core\Response('body'))
        ->withStatus(418)
        ->withHeader('X-Custom', 'value');
    if ($fluent->getStatus() === 418 && ($fluent->getHeaders()['X-Custom'] ?? '') === 'value') {
        test_pass('Response fluent methods (withStatus, withHeader) work');
    } else {
        test_fail('Response fluent methods (withStatus, withHeader) work');
    }
} catch (\Throwable $e) {
    test_fail('Response class works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: Router dispatches routes with parameters
// ---------------------------------------------------------------------------
try {
    $router = new \App\Core\Router();

    $router->get('/', function ($req) {
        return new \App\Core\Response('home');
    });

    $router->get('/posts/{id}', function ($req, $id) {
        return new \App\Core\Response("post-{$id}");
    });

    $router->post('/posts', function ($req) {
        return new \App\Core\Response('created');
    });

    // Test: GET / matches
    $match = $router->dispatch('GET', '/');
    if ($match !== null && isset($match['handler'])) {
        test_pass('Router dispatches GET /');
    } else {
        test_fail('Router dispatches GET /', 'no match returned');
    }

    // Test: GET /posts/42 matches with param
    $match = $router->dispatch('GET', '/posts/42');
    if ($match !== null && ($match['params']['id'] ?? '') === '42') {
        test_pass('Router extracts route parameters: /posts/{id} -> id=42');
    } else {
        test_fail('Router extracts route parameters', var_export($match, true));
    }

    // Test: POST /posts matches
    $match = $router->dispatch('POST', '/posts');
    if ($match !== null) {
        test_pass('Router dispatches POST /posts');
    } else {
        test_fail('Router dispatches POST /posts');
    }

    // Test: route grouping
    $router->group('/admin', function ($r) {
        $r->get('/dashboard', function ($req) {
            return new \App\Core\Response('dashboard');
        });
    });

    $match = $router->dispatch('GET', '/admin/dashboard');
    if ($match !== null) {
        test_pass('Router group prefix works: /admin/dashboard');
    } else {
        test_fail('Router group prefix works', 'no match for /admin/dashboard');
    }
} catch (\Throwable $e) {
    test_fail('Router works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: Router returns null for undefined routes (404)
// ---------------------------------------------------------------------------
try {
    $router2 = new \App\Core\Router();
    $router2->get('/', function ($req) { return new \App\Core\Response('home'); });

    $match = $router2->dispatch('GET', '/nonexistent');
    if ($match === null) {
        test_pass('Router returns null for undefined route (404 case)');
    } else {
        test_fail('Router returns null for undefined route', 'got a match when none expected');
    }

    // Wrong method should also not match
    $match = $router2->dispatch('POST', '/');
    if ($match === null) {
        test_pass('Router returns null for wrong HTTP method');
    } else {
        test_fail('Router returns null for wrong HTTP method');
    }
} catch (\Throwable $e) {
    test_fail('Router 404 handling works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: Middleware pipeline executes in order
// ---------------------------------------------------------------------------
try {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_GET = [];
    $_POST = [];
    $request = new \App\Core\Request();

    $order = [];

    $mw1 = function (\App\Core\Request $req, callable $next) use (&$order): \App\Core\Response {
        $order[] = 'A-before';
        $response = $next($req);
        $order[] = 'A-after';
        return $response;
    };

    $mw2 = function (\App\Core\Request $req, callable $next) use (&$order): \App\Core\Response {
        $order[] = 'B-before';
        $response = $next($req);
        $order[] = 'B-after';
        return $response;
    };

    $handler = function (\App\Core\Request $req) use (&$order): \App\Core\Response {
        $order[] = 'handler';
        return new \App\Core\Response('ok');
    };

    $response = \App\Core\Middleware::run($request, [$mw1, $mw2], $handler);

    $expected = ['A-before', 'B-before', 'handler', 'B-after', 'A-after'];
    if ($order === $expected) {
        test_pass('Middleware pipeline executes in correct order (A-before, B-before, handler, B-after, A-after)');
    } else {
        test_fail('Middleware pipeline order', 'expected: ' . implode(', ', $expected) . ' — got: ' . implode(', ', $order));
    }

    // Test short-circuit: middleware that returns without calling $next
    $blocker = function (\App\Core\Request $req, callable $next): \App\Core\Response {
        return new \App\Core\Response('blocked', 403);
    };

    $reached = false;
    $afterHandler = function (\App\Core\Request $req) use (&$reached): \App\Core\Response {
        $reached = true;
        return new \App\Core\Response('ok');
    };

    $response = \App\Core\Middleware::run($request, [$blocker], $afterHandler);
    if (!$reached && $response->getStatus() === 403) {
        test_pass('Middleware can short-circuit (block request, return 403)');
    } else {
        test_fail('Middleware short-circuit', $reached ? 'handler was reached' : 'wrong status');
    }
} catch (\Throwable $e) {
    test_fail('Middleware pipeline works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: TemplateEngine renders templates with layout support
// ---------------------------------------------------------------------------
try {
    $templatesDir = $rootDir . '/templates';

    if (!is_dir($templatesDir)) {
        test_fail('Templates directory exists', 'templates/ not found');
    } else {
        $engine = new \App\Templates\TemplateEngine($templatesDir);

        // Test: render a template that uses a layout
        $homeTemplate = $templatesDir . '/public/home.php';
        $layoutTemplate = $templatesDir . '/public/layout.php';

        if (!file_exists($homeTemplate) || !file_exists($layoutTemplate)) {
            test_fail('Public home and layout templates exist');
        } else {
            $html = $engine->render('public/home', ['title' => 'TestSite']);

            if (str_contains($html, 'TestSite')) {
                test_pass('TemplateEngine renders template with data (title visible in output)');
            } else {
                test_fail('TemplateEngine renders template with data', 'title not found in output');
            }

            if (str_contains($html, '<!DOCTYPE html') || str_contains($html, '<html')) {
                test_pass('TemplateEngine wraps content in layout (HTML structure present)');
            } else {
                test_fail('TemplateEngine wraps content in layout', 'no HTML document structure in output');
            }
        }

        // Test: escape helper
        $escaped = $engine->e('<script>alert("xss")</script>');
        if (!str_contains($escaped, '<script>')) {
            test_pass('TemplateEngine::e() escapes HTML (XSS protection)');
        } else {
            test_fail('TemplateEngine::e() escapes HTML', 'raw <script> tag in output');
        }
    }
} catch (\Throwable $e) {
    test_fail('TemplateEngine works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: Directory structure exists
// ---------------------------------------------------------------------------
$requiredDirs = [
    'storage/logs',
    'storage/cache',
    'public/assets/css',
    'public/assets/js',
    'public/assets/uploads',
    'templates/auth',
    'migrations',
];

$allDirsExist = true;
foreach ($requiredDirs as $dir) {
    if (!is_dir($rootDir . '/' . $dir)) {
        $allDirsExist = false;
        test_fail("Directory exists: {$dir}");
    }
}
if ($allDirsExist) {
    test_pass('All required directories exist (storage, public/assets, templates/auth, migrations)');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 1.1 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
