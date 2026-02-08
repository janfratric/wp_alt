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
     * Expects JSON body: {"message": "...", "content_id": 123, "conversation_id": 456}
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

        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            return Response::json([
                'success' => false,
                'error'   => 'Claude API key is not configured. Please add your API key in Settings.',
            ], 400);
        }

        $model = $this->getSetting('claude_model', Config::getString('claude_model', 'claude-sonnet-4-20250514'));

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

        $existingMessages = $manager->getMessages($conversation);

        $apiMessages = [];
        foreach ($existingMessages as $msg) {
            $apiMessages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        $apiMessages[] = [
            'role'    => 'user',
            'content' => $userMessage,
        ];

        $systemPrompt = $this->buildSystemPrompt($contentId);

        try {
            $client = new ClaudeClient($apiKey, $model);
            $result = $client->sendMessage($apiMessages, $systemPrompt);
        } catch (\RuntimeException $e) {
            return Response::json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 502);
        }

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
