# Chunk 7.1 — Embed Pencil Editor in LiteCMS Admin
## Detailed Implementation Plan

---

## Overview

This chunk ports the Pencil visual design editor (a self-contained browser SPA using CanvasKit/Skia WASM for rendering) into LiteCMS's admin panel. The editor is currently distributed as a VS Code extension webview. We copy its static files, create an IPC bridge that replaces the VS Code `window.vscodeapi` with `fetch()` calls to a PHP backend, build a `DesignController` for `.pen` file I/O, and wire it into the admin navigation.

At completion, administrators can navigate to `/admin/design/editor`, see a Figma-like canvas editor, create/edit design files, and save them to the server as `.pen` JSON files.

---

## Input Prerequisites

- **Phase 6 complete**: Element-based page builder working (Chunks 6.1–6.6)
- **Pencil editor SPA files**: Located at `%APPDATA%/Code/User/globalStorage/highagency.pencildev/editor/` containing:
  - `index.html` (SPA entry point)
  - `assets/index.js` (~7MB, editor bundle)
  - `assets/index.css` (~122KB, editor styles)
  - `assets/pencil.wasm` (~8MB, CanvasKit/Skia WASM binary)
  - `assets/browserAll.js`, `browserAll2.js` (browser workers)
  - `assets/webworkerAll.js`, `webworkerAll2.js` (web workers)
  - `images/` (logos and design kit thumbnails)

---

## Key Concepts

### IPC Protocol

The Pencil editor communicates with its host via a global `window.vscodeapi` object that the host injects before the editor bundle loads. The editor uses:

- **`ipc.notify(method, payload)`** — One-way messages (editor → host): `initialized`, `file-changed`, `load-file`, `send-prompt`, etc.
- **`ipc.request(method, payload)`** — Request/response (editor → host → editor): `get-recent-files`, `get-license`, `import-uri`, etc.
- **Event listeners (host → editor)**: `file-update`, `file-error`, `color-theme-changed`, `fullscreen-change`, etc.

The bridge script intercepts these calls and routes file operations (`load-file`, `file-changed`/save) through `fetch()` to the PHP backend.

### Editor Environment Detection

The editor checks `typeof window !== "undefined" && window.vscodeapi && window.canvaskitWasm` to detect the VS Code environment. Our bridge must set both globals before the editor bundle loads.

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `designs/` directory

**Purpose**: Storage directory for `.pen` design files.

Create with a `.gitkeep` file to ensure Git tracks the empty directory. This is parallel to `storage/` — it holds user-created design files on the server.

```
designs/.gitkeep
```

**Notes**:
- `.pen` files are JSON — plain text, Git-friendly.
- The DesignController reads/writes from this directory.
- Add `designs/*.pen` to `.gitignore` if user designs shouldn't be committed (optional — depends on deployment model).

---

### 2. `public/assets/pencil-editor/` — Editor SPA files (copied)

**Purpose**: Static files for the Pencil editor SPA, served directly by the web server.

**Action**: Copy the entire editor directory from the VS Code extension into the project:

```
public/assets/pencil-editor/
├── index.html          ← PATCHED (see below)
├── assets/
│   ├── index.js        ← Copied as-is (~7MB)
│   ├── index.css       ← Copied as-is (~122KB)
│   ├── pencil.wasm     ← Copied as-is (~8MB)
│   ├── browserAll.js   ← Copied as-is
│   ├── browserAll2.js  ← Copied as-is
│   ├── webworkerAll.js ← Copied as-is
│   └── webworkerAll2.js← Copied as-is
└── images/
    └── *.png, *.svg    ← Copied as-is (logos, design kit thumbnails)
```

**Patched `index.html`**:

The original `index.html` must be modified to:
1. **Remove the CSP meta tag** — the existing CSP is too restrictive for our use. We control CSP via PHP response headers instead.
2. **Inject the bridge script** before the editor bundle — the bridge must define `window.vscodeapi` and `window.canvaskitWasm` before `index.js` loads.
3. **Add a query-param reader** so the host page can pass the `.pen` file path and base URL.

```html
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="google" content="notranslate">
    <title>Design Editor</title>
    <!-- Bridge script MUST load before editor bundle -->
    <script src="../js/pencil-bridge.js"></script>
    <script type="module" crossorigin src="./assets/index.js"></script>
    <link rel="stylesheet" crossorigin href="./assets/index.css">
  </head>
  <body>
    <div id="root"></div>
  </body>
</html>
```

**Notes**:
- The bridge script is loaded as a regular (non-module) script so it executes synchronously before the module script.
- `../js/pencil-bridge.js` resolves to `public/assets/js/pencil-bridge.js` (the project's JS directory).
- The `.map` files (sourcemaps) are NOT copied — they are large and unnecessary in production.

---

### 3. `public/assets/js/pencil-bridge.js`

**Purpose**: IPC bridge that mocks the VS Code API (`window.vscodeapi`) and intercepts postMessage calls from the editor. Routes file I/O operations to the PHP backend via `fetch()`. Also provides the WASM loader reference.

**Design**:
- Executes immediately (IIFE) before the editor module loads.
- Creates `window.vscodeapi` as a mock object with a `postMessage(msg)` method.
- Creates `window.canvaskitWasm` pointing to the WASM file path.
- Sets `window.PENCIL_BRIDGE_BASE` to the LiteCMS admin base URL (read from parent iframe's `data-base-url` attribute or query param).
- Listens for messages from the editor, routes them to PHP endpoints.
- Provides a way for the parent page to send messages into the editor iframe.

**IPC Message Flow**:

```
Editor (iframe)  →  postMessage  →  Bridge (pencil-bridge.js)  →  fetch()  →  PHP DesignController
                 ←  postMessage  ←                              ←  JSON     ←
```

**Key methods handled by the bridge**:

| Editor Method | Bridge Action |
|---|---|
| `initialized` | Log ready state, send `color-theme-changed` back with current theme |
| `load-file` | `GET /admin/design/load?path={path}` → on success, send `file-update` back to editor |
| `file-changed` | `POST /admin/design/save` with `{path, content}` → persist to disk |
| `import-uri` | `POST /admin/design/import-file` with file data → save asset, return URL |
| `get-recent-files` | Return empty array (not needed in LiteCMS context) |
| `get-license` | Return a stub license object (editor runs locally, no license needed) |
| `get-fullscreen` | Return `false` |

**Implementation**:

```javascript
// public/assets/js/pencil-bridge.js
(function() {
    'use strict';

    // Read configuration from query params or parent iframe data attributes
    const params = new URLSearchParams(window.location.search);
    const baseUrl = params.get('baseUrl') || '';
    const filePath = params.get('filePath') || '';
    const csrfToken = params.get('csrf') || '';

    // Pending request callbacks: id → {resolve, reject}
    const pendingRequests = {};
    let requestIdCounter = 0;

    // ---- Mock vscodeapi ----

    /**
     * The editor calls window.vscodeapi.postMessage(msg) to communicate.
     * Messages have shape: { id?, type, method, payload, source }
     *   - type: "request" (expects response), "notification" (fire-and-forget)
     *   - source: "application" (from editor)
     *   - method: the action name
     *   - payload: action-specific data
     */
    window.vscodeapi = {
        postMessage: function(msg) {
            handleEditorMessage(msg);
        }
    };

    // ---- WASM Loader ----

    // Point to the local WASM file for CanvasKit/Skia
    // The editor checks window.canvaskitWasm to know where to load the binary from
    window.canvaskitWasm = './assets/pencil.wasm';

    // ---- Message Handler ----

    function handleEditorMessage(msg) {
        if (!msg || !msg.method) return;

        const method = msg.method;
        const payload = msg.payload || {};
        const id = msg.id;
        const type = msg.type; // "request" or "notification"

        switch (method) {
            case 'initialized':
                onEditorInitialized();
                break;

            case 'load-file':
                loadFile(payload.path || filePath);
                break;

            case 'file-changed':
                saveFile(payload);
                break;

            case 'import-uri':
                importFile(id, payload);
                break;

            case 'get-recent-files':
                respondToEditor(id, []);
                break;

            case 'get-license':
                respondToEditor(id, {
                    email: 'admin@litecms.local',
                    status: 'active',
                    plan: 'pro'
                });
                break;

            case 'get-fullscreen':
                respondToEditor(id, false);
                break;

            case 'toggle-design-mode':
            case 'toggle-theme':
            case 'clear-recent-files':
            case 'telemetry':
            case 'claude-disconnect':
            case 'claude-status-help-triggered':
            case 'set-license':
            case 'set-left-sidebar-visible':
            case 'send-prompt':
            case 'agent-stop':
            case 'desktop-update-install':
                // Silently ignore — not needed in LiteCMS context
                break;

            default:
                console.log('[pencil-bridge] Unhandled message:', method, payload);
                // For unknown requests, respond with null to avoid hanging
                if (type === 'request' && id) {
                    respondToEditor(id, null);
                }
                break;
        }
    }

    // ---- Editor Communication Helpers ----

    /**
     * Send a message to the editor.
     * The editor listens on window 'message' event.
     */
    function sendToEditor(method, payload, id) {
        const msg = {
            method: method,
            payload: payload,
            source: 'vscode-extension'
        };
        if (id !== undefined) {
            msg.id = id;
            msg.type = 'response';
        } else {
            msg.type = 'notification';
        }
        // The editor listens on window's message event
        window.postMessage(msg, '*');
    }

    /**
     * Respond to a request from the editor (by id).
     */
    function respondToEditor(id, data) {
        if (id === undefined || id === null) return;
        sendToEditor(undefined, data, id);
    }

    // ---- File Operations ----

    function onEditorInitialized() {
        console.log('[pencil-bridge] Editor initialized');

        // Send theme to match the admin panel (dark)
        sendToEditor('color-theme-changed', { theme: 'dark' });

        // If a file path was specified, trigger a load
        if (filePath) {
            loadFile(filePath);
        }

        // Notify parent page that editor is ready
        if (window.parent !== window) {
            window.parent.postMessage({
                source: 'pencil-bridge',
                event: 'editor-ready'
            }, '*');
        }
    }

    function loadFile(path) {
        if (!path) return;

        fetch(baseUrl + '/admin/design/load?path=' + encodeURIComponent(path), {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin'
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Load failed: ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (data.success && data.content) {
                // Send file content to editor
                sendToEditor('file-update', {
                    path: path,
                    content: data.content,
                    type: 'pen'
                });
            } else {
                sendToEditor('file-error', {
                    path: path,
                    error: data.error || 'Failed to load file'
                });
            }
        })
        .catch(function(err) {
            console.error('[pencil-bridge] Load error:', err);
            sendToEditor('file-error', {
                path: path,
                error: err.message
            });
        });
    }

    function saveFile(payload) {
        const path = payload.path || filePath;
        const content = payload.content;

        if (!path || content === undefined) {
            console.warn('[pencil-bridge] Save called without path or content');
            return;
        }

        fetch(baseUrl + '/admin/design/save', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                path: path,
                content: content,
                _csrf_token: csrfToken
            })
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Save failed: ' + response.status);
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                // Notify parent page of successful save
                if (window.parent !== window) {
                    window.parent.postMessage({
                        source: 'pencil-bridge',
                        event: 'file-saved',
                        path: path
                    }, '*');
                }
            } else {
                console.error('[pencil-bridge] Save error:', data.error);
            }
        })
        .catch(function(err) {
            console.error('[pencil-bridge] Save error:', err);
        });
    }

    function importFile(requestId, payload) {
        // For image/asset imports — upload to the server and return the URL
        const uri = payload.uri || payload.url;
        if (!uri) {
            respondToEditor(requestId, { error: 'No URI provided' });
            return;
        }

        fetch(baseUrl + '/admin/design/import-file', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                uri: uri,
                _csrf_token: csrfToken
            })
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Import failed: ' + response.status);
            return response.json();
        })
        .then(function(data) {
            respondToEditor(requestId, data);
        })
        .catch(function(err) {
            console.error('[pencil-bridge] Import error:', err);
            respondToEditor(requestId, { error: err.message });
        });
    }

    // ---- Parent Page Communication ----

    // Listen for messages from the parent admin page (outside the iframe)
    window.addEventListener('message', function(event) {
        if (!event.data || event.data.source !== 'litecms-admin') return;

        const action = event.data.action;
        const payload = event.data.payload || {};

        switch (action) {
            case 'load-file':
                loadFile(payload.path);
                break;

            case 'set-theme':
                sendToEditor('color-theme-changed', {
                    theme: payload.theme || 'dark'
                });
                break;

            case 'save':
                // Trigger a save (the editor will send file-changed)
                sendToEditor('save', {});
                break;
        }
    });

})();
```

**Notes**:
- The IIFE executes synchronously before the editor module.
- `window.vscodeapi` and `window.canvaskitWasm` are set as plain globals (not inside a module).
- CSRF token is passed via query param from the parent page (the admin template injects it).
- The bridge communicates with the parent page (hosting iframe) via `window.parent.postMessage()` for status updates.

---

### 4. `app/Admin/DesignController.php`

**Purpose**: PHP backend for design file I/O. Handles loading, saving, and importing assets for `.pen` files. Also renders the editor admin page.

**Class**: `App\Admin\DesignController`

**Design**:
- Follows the same patterns as `LayoutController`, `ContentController`, etc.
- Constructor receives `App $app`.
- All methods receive `Request $request` and return `Response`.
- File operations are restricted to the `designs/` directory (path traversal prevention).
- JSON responses for AJAX endpoints, HTML responses for page rendering.

**Public API**:

```php
__construct(App $app)

editor(Request $request): Response
    // GET /admin/design/editor — Renders the admin page with editor iframe
    // Query params: ?file=path/to/file.pen (optional)

load(Request $request): Response
    // GET /admin/design/load — Returns .pen file content as JSON
    // Query params: ?path=filename.pen
    // Response: {"success": true, "content": "...pen JSON string..."}

save(Request $request): Response
    // POST /admin/design/save — Saves .pen file content
    // Body: {"path": "filename.pen", "content": "...pen JSON string...", "_csrf_token": "..."}
    // Response: {"success": true}

importFile(Request $request): Response
    // POST /admin/design/import-file — Handles image/asset import
    // Body: {"uri": "data:image/png;base64,...", "_csrf_token": "..."}
    // Response: {"success": true, "url": "/assets/uploads/design/abc123.png"}

list(Request $request): Response
    // GET /admin/design/list — Returns list of .pen files as JSON
    // Response: {"success": true, "files": [{"name": "...", "path": "...", "modified": "..."}]}
```

**Implementation details**:

```
PROPERTIES:
  - private App $app
  - private string $designsDir   // Absolute path to designs/ directory

CONSTRUCTOR:
  __construct(App $app)
    $this->app = $app
    $this->designsDir = dirname(__DIR__, 2) . '/designs'

METHODS:

  editor(Request $request): Response
    1. $file = $request->query('file', '')
    2. $csrfToken = Session::get('_csrf_token')
    3. Render 'admin/design/editor' template with:
       - title: 'Design Editor'
       - activeNav: 'design-editor'
       - file: $file
       - csrfToken: $csrfToken
       - designFiles: $this->getDesignFileList()
    4. Return Response with security headers
       NOTE: CSP must allow 'unsafe-eval' and 'unsafe-inline' for editor WASM + canvas

  load(Request $request): Response
    1. $path = $request->query('path', '')
    2. Validate: $safePath = $this->sanitizePath($path)
       If null → return JSON error 400
    3. $fullPath = $this->designsDir . '/' . $safePath
    4. If file doesn't exist:
       - If it looks like a new file request → return JSON {"success": true, "content": null}
       - Otherwise → return JSON error 404
    5. $content = file_get_contents($fullPath)
    6. Return Response::json(["success" => true, "content" => $content])

  save(Request $request): Response
    1. Validate CSRF token
    2. Read JSON body: $body = json_decode(file_get_contents('php://input'), true)
    3. $path = $body['path'] ?? ''
    4. $content = $body['content'] ?? ''
    5. $safePath = $this->sanitizePath($path)
       If null → return JSON error 400
    6. $fullPath = $this->designsDir . '/' . $safePath
    7. Ensure parent directory exists: mkdir(dirname($fullPath), 0755, true)
    8. file_put_contents($fullPath, $content)
    9. Return Response::json(["success" => true])

  importFile(Request $request): Response
    1. Validate CSRF token
    2. Read JSON body
    3. $uri = $body['uri'] ?? ''
    4. If data URI:
       a. Parse MIME type and base64 data
       b. Validate MIME is image type (png, jpg, gif, webp, svg)
       c. Decode base64
       d. Generate random filename: bin2hex(random_bytes(16)) . '.' . $ext
       e. Save to public/assets/uploads/design/
       f. Return {"success": true, "url": "/assets/uploads/design/{filename}"}
    5. If URL:
       a. Return {"success": true, "url": $uri} (pass-through for external URLs)

  list(Request $request): Response
    1. Scan designs/ directory for *.pen files (recursive)
    2. Build array of {name, path, modified, size}
    3. Sort by modified descending
    4. Return Response::json(["success" => true, "files" => $files])

PRIVATE METHODS:

  sanitizePath(string $path): ?string
    1. Trim whitespace
    2. Remove leading/trailing slashes
    3. Reject if contains '..' (path traversal)
    4. Reject if contains null bytes
    5. Reject if doesn't end with '.pen'
    6. Reject if contains characters other than [a-zA-Z0-9_\-./]
    7. Return sanitized path or null on failure

  getDesignFileList(): array
    Scan designs/ for *.pen files, return array of [name, path, modified]

  withSecurityHeaders(Response $response): Response
    Add security headers. CSP MUST be relaxed for the editor:
    - script-src: 'self' 'unsafe-inline' 'unsafe-eval' (WASM requires eval)
    - worker-src: 'self' blob: data: (web workers)
    - style-src: 'self' 'unsafe-inline' https://fonts.googleapis.com
    - connect-src: 'self' https://fonts.gstatic.com https://fonts.googleapis.com
               https://unpkg.com https://images.unsplash.com
               https://hctfc8iexhqk0x3o.public.blob.vercel-storage.com
    - img-src: 'self' data: blob: https://images.unsplash.com
               https://*.public.blob.vercel-storage.com
    - font-src: 'self' data: blob: https://fonts.gstatic.com https://unpkg.com
               https://hctfc8iexhqk0x3o.public.blob.vercel-storage.com
    - child-src: 'self' blob:
    - X-Frame-Options: SAMEORIGIN (not DENY — the editor runs in an iframe)
```

---

### 5. `templates/admin/design/editor.php`

**Purpose**: Admin page that hosts the Pencil editor in an iframe. Provides a file selector, save button, and status indicators.

**Template**:

```php
<?php $this->layout('admin/layout'); ?>

<div class="design-editor-page">
    <div class="design-editor-toolbar">
        <h1 class="design-editor-title">Design Editor</h1>

        <div class="design-editor-controls">
            <!-- File selector -->
            <div class="design-file-select">
                <label for="design-file-path">File:</label>
                <select id="design-file-path" class="form-control form-control-sm">
                    <option value="">— New Design —</option>
                    <?php foreach ($designFiles as $df): ?>
                    <option value="<?= $this->e($df['path']) ?>"
                        <?= ($file ?? '') === $df['path'] ? 'selected' : '' ?>>
                        <?= $this->e($df['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- New file name input (shown when "New Design" selected) -->
            <div class="design-new-file" id="new-file-group">
                <input type="text" id="new-file-name"
                       class="form-control form-control-sm"
                       placeholder="my-design.pen"
                       pattern="[a-zA-Z0-9_\-]+\.pen">
            </div>

            <!-- Status indicator -->
            <span class="design-status" id="design-status">Ready</span>
        </div>
    </div>

    <!-- Editor iframe -->
    <div class="design-editor-frame-wrapper" id="editor-wrapper">
        <iframe
            id="pencil-editor-iframe"
            class="design-editor-iframe"
            data-base-url=""
            data-csrf-token="<?= $this->e($csrfToken) ?>"
            sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-modals"
            allowfullscreen
        ></iframe>
        <div class="design-editor-loading" id="editor-loading">
            <div class="loading-spinner"></div>
            <p>Loading Design Editor...</p>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const iframe = document.getElementById('pencil-editor-iframe');
    const fileSelect = document.getElementById('design-file-path');
    const newFileGroup = document.getElementById('new-file-group');
    const newFileInput = document.getElementById('new-file-name');
    const statusEl = document.getElementById('design-status');
    const loadingEl = document.getElementById('editor-loading');

    const csrfToken = iframe.dataset.csrfToken || '';
    let currentFile = <?= json_encode($file ?? '') ?>;

    // Build iframe URL with parameters
    function buildEditorUrl(filePath) {
        const base = '/assets/pencil-editor/index.html';
        const params = new URLSearchParams();
        params.set('baseUrl', '');
        params.set('csrf', csrfToken);
        if (filePath) {
            params.set('filePath', filePath);
        }
        return base + '?' + params.toString();
    }

    // Load editor with current file
    function loadEditor(filePath) {
        currentFile = filePath || '';
        loadingEl.style.display = 'flex';
        iframe.src = buildEditorUrl(filePath);
    }

    // Toggle new file input visibility
    function toggleNewFile() {
        const isNew = fileSelect.value === '';
        newFileGroup.style.display = isNew ? 'inline-block' : 'none';
    }

    // Listen for bridge messages from iframe
    window.addEventListener('message', function(event) {
        if (!event.data || event.data.source !== 'pencil-bridge') return;

        switch (event.data.event) {
            case 'editor-ready':
                loadingEl.style.display = 'none';
                statusEl.textContent = 'Ready';
                statusEl.className = 'design-status status-ready';
                break;

            case 'file-saved':
                statusEl.textContent = 'Saved';
                statusEl.className = 'design-status status-saved';
                setTimeout(function() {
                    statusEl.textContent = 'Ready';
                    statusEl.className = 'design-status status-ready';
                }, 2000);
                break;
        }
    });

    // File selector change
    fileSelect.addEventListener('change', function() {
        toggleNewFile();
        if (this.value) {
            loadEditor(this.value);
        }
    });

    // New file creation — load a blank editor, set file path for saves
    newFileInput.addEventListener('change', function() {
        let name = this.value.trim();
        if (!name) return;
        if (!name.endsWith('.pen')) name += '.pen';
        // Sanitize: only allow alphanumeric, dash, underscore, dot
        name = name.replace(/[^a-zA-Z0-9_\-\.]/g, '');
        this.value = name;
        loadEditor(name);
    });

    // Initial load
    toggleNewFile();
    loadEditor(currentFile);
})();
</script>
```

**Notes**:
- The iframe `sandbox` attribute includes `allow-scripts allow-same-origin` which are both required for WASM to work.
- The loading overlay hides once the bridge reports `editor-ready`.
- File selection triggers a full iframe reload with the new path in query params.

---

### 6. Update `public/index.php` — Add design routes

**Purpose**: Register admin routes for the design editor and file API.

**Changes**: Add inside the existing `/admin` route group:

```php
// Design Editor (Chunk 7.1)
$router->get('/design/editor', [App\Admin\DesignController::class, 'editor']);
$router->get('/design/load', [App\Admin\DesignController::class, 'load']);
$router->post('/design/save', [App\Admin\DesignController::class, 'save']);
$router->post('/design/import-file', [App\Admin\DesignController::class, 'importFile']);
$router->get('/design/list', [App\Admin\DesignController::class, 'list']);
```

**Notes**:
- These routes are inside the admin group, so they're protected by auth middleware.
- The AJAX endpoints (`load`, `save`, `import-file`, `list`) return JSON.
- The `editor` route returns the full admin page with the embedded iframe.

---

### 7. Update `templates/admin/layout.php` — Add nav link

**Purpose**: Add a "Design Editor" link in the admin sidebar under the Design section.

**Change**: In the Design section of the sidebar navigation (after "Master Style" and "Layouts"), add:

```php
<a href="/admin/design/editor"
   class="<?= ($activeNav ?? '') === 'design-editor' ? 'active' : '' ?>">
    <span class="nav-icon">&#9998;</span> Design Editor
</a>
```

**Notes**:
- Uses the pencil character (✎ / `&#9998;`) as the nav icon — fitting for a design editor.
- `activeNav` value is `'design-editor'` — set by `DesignController::editor()`.
- Placed under the existing "Design" nav section alongside "Master Style" and "Layouts".

---

### 8. Update `public/assets/css/admin.css` — Add editor styles

**Purpose**: Styles for the design editor page: toolbar, iframe container, loading overlay, status indicators.

**New CSS rules** (append to existing file):

```css
/* ============================================
   Design Editor (Chunk 7.1)
   ============================================ */

.design-editor-page {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 60px); /* full height minus admin header */
    margin: -24px; /* negate the .admin-content padding */
}

.design-editor-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    background: var(--color-card);
    border-bottom: 1px solid var(--color-border);
    flex-shrink: 0;
    gap: 16px;
    flex-wrap: wrap;
}

.design-editor-title {
    font-size: 1.1rem;
    font-weight: 600;
    margin: 0;
    white-space: nowrap;
}

.design-editor-controls {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.design-file-select {
    display: flex;
    align-items: center;
    gap: 6px;
}

.design-file-select label {
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--color-text-muted);
    white-space: nowrap;
}

.design-file-select select {
    min-width: 200px;
    max-width: 350px;
}

.design-new-file input {
    width: 200px;
}

.design-status {
    font-size: 0.8rem;
    padding: 3px 10px;
    border-radius: 12px;
    font-weight: 500;
    white-space: nowrap;
}

.design-status.status-ready {
    background: var(--color-info-bg, #e3f2fd);
    color: var(--color-info, #1976d2);
}

.design-status.status-saved {
    background: var(--color-success-bg, #e8f5e9);
    color: var(--color-success, #2e7d32);
}

.design-status.status-error {
    background: var(--color-error-bg, #ffebee);
    color: var(--color-error, #c62828);
}

.design-editor-frame-wrapper {
    flex: 1;
    position: relative;
    overflow: hidden;
    background: #1a1a2e; /* dark background matching editor */
}

.design-editor-iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
}

.design-editor-loading {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #1a1a2e;
    color: #a0a0b0;
    z-index: 10;
}

.design-editor-loading .loading-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid rgba(255,255,255,0.1);
    border-top-color: var(--color-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-bottom: 16px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.design-editor-loading p {
    font-size: 0.95rem;
    margin: 0;
}
```

---

### 9. `public/assets/uploads/design/.htaccess`

**Purpose**: Prevent script execution in the design uploads directory (same pattern as media uploads).

```apache
# Disable script execution
<FilesMatch "\.(?:php|phtml|php3|php4|php5|phps|cgi|pl|py|sh|bash)$">
    Require all denied
</FilesMatch>

# Allow only image types
<FilesMatch "\.(?:jpg|jpeg|png|gif|webp|svg)$">
    Require all granted
</FilesMatch>
```

---

### 10. `tests/chunk-7.1-verify.php`

**Purpose**: Verification test script for Chunk 7.1.

**Test cases**:

```
Test  1: DesignController class exists and is autoloadable
Test  2: DesignController has required methods (editor, load, save, importFile, list)
Test  3: designs/ directory exists
Test  4: public/assets/pencil-editor/index.html exists (patched)
Test  5: public/assets/pencil-editor/assets/index.js exists (editor bundle)
Test  6: public/assets/pencil-editor/assets/pencil.wasm exists (WASM binary)
Test  7: public/assets/pencil-editor/assets/index.css exists
Test  8: public/assets/js/pencil-bridge.js exists and contains vscodeapi mock
Test  9: Patched index.html references pencil-bridge.js (bridge script tag)
Test 10: Patched index.html does NOT contain original CSP meta tag
Test 11: templates/admin/design/editor.php exists
Test 12: Editor template contains iframe element
Test 13: Editor template contains file selector
Test 14: Admin layout sidebar contains "Design Editor" link
Test 15: Route GET /admin/design/editor resolves to DesignController::editor
Test 16: Route GET /admin/design/load resolves to DesignController::load
Test 17: Route POST /admin/design/save resolves to DesignController::save
Test 18: Route POST /admin/design/import-file resolves to DesignController::importFile
Test 19: Route GET /admin/design/list resolves to DesignController::list
Test 20: DesignController::sanitizePath blocks path traversal ('../../etc/passwd')
Test 21: DesignController::sanitizePath blocks non-.pen extensions
Test 22: DesignController::sanitizePath blocks null bytes
Test 23: DesignController::sanitizePath accepts valid paths ('my-design.pen')
Test 24: DesignController::save writes file to designs/ directory
Test 25: DesignController::load reads file from designs/ directory
Test 26: DesignController::list returns array of .pen files
Test 27: public/assets/uploads/design/ directory exists with .htaccess
Test 28: Editor worker files exist (browserAll.js, webworkerAll.js, etc.)
Test 29: admin.css contains design-editor-page styles
Test 30: pencil-bridge.js contains canvaskitWasm global assignment
```

---

## Detailed Class Specification

### `App\Admin\DesignController`

```
PROPERTIES:
  - private App $app
  - private string $designsDir

CONSTRUCTOR:
  __construct(App $app)
    $this->app = $app
    $this->designsDir = dirname(__DIR__, 2) . '/designs'
    // Ensure designs dir exists
    if (!is_dir($this->designsDir)) {
        mkdir($this->designsDir, 0755, true);
    }

METHODS:

  public editor(Request $request): Response
    PURPOSE: Render the admin page that hosts the Pencil editor in an iframe.
    PARAMS: $request — Request object
    RETURNS: Response with rendered HTML
    LOGIC:
      1. $file = (string) $request->query('file', '')
      2. $csrfToken = Session::get('_csrf_token', '')
      3. $designFiles = $this->getDesignFileList()
      4. $html = $this->app->template()->render('admin/design/editor', [
             'title' => 'Design Editor',
             'activeNav' => 'design-editor',
             'file' => $file,
             'csrfToken' => $csrfToken,
             'designFiles' => $designFiles,
         ])
      5. $response = new Response($html)
      6. return $this->withSecurityHeaders($response)

  public load(Request $request): Response
    PURPOSE: Read a .pen file and return its content as JSON.
    PARAMS: $request — Request with 'path' query param
    RETURNS: JSON response with file content
    LOGIC:
      1. $path = (string) $request->query('path', '')
      2. $safePath = $this->sanitizePath($path)
      3. If $safePath === null:
           return Response::json(['success' => false, 'error' => 'Invalid path'], 400)
      4. $fullPath = $this->designsDir . '/' . $safePath
      5. If !file_exists($fullPath):
           return Response::json(['success' => true, 'content' => null])
           // Null content signals "new file" — editor will start with blank canvas
      6. $content = file_get_contents($fullPath)
      7. return Response::json(['success' => true, 'content' => $content])

  public save(Request $request): Response
    PURPOSE: Write .pen file content to disk.
    PARAMS: $request — Request with JSON body {path, content, _csrf_token}
    RETURNS: JSON response
    LOGIC:
      1. $body = json_decode(file_get_contents('php://input'), true)
      2. If !$body: return Response::json(['success' => false, 'error' => 'Invalid request'], 400)
      3. // CSRF validation
         $token = $body['_csrf_token'] ?? ''
         If $token !== Session::get('_csrf_token', ''):
           return Response::json(['success' => false, 'error' => 'CSRF validation failed'], 403)
      4. $path = (string) ($body['path'] ?? '')
      5. $content = $body['content'] ?? ''
      6. $safePath = $this->sanitizePath($path)
      7. If $safePath === null:
           return Response::json(['success' => false, 'error' => 'Invalid path'], 400)
      8. $fullPath = $this->designsDir . '/' . $safePath
      9. // Ensure parent directory exists (for subdirectories like designs/pages/)
         $dir = dirname($fullPath)
         if (!is_dir($dir)) mkdir($dir, 0755, true)
      10. // Content may be a string (JSON) or array — normalize
          if (is_array($content) || is_object($content)):
              $content = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
          $content is a string at this point
      11. file_put_contents($fullPath, $content, LOCK_EX)
      12. return Response::json(['success' => true])

  public importFile(Request $request): Response
    PURPOSE: Import an image/asset file (from data URI or URL) for use in designs.
    PARAMS: $request — Request with JSON body {uri, _csrf_token}
    RETURNS: JSON response with URL of the saved file
    LOGIC:
      1. $body = json_decode(file_get_contents('php://input'), true)
      2. CSRF validation (same as save)
      3. $uri = (string) ($body['uri'] ?? '')
      4. If empty: return Response::json(['success' => false, 'error' => 'No URI'], 400)
      5. If starts with 'data:':
           a. Parse: preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,(.+)$#', $uri, $m)
           b. If no match: return error 400 (unsupported format)
           c. $ext = $m[1] === 'jpeg' ? 'jpg' : ($m[1] === 'svg+xml' ? 'svg' : $m[1])
           d. $data = base64_decode($m[2], true)
           e. If $data === false: return error 400 (invalid base64)
           f. $filename = bin2hex(random_bytes(16)) . '.' . $ext
           g. $uploadDir = dirname(__DIR__, 2) . '/public/assets/uploads/design'
              if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true)
           h. file_put_contents($uploadDir . '/' . $filename, $data)
           i. return Response::json(['success' => true, 'url' => '/assets/uploads/design/' . $filename])
      6. If starts with 'http':
           // External URL — pass through (editor can use it directly)
           return Response::json(['success' => true, 'url' => $uri])
      7. Otherwise: return error 400

  public list(Request $request): Response
    PURPOSE: List all .pen files in the designs directory.
    PARAMS: $request — Request object
    RETURNS: JSON response with file listing
    LOGIC:
      1. $files = $this->getDesignFileList()
      2. return Response::json(['success' => true, 'files' => $files])

PRIVATE METHODS:

  private sanitizePath(string $path): ?string
    PURPOSE: Validate and sanitize a file path to prevent directory traversal.
    PARAMS: $path — raw path from user input
    RETURNS: sanitized path or null if invalid
    LOGIC:
      1. $path = trim($path)
      2. If empty: return null
      3. // Remove leading slashes
         $path = ltrim($path, '/\\')
      4. // Block path traversal
         If str_contains($path, '..') return null
      5. // Block null bytes
         If str_contains($path, "\0") return null
      6. // Must end with .pen
         If !str_ends_with($path, '.pen') return null
      7. // Only allow safe characters
         If !preg_match('#^[a-zA-Z0-9_\-/]+\.pen$#', $path) return null
      8. return $path

  private getDesignFileList(): array
    PURPOSE: Scan designs/ directory for .pen files.
    RETURNS: Array of ['name' => string, 'path' => string, 'modified' => string, 'size' => int]
    LOGIC:
      1. If !is_dir($this->designsDir): return []
      2. $files = []
      3. $iterator = new RecursiveIteratorIterator(
             new RecursiveDirectoryIterator($this->designsDir, FilesystemIterator::SKIP_DOTS)
         )
      4. foreach ($iterator as $fileInfo):
           If $fileInfo->getExtension() !== 'pen': continue
           $relativePath = str_replace($this->designsDir . '/', '', $fileInfo->getPathname())
           // Normalize path separators for Windows
           $relativePath = str_replace('\\', '/', $relativePath)
           $files[] = [
               'name' => $fileInfo->getBasename('.pen'),
               'path' => $relativePath,
               'modified' => date('Y-m-d H:i:s', $fileInfo->getMTime()),
               'size' => $fileInfo->getSize(),
           ]
      5. usort($files, fn($a, $b) => strcmp($b['modified'], $a['modified']))
      6. return $files

  private withSecurityHeaders(Response $response): Response
    PURPOSE: Add CSP and security headers. Must be relaxed for Pencil editor.
    LOGIC:
      $csp = "default-src 'self'; "
           . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
           . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
           . "connect-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com "
           .   "https://unpkg.com https://images.unsplash.com "
           .   "https://hctfc8iexhqk0x3o.public.blob.vercel-storage.com; "
           . "img-src 'self' data: blob: https://images.unsplash.com "
           .   "https://*.public.blob.vercel-storage.com; "
           . "font-src 'self' data: blob: https://fonts.gstatic.com https://unpkg.com "
           .   "https://hctfc8iexhqk0x3o.public.blob.vercel-storage.com; "
           . "worker-src 'self' blob: data:; "
           . "child-src 'self' blob:; "
           . "frame-src 'self'"
      return $response
          ->withHeader('Content-Security-Policy', $csp)
          ->withHeader('X-Frame-Options', 'SAMEORIGIN')
          ->withHeader('X-Content-Type-Options', 'nosniff')
          ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
```

---

## Implementation Notes

### File Copy Strategy

The Pencil editor SPA files (~36MB total) are copied once during project setup. They are NOT downloaded at runtime. The copy process:

1. Source: `%APPDATA%/Code/User/globalStorage/highagency.pencildev/editor/`
2. Destination: `public/assets/pencil-editor/`
3. Copy everything EXCEPT `.map` files (source maps — 20MB+ savings)
4. Patch `index.html` (replace CSP meta tag, inject bridge script)

This should be done as a one-time setup step (could be a PHP script or manual copy). The plan includes a helper script for this.

### Copy Helper Script

Create `scripts/copy-pencil-editor.php`:

```php
<?php declare(strict_types=1);
/**
 * One-time setup script: copies Pencil editor SPA files from the VS Code extension
 * to the public directory and patches index.html for LiteCMS integration.
 *
 * Usage: php scripts/copy-pencil-editor.php
 */

$appdata = getenv('APPDATA');
if (!$appdata) {
    // macOS/Linux fallback
    $appdata = getenv('HOME') . '/.vscode/extensions';
}
$source = $appdata . '/Code/User/globalStorage/highagency.pencildev/editor';
$dest = __DIR__ . '/../public/assets/pencil-editor';

if (!is_dir($source)) {
    echo "ERROR: Pencil editor not found at: $source\n";
    echo "Please install the Pencil VS Code extension first.\n";
    exit(1);
}

// Create destination
if (!is_dir($dest)) {
    mkdir($dest, 0755, true);
}
if (!is_dir($dest . '/assets')) {
    mkdir($dest . '/assets', 0755, true);
}
if (!is_dir($dest . '/images')) {
    mkdir($dest . '/images', 0755, true);
}

// Copy assets (skip .map files)
$assets = glob($source . '/assets/*');
foreach ($assets as $file) {
    if (str_ends_with($file, '.map')) continue;
    $basename = basename($file);
    echo "Copying assets/$basename...\n";
    copy($file, $dest . '/assets/' . $basename);
}

// Copy images
$images = glob($source . '/images/*');
foreach ($images as $file) {
    $basename = basename($file);
    echo "Copying images/$basename...\n";
    copy($file, $dest . '/images/' . $basename);
}

// Create patched index.html
$patchedHtml = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="google" content="notranslate">
    <title>Design Editor</title>
    <script src="../js/pencil-bridge.js"></script>
    <script type="module" crossorigin src="./assets/index.js"></script>
    <link rel="stylesheet" crossorigin href="./assets/index.css">
  </head>
  <body>
    <div id="root"></div>
  </body>
</html>
HTML;

file_put_contents($dest . '/index.html', $patchedHtml);
echo "Created patched index.html\n";

echo "\nDone! Editor files copied to: $dest\n";
echo "Total size: " . round(array_sum(array_map('filesize',
    glob($dest . '/assets/*') ?: [])) / 1024 / 1024, 1) . " MB\n";
```

### WASM Loading

The Pencil editor uses CanvasKit (Skia compiled to WASM) for canvas rendering. The bridge sets `window.canvaskitWasm = './assets/pencil.wasm'` so the editor fetches the WASM file from its own assets directory (relative to the iframe's HTML file). This avoids any cross-origin issues.

### CSP Considerations

The editor requires a relaxed Content-Security-Policy because:
- **`unsafe-eval`**: Required by WASM compilation and some editor internals.
- **`unsafe-inline`**: Required by the editor's inline styles and script patterns.
- **`worker-src blob:`**: Web workers are loaded from blob URLs.
- **External font/image sources**: Editor fetches Google Fonts and Unsplash images.

The CSP is set ONLY on the editor page (`/admin/design/editor`). Other admin pages retain the standard restrictive CSP.

### iframe Sandbox

The iframe `sandbox` attribute must include:
- `allow-scripts` — WASM and JS execution
- `allow-same-origin` — fetch() calls to the PHP backend (same origin)
- `allow-popups` — editor dialogs/modals
- `allow-forms` — editor form elements
- `allow-modals` — alert/confirm dialogs

### CSRF Token Handling

The CSRF token is passed from the admin template to the iframe via query params:
1. Admin page reads `Session::get('_csrf_token')` and embeds it in the iframe URL.
2. Bridge script reads it from `URLSearchParams`.
3. Bridge sends it in `X-CSRF-Token` header and `_csrf_token` body field on every mutation request.
4. DesignController validates it against the session.

### Path Traversal Prevention

The `sanitizePath()` method is critical security — it prevents users from reading/writing files outside the `designs/` directory. It:
1. Rejects `..` sequences
2. Rejects null bytes
3. Requires `.pen` extension
4. Only allows `[a-zA-Z0-9_\-/]` characters
5. Strips leading slashes

### Windows Compatibility

On Windows, the `RecursiveDirectoryIterator` returns paths with `\` separators. The `getDesignFileList()` method normalizes these to `/` for consistent JSON output and URL construction.

---

## Edge Cases

1. **No .pen files exist yet**: The file selector shows "— New Design —" by default. User types a name and starts designing on a blank canvas.

2. **WASM fails to load**: If `pencil.wasm` is missing or corrupt, the editor will show a blank canvas / error. The loading overlay helps — if `editor-ready` is never received, the spinner stays visible (indicating something is wrong).

3. **Large .pen files**: Some designs can produce `.pen` files of 1-5MB. The `save` endpoint reads from `php://input` and writes with `LOCK_EX` to prevent corruption. PHP's `post_max_size` and `upload_max_filesize` settings may need adjustment for very large designs.

4. **Concurrent saves**: Using `LOCK_EX` on `file_put_contents` prevents partial writes from concurrent requests.

5. **Browser compatibility**: The editor requires:
   - WebAssembly support (all modern browsers)
   - WebGL2 (for Skia rendering)
   - Web Workers (for background processing)
   - Not supported: IE11, very old mobile browsers

6. **iframe communication timing**: The bridge must be loaded before the editor bundle. Using a synchronous `<script>` tag (not `type="module"`) ensures this.

7. **Editor theme sync**: The bridge sends `color-theme-changed` with `{theme: 'dark'}` on initialization to match the admin panel's dark sidebar aesthetic.

---

## Acceptance Test Procedures

### Test 1: Editor SPA files exist in project
```
1. Verify public/assets/pencil-editor/index.html exists
2. Verify public/assets/pencil-editor/assets/index.js exists (~7MB)
3. Verify public/assets/pencil-editor/assets/pencil.wasm exists (~8MB)
4. Verify public/assets/pencil-editor/assets/index.css exists (~122KB)
5. Verify worker files exist (browserAll.js, browserAll2.js, webworkerAll.js, webworkerAll2.js)
```

### Test 2: Editor loads in admin panel
```
1. Log in as admin
2. Navigate to /admin/design/editor
3. Verify the page loads with toolbar and iframe
4. Verify loading spinner shows, then disappears when editor is ready
5. Verify editor canvas renders (not blank/white — should show dark canvas background)
```

### Test 3: WASM loads successfully
```
1. Open browser DevTools → Console
2. Verify no WASM-related errors
3. Try creating a frame in the editor (click/drag on canvas)
4. Verify the frame renders with Skia rendering (smooth anti-aliased shapes)
```

### Test 4: Canvas interaction works
```
1. Create a frame (click and drag)
2. Create a text element
3. Select, move, and resize elements
4. Zoom in/out (scroll wheel / trackpad pinch)
5. Pan (space + drag, or middle mouse button)
6. Verify all interactions are responsive
```

### Test 5: Save works
```
1. Create some elements on the canvas
2. Trigger save (Ctrl+S or editor's save action)
3. Verify status indicator shows "Saved"
4. Check designs/ directory — a .pen file should exist
5. Verify the .pen file contains valid JSON
```

### Test 6: Load works
```
1. Save a design (from Test 5)
2. Reload the page (F5)
3. Verify the design is restored from the server
4. All elements should be in their original positions
```

### Test 7: Bridge mocks work
```
1. Open browser DevTools → Console
2. Verify no errors about "acquireVsCodeApi" or missing VS Code API
3. Verify "[pencil-bridge] Editor initialized" appears in console
```

### Test 8: IPC flow works end-to-end
```
1. Create a design and save
2. Monitor Network tab — verify:
   a. POST to /admin/design/save with .pen JSON body
   b. Response is {"success": true}
3. Reload page — verify:
   a. GET to /admin/design/load?path=... returns the saved .pen JSON
   b. Editor displays the loaded design
```

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `designs/.gitkeep` | Directory | Create |
| 2 | `public/assets/pencil-editor/index.html` | HTML | Create (patched) |
| 3 | `public/assets/pencil-editor/assets/index.js` | JS | Copy |
| 4 | `public/assets/pencil-editor/assets/index.css` | CSS | Copy |
| 5 | `public/assets/pencil-editor/assets/pencil.wasm` | WASM | Copy |
| 6 | `public/assets/pencil-editor/assets/browserAll.js` | JS | Copy |
| 7 | `public/assets/pencil-editor/assets/browserAll2.js` | JS | Copy |
| 8 | `public/assets/pencil-editor/assets/webworkerAll.js` | JS | Copy |
| 9 | `public/assets/pencil-editor/assets/webworkerAll2.js` | JS | Copy |
| 10 | `public/assets/pencil-editor/images/*` | Assets | Copy |
| 11 | `public/assets/js/pencil-bridge.js` | JS | Create |
| 12 | `app/Admin/DesignController.php` | PHP Class | Create |
| 13 | `templates/admin/design/editor.php` | Template | Create |
| 14 | `public/assets/uploads/design/.htaccess` | Config | Create |
| 15 | `scripts/copy-pencil-editor.php` | Script | Create |
| 16 | `public/index.php` | Entry point | Modify (add routes) |
| 17 | `templates/admin/layout.php` | Template | Modify (add nav link) |
| 18 | `public/assets/css/admin.css` | CSS | Modify (add styles) |
| 19 | `tests/chunk-7.1-verify.php` | Test | Create |

---

## Estimated Scope

- **PHP classes**: 1 (DesignController)
- **JavaScript files**: 1 new (pencil-bridge.js) + 1 helper script (copy-pencil-editor.php)
- **Templates**: 1 (admin/design/editor.php)
- **Copied files**: ~8 files from Pencil extension (~16MB without sourcemaps)
- **Modified files**: 3 (index.php, admin/layout.php, admin.css)
- **Tests**: 30 test cases
- **Approximate new PHP LOC**: ~200 lines
- **Approximate new JS LOC**: ~250 lines
- **Approximate new CSS LOC**: ~100 lines
