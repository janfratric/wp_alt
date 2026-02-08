<?php declare(strict_types=1);

namespace App\PageBuilder;

use App\Database\QueryBuilder;

/**
 * Renders element-based pages by assembling element instances into HTML + CSS.
 */
class PageRenderer
{
    /**
     * Render all elements for a content item into combined HTML.
     */
    public static function renderPage(int $contentId): string
    {
        $instances = self::loadInstances($contentId);

        if (empty($instances)) {
            return '';
        }

        $html = '';
        foreach ($instances as $instance) {
            $html .= self::renderInstance($instance);
        }

        return $html;
    }

    /**
     * Collect CSS for all elements used on a page (deduplicated by element ID).
     */
    public static function getPageCss(int $contentId): string
    {
        $instances = self::loadInstances($contentId);

        $seen = [];
        $css = '';
        foreach ($instances as $instance) {
            $eid = (int) $instance['element_id'];
            if (isset($seen[$eid])) {
                continue;
            }
            $seen[$eid] = true;

            $elementCss = trim($instance['css'] ?? '');
            if ($elementCss !== '') {
                $css .= "/* Element: {$instance['name']} */\n{$elementCss}\n\n";
            }
        }

        return $css;
    }

    /**
     * Render a single element instance.
     */
    public static function renderInstance(array $instance): string
    {
        $slug = $instance['slug'] ?? 'unknown';
        $elementId = (int) ($instance['element_id'] ?? $instance['id'] ?? 0);
        $template = $instance['html_template'] ?? '';
        $slotData = $instance['slot_data'] ?? [];

        if (is_string($slotData)) {
            $slotData = json_decode($slotData, true) ?: [];
        }

        $rendered = SlotRenderer::render($template, $slotData);

        return '<div class="lcms-el lcms-el-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')
            . '" data-element-id="' . $elementId . '">'
            . "\n" . $rendered . "\n"
            . "</div>\n";
    }

    /**
     * Load all element instances for a content item, ordered by sort_order.
     * Joins with elements table to get template, CSS, and slug.
     */
    private static function loadInstances(int $contentId): array
    {
        return QueryBuilder::query('page_elements')
            ->select(
                'page_elements.id',
                'page_elements.element_id',
                'page_elements.sort_order',
                'page_elements.slot_data_json',
                'elements.slug',
                'elements.name',
                'elements.html_template',
                'elements.css'
            )
            ->leftJoin('elements', 'elements.id', '=', 'page_elements.element_id')
            ->where('page_elements.content_id', (string) $contentId)
            ->orderBy('page_elements.sort_order')
            ->get();
    }
}
