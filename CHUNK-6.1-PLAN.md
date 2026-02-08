# Chunk 6.1 — Element Catalogue & Rendering Engine
## Detailed Implementation Plan

---

## Overview

This chunk builds the foundation of the element-based page builder: a database schema for reusable UI elements, a micro-mustache template engine (SlotRenderer) for rendering element templates with typed content slots, a PageRenderer for assembling element-based pages, full admin CRUD for managing the element catalogue, and 7 seed starter elements. At completion, admins can create/edit/preview/delete elements, and the front controller renders element-based pages when `editor_mode = 'elements'`.

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `migrations/004_page_builder.sqlite.sql`

**Purpose**: Create the element catalogue, page composition, and AI proposal tables for SQLite. Also adds `editor_mode` column to the existing `content` table.

```sql
-- Elements catalogue (reusable UI components)
CREATE TABLE IF NOT EXISTS elements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'general',
    html_template TEXT NOT NULL DEFAULT '',
    css TEXT NOT NULL DEFAULT '',
    slots_json TEXT NOT NULL DEFAULT '[]',
    preview_html TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    is_ai_generated INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'draft', 'archived')),
    author_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_elements_slug ON elements(slug);
CREATE INDEX IF NOT EXISTS idx_elements_category ON elements(category);
CREATE INDEX IF NOT EXISTS idx_elements_status ON elements(status);

-- Page elements (element instances on content pages)
CREATE TABLE IF NOT EXISTS page_elements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_id INTEGER NOT NULL,
    element_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    slot_data_json TEXT NOT NULL DEFAULT '{}',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE,
    FOREIGN KEY (element_id) REFERENCES elements(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_page_elements_content ON page_elements(content_id);
CREATE INDEX IF NOT EXISTS idx_page_elements_element ON page_elements(element_id);
CREATE INDEX IF NOT EXISTS idx_page_elements_sort ON page_elements(content_id, sort_order);

-- Element proposals (AI-generated elements awaiting approval — used by Chunk 6.3)
CREATE TABLE IF NOT EXISTS element_proposals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'general',
    html_template TEXT NOT NULL,
    css TEXT NOT NULL DEFAULT '',
    slots_json TEXT NOT NULL DEFAULT '[]',
    conversation_id INTEGER,
    proposed_by INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id),
    FOREIGN KEY (proposed_by) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_element_proposals_status ON element_proposals(status);

-- Add editor mode to content table
ALTER TABLE content ADD COLUMN editor_mode VARCHAR(20) NOT NULL DEFAULT 'html';
```

**Notes**:
- `elements` table stores reusable UI component definitions (template, CSS, slot schema).
- `page_elements` is a join table: maps content items to element instances with sort order and slot data.
- `element_proposals` is prepared for Chunk 6.3 (AI-proposed elements awaiting approval).
- `editor_mode` on `content` distinguishes `'html'` (TinyMCE) from `'elements'` (page builder).
- `ON DELETE RESTRICT` on `element_id` prevents deleting elements that are in use.
- `ON DELETE CASCADE` on `content_id` cleans up element instances when content is deleted.

---

### 2. `migrations/004_page_builder.mysql.sql`

**Purpose**: MySQL/MariaDB variant of the same migration.

```sql
-- Elements catalogue
CREATE TABLE IF NOT EXISTS elements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'general',
    html_template TEXT NOT NULL,
    css TEXT NOT NULL,
    slots_json TEXT NOT NULL,
    preview_html TEXT,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    is_ai_generated TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'draft', 'archived') NOT NULL DEFAULT 'active',
    author_id BIGINT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_elements_category (category),
    INDEX idx_elements_status (status),
    FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Page elements
CREATE TABLE IF NOT EXISTS page_elements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_id BIGINT UNSIGNED NOT NULL,
    element_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    slot_data_json TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_page_elements_content (content_id),
    INDEX idx_page_elements_element (element_id),
    INDEX idx_page_elements_sort (content_id, sort_order),
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE,
    FOREIGN KEY (element_id) REFERENCES elements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Element proposals
CREATE TABLE IF NOT EXISTS element_proposals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'general',
    html_template TEXT NOT NULL,
    css TEXT NOT NULL,
    slots_json TEXT NOT NULL,
    conversation_id BIGINT UNSIGNED,
    proposed_by BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_element_proposals_status (status),
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id),
    FOREIGN KEY (proposed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE content ADD COLUMN editor_mode VARCHAR(20) NOT NULL DEFAULT 'html';
```

**Notes**: Uses `BIGINT UNSIGNED AUTO_INCREMENT`, `ENUM`, `ON UPDATE CURRENT_TIMESTAMP`, and InnoDB engine per MySQL conventions.

---

### 3. `migrations/004_page_builder.pgsql.sql`

**Purpose**: PostgreSQL variant of the same migration.

```sql
-- Elements catalogue
CREATE TABLE IF NOT EXISTS elements (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'general',
    html_template TEXT NOT NULL DEFAULT '',
    css TEXT NOT NULL DEFAULT '',
    slots_json TEXT NOT NULL DEFAULT '[]',
    preview_html TEXT,
    version INTEGER NOT NULL DEFAULT 1,
    is_ai_generated INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'draft', 'archived')),
    author_id INTEGER REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_elements_category ON elements(category);
CREATE INDEX IF NOT EXISTS idx_elements_status ON elements(status);

-- Page elements
CREATE TABLE IF NOT EXISTS page_elements (
    id SERIAL PRIMARY KEY,
    content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE,
    element_id INTEGER NOT NULL REFERENCES elements(id) ON DELETE RESTRICT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    slot_data_json TEXT NOT NULL DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_page_elements_content ON page_elements(content_id);
CREATE INDEX IF NOT EXISTS idx_page_elements_element ON page_elements(element_id);
CREATE INDEX IF NOT EXISTS idx_page_elements_sort ON page_elements(content_id, sort_order);

-- Element proposals
CREATE TABLE IF NOT EXISTS element_proposals (
    id SERIAL PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(100) DEFAULT 'general',
    html_template TEXT NOT NULL,
    css TEXT NOT NULL DEFAULT '',
    slots_json TEXT NOT NULL DEFAULT '[]',
    conversation_id INTEGER REFERENCES ai_conversations(id),
    proposed_by INTEGER NOT NULL REFERENCES users(id),
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_element_proposals_status ON element_proposals(status);

ALTER TABLE content ADD COLUMN editor_mode VARCHAR(20) NOT NULL DEFAULT 'html';
```

**Notes**: Uses `SERIAL`, inline FK references, `CHECK` constraints per PostgreSQL conventions.

---

### 4. `app/PageBuilder/SlotRenderer.php`

**Purpose**: Lightweight Mustache-like template engine for rendering element HTML templates with slot data.

**Class**: `App\PageBuilder\SlotRenderer`

**Supported syntax**:
| Syntax | Meaning |
|--------|---------|
| `{{key}}` | Escaped text output (XSS-safe) |
| `{{{key}}}` | Raw HTML output (for richtext) |
| `{{#key}}...{{/key}}` | Section: truthy conditional OR array loop |
| `{{^key}}...{{/key}}` | Inverted section: falsy / empty check |
| `{{key.sub}}` | Dot notation for nested values |

**Public API**:
```php
public static function render(string $template, array $data): string
```

**Private methods**:
```
STATIC METHODS:
  - private static processSections(string $template, array $data): string
      Handles {{#key}}...{{/key}} blocks:
        - If value is a sequential array of arrays → loop (render inner per item, merging item+parent data)
        - If value is truthy → render inner once with current data
        - If value is falsy/empty → remove section
      Uses iterative regex matching (max 10 depth levels) to handle nested sections.
      Regex: '/\{\{#([a-zA-Z0-9_.]+)\}\}((?:(?!\{\{#|\{\{\/).)*?)\{\{\/\1\}\}/s'

  - private static processInvertedSections(string $template, array $data): string
      Handles {{^key}}...{{/key}} blocks:
        - If value is empty/falsy → render inner
        - If value is truthy → remove section
      Same iterative approach as processSections.

  - private static resolve(string $key, array $data): mixed
      Resolves dotted key paths: "cta.url" → $data['cta']['url']
      Returns '' if key path does not exist.
      Returns arrays and booleans as-is (for section evaluation).

  - private static isSequential(array $arr): bool
      Returns true if array keys are 0..n-1 (numeric sequential).
```

**Processing order**:
1. Process sections (`{{#key}}...{{/key}}`) — handles both loops and conditionals
2. Process inverted sections (`{{^key}}...{{/key}}`)
3. Replace triple-mustache raw output (`{{{key}}}`)
4. Replace double-mustache escaped output (`{{key}}`)

**Implementation notes**:
- Sections are processed first because they contain inner templates with mustache tags that need recursive rendering.
- Triple-mustache is processed before double-mustache to avoid the double-mustache regex matching inside a triple-mustache.
- Recursive `render()` calls within sections allow nested sections and mustache tags inside loops.
- Max nesting depth of 10 prevents infinite loops from malformed templates.

---

### 5. `app/PageBuilder/PageRenderer.php`

**Purpose**: Assembles element-based pages by rendering all element instances for a content item and collecting their CSS.

**Class**: `App\PageBuilder\PageRenderer`

**Public API**:
```php
public static function renderPage(int $contentId): string
    // Loads all element instances for a content item (via loadInstances), renders each,
    // and concatenates the HTML. Returns empty string if no instances.

public static function getPageCss(int $contentId): string
    // Loads all element instances, deduplicates CSS by element_id,
    // returns combined CSS with comment headers per element.

public static function renderInstance(array $instance): string
    // Renders a single element instance:
    //   1. Extracts slug, element_id, html_template, slot_data from the instance array
    //   2. Parses slot_data (JSON string → array if needed)
    //   3. Calls SlotRenderer::render() with template and data
    //   4. Wraps output in: <div class="lcms-el lcms-el-{slug}" data-element-id="{id}">...\n</div>
```

**Private methods**:
```
STATIC METHODS:
  - private static loadInstances(int $contentId): array
      Queries page_elements joined with elements table:
        SELECT page_elements.id, page_elements.element_id, page_elements.sort_order,
               page_elements.slot_data_json,
               elements.slug, elements.name, elements.html_template, elements.css
        FROM page_elements
        LEFT JOIN elements ON elements.id = page_elements.element_id
        WHERE page_elements.content_id = $contentId
        ORDER BY page_elements.sort_order ASC
```

**Implementation notes**:
- `renderInstance()` accepts `slot_data` as either a parsed array or a JSON string (handles both).
- The wrapper div uses `lcms-el` as a shared class and `lcms-el-{slug}` for element-specific scoping.
- `data-element-id` attribute enables JS interaction in the page builder UI (Chunk 6.2).
- `getPageCss()` deduplicates by element_id so CSS is only included once even if an element is used multiple times.

---

### 6. `app/PageBuilder/SeedElements.php`

**Purpose**: Seeds the element catalogue with 7 common starter elements on first run (idempotent).

**Class**: `App\PageBuilder\SeedElements`

**Public API**:
```php
public static function seed(): int
    // Iterates definitions(), skips elements where slug already exists,
    // inserts new ones. Returns count of newly inserted elements.

public static function definitions(): array
    // Returns array of 7 element definition arrays.
```

**Private methods** (one per seed element):
```
  - private static heroSection(): array
  - private static textSection(): array
  - private static featureGrid(): array
  - private static ctaBanner(): array
  - private static imageText(): array
  - private static testimonialSection(): array
  - private static faqSection(): array
```

**Seed elements**:

| # | Slug | Name | Category | Slot Types Used |
|---|------|------|----------|-----------------|
| 1 | `hero-section` | Hero Section | hero | text, richtext, image, link, select |
| 2 | `text-section` | Text Section | content | text, richtext |
| 3 | `feature-grid` | Feature Grid | features | text, select, list (sub: text, text, richtext) |
| 4 | `cta-banner` | CTA Banner | cta | text, richtext, link |
| 5 | `image-text` | Image + Text | content | image, text, richtext, select |
| 6 | `testimonial-section` | Testimonials | testimonials | text, list (sub: richtext, text, text) |
| 7 | `faq-section` | FAQ Section | content | text, list (sub: text, richtext) |

**Element definition format**:
```php
[
    'slug'          => 'hero-section',
    'name'          => 'Hero Section',
    'description'   => 'Full-width hero banner with headline, description, and call-to-action button.',
    'category'      => 'hero',
    'status'        => 'active',
    'slots_json'    => json_encode([...]),   // Array of slot definitions
    'html_template' => '...',                // Mustache template HTML
    'css'           => '...',                // Scoped CSS under .lcms-el-{slug}
]
```

**Slot definition format**:
```php
[
    'key'       => 'title',           // Unique within element, [a-z0-9_]+
    'label'     => 'Headline',        // Display label in admin UI
    'type'      => 'text',            // One of: text, richtext, image, link, select, boolean, number, list
    'required'  => true,              // Optional, defaults to false
    'default'   => 'center',          // Optional default value
    'options'   => ['left','center'], // Required for select type
    'sub_slots' => [...],             // Required for list type — array of child slot defs
    'min_items' => 1,                 // Optional for list type
    'max_items' => 12,                // Optional for list type
]
```

**Implementation notes**:
- Idempotent: checks for existing slug before inserting, safe to call multiple times.
- Each element's CSS is scoped under `.lcms-el-{slug}` to prevent conflicts.
- Templates use semantic HTML5 (`<section>`, `<blockquote>`, `<details>`).
- CSS uses CSS custom properties (`var(--color-primary, #2563eb)`) for theming consistency.
- Responsive: feature-grid and image-text collapse to single column on mobile (`@media max-width: 768px`).
- FAQ section uses native `<details>/<summary>` for JS-free expand/collapse.

---

### 7. `app/Admin/ElementController.php`

**Purpose**: Full admin CRUD for the element catalogue plus preview API and JSON list endpoint.

**Class**: `App\Admin\ElementController`

**Properties**:
```
PROPERTIES:
  - private App $app
  - private const VALID_SLOT_TYPES = ['text', 'richtext', 'image', 'link', 'select', 'boolean', 'number', 'list']
  - private const VALID_STATUSES = ['active', 'draft', 'archived']

CONSTRUCTOR:
  __construct(App $app)
```

**Public API**:
```php
// CRUD
public function index(Request $request): Response
    // GET /admin/elements — Lists all elements with category filter, search, usage counts.
    // Template: admin/elements/index
    // Template data: title, activeNav, elements, categories, filter

public function create(Request $request): Response
    // GET /admin/elements/create — Shows empty element editor form.
    // Template: admin/elements/edit (with isNew=true)

public function store(Request $request): Response
    // POST /admin/elements — Validates and creates new element.
    // Reads form data, generates slug, validates, inserts, redirects to edit page.
    // Flash messages: success or error.

public function edit(Request $request, string $id): Response
    // GET /admin/elements/{id}/edit — Shows editor with existing element data.
    // Includes usage count for delete protection hint.
    // Template: admin/elements/edit (with isNew=false)

public function update(Request $request, string $id): Response
    // PUT /admin/elements/{id} — Updates existing element.
    // Increments version number. Redirects back to edit page.

public function delete(Request $request, string $id): Response
    // DELETE /admin/elements/{id} — Deletes element if not in use.
    // If element is referenced by any page_elements rows, blocks deletion with error.
    // On success, removes row and redirects to index.

// API endpoints
public function preview(Request $request, string $id): Response
    // GET /admin/elements/{id}/preview — Returns JSON {success, html, css}.
    // Generates sample data from slot definitions, renders with SlotRenderer,
    // wraps in .lcms-el-{slug} div.

public function apiList(Request $request): Response
    // GET /admin/elements/api/list — Returns JSON {success, elements[]}.
    // Lists active elements with id, slug, name, description, category, slots_json.
    // Used by the page builder element picker (Chunk 6.2).

// Static helper
public static function generateSampleData(array $slots): array
    // Generates placeholder data for each slot type for preview rendering.
    // text → "Sample {label}", richtext → "<p>Sample {label} content.</p>",
    // image → "", link → {url:'#', text:label, target:'_self'},
    // select → first option, boolean → true, number → 3,
    // list → [sampleItem, sampleItem] (recursive call for sub_slots)
```

**Private methods**:
```
  - private readFormData(Request $request): array
      Extracts: name, slug, description, category, html_template, css, slots_json, status

  - private validate(array $data, ?int $excludeId = null): ?string
      Validates:
        - Name: required, max 200 chars
        - Slug: required, max 100 chars, regex [a-z0-9]+(-[a-z0-9]+)*, unique (excluding self on update)
        - Status: must be in VALID_STATUSES
        - Category: required
        - Slots JSON: delegates to validateSlotsJson()
      Returns null on success, error string on failure.

  - private validateSlotsJson(string $json): ?string
      Validates slot JSON structure:
        - Must be valid JSON array
        - Each slot must have: key (string, [a-z0-9_]+), label (string), type (valid slot type)
        - No duplicate keys
        - Select type requires non-empty options array
        - List type requires non-empty sub_slots array

  - private generateSlug(string $name, string $manualSlug = ''): string
      Prefers manual slug if provided, otherwise generates from name.
      Lowercases, replaces non-alphanumeric with hyphens, trims, deduplicates hyphens.
      Returns 'untitled' as fallback.

  - private withSecurityHeaders(Response $response): Response
      Adds X-Frame-Options: DENY and Content-Security-Policy headers.
```

---

### 8. `templates/admin/elements/index.php`

**Purpose**: Element catalogue grid view with category filters, search, and element cards.

**Template data**: `$elements`, `$categories`, `$filter`

**Layout**: Uses `admin/layout` (via `$this->layout(...)`)

**Structure**:
- Page header: title "Element Catalogue", element count badge, "+ New Element" button
- Filters: category dropdown (auto-submit on change), text search input, search/clear buttons
- Empty state: shown when no elements exist
- Grid: responsive grid of `.element-card` components, each showing:
  - Header: category badge, status indicator (active/draft/archived)
  - Body: element name, slug code tag, truncated description
  - Footer: usage count, Edit button, Delete button (only if usage count = 0, with confirm dialog)

---

### 9. `templates/admin/elements/edit.php`

**Purpose**: Element editor form with meta fields, slot builder, HTML/CSS code editors, and live preview.

**Template data**: `$element`, `$isNew`, `$usageCount`

**Layout**: Uses `admin/layout`

**Structure** (two-column grid layout):
- **Left panel (`.element-meta-panel`)**: Name input, slug input (auto-generated), description textarea, category (with datalist suggestions), status select, slot builder section (JS-driven), save/cancel buttons
- **Right panel (`.element-code-panel`)**: HTML template textarea (with syntax hint for mustache tags), CSS textarea (with scope hint showing `.lcms-el-{slug}`), live preview section with refresh button

**Form behavior**:
- `_method=PUT` hidden input for update (existing elements)
- CSRF token field
- Slots JSON serialized to hidden `slots_json` input on form submit (JS)
- Includes `element-editor.js` script and calls `initElementEditor(slots, elementId)` on DOMContentLoaded

---

### 10. `public/assets/js/element-editor.js`

**Purpose**: Slot builder UI and live preview functionality for the element editor.

**IIFE module** — all functions encapsulated in `(function() { 'use strict'; ... })();`

**Global entry point**: `window.initElementEditor(initialSlots, elId)`

**Key functions**:
```
initElementEditor(initialSlots, elId)
    - Initializes DOM references (slot list, no-slots message, JSON input, element ID)
    - Parses initial slots
    - Renders all slot rows
    - Sets up "Add Slot" button handler
    - Sets up form submit handler (serializes slots to JSON)
    - Sets up slug auto-generation from name
    - Sets up preview refresh button

renderAllSlots()
    - Clears and re-renders all slot rows
    - Shows/hides "No slots" message

createSlotRow(slot, index) → HTMLElement
    - Creates a .slot-row div containing:
      - Header: drag handle, slot title, remove button (.slot-remove-btn)
      - Fields: key input, label input, type select, required checkbox
      - Options section (for select type): textarea for options (one per line)
      - Sub-slots section (for list type): sub-slot rows + "Add Sub-Slot" button
    - Attaches type change handler (shows/hides options/sub-slots sections)
    - Attaches remove handler (splices from slots array, re-renders)
    - Attaches "Add Sub-Slot" button handler

createSubSlotRow(sub, index) → HTMLElement
    - Creates row with: key input, label input, type select (text/richtext/image/number/boolean), remove button

readSlotsFromDOM()
    - Reads all slot data from DOM inputs back into the slots array
    - Handles options (for select) and sub_slots (for list)

serializeSlots()
    - Calls readSlotsFromDOM(), writes JSON.stringify(slots) to hidden input

refreshPreview()
    - For saved elements: fetches /admin/elements/{id}/preview (JSON API)
    - For new elements: calls clientSidePreview()

clientSidePreview()
    - Reads HTML template and CSS from textareas
    - Generates sample data from current slot definitions
    - Does basic mustache replacement (text only, strips section tags)
    - Renders into preview container with CSS

renderPreview(html, css)
    - Injects <style> + html into the preview container

escHtml(str) → string — HTML-escapes via textContent/innerHTML trick
escAttr(str) → string — Escapes &, ", <, > for attribute contexts
```

---

## Modifications to Existing Files

### 11. `app/Templates/FrontController.php`

**Changes**: Added element-mode rendering branch in `page()` and `blogPost()` methods.

**New import**: `use App\PageBuilder\PageRenderer;`

**Modified methods**:
- `page()`: After loading content, checks `editor_mode`. If `'elements'`:
  - Calls `PageRenderer::renderPage($contentId)` → overwrites `$content['body']`
  - Calls `PageRenderer::getPageCss($contentId)` → passes as `$elementCss`
- `blogPost()`: Same element-mode branch as `page()`.
- Both methods pass `$elementCss` to template data (defaults to `''`).

---

### 12. `templates/public/layout.php`

**Change**: Added `<style id="litecms-element-styles">` block in `<head>` for element CSS injection.

```php
<?php if (!empty($elementCss)): ?>
    <style id="litecms-element-styles"><?= $elementCss ?></style>
<?php endif; ?>
```

Inserted after the `yieldSection('head')` call, before closing `</head>`.

---

### 13. `templates/admin/layout.php`

**Change**: Added "Elements" navigation link to the sidebar, in the Content section.

```php
<a href="/admin/elements"
   class="<?= ($activeNav ?? '') === 'elements' ? 'active' : '' ?>">
    <span class="nav-icon">&#9647;</span> Elements
</a>
```

Placed after "Content Types" and before "Generate Page" in the sidebar nav.

---

### 14. `public/index.php`

**Changes**:
- Added `use App\Admin\ElementController;` import
- Registered element CRUD routes within the `/admin` group:

```php
// Element catalogue routes
$router->get('/elements', [ElementController::class, 'index']);
$router->get('/elements/api/list', [ElementController::class, 'apiList']);
$router->get('/elements/create', [ElementController::class, 'create']);
$router->post('/elements', [ElementController::class, 'store']);
$router->get('/elements/{id}/edit', [ElementController::class, 'edit']);
$router->get('/elements/{id}/preview', [ElementController::class, 'preview']);
$router->put('/elements/{id}', [ElementController::class, 'update']);
$router->delete('/elements/{id}', [ElementController::class, 'delete']);
```

**Note**: `/elements/api/list` must be registered before `/elements/{id}/edit` to avoid `api` matching as an `{id}` parameter. Same for `/elements/create`.

---

### 15. `public/assets/css/admin.css`

**Changes**: Added ~400 lines of CSS for the element catalogue and editor UI.

**New CSS sections**:
- `.elements-filters` — Filter bar for category dropdown and search
- `.btn-link`, `.btn-secondary` — Button variants
- `.elements-grid` — Responsive grid layout for element cards
- `.element-card` — Card component (header with category/status badges, body with name/slug/description, footer with usage count and action buttons)
- `.element-editor-grid` — Two-column editor layout (380px meta panel + fluid code panel)
- `.element-meta-panel`, `.element-code-panel` — Panel styling
- `.code-editor` — Monospace textarea with dark theme (background: #1e1e2e, color: #cdd6f4)
- `.code-section` — Spacing between code editor sections
- `.element-preview-container` — White bordered container for live preview
- `.slot-item` — Slot row styling with header, fields grid, actions
- `.slot-fields` — Two-column grid for slot field inputs
- `.sub-slots-container`, `.sub-slot-item` — Nested sub-slot styling for list type
- `.form-actions` — Button row alignment
- Responsive: at `max-width: 900px`, editor grid and elements grid collapse to single column

---

## Detailed Class Specifications

### `App\PageBuilder\SlotRenderer`

```
PROPERTIES:
  (none — all static methods)

PUBLIC METHODS:
  - public static render(string $template, array $data): string
      1. Process sections: processSections($template, $data)
      2. Process inverted sections: processInvertedSections($template, $data)
      3. Replace {{{key}}} with raw value (no escaping)
      4. Replace {{key}} with HTML-escaped value
      Return final template string.

PRIVATE METHODS:
  - private static processSections(string $template, array $data): string
      Iteratively (max 10 rounds) applies regex to match innermost {{#key}}...{{/key}}.
      For each match:
        value = resolve(key, data)
        If sequential array → loop: concatenate render(inner, merge(data, item)) per item
        If truthy → render(inner, data) once
        If falsy → remove (return '')
      Stops when no more replacements are made.

  - private static processInvertedSections(string $template, array $data): string
      Same iterative approach for {{^key}}...{{/key}}.
      If value is empty/falsy → render(inner, data)
      If truthy → remove

  - private static resolve(string $key, array $data): mixed
      Split key by '.', walk into nested arrays.
      Return '' if path not found.
      Return arrays/booleans as-is for section evaluation.

  - private static isSequential(array $arr): bool
      Returns true if array_keys === range(0, count-1).
```

### `App\PageBuilder\PageRenderer`

```
PROPERTIES:
  (none — all static methods)

PUBLIC METHODS:
  - public static renderPage(int $contentId): string
      instances = loadInstances(contentId)
      If empty → return ''
      Concatenate renderInstance(instance) for each.

  - public static getPageCss(int $contentId): string
      instances = loadInstances(contentId)
      Deduplicate by element_id.
      For each unique element with non-empty CSS:
        Append "/* Element: {name} */\n{css}\n\n"

  - public static renderInstance(array $instance): string
      Extract slug, element_id, html_template, slot_data.
      Parse slot_data from JSON string if needed.
      Render via SlotRenderer::render(template, slotData).
      Wrap in <div class="lcms-el lcms-el-{slug}" data-element-id="{id}">

PRIVATE METHODS:
  - private static loadInstances(int $contentId): array
      QueryBuilder join: page_elements LEFT JOIN elements
      WHERE content_id = $contentId
      ORDER BY sort_order ASC
      Returns array of rows with: id, element_id, sort_order, slot_data_json,
        slug, name, html_template, css
```

### `App\PageBuilder\SeedElements`

```
PROPERTIES:
  (none — all static methods)

PUBLIC METHODS:
  - public static seed(): int
      For each definition: check slug existence, insert if new.
      Return count of newly inserted elements.

  - public static definitions(): array
      Returns [heroSection(), textSection(), featureGrid(), ctaBanner(),
               imageText(), testimonialSection(), faqSection()]

PRIVATE METHODS:
  - private static heroSection(): array
  - private static textSection(): array
  - private static featureGrid(): array
  - private static ctaBanner(): array
  - private static imageText(): array
  - private static testimonialSection(): array
  - private static faqSection(): array

  Each returns an element definition array with:
    slug, name, description, category, status='active',
    slots_json (JSON string), html_template, css
```

### `App\Admin\ElementController`

```
PROPERTIES:
  - private App $app
  - private const VALID_SLOT_TYPES = ['text','richtext','image','link','select','boolean','number','list']
  - private const VALID_STATUSES = ['active','draft','archived']

CONSTRUCTOR:
  __construct(App $app) — stores app reference

PUBLIC METHODS:
  - index(Request $request): Response
      Reads category filter and search query.
      Queries elements with optional WHERE clauses.
      Computes usage_count per element (page_elements count).
      Extracts unique categories for filter dropdown.
      Renders admin/elements/index template. Returns with security headers.

  - create(Request $request): Response
      Creates empty element array with defaults.
      Renders admin/elements/edit template (isNew=true).

  - store(Request $request): Response
      Reads form data, generates slug, validates.
      On error: flash error, redirect to /admin/elements/create.
      On success: insert into elements table (with author_id, updated_at).
      Flash success, redirect to /admin/elements/{id}/edit.

  - edit(Request $request, string $id): Response
      Loads element by ID (404 redirect if not found).
      Counts page_elements usage.
      Renders admin/elements/edit template (isNew=false).

  - update(Request $request, string $id): Response
      Loads existing element (404 redirect if not found).
      Reads form data, validates (with excludeId for slug uniqueness).
      On error: flash error, redirect to edit page.
      On success: update row, increment version by 1, set updated_at.
      Flash success, redirect to edit page.

  - delete(Request $request, string $id): Response
      Loads element (404 redirect if not found).
      Counts page_elements usage.
      If usage > 0: flash error with count, redirect to index.
      If unused: delete row, flash success, redirect to index.

  - preview(Request $request, string $id): Response
      Loads element (JSON 404 if not found).
      Generates sample data from slots_json.
      Renders HTML via SlotRenderer, wraps in .lcms-el div.
      Returns JSON {success: true, html, css}.

  - apiList(Request $request): Response
      Queries active elements (id, slug, name, description, category, slots_json).
      Returns JSON {success: true, elements: [...]}.

  - static generateSampleData(array $slots): array
      Per slot type:
        text → "Sample {label}" (or default)
        richtext → "<p>Sample {label} content.</p>" (or default)
        image → "" (or default)
        link → {url:'#', text:label, target:'_self'} (or default)
        select → first option (or default)
        boolean → true (or default)
        number → 3 (or default)
        list → [sampleItem, sampleItem] (recursive for sub_slots)

PRIVATE METHODS:
  - readFormData(Request): array — extracts 8 form fields
  - validate(array, ?int): ?string — name/slug/status/category/slots validation
  - validateSlotsJson(string): ?string — JSON structure, key uniqueness, type validation
  - generateSlug(string, string): string — slug generation with fallback
  - withSecurityHeaders(Response): Response — X-Frame-Options + CSP
```

---

## Route Registration

```
GET    /admin/elements              → ElementController::index
GET    /admin/elements/api/list     → ElementController::apiList
GET    /admin/elements/create       → ElementController::create
POST   /admin/elements              → ElementController::store
GET    /admin/elements/{id}/edit    → ElementController::edit
GET    /admin/elements/{id}/preview → ElementController::preview
PUT    /admin/elements/{id}         → ElementController::update
DELETE /admin/elements/{id}         → ElementController::delete
```

**Route ordering note**: `/elements/api/list` and `/elements/create` are registered before `/elements/{id}/edit` to prevent `api` or `create` from being captured as `{id}`.

---

## Slot Type System

| Type | Admin Input | Template Output | Default Sample |
|------|------------|-----------------|----------------|
| `text` | `<input type="text">` | `{{key}}` (escaped) | "Sample {label}" |
| `richtext` | `<textarea>` (WYSIWYG in 6.2) | `{{{key}}}` (raw HTML) | `<p>Sample {label} content.</p>` |
| `image` | Media browser (text input for now) | `{{key}}` in `src=""` | `""` |
| `link` | URL + text + target fields | `{{key.url}}`, `{{key.text}}`, `{{key.target}}` | `{url:'#', text:label, target:'_self'}` |
| `select` | `<select>` from options | `{{key}}` | First option value |
| `boolean` | `<input type="checkbox">` | `{{#key}}...{{/key}}` conditional | `true` |
| `number` | `<input type="number">` | `{{key}}` | `3` |
| `list` | Repeatable group of sub_slots | `{{#key}}...{{/key}}` loop | Two sample items |

---

## Acceptance Test Procedures

### Test 1: All PageBuilder classes autoloadable
```
Verify class_exists() for:
  - App\PageBuilder\SlotRenderer
  - App\PageBuilder\PageRenderer
  - App\PageBuilder\SeedElements
```

### Test 2: ElementController autoloadable
```
Verify class_exists(App\Admin\ElementController)
```

### Test 3: All required files exist
```
Verify file_exists() for all 10 key files:
  migrations/004_page_builder.{sqlite,mysql,pgsql}.sql
  templates/admin/elements/{index,edit}.php
  public/assets/js/element-editor.js
  app/PageBuilder/{SlotRenderer,PageRenderer,SeedElements}.php
  app/Admin/ElementController.php
```

### Test 4: Migration creates tables + editor_mode column
```
1. Run migrations on fresh test DB.
2. Verify tables exist: elements, page_elements, element_proposals.
3. Verify content table has editor_mode column.
```

### Test 5: SeedElements populates 7 elements (idempotent)
```
1. Call SeedElements::seed() → returns 7.
2. Verify elements table has 7 rows.
3. Call SeedElements::seed() again → returns 0, still 7 rows.
```

### Test 6: SlotRenderer `{{key}}` escapes HTML
```
Render '<h1>{{title}}</h1>' with title='<script>alert("xss")</script>'
Verify output contains '&lt;script&gt;' and NOT '<script>'.
```

### Test 7: SlotRenderer `{{{key}}}` outputs raw HTML
```
Render '{{{body}}}' with body='<p>Hello <strong>World</strong></p>'
Verify output === input (no escaping).
```

### Test 8: SlotRenderer `{{#key}}...{{/key}}` conditional
```
Template: '{{#show}}Visible{{/show}}{{^show}}Hidden{{/show}}'
With show=true  → 'Visible'
With show=false → 'Hidden'
```

### Test 9: SlotRenderer `{{#items}}...{{/items}}` loops
```
Template: '<ul>{{#items}}<li>{{name}}</li>{{/items}}</ul>'
With items=[{name:'Alpha'},{name:'Beta'},{name:'Gamma'}]
Verify output contains all three <li> elements.
```

### Test 10: SlotRenderer `{{^key}}...{{/key}}` inverted section
```
Template: '{{^items}}No items{{/items}}'
With items=[]  → 'No items'
With items=[{x:1}] → ''
```

### Test 11: SlotRenderer `{{key.sub}}` dot notation
```
Render '<a href="{{cta.url}}">{{cta.text}}</a>'
With cta={url:'https://example.com', text:'Click Me'}
Verify output contains both values.
```

### Test 12: PageRenderer `renderInstance()` wraps correctly
```
Render an instance with slug='test-element', element_id=99.
Verify output contains class="lcms-el lcms-el-test-element" and data-element-id="99".
```

### Test 13: PageRenderer full page assembly
```
1. Create content with editor_mode='elements'.
2. Insert 2 page_elements (hero-section + text-section).
3. Call renderPage() → verify HTML contains both lcms-el-hero-section and lcms-el-text-section.
4. Call getPageCss() → verify CSS contains both element scopes.
```

### Tests 14–21: ElementController CRUD
```
14. index() returns 200, body contains seed element names.
15. store() creates element, returns 302 redirect to edit page.
16. edit() returns 200, body contains element name.
17. update() persists changes, increments version to 2.
18. delete() blocks when element has page_elements usage.
19. delete() succeeds when element has no usage.
20. preview() returns JSON with success=true, html, css.
21. apiList() returns JSON with success=true and ≥7 active elements.
```

### Test 22: Validation rejects duplicate slot keys
```
Submit element with two slots both having key='title'.
Verify element is NOT created (validation error).
```

### Tests 23–28: Integration checks (file content verification)
```
23. FrontController source contains 'use App\PageBuilder\PageRenderer' and 'editor_mode' check.
24. Public layout contains 'elementCss' and 'litecms-element-styles'.
25. Admin layout contains '/admin/elements' and 'Elements' nav link.
26. index.php contains ElementController import and all route registrations.
27. admin.css contains required class names (.elements-grid, .element-card, .element-editor-grid, .code-editor, .slot-item, .element-preview-container).
28. element-editor.js contains required functions (initElementEditor, createSlotRow, serializeSlots, refreshPreview).
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);`
- PSR-4 namespacing: `App\PageBuilder\SlotRenderer` → `app/PageBuilder/SlotRenderer.php`
- No framework imports — only native PHP
- All template output uses `$this->e()` for escaping (XSS prevention)
- Parameterized queries via QueryBuilder throughout

### Security Considerations
- ElementController adds `X-Frame-Options: DENY` and CSP headers to all HTML responses
- SlotRenderer's `{{key}}` uses `htmlspecialchars(... ENT_QUOTES, 'UTF-8')` for all escaped output
- PageRenderer's `renderInstance()` escapes the element slug in the wrapper div
- CSRF protection on all store/update/delete operations (via CsrfMiddleware)
- Slot validation prevents injection via malformed JSON

### Edge Cases
- Empty element catalogue: index page shows empty state message with create link
- Element in use: delete is blocked with user-friendly message showing usage count
- Malformed slots JSON: validation catches invalid JSON, missing keys, duplicate keys
- Missing element on page: `loadInstances()` uses LEFT JOIN — if an element is deleted (should be blocked by RESTRICT), the instance still loads with null template
- No slot data: SlotRenderer returns empty strings for missing keys (graceful degradation)
- Nested sections: iterative processing with max depth 10 handles nesting without stack overflow

### Dependencies on Previous Chunks
- **Chunk 1.1**: App, Router, Request, Response, Config, TemplateEngine
- **Chunk 1.2**: Connection, QueryBuilder (including leftJoin support), Migrator
- **Chunk 1.3**: Session (for flash messages, CSRF, user_id), AuthMiddleware, CsrfMiddleware
- **Chunk 2.1**: Admin layout template (sidebar nav structure, CSS custom properties)
- **Chunk 2.2**: Content table and ContentController (editor_mode column added to existing table)
- **Chunk 3.1**: FrontController (page() and blogPost() methods modified)
- **Chunk 3.2**: Public layout template (elementCss style block added)

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `migrations/004_page_builder.sqlite.sql` | SQL | Create |
| 2 | `migrations/004_page_builder.mysql.sql` | SQL | Create |
| 3 | `migrations/004_page_builder.pgsql.sql` | SQL | Create |
| 4 | `app/PageBuilder/SlotRenderer.php` | Class | Create |
| 5 | `app/PageBuilder/PageRenderer.php` | Class | Create |
| 6 | `app/PageBuilder/SeedElements.php` | Class | Create |
| 7 | `app/Admin/ElementController.php` | Class | Create |
| 8 | `templates/admin/elements/index.php` | Template | Create |
| 9 | `templates/admin/elements/edit.php` | Template | Create |
| 10 | `public/assets/js/element-editor.js` | JavaScript | Create |
| 11 | `app/Templates/FrontController.php` | Class | Modify (add PageRenderer import + element-mode branches) |
| 12 | `templates/public/layout.php` | Template | Modify (add elementCss style block) |
| 13 | `templates/admin/layout.php` | Template | Modify (add Elements nav link) |
| 14 | `public/index.php` | Entry point | Modify (add ElementController import + routes) |
| 15 | `public/assets/css/admin.css` | Stylesheet | Modify (add ~400 lines of element UI styles) |

---

## Estimated Scope

- **New PHP classes**: 3 (SlotRenderer, PageRenderer, SeedElements) + 1 controller (ElementController)
- **New templates**: 2 (elements/index, elements/edit)
- **New JavaScript**: 1 (element-editor.js, ~287 lines)
- **New CSS**: ~400 lines (element catalogue grid, editor layout, slot builder, code editor)
- **New SQL migrations**: 3 (sqlite, mysql, pgsql)
- **Modified files**: 5 (FrontController, public layout, admin layout, index.php, admin.css)
- **Approximate new PHP LOC**: ~650 lines
