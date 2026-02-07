# LiteCMS — Manual Test Cases

> **Scope**: Chunks 1.1 (Scaffolding & Core Framework) + 1.2 (Database Layer & Migrations)
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

### A2. Admin dashboard loads
1. Open [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard)
2. **Expected**: Page renders with "Dashboard" heading
3. **Verify**: Shows "Welcome to the LiteCMS admin panel"
4. **Verify**: Uses a different layout than the public homepage

### A3. 404 for unknown routes
1. Open [http://localhost:8000/this-does-not-exist](http://localhost:8000/this-does-not-exist)
2. **Expected**: Shows "404 Not Found"
3. Try a few more: `/admin`, `/admin/settings`, `/login`
4. **Expected**: All return 404 (these routes aren't registered yet)

### A4. Query strings don't break routing
1. Open [http://localhost:8000/?foo=bar](http://localhost:8000/?foo=bar)
2. **Expected**: Homepage loads normally (query string is stripped from route matching)
3. Open [http://localhost:8000/admin/dashboard?debug=1](http://localhost:8000/admin/dashboard?debug=1)
4. **Expected**: Dashboard loads normally

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

## Summary Checklist

| # | Test | Status |
|---|------|--------|
| A1 | Homepage loads | ☐ |
| A2 | Admin dashboard loads | ☐ |
| A3 | 404 for unknown routes | ☐ |
| A4 | Query strings don't break routing | ☐ |
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
