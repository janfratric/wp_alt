# Chunk 5.1 — Custom Content Types
## Detailed Implementation Plan

---

## Overview

This chunk builds the system for defining and using custom content types with custom fields. Admins can create new content types (e.g., "Products", "Team Members") with configurable fields (text, textarea, image, select, boolean). Content of custom types reuses the existing CRUD from `ContentController` but renders additional custom fields in the editor. Public archive pages display custom type listings.

**Prerequisite chunks**: 1.1–4.2 (all 11 complete chunks). Key dependencies:
- `content_types` and `custom_fields` tables already exist in migration `001_initial`
- `FrontController::archive()` already handles custom type archive queries
- `templates/public/archive.php` already renders archive listings
- `ContentController` handles page/post CRUD — must be extended for custom types

---

## File Creation Order

Files are listed in dependency order — each file depends only on files listed before it.

### Files to Create (4 files)

| # | File | Type |
|---|------|------|
| 1 | `app/Admin/ContentTypeController.php` | Controller |
| 2 | `templates/admin/content-types/index.php` | Template |
| 3 | `templates/admin/content-types/edit.php` | Template |
| 4 | `public/assets/js/field-builder.js` | JavaScript |

### Files to Modify (5 files)

| # | File | Change |
|---|------|--------|
| 5 | `templates/admin/layout.php` | Add "Content Types" nav link |
| 6 | `public/index.php` | Register content type routes (admin + dynamic public) |
| 7 | `app/Admin/ContentController.php` | Custom type validation, custom field CRUD, pass types to templates |
| 8 | `templates/admin/content/index.php` | Dynamic type filter dropdown |
| 9 | `templates/admin/content/edit.php` | Render custom fields, dynamic type dropdown |

---

## 1. `app/Admin/ContentTypeController.php`

**Purpose**: Full CRUD controller for managing custom content type definitions. Handles listing, creating, editing, updating, and deleting content types. Validates type slugs, names, and field JSON structures.

**Class**: `App\Admin\ContentTypeController`

**Dependencies**: `App\Core\App`, `App\Core\Request`, `App\Core\Response`, `App\Core\Config`, `App\Database\QueryBuilder`, `App\Auth\Session`

```
PROPERTIES:
  - private App $app

CONSTRUCTOR:
  __construct(App $app)
    $this->app = $app

METHODS:

  public index(Request $request): Response
    Purpose: GET /admin/content-types — List all custom content types.
    Logic:
      1. Query content_types table, ordered by name ASC.
      2. For each type, query a count of content items of that type.
      3. Render admin/content-types/index template.
    Template data: title, activeNav='content-types', types (array with content counts)

  public create(Request $request): Response
    Purpose: GET /admin/content-types/create — Show empty type editor.
    Logic:
      1. Build empty type array: {id: null, slug: '', name: '', fields_json: '[]', has_archive: 1}
      2. Render admin/content-types/edit template.
    Template data: title='Create Content Type', activeNav='content-types', type, isNew=true

  public store(Request $request): Response
    Purpose: POST /admin/content-types — Validate and save new content type.
    Logic:
      1. Read form data: name, slug, has_archive, fields_json.
      2. Validate: name required, slug required + unique + not reserved, fields_json is valid JSON array.
      3. Auto-generate slug from name if empty (same slugify logic as content).
      4. Insert into content_types table.
      5. Flash success, redirect to edit page.
    Reserved slugs: page, post, blog, admin, contact, assets, storage (these would conflict with routes).
    Validation:
      - name: required, max 100 chars
      - slug: required, max 50 chars, a-z0-9 and hyphens only, unique in content_types, not reserved
      - fields_json: valid JSON, must be array, each item must have key, label, type
      - has_archive: 0 or 1

  public edit(Request $request, string $id): Response
    Purpose: GET /admin/content-types/{id}/edit — Show type editor with existing data.
    Logic:
      1. Query content_types by ID.
      2. If not found, flash error, redirect to index.
      3. Query count of content using this type.
      4. Render admin/content-types/edit template.
    Template data: title, activeNav='content-types', type, isNew=false, contentCount

  public update(Request $request, string $id): Response
    Purpose: PUT /admin/content-types/{id} — Validate and update existing type.
    Logic:
      1. Verify type exists.
      2. Read form data.
      3. Validate (slug uniqueness check excludes current ID).
      4. Update content_types record.
      5. Flash success, redirect to edit page.
    Note: Slug change is allowed but if content items reference the old slug, they must be updated too.
    When slug changes: UPDATE content SET type = :newSlug WHERE type = :oldSlug

  public delete(Request $request, string $id): Response
    Purpose: DELETE /admin/content-types/{id} — Delete a content type definition.
    Logic:
      1. Verify type exists.
      2. Check if content items use this type.
      3. If content exists: flash error "Cannot delete — X content items use this type. Delete or reassign them first."
      4. If no content: delete the content type record, flash success, redirect to index.
    Safety: Never delete a type that has content referencing it.

  private readFormData(Request $request): array
    Returns: ['name' => trim(input), 'slug' => trim(input), 'has_archive' => int, 'fields_json' => string]

  private validate(array $data, ?int $excludeId = null): ?string
    Validates all fields. Returns error message or null.
    Checks:
      - name required, max 100 chars
      - slug required, a-z0-9 and hyphens, max 50 chars
      - slug not in reserved list ['page', 'post', 'blog', 'admin', 'contact', 'assets', 'storage']
      - slug unique in content_types (excluding $excludeId)
      - fields_json parses as JSON array
      - Each field object has: key (string, a-z0-9_), label (string), type (one of: text, textarea, image, select, boolean)
      - If type is 'select', options must be a non-empty array of strings

  private generateSlug(string $name, string $manualSlug = ''): string
    Same logic as ContentController::generateSlug() — lowercase, strip non-alphanum, collapse hyphens.

  private validateFieldsJson(string $json): ?string
    Decodes JSON, validates each field definition.
    Returns error message or null.
    Valid field types: text, textarea, image, select, boolean
    Required per field: key, label, type
    Optional per field: required (bool), options (array, required for select type)
    Field keys must be unique within the type.

  private withSecurityHeaders(Response $response): Response
    Same security header logic as ContentController.
```

**Code Template**:

```php
<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;

class ContentTypeController
{
    private App $app;

    private const RESERVED_SLUGS = ['page', 'post', 'blog', 'admin', 'contact', 'assets', 'storage'];
    private const VALID_FIELD_TYPES = ['text', 'textarea', 'image', 'select', 'boolean'];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $types = QueryBuilder::query('content_types')
            ->select()
            ->orderBy('name', 'ASC')
            ->get();

        // Get content counts per type
        foreach ($types as &$type) {
            $type['content_count'] = QueryBuilder::query('content')
                ->select()
                ->where('type', $type['slug'])
                ->count();
        }
        unset($type);

        $html = $this->app->template()->render('admin/content-types/index', [
            'title'     => 'Content Types',
            'activeNav' => 'content-types',
            'types'     => $types,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    public function create(Request $request): Response
    {
        $type = [
            'id'          => null,
            'slug'        => '',
            'name'        => '',
            'fields_json' => '[]',
            'has_archive' => 1,
        ];

        $html = $this->app->template()->render('admin/content-types/edit', [
            'title'        => 'Create Content Type',
            'activeNav'    => 'content-types',
            'type'         => $type,
            'isNew'        => true,
            'contentCount' => 0,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    public function store(Request $request): Response
    {
        $data = $this->readFormData($request);
        $data['slug'] = $this->generateSlug($data['name'], $data['slug']);

        $error = $this->validate($data);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/content-types/create');
        }

        $id = QueryBuilder::query('content_types')->insert([
            'slug'        => $data['slug'],
            'name'        => $data['name'],
            'fields_json' => $data['fields_json'],
            'has_archive' => $data['has_archive'],
        ]);

        Session::flash('success', 'Content type created successfully.');
        return Response::redirect('/admin/content-types/' . $id . '/edit');
    }

    public function edit(Request $request, string $id): Response
    {
        $type = QueryBuilder::query('content_types')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($type === null) {
            Session::flash('error', 'Content type not found.');
            return Response::redirect('/admin/content-types');
        }

        $contentCount = QueryBuilder::query('content')
            ->select()
            ->where('type', $type['slug'])
            ->count();

        $html = $this->app->template()->render('admin/content-types/edit', [
            'title'        => 'Edit: ' . $type['name'],
            'activeNav'    => 'content-types',
            'type'         => $type,
            'isNew'        => false,
            'contentCount' => $contentCount,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    public function update(Request $request, string $id): Response
    {
        $existing = QueryBuilder::query('content_types')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($existing === null) {
            Session::flash('error', 'Content type not found.');
            return Response::redirect('/admin/content-types');
        }

        $data = $this->readFormData($request);
        $data['slug'] = $this->generateSlug($data['name'], $data['slug']);

        $error = $this->validate($data, (int) $id);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/content-types/' . $id . '/edit');
        }

        QueryBuilder::query('content_types')->where('id', (int) $id)->update([
            'slug'        => $data['slug'],
            'name'        => $data['name'],
            'fields_json' => $data['fields_json'],
            'has_archive' => $data['has_archive'],
        ]);

        // If slug changed, update all content referencing the old slug
        if ($existing['slug'] !== $data['slug']) {
            QueryBuilder::query('content')
                ->where('type', $existing['slug'])
                ->update(['type' => $data['slug']]);
        }

        Session::flash('success', 'Content type updated successfully.');
        return Response::redirect('/admin/content-types/' . $id . '/edit');
    }

    public function delete(Request $request, string $id): Response
    {
        $type = QueryBuilder::query('content_types')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($type === null) {
            Session::flash('error', 'Content type not found.');
            return Response::redirect('/admin/content-types');
        }

        $contentCount = QueryBuilder::query('content')
            ->select()
            ->where('type', $type['slug'])
            ->count();

        if ($contentCount > 0) {
            Session::flash('error',
                'Cannot delete — ' . $contentCount . ' content item(s) use this type. Delete or reassign them first.');
            return Response::redirect('/admin/content-types');
        }

        QueryBuilder::query('content_types')->where('id', (int) $id)->delete();

        Session::flash('success', 'Content type "' . $type['name'] . '" deleted.');
        return Response::redirect('/admin/content-types');
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    private function readFormData(Request $request): array
    {
        return [
            'name'        => trim((string) $request->input('name', '')),
            'slug'        => trim((string) $request->input('slug', '')),
            'has_archive' => (int) $request->input('has_archive', '1'),
            'fields_json' => (string) $request->input('fields_json', '[]'),
        ];
    }

    private function validate(array $data, ?int $excludeId = null): ?string
    {
        if ($data['name'] === '') {
            return 'Name is required.';
        }
        if (mb_strlen($data['name']) > 100) {
            return 'Name must be 100 characters or less.';
        }
        if ($data['slug'] === '') {
            return 'Slug is required.';
        }
        if (mb_strlen($data['slug']) > 50) {
            return 'Slug must be 50 characters or less.';
        }
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $data['slug'])) {
            return 'Slug must contain only lowercase letters, numbers, and hyphens.';
        }
        if (in_array($data['slug'], self::RESERVED_SLUGS, true)) {
            return 'The slug "' . $data['slug'] . '" is reserved and cannot be used.';
        }

        // Check slug uniqueness in content_types
        $qb = QueryBuilder::query('content_types')->select()->where('slug', $data['slug']);
        if ($excludeId !== null) {
            $qb->where('id', '!=', $excludeId);
        }
        if ($qb->first() !== null) {
            return 'A content type with this slug already exists.';
        }

        // Validate fields JSON
        $fieldsError = $this->validateFieldsJson($data['fields_json']);
        if ($fieldsError !== null) {
            return $fieldsError;
        }

        if (!in_array($data['has_archive'], [0, 1], true)) {
            return 'Invalid archive setting.';
        }

        return null;
    }

    private function validateFieldsJson(string $json): ?string
    {
        $fields = json_decode($json, true);
        if (!is_array($fields)) {
            return 'Fields must be a valid JSON array.';
        }

        $keys = [];
        foreach ($fields as $i => $field) {
            if (!is_array($field)) {
                return 'Field #' . ($i + 1) . ' must be an object.';
            }
            if (empty($field['key']) || !is_string($field['key'])) {
                return 'Field #' . ($i + 1) . ': key is required.';
            }
            if (!preg_match('/^[a-z0-9_]+$/', $field['key'])) {
                return 'Field #' . ($i + 1) . ': key must contain only lowercase letters, numbers, and underscores.';
            }
            if (in_array($field['key'], $keys, true)) {
                return 'Field #' . ($i + 1) . ': duplicate key "' . $field['key'] . '".';
            }
            $keys[] = $field['key'];

            if (empty($field['label']) || !is_string($field['label'])) {
                return 'Field #' . ($i + 1) . ': label is required.';
            }
            if (empty($field['type']) || !in_array($field['type'], self::VALID_FIELD_TYPES, true)) {
                return 'Field #' . ($i + 1) . ': type must be one of: '
                    . implode(', ', self::VALID_FIELD_TYPES) . '.';
            }
            if ($field['type'] === 'select') {
                if (empty($field['options']) || !is_array($field['options'])) {
                    return 'Field #' . ($i + 1) . ': select type requires a non-empty options array.';
                }
            }
        }

        return null;
    }

    private function generateSlug(string $name, string $manualSlug = ''): string
    {
        $base = $manualSlug !== '' ? $manualSlug : $name;
        $slug = strtolower($base);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = preg_replace('/-+/', '-', $slug);
        return $slug !== '' ? $slug : 'untitled';
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

## 2. `templates/admin/content-types/index.php`

**Purpose**: List all custom content types with name, slug, field count, content count, archive status, and action buttons.

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Content Types</h1>
    <a href="/admin/content-types/create" class="btn btn-primary">+ New Content Type</a>
</div>

<?php if (empty($types)): ?>
    <div class="card">
        <div class="card-body">
            <div class="empty-state">
                <p>No custom content types defined yet.</p>
                <p class="text-muted">Content types let you create structured content like Products, Team Members, Testimonials, etc.</p>
                <a href="/admin/content-types/create" class="btn btn-primary">Create your first content type</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th>Fields</th>
                        <th>Content Items</th>
                        <th>Archive</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $ct): ?>
                        <?php $fields = json_decode($ct['fields_json'] ?? '[]', true) ?: []; ?>
                        <tr>
                            <td>
                                <a href="/admin/content-types/<?= (int)$ct['id'] ?>/edit">
                                    <strong><?= $this->e($ct['name']) ?></strong>
                                </a>
                            </td>
                            <td class="text-muted"><?= $this->e($ct['slug']) ?></td>
                            <td><?= count($fields) ?> field(s)</td>
                            <td>
                                <?php if ((int)($ct['content_count'] ?? 0) > 0): ?>
                                    <a href="/admin/content?type=<?= urlencode($ct['slug']) ?>">
                                        <?= (int)$ct['content_count'] ?> item(s)
                                    </a>
                                <?php else: ?>
                                    0 items
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)($ct['has_archive'] ?? 1)): ?>
                                    <span class="badge badge-published">Yes</span>
                                <?php else: ?>
                                    <span class="badge">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/content-types/<?= (int)$ct['id'] ?>/edit"
                                   class="btn btn-sm">Edit</a>
                                <button type="button" class="btn btn-sm btn-danger delete-ct-btn"
                                        data-id="<?= (int)$ct['id'] ?>"
                                        data-name="<?= $this->e($ct['name']) ?>">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Delete form -->
    <form method="POST" id="delete-ct-form" style="display:none;">
        <?= $this->csrfField() ?>
        <input type="hidden" name="_method" value="DELETE">
    </form>

    <script>
    document.querySelectorAll('.delete-ct-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var name = this.getAttribute('data-name');
            if (confirm('Delete content type "' + name + '"? This cannot be undone.')) {
                var form = document.getElementById('delete-ct-form');
                form.action = '/admin/content-types/' + this.getAttribute('data-id');
                form.submit();
            }
        });
    });
    </script>
<?php endif; ?>
```

---

## 3. `templates/admin/content-types/edit.php`

**Purpose**: Create/edit form for a content type. Includes name, slug, has_archive toggle, and a dynamic field builder interface. The field builder lets users add, remove, and reorder custom fields with their properties (key, label, type, required, options).

**Template variables**: `$type` (content type record), `$isNew` (bool), `$contentCount` (int)

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $isNew ? 'Create Content Type' : 'Edit: ' . $this->e($type['name']) ?></h1>
    <a href="/admin/content-types" class="btn">← Back to Content Types</a>
</div>

<form method="POST"
      action="<?= $isNew ? '/admin/content-types' : '/admin/content-types/' . (int)$type['id'] ?>"
      id="content-type-form">
    <?= $this->csrfField() ?>
    <?php if (!$isNew): ?>
        <input type="hidden" name="_method" value="PUT">
    <?php endif; ?>

    <div class="card" style="margin-bottom: 1rem;">
        <div class="card-header">Type Settings</div>
        <div class="card-body">
            <div class="form-group">
                <label for="ct-name">Name <span style="color:var(--color-error);">*</span></label>
                <input type="text" id="ct-name" name="name"
                       value="<?= $this->e($type['name']) ?>"
                       required maxlength="100"
                       placeholder="e.g. Products, Team Members, Testimonials">
            </div>

            <div class="form-group">
                <label for="ct-slug">Slug <span style="color:var(--color-error);">*</span></label>
                <input type="text" id="ct-slug" name="slug"
                       value="<?= $this->e($type['slug']) ?>"
                       maxlength="50"
                       placeholder="auto-generated-from-name"
                       pattern="[a-z0-9]+(-[a-z0-9]+)*">
                <small class="text-muted">Used in URLs. Lowercase letters, numbers, and hyphens only.</small>
            </div>

            <div class="form-group mb-0">
                <label>
                    <input type="checkbox" name="has_archive" value="1"
                           <?= (int)($type['has_archive'] ?? 1) ? 'checked' : '' ?>>
                    Enable archive page
                </label>
                <small class="text-muted">When enabled, a public listing page is available at /slug/</small>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 1rem;">
        <div class="card-header">
            <span>Custom Fields</span>
            <button type="button" id="add-field-btn" class="btn btn-sm btn-primary">+ Add Field</button>
        </div>
        <div class="card-body">
            <div id="field-list">
                <!-- Field rows rendered by JS from fields_json -->
            </div>
            <p id="no-fields-msg" class="text-muted" style="text-align:center; padding: 1rem 0;">
                No custom fields defined. Click "Add Field" to begin.
            </p>
        </div>
    </div>

    <!-- Hidden input to hold serialized fields JSON -->
    <input type="hidden" name="fields_json" id="fields-json-input"
           value="<?= $this->e($type['fields_json'] ?? '[]') ?>">

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            <?= $isNew ? 'Create Content Type' : 'Update Content Type' ?>
        </button>
        <?php if (!$isNew && ($contentCount ?? 0) === 0): ?>
            <button type="button" class="btn btn-danger" id="delete-type-btn"
                    style="margin-left: 0.5rem;">Delete Type</button>
        <?php elseif (!$isNew): ?>
            <span class="text-muted" style="margin-left: 0.5rem;">
                <?= (int)$contentCount ?> content item(s) use this type
            </span>
        <?php endif; ?>
    </div>
</form>

<?php if (!$isNew): ?>
<form method="POST" id="delete-type-form"
      action="/admin/content-types/<?= (int)$type['id'] ?>" style="display:none;">
    <?= $this->csrfField() ?>
    <input type="hidden" name="_method" value="DELETE">
</form>
<?php endif; ?>

<script src="/assets/js/field-builder.js"></script>
<script>
(function() {
    // Initialize field builder with existing fields
    var initialFields = <?= $type['fields_json'] ?? '[]' ?>;
    if (typeof initFieldBuilder === 'function') {
        initFieldBuilder(initialFields);
    }

    // Auto-generate slug from name
    var nameInput = document.getElementById('ct-name');
    var slugInput = document.getElementById('ct-slug');
    var slugManuallyEdited = slugInput.value !== '';
    nameInput.addEventListener('input', function() {
        if (!slugManuallyEdited) {
            slugInput.value = nameInput.value.toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');
        }
    });
    slugInput.addEventListener('input', function() {
        slugManuallyEdited = slugInput.value !== '';
    });

    // Delete type button
    var deleteBtn = document.getElementById('delete-type-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            if (confirm('Delete this content type? This cannot be undone.')) {
                document.getElementById('delete-type-form').submit();
            }
        });
    }
})();
</script>
```

---

## 4. `public/assets/js/field-builder.js`

**Purpose**: JavaScript module for the dynamic field builder in the content type editor. Provides add, remove, reorder (drag), and edit capabilities for field definitions. Serializes the field list to a hidden JSON input on form submit.

**Functions**:
- `initFieldBuilder(initialFields)` — Called on page load with existing fields array. Renders field rows.
- `addFieldRow(field)` — Adds a new field row to the UI.
- `removeFieldRow(index)` — Removes a field row.
- `moveFieldRow(from, to)` — Reorders fields.
- `serializeFields()` — Reads all field rows from the DOM, builds JSON, sets hidden input value.

**Field row UI** (each field displayed as a card/row):
- Key input (readonly after first save if content exists — but for simplicity, always editable)
- Label input
- Type dropdown (text, textarea, image, select, boolean)
- Required checkbox
- Options textarea (only visible when type is "select", one option per line)
- Move up / Move down buttons
- Remove button

**Serialization**: On form submit, `serializeFields()` is called to update the hidden `fields_json` input.

```javascript
/**
 * Field Builder — Dynamic custom field management for content type editor.
 */
(function() {
    'use strict';

    var fields = [];
    var fieldListEl = null;
    var noFieldsMsg = null;
    var jsonInput = null;

    window.initFieldBuilder = function(initialFields) {
        fieldListEl = document.getElementById('field-list');
        noFieldsMsg = document.getElementById('no-fields-msg');
        jsonInput = document.getElementById('fields-json-input');

        fields = Array.isArray(initialFields) ? initialFields : [];

        renderAllFields();

        document.getElementById('add-field-btn').addEventListener('click', function() {
            fields.push({
                key: '',
                label: '',
                type: 'text',
                required: false,
                options: []
            });
            renderAllFields();
        });

        // Serialize on form submit
        document.getElementById('content-type-form').addEventListener('submit', function() {
            serializeFields();
        });
    };

    function renderAllFields() {
        fieldListEl.innerHTML = '';
        noFieldsMsg.style.display = fields.length === 0 ? '' : 'none';

        fields.forEach(function(field, index) {
            fieldListEl.appendChild(createFieldRow(field, index));
        });
    }

    function createFieldRow(field, index) {
        var row = document.createElement('div');
        row.className = 'field-row';
        row.setAttribute('data-index', index);
        row.style.cssText = 'border:1px solid var(--border-color,#ddd);border-radius:6px;padding:1rem;margin-bottom:0.75rem;background:var(--bg-card,#fafafa);';

        var isSelect = field.type === 'select';
        var optionsStr = Array.isArray(field.options) ? field.options.join('\n') : '';

        row.innerHTML = ''
            + '<div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-start;">'
            + '  <div style="flex:1;min-width:120px;">'
            + '    <label style="font-size:0.8rem;font-weight:600;">Key</label>'
            + '    <input type="text" class="field-key" value="' + escAttr(field.key) + '"'
            + '           placeholder="field_key" pattern="[a-z0-9_]+" style="width:100%;">'
            + '  </div>'
            + '  <div style="flex:2;min-width:150px;">'
            + '    <label style="font-size:0.8rem;font-weight:600;">Label</label>'
            + '    <input type="text" class="field-label" value="' + escAttr(field.label) + '"'
            + '           placeholder="Display Label" style="width:100%;">'
            + '  </div>'
            + '  <div style="flex:1;min-width:120px;">'
            + '    <label style="font-size:0.8rem;font-weight:600;">Type</label>'
            + '    <select class="field-type" style="width:100%;">'
            + '      <option value="text"' + (field.type === 'text' ? ' selected' : '') + '>Text</option>'
            + '      <option value="textarea"' + (field.type === 'textarea' ? ' selected' : '') + '>Textarea</option>'
            + '      <option value="image"' + (field.type === 'image' ? ' selected' : '') + '>Image</option>'
            + '      <option value="select"' + (field.type === 'select' ? ' selected' : '') + '>Select</option>'
            + '      <option value="boolean"' + (field.type === 'boolean' ? ' selected' : '') + '>Boolean</option>'
            + '    </select>'
            + '  </div>'
            + '  <div style="display:flex;align-items:flex-end;gap:0.25rem;padding-bottom:2px;">'
            + '    <label style="font-size:0.8rem;cursor:pointer;">'
            + '      <input type="checkbox" class="field-required"' + (field.required ? ' checked' : '') + '> Req.'
            + '    </label>'
            + '  </div>'
            + '  <div style="display:flex;align-items:flex-end;gap:0.25rem;padding-bottom:2px;">'
            + '    <button type="button" class="btn btn-sm move-up-btn"' + (index === 0 ? ' disabled' : '') + ' title="Move up">&#9650;</button>'
            + '    <button type="button" class="btn btn-sm move-down-btn"' + (index === fields.length - 1 ? ' disabled' : '') + ' title="Move down">&#9660;</button>'
            + '    <button type="button" class="btn btn-sm btn-danger remove-field-btn" title="Remove field">&times;</button>'
            + '  </div>'
            + '</div>'
            + '<div class="field-options-wrap" style="margin-top:0.5rem;' + (isSelect ? '' : 'display:none;') + '">'
            + '  <label style="font-size:0.8rem;font-weight:600;">Options (one per line)</label>'
            + '  <textarea class="field-options" rows="3" placeholder="Option 1&#10;Option 2&#10;Option 3" style="width:100%;">' + escHtml(optionsStr) + '</textarea>'
            + '</div>';

        // Event: type change toggles options visibility
        var typeSelect = row.querySelector('.field-type');
        var optionsWrap = row.querySelector('.field-options-wrap');
        typeSelect.addEventListener('change', function() {
            optionsWrap.style.display = this.value === 'select' ? '' : 'none';
        });

        // Event: remove
        row.querySelector('.remove-field-btn').addEventListener('click', function() {
            fields.splice(index, 1);
            renderAllFields();
        });

        // Event: move up
        row.querySelector('.move-up-btn').addEventListener('click', function() {
            if (index > 0) {
                readFieldsFromDOM();
                var temp = fields[index];
                fields[index] = fields[index - 1];
                fields[index - 1] = temp;
                renderAllFields();
            }
        });

        // Event: move down
        row.querySelector('.move-down-btn').addEventListener('click', function() {
            if (index < fields.length - 1) {
                readFieldsFromDOM();
                var temp = fields[index];
                fields[index] = fields[index + 1];
                fields[index + 1] = temp;
                renderAllFields();
            }
        });

        return row;
    }

    function readFieldsFromDOM() {
        var rows = fieldListEl.querySelectorAll('.field-row');
        fields = [];
        rows.forEach(function(row) {
            var optionsText = row.querySelector('.field-options').value.trim();
            var options = optionsText ? optionsText.split('\n').map(function(s) { return s.trim(); }).filter(Boolean) : [];
            fields.push({
                key: row.querySelector('.field-key').value.trim(),
                label: row.querySelector('.field-label').value.trim(),
                type: row.querySelector('.field-type').value,
                required: row.querySelector('.field-required').checked,
                options: options
            });
        });
    }

    function serializeFields() {
        readFieldsFromDOM();
        jsonInput.value = JSON.stringify(fields);
    }

    function escAttr(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function escHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
})();
```

---

## 5. Modify `templates/admin/layout.php`

**Change**: Add a "Content Types" navigation link under the Content section (after Media).

**Location**: After line 35 (the Media link), before line 37 (the System section divider).

**Insert**:
```php
                <a href="/admin/content-types"
                   class="<?= ($activeNav ?? '') === 'content-types' ? 'active' : '' ?>">
                    <span class="nav-icon">&#128209;</span> Content Types
                </a>
```

**Result**: The sidebar "Content" section becomes:
- Content
- Media
- Content Types ← new

---

## 6. Modify `public/index.php`

### 6a. Add use statement

After the existing use statements (after line 23 `use App\Admin\SettingsController;`), add:
```php
use App\Admin\ContentTypeController;
```

### 6b. Register admin routes for content types

Inside the `/admin` route group (after the Settings routes, before the AI routes block), add:
```php
    // Content type management routes
    $router->get('/content-types', [ContentTypeController::class, 'index']);
    $router->get('/content-types/create', [ContentTypeController::class, 'create']);
    $router->post('/content-types', [ContentTypeController::class, 'store']);
    $router->get('/content-types/{id}/edit', [ContentTypeController::class, 'edit']);
    $router->put('/content-types/{id}', [ContentTypeController::class, 'update']);
    $router->delete('/content-types/{id}', [ContentTypeController::class, 'delete']);
```

### 6c. Register dynamic public routes for custom type archives

After the admin group closes (after line 111 `});`) and BEFORE the catch-all route (line 114 `$router->get('/{slug}', ...)`), add:

```php
// Dynamic routes for custom content type archives and single items
try {
    $customTypes = QueryBuilder::query('content_types')
        ->select('slug', 'has_archive')
        ->get();

    foreach ($customTypes as $ct) {
        if ((int)$ct['has_archive'] === 1) {
            $router->get('/' . $ct['slug'], [FrontController::class, 'archive']);
        }
        // Single custom type item: /type-slug/item-slug
        $router->get('/' . $ct['slug'] . '/{slug}', [FrontController::class, 'page']);
    }
} catch (\Throwable $e) {
    // Table might not exist yet during first migration run — silently skip
}
```

**Note**: Single items for custom types route to `FrontController::page()`, which already handles arbitrary content by slug. The `page()` method queries content by slug regardless of type. The route `/{typeSlug}/{slug}` just ensures the URL pattern is matched before the catch-all. No FrontController changes are needed for single items.

**Important**: The `page()` method currently redirects posts to `/blog/{slug}`. Custom type items will NOT be affected because they aren't type `post`. If the content slug matches and it's published, it renders. The `typeSlug` parameter from the route is effectively ignored by `page()` since it looks up by slug (which is globally unique). This is correct behavior — the URL structure `/{type}/{slug}` is for human readability and SEO.

---

## 7. Modify `app/Admin/ContentController.php`

### 7a. Update `index()` — Pass content types to template

After the data query (line 54), before the render (line 56), add a query for custom types:

```php
        $contentTypes = QueryBuilder::query('content_types')
            ->select('slug', 'name')
            ->orderBy('name', 'ASC')
            ->get();
```

Add `'contentTypes' => $contentTypes` to the template data array in the render call (line 56-66).

### 7b. Update `create()` — Accept custom types, load field definitions

Replace the type validation block (lines 76-79) with:

```php
        $type = (string) $request->query('type', 'page');
        $validTypes = ['page', 'post'];
        $customTypes = QueryBuilder::query('content_types')
            ->select('slug')
            ->get();
        foreach ($customTypes as $ct) {
            $validTypes[] = $ct['slug'];
        }
        if (!in_array($type, $validTypes, true)) {
            $type = 'page';
        }
```

After building the `$content` array, add custom field loading:

```php
        // Load custom field definitions for this type
        $customFieldDefinitions = [];
        $customFieldValues = [];
        $contentTypeRecord = QueryBuilder::query('content_types')
            ->select('fields_json')
            ->where('slug', $type)
            ->first();
        if ($contentTypeRecord !== null) {
            $customFieldDefinitions = json_decode($contentTypeRecord['fields_json'], true) ?: [];
        }
```

Add to the template data: `'customFieldDefinitions' => $customFieldDefinitions, 'customFieldValues' => $customFieldValues, 'contentTypes' => $contentTypes` (where `$contentTypes` is queried similarly to index).

Also pass `$contentTypes` so the type dropdown in the edit template can show custom types.

### 7c. Update `store()` — Save custom fields after content insert

After the content insert (line 136, after `$id = QueryBuilder::query('content')->insert(...)`) and before the flash/redirect:

```php
        // Save custom fields
        $customFields = $request->input('custom_fields', []);
        if (is_array($customFields)) {
            foreach ($customFields as $key => $value) {
                if (!is_string($key)) continue;
                QueryBuilder::query('custom_fields')->insert([
                    'content_id'  => (int) $id,
                    'field_key'   => $key,
                    'field_value' => is_string($value) ? $value : '',
                ]);
            }
        }
```

### 7d. Update `edit()` — Load custom field definitions and values

After fetching the content record (line 150), add:

```php
        // Load custom field definitions for this type
        $customFieldDefinitions = [];
        $customFieldValues = [];
        $contentTypeRecord = QueryBuilder::query('content_types')
            ->select('fields_json')
            ->where('slug', $content['type'])
            ->first();
        if ($contentTypeRecord !== null) {
            $customFieldDefinitions = json_decode($contentTypeRecord['fields_json'], true) ?: [];
        }

        // Load custom field values
        $cfRows = QueryBuilder::query('custom_fields')
            ->select('field_key', 'field_value')
            ->where('content_id', (int) $id)
            ->get();
        foreach ($cfRows as $row) {
            $customFieldValues[$row['field_key']] = $row['field_value'];
        }
```

Also query content types for the dropdown:
```php
        $contentTypes = QueryBuilder::query('content_types')
            ->select('slug', 'name')
            ->orderBy('name', 'ASC')
            ->get();
```

Add to the template data: `'customFieldDefinitions' => $customFieldDefinitions, 'customFieldValues' => $customFieldValues, 'contentTypes' => $contentTypes`.

### 7e. Update `update()` — Save custom fields after content update

After the content update (line 206, after `QueryBuilder::query('content')->where('id', ...)->update(...)`) and before the flash/redirect:

```php
        // Update custom fields: delete old, insert new
        QueryBuilder::query('custom_fields')
            ->where('content_id', (int) $id)
            ->delete();

        $customFields = $request->input('custom_fields', []);
        if (is_array($customFields)) {
            foreach ($customFields as $key => $value) {
                if (!is_string($key)) continue;
                QueryBuilder::query('custom_fields')->insert([
                    'content_id'  => (int) $id,
                    'field_key'   => $key,
                    'field_value' => is_string($value) ? $value : '',
                ]);
            }
        }
```

### 7f. Update `validate()` — Accept custom type slugs

Replace line 302 (`if (!in_array($data['type'], ['page', 'post'], true))`) with:

```php
        $validTypes = ['page', 'post'];
        $customTypes = QueryBuilder::query('content_types')
            ->select('slug')
            ->get();
        foreach ($customTypes as $ct) {
            $validTypes[] = $ct['slug'];
        }
        if (!in_array($data['type'], $validTypes, true)) {
            return 'Invalid content type.';
        }
```

---

## 8. Modify `templates/admin/content/index.php`

**Change**: Replace the hardcoded type filter dropdown (lines 17-24) with a dynamic version that includes custom types.

Replace the current type filter `<select>` block with:

```php
            <div class="form-group">
                <label for="filter-type">Type</label>
                <select id="filter-type" name="type">
                    <option value="">All Types</option>
                    <option value="page" <?= $type === 'page' ? 'selected' : '' ?>>Page</option>
                    <option value="post" <?= $type === 'post' ? 'selected' : '' ?>>Post</option>
                    <?php if (!empty($contentTypes)): ?>
                        <?php foreach ($contentTypes as $ct): ?>
                            <option value="<?= $this->e($ct['slug']) ?>"
                                    <?= $type === $ct['slug'] ? 'selected' : '' ?>>
                                <?= $this->e($ct['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
```

Also update the type badge display (line 99-101) to show the content type name instead of `ucfirst(type)`:

Replace:
```php
                                    <span class="badge">
                                        <?= $this->e(ucfirst($item['type'])) ?>
                                    </span>
```

With:
```php
                                    <span class="badge">
                                        <?php
                                        $typeName = ucfirst($item['type']);
                                        if (!empty($contentTypes)) {
                                            foreach ($contentTypes as $ct) {
                                                if ($ct['slug'] === $item['type']) {
                                                    $typeName = $ct['name'];
                                                    break;
                                                }
                                            }
                                        }
                                        ?>
                                        <?= $this->e($typeName) ?>
                                    </span>
```

---

## 9. Modify `templates/admin/content/edit.php`

### 9a. Dynamic type dropdown

Replace the hardcoded type `<select>` (lines 63-68) with:

```php
                    <div class="form-group">
                        <label for="type">Type</label>
                        <select id="type" name="type">
                            <option value="page" <?= ($content['type'] ?? 'page') === 'page' ? 'selected' : '' ?>>Page</option>
                            <option value="post" <?= ($content['type'] ?? '') === 'post' ? 'selected' : '' ?>>Post</option>
                            <?php if (!empty($contentTypes)): ?>
                                <?php foreach ($contentTypes as $ct): ?>
                                    <option value="<?= $this->e($ct['slug']) ?>"
                                            <?= ($content['type'] ?? '') === $ct['slug'] ? 'selected' : '' ?>>
                                        <?= $this->e($ct['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
```

### 9b. Custom fields section

After the excerpt `</div>` (line 53, end of the excerpt form-group) and before `</div>` (line 54, end of `.editor-main`), insert:

```php
            <?php if (!empty($customFieldDefinitions)): ?>
            <div class="custom-fields-section" style="margin-top: 1rem;">
                <h3 style="margin-bottom: 0.75rem;">Custom Fields</h3>
                <?php foreach ($customFieldDefinitions as $fieldDef): ?>
                    <div class="form-group">
                        <label for="cf_<?= $this->e($fieldDef['key']) ?>">
                            <?= $this->e($fieldDef['label']) ?>
                            <?php if (!empty($fieldDef['required'])): ?>
                                <span style="color:var(--color-error);">*</span>
                            <?php endif; ?>
                        </label>

                        <?php if ($fieldDef['type'] === 'text'): ?>
                            <input type="text"
                                   id="cf_<?= $this->e($fieldDef['key']) ?>"
                                   name="custom_fields[<?= $this->e($fieldDef['key']) ?>]"
                                   value="<?= $this->e($customFieldValues[$fieldDef['key']] ?? '') ?>">

                        <?php elseif ($fieldDef['type'] === 'textarea'): ?>
                            <textarea id="cf_<?= $this->e($fieldDef['key']) ?>"
                                      name="custom_fields[<?= $this->e($fieldDef['key']) ?>]"
                                      rows="5"><?= $this->e($customFieldValues[$fieldDef['key']] ?? '') ?></textarea>

                        <?php elseif ($fieldDef['type'] === 'image'): ?>
                            <?php $cfImgVal = $customFieldValues[$fieldDef['key']] ?? ''; ?>
                            <div style="margin-bottom: 0.5rem;">
                                <img id="cf_preview_<?= $this->e($fieldDef['key']) ?>"
                                     src="<?= $this->e($cfImgVal) ?>"
                                     alt=""
                                     style="max-width:200px;max-height:120px;<?= $cfImgVal ? '' : 'display:none;' ?>">
                            </div>
                            <input type="hidden"
                                   id="cf_<?= $this->e($fieldDef['key']) ?>"
                                   name="custom_fields[<?= $this->e($fieldDef['key']) ?>]"
                                   value="<?= $this->e($cfImgVal) ?>">
                            <button type="button" class="btn btn-sm cf-image-browse"
                                    data-field="<?= $this->e($fieldDef['key']) ?>">Browse Media</button>
                            <button type="button" class="btn btn-sm cf-image-remove"
                                    data-field="<?= $this->e($fieldDef['key']) ?>"
                                    style="<?= $cfImgVal ? '' : 'display:none;' ?>">Remove</button>

                        <?php elseif ($fieldDef['type'] === 'boolean'): ?>
                            <div>
                                <label style="cursor:pointer;">
                                    <input type="checkbox"
                                           id="cf_<?= $this->e($fieldDef['key']) ?>"
                                           name="custom_fields[<?= $this->e($fieldDef['key']) ?>]"
                                           value="1"
                                           <?= ($customFieldValues[$fieldDef['key']] ?? '0') === '1' ? 'checked' : '' ?>>
                                    Yes
                                </label>
                            </div>

                        <?php elseif ($fieldDef['type'] === 'select'): ?>
                            <select id="cf_<?= $this->e($fieldDef['key']) ?>"
                                    name="custom_fields[<?= $this->e($fieldDef['key']) ?>]">
                                <option value="">— Select —</option>
                                <?php foreach (($fieldDef['options'] ?? []) as $option): ?>
                                    <option value="<?= $this->e($option) ?>"
                                            <?= ($customFieldValues[$fieldDef['key']] ?? '') === $option ? 'selected' : '' ?>>
                                        <?= $this->e($option) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
```

### 9c. Custom field image browse JS

Add a small script block after the existing `<script>` tags at the end of the template to handle custom field image browse/remove buttons. This reuses the media browser modal already present from Chunk 2.3:

```html
<script>
(function() {
    // Custom field image browse buttons
    document.querySelectorAll('.cf-image-browse').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var fieldKey = this.getAttribute('data-field');
            var input = document.getElementById('cf_' + fieldKey);
            var preview = document.getElementById('cf_preview_' + fieldKey);
            var removeBtn = this.parentNode.querySelector('.cf-image-remove');

            // Open media browser in modal (reuse existing media browse)
            var modal = window.open('/admin/media/browse?select=1', 'mediaBrowser',
                'width=800,height=600,scrollbars=yes');

            window.addEventListener('message', function handler(e) {
                if (e.data && e.data.mediaUrl) {
                    input.value = e.data.mediaUrl;
                    preview.src = e.data.mediaUrl;
                    preview.style.display = '';
                    removeBtn.style.display = '';
                    window.removeEventListener('message', handler);
                }
            });
        });
    });

    // Custom field image remove buttons
    document.querySelectorAll('.cf-image-remove').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var fieldKey = this.getAttribute('data-field');
            var input = document.getElementById('cf_' + fieldKey);
            var preview = document.getElementById('cf_preview_' + fieldKey);
            input.value = '';
            preview.style.display = 'none';
            this.style.display = 'none';
        });
    });
})();
</script>
```

---

## Acceptance Test Procedures

### Test 1: Create a custom content type "Products" with fields

```
1. Log in as admin.
2. Navigate to /admin/content-types.
3. Click "+ New Content Type".
4. Enter Name: "Products", verify slug auto-generates as "products".
5. Click "+ Add Field" three times and configure:
   - Field 1: key=price, label=Price, type=text, required=checked
   - Field 2: key=description, label=Description, type=textarea
   - Field 3: key=featured, label=Featured Product, type=boolean
6. Click "Create Content Type".
7. Verify: flash success message, redirected to edit page.
8. Verify: content_types table contains the record with correct fields_json.
```

### Test 2: Create content of custom type with custom fields

```
1. Navigate to /admin/content/create?type=products
2. Verify: type dropdown shows "Products" selected.
3. Verify: below the excerpt field, "Custom Fields" section appears with:
   - Price (text input, with required asterisk)
   - Description (textarea)
   - Featured Product (checkbox)
4. Fill in: Title="Widget Pro", Price="29.99", Description="A great widget", Featured=checked.
5. Click "Create".
6. Verify: content record created with type="products".
7. Verify: custom_fields table has 3 rows for this content_id:
   - field_key=price, field_value=29.99
   - field_key=description, field_value=A great widget
   - field_key=featured, field_value=1
```

### Test 3: Custom field values persist on edit

```
1. Navigate to /admin/content/{id}/edit for the Widget Pro created above.
2. Verify: custom fields are populated with saved values.
3. Change Price to "39.99", uncheck Featured.
4. Click "Update".
5. Verify: custom_fields table updated — price=39.99, featured absent (checkbox unchecked → not submitted).
6. Reload edit page — verify values reflect the update.
```

### Test 4: Content list filterable by custom type

```
1. Navigate to /admin/content.
2. Verify: type filter dropdown shows "Products" option (in addition to Page, Post).
3. Select "Products" filter.
4. Verify: only products content items shown.
5. Verify: type badge shows "Products" (not "products").
```

### Test 5: Public archive at `/products/` shows published products

```
1. Publish the Widget Pro content item (set status to published).
2. Visit /products/ in a browser.
3. Verify: archive page renders with "Products" heading and the Widget Pro item listed.
4. Verify: item links to /products/widget-pro.
5. Visit /products/widget-pro.
6. Verify: page renders with the content.
```

### Test 6: Delete a content type — handles gracefully

```
1. With content items using the "Products" type, try deleting the type.
2. Verify: error flash "Cannot delete — X content item(s) use this type."
3. Delete all Products content items.
4. Try deleting the type again.
5. Verify: type deleted successfully, no longer in content_types table.
```

### Test 7: Reserved slug validation

```
1. Create a new content type with slug "blog".
2. Verify: validation error — "The slug 'blog' is reserved and cannot be used."
3. Try slug "admin" — same error.
4. Try slug "page" — same error.
```

### Test 8: Select field type with options

```
1. Create or edit a content type.
2. Add a field: key=category, label=Category, type=select.
3. In the options textarea, enter: Electronics, Clothing, Home (one per line).
4. Save the content type.
5. Create new content of this type.
6. Verify: Category field renders as a dropdown with "— Select —", "Electronics", "Clothing", "Home".
7. Select "Clothing", save.
8. Verify: custom_fields table stores field_key=category, field_value=Clothing.
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);`
- PSR-4: `App\Admin\ContentTypeController` → `app/Admin/ContentTypeController.php`
- No framework imports — native PHP only
- Parameterized queries for all database operations
- `$this->e()` for all template output

### Edge Cases
1. **Boolean checkbox fields**: When unchecked, HTML forms don't submit the field. The `custom_fields` handler must account for this — on update, it deletes all existing custom fields and re-inserts only those submitted. Unchecked booleans simply won't have a row (treated as "0" / false).
2. **Slug change on content type**: When a content type's slug changes, all content items of that type must be updated to reference the new slug. This is handled in `ContentTypeController::update()`.
3. **Dynamic route registration**: Custom type archive routes are registered during bootstrap by querying the `content_types` table. This runs on every request but is a simple indexed query. If the table doesn't exist yet (first migration), the `try/catch` silently skips.
4. **Type dropdown on content editor**: When editing existing content of a custom type, the type dropdown must include that custom type as an option. The `$contentTypes` variable must be passed to the template.
5. **Content type deletion protection**: Types with existing content cannot be deleted. This prevents orphaned content records with an invalid type.
6. **Field key uniqueness**: Within a single content type, field keys must be unique. This is validated in `validateFieldsJson()`.
7. **Image field type**: Uses the same media browser as the featured image field. Opens in a popup, receives the selected URL via `postMessage`.

### What This Chunk Does NOT Do
- No drag-and-drop reordering (uses up/down buttons instead — simpler, no library needed)
- No inline editing of content type fields from the content editor
- No custom display templates per content type (uses the generic `page` template)
- No field-level validation on content save (e.g., "required" flag is informational in the editor, not enforced server-side in this chunk — can be added in 7.1 polish)

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `app/Admin/ContentTypeController.php` | Controller | Create |
| 2 | `templates/admin/content-types/index.php` | Template | Create |
| 3 | `templates/admin/content-types/edit.php` | Template | Create |
| 4 | `public/assets/js/field-builder.js` | JavaScript | Create |
| 5 | `templates/admin/layout.php` | Template | Modify (add nav link) |
| 6 | `public/index.php` | Entry point | Modify (add routes) |
| 7 | `app/Admin/ContentController.php` | Controller | Modify (custom types + fields) |
| 8 | `templates/admin/content/index.php` | Template | Modify (dynamic type filter) |
| 9 | `templates/admin/content/edit.php` | Template | Modify (custom fields + type dropdown) |

---

## Estimated Scope

- **New PHP class**: 1 (ContentTypeController) — ~250 lines
- **New templates**: 2 (content-types/index, content-types/edit) — ~180 lines
- **New JavaScript**: 1 (field-builder.js) — ~180 lines
- **Modified PHP**: 1 (ContentController) — ~80 lines added
- **Modified templates**: 3 (layout, content/index, content/edit) — ~90 lines added
- **Modified entry point**: 1 (index.php) — ~20 lines added
- **Approximate total new/modified LOC**: ~800 lines
