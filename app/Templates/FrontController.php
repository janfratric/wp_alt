<?php declare(strict_types=1);

namespace App\Templates;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;

class FrontController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Homepage — shows recent posts and welcome content.
     */
    public function homepage(Request $request): Response
    {
        $perPage = Config::getInt('items_per_page', 10);

        $posts = QueryBuilder::query('content')
            ->select('content.*', 'users.username as author_name')
            ->leftJoin('users', 'users.id', '=', 'content.author_id')
            ->where('content.type', 'post')
            ->where('content.status', 'published')
            ->whereRaw(
                '(content.published_at IS NULL OR content.published_at <= :now)',
                [':now' => gmdate('Y-m-d H:i:s')]
            )
            ->orderBy('content.published_at', 'DESC')
            ->limit($perPage)
            ->get();

        $meta = [
            'title'       => Config::getString('site_name', 'LiteCMS'),
            'description' => '',
            'canonical'   => Config::getString('site_url', ''),
            'og_type'     => 'website',
            'og_url'      => Config::getString('site_url', ''),
        ];

        return $this->renderPublic('public/home', [
            'title' => Config::getString('site_name', 'LiteCMS'),
            'posts' => $posts,
            'meta'  => $meta,
        ]);
    }

    /**
     * Blog index — paginated listing of published posts.
     */
    public function blogIndex(Request $request): Response
    {
        $perPage = Config::getInt('items_per_page', 10);
        $page = max(1, (int) ($request->query('page', '1')));
        $offset = ($page - 1) * $perPage;
        $now = gmdate('Y-m-d H:i:s');

        $total = QueryBuilder::query('content')
            ->select()
            ->where('type', 'post')
            ->where('status', 'published')
            ->whereRaw(
                '(published_at IS NULL OR published_at <= :now)',
                [':now' => $now]
            )
            ->count();

        $totalPages = (int) ceil($total / $perPage);

        $posts = QueryBuilder::query('content')
            ->select('content.*', 'users.username as author_name')
            ->leftJoin('users', 'users.id', '=', 'content.author_id')
            ->where('content.type', 'post')
            ->where('content.status', 'published')
            ->whereRaw(
                '(content.published_at IS NULL OR content.published_at <= :now)',
                [':now' => $now]
            )
            ->orderBy('content.published_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $meta = [
            'title'       => 'Blog — ' . Config::getString('site_name', 'LiteCMS'),
            'description' => 'Blog posts from ' . Config::getString('site_name', 'LiteCMS'),
            'canonical'   => Config::getString('site_url', '') . '/blog',
            'og_type'     => 'website',
            'og_url'      => Config::getString('site_url', '') . '/blog',
        ];

        return $this->renderPublic('public/blog-index', [
            'title'       => 'Blog',
            'posts'       => $posts,
            'currentPage' => $page,
            'totalPages'  => $totalPages,
            'total'       => $total,
            'meta'        => $meta,
        ]);
    }

    /**
     * Single blog post by slug.
     */
    public function blogPost(Request $request, string $slug): Response
    {
        $content = QueryBuilder::query('content')
            ->select('content.*', 'users.username as author_name')
            ->leftJoin('users', 'users.id', '=', 'content.author_id')
            ->where('content.slug', $slug)
            ->where('content.type', 'post')
            ->first();

        if ($content === null || !$this->isPublished($content)) {
            return $this->notFound($request);
        }

        $meta = $this->buildMeta($content, 'article');

        return $this->renderPublic('public/blog-post', [
            'title'   => $content['title'],
            'content' => $content,
            'meta'    => $meta,
        ]);
    }

    /**
     * Single page by slug. Catch-all for /{slug}.
     */
    public function page(Request $request, string $slug): Response
    {
        $content = QueryBuilder::query('content')
            ->select('content.*', 'users.username as author_name')
            ->leftJoin('users', 'users.id', '=', 'content.author_id')
            ->where('content.slug', $slug)
            ->first();

        if ($content === null || !$this->isPublished($content)) {
            return $this->notFound($request);
        }

        // If this is a post type, redirect to /blog/{slug} for canonical URLs
        if ($content['type'] === 'post') {
            return Response::redirect('/blog/' . $content['slug'], 301);
        }

        $template = 'public/page';
        if (!empty($content['template'])) {
            $templateCandidate = 'public/' . $content['template'];
            $templatePath = dirname(__DIR__, 2) . '/templates/' . $templateCandidate . '.php';
            if (file_exists($templatePath)) {
                $template = $templateCandidate;
            }
        }

        $meta = $this->buildMeta($content, 'website');

        return $this->renderPublic($template, [
            'title'   => $content['title'],
            'content' => $content,
            'meta'    => $meta,
        ]);
    }

    /**
     * 404 Not Found page.
     */
    public function notFound(Request $request): Response
    {
        $html = $this->app->template()->render('public/404', [
            'title'       => 'Page Not Found',
            'navPages'    => $this->getNavPages(),
            'siteName'    => Config::getString('site_name', 'LiteCMS'),
            'siteUrl'     => Config::getString('site_url', ''),
            'currentSlug' => '',
            'meta'        => [
                'title' => 'Page Not Found — ' . Config::getString('site_name', 'LiteCMS'),
            ],
        ]);

        return Response::html($html, 404);
    }

    /**
     * Fetch published pages for navigation, sorted by sort_order.
     */
    private function getNavPages(): array
    {
        $now = gmdate('Y-m-d H:i:s');

        return QueryBuilder::query('content')
            ->select('title', 'slug')
            ->where('type', 'page')
            ->where('status', 'published')
            ->whereRaw(
                '(published_at IS NULL OR published_at <= :now)',
                [':now' => $now]
            )
            ->orderBy('sort_order', 'ASC')
            ->get();
    }

    /**
     * Check if content is published and not scheduled for the future.
     */
    private function isPublished(array $content): bool
    {
        if ($content['status'] !== 'published') {
            return false;
        }

        if (!empty($content['published_at'])) {
            $publishedAt = strtotime($content['published_at']);
            if ($publishedAt !== false && $publishedAt > time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build SEO meta array from a content record.
     */
    private function buildMeta(array $content, string $type = 'website'): array
    {
        $siteUrl = Config::getString('site_url', '');

        $description = $content['meta_description'] ?? '';
        if ($description === '' || $description === null) {
            $raw = $content['excerpt'] ?? $content['body'] ?? '';
            $description = mb_substr(strip_tags($raw), 0, 160, 'UTF-8');
        }

        $ogImage = '';
        if (!empty($content['featured_image'])) {
            $image = $content['featured_image'];
            if (!str_starts_with($image, 'http')) {
                $image = $siteUrl . $image;
            }
            $ogImage = $image;
        }

        $canonical = $siteUrl;
        if ($content['type'] === 'post') {
            $canonical .= '/blog/' . $content['slug'];
        } else {
            $canonical .= '/' . $content['slug'];
        }

        $meta = [
            'title'          => $content['meta_title'] ?: $content['title'],
            'description'    => $description,
            'canonical'      => $canonical,
            'og_title'       => $content['meta_title'] ?: $content['title'],
            'og_description' => $description,
            'og_image'       => $ogImage,
            'og_type'        => $type,
            'og_url'         => $canonical,
        ];

        if ($type === 'article') {
            $meta['article_author'] = $content['author_name'] ?? '';
            $meta['article_published'] = $content['published_at'] ?? $content['created_at'] ?? '';
        }

        return $meta;
    }

    /**
     * Common render helper — merges in navigation and global data.
     */
    private function renderPublic(string $template, array $data): Response
    {
        $data = array_merge([
            'navPages'    => $this->getNavPages(),
            'siteName'    => Config::getString('site_name', 'LiteCMS'),
            'siteUrl'     => Config::getString('site_url', ''),
            'currentSlug' => '',
        ], $data);

        if (isset($data['content']['slug'])) {
            $data['currentSlug'] = $data['content']['slug'];
        }

        $html = $this->app->template()->render($template, $data);
        return Response::html($html);
    }
}
