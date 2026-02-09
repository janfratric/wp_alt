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
            'csrfToken'    => Session::get('csrf_token', ''),
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
        $attachments  = $data['attachments'] ?? [];
        $editorMode   = $data['editor_mode'] ?? 'html';

        $model  = $this->resolveModel($data['model'] ?? null);

        // Load configurable API parameters from settings
        $maxTokens   = (int) $this->getSetting('ai_max_tokens', (string) ClaudeClient::DEFAULT_MAX_TOKENS);
        $timeout     = (int) $this->getSetting('ai_timeout', (string) ClaudeClient::DEFAULT_TIMEOUT);
        $temperature = (float) $this->getSetting('ai_temperature', (string) ClaudeClient::DEFAULT_TEMPERATURE);

        $maxTokens   = max(1, min(128000, $maxTokens));
        $timeout     = max(10, min(600, $timeout));
        $temperature = max(0.0, min(1.0, $temperature));

        $client = new ClaudeClient($apiKey, $model, [
            'max_tokens'  => $maxTokens,
            'timeout'     => $timeout,
            'temperature' => $temperature,
        ]);
        $manager = new ConversationManager();

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

        // Build API messages from existing conversation history (text-only for past messages)
        $existingMessages = $manager->getMessages($conversation);
        $apiMessages = [];
        foreach ($existingMessages as $msg) {
            $apiMessages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Build the current user message with optional image content blocks for vision
        $userApiContent = $this->buildUserContent($message, $attachments);
        $apiMessages[] = [
            'role'    => 'user',
            'content' => $userApiContent,
        ];

        $existingPages = $this->getExistingPages();
        $typeFields = $this->getContentTypeFields($contentType);
        $siteName = Config::getString('site_name', 'LiteCMS');

        if ($editorMode === 'elements') {
            $catalogueElements = QueryBuilder::query('elements')
                ->select()
                ->where('status', 'active')
                ->get();
            $catalogue = GeneratorPrompts::formatElementCatalogue($catalogueElements);

            if ($step === 'generating') {
                $imageUrls = $this->collectImageUrls($existingMessages, $attachments);
                $systemPrompt = GeneratorPrompts::elementGenerationPrompt($siteName, $contentType, $typeFields, $catalogue, $imageUrls);
            } else {
                $systemPrompt = GeneratorPrompts::elementGatheringPrompt($siteName, $existingPages, $typeFields, $catalogue);
            }
        } else {
            if ($step === 'generating') {
                // Collect all image URLs from conversation history so the generation prompt knows them
                $imageUrls = $this->collectImageUrls($existingMessages, $attachments);
                $systemPrompt = GeneratorPrompts::generationPrompt($siteName, $contentType, $typeFields, $imageUrls);
            } else {
                $systemPrompt = GeneratorPrompts::gatheringPrompt($siteName, $existingPages, $typeFields);
            }
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

        // Store messages after successful API call
        $storedAttachments = $this->sanitizeAttachments($attachments);
        $manager->appendMessageWithUsage($convId, 'user', $message, $storedAttachments);
        $manager->appendMessageWithUsage($convId, 'assistant', $aiContent, [], $result['usage']);
        $manager->updateUsage($convId, $result['usage']);

        $responseStep = 'gathering';
        $generated = null;

        if ($step === 'generating') {
            if ($editorMode === 'elements') {
                $generated = $this->parseGeneratedContent($aiContent, true);
            } else {
                $generated = $this->parseGeneratedContent($aiContent);
            }
            if ($generated !== null) {
                $responseStep = 'generated';
                if (empty($generated['slug'])) {
                    $generated['slug'] = $this->generateSlug($generated['title'] ?? 'untitled');
                }
            } else {
                $responseStep = 'generation_failed';
            }
        } elseif (str_contains($aiContent, 'READY_TO_GENERATE')) {
            $responseStep = 'ready';
            $aiContent = trim(str_replace('READY_TO_GENERATE', '', $aiContent));
        }

        $totalUsage = $manager->getUsage($convId);
        $contextWindow = AIController::CONTEXT_WINDOWS[$model] ?? AIController::DEFAULT_CONTEXT_WINDOW;

        return Response::json([
            'success'         => true,
            'response'        => $aiContent,
            'conversation_id' => $convId,
            'step'            => $responseStep,
            'generated'       => $generated,
            'usage'           => array_merge($result['usage'], [
                'total_input_tokens'  => $totalUsage['total_input_tokens'] ?? 0,
                'total_output_tokens' => $totalUsage['total_output_tokens'] ?? 0,
                'context_window'      => $contextWindow,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody, true);

        $editorMode = $data['editor_mode'] ?? 'html';

        // Element mode doesn't require body
        if ($editorMode === 'elements') {
            if (!is_array($data) || empty(trim($data['title'] ?? ''))) {
                return Response::json(['success' => false, 'error' => 'Title is required.'], 400);
            }
        } else {
            if (!is_array($data) || empty(trim($data['title'] ?? '')) || empty(trim($data['body'] ?? ''))) {
                return Response::json(['success' => false, 'error' => 'Title and body are required.'], 400);
            }
        }

        $title           = trim($data['title']);
        $contentType     = $data['content_type'] ?? 'page';
        $status          = in_array($data['status'] ?? '', ['draft', 'published']) ? $data['status'] : 'draft';
        $slug            = $this->ensureUniqueSlug(
            $this->generateSlug(!empty($data['slug']) ? $data['slug'] : $title)
        );
        $publishedAt     = $status === 'published' ? date('Y-m-d H:i:s') : null;

        $contentData = [
            'type'             => $contentType,
            'title'            => $title,
            'slug'             => $slug,
            'body'             => $data['body'] ?? '',
            'excerpt'          => $data['excerpt'] ?? '',
            'status'           => $status,
            'author_id'        => (int) Session::get('user_id'),
            'sort_order'       => 0,
            'meta_title'       => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'featured_image'   => null,
            'published_at'     => $publishedAt,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];

        if ($editorMode === 'elements') {
            $contentData['editor_mode'] = 'elements';
        }

        $id = QueryBuilder::query('content')->insert($contentData);

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

        // Handle element-based page creation
        if ($editorMode === 'elements' && !empty($data['elements']) && is_array($data['elements'])) {
            $userId = (int) Session::get('user_id');
            $sortOrder = 0;
            foreach ($data['elements'] as $elData) {
                if (!is_array($elData)) {
                    continue;
                }
                $elSlug = $elData['element_slug'] ?? '';
                $slotData = $elData['slot_data'] ?? [];

                if ($elSlug === '__new__' && !empty($elData['new_element'])) {
                    // Create element proposal
                    $this->createElementProposal($elData['new_element'], $userId, null);
                } else {
                    // Look up existing element by slug
                    $existingEl = QueryBuilder::query('elements')
                        ->select('id')
                        ->where('slug', $elSlug)
                        ->first();
                    if ($existingEl !== null) {
                        QueryBuilder::query('page_elements')->insert([
                            'content_id'     => (int) $id,
                            'element_id'     => (int) $existingEl['id'],
                            'sort_order'     => $sortOrder,
                            'slot_data_json' => json_encode($slotData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        ]);
                        $sortOrder++;
                    }
                }
            }
        }

        return Response::json([
            'success'    => true,
            'content_id' => (int) $id,
            'edit_url'   => '/admin/content/' . (int) $id . '/edit',
        ]);
    }

    /**
     * Create an element proposal from AI-generated data.
     */
    private function createElementProposal(array $newElement, int $userId, ?int $conversationId): int
    {
        $slotsJson = $newElement['slots_json'] ?? [];
        if (is_array($slotsJson)) {
            $slotsJson = json_encode($slotsJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        return (int) QueryBuilder::query('element_proposals')->insert([
            'name'          => $newElement['name'] ?? 'Untitled Element',
            'slug'          => $newElement['slug'] ?? 'untitled',
            'description'   => $newElement['description'] ?? '',
            'category'      => $newElement['category'] ?? 'general',
            'html_template' => $newElement['html_template'] ?? '',
            'css'           => $newElement['css'] ?? '',
            'slots_json'    => $slotsJson,
            'proposed_by'   => $userId,
            'conversation_id' => $conversationId,
            'status'        => 'pending',
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

    /**
     * Build user message content with optional image blocks for Claude Vision.
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

        // Append URL mapping to the text so Claude knows how to reference images in generated HTML
        $urlInfo = "\n\n[Attached images and their URLs for use in generated content:\n"
            . implode("\n", $urlMap) . "\n"
            . "Use these exact URLs in <img src=\"...\"> tags when including these images in the page.]";

        $blocks[] = ['type' => 'text', 'text' => $message . $urlInfo];
        return $blocks;
    }

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
     * Collect all image URLs from stored conversation messages and current attachments.
     */
    private function collectImageUrls(array $storedMessages, array $currentAttachments): array
    {
        $urls = [];

        // From stored messages (previous turns)
        foreach ($storedMessages as $msg) {
            if (empty($msg['attachments']) || !is_array($msg['attachments'])) {
                continue;
            }
            foreach ($msg['attachments'] as $att) {
                if (!empty($att['url']) && str_starts_with($att['mime_type'] ?? '', 'image/')) {
                    $urls[] = $att['url'];
                }
            }
        }

        // From current message attachments
        foreach ($currentAttachments as $att) {
            if (!is_array($att) || empty($att['url'])) {
                continue;
            }
            if (str_starts_with($att['mime_type'] ?? '', 'image/')) {
                $urls[] = $att['url'];
            }
        }

        return array_values(array_unique($urls));
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

    private function parseGeneratedContent(string $aiResponse, bool $elementMode = false): ?array
    {
        $text = trim($aiResponse);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text);
            $text = preg_replace('/\n?```\s*$/', '', $text);
            $text = trim($text);
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed) || empty($parsed['title'])) {
            return null;
        }

        if ($elementMode) {
            // Element mode: requires elements array instead of body
            if (empty($parsed['elements']) || !is_array($parsed['elements'])) {
                // Fall back to body if present
                if (empty($parsed['body'])) {
                    return null;
                }
            }

            return [
                'editor_mode'      => 'elements',
                'title'            => $parsed['title'],
                'slug'             => $parsed['slug'] ?? '',
                'body'             => $parsed['body'] ?? '',
                'excerpt'          => $parsed['excerpt'] ?? '',
                'meta_title'       => $parsed['meta_title'] ?? $parsed['title'],
                'meta_description' => $parsed['meta_description'] ?? '',
                'custom_fields'    => $parsed['custom_fields'] ?? [],
                'elements'         => $parsed['elements'] ?? [],
            ];
        }

        if (empty($parsed['body'])) {
            return null;
        }

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
                "default-src 'self'; "
                . "script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; "
                . "style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self'; "
                . "font-src 'self' https://cdn.jsdelivr.net"
            );
    }
}
