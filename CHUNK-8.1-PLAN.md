# Chunk 8.1 — Final Polish, Error Handling & Documentation
## Detailed Implementation Plan

---

## Overview

This chunk is the final pass over the LiteCMS codebase. It delivers:

1. **Contact Submissions Admin UI** — list, view, and delete contact form submissions from the admin panel
2. **Contact Form Email Notification** — send email when `contact_notification_email` setting is configured
3. **Global Error Handling & Logging** — catch all uncaught exceptions, render styled error pages, log errors to file
4. **Error Page Template** — styled generic error page for 500 errors
5. **README.md** — complete installation guide, requirements, usage basics

No new tables are required. The `contact_submissions` table already exists (migration 002). The `settings` table already handles configuration storage.

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `app/Core/Logger.php` (NEW)

**Purpose**: Simple file-based logger for error/event logging. Writes to `storage/logs/`.

**Class**: `App\Core\Logger`

**Design**:
- Static class (like Config) — call `Logger::error()` from anywhere.
- Writes timestamped log lines to `storage/logs/litecms.log`.
- Auto-rotates when file exceeds 5 MB (renames to `.log.1`, keeps max 3 rotations).

**Public API**:
```php
static error(string $message, array $context = []): void
static warning(string $message, array $context = []): void
static info(string $message, array $context = []): void
```

**Implementation**:
```php
<?php declare(strict_types=1);

namespace App\Core;

class Logger
{
    private static string $logDir = '';

    private static function logDir(): string
    {
        if (self::$logDir === '') {
            self::$logDir = dirname(__DIR__, 2) . '/storage/logs';
        }
        return self::$logDir;
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $logDir = self::logDir();
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $file = $logDir . '/litecms.log';

        // Rotate if > 5 MB
        if (file_exists($file) && filesize($file) > 5 * 1024 * 1024) {
            self::rotate($file);
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = $context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function rotate(string $file): void
    {
        // Keep max 3 rotations
        for ($i = 3; $i >= 1; $i--) {
            $old = $file . '.' . $i;
            if ($i === 3 && file_exists($old)) {
                @unlink($old);
            }
            if ($i > 1) {
                $prev = $file . '.' . ($i - 1);
                if (file_exists($prev)) {
                    @rename($prev, $old);
                }
            }
        }
        @rename($file, $file . '.1');
    }
}
```

---

### 2. `templates/public/error.php` (NEW)

**Purpose**: Generic styled error page for 500 and other server errors on the public site.

**Implementation**:
```php
<?php declare(strict_types=1); ?>
<?php $this->layout('public/layout'); ?>

<div class="error-page">
    <h1><?= $this->e($errorCode ?? '500') ?> — <?= $this->e($errorTitle ?? 'Server Error') ?></h1>
    <p><?= $this->e($errorMessage ?? 'Something went wrong. Please try again later.') ?></p>
    <p><a href="/">Return to homepage</a></p>
</div>
```

---

### 3. Update `app/Core/App.php` — Global Exception Handler

**Purpose**: Wrap the entire `run()` method in a try/catch. Uncaught exceptions are caught, logged, and a friendly error page is rendered.

**Changes to `run()` method**:

```php
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

        $finalHandler = function (Request $req) use ($handler, $params): Response {
            if (is_array($handler)) {
                $controller = new $handler[0]($this);
                return $controller->{$handler[1]}($req, ...$params);
            }
            return $handler($req, ...$params);
        };

        $response = Middleware::run($request, $this->middlewares, $finalHandler);
        $response->send();
    } catch (\Throwable $e) {
        \App\Core\Logger::error('Uncaught exception: ' . $e->getMessage(), [
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

    // Determine if we should show debug info
    $debug = Config::getBool('debug', false);

    try {
        if ($isAdmin) {
            // For admin pages, show a simple error with details in debug mode
            $body = '<h1>Error</h1>';
            $body .= '<p>An unexpected error occurred. The error has been logged.</p>';
            if ($debug) {
                $body .= '<pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
            }
            $response = Response::html($body, 500);
        } else {
            // For public pages, render the error template
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
        // If even the error page fails, fall back to plain text
        \App\Core\Logger::error('Error page render failed: ' . $renderError->getMessage());
        $response = Response::html(
            '<h1>500 Internal Server Error</h1><p>An unexpected error occurred.</p>',
            500
        );
    }

    $response->send();
}
```

**Add `use App\Core\Logger;`** to the imports at the top of App.php (or use FQCN as shown above).

---

### 4. `app/Admin/ContactSubmissionsController.php` (NEW)

**Purpose**: Admin interface for listing, viewing, and deleting contact form submissions. Admin-only access.

**Class**: `App\Admin\ContactSubmissionsController`

**Design**:
- Follows the same controller pattern as `DashboardController` and `UserController`.
- Constructor receives `App` instance.
- Three actions: `index()` (list with pagination), `view()` (single submission detail), `delete()` (remove submission).

**Public API**:
```php
__construct(App $app)
index(Request $request): Response       // GET /admin/contact-submissions
view(Request $request, string $id): Response    // GET /admin/contact-submissions/{id}
delete(Request $request, string $id): Response  // DELETE /admin/contact-submissions/{id}
```

**Implementation**:
```php
<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;

class ContactSubmissionsController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/contact-submissions — List submissions with pagination.
     */
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = Config::getInt('items_per_page', 10);
        $offset = ($page - 1) * $perPage;

        $total = QueryBuilder::query('contact_submissions')->select()->count();
        $totalPages = (int) ceil($total / $perPage);

        $submissions = QueryBuilder::query('contact_submissions')
            ->select()
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $html = $this->app->template()->render('admin/contact-submissions/index', [
            'title'       => 'Messages',
            'activeNav'   => 'messages',
            'submissions' => $submissions,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'total'       => $total,
        ]);

        return Response::html($html)
            ->withHeader('X-Frame-Options', 'DENY');
    }

    /**
     * GET /admin/contact-submissions/{id} — View a single submission.
     */
    public function view(Request $request, string $id): Response
    {
        $submission = QueryBuilder::query('contact_submissions')
            ->select()
            ->where('id', $id)
            ->first();

        if ($submission === null) {
            $_SESSION['flash_error'] = 'Submission not found.';
            return Response::redirect('/admin/contact-submissions');
        }

        $html = $this->app->template()->render('admin/contact-submissions/view', [
            'title'      => 'View Message',
            'activeNav'  => 'messages',
            'submission' => $submission,
        ]);

        return Response::html($html)
            ->withHeader('X-Frame-Options', 'DENY');
    }

    /**
     * DELETE /admin/contact-submissions/{id} — Delete a submission.
     */
    public function delete(Request $request, string $id): Response
    {
        $submission = QueryBuilder::query('contact_submissions')
            ->select()
            ->where('id', $id)
            ->first();

        if ($submission === null) {
            $_SESSION['flash_error'] = 'Submission not found.';
            return Response::redirect('/admin/contact-submissions');
        }

        QueryBuilder::query('contact_submissions')
            ->where('id', $id)
            ->delete();

        $_SESSION['flash_success'] = 'Message deleted.';
        return Response::redirect('/admin/contact-submissions');
    }
}
```

**Notes**:
- Uses `$_SESSION['flash_success']` / `$_SESSION['flash_error']` for flash messages (same pattern as other controllers).
- Session flash is read by `templates/admin/layout.php` (via `Session::flash()`).

---

### 5. `templates/admin/contact-submissions/index.php` (NEW)

**Purpose**: Submissions list table with pagination. Shows name, email, subject, date, and truncated message preview.

**Implementation**:
```php
<?php declare(strict_types=1); ?>
<?php $this->layout('admin/layout'); ?>

<div class="content-header">
    <h1>Messages</h1>
</div>

<?php if (empty($submissions)): ?>
    <div class="empty-state">
        <p>No contact form submissions yet.</p>
    </div>
<?php else: ?>

<div class="table-wrapper">
    <table class="data-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Subject</th>
                <th>Message</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($submissions as $s): ?>
            <tr>
                <td><?= $this->e($s['name']) ?></td>
                <td><a href="mailto:<?= $this->e($s['email']) ?>"><?= $this->e($s['email']) ?></a></td>
                <td><?= $this->e($s['subject'] ?: '—') ?></td>
                <td class="message-preview"><?= $this->e(mb_strimwidth($s['message'], 0, 80, '...')) ?></td>
                <td><?= $this->e(date('M j, Y g:ia', strtotime($s['created_at']))) ?></td>
                <td>
                    <a href="/admin/contact-submissions/<?= $this->e((string)$s['id']) ?>" class="btn btn-sm">View</a>
                    <form method="POST" action="/admin/contact-submissions/<?= $this->e((string)$s['id']) ?>"
                          style="display:inline;" onsubmit="return confirm('Delete this message?');">
                        <input type="hidden" name="_method" value="DELETE">
                        <?= $this->csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <?php if ($i === $page): ?>
            <span class="pagination-current"><?= $i ?></span>
        <?php else: ?>
            <a href="/admin/contact-submissions?page=<?= $i ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>
```

---

### 6. `templates/admin/contact-submissions/view.php` (NEW)

**Purpose**: Full detail view for a single contact submission.

**Implementation**:
```php
<?php declare(strict_types=1); ?>
<?php $this->layout('admin/layout'); ?>

<div class="content-header">
    <h1>View Message</h1>
    <a href="/admin/contact-submissions" class="btn">Back to Messages</a>
</div>

<div class="card">
    <table class="detail-table">
        <tr>
            <th>Name</th>
            <td><?= $this->e($submission['name']) ?></td>
        </tr>
        <tr>
            <th>Email</th>
            <td><a href="mailto:<?= $this->e($submission['email']) ?>"><?= $this->e($submission['email']) ?></a></td>
        </tr>
        <tr>
            <th>Subject</th>
            <td><?= $this->e($submission['subject'] ?: '—') ?></td>
        </tr>
        <tr>
            <th>IP Address</th>
            <td><?= $this->e($submission['ip_address'] ?? '—') ?></td>
        </tr>
        <tr>
            <th>Date</th>
            <td><?= $this->e(date('F j, Y g:i:s A', strtotime($submission['created_at']))) ?></td>
        </tr>
        <tr>
            <th>Message</th>
            <td class="message-body"><?= nl2br($this->e($submission['message'])) ?></td>
        </tr>
    </table>
</div>

<form method="POST" action="/admin/contact-submissions/<?= $this->e((string)$submission['id']) ?>"
      style="margin-top: 1rem;" onsubmit="return confirm('Delete this message?');">
    <input type="hidden" name="_method" value="DELETE">
    <?= $this->csrfField() ?>
    <button type="submit" class="btn btn-danger">Delete Message</button>
</form>
```

---

### 7. Update `templates/admin/layout.php` — Add "Messages" Link

**Purpose**: Add the "Messages" nav item to the admin sidebar under the "System" section.

**Change**: In the sidebar nav, add a new link between "Users" and "Settings" in the System section:

```php
<a href="/admin/contact-submissions"
   class="<?= ($activeNav ?? '') === 'messages' ? 'active' : '' ?>">
    <span class="nav-icon">&#9993;</span> Messages
</a>
```

Insert this right after the Users link and before the Settings link.

---

### 8. Update `public/index.php` — Register Contact Submission Routes

**Purpose**: Add routes for the contact submissions admin interface.

**Add import** at top:
```php
use App\Admin\ContactSubmissionsController;
```

**Add routes** inside the `/admin` group, after the Settings routes:

```php
// Contact submissions management
$router->get('/contact-submissions', [ContactSubmissionsController::class, 'index']);
$router->get('/contact-submissions/{id}', [ContactSubmissionsController::class, 'view']);
$router->delete('/contact-submissions/{id}', [ContactSubmissionsController::class, 'delete']);
```

**Note**: These must be placed before the Element routes to avoid route conflicts (element routes use `/elements/{id}` pattern).

---

### 9. Update `app/Templates/FrontController.php` — Email Notification

**Purpose**: After storing a contact submission, if `contact_notification_email` setting is configured, send a notification email via `mail()`. Fail silently if `mail()` fails.

**Changes to `contactSubmit()` method**: Add email notification after the `insert()` call, before the redirect:

```php
// Send email notification if configured
$notifyEmail = Config::getString('contact_notification_email', '');
if ($notifyEmail !== '' && filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
    $emailSubject = 'New contact form submission: ' . ($subject ?: '(no subject)');
    $emailBody = "Name: {$name}\n"
        . "Email: {$email}\n"
        . "Subject: {$subject}\n"
        . "Date: " . date('Y-m-d H:i:s') . "\n\n"
        . "Message:\n{$message}\n";
    $headers = "From: noreply@" . parse_url(Config::getString('site_url', 'localhost'), PHP_URL_HOST) . "\r\n"
        . "Reply-To: {$email}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    $sent = @mail($notifyEmail, $emailSubject, $emailBody, $headers);
    if (!$sent) {
        \App\Core\Logger::warning('Contact form email notification failed', [
            'to' => $notifyEmail,
            'from' => $email,
        ]);
    }
}
```

**Also add** `use App\Core\Logger;` at the top of FrontController.php (if not already present).

---

### 10. `README.md` (NEW)

**Purpose**: Project description, requirements, installation steps, first-time setup, and usage guide.

**Implementation**:
```markdown
# LiteCMS

A lightweight content management system built with PHP. Designed as a simpler alternative to WordPress for small business websites.

## Features

- Pages, blog posts, and custom content types
- WYSIWYG editor (TinyMCE)
- AI writing assistant (powered by Claude)
- Visual page builder with reusable elements
- Visual design editor (Pencil) with .pen file support
- Media library with image upload
- Light/dark theme support
- User management with role-based access (admin/editor)
- Contact form with admin message viewer
- SEO meta fields and Open Graph tags
- Cookie consent banner (GDPR)
- Google Analytics integration (consent-aware)
- Responsive, mobile-first design
- Multi-database support: SQLite, PostgreSQL, MariaDB

## Requirements

- PHP 8.1 or higher
- Composer
- One of: SQLite (default), PostgreSQL 14+, or MariaDB 10.6+
- Apache with mod_rewrite (or equivalent URL rewriting)

## Installation

1. Clone the repository:

```bash
git clone <repository-url> litecms
cd litecms
```

2. Install PHP dependencies:

```bash
composer install
```

3. Configure the application:

```bash
cp .env.example .env
```

Edit `config/app.php` or set environment variables to configure:
- `DB_DRIVER` — `sqlite` (default), `pgsql`, or `mysql`
- `SITE_NAME` — Your site name
- `SITE_URL` — Your site's base URL
- `APP_SECRET` — Change to a random string for security

4. Ensure the `storage/` and `public/assets/uploads/` directories are writable:

```bash
chmod -R 775 storage/
chmod -R 775 public/assets/uploads/
```

5. Point your web server's document root to the `public/` directory.

6. Visit your site URL. The database and tables will be created automatically on first request.

## First-Time Setup

1. Navigate to `/admin/login`
2. Log in with the default credentials:
   - Username: `admin`
   - Password: `admin`
3. **Change the default password immediately** via Settings or User Management
4. Configure your site name, URL, and other settings at `/admin/settings`

## Usage

### Content Management

- **Dashboard**: Overview of content, media, and user counts
- **Content**: Create and manage pages and blog posts
- **Media**: Upload and manage images and files
- **Content Types**: Define custom content types with custom fields

### Page Builder

- When editing content, switch between HTML editor and Page Builder modes
- Page Builder uses reusable elements (hero sections, feature grids, CTAs, etc.)
- Elements can be styled per-instance with visual controls or custom CSS

### AI Assistant

1. Go to Settings and enter your Claude API key
2. When editing content, open the AI panel to get writing assistance
3. Use the Page Generator to create entire pages through conversation

### Design Editor

- Access the visual design editor at Design > Design Editor
- Create and edit `.pen` design files
- Convert designs to HTML for use on the public site

### Settings

- **General**: Site name, URL, timezone, items per page
- **AI**: Claude API key, model selection
- **Design System**: Theme colors, fonts, spacing
- **Cookie Consent & Analytics**: GDPR consent banner, Google Analytics
- **Contact**: Notification email for contact form submissions

## Database

LiteCMS supports three database backends:

| Driver | Config Value | Notes |
|--------|-------------|-------|
| SQLite | `sqlite` | Default. Zero configuration. File stored at `storage/database.sqlite` |
| PostgreSQL | `pgsql` | Set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` |
| MariaDB | `mysql` | Set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` |

Migrations run automatically on first request.

## File Structure

```
public/           Web root (point server here)
  index.php       Single entry point
  assets/         CSS, JS, uploads
app/              Application source code
  Core/           Framework core (router, config, request/response)
  Admin/          Admin controllers
  Auth/           Authentication
  AIAssistant/    AI integration
  Database/       Database layer
  PageBuilder/    Element and page rendering
  Templates/      Public site controller and template engine
config/           Configuration
templates/        PHP templates (admin + public)
migrations/       Database migration files
storage/          SQLite database, logs, cache
designs/          .pen design files
```

## License

MIT
```

---

## Detailed Class Specifications

### `App\Core\Logger`

```
PROPERTIES:
  - private static string $logDir = ''

METHODS:
  - private static logDir(): string
      Returns the logs directory path (storage/logs).
      Caches in static property.

  - public static error(string $message, array $context = []): void
      Writes an ERROR-level line to the log file.

  - public static warning(string $message, array $context = []): void
      Writes a WARNING-level line.

  - public static info(string $message, array $context = []): void
      Writes an INFO-level line.

  - private static write(string $level, string $message, array $context): void
      Core writing logic:
      1. Ensure log dir exists
      2. Rotate if file > 5MB
      3. Format: [YYYY-MM-DD HH:MM:SS] [LEVEL] message {context json}
      4. file_put_contents with LOCK_EX

  - private static rotate(string $file): void
      Rotates litecms.log → litecms.log.1 → .2 → .3 (max 3)
      Deletes .3 if it exists before rotation.
```

### `App\Admin\ContactSubmissionsController`

```
PROPERTIES:
  - private App $app

CONSTRUCTOR:
  __construct(App $app)

METHODS:
  - index(Request $request): Response
      GET /admin/contact-submissions
      1. Read page query param (default 1)
      2. Query total count of contact_submissions
      3. Query submissions with LIMIT/OFFSET, ordered by created_at DESC
      4. Render admin/contact-submissions/index template
      5. Return Response with X-Frame-Options: DENY

  - view(Request $request, string $id): Response
      GET /admin/contact-submissions/{id}
      1. Query contact_submissions WHERE id = $id
      2. If null: flash error, redirect to index
      3. Render admin/contact-submissions/view template
      4. Return Response with X-Frame-Options: DENY

  - delete(Request $request, string $id): Response
      DELETE /admin/contact-submissions/{id}
      1. Query contact_submissions WHERE id = $id
      2. If null: flash error, redirect to index
      3. Delete the row
      4. Flash success message, redirect to index
```

---

## Changes to Existing Files

### `app/Core/App.php`

**Changes**:
1. Wrap the body of `run()` in `try { ... } catch (\Throwable $e) { ... }`
2. In the catch block: log the error via `Logger::error()`, call new `renderErrorPage()` method
3. Add new `private renderErrorPage(Request $request, \Throwable $e): void` method:
   - Detects admin vs public page from URI
   - For public: renders `templates/public/error.php` with generic message
   - For admin: renders inline HTML error
   - If `debug` config is true, includes exception message
   - Falls back to plain HTML if even the error page render fails

### `app/Templates/FrontController.php`

**Changes**:
1. Add `use App\Core\Logger;` import
2. In `contactSubmit()`: After the `insert()` call and before the redirect, add email notification logic:
   - Check `contact_notification_email` setting
   - If set and valid email, call `@mail()` with submission details
   - On failure, log warning via `Logger::warning()`

### `templates/admin/layout.php`

**Changes**:
1. Add "Messages" link to sidebar nav, in the System section after Users and before Settings:
```php
<a href="/admin/contact-submissions"
   class="<?= ($activeNav ?? '') === 'messages' ? 'active' : '' ?>">
    <span class="nav-icon">&#9993;</span> Messages
</a>
```

### `public/index.php`

**Changes**:
1. Add `use App\Admin\ContactSubmissionsController;` import
2. Add three routes inside the `/admin` group:
```php
$router->get('/contact-submissions', [ContactSubmissionsController::class, 'index']);
$router->get('/contact-submissions/{id}', [ContactSubmissionsController::class, 'view']);
$router->delete('/contact-submissions/{id}', [ContactSubmissionsController::class, 'delete']);
```

---

## Acceptance Test Procedures

### Test 1: Fresh install works with no errors
```
1. Ensure vendor/ exists (composer install)
2. Delete storage/database.sqlite (or use a fresh test DB)
3. Instantiate the App, create a Request, verify no exceptions thrown
4. Database auto-creates, migrations run, admin user seeded
```

### Test 2: Database errors are logged, user sees friendly error page
```
1. Trigger a deliberate exception in a controller
2. Verify Logger::error() writes to storage/logs/litecms.log
3. Verify the response contains a user-friendly message (not a stack trace)
4. Verify HTTP status is 500
```

### Test 3: PHP error reporting doesn't leak details
```
1. With debug=false (default): verify error page does NOT contain exception details
2. With debug=true: verify error page DOES contain the exception message
```

### Test 4: Performance — homepage renders under 50ms
```
1. Measure execution time of the FrontController homepage method
2. Verify it completes in under 50ms
```

### Test 5: Codebase under 5,000 lines of PHP
```
1. Count lines in all .php files under app/
2. Verify total is under 5,000
```

### Test 6: README.md covers installation and setup
```
1. README.md exists at project root
2. Contains: requirements section, installation steps, first-time setup, usage guide
3. Has at least 50 lines of content
```

### Test 7: Contact submissions list at /admin/contact-submissions
```
1. ContactSubmissionsController exists and is autoloadable
2. index() method returns a Response with HTML containing 'Messages'
3. If submissions exist, they appear in the rendered HTML
```

### Test 8: Viewing a single submission shows all fields
```
1. Create a test submission in the database
2. Call view() with the submission ID
3. Verify response HTML contains name, email, subject, message, IP address, date
```

### Test 9: Deleting a submission removes it from the database
```
1. Create a test submission
2. Call delete() with the submission ID
3. Verify the row no longer exists in contact_submissions
4. Verify a success flash message is set
```

### Test 10: Email notification on contact form submission
```
1. Set 'contact_notification_email' setting in DB
2. Verify FrontController::contactSubmit() contains mail() call logic
3. If mail() is unavailable, verify it fails silently and logs a warning
```

### Test 11: Logger writes to file
```
1. Call Logger::error('Test message', ['key' => 'value'])
2. Verify storage/logs/litecms.log exists
3. Verify the log line contains [ERROR], the message, and the context JSON
```

### Test 12: Admin sidebar has Messages link
```
1. Read templates/admin/layout.php
2. Verify it contains '/admin/contact-submissions'
3. Verify it contains "Messages" text
```

### Test 13: Routes are registered for contact submissions
```
1. Read public/index.php
2. Verify it contains routes for contact-submissions: GET index, GET {id}, DELETE {id}
```

### Test 14: Error template exists
```
1. Verify templates/public/error.php exists
2. Verify it uses the public layout
3. Verify it displays $errorCode, $errorTitle, and $errorMessage
```

### Test 15: Global error handler catches exceptions
```
1. Verify App::run() contains try/catch around dispatch
2. Verify the catch block calls Logger::error()
3. Verify it calls renderErrorPage()
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);`
- PSR-4 namespacing: `App\Admin\ContactSubmissionsController` → `app/Admin/ContactSubmissionsController.php`
- No framework imports — only native PHP
- All user output escaped with `$this->e()` in templates
- Parameterized queries for all database access

### Error Handling Strategy
- **Global catch**: `App::run()` catches all `\Throwable` — nothing leaks to the user
- **Per-action catch**: Existing controller try/catch blocks remain — they handle expected errors (like invalid input)
- **Logging**: All caught exceptions are logged with file, line, and stack trace
- **User-facing**: Public site shows generic "Something went wrong" message. Admin site shows "An unexpected error occurred"
- **Debug mode**: When `debug=true` in config, error details are shown (for development only)

### Email Notification
- Uses PHP's built-in `mail()` function — works on most hosting
- Suppresses errors with `@mail()` — if mail fails, it's logged but doesn't break the user experience
- Reply-To set to the contact form sender's email
- From address generated from site URL hostname

### What This Chunk Does NOT Do
- Does not add CAPTCHA or honeypot to contact form (out of scope)
- Does not add rate limiting to contact form (existing CSRF protection is sufficient)
- Does not refactor existing code — only adds new features and the error handling wrapper
- Does not change any existing controller logic beyond the FrontController contact email addition

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `app/Core/Logger.php` | Class | **CREATE** |
| 2 | `templates/public/error.php` | Template | **CREATE** |
| 3 | `app/Core/App.php` | Class | **MODIFY** (add try/catch + renderErrorPage) |
| 4 | `app/Admin/ContactSubmissionsController.php` | Class | **CREATE** |
| 5 | `templates/admin/contact-submissions/index.php` | Template | **CREATE** |
| 6 | `templates/admin/contact-submissions/view.php` | Template | **CREATE** |
| 7 | `templates/admin/layout.php` | Template | **MODIFY** (add Messages nav link) |
| 8 | `public/index.php` | Entry point | **MODIFY** (add routes + import) |
| 9 | `app/Templates/FrontController.php` | Class | **MODIFY** (add email notification) |
| 10 | `README.md` | Documentation | **CREATE** |

---

## Estimated Scope

- **New PHP classes**: 2 (Logger, ContactSubmissionsController)
- **New templates**: 3 (error.php, contact-submissions/index.php, contact-submissions/view.php)
- **Modified files**: 4 (App.php, FrontController.php, layout.php, index.php)
- **New documentation**: 1 (README.md)
- **Approximate new PHP LOC**: ~200 lines
- **Approximate new template LOC**: ~120 lines
