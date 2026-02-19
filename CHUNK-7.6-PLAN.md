# Chunk 7.6 — Template System & Theme Integration
## Detailed Implementation Plan

---

## Overview

Extend the design system to site-wide theming. Allow `.pen` design files to define visual variables (colors, fonts, spacing) that are editable from the admin Settings page without opening the editor. Add light/dark theme switching on the public site driven by `.pen` design variables. Allow `.pen` files to serve as layout templates via the existing LayoutController infrastructure.

**Key Insight**: The PenConverter already generates `:root` CSS variables and themed selectors (`[data-theme-mode="dark"]`) from `.pen` document variables. The main work is:
1. A "Design System" settings section that reads variables from the active `.pen` file and lets admins override them
2. A mechanism to inject those overrides into PenConverter's CSS output
3. Theme switching on the public site (`?theme=dark`, cookie, toggle button)
4. Wiring `.pen` files as layout template options in LayoutController
5. Per-page theme override support via the content editor

---

## File Creation Order

Files are listed in dependency order — each file only depends on files listed before it.

---

### 1. `migrations/011_theme_settings.sqlite.sql`

**Purpose**: No schema changes needed. The `settings` table (key-value store) already exists and can store all theme-related settings. The `content` table already has `design_file`. We need one new column for per-page theme override.

```sql
-- Chunk 7.6: Add theme_override column to content table
ALTER TABLE content ADD COLUMN theme_override VARCHAR(50) DEFAULT NULL;
```

**Note**: `theme_override` stores a value like `dark` or `light` for per-page theme. NULL means "use site default".

---

### 2. `migrations/011_theme_settings.mysql.sql`

```sql
ALTER TABLE content ADD COLUMN theme_override VARCHAR(50) DEFAULT NULL;
```

---

### 3. `migrations/011_theme_settings.pgsql.sql`

```sql
ALTER TABLE content ADD COLUMN theme_override VARCHAR(50) DEFAULT NULL;
```

---

### 4. `public/assets/js/theme-toggle.js`

**Purpose**: Client-side theme switching. Reads theme from cookie/localStorage on page load, provides a toggle button, persists preference.

```javascript
(function() {
    'use strict';

    var STORAGE_KEY = 'litecms_theme_mode';
    var ATTR = 'data-theme-mode';

    // Get page-level override (set by server as data attribute)
    var pageOverride = document.body.getAttribute('data-theme-override') || '';

    function getStoredTheme() {
        try { return localStorage.getItem(STORAGE_KEY); } catch(e) { return null; }
    }

    function setStoredTheme(theme) {
        try { localStorage.setItem(STORAGE_KEY, theme); } catch(e) {}
        // Also set cookie for server-side access
        document.cookie = STORAGE_KEY + '=' + theme + ';path=/;max-age=31536000;SameSite=Lax';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute(ATTR, theme);
        document.body.setAttribute(ATTR, theme);
        // Update toggle button icon
        var toggles = document.querySelectorAll('.theme-toggle-btn');
        toggles.forEach(function(btn) {
            btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
            btn.innerHTML = theme === 'dark'
                ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>'
                : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
        });
    }

    function getActiveTheme() {
        // Priority: page override > stored preference > site default
        if (pageOverride) return pageOverride;
        return getStoredTheme() || (document.body.getAttribute('data-default-theme') || 'light');
    }

    // Apply theme immediately (before paint)
    var activeTheme = getActiveTheme();
    applyTheme(activeTheme);

    // Toggle handler
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.theme-toggle-btn');
        if (!btn) return;
        // Don't allow toggle if page has forced override
        if (pageOverride) return;
        var current = document.documentElement.getAttribute(ATTR) || 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        setStoredTheme(next);
    });
})();
```

**Key behaviors**:
- Runs immediately (IIFE) to prevent flash of wrong theme
- Reads page-level override from `data-theme-override` on `<body>`
- Falls back to localStorage → cookie → site default
- Sets `data-theme-mode` on both `<html>` and `<body>` (PenConverter generates selectors like `[data-theme-mode="dark"]`)
- Toggle button updates its icon (sun ↔ moon)
- Persists to both localStorage and cookie (cookie enables server-side detection)

---

### 5. Modify `app/PageBuilder/PenConverter.php`

**Purpose**: Add variable override injection so admin-set values override `.pen` file defaults.

#### 5a. New property

```php
/** @var array<string, string> Runtime variable overrides from settings */
private array $variableOverrides = [];
```

#### 5b. New public method: `setVariableOverrides(array $overrides): void`

```php
/**
 * Set runtime variable overrides (from admin settings).
 * Keys are variable names (without --), values are CSS values.
 * These override the :root defaults from the .pen file.
 */
public function setVariableOverrides(array $overrides): void
{
    $this->variableOverrides = $overrides;
}
```

#### 5c. New static method: `extractVariables(string $penPath): array`

```php
/**
 * Extract variable definitions from a .pen file.
 * Returns array of variable metadata for settings UI.
 * Format: ['varName' => ['type'=>..., 'value'=>..., 'themed'=>bool, 'themes'=>[...]]]
 */
public static function extractVariables(string $penPath): array
{
    if (!file_exists($penPath)) {
        return [];
    }

    $json = file_get_contents($penPath);
    $doc = json_decode($json, true);
    if (!$doc || !is_array($doc)) {
        return [];
    }

    $variables = $doc['variables'] ?? [];
    $result = [];

    foreach ($variables as $name => $def) {
        $type = $def['type'] ?? 'string';
        $value = $def['value'] ?? null;

        $entry = [
            'type' => $type,
            'themed' => false,
            'values' => [],
        ];

        if (is_array($value) && isset($value[0]) && is_array($value[0])) {
            // Themed variable
            $entry['themed'] = true;
            foreach ($value as $v) {
                $theme = $v['theme'] ?? [];
                $themeKey = empty($theme) ? 'default' : implode('/', array_map(
                    fn($k, $val) => "{$k}:{$val}",
                    array_keys($theme),
                    array_values($theme)
                ));
                $entry['values'][$themeKey] = $v['value'] ?? '';
            }
        } else {
            $entry['values']['default'] = $value;
        }

        $result[$name] = $entry;
    }

    return $result;
}
```

#### 5d. Modify `buildVariableCss()` — inject overrides after `:root`

After the `:root` block is built (line 262), add:

```php
// Apply overrides from settings
if (!empty($this->variableOverrides)) {
    $css .= "/* Settings overrides */\n:root {\n";
    foreach ($this->variableOverrides as $name => $val) {
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
        $safeVal = str_replace([';', '{', '}', '<', '>'], '', (string) $val);
        $css .= "  --{$safeName}: {$safeVal};\n";
    }
    $css .= "}\n";
}
```

This second `:root` block overrides the first due to CSS cascade (same specificity, later in source wins).

#### 5e. Modify static entry points to support overrides

Add optional `$overrides` parameter to `convertFile` and `convertDocument`:

```php
public static function convertFile(string $penPath, array $variableOverrides = []): array
{
    // ... existing code ...
    $instance = new self($document);
    if (!empty($variableOverrides)) {
        $instance->setVariableOverrides($variableOverrides);
    }
    return $instance->convert();
}

public static function convertDocument(array $document, array $variableOverrides = []): array
{
    $instance = new self($document);
    if (!empty($variableOverrides)) {
        $instance->setVariableOverrides($variableOverrides);
    }
    return $instance->convert();
}
```

---

### 6. Modify `app/Admin/SettingsController.php`

**Purpose**: Add "Design System" settings section.

#### 6a. Modify `index()` — load design system variables for template

After loading settings (line 39), add:

```php
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
```

Add to template render data:

```php
'designSystemFile' => $designSystemFile,
'designVars'       => $designVars,
'designFiles'      => $designFiles,
'varOverrides'     => $varOverrides,
'defaultTheme'     => $settings['default_theme_mode'] ?? 'light',
'themeToggleEnabled' => ($settings['theme_toggle_enabled'] ?? '1') === '1',
```

#### 6b. Modify `update()` — save design system settings

After the existing "Advanced" section (line ~194), add:

```php
// --- Design System ---
$dsFile = trim((string) $request->input('design_system_file', ''));
if ($dsFile !== '') {
    // Validate file exists
    $dsPath = dirname(__DIR__, 2) . '/designs/' . basename($dsFile);
    if (file_exists($dsPath) || str_contains($dsFile, '/')) {
        $dsPath = dirname(__DIR__, 2) . '/designs/' . $dsFile;
    }
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
            // Sanitize CSS value
            $val = str_replace([';', '{', '}', '<', '>', 'javascript:', '@import'], '', $val);
            $varOverrides[$name] = $val;
        }
    }
}
$this->saveSetting('design_variable_overrides', json_encode($varOverrides));
```

---

### 7. Modify `templates/admin/settings.php`

**Purpose**: Add "Design System" section between Contact Form and AI Assistant sections.

#### Insert after the Contact Form section (~line 178):

```php
<!-- Design System -->
<div class="settings-section">
    <h2>Design System</h2>

    <div class="form-group">
        <label for="design_system_file">Active Design System</label>
        <select name="design_system_file" id="design_system_file" class="form-control">
            <?php foreach ($designFiles ?? [] as $df): ?>
            <option value="<?= $this->e($df) ?>"
                <?= ($designSystemFile ?? '') === $df ? 'selected' : '' ?>>
                <?= $this->e($df) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <small>Select the .pen design system file that defines your site's visual tokens.</small>
    </div>

    <div class="form-group">
        <label for="default_theme_mode">Default Theme</label>
        <select name="default_theme_mode" id="default_theme_mode" class="form-control">
            <option value="light" <?= ($defaultTheme ?? 'light') === 'light' ? 'selected' : '' ?>>Light</option>
            <option value="dark" <?= ($defaultTheme ?? 'light') === 'dark' ? 'selected' : '' ?>>Dark</option>
        </select>
        <small>The default theme shown to visitors before they choose.</small>
    </div>

    <div class="form-group">
        <label>
            <input type="checkbox" name="theme_toggle_enabled" value="1"
                <?= ($themeToggleEnabled ?? true) ? 'checked' : '' ?>>
            Show theme toggle button on public site
        </label>
    </div>

    <?php if (!empty($designVars)): ?>
    <h3>Design Tokens</h3>
    <p class="help-text">Override design variables below. Leave blank to use the default from the .pen file.</p>

    <div class="design-vars-grid">
    <?php foreach ($designVars as $varName => $varDef): ?>
        <?php
            $overrideVal = $varOverrides[$varName] ?? '';
            $defaultVal = $varDef['values']['default'] ?? '';
            $type = $varDef['type'] ?? 'string';
        ?>
        <div class="design-var-row">
            <label for="var_<?= $this->e($varName) ?>"
                   class="design-var-label">
                --<?= $this->e($varName) ?>
                <span class="design-var-type"><?= $this->e($type) ?></span>
            </label>
            <div class="design-var-inputs">
                <?php if ($type === 'color'): ?>
                <input type="color"
                       value="<?= $this->e($overrideVal ?: $defaultVal) ?>"
                       onchange="document.getElementById('var_<?= $this->e($varName) ?>').value = this.value"
                       class="design-var-color-picker">
                <?php endif; ?>
                <input type="text"
                       name="var_override[<?= $this->e($varName) ?>]"
                       id="var_<?= $this->e($varName) ?>"
                       value="<?= $this->e($overrideVal) ?>"
                       placeholder="<?= $this->e($defaultVal) ?>"
                       class="form-control form-control-sm">
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="help-text">No design variables found. Select a .pen design system file above.</p>
    <?php endif; ?>
</div>
```

---

### 8. Modify `app/Templates/FrontController.php`

**Purpose**: Add theme switching support and pass theme data to templates.

#### 8a. Modify `getPublicSettings()` — fetch theme-related settings

Update the SQL query to also fetch design system settings:

Add to the `whereRaw` IN list:
```php
':k7' => 'design_system_file',
':k8' => 'design_variable_overrides',
':k9' => 'default_theme_mode',
':k10' => 'theme_toggle_enabled',
```

#### 8b. New private method: `resolveTheme(Request $request, array $content = []): string`

```php
/**
 * Determine the active theme mode.
 * Priority: per-page override > query param > cookie > site default.
 */
private function resolveTheme(Request $request, array $content = []): string
{
    // 1. Per-page override (from content.theme_override)
    $pageOverride = $content['theme_override'] ?? null;
    if ($pageOverride !== null && $pageOverride !== '') {
        return $pageOverride;
    }

    // 2. Query param (?theme=dark)
    $queryTheme = $request->query('theme');
    if ($queryTheme !== null && in_array($queryTheme, ['light', 'dark'], true)) {
        return $queryTheme;
    }

    // 3. Cookie
    $cookieTheme = $request->cookie('litecms_theme_mode');
    if ($cookieTheme !== null && in_array($cookieTheme, ['light', 'dark'], true)) {
        return $cookieTheme;
    }

    // 4. Site default from settings
    $settings = $this->getPublicSettings();
    return $settings['default_theme_mode'] ?? 'light';
}
```

#### 8c. New private method: `getVariableOverrides(): array`

```php
/**
 * Get design variable overrides from settings.
 */
private function getVariableOverrides(): array
{
    $settings = $this->getPublicSettings();
    $json = $settings['design_variable_overrides'] ?? '{}';
    return json_decode($json, true) ?: [];
}
```

#### 8d. Modify `renderPublic()` — pass theme data

Add to the `$data` merge array:

```php
'activeTheme'      => $this->resolveTheme($request, $data['content'] ?? []),
'defaultTheme'     => $settings['default_theme_mode'] ?? 'light',
'themeOverride'    => $data['content']['theme_override'] ?? '',
'themeToggleEnabled' => ($settings['theme_toggle_enabled'] ?? '1') === '1',
```

**Issue**: `renderPublic` doesn't currently receive `$request`. We need to pass it.

**Change**: Update `renderPublic` signature:

```php
private function renderPublic(string $template, array $data, ?Request $request = null): Response
```

And update all callers to pass `$request`. There are ~8 calls to `renderPublic` in the file — each must add `$request` as the third argument.

#### 8e. Modify `.pen` rendering paths — pass variable overrides

In the places where `PageRenderer::renderFromPen()` or `PenConverter::convertFile()` is called (in `renderContentHomepage`, `blogPost`, `page`), pass the variable overrides:

```php
$overrides = $this->getVariableOverrides();
$penResult = PenConverter::convertFile($penPath, $overrides);
```

This replaces existing calls like:
```php
$penResult = PageRenderer::renderFromPen($penPath);
```

---

### 9. Modify `templates/public/layout.php`

**Purpose**: Add `data-theme-mode` attribute to `<body>`, optional theme toggle, load theme JS.

#### 9a. Modify `<body>` tag (line 20)

Replace:
```php
<body<?php if (!empty($gaId)): ?> data-ga-id="<?= $this->e($gaId) ?>"<?php endif; ?>>
```

With:
```php
<body data-theme-mode="<?= $this->e($activeTheme ?? 'light') ?>"<?php if (!empty($themeOverride)): ?> data-theme-override="<?= $this->e($themeOverride) ?>"<?php endif; ?> data-default-theme="<?= $this->e($defaultTheme ?? 'light') ?>"<?php if (!empty($gaId)): ?> data-ga-id="<?= $this->e($gaId) ?>"<?php endif; ?>>
```

#### 9b. Add `data-theme-mode` to `<html>` tag (line 2)

Replace:
```php
<html lang="en">
```

With:
```php
<html lang="en" data-theme-mode="<?= $this->e($activeTheme ?? 'light') ?>">
```

#### 9c. Add theme toggle button in header (after the nav element, inside header)

After the `<nav>` closing tag (around line 53), before `</div>`:

```php
<?php if ($themeToggleEnabled ?? true): ?>
                    <button type="button" class="theme-toggle-btn" aria-label="Toggle theme">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                        </svg>
                    </button>
<?php endif; ?>
```

#### 9d. Add theme toggle JS (before closing `</body>`)

Add before the nav toggle script:

```php
    <script src="/assets/js/theme-toggle.js"></script>
```

---

### 10. Modify `public/assets/css/style.css`

**Purpose**: Add theme-aware CSS for dark mode. The PenConverter generates themed CSS variables via `[data-theme-mode="dark"]` selectors, but the public site's own CSS (navigation, footer, etc.) also needs dark mode support.

Add at the end of `style.css`:

```css
/* --- Theme Toggle Button --- */
.theme-toggle-btn {
    background: none;
    border: 1px solid var(--border, #ddd);
    border-radius: 50%;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: inherit;
    padding: 0;
    margin-left: 0.75rem;
    transition: background 0.2s, color 0.2s;
}

.theme-toggle-btn:hover {
    background: rgba(128, 128, 128, 0.15);
}

/* --- Dark Theme Overrides (site chrome) --- */
[data-theme-mode="dark"] {
    color-scheme: dark;
}

[data-theme-mode="dark"] body,
[data-theme-mode="dark"] {
    background-color: var(--background, #1a1a2e);
    color: var(--foreground, #e0e0e0);
}

[data-theme-mode="dark"] .site-header {
    background-color: var(--card, #16213e);
    border-bottom-color: var(--border, #333);
}

[data-theme-mode="dark"] .site-footer {
    background-color: var(--card, #16213e);
    color: var(--foreground, #e0e0e0);
}

[data-theme-mode="dark"] a {
    color: var(--primary, #4fc3f7);
}

[data-theme-mode="dark"] .nav-list a {
    color: var(--foreground, #e0e0e0);
}

[data-theme-mode="dark"] .theme-toggle-btn {
    border-color: var(--border, #555);
    color: var(--foreground, #e0e0e0);
}
```

**Note**: These use CSS custom properties from the design system where available (`var(--background, fallback)`), so if PenConverter-generated variables are present, they take precedence.

---

### 11. Modify `app/Admin/LayoutController.php`

**Purpose**: Add option to assign a `.pen` file as a layout template source. When a `.pen` file is assigned, its header/footer components are rendered via PenConverter instead of the standard or block-mode header/footer.

#### 11a. Modify `readFormData()` — add `pen_file` field

```php
'pen_file' => trim((string) $request->input('pen_file', '')),
```

#### 11b. Modify `store()` and `update()` — save `pen_file`

Add `pen_file` to the INSERT and UPDATE SQL statements.

**Wait**: This requires a schema change to `layout_templates` table. We need to add a `pen_file` column.

**Update migration 011**:

```sql
ALTER TABLE content ADD COLUMN theme_override VARCHAR(50) DEFAULT NULL;
ALTER TABLE layout_templates ADD COLUMN pen_file VARCHAR(500) DEFAULT NULL;
```

#### 11c. Modify `resolveTemplate()` — include `pen_file` in returned data

The static method already returns the full row. If `pen_file` column exists, it's included automatically.

#### 11d. Modify edit/create templates — add `.pen` file selector

In `templates/admin/layouts/edit.php`, add a field:

```php
<div class="form-group">
    <label for="pen_file">.pen Design File (optional)</label>
    <select name="pen_file" id="pen_file" class="form-control">
        <option value="">None — use standard layout</option>
        <?php foreach ($designFiles ?? [] as $df): ?>
        <option value="<?= $this->e($df) ?>"
            <?= ($layout['pen_file'] ?? '') === $df ? 'selected' : '' ?>>
            <?= $this->e($df) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <small>Assign a .pen design file to use its components for this layout's header and footer.</small>
</div>
```

#### 11e. Modify `edit()` and `create()` — pass design files list

Load available `.pen` files and pass to template:

```php
$designsDir = dirname(__DIR__, 2) . '/designs';
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
```

Add `'designFiles' => $designFiles` to template data.

---

### 12. Modify `templates/admin/content/edit.php`

**Purpose**: Add per-page theme override selector.

Add a new field in the sidebar (settings section), near the template/layout selector:

```php
<div class="form-group">
    <label for="theme_override">Theme Override</label>
    <select name="theme_override" id="theme_override" class="form-control">
        <option value="" <?= empty($content['theme_override'] ?? '') ? 'selected' : '' ?>>
            Site Default
        </option>
        <option value="light" <?= ($content['theme_override'] ?? '') === 'light' ? 'selected' : '' ?>>
            Light
        </option>
        <option value="dark" <?= ($content['theme_override'] ?? '') === 'dark' ? 'selected' : '' ?>>
            Dark
        </option>
    </select>
    <small>Force a specific theme for this page.</small>
</div>
```

---

### 13. Modify `app/Admin/ContentController.php`

**Purpose**: Handle `theme_override` in form data.

#### 13a. Modify `readFormData()`

Add:
```php
'theme_override' => trim((string) $request->input('theme_override', '')),
```

#### 13b. Modify `store()` and `update()`

Add `theme_override` to the INSERT and UPDATE SQL field lists. Value should be stored as NULL when empty:

```php
$themeOverride = $data['theme_override'] !== '' ? $data['theme_override'] : null;
```

---

### 14. Modify `app/PageBuilder/PageRenderer.php`

**Purpose**: Update `renderFromPen()` to accept and pass variable overrides.

```php
public static function renderFromPen(string $penFilePath, array $variableOverrides = []): array
{
    return PenConverter::convertFile($penFilePath, $variableOverrides);
}
```

---

### 15. Modify `public/assets/css/admin.css`

**Purpose**: Add styles for design variables editor in settings.

```css
/* --- Design System Settings --- */
.design-vars-grid {
    display: grid;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

.design-var-row {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 0.75rem;
    align-items: center;
    padding: 0.4rem 0;
    border-bottom: 1px solid var(--color-border-light);
}

.design-var-label {
    font-family: 'SF Mono', 'Fira Code', monospace;
    font-size: 0.8rem;
    font-weight: 500;
    word-break: break-all;
}

.design-var-type {
    display: inline-block;
    font-size: 0.65rem;
    padding: 0.1rem 0.3rem;
    background: var(--color-bg);
    border-radius: 3px;
    color: var(--color-text-muted);
    margin-left: 0.25rem;
    font-family: inherit;
    text-transform: uppercase;
}

.design-var-inputs {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.design-var-color-picker {
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--color-border);
    border-radius: 4px;
    cursor: pointer;
    flex-shrink: 0;
}
```

---

## Detailed Class Specifications

### `App\PageBuilder\PenConverter` (modifications)

```
NEW PROPERTIES:
  - private array $variableOverrides = []

NEW METHODS:
  - public setVariableOverrides(array $overrides): void
      Stores overrides to be injected into CSS.

  - public static extractVariables(string $penPath): array
      Reads .pen file, extracts variable definitions.
      Returns: ['varName' => ['type'=>..., 'themed'=>bool, 'values'=>['default'=>..., 'mode:dark'=>...]]]

MODIFIED METHODS:
  - public static convertFile(string $penPath, array $variableOverrides = []): array
      Now accepts optional overrides, passes to instance.

  - public static convertDocument(array $document, array $variableOverrides = []): array
      Now accepts optional overrides, passes to instance.

  - private buildVariableCss(): string
      After building normal :root and theme blocks, appends override :root block
      if $this->variableOverrides is not empty.
```

### `App\Admin\SettingsController` (modifications)

```
MODIFIED METHODS:
  - public index(Request $request): Response
      Additionally loads: design system file list, design variables from active
      .pen file, current variable overrides from settings.
      Passes to template: designSystemFile, designVars, designFiles, varOverrides,
      defaultTheme, themeToggleEnabled.

  - public update(Request $request): Response
      Additionally saves: design_system_file, default_theme_mode,
      theme_toggle_enabled, design_variable_overrides (JSON).
```

### `App\Templates\FrontController` (modifications)

```
NEW METHODS:
  - private resolveTheme(Request $request, array $content = []): string
      Determines active theme: page override > query > cookie > site default.

  - private getVariableOverrides(): array
      Returns design variable overrides from settings as associative array.

MODIFIED METHODS:
  - private renderPublic(string $template, array $data, ?Request $request = null): Response
      Now accepts $request. Passes theme data to template: activeTheme,
      defaultTheme, themeOverride, themeToggleEnabled.

  - All methods calling renderPublic (homepage, blogIndex, blogPost, page,
    contactPage, contactSubmit, archive, notFound) — pass $request as 3rd arg.

  - private getPublicSettings(): array
      Query expanded to also fetch design system settings (design_system_file,
      design_variable_overrides, default_theme_mode, theme_toggle_enabled).

  - Pen rendering paths — pass variable overrides to PenConverter::convertFile.
```

### `App\Admin\ContentController` (modifications)

```
MODIFIED METHODS:
  - private readFormData(Request $request): array
      Adds 'theme_override' field.

  - public store() and update()
      Save theme_override to content table.
```

### `App\Admin\LayoutController` (modifications)

```
MODIFIED METHODS:
  - private readFormData(Request $request): array
      Adds 'pen_file' field.

  - public store() and update()
      Save pen_file to layout_templates table.

  - public edit() and create()
      Pass designFiles list to template.
```

### `App\PageBuilder\PageRenderer` (modifications)

```
MODIFIED METHODS:
  - public static renderFromPen(string $penFilePath, array $variableOverrides = []): array
      Passes overrides to PenConverter::convertFile.
```

---

## Acceptance Test Procedures

### Test 1: A `.pen` file can be assigned as a layout template in the layout editor
```
1. Navigate to /admin/layouts/create or /admin/layouts/{id}/edit.
2. Verify a "pen Design File" dropdown appears with available .pen files.
3. Select a .pen file and save the layout.
4. Verify pen_file is stored in the layout_templates table.
5. Content using this layout renders the .pen-based header/footer.
```

### Test 2: Settings page shows design variables from the active `.pen` design system file
```
1. Navigate to /admin/settings.
2. Verify a "Design System" section appears.
3. Verify the "Active Design System" dropdown shows available .pen files.
4. Verify design token fields appear for each variable in the active .pen file.
5. Color-type variables should show a color picker + text input.
6. Placeholder text shows the default value from the .pen file.
```

### Test 3: Changing a color variable in settings updates the public site's CSS `:root` value
```
1. In settings, change --primary color to #ff0000.
2. Save settings.
3. Visit the public site.
4. Inspect the page — verify a :root block with --primary: #ff0000 is present.
5. Elements using var(--primary) should reflect the new color.
```

### Test 4: Theme switching works: `?theme=dark` activates dark theme CSS
```
1. Visit the public site normally (default light theme).
2. Add ?theme=dark to the URL.
3. Verify <html> and <body> have data-theme-mode="dark".
4. Verify dark theme CSS variables are applied (from [data-theme-mode="dark"] selectors).
5. Verify the theme toggle button switches between sun and moon icons.
```

### Test 5: PenConverter generates correct CSS for both light and dark themes
```
1. Create a .pen file with themed variables (light/dark values).
2. Call PenConverter::convertFile() on it.
3. Verify output CSS contains both :root (light defaults) and
   [data-theme-mode="dark"] blocks with the correct values.
4. Apply variable overrides — verify they appear in a separate :root block.
```

### Test 6: Variable overrides persist in the settings table and survive restarts
```
1. Set several variable overrides in settings.
2. Save. Verify design_variable_overrides in settings table contains JSON.
3. Reload settings page — overrides are still shown in the form.
4. Public site still uses the overrides after page refresh.
```

### Test 7: Per-page theme override applies the correct theme to individual pages
```
1. Edit a content item, set theme_override to "dark".
2. Save.
3. Visit the page on the public site.
4. Verify data-theme-mode="dark" on <body>, regardless of user's stored preference.
5. The theme toggle button should be disabled/hidden (page has forced theme).
6. Visit a different page — should use the user's preference or site default.
```

---

## Implementation Notes

### Theme Cascade (Priority)
1. Per-page `theme_override` (highest — content creator controls the page look)
2. `?theme=dark` query parameter (useful for testing/previewing)
3. User's stored preference (cookie `litecms_theme_mode` / localStorage)
4. Site default from settings (`default_theme_mode`)

### CSS Specificity
PenConverter generates `[data-theme-mode="dark"]` selectors. These have higher specificity than `:root` because attribute selectors are more specific. So when `data-theme-mode="dark"` is set on `<html>` or `<body>`, the dark variables automatically override the `:root` defaults. No extra JS/PHP logic needed for variable switching — it's pure CSS.

### Variable Override Injection
Variable overrides from settings are injected as a second `:root {}` block *after* the first one. This works because in CSS, when two selectors have the same specificity, the later one wins. The override `:root` block appears after the `.pen` file's own `:root` block, so it takes precedence for the light theme. For dark theme, the `[data-theme-mode="dark"]` selector has higher specificity than `:root`, so dark theme values from the `.pen` file still apply. If the admin wants to override dark theme values too, they'd need to edit the `.pen` file directly (which is the correct UX — fine-grained theme control belongs in the visual editor).

### No New Routes Needed
All functionality is added to existing controllers and routes:
- Settings: `GET/PUT /admin/settings` (already exists)
- Layout: `GET/POST/PUT /admin/layouts/*` (already exists)
- Theme switching: purely client-side via JS + CSS
- Theme query param: handled in FrontController before rendering

### Security
- Variable overrides sanitized: no `;`, `{`, `}`, `<`, `>`, `javascript:`, `@import`
- Variable names sanitized: only `[a-zA-Z0-9_-]` allowed
- Theme values validated against whitelist: `['light', 'dark']`
- `.pen` file paths validated by existing `sanitizePath()` in DesignController
- Per-page theme_override validated against whitelist

### Performance
- `PenConverter::extractVariables()` reads and parses the `.pen` file JSON only when the settings page loads — not on every public request
- Variable overrides are stored as JSON in a single settings row — single DB read
- Theme switching is purely client-side after initial page load (no server round-trip)
- CSS variables cascade naturally — no runtime CSS generation needed per theme switch

---

## File Checklist

| # | File | Type | Action |
|---|------|------|--------|
| 1 | `migrations/011_theme_settings.sqlite.sql` | Migration | Create |
| 2 | `migrations/011_theme_settings.mysql.sql` | Migration | Create |
| 3 | `migrations/011_theme_settings.pgsql.sql` | Migration | Create |
| 4 | `public/assets/js/theme-toggle.js` | JavaScript | Create |
| 5 | `app/PageBuilder/PenConverter.php` | Class | Modify |
| 6 | `app/Admin/SettingsController.php` | Class | Modify |
| 7 | `templates/admin/settings.php` | Template | Modify |
| 8 | `app/Templates/FrontController.php` | Class | Modify |
| 9 | `templates/public/layout.php` | Template | Modify |
| 10 | `public/assets/css/style.css` | Stylesheet | Modify |
| 11 | `app/Admin/LayoutController.php` | Class | Modify |
| 12 | `templates/admin/layouts/edit.php` | Template | Modify |
| 13 | `templates/admin/content/edit.php` | Template | Modify |
| 14 | `app/Admin/ContentController.php` | Class | Modify |
| 15 | `app/PageBuilder/PageRenderer.php` | Class | Modify |
| 16 | `public/assets/css/admin.css` | Stylesheet | Modify |

---

## Estimated Scope

- **New files**: 4 (3 migrations + 1 JS file)
- **Modified files**: 12
- **New PHP methods**: 4 (setVariableOverrides, extractVariables, resolveTheme, getVariableOverrides)
- **Modified PHP methods**: ~15
- **New JS**: ~60 lines (theme-toggle.js)
- **New CSS**: ~80 lines (admin design vars + public dark theme)
- **Schema changes**: 2 columns (content.theme_override, layout_templates.pen_file)
- **Approximate LOC added**: ~400
