# Chunk 1.1 — Project Scaffolding & Core Framework
## Detailed Implementation Plan

---

## Overview

This chunk builds the skeleton of LiteCMS: project structure, Composer autoloading, configuration system, and the micro-framework core (router, request/response, middleware pipeline, application container, template engine). At completion, HTTP requests route to controllers, templates render, and the middleware pipeline executes in order.

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `composer.json`

**Purpose**: PSR-4 autoloading, project metadata, minimal dependencies.

```json
{
  "name": "litecms/litecms",
  "description": "Lightweight PHP CMS — WordPress alternative for small businesses",
  "type": "project",
  "license": "MIT",
  "require": {
    "php": ">=8.1"
  },
  "autoload": {
    "psr-4": {
      "App\\": "app/"
    }
  }
}
```

**Notes**:
- No dependencies for Chunk 1.1 — only PHP 8.1+ requirement.
- The `App\` namespace maps to the `app/` directory.
- Run `composer install` to generate `vendor/autoload.php`.

---

### 2. `.env.example`

**Purpose**: Environment template showing all configurable values.

```env
# Database
DB_DRIVER=sqlite
DB_PATH=storage/database.sqlite
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=litecms
DB_USER=root
DB_PASS=

# Site
SITE_NAME=LiteCMS
SITE_URL=http://localhost
TIMEZONE=UTC
ITEMS_PER_PAGE=10

# AI (Phase 4)
CLAUDE_API_KEY=
CLAUDE_MODEL=claude-sonnet-4-20250514

# Security
APP_SECRET=change-this-to-a-random-string
```

**Notes**:
- This file is committed to repo as a template. Users copy it to `.env` (or just edit `config/app.php` directly).
- The config system reads `config/app.php` which can optionally pull from env vars.

---

### 3. `config/app.php`

**Purpose**: Central configuration array. Returns an associative array. Reads from environment variables with fallback defaults.

```php
<?php declare(strict_types=1);

return [
    'db_driver'      => getenv('DB_DRIVER') ?: 'sqlite',
    'db_path'        => getenv('DB_PATH') ?: __DIR__ . '/../storage/database.sqlite',
    'db_host'        => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port'        => (int)(getenv('DB_PORT') ?: 3306),
    'db_name'        => getenv('DB_NAME') ?: 'litecms',
    'db_user'        => getenv('DB_USER') ?: 'root',
    'db_pass'        => getenv('DB_PASS') ?: '',
    'site_name'      => getenv('SITE_NAME') ?: 'LiteCMS',
    'site_url'       => getenv('SITE_URL') ?: 'http://localhost',
    'timezone'       => getenv('TIMEZONE') ?: 'UTC',
    'items_per_page' => (int)(getenv('ITEMS_PER_PAGE') ?: 10),
    'claude_api_key' => getenv('CLAUDE_API_KEY') ?: '',
    'claude_model'   => getenv('CLAUDE_MODEL') ?: 'claude-sonnet-4-20250514',
    'app_secret'     => getenv('APP_SECRET') ?: 'change-this-to-a-random-string',
];
```

---

### 4. `app/Core/Config.php`

**Purpose**: Static config reader. Loads `config/app.php` once, provides typed getters.

**Class**: `App\Core\Config`

**Design**:
- Loads the config array from `config/app.php` on first access (lazy singleton pattern via static property).
- Provides static methods for typed access.

**Public API**:
```php
Config::get(string $key, mixed $default = null): mixed
Config::getString(string $key, string $default = ''): string
Config::getInt(string $key, int $default = 0): int
Config::getBool(string $key, bool $default = false): bool
Config::all(): array
```

**Implementation details**:
- `private static ?array $config = null;`
- `private static function load(): void` — requires `config/app.php`, stores in `$config`.
- `get()` calls `load()` if `$config` is null, returns `$config[$key] ?? $default`.
- Typed getters cast the result: `getString` casts to `(string)`, `getInt` to `(int)`, `getBool` to `(bool)`.

---

### 5. `app/Core/Request.php`

**Purpose**: Wraps the current HTTP request. Provides sanitized access to input, method detection, URI parsing.

**Class**: `App\Core\Request`

**Design**:
- Instantiated once per request in `index.php`, passed into the app.
- Wraps `$_GET`, `$_POST`, `$_SERVER`, `$_COOKIE`.
- Supports method override via `_method` POST field (for PUT/DELETE from HTML forms).

**Public API**:
```php
__construct()                                          // Captures superglobals
method(): string                                       // GET, POST, PUT, DELETE (with _method override)
uri(): string                                          // Request URI path (no query string), e.g. "/admin/login"
input(string $key, mixed $default = null): mixed       // From POST body (sanitized)
query(string $key, mixed $default = null): mixed       // From GET params (sanitized)
all(): array                                           // Merged GET+POST (sanitized)
cookie(string $key, mixed $default = null): mixed      // From cookies
server(string $key, mixed $default = null): mixed      // From $_SERVER
isMethod(string $method): bool                         // Shorthand check
isAjax(): bool                                         // Checks X-Requested-With header
```

**Implementation details**:
- `uri()`: Parse `$_SERVER['REQUEST_URI']`, strip query string with `strtok($uri, '?')`, trim trailing slashes (except root `/`), handle subdirectory installs by stripping script directory prefix.
- `method()`: Check `$_SERVER['REQUEST_METHOD']`. If POST and `$_POST['_method']` is set and is PUT or DELETE, return that instead.
- Input sanitization: `trim()` string values. Do NOT `htmlspecialchars()` at input time — escaping happens at output in templates. But do `trim()` all string inputs.
- Store sanitized copies of `$_GET` and `$_POST` in private properties on construction.

---

### 6. `app/Core/Response.php`

**Purpose**: Build and send HTTP responses (HTML, JSON, redirects).

**Class**: `App\Core\Response`

**Design**:
- Fluent builder pattern. Accumulates headers, status code, body — then `send()` flushes everything.

**Public API**:
```php
__construct(string $body = '', int $status = 200, array $headers = [])
static html(string $body, int $status = 200): self     // Shorthand for HTML response
static json(mixed $data, int $status = 200): self       // JSON-encodes data
static redirect(string $url, int $status = 302): self   // Redirect response
withHeader(string $name, string $value): self            // Add header (fluent)
withStatus(int $code): self                              // Set status (fluent)
send(): void                                             // Sends headers + body, exits
getBody(): string                                        // Getter for body
getStatus(): int                                         // Getter for status code
getHeaders(): array                                      // Getter for headers
```

**Implementation details**:
- `send()`: calls `http_response_code($this->status)`, loops `$this->headers` calling `header()`, echoes `$this->body`.
- `json()`: Sets `Content-Type: application/json`, uses `json_encode($data, JSON_THROW_ON_ERROR)`.
- `redirect()`: Sets `Location` header, empty body.
- Store body, status, headers as private properties.

---

### 7. `app/Core/Router.php`

**Purpose**: Regex-based URL router. Maps HTTP method + URL pattern to handler callables.

**Class**: `App\Core\Router`

**Design**:
- Routes stored as array of `['method' => ..., 'pattern' => ..., 'handler' => ...]`.
- Patterns support named parameters: `/admin/content/{id}` becomes regex `/admin/content/(?P<id>[^/]+)`.
- `dispatch()` iterates routes, returns matched handler + extracted params.

**Public API**:
```php
get(string $pattern, callable|array $handler): void
post(string $pattern, callable|array $handler): void
put(string $pattern, callable|array $handler): void
delete(string $pattern, callable|array $handler): void
group(string $prefix, callable $callback): void         // Route grouping with shared prefix
dispatch(string $method, string $uri): ?array            // Returns ['handler' => ..., 'params' => [...]] or null
```

**Implementation details**:
- `private array $routes = [];`
- `private string $groupPrefix = '';`
- `addRoute(string $method, string $pattern, callable|array $handler): void` — internal method used by get/post/put/delete. Prepends `$groupPrefix`. Stores route.
- `group()`: Saves current prefix, sets new prefix = old + $prefix, calls $callback($this), restores old prefix.
- `dispatch()`:
  1. Iterate `$routes` looking for method match.
  2. Convert pattern to regex: replace `{paramName}` with `(?P<paramName>[^/]+)`, anchor with `^` and `$`.
  3. Run `preg_match()`. If match, return handler + named captures.
  4. If no route matches, return null.
- Handler format: either a `callable` (closure) or `[ControllerClass::class, 'methodName']` array. The App resolves the latter at dispatch time.

---

### 8. `app/Core/Middleware.php`

**Purpose**: Define the middleware interface and pipeline runner.

**Design**: A middleware is any callable with signature:
```php
function(Request $request, callable $next): Response
```

The pipeline runner takes a stack of middleware callables and a final handler, builds a nested call chain, and executes it.

**Class**: `App\Core\Middleware`

**Public API**:
```php
static run(Request $request, array $middlewares, callable $handler): Response
```

**Implementation details**:
- `run()` builds the pipeline by reducing the middleware array from right to left:
  ```
  $next = $handler;
  foreach (array_reverse($middlewares) as $mw) {
      $prev = $next;
      $next = function(Request $request) use ($mw, $prev): Response {
          return $mw($request, $prev);
      };
  }
  return $next($request);
  ```
- The final `$handler` callable receives the `Request` and must return a `Response`.
- Each middleware can:
  - Short-circuit by returning a Response directly (e.g., auth redirect).
  - Pass through by calling `$next($request)`.
  - Modify the response after calling `$next`.

---

### 9. `app/Templates/TemplateEngine.php`

**Purpose**: Renders PHP template files with data, supports layouts.

**Class**: `App\Templates\TemplateEngine`

**Design**:
- `render($template, $data)` renders a PHP template file with `$data` extracted as local variables.
- Layout support: templates can call `$this->layout('admin/layout')` to wrap their output in a layout. The layout uses `$this->content()` to output the child content.
- Partials support: `$this->partial('partials/nav', $data)` renders a sub-template inline.
- Escape helper: `$this->e($string)` — shorthand for `htmlspecialchars($string, ENT_QUOTES, 'UTF-8')`.

**Public API**:
```php
__construct(string $basePath)                           // Base path for template files (e.g., __DIR__.'/../../templates')
render(string $template, array $data = []): string      // Render template to string
```

**Template-internal API** (available inside template files via `$this`):
```php
layout(string $layoutTemplate): void                    // Declare parent layout
content(): string                                       // Output child content (used in layouts)
partial(string $template, array $data = []): string     // Render a partial inline
e(string $value): string                                // HTML-escape helper
```

**Implementation details**:
- `render()`:
  1. Resolve template path: `$this->basePath . '/' . $template . '.php'`.
  2. Extract `$data` to local scope.
  3. Use output buffering (`ob_start()` / `ob_get_clean()`) to capture template output.
  4. If `$this->layoutTemplate` was set during rendering (template called `$this->layout(...)`), render the layout template with `$this->childContent` set, and the layout calls `$this->content()` to insert the child output.
  5. Return final HTML string.
- Private properties: `$basePath`, `$layoutTemplate = null`, `$childContent = ''`.
- The template file is included via `include` inside a method, so `$this` refers to the TemplateEngine instance.

---

### 10. `app/Core/App.php`

**Purpose**: Application container. Bootstraps the app, registers routes and middleware, dispatches requests.

**Class**: `App\Core\App`

**Design**:
- Central orchestrator. Created in `index.php`.
- Holds the Router, TemplateEngine, and service instances.
- Registers routes (or delegates to route files).
- Runs the middleware pipeline and dispatches to matched handler.

**Public API**:
```php
__construct()                                           // Creates Router, TemplateEngine, loads config
router(): Router                                        // Getter for router instance
template(): TemplateEngine                              // Getter for template engine
register(string $key, mixed $value): void               // Simple service container (store by key)
resolve(string $key): mixed                             // Retrieve from container
run(Request $request): void                             // Main dispatch loop
addMiddleware(callable $middleware): void                // Add global middleware
```

**Implementation details**:
- Constructor:
  1. Set timezone from config: `date_default_timezone_set(Config::getString('timezone', 'UTC'))`.
  2. Create `Router` instance.
  3. Create `TemplateEngine` instance with base path = project `templates/` dir.
  4. Initialize empty `$middlewares` array and `$services` array.
- `run(Request $request)`:
  1. Call `$this->router->dispatch($request->method(), $request->uri())`.
  2. If no match: return 404 response (render a simple 404 page or plain text).
  3. If match: build the handler callable.
     - If handler is `[ControllerClass, 'method']`, instantiate the controller (pass `$this` App instance to constructor), call the method with `$request` and route `$params`.
     - If handler is a closure, call it with `$request` and route `$params`.
  4. Wrap in middleware pipeline: `Middleware::run($request, $this->middlewares, $finalHandler)`.
  5. Call `$response->send()` on the returned Response.
- `$services` is a simple `array<string, mixed>` — no auto-wiring, just manual registration. Controllers receive the App instance and pull what they need.

---

### 11. `public/.htaccess`

**Purpose**: Apache URL rewriting — all requests to `index.php`.

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

**Notes**:
- Requests for existing files (CSS, JS, images) are served directly.
- Everything else goes through `index.php`.

---

### 12. `.htaccess` (root)

**Purpose**: Deny direct access to non-public files (app/, config/, storage/, etc.).

```apache
# Deny access to everything in the project root
# The web server should be pointed at the public/ directory
# This is a safety net in case the server is misconfigured

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^ public/index.php [L]
</IfModule>

# Block access to sensitive files
<FilesMatch "\.(php|sql|md|json|lock|env)$">
    Require all denied
</FilesMatch>
```

---

### 13. `public/index.php`

**Purpose**: Single entry point. Bootstraps the application.

```php
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
```

**Notes**:
- In later chunks, route registration will be extracted to a separate routes file or organized by module. For now, inline routes in `index.php` are fine to prove the framework works.
- The demo routes will be replaced in subsequent chunks.

---

### 14. `templates/public/home.php`

**Purpose**: Minimal public homepage template to test routing + template engine.

```php
<?php $this->layout('public/layout'); ?>

<h1>Welcome to <?= $this->e($title) ?></h1>
<p>LiteCMS is running.</p>
```

---

### 15. `templates/public/layout.php`

**Purpose**: Base public HTML5 layout.

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e($title ?? 'LiteCMS') ?></title>
</head>
<body>
    <header>
        <nav>
            <a href="/"><?= $this->e($title ?? 'LiteCMS') ?></a>
        </nav>
    </header>
    <main>
        <?= $this->content() ?>
    </main>
    <footer>
        <p>&copy; <?= date('Y') ?> <?= $this->e($title ?? 'LiteCMS') ?></p>
    </footer>
</body>
</html>
```

---

### 16. `templates/admin/layout.php`

**Purpose**: Minimal admin layout placeholder (will be expanded in Chunk 2.1).

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
        </aside>
        <main class="admin-content">
            <?= $this->content() ?>
        </main>
    </div>
</body>
</html>
```

---

### 17. `templates/admin/dashboard.php`

**Purpose**: Minimal dashboard placeholder for testing admin routes.

```php
<?php $this->layout('admin/layout'); ?>

<h1>Dashboard</h1>
<p>Welcome to the LiteCMS admin panel.</p>
```

---

### 18. Directory Structure (empty dirs)

Create these directories (with `.gitkeep` files to ensure they're tracked):

```
storage/
storage/logs/
storage/cache/
public/assets/css/
public/assets/js/
public/assets/uploads/
templates/auth/
migrations/
```

---

## Detailed Class Specifications

### `App\Core\Config`

```
PROPERTIES:
  - private static ?array $config = null

METHODS:
  - private static load(): void
      Requires config/app.php, stores returned array in self::$config.
      Path: __DIR__ . '/../../config/app.php'

  - public static get(string $key, mixed $default = null): mixed
      Calls load() if needed. Returns $config[$key] ?? $default.

  - public static getString(string $key, string $default = ''): string
      Returns (string) self::get($key, $default).

  - public static getInt(string $key, int $default = 0): int
      Returns (int) self::get($key, $default).

  - public static getBool(string $key, bool $default = false): bool
      Returns (bool) self::get($key, $default).

  - public static all(): array
      Calls load() if needed. Returns full $config array.
```

### `App\Core\Request`

```
PROPERTIES:
  - private string $method
  - private string $uri
  - private array $get
  - private array $post
  - private array $server
  - private array $cookies

CONSTRUCTOR:
  1. Capture $_GET, $_POST, $_SERVER, $_COOKIE into private properties.
  2. Trim all string values in $get and $post recursively.
  3. Determine $method: $_SERVER['REQUEST_METHOD'], overridden by $_POST['_method']
     if present and in ['PUT', 'DELETE'].
  4. Parse $uri: strtok($_SERVER['REQUEST_URI'], '?'), then rtrim of '/'
     (but keep '/' if that's the entire path).
     Handle potential subdirectory: detect SCRIPT_NAME base path and strip it.

METHODS:
  - method(): string — return strtoupper($this->method)
  - uri(): string — return $this->uri
  - input(string $key, mixed $default = null): mixed — $this->post[$key] ?? $default
  - query(string $key, mixed $default = null): mixed — $this->get[$key] ?? $default
  - all(): array — array_merge($this->get, $this->post)
  - cookie(string $key, mixed $default = null): mixed — $this->cookies[$key] ?? $default
  - server(string $key, mixed $default = null): mixed — $this->server[$key] ?? $default
  - isMethod(string $method): bool — $this->method() === strtoupper($method)
  - isAjax(): bool — ($this->server('HTTP_X_REQUESTED_WITH') ?? '') === 'XMLHttpRequest'
```

### `App\Core\Response`

```
PROPERTIES:
  - private string $body
  - private int $status
  - private array $headers   // ['Header-Name' => 'value', ...]

CONSTRUCTOR:
  __construct(string $body = '', int $status = 200, array $headers = [])

STATIC FACTORIES:
  - html(string $body, int $status = 200): self
      Returns new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8'])

  - json(mixed $data, int $status = 200): self
      Returns new self(
          json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
          $status,
          ['Content-Type' => 'application/json']
      )

  - redirect(string $url, int $status = 302): self
      Returns new self('', $status, ['Location' => $url])

FLUENT METHODS:
  - withHeader(string $name, string $value): self — $this->headers[$name] = $value; return $this
  - withStatus(int $code): self — $this->status = $code; return $this

SEND:
  - send(): void
      1. http_response_code($this->status)
      2. foreach $headers: header("$name: $value")
      3. echo $this->body

GETTERS:
  - getBody(): string
  - getStatus(): int
  - getHeaders(): array
```

### `App\Core\Router`

```
PROPERTIES:
  - private array $routes = []          // Each: ['method'=>string, 'pattern'=>string, 'handler'=>callable|array]
  - private string $groupPrefix = ''

METHODS:
  - get(string $pattern, callable|array $handler): void    — addRoute('GET', ...)
  - post(string $pattern, callable|array $handler): void   — addRoute('POST', ...)
  - put(string $pattern, callable|array $handler): void    — addRoute('PUT', ...)
  - delete(string $pattern, callable|array $handler): void — addRoute('DELETE', ...)

  - private addRoute(string $method, string $pattern, callable|array $handler): void
      $fullPattern = $this->groupPrefix . $pattern
      Normalize: ensure leading /, rtrim trailing / (except if pattern is just '/')
      Store in $this->routes[]

  - group(string $prefix, callable $callback): void
      $previousPrefix = $this->groupPrefix
      $this->groupPrefix .= $prefix
      $callback($this)
      $this->groupPrefix = $previousPrefix

  - dispatch(string $method, string $uri): ?array
      foreach $this->routes as $route:
          if $route['method'] !== strtoupper($method): continue
          $regex = $this->patternToRegex($route['pattern'])
          if preg_match($regex, $uri, $matches):
              $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY)
              return ['handler' => $route['handler'], 'params' => $params]
      return null

  - private patternToRegex(string $pattern): string
      $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern)
      return '#^' . $regex . '$#'
```

### `App\Core\Middleware`

```
STATIC METHODS:
  - run(Request $request, array $middlewares, callable $handler): Response
      Build nested chain:
      $next = fn(Request $req): Response => $handler($req);
      foreach (array_reverse($middlewares) as $mw):
          $prev = $next
          $next = fn(Request $req) use ($mw, $prev): Response => $mw($req, $prev)
      return $next($request)
```

### `App\Templates\TemplateEngine`

```
PROPERTIES:
  - private string $basePath
  - private ?string $layoutTemplate = null
  - private string $childContent = ''

CONSTRUCTOR:
  __construct(string $basePath)

PUBLIC METHODS:
  - render(string $template, array $data = []): string
      1. $this->layoutTemplate = null (reset)
      2. $file = $this->basePath . '/' . $template . '.php'
      3. If file doesn't exist: throw RuntimeException
      4. extract($data)
      5. ob_start()
      6. include $file       // Template can call $this->layout(), $this->partial(), $this->e()
      7. $output = ob_get_clean()
      8. If $this->layoutTemplate is not null:
          $this->childContent = $output
          $output = $this->render($this->layoutTemplate, $data)
          $this->childContent = ''   // reset
      9. Return $output

TEMPLATE-INTERNAL METHODS:
  - layout(string $template): void
      $this->layoutTemplate = $template

  - content(): string
      return $this->childContent

  - partial(string $template, array $data = []): string
      // Render without layout support (standalone render)
      $file = $this->basePath . '/' . $template . '.php'
      extract($data)
      ob_start()
      include $file
      return ob_get_clean()

  - e(string $value): string
      return htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
```

### `App\Core\App`

```
PROPERTIES:
  - private Router $router
  - private TemplateEngine $template
  - private array $middlewares = []
  - private array $services = []

CONSTRUCTOR:
  1. date_default_timezone_set(Config::getString('timezone', 'UTC'))
  2. $this->router = new Router()
  3. $this->template = new TemplateEngine(dirname(__DIR__, 2) . '/templates')

METHODS:
  - router(): Router
  - template(): TemplateEngine

  - register(string $key, mixed $value): void
      $this->services[$key] = $value

  - resolve(string $key): mixed
      return $this->services[$key] ?? throw RuntimeException("Service not found: $key")

  - addMiddleware(callable $middleware): void
      $this->middlewares[] = $middleware

  - run(Request $request): void
      1. $match = $this->router->dispatch($request->method(), $request->uri())
      2. If $match is null:
          $response = Response::html('<h1>404 Not Found</h1>', 404)
          $response->send()
          return
      3. $handler = $match['handler']
         $params = $match['params']
      4. Build $finalHandler closure:
          If $handler is an array [class, method]:
              $controller = new $handler[0]($this)
              return $controller->{$handler[1]}($request, ...$params)
          If $handler is callable:
              return $handler($request, ...$params)
      5. $response = Middleware::run($request, $this->middlewares, $finalHandler)
      6. $response->send()
```

---

## Acceptance Test Procedures

### Test 1: Composer autoloading works
```
1. Run `composer install` in project root.
2. Verify `vendor/` directory is created with `autoload.php`.
3. Verify no errors.
```

### Test 2: Homepage returns 200 with rendered HTML
```
1. Start PHP dev server: `php -S localhost:8000 -t public`
2. Visit http://localhost:8000/
3. Verify: HTTP 200, page shows "Welcome to LiteCMS", wrapped in HTML layout.
```

### Test 3: Undefined route returns 404
```
1. Visit http://localhost:8000/nonexistent
2. Verify: HTTP 404, page shows "404 Not Found".
```

### Test 4: POST/PUT/DELETE routes work, method detection correct
```
1. Add test routes in index.php:
   - POST /test-post → returns JSON {"method": "POST"}
   - PUT /test-put → returns JSON {"method": "PUT"}
   - DELETE /test-delete → returns JSON {"method": "DELETE"}
2. Use curl:
   curl -X POST http://localhost:8000/test-post
   curl -X POST -d "_method=PUT" http://localhost:8000/test-put
   curl -X POST -d "_method=DELETE" http://localhost:8000/test-delete
3. Verify correct method in each JSON response.
```

### Test 5: Middleware pipeline executes in order
```
1. Add two test middlewares that append to a header:
   - Middleware A: adds X-Middleware: A
   - Middleware B: adds X-Middleware: B
2. Verify response headers show both middlewares executed.
   (Or: middlewares append to response body in order.)
```

### Test 6: Config values are readable with type safety
```
1. Add a test route that returns JSON of config values:
   {
     "site_name": Config::getString('site_name'),
     "items_per_page": Config::getInt('items_per_page'),
     "db_driver": Config::getString('db_driver')
   }
2. Visit the route, verify values match config/app.php defaults.
3. Verify getInt returns an integer (not string).
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);`
- PSR-4 namespacing: `App\Core\Config` → `app/Core/Config.php`
- No `use` of any framework classes — only native PHP
- All classes are final unless explicitly designed for extension
- Private properties by default, public only where needed

### Error Handling (Chunk 1.1 scope)
- Template not found → `RuntimeException`
- Config file not found → `RuntimeException`
- No route match → 404 Response (not an exception)
- PHP errors → let PHP's default error handler deal with them for now (comprehensive error handling is in Chunk 5.3)

### Subdirectory Install Support
The Request class must handle the CMS being installed in a subdirectory (e.g., `http://example.com/cms/`). It should strip the base path from `REQUEST_URI` by comparing against `SCRIPT_NAME`'s directory component.

### No Session/Cookie/DB in This Chunk
Sessions, cookies, database, and authentication are NOT part of Chunk 1.1. The middleware pipeline is built and tested with simple pass-through middlewares. Auth middleware comes in Chunk 1.3.

---

## File Checklist

| # | File | Type |
|---|------|------|
| 1 | `composer.json` | Config |
| 2 | `.env.example` | Config |
| 3 | `config/app.php` | Config |
| 4 | `app/Core/Config.php` | Class |
| 5 | `app/Core/Request.php` | Class |
| 6 | `app/Core/Response.php` | Class |
| 7 | `app/Core/Router.php` | Class |
| 8 | `app/Core/Middleware.php` | Class |
| 9 | `app/Templates/TemplateEngine.php` | Class |
| 10 | `app/Core/App.php` | Class |
| 11 | `public/.htaccess` | Config |
| 12 | `.htaccess` (root) | Config |
| 13 | `public/index.php` | Entry point |
| 14 | `templates/public/home.php` | Template |
| 15 | `templates/public/layout.php` | Template |
| 16 | `templates/admin/layout.php` | Template |
| 17 | `templates/admin/dashboard.php` | Template |
| 18 | `storage/logs/.gitkeep` | Placeholder |
| 19 | `storage/cache/.gitkeep` | Placeholder |
| 20 | `public/assets/css/.gitkeep` | Placeholder |
| 21 | `public/assets/js/.gitkeep` | Placeholder |
| 22 | `public/assets/uploads/.gitkeep` | Placeholder |
| 23 | `templates/auth/.gitkeep` | Placeholder |
| 24 | `migrations/.gitkeep` | Placeholder |
| 25 | `.gitignore` | Config |

---

## `.gitignore` (to be created)

```
/vendor/
/storage/database.sqlite
/storage/logs/*.log
/storage/cache/*
!/storage/cache/.gitkeep
!/storage/logs/.gitkeep
/public/assets/uploads/*
!/public/assets/uploads/.gitkeep
.env
```

---

## Estimated Scope

- **PHP classes**: 6 (Config, Request, Response, Router, Middleware, App) + 1 (TemplateEngine)
- **Templates**: 4 (public/home, public/layout, admin/layout, admin/dashboard)
- **Config files**: 4 (composer.json, .env.example, config/app.php, .gitignore)
- **Server config**: 2 (.htaccess files)
- **Entry point**: 1 (public/index.php)
- **Approximate PHP LOC**: ~350-450 lines
