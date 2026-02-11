<?php declare(strict_types=1);

namespace App\PageBuilder;

/**
 * Renders individual .pen node types into HTML + CSS pairs.
 */
class PenNodeRenderer
{
    /** Semantic HTML tag mapping based on frame name keywords */
    private const SEMANTIC_TAG_MAP = [
        'header'  => 'header',
        'footer'  => 'footer',
        'nav'     => 'nav',
        'sidebar' => 'aside',
        'section' => 'section',
        'article' => 'article',
        'main'    => 'main',
    ];

    /** Heading inference thresholds */
    private const HEADING_THRESHOLDS = [
        ['min' => 32, 'tag' => 'h1', 'requireBold' => false],
        ['min' => 24, 'tag' => 'h2', 'requireBold' => false],
        ['min' => 20, 'tag' => 'h3', 'requireBold' => false],
        ['min' => 18, 'tag' => 'h4', 'requireBold' => false],
        ['min' => 16, 'tag' => 'h5', 'requireBold' => true],
    ];

    /**
     * Main dispatcher — routes to the correct render method based on node type.
     */
    public static function renderNode(array $node, PenConverter $converter): array
    {
        // Skip disabled nodes
        if (isset($node['enabled']) && $node['enabled'] === false) {
            return ['html' => '', 'css' => ''];
        }

        $type = $node['type'] ?? '';
        return match ($type) {
            'frame'     => self::renderFrame($node, $converter),
            'text'      => self::renderText($node, $converter),
            'rectangle' => self::renderRectangle($node, $converter),
            'ellipse'   => self::renderEllipse($node, $converter),
            'line'      => self::renderLine($node, $converter),
            'polygon'   => self::renderPolygon($node, $converter),
            'path'      => self::renderPath($node, $converter),
            'ref'       => self::renderRef($node, $converter),
            'group'     => self::renderGroup($node, $converter),
            'icon_font' => self::renderIconFont($node, $converter),
            // Skip non-renderable types
            'note', 'prompt', 'context' => ['html' => '', 'css' => ''],
            default     => ['html' => '', 'css' => ''],
        };
    }

    /**
     * Render a frame node — semantic tag inferred from name.
     */
    public static function renderFrame(array $node, PenConverter $converter): array
    {
        // Skip reusable component definitions (they're templates)
        if (!empty($node['reusable'])) {
            return ['html' => '', 'css' => ''];
        }

        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $tag = self::inferFrameTag($node['name'] ?? '');

        // Build CSS
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box;';
        $css .= PenStyleBuilder::buildLayout($node);
        $css .= PenStyleBuilder::buildFill($node['fill'] ?? null);
        $css .= PenStyleBuilder::buildStroke($node['stroke'] ?? null);
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= PenStyleBuilder::buildAllStyles($node, $isAbsolute, $parentLayout);
        $css .= "}\n";

        // Render children
        $childrenResult = ['html' => '', 'css' => ''];
        if (!empty($node['children'])) {
            $prevLayout = $converter->getParentLayout();
            $converter->setParentLayout($node['layout'] ?? 'horizontal');
            $childrenResult = $converter->renderChildren($node['children']);
            $converter->setParentLayout($prevLayout);
        }

        $html = "<{$tag} class=\"{$cls}\">";
        $html .= $childrenResult['html'];
        $html .= "</{$tag}>";

        return [
            'html' => $html,
            'css' => $css . $childrenResult['css'],
        ];
    }

    /**
     * Render a text node — semantic tag inferred from font properties.
     */
    public static function renderText(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $tag = self::inferTextTag($node);
        $content = self::renderTextContent($node['content'] ?? '');

        // Build CSS
        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box; margin: 0;';
        $css .= PenStyleBuilder::buildTypography($node);
        $css .= PenStyleBuilder::buildTextColor($node['fill'] ?? null);
        $css .= PenStyleBuilder::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= PenStyleBuilder::buildOpacity($node['opacity'] ?? null);
        $css .= PenStyleBuilder::buildRotation($node['rotation'] ?? null);
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);

        // textGrowth handling
        $tg = $node['textGrowth'] ?? 'auto';
        if ($tg === 'fixed-width-height') {
            $css .= 'overflow: hidden;';
        }

        $css .= "}\n";

        // Handle href
        $href = $node['href'] ?? null;
        if ($href) {
            $hrefEsc = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
            $html = "<a href=\"{$hrefEsc}\" class=\"{$cls}\">{$content}</a>";
        } else {
            $html = "<{$tag} class=\"{$cls}\">{$content}</{$tag}>";
        }

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Render a rectangle node as a styled div.
     */
    public static function renderRectangle(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box;';
        $css .= PenStyleBuilder::buildFill($node['fill'] ?? null);
        $css .= PenStyleBuilder::buildStroke($node['stroke'] ?? null);
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= PenStyleBuilder::buildAllStyles($node, $isAbsolute, $parentLayout);
        $css .= "}\n";

        return [
            'html' => "<div class=\"{$cls}\"></div>",
            'css' => $css,
        ];
    }

    /**
     * Render an ellipse node.
     */
    public static function renderEllipse(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box; border-radius: 50%;';
        $css .= PenStyleBuilder::buildFill($node['fill'] ?? null);
        $css .= PenStyleBuilder::buildStroke($node['stroke'] ?? null);
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= PenStyleBuilder::buildAllStyles($node, $isAbsolute, $parentLayout);
        $css .= "}\n";

        return [
            'html' => "<div class=\"{$cls}\"></div>",
            'css' => $css,
        ];
    }

    /**
     * Render a line node.
     */
    public static function renderLine(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $stroke = $node['stroke'] ?? [];
        $thickness = 1;
        if (isset($stroke['thickness'])) {
            $t = $stroke['thickness'];
            $thickness = is_numeric($t) ? (int)$t : 1;
        }
        $color = '#000000';
        if (isset($stroke['fill'])) {
            $color = PenStyleBuilder::resolveColor(
                is_string($stroke['fill']) ? $stroke['fill'] :
                ($stroke['fill']['color'] ?? '#000000')
            );
        }

        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box; border: none;';
        $css .= "border-top: {$thickness}px solid {$color};";
        $css .= PenStyleBuilder::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= "}\n";

        return [
            'html' => "<hr class=\"{$cls}\">",
            'css' => $css,
        ];
    }

    /**
     * Render a polygon node as SVG.
     */
    public static function renderPolygon(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $w = (float)($node['width'] ?? 100);
        $h = (float)($node['height'] ?? 100);
        $sides = (int)($node['polygonCount'] ?? 6);

        $points = self::generatePolygonPoints($sides, $w, $h);
        $fillColor = self::extractSvgFill($node['fill'] ?? null);
        $strokeAttr = self::extractSvgStroke($node['stroke'] ?? null);

        $html = "<svg class=\"{$cls}\" viewBox=\"0 0 {$w} {$h}\" " .
                "width=\"{$w}\" height=\"{$h}\">" .
                "<polygon points=\"{$points}\" fill=\"{$fillColor}\" {$strokeAttr}/>" .
                '</svg>';

        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';
        $css = ".{$cls} {";
        $css .= PenStyleBuilder::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= "}\n";

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Render an SVG path node.
     */
    public static function renderPath(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $w = (float)($node['width'] ?? 100);
        $h = (float)($node['height'] ?? 100);
        $geometry = $node['geometry'] ?? '';
        $fillRule = $node['fillRule'] ?? 'nonzero';

        $fillColor = self::extractSvgFill($node['fill'] ?? null);
        $strokeAttr = self::extractSvgStroke($node['stroke'] ?? null);

        $html = "<svg class=\"{$cls}\" viewBox=\"0 0 {$w} {$h}\" " .
                "width=\"{$w}\" height=\"{$h}\">" .
                "<path d=\"{$geometry}\" fill=\"{$fillColor}\" " .
                "fill-rule=\"{$fillRule}\" {$strokeAttr}/>" .
                '</svg>';

        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';
        $css = ".{$cls} {";
        $css .= PenStyleBuilder::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= "}\n";

        return ['html' => $html, 'css' => $css];
    }

    /**
     * Render a ref (component instance) by resolving through the converter.
     */
    public static function renderRef(array $node, PenConverter $converter): array
    {
        $refId = $node['ref'] ?? '';
        if ($refId === '') {
            return ['html' => '<!-- Missing ref -->', 'css' => ''];
        }

        if (!$converter->incrementRefDepth()) {
            return [
                'html' => "<!-- Max ref depth exceeded for: {$refId} -->",
                'css' => '',
            ];
        }

        $component = $converter->getComponent($refId);
        if ($component === null) {
            $converter->decrementRefDepth();
            return [
                'html' => "<!-- Component not found: {$refId} -->",
                'css' => '',
            ];
        }

        // Deep clone the component
        $resolved = json_decode(json_encode($component), true);

        // Apply root-level overrides from the ref node
        $skipKeys = ['type', 'ref', 'descendants', 'id', 'reusable'];
        foreach ($node as $key => $value) {
            if (!in_array($key, $skipKeys, true)) {
                $resolved[$key] = $value;
            }
        }

        // Assign the instance's own ID (so CSS classes are unique)
        $resolved['id'] = $node['id'] ?? $refId . '-inst';
        // Clear the reusable flag so it gets rendered
        $resolved['reusable'] = false;

        // Apply descendant overrides
        $descendants = $node['descendants'] ?? [];
        if (!empty($descendants)) {
            self::applyDescendants($resolved, $descendants);
        }

        // Render the resolved node
        $result = $converter->renderNode($resolved);
        $converter->decrementRefDepth();

        return $result;
    }

    /**
     * Render a group node.
     */
    public static function renderGroup(array $node, PenConverter $converter): array
    {
        if (!empty($node['reusable'])) {
            return ['html' => '', 'css' => ''];
        }

        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';

        $css = ".{$cls} {";
        $css .= 'box-sizing: border-box;';
        if (isset($node['layout'])) {
            $css .= PenStyleBuilder::buildLayout($node);
        }
        $css .= PenStyleBuilder::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= PenStyleBuilder::buildEffects($node['effect'] ?? null);
        $css .= PenStyleBuilder::buildOpacity($node['opacity'] ?? null);
        $css .= "}\n";

        // Render children
        $childrenResult = ['html' => '', 'css' => ''];
        if (!empty($node['children'])) {
            $prevLayout = $converter->getParentLayout();
            $converter->setParentLayout($node['layout'] ?? 'none');
            $childrenResult = $converter->renderChildren($node['children']);
            $converter->setParentLayout($prevLayout);
        }

        $html = "<div class=\"{$cls}\">{$childrenResult['html']}</div>";

        return [
            'html' => $html,
            'css' => $css . $childrenResult['css'],
        ];
    }

    /**
     * Render an icon_font node.
     */
    public static function renderIconFont(array $node, PenConverter $converter): array
    {
        $id = $node['id'] ?? 'unknown';
        $cls = 'pen-' . $id;
        $family = PenStyleBuilder::resolveValue($node['iconFontFamily'] ?? 'lucide');
        $name = PenStyleBuilder::resolveValue($node['iconFontName'] ?? '');
        $w = $node['width'] ?? 24;
        $h = $node['height'] ?? 24;
        $size = max((float)$w, (float)$h);

        // Register icon font import
        $cdnMap = [
            'lucide' => 'https://cdn.jsdelivr.net/npm/lucide-static@latest/font/lucide.min.css',
            'feather' => 'https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.css',
            'Material Symbols Outlined' =>
                'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined',
            'Material Symbols Rounded' =>
                'https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded',
            'Material Symbols Sharp' =>
                'https://fonts.googleapis.com/css2?family=Material+Symbols+Sharp',
            'phosphor' =>
                'https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2/src/regular/style.css',
        ];
        if (isset($cdnMap[$family])) {
            $converter->addIconFontImport($family, $cdnMap[$family]);
        }

        // Build CSS
        $parentLayout = $converter->getParentLayout();
        $isAbsolute = $parentLayout === 'none';
        $fillCss = PenStyleBuilder::buildTextColor($node['fill'] ?? null);

        $css = ".{$cls} {";
        $css .= "font-size: {$size}px;";
        $css .= "width: {$w}px; height: {$h}px;";
        $css .= 'display: inline-flex; align-items: center; justify-content: center;';
        $css .= $fillCss;
        if ($isAbsolute) {
            $css .= PenStyleBuilder::buildPosition($node);
        }
        $css .= "}\n";

        // Determine icon HTML based on font family
        if (str_starts_with($family, 'Material Symbols')) {
            $html = "<span class=\"{$cls} {$family}\">{$name}</span>";
        } elseif ($family === 'phosphor') {
            $html = "<i class=\"{$cls} ph ph-{$name}\"></i>";
        } elseif ($family === 'feather') {
            $html = "<i class=\"{$cls} feather icon-{$name}\"></i>";
        } else {
            // Default: lucide
            $html = "<i class=\"{$cls} icon-{$name}\"></i>";
        }

        return ['html' => $html, 'css' => $css];
    }

    // --- Private helpers ---

    private static function inferFrameTag(string $name): string
    {
        $lower = strtolower($name);
        foreach (self::SEMANTIC_TAG_MAP as $keyword => $tag) {
            if (str_contains($lower, $keyword)) {
                return $tag;
            }
        }
        return 'div';
    }

    private static function inferTextTag(array $node): string
    {
        if (isset($node['href'])) {
            return 'a';
        }
        $fontSize = $node['fontSize'] ?? null;
        if ($fontSize === null || !is_numeric($fontSize)) {
            return 'p';
        }
        $fontWeight = $node['fontWeight'] ?? '400';
        $weightNum = is_numeric($fontWeight) ? (int)$fontWeight : 400;

        foreach (self::HEADING_THRESHOLDS as $t) {
            if ((float)$fontSize >= $t['min']) {
                if (!empty($t['requireBold']) && $weightNum < 600) {
                    continue;
                }
                return $t['tag'];
            }
        }
        return 'p';
    }

    private static function renderTextContent(mixed $content): string
    {
        if ($content === null || $content === '') {
            return '';
        }
        if (is_string($content)) {
            // Variable reference → output as placeholder
            if (str_starts_with($content, '$')) {
                return htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
            }
            return nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
        }
        // Array of styled text runs
        if (is_array($content)) {
            $html = '';
            foreach ($content as $run) {
                if (is_string($run)) {
                    $html .= htmlspecialchars($run, ENT_QUOTES, 'UTF-8');
                    continue;
                }
                if (!is_array($run)) {
                    continue;
                }
                $text = htmlspecialchars((string)($run['content'] ?? ''), ENT_QUOTES, 'UTF-8');
                $style = PenStyleBuilder::buildTypography($run);
                $colorCss = PenStyleBuilder::buildTextColor($run['fill'] ?? null);
                $allStyle = trim($style . $colorCss);

                if (isset($run['href'])) {
                    $href = htmlspecialchars($run['href'], ENT_QUOTES, 'UTF-8');
                    $html .= "<a href=\"{$href}\"" .
                             ($allStyle ? " style=\"{$allStyle}\"" : '') .
                             ">{$text}</a>";
                } else {
                    $html .= $allStyle
                        ? "<span style=\"{$allStyle}\">{$text}</span>"
                        : $text;
                }
            }
            return $html;
        }
        return '';
    }

    /**
     * Apply descendant overrides to a resolved component tree.
     */
    private static function applyDescendants(array &$node, array $descendants): void
    {
        foreach ($descendants as $path => $override) {
            $target = &self::findDescendant($node, explode('/', $path));
            if ($target === null) {
                continue;
            }
            // Full replacement if override has 'type'
            if (isset($override['type'])) {
                $target = $override;
            } else {
                // Property merge
                foreach ($override as $k => $v) {
                    $target[$k] = $v;
                }
            }
        }
    }

    /**
     * Find a descendant in the node tree by ID path parts.
     */
    private static function &findDescendant(array &$node, array $parts): ?array
    {
        $null = null;
        if (empty($parts)) {
            return $node;
        }

        $targetId = array_shift($parts);
        $children = &$node['children'] ?? [];

        if (!is_array($children)) {
            return $null;
        }

        for ($i = 0; $i < count($children); $i++) {
            if (($children[$i]['id'] ?? '') === $targetId) {
                if (empty($parts)) {
                    return $children[$i];
                }
                return self::findDescendant($children[$i], $parts);
            }
        }

        return $null;
    }

    /**
     * Extract fill color for SVG fill attribute.
     */
    private static function extractSvgFill(mixed $fill): string
    {
        if ($fill === null) {
            return 'none';
        }
        if (is_string($fill)) {
            return PenStyleBuilder::resolveColor($fill);
        }
        if (is_array($fill) && isset($fill['type']) && $fill['type'] === 'color') {
            return PenStyleBuilder::resolveColor($fill['color'] ?? 'none');
        }
        if (is_array($fill) && isset($fill[0])) {
            return self::extractSvgFill($fill[0]);
        }
        return 'none';
    }

    /**
     * Extract stroke attributes for SVG.
     */
    private static function extractSvgStroke(mixed $stroke): string
    {
        if (!is_array($stroke) || empty($stroke)) {
            return 'stroke="none"';
        }
        $color = 'none';
        $width = 0;
        if (isset($stroke['fill'])) {
            $color = PenStyleBuilder::resolveColor(
                is_string($stroke['fill']) ? $stroke['fill'] :
                ($stroke['fill']['color'] ?? 'none')
            );
        }
        if (isset($stroke['thickness'])) {
            $width = is_numeric($stroke['thickness'])
                ? (float)$stroke['thickness'] : 0;
        }
        return "stroke=\"{$color}\" stroke-width=\"{$width}\"";
    }

    /**
     * Generate SVG polygon points for a regular polygon.
     */
    private static function generatePolygonPoints(int $sides, float $w, float $h): string
    {
        $cx = $w / 2;
        $cy = $h / 2;
        $rx = $w / 2;
        $ry = $h / 2;
        $points = [];
        for ($i = 0; $i < $sides; $i++) {
            $angle = (2 * M_PI * $i / $sides) - (M_PI / 2); // start from top
            $x = round($cx + $rx * cos($angle), 2);
            $y = round($cy + $ry * sin($angle), 2);
            $points[] = "{$x},{$y}";
        }
        return implode(' ', $points);
    }
}
