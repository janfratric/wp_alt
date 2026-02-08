<?php declare(strict_types=1);

namespace App\AIAssistant;

class GeneratorPrompts
{
    public static function gatheringPrompt(string $siteName, array $existingPages, ?array $contentTypeFields): string
    {
        $pagesList = self::formatExistingPages($existingPages);
        $fieldsDesc = $contentTypeFields !== null ? "\n\n" . self::formatCustomFields($contentTypeFields) : '';

        return <<<PROMPT
You are a professional web content planning assistant for "{$siteName}".
Your job is to help the user plan a new webpage by asking smart, focused questions.

{$pagesList}{$fieldsDesc}

Guidelines:
- Ask 2-3 focused questions per message. Do not overwhelm the user with too many questions at once.
- Start by asking about the page's purpose and target audience.
- Then progressively ask about: desired sections/structure, key content points, tone/style preferences, and any specific details they want included.
- If the user is vague, suggest concrete options (e.g., "Would you like a hero section with a headline and call-to-action, or a more informational layout with sections?").
- Consider how this new page fits with the existing site structure.
- Keep your responses conversational and helpful. Be encouraging.
- When you have gathered enough information to generate a complete, high-quality page, include the exact marker READY_TO_GENERATE on its own line at the END of your message. Before the marker, give the user a brief summary of what you'll generate so they can confirm or adjust.
- Do NOT generate the actual page content during this phase — only gather and confirm requirements.
PROMPT;
    }

    public static function generationPrompt(string $siteName, string $contentType, ?array $contentTypeFields): string
    {
        $fieldsDesc = '';
        $fieldsJson = '';
        if ($contentTypeFields !== null) {
            $fieldsDesc = "\n\n" . self::formatCustomFields($contentTypeFields);
            $keys = array_map(fn($f) => '"' . ($f['key'] ?? 'field') . '": "value"', $contentTypeFields);
            $fieldsJson = ', "custom_fields": {' . implode(', ', $keys) . '}';
        }

        return <<<PROMPT
You are a professional web content generator for "{$siteName}".
Based on the entire conversation above, generate a complete webpage.

Content type: {$contentType}{$fieldsDesc}

You MUST respond with ONLY a valid JSON object (no markdown code fences, no explanatory text before or after) in this exact format:
{"title": "Page Title", "slug": "page-title", "excerpt": "A 1-2 sentence summary for SEO and listings.", "meta_title": "SEO title (50-60 chars)", "meta_description": "SEO description (150-160 chars)", "body": "<section>...full HTML content here...</section>"{$fieldsJson}}

HTML body rules:
- Use semantic HTML5 tags: section, h2, h3, p, ul, ol, figure, blockquote, strong, em
- Do NOT use h1 (the page title is rendered separately by the site template)
- Do NOT use inline styles, style attributes, or class attributes
- Do NOT wrap in html, head, or body tags — just the inner content sections
- Keep markup clean, minimal, and well-structured
- Write real, contextual, professional content — not Lorem ipsum
- Organize content into logical sections using <section> tags
- Ensure the content is ready to publish on a professional business website
PROMPT;
    }

    public static function formatExistingPages(array $pages): string
    {
        if (empty($pages)) {
            return 'This is a new site with no pages yet.';
        }

        $list = array_map(
            fn(array $p) => ($p['title'] ?? 'Untitled') . ' (/' . ltrim($p['slug'] ?? '', '/') . ')',
            $pages
        );

        return 'Existing pages on this site: ' . implode(', ', $list) . '.';
    }

    public static function formatCustomFields(array $fields): string
    {
        if (empty($fields)) {
            return '';
        }

        $lines = ['This content type has the following custom fields that need values:'];
        foreach ($fields as $field) {
            $key = $field['key'] ?? 'unknown';
            $type = $field['type'] ?? 'text';
            $label = $field['label'] ?? $key;
            $required = !empty($field['required']) ? ', required' : '';
            $options = '';
            if ($type === 'select' && !empty($field['options'])) {
                $optList = is_array($field['options']) ? $field['options'] : [];
                $options = ' (options: ' . implode(', ', $optList) . ')';
            }
            $lines[] = "- {$label} ({$type}{$required}){$options}";
        }

        return implode("\n", $lines);
    }
}
