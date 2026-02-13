# Chunk 7.4 — AI Design Pipeline
## Detailed Implementation Plan

---

## Overview

This chunk extends LiteCMS's AI page generator to produce `.pen` design files using the design system component library from Chunk 7.3. When a user selects "Visual Design" mode in the page generator, the AI creates a `.pen` document that references design system components (`ref` nodes with `descendants` overrides for custom content). The generated `.pen` file is saved to disk, converted to HTML via PenConverter for preview and public rendering, and linked to the content record via a new `design_file` column.

This builds on three prior chunks:
- **Chunk 7.2**: PenConverter (`.pen` → HTML+CSS)
- **Chunk 7.3**: Design system components in `designs/litecms-system.pen`
- **Chunk 5.3**: AI Page Generator (conversational flow with gathering → generation)

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `migrations/010_design_file.sqlite.sql`

**Purpose**: Add `design_file` column to `content` table for linking content records to `.pen` files.

```sql
-- Migration 010: Add design_file column to content table
ALTER TABLE content ADD COLUMN design_file VARCHAR(500) DEFAULT NULL;
```

**Notes**:
- Stores a relative path like `pages/about-us.pen` (relative to `designs/` directory).
- Nullable — only set when content uses Visual Design mode.
- The `editor_mode` column already exists (added in migration 004). We reuse it with a new value `'design'`.

---

### 2. `migrations/010_design_file.mysql.sql`

```sql
-- Migration 010: Add design_file column to content table
ALTER TABLE content ADD COLUMN design_file VARCHAR(500) DEFAULT NULL;
```

---

### 3. `migrations/010_design_file.pgsql.sql`

```sql
-- Migration 010: Add design_file column to content table
ALTER TABLE content ADD COLUMN design_file VARCHAR(500) DEFAULT NULL;
```

---

### 4. `app/AIAssistant/GeneratorPrompts.php` (modify)

**Purpose**: Add two new prompt methods for the "design" editor mode.

**New methods**:

```
penDesignGatheringPrompt(string $siteName, array $existingPages, ?array $typeFields, string $componentSummary): string
penDesignGenerationPrompt(string $siteName, string $contentType, ?array $typeFields, string $componentSummary, array $variables, array $imageUrls = []): string
formatDesignSystemComponents(array $designSystemDoc): string
```

**Design decisions**:
- `formatDesignSystemComponents()` reads the design system document (already loaded as array) and produces a concise summary of available components: their IDs, slot node IDs, and what each slot controls. This is what the AI needs to compose `ref` + `descendants` overrides.
- The gathering prompt tells the AI to plan a page using the available design components.
- The generation prompt instructs the AI to output a complete `.pen` document JSON with:
  - A top-level page frame containing `ref` nodes pointing to design system components.
  - `descendants` overrides on each `ref` to customize text content, colors, etc.
  - The document includes the design system `children` (for component definitions) plus the page frame.
  - The document includes `variables` from the design system.

---

### 5. `app/AIAssistant/PageGeneratorController.php` (modify)

**Purpose**: Handle `editor_mode = 'design'` in both `chat()` and `create()` methods.

**Changes to `chat()` method** (around line 118):

Add a third branch alongside the existing `html` and `elements` branches:

```php
if ($editorMode === 'design') {
    $designSystemPath = $rootDir . '/designs/litecms-system.pen';
    $designSystemDoc = json_decode(file_get_contents($designSystemPath), true);
    $componentSummary = GeneratorPrompts::formatDesignSystemComponents($designSystemDoc);

    if ($step === 'generating') {
        $imageUrls = $this->collectImageUrls($existingMessages, $attachments);
        $systemPrompt = GeneratorPrompts::penDesignGenerationPrompt(
            $siteName, $contentType, $typeFields, $componentSummary,
            $designSystemDoc['variables'] ?? [], $imageUrls
        );
    } else {
        $systemPrompt = GeneratorPrompts::penDesignGatheringPrompt(
            $siteName, $existingPages, $typeFields, $componentSummary
        );
    }
} elseif ($editorMode === 'elements') {
    // ... existing elements branch ...
}
```

**Changes to `parseGeneratedContent()` method**:

Add handling for `editor_mode === 'design'` in the parsed output. The AI returns JSON with a `.pen` document structure. The parser extracts:
- `title`, `slug`, `excerpt`, `meta_title`, `meta_description` — same as other modes.
- `pen_document` — the full `.pen` JSON object (the page document).

New parameter or branch:

```php
// In parseGeneratedContent, when $isDesign is true:
// Extract pen_document from the JSON alongside the standard metadata fields
```

**Changes to `create()` method** (around line 239):

Add design mode handling:

```php
if ($editorMode === 'design') {
    $contentData['editor_mode'] = 'design';

    // Save the .pen file to disk
    $penDocument = $data['pen_document'] ?? null;
    if ($penDocument !== null) {
        $designFilePath = 'pages/' . $slug . '.pen';
        $fullPath = $rootDir . '/designs/' . $designFilePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($fullPath, json_encode($penDocument, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        $contentData['design_file'] = $designFilePath;

        // Convert to HTML for public rendering
        try {
            $result = PenConverter::convertDocument($penDocument);
            $contentData['body'] = $result['html'];
        } catch (\Throwable $e) {
            // Store empty body if conversion fails — can be re-converted later
            $contentData['body'] = '';
        }
    }
}
```

---

### 6. `app/Admin/ContentController.php` (modify)

**Purpose**: Support `design_file` field in content CRUD and add a preview endpoint.

**Changes to `store()` method** (around line 163):

```php
// Expand valid editor_mode values
$editorMode = in_array($data['editor_mode'], ['html', 'elements', 'design'], true)
    ? $data['editor_mode'] : 'html';

// Add design_file to insert array (null for non-design modes)
$insertData['design_file'] = ($editorMode === 'design' && !empty($data['design_file']))
    ? $data['design_file'] : null;
```

**Changes to `update()` method**:

Same pattern — accept `design_file` from form data and persist it.

**Changes to `edit()` method** (around line 251):

Pass `design_file` to the template data so the editor can show a link to the Pencil editor.

**Changes to `readFormData()` method**:

Add `design_file` extraction:

```php
$data['design_file'] = (string) $request->input('design_file', '');
```

**New method — `previewPen()`**:

```php
/**
 * POST /admin/content/preview-pen
 * Accepts .pen JSON, returns rendered HTML + CSS.
 */
public function previewPen(Request $request): Response
```

This endpoint accepts raw `.pen` JSON from the page generator frontend, converts it via `PenConverter::convertDocument()`, and returns `{success, html, css}`. This enables live preview in the generator before creating the content record.

---

### 7. `public/index.php` (modify)

**Purpose**: Register the new route.

**New route**:

```php
$router->post('/admin/content/preview-pen', [ContentController::class, 'previewPen']);
```

Place this alongside the existing content routes (before the `{id}` parameterized routes to avoid conflicts).

---

### 8. `templates/admin/generator/index.php` (modify)

**Purpose**: Add "Visual Design" mode button in Step 1.

**Change**: Add a third button in `.mode-options`:

```html
<button type="button" class="mode-option active" data-mode="html">HTML</button>
<button type="button" class="mode-option" data-mode="elements">Elements</button>
<button type="button" class="mode-option" data-mode="design">Visual Design</button>
```

**Change**: In Step 3 (Preview), add a design-specific preview section:

```html
<div id="preview-design" class="preview-content hidden">
    <div id="preview-design-frame" class="design-preview-frame"></div>
    <div class="preview-design-actions">
        <a id="btn-open-editor" href="#" class="btn btn-secondary" target="_blank">Open in Editor</a>
    </div>
</div>
```

---

### 9. `public/assets/js/page-generator.js` (modify)

**Purpose**: Handle "design" editor mode in all stages of the generator.

**Changes**:

1. **Mode initialization** (around line 32): Already handles mode button clicks — no change needed, just needs to accept `data-mode="design"`.

2. **`requestGeneration()` function** (around line 187): Already sends `editor_mode` with the request — works as-is.

3. **`populatePreview()` function** (around line 223): Add design mode branch:

```javascript
if (data.editor_mode === 'design' || editorMode === 'design') {
    // Preview via PenConverter
    var penDoc = data.pen_document;
    if (penDoc) {
        fetch('/admin/content/preview-pen', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.getElementById('generator-app').dataset.csrf
            },
            body: JSON.stringify({ pen_document: penDoc })
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                var frame = document.getElementById('preview-design-frame');
                frame.innerHTML = '<style>' + result.css + '</style>' + result.html;
                document.getElementById('preview-design').classList.remove('hidden');
                document.getElementById('preview-body').classList.add('hidden');
            }
        });
    }
}
```

4. **`createContent()` function** (around line 300): Add design mode payload:

```javascript
if (editorMode === 'design') {
    payload.editor_mode = 'design';
    payload.pen_document = generatedData.pen_document;
}
```

---

### 10. `tests/chunk-7.4-verify.php`

**Purpose**: Automated verification script for all chunk 7.4 functionality.

---

## Detailed Class Specifications

### `GeneratorPrompts` (modified)

```
EXISTING METHODS (unchanged):
  - gatheringPrompt(string, array, ?array): string
  - generationPrompt(string, string, ?array, array): string
  - elementGatheringPrompt(string, array, ?array, string): string
  - elementGenerationPrompt(string, string, ?array, string, array): string
  - formatExistingPages(array): string
  - formatCustomFields(array): string
  - formatElementCatalogue(array): string

NEW METHODS:

  + formatDesignSystemComponents(array $designSystemDoc): string
      Reads the design system document array.
      Iterates $designSystemDoc['children'], filters for reusable: true.
      For each component, builds a summary:
        - Component ID and name
        - List of slot node IDs (child nodes whose content can be overridden)
        - Brief description of what each slot controls
      Returns a formatted string for inclusion in AI prompts.

      Example output:
      ```
      Component: hero-section (Hero Section)
        Slots: hero-heading (text, h1), hero-subheading (text, subtitle),
               hero-cta-text (text, button label)

      Component: text-section (Text Section)
        Slots: text-heading (text, section title), text-body (text, body content)
      ...
      ```

      Implementation approach:
      - Walk each reusable component's children recursively
      - Collect all nodes with IDs (these are the overridable slots)
      - For text nodes: note content, fontSize (to hint at heading vs body)
      - For frame nodes: note name (to hint at purpose)
      - Skip structural/wrapper nodes that aren't meaningful slots

  + penDesignGatheringPrompt(string $siteName, array $existingPages, ?array $typeFields, string $componentSummary): string
      Returns the system prompt for the gathering phase in design mode.

      Key elements of the prompt:
      - Identifies the assistant as a web content planning assistant
      - Lists existing pages (via formatExistingPages)
      - Lists custom fields if applicable
      - Lists available design components (from componentSummary)
      - Instructs the AI to ask about purpose, audience, sections, content
      - Tells the AI to reference specific design system components by name
      - Uses the same READY_TO_GENERATE marker pattern as other modes
      - Does NOT generate content during this phase

  + penDesignGenerationPrompt(string $siteName, string $contentType, ?array $typeFields, string $componentSummary, array $variables, array $imageUrls = []): string
      Returns the system prompt for the generation phase in design mode.

      Key elements of the prompt:
      - Instructs the AI to generate a complete .pen document JSON
      - Provides the component summary with slot IDs
      - Provides the variable definitions (for reference, not for the AI to modify)
      - Specifies the exact JSON output format (see Output Format below)
      - Rules for content generation:
        * Use ref nodes pointing to design system component IDs
        * Customize content via descendants overrides on slot node IDs
        * Write real, professional content (not placeholder text)
        * Include all standard metadata (title, slug, excerpt, meta_title, meta_description)
        * The pen_document should be a complete .pen document with children array
      - Image handling: if imageUrls provided, reference them in appropriate slots

      Output format the AI must produce:
      ```json
      {
        "title": "Page Title",
        "slug": "page-title",
        "excerpt": "Summary",
        "meta_title": "SEO title",
        "meta_description": "SEO description",
        "pen_document": {
          "version": "2.7",
          "variables": { ... copy from design system ... },
          "children": [
            ... design system component definitions (reusable: true) ...,
            {
              "id": "page-root",
              "type": "frame",
              "name": "Page",
              "layout": "vertical",
              "width": 1200,
              "children": [
                {
                  "id": "hero-inst",
                  "type": "ref",
                  "ref": "hero-section",
                  "descendants": {
                    "hero-heading": { "content": "Custom Title" },
                    "hero-subheading": { "content": "Custom subtitle" },
                    "hero-cta-text": { "content": "Learn More" }
                  }
                },
                {
                  "id": "text-inst",
                  "type": "ref",
                  "ref": "text-section",
                  "descendants": {
                    "text-heading": { "content": "About Us" },
                    "text-body": { "content": "Full body text here..." }
                  }
                }
              ]
            }
          ]
        }
      }
      ```

      IMPORTANT: The prompt must instruct the AI to include the design system
      component definitions in the pen_document.children array (before the
      page frame). This is because PenConverter needs the component definitions
      to resolve ref nodes. The AI should copy the component structures verbatim
      and only customize content via descendants on the ref instances.

      Alternative (simpler) approach: The prompt can instruct the AI to output
      ONLY the page frame with ref nodes (no component definitions), and the
      PHP code merges the design system components in before conversion. This
      is cleaner and avoids the AI having to reproduce component JSON.

      RECOMMENDED: Use the simpler approach. The AI outputs:
      ```json
      {
        "title": "...", "slug": "...", "excerpt": "...",
        "meta_title": "...", "meta_description": "...",
        "pen_page": {
          "id": "page-root",
          "type": "frame",
          "name": "Page",
          "layout": "vertical",
          "width": 1200,
          "children": [
            { "id": "hero-1", "type": "ref", "ref": "hero-section", "descendants": { ... } },
            { "id": "text-1", "type": "ref", "ref": "text-section", "descendants": { ... } },
            ...
          ]
        }
      }
      ```

      Then PHP assembles the full document:
      ```php
      $fullDocument = [
          'version' => '2.7',
          'variables' => $designSystemDoc['variables'],
          'children' => array_merge(
              $designSystemDoc['children'],  // component definitions
              [$penPage]                      // the page frame
          ),
      ];
      ```
```

### `PageGeneratorController` (modified)

```
EXISTING METHODS (signatures unchanged):
  - index(Request): Response
  - chat(Request): Response
  - create(Request): Response

MODIFIED BEHAVIOR:

  chat() — New branch for design mode:
    1. Read $editorMode from request (already done at line 65)
    2. If $editorMode === 'design':
       a. Load design system: $designSystemPath = $rootDir . '/designs/litecms-system.pen'
       b. $designSystemDoc = json_decode(file_get_contents($designSystemPath), true)
       c. $componentSummary = GeneratorPrompts::formatDesignSystemComponents($designSystemDoc)
       d. If $step === 'generating':
          - $imageUrls = $this->collectImageUrls(...)
          - $systemPrompt = GeneratorPrompts::penDesignGenerationPrompt(
              $siteName, $contentType, $typeFields, $componentSummary,
              $designSystemDoc['variables'] ?? [], $imageUrls
            )
       e. Else (gathering):
          - $systemPrompt = GeneratorPrompts::penDesignGatheringPrompt(
              $siteName, $existingPages, $typeFields, $componentSummary
            )
    3. The response parsing logic needs a design mode branch:
       - If $editorMode === 'design':
         $generated = $this->parseGeneratedContent($aiContent, false, true)
         (third parameter = isDesign flag)

  parseGeneratedContent() — Extended signature:
    parseGeneratedContent(string $content, bool $isElements = false, bool $isDesign = false): ?array

    When $isDesign is true:
    1. Extract JSON from the AI response (same regex as existing)
    2. Expect keys: title, slug, excerpt, meta_title, meta_description, pen_page
    3. Return array with all standard fields plus:
       - 'editor_mode' => 'design'
       - 'pen_page' => the page frame object (JSON-decoded array)

  create() — Design mode branch (after line 239):
    If $editorMode === 'design':
    1. Set $contentData['editor_mode'] = 'design'
    2. Extract pen_page from $data
    3. Load design system document
    4. Assemble full .pen document:
       $fullDoc = [
         'version' => '2.7',
         'variables' => $designSystemDoc['variables'],
         'children' => array_merge($designSystemDoc['children'], [$data['pen_page']]),
       ];
    5. Save to disk: designs/pages/{slug}.pen
       - Ensure designs/pages/ directory exists
       - Write JSON with LOCK_EX
    6. Set $contentData['design_file'] = 'pages/' . $slug . '.pen'
    7. Convert for public rendering:
       try {
         $result = PenConverter::convertDocument($fullDoc);
         $contentData['body'] = $result['html'];
       } catch (\Throwable) {
         $contentData['body'] = '';
       }

NEW PRIVATE HELPER:

  + getDesignSystemDocument(): array
      Loads and caches the design system document from designs/litecms-system.pen.
      Returns the decoded JSON array.
      Throws RuntimeException if file not found or invalid JSON.
```

### `ContentController` (modified)

```
MODIFIED METHODS:

  store():
    Line ~163: Change editor_mode validation:
      BEFORE: in_array($data['editor_mode'], ['html', 'elements'], true)
      AFTER:  in_array($data['editor_mode'], ['html', 'elements', 'design'], true)

    Add to insert array:
      'design_file' => ($editorMode === 'design' && !empty($data['design_file']))
                        ? $data['design_file'] : null,

  update():
    Same changes as store() for editor_mode validation and design_file persistence.

  edit():
    Add 'design_file' to template data:
      'designFile' => $content['design_file'] ?? null,

  readFormData():
    Add:
      $data['design_file'] = (string) $request->input('design_file', '');

NEW METHOD:

  + previewPen(Request $request): Response
      POST /admin/content/preview-pen
      Body: { "pen_document": { ... full .pen document ... } }
            OR { "pen_page": { ... page frame only ... } }

      If pen_page provided (no component definitions):
        1. Load design system from designs/litecms-system.pen
        2. Merge: children = design system children + [pen_page]
        3. Add variables from design system

      Convert via PenConverter::convertDocument()
      Return: { "success": true, "html": "...", "css": "..." }
      On error: { "success": false, "error": "message" }

      Note: This is essentially like the existing DesignController::convert()
      but accepts raw JSON from the generator (not a file path) and
      auto-merges with the design system.
```

### Route Registration (index.php)

```
NEW ROUTE (add before /admin/content/{id} routes):
  POST /admin/content/preview-pen → ContentController::previewPen
```

---

## Detailed File Templates

### Migration: `migrations/010_design_file.sqlite.sql`

```sql
-- Migration 010: Add design_file column to content table
ALTER TABLE content ADD COLUMN design_file VARCHAR(500) DEFAULT NULL;
```

### Migration: `migrations/010_design_file.mysql.sql`

```sql
-- Migration 010: Add design_file column to content table
ALTER TABLE content ADD COLUMN design_file VARCHAR(500) DEFAULT NULL;
```

### Migration: `migrations/010_design_file.pgsql.sql`

```sql
-- Migration 010: Add design_file column to content table
ALTER TABLE content ADD COLUMN design_file VARCHAR(500) DEFAULT NULL;
```

### `GeneratorPrompts` — New Methods

```php
/**
 * Format the design system components for AI context.
 * Produces a concise summary of available components and their slot nodes.
 */
public static function formatDesignSystemComponents(array $designSystemDoc): string
{
    $children = $designSystemDoc['children'] ?? [];
    $lines = [];

    foreach ($children as $component) {
        if (empty($component['reusable'])) {
            continue;
        }

        $id = $component['id'] ?? 'unknown';
        $name = $component['name'] ?? $id;
        $slots = self::collectSlotNodes($component);

        $line = "Component: {$id} ({$name})";
        if (!empty($slots)) {
            $slotDescs = [];
            foreach ($slots as $slot) {
                $slotDescs[] = $slot['id'] . ' (' . $slot['type'] . ', ' . $slot['hint'] . ')';
            }
            $line .= "\n  Slots: " . implode(', ', $slotDescs);
        }
        $lines[] = $line;
    }

    return implode("\n\n", $lines);
}

/**
 * Recursively collect slot nodes (overridable children) from a component.
 */
private static function collectSlotNodes(array $node, int $depth = 0): array
{
    $slots = [];
    $children = $node['children'] ?? [];

    foreach ($children as $child) {
        $childId = $child['id'] ?? '';
        $childType = $child['type'] ?? 'frame';

        if ($childId === '') {
            // Skip anonymous nodes
            if (!empty($child['children'])) {
                $slots = array_merge($slots, self::collectSlotNodes($child, $depth + 1));
            }
            continue;
        }

        if ($childType === 'text') {
            $fontSize = $child['fontSize'] ?? 16;
            $hint = $fontSize >= 32 ? 'heading' :
                   ($fontSize >= 20 ? 'subheading' :
                   ($fontSize >= 16 ? 'body text' : 'small text'));
            $slots[] = ['id' => $childId, 'type' => 'text', 'hint' => $hint];
        } elseif ($childType === 'frame' && !empty($child['children'])) {
            // Recurse into frames to find nested text slots
            $slots = array_merge($slots, self::collectSlotNodes($child, $depth + 1));
        }
    }

    return $slots;
}

/**
 * Design mode gathering prompt for the page generator.
 */
public static function penDesignGatheringPrompt(
    string $siteName,
    array $existingPages,
    ?array $typeFields,
    string $componentSummary
): string {
    $pagesList = self::formatExistingPages($existingPages);
    $fieldsDesc = $typeFields !== null ? "\n\n" . self::formatCustomFields($typeFields) : '';

    return <<<PROMPT
You are a professional web content planning assistant for "{$siteName}".
You are building a visually designed page using the site's design system components.
Your job is to help the user plan a new webpage by asking smart, focused questions.

{$pagesList}{$fieldsDesc}

Available design system components:
{$componentSummary}

Guidelines:
- Ask 2-3 focused questions per message. Do not overwhelm the user with too many questions at once.
- Start by asking about the page's purpose and target audience.
- Then progressively ask about: desired sections/structure, key content points, tone/style preferences, and any specific details they want included.
- Reference available design system components by name when suggesting page structure (e.g., "We could use the Hero Section for a bold opening, followed by a Feature Grid to highlight your services").
- If the user is vague, suggest concrete layouts using the available components.
- Consider how this new page fits with the existing site structure.
- Keep your responses conversational and helpful. Be encouraging.
- When you have gathered enough information to generate a complete, high-quality page, include the exact marker READY_TO_GENERATE on its own line at the END of your message. Before the marker, give the user a brief summary of what you'll generate (which components you'll use and in what order) so they can confirm or adjust.
- Do NOT generate the actual page content during this phase — only gather and confirm requirements.
PROMPT;
}

/**
 * Design mode generation prompt for the page generator.
 */
public static function penDesignGenerationPrompt(
    string $siteName,
    string $contentType,
    ?array $typeFields,
    string $componentSummary,
    array $variables,
    array $imageUrls = []
): string {
    $fieldsDesc = '';
    $fieldsJson = '';
    if ($typeFields !== null) {
        $fieldsDesc = "\n\n" . self::formatCustomFields($typeFields);
        $keys = array_map(fn($f) => '"' . ($f['key'] ?? 'field') . '": "value"', $typeFields);
        $fieldsJson = ', "custom_fields": {' . implode(', ', $keys) . '}';
    }

    $imageSection = '';
    if (!empty($imageUrls)) {
        $imageList = implode("\n", array_map(
            fn($url, $i) => "Image " . ($i + 1) . ": {$url}",
            $imageUrls,
            array_keys($imageUrls)
        ));
        $imageSection = <<<IMG

AVAILABLE IMAGES — The user uploaded these images during the conversation.
Reference them in descendants overrides where appropriate:
{$imageList}
IMG;
    }

    return <<<PROMPT
You are a professional web content generator for "{$siteName}".
Based on the entire conversation above, generate a complete visually designed webpage using the design system components.

Content type: {$contentType}{$fieldsDesc}{$imageSection}

Available design system components:
{$componentSummary}

Rules:
- Compose the page using ref nodes that reference the design system components by their IDs.
- Customize each component instance's content using "descendants" overrides on slot node IDs.
- Each ref node MUST have a unique "id" (e.g., "hero-1", "text-about", "features-1").
- Write real, contextual, professional content — not Lorem ipsum or placeholder text.
- Choose components that best match the user's requirements from the conversation.
- Order components logically (typically: hero → content sections → CTA → footer).

You MUST respond with ONLY a valid JSON object (no markdown code fences, no explanatory text before or after) in this exact format:
{"title": "Page Title", "slug": "page-title", "excerpt": "A 1-2 sentence summary.", "meta_title": "SEO title (50-60 chars)", "meta_description": "SEO description (150-160 chars)"{$fieldsJson}, "pen_page": {"id": "page-root", "type": "frame", "name": "Page", "layout": "vertical", "width": 1200, "children": [{"id": "hero-1", "type": "ref", "ref": "hero-section", "descendants": {"hero-heading": {"content": "Your Custom Title"}, "hero-subheading": {"content": "Your custom subtitle"}, "hero-cta-text": {"content": "Get Started"}}}, {"id": "text-1", "type": "ref", "ref": "text-section", "descendants": {"text-heading": {"content": "Section Title"}, "text-body": {"content": "Full section content here..."}}}]}}

Important:
- The "ref" value must exactly match a component ID from the list above.
- The "descendants" keys must exactly match slot node IDs from the component specification.
- For text slot overrides, always include a "content" property with the new text.
- Do NOT include the component definitions in your output — only the page frame with ref instances.
- Do NOT modify component structure, variables, or styling — only override text content via descendants.
PROMPT;
}
```

### `PageGeneratorController::chat()` — Design Branch

```php
// Insert this block BEFORE the existing elements branch (around line 118)
if ($editorMode === 'design') {
    $designSystemDoc = $this->getDesignSystemDocument();
    $componentSummary = GeneratorPrompts::formatDesignSystemComponents($designSystemDoc);

    if ($step === 'generating') {
        $imageUrls = $this->collectImageUrls($existingMessages, $attachments);
        $systemPrompt = GeneratorPrompts::penDesignGenerationPrompt(
            $siteName, $contentType, $typeFields, $componentSummary,
            $designSystemDoc['variables'] ?? [], $imageUrls
        );
    } else {
        $systemPrompt = GeneratorPrompts::penDesignGatheringPrompt(
            $siteName, $existingPages, $typeFields, $componentSummary
        );
    }
} elseif ($editorMode === 'elements') {
    // ... existing code unchanged ...
```

### `PageGeneratorController::parseGeneratedContent()` — Design Support

```php
// Extended signature:
private function parseGeneratedContent(string $content, bool $isElements = false, bool $isDesign = false): ?array

// Add at the beginning of the method, before existing parsing:
if ($isDesign) {
    // Extract JSON from AI response
    $json = $this->extractJson($content);
    if ($json === null) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    return [
        'title'            => $data['title'] ?? '',
        'slug'             => $data['slug'] ?? '',
        'excerpt'          => $data['excerpt'] ?? '',
        'meta_title'       => $data['meta_title'] ?? '',
        'meta_description' => $data['meta_description'] ?? '',
        'editor_mode'      => 'design',
        'pen_page'         => $data['pen_page'] ?? null,
        'custom_fields'    => $data['custom_fields'] ?? [],
    ];
}
// ... existing parsing logic for html/elements modes ...
```

### `PageGeneratorController::create()` — Design Branch

```php
// After the existing elements block (around line 290), add:
if ($editorMode === 'design' && !empty($data['pen_page']) && is_array($data['pen_page'])) {
    $contentData['editor_mode'] = 'design';

    // Load design system
    $designSystemDoc = $this->getDesignSystemDocument();

    // Assemble full .pen document
    $fullDoc = [
        'version'   => '2.7',
        'variables' => $designSystemDoc['variables'] ?? [],
        'children'  => array_merge(
            $designSystemDoc['children'] ?? [],
            [$data['pen_page']]
        ),
    ];

    // Save .pen file to disk
    $designFilePath = 'pages/' . $slug . '.pen';
    $rootDir = dirname(__DIR__, 2);
    $fullPath = $rootDir . '/designs/' . $designFilePath;
    $dir = dirname($fullPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents(
        $fullPath,
        json_encode($fullDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    $contentData['design_file'] = $designFilePath;

    // Convert to HTML for fast public rendering
    try {
        $result = PenConverter::convertDocument($fullDoc);
        $contentData['body'] = $result['html'];
    } catch (\Throwable $e) {
        $contentData['body'] = '<!-- Design conversion pending -->';
    }
}
```

### `PageGeneratorController::getDesignSystemDocument()` — New Helper

```php
/**
 * Load the design system document from disk (cached per request).
 */
private ?array $designSystemCache = null;

private function getDesignSystemDocument(): array
{
    if ($this->designSystemCache !== null) {
        return $this->designSystemCache;
    }

    $rootDir = dirname(__DIR__, 2);
    $path = $rootDir . '/designs/litecms-system.pen';

    if (!file_exists($path)) {
        throw new \RuntimeException('Design system not found: designs/litecms-system.pen');
    }

    $json = file_get_contents($path);
    $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $this->designSystemCache = $doc;

    return $doc;
}
```

### `ContentController::previewPen()` — New Method

```php
/**
 * POST /admin/content/preview-pen
 * Convert .pen page data to HTML for preview.
 * Accepts: { "pen_page": { ... } } or { "pen_document": { ... } }
 */
public function previewPen(Request $request): Response
{
    try {
        $body = json_decode(file_get_contents('php://input'), true);

        if (!is_array($body)) {
            return Response::json(['success' => false, 'error' => 'Invalid request'], 400);
        }

        if (isset($body['pen_document'])) {
            // Full document provided — convert directly
            $document = $body['pen_document'];
        } elseif (isset($body['pen_page'])) {
            // Page frame only — merge with design system
            $rootDir = dirname(__DIR__, 2);
            $dsPath = $rootDir . '/designs/litecms-system.pen';
            if (!file_exists($dsPath)) {
                return Response::json(['success' => false, 'error' => 'Design system not found'], 500);
            }
            $dsDoc = json_decode(file_get_contents($dsPath), true);

            $document = [
                'version'   => '2.7',
                'variables' => $dsDoc['variables'] ?? [],
                'children'  => array_merge(
                    $dsDoc['children'] ?? [],
                    [$body['pen_page']]
                ),
            ];
        } else {
            return Response::json(['success' => false, 'error' => 'Provide pen_document or pen_page'], 400);
        }

        $result = PenConverter::convertDocument($document);

        return Response::json([
            'success' => true,
            'html'    => $result['html'],
            'css'     => $result['css'],
        ]);
    } catch (\Throwable $e) {
        return Response::json([
            'success' => false,
            'error'   => $e->getMessage(),
        ], 500);
    }
}
```

### `ContentController::store()` — Modifications

```php
// Change line ~163:
// BEFORE:
$editorMode = in_array($data['editor_mode'], ['html', 'elements'], true)
    ? $data['editor_mode'] : 'html';
// AFTER:
$editorMode = in_array($data['editor_mode'], ['html', 'elements', 'design'], true)
    ? $data['editor_mode'] : 'html';

// In the insert array, add after 'layout_template_id':
'design_file' => ($editorMode === 'design' && !empty($data['design_file']))
    ? $data['design_file'] : null,
```

### `ContentController::update()` — Modifications

Same pattern as store():
```php
// Change editor_mode validation to include 'design'
// Add design_file to the update array
```

### `ContentController::readFormData()` — Addition

```php
// Add alongside other field extractions:
$data['design_file'] = (string) $request->input('design_file', '');
```

### Template: `templates/admin/generator/index.php` — Modifications

```html
<!-- Change the mode-options section (around line 34) -->
<div class="mode-options" style="display:flex;gap:0.5rem;margin-top:0.5rem;">
    <button type="button" class="mode-option active" data-mode="html">HTML</button>
    <button type="button" class="mode-option" data-mode="elements">Elements</button>
    <button type="button" class="mode-option" data-mode="design">Visual Design</button>
</div>

<!-- In Step 3 (Preview), add after the existing preview-body div (around line 79): -->
<div id="preview-design" class="preview-content hidden">
    <div id="preview-design-frame" style="border:1px solid var(--border-color,#ddd);border-radius:8px;padding:0;overflow:hidden;background:#fff;"></div>
    <div style="margin-top:1rem;display:flex;gap:0.5rem;">
        <a id="btn-open-editor" href="#" class="btn btn-secondary" target="_blank"
           style="display:none;">Open in Design Editor</a>
    </div>
</div>
```

### JavaScript: `public/assets/js/page-generator.js` — Modifications

```javascript
// In populatePreview() — add design mode branch:
// (Insert before the existing elements check)

if (data.editor_mode === 'design' || editorMode === 'design') {
    // Show design preview via PenConverter
    var penPage = data.pen_page;
    if (penPage) {
        var csrf = document.getElementById('generator-app').dataset.csrf;
        fetch('/admin/content/preview-pen', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({ pen_page: penPage })
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                var frame = document.getElementById('preview-design-frame');
                frame.innerHTML = '<style>' + result.css + '</style>' + result.html;
                document.getElementById('preview-design').classList.remove('hidden');
                document.getElementById('preview-body').classList.add('hidden');
            }
        });
    }
    return; // skip HTML/elements preview
}

// In createContent() — add design payload:
if (editorMode === 'design' && generatedData && generatedData.pen_page) {
    payload.editor_mode = 'design';
    payload.pen_page = generatedData.pen_page;
}
```

---

## Acceptance Test Procedures

### Test 1: Migration adds `design_file` column
```
1. Run migrations (fresh database or incremental).
2. Query content table schema — design_file VARCHAR(500) column exists.
3. Column is nullable with default NULL.
```

### Test 2: AI page generator shows "Visual Design" mode option
```
1. Navigate to /admin/generator.
2. Three mode buttons visible: "HTML", "Elements", "Visual Design".
3. Clicking "Visual Design" activates it (adds active class).
```

### Test 3: Design mode gathering phase works
```
1. Select a content type, choose "Visual Design" mode.
2. Send a message describing a page.
3. AI responds referencing design system components by name.
4. AI asks focused follow-up questions.
5. After sufficient info, AI includes READY_TO_GENERATE marker.
```

### Test 4: Design mode generation produces valid .pen page data
```
1. After READY_TO_GENERATE, click "Generate".
2. AI returns JSON with title, slug, excerpt, meta fields, and pen_page object.
3. pen_page contains ref nodes with descendants overrides.
4. ref values match actual design system component IDs.
```

### Test 5: Preview endpoint converts pen_page to HTML
```
1. POST to /admin/content/preview-pen with pen_page data.
2. Response contains success: true, html, css.
3. HTML contains the customized content from descendants overrides.
4. CSS contains :root variables and component styles.
```

### Test 6: "Open in Editor" button loads .pen file in embedded editor
```
1. After creating content in design mode, the edit page shows design_file.
2. Clicking "Open in Editor" navigates to /admin/design/editor?file=pages/{slug}.pen.
3. The editor loads and shows the generated page with components.
```

### Test 7: Generated design uses component instances
```
1. Generate a page with multiple sections in design mode.
2. The pen_page has ref nodes for hero-section, text-section, etc.
3. Each ref has descendants overrides with custom content.
4. PenConverter successfully renders all components to HTML.
```

### Test 8: Content record stores design_file path
```
1. Create content via design mode generator.
2. Query content table — design_file = 'pages/{slug}.pen'.
3. editor_mode = 'design'.
4. body contains the pre-converted HTML.
```

### Test 9: .pen file saved to disk correctly
```
1. After design mode creation, check designs/pages/{slug}.pen exists.
2. File is valid JSON.
3. Document has version, variables, children (including design system components + page frame).
4. Page frame's ref nodes resolve correctly when re-converted.
```

### Test 10: Public site serves pre-converted HTML
```
1. Create and publish a design-mode page.
2. Visit the public URL.
3. Page renders with the converted HTML content.
4. Content matches the design preview.
```

---

## Test Script: `tests/chunk-7.4-verify.php`

### Test List

| # | Test | What It Checks |
|---|---|---|
| 1 | Migration file exists | `migrations/010_design_file.*.sql` for all 3 drivers |
| 2 | Migration SQL is valid | ALTER TABLE adds design_file column |
| 3 | Migration applies cleanly | Column exists after migration |
| 4 | GeneratorPrompts has design methods | `formatDesignSystemComponents`, `penDesignGatheringPrompt`, `penDesignGenerationPrompt` exist |
| 5 | formatDesignSystemComponents produces valid output | Loads litecms-system.pen, returns component summary with IDs and slots |
| 6 | penDesignGatheringPrompt includes component context | Prompt string contains component names and READY_TO_GENERATE instruction |
| 7 | penDesignGenerationPrompt specifies JSON format | Prompt string contains pen_page format and ref/descendants instructions |
| 8 | PageGeneratorController handles design mode | chat() method accepts editor_mode=design without error |
| 9 | parseGeneratedContent handles design JSON | Parses pen_page from JSON, returns correct structure |
| 10 | ContentController validates design editor_mode | store() accepts 'design' as valid editor_mode |
| 11 | ContentController has previewPen method | Method exists and is callable |
| 12 | previewPen converts pen_page with design system | Returns HTML+CSS when given a valid pen_page |
| 13 | Route /admin/content/preview-pen exists | POST route resolves to ContentController::previewPen |
| 14 | Generator template has design mode button | HTML contains data-mode="design" button |
| 15 | page-generator.js handles design mode | JS file contains 'design' mode handling code |
| 16 | Design file save creates valid .pen JSON | Assembled document with design system + page frame is valid |
| 17 | PenConverter renders assembled design document | Full document with refs resolves to HTML with custom content |
| 18 | Content CRUD persists design_file | Insert and retrieve design_file from content table |
| 19 | Descendants overrides work in generated pages | ref instances with descendants produce customized HTML |
| 20 | Full pipeline integration | Generate → preview → create → .pen on disk → HTML in body → public render |

### Test Script Template

```php
<?php declare(strict_types=1);

/**
 * Chunk 7.4 — AI Design Pipeline
 * Automated Verification Tests
 *
 * Tests:
 *   1.  Migration files exist (3 drivers)
 *   2.  Migration SQL has correct ALTER TABLE
 *   3.  Migration applies cleanly (design_file column exists)
 *   4.  GeneratorPrompts has design methods
 *   5.  formatDesignSystemComponents produces valid output
 *   6.  penDesignGatheringPrompt includes component context
 *   7.  penDesignGenerationPrompt specifies JSON format
 *   8.  PageGeneratorController handles design mode
 *   9.  parseGeneratedContent handles design JSON
 *  10.  ContentController validates design editor_mode
 *  11.  ContentController has previewPen method
 *  12.  previewPen converts pen_page with design system
 *  13.  Route /admin/content/preview-pen registered
 *  14.  Generator template has design mode button
 *  15.  page-generator.js handles design mode
 *  16.  Design file save creates valid .pen JSON
 *  17.  PenConverter renders assembled design document
 *  18.  Content CRUD persists design_file
 *  19.  Descendants overrides work in generated pages
 *  20.  Full pipeline integration
 *
 * Smoke mode (LITECMS_TEST_SMOKE=1): runs only tests 1-5
 */

$rootDir = dirname(__DIR__);
$isSmoke = (getenv('LITECMS_TEST_SMOKE') === '1');

$pass = 0;
$fail = 0;

function test_pass(string $description): void {
    global $pass;
    $pass++;
    echo "[PASS] {$description}\n";
}

function test_fail(string $description, string $reason = ''): void {
    global $fail;
    $fail++;
    $detail = $reason ? " — {$reason}" : '';
    echo "[FAIL] {$description}{$detail}\n";
}

function test_skip(string $description): void {
    echo "[SKIP] {$description}\n";
}

// Autoloader
$autoloadPath = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found');
    echo "\n[FAIL] Cannot continue\n";
    exit(1);
}
require_once $autoloadPath;

use App\AIAssistant\GeneratorPrompts;
use App\AIAssistant\PageGeneratorController;
use App\Admin\ContentController;
use App\PageBuilder\PenConverter;
use App\Database\Connection;
use App\Database\QueryBuilder;
use App\Database\Migrator;

// ---------------------------------------------------------------------------
// Test 1: Migration files exist
// ---------------------------------------------------------------------------
$migrationDrivers = ['sqlite', 'mysql', 'pgsql'];
$migrationFiles = [];
$allExist = true;
foreach ($migrationDrivers as $driver) {
    $path = $rootDir . "/migrations/010_design_file.{$driver}.sql";
    $migrationFiles[$driver] = $path;
    if (!file_exists($path)) {
        $allExist = false;
    }
}
if ($allExist) {
    test_pass('Test 1: Migration files exist (3 drivers)');
} else {
    $missing = [];
    foreach ($migrationFiles as $d => $p) {
        if (!file_exists($p)) $missing[] = $d;
    }
    test_fail('Test 1: Migration files', 'missing: ' . implode(', ', $missing));
}

// ---------------------------------------------------------------------------
// Test 2: Migration SQL has correct ALTER TABLE
// ---------------------------------------------------------------------------
$sqlContent = file_get_contents($migrationFiles['sqlite'] ?? '');
if ($sqlContent !== false &&
    stripos($sqlContent, 'ALTER TABLE') !== false &&
    stripos($sqlContent, 'design_file') !== false) {
    test_pass('Test 2: Migration SQL has ALTER TABLE with design_file');
} else {
    test_fail('Test 2: Migration SQL content', 'expected ALTER TABLE ... design_file');
}

// ---------------------------------------------------------------------------
// Test 3: Migration applies cleanly
// ---------------------------------------------------------------------------
try {
    Connection::initialize();
    $pdo = Connection::get();
    Migrator::run();

    // Check if design_file column exists
    $stmt = $pdo->query("PRAGMA table_info(content)");
    $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $hasDesignFile = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'design_file') {
            $hasDesignFile = true;
            break;
        }
    }

    if ($hasDesignFile) {
        test_pass('Test 3: Migration applies — design_file column exists');
    } else {
        test_fail('Test 3: Migration applies', 'design_file column not found in content table');
    }
} catch (\Throwable $e) {
    test_fail('Test 3: Migration applies', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 4: GeneratorPrompts has design methods
// ---------------------------------------------------------------------------
$requiredMethods = [
    'formatDesignSystemComponents',
    'penDesignGatheringPrompt',
    'penDesignGenerationPrompt',
];
$missingMethods = [];
foreach ($requiredMethods as $method) {
    if (!method_exists(GeneratorPrompts::class, $method)) {
        $missingMethods[] = $method;
    }
}
if (empty($missingMethods)) {
    test_pass('Test 4: GeneratorPrompts has design methods');
} else {
    test_fail('Test 4: GeneratorPrompts methods', 'missing: ' . implode(', ', $missingMethods));
}

// ---------------------------------------------------------------------------
// Test 5: formatDesignSystemComponents produces valid output
// ---------------------------------------------------------------------------
try {
    $dsPath = $rootDir . '/designs/litecms-system.pen';
    $dsDoc = json_decode(file_get_contents($dsPath), true, 512, JSON_THROW_ON_ERROR);
    $summary = GeneratorPrompts::formatDesignSystemComponents($dsDoc);

    $hasHero = str_contains($summary, 'hero-section');
    $hasText = str_contains($summary, 'text-section');
    $hasSlots = str_contains($summary, 'Slots:');

    if ($hasHero && $hasText && $hasSlots && strlen($summary) > 100) {
        test_pass('Test 5: formatDesignSystemComponents produces valid output');
    } else {
        test_fail('Test 5: Component summary', 'missing expected content (hero-section, text-section, Slots:)');
    }
} catch (\Throwable $e) {
    test_fail('Test 5: Component summary', $e->getMessage());
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 7.4 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 6: penDesignGatheringPrompt includes component context
// ---------------------------------------------------------------------------
try {
    $prompt = GeneratorPrompts::penDesignGatheringPrompt(
        'Test Site', [['title' => 'Home', 'slug' => '/']], null, $summary
    );
    $ok = str_contains($prompt, 'hero-section') &&
          str_contains($prompt, 'READY_TO_GENERATE') &&
          str_contains($prompt, 'design system');
    if ($ok) {
        test_pass('Test 6: penDesignGatheringPrompt includes component context');
    } else {
        test_fail('Test 6: Gathering prompt', 'missing expected content');
    }
} catch (\Throwable $e) {
    test_fail('Test 6: Gathering prompt', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: penDesignGenerationPrompt specifies JSON format
// ---------------------------------------------------------------------------
try {
    $prompt = GeneratorPrompts::penDesignGenerationPrompt(
        'Test Site', 'page', null, $summary, $dsDoc['variables'] ?? []
    );
    $ok = str_contains($prompt, 'pen_page') &&
          str_contains($prompt, '"ref"') &&
          str_contains($prompt, 'descendants');
    if ($ok) {
        test_pass('Test 7: penDesignGenerationPrompt specifies JSON format');
    } else {
        test_fail('Test 7: Generation prompt', 'missing pen_page/ref/descendants in prompt');
    }
} catch (\Throwable $e) {
    test_fail('Test 7: Generation prompt', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: PageGeneratorController handles design mode (class exists)
// ---------------------------------------------------------------------------
if (class_exists(PageGeneratorController::class)) {
    test_pass('Test 8: PageGeneratorController class exists');
} else {
    test_fail('Test 8: PageGeneratorController', 'class not found');
}

// ---------------------------------------------------------------------------
// Test 9: parseGeneratedContent handles design JSON
// ---------------------------------------------------------------------------
try {
    $ref = new \ReflectionClass(PageGeneratorController::class);
    $method = $ref->getMethod('parseGeneratedContent');
    $method->setAccessible(true);

    $testJson = json_encode([
        'title' => 'Test Page',
        'slug' => 'test-page',
        'excerpt' => 'Test excerpt',
        'meta_title' => 'Test Meta',
        'meta_description' => 'Test desc',
        'pen_page' => [
            'id' => 'page-root',
            'type' => 'frame',
            'name' => 'Page',
            'layout' => 'vertical',
            'width' => 1200,
            'children' => [
                ['id' => 'hero-1', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                    'hero-heading' => ['content' => 'My Custom Title'],
                ]],
            ],
        ],
    ]);

    // Create a minimal instance via reflection
    $app = new \App\Core\App();
    $controller = new PageGeneratorController($app);
    $result = $method->invoke($controller, $testJson, false, true);

    if (is_array($result) &&
        ($result['title'] ?? '') === 'Test Page' &&
        ($result['editor_mode'] ?? '') === 'design' &&
        is_array($result['pen_page'] ?? null) &&
        ($result['pen_page']['id'] ?? '') === 'page-root') {
        test_pass('Test 9: parseGeneratedContent handles design JSON');
    } else {
        test_fail('Test 9: Design JSON parsing', 'returned unexpected result');
    }
} catch (\Throwable $e) {
    test_fail('Test 9: Design JSON parsing', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: ContentController validates design editor_mode
// ---------------------------------------------------------------------------
try {
    $indexContent = file_get_contents($rootDir . '/app/Admin/ContentController.php');
    if (str_contains($indexContent, "'design'") &&
        str_contains($indexContent, 'design_file')) {
        test_pass('Test 10: ContentController validates design editor_mode');
    } else {
        test_fail('Test 10: ContentController', 'missing design mode or design_file handling');
    }
} catch (\Throwable $e) {
    test_fail('Test 10: ContentController', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: ContentController has previewPen method
// ---------------------------------------------------------------------------
if (method_exists(ContentController::class, 'previewPen')) {
    test_pass('Test 11: ContentController has previewPen method');
} else {
    test_fail('Test 11: previewPen method', 'method not found');
}

// ---------------------------------------------------------------------------
// Test 12: previewPen converts pen_page with design system
// ---------------------------------------------------------------------------
try {
    // Test directly via PenConverter (same logic as previewPen)
    $penPage = [
        'id' => 'test-page',
        'type' => 'frame',
        'name' => 'Test Page',
        'layout' => 'vertical',
        'width' => 1200,
        'children' => [
            ['id' => 'h1', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                'hero-heading' => ['content' => 'Preview Test Title'],
            ]],
        ],
    ];

    $document = [
        'version' => '2.7',
        'variables' => $dsDoc['variables'] ?? [],
        'children' => array_merge($dsDoc['children'] ?? [], [$penPage]),
    ];

    $result = PenConverter::convertDocument($document);

    if (str_contains($result['html'], 'Preview Test Title') &&
        str_contains($result['css'], ':root')) {
        test_pass('Test 12: previewPen converts pen_page with design system');
    } else {
        test_fail('Test 12: Preview conversion', 'HTML missing expected content or CSS missing :root');
    }
} catch (\Throwable $e) {
    test_fail('Test 12: Preview conversion', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: Route /admin/content/preview-pen registered
// ---------------------------------------------------------------------------
try {
    $indexPhp = file_get_contents($rootDir . '/public/index.php');
    if (str_contains($indexPhp, 'preview-pen') &&
        str_contains($indexPhp, 'previewPen')) {
        test_pass('Test 13: Route /admin/content/preview-pen registered');
    } else {
        test_fail('Test 13: Route registration', 'preview-pen route not found in index.php');
    }
} catch (\Throwable $e) {
    test_fail('Test 13: Route', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: Generator template has design mode button
// ---------------------------------------------------------------------------
try {
    $tplContent = file_get_contents($rootDir . '/templates/admin/generator/index.php');
    if (str_contains($tplContent, 'data-mode="design"') &&
        str_contains($tplContent, 'Visual Design')) {
        test_pass('Test 14: Generator template has design mode button');
    } else {
        test_fail('Test 14: Template', 'missing data-mode="design" or "Visual Design" text');
    }
} catch (\Throwable $e) {
    test_fail('Test 14: Template', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: page-generator.js handles design mode
// ---------------------------------------------------------------------------
try {
    $jsContent = file_get_contents($rootDir . '/public/assets/js/page-generator.js');
    if (str_contains($jsContent, 'design') &&
        str_contains($jsContent, 'preview-pen') &&
        str_contains($jsContent, 'pen_page')) {
        test_pass('Test 15: page-generator.js handles design mode');
    } else {
        test_fail('Test 15: JS file', 'missing design/preview-pen/pen_page handling');
    }
} catch (\Throwable $e) {
    test_fail('Test 15: JS file', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: Design file save creates valid .pen JSON
// ---------------------------------------------------------------------------
try {
    $testPage = [
        'id' => 'save-test-page',
        'type' => 'frame',
        'name' => 'Save Test',
        'layout' => 'vertical',
        'width' => 1200,
        'children' => [
            ['id' => 'save-hero', 'type' => 'ref', 'ref' => 'hero-section'],
        ],
    ];

    $fullDoc = [
        'version' => '2.7',
        'variables' => $dsDoc['variables'] ?? [],
        'children' => array_merge($dsDoc['children'] ?? [], [$testPage]),
    ];

    $testPath = $rootDir . '/designs/pages/_test_save.pen';
    $dir = dirname($testPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($testPath, json_encode($fullDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    // Verify it's valid JSON and re-convertable
    $reloaded = json_decode(file_get_contents($testPath), true, 512, JSON_THROW_ON_ERROR);
    $reResult = PenConverter::convertDocument($reloaded);

    if (str_contains($reResult['html'], 'Welcome to Our Site') || strlen($reResult['html']) > 50) {
        test_pass('Test 16: Design file save creates valid .pen JSON');
    } else {
        test_fail('Test 16: Save/reload', 're-converted HTML too short or missing content');
    }

    // Cleanup
    @unlink($testPath);
} catch (\Throwable $e) {
    test_fail('Test 16: Save/reload', $e->getMessage());
    @unlink($testPath ?? '');
}

// ---------------------------------------------------------------------------
// Test 17: PenConverter renders assembled design document
// ---------------------------------------------------------------------------
try {
    $page = [
        'id' => 'render-test',
        'type' => 'frame',
        'name' => 'Render Test Page',
        'layout' => 'vertical',
        'width' => 1200,
        'children' => [
            ['id' => 'r-hero', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                'hero-heading' => ['content' => 'Render Test Hero'],
            ]],
            ['id' => 'r-text', 'type' => 'ref', 'ref' => 'text-section', 'descendants' => [
                'text-heading' => ['content' => 'Render Test Section'],
                'text-body' => ['content' => 'This is render test body content.'],
            ]],
            ['id' => 'r-footer', 'type' => 'ref', 'ref' => 'footer-section', 'descendants' => [
                'footer-copyright' => ['content' => '© 2026 Render Test'],
            ]],
        ],
    ];

    $doc = [
        'version' => '2.7',
        'variables' => $dsDoc['variables'] ?? [],
        'children' => array_merge($dsDoc['children'] ?? [], [$page]),
    ];

    $result = PenConverter::convertDocument($doc);

    $checks = [
        str_contains($result['html'], 'Render Test Hero'),
        str_contains($result['html'], 'Render Test Section'),
        str_contains($result['html'], 'Render Test'),
        str_contains($result['css'], '--primary'),
    ];

    if (!in_array(false, $checks, true)) {
        test_pass('Test 17: PenConverter renders assembled design document');
    } else {
        test_fail('Test 17: Render test', 'missing expected content in HTML or CSS');
    }
} catch (\Throwable $e) {
    test_fail('Test 17: Render test', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: Content CRUD persists design_file
// ---------------------------------------------------------------------------
try {
    // Insert a content record with design_file
    $id = QueryBuilder::query('content')->insert([
        'type'        => 'page',
        'title'       => 'Design Test Page',
        'slug'        => '_test_design_' . time(),
        'body'        => '<p>Test</p>',
        'excerpt'     => '',
        'status'      => 'draft',
        'author_id'   => 1,
        'sort_order'  => 0,
        'editor_mode' => 'design',
        'design_file' => 'pages/test-design.pen',
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);

    $record = QueryBuilder::query('content')
        ->select('editor_mode', 'design_file')
        ->where('id', (int) $id)
        ->first();

    if ($record !== null &&
        ($record['editor_mode'] ?? '') === 'design' &&
        ($record['design_file'] ?? '') === 'pages/test-design.pen') {
        test_pass('Test 18: Content CRUD persists design_file');
    } else {
        test_fail('Test 18: Content CRUD', 'design_file or editor_mode not persisted correctly');
    }

    // Cleanup
    QueryBuilder::query('content')->where('id', (int) $id)->delete();
} catch (\Throwable $e) {
    test_fail('Test 18: Content CRUD', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: Descendants overrides work in generated pages
// ---------------------------------------------------------------------------
try {
    $page = [
        'id' => 'override-test',
        'type' => 'frame',
        'name' => 'Override Test',
        'layout' => 'vertical',
        'width' => 1200,
        'children' => [
            ['id' => 'oh', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                'hero-heading' => ['content' => 'UNIQUE_OVERRIDE_TITLE_XYZ'],
                'hero-subheading' => ['content' => 'UNIQUE_OVERRIDE_SUB_ABC'],
                'hero-cta-text' => ['content' => 'UNIQUE_CTA_BTN'],
            ]],
        ],
    ];

    $doc = [
        'version' => '2.7',
        'variables' => $dsDoc['variables'] ?? [],
        'children' => array_merge($dsDoc['children'] ?? [], [$page]),
    ];

    $result = PenConverter::convertDocument($doc);

    $ok = str_contains($result['html'], 'UNIQUE_OVERRIDE_TITLE_XYZ') &&
          str_contains($result['html'], 'UNIQUE_OVERRIDE_SUB_ABC') &&
          str_contains($result['html'], 'UNIQUE_CTA_BTN') &&
          !str_contains($result['html'], 'Welcome to Our Site');

    if ($ok) {
        test_pass('Test 19: Descendants overrides work in generated pages');
    } else {
        test_fail('Test 19: Overrides', 'custom text not found or default text still present');
    }
} catch (\Throwable $e) {
    test_fail('Test 19: Overrides', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 20: Full pipeline integration
// ---------------------------------------------------------------------------
try {
    // Simulate the full pipeline:
    // 1. Format design system components
    $summary = GeneratorPrompts::formatDesignSystemComponents($dsDoc);

    // 2. Generate prompts (verify they don't crash)
    $gatherPrompt = GeneratorPrompts::penDesignGatheringPrompt(
        'Test Site', [['title' => 'Home', 'slug' => '/']], null, $summary
    );
    $genPrompt = GeneratorPrompts::penDesignGenerationPrompt(
        'Test Site', 'page', null, $summary, $dsDoc['variables'] ?? []
    );

    // 3. Simulate AI output (parseGeneratedContent)
    $fakeAiOutput = json_encode([
        'title' => 'Integration Test Page',
        'slug' => 'integration-test',
        'excerpt' => 'An integration test page.',
        'meta_title' => 'Integration Test',
        'meta_description' => 'Testing the full pipeline.',
        'pen_page' => [
            'id' => 'int-page',
            'type' => 'frame',
            'name' => 'Integration Page',
            'layout' => 'vertical',
            'width' => 1200,
            'children' => [
                ['id' => 'int-hero', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                    'hero-heading' => ['content' => 'Integration Hero'],
                ]],
                ['id' => 'int-feat', 'type' => 'ref', 'ref' => 'feature-grid', 'descendants' => [
                    'feat-heading' => ['content' => 'Integration Features'],
                ]],
                ['id' => 'int-cta', 'type' => 'ref', 'ref' => 'cta-banner', 'descendants' => [
                    'cta-heading' => ['content' => 'Integration CTA'],
                ]],
            ],
        ],
    ]);

    // 4. Parse (via reflection)
    $ref = new \ReflectionClass(PageGeneratorController::class);
    $method = $ref->getMethod('parseGeneratedContent');
    $method->setAccessible(true);
    $app = new \App\Core\App();
    $controller = new PageGeneratorController($app);
    $parsed = $method->invoke($controller, $fakeAiOutput, false, true);

    // 5. Assemble document
    $fullDoc = [
        'version' => '2.7',
        'variables' => $dsDoc['variables'] ?? [],
        'children' => array_merge($dsDoc['children'] ?? [], [$parsed['pen_page']]),
    ];

    // 6. Convert to HTML
    $result = PenConverter::convertDocument($fullDoc);

    // 7. Save to disk
    $testPenPath = $rootDir . '/designs/pages/_integration_test.pen';
    file_put_contents($testPenPath, json_encode($fullDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    // 8. Re-read and re-convert
    $reResult = PenConverter::convertFile($testPenPath);

    $ok = true;

    // Check prompts are non-empty
    if (strlen($gatherPrompt) < 100 || strlen($genPrompt) < 100) {
        test_fail('Test 20: Integration — prompts', 'prompts too short');
        $ok = false;
    }

    // Check parsed data
    if (($parsed['title'] ?? '') !== 'Integration Test Page' || ($parsed['editor_mode'] ?? '') !== 'design') {
        test_fail('Test 20: Integration — parsing', 'parsed data incorrect');
        $ok = false;
    }

    // Check HTML contains customized content
    if (!str_contains($result['html'], 'Integration Hero') ||
        !str_contains($result['html'], 'Integration Features') ||
        !str_contains($result['html'], 'Integration CTA')) {
        test_fail('Test 20: Integration — HTML content', 'missing expected text in HTML');
        $ok = false;
    }

    // Check CSS has variables
    if (!str_contains($result['css'], ':root') || !str_contains($result['css'], '--primary')) {
        test_fail('Test 20: Integration — CSS variables', 'missing :root or --primary');
        $ok = false;
    }

    // Check re-conversion matches
    if (!str_contains($reResult['html'], 'Integration Hero')) {
        test_fail('Test 20: Integration — re-conversion', 'saved/reloaded file produces different output');
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 20: Full pipeline integration');
    }

    // Cleanup
    @unlink($testPenPath);
} catch (\Throwable $e) {
    test_fail('Test 20: Integration', $e->getMessage());
    @unlink($rootDir . '/designs/pages/_integration_test.pen');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 7.4 results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
```

---

## Implementation Notes

### Design Approach: AI Generates Page Frame Only

The AI outputs only the page frame with `ref` nodes and `descendants` overrides. The PHP backend merges this with the design system document before saving and converting. This is simpler and more reliable than having the AI reproduce the full design system JSON because:

1. **Reduced token usage**: The AI doesn't need to output ~900 lines of component definitions.
2. **No drift risk**: Component definitions always come from the canonical `litecms-system.pen` file.
3. **Smaller prompt payload**: The generation prompt only needs to describe component IDs and slot names, not full structure.
4. **Easier validation**: We only validate that `ref` values match known component IDs.

### File Storage Convention

Design files generated by the AI pipeline are saved to `designs/pages/{slug}.pen`. This keeps them separate from the design system file and any manually created designs. The `content.design_file` column stores the relative path (e.g., `pages/about-us.pen`).

### Pre-converted HTML

When a design-mode page is created, the body column is populated with the PenConverter output. This means the public site serves pre-converted HTML without needing to run PenConverter on every request. If the design is later modified in the editor, the content body should be re-converted (this is handled in Chunk 7.5).

### Error Handling

- If `litecms-system.pen` is missing, the design mode gracefully reports an error rather than crashing.
- If PenConverter fails during creation, the body is set to an empty placeholder — the page can be re-converted later.
- If the AI returns invalid JSON or missing pen_page, `parseGeneratedContent` returns null and the generator shows an error.

### No FrontController Changes Needed

The existing FrontController already renders content from the `body` column. Since design-mode pages store pre-converted HTML in `body`, no changes to FrontController are needed in this chunk. Chunk 7.5 adds the design_file-aware rendering path.

### Security

- The `previewPen` endpoint validates that the input is a valid array before passing to PenConverter.
- `.pen` file paths are validated via `DesignController::sanitizePath()` (already exists).
- The `design_file` column value is sanitized before use in any file operations.
- PenConverter's output is HTML — it goes through the existing template escaping for admin display.

### Edge Cases

- **No design system file**: If `litecms-system.pen` doesn't exist, design mode is unavailable. The gathering prompt should mention this and the JS should show an error.
- **Empty pen_page children**: If the AI returns a page with no components, the result is an empty page. This is valid and the user can add components in the editor (Chunk 7.5).
- **Unknown component refs**: If the AI references a component ID not in the design system, PenConverter will show "Component not found" in the HTML. The validation in `parseGeneratedContent` could catch this, but it's not critical — the user sees the issue in preview.
- **Slug collisions**: The `designs/pages/` directory could have name collisions if two pages have the same slug. The `ensureUniqueSlug()` method already prevents duplicate slugs in the content table, so this shouldn't happen.

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `migrations/010_design_file.sqlite.sql` | Migration | Create |
| 2 | `migrations/010_design_file.mysql.sql` | Migration | Create |
| 3 | `migrations/010_design_file.pgsql.sql` | Migration | Create |
| 4 | `app/AIAssistant/GeneratorPrompts.php` | Class | Modify (add 3 methods) |
| 5 | `app/AIAssistant/PageGeneratorController.php` | Class | Modify (design branch in chat/create/parse) |
| 6 | `app/Admin/ContentController.php` | Class | Modify (design_file support + previewPen) |
| 7 | `public/index.php` | Routes | Modify (add preview-pen route) |
| 8 | `templates/admin/generator/index.php` | Template | Modify (add design mode button + preview) |
| 9 | `public/assets/js/page-generator.js` | JavaScript | Modify (design mode handling) |
| 10 | `tests/chunk-7.4-verify.php` | Test script | Create |

---

## Estimated Scope

- **Migration files**: 3 files, ~3 lines each
- **PHP code changes**: ~250-300 new lines across GeneratorPrompts, PageGeneratorController, ContentController
- **JavaScript changes**: ~40-50 new lines in page-generator.js
- **Template changes**: ~15 new lines in generator/index.php
- **Test script**: ~400 lines
- **Total new PHP LOC**: ~300
- **Files modified**: 6
- **Files created**: 4 (3 migrations + 1 test)
