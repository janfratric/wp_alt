<?php declare(strict_types=1);

/**
 * Chunk 6.5 — Layout Template Block Management
 * Automated Verification Tests
 *
 * Tests:
 *   1.  Migration 008 files exist for all 3 drivers
 *   2.  LayoutController has saveTemplateBlocks() and loadTemplateBlocks() methods
 *   3.  Layout edit template has blocks section, blocks_json input, layout-editor.js
 *  [SMOKE STOP]
 *   4.  Migration 008 adds layout_template_id column and makes content_id nullable
 *   5.  LayoutController::store() saves template blocks via blocks_json
 *   6.  LayoutController::edit() loads and passes blocks to template
 *   7.  LayoutController::update() updates blocks correctly (delete + reinsert)
 *   8.  Block validation: columns clamped 1-12, width 10-100, alignment/display whitelisted
 *   9.  ContentController::loadPageElements includes block_id in output
 *  10.  ContentController::savePageElements persists block_id
 *  11.  ContentController::edit() passes templateBlocks to template
 *  12.  content/edit.php has data-template-blocks attribute
 *  13.  page-builder-init.js parses template blocks and passes to initPageBuilder
 *  14.  page-builder.js has templateBlocks state, block-aware rendering, block_id in serialize
 *  15.  PageRenderer::loadBlocks() supports layout_template_id parameter
 *  16.  PageRenderer::renderPageWithBlocks() accepts optional template ID
 *  17.  FrontController passes resolved template ID to block loading
 *  18.  layout-editor.js has block CRUD functions (addBlock, removeBlock, renderBlocks, serialize)
 *  19.  admin.css has block container styles (.pb-template-block, .pb-unassigned-section)
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
// Test 1: Migration 008 files exist for all 3 drivers
// ===========================================================================
$migrationFiles = [
    'migrations/008_blocks_to_templates.sqlite.sql',
    'migrations/008_blocks_to_templates.mysql.sql',
    'migrations/008_blocks_to_templates.pgsql.sql',
];

$allExist = true;
$missing = [];
foreach ($migrationFiles as $file) {
    if (!file_exists($rootDir . '/' . $file)) {
        $allExist = false;
        $missing[] = $file;
    }
}

if ($allExist) {
    test_pass('Migration 008 files exist for all 3 drivers (sqlite, mysql, pgsql)');
} else {
    test_fail('Migration 008 files exist', 'missing: ' . implode(', ', $missing));
}

// ===========================================================================
// Test 2: LayoutController has saveTemplateBlocks() and loadTemplateBlocks()
// ===========================================================================
$lcClass = \App\Admin\LayoutController::class;
if (class_exists($lcClass)) {
    $lcRef = new ReflectionClass($lcClass);
    $hasSave = $lcRef->hasMethod('saveTemplateBlocks');
    $hasLoad = $lcRef->hasMethod('loadTemplateBlocks');

    if ($hasSave && $hasLoad) {
        test_pass('LayoutController has saveTemplateBlocks() and loadTemplateBlocks() methods');
    } else {
        $missing = [];
        if (!$hasSave) $missing[] = 'saveTemplateBlocks';
        if (!$hasLoad) $missing[] = 'loadTemplateBlocks';
        test_fail('LayoutController methods', 'missing: ' . implode(', ', $missing));
    }
} else {
    test_fail('LayoutController class exists');
}

// ===========================================================================
// Test 3: Layout edit template has blocks section, blocks_json input, layout-editor.js
// ===========================================================================
$layoutEditPath = $rootDir . '/templates/admin/layouts/edit.php';
if (file_exists($layoutEditPath)) {
    $src = file_get_contents($layoutEditPath);
    $checks = [
        'blocks-container'  => str_contains($src, 'blocks-container'),
        'blocks_json'       => str_contains($src, 'blocks_json'),
        'data-blocks'       => str_contains($src, 'data-blocks'),
        'add-block-btn'     => str_contains($src, 'add-block-btn'),
        'layout-editor.js'  => str_contains($src, 'layout-editor.js'),
    ];

    $allPresent = !in_array(false, $checks, true);
    if ($allPresent) {
        test_pass('Layout edit template has blocks section, hidden input, data-blocks, add button, and JS');
    } else {
        $missingChecks = array_keys(array_filter($checks, function($v) { return !$v; }));
        test_fail('Layout edit template blocks', 'missing: ' . implode(', ', $missingChecks));
    }
} else {
    test_fail('Layout edit template exists');
}

// ===========================================================================
// Smoke stop
// ===========================================================================
if ($isSmoke) {
    echo "\n  Chunk 6.5 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Setup: test database for full tests
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk65.sqlite';

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

    echo "\n  Chunk 6.5 results: {$pass} passed, {$fail} failed\n";
}

register_shutdown_function('cleanup');

// ===========================================================================
// Test 4: Migration 008 adds layout_template_id column and makes content_id nullable
// ===========================================================================
$columns = $pdo->query('PRAGMA table_info(page_blocks)')->fetchAll(PDO::FETCH_ASSOC);
$colMap = [];
foreach ($columns as $col) {
    $colMap[$col['name']] = $col;
}

$hasTemplateId = isset($colMap['layout_template_id']);
$hasContentId = isset($colMap['content_id']);
$contentIdNullable = $hasContentId && ((int) $colMap['content_id']['notnull'] === 0);

if ($hasTemplateId && $hasContentId && $contentIdNullable) {
    test_pass('Migration 008: layout_template_id column exists, content_id is nullable');
} else {
    $issues = [];
    if (!$hasTemplateId) $issues[] = 'layout_template_id missing';
    if (!$hasContentId) $issues[] = 'content_id missing';
    if (!$contentIdNullable) $issues[] = 'content_id still NOT NULL';
    test_fail('Migration 008 schema', implode(', ', $issues));
}

// ===========================================================================
// Test 5: LayoutController::store() saves template blocks via blocks_json
// ===========================================================================
try {
    $app = new \App\Core\App();
    $lc = new \App\Admin\LayoutController($app);

    // Create a layout template with blocks
    $blocksJson = json_encode([
        ['name' => 'Hero', 'columns' => 1, 'width_percent' => 100, 'alignment' => 'center', 'display_mode' => 'flex'],
        ['name' => 'Grid', 'columns' => 3, 'width_percent' => 80, 'alignment' => 'center', 'display_mode' => 'grid'],
    ]);

    $request = makeRequest('POST', '/admin/layouts', [
        'name' => 'Test Layout',
        'slug' => 'test-layout',
        'is_default' => '0',
        'header_visible' => '1',
        'header_height' => 'auto',
        'header_mode' => 'standard',
        'header_element_id' => '',
        'footer_visible' => '1',
        'footer_height' => 'auto',
        'footer_mode' => 'standard',
        'footer_element_id' => '',
        'blocks_json' => $blocksJson,
        'csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $lc->store($request);

    // Check blocks were saved
    $template = \App\Database\QueryBuilder::query('layout_templates')
        ->select('id')
        ->where('slug', 'test-layout')
        ->first();

    if ($template === null) {
        test_fail('LayoutController::store() creates template', 'template not found in DB');
    } else {
        $blocks = \App\Database\QueryBuilder::query('page_blocks')
            ->select()
            ->where('layout_template_id', (string) $template['id'])
            ->orderBy('sort_order')
            ->get();

        if (count($blocks) === 2
            && $blocks[0]['name'] === 'Hero'
            && (int) $blocks[0]['columns'] === 1
            && (int) $blocks[0]['width_percent'] === 100
            && $blocks[1]['name'] === 'Grid'
            && (int) $blocks[1]['columns'] === 3
            && (int) $blocks[1]['width_percent'] === 80
            && $blocks[1]['display_mode'] === 'grid'
        ) {
            test_pass('LayoutController::store() saves template blocks from blocks_json');
        } else {
            test_fail('LayoutController::store() blocks', 'found ' . count($blocks) . ' blocks, expected 2 with correct data');
        }
    }
} catch (\Throwable $e) {
    test_fail('LayoutController::store()', $e->getMessage());
}

// ===========================================================================
// Test 6: LayoutController::edit() loads and passes blocks to template
// ===========================================================================
try {
    $template = \App\Database\QueryBuilder::query('layout_templates')
        ->select('id')
        ->where('slug', 'test-layout')
        ->first();

    $blocks = $lc->loadTemplateBlocks((int) $template['id']);

    if (count($blocks) === 2 && $blocks[0]['name'] === 'Hero' && $blocks[1]['name'] === 'Grid') {
        test_pass('LayoutController::loadTemplateBlocks() returns correct blocks for template');
    } else {
        test_fail('loadTemplateBlocks()', 'expected 2 blocks, got ' . count($blocks));
    }
} catch (\Throwable $e) {
    test_fail('loadTemplateBlocks()', $e->getMessage());
}

// ===========================================================================
// Test 7: LayoutController::update() updates blocks correctly (delete + reinsert)
// ===========================================================================
try {
    $template = \App\Database\QueryBuilder::query('layout_templates')
        ->select('id')
        ->where('slug', 'test-layout')
        ->first();
    $templateId = (string) $template['id'];

    $newBlocksJson = json_encode([
        ['name' => 'Full Width', 'columns' => 1, 'width_percent' => 100, 'alignment' => 'center', 'display_mode' => 'block'],
        ['name' => 'Two Column', 'columns' => 2, 'width_percent' => 90, 'alignment' => 'left', 'display_mode' => 'flex'],
        ['name' => 'Footer Grid', 'columns' => 4, 'width_percent' => 60, 'alignment' => 'right', 'display_mode' => 'grid'],
    ]);

    $request = makeRequest('PUT', '/admin/layouts/' . $templateId, [
        'name' => 'Test Layout Updated',
        'slug' => 'test-layout',
        'is_default' => '0',
        'header_visible' => '1',
        'header_height' => 'auto',
        'header_mode' => 'standard',
        'header_element_id' => '',
        'footer_visible' => '1',
        'footer_height' => 'auto',
        'footer_mode' => 'standard',
        'footer_element_id' => '',
        'blocks_json' => $newBlocksJson,
        'csrf_token' => $_SESSION['csrf_token'],
        '_method' => 'PUT',
    ]);

    $response = $lc->update($request, $templateId);

    $blocks = \App\Database\QueryBuilder::query('page_blocks')
        ->select()
        ->where('layout_template_id', $templateId)
        ->orderBy('sort_order')
        ->get();

    if (count($blocks) === 3
        && $blocks[0]['name'] === 'Full Width'
        && $blocks[1]['name'] === 'Two Column'
        && (int) $blocks[1]['columns'] === 2
        && $blocks[1]['alignment'] === 'left'
        && $blocks[2]['name'] === 'Footer Grid'
        && (int) $blocks[2]['columns'] === 4
        && $blocks[2]['alignment'] === 'right'
    ) {
        test_pass('LayoutController::update() replaces blocks correctly (old deleted, new inserted)');
    } else {
        test_fail('LayoutController::update() blocks', 'got ' . count($blocks) . ' blocks, expected 3 with correct data');
    }
} catch (\Throwable $e) {
    test_fail('LayoutController::update()', $e->getMessage());
}

// ===========================================================================
// Test 8: Block validation: columns clamped, width clamped, alignment/display whitelisted
// ===========================================================================
try {
    // Create another template with out-of-range values
    $edgeBlocksJson = json_encode([
        ['name' => 'Over', 'columns' => 99, 'width_percent' => 999, 'alignment' => 'invalid', 'display_mode' => 'invalid'],
        ['name' => 'Under', 'columns' => -5, 'width_percent' => 1, 'alignment' => 'left', 'display_mode' => 'block'],
    ]);

    $request = makeRequest('POST', '/admin/layouts', [
        'name' => 'Edge Case Layout',
        'slug' => 'edge-case-layout',
        'is_default' => '0',
        'header_visible' => '1',
        'header_height' => 'auto',
        'header_mode' => 'standard',
        'header_element_id' => '',
        'footer_visible' => '1',
        'footer_height' => 'auto',
        'footer_mode' => 'standard',
        'footer_element_id' => '',
        'blocks_json' => $edgeBlocksJson,
        'csrf_token' => $_SESSION['csrf_token'],
    ]);

    $lc->store($request);

    $tpl = \App\Database\QueryBuilder::query('layout_templates')
        ->select('id')
        ->where('slug', 'edge-case-layout')
        ->first();

    $blocks = \App\Database\QueryBuilder::query('page_blocks')
        ->select()
        ->where('layout_template_id', (string) $tpl['id'])
        ->orderBy('sort_order')
        ->get();

    $ok = true;
    $issues = [];

    // Block "Over": columns should be clamped to 12, width to 100, alignment default, display default
    if ((int) $blocks[0]['columns'] !== 12) { $ok = false; $issues[] = 'cols=' . $blocks[0]['columns']; }
    if ((int) $blocks[0]['width_percent'] !== 100) { $ok = false; $issues[] = 'width=' . $blocks[0]['width_percent']; }
    if ($blocks[0]['alignment'] !== 'center') { $ok = false; $issues[] = 'align=' . $blocks[0]['alignment']; }
    if ($blocks[0]['display_mode'] !== 'flex') { $ok = false; $issues[] = 'display=' . $blocks[0]['display_mode']; }

    // Block "Under": columns should be clamped to 1, width to 10
    if ((int) $blocks[1]['columns'] !== 1) { $ok = false; $issues[] = 'cols2=' . $blocks[1]['columns']; }
    if ((int) $blocks[1]['width_percent'] !== 10) { $ok = false; $issues[] = 'width2=' . $blocks[1]['width_percent']; }

    if ($ok) {
        test_pass('Block validation: columns clamped 1-12, width 10-100, alignment/display whitelisted');
    } else {
        test_fail('Block validation', implode(', ', $issues));
    }
} catch (\Throwable $e) {
    test_fail('Block validation', $e->getMessage());
}

// ===========================================================================
// Test 9: ContentController::loadPageElements includes block_id in output
// ===========================================================================
try {
    $ccRef = new ReflectionClass(\App\Admin\ContentController::class);
    $loadMethod = $ccRef->getMethod('loadPageElements');
    $loadMethod->setAccessible(true);

    // Create content in elements mode
    $contentId = \App\Database\QueryBuilder::query('content')->insert([
        'type'          => 'page',
        'title'         => 'Block Test Page',
        'slug'          => 'block-test-page',
        'body'          => '',
        'status'        => 'published',
        'author_id'     => 1,
        'editor_mode'   => 'elements',
    ]);

    // Get an element ID
    $element = \App\Database\QueryBuilder::query('elements')
        ->select('id')
        ->where('status', 'active')
        ->first();

    if ($element !== null) {
        // Insert a page_element with block_id
        $tpl = \App\Database\QueryBuilder::query('layout_templates')
            ->select('id')
            ->where('slug', 'test-layout')
            ->first();
        $blocks = \App\Database\QueryBuilder::query('page_blocks')
            ->select('id')
            ->where('layout_template_id', (string) $tpl['id'])
            ->first();
        $blockId = $blocks ? (string) $blocks['id'] : null;

        \App\Database\QueryBuilder::query('page_elements')->insert([
            'content_id'      => (int) $contentId,
            'element_id'      => (int) $element['id'],
            'sort_order'      => 0,
            'slot_data_json'  => '{}',
            'style_data_json' => '{}',
            'block_id'        => $blockId,
        ]);

        $cc = new \App\Admin\ContentController($app);
        $result = $loadMethod->invoke($cc, (int) $contentId);

        if (count($result) === 1 && array_key_exists('blockId', $result[0])) {
            if ($blockId !== null && (int) $result[0]['blockId'] === (int) $blockId) {
                test_pass('ContentController::loadPageElements() includes block_id in output');
            } else {
                test_fail('loadPageElements block_id', 'blockId mismatch: expected ' . $blockId . ', got ' . var_export($result[0]['blockId'], true));
            }
        } else {
            test_fail('loadPageElements block_id', 'blockId key not in output');
        }
    } else {
        test_fail('loadPageElements block_id', 'no elements seeded');
    }
} catch (\Throwable $e) {
    test_fail('loadPageElements block_id', $e->getMessage());
}

// ===========================================================================
// Test 10: ContentController::savePageElements persists block_id
// ===========================================================================
try {
    $ccRef = new ReflectionClass(\App\Admin\ContentController::class);
    $saveMethod = $ccRef->getMethod('savePageElements');
    $saveMethod->setAccessible(true);

    $element = \App\Database\QueryBuilder::query('elements')
        ->select('id')
        ->where('status', 'active')
        ->first();

    $tpl = \App\Database\QueryBuilder::query('layout_templates')
        ->select('id')
        ->where('slug', 'test-layout')
        ->first();
    $blockRow = \App\Database\QueryBuilder::query('page_blocks')
        ->select('id')
        ->where('layout_template_id', (string) $tpl['id'])
        ->first();

    if ($element && $blockRow) {
        $elementsJson = json_encode([
            [
                'element_id' => (int) $element['id'],
                'slot_data'  => [],
                'style_data' => [],
                'block_id'   => (int) $blockRow['id'],
            ],
        ]);

        $request = makeRequest('POST', '/admin/content', [
            'elements_json' => $elementsJson,
        ]);

        $cc = new \App\Admin\ContentController($app);
        $saveMethod->invoke($cc, (int) $contentId, $request);

        $saved = \App\Database\QueryBuilder::query('page_elements')
            ->select('block_id')
            ->where('content_id', (string) $contentId)
            ->first();

        if ($saved !== null && (int) $saved['block_id'] === (int) $blockRow['id']) {
            test_pass('ContentController::savePageElements() persists block_id');
        } else {
            test_fail('savePageElements block_id', 'block_id not saved correctly');
        }
    } else {
        test_fail('savePageElements block_id', 'no elements or blocks in DB');
    }
} catch (\Throwable $e) {
    test_fail('savePageElements block_id', $e->getMessage());
}

// ===========================================================================
// Test 11: ContentController::edit() passes templateBlocks to template
// ===========================================================================
try {
    // Set layout_template_id on the test content
    $tpl = \App\Database\QueryBuilder::query('layout_templates')
        ->select('id')
        ->where('slug', 'test-layout')
        ->first();

    \App\Database\QueryBuilder::query('content')
        ->where('id', (int) $contentId)
        ->update(['layout_template_id' => (int) $tpl['id']]);

    $ccRef = new ReflectionClass(\App\Admin\ContentController::class);
    $loadTplMethod = $ccRef->getMethod('loadTemplateBlocks');
    $loadTplMethod->setAccessible(true);

    $cc = new \App\Admin\ContentController($app);
    $content = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('id', (int) $contentId)
        ->first();
    $templateBlocks = $loadTplMethod->invoke($cc, $content);

    if (is_array($templateBlocks) && count($templateBlocks) > 0) {
        $hasId = isset($templateBlocks[0]['id']);
        $hasName = isset($templateBlocks[0]['name']);
        if ($hasId && $hasName) {
            test_pass('ContentController loads templateBlocks with id and name from page_blocks');
        } else {
            test_fail('templateBlocks structure', 'missing id or name fields');
        }
    } else {
        test_fail('templateBlocks', 'empty or not array');
    }
} catch (\Throwable $e) {
    test_fail('templateBlocks', $e->getMessage());
}

// ===========================================================================
// Test 12: content/edit.php has data-template-blocks attribute
// ===========================================================================
$contentEditPath = $rootDir . '/templates/admin/content/edit.php';
if (file_exists($contentEditPath)) {
    $src = file_get_contents($contentEditPath);
    if (str_contains($src, 'data-template-blocks')) {
        test_pass('content/edit.php has data-template-blocks attribute');
    } else {
        test_fail('content/edit.php', 'data-template-blocks attribute not found');
    }
} else {
    test_fail('content/edit.php', 'file not found');
}

// ===========================================================================
// Test 13: page-builder-init.js parses template blocks and passes to initPageBuilder
// ===========================================================================
$initJsPath = $rootDir . '/public/assets/js/page-builder-init.js';
if (file_exists($initJsPath)) {
    $src = file_get_contents($initJsPath);
    $checks = [
        'data-template-blocks'   => str_contains($src, 'data-template-blocks'),
        'templateBlocks'         => str_contains($src, 'templateBlocks'),
        'initPageBuilder_3args'  => str_contains($src, 'initPageBuilder(existingInstances, csrf, templateBlocks)'),
    ];

    $allPresent = !in_array(false, $checks, true);
    if ($allPresent) {
        test_pass('page-builder-init.js parses template blocks and passes as 3rd arg to initPageBuilder');
    } else {
        $missingChecks = array_keys(array_filter($checks, function($v) { return !$v; }));
        test_fail('page-builder-init.js', 'missing: ' . implode(', ', $missingChecks));
    }
} else {
    test_fail('page-builder-init.js', 'file not found');
}

// ===========================================================================
// Test 14: page-builder.js has templateBlocks state, block-aware rendering, block_id in serialize
// ===========================================================================
$pbJsPath = $rootDir . '/public/assets/js/page-builder.js';
if (file_exists($pbJsPath)) {
    $src = file_get_contents($pbJsPath);
    $checks = [
        'templateBlocks_state'     => str_contains($src, 'var templateBlocks = []'),
        'pickerTargetBlockId'      => str_contains($src, 'pickerTargetBlockId'),
        'renderBlockAwareLayout'   => str_contains($src, 'renderBlockAwareLayout'),
        'pb-template-block'        => str_contains($src, 'pb-template-block'),
        'blockId_in_instance'      => str_contains($src, 'blockId:'),
        'block_id_serialize'       => str_contains($src, 'block_id:'),
        'tplBlocks_param'          => str_contains($src, 'tplBlocks'),
    ];

    $allPresent = !in_array(false, $checks, true);
    if ($allPresent) {
        test_pass('page-builder.js has templateBlocks state, block-aware rendering, block_id in serialization');
    } else {
        $missingChecks = array_keys(array_filter($checks, function($v) { return !$v; }));
        test_fail('page-builder.js', 'missing: ' . implode(', ', $missingChecks));
    }
} else {
    test_fail('page-builder.js', 'file not found');
}

// ===========================================================================
// Test 15: PageRenderer::loadBlocks() supports layout_template_id parameter
// ===========================================================================
try {
    $prRef = new ReflectionClass(\App\PageBuilder\PageRenderer::class);
    $loadBlocksMethod = $prRef->getMethod('loadBlocks');
    $params = $loadBlocksMethod->getParameters();

    // Should have 2 params: $contentId, $layoutTemplateId (optional)
    if (count($params) >= 2 && $params[1]->isOptional()) {
        // Test template-based loading
        $tpl = \App\Database\QueryBuilder::query('layout_templates')
            ->select('id')
            ->where('slug', 'test-layout')
            ->first();

        $blocks = \App\PageBuilder\PageRenderer::loadBlocks(0, (int) $tpl['id']);
        if (count($blocks) === 3) {
            test_pass('PageRenderer::loadBlocks() supports optional layout_template_id and returns template blocks');
        } else {
            test_fail('PageRenderer::loadBlocks()', 'expected 3 blocks from template, got ' . count($blocks));
        }
    } else {
        test_fail('PageRenderer::loadBlocks()', 'missing optional $layoutTemplateId parameter');
    }
} catch (\Throwable $e) {
    test_fail('PageRenderer::loadBlocks()', $e->getMessage());
}

// ===========================================================================
// Test 16: PageRenderer::renderPageWithBlocks() accepts optional template ID
// ===========================================================================
try {
    $prRef = new ReflectionClass(\App\PageBuilder\PageRenderer::class);
    $method = $prRef->getMethod('renderPageWithBlocks');
    $params = $method->getParameters();

    if (count($params) >= 2 && $params[1]->isOptional()) {
        test_pass('PageRenderer::renderPageWithBlocks() accepts optional $layoutTemplateId parameter');
    } else {
        test_fail('renderPageWithBlocks()', 'missing optional $layoutTemplateId parameter');
    }
} catch (\Throwable $e) {
    test_fail('renderPageWithBlocks()', $e->getMessage());
}

// ===========================================================================
// Test 17: FrontController passes resolved template ID to block loading
// ===========================================================================
$fcPath = $rootDir . '/app/Templates/FrontController.php';
if (file_exists($fcPath)) {
    $src = file_get_contents($fcPath);
    $checks = [
        'resolvedTemplateId'       => str_contains($src, 'resolvedTemplateId'),
        'loadBlocks_with_template' => str_contains($src, 'loadBlocks($contentId, $resolvedTemplateId'),
        'renderPageWithBlocks_tpl' => str_contains($src, 'renderPageWithBlocks($contentId, $resolvedTemplateId'),
    ];

    $allPresent = !in_array(false, $checks, true);
    if ($allPresent) {
        test_pass('FrontController passes resolvedTemplateId to loadBlocks and renderPageWithBlocks');
    } else {
        $missingChecks = array_keys(array_filter($checks, function($v) { return !$v; }));
        test_fail('FrontController template ID', 'missing: ' . implode(', ', $missingChecks));
    }
} else {
    test_fail('FrontController.php', 'file not found');
}

// ===========================================================================
// Test 18: layout-editor.js has block CRUD functions
// ===========================================================================
$leJsPath = $rootDir . '/public/assets/js/layout-editor.js';
if (file_exists($leJsPath)) {
    $src = file_get_contents($leJsPath);
    $checks = [
        'initBlocks'       => str_contains($src, 'function initBlocks'),
        'addBlock'         => str_contains($src, 'function addBlock'),
        'removeBlock'      => str_contains($src, 'function removeBlock'),
        'renderBlocks'     => str_contains($src, 'function renderBlocks'),
        'serializeBlocks'  => str_contains($src, 'function serializeBlocks'),
        'readBlocksFromDOM'=> str_contains($src, 'function readBlocksFromDOM'),
        'dragstart'        => str_contains($src, 'dragstart'),
        'draggable'        => str_contains($src, 'draggable'),
    ];

    $allPresent = !in_array(false, $checks, true);
    if ($allPresent) {
        test_pass('layout-editor.js has block CRUD (init, add, remove, render, serialize, drag-drop)');
    } else {
        $missingChecks = array_keys(array_filter($checks, function($v) { return !$v; }));
        test_fail('layout-editor.js', 'missing: ' . implode(', ', $missingChecks));
    }
} else {
    test_fail('layout-editor.js', 'file not found');
}

// ===========================================================================
// Test 19: admin.css has block container styles
// ===========================================================================
$adminCssPath = $rootDir . '/public/assets/css/admin.css';
if (file_exists($adminCssPath)) {
    $src = file_get_contents($adminCssPath);
    $checks = [
        '.pb-template-block'        => str_contains($src, '.pb-template-block'),
        '.pb-template-block-header' => str_contains($src, '.pb-template-block-header'),
        '.pb-block-name'            => str_contains($src, '.pb-block-name'),
        '.pb-unassigned-section'    => str_contains($src, '.pb-unassigned-section'),
        '.pb-block-empty'           => str_contains($src, '.pb-block-empty'),
    ];

    $allPresent = !in_array(false, $checks, true);
    if ($allPresent) {
        test_pass('admin.css has block container styles (.pb-template-block, .pb-unassigned-section, etc.)');
    } else {
        $missingChecks = array_keys(array_filter($checks, function($v) { return !$v; }));
        test_fail('admin.css block styles', 'missing: ' . implode(', ', $missingChecks));
    }
} else {
    test_fail('admin.css', 'file not found');
}
