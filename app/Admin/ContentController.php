<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;
use App\PageBuilder\PenConverter;
use App\PageBuilder\StyleRenderer;

class ContentController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/content — List content with search, filters, and pagination.
     */
    public function index(Request $request): Response
    {
        $type    = (string) $request->query('type', '');
        $status  = (string) $request->query('status', '');
        $search  = (string) $request->query('q', '');
        $page    = max(1, (int) $request->query('page', '1'));
        $perPage = Config::getInt('items_per_page', 10);

        // Count query
        $countQb = QueryBuilder::query('content')->select();
        if ($type !== '')   $countQb->where('type', $type);
        if ($status !== '') $countQb->where('status', $status);
        if ($search !== '') $countQb->whereRaw('title LIKE :search', [':search' => "%{$search}%"]);
        $total = $countQb->count();

        // Pagination
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        // Data query
        $qb = QueryBuilder::query('content')
            ->select('content.*', 'users.username as author_name')
            ->leftJoin('users', 'users.id', '=', 'content.author_id');
        if ($type !== '')   $qb->where('content.type', $type);
        if ($status !== '') $qb->where('content.status', $status);
        if ($search !== '') $qb->whereRaw('content.title LIKE :search', [':search' => "%{$search}%"]);
        $items = $qb->orderBy('content.updated_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $contentTypes = QueryBuilder::query('content_types')
            ->select('slug', 'name')
            ->orderBy('name', 'ASC')
            ->get();

        $html = $this->app->template()->render('admin/content/index', [
            'title'        => 'Content',
            'activeNav'    => 'content',
            'items'        => $items,
            'type'         => $type,
            'status'       => $status,
            'search'       => $search,
            'page'         => $page,
            'totalPages'   => $totalPages,
            'total'        => $total,
            'contentTypes' => $contentTypes,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * GET /admin/content/create — Show empty content editor.
     */
    public function create(Request $request): Response {
        $type = (string) $request->query('type', 'page');
        $validTypes = ['page', 'post'];
        $contentTypes = QueryBuilder::query('content_types')
            ->select('slug', 'name')
            ->orderBy('name', 'ASC')
            ->get();
        foreach ($contentTypes as $ct) {
            $validTypes[] = $ct['slug'];
        }
        if (!in_array($type, $validTypes, true)) {
            $type = 'page';
        }

        $content = [
            'id'               => null,
            'type'             => $type,
            'title'            => '',
            'slug'             => '',
            'body'             => '',
            'excerpt'          => '',
            'status'           => 'draft',
            'meta_title'       => '',
            'meta_description' => '',
            'featured_image'   => '',
            'published_at'     => '',
            'sort_order'       => 0,
            'editor_mode'      => (string) $request->query('editor_mode', 'html'),
        ];

        // Load custom field definitions for this type
        $customFieldDefinitions = [];
        $customFieldValues = [];
        $contentTypeRecord = QueryBuilder::query('content_types')
            ->select('fields_json')
            ->where('slug', $type)
            ->first();
        if ($contentTypeRecord !== null) {
            $customFieldDefinitions = json_decode($contentTypeRecord['fields_json'], true) ?: [];
        }

        $layoutTemplates = QueryBuilder::query('layout_templates')
            ->select('id', 'name', 'is_default')
            ->orderBy('is_default', 'DESC')
            ->orderBy('name', 'ASC')
            ->get();

        // Load design files for design mode selector
        $designFiles = $this->loadDesignFiles();

        $html = $this->app->template()->render('admin/content/edit', [
            'title'                  => 'Create Content',
            'activeNav'              => 'content',
            'content'                => $content,
            'isNew'                  => true,
            'contentTypes'           => $contentTypes,
            'customFieldDefinitions' => $customFieldDefinitions,
            'customFieldValues'      => $customFieldValues,
            'pageElements'           => [],
            'pageStyles'             => [],
            'csrfToken'              => Session::get('csrf_token', ''),
            'layoutTemplates'        => $layoutTemplates,
            'templateBlocks'         => [],
            'designFiles'            => $designFiles,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * POST /admin/content — Validate and store new content.
     */
    public function store(Request $request): Response
    {
        $data = $this->readFormData($request);
        $error = $this->validate($data);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/content/create?type=' . urlencode($data['type']));
        }

        $data['slug'] = $this->generateSlug($data['title'], $data['slug']);
        $data['slug'] = $this->ensureUniqueSlug($data['slug']);
        $data['published_at'] = $this->resolvePublishedAt($data['status'], $data['published_at']);

        $editorMode = in_array($data['editor_mode'], ['html', 'elements', 'design'], true)
            ? $data['editor_mode'] : 'html';

        $layoutTemplateId = $data['layout_template_id'] !== '' ? (int) $data['layout_template_id'] : null;

        $themeOverride = $data['theme_override'] !== '' ? $data['theme_override'] : null;

        $id = QueryBuilder::query('content')->insert([
            'type'               => $data['type'],
            'title'              => $data['title'],
            'slug'               => $data['slug'],
            'body'               => $data['body'],
            'excerpt'            => $data['excerpt'],
            'status'             => $data['status'],
            'author_id'          => (int) Session::get('user_id'),
            'sort_order'         => $data['sort_order'],
            'meta_title'         => $data['meta_title'] ?: null,
            'meta_description'   => $data['meta_description'] ?: null,
            'featured_image'     => $data['featured_image'] ?: null,
            'published_at'       => $data['published_at'] ?: null,
            'updated_at'         => date('Y-m-d H:i:s'),
            'editor_mode'        => $editorMode,
            'layout_template_id' => $layoutTemplateId,
            'design_file'        => ($editorMode === 'design' && !empty($data['design_file']))
                                    ? $data['design_file'] : null,
            'theme_override'     => $themeOverride,
        ]);

        // Save page elements if in elements mode
        if ($editorMode === 'elements') {
            $this->savePageElements((int) $id, $request);
            $this->savePageStyles((int) $id, $request);
        }

        // Save custom fields
        $customFields = $request->input('custom_fields', []);
        if (is_array($customFields)) {
            foreach ($customFields as $key => $value) {
                if (!is_string($key)) continue;
                QueryBuilder::query('custom_fields')->insert([
                    'content_id'  => (int) $id,
                    'field_key'   => $key,
                    'field_value' => is_string($value) ? $value : '',
                ]);
            }
        }

        Session::flash('success', 'Content created successfully.');
        return Response::redirect('/admin/content/' . $id . '/edit');
    }

    /**
     * GET /admin/content/{id}/edit — Show content editor with existing data.
     */
    public function edit(Request $request, string $id): Response
    {
        $content = QueryBuilder::query('content')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($content === null) {
            Session::flash('error', 'Content not found.');
            return Response::redirect('/admin/content');
        }

        // Load custom field definitions for this type
        $customFieldDefinitions = [];
        $customFieldValues = [];
        $contentTypeRecord = QueryBuilder::query('content_types')
            ->select('fields_json')
            ->where('slug', $content['type'])
            ->first();
        if ($contentTypeRecord !== null) {
            $customFieldDefinitions = json_decode($contentTypeRecord['fields_json'], true) ?: [];
        }

        // Load custom field values
        $cfRows = QueryBuilder::query('custom_fields')
            ->select('field_key', 'field_value')
            ->where('content_id', (int) $id)
            ->get();
        foreach ($cfRows as $row) {
            $customFieldValues[$row['field_key']] = $row['field_value'];
        }

        $contentTypes = QueryBuilder::query('content_types')
            ->select('slug', 'name')
            ->orderBy('name', 'ASC')
            ->get();

        // Load page elements for element-mode content
        $pageElements = [];
        if (($content['editor_mode'] ?? 'html') === 'elements') {
            $pageElements = $this->loadPageElements((int) $id);
        }

        $layoutTemplates = QueryBuilder::query('layout_templates')
            ->select('id', 'name', 'is_default')
            ->orderBy('is_default', 'DESC')
            ->orderBy('name', 'ASC')
            ->get();

        // Load template blocks for the page builder
        $templateBlocks = $this->loadTemplateBlocks($content);

        // Load design files for design mode selector
        $designFiles = $this->loadDesignFiles();

        $html = $this->app->template()->render('admin/content/edit', [
            'title'                  => 'Edit: ' . $content['title'],
            'activeNav'              => 'content',
            'content'                => $content,
            'isNew'                  => false,
            'contentTypes'           => $contentTypes,
            'customFieldDefinitions' => $customFieldDefinitions,
            'customFieldValues'      => $customFieldValues,
            'pageElements'           => $pageElements,
            'pageStyles'             => $this->loadPageStyles((int) $id),
            'csrfToken'              => Session::get('csrf_token', ''),
            'layoutTemplates'        => $layoutTemplates,
            'templateBlocks'         => $templateBlocks,
            'designFile'             => $content['design_file'] ?? null,
            'designFiles'            => $designFiles,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * PUT /admin/content/{id} — Validate and update existing content.
     */
    public function update(Request $request, string $id): Response
    {
        $existing = QueryBuilder::query('content')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($existing === null) {
            Session::flash('error', 'Content not found.');
            return Response::redirect('/admin/content');
        }

        $data = $this->readFormData($request);
        $error = $this->validate($data);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/content/' . $id . '/edit');
        }

        $data['slug'] = $this->generateSlug($data['title'], $data['slug']);
        $data['slug'] = $this->ensureUniqueSlug($data['slug'], (int) $id);
        $data['published_at'] = $this->resolvePublishedAt($data['status'], $data['published_at']);

        $editorMode = in_array($data['editor_mode'], ['html', 'elements', 'design'], true)
            ? $data['editor_mode'] : 'html';

        $layoutTemplateId = $data['layout_template_id'] !== '' ? (int) $data['layout_template_id'] : null;
        $themeOverride = $data['theme_override'] !== '' ? $data['theme_override'] : null;

        QueryBuilder::query('content')->where('id', (int) $id)->update([
            'type'               => $data['type'],
            'title'              => $data['title'],
            'slug'               => $data['slug'],
            'body'               => $data['body'],
            'excerpt'            => $data['excerpt'],
            'status'             => $data['status'],
            'sort_order'         => $data['sort_order'],
            'meta_title'         => $data['meta_title'] ?: null,
            'meta_description'   => $data['meta_description'] ?: null,
            'featured_image'     => $data['featured_image'] ?: null,
            'published_at'       => $data['published_at'] ?: null,
            'updated_at'         => date('Y-m-d H:i:s'),
            'editor_mode'        => $editorMode,
            'layout_template_id' => $layoutTemplateId,
            'design_file'        => ($editorMode === 'design' && !empty($data['design_file']))
                                    ? $data['design_file'] : null,
            'theme_override'     => $themeOverride,
        ]);

        // Save page elements if in elements mode
        if ($editorMode === 'elements') {
            $this->savePageElements((int) $id, $request);
            $this->savePageStyles((int) $id, $request);
        }

        // Update custom fields: delete old, insert new
        QueryBuilder::query('custom_fields')
            ->where('content_id', (int) $id)
            ->delete();

        $customFields = $request->input('custom_fields', []);
        if (is_array($customFields)) {
            foreach ($customFields as $key => $value) {
                if (!is_string($key)) continue;
                QueryBuilder::query('custom_fields')->insert([
                    'content_id'  => (int) $id,
                    'field_key'   => $key,
                    'field_value' => is_string($value) ? $value : '',
                ]);
            }
        }

        Session::flash('success', 'Content updated successfully.');
        return Response::redirect('/admin/content/' . $id . '/edit');
    }

    /**
     * DELETE /admin/content/{id} — Delete a single content item.
     */
    public function delete(Request $request, string $id): Response
    {
        $content = QueryBuilder::query('content')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($content === null) {
            Session::flash('error', 'Content not found.');
            return Response::redirect('/admin/content');
        }

        QueryBuilder::query('content')->where('id', (int) $id)->delete();

        Session::flash('success', 'Content deleted.');
        return Response::redirect('/admin/content');
    }

    /**
     * POST /admin/content/bulk — Handle bulk actions.
     */
    public function bulk(Request $request): Response
    {
        $action = (string) $request->input('bulk_action', '');
        $ids = $request->input('ids', []);

        if (!is_array($ids) || empty($ids)) {
            Session::flash('error', 'No items selected.');
            return Response::redirect('/admin/content');
        }

        if (!in_array($action, ['delete', 'publish', 'draft', 'archive'], true)) {
            Session::flash('error', 'Invalid action.');
            return Response::redirect('/admin/content');
        }

        $ids = array_map('intval', $ids);
        $count = count($ids);

        if ($action === 'delete') {
            QueryBuilder::query('content')->where('id', 'IN', $ids)->delete();
            Session::flash('success', $count . ' item(s) deleted.');
        } else {
            $statusMap = [
                'publish' => 'published',
                'draft'   => 'draft',
                'archive' => 'archived',
            ];
            QueryBuilder::query('content')->where('id', 'IN', $ids)->update([
                'status'     => $statusMap[$action],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Session::flash('success', $count . ' item(s) updated.');
        }

        return Response::redirect('/admin/content');
    }

    /**
     * POST /admin/content/{id}/reconvert — Re-convert .pen file and update content body.
     */
    public function reconvert(Request $request, string $id): Response
    {
        $db = $this->app->resolve('db');
        $stmt = $db->prepare('SELECT * FROM content WHERE id = ?');
        $stmt->execute([(int) $id]);
        $content = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$content) {
            return Response::json(['success' => false, 'error' => 'Content not found'], 404);
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $designFile = $body['design_file'] ?? ($content['design_file'] ?? '');

        if (empty($designFile)) {
            return Response::json(['success' => false, 'error' => 'No design file specified'], 400);
        }

        $designsDir = dirname(__DIR__, 2) . '/designs';
        $fullPath = $designsDir . '/' . $designFile;

        if (!file_exists($fullPath)) {
            return Response::json(['success' => false, 'error' => 'Design file not found'], 404);
        }

        try {
            $result = PenConverter::convertFile($fullPath);

            $update = $db->prepare(
                'UPDATE content SET body = ?, design_file = ?, updated_at = ? WHERE id = ?'
            );
            $update->execute([
                $result['html'],
                $designFile,
                date('Y-m-d H:i:s'),
                (int) $id,
            ]);

            return Response::json([
                'success' => true,
                'html'    => $result['html'],
                'css'     => $result['css'],
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    private function readFormData(Request $request): array
    {
        return [
            'title'            => trim((string) $request->input('title', '')),
            'slug'             => trim((string) $request->input('slug', '')),
            'body'             => (string) $request->input('body', ''),
            'excerpt'          => trim((string) $request->input('excerpt', '')),
            'type'             => (string) $request->input('type', 'page'),
            'status'           => (string) $request->input('status', 'draft'),
            'meta_title'       => trim((string) $request->input('meta_title', '')),
            'meta_description' => trim((string) $request->input('meta_description', '')),
            'featured_image'   => trim((string) $request->input('featured_image', '')),
            'published_at'     => trim((string) $request->input('published_at', '')),
            'sort_order'       => (int) $request->input('sort_order', '0'),
            'editor_mode'      => (string) $request->input('editor_mode', 'html'),
            'layout_template_id' => trim((string) $request->input('layout_template_id', '')),
            'design_file'      => (string) $request->input('design_file', ''),
            'theme_override'   => trim((string) $request->input('theme_override', '')),
        ];
    }

    private function validate(array $data): ?string
    {
        if ($data['title'] === '') {
            return 'Title is required.';
        }
        if (mb_strlen($data['title']) > 255) {
            return 'Title must be 255 characters or less.';
        }
        $validTypes = ['page', 'post'];
        $customTypes = QueryBuilder::query('content_types')
            ->select('slug')
            ->get();
        foreach ($customTypes as $ct) {
            $validTypes[] = $ct['slug'];
        }
        if (!in_array($data['type'], $validTypes, true)) {
            return 'Invalid content type.';
        }
        if (!in_array($data['status'], ['draft', 'published', 'archived'], true)) {
            return 'Invalid status.';
        }
        return null;
    }

    private function generateSlug(string $title, string $manualSlug = ''): string
    {
        $base = $manualSlug !== '' ? $manualSlug : $title;
        $slug = strtolower($base);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = preg_replace('/-+/', '-', $slug);
        return $slug !== '' ? $slug : 'untitled';
    }

    private function ensureUniqueSlug(string $slug, ?int $excludeId = null): string
    {
        $candidate = $slug;
        $counter = 1;

        while (true) {
            $qb = QueryBuilder::query('content')->select()->where('slug', $candidate);
            if ($excludeId !== null) {
                $qb->where('id', '!=', $excludeId);
            }
            if ($qb->first() === null) {
                break;
            }
            $counter++;
            $candidate = $slug . '-' . $counter;
        }

        return $candidate;
    }

    private function resolvePublishedAt(string $status, string $publishedAt): string
    {
        if ($publishedAt !== '') {
            // Convert datetime-local format (2026-02-07T14:30) to SQL format
            $publishedAt = str_replace('T', ' ', $publishedAt);
            if (strlen($publishedAt) === 16) {
                $publishedAt .= ':00';
            }
            return $publishedAt;
        }
        if ($status === 'published') {
            return date('Y-m-d H:i:s');
        }
        return '';
    }

    /**
     * Parse elements_json from request and save page_elements rows.
     * Deletes old rows and inserts new ones with correct sort_order.
     */
    private function savePageElements(int $contentId, Request $request): void
    {
        // Delete existing page_elements for this content
        QueryBuilder::query('page_elements')
            ->where('content_id', $contentId)
            ->delete();

        $elementsJson = (string) $request->input('elements_json', '[]');
        $elements = json_decode($elementsJson, true);

        if (!is_array($elements)) {
            return;
        }

        foreach ($elements as $sortOrder => $element) {
            if (!is_array($element) || empty($element['element_id'])) {
                continue;
            }

            $elementId = (int) $element['element_id'];

            // Verify element exists
            $exists = QueryBuilder::query('elements')
                ->select('id')
                ->where('id', $elementId)
                ->first();

            if ($exists === null) {
                continue;
            }

            $slotData = $element['slot_data'] ?? [];
            if (!is_array($slotData)) {
                $slotData = [];
            }

            $styleData = $element['style_data'] ?? [];
            if (!is_array($styleData)) {
                $styleData = [];
            }
            $styleData = StyleRenderer::sanitizeStyleData($styleData);

            $blockId = isset($element['block_id']) && $element['block_id'] !== null && $element['block_id'] !== ''
                ? (int) $element['block_id'] : null;

            QueryBuilder::query('page_elements')->insert([
                'content_id'      => $contentId,
                'element_id'      => $elementId,
                'sort_order'      => $sortOrder,
                'slot_data_json'  => json_encode($slotData, JSON_UNESCAPED_UNICODE),
                'style_data_json' => json_encode($styleData, JSON_UNESCAPED_UNICODE),
                'block_id'        => $blockId,
            ]);
        }
    }

    /**
     * Load page_elements for a content item with full element metadata.
     * Returns an array suitable for passing to the page builder JS.
     */
    private function loadPageElements(int $contentId): array
    {
        $rows = QueryBuilder::query('page_elements')
            ->select(
                'page_elements.id',
                'page_elements.element_id',
                'page_elements.sort_order',
                'page_elements.slot_data_json',
                'page_elements.style_data_json',
                'page_elements.block_id',
                'elements.slug',
                'elements.name',
                'elements.category',
                'elements.slots_json'
            )
            ->leftJoin('elements', 'elements.id', '=', 'page_elements.element_id')
            ->where('page_elements.content_id', (string) $contentId)
            ->orderBy('page_elements.sort_order')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'elementId'       => (int) $row['element_id'],
                'elementSlug'     => $row['slug'] ?? 'unknown',
                'elementName'     => $row['name'] ?? 'Unknown Element',
                'elementCategory' => $row['category'] ?? 'general',
                'slots'           => json_decode($row['slots_json'] ?? '[]', true) ?: [],
                'slotData'        => json_decode($row['slot_data_json'] ?? '{}', true) ?: [],
                'styleData'       => json_decode($row['style_data_json'] ?? '{}', true) ?: [],
                'blockId'         => $row['block_id'] ? (int) $row['block_id'] : null,
            ];
        }

        return $result;
    }

    /**
     * Save page-level layout styles from page_styles_json form input.
     */
    private function savePageStyles(int $contentId, Request $request): void
    {
        $pageStylesJson = (string) $request->input('page_styles_json', '{}');
        $data = json_decode($pageStylesJson, true);

        if (!is_array($data)) {
            return;
        }

        // Sanitize each target
        $allowedTargets = ['page_body', 'container', 'site_main'];
        $sanitized = [];
        foreach ($allowedTargets as $target) {
            if (isset($data[$target]) && is_array($data[$target])) {
                $sanitized[$target] = StyleRenderer::sanitizeStyleData($data[$target]);
            }
        }

        // Only persist if there's data
        if (empty($sanitized)) {
            // Delete existing row if any
            QueryBuilder::query('page_styles')
                ->where('content_id', $contentId)
                ->delete();
            return;
        }

        // Upsert: delete + insert
        QueryBuilder::query('page_styles')
            ->where('content_id', $contentId)
            ->delete();

        QueryBuilder::query('page_styles')->insert([
            'content_id'      => $contentId,
            'style_data_json' => json_encode($sanitized, JSON_UNESCAPED_UNICODE),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Load page-level layout styles for a content item.
     */
    private function loadPageStyles(int $contentId): array
    {
        $row = QueryBuilder::query('page_styles')
            ->select('style_data_json')
            ->where('content_id', (string) $contentId)
            ->first();

        if ($row === null) {
            return [];
        }

        return json_decode($row['style_data_json'] ?? '{}', true) ?: [];
    }

    /**
     * Load template blocks for a content item based on its layout_template_id.
     */
    private function loadTemplateBlocks(array $content): array
    {
        $templateId = $content['layout_template_id'] ?? null;
        if ($templateId === null || $templateId === '' || $templateId === '0') {
            // Try the default template
            $default = QueryBuilder::query('layout_templates')
                ->select('id')
                ->where('is_default', '1')
                ->first();
            if ($default !== null) {
                $templateId = (int) $default['id'];
            }
        } else {
            $templateId = (int) $templateId;
        }

        if (!$templateId) {
            return [];
        }

        return QueryBuilder::query('page_blocks')
            ->select('id', 'name', 'sort_order', 'columns', 'width_percent', 'alignment', 'display_mode')
            ->where('layout_template_id', (string) $templateId)
            ->orderBy('sort_order')
            ->get();
    }

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
                $document = $body['pen_document'];
            } elseif (isset($body['pen_page'])) {
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

    /**
     * Scan designs/ directory for .pen files.
     */
    private function loadDesignFiles(): array
    {
        $designsDir = dirname(__DIR__, 2) . '/designs';
        if (!is_dir($designsDir)) {
            return [];
        }

        $files = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($designsDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $fi) {
            if ($fi->getExtension() !== 'pen') continue;
            $rel = str_replace($designsDir . DIRECTORY_SEPARATOR, '', $fi->getPathname());
            $rel = str_replace('\\', '/', $rel);
            $files[] = ['name' => $fi->getBasename('.pen'), 'path' => $rel];
        }
        usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $files;
    }

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
