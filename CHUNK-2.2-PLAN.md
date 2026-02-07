# Chunk 2.2 — Content CRUD (Pages & Posts)
## Detailed Implementation Plan

---

## Overview

This chunk builds the full content management interface: listing pages/posts with search, filtering, and pagination; creating and editing content with TinyMCE WYSIWYG editor; slug auto-generation; draft/publish/archive workflow; SEO fields; and bulk actions. At completion, admins can create, list, search, filter, edit, and delete pages and blog posts.

---

## Prerequisites (Already Implemented)

From previous chunks, the following are available and must NOT be duplicated:

- **Core framework**: `App`, `Router`, `Request`, `Response`, `Config`, `Middleware`, `TemplateEngine`
- **Database layer**: `Connection`, `QueryBuilder`, `Migrator` — content table with all columns already exists
- **Auth system**: `Session`, `AuthMiddleware`, `CsrfMiddleware`, `RoleMiddleware` — sessions, CSRF, and login/logout working
- **Admin UI**: `DashboardController`, admin layout with sidebar, `admin.css` with cards/tables/badges/buttons/forms, `admin.js` with sidebar toggle + delete confirmations + flash auto-dismiss
- **Content table schema**: id, type, title, slug, body, excerpt, status, author_id, template, sort_order, meta_title, meta_description, featured_image, created_at, updated_at, published_at

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `app/Admin/ContentController.php` (new file)

**Purpose**: Full CRUD controller for content (pages and posts). Handles listing, creation, editing, deletion, and bulk actions.

**Class**: `App\Admin\ContentController`

**Dependencies**: `App\Core\App`, `App\Core\Request`, `App\Core\Response`, `App\Core\Config`, `App\Database\QueryBuilder`, `App\Auth\Session`

---

### 2. `templates/admin/content/index.php` (new file)

**Purpose**: Content listing template with search bar, type/status filter dropdowns, sortable table, pagination, and bulk action controls.

**Dependencies**: Admin layout (`templates/admin/layout.php`), existing CSS classes.

---

### 3. `templates/admin/content/edit.php` (new file)

**Purpose**: Content create/edit form template. Two-column layout: main area (title, slug, TinyMCE editor, excerpt) and sidebar (status, type, published_at, SEO fields, featured image).

**Dependencies**: Admin layout, `editor.js`.

---

### 4. `public/assets/js/editor.js` (new file)

**Purpose**: TinyMCE initialization from CDN, slug auto-generation from title, and published_at datetime helper.

**Dependencies**: TinyMCE CDN (loaded dynamically).

---

### 5. `public/assets/css/admin.css` (modify — append new styles)

**Purpose**: Add CSS for content-specific UI: filter bar, pagination, two-column editor layout, bulk action toolbar.

---

### 6. `public/index.php` (modify — replace placeholder routes)

**Purpose**: Replace the placeholder `/admin/content` route with full CRUD routes pointing to `ContentController`.

---

## Detailed Class Specification

### `App\Admin\ContentController`

```
NAMESPACE: App\Admin
USE STATEMENTS:
  App\Core\App
  App\Core\Config
  App\Core\Request
  App\Core\Response
  App\Database\QueryBuilder
  App\Auth\Session

PROPERTIES:
  - private App $app

CONSTRUCTOR:
  public function __construct(App $app)
    Stores $app as private property (same pattern as DashboardController).

METHODS:

  1. index(Request $request): Response
     Purpose: GET /admin/content — List content with search, filters, pagination.

     Logic:
       a. Read query params:
          - $type   = $request->query('type', '')     — 'page', 'post', or '' (all)
          - $status = $request->query('status', '')   — 'draft', 'published', 'archived', or '' (all)
          - $search = $request->query('q', '')         — search term
          - $page   = max(1, (int)$request->query('page', '1'))
          - $perPage = Config::getInt('items_per_page', 10)

       b. Build count query:
          $countQb = QueryBuilder::query('content')->select();
          if ($type)   $countQb->where('type', $type);
          if ($status) $countQb->where('status', $status);
          if ($search) $countQb->whereRaw('title LIKE :search', [':search' => "%{$search}%"]);
          $total = $countQb->count();

       c. Calculate pagination:
          $totalPages = max(1, (int)ceil($total / $perPage));
          $page = min($page, $totalPages);
          $offset = ($page - 1) * $perPage;

       d. Build data query:
          $qb = QueryBuilder::query('content')
              ->select('content.*', 'users.username as author_name')
              ->leftJoin('users', 'users.id', '=', 'content.author_id');
          if ($type)   $qb->where('content.type', $type);
          if ($status) $qb->where('content.status', $status);
          if ($search) $qb->whereRaw('content.title LIKE :search', [':search' => "%{$search}%"]);
          $items = $qb->orderBy('content.updated_at', 'DESC')
              ->limit($perPage)->offset($offset)->get();

       e. Render template with data:
          $html = $this->app->template()->render('admin/content/index', [
              'title'       => 'Content',
              'activeNav'   => 'content',
              'items'       => $items,
              'type'        => $type,
              'status'      => $status,
              'search'      => $search,
              'page'        => $page,
              'totalPages'  => $totalPages,
              'total'       => $total,
          ]);
          return Response::html($html)
              ->withHeader('X-Frame-Options', 'DENY')
              ->withHeader('Content-Security-Policy', "...");


  2. create(Request $request): Response
     Purpose: GET /admin/content/create — Show empty content editor form.

     Logic:
       a. Read optional type query param: $type = $request->query('type', 'page')
       b. Build an empty content record with defaults:
          $content = [
              'id'               => null,
              'type'             => $type,
              'title'            => '',
              'slug'             => '',
              'body'             => '',
              'excerpt'          => '',
              'status'           => 'draft',
              'meta_title'       => '',
              'meta_description' => '',
              'featured_image'   => '',
              'published_at'     => '',
              'sort_order'       => 0,
          ];
       c. Render edit template in "create" mode:
          $html = $this->app->template()->render('admin/content/edit', [
              'title'     => 'Create Content',
              'activeNav' => 'content',
              'content'   => $content,
              'isNew'     => true,
          ]);
          return Response::html($html) with security headers
            (CSP must allow TinyMCE CDN: script-src 'self' https://cdn.tiny.cloud;
             style-src 'self' 'unsafe-inline' https://cdn.tiny.cloud;
             img-src 'self' data: blob:; connect-src 'self' https://cdn.tiny.cloud)


  3. store(Request $request): Response
     Purpose: POST /admin/content — Validate and insert new content.

     Logic:
       a. Read all form fields from $request->input():
          $title           = trim((string) $request->input('title', ''))
          $slug            = trim((string) $request->input('slug', ''))
          $body            = (string) $request->input('body', '')  // HTML from TinyMCE — do NOT trim
          $excerpt         = trim((string) $request->input('excerpt', ''))
          $type            = (string) $request->input('type', 'page')
          $status          = (string) $request->input('status', 'draft')
          $metaTitle       = trim((string) $request->input('meta_title', ''))
          $metaDescription = trim((string) $request->input('meta_description', ''))
          $featuredImage   = trim((string) $request->input('featured_image', ''))
          $publishedAt     = trim((string) $request->input('published_at', ''))
          $sortOrder       = (int) $request->input('sort_order', '0')

       b. Validate:
          - title: required, max 255 chars
          - type: must be in ['page', 'post']
          - status: must be in ['draft', 'published', 'archived']
          If validation fails: Session::flash('error', ...), redirect back to /admin/content/create

       c. Generate slug if empty:
          $slug = $this->generateSlug($title, $slug);

       d. Ensure slug uniqueness:
          $slug = $this->ensureUniqueSlug($slug);

       e. Handle published_at:
          - If $status === 'published' and $publishedAt is empty, set to current datetime
          - If $publishedAt is not empty, validate format and store as-is

       f. Insert into database:
          $id = QueryBuilder::query('content')->insert([
              'type'             => $type,
              'title'            => $title,
              'slug'             => $slug,
              'body'             => $body,
              'excerpt'          => $excerpt,
              'status'           => $status,
              'author_id'        => (int)Session::get('user_id'),
              'sort_order'       => $sortOrder,
              'meta_title'       => $metaTitle ?: null,
              'meta_description' => $metaDescription ?: null,
              'featured_image'   => $featuredImage ?: null,
              'published_at'     => $publishedAt ?: null,
              'updated_at'       => date('Y-m-d H:i:s'),
          ]);

       g. Flash success and redirect:
          Session::flash('success', 'Content created successfully.');
          return Response::redirect('/admin/content/' . $id . '/edit');


  4. edit(Request $request, string $id): Response
     Purpose: GET /admin/content/{id}/edit — Show content editor form with existing data.

     Logic:
       a. Fetch content by ID:
          $content = QueryBuilder::query('content')->select()->where('id', (int)$id)->first();
       b. If not found: Session::flash('error', 'Content not found.'), redirect /admin/content
       c. Render edit template:
          $html = $this->app->template()->render('admin/content/edit', [
              'title'     => 'Edit: ' . $content['title'],
              'activeNav' => 'content',
              'content'   => $content,
              'isNew'     => false,
          ]);
          return Response::html($html) with security headers (including TinyMCE CDP domains)


  5. update(Request $request, string $id): Response
     Purpose: PUT /admin/content/{id} — Validate and update existing content.

     Logic:
       a. Fetch existing content — if not found, flash error, redirect.
       b. Read all form fields (same as store()).
       c. Validate (same as store()).
       d. Generate/update slug:
          $slug = $this->generateSlug($title, $slug);
          $slug = $this->ensureUniqueSlug($slug, (int)$id);  // exclude self from uniqueness check
       e. Handle published_at (same logic as store()).
       f. Update in database:
          QueryBuilder::query('content')->where('id', (int)$id)->update([
              'type'             => $type,
              'title'            => $title,
              'slug'             => $slug,
              'body'             => $body,
              'excerpt'          => $excerpt,
              'status'           => $status,
              'sort_order'       => $sortOrder,
              'meta_title'       => $metaTitle ?: null,
              'meta_description' => $metaDescription ?: null,
              'featured_image'   => $featuredImage ?: null,
              'published_at'     => $publishedAt ?: null,
              'updated_at'       => date('Y-m-d H:i:s'),
          ]);
       g. Flash success and redirect:
          Session::flash('success', 'Content updated successfully.');
          return Response::redirect('/admin/content/' . $id . '/edit');


  6. delete(Request $request, string $id): Response
     Purpose: DELETE /admin/content/{id} — Delete a single content item.

     Logic:
       a. Fetch content by ID — if not found, flash error, redirect.
       b. Delete: QueryBuilder::query('content')->where('id', (int)$id)->delete();
       c. Session::flash('success', 'Content deleted.');
       d. return Response::redirect('/admin/content');


  7. bulk(Request $request): Response
     Purpose: POST /admin/content/bulk — Handle bulk actions (delete, change status).

     Logic:
       a. Read bulk action: $action = $request->input('bulk_action', '')
       b. Read selected IDs: $ids = $request->input('ids', [])
          — These come as an array from checkbox inputs named "ids[]"
       c. Validate:
          - $ids must be a non-empty array
          - $action must be in ['delete', 'publish', 'draft', 'archive']
          If invalid: Session::flash('error', '...'), redirect back
       d. Cast IDs to int: $ids = array_map('intval', $ids);
       e. Execute action:
          if ($action === 'delete'):
              QueryBuilder::query('content')->where('id', 'IN', $ids)->delete();
              Session::flash('success', count($ids) . ' item(s) deleted.');
          else:
              Map action to status: 'publish' => 'published', 'draft' => 'draft', 'archive' => 'archived'
              QueryBuilder::query('content')->where('id', 'IN', $ids)->update([
                  'status' => $statusValue,
                  'updated_at' => date('Y-m-d H:i:s'),
              ]);
              Session::flash('success', count($ids) . ' item(s) updated.');
       f. return Response::redirect('/admin/content');


PRIVATE HELPER METHODS:

  generateSlug(string $title, string $manualSlug = ''): string
    Purpose: Convert title to URL-safe slug, or use manual slug if provided.
    Logic:
      a. If $manualSlug is not empty, use it as base; otherwise use $title.
      b. $slug = strtolower($base)
      c. Replace non-alphanumeric characters with hyphens: preg_replace('/[^a-z0-9]+/', '-', $slug)
      d. Trim leading/trailing hyphens: trim($slug, '-')
      e. Collapse multiple hyphens: preg_replace('/-+/', '-', $slug)
      f. If empty after processing, default to 'untitled'
      g. Return $slug

  ensureUniqueSlug(string $slug, ?int $excludeId = null): string
    Purpose: Check database for slug collisions and append a number if needed.
    Logic:
      a. $candidate = $slug
      b. $counter = 1
      c. Loop:
          $qb = QueryBuilder::query('content')->select()->where('slug', $candidate);
          if ($excludeId !== null) $qb->where('id', '!=', $excludeId);
          $exists = $qb->first();
          if ($exists === null) break;
          $counter++;
          $candidate = $slug . '-' . $counter;
      d. Return $candidate

  securityHeaders(Response $response): Response
    Purpose: Add standard security headers to admin responses.
    Logic:
      return $response
          ->withHeader('X-Frame-Options', 'DENY')
          ->withHeader('Content-Security-Policy',
              "default-src 'self'; "
              . "script-src 'self' https://cdn.tiny.cloud; "
              . "style-src 'self' 'unsafe-inline' https://cdn.tiny.cloud; "
              . "img-src 'self' data: blob:; "
              . "connect-src 'self' https://cdn.tiny.cloud; "
              . "font-src 'self' https://cdn.tiny.cloud"
          );
```

---

## Template Specifications

### `templates/admin/content/index.php`

**Layout**: Extends `admin/layout` (via `$this->layout('admin/layout')`)

**Data variables available** (from `extract($data)`):
- `$items` — array of content rows (with `author_name` joined)
- `$type` — current type filter (string)
- `$status` — current status filter (string)
- `$search` — current search term (string)
- `$page` — current page number (int)
- `$totalPages` — total pages (int)
- `$total` — total items count (int)

**Structure**:

```html
<!-- Page Header -->
<div class="page-header">
    <h1>Content</h1>
    <a href="/admin/content/create" class="btn btn-primary">+ New Content</a>
</div>

<!-- Filter Bar -->
<div class="content-filters card">
    <div class="card-body">
        <form method="GET" action="/admin/content" class="filter-form">
            <!-- Search input: name="q", value=$search -->
            <!-- Type dropdown: name="type" with options All/Page/Post -->
            <!-- Status dropdown: name="status" with options All/Draft/Published/Archived -->
            <!-- Submit button: "Filter" -->
            <!-- Reset link: href="/admin/content" -->
        </form>
    </div>
</div>

<!-- Bulk Actions Form wraps the table -->
<form method="POST" action="/admin/content/bulk" id="bulk-form">
    CSRF field: <?= $this->csrfField() ?>

    <!-- Bulk Action Bar (shown at top of card) -->
    <div class="card">
        <div class="card-header">
            <span>Showing $total item(s)</span>
            <div class="bulk-actions">
                <!-- Select: name="bulk_action" with options Delete/Publish/Draft/Archive -->
                <!-- Submit button: "Apply" with data-confirm -->
            </div>
        </div>

        <!-- Content Table (if items exist) -->
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all"></th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Author</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    For each $item:
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="$item['id']"></td>
                        <td>
                            <a href="/admin/content/{id}/edit"><strong>$title</strong></a>
                            <div class="text-muted" style="font-size:0.8rem;">/$slug</div>
                        </td>
                        <td><span class="badge">$type (ucfirst)</span></td>
                        <td><span class="badge badge-$status">$status (ucfirst)</span></td>
                        <td>$author_name</td>
                        <td class="text-muted">$updated_at</td>
                        <td>
                            <a href="/admin/content/{id}/edit" class="btn btn-sm">Edit</a>
                            <!-- Delete form (inline) -->
                            <form method="POST" action="/admin/content/{id}/delete" style="display:inline;">
                                CSRF + _method=DELETE
                                <button class="btn btn-sm btn-danger" data-confirm="Delete this item?">Delete</button>
                            </form>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Empty state if no items -->
        OR
        <div class="card-body"><div class="empty-state">...</div></div>
    </div>
</form>

<!-- Pagination -->
<div class="pagination">
    If $page > 1: « Prev link
    Page X of Y
    If $page < $totalPages: Next » link
    (Links preserve current filter params: type, status, q)
</div>
```

**Key template details**:
- All user strings escaped with `$this->e()`
- Pagination links build query strings preserving current filters
- Select-all checkbox toggles all row checkboxes (via editor.js or inline script)
- Delete buttons use `data-confirm` attribute (handled by existing admin.js)

---

### `templates/admin/content/edit.php`

**Layout**: Extends `admin/layout`

**Data variables**:
- `$content` — associative array with all content fields (empty defaults for new)
- `$isNew` — boolean (true = create mode, false = edit mode)

**Structure**:

```html
<!-- Page Header -->
<div class="page-header">
    <h1>Create Content / Edit: $title</h1>
    <a href="/admin/content" class="btn">← Back to Content</a>
</div>

<form method="POST"
      action="(if $isNew: /admin/content) (else: /admin/content/{id})"
      id="content-form">
    CSRF field
    If not $isNew: <input type="hidden" name="_method" value="PUT">

    <div class="editor-layout">
        <!-- Main Column -->
        <div class="editor-main">
            <!-- Title field -->
            <div class="form-group">
                <label for="title">Title *</label>
                <input type="text" id="title" name="title"
                       value="$content['title']" required maxlength="255">
            </div>

            <!-- Slug field (auto-generated, editable) -->
            <div class="form-group">
                <label for="slug">Slug</label>
                <div class="slug-field">
                    <span class="slug-prefix">/</span>
                    <input type="text" id="slug" name="slug"
                           value="$content['slug']" placeholder="auto-generated-from-title">
                </div>
            </div>

            <!-- Body field (TinyMCE target) -->
            <div class="form-group">
                <label for="body">Body</label>
                <textarea id="body" name="body" rows="20">$content['body']</textarea>
            </div>

            <!-- Excerpt field -->
            <div class="form-group">
                <label for="excerpt">Excerpt</label>
                <textarea id="excerpt" name="excerpt" rows="3"
                          placeholder="Brief summary for listings...">$content['excerpt']</textarea>
            </div>
        </div>

        <!-- Sidebar Column -->
        <div class="editor-sidebar">
            <!-- Publish Card -->
            <div class="card">
                <div class="card-header">Publish</div>
                <div class="card-body">
                    <!-- Type -->
                    <div class="form-group">
                        <label for="type">Type</label>
                        <select id="type" name="type">
                            <option value="page" (selected if 'page')>Page</option>
                            <option value="post" (selected if 'post')>Post</option>
                        </select>
                    </div>

                    <!-- Status -->
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>

                    <!-- Published At -->
                    <div class="form-group">
                        <label for="published_at">Publish Date</label>
                        <input type="datetime-local" id="published_at" name="published_at"
                               value="(formatted for datetime-local input)">
                    </div>

                    <!-- Sort Order -->
                    <div class="form-group">
                        <label for="sort_order">Sort Order</label>
                        <input type="number" id="sort_order" name="sort_order"
                               value="$content['sort_order']" min="0">
                    </div>

                    <!-- Submit buttons -->
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            (if $isNew: "Create") (else: "Update")
                        </button>
                    </div>
                </div>
            </div>

            <!-- SEO Card -->
            <div class="card">
                <div class="card-header">SEO</div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="meta_title">Meta Title</label>
                        <input type="text" id="meta_title" name="meta_title"
                               value="$content['meta_title']" maxlength="255">
                    </div>
                    <div class="form-group mb-0">
                        <label for="meta_description">Meta Description</label>
                        <textarea id="meta_description" name="meta_description"
                                  rows="3" maxlength="320">$content['meta_description']</textarea>
                    </div>
                </div>
            </div>

            <!-- Featured Image Card -->
            <div class="card">
                <div class="card-header">Featured Image</div>
                <div class="card-body">
                    <div class="form-group mb-0">
                        <label for="featured_image">Image URL</label>
                        <input type="text" id="featured_image" name="featured_image"
                               value="$content['featured_image']"
                               placeholder="(media browser in Chunk 2.3)">
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Load editor.js AFTER the form -->
<script src="/assets/js/editor.js"></script>
```

**Key template details**:
- Two-column layout: main (wide) + sidebar (narrow), stacking on mobile
- All output values escaped with `$this->e()` (NOTE: `$content['body']` is HTML for TinyMCE and must NOT be double-escaped — use raw output into the `<textarea>`, TinyMCE handles rendering)
- Actually, textarea content is displayed as text (not rendered as HTML), so `$this->e()` IS correct for the textarea value — the browser renders it as text inside `<textarea>`. TinyMCE reads the textarea content and interprets the HTML. So we must use `$this->e()` on the body value inside the textarea to prevent XSS in the raw HTML view, BUT this will also escape the HTML that TinyMCE needs to render. The correct approach: output the body **raw** inside the `<textarea>` tag, because textarea content is inherently treated as text by the browser (not rendered as HTML), AND TinyMCE needs the raw HTML to initialize correctly. **Do not escape body inside textarea.**
- `published_at` datetime value needs to be converted to `datetime-local` input format (`Y-m-d\TH:i`) if not empty

---

## JavaScript Specification

### `public/assets/js/editor.js`

```javascript
/**
 * LiteCMS Content Editor
 * - TinyMCE WYSIWYG initialization (CDN)
 * - Slug auto-generation from title
 * - Select-all checkbox for content list
 */
document.addEventListener('DOMContentLoaded', function() {

    // --- TinyMCE Initialization ---
    // Only runs if #body textarea exists (edit page, not index page)
    var bodyField = document.getElementById('body');
    if (bodyField) {
        // Load TinyMCE from CDN
        var script = document.createElement('script');
        script.src = 'https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js';
        script.referrerPolicy = 'origin';
        script.onload = function() {
            tinymce.init({
                selector: '#body',
                height: 500,
                menubar: true,
                plugins: [
                    'advlist', 'autolink', 'lists', 'link', 'image', 'charmap',
                    'preview', 'anchor', 'searchreplace', 'visualblocks', 'code',
                    'fullscreen', 'insertdatetime', 'media', 'table', 'help', 'wordcount'
                ],
                toolbar: 'undo redo | blocks | bold italic forecolor | '
                    + 'alignleft aligncenter alignright alignjust | '
                    + 'bullist numlist outdent indent | removeformat | code | help',
                content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, '
                    + '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; '
                    + 'font-size: 16px; line-height: 1.6; }',
                promotion: false,
                branding: false,
                // Sync TinyMCE content to textarea before form submit
                setup: function(editor) {
                    editor.on('change', function() {
                        editor.save();
                    });
                }
            });
        };
        document.head.appendChild(script);

        // Sync TinyMCE to textarea on form submit
        var form = document.getElementById('content-form');
        if (form) {
            form.addEventListener('submit', function() {
                if (typeof tinymce !== 'undefined') {
                    tinymce.triggerSave();
                }
            });
        }
    }

    // --- Slug Auto-Generation ---
    var titleField = document.getElementById('title');
    var slugField = document.getElementById('slug');
    // Track whether user has manually edited the slug
    var slugManuallyEdited = false;

    if (slugField) {
        // If slug already has a value (edit mode), mark as manually edited
        if (slugField.value.trim() !== '') {
            slugManuallyEdited = true;
        }

        // When user types in slug field, mark it as manually edited
        slugField.addEventListener('input', function() {
            slugManuallyEdited = true;
        });
    }

    if (titleField && slugField) {
        titleField.addEventListener('input', function() {
            if (!slugManuallyEdited) {
                slugField.value = generateSlug(titleField.value);
            }
        });
    }

    function generateSlug(text) {
        return text
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .replace(/-{2,}/g, '-');
    }

    // --- Select All Checkbox (Content Index Page) ---
    var selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('input[name="ids[]"]');
            checkboxes.forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
        });
    }
});
```

**Notes**:
- TinyMCE is loaded dynamically via script injection so it only loads on editor pages (not content list)
- The `no-api-key` path works for TinyMCE 6 community edition with a notification banner. For production, users can register for a free API key at tiny.cloud. This is acceptable per the spec (CDN load with fallback option)
- `editor.save()` on change syncs TinyMCE content to the underlying textarea, ensuring the form submits the latest content
- Slug generation only auto-fires when the slug hasn't been manually edited

---

## CSS Additions

Append the following to `public/assets/css/admin.css`:

```css
/* --- Content Filter Bar --- */
.filter-form {
    display: flex;
    gap: 0.75rem;
    align-items: flex-end;
    flex-wrap: wrap;
}

.filter-form .form-group {
    margin-bottom: 0;
    flex: 1;
    min-width: 150px;
}

.filter-form .form-group.search-group {
    flex: 2;
    min-width: 200px;
}

.filter-form .filter-buttons {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

/* --- Pagination --- */
.pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 1.5rem;
    font-size: 0.9rem;
}

.pagination a {
    padding: 0.4rem 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: 6px;
    text-decoration: none;
    color: var(--color-primary);
    transition: all var(--transition-fast);
}

.pagination a:hover {
    background: var(--color-primary);
    color: var(--color-white);
    border-color: var(--color-primary);
    text-decoration: none;
}

.pagination .page-info {
    color: var(--color-text-muted);
    padding: 0.4rem 0.5rem;
}

/* --- Editor Two-Column Layout --- */
.editor-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 1.5rem;
    align-items: start;
}

.editor-main .form-group textarea#body {
    min-height: 400px;
}

.editor-sidebar .card {
    margin-bottom: 1rem;
}

.editor-sidebar .form-actions {
    margin-top: 0.5rem;
}

/* Slug field with prefix */
.slug-field {
    display: flex;
    align-items: center;
    border: 1px solid var(--color-border);
    border-radius: 6px;
    overflow: hidden;
}

.slug-field .slug-prefix {
    padding: 0.5rem 0.5rem 0.5rem 0.75rem;
    background: var(--color-border-light);
    color: var(--color-text-muted);
    font-size: 0.9rem;
    border-right: 1px solid var(--color-border);
}

.slug-field input {
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}

/* Bulk action bar */
.bulk-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.bulk-actions select {
    padding: 0.3rem 0.5rem;
    border: 1px solid var(--color-border);
    border-radius: 6px;
    font-size: 0.85rem;
}

/* Inline delete forms */
td form[style*="display:inline"],
td form.inline-form {
    display: inline;
}

/* Table checkbox column */
table.data-table th:first-child,
table.data-table td:first-child {
    width: 40px;
    text-align: center;
}

/* --- Responsive: Editor --- */
@media (max-width: 768px) {
    .editor-layout {
        grid-template-columns: 1fr;
    }

    .filter-form {
        flex-direction: column;
    }

    .filter-form .form-group {
        min-width: 100%;
    }
}
```

---

## Route Registration Changes

### In `public/index.php`

**Add** `use App\Admin\ContentController;` to the import block (near line 17).

**Replace** the placeholder content route (lines 76-84):

```php
// OLD (remove):
$router->get('/content', function($request) use ($app) {
    return new Response(
        $app->template()->render('admin/placeholder', [
            'title' => 'Content',
            'activeNav' => 'content',
            'message' => 'Content management is coming in Chunk 2.2.',
        ])
    );
});

// NEW (add in its place):
$router->get('/content', [ContentController::class, 'index']);
$router->get('/content/create', [ContentController::class, 'create']);
$router->post('/content', [ContentController::class, 'store']);
$router->get('/content/{id}/edit', [ContentController::class, 'edit']);
$router->put('/content/{id}', [ContentController::class, 'update']);
$router->delete('/content/{id}', [ContentController::class, 'delete']);
$router->post('/content/bulk', [ContentController::class, 'bulk']);
```

**Important routing note**: The routes are inside the `/admin` group, so the actual URLs are `/admin/content`, `/admin/content/create`, etc. Route params `{id}` are passed as named string arguments via PHP 8.1's named argument unpacking (`...$params` where `$params` is `['id' => '42']`).

---

## Full Code Templates

### `app/Admin/ContentController.php`

```php
<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;

class ContentController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/content — List content with search, filters, and pagination.
     */
    public function index(Request $request): Response
    {
        $type    = (string) $request->query('type', '');
        $status  = (string) $request->query('status', '');
        $search  = (string) $request->query('q', '');
        $page    = max(1, (int) $request->query('page', '1'));
        $perPage = Config::getInt('items_per_page', 10);

        // Count query
        $countQb = QueryBuilder::query('content')->select();
        if ($type !== '')   $countQb->where('type', $type);
        if ($status !== '') $countQb->where('status', $status);
        if ($search !== '') $countQb->whereRaw('title LIKE :search', [':search' => "%{$search}%"]);
        $total = $countQb->count();

        // Pagination
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // Data query
        $qb = QueryBuilder::query('content')
            ->select('content.*', 'users.username as author_name')
            ->leftJoin('users', 'users.id', '=', 'content.author_id');
        if ($type !== '')   $qb->where('content.type', $type);
        if ($status !== '') $qb->where('content.status', $status);
        if ($search !== '') $qb->whereRaw('content.title LIKE :search', [':search' => "%{$search}%"]);
        $items = $qb->orderBy('content.updated_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $html = $this->app->template()->render('admin/content/index', [
            'title'      => 'Content',
            'activeNav'  => 'content',
            'items'      => $items,
            'type'       => $type,
            'status'     => $status,
            'search'     => $search,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * GET /admin/content/create — Show empty content editor.
     */
    public function create(Request $request): Response
    {
        $type = (string) $request->query('type', 'page');
        if (!in_array($type, ['page', 'post'], true)) {
            $type = 'page';
        }

        $content = [
            'id'               => null,
            'type'             => $type,
            'title'            => '',
            'slug'             => '',
            'body'             => '',
            'excerpt'          => '',
            'status'           => 'draft',
            'meta_title'       => '',
            'meta_description' => '',
            'featured_image'   => '',
            'published_at'     => '',
            'sort_order'       => 0,
        ];

        $html = $this->app->template()->render('admin/content/edit', [
            'title'     => 'Create Content',
            'activeNav' => 'content',
            'content'   => $content,
            'isNew'     => true,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * POST /admin/content — Validate and store new content.
     */
    public function store(Request $request): Response
    {
        $data = $this->readFormData($request);
        $error = $this->validate($data);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/content/create?type=' . urlencode($data['type']));
        }

        $data['slug'] = $this->generateSlug($data['title'], $data['slug']);
        $data['slug'] = $this->ensureUniqueSlug($data['slug']);
        $data['published_at'] = $this->resolvePublishedAt($data['status'], $data['published_at']);

        $id = QueryBuilder::query('content')->insert([
            'type'             => $data['type'],
            'title'            => $data['title'],
            'slug'             => $data['slug'],
            'body'             => $data['body'],
            'excerpt'          => $data['excerpt'],
            'status'           => $data['status'],
            'author_id'        => (int) Session::get('user_id'),
            'sort_order'       => $data['sort_order'],
            'meta_title'       => $data['meta_title'] ?: null,
            'meta_description' => $data['meta_description'] ?: null,
            'featured_image'   => $data['featured_image'] ?: null,
            'published_at'     => $data['published_at'] ?: null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        Session::flash('success', 'Content created successfully.');
        return Response::redirect('/admin/content/' . $id . '/edit');
    }

    /**
     * GET /admin/content/{id}/edit — Show content editor with existing data.
     */
    public function edit(Request $request, string $id): Response
    {
        $content = QueryBuilder::query('content')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($content === null) {
            Session::flash('error', 'Content not found.');
            return Response::redirect('/admin/content');
        }

        $html = $this->app->template()->render('admin/content/edit', [
            'title'     => 'Edit: ' . $content['title'],
            'activeNav' => 'content',
            'content'   => $content,
            'isNew'     => false,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * PUT /admin/content/{id} — Validate and update existing content.
     */
    public function update(Request $request, string $id): Response
    {
        $existing = QueryBuilder::query('content')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($existing === null) {
            Session::flash('error', 'Content not found.');
            return Response::redirect('/admin/content');
        }

        $data = $this->readFormData($request);
        $error = $this->validate($data);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/content/' . $id . '/edit');
        }

        $data['slug'] = $this->generateSlug($data['title'], $data['slug']);
        $data['slug'] = $this->ensureUniqueSlug($data['slug'], (int) $id);
        $data['published_at'] = $this->resolvePublishedAt($data['status'], $data['published_at']);

        QueryBuilder::query('content')->where('id', (int) $id)->update([
            'type'             => $data['type'],
            'title'            => $data['title'],
            'slug'             => $data['slug'],
            'body'             => $data['body'],
            'excerpt'          => $data['excerpt'],
            'status'           => $data['status'],
            'sort_order'       => $data['sort_order'],
            'meta_title'       => $data['meta_title'] ?: null,
            'meta_description' => $data['meta_description'] ?: null,
            'featured_image'   => $data['featured_image'] ?: null,
            'published_at'     => $data['published_at'] ?: null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        Session::flash('success', 'Content updated successfully.');
        return Response::redirect('/admin/content/' . $id . '/edit');
    }

    /**
     * DELETE /admin/content/{id} — Delete a single content item.
     */
    public function delete(Request $request, string $id): Response
    {
        $content = QueryBuilder::query('content')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($content === null) {
            Session::flash('error', 'Content not found.');
            return Response::redirect('/admin/content');
        }

        QueryBuilder::query('content')->where('id', (int) $id)->delete();

        Session::flash('success', 'Content deleted.');
        return Response::redirect('/admin/content');
    }

    /**
     * POST /admin/content/bulk — Handle bulk actions.
     */
    public function bulk(Request $request): Response
    {
        $action = (string) $request->input('bulk_action', '');
        $ids = $request->input('ids', []);

        if (!is_array($ids) || empty($ids)) {
            Session::flash('error', 'No items selected.');
            return Response::redirect('/admin/content');
        }

        if (!in_array($action, ['delete', 'publish', 'draft', 'archive'], true)) {
            Session::flash('error', 'Invalid action.');
            return Response::redirect('/admin/content');
        }

        $ids = array_map('intval', $ids);
        $count = count($ids);

        if ($action === 'delete') {
            QueryBuilder::query('content')->where('id', 'IN', $ids)->delete();
            Session::flash('success', $count . ' item(s) deleted.');
        } else {
            $statusMap = [
                'publish' => 'published',
                'draft'   => 'draft',
                'archive' => 'archived',
            ];
            QueryBuilder::query('content')->where('id', 'IN', $ids)->update([
                'status'     => $statusMap[$action],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Session::flash('success', $count . ' item(s) updated.');
        }

        return Response::redirect('/admin/content');
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    private function readFormData(Request $request): array
    {
        return [
            'title'            => trim((string) $request->input('title', '')),
            'slug'             => trim((string) $request->input('slug', '')),
            'body'             => (string) $request->input('body', ''),
            'excerpt'          => trim((string) $request->input('excerpt', '')),
            'type'             => (string) $request->input('type', 'page'),
            'status'           => (string) $request->input('status', 'draft'),
            'meta_title'       => trim((string) $request->input('meta_title', '')),
            'meta_description' => trim((string) $request->input('meta_description', '')),
            'featured_image'   => trim((string) $request->input('featured_image', '')),
            'published_at'     => trim((string) $request->input('published_at', '')),
            'sort_order'       => (int) $request->input('sort_order', '0'),
        ];
    }

    private function validate(array $data): ?string
    {
        if ($data['title'] === '') {
            return 'Title is required.';
        }
        if (mb_strlen($data['title']) > 255) {
            return 'Title must be 255 characters or less.';
        }
        if (!in_array($data['type'], ['page', 'post'], true)) {
            return 'Invalid content type.';
        }
        if (!in_array($data['status'], ['draft', 'published', 'archived'], true)) {
            return 'Invalid status.';
        }
        return null;
    }

    private function generateSlug(string $title, string $manualSlug = ''): string
    {
        $base = $manualSlug !== '' ? $manualSlug : $title;
        $slug = strtolower($base);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = preg_replace('/-+/', '-', $slug);
        return $slug !== '' ? $slug : 'untitled';
    }

    private function ensureUniqueSlug(string $slug, ?int $excludeId = null): string
    {
        $candidate = $slug;
        $counter = 1;

        while (true) {
            $qb = QueryBuilder::query('content')->select()->where('slug', $candidate);
            if ($excludeId !== null) {
                $qb->where('id', '!=', $excludeId);
            }
            if ($qb->first() === null) {
                break;
            }
            $counter++;
            $candidate = $slug . '-' . $counter;
        }

        return $candidate;
    }

    private function resolvePublishedAt(string $status, string $publishedAt): string
    {
        if ($publishedAt !== '') {
            // Convert datetime-local format (2026-02-07T14:30) to SQL format
            $publishedAt = str_replace('T', ' ', $publishedAt);
            if (strlen($publishedAt) === 16) {
                $publishedAt .= ':00';
            }
            return $publishedAt;
        }
        if ($status === 'published') {
            return date('Y-m-d H:i:s');
        }
        return '';
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self' https://cdn.tiny.cloud; "
                . "style-src 'self' 'unsafe-inline' https://cdn.tiny.cloud; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self' https://cdn.tiny.cloud; "
                . "font-src 'self' https://cdn.tiny.cloud"
            );
    }
}
```

---

### `templates/admin/content/index.php`

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Content</h1>
    <a href="/admin/content/create" class="btn btn-primary">+ New Content</a>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 1rem;">
    <div class="card-body">
        <form method="GET" action="/admin/content" class="filter-form">
            <div class="form-group search-group">
                <label for="q">Search</label>
                <input type="text" id="q" name="q"
                       value="<?= $this->e($search) ?>" placeholder="Search by title...">
            </div>
            <div class="form-group">
                <label for="filter-type">Type</label>
                <select id="filter-type" name="type">
                    <option value="">All Types</option>
                    <option value="page" <?= $type === 'page' ? 'selected' : '' ?>>Page</option>
                    <option value="post" <?= $type === 'post' ? 'selected' : '' ?>>Post</option>
                </select>
            </div>
            <div class="form-group">
                <label for="filter-status">Status</label>
                <select id="filter-status" name="status">
                    <option value="">All Statuses</option>
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published</option>
                    <option value="archived" <?= $status === 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="/admin/content" class="btn btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Content Table with Bulk Actions -->
<form method="POST" action="/admin/content/bulk" id="bulk-form">
    <?= $this->csrfField() ?>

    <div class="card">
        <div class="card-header">
            <span><?= (int)$total ?> item(s)</span>
            <div class="bulk-actions">
                <select name="bulk_action">
                    <option value="">Bulk Actions</option>
                    <option value="publish">Set Published</option>
                    <option value="draft">Set Draft</option>
                    <option value="archive">Set Archived</option>
                    <option value="delete">Delete</option>
                </select>
                <button type="submit" class="btn btn-sm"
                        data-confirm="Apply this action to all selected items?">Apply</button>
            </div>
        </div>

        <?php if (empty($items)): ?>
            <div class="card-body">
                <div class="empty-state">
                    <p>No content found.</p>
                    <a href="/admin/content/create" class="btn btn-primary">Create your first page</a>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all"></th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Author</th>
                            <th>Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="ids[]"
                                           value="<?= (int)$item['id'] ?>">
                                </td>
                                <td>
                                    <a href="/admin/content/<?= (int)$item['id'] ?>/edit">
                                        <strong><?= $this->e($item['title']) ?></strong>
                                    </a>
                                    <div class="text-muted" style="font-size:0.8rem;">
                                        /<?= $this->e($item['slug']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge">
                                        <?= $this->e(ucfirst($item['type'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $this->e($item['status']) ?>">
                                        <?= $this->e(ucfirst($item['status'])) ?>
                                    </span>
                                </td>
                                <td><?= $this->e($item['author_name'] ?? 'Unknown') ?></td>
                                <td class="text-muted">
                                    <?= $this->e($item['updated_at'] ?? '') ?>
                                </td>
                                <td>
                                    <a href="/admin/content/<?= (int)$item['id'] ?>/edit"
                                       class="btn btn-sm">Edit</a>
                                    <form method="POST"
                                          action="/admin/content/<?= (int)$item['id'] ?>/delete"
                                          style="display:inline;">
                                        <?= $this->csrfField() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                data-confirm="Are you sure you want to delete this content?">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</form>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        // Build query string preserving filters
        $queryParams = [];
        if ($type !== '') $queryParams['type'] = $type;
        if ($status !== '') $queryParams['status'] = $status;
        if ($search !== '') $queryParams['q'] = $search;
        ?>

        <?php if ($page > 1): ?>
            <?php $queryParams['page'] = $page - 1; ?>
            <a href="/admin/content?<?= http_build_query($queryParams) ?>">« Prev</a>
        <?php endif; ?>

        <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>

        <?php if ($page < $totalPages): ?>
            <?php $queryParams['page'] = $page + 1; ?>
            <a href="/admin/content?<?= http_build_query($queryParams) ?>">Next »</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script src="/assets/js/editor.js"></script>
```

---

### `templates/admin/content/edit.php`

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $isNew ? 'Create Content' : 'Edit: ' . $this->e($content['title']) ?></h1>
    <a href="/admin/content" class="btn">← Back to Content</a>
</div>

<form method="POST"
      action="<?= $isNew ? '/admin/content' : '/admin/content/' . (int)$content['id'] ?>"
      id="content-form">
    <?= $this->csrfField() ?>
    <?php if (!$isNew): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>

    <div class="editor-layout">
        <!-- Main Column -->
        <div class="editor-main">
            <div class="form-group">
                <label for="title">Title <span style="color:var(--color-error);">*</span></label>
                <input type="text" id="title" name="title"
                       value="<?= $this->e($content['title']) ?>"
                       required maxlength="255"
                       placeholder="Enter title...">
            </div>

            <div class="form-group">
                <label for="slug">Slug</label>
                <div class="slug-field">
                    <span class="slug-prefix">/</span>
                    <input type="text" id="slug" name="slug"
                           value="<?= $this->e($content['slug']) ?>"
                           placeholder="auto-generated-from-title">
                </div>
            </div>

            <div class="form-group">
                <label for="body">Body</label>
                <textarea id="body" name="body" rows="20"><?= $content['body'] ?></textarea>
            </div>

            <div class="form-group">
                <label for="excerpt">Excerpt</label>
                <textarea id="excerpt" name="excerpt" rows="3"
                          placeholder="Brief summary for listings..."><?= $this->e($content['excerpt'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Sidebar Column -->
        <div class="editor-sidebar">
            <!-- Publish Card -->
            <div class="card">
                <div class="card-header">Publish</div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="type">Type</label>
                        <select id="type" name="type">
                            <option value="page" <?= ($content['type'] ?? 'page') === 'page' ? 'selected' : '' ?>>Page</option>
                            <option value="post" <?= ($content['type'] ?? '') === 'post' ? 'selected' : '' ?>>Post</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="draft" <?= ($content['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Draft</option>
                            <option value="published" <?= ($content['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option>
                            <option value="archived" <?= ($content['status'] ?? '') === 'archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="published_at">Publish Date</label>
                        <?php
                        $pubAt = $content['published_at'] ?? '';
                        $pubAtValue = '';
                        if ($pubAt !== '' && $pubAt !== null) {
                            $ts = strtotime($pubAt);
                            if ($ts !== false) {
                                $pubAtValue = date('Y-m-d\TH:i', $ts);
                            }
                        }
                        ?>
                        <input type="datetime-local" id="published_at" name="published_at"
                               value="<?= $this->e($pubAtValue) ?>">
                    </div>

                    <div class="form-group">
                        <label for="sort_order">Sort Order</label>
                        <input type="number" id="sort_order" name="sort_order"
                               value="<?= (int)($content['sort_order'] ?? 0) ?>" min="0">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" style="width:100%;">
                            <?= $isNew ? 'Create' : 'Update' ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- SEO Card -->
            <div class="card">
                <div class="card-header">SEO</div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="meta_title">Meta Title</label>
                        <input type="text" id="meta_title" name="meta_title"
                               value="<?= $this->e($content['meta_title'] ?? '') ?>"
                               maxlength="255"
                               placeholder="Custom title for search engines">
                    </div>
                    <div class="form-group mb-0">
                        <label for="meta_description">Meta Description</label>
                        <textarea id="meta_description" name="meta_description"
                                  rows="3" maxlength="320"
                                  placeholder="Brief description for search results..."><?= $this->e($content['meta_description'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Featured Image Card -->
            <div class="card">
                <div class="card-header">Featured Image</div>
                <div class="card-body">
                    <div class="form-group mb-0">
                        <label for="featured_image">Image URL</label>
                        <input type="text" id="featured_image" name="featured_image"
                               value="<?= $this->e($content['featured_image'] ?? '') ?>"
                               placeholder="Enter image URL or path">
                        <small class="text-muted mt-1" style="display:block;">
                            Media browser coming in a future update.
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script src="/assets/js/editor.js"></script>
```

---

### `public/assets/js/editor.js`

```javascript
/**
 * LiteCMS Content Editor
 * Handles: TinyMCE WYSIWYG, slug auto-generation, select-all checkbox
 */
document.addEventListener('DOMContentLoaded', function() {

    // --- TinyMCE Initialization ---
    var bodyField = document.getElementById('body');
    if (bodyField) {
        var script = document.createElement('script');
        script.src = 'https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js';
        script.referrerPolicy = 'origin';
        script.onload = function() {
            tinymce.init({
                selector: '#body',
                height: 500,
                menubar: true,
                plugins: [
                    'advlist', 'autolink', 'lists', 'link', 'image', 'charmap',
                    'preview', 'anchor', 'searchreplace', 'visualblocks', 'code',
                    'fullscreen', 'insertdatetime', 'media', 'table', 'help', 'wordcount'
                ],
                toolbar: 'undo redo | blocks | bold italic forecolor | '
                    + 'alignleft aligncenter alignright alignjust | '
                    + 'bullist numlist outdent indent | removeformat | code | help',
                content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, '
                    + '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; '
                    + 'font-size: 16px; line-height: 1.6; max-width: 100%; }',
                promotion: false,
                branding: false,
                setup: function(editor) {
                    editor.on('change', function() {
                        editor.save();
                    });
                }
            });
        };
        document.head.appendChild(script);

        // Sync TinyMCE to textarea on form submit
        var form = document.getElementById('content-form');
        if (form) {
            form.addEventListener('submit', function() {
                if (typeof tinymce !== 'undefined') {
                    tinymce.triggerSave();
                }
            });
        }
    }

    // --- Slug Auto-Generation ---
    var titleField = document.getElementById('title');
    var slugField = document.getElementById('slug');
    var slugManuallyEdited = false;

    if (slugField && slugField.value.trim() !== '') {
        slugManuallyEdited = true;
    }

    if (slugField) {
        slugField.addEventListener('input', function() {
            slugManuallyEdited = true;
        });
    }

    if (titleField && slugField) {
        titleField.addEventListener('input', function() {
            if (!slugManuallyEdited) {
                slugField.value = generateSlug(titleField.value);
            }
        });
    }

    function generateSlug(text) {
        return text
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .replace(/-{2,}/g, '-');
    }

    // --- Select All Checkbox ---
    var selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('input[name="ids[]"]');
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = selectAll.checked;
            }
        });
    }
});
```

---

## Route Registration (Changes to `public/index.php`)

**Add import** (after line 17):
```php
use App\Admin\ContentController;
```

**Replace lines 76-84** (the placeholder `/content` route) with:
```php
$router->get('/content', [ContentController::class, 'index']);
$router->get('/content/create', [ContentController::class, 'create']);
$router->post('/content', [ContentController::class, 'store']);
$router->get('/content/{id}/edit', [ContentController::class, 'edit']);
$router->put('/content/{id}', [ContentController::class, 'update']);
$router->delete('/content/{id}', [ContentController::class, 'delete']);
$router->post('/content/bulk', [ContentController::class, 'bulk']);
```

**Note**: These routes are inside the `/admin` group, so actual paths are `/admin/content`, `/admin/content/create`, etc.

**Note on PUT/DELETE routes**: HTML forms cannot natively send PUT/DELETE. The `Request` class already supports method override via `_method` hidden field. However, the Router registers `put()` and `delete()` routes, and the Request class reports the overridden method. The forms in the templates include `<input type="hidden" name="_method" value="PUT">` (or DELETE) and use `method="POST"`. The Router will match because `Request::method()` returns `PUT` or `DELETE` when `_method` is set.

---

## Acceptance Test Procedures

### Test 1: Create a new page and publish it
```
1. Login as admin, navigate to /admin/content
2. Click "+ New Content"
3. Enter title: "About Us"
4. Verify slug auto-generates to "about-us"
5. Enter body text via TinyMCE editor
6. Set Type to "Page", Status to "Published"
7. Click "Create"
8. Verify: redirected to edit page, flash success message shown
9. Navigate to /admin/content — "About Us" appears in list with status "Published"
```

### Test 2: Slug auto-generation and manual override
```
1. Create a new content item
2. Type title "My Test Page" — verify slug field shows "my-test-page"
3. Clear the slug field, type "custom-slug" — slug stays "custom-slug" even if title changes
4. Submit — verify slug in database is "custom-slug"
5. Create another item with same title "My Test Page" — verify slug is "my-test-page-2" (uniqueness)
```

### Test 3: Filter by type
```
1. Create a page and a post
2. Go to /admin/content — both appear
3. Select type filter "Page" and click Filter — only the page appears
4. Select type filter "Post" — only the post appears
5. Click Reset — both appear again
```

### Test 4: Search by title
```
1. With multiple content items, type a partial title in the search box
2. Click Filter — only matching items appear
3. Verify the search term is preserved in the input field
```

### Test 5: Change status via edit form
```
1. Create a published page
2. Edit it, change status to "Archived"
3. Save — verify status is "archived" in the database
4. Content list shows "Archived" badge
```

### Test 6: Bulk delete
```
1. Create 3 content items
2. Go to content list, select checkboxes for all 3
3. Choose "Delete" from bulk actions dropdown
4. Click Apply, confirm the dialog
5. Verify: all 3 items removed from list, flash success shows "3 item(s) deleted."
```

### Test 7: Scheduled publishing
```
1. Create a post with status "Published" and published_at set to a future date
2. Verify published_at is stored correctly in the database
3. Verify it appears in the content list
```

### Test 8: Edit existing content
```
1. Edit an existing content item
2. Modify the title and body
3. Save — verify changes persisted
4. Slug was not changed (since it was manually set/existing)
```

### Test 9: Delete single item
```
1. Click "Delete" on a content item in the list
2. Confirm the dialog
3. Verify: item removed, redirected to list, flash success shown
```

### Test 10: Pagination
```
1. Create 15+ content items (with items_per_page = 10 in config)
2. Content list shows first 10 items
3. "Next »" link appears — click it — page 2 shows remaining items
4. "« Prev" link appears on page 2
5. Filters are preserved across pagination
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);`
- PSR-4 namespacing: `App\Admin\ContentController` → `app/Admin/ContentController.php`
- No framework imports — native PHP only
- All DB queries use `QueryBuilder` with parameterized queries (no raw SQL injection risk)
- All template output escaped with `$this->e()` except body inside `<textarea>` (see note below)

### Body Field — HTML and XSS
The body field contains HTML from TinyMCE. Inside a `<textarea>` tag, the browser treats content as text (not rendered HTML), so there's no XSS risk from raw HTML in the textarea. However, the body IS rendered as raw HTML on the public site (in future chunks). The body is stored as-is in the database. XSS prevention on the public site is handled by the template engine's `e()` method for non-body fields, and the body HTML is intentionally rendered raw (it comes from the WYSIWYG editor, which is a trusted admin input).

### Route Parameter Passing
Route params like `{id}` are extracted as an associative array `['id' => '42']` by the Router. In `App::run()`, they're unpacked via `...$params`, which in PHP 8.1+ uses named arguments. The controller method signature `edit(Request $request, string $id)` receives the `id` parameter by name. The value is always a string (from URL regex capture).

### TinyMCE CDN and "no-api-key"
Using `no-api-key` in the TinyMCE CDN URL provides full community edition functionality but shows a small notification banner. This is the correct approach per the spec ("CDN load with fallback option"). Users who want to remove the banner can register for a free API key at tiny.cloud and update the script URL. A future enhancement could make this configurable via settings.

### Content Security Policy (CSP)
The CSP header on content editor pages must allow TinyMCE CDN domains:
- `script-src 'self' https://cdn.tiny.cloud` — for TinyMCE JS
- `style-src 'self' 'unsafe-inline' https://cdn.tiny.cloud` — for TinyMCE CSS and inline styles
- `img-src 'self' data: blob:` — for TinyMCE image handling
- `connect-src 'self' https://cdn.tiny.cloud` — for TinyMCE API calls
- `font-src 'self' https://cdn.tiny.cloud` — for TinyMCE fonts

The `DashboardController` has a stricter CSP without CDN allowances — this is correct since TinyMCE is only needed on the editor page.

### File Counts and LOC Estimate
- **New PHP files**: 1 (`ContentController.php`) — ~230 lines
- **New templates**: 2 (`content/index.php`, `content/edit.php`) — ~110 + ~110 lines
- **New JS**: 1 (`editor.js`) — ~80 lines
- **CSS additions**: ~100 lines appended to existing `admin.css`
- **Modified files**: 1 (`public/index.php` — route changes, ~10 line diff)

---

## Edge Cases

1. **Empty title**: Caught by validation, returns error flash
2. **Duplicate slugs**: `ensureUniqueSlug()` appends `-2`, `-3`, etc.
3. **Very long titles**: Slug generation handles gracefully; title capped at 255 chars by DB and validation
4. **XSS in title/slug/excerpt**: All escaped via `$this->e()` in templates
5. **SQL injection**: Impossible — all queries use parameterized binding via QueryBuilder
6. **CSRF on all mutations**: All POST/PUT/DELETE forms include `$this->csrfField()`, validated by CsrfMiddleware
7. **Missing content ID**: Edit/update/delete check for existence, flash error and redirect if not found
8. **Empty bulk selection**: Caught by validation, returns error flash
9. **Invalid bulk action**: Caught by validation, returns error flash
10. **TinyMCE fails to load (CDN blocked)**: The textarea remains functional as a plain HTML editor — TinyMCE is an enhancement, not a requirement for form submission

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `app/Admin/ContentController.php` | Class | Create |
| 2 | `templates/admin/content/index.php` | Template | Create |
| 3 | `templates/admin/content/edit.php` | Template | Create |
| 4 | `public/assets/js/editor.js` | JavaScript | Create |
| 5 | `public/assets/css/admin.css` | Stylesheet | Modify (append) |
| 6 | `public/index.php` | Entry point | Modify (replace placeholder route) |
