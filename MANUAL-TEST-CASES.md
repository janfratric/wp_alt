# LiteCMS — Manual Test Cases

> **Scope**: Chunks 1.1 (Scaffolding & Core Framework) + 1.2 (Database Layer & Migrations) + 1.3 (Authentication System)
>
> **Last updated**: 2026-02-07

---

## Prerequisites

```bash
# 1. Install dependencies (if not done)
composer install

# 2. Start the dev server
php -S localhost:8000 -t public
```

---

## Test Group A: Browser — Pages & Routing

### A1. Homepage loads
1. Open [http://localhost:8000/](http://localhost:8000/)
2. **Expected**: Page renders with "Welcome to LiteCMS" heading
3. **Verify**: Page has proper HTML structure (`<html>`, `<head>`, `<body>`)
4. **Verify**: Title includes "LiteCMS"

### A2. Admin dashboard redirects to login when not authenticated
1. Clear cookies / use incognito window
2. Open [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard)
3. **Expected**: Redirected to `/admin/login` (not dashboard)
4. **Verify**: Login form is displayed with username and password fields

### A3. Login page loads
1. Open [http://localhost:8000/admin/login](http://localhost:8000/admin/login)
2. **Expected**: Centered card with "LiteCMS" heading and "Sign in to your account" subtitle
3. **Verify**: Form has username, password fields and a "Sign In" button
4. **Verify**: Page source contains a hidden `_csrf_token` field

### A4. 404 for unknown routes
1. Open [http://localhost:8000/this-does-not-exist](http://localhost:8000/this-does-not-exist)
2. **Expected**: Shows "404 Not Found"
3. Try a few more: `/admin`, `/admin/settings`, `/login`
4. **Expected**: All return 404 (these routes aren't registered yet)

### A5. Query strings don't break routing
1. Open [http://localhost:8000/?foo=bar](http://localhost:8000/?foo=bar)
2. **Expected**: Homepage loads normally (query string is stripped from route matching)
3. Open [http://localhost:8000/admin/login?debug=1](http://localhost:8000/admin/login?debug=1)
4. **Expected**: Login page loads normally

---

## Test Group B: Database — Auto-Creation & Migrations

### B1. Database is created on first request
1. Delete `storage/database.sqlite` if it exists
2. Open [http://localhost:8000/](http://localhost:8000/) in the browser
3. **Verify**: `storage/database.sqlite` now exists
4. **Verify**: File is non-empty (> 0 bytes)

### B2. All 7 tables exist
1. Run in terminal:
   ```bash
   sqlite3 storage/database.sqlite ".tables"
   ```
2. **Expected output** (8 tables — 7 app tables + 1 tracking table):
   ```
   _migrations      content          content_types    custom_fields
   ai_conversations media            settings         users
   ```

### B3. Migration tracking works
1. Run in terminal:
   ```bash
   sqlite3 storage/database.sqlite "SELECT * FROM _migrations;"
   ```
2. **Expected**: One row showing `001_initial.sqlite.sql` with a timestamp
3. **Verify**: `applied_at` has a reasonable date/time

### B4. Migrations are idempotent
1. Refresh the browser page (any URL) — this re-runs the migration check
2. Run:
   ```bash
   sqlite3 storage/database.sqlite "SELECT COUNT(*) FROM _migrations;"
   ```
3. **Expected**: Still `1` — no duplicate migration entries

---

## Test Group C: Database — Schema Verification

### C1. Users table has correct columns
```bash
sqlite3 storage/database.sqlite "PRAGMA table_info(users);"
```
**Expected columns**: `id`, `username`, `email`, `password_hash`, `role`, `created_at`, `updated_at`

### C2. Content table has correct columns
```bash
sqlite3 storage/database.sqlite "PRAGMA table_info(content);"
```
**Expected columns**: `id`, `type`, `title`, `slug`, `body`, `excerpt`, `status`, `author_id`, `template`, `sort_order`, `meta_title`, `meta_description`, `featured_image`, `created_at`, `updated_at`, `published_at`

### C3. Foreign keys are enabled
```bash
sqlite3 storage/database.sqlite "PRAGMA foreign_keys;"
```
**Expected**: `1` (enabled)

### C4. Indexes exist
```bash
sqlite3 storage/database.sqlite ".indexes"
```
**Expected**: Should list indexes like `idx_content_slug`, `idx_content_type`, `idx_content_status`, etc.

---

## Test Group D: Interactive PHP — Query Builder

Open a PHP interactive shell from the project root:

```bash
php -a
```

Then paste each block and check the output.

### D1. Bootstrap and connect
```php
require 'vendor/autoload.php';
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=storage/database.sqlite');
$pdo = App\Database\Connection::getInstance();
echo "Driver: " . App\Database\Connection::getDriver() . "\n";
// Expected: Driver: sqlite
```

### D2. Insert a user
```php
$id = App\Database\QueryBuilder::query('users')->insert([
    'username' => 'admin',
    'email' => 'admin@example.com',
    'password_hash' => password_hash('secret123', PASSWORD_BCRYPT),
    'role' => 'admin',
]);
echo "Inserted user ID: {$id}\n";
// Expected: Inserted user ID: 1
```

### D3. Read the user back
```php
$user = App\Database\QueryBuilder::query('users')
    ->select()
    ->where('username', 'admin')
    ->first();
print_r($user);
// Expected: Array with username=admin, email=admin@example.com, role=admin
```

### D4. Insert some content
```php
App\Database\QueryBuilder::query('content')->insert([
    'type' => 'post', 'title' => 'Hello World',
    'slug' => 'hello-world', 'body' => '<p>My first post!</p>',
    'status' => 'published', 'author_id' => 1,
]);
App\Database\QueryBuilder::query('content')->insert([
    'type' => 'post', 'title' => 'Draft Post',
    'slug' => 'draft-post', 'body' => '<p>Work in progress</p>',
    'status' => 'draft', 'author_id' => 1,
]);
App\Database\QueryBuilder::query('content')->insert([
    'type' => 'page', 'title' => 'About',
    'slug' => 'about', 'body' => '<p>About us</p>',
    'status' => 'published', 'author_id' => 1,
]);
echo "3 content items inserted\n";
```

### D5. Query with filters
```php
// Only published items
$published = App\Database\QueryBuilder::query('content')
    ->select()->where('status', 'published')->get();
echo "Published: " . count($published) . "\n";
// Expected: Published: 2

// Only posts
$posts = App\Database\QueryBuilder::query('content')
    ->select()->where('type', 'post')->get();
echo "Posts: " . count($posts) . "\n";
// Expected: Posts: 2

// Count all
$total = App\Database\QueryBuilder::query('content')->select()->count();
echo "Total: {$total}\n";
// Expected: Total: 3
```

### D6. Update and verify
```php
App\Database\QueryBuilder::query('content')
    ->where('slug', 'draft-post')
    ->update(['status' => 'published', 'title' => 'No Longer a Draft']);

$updated = App\Database\QueryBuilder::query('content')
    ->select()->where('slug', 'draft-post')->first();
echo "Title: {$updated['title']}, Status: {$updated['status']}\n";
// Expected: Title: No Longer a Draft, Status: published
```

### D7. Delete and verify
```php
$deleted = App\Database\QueryBuilder::query('content')
    ->where('slug', 'draft-post')
    ->delete();
echo "Deleted: {$deleted} row(s)\n";
// Expected: Deleted: 1 row(s)

$gone = App\Database\QueryBuilder::query('content')
    ->select()->where('slug', 'draft-post')->first();
echo "Still exists: " . ($gone === null ? "no" : "yes") . "\n";
// Expected: Still exists: no
```

### D8. Order and limit
```php
$items = App\Database\QueryBuilder::query('content')
    ->select('title', 'type')
    ->orderBy('title', 'ASC')
    ->limit(2)
    ->get();
foreach ($items as $item) {
    echo "- {$item['title']} ({$item['type']})\n";
}
// Expected: About and Hello World, alphabetically
```

### D9. Raw query
```php
$stmt = App\Database\QueryBuilder::raw(
    'SELECT type, COUNT(*) as cnt FROM content GROUP BY type',
    []
);
foreach ($stmt->fetchAll() as $row) {
    echo "{$row['type']}: {$row['cnt']}\n";
}
// Expected: counts per type (page: 1, post: 1)
```

### D10. Clean up test data (optional)
```php
App\Database\QueryBuilder::query('content')->where('id', '>', 0)->delete();
App\Database\QueryBuilder::query('users')->where('id', '>', 0)->delete();
echo "Cleaned up\n";
```

---

## Test Group E: Migration Files Exist

Verify all three driver-specific migration files are present:

```bash
ls migrations/
```

**Expected**:
```
001_initial.mysql.sql
001_initial.pgsql.sql
001_initial.sqlite.sql
```

Open each and verify they contain `CREATE TABLE` statements for all 7 tables.

---

## Test Group F: Authentication — Login & Logout

### F1. Default admin user is auto-created on first visit
1. Delete `storage/database.sqlite` if it exists
2. Open [http://localhost:8000/](http://localhost:8000/) to trigger bootstrap
3. Run:
   ```bash
   sqlite3 storage/database.sqlite "SELECT username, email, role, password_hash FROM users;"
   ```
4. **Expected**: One row: `admin|admin@localhost|admin|$2y$...` (bcrypt hash, NOT plaintext "admin")

### F2. Login with default credentials
1. Open [http://localhost:8000/admin/login](http://localhost:8000/admin/login)
2. Enter username: `admin`, password: `admin`
3. Click "Sign In"
4. **Expected**: Redirected to `/admin/dashboard`
5. **Verify**: Dashboard shows "Welcome to the LiteCMS admin panel"
6. **Verify**: Sidebar shows username "admin" and a "Logout" button

### F3. Session persists across requests
1. After logging in (F2), refresh the dashboard page
2. **Expected**: Still on dashboard (not redirected to login)
3. Open [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard) in a new tab
4. **Expected**: Dashboard loads (session is shared)

### F4. Logout destroys session
1. After logging in, click "Logout" in the sidebar
2. **Expected**: Redirected to `/admin/login`
3. Open [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard) directly
4. **Expected**: Redirected to `/admin/login` (session was destroyed)

### F5. Login with invalid credentials shows error
1. Open [http://localhost:8000/admin/login](http://localhost:8000/admin/login)
2. Enter username: `admin`, password: `wrongpassword`
3. Click "Sign In"
4. **Expected**: Redirected back to login page
5. **Verify**: Error message "Invalid username or password." is displayed

### F6. Already-logged-in user visiting login page redirects to dashboard
1. Log in as admin
2. Navigate to [http://localhost:8000/admin/login](http://localhost:8000/admin/login)
3. **Expected**: Redirected to `/admin/dashboard` (not shown the login form again)

---

## Test Group G: Authentication — CSRF Protection

### G1. POST without CSRF token returns 403
1. Using curl or browser dev tools, send a POST to `/admin/login` without `_csrf_token`:
   ```bash
   curl -X POST http://localhost:8000/admin/login -d "username=admin&password=admin" -v
   ```
2. **Expected**: HTTP 403 response with "Invalid or missing CSRF token"

### G2. POST with invalid CSRF token returns 403
1. Send a POST with a fake token:
   ```bash
   curl -X POST http://localhost:8000/admin/login -d "username=admin&password=admin&_csrf_token=fake" -v
   ```
2. **Expected**: HTTP 403 response

### G3. Login form includes valid CSRF token
1. Open [http://localhost:8000/admin/login](http://localhost:8000/admin/login)
2. View page source
3. **Verify**: Hidden input `_csrf_token` has a 64-character hex value
4. Submit the form normally with username/password
5. **Expected**: Login processes (not blocked by CSRF)

---

## Test Group H: Authentication — Rate Limiting

### H1. Rate limiting after 5 failed attempts
1. Open [http://localhost:8000/admin/login](http://localhost:8000/admin/login)
2. Enter wrong password 5 times in a row
3. **Verify**: Each attempt shows "Invalid username or password."
4. On the 6th attempt (even with correct password `admin`):
5. **Expected**: Error message "Too many failed login attempts. Please try again in 15 minutes."
6. **Verify**: A JSON file exists in `storage/cache/rate_limit/` with `locked_until` set

### H2. Rate limit clears after lockout expires
1. After being rate-limited, manually delete the file in `storage/cache/rate_limit/`
2. Try logging in with correct credentials
3. **Expected**: Login succeeds

---

## Test Group I: Authentication — Admin Layout Updates

### I1. Admin layout shows user info and logout
1. Log in as admin
2. **Verify**: Sidebar shows "admin" username text
3. **Verify**: Sidebar has a "Logout" button/link
4. **Verify**: Logout button is inside a form with CSRF token (view source)

### I2. Flash messages display correctly
1. Log in with wrong credentials
2. **Verify**: Red error alert appears on login page
3. Refresh the page
4. **Verify**: Error message is gone (flash messages are consumed on read)

---

## Summary Checklist

| # | Test | Status |
|---|------|--------|
| A1 | Homepage loads | ☐ |
| A2 | Admin dashboard redirects to login | ☐ |
| A3 | Login page loads | ☐ |
| A4 | 404 for unknown routes | ☐ |
| A5 | Query strings don't break routing | ☐ |
| B1 | Database auto-created | ☐ |
| B2 | All 7+1 tables exist | ☐ |
| B3 | Migration tracking works | ☐ |
| B4 | Migrations are idempotent | ☐ |
| C1 | Users table schema correct | ☐ |
| C2 | Content table schema correct | ☐ |
| C3 | Foreign keys enabled | ☐ |
| C4 | Indexes exist | ☐ |
| D1–D10 | Query builder CRUD operations | ☐ |
| E1 | Migration files for all 3 drivers exist | ☐ |
| F1 | Default admin auto-created with bcrypt hash | ☐ |
| F2 | Login with default credentials | ☐ |
| F3 | Session persists across requests | ☐ |
| F4 | Logout destroys session | ☐ |
| F5 | Login with invalid credentials shows error | ☐ |
| F6 | Already-logged-in user redirected from login | ☐ |
| G1 | POST without CSRF returns 403 | ☐ |
| G2 | POST with invalid CSRF returns 403 | ☐ |
| G3 | Login form includes valid CSRF token | ☐ |
| H1 | Rate limiting after 5 failures | ☐ |
| H2 | Rate limit clears after expiry | ☐ |
| I1 | Admin layout shows user + logout | ☐ |
| I2 | Flash messages display and clear | ☐ |
