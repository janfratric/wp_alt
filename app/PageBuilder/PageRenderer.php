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
     * Also includes per-instance custom CSS scoped to each instance.
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

        // Per-instance style overrides via CSS rules (cascades into child elements)
        foreach ($instances as $instance) {
            $styleData = $instance['style_data_json'] ?? '{}';
            if (is_string($styleData)) {
                $styleData = json_decode($styleData, true) ?: [];
            }

            $instanceId = (int) ($instance['id'] ?? 0);
            $scope = '.lcms-el[data-instance-id="' . $instanceId . '"]';

            // Non-inheriting properties (background, padding, border, border-radius)
            // are emitted as CSS rules so they cascade into child elements whose
            // own catalogue CSS would otherwise cover the wrapper's inline styles.
            $cascadeCss = StyleRenderer::buildCascadeStyles($styleData, $scope);
            if ($cascadeCss !== '') {
                $css .= $cascadeCss;
            }

            // Custom CSS scoped to this instance
            $customCss = trim($styleData['custom_css'] ?? '');
            if ($customCss !== '') {
                $css .= "/* Custom CSS: instance #{$instanceId} */\n"
                     . StyleRenderer::scopeCustomCss($customCss, $scope) . "\n\n";
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
        $slotData = $instance['slot_data_json'] ?? $instance['slot_data'] ?? [];

        if (is_string($slotData)) {
            $slotData = json_decode($slotData, true) ?: [];
        }

        // Read and apply style data
        $instanceId = (int) ($instance['id'] ?? 0);
        $styleData = $instance['style_data_json'] ?? '{}';
        if (is_string($styleData)) {
            $styleData = json_decode($styleData, true) ?: [];
        }

        $inlineStyle = StyleRenderer::buildInlineStyle($styleData);
        $extraClasses = StyleRenderer::getCustomClasses($styleData);

        $rendered = SlotRenderer::render($template, $slotData);

        return '<div class="lcms-el lcms-el-' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8')
            . ($extraClasses !== '' ? ' ' . htmlspecialchars($extraClasses, ENT_QUOTES, 'UTF-8') : '')
            . '" data-element-id="' . $elementId . '"'
            . ' data-instance-id="' . $instanceId . '"'
            . ($inlineStyle !== '' ? ' style="' . htmlspecialchars($inlineStyle, ENT_QUOTES, 'UTF-8') . '"' : '')
            . '>'
            . "\n" . $rendered . "\n"
            . "</div>\n";
    }

    /**
     * Build page-level layout CSS from page_styles table.
     */
    public static function getPageLayoutCss(int $contentId): string
    {
        $row = QueryBuilder::query('page_styles')
            ->select('style_data_json')
            ->where('content_id', (string) $contentId)
            ->first();

        if ($row === null) {
            return '';
        }

        $data = json_decode($row['style_data_json'] ?? '{}', true) ?: [];
        if (empty($data)) {
            return '';
        }

        $css = '';

        // GUI styles
        $guiCss = StyleRenderer::buildPageLayoutCss($data);
        if ($guiCss !== '') {
            $css .= "/* Page Layout Styles */\n" . $guiCss . "\n";
        }

        // Custom CSS per target
        $targets = [
            'page_body'  => '.page-body',
            'container'  => '.container',
            'site_main'  => '.site-main',
        ];
        foreach ($targets as $key => $selector) {
            $targetData = $data[$key] ?? [];
            if (!is_array($targetData)) {
                continue;
            }
            $customCss = trim($targetData['custom_css'] ?? '');
            if ($customCss !== '') {
                $css .= "/* Page Layout Custom CSS: {$key} */\n"
                     . StyleRenderer::scopeCustomCss($customCss, $selector) . "\n\n";
            }
        }

        return $css;
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
                'page_elements.style_data_json',
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
