<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;

class StyleController
{
    private App $app;

    /**
     * Font stacks keyed by identifier.
     * Keys starting with "google_" trigger Google Fonts loading on the public site.
     */
    public const FONT_STACKS = [
        // Web-safe
        'system_ui'          => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
        'georgia'            => "Georgia, 'Times New Roman', Times, serif",
        'helvetica'          => "'Helvetica Neue', Helvetica, Arial, sans-serif",
        'verdana'            => "Verdana, Geneva, Tahoma, sans-serif",
        'palatino'           => "'Palatino Linotype', 'Book Antiqua', Palatino, serif",
        // Google Fonts
        'google_inter'       => "'Inter', sans-serif",
        'google_poppins'     => "'Poppins', sans-serif",
        'google_open_sans'   => "'Open Sans', sans-serif",
        'google_lora'        => "'Lora', serif",
        'google_merriweather'=> "'Merriweather', serif",
        'google_playfair'    => "'Playfair Display', serif",
        'google_roboto'      => "'Roboto', sans-serif",
        'google_source_sans' => "'Source Sans 3', sans-serif",
        'google_raleway'     => "'Raleway', sans-serif",
        'google_nunito'      => "'Nunito', sans-serif",
    ];

    /**
     * Human-readable labels for font options.
     */
    public const FONT_LABELS = [
        'system_ui'          => 'System UI (default)',
        'georgia'            => 'Georgia (serif)',
        'helvetica'          => 'Helvetica / Arial',
        'verdana'            => 'Verdana',
        'palatino'           => 'Palatino (serif)',
        'google_inter'       => 'Inter',
        'google_poppins'     => 'Poppins',
        'google_open_sans'   => 'Open Sans',
        'google_lora'        => 'Lora (serif)',
        'google_merriweather'=> 'Merriweather (serif)',
        'google_playfair'    => 'Playfair Display (serif)',
        'google_roboto'      => 'Roboto',
        'google_source_sans' => 'Source Sans 3',
        'google_raleway'     => 'Raleway',
        'google_nunito'      => 'Nunito',
    ];

    /**
     * Map Google Font keys to the family name used in the fonts.googleapis.com URL.
     */
    public const GOOGLE_FONT_FAMILIES = [
        'google_inter'        => 'Inter',
        'google_poppins'      => 'Poppins',
        'google_open_sans'    => 'Open+Sans',
        'google_lora'         => 'Lora',
        'google_merriweather' => 'Merriweather',
        'google_playfair'     => 'Playfair+Display',
        'google_roboto'       => 'Roboto',
        'google_source_sans'  => 'Source+Sans+3',
        'google_raleway'      => 'Raleway',
        'google_nunito'       => 'Nunito',
    ];

    /**
     * Shadow presets: key => [shadow, shadow-lg]
     */
    public const SHADOW_PRESETS = [
        'none'   => ['none', 'none'],
        'subtle' => [
            '0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06)',
            '0 4px 12px rgba(0,0,0,0.1)',
        ],
        'medium' => [
            '0 2px 6px rgba(0,0,0,0.12), 0 2px 4px rgba(0,0,0,0.08)',
            '0 8px 24px rgba(0,0,0,0.15)',
        ],
        'strong' => [
            '0 4px 8px rgba(0,0,0,0.16), 0 2px 4px rgba(0,0,0,0.12)',
            '0 12px 36px rgba(0,0,0,0.2)',
        ],
    ];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Default values for all style settings.
     */
    public static function getDefaults(): array
    {
        return [
            // Colors
            'style_color_primary'      => '#2563eb',
            'style_color_primary_hover'=> '#1d4ed8',
            'style_color_text'         => '#1e293b',
            'style_color_text_muted'   => '#64748b',
            'style_color_bg'           => '#ffffff',
            'style_color_bg_alt'       => '#f8fafc',
            'style_color_border'       => '#e2e8f0',
            'style_color_link'         => '#2563eb',
            'style_color_link_hover'   => '#1d4ed8',
            // Header & Footer
            'style_color_header_bg'    => '#ffffff',
            'style_color_header_text'  => '#1e293b',
            'style_color_footer_bg'    => '#f8fafc',
            'style_color_footer_text'  => '#64748b',
            // Typography
            'style_font_family'        => 'system_ui',
            'style_font_heading'       => 'system_ui',
            'style_font_size_base'     => '1rem',
            'style_line_height'        => '1.7',
            'style_heading_weight'     => '700',
            // Shadows
            'style_shadow'             => 'subtle',
            // Auto-derive
            'style_auto_derive_hover'  => '1',
        ];
    }

    /**
     * GET /admin/style — Show the master style editor.
     */
    public function index(Request $request): Response
    {
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can access style settings.');
            return Response::redirect('/admin/dashboard');
        }

        $defaults = self::getDefaults();
        $saved = $this->loadStyleSettings();
        $styles = array_merge($defaults, $saved);

        $html = $this->app->template()->render('admin/style', [
            'title'      => 'Master Style',
            'activeNav'  => 'style',
            'styles'     => $styles,
            'defaults'   => $defaults,
            'fontStacks' => self::FONT_STACKS,
            'fontLabels' => self::FONT_LABELS,
            'googleFontFamilies' => self::GOOGLE_FONT_FAMILIES,
            'shadowPresets' => self::SHADOW_PRESETS,
        ]);

        return $this->withSecurityHeaders($html);
    }

    /**
     * PUT /admin/style — Save style settings.
     */
    public function update(Request $request): Response
    {
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can access style settings.');
            return Response::redirect('/admin/dashboard');
        }

        // Handle reset
        if ($request->input('_reset') === '1') {
            $this->deleteAllStyleSettings();
            Config::reset();
            Session::flash('success', 'Styles reset to defaults.');
            return Response::redirect('/admin/style');
        }

        $defaults = self::getDefaults();

        // --- Colors ---
        $colorKeys = [
            'style_color_primary', 'style_color_primary_hover',
            'style_color_text', 'style_color_text_muted',
            'style_color_bg', 'style_color_bg_alt', 'style_color_border',
            'style_color_link', 'style_color_link_hover',
            'style_color_header_bg', 'style_color_header_text',
            'style_color_footer_bg', 'style_color_footer_text',
        ];

        foreach ($colorKeys as $key) {
            $value = trim((string) $request->input($key, ''));
            if ($value !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                $this->saveSetting($key, $value);
            }
        }

        // --- Auto-derive hover ---
        $autoDerive = $request->input('style_auto_derive_hover');
        $this->saveSetting(
            'style_auto_derive_hover',
            ($autoDerive !== null && $autoDerive !== '' && $autoDerive !== '0') ? '1' : '0'
        );

        // If auto-derive is on, compute hover from primary
        if ($autoDerive !== null && $autoDerive !== '' && $autoDerive !== '0') {
            $primary = trim((string) $request->input('style_color_primary', ''));
            if ($primary !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) {
                $hover = self::darkenColor($primary, 12);
                $this->saveSetting('style_color_primary_hover', $hover);
            }
        }

        // --- Typography ---
        $fontFamily = trim((string) $request->input('style_font_family', ''));
        if ($fontFamily !== '' && isset(self::FONT_STACKS[$fontFamily])) {
            $this->saveSetting('style_font_family', $fontFamily);
        }

        $fontHeading = trim((string) $request->input('style_font_heading', ''));
        if ($fontHeading !== '' && isset(self::FONT_STACKS[$fontHeading])) {
            $this->saveSetting('style_font_heading', $fontHeading);
        }

        $fontSize = trim((string) $request->input('style_font_size_base', ''));
        $allowedSizes = ['0.875rem', '1rem', '1.125rem'];
        if (in_array($fontSize, $allowedSizes, true)) {
            $this->saveSetting('style_font_size_base', $fontSize);
        }

        $lineHeight = $request->input('style_line_height');
        if ($lineHeight !== null && $lineHeight !== '') {
            $lh = max(1.2, min(2.2, round((float) $lineHeight, 1)));
            $this->saveSetting('style_line_height', (string) $lh);
        }

        $headingWeight = trim((string) $request->input('style_heading_weight', ''));
        $allowedWeights = ['400', '500', '600', '700', '800', '900'];
        if (in_array($headingWeight, $allowedWeights, true)) {
            $this->saveSetting('style_heading_weight', $headingWeight);
        }

        // --- Shadows ---
        $shadow = trim((string) $request->input('style_shadow', ''));
        if ($shadow !== '' && isset(self::SHADOW_PRESETS[$shadow])) {
            $this->saveSetting('style_shadow', $shadow);
        }

        Config::reset();
        Session::flash('success', 'Master style saved successfully.');
        return Response::redirect('/admin/style');
    }

    /**
     * Load all style_* settings from the database.
     */
    private function loadStyleSettings(): array
    {
        $rows = QueryBuilder::query('settings')
            ->select('key', 'value')
            ->whereRaw("key LIKE :prefix", [':prefix' => 'style_%'])
            ->get();

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    /**
     * Save a single setting (upsert).
     */
    private function saveSetting(string $key, string $value): void
    {
        $existing = QueryBuilder::query('settings')
            ->select('key')
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            QueryBuilder::query('settings')
                ->where('key', $key)
                ->update(['value' => $value]);
        } else {
            QueryBuilder::query('settings')->insert([
                'key'   => $key,
                'value' => $value,
            ]);
        }
    }

    /**
     * Delete all style_* settings (for reset).
     */
    private function deleteAllStyleSettings(): void
    {
        QueryBuilder::query('settings')
            ->whereRaw("key LIKE :prefix", [':prefix' => 'style_%'])
            ->delete();
    }

    /**
     * Darken a hex color by a given percentage.
     */
    public static function darkenColor(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        $r = max(0, (int) round(hexdec(substr($hex, 0, 2)) * (1 - $percent / 100)));
        $g = max(0, (int) round(hexdec(substr($hex, 2, 2)) * (1 - $percent / 100)));
        $b = max(0, (int) round(hexdec(substr($hex, 4, 2)) * (1 - $percent / 100)));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Build CSS variable overrides string for the public site.
     * Returns the content for a <style> block (including :root selector and component overrides).
     */
    public static function buildStyleOverrides(array $settings): string
    {
        $defaults = self::getDefaults();
        $cssVars = [];

        // Map settings keys to CSS variable names
        $varMap = [
            'style_color_primary'      => '--color-primary',
            'style_color_primary_hover'=> '--color-primary-hover',
            'style_color_text'         => '--color-text',
            'style_color_text_muted'   => '--color-text-muted',
            'style_color_bg'           => '--color-bg',
            'style_color_bg_alt'       => '--color-bg-alt',
            'style_color_border'       => '--color-border',
            'style_color_link'         => '--color-link',
            'style_color_link_hover'   => '--color-link-hover',
            'style_font_size_base'     => '--font-size-base',
            'style_line_height'        => '--line-height',
        ];

        foreach ($varMap as $settingKey => $cssVar) {
            $value = $settings[$settingKey] ?? '';
            $default = $defaults[$settingKey] ?? '';
            if ($value !== '' && $value !== $default) {
                $cssVars[] = $cssVar . ': ' . $value;
            }
        }

        // Font family
        $fontKey = $settings['style_font_family'] ?? 'system_ui';
        if ($fontKey !== 'system_ui' && isset(self::FONT_STACKS[$fontKey])) {
            $cssVars[] = '--font-family: ' . self::FONT_STACKS[$fontKey];
        }

        // Shadow
        $shadowKey = $settings['style_shadow'] ?? 'subtle';
        if ($shadowKey !== 'subtle' && isset(self::SHADOW_PRESETS[$shadowKey])) {
            $preset = self::SHADOW_PRESETS[$shadowKey];
            $cssVars[] = '--shadow: ' . $preset[0];
            $cssVars[] = '--shadow-lg: ' . $preset[1];
        }

        $output = '';

        if (!empty($cssVars)) {
            $output .= ':root { ' . implode('; ', $cssVars) . '; }' . "\n";
        }

        // Component-level overrides
        $componentCss = [];

        // Header
        $headerBg = $settings['style_color_header_bg'] ?? $defaults['style_color_header_bg'];
        $headerText = $settings['style_color_header_text'] ?? $defaults['style_color_header_text'];
        if ($headerBg !== $defaults['style_color_header_bg'] || $headerText !== $defaults['style_color_header_text']) {
            $componentCss[] = '.site-header { background: ' . $headerBg . '; }';
            $componentCss[] = '.site-header .site-logo, .site-header .nav-list a { color: ' . $headerText . '; }';
        }

        // Footer
        $footerBg = $settings['style_color_footer_bg'] ?? $defaults['style_color_footer_bg'];
        $footerText = $settings['style_color_footer_text'] ?? $defaults['style_color_footer_text'];
        if ($footerBg !== $defaults['style_color_footer_bg'] || $footerText !== $defaults['style_color_footer_text']) {
            $componentCss[] = '.site-footer { background: ' . $footerBg . '; color: ' . $footerText . '; }';
        }

        // Heading font
        $headingFontKey = $settings['style_font_heading'] ?? 'system_ui';
        $headingWeight = $settings['style_heading_weight'] ?? '700';
        if ($headingFontKey !== 'system_ui' || $headingWeight !== '700') {
            $headingFont = isset(self::FONT_STACKS[$headingFontKey])
                ? self::FONT_STACKS[$headingFontKey]
                : 'var(--font-family)';
            $componentCss[] = 'h1, h2, h3, h4, h5, h6 { font-family: ' . $headingFont . '; font-weight: ' . $headingWeight . '; }';
        }

        if (!empty($componentCss)) {
            $output .= implode("\n", $componentCss) . "\n";
        }

        return $output;
    }

    /**
     * Build Google Font <link> tags for the public site.
     */
    public static function buildGoogleFontLinks(array $settings): string
    {
        $families = [];

        $fontKeys = [
            $settings['style_font_family'] ?? 'system_ui',
            $settings['style_font_heading'] ?? 'system_ui',
        ];

        foreach ($fontKeys as $key) {
            if (isset(self::GOOGLE_FONT_FAMILIES[$key])) {
                $family = self::GOOGLE_FONT_FAMILIES[$key];
                if (!in_array($family, $families, true)) {
                    $families[] = $family;
                }
            }
        }

        if (empty($families)) {
            return '';
        }

        $familyParam = implode('&family=', array_map(
            fn(string $f) => $f . ':wght@400;500;600;700;800;900',
            $families
        ));

        return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
            . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' . $familyParam . '&display=swap">' . "\n";
    }

    /**
     * Add standard security headers.
     */
    private function withSecurityHeaders(string $html): Response
    {
        return Response::html($html)
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self'; "
                . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self'; "
                . "font-src 'self' https://fonts.gstatic.com"
            );
    }
}
