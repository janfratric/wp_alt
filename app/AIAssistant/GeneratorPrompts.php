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

    public static function generationPrompt(string $siteName, string $contentType, ?array $contentTypeFields, array $imageUrls = []): string
    {
        $fieldsDesc = '';
        $fieldsJson = '';
        if ($contentTypeFields !== null) {
            $fieldsDesc = "\n\n" . self::formatCustomFields($contentTypeFields);
            $keys = array_map(fn($f) => '"' . ($f['key'] ?? 'field') . '": "value"', $contentTypeFields);
            $fieldsJson = ', "custom_fields": {' . implode(', ', $keys) . '}';
        }

        $imageSection = '';
        if (!empty($imageUrls)) {
            $imageList = implode("\n", array_map(fn($url, $i) => "Image " . ($i + 1) . ": {$url}", $imageUrls, array_keys($imageUrls)));
            $imageSection = <<<IMG


AVAILABLE IMAGES — The user uploaded these images during the conversation. Use these EXACT URLs in <img src="..."> tags:
{$imageList}
Do NOT invent, guess, or modify image URLs. ONLY use the exact URLs listed above.
IMG;
        }

        return <<<PROMPT
You are a professional web content generator for "{$siteName}".
Based on the entire conversation above, generate a complete webpage.

Content type: {$contentType}{$fieldsDesc}{$imageSection}

You MUST respond with ONLY a valid JSON object (no markdown code fences, no explanatory text before or after) in this exact format:
{"title": "Page Title", "slug": "page-title", "excerpt": "A 1-2 sentence summary for SEO and listings.", "meta_title": "SEO title (50-60 chars)", "meta_description": "SEO description (150-160 chars)", "body": "<section>...full HTML content here...</section>"{$fieldsJson}}

HTML body rules:
- Use semantic HTML5 tags: section, h2, h3, p, ul, ol, figure, figcaption, blockquote, strong, em, img
- Do NOT use h1 (the page title is rendered separately by the site template)
- Do NOT use inline styles, style attributes, or class attributes
- Do NOT wrap in html, head, or body tags — just the inner content sections
- Keep markup clean, minimal, and well-structured
- Write real, contextual, professional content — not Lorem ipsum
- Organize content into logical sections using <section> tags
- Ensure the content is ready to publish on a professional business website
- When including images, you MUST use the exact URLs from the AVAILABLE IMAGES list above. Wrap each image in <figure><img src="EXACT_URL" alt="descriptive alt text"><figcaption>caption</figcaption></figure>. Do NOT invent or guess image URLs — only use URLs that were explicitly provided.
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

    /**
     * Format the element catalogue for AI context.
     */
    public static function formatElementCatalogue(array $elements): string
    {
        if (empty($elements)) {
            return 'No elements in the catalogue yet.';
        }

        $lines = [];
        foreach ($elements as $el) {
            $name = $el['name'] ?? 'Untitled';
            $slug = $el['slug'] ?? 'unknown';
            $desc = $el['description'] ?? '';
            $slotsJson = $el['slots_json'] ?? '[]';
            $slots = is_string($slotsJson) ? (json_decode($slotsJson, true) ?: []) : (is_array($slotsJson) ? $slotsJson : []);

            $slotDescs = [];
            foreach ($slots as $slot) {
                $key = $slot['key'] ?? 'unknown';
                $type = $slot['type'] ?? 'text';
                $slotDescs[] = "{$key} ({$type})";
            }
            $slotStr = !empty($slotDescs) ? 'Slots: ' . implode(', ', $slotDescs) : 'No slots';

            $line = "- {$name} (slug: {$slug})";
            if ($desc !== '') {
                $line .= ": {$desc}";
            }
            $line .= ". {$slotStr}";
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Format the design system components for AI context.
     * Produces a concise summary of available components and their slot nodes.
     */
    public static function formatDesignSystemComponents(array $designSystemDoc): string
    {
        $children = $designSystemDoc['children'] ?? [];
        $lines = [];

        foreach ($children as $component) {
            if (empty($component['reusable'])) {
                continue;
            }

            $id = $component['id'] ?? 'unknown';
            $name = $component['name'] ?? $id;
            $slots = self::collectSlotNodes($component);

            $line = "Component: {$id} ({$name})";
            if (!empty($slots)) {
                $slotDescs = [];
                foreach ($slots as $slot) {
                    $slotDescs[] = $slot['id'] . ' (' . $slot['type'] . ', ' . $slot['hint'] . ')';
                }
                $line .= "\n  Slots: " . implode(', ', $slotDescs);
            }
            $lines[] = $line;
        }

        return implode("\n\n", $lines);
    }

    /**
     * Recursively collect slot nodes (overridable children) from a component.
     */
    private static function collectSlotNodes(array $node, int $depth = 0, string $pathPrefix = ''): array
    {
        $slots = [];
        $children = $node['children'] ?? [];

        foreach ($children as $child) {
            $childId = $child['id'] ?? '';
            $childType = $child['type'] ?? 'frame';

            if ($childId === '') {
                if (!empty($child['children'])) {
                    $slots = array_merge($slots, self::collectSlotNodes($child, $depth + 1, $pathPrefix));
                }
                continue;
            }

            $fullPath = $pathPrefix !== '' ? $pathPrefix . '/' . $childId : $childId;

            if ($childType === 'text') {
                $fontSize = $child['fontSize'] ?? 16;
                $hint = $fontSize >= 32 ? 'heading' :
                       ($fontSize >= 20 ? 'subheading' :
                       ($fontSize >= 16 ? 'body text' : 'small text'));
                $slots[] = ['id' => $fullPath, 'type' => 'text', 'hint' => $hint];
            } elseif ($childType === 'frame' && !empty($child['children'])) {
                $slots = array_merge($slots, self::collectSlotNodes($child, $depth + 1, $fullPath));
            }
        }

        return $slots;
    }

    /**
     * Design mode gathering prompt for the page generator.
     */
    public static function penDesignGatheringPrompt(
        string $siteName,
        array $existingPages,
        ?array $typeFields,
        string $componentSummary
    ): string {
        $pagesList = self::formatExistingPages($existingPages);
        $fieldsDesc = $typeFields !== null ? "\n\n" . self::formatCustomFields($typeFields) : '';

        return <<<PROMPT
You are a professional web content planning assistant for "{$siteName}".
You are building a visually designed page using the site's design system components.
Your job is to help the user plan a new webpage by asking smart, focused questions.

{$pagesList}{$fieldsDesc}

Available design system components:
{$componentSummary}

Guidelines:
- Ask 2-3 focused questions per message. Do not overwhelm the user with too many questions at once.
- Start by asking about the page's purpose and target audience.
- Then progressively ask about: desired sections/structure, key content points, tone/style preferences, and any specific details they want included.
- Reference available design system components by name when suggesting page structure (e.g., "We could use the Hero Section for a bold opening, followed by a Feature Grid to highlight your services").
- If the user is vague, suggest concrete layouts using the available components.
- Consider how this new page fits with the existing site structure.
- Keep your responses conversational and helpful. Be encouraging.
- When you have gathered enough information to generate a complete, high-quality page, include the exact marker READY_TO_GENERATE on its own line at the END of your message. Before the marker, give the user a brief summary of what you'll generate (which components you'll use and in what order) so they can confirm or adjust.
- Do NOT generate the actual page content during this phase — only gather and confirm requirements.
PROMPT;
    }

    /**
     * Design mode generation prompt for the page generator.
     */
    public static function penDesignGenerationPrompt(
        string $siteName,
        string $contentType,
        ?array $typeFields,
        string $componentSummary,
        array $variables,
        array $imageUrls = []
    ): string {
        $fieldsDesc = '';
        $fieldsJson = '';
        if ($typeFields !== null) {
            $fieldsDesc = "\n\n" . self::formatCustomFields($typeFields);
            $keys = array_map(fn($f) => '"' . ($f['key'] ?? 'field') . '": "value"', $typeFields);
            $fieldsJson = ', "custom_fields": {' . implode(', ', $keys) . '}';
        }

        $imageSection = '';
        if (!empty($imageUrls)) {
            $imageList = implode("\n", array_map(
                fn($url, $i) => "Image " . ($i + 1) . ": {$url}",
                $imageUrls,
                array_keys($imageUrls)
            ));
            $imageSection = <<<IMG

AVAILABLE IMAGES — The user uploaded these images during the conversation.
Reference them in descendants overrides where appropriate:
{$imageList}
IMG;
        }

        return <<<PROMPT
You are a professional web content generator for "{$siteName}".
Based on the entire conversation above, generate a complete visually designed webpage using the design system components.

Content type: {$contentType}{$fieldsDesc}{$imageSection}

Available design system components:
{$componentSummary}

Rules:
- Compose the page using ref nodes that reference the design system components by their IDs.
- Customize each component instance's content using "descendants" overrides. The descendants keys are PATHS to the slot nodes using "/" as separator (e.g., "hero-cta/hero-cta-text" for a text node inside a frame).
- Each ref node MUST have a unique "id" (e.g., "hero-1", "text-about", "features-1").
- Write real, contextual, professional content — not Lorem ipsum or placeholder text.
- Choose components that best match the user's requirements from the conversation.
- Order components logically (typically: hero → content sections → CTA → footer).

You MUST respond with ONLY a valid JSON object (no markdown code fences, no explanatory text before or after) in this exact format:
{"title": "Page Title", "slug": "page-title", "excerpt": "A 1-2 sentence summary.", "meta_title": "SEO title (50-60 chars)", "meta_description": "SEO description (150-160 chars)"{$fieldsJson}, "pen_page": {"id": "page-root", "type": "frame", "name": "Page", "layout": "vertical", "width": 1200, "children": [{"id": "hero-1", "type": "ref", "ref": "hero-section", "descendants": {"hero-heading": {"content": "Your Custom Title"}, "hero-subheading": {"content": "Your custom subtitle"}, "hero-cta/hero-cta-text": {"content": "Get Started"}}}, {"id": "text-1", "type": "ref", "ref": "text-section", "descendants": {"text-content-wrapper/text-heading": {"content": "Section Title"}, "text-content-wrapper/text-body": {"content": "Full section content here..."}}}]}}

Important:
- The "ref" value must exactly match a component ID from the list above.
- The "descendants" keys must use the EXACT PATHS shown in the component slot listings above (e.g., "hero-cta/hero-cta-text", NOT just "hero-cta-text").
- For text slot overrides, always include a "content" property with the new text.
- Do NOT include the component definitions in your output — only the page frame with ref instances.
- Do NOT modify component structure, variables, or styling — only override text content via descendants.
PROMPT;
    }

    /**
     * Element-aware gathering prompt for the page generator.
     */
    public static function elementGatheringPrompt(string $siteName, array $existingPages, ?array $typeFields, string $catalogue): string
    {
        $pagesList = self::formatExistingPages($existingPages);
        $fieldsDesc = $typeFields !== null ? "\n\n" . self::formatCustomFields($typeFields) : '';

        return <<<PROMPT
You are a professional web content planning assistant for "{$siteName}".
You are building an element-based page using the site's element catalogue.
Your job is to help the user plan a new webpage by asking smart, focused questions.

{$pagesList}{$fieldsDesc}

Available elements in the catalogue:
{$catalogue}

Guidelines:
- Ask 2-3 focused questions per message. Do not overwhelm the user with too many questions at once.
- Start by asking about the page's purpose and target audience.
- Then progressively ask about: desired sections/structure, key content points, tone/style preferences, and any specific details they want included.
- During the gathering phase, understand which existing elements can be reused and what new elements might be needed.
- If the user is vague, suggest concrete options referencing available elements.
- Consider how this new page fits with the existing site structure.
- Keep your responses conversational and helpful. Be encouraging.
- When you have gathered enough information to generate a complete, high-quality page, include the exact marker READY_TO_GENERATE on its own line at the END of your message. Before the marker, give the user a brief summary of what you'll generate so they can confirm or adjust.
- Do NOT generate the actual page content during this phase — only gather and confirm requirements.
PROMPT;
    }

    /**
     * Element-aware generation prompt for the page generator.
     */
    public static function elementGenerationPrompt(string $siteName, string $contentType, ?array $typeFields, string $catalogue, array $imageUrls = []): string
    {
        $fieldsDesc = '';
        $fieldsJson = '';
        if ($typeFields !== null) {
            $fieldsDesc = "\n\n" . self::formatCustomFields($typeFields);
            $keys = array_map(fn($f) => '"' . ($f['key'] ?? 'field') . '": "value"', $typeFields);
            $fieldsJson = ', "custom_fields": {' . implode(', ', $keys) . '}';
        }

        return <<<PROMPT
You are a professional web content generator for "{$siteName}".
Based on the entire conversation above, generate a complete element-based webpage.

Content type: {$contentType}{$fieldsDesc}

Available elements in the catalogue:
{$catalogue}

Rules:
- Use existing elements from the catalogue whenever possible. Only propose __new__ elements when no existing element can serve the purpose.
- For __new__ elements, the html_template must use micro-mustache syntax ({{slot}}, {{{slot}}}, {{#list}}...{{/list}}, {{^key}}...{{/key}}) and the CSS must be scoped under .lcms-el-{slug}.

You MUST respond with ONLY a valid JSON object (no markdown code fences, no explanatory text) in this exact format:
{"editor_mode": "elements", "title": "Page Title", "slug": "page-title", "excerpt": "A 1-2 sentence summary.", "meta_title": "SEO title", "meta_description": "SEO description"{$fieldsJson}, "elements": [{"element_slug": "hero-section", "slot_data": {"title": "...", "description": "..."}}, {"element_slug": "__new__", "new_element": {"name": "...", "slug": "...", "description": "...", "category": "...", "html_template": "...", "css": "...", "slots_json": [{"key": "...", "type": "text", "label": "..."}]}, "slot_data": {"key": "value"}}]}

Write real, contextual, professional content — not Lorem ipsum.
PROMPT;
    }
}
