# Chunk 1.3 — Authentication System
## Detailed Implementation Plan

---

## Overview

This chunk builds the complete authentication system for LiteCMS: session management with security hardening, CSRF protection middleware, login/logout controllers, rate limiting for failed logins, role-based authorization, and a first-run admin user bootstrap. At completion, the admin area is protected — unauthenticated users are redirected to a login form, CSRF tokens guard all mutating requests, and brute-force login attempts are rate-limited.

---

## Dependencies on Previous Chunks

This chunk uses these existing components (DO NOT rewrite):

| Component | File | Used For |
|-----------|------|----------|
| `App` | `app/Core/App.php` | Service container, middleware registration, route dispatch |
| `Router` | `app/Core/Router.php` | Route registration (`get`, `post`, `group`) |
| `Request` | `app/Core/Request.php` | Input access (`input()`, `cookie()`, `server()`, `uri()`) |
| `Response` | `app/Core/Response.php` | Response building (`redirect()`, `html()`, `withHeader()`) |
| `Middleware` | `app/Core/Middleware.php` | Middleware pipeline (`run()`) |
| `Config` | `app/Core/Config.php` | Configuration access (`getString('app_secret')`) |
| `TemplateEngine` | `app/Templates/TemplateEngine.php` | Template rendering (`render()`, `e()`) |
| `Connection` | `app/Database/Connection.php` | PDO singleton |
| `QueryBuilder` | `app/Database/QueryBuilder.php` | Database CRUD (`query()`, `where()`, `first()`, `insert()`, `count()`) |

**Key integration points:**
- Controllers receive the `App` instance in constructor: `new ControllerClass($app)`
- Controllers return `Response` objects: `method(Request $request, ...$params): Response`
- Middleware signature: `fn(Request $request, callable $next): Response`
- Global middleware is registered via `$app->addMiddleware(callable)`
- Templates are rendered via `$app->template()->render('template', $data)`
- All template output uses `$this->e($value)` for XSS prevention

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it (or existing files from previous chunks).

---

### 1. `app/Auth/Session.php`

**Purpose**: Static session helper. Wraps PHP native sessions with security hardening (secure cookie flags, regeneration, flash messages). Used by all auth components and will be used by all future controllers for flash messages.

**Class**: `App\Auth\Session`

**Justification for class**: Session access is needed across AuthController, CsrfMiddleware, AuthMiddleware, templates (flash messages), and all future admin controllers. Centralizing cookie hardening and flash message logic prevents duplication.

```php
<?php declare(strict_types=1);

namespace App\Auth;

class Session
{
    private static bool $started = false;

    /**
     * Start the session with secure cookie parameters.
     * Safe to call multiple times — only starts once.
     */
    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_set_cookie_params([
            'lifetime' => 0,          // Session cookie — expires when browser closes
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,    // Only over HTTPS when available
            'httponly'  => true,       // Not accessible via JavaScript
            'samesite' => 'Lax',      // CSRF protection at cookie level
        ]);

        session_start();
        self::$started = true;
    }

    /**
     * Get a session value.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a session value.
     */
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Check if a session key exists.
     */
    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a session key.
     */
    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Regenerate the session ID (call on login to prevent session fixation).
     */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Destroy the session completely (logout).
     */
    public static function destroy(): void
    {
        $_SESSION = [];

        // Delete the session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        self::$started = false;
    }

    /**
     * Flash messages: set (2 args) or get-and-remove (1 arg).
     *
     * Set:  Session::flash('error', 'Invalid credentials.')
     * Get:  $msg = Session::flash('error')  // returns string|null, removes from session
     */
    public static function flash(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $_SESSION['_flash'][$key] = $message;
            return null;
        }

        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }
}
```

**Properties**:
- `private static bool $started = false` — prevents double-start

**Public API**:
```
Session::start(): void                                      // Start with secure cookie params
Session::get(string $key, mixed $default = null): mixed     // Read session value
Session::set(string $key, mixed $value): void               // Write session value
Session::has(string $key): bool                             // Check existence
Session::remove(string $key): void                          // Remove key
Session::regenerate(): void                                 // Regenerate session ID
Session::destroy(): void                                    // Full session destruction
Session::flash(string $key, ?string $message = null): ?string  // Flash messages (set/get)
```

---

### 2. `app/Auth/CsrfMiddleware.php`

**Purpose**: Generates a CSRF token per session and validates it on all POST/PUT/DELETE requests. Protects every form in the CMS (login, content editing, settings, etc.).

**Class**: `App\Auth\CsrfMiddleware`

**Design**:
- Token stored in `$_SESSION['csrf_token']` — generated once per session, 32 bytes hex-encoded (64 chars).
- Validated on POST/PUT/DELETE by checking `_csrf_token` form field or `X-CSRF-TOKEN` header (for AJAX).
- Uses `hash_equals()` for timing-safe comparison.
- Returns 403 on mismatch.

```php
<?php declare(strict_types=1);

namespace App\Auth;

use App\Core\Request;
use App\Core\Response;

class CsrfMiddleware
{
    public static function handle(Request $request, callable $next): Response
    {
        // Generate CSRF token if not present in session
        if (!Session::get('csrf_token')) {
            Session::set('csrf_token', bin2hex(random_bytes(32)));
        }

        // Validate on state-changing methods
        if (in_array($request->method(), ['POST', 'PUT', 'DELETE'], true)) {
            $token = (string) $request->input('_csrf_token', '');

            // Also accept token from header (for AJAX/fetch requests)
            if ($token === '') {
                $token = (string) ($request->server('HTTP_X_CSRF_TOKEN') ?? '');
            }

            $sessionToken = (string) Session::get('csrf_token', '');

            if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
                return Response::html(
                    '<h1>403 Forbidden</h1><p>Invalid or missing CSRF token.</p>',
                    403
                );
            }
        }

        return $next($request);
    }
}
```

**Middleware signature**: `(Request $request, callable $next): Response`

**Token flow**:
1. First GET request → token generated, stored in session
2. Template renders form with `<?= $this->csrfField() ?>` → hidden input with token
3. Form submitted (POST) → CsrfMiddleware reads `_csrf_token` from POST body
4. `hash_equals()` comparison → pass or 403

---

### 3. `app/Auth/AuthMiddleware.php`

**Purpose**: Protects admin routes. Checks if user is authenticated (session has `user_id`). Unauthenticated requests to `/admin/*` are redirected to `/admin/login`. Public routes and auth routes (login, logout) pass through.

**Class**: `App\Auth\AuthMiddleware`

```php
<?php declare(strict_types=1);

namespace App\Auth;

use App\Core\Request;
use App\Core\Response;

class AuthMiddleware
{
    /**
     * Paths under /admin that do NOT require authentication.
     */
    private const PUBLIC_ADMIN_PATHS = [
        '/admin/login',
    ];

    public static function handle(Request $request, callable $next): Response
    {
        $uri = $request->uri();

        // Only protect /admin routes
        if (!str_starts_with($uri, '/admin')) {
            return $next($request);
        }

        // Allow public admin paths (login page)
        if (in_array($uri, self::PUBLIC_ADMIN_PATHS, true)) {
            return $next($request);
        }

        // Check authentication
        if (!Session::get('user_id')) {
            return Response::redirect('/admin/login');
        }

        return $next($request);
    }
}
```

**Logic**:
- Non-admin routes (`/`, `/about`, `/blog`, etc.) → always pass through
- `/admin/login` → pass through (need to see login form)
- All other `/admin/*` routes → require `user_id` in session, else redirect

**Note**: `/admin/logout` is NOT in `PUBLIC_ADMIN_PATHS` because logout requires being logged in (the POST form is rendered in the admin layout). If a user's session expires before clicking logout, they'll be redirected to login (expected behavior).

---

### 4. `app/Auth/RoleMiddleware.php`

**Purpose**: Role-based authorization. Provides a static helper to check if the current user has a specific role. Used by controllers that restrict access (e.g., user management is admin-only).

**Class**: `App\Auth\RoleMiddleware`

**Design**: Since the current framework only supports global middleware (not per-route), this class provides a static `check()` method that controllers call directly. Also provides a `require()` factory that returns a middleware closure for future per-route middleware support.

```php
<?php declare(strict_types=1);

namespace App\Auth;

use App\Core\Request;
use App\Core\Response;

class RoleMiddleware
{
    /**
     * Check if current user has the required role.
     * Returns null if authorized, or a 403 Response if not.
     *
     * Usage in controllers:
     *   $denied = RoleMiddleware::check('admin');
     *   if ($denied !== null) return $denied;
     */
    public static function check(string $requiredRole): ?Response
    {
        $userRole = (string) Session::get('user_role', '');

        if ($userRole !== $requiredRole) {
            return Response::html(
                '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>',
                403
            );
        }

        return null;
    }

    /**
     * Returns a middleware closure that requires a specific role.
     * For future per-route middleware support.
     */
    public static function require(string $role): \Closure
    {
        return function (Request $request, callable $next) use ($role): Response {
            $denied = self::check($role);
            if ($denied !== null) {
                return $denied;
            }
            return $next($request);
        };
    }
}
```

**Public API**:
```
RoleMiddleware::check(string $requiredRole): ?Response       // null = OK, Response = denied
RoleMiddleware::require(string $role): \Closure              // Middleware factory (future use)
```

---

### 5. `app/Auth/AuthController.php`

**Purpose**: Handles login form display (GET), login submission (POST), and logout (POST). Includes rate limiting for failed login attempts.

**Class**: `App\Auth\AuthController`

```php
<?php declare(strict_types=1);

namespace App\Auth;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;

class AuthController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/login — Show the login form.
     */
    public function showLogin(Request $request): Response
    {
        // Already logged in? Redirect to dashboard.
        if (Session::get('user_id')) {
            return Response::redirect('/admin/dashboard');
        }

        return new Response(
            $this->app->template()->render('auth/login', [
                'title' => 'Login',
                'error' => Session::flash('error'),
                'success' => Session::flash('success'),
            ])
        );
    }

    /**
     * POST /admin/login — Authenticate the user.
     */
    public function handleLogin(Request $request): Response
    {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

        // Rate limiting: check if this IP is locked out
        $ip = (string) ($request->server('REMOTE_ADDR') ?? '127.0.0.1');

        if ($this->isRateLimited($ip)) {
            Session::flash('error', 'Too many failed login attempts. Please try again in 15 minutes.');
            return Response::redirect('/admin/login');
        }

        // Validate input
        if ($username === '' || $password === '') {
            Session::flash('error', 'Username and password are required.');
            return Response::redirect('/admin/login');
        }

        // Look up user by username
        $user = QueryBuilder::query('users')
            ->select('*')
            ->where('username', $username)
            ->first();

        // Verify password
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            $this->recordFailedAttempt($ip);
            Session::flash('error', 'Invalid username or password.');
            return Response::redirect('/admin/login');
        }

        // Authentication successful
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('user_role', $user['role']);
        Session::set('user_name', $user['username']);

        $this->clearFailedAttempts($ip);

        return Response::redirect('/admin/dashboard');
    }

    /**
     * POST /admin/logout — Destroy session and redirect to login.
     */
    public function logout(Request $request): Response
    {
        Session::destroy();
        return Response::redirect('/admin/login');
    }

    // -----------------------------------------------------------------
    // Rate limiting — file-based per-IP tracking
    // -----------------------------------------------------------------

    private function getRateLimitPath(string $ip): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cache/rate_limit';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . '/' . hash('sha256', $ip) . '.json';
    }

    private function isRateLimited(string $ip): bool
    {
        $file = $this->getRateLimitPath($ip);
        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return false;
        }

        // Check if currently locked out
        if (isset($data['locked_until']) && $data['locked_until'] > time()) {
            return true;
        }

        // Lockout expired — clear the file
        if (isset($data['locked_until']) && $data['locked_until'] <= time()) {
            @unlink($file);
            return false;
        }

        return false;
    }

    private function recordFailedAttempt(string $ip): void
    {
        $file = $this->getRateLimitPath($ip);
        $data = ['attempts' => 0, 'first_attempt_at' => time()];

        if (file_exists($file)) {
            $existing = json_decode((string) file_get_contents($file), true);
            if (is_array($existing)) {
                $data = $existing;
            }
        }

        $data['attempts'] = ($data['attempts'] ?? 0) + 1;

        // Lock out after 5 failures
        if ($data['attempts'] >= 5) {
            $data['locked_until'] = time() + (15 * 60); // 15 minutes
        }

        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    private function clearFailedAttempts(string $ip): void
    {
        $file = $this->getRateLimitPath($ip);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
```

**Properties**:
- `private App $app` — application instance (for template engine access)

**Public API**:
```
__construct(App $app)
showLogin(Request $request): Response       // GET /admin/login
handleLogin(Request $request): Response     // POST /admin/login
logout(Request $request): Response          // POST /admin/logout
```

**Rate Limiting Design**:
- File-based storage in `storage/cache/rate_limit/`
- One file per IP address (SHA-256 hash of IP as filename)
- File contents: JSON `{"attempts": N, "first_attempt_at": timestamp, "locked_until": timestamp}`
- After 5 failed attempts: `locked_until` set to `time() + 900` (15 minutes)
- On successful login: rate limit file deleted
- On lockout expiry: file deleted on next check

**Why file-based**: No schema changes needed. Works on shared hosting. Simple. Rate limit data is transient (no persistence requirement). The `storage/cache/` directory already exists from Chunk 1.1.

---

### 6. `templates/auth/layout.php`

**Purpose**: Standalone layout for the login page. Minimal, centered design without sidebar (user is not authenticated, so admin layout is inappropriate). Includes inline CSS since admin stylesheet is not yet created (comes in Chunk 2.1).

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($title ?? 'Login') ?> — LiteCMS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .auth-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.08);
            padding: 2.5rem;
            width: 100%;
            max-width: 400px;
        }
        .auth-container h1 {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
            text-align: center;
        }
        .auth-container .subtitle {
            color: #666;
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            margin-bottom: 0.35rem;
            font-weight: 500;
            font-size: 0.9rem;
        }
        .form-group input {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.15s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .btn-primary {
            width: 100%;
            padding: 0.65rem;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-primary:hover { background: #1d4ed8; }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
    </style>
</head>
<body>
    <main class="auth-container">
        <?= $this->content() ?>
    </main>
</body>
</html>
```

---

### 7. `templates/auth/login.php`

**Purpose**: Login form page. Displays username/password fields, CSRF token, flash error/success messages.

```php
<?php $this->layout('auth/layout'); ?>

<h1>LiteCMS</h1>
<p class="subtitle">Sign in to your account</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= $this->e($error) ?></div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?= $this->e($success) ?></div>
<?php endif; ?>

<form method="POST" action="/admin/login">
    <?= $this->csrfField() ?>

    <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autofocus
               autocomplete="username">
    </div>

    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required
               autocomplete="current-password">
    </div>

    <button type="submit" class="btn-primary">Sign In</button>
</form>
```

**Template variables**:
- `$title` (string) — page title (used in layout's `<title>`)
- `$error` (string|null) — flash error message
- `$success` (string|null) — flash success message

---

### 8. Modify `app/Templates/TemplateEngine.php`

**Purpose**: Add `csrfField()` helper method so templates can easily include CSRF tokens in forms.

**Change**: Add one method to the existing class.

```php
/**
 * Output a hidden CSRF token field for forms.
 */
public function csrfField(): string
{
    $token = $_SESSION['csrf_token'] ?? '';
    return '<input type="hidden" name="_csrf_token" value="' . $this->e($token) . '">';
}
```

**Why modify TemplateEngine**: Every form in the CMS needs a CSRF token. A template helper prevents forgetting to include it and standardizes the field name. This method will be called in login, content edit, media upload, settings, user management — across every form in every chunk.

---

### 9. Modify `templates/admin/layout.php`

**Purpose**: Add flash message display area and a logout form to the admin layout. Flash messages are needed for all admin operations (success/error feedback).

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($title ?? 'Admin') ?> — LiteCMS Admin</title>
</head>
<body>
    <div class="admin-wrapper">
        <aside class="sidebar">
            <h2>LiteCMS</h2>
            <nav>
                <a href="/admin/dashboard">Dashboard</a>
            </nav>
            <div class="sidebar-footer">
                <span><?= $this->e($_SESSION['user_name'] ?? '') ?></span>
                <form method="POST" action="/admin/logout" style="display:inline;">
                    <?= $this->csrfField() ?>
                    <button type="submit" style="background:none;border:none;color:inherit;cursor:pointer;text-decoration:underline;">
                        Logout
                    </button>
                </form>
            </div>
        </aside>
        <main class="admin-content">
            <?php
            $flashError = \App\Auth\Session::flash('error');
            $flashSuccess = \App\Auth\Session::flash('success');
            ?>
            <?php if ($flashError): ?>
                <div class="alert alert-error" style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:0.75rem 1rem;border-radius:6px;margin-bottom:1rem;">
                    <?= $this->e($flashError) ?>
                </div>
            <?php endif; ?>
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:0.75rem 1rem;border-radius:6px;margin-bottom:1rem;">
                    <?= $this->e($flashSuccess) ?>
                </div>
            <?php endif; ?>

            <?= $this->content() ?>
        </main>
    </div>
</body>
</html>
```

**Changes from current version**:
1. Added `sidebar-footer` with username display and logout form (POST with CSRF)
2. Added flash message rendering before `<?= $this->content() ?>`
3. Inline styles for alerts (admin CSS comes in Chunk 2.1)

---

### 10. Modify `public/index.php`

**Purpose**: Start session, bootstrap default admin user on first run, register auth routes, register auth middleware.

```php
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
```

**Changes from current version**:
1. Added `use` statements for auth classes
2. Added `Session::start()` after database bootstrap
3. Added first-run admin bootstrap (check user count, create default admin)
4. Added two global middleware registrations (CSRF + Auth)
5. Added auth routes: GET /admin/login, POST /admin/login, POST /admin/logout
6. Kept existing routes (homepage, dashboard) unchanged

---

## Detailed Class Specifications

### `App\Auth\Session`

```
PROPERTIES:
  - private static bool $started = false

METHODS:
  - public static start(): void
      If already started (self::$started or session_status === ACTIVE), return.
      Set cookie params: lifetime=0, path=/, httponly=true, samesite=Lax,
      secure=true when HTTPS detected.
      Call session_start(). Set $started = true.

  - public static get(string $key, mixed $default = null): mixed
      Returns $_SESSION[$key] ?? $default

  - public static set(string $key, mixed $value): void
      Sets $_SESSION[$key] = $value

  - public static has(string $key): bool
      Returns isset($_SESSION[$key])

  - public static remove(string $key): void
      unset($_SESSION[$key])

  - public static regenerate(): void
      Calls session_regenerate_id(true)
      The true parameter deletes the old session file.

  - public static destroy(): void
      Clears $_SESSION. Deletes session cookie. Calls session_destroy().
      Resets $started = false.

  - public static flash(string $key, ?string $message = null): ?string
      Two-arg (set): stores $message in $_SESSION['_flash'][$key], returns null.
      One-arg (get): returns $_SESSION['_flash'][$key] or null, then unsets it.
```

### `App\Auth\CsrfMiddleware`

```
STATIC METHODS:
  - public static handle(Request $request, callable $next): Response

      1. If no csrf_token in session: generate with bin2hex(random_bytes(32)), store in session.
      2. If request method is POST, PUT, or DELETE:
         a. Read _csrf_token from POST body via $request->input()
         b. If empty, read X-CSRF-TOKEN from request headers via $request->server('HTTP_X_CSRF_TOKEN')
         c. Compare with session token via hash_equals()
         d. If mismatch: return 403 Response
      3. Call $next($request) and return result.
```

### `App\Auth\AuthMiddleware`

```
CONSTANTS:
  - private const PUBLIC_ADMIN_PATHS = ['/admin/login']

STATIC METHODS:
  - public static handle(Request $request, callable $next): Response

      1. If URI does NOT start with /admin: pass through ($next).
      2. If URI is in PUBLIC_ADMIN_PATHS: pass through ($next).
      3. If Session::get('user_id') is falsy: redirect to /admin/login.
      4. Otherwise: pass through ($next).
```

### `App\Auth\RoleMiddleware`

```
STATIC METHODS:
  - public static check(string $requiredRole): ?Response
      Reads user_role from session.
      If role doesn't match: returns 403 Response.
      If authorized: returns null.

  - public static require(string $role): \Closure
      Returns a middleware closure:
        fn(Request, callable $next) => check($role) ?? $next($request)
```

### `App\Auth\AuthController`

```
PROPERTIES:
  - private App $app

CONSTRUCTOR:
  __construct(App $app)

PUBLIC METHODS:
  - showLogin(Request $request): Response
      If already logged in (Session::get('user_id')): redirect to /admin/dashboard.
      Otherwise: render 'auth/login' template with error/success flash messages.

  - handleLogin(Request $request): Response
      1. Read username and password from POST input.
      2. Get client IP from REMOTE_ADDR.
      3. Check rate limiting — if locked out, flash error and redirect.
      4. Validate non-empty input.
      5. Query users table for matching username.
      6. Verify password with password_verify().
      7. On failure: record failed attempt, flash error, redirect to login.
      8. On success: regenerate session, store user_id/user_role/user_name,
         clear failed attempts, redirect to /admin/dashboard.

  - logout(Request $request): Response
      Call Session::destroy(). Redirect to /admin/login.

PRIVATE METHODS:
  - getRateLimitPath(string $ip): string
      Returns storage/cache/rate_limit/{sha256_hash}.json
      Creates directory if it doesn't exist.

  - isRateLimited(string $ip): bool
      Reads the rate limit file for this IP.
      Returns true if locked_until > current time.
      Cleans up expired lockout files.

  - recordFailedAttempt(string $ip): void
      Increments attempt counter in rate limit file.
      Sets locked_until = time() + 900 when attempts >= 5.
      Uses LOCK_EX for file writing safety.

  - clearFailedAttempts(string $ip): void
      Deletes the rate limit file for this IP.
```

---

## Middleware Pipeline Execution Order

When a request hits the application:

```
Request arrives
    │
    ▼
CsrfMiddleware (outer)
    │─── POST/PUT/DELETE? → validate _csrf_token → 403 if invalid
    │─── GET? → pass through
    ▼
AuthMiddleware (inner)
    │─── Not /admin route? → pass through
    │─── /admin/login? → pass through
    │─── No session user_id? → redirect to /admin/login
    │─── Authenticated? → pass through
    ▼
Route Handler
    │
    ▼
Response sent
```

---

## Acceptance Test Procedures

### Test 1: First visit creates default admin; login with admin/admin works

```
1. Delete storage/database.sqlite (fresh database).
2. Visit http://localhost:8000/ to trigger bootstrap.
3. Verify: users table has exactly 1 row with username='admin', role='admin'.
4. Verify: password_hash column is NOT 'admin' (it's a bcrypt hash starting with $2y$).
5. Visit http://localhost:8000/admin/login — login form appears.
6. Enter username=admin, password=admin, submit.
7. Verify: redirected to /admin/dashboard. Dashboard renders.
```

### Test 2: After login, session persists across requests

```
1. Log in as admin.
2. Visit http://localhost:8000/admin/dashboard — page loads (not redirected to login).
3. Refresh the page — still on dashboard (session persists).
```

### Test 3: Logout destroys session; /admin/* redirects to login

```
1. Log in as admin.
2. Click the logout button (POST /admin/logout).
3. Verify: redirected to /admin/login.
4. Visit http://localhost:8000/admin/dashboard directly.
5. Verify: redirected to /admin/login (not dashboard).
```

### Test 4: Missing/invalid CSRF token returns 403

```
1. Using curl or a tool, POST to /admin/login without a _csrf_token field.
2. Verify: response is 403 with "Invalid or missing CSRF token" message.
3. POST with an incorrect _csrf_token value.
4. Verify: response is 403.
5. Load the login form (GET), extract the CSRF token, submit with correct token.
6. Verify: login attempt is processed (not blocked by CSRF).
```

### Test 5: Rate limiting blocks after 5 failed attempts

```
1. Attempt to log in with wrong password 5 times.
2. Verify: each attempt shows "Invalid username or password."
3. Attempt a 6th login (even with correct credentials).
4. Verify: response shows "Too many failed login attempts. Please try again in 15 minutes."
5. Verify: a file exists in storage/cache/rate_limit/ with locked_until set.
6. After manual expiry (delete the file or wait), login works again.
```

### Test 6: Password stored as bcrypt hash

```
1. After bootstrap, read the users table.
2. Verify: password_hash starts with '$2y$' (bcrypt identifier).
3. Verify: password_hash is NOT the literal string 'admin'.
4. Verify: password_verify('admin', $hash) returns true.
```

---

## Implementation Notes

### Security Measures Implemented

| Measure | Implementation |
|---------|---------------|
| Password hashing | `password_hash($pw, PASSWORD_BCRYPT)` / `password_verify()` |
| Session fixation | `session_regenerate_id(true)` on login |
| Secure cookies | httponly=true, samesite=Lax, secure=true (when HTTPS) |
| CSRF protection | Per-session token, validated on POST/PUT/DELETE, timing-safe comparison |
| Rate limiting | File-based per-IP tracking, 5 attempts → 15-minute lockout |
| XSS prevention | All template output uses `$this->e()` (`htmlspecialchars`) |
| SQL injection | All queries via `QueryBuilder` with parameterized statements |

### Session Storage

Session data stored in PHP's default session handler (filesystem in `session.save_path`). Session keys used by the auth system:
- `user_id` (int) — authenticated user's ID
- `user_role` (string) — 'admin' or 'editor'
- `user_name` (string) — username for display
- `csrf_token` (string) — 64-char hex token
- `_flash` (array) — flash messages, auto-cleared on read

### Deferred Items

These items are mentioned in the spec but intentionally deferred:
- **Forced password change on first login**: Will be implemented in Chunk 2.4 (User Management) which handles password changes. For now, the default admin can use admin/admin indefinitely.
- **Content Security Policy headers**: Will be added in Chunk 2.1 (Admin Layout & Dashboard) which establishes the full admin layout with proper HTTP headers.
- **Admin CSS**: Login page uses inline styles. Full admin stylesheet comes in Chunk 2.1.

### Edge Cases

1. **Double session start**: `Session::start()` is idempotent — checks `session_status()` before starting.
2. **Concurrent rate limit file writes**: Uses `LOCK_EX` flag on `file_put_contents()` to prevent race conditions.
3. **Already authenticated visiting login page**: Redirects to dashboard (no re-rendering of login form).
4. **Session expired during admin use**: Next request to any `/admin/*` route redirects to login via AuthMiddleware.
5. **CSRF token on AJAX requests**: Can be sent via `X-CSRF-TOKEN` header (for future fetch-based requests in Chunks 4.2, etc.).
6. **Logout with expired session**: If session expires before clicking logout, CsrfMiddleware will return 403 (token mismatch). User can navigate to /admin/login manually. This is acceptable for a lightweight CMS.

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `app/Auth/Session.php` | Class | **Create** |
| 2 | `app/Auth/CsrfMiddleware.php` | Class | **Create** |
| 3 | `app/Auth/AuthMiddleware.php` | Class | **Create** |
| 4 | `app/Auth/RoleMiddleware.php` | Class | **Create** |
| 5 | `app/Auth/AuthController.php` | Class | **Create** |
| 6 | `templates/auth/layout.php` | Template | **Create** |
| 7 | `templates/auth/login.php` | Template | **Create** |
| 8 | `app/Templates/TemplateEngine.php` | Class | **Modify** — add `csrfField()` method |
| 9 | `templates/admin/layout.php` | Template | **Modify** — add logout form + flash messages |
| 10 | `public/index.php` | Entry point | **Modify** — session, admin bootstrap, auth routes, middleware |

---

## Estimated Scope

- **New PHP classes**: 5 (Session, CsrfMiddleware, AuthMiddleware, RoleMiddleware, AuthController)
- **New templates**: 2 (auth/layout, auth/login)
- **Modified files**: 3 (TemplateEngine, admin/layout.php, index.php)
- **Approximate new PHP LOC**: ~300-350 lines
- **No new Composer dependencies**
- **No schema changes** (users table already exists from Chunk 1.2)
