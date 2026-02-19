<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;
use App\AIAssistant\AIController;
use App\AIAssistant\ClaudeClient;
use App\PageBuilder\PenConverter;

class SettingsController
{
    private const DEFAULT_MODELS = [
        ['id' => 'claude-sonnet-4-20250514', 'display_name' => 'Claude Sonnet 4 (Recommended)'],
        ['id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5 (Faster, lower cost)'],
        ['id' => 'claude-opus-4-6', 'display_name' => 'Claude Opus 4.6 (Most capable)'],
    ];

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/settings — Show the settings form.
     */
    public function index(Request $request): Response
    {
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can access settings.');
            return Response::redirect('/admin/dashboard');
        }

        $settings = $this->loadSettings();

        $hasApiKey = !empty($settings['claude_api_key']);
        $hasGoogleApiKey = !empty($settings['google_api_key']);

        // Decode model management data
        $availableModels = json_decode($settings['available_models'] ?? '[]', true) ?: [];
        $enabledModels = json_decode($settings['enabled_models'] ?? '[]', true) ?: [];

        // Build dropdown: enabled models from available, or defaults
        if (!empty($enabledModels) && !empty($availableModels)) {
            $enabledSet = array_flip($enabledModels);
            $dropdownModels = array_filter($availableModels, fn($m) => isset($enabledSet[$m['id']]));
            $dropdownModels = array_values($dropdownModels);
        } else {
            $dropdownModels = self::DEFAULT_MODELS;
        }

        // Load design system file variables for theme editing
        $designSystemFile = $settings['design_system_file'] ?? 'litecms-system.pen';
        $designsDir = dirname(__DIR__, 2) . '/designs';
        $designSystemPath = $designsDir . '/' . $designSystemFile;
        $designVars = [];
        if (file_exists($designSystemPath)) {
            $designVars = PenConverter::extractVariables($designSystemPath);
        }

        // Load list of .pen files for file selector
        $designFiles = [];
        if (is_dir($designsDir)) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($designsDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $fi) {
                if ($fi->getExtension() !== 'pen') continue;
                $rel = str_replace($designsDir . DIRECTORY_SEPARATOR, '', $fi->getPathname());
                $rel = str_replace('\\', '/', $rel);
                $designFiles[] = $rel;
            }
            sort($designFiles);
        }

        // Load current variable overrides from settings
        $varOverrides = json_decode($settings['design_variable_overrides'] ?? '{}', true) ?: [];

        $html = $this->app->template()->render('admin/settings', [
            'title'            => 'Settings',
            'activeNav'        => 'settings',
            'settings'         => $settings,
            'hasApiKey'        => $hasApiKey,
            'hasGoogleApiKey'  => $hasGoogleApiKey,
            'claudeModel'      => $settings['claude_model']
                ?? Config::getString('claude_model', 'claude-sonnet-4-20250514'),
            'dropdownModels'   => $dropdownModels,
            'availableModels'  => $availableModels,
            'enabledModels'    => $enabledModels,
            'timezones'        => self::getTimezoneList(),
            'currentTimezone'  => $settings['timezone'] ?? Config::getString('timezone', 'UTC'),
            'designSystemFile' => $designSystemFile,
            'designVars'       => $designVars,
            'designFiles'      => $designFiles,
            'varOverrides'     => $varOverrides,
            'defaultTheme'     => $settings['default_theme_mode'] ?? 'light',
            'themeToggleEnabled' => ($settings['theme_toggle_enabled'] ?? '1') === '1',
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * PUT /admin/settings — Save settings.
     */
    public function update(Request $request): Response
    {
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Only administrators can access settings.');
            return Response::redirect('/admin/dashboard');
        }

        // --- AI Settings (unchanged from 4.1) ---
        $newApiKey = trim((string) $request->input('claude_api_key', ''));
        if ($newApiKey !== '') {
            $encrypted = AIController::encrypt($newApiKey);
            $this->saveSetting('claude_api_key', $encrypted);
        }

        $newGoogleKey = trim((string) $request->input('google_api_key', ''));
        if ($newGoogleKey !== '') {
            $encrypted = AIController::encrypt($newGoogleKey);
            $this->saveSetting('google_api_key', $encrypted);
        }

        $model = trim((string) $request->input('claude_model', ''));
        if ($model !== '') {
            $this->saveSetting('claude_model', $model);
        }

        $aiMaxTokens = $request->input('ai_max_tokens');
        if ($aiMaxTokens !== null && $aiMaxTokens !== '') {
            $this->saveSetting('ai_max_tokens', (string) max(1, min(128000, (int) $aiMaxTokens)));
        }

        $aiTimeout = $request->input('ai_timeout');
        if ($aiTimeout !== null && $aiTimeout !== '') {
            $this->saveSetting('ai_timeout', (string) max(10, min(600, (int) $aiTimeout)));
        }

        $aiTemperature = $request->input('ai_temperature');
        if ($aiTemperature !== null && $aiTemperature !== '') {
            $this->saveSetting('ai_temperature', (string) max(0.0, min(1.0, round((float) $aiTemperature, 2))));
        }

        // --- General Settings ---
        $siteName = trim((string) $request->input('site_name', ''));
        if ($siteName !== '') {
            $this->saveSetting('site_name', $siteName);
        }

        $siteUrl = trim((string) $request->input('site_url', ''));
        if ($siteUrl === '' || filter_var($siteUrl, FILTER_VALIDATE_URL) !== false) {
            $this->saveSetting('site_url', rtrim($siteUrl, '/'));
        }

        $tagline = trim((string) $request->input('site_tagline', ''));
        $this->saveSetting('site_tagline', $tagline);

        $timezone = trim((string) $request->input('timezone', ''));
        if ($timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $this->saveSetting('timezone', $timezone);
        }

        $itemsPerPage = $request->input('items_per_page');
        if ($itemsPerPage !== null && $itemsPerPage !== '') {
            $this->saveSetting('items_per_page', (string) max(1, min(100, (int) $itemsPerPage)));
        }

        // --- SEO Settings ---
        $metaDesc = trim((string) $request->input('default_meta_description', ''));
        $this->saveSetting('default_meta_description', mb_substr($metaDesc, 0, 300));

        $ogImage = trim((string) $request->input('og_default_image', ''));
        $this->saveSetting('og_default_image', $ogImage);

        // --- Cookie Consent & Analytics ---
        $this->saveCheckbox($request, 'cookie_consent_enabled');

        $consentText = trim((string) $request->input('cookie_consent_text', ''));
        $this->saveSetting('cookie_consent_text', $consentText);

        $consentLink = trim((string) $request->input('cookie_consent_link', ''));
        $this->saveSetting('cookie_consent_link', $consentLink);

        $this->saveCheckbox($request, 'ga_enabled');

        $gaId = trim((string) $request->input('ga_measurement_id', ''));
        if ($gaId === '' || preg_match('/^G-[A-Z0-9]+$/', $gaId)) {
            $this->saveSetting('ga_measurement_id', $gaId);
        }

        // --- Contact Form ---
        $contactEmail = trim((string) $request->input('contact_notification_email', ''));
        if ($contactEmail === '' || filter_var($contactEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $this->saveSetting('contact_notification_email', $contactEmail);
        }

        // --- Media & Image Settings ---
        $maxUploadSize = $request->input('max_upload_size');
        if ($maxUploadSize !== null && $maxUploadSize !== '') {
            $this->saveSetting('max_upload_size', (string) max(102400, min(104857600, (int) $maxUploadSize)));
        }

        $imageResizeThreshold = $request->input('image_resize_threshold');
        if ($imageResizeThreshold !== null && $imageResizeThreshold !== '') {
            $this->saveSetting('image_resize_threshold', (string) max(102400, min(104857600, (int) $imageResizeThreshold)));
        }

        $imageMaxDimension = $request->input('image_max_dimension');
        if ($imageMaxDimension !== null && $imageMaxDimension !== '') {
            $this->saveSetting('image_max_dimension', (string) max(100, min(10000, (int) $imageMaxDimension)));
        }

        $jpegQuality = $request->input('image_jpeg_quality');
        if ($jpegQuality !== null && $jpegQuality !== '') {
            $this->saveSetting('image_jpeg_quality', (string) max(10, min(100, (int) $jpegQuality)));
        }

        // --- Design System ---
        $dsFile = trim((string) $request->input('design_system_file', ''));
        if ($dsFile !== '') {
            $dsPath = dirname(__DIR__, 2) . '/designs/' . $dsFile;
            if (file_exists($dsPath)) {
                $this->saveSetting('design_system_file', $dsFile);
            }
        }

        $defaultTheme = trim((string) $request->input('default_theme_mode', 'light'));
        if (in_array($defaultTheme, ['light', 'dark'], true)) {
            $this->saveSetting('default_theme_mode', $defaultTheme);
        }

        $this->saveCheckbox($request, 'theme_toggle_enabled');

        // Variable overrides — collect from form fields named var_override[varname]
        $varOverrideRaw = $request->input('var_override');
        $varOverrides = [];
        if (is_array($varOverrideRaw)) {
            foreach ($varOverrideRaw as $name => $val) {
                $name = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $name);
                $val = trim((string) $val);
                if ($name !== '' && $val !== '') {
                    $val = str_replace([';', '{', '}', '<', '>', 'javascript:', '@import'], '', $val);
                    $varOverrides[$name] = $val;
                }
            }
        }
        $this->saveSetting('design_variable_overrides', json_encode($varOverrides));

        // --- Advanced ---
        $this->saveCheckbox($request, 'registration_enabled');
        $this->saveCheckbox($request, 'maintenance_mode');

        // Clear cached config so next request picks up new values
        Config::reset();

        Session::flash('success', 'Settings saved successfully.');
        return Response::redirect('/admin/settings');
    }

    /**
     * Load all settings from the database as a key-value array.
     */
    private function loadSettings(): array
    {
        $rows = QueryBuilder::query('settings')->select('key', 'value')->get();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    /**
     * Save a single setting (upsert: insert if new, update if exists).
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
     * Save a checkbox field value ("1" if checked, "0" if unchecked).
     */
    private function saveCheckbox(Request $request, string $field): void
    {
        $value = $request->input($field);
        $this->saveSetting($field, ($value !== null && $value !== '' && $value !== '0') ? '1' : '0');
    }

    /**
     * Get timezone list grouped by continent for <select> optgroups.
     */
    private static function getTimezoneList(): array
    {
        $grouped = [];
        foreach (\DateTimeZone::listIdentifiers() as $tz) {
            $parts = explode('/', $tz, 2);
            $group = count($parts) > 1 ? $parts[0] : 'Other';
            $grouped[$group][] = $tz;
        }
        ksort($grouped);
        return $grouped;
    }

    /**
     * Add standard security headers to admin responses.
     */
    private function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self' https://cdn.jsdelivr.net; "
                . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self'; "
                . "font-src 'self' https://cdn.jsdelivr.net"
            );
    }
}
