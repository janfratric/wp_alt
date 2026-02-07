# LiteCMS — Manual Test Cases

> **Scope**: Chunks 1.1 (Scaffolding & Core Framework) + 1.2 (Database Layer & Migrations) + 1.3 (Authentication System) + 2.1 (Admin Layout & Dashboard) + 2.2 (Content CRUD) + 2.3 (Media Management)
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
3. Try: `/admin`, `/login`
4. **Expected**: Both return 404 (these routes aren't registered)
5. Note: `/admin/content` is now a full content list (Chunk 2.2); `/admin/media` is now the media library (Chunk 2.3); `/admin/users`, `/admin/settings` still have placeholder pages

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
5. **Verify**: Dashboard shows stats cards (Total Content, Published, Drafts, Users, Media Files)
6. **Verify**: Sidebar shows username "admin", role "Admin", and a "Logout" button

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

## Test Group J: Admin Dashboard — Stats & Layout (Chunk 2.1)

### J1. Dashboard loads with stats cards
1. Log in as admin
2. Open [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard)
3. **Verify**: 5 stat cards are visible: Total Content, Published, Drafts, Users, Media Files
4. **Verify**: On fresh install — Total Content = 0, Published = 0, Drafts = 0, Users = 1, Media Files = 0
5. **Verify**: Total Content card shows "0 pages, 0 posts" detail line

### J2. Dashboard recent content table (empty state)
1. On a fresh install with no content
2. **Verify**: "Recent Content" section shows "No content yet. Start by creating your first page or post."
3. **Verify**: No table is rendered when there is no content

### J3. Admin CSS and JS loaded
1. Open [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard)
2. **Verify**: Page source includes `<link rel="stylesheet" href="/assets/css/admin.css">`
3. **Verify**: Page source includes `<script src="/assets/js/admin.js"></script>`
4. **Verify**: Page is styled (dark sidebar, white topbar, card-based stats)

### J4. Security headers on dashboard
1. Open browser DevTools → Network tab
2. Navigate to [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard)
3. Click on the request and check response headers
4. **Verify**: `X-Frame-Options: DENY` header is present
5. **Verify**: `Content-Security-Policy` header contains `default-src 'self'` and `script-src 'self'`

---

## Test Group K: Sidebar Navigation (Chunk 2.1)

### K1. All 5 navigation links present
1. Log in and visit dashboard
2. **Verify**: Sidebar contains these links: Dashboard, Content, Media, Users, Settings
3. **Verify**: Links are grouped under section labels: "Main", "Content", "System"
4. **Verify**: Each link has a Unicode icon (square, pencil, camera, people, gear)

### K2. Active state highlighting
1. Visit [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard)
2. **Verify**: "Dashboard" link in sidebar is highlighted (has `active` class)
3. Click "Content" link
4. **Verify**: "Content" link is now highlighted, "Dashboard" is not
5. Click "Media", "Users", "Settings" in turn
6. **Verify**: Each page highlights its respective nav link

### K3. All sidebar links work (no 404s)
1. Click each of the 5 sidebar links in order
2. **Verify**: All pages load (no 404 errors)
3. **Verify**: Content shows a content list with filters and "+ New Content" button (Chunk 2.2); Media shows the media library with upload form (Chunk 2.3); Users, Settings show "coming soon" placeholder messages
4. **Verify**: Dashboard shows the full stats dashboard

### K4. Sidebar user info and logout
1. **Verify**: Sidebar footer shows username "admin"
2. **Verify**: Sidebar footer shows role "Admin"
3. **Verify**: "Logout" button is present in the sidebar footer
4. Click "Logout"
5. **Verify**: Redirected to `/admin/login` (session destroyed)

### K5. Top bar
1. Visit dashboard
2. **Verify**: Top bar shows the page title ("Dashboard")
3. **Verify**: Top bar has a "View Site" link that opens the homepage in a new tab
4. Click "Content" in sidebar
5. **Verify**: Top bar title changes to "Content"

---

## Test Group L: Responsive Design (Chunk 2.1)

### L1. Desktop layout (>768px)
1. Open dashboard on a desktop browser (wide viewport)
2. **Verify**: Sidebar is visible on the left, fixed position
3. **Verify**: Hamburger toggle button (three lines) is NOT visible
4. **Verify**: Content area is beside the sidebar, not overlapping

### L2. Mobile layout (<=768px)
1. Resize browser window to <=768px wide (or use DevTools responsive mode)
2. **Verify**: Sidebar slides off-screen (hidden)
3. **Verify**: Hamburger toggle button (three lines / ☰) appears in the top bar
4. Click the hamburger button
5. **Verify**: Sidebar slides in from the left
6. **Verify**: A semi-transparent overlay appears behind the sidebar
7. Click the overlay
8. **Verify**: Sidebar slides back out and overlay disappears

### L3. Stats grid responds to width
1. On desktop — stats cards display in a row (up to 5 across)
2. On mobile — stats cards stack (1-2 per row)
3. **Verify**: Cards remain readable at all widths

---

## Test Group M: Flash Messages (Chunk 2.1)

### M1. Flash messages use CSS classes
1. Log in with wrong credentials to trigger an error flash
2. **Verify**: Error message appears with a styled alert box (red background, border)
3. **Verify**: Page source shows `class="alert alert-error"` — NO inline `style=` attribute on the alert div

### M2. Flash messages auto-dismiss
1. Trigger a flash message (e.g., failed login, then correct login to see success flash)
2. **Verify**: After ~5 seconds, the flash message fades out and disappears
3. **Verify**: The message is removed from the DOM (not just hidden)

---

## Test Group N: Content List — Display & Filters (Chunk 2.2)

### N1. Content list loads (empty state)
1. Log in as admin (fresh database, no content yet)
2. Open [http://localhost:8000/admin/content](http://localhost:8000/admin/content)
3. **Verify**: Page loads with "Content" heading and "+ New Content" button
4. **Verify**: Empty state message "No content found." with "Create your first page" link
5. **Verify**: Filter bar is present with Search, Type, Status dropdowns and Filter/Reset buttons
6. **Verify**: Sidebar highlights "Content" nav link

### N2. Content list shows items after creation
1. Create a page and a post (see Test Group O)
2. Open [http://localhost:8000/admin/content](http://localhost:8000/admin/content)
3. **Verify**: Both items appear in the table with title, type badge, status badge, author, and date
4. **Verify**: Title links to the edit page (`/admin/content/{id}/edit`)
5. **Verify**: Slug is shown below the title in muted text

### N3. Filter by type
1. With both pages and posts in the database
2. Select "Page" from Type dropdown, click "Filter"
3. **Verify**: Only pages appear in the list
4. Select "Post" — only posts appear
5. Click "Reset" — all items appear again
6. **Verify**: The selected filter value persists in the dropdown after filtering

### N4. Filter by status
1. With items in various statuses (draft, published, archived)
2. Select "Draft" from Status dropdown, click "Filter"
3. **Verify**: Only draft items appear
4. Select "Published" — only published items appear
5. **Verify**: Filters can be combined (e.g., type=page AND status=draft)

### N5. Search by title
1. Type a partial title in the search box (e.g., "About")
2. Click "Filter"
3. **Verify**: Only items with matching titles appear
4. **Verify**: Search term is preserved in the input field after filtering
5. Clear search and click "Filter" — all items return

### N6. Pagination
1. Create 12+ content items (items_per_page defaults to 10)
2. Open [http://localhost:8000/admin/content](http://localhost:8000/admin/content)
3. **Verify**: First page shows 10 items, "Next" link appears
4. Click "Next" — page 2 shows remaining items, "Prev" link appears
5. **Verify**: "Page X of Y" info is displayed between nav links
6. **Verify**: Applying a filter and then paginating preserves the filter (check URL query params)

### N7. Bulk actions — delete
1. Select 2–3 items using checkboxes
2. Select "Delete" from the bulk actions dropdown
3. Click "Apply" — browser shows confirmation dialog
4. Confirm — items are removed, flash message shows "{N} item(s) deleted."

### N8. Bulk actions — status change
1. Select items, choose "Set Published" from bulk actions, click "Apply"
2. **Verify**: Selected items change to "Published" status
3. Repeat with "Set Draft" and "Set Archived"

### N9. Select-all checkbox
1. Click the checkbox in the table header row
2. **Verify**: All row checkboxes become checked
3. Uncheck the header checkbox — all row checkboxes uncheck

---

## Test Group O: Content Editor — Create & Edit (Chunk 2.2)

### O1. Create content form loads
1. Click "+ New Content" on the content list, or visit [http://localhost:8000/admin/content/create](http://localhost:8000/admin/content/create)
2. **Verify**: Two-column layout: main area (title, slug, body, excerpt) and sidebar (publish, SEO, featured image)
3. **Verify**: Title field is empty, slug field is empty with "/" prefix
4. **Verify**: Type defaults to "Page", Status defaults to "Draft"
5. **Verify**: Submit button reads "Create"

### O2. Create with type=post preset
1. Visit [http://localhost:8000/admin/content/create?type=post](http://localhost:8000/admin/content/create?type=post)
2. **Verify**: Type dropdown pre-selects "Post"

### O3. TinyMCE loads in the body field
1. Open the create/edit form
2. **Verify**: After a moment, the plain `<textarea>` is replaced by the TinyMCE WYSIWYG editor
3. **Verify**: Toolbar includes formatting buttons (bold, italic, lists, links, code, etc.)
4. Type and format some text in the editor
5. **Note**: A small "This domain is not registered..." banner from TinyMCE is expected (using `no-api-key`)

### O4. Slug auto-generates from title
1. Start typing in the Title field (e.g., "My Test Page")
2. **Verify**: Slug field auto-populates with "my-test-page" as you type
3. Clear the slug field and type a custom value (e.g., "custom-slug")
4. Change the title — slug should NOT change (manual edit is preserved)

### O5. Create a published page
1. Enter title: "About Us"
2. Enter some body text in TinyMCE
3. Set Type to "Page", Status to "Published"
4. Click "Create"
5. **Expected**: Redirected to the edit page for the new item (`/admin/content/{id}/edit`)
6. **Verify**: Flash success message "Content created successfully."
7. **Verify**: Slug was auto-generated as "about-us"

### O6. Edit existing content
1. From the content list, click "Edit" on an existing item
2. **Verify**: Form loads with all existing data populated (title, slug, body in TinyMCE, excerpt, type, status, SEO fields)
3. **Verify**: Submit button reads "Update"
4. **Verify**: Form action points to `/admin/content/{id}` with `_method=PUT` hidden field
5. Change the title and body, click "Update"
6. **Expected**: Flash success message "Content updated successfully."
7. **Verify**: Changes are persisted (refresh the page to confirm)

### O7. Duplicate slug uniqueness
1. Create a new item with the same title as an existing one (e.g., another "About Us")
2. **Verify**: Slug is auto-generated as "about-us-2" (or next available number)
3. Create a third — slug becomes "about-us-3"

### O8. published_at auto-set
1. Create a new item with Status = "Published" and leave "Publish Date" empty
2. After saving, edit the item
3. **Verify**: "Publish Date" field now shows the current date/time (auto-set on publish)

### O9. Scheduled publishing
1. Create a new item with Status = "Published" and set "Publish Date" to a future date
2. After saving, edit the item
3. **Verify**: The future date is preserved in the "Publish Date" field

### O10. SEO fields persist
1. Create or edit content, fill in "Meta Title" and "Meta Description" in the SEO sidebar card
2. Save and re-open the editor
3. **Verify**: SEO field values are preserved

### O11. Featured image field
1. Enter a URL in the "Image URL" field (e.g., `/assets/uploads/photo.jpg`)
2. Save and re-open
3. **Verify**: Value persists
4. **Note**: "Media browser coming in a future update." hint text is shown

---

## Test Group P: Content — Delete & Validation (Chunk 2.2)

### P1. Delete single item
1. On the content list, click "Delete" on an item
2. **Verify**: Browser confirmation dialog appears ("Are you sure you want to delete this content?")
3. Confirm — item is removed from the list
4. **Verify**: Flash message "Content deleted."

### P2. Delete non-existent item
1. Visit `/admin/content/99999/edit` (non-existent ID)
2. **Expected**: Redirected to `/admin/content` with error flash "Content not found."

### P3. Validation — empty title rejected
1. Open create form, leave title empty, click "Create"
2. **Expected**: Redirected back to create form with error flash "Title is required."
3. **Verify**: No new row was inserted in the database

### P4. Validation — invalid type rejected
1. Using browser dev tools, change the type `<select>` value to "invalid"
2. Submit the form
3. **Expected**: Error flash "Invalid content type." — no row inserted

### P5. Validation — invalid status rejected
1. Using browser dev tools, change the status `<select>` value to "unknown"
2. Submit the form
3. **Expected**: Error flash "Invalid status." — no row inserted

---

## Test Group Q: Content — Security & Headers (Chunk 2.2)

### Q1. CSRF protection on content forms
1. Open the content create form, view page source
2. **Verify**: `_csrf_token` hidden input is present in the form
3. **Verify**: Edit form also has `_csrf_token`
4. **Verify**: Bulk action form also has `_csrf_token`

### Q2. Method override on edit form
1. Open the edit form for an existing item, view page source
2. **Verify**: `<input type="hidden" name="_method" value="PUT">` is present
3. **Verify**: Delete buttons on the content list have `_method=DELETE` hidden inputs

### Q3. Security headers on content pages
1. Open browser DevTools > Network tab
2. Visit [http://localhost:8000/admin/content](http://localhost:8000/admin/content)
3. **Verify**: `X-Frame-Options: DENY` header is present
4. **Verify**: `Content-Security-Policy` header includes `cdn.tiny.cloud` allowances for script-src, style-src, connect-src, font-src

### Q4. XSS prevention
1. Create content with title: `<script>alert('xss')</script>`
2. **Verify**: On the content list, the title is displayed as escaped text (visible tags), NOT executed as JavaScript
3. **Verify**: The slug is generated safely from the title

---

## Test Group R: Content — Responsive & CSS (Chunk 2.2)

### R1. Editor layout — desktop
1. Open the content editor on a wide viewport (>768px)
2. **Verify**: Two-column layout: main area on the left, sidebar (320px) on the right
3. **Verify**: TinyMCE editor fills the main column width

### R2. Editor layout — mobile
1. Resize browser to <=768px (or use DevTools responsive mode)
2. **Verify**: Editor switches to single-column layout (sidebar stacks below main area)
3. **Verify**: All form fields remain accessible and usable

### R3. Filter bar — responsive
1. On mobile width, check the content list filter bar
2. **Verify**: Filter inputs stack vertically instead of horizontally

### R4. Dashboard stats update with content
1. Create several pages and posts with different statuses
2. Visit [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard)
3. **Verify**: Stats cards reflect the correct counts (Total Content, Published, Drafts, pages, posts)
4. **Verify**: Recent content table shows the latest items with correct author name

---

## Test Group S: Media Library — Upload & Display (Chunk 2.3)

### S1. Media library loads (empty state)
1. Log in as admin (fresh database, no media yet)
2. Open [http://localhost:8000/admin/media](http://localhost:8000/admin/media)
3. **Verify**: Page loads with "Media Library" heading and "0 file(s)" count
4. **Verify**: Upload form with drag & drop zone is visible
5. **Verify**: Empty state message "No media files uploaded yet."
6. **Verify**: Sidebar highlights "Media" nav link

### S2. Upload a JPG image
1. Go to /admin/media
2. Click the upload zone, select a .jpg file under 5MB
3. Click "Upload"
4. **Verify**: Flash message "File uploaded successfully." appears
5. **Verify**: Image appears in the media grid with correct thumbnail
6. **Verify**: File exists on disk at `public/assets/uploads/YYYY/MM/{hash}.jpg`
7. **Verify**: Card shows original filename, MIME type (image/jpeg), and uploader (admin)

### S3. Upload a .php file — rejected
1. Attempt to upload a file named "test.php"
2. **Verify**: Error message "File type not allowed. Allowed: jpg, jpeg, png, gif, webp, pdf"
3. **Verify**: No file saved to disk, no record in media table

### S4. Upload a faked extension — rejected by MIME check
1. Rename a PHP file to "malicious.jpg"
2. Attempt to upload it
3. **Verify**: Error message "File content does not match its extension."
4. **Verify**: No file saved

### S5. Upload file too large — rejected
1. Attempt to upload a file larger than 5MB
2. **Verify**: Error message includes "File is too large. Maximum size: 5 MB"

### S6. Delete a media item
1. Upload an image
2. Click "Delete" on the media card. Confirm the dialog
3. **Verify**: Flash message "Media deleted."
4. **Verify**: File removed from disk
5. **Verify**: Item removed from the grid

### S7. Upload preview
1. Click the upload zone and select an image
2. **Verify**: Upload zone hides, preview area appears with image thumbnail, filename, and file size
3. **Verify**: "Upload" and "Cancel" buttons are visible
4. Click "Cancel"
5. **Verify**: Preview hides, upload zone reappears

### S8. Drag and drop upload
1. Drag an image file from the desktop onto the upload zone
2. **Verify**: Upload zone highlights with border color change
3. **Verify**: After drop, preview area appears with file info

### S9. Media pagination
1. Upload 12+ images (items_per_page defaults to 10)
2. Open /admin/media
3. **Verify**: First page shows 10 items, "Next →" link appears
4. Click "Next" — page 2 shows remaining items, "← Prev" link appears
5. **Verify**: "Page X of Y" info is displayed

### S10. View button opens file
1. Upload an image
2. Click "View" on the media card
3. **Verify**: Image opens in a new browser tab at `/assets/uploads/YYYY/MM/{hash}.ext`

---

## Test Group T: Media Browser Modal & Content Integration (Chunk 2.3)

### T1. TinyMCE media browser button
1. Go to Content → Create
2. In the TinyMCE toolbar, find the media browser button (image icon labeled "Insert from Media Library")
3. Click it
4. **Verify**: Modal opens with "Select Media" header
5. **Verify**: Modal shows uploaded images in a grid (or "No images found" if empty)

### T2. Insert image from media browser into TinyMCE
1. Upload some images first (via /admin/media)
2. Go to Content → Create, click the media browser toolbar button
3. Click an image in the modal grid
4. **Verify**: Image gets a blue border (selected state)
5. Click "Select"
6. **Verify**: `<img>` tag is inserted into the TinyMCE editor body
7. **Verify**: Modal closes

### T3. Featured image — Browse Media
1. Go to Content → Create
2. In the sidebar "Featured Image" card, click "Browse Media"
3. Select an image from the modal
4. **Verify**: Image preview appears in the Featured Image card
5. **Verify**: "Remove" button appears
6. **Verify**: Hidden input contains the image URL (check with browser dev tools)

### T4. Featured image — Remove
1. After selecting a featured image (T3), click "Remove"
2. **Verify**: Preview disappears
3. **Verify**: Hidden input value is cleared

### T5. Featured image persists after save
1. Create content with a featured image selected
2. Save the content, then edit it again
3. **Verify**: Featured image preview is still shown with the correct URL
4. **Verify**: "Remove" button is visible

### T6. TinyMCE drag-and-drop image upload
1. Go to Content → Edit an existing item
2. Drag an image file from the desktop into the TinyMCE editor area
3. **Verify**: Image is uploaded via AJAX to `/admin/media/upload`
4. **Verify**: Image appears inline in the editor
5. **Verify**: New file appears in the media library

### T7. Media browser modal — Cancel and close
1. Open the media browser modal
2. Click "Cancel" — modal closes
3. Open again, click the × button — modal closes
4. Open again, click the dark overlay — modal closes
5. **Verify**: No image was inserted in any case

---

## Test Group U: Media — Security & Headers (Chunk 2.3)

### U1. CSRF on upload form
1. Open /admin/media, view page source
2. **Verify**: Upload form has `_csrf_token` hidden input

### U2. CSRF on delete form
1. With media items present, view page source of /admin/media
2. **Verify**: Each delete form has `_csrf_token` hidden input and `_method=DELETE`

### U3. Security headers on media pages
1. Open browser DevTools > Network tab
2. Visit /admin/media
3. **Verify**: `X-Frame-Options: DENY` header is present
4. **Verify**: `Content-Security-Policy` header includes `cdn.jsdelivr.net` allowances

### U4. Uploads directory .htaccess
1. Navigate to `public/assets/uploads/.htaccess`
2. **Verify**: Contains `php_flag engine off` or `FilesMatch` deny for PHP files
3. **Verify**: Contains `X-Content-Type-Options: nosniff` header

### U5. Randomized filenames on disk
1. Upload an image named "my-photo.jpg"
2. Check `public/assets/uploads/YYYY/MM/` directory
3. **Verify**: File is stored as a 32-character hex string + extension (e.g., `a1b2c3d4...f0.jpg`)
4. **Verify**: Original filename "my-photo.jpg" is NOT used on disk

### U6. Dashboard media files count updates
1. Upload several media files
2. Visit /admin/dashboard
3. **Verify**: "Media Files" stat card shows the correct count

---

## Summary Checklist

(continued from previous chunks)

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
| J1 | Dashboard loads with stats cards | ☐ |
| J2 | Dashboard recent content (empty state) | ☐ |
| J3 | Admin CSS and JS loaded | ☐ |
| J4 | Security headers on dashboard | ☐ |
| K1 | All 5 navigation links present | ☐ |
| K2 | Active state highlighting | ☐ |
| K3 | All sidebar links work (no 404s) | ☐ |
| K4 | Sidebar user info and logout | ☐ |
| K5 | Top bar title and View Site link | ☐ |
| L1 | Desktop layout (sidebar visible) | ☐ |
| L2 | Mobile layout (sidebar toggles) | ☐ |
| L3 | Stats grid responds to width | ☐ |
| M1 | Flash messages use CSS classes | ☐ |
| M2 | Flash messages auto-dismiss | ☐ |
| N1 | Content list loads (empty state) | ☐ |
| N2 | Content list shows items | ☐ |
| N3 | Filter by type | ☐ |
| N4 | Filter by status | ☐ |
| N5 | Search by title | ☐ |
| N6 | Pagination | ☐ |
| N7 | Bulk delete | ☐ |
| N8 | Bulk status change | ☐ |
| N9 | Select-all checkbox | ☐ |
| O1 | Create form loads | ☐ |
| O2 | Create with type=post preset | ☐ |
| O3 | TinyMCE loads | ☐ |
| O4 | Slug auto-generates from title | ☐ |
| O5 | Create a published page | ☐ |
| O6 | Edit existing content | ☐ |
| O7 | Duplicate slug uniqueness | ☐ |
| O8 | published_at auto-set | ☐ |
| O9 | Scheduled publishing | ☐ |
| O10 | SEO fields persist | ☐ |
| O11 | Featured image field | ☐ |
| P1 | Delete single item | ☐ |
| P2 | Delete non-existent item | ☐ |
| P3 | Empty title rejected | ☐ |
| P4 | Invalid type rejected | ☐ |
| P5 | Invalid status rejected | ☐ |
| Q1 | CSRF on content forms | ☐ |
| Q2 | Method override on edit form | ☐ |
| Q3 | Security headers on content pages | ☐ |
| Q4 | XSS prevention | ☐ |
| R1 | Editor layout — desktop | ☐ |
| R2 | Editor layout — mobile | ☐ |
| R3 | Filter bar — responsive | ☐ |
| R4 | Dashboard stats update with content | ☐ |
| S1 | Media library loads (empty state) | ☐ |
| S2 | Upload a JPG image | ☐ |
| S3 | Upload .php file rejected | ☐ |
| S4 | Faked extension rejected by MIME check | ☐ |
| S5 | Upload file too large rejected | ☐ |
| S6 | Delete a media item | ☐ |
| S7 | Upload preview | ☐ |
| S8 | Drag and drop upload | ☐ |
| S9 | Media pagination | ☐ |
| S10 | View button opens file | ☐ |
| T1 | TinyMCE media browser button | ☐ |
| T2 | Insert image from media browser | ☐ |
| T3 | Featured image — Browse Media | ☐ |
| T4 | Featured image — Remove | ☐ |
| T5 | Featured image persists after save | ☐ |
| T6 | TinyMCE drag-and-drop upload | ☐ |
| T7 | Media browser modal — Cancel/close | ☐ |
| U1 | CSRF on upload form | ☐ |
| U2 | CSRF on delete form | ☐ |
| U3 | Security headers on media pages | ☐ |
| U4 | Uploads directory .htaccess | ☐ |
| U5 | Randomized filenames on disk | ☐ |
| U6 | Dashboard media files count updates | ☐ |
