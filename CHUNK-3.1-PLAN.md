# Chunk 3.1 — Template Engine & Front Controller
## Detailed Implementation Plan

---

## Overview

This chunk makes the public-facing website functional. It enhances the template engine with section/yield blocks, SEO meta helpers, and a navigation builder. It builds a front controller that maps public URLs to published content from the database and renders the appropriate template. At completion, visitors can browse published pages and blog posts by slug, see auto-generated navigation, and receive proper SEO meta/Open Graph tags on every page.

---

## Dependencies on Previous Chunks

This chunk builds on:
- **Chunk 1.1**: Router, Request, Response, App, TemplateEngine (render/layout/partial/e/csrfField)
- **Chunk 1.2**: QueryBuilder, Connection, content table schema
- **Chunk 1.3**: Auth system, Session (not directly used here, but middleware is already registered)
- **Chunk 2.1**: Admin layout, dashboard (admin side is untouched in this chunk)
- **Chunk 2.2**: ContentController (content exists in database with all fields)
- **Chunk 2.3**: Media management (featured images stored as paths)
- **Chunk 2.4**: User management (author names for blog posts)

**Key existing code this chunk relies on**:
- `App\Database\QueryBuilder` — for fetching published content by slug/type/status
- `App\Templates\TemplateEngine` — existing methods: `render()`, `layout()`, `content()`, `partial()`, `e()`, `csrfField()`
- `App\Core\Router` — for registering public routes with `{slug}` parameters
- `App\Core\App` — service container and route dispatch
- `App\Core\Config` — for site_name, site_url, items_per_page
- Content table columns: `slug`, `type`, `status`, `title`, `body`, `excerpt`, `meta_title`, `meta_description`, `featured_image`, `sort_order`, `published_at`, `author_id`

---

## File Creation/Modification Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. Update `app/Templates/TemplateEngine.php`

**Purpose**: Enhance the existing template engine with section/yield blocks, SEO meta tag helpers, navigation builder, and breadcrumb support. These additions support the public layout's needs without breaking existing admin template usage.

**Existing methods (DO NOT MODIFY)**:
- `render(string $template, array $data = []): string`
- `layout(string $template): void`
- `content(): string`
- `partial(string $template, array $data = []): string`
- `e(string $value): string`
- `csrfField(): string`

**New methods to add**:

```php
// --- Section/Yield System ---
// Allows templates to define named content blocks that the layout can yield.

startSection(string $name): void
    // Start capturing output for a named section.
    // Pushes section name onto a stack and starts ob_start().

endSection(): void
    // End the current section capture.
    // Pops from stack, stores ob_get_clean() into $this->sections[$name].

yieldSection(string $name, string $default = ''): string
    // Called from layout. Returns the content of a named section, or $default if not defined.

hasSection(string $name): bool
    // Check if a named section has been defined.

// --- SEO Meta Helpers ---

metaTags(array $meta): string
    // Generate HTML meta tags for SEO. Accepts an associative array:
    //   'title'            => string  (page title, used in <title> tag)
    //   'description'      => string  (meta description)
    //   'canonical'        => string  (canonical URL)
    //   'og_title'         => string  (Open Graph title, falls back to title)
    //   'og_description'   => string  (Open Graph description, falls back to description)
    //   'og_image'         => string  (Open Graph image URL)
    //   'og_type'          => string  (Open Graph type: 'website' or 'article')
    //   'og_url'           => string  (Open Graph URL, falls back to canonical)
    //   'article_author'   => string  (for og:article:author)
    //   'article_published'=> string  (for og:article:published_time)
    //
    // Returns concatenated HTML: <meta name="description" content="...">,
    //   <meta property="og:title" content="...">, etc.
    // All values are escaped via $this->e().

// --- Navigation Builder ---

navigation(array $pages, string $currentSlug = ''): string
    // Generate an HTML <ul> navigation list from an array of page records.
    // Each page record should have: 'title', 'slug'.
    // Marks the matching $currentSlug with class="active".
    // Escapes all output.

// --- Breadcrumb Builder ---

breadcrumbs(array $crumbs): string
    // Generate breadcrumb HTML from an array of ['label' => ..., 'url' => ...] pairs.
    // Last item is the current page (no link, uses <span>).
    // Uses <nav aria-label="Breadcrumb"><ol>...</ol></nav> for accessibility.
```

**New private properties**:
```
private array $sections = []
private array $sectionStack = []
```

**Implementation details**:
- `startSection()`: Push `$name` onto `$sectionStack`, call `ob_start()`.
- `endSection()`: Pop name from `$sectionStack`, store `ob_get_clean()` in `$sections[$name]`.
- `yieldSection()`: Return `$sections[$name] ?? $default`. Reset the section after yielding to avoid bleed between renders.
- `metaTags()`: Build individual `<meta>` and `<meta property="og:...">` tags. Skip empty values. Escape everything.
- `navigation()`: Build `<ul class="nav-list">` with `<li>` items. Each `<li>` contains an `<a href="/{slug}">`. Add `class="active"` to the matching slug.
- `breadcrumbs()`: Build `<nav aria-label="Breadcrumb"><ol class="breadcrumbs">` with `<li>` items. All but last are `<a>`, last is `<span aria-current="page">`.

**Full code template**:

```php
<?php declare(strict_types=1);

namespace App\Templates;

use RuntimeException;

class TemplateEngine
{
    private string $basePath;
    private ?string $layoutTemplate = null;
    private string $childContent = '';
    private array $sections = [];
    private array $sectionStack = [];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    // ---- EXISTING METHODS (unchanged) ----

    public function render(string $template, array $data = []): string
    {
        $this->layoutTemplate = null;

        $file = $this->basePath . '/' . $template . '.php';

        if (!file_exists($file)) {
            throw new RuntimeException("Template not found: {$template} (looked in {$file})");
        }

        extract($data);

        ob_start();
        include $file;
        $output = ob_get_clean();

        if ($this->layoutTemplate !== null) {
            $this->childContent = $output;
            $output = $this->render($this->layoutTemplate, $data);
            $this->childContent = '';
        }

        return $output;
    }

    public function layout(string $template): void
    {
        $this->layoutTemplate = $template;
    }

    public function content(): string
    {
        return $this->childContent;
    }

    public function partial(string $template, array $data = []): string
    {
        $file = $this->basePath . '/' . $template . '.php';

        if (!file_exists($file)) {
            throw new RuntimeException("Partial template not found: {$template}");
        }

        extract($data);
        ob_start();
        include $file;
        return ob_get_clean();
    }

    public function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public function csrfField(): string
    {
        $token = $_SESSION['csrf_token'] ?? '';
        return '<input type="hidden" name="_csrf_token" value="' . $this->e($token) . '">';
    }

    // ---- NEW METHODS (Chunk 3.1) ----

    /**
     * Start capturing output for a named section.
     */
    public function startSection(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    /**
     * End the current section capture.
     */
    public function endSection(): void
    {
        if (empty($this->sectionStack)) {
            throw new RuntimeException('endSection() called without matching startSection()');
        }

        $name = array_pop($this->sectionStack);
        $this->sections[$name] = ob_get_clean();
    }

    /**
     * Yield a named section's content (called from layout).
     */
    public function yieldSection(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Check if a named section has been defined.
     */
    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    /**
     * Generate HTML meta tags for SEO and Open Graph.
     */
    public function metaTags(array $meta): string
    {
        $html = '';

        // Meta description
        $desc = trim($meta['description'] ?? '');
        if ($desc !== '') {
            $html .= '    <meta name="description" content="' . $this->e($desc) . '">' . "\n";
        }

        // Canonical URL
        $canonical = trim($meta['canonical'] ?? '');
        if ($canonical !== '') {
            $html .= '    <link rel="canonical" href="' . $this->e($canonical) . '">' . "\n";
        }

        // Open Graph tags
        $ogTitle = trim($meta['og_title'] ?? $meta['title'] ?? '');
        if ($ogTitle !== '') {
            $html .= '    <meta property="og:title" content="' . $this->e($ogTitle) . '">' . "\n";
        }

        $ogDesc = trim($meta['og_description'] ?? $meta['description'] ?? '');
        if ($ogDesc !== '') {
            $html .= '    <meta property="og:description" content="' . $this->e($ogDesc) . '">' . "\n";
        }

        $ogType = trim($meta['og_type'] ?? 'website');
        $html .= '    <meta property="og:type" content="' . $this->e($ogType) . '">' . "\n";

        $ogUrl = trim($meta['og_url'] ?? $meta['canonical'] ?? '');
        if ($ogUrl !== '') {
            $html .= '    <meta property="og:url" content="' . $this->e($ogUrl) . '">' . "\n";
        }

        $ogImage = trim($meta['og_image'] ?? '');
        if ($ogImage !== '') {
            $html .= '    <meta property="og:image" content="' . $this->e($ogImage) . '">' . "\n";
        }

        // Article-specific OG tags
        $articleAuthor = trim($meta['article_author'] ?? '');
        if ($articleAuthor !== '' && $ogType === 'article') {
            $html .= '    <meta property="article:author" content="' . $this->e($articleAuthor) . '">' . "\n";
        }

        $articlePublished = trim($meta['article_published'] ?? '');
        if ($articlePublished !== '' && $ogType === 'article') {
            $html .= '    <meta property="article:published_time" content="' . $this->e($articlePublished) . '">' . "\n";
        }

        return $html;
    }

    /**
     * Generate an HTML navigation list from page records.
     */
    public function navigation(array $pages, string $currentSlug = ''): string
    {
        if (empty($pages)) {
            return '';
        }

        $html = '<ul class="nav-list">' . "\n";
        foreach ($pages as $page) {
            $slug = $page['slug'] ?? '';
            $title = $page['title'] ?? '';
            $activeClass = ($slug === $currentSlug) ? ' class="active"' : '';
            $html .= '    <li' . $activeClass . '><a href="/' . $this->e($slug) . '">' . $this->e($title) . '</a></li>' . "\n";
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * Generate accessible breadcrumb HTML.
     */
    public function breadcrumbs(array $crumbs): string
    {
        if (empty($crumbs)) {
            return '';
        }

        $html = '<nav aria-label="Breadcrumb"><ol class="breadcrumbs">' . "\n";
        $last = count($crumbs) - 1;

        foreach ($crumbs as $i => $crumb) {
            $label = $this->e($crumb['label'] ?? '');
            if ($i === $last) {
                $html .= '    <li><span aria-current="page">' . $label . '</span></li>' . "\n";
            } else {
                $url = $this->e($crumb['url'] ?? '/');
                $html .= '    <li><a href="' . $url . '">' . $label . '</a></li>' . "\n";
            }
        }

        $html .= '</ol></nav>';

        return $html;
    }
}
```

---

### 2. `app/Templates/FrontController.php` (NEW)

**Purpose**: Routes public URLs to content from the database and renders the appropriate template. This is the central public-site controller.

**Class**: `App\Templates\FrontController`

**Design**:
- Follows the same controller pattern as admin controllers: receives `App` instance in constructor.
- Queries the `content` table for published items by slug/type.
- Selects the correct template based on content type and the optional `template` column.
- Builds SEO meta data from content fields.
- Fetches navigation pages for the layout.
- Handles scheduling: only shows content where `published_at IS NULL OR published_at <= NOW()`.

**Constructor**:
```php
__construct(App\Core\App $app)
    // Stores $app for template rendering and config access.
```

**Public methods**:
```php
homepage(Request $request): Response
    // Renders the homepage.
    // Fetches recent published posts (limit from config items_per_page) for display.
    // Fetches published pages for navigation.
    // Uses template 'public/home'.

blogIndex(Request $request): Response
    // Renders the blog listing with pagination.
    // Fetches published posts (type='post') ordered by published_at DESC.
    // Pagination via ?page=N query param, items_per_page from config.
    // Uses template 'public/blog-index'.

blogPost(Request $request, string $slug): Response
    // Fetches a single published post by slug.
    // If not found or not published or scheduled for future → 404.
    // Joins with users table to get author name.
    // Uses template 'public/blog-post'.

page(Request $request, string $slug): Response
    // Fetches a single published page by slug.
    // If not found or not published → 404.
    // Checks content.template column; if set, uses that template, otherwise 'public/page'.
    // Uses template 'public/page' (or custom template if specified).

notFound(Request $request): Response
    // Renders the 404 page.
    // Returns Response with status 404.
```

**Private helper methods**:
```php
private getNavPages(): array
    // Fetches all published pages ordered by sort_order ASC.
    // Returns array of ['title' => ..., 'slug' => ...] for the navigation builder.
    // Filters: type='page', status='published', (published_at IS NULL OR published_at <= NOW()).

private isPublished(array $content): bool
    // Checks if a content item is visible:
    //   - status must be 'published'
    //   - published_at must be NULL or <= current time (UTC)

private buildMeta(array $content, string $type = 'website'): array
    // Builds the meta array for TemplateEngine::metaTags() from a content record.
    //   'title'       => $content['meta_title'] ?: $content['title']
    //   'description' => $content['meta_description'] ?: substr(strip_tags($content['excerpt'] ?? $content['body']), 0, 160)
    //   'canonical'   => Config::getString('site_url') . '/' . $content['slug']
    //   'og_title'    => same as title
    //   'og_description' => same as description
    //   'og_image'    => $content['featured_image'] (full URL if set)
    //   'og_type'     => $type ('article' for posts, 'website' for pages)
    //   'og_url'      => same as canonical
    //   'article_author'    => (for posts only)
    //   'article_published' => (for posts only)

private renderPublic(string $template, array $data): Response
    // Common render helper.
    // Merges in: navigation pages, site_name, site_url, current_year.
    // Calls $this->app->template()->render($template, $data).
    // Returns Response::html($html).
```

**Full code template**:

```php
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

        // Fetch recent published posts
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

        // Count total published posts
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

        // Fetch paginated posts
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

        // Determine which template to use
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
            'title'     => 'Page Not Found',
            'navPages'  => $this->getNavPages(),
            'siteName'  => Config::getString('site_name', 'LiteCMS'),
            'siteUrl'   => Config::getString('site_url', ''),
            'currentSlug' => '',
            'meta'      => [
                'title' => 'Page Not Found — ' . Config::getString('site_name', 'LiteCMS'),
            ],
        ]);

        return Response::html($html, 404);
    }

    // ---- Private Helpers ----

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

        // Check scheduled publishing
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

        // Build description: prefer meta_description, fall back to excerpt, then body
        $description = $content['meta_description'] ?? '';
        if ($description === '' || $description === null) {
            $raw = $content['excerpt'] ?? $content['body'] ?? '';
            $description = mb_substr(strip_tags($raw), 0, 160, 'UTF-8');
        }

        // Build featured image full URL
        $ogImage = '';
        if (!empty($content['featured_image'])) {
            $image = $content['featured_image'];
            // If relative path, prepend site URL
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
            'title'       => $content['meta_title'] ?: $content['title'],
            'description' => $description,
            'canonical'   => $canonical,
            'og_title'    => $content['meta_title'] ?: $content['title'],
            'og_description' => $description,
            'og_image'    => $ogImage,
            'og_type'     => $type,
            'og_url'      => $canonical,
        ];

        // Article-specific meta
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
        // Merge in global data for the public layout
        $data = array_merge([
            'navPages'    => $this->getNavPages(),
            'siteName'    => Config::getString('site_name', 'LiteCMS'),
            'siteUrl'     => Config::getString('site_url', ''),
            'currentSlug' => '',
        ], $data);

        // Extract current slug from content if available
        if (isset($data['content']['slug'])) {
            $data['currentSlug'] = $data['content']['slug'];
        }

        $html = $this->app->template()->render($template, $data);
        return Response::html($html);
    }
}
```

---

### 3. Update `templates/public/layout.php`

**Purpose**: Replace the minimal placeholder layout with a full HTML5 layout that includes SEO meta/Open Graph tags, auto-generated navigation, breadcrumbs, and a proper footer.

**Current state**: Minimal layout with hardcoded nav link, no meta tags, no dynamic navigation.

**New layout features**:
- `<title>` from `$meta['title']` or `$title`
- SEO meta tags via `$this->metaTags($meta)` (meta description, OG tags, canonical)
- Auto-generated `<nav>` from `$navPages` via `$this->navigation($navPages, $currentSlug)`
- `$this->yieldSection('head')` for per-page additional head content
- Semantic HTML5 structure: `<header>`, `<main>`, `<footer>`
- Site name in header links to homepage
- Footer with copyright and site name

**Full code template**:

```php
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->e(($meta['title'] ?? $title ?? '') . ' — ' . ($siteName ?? 'LiteCMS')) ?></title>
<?php if (!empty($meta)): ?>
<?= $this->metaTags($meta) ?>
<?php endif; ?>
    <meta property="og:site_name" content="<?= $this->e($siteName ?? 'LiteCMS') ?>">
<?= $this->yieldSection('head') ?>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a href="/" class="site-logo"><?= $this->e($siteName ?? 'LiteCMS') ?></a>
            <nav class="site-nav" aria-label="Main navigation">
                <ul class="nav-list">
                    <li<?= (($currentSlug ?? '') === '' && ($title ?? '') !== 'Blog' && ($title ?? '') !== 'Page Not Found') ? ' class="active"' : '' ?>><a href="/">Home</a></li>
<?php if (!empty($navPages)): ?>
<?php foreach ($navPages as $navPage): ?>
                    <li<?= (($currentSlug ?? '') === $navPage['slug']) ? ' class="active"' : '' ?>><a href="/<?= $this->e($navPage['slug']) ?>"><?= $this->e($navPage['title']) ?></a></li>
<?php endforeach; ?>
<?php endif; ?>
                    <li<?= (($title ?? '') === 'Blog') ? ' class="active"' : '' ?>><a href="/blog">Blog</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="site-main">
        <div class="container">
<?= $this->content() ?>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> <?= $this->e($siteName ?? 'LiteCMS') ?>. All rights reserved.</p>
        </div>
    </footer>
<?= $this->yieldSection('scripts') ?>
</body>
</html>
```

**Design decisions**:
- Navigation includes a "Home" link and a "Blog" link as fixed items, with dynamic pages from the DB in between.
- Active state is determined by matching `$currentSlug` to the page slug.
- The `head` and `scripts` sections allow child templates to inject per-page CSS or JS.
- The `<title>` format is `Page Title — Site Name` per SEO best practice.
- On the homepage itself, the `<title>` will be just `Site Name — Site Name`, which the homepage template will handle by setting `meta.title` to just the site name directly.

---

### 4. `templates/public/404.php` (NEW)

**Purpose**: A user-friendly 404 page that extends the public layout.

**Full code template**:

```php
<?php $this->layout('public/layout'); ?>

<div class="error-page">
    <h1>404 — Page Not Found</h1>
    <p>Sorry, the page you're looking for doesn't exist or has been moved.</p>
    <p><a href="/">Return to homepage</a></p>
</div>
```

---

### 5. Update `templates/public/home.php`

**Purpose**: Replace the placeholder homepage with a proper template that shows recent blog posts. (The full homepage with hero/CTA comes in Chunk 3.2 — this version provides the functional foundation.)

**Full code template**:

```php
<?php $this->layout('public/layout'); ?>

<div class="homepage">
    <h1>Welcome to <?= $this->e($siteName ?? $title) ?></h1>

<?php if (!empty($posts)): ?>
    <section class="recent-posts">
        <h2>Recent Posts</h2>
<?php foreach ($posts as $post): ?>
        <article class="post-card">
<?php if (!empty($post['featured_image'])): ?>
            <div class="post-card-image">
                <a href="/blog/<?= $this->e($post['slug']) ?>">
                    <img src="<?= $this->e($post['featured_image']) ?>" alt="<?= $this->e($post['title']) ?>">
                </a>
            </div>
<?php endif; ?>
            <div class="post-card-body">
                <h3><a href="/blog/<?= $this->e($post['slug']) ?>"><?= $this->e($post['title']) ?></a></h3>
                <div class="post-meta">
                    <time datetime="<?= $this->e($post['published_at'] ?? $post['created_at']) ?>">
                        <?= date('M j, Y', strtotime($post['published_at'] ?? $post['created_at'])) ?>
                    </time>
                    <span class="post-author">by <?= $this->e($post['author_name'] ?? 'Unknown') ?></span>
                </div>
<?php if (!empty($post['excerpt'])): ?>
                <p class="post-excerpt"><?= $this->e($post['excerpt']) ?></p>
<?php else: ?>
                <p class="post-excerpt"><?= $this->e(mb_substr(strip_tags($post['body']), 0, 160, 'UTF-8')) ?>...</p>
<?php endif; ?>
                <a href="/blog/<?= $this->e($post['slug']) ?>" class="read-more">Read more</a>
            </div>
        </article>
<?php endforeach; ?>
    </section>
<?php else: ?>
    <p>No posts published yet. Check back soon!</p>
<?php endif; ?>
</div>
```

---

### 6. Update `public/index.php`

**Purpose**: Replace the inline homepage closure route with FrontController routes. Add public routes for blog listing, blog post by slug, and page by slug (catch-all).

**Changes to make**:
1. Add `use App\Templates\FrontController;` import.
2. Replace the inline `/` route with `FrontController::homepage`.
3. Add `/blog` route for blog index.
4. Add `/blog/{slug}` route for individual blog posts.
5. Add `/{slug}` catch-all route for pages (MUST be registered LAST since it matches any single-segment URL).
6. Update the 404 handler in `App::run()` to use FrontController's 404 page (done via modifying App.php — see next item).

**Specific route changes in `public/index.php`**:

```php
// REMOVE this route:
$router->get('/', function($request) use ($app) {
    return new Response(
        $app->template()->render('public/home', [
            'title' => Config::getString('site_name'),
        ])
    );
});

// ADD these routes (AFTER admin routes, BEFORE $app->run()):

// Public routes (order matters — specific routes before catch-all)
$router->get('/', [FrontController::class, 'homepage']);
$router->get('/blog', [FrontController::class, 'blogIndex']);
$router->get('/blog/{slug}', [FrontController::class, 'blogPost']);
// Catch-all for pages by slug (MUST be last)
$router->get('/{slug}', [FrontController::class, 'page']);
```

**Import to add**:
```php
use App\Templates\FrontController;
```

---

### 7. Update `app/Core/App.php`

**Purpose**: Update the 404 fallback to render the styled public 404 template instead of a raw `<h1>404 Not Found</h1>`.

**Change in `run()` method**:

```php
// REPLACE this:
if ($match === null) {
    $response = Response::html('<h1>404 Not Found</h1>', 404);
    $response->send();
    return;
}

// WITH this:
if ($match === null) {
    $controller = new \App\Templates\FrontController($this);
    $response = $controller->notFound($request);
    $response->send();
    return;
}
```

---

## Detailed Class Specifications

### `App\Templates\TemplateEngine` (updated)

```
EXISTING PROPERTIES (unchanged):
  - private string $basePath
  - private ?string $layoutTemplate = null
  - private string $childContent = ''

NEW PROPERTIES:
  - private array $sections = []
  - private array $sectionStack = []

EXISTING METHODS (unchanged):
  - __construct(string $basePath)
  - render(string $template, array $data = []): string
  - layout(string $template): void
  - content(): string
  - partial(string $template, array $data = []): string
  - e(string $value): string
  - csrfField(): string

NEW METHODS:
  - startSection(string $name): void
      Push $name onto $sectionStack, call ob_start().

  - endSection(): void
      Pop from $sectionStack, store ob_get_clean() in $sections[$name].
      Throw RuntimeException if stack is empty.

  - yieldSection(string $name, string $default = ''): string
      Return $sections[$name] ?? $default.

  - hasSection(string $name): bool
      Return isset($sections[$name]).

  - metaTags(array $meta): string
      Build HTML meta tags from associative array. Keys:
      description, canonical, og_title, og_description, og_type,
      og_url, og_image, article_author, article_published.
      Skip empty values. Escape everything via $this->e().
      Returns multi-line HTML string.

  - navigation(array $pages, string $currentSlug = ''): string
      Build <ul class="nav-list"> from page records.
      Each item: <li><a href="/{slug}">{title}</a></li>.
      Active item gets class="active" on the <li>.

  - breadcrumbs(array $crumbs): string
      Build <nav aria-label="Breadcrumb"><ol class="breadcrumbs">.
      Each crumb: ['label' => string, 'url' => string].
      Last item is <span aria-current="page"> (no link).
```

### `App\Templates\FrontController` (new)

```
PROPERTIES:
  - private App $app

CONSTRUCTOR:
  __construct(App $app) — stores $app

PUBLIC METHODS:
  - homepage(Request $request): Response
      Fetches recent published posts.
      Renders 'public/home' with posts, meta, nav.

  - blogIndex(Request $request): Response
      Fetches published posts with pagination.
      Reads ?page=N from query. Computes offset.
      Renders 'public/blog-index' with posts, pagination data, meta.

  - blogPost(Request $request, string $slug): Response
      Fetches single post by slug with author JOIN.
      Checks isPublished(). Returns 404 if not found.
      Renders 'public/blog-post' with content, meta.

  - page(Request $request, string $slug): Response
      Fetches any content by slug with author JOIN.
      If post type → 301 redirect to /blog/{slug}.
      Checks isPublished(). Returns 404 if not found.
      Uses content.template column if set, falls back to 'public/page'.
      Renders with content, meta.

  - notFound(Request $request): Response
      Renders 'public/404' with nav, site info.
      Returns Response with HTTP 404 status.

PRIVATE METHODS:
  - getNavPages(): array
      SELECT title, slug FROM content
      WHERE type='page' AND status='published'
      AND (published_at IS NULL OR published_at <= NOW())
      ORDER BY sort_order ASC.

  - isPublished(array $content): bool
      Returns true if status='published' AND
      (published_at is empty OR published_at <= now).

  - buildMeta(array $content, string $type = 'website'): array
      Builds SEO meta array from content record.
      Uses meta_title or title, meta_description or excerpt or body truncated.
      Builds canonical URL, OG tags, article metadata.

  - renderPublic(string $template, array $data): Response
      Merges in navPages, siteName, siteUrl, currentSlug.
      Renders template, returns Response::html().
```

---

## Route Map After This Chunk

| Method | Pattern | Handler | Notes |
|--------|---------|---------|-------|
| GET | `/` | FrontController::homepage | Recent posts, welcome |
| GET | `/blog` | FrontController::blogIndex | Paginated post listing |
| GET | `/blog/{slug}` | FrontController::blogPost | Single blog post |
| GET | `/{slug}` | FrontController::page | Single page (catch-all, LAST) |
| GET | `/admin/login` | AuthController::showLogin | (existing) |
| POST | `/admin/login` | AuthController::handleLogin | (existing) |
| POST | `/admin/logout` | AuthController::logout | (existing) |
| GET | `/admin/dashboard` | DashboardController::index | (existing) |
| GET | `/admin/content` | ContentController::index | (existing) |
| ... | `/admin/*` | ... | (all existing admin routes) |

**Route registration order matters**: The `/{slug}` catch-all MUST be registered after all other GET routes, otherwise it will match `/blog` and `/admin/...` before those specific routes get a chance.

---

## Acceptance Test Procedures

### Test 1: Published page accessible by slug
```
1. Log in to admin. Create a page with title "About Us", slug "about", status "published".
2. Visit http://localhost/about
3. Verify: HTTP 200, page displays "About Us" title and body content.
4. Verify: Page is wrapped in public layout with navigation.
```

### Test 2: Draft page returns 404
```
1. Create a page with title "Secret Page", slug "secret", status "draft".
2. Visit http://localhost/secret
3. Verify: HTTP 404, styled 404 page is shown.
```

### Test 3: Navigation shows published pages in sort_order
```
1. Create published pages: "About" (sort_order=1), "Services" (sort_order=2), "Contact" (sort_order=3).
2. Visit any public page.
3. Verify: Navigation shows Home, About, Services, Contact, Blog — in that order.
4. Change "Contact" sort_order to 0.
5. Refresh. Verify: Contact now appears before About in navigation.
```

### Test 4: Page source includes correct SEO meta tags
```
1. Create a published page with:
   - title: "Our Services"
   - slug: "services"
   - meta_title: "Professional Services | MyBiz"
   - meta_description: "We offer high-quality professional services."
   - featured_image: "/assets/uploads/abc123.jpg"
2. Visit http://localhost/services
3. View page source. Verify:
   - <title> contains "Professional Services | MyBiz"
   - <meta name="description"> contains the meta_description text
   - <meta property="og:title"> contains the meta_title
   - <meta property="og:description"> contains the description
   - <meta property="og:image"> contains the image URL
   - <meta property="og:type"> is "website"
   - <link rel="canonical"> points to the correct URL
```

### Test 5: Non-existent slug returns styled 404
```
1. Visit http://localhost/nonexistent-page-xyz
2. Verify: HTTP 404 status code.
3. Verify: Styled 404 page with navigation and "Return to homepage" link.
```

### Test 6: Scheduled post not visible before publish date
```
1. Create a post with:
   - title: "Future Post"
   - slug: "future-post"
   - status: "published"
   - published_at: tomorrow's date
2. Visit http://localhost/blog/future-post
3. Verify: HTTP 404 (post is scheduled for the future).
4. Change published_at to yesterday's date.
5. Visit http://localhost/blog/future-post
6. Verify: HTTP 200, post content is visible.
```

### Test 7: Blog index with pagination
```
1. Create 15 published posts (status=published, published_at in the past).
2. Visit http://localhost/blog
3. Verify: First 10 posts shown (assuming items_per_page=10).
4. Visit http://localhost/blog?page=2
5. Verify: Remaining 5 posts shown.
```

### Test 8: Blog post displays author and date
```
1. Create a published post with author assigned.
2. Visit http://localhost/blog/{slug}
3. Verify: Author name and publish date are displayed.
4. Verify: <meta property="og:type"> is "article".
5. Verify: article:author and article:published_time OG tags present.
```

### Test 9: Post accessed via /{slug} redirects to /blog/{slug}
```
1. Create a published post with slug "my-post".
2. Visit http://localhost/my-post
3. Verify: HTTP 301 redirect to http://localhost/blog/my-post
```

### Test 10: Homepage shows recent posts
```
1. Create several published posts.
2. Visit http://localhost/
3. Verify: Recent posts displayed with titles, excerpts, dates, and "Read more" links.
4. Click "Read more" — navigates to /blog/{slug}.
```

### Test 11: Archived content returns 404
```
1. Create a page with status "archived".
2. Visit http://localhost/{slug}
3. Verify: HTTP 404.
```

---

## Implementation Notes

### Template variable naming conventions
All public templates receive these standard variables via `renderPublic()`:
- `$navPages` — array of page records for navigation
- `$siteName` — site name from config
- `$siteUrl` — site URL from config
- `$currentSlug` — current page slug for nav active state
- `$meta` — SEO meta array for `$this->metaTags($meta)`
- `$title` — page title string

### URL structure decisions
- Pages: `/{slug}` — flat URL structure, matches WordPress convention
- Posts: `/blog/{slug}` — grouped under /blog for clarity
- Blog index: `/blog` — listing page
- A post accessed via `/{slug}` does a 301 redirect to `/blog/{slug}` for canonical URLs

### Scheduling logic
A content item is publicly visible only if:
1. `status = 'published'` AND
2. `published_at IS NULL` (published immediately) OR `published_at <= NOW()` (publish time has passed)

This means setting `published_at` to a future date effectively schedules the content, even though its status is already "published".

### Navigation building
Navigation pages are fetched once per page render via `getNavPages()`. In a future performance optimization (Chunk 7.1), this could be cached. For now, a single extra query per page load is acceptable.

### Section/Yield system
The section/yield system is intentionally simple — it does NOT support section inheritance or appending. A section defined in a child template replaces whatever the layout yields for that section. This is sufficient for injecting per-page `<head>` content and `<script>` tags.

### What this chunk does NOT do
- No public CSS styling (comes in Chunk 3.2)
- No contact form (comes in Chunk 3.2)
- No cookie consent banner (comes in Chunk 3.2)
- No Google Analytics (comes in Chunk 3.2)
- No custom content type archives (comes in Chunk 5.1)
- Templates created here are functional but unstyled — CSS arrives in Chunk 3.2

### Edge cases handled
- Empty blog (no posts): Homepage shows "No posts published yet" message
- Post with no excerpt: Auto-generates excerpt from first 160 chars of body (stripped of HTML)
- Content with no meta_title: Falls back to content title
- Content with no meta_description: Falls back to excerpt, then body truncation
- Featured image with relative path: Prepended with site_url for OG tag
- Multiple content items with same slug: Impossible due to UNIQUE constraint on slug column
- `/{slug}` matching admin routes: Not possible because admin routes are registered first and matched before the catch-all

### Coding standards
- Every `.php` file: `<?php declare(strict_types=1);`
- No framework imports — native PHP only
- All output escaped via `$this->e()` in templates
- All database queries use parameterized bindings via QueryBuilder
- PSR-4 namespacing: `App\Templates\FrontController` → `app/Templates/FrontController.php`

---

## File Checklist

| # | File | Action | Type |
|---|------|--------|------|
| 1 | `app/Templates/TemplateEngine.php` | Modify | Class (add 6 new methods, 2 properties) |
| 2 | `app/Templates/FrontController.php` | Create | Class (new file) |
| 3 | `templates/public/layout.php` | Replace | Template (full rewrite) |
| 4 | `templates/public/404.php` | Create | Template (new file) |
| 5 | `templates/public/home.php` | Replace | Template (full rewrite) |
| 6 | `public/index.php` | Modify | Entry point (add import + routes) |
| 7 | `app/Core/App.php` | Modify | Class (update 404 fallback) |

---

## Estimated Scope

- **New PHP class**: 1 (FrontController) — ~180 lines
- **Modified PHP class**: 2 (TemplateEngine ~+80 lines, App ~+2 lines)
- **Templates created/modified**: 3 (layout rewrite, 404 new, home rewrite)
- **Entry point changes**: Route registration additions (~5 lines)
- **Total new PHP LOC**: ~260 lines
- **Total template LOC**: ~80 lines
