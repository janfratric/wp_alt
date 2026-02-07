<?php declare(strict_types=1);

/**
 * Chunk 1.3 — Authentication System
 * Automated Verification Tests
 *
 * Tests:
 *   1. Auth classes are autoloadable
 *   2. Session class: start, get, set, has, remove
 *   3. Session flash messages: set then get-and-remove
 *   [SMOKE STOP]
 *   4. TemplateEngine::csrfField() returns hidden input HTML
 *   5. CsrfMiddleware generates token on GET request
 *   6. CsrfMiddleware passes valid CSRF token on POST
 *   7. CsrfMiddleware blocks missing/invalid CSRF token with 403
 *   8. AuthMiddleware allows non-admin routes through
 *   9. AuthMiddleware allows /admin/login through (unauthenticated)
 *  10. AuthMiddleware blocks unauthenticated /admin/dashboard (redirect)
 *  11. AuthMiddleware allows authenticated /admin/dashboard
 *  12. Default admin bootstrap creates user with bcrypt hash
 *  13. password_verify confirms default admin password is 'admin'
 *  14. AuthController::showLogin renders login page
 *  15. AuthController::handleLogin succeeds with valid credentials
 *  16. AuthController::handleLogin fails with invalid credentials
 *  17. Rate limiting blocks after 5 failed attempts
 *  18. RoleMiddleware::check() passes for correct role
 *  19. RoleMiddleware::check() returns 403 for wrong role
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
// Setup: test database + autoloader
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk13.sqlite';
$testRateLimitDir = $rootDir . '/storage/cache/rate_limit';

// Clean up any previous test database
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

$autoloadPath = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found — run composer install');
    echo "\n[FAIL] Cannot continue — autoloader missing\n";
    exit(1);
}
require_once $autoloadPath;

// Override config to use the test database
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $testDbPath);

// Reset Config cache and Connection singleton
$configReflection = new ReflectionClass(\App\Core\Config::class);
$configProp = $configReflection->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, null);

if (class_exists(\App\Database\Connection::class)) {
    \App\Database\Connection::reset();
}

// Run migrations on the test database
$pdo = \App\Database\Connection::getInstance();
$migrator = new \App\Database\Migrator($pdo);
$migrator->migrate();

// ---------------------------------------------------------------------------
// Test 1: Auth classes are autoloadable
// ---------------------------------------------------------------------------
$requiredClasses = [
    'App\\Auth\\Session',
    'App\\Auth\\CsrfMiddleware',
    'App\\Auth\\AuthMiddleware',
    'App\\Auth\\RoleMiddleware',
    'App\\Auth\\AuthController',
];

$allClassesFound = true;
foreach ($requiredClasses as $class) {
    if (!class_exists($class)) {
        test_fail("Class {$class} is autoloadable", 'class not found');
        $allClassesFound = false;
    }
}
if ($allClassesFound) {
    test_pass('All auth classes are autoloadable (Session, CsrfMiddleware, AuthMiddleware, RoleMiddleware, AuthController)');
}

// ---------------------------------------------------------------------------
// Test 2: Session class — start, get, set, has, remove
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::start();

    \App\Auth\Session::set('test_key', 'test_value');
    $val = \App\Auth\Session::get('test_key');

    if ($val === 'test_value') {
        $hasKey = \App\Auth\Session::has('test_key');
        \App\Auth\Session::remove('test_key');
        $afterRemove = \App\Auth\Session::get('test_key');

        if ($hasKey === true && $afterRemove === null) {
            test_pass('Session get/set/has/remove work correctly');
        } else {
            test_fail('Session has/remove', "has={$hasKey}, afterRemove=" . var_export($afterRemove, true));
        }
    } else {
        test_fail('Session get/set', "expected 'test_value', got: " . var_export($val, true));
    }

    // Test default value
    $missing = \App\Auth\Session::get('nonexistent', 'default_val');
    if ($missing === 'default_val') {
        test_pass('Session::get() returns default for missing key');
    } else {
        test_fail('Session::get() default', "expected 'default_val', got: " . var_export($missing, true));
    }
} catch (\Throwable $e) {
    test_fail('Session class works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 3: Session flash messages — set then get-and-remove
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('error', 'Something went wrong');

    // Flash should be readable once
    $msg = \App\Auth\Session::flash('error');
    if ($msg === 'Something went wrong') {
        // Second read should return null (consumed)
        $msg2 = \App\Auth\Session::flash('error');
        if ($msg2 === null) {
            test_pass('Session flash: set, get-and-remove, second read returns null');
        } else {
            test_fail('Session flash consumed on read', "second read returned: " . var_export($msg2, true));
        }
    } else {
        test_fail('Session flash get', "expected 'Something went wrong', got: " . var_export($msg, true));
    }
} catch (\Throwable $e) {
    test_fail('Session flash messages work without errors', $e->getMessage());
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    // Cleanup
    \App\Database\Connection::reset();
    $configProp->setValue(null, null);
    putenv('DB_DRIVER');
    putenv('DB_PATH');
    if (file_exists($testDbPath)) { unlink($testDbPath); }
    foreach ([$testDbPath . '-wal', $testDbPath . '-shm'] as $f) {
        if (file_exists($f)) { unlink($f); }
    }
    echo "\nChunk 1.3 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: TemplateEngine::csrfField() returns hidden input HTML
// ---------------------------------------------------------------------------
try {
    // Ensure a CSRF token exists in the session
    $_SESSION['csrf_token'] = 'test_csrf_token_value_abc123';

    $engine = new \App\Templates\TemplateEngine($rootDir . '/templates');

    if (!method_exists($engine, 'csrfField')) {
        test_fail('TemplateEngine has csrfField() method', 'method does not exist');
    } else {
        $html = $engine->csrfField();

        $hasInput = str_contains($html, '<input');
        $hasName = str_contains($html, 'name="_csrf_token"');
        $hasValue = str_contains($html, 'test_csrf_token_value_abc123');
        $hasHidden = str_contains($html, 'type="hidden"');

        if ($hasInput && $hasName && $hasValue && $hasHidden) {
            test_pass('TemplateEngine::csrfField() returns hidden input with CSRF token');
        } else {
            test_fail('TemplateEngine::csrfField() output', "got: {$html}");
        }
    }

    // Clean up test token
    unset($_SESSION['csrf_token']);
} catch (\Throwable $e) {
    test_fail('TemplateEngine::csrfField() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Helper: create a Request with specific superglobals
// ---------------------------------------------------------------------------
function makeRequest(string $method, string $uri, array $post = [], array $server = []): \App\Core\Request
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_POST = $post;
    $_GET = [];
    $_COOKIE = [];
    foreach ($server as $k => $v) {
        $_SERVER[$k] = $v;
    }
    return new \App\Core\Request();
}

// Pass-through handler for middleware tests
function passHandler(): \Closure
{
    return function (\App\Core\Request $req): \App\Core\Response {
        return new \App\Core\Response('passed', 200);
    };
}

// ---------------------------------------------------------------------------
// Test 5: CsrfMiddleware generates token on GET request
// ---------------------------------------------------------------------------
try {
    // Clear any existing token
    unset($_SESSION['csrf_token']);

    $request = makeRequest('GET', '/');
    $response = \App\Auth\CsrfMiddleware::handle($request, passHandler());

    $token = $_SESSION['csrf_token'] ?? null;

    if ($token !== null && strlen($token) === 64 && $response->getStatus() === 200) {
        test_pass('CsrfMiddleware generates 64-char hex token on GET request');
    } else {
        $len = $token !== null ? strlen($token) : 'null';
        test_fail('CsrfMiddleware token generation', "token length: {$len}, status: {$response->getStatus()}");
    }
} catch (\Throwable $e) {
    test_fail('CsrfMiddleware GET request works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: CsrfMiddleware passes valid CSRF token on POST
// ---------------------------------------------------------------------------
try {
    $validToken = $_SESSION['csrf_token'];
    $request = makeRequest('POST', '/admin/login', ['_csrf_token' => $validToken]);

    $handlerCalled = false;
    $next = function (\App\Core\Request $req) use (&$handlerCalled): \App\Core\Response {
        $handlerCalled = true;
        return new \App\Core\Response('ok', 200);
    };

    $response = \App\Auth\CsrfMiddleware::handle($request, $next);

    if ($handlerCalled && $response->getStatus() === 200) {
        test_pass('CsrfMiddleware passes through when valid CSRF token is provided');
    } else {
        test_fail('CsrfMiddleware valid token', "handlerCalled={$handlerCalled}, status={$response->getStatus()}");
    }
} catch (\Throwable $e) {
    test_fail('CsrfMiddleware POST with valid token works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: CsrfMiddleware blocks missing/invalid CSRF token with 403
// ---------------------------------------------------------------------------
try {
    // Test with missing token
    $request = makeRequest('POST', '/admin/login', []);
    $handlerCalled = false;
    $next = function (\App\Core\Request $req) use (&$handlerCalled): \App\Core\Response {
        $handlerCalled = true;
        return new \App\Core\Response('ok', 200);
    };

    $response = \App\Auth\CsrfMiddleware::handle($request, $next);
    $blockedMissing = (!$handlerCalled && $response->getStatus() === 403);

    // Test with invalid token
    $request = makeRequest('POST', '/admin/login', ['_csrf_token' => 'invalid-token-value']);
    $handlerCalled = false;
    $response = \App\Auth\CsrfMiddleware::handle($request, $next);
    $blockedInvalid = (!$handlerCalled && $response->getStatus() === 403);

    if ($blockedMissing && $blockedInvalid) {
        test_pass('CsrfMiddleware returns 403 for missing and invalid CSRF tokens');
    } else {
        test_fail('CsrfMiddleware token blocking', "blockedMissing={$blockedMissing}, blockedInvalid={$blockedInvalid}");
    }
} catch (\Throwable $e) {
    test_fail('CsrfMiddleware token validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: AuthMiddleware allows non-admin routes through
// ---------------------------------------------------------------------------
try {
    // Clear auth session
    \App\Auth\Session::remove('user_id');

    $request = makeRequest('GET', '/');
    $response = \App\Auth\AuthMiddleware::handle($request, passHandler());

    if ($response->getStatus() === 200 && $response->getBody() === 'passed') {
        test_pass('AuthMiddleware allows non-admin route (/) through without auth');
    } else {
        test_fail('AuthMiddleware non-admin route', "status={$response->getStatus()}, body={$response->getBody()}");
    }
} catch (\Throwable $e) {
    test_fail('AuthMiddleware non-admin route works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: AuthMiddleware allows /admin/login through (unauthenticated)
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/admin/login');
    $response = \App\Auth\AuthMiddleware::handle($request, passHandler());

    if ($response->getStatus() === 200 && $response->getBody() === 'passed') {
        test_pass('AuthMiddleware allows /admin/login through without auth');
    } else {
        test_fail('AuthMiddleware /admin/login', "status={$response->getStatus()}");
    }
} catch (\Throwable $e) {
    test_fail('AuthMiddleware /admin/login works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: AuthMiddleware blocks unauthenticated /admin/dashboard (redirect)
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::remove('user_id');

    $request = makeRequest('GET', '/admin/dashboard');
    $response = \App\Auth\AuthMiddleware::handle($request, passHandler());

    $headers = $response->getHeaders();
    $location = $headers['Location'] ?? '';

    if ($response->getStatus() === 302 && str_contains($location, '/admin/login')) {
        test_pass('AuthMiddleware redirects unauthenticated /admin/dashboard to /admin/login');
    } else {
        test_fail('AuthMiddleware auth redirect', "status={$response->getStatus()}, location={$location}");
    }
} catch (\Throwable $e) {
    test_fail('AuthMiddleware unauthenticated redirect works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: AuthMiddleware allows authenticated /admin/dashboard
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::set('user_id', 1);
    \App\Auth\Session::set('user_role', 'admin');

    $request = makeRequest('GET', '/admin/dashboard');
    $response = \App\Auth\AuthMiddleware::handle($request, passHandler());

    if ($response->getStatus() === 200 && $response->getBody() === 'passed') {
        test_pass('AuthMiddleware allows authenticated user to access /admin/dashboard');
    } else {
        test_fail('AuthMiddleware authenticated access', "status={$response->getStatus()}");
    }

    // Clean up
    \App\Auth\Session::remove('user_id');
    \App\Auth\Session::remove('user_role');
} catch (\Throwable $e) {
    test_fail('AuthMiddleware authenticated access works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: Default admin bootstrap — user created with bcrypt hash
// ---------------------------------------------------------------------------
try {
    // Simulate the bootstrap logic from index.php:
    // If no users exist, create default admin
    $userCount = \App\Database\QueryBuilder::query('users')->select()->count();
    if ($userCount === 0) {
        \App\Database\QueryBuilder::query('users')->insert([
            'username'      => 'admin',
            'email'         => 'admin@localhost',
            'password_hash' => password_hash('admin', PASSWORD_BCRYPT),
            'role'          => 'admin',
        ]);
    }

    $admin = \App\Database\QueryBuilder::query('users')
        ->select('*')
        ->where('username', 'admin')
        ->first();

    if ($admin === null) {
        test_fail('Default admin user exists', 'no user with username=admin found');
    } else {
        $hash = $admin['password_hash'] ?? '';
        // bcrypt hashes start with $2y$ (or $2b$ in some PHP versions)
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2b$')) {
            test_pass('Default admin password stored as bcrypt hash (starts with $2y$)');
        } else {
            test_fail('Admin password is bcrypt', "hash does not start with \$2y\$: " . substr($hash, 0, 10) . '...');
        }

        if ($admin['role'] === 'admin') {
            test_pass('Default admin has role=admin');
        } else {
            test_fail('Default admin role', "expected 'admin', got: {$admin['role']}");
        }
    }
} catch (\Throwable $e) {
    test_fail('Default admin bootstrap works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: password_verify confirms default admin password is 'admin'
// ---------------------------------------------------------------------------
try {
    $admin = \App\Database\QueryBuilder::query('users')
        ->select('password_hash')
        ->where('username', 'admin')
        ->first();

    if ($admin !== null && password_verify('admin', $admin['password_hash'])) {
        test_pass('password_verify("admin", hash) returns true — default password works');
    } else {
        test_fail('password_verify for default admin', 'verification failed');
    }
} catch (\Throwable $e) {
    test_fail('password_verify works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: AuthController::showLogin renders login page
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::remove('user_id');

    $app = new \App\Core\App();
    $app->register('db', $pdo);

    $controller = new \App\Auth\AuthController($app);
    $request = makeRequest('GET', '/admin/login');

    $response = $controller->showLogin($request);

    if ($response->getStatus() === 200) {
        $body = $response->getBody();
        // Check for form element and input fields
        $hasForm = str_contains($body, '<form') && str_contains($body, 'method="POST"');
        $hasUsername = str_contains($body, 'name="username"');
        $hasPassword = str_contains($body, 'name="password"');
        $hasCsrf = str_contains($body, '_csrf_token');

        if ($hasForm && $hasUsername && $hasPassword && $hasCsrf) {
            test_pass('AuthController::showLogin renders login form with username, password, and CSRF fields');
        } else {
            test_fail('Login form content', "form={$hasForm}, user={$hasUsername}, pass={$hasPassword}, csrf={$hasCsrf}");
        }
    } else {
        test_fail('AuthController::showLogin returns 200', "got status: {$response->getStatus()}");
    }
} catch (\Throwable $e) {
    test_fail('AuthController::showLogin works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: AuthController::handleLogin succeeds with valid credentials
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::remove('user_id');
    \App\Auth\Session::remove('user_role');
    \App\Auth\Session::remove('user_name');

    $controller = new \App\Auth\AuthController($app);
    $request = makeRequest('POST', '/admin/login', [
        'username' => 'admin',
        'password' => 'admin',
        '_csrf_token' => $_SESSION['csrf_token'] ?? '',
    ], ['REMOTE_ADDR' => '10.200.200.1']);

    $response = $controller->handleLogin($request);
    $headers = $response->getHeaders();
    $location = $headers['Location'] ?? '';

    $loggedIn = \App\Auth\Session::get('user_id') !== null;
    $redirectToDashboard = str_contains($location, '/admin/dashboard');

    if ($loggedIn && $redirectToDashboard && $response->getStatus() === 302) {
        test_pass('AuthController::handleLogin succeeds — session set, redirect to /admin/dashboard');
    } else {
        test_fail('Login success', "loggedIn={$loggedIn}, location={$location}, status={$response->getStatus()}");
    }

    // Verify session data
    $userName = \App\Auth\Session::get('user_name');
    $userRole = \App\Auth\Session::get('user_role');
    if ($userName === 'admin' && $userRole === 'admin') {
        test_pass('Login sets session: user_name=admin, user_role=admin');
    } else {
        test_fail('Login session data', "user_name={$userName}, user_role={$userRole}");
    }

    // Clean up login state for next tests
    \App\Auth\Session::remove('user_id');
    \App\Auth\Session::remove('user_role');
    \App\Auth\Session::remove('user_name');
} catch (\Throwable $e) {
    test_fail('AuthController::handleLogin success works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: AuthController::handleLogin fails with invalid credentials
// ---------------------------------------------------------------------------
try {
    $controller = new \App\Auth\AuthController($app);
    $request = makeRequest('POST', '/admin/login', [
        'username' => 'admin',
        'password' => 'wrong_password',
        '_csrf_token' => $_SESSION['csrf_token'] ?? '',
    ], ['REMOTE_ADDR' => '10.200.200.2']);

    $response = $controller->handleLogin($request);
    $headers = $response->getHeaders();
    $location = $headers['Location'] ?? '';

    $notLoggedIn = \App\Auth\Session::get('user_id') === null;
    $redirectToLogin = str_contains($location, '/admin/login');

    // Read and discard flash message
    $flashError = \App\Auth\Session::flash('error');

    if ($notLoggedIn && $redirectToLogin && $response->getStatus() === 302 && $flashError !== null) {
        test_pass('AuthController::handleLogin fails — no session, redirect to /admin/login, flash error set');
    } else {
        test_fail('Login failure', "notLoggedIn={$notLoggedIn}, location={$location}, flash=" . var_export($flashError, true));
    }

    // Clean up rate limit file for this IP
    $rlFile = $testRateLimitDir . '/' . hash('sha256', '10.200.200.2') . '.json';
    if (file_exists($rlFile)) { @unlink($rlFile); }
} catch (\Throwable $e) {
    test_fail('AuthController::handleLogin failure works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 17: Rate limiting blocks after 5 failed attempts
// ---------------------------------------------------------------------------
try {
    $testIp = '10.99.99.99';
    $controller = new \App\Auth\AuthController($app);

    // Make 5 failed login attempts
    for ($i = 1; $i <= 5; $i++) {
        $request = makeRequest('POST', '/admin/login', [
            'username' => 'admin',
            'password' => 'wrong_password',
            '_csrf_token' => $_SESSION['csrf_token'] ?? '',
        ], ['REMOTE_ADDR' => $testIp]);

        $controller->handleLogin($request);
        // Consume the flash message
        \App\Auth\Session::flash('error');
    }

    // 6th attempt — should be rate limited
    $request = makeRequest('POST', '/admin/login', [
        'username' => 'admin',
        'password' => 'admin', // Even correct password should be blocked
        '_csrf_token' => $_SESSION['csrf_token'] ?? '',
    ], ['REMOTE_ADDR' => $testIp]);

    $response = $controller->handleLogin($request);
    $flashError = \App\Auth\Session::flash('error') ?? '';
    $notLoggedIn = \App\Auth\Session::get('user_id') === null;

    $isBlocked = str_contains(strtolower($flashError), 'too many') || str_contains(strtolower($flashError), '15 minute');

    if ($notLoggedIn && $isBlocked) {
        test_pass('Rate limiting: 6th attempt blocked after 5 failures — "too many attempts" message');
    } else {
        test_fail('Rate limiting', "notLoggedIn={$notLoggedIn}, flash={$flashError}");
    }

    // Clean up rate limit file
    $rlFile = $testRateLimitDir . '/' . hash('sha256', $testIp) . '.json';
    if (file_exists($rlFile)) { @unlink($rlFile); }
} catch (\Throwable $e) {
    test_fail('Rate limiting works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: RoleMiddleware::check() passes for correct role
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::set('user_role', 'admin');

    $result = \App\Auth\RoleMiddleware::check('admin');
    if ($result === null) {
        test_pass('RoleMiddleware::check("admin") returns null when user is admin');
    } else {
        test_fail('RoleMiddleware check pass', "expected null, got Response with status {$result->getStatus()}");
    }
} catch (\Throwable $e) {
    test_fail('RoleMiddleware::check() pass case works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: RoleMiddleware::check() returns 403 for wrong role
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::set('user_role', 'editor');

    $result = \App\Auth\RoleMiddleware::check('admin');
    if ($result !== null && $result->getStatus() === 403) {
        test_pass('RoleMiddleware::check("admin") returns 403 when user is editor');
    } else {
        $status = $result !== null ? $result->getStatus() : 'null';
        test_fail('RoleMiddleware check deny', "expected 403 Response, got: {$status}");
    }

    // Clean up
    \App\Auth\Session::remove('user_role');
} catch (\Throwable $e) {
    test_fail('RoleMiddleware::check() deny case works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
\App\Database\Connection::reset();
$configProp->setValue(null, null);
putenv('DB_DRIVER');
putenv('DB_PATH');

// Remove test database
if (file_exists($testDbPath)) { unlink($testDbPath); }
foreach ([$testDbPath . '-wal', $testDbPath . '-shm'] as $f) {
    if (file_exists($f)) { unlink($f); }
}

// Remove any lingering rate limit files from tests
if (is_dir($testRateLimitDir)) {
    foreach (glob($testRateLimitDir . '/*.json') as $rlFile) {
        @unlink($rlFile);
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 1.3 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
