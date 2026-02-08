# LiteCMS — Manual Test Cases

> **Scope**: Chunks 1.1 (Scaffolding & Core Framework) + 1.2 (Database Layer & Migrations) + 1.3 (Authentication System) + 2.1 (Admin Layout & Dashboard) + 2.2 (Content CRUD) + 2.3 (Media Management) + 2.4 (User Management) + 3.1 (Template Engine & Front Controller) + 3.2 (Public Templates & Styling) + 4.1 (Claude API Client & Backend) + 4.2 (AI Chat Panel Frontend) + 5.1 (Custom Content Types) + 5.2 (Settings Panel & Site Configuration) + 5.3 (AI Page Generator) + 6.1 (Element Catalogue & Rendering Engine) + 6.2 (Content Editor Element Mode & Page Builder UI)
>
> **Last updated**: 2026-02-08

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

### A1. Homepage loads with navigation and recent posts
1. Open [http://localhost:8000/](http://localhost:8000/)
2. **Expected**: Page renders with "Welcome to LiteCMS" heading
3. **Verify**: Page has proper HTML structure (`<html>`, `<head>`, `<body>`)
4. **Verify**: Title includes "LiteCMS"
5. **Verify**: Navigation bar shows Home, Blog, and any published pages
6. **Verify**: Recent published posts are displayed (if any exist)
7. **Verify**: Page source includes `<meta property="og:type" content="website">`

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

### A4. Styled 404 for unknown routes
1. Open [http://localhost:8000/this-does-not-exist](http://localhost:8000/this-does-not-exist)
2. **Expected**: Shows styled "404 — Page Not Found" page with navigation and "Return to homepage" link
3. **Verify**: Page uses the public layout (has header, nav, footer)
4. Try: `/admin`, `/login`
5. **Expected**: Both return styled 404 page (these routes aren't registered)
6. Note: `/admin/content` is now a full content list (Chunk 2.2); `/admin/media` is now the media library (Chunk 2.3); `/admin/users` is now the user management section (Chunk 2.4); `/admin/settings` is now the real settings page (Chunk 4.1); `/contact` is the public contact form (Chunk 3.2)

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

### K1. All 7 navigation links present
1. Log in and visit dashboard
2. **Verify**: Sidebar contains these links: Dashboard, Content, Media, Content Types, Generate Page, Users, Settings
3. **Verify**: Links are grouped under section labels: "Main", "Content", "System"
4. **Verify**: Each link has a Unicode icon (square, pencil, camera, clipboard, star, people, gear)

### K2. Active state highlighting
1. Visit [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard)
2. **Verify**: "Dashboard" link in sidebar is highlighted (has `active` class)
3. Click "Content" link
4. **Verify**: "Content" link is now highlighted, "Dashboard" is not
5. Click "Media", "Users", "Settings" in turn
6. **Verify**: Each page highlights its respective nav link

### K3. All sidebar links work (no 404s)
1. Click each of the 7 sidebar links in order
2. **Verify**: All pages load (no 404 errors)
3. **Verify**: Content shows a content list with filters and "+ New Content" button (Chunk 2.2); Media shows the media library with upload form (Chunk 2.3); Content Types shows the custom content types list with "+ New Content Type" button (Chunk 5.1); Generate Page shows the AI wizard with content type selector (Chunk 5.3); Users shows the user management list with "+ New User" button (Chunk 2.4); Settings shows AI and General settings form (Chunk 4.1)
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

## Test Group V: User List — Display & Search (Chunk 2.4)

### V1. User list loads with default admin
1. Log in as admin (fresh database)
2. Open [http://localhost:8000/admin/users](http://localhost:8000/admin/users)
3. **Verify**: Page loads with "Users" heading and "+ New User" button
4. **Verify**: Admin user appears in the table with username, email, role badge, and created date
5. **Verify**: Sidebar highlights "Users" nav link
6. **Verify**: "1 user(s)" count shown in card header

### V2. Search by username
1. Create additional users (see Test Group W)
2. Type a username in the search box, click "Search"
3. **Verify**: Only matching users appear
4. **Verify**: Search term is preserved in the input field
5. Click "Reset" — all users return

### V3. Search by email
1. Type an email in the search box, click "Search"
2. **Verify**: Users with matching email appear
3. **Verify**: Partial matches work (e.g., "example.com" matches all example.com emails)

### V4. User list pagination
1. Create 12+ users
2. Open /admin/users
3. **Verify**: First page shows 10 items, "Next »" link appears
4. Click "Next" — page 2 shows remaining items, "« Prev" link appears
5. **Verify**: "Page X of Y" info is displayed

### V5. Delete button hidden for own account
1. View the user list as admin
2. **Verify**: The "Delete" button is NOT shown on admin's own row
3. **Verify**: Other users have both "Edit" and "Delete" buttons

---

## Test Group W: User Editor — Create & Edit (Chunk 2.4)

### W1. Create user form loads
1. Click "+ New User" or visit [http://localhost:8000/admin/users/create](http://localhost:8000/admin/users/create)
2. **Verify**: Form has username, email, role select, and password fields
3. **Verify**: Role defaults to "Editor"
4. **Verify**: Password field is required (has `required` attribute)
5. **Verify**: Submit button reads "Create User"

### W2. Create a new editor user
1. Fill in: username=`testuser`, email=`test@example.com`, password=`password123`, role=Editor
2. Click "Create User"
3. **Expected**: Redirected to /admin/users with success flash "User created successfully."
4. **Verify**: "testuser" appears in the list with "Editor" badge
5. Log out, log in as `testuser`/`password123`
6. **Verify**: Login succeeds, dashboard loads

### W3. Create a new admin user
1. Fill in: username=`admin2`, email=`admin2@example.com`, password=`password123`, role=Admin
2. Click "Create User"
3. **Verify**: User appears with "Admin" badge
4. **Verify**: New admin can access /admin/users (not blocked by role check)

### W4. Edit existing user
1. From the user list, click "Edit" on a user
2. **Verify**: Form loads with existing username, email, and role pre-filled
3. **Verify**: Password field is empty (not pre-filled) and NOT required
4. **Verify**: Submit button reads "Save Changes"
5. **Verify**: Form has `_method=PUT` hidden input and CSRF token
6. Change the username and email, click "Save Changes"
7. **Expected**: Flash success "User updated successfully."
8. **Verify**: Changes are persisted (refresh to confirm)

### W5. Change user role
1. Edit an editor user, change role to "Admin"
2. Save — verify role badge updates to "Admin"
3. Edit same user, change role back to "Editor"
4. Save — verify role badge updates to "Editor"

### W6. Reset another user's password
1. Edit another user (not your own account)
2. Enter a new password in the password field (no current password needed)
3. Save — verify success
4. Log out, log in as that user with the new password — verify it works

### W7. Edit own account — role field disabled
1. Navigate to /admin/users/{own-id}/edit
2. **Verify**: Role field is disabled (shows current role as read-only text)
3. **Verify**: Hidden input preserves the current role value
4. **Verify**: Help text "You cannot change your own role." is shown

### W8. Change own password (requires current password)
1. Edit own account (/admin/users/{own-id}/edit)
2. **Verify**: "Current Password" field is shown
3. Enter new password WITHOUT entering current password
4. Save — **Expected**: Error "Current password is incorrect."
5. Enter correct current password + new password
6. Save — **Expected**: Success. Log out, log in with new password — works

### W9. Edit own username — session syncs
1. Edit own account, change username
2. Save — **Verify**: Sidebar immediately shows the new username (no re-login needed)

---

## Test Group X: User — Delete & Validation (Chunk 2.4)

### X1. Delete user without content
1. Create a user who has no content
2. Click "Delete" on the user list — modal appears
3. Select a reassignment target and confirm deletion
4. **Verify**: User removed from list, flash "User deleted."

### X2. Delete user with content — reassignment
1. Create a user and create content authored by them
2. Delete that user via the delete modal
3. **Verify**: Modal shows "Reassign their content to:" with a dropdown
4. Select admin as the reassignment target, confirm
5. **Verify**: User deleted. Content still exists with author changed to admin

### X3. Self-deletion prevented
1. As admin, try to delete your own account (the Delete button should be hidden)
2. Manually POST DELETE /admin/users/{own-id} (via curl or form manipulation)
3. **Expected**: Error flash "You cannot delete your own account." Redirect to /admin/users

### X4. Self-role-change prevented
1. Edit own account, try to submit with a changed role (via browser dev tools)
2. **Expected**: Error flash "You cannot change your own role."
3. **Verify**: Role unchanged in database

### X5. Validation — empty username rejected
1. Create form, leave username empty, submit
2. **Expected**: Error flash "Username is required." No user created

### X6. Validation — invalid username format
1. Create form, enter username with spaces or special chars (e.g., "test user!")
2. **Expected**: Error flash about invalid characters

### X7. Validation — duplicate username rejected
1. Try to create a user with username "admin"
2. **Expected**: Error flash "Username is already taken."

### X8. Validation — duplicate email rejected
1. Try to create a user with email "admin@localhost"
2. **Expected**: Error flash "Email is already in use."

### X9. Validation — invalid email rejected
1. Create form, enter email "not-an-email"
2. **Expected**: Error flash about invalid email

### X10. Validation — short password rejected
1. Create form, enter password with < 6 chars
2. **Expected**: Error flash "Password must be at least 6 characters."

---

## Test Group Y: User — Security & Role Enforcement (Chunk 2.4)

### Y1. Editor cannot access user management
1. Log in as an editor user
2. Navigate to /admin/users
3. **Expected**: 403 Forbidden ("You do not have permission to access this page.")
4. Try /admin/users/create — also 403
5. **Verify**: Editor CAN still access /admin/dashboard and /admin/content

### Y2. CSRF on user forms
1. Open create user form, view page source
2. **Verify**: `_csrf_token` hidden input is present
3. Open edit user form
4. **Verify**: `_csrf_token` and `_method=PUT` hidden inputs are present

### Y3. Security headers on user pages
1. Open browser DevTools > Network tab
2. Visit /admin/users
3. **Verify**: `X-Frame-Options: DENY` header is present
4. **Verify**: `Content-Security-Policy` header includes `default-src 'self'`

### Y4. XSS prevention
1. Create a user with username containing HTML (if bypassing client validation): `<script>alert('xss')</script>`
2. **Verify**: On the user list, the username is displayed as escaped text, NOT executed

### Y5. Dashboard users count updates
1. Create several users
2. Visit /admin/dashboard
3. **Verify**: "Users" stat card shows the correct count

---

## Test Group Z: Public Website — Homepage & Navigation (Chunk 3.1)

### Z1. Homepage shows recent published posts
1. Create several published posts via admin (with published_at in the past)
2. Open [http://localhost:8000/](http://localhost:8000/)
3. **Verify**: Recent posts are displayed with title, date, author, and excerpt
4. **Verify**: "Read more" links point to `/blog/{slug}`
5. **Verify**: Future-scheduled posts are NOT shown
6. **Verify**: Draft/archived posts are NOT shown

### Z2. Navigation includes Home, pages, and Blog
1. Create published pages "About" (sort_order=1) and "Services" (sort_order=2) via admin
2. Open any public page
3. **Verify**: Navigation shows: Home, About, Services, Blog — in that order
4. **Verify**: Draft and archived pages are NOT shown in navigation

### Z3. Navigation active state
1. Open [http://localhost:8000/about](http://localhost:8000/about)
2. **Verify**: "About" link in navigation has `class="active"`
3. Open [http://localhost:8000/](http://localhost:8000/)
4. **Verify**: "Home" link has `class="active"`
5. Open [http://localhost:8000/blog](http://localhost:8000/blog)
6. **Verify**: "Blog" link has `class="active"`

### Z4. Navigation sort order changes dynamically
1. Change "Services" sort_order to 0 (via admin content edit)
2. Refresh any public page
3. **Verify**: Services now appears before About in navigation

---

## Test Group AA: Public Website — Blog (Chunk 3.1)

### AA1. Blog index with pagination
1. Create 15 published posts
2. Open [http://localhost:8000/blog](http://localhost:8000/blog)
3. **Verify**: First 10 posts shown (items_per_page=10)
4. **Verify**: "Next" pagination link visible, "Previous" not visible
5. Open [http://localhost:8000/blog?page=2](http://localhost:8000/blog?page=2)
6. **Verify**: Remaining 5 posts shown, "Previous" link visible

### AA2. Blog post displays with OG tags
1. Create a published post with author
2. Open [http://localhost:8000/blog/{slug}](http://localhost:8000/blog/{slug})
3. **Verify**: Post title, body, author name, and publish date are displayed
4. **Verify**: Page source contains `og:type` with value `article`
5. **Verify**: Page source contains `article:author` and `article:published_time`

### AA3. Future-scheduled post returns 404
1. Create a post with status="published" and published_at set to tomorrow
2. Open [http://localhost:8000/blog/{slug}](http://localhost:8000/blog/{slug})
3. **Expected**: Styled 404 page (post not yet visible)
4. Change published_at to yesterday, refresh
5. **Expected**: Post is now visible (HTTP 200)

### AA4. Draft/archived post returns 404
1. Create a post with status="draft"
2. Open [http://localhost:8000/blog/{slug}](http://localhost:8000/blog/{slug})
3. **Expected**: 404 page
4. Repeat with status="archived" — also 404

---

## Test Group AB: Public Website — Pages & SEO (Chunk 3.1)

### AB1. Published page accessible by slug
1. Create a published page with slug "about" via admin
2. Open [http://localhost:8000/about](http://localhost:8000/about)
3. **Verify**: HTTP 200, page title and body content displayed
4. **Verify**: Page wrapped in public layout with navigation

### AB2. Page SEO meta tags
1. Create a published page with meta_title="Professional Services | MyBiz", meta_description="We offer services.", featured_image="/assets/uploads/test.jpg"
2. Open [http://localhost:8000/services](http://localhost:8000/services)
3. **Verify**: `<title>` contains "Professional Services | MyBiz"
4. **Verify**: `<meta name="description">` contains the meta_description
5. **Verify**: `<meta property="og:title">` contains the meta_title
6. **Verify**: `<meta property="og:type">` is "website"
7. **Verify**: `<link rel="canonical">` points to the correct URL
8. **Verify**: `<meta property="og:image">` references the featured image

### AB3. Post accessed via /{slug} redirects to /blog/{slug}
1. Create a published post with slug "my-post"
2. Open [http://localhost:8000/my-post](http://localhost:8000/my-post)
3. **Expected**: HTTP 301 redirect to [http://localhost:8000/blog/my-post](http://localhost:8000/blog/my-post)
4. **Verify**: Browser follows the redirect and loads the blog post

### AB4. Draft page returns 404
1. Create a page with status="draft"
2. Open [http://localhost:8000/{slug}](http://localhost:8000/{slug})
3. **Expected**: Styled 404 page

### AB5. Archived page returns 404
1. Create a page with status="archived"
2. Open [http://localhost:8000/{slug}](http://localhost:8000/{slug})
3. **Expected**: Styled 404 page

### AB6. Non-existent slug returns styled 404
1. Open [http://localhost:8000/nonexistent-page-xyz](http://localhost:8000/nonexistent-page-xyz)
2. **Verify**: HTTP 404 status
3. **Verify**: Styled 404 page with navigation and "Return to homepage" link
4. **Verify**: Page uses the same layout as other public pages

### AB7. Admin routes still work after public catch-all
1. Log in as admin
2. Open [http://localhost:8000/admin/dashboard](http://localhost:8000/admin/dashboard)
3. **Verify**: Dashboard loads correctly (catch-all route doesn't intercept admin routes)
4. Visit /admin/content, /admin/media, /admin/users
5. **Verify**: All admin sections work correctly

---

## Test Group AC: Public Stylesheet & Responsive Design (Chunk 3.2)

### AC1. Public CSS loads and styles the site
1. Open [http://localhost:8000/](http://localhost:8000/)
2. **Verify**: Page is styled (not raw HTML) — fonts, colors, spacing visible
3. **Verify**: Page source includes `<link rel="stylesheet" href="/assets/css/style.css">`
4. **Verify**: Header has sticky positioning (stays at top when scrolling)

### AC2. Homepage hero section
1. Open [http://localhost:8000/](http://localhost:8000/)
2. **Verify**: Hero section with centered text: "Welcome to LiteCMS"
3. **Verify**: Tagline paragraph below the heading
4. **Verify**: "Read Our Blog" CTA button (blue, links to /blog)
5. **Verify**: Recent posts displayed as styled post cards below the hero

### AC3. Mobile navigation hamburger
1. Resize browser to 375px width (or use DevTools responsive mode)
2. **Verify**: Nav links hidden, hamburger icon (three lines) appears
3. Click the hamburger button
4. **Verify**: Navigation slides open, `aria-expanded` changes to `true`
5. Click again — navigation closes

### AC4. Post cards responsive
1. On desktop — post cards show image on left, text on right (horizontal)
2. On mobile (375px) — post cards stack vertically (image on top, text below)

### AC5. Cookie consent banner appears
1. Clear all cookies for localhost
2. Open any public page
3. **Verify**: Cookie consent banner appears at bottom of page (fixed position, dark background)
4. **Verify**: Banner has "Accept" and "Decline" buttons
5. **Verify**: No `litecms_consent` cookie set yet

### AC6. Cookie consent — Accept
1. Click "Accept" on the banner
2. **Verify**: Banner disappears
3. **Verify**: `litecms_consent` cookie set to "accepted" (check via DevTools > Application > Cookies)
4. Refresh the page — banner stays hidden

### AC7. Cookie consent — Decline
1. Clear cookies, reload
2. Click "Decline" on the banner
3. **Verify**: Banner disappears
4. **Verify**: `litecms_consent` cookie set to "declined"
5. Refresh — banner stays hidden, no GA loaded

### AC8. Google Analytics conditional loading
1. Insert GA settings: `INSERT INTO settings (key, value) VALUES ('ga_enabled', '1'), ('ga_measurement_id', 'G-TESTID123');`
2. Clear cookies, visit homepage
3. **Verify**: `<body>` has `data-ga-id="G-TESTID123"` attribute
4. **Verify**: No `googletagmanager` script loaded yet (check Network tab)
5. Click "Accept" on consent banner
6. **Verify**: `googletagmanager.com/gtag/js?id=G-TESTID123` script loaded

---

## Test Group AD: Contact Page (Chunk 3.2)

### AD1. Contact form loads
1. Open [http://localhost:8000/contact](http://localhost:8000/contact)
2. **Verify**: Form with Name, Email, Subject, Message fields
3. **Verify**: CSRF token hidden input present (view source)
4. **Verify**: "Send Message" submit button
5. **Verify**: Navigation highlights "Contact" link
6. **Verify**: Page has SEO meta tags (title, canonical, og:type)

### AD2. Contact form — valid submission
1. Fill in: Name=`Jane Doe`, Email=`jane@example.com`, Subject=`Test`, Message=`Hello there`
2. Click "Send Message"
3. **Expected**: Redirected back to `/contact` (PRG pattern)
4. **Verify**: Success flash message: "Thank you for your message! We will get back to you soon."
5. **Verify**: Form fields are empty (not pre-filled with submitted values)
6. Run: `sqlite3 storage/database.sqlite "SELECT * FROM contact_submissions;"`
7. **Verify**: Row with name=Jane Doe, email=jane@example.com, message=Hello there

### AD3. Contact form — validation errors
1. Submit the form with empty name, invalid email, empty message
2. **Expected**: Form re-renders (not redirected) with error messages
3. **Verify**: Error text visible in a red box
4. **Verify**: Previously entered values preserved in form fields (`$old` values)

### AD4. Contact form — CSRF protection
1. Using curl: `curl -X POST http://localhost:8000/contact -d "name=Test&email=test@test.com&message=Hello"`
2. **Expected**: HTTP 403 — CSRF token missing

### AD5. Contact form — refresh doesn't resubmit
1. Submit a valid contact form
2. After the success redirect, press F5 to refresh
3. **Verify**: No duplicate submission (PRG pattern prevents it)

---

## Test Group AE: Archive & Navigation Enhancements (Chunk 3.2)

### AE1. Navigation includes Contact link
1. Open any public page
2. **Verify**: Navigation shows: Home, [pages], Blog, Contact — in that order
3. **Verify**: Contact link points to `/contact`

### AE2. Contact active state in navigation
1. Open [http://localhost:8000/contact](http://localhost:8000/contact)
2. **Verify**: "Contact" link has `class="active"` in the nav

### AE3. Home not highlighted on Contact page
1. Open /contact
2. **Verify**: "Home" link does NOT have `class="active"`

### AE4. Archive template exists
1. **Verify**: File `templates/public/archive.php` exists
2. **Verify**: Template references `archiveSlug`, `archiveTitle`, `totalPages` variables
3. **Note**: Archive routes not yet registered (deferred to Chunk 5.1)

### AE5. Migration created contact_submissions table
1. Run: `sqlite3 storage/database.sqlite ".tables"`
2. **Verify**: `contact_submissions` table exists
3. Run: `sqlite3 storage/database.sqlite "PRAGMA table_info(contact_submissions);"`
4. **Verify**: Columns: id, name, email, subject, message, ip_address, created_at

---

## Test Group AF: Settings Page — API Key & Model Management (Chunk 4.1)

### AF1. Settings page loads (admin-only)
1. Log in as admin
2. Open [http://localhost:8000/admin/settings](http://localhost:8000/admin/settings)
3. **Verify**: Page loads with "Settings" heading
4. **Verify**: Two sections visible: "AI Assistant" and "General"
5. **Verify**: Sidebar highlights "Settings" nav link
6. **Verify**: CSRF token hidden input present in the form
7. **Verify**: Form has `_method=PUT` hidden input

### AF2. API key stored encrypted
1. Enter a test API key (e.g., `sk-ant-test-12345`) in the Claude API Key field
2. Click "Save Settings"
3. **Verify**: Success flash message "Settings saved successfully."
4. Run: `sqlite3 storage/database.sqlite "SELECT value FROM settings WHERE key='claude_api_key';"`
5. **Verify**: Value is a base64-encoded string — NOT the plain text key
6. **Verify**: Decoding the value shows 16-byte IV prefix + ciphertext (AES-256-CBC)

### AF3. API key never displayed in browser
1. After saving an API key, reload /admin/settings
2. **Verify**: API key input field is empty (type="password")
3. **Verify**: Green status indicator shows "API key is configured (stored encrypted)"
4. **Verify**: View page source — no plain text API key anywhere in the HTML

### AF4. Model selection persists
1. Select "Claude Haiku 4.5 (Faster, lower cost)" from the model dropdown
2. Click "Save Settings"
3. Reload the page
4. **Verify**: "Claude Haiku 4.5" is still selected in the dropdown
5. Run: `sqlite3 storage/database.sqlite "SELECT value FROM settings WHERE key='claude_model';"`
6. **Verify**: Value is `claude-haiku-4-5-20251001`

### AF5. Site name setting persists
1. Change "Site Name" to "My Business Website"
2. Click "Save Settings"
3. Reload the page
4. **Verify**: Site Name field shows "My Business Website"

### AF6. Empty API key preserves existing
1. Save an API key first (AF2)
2. Leave the API key field blank and click "Save Settings"
3. **Verify**: Green indicator still shows "API key is configured" — existing key preserved
4. **Verify**: Database still has the encrypted key value

### AF7. Editor cannot access settings
1. Log in as an editor (non-admin) user
2. Navigate to /admin/settings
3. **Expected**: Redirected to /admin/dashboard with error flash "Only administrators can access settings."

---

## Test Group AG: AI Chat Endpoint (Chunk 4.1)

### AG1. AI chat endpoint returns response (requires valid API key)
1. Configure a valid Claude API key in /admin/settings
2. Using browser DevTools console or curl, send:
   ```bash
   curl -X POST http://localhost:8000/admin/ai/chat \
     -H "Content-Type: application/json" \
     -H "X-CSRF-Token: <token>" \
     -H "Cookie: PHPSESSID=<session>" \
     -d '{"message": "Write a short greeting"}'
   ```
3. **Verify**: HTTP 200 response with JSON:
   ```json
   {"success": true, "response": "...", "conversation_id": 1, "usage": {...}}
   ```
4. **Verify**: `response` contains a non-empty AI-generated reply

### AG2. Conversation persists in database
1. After AG1, check the database:
   ```bash
   sqlite3 storage/database.sqlite "SELECT messages_json FROM ai_conversations WHERE id=1;"
   ```
2. **Verify**: JSON array with 2 entries (user message + assistant response)
3. **Verify**: Each message has `role`, `content`, and `timestamp` fields

### AG3. Conversation history endpoint works
1. GET /admin/ai/conversations (while logged in)
2. **Verify**: JSON response with `success: true` and `conversations` array
3. **Verify**: Conversations include `id`, `content_id`, `messages`, `created_at`, `updated_at`

### AG4. Missing API key returns friendly error
1. Remove the API key from settings and config
2. POST to /admin/ai/chat with a message
3. **Expected**: HTTP 400 with JSON:
   ```json
   {"success": false, "error": "Claude API key is not configured. Please add your API key in Settings."}
   ```

### AG5. CSRF via X-CSRF-Token header works
1. POST to /admin/ai/chat without CSRF token
2. **Expected**: HTTP 403 (CSRF validation fails)
3. POST with `X-CSRF-Token` header containing a valid token
4. **Expected**: Request passes CSRF validation

### AG6. Conversation isolation between users
1. As user A, send a chat message for content_id=1
2. As user B, request conversations for content_id=1
3. **Verify**: User B does NOT see user A's conversation
4. **Verify**: Each user has separate conversation records

---

## Test Group AH: AI Chat Panel — Toggle & Layout (Chunk 4.2)

### AH1. AI toggle button visible on content editor
1. Log in as admin
2. Open [http://localhost:8000/admin/content/create](http://localhost:8000/admin/content/create)
3. **Verify**: "AI Assistant" button visible in the page header (next to "Back to Content")
4. **Verify**: Button has a star icon and "AI Assistant" text
5. Open an existing content item for editing
6. **Verify**: Toggle button also present on the edit page

### AH2. Toggle AI panel — opens as third column
1. On the content editor page, click "AI Assistant" button
2. **Verify**: AI panel slides in as a third column to the right of the sidebar
3. **Verify**: Editor layout adjusts to 3-column grid (main, sidebar, AI panel)
4. **Verify**: Panel shows empty state: star icon + "Ask the AI assistant..." placeholder text
5. **Verify**: `aria-expanded` changes to "true" on the toggle button

### AH3. Close AI panel
1. With the AI panel open, click the "×" button in the panel header
2. **Verify**: Panel closes, editor returns to 2-column layout
3. Open the panel again, click the "AI Assistant" toggle button
4. **Verify**: Panel closes (toggle behavior)
5. **Verify**: `aria-expanded` changes to "false"

---

## Test Group AI: AI Chat Panel — Messaging (Chunk 4.2)

### AI1. Send a message — user bubble appears
1. Open the AI panel
2. Type "Write a short welcome message" in the input
3. Press Enter (or click the send arrow button)
4. **Verify**: User message appears as a blue bubble on the right
5. **Verify**: Input field is cleared after sending

### AI2. AI response appears with action buttons
1. After sending a message (AI1), wait for response
2. **Verify**: Typing indicator (animated bouncing dots) appears while waiting
3. **Verify**: Send button is disabled during the request
4. **Verify**: AI response appears as a gray bubble on the left
5. **Verify**: Three buttons below the response: "Insert", "Replace", "Copy"

### AI3. Enter to send, Shift+Enter for newline
1. Focus the AI input textarea
2. Type some text and press Shift+Enter
3. **Verify**: A newline is inserted (message NOT sent)
4. Press Enter (without Shift)
5. **Verify**: Message is sent

### AI4. Double-send prevention
1. Type a message and press Enter
2. Immediately try to type and send another message
3. **Verify**: Second message is blocked while the first is loading (send button disabled)
4. After the first response arrives, send button re-enables

### AI5. Error message with API key link
1. Ensure no Claude API key is configured in Settings
2. Open the AI panel, type a message, and send
3. **Verify**: Red error bubble appears with message about API key
4. **Verify**: Error includes a clickable link to "/admin/settings"

### AI6. Network error handling
1. Disconnect from the network (or stop the server mid-request)
2. Try to send a message
3. **Verify**: Error bubble appears: "Network error. Please check your connection and try again."

---

## Test Group AJ: AI Chat Panel — Editor Integration (Chunk 4.2)

### AJ1. Insert button — inserts AI HTML into TinyMCE
1. Create or edit content, add some text in the TinyMCE editor
2. Open AI panel, send a message asking for content
3. When AI responds, click "Insert"
4. **Verify**: AI-generated HTML is inserted into TinyMCE at the cursor position
5. **Verify**: Existing editor content is preserved (not replaced)
6. **Verify**: "Insert" button briefly shows "Inserted!" feedback

### AJ2. Replace button — replaces TinyMCE content with confirm
1. Add text in TinyMCE editor
2. Get an AI response
3. Click "Replace"
4. **Verify**: Confirmation dialog appears: "Replace all editor content with this response?"
5. Click Cancel — **Verify**: Editor content unchanged
6. Click "Replace" again, click OK
7. **Verify**: TinyMCE editor content completely replaced with AI response

### AJ3. Copy button — copies plain text to clipboard
1. Get an AI response (which may contain HTML)
2. Click "Copy"
3. **Verify**: "Copy" button briefly shows "Copied!" feedback
4. Paste into a text editor
5. **Verify**: Plain text (HTML tags stripped) was copied

---

## Test Group AK: AI Chat Panel — Conversations (Chunk 4.2)

### AK1. New conversation button
1. Have an ongoing conversation with messages
2. Click "New" button in the AI panel header
3. **Verify**: Chat messages are cleared
4. **Verify**: System message "New conversation started." appears (centered, gray)
5. Send a new message
6. **Verify**: New conversation begins (no prior context from backend)

### AK2. Conversation history loads on edit page
1. Edit an existing content item (e.g., /admin/content/1/edit)
2. Open AI panel, send 2-3 messages
3. Navigate away to /admin/content
4. Navigate back to /admin/content/1/edit
5. Open AI panel
6. **Verify**: Previous conversation messages are loaded from the server

### AK3. New content has no conversation preload
1. Open [http://localhost:8000/admin/content/create](http://localhost:8000/admin/content/create)
2. Open the AI panel
3. **Verify**: Panel shows empty state (no conversation loaded)
4. **Verify**: `data-content-id` attribute on the form is empty

---

## Test Group AL: AI Chat Panel — Responsive (Chunk 4.2)

### AL1. Mobile — panel becomes full-screen overlay
1. Resize browser to <768px width
2. Open the AI panel
3. **Verify**: Panel opens as a full-screen overlay (position: fixed, covers entire viewport)
4. **Verify**: Close button (×) works to dismiss the overlay
5. **Verify**: Messages and input are fully usable in overlay mode

### AL2. Desktop — panel as third column
1. On desktop (>768px), open the AI panel
2. **Verify**: Panel appears alongside the editor and sidebar as a third column
3. **Verify**: TinyMCE editor area narrows to accommodate the panel

---

## Test Group AM: Content Type List — Display & CRUD (Chunk 5.1)

### AM1. Content types list loads (empty state)
1. Log in as admin (fresh database)
2. Open [http://localhost:8000/admin/content-types](http://localhost:8000/admin/content-types)
3. **Verify**: Page loads with "Content Types" heading and "+ New Content Type" button
4. **Verify**: Empty state message "No custom content types defined yet." with description and "Create your first content type" link
5. **Verify**: Sidebar highlights "Content Types" nav link

### AM2. Create a content type with custom fields
1. Click "+ New Content Type" or visit [http://localhost:8000/admin/content-types/create](http://localhost:8000/admin/content-types/create)
2. Enter Name: "Products"
3. **Verify**: Slug auto-generates as "products"
4. **Verify**: "Enable archive page" checkbox is checked by default
5. Click "+ Add Field" three times and configure:
   - Field 1: key=price, label=Price, type=Text, required=checked
   - Field 2: key=description, label=Description, type=Textarea
   - Field 3: key=featured, label=Featured Product, type=Boolean
6. Click "Create Content Type"
7. **Expected**: Redirected to edit page with flash success "Content type created successfully."
8. **Verify**: Fields are preserved on reload (field builder re-renders with saved data)

### AM3. Content type list shows items after creation
1. Open [http://localhost:8000/admin/content-types](http://localhost:8000/admin/content-types)
2. **Verify**: "Products" appears in the table with name, slug, "3 field(s)", "0 items", and archive "Yes"
3. **Verify**: Name links to the edit page
4. **Verify**: Edit and Delete buttons are present

### AM4. Edit content type
1. Click "Edit" on the Products content type
2. **Verify**: Form loads with existing name, slug, and fields pre-filled
3. Change the name to "Our Products"
4. Click "Update Content Type"
5. **Expected**: Flash success "Content type updated successfully."
6. **Verify**: Name change persisted

### AM5. Reserved slug validation
1. Create a new content type with slug "blog"
2. **Expected**: Error flash: "The slug 'blog' is reserved and cannot be used."
3. Try slug "admin" — same error
4. Try slug "page" — same error
5. Try slug "post" — same error

### AM6. Duplicate slug validation
1. Try creating another content type with slug "products"
2. **Expected**: Error flash: "A content type with this slug already exists."

### AM7. Delete content type — with content protection
1. Create some content items of type "products" (see Test Group AN)
2. Try deleting the Products content type
3. **Expected**: Error flash "Cannot delete — X content item(s) use this type. Delete or reassign them first."
4. Delete all products content items
5. Try deleting the Products type again
6. **Expected**: Type deleted successfully, no longer in the list

### AM8. Slug change cascades to content items
1. Create a content type "items" and create some content of that type
2. Edit the content type, change slug from "items" to "products"
3. Save — check the content items in /admin/content
4. **Verify**: Content items now show type "Products" (not "Items")

---

## Test Group AN: Custom Types in Content Editor (Chunk 5.1)

### AN1. Content editor type dropdown includes custom types
1. Create a "Products" content type
2. Open [http://localhost:8000/admin/content/create](http://localhost:8000/admin/content/create)
3. **Verify**: Type dropdown shows "Page", "Post", and "Products"
4. Open [http://localhost:8000/admin/content/create?type=products](http://localhost:8000/admin/content/create?type=products)
5. **Verify**: Type dropdown pre-selects "Products"

### AN2. Custom fields section appears for custom type
1. Open [http://localhost:8000/admin/content/create?type=products](http://localhost:8000/admin/content/create?type=products)
2. **Verify**: Below the excerpt field, a "Custom Fields" section appears
3. **Verify**: Price field (text input) with required asterisk
4. **Verify**: Description field (textarea)
5. **Verify**: Featured Product field (checkbox)

### AN3. Custom fields persist on create and edit
1. Create content with type=products: Title="Widget Pro", Price="29.99", Description="Great widget", Featured=checked
2. Click "Create"
3. **Expected**: Content created with flash success
4. Edit the content item
5. **Verify**: Custom fields populated with saved values (Price=29.99, Featured=checked)
6. Change Price to "39.99", uncheck Featured
7. Click "Update"
8. **Verify**: Updated values persist (Price=39.99, Featured unchecked)

### AN4. Content list filter includes custom types
1. Open [http://localhost:8000/admin/content](http://localhost:8000/admin/content)
2. **Verify**: Type filter dropdown shows "Products" in addition to "Page" and "Post"
3. Select "Products" filter, click "Filter"
4. **Verify**: Only products content items shown
5. **Verify**: Type badge shows "Products" (not "products")

### AN5. Select field type with options
1. Create or edit a content type with a select field: key=category, label=Category, type=Select
2. Enter options: Electronics, Clothing, Home (one per line)
3. Save the content type
4. Create content of this type
5. **Verify**: Category field renders as a dropdown with "— Select —", "Electronics", "Clothing", "Home"
6. Select "Clothing", save
7. **Verify**: Value persists on edit

### AN6. Image field type with media browser
1. Create a content type with an image field: key=photo, label=Photo, type=Image
2. Upload some images to the media library
3. Create content of this type
4. **Verify**: Photo field has "Browse Media" button and hidden input
5. Click "Browse Media"
6. **Verify**: Media browser opens (popup window)
7. Select an image
8. **Verify**: Preview appears, "Remove" button shows
9. Click "Remove" — preview hides

### AN7. Custom fields not shown for page/post types
1. Open [http://localhost:8000/admin/content/create?type=page](http://localhost:8000/admin/content/create?type=page)
2. **Verify**: No "Custom Fields" section appears
3. Open [http://localhost:8000/admin/content/create?type=post](http://localhost:8000/admin/content/create?type=post)
4. **Verify**: No "Custom Fields" section appears

---

## Test Group AO: Custom Type Public Routes (Chunk 5.1)

### AO1. Archive page renders for custom type
1. Create a "Products" content type with has_archive enabled
2. Create and publish a product ("Widget Pro" with slug "widget-pro")
3. Open [http://localhost:8000/products](http://localhost:8000/products)
4. **Verify**: Archive page renders with published products listed
5. **Verify**: Each item links to /products/{slug}

### AO2. Single custom type item renders
1. Open [http://localhost:8000/products/widget-pro](http://localhost:8000/products/widget-pro)
2. **Verify**: Page renders with content title and body
3. **Verify**: Page uses the public layout (header, nav, footer)

### AO3. Draft custom type items not shown
1. Create a product with status="draft"
2. Open [http://localhost:8000/products/{slug}](http://localhost:8000/products/{slug})
3. **Expected**: 404 page

### AO4. Archive not available when has_archive disabled
1. Edit the content type, uncheck "Enable archive page"
2. Save the content type
3. Open [http://localhost:8000/products](http://localhost:8000/products)
4. **Expected**: 404 or falls through to page slug handler (no archive route registered)

---

## Test Group AP: Content Type Field Builder (Chunk 5.1)

### AP1. Field builder — add fields
1. Open the create content type form
2. Click "+ Add Field"
3. **Verify**: A new field row appears with key, label, type, and required inputs
4. **Verify**: "No custom fields defined" message disappears

### AP2. Field builder — remove fields
1. Add two fields
2. Click the remove (×) button on the first field
3. **Verify**: First field removed, second field remains
4. Remove the last field
5. **Verify**: "No custom fields defined" message reappears

### AP3. Field builder — reorder fields
1. Add three fields: A, B, C
2. Click "Move down" (▼) on field A
3. **Verify**: Order changes to B, A, C
4. Click "Move up" (▲) on field C
5. **Verify**: Order changes to B, C, A

### AP4. Field builder — select type shows options
1. Add a field and change type to "Select"
2. **Verify**: Options textarea appears below the field
3. Change type back to "Text"
4. **Verify**: Options textarea hides

### AP5. Field builder — serialization on submit
1. Add fields, fill in key/label/type
2. Submit the form
3. **Verify**: Hidden `fields_json` input contains the serialized JSON (inspect via browser dev tools)
4. **Verify**: Saved content type has correct fields_json in the database

### AP6. Duplicate field key validation
1. Create a content type with two fields both having key="price"
2. **Expected**: Error flash about duplicate key
3. **Verify**: Content type not created

---

## Test Group AQ: Settings — General Section (Chunk 5.2)

### AQ1. Settings page loads with all sections
1. Log in as admin
2. Open [http://localhost:8000/admin/settings](http://localhost:8000/admin/settings)
3. **Verify**: Page loads with six sections: General, SEO, Cookie Consent & Analytics, Contact Form, AI Assistant, Advanced
4. **Verify**: Sidebar highlights "Settings" nav link
5. **Verify**: CSRF token and `_method=PUT` hidden inputs present

### AQ2. Change site name — public site reflects immediately
1. Change "Site Name" to "My Business Site"
2. Click "Save Settings"
3. **Verify**: Flash success "Settings saved successfully."
4. Open the public homepage
5. **Verify**: Page title and header show "My Business Site" (not "LiteCMS")
6. **Verify**: No change needed to `config/app.php` — the DB value takes priority

### AQ3. Site URL validates and saves
1. Enter "https://example.com" in Site URL field
2. Save — **Verify**: Value persists on reload
3. Enter "not-a-url" — save
4. **Verify**: Invalid URL is not saved (old value remains)
5. Clear the field and save — **Verify**: Empty is accepted

### AQ4. Tagline saves and displays
1. Enter a tagline in the Tagline field
2. Save — reload settings page
3. **Verify**: Tagline value persists
4. Open public homepage
5. **Verify**: Tagline appears in the hero section

### AQ5. Timezone select with optgroups
1. **Verify**: Timezone dropdown shows grouped timezones (America, Europe, etc.)
2. Select "America/New_York"
3. Save — reload
4. **Verify**: "America/New_York" is still selected

### AQ6. Items per page — pagination adjusts
1. Ensure at least 6 published blog posts exist
2. Change "Items Per Page" to 5, save
3. Open [http://localhost:8000/blog](http://localhost:8000/blog)
4. **Verify**: Only 5 posts shown on page 1, pagination shows page 2
5. Change back to 10 — all 6 posts on single page

### AQ7. Items per page clamped to 1–100
1. Enter 0 in Items Per Page, save — **Verify**: Value saved as 1
2. Enter 200, save — **Verify**: Value saved as 100
3. Enter 50, save — **Verify**: Value saved as 50

---

## Test Group AR: Settings — SEO Section (Chunk 5.2)

### AR1. Default meta description saves
1. Enter "A test description for SEO purposes." in Default Meta Description
2. Save — reload
3. **Verify**: Value persists (max 300 characters)

### AR2. Default OG image saves
1. Enter "/assets/uploads/og-image.jpg" in Default Open Graph Image
2. Save — reload
3. **Verify**: Value persists

---

## Test Group AS: Settings — Cookie Consent & Analytics (Chunk 5.2)

### AS1. Enable/disable cookie consent banner
1. **Verify**: "Enable cookie consent banner" is checked by default
2. Open public site — **Verify**: Cookie consent banner appears
3. Go to settings, uncheck "Enable cookie consent banner", save
4. Open public site — **Verify**: Cookie consent banner is NOT shown
5. Re-enable — banner reappears

### AS2. Cookie consent text and privacy link
1. Enter custom consent text: "We use cookies for analytics. Accept?"
2. Enter privacy link: "/privacy-policy"
3. Save, open incognito public page
4. **Verify**: Banner shows custom text and "Learn more" link

### AS3. Enable Google Analytics with measurement ID
1. Check "Enable Google Analytics"
2. Enter "G-TEST12345" as Measurement ID
3. Save, visit public site
4. **Verify**: `<body>` has `data-ga-id="G-TEST12345"` attribute
5. Click "Accept" on cookie banner
6. **Verify**: gtag.js script loaded

### AS4. Disable GA — no script injected
1. Uncheck "Enable Google Analytics" (leave Measurement ID filled)
2. Save, visit public site
3. **Verify**: No `data-ga-id` attribute on body, no GA script

### AS5. GA Measurement ID validation
1. Enter "UA-12345" (invalid format) — save
2. **Verify**: Invalid value not saved (old value remains)
3. Enter "G-VALID123" — save
4. **Verify**: Value persists

---

## Test Group AT: Settings — Contact & Advanced (Chunk 5.2)

### AT1. Contact notification email validates
1. Enter "admin@example.com" in Notification Email, save
2. **Verify**: Value persists on reload
3. Enter "not-an-email", save
4. **Verify**: Invalid value not saved
5. Clear field and save — **Verify**: Empty accepted (disables notifications)

### AT2. Registration enabled toggle
1. Check "Enable user registration", save
2. **Verify**: Checkbox stays checked on reload
3. Uncheck, save
4. **Verify**: Checkbox unchecked on reload

### AT3. Maintenance mode toggle
1. Check "Maintenance mode", save
2. **Verify**: Checkbox stays checked on reload
3. Uncheck, save
4. **Verify**: Checkbox unchecked on reload

---

## Test Group AU2: Settings — Persistence & Config Override (Chunk 5.2)

### AU2-1. Settings persist across logout/login
1. Change site name and items per page
2. Log out, log in again
3. Go to settings page
4. **Verify**: Changed values are still shown (loaded from DB, not session)

### AU2-2. DB settings override file config transparently
1. Set items_per_page to 25 via admin settings
2. **Verify**: Blog pagination uses 25 items per page
3. **Verify**: No change to `config/app.php` was needed

### AU2-3. Protected keys cannot be overridden
1. **Verify**: DB cannot override db_driver, db_path, db_host, db_port, db_name, db_user, db_pass, or app_secret
2. Even if someone manually inserts `db_driver=pgsql` in the settings table, `Config::getString('db_driver')` still returns `sqlite`

### AU2-4. AI settings preserved after 5.2 changes
1. Configure a Claude API key, select a model
2. Save settings
3. **Verify**: AI section still shows "API key is configured" status
4. **Verify**: Model dropdown, model management UI, and API parameters are all present and functional

---

## Test Group AV: Settings — Editor Access (Chunk 5.2)

### AV1. Editor cannot access settings
1. Log in as an editor user
2. Navigate to /admin/settings
3. **Expected**: Redirected to /admin/dashboard with error "Only administrators can access settings."

---

## Test Group AW: AI Page Generator — Setup & Navigation (Chunk 5.3)

### AW1. Generator page loads with content type selector
1. Log in as admin
2. Open [http://localhost:8000/admin/generator](http://localhost:8000/admin/generator)
3. **Verify**: Page loads with "Generate Page" heading and step indicator (1. Setup → 2. Describe → 3. Preview → 4. Done)
4. **Verify**: Content type buttons visible: "Page", "Blog Post", plus any custom types
5. **Verify**: Sidebar highlights "Generate Page" nav link
6. **Verify**: Step 1 (Setup) is active in the step indicator

### AW2. Sidebar shows "Generate Page" link
1. Log in as admin, visit any admin page
2. **Verify**: Sidebar Content section shows "Generate Page" with star icon (★)
3. Click the link
4. **Verify**: Navigates to /admin/generator with active nav highlight

### AW3. Custom content types appear in type selector
1. Create a custom content type (e.g., "Products") via /admin/content-types
2. Navigate to /admin/generator
3. **Verify**: "Products" button appears alongside "Page" and "Blog Post"

---

## Test Group AX: AI Page Generator — Chat & Gathering (Chunk 5.3)

### AX1. Select content type starts conversation
1. On the generator page, click "Page"
2. **Verify**: Step indicator advances to "2. Describe"
3. **Verify**: Chat interface appears with a user message and AI response
4. **Verify**: AI asks questions about the page's purpose and audience

### AX2. Chat conversation flows naturally
1. Continue from AX1, answer the AI's questions
2. **Verify**: AI responds with follow-up questions (2-3 per turn)
3. **Verify**: User messages appear as blue bubbles on the right
4. **Verify**: AI messages appear as gray bubbles on the left
5. **Verify**: Chat auto-scrolls to newest messages

### AX3. Enter to send, Shift+Enter for newline
1. In the chat input, type text and press Enter
2. **Verify**: Message is sent (not a newline inserted)
3. Type text and press Shift+Enter
4. **Verify**: Newline inserted (message NOT sent)

### AX4. Loading state during AI response
1. Send a message
2. **Verify**: "AI is thinking..." indicator appears
3. **Verify**: Send button is disabled
4. **Verify**: After response, indicator disappears and send button re-enables

### AX5. "Generate Page" button appears when AI is ready
1. Answer enough questions until the AI indicates it has sufficient info
2. **Verify**: "Generate Page" button appears in the chat (centered)
3. **Verify**: The READY_TO_GENERATE marker is NOT shown as visible text

### AX6. Missing API key shows helpful error
1. Remove the Claude API key from Settings
2. Navigate to /admin/generator, select a type
3. **Verify**: Error message in chat: "Claude API key is not configured. Please set it in Settings."

---

## Test Group AY: AI Page Generator — Preview & Create (Chunk 5.3)

### AY1. Preview shows generated content
1. After clicking "Generate Page" in the chat
2. **Verify**: Step indicator advances to "3. Preview"
3. **Verify**: Preview pane shows: Title, Slug, Excerpt, Meta Title, Meta Description
4. **Verify**: HTML body is rendered visually (not as raw code)
5. **Verify**: Three buttons visible: "Back to Chat", "Create as Draft", "Create & Publish"

### AY2. Preview shows custom fields for custom types
1. Create a "Products" content type with price, description, featured fields
2. Generate a product through the wizard
3. **Verify**: Preview shows "Custom Fields" section with generated values

### AY3. "Back to Chat" returns to gathering step
1. On the preview step, click "Back to Chat"
2. **Verify**: Returns to the chat interface (step 2) with previous messages preserved
3. **Verify**: Step indicator goes back to "2. Describe"

### AY4. "Create as Draft" creates draft content
1. On the preview step, click "Create as Draft"
2. **Verify**: Step indicator advances to "4. Done"
3. **Verify**: Success message with "Open in Editor" link
4. Navigate to /admin/content
5. **Verify**: New content item appears with status "draft"
6. Edit the item — **Verify**: all fields populated (title, slug, body, excerpt, meta fields)

### AY5. "Create & Publish" creates published content
1. Generate another page, click "Create & Publish"
2. **Verify**: Success screen with "Open in Editor" link
3. Navigate to /admin/content
4. **Verify**: Content appears with status "published" and published_at set
5. Visit the public URL (e.g., /about-us)
6. **Verify**: Page is accessible on the public site

### AY6. Success step actions
1. On the success step (step 4):
2. **Verify**: "Open in Editor" link navigates to /admin/content/{id}/edit
3. **Verify**: "Generate Another" link navigates to /admin/generator
4. **Verify**: "View All Content" link navigates to /admin/content

### AY7. Duplicate slug handled
1. Generate two pages with the same title
2. **Verify**: Second page gets a unique slug (e.g., "about-us-2")

### AY8. Custom fields saved to database
1. Generate a custom type content item (e.g., Products) with custom fields
2. Create as draft
3. Edit the item in /admin/content/{id}/edit
4. **Verify**: Custom field values match what was shown in the preview

---

## Test Group AZ: Page Builder — Editor Mode Toggle (Chunk 6.2)

### AZ1. Editor mode toggle visible on content editor
1. Log in as admin
2. Open [http://localhost:8000/admin/content/create](http://localhost:8000/admin/content/create)
3. **Verify**: Editor Mode toggle visible with "HTML Editor" and "Page Builder" radio buttons
4. **Verify**: "HTML Editor" is selected by default
5. **Verify**: Body textarea (TinyMCE) is visible
6. **Verify**: Page builder panel is hidden

### AZ2. Toggle to Page Builder mode
1. Click "Page Builder" radio button
2. **Verify**: Body textarea/TinyMCE is hidden
3. **Verify**: Page builder panel is visible with "Add Element" button and empty state message
4. **Verify**: Empty state shows "No elements added yet." with hint text

### AZ3. Toggle back to HTML mode
1. Click "HTML Editor" radio button
2. **Verify**: Body textarea/TinyMCE reappears
3. **Verify**: Page builder panel is hidden
4. **Verify**: No data is lost when switching modes

### AZ4. Editor mode persists on save
1. Create content in Page Builder mode, add an element, save
2. Edit the content
3. **Verify**: "Page Builder" radio is selected (not "HTML Editor")
4. **Verify**: Page builder panel is visible with saved elements

---

## Test Group BA: Page Builder — Element Picker (Chunk 6.2)

### BA1. Element picker opens with catalogue
1. Switch to Page Builder mode
2. Click "Add Element" button
3. **Verify**: Picker modal appears with all 7 seed elements
4. **Verify**: Search input is focused
5. **Verify**: Category tabs appear (All + unique categories from catalogue)

### BA2. Element picker — search filter
1. Type "hero" in the search input
2. **Verify**: Only Hero Section element is shown
3. Clear search — all elements shown again

### BA3. Element picker — category filter
1. Click a category tab (e.g., "content")
2. **Verify**: Only elements in that category are shown
3. Click "All" tab — all elements shown again

### BA4. Element picker — close
1. Click the × button in the picker header
2. **Verify**: Picker modal closes
3. Open again, click the dark overlay
4. **Verify**: Picker modal closes
5. **Verify**: No element was added in either case

---

## Test Group BB: Page Builder — Instance Cards & Slot Fields (Chunk 6.2)

### BB1. Adding an element creates instance card
1. In picker modal, click "Hero Section"
2. **Verify**: Modal closes
3. **Verify**: Instance card appears with header showing "Hero Section" name and category badge
4. **Verify**: Element count badge shows "1 element"
5. **Verify**: Slot fields are visible in the card body

### BB2. Slot fields render correctly for all types
1. Add a Hero Section element (has text, richtext, image, link, select)
2. **Verify**: Title slot → text input
3. **Verify**: Description slot → textarea
4. **Verify**: Background Image slot → hidden input + "Browse Media" button
5. **Verify**: CTA Button slot → three fields (URL, text, target select)
6. **Verify**: Text Alignment slot → select dropdown with options

### BB3. List slot type
1. Add a Feature Grid element (has list type)
2. **Verify**: Features list slot → container with "+ Add Item" button
3. Click "+ Add Item" → sub-slot fields appear (one item)
4. Click "+ Add Item" again → two items visible
5. Remove an item → one item remaining

### BB4. Collapsible instance cards
1. Add 2-3 elements
2. Click the collapse toggle (▼) on the first element
3. **Verify**: Slot fields are hidden (card collapsed)
4. Click the collapse toggle again (▲)
5. **Verify**: Slot fields are visible again

### BB5. Remove element
1. Click the remove button (×) on an element
2. **Verify**: Confirmation dialog appears
3. Confirm — element is removed from the list
4. **Verify**: Element count badge updates

### BB6. Image slot — media browser integration
1. Add an element with an image slot (e.g., Hero Section)
2. Click "Browse Media" on the image slot
3. **Verify**: Media browser modal opens (same as featured image)
4. Select an image
5. **Verify**: Image preview appears and hidden input is populated
6. Click "Remove" — preview hides and input clears

---

## Test Group BC: Page Builder — Drag & Drop Reorder (Chunk 6.2)

### BC1. Drag and drop reorders elements
1. Add Hero Section, Text Section, CTA Banner (3 elements)
2. **Verify**: Order is Hero, Text, CTA
3. Grab the drag handle (☰) on CTA Banner
4. Drag it to the top position (above Hero Section)
5. Drop it
6. **Verify**: Order changes to CTA, Hero, Text
7. **Verify**: Slot data is preserved during reorder

---

## Test Group BD: Page Builder — Save & Load (Chunk 6.2)

### BD1. Saving persists page_elements rows
1. Create new content, switch to Page Builder mode
2. Add Hero Section, fill in title="Welcome"
3. Add Text Section, fill in heading="About Us"
4. Click "Create"
5. **Verify**: Flash success "Content created successfully."
6. Run: `sqlite3 storage/database.sqlite "SELECT editor_mode FROM content WHERE slug='...'"`
7. **Verify**: editor_mode = 'elements'
8. Run: `sqlite3 storage/database.sqlite "SELECT * FROM page_elements WHERE content_id=..."`
9. **Verify**: 2 rows with sort_order 0 and 1

### BD2. Loading editor restores all element instances
1. Edit the content created in BD1
2. **Verify**: Page Builder mode is active (radio selected)
3. **Verify**: 2 instance cards shown: Hero Section, Text Section
4. **Verify**: Hero Section's title field has "Welcome"
5. **Verify**: Text Section's heading field has "About Us"
6. **Verify**: Element count badge shows "2 elements"

### BD3. Updating reorders and updates slot data
1. Edit content from BD1
2. Drag Text Section above Hero Section
3. Change Hero Section's title to "Updated Welcome"
4. Click "Update"
5. **Verify**: Refresh the page — order is Text, Hero; title is "Updated Welcome"

### BD4. HTML-mode content unaffected
1. Create a new content item in HTML mode (default)
2. Add title, body text via TinyMCE, publish
3. Save
4. **Verify**: editor_mode = 'html' in database
5. Edit — verify TinyMCE is visible, no page builder panel active
6. **Verify**: No page_elements rows exist for this content

### BD5. Element-based page renders on public site
1. Create content in Page Builder mode with Hero + Text sections
2. Fill in slot data, set status to published
3. Save
4. Visit the public page URL
5. **Verify**: Page renders with both elements
6. **Verify**: Elements have .lcms-el-{slug} wrapper classes

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
| K1 | All 7 navigation links present | ☐ |
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
| V1 | User list loads with default admin | ☐ |
| V2 | Search by username | ☐ |
| V3 | Search by email | ☐ |
| V4 | User list pagination | ☐ |
| V5 | Delete button hidden for own account | ☐ |
| W1 | Create user form loads | ☐ |
| W2 | Create a new editor user | ☐ |
| W3 | Create a new admin user | ☐ |
| W4 | Edit existing user | ☐ |
| W5 | Change user role | ☐ |
| W6 | Reset another user's password | ☐ |
| W7 | Edit own account — role disabled | ☐ |
| W8 | Change own password (requires current) | ☐ |
| W9 | Edit own username — session syncs | ☐ |
| X1 | Delete user without content | ☐ |
| X2 | Delete user with content — reassignment | ☐ |
| X3 | Self-deletion prevented | ☐ |
| X4 | Self-role-change prevented | ☐ |
| X5 | Empty username rejected | ☐ |
| X6 | Invalid username format rejected | ☐ |
| X7 | Duplicate username rejected | ☐ |
| X8 | Duplicate email rejected | ☐ |
| X9 | Invalid email rejected | ☐ |
| X10 | Short password rejected | ☐ |
| Y1 | Editor cannot access user management | ☐ |
| Y2 | CSRF on user forms | ☐ |
| Y3 | Security headers on user pages | ☐ |
| Y4 | XSS prevention | ☐ |
| Y5 | Dashboard users count updates | ☐ |
| Z1 | Homepage shows recent published posts | ☐ |
| Z2 | Navigation includes Home, pages, and Blog | ☐ |
| Z3 | Navigation active state | ☐ |
| Z4 | Navigation sort order changes dynamically | ☐ |
| AA1 | Blog index with pagination | ☐ |
| AA2 | Blog post displays with OG tags | ☐ |
| AA3 | Future-scheduled post returns 404 | ☐ |
| AA4 | Draft/archived post returns 404 | ☐ |
| AB1 | Published page accessible by slug | ☐ |
| AB2 | Page SEO meta tags | ☐ |
| AB3 | Post /{slug} redirects to /blog/{slug} | ☐ |
| AB4 | Draft page returns 404 | ☐ |
| AB5 | Archived page returns 404 | ☐ |
| AB6 | Non-existent slug returns styled 404 | ☐ |
| AB7 | Admin routes still work after catch-all | ☐ |
| AC1 | Public CSS loads and styles the site | ☐ |
| AC2 | Homepage hero section | ☐ |
| AC3 | Mobile navigation hamburger | ☐ |
| AC4 | Post cards responsive | ☐ |
| AC5 | Cookie consent banner appears | ☐ |
| AC6 | Cookie consent — Accept | ☐ |
| AC7 | Cookie consent — Decline | ☐ |
| AC8 | Google Analytics conditional loading | ☐ |
| AD1 | Contact form loads | ☐ |
| AD2 | Contact form — valid submission | ☐ |
| AD3 | Contact form — validation errors | ☐ |
| AD4 | Contact form — CSRF protection | ☐ |
| AD5 | Contact form — refresh doesn't resubmit | ☐ |
| AE1 | Navigation includes Contact link | ☐ |
| AE2 | Contact active state in navigation | ☐ |
| AE3 | Home not highlighted on Contact page | ☐ |
| AE4 | Archive template exists | ☐ |
| AE5 | Migration created contact_submissions table | ☐ |
| AF1 | Settings page loads (admin-only) | ☐ |
| AF2 | API key stored encrypted | ☐ |
| AF3 | API key never displayed in browser | ☐ |
| AF4 | Model selection persists | ☐ |
| AF5 | Site name setting persists | ☐ |
| AF6 | Empty API key preserves existing | ☐ |
| AF7 | Editor cannot access settings | ☐ |
| AG1 | AI chat endpoint returns response | ☐ |
| AG2 | Conversation persists in database | ☐ |
| AG3 | Conversation history endpoint works | ☐ |
| AG4 | Missing API key returns friendly error | ☐ |
| AG5 | CSRF via X-CSRF-Token header works | ☐ |
| AG6 | Conversation isolation between users | ☐ |
| AH1 | AI toggle button visible on editor | ☐ |
| AH2 | Toggle AI panel opens as third column | ☐ |
| AH3 | Close AI panel | ☐ |
| AI1 | Send message — user bubble appears | ☐ |
| AI2 | AI response with action buttons | ☐ |
| AI3 | Enter to send, Shift+Enter for newline | ☐ |
| AI4 | Double-send prevention | ☐ |
| AI5 | Error message with API key link | ☐ |
| AI6 | Network error handling | ☐ |
| AJ1 | Insert button inserts into TinyMCE | ☐ |
| AJ2 | Replace button with confirm dialog | ☐ |
| AJ3 | Copy button copies plain text | ☐ |
| AK1 | New conversation button | ☐ |
| AK2 | Conversation history loads on edit | ☐ |
| AK3 | New content has no conversation | ☐ |
| AL1 | Mobile — full-screen overlay | ☐ |
| AL2 | Desktop — third column | ☐ |
| AM1 | Content types list loads (empty state) | ☐ |
| AM2 | Create content type with custom fields | ☐ |
| AM3 | Content type list shows items | ☐ |
| AM4 | Edit content type | ☐ |
| AM5 | Reserved slug validation | ☐ |
| AM6 | Duplicate slug validation | ☐ |
| AM7 | Delete with content protection | ☐ |
| AM8 | Slug change cascades | ☐ |
| AN1 | Content editor type dropdown includes custom types | ☐ |
| AN2 | Custom fields section appears | ☐ |
| AN3 | Custom fields persist on create/edit | ☐ |
| AN4 | Content list filter includes custom types | ☐ |
| AN5 | Select field type with options | ☐ |
| AN6 | Image field type with media browser | ☐ |
| AN7 | No custom fields for page/post | ☐ |
| AO1 | Archive page renders for custom type | ☐ |
| AO2 | Single custom type item renders | ☐ |
| AO3 | Draft custom type items not shown | ☐ |
| AO4 | Archive disabled when has_archive off | ☐ |
| AP1 | Field builder — add fields | ☐ |
| AP2 | Field builder — remove fields | ☐ |
| AP3 | Field builder — reorder fields | ☐ |
| AP4 | Field builder — select shows options | ☐ |
| AP5 | Field builder — serialization | ☐ |
| AP6 | Duplicate field key validation | ☐ |
| AQ1 | Settings page loads with all sections | ☐ |
| AQ2 | Change site name — public site reflects | ☐ |
| AQ3 | Site URL validates and saves | ☐ |
| AQ4 | Tagline saves and displays | ☐ |
| AQ5 | Timezone select with optgroups | ☐ |
| AQ6 | Items per page — pagination adjusts | ☐ |
| AQ7 | Items per page clamped 1–100 | ☐ |
| AR1 | Default meta description saves | ☐ |
| AR2 | Default OG image saves | ☐ |
| AS1 | Enable/disable cookie consent banner | ☐ |
| AS2 | Cookie consent text and privacy link | ☐ |
| AS3 | Enable GA with measurement ID | ☐ |
| AS4 | Disable GA — no script injected | ☐ |
| AS5 | GA Measurement ID validation | ☐ |
| AT1 | Contact notification email validates | ☐ |
| AT2 | Registration enabled toggle | ☐ |
| AT3 | Maintenance mode toggle | ☐ |
| AU2-1 | Settings persist across logout/login | ☐ |
| AU2-2 | DB settings override file config | ☐ |
| AU2-3 | Protected keys cannot be overridden | ☐ |
| AU2-4 | AI settings preserved after 5.2 | ☐ |
| AV1 | Editor cannot access settings | ☐ |
| AW1 | Generator page loads with type selector | ☐ |
| AW2 | Sidebar shows Generate Page link | ☐ |
| AW3 | Custom content types in type selector | ☐ |
| AX1 | Select type starts conversation | ☐ |
| AX2 | Chat conversation flows naturally | ☐ |
| AX3 | Enter to send, Shift+Enter for newline | ☐ |
| AX4 | Loading state during AI response | ☐ |
| AX5 | Generate Page button appears when ready | ☐ |
| AX6 | Missing API key shows helpful error | ☐ |
| AY1 | Preview shows generated content | ☐ |
| AY2 | Preview shows custom fields | ☐ |
| AY3 | Back to Chat returns to gathering | ☐ |
| AY4 | Create as Draft creates draft content | ☐ |
| AY5 | Create & Publish creates published content | ☐ |
| AY6 | Success step actions work | ☐ |
| AY7 | Duplicate slug handled | ☐ |
| AY8 | Custom fields saved to database | ☐ |
| AZ1 | Editor mode toggle visible | ☐ |
| AZ2 | Toggle to Page Builder mode | ☐ |
| AZ3 | Toggle back to HTML mode | ☐ |
| AZ4 | Editor mode persists on save | ☐ |
| BA1 | Element picker opens with catalogue | ☐ |
| BA2 | Element picker — search filter | ☐ |
| BA3 | Element picker — category filter | ☐ |
| BA4 | Element picker — close | ☐ |
| BB1 | Adding element creates instance card | ☐ |
| BB2 | Slot fields render correctly | ☐ |
| BB3 | List slot type | ☐ |
| BB4 | Collapsible instance cards | ☐ |
| BB5 | Remove element | ☐ |
| BB6 | Image slot media browser | ☐ |
| BC1 | Drag and drop reorders elements | ☐ |
| BD1 | Saving persists page_elements | ☐ |
| BD2 | Loading restores element instances | ☐ |
| BD3 | Updating reorders and updates data | ☐ |
| BD4 | HTML-mode content unaffected | ☐ |
| BD5 | Element-based page renders publicly | ☐ |
