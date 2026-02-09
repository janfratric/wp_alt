<?php declare(strict_types=1);

namespace App\PageBuilder;

/**
 * CSS generation, scoping, and sanitization for per-instance and page-level styles.
 */
class StyleRenderer
{
    /** Allowed units for spacing, font-size, border, etc. */
    private const UNITS = ['px', 'rem', 'em', '%', 'vh', 'vw'];

    /** Allowed text-align values */
    private const TEXT_ALIGNS = ['left', 'center', 'right', 'justify'];

    /** Allowed font-weight values */
    private const TEXT_WEIGHTS = ['100', '200', '300', '400', '500', '600', '700', '800', '900'];

    /** Allowed border-style values */
    private const BORDER_STYLES = ['none', 'solid', 'dashed', 'dotted', 'double'];

    /** Allowed background-size values */
    private const BG_SIZES = ['cover', 'contain', 'auto'];

    /** Allowed background-position values */
    private const BG_POSITIONS = ['center center', 'top center', 'bottom center', 'left center', 'right center'];

    /** Allowed background-repeat values */
    private const BG_REPEATS = ['no-repeat', 'repeat', 'repeat-x', 'repeat-y'];

    /** Page layout target whitelist (key => CSS selector) */
    private const PAGE_TARGETS = [
        'page_body'  => '.page-body',
        'container'  => '.container',
        'site_main'  => '.site-main',
    ];

    /**
     * Convert style_data keys to CSS property:value pairs for inline style attribute.
     */
    public static function buildInlineStyle(array $styleData): string
    {
        $parts = [];

        // Margin
        $mUnit = self::validUnit($styleData['margin_unit'] ?? 'px');
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $val = $styleData['margin_' . $side] ?? '';
            if ($val !== '' && $val !== null && is_numeric($val)) {
                $parts[] = "margin-{$side}: {$val}{$mUnit}";
            }
        }

        // NOTE: padding, background-*, border, border-radius are emitted by
        // buildCascadeStyles() as CSS rules (not inline) so they cascade into
        // child elements.  Only inheriting / wrapper-only properties go here.

        // Text color
        $textColor = $styleData['text_color'] ?? '';
        if ($textColor !== '' && self::validColor($textColor)) {
            $parts[] = "color: {$textColor}";
        }

        // Font size
        $textSize = $styleData['text_size'] ?? '';
        $textSizeUnit = self::validUnit($styleData['text_size_unit'] ?? 'px');
        if ($textSize !== '' && is_numeric($textSize)) {
            $parts[] = "font-size: {$textSize}{$textSizeUnit}";
        }

        // Text align
        $textAlign = $styleData['text_align'] ?? '';
        if ($textAlign !== '' && in_array($textAlign, self::TEXT_ALIGNS, true)) {
            $parts[] = "text-align: {$textAlign}";
        }

        // Font weight
        $textWeight = $styleData['text_weight'] ?? '';
        if ($textWeight !== '' && in_array($textWeight, self::TEXT_WEIGHTS, true)) {
            $parts[] = "font-weight: {$textWeight}";
        }

        // NOTE: border + border-radius are emitted by buildCascadeStyles().

        // Box shadow
        $shadowX = $styleData['shadow_x'] ?? '';
        $shadowY = $styleData['shadow_y'] ?? '';
        $shadowBlur = $styleData['shadow_blur'] ?? '';
        $shadowSpread = $styleData['shadow_spread'] ?? '';
        $shadowColor = $styleData['shadow_color'] ?? '';
        if (is_numeric($shadowX) && is_numeric($shadowY) && is_numeric($shadowBlur)
            && is_numeric($shadowSpread) && self::validColor($shadowColor)
            && ($shadowX != 0 || $shadowY != 0 || $shadowBlur != 0 || $shadowSpread != 0)) {
            $parts[] = "box-shadow: {$shadowX}px {$shadowY}px {$shadowBlur}px {$shadowSpread}px {$shadowColor}";
        }

        // Opacity
        $opacity = $styleData['opacity'] ?? '';
        if ($opacity !== '' && is_numeric($opacity) && (float)$opacity < 1.0) {
            $clamped = max(0.0, min(1.0, (float)$opacity));
            $parts[] = "opacity: {$clamped}";
        }

        // Max width
        $maxWidth = $styleData['max_width'] ?? '';
        if ($maxWidth !== '' && self::validCssDimension($maxWidth)) {
            $parts[] = "max-width: {$maxWidth}";
        }

        // Min height
        $minHeight = $styleData['min_height'] ?? '';
        if ($minHeight !== '' && self::validCssDimension($minHeight)) {
            $parts[] = "min-height: {$minHeight}";
        }

        return implode('; ', $parts);
    }

    /**
     * Extract and sanitize the custom_class value.
     */
    public static function getCustomClasses(array $styleData): string
    {
        $cls = $styleData['custom_class'] ?? '';
        if (!is_string($cls)) {
            return '';
        }
        return self::sanitizeCssClass($cls);
    }

    /**
     * Build CSS rules for page layout targets (.page-body, .container, .site-main).
     */
    public static function buildPageLayoutCss(array $pageStyleData): string
    {
        $css = '';

        foreach (self::PAGE_TARGETS as $key => $selector) {
            $data = $pageStyleData[$key] ?? [];
            if (!is_array($data) || empty($data)) {
                continue;
            }

            // Page layout targets are CSS rules (not inline styles), so we
            // combine inheriting + non-inheriting declarations in one rule.
            $inheriting = self::buildInlineStyle($data);
            $nonInheriting = self::buildNonInheritingDeclarations($data);
            $all = implode('; ', array_filter([$inheriting, $nonInheriting]));
            if ($all !== '') {
                $css .= "{$selector} { {$all}; }\n";
            }
        }

        return $css;
    }

    /**
     * Validate and sanitize all style values.
     */
    public static function sanitizeStyleData(array $data): array
    {
        $sanitized = [];

        // Numeric fields (spacing, sizes)
        $numericKeys = [
            'margin_top', 'margin_right', 'margin_bottom', 'margin_left',
            'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
            'text_size', 'border_width', 'border_radius',
            'shadow_x', 'shadow_y', 'shadow_blur', 'shadow_spread',
        ];
        foreach ($numericKeys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $val = (float)$data[$key];
                $val = max(-9999, min(9999, $val));
                $sanitized[$key] = (string)$val;
            }
        }

        // Opacity (0 to 1)
        if (isset($data['opacity']) && $data['opacity'] !== '') {
            $val = (float)$data['opacity'];
            $sanitized['opacity'] = (string)max(0.0, min(1.0, $val));
        }

        // Unit fields
        $unitKeys = [
            'margin_unit', 'padding_unit', 'text_size_unit',
            'border_unit', 'border_radius_unit',
        ];
        foreach ($unitKeys as $key) {
            if (isset($data[$key]) && in_array($data[$key], self::UNITS, true)) {
                $sanitized[$key] = $data[$key];
            }
        }

        // Color fields
        $colorKeys = ['bg_color', 'text_color', 'border_color', 'shadow_color'];
        foreach ($colorKeys as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && self::validColor($data[$key])) {
                $sanitized[$key] = $data[$key];
            }
        }

        // Select fields
        $selectWhitelist = [
            'text_align'   => self::TEXT_ALIGNS,
            'text_weight'  => self::TEXT_WEIGHTS,
            'border_style' => self::BORDER_STYLES,
            'bg_size'      => self::BG_SIZES,
            'bg_position'  => self::BG_POSITIONS,
            'bg_repeat'    => self::BG_REPEATS,
        ];
        foreach ($selectWhitelist as $key => $allowed) {
            if (isset($data[$key]) && in_array($data[$key], $allowed, true)) {
                $sanitized[$key] = $data[$key];
            }
        }

        // String fields requiring sanitization
        if (isset($data['bg_image']) && is_string($data['bg_image'])) {
            $img = self::sanitizeCssValue($data['bg_image']);
            if ($img !== '' && self::validBgImageUrl($img)) {
                $sanitized['bg_image'] = $img;
            }
        }

        if (isset($data['max_width']) && is_string($data['max_width'])) {
            $val = self::sanitizeCssValue($data['max_width']);
            if ($val !== '' && self::validCssDimension($val)) {
                $sanitized['max_width'] = $val;
            }
        }

        if (isset($data['min_height']) && is_string($data['min_height'])) {
            $val = self::sanitizeCssValue($data['min_height']);
            if ($val !== '' && self::validCssDimension($val)) {
                $sanitized['min_height'] = $val;
            }
        }

        // Custom class
        if (isset($data['custom_class']) && is_string($data['custom_class'])) {
            $sanitized['custom_class'] = self::sanitizeCssClass($data['custom_class']);
        }

        // Custom CSS
        if (isset($data['custom_css']) && is_string($data['custom_css'])) {
            $sanitized['custom_css'] = self::sanitizeCustomCss($data['custom_css']);
        }

        // Linked mode flags (UI convenience, not used in rendering)
        foreach (['margin_linked', 'padding_linked'] as $key) {
            if (isset($data[$key])) {
                $sanitized[$key] = (bool)$data[$key];
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize custom CSS: strip dangerous patterns, limit length.
     */
    public static function sanitizeCustomCss(string $css): string
    {
        // Limit length
        $css = mb_substr($css, 0, 10000, 'UTF-8');

        // Strip dangerous patterns (case-insensitive)
        $dangerous = [
            '/@import\b/i',
            '/@charset\b/i',
            '/javascript\s*:/i',
            '/expression\s*\(/i',
            '/behavior\s*:/i',
            '/-moz-binding\s*:/i',
            '/<\/style/i',
            '/<script/i',
            '/<!--/',
        ];
        $css = preg_replace($dangerous, '', $css);

        return trim($css);
    }

    /**
     * Prepend scope selector to every CSS rule.
     */
    public static function scopeCustomCss(string $css, string $scope): string
    {
        $css = trim($css);
        if ($css === '') {
            return '';
        }

        // Split into individual rule blocks by closing brace
        $result = '';
        $depth = 0;
        $buffer = '';
        $inMedia = false;

        $len = strlen($css);
        for ($i = 0; $i < $len; $i++) {
            $char = $css[$i];
            $buffer .= $char;

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    // We have a complete top-level block
                    $block = trim($buffer);
                    $buffer = '';

                    if ($block === '') {
                        continue;
                    }

                    // Check if it's an @media or @keyframes block
                    if (preg_match('/^@(media|keyframes|supports)\b/i', $block)) {
                        // Scope rules inside the media block
                        $result .= self::scopeMediaBlock($block, $scope) . "\n";
                    } else {
                        // Regular rule — scope the selector
                        $result .= self::scopeRule($block, $scope) . "\n";
                    }
                }
            }
        }

        // Handle remaining buffer (bare properties without selector)
        $remaining = trim($buffer);
        if ($remaining !== '') {
            // Wrap bare properties in scope selector
            if (strpos($remaining, '{') === false) {
                $result .= "{$scope} { {$remaining} }\n";
            }
        }

        return trim($result);
    }

    /**
     * Build CSS rules for non-inheriting properties that must cascade into
     * child elements via "scope, scope > *" selectors.
     *
     * Properties like background-color, padding, border, and border-radius
     * do not inherit in CSS.  Inline styles on the wrapper div are invisible
     * when a child element sets its own value via catalogue CSS.  Emitting
     * them as CSS rules with higher source-order wins the tie at equal
     * specificity and lets the GUI controls actually take effect.
     *
     * @return string CSS rule block (may be empty if no relevant properties are set)
     */
    public static function buildCascadeStyles(array $styleData, string $scope): string
    {
        $declarations = self::buildNonInheritingDeclarations($styleData);
        if ($declarations === '') {
            return '';
        }
        return "{$scope}, {$scope} > * { {$declarations}; }\n";
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Build CSS declarations for non-inheriting properties (background, padding,
     * border, border-radius).  Returns a semicolon-separated string of
     * property:value pairs without wrapping selectors or braces.
     */
    private static function buildNonInheritingDeclarations(array $styleData): string
    {
        $props = [];

        // Background color
        $bgColor = $styleData['bg_color'] ?? '';
        if ($bgColor !== '' && self::validColor($bgColor)) {
            $props[] = "background-color: {$bgColor}";
        }

        // Background image
        $bgImage = $styleData['bg_image'] ?? '';
        if ($bgImage !== '' && self::validBgImageUrl($bgImage)) {
            $props[] = "background-image: url('" . str_replace("'", "\\'", $bgImage) . "')";
        }

        // Background size
        $bgSize = $styleData['bg_size'] ?? '';
        if ($bgSize !== '' && in_array($bgSize, self::BG_SIZES, true)) {
            $props[] = "background-size: {$bgSize}";
        }

        // Background position
        $bgPos = $styleData['bg_position'] ?? '';
        if ($bgPos !== '' && in_array($bgPos, self::BG_POSITIONS, true)) {
            $props[] = "background-position: {$bgPos}";
        }

        // Background repeat
        $bgRepeat = $styleData['bg_repeat'] ?? '';
        if ($bgRepeat !== '' && in_array($bgRepeat, self::BG_REPEATS, true)) {
            $props[] = "background-repeat: {$bgRepeat}";
        }

        // Padding
        $pUnit = self::validUnit($styleData['padding_unit'] ?? 'px');
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $val = $styleData['padding_' . $side] ?? '';
            if ($val !== '' && $val !== null && is_numeric($val)) {
                $props[] = "padding-{$side}: {$val}{$pUnit}";
            }
        }

        // Border
        $borderWidth = $styleData['border_width'] ?? '';
        $borderUnit = self::validUnit($styleData['border_unit'] ?? 'px');
        $borderStyle = $styleData['border_style'] ?? '';
        $borderColor = $styleData['border_color'] ?? '';
        if ($borderWidth !== '' && is_numeric($borderWidth) && (float)$borderWidth > 0
            && in_array($borderStyle, self::BORDER_STYLES, true) && $borderStyle !== 'none'
            && self::validColor($borderColor)) {
            $props[] = "border: {$borderWidth}{$borderUnit} {$borderStyle} {$borderColor}";
        }

        // Border radius
        $borderRadius = $styleData['border_radius'] ?? '';
        $borderRadiusUnit = self::validUnit($styleData['border_radius_unit'] ?? 'px');
        if ($borderRadius !== '' && is_numeric($borderRadius) && (float)$borderRadius > 0) {
            $props[] = "border-radius: {$borderRadius}{$borderRadiusUnit}";
        }

        return implode('; ', $props);
    }

    /**
     * Block injection in individual CSS values.
     */
    private static function sanitizeCssValue(string $value): string
    {
        $value = trim($value);

        // Block dangerous patterns
        $patterns = [
            '/expression\s*\(/i',
            '/javascript\s*:/i',
            '/@import/i',
            '/url\s*\(\s*["\']?\s*javascript/i',
            '/</',
            '/>/',
            '/\/\*/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return '';
            }
        }

        return $value;
    }

    /**
     * Strip to alphanumeric, hyphens, underscores, and spaces.
     */
    private static function sanitizeCssClass(string $value): string
    {
        return trim(preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $value));
    }

    /**
     * Validate a unit value against the whitelist.
     */
    private static function validUnit(string $unit): string
    {
        return in_array($unit, self::UNITS, true) ? $unit : 'px';
    }

    /**
     * Validate a CSS color value (#hex or rgba()).
     */
    private static function validColor(string $color): bool
    {
        if ($color === '') {
            return false;
        }
        // #hex (3, 4, 6, or 8 chars)
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            return true;
        }
        // rgba(n, n, n, n) or rgb(n, n, n)
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[\d.]+\s*)?\)$/', $color)) {
            return true;
        }
        return false;
    }

    /**
     * Validate a background image URL (simple path or data URI).
     */
    private static function validBgImageUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        // Block javascript: and data URIs that aren't images
        if (preg_match('/^javascript:/i', $url)) {
            return false;
        }
        if (str_starts_with($url, 'data:') && !preg_match('/^data:image\//i', $url)) {
            return false;
        }
        // Block dangerous patterns
        if (preg_match('/<|>|expression|javascript/i', $url)) {
            return false;
        }
        return true;
    }

    /**
     * Validate a CSS dimension value like "100px", "50%", "auto", "100vh".
     */
    private static function validCssDimension(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || $value === 'auto' || $value === 'none') {
            return true;
        }
        // Number + unit
        if (preg_match('/^-?\d+(\.\d+)?(px|rem|em|%|vh|vw)$/', $value)) {
            return true;
        }
        return false;
    }

    /**
     * Scope a single CSS rule block (selector { declarations }).
     */
    private static function scopeRule(string $block, string $scope): string
    {
        $bracePos = strpos($block, '{');
        if ($bracePos === false) {
            return $block;
        }

        $selector = trim(substr($block, 0, $bracePos));
        $rest = substr($block, $bracePos);

        // Already scoped — don't double-prefix
        if (str_starts_with($selector, $scope)) {
            return $block;
        }

        // Multiple selectors separated by comma
        $selectors = explode(',', $selector);
        $scoped = [];
        foreach ($selectors as $sel) {
            $sel = trim($sel);
            if ($sel !== '') {
                $scoped[] = $scope . ' ' . $sel;
            }
        }

        return implode(', ', $scoped) . ' ' . $rest;
    }

    /**
     * Scope rules inside an @media or @supports block.
     */
    private static function scopeMediaBlock(string $block, string $scope): string
    {
        // Find the first { and last }
        $firstBrace = strpos($block, '{');
        if ($firstBrace === false) {
            return $block;
        }
        $lastBrace = strrpos($block, '}');
        if ($lastBrace === false || $lastBrace <= $firstBrace) {
            return $block;
        }

        $atRule = substr($block, 0, $firstBrace + 1);
        $inner = substr($block, $firstBrace + 1, $lastBrace - $firstBrace - 1);

        // Scope inner rules
        $scopedInner = self::scopeCustomCss($inner, $scope);

        return $atRule . "\n" . $scopedInner . "\n}";
    }
}
