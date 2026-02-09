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
    /** Known context window sizes for Claude models (in tokens). */
    public const CONTEXT_WINDOWS = [
        'claude-sonnet-4-20250514'  => 200000,
        'claude-haiku-4-5-20251001' => 200000,
        'claude-opus-4-6'           => 200000,
    ];
    public const DEFAULT_CONTEXT_WINDOW = 200000;

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * POST /admin/ai/chat
     *
     * Expects JSON body: {"message": "...", "content_id": 123, "conversation_id": 456, "model": "...", "attachments": [...]}
     * Returns JSON: {"success": true, "response": "...", "conversation_id": 456, "usage": {...}}
     */
    public function chat(Request $request): Response
    {
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
        $attachments    = $data['attachments'] ?? [];

        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            return Response::json([
                'success' => false,
                'error'   => 'Claude API key is not configured. Please add your API key in Settings.',
            ], 400);
        }

        $model = $this->resolveModel($data['model'] ?? null);

        // Load configurable API parameters from settings
        $maxTokens   = (int) $this->getSetting('ai_max_tokens', (string) ClaudeClient::DEFAULT_MAX_TOKENS);
        $timeout     = (int) $this->getSetting('ai_timeout', (string) ClaudeClient::DEFAULT_TIMEOUT);
        $temperature = (float) $this->getSetting('ai_temperature', (string) ClaudeClient::DEFAULT_TEMPERATURE);

        // Clamp values to safe ranges
        $maxTokens   = max(1, min(128000, $maxTokens));
        $timeout     = max(10, min(600, $timeout));
        $temperature = max(0.0, min(1.0, $temperature));

        // Extend PHP execution time to accommodate the API timeout
        $phpTimeout = $timeout + 10;
        if ((int) ini_get('max_execution_time') > 0 && (int) ini_get('max_execution_time') < $phpTimeout) {
            set_time_limit($phpTimeout);
        }

        $manager = new ConversationManager();
        $userId = (int) Session::get('user_id', 0);

        if ($conversationId !== null) {
            $conversation = $manager->findById($conversationId);
            if ($conversation === null || (int) $conversation['user_id'] !== $userId) {
                $conversation = $manager->findOrCreate($userId, $contentId);
            }
        } else {
            $conversation = $manager->findOrCreate($userId, $contentId);
        }

        $conversationId = (int) $conversation['id'];

        // Auto-set conversation title from first user message
        $existingMessages = $manager->getMessages($conversation);
        if (empty($existingMessages)) {
            $manager->setTitle($conversationId, $userMessage);
        }

        $apiMessages = [];
        foreach ($existingMessages as $msg) {
            $apiMessages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Build the current user message (with optional image attachments)
        $userApiContent = $this->buildUserContent($userMessage, $attachments);
        $apiMessages[] = [
            'role'    => 'user',
            'content' => $userApiContent,
        ];

        $systemPrompt = $this->buildSystemPrompt($contentId);

        try {
            $client = new ClaudeClient($apiKey, $model, [
                'max_tokens'  => $maxTokens,
                'timeout'     => $timeout,
                'temperature' => $temperature,
            ]);
            $result = $client->sendMessage($apiMessages, $systemPrompt);
        } catch (\RuntimeException $e) {
            return Response::json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 502);
        }

        // Store messages with metadata
        $storedAttachments = $this->sanitizeAttachments($attachments);
        $manager->appendMessageWithUsage($conversationId, 'user', $userMessage, $storedAttachments);
        $manager->appendMessageWithUsage($conversationId, 'assistant', $result['content'], [], $result['usage']);
        $manager->updateUsage($conversationId, $result['usage']);

        // Build extended usage response
        $totalUsage = $manager->getUsage($conversationId);
        $contextWindow = self::CONTEXT_WINDOWS[$model] ?? self::DEFAULT_CONTEXT_WINDOW;

        return Response::json([
            'success'         => true,
            'response'        => $result['content'],
            'conversation_id' => $conversationId,
            'usage'           => array_merge($result['usage'], [
                'total_input_tokens'  => $totalUsage['total_input_tokens'] ?? 0,
                'total_output_tokens' => $totalUsage['total_output_tokens'] ?? 0,
                'context_window'      => $contextWindow,
            ]),
        ]);
    }

    /**
     * GET /admin/ai/conversations
     */
    public function conversations(Request $request): Response
    {
        $contentId = $request->query('content_id') !== null
            ? (int) $request->query('content_id')
            : null;

        $userId = (int) Session::get('user_id', 0);
        $manager = new ConversationManager();
        $history = $manager->getHistory($userId, $contentId);

        $result = [];
        foreach ($history as $conv) {
            $usage = @json_decode($conv['usage_json'] ?? '{}', true);
            $result[] = [
                'id'         => (int) $conv['id'],
                'content_id' => $conv['content_id'] !== null ? (int) $conv['content_id'] : null,
                'title'      => $conv['title'] ?? null,
                'messages'   => $manager->getMessages($conv),
                'usage'      => is_array($usage) ? $usage : [],
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

        if ($contentId !== null) {
            $content = QueryBuilder::query('content')
                ->select('type', 'title', 'body', 'excerpt', 'status', 'editor_mode')
                ->where('id', $contentId)
                ->first();

            if ($content !== null) {
                $prompt .= "\n\nThe user is currently editing the following content:\n";
                $prompt .= "- Type: {$content['type']}\n";
                $prompt .= "- Title: {$content['title']}\n";
                $prompt .= "- Status: {$content['status']}\n";

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

                // Include element catalogue for element-mode content
                if (($content['editor_mode'] ?? 'html') === 'elements') {
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
            }
        }

        return $prompt;
    }

    /**
     * Get the decrypted Claude API key.
     */
    private function getApiKey(): string
    {
        $encryptedKey = $this->getSetting('claude_api_key', '');

        if ($encryptedKey !== '') {
            $decrypted = self::decrypt($encryptedKey);
            if ($decrypted !== '') {
                return $decrypted;
            }
        }

        return Config::getString('claude_api_key', '');
    }

    /**
     * POST /admin/ai/models/fetch
     * Fetches available models from the Claude API and caches them in settings.
     */
    public function fetchModels(Request $request): Response
    {
        if (Session::get('user_role') !== 'admin') {
            return Response::json(['success' => false, 'error' => 'Admin access required.'], 403);
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            return Response::json([
                'success' => false,
                'error'   => 'Claude API key is not configured. Please add your API key in Settings.',
            ], 400);
        }

        try {
            $client = new ClaudeClient($apiKey);
            $models = $client->listModels();
        } catch (\RuntimeException $e) {
            return Response::json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 502);
        }

        $this->saveSetting('available_models', json_encode($models, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return Response::json([
            'success' => true,
            'models'  => $models,
        ]);
    }

    /**
     * POST /admin/ai/models/enable
     * Saves the admin's selection of which models to show in the dropdown.
     */
    public function saveEnabledModels(Request $request): Response
    {
        if (Session::get('user_role') !== 'admin') {
            return Response::json(['success' => false, 'error' => 'Admin access required.'], 403);
        }

        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        if (!is_array($data) || !isset($data['model_ids']) || !is_array($data['model_ids']) || empty($data['model_ids'])) {
            return Response::json([
                'success' => false,
                'error'   => 'Select at least one model.',
            ], 400);
        }

        $modelIds = array_values(array_filter($data['model_ids'], 'is_string'));
        if (empty($modelIds)) {
            return Response::json([
                'success' => false,
                'error'   => 'Select at least one model.',
            ], 400);
        }

        $this->saveSetting('enabled_models', json_encode($modelIds, JSON_THROW_ON_ERROR));

        // If current model is no longer in the enabled list, reset to first enabled
        $currentModel = $this->getSetting('claude_model', '');
        if ($currentModel !== '' && !in_array($currentModel, $modelIds, true)) {
            $this->saveSetting('claude_model', $modelIds[0]);
        }

        return Response::json(['success' => true]);
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
     * Save a value to the settings table (upsert).
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
     * POST /admin/ai/compact
     * Summarizes a conversation to reduce token usage.
     */
    public function compact(Request $request): Response
    {
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        if (!is_array($data) || !isset($data['conversation_id'])) {
            return Response::json(['success' => false, 'error' => 'conversation_id is required.'], 400);
        }

        $manager = new ConversationManager();
        $userId = (int) Session::get('user_id', 0);
        $convId = (int) $data['conversation_id'];

        $conversation = $manager->findById($convId);
        if ($conversation === null || (int) $conversation['user_id'] !== $userId) {
            return Response::json(['success' => false, 'error' => 'Conversation not found.'], 404);
        }

        $messages = $manager->getMessages($conversation);
        if (count($messages) < 6) {
            return Response::json(['success' => false, 'error' => 'Conversation too short to compact.'], 400);
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            return Response::json(['success' => false, 'error' => 'API key not configured.'], 400);
        }

        $model = $this->resolveModel($data['model'] ?? null);

        // Calculate tokens before
        $usageBefore = $manager->getUsage($convId);
        $tokensBefore = ($usageBefore['total_input_tokens'] ?? 0) + ($usageBefore['total_output_tokens'] ?? 0);

        // Build summarization request
        $summaryMessages = [];
        foreach ($messages as $msg) {
            $summaryMessages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }
        $summaryMessages[] = [
            'role'    => 'user',
            'content' => 'Please provide a concise summary of our entire conversation so far. '
                . 'Preserve all key decisions, requirements, context, and details needed to continue effectively. '
                . 'Format as a brief paragraph.',
        ];

        try {
            $client = new ClaudeClient($apiKey, $model, ['max_tokens' => 2048]);
            $result = $client->sendMessage(
                $summaryMessages,
                'You are a conversation summarizer. Provide a clear, concise summary.'
            );
        } catch (\RuntimeException $e) {
            return Response::json(['success' => false, 'error' => 'Summarization failed: ' . $e->getMessage()], 502);
        }

        $newMessages = $manager->compact($convId, $result['content'], 4);
        $contextWindow = self::CONTEXT_WINDOWS[$model] ?? self::DEFAULT_CONTEXT_WINDOW;

        return Response::json([
            'success'       => true,
            'messages'      => $newMessages,
            'tokens_before' => $tokensBefore,
            'tokens_after'  => 0,
            'usage'         => [
                'total_input_tokens'  => 0,
                'total_output_tokens' => 0,
                'context_window'      => $contextWindow,
            ],
        ]);
    }

    /**
     * GET /admin/ai/models/enabled
     * Returns the list of enabled models for the chat UI model selector.
     */
    public function enabledModels(Request $request): Response
    {
        $enabledJson = $this->getSetting('enabled_models', '');
        $enabledIds = [];
        if ($enabledJson !== '') {
            $enabledIds = @json_decode($enabledJson, true);
            if (!is_array($enabledIds)) {
                $enabledIds = [];
            }
        }

        $availableJson = $this->getSetting('available_models', '');
        $availableModels = [];
        if ($availableJson !== '') {
            $availableModels = @json_decode($availableJson, true);
            if (!is_array($availableModels)) {
                $availableModels = [];
            }
        }

        // Build the enabled models list with display names
        $models = [];
        if (!empty($enabledIds) && !empty($availableModels)) {
            $lookup = [];
            foreach ($availableModels as $m) {
                $lookup[$m['id']] = $m['display_name'] ?? $m['id'];
            }
            foreach ($enabledIds as $id) {
                $models[] = [
                    'id'             => $id,
                    'display_name'   => $lookup[$id] ?? $id,
                    'context_window' => self::CONTEXT_WINDOWS[$id] ?? self::DEFAULT_CONTEXT_WINDOW,
                ];
            }
        }

        // Fallback if no models configured
        if (empty($models)) {
            $defaultModel = Config::getString('claude_model', 'claude-sonnet-4-20250514');
            $models[] = [
                'id'             => $defaultModel,
                'display_name'   => $defaultModel,
                'context_window' => self::CONTEXT_WINDOWS[$defaultModel] ?? self::DEFAULT_CONTEXT_WINDOW,
            ];
        }

        $currentModel = $this->getSetting('claude_model', Config::getString('claude_model', 'claude-sonnet-4-20250514'));

        return Response::json([
            'success'       => true,
            'models'        => $models,
            'current_model' => $currentModel,
        ]);
    }

    /**
     * Resolve the model to use: prefer request override if it's in enabled list, else settings default.
     */
    private function resolveModel(?string $requestModel): string
    {
        $defaultModel = $this->getSetting('claude_model', Config::getString('claude_model', 'claude-sonnet-4-20250514'));

        if ($requestModel === null || $requestModel === '') {
            return $defaultModel;
        }

        // Validate the requested model is in the enabled list
        $enabledJson = $this->getSetting('enabled_models', '');
        if ($enabledJson !== '') {
            $enabledIds = @json_decode($enabledJson, true);
            if (is_array($enabledIds) && in_array($requestModel, $enabledIds, true)) {
                return $requestModel;
            }
        }

        return $defaultModel;
    }

    /**
     * Build user message content, optionally with image content blocks for vision.
     *
     * @return string|array String for text-only, array of content blocks for vision
     */
    private function buildUserContent(string $message, array $attachments): string|array
    {
        if (empty($attachments)) {
            return $message;
        }

        $blocks = [];
        $urlMap = [];
        $publicDir = dirname(__DIR__, 2) . '/public';
        $imageIndex = 1;

        foreach ($attachments as $att) {
            if (!is_array($att) || empty($att['url']) || empty($att['mime_type'])) {
                continue;
            }

            // Only process image attachments for vision
            if (!str_starts_with($att['mime_type'], 'image/')) {
                continue;
            }

            $filePath = $publicDir . $att['url'];
            try {
                $blocks[] = ClaudeClient::imageToBase64Block($filePath, $att['mime_type']);
                $urlMap[] = "Image {$imageIndex}: {$att['url']}";
                $imageIndex++;
            } catch (\RuntimeException $e) {
                // Skip unreadable images
            }
        }

        if (empty($blocks)) {
            return $message;
        }

        // Append URL mapping so Claude knows how to reference images in generated HTML
        $urlInfo = "\n\n[Attached images and their URLs for use in generated content:\n"
            . implode("\n", $urlMap) . "\n"
            . "Use these exact URLs in <img src=\"...\"> tags when including these images in the page.]";

        $blocks[] = ['type' => 'text', 'text' => $message . $urlInfo];
        return $blocks;
    }

    /**
     * Sanitize attachments for storage (remove any fields we don't need to persist).
     */
    private function sanitizeAttachments(array $attachments): array
    {
        $clean = [];
        foreach ($attachments as $att) {
            if (!is_array($att) || empty($att['url'])) {
                continue;
            }
            $clean[] = [
                'type'      => $att['type'] ?? 'image',
                'url'       => $att['url'],
                'media_id'  => $att['media_id'] ?? null,
                'mime_type' => $att['mime_type'] ?? '',
            ];
        }
        return $clean;
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
