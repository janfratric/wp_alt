# Chunk 5.2 — Settings Panel & Site Configuration
## Detailed Implementation Plan

---

## Overview

This chunk expands the existing Settings panel (partially built in Chunk 4.1 for AI configuration) into a comprehensive site configuration interface. It adds General, SEO, Cookie Consent & Analytics, Contact Form, and Advanced settings sections. Critically, it also updates `Config.php` so that database settings override file-based config values, making all settings take effect immediately without editing `config/app.php`.

---

## What Already Exists (Do Not Rewrite)

These components were built in previous chunks and must be preserved:

1. **`app/Admin/SettingsController.php`** (Chunk 4.1) — Has `index()`, `update()`, `loadSettings()`, `saveSetting()`, `withSecurityHeaders()`. Handles AI settings (API key encryption, model selection, AI parameters) and site name.
2. **`templates/admin/settings.php`** (Chunk 4.1) — AI Assistant section (API key, model dropdown, model management, API parameters) and a basic General section (site name only). Includes inline CSS.
3. **`app/Core/Config.php`** (Chunk 1.1) — Static config reader. Loads `config/app.php` only. No DB awareness.
4. **`templates/public/layout.php`** (Chunk 3.2) — Already has `data-ga-id` attribute, cookie consent partial inclusion, and `cookie-consent.js` load.
5. **`templates/public/partials/cookie-consent.php`** (Chunk 3.2) — Cookie consent banner with Accept/Decline buttons.
6. **`public/assets/js/cookie-consent.js`** (Chunk 3.2) — Cookie consent logic, GA loading based on `data-ga-id`.
7. **`app/Templates/FrontController.php`** (Chunk 3.2) — `getPublicSettings()` already fetches `site_tagline`, `cookie_consent_text`, `cookie_consent_link`, `ga_enabled`, `ga_measurement_id` from the DB and passes them to public templates via `renderPublic()`.
8. **Settings route** in `public/index.php` — `GET /admin/settings` and `PUT /admin/settings` already registered.
9. **Settings sidebar link** in `templates/admin/layout.php` — Already present with `activeNav` support.

---

## File Modification Order

Files are listed in dependency order. Earlier files have no dependencies on later ones.

---

### 1. Update `app/Core/Config.php`

**Purpose**: Add database settings overlay so DB values override file-based config. This is the critical architectural change — all existing `Config::get()` calls throughout the codebase will automatically pick up DB settings without any caller changes.

**Current state** (42 lines): Loads `config/app.php` once, returns values via static getters.

**Changes**:
- Add a `private static ?array $dbSettings = null` property.
- Add a `public static function loadDbSettings(): void` method that queries the `settings` table and merges results over file config.
- Modify `load()` to remain file-only (unchanged).
- Add `loadDbOverrides()` as a separate step called from `App::__construct()` after the database is available.
- The `get()` method checks `$dbSettings` first (if loaded), then falls back to file config.
- Add a `public static function reset(): void` method to clear cached state (useful for tests).
- **Important safety**: DB settings must NOT override database connection values (`db_driver`, `db_path`, `db_host`, `db_port`, `db_name`, `db_user`, `db_pass`, `app_secret`) — those must always come from the file config to avoid chicken-and-egg problems.

**Updated class specification**:

```
PROPERTIES:
  - private static ?array $config = null      // File-based config
  - private static ?array $dbSettings = null  // DB settings overlay
  - private static array $protectedKeys = [   // Keys that cannot be overridden by DB
      'db_driver', 'db_path', 'db_host', 'db_port',
      'db_name', 'db_user', 'db_pass', 'app_secret'
    ]

METHODS (unchanged):
  - private static load(): void
      Requires config/app.php, stores in self::$config. (No changes.)

  - public static getString(string $key, string $default = ''): string
  - public static getInt(string $key, int $default = 0): int
  - public static getBool(string $key, bool $default = false): bool
  - public static all(): array

METHODS (modified):
  - public static get(string $key, mixed $default = null): mixed
      1. self::load()
      2. If self::$dbSettings !== null AND $key is not in $protectedKeys:
           if isset(self::$dbSettings[$key]): return self::$dbSettings[$key]
      3. return self::$config[$key] ?? $default

METHODS (new):
  - public static loadDbSettings(): void
      1. Try to query settings table: SELECT key, value FROM settings
      2. Store results as key => value in self::$dbSettings
      3. On any exception (e.g. table doesn't exist yet), set self::$dbSettings = []
      4. Must NOT use Config::get() for DB connection (circular!) — uses Connection directly

  - public static reset(): void
      self::$config = null
      self::$dbSettings = null
```

**Code template**:

```php
<?php declare(strict_types=1);

namespace App\Core;

use App\Database\Connection;

class Config
{
    private static ?array $config = null;
    private static ?array $dbSettings = null;

    /** Keys that must always come from the file config (not DB). */
    private static array $protectedKeys = [
        'db_driver', 'db_path', 'db_host', 'db_port',
        'db_name', 'db_user', 'db_pass', 'app_secret',
    ];

    private static function load(): void
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../../config/app.php';
        }
    }

    /**
     * Load settings from the database to override file config.
     * Called once during App bootstrap, after DB connection is available.
     * Silently skips if DB is not ready (e.g., first run before migrations).
     */
    public static function loadDbSettings(): void
    {
        if (self::$dbSettings !== null) {
            return;
        }

        try {
            $pdo = Connection::getInstance();
            $stmt = $pdo->query('SELECT key, value FROM settings');
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            self::$dbSettings = [];
            foreach ($rows as $row) {
                self::$dbSettings[$row['key']] = $row['value'];
            }
        } catch (\Throwable $e) {
            // DB not ready (no table, no connection, etc.) — skip silently
            self::$dbSettings = [];
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        // DB settings override file config (except protected keys)
        if (self::$dbSettings !== null
            && !in_array($key, self::$protectedKeys, true)
            && array_key_exists($key, self::$dbSettings)) {
            return self::$dbSettings[$key];
        }

        return self::$config[$key] ?? $default;
    }

    public static function getString(string $key, string $default = ''): string
    {
        return (string) self::get($key, $default);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        return (bool) self::get($key, $default);
    }

    public static function all(): array
    {
        self::load();
        $merged = self::$config;

        if (self::$dbSettings !== null) {
            foreach (self::$dbSettings as $key => $value) {
                if (!in_array($key, self::$protectedKeys, true)) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * Reset cached state. Used primarily in tests.
     */
    public static function reset(): void
    {
        self::$config = null;
        self::$dbSettings = null;
    }
}
```

---

### 2. Update `app/Core/App.php`

**Purpose**: Call `Config::loadDbSettings()` after database connection is established during bootstrap.

**Current state**: Constructor sets timezone, creates Router and TemplateEngine.

**Change**: Add one line after the timezone is set. The DB connection is established lazily by `Connection::getInstance()` (which is already called during migrations in the bootstrap flow), so by the time routes are dispatched the DB is ready. We add the call early so all subsequent `Config::get()` calls pick up DB values.

**Specific edit**: After `date_default_timezone_set(...)`, add:

```php
// Load DB settings to override file config
Config::loadDbSettings();

// Re-apply timezone in case it was overridden by a DB setting
$dbTimezone = Config::getString('timezone', 'UTC');
if ($dbTimezone !== 'UTC') {
    date_default_timezone_set($dbTimezone);
}
```

**Why**: The first `date_default_timezone_set()` uses file config. After loading DB settings, the timezone may have been changed by admin, so we re-apply it. This ensures timezone changes from the settings panel take effect immediately.

---

### 3. Update `app/Admin/SettingsController.php`

**Purpose**: Expand `index()` to pass all settings to the template, and expand `update()` to handle all new settings fields with validation.

**Current state** (168 lines): Handles AI settings + site name only.

**Changes to `index()`**:
- Load settings as before.
- Pass additional template variables for all new settings sections.
- Load timezone list for the timezone dropdown.

**Changes to `update()`**:
- Add handling for all new settings fields with validation:
  - **General**: `site_url` (URL validation), `site_tagline` (string), `timezone` (validate against `DateTimeZone::listIdentifiers()`), `items_per_page` (int, 1-100)
  - **SEO**: `default_meta_description` (string, max 300 chars), `og_default_image` (string URL)
  - **Cookie Consent & Analytics**: `cookie_consent_enabled` (checkbox → "1"/"0"), `cookie_consent_text` (string), `cookie_consent_link` (string URL), `ga_enabled` (checkbox → "1"/"0"), `ga_measurement_id` (validate G-XXXXXXXXXX format)
  - **Contact Form**: `contact_notification_email` (email validation or empty)
  - **Advanced**: `registration_enabled` (checkbox → "1"/"0"), `maintenance_mode` (checkbox → "1"/"0")
- After saving, call `Config::reset()` so the next request picks up fresh values.

**New method `getTimezoneList()`**: Returns a grouped list of common timezones.

**Updated class specification**:

```
CLASS: App\Admin\SettingsController

PROPERTIES:
  - private const DEFAULT_MODELS = [...]   // (unchanged)
  - private App $app

CONSTRUCTOR:
  __construct(App $app)                     // (unchanged)

METHODS (modified):
  - index(Request $request): Response
      1. Admin role check (unchanged)
      2. $settings = $this->loadSettings()
      3. Build template data (expanded):
         - title, activeNav, settings, hasApiKey, claudeModel, dropdownModels, availableModels, enabledModels (all unchanged)
         - timezones: self::getTimezoneList()
         - currentTimezone: $settings['timezone'] ?? Config::getString('timezone', 'UTC')
         - currentItemsPerPage: $settings['items_per_page'] ?? Config::getString('items_per_page', '10')
         - currentSiteUrl: $settings['site_url'] ?? Config::getString('site_url', 'http://localhost')
      4. Render 'admin/settings' and return with security headers

  - update(Request $request): Response
      1. Admin role check (unchanged)
      2. Handle AI settings (unchanged — API key, model, AI params)
      3. Handle General settings:
         - site_name (unchanged)
         - site_url: trim, validate URL format (filter_var FILTER_VALIDATE_URL or allow empty), save
         - site_tagline: trim, save (allow empty to clear)
         - timezone: validate in_array(DateTimeZone::listIdentifiers()), save
         - items_per_page: clamp to int 1..100, save
      4. Handle SEO settings:
         - default_meta_description: trim, max 300 chars via substr, save
         - og_default_image: trim, save (allow empty)
      5. Handle Cookie Consent & Analytics:
         - cookie_consent_enabled: checkbox — save "1" if present, "0" if absent
         - cookie_consent_text: trim, save
         - cookie_consent_link: trim, save
         - ga_enabled: checkbox — save "1" if present, "0" if absent
         - ga_measurement_id: trim, validate /^G-[A-Z0-9]+$/ or allow empty, save
      6. Handle Contact Form:
         - contact_notification_email: trim, validate email format (or allow empty to disable), save
      7. Handle Advanced:
         - registration_enabled: checkbox — save "1" if present, "0" if absent
         - maintenance_mode: checkbox — save "1" if present, "0" if absent
      8. Config::reset() — clear cached config so next request sees updated values
      9. Flash success, redirect to /admin/settings

METHODS (unchanged):
  - private loadSettings(): array
  - private saveSetting(string $key, string $value): void
  - private withSecurityHeaders(Response $response): Response

METHODS (new):
  - private static getTimezoneList(): array
      Returns array of timezone groups:
      [
        'UTC' => ['UTC'],
        'America' => ['America/New_York', 'America/Chicago', ...],
        'Europe'  => ['Europe/London', 'Europe/Paris', ...],
        ...
      ]
      Uses DateTimeZone::listIdentifiers() grouped by continent prefix.

  - private saveCheckbox(Request $request, string $field): void
      Helper: reads input, saves "1" if present and truthy, "0" otherwise.
      Simplifies checkbox handling (unchecked checkboxes don't appear in POST data).
```

**Code template for the new/modified methods** (showing only new logic; AI handling stays unchanged):

```php
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
    // Allow empty (to revert to config file default) or valid URL
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

    // --- Advanced ---
    $this->saveCheckbox($request, 'registration_enabled');
    $this->saveCheckbox($request, 'maintenance_mode');

    // Clear cached config so next request picks up new values
    Config::reset();

    Session::flash('success', 'Settings saved successfully.');
    return Response::redirect('/admin/settings');
}

private function saveCheckbox(Request $request, string $field): void
{
    $value = $request->input($field);
    $this->saveSetting($field, ($value !== null && $value !== '' && $value !== '0') ? '1' : '0');
}

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
```

---

### 4. Update `templates/admin/settings.php`

**Purpose**: Expand the settings form to include all sections: General (expanded), SEO, Cookie Consent & Analytics, Contact Form, and Advanced. Preserve existing AI Assistant section.

**Current state** (264 lines): AI Assistant section + minimal General section (site name only) + inline CSS.

**Strategy**: Keep the AI Assistant section at the top (unchanged). Expand General. Add new sections below. Extend the inline CSS for new form elements.

**Section order in the form**:
1. General (expanded: site name, site URL, tagline, timezone, items per page)
2. SEO (default meta description, OG default image)
3. Cookie Consent & Analytics (enable consent, consent text, consent link, enable GA, GA measurement ID)
4. Contact Form (notification email)
5. AI Assistant (existing — unchanged)
6. Advanced (enable registration, maintenance mode)

**Full template specification**:

```php
<?php $this->layout('admin/layout'); ?>

<div class="page-header">
    <h1>Settings</h1>
</div>

<form method="POST" action="/admin/settings" class="settings-form">
    <?= $this->csrfField() ?>
    <input type="hidden" name="_method" value="PUT">

    <!-- ============================================= -->
    <!-- General Section (expanded)                    -->
    <!-- ============================================= -->
    <div class="settings-section">
        <h2>General</h2>
        <p class="section-desc">Basic site configuration.</p>

        <div class="form-group">
            <label for="site_name">Site Name</label>
            <input type="text"
                   id="site_name"
                   name="site_name"
                   value="<?= $this->e($settings['site_name'] ?? \App\Core\Config::getString('site_name', 'LiteCMS')) ?>">
            <small>The name of your website, shown in titles and navigation.</small>
        </div>

        <div class="form-group">
            <label for="site_url">Site URL</label>
            <input type="url"
                   id="site_url"
                   name="site_url"
                   value="<?= $this->e($settings['site_url'] ?? \App\Core\Config::getString('site_url', 'http://localhost')) ?>"
                   placeholder="https://example.com">
            <small>The full URL of your website (no trailing slash). Used for canonical URLs and Open Graph tags.</small>
        </div>

        <div class="form-group">
            <label for="site_tagline">Tagline</label>
            <input type="text"
                   id="site_tagline"
                   name="site_tagline"
                   value="<?= $this->e($settings['site_tagline'] ?? '') ?>"
                   placeholder="A short description of your site">
            <small>A brief tagline or slogan for your site.</small>
        </div>

        <div class="form-group">
            <label for="timezone">Timezone</label>
            <select id="timezone" name="timezone">
                <?php foreach ($timezones as $group => $tzList): ?>
                    <optgroup label="<?= $this->e($group) ?>">
                        <?php foreach ($tzList as $tz): ?>
                            <option value="<?= $this->e($tz) ?>"
                                <?= ($currentTimezone === $tz) ? 'selected' : '' ?>>
                                <?= $this->e($tz) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <small>Timezone used for displaying dates on the public site.</small>
        </div>

        <div class="form-group">
            <label for="items_per_page">Items Per Page</label>
            <input type="number"
                   id="items_per_page"
                   name="items_per_page"
                   value="<?= (int)($settings['items_per_page'] ?? \App\Core\Config::getInt('items_per_page', 10)) ?>"
                   min="1" max="100">
            <small>Number of items shown per page in listings (blog, admin content list, etc.).</small>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- SEO Section                                   -->
    <!-- ============================================= -->
    <div class="settings-section">
        <h2>SEO</h2>
        <p class="section-desc">Search engine optimization defaults. Individual content items can override these.</p>

        <div class="form-group">
            <label for="default_meta_description">Default Meta Description</label>
            <textarea id="default_meta_description"
                      name="default_meta_description"
                      rows="3"
                      maxlength="300"
                      placeholder="A brief description of your website for search engines..."
            ><?= $this->e($settings['default_meta_description'] ?? '') ?></textarea>
            <small>Used when individual pages don't have their own meta description. Max 300 characters.</small>
        </div>

        <div class="form-group">
            <label for="og_default_image">Default Open Graph Image</label>
            <input type="text"
                   id="og_default_image"
                   name="og_default_image"
                   value="<?= $this->e($settings['og_default_image'] ?? '') ?>"
                   placeholder="/assets/uploads/og-image.jpg">
            <small>Default image shown when pages are shared on social media. Use a path like <code>/assets/uploads/filename.jpg</code>.</small>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- Cookie Consent & Analytics Section            -->
    <!-- ============================================= -->
    <div class="settings-section">
        <h2>Cookie Consent &amp; Analytics</h2>
        <p class="section-desc">GDPR-compliant cookie consent banner and Google Analytics integration.</p>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="cookie_consent_enabled" value="0">
                <input type="checkbox"
                       name="cookie_consent_enabled"
                       value="1"
                       <?= ($settings['cookie_consent_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                Enable cookie consent banner
            </label>
            <small>Show a consent banner to first-time visitors. Recommended for EU compliance.</small>
        </div>

        <div class="form-group">
            <label for="cookie_consent_text">Consent Banner Text</label>
            <textarea id="cookie_consent_text"
                      name="cookie_consent_text"
                      rows="2"
                      placeholder="This website uses cookies to improve your experience..."
            ><?= $this->e($settings['cookie_consent_text'] ?? '') ?></textarea>
            <small>Custom message shown in the cookie consent banner.</small>
        </div>

        <div class="form-group">
            <label for="cookie_consent_link">Privacy Policy Link</label>
            <input type="text"
                   id="cookie_consent_link"
                   name="cookie_consent_link"
                   value="<?= $this->e($settings['cookie_consent_link'] ?? '') ?>"
                   placeholder="/privacy-policy">
            <small>Link to your privacy policy page. Shown as "Learn more" in the consent banner.</small>
        </div>

        <hr style="margin: 1.5rem 0; border-color: var(--border-color, #dee2e6);">

        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="ga_enabled" value="0">
                <input type="checkbox"
                       name="ga_enabled"
                       value="1"
                       <?= ($settings['ga_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                Enable Google Analytics
            </label>
            <small>When enabled, Google Analytics tracking code is loaded after cookie consent is accepted.</small>
        </div>

        <div class="form-group">
            <label for="ga_measurement_id">GA Measurement ID</label>
            <input type="text"
                   id="ga_measurement_id"
                   name="ga_measurement_id"
                   value="<?= $this->e($settings['ga_measurement_id'] ?? '') ?>"
                   placeholder="G-XXXXXXXXXX"
                   pattern="G-[A-Za-z0-9]+"
                   title="Must start with G- followed by alphanumeric characters">
            <small>Your Google Analytics 4 Measurement ID (e.g., <code>G-XXXXXXXXXX</code>). Found in GA Admin &gt; Data Streams.</small>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- Contact Form Section                          -->
    <!-- ============================================= -->
    <div class="settings-section">
        <h2>Contact Form</h2>
        <p class="section-desc">Configure how contact form submissions are handled.</p>

        <div class="form-group">
            <label for="contact_notification_email">Notification Email</label>
            <input type="email"
                   id="contact_notification_email"
                   name="contact_notification_email"
                   value="<?= $this->e($settings['contact_notification_email'] ?? '') ?>"
                   placeholder="admin@example.com">
            <small>When set, new contact form submissions will trigger an email notification to this address. Leave blank to disable email notifications (submissions are still saved to the database).</small>
        </div>
    </div>

    <!-- ============================================= -->
    <!-- AI Assistant Section (PRESERVED FROM 4.1)     -->
    <!-- ============================================= -->
    <div class="settings-section">
        <h2>AI Assistant</h2>
        <p class="section-desc">Configure your Claude API integration for the AI writing assistant.</p>

        <!-- ... existing AI section content unchanged ... -->
        <!-- (API key, model dropdown, model management, API parameters) -->
    </div>

    <!-- ============================================= -->
    <!-- Advanced Section                              -->
    <!-- ============================================= -->
    <div class="settings-section">
        <h2>Advanced</h2>
        <p class="section-desc">Advanced configuration options. Change these only if you know what you're doing.</p>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="registration_enabled" value="0">
                <input type="checkbox"
                       name="registration_enabled"
                       value="1"
                       <?= ($settings['registration_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                Enable user registration
            </label>
            <small>Allow new users to register accounts. When disabled, only admins can create user accounts.</small>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="hidden" name="maintenance_mode" value="0">
                <input type="checkbox"
                       name="maintenance_mode"
                       value="1"
                       <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                Maintenance mode
            </label>
            <small>When enabled, the public site shows a maintenance page. Admin panel remains accessible.</small>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
</form>

<!-- CSS extends existing styles with new additions -->
<style>
/* ... existing CSS preserved ... */
/* New additions: */
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 500;
    cursor: pointer;
}
.checkbox-label input[type="checkbox"] {
    width: auto;
    margin: 0;
}
.settings-section textarea {
    width: 100%;
    resize: vertical;
}
</style>
```

**Implementation note**: The AI Assistant section block is preserved exactly as-is from the existing template. The General section replaces the existing minimal one. New sections are added between General and the existing AI section.

---

### 5. Update `app/Templates/FrontController.php`

**Purpose**: Update `getPublicSettings()` to also fetch the new `cookie_consent_enabled` setting, and conditionally control whether the consent banner is shown. Update `renderPublic()` to pass `consentEnabled` to templates.

**Current state**: `getPublicSettings()` fetches 5 settings. `renderPublic()` always passes consent text/link.

**Changes**:

1. In `getPublicSettings()` — add `cookie_consent_enabled` to the IN clause:
```php
->whereRaw("key IN (:k1, :k2, :k3, :k4, :k5, :k6)", [
    ':k1' => 'site_tagline',
    ':k2' => 'cookie_consent_text',
    ':k3' => 'cookie_consent_link',
    ':k4' => 'ga_enabled',
    ':k5' => 'ga_measurement_id',
    ':k6' => 'cookie_consent_enabled',
])
```

2. In `renderPublic()` — conditionally pass consent data:
```php
$consentEnabled = ($settings['cookie_consent_enabled'] ?? '1') === '1';
// ...
'consentText' => $consentEnabled ? ($settings['cookie_consent_text'] ?? '') : '',
'consentLink' => $consentEnabled ? ($settings['cookie_consent_link'] ?? '') : '',
'consentEnabled' => $consentEnabled,
```

3. Also add `contact_notification_email` to the fetched keys (for chunk 5.4 contact email feature to reference, but we fetch it now for consistency):
```php
':k7' => 'contact_notification_email',
```

---

### 6. Update `templates/public/layout.php`

**Purpose**: Conditionally include cookie consent partial based on `consentEnabled` flag.

**Current state**: Always includes cookie consent partial and JS.

**Change**: Wrap the consent partial inclusion in a conditional:

```php
<?php if ($consentEnabled ?? true): ?>
<?= $this->partial('public/partials/cookie-consent', [
    'consentText' => $consentText ?? '',
    'consentLink' => $consentLink ?? '',
]) ?>
    <script src="/assets/js/cookie-consent.js"></script>
<?php endif; ?>
```

This is a small, targeted change — only adds the `if` wrapper.

---

## Files NOT Modified

The following files are intentionally left unchanged:
- `config/app.php` — File-based defaults remain as-is. DB settings override them.
- `public/index.php` — Routes are already registered.
- `templates/admin/layout.php` — Settings nav link already exists.
- `public/assets/js/cookie-consent.js` — Already handles GA loading correctly.
- `templates/public/partials/cookie-consent.php` — Already renders correctly.
- `app/Database/Connection.php` — No changes needed.

---

## Acceptance Test Procedures

### Test 1: Change site name in settings — public site reflects it immediately
```
1. Log in as admin, go to /admin/settings.
2. Change "Site Name" to "My Business Site".
3. Click "Save Settings".
4. Visit the public homepage.
5. Verify: page title and header show "My Business Site" (not "LiteCMS").
6. Verify: no change needed to config/app.php — the DB value takes priority.
```

### Test 2: Change items_per_page — pagination adjusts
```
1. Ensure at least 6 blog posts exist and are published.
2. Go to settings, change "Items Per Page" to 5.
3. Save settings.
4. Visit /blog.
5. Verify: only 5 posts shown on page 1, pagination shows page 2.
6. Change back to 10 — all 6 posts shown on single page.
```

### Test 3: API key shows masked value
```
1. Go to settings with an API key already configured.
2. Verify: the API key input field shows placeholder "Leave blank to keep current key".
3. Verify: the actual key is NOT in the page source (inspect HTML).
4. Verify: green status shows "API key is configured (stored encrypted)".
```

### Test 4: Timezone setting affects displayed dates
```
1. Set timezone to "America/New_York" in settings.
2. Save.
3. Visit a blog post with a publish date.
4. Verify: date/time reflects Eastern timezone.
5. Change to "Europe/London", verify date changes.
```

### Test 5: Settings persist across restarts (DB, not session)
```
1. Change site name and items per page.
2. Clear browser session / log out / log in again.
3. Go to settings page.
4. Verify: changed values are still shown (loaded from DB).
5. Visit public site — changed site name is displayed.
```

### Test 6: Enable GA + enter measurement ID — GA script appears after consent
```
1. Go to settings.
2. Check "Enable Google Analytics".
3. Enter "G-TEST12345" as Measurement ID.
4. Save.
5. Open an incognito window, visit the public site.
6. View page source: verify `data-ga-id="G-TEST12345"` on body tag.
7. Verify: no gtag.js script loaded yet (consent not given).
8. Click "Accept" on cookie banner.
9. Verify: gtag.js script now loaded (check network tab or DOM).
```

### Test 7: Disable GA toggle — GA script not injected regardless
```
1. Go to settings.
2. Uncheck "Enable Google Analytics" (leave Measurement ID filled).
3. Save.
4. Visit public site.
5. Verify: no `data-ga-id` attribute on body, no GA script.
```

### Test 8: Change cookie consent banner text — updated on public site
```
1. Go to settings.
2. Change consent text to "We use cookies for analytics. Accept?"
3. Save.
4. Open incognito window, visit public site.
5. Verify: banner shows custom text "We use cookies for analytics. Accept?"
```

### Test 9: Contact notification email validates and saves
```
1. Go to settings.
2. Enter "admin@example.com" in contact notification email.
3. Save — verify success message.
4. Reload settings page — value persists.
5. Enter "not-an-email" — save.
6. Verify: invalid value is NOT saved (old value remains, or field stays as-is).
7. Clear the field and save — verify empty is accepted (disables email).
```

---

## Implementation Notes

### Checkbox Handling
HTML forms don't send unchecked checkboxes. We use the hidden input pattern:
```html
<input type="hidden" name="field" value="0">
<input type="checkbox" name="field" value="1">
```
When checked, the checkbox value "1" overrides the hidden "0". When unchecked, only the hidden "0" is sent.

### Config Override Safety
Database settings MUST NOT override these keys (they're needed to connect to the DB in the first place):
- `db_driver`, `db_path`, `db_host`, `db_port`, `db_name`, `db_user`, `db_pass`
- `app_secret` (used for encryption; changing it would invalidate existing encrypted data)

### Settings That Already Flow Through
The FrontController already fetches `site_tagline`, `cookie_consent_text`, `cookie_consent_link`, `ga_enabled`, `ga_measurement_id` from DB and passes them to public templates. After this chunk, the admin can actually set these values through the UI.

### Backward Compatibility
- If no settings are in the DB, `Config::get()` falls back to file config values — exactly as before.
- Existing code that calls `Config::getInt('items_per_page', 10)` will now transparently get the DB value if one is set, otherwise the file config value.
- This is why the `protectedKeys` safeguard is critical — prevents DB corruption from breaking the app.

### CSS Approach
All new styles are added via the existing inline `<style>` block in the settings template (matching the pattern established in Chunk 4.1). No separate CSS file changes needed.

### Validation Summary

| Setting | Type | Validation |
|---------|------|------------|
| `site_name` | string | Non-empty |
| `site_url` | string | Empty or `filter_var(FILTER_VALIDATE_URL)` |
| `site_tagline` | string | Any (allow empty) |
| `timezone` | string | Must be in `DateTimeZone::listIdentifiers()` |
| `items_per_page` | int | 1–100 |
| `default_meta_description` | string | Max 300 chars |
| `og_default_image` | string | Any (allow empty) |
| `cookie_consent_enabled` | bool | Checkbox → "1"/"0" |
| `cookie_consent_text` | string | Any (allow empty) |
| `cookie_consent_link` | string | Any (allow empty) |
| `ga_enabled` | bool | Checkbox → "1"/"0" |
| `ga_measurement_id` | string | Empty or `/^G-[A-Z0-9]+$/` |
| `contact_notification_email` | string | Empty or `filter_var(FILTER_VALIDATE_EMAIL)` |
| `registration_enabled` | bool | Checkbox → "1"/"0" |
| `maintenance_mode` | bool | Checkbox → "1"/"0" |

---

## Edge Cases

1. **First run (no settings in DB)**: `Config::loadDbSettings()` catches the exception if the settings table doesn't exist yet. All values fall back to file config. This is safe.

2. **Circular dependency**: `Config::loadDbSettings()` uses `Connection::getInstance()` directly (which internally uses `Config::getString('db_driver')` from file config). Since DB connection keys are protected and never overridden by DB settings, there's no circular dependency.

3. **Empty timezone select**: If admin submits the form without selecting a timezone, the current value is preserved (validation rejects empty).

4. **GA Measurement ID case sensitivity**: The regex pattern `G-[A-Z0-9]+` is used. Real GA4 IDs use uppercase. We enforce uppercase in the pattern attribute and server-side validation.

5. **Concurrent settings changes**: The `saveSetting()` upsert pattern handles concurrent writes safely — last write wins, which is acceptable for a settings panel.

6. **Config cache in long-running requests**: After saving settings, `Config::reset()` clears the cache. The redirect causes a new request where `Config::loadDbSettings()` runs fresh.

---

## File Checklist

| # | File | Action | Lines (est.) |
|---|------|--------|-------------|
| 1 | `app/Core/Config.php` | Rewrite | ~95 |
| 2 | `app/Core/App.php` | Edit (add ~5 lines) | ~5 added |
| 3 | `app/Admin/SettingsController.php` | Expand | ~280 total |
| 4 | `templates/admin/settings.php` | Rewrite | ~420 total |
| 5 | `app/Templates/FrontController.php` | Edit (small) | ~10 changed |
| 6 | `templates/public/layout.php` | Edit (small) | ~3 changed |

**Estimated total new/changed PHP LOC**: ~250 net new lines

---

## Dependency on Previous Chunks

| Dependency | From Chunk | What We Use |
|-----------|-----------|-------------|
| Settings DB table | 1.2 | `settings` table (key/value) |
| Config class | 1.1 | `Config::get()` and typed getters |
| Auth/Session | 1.3 | Admin role check, CSRF |
| Admin layout | 2.1 | Template layout, sidebar nav |
| SettingsController (partial) | 4.1 | AI settings handling, encryption |
| Settings template (partial) | 4.1 | AI section UI |
| Cookie consent | 3.2 | Banner template + JS |
| FrontController | 3.1/3.2 | Public settings query |
| Connection class | 1.2 | Direct PDO access for Config |
