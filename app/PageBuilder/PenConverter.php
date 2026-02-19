<?php declare(strict_types=1);

namespace App\PageBuilder;

/**
 * Main .pen-to-HTML converter.
 * Reads a .pen JSON document, resolves components and variables,
 * and generates semantic HTML + CSS.
 */
class PenConverter
{
    private array $document;
    private array $components = [];
    private array $cssRules = [];
    private array $iconFontImports = [];
    private array $variables = [];
    private array $themes = [];
    private int $refDepth = 0;
    private string $parentLayout = 'none';

    /** @var array<string, string> Runtime variable overrides from settings */
    private array $variableOverrides = [];

    private const MAX_REF_DEPTH = 10;

    private function __construct(array $document)
    {
        $this->document = $document;
        $this->variables = $document['variables'] ?? [];
        $this->themes = $document['themes'] ?? [];
        $this->buildComponentRegistry();
    }

    // --- Public static entry points ---

    /**
     * Convert a .pen file to HTML + CSS.
     *
     * @return array{html: string, css: string}
     * @throws \RuntimeException if file not found or unreadable
     */
    public static function convertFile(string $penPath, array $variableOverrides = []): array
    {
        if (!file_exists($penPath)) {
            throw new \RuntimeException("PEN file not found: {$penPath}");
        }
        $contents = file_get_contents($penPath);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read PEN file: {$penPath}");
        }
        $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        return self::convertDocument($document, $variableOverrides);
    }

    /**
     * Convert a .pen JSON string to HTML + CSS.
     *
     * @return array{html: string, css: string}
     * @throws \JsonException on invalid JSON
     */
    public static function convertJson(string $json): array
    {
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return self::convertDocument($document);
    }

    /**
     * Convert a pre-parsed .pen document array to HTML + CSS.
     *
     * @return array{html: string, css: string}
     * @throws \RuntimeException if document is invalid
     */
    public static function convertDocument(array $document, array $variableOverrides = []): array
    {
        if (!isset($document['children'])) {
            throw new \RuntimeException('Invalid .pen document: missing children');
        }
        $instance = new self($document);
        if (!empty($variableOverrides)) {
            $instance->setVariableOverrides($variableOverrides);
        }
        return $instance->convert();
    }

    /**
     * Set runtime variable overrides (from admin settings).
     * Keys are variable names (without --), values are CSS values.
     */
    public function setVariableOverrides(array $overrides): void
    {
        $this->variableOverrides = $overrides;
    }

    /**
     * Extract variable definitions from a .pen file.
     * Returns array of variable metadata for settings UI.
     */
    public static function extractVariables(string $penPath): array
    {
        if (!file_exists($penPath)) {
            return [];
        }

        $json = file_get_contents($penPath);
        $doc = json_decode($json, true);
        if (!$doc || !is_array($doc)) {
            return [];
        }

        $variables = $doc['variables'] ?? [];
        $result = [];

        foreach ($variables as $name => $def) {
            $type = $def['type'] ?? 'string';
            $value = $def['value'] ?? null;

            $entry = [
                'type' => $type,
                'themed' => false,
                'values' => [],
            ];

            if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                // Themed variable
                $entry['themed'] = true;
                foreach ($value as $v) {
                    $theme = $v['theme'] ?? [];
                    $themeKey = empty($theme) ? 'default' : implode('/', array_map(
                        fn($k, $val) => "{$k}:{$val}",
                        array_keys($theme),
                        array_values($theme)
                    ));
                    $entry['values'][$themeKey] = $v['value'] ?? '';
                }
            } else {
                $entry['values']['default'] = $value;
            }

            $result[$name] = $entry;
        }

        return $result;
    }

    // --- Public methods called by PenNodeRenderer ---

    public function getComponent(string $id): ?array
    {
        return $this->components[$id] ?? null;
    }

    public function getParentLayout(): string
    {
        return $this->parentLayout;
    }

    public function setParentLayout(string $layout): void
    {
        $this->parentLayout = $layout;
    }

    /**
     * Render a list of child nodes.
     * @return array{html: string, css: string}
     */
    public function renderChildren(array $children): array
    {
        $html = '';
        $css = '';
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $result = $this->renderNode($child);
            $html .= $result['html'];
            $css .= $result['css'];
        }
        return ['html' => $html, 'css' => $css];
    }

    /**
     * Render a single node, collecting CSS.
     * @return array{html: string, css: string}
     */
    public function renderNode(array $node): array
    {
        $result = PenNodeRenderer::renderNode($node, $this);
        if ($result['css'] !== '') {
            $this->cssRules[] = $result['css'];
        }
        return $result;
    }

    public function addIconFontImport(string $family, string $url): void
    {
        $this->iconFontImports[$family] = $url;
    }

    public function incrementRefDepth(): bool
    {
        $this->refDepth++;
        return $this->refDepth <= self::MAX_REF_DEPTH;
    }

    public function decrementRefDepth(): void
    {
        $this->refDepth = max(0, $this->refDepth - 1);
    }

    // --- Private methods ---

    /**
     * Run the full conversion pipeline.
     */
    private function convert(): array
    {
        $this->cssRules = [];
        $this->iconFontImports = [];

        // Render only non-reusable top-level children
        $html = '';
        foreach ($this->document['children'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            // Skip component definitions
            if (!empty($child['reusable'])) {
                continue;
            }
            $result = $this->renderNode($child);
            $html .= $result['html'];
        }

        // Assemble CSS
        $css = '';

        // Icon font imports
        foreach ($this->iconFontImports as $family => $url) {
            $css .= "@import url('{$url}');\n";
        }

        // Variable/theme CSS
        $varCss = $this->buildVariableCss();
        if ($varCss !== '') {
            $css .= $varCss . "\n";
        }

        // Reset base styles
        $css .= "/* PenConverter base */\n";
        $css .= "[class^=\"pen-\"] { box-sizing: border-box; }\n\n";

        // Collected node CSS rules
        $css .= implode("\n", $this->cssRules);

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Build component registry from document tree.
     */
    private function buildComponentRegistry(): void
    {
        $this->scanForComponents($this->document['children'] ?? []);
    }

    private function scanForComponents(array $children): void
    {
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (!empty($child['reusable'])) {
                $id = $child['id'] ?? '';
                if ($id !== '') {
                    $this->components[$id] = $child;
                }
            }
            // Recurse into children to find nested components
            if (!empty($child['children']) && is_array($child['children'])) {
                $this->scanForComponents($child['children']);
            }
        }
    }

    /**
     * Build CSS :root block and theme selectors from document variables.
     */
    private function buildVariableCss(): string
    {
        if (empty($this->variables)) {
            return '';
        }

        $rootVars = [];
        $themeVars = []; // ['dark' => ['--name' => 'value'], ...]

        foreach ($this->variables as $name => $def) {
            $type = $def['type'] ?? 'string';
            $value = $def['value'] ?? null;

            if ($value === null) {
                continue;
            }

            // Themed variable (array of {value, theme})
            if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                foreach ($value as $entry) {
                    $val = $entry['value'] ?? '';
                    $theme = $entry['theme'] ?? [];
                    $cssVal = self::formatVariableValue($type, $val);

                    if (empty($theme)) {
                        // Default/root
                        $rootVars["--{$name}"] = $cssVal;
                    } else {
                        // Theme-specific
                        $themeKey = self::buildThemeSelector($theme);
                        $themeVars[$themeKey]["--{$name}"] = $cssVal;
                    }
                }
            } else {
                // Non-themed variable
                $cssVal = self::formatVariableValue($type, $value);
                $rootVars["--{$name}"] = $cssVal;
            }
        }

        $css = '';
        if (!empty($rootVars)) {
            $css .= ":root {\n";
            foreach ($rootVars as $prop => $val) {
                $css .= "  {$prop}: {$val};\n";
            }
            $css .= "}\n";
        }

        foreach ($themeVars as $selector => $vars) {
            $css .= "{$selector} {\n";
            foreach ($vars as $prop => $val) {
                $css .= "  {$prop}: {$val};\n";
            }
            $css .= "}\n";
        }

        // Apply overrides from settings
        if (!empty($this->variableOverrides)) {
            $css .= "/* Settings overrides */\n:root {\n";
            foreach ($this->variableOverrides as $name => $val) {
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
                $safeVal = str_replace([';', '{', '}', '<', '>'], '', (string) $val);
                $css .= "  --{$safeName}: {$safeVal};\n";
            }
            $css .= "}\n";
        }

        return $css;
    }

    /**
     * Format a variable value for CSS output.
     */
    private static function formatVariableValue(string $type, mixed $value): string
    {
        return match ($type) {
            'color' => (string) $value,
            'number' => (string) $value,
            'string' => (string) $value,
            'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    /**
     * Build a CSS selector for a theme combination.
     * E.g., ['mode' => 'dark'] → '[data-theme-mode="dark"]'
     */
    private static function buildThemeSelector(array $theme): string
    {
        $selectors = [];
        foreach ($theme as $axis => $value) {
            $selectors[] = "[data-theme-{$axis}=\"{$value}\"]";
        }
        return implode('', $selectors);
    }
}
