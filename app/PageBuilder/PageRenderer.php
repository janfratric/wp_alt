<?php declare(strict_types=1);

namespace App\PageBuilder;

use App\Database\QueryBuilder;
use App\PageBuilder\PenConverter;

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

        // Enrich dynamic elements with database data
        if (DynamicElements::isDynamic($slug)) {
            $slotData = DynamicElements::enrich($slug, $slotData);
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
     * Render page content with block support.
     * Elements assigned to blocks are grouped inside block wrappers;
     * unassigned elements (block_id=NULL) render flat for backward compat.
     */
    public static function renderPageWithBlocks(int $contentId, ?int $layoutTemplateId = null): string
    {
        $blocks = self::loadBlocks($contentId, $layoutTemplateId);
        $instances = self::loadInstancesWithBlocks($contentId);

        if (empty($instances) && empty($blocks)) {
            return '';
        }

        // Separate unassigned (flat) instances from block-assigned ones
        $flatInstances = [];
        $blockInstances = []; // blockId => [instances]
        foreach ($instances as $inst) {
            $bid = $inst['block_id'] ?? null;
            if ($bid === null || $bid === '' || $bid === '0') {
                $flatInstances[] = $inst;
            } else {
                $blockInstances[(int) $bid][] = $inst;
            }
        }

        $html = '';

        // Render flat instances first (backward compat)
        foreach ($flatInstances as $inst) {
            $html .= self::renderInstance($inst);
        }

        // Render blocks
        foreach ($blocks as $block) {
            $html .= self::renderBlock($block, $blockInstances[(int) $block['id']] ?? []);
        }

        return $html;
    }

    /**
     * Render a single block wrapper with its child elements.
     */
    public static function renderBlock(array $block, array $instances): string
    {
        $blockId = (int) $block['id'];
        $columns = max(1, min(12, (int) ($block['columns'] ?? 1)));
        $widthPercent = max(10, min(100, (int) ($block['width_percent'] ?? 100)));
        $alignment = in_array($block['alignment'] ?? 'center', ['left', 'center', 'right'], true)
            ? $block['alignment'] : 'center';
        $displayMode = in_array($block['display_mode'] ?? 'flex', ['flex', 'block', 'grid'], true)
            ? $block['display_mode'] : 'flex';

        // Build inline styles
        $styles = [];
        if ($widthPercent < 100) {
            $styles[] = 'width:' . $widthPercent . '%';
            if ($alignment === 'center') {
                $styles[] = 'margin-left:auto;margin-right:auto';
            } elseif ($alignment === 'right') {
                $styles[] = 'margin-left:auto';
            }
        }

        if ($displayMode === 'grid') {
            $styles[] = 'display:grid';
            $styles[] = 'grid-template-columns:repeat(' . $columns . ',1fr)';
            $styles[] = 'gap:1rem';
        } elseif ($displayMode === 'flex') {
            $styles[] = 'display:flex';
            $styles[] = 'flex-wrap:wrap';
            $styles[] = 'gap:1rem';
        }
        // 'block' display mode needs no special styles

        $styleAttr = !empty($styles) ? ' style="' . htmlspecialchars(implode(';', $styles), ENT_QUOTES, 'UTF-8') . '"' : '';

        $html = '<div class="lcms-block" data-block-id="' . $blockId . '"' . $styleAttr . '>' . "\n";

        foreach ($instances as $inst) {
            $html .= self::renderInstance($inst);
        }

        $html .= "</div>\n";

        return $html;
    }

    /**
     * Render a single element from the catalogue (by element ID).
     * Used for header/footer block-mode rendering with empty slot data.
     */
    public static function renderSingleElement(int $elementId): string
    {
        $element = QueryBuilder::query('elements')
            ->select('id', 'slug', 'name', 'html_template', 'css', 'slots_json')
            ->where('id', (string) $elementId)
            ->where('status', 'active')
            ->first();

        if ($element === null) {
            return '';
        }

        // Render with default slot values
        $slots = json_decode($element['slots_json'] ?? '[]', true) ?: [];
        $slotData = [];
        foreach ($slots as $slot) {
            if (isset($slot['key'], $slot['default'])) {
                $slotData[$slot['key']] = $slot['default'];
            }
        }

        $instance = [
            'id'             => 0,
            'element_id'     => (int) $element['id'],
            'slug'           => $element['slug'],
            'name'           => $element['name'],
            'html_template'  => $element['html_template'],
            'css'            => $element['css'],
            'slot_data_json' => $slotData,
            'style_data_json' => '{}',
        ];

        return self::renderInstance($instance);
    }

    /**
     * Get CSS for a single element from the catalogue (for header/footer block mode).
     */
    public static function getSingleElementCss(int $elementId): string
    {
        $element = QueryBuilder::query('elements')
            ->select('name', 'css')
            ->where('id', (string) $elementId)
            ->where('status', 'active')
            ->first();

        if ($element === null) {
            return '';
        }

        $css = trim($element['css'] ?? '');
        if ($css !== '') {
            return "/* Element: {$element['name']} */\n{$css}\n\n";
        }

        return '';
    }

    /**
     * Load page blocks ordered by sort_order.
     * If $layoutTemplateId is provided, loads template-level blocks.
     * Otherwise falls back to legacy content-level blocks.
     */
    public static function loadBlocks(int $contentId, ?int $layoutTemplateId = null): array
    {
        if ($layoutTemplateId !== null && $layoutTemplateId > 0) {
            return QueryBuilder::query('page_blocks')
                ->select()
                ->where('layout_template_id', (string) $layoutTemplateId)
                ->orderBy('sort_order')
                ->get();
        }

        return QueryBuilder::query('page_blocks')
            ->select()
            ->where('content_id', (string) $contentId)
            ->orderBy('sort_order')
            ->get();
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

    /**
     * Load instances including block_id for block-aware rendering.
     */
    private static function loadInstancesWithBlocks(int $contentId): array
    {
        return QueryBuilder::query('page_elements')
            ->select(
                'page_elements.id',
                'page_elements.element_id',
                'page_elements.sort_order',
                'page_elements.slot_data_json',
                'page_elements.style_data_json',
                'page_elements.block_id',
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

    /**
     * Render a .pen design file to HTML + CSS.
     *
     * @param string $penFilePath Absolute path to the .pen file.
     * @return array{html: string, css: string}
     */
    public static function renderFromPen(string $penFilePath, array $variableOverrides = []): array
    {
        return PenConverter::convertFile($penFilePath, $variableOverrides);
    }
}
