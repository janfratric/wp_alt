# Chunk 4.1 — Claude API Client & Backend
## Detailed Implementation Plan

---

## Overview

This chunk builds the AI assistant backend: a thin Claude Messages API client using raw PHP curl (no SDK), a conversation manager for persisting chat history per content item in the existing `ai_conversations` table, the AI controller that handles chat requests from the editor, and a settings controller/page for managing the Claude API key (encrypted at rest) and model selection. At completion, the backend can accept chat messages, call Claude API with content context, return AI responses, and persist conversation history. The API key is stored encrypted in the `settings` table.

---

## Prerequisites (already implemented)

These components from previous chunks are used directly:

- **Database**: `ai_conversations` and `settings` tables exist (migration 001)
- **Config**: `config/app.php` has `claude_api_key`, `claude_model`, and `app_secret` keys
- **Controller pattern**: Controllers receive `App $app` in constructor, methods receive `Request` and return `Response`
- **QueryBuilder**: `QueryBuilder::query('table')` static factory with fluent `->select()`, `->where()`, `->insert()`, `->update()`, `->get()`, `->first()` API
- **Session**: `App\Auth\Session` with `::get()`, `::set()`, `::flash()` static methods
- **TemplateEngine**: `$this->app->template()->render()` with `$this->e()`, `$this->csrfField()`, layout support
- **Routes**: Registered in `public/index.php` inside `$router->group('/admin', ...)`
- **Admin layout**: `templates/admin/layout.php` with sidebar nav (Settings link already exists at `/admin/settings`)
- **Security**: CSRF middleware protects all POST/PUT/DELETE; AuthMiddleware protects `/admin/*`

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `app/AIAssistant/ClaudeClient.php`

**Purpose**: Thin wrapper around the Claude Messages API. Uses raw PHP curl (no SDK). Handles authentication headers, request formatting, error handling. No dependencies on other new chunk 4.1 files.

**Class**: `App\AIAssistant\ClaudeClient`

```php
<?php declare(strict_types=1);

namespace App\AIAssistant;

use RuntimeException;

class ClaudeClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const MAX_TOKENS = 4096;
    private const TIMEOUT_SECONDS = 60;

    private string $apiKey;
    private string $model;

    /**
     * @param string $apiKey  Decrypted Claude API key
     * @param string $model   Model identifier (e.g., 'claude-sonnet-4-20250514')
     */
    public function __construct(string $apiKey, string $model = 'claude-sonnet-4-20250514')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    /**
     * Send a message to the Claude Messages API.
     *
     * @param array  $messages     Array of message objects: [['role'=>'user','content'=>'...'], ...]
     * @param string $systemPrompt System prompt providing context
     * @param int    $maxTokens    Maximum tokens in response
     *
     * @return array{content: string, usage: array, model: string, stop_reason: string}
     *
     * @throws RuntimeException on network error, API error, or invalid response
     */
    public function sendMessage(array $messages, string $systemPrompt = '', int $maxTokens = self::MAX_TOKENS): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Claude API key is not configured.');
        }

        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ];

        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new RuntimeException('Failed to connect to Claude API: ' . $curlError);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = $data['error']['message'] ?? 'Unknown API error';
            $errorType = $data['error']['type'] ?? 'unknown';
            throw new RuntimeException(
                "Claude API error ({$errorType}): {$errorMsg}",
                $httpCode
            );
        }

        if (!isset($data['content'][0]['text'])) {
            throw new RuntimeException('Unexpected Claude API response format.');
        }

        return [
            'content'     => $data['content'][0]['text'],
            'usage'       => $data['usage'] ?? [],
            'model'       => $data['model'] ?? $this->model,
            'stop_reason' => $data['stop_reason'] ?? '',
        ];
    }
}
```

**Properties**:
```
PROPERTIES:
  - private string $apiKey        — Decrypted Claude API key
  - private string $model         — Model identifier string

CONSTANTS:
  - API_URL         = 'https://api.anthropic.com/v1/messages'
  - API_VERSION     = '2023-06-01'
  - MAX_TOKENS      = 4096
  - TIMEOUT_SECONDS = 60
```

**Public API**:
```
__construct(string $apiKey, string $model = 'claude-sonnet-4-20250514')
sendMessage(array $messages, string $systemPrompt = '', int $maxTokens = 4096): array
    Returns: ['content' => string, 'usage' => array, 'model' => string, 'stop_reason' => string]
    Throws:  RuntimeException on any failure
```

**Implementation details**:
- Uses `curl_init()` / `curl_exec()` — no external HTTP libraries.
- Sets `anthropic-version` header as required by the API.
- On non-200 status, parses the error response and throws a descriptive `RuntimeException` with the HTTP code.
- On curl failure (network error), throws with the curl error message.
- Validates response has the expected `content[0].text` structure.
- No streaming support in this chunk — full response only. Streaming could be added later in chunk 4.2 if desired.

---

### 2. `app/AIAssistant/ConversationManager.php`

**Purpose**: CRUD operations for the `ai_conversations` table. Creates, retrieves, appends messages to, and lists conversations per content item.

**Class**: `App\AIAssistant\ConversationManager`

```php
<?php declare(strict_types=1);

namespace App\AIAssistant;

use App\Database\QueryBuilder;

class ConversationManager
{
    /**
     * Find an existing conversation for a user+content pair, or create a new one.
     *
     * @param int      $userId    Current user's ID
     * @param int|null $contentId Content item ID (null for general conversations)
     *
     * @return array The conversation record
     */
    public function findOrCreate(int $userId, ?int $contentId): array
    {
        $qb = QueryBuilder::query('ai_conversations')
            ->select()
            ->where('user_id', $userId);

        if ($contentId !== null) {
            $qb->where('content_id', $contentId);
        } else {
            $qb->whereNull('content_id');
        }

        $conversation = $qb->orderBy('updated_at', 'DESC')->first();

        if ($conversation !== null) {
            return $conversation;
        }

        // Create new conversation
        $id = QueryBuilder::query('ai_conversations')->insert([
            'user_id'       => $userId,
            'content_id'    => $contentId,
            'messages_json' => '[]',
        ]);

        return $this->findById((int) $id);
    }

    /**
     * Find a conversation by ID.
     */
    public function findById(int $id): ?array
    {
        return QueryBuilder::query('ai_conversations')
            ->select()
            ->where('id', $id)
            ->first();
    }

    /**
     * Get the messages array from a conversation record.
     *
     * @param array $conversation The conversation record from the database
     * @return array Array of message objects: [['role'=>'user|assistant','content'=>'...'], ...]
     */
    public function getMessages(array $conversation): array
    {
        $json = $conversation['messages_json'] ?? '[]';
        $messages = json_decode($json, true);

        return is_array($messages) ? $messages : [];
    }

    /**
     * Append a message to a conversation and update the database.
     *
     * @param int    $conversationId The conversation ID
     * @param string $role           'user' or 'assistant'
     * @param string $content        The message text
     *
     * @return array The updated messages array
     */
    public function appendMessage(int $conversationId, string $role, string $content): array
    {
        $conversation = $this->findById($conversationId);
        if ($conversation === null) {
            return [];
        }

        $messages = $this->getMessages($conversation);
        $messages[] = [
            'role'      => $role,
            'content'   => $content,
            'timestamp' => date('c'),
        ];

        QueryBuilder::query('ai_conversations')
            ->where('id', $conversationId)
            ->update([
                'messages_json' => json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

        return $messages;
    }

    /**
     * Get conversation history for a specific content item.
     *
     * @param int      $userId    User ID
     * @param int|null $contentId Content item ID
     *
     * @return array List of conversation records (most recent first)
     */
    public function getHistory(int $userId, ?int $contentId): array
    {
        $qb = QueryBuilder::query('ai_conversations')
            ->select()
            ->where('user_id', $userId);

        if ($contentId !== null) {
            $qb->where('content_id', $contentId);
        } else {
            $qb->whereNull('content_id');
        }

        return $qb->orderBy('updated_at', 'DESC')->get();
    }

    /**
     * Delete a conversation by ID.
     */
    public function delete(int $id): void
    {
        QueryBuilder::query('ai_conversations')
            ->where('id', $id)
            ->delete();
    }
}
```

**Properties**: None (stateless — all state lives in the database).

**Public API**:
```
findOrCreate(int $userId, ?int $contentId): array
findById(int $id): ?array
getMessages(array $conversation): array
appendMessage(int $conversationId, string $role, string $content): array
getHistory(int $userId, ?int $contentId): array
delete(int $id): void
```

**Implementation details**:
- `findOrCreate()` looks for the most recent conversation matching the user+content pair. If none exists, creates one with empty messages.
- `appendMessage()` loads the current messages, appends the new message with a timestamp, then writes the full array back as JSON. Returns the updated messages array.
- `getMessages()` safely parses the JSON, returning an empty array if invalid.
- `whereNull()` is needed for matching `content_id IS NULL` for general (non-content) conversations. **Note**: Check if `QueryBuilder` has `whereNull()`. If not, use `->where('content_id', null)` if supported, or use `QueryBuilder::raw()` for that specific query. See Implementation Notes section.

---

### 3. `app/AIAssistant/AIController.php`

**Purpose**: Handles the POST `/admin/ai/chat` endpoint. Receives a user message + content context, calls the Claude API, persists the conversation, and returns the AI response as JSON.

**Class**: `App\AIAssistant\AIController`

```php
<?php declare(strict_types=1);

namespace App\AIAssistant;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Auth\Session;
use App\Database\QueryBuilder;

class AIController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * POST /admin/ai/chat
     *
     * Expects JSON body:
     * {
     *   "message": "user's message text",
     *   "content_id": 123,            // optional — ID of content being edited
     *   "conversation_id": 456        // optional — resume existing conversation
     * }
     *
     * Returns JSON:
     * {
     *   "success": true,
     *   "response": "AI response text",
     *   "conversation_id": 456,
     *   "usage": {...}
     * }
     */
    public function chat(Request $request): Response
    {
        // Parse JSON request body
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        if (!is_array($data) || empty($data['message'])) {
            return Response::json([
                'success' => false,
                'error'   => 'Message is required.',
            ], 400);
        }

        $userMessage    = trim((string) $data['message']);
        $contentId      = isset($data['content_id']) ? (int) $data['content_id'] : null;
        $conversationId = isset($data['conversation_id']) ? (int) $data['conversation_id'] : null;

        // Get API key (decrypt from settings, fall back to config)
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            return Response::json([
                'success' => false,
                'error'   => 'Claude API key is not configured. Please add your API key in Settings.',
            ], 400);
        }

        // Get model from settings, fall back to config
        $model = $this->getSetting('claude_model', Config::getString('claude_model', 'claude-sonnet-4-20250514'));

        // Get or create conversation
        $manager = new ConversationManager();
        $userId = (int) Session::get('user_id', 0);

        if ($conversationId !== null) {
            $conversation = $manager->findById($conversationId);
            if ($conversation === null || (int) $conversation['user_id'] !== $userId) {
                // Invalid or unauthorized conversation — start fresh
                $conversation = $manager->findOrCreate($userId, $contentId);
            }
        } else {
            $conversation = $manager->findOrCreate($userId, $contentId);
        }

        $conversationId = (int) $conversation['id'];

        // Build message history for API call
        $existingMessages = $manager->getMessages($conversation);

        // Convert stored messages to API format (strip timestamp)
        $apiMessages = [];
        foreach ($existingMessages as $msg) {
            $apiMessages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Add current user message
        $apiMessages[] = [
            'role'    => 'user',
            'content' => $userMessage,
        ];

        // Build system prompt with content context
        $systemPrompt = $this->buildSystemPrompt($contentId);

        // Call Claude API
        try {
            $client = new ClaudeClient($apiKey, $model);
            $result = $client->sendMessage($apiMessages, $systemPrompt);
        } catch (\RuntimeException $e) {
            return Response::json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 502);
        }

        // Persist both messages to conversation
        $manager->appendMessage($conversationId, 'user', $userMessage);
        $manager->appendMessage($conversationId, 'assistant', $result['content']);

        return Response::json([
            'success'         => true,
            'response'        => $result['content'],
            'conversation_id' => $conversationId,
            'usage'           => $result['usage'],
        ]);
    }

    /**
     * GET /admin/ai/conversations
     *
     * Returns conversation history for a content item.
     *
     * Query params:
     * - content_id (optional): filter by content item
     */
    public function conversations(Request $request): Response
    {
        $contentId = $request->query('content_id') !== null
            ? (int) $request->query('content_id')
            : null;

        $userId = (int) Session::get('user_id', 0);
        $manager = new ConversationManager();
        $history = $manager->getHistory($userId, $contentId);

        // Parse messages_json in each conversation for the response
        $result = [];
        foreach ($history as $conv) {
            $result[] = [
                'id'         => (int) $conv['id'],
                'content_id' => $conv['content_id'] !== null ? (int) $conv['content_id'] : null,
                'messages'   => $manager->getMessages($conv),
                'created_at' => $conv['created_at'],
                'updated_at' => $conv['updated_at'],
            ];
        }

        return Response::json([
            'success'       => true,
            'conversations' => $result,
        ]);
    }

    /**
     * Build the system prompt, including content context if editing a specific item.
     */
    private function buildSystemPrompt(?int $contentId): string
    {
        $siteName = $this->getSetting('site_name', Config::getString('site_name', 'LiteCMS'));

        $prompt = "You are a professional content writing assistant for \"{$siteName}\", "
            . "a business website CMS. Help the user write, edit, and improve web content. "
            . "When the user shares their current page content, reference it in your suggestions. "
            . "Keep responses practical and ready to paste into a web page. "
            . "Format output as clean HTML suitable for a WYSIWYG editor.";

        // Add content context if editing a specific item
        if ($contentId !== null) {
            $content = QueryBuilder::query('content')
                ->select('type', 'title', 'body', 'excerpt', 'status')
                ->where('id', $contentId)
                ->first();

            if ($content !== null) {
                $prompt .= "\n\nThe user is currently editing the following content:\n";
                $prompt .= "- Type: {$content['type']}\n";
                $prompt .= "- Title: {$content['title']}\n";
                $prompt .= "- Status: {$content['status']}\n";

                // Include a body excerpt (first 1000 chars) for context
                $body = strip_tags($content['body'] ?? '');
                if (strlen($body) > 1000) {
                    $body = substr($body, 0, 1000) . '...';
                }
                if ($body !== '') {
                    $prompt .= "- Current body (excerpt): {$body}\n";
                }

                $excerpt = $content['excerpt'] ?? '';
                if ($excerpt !== '') {
                    $prompt .= "- Excerpt: {$excerpt}\n";
                }
            }
        }

        return $prompt;
    }

    /**
     * Get the decrypted Claude API key.
     * Checks settings table first (encrypted), falls back to config file (plain text).
     */
    private function getApiKey(): string
    {
        // Check settings table for encrypted key
        $encryptedKey = $this->getSetting('claude_api_key', '');

        if ($encryptedKey !== '') {
            $decrypted = self::decrypt($encryptedKey);
            if ($decrypted !== '') {
                return $decrypted;
            }
        }

        // Fall back to config file (plain text, for development convenience)
        return Config::getString('claude_api_key', '');
    }

    /**
     * Get a value from the settings table.
     */
    private function getSetting(string $key, string $default = ''): string
    {
        $row = QueryBuilder::query('settings')
            ->select('value')
            ->where('key', $key)
            ->first();

        return ($row !== null && $row['value'] !== null) ? $row['value'] : $default;
    }

    /**
     * Encrypt a value using AES-256-CBC with the app secret.
     */
    public static function encrypt(string $plainText): string
    {
        $secret = Config::getString('app_secret', '');
        if ($secret === '' || $plainText === '') {
            return '';
        }

        $key = hash('sha256', $secret, true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plainText, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            return '';
        }

        // Store as base64(iv + encrypted)
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a value encrypted with encrypt().
     */
    public static function decrypt(string $cipherText): string
    {
        $secret = Config::getString('app_secret', '');
        if ($secret === '' || $cipherText === '') {
            return '';
        }

        $decoded = base64_decode($cipherText, true);
        if ($decoded === false || strlen($decoded) < 17) {
            return '';
        }

        $key = hash('sha256', $secret, true);
        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);

        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false ? $decrypted : '';
    }
}
```

**Properties**:
```
PROPERTIES:
  - private App $app
```

**Public API**:
```
__construct(App $app)
chat(Request $request): Response         — POST /admin/ai/chat
conversations(Request $request): Response — GET /admin/ai/conversations

Static utility:
encrypt(string $plainText): string       — AES-256-CBC encryption
decrypt(string $cipherText): string      — AES-256-CBC decryption
```

**Implementation details**:
- `chat()`:
  1. Parses JSON body from `php://input` (not `$request->input()` since this is a JSON API endpoint, not a form POST).
  2. Validates the `message` field is present and non-empty.
  3. Retrieves and decrypts the API key: first from `settings` table (encrypted), falling back to `config/app.php` (plain text for dev convenience).
  4. Finds or creates a conversation using `ConversationManager`.
  5. Loads existing message history and builds the API messages array.
  6. Builds a system prompt with content context (type, title, body excerpt).
  7. Calls `ClaudeClient::sendMessage()`.
  8. On success: persists both user message and AI response to the conversation, returns JSON.
  9. On failure: returns a JSON error with 502 status.
- `conversations()`: Returns conversation history for a user+content pair as JSON.
- Encryption: AES-256-CBC with a key derived from `sha256(app_secret)`. The IV is generated randomly and prepended to the ciphertext, then the whole thing is base64-encoded for storage. This is a standard approach for symmetric encryption at rest.

---

### 4. `app/Admin/SettingsController.php`

**Purpose**: Settings page for managing the Claude API key, model selection, and other basic settings. This is a focused settings page for chunk 4.1 — it will be expanded in chunk 5.2 with full site configuration sections.

**Class**: `App\Admin\SettingsController`

```php
<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;
use App\AIAssistant\AIController;

class SettingsController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/settings — Show the settings form.
     */
    public function index(Request $request): Response
    {
        // Check admin role
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can access settings.');
            return Response::redirect('/admin/dashboard');
        }

        // Load current settings from database
        $settings = $this->loadSettings();

        // Check if API key is configured (show masked indicator, not the key itself)
        $hasApiKey = !empty($settings['claude_api_key']);

        $html = $this->app->template()->render('admin/settings', [
            'title'       => 'Settings',
            'activeNav'   => 'settings',
            'settings'    => $settings,
            'hasApiKey'   => $hasApiKey,
            'claudeModel' => $settings['claude_model']
                ?? Config::getString('claude_model', 'claude-sonnet-4-20250514'),
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * PUT /admin/settings — Save settings.
     */
    public function update(Request $request): Response
    {
        // Check admin role
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can access settings.');
            return Response::redirect('/admin/dashboard');
        }

        // API key: only update if a new value was provided (non-empty)
        $newApiKey = trim((string) $request->input('claude_api_key', ''));
        if ($newApiKey !== '') {
            $encrypted = AIController::encrypt($newApiKey);
            $this->saveSetting('claude_api_key', $encrypted);
        }

        // Model selection
        $model = trim((string) $request->input('claude_model', ''));
        if ($model !== '') {
            $this->saveSetting('claude_model', $model);
        }

        // Site name (basic setting for preview purposes)
        $siteName = trim((string) $request->input('site_name', ''));
        if ($siteName !== '') {
            $this->saveSetting('site_name', $siteName);
        }

        Session::flash('success', 'Settings saved successfully.');
        return Response::redirect('/admin/settings');
    }

    /**
     * Load all settings from the database as a key-value array.
     */
    private function loadSettings(): array
    {
        $rows = QueryBuilder::query('settings')->select('key', 'value')->get();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    /**
     * Save a single setting (upsert: insert if new, update if exists).
     */
    private function saveSetting(string $key, string $value): void
    {
        $existing = QueryBuilder::query('settings')
            ->select('key')
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            QueryBuilder::query('settings')
                ->where('key', $key)
                ->update(['value' => $value]);
        } else {
            QueryBuilder::query('settings')->insert([
                'key'   => $key,
                'value' => $value,
            ]);
        }
    }

    /**
     * Add standard security headers to admin responses.
     */
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

**Properties**:
```
PROPERTIES:
  - private App $app
```

**Public API**:
```
__construct(App $app)
index(Request $request): Response   — GET  /admin/settings
update(Request $request): Response  — PUT  /admin/settings
```

**Implementation details**:
- Admin-only access enforced via `Session::get('user_role')` check. Non-admins get redirected with an error flash message.
- API key is **never displayed** to the user — only a boolean `$hasApiKey` flag is passed to the template.
- On save, the API key is encrypted using `AIController::encrypt()` before writing to the `settings` table.
- If the API key field is submitted empty, the existing key is **not overwritten** (preserves the current key).
- Uses upsert pattern for `saveSetting()`: checks if key exists, then either updates or inserts.
- Security headers applied via `withSecurityHeaders()` (same pattern as `ContentController`).

---

### 5. `templates/admin/settings.php`

**Purpose**: Settings page template with API key management, model selection, and site name fields.

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Settings</h1>
</div>

<form method="POST" action="/admin/settings" class="settings-form">
    <?= $this->csrfField() ?>
    <input type="hidden" name="_method" value="PUT">

    <!-- AI Assistant Section -->
    <div class="settings-section">
        <h2>AI Assistant</h2>
        <p class="section-desc">Configure your Claude API integration for the AI writing assistant.</p>

        <div class="form-group">
            <label for="claude_api_key">Claude API Key</label>
            <?php if ($hasApiKey): ?>
                <div class="key-status key-configured">
                    API key is configured (stored encrypted)
                </div>
            <?php else: ?>
                <div class="key-status key-missing">
                    No API key configured
                </div>
            <?php endif; ?>
            <input type="password"
                   id="claude_api_key"
                   name="claude_api_key"
                   placeholder="<?= $hasApiKey ? 'Leave blank to keep current key' : 'sk-ant-...' ?>"
                   autocomplete="off">
            <small>Get your API key from <a href="https://console.anthropic.com/" target="_blank" rel="noopener">console.anthropic.com</a>. Leave blank to keep the current key.</small>
        </div>

        <div class="form-group">
            <label for="claude_model">Claude Model</label>
            <select id="claude_model" name="claude_model">
                <option value="claude-sonnet-4-20250514"
                    <?= ($claudeModel === 'claude-sonnet-4-20250514') ? 'selected' : '' ?>>
                    Claude Sonnet 4 (Recommended)
                </option>
                <option value="claude-haiku-4-5-20251001"
                    <?= ($claudeModel === 'claude-haiku-4-5-20251001') ? 'selected' : '' ?>>
                    Claude Haiku 4.5 (Faster, lower cost)
                </option>
                <option value="claude-opus-4-6"
                    <?= ($claudeModel === 'claude-opus-4-6') ? 'selected' : '' ?>>
                    Claude Opus 4.6 (Most capable)
                </option>
            </select>
            <small>Choose the Claude model for the AI writing assistant.</small>
        </div>
    </div>

    <!-- General Section (preview — will be expanded in chunk 5.2) -->
    <div class="settings-section">
        <h2>General</h2>

        <div class="form-group">
            <label for="site_name">Site Name</label>
            <input type="text"
                   id="site_name"
                   name="site_name"
                   value="<?= $this->e($settings['site_name'] ?? \App\Core\Config::getString('site_name', 'LiteCMS')) ?>">
            <small>The name of your website, shown in titles and navigation.</small>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>

<style>
.settings-form {
    max-width: 700px;
}
.settings-section {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #dee2e6);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}
.settings-section h2 {
    margin-top: 0;
    font-size: 1.15rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border-color, #dee2e6);
}
.section-desc {
    color: var(--text-muted, #6c757d);
    font-size: 0.9rem;
    margin-bottom: 1.25rem;
}
.key-status {
    padding: 0.5rem 0.75rem;
    border-radius: 4px;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
}
.key-configured {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}
.key-missing {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}
</style>
```

**Notes**:
- The API key field is `type="password"` — never shows the stored key.
- A status indicator shows whether a key is configured (green) or missing (yellow warning).
- The model selector has the three main Claude models as options.
- Inline `<style>` for settings-specific styles that don't warrant a CSS file addition yet. These will move into `admin.css` in chunk 5.2 when the full settings panel is built.
- Uses the existing admin layout via `$this->layout('admin/layout')`.

---

### 6. Updates to `public/index.php`

**Purpose**: Register the new routes for the AI chat endpoint and replace the settings placeholder with the real settings controller.

**Changes to make** (do NOT rewrite the entire file — only add/modify these sections):

**Add imports** at the top:
```php
use App\AIAssistant\AIController;
use App\Admin\SettingsController;
```

**Replace the settings placeholder** inside the `$router->group('/admin', ...)` block:

Remove this:
```php
    // Placeholder routes for sidebar links (to be replaced in future chunks)
    $router->get('/settings', function($request) use ($app) {
        return new Response(
            $app->template()->render('admin/placeholder', [
                'title' => 'Settings',
                'activeNav' => 'settings',
                'message' => 'Settings panel is coming in Chunk 5.2.',
            ])
        );
    });
```

Replace with:
```php
    // Settings routes
    $router->get('/settings', [SettingsController::class, 'index']);
    $router->put('/settings', [SettingsController::class, 'update']);

    // AI Assistant routes
    $router->post('/ai/chat', [AIController::class, 'chat']);
    $router->get('/ai/conversations', [AIController::class, 'conversations']);
```

**Notes**:
- The AI routes live under `/admin/ai/*` (they're inside the `/admin` group).
- The CSRF middleware already covers POST requests, so `/admin/ai/chat` is CSRF-protected. The frontend (chunk 4.2) will need to send the CSRF token with chat requests.
- The AuthMiddleware already protects `/admin/*` routes, so no additional auth checks are needed at the route level (though the SettingsController adds a role check for admin-only access).

---

## Detailed Class Specifications

### `App\AIAssistant\ClaudeClient`

```
PROPERTIES:
  - private string $apiKey            — Decrypted API key
  - private string $model             — Model identifier

CONSTANTS:
  - API_URL: string         = 'https://api.anthropic.com/v1/messages'
  - API_VERSION: string     = '2023-06-01'
  - MAX_TOKENS: int         = 4096
  - TIMEOUT_SECONDS: int    = 60

CONSTRUCTOR:
  __construct(string $apiKey, string $model = 'claude-sonnet-4-20250514')
    Stores both values in private properties.

METHODS:
  - public sendMessage(array $messages, string $systemPrompt = '', int $maxTokens = 4096): array
      1. Validate $apiKey is not empty — throw RuntimeException if missing.
      2. Build payload: model, max_tokens, messages, system (if non-empty).
      3. JSON-encode payload.
      4. Initialize curl with API_URL.
      5. Set headers: Content-Type, x-api-key, anthropic-version.
      6. Set options: POST, RETURNTRANSFER, TIMEOUT, CONNECTTIMEOUT.
      7. Execute curl. If response is false or curl_error is non-empty, throw.
      8. Parse JSON response.
      9. If HTTP code is not 200, extract error.message and error.type, throw.
      10. Validate response has content[0].text.
      11. Return ['content' => text, 'usage' => usage array, 'model' => model, 'stop_reason' => stop_reason].
```

### `App\AIAssistant\ConversationManager`

```
NO PROPERTIES (stateless)

METHODS:
  - public findOrCreate(int $userId, ?int $contentId): array
      Query ai_conversations for matching user_id + content_id.
      If found: return the most recent.
      If not found: insert a new record with empty messages_json, return it.

  - public findById(int $id): ?array
      Simple select by ID, return row or null.

  - public getMessages(array $conversation): array
      JSON-decode conversation['messages_json'], return array.
      If invalid JSON, return [].

  - public appendMessage(int $conversationId, string $role, string $content): array
      1. Find conversation by ID.
      2. Decode existing messages.
      3. Append new message: {role, content, timestamp}.
      4. Update row: messages_json = JSON-encode, updated_at = now.
      5. Return updated messages array.

  - public getHistory(int $userId, ?int $contentId): array
      Query ai_conversations for matching user_id + content_id.
      Order by updated_at DESC.
      Return all matching rows.

  - public delete(int $id): void
      Delete row from ai_conversations by ID.
```

### `App\Admin\SettingsController`

```
PROPERTIES:
  - private App $app

CONSTRUCTOR:
  __construct(App $app)

METHODS:
  - public index(Request $request): Response
      1. Check admin role — redirect if not admin.
      2. Load all settings from DB via loadSettings().
      3. Determine hasApiKey boolean.
      4. Get current claude_model from settings or config fallback.
      5. Render admin/settings template.
      6. Apply security headers.

  - public update(Request $request): Response
      1. Check admin role — redirect if not admin.
      2. Read form inputs: claude_api_key, claude_model, site_name.
      3. If API key is non-empty, encrypt and save.
      4. Save model and site name if non-empty.
      5. Flash success message.
      6. Redirect to settings page.

  - private loadSettings(): array
      Select all rows from settings table.
      Return as ['key' => 'value', ...] associative array.

  - private saveSetting(string $key, string $value): void
      Upsert: check if key exists, update or insert accordingly.

  - private withSecurityHeaders(Response $response): Response
      Same pattern as ContentController — X-Frame-Options: DENY, CSP header.
```

---

## Acceptance Test Procedures

### Test 1: API key encryption and storage
```
1. Log in as admin.
2. Navigate to /admin/settings.
3. Enter a test API key "sk-ant-test-123456" in the Claude API Key field.
4. Submit the form.
5. Verify: success flash message appears.
6. Check the database directly: settings table has key='claude_api_key' with
   a base64-encoded value — NOT the plain text key.
7. Verify: the value can be decrypted back to "sk-ant-test-123456" using
   AIController::decrypt() with the same app_secret.
```

### Test 2: Chat endpoint returns Claude response
```
1. Configure a valid Claude API key in settings.
2. POST to /admin/ai/chat with JSON body:
   {"message": "Write a short greeting", "content_id": null}
3. Include CSRF token in the request.
4. Verify: HTTP 200 response with JSON body containing:
   - success: true
   - response: non-empty string (Claude's reply)
   - conversation_id: integer
   - usage: object with input_tokens and output_tokens
```

### Test 3: Conversation persistence
```
1. POST to /admin/ai/chat with a first message.
2. Note the returned conversation_id.
3. Query ai_conversations table: row exists with messages_json containing
   both the user message and the assistant response.
4. Verify: messages_json is a valid JSON array with 2 entries,
   each having role, content, and timestamp fields.
```

### Test 4: Conversation history continuity
```
1. POST to /admin/ai/chat with message "My name is Alice" and note conversation_id.
2. POST to /admin/ai/chat with message "What is my name?" and the same conversation_id.
3. Verify: the AI response references "Alice" (proving history was included in the API call).
4. Check ai_conversations: messages_json now has 4 entries (2 user + 2 assistant).
```

### Test 5: Missing/invalid API key returns user-friendly error
```
1. Ensure no API key is configured (remove from settings and config).
2. POST to /admin/ai/chat with a message.
3. Verify: HTTP 400 response with JSON:
   {"success": false, "error": "Claude API key is not configured. Please add your API key in Settings."}
4. No PHP errors or exceptions visible.
```

### Test 6: Settings page model configuration
```
1. Navigate to /admin/settings.
2. Select "Claude Haiku 4.5 (Faster, lower cost)" from the model dropdown.
3. Submit the form.
4. Verify: settings table has key='claude_model' with value='claude-haiku-4-5-20251001'.
5. Reload settings page: the Haiku option is selected in the dropdown.
```

### Test 7: Editor role cannot access settings
```
1. Log in as an editor (non-admin) user.
2. Navigate to /admin/settings.
3. Verify: redirected to /admin/dashboard with error flash message.
```

### Test 8: Conversations endpoint returns history
```
1. Create a conversation via /admin/ai/chat (requires valid API key).
2. GET /admin/ai/conversations?content_id=1
3. Verify: HTTP 200 with JSON containing conversations array.
4. Each conversation has id, content_id, messages (parsed array), timestamps.
```

---

## Implementation Notes

### QueryBuilder `whereNull()` Support

The `ConversationManager` needs to match `content_id IS NULL` for general (non-content-specific) conversations. Check if `QueryBuilder` supports `whereNull()`.

**If `whereNull()` does NOT exist**, use one of these approaches:
- Option A: Use `QueryBuilder::raw()` to build a custom query for the null case:
  ```php
  $stmt = QueryBuilder::raw(
      'SELECT * FROM ai_conversations WHERE user_id = :uid AND content_id IS NULL ORDER BY updated_at DESC LIMIT 1',
      [':uid' => $userId]
  );
  $conversation = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
  ```
- Option B: Add a `whereNull(string $column)` method to `QueryBuilder`. This is a small, useful addition that future chunks would also benefit from. If adding it:
  ```php
  public function whereNull(string $column): self
  {
      $this->wheres[] = ['type' => 'null', 'column' => $column];
      return $this;
  }
  ```
  And update `buildWhereClause()` to handle `type === 'null'` by appending `"{$column} IS NULL"` without a parameter.

**Recommendation**: Use Option A (raw query) to minimize changes to existing, tested code. The raw fallback is simple and contained.

### CSRF Token for JSON API Requests

The CSRF middleware validates `$_POST['_csrf_token']` on POST requests. Since the `/admin/ai/chat` endpoint receives a JSON body (not form data), the CSRF token needs to be sent in a way the middleware can read it.

**Options** (choose during implementation):
1. **Header-based**: Modify `CsrfMiddleware` to also check `X-CSRF-Token` header (recommended for JSON APIs). The frontend (chunk 4.2) sends the token as a header.
2. **Include in JSON body**: Have `AIController::chat()` manually validate the CSRF token from the JSON body, bypassing the global middleware for this route.
3. **Query parameter**: Send the CSRF token as `?_csrf_token=xxx` in the POST URL.

**Recommendation**: Option 1 — add header-based CSRF checking. This is a small, backwards-compatible change to `CsrfMiddleware`:
```php
// In CsrfMiddleware::handle(), after checking $_POST['_csrf_token']:
$headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($headerToken !== '' && hash_equals($sessionToken, $headerToken)) {
    return $next($request);
}
```

### API Response Format Consistency

All JSON responses from `AIController` use a consistent format:
```json
{
    "success": true|false,
    "response": "...",           // only on success
    "error": "...",              // only on failure
    "conversation_id": 123,     // only on chat success
    "usage": {}                 // only on chat success
}
```

The frontend (chunk 4.2) can always check `success` first.

### Error Handling Strategy

- **Network errors** (curl failures): Caught and returned as 502 with a user-friendly message.
- **API errors** (invalid key, rate limit, overloaded): Error message from Claude's response is passed through. Common codes:
  - 401 → "Invalid API key"
  - 429 → "Rate limited"
  - 500/529 → "Claude API is temporarily unavailable"
- **Missing key**: Caught before the API call, returned as 400 with instructions to visit Settings.
- **Invalid conversation**: Falls back to creating a new conversation rather than erroring.

### Security Considerations

- API key is **encrypted at rest** using AES-256-CBC with a key derived from `sha256(app_secret)`.
- API key is **never returned** to the browser — the settings page only shows whether a key is configured.
- The `app_secret` in `config/app.php` must be changed from the default for encryption to be meaningful.
- All database queries use parameterized statements via `QueryBuilder`.
- All template output is escaped via `$this->e()`.
- Admin-only routes are protected by `AuthMiddleware` (session check) and `SettingsController` adds a role check.
- CSRF protection covers all POST/PUT/DELETE requests.

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `app/AIAssistant/ClaudeClient.php` | Class | Create |
| 2 | `app/AIAssistant/ConversationManager.php` | Class | Create |
| 3 | `app/AIAssistant/AIController.php` | Class | Create |
| 4 | `app/Admin/SettingsController.php` | Class | Create |
| 5 | `templates/admin/settings.php` | Template | Create |
| 6 | `public/index.php` | Entry point | Modify (add routes + imports) |
| 7 | `app/Auth/CsrfMiddleware.php` | Class | Modify (add header-based CSRF check) |

---

## Estimated Scope

- **New PHP classes**: 4 (ClaudeClient, ConversationManager, AIController, SettingsController)
- **New templates**: 1 (admin/settings)
- **Modified files**: 2 (public/index.php, CsrfMiddleware.php)
- **Approximate new PHP LOC**: ~450-500 lines
- **No new database tables or migrations** — uses existing `ai_conversations` and `settings` tables
