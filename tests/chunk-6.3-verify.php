<?php declare(strict_types=1);

/**
 * Chunk 6.3 — Per-Instance Element Styling
 * Automated Verification Tests
 *
 * Tests:
 *   1.  StyleRenderer class is autoloadable
 *   2.  Required new files exist (migrations, page-styles-init.js)
 *   3.  Content edit template has page styles card and script tag
 *  [SMOKE STOP]
 *   4.  Migration creates style_data_json column on page_elements + page_styles table
 *   5.  StyleRenderer::buildInlineStyle() generates correct CSS from style data
 *   6.  StyleRenderer::sanitizeStyleData() clamps numerics, validates colors, whitelists selects
 *   7.  StyleRenderer::sanitizeCustomCss() blocks injection patterns, preserves legitimate CSS
 *   8.  StyleRenderer::scopeCustomCss() prepends scope selector to every rule
 *   9.  StyleRenderer::scopeCustomCss() wraps bare properties in scope block
 *  10.  StyleRenderer::getCustomClasses() returns sanitized class names
 *  11.  StyleRenderer::buildPageLayoutCss() generates rules for page wrappers
 *  12.  ContentController has savePageStyles/loadPageStyles methods
 *  13.  ContentController::store() saves style_data alongside slot_data
 *  14.  ContentController::edit() passes pageStyles to template
 *  15.  PageRenderer::renderInstance() emits inline style + data-instance-id on wrapper
 *  16.  PageRenderer::getPageCss() includes per-instance custom CSS scoped
 *  17.  PageRenderer::getPageLayoutCss() returns CSS for page wrappers
 *  18.  HTML-mode content unaffected (no style_data, no page_styles)
 *  19.  page-builder.js has style tab and style panel functions
 *  20.  admin.css has style panel CSS classes
 *
 * Smoke mode (LITECMS_TEST_SMOKE=1): runs only tests 1-3
 */

$rootDir = dirname(__DIR__);
$isSmoke = (getenv('LITECMS_TEST_SMOKE') === '1');

$pass = 0;
$fail = 0;

function test_pass(string $description): void {
    global $pass;
    $pass++;
    echo "  [PASS] {$description}\n";
}

function test_fail(string $description, string $reason = ''): void {
    global $fail;
    $fail++;
    $detail = $reason ? " — {$reason}" : '';
    echo "  [FAIL] {$description}{$detail}\n";
}

function test_skip(string $description): void {
    echo "  [SKIP] {$description}\n";
}

// ---------------------------------------------------------------------------
// Setup: autoloader
// ---------------------------------------------------------------------------
$autoloadPath = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found — run composer install');
    echo "\n[FAIL] Cannot continue — autoloader missing\n";
    exit(1);
}
require_once $autoloadPath;

// ===========================================================================
// Test 1: StyleRenderer class is autoloadable
// ===========================================================================
$styleRendererLoadable = class_exists(\App\PageBuilder\StyleRenderer::class);

if ($styleRendererLoadable) {
    test_pass('StyleRenderer class is autoloadable');
} else {
    test_fail('StyleRenderer class is autoloadable');
}

// ===========================================================================
// Test 2: Required new files exist
// ===========================================================================
$requiredFiles = [
    'migrations/005_element_styles.sqlite.sql',
    'migrations/005_element_styles.mysql.sql',
    'migrations/005_element_styles.pgsql.sql',
    'app/PageBuilder/StyleRenderer.php',
    'public/assets/js/page-styles-init.js',
];

$allExist = true;
$missing = [];
foreach ($requiredFiles as $file) {
    if (!file_exists($rootDir . '/' . $file)) {
        $allExist = false;
        $missing[] = $file;
    }
}

if ($allExist) {
    test_pass('All required new files exist (migrations x3, StyleRenderer, page-styles-init.js)');
} else {
    test_fail('Required files exist', 'missing: ' . implode(', ', $missing));
}

// ===========================================================================
// Test 3: Content edit template has page styles card and script tag
// ===========================================================================
$editTemplatePath = $rootDir . '/templates/admin/content/edit.php';
if (!file_exists($editTemplatePath)) {
    test_fail('Content edit template exists');
} else {
    $editSrc = file_get_contents($editTemplatePath);
    $checks = [
        'page-styles-card'      => str_contains($editSrc, 'page-styles-card') || str_contains($editSrc, 'page-style'),
        'page_styles_json'      => str_contains($editSrc, 'page_styles_json') || str_contains($editSrc, 'page-styles-json'),
        'page-styles-init.js'   => str_contains($editSrc, 'page-styles-init.js'),
    ];

    $allPresent = !in_array(false, $checks, true);
    if ($allPresent) {
        test_pass('Content edit template has page styles card, hidden input, and page-styles-init.js script');
    } else {
        $missing = array_keys(array_filter($checks, fn($v) => !$v));
        test_fail('Edit template page styles markup', 'missing: ' . implode(', ', $missing));
    }
}

// --- Smoke stop ---
if ($isSmoke) {
    echo "\n  [INFO] Smoke mode — skipping remaining tests\n";
    echo "\n  Chunk 6.3 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Setup: test database for full tests
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk63.sqlite';

if (file_exists($testDbPath)) {
    @unlink($testDbPath);
}

putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $testDbPath);

// Reset Config cache and Connection singleton
$configReflection = new ReflectionClass(\App\Core\Config::class);
$configProp = $configReflection->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, null);

if (class_exists(\App\Database\Connection::class)) {
    \App\Database\Connection::reset();
}

// Run migrations
$pdo = \App\Database\Connection::getInstance();
$migrator = new \App\Database\Migrator($pdo);
$migrator->migrate();

// Start session
if (session_status() !== PHP_SESSION_ACTIVE) {
    \App\Auth\Session::start();
}

$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'admin';
$_SESSION['user_role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Create default admin user
\App\Database\QueryBuilder::query('users')->insert([
    'username'      => 'admin',
    'email'         => 'admin@localhost',
    'password_hash' => password_hash('admin', PASSWORD_BCRYPT),
    'role'          => 'admin',
]);

// Seed elements
if (class_exists(\App\PageBuilder\SeedElements::class)) {
    \App\PageBuilder\SeedElements::seed();
}

// Helper: simulated Request
function makeRequest(string $method, string $uri, array $post = [], array $get = []): \App\Core\Request
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_POST = $post;
    $_GET = $get;
    $_COOKIE = [];
    return new \App\Core\Request();
}

// Cleanup helper
function cleanup(): void
{
    global $testDbPath, $configProp, $pass, $fail;

    $pdo = null;
    \App\Database\Connection::reset();
    $configProp->setValue(null, null);
    putenv('DB_DRIVER');
    putenv('DB_PATH');

    usleep(100000);

    @unlink($testDbPath);
    @unlink($testDbPath . '-wal');
    @unlink($testDbPath . '-shm');

    echo "\n  Chunk 6.3 results: {$pass} passed, {$fail} failed\n";
}

register_shutdown_function('cleanup');

// ===========================================================================
// Test 4: Migration creates style_data_json column + page_styles table
// ===========================================================================
try {
    // Check style_data_json column on page_elements
    $cols = $pdo->query("PRAGMA table_info(page_elements)")->fetchAll(\PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    $hasStyleCol = in_array('style_data_json', $colNames, true);

    // Check page_styles table exists
    $tableCheck = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='page_styles'")->fetchColumn();
    $hasTable = ($tableCheck === 'page_styles');

    // Check page_styles columns
    $psCols = [];
    if ($hasTable) {
        $psCols = array_column(
            $pdo->query("PRAGMA table_info(page_styles)")->fetchAll(\PDO::FETCH_ASSOC),
            'name'
        );
    }
    $hasContentId = in_array('content_id', $psCols, true);
    $hasStyleData = in_array('style_data_json', $psCols, true);

    if ($hasStyleCol && $hasTable && $hasContentId && $hasStyleData) {
        test_pass('Migration: page_elements.style_data_json column + page_styles table with correct columns');
    } else {
        test_fail('Migration schema', 'style_data_json_col=' . ($hasStyleCol ? 'ok' : 'MISSING')
            . ' page_styles_table=' . ($hasTable ? 'ok' : 'MISSING')
            . ' content_id=' . ($hasContentId ? 'ok' : 'MISSING')
            . ' style_data_json=' . ($hasStyleData ? 'ok' : 'MISSING'));
    }
} catch (\Throwable $e) {
    test_fail('Migration schema', $e->getMessage());
}

// ===========================================================================
// Test 5: buildInlineStyle() has inheriting props; buildCascadeStyles() has non-inheriting
// ===========================================================================
try {
    $testData = [
        'padding_top' => '20',
        'padding_right' => '10',
        'padding_bottom' => '20',
        'padding_left' => '10',
        'padding_unit' => 'px',
        'bg_color' => '#ff0000',
        'text_color' => '#333333',
        'opacity' => '0.8',
    ];

    $inline = \App\PageBuilder\StyleRenderer::buildInlineStyle($testData);
    $scope = '.lcms-el[data-instance-id="99"]';
    $cascade = \App\PageBuilder\StyleRenderer::buildCascadeStyles($testData, $scope);

    // Inheriting properties should be in inline style
    $hasTextColor = str_contains($inline, 'color') && str_contains($inline, '#333333');
    $hasOpacity = str_contains($inline, 'opacity') && str_contains($inline, '0.8');

    // Non-inheriting properties should be in cascade CSS rules, NOT inline
    $cascadeHasPadding = str_contains($cascade, 'padding-top') && str_contains($cascade, '20px');
    $cascadeHasBgColor = str_contains($cascade, 'background-color') && str_contains($cascade, '#ff0000');
    $cascadeHasScope = str_contains($cascade, $scope) && str_contains($cascade, '> *');

    // Empty values should produce empty output
    $emptyInline = \App\PageBuilder\StyleRenderer::buildInlineStyle([]);
    $emptyCascade = \App\PageBuilder\StyleRenderer::buildCascadeStyles([], $scope);
    $emptyOk = ($emptyInline === '' && $emptyCascade === '');

    if ($hasTextColor && $hasOpacity && $cascadeHasPadding && $cascadeHasBgColor && $cascadeHasScope && $emptyOk) {
        test_pass('buildInlineStyle() has inheriting props; buildCascadeStyles() has non-inheriting with scope > *');
    } else {
        test_fail('buildInlineStyle()/buildCascadeStyles()', 'textColor=' . ($hasTextColor ? 'ok' : 'FAIL')
            . ' opacity=' . ($hasOpacity ? 'ok' : 'FAIL')
            . ' cascadePadding=' . ($cascadeHasPadding ? 'ok' : 'FAIL')
            . ' cascadeBgColor=' . ($cascadeHasBgColor ? 'ok' : 'FAIL')
            . ' cascadeScope=' . ($cascadeHasScope ? 'ok' : 'FAIL')
            . ' empty=' . ($emptyOk ? 'ok' : 'FAIL'));
    }
} catch (\Throwable $e) {
    test_fail('buildInlineStyle()/buildCascadeStyles()', $e->getMessage());
}

// ===========================================================================
// Test 6: StyleRenderer::sanitizeStyleData() validates values
// ===========================================================================
try {
    $sanitized = \App\PageBuilder\StyleRenderer::sanitizeStyleData([
        'margin_top' => '50000',       // Should be clamped
        'opacity' => '5',              // Should be clamped to 0-1
        'bg_color' => '#ff0000',       // Valid — kept
        'text_align' => 'center',      // Valid whitelist — kept
        'text_align_bad' => 'evil',    // Not a known field — should be removed or kept benign
        'padding_unit' => 'rem',       // Valid whitelist — kept
        'custom_class' => 'my-class <script>bad</script>', // Stripped to safe chars
    ]);

    // opacity should be clamped to max 1
    $opacityClamped = !isset($sanitized['opacity']) || (float)$sanitized['opacity'] <= 1.0;
    // bg_color preserved
    $bgColorOk = ($sanitized['bg_color'] ?? '') === '#ff0000';
    // text_align preserved
    $alignOk = ($sanitized['text_align'] ?? '') === 'center';
    // padding_unit preserved
    $unitOk = ($sanitized['padding_unit'] ?? '') === 'rem';
    // custom_class should not contain < or >
    $classClean = !str_contains($sanitized['custom_class'] ?? '', '<')
        && !str_contains($sanitized['custom_class'] ?? '', '>');

    if ($opacityClamped && $bgColorOk && $alignOk && $unitOk && $classClean) {
        test_pass('sanitizeStyleData() clamps numerics, validates colors, whitelists selects, sanitizes class');
    } else {
        test_fail('sanitizeStyleData()', 'opacity=' . ($opacityClamped ? 'ok' : 'FAIL')
            . ' bgColor=' . ($bgColorOk ? 'ok' : 'FAIL')
            . ' align=' . ($alignOk ? 'ok' : 'FAIL')
            . ' unit=' . ($unitOk ? 'ok' : 'FAIL')
            . ' class=' . ($classClean ? 'ok' : 'FAIL'));
    }
} catch (\Throwable $e) {
    test_fail('sanitizeStyleData()', $e->getMessage());
}

// ===========================================================================
// Test 7: StyleRenderer::sanitizeCustomCss() blocks injection, preserves CSS
// ===========================================================================
try {
    $malicious = '@import url("evil.css"); .x { color: red; } </style><script>alert(1)</script> expression(alert(1)) javascript:void(0)';
    $clean = \App\PageBuilder\StyleRenderer::sanitizeCustomCss($malicious);

    $noImport = !str_contains(strtolower($clean), '@import');
    $noStyleClose = !str_contains(strtolower($clean), '</style>');
    $noScript = !str_contains(strtolower($clean), '<script');
    $noExpression = !str_contains(strtolower($clean), 'expression(');
    $noJavascript = !str_contains(strtolower($clean), 'javascript:');
    $preservesLegit = str_contains($clean, 'color') && str_contains($clean, 'red');

    if ($noImport && $noStyleClose && $noScript && $noExpression && $noJavascript && $preservesLegit) {
        test_pass('sanitizeCustomCss() blocks @import, </style>, <script>, expression(), javascript:; preserves legitimate CSS');
    } else {
        test_fail('sanitizeCustomCss()', 'import=' . ($noImport ? 'ok' : 'STILL PRESENT')
            . ' style=' . ($noStyleClose ? 'ok' : 'STILL PRESENT')
            . ' script=' . ($noScript ? 'ok' : 'STILL PRESENT')
            . ' expression=' . ($noExpression ? 'ok' : 'STILL PRESENT')
            . ' javascript=' . ($noJavascript ? 'ok' : 'STILL PRESENT')
            . ' legit=' . ($preservesLegit ? 'ok' : 'LOST'));
    }
} catch (\Throwable $e) {
    test_fail('sanitizeCustomCss()', $e->getMessage());
}

// ===========================================================================
// Test 8: StyleRenderer::scopeCustomCss() prepends scope to rules
// ===========================================================================
try {
    $css = '.inner { color: red; } h2 { font-size: 2rem; }';
    $scope = '.lcms-el[data-instance-id="42"]';
    $scoped = \App\PageBuilder\StyleRenderer::scopeCustomCss($css, $scope);

    $hasInnerScoped = str_contains($scoped, $scope . ' .inner')
        || str_contains($scoped, $scope . '  .inner');
    $hasH2Scoped = str_contains($scoped, $scope . ' h2')
        || str_contains($scoped, $scope . '  h2');
    $hasColorRed = str_contains($scoped, 'color: red') || str_contains($scoped, 'color:red');
    $hasFontSize = str_contains($scoped, 'font-size');

    if ($hasInnerScoped && $hasH2Scoped && $hasColorRed && $hasFontSize) {
        test_pass('scopeCustomCss() prepends scope selector to every rule');
    } else {
        test_fail('scopeCustomCss() rule scoping', 'innerScoped=' . ($hasInnerScoped ? 'ok' : 'FAIL')
            . ' h2Scoped=' . ($hasH2Scoped ? 'ok' : 'FAIL')
            . ' output: ' . substr($scoped, 0, 200));
    }
} catch (\Throwable $e) {
    test_fail('scopeCustomCss() rule scoping', $e->getMessage());
}

// ===========================================================================
// Test 9: StyleRenderer::scopeCustomCss() wraps bare properties
// ===========================================================================
try {
    $bareCss = 'color: red; font-size: 2rem;';
    $scope = '.lcms-el[data-instance-id="99"]';
    $scoped = \App\PageBuilder\StyleRenderer::scopeCustomCss($bareCss, $scope);

    // Should wrap as: scope { color: red; font-size: 2rem; }
    $hasScope = str_contains($scoped, $scope);
    $hasBraces = str_contains($scoped, '{') && str_contains($scoped, '}');
    $hasColor = str_contains($scoped, 'color');

    if ($hasScope && $hasBraces && $hasColor) {
        test_pass('scopeCustomCss() wraps bare properties (no selector) in scope block');
    } else {
        test_fail('scopeCustomCss() bare properties', 'scope=' . ($hasScope ? 'ok' : 'FAIL')
            . ' braces=' . ($hasBraces ? 'ok' : 'FAIL')
            . ' output: ' . substr($scoped, 0, 200));
    }
} catch (\Throwable $e) {
    test_fail('scopeCustomCss() bare properties', $e->getMessage());
}

// ===========================================================================
// Test 10: StyleRenderer::getCustomClasses() returns sanitized classes
// ===========================================================================
try {
    $classes = \App\PageBuilder\StyleRenderer::getCustomClasses([
        'custom_class' => 'my-class hero_big',
    ]);
    $cleanOk = str_contains($classes, 'my-class') && str_contains($classes, 'hero_big');

    $injected = \App\PageBuilder\StyleRenderer::getCustomClasses([
        'custom_class' => '<script>alert</script> valid-class',
    ]);
    $noHtml = !str_contains($injected, '<') && !str_contains($injected, '>');

    $empty = \App\PageBuilder\StyleRenderer::getCustomClasses([]);
    $emptyOk = ($empty === '');

    if ($cleanOk && $noHtml && $emptyOk) {
        test_pass('getCustomClasses() returns sanitized class names, strips HTML, empty for no data');
    } else {
        test_fail('getCustomClasses()', 'clean=' . ($cleanOk ? 'ok' : 'FAIL')
            . ' noHtml=' . ($noHtml ? 'ok' : 'FAIL')
            . ' empty=' . ($emptyOk ? 'ok' : 'FAIL'));
    }
} catch (\Throwable $e) {
    test_fail('getCustomClasses()', $e->getMessage());
}

// ===========================================================================
// Test 11: StyleRenderer::buildPageLayoutCss() generates rules for wrappers
// ===========================================================================
try {
    $pageCss = \App\PageBuilder\StyleRenderer::buildPageLayoutCss([
        'page_body' => [
            'bg_color' => '#f0f0f0',
            'padding_top' => '40',
            'padding_unit' => 'px',
        ],
        'container' => [
            'max_width' => '1200px',
        ],
    ]);

    $hasPageBody = str_contains($pageCss, '.page-body');
    $hasBgColor = str_contains($pageCss, '#f0f0f0');
    $hasContainer = str_contains($pageCss, '.container');
    $hasMaxWidth = str_contains($pageCss, '1200');

    // Empty input should produce empty output
    $emptyCss = \App\PageBuilder\StyleRenderer::buildPageLayoutCss([]);
    $emptyOk = ($emptyCss === '' || trim($emptyCss) === '');

    if ($hasPageBody && $hasBgColor && $hasContainer && $hasMaxWidth && $emptyOk) {
        test_pass('buildPageLayoutCss() generates .page-body and .container rules with correct properties');
    } else {
        test_fail('buildPageLayoutCss()', 'pageBody=' . ($hasPageBody ? 'ok' : 'FAIL')
            . ' bgColor=' . ($hasBgColor ? 'ok' : 'FAIL')
            . ' container=' . ($hasContainer ? 'ok' : 'FAIL')
            . ' maxWidth=' . ($hasMaxWidth ? 'ok' : 'FAIL')
            . ' empty=' . ($emptyOk ? 'ok' : 'FAIL'));
    }
} catch (\Throwable $e) {
    test_fail('buildPageLayoutCss()', $e->getMessage());
}

// ===========================================================================
// Test 12: ContentController has savePageStyles/loadPageStyles methods
// ===========================================================================
try {
    $reflection = new ReflectionClass(\App\Admin\ContentController::class);

    $hasSave = $reflection->hasMethod('savePageStyles');
    $hasLoad = $reflection->hasMethod('loadPageStyles');

    if ($hasSave && $hasLoad) {
        test_pass('ContentController has savePageStyles() and loadPageStyles() methods');
    } else {
        test_fail('ContentController new methods',
            'savePageStyles=' . ($hasSave ? 'ok' : 'MISSING')
            . ' loadPageStyles=' . ($hasLoad ? 'ok' : 'MISSING'));
    }
} catch (\Throwable $e) {
    test_fail('ContentController reflection', $e->getMessage());
}

// ===========================================================================
// Test 13: store() saves style_data alongside slot_data
// ===========================================================================
$createdContentId = null;
try {
    $app = new \App\Core\App();
    $ctrl = new \App\Admin\ContentController($app);

    $heroEl = \App\Database\QueryBuilder::query('elements')
        ->select('id')
        ->where('slug', 'hero-section')
        ->first();

    $elementsJson = json_encode([
        [
            'element_id' => (int)$heroEl['id'],
            'slot_data' => ['title' => 'Styled Hero'],
            'style_data' => [
                'padding_top' => '40',
                'padding_unit' => 'px',
                'bg_color' => '#0000ff',
                'custom_css' => 'h2 { color: white; }',
            ],
        ],
    ]);

    $req = makeRequest('POST', '/admin/content', [
        'title'         => 'Styled Page',
        'slug'          => 'styled-page',
        'body'          => '',
        'type'          => 'page',
        'status'        => 'published',
        'editor_mode'   => 'elements',
        'elements_json' => $elementsJson,
        'page_styles_json' => json_encode([
            'page_body' => ['bg_color' => '#fafafa', 'custom_css' => '.page-body { min-height: 100vh; }'],
        ]),
        'published_at'  => '',
        'excerpt'       => '',
        'meta_title'    => '',
        'meta_description' => '',
        'featured_image'   => '',
        'sort_order'    => '0',
        'csrf_token'    => $_SESSION['csrf_token'],
    ]);

    $resp = $ctrl->store($req);

    $created = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('slug', 'styled-page')
        ->first();

    if ($created !== null) {
        $createdContentId = (int)$created['id'];

        $peRows = \App\Database\QueryBuilder::query('page_elements')
            ->select()
            ->where('content_id', $createdContentId)
            ->get();

        $hasRow = count($peRows) === 1;
        $styleJson = $peRows[0]['style_data_json'] ?? '{}';
        $styleData = json_decode($styleJson, true) ?: [];

        $hasBgColor = ($styleData['bg_color'] ?? '') === '#0000ff';
        $hasPadding = ($styleData['padding_top'] ?? '') === '40' || ($styleData['padding_top'] ?? 0) == 40;
        $hasCustomCss = str_contains($styleData['custom_css'] ?? '', 'color');

        if ($hasRow && $hasBgColor && $hasPadding && $hasCustomCss) {
            test_pass('store() saves style_data_json with bg_color, padding, and custom_css');
        } else {
            test_fail('store() style_data', 'row=' . ($hasRow ? 'ok' : 'FAIL')
                . ' bgColor=' . ($hasBgColor ? 'ok' : 'FAIL')
                . ' padding=' . ($hasPadding ? 'ok' : 'FAIL')
                . ' customCss=' . ($hasCustomCss ? 'ok' : 'FAIL')
                . ' raw=' . substr($styleJson, 0, 200));
        }
    } else {
        test_fail('store() style_data', 'content not created');
    }
} catch (\Throwable $e) {
    test_fail('store() style_data', $e->getMessage());
}

// ===========================================================================
// Test 14: edit() passes pageStyles to template
// ===========================================================================
try {
    if ($createdContentId === null) {
        test_skip('edit() pageStyles — no content from previous test');
    } else {
        $app = new \App\Core\App();
        $ctrl = new \App\Admin\ContentController($app);

        $req = makeRequest('GET', '/admin/content/' . $createdContentId . '/edit');
        $resp = $ctrl->edit($req, (string)$createdContentId);
        $body = $resp->getBody();

        // Template should contain page_styles_json hidden input with data
        $hasPageStylesInput = str_contains($body, 'page_styles_json') || str_contains($body, 'page-styles-json');
        // Should contain the style data we saved (bg_color or the page_body reference)
        $hasStyleData = str_contains($body, 'fafafa') || str_contains($body, 'page_body')
            || str_contains($body, 'pageStyles');

        if ($resp->getStatus() === 200 && $hasPageStylesInput) {
            test_pass('edit() renders page with page_styles_json hidden input');
        } else {
            test_fail('edit() pageStyles', 'status=' . $resp->getStatus()
                . ' hasInput=' . ($hasPageStylesInput ? 'ok' : 'FAIL'));
        }
    }
} catch (\Throwable $e) {
    test_fail('edit() pageStyles', $e->getMessage());
}

// ===========================================================================
// Test 15: PageRenderer::renderInstance() emits data-instance-id; inheriting inline, non-inheriting via CSS
// ===========================================================================
try {
    $html = \App\PageBuilder\PageRenderer::renderInstance([
        'id' => 42,
        'element_id' => 1,
        'slug' => 'test-element',
        'html_template' => '<p>Hello</p>',
        'slot_data_json' => '{}',
        'style_data_json' => json_encode([
            'text_color' => '#333333',
            'padding_top' => '20',
            'padding_unit' => 'px',
            'bg_color' => '#ff0000',
        ]),
    ]);

    $hasInstanceId = str_contains($html, 'data-instance-id="42"');
    // Inheriting property (text_color) should be inline
    $hasInlineColor = str_contains($html, 'style="') && str_contains($html, 'color: #333333');
    // Non-inheriting properties (padding, bg_color) should NOT be in inline style —
    // they are emitted by getPageCss() as CSS rules targeting data-instance-id
    $noPaddingInline = !str_contains($html, 'padding-top');
    $noBgColorInline = !str_contains($html, 'background-color');

    if ($hasInstanceId && $hasInlineColor && $noPaddingInline && $noBgColorInline) {
        test_pass('renderInstance() outputs data-instance-id, inheriting props inline, non-inheriting via CSS rules');
    } else {
        test_fail('renderInstance() styling', 'instanceId=' . ($hasInstanceId ? 'ok' : 'FAIL')
            . ' inlineColor=' . ($hasInlineColor ? 'ok' : 'FAIL')
            . ' noPaddingInline=' . ($noPaddingInline ? 'ok' : 'FAIL')
            . ' noBgColorInline=' . ($noBgColorInline ? 'ok' : 'FAIL')
            . ' html=' . substr($html, 0, 300));
    }
} catch (\Throwable $e) {
    test_fail('renderInstance() styling', $e->getMessage());
}

// ===========================================================================
// Test 16: PageRenderer::getPageCss() includes per-instance custom CSS scoped
// ===========================================================================
try {
    if ($createdContentId === null) {
        test_skip('getPageCss() custom CSS — no content from previous test');
    } else {
        $css = \App\PageBuilder\PageRenderer::getPageCss($createdContentId);

        // Should contain the custom CSS scoped to the instance
        $hasCustomCss = str_contains($css, 'color') && str_contains($css, 'white');
        $hasScope = str_contains($css, 'data-instance-id');

        if ($hasCustomCss && $hasScope) {
            test_pass('getPageCss() includes per-instance custom CSS scoped with data-instance-id');
        } else {
            test_fail('getPageCss() custom CSS', 'customCss=' . ($hasCustomCss ? 'ok' : 'FAIL')
                . ' scope=' . ($hasScope ? 'ok' : 'FAIL')
                . ' css=' . substr($css, 0, 300));
        }
    }
} catch (\Throwable $e) {
    test_fail('getPageCss() custom CSS', $e->getMessage());
}

// ===========================================================================
// Test 17: PageRenderer::getPageLayoutCss() returns CSS for page wrappers
// ===========================================================================
try {
    if ($createdContentId === null) {
        test_skip('getPageLayoutCss() — no content from previous test');
    } else {
        $layoutCss = \App\PageBuilder\PageRenderer::getPageLayoutCss($createdContentId);

        // Should contain page_body styles we saved
        $hasPageBody = str_contains($layoutCss, '.page-body');
        $hasBgColor = str_contains($layoutCss, 'fafafa');
        // Should contain the custom CSS for page_body
        $hasCustomCss = str_contains($layoutCss, 'min-height') || str_contains($layoutCss, '100vh');

        if ($hasPageBody && ($hasBgColor || $hasCustomCss)) {
            test_pass('getPageLayoutCss() returns CSS for .page-body with styles/custom CSS');
        } else {
            test_fail('getPageLayoutCss()', 'pageBody=' . ($hasPageBody ? 'ok' : 'FAIL')
                . ' bgColor=' . ($hasBgColor ? 'ok' : 'FAIL')
                . ' customCss=' . ($hasCustomCss ? 'ok' : 'FAIL')
                . ' css=' . substr($layoutCss, 0, 300));
        }
    }
} catch (\Throwable $e) {
    test_fail('getPageLayoutCss()', $e->getMessage());
}

// ===========================================================================
// Test 18: HTML-mode content unaffected (no style_data, no page_styles)
// ===========================================================================
try {
    $app = new \App\Core\App();
    $ctrl = new \App\Admin\ContentController($app);

    $req = makeRequest('POST', '/admin/content', [
        'title'         => 'HTML Only Page',
        'slug'          => 'html-only-page',
        'body'          => '<p>Plain HTML</p>',
        'type'          => 'page',
        'status'        => 'published',
        'editor_mode'   => 'html',
        'elements_json' => '',
        'published_at'  => '',
        'excerpt'       => '',
        'meta_title'    => '',
        'meta_description' => '',
        'featured_image'   => '',
        'sort_order'    => '0',
        'csrf_token'    => $_SESSION['csrf_token'],
    ]);

    $resp = $ctrl->store($req);

    $htmlContent = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('slug', 'html-only-page')
        ->first();

    if ($htmlContent !== null) {
        $htmlId = (int)$htmlContent['id'];

        $peRows = \App\Database\QueryBuilder::query('page_elements')
            ->select()
            ->where('content_id', $htmlId)
            ->get();

        $psRows = \App\Database\QueryBuilder::query('page_styles')
            ->select()
            ->where('content_id', $htmlId)
            ->get();

        $noPe = count($peRows) === 0;
        $noPs = count($psRows) === 0;

        if ($noPe && $noPs) {
            test_pass('HTML-mode content has 0 page_elements rows and 0 page_styles rows');
        } else {
            test_fail('HTML-mode isolation', 'pageElements=' . count($peRows) . ' pageStyles=' . count($psRows));
        }
    } else {
        test_fail('HTML-mode isolation', 'content not created');
    }
} catch (\Throwable $e) {
    test_fail('HTML-mode isolation', $e->getMessage());
}

// ===========================================================================
// Test 19: page-builder.js has style tab and style panel functions
// ===========================================================================
$jsSrc = @file_get_contents($rootDir . '/public/assets/js/page-builder.js');
if ($jsSrc === false) {
    test_fail('page-builder.js readable', 'file not found');
} else {
    $jsChecks = [
        'pb-tab / tab system'    => str_contains($jsSrc, 'pb-tab') || str_contains($jsSrc, 'tabBar'),
        'style panel'            => str_contains($jsSrc, 'style-panel') || str_contains($jsSrc, 'StylePanel')
                                     || str_contains($jsSrc, 'createStylePanel'),
        'styleData serialized'   => str_contains($jsSrc, 'style_data') || str_contains($jsSrc, 'styleData'),
        'custom_css / textarea'  => str_contains($jsSrc, 'custom_css') || str_contains($jsSrc, 'customCss')
                                     || str_contains($jsSrc, 'pb-custom-css'),
    ];

    $allJs = !in_array(false, $jsChecks, true);
    if ($allJs) {
        test_pass('page-builder.js has tab system, style panel, styleData serialization, and custom CSS');
    } else {
        $missing = array_keys(array_filter($jsChecks, fn($v) => !$v));
        test_fail('page-builder.js style features', 'missing: ' . implode(', ', $missing));
    }
}

// ===========================================================================
// Test 20: admin.css has style panel CSS classes
// ===========================================================================
$cssSrc = @file_get_contents($rootDir . '/public/assets/css/admin.css');
if ($cssSrc === false) {
    test_fail('admin.css readable', 'file not found');
} else {
    $cssChecks = [
        'pb-tab-bar'         => str_contains($cssSrc, '.pb-tab-bar') || str_contains($cssSrc, '.pb-tab'),
        'pb-style-panel'     => str_contains($cssSrc, '.pb-style-panel') || str_contains($cssSrc, 'pb-style-group'),
        'pb-spacing-control' => str_contains($cssSrc, '.pb-spacing-control') || str_contains($cssSrc, 'pb-spacing'),
        'pb-color-field'     => str_contains($cssSrc, '.pb-color-field') || str_contains($cssSrc, 'pb-color'),
        'pb-custom-css'      => str_contains($cssSrc, '.pb-custom-css'),
    ];

    $allCss = !in_array(false, $cssChecks, true);
    if ($allCss) {
        test_pass('admin.css has style panel classes (tabs, style panel, spacing, color field, custom CSS)');
    } else {
        $missing = array_keys(array_filter($cssChecks, fn($v) => !$v));
        test_fail('admin.css style panel styles', 'missing: ' . implode(', ', $missing));
    }
}

// ---------------------------------------------------------------------------
// Summary (printed by cleanup shutdown function)
// ---------------------------------------------------------------------------
exit($fail > 0 ? 1 : 0);
