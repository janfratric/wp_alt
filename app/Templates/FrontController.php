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
    private ?array $settingsCache = null;

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
     * Contact page (GET) — show the contact form.
     */
    public function contactPage(Request $request): Response
    {
        $meta = [
            'title'       => 'Contact — ' . Config::getString('site_name', 'LiteCMS'),
            'description' => 'Get in touch with us.',
            'canonical'   => Config::getString('site_url', '') . '/contact',
            'og_type'     => 'website',
            'og_url'      => Config::getString('site_url', '') . '/contact',
        ];

        $success = $_SESSION['flash_success'] ?? '';
        unset($_SESSION['flash_success']);

        return $this->renderPublic('public/contact', [
            'title'   => 'Contact',
            'meta'    => $meta,
            'old'     => [],
            'success' => $success,
            'error'   => '',
        ]);
    }

    /**
     * Contact form submission (POST) — validate and store.
     */
    public function contactSubmit(Request $request): Response
    {
        $name    = trim((string) $request->input('name', ''));
        $email   = trim((string) $request->input('email', ''));
        $subject = trim((string) $request->input('subject', ''));
        $message = trim((string) $request->input('message', ''));

        // Server-side validation
        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Name is required (max 100 characters).';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            $errors[] = 'A valid email address is required.';
        }
        if (mb_strlen($subject) > 255) {
            $errors[] = 'Subject must be 255 characters or fewer.';
        }
        if ($message === '' || mb_strlen($message) > 5000) {
            $errors[] = 'Message is required (max 5000 characters).';
        }

        if (!empty($errors)) {
            $meta = [
                'title'     => 'Contact — ' . Config::getString('site_name', 'LiteCMS'),
                'canonical' => Config::getString('site_url', '') . '/contact',
                'og_type'   => 'website',
            ];
            return $this->renderPublic('public/contact', [
                'title'   => 'Contact',
                'meta'    => $meta,
                'old'     => ['name' => $name, 'email' => $email, 'subject' => $subject, 'message' => $message],
                'error'   => implode(' ', $errors),
                'success' => '',
            ]);
        }

        // Store submission
        QueryBuilder::query('contact_submissions')->insert([
            'name'       => $name,
            'email'      => $email,
            'subject'    => $subject,
            'message'    => $message,
            'ip_address' => $request->server('REMOTE_ADDR', ''),
        ]);

        // Redirect with flash message (PRG pattern)
        $_SESSION['flash_success'] = 'Thank you for your message! We will get back to you soon.';
        return Response::redirect('/contact');
    }

    /**
     * Archive listing for a custom content type.
     */
    public function archive(Request $request, string $typeSlug): Response
    {
        // Look up the content type
        $contentType = QueryBuilder::query('content_types')
            ->select()
            ->where('slug', $typeSlug)
            ->where('has_archive', 1)
            ->first();

        if ($contentType === null) {
            return $this->notFound($request);
        }

        $perPage = Config::getInt('items_per_page', 10);
        $page = max(1, (int) ($request->query('page', '1')));
        $offset = ($page - 1) * $perPage;
        $now = gmdate('Y-m-d H:i:s');

        $total = QueryBuilder::query('content')
            ->select()
            ->where('type', $typeSlug)
            ->where('status', 'published')
            ->whereRaw('(published_at IS NULL OR published_at <= :now)', [':now' => $now])
            ->count();

        $totalPages = (int) ceil($total / $perPage);

        $items = QueryBuilder::query('content')
            ->select('content.*', 'users.username as author_name')
            ->leftJoin('users', 'users.id', '=', 'content.author_id')
            ->where('content.type', $typeSlug)
            ->where('content.status', 'published')
            ->whereRaw('(content.published_at IS NULL OR content.published_at <= :now)', [':now' => $now])
            ->orderBy('content.published_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $meta = [
            'title'       => $contentType['name'] . ' — ' . Config::getString('site_name', 'LiteCMS'),
            'description' => 'Browse all ' . strtolower($contentType['name']),
            'canonical'   => Config::getString('site_url', '') . '/' . $typeSlug,
            'og_type'     => 'website',
            'og_url'      => Config::getString('site_url', '') . '/' . $typeSlug,
        ];

        return $this->renderPublic('public/archive', [
            'title'        => $contentType['name'],
            'archiveTitle' => $contentType['name'],
            'archiveSlug'  => $typeSlug,
            'items'        => $items,
            'currentPage'  => $page,
            'totalPages'   => $totalPages,
            'total'        => $total,
            'meta'         => $meta,
        ]);
    }

    /**
     * 404 Not Found page.
     */
    public function notFound(Request $request): Response
    {
        $settings = $this->getPublicSettings();

        $html = $this->app->template()->render('public/404', [
            'title'       => 'Page Not Found',
            'navPages'    => $this->getNavPages(),
            'siteName'    => Config::getString('site_name', 'LiteCMS'),
            'siteUrl'     => Config::getString('site_url', ''),
            'currentSlug' => '',
            'consentText' => $settings['cookie_consent_text'] ?? '',
            'consentLink' => $settings['cookie_consent_link'] ?? '',
            'gaId'        => ($settings['ga_enabled'] ?? '') === '1' ? ($settings['ga_measurement_id'] ?? '') : '',
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
     * Common render helper — merges in navigation, global data, and settings.
     */
    private function renderPublic(string $template, array $data): Response
    {
        $settings = $this->getPublicSettings();

        $data = array_merge([
            'navPages'    => $this->getNavPages(),
            'siteName'    => Config::getString('site_name', 'LiteCMS'),
            'siteUrl'     => Config::getString('site_url', ''),
            'currentSlug' => '',
            'tagline'     => $settings['site_tagline'] ?? '',
            'consentText' => $settings['cookie_consent_text'] ?? '',
            'consentLink' => $settings['cookie_consent_link'] ?? '',
            'gaId'        => ($settings['ga_enabled'] ?? '') === '1' ? ($settings['ga_measurement_id'] ?? '') : '',
        ], $data);

        if (isset($data['content']['slug'])) {
            $data['currentSlug'] = $data['content']['slug'];
        }

        // Clear flash messages after reading
        if (isset($_SESSION['flash_success'])) {
            $data['success'] = $data['success'] ?? $_SESSION['flash_success'];
            unset($_SESSION['flash_success']);
        }

        $html = $this->app->template()->render($template, $data);
        return Response::html($html);
    }

    /**
     * Fetch public-relevant settings from the settings table.
     * Returns an associative array of key => value.
     * Gracefully returns empty array if settings table is empty.
     */
    private function getPublicSettings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        try {
            $rows = QueryBuilder::query('settings')
                ->select('key', 'value')
                ->whereRaw("key IN (:k1, :k2, :k3, :k4, :k5)", [
                    ':k1' => 'site_tagline',
                    ':k2' => 'cookie_consent_text',
                    ':k3' => 'cookie_consent_link',
                    ':k4' => 'ga_enabled',
                    ':k5' => 'ga_measurement_id',
                ])
                ->get();

            $this->settingsCache = [];
            foreach ($rows as $row) {
                $this->settingsCache[$row['key']] = $row['value'];
            }
        } catch (\Throwable $e) {
            $this->settingsCache = [];
        }

        return $this->settingsCache;
    }
}
