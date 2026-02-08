<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;
use App\PageBuilder\SlotRenderer;

class ElementController
{
    private App $app;

    private const VALID_SLOT_TYPES = ['text', 'richtext', 'image', 'link', 'select', 'boolean', 'number', 'list'];
    private const VALID_STATUSES = ['active', 'draft', 'archived'];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/elements — List all elements in the catalogue.
     */
    public function index(Request $request): Response
    {
        $category = (string) $request->query('category', '');
        $search = (string) $request->query('q', '');

        $qb = QueryBuilder::query('elements')->select()->orderBy('category', 'ASC');

        if ($category !== '') {
            $qb->where('category', $category);
        }
        if ($search !== '') {
            $qb->where('name', 'LIKE', '%' . $search . '%');
        }

        $elements = $qb->get();

        // Get usage counts
        foreach ($elements as &$el) {
            $el['usage_count'] = QueryBuilder::query('page_elements')
                ->select()
                ->where('element_id', (string) $el['id'])
                ->count();
        }
        unset($el);

        // Get unique categories for filter
        $categories = array_values(array_unique(array_column($elements, 'category')));
        sort($categories);

        $html = $this->app->template()->render('admin/elements/index', [
            'title'      => 'Elements',
            'activeNav'  => 'elements',
            'elements'   => $elements,
            'categories' => $categories,
            'filter'     => ['category' => $category, 'q' => $search],
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * GET /admin/elements/create — Show empty element editor.
     */
    public function create(Request $request): Response
    {
        $element = [
            'id'            => null,
            'slug'          => '',
            'name'          => '',
            'description'   => '',
            'category'      => 'general',
            'html_template' => '',
            'css'           => '',
            'slots_json'    => '[]',
            'status'        => 'active',
        ];

        $html = $this->app->template()->render('admin/elements/edit', [
            'title'      => 'Create Element',
            'activeNav'  => 'elements',
            'element'    => $element,
            'isNew'      => true,
            'usageCount' => 0,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * POST /admin/elements — Validate and store new element.
     */
    public function store(Request $request): Response
    {
        $data = $this->readFormData($request);
        $data['slug'] = $this->generateSlug($data['name'], $data['slug']);

        $error = $this->validate($data);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/elements/create');
        }

        $id = QueryBuilder::query('elements')->insert([
            'slug'          => $data['slug'],
            'name'          => $data['name'],
            'description'   => $data['description'],
            'category'      => $data['category'],
            'html_template' => $data['html_template'],
            'css'           => $data['css'],
            'slots_json'    => $data['slots_json'],
            'status'        => $data['status'],
            'author_id'     => (int) Session::get('user_id'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        Session::flash('success', 'Element created successfully.');
        return Response::redirect('/admin/elements/' . $id . '/edit');
    }

    /**
     * GET /admin/elements/{id}/edit — Show element editor with existing data.
     */
    public function edit(Request $request, string $id): Response
    {
        $element = QueryBuilder::query('elements')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($element === null) {
            Session::flash('error', 'Element not found.');
            return Response::redirect('/admin/elements');
        }

        $usageCount = QueryBuilder::query('page_elements')
            ->select()
            ->where('element_id', (int) $id)
            ->count();

        $html = $this->app->template()->render('admin/elements/edit', [
            'title'      => 'Edit: ' . $element['name'],
            'activeNav'  => 'elements',
            'element'    => $element,
            'isNew'      => false,
            'usageCount' => $usageCount,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * PUT /admin/elements/{id} — Update existing element.
     */
    public function update(Request $request, string $id): Response
    {
        $existing = QueryBuilder::query('elements')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($existing === null) {
            Session::flash('error', 'Element not found.');
            return Response::redirect('/admin/elements');
        }

        $data = $this->readFormData($request);
        $data['slug'] = $this->generateSlug($data['name'], $data['slug']);

        $error = $this->validate($data, (int) $id);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/elements/' . $id . '/edit');
        }

        QueryBuilder::query('elements')->where('id', (int) $id)->update([
            'slug'          => $data['slug'],
            'name'          => $data['name'],
            'description'   => $data['description'],
            'category'      => $data['category'],
            'html_template' => $data['html_template'],
            'css'           => $data['css'],
            'slots_json'    => $data['slots_json'],
            'status'        => $data['status'],
            'version'       => ((int) ($existing['version'] ?? 1)) + 1,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        Session::flash('success', 'Element updated successfully.');
        return Response::redirect('/admin/elements/' . $id . '/edit');
    }

    /**
     * DELETE /admin/elements/{id} — Delete element if not in use.
     */
    public function delete(Request $request, string $id): Response
    {
        $element = QueryBuilder::query('elements')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($element === null) {
            Session::flash('error', 'Element not found.');
            return Response::redirect('/admin/elements');
        }

        $usageCount = QueryBuilder::query('page_elements')
            ->select()
            ->where('element_id', (int) $id)
            ->count();

        if ($usageCount > 0) {
            Session::flash('error',
                'Cannot delete — ' . $usageCount . ' page(s) use this element. Remove all usages first or archive it.');
            return Response::redirect('/admin/elements');
        }

        QueryBuilder::query('elements')->where('id', (int) $id)->delete();

        Session::flash('success', 'Element "' . $element['name'] . '" deleted.');
        return Response::redirect('/admin/elements');
    }

    /**
     * GET /admin/elements/{id}/preview — Render element with sample data (JSON response).
     */
    public function preview(Request $request, string $id): Response
    {
        $element = QueryBuilder::query('elements')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($element === null) {
            return Response::json(['success' => false, 'error' => 'Element not found.'], 404);
        }

        $slots = json_decode($element['slots_json'] ?? '[]', true) ?: [];
        $sampleData = self::generateSampleData($slots);

        $html = SlotRenderer::render($element['html_template'] ?? '', $sampleData);
        $wrappedHtml = '<div class="lcms-el lcms-el-' . htmlspecialchars($element['slug'], ENT_QUOTES, 'UTF-8') . '">'
            . $html . '</div>';

        return Response::json([
            'success' => true,
            'html'    => $wrappedHtml,
            'css'     => $element['css'] ?? '',
        ]);
    }

    /**
     * GET /admin/elements/api/list — JSON list for element picker.
     */
    public function apiList(Request $request): Response
    {
        $elements = QueryBuilder::query('elements')
            ->select('id', 'slug', 'name', 'description', 'category', 'slots_json')
            ->where('status', 'active')
            ->orderBy('category', 'ASC')
            ->get();

        return Response::json(['success' => true, 'elements' => $elements]);
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    private function readFormData(Request $request): array
    {
        return [
            'name'          => trim((string) $request->input('name', '')),
            'slug'          => trim((string) $request->input('slug', '')),
            'description'   => trim((string) $request->input('description', '')),
            'category'      => trim((string) $request->input('category', 'general')),
            'html_template' => (string) $request->input('html_template', ''),
            'css'           => (string) $request->input('css', ''),
            'slots_json'    => (string) $request->input('slots_json', '[]'),
            'status'        => (string) $request->input('status', 'active'),
        ];
    }

    private function validate(array $data, ?int $excludeId = null): ?string
    {
        if ($data['name'] === '') {
            return 'Name is required.';
        }
        if (mb_strlen($data['name']) > 200) {
            return 'Name must be 200 characters or less.';
        }
        if ($data['slug'] === '') {
            return 'Slug is required.';
        }
        if (mb_strlen($data['slug']) > 100) {
            return 'Slug must be 100 characters or less.';
        }
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $data['slug'])) {
            return 'Slug must contain only lowercase letters, numbers, and hyphens.';
        }

        // Slug uniqueness
        $qb = QueryBuilder::query('elements')->select()->where('slug', $data['slug']);
        if ($excludeId !== null) {
            $qb->where('id', '!=', $excludeId);
        }
        if ($qb->first() !== null) {
            return 'An element with this slug already exists.';
        }

        if (!in_array($data['status'], self::VALID_STATUSES, true)) {
            return 'Invalid status.';
        }

        if ($data['category'] === '') {
            return 'Category is required.';
        }

        // Validate slots JSON
        $slotsError = $this->validateSlotsJson($data['slots_json']);
        if ($slotsError !== null) {
            return $slotsError;
        }

        return null;
    }

    private function validateSlotsJson(string $json): ?string
    {
        $slots = json_decode($json, true);
        if (!is_array($slots)) {
            return 'Slots must be a valid JSON array.';
        }

        $keys = [];
        foreach ($slots as $i => $slot) {
            if (!is_array($slot)) {
                return 'Slot #' . ($i + 1) . ' must be an object.';
            }
            if (empty($slot['key']) || !is_string($slot['key'])) {
                return 'Slot #' . ($i + 1) . ': key is required.';
            }
            if (!preg_match('/^[a-z0-9_]+$/', $slot['key'])) {
                return 'Slot #' . ($i + 1) . ': key must contain only lowercase letters, numbers, and underscores.';
            }
            if (in_array($slot['key'], $keys, true)) {
                return 'Slot #' . ($i + 1) . ': duplicate key "' . $slot['key'] . '".';
            }
            $keys[] = $slot['key'];

            if (empty($slot['label']) || !is_string($slot['label'])) {
                return 'Slot #' . ($i + 1) . ': label is required.';
            }
            if (empty($slot['type']) || !in_array($slot['type'], self::VALID_SLOT_TYPES, true)) {
                return 'Slot #' . ($i + 1) . ': type must be one of: '
                    . implode(', ', self::VALID_SLOT_TYPES) . '.';
            }
            if ($slot['type'] === 'select') {
                if (empty($slot['options']) || !is_array($slot['options'])) {
                    return 'Slot #' . ($i + 1) . ': select type requires a non-empty options array.';
                }
            }
            if ($slot['type'] === 'list') {
                if (empty($slot['sub_slots']) || !is_array($slot['sub_slots'])) {
                    return 'Slot #' . ($i + 1) . ': list type requires a non-empty sub_slots array.';
                }
            }
        }

        return null;
    }

    private function generateSlug(string $name, string $manualSlug = ''): string
    {
        $base = $manualSlug !== '' ? $manualSlug : $name;
        $slug = strtolower($base);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = preg_replace('/-+/', '-', $slug);
        return $slug !== '' ? $slug : 'untitled';
    }

    /**
     * Generate sample data for a set of slot definitions (for preview).
     */
    public static function generateSampleData(array $slots): array
    {
        $data = [];
        foreach ($slots as $slot) {
            $key = $slot['key'] ?? '';
            if ($key === '') continue;

            $type = $slot['type'] ?? 'text';
            $label = $slot['label'] ?? $key;

            switch ($type) {
                case 'text':
                    $data[$key] = $slot['default'] ?? 'Sample ' . $label;
                    break;
                case 'richtext':
                    $data[$key] = $slot['default'] ?? '<p>Sample ' . htmlspecialchars($label) . ' content.</p>';
                    break;
                case 'image':
                    $data[$key] = $slot['default'] ?? '';
                    break;
                case 'link':
                    $data[$key] = $slot['default'] ?? ['url' => '#', 'text' => $label, 'target' => '_self'];
                    break;
                case 'select':
                    $options = $slot['options'] ?? [];
                    $data[$key] = $slot['default'] ?? ($options[0] ?? '');
                    break;
                case 'boolean':
                    $data[$key] = $slot['default'] ?? true;
                    break;
                case 'number':
                    $data[$key] = $slot['default'] ?? 3;
                    break;
                case 'list':
                    $subSlots = $slot['sub_slots'] ?? [];
                    $item = self::generateSampleData($subSlots);
                    $data[$key] = [$item, $item];
                    break;
            }
        }
        return $data;
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; "
                . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self'; "
                . "font-src 'self' https://cdn.jsdelivr.net"
            );
    }
}
