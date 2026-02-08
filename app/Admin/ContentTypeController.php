<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;

class ContentTypeController
{
    private App $app;

    private const RESERVED_SLUGS = ['page', 'post', 'blog', 'admin', 'contact', 'assets', 'storage'];
    private const VALID_FIELD_TYPES = ['text', 'textarea', 'image', 'select', 'boolean'];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(Request $request): Response
    {
        $types = QueryBuilder::query('content_types')
            ->select()
            ->orderBy('name', 'ASC')
            ->get();

        // Get content counts per type
        foreach ($types as &$type) {
            $type['content_count'] = QueryBuilder::query('content')
                ->select()
                ->where('type', $type['slug'])
                ->count();
        }
        unset($type);

        $html = $this->app->template()->render('admin/content-types/index', [
            'title'     => 'Content Types',
            'activeNav' => 'content-types',
            'types'     => $types,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    public function create(Request $request): Response
    {
        $type = [
            'id'          => null,
            'slug'        => '',
            'name'        => '',
            'fields_json' => '[]',
            'has_archive' => 1,
        ];

        $html = $this->app->template()->render('admin/content-types/edit', [
            'title'        => 'Create Content Type',
            'activeNav'    => 'content-types',
            'type'         => $type,
            'isNew'        => true,
            'contentCount' => 0,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    public function store(Request $request): Response
    {
        $data = $this->readFormData($request);
        $data['slug'] = $this->generateSlug($data['name'], $data['slug']);

        $error = $this->validate($data);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/content-types/create');
        }

        $id = QueryBuilder::query('content_types')->insert([
            'slug'        => $data['slug'],
            'name'        => $data['name'],
            'fields_json' => $data['fields_json'],
            'has_archive' => $data['has_archive'],
        ]);

        Session::flash('success', 'Content type created successfully.');
        return Response::redirect('/admin/content-types/' . $id . '/edit');
    }

    public function edit(Request $request, string $id): Response
    {
        $type = QueryBuilder::query('content_types')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($type === null) {
            Session::flash('error', 'Content type not found.');
            return Response::redirect('/admin/content-types');
        }

        $contentCount = QueryBuilder::query('content')
            ->select()
            ->where('type', $type['slug'])
            ->count();

        $html = $this->app->template()->render('admin/content-types/edit', [
            'title'        => 'Edit: ' . $type['name'],
            'activeNav'    => 'content-types',
            'type'         => $type,
            'isNew'        => false,
            'contentCount' => $contentCount,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    public function update(Request $request, string $id): Response
    {
        $existing = QueryBuilder::query('content_types')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($existing === null) {
            Session::flash('error', 'Content type not found.');
            return Response::redirect('/admin/content-types');
        }

        $data = $this->readFormData($request);
        $data['slug'] = $this->generateSlug($data['name'], $data['slug']);

        $error = $this->validate($data, (int) $id);
        if ($error !== null) {
            Session::flash('error', $error);
            return Response::redirect('/admin/content-types/' . $id . '/edit');
        }

        QueryBuilder::query('content_types')->where('id', (int) $id)->update([
            'slug'        => $data['slug'],
            'name'        => $data['name'],
            'fields_json' => $data['fields_json'],
            'has_archive' => $data['has_archive'],
        ]);

        // If slug changed, update all content referencing the old slug
        if ($existing['slug'] !== $data['slug']) {
            QueryBuilder::query('content')
                ->where('type', $existing['slug'])
                ->update(['type' => $data['slug']]);
        }

        Session::flash('success', 'Content type updated successfully.');
        return Response::redirect('/admin/content-types/' . $id . '/edit');
    }

    public function delete(Request $request, string $id): Response
    {
        $type = QueryBuilder::query('content_types')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($type === null) {
            Session::flash('error', 'Content type not found.');
            return Response::redirect('/admin/content-types');
        }

        $contentCount = QueryBuilder::query('content')
            ->select()
            ->where('type', $type['slug'])
            ->count();

        if ($contentCount > 0) {
            Session::flash('error',
                'Cannot delete — ' . $contentCount . ' content item(s) use this type. Delete or reassign them first.');
            return Response::redirect('/admin/content-types');
        }

        QueryBuilder::query('content_types')->where('id', (int) $id)->delete();

        Session::flash('success', 'Content type "' . $type['name'] . '" deleted.');
        return Response::redirect('/admin/content-types');
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    private function readFormData(Request $request): array
    {
        return [
            'name'        => trim((string) $request->input('name', '')),
            'slug'        => trim((string) $request->input('slug', '')),
            'has_archive' => (int) $request->input('has_archive', '1'),
            'fields_json' => (string) $request->input('fields_json', '[]'),
        ];
    }

    private function validate(array $data, ?int $excludeId = null): ?string
    {
        if ($data['name'] === '') {
            return 'Name is required.';
        }
        if (mb_strlen($data['name']) > 100) {
            return 'Name must be 100 characters or less.';
        }
        if ($data['slug'] === '') {
            return 'Slug is required.';
        }
        if (mb_strlen($data['slug']) > 50) {
            return 'Slug must be 50 characters or less.';
        }
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $data['slug'])) {
            return 'Slug must contain only lowercase letters, numbers, and hyphens.';
        }
        if (in_array($data['slug'], self::RESERVED_SLUGS, true)) {
            return 'The slug "' . $data['slug'] . '" is reserved and cannot be used.';
        }

        // Check slug uniqueness in content_types
        $qb = QueryBuilder::query('content_types')->select()->where('slug', $data['slug']);
        if ($excludeId !== null) {
            $qb->where('id', '!=', $excludeId);
        }
        if ($qb->first() !== null) {
            return 'A content type with this slug already exists.';
        }

        // Validate fields JSON
        $fieldsError = $this->validateFieldsJson($data['fields_json']);
        if ($fieldsError !== null) {
            return $fieldsError;
        }

        if (!in_array($data['has_archive'], [0, 1], true)) {
            return 'Invalid archive setting.';
        }

        return null;
    }

    private function validateFieldsJson(string $json): ?string
    {
        $fields = json_decode($json, true);
        if (!is_array($fields)) {
            return 'Fields must be a valid JSON array.';
        }

        $keys = [];
        foreach ($fields as $i => $field) {
            if (!is_array($field)) {
                return 'Field #' . ($i + 1) . ' must be an object.';
            }
            if (empty($field['key']) || !is_string($field['key'])) {
                return 'Field #' . ($i + 1) . ': key is required.';
            }
            if (!preg_match('/^[a-z0-9_]+$/', $field['key'])) {
                return 'Field #' . ($i + 1) . ': key must contain only lowercase letters, numbers, and underscores.';
            }
            if (in_array($field['key'], $keys, true)) {
                return 'Field #' . ($i + 1) . ': duplicate key "' . $field['key'] . '".';
            }
            $keys[] = $field['key'];

            if (empty($field['label']) || !is_string($field['label'])) {
                return 'Field #' . ($i + 1) . ': label is required.';
            }
            if (empty($field['type']) || !in_array($field['type'], self::VALID_FIELD_TYPES, true)) {
                return 'Field #' . ($i + 1) . ': type must be one of: '
                    . implode(', ', self::VALID_FIELD_TYPES) . '.';
            }
            if ($field['type'] === 'select') {
                if (empty($field['options']) || !is_array($field['options'])) {
                    return 'Field #' . ($i + 1) . ': select type requires a non-empty options array.';
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
