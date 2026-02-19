# Chunk 7.5 — Admin Integration & Preview
## Detailed Implementation Plan

---

## Overview

Wire the embedded Pencil editor into the content editing workflow. Add a "Design" tab alongside HTML/Elements in the content editor. When active, it shows the embedded editor with the content's `.pen` file, a preview panel with converted HTML, and a "Re-convert" button. Build a design file browser for managing `.pen` files.

**Key Insight**: Much of the backend already works from chunks 7.1–7.4. The `editor_mode` field already accepts `'design'`, `design_file` is stored in the content table, `DesignController` has load/save/convert endpoints, `FrontController` renders `.pen` files, and the bridge JS handles iframe communication. This chunk is primarily about **UI wiring** — connecting these existing pieces in the content editor and building the browser UI.

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `templates/admin/design/browser.php`

**Purpose**: Design file browser page — list, duplicate, delete `.pen` files.

**Template structure**:
```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Design Files</h1>
    <div>
        <a href="/admin/design/editor" class="btn btn-primary">+ New Design</a>
    </div>
</div>

<!-- Design file grid -->
<div class="design-browser-grid" id="design-browser">
    <!-- Each card -->
    <div class="design-browser-card" data-path="{path}">
        <div class="design-browser-thumb">
            <iframe src="/admin/design/preview?path={path}" sandbox="..." loading="lazy"></iframe>
        </div>
        <div class="design-browser-info">
            <span class="design-browser-name">{name}</span>
            <span class="design-browser-meta">{modified} &middot; {size}</span>
        </div>
        <div class="design-browser-actions">
            <a href="/admin/design/editor?file={path}" class="btn btn-sm">Edit</a>
            <button class="btn btn-sm" data-action="duplicate" data-path="{path}">Duplicate</button>
            <button class="btn btn-sm btn-danger" data-action="delete" data-path="{path}">Delete</button>
        </div>
    </div>
</div>
```

**Template variables**: `$title`, `$activeNav`, `$designFiles` (array), `$csrfToken`

**Features**:
- Grid layout showing all `.pen` files with iframe preview thumbnails
- "Edit" link opens the standalone design editor
- "Duplicate" button: prompts for new name, POSTs to `/admin/design/duplicate`
- "Delete" button: confirm dialog, POSTs to `/admin/design/delete`
- Inline `<script>` block handles duplicate/delete AJAX calls

---

### 2. `public/assets/js/design-mode-init.js`

**Purpose**: Handle the "Design" editor mode in the content edit page. Manages the embedded Pencil editor iframe, design file selection, preview panel, and re-convert workflow.

**Design**: Follows the same pattern as `page-builder-init.js` for mode toggling, plus adapts the iframe communication pattern from `templates/admin/design/editor.php`.

```javascript
document.addEventListener('DOMContentLoaded', function() {
    var htmlPanel = document.getElementById('html-editor-panel');
    var builderPanel = document.getElementById('page-builder-panel');
    var designPanel = document.getElementById('design-editor-panel');
    var modeRadios = document.querySelectorAll('input[name="editor_mode"]');
    var pageStylesCard = document.getElementById('page-styles-card');

    // Design mode elements
    var designFileInput = document.getElementById('design-file-input');
    var designFileSelect = document.getElementById('design-file-select');
    var designIframe = document.getElementById('design-editor-iframe');
    var designPreview = document.getElementById('design-preview-frame');
    var reconvertBtn = document.getElementById('design-reconvert-btn');
    var designStatus = document.getElementById('design-status');
    var designNewName = document.getElementById('design-new-name');

    var editorReady = false;
    var currentDesignFile = designFileInput ? designFileInput.value : '';

    // --- Mode toggle (extends page-builder-init.js) ---
    modeRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            htmlPanel.classList.toggle('hidden', this.value !== 'html');
            builderPanel.classList.toggle('hidden', this.value !== 'elements');
            designPanel.classList.toggle('hidden', this.value !== 'design');
            if (pageStylesCard) {
                pageStylesCard.style.display = this.value === 'elements' ? '' : 'none';
            }
            // Load editor when switching to design mode
            if (this.value === 'design' && designIframe && !designIframe.src) {
                loadDesignEditor(currentDesignFile);
            }
        });
    });

    // --- Build iframe URL ---
    function buildEditorUrl(filePath) {
        var base = '/assets/pencil-editor/index.html';
        var params = new URLSearchParams();
        params.set('baseUrl', '');
        var csrfMeta = document.querySelector('input[name="csrf_token"]');
        params.set('csrf', csrfMeta ? csrfMeta.value : '');
        if (filePath) params.set('filePath', filePath);
        return base + '?' + params.toString();
    }

    // --- Send message to bridge ---
    function sendToBridge(action, payload) {
        if (!designIframe || !designIframe.contentWindow) return;
        designIframe.contentWindow.postMessage({
            source: 'litecms-admin',
            action: action,
            payload: payload || {}
        }, '*');
    }

    // --- Load design editor iframe ---
    function loadDesignEditor(filePath) {
        editorReady = false;
        designIframe.src = buildEditorUrl(filePath);
        if (designStatus) designStatus.textContent = 'Loading...';
    }

    // --- File select change ---
    if (designFileSelect) {
        designFileSelect.addEventListener('change', function() {
            var selected = this.value;
            var isNew = selected === '__new__';
            if (designNewName) {
                designNewName.style.display = isNew ? 'inline-block' : 'none';
            }
            if (isNew) {
                currentDesignFile = '';
                designFileInput.value = '';
            } else {
                currentDesignFile = selected;
                designFileInput.value = selected;
                loadDesignEditor(selected);
            }
        });
    }

    // --- New file name input ---
    if (designNewName) {
        designNewName.addEventListener('input', function() {
            var name = this.value.trim();
            if (name && !name.endsWith('.pen')) name += '.pen';
            name = name.replace(/[^a-zA-Z0-9_\-\.]/g, '');
            currentDesignFile = name;
            designFileInput.value = name;
            if (editorReady && name) {
                sendToBridge('set-file-path', { path: name });
            }
        });
    }

    // --- Save design file (before form submit) ---
    function saveDesignFile(callback) {
        if (!currentDesignFile || !editorReady) {
            if (callback) callback();
            return;
        }
        sendToBridge('set-file-path', { path: currentDesignFile });
        sendToBridge('save');
        // Wait for save confirmation
        var onSaved = function(event) {
            if (event.data && event.data.source === 'pencil-bridge'
                && event.data.event === 'file-saved') {
                window.removeEventListener('message', onSaved);
                if (callback) callback();
            }
        };
        window.addEventListener('message', onSaved);
        // Timeout fallback
        setTimeout(function() {
            window.removeEventListener('message', onSaved);
            if (callback) callback();
        }, 5000);
    }

    // --- Re-convert button ---
    if (reconvertBtn) {
        reconvertBtn.addEventListener('click', function() {
            if (!currentDesignFile) return;
            reconvertBtn.disabled = true;
            reconvertBtn.textContent = 'Converting...';

            // First save the current design
            saveDesignFile(function() {
                // Then convert
                fetch('/admin/content/' + getContentId() + '/reconvert', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        csrf_token: getCsrfToken(),
                        design_file: currentDesignFile
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    reconvertBtn.disabled = false;
                    reconvertBtn.textContent = 'Re-convert to HTML';
                    if (data.success) {
                        if (designStatus) {
                            designStatus.textContent = 'Converted!';
                            designStatus.className = 'design-status status-saved';
                        }
                        // Update preview
                        refreshPreview();
                        // Update body textarea with new HTML
                        var bodyEl = document.getElementById('body');
                        if (bodyEl && data.html) bodyEl.value = data.html;
                    } else {
                        if (designStatus) {
                            designStatus.textContent = 'Error: ' + (data.error || 'Unknown');
                            designStatus.className = 'design-status status-error';
                        }
                    }
                })
                .catch(function() {
                    reconvertBtn.disabled = false;
                    reconvertBtn.textContent = 'Re-convert to HTML';
                });
            });
        });
    }

    // --- Preview refresh ---
    function refreshPreview() {
        if (!designPreview || !currentDesignFile) return;
        designPreview.src = '/admin/design/preview?path=' +
            encodeURIComponent(currentDesignFile) + '&t=' + Date.now();
    }

    // --- Listen for bridge messages ---
    window.addEventListener('message', function(event) {
        if (!event.data || event.data.source !== 'pencil-bridge') return;
        switch (event.data.event) {
            case 'editor-ready':
                editorReady = true;
                if (designStatus) {
                    designStatus.textContent = 'Ready';
                    designStatus.className = 'design-status status-ready';
                }
                if (currentDesignFile) {
                    sendToBridge('set-file-path', { path: currentDesignFile });
                }
                break;
            case 'file-saved':
                if (designStatus) {
                    designStatus.textContent = 'Saved';
                    designStatus.className = 'design-status status-saved';
                    setTimeout(function() {
                        designStatus.textContent = 'Ready';
                        designStatus.className = 'design-status status-ready';
                    }, 2000);
                }
                break;
        }
    });

    // --- Helpers ---
    function getContentId() {
        var form = document.getElementById('content-form');
        return form ? form.dataset.contentId : '';
    }
    function getCsrfToken() {
        var el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    // --- Intercept form submit to save design file first ---
    var contentForm = document.getElementById('content-form');
    if (contentForm) {
        contentForm.addEventListener('submit', function(e) {
            var mode = document.querySelector('input[name="editor_mode"]:checked');
            if (mode && mode.value === 'design' && currentDesignFile && editorReady) {
                e.preventDefault();
                saveDesignFile(function() {
                    contentForm.submit();
                });
            }
        });
    }

    // --- Initial load if already in design mode ---
    var checkedMode = document.querySelector('input[name="editor_mode"]:checked');
    if (checkedMode && checkedMode.value === 'design' && designIframe) {
        loadDesignEditor(currentDesignFile);
    }
});
```

**Key behaviors**:
- Extends the existing mode toggle to support three modes (html, elements, design)
- Lazy-loads the Pencil editor iframe only when switching to design mode
- Manages file path via hidden input `design_file` (submitted with form)
- Intercepts form submit to save the `.pen` file via bridge before POST
- Re-convert button: saves design, calls reconvert endpoint, updates preview + body
- Listens for bridge messages (`editor-ready`, `file-saved`)

---

### 3. Modify `templates/admin/content/edit.php`

**Purpose**: Add the "Design" editor mode tab and design editor panel.

**Changes**:

#### 3a. Add "Design Editor" radio option (after line 56)

```php
<label class="pb-mode-option">
    <input type="radio" name="editor_mode" value="design"
           <?= ($content['editor_mode'] ?? 'html') === 'design' ? 'checked' : '' ?>>
    <span>Design Editor</span>
</label>
```

#### 3b. Add design editor panel (after the page builder panel, after line 94)

```php
<!-- Design Editor Panel (visible when editor_mode = design) -->
<div id="design-editor-panel"
     class="<?= ($content['editor_mode'] ?? 'html') !== 'design' ? 'hidden' : '' ?>">

    <div class="design-mode-toolbar">
        <div class="design-file-selector">
            <label for="design-file-select">Design File:</label>
            <select id="design-file-select" class="form-control form-control-sm">
                <option value="__new__">-- New Design --</option>
                <?php foreach ($designFiles ?? [] as $df): ?>
                <option value="<?= $this->e($df['path']) ?>"
                    <?= ($designFile ?? '') === $df['path'] ? 'selected' : '' ?>>
                    <?= $this->e($df['name']) ?> (<?= $this->e($df['path']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="design-new-name"
                   class="form-control form-control-sm"
                   placeholder="my-design.pen"
                   style="<?= ($designFile ?? '') ? 'display:none;' : '' ?>">
        </div>

        <div class="design-mode-actions">
            <button type="button" id="design-reconvert-btn" class="btn btn-sm btn-primary">
                Re-convert to HTML
            </button>
            <a href="/admin/design/editor?file=<?= $this->e($designFile ?? '') ?>"
               target="_blank" class="btn btn-sm" id="design-open-full">
                Open Full Editor
            </a>
            <span class="design-status" id="design-status">Ready</span>
        </div>
    </div>

    <!-- Split view: Editor + Preview -->
    <div class="design-editor-split">
        <div class="design-editor-pane">
            <iframe id="design-editor-iframe"
                    class="design-editor-iframe"
                    sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-modals allow-downloads"
                    allowfullscreen></iframe>
        </div>
        <div class="design-preview-pane">
            <div class="design-preview-header">Preview</div>
            <iframe id="design-preview-frame"
                    class="design-preview-iframe"
                    <?php if ($designFile ?? ''): ?>
                    src="/admin/design/preview?path=<?= $this->e($designFile) ?>"
                    <?php endif; ?>
                    sandbox="allow-scripts allow-same-origin"
            ></iframe>
        </div>
    </div>

    <input type="hidden" name="design_file" id="design-file-input"
           value="<?= $this->e($designFile ?? '') ?>">
</div>
```

#### 3c. Add hidden `design_file` input for non-design modes

Already handled — the hidden input is inside the design panel. When mode is not design, ContentController ignores `design_file` because `$editorMode !== 'design'`.

#### 3d. Update the html-editor-panel visibility (line 63)

Change from:
```php
class="<?= ($content['editor_mode'] ?? 'html') === 'elements' ? 'hidden' : '' ?>"
```
To:
```php
class="<?= ($content['editor_mode'] ?? 'html') !== 'html' ? 'hidden' : '' ?>"
```

This ensures the HTML panel is hidden when design mode is active too.

#### 3e. Add `design-mode-init.js` script include (before closing `</form>` area, alongside other scripts)

```html
<script src="/assets/js/design-mode-init.js"></script>
```

---

### 4. Modify `public/assets/js/page-builder-init.js`

**Purpose**: Update mode toggle to support three modes (design hides both html and builder panels).

**Change**: Replace the toggle handler with three-way logic:

```javascript
modeRadios.forEach(function(radio) {
    radio.addEventListener('change', function() {
        var designPanel = document.getElementById('design-editor-panel');
        if (this.value === 'html') {
            htmlPanel.classList.remove('hidden');
            builderPanel.classList.add('hidden');
            if (designPanel) designPanel.classList.add('hidden');
            if (pageStylesCard) pageStylesCard.style.display = 'none';
        } else if (this.value === 'elements') {
            htmlPanel.classList.add('hidden');
            builderPanel.classList.remove('hidden');
            if (designPanel) designPanel.classList.add('hidden');
            if (pageStylesCard) pageStylesCard.style.display = '';
        } else if (this.value === 'design') {
            htmlPanel.classList.add('hidden');
            builderPanel.classList.add('hidden');
            if (designPanel) designPanel.classList.remove('hidden');
            if (pageStylesCard) pageStylesCard.style.display = 'none';
        }
    });
});
```

**Note**: The `design-mode-init.js` script also sets up mode toggle listeners, so we must ensure they don't conflict. The solution: `page-builder-init.js` handles panel visibility only (show/hide the three panels). `design-mode-init.js` handles design-specific logic (editor loading, bridge, reconvert). Both listen to the same radio change event — this is fine since both update different elements.

**Actually, simpler approach**: Remove the mode toggle from `page-builder-init.js` entirely and move it to `design-mode-init.js` which handles all three modes. This avoids duplicate listeners. Keep `page-builder-init.js` focused on just initializing the page builder.

Revised `page-builder-init.js`:
```javascript
document.addEventListener('DOMContentLoaded', function() {
    var builderPanel = document.getElementById('page-builder-panel');

    // Read config from data attributes on the builder panel
    if (builderPanel && typeof initPageBuilder === 'function') {
        var raw = builderPanel.getAttribute('data-instances') || '[]';
        var csrf = builderPanel.getAttribute('data-csrf') || '';
        var existingInstances = [];
        try {
            existingInstances = JSON.parse(raw);
        } catch (e) {
            existingInstances = [];
        }

        var templateBlocks = [];
        var blocksRaw = builderPanel.getAttribute('data-template-blocks') || '[]';
        try {
            templateBlocks = JSON.parse(blocksRaw);
        } catch (e) {
            templateBlocks = [];
        }

        initPageBuilder(existingInstances, csrf, templateBlocks);
    }
});
```

The mode toggle (all three panels + sidebar card) is handled by `design-mode-init.js`.

---

### 5. Modify `app/Admin/DesignController.php`

**Purpose**: Add browser, duplicate, and delete endpoints.

#### 5a. New method: `browser(Request $request): Response`

```php
/**
 * GET /admin/design/browser — Design file browser page.
 */
public function browser(Request $request): Response
{
    $designFiles = $this->getDesignFileList();
    $csrfToken = Session::get('csrf_token', '');

    $html = $this->app->template()->render('admin/design/browser', [
        'title'       => 'Design Files',
        'activeNav'   => 'design-browser',
        'designFiles' => $designFiles,
        'csrfToken'   => $csrfToken,
    ]);

    return Response::html($html);
}
```

#### 5b. New method: `duplicate(Request $request): Response`

```php
/**
 * POST /admin/design/duplicate — Duplicate an existing .pen file.
 * Body: { "source": "original.pen", "target": "copy.pen" }
 */
public function duplicate(Request $request): Response
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        return Response::json(['success' => false, 'error' => 'Invalid request'], 400);
    }

    $source = $this->sanitizePath($body['source'] ?? '');
    $target = $this->sanitizePath($body['target'] ?? '');

    if ($source === null || $target === null) {
        return Response::json(['success' => false, 'error' => 'Invalid path'], 400);
    }

    $sourceFull = $this->designsDir . '/' . $source;
    $targetFull = $this->designsDir . '/' . $target;

    if (!file_exists($sourceFull)) {
        return Response::json(['success' => false, 'error' => 'Source file not found'], 404);
    }

    if (file_exists($targetFull)) {
        return Response::json(['success' => false, 'error' => 'Target file already exists'], 409);
    }

    // Ensure parent directory exists
    $dir = dirname($targetFull);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    copy($sourceFull, $targetFull);

    return Response::json(['success' => true]);
}
```

#### 5c. New method: `deleteFile(Request $request): Response`

```php
/**
 * POST /admin/design/delete — Delete a .pen file.
 * Body: { "path": "filename.pen" }
 */
public function deleteFile(Request $request): Response
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        return Response::json(['success' => false, 'error' => 'Invalid request'], 400);
    }

    $path = $this->sanitizePath($body['path'] ?? '');
    if ($path === null) {
        return Response::json(['success' => false, 'error' => 'Invalid path'], 400);
    }

    $fullPath = $this->designsDir . '/' . $path;
    if (!file_exists($fullPath)) {
        return Response::json(['success' => false, 'error' => 'File not found'], 404);
    }

    // Check if any content items reference this file
    $db = $this->app->resolve('db');
    $stmt = $db->prepare('SELECT COUNT(*) FROM content WHERE design_file = ?');
    $stmt->execute([$path]);
    $usageCount = (int) $stmt->fetchColumn();

    if ($usageCount > 0) {
        return Response::json([
            'success' => false,
            'error' => "File is used by {$usageCount} content item(s). Remove references first.",
            'usage_count' => $usageCount,
        ], 409);
    }

    unlink($fullPath);

    return Response::json(['success' => true]);
}
```

---

### 6. Modify `app/Admin/ContentController.php`

**Purpose**: Add reconvert endpoint and pass `designFiles` to edit template.

#### 6a. New method: `reconvert(Request $request, string $id): Response`

```php
/**
 * POST /admin/content/{id}/reconvert — Re-convert .pen file and update content body.
 */
public function reconvert(Request $request, string $id): Response
{
    $db = $this->app->resolve('db');
    $stmt = $db->prepare('SELECT * FROM content WHERE id = ?');
    $stmt->execute([(int) $id]);
    $content = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$content) {
        return Response::json(['success' => false, 'error' => 'Content not found'], 404);
    }

    // Read design_file from request body (may differ from DB if user changed it)
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $designFile = $body['design_file'] ?? ($content['design_file'] ?? '');

    if (empty($designFile)) {
        return Response::json(['success' => false, 'error' => 'No design file specified'], 400);
    }

    $designsDir = dirname(__DIR__, 2) . '/designs';
    $fullPath = $designsDir . '/' . $designFile;

    if (!file_exists($fullPath)) {
        return Response::json(['success' => false, 'error' => 'Design file not found'], 404);
    }

    try {
        $result = PenConverter::convertFile($fullPath);

        // Update content body with converted HTML
        $update = $db->prepare(
            'UPDATE content SET body = ?, design_file = ?, updated_at = ? WHERE id = ?'
        );
        $update->execute([
            $result['html'],
            $designFile,
            date('Y-m-d H:i:s'),
            (int) $id,
        ]);

        return Response::json([
            'success' => true,
            'html'    => $result['html'],
            'css'     => $result['css'],
        ]);
    } catch (\Throwable $e) {
        return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
    }
}
```

#### 6b. Modify `edit()` method — pass `designFiles` list

Add to the template variables in the `edit()` method:

```php
// Load design files for design mode selector
$designsDir = dirname(__DIR__, 2) . '/designs';
$designFiles = [];
if (is_dir($designsDir)) {
    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($designsDir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $fi) {
        if ($fi->getExtension() !== 'pen') continue;
        $rel = str_replace($designsDir . DIRECTORY_SEPARATOR, '', $fi->getPathname());
        $rel = str_replace('\\', '/', $rel);
        $designFiles[] = ['name' => $fi->getBasename('.pen'), 'path' => $rel];
    }
    usort($designFiles, fn($a, $b) => strcmp($a['name'], $b['name']));
}
```

Add `'designFiles' => $designFiles` to the template render data array.

#### 6c. Modify `create()` method — same thing

Pass `'designFiles' => $designFiles` when rendering the create form too.

---

### 7. Modify `public/index.php`

**Purpose**: Register new routes for browser, duplicate, delete, and reconvert.

**Add these routes** (in the admin group, near the existing design routes):

```php
// Design Browser (Chunk 7.5)
$router->get('/admin/design/browser', [DesignController::class, 'browser']);
$router->post('/admin/design/duplicate', [DesignController::class, 'duplicate']);
$router->post('/admin/design/delete', [DesignController::class, 'deleteFile']);

// Content reconvert (Chunk 7.5)
$router->post('/admin/content/{id}/reconvert', [ContentController::class, 'reconvert']);
```

---

### 8. Modify `public/assets/css/admin.css`

**Purpose**: Add styles for design mode panel and design browser.

**New CSS rules**:

```css
/* --- Design Editor Mode (content edit page) --- */
.design-mode-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem;
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: var(--card-radius) var(--card-radius) 0 0;
    flex-wrap: wrap;
}

.design-file-selector {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.design-file-selector label {
    font-weight: 600;
    font-size: 0.875rem;
    white-space: nowrap;
}

.design-mode-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.design-editor-split {
    display: flex;
    border: 1px solid var(--color-border);
    border-top: none;
    border-radius: 0 0 var(--card-radius) var(--card-radius);
    overflow: hidden;
    min-height: 600px;
}

.design-editor-pane {
    flex: 3;
    min-width: 0;
    position: relative;
}

.design-preview-pane {
    flex: 2;
    border-left: 1px solid var(--color-border);
    display: flex;
    flex-direction: column;
    min-width: 280px;
}

.design-preview-header {
    padding: 0.5rem 0.75rem;
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-muted);
    background: var(--color-bg);
    border-bottom: 1px solid var(--color-border);
}

.design-editor-iframe,
.design-preview-iframe {
    width: 100%;
    height: 100%;
    border: none;
    flex: 1;
}

.design-status {
    font-size: 0.8rem;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    white-space: nowrap;
}
.design-status.status-ready { color: var(--color-success); }
.design-status.status-saving { color: var(--color-warning); }
.design-status.status-saved { color: var(--color-success); }
.design-status.status-error { color: var(--color-error); }

/* --- Design File Browser --- */
.design-browser-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.25rem;
}

.design-browser-card {
    background: var(--color-white);
    border: 1px solid var(--color-border);
    border-radius: var(--card-radius);
    overflow: hidden;
    box-shadow: var(--card-shadow);
    transition: box-shadow 0.2s;
}

.design-browser-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.design-browser-thumb {
    height: 180px;
    overflow: hidden;
    background: #f8f9fa;
    position: relative;
}

.design-browser-thumb iframe {
    width: 200%;
    height: 200%;
    border: none;
    transform: scale(0.5);
    transform-origin: 0 0;
    pointer-events: none;
}

.design-browser-info {
    padding: 0.75rem;
    border-top: 1px solid var(--color-border);
}

.design-browser-name {
    display: block;
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 0.25rem;
}

.design-browser-meta {
    font-size: 0.8rem;
    color: var(--color-text-muted);
}

.design-browser-actions {
    display: flex;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    border-top: 1px solid var(--color-border-light);
    background: var(--color-bg);
}

.design-browser-empty {
    grid-column: 1 / -1;
    text-align: center;
    padding: 3rem 1rem;
    color: var(--color-text-muted);
}
```

---

### 9. Modify `templates/admin/layout.php`

**Purpose**: Add "Design Files" link to sidebar navigation.

**Change**: Add a link for the design browser after the existing "Design Editor" link.

```php
<a href="/admin/design/browser"
   class="<?= ($activeNav ?? '') === 'design-browser' ? 'active' : '' ?>">
    <span class="nav-icon">&#128193;</span> Design Files
</a>
```

---

### 10. Modify `app/Templates/FrontController.php`

**Purpose**: Optimize to use pre-converted HTML from body (fast path).

**Current behavior**: On every request, reads the `.pen` file and runs PenConverter. This is slow.

**Fast path**: If `design_file` is set AND `body` is not empty, serve `body` as-is (already converted HTML). Only fall back to on-the-fly conversion if body is empty.

**Change** (in the three render methods — `showPage`, `showPost`, `showCustomContent`):

Replace:
```php
$designFile = $content['design_file'] ?? null;
if ($designFile !== null && trim($designFile) !== '') {
    $penPath = dirname(__DIR__, 2) . '/designs/' . $designFile;
    if (file_exists($penPath)) {
        $penResult = PageRenderer::renderFromPen($penPath);
        $content['body'] = $penResult['html'];
        $elementCss = ($elementCss ?? '') . "\n" . $penResult['css'];
    }
}
```

With:
```php
$designFile = $content['design_file'] ?? null;
if ($designFile !== null && trim($designFile) !== '') {
    // Fast path: use pre-converted HTML from body if available
    if (empty(trim($content['body'] ?? ''))) {
        // Fallback: on-the-fly conversion if body is empty
        $penPath = dirname(__DIR__, 2) . '/designs/' . $designFile;
        if (file_exists($penPath)) {
            $penResult = PageRenderer::renderFromPen($penPath);
            $content['body'] = $penResult['html'];
            $elementCss = ($elementCss ?? '') . "\n" . $penResult['css'];
        }
    } else {
        // Body already has pre-converted HTML — just need the CSS
        $penPath = dirname(__DIR__, 2) . '/designs/' . $designFile;
        if (file_exists($penPath)) {
            $penResult = PenConverter::convertFile($penPath);
            $elementCss = ($elementCss ?? '') . "\n" . $penResult['css'];
        }
    }
}
```

**Note**: We still need the CSS from the `.pen` file even when using pre-converted HTML, because CSS is not stored in the body column. We could store the CSS too, but that adds complexity. For now, CSS is generated on each request (it's much faster than full conversion since we can cache the parse result).

**Actually, simpler and better approach**: Keep the current behavior (always re-convert from .pen file on public requests). The pre-conversion benefit is marginal since PenConverter is fast (pure PHP string manipulation). The "fast path" optimization can be deferred. The current code from chunk 7.4 already works correctly. No change needed here.

**Decision**: Leave FrontController unchanged. The existing behavior is correct and performant enough. The real value of the reconvert endpoint is that it stores HTML in `body` so the content can be viewed/edited in HTML mode too.

---

## Detailed Class Specifications

### `App\Admin\DesignController` (modifications)

```
NEW METHODS:
  - public browser(Request $request): Response
      Renders admin/design/browser template with list of .pen files.
      Template data: title, activeNav, designFiles, csrfToken.

  - public duplicate(Request $request): Response
      JSON body: { "source": "file.pen", "target": "copy.pen" }
      Validates paths with sanitizePath().
      Checks source exists, target doesn't exist.
      Copies file. Returns { success: true }.

  - public deleteFile(Request $request): Response
      JSON body: { "path": "file.pen" }
      Validates path with sanitizePath().
      Checks if any content references this file via DB query.
      If referenced: returns 409 with usage count.
      If not referenced: deletes file. Returns { success: true }.

EXISTING (unchanged):
  - editor, load, save, importFile, list, convert, preview
  - sanitizePath, getDesignFileList, withSecurityHeaders
```

### `App\Admin\ContentController` (modifications)

```
NEW METHODS:
  - public reconvert(Request $request, string $id): Response
      Loads content by ID.
      Reads design_file from JSON body (or falls back to DB).
      Runs PenConverter::convertFile() on the .pen file.
      Updates content.body and content.design_file in DB.
      Returns { success: true, html: "...", css: "..." }.

MODIFIED METHODS:
  - edit(): Also loads designFiles list and passes to template.
  - create(): Also loads designFiles list and passes to template.
```

---

## Acceptance Test Procedures

### Test 1: Content editor shows "Design" tab alongside HTML/Elements editor modes
```
1. Navigate to /admin/content/create?type=page
2. Verify three radio buttons: "HTML Editor", "Page Builder", "Design Editor"
3. Default should be "HTML Editor" (checked).
4. Clicking each radio shows the correct panel and hides others.
```

### Test 2: Selecting "Design" mode loads the embedded Pencil editor
```
1. Navigate to /admin/content/create?type=page
2. Select "Design Editor" mode.
3. Verify the design editor panel appears with:
   - File selector dropdown
   - New file name input
   - Re-convert button
   - "Open Full Editor" link
   - Pencil editor iframe (loading)
   - Preview iframe
4. Verify the Pencil editor loads inside the iframe (check for "Ready" status).
```

### Test 3: Preview panel shows converted HTML alongside the editor
```
1. Open an existing content item with editor_mode = 'design' and a valid design_file.
2. Verify the preview iframe loads with the converted HTML.
3. The preview shows the rendered output of the .pen file.
```

### Test 4: "Re-convert" button triggers PenConverter and updates content body
```
1. Open an existing content item with a .pen design file.
2. Click "Re-convert to HTML".
3. Verify:
   - Button shows "Converting..." during operation.
   - Preview iframe refreshes with new HTML.
   - Body textarea (hidden) is updated with converted HTML.
   - Status shows "Converted!".
4. Save the content and verify body in DB matches converted HTML.
```

### Test 5: Public site serves the content correctly
```
1. Create content with design mode and a .pen file.
2. Re-convert to populate the body.
3. Visit the public URL.
4. Verify the page renders with the design's HTML and CSS.
```

### Test 6: Design file browser at `/admin/design/browser` lists all .pen files
```
1. Navigate to /admin/design/browser.
2. Verify grid of design file cards.
3. Each card shows: thumbnail preview, file name, modification date.
4. "Edit" links to /admin/design/editor?file={path}.
```

### Test 7: Creating a new design from template works; duplicating an existing design works
```
1. In the browser, click "Duplicate" on an existing design.
2. Enter a new name in the prompt dialog.
3. Verify new file appears in the grid.
4. "Delete" button removes a file (if not referenced by content).
```

---

## Implementation Notes

### Script Load Order
The content edit page loads scripts in this order:
1. `page-builder.js` — Page builder core
2. `page-builder-init.js` — Page builder initialization (no longer handles mode toggle)
3. `page-styles-init.js` — Page styles
4. `design-mode-init.js` — **NEW**: Design mode initialization + mode toggle for all three modes
5. `editor.js` — Other editor logic
6. `ai-assistant.js` — AI panel

### No Duplicate Mode Toggling
`design-mode-init.js` owns the mode toggle for all three modes. The toggle code is removed from `page-builder-init.js` to avoid conflicts.

### Form Submission Flow (Design Mode)
When the user clicks "Create" or "Update":
1. `design-mode-init.js` intercepts the submit event.
2. Sends `save` message to bridge iframe.
3. Waits for `file-saved` response (with timeout).
4. Then allows the form to submit normally.
5. Form includes `editor_mode=design` and `design_file=filename.pen`.
6. ContentController stores both values.

### Bridge Communication
Uses the exact same postMessage protocol as `templates/admin/design/editor.php`:
- `litecms-admin` → iframe: `load-file`, `set-file-path`, `save`
- iframe → parent: `editor-ready`, `file-saved`

### Security
- All file paths validated via `DesignController::sanitizePath()`.
- CSRF token checked on POST requests.
- Delete endpoint checks for content references before deleting.
- Iframe sandboxed with minimal permissions.

### CSP Headers
The content edit page already has relaxed CSP (from Chunk 7.1 `withSecurityHeaders`). Need to apply the same CSP to the content edit page since it now embeds the Pencil editor iframe. The `edit()` method should use `$this->withSecurityHeaders()`.

**Wait**: ContentController doesn't have `withSecurityHeaders()`. And the CSP needs to allow the Pencil editor. Since the edit page is the same admin layout, and the iframe loads from `/assets/pencil-editor/index.html` (same origin), the default CSP should work. The iframe itself handles its own CSP via the Pencil editor page. The parent page just needs `frame-src 'self'` which is already the default. No CSP changes needed.

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `templates/admin/design/browser.php` | Template | Create |
| 2 | `public/assets/js/design-mode-init.js` | JavaScript | Create |
| 3 | `templates/admin/content/edit.php` | Template | Modify |
| 4 | `public/assets/js/page-builder-init.js` | JavaScript | Modify |
| 5 | `app/Admin/DesignController.php` | Class | Modify |
| 6 | `app/Admin/ContentController.php` | Class | Modify |
| 7 | `public/index.php` | Routes | Modify |
| 8 | `public/assets/css/admin.css` | Stylesheet | Modify |
| 9 | `templates/admin/layout.php` | Template | Modify |

---

## Estimated Scope

- **New files**: 2 (browser template, design-mode-init.js)
- **Modified files**: 7
- **New PHP methods**: 4 (browser, duplicate, deleteFile, reconvert)
- **Modified PHP methods**: 2 (edit, create — pass designFiles)
- **New JS**: ~200 lines (design-mode-init.js)
- **New CSS**: ~120 lines (design mode + browser styles)
- **New routes**: 4 (browser, duplicate, delete, reconvert)
- **Approximate LOC added**: ~500
