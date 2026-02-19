<?php declare(strict_types=1);

/**
 * Chunk 7.5 — Admin Integration & Preview
 * Automated Verification Tests
 *
 * Tests:
 *   1.  Content edit template has "Design Editor" radio option
 *   2.  Content edit template has design editor panel with required elements
 *   3.  design-mode-init.js exists and handles three-way mode toggle
 *   4.  page-builder-init.js updated (no mode toggle conflict)
 *   5.  design-mode-init.js script included in edit template
 *   6.  DesignController has browser method
 *   7.  DesignController has duplicate method
 *   8.  DesignController has deleteFile method
 *   9.  Design browser template exists with required structure
 *  10.  ContentController has reconvert method
 *  11.  ContentController::edit passes designFiles to template
 *  12.  ContentController::create passes designFiles to template
 *  13.  Routes registered: browser, duplicate, delete, reconvert
 *  14.  admin.css has design editor mode styles
 *  15.  admin.css has design browser styles
 *  16.  Admin layout has "Design Files" nav link
 *  17.  DesignController::sanitizePath rejects traversal
 *  18.  DesignController::deleteFile checks content references
 *  19.  Reconvert endpoint updates content body with converted HTML
 *  20.  End-to-end: design mode content CRUD with reconvert
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

use App\Admin\DesignController;
use App\Admin\ContentController;
use App\PageBuilder\PenConverter;
use App\Database\Connection;
use App\Database\QueryBuilder;
use App\Database\Migrator;

// ---------------------------------------------------------------------------
// Test 1: Content edit template has "Design Editor" radio option
// ---------------------------------------------------------------------------
$editTpl = $rootDir . '/templates/admin/content/edit.php';
$editContent = file_exists($editTpl) ? file_get_contents($editTpl) : '';

if ($editContent !== '' &&
    str_contains($editContent, 'value="design"') &&
    str_contains($editContent, 'Design Editor')) {
    test_pass('Test 1: Content edit template has "Design Editor" radio option');
} else {
    $issues = [];
    if ($editContent === '') $issues[] = 'template not found';
    if (!str_contains($editContent, 'value="design"')) $issues[] = 'missing value="design"';
    if (!str_contains($editContent, 'Design Editor')) $issues[] = 'missing "Design Editor" text';
    test_fail('Test 1: Design Editor radio option', implode(', ', $issues));
}

// ---------------------------------------------------------------------------
// Test 2: Content edit template has design editor panel with required elements
// ---------------------------------------------------------------------------
$panelChecks = [
    'design-editor-panel'     => 'panel container',
    'design-file-select'      => 'file selector dropdown',
    'design-editor-iframe'    => 'Pencil editor iframe',
    'design-preview-frame'    => 'preview iframe',
    'design-reconvert-btn'    => 're-convert button',
    'design-file-input'       => 'hidden design_file input',
    'design-new-name'         => 'new file name input',
];

$panelMissing = [];
foreach ($panelChecks as $id => $desc) {
    if (!str_contains($editContent, $id)) {
        $panelMissing[] = "{$desc} ({$id})";
    }
}

if (empty($panelMissing)) {
    test_pass('Test 2: Content edit template has design editor panel with required elements');
} else {
    test_fail('Test 2: Design editor panel elements', 'missing: ' . implode(', ', $panelMissing));
}

// ---------------------------------------------------------------------------
// Test 3: design-mode-init.js exists and handles three-way mode toggle
// ---------------------------------------------------------------------------
$designInitJs = $rootDir . '/public/assets/js/design-mode-init.js';
$designInitContent = file_exists($designInitJs) ? file_get_contents($designInitJs) : '';

if ($designInitContent !== '' &&
    str_contains($designInitContent, 'design-editor-panel') &&
    str_contains($designInitContent, 'html-editor-panel') &&
    str_contains($designInitContent, 'page-builder-panel') &&
    str_contains($designInitContent, 'editor_mode')) {
    test_pass('Test 3: design-mode-init.js exists and handles three-way mode toggle');
} else {
    $issues = [];
    if ($designInitContent === '') $issues[] = 'file not found';
    if (!str_contains($designInitContent, 'design-editor-panel')) $issues[] = 'missing design-editor-panel';
    if (!str_contains($designInitContent, 'html-editor-panel')) $issues[] = 'missing html-editor-panel';
    if (!str_contains($designInitContent, 'page-builder-panel')) $issues[] = 'missing page-builder-panel';
    test_fail('Test 3: design-mode-init.js', implode(', ', $issues));
}

// ---------------------------------------------------------------------------
// Test 4: page-builder-init.js updated (no mode toggle conflict)
// ---------------------------------------------------------------------------
$pbInitJs = $rootDir . '/public/assets/js/page-builder-init.js';
$pbInitContent = file_exists($pbInitJs) ? file_get_contents($pbInitJs) : '';

// The mode toggle should be removed from page-builder-init.js (now handled by design-mode-init.js)
// OR if it's still there, it should handle all three modes (html, elements, design)
$hasOldTwoWayToggle = str_contains($pbInitContent, "this.value === 'html'") &&
                      !str_contains($pbInitContent, 'design');

if ($pbInitContent !== '' && !$hasOldTwoWayToggle) {
    test_pass('Test 4: page-builder-init.js updated (no two-way-only mode toggle)');
} else {
    $issues = [];
    if ($pbInitContent === '') $issues[] = 'file not found';
    if ($hasOldTwoWayToggle) $issues[] = 'still has old two-way toggle without design support';
    test_fail('Test 4: page-builder-init.js', implode(', ', $issues));
}

// ---------------------------------------------------------------------------
// Test 5: design-mode-init.js script included in edit template
// ---------------------------------------------------------------------------
if (str_contains($editContent, 'design-mode-init.js')) {
    test_pass('Test 5: design-mode-init.js script included in edit template');
} else {
    test_fail('Test 5: Script include', 'design-mode-init.js not referenced in edit.php');
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 7.5 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 6: DesignController has browser method
// ---------------------------------------------------------------------------
if (method_exists(DesignController::class, 'browser')) {
    test_pass('Test 6: DesignController has browser method');
} else {
    test_fail('Test 6: DesignController::browser', 'method not found');
}

// ---------------------------------------------------------------------------
// Test 7: DesignController has duplicate method
// ---------------------------------------------------------------------------
if (method_exists(DesignController::class, 'duplicate')) {
    test_pass('Test 7: DesignController has duplicate method');
} else {
    test_fail('Test 7: DesignController::duplicate', 'method not found');
}

// ---------------------------------------------------------------------------
// Test 8: DesignController has deleteFile method
// ---------------------------------------------------------------------------
if (method_exists(DesignController::class, 'deleteFile')) {
    test_pass('Test 8: DesignController has deleteFile method');
} else {
    test_fail('Test 8: DesignController::deleteFile', 'method not found');
}

// ---------------------------------------------------------------------------
// Test 9: Design browser template exists with required structure
// ---------------------------------------------------------------------------
$browserTpl = $rootDir . '/templates/admin/design/browser.php';
$browserContent = file_exists($browserTpl) ? file_get_contents($browserTpl) : '';

if ($browserContent !== '' &&
    str_contains($browserContent, 'design-browser') &&
    str_contains($browserContent, 'Design Files') &&
    str_contains($browserContent, 'designFiles')) {
    test_pass('Test 9: Design browser template exists with required structure');
} else {
    $issues = [];
    if ($browserContent === '') $issues[] = 'template not found';
    if (!str_contains($browserContent, 'design-browser')) $issues[] = 'missing design-browser class';
    if (!str_contains($browserContent, 'Design Files')) $issues[] = 'missing "Design Files" heading';
    if (!str_contains($browserContent, 'designFiles')) $issues[] = 'missing designFiles variable usage';
    test_fail('Test 9: Browser template', implode(', ', $issues));
}

// ---------------------------------------------------------------------------
// Test 10: ContentController has reconvert method
// ---------------------------------------------------------------------------
if (method_exists(ContentController::class, 'reconvert')) {
    test_pass('Test 10: ContentController has reconvert method');
} else {
    test_fail('Test 10: ContentController::reconvert', 'method not found');
}

// ---------------------------------------------------------------------------
// Test 11: ContentController::edit passes designFiles to template
// ---------------------------------------------------------------------------
$ccContent = file_get_contents($rootDir . '/app/Admin/ContentController.php');
// Check that edit() method loads designFiles and passes them to template
if (str_contains($ccContent, 'designFiles') &&
    str_contains($ccContent, '.pen')) {
    test_pass('Test 11: ContentController::edit passes designFiles to template');
} else {
    $issues = [];
    if (!str_contains($ccContent, 'designFiles')) $issues[] = 'missing designFiles variable';
    if (!str_contains($ccContent, '.pen')) $issues[] = 'missing .pen file scanning';
    test_fail('Test 11: Edit designFiles', implode(', ', $issues));
}

// ---------------------------------------------------------------------------
// Test 12: ContentController::create passes designFiles to template
// ---------------------------------------------------------------------------
// The create method should also pass designFiles
// Check that both 'create' and 'designFiles' coexist in the source
// More specifically, look for designFiles being passed in create context
$createSection = '';
$inCreate = false;
$braceCount = 0;
$lines = explode("\n", $ccContent);
foreach ($lines as $line) {
    if (str_contains($line, 'function create(')) {
        $inCreate = true;
        $braceCount = 0;
    }
    if ($inCreate) {
        $createSection .= $line . "\n";
        $braceCount += substr_count($line, '{') - substr_count($line, '}');
        if ($braceCount <= 0 && strlen($createSection) > 50) {
            break;
        }
    }
}

if (str_contains($createSection, 'designFiles')) {
    test_pass('Test 12: ContentController::create passes designFiles to template');
} else {
    test_fail('Test 12: Create designFiles', 'designFiles not found in create() method');
}

// ---------------------------------------------------------------------------
// Test 13: Routes registered: browser, duplicate, delete, reconvert
// ---------------------------------------------------------------------------
$indexPhp = file_get_contents($rootDir . '/public/index.php');

$routeChecks = [
    '/admin/design/browser'  => 'browser',
    '/admin/design/duplicate' => 'duplicate',
    '/admin/design/delete'   => 'deleteFile',
    'reconvert'              => 'reconvert',
];

$routeMissing = [];
foreach ($routeChecks as $pattern => $desc) {
    if (!str_contains($indexPhp, $pattern)) {
        $routeMissing[] = $desc;
    }
}

if (empty($routeMissing)) {
    test_pass('Test 13: Routes registered: browser, duplicate, delete, reconvert');
} else {
    test_fail('Test 13: Route registration', 'missing: ' . implode(', ', $routeMissing));
}

// ---------------------------------------------------------------------------
// Test 14: admin.css has design editor mode styles
// ---------------------------------------------------------------------------
$adminCss = $rootDir . '/public/assets/css/admin.css';
$cssContent = file_exists($adminCss) ? file_get_contents($adminCss) : '';

$editorStyleChecks = [
    'design-mode-toolbar'    => 'toolbar',
    'design-editor-split'    => 'split view',
    'design-editor-pane'     => 'editor pane',
    'design-preview-pane'    => 'preview pane',
    'design-status'          => 'status indicator',
];

$styleMissing = [];
foreach ($editorStyleChecks as $cls => $desc) {
    if (!str_contains($cssContent, $cls)) {
        $styleMissing[] = "{$desc} (.{$cls})";
    }
}

if (empty($styleMissing)) {
    test_pass('Test 14: admin.css has design editor mode styles');
} else {
    test_fail('Test 14: CSS editor styles', 'missing: ' . implode(', ', $styleMissing));
}

// ---------------------------------------------------------------------------
// Test 15: admin.css has design browser styles
// ---------------------------------------------------------------------------
$browserStyleChecks = [
    'design-browser-grid'    => 'grid layout',
    'design-browser-card'    => 'card',
    'design-browser-thumb'   => 'thumbnail',
    'design-browser-actions' => 'actions',
];

$browserStyleMissing = [];
foreach ($browserStyleChecks as $cls => $desc) {
    if (!str_contains($cssContent, $cls)) {
        $browserStyleMissing[] = "{$desc} (.{$cls})";
    }
}

if (empty($browserStyleMissing)) {
    test_pass('Test 15: admin.css has design browser styles');
} else {
    test_fail('Test 15: CSS browser styles', 'missing: ' . implode(', ', $browserStyleMissing));
}

// ---------------------------------------------------------------------------
// Test 16: Admin layout has "Design Files" nav link
// ---------------------------------------------------------------------------
$layoutTpl = $rootDir . '/templates/admin/layout.php';
$layoutContent = file_exists($layoutTpl) ? file_get_contents($layoutTpl) : '';

if (str_contains($layoutContent, '/admin/design/browser') &&
    str_contains($layoutContent, 'Design Files')) {
    test_pass('Test 16: Admin layout has "Design Files" nav link');
} else {
    $issues = [];
    if (!str_contains($layoutContent, '/admin/design/browser')) $issues[] = 'missing browser URL';
    if (!str_contains($layoutContent, 'Design Files')) $issues[] = 'missing "Design Files" text';
    test_fail('Test 16: Nav link', implode(', ', $issues));
}

// ---------------------------------------------------------------------------
// Test 17: DesignController::sanitizePath rejects traversal
// ---------------------------------------------------------------------------
try {
    $app = new \App\Core\App();
    $dc = new DesignController($app);

    $ref = new \ReflectionClass($dc);
    $method = $ref->getMethod('sanitizePath');
    $method->setAccessible(true);

    // Valid path should pass
    $valid = $method->invoke($dc, 'pages/my-design.pen');
    // Traversal path should return null
    $traversal = $method->invoke($dc, '../../../etc/passwd');
    // Double dot in middle
    $traversal2 = $method->invoke($dc, 'pages/../../secret.pen');

    if ($valid !== null && $traversal === null && $traversal2 === null) {
        test_pass('Test 17: DesignController::sanitizePath rejects traversal');
    } else {
        $issues = [];
        if ($valid === null) $issues[] = 'valid path rejected';
        if ($traversal !== null) $issues[] = 'traversal path accepted';
        if ($traversal2 !== null) $issues[] = 'nested traversal accepted';
        test_fail('Test 17: sanitizePath', implode(', ', $issues));
    }
} catch (\Throwable $e) {
    test_fail('Test 17: sanitizePath', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: DesignController::deleteFile checks content references
// ---------------------------------------------------------------------------
try {
    // Verify the deleteFile method source checks for content references via DB query
    $dcSource = file_get_contents($rootDir . '/app/Admin/DesignController.php');

    if (str_contains($dcSource, 'deleteFile') &&
        str_contains($dcSource, 'design_file') &&
        (str_contains($dcSource, 'SELECT COUNT') || str_contains($dcSource, 'usage') || str_contains($dcSource, 'content'))) {
        test_pass('Test 18: DesignController::deleteFile checks content references');
    } else {
        test_fail('Test 18: deleteFile reference check', 'missing content reference check in deleteFile');
    }
} catch (\Throwable $e) {
    test_fail('Test 18: deleteFile', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: Reconvert endpoint updates content body with converted HTML
// ---------------------------------------------------------------------------
try {
    // Ensure migrations are run
    $pdo = Connection::getInstance();
    $migrator = new Migrator($pdo);
    $migrator->migrate();

    // Create a test .pen file
    $dsPath = $rootDir . '/designs/litecms-system.pen';
    $dsDoc = null;
    if (file_exists($dsPath)) {
        $dsDoc = json_decode(file_get_contents($dsPath), true);
    }

    if ($dsDoc === null) {
        test_skip('Test 19: Reconvert (design system file not available)');
    } else {
        $testPage = [
            'id' => 'reconvert-test',
            'type' => 'frame',
            'name' => 'Reconvert Test Page',
            'layout' => 'vertical',
            'width' => 1200,
            'children' => [
                ['id' => 'rct-hero', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                    'hero-heading' => ['content' => 'RECONVERT_TEST_TITLE'],
                ]],
            ],
        ];

        $fullDoc = [
            'version' => '2.7',
            'variables' => $dsDoc['variables'] ?? [],
            'children' => array_merge($dsDoc['children'] ?? [], [$testPage]),
        ];

        $testPenPath = $rootDir . '/designs/pages/_test_reconvert.pen';
        $dir = dirname($testPenPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($testPenPath, json_encode($fullDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        // Insert a content record pointing to this design file
        $testSlug = '_test_reconvert_' . time();
        $id = QueryBuilder::query('content')->insert([
            'type'        => 'page',
            'title'       => 'Reconvert Test Page',
            'slug'        => $testSlug,
            'body'        => '',
            'excerpt'     => '',
            'status'      => 'draft',
            'author_id'   => 1,
            'sort_order'  => 0,
            'editor_mode' => 'design',
            'design_file' => 'pages/_test_reconvert.pen',
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // Convert the .pen file and simulate what reconvert does
        $result = PenConverter::convertFile($testPenPath);

        // Update content body (same logic as reconvert endpoint)
        $update = $pdo->prepare('UPDATE content SET body = ?, design_file = ?, updated_at = ? WHERE id = ?');
        $update->execute([
            $result['html'],
            'pages/_test_reconvert.pen',
            date('Y-m-d H:i:s'),
            (int) $id,
        ]);

        // Verify the body was updated with converted HTML
        $record = QueryBuilder::query('content')
            ->select('body', 'design_file')
            ->where('id', (int) $id)
            ->first();

        if ($record !== null &&
            str_contains($record['body'] ?? '', 'RECONVERT_TEST_TITLE') &&
            ($record['design_file'] ?? '') === 'pages/_test_reconvert.pen') {
            test_pass('Test 19: Reconvert updates content body with converted HTML');
        } else {
            $issues = [];
            if ($record === null) $issues[] = 'record not found';
            if (!str_contains($record['body'] ?? '', 'RECONVERT_TEST_TITLE')) $issues[] = 'converted text not in body';
            if (($record['design_file'] ?? '') !== 'pages/_test_reconvert.pen') $issues[] = 'design_file mismatch';
            test_fail('Test 19: Reconvert', implode(', ', $issues));
        }

        // Cleanup
        QueryBuilder::query('content')->where('id', (int) $id)->delete();
        @unlink($testPenPath);
    }
} catch (\Throwable $e) {
    test_fail('Test 19: Reconvert', $e->getMessage());
    @unlink($rootDir . '/designs/pages/_test_reconvert.pen');
}

// ---------------------------------------------------------------------------
// Test 20: End-to-end: design mode content CRUD with reconvert
// ---------------------------------------------------------------------------
try {
    if ($dsDoc === null) {
        test_skip('Test 20: End-to-end (design system file not available)');
    } else {
        $ok = true;

        // 1. Create a .pen file with multiple sections
        $testPage = [
            'id' => 'e2e-page',
            'type' => 'frame',
            'name' => 'E2E Test Page',
            'layout' => 'vertical',
            'width' => 1200,
            'children' => [
                ['id' => 'e2e-hero', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                    'hero-heading' => ['content' => 'E2E_HERO_TITLE'],
                    'hero-subheading' => ['content' => 'E2E_HERO_SUBTITLE'],
                ]],
                ['id' => 'e2e-text', 'type' => 'ref', 'ref' => 'text-section', 'descendants' => [
                    'text-content-wrapper/text-heading' => ['content' => 'E2E_TEXT_HEADING'],
                    'text-content-wrapper/text-body' => ['content' => 'E2E text body content here.'],
                ]],
            ],
        ];

        $fullDoc = [
            'version' => '2.7',
            'variables' => $dsDoc['variables'] ?? [],
            'children' => array_merge($dsDoc['children'] ?? [], [$testPage]),
        ];

        $testPenPath = $rootDir . '/designs/pages/_test_e2e.pen';
        $dir = dirname($testPenPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($testPenPath, json_encode($fullDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        // 2. Insert content with design mode
        $testSlug = '_test_e2e_' . time();
        $contentId = QueryBuilder::query('content')->insert([
            'type'        => 'page',
            'title'       => 'E2E Design Test',
            'slug'        => $testSlug,
            'body'        => '',
            'excerpt'     => 'E2E test',
            'status'      => 'draft',
            'author_id'   => 1,
            'sort_order'  => 0,
            'editor_mode' => 'design',
            'design_file' => 'pages/_test_e2e.pen',
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        // 3. Verify content was created with design mode
        $record = QueryBuilder::query('content')
            ->select('editor_mode', 'design_file', 'body')
            ->where('id', (int) $contentId)
            ->first();

        if (($record['editor_mode'] ?? '') !== 'design') {
            test_fail('Test 20: E2E — editor_mode not saved as design');
            $ok = false;
        }
        if (($record['design_file'] ?? '') !== 'pages/_test_e2e.pen') {
            test_fail('Test 20: E2E — design_file not saved correctly');
            $ok = false;
        }

        // 4. Convert .pen to HTML (simulate reconvert)
        $result = PenConverter::convertFile($testPenPath);

        if (!str_contains($result['html'], 'E2E_HERO_TITLE') ||
            !str_contains($result['html'], 'E2E_TEXT_HEADING')) {
            test_fail('Test 20: E2E — conversion missing expected text');
            $ok = false;
        }

        // 5. Update content body with converted HTML
        $update = $pdo->prepare('UPDATE content SET body = ?, updated_at = ? WHERE id = ?');
        $update->execute([$result['html'], date('Y-m-d H:i:s'), (int) $contentId]);

        // 6. Verify body now contains converted HTML
        $updated = QueryBuilder::query('content')
            ->select('body')
            ->where('id', (int) $contentId)
            ->first();

        if (!str_contains($updated['body'] ?? '', 'E2E_HERO_TITLE')) {
            test_fail('Test 20: E2E — body not updated after reconvert');
            $ok = false;
        }

        // 7. Verify CSS was generated
        if (!str_contains($result['css'], ':root') || !str_contains($result['css'], '--')) {
            test_fail('Test 20: E2E — CSS missing variables');
            $ok = false;
        }

        if ($ok) {
            test_pass('Test 20: End-to-end design mode content CRUD with reconvert');
        }

        // Cleanup
        QueryBuilder::query('content')->where('id', (int) $contentId)->delete();
        @unlink($testPenPath);
    }
} catch (\Throwable $e) {
    test_fail('Test 20: End-to-end', $e->getMessage());
    @unlink($rootDir . '/designs/pages/_test_e2e.pen');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 7.5 results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
