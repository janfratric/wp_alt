# Chunk 6.3 — Per-Instance Element Styling
## Detailed Implementation Plan

---

## Overview

This chunk adds Elementor-like per-instance styling controls to the page builder. Each element instance gets a "Style" tab alongside the existing content fields, with controls for spacing, colors, typography, borders, effects, and layout. Each instance also gets a **Custom CSS** textarea where users (or the AI assistant) can write freeform CSS scoped to that instance — this is the primary mechanism for the AI to visually modify elements on request. Additionally, page-level wrappers (page-body, container, site-main) become stylable through a sidebar panel with both GUI controls and custom CSS. All style data is stored separately from content data (slot_data vs style_data).

**Core design decisions**:
- Content (slot_data_json) and styling (style_data_json) are stored in separate columns
- Per-instance GUI styles render as inline `style` attributes on the wrapper div (avoids CSS specificity wars)
- Per-instance **Custom CSS** renders in a `<style>` block, scoped to the instance via `[data-instance-id="N"]`
- Custom CSS overrides GUI styles (higher specificity from attribute selector)
- Page-level wrapper styles (GUI + custom CSS) render as a `<style>` block (targets fixed class names)
- All CSS values are sanitized server-side by a new `StyleRenderer` class

**AI workflow** (wired up fully in Chunk 6.4, but the UI and storage are built here):
- User tells AI: "make this hero section have a gradient background with rounded corners"
- AI writes CSS and applies it to the element's `custom_css` field in `style_data_json`
- The custom CSS appears in the Style tab's Custom CSS textarea — visible and editable by the user
- Same workflow applies to page layout wrappers

**Future**: Custom JavaScript per instance follows the same pattern (textarea in Advanced section, scoped execution) — deferred to a later chunk to keep scope manageable.

---

## File Modification/Creation Order

Files are listed in dependency order — each change only depends on files listed before it.

---

### 1. Create `migrations/005_element_styles.sqlite.sql` (+ mysql + pgsql variants)

**Purpose**: Add per-instance style data column and page-level styles table.

**Dependencies**: None — schema-only.

**SQLite variant**:

```sql
-- LiteCMS Element Styles — SQLite
-- Migration: 005_element_styles
-- Adds per-instance styling and page-level layout styling

-- Per-instance style overrides (spacing, colors, borders, etc.)
ALTER TABLE page_elements ADD COLUMN style_data_json TEXT NOT NULL DEFAULT '{}';

-- Page-level wrapper styling (page-body, container, site-main)
CREATE TABLE IF NOT EXISTS page_styles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_id INTEGER NOT NULL UNIQUE,
    style_data_json TEXT NOT NULL DEFAULT '{}',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_page_styles_content ON page_styles(content_id);
```

**MySQL variant**: Same structure with `INT AUTO_INCREMENT`, `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`.

**PostgreSQL variant**: Same structure with `SERIAL PRIMARY KEY`, `TIMESTAMP`.

---

### 2. Create `app/PageBuilder/StyleRenderer.php`

**Purpose**: Static utility class that converts style data arrays to CSS output and sanitizes CSS values.

**Dependencies**: None — pure utility class.

#### Methods:

```php
public static function buildInlineStyle(array $styleData): string
```
- Converts style_data keys to CSS `property:value` pairs
- Skips empty/unset values
- Returns semicolon-separated string for the `style` attribute (no outer quotes)

```php
public static function getCustomClasses(array $styleData): string
```
- Returns sanitized `custom_class` value (alphanumeric, hyphens, underscores, spaces only)

```php
public static function buildPageLayoutCss(array $pageStyleData): string
```
- Takes decoded page_styles JSON (keyed by target: `page_body`, `container`, `site_main`)
- Generates CSS rules for each target: `.page-body { ... }`, `.container { ... }`, `.site-main { ... }`
- Returns combined CSS string

```php
public static function sanitizeStyleData(array $data): array
```
- Validates all style values:
  - Numeric fields (`margin_top`, `opacity`, etc.): cast to float, clamp to reasonable range (0-9999 for sizes, 0-1 for opacity)
  - Color fields: regex validate `#hex` (3-8 chars) or `rgba(n,n,n,n)` pattern
  - Select fields (unit, align, weight, style): whitelist check against known values
  - `custom_class`: strip non-alphanumeric/hyphen/underscore/space characters
  - `custom_css`: sanitize via `sanitizeCustomCss()` (see below)
  - All values: block dangerous patterns (`url()`, `expression()`, `javascript:`, `@import`, `{`, `}`, `<`, `>`, `/*`)
- Returns sanitized array (invalid values replaced with empty string or removed)

```php
public static function sanitizeCustomCss(string $css): string
```
- Strips dangerous patterns: `@import`, `@charset`, `javascript:`, `expression()`, `behavior:`, `binding:`, `-moz-binding:`, `</style>`, `<script`, `<!--`
- Strips `url()` references that are not simple relative paths or data URIs (block `url(javascript:...)` etc.)
- Limits total length (max 10,000 chars)
- Returns sanitized CSS string

```php
public static function scopeCustomCss(string $css, string $scopeSelector): string
```
- Prepends the scope selector to every rule in the custom CSS
- Input: raw CSS like `".inner { color: red; } h2 { font-size: 2rem; }"`
- Scope selector: `".lcms-el[data-instance-id=\"42\"]"` or `".page-body"`
- Output: `".lcms-el[data-instance-id=\"42\"] .inner { color: red; } .lcms-el[data-instance-id=\"42\"] h2 { font-size: 2rem; }"`
- Simple regex-based approach: split on `}`, find the selector portion before `{`, prepend scope
- If a rule already starts with the scope selector, don't double-prefix

```php
private static function sanitizeCssValue(string $value): string
```
- Internal helper for individual value sanitization

#### Key mapping (style_data key → CSS property):

| Key Pattern | CSS Output |
|---|---|
| `margin_top` + `margin_unit` | `margin-top: {val}{unit}` |
| `margin_right` + `margin_unit` | `margin-right: {val}{unit}` |
| `margin_bottom` + `margin_unit` | `margin-bottom: {val}{unit}` |
| `margin_left` + `margin_unit` | `margin-left: {val}{unit}` |
| `padding_top` + `padding_unit` | `padding-top: {val}{unit}` |
| `padding_right` + `padding_unit` | `padding-right: {val}{unit}` |
| `padding_bottom` + `padding_unit` | `padding-bottom: {val}{unit}` |
| `padding_left` + `padding_unit` | `padding-left: {val}{unit}` |
| `bg_color` | `background-color: {val}` |
| `bg_image` | `background-image: url('{val}')` |
| `bg_size` | `background-size: {val}` |
| `bg_position` | `background-position: {val}` |
| `bg_repeat` | `background-repeat: {val}` |
| `text_color` | `color: {val}` |
| `text_size` + `text_size_unit` | `font-size: {val}{unit}` |
| `text_align` | `text-align: {val}` |
| `text_weight` | `font-weight: {val}` |
| `border_width` + `border_unit` + `border_style` + `border_color` | `border: {w}{unit} {style} {color}` |
| `border_radius` + `border_unit` | `border-radius: {val}{unit}` |
| `shadow_x` + `shadow_y` + `shadow_blur` + `shadow_spread` + `shadow_color` | `box-shadow: {x}px {y}px {blur}px {spread}px {color}` |
| `opacity` | `opacity: {val}` |
| `max_width` | `max-width: {val}` |
| `min_height` | `min-height: {val}` |
| `custom_css` | Rendered as a scoped `<style>` block (not inline) — see rendering section |

#### Whitelist values for select fields:

- `*_unit`: `px`, `rem`, `em`, `%`, `vh`, `vw`
- `text_align`: `left`, `center`, `right`, `justify`
- `text_weight`: `100`, `200`, `300`, `400`, `500`, `600`, `700`, `800`, `900`
- `border_style`: `none`, `solid`, `dashed`, `dotted`, `double`
- `bg_size`: `cover`, `contain`, `auto`
- `bg_position`: `center center`, `top center`, `bottom center`, `left center`, `right center`
- `bg_repeat`: `no-repeat`, `repeat`, `repeat-x`, `repeat-y`

---

### 3. Modify `app/PageBuilder/PageRenderer.php`

**Purpose**: Apply per-instance inline styles when rendering, add page layout CSS method.

**Dependencies**: StyleRenderer (step 2).

#### Changes to `loadInstances()`:

Add `'page_elements.style_data_json'` to the SELECT list.

#### Changes to `renderInstance()`:

After reading slot data, also read and apply style data:

```php
$instanceId = (int) ($instance['id'] ?? 0);
$styleData = $instance['style_data_json'] ?? '{}';
if (is_string($styleData)) {
    $styleData = json_decode($styleData, true) ?: [];
}

$inlineStyle = StyleRenderer::buildInlineStyle($styleData);
$extraClasses = StyleRenderer::getCustomClasses($styleData);

// Updated wrapper div output — now includes data-instance-id for CSS scoping:
return '<div class="lcms-el lcms-el-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')
    . ($extraClasses !== '' ? ' ' . htmlspecialchars($extraClasses, ENT_QUOTES, 'UTF-8') : '')
    . '" data-element-id="' . $elementId . '"'
    . ' data-instance-id="' . $instanceId . '"'
    . ($inlineStyle !== '' ? ' style="' . htmlspecialchars($inlineStyle, ENT_QUOTES, 'UTF-8') . '"' : '')
    . '>'
    . "\n" . $rendered . "\n"
    . "</div>\n";
```

**Key addition**: `data-instance-id` attribute on every wrapper div — used by Custom CSS scoping.

#### Changes to `getPageCss()`:

After collecting element catalogue CSS (deduplicated), also collect per-instance custom CSS:

```php
// After the existing loop that collects element CSS...
// Now collect per-instance custom CSS (scoped to each instance)
foreach ($instances as $instance) {
    $styleData = $instance['style_data_json'] ?? '{}';
    if (is_string($styleData)) {
        $styleData = json_decode($styleData, true) ?: [];
    }
    $customCss = trim($styleData['custom_css'] ?? '');
    if ($customCss !== '') {
        $instanceId = (int) ($instance['id'] ?? 0);
        $scope = '.lcms-el[data-instance-id="' . $instanceId . '"]';
        $css .= "/* Custom CSS: instance #{$instanceId} */\n"
             . StyleRenderer::scopeCustomCss($customCss, $scope) . "\n\n";
    }
}
```

#### New method:

```php
public static function getPageLayoutCss(int $contentId): string
```
- Queries `page_styles` table for the given content_id
- Parses `style_data_json`
- Calls `StyleRenderer::buildPageLayoutCss()` for GUI styles
- Also collects `custom_css` per target, scopes it via `StyleRenderer::scopeCustomCss()` using the target class (`.page-body`, `.container`, `.site-main`)
- Returns combined CSS string (empty if no styles)

**Note**: Must add `use App\PageBuilder\StyleRenderer;` import.

---

### 4. Modify `app/Admin/ContentController.php`

**Purpose**: Persist and load style data for elements and page layouts.

**Dependencies**: StyleRenderer (step 2), migration (step 1).

#### Changes to `savePageElements()`:

After reading `slot_data`, also read and persist `style_data`:

```php
$styleData = $element['style_data'] ?? [];
if (!is_array($styleData)) {
    $styleData = [];
}
$styleData = StyleRenderer::sanitizeStyleData($styleData);

QueryBuilder::query('page_elements')->insert([
    'content_id'      => $contentId,
    'element_id'      => $elementId,
    'sort_order'      => $sortOrder,
    'slot_data_json'  => json_encode($slotData, JSON_UNESCAPED_UNICODE),
    'style_data_json' => json_encode($styleData, JSON_UNESCAPED_UNICODE),
]);
```

#### Changes to `loadPageElements()`:

Add `'page_elements.style_data_json'` to the query SELECT. Add to returned array:

```php
'styleData' => json_decode($row['style_data_json'] ?? '{}', true) ?: [],
```

#### New methods:

```php
private function savePageStyles(int $contentId, Request $request): void
```
- Reads `page_styles_json` from request input
- Parses JSON, sanitizes each target's style data via `StyleRenderer::sanitizeStyleData()`
- Validates targets against whitelist: `page_body`, `container`, `site_main`
- Deletes existing row in `page_styles` for this content_id, inserts new one

```php
private function loadPageStyles(int $contentId): array
```
- Queries `page_styles` for the content_id
- Returns decoded `style_data_json` or empty array

#### Changes to `store()` and `update()`:

After calling `$this->savePageElements()`, also call:
```php
$this->savePageStyles((int) $id, $request);
```

#### Changes to `create()`:

Pass `'pageStyles' => []` to template render data.

#### Changes to `edit()`:

Pass `'pageStyles' => $this->loadPageStyles((int) $id)` to template render data.

**Note**: Must add `use App\PageBuilder\StyleRenderer;` import.

---

### 5. Modify `app/Templates/FrontController.php`

**Purpose**: Include page layout CSS in public rendering.

**Dependencies**: PageRenderer changes (step 3).

#### Changes to `page()` (after line 179):

```php
$elementCss .= PageRenderer::getPageLayoutCss($contentId);
```

#### Changes to `blogPost()` (similar location):

Same addition after the existing `getPageCss()` call.

---

### 6. Modify `public/assets/js/page-builder.js`

**Purpose**: Add tab system to instance cards, style panel with controls, and style data serialization.

**Dependencies**: None — JS-only. This is the **largest change** (~300 new lines).

#### Instance state extension:

Add `styleData: {}` to each instance object, loaded from existing data or initialized empty.

#### `createInstanceCard()` — add tab system:

After the header div, insert a tab bar:

```
pb-tab-bar
├── pb-tab.active (Content)
└── pb-tab (Style)
```

Split the existing body into two panels:
- `pb-content-panel` (contains existing slot fields) — visible by default
- `pb-style-panel` (created by `createStylePanel()`) — hidden by default

Tab click handler: toggle `.active` on tab buttons, toggle `.hidden` on panels.

#### New function `createStylePanel(instance, index)`:

Builds the style panel using `<details>/<summary>` for accordion sections:

1. **Spacing** — margin (4 number inputs + linked toggle + unit select) + padding (same)
2. **Background** — color picker, media browse button for bg image, size/position/repeat selects
3. **Typography** — text color picker, font size (number + unit), text align (button group: L/C/R/J), font weight select
4. **Border** — width (number + unit), style select, color picker, border radius (number)
5. **Effects** — box shadow (x/y/blur/spread number inputs + color), opacity range slider
6. **Layout** — max-width (text input), min-height (text input)
7. **Custom CSS** — monospace `<textarea>` for freeform CSS (scoped to this instance wrapper). Placeholder text: "/* CSS rules here apply to this element */". Shows a small info note: "Selectors are automatically scoped to this element instance."
8. **Advanced** — custom CSS class (text input)

All inputs use `data-instance="{index}" data-style="{propertyKey}"` attributes for DOM reading.

#### New function `createSpacingControl(prefix, data, index)`:

Creates a 4-sided spacing control with linked toggle:

```
[chain icon toggle]
[Top: ___] [Right: ___] [Bottom: ___] [Left: ___] [unit: px v]
```

When linked mode is active, changing any input propagates the value to all four.

#### New function `createColorField(key, data, index)`:

Creates a synced color picker + hex text input:

```
[color picker] [#hex text input]
```

Changes to the color picker update the text input and vice versa.

#### New function `readStyleDataFromDOM(card, index)`:

Reads all `[data-style]` inputs from the given card and populates `instance.styleData`. Handles:
- Regular inputs: `.value`
- Checkboxes (linked toggles): `.checked`
- Spacing groups: reads all 4 sides + unit + linked state
- Color fields: reads the text input value (canonical source)

#### Changes to `serializeInstances()`:

Update output to include style_data (including custom_css):

```js
var output = instances.map(function(inst) {
    return {
        element_id: inst.elementId,
        slot_data: inst.slotData || {},
        style_data: inst.styleData || {}
        // styleData includes custom_css key from the textarea
    };
});
```

#### Changes to `initPageBuilder()`:

When parsing existing instances, read `styleData` from each instance object.

---

### 7. Modify `public/assets/js/page-builder-init.js`

**Purpose**: Toggle page styles card visibility with editor mode, pass styleData during init.

**Dependencies**: page-builder.js changes (step 6).

#### Changes:

In the mode toggle handler, also toggle the `#page-styles-card` visibility:
- When elements mode: show the card
- When HTML mode: hide the card

No changes needed for instance data passing — `styleData` is already part of the instances array from `data-instances`.

---

### 8. Modify `templates/admin/content/edit.php`

**Purpose**: Add page styles sidebar card and script tag for page styles JS.

**Dependencies**: ContentController changes (step 4).

#### Add "Page Layout Styles" card in sidebar (after the Featured Image card):

```php
<!-- Page Layout Styles (visible when editor_mode = elements) -->
<div class="card" id="page-styles-card"
     style="<?= ($content['editor_mode'] ?? 'html') !== 'elements' ? 'display:none;' : '' ?>">
    <div class="card-header">Page Layout Styles</div>
    <div class="card-body">
        <div class="form-group">
            <label for="page-style-target">Target</label>
            <select id="page-style-target">
                <option value="page_body">Page Body</option>
                <option value="container">Container</option>
                <option value="site_main">Site Main</option>
            </select>
        </div>
        <div id="page-style-controls"></div>
        <input type="hidden" name="page_styles_json" id="page-styles-json"
               value="<?= $this->e(json_encode($pageStyles ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
    </div>
</div>
```

#### Add script tag (before editor.js):

```php
<script src="/assets/js/page-styles-init.js"></script>
```

---

### 9. Create `public/assets/js/page-styles-init.js`

**Purpose**: Handle page-level style controls in the sidebar card.

**Dependencies**: Same style control pattern as page-builder.js (step 6).

#### Functions:

- **`initPageStyles()`** — reads `page_styles_json` hidden input, parses JSON, builds controls for default target
- **Target selector change handler** — switches which target's controls are visible
- **`buildPageStyleControls(target, data)`** — creates style controls for a single target (reuses same accordion pattern as instance styles, but limited to: spacing, background, typography, layout, **custom CSS textarea**)
- **`readPageStylesFromDOM()`** — reads all style inputs and builds the full JSON
- **`serializePageStyles()`** — called on form submit, writes JSON to hidden input

Attaches to form submit event to serialize before submission.

---

### 10. Modify `public/assets/css/admin.css`

**Purpose**: Styles for tab bar, style panel, spacing controls, color fields, and page styles card.

**Dependencies**: None — CSS-only.

#### New CSS to append:

```css
/* --- Page Builder Tabs --- */
.pb-tab-bar { display: flex; gap: 0; border-bottom: 2px solid var(--color-border, #e5e7eb); margin-bottom: 1rem; }
.pb-tab { padding: 0.5rem 1rem; border: none; background: none; cursor: pointer; font-size: 0.85rem; font-weight: 500; color: var(--color-text-muted); border-bottom: 2px solid transparent; margin-bottom: -2px; transition: color 0.2s, border-color 0.2s; }
.pb-tab:hover { color: var(--color-text); }
.pb-tab.active { color: var(--color-primary); border-bottom-color: var(--color-primary); }

/* --- Style Panel --- */
.pb-style-panel { padding: 0.5rem 0; }
.pb-style-group { margin-bottom: 0.5rem; }
.pb-style-group summary { padding: 0.5rem 0; font-weight: 600; font-size: 0.85rem; cursor: pointer; color: var(--color-text); }
.pb-style-group[open] summary { margin-bottom: 0.5rem; }
.pb-style-group-content { padding: 0 0 0.5rem; }

/* --- Spacing Control --- */
.pb-spacing-control { display: flex; gap: 0.25rem; align-items: center; flex-wrap: wrap; margin-bottom: 0.75rem; }
.pb-spacing-control label { font-size: 0.75rem; color: var(--color-text-muted); min-width: 2rem; }
.pb-spacing-control input[type="number"] { width: 60px; padding: 0.25rem; font-size: 0.8rem; }
.pb-spacing-link { background: none; border: 1px solid var(--color-border); border-radius: 4px; padding: 0.25rem 0.4rem; cursor: pointer; font-size: 0.75rem; }
.pb-spacing-link.active { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }

/* --- Color Field --- */
.pb-color-field { display: flex; align-items: center; gap: 0.5rem; }
.pb-color-field input[type="color"] { width: 36px; height: 30px; padding: 0; border: 1px solid var(--color-border); border-radius: 4px; cursor: pointer; }
.pb-color-field input[type="text"] { width: 90px; padding: 0.25rem 0.4rem; font-size: 0.8rem; font-family: monospace; }

/* --- Align Button Group --- */
.pb-align-group { display: flex; gap: 0; }
.pb-align-group button { padding: 0.3rem 0.6rem; border: 1px solid var(--color-border); background: var(--color-bg); cursor: pointer; font-size: 0.8rem; }
.pb-align-group button:first-child { border-radius: 4px 0 0 4px; }
.pb-align-group button:last-child { border-radius: 0 4px 4px 0; }
.pb-align-group button:not(:first-child) { border-left: none; }
.pb-align-group button.active { background: var(--color-primary); color: #fff; border-color: var(--color-primary); }

/* --- Range Slider --- */
.pb-range-field { display: flex; align-items: center; gap: 0.5rem; }
.pb-range-field input[type="range"] { flex: 1; }
.pb-range-field .pb-range-value { min-width: 3rem; text-align: right; font-size: 0.8rem; font-family: monospace; }

/* --- Style Field Row --- */
.pb-style-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
.pb-style-row label { font-size: 0.8rem; min-width: 5rem; color: var(--color-text-muted); }
.pb-style-row select { font-size: 0.8rem; padding: 0.25rem; }
.pb-style-row input[type="number"] { width: 70px; padding: 0.25rem; font-size: 0.8rem; }
.pb-style-row input[type="text"] { width: 120px; padding: 0.25rem; font-size: 0.8rem; }

/* --- Custom CSS Textarea --- */
.pb-custom-css textarea { width: 100%; min-height: 100px; font-family: 'Consolas', 'Monaco', 'Courier New', monospace; font-size: 0.8rem; line-height: 1.4; padding: 0.5rem; border: 1px solid var(--color-border); border-radius: 4px; background: var(--color-bg-dark, #1e1e2e); color: var(--color-text-light, #cdd6f4); resize: vertical; tab-size: 2; }
.pb-custom-css textarea:focus { outline: 2px solid var(--color-primary); outline-offset: -1px; }
.pb-custom-css .pb-css-hint { font-size: 0.7rem; color: var(--color-text-muted); margin-top: 0.25rem; }
```

---

## Detailed Class Specifications

### `App\PageBuilder\StyleRenderer`

```
NEW STATIC METHODS:
  - public static buildInlineStyle(array $styleData): string
      Converts style data keys to CSS properties.
      Input: associative array of style keys → values.
      Output: semicolon-separated CSS string (e.g. "padding: 20px; background-color: #fff").
      Empty/unset values are skipped.

  - public static getCustomClasses(array $styleData): string
      Extracts and sanitizes the custom_class value.
      Returns space-separated class names (alphanumeric, hyphens, underscores only).

  - public static buildPageLayoutCss(array $pageStyleData): string
      Input: decoded page_styles JSON keyed by target (page_body, container, site_main).
      Output: CSS rules targeting .page-body, .container, .site-main.

  - public static sanitizeStyleData(array $data): array
      Validates and sanitizes all style values.
      Returns cleaned array.

  - public static sanitizeCustomCss(string $css): string
      Strips @import, @charset, javascript:, expression(), behavior:, </style>, <script.
      Limits length to 10,000 chars.

  - public static scopeCustomCss(string $css, string $scopeSelector): string
      Prepends scope selector to every CSS rule.
      Used for both per-instance scoping and page layout target scoping.

PRIVATE METHODS:
  - private static sanitizeCssValue(string $value): string
      Blocks injection patterns, allows safe CSS value characters.

  - private static sanitizeCssClass(string $value): string
      Strips everything except alphanumeric, hyphens, underscores, spaces.
```

### Changes to `App\PageBuilder\PageRenderer`

```
MODIFIED METHODS:
  - private static loadInstances(int $contentId): array
      CHANGE: Add 'page_elements.style_data_json' to SELECT list.

  - public static renderInstance(array $instance): string
      CHANGE: Read style_data_json, call StyleRenderer::buildInlineStyle() and
              StyleRenderer::getCustomClasses(). Apply to wrapper div.
              Add data-instance-id attribute for Custom CSS scoping.

  - public static getPageCss(int $contentId): string
      CHANGE: After collecting element catalogue CSS, also collect per-instance
              custom_css from style_data_json, scope via StyleRenderer::scopeCustomCss().

NEW METHODS:
  - public static getPageLayoutCss(int $contentId): string
      Queries page_styles table, calls StyleRenderer::buildPageLayoutCss() for GUI styles.
      Also collects custom_css per target, scopes via StyleRenderer::scopeCustomCss().
      Returns combined CSS string.

NEW IMPORTS:
  - use App\PageBuilder\StyleRenderer;
```

### Changes to `App\Admin\ContentController`

```
MODIFIED METHODS:
  - private savePageElements(int $contentId, Request $request): void
      CHANGE: Read style_data from each element in JSON. Sanitize via
              StyleRenderer::sanitizeStyleData(). Persist to style_data_json column.

  - private loadPageElements(int $contentId): array
      CHANGE: Add 'page_elements.style_data_json' to SELECT.
              Include 'styleData' in returned array.

  - public create(): Response
      CHANGE: Pass 'pageStyles' => [] to template data.

  - public store(): Response
      CHANGE: Call $this->savePageStyles() after savePageElements().

  - public edit(): Response
      CHANGE: Pass 'pageStyles' => $this->loadPageStyles($contentId) to template data.

  - public update(): Response
      CHANGE: Call $this->savePageStyles() after savePageElements().

NEW METHODS:
  - private savePageStyles(int $contentId, Request $request): void
      Reads page_styles_json from request, sanitizes, upserts into page_styles table.

  - private loadPageStyles(int $contentId): array
      Queries page_styles table, returns decoded style_data_json.

NEW IMPORTS:
  - use App\PageBuilder\StyleRenderer;
```

### Changes to `App\Templates\FrontController`

```
MODIFIED METHODS:
  - public page(): Response
      CHANGE: After getPageCss(), append getPageLayoutCss() to elementCss.

  - public blogPost(): Response
      CHANGE: Same addition.
```

---

## Acceptance Test Procedures

### Test 1: Migration applies cleanly

```
1. Run composer install / migrations.
2. Verify page_elements table has style_data_json column.
3. Verify page_styles table exists with content_id, style_data_json columns.
4. Existing page_elements rows have default '{}' for style_data_json.
```

### Test 2: StyleRenderer generates correct CSS

```
1. Call StyleRenderer::buildInlineStyle() with sample data:
   ['padding_top' => '20', 'padding_unit' => 'px', 'bg_color' => '#ff0000']
2. Verify output contains 'padding-top: 20px' and 'background-color: #ff0000'.
3. Verify empty values are skipped (no empty property:value pairs).
```

### Test 3: StyleRenderer blocks CSS injection

```
1. Call StyleRenderer::sanitizeStyleData() with malicious values:
   ['bg_color' => 'red; background-image: url(javascript:alert(1))', 'custom_class' => '<script>']
2. Verify injection patterns are stripped/blocked.
3. Verify custom_class returns only safe characters.
```

### Test 4: Per-instance styles save and load

```
1. Create content in elements mode with style_data on instances.
2. Verify page_elements rows contain style_data_json.
3. Load page in editor — verify styleData appears in template data.
```

### Test 5: Per-instance styles render on frontend

```
1. Save an element instance with padding and background color.
2. Visit the public page.
3. Verify the wrapper div has inline style attribute with correct CSS.
```

### Test 6: Page layout styles save and render

```
1. Save page_styles_json with page_body background color.
2. Visit the public page.
3. Verify the <style> block contains .page-body rule with correct color.
```

### Test 7: Style tab visible in admin UI

```
1. Open content editor with elements mode.
2. Add an element.
3. Verify "Content" and "Style" tabs appear.
4. Click "Style" — verify style controls appear (spacing, background, etc.).
5. Click "Content" — verify slot fields reappear.
```

### Test 8: HTML-mode content unaffected

```
1. Create content in HTML mode.
2. Verify no style_data_json or page_styles rows created.
3. Verify public rendering unchanged.
```

### Test 9: Custom CSS per instance saves and renders scoped

```
1. Save an element instance with custom_css: "h2 { color: red; } .inner { padding: 10px; }"
2. Visit the public page.
3. Verify the <style> block contains scoped rules:
   .lcms-el[data-instance-id="N"] h2 { color: red; }
   .lcms-el[data-instance-id="N"] .inner { padding: 10px; }
4. Verify the wrapper div has data-instance-id attribute.
```

### Test 10: Custom CSS sanitization blocks injection

```
1. Call StyleRenderer::sanitizeCustomCss() with:
   "@import url('evil.css'); .x { color: red; } </style><script>alert(1)</script>"
2. Verify @import is stripped.
3. Verify </style> and <script> are stripped.
4. Verify legitimate CSS (".x { color: red; }") is preserved.
```

### Test 11: Custom CSS scoping works correctly

```
1. Call StyleRenderer::scopeCustomCss() with CSS and scope selector.
2. Verify every rule is prefixed with scope selector.
3. Verify bare properties (no selector) are wrapped in scope block.
4. Verify @media queries have scope injected inside the block.
```

### Test 12: Page layout custom CSS renders scoped

```
1. Save page_styles_json with custom_css on page_body target:
   "background: linear-gradient(135deg, #667eea, #764ba2);"
2. Visit public page.
3. Verify <style> block contains: .page-body { background: linear-gradient(...); }
```

---

## Implementation Notes

### Design Decisions

1. **Inline styles vs generated CSS**: GUI style controls render as inline `style` attributes on the wrapper div — avoids CSS specificity issues. Element-level CSS (from the catalogue) provides the base styling, instance-level inline styles override specific properties.

2. **Custom CSS as scoped `<style>` block**: Per-instance custom CSS is rendered in the page's `<style>` block, scoped via `[data-instance-id="N"]` attribute selector. This gives custom CSS **higher specificity** than the inline GUI styles, so it acts as a true override. This also means the AI can write any valid CSS (pseudo-elements, media queries, transitions, animations) without being limited to inline-only properties.

3. **`<details>/<summary>` for accordion**: Using native HTML elements eliminates JS complexity for expand/collapse behavior and provides built-in accessibility.

4. **Separate `style_data_json` column**: Clean separation of content and presentation. Content editors modifying slot data cannot accidentally corrupt style data.

5. **Native `<input type="color">`**: Works in all modern browsers without third-party libraries. Paired with a text hex input for precise value entry.

6. **Page styles as `<style>` block**: Page wrapper targets use fixed class names, so a generated CSS block is cleaner than inline styles that would require template modifications.

7. **CSS specificity cascade**: Catalogue CSS → inline GUI styles → scoped custom CSS. Each layer can override the previous. The user/AI always has final say via custom CSS.

### Edge Cases

1. **Empty style data**: No inline style attribute or CSS emitted — element renders as before (backward compatible).
2. **Invalid CSS values**: `sanitizeStyleData()` strips or replaces them — the element still renders, just without that specific style property.
3. **Background image with spaces in URL**: URL is wrapped in single quotes in the CSS output.
4. **Multiple custom classes**: Allowed — space-separated values are preserved after sanitization.
5. **Linked spacing mode**: A UI convenience only — all four values are stored individually regardless. The `*_linked` key is stored but not used in rendering.
6. **Custom CSS with no selectors**: If the user writes just `color: red;` (no selector), `scopeCustomCss()` wraps it as `{scope} { color: red; }` — applying directly to the wrapper.
7. **Custom CSS with media queries**: `@media` rules are preserved; the scope selector is injected inside the media block.
8. **Custom CSS exceeds length limit**: Truncated to 10,000 chars after sanitization.

### Security Notes

- All CSS values pass through `sanitizeCssValue()` which blocks `url()`, `expression()`, `javascript:`, `@import`, braces, and HTML tags.
- Color values are regex-validated against `#hex` and `rgba()` patterns.
- Custom class names are stripped to alphanumeric/hyphens/underscores only.
- `htmlspecialchars()` is applied when outputting inline styles in HTML attributes.
- **Custom CSS sanitization**: `sanitizeCustomCss()` strips `@import`, `@charset`, `javascript:`, `expression()`, `behavior:`, `-moz-binding:`, `</style>`, `<script>`, and HTML comments. This allows legitimate CSS (selectors, properties, media queries, animations) while blocking XSS vectors.
- **Custom CSS scoping**: `scopeCustomCss()` ensures every rule is prefixed with the instance's attribute selector, preventing custom CSS from leaking to other elements or the page at large.

### What NOT to Change

- Existing element CSS from the catalogue — untouched.
- Existing slot data handling — fully backward compatible.
- Existing page builder features (drag/drop, picker, serialization) — only extended.
- HTML-mode content rendering — completely unchanged.

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `migrations/005_element_styles.sqlite.sql` | Migration | Create |
| 2 | `migrations/005_element_styles.mysql.sql` | Migration | Create |
| 3 | `migrations/005_element_styles.pgsql.sql` | Migration | Create |
| 4 | `app/PageBuilder/StyleRenderer.php` | Class | Create — 4 public + 2 private static methods |
| 5 | `app/PageBuilder/PageRenderer.php` | Class | Modify — loadInstances, renderInstance, new getPageLayoutCss |
| 6 | `app/Admin/ContentController.php` | Class | Modify — savePageElements, loadPageElements, new savePageStyles/loadPageStyles, create/edit/store/update |
| 7 | `app/Templates/FrontController.php` | Class | Modify — page() + blogPost(): append page layout CSS |
| 8 | `public/assets/js/page-builder.js` | JavaScript | Modify — tab system, style panel, controls, serialization (~300 new lines) |
| 9 | `public/assets/js/page-builder-init.js` | JavaScript | Modify — toggle page styles card visibility |
| 10 | `public/assets/js/page-styles-init.js` | JavaScript | Create (~80 lines) |
| 11 | `templates/admin/content/edit.php` | Template | Modify — add page styles card in sidebar, script tag |
| 12 | `public/assets/css/admin.css` | Stylesheet | Modify — append ~150 lines for tabs, style panel, controls |

---

## Estimated Scope

- **New PHP class**: ~200 LOC (StyleRenderer — includes sanitizeCustomCss + scopeCustomCss)
- **New migrations**: ~15 LOC x 3
- **Modified PHP**: ~100 LOC across ContentController, PageRenderer, FrontController
- **New/modified JS**: ~450 LOC (page-builder.js style panel + custom CSS textarea + controls), ~100 LOC (page-styles-init.js)
- **New CSS**: ~170 LOC (includes custom CSS textarea styles)
- **Approximate total LOC change**: ~1,100–1,300 lines
