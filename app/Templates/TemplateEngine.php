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

    /**
     * Render a template file with data, optionally wrapping in a layout.
     */
    public function render(string $template, array $data = []): string
    {
        $this->layoutTemplate = null;

        $file = $this->basePath . '/' . $template . '.php';

        if (!file_exists($file)) {
            throw new RuntimeException("Template not found: {$template} (looked in {$file})");
        }

        // Extract data to local scope
        extract($data);

        ob_start();
        include $file;
        $output = ob_get_clean();

        // If the template declared a layout, wrap the output
        if ($this->layoutTemplate !== null) {
            $this->childContent = $output;
            $output = $this->render($this->layoutTemplate, $data);
            $this->childContent = '';
        }

        return $output;
    }

    /**
     * Declare a parent layout (called from within a template).
     */
    public function layout(string $template): void
    {
        $this->layoutTemplate = $template;
    }

    /**
     * Output child content (called from within a layout template).
     */
    public function content(): string
    {
        return $this->childContent;
    }

    /**
     * Render a partial template inline (no layout support).
     */
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

    /**
     * HTML-escape helper for XSS prevention.
     */
    public function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Output a hidden CSRF token field for forms.
     */
    public function csrfField(): string
    {
        $token = $_SESSION['csrf_token'] ?? '';
        return '<input type="hidden" name="_csrf_token" value="' . $this->e($token) . '">';
    }

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

        $desc = trim($meta['description'] ?? '');
        if ($desc !== '') {
            $html .= '    <meta name="description" content="' . $this->e($desc) . '">' . "\n";
        }

        $canonical = trim($meta['canonical'] ?? '');
        if ($canonical !== '') {
            $html .= '    <link rel="canonical" href="' . $this->e($canonical) . '">' . "\n";
        }

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
