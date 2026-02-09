# Chunk 6.4 — AI Element Integration
## Detailed Implementation Plan

---

## Overview

This chunk connects the AI system to the element-based page builder across four sub-features:

- **A) Element Editor AI Assistant** — AI coding assistant in the element editor to help write/refine HTML templates and CSS for individual elements. This is the primary AI integration point for the page builder.
- **B) Page Generator Element Awareness** — Extend the AI page generator to produce element-based pages, reusing catalogue elements.
- **C) Element Proposal Approval Flow** — When the AI generates a page needing an element that doesn't exist, it proposes new elements for admin approval.
- **D) Content Editor AI Catalogue Context** — Include the element catalogue in the content editor AI's system prompt so it can reference available elements.

**Core design decisions**:
- Reuse existing AI infrastructure: `ClaudeClient`, `ConversationManager`, `AIChatCore.js`, `AIController` patterns
- Element AI conversations use a new `element_id` column on `ai_conversations` (migration 006)
- Element editor AI uses the same thin-wrapper pattern as `ai-assistant.js` → `AIChatCore`
- Element-mode page generation produces JSON with `element_slug` references, not raw HTML
- The `element_proposals` table already exists from migration 004 — no new table needed

**Key reuse**:
| Component | Existing file | Reuse |
|-----------|--------------|-------|
| API client | `app/AIAssistant/ClaudeClient.php` | Direct, no changes |
| Chat UI core | `public/assets/js/ai-chat-core.js` | `new AIChatCore(config)` |
| Chat CSS | `public/assets/css/ai-chat.css` | Include in template via layout |
| Conversations | `app/AIAssistant/ConversationManager.php` | Add 2 new methods |
| API key/model helpers | `app/AIAssistant/AIController.php` | Pattern copied to ElementAIController |
| Prompt building | `app/AIAssistant/GeneratorPrompts.php` | Extended with 3 new methods |
| Element catalogue data | `app/PageBuilder/SeedElements.php` | Reference for prompt examples |
| Proposal table | `migrations/004_page_builder.*.sql` | `element_proposals` already exists |

---

## File Modification/Creation Order

Files are listed in dependency order — each change only depends on files listed before it.

---

### 1. Create `migrations/006_element_ai.sqlite.sql` (+ mysql + pgsql variants)

**Purpose**: Add `element_id` column to `ai_conversations` table for per-element AI conversations.

**Dependencies**: None — schema-only.

**SQLite variant**:

```sql
-- LiteCMS Element AI — SQLite
-- Migration: 006_element_ai
-- Adds element_id to ai_conversations for per-element AI chats

ALTER TABLE ai_conversations ADD COLUMN element_id INTEGER DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_ai_conversations_element ON ai_conversations(element_id);
```

**MySQL variant**: Same ALTER/INDEX with appropriate syntax.

**PostgreSQL variant**: Same ALTER/INDEX with appropriate syntax.

---

### 2. Create `app/AIAssistant/ElementPrompts.php`

**Purpose**: Static prompt builder for the element editor AI assistant. Provides the system prompt with template syntax reference, CSS scoping rules, and element context.

**Dependencies**: None — pure utility class.

#### Methods:

```php
public static function systemPrompt(string $siteName, ?array $element, array $catalogueElements = []): string
```
- Role: "You are an HTML/CSS template coding assistant for the element builder in {siteName}."
- Template syntax reference:
  - `{{slot}}` — text output (HTML-escaped)
  - `{{{slot}}}` — raw HTML output (unescaped)
  - `{{#list}}...{{/list}}` — loop over array slot
  - `{{^key}}...{{/key}}` — inverted section (renders when key is falsy/empty)
  - `{{key.sub}}` — nested property access
  - `{{#boolean_slot}}...{{/boolean_slot}}` — conditional section
- CSS scoping rule: "All CSS selectors MUST be scoped under `.lcms-el-{slug}`. Example: `.lcms-el-hero-section .hero-inner { ... }`"
- Current element context (if editing, not creating):
  - Name, slug, description, category
  - Slots with types and labels
  - Current HTML template excerpt (first 500 chars)
  - Current CSS excerpt (first 500 chars)
- For new elements: "The user is creating a new element from scratch."
- Abbreviated reference of 1-2 seed elements (Hero Section, Text Section) showing name, slug, slot structure
- Output format instructions:
  - Use ` ```html ` fenced code blocks for HTML templates
  - Use ` ```css ` fenced code blocks for CSS
  - When providing both, put them in separate fenced blocks
  - Explain changes briefly before each code block

```php
public static function formatElementForPrompt(?array $element): string
```
- Formats a single element's metadata for the system prompt
- Includes: name, slug, description, category, slots (key, type, label, required)
- Returns empty string if element is null

---

### 3. Create `app/AIAssistant/ElementAIController.php`

**Purpose**: Controller for element editor AI chat. Follows the same pattern as `PageGeneratorController`.

**Dependencies**: ElementPrompts (step 2), ConversationManager (step 5), ClaudeClient, AIController (for decrypt/helper methods).

#### Methods:

```php
public function chat(Request $request): Response
```
- POST `/admin/ai/element/chat`
- Accepts JSON: `{message, element_id, conversation_id, model, current_html, current_css, attachments[]}`
- `element_id` may be null (new element — AI generates from scratch)
- Uses `ConversationManager::findOrCreateForElement()` for per-element conversations
- Loads element data from `elements` table if `element_id` is provided
- Builds system prompt via `ElementPrompts::systemPrompt()` with element metadata
- Prepends `current_html` and `current_css` to the first user message as context (only if non-empty and this is the first message)
- Reuses `getApiKey()`, `resolveModel()`, `getSetting()` (same pattern as PageGeneratorController)
- Stores messages via `appendMessageWithUsage()`, updates usage
- Returns JSON: `{success, response, conversation_id, usage}`

```php
public function conversations(Request $request): Response
```
- GET `/admin/ai/element/conversations?element_id=N`
- Returns conversation history for the given element (or null for new elements)
- Uses `ConversationManager::getHistoryForElement()`
- Same response format as `AIController::conversations()`

```php
private function getApiKey(): string
private function resolveModel(?string $requestModel): string
private function getSetting(string $key, string $default = ''): string
```
- Copied from PageGeneratorController pattern

---

### 4. Create `public/assets/js/element-ai-assistant.js`

**Purpose**: Thin wrapper around `AIChatCore` for the element editor AI panel. Same pattern as `ai-assistant.js`.

**Dependencies**: `ai-chat-core.js` must be loaded first.

#### Structure:

```javascript
(function() {
    'use strict';

    var panelOpen = false;
    var elementId = null;
    var csrfToken = '';
    var core = null;

    document.addEventListener('DOMContentLoaded', function() {
        initElementAIPanel();
    });

    function initElementAIPanel() {
        var panel = document.getElementById('element-ai-panel');
        if (!panel) return;

        // Read element ID from form data attribute
        var form = document.getElementById('element-form');
        if (form && form.dataset.elementId) {
            elementId = parseInt(form.dataset.elementId, 10) || null;
        }

        var csrfInput = document.querySelector('input[name="_csrf_token"]');
        if (csrfInput) csrfToken = csrfInput.value;

        // Initialize AIChatCore
        core = new window.AIChatCore({
            messagesEl:     document.getElementById('element-ai-messages'),
            inputEl:        document.getElementById('element-ai-input'),
            sendBtnEl:      document.getElementById('element-ai-send'),
            headerEl:       document.getElementById('element-ai-header'),
            attachPreviewEl: document.getElementById('element-ai-attach-preview'),
            attachBtnEl:    null, // No image attachments for code assistant
            chatEndpoint:   '/admin/ai/element/chat',
            compactEndpoint: '/admin/ai/compact',
            modelsEndpoint:  '/admin/ai/models/enabled',
            conversationsEndpoint: '/admin/ai/element/conversations',
            csrfToken:       csrfToken,
            contentId:       null,
            enableAttachments: false,
            enableModelSelector: true,
            enableContextMeter: true,
            enableCompact: true,
            enableConversationHistory: true,
            enableMarkdown: true,
            enableResizable: true,
            resizableEl: panel,
            messageActions: function(content) {
                return [
                    { label: 'Apply HTML', title: 'Apply HTML to template', feedback: 'Applied!',
                      action: function() { applyCodeBlock(content, 'html'); } },
                    { label: 'Apply CSS', title: 'Apply CSS to stylesheet', feedback: 'Applied!',
                      action: function() { applyCodeBlock(content, 'css'); } },
                    { label: 'Apply Both', title: 'Apply both HTML and CSS', feedback: 'Applied!',
                      action: function() { applyCodeBlock(content, 'html'); applyCodeBlock(content, 'css'); } },
                    { label: 'Copy', title: 'Copy to clipboard', feedback: 'Copied!',
                      action: function() { copyToClipboard(content); } }
                ];
            },
            extraPayload: function() {
                return {
                    element_id: elementId,
                    current_html: getCurrentHtml(),
                    current_css: getCurrentCss()
                };
            }
        });

        // Toggle button
        var toggleBtn = document.getElementById('element-ai-toggle');
        if (toggleBtn) toggleBtn.addEventListener('click', togglePanel);

        // Close button
        var closeBtn = document.getElementById('element-ai-close');
        if (closeBtn) closeBtn.addEventListener('click', function() {
            if (panelOpen) togglePanel();
        });

        // New conversation
        var newBtn = document.getElementById('element-ai-new');
        if (newBtn) newBtn.addEventListener('click', function() {
            core.newConversation();
        });
    }

    function togglePanel() { /* toggle .ai-panel-open on .element-editor-grid, show/hide panel */ }

    function getCurrentHtml() {
        var el = document.getElementById('el-html-template');
        return el ? el.value : '';
    }

    function getCurrentCss() {
        var el = document.getElementById('el-css');
        return el ? el.value : '';
    }

    function extractCodeBlock(content, lang) {
        // Parse fenced code block: ```lang\n...\n```
        var regex = new RegExp('```' + lang + '\\s*\\n([\\s\\S]*?)```', 'i');
        var match = content.match(regex);
        return match ? match[1].trim() : null;
    }

    function applyCodeBlock(content, lang) {
        var code = extractCodeBlock(content, lang);
        if (!code) return;
        if (lang === 'html') {
            var el = document.getElementById('el-html-template');
            if (el) el.value = code;
        } else if (lang === 'css') {
            var el = document.getElementById('el-css');
            if (el) el.value = code;
        }
    }

    function copyToClipboard(text) {
        var tmp = document.createElement('div');
        tmp.innerHTML = text;
        var plain = tmp.textContent || tmp.innerText || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(plain);
        }
    }
})();
```

**Key differences from `ai-assistant.js`**:
- `chatEndpoint: '/admin/ai/element/chat'` (not `/admin/ai/chat`)
- `conversationsEndpoint: '/admin/ai/element/conversations'`
- `enableAttachments: false` (code assistant, no images)
- `extraPayload` sends `element_id`, `current_html`, `current_css`
- `messageActions` returns: **Apply HTML**, **Apply CSS**, **Apply Both**, **Copy**
- `extractCodeBlock(content, lang)` — parses ` ```html ` and ` ```css ` fenced code blocks from markdown
- Apply functions set textarea values directly (`#el-html-template`, `#el-css`)
- Toggle panel adds/removes `.ai-panel-open` on `.element-editor-grid`

---

### 5. Modify `app/AIAssistant/ConversationManager.php`

**Purpose**: Add element-aware conversation methods. Existing methods are untouched.

**Dependencies**: Migration 006 (step 1).

#### New methods:

```php
public function findOrCreateForElement(int $userId, ?int $elementId): array
```
- Queries `ai_conversations` with `user_id = $userId`, `element_id = $elementId`, `content_id IS NULL`
- If found, returns the most recent conversation
- If not found, creates a new one with `element_id` set, `content_id` null
- Returns the conversation array

```php
public function getHistoryForElement(int $userId, ?int $elementId): array
```
- Queries `ai_conversations` with `user_id = $userId`, `element_id = $elementId`, `content_id IS NULL`
- Returns all matching conversations ordered by `updated_at DESC`

---

### 6. Modify `templates/admin/elements/edit.php`

**Purpose**: Add AI panel markup, scripts, and data attributes to the element editor.

**Dependencies**: element-ai-assistant.js (step 4), element editor structure.

#### Changes:

**Add data-element-id to form** (line 21):
```php
<form id="element-form" method="POST" action="<?= $this->e($action) ?>"
      data-element-id="<?= $isNew ? '' : (int) $el['id'] ?>">
```

**Add AI toggle button in `.page-header-left`** (after the badge span):
```php
<button type="button" id="element-ai-toggle" class="btn btn-secondary btn-sm"
        aria-expanded="false" title="Toggle AI Assistant">
    AI Assistant
</button>
```

**Add `#element-ai-panel` div after `.element-code-panel`** (inside `.element-editor-grid`):
```php
<!-- AI Assistant Panel -->
<div class="element-ai-panel" id="element-ai-panel">
    <div class="ai-resize-handle" title="Drag to resize"></div>
    <div class="ai-panel-header" id="element-ai-header">
        <div class="ai-panel-header-left">
            <select class="ai-model-select" title="Select model"></select>
        </div>
        <div class="ai-panel-header-actions">
            <button type="button" id="element-ai-compact" title="Compact conversation" style="display:none;">Compact</button>
            <button type="button" id="element-ai-history" title="Conversation history" style="display:none;">History</button>
            <button type="button" id="element-ai-new" title="New conversation">New</button>
            <button type="button" id="element-ai-close" title="Close panel">&times;</button>
        </div>
    </div>
    <div class="ai-context-meter">
        <div class="context-bar-track">
            <div class="context-bar context-bar-ok" style="width:0"></div>
        </div>
        <span class="context-text">0 / 200.0k</span>
    </div>
    <div id="element-ai-messages" class="chat-messages"></div>
    <div id="element-ai-attach-preview" class="ai-attachments-preview"></div>
    <div class="chat-input-area">
        <textarea id="element-ai-input" placeholder="Ask AI to help write HTML/CSS..." rows="2"></textarea>
        <button id="element-ai-send" type="button" class="btn btn-primary">Send</button>
    </div>
</div>
```

**Add script and style tags** (before closing, after existing element-editor.js script):
```php
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11/build/styles/github-dark.min.css">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/highlightjs/cdn-release@11/build/highlight.min.js"></script>
<script src="/assets/js/ai-chat-core.js"></script>
<script src="/assets/js/element-ai-assistant.js"></script>
```

**Note**: `ai-chat.css` is already loaded via the admin layout template (line 8 of layout.php).

---

### 7. Modify `public/index.php`

**Purpose**: Register routes for element AI chat, element AI conversations, and proposal management.

**Dependencies**: ElementAIController (step 3), ElementController modifications (step 12).

#### Add import:
```php
use App\AIAssistant\ElementAIController;
```

#### Add routes inside the `/admin` group (after existing AI routes):
```php
// Element AI Assistant
$router->post('/ai/element/chat', [ElementAIController::class, 'chat']);
$router->get('/ai/element/conversations', [ElementAIController::class, 'conversations']);

// Element proposals
$router->get('/element-proposals', [ElementController::class, 'proposals']);
$router->post('/element-proposals/{id}/approve', [ElementController::class, 'approveProposal']);
$router->post('/element-proposals/{id}/reject', [ElementController::class, 'rejectProposal']);
```

---

### 8. Modify `public/assets/css/admin.css`

**Purpose**: Add grid rule for the element editor AI panel.

**Dependencies**: None — CSS-only.

#### Append:
```css
/* --- Element Editor AI Panel --- */
.element-editor-grid.ai-panel-open { grid-template-columns: 380px 1fr 380px; }
.element-ai-panel { display: none; flex-direction: column; height: calc(100vh - 120px); border-left: 1px solid var(--color-border, #e5e7eb); background: var(--color-bg, #fff); position: sticky; top: 70px; overflow: hidden; }
.element-editor-grid.ai-panel-open .element-ai-panel { display: flex; }
```

---

### 9. Modify `app/AIAssistant/GeneratorPrompts.php`

**Purpose**: Add element catalogue formatter and element-aware prompt builders for page generation.

**Dependencies**: None — pure static methods.

#### New methods:

```php
public static function formatElementCatalogue(array $elements): string
```
- Takes an array of active elements from the catalogue
- Formats each as: `"- {name} (slug: {slug}): {description}. Slots: {slot_key} ({type}), ..."`
- Returns the full list as a string
- Empty array returns "No elements in the catalogue yet."

```php
public static function elementGatheringPrompt(string $siteName, array $existingPages, ?array $typeFields, string $catalogue): string
```
- Same structure as `gatheringPrompt()` but adds:
  - "You are building an element-based page using the site's element catalogue."
  - The catalogue string from `formatElementCatalogue()`
  - Instruction: "During the gathering phase, understand which existing elements can be reused and what new elements might be needed."
  - Same READY_TO_GENERATE marker behavior

```php
public static function elementGenerationPrompt(string $siteName, string $contentType, ?array $typeFields, string $catalogue, array $imageUrls = []): string
```
- Similar to `generationPrompt()` but outputs element-based JSON instead of HTML body
- JSON format:
  ```json
  {
    "editor_mode": "elements",
    "title": "...", "slug": "...", "excerpt": "...", "meta_title": "...", "meta_description": "...",
    "elements": [
      {"element_slug": "hero-section", "slot_data": {"title": "...", "description": "..."}},
      {"element_slug": "__new__", "new_element": {"name": "...", "slug": "...", "description": "...", "category": "...", "html_template": "...", "css": "...", "slots_json": [...]}, "slot_data": {...}}
    ]
  }
  ```
- Includes the catalogue so the AI knows which elements exist
- Rule: "Use existing elements from the catalogue whenever possible. Only propose `__new__` elements when no existing element can serve the purpose."
- Rule: "For `__new__` elements, the `html_template` must use micro-mustache syntax (`{{slot}}`, `{{{slot}}}`, etc.) and the CSS must be scoped under `.lcms-el-{slug}`."

---

### 10. Modify `app/AIAssistant/PageGeneratorController.php`

**Purpose**: Handle element-based generation mode in chat and create flows.

**Dependencies**: GeneratorPrompts changes (step 9), element catalogue data.

#### Changes to `chat()`:

After reading `$step` from the request data, also read:
```php
$editorMode = $data['editor_mode'] ?? 'html';
```

When `$editorMode === 'elements'`:
- Load active elements: `QueryBuilder::query('elements')->select()->where('status', 'active')->get()`
- Build catalogue string: `GeneratorPrompts::formatElementCatalogue($elements)`
- Use `GeneratorPrompts::elementGatheringPrompt()` instead of `gatheringPrompt()`
- Use `GeneratorPrompts::elementGenerationPrompt()` instead of `generationPrompt()`

When `$step === 'generating'` and `$editorMode === 'elements'`:
- Parse AI response as element-based JSON (expects `editor_mode: 'elements'` and `elements` array)
- Set `$responseStep = 'generated'` with the element-based data

#### Changes to `create()`:

Accept `editor_mode` field in the request data. When `editor_mode === 'elements'`:
- Create the content record with `editor_mode = 'elements'` and empty `body`
- Iterate over `elements` array:
  - For `element_slug` matching existing elements: create `page_elements` rows with `slot_data_json`
  - For `element_slug === '__new__'`: create `element_proposals` rows with the full `new_element` data, set `proposed_by` to current user, link `conversation_id` if available

#### New helper:

```php
private function createElementProposal(array $newElement, int $userId, ?int $conversationId): int
```
- Inserts into `element_proposals` table
- Returns the proposal ID

---

### 11. Modify `templates/admin/generator/index.php`

**Purpose**: Add editor mode toggle in the setup step.

**Dependencies**: None — template-only.

#### Changes to Step 1 (setup panel):

After the type selector buttons, add an editor mode toggle:
```php
<div class="editor-mode-selector" style="margin-top:1rem;">
    <label style="font-weight:600;">Editor Mode:</label>
    <div class="mode-options" style="display:flex;gap:0.5rem;margin-top:0.5rem;">
        <button type="button" class="mode-option active" data-mode="html">HTML</button>
        <button type="button" class="mode-option" data-mode="elements">Elements</button>
    </div>
</div>
```

---

### 12. Modify `public/assets/js/page-generator.js`

**Purpose**: Send `editor_mode` in requests, handle element-based preview.

**Dependencies**: Generator template changes (step 11).

#### Changes:

Add `editorMode` state variable (default `'html'`).

In `init()`: bind click handlers on `.mode-option` buttons to set `editorMode` and toggle `.active`.

In `extraPayload()`:
```javascript
return {
    content_type: contentType,
    step: currentStep,
    editor_mode: editorMode
};
```

In `checkReadyState()` / preview rendering:
- When `data.generated.editor_mode === 'elements'`: render element-based preview
  - Show each element as a card with slug, slot data summary
  - Highlight `__new__` elements with a "Proposed" badge
  - Show element HTML template preview for `__new__` elements

In `createContent()`:
- Include `editor_mode: editorMode` in the create request payload
- When `editor_mode === 'elements'`: send the `elements` array instead of `body`

---

### 13. Modify `app/Admin/ElementController.php`

**Purpose**: Add proposal management endpoints.

**Dependencies**: `element_proposals` table (exists from migration 004).

#### New methods:

```php
public function proposals(Request $request): Response
```
- GET `/admin/element-proposals`
- Queries `element_proposals` where `status = 'pending'`, ordered by `created_at DESC`
- Also accepts `?status=approved` or `?status=rejected` for filtering
- Renders `admin/elements/proposals` template with proposal list

```php
public function approveProposal(Request $request, string $id): Response
```
- POST `/admin/element-proposals/{id}/approve`
- Loads the proposal, validates it's pending
- Creates a new element in the `elements` table from the proposal data
- Sets `is_ai_generated = 1` on the new element
- Updates proposal status to `approved`
- Redirects back to proposals list with success flash

```php
public function rejectProposal(Request $request, string $id): Response
```
- POST `/admin/element-proposals/{id}/reject`
- Updates proposal status to `rejected`
- Redirects back to proposals list

---

### 14. Create `templates/admin/elements/proposals.php`

**Purpose**: Proposal review UI for AI-generated element proposals.

**Dependencies**: ElementController proposal methods (step 13).

#### Template structure:

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Element Proposals</h1>
    <p>Review AI-generated element proposals.</p>
</div>

<!-- Filter tabs: Pending | Approved | Rejected -->
<div class="proposal-filters">...</div>

<!-- Proposal cards -->
<?php foreach ($proposals as $proposal): ?>
<div class="card proposal-card">
    <div class="card-header">
        <strong><?= $this->e($proposal['name']) ?></strong>
        <span class="badge"><?= $this->e($proposal['category']) ?></span>
    </div>
    <div class="card-body">
        <p><?= $this->e($proposal['description']) ?></p>
        <details>
            <summary>HTML Template</summary>
            <pre><code><?= $this->e($proposal['html_template']) ?></code></pre>
        </details>
        <details>
            <summary>CSS</summary>
            <pre><code><?= $this->e($proposal['css']) ?></code></pre>
        </details>
    </div>
    <div class="card-footer">
        <form method="POST" action="/admin/element-proposals/<?= (int)$proposal['id'] ?>/approve" style="display:inline;">
            <?= $this->csrfField() ?>
            <button type="submit" class="btn btn-primary btn-sm">Approve</button>
        </form>
        <form method="POST" action="/admin/element-proposals/<?= (int)$proposal['id'] ?>/reject" style="display:inline;">
            <?= $this->csrfField() ?>
            <button type="submit" class="btn btn-danger btn-sm">Reject</button>
        </form>
    </div>
</div>
<?php endforeach; ?>
```

---

### 15. Modify `app/AIAssistant/AIController.php`

**Purpose**: Include element catalogue summary in the content editor AI system prompt when the content is in elements mode.

**Dependencies**: GeneratorPrompts `formatElementCatalogue()` (step 9).

#### Changes to `buildSystemPrompt()`:

After the existing content context block, add:
```php
if ($content !== null && ($content['editor_mode'] ?? 'html') === 'elements') {
    $elements = QueryBuilder::query('elements')
        ->select('name', 'slug', 'description', 'slots_json')
        ->where('status', 'active')
        ->get();

    if (!empty($elements)) {
        $prompt .= "\n\nThis page uses the element-based editor. Available elements:\n";
        $prompt .= GeneratorPrompts::formatElementCatalogue($elements);
        $prompt .= "\nYou can reference these elements when suggesting page structure changes.";
    }
}
```

Also need to add `editor_mode` to the SELECT query for content (add it alongside `type`, `title`, `body`, `excerpt`, `status`).

---

## Detailed Class Specifications

### `App\AIAssistant\ElementPrompts` (NEW)

```
NEW STATIC METHODS:
  - public static systemPrompt(string $siteName, ?array $element, array $catalogueElements = []): string
      Builds the system prompt for the element editor AI assistant.
      Includes template syntax reference, CSS scoping rules, current element context.
      Returns the full system prompt string.

  - public static formatElementForPrompt(?array $element): string
      Formats a single element's metadata (name, slug, description, slots).
      Returns formatted string or empty string if null.
```

### `App\AIAssistant\ElementAIController` (NEW)

```
NEW METHODS:
  - public chat(Request $request): Response
      POST handler for element AI chat. Validates input, loads element,
      builds system prompt, calls Claude API, stores messages.

  - public conversations(Request $request): Response
      GET handler for element conversation history.

PRIVATE METHODS:
  - private getApiKey(): string
  - private resolveModel(?string $requestModel): string
  - private getSetting(string $key, string $default = ''): string
```

### Changes to `App\AIAssistant\ConversationManager`

```
NEW METHODS:
  - public findOrCreateForElement(int $userId, ?int $elementId): array
      Queries by user_id + element_id + content_id IS NULL.
      Creates new conversation if not found.

  - public getHistoryForElement(int $userId, ?int $elementId): array
      Returns all conversations for user + element_id.
```

### Changes to `App\AIAssistant\GeneratorPrompts`

```
NEW STATIC METHODS:
  - public static formatElementCatalogue(array $elements): string
      Formats active elements with name, slug, description, slots for AI context.

  - public static elementGatheringPrompt(string $siteName, array $existingPages, ?array $typeFields, string $catalogue): string
      Element-aware gathering prompt. Includes catalogue context.

  - public static elementGenerationPrompt(string $siteName, string $contentType, ?array $typeFields, string $catalogue, array $imageUrls = []): string
      Element-mode generation prompt. Output format is element-based JSON.
```

### Changes to `App\AIAssistant\PageGeneratorController`

```
MODIFIED METHODS:
  - public chat(Request $request): Response
      CHANGE: Read editor_mode from request. When 'elements': load catalogue,
              use element-aware prompts, parse element-based response.

  - public create(Request $request): Response
      CHANGE: When editor_mode = 'elements': create page_elements rows for
              existing elements, create element_proposals for __new__ elements.

NEW METHODS:
  - private createElementProposal(array $newElement, int $userId, ?int $conversationId): int
      Inserts into element_proposals table. Returns proposal ID.
```

### Changes to `App\Admin\ElementController`

```
NEW METHODS:
  - public proposals(Request $request): Response
      GET handler for element proposals list.

  - public approveProposal(Request $request, string $id): Response
      POST handler. Creates element from proposal, marks approved.

  - public rejectProposal(Request $request, string $id): Response
      POST handler. Marks proposal as rejected.
```

### Changes to `App\AIAssistant\AIController`

```
MODIFIED METHODS:
  - private buildSystemPrompt(?int $contentId): string
      CHANGE: When content is in editor_mode='elements', append element
              catalogue summary using GeneratorPrompts::formatElementCatalogue().
      CHANGE: Add 'editor_mode' to content SELECT query.
```

---

## Acceptance Test Procedures

### Test 1: ElementPrompts class exists and includes template syntax

```
1. Verify class App\AIAssistant\ElementPrompts is autoloadable.
2. Call ElementPrompts::systemPrompt('TestSite', null).
3. Verify output contains '{{slot}}' (template syntax reference).
4. Verify output contains 'lcms-el-' (CSS scoping rule).
5. Verify output contains 'html' and 'css' (code block format).
```

### Test 2: ElementAIController exists with required methods

```
1. Verify class App\AIAssistant\ElementAIController is autoloadable.
2. Verify it has public method chat().
3. Verify it has public method conversations().
```

### Test 3: Migration 006 applies (element_id on ai_conversations)

```
1. Run migrations.
2. Verify ai_conversations table has element_id column.
```

### Test 4: ConversationManager has element methods

```
1. Verify ConversationManager has method findOrCreateForElement().
2. Verify ConversationManager has method getHistoryForElement().
3. Call findOrCreateForElement(1, null) — should create and return a conversation.
4. Call again — should return the same conversation (not create a new one).
```

### Test 5: Routes resolve for element AI and proposals

```
1. Verify route '/admin/ai/element/chat' is registered (POST).
2. Verify route '/admin/ai/element/conversations' is registered (GET).
3. Verify route '/admin/element-proposals' is registered (GET).
```

### Test 6: Element edit template has AI panel markup

```
1. Read templates/admin/elements/edit.php.
2. Verify it contains 'element-ai-panel'.
3. Verify it contains 'ai-chat-core.js'.
4. Verify it contains 'element-ai-assistant.js'.
5. Verify it contains 'element-ai-toggle'.
```

### Test 7: element-ai-assistant.js exists with AIChatCore and extractCodeBlock

```
1. Read public/assets/js/element-ai-assistant.js.
2. Verify it contains 'AIChatCore'.
3. Verify it contains 'extractCodeBlock'.
4. Verify it contains '/admin/ai/element/chat'.
5. Verify it contains 'Apply HTML' or 'applyCodeBlock'.
```

### Test 8: GeneratorPrompts has element-aware methods

```
1. Verify GeneratorPrompts has method formatElementCatalogue().
2. Verify GeneratorPrompts has method elementGatheringPrompt().
3. Verify GeneratorPrompts has method elementGenerationPrompt().
4. Call formatElementCatalogue([]) — should return "No elements" message.
5. Call formatElementCatalogue([sample element]) — should contain element name and slug.
```

### Test 9: PageGeneratorController handles editor_mode

```
1. Inspect PageGeneratorController::chat() source.
2. Verify it reads 'editor_mode' from request data.
3. Verify it references elementGatheringPrompt or elementGenerationPrompt.
```

### Test 10: ElementController has proposal methods

```
1. Verify ElementController has method proposals().
2. Verify ElementController has method approveProposal().
3. Verify ElementController has method rejectProposal().
```

### Test 11: Proposals template exists

```
1. Verify file templates/admin/elements/proposals.php exists.
2. Read its contents.
3. Verify it contains 'approve' (approve button/form).
4. Verify it contains 'reject' (reject button/form).
```

### Test 12: AIController buildSystemPrompt includes catalogue for element-mode content

```
1. Read AIController.php source.
2. Verify buildSystemPrompt() references 'editor_mode' or 'elements'.
3. Verify it references formatElementCatalogue.
```

### Test 13: Generator index has editor mode toggle

```
1. Read templates/admin/generator/index.php.
2. Verify it contains 'editor-mode' or 'mode-option' or 'editor_mode'.
3. Verify it contains both 'html' and 'elements' as mode options.
```

---

## Implementation Notes

### Design Decisions

1. **Separate controller for element AI**: `ElementAIController` is separate from `AIController` because it has different system prompts, different conversation scoping (element_id vs content_id), and different response handling. The content editor AI writes prose HTML; the element editor AI writes template code.

2. **No image attachments in element AI**: The element editor AI is a coding assistant — it writes HTML templates and CSS, not visual content. Image attachments would not be useful here.

3. **Current code as context**: `current_html` and `current_css` from the live textareas are sent with every message so the AI always has the latest state of the element being edited.

4. **extractCodeBlock()**: Client-side parsing of fenced code blocks from AI markdown responses. The AI is instructed to use ` ```html ` and ` ```css ` blocks. The Apply buttons extract these blocks and set the textarea values directly.

5. **Element proposals table already exists**: Migration 004 created `element_proposals` with all needed columns (name, slug, description, category, html_template, css, slots_json, conversation_id, proposed_by, status). No new table needed.

6. **Editor mode toggle in generator**: Simple radio-style toggle between HTML and Elements mode. The mode is sent with every chat request and determines which prompts and output format to use.

### Edge Cases

1. **New element (no element_id)**: Element AI works on create page too. `element_id` is null, conversation is scoped by `element_id IS NULL` + user. System prompt says "The user is creating a new element from scratch."
2. **No API key configured**: Same error handling as existing AI controllers — returns 400 with helpful message.
3. **AI returns no code blocks**: Apply buttons silently do nothing. The user can still read the AI's text response and manually apply suggestions.
4. **Element deleted while AI panel open**: Conversation is preserved (soft reference). AI can still chat but can't load element metadata.
5. **Empty catalogue**: `formatElementCatalogue([])` returns "No elements in the catalogue yet." The AI can still generate `__new__` elements.
6. **Duplicate proposal slugs**: The `ensureUniqueSlug()` logic runs when a proposal is approved and converted to an element.

### Security Notes

- Element AI chat endpoint is protected by AuthMiddleware (inside `/admin` group)
- CSRF token is validated on all POST requests
- User messages are stored as-is in the database (no HTML rendering of stored messages)
- Element code (HTML template, CSS) from AI responses is only applied when the user explicitly clicks "Apply" — never auto-applied
- Proposals require explicit admin approval before entering the catalogue

### What NOT to Change

- Existing `AIController::chat()` — untouched (content editor AI)
- Existing `ai-assistant.js` — untouched (content editor wrapper)
- Existing `ai-chat-core.js` — untouched (shared UI module)
- Existing `ClaudeClient.php` — untouched (API client)
- Existing element catalogue and rendering — untouched
- HTML-mode page generation — completely unaffected

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `migrations/006_element_ai.sqlite.sql` | Migration | Create |
| 2 | `migrations/006_element_ai.mysql.sql` | Migration | Create |
| 3 | `migrations/006_element_ai.pgsql.sql` | Migration | Create |
| 4 | `app/AIAssistant/ElementPrompts.php` | Class | Create — 2 static methods |
| 5 | `app/AIAssistant/ElementAIController.php` | Class | Create — 2 public + 3 private methods |
| 6 | `public/assets/js/element-ai-assistant.js` | JavaScript | Create (~120 lines) |
| 7 | `app/AIAssistant/ConversationManager.php` | Class | Modify — add 2 new methods |
| 8 | `templates/admin/elements/edit.php` | Template | Modify — AI panel markup + scripts |
| 9 | `public/index.php` | Routes | Modify — 5 new routes |
| 10 | `public/assets/css/admin.css` | Stylesheet | Modify — element AI panel grid rule |
| 11 | `app/AIAssistant/GeneratorPrompts.php` | Class | Modify — add 3 new static methods |
| 12 | `app/AIAssistant/PageGeneratorController.php` | Class | Modify — element mode in chat + create |
| 13 | `templates/admin/generator/index.php` | Template | Modify — editor mode toggle |
| 14 | `public/assets/js/page-generator.js` | JavaScript | Modify — editor_mode state + element preview |
| 15 | `app/Admin/ElementController.php` | Class | Modify — 3 new proposal methods |
| 16 | `templates/admin/elements/proposals.php` | Template | Create — proposal review UI |
| 17 | `app/AIAssistant/AIController.php` | Class | Modify — catalogue context in system prompt |

---

## Estimated Scope

- **New PHP classes**: ~260 LOC (ElementPrompts ~80, ElementAIController ~180)
- **New migrations**: ~5 LOC x 3
- **New JS**: ~120 LOC (element-ai-assistant.js)
- **New template**: ~60 LOC (proposals.php)
- **Modified PHP**: ~200 LOC across ConversationManager, GeneratorPrompts, PageGeneratorController, ElementController, AIController
- **Modified templates**: ~60 LOC (edit.php AI panel, generator index.php mode toggle)
- **Modified JS**: ~80 LOC (page-generator.js element mode)
- **Modified CSS**: ~10 LOC
- **Approximate total LOC change**: ~800–1,000 lines
