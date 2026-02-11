# Chunk 7.2 — .pen-to-HTML Converter (PenConverter)
## Detailed Implementation Plan

---

## Overview

This chunk builds a PHP converter that reads `.pen` JSON files (created by the Pencil visual editor embedded in Chunk 7.1) and generates semantic HTML + CSS. The converter handles all `.pen` node types (frame, text, rectangle, ellipse, path, ref, group, icon\_font, line, polygon), CSS flexbox layout, fill/stroke/effects, typography, component resolution (`ref` → deep-clone + overrides), and variable resolution (`$--var` → CSS custom properties).

At completion, any `.pen` design file can be converted to clean, renderable HTML+CSS. This is the critical bridge enabling public rendering of visual editor designs.

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `app/PageBuilder/PenStyleBuilder.php`

**Purpose**: Converts individual `.pen` node properties into CSS declarations. Pure stateless utility — all methods are static, each takes a property value from the `.pen` schema and returns CSS string(s).

**Why first**: No dependencies on other new files. Both PenNodeRenderer and PenConverter depend on it.

---

### 2. `app/PageBuilder/PenNodeRenderer.php`

**Purpose**: Renders individual `.pen` node types into HTML+CSS pairs. Each node type (frame, text, rectangle, etc.) has a dedicated render method that produces semantic HTML and any node-specific CSS rules.

**Why second**: Depends on PenStyleBuilder for CSS generation. PenConverter depends on it for per-node rendering.

---

### 3. `app/PageBuilder/PenConverter.php`

**Purpose**: Main converter orchestrator. Reads a `.pen` JSON file, resolves components (ref nodes), resolves variables, recursively renders the node tree via PenNodeRenderer, and collects all CSS into a single stylesheet.

**Why third**: Depends on both PenStyleBuilder and PenNodeRenderer. This is the public API other code calls.

---

### 4. Modifications to `app/PageBuilder/PageRenderer.php`

**Purpose**: Add `renderFromPen($penFilePath)` static method that delegates to PenConverter and returns the same `{html, css}` structure used by existing element rendering.

**Why fourth**: Thin integration layer. Depends on PenConverter.

---

### 5. Modifications to `app/Admin/DesignController.php`

**Purpose**: Add `POST /admin/design/convert` endpoint that accepts a `.pen` file path, runs PenConverter, and returns the converted HTML+CSS as JSON. Add `GET /admin/design/preview` endpoint that renders the conversion in a standalone HTML page for visual verification.

**Why fifth**: API endpoints for testing and admin use. Depends on PenConverter.

---

### 6. Modifications to `app/Templates/FrontController.php`

**Purpose**: Add conditional check — when content has a `design_file` attribute (column added in Chunk 7.4), use PenConverter to render the page instead of the standard body or element-based rendering.

**Why sixth**: Light integration. The `design_file` column doesn't exist yet (migration in Chunk 7.4), so this code path won't trigger until then. But the logic is ready.

---

### 7. Modifications to `public/index.php`

**Purpose**: Register the new design convert/preview routes.

---

### 8. `tests/chunk-7.2-verify.php`

**Purpose**: Verification test script covering all converter functionality.

---

## Detailed Class Specifications

---

### `App\PageBuilder\PenStyleBuilder`

Stateless utility class. All methods are `public static`. Converts `.pen` schema properties to CSS declaration strings.

```
CONSTANTS:
  SEMANTIC_FONT_SIZES = [
      'h1' => 32, 'h2' => 24, 'h3' => 20, 'h4' => 18
  ]

  FILL_CONTAINER = 'fill_container'
  FIT_CONTENT = 'fit_content'

PUBLIC STATIC METHODS:

  buildFill(mixed $fill): string
      Converts .pen Fill type to CSS background declarations.
      - Color string (e.g. '#AABBCC', '#AABBCCDD') → 'background-color: #AABBCC;'
        For 8-digit hex, converts to rgba().
      - Variable string ('$--primary') → 'background-color: var(--primary);'
      - Fill object {type: 'color', color: '...'} → 'background-color: ...;'
        Respects 'enabled' flag — returns '' if enabled === false.
      - Fill object {type: 'gradient', ...} → 'background: linear-gradient(...)' or
        'background: radial-gradient(...)' based on gradientType.
        Converts gradient stops: color + position → CSS gradient stop list.
        Rotation: .pen uses counter-clockwise from top, CSS uses clockwise from top,
        so convert: css_angle = (360 - pen_rotation) % 360.
      - Fill object {type: 'image', url: '...', mode: '...'} →
        'background-image: url(...); background-size: cover|contain|100% 100%;
         background-position: center; background-repeat: no-repeat;'
        mode mapping: 'fill' → 'cover', 'fit' → 'contain', 'stretch' → '100% 100%'
      - Fill array → processes first enabled fill (or first if none have 'enabled').
      - Null/missing → ''
      Returns: CSS declaration string (including trailing semicolons).

  buildFills(mixed $fills): string
      Handles array of fills. Combines multiple fills using CSS stacking:
      backgrounds are layered with commas (images/gradients on top, solid color last).

  buildStroke(array $stroke): string
      Converts .pen Stroke to CSS border declarations.
      - $stroke['thickness'] as number → 'border: {n}px {style} {color};'
      - $stroke['thickness'] as {top,right,bottom,left} →
        individual border-top, border-right, etc.
      - $stroke['fill'] → processes via buildFill() for border-color.
        If fill is complex (gradient), falls back to first color stop.
      - $stroke['align'] → 'inside' uses box-sizing: border-box (default),
        'outside' adds outline instead of border,
        'center' uses standard border.
      - $stroke['dashPattern'] → if set, border-style: dashed.
      - $stroke['join'] → not directly mappable to CSS, ignored.
      Returns: CSS declaration string.

  buildEffects(mixed $effects): string
      Converts .pen Effects to CSS.
      - Effect {type: 'shadow', shadowType, offset, spread, blur, color} →
        Inner: 'box-shadow: inset {x}px {y}px {blur}px {spread}px {color};'
        Outer: 'box-shadow: {x}px {y}px {blur}px {spread}px {color};'
        Multiple shadows → comma-separated.
      - Effect {type: 'blur', radius} → 'filter: blur({radius}px);'
      - Effect {type: 'background_blur', radius} →
        'backdrop-filter: blur({radius}px); -webkit-backdrop-filter: blur({radius}px);'
      - Respects 'enabled' flag on each effect.
      Returns: CSS declaration string.

  buildLayout(array $node): string
      Converts .pen Layout properties to CSS flexbox declarations.
      - layout: 'horizontal' → 'display: flex; flex-direction: row;'
      - layout: 'vertical' → 'display: flex; flex-direction: column;'
      - layout: 'none' or missing → 'position: relative;' (children use absolute positioning)
      - gap → 'gap: {n}px;'
      - padding → 'padding: ...' (handles single value, [h,v], [t,r,b,l])
      - justifyContent → 'justify-content: ...'
        Mapping: 'start'→'flex-start', 'end'→'flex-end', 'center'→'center',
        'space_between'→'space-between', 'space_around'→'space-around'
      - alignItems → 'align-items: ...'
        Mapping: 'start'→'flex-start', 'end'→'flex-end', 'center'→'center'
      Returns: CSS declaration string.

  buildTypography(array $node): string
      Converts .pen TextStyle properties to CSS.
      - fontFamily → 'font-family: "{family}", sans-serif;'
        Variable reference → 'font-family: var(--family);'
      - fontSize → 'font-size: {n}px;'
      - fontWeight → 'font-weight: {w};'
      - fontStyle → 'font-style: {s};' (italic, normal)
      - letterSpacing → 'letter-spacing: {n}px;'
      - lineHeight → 'line-height: {n};' (ratio, e.g. 1.5)
      - textAlign → 'text-align: left|center|right|justify;'
      - textAlignVertical → not directly CSS; uses flexbox parent for vertical alignment
      - underline → 'text-decoration: underline;'
      - strikethrough → 'text-decoration: line-through;'
      Returns: CSS declaration string.

  buildSizing(array $node): string
      Converts .pen Size properties to CSS width/height.
      - Numeric value → '{n}px'
      - 'fill_container' → 'flex: 1 1 0%; min-width: 0;' (or min-height: 0 for column layout)
        Also stores 'width: 100%' or 'height: 100%' as fallback.
      - 'fill_container(N)' → same as fill_container + base size N
      - 'fit_content' → 'width: fit-content;' or 'height: fit-content;'
      - 'fit_content(N)' → 'width: fit-content; min-width: {N}px;'
      - Variable reference → 'width: var(--name);'
      Returns: CSS declaration string.

  buildPosition(array $node): string
      Only emitted for absolutely positioned children (parent layout=none).
      - x → 'left: {n}px;'
      - y → 'top: {n}px;'
      - rotation → 'transform: rotate({-n}deg);' (pen is counter-clockwise, CSS is clockwise)
      Returns: CSS declaration string.

  buildCornerRadius(mixed $radius): string
      - Number → 'border-radius: {n}px;'
      - Array [tl, tr, br, bl] → 'border-radius: {tl}px {tr}px {br}px {bl}px;'
      - Variable → 'border-radius: var(--name);'
      Returns: CSS declaration string.

  buildOpacity(mixed $opacity): string
      - Number → 'opacity: {n};'
      - Variable → 'opacity: var(--name);'
      Returns: CSS declaration string.

  buildClip(mixed $clip): string
      - true → 'overflow: hidden;'
      - false or missing → ''
      Returns: CSS declaration string.

  resolveValue(mixed $value): string
      Resolves variable references in any property value.
      - If string starts with '$' → 'var(--{rest})'
        E.g. '$--primary' → 'var(--primary)', '$spacing-m' → 'var(--spacing-m)'
      - If numeric → '{n}'
      - Otherwise → passthrough as string.

  resolveColor(mixed $color): string
      Like resolveValue but specifically for colors.
      - Variable → 'var(--name)'
      - 8-digit hex '#RRGGBBAA' → 'rgba(r, g, b, a)'
      - 6-digit hex → passthrough
      - 3-digit hex → expand to 6-digit

  hexToRgba(string $hex): string
      Converts 8-digit hex to rgba() string.

  buildAllStyles(array $node, bool $isAbsolute = false): string
      Convenience: calls buildFill, buildStroke, buildEffects, buildLayout,
      buildTypography, buildSizing, buildPosition (if isAbsolute), buildCornerRadius,
      buildOpacity, buildClip. Concatenates all non-empty results.
      Returns: Complete CSS declaration block (without selector/braces).
```

**Implementation Notes**:
- All `resolveValue()` calls handle the case where a value is a variable reference (`$--name`). The `$` prefix is stripped, and the result is wrapped in `var()`.
- Gradient rotation: `.pen` measures counter-clockwise from top (0°=up, 90°=left). CSS `linear-gradient()` measures clockwise from top (0°=up, 90°=right). Conversion: `css_deg = (360 - pen_deg) % 360`.
- Fill arrays: In `.pen`, later fills render on top. In CSS `background`, earlier backgrounds render on top. Reverse the array when building multi-background.
- `SizingBehavior` strings like `fill_container(100)` need regex parsing to extract the fallback value.

---

### `App\PageBuilder\PenNodeRenderer`

Renders individual `.pen` node types to `{html, css}` pairs. All methods are static. Each method receives the node array and a rendering context.

```
CONSTANTS:
  SEMANTIC_TAG_MAP = [
      'header'  => 'header',
      'footer'  => 'footer',
      'nav'     => 'nav',
      'sidebar' => 'aside',
      'section' => 'section',
      'article' => 'article',
      'main'    => 'main',
  ]

  HEADING_THRESHOLDS = [
      ['min' => 32, 'tag' => 'h1'],
      ['min' => 24, 'tag' => 'h2'],
      ['min' => 20, 'tag' => 'h3'],
      ['min' => 18, 'tag' => 'h4'],
      ['min' => 16, 'tag' => 'h5', 'requireBold' => true],
  ]

  ICON_FONT_CDN = [
      'lucide' => 'https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css',
      'feather' => 'https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.css',
      'Material Symbols Outlined' =>
          'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined',
      'Material Symbols Rounded' =>
          'https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded',
      'Material Symbols Sharp' =>
          'https://fonts.googleapis.com/css2?family=Material+Symbols+Sharp',
      'phosphor' => 'https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2/src/regular/style.css',
  ]

PUBLIC STATIC METHODS:

  renderNode(array $node, PenConverter $converter): array
      Main dispatcher. Reads $node['type'], delegates to the appropriate render method.
      Returns ['html' => string, 'css' => string].
      Handles 'enabled' === false → returns empty html/css.
      Handles opacity === 0 → returns empty html/css.

  renderFrame(array $node, PenConverter $converter): array
      Infers semantic HTML tag from node name:
        - Lowercase name, check against SEMANTIC_TAG_MAP keys (substring match).
        - Default: 'div'.
      Generates CSS class: 'pen-{id}'.
      Builds CSS rule from: fill, stroke, effects, layout, sizing, cornerRadius, clip, opacity.
      Recursively renders children via $converter->renderChildren($node['children']).
      Returns:
        html: '<{tag} class="pen-{id}">{childrenHtml}</{tag}>'
        css: '.pen-{id} { declarations }\n' + childrenCss

  renderText(array $node, PenConverter $converter): array
      Infers semantic HTML tag from fontSize + fontWeight:
        - Walk HEADING_THRESHOLDS; if fontSize >= min (and requireBold → fontWeight >= 600)
          use that tag.
        - If node has href → 'a' (with href attribute).
        - Default: 'p'.
      Handles TextContent:
        - String → escape HTML, preserve newlines as <br>.
        - Array of TextStyle → render styled <span> runs within parent tag.
          Each run can have its own fontFamily, fontSize, fontWeight, color, href, etc.
          href → wrap in <a>.
      Handles textGrowth:
        - 'auto' → no width/height constraints
        - 'fixed-width' → set width, let height auto
        - 'fixed-width-height' → set both, overflow hidden
      Generates CSS class: 'pen-{id}'.
      Returns:
        html: '<{tag} class="pen-{id}">{content}</{tag}>'
        css: '.pen-{id} { typography + fill-as-color + sizing }'
      NOTE: text fill → 'color' CSS property (not 'background-color').

  renderRectangle(array $node, PenConverter $converter): array
      Simple styled div.
      Generates CSS class: 'pen-{id}'.
      Returns:
        html: '<div class="pen-{id}"></div>'
        css: '.pen-{id} { fill→background, stroke→border, cornerRadius, effects, sizing }'

  renderEllipse(array $node, PenConverter $converter): array
      Styled div with border-radius: 50%.
      Handles innerRadius for ring shapes (mask or border-based).
      Handles partial arcs (startAngle/sweepAngle) via SVG fallback:
        if arc is not full circle → render as <svg> with arc path.
      Returns:
        html: '<div class="pen-{id}"></div>'  (or <svg> for arcs)
        css: '.pen-{id} { border-radius: 50%; fill→background, stroke→border, sizing }'

  renderLine(array $node, PenConverter $converter): array
      Renders as a styled <hr> or thin <div>.
      Returns:
        html: '<hr class="pen-{id}">'
        css: '.pen-{id} { border: none; border-top: {stroke}; width; height; }'

  renderPolygon(array $node, PenConverter $converter): array
      Renders as <svg> with a regular polygon path.
      Calculates polygon vertices from polygonCount and bounding box.
      Returns:
        html: '<svg class="pen-{id}" ...><polygon points="..."/></svg>'
        css: '.pen-{id} { sizing, effects }'

  renderPath(array $node, PenConverter $converter): array
      Renders as inline <svg> with the path geometry.
      Returns:
        html: '<svg class="pen-{id}" viewBox="0 0 {w} {h}" ...>
                 <path d="{geometry}" fill="{fill}" stroke="{stroke}" .../>
               </svg>'
        css: '.pen-{id} { sizing }'

  renderRef(array $node, PenConverter $converter): array
      Component instance resolution.
      1. Look up the referenced component from $converter->getComponent($node['ref']).
      2. Deep-clone the component's node tree.
      3. Apply root-level overrides: merge $node properties (except 'type', 'ref',
         'descendants', 'id') onto the cloned root.
      4. Apply descendant overrides from $node['descendants']:
         For each key (ID path) in descendants:
           - Navigate the cloned tree to find the descendant by ID path.
           - If the override has 'type' → full replacement (swap subtree).
           - Otherwise → merge properties onto the found descendant.
         ID path handling: 'childId' is a direct child, 'childId/grandchildId' is nested.
         For nested instances (ref within ref): resolve recursively.
      5. Render the resolved clone via $converter->renderNode().
      Returns: the rendered result.

  renderGroup(array $node, PenConverter $converter): array
      Similar to frame but without fill/stroke/cornerRadius.
      Groups are transparent containers that may have layout.
      Returns:
        html: '<div class="pen-{id}">{childrenHtml}</div>'
        css: '.pen-{id} { layout, sizing, effects, opacity }\n' + childrenCss

  renderIconFont(array $node, PenConverter $converter): array
      Renders an icon from an icon font.
      Returns:
        html: '<i class="pen-{id} {fontFamily} {iconName}"
                  style="font-size: {size}px; {fill→color}"></i>'
        css: may include @import for the icon font CDN (deduplicated by converter).
      Icon font class mapping:
        'lucide' → class 'icon-{name}'
        'Material Symbols *' → class '{family}' + ligature text content
        'feather' → class 'feather icon-{name}'
        'phosphor' → class 'ph ph-{name}'

PRIVATE STATIC METHODS:

  inferFrameTag(string $name): string
      Lowercase the name, check each SEMANTIC_TAG_MAP key as substring.
      Returns matched tag or 'div'.

  inferTextTag(array $node): string
      Check fontSize against HEADING_THRESHOLDS.
      Check href → 'a'.
      Default → 'p'.

  renderTextContent(mixed $content): string
      If string → htmlspecialchars() + nl2br().
      If array of TextStyle objects → render each as styled <span>.
      If null/empty → ''.

  generatePolygonPoints(int $sides, float $width, float $height,
                         float $cornerRadius = 0): string
      Calculates SVG polygon points for a regular polygon inscribed
      in the bounding box.

  escapeHtml(string $text): string
      htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
```

**Implementation Notes**:
- `renderRef()` is the most complex method. Component resolution must handle:
  - Simple single-level refs
  - Nested refs (a component that contains instances of other components)
  - `descendants` overrides with slash-separated ID paths
  - Full subtree replacement (when override has `type` property)
  - Circular reference detection (max depth of 10 to prevent infinite recursion)
- Text `fill` property maps to CSS `color` (not `background-color`), because text fill colors the glyphs.
- Icon font CDN links are collected by the converter and deduplicated in the final CSS output.
- For absolutely positioned children (parent has `layout: none`), emit `position: absolute; left: {x}px; top: {y}px;`.

---

### `App\PageBuilder\PenConverter`

Main converter class. Orchestrates the full `.pen` → HTML+CSS pipeline.

```
PROPERTIES:
  private array $document           // Parsed .pen document
  private array $components = []    // Registry: id → component node (reusable: true)
  private array $cssRules = []      // Collected CSS rules
  private array $iconFontImports = [] // Deduplicated icon font CSS imports
  private array $variables = []     // Document variables for :root CSS
  private array $themes = []        // Document theme axes
  private int $refDepth = 0         // Current ref resolution depth (circular ref guard)
  private const MAX_REF_DEPTH = 10  // Maximum nesting depth for ref resolution

CONSTRUCTOR:
  private __construct(array $document)
      Stores document, builds component registry, extracts variables/themes.

PUBLIC STATIC METHODS:

  convertFile(string $penPath): array
      Main entry point.
      1. Read file contents: file_get_contents($penPath).
      2. Parse JSON: json_decode($contents, true, 512, JSON_THROW_ON_ERROR).
      3. Validate: check 'children' key exists.
      4. Create instance: new self($document).
      5. Call $instance->convert().
      6. Return ['html' => string, 'css' => string].

  convertJson(string $json): array
      Alternative entry point for JSON string input (useful for API endpoints).
      Same as convertFile but skips file_get_contents.

  convertDocument(array $document): array
      Alternative entry point for pre-parsed document array.
      Creates instance and calls convert().

PUBLIC METHODS:

  getComponent(string $id): ?array
      Returns the component node from the registry, or null if not found.

  renderChildren(array $children): array
      Iterates $children, calls renderNode() on each.
      Returns ['html' => combinedHtml, 'css' => combinedCss].

  renderNode(array $node): array
      Delegates to PenNodeRenderer::renderNode($node, $this).
      Adds returned CSS to $this->cssRules.
      Returns ['html' => string, 'css' => string].

  addIconFontImport(string $family, string $url): void
      Adds icon font import URL if not already present.

  incrementRefDepth(): bool
      Increments refDepth, returns false if MAX_REF_DEPTH exceeded.

  decrementRefDepth(): void
      Decrements refDepth.

PRIVATE METHODS:

  convert(): array
      1. Build component registry: buildComponentRegistry().
      2. Render top-level children (skip reusable components — they're only
         rendered when referenced).
      3. Build variable CSS: buildVariableCss().
      4. Assemble final CSS: icon font imports + variable CSS + collected rules.
      5. Assemble final HTML: concatenated child HTML.
      6. Return ['html' => html, 'css' => css].

  buildComponentRegistry(): void
      Recursively walk document children.
      For any node with 'reusable' === true:
        Store in $this->components[$node['id']] = $node.
      Components can be nested (a component containing other components).

  buildVariableCss(): string
      Reads $this->document['variables'].
      Generates CSS :root block:
        :root {
          --name: value;
          --name: value;
        }
      For themed variables (value is array of {value, theme}):
        Default theme values go in :root.
        Other theme values go in [data-theme="..."] selectors.
      Variable types:
        'color' → output as-is (hex color)
        'number' → output with 'px' suffix (context-dependent, may need refinement)
        'string' → output as-is (font names, etc.)
        'boolean' → output as 1 or 0
      Returns: CSS string with :root and theme selectors.

  buildThemeCss(): string
      For each theme axis and value combination:
        [data-theme="{value}"] { themed variable overrides }
      Handles multi-axis themes by generating combination selectors.

  resolveComponent(string $refId, array $overrides, ?array $descendants): array
      1. Look up component: $this->components[$refId].
      2. Deep-clone via json_decode(json_encode($component), true).
      3. Merge root-level overrides.
      4. Apply descendant overrides via applyDescendants().
      5. Return resolved node tree.

  applyDescendants(array &$node, array $descendants): void
      For each path => override in $descendants:
        - Split path by '/'.
        - Walk the node tree following the path segments (matching by 'id').
        - For nested ref instances: follow through resolved component trees.
        - If override has 'type' → replace the node entirely.
        - Otherwise → merge override properties into the found node.

  findDescendant(array &$node, array $pathParts): ?array
      Recursively searches node tree for a descendant matching the ID path.
      Returns reference to the found node, or null.

  deepClone(array $node): array
      json_decode(json_encode($node), true) — simple deep clone.
```

**Implementation Notes**:
- **Component Registry**: Built by scanning all top-level and nested children for `reusable: true`. Components are NOT rendered in the main output — they only serve as templates for `ref` instances.
- **Ref Resolution**: When a `ref` node is encountered, the converter deep-clones the referenced component, applies overrides, and then renders the clone. This means the final HTML has no "component" concept — everything is expanded inline.
- **Circular Reference Guard**: `refDepth` is incremented each time a ref is resolved and decremented after. If it exceeds `MAX_REF_DEPTH` (10), the ref is rendered as a comment `<!-- Max ref depth exceeded for: {id} -->`.
- **Variable Resolution**: During CSS generation, any property value starting with `$` is converted to `var(--name)`. This happens in PenStyleBuilder, not PenConverter. PenConverter only generates the `:root` block.
- **Theme Support**: `.pen` files can define theme axes (e.g., `{mode: ['light', 'dark']}`). Variables can have per-theme values. PenConverter generates CSS using `[data-theme="dark"]` selectors for non-default theme values.
- **Top-Level Rendering**: Only non-reusable top-level children are rendered. These are typically "screens" or "pages" in the design. If there are multiple top-level frames, all are rendered in order.

---

## Integration Modifications

### `app/PageBuilder/PageRenderer.php` — New Method

```php
/**
 * Render a .pen design file to HTML + CSS.
 *
 * @param string $penFilePath Absolute path to the .pen file
 * @return array{html: string, css: string}
 */
public static function renderFromPen(string $penFilePath): array
{
    return PenConverter::convertFile($penFilePath);
}
```

Add `use App\PageBuilder\PenConverter;` to imports.

---

### `app/Admin/DesignController.php` — New Endpoints

**Method: `convert(Request $request): Response`**
- Route: `POST /admin/design/convert`
- Body: JSON `{ "path": "filename.pen" }` or `{ "json": "{...}" }`
- Validates path/json input.
- If `path`: resolves to `$this->designsDir . '/' . $path`, runs `PenConverter::convertFile()`.
- If `json`: runs `PenConverter::convertJson($json)`.
- Returns: JSON `{ "success": true, "html": "...", "css": "..." }`.
- Error handling: catches exceptions, returns `{ "success": false, "error": "..." }`.

**Method: `preview(Request $request): Response`**
- Route: `GET /admin/design/preview`
- Query param: `path` (filename of .pen file)
- Converts the file via PenConverter.
- Returns a standalone HTML page with the CSS in `<style>` and HTML in `<body>`.
- Includes a basic responsive viewport meta tag.
- Useful for visual testing of conversions.

---

### `app/Templates/FrontController.php` — Conditional Design File Rendering

In the `page()`, `blogPost()`, and `renderContentHomepage()` methods, add a check before the existing rendering logic:

```php
// Check for design file (column added in Chunk 7.4)
$designFile = $content['design_file'] ?? null;
if ($designFile && !empty(trim($designFile))) {
    $penPath = dirname(__DIR__, 2) . '/designs/' . $designFile;
    if (file_exists($penPath)) {
        $result = PageRenderer::renderFromPen($penPath);
        $body = $result['html'];
        $elementCss = ($elementCss ?? '') . "\n" . $result['css'];
    }
}
```

This code is safe even before the `design_file` column exists — `$content['design_file'] ?? null` will return null, and the block won't execute.

---

### `public/index.php` — New Routes

```php
// Inside the /admin group:
$router->post('/admin/design/convert', [App\Admin\DesignController::class, 'convert']);
$router->get('/admin/design/preview', [App\Admin\DesignController::class, 'preview']);
```

---

### `templates/public/layout.php` — No Changes Needed

The existing `<style id="litecms-element-styles">` block already injects `$elementCss`. PenConverter CSS will be appended to the `$elementCss` variable by FrontController, so it flows through the existing injection point.

---

## Full Code Templates

---

### `app/PageBuilder/PenStyleBuilder.php`

```php
<?php declare(strict_types=1);

namespace App\PageBuilder;

/**
 * Converts .pen node properties to CSS declarations.
 * Pure stateless utility — all methods are static.
 */
class PenStyleBuilder
{
    /**
     * Resolve a .pen variable reference to CSS var() or return raw value.
     */
    public static function resolveValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value) && str_starts_with($value, '$')) {
            $varName = substr($value, 1);
            // Strip leading -- if present (some .pen files use $--name)
            if (str_starts_with($varName, '--')) {
                return 'var(' . $varName . ')';
            }
            return 'var(--' . $varName . ')';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        return (string) $value;
    }

    /**
     * Resolve a .pen color value (hex, variable, rgba).
     */
    public static function resolveColor(mixed $color): string
    {
        if ($color === null) {
            return '';
        }
        if (is_string($color) && str_starts_with($color, '$')) {
            return self::resolveValue($color);
        }
        if (is_string($color) && str_starts_with($color, '#')) {
            $hex = ltrim($color, '#');
            // 3-digit hex → expand
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                return '#' . $hex;
            }
            // 8-digit hex → rgba
            if (strlen($hex) === 8) {
                return self::hexToRgba($color);
            }
            return $color;
        }
        return (string) $color;
    }

    /**
     * Convert 8-digit hex (#RRGGBBAA) to rgba().
     */
    public static function hexToRgba(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 8) {
            return '#' . $hex;
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $a = round(hexdec(substr($hex, 6, 2)) / 255, 3);
        return "rgba({$r}, {$g}, {$b}, {$a})";
    }

    /**
     * Convert .pen Fill to CSS background declarations.
     */
    public static function buildFill(mixed $fill): string
    {
        if ($fill === null) {
            return '';
        }

        // Variable reference
        if (is_string($fill) && str_starts_with($fill, '$')) {
            return 'background-color: ' . self::resolveValue($fill) . ';';
        }

        // Simple color string
        if (is_string($fill)) {
            return 'background-color: ' . self::resolveColor($fill) . ';';
        }

        // Array of fills → handle multi-fill
        if (is_array($fill) && isset($fill[0])) {
            return self::buildFills($fill);
        }

        // Fill object
        if (is_array($fill) && isset($fill['type'])) {
            if (isset($fill['enabled']) && $fill['enabled'] === false) {
                return '';
            }
            return match ($fill['type']) {
                'color' => 'background-color: ' . self::resolveColor($fill['color'] ?? '') . ';',
                'gradient' => self::buildGradient($fill),
                'image' => self::buildImageFill($fill),
                default => '',
            };
        }

        return '';
    }

    /**
     * Handle array of multiple fills (layered backgrounds).
     */
    public static function buildFills(mixed $fills): string
    {
        if (!is_array($fills) || empty($fills)) {
            return '';
        }
        // Single fill (not wrapped in array)
        if (isset($fills['type']) || (is_string($fills) && $fills !== '')) {
            return self::buildFill($fills);
        }

        $backgrounds = [];
        $bgColor = '';
        foreach ($fills as $fill) {
            if (!is_array($fill)) {
                // Simple color
                $bgColor = 'background-color: ' . self::resolveColor($fill) . ';';
                continue;
            }
            if (isset($fill['enabled']) && $fill['enabled'] === false) {
                continue;
            }
            $type = $fill['type'] ?? 'color';
            if ($type === 'color') {
                $bgColor = 'background-color: ' . self::resolveColor($fill['color'] ?? '') . ';';
            } elseif ($type === 'gradient') {
                $backgrounds[] = self::buildGradientValue($fill);
            } elseif ($type === 'image') {
                $url = $fill['url'] ?? '';
                $mode = $fill['mode'] ?? 'fill';
                $size = match ($mode) {
                    'fill' => 'cover', 'fit' => 'contain', 'stretch' => '100% 100%',
                    default => 'cover',
                };
                $backgrounds[] = "url('{$url}') center / {$size} no-repeat";
            }
        }

        $css = '';
        if (!empty($backgrounds)) {
            $css .= 'background: ' . implode(', ', $backgrounds) . ';';
        }
        if ($bgColor !== '') {
            $css .= $bgColor;
        }
        return $css;
    }

    /**
     * Build CSS gradient from .pen gradient fill.
     */
    private static function buildGradient(array $fill): string
    {
        $value = self::buildGradientValue($fill);
        return $value ? "background: {$value};" : '';
    }

    private static function buildGradientValue(array $fill): string
    {
        $gradType = $fill['gradientType'] ?? 'linear';
        $colors = $fill['colors'] ?? [];
        if (empty($colors)) {
            return '';
        }

        $stops = [];
        foreach ($colors as $stop) {
            $c = self::resolveColor($stop['color'] ?? '#000000');
            $pos = isset($stop['position']) ? (self::resolveValue($stop['position'])) : '';
            $posStr = is_numeric($pos) ? ' ' . round((float)$pos * 100) . '%' : '';
            $stops[] = $c . $posStr;
        }
        $stopStr = implode(', ', $stops);

        if ($gradType === 'linear') {
            $rotation = (float)($fill['rotation'] ?? 0);
            // .pen: counter-clockwise from top. CSS: clockwise from top (to bottom = 180deg)
            $cssDeg = fmod(360 - $rotation + 180, 360);
            return "linear-gradient({$cssDeg}deg, {$stopStr})";
        }

        if ($gradType === 'radial') {
            return "radial-gradient(ellipse at center, {$stopStr})";
        }

        if ($gradType === 'angular') {
            return "conic-gradient({$stopStr})";
        }

        return "linear-gradient({$stopStr})";
    }

    /**
     * Build CSS background-image from .pen image fill.
     */
    private static function buildImageFill(array $fill): string
    {
        $url = $fill['url'] ?? '';
        if ($url === '') {
            return '';
        }
        $mode = $fill['mode'] ?? 'fill';
        $size = match ($mode) {
            'fill' => 'cover',
            'fit' => 'contain',
            'stretch' => '100% 100%',
            default => 'cover',
        };
        $css = "background-image: url('{$url}');";
        $css .= "background-size: {$size};";
        $css .= 'background-position: center;';
        $css .= 'background-repeat: no-repeat;';
        if (isset($fill['opacity'])) {
            $css .= 'opacity: ' . self::resolveValue($fill['opacity']) . ';';
        }
        return $css;
    }

    /**
     * Convert .pen Stroke to CSS border declarations.
     */
    public static function buildStroke(mixed $stroke): string
    {
        if (!is_array($stroke) || empty($stroke)) {
            return '';
        }

        $thickness = $stroke['thickness'] ?? 0;
        $strokeFill = $stroke['fill'] ?? null;
        $color = '#000000';
        if ($strokeFill !== null) {
            if (is_string($strokeFill)) {
                $color = self::resolveColor($strokeFill);
            } elseif (is_array($strokeFill) && isset($strokeFill['type'])) {
                if ($strokeFill['type'] === 'color') {
                    $color = self::resolveColor($strokeFill['color'] ?? '#000000');
                }
            } elseif (is_array($strokeFill) && isset($strokeFill[0])) {
                // First color from array
                $first = $strokeFill[0];
                if (is_string($first)) {
                    $color = self::resolveColor($first);
                } elseif (is_array($first) && isset($first['color'])) {
                    $color = self::resolveColor($first['color']);
                }
            }
        }

        $style = 'solid';
        if (!empty($stroke['dashPattern'])) {
            $style = 'dashed';
        }

        // Per-side thickness
        if (is_array($thickness)) {
            $css = '';
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $t = $thickness[$side] ?? 0;
                if (is_numeric($t) && (float)$t > 0) {
                    $tv = self::resolveValue($t);
                    $css .= "border-{$side}: {$tv}px {$style} {$color};";
                }
            }
            return $css;
        }

        // Uniform thickness
        $tv = self::resolveValue($thickness);
        if ($tv === '' || $tv === '0') {
            return '';
        }
        return "border: {$tv}px {$style} {$color};";
    }

    /**
     * Convert .pen Effects to CSS.
     */
    public static function buildEffects(mixed $effects): string
    {
        if ($effects === null) {
            return '';
        }
        if (!is_array($effects)) {
            return '';
        }
        // Single effect (not wrapped in array)
        if (isset($effects['type'])) {
            $effects = [$effects];
        }

        $shadows = [];
        $filters = [];
        $backdropFilters = [];

        foreach ($effects as $effect) {
            if (isset($effect['enabled']) && $effect['enabled'] === false) {
                continue;
            }
            $type = $effect['type'] ?? '';
            if ($type === 'shadow') {
                $x = self::resolveValue($effect['offset']['x'] ?? 0);
                $y = self::resolveValue($effect['offset']['y'] ?? 0);
                $blur = self::resolveValue($effect['blur'] ?? 0);
                $spread = self::resolveValue($effect['spread'] ?? 0);
                $sColor = self::resolveColor($effect['color'] ?? '#00000040');
                $inner = ($effect['shadowType'] ?? 'outer') === 'inner' ? 'inset ' : '';
                $shadows[] = "{$inner}{$x}px {$y}px {$blur}px {$spread}px {$sColor}";
            } elseif ($type === 'blur') {
                $radius = self::resolveValue($effect['radius'] ?? 0);
                $filters[] = "blur({$radius}px)";
            } elseif ($type === 'background_blur') {
                $radius = self::resolveValue($effect['radius'] ?? 0);
                $backdropFilters[] = "blur({$radius}px)";
            }
        }

        $css = '';
        if (!empty($shadows)) {
            $css .= 'box-shadow: ' . implode(', ', $shadows) . ';';
        }
        if (!empty($filters)) {
            $css .= 'filter: ' . implode(' ', $filters) . ';';
        }
        if (!empty($backdropFilters)) {
            $bd = implode(' ', $backdropFilters);
            $css .= "backdrop-filter: {$bd}; -webkit-backdrop-filter: {$bd};";
        }
        return $css;
    }

    /**
     * Convert .pen Layout properties to CSS flexbox.
     */
    public static function buildLayout(array $node): string
    {
        $layout = $node['layout'] ?? null;
        if ($layout === null || $layout === 'none') {
            // No flexbox — children are absolute
            return 'position: relative;';
        }

        $css = 'display: flex;';
        $css .= $layout === 'vertical' ? 'flex-direction: column;' : 'flex-direction: row;';

        $gap = $node['gap'] ?? null;
        if ($gap !== null) {
            $css .= 'gap: ' . self::resolveValue($gap) . 'px;';
        }

        // Padding
        $padding = $node['padding'] ?? null;
        if ($padding !== null) {
            $css .= self::buildPadding($padding);
        }

        // Justify content
        $jc = $node['justifyContent'] ?? null;
        if ($jc !== null) {
            $map = [
                'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end',
                'space_between' => 'space-between', 'space_around' => 'space-around',
            ];
            $css .= 'justify-content: ' . ($map[$jc] ?? 'flex-start') . ';';
        }

        // Align items
        $ai = $node['alignItems'] ?? null;
        if ($ai !== null) {
            $map = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end'];
            $css .= 'align-items: ' . ($map[$ai] ?? 'flex-start') . ';';
        }

        return $css;
    }

    /**
     * Build CSS padding from .pen padding value.
     */
    public static function buildPadding(mixed $padding): string
    {
        if ($padding === null) {
            return '';
        }
        if (is_numeric($padding) || (is_string($padding) && str_starts_with($padding, '$'))) {
            return 'padding: ' . self::resolveValue($padding) . 'px;';
        }
        if (is_array($padding)) {
            $vals = array_map(fn($v) => self::resolveValue($v) . 'px', $padding);
            if (count($vals) === 2) {
                return "padding: {$vals[0]} {$vals[1]};";
            }
            if (count($vals) === 4) {
                return "padding: {$vals[0]} {$vals[1]} {$vals[2]} {$vals[3]};";
            }
        }
        return '';
    }

    /**
     * Convert .pen TextStyle to CSS typography.
     */
    public static function buildTypography(array $node): string
    {
        $css = '';

        if (isset($node['fontFamily'])) {
            $ff = self::resolveValue($node['fontFamily']);
            if (str_starts_with($ff, 'var(')) {
                $css .= "font-family: {$ff};";
            } else {
                $css .= "font-family: \"{$ff}\", sans-serif;";
            }
        }
        if (isset($node['fontSize'])) {
            $css .= 'font-size: ' . self::resolveValue($node['fontSize']) . 'px;';
        }
        if (isset($node['fontWeight'])) {
            $css .= 'font-weight: ' . self::resolveValue($node['fontWeight']) . ';';
        }
        if (isset($node['fontStyle'])) {
            $css .= 'font-style: ' . self::resolveValue($node['fontStyle']) . ';';
        }
        if (isset($node['letterSpacing'])) {
            $css .= 'letter-spacing: ' . self::resolveValue($node['letterSpacing']) . 'px;';
        }
        if (isset($node['lineHeight'])) {
            $css .= 'line-height: ' . self::resolveValue($node['lineHeight']) . ';';
        }
        if (isset($node['textAlign'])) {
            $css .= 'text-align: ' . $node['textAlign'] . ';';
        }

        $decoration = [];
        if (!empty($node['underline'])) {
            $decoration[] = 'underline';
        }
        if (!empty($node['strikethrough'])) {
            $decoration[] = 'line-through';
        }
        if (!empty($decoration)) {
            $css .= 'text-decoration: ' . implode(' ', $decoration) . ';';
        }

        return $css;
    }

    /**
     * Convert .pen Size to CSS width/height.
     */
    public static function buildSizing(array $node, string $parentLayout = 'horizontal'): string
    {
        $css = '';

        foreach (['width', 'height'] as $dim) {
            $value = $node[$dim] ?? null;
            if ($value === null) {
                continue;
            }
            $css .= self::buildDimension($dim, $value, $parentLayout);
        }

        return $css;
    }

    /**
     * Build a single dimension (width or height) CSS.
     */
    public static function buildDimension(string $dim, mixed $value,
                                           string $parentLayout = 'horizontal'): string
    {
        if ($value === null) {
            return '';
        }
        if (is_numeric($value)) {
            return "{$dim}: {$value}px;";
        }
        if (is_string($value)) {
            // Variable
            if (str_starts_with($value, '$')) {
                return "{$dim}: " . self::resolveValue($value) . ';';
            }
            // fill_container
            if (str_starts_with($value, 'fill_container')) {
                $fallback = self::extractFallback($value);
                $isMainAxis = ($dim === 'width' && $parentLayout === 'horizontal')
                           || ($dim === 'height' && $parentLayout === 'vertical');
                if ($isMainAxis) {
                    $base = $fallback !== null ? "{$fallback}px" : '0%';
                    return "flex: 1 1 {$base}; min-{$dim}: 0;";
                }
                // Cross axis: fill_container = 100%
                return "{$dim}: 100%;";
            }
            // fit_content
            if (str_starts_with($value, 'fit_content')) {
                $fallback = self::extractFallback($value);
                $css = "{$dim}: fit-content;";
                if ($fallback !== null) {
                    $css .= "min-{$dim}: {$fallback}px;";
                }
                return $css;
            }
        }

        return '';
    }

    /**
     * Extract fallback value from sizing strings like 'fill_container(100)'.
     */
    public static function extractFallback(string $sizing): ?float
    {
        if (preg_match('/\((\d+(?:\.\d+)?)\)/', $sizing, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /**
     * Build CSS position for absolutely positioned nodes.
     */
    public static function buildPosition(array $node): string
    {
        $css = 'position: absolute;';
        if (isset($node['x'])) {
            $css .= 'left: ' . self::resolveValue($node['x']) . 'px;';
        }
        if (isset($node['y'])) {
            $css .= 'top: ' . self::resolveValue($node['y']) . 'px;';
        }
        return $css;
    }

    /**
     * Build CSS rotation transform.
     */
    public static function buildRotation(mixed $rotation): string
    {
        if ($rotation === null || $rotation === 0) {
            return '';
        }
        // .pen: counter-clockwise. CSS: clockwise. Negate.
        $val = self::resolveValue($rotation);
        if (is_numeric($val)) {
            $deg = -(float)$val;
            return "transform: rotate({$deg}deg);";
        }
        return "transform: rotate(calc(-1 * {$val}));";
    }

    /**
     * Convert .pen cornerRadius to CSS border-radius.
     */
    public static function buildCornerRadius(mixed $radius): string
    {
        if ($radius === null || $radius === 0) {
            return '';
        }
        if (is_numeric($radius) || (is_string($radius) && str_starts_with($radius, '$'))) {
            return 'border-radius: ' . self::resolveValue($radius) . 'px;';
        }
        if (is_array($radius) && count($radius) === 4) {
            $vals = array_map(fn($v) => self::resolveValue($v) . 'px', $radius);
            return 'border-radius: ' . implode(' ', $vals) . ';';
        }
        return '';
    }

    /**
     * Convert opacity value.
     */
    public static function buildOpacity(mixed $opacity): string
    {
        if ($opacity === null || $opacity === 1 || $opacity === 1.0) {
            return '';
        }
        return 'opacity: ' . self::resolveValue($opacity) . ';';
    }

    /**
     * Convert clip to overflow.
     */
    public static function buildClip(mixed $clip): string
    {
        if ($clip === true) {
            return 'overflow: hidden;';
        }
        return '';
    }

    /**
     * Build text color from fill (text uses color, not background).
     */
    public static function buildTextColor(mixed $fill): string
    {
        if ($fill === null) {
            return '';
        }
        if (is_string($fill)) {
            return 'color: ' . self::resolveColor($fill) . ';';
        }
        if (is_array($fill) && isset($fill['type']) && $fill['type'] === 'color') {
            return 'color: ' . self::resolveColor($fill['color'] ?? '') . ';';
        }
        if (is_array($fill) && isset($fill['type']) && $fill['type'] === 'gradient') {
            // Text with gradient fill → CSS background-clip trick
            $grad = self::buildGradientValue($fill);
            return "background: {$grad}; -webkit-background-clip: text; " .
                   '-webkit-text-fill-color: transparent; background-clip: text;';
        }
        // Array of fills → use first color
        if (is_array($fill) && isset($fill[0])) {
            return self::buildTextColor($fill[0]);
        }
        return '';
    }

    /**
     * Convenience: build all CSS for a node.
     */
    public static function buildAllStyles(array $node, bool $isAbsolute = false,
                                           string $parentLayout = 'horizontal'): string
    {
        $css = '';
        $css .= self::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= self::buildPosition($node);
        }
        $css .= self::buildCornerRadius($node['cornerRadius'] ?? null);
        $css .= self::buildOpacity($node['opacity'] ?? null);
        $css .= self::buildClip($node['clip'] ?? null);
        $css .= self::buildRotation($node['rotation'] ?? null);
        return $css;
    }
}
```

---

### `app/PageBuilder/PenNodeRenderer.php`

```php
<?php declare(strict_types=1);

namespace App\PageBuilder;

/**
 * Renders individual .pen node types into HTML + CSS pairs.
 */
class PenNodeRenderer
{
    /** Semantic HTML tag mapping based on frame name keywords */
    private const SEMANTIC_TAG_MAP = [
        'header'  => 'header',
        'footer'  => 'footer',
        'nav'     => 'nav',
        'sidebar' => 'aside',
        'section' => 'section',
        'article' => 'article',
        'main'    => 'main',
    ];

    /** Heading inference thresholds */
    private const HEADING_THRESHOLDS = [
        ['min' => 32, 'tag' => 'h1', 'requireBold' => false],
        ['min' => 24, 'tag' => 'h2', 'requireBold' => false],
        ['min' => 20, 'tag' => 'h3', 'requireBold' => false],
        ['min' => 18, 'tag' => 'h4', 'requireBold' => false],
        ['min' => 16, 'tag' => 'h5', 'requireBold' => true],
    ];

    /**
     * Main dispatcher — routes to the correct render method based on node type.
     */
    public static function renderNode(array $node, PenConverter $converter): array
    {
        // Skip disabled nodes
        if (isset($node['enabled']) && $node['enabled'] === false) {
            return ['html' => '', 'css' => ''];
        }

        $type = $node['type'] ?? '';
        return match ($type) {
            'frame'     => self::renderFrame($node, $converter),
            'text'      => self::renderText($node, $converter),
            'rectangle' => self::renderRectangle($node, $converter),
            'ellipse'   => self::renderEllipse($node, $converter),
            'line'      => self::renderLine($node, $converter),
            'polygon'   => self::renderPolygon($node, $converter),
            'path'      => self::renderPath($node, $converter),
            'ref'       => self::renderRef($node, $converter),
            'group'     => self::renderGroup($node, $converter),
            'icon_font' => self::renderIconFont($node, $converter),
            // Skip non-renderable types
            'note', 'prompt', 'context' => ['html' => '', 'css' => ''],
            default     => ['html' => '', 'css' => ''],
        };
    }

    /**
     * Render a frame node — semantic tag inferred from name.
     */
    public static function renderFrame(array $node, PenConverter $converter): array
    {
        // Skip reusable component definitions (they're templates)
        if (!empty($node['reusable'])) {
            return ['html' => '', 'css' => ''];
        }

        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $tag = self::inferFrameTag($node['name'] ?? '');

        // Build CSS
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box;';
        $css .= PenStyleBuilder::buildLayout($node);
        $css .= PenStyleBuilder::buildFill($node['fill'] ?? null);
        $css .= PenStyleBuilder::buildStroke($node['stroke'] ?? null);
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= PenStyleBuilder::buildAllStyles($node, $isAbsolute, $parentLayout);
        $css .= "}\n";

        // Render children
        $childrenResult = ['html' => '', 'css' => ''];
        if (!empty($node['children'])) {
            $prevLayout = $converter->getParentLayout();
            $converter->setParentLayout($node['layout'] ?? 'horizontal');
            $childrenResult = $converter->renderChildren($node['children']);
            $converter->setParentLayout($prevLayout);
        }

        $html = "<{$tag} class=\"{$cls}\">";
        $html .= $childrenResult['html'];
        $html .= "</{$tag}>";

        return [
            'html' => $html,
            'css' => $css . $childrenResult['css'],
        ];
    }

    /**
     * Render a text node — semantic tag inferred from font properties.
     */
    public static function renderText(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $tag = self::inferTextTag($node);
        $content = self::renderTextContent($node['content'] ?? '');

        // Build CSS
        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box; margin: 0;';
        $css .= PenStyleBuilder::buildTypography($node);
        $css .= PenStyleBuilder::buildTextColor($node['fill'] ?? null);
        $css .= PenStyleBuilder::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= PenStyleBuilder::buildOpacity($node['opacity'] ?? null);
        $css .= PenStyleBuilder::buildRotation($node['rotation'] ?? null);
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);

        // textGrowth handling
        $tg = $node['textGrowth'] ?? 'auto';
        if ($tg === 'fixed-width-height') {
            $css .= 'overflow: hidden;';
        }

        $css .= "}\n";

        // Handle href
        $href = $node['href'] ?? null;
        if ($href) {
            $hrefEsc = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
            $html = "<a href=\"{$hrefEsc}\" class=\"{$cls}\">{$content}</a>";
        } else {
            $html = "<{$tag} class=\"{$cls}\">{$content}</{$tag}>";
        }

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Render a rectangle node as a styled div.
     */
    public static function renderRectangle(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box;';
        $css .= PenStyleBuilder::buildFill($node['fill'] ?? null);
        $css .= PenStyleBuilder::buildStroke($node['stroke'] ?? null);
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= PenStyleBuilder::buildAllStyles($node, $isAbsolute, $parentLayout);
        $css .= "}\n";

        return [
            'html' => "<div class=\"{$cls}\"></div>",
            'css' => $css,
        ];
    }

    /**
     * Render an ellipse node.
     */
    public static function renderEllipse(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box; border-radius: 50%;';
        $css .= PenStyleBuilder::buildFill($node['fill'] ?? null);
        $css .= PenStyleBuilder::buildStroke($node['stroke'] ?? null);
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= PenStyleBuilder::buildAllStyles($node, $isAbsolute, $parentLayout);
        $css .= "}\n";

        return [
            'html' => "<div class=\"{$cls}\"></div>",
            'css' => $css,
        ];
    }

    /**
     * Render a line node.
     */
    public static function renderLine(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $stroke = $node['stroke'] ?? [];
        $thickness = 1;
        if (isset($stroke['thickness'])) {
            $t = $stroke['thickness'];
            $thickness = is_numeric($t) ? (int)$t : 1;
        }
        $color = '#000000';
        if (isset($stroke['fill'])) {
            $color = PenStyleBuilder::resolveColor(
                is_string($stroke['fill']) ? $stroke['fill'] :
                ($stroke['fill']['color'] ?? '#000000')
            );
        }

        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box; border: none;';
        $css .= "border-top: {$thickness}px solid {$color};";
        $css .= PenStyleBuilder::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= "}\n";

        return [
            'html' => "<hr class=\"{$cls}\">",
            'css' => $css,
        ];
    }

    /**
     * Render a polygon node as SVG.
     */
    public static function renderPolygon(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $w = (float)($node['width'] ?? 100);
        $h = (float)($node['height'] ?? 100);
        $sides = (int)($node['polygonCount'] ?? 6);

        $points = self::generatePolygonPoints($sides, $w, $h);
        $fillColor = self::extractSvgFill($node['fill'] ?? null);
        $strokeAttr = self::extractSvgStroke($node['stroke'] ?? null);

        $html = "<svg class=\"{$cls}\" viewBox=\"0 0 {$w} {$h}\" " .
                "width=\"{$w}\" height=\"{$h}\">" .
                "<polygon points=\"{$points}\" fill=\"{$fillColor}\" {$strokeAttr}/>" .
                '</svg>';

        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';
        $css = ".{$cls} {";
        $css .= PenStyleBuilder::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= "}\n";

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Render an SVG path node.
     */
    public static function renderPath(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $w = (float)($node['width'] ?? 100);
        $h = (float)($node['height'] ?? 100);
        $geometry = $node['geometry'] ?? '';
        $fillRule = $node['fillRule'] ?? 'nonzero';

        $fillColor = self::extractSvgFill($node['fill'] ?? null);
        $strokeAttr = self::extractSvgStroke($node['stroke'] ?? null);

        $html = "<svg class=\"{$cls}\" viewBox=\"0 0 {$w} {$h}\" " .
                "width=\"{$w}\" height=\"{$h}\">" .
                "<path d=\"{$geometry}\" fill=\"{$fillColor}\" " .
                "fill-rule=\"{$fillRule}\" {$strokeAttr}/>" .
                '</svg>';

        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';
        $css = ".{$cls} {";
        $css .= PenStyleBuilder::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= "}\n";

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Render a ref (component instance) by resolving through the converter.
     */
    public static function renderRef(array $node, PenConverter $converter): array
    {
        $refId = $node['ref'] ?? '';
        if ($refId === '') {
            return ['html' => '<!-- Missing ref -->', 'css' => ''];
        }

        if (!$converter->incrementRefDepth()) {
            return [
                'html' => "<!-- Max ref depth exceeded for: {$refId} -->",
                'css' => '',
            ];
        }

        $component = $converter->getComponent($refId);
        if ($component === null) {
            $converter->decrementRefDepth();
            return [
                'html' => "<!-- Component not found: {$refId} -->",
                'css' => '',
            ];
        }

        // Deep clone the component
        $resolved = json_decode(json_encode($component), true);

        // Apply root-level overrides from the ref node
        $skipKeys = ['type', 'ref', 'descendants', 'id', 'reusable'];
        foreach ($node as $key => $value) {
            if (!in_array($key, $skipKeys, true)) {
                $resolved[$key] = $value;
            }
        }

        // Assign the instance's own ID (so CSS classes are unique)
        $resolved['id'] = $node['id'] ?? $refId . '-inst';
        // Clear the reusable flag so it gets rendered
        $resolved['reusable'] = false;

        // Apply descendant overrides
        $descendants = $node['descendants'] ?? [];
        if (!empty($descendants)) {
            self::applyDescendants($resolved, $descendants);
        }

        // Render the resolved node
        $result = $converter->renderNode($resolved);
        $converter->decrementRefDepth();

        return $result;
    }

    /**
     * Render a group node.
     */
    public static function renderGroup(array $node, PenConverter $converter): array
    {
        if (!empty($node['reusable'])) {
            return ['html' => '', 'css' => ''];
        }

        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box;';
        if (isset($node['layout'])) {
            $css .= PenStyleBuilder::buildLayout($node);
        }
        $css .= PenStyleBuilder::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= PenStyleBuilder::buildOpacity($node['opacity'] ?? null);
        $css .= "}\n";

        // Render children
        $childrenResult = ['html' => '', 'css' => ''];
        if (!empty($node['children'])) {
            $prevLayout = $converter->getParentLayout();
            $converter->setParentLayout($node['layout'] ?? 'none');
            $childrenResult = $converter->renderChildren($node['children']);
            $converter->setParentLayout($prevLayout);
        }

        $html = "<div class=\"{$cls}\">{$childrenResult['html']}</div>";

        return [
            'html' => $html,
            'css' => $css . $childrenResult['css'],
        ];
    }

    /**
     * Render an icon_font node.
     */
    public static function renderIconFont(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $family = PenStyleBuilder::resolveValue($node['iconFontFamily'] ?? 'lucide');
        $name = PenStyleBuilder::resolveValue($node['iconFontName'] ?? '');
        $w = $node['width'] ?? 24;
        $h = $node['height'] ?? 24;
        $size = max((float)$w, (float)$h);

        // Register icon font import
        $cdnMap = [
            'lucide' => 'https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css',
            'feather' => 'https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.css',
            'Material Symbols Outlined' =>
                'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined',
            'Material Symbols Rounded' =>
                'https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded',
            'Material Symbols Sharp' =>
                'https://fonts.googleapis.com/css2?family=Material+Symbols+Sharp',
            'phosphor' =>
                'https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2/src/regular/style.css',
        ];
        if (isset($cdnMap[$family])) {
            $converter->addIconFontImport($family, $cdnMap[$family]);
        }

        // Build CSS
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';
        $fillCss = PenStyleBuilder::buildTextColor($node['fill'] ?? null);

        $css = ".{$cls} {";
        $css .= "font-size: {$size}px;";
        $css .= "width: {$w}px; height: {$h}px;";
        $css .= 'display: inline-flex; align-items: center; justify-content: center;';
        $css .= $fillCss;
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= "}\n";

        // Determine icon HTML based on font family
        if (str_starts_with($family, 'Material Symbols')) {
            $html = "<span class=\"{$cls} {$family}\">{$name}</span>";
        } elseif ($family === 'phosphor') {
            $html = "<i class=\"{$cls} ph ph-{$name}\"></i>";
        } elseif ($family === 'feather') {
            $html = "<i class=\"{$cls} feather icon-{$name}\"></i>";
        } else {
            // Default: lucide
            $html = "<i class=\"{$cls} icon-{$name}\"></i>";
        }

        return ['html' => $html, 'css' => $css];
    }

    // --- Private helpers ---

    private static function inferFrameTag(string $name): string
    {
        $lower = strtolower($name);
        foreach (self::SEMANTIC_TAG_MAP as $keyword => $tag) {
            if (str_contains($lower, $keyword)) {
                return $tag;
            }
        }
        return 'div';
    }

    private static function inferTextTag(array $node): string
    {
        if (isset($node['href'])) {
            return 'a';
        }
        $fontSize = $node['fontSize'] ?? null;
        if ($fontSize === null || !is_numeric($fontSize)) {
            return 'p';
        }
        $fontWeight = $node['fontWeight'] ?? '400';
        $weightNum = is_numeric($fontWeight) ? (int)$fontWeight : 400;

        foreach (self::HEADING_THRESHOLDS as $t) {
            if ((float)$fontSize >= $t['min']) {
                if (!empty($t['requireBold']) && $weightNum < 600) {
                    continue;
                }
                return $t['tag'];
            }
        }
        return 'p';
    }

    private static function renderTextContent(mixed $content): string
    {
        if ($content === null || $content === '') {
            return '';
        }
        if (is_string($content)) {
            // Variable reference → output as placeholder
            if (str_starts_with($content, '$')) {
                return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
            }
            return nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
        }
        // Array of styled text runs
        if (is_array($content)) {
            $html = '';
            foreach ($content as $run) {
                if (is_string($run)) {
                    $html .= htmlspecialchars($run, ENT_QUOTES, 'UTF-8');
                    continue;
                }
                if (!is_array($run)) {
                    continue;
                }
                $text = htmlspecialchars((string)($run['content'] ?? ''), ENT_QUOTES, 'UTF-8');
                $style = PenStyleBuilder::buildTypography($run);
                $colorCss = PenStyleBuilder::buildTextColor($run['fill'] ?? null);
                $allStyle = trim($style . $colorCss);

                if (isset($run['href'])) {
                    $href = htmlspecialchars($run['href'], ENT_QUOTES, 'UTF-8');
                    $html .= "<a href=\"{$href}\"" .
                             ($allStyle ? " style=\"{$allStyle}\"" : '') .
                             ">{$text}</a>";
                } else {
                    $html .= $allStyle
                        ? "<span style=\"{$allStyle}\">{$text}</span>"
                        : $text;
                }
            }
            return $html;
        }
        return '';
    }

    /**
     * Apply descendant overrides to a resolved component tree.
     */
    private static function applyDescendants(array &$node, array $descendants): void
    {
        foreach ($descendants as $path => $override) {
            $target = &self::findDescendant($node, explode('/', $path));
            if ($target === null) {
                continue;
            }
            // Full replacement if override has 'type'
            if (isset($override['type'])) {
                $target = $override;
            } else {
                // Property merge
                foreach ($override as $k => $v) {
                    $target[$k] = $v;
                }
            }
        }
    }

    /**
     * Find a descendant in the node tree by ID path parts.
     */
    private static function &findDescendant(array &$node, array $parts): ?array
    {
        $null = null;
        if (empty($parts)) {
            return $node;
        }

        $targetId = array_shift($parts);
        $children = &$node['children'] ?? [];

        if (!is_array($children)) {
            return $null;
        }

        for ($i = 0; $i < count($children); $i++) {
            if (($children[$i]['id'] ?? '') === $targetId) {
                if (empty($parts)) {
                    return $children[$i];
                }
                return self::findDescendant($children[$i], $parts);
            }
        }

        return $null;
    }

    /**
     * Extract fill color for SVG fill attribute.
     */
    private static function extractSvgFill(mixed $fill): string
    {
        if ($fill === null) {
            return 'none';
        }
        if (is_string($fill)) {
            return PenStyleBuilder::resolveColor($fill);
        }
        if (is_array($fill) && isset($fill['type']) && $fill['type'] === 'color') {
            return PenStyleBuilder::resolveColor($fill['color'] ?? 'none');
        }
        if (is_array($fill) && isset($fill[0])) {
            return self::extractSvgFill($fill[0]);
        }
        return 'none';
    }

    /**
     * Extract stroke attributes for SVG.
     */
    private static function extractSvgStroke(mixed $stroke): string
    {
        if (!is_array($stroke) || empty($stroke)) {
            return 'stroke="none"';
        }
        $color = 'none';
        $width = 0;
        if (isset($stroke['fill'])) {
            $color = PenStyleBuilder::resolveColor(
                is_string($stroke['fill']) ? $stroke['fill'] :
                ($stroke['fill']['color'] ?? 'none')
            );
        }
        if (isset($stroke['thickness'])) {
            $width = is_numeric($stroke['thickness'])
                ? (float)$stroke['thickness'] : 0;
        }
        return "stroke=\"{$color}\" stroke-width=\"{$width}\"";
    }

    /**
     * Generate SVG polygon points for a regular polygon.
     */
    private static function generatePolygonPoints(int $sides, float $w, float $h): string
    {
        $cx = $w / 2;
        $cy = $h / 2;
        $rx = $w / 2;
        $ry = $h / 2;
        $points = [];
        for ($i = 0; $i < $sides; $i++) {
            $angle = (2 * M_PI * $i / $sides) - (M_PI / 2); // start from top
            $x = round($cx + $rx * cos($angle), 2);
            $y = round($cy + $ry * sin($angle), 2);
            $points[] = "{$x},{$y}";
        }
        return implode(' ', $points);
    }
}
```

---

### `app/PageBuilder/PenConverter.php`

```php
<?php declare(strict_types=1);

namespace App\PageBuilder;

/**
 * Main .pen-to-HTML converter.
 * Reads a .pen JSON document, resolves components and variables,
 * and generates semantic HTML + CSS.
 */
class PenConverter
{
    private array $document;
    private array $components = [];
    private array $cssRules = [];
    private array $iconFontImports = [];
    private array $variables = [];
    private array $themes = [];
    private int $refDepth = 0;
    private string $parentLayout = 'none';

    private const MAX_REF_DEPTH = 10;

    private function __construct(array $document)
    {
        $this->document = $document;
        $this->variables = $document['variables'] ?? [];
        $this->themes = $document['themes'] ?? [];
        $this->buildComponentRegistry();
    }

    // --- Public static entry points ---

    /**
     * Convert a .pen file to HTML + CSS.
     *
     * @return array{html: string, css: string}
     */
    public static function convertFile(string $penPath): array
    {
        if (!file_exists($penPath)) {
            throw new \RuntimeException("PEN file not found: {$penPath}");
        }
        $contents = file_get_contents($penPath);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read PEN file: {$penPath}");
        }
        return self::convertJson($contents);
    }

    /**
     * Convert a .pen JSON string to HTML + CSS.
     *
     * @return array{html: string, css: string}
     */
    public static function convertJson(string $json): array
    {
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return self::convertDocument($document);
    }

    /**
     * Convert a pre-parsed .pen document array to HTML + CSS.
     *
     * @return array{html: string, css: string}
     */
    public static function convertDocument(array $document): array
    {
        if (!isset($document['children'])) {
            throw new \RuntimeException('Invalid .pen document: missing children');
        }
        $instance = new self($document);
        return $instance->convert();
    }

    // --- Public methods called by PenNodeRenderer ---

    public function getComponent(string $id): ?array
    {
        return $this->components[$id] ?? null;
    }

    public function getParentLayout(): string
    {
        return $this->parentLayout;
    }

    public function setParentLayout(string $layout): void
    {
        $this->parentLayout = $layout;
    }

    /**
     * Render a list of child nodes.
     * @return array{html: string, css: string}
     */
    public function renderChildren(array $children): array
    {
        $html = '';
        $css = '';
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $result = $this->renderNode($child);
            $html .= $result['html'];
            $css .= $result['css'];
        }
        return ['html' => $html, 'css' => $css];
    }

    /**
     * Render a single node, collecting CSS.
     * @return array{html: string, css: string}
     */
    public function renderNode(array $node): array
    {
        $result = PenNodeRenderer::renderNode($node, $this);
        if ($result['css'] !== '') {
            $this->cssRules[] = $result['css'];
        }
        return $result;
    }

    public function addIconFontImport(string $family, string $url): void
    {
        $this->iconFontImports[$family] = $url;
    }

    public function incrementRefDepth(): bool
    {
        $this->refDepth++;
        return $this->refDepth <= self::MAX_REF_DEPTH;
    }

    public function decrementRefDepth(): void
    {
        $this->refDepth = max(0, $this->refDepth - 1);
    }

    // --- Private methods ---

    /**
     * Run the full conversion pipeline.
     */
    private function convert(): array
    {
        $this->cssRules = [];
        $this->iconFontImports = [];

        // Render only non-reusable top-level children
        $html = '';
        foreach ($this->document['children'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            // Skip component definitions
            if (!empty($child['reusable'])) {
                continue;
            }
            $result = $this->renderNode($child);
            $html .= $result['html'];
        }

        // Assemble CSS
        $css = '';

        // Icon font imports
        foreach ($this->iconFontImports as $family => $url) {
            $css .= "@import url('{$url}');\n";
        }

        // Variable/theme CSS
        $varCss = $this->buildVariableCss();
        if ($varCss !== '') {
            $css .= $varCss . "\n";
        }

        // Reset base styles
        $css .= "/* PenConverter base */\n";
        $css .= "[class^=\"pen-\"] { box-sizing: border-box; }\n\n";

        // Collected node CSS rules
        $css .= implode("\n", $this->cssRules);

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Build component registry from document tree.
     */
    private function buildComponentRegistry(): void
    {
        $this->scanForComponents($this->document['children'] ?? []);
    }

    private function scanForComponents(array $children): void
    {
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (!empty($child['reusable'])) {
                $id = $child['id'] ?? '';
                if ($id !== '') {
                    $this->components[$id] = $child;
                }
            }
            // Recurse into children to find nested components
            if (!empty($child['children']) && is_array($child['children'])) {
                $this->scanForComponents($child['children']);
            }
        }
    }

    /**
     * Build CSS :root block and theme selectors from document variables.
     */
    private function buildVariableCss(): string
    {
        if (empty($this->variables)) {
            return '';
        }

        $rootVars = [];
        $themeVars = []; // ['dark' => ['--name' => 'value'], ...]

        foreach ($this->variables as $name => $def) {
            $type = $def['type'] ?? 'string';
            $value = $def['value'] ?? null;

            if ($value === null) {
                continue;
            }

            // Themed variable (array of {value, theme})
            if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                foreach ($value as $entry) {
                    $val = $entry['value'] ?? '';
                    $theme = $entry['theme'] ?? [];
                    $cssVal = self::formatVariableValue($type, $val);

                    if (empty($theme)) {
                        // Default/root
                        $rootVars["--{$name}"] = $cssVal;
                    } else {
                        // Theme-specific
                        $themeKey = self::buildThemeSelector($theme);
                        $themeVars[$themeKey]["--{$name}"] = $cssVal;
                    }
                }
            } else {
                // Non-themed variable
                $cssVal = self::formatVariableValue($type, $value);
                $rootVars["--{$name}"] = $cssVal;
            }
        }

        $css = '';
        if (!empty($rootVars)) {
            $css .= ":root {\n";
            foreach ($rootVars as $prop => $val) {
                $css .= "  {$prop}: {$val};\n";
            }
            $css .= "}\n";
        }

        foreach ($themeVars as $selector => $vars) {
            $css .= "{$selector} {\n";
            foreach ($vars as $prop => $val) {
                $css .= "  {$prop}: {$val};\n";
            }
            $css .= "}\n";
        }

        return $css;
    }

    /**
     * Format a variable value for CSS output.
     */
    private static function formatVariableValue(string $type, mixed $value): string
    {
        return match ($type) {
            'color' => (string) $value,
            'number' => (string) $value,
            'string' => (string) $value,
            'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    /**
     * Build a CSS selector for a theme combination.
     * E.g., ['mode' => 'dark'] → '[data-theme="dark"]'
     */
    private static function buildThemeSelector(array $theme): string
    {
        $selectors = [];
        foreach ($theme as $axis => $value) {
            $selectors[] = "[data-theme-{$axis}=\"{$value}\"]";
        }
        return implode('', $selectors);
    }
}
```

---

## Modifications to Existing Files

### `app/PageBuilder/PageRenderer.php` — Add import + method

Add to imports at top:
```php
use App\PageBuilder\PenConverter;
```

Add new static method (after existing methods):
```php
/**
 * Render a .pen design file to HTML + CSS.
 *
 * @param string $penFilePath Absolute path to the .pen file.
 * @return array{html: string, css: string}
 */
public static function renderFromPen(string $penFilePath): array
{
    return PenConverter::convertFile($penFilePath);
}
```

---

### `app/Admin/DesignController.php` — Add convert + preview methods

Add to imports:
```php
use App\PageBuilder\PenConverter;
```

Add two new public methods:

```php
/**
 * Convert a .pen file to HTML + CSS.
 * POST /admin/design/convert
 * Body: { "path": "filename.pen" } or { "json": "..." }
 */
public function convert(Request $request): Response
{
    try {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        if (isset($body['path'])) {
            $path = $this->sanitizePath($body['path']);
            if ($path === null) {
                return Response::json(['success' => false, 'error' => 'Invalid path'], 400);
            }
            $fullPath = $this->designsDir . DIRECTORY_SEPARATOR . $path;
            $result = PenConverter::convertFile($fullPath);
        } elseif (isset($body['json'])) {
            $result = PenConverter::convertJson($body['json']);
        } else {
            return Response::json(['success' => false, 'error' => 'Provide path or json'], 400);
        }

        return Response::json([
            'success' => true,
            'html' => $result['html'],
            'css' => $result['css'],
        ]);
    } catch (\Throwable $e) {
        return Response::json([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
}

/**
 * Preview a .pen file conversion as standalone HTML page.
 * GET /admin/design/preview?path=filename.pen
 */
public function preview(Request $request): Response
{
    try {
        $path = $this->sanitizePath($request->query('path', ''));
        if ($path === null) {
            return Response::html('<h1>Invalid path</h1>', 400);
        }

        $fullPath = $this->designsDir . DIRECTORY_SEPARATOR . $path;
        $result = PenConverter::convertFile($fullPath);

        $html = '<!DOCTYPE html><html lang="en"><head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>Preview: ' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '</title>';
        $html .= '<style>' . $result['css'] . '</style>';
        $html .= '</head><body>';
        $html .= $result['html'];
        $html .= '</body></html>';

        return Response::html($html);
    } catch (\Throwable $e) {
        return Response::html(
            '<h1>Conversion Error</h1><pre>' .
            htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') .
            '</pre>',
            500
        );
    }
}
```

---

### `app/Templates/FrontController.php` — Design file check

In the `page()` method, add before the element-mode rendering block (inside the "content found" branch, before `renderPublic`):

```php
// Design file rendering (column added in Chunk 7.4)
$designFile = $content['design_file'] ?? null;
if ($designFile !== null && trim($designFile) !== '') {
    $penPath = dirname(__DIR__, 2) . '/designs/' . $designFile;
    if (file_exists($penPath)) {
        $penResult = PageRenderer::renderFromPen($penPath);
        $body = $penResult['html'];
        $elementCss = ($elementCss ?? '') . "\n" . $penResult['css'];
    }
}
```

Add the same pattern in `blogPost()` and `renderContentHomepage()`. This code is inert until the `design_file` column exists (Chunk 7.4) — the `?? null` safely returns null.

---

### `public/index.php` — New routes

Add inside the `/admin` route group:

```php
$router->post('/admin/design/convert', [App\Admin\DesignController::class, 'convert']);
$router->get('/admin/design/preview', [App\Admin\DesignController::class, 'preview']);
```

---

## Acceptance Test Procedures

### `tests/chunk-7.2-verify.php`

The test script creates synthetic `.pen` JSON documents in memory and verifies conversion.

```
Test 1: PenStyleBuilder — resolveValue handles variables
  - Input: '$--primary' → Expected: 'var(--primary)'
  - Input: '$spacing-m' → Expected: 'var(--spacing-m)'
  - Input: 42 → Expected: '42'

Test 2: PenStyleBuilder — resolveColor handles hex formats
  - Input: '#AABBCCDD' → Expected: 'rgba(170, 187, 204, ...) '
  - Input: '#ABC' → Expected: '#AABBCC'
  - Input: '$--bg' → Expected: 'var(--bg)'

Test 3: PenStyleBuilder — buildFill converts colors and gradients
  - Color: '#FF0000' → Contains 'background-color: #FF0000'
  - Gradient: linear with stops → Contains 'linear-gradient'
  - Image: url + fill mode → Contains 'background-image' and 'cover'
  - Variable: '$--bg' → Contains 'var(--bg)'
  - Disabled fill (enabled: false) → Returns ''

Test 4: PenStyleBuilder — buildStroke converts borders
  - Uniform: {thickness: 2, fill: '#000'} → Contains 'border: 2px solid #000'
  - Per-side: {thickness: {top: 1, bottom: 2}} → Contains 'border-top' and 'border-bottom'

Test 5: PenStyleBuilder — buildEffects converts shadows and blurs
  - Shadow: {type: 'shadow', offset: {x:2,y:4}, blur: 8} → Contains 'box-shadow:'
  - Inner shadow → Contains 'inset'
  - Blur: {type: 'blur', radius: 10} → Contains 'filter: blur(10px)'
  - Background blur → Contains 'backdrop-filter'

Test 6: PenStyleBuilder — buildLayout converts flexbox
  - Horizontal → Contains 'flex-direction: row'
  - Vertical → Contains 'flex-direction: column'
  - Gap → Contains 'gap: 16px'
  - Padding array → Contains correct padding
  - Justify/align → Contains correct values

Test 7: PenStyleBuilder — buildTypography converts fonts
  - fontFamily, fontSize, fontWeight → Correct CSS output
  - Variable font family → Contains 'var(--...)'

Test 8: PenStyleBuilder — buildSizing handles fill_container/fit_content
  - fill_container → Contains 'flex: 1'
  - fill_container(200) → Contains fallback
  - fit_content → Contains 'fit-content'
  - Numeric → Contains '{n}px'

Test 9: PenNodeRenderer — renderFrame produces semantic HTML
  - Frame named "Header" → Produces <header> tag
  - Frame named "Footer" → Produces <footer> tag
  - Frame named "Main Content" → Produces <main> tag
  - Frame named "Card" → Produces <div> tag (default)

Test 10: PenNodeRenderer — renderText produces correct tags
  - fontSize: 32 → Produces <h1>
  - fontSize: 24 → Produces <h2>
  - fontSize: 14 → Produces <p>
  - With href → Produces <a>

Test 11: PenNodeRenderer — renderText handles content types
  - String content → Escaped HTML
  - Array of styled runs → <span> with inline styles
  - Empty → ''

Test 12: PenNodeRenderer — renderRectangle outputs styled div
  - With fill and stroke → CSS class with background and border

Test 13: PenNodeRenderer — renderEllipse outputs div with border-radius 50%
  - Contains 'border-radius: 50%'

Test 14: PenNodeRenderer — renderPath outputs SVG
  - Contains <svg> and <path> with geometry

Test 15: PenNodeRenderer — renderLine outputs hr
  - Contains <hr> with stroke-based border

Test 16: PenNodeRenderer — renderPolygon outputs SVG polygon
  - Contains <svg> and <polygon> with points

Test 17: PenNodeRenderer — renderRef resolves components
  - Create a reusable component + ref instance
  - Ref renders the component content (not empty)
  - Root overrides applied (e.g., overridden fill)

Test 18: PenNodeRenderer — renderRef applies descendant overrides
  - Component with child text node
  - Ref with descendants override on the text
  - Override applied in rendered output

Test 19: PenNodeRenderer — renderRef handles circular refs
  - Component that references itself → Does not infinite loop
  - Max depth comment rendered

Test 20: PenNodeRenderer — renderIconFont outputs icon markup
  - Lucide icon → <i class="icon-{name}">
  - Material icon → <span class="Material Symbols...">{name}</span>

Test 21: PenNodeRenderer — disabled nodes produce empty output
  - Node with enabled: false → Empty html and css

Test 22: PenConverter — convertDocument processes full document
  - Document with children → Non-empty html and css

Test 23: PenConverter — component registry built correctly
  - Document with reusable: true nodes → Components accessible via getComponent()
  - Non-reusable nodes → Not in registry

Test 24: PenConverter — variable CSS generated correctly
  - Document with variables → :root block with --name: value
  - Themed variables → [data-theme-...] blocks

Test 25: PenConverter — full integration test
  - Build a realistic .pen document with:
    - Reusable button component (frame + text)
    - Top-level frame with text, rectangle, and button ref instance
    - Variables for colors
  - Convert → HTML contains <div>, <p>, component text
  - CSS contains :root, .pen-{id} rules

Test 26: PenConverter — convertFile reads from disk
  - Write a temp .pen JSON file
  - convertFile() reads and converts it
  - Result has non-empty html and css

Test 27: PenConverter — convertFile throws on missing file
  - Non-existent path → RuntimeException

Test 28: PenConverter — icon font imports deduplicated
  - Multiple icon_font nodes with same family → Only one @import in CSS

Test 29: PageRenderer — renderFromPen method exists and delegates
  - PageRenderer::renderFromPen() calls PenConverter::convertFile()
  - Returns array with html and css keys

Test 30: DesignController — convert endpoint exists
  - Route /admin/design/convert is registered

Test 31: DesignController — preview endpoint exists
  - Route /admin/design/preview is registered

Test 32: FrontController — design_file check is safe when column missing
  - Content array without design_file key → No error, renders normally
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);`
- PSR-4 namespacing: `App\PageBuilder\PenConverter` → `app/PageBuilder/PenConverter.php`
- No framework imports — only native PHP
- All output escaping via `htmlspecialchars($string, ENT_QUOTES, 'UTF-8')`
- All methods that could throw document `@throws` in docblocks

### Edge Cases

1. **Empty documents**: A `.pen` file with `children: []` produces empty HTML and minimal CSS (just `:root` if variables exist).

2. **Missing properties**: Every property access uses `$node['prop'] ?? default`. Missing properties produce no CSS for that property.

3. **Deeply nested refs**: The `MAX_REF_DEPTH = 10` guard prevents infinite recursion. Components referencing themselves (directly or transitively) are caught.

4. **Large files**: The converter processes in a single pass (no multi-pass required). Memory usage scales with document size. A typical page design (100-500 nodes) should convert in <50ms.

5. **Variable references in nested contexts**: A variable like `$--primary` in a deeply nested node is resolved to `var(--primary)` at CSS generation time by PenStyleBuilder. The actual value resolution happens in the browser via the `:root` CSS block.

6. **8-digit hex colors**: `.pen` uses `#RRGGBBAA` (alpha in last two digits). CSS doesn't universally support this, so we convert to `rgba()`.

7. **SizingBehavior with fallback**: Strings like `fill_container(200)` need regex extraction of the fallback. The fallback is used as the flex-basis.

8. **Text fill vs background fill**: For `text` nodes, the `fill` property maps to CSS `color` (text color), not `background-color`. For all other node types, `fill` maps to `background-color`/`background`.

9. **Gradient rotation**: `.pen` gradients use counter-clockwise rotation from top. CSS `linear-gradient` uses clockwise from top. The conversion formula is: `css_deg = (360 - pen_deg + 180) % 360`.

10. **Per-side stroke thickness**: `.pen` supports `{top: 1, right: 0, bottom: 1, left: 0}` style per-side borders. Each side is rendered as a separate CSS `border-{side}` declaration.

### What This Chunk Does NOT Do
- No database migration (the `design_file` column comes in Chunk 7.4)
- No admin UI for linking designs to content (that's Chunk 7.5)
- No AI generation of .pen files (that's Chunk 7.4)
- No design system .pen file creation (that's Chunk 7.3)
- No theme switching UI (that's Chunk 7.6)

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `app/PageBuilder/PenStyleBuilder.php` | Class | Create |
| 2 | `app/PageBuilder/PenNodeRenderer.php` | Class | Create |
| 3 | `app/PageBuilder/PenConverter.php` | Class | Create |
| 4 | `app/PageBuilder/PageRenderer.php` | Class | Modify (add `renderFromPen`) |
| 5 | `app/Admin/DesignController.php` | Class | Modify (add `convert`, `preview`) |
| 6 | `app/Templates/FrontController.php` | Class | Modify (add design\_file check) |
| 7 | `public/index.php` | Routes | Modify (add 2 routes) |
| 8 | `tests/chunk-7.2-verify.php` | Test | Create |

---

## Estimated Scope

- **New PHP classes**: 3 (PenStyleBuilder, PenNodeRenderer, PenConverter)
- **Modified PHP files**: 4 (PageRenderer, DesignController, FrontController, index.php)
- **Test script**: 1 (32 tests)
- **Approximate new PHP LOC**: ~800–1000 lines across the 3 new classes
- **No new templates, no new migrations, no new CSS/JS**
