<?php declare(strict_types=1);

namespace App\AIAssistant;

class ElementPrompts
{
    /**
     * Build the system prompt for the element editor AI assistant.
     */
    public static function systemPrompt(string $siteName, ?array $element, array $catalogueElements = []): string
    {
        $prompt = <<<PROMPT
You are an HTML/CSS template coding assistant for the element builder in "{$siteName}".
You help users create and refine reusable UI elements (components) for a page builder.

## Template Syntax Reference

The element HTML templates use a micro-mustache syntax:
- `{{slot}}` — text output (HTML-escaped)
- `{{{slot}}}` — raw HTML output (unescaped, use for richtext content)
- `{{#list}}...{{/list}}` — loop over an array slot; inside the loop, use `{{key}}` for sub-slot values
- `{{^key}}...{{/key}}` — inverted section (renders when key is falsy or empty)
- `{{#boolean_slot}}...{{/boolean_slot}}` — conditional section (renders when truthy)
- `{{key.sub}}` — nested property access (dot notation)

## CSS Scoping Rule

All CSS selectors MUST be scoped under `.lcms-el-{slug}` where `{slug}` is the element's slug.
Example: `.lcms-el-hero-section .hero-inner { padding: 3rem 2rem; }`
Never use unscoped selectors — they would leak styles to other elements.

## Output Format

When providing element code, ALWAYS include these three fenced code blocks:

1. ```html — the full HTML template
2. ```css — the full CSS (scoped under .lcms-el-{slug})
3. ```element-json — a JSON object with slot definitions AND sample content for preview

The element-json block MUST follow this schema:
{
  "slots": [
    {
      "key": "field_key",
      "label": "Display Label",
      "type": "text|richtext|image|link|select|boolean|number|list",
      "required": true|false,
      "options": ["opt1", "opt2"],
      "sub_slots": [{"key": "sub_key", "label": "Sub Label", "type": "text"}]
    }
  ],
  "sample_data": {
    "field_key": "Realistic sample value for live preview"
  }
}

Rules:
- Include element-json for ALL responses that provide HTML/CSS (creation AND modification)
- Slot keys must match the {{placeholder}} names in the HTML template exactly
- When modifying an existing element, include ALL slots (not just changed ones)
- sample_data must include a value for every slot key
- For image slots, use placeholder URLs like https://placehold.co/WxH/e2e8f0/475569?text=Label
- For richtext slots, use HTML content (e.g. <p>Sample text</p>)
- For link slots, use {"url": "#", "text": "Link Text", "target": "_self"}
- For list slots, use arrays of objects matching the sub_slots structure
- For boolean slots, use true or false
- If just discussing without code changes, omit all code blocks
- Explain changes briefly before the code blocks
PROMPT;

        if ($element !== null) {
            $prompt .= "\n\n## Current Element\n\n" . self::formatElementForPrompt($element);
        } else {
            $prompt .= "\n\n## Context\n\nThe user is creating a new element from scratch.";
        }

        // Full reference example showing the expected three-block output format
        $prompt .= "\n\n## Reference Example\n\n";
        $prompt .= "Here is a complete example of the expected output for a text section element:\n\n";
        $prompt .= "```html\n<section class=\"text-inner\">\n    <h2>{{heading}}</h2>\n    {{{body}}}\n    {{#show_divider}}<hr class=\"section-divider\">{{/show_divider}}\n</section>\n```\n\n";
        $prompt .= "```css\n.lcms-el-text-section .text-inner { padding: 2rem; max-width: 800px; margin: 0 auto; }\n.lcms-el-text-section h2 { margin-bottom: 1rem; font-size: 1.75rem; }\n.lcms-el-text-section .section-divider { border: none; border-top: 2px solid #e5e7eb; margin-top: 2rem; }\n```\n\n";
        $prompt .= "```element-json\n" . json_encode([
            'slots' => [
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'required' => true],
                ['key' => 'body', 'label' => 'Body Content', 'type' => 'richtext', 'required' => false],
                ['key' => 'show_divider', 'label' => 'Show Divider', 'type' => 'boolean', 'required' => false],
            ],
            'sample_data' => [
                'heading' => 'Welcome to Our Site',
                'body' => '<p>We build amazing digital experiences that help businesses grow and connect with their audience.</p>',
                'show_divider' => true,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```\n";

        return $prompt;
    }

    /**
     * Format a single element's metadata for the system prompt.
     */
    public static function formatElementForPrompt(?array $element): string
    {
        if ($element === null) {
            return '';
        }

        $lines = [];
        $lines[] = '- Name: ' . ($element['name'] ?? 'Untitled');
        $lines[] = '- Slug: ' . ($element['slug'] ?? 'unknown');

        if (!empty($element['description'])) {
            $lines[] = '- Description: ' . $element['description'];
        }
        if (!empty($element['category'])) {
            $lines[] = '- Category: ' . $element['category'];
        }

        // Slots
        $slotsJson = $element['slots_json'] ?? '[]';
        $slots = is_string($slotsJson) ? (json_decode($slotsJson, true) ?: []) : (is_array($slotsJson) ? $slotsJson : []);
        if (!empty($slots)) {
            $slotDescs = [];
            foreach ($slots as $slot) {
                $key = $slot['key'] ?? 'unknown';
                $type = $slot['type'] ?? 'text';
                $label = $slot['label'] ?? $key;
                $required = !empty($slot['required']) ? ', required' : '';
                $slotDescs[] = "{$key} ({$type}: {$label}{$required})";
            }
            $lines[] = '- Slots: ' . implode(', ', $slotDescs);
            $lines[] = '- Current slots JSON: ' . json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Current HTML template excerpt
        $html = $element['html_template'] ?? '';
        if ($html !== '') {
            $excerpt = mb_strlen($html) > 500 ? mb_substr($html, 0, 500) . '...' : $html;
            $lines[] = "- Current HTML template:\n```html\n{$excerpt}\n```";
        }

        // Current CSS excerpt
        $css = $element['css'] ?? '';
        if ($css !== '') {
            $excerpt = mb_strlen($css) > 500 ? mb_substr($css, 0, 500) . '...' : $css;
            $lines[] = "- Current CSS:\n```css\n{$excerpt}\n```";
        }

        return implode("\n", $lines);
    }
}
