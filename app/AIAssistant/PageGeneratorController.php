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

        $model  = $this->getModel();
        $client = new ClaudeClient($apiKey, $model);
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

        $manager->appendMessage($convId, 'user', $message);

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

        $manager->appendMessage($convId, 'assistant', $aiContent);

        $responseStep = 'gathering';
        $generated = null;

        if ($step === 'generating') {
            $generated = $this->parseGeneratedContent($aiContent);
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

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text);
            $text = preg_replace('/\n?```\s*$/', '', $text);
            $text = trim($text);
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed) || empty($parsed['title']) || empty($parsed['body'])) {
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
                "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net"
            );
    }
}
