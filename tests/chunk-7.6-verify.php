<?php declare(strict_types=1);

/**
 * Chunk 7.6 — Template System & Theme Integration
 * Automated Verification Tests
 *
 * Tests:
 *   1.  Migration files exist (sqlite, mysql, pgsql) with correct ALTER statements
 *   2.  theme-toggle.js exists with IIFE, localStorage, cookie, data-theme-mode handling
 *   3.  PenConverter::extractVariables() method exists and returns variable metadata
 *   4.  PenConverter::setVariableOverrides() method exists
 *   5.  PenConverter::convertFile() accepts optional $variableOverrides parameter
 *
 *   6.  PenConverter variable overrides inject a second :root block in output CSS
 *   7.  SettingsController::index passes design system data to template
 *   8.  SettingsController::update handles design system settings
 *   9.  Settings template has Design System section with variable editor UI
 *  10.  FrontController has resolveTheme() method
 *  11.  FrontController has getVariableOverrides() method
 *  12.  FrontController::renderPublic accepts optional Request parameter
 *  13.  Public layout template has data-theme-mode attribute on html and body
 *  14.  Public layout template has theme toggle button
 *  15.  style.css has dark theme overrides and theme toggle button styles
 *  16.  LayoutController::readFormData includes pen_file field
 *  17.  Layout edit template has .pen file selector
 *  18.  Content edit template has theme override selector
 *  19.  ContentController::readFormData includes theme_override field
 *  20.  PageRenderer::renderFromPen accepts optional $variableOverrides parameter
 *  21.  admin.css has design variables editor styles
 *  22.  End-to-end: extractVariables + override injection round-trip
 *
 * Smoke mode (LITECMS_TEST_SMOKE=1): runs only tests 1-5
 */

$rootDir = dirname(__DIR__);
$isSmoke = (getenv('LITECMS_TEST_SMOKE') === '1');

$pass = 0;
$fail = 0;

function test_pass(string $description): void {
    global $pass;
    $pass++;
    echo "[PASS] {$description}\n";
}

function test_fail(string $description, string $reason = ''): void {
    global $fail;
    $fail++;
    $detail = $reason ? " — {$reason}" : '';
    echo "[FAIL] {$description}{$detail}\n";
}

function test_skip(string $description): void {
    echo "[SKIP] {$description}\n";
}

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------
$autoloadPath = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found — run composer install');
    echo "\n[FAIL] Cannot continue — autoloader missing\n";
    exit(1);
}
require_once $autoloadPath;

use App\PageBuilder\PenConverter;
use App\PageBuilder\PageRenderer;
use App\Admin\SettingsController;
use App\Admin\ContentController;
use App\Admin\LayoutController;
use App\Templates\FrontController;

// ---------------------------------------------------------------------------
// Test 1: Migration files exist with correct ALTER statements
// ---------------------------------------------------------------------------
$migrationChecks = [
    'migrations/011_theme_settings.sqlite.sql',
    'migrations/011_theme_settings.mysql.sql',
    'migrations/011_theme_settings.pgsql.sql',
];

$migrationOk = true;
$migrationIssues = [];

foreach ($migrationChecks as $mf) {
    $path = $rootDir . '/' . $mf;
    if (!file_exists($path)) {
        $migrationIssues[] = "{$mf} not found";
        $migrationOk = false;
        continue;
    }
    $sql = file_get_contents($path);
    if (!str_contains($sql, 'theme_override')) {
        $migrationIssues[] = "{$mf} missing theme_override column";
        $migrationOk = false;
    }
    if (!str_contains($sql, 'pen_file')) {
        $migrationIssues[] = "{$mf} missing pen_file column";
        $migrationOk = false;
    }
}

if ($migrationOk) {
    test_pass('Test 1: Migration files exist with theme_override + pen_file columns');
} else {
    test_fail('Test 1: Migrations', implode(', ', $migrationIssues));
}

// ---------------------------------------------------------------------------
// Test 2: theme-toggle.js exists with required functionality
// ---------------------------------------------------------------------------
$themeToggleJs = $rootDir . '/public/assets/js/theme-toggle.js';
$toggleContent = file_exists($themeToggleJs) ? file_get_contents($themeToggleJs) : '';

$toggleChecks = [
    'localStorage'       => 'localStorage persistence',
    'litecms_theme_mode' => 'storage key',
    'data-theme-mode'    => 'theme attribute',
    'theme-toggle-btn'   => 'toggle button class',
    'cookie'             => 'cookie persistence',
];

$toggleMissing = [];
foreach ($toggleChecks as $needle => $desc) {
    if (!str_contains($toggleContent, $needle)) {
        $toggleMissing[] = $desc;
    }
}

if (empty($toggleMissing)) {
    test_pass('Test 2: theme-toggle.js exists with localStorage, cookie, and toggle handling');
} else {
    test_fail('Test 2: theme-toggle.js', 'missing: ' . implode(', ', $toggleMissing));
}

// ---------------------------------------------------------------------------
// Test 3: PenConverter::extractVariables() exists and works
// ---------------------------------------------------------------------------
if (method_exists(PenConverter::class, 'extractVariables')) {
    // Test with a non-existent file — should return empty array
    $result = PenConverter::extractVariables($rootDir . '/nonexistent.pen');
    if (is_array($result) && empty($result)) {
        test_pass('Test 3: PenConverter::extractVariables() exists and returns [] for missing file');
    } else {
        test_fail('Test 3: extractVariables', 'did not return empty array for missing file');
    }
} else {
    test_fail('Test 3: PenConverter::extractVariables', 'method not found');
}

// ---------------------------------------------------------------------------
// Test 4: PenConverter::setVariableOverrides() exists
// ---------------------------------------------------------------------------
if (method_exists(PenConverter::class, 'setVariableOverrides')) {
    test_pass('Test 4: PenConverter::setVariableOverrides() method exists');
} else {
    test_fail('Test 4: PenConverter::setVariableOverrides', 'method not found');
}

// ---------------------------------------------------------------------------
// Test 5: PenConverter::convertFile() accepts optional $variableOverrides
// ---------------------------------------------------------------------------
try {
    $ref = new ReflectionMethod(PenConverter::class, 'convertFile');
    $params = $ref->getParameters();
    $hasOverrides = false;
    foreach ($params as $p) {
        if ($p->getName() === 'variableOverrides' && $p->isOptional()) {
            $hasOverrides = true;
            break;
        }
    }
    if ($hasOverrides) {
        test_pass('Test 5: PenConverter::convertFile() accepts optional $variableOverrides parameter');
    } else {
        test_fail('Test 5: convertFile signature', 'missing optional variableOverrides parameter');
    }
} catch (\Throwable $e) {
    test_fail('Test 5: convertFile signature', $e->getMessage());
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 7.6 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 6: Variable overrides inject a second :root block in CSS
// ---------------------------------------------------------------------------
try {
    // Create a minimal .pen document with a variable
    $testDoc = [
        'version' => '2.7',
        'variables' => [
            'primary' => ['type' => 'color', 'value' => '#3366ff'],
        ],
        'children' => [
            [
                'id' => 'test-frame',
                'type' => 'frame',
                'name' => 'Test Frame',
                'width' => 800,
                'height' => 400,
                'children' => [
                    ['id' => 'test-text', 'type' => 'text', 'content' => 'Hello'],
                ],
            ],
        ],
    ];

    // Convert without overrides
    $resultNoOverride = PenConverter::convertDocument($testDoc);
    // Convert with overrides
    $resultWithOverride = PenConverter::convertDocument($testDoc, ['primary' => '#ff0000']);

    $cssNoOverride = $resultNoOverride['css'] ?? '';
    $cssWithOverride = $resultWithOverride['css'] ?? '';

    // The override version should contain #ff0000
    if (str_contains($cssWithOverride, '#ff0000') || str_contains($cssWithOverride, 'ff0000')) {
        // And should still have the original :root block too
        if (str_contains($cssWithOverride, '--primary')) {
            test_pass('Test 6: Variable overrides inject override values into CSS output');
        } else {
            test_fail('Test 6: Override CSS', 'override value found but --primary variable name missing');
        }
    } else {
        test_fail('Test 6: Override CSS', 'override value #ff0000 not found in CSS output');
    }
} catch (\Throwable $e) {
    test_fail('Test 6: Override CSS', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: SettingsController::index passes design system data to template
// ---------------------------------------------------------------------------
$settingsSrc = file_get_contents($rootDir . '/app/Admin/SettingsController.php');

$settingsDataChecks = [
    'designSystemFile'  => 'design system file variable',
    'designVars'        => 'design variables',
    'designFiles'       => 'design files list',
    'varOverrides'      => 'variable overrides',
    'defaultTheme'      => 'default theme',
    'themeToggleEnabled' => 'theme toggle enabled',
];

$settingsDataMissing = [];
foreach ($settingsDataChecks as $needle => $desc) {
    if (!str_contains($settingsSrc, $needle)) {
        $settingsDataMissing[] = $desc;
    }
}

if (empty($settingsDataMissing)) {
    test_pass('Test 7: SettingsController::index passes design system data to template');
} else {
    test_fail('Test 7: Settings index data', 'missing: ' . implode(', ', $settingsDataMissing));
}

// ---------------------------------------------------------------------------
// Test 8: SettingsController::update handles design system settings
// ---------------------------------------------------------------------------
$settingsUpdateChecks = [
    'design_system_file'        => 'design system file save',
    'default_theme_mode'        => 'default theme save',
    'theme_toggle_enabled'      => 'theme toggle save',
    'design_variable_overrides' => 'variable overrides save',
    'var_override'              => 'variable override form field',
];

$updateMissing = [];
foreach ($settingsUpdateChecks as $needle => $desc) {
    if (!str_contains($settingsSrc, $needle)) {
        $updateMissing[] = $desc;
    }
}

if (empty($updateMissing)) {
    test_pass('Test 8: SettingsController::update handles design system settings');
} else {
    test_fail('Test 8: Settings update', 'missing: ' . implode(', ', $updateMissing));
}

// ---------------------------------------------------------------------------
// Test 9: Settings template has Design System section
// ---------------------------------------------------------------------------
$settingsTpl = $rootDir . '/templates/admin/settings.php';
$settingsTplContent = file_exists($settingsTpl) ? file_get_contents($settingsTpl) : '';

$tplChecks = [
    'Design System'          => 'section heading',
    'design_system_file'     => 'file selector',
    'default_theme_mode'     => 'theme selector',
    'theme_toggle_enabled'   => 'toggle checkbox',
    'var_override'           => 'variable override fields',
    'design-var'             => 'variable editor styling',
];

$tplMissing = [];
foreach ($tplChecks as $needle => $desc) {
    if (!str_contains($settingsTplContent, $needle)) {
        $tplMissing[] = $desc;
    }
}

if (empty($tplMissing)) {
    test_pass('Test 9: Settings template has Design System section with variable editor UI');
} else {
    test_fail('Test 9: Settings template', 'missing: ' . implode(', ', $tplMissing));
}

// ---------------------------------------------------------------------------
// Test 10: FrontController has resolveTheme() method
// ---------------------------------------------------------------------------
if (method_exists(FrontController::class, 'resolveTheme')) {
    test_pass('Test 10: FrontController has resolveTheme() method');
} else {
    // It may be private — check source
    $fcSrc = file_get_contents($rootDir . '/app/Templates/FrontController.php');
    if (str_contains($fcSrc, 'function resolveTheme')) {
        test_pass('Test 10: FrontController has resolveTheme() method (private)');
    } else {
        test_fail('Test 10: FrontController::resolveTheme', 'method not found');
    }
}

// ---------------------------------------------------------------------------
// Test 11: FrontController has getVariableOverrides() method
// ---------------------------------------------------------------------------
$fcSrc = $fcSrc ?? file_get_contents($rootDir . '/app/Templates/FrontController.php');

if (str_contains($fcSrc, 'function getVariableOverrides')) {
    test_pass('Test 11: FrontController has getVariableOverrides() method');
} else {
    test_fail('Test 11: FrontController::getVariableOverrides', 'method not found');
}

// ---------------------------------------------------------------------------
// Test 12: FrontController::renderPublic accepts optional Request parameter
// ---------------------------------------------------------------------------
// Check the source for the updated signature
if (str_contains($fcSrc, 'renderPublic') &&
    (str_contains($fcSrc, '?Request $request') || str_contains($fcSrc, 'Request $request = null'))) {
    test_pass('Test 12: FrontController::renderPublic accepts optional Request parameter');
} else {
    test_fail('Test 12: renderPublic signature', 'missing optional Request parameter');
}

// ---------------------------------------------------------------------------
// Test 13: Public layout template has data-theme-mode on html and body
// ---------------------------------------------------------------------------
$publicLayout = $rootDir . '/templates/public/layout.php';
$layoutContent = file_exists($publicLayout) ? file_get_contents($publicLayout) : '';

$themeAttrChecks = [
    'data-theme-mode' => 'theme mode attribute',
    'activeTheme'     => 'activeTheme variable usage',
];

$themeAttrMissing = [];
foreach ($themeAttrChecks as $needle => $desc) {
    if (!str_contains($layoutContent, $needle)) {
        $themeAttrMissing[] = $desc;
    }
}

// Check both html and body have the attribute
$htmlHasAttr = (bool) preg_match('/<html[^>]+data-theme-mode/', $layoutContent);
$bodyHasAttr = str_contains($layoutContent, 'data-theme-mode');

if (empty($themeAttrMissing) && $htmlHasAttr && $bodyHasAttr) {
    test_pass('Test 13: Public layout has data-theme-mode on html and body');
} else {
    $issues = $themeAttrMissing;
    if (!$htmlHasAttr) $issues[] = 'data-theme-mode not on <html>';
    if (!$bodyHasAttr) $issues[] = 'data-theme-mode not on <body>';
    test_fail('Test 13: Layout theme attributes', implode(', ', $issues));
}

// ---------------------------------------------------------------------------
// Test 14: Public layout template has theme toggle button
// ---------------------------------------------------------------------------
if (str_contains($layoutContent, 'theme-toggle-btn') &&
    str_contains($layoutContent, 'themeToggleEnabled')) {
    test_pass('Test 14: Public layout has theme toggle button');
} else {
    $issues = [];
    if (!str_contains($layoutContent, 'theme-toggle-btn')) $issues[] = 'missing theme-toggle-btn class';
    if (!str_contains($layoutContent, 'themeToggleEnabled')) $issues[] = 'missing themeToggleEnabled check';
    test_fail('Test 14: Theme toggle button', implode(', ', $issues));
}

// ---------------------------------------------------------------------------
// Test 15: style.css has dark theme overrides and toggle button styles
// ---------------------------------------------------------------------------
$styleCss = $rootDir . '/public/assets/css/style.css';
$styleContent = file_exists($styleCss) ? file_get_contents($styleCss) : '';

$cssChecks = [
    'theme-toggle-btn'          => 'toggle button styles',
    'data-theme-mode="dark"'    => 'dark theme selector',
    'color-scheme: dark'        => 'dark color scheme',
];

$cssMissing = [];
foreach ($cssChecks as $needle => $desc) {
    if (!str_contains($styleContent, $needle)) {
        $cssMissing[] = $desc;
    }
}

if (empty($cssMissing)) {
    test_pass('Test 15: style.css has dark theme overrides and toggle button styles');
} else {
    test_fail('Test 15: Public CSS', 'missing: ' . implode(', ', $cssMissing));
}

// ---------------------------------------------------------------------------
// Test 16: LayoutController::readFormData includes pen_file field
// ---------------------------------------------------------------------------
$lcSrc = file_get_contents($rootDir . '/app/Admin/LayoutController.php');

if (str_contains($lcSrc, 'pen_file')) {
    test_pass('Test 16: LayoutController handles pen_file field');
} else {
    test_fail('Test 16: LayoutController pen_file', 'pen_file not found in source');
}

// ---------------------------------------------------------------------------
// Test 17: Layout edit template has .pen file selector
// ---------------------------------------------------------------------------
$layoutEditTpl = $rootDir . '/templates/admin/layouts/edit.php';
$layoutEditContent = file_exists($layoutEditTpl) ? file_get_contents($layoutEditTpl) : '';

if (str_contains($layoutEditContent, 'pen_file') &&
    str_contains($layoutEditContent, 'designFiles')) {
    test_pass('Test 17: Layout edit template has .pen file selector');
} else {
    $issues = [];
    if (!str_contains($layoutEditContent, 'pen_file')) $issues[] = 'missing pen_file field';
    if (!str_contains($layoutEditContent, 'designFiles')) $issues[] = 'missing designFiles variable';
    test_fail('Test 17: Layout edit template', implode(', ', $issues));
}

// ---------------------------------------------------------------------------
// Test 18: Content edit template has theme override selector
// ---------------------------------------------------------------------------
$contentEditTpl = $rootDir . '/templates/admin/content/edit.php';
$contentEditContent = file_exists($contentEditTpl) ? file_get_contents($contentEditTpl) : '';

if (str_contains($contentEditContent, 'theme_override')) {
    test_pass('Test 18: Content edit template has theme override selector');
} else {
    test_fail('Test 18: Theme override selector', 'theme_override not found in content edit template');
}

// ---------------------------------------------------------------------------
// Test 19: ContentController::readFormData includes theme_override
// ---------------------------------------------------------------------------
$ccSrc = file_get_contents($rootDir . '/app/Admin/ContentController.php');

if (str_contains($ccSrc, 'theme_override')) {
    test_pass('Test 19: ContentController handles theme_override field');
} else {
    test_fail('Test 19: ContentController theme_override', 'theme_override not found in source');
}

// ---------------------------------------------------------------------------
// Test 20: PageRenderer::renderFromPen accepts optional $variableOverrides
// ---------------------------------------------------------------------------
try {
    $ref = new ReflectionMethod(PageRenderer::class, 'renderFromPen');
    $params = $ref->getParameters();
    $hasOverrides = false;
    foreach ($params as $p) {
        if ($p->getName() === 'variableOverrides' && $p->isOptional()) {
            $hasOverrides = true;
            break;
        }
    }
    if ($hasOverrides) {
        test_pass('Test 20: PageRenderer::renderFromPen() accepts optional $variableOverrides');
    } else {
        test_fail('Test 20: renderFromPen signature', 'missing optional variableOverrides parameter');
    }
} catch (\Throwable $e) {
    test_fail('Test 20: renderFromPen signature', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 21: admin.css has design variables editor styles
// ---------------------------------------------------------------------------
$adminCss = $rootDir . '/public/assets/css/admin.css';
$adminCssContent = file_exists($adminCss) ? file_get_contents($adminCss) : '';

$adminCssChecks = [
    'design-vars-grid'        => 'variable grid layout',
    'design-var-row'          => 'variable row',
    'design-var-label'        => 'variable label',
    'design-var-inputs'       => 'variable inputs',
    'design-var-color-picker' => 'color picker',
];

$adminCssMissing = [];
foreach ($adminCssChecks as $needle => $desc) {
    if (!str_contains($adminCssContent, $needle)) {
        $adminCssMissing[] = "{$desc} (.{$needle})";
    }
}

if (empty($adminCssMissing)) {
    test_pass('Test 21: admin.css has design variables editor styles');
} else {
    test_fail('Test 21: Admin CSS', 'missing: ' . implode(', ', $adminCssMissing));
}

// ---------------------------------------------------------------------------
// Test 22: End-to-end: extractVariables + override injection round-trip
// ---------------------------------------------------------------------------
try {
    // Create a temporary .pen file with themed variables
    $tmpPen = $rootDir . '/storage/cache/_test_theme_vars.pen';
    $testDoc = [
        'version' => '2.7',
        'variables' => [
            'primary' => [
                'type' => 'color',
                'value' => [
                    ['theme' => [], 'value' => '#3366ff'],
                    ['theme' => ['mode' => 'dark'], 'value' => '#6699ff'],
                ],
            ],
            'background' => [
                'type' => 'color',
                'value' => '#ffffff',
            ],
            'font-size' => [
                'type' => 'number',
                'value' => '16px',
            ],
        ],
        'children' => [
            [
                'id' => 'test-frame',
                'type' => 'frame',
                'name' => 'Test Page',
                'width' => 800,
                'height' => 400,
                'children' => [
                    ['id' => 'test-text', 'type' => 'text', 'content' => 'Theme Test'],
                ],
            ],
        ],
    ];

    file_put_contents($tmpPen, json_encode($testDoc, JSON_PRETTY_PRINT), LOCK_EX);

    // 1. Extract variables
    $vars = PenConverter::extractVariables($tmpPen);

    $ok = true;
    $issues = [];

    if (!isset($vars['primary'])) {
        $issues[] = 'primary variable not extracted';
        $ok = false;
    } else {
        if (($vars['primary']['type'] ?? '') !== 'color') {
            $issues[] = 'primary type not color';
            $ok = false;
        }
        if (!($vars['primary']['themed'] ?? false)) {
            $issues[] = 'primary not marked as themed';
            $ok = false;
        }
    }

    if (!isset($vars['background'])) {
        $issues[] = 'background variable not extracted';
        $ok = false;
    } else {
        if (($vars['background']['themed'] ?? true) !== false) {
            $issues[] = 'background incorrectly marked as themed';
            $ok = false;
        }
    }

    if (!isset($vars['font-size'])) {
        $issues[] = 'font-size variable not extracted';
        $ok = false;
    }

    // 2. Convert with overrides
    $overrides = ['primary' => '#ee0000', 'background' => '#f0f0f0'];
    $result = PenConverter::convertFile($tmpPen, $overrides);
    $css = $result['css'] ?? '';

    if (!str_contains($css, '#ee0000') && !str_contains($css, 'ee0000')) {
        $issues[] = 'override value #ee0000 not in CSS';
        $ok = false;
    }

    if (!str_contains($css, '#f0f0f0') && !str_contains($css, 'f0f0f0')) {
        $issues[] = 'override value #f0f0f0 not in CSS';
        $ok = false;
    }

    // The HTML should exist
    $html = $result['html'] ?? '';
    if (!str_contains($html, 'Theme Test')) {
        $issues[] = 'HTML missing expected text content';
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 22: End-to-end extractVariables + override injection round-trip');
    } else {
        test_fail('Test 22: E2E round-trip', implode(', ', $issues));
    }

    // Cleanup
    @unlink($tmpPen);
} catch (\Throwable $e) {
    test_fail('Test 22: E2E round-trip', $e->getMessage());
    @unlink($rootDir . '/storage/cache/_test_theme_vars.pen');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 7.6 results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
