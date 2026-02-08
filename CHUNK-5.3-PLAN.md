# Chunk 5.3 — AI Page Generator
## Detailed Implementation Plan

---

## Overview

This chunk builds a conversational AI agent flow accessible from the admin panel that guides users through creating a new webpage. The agent asks iterative questions (purpose, sections, style, content details), generates HTML content and metadata, and inserts a complete content record into the database. The result is immediately editable via the standard content editor and AI chat companion.

**Prerequisites**: Chunks 4.1 (Claude API client), 4.2 (AI chat panel frontend), 5.1 (custom content types), 5.2 (settings panel) — all complete.

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `app/AIAssistant/GeneratorPrompts.php`

**Purpose**: Encapsulates all system prompts used by the page generator. Separates prompt engineering from controller logic. Provides context-aware prompt building using site data, existing pages, and content type field definitions.

```php
<?php declare(strict_types=1);

namespace App\AIAssistant;

class GeneratorPrompts
{
    /**
     * System prompt for the requirement-gathering phase.
     * The AI acts as a web content consultant, asking questions about what the user needs.
     */
    public static function gatheringPrompt(string $siteName, array $existingPages, ?array $contentTypeFields): string
    {
        // Builds a prompt that:
        // 1. Introduces the AI as a web content planning assistant for {siteName}
        // 2. Instructs it to ask about: page purpose, target audience, key sections, tone/style, specific content
        // 3. Lists existing pages so the AI can suggest how new content fits the site
        // 4. If a custom content type is selected, describes the custom fields that need values
        // 5. Instructs the AI to ask 2-3 questions per turn (not overwhelm the user)
        // 6. Instructs the AI to say "READY_TO_GENERATE" when it has enough info
    }

    /**
     * System prompt for the HTML generation phase.
     * Given the gathered requirements, produces the final content.
     */
    public static function generationPrompt(string $siteName, string $contentType, ?array $contentTypeFields): string
    {
        // Builds a prompt that instructs the AI to:
        // 1. Generate a complete, clean HTML body (no <html>, <head>, <body> wrapper — just inner content)
        // 2. Use semantic HTML5 (h2, h3, p, ul, ol, section, figure, blockquote)
        // 3. NOT use inline styles — rely on the site's existing CSS classes
        // 4. Include sensible placeholder text where user didn't provide specifics
        // 5. Output in a structured JSON format (see generation output format below)
        // 6. If custom type, include values for each custom field
    }

    /**
     * Formats existing pages into a concise context string for the gathering prompt.
     */
    public static function formatExistingPages(array $pages): string
    {
        // Returns: "Existing pages on this site: Home (/), About (/about), Services (/services), Blog (/blog)"
        // Or "No pages exist yet." if empty
    }

    /**
     * Formats custom field definitions into a description for the AI.
     */
    public static function formatCustomFields(array $fields): string
    {
        // Returns e.g.: "This content type has these custom fields:
        //   - price (text, required): The product price
        //   - description (textarea): Product description
        //   - featured (boolean): Whether to feature this item"
    }
}
```

**Implementation Details**:

`gatheringPrompt()`:
```
You are a professional web content planning assistant for "{siteName}".
Your job is to help the user plan a new webpage by asking smart questions.

Existing pages on this site: {existingPagesList}

{customFieldsDescription (if applicable)}

Guidelines:
- Ask 2-3 focused questions per message. Do not overwhelm the user.
- Start by asking about the page's purpose and target audience.
- Then ask about desired sections, key content points, and tone/style preferences.
- If the user is vague, suggest concrete options (e.g., "Would you like a hero section with a headline and call-to-action, or a more informational layout?").
- Consider how this page fits with the existing site structure.
- When you have enough information to generate the page, respond with exactly the marker "READY_TO_GENERATE" on a line by itself at the END of your message (after any final confirmation to the user).
- Do NOT generate the page content during this phase — only gather requirements.
```

`generationPrompt()`:
```
You are a professional web content generator for "{siteName}".
Generate a complete webpage based on the conversation history.

Content type: {contentType}
{customFieldsDescription (if applicable)}

You MUST respond with ONLY a JSON object (no markdown fences, no extra text) in this exact format:
{
  "title": "Page Title",
  "slug": "page-title",
  "excerpt": "A 1-2 sentence summary of the page for SEO and listings.",
  "meta_title": "SEO-optimized title (50-60 chars)",
  "meta_description": "SEO-optimized description (150-160 chars)",
  "body": "<section>...full HTML content...</section>",
  "custom_fields": {"field_key": "value", ...}
}

HTML body rules:
- Use semantic HTML5: <section>, <h2>, <h3>, <p>, <ul>, <ol>, <figure>, <blockquote>
- Do NOT use <h1> (the page title is rendered separately by the template)
- Do NOT use inline styles or style attributes
- Do NOT wrap in <html>, <head>, or <body> tags
- Keep markup clean and minimal — the site's CSS will handle styling
- Use real, contextual content — not "Lorem ipsum" unless the user asked for placeholder text
```

**Notes**:
- Prompts are static methods returning strings — no state needed.
- The `READY_TO_GENERATE` marker is how the controller detects the AI is done gathering info.
- The JSON output format for generation phase makes parsing reliable.

---

### 2. `app/AIAssistant/PageGeneratorController.php`

**Purpose**: Multi-step wizard endpoint. Manages conversation state, calls Claude API via `ClaudeClient`, and creates content records on completion. This is the main backend for the page generator feature.

**Class**: `App\AIAssistant\PageGeneratorController`

**Properties**:
```
private App $app
```

**Constructor**:
```php
public function __construct(App $app)
```

**Public Methods**:

```php
/**
 * GET /admin/generator
 * Renders the generator wizard UI.
 */
public function index(Request $request): Response
```
- Fetches content types (built-in page/post + custom types from `content_types` table)
- Renders `admin/generator/index` template with:
  - `title` => 'Generate Page'
  - `activeNav` => 'generator'
  - `contentTypes` => array of available types (slug => name)
  - `csrfToken` => current CSRF token

```php
/**
 * POST /admin/generator/chat
 * Handles a single chat message in the generator conversation.
 * Manages the gathering → generation flow.
 *
 * Request JSON body:
 *   message: string         — user's message
 *   conversation_id: ?int   — null on first message
 *   content_type: string    — 'page', 'post', or custom type slug
 *   step: string            — 'gathering' or 'generating'
 *
 * Response JSON:
 *   success: bool
 *   response: string        — AI's response text
 *   conversation_id: int
 *   step: string            — current step ('gathering' or 'ready' or 'generated')
 *   generated: ?object      — null during gathering; {title, slug, excerpt, ...} when generated
 */
public function chat(Request $request): Response
```

**Chat method flow**:
1. Parse JSON body, validate `message` is present.
2. Get API key via `getApiKey()` — return error if missing.
3. Create `ClaudeClient` with key and model from settings.
4. Find or create conversation via `ConversationManager`:
   - If `conversation_id` is null: create new conversation with `content_id = null` (generator conversations aren't tied to existing content).
   - Otherwise: load existing conversation.
5. Append user message to conversation.
6. Determine current phase:
   - If `step === 'gathering'`: Use the gathering system prompt. Send conversation history to Claude. Check if response contains `READY_TO_GENERATE`. If yes, return `step: 'ready'`. Otherwise return `step: 'gathering'`.
   - If `step === 'generating'`: User has confirmed they want to generate. Build a new system prompt using `GeneratorPrompts::generationPrompt()`. Send the full conversation history (gathering phase messages provide the context). Parse the JSON response into structured data. Return `step: 'generated'` with the parsed content.
7. Append assistant message to conversation.
8. Return JSON response.

```php
/**
 * POST /admin/generator/create
 * Creates the content record from generated data.
 *
 * Request JSON body:
 *   title: string
 *   slug: string
 *   body: string (HTML)
 *   excerpt: string
 *   meta_title: string
 *   meta_description: string
 *   content_type: string
 *   status: string ('draft' or 'published')
 *   custom_fields: ?object
 *
 * Response JSON:
 *   success: bool
 *   content_id: int
 *   edit_url: string
 */
public function create(Request $request): Response
```

**Create method flow**:
1. Parse JSON body. Validate required fields (title, body, content_type, status).
2. Generate slug from title if not provided. Ensure unique slug (reuse `ContentController` pattern).
3. Determine `published_at`: if status is `published`, set to current datetime; otherwise null.
4. Insert into `content` table:
   ```php
   $id = QueryBuilder::query('content')->insert([
       'type'             => $data['content_type'],
       'title'            => $data['title'],
       'slug'             => $slug,
       'body'             => $data['body'],
       'excerpt'          => $data['excerpt'] ?? '',
       'status'           => $data['status'],
       'author_id'        => (int) Session::get('user_id'),
       'sort_order'       => 0,
       'meta_title'       => $data['meta_title'] ?? null,
       'meta_description' => $data['meta_description'] ?? null,
       'featured_image'   => null,
       'published_at'     => $publishedAt,
       'updated_at'       => date('Y-m-d H:i:s'),
   ]);
   ```
5. If custom fields provided, insert each into `custom_fields` table:
   ```php
   foreach ($data['custom_fields'] as $key => $value) {
       QueryBuilder::query('custom_fields')->insert([
           'content_id'  => (int) $id,
           'field_key'   => $key,
           'field_value' => is_string($value) ? $value : '',
       ]);
   }
   ```
6. Return JSON with `content_id` and `edit_url` (`/admin/content/{id}/edit`).

**Private Methods**:

```php
/**
 * Gets the decrypted API key from settings or config.
 */
private function getApiKey(): string
```
- Same pattern as `AIController::getApiKey()` — check settings table first (decrypt), fall back to config.

```php
/**
 * Gets the configured model name from settings or config.
 */
private function getModel(): string
```
- Reads `claude_model` from settings/config. Defaults to `claude-sonnet-4-20250514`.

```php
/**
 * Gets existing published pages for context.
 * Returns array of ['title' => ..., 'slug' => ...].
 */
private function getExistingPages(): array
```
- `QueryBuilder::query('content')->select('title', 'slug')->where('status', 'published')->orderBy('sort_order')->get()`

```php
/**
 * Gets custom field definitions for a content type.
 * Returns decoded fields_json array or null for built-in types.
 */
private function getContentTypeFields(string $typeSlug): ?array
```
- For `page` and `post`: return `null` (no custom fields).
- For custom types: query `content_types` table for `slug = $typeSlug`, decode `fields_json`.

```php
/**
 * Generates a URL-safe slug from a title string.
 */
private function generateSlug(string $title): string
```
- Lowercase, replace non-alphanumeric with hyphens, collapse multiple hyphens, trim hyphens.

```php
/**
 * Ensures a slug is unique by appending -2, -3, etc.
 */
private function ensureUniqueSlug(string $slug): string
```
- Query `content` table for existing slug. If found, try `$slug-2`, `$slug-3`, etc.

```php
/**
 * Adds security headers to a response.
 */
private function withSecurityHeaders(Response $response): Response
```
- Same CSP pattern as other admin controllers.

```php
/**
 * Parses the AI's generation JSON output.
 * Returns structured array or null on parse failure.
 */
private function parseGeneratedContent(string $aiResponse): ?array
```
- Attempts `json_decode()` on the response.
- If the AI wrapped it in markdown code fences, strips those first.
- Validates required keys exist: `title`, `body`.
- Sets defaults for optional keys: `slug` (generated from title), `excerpt` (''), `meta_title` (same as title), `meta_description` ('').

---

### 3. `templates/admin/generator/index.php`

**Purpose**: Generator wizard UI — a chat-style interface with content type selector, progress steps, chat messages area, preview pane, and create button.

**Template layout**:
```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Generate Page</h1>
    <p class="page-description">Use AI to create a new page through a guided conversation.</p>
</div>

<div class="generator-container" id="generator-app"
     data-csrf="<?= $this->e($csrfToken) ?>">

    <!-- Step indicator -->
    <div class="generator-steps">
        <div class="step active" data-step="setup">1. Setup</div>
        <div class="step" data-step="gathering">2. Describe</div>
        <div class="step" data-step="preview">3. Preview</div>
        <div class="step" data-step="created">4. Done</div>
    </div>

    <!-- Step 1: Setup (content type selection) -->
    <div class="generator-panel" id="step-setup">
        <h2>What would you like to create?</h2>
        <div class="type-selector">
            <?php foreach ($contentTypes as $slug => $name): ?>
                <button class="type-option" data-type="<?= $this->e($slug) ?>">
                    <?= $this->e($name) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Step 2: Chat interface (requirement gathering) -->
    <div class="generator-panel hidden" id="step-gathering">
        <div class="generator-chat">
            <div id="generator-messages" class="chat-messages"></div>
            <div class="chat-input-area">
                <textarea id="generator-input" placeholder="Describe what you need..."
                          rows="2"></textarea>
                <button id="generator-send" type="button">Send</button>
            </div>
        </div>
    </div>

    <!-- Step 3: Preview + create -->
    <div class="generator-panel hidden" id="step-preview">
        <div class="preview-header">
            <h2>Preview</h2>
            <div class="preview-meta">
                <div><strong>Title:</strong> <span id="preview-title"></span></div>
                <div><strong>Slug:</strong> <span id="preview-slug"></span></div>
                <div><strong>Excerpt:</strong> <span id="preview-excerpt"></span></div>
                <div><strong>Meta Title:</strong> <span id="preview-meta-title"></span></div>
                <div><strong>Meta Description:</strong> <span id="preview-meta-desc"></span></div>
            </div>
        </div>
        <div id="preview-body" class="preview-content"></div>
        <div class="preview-actions">
            <button id="btn-back-to-chat" type="button" class="btn btn-secondary">Back to Chat</button>
            <button id="btn-create-draft" type="button" class="btn btn-secondary">Create as Draft</button>
            <button id="btn-create-publish" type="button" class="btn btn-primary">Create &amp; Publish</button>
        </div>
    </div>

    <!-- Step 4: Success -->
    <div class="generator-panel hidden" id="step-created">
        <div class="success-message">
            <h2>Page Created!</h2>
            <p>Your content has been created successfully.</p>
            <div class="success-actions">
                <a id="btn-edit-content" href="#" class="btn btn-primary">Edit in Editor</a>
                <a href="/admin/generator" class="btn btn-secondary">Generate Another</a>
            </div>
        </div>
    </div>

</div>

<script src="/assets/js/page-generator.js"></script>
```

**Key UI patterns**:
- Step indicator at the top shows progress (Setup → Describe → Preview → Done).
- Only one step panel is visible at a time (`hidden` class toggles).
- Chat interface reuses the visual style from the AI assistant panel (message bubbles, loading indicator).
- Preview pane renders the HTML body safely and shows all metadata fields.
- Create buttons trigger the `/admin/generator/create` endpoint.

---

### 4. `public/assets/js/page-generator.js`

**Purpose**: Frontend logic for the generator wizard — step management, API calls, chat display, live preview rendering, and content creation trigger.

**Structure**:
```javascript
(function() {
    'use strict';

    // --- State ---
    var csrfToken = '';
    var conversationId = null;
    var contentType = '';
    var currentStep = 'setup';    // 'setup' | 'gathering' | 'preview' | 'created'
    var generatedData = null;      // Parsed JSON from generation step
    var isLoading = false;

    // --- DOM references ---
    // (cached on DOMContentLoaded)
    var appEl, stepsEls, panelEls;
    var messagesEl, inputEl, sendBtn;
    var previewEls;

    // --- Initialization ---
    function init() { ... }

    // --- Step Management ---
    function goToStep(step) { ... }
    // Activates the correct step indicator and shows/hides panels.

    // --- Setup Step ---
    function onTypeSelected(typeSlug) { ... }
    // Sets contentType, transitions to gathering step, sends initial prompt.

    // --- Chat (Gathering Step) ---

    function sendMessage() { ... }
    // 1. Get message from input
    // 2. Display user message bubble
    // 3. Show loading indicator
    // 4. POST to /admin/generator/chat with {message, conversation_id, content_type, step: 'gathering'}
    // 5. On response:
    //    - Display AI response bubble
    //    - Update conversation_id
    //    - If step === 'ready': show "Generate" button in chat (the AI said it has enough info)

    function requestGeneration() { ... }
    // 1. Show loading indicator ("Generating your page...")
    // 2. POST to /admin/generator/chat with {message: 'Generate the page now.', conversation_id, content_type, step: 'generating'}
    // 3. On response:
    //    - Parse generated data
    //    - Store in generatedData
    //    - Populate preview
    //    - Go to preview step

    function appendMessage(role, content) { ... }
    // Creates a chat bubble div with appropriate class.
    // AI messages: innerHTML (may contain formatting).
    // User messages: textContent (plain text).
    // Auto-scrolls messages container.

    function showLoading() { ... }
    function hideLoading() { ... }
    // Adds/removes a loading indicator in the messages area.

    // --- Preview Step ---

    function populatePreview(data) { ... }
    // Sets preview-title, preview-slug, preview-excerpt, preview-meta-title, preview-meta-desc
    // Sets preview-body innerHTML to data.body
    // If data.custom_fields exists, renders those below the body preview

    // --- Create Step ---

    function createContent(status) { ... }
    // 1. POST to /admin/generator/create with:
    //    {title, slug, body, excerpt, meta_title, meta_description, content_type, status, custom_fields}
    // 2. On success:
    //    - Store the edit_url
    //    - Set the "Edit in Editor" link href
    //    - Go to 'created' step
    // 3. On error:
    //    - Show error message

    // --- Event Listeners ---
    // Type selection buttons: click → onTypeSelected()
    // Send button: click → sendMessage()
    // Input textarea: Enter (without Shift) → sendMessage(); Shift+Enter → newline
    // "Generate" button (appears in chat): click → requestGeneration()
    // "Back to Chat" button: click → goToStep('gathering')
    // "Create as Draft" button: click → createContent('draft')
    // "Create & Publish" button: click → createContent('published')

    document.addEventListener('DOMContentLoaded', init);
})();
```

**Fetch call pattern** (consistent with existing `ai-assistant.js`):
```javascript
function apiCall(url, data) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify(data)
    }).then(function(res) { return res.json(); });
}
```

**"Generate" button insertion**:
When the AI response contains `READY_TO_GENERATE`, the JS:
1. Strips the marker from the displayed message.
2. Appends a special "Generate Page" button below the last AI message.
3. Clicking this button calls `requestGeneration()`.

---

### 5. Update `templates/admin/layout.php`

**Purpose**: Add "Generate Page" link to the sidebar navigation.

**Change**: Insert a new nav link in the "Content" section, after "Content Types":

```php
<!-- Existing -->
<a href="/admin/content-types" class="<?= ($activeNav ?? '') === 'content-types' ? 'active' : '' ?>">
    <span class="nav-icon">&#128209;</span> Content Types
</a>
<!-- NEW: Add this line -->
<a href="/admin/generator" class="<?= ($activeNav ?? '') === 'generator' ? 'active' : '' ?>">
    <span class="nav-icon">&#9733;</span> Generate Page
</a>
```

**Notes**:
- Uses `&#9733;` (★ star) icon to visually distinguish the AI feature.
- Placed in the "Content" section since it creates content.
- `activeNav === 'generator'` highlights when on the generator page.

---

### 6. Update `public/index.php`

**Purpose**: Register routes for the page generator.

**Change**: Add three routes inside the existing `/admin` route group:

```php
// AI Page Generator
$router->get('/generator', [PageGeneratorController::class, 'index']);
$router->post('/generator/chat', [PageGeneratorController::class, 'chat']);
$router->post('/generator/create', [PageGeneratorController::class, 'create']);
```

**Notes**:
- Add the `use App\AIAssistant\PageGeneratorController;` import at the top of the file alongside existing controller imports.
- These routes sit inside the `/admin` group, so they inherit the auth middleware protection.

---

### 7. Update `public/assets/css/admin.css`

**Purpose**: Add styles for the generator wizard UI.

**New CSS rules** (append to existing file):

```css
/* === Page Generator === */

.generator-container {
    max-width: 900px;
    margin: 0 auto;
}

/* Step indicator */
.generator-steps {
    display: flex;
    gap: 0;
    margin-bottom: 2rem;
    border-bottom: 2px solid var(--border-color, #ddd);
    padding-bottom: 0;
}
.generator-steps .step {
    flex: 1;
    text-align: center;
    padding: 0.75rem 1rem;
    color: var(--text-muted, #888);
    font-size: 0.9rem;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
}
.generator-steps .step.active {
    color: var(--primary-color, #4a90d9);
    border-bottom-color: var(--primary-color, #4a90d9);
    font-weight: 600;
}
.generator-steps .step.completed {
    color: var(--success-color, #28a745);
    border-bottom-color: var(--success-color, #28a745);
}

/* Type selector (setup step) */
.type-selector {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 1rem;
    margin-top: 1.5rem;
}
.type-option {
    padding: 1.5rem 1rem;
    border: 2px solid var(--border-color, #ddd);
    border-radius: 8px;
    background: var(--card-bg, #fff);
    cursor: pointer;
    font-size: 1rem;
    text-align: center;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.type-option:hover {
    border-color: var(--primary-color, #4a90d9);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Chat interface (gathering step) */
.generator-chat {
    display: flex;
    flex-direction: column;
    height: 500px;
    border: 1px solid var(--border-color, #ddd);
    border-radius: 8px;
    overflow: hidden;
}
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
}
.chat-message {
    margin-bottom: 1rem;
    max-width: 80%;
}
.chat-message-user {
    margin-left: auto;
    text-align: right;
}
.chat-message-user .chat-bubble {
    background: var(--primary-color, #4a90d9);
    color: #fff;
    border-radius: 12px 12px 4px 12px;
    display: inline-block;
    padding: 0.75rem 1rem;
    text-align: left;
}
.chat-message-assistant .chat-bubble {
    background: var(--card-bg, #f5f5f5);
    border-radius: 12px 12px 12px 4px;
    display: inline-block;
    padding: 0.75rem 1rem;
}
.chat-input-area {
    display: flex;
    gap: 0.5rem;
    padding: 0.75rem;
    border-top: 1px solid var(--border-color, #ddd);
    background: var(--card-bg, #fff);
}
.chat-input-area textarea {
    flex: 1;
    resize: none;
    border: 1px solid var(--border-color, #ddd);
    border-radius: 6px;
    padding: 0.5rem;
    font-family: inherit;
    font-size: 0.95rem;
}
.chat-input-area button {
    padding: 0.5rem 1.5rem;
    white-space: nowrap;
}
.chat-loading {
    text-align: center;
    padding: 1rem;
    color: var(--text-muted, #888);
    font-style: italic;
}

/* Generate button (appears in chat) */
.btn-generate {
    display: block;
    margin: 1rem auto;
    padding: 0.75rem 2rem;
    font-size: 1rem;
    font-weight: 600;
}

/* Preview step */
.preview-header {
    margin-bottom: 1.5rem;
}
.preview-meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
    margin-top: 1rem;
    padding: 1rem;
    background: var(--card-bg, #f5f5f5);
    border-radius: 8px;
    font-size: 0.9rem;
}
.preview-content {
    border: 1px solid var(--border-color, #ddd);
    border-radius: 8px;
    padding: 2rem;
    margin: 1.5rem 0;
    background: #fff;
    min-height: 200px;
}
.preview-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 1.5rem;
}

/* Success step */
.success-message {
    text-align: center;
    padding: 3rem;
}
.success-message h2 {
    color: var(--success-color, #28a745);
    margin-bottom: 0.5rem;
}
.success-actions {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 2rem;
}

/* Hidden utility */
.generator-panel.hidden {
    display: none;
}
```

---

## Detailed Class Specifications

### `App\AIAssistant\GeneratorPrompts`

```
STATIC METHODS:

  - public static gatheringPrompt(
        string $siteName,
        array $existingPages,       // [['title'=>..., 'slug'=>...], ...]
        ?array $contentTypeFields   // null for page/post, decoded fields_json for custom types
    ): string
      Returns the system prompt for requirement-gathering phase.
      Embeds site name, existing pages list, and custom field descriptions.

  - public static generationPrompt(
        string $siteName,
        string $contentType,        // 'page', 'post', or custom type slug
        ?array $contentTypeFields
    ): string
      Returns the system prompt for the HTML generation phase.
      Instructs AI to output a strict JSON format.

  - public static formatExistingPages(array $pages): string
      Formats page list into a readable string.
      Example: "Home (/), About (/about), Services (/services)"

  - public static formatCustomFields(array $fields): string
      Formats field definitions into a description.
      Each field listed with key, type, required flag, and options (for select).
```

### `App\AIAssistant\PageGeneratorController`

```
PROPERTIES:
  - private App $app

CONSTRUCTOR:
  __construct(App $app)

PUBLIC METHODS:

  - index(Request $request): Response
      Renders the generator page.
      Fetches available content types:
        1. Built-in: ['page' => 'Page', 'post' => 'Blog Post']
        2. Custom: query content_types table, add slug => name pairs
      Renders admin/generator/index with {title, activeNav, contentTypes, csrfToken}.

  - chat(Request $request): Response
      Handles chat messages (gathering and generation phases).
      Reads JSON from php://input.
      Returns JSON response.

  - create(Request $request): Response
      Creates content record from generated data.
      Reads JSON from php://input.
      Inserts into content table + custom_fields table.
      Returns JSON with {success, content_id, edit_url}.

PRIVATE METHODS:

  - getApiKey(): string
      Checks settings table for encrypted claude_api_key.
      Falls back to Config::getString('claude_api_key').
      Same pattern as AIController::getApiKey().

  - getModel(): string
      Reads claude_model from settings/config.
      Defaults to 'claude-sonnet-4-20250514'.

  - getExistingPages(): array
      SELECT title, slug FROM content WHERE status = 'published' ORDER BY sort_order.
      Returns array of associative arrays.

  - getContentTypeFields(string $typeSlug): ?array
      For 'page'/'post': returns null.
      For custom types: queries content_types table, json_decodes fields_json.

  - generateSlug(string $title): string
      strtolower, preg_replace non-alphanumeric to hyphens, trim hyphens.

  - ensureUniqueSlug(string $slug): string
      Checks content table. Appends -2, -3 etc. until unique.

  - parseGeneratedContent(string $aiResponse): ?array
      Strips markdown code fences if present.
      json_decode. Validates required keys.
      Sets defaults for optional keys.

  - withSecurityHeaders(Response $response): Response
      Adds X-Frame-Options, Content-Security-Policy, X-Content-Type-Options.
```

---

## Complete Code Templates

### `app/AIAssistant/GeneratorPrompts.php`

```php
<?php declare(strict_types=1);

namespace App\AIAssistant;

class GeneratorPrompts
{
    public static function gatheringPrompt(string $siteName, array $existingPages, ?array $contentTypeFields): string
    {
        $pagesList = self::formatExistingPages($existingPages);
        $fieldsDesc = $contentTypeFields !== null ? "\n\n" . self::formatCustomFields($contentTypeFields) : '';

        return <<<PROMPT
You are a professional web content planning assistant for "{$siteName}".
Your job is to help the user plan a new webpage by asking smart, focused questions.

{$pagesList}{$fieldsDesc}

Guidelines:
- Ask 2-3 focused questions per message. Do not overwhelm the user with too many questions at once.
- Start by asking about the page's purpose and target audience.
- Then progressively ask about: desired sections/structure, key content points, tone/style preferences, and any specific details they want included.
- If the user is vague, suggest concrete options (e.g., "Would you like a hero section with a headline and call-to-action, or a more informational layout with sections?").
- Consider how this new page fits with the existing site structure.
- Keep your responses conversational and helpful. Be encouraging.
- When you have gathered enough information to generate a complete, high-quality page, include the exact marker READY_TO_GENERATE on its own line at the END of your message. Before the marker, give the user a brief summary of what you'll generate so they can confirm or adjust.
- Do NOT generate the actual page content during this phase — only gather and confirm requirements.
PROMPT;
    }

    public static function generationPrompt(string $siteName, string $contentType, ?array $contentTypeFields): string
    {
        $fieldsDesc = '';
        $fieldsJson = '';
        if ($contentTypeFields !== null) {
            $fieldsDesc = "\n\n" . self::formatCustomFields($contentTypeFields);
            $keys = array_map(fn($f) => '"' . ($f['key'] ?? 'field') . '": "value"', $contentTypeFields);
            $fieldsJson = ', "custom_fields": {' . implode(', ', $keys) . '}';
        }

        return <<<PROMPT
You are a professional web content generator for "{$siteName}".
Based on the entire conversation above, generate a complete webpage.

Content type: {$contentType}{$fieldsDesc}

You MUST respond with ONLY a valid JSON object (no markdown code fences, no explanatory text before or after) in this exact format:
{"title": "Page Title", "slug": "page-title", "excerpt": "A 1-2 sentence summary for SEO and listings.", "meta_title": "SEO title (50-60 chars)", "meta_description": "SEO description (150-160 chars)", "body": "<section>...full HTML content here...</section>"{$fieldsJson}}

HTML body rules:
- Use semantic HTML5 tags: section, h2, h3, p, ul, ol, figure, blockquote, strong, em
- Do NOT use h1 (the page title is rendered separately by the site template)
- Do NOT use inline styles, style attributes, or class attributes
- Do NOT wrap in html, head, or body tags — just the inner content sections
- Keep markup clean, minimal, and well-structured
- Write real, contextual, professional content — not Lorem ipsum
- Organize content into logical sections using <section> tags
- Ensure the content is ready to publish on a professional business website
PROMPT;
    }

    public static function formatExistingPages(array $pages): string
    {
        if (empty($pages)) {
            return 'This is a new site with no pages yet.';
        }

        $list = array_map(
            fn(array $p) => ($p['title'] ?? 'Untitled') . ' (/' . ltrim($p['slug'] ?? '', '/') . ')',
            $pages
        );

        return 'Existing pages on this site: ' . implode(', ', $list) . '.';
    }

    public static function formatCustomFields(array $fields): string
    {
        if (empty($fields)) {
            return '';
        }

        $lines = ['This content type has the following custom fields that need values:'];
        foreach ($fields as $field) {
            $key = $field['key'] ?? 'unknown';
            $type = $field['type'] ?? 'text';
            $label = $field['label'] ?? $key;
            $required = !empty($field['required']) ? ', required' : '';
            $options = '';
            if ($type === 'select' && !empty($field['options'])) {
                $optList = is_array($field['options']) ? $field['options'] : [];
                $options = ' (options: ' . implode(', ', $optList) . ')';
            }
            $lines[] = "- {$label} ({$type}{$required}){$options}";
        }

        return implode("\n", $lines);
    }
}
```

### `app/AIAssistant/PageGeneratorController.php`

```php
<?php declare(strict_types=1);

namespace App\AIAssistant;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Auth\Session;
use App\Database\QueryBuilder;

class PageGeneratorController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $contentTypes = ['page' => 'Page', 'post' => 'Blog Post'];

        // Add custom content types
        $custom = QueryBuilder::query('content_types')
            ->select('slug', 'name')
            ->orderBy('name')
            ->get();
        foreach ($custom as $ct) {
            $contentTypes[$ct['slug']] = $ct['name'];
        }

        $html = $this->app->template()->render('admin/generator/index', [
            'title'        => 'Generate Page',
            'activeNav'    => 'generator',
            'contentTypes' => $contentTypes,
            'csrfToken'    => Session::csrfToken(),
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    public function chat(Request $request): Response
    {
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        if (!is_array($data) || empty(trim($data['message'] ?? ''))) {
            return Response::json(['success' => false, 'error' => 'Message is required.'], 400);
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            return Response::json([
                'success' => false,
                'error'   => 'Claude API key is not configured. Please set it in Settings.',
            ], 400);
        }

        $message      = trim($data['message']);
        $convId       = $data['conversation_id'] ?? null;
        $contentType  = $data['content_type'] ?? 'page';
        $step         = $data['step'] ?? 'gathering';

        $model  = $this->getModel();
        $client = new ClaudeClient($apiKey, $model);
        $manager = new ConversationManager();

        // Find or create conversation
        $userId = (int) Session::get('user_id');
        if ($convId !== null) {
            $conversation = $manager->findById((int) $convId);
            if ($conversation === null || (int) $conversation['user_id'] !== $userId) {
                return Response::json(['success' => false, 'error' => 'Conversation not found.'], 404);
            }
        } else {
            $conversation = $manager->findOrCreate($userId, null);
        }

        $convId = (int) $conversation['id'];

        // Append user message
        $manager->appendMessage($convId, 'user', $message);

        // Build message history for API
        $allMessages = $manager->getMessages(
            $manager->findById($convId) ?? $conversation
        );
        $apiMessages = [];
        foreach ($allMessages as $msg) {
            $apiMessages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Build system prompt based on step
        $existingPages = $this->getExistingPages();
        $typeFields = $this->getContentTypeFields($contentType);
        $siteName = Config::getString('site_name', 'LiteCMS');

        if ($step === 'generating') {
            $systemPrompt = GeneratorPrompts::generationPrompt($siteName, $contentType, $typeFields);
        } else {
            $systemPrompt = GeneratorPrompts::gatheringPrompt($siteName, $existingPages, $typeFields);
        }

        try {
            $result = $client->sendMessage($apiMessages, $systemPrompt);
        } catch (\RuntimeException $e) {
            return Response::json([
                'success' => false,
                'error'   => 'AI request failed: ' . $e->getMessage(),
            ], 502);
        }

        $aiContent = $result['content'] ?? '';

        // Append assistant message
        $manager->appendMessage($convId, 'assistant', $aiContent);

        // Determine response step
        $responseStep = 'gathering';
        $generated = null;

        if ($step === 'generating') {
            $generated = $this->parseGeneratedContent($aiContent);
            if ($generated !== null) {
                $responseStep = 'generated';
                // Ensure slug
                if (empty($generated['slug'])) {
                    $generated['slug'] = $this->generateSlug($generated['title'] ?? 'untitled');
                }
            } else {
                // Parsing failed — ask the AI to retry or show raw response
                $responseStep = 'generation_failed';
            }
        } elseif (str_contains($aiContent, 'READY_TO_GENERATE')) {
            $responseStep = 'ready';
            // Strip marker from displayed message
            $aiContent = trim(str_replace('READY_TO_GENERATE', '', $aiContent));
        }

        return Response::json([
            'success'         => true,
            'response'        => $aiContent,
            'conversation_id' => $convId,
            'step'            => $responseStep,
            'generated'       => $generated,
        ]);
    }

    public function create(Request $request): Response
    {
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        if (!is_array($data) || empty(trim($data['title'] ?? '')) || empty(trim($data['body'] ?? ''))) {
            return Response::json(['success' => false, 'error' => 'Title and body are required.'], 400);
        }

        $title           = trim($data['title']);
        $contentType     = $data['content_type'] ?? 'page';
        $status          = in_array($data['status'] ?? '', ['draft', 'published']) ? $data['status'] : 'draft';
        $slug            = $this->ensureUniqueSlug(
            $this->generateSlug(!empty($data['slug']) ? $data['slug'] : $title)
        );
        $publishedAt     = $status === 'published' ? date('Y-m-d H:i:s') : null;

        $id = QueryBuilder::query('content')->insert([
            'type'             => $contentType,
            'title'            => $title,
            'slug'             => $slug,
            'body'             => $data['body'],
            'excerpt'          => $data['excerpt'] ?? '',
            'status'           => $status,
            'author_id'        => (int) Session::get('user_id'),
            'sort_order'       => 0,
            'meta_title'       => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'featured_image'   => null,
            'published_at'     => $publishedAt,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        // Save custom fields if present
        $customFields = $data['custom_fields'] ?? [];
        if (is_array($customFields)) {
            foreach ($customFields as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                QueryBuilder::query('custom_fields')->insert([
                    'content_id'  => (int) $id,
                    'field_key'   => $key,
                    'field_value' => is_string($value) ? $value : '',
                ]);
            }
        }

        return Response::json([
            'success'    => true,
            'content_id' => (int) $id,
            'edit_url'   => '/admin/content/' . (int) $id . '/edit',
        ]);
    }

    private function getApiKey(): string
    {
        // Check settings table for encrypted key
        $encrypted = QueryBuilder::query('settings')
            ->select('value')
            ->where('key', 'claude_api_key')
            ->first();

        if ($encrypted !== null && $encrypted['value'] !== '') {
            try {
                return AIController::decrypt($encrypted['value']);
            } catch (\RuntimeException $e) {
                // Fall through to config
            }
        }

        return Config::getString('claude_api_key');
    }

    private function getModel(): string
    {
        $setting = QueryBuilder::query('settings')
            ->select('value')
            ->where('key', 'claude_model')
            ->first();

        if ($setting !== null && $setting['value'] !== '') {
            return $setting['value'];
        }

        return Config::getString('claude_model', 'claude-sonnet-4-20250514');
    }

    private function getExistingPages(): array
    {
        return QueryBuilder::query('content')
            ->select('title', 'slug')
            ->where('status', 'published')
            ->orderBy('sort_order')
            ->get();
    }

    private function getContentTypeFields(string $typeSlug): ?array
    {
        if (in_array($typeSlug, ['page', 'post'], true)) {
            return null;
        }

        $ct = QueryBuilder::query('content_types')
            ->select('fields_json')
            ->where('slug', $typeSlug)
            ->first();

        if ($ct === null) {
            return null;
        }

        $fields = json_decode($ct['fields_json'] ?? '[]', true);
        return is_array($fields) ? $fields : null;
    }

    private function generateSlug(string $title): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }

    private function ensureUniqueSlug(string $slug): string
    {
        $original = $slug;
        $counter = 2;

        while (true) {
            $existing = QueryBuilder::query('content')
                ->select('id')
                ->where('slug', $slug)
                ->first();

            if ($existing === null) {
                break;
            }

            $slug = $original . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function parseGeneratedContent(string $aiResponse): ?array
    {
        $text = trim($aiResponse);

        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text);
            $text = preg_replace('/\n?```\s*$/', '', $text);
            $text = trim($text);
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed) || empty($parsed['title']) || empty($parsed['body'])) {
            return null;
        }

        // Set defaults for optional fields
        return [
            'title'            => $parsed['title'],
            'slug'             => $parsed['slug'] ?? '',
            'body'             => $parsed['body'],
            'excerpt'          => $parsed['excerpt'] ?? '',
            'meta_title'       => $parsed['meta_title'] ?? $parsed['title'],
            'meta_description' => $parsed['meta_description'] ?? '',
            'custom_fields'    => $parsed['custom_fields'] ?? [],
        ];
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net"
            );
    }
}
```

### `templates/admin/generator/index.php`

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1><?= $this->e($title) ?></h1>
    <p style="color:var(--text-muted,#888);margin-top:0.25rem;">
        Use AI to create a new page through a guided conversation.
    </p>
</div>

<div class="generator-container" id="generator-app"
     data-csrf="<?= $this->e($csrfToken) ?>">

    <!-- Step indicator -->
    <div class="generator-steps">
        <div class="step active" id="step-ind-setup">1. Setup</div>
        <div class="step" id="step-ind-gathering">2. Describe</div>
        <div class="step" id="step-ind-preview">3. Preview</div>
        <div class="step" id="step-ind-created">4. Done</div>
    </div>

    <!-- Step 1: Setup -->
    <div class="generator-panel" id="step-setup">
        <h2>What would you like to create?</h2>
        <div class="type-selector">
            <?php foreach ($contentTypes as $slug => $name): ?>
                <button type="button" class="type-option" data-type="<?= $this->e($slug) ?>">
                    <?= $this->e($name) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Step 2: Gathering -->
    <div class="generator-panel hidden" id="step-gathering">
        <div class="generator-chat">
            <div id="generator-messages" class="chat-messages"></div>
            <div class="chat-input-area">
                <textarea id="generator-input" placeholder="Describe what you need..." rows="2"></textarea>
                <button id="generator-send" type="button" class="btn btn-primary">Send</button>
            </div>
        </div>
    </div>

    <!-- Step 3: Preview -->
    <div class="generator-panel hidden" id="step-preview">
        <div class="preview-header">
            <h2>Preview Generated Content</h2>
            <div class="preview-meta">
                <div><strong>Title:</strong> <span id="preview-title"></span></div>
                <div><strong>Slug:</strong> /<span id="preview-slug"></span></div>
                <div><strong>Excerpt:</strong> <span id="preview-excerpt"></span></div>
                <div><strong>Meta Title:</strong> <span id="preview-meta-title"></span></div>
                <div><strong>Meta Desc:</strong> <span id="preview-meta-desc"></span></div>
            </div>
        </div>
        <div id="preview-body" class="preview-content"></div>
        <div id="preview-custom-fields"></div>
        <div class="preview-actions">
            <button id="btn-back-to-chat" type="button" class="btn btn-secondary">Back to Chat</button>
            <button id="btn-create-draft" type="button" class="btn btn-secondary">Create as Draft</button>
            <button id="btn-create-publish" type="button" class="btn btn-primary">Create &amp; Publish</button>
        </div>
    </div>

    <!-- Step 4: Success -->
    <div class="generator-panel hidden" id="step-created">
        <div class="success-message">
            <h2>Page Created Successfully!</h2>
            <p>Your content has been created and is ready for review.</p>
            <div class="success-actions">
                <a id="btn-edit-content" href="#" class="btn btn-primary">Open in Editor</a>
                <a href="/admin/generator" class="btn btn-secondary">Generate Another</a>
                <a href="/admin/content" class="btn btn-secondary">View All Content</a>
            </div>
        </div>
    </div>

</div>

<script src="/assets/js/page-generator.js"></script>
```

### `public/assets/js/page-generator.js`

```javascript
(function() {
    'use strict';

    // --- State ---
    var csrfToken = '';
    var conversationId = null;
    var contentType = '';
    var currentStep = 'setup';
    var generatedData = null;
    var isLoading = false;

    // --- DOM refs ---
    var appEl;
    var messagesEl, inputEl, sendBtn;

    // --- Init ---
    function init() {
        appEl = document.getElementById('generator-app');
        if (!appEl) return;

        csrfToken = appEl.getAttribute('data-csrf') || '';
        messagesEl = document.getElementById('generator-messages');
        inputEl = document.getElementById('generator-input');
        sendBtn = document.getElementById('generator-send');

        // Type selection
        var typeButtons = appEl.querySelectorAll('.type-option');
        for (var i = 0; i < typeButtons.length; i++) {
            typeButtons[i].addEventListener('click', function() {
                onTypeSelected(this.getAttribute('data-type'));
            });
        }

        // Chat input
        if (sendBtn) {
            sendBtn.addEventListener('click', sendMessage);
        }
        if (inputEl) {
            inputEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        }

        // Preview buttons
        var backBtn = document.getElementById('btn-back-to-chat');
        if (backBtn) backBtn.addEventListener('click', function() { goToStep('gathering'); });

        var draftBtn = document.getElementById('btn-create-draft');
        if (draftBtn) draftBtn.addEventListener('click', function() { createContent('draft'); });

        var publishBtn = document.getElementById('btn-create-publish');
        if (publishBtn) publishBtn.addEventListener('click', function() { createContent('published'); });
    }

    // --- Step Management ---
    function goToStep(step) {
        currentStep = step;
        var steps = ['setup', 'gathering', 'preview', 'created'];
        var currentIndex = steps.indexOf(step);

        for (var i = 0; i < steps.length; i++) {
            var panel = document.getElementById('step-' + steps[i]);
            var indicator = document.getElementById('step-ind-' + steps[i]);

            if (panel) {
                if (steps[i] === step) {
                    panel.classList.remove('hidden');
                } else {
                    panel.classList.add('hidden');
                }
            }

            if (indicator) {
                indicator.classList.remove('active', 'completed');
                if (i < currentIndex) {
                    indicator.classList.add('completed');
                } else if (i === currentIndex) {
                    indicator.classList.add('active');
                }
            }
        }

        if (step === 'gathering' && inputEl) {
            inputEl.focus();
        }
    }

    // --- Setup ---
    function onTypeSelected(typeSlug) {
        contentType = typeSlug;
        conversationId = null;
        generatedData = null;

        // Clear any previous chat
        if (messagesEl) messagesEl.innerHTML = '';

        goToStep('gathering');

        // Send initial message to start the conversation
        appendMessage('user', 'I want to create a new ' + typeSlug + '.');
        showLoading();

        apiCall('/admin/generator/chat', {
            message: 'I want to create a new ' + typeSlug + '. Please help me plan it.',
            conversation_id: null,
            content_type: contentType,
            step: 'gathering'
        }).then(function(data) {
            hideLoading();
            if (data.success) {
                conversationId = data.conversation_id;
                appendMessage('assistant', data.response);
                checkReadyState(data);
            } else {
                appendMessage('assistant', 'Error: ' + (data.error || 'Something went wrong.'));
            }
        }).catch(function(err) {
            hideLoading();
            appendMessage('assistant', 'Error: Could not reach the server.');
        });
    }

    // --- Chat ---
    function sendMessage() {
        if (isLoading) return;
        var message = inputEl ? inputEl.value.trim() : '';
        if (message === '') return;

        inputEl.value = '';
        appendMessage('user', message);
        showLoading();

        apiCall('/admin/generator/chat', {
            message: message,
            conversation_id: conversationId,
            content_type: contentType,
            step: 'gathering'
        }).then(function(data) {
            hideLoading();
            if (data.success) {
                conversationId = data.conversation_id;
                appendMessage('assistant', data.response);
                checkReadyState(data);
            } else {
                appendMessage('assistant', 'Error: ' + (data.error || 'Something went wrong.'));
            }
        }).catch(function(err) {
            hideLoading();
            appendMessage('assistant', 'Error: Could not reach the server.');
        });
    }

    function checkReadyState(data) {
        if (data.step === 'ready') {
            // Show the "Generate Page" button
            var btnDiv = document.createElement('div');
            btnDiv.style.textAlign = 'center';
            btnDiv.style.margin = '1rem 0';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-primary btn-generate';
            btn.textContent = 'Generate Page';
            btn.addEventListener('click', requestGeneration);
            btnDiv.appendChild(btn);
            messagesEl.appendChild(btnDiv);
            scrollMessages();
        }
    }

    function requestGeneration() {
        if (isLoading) return;
        showLoading();

        // Disable the generate button if it exists
        var genBtns = messagesEl.querySelectorAll('.btn-generate');
        for (var i = 0; i < genBtns.length; i++) {
            genBtns[i].disabled = true;
        }

        apiCall('/admin/generator/chat', {
            message: 'Generate the page now based on everything we discussed.',
            conversation_id: conversationId,
            content_type: contentType,
            step: 'generating'
        }).then(function(data) {
            hideLoading();
            if (data.success && data.step === 'generated' && data.generated) {
                generatedData = data.generated;
                populatePreview(data.generated);
                goToStep('preview');
            } else if (data.success && data.step === 'generation_failed') {
                appendMessage('assistant', 'I had trouble generating structured output. Let me try again. ' + (data.response || ''));
                // Re-enable generate buttons
                var btns = messagesEl.querySelectorAll('.btn-generate');
                for (var j = 0; j < btns.length; j++) { btns[j].disabled = false; }
            } else {
                appendMessage('assistant', 'Error: ' + (data.error || 'Generation failed.'));
            }
        }).catch(function(err) {
            hideLoading();
            appendMessage('assistant', 'Error: Could not reach the server.');
        });
    }

    // --- Messages UI ---
    function appendMessage(role, content) {
        if (!messagesEl) return;

        var wrapper = document.createElement('div');
        wrapper.className = 'chat-message chat-message-' + role;

        var bubble = document.createElement('div');
        bubble.className = 'chat-bubble';

        if (role === 'assistant') {
            bubble.innerHTML = formatResponse(content);
        } else {
            bubble.textContent = content;
        }

        wrapper.appendChild(bubble);
        messagesEl.appendChild(wrapper);
        scrollMessages();
    }

    function formatResponse(text) {
        // Basic markdown-like formatting
        // Convert **bold** to <strong>
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Convert *italic* to <em>
        text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
        // Convert newlines to <br>
        text = text.replace(/\n/g, '<br>');
        return text;
    }

    function showLoading() {
        isLoading = true;
        var el = document.createElement('div');
        el.className = 'chat-loading';
        el.id = 'generator-loading';
        el.textContent = 'AI is thinking...';
        if (messagesEl) {
            messagesEl.appendChild(el);
            scrollMessages();
        }
        if (sendBtn) sendBtn.disabled = true;
    }

    function hideLoading() {
        isLoading = false;
        var el = document.getElementById('generator-loading');
        if (el) el.remove();
        if (sendBtn) sendBtn.disabled = false;
    }

    function scrollMessages() {
        if (messagesEl) {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }
    }

    // --- Preview ---
    function populatePreview(data) {
        setText('preview-title', data.title || '');
        setText('preview-slug', data.slug || '');
        setText('preview-excerpt', data.excerpt || '');
        setText('preview-meta-title', data.meta_title || '');
        setText('preview-meta-desc', data.meta_description || '');

        var bodyEl = document.getElementById('preview-body');
        if (bodyEl) {
            bodyEl.innerHTML = data.body || '';
        }

        // Custom fields
        var cfEl = document.getElementById('preview-custom-fields');
        if (cfEl && data.custom_fields && Object.keys(data.custom_fields).length > 0) {
            var html = '<h3>Custom Fields</h3><div class="preview-meta">';
            for (var key in data.custom_fields) {
                if (data.custom_fields.hasOwnProperty(key)) {
                    html += '<div><strong>' + escapeHtml(key) + ':</strong> ' +
                            escapeHtml(String(data.custom_fields[key])) + '</div>';
                }
            }
            html += '</div>';
            cfEl.innerHTML = html;
        } else if (cfEl) {
            cfEl.innerHTML = '';
        }
    }

    function setText(id, text) {
        var el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // --- Create ---
    function createContent(status) {
        if (!generatedData || isLoading) return;

        isLoading = true;
        var draftBtn = document.getElementById('btn-create-draft');
        var publishBtn = document.getElementById('btn-create-publish');
        if (draftBtn) draftBtn.disabled = true;
        if (publishBtn) publishBtn.disabled = true;

        apiCall('/admin/generator/create', {
            title:            generatedData.title,
            slug:             generatedData.slug,
            body:             generatedData.body,
            excerpt:          generatedData.excerpt || '',
            meta_title:       generatedData.meta_title || '',
            meta_description: generatedData.meta_description || '',
            content_type:     contentType,
            status:           status,
            custom_fields:    generatedData.custom_fields || {}
        }).then(function(data) {
            isLoading = false;
            if (data.success) {
                var editLink = document.getElementById('btn-edit-content');
                if (editLink) editLink.href = data.edit_url;
                goToStep('created');
            } else {
                alert('Error creating content: ' + (data.error || 'Unknown error'));
                if (draftBtn) draftBtn.disabled = false;
                if (publishBtn) publishBtn.disabled = false;
            }
        }).catch(function(err) {
            isLoading = false;
            alert('Error: Could not reach the server.');
            if (draftBtn) draftBtn.disabled = false;
            if (publishBtn) publishBtn.disabled = false;
        });
    }

    // --- API Helper ---
    function apiCall(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(data)
        }).then(function(res) {
            return res.json();
        });
    }

    // --- Boot ---
    document.addEventListener('DOMContentLoaded', init);
})();
```

---

## Acceptance Test Procedures

### Test 1: Start generator and describe a page
```
1. Log in as admin. Navigate to /admin/generator.
2. Click "Page" to select page as content type.
3. The chat interface appears. AI sends a greeting asking about the page purpose.
4. Reply with "I want an About Us page for a bakery called Sweet Delights".
5. AI asks follow-up questions (team info, history, tone, etc.).
6. Answer the questions over 2-3 messages.
7. Verify: AI conversations are coherent, references previous messages.
```

### Test 2: AI generates complete content with all fields
```
1. Continue from Test 1. After answering questions, AI indicates it's ready.
2. A "Generate Page" button appears in the chat.
3. Click the button.
4. Verify: preview shows populated title, slug, excerpt, meta_title, meta_description, and HTML body.
5. Verify: body HTML is semantic (h2, p, section tags), no inline styles, no h1 tag.
```

### Test 3: Preview shows rendered HTML before creation
```
1. Continue from Test 2. On the preview step:
2. Verify: all metadata fields are displayed (title, slug, excerpt, meta_title, meta_description).
3. Verify: body HTML is rendered visually (not as raw code).
4. Verify: "Back to Chat", "Create as Draft", and "Create & Publish" buttons are visible.
```

### Test 4: Create as Draft
```
1. On the preview step, click "Create as Draft".
2. Verify: success screen appears with "Open in Editor" link.
3. Navigate to /admin/content.
4. Verify: new content item appears in the list with status "draft".
5. Click edit on the item.
6. Verify: all fields are populated — title, slug, body (in TinyMCE), excerpt, meta fields.
```

### Test 5: Create & Publish
```
1. Start a new generation. Go through the flow and click "Create & Publish".
2. Navigate to /admin/content.
3. Verify: content appears with status "published" and published_at set to current time.
4. Visit the public URL (e.g., /about-us) — page renders on the public site.
```

### Test 6: Generator works with custom content types
```
1. First create a custom content type "Products" with fields:
   - price (text, required)
   - description (textarea)
   - featured (boolean)
2. Navigate to /admin/generator.
3. Select "Products" type.
4. Describe a product in the chat conversation.
5. After generation, verify: preview shows custom field values (price, description, featured).
6. Create as draft. Edit the item.
7. Verify: custom field values are saved correctly in the editor.
```

### Test 7: Generated HTML is clean and semantic
```
1. Create a page via the generator.
2. Edit the page, view the HTML source in TinyMCE.
3. Verify: no inline styles, no <h1> tags, no <html>/<head>/<body> wrapper.
4. Verify: uses semantic tags (section, h2, h3, p, ul, etc.).
5. View the page on the public site. Verify: content is styled by the site's CSS.
```

### Test 8: Missing API key shows helpful error
```
1. Remove/clear the Claude API key from Settings.
2. Navigate to /admin/generator, select a type, type a message.
3. Verify: error message appears: "Claude API key is not configured. Please set it in Settings."
```

### Test 9: Sidebar navigation includes Generate Page link
```
1. Log in as admin.
2. Verify: sidebar shows "Generate Page" link in the Content section.
3. Click the link. Verify: navigates to /admin/generator with active nav highlight.
```

---

## Implementation Notes

### Coding Standards
- Every `.php` file starts with `<?php declare(strict_types=1);`
- PSR-4 namespacing: `App\AIAssistant\PageGeneratorController` → `app/AIAssistant/PageGeneratorController.php`
- Follow existing controller patterns (constructor takes `App`, private helper methods)
- No framework imports — native PHP only
- All output escaped with `$this->e()` in templates

### Reuse, Not Duplication
- Reuse `ClaudeClient` from Chunk 4.1 (no new API client code).
- Reuse `ConversationManager` from Chunk 4.1 for storing conversation state.
- Reuse `AIController::encrypt()`/`AIController::decrypt()` for API key handling.
- Reuse the same `QueryBuilder::query()` patterns from `ContentController::store()` for content creation.
- Follow the same `apiCall()` fetch pattern from `ai-assistant.js`.

### Conversation Flow Architecture
The generator uses a two-phase conversation:
1. **Gathering phase**: The AI asks questions using a system prompt that encourages iterative requirement discovery. It signals readiness with a `READY_TO_GENERATE` marker.
2. **Generation phase**: A new system prompt is used that instructs the AI to produce structured JSON output. The full conversation history (from gathering phase) provides the context.

This two-prompt approach ensures the AI's behavior switches cleanly between "ask questions" and "generate output" modes.

### Parsing Robustness
The `parseGeneratedContent()` method handles common AI output quirks:
- Markdown code fences wrapping the JSON (`\`\`\`json ... \`\`\``)
- Missing optional fields (slug, excerpt, meta_title, meta_description)
- Returns `null` on parse failure, allowing the frontend to show a retry option

### Edge Cases
- **Empty conversation**: If user starts generator but never chats, no content is created.
- **API timeout**: ClaudeClient's timeout setting (from settings) applies. Frontend shows error on timeout.
- **Duplicate slug**: `ensureUniqueSlug()` prevents conflicts with existing content.
- **Custom type deleted**: If user selects a custom type that's later deleted, the content is still created with that type slug (the content table allows any string in the type field).
- **Large HTML body**: No artificial size limit — the body TEXT column handles arbitrary length.
- **Conversation cleanup**: Generator conversations (content_id = null) are not automatically cleaned up. This is acceptable; cleanup could be added in Chunk 7.1 if needed.

### Security Considerations
- All API calls require CSRF token (validated by existing middleware).
- User input is not directly injected into SQL (parameterized queries via QueryBuilder).
- Generated HTML body is stored as-is in the database (same as TinyMCE content) — output escaping happens in public templates.
- The preview pane renders HTML via innerHTML, which is safe since it's admin-only content authored by the AI.
- API key decryption reuses the existing secure pattern from AIController.

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `app/AIAssistant/GeneratorPrompts.php` | Class | Create |
| 2 | `app/AIAssistant/PageGeneratorController.php` | Class | Create |
| 3 | `templates/admin/generator/index.php` | Template | Create |
| 4 | `public/assets/js/page-generator.js` | JavaScript | Create |
| 5 | `templates/admin/layout.php` | Template | Update (add nav link) |
| 6 | `public/index.php` | Entry point | Update (add routes) |
| 7 | `public/assets/css/admin.css` | Stylesheet | Update (add generator styles) |

---

## Estimated Scope

- **PHP classes**: 2 new (GeneratorPrompts, PageGeneratorController)
- **Templates**: 1 new (generator/index.php), 1 updated (admin/layout.php)
- **JavaScript**: 1 new (page-generator.js)
- **CSS**: ~120 lines added to admin.css
- **Routes**: 3 new (GET + 2 POST)
- **Approximate new PHP LOC**: ~300-350 lines
- **Approximate new JS LOC**: ~280-320 lines
