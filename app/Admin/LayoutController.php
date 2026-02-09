<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;

class LayoutController
{
    private App $app;

    private const VALID_MODES = ['standard', 'block'];
    private const VALID_ALIGNMENTS = ['left', 'center', 'right'];
    private const VALID_DISPLAY_MODES = ['flex', 'block', 'grid'];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/layouts — List all layout templates.
     */
    public function index(Request $request): Response
    {
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can manage layouts.');
            return Response::redirect('/admin/dashboard');
        }

        $templates = QueryBuilder::query('layout_templates')
            ->select()
            ->orderBy('is_default', 'DESC')
            ->orderBy('name', 'ASC')
            ->get();

        // Count usage per template
        foreach ($templates as &$tpl) {
            $tpl['usage_count'] = QueryBuilder::query('content')
                ->select()
                ->where('layout_template_id', (string) $tpl['id'])
                ->count();
        }
        unset($tpl);

        $html = $this->app->template()->render('admin/layouts/index', [
            'title'     => 'Layout Templates',
            'activeNav' => 'layouts',
            'templates' => $templates,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * GET /admin/layouts/create — Show create form.
     */
    public function create(Request $request): Response
    {
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can manage layouts.');
            return Response::redirect('/admin/dashboard');
        }

        $elements = $this->getActiveElements();

        $html = $this->app->template()->render('admin/layouts/edit', [
            'title'     => 'Create Layout',
            'activeNav' => 'layouts',
            'isNew'     => true,
            'layout'    => [
                'name' => '', 'slug' => '', 'is_default' => '0',
                'header_visible' => '1', 'header_height' => 'auto',
                'header_mode' => 'standard', 'header_element_id' => '',
                'footer_visible' => '1', 'footer_height' => 'auto',
                'footer_mode' => 'standard', 'footer_element_id' => '',
            ],
            'elements'  => $elements,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * POST /admin/layouts — Store new layout template.
     */
    public function store(Request $request): Response
    {
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can manage layouts.');
            return Response::redirect('/admin/dashboard');
        }

        $data = $this->readFormData($request);
        $error = $this->validate($data);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/layouts/create');
        }

        $slug = $this->generateSlug($data['name'], $data['slug']);

        // Check slug uniqueness
        $existing = QueryBuilder::query('layout_templates')
            ->select('id')
            ->where('slug', $slug)
            ->first();
        if ($existing !== null) {
            Session::flash('error', 'A layout with this slug already exists.');
            return Response::redirect('/admin/layouts/create');
        }

        // Handle default toggle
        if ($data['is_default'] === '1') {
            QueryBuilder::query('layout_templates')
                ->where('is_default', '1')
                ->update(['is_default' => '0']);
        }

        QueryBuilder::query('layout_templates')->insert([
            'name'              => $data['name'],
            'slug'              => $slug,
            'is_default'        => $data['is_default'],
            'header_visible'    => $data['header_visible'],
            'header_height'     => $data['header_height'],
            'header_mode'       => $data['header_mode'],
            'header_element_id' => $data['header_mode'] === 'block' ? ($data['header_element_id'] ?: null) : null,
            'footer_visible'    => $data['footer_visible'],
            'footer_height'     => $data['footer_height'],
            'footer_mode'       => $data['footer_mode'],
            'footer_element_id' => $data['footer_mode'] === 'block' ? ($data['footer_element_id'] ?: null) : null,
        ]);

        Session::flash('success', 'Layout template created.');
        return Response::redirect('/admin/layouts');
    }

    /**
     * GET /admin/layouts/{id}/edit — Show edit form.
     */
    public function edit(Request $request, string $id): Response
    {
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can manage layouts.');
            return Response::redirect('/admin/dashboard');
        }

        $layout = QueryBuilder::query('layout_templates')
            ->select()
            ->where('id', $id)
            ->first();

        if ($layout === null) {
            Session::flash('error', 'Layout template not found.');
            return Response::redirect('/admin/layouts');
        }

        $elements = $this->getActiveElements();

        $html = $this->app->template()->render('admin/layouts/edit', [
            'title'     => 'Edit: ' . $layout['name'],
            'activeNav' => 'layouts',
            'isNew'     => false,
            'layout'    => $layout,
            'elements'  => $elements,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * PUT /admin/layouts/{id} — Update layout template.
     */
    public function update(Request $request, string $id): Response
    {
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can manage layouts.');
            return Response::redirect('/admin/dashboard');
        }

        $layout = QueryBuilder::query('layout_templates')
            ->select('id')
            ->where('id', $id)
            ->first();

        if ($layout === null) {
            Session::flash('error', 'Layout template not found.');
            return Response::redirect('/admin/layouts');
        }

        $data = $this->readFormData($request);
        $error = $this->validate($data, (int) $id);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/layouts/' . $id . '/edit');
        }

        $slug = $this->generateSlug($data['name'], $data['slug']);

        // Check slug uniqueness (exclude self)
        $existing = QueryBuilder::query('layout_templates')
            ->select('id')
            ->where('slug', $slug)
            ->first();
        if ($existing !== null && (int) $existing['id'] !== (int) $id) {
            Session::flash('error', 'A layout with this slug already exists.');
            return Response::redirect('/admin/layouts/' . $id . '/edit');
        }

        // Handle default toggle
        if ($data['is_default'] === '1') {
            QueryBuilder::query('layout_templates')
                ->where('is_default', '1')
                ->update(['is_default' => '0']);
        }

        QueryBuilder::query('layout_templates')
            ->where('id', $id)
            ->update([
                'name'              => $data['name'],
                'slug'              => $slug,
                'is_default'        => $data['is_default'],
                'header_visible'    => $data['header_visible'],
                'header_height'     => $data['header_height'],
                'header_mode'       => $data['header_mode'],
                'header_element_id' => $data['header_mode'] === 'block' ? ($data['header_element_id'] ?: null) : null,
                'footer_visible'    => $data['footer_visible'],
                'footer_height'     => $data['footer_height'],
                'footer_mode'       => $data['footer_mode'],
                'footer_element_id' => $data['footer_mode'] === 'block' ? ($data['footer_element_id'] ?: null) : null,
            ]);

        Session::flash('success', 'Layout template updated.');
        return Response::redirect('/admin/layouts');
    }

    /**
     * DELETE /admin/layouts/{id} — Delete layout template.
     */
    public function delete(Request $request, string $id): Response
    {
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can manage layouts.');
            return Response::redirect('/admin/dashboard');
        }

        $layout = QueryBuilder::query('layout_templates')
            ->select()
            ->where('id', $id)
            ->first();

        if ($layout === null) {
            Session::flash('error', 'Layout template not found.');
            return Response::redirect('/admin/layouts');
        }

        if ((int) $layout['is_default'] === 1) {
            Session::flash('error', 'Cannot delete the default layout template.');
            return Response::redirect('/admin/layouts');
        }

        $usageCount = QueryBuilder::query('content')
            ->select()
            ->where('layout_template_id', $id)
            ->count();

        if ($usageCount > 0) {
            Session::flash('error', 'Cannot delete: ' . $usageCount . ' page(s) use this layout. Reassign them first.');
            return Response::redirect('/admin/layouts');
        }

        QueryBuilder::query('layout_templates')
            ->where('id', $id)
            ->delete();

        Session::flash('success', 'Layout template deleted.');
        return Response::redirect('/admin/layouts');
    }

    /**
     * Read form data from request.
     */
    private function readFormData(Request $request): array
    {
        return [
            'name'              => trim((string) $request->input('name', '')),
            'slug'              => trim((string) $request->input('slug', '')),
            'is_default'        => $request->input('is_default') ? '1' : '0',
            'header_visible'    => $request->input('header_visible') ? '1' : '0',
            'header_height'     => trim((string) $request->input('header_height', 'auto')),
            'header_mode'       => trim((string) $request->input('header_mode', 'standard')),
            'header_element_id' => trim((string) $request->input('header_element_id', '')),
            'footer_visible'    => $request->input('footer_visible') ? '1' : '0',
            'footer_height'     => trim((string) $request->input('footer_height', 'auto')),
            'footer_mode'       => trim((string) $request->input('footer_mode', 'standard')),
            'footer_element_id' => trim((string) $request->input('footer_element_id', '')),
        ];
    }

    /**
     * Validate form data.
     */
    private function validate(array $data, ?int $excludeId = null): ?string
    {
        if ($data['name'] === '') {
            return 'Name is required.';
        }
        if (mb_strlen($data['name']) > 200) {
            return 'Name must be 200 characters or less.';
        }
        if (!in_array($data['header_mode'], self::VALID_MODES, true)) {
            return 'Invalid header mode.';
        }
        if (!in_array($data['footer_mode'], self::VALID_MODES, true)) {
            return 'Invalid footer mode.';
        }
        // Validate height format
        if ($data['header_height'] !== 'auto' && !preg_match('/^\d+px$/', $data['header_height'])) {
            return 'Header height must be "auto" or a value like "80px".';
        }
        if ($data['footer_height'] !== 'auto' && !preg_match('/^\d+px$/', $data['footer_height'])) {
            return 'Footer height must be "auto" or a value like "60px".';
        }
        // Validate element references
        if ($data['header_mode'] === 'block' && $data['header_element_id'] !== '') {
            $el = QueryBuilder::query('elements')
                ->select('id')
                ->where('id', $data['header_element_id'])
                ->where('status', 'active')
                ->first();
            if ($el === null) {
                return 'Selected header element does not exist or is not active.';
            }
        }
        if ($data['footer_mode'] === 'block' && $data['footer_element_id'] !== '') {
            $el = QueryBuilder::query('elements')
                ->select('id')
                ->where('id', $data['footer_element_id'])
                ->where('status', 'active')
                ->first();
            if ($el === null) {
                return 'Selected footer element does not exist or is not active.';
            }
        }
        return null;
    }

    /**
     * Generate slug from name.
     */
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
     * Get active elements for header/footer picker.
     */
    private function getActiveElements(): array
    {
        return QueryBuilder::query('elements')
            ->select('id', 'name', 'slug', 'category')
            ->where('status', 'active')
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Resolve a layout template by ID, falling back to default.
     */
    public static function resolveTemplate(?int $layoutTemplateId): array
    {
        $layout = null;

        if ($layoutTemplateId !== null) {
            $layout = QueryBuilder::query('layout_templates')
                ->select()
                ->where('id', (string) $layoutTemplateId)
                ->first();
        }

        if ($layout === null) {
            $layout = QueryBuilder::query('layout_templates')
                ->select()
                ->where('is_default', '1')
                ->first();
        }

        if ($layout === null) {
            // Hardcoded fallback matching current behavior
            return [
                'id' => 0,
                'name' => 'Default',
                'header_visible' => '1',
                'header_height' => 'auto',
                'header_mode' => 'standard',
                'header_element_id' => null,
                'footer_visible' => '1',
                'footer_height' => 'auto',
                'footer_mode' => 'standard',
                'footer_element_id' => null,
            ];
        }

        return $layout;
    }

    /**
     * Add standard security headers.
     */
    private function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self'; "
                . "style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self'; "
                . "font-src 'self'"
            );
    }
}
