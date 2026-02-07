<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;

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

        $html = $this->app->template()->render('admin/content/index', [
            'title'      => 'Content',
            'activeNav'  => 'content',
            'items'      => $items,
            'type'       => $type,
            'status'     => $status,
            'search'     => $search,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * GET /admin/content/create — Show empty content editor.
     */
    public function create(Request $request): Response
    {
        $type = (string) $request->query('type', 'page');
        if (!in_array($type, ['page', 'post'], true)) {
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
        ];

        $html = $this->app->template()->render('admin/content/edit', [
            'title'     => 'Create Content',
            'activeNav' => 'content',
            'content'   => $content,
            'isNew'     => true,
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

        $id = QueryBuilder::query('content')->insert([
            'type'             => $data['type'],
            'title'            => $data['title'],
            'slug'             => $data['slug'],
            'body'             => $data['body'],
            'excerpt'          => $data['excerpt'],
            'status'           => $data['status'],
            'author_id'        => (int) Session::get('user_id'),
            'sort_order'       => $data['sort_order'],
            'meta_title'       => $data['meta_title'] ?: null,
            'meta_description' => $data['meta_description'] ?: null,
            'featured_image'   => $data['featured_image'] ?: null,
            'published_at'     => $data['published_at'] ?: null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

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

        $html = $this->app->template()->render('admin/content/edit', [
            'title'     => 'Edit: ' . $content['title'],
            'activeNav' => 'content',
            'content'   => $content,
            'isNew'     => false,
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

        QueryBuilder::query('content')->where('id', (int) $id)->update([
            'type'             => $data['type'],
            'title'            => $data['title'],
            'slug'             => $data['slug'],
            'body'             => $data['body'],
            'excerpt'          => $data['excerpt'],
            'status'           => $data['status'],
            'sort_order'       => $data['sort_order'],
            'meta_title'       => $data['meta_title'] ?: null,
            'meta_description' => $data['meta_description'] ?: null,
            'featured_image'   => $data['featured_image'] ?: null,
            'published_at'     => $data['published_at'] ?: null,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

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
        if (!in_array($data['type'], ['page', 'post'], true)) {
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
