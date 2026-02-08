<?php declare(strict_types=1);

namespace App\PageBuilder;

/**
 * Lightweight Mustache-like template engine for element slots.
 *
 * Syntax:
 *   {{key}}          — escaped text output
 *   {{{key}}}        — raw HTML output (for richtext)
 *   {{#key}}...{{/key}} — section: truthy conditional OR array loop
 *   {{^key}}...{{/key}} — inverted section: falsy / empty check
 *   {{key.sub}}      — dot notation for nested values
 */
class SlotRenderer
{
    /**
     * Render a template with the given slot data.
     */
    public static function render(string $template, array $data): string
    {
        // 1. Process sections (loops and conditionals): {{#key}}...{{/key}}
        $template = self::processSections($template, $data);

        // 2. Process inverted sections: {{^key}}...{{/key}}
        $template = self::processInvertedSections($template, $data);

        // 3. Replace triple-mustache (raw output): {{{key}}}
        $template = preg_replace_callback('/\{\{\{([a-zA-Z0-9_.]+)\}\}\}/', function ($m) use ($data) {
            return (string) self::resolve($m[1], $data);
        }, $template);

        // 4. Replace double-mustache (escaped output): {{key}}
        $template = preg_replace_callback('/\{\{([a-zA-Z0-9_.]+)\}\}/', function ($m) use ($data) {
            return htmlspecialchars((string) self::resolve($m[1], $data), ENT_QUOTES, 'UTF-8');
        }, $template);

        return $template;
    }

    /**
     * Process {{#key}}...{{/key}} sections.
     * - If value is an array of arrays (list), loop and render inner template per item.
     * - If value is truthy, render inner template once with current data.
     * - If value is falsy/empty, remove the section.
     */
    private static function processSections(string $template, array $data): string
    {
        // Match sections (non-greedy, handles nesting by processing innermost first)
        $maxDepth = 10;
        for ($i = 0; $i < $maxDepth; $i++) {
            $before = $template;
            $template = preg_replace_callback(
                '/\{\{#([a-zA-Z0-9_.]+)\}\}((?:(?!\{\{#|\{\{\/).)*?)\{\{\/\1\}\}/s',
                function ($m) use ($data) {
                    $key = $m[1];
                    $inner = $m[2];
                    $value = self::resolve($key, $data);

                    // List: array of arrays → loop
                    if (is_array($value) && !empty($value) && self::isSequential($value)) {
                        $out = '';
                        foreach ($value as $item) {
                            if (is_array($item)) {
                                // Merge item data with parent data (item takes precedence)
                                $out .= self::render($inner, array_merge($data, $item));
                            } else {
                                $out .= self::render($inner, $data);
                            }
                        }
                        return $out;
                    }

                    // Truthy: render once
                    if (!empty($value)) {
                        return self::render($inner, $data);
                    }

                    // Falsy: remove
                    return '';
                },
                $template
            );

            if ($template === $before) {
                break;
            }
        }

        return $template;
    }

    /**
     * Process {{^key}}...{{/key}} inverted sections.
     * Renders content only when value is falsy or empty.
     */
    private static function processInvertedSections(string $template, array $data): string
    {
        $maxDepth = 10;
        for ($i = 0; $i < $maxDepth; $i++) {
            $before = $template;
            $template = preg_replace_callback(
                '/\{\{\^([a-zA-Z0-9_.]+)\}\}((?:(?!\{\{\^|\{\{\/).)*?)\{\{\/\1\}\}/s',
                function ($m) use ($data) {
                    $key = $m[1];
                    $inner = $m[2];
                    $value = self::resolve($key, $data);

                    if (empty($value)) {
                        return self::render($inner, $data);
                    }

                    return '';
                },
                $template
            );

            if ($template === $before) {
                break;
            }
        }

        return $template;
    }

    /**
     * Resolve a dotted key path against the data array.
     *
     * e.g. "cta.url" resolves to $data['cta']['url']
     *
     * @return mixed
     */
    private static function resolve(string $key, array $data)
    {
        $parts = explode('.', $key);
        $current = $data;

        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return '';
            }
        }

        if (is_array($current)) {
            return $current;
        }

        if (is_bool($current)) {
            return $current;
        }

        return $current;
    }

    /**
     * Check if an array is a sequential (numeric-indexed) list.
     */
    private static function isSequential(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
