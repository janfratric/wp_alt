<?php declare(strict_types=1);

namespace App\PageBuilder;

/**
 * Converts .pen node properties to CSS declarations.
 * Pure stateless utility — all methods are static.
 */
class PenStyleBuilder
{
    /**
     * Resolve a .pen variable reference to CSS var() or return raw value.
     */
    public static function resolveValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value) && str_starts_with($value, '$')) {
            $varName = substr($value, 1);
            // Strip leading -- if present (some .pen files use $--name)
            if (str_starts_with($varName, '--')) {
                return 'var(' . $varName . ')';
            }
            return 'var(--' . $varName . ')';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        return (string) $value;
    }

    /**
     * Resolve a .pen color value (hex, variable, rgba).
     */
    public static function resolveColor(mixed $color): string
    {
        if ($color === null) {
            return '';
        }
        if (is_string($color) && str_starts_with($color, '$')) {
            return self::resolveValue($color);
        }
        if (is_string($color) && str_starts_with($color, '#')) {
            $hex = ltrim($color, '#');
            // 3-digit hex → expand
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                return '#' . $hex;
            }
            // 8-digit hex → rgba
            if (strlen($hex) === 8) {
                return self::hexToRgba($color);
            }
            return $color;
        }
        return (string) $color;
    }

    /**
     * Convert 8-digit hex (#RRGGBBAA) to rgba().
     */
    public static function hexToRgba(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 8) {
            return '#' . $hex;
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $a = round(hexdec(substr($hex, 6, 2)) / 255, 3);
        return "rgba({$r}, {$g}, {$b}, {$a})";
    }

    /**
     * Convert .pen Fill to CSS background declarations.
     */
    public static function buildFill(mixed $fill): string
    {
        if ($fill === null) {
            return '';
        }

        // Variable reference
        if (is_string($fill) && str_starts_with($fill, '$')) {
            return 'background-color: ' . self::resolveValue($fill) . ';';
        }

        // Simple color string
        if (is_string($fill)) {
            return 'background-color: ' . self::resolveColor($fill) . ';';
        }

        // Array of fills → handle multi-fill
        if (is_array($fill) && isset($fill[0])) {
            return self::buildFills($fill);
        }

        // Fill object
        if (is_array($fill) && isset($fill['type'])) {
            if (isset($fill['enabled']) && $fill['enabled'] === false) {
                return '';
            }
            return match ($fill['type']) {
                'color' => 'background-color: ' . self::resolveColor($fill['color'] ?? '') . ';',
                'gradient' => self::buildGradient($fill),
                'image' => self::buildImageFill($fill),
                default => '',
            };
        }

        return '';
    }

    /**
     * Handle array of multiple fills (layered backgrounds).
     */
    public static function buildFills(mixed $fills): string
    {
        if (!is_array($fills) || empty($fills)) {
            return '';
        }
        // Single fill (not wrapped in array)
        if (isset($fills['type']) || (is_string($fills) && $fills !== '')) {
            return self::buildFill($fills);
        }

        $backgrounds = [];
        $bgColor = '';
        foreach ($fills as $fill) {
            if (!is_array($fill)) {
                // Simple color
                $bgColor = 'background-color: ' . self::resolveColor($fill) . ';';
                continue;
            }
            if (isset($fill['enabled']) && $fill['enabled'] === false) {
                continue;
            }
            $type = $fill['type'] ?? 'color';
            if ($type === 'color') {
                $bgColor = 'background-color: ' . self::resolveColor($fill['color'] ?? '') . ';';
            } elseif ($type === 'gradient') {
                $backgrounds[] = self::buildGradientValue($fill);
            } elseif ($type === 'image') {
                $url = $fill['url'] ?? '';
                $mode = $fill['mode'] ?? 'fill';
                $size = match ($mode) {
                    'fill' => 'cover', 'fit' => 'contain', 'stretch' => '100% 100%',
                    default => 'cover',
                };
                $backgrounds[] = "url('{$url}') center / {$size} no-repeat";
            }
        }

        $css = '';
        if (!empty($backgrounds)) {
            $css .= 'background: ' . implode(', ', $backgrounds) . ';';
        }
        if ($bgColor !== '') {
            $css .= $bgColor;
        }
        return $css;
    }

    /**
     * Build CSS gradient from .pen gradient fill.
     */
    private static function buildGradient(array $fill): string
    {
        $value = self::buildGradientValue($fill);
        return $value ? "background: {$value};" : '';
    }

    private static function buildGradientValue(array $fill): string
    {
        $gradType = $fill['gradientType'] ?? 'linear';
        $colors = $fill['colors'] ?? [];
        if (empty($colors)) {
            return '';
        }

        $stops = [];
        foreach ($colors as $stop) {
            $c = self::resolveColor($stop['color'] ?? '#000000');
            $pos = isset($stop['position']) ? (self::resolveValue($stop['position'])) : '';
            $posStr = is_numeric($pos) ? ' ' . round((float)$pos * 100) . '%' : '';
            $stops[] = $c . $posStr;
        }
        $stopStr = implode(', ', $stops);

        if ($gradType === 'linear') {
            $rotation = (float)($fill['rotation'] ?? 0);
            // .pen: counter-clockwise from top. CSS: clockwise from top (to bottom = 180deg)
            $cssDeg = fmod(360 - $rotation + 180, 360);
            return "linear-gradient({$cssDeg}deg, {$stopStr})";
        }

        if ($gradType === 'radial') {
            return "radial-gradient(ellipse at center, {$stopStr})";
        }

        if ($gradType === 'angular') {
            return "conic-gradient({$stopStr})";
        }

        return "linear-gradient({$stopStr})";
    }

    /**
     * Build CSS background-image from .pen image fill.
     */
    private static function buildImageFill(array $fill): string
    {
        $url = $fill['url'] ?? '';
        if ($url === '') {
            return '';
        }
        $mode = $fill['mode'] ?? 'fill';
        $size = match ($mode) {
            'fill' => 'cover',
            'fit' => 'contain',
            'stretch' => '100% 100%',
            default => 'cover',
        };
        $css = "background-image: url('{$url}');";
        $css .= "background-size: {$size};";
        $css .= 'background-position: center;';
        $css .= 'background-repeat: no-repeat;';
        if (isset($fill['opacity'])) {
            $css .= 'opacity: ' . self::resolveValue($fill['opacity']) . ';';
        }
        return $css;
    }

    /**
     * Convert .pen Stroke to CSS border declarations.
     */
    public static function buildStroke(mixed $stroke): string
    {
        if (!is_array($stroke) || empty($stroke)) {
            return '';
        }

        $thickness = $stroke['thickness'] ?? 0;
        $strokeFill = $stroke['fill'] ?? null;
        $color = '#000000';
        if ($strokeFill !== null) {
            if (is_string($strokeFill)) {
                $color = self::resolveColor($strokeFill);
            } elseif (is_array($strokeFill) && isset($strokeFill['type'])) {
                if ($strokeFill['type'] === 'color') {
                    $color = self::resolveColor($strokeFill['color'] ?? '#000000');
                }
            } elseif (is_array($strokeFill) && isset($strokeFill[0])) {
                // First color from array
                $first = $strokeFill[0];
                if (is_string($first)) {
                    $color = self::resolveColor($first);
                } elseif (is_array($first) && isset($first['color'])) {
                    $color = self::resolveColor($first['color']);
                }
            }
        }

        $style = 'solid';
        if (!empty($stroke['dashPattern'])) {
            $style = 'dashed';
        }

        // Per-side thickness
        if (is_array($thickness)) {
            $css = '';
            foreach (['top', 'right', 'bottom', 'left'] as $side) {
                $t = $thickness[$side] ?? 0;
                if (is_numeric($t) && (float)$t > 0) {
                    $tv = self::resolveValue($t);
                    $css .= "border-{$side}: {$tv}px {$style} {$color};";
                }
            }
            return $css;
        }

        // Uniform thickness
        $tv = self::resolveValue($thickness);
        if ($tv === '' || $tv === '0') {
            return '';
        }
        return "border: {$tv}px {$style} {$color};";
    }

    /**
     * Convert .pen Effects to CSS.
     */
    public static function buildEffects(mixed $effects): string
    {
        if ($effects === null) {
            return '';
        }
        if (!is_array($effects)) {
            return '';
        }
        // Single effect (not wrapped in array)
        if (isset($effects['type'])) {
            $effects = [$effects];
        }

        $shadows = [];
        $filters = [];
        $backdropFilters = [];

        foreach ($effects as $effect) {
            if (isset($effect['enabled']) && $effect['enabled'] === false) {
                continue;
            }
            $type = $effect['type'] ?? '';
            if ($type === 'shadow') {
                $x = self::resolveValue($effect['offset']['x'] ?? 0);
                $y = self::resolveValue($effect['offset']['y'] ?? 0);
                $blur = self::resolveValue($effect['blur'] ?? 0);
                $spread = self::resolveValue($effect['spread'] ?? 0);
                $sColor = self::resolveColor($effect['color'] ?? '#00000040');
                $inner = ($effect['shadowType'] ?? 'outer') === 'inner' ? 'inset ' : '';
                $shadows[] = "{$inner}{$x}px {$y}px {$blur}px {$spread}px {$sColor}";
            } elseif ($type === 'blur') {
                $radius = self::resolveValue($effect['radius'] ?? 0);
                $filters[] = "blur({$radius}px)";
            } elseif ($type === 'background_blur') {
                $radius = self::resolveValue($effect['radius'] ?? 0);
                $backdropFilters[] = "blur({$radius}px)";
            }
        }

        $css = '';
        if (!empty($shadows)) {
            $css .= 'box-shadow: ' . implode(', ', $shadows) . ';';
        }
        if (!empty($filters)) {
            $css .= 'filter: ' . implode(' ', $filters) . ';';
        }
        if (!empty($backdropFilters)) {
            $bd = implode(' ', $backdropFilters);
            $css .= "backdrop-filter: {$bd}; -webkit-backdrop-filter: {$bd};";
        }
        return $css;
    }

    /**
     * Convert .pen Layout properties to CSS flexbox.
     */
    public static function buildLayout(array $node): string
    {
        $layout = $node['layout'] ?? null;
        if ($layout === null || $layout === 'none') {
            // No flexbox — children are absolute
            return 'position: relative;';
        }

        $css = 'display: flex;';
        $css .= $layout === 'vertical' ? 'flex-direction: column;' : 'flex-direction: row;';

        $gap = $node['gap'] ?? null;
        if ($gap !== null) {
            $css .= 'gap: ' . self::resolveValue($gap) . 'px;';
        }

        // Padding
        $padding = $node['padding'] ?? null;
        if ($padding !== null) {
            $css .= self::buildPadding($padding);
        }

        // Justify content
        $jc = $node['justifyContent'] ?? null;
        if ($jc !== null) {
            $map = [
                'start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end',
                'space_between' => 'space-between', 'space_around' => 'space-around',
            ];
            $css .= 'justify-content: ' . ($map[$jc] ?? 'flex-start') . ';';
        }

        // Align items
        $ai = $node['alignItems'] ?? null;
        if ($ai !== null) {
            $map = ['start' => 'flex-start', 'center' => 'center', 'end' => 'flex-end'];
            $css .= 'align-items: ' . ($map[$ai] ?? 'flex-start') . ';';
        }

        return $css;
    }

    /**
     * Build CSS padding from .pen padding value.
     */
    public static function buildPadding(mixed $padding): string
    {
        if ($padding === null) {
            return '';
        }
        if (is_numeric($padding) || (is_string($padding) && str_starts_with($padding, '$'))) {
            return 'padding: ' . self::resolveValue($padding) . 'px;';
        }
        if (is_array($padding)) {
            $vals = array_map(fn($v) => self::resolveValue($v) . 'px', $padding);
            if (count($vals) === 2) {
                return "padding: {$vals[0]} {$vals[1]};";
            }
            if (count($vals) === 4) {
                return "padding: {$vals[0]} {$vals[1]} {$vals[2]} {$vals[3]};";
            }
        }
        return '';
    }

    /**
     * Convert .pen TextStyle to CSS typography.
     */
    public static function buildTypography(array $node): string
    {
        $css = '';

        if (isset($node['fontFamily'])) {
            $ff = self::resolveValue($node['fontFamily']);
            if (str_starts_with($ff, 'var(')) {
                $css .= "font-family: {$ff};";
            } else {
                $css .= "font-family: \"{$ff}\", sans-serif;";
            }
        }
        if (isset($node['fontSize'])) {
            $css .= 'font-size: ' . self::resolveValue($node['fontSize']) . 'px;';
        }
        if (isset($node['fontWeight'])) {
            $css .= 'font-weight: ' . self::resolveValue($node['fontWeight']) . ';';
        }
        if (isset($node['fontStyle'])) {
            $css .= 'font-style: ' . self::resolveValue($node['fontStyle']) . ';';
        }
        if (isset($node['letterSpacing'])) {
            $css .= 'letter-spacing: ' . self::resolveValue($node['letterSpacing']) . 'px;';
        }
        if (isset($node['lineHeight'])) {
            $css .= 'line-height: ' . self::resolveValue($node['lineHeight']) . ';';
        }
        if (isset($node['textAlign'])) {
            $css .= 'text-align: ' . $node['textAlign'] . ';';
        }

        $decoration = [];
        if (!empty($node['underline'])) {
            $decoration[] = 'underline';
        }
        if (!empty($node['strikethrough'])) {
            $decoration[] = 'line-through';
        }
        if (!empty($decoration)) {
            $css .= 'text-decoration: ' . implode(' ', $decoration) . ';';
        }

        return $css;
    }

    /**
     * Convert .pen Size to CSS width/height.
     */
    public static function buildSizing(array $node, string $parentLayout = 'horizontal'): string
    {
        $css = '';

        foreach (['width', 'height'] as $dim) {
            $value = $node[$dim] ?? null;
            if ($value === null) {
                continue;
            }
            $css .= self::buildDimension($dim, $value, $parentLayout);
        }

        return $css;
    }

    /**
     * Build a single dimension (width or height) CSS.
     */
    public static function buildDimension(string $dim, mixed $value,
                                           string $parentLayout = 'horizontal'): string
    {
        if ($value === null) {
            return '';
        }
        if (is_numeric($value)) {
            return "{$dim}: {$value}px;";
        }
        if (is_string($value)) {
            // Variable
            if (str_starts_with($value, '$')) {
                return "{$dim}: " . self::resolveValue($value) . ';';
            }
            // fill_container
            if (str_starts_with($value, 'fill_container')) {
                $fallback = self::extractFallback($value);
                $isMainAxis = ($dim === 'width' && $parentLayout === 'horizontal')
                           || ($dim === 'height' && $parentLayout === 'vertical');
                if ($isMainAxis) {
                    $base = $fallback !== null ? "{$fallback}px" : '0%';
                    return "flex: 1 1 {$base}; min-{$dim}: 0;";
                }
                // Cross axis: fill_container = 100%
                return "{$dim}: 100%;";
            }
            // fit_content
            if (str_starts_with($value, 'fit_content')) {
                $fallback = self::extractFallback($value);
                $css = "{$dim}: fit-content;";
                if ($fallback !== null) {
                    $css .= "min-{$dim}: {$fallback}px;";
                }
                return $css;
            }
        }

        return '';
    }

    /**
     * Extract fallback value from sizing strings like 'fill_container(100)'.
     */
    public static function extractFallback(string $sizing): ?float
    {
        if (preg_match('/\((\d+(?:\.\d+)?)\)/', $sizing, $m)) {
            return (float) $m[1];
        }
        return null;
    }

    /**
     * Build CSS position for absolutely positioned nodes.
     */
    public static function buildPosition(array $node): string
    {
        $css = 'position: absolute;';
        if (isset($node['x'])) {
            $css .= 'left: ' . self::resolveValue($node['x']) . 'px;';
        }
        if (isset($node['y'])) {
            $css .= 'top: ' . self::resolveValue($node['y']) . 'px;';
        }
        return $css;
    }

    /**
     * Build CSS rotation transform.
     */
    public static function buildRotation(mixed $rotation): string
    {
        if ($rotation === null || $rotation === 0) {
            return '';
        }
        // .pen: counter-clockwise. CSS: clockwise. Negate.
        $val = self::resolveValue($rotation);
        if (is_numeric($val)) {
            $deg = -(float)$val;
            return "transform: rotate({$deg}deg);";
        }
        return "transform: rotate(calc(-1 * {$val}));";
    }

    /**
     * Convert .pen cornerRadius to CSS border-radius.
     */
    public static function buildCornerRadius(mixed $radius): string
    {
        if ($radius === null || $radius === 0) {
            return '';
        }
        if (is_numeric($radius) || (is_string($radius) && str_starts_with($radius, '$'))) {
            return 'border-radius: ' . self::resolveValue($radius) . 'px;';
        }
        if (is_array($radius) && count($radius) === 4) {
            $vals = array_map(fn($v) => self::resolveValue($v) . 'px', $radius);
            return 'border-radius: ' . implode(' ', $vals) . ';';
        }
        return '';
    }

    /**
     * Convert opacity value.
     */
    public static function buildOpacity(mixed $opacity): string
    {
        if ($opacity === null || $opacity === 1 || $opacity === 1.0) {
            return '';
        }
        return 'opacity: ' . self::resolveValue($opacity) . ';';
    }

    /**
     * Convert clip to overflow.
     */
    public static function buildClip(mixed $clip): string
    {
        if ($clip === true) {
            return 'overflow: hidden;';
        }
        return '';
    }

    /**
     * Build text color from fill (text uses color, not background).
     */
    public static function buildTextColor(mixed $fill): string
    {
        if ($fill === null) {
            return '';
        }
        if (is_string($fill)) {
            return 'color: ' . self::resolveColor($fill) . ';';
        }
        if (is_array($fill) && isset($fill['type']) && $fill['type'] === 'color') {
            return 'color: ' . self::resolveColor($fill['color'] ?? '') . ';';
        }
        if (is_array($fill) && isset($fill['type']) && $fill['type'] === 'gradient') {
            // Text with gradient fill → CSS background-clip trick
            $grad = self::buildGradientValue($fill);
            return "background: {$grad}; -webkit-background-clip: text; " .
                   '-webkit-text-fill-color: transparent; background-clip: text;';
        }
        // Array of fills → use first color
        if (is_array($fill) && isset($fill[0])) {
            return self::buildTextColor($fill[0]);
        }
        return '';
    }

    /**
     * Convenience: build all CSS for a node.
     */
    public static function buildAllStyles(array $node, bool $isAbsolute = false,
                                           string $parentLayout = 'horizontal'): string
    {
        $css = '';
        $css .= self::buildSizing($node, $parentLayout);
        if ($isAbsolute) {
            $css .= self::buildPosition($node);
        }
        $css .= self::buildCornerRadius($node['cornerRadius'] ?? null);
        $css .= self::buildOpacity($node['opacity'] ?? null);
        $css .= self::buildClip($node['clip'] ?? null);
        $css .= self::buildRotation($node['rotation'] ?? null);
        return $css;
    }
}
