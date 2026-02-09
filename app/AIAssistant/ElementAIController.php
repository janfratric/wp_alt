<?php declare(strict_types=1);

namespace App\AIAssistant;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Auth\Session;
use App\Database\QueryBuilder;

class ElementAIController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * POST /admin/ai/element/chat
     *
     * Accepts JSON: {message, element_id, conversation_id, model, current_html, current_css}
     * Returns JSON: {success, response, conversation_id, usage}
     */
    public function chat(Request $request): Response
    {
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        if (!is_array($data) || empty(trim($data['message'] ?? ''))) {
            return Response::json([
                'success' => false,
                'error'   => 'Message is required.',
            ], 400);
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            return Response::json([
                'success' => false,
                'error'   => 'Claude API key is not configured. Please add your API key in Settings.',
            ], 400);
        }

        $message      = trim($data['message']);
        $elementId    = isset($data['element_id']) && $data['element_id'] !== '' ? (int) $data['element_id'] : null;
        $convId       = isset($data['conversation_id']) ? (int) $data['conversation_id'] : null;
        $currentHtml  = trim((string) ($data['current_html'] ?? ''));
        $currentCss   = trim((string) ($data['current_css'] ?? ''));

        $model = $this->resolveModel($data['model'] ?? null);

        // Load configurable API parameters
        $maxTokens   = (int) $this->getSetting('ai_max_tokens', (string) ClaudeClient::DEFAULT_MAX_TOKENS);
        $timeout     = (int) $this->getSetting('ai_timeout', (string) ClaudeClient::DEFAULT_TIMEOUT);
        $temperature = (float) $this->getSetting('ai_temperature', (string) ClaudeClient::DEFAULT_TEMPERATURE);

        $maxTokens   = max(1, min(128000, $maxTokens));
        $timeout     = max(10, min(600, $timeout));
        $temperature = max(0.0, min(1.0, $temperature));

        $phpTimeout = $timeout + 10;
        if ((int) ini_get('max_execution_time') > 0 && (int) ini_get('max_execution_time') < $phpTimeout) {
            set_time_limit($phpTimeout);
        }

        $manager = new ConversationManager();
        $userId  = (int) Session::get('user_id', 0);

        if ($convId !== null) {
            $conversation = $manager->findById($convId);
            if ($conversation === null || (int) $conversation['user_id'] !== $userId) {
                $conversation = $manager->findOrCreateForElement($userId, $elementId);
            }
        } else {
            $conversation = $manager->findOrCreateForElement($userId, $elementId);
        }

        $convId = (int) $conversation['id'];

        // Auto-set title from first message
        $existingMessages = $manager->getMessages($conversation);
        if (empty($existingMessages)) {
            $manager->setTitle($convId, $message);
        }

        // Build API messages from history
        $apiMessages = [];
        foreach ($existingMessages as $msg) {
            $apiMessages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Prepend current code context if this is the first message and code is non-empty
        $userText = $message;
        if (empty($existingMessages) && ($currentHtml !== '' || $currentCss !== '')) {
            $context = "Here is the current element code:\n";
            if ($currentHtml !== '') {
                $context .= "\n```html\n{$currentHtml}\n```\n";
            }
            if ($currentCss !== '') {
                $context .= "\n```css\n{$currentCss}\n```\n";
            }
            $userText = $context . "\n" . $message;
        }

        $apiMessages[] = [
            'role'    => 'user',
            'content' => $userText,
        ];

        // Load element data if editing an existing element
        $element = null;
        if ($elementId !== null) {
            $element = QueryBuilder::query('elements')
                ->select()
                ->where('id', $elementId)
                ->first();
        }

        $siteName = $this->getSetting('site_name', Config::getString('site_name', 'LiteCMS'));
        $systemPrompt = ElementPrompts::systemPrompt($siteName, $element);

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

        // Store messages
        $manager->appendMessageWithUsage($convId, 'user', $message);
        $manager->appendMessageWithUsage($convId, 'assistant', $result['content'], [], $result['usage']);
        $manager->updateUsage($convId, $result['usage']);

        $totalUsage = $manager->getUsage($convId);
        $contextWindow = AIController::CONTEXT_WINDOWS[$model] ?? AIController::DEFAULT_CONTEXT_WINDOW;

        return Response::json([
            'success'         => true,
            'response'        => $result['content'],
            'conversation_id' => $convId,
            'usage'           => array_merge($result['usage'], [
                'total_input_tokens'  => $totalUsage['total_input_tokens'] ?? 0,
                'total_output_tokens' => $totalUsage['total_output_tokens'] ?? 0,
                'context_window'      => $contextWindow,
            ]),
        ]);
    }

    /**
     * GET /admin/ai/element/conversations?element_id=N
     */
    public function conversations(Request $request): Response
    {
        $elementId = $request->query('element_id') !== null && $request->query('element_id') !== ''
            ? (int) $request->query('element_id')
            : null;

        $userId  = (int) Session::get('user_id', 0);
        $manager = new ConversationManager();
        $history = $manager->getHistoryForElement($userId, $elementId);

        $result = [];
        foreach ($history as $conv) {
            $usage = @json_decode($conv['usage_json'] ?? '{}', true);
            $result[] = [
                'id'         => (int) $conv['id'],
                'element_id' => $conv['element_id'] !== null ? (int) $conv['element_id'] : null,
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

    private function getApiKey(): string
    {
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

    private function resolveModel(?string $requestModel): string
    {
        $defaultModel = $this->getSetting('claude_model',
            Config::getString('claude_model', 'claude-sonnet-4-20250514'));

        if ($requestModel === null || $requestModel === '') {
            return $defaultModel;
        }

        $enabledJson = $this->getSetting('enabled_models', '');
        if ($enabledJson !== '') {
            $enabledIds = @json_decode($enabledJson, true);
            if (is_array($enabledIds) && in_array($requestModel, $enabledIds, true)) {
                return $requestModel;
            }
        }

        return $defaultModel;
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $row = QueryBuilder::query('settings')
            ->select('value')
            ->where('key', $key)
            ->first();

        return ($row !== null && $row['value'] !== null) ? $row['value'] : $default;
    }
}
