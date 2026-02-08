# Chunk 6.2 — Content Editor Element Mode & Page Builder UI
## Detailed Implementation Plan

---

## Overview

This chunk adds element-based editing mode to the content editor. When `editor_mode = 'elements'`, the editor shows a page builder panel instead of TinyMCE — with an element picker modal, slot data forms auto-generated from slot definitions, drag-and-drop reordering, and JSON serialization of page composition. The ContentController is updated to persist and restore `page_elements` rows. Existing HTML-mode content remains completely unaffected.

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `public/assets/js/page-builder.js` (CREATE)

**Purpose**: Page builder UI: element picker modal, slot forms by type, drag-drop reorder, JSON serialization to hidden input. This is the main new file for this chunk.

**IIFE module** — all functions encapsulated in `(function() { 'use strict'; ... })();`

**Global entry point**: `window.initPageBuilder(existingInstances, csrfToken)`

**State variables**:
```
var instances = [];       // Array of {elementId, elementSlug, elementName, elementCategory, slots, slotData}
var catalogue = [];       // Cached element catalogue from API
var pickerModal = null;   // DOM reference to picker modal
var instanceList = null;  // DOM reference to instance list container
var jsonInput = null;     // DOM reference to hidden elements_json input
var csrfToken = '';       // CSRF token for API calls
var dragSrcIndex = null;  // Index of element being dragged
```

**Key functions**:

```
initPageBuilder(existingInstances, csrf)
    - Stores csrf token
    - Grabs DOM references: #pb-instance-list, #elements-json-input, #pb-picker-modal
    - Parses existingInstances array → populates instances[]
    - Renders all instances
    - Sets up "Add Element" button click → openPicker()
    - Sets up form submit handler → serializeInstances()
    - Fetches element catalogue from /admin/elements/api/list (caches result)

fetchCatalogue(callback)
    - If catalogue[] is already loaded, calls callback immediately
    - Otherwise: fetch('/admin/elements/api/list') → parse JSON
    - Stores in catalogue[], calls callback

openPicker()
    - Calls fetchCatalogue() to ensure catalogue is loaded
    - Renders picker modal content: search input + category tabs + element grid
    - Shows modal (removes 'hidden' class)
    - Focus on search input

closePicker()
    - Hides modal (adds 'hidden' class)

renderPickerContent(searchTerm, categoryFilter)
    - Filters catalogue by search term (name match) and category
    - Renders category tabs (All + unique categories from catalogue)
    - Renders grid of element cards: name, description, category badge
    - Each card has click handler → addElement(elementFromCatalogue)

addElement(elementDef)
    - Creates instance object:
      {
        elementId: elementDef.id,
        elementSlug: elementDef.slug,
        elementName: elementDef.name,
        elementCategory: elementDef.category,
        slots: JSON.parse(elementDef.slots_json),
        slotData: buildDefaultSlotData(JSON.parse(elementDef.slots_json))
      }
    - Pushes to instances[]
    - Closes picker
    - Renders all instances
    - Scrolls to new instance

buildDefaultSlotData(slots)
    - Returns an object with default values per slot type:
      text → '', richtext → '', image → '',
      link → {url: '', text: '', target: '_self'},
      select → first option or '', boolean → false, number → 0,
      list → [] (empty array)

removeInstance(index)
    - Confirms with user ("Remove this element?")
    - Splices from instances[]
    - Re-renders all instances

renderAllInstances()
    - Clears instanceList container
    - If instances is empty: shows empty state message
    - For each instance: creates instance card via createInstanceCard()
    - Shows element count badge

createInstanceCard(instance, index) → HTMLElement
    - Creates div.pb-instance-card
    - Header row:
      - Drag handle (span.pb-drag-handle, ☰ icon)
      - Element name + category badge
      - Collapse toggle button (▼/▲)
      - Remove button (×)
    - Collapsible body:
      - For each slot in instance.slots: createSlotField(slot, instance.slotData, index)
    - Drag event listeners: dragstart, dragover, dragend, drop
    - Collapse toggle handler: toggles .pb-collapsed class on card
    - Remove handler: calls removeInstance(index)
    - Returns the card element

createSlotField(slot, slotData, instanceIndex) → HTMLElement
    - Creates a .pb-slot-field div with label and input based on slot.type:

    text:
        <label>{slot.label}</label>
        <input type="text" value="{slotData[slot.key]}"
               data-instance="{instanceIndex}" data-slot="{slot.key}">

    richtext:
        <label>{slot.label}</label>
        <textarea rows="5" data-instance="{instanceIndex}" data-slot="{slot.key}">
            {slotData[slot.key]}
        </textarea>

    image:
        <label>{slot.label}</label>
        <div class="pb-image-field">
            <img src="{slotData[slot.key]}" class="pb-image-preview"
                 style="display:{value ? '' : 'none'}">
            <input type="hidden" data-instance="{instanceIndex}" data-slot="{slot.key}"
                   value="{slotData[slot.key]}">
            <button type="button" class="btn btn-sm pb-browse-media">Browse Media</button>
            <button type="button" class="btn btn-sm pb-remove-media"
                    style="display:{value ? '' : 'none'}">Remove</button>
        </div>
        - Browse Media opens existing media browser modal (same as featured image)
        - On select: updates hidden input + shows preview
        - Remove: clears hidden input + hides preview

    link:
        <label>{slot.label}</label>
        <div class="pb-link-fields">
            <input type="text" placeholder="URL"
                   data-instance="{instanceIndex}" data-slot="{slot.key}" data-subkey="url"
                   value="{slotData[slot.key].url}">
            <input type="text" placeholder="Link Text"
                   data-instance="{instanceIndex}" data-slot="{slot.key}" data-subkey="text"
                   value="{slotData[slot.key].text}">
            <select data-instance="{instanceIndex}" data-slot="{slot.key}" data-subkey="target">
                <option value="_self">Same Tab</option>
                <option value="_blank">New Tab</option>
            </select>
        </div>

    select:
        <label>{slot.label}</label>
        <select data-instance="{instanceIndex}" data-slot="{slot.key}">
            {slot.options.map(opt => <option value="{opt}">{opt}</option>)}
        </select>

    boolean:
        <label>
            <input type="checkbox" data-instance="{instanceIndex}" data-slot="{slot.key}"
                   {slotData[slot.key] ? 'checked' : ''}>
            {slot.label}
        </label>

    number:
        <label>{slot.label}</label>
        <input type="number" data-instance="{instanceIndex}" data-slot="{slot.key}"
               value="{slotData[slot.key]}">

    list:
        <label>{slot.label}</label>
        <div class="pb-list-field" data-instance="{instanceIndex}" data-slot="{slot.key}">
            {for each item in slotData[slot.key]: createListItem(slot.sub_slots, item, itemIndex)}
            <button type="button" class="btn btn-sm pb-add-list-item">+ Add Item</button>
        </div>
        - "Add Item" pushes a new empty item with defaults from sub_slots
        - Each item has remove button
        - Sub-slot fields rendered recursively (text/richtext/image/number/boolean only)

createListItem(subSlots, itemData, itemIndex) → HTMLElement
    - Creates div.pb-list-item with:
      - Item header: "Item {itemIndex+1}" + remove button
      - For each sub-slot: input based on type (text/richtext/image/number/boolean)

readInstancesFromDOM()
    - Walks DOM inputs/textareas/selects with data-instance and data-slot attributes
    - Updates instances[].slotData from current DOM values
    - Handles:
      - Simple fields (text, richtext, number, select, image): value from input
      - Boolean: checked property from checkbox
      - Link: reads url/text/target sub-fields → builds {url, text, target} object
      - List: walks .pb-list-item children → builds array of sub-slot objects

serializeInstances()
    - Calls readInstancesFromDOM()
    - Builds JSON array:
      [
        {
          element_id: instances[0].elementId,
          slot_data: instances[0].slotData
        },
        ...
      ]
    - Writes JSON.stringify(array) to jsonInput.value

--- Drag and Drop ---

handleDragStart(e, index)
    - Sets dragSrcIndex = index
    - e.dataTransfer.effectAllowed = 'move'
    - Adds .pb-dragging class to card

handleDragOver(e, index)
    - e.preventDefault()
    - e.dataTransfer.dropEffect = 'move'
    - Adds .pb-drag-over class to target card (remove from others)

handleDrop(e, index)
    - e.preventDefault()
    - If dragSrcIndex !== index:
      - readInstancesFromDOM() first to preserve edits
      - Splice instance from dragSrcIndex, insert at index
      - Re-render all instances

handleDragEnd(e)
    - Remove .pb-dragging and .pb-drag-over classes from all cards
    - dragSrcIndex = null

--- Media Browser Integration ---

setupMediaBrowse(fieldContainer, hiddenInput, previewImg, removeBtn)
    - Reuses existing media browse modal from editor.js
    - On select: sets hiddenInput.value, shows previewImg with src, shows removeBtn
    - Remove: clears hiddenInput.value, hides previewImg, hides removeBtn

--- Utility ---

escHtml(str) → string
    - HTML-escapes via textContent/innerHTML trick (same as element-editor.js)

escAttr(str) → string
    - Escapes &, ", <, > for attribute contexts
```

**Approximate size**: ~550-650 lines of JavaScript.

---

### 2. `templates/admin/content/edit.php` (MODIFY)

**Purpose**: Add editor mode toggle and page builder panel. When mode is 'elements', show the page builder UI instead of the TinyMCE body textarea.

**Changes to existing template**:

#### 2a. Add `editor_mode` hidden input and toggle radio buttons

Insert after the slug field div (line ~42), before the body form-group:

```php
<!-- Editor Mode Toggle -->
<div class="form-group pb-mode-toggle">
    <label>Editor Mode</label>
    <div class="pb-mode-options">
        <label class="pb-mode-option">
            <input type="radio" name="editor_mode" value="html"
                   <?= ($content['editor_mode'] ?? 'html') === 'html' ? 'checked' : '' ?>>
            <span>HTML Editor</span>
        </label>
        <label class="pb-mode-option">
            <input type="radio" name="editor_mode" value="elements"
                   <?= ($content['editor_mode'] ?? 'html') === 'elements' ? 'checked' : '' ?>>
            <span>Page Builder</span>
        </label>
    </div>
</div>
```

#### 2b. Wrap the body textarea in a conditional panel

Wrap the existing body `<div class="form-group">` (with the textarea) in:

```php
<!-- HTML Editor Panel (visible when editor_mode = html) -->
<div id="html-editor-panel"
     class="<?= ($content['editor_mode'] ?? 'html') === 'elements' ? 'hidden' : '' ?>">
    <!-- existing body textarea + label here -->
</div>
```

#### 2c. Add the Page Builder panel

Insert immediately after the HTML editor panel:

```php
<!-- Page Builder Panel (visible when editor_mode = elements) -->
<div id="page-builder-panel"
     class="<?= ($content['editor_mode'] ?? 'html') !== 'elements' ? 'hidden' : '' ?>">
    <div class="pb-toolbar">
        <button type="button" id="pb-add-element" class="btn btn-primary btn-sm">
            + Add Element
        </button>
        <span id="pb-element-count" class="pb-count-badge">0 elements</span>
    </div>

    <div id="pb-instance-list" class="pb-instance-list">
        <div class="pb-empty-state" id="pb-empty-state">
            <div class="pb-empty-icon">&#9647;</div>
            <p>No elements added yet.</p>
            <p style="font-size:0.85rem;color:var(--color-text-muted);">
                Click "Add Element" to start building your page.
            </p>
        </div>
    </div>

    <input type="hidden" id="elements-json-input" name="elements_json" value="">
</div>

<!-- Element Picker Modal -->
<div id="pb-picker-modal" class="pb-picker-modal hidden">
    <div class="pb-picker-overlay"></div>
    <div class="pb-picker-content">
        <div class="pb-picker-header">
            <h3>Choose an Element</h3>
            <button type="button" class="pb-picker-close" title="Close">&times;</button>
        </div>
        <div class="pb-picker-search">
            <input type="text" id="pb-picker-search" placeholder="Search elements...">
        </div>
        <div class="pb-picker-categories" id="pb-picker-categories"></div>
        <div class="pb-picker-grid" id="pb-picker-grid"></div>
    </div>
</div>
```

#### 2d. Add page-builder.js script and initialization

Insert before the closing `</form>` or after the existing scripts, add:

```php
<script src="/assets/js/page-builder.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Editor mode toggle
    var htmlPanel = document.getElementById('html-editor-panel');
    var builderPanel = document.getElementById('page-builder-panel');
    var modeRadios = document.querySelectorAll('input[name="editor_mode"]');

    modeRadios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            if (this.value === 'html') {
                htmlPanel.classList.remove('hidden');
                builderPanel.classList.add('hidden');
            } else {
                htmlPanel.classList.add('hidden');
                builderPanel.classList.remove('hidden');
            }
        });
    });

    // Initialize page builder with existing instances
    var existingInstances = <?= json_encode($pageElements ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    initPageBuilder(existingInstances, '<?= $this->e($csrfToken ?? '') ?>');
});
</script>
```

#### 2e. Pass CSRF token to template

The CSRF token is already available via `Session::get('csrf_token')`. The template already uses `$this->csrfField()`. We need to pass the raw token value for JS API calls. This is done by having the ContentController pass it as template data (see section 3).

---

### 3. `app/Admin/ContentController.php` (MODIFY)

**Purpose**: Handle `editor_mode` in form data, save/load `page_elements` rows.

#### 3a. Add import

```php
use App\PageBuilder\PageRenderer;
```

#### 3b. Modify `readFormData()`

Add `editor_mode` to the returned array:

```php
private function readFormData(Request $request): array
{
    return [
        // ... existing fields ...
        'editor_mode'  => (string) $request->input('editor_mode', 'html'),
    ];
}
```

#### 3c. Modify `create()`

Add `editor_mode` default and empty `pageElements` to template data:

```php
$content = [
    // ... existing defaults ...
    'editor_mode'  => (string) $request->query('editor_mode', 'html'),
];
```

Pass additional data to template:

```php
$html = $this->app->template()->render('admin/content/edit', [
    // ... existing data ...
    'pageElements' => [],
    'csrfToken'    => Session::get('csrf_token', ''),
]);
```

#### 3d. Modify `store()`

After inserting the content row (which returns $id), save `editor_mode` and `page_elements`:

```php
// In the insert array, add:
'editor_mode' => in_array($data['editor_mode'], ['html', 'elements'], true)
    ? $data['editor_mode'] : 'html',

// After content insert, save page elements if in elements mode:
if ($data['editor_mode'] === 'elements') {
    $this->savePageElements((int) $id, $request);
}
```

#### 3e. Modify `edit()`

Load existing page_elements and pass to template:

```php
// Load page elements for this content
$pageElements = [];
if (($content['editor_mode'] ?? 'html') === 'elements') {
    $pageElements = $this->loadPageElements((int) $id);
}

$html = $this->app->template()->render('admin/content/edit', [
    // ... existing data ...
    'pageElements' => $pageElements,
    'csrfToken'    => Session::get('csrf_token', ''),
]);
```

#### 3f. Modify `update()`

Update `editor_mode` and save page elements:

```php
// In the update array, add:
'editor_mode' => in_array($data['editor_mode'], ['html', 'elements'], true)
    ? $data['editor_mode'] : 'html',

// After content update, save page elements:
if ($data['editor_mode'] === 'elements') {
    $this->savePageElements((int) $id, $request);
}
```

#### 3g. Add private helper: `savePageElements()`

```php
/**
 * Parse elements_json from request and save page_elements rows.
 * Deletes old rows and inserts new ones with correct sort_order.
 */
private function savePageElements(int $contentId, Request $request): void
{
    // Delete existing page_elements for this content
    QueryBuilder::query('page_elements')
        ->where('content_id', $contentId)
        ->delete();

    $elementsJson = (string) $request->input('elements_json', '[]');
    $elements = json_decode($elementsJson, true);

    if (!is_array($elements)) {
        return;
    }

    foreach ($elements as $sortOrder => $element) {
        if (!is_array($element) || empty($element['element_id'])) {
            continue;
        }

        $elementId = (int) $element['element_id'];

        // Verify element exists
        $exists = QueryBuilder::query('elements')
            ->select('id')
            ->where('id', $elementId)
            ->first();

        if ($exists === null) {
            continue;
        }

        $slotData = $element['slot_data'] ?? [];
        if (!is_array($slotData)) {
            $slotData = [];
        }

        QueryBuilder::query('page_elements')->insert([
            'content_id'    => $contentId,
            'element_id'    => $elementId,
            'sort_order'    => $sortOrder,
            'slot_data_json' => json_encode($slotData, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
```

#### 3h. Add private helper: `loadPageElements()`

```php
/**
 * Load page_elements for a content item with full element metadata.
 * Returns an array suitable for passing to the page builder JS.
 */
private function loadPageElements(int $contentId): array
{
    $rows = QueryBuilder::query('page_elements')
        ->select(
            'page_elements.id',
            'page_elements.element_id',
            'page_elements.sort_order',
            'page_elements.slot_data_json',
            'elements.slug',
            'elements.name',
            'elements.category',
            'elements.slots_json'
        )
        ->leftJoin('elements', 'elements.id', '=', 'page_elements.element_id')
        ->where('page_elements.content_id', (string) $contentId)
        ->orderBy('page_elements.sort_order')
        ->get();

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'elementId'       => (int) $row['element_id'],
            'elementSlug'     => $row['slug'] ?? 'unknown',
            'elementName'     => $row['name'] ?? 'Unknown Element',
            'elementCategory' => $row['category'] ?? 'general',
            'slots'           => json_decode($row['slots_json'] ?? '[]', true) ?: [],
            'slotData'        => json_decode($row['slot_data_json'] ?? '{}', true) ?: [],
        ];
    }

    return $result;
}
```

**Complete modified method signatures** (for clarity, only listing changed signatures):

```
EXISTING METHODS (modified):
  - readFormData(Request): array — adds 'editor_mode' field
  - create(Request): Response — adds editor_mode to content defaults, passes pageElements + csrfToken
  - store(Request): Response — saves editor_mode, calls savePageElements()
  - edit(Request, string): Response — loads pageElements, passes to template + csrfToken
  - update(Request, string): Response — saves editor_mode, calls savePageElements()

NEW PRIVATE METHODS:
  - savePageElements(int $contentId, Request $request): void
  - loadPageElements(int $contentId): array
```

---

### 4. `public/assets/css/admin.css` (MODIFY)

**Purpose**: Add page builder styles for element instance cards, drag handles, picker modal, slot forms.

**New CSS sections** (append to end of file, ~300 lines):

```
/* =========================================================
   Page Builder Styles
   ========================================================= */

/* --- Editor Mode Toggle --- */
.pb-mode-toggle { ... }
.pb-mode-options { display: flex; gap: 0.5rem; }
.pb-mode-option {
    cursor: pointer;
    display: flex; align-items: center; gap: 0.375rem;
    padding: 0.5rem 1rem;
    border: 2px solid var(--color-border);
    border-radius: 6px;
    transition: border-color 0.15s, background 0.15s;
}
.pb-mode-option:has(input:checked) {
    border-color: var(--color-primary);
    background: var(--color-primary-light);
}
.pb-mode-option input[type="radio"] { margin: 0; }

/* --- Page Builder Panel --- */
.pb-toolbar {
    display: flex; align-items: center; gap: 0.75rem;
    margin-bottom: 1rem;
    padding: 0.75rem;
    background: var(--color-bg);
    border-radius: var(--card-radius);
}
.pb-count-badge {
    font-size: 0.85rem;
    color: var(--color-text-muted);
}
.pb-empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: var(--color-text-muted);
    border: 2px dashed var(--color-border);
    border-radius: var(--card-radius);
}
.pb-empty-icon {
    font-size: 3rem;
    margin-bottom: 0.5rem;
    opacity: 0.4;
}

/* --- Instance Cards --- */
.pb-instance-list {
    display: flex; flex-direction: column; gap: 0.75rem;
}
.pb-instance-card {
    border: 1px solid var(--color-border);
    border-radius: var(--card-radius);
    background: var(--color-white);
    box-shadow: var(--card-shadow);
    transition: box-shadow 0.15s, border-color 0.15s;
}
.pb-instance-card:hover {
    border-color: var(--color-primary);
}
.pb-instance-header {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: var(--color-bg);
    border-bottom: 1px solid var(--color-border);
    border-radius: var(--card-radius) var(--card-radius) 0 0;
    cursor: pointer;
    user-select: none;
}
.pb-drag-handle {
    cursor: grab; color: var(--color-text-muted);
    font-size: 1.1rem; padding: 0 0.25rem;
}
.pb-drag-handle:active { cursor: grabbing; }
.pb-instance-name {
    flex: 1; font-weight: 600; font-size: 0.95rem;
}
.pb-instance-category {
    font-size: 0.75rem;
    padding: 0.15rem 0.5rem;
    border-radius: 100px;
    background: var(--color-primary-light);
    color: var(--color-primary);
}
.pb-collapse-btn, .pb-remove-btn {
    background: none; border: none; cursor: pointer;
    font-size: 1.1rem; color: var(--color-text-muted);
    padding: 0.25rem;
}
.pb-remove-btn:hover { color: var(--color-error); }
.pb-instance-body {
    padding: 1rem;
}
.pb-instance-card.pb-collapsed .pb-instance-body {
    display: none;
}
.pb-instance-card.pb-collapsed .pb-instance-header {
    border-bottom: none;
    border-radius: var(--card-radius);
}

/* Drag & Drop States */
.pb-instance-card.pb-dragging {
    opacity: 0.5;
    border: 2px dashed var(--color-primary);
}
.pb-instance-card.pb-drag-over {
    border-top: 3px solid var(--color-primary);
}

/* --- Slot Fields --- */
.pb-slot-field {
    margin-bottom: 1rem;
}
.pb-slot-field:last-child {
    margin-bottom: 0;
}
.pb-slot-field > label {
    display: block;
    font-weight: 500;
    font-size: 0.85rem;
    margin-bottom: 0.375rem;
    color: var(--color-text);
}
.pb-slot-field input[type="text"],
.pb-slot-field input[type="number"],
.pb-slot-field textarea,
.pb-slot-field select {
    width: 100%;
    padding: 0.5rem 0.625rem;
    border: 1px solid var(--color-border);
    border-radius: 4px;
    font-size: 0.875rem;
    font-family: inherit;
}
.pb-slot-field textarea { resize: vertical; min-height: 80px; }

/* Link fields (url + text + target in a row) */
.pb-link-fields {
    display: grid; grid-template-columns: 1fr 1fr auto; gap: 0.5rem;
}
@media (max-width: 768px) {
    .pb-link-fields { grid-template-columns: 1fr; }
}

/* Image fields */
.pb-image-field { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.pb-image-preview {
    max-width: 150px; max-height: 80px; border-radius: 4px;
    border: 1px solid var(--color-border);
}

/* List fields */
.pb-list-field {
    border: 1px solid var(--color-border);
    border-radius: 6px;
    padding: 0.75rem;
    background: #fafbfc;
}
.pb-list-item {
    padding: 0.75rem;
    margin-bottom: 0.5rem;
    border: 1px solid var(--color-border-light);
    border-radius: 4px;
    background: var(--color-white);
}
.pb-list-item-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 0.5rem;
    font-weight: 500; font-size: 0.85rem;
}
.pb-list-remove {
    background: none; border: none; cursor: pointer;
    color: var(--color-text-muted); font-size: 1rem;
}
.pb-list-remove:hover { color: var(--color-error); }
.pb-add-list-item {
    margin-top: 0.5rem;
}

/* --- Picker Modal --- */
.pb-picker-modal {
    position: fixed; inset: 0; z-index: 1000;
    display: flex; align-items: center; justify-content: center;
}
.pb-picker-modal.hidden { display: none; }
.pb-picker-overlay {
    position: absolute; inset: 0;
    background: rgba(0, 0, 0, 0.5);
}
.pb-picker-content {
    position: relative; z-index: 1;
    width: 90%; max-width: 700px; max-height: 80vh;
    background: var(--color-white);
    border-radius: var(--card-radius);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    display: flex; flex-direction: column;
    overflow: hidden;
}
.pb-picker-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
}
.pb-picker-header h3 { margin: 0; }
.pb-picker-close {
    background: none; border: none; cursor: pointer;
    font-size: 1.5rem; color: var(--color-text-muted);
}
.pb-picker-close:hover { color: var(--color-text); }
.pb-picker-search {
    padding: 0.75rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
}
.pb-picker-search input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: 6px;
    font-size: 0.95rem;
}
.pb-picker-categories {
    display: flex; gap: 0.375rem;
    padding: 0.75rem 1.25rem;
    border-bottom: 1px solid var(--color-border);
    flex-wrap: wrap;
}
.pb-category-tab {
    padding: 0.3rem 0.75rem;
    border-radius: 100px;
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    cursor: pointer; font-size: 0.8rem;
    transition: all 0.15s;
}
.pb-category-tab.active {
    background: var(--color-primary);
    color: #fff;
    border-color: var(--color-primary);
}
.pb-picker-grid {
    padding: 1rem 1.25rem;
    overflow-y: auto; flex: 1;
    display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.75rem;
    align-content: start;
}
.pb-picker-card {
    border: 1px solid var(--color-border);
    border-radius: 6px;
    padding: 1rem;
    cursor: pointer;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.pb-picker-card:hover {
    border-color: var(--color-primary);
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.15);
}
.pb-picker-card-name {
    font-weight: 600; font-size: 0.95rem;
    margin-bottom: 0.25rem;
}
.pb-picker-card-desc {
    font-size: 0.8rem;
    color: var(--color-text-muted);
    line-height: 1.4;
}
.pb-picker-card-category {
    display: inline-block;
    margin-top: 0.5rem;
    font-size: 0.7rem;
    padding: 0.15rem 0.5rem;
    border-radius: 100px;
    background: var(--color-primary-light);
    color: var(--color-primary);
}
.pb-picker-empty {
    grid-column: 1 / -1;
    text-align: center;
    padding: 2rem;
    color: var(--color-text-muted);
}

/* Hidden utility */
.hidden { display: none !important; }

/* Responsive */
@media (max-width: 768px) {
    .pb-picker-content { width: 95%; max-height: 90vh; }
    .pb-picker-grid { grid-template-columns: 1fr; }
    .pb-instance-header { flex-wrap: wrap; }
}
```

---

## Detailed Class Specifications

### `App\Admin\ContentController` (Modified)

```
EXISTING PROPERTIES:
  - private App $app

NEW IMPORT:
  - (none needed — already uses QueryBuilder, Session)

MODIFIED METHODS:
  - private readFormData(Request $request): array
      CHANGE: Add 'editor_mode' key:
        'editor_mode' => (string) $request->input('editor_mode', 'html')

  - public create(Request $request): Response
      CHANGE: Add to $content defaults array:
        'editor_mode' => (string) $request->query('editor_mode', 'html')
      CHANGE: Add to template render data:
        'pageElements' => []
        'csrfToken' => Session::get('csrf_token', '')

  - public store(Request $request): Response
      CHANGE: Add 'editor_mode' to insert array (validated to 'html' or 'elements')
      CHANGE: After insert, call $this->savePageElements((int)$id, $request)
              if editor_mode === 'elements'

  - public edit(Request $request, string $id): Response
      CHANGE: Load page elements: $pageElements = $this->loadPageElements((int)$id)
              (only if editor_mode === 'elements', otherwise empty array)
      CHANGE: Add to template render data:
        'pageElements' => $pageElements
        'csrfToken' => Session::get('csrf_token', '')

  - public update(Request $request, string $id): Response
      CHANGE: Add 'editor_mode' to update array (validated to 'html' or 'elements')
      CHANGE: After update, call $this->savePageElements((int)$id, $request)
              if editor_mode === 'elements'

NEW PRIVATE METHODS:
  - private savePageElements(int $contentId, Request $request): void
      1. Delete all existing page_elements WHERE content_id = $contentId
      2. Read elements_json from request input
      3. json_decode to array
      4. For each element in array:
         a. Validate element_id exists in elements table
         b. Insert page_elements row with:
            content_id, element_id, sort_order (= array index),
            slot_data_json (json_encode of slot_data)
      5. Skip invalid entries silently (graceful handling)

  - private loadPageElements(int $contentId): array
      1. Query page_elements LEFT JOIN elements
         WHERE content_id = $contentId
         ORDER BY sort_order ASC
      2. For each row, build array entry:
         {
           elementId: (int) row.element_id,
           elementSlug: row.slug,
           elementName: row.name,
           elementCategory: row.category,
           slots: json_decode(row.slots_json),
           slotData: json_decode(row.slot_data_json)
         }
      3. Return array (suitable for json_encode in template)
```

---

## Integration with Existing Code

### Media Browser Reuse

The content editor already has a media browser modal (from Chunk 2.3) that is used for featured images and custom field images. The page builder's image slot fields will reuse this same mechanism:

1. The existing `editor.js` initializes TinyMCE and handles the media browse modal
2. The page builder needs to trigger the same media browse modal for image slot fields
3. This is done by dispatching a custom event or calling the existing browse function with a callback

The page builder will use `window.openMediaBrowser(callback)` if available, or fall back to a simple text input for the image URL. The `editor.js` already exposes this via the featured image browse button pattern. The page builder will replicate the same `fetch('/admin/media/browse')` approach.

### TinyMCE Coexistence

When `editor_mode = 'html'`, TinyMCE initializes on the `#body` textarea as usual. When `editor_mode = 'elements'`, the body textarea is hidden and TinyMCE is NOT initialized on it. The mode toggle handles this:

- Switching from HTML to Elements: if TinyMCE is active on `#body`, its content is preserved in the textarea (TinyMCE sync). The TinyMCE instance remains but its container is hidden.
- Switching from Elements to HTML: the TinyMCE container becomes visible again.
- On form submit: if mode is `html`, the body textarea value is submitted normally. If mode is `elements`, `elements_json` hidden input is submitted, and body content is ignored (will be rendered dynamically from page_elements).

### AI Assistant Panel

The AI assistant panel remains unchanged. It works alongside either editor mode. When in elements mode, the AI can still help with content — the user can discuss page structure and get suggestions, even though the body field isn't directly editable via TinyMCE.

---

## Acceptance Test Procedures

### Test 1: Editor mode toggle switches UI panels
```
1. Navigate to /admin/content/create
2. Verify "HTML Editor" radio is selected by default
3. Verify body textarea (TinyMCE) is visible
4. Verify page builder panel is hidden
5. Click "Page Builder" radio
6. Verify body textarea/TinyMCE is hidden
7. Verify page builder panel is visible with "Add Element" button and empty state
8. Click "HTML Editor" radio — body textarea is visible again
```

### Test 2: Element picker shows catalogue (searchable, categorized)
```
1. Switch to Page Builder mode
2. Click "Add Element" button
3. Verify picker modal appears with all 7 seed elements
4. Verify category tabs appear (All, hero, content, features, cta, testimonials)
5. Click "content" category tab — only content-category elements shown
6. Type "hero" in search — only Hero Section shown
7. Clear search — all elements shown again
```

### Test 3: Adding an element creates instance card with slot forms
```
1. In picker modal, click "Hero Section"
2. Verify modal closes
3. Verify instance card appears in the builder with:
   - Header showing "Hero Section" name + "hero" category badge
   - Slot fields: Headline (text input), Description (textarea),
     Background Image (image browse), CTA Button (link fields),
     Text Alignment (select dropdown)
4. Verify element count badge shows "1 element"
```

### Test 4: Slot forms render correctly for all slot types
```
1. Add a Hero Section element (has text, richtext, image, link, select)
2. Verify text slot → text input
3. Verify richtext slot → textarea
4. Verify image slot → hidden input + browse button + preview
5. Verify link slot → three fields (URL, text, target select)
6. Verify select slot → select dropdown with options (left, center, right)
7. Add a Feature Grid element (has list type)
8. Verify list slot → container with "Add Item" button
9. Click "Add Item" → sub-slot fields appear
10. Add a second item → two items visible
11. Remove an item → one item remaining
```

### Test 5: Drag-and-drop reorders elements
```
1. Add Hero Section, then Text Section, then CTA Banner (3 elements)
2. Verify order: Hero, Text, CTA
3. Drag CTA banner (grab the drag handle) to the top position
4. Drop it above Hero Section
5. Verify order changes to: CTA, Hero, Text
6. Verify slot data is preserved during reorder
```

### Test 6: Saving persists page_elements rows with correct sort_order and slot_data_json
```
1. Create new content, switch to Page Builder mode
2. Add Hero Section, fill in title="Welcome", set alignment="center"
3. Add Text Section, fill in title="About Us"
4. Save (click Create)
5. Verify content record has editor_mode='elements' in database
6. Verify page_elements table has 2 rows:
   - Row 1: element_id=(hero-section id), sort_order=0, slot_data contains title="Welcome"
   - Row 2: element_id=(text-section id), sort_order=1, slot_data contains title="About Us"
```

### Test 7: Loading editor restores all element instances with filled slot data
```
1. Edit the content created in Test 6
2. Verify Page Builder mode is active (radio selected)
3. Verify 2 instance cards are shown: Hero Section, Text Section
4. Verify Hero Section's title input has value "Welcome"
5. Verify Hero Section's alignment select has value "center"
6. Verify Text Section's title input has value "About Us"
7. Verify element count badge shows "2 elements"
```

### Test 8: Remove element works
```
1. Edit content from Test 6
2. Click remove (×) on Text Section
3. Confirm removal
4. Verify only Hero Section remains
5. Save
6. Verify page_elements table has only 1 row for this content
```

### Test 9: Existing HTML-mode content completely unaffected
```
1. Create a new content item in HTML mode (default)
2. Add title, body text via TinyMCE, publish it
3. Save
4. Verify content record has editor_mode='html'
5. Edit it again — verify TinyMCE is visible, no page builder panel
6. Verify the content displays correctly on the public site
7. Verify no page_elements rows exist for this content
```

### Test 10: Element-based page renders on public site
```
1. Create content in Page Builder mode with Hero Section + Text Section
2. Fill in slot data, set status to published
3. Save
4. Visit the public page URL
5. Verify the page renders with both elements
6. Verify element CSS is injected in the <head>
7. Verify wrapper divs have correct .lcms-el-{slug} classes
```

### Test 11: Collapsible instance cards
```
1. Add 3 elements to the page builder
2. Click the collapse toggle on the first element
3. Verify the slot fields are hidden (card collapsed)
4. Click the collapse toggle again
5. Verify the slot fields are visible again (card expanded)
```

### Test 12: Image slot media browser integration
```
1. Add an Image + Text element (has image slot)
2. Click "Browse Media" on the image slot
3. Verify media browser modal opens
4. Select an image
5. Verify image preview appears and hidden input is populated
6. Click "Remove" — verify image preview hides and input clears
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);`
- PSR-4 namespacing unchanged
- No framework imports — only native PHP
- All template output uses `$this->e()` for escaping (XSS prevention)
- Parameterized queries via QueryBuilder throughout
- JavaScript uses vanilla JS only (no build step, no npm)

### Security Considerations
- `editor_mode` is validated server-side to only accept `'html'` or `'elements'`
- `elements_json` is parsed server-side with JSON decode; invalid JSON is silently ignored
- Each `element_id` in elements_json is verified to exist in the `elements` table before inserting a `page_elements` row (prevents referencing deleted/nonexistent elements)
- Slot data is stored as JSON — no direct SQL interpolation
- CSRF token is passed to JS for API calls to `/admin/elements/api/list`
- All slot form data is read from DOM at submit time and serialized to JSON — not echoed back unsanitized
- The media browser reuse follows the same security model as the existing featured image browser

### Edge Cases
- **Empty page builder**: If no elements are added and the mode is 'elements', `elements_json` is `[]`, resulting in zero `page_elements` rows. The public page will render an empty body.
- **Deleted element in catalogue**: If an element is deleted from the catalogue while a page uses it, the `ON DELETE RESTRICT` FK constraint prevents this. The ElementController already checks usage count before deletion.
- **Switching modes**: If a user creates content in HTML mode, switches to elements mode, and saves — the body field retains its old HTML content (not lost), but the public rendering uses the element-based rendering. If they switch back to HTML, the old body content reappears. This is intentional — no data is lost.
- **Missing catalogue**: If the element catalogue is empty or the API call fails, the picker modal shows an empty state message instead of crashing.
- **Large slot data**: List-type slots with many items generate large JSON. The `slot_data_json` column is TEXT type, which handles this.
- **Concurrent edits**: No optimistic locking is implemented (consistent with existing content CRUD behavior).

### Dependencies on Previous Chunks
- **Chunk 6.1**: Element catalogue (`elements` table, `page_elements` table, `editor_mode` column), `ElementController::apiList()` endpoint, `SlotRenderer`, `PageRenderer`, `SeedElements`, `element-editor.js`
- **Chunk 2.2**: ContentController (base CRUD), content edit template, editor.js (TinyMCE)
- **Chunk 2.3**: Media browser modal (reused for image slot fields)
- **Chunk 1.3**: Session (CSRF token, flash messages, user_id)
- **Chunk 1.1**: App, Router, Request, Response, TemplateEngine

### What This Chunk Does NOT Do
- No AI-aware element generation (that's Chunk 6.3)
- No new database migrations (the schema from 6.1 already has everything needed)
- No new admin routes (uses existing `/admin/elements/api/list` from 6.1)
- No changes to the public rendering (FrontController already handles element-mode from 6.1)

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `public/assets/js/page-builder.js` | JavaScript | Create (~550-650 lines) |
| 2 | `templates/admin/content/edit.php` | Template | Modify (add mode toggle, builder panel, picker modal, init script) |
| 3 | `app/Admin/ContentController.php` | Class | Modify (editor_mode handling, savePageElements, loadPageElements) |
| 4 | `public/assets/css/admin.css` | Stylesheet | Modify (add ~300 lines of page builder styles) |

---

## Estimated Scope

- **New JavaScript**: 1 file (page-builder.js, ~550-650 lines)
- **Modified PHP**: 1 file (ContentController.php, ~80 new lines)
- **Modified template**: 1 file (edit.php, ~60 new lines)
- **Modified CSS**: ~300 new lines
- **Total new LOC**: ~1,000-1,100 lines
- **Total files touched**: 4 (1 new, 3 modified)
