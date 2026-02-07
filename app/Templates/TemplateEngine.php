<?php declare(strict_types=1);

namespace App\Templates;

use RuntimeException;

class TemplateEngine
{
    private string $basePath;
    private ?string $layoutTemplate = null;
    private string $childContent = '';

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
}
