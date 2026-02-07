# Chunk 2.3 — Media Management
## Detailed Implementation Plan

---

## Overview

This chunk builds the media upload system and library browser — file upload with validation (type whitelist, MIME check, size limit), storage with randomized filenames, media library grid view with pagination, deletion, and integration into the content editor. After completion, admins can upload images/PDFs, browse the media library, delete media items, and select images from a media browser modal when editing content (both for featured image and TinyMCE inline images).

---

## Prerequisites

The following are already implemented and available:

- **Database**: `media` table exists (created in migration 001) with columns: `id`, `filename`, `original_name`, `mime_type`, `size_bytes`, `uploaded_by`, `created_at`
- **Auth**: Session-based auth with `AuthMiddleware`, `CsrfMiddleware`, `Session` class (flash messages)
- **QueryBuilder**: Fluent builder with `select()`, `insert()`, `where()`, `delete()`, `leftJoin()`, `orderBy()`, `limit()`, `offset()`, `count()`, `get()`, `first()`
- **Router**: Supports `get()`, `post()`, `delete()`, `group()` with `{param}` patterns
- **Template Engine**: `render()`, `layout()`, `partial()`, `e()`, `csrfField()`
- **Admin Layout**: Sidebar already has "Media" link pointing to `/admin/media`
- **Content Editor**: `templates/admin/content/edit.php` has a "Featured Image" card with placeholder text "Media browser coming in a future update"
- **Editor JS**: `public/assets/js/editor.js` loads TinyMCE from CDN with `image` plugin already listed
- **CSS**: `public/assets/css/admin.css` has card, button, form, table, pagination, alert styles
- **Uploads directory**: `public/assets/uploads/` exists with `.gitkeep`

---

## File Creation / Modification Order

Files are listed in dependency order — each file only depends on files listed before it.

| # | File | Action | Purpose |
|---|------|--------|---------|
| 1 | `app/Core/Request.php` | **Modify** | Add `file()` and `hasFile()` methods for `$_FILES` access |
| 2 | `config/app.php` | **Modify** | Add `max_upload_size` config key (default 5MB) |
| 3 | `public/assets/uploads/.htaccess` | **Create** | Disable PHP/script execution in uploads directory |
| 4 | `app/Admin/MediaController.php` | **Create** | Full media CRUD: index, upload, delete, browse (AJAX) |
| 5 | `templates/admin/media/index.php` | **Create** | Media library grid view with upload form |
| 6 | `public/assets/css/admin.css` | **Modify** | Add media grid, upload zone, modal styles |
| 7 | `public/assets/js/editor.js` | **Modify** | Add media browser modal, TinyMCE image_upload_handler, featured image picker |
| 8 | `templates/admin/content/edit.php` | **Modify** | Replace featured image text input with browse button + preview |
| 9 | `public/index.php` | **Modify** | Replace placeholder media route with MediaController routes |

---

## 1. `app/Core/Request.php` — Modify

**Purpose**: Add file upload access methods. Currently the Request class wraps `$_GET`, `$_POST`, `$_SERVER`, `$_COOKIE` but has no access to `$_FILES`.

**Changes**: Add two new public methods.

```php
/**
 * Get uploaded file data by input name.
 * Returns the $_FILES entry array or null if not present.
 */
public function file(string $key): ?array
{
    $file = $_FILES[$key] ?? null;
    if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    return $file;
}

/**
 * Check if a file was uploaded for the given input name.
 */
public function hasFile(string $key): bool
{
    return $this->file($key) !== null;
}
```

**Notes**:
- We read `$_FILES` directly rather than capturing at construction because file data is read-only and doesn't benefit from trimming.
- `UPLOAD_ERR_NO_FILE` is treated as "no file uploaded" (returns null).

---

## 2. `config/app.php` — Modify

**Purpose**: Add `max_upload_size` configuration key.

**Change**: Add one line to the config array:

```php
'max_upload_size' => (int)(getenv('MAX_UPLOAD_SIZE') ?: 5242880), // 5MB in bytes
```

---

## 3. `public/assets/uploads/.htaccess` — Create

**Purpose**: Prevent PHP (or any script) execution in the uploads directory. Even if an attacker bypasses the MIME check and uploads a PHP file, Apache will not execute it.

```apache
# Disable script execution in uploads directory
<IfModule mod_php.c>
    php_flag engine off
</IfModule>

# Deny access to .php and other script files
<FilesMatch "\.(php|phtml|php[3-7]|phps|pht|shtml|pl|py|cgi|sh|bash)$">
    Require all denied
</FilesMatch>

# Force all files to be served as their MIME type, not executed
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
</IfModule>
```

---

## 4. `app/Admin/MediaController.php` — Create

**Purpose**: Full media management controller — list with pagination, upload with security validation, delete (file + record), and a JSON browse endpoint for the media browser modal.

**Class**: `App\Admin\MediaController`

**Dependencies**: `App\Core\App`, `App\Core\Config`, `App\Core\Request`, `App\Core\Response`, `App\Database\QueryBuilder`, `App\Auth\Session`

### Properties

```
private App $app
private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf']
private const ALLOWED_MIMES = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/pdf',
]
```

### Constructor

```php
public function __construct(App $app)
```

Stores the `$app` instance.

### Methods

#### `index(Request $request): Response`

**Route**: `GET /admin/media`

**Behavior**:
1. Read pagination params: `$page = max(1, (int) $request->query('page', '1'))`, `$perPage = Config::getInt('items_per_page', 10)`.
2. Count total media records: `QueryBuilder::query('media')->select()->count()`.
3. Calculate `$totalPages`, clamp `$page`, compute `$offset`.
4. Fetch media items with uploader info:
   ```php
   QueryBuilder::query('media')
       ->select('media.*', 'users.username as uploaded_by_name')
       ->leftJoin('users', 'users.id', '=', 'media.uploaded_by')
       ->orderBy('media.created_at', 'DESC')
       ->limit($perPage)
       ->offset($offset)
       ->get();
   ```
5. Render `admin/media/index` template with: `title`, `activeNav`, `items`, `page`, `totalPages`, `total`.
6. Apply security headers (same pattern as ContentController).

#### `upload(Request $request): Response`

**Route**: `POST /admin/media/upload`

**Behavior**:
1. Check `$request->hasFile('file')` — if no file, flash error and redirect back.
2. Get file data: `$file = $request->file('file')`.
3. Check for upload errors: if `$file['error'] !== UPLOAD_ERR_OK`, flash appropriate error message and redirect.
4. **Validate extension**:
   - Extract extension: `$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION))`.
   - Check against `ALLOWED_EXTENSIONS`. If not allowed, flash error: "File type not allowed. Allowed: jpg, jpeg, png, gif, webp, pdf".
5. **Validate MIME type**:
   - Use `finfo_open(FILEINFO_MIME_TYPE)` / `finfo_file()` to detect real MIME.
   - Check against `ALLOWED_MIMES`. If mismatch, flash error: "File content does not match its extension."
6. **Validate file size**:
   - Check `$file['size'] <= Config::getInt('max_upload_size', 5242880)`. If too large, flash error with max size.
7. **Generate unique filename**:
   - `$hash = bin2hex(random_bytes(16))` — 32 hex chars.
   - `$newFilename = $hash . '.' . $ext`.
8. **Determine upload directory**:
   - Base: `public/assets/uploads/`.
   - Organize by year/month: `$subdir = date('Y') . '/' . date('m')`.
   - Full dir: `$uploadsBase . $subdir`.
   - Create directory if not exists: `mkdir($fullDir, 0755, true)`.
9. **Move file**:
   - `move_uploaded_file($file['tmp_name'], $fullDir . '/' . $newFilename)`.
   - If move fails, flash error.
10. **Insert database record**:
    ```php
    QueryBuilder::query('media')->insert([
        'filename'      => $subdir . '/' . $newFilename,
        'original_name' => $file['name'],
        'mime_type'     => $mime,
        'size_bytes'    => $file['size'],
        'uploaded_by'   => (int) Session::get('user_id'),
    ]);
    ```
    Note: `filename` stores the relative path from the uploads directory (e.g., `2026/02/abcdef123456.jpg`).
11. Flash success message, redirect to `/admin/media`.

**For AJAX uploads** (detected via `$request->isAjax()`):
- On success: return `Response::json(['success' => true, 'id' => $id, 'url' => '/assets/uploads/' . $subdir . '/' . $newFilename, 'filename' => $file['name']])`.
- On error: return `Response::json(['success' => false, 'error' => $errorMessage], 400)`.

#### `delete(Request $request, string $id): Response`

**Route**: `DELETE /admin/media/{id}`

**Behavior**:
1. Fetch media record by ID. If not found, flash error and redirect.
2. Delete file from disk: `unlink($uploadsBase . $record['filename'])` — suppress errors with `@` in case file was already manually removed.
3. Delete database record: `QueryBuilder::query('media')->where('id', (int)$id)->delete()`.
4. Flash success, redirect to `/admin/media`.

For AJAX: return `Response::json(['success' => true])`.

#### `browse(Request $request): Response`

**Route**: `GET /admin/media/browse`

**Purpose**: JSON endpoint for the media browser modal in the content editor. Returns a paginated list of media items as JSON.

**Behavior**:
1. Read `$page` from query params.
2. Fetch media items (images only for browse mode — filter by MIME LIKE 'image/%'), paginated.
3. Return `Response::json(['items' => [...], 'page' => $page, 'totalPages' => $totalPages])`.
4. Each item: `['id' => ..., 'url' => '/assets/uploads/' . $filename, 'original_name' => ..., 'mime_type' => ..., 'created_at' => ...]`.

### Private Helper Methods

#### `withSecurityHeaders(Response $response): Response`

Same pattern as `ContentController::withSecurityHeaders()`:
```php
private function withSecurityHeaders(Response $response): Response
{
    return $response
        ->withHeader('X-Frame-Options', 'DENY')
        ->withHeader('Content-Security-Policy',
            "default-src 'self'; "
            . "script-src 'self' https://cdn.jsdelivr.net; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
            . "img-src 'self' data: blob:; "
            . "connect-src 'self'; "
            . "font-src 'self' https://cdn.jsdelivr.net"
        );
}
```

#### `getUploadsPath(): string`

Returns the absolute path to the uploads directory:
```php
private function getUploadsPath(): string
{
    return dirname(__DIR__, 2) . '/public/assets/uploads/';
}
```

#### `formatFileSize(int $bytes): string`

Human-readable file size:
```php
private function formatFileSize(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}
```

---

### Full Code Template: `app/Admin/MediaController.php`

```php
<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;

class MediaController
{
    private App $app;

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/media — Media library grid with pagination.
     */
    public function index(Request $request): Response
    {
        $page    = max(1, (int) $request->query('page', '1'));
        $perPage = Config::getInt('items_per_page', 10);

        $total      = QueryBuilder::query('media')->select()->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        $items = QueryBuilder::query('media')
            ->select('media.*', 'users.username as uploaded_by_name')
            ->leftJoin('users', 'users.id', '=', 'media.uploaded_by')
            ->orderBy('media.created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $html = $this->app->template()->render('admin/media/index', [
            'title'      => 'Media Library',
            'activeNav'  => 'media',
            'items'      => $items,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * POST /admin/media/upload — Handle file upload.
     */
    public function upload(Request $request): Response
    {
        $isAjax = $request->isAjax();

        // Check file presence
        if (!$request->hasFile('file')) {
            return $this->uploadError('No file was uploaded.', $isAjax);
        }

        $file = $request->file('file');
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->uploadError($this->uploadErrorMessage($file['error']), $isAjax);
        }

        // Validate extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return $this->uploadError(
                'File type not allowed. Allowed: ' . implode(', ', self::ALLOWED_EXTENSIONS),
                $isAjax
            );
        }

        // Validate MIME type with finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return $this->uploadError('File content does not match its extension.', $isAjax);
        }

        // Validate file size
        $maxSize = Config::getInt('max_upload_size', 5242880);
        if ($file['size'] > $maxSize) {
            return $this->uploadError(
                'File is too large. Maximum size: ' . $this->formatFileSize($maxSize),
                $isAjax
            );
        }

        // Generate unique filename
        $hash = bin2hex(random_bytes(16));
        $newFilename = $hash . '.' . $ext;
        $subdir = date('Y') . '/' . date('m');

        // Ensure directory exists
        $uploadsBase = $this->getUploadsPath();
        $fullDir = $uploadsBase . $subdir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        // Move uploaded file
        $destPath = $fullDir . '/' . $newFilename;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return $this->uploadError('Failed to save uploaded file.', $isAjax);
        }

        // Insert database record
        $relativePath = $subdir . '/' . $newFilename;
        $id = QueryBuilder::query('media')->insert([
            'filename'      => $relativePath,
            'original_name' => $file['name'],
            'mime_type'     => $mime,
            'size_bytes'    => $file['size'],
            'uploaded_by'   => (int) Session::get('user_id'),
        ]);

        $url = '/assets/uploads/' . $relativePath;

        if ($isAjax) {
            return Response::json([
                'success'       => true,
                'id'            => (int) $id,
                'url'           => $url,
                'original_name' => $file['name'],
                'mime_type'     => $mime,
            ]);
        }

        Session::flash('success', 'File uploaded successfully.');
        return Response::redirect('/admin/media');
    }

    /**
     * DELETE /admin/media/{id} — Delete a media item.
     */
    public function delete(Request $request, string $id): Response
    {
        $isAjax = $request->isAjax();

        $item = QueryBuilder::query('media')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($item === null) {
            if ($isAjax) {
                return Response::json(['success' => false, 'error' => 'Media not found.'], 404);
            }
            Session::flash('error', 'Media not found.');
            return Response::redirect('/admin/media');
        }

        // Delete file from disk
        $filePath = $this->getUploadsPath() . $item['filename'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        // Delete database record
        QueryBuilder::query('media')->where('id', (int) $id)->delete();

        if ($isAjax) {
            return Response::json(['success' => true]);
        }

        Session::flash('success', 'Media deleted.');
        return Response::redirect('/admin/media');
    }

    /**
     * GET /admin/media/browse — JSON endpoint for media browser modal.
     */
    public function browse(Request $request): Response
    {
        $page    = max(1, (int) $request->query('page', '1'));
        $perPage = 20;
        $type    = (string) $request->query('type', ''); // 'image' to filter images only

        $qb = QueryBuilder::query('media')->select();
        if ($type === 'image') {
            $qb->whereRaw('mime_type LIKE :mime', [':mime' => 'image/%']);
        }
        $total = $qb->count();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        $qb2 = QueryBuilder::query('media')->select();
        if ($type === 'image') {
            $qb2->whereRaw('mime_type LIKE :mime', [':mime' => 'image/%']);
        }
        $items = $qb2->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'id'            => (int) $item['id'],
                'url'           => '/assets/uploads/' . $item['filename'],
                'original_name' => $item['original_name'],
                'mime_type'     => $item['mime_type'],
                'size'          => $this->formatFileSize((int) $item['size_bytes']),
                'created_at'    => $item['created_at'],
            ];
        }

        return Response::json([
            'items'      => $result,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    private function getUploadsPath(): string
    {
        return dirname(__DIR__, 2) . '/public/assets/uploads/';
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    private function uploadError(string $message, bool $isAjax): Response
    {
        if ($isAjax) {
            return Response::json(['success' => false, 'error' => $message], 400);
        }
        Session::flash('error', $message);
        return Response::redirect('/admin/media');
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size.',
            UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
            default               => 'Unknown upload error.',
        };
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self' https://cdn.jsdelivr.net; "
                . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self'; "
                . "font-src 'self' https://cdn.jsdelivr.net"
            );
    }
}
```

---

## 5. `templates/admin/media/index.php` — Create

**Purpose**: Media library page with upload form and grid of existing media.

### Full Code Template

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Media Library</h1>
    <span class="text-muted"><?= $total ?> file(s)</span>
</div>

<!-- Upload Form -->
<div class="card" style="margin-bottom: 1.5rem;">
    <div class="card-header">Upload New File</div>
    <div class="card-body">
        <form method="POST" action="/admin/media/upload" enctype="multipart/form-data"
              id="upload-form">
            <?= $this->csrfField() ?>
            <div class="upload-zone" id="upload-zone">
                <div class="upload-zone-content">
                    <p class="upload-zone-icon">&#128206;</p>
                    <p>Drag & drop a file here, or click to select</p>
                    <p class="text-muted" style="font-size: 0.8rem;">
                        Allowed: JPG, PNG, GIF, WebP, PDF — Max 5 MB
                    </p>
                </div>
                <input type="file" name="file" id="file-input"
                       accept=".jpg,.jpeg,.png,.gif,.webp,.pdf"
                       style="display: none;">
            </div>
            <div id="upload-preview" style="display:none; margin-top: 1rem;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <img id="upload-preview-img" src="" alt="Preview"
                         style="max-width: 80px; max-height: 80px; border-radius: 4px; display: none;">
                    <div>
                        <div id="upload-preview-name" style="font-weight: 500;"></div>
                        <div id="upload-preview-size" class="text-muted"
                             style="font-size: 0.85rem;"></div>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                    <button type="button" class="btn" id="upload-cancel">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Media Grid -->
<?php if (empty($items)): ?>
    <div class="empty-state">
        <p>No media files uploaded yet.</p>
        <p class="text-muted">Upload your first file using the form above.</p>
    </div>
<?php else: ?>
    <div class="media-grid">
        <?php foreach ($items as $item): ?>
            <?php
            $isImage = str_starts_with($item['mime_type'], 'image/');
            $url = '/assets/uploads/' . $item['filename'];
            ?>
            <div class="media-card" data-id="<?= (int)$item['id'] ?>">
                <div class="media-card-preview">
                    <?php if ($isImage): ?>
                        <img src="<?= $this->e($url) ?>"
                             alt="<?= $this->e($item['original_name']) ?>"
                             loading="lazy">
                    <?php else: ?>
                        <div class="media-card-icon">&#128196;</div>
                    <?php endif; ?>
                </div>
                <div class="media-card-info">
                    <div class="media-card-name" title="<?= $this->e($item['original_name']) ?>">
                        <?= $this->e($item['original_name']) ?>
                    </div>
                    <div class="media-card-meta">
                        <?= $this->e($item['mime_type']) ?>
                        — <?= $this->e($item['uploaded_by_name'] ?? 'unknown') ?>
                    </div>
                </div>
                <div class="media-card-actions">
                    <a href="<?= $this->e($url) ?>" target="_blank"
                       class="btn btn-sm">View</a>
                    <form method="POST" action="/admin/media/<?= (int)$item['id'] ?>"
                          style="display:inline;"
                          onsubmit="return confirm('Delete this file permanently?');">
                        <?= $this->csrfField() ?>
                        <input type="hidden" name="_method" value="DELETE">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="/admin/media?page=<?= $page - 1 ?>">← Prev</a>
            <?php endif; ?>

            <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>

            <?php if ($page < $totalPages): ?>
                <a href="/admin/media?page=<?= $page + 1 ?>">Next →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
// Inline upload zone interactions (no separate JS file needed — tiny amount of code)
document.addEventListener('DOMContentLoaded', function() {
    var zone = document.getElementById('upload-zone');
    var input = document.getElementById('file-input');
    var preview = document.getElementById('upload-preview');
    var previewImg = document.getElementById('upload-preview-img');
    var previewName = document.getElementById('upload-preview-name');
    var previewSize = document.getElementById('upload-preview-size');
    var cancelBtn = document.getElementById('upload-cancel');

    if (!zone || !input) return;

    zone.addEventListener('click', function() { input.click(); });

    zone.addEventListener('dragover', function(e) {
        e.preventDefault();
        zone.classList.add('drag-over');
    });

    zone.addEventListener('dragleave', function() {
        zone.classList.remove('drag-over');
    });

    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('drag-over');
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            showPreview(e.dataTransfer.files[0]);
        }
    });

    input.addEventListener('change', function() {
        if (input.files.length) {
            showPreview(input.files[0]);
        }
    });

    cancelBtn.addEventListener('click', function() {
        input.value = '';
        preview.style.display = 'none';
        zone.style.display = '';
    });

    function showPreview(file) {
        previewName.textContent = file.name;
        previewSize.textContent = formatSize(file.size);
        zone.style.display = 'none';
        preview.style.display = 'block';

        if (file.type.startsWith('image/')) {
            var reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                previewImg.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            previewImg.style.display = 'none';
        }
    }

    function formatSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }
});
</script>
```

---

## 6. `public/assets/css/admin.css` — Modify

**Purpose**: Add styles for the media grid, upload zone, and media browser modal.

**Append the following CSS blocks** after the existing content (before the `@media` responsive block at the end):

```css
/* --- Upload Zone --- */
.upload-zone {
    border: 2px dashed var(--color-border);
    border-radius: var(--card-radius);
    padding: 2rem;
    text-align: center;
    cursor: pointer;
    transition: border-color var(--transition-fast), background var(--transition-fast);
}

.upload-zone:hover,
.upload-zone.drag-over {
    border-color: var(--color-primary);
    background: var(--color-primary-light);
}

.upload-zone-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.upload-zone-content p {
    margin: 0.25rem 0;
}

/* --- Media Grid --- */
.media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1rem;
}

.media-card {
    background: var(--color-white);
    border-radius: var(--card-radius);
    box-shadow: var(--card-shadow);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: box-shadow var(--transition-fast);
}

.media-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.media-card-preview {
    aspect-ratio: 1;
    background: var(--color-border-light);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.media-card-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.media-card-icon {
    font-size: 3rem;
    color: var(--color-text-muted);
}

.media-card-info {
    padding: 0.75rem;
    flex: 1;
}

.media-card-name {
    font-size: 0.85rem;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.media-card-meta {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    margin-top: 0.2rem;
}

.media-card-actions {
    padding: 0.5rem 0.75rem;
    border-top: 1px solid var(--color-border);
    display: flex;
    gap: 0.5rem;
}

/* --- Media Browser Modal --- */
.media-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.media-modal-overlay.active {
    display: flex;
}

.media-modal {
    background: var(--color-white);
    border-radius: var(--card-radius);
    width: 90%;
    max-width: 800px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.media-modal-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-weight: 600;
}

.media-modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--color-text-muted);
    padding: 0;
    line-height: 1;
}

.media-modal-close:hover {
    color: var(--color-text);
}

.media-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 1.25rem;
}

.media-modal-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 0.75rem;
}

.media-modal-item {
    aspect-ratio: 1;
    border-radius: 6px;
    overflow: hidden;
    cursor: pointer;
    border: 3px solid transparent;
    transition: border-color var(--transition-fast);
    background: var(--color-border-light);
    display: flex;
    align-items: center;
    justify-content: center;
}

.media-modal-item:hover {
    border-color: var(--color-primary);
}

.media-modal-item.selected {
    border-color: var(--color-primary);
    box-shadow: 0 0 0 2px var(--color-primary-light);
}

.media-modal-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.media-modal-footer {
    padding: 1rem 1.25rem;
    border-top: 1px solid var(--color-border);
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
}

/* --- Featured Image Preview --- */
.featured-image-preview {
    margin-bottom: 0.75rem;
}

.featured-image-preview img {
    max-width: 100%;
    max-height: 150px;
    border-radius: 6px;
    object-fit: cover;
}

.featured-image-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}
```

**Add to the responsive `@media (max-width: 768px)` block:**

```css
.media-grid {
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
}

.media-modal {
    width: 95%;
    max-height: 90vh;
}

.media-modal-grid {
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
}
```

---

## 7. `public/assets/js/editor.js` — Modify

**Purpose**: Add media browser modal functionality, TinyMCE image upload handler, and featured image picker. These additions integrate with the MediaController's `/admin/media/browse` and `/admin/media/upload` endpoints.

### Changes

**Add to the TinyMCE `init()` configuration** (inside the existing `tinymce.init({...})` block):

1. Add `images_upload_handler` for TinyMCE image uploads:
```javascript
images_upload_handler: function(blobInfo) {
    return new Promise(function(resolve, reject) {
        var formData = new FormData();
        formData.append('file', blobInfo.blob(), blobInfo.filename());
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        fetch('/admin/media/upload', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                resolve(data.url);
            } else {
                reject('Upload failed: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(function() { reject('Upload failed: Network error'); });
    });
}
```

2. Add a custom toolbar button for the media browser:
```javascript
// In the setup callback, add:
editor.ui.registry.addButton('mediabrowser', {
    icon: 'image',
    tooltip: 'Insert from Media Library',
    onAction: function() {
        openMediaBrowser(function(url) {
            editor.insertContent('<img src="' + url + '" alt="" />');
        });
    }
});
```

3. Update the `toolbar` string to include `| mediabrowser` after `removeformat`.

**Add the `openMediaBrowser()` function** and the **featured image picker** logic at the bottom of the DOMContentLoaded handler:

```javascript
// --- Media Browser Modal ---
function openMediaBrowser(callback) {
    // Create modal if it doesn't exist yet
    var overlay = document.getElementById('media-modal-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'media-modal-overlay';
        overlay.className = 'media-modal-overlay';
        overlay.innerHTML =
            '<div class="media-modal">'
            + '<div class="media-modal-header">'
            + '<span>Select Media</span>'
            + '<button class="media-modal-close">&times;</button>'
            + '</div>'
            + '<div class="media-modal-body">'
            + '<div class="media-modal-grid" id="media-modal-grid"></div>'
            + '<div id="media-modal-loading" class="text-center text-muted" style="padding:2rem;">Loading...</div>'
            + '</div>'
            + '<div class="media-modal-footer">'
            + '<button class="btn" id="media-modal-cancel">Cancel</button>'
            + '<button class="btn btn-primary" id="media-modal-select" disabled>Select</button>'
            + '</div>'
            + '</div>';
        document.body.appendChild(overlay);

        overlay.querySelector('.media-modal-close').addEventListener('click', closeMediaBrowser);
        document.getElementById('media-modal-cancel').addEventListener('click', closeMediaBrowser);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeMediaBrowser();
        });
    }

    overlay.classList.add('active');
    var grid = document.getElementById('media-modal-grid');
    var loading = document.getElementById('media-modal-loading');
    var selectBtn = document.getElementById('media-modal-select');
    var selectedUrl = null;

    grid.innerHTML = '';
    loading.style.display = 'block';
    selectBtn.disabled = true;

    fetch('/admin/media/browse?type=image&page=1', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        loading.style.display = 'none';
        if (!data.items || data.items.length === 0) {
            grid.innerHTML = '<p class="text-muted" style="grid-column:1/-1;text-align:center;padding:2rem;">No images found. Upload some first.</p>';
            return;
        }
        data.items.forEach(function(item) {
            var el = document.createElement('div');
            el.className = 'media-modal-item';
            el.innerHTML = '<img src="' + item.url + '" alt="' + item.original_name + '" loading="lazy">';
            el.addEventListener('click', function() {
                // Deselect others
                grid.querySelectorAll('.media-modal-item.selected').forEach(function(s) {
                    s.classList.remove('selected');
                });
                el.classList.add('selected');
                selectedUrl = item.url;
                selectBtn.disabled = false;
            });
            grid.appendChild(el);
        });
    })
    .catch(function() {
        loading.style.display = 'none';
        grid.innerHTML = '<p class="text-muted" style="grid-column:1/-1;text-align:center;">Failed to load media.</p>';
    });

    // Wire up select button
    selectBtn.onclick = function() {
        if (selectedUrl && callback) {
            callback(selectedUrl);
        }
        closeMediaBrowser();
    };
}

function closeMediaBrowser() {
    var overlay = document.getElementById('media-modal-overlay');
    if (overlay) overlay.classList.remove('active');
}

// --- Featured Image Picker ---
var browseBtn = document.getElementById('featured-image-browse');
var removeBtn = document.getElementById('featured-image-remove');
var featuredInput = document.getElementById('featured_image');
var featuredPreview = document.getElementById('featured-image-preview-img');

if (browseBtn) {
    browseBtn.addEventListener('click', function() {
        openMediaBrowser(function(url) {
            featuredInput.value = url;
            if (featuredPreview) {
                featuredPreview.src = url;
                featuredPreview.parentElement.style.display = 'block';
            }
        });
    });
}

if (removeBtn) {
    removeBtn.addEventListener('click', function() {
        featuredInput.value = '';
        if (featuredPreview) {
            featuredPreview.parentElement.style.display = 'none';
        }
    });
}
```

### Full Updated `editor.js`

```javascript
/**
 * LiteCMS Content Editor
 * Handles: TinyMCE WYSIWYG, slug auto-generation, select-all checkbox, media browser
 */
document.addEventListener('DOMContentLoaded', function() {

    // --- TinyMCE Initialization ---
    var bodyField = document.getElementById('body');
    if (bodyField) {
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js';
        script.onload = function() {
            tinymce.init({
                selector: '#body',
                base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
                suffix: '.min',
                height: 500,
                menubar: true,
                plugins: [
                    'advlist', 'autolink', 'lists', 'link', 'image', 'charmap',
                    'preview', 'anchor', 'searchreplace', 'visualblocks', 'code',
                    'fullscreen', 'insertdatetime', 'media', 'table', 'help', 'wordcount'
                ],
                toolbar: 'undo redo | blocks | bold italic forecolor | '
                    + 'alignleft aligncenter alignright alignjust | '
                    + 'bullist numlist outdent indent | removeformat | mediabrowser | code | help',
                content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, '
                    + '"Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; '
                    + 'font-size: 16px; line-height: 1.6; max-width: 100%; }',
                promotion: false,
                branding: false,
                images_upload_handler: function(blobInfo) {
                    return new Promise(function(resolve, reject) {
                        var csrfInput = document.querySelector('input[name="csrf_token"]');
                        var formData = new FormData();
                        formData.append('file', blobInfo.blob(), blobInfo.filename());
                        if (csrfInput) {
                            formData.append('csrf_token', csrfInput.value);
                        }

                        fetch('/admin/media/upload', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body: formData
                        })
                        .then(function(res) { return res.json(); })
                        .then(function(data) {
                            if (data.success) {
                                resolve(data.url);
                            } else {
                                reject('Upload failed: ' + (data.error || 'Unknown error'));
                            }
                        })
                        .catch(function() { reject('Upload failed: Network error'); });
                    });
                },
                setup: function(editor) {
                    editor.on('change', function() {
                        editor.save();
                    });
                    // Media browser toolbar button
                    editor.ui.registry.addButton('mediabrowser', {
                        icon: 'image',
                        tooltip: 'Insert from Media Library',
                        onAction: function() {
                            openMediaBrowser(function(url) {
                                editor.insertContent('<img src="' + url + '" alt="" />');
                            });
                        }
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

    // --- Delete Buttons (standalone form to avoid nested-form bug) ---
    var deleteForm = document.getElementById('delete-form');
    var deleteButtons = document.querySelectorAll('.delete-btn');
    for (var i = 0; i < deleteButtons.length; i++) {
        deleteButtons[i].addEventListener('click', function() {
            var msg = this.getAttribute('data-confirm');
            if (!msg || confirm(msg)) {
                deleteForm.action = '/admin/content/' + this.getAttribute('data-id');
                deleteForm.submit();
            }
        });
    }

    // --- Confirm Dialogs (for bulk apply, etc.) ---
    var confirmButtons = document.querySelectorAll('button[data-confirm]:not(.delete-btn)');
    for (var i = 0; i < confirmButtons.length; i++) {
        confirmButtons[i].addEventListener('click', function(e) {
            if (!confirm(this.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    }

    // --- Media Browser Modal ---
    function openMediaBrowser(callback) {
        var overlay = document.getElementById('media-modal-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'media-modal-overlay';
            overlay.className = 'media-modal-overlay';
            overlay.innerHTML =
                '<div class="media-modal">'
                + '<div class="media-modal-header">'
                + '<span>Select Media</span>'
                + '<button class="media-modal-close">&times;</button>'
                + '</div>'
                + '<div class="media-modal-body">'
                + '<div class="media-modal-grid" id="media-modal-grid"></div>'
                + '<div id="media-modal-loading" class="text-center text-muted" style="padding:2rem;">Loading...</div>'
                + '</div>'
                + '<div class="media-modal-footer">'
                + '<button class="btn" id="media-modal-cancel">Cancel</button>'
                + '<button class="btn btn-primary" id="media-modal-select" disabled>Select</button>'
                + '</div>'
                + '</div>';
            document.body.appendChild(overlay);

            overlay.querySelector('.media-modal-close').addEventListener('click', closeMediaBrowser);
            document.getElementById('media-modal-cancel').addEventListener('click', closeMediaBrowser);
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeMediaBrowser();
            });
        }

        overlay.classList.add('active');
        var grid = document.getElementById('media-modal-grid');
        var loading = document.getElementById('media-modal-loading');
        var selectBtn = document.getElementById('media-modal-select');
        var selectedUrl = null;

        grid.innerHTML = '';
        loading.style.display = 'block';
        selectBtn.disabled = true;

        fetch('/admin/media/browse?type=image&page=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            loading.style.display = 'none';
            if (!data.items || data.items.length === 0) {
                grid.innerHTML = '<p class="text-muted" style="grid-column:1/-1;text-align:center;padding:2rem;">No images found. Upload some first.</p>';
                return;
            }
            data.items.forEach(function(item) {
                var el = document.createElement('div');
                el.className = 'media-modal-item';
                el.innerHTML = '<img src="' + item.url + '" alt="" loading="lazy">';
                el.addEventListener('click', function() {
                    grid.querySelectorAll('.media-modal-item.selected').forEach(function(s) {
                        s.classList.remove('selected');
                    });
                    el.classList.add('selected');
                    selectedUrl = item.url;
                    selectBtn.disabled = false;
                });
                grid.appendChild(el);
            });
        })
        .catch(function() {
            loading.style.display = 'none';
            grid.innerHTML = '<p class="text-muted" style="grid-column:1/-1;text-align:center;">Failed to load media.</p>';
        });

        selectBtn.onclick = function() {
            if (selectedUrl && callback) {
                callback(selectedUrl);
            }
            closeMediaBrowser();
        };
    }

    function closeMediaBrowser() {
        var overlay = document.getElementById('media-modal-overlay');
        if (overlay) overlay.classList.remove('active');
    }

    // --- Featured Image Picker ---
    var browseBtn = document.getElementById('featured-image-browse');
    var removeBtn = document.getElementById('featured-image-remove');
    var featuredInput = document.getElementById('featured_image');
    var featuredPreview = document.getElementById('featured-image-preview-img');

    if (browseBtn) {
        browseBtn.addEventListener('click', function() {
            openMediaBrowser(function(url) {
                featuredInput.value = url;
                if (featuredPreview) {
                    featuredPreview.src = url;
                    featuredPreview.parentElement.style.display = 'block';
                }
            });
        });
    }

    if (removeBtn) {
        removeBtn.addEventListener('click', function() {
            featuredInput.value = '';
            if (featuredPreview) {
                featuredPreview.parentElement.style.display = 'none';
            }
        });
    }
});
```

---

## 8. `templates/admin/content/edit.php` — Modify

**Purpose**: Replace the "Featured Image" card's simple text input with a media browser integration: a hidden input to store the URL, a preview image, a "Browse Media" button, and a "Remove" button.

**Replace the Featured Image Card** (lines 122–136 in the current file) with:

```php
<!-- Featured Image Card -->
<div class="card">
    <div class="card-header">Featured Image</div>
    <div class="card-body">
        <div class="form-group mb-0">
            <?php
            $featuredImg = $content['featured_image'] ?? '';
            $hasImage = ($featuredImg !== '' && $featuredImg !== null);
            ?>
            <div class="featured-image-preview"
                 style="<?= $hasImage ? '' : 'display:none;' ?>">
                <img id="featured-image-preview-img"
                     src="<?= $this->e((string)$featuredImg) ?>"
                     alt="Featured image preview">
            </div>
            <input type="hidden" id="featured_image" name="featured_image"
                   value="<?= $this->e((string)$featuredImg) ?>">
            <div class="featured-image-actions">
                <button type="button" class="btn btn-sm" id="featured-image-browse">
                    Browse Media
                </button>
                <button type="button" class="btn btn-sm"
                        id="featured-image-remove"
                        style="<?= $hasImage ? '' : 'display:none;' ?>">
                    Remove
                </button>
            </div>
        </div>
    </div>
</div>
```

---

## 9. `public/index.php` — Modify

**Purpose**: Replace the placeholder `/admin/media` route with real MediaController routes.

**Add import** at the top with other `use` statements:

```php
use App\Admin\MediaController;
```

**Replace** the placeholder media route block (lines 86–94) with:

```php
// Media management routes
$router->get('/media', [MediaController::class, 'index']);
$router->post('/media/upload', [MediaController::class, 'upload']);
$router->delete('/media/{id}', [MediaController::class, 'delete']);
$router->get('/media/browse', [MediaController::class, 'browse']);
```

**Important ordering note**: The `/media/browse` and `/media/upload` routes must appear before `/media/{id}` to avoid the `{id}` pattern capturing "browse" or "upload" as an ID. However, since we're using `GET` vs `POST` vs `DELETE` methods, and `browse` is GET while `{id}` is DELETE, there's no actual conflict. But for safety, place the explicit routes before the parameterized one.

Reordered:
```php
$router->get('/media', [MediaController::class, 'index']);
$router->get('/media/browse', [MediaController::class, 'browse']);
$router->post('/media/upload', [MediaController::class, 'upload']);
$router->delete('/media/{id}', [MediaController::class, 'delete']);
```

---

## Acceptance Test Procedures

### Test 1: Upload a JPG image
```
1. Log in as admin. Go to /admin/media.
2. Click the upload zone, select a .jpg file under 5MB.
3. Click "Upload".
4. Verify:
   - Flash message "File uploaded successfully." appears.
   - Image appears in the media grid with correct thumbnail.
   - File exists on disk at public/assets/uploads/YYYY/MM/{hash}.jpg.
   - Record exists in media table with correct filename, original_name, mime_type, size_bytes.
```

### Test 2: Upload a .php file — rejected
```
1. Go to /admin/media.
2. Attempt to upload a file named "test.php".
3. Verify:
   - Error message: "File type not allowed."
   - No file saved to disk.
   - No record in media table.
```

### Test 3: Upload a faked extension — rejected by MIME check
```
1. Rename a PHP file to "malicious.jpg".
2. Attempt to upload it.
3. Verify:
   - Error message: "File content does not match its extension."
   - No file saved.
```

### Test 4: Media library grid displays uploaded images
```
1. Upload 3 images.
2. Go to /admin/media.
3. Verify:
   - All 3 images appear in the grid with thumbnails.
   - Each card shows original filename, MIME type, and uploader.
   - "View" link opens the image in a new tab.
```

### Test 5: Delete a media item
```
1. Upload an image. Note the filename.
2. Click "Delete" on the media card. Confirm.
3. Verify:
   - Flash message "Media deleted." appears.
   - File removed from disk.
   - Record removed from media table.
```

### Test 6: Insert image from media library into TinyMCE
```
1. Go to Content → Create.
2. In the TinyMCE toolbar, click the media browser button (image icon).
3. Modal opens showing uploaded images.
4. Click an image, click "Select".
5. Verify:
   - Image is inserted into the TinyMCE editor body.
   - The HTML <img src="/assets/uploads/..."> is correct.
```

### Test 7: Featured image picker works
```
1. Go to Content → Create.
2. In the sidebar "Featured Image" card, click "Browse Media".
3. Select an image from the modal.
4. Verify:
   - Image preview appears in the Featured Image card.
   - Hidden input contains the image URL.
5. Click "Remove" — preview disappears, input clears.
6. Save the content. Verify featured_image field is stored correctly.
```

### Test 8: TinyMCE image drag-and-drop upload
```
1. Go to Content → Edit an existing item.
2. Drag an image file from the desktop into the TinyMCE editor.
3. Verify:
   - Image is uploaded via the AJAX upload handler.
   - Image appears in the editor.
   - File appears in the media library.
```

### Test 9: Pagination in media library
```
1. Upload 15+ images (with items_per_page = 10 in config).
2. Go to /admin/media.
3. Verify:
   - Only 10 images shown on page 1.
   - "Next →" link appears.
   - Clicking it shows the remaining images.
```

### Test 10: File size limit enforcement
```
1. Set max_upload_size to a small value (e.g., 1024 = 1KB) in config.
2. Attempt to upload a normal image.
3. Verify:
   - Error message includes the maximum size.
   - File not saved.
```

---

## Implementation Notes

### Coding Standards
- `<?php declare(strict_types=1);` at the top of every PHP file.
- PSR-4: `App\Admin\MediaController` → `app/Admin/MediaController.php`.
- All database queries use parameterized QueryBuilder methods — no raw SQL with user input.
- All template output uses `$this->e()` for XSS prevention.
- CSRF token required on all POST/DELETE requests (handled by existing CsrfMiddleware).

### Security Highlights
- **Extension whitelist**: Only `jpg`, `jpeg`, `png`, `gif`, `webp`, `pdf` are allowed. Everything else is rejected before any processing.
- **MIME validation**: Uses `finfo_file()` (libmagic) to detect actual file content type, independent of the declared extension. A PHP file renamed to `.jpg` will be caught here.
- **Randomized filenames**: `bin2hex(random_bytes(16))` generates an unpredictable 32-character hex string. Original filenames are stored in the database but never used on disk.
- **Upload directory protection**: `.htaccess` in the uploads directory disables PHP execution entirely. Even if all other checks fail, the file cannot be executed as PHP.
- **Size validation**: Configurable via `max_upload_size` in `config/app.php`. Server-side check regardless of client-side limits.

### Storage Path Convention
Files are stored at: `public/assets/uploads/{YYYY}/{MM}/{hash}.{ext}`

The `media.filename` column stores the relative path from the uploads root: `2026/02/abcdef123456.jpg`

URLs for the frontend: `/assets/uploads/2026/02/abcdef123456.jpg`

This structure:
- Prevents too many files in a single directory (spreads across year/month folders).
- Makes it easy to clean up old files by date.
- Is compatible with the existing `.htaccess` rewrite rules that serve static files directly.

### Edge Cases
- **Upload to non-existent directory**: The controller creates the year/month directory with `mkdir(..., 0755, true)` before moving the file.
- **Deleting a file that's already been manually removed from disk**: `@unlink()` suppresses the warning; the database record is still cleaned up.
- **Deleting media that's referenced as featured_image in content**: This is allowed — the content editor will show a broken image. The plan does not implement referential integrity between content.featured_image and media.filename because the featured_image field is a free-text URL (could reference external images too). A future enhancement could warn about this.
- **Concurrent uploads with same random hash**: Astronomically unlikely with 128 bits of randomness (1 in 2^128). Not worth guarding against.
- **Large files**: PHP's `upload_max_filesize` and `post_max_size` in php.ini also limit uploads server-side. The controller's size check is an additional safeguard.
- **AJAX vs form-based upload**: The controller detects AJAX via `$request->isAjax()` and returns JSON for AJAX or redirects for standard form submissions.

### What This Chunk Does NOT Include
- **Image resizing / thumbnails**: Images are served at their original size. CSS `object-fit: cover` handles display. Thumbnail generation would add a dependency (GD/Imagick) which may not be available on shared hosting.
- **Bulk delete in media library**: Not in the spec for 2.3. Could be added later.
- **Media edit (rename, alt text)**: Not in the spec. The `original_name` is stored but not editable.

---

## File Checklist

| # | File | Action | Type |
|---|------|--------|------|
| 1 | `app/Core/Request.php` | Modify | Class (add 2 methods) |
| 2 | `config/app.php` | Modify | Config (add 1 key) |
| 3 | `public/assets/uploads/.htaccess` | Create | Security config |
| 4 | `app/Admin/MediaController.php` | Create | Class |
| 5 | `templates/admin/media/index.php` | Create | Template |
| 6 | `public/assets/css/admin.css` | Modify | Stylesheet (append) |
| 7 | `public/assets/js/editor.js` | Modify | JavaScript (major update) |
| 8 | `templates/admin/content/edit.php` | Modify | Template (featured image card) |
| 9 | `public/index.php` | Modify | Routes (replace placeholder) |

---

## Estimated Scope

- **New PHP class**: 1 (MediaController — ~200 lines)
- **Modified PHP class**: 1 (Request — +15 lines)
- **New template**: 1 (admin/media/index.php — ~110 lines)
- **Modified template**: 1 (admin/content/edit.php — Featured Image card)
- **JavaScript changes**: ~150 lines added to editor.js (media browser modal, upload handler, featured image picker)
- **CSS additions**: ~150 lines appended to admin.css
- **Config changes**: 1 line in config/app.php, 1 new .htaccess
- **Route changes**: 4 routes replace 1 placeholder in index.php
- **Approximate total new/changed LOC**: ~550-650 lines
