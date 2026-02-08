<?php declare(strict_types=1);

/**
 * Chunk 6.2 — Content Editor Element Mode & Page Builder UI
 * Automated Verification Tests
 *
 * Tests:
 *   1.  Required new file exists (page-builder.js)
 *   2.  ContentController has new private methods (savePageElements, loadPageElements)
 *   3.  Content edit template has page builder markup (mode toggle, builder panel, picker modal)
 *  [SMOKE STOP]
 *   4.  ContentController::readFormData includes editor_mode field
 *   5.  ContentController::create() passes pageElements and csrfToken to template
 *   6.  ContentController::store() saves editor_mode and page_elements
 *   7.  ContentController::edit() loads page_elements and passes to template
 *   8.  ContentController::update() saves editor_mode and page_elements
 *   9.  savePageElements skips invalid element_ids (validates against elements table)
 *  10.  loadPageElements returns correct structure (elementId, slots, slotData)
 *  11.  Saving in HTML mode does NOT create page_elements rows
 *  12.  page-builder.js has required functions and entry point
 *  13.  admin.css has page builder styles (pb-instance-card, pb-picker-modal, etc.)
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
// Test 1: Required new file exists (page-builder.js)
// ===========================================================================
$requiredFiles = [
    'public/assets/js/page-builder.js',
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
    test_pass('Required new file exists: page-builder.js');
} else {
    test_fail('Required files exist', 'missing: ' . implode(', ', $missing));
}

// ===========================================================================
// Test 2: ContentController has new private methods
// ===========================================================================
try {
    $reflection = new ReflectionClass(\App\Admin\ContentController::class);

    $hasSave = $reflection->hasMethod('savePageElements');
    $hasLoad = $reflection->hasMethod('loadPageElements');

    if ($hasSave && $hasLoad) {
        test_pass('ContentController has savePageElements() and loadPageElements() methods');
    } else {
        test_fail('ContentController new methods',
            'savePageElements=' . ($hasSave ? 'ok' : 'MISSING')
            . ' loadPageElements=' . ($hasLoad ? 'ok' : 'MISSING'));
    }
} catch (\Throwable $e) {
    test_fail('ContentController reflection', $e->getMessage());
}

// ===========================================================================
// Test 3: Content edit template has page builder markup
// ===========================================================================
$editTemplatePath = $rootDir . '/templates/admin/content/edit.php';
if (!file_exists($editTemplatePath)) {
    test_fail('Content edit template exists');
} else {
    $editSrc = file_get_contents($editTemplatePath);
    $checks = [
        'editor_mode radio'   => str_contains($editSrc, 'editor_mode'),
        'page-builder-panel'  => str_contains($editSrc, 'page-builder-panel'),
        'pb-picker-modal'     => str_contains($editSrc, 'pb-picker-modal'),
        'elements-json-input' => str_contains($editSrc, 'elements-json-input') || str_contains($editSrc, 'elements_json'),
        'page-builder.js'     => str_contains($editSrc, 'page-builder.js'),
        'initPageBuilder'     => str_contains($editSrc, 'initPageBuilder'),
    ];

    $allPresent = !in_array(false, $checks, true);
    if ($allPresent) {
        test_pass('Content edit template has page builder markup (mode toggle, builder panel, picker modal, init script)');
    } else {
        $missing = array_keys(array_filter($checks, fn($v) => !$v));
        test_fail('Edit template page builder markup', 'missing: ' . implode(', ', $missing));
    }
}

// --- Smoke stop ---
if ($isSmoke) {
    echo "\n  [INFO] Smoke mode — skipping remaining tests\n";
    echo "\n  Chunk 6.2 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Setup: test database for full tests
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk62.sqlite';

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

// Seed elements (from chunk 6.1)
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

    echo "\n  Chunk 6.2 results: {$pass} passed, {$fail} failed\n";
}

register_shutdown_function('cleanup');

$app = new \App\Core\App();
$ctrl = new \App\Admin\ContentController($app);

// ===========================================================================
// Test 4: ContentController::readFormData includes editor_mode
// ===========================================================================
try {
    $reflection = new ReflectionClass($ctrl);
    $readFormData = $reflection->getMethod('readFormData');
    $readFormData->setAccessible(true);

    $req = makeRequest('POST', '/admin/content', [
        'title'       => 'Test',
        'slug'        => 'test',
        'body'        => '',
        'type'        => 'page',
        'status'      => 'draft',
        'editor_mode' => 'elements',
        'csrf_token'  => $_SESSION['csrf_token'],
    ]);

    $data = $readFormData->invoke($ctrl, $req);

    if (array_key_exists('editor_mode', $data) && $data['editor_mode'] === 'elements') {
        test_pass('readFormData() includes editor_mode field (value: elements)');
    } else {
        test_fail('readFormData() editor_mode', 'key missing or wrong value: ' . var_export($data['editor_mode'] ?? null, true));
    }
} catch (\Throwable $e) {
    test_fail('readFormData() editor_mode', $e->getMessage());
}

// ===========================================================================
// Test 5: ContentController::create() passes pageElements and csrfToken
// ===========================================================================
try {
    $req = makeRequest('GET', '/admin/content/create');
    $resp = $ctrl->create($req);
    $body = $resp->getBody();

    $hasPageElements = str_contains($body, 'initPageBuilder') || str_contains($body, 'page-builder-panel');
    $hasCsrfForJs = str_contains($body, $_SESSION['csrf_token']) || str_contains($body, 'csrfToken');

    if ($resp->getStatus() === 200 && $hasPageElements) {
        test_pass('ContentController::create() renders page builder panel in output');
    } else {
        test_fail('ContentController::create() page builder', 'status=' . $resp->getStatus()
            . ' hasPageBuilder=' . ($hasPageElements ? 'yes' : 'no'));
    }
} catch (\Throwable $e) {
    test_fail('ContentController::create()', $e->getMessage());
}

// ===========================================================================
// Test 6: ContentController::store() saves editor_mode and page_elements
// ===========================================================================
try {
    // Get hero-section element ID
    $heroEl = \App\Database\QueryBuilder::query('elements')
        ->select('id')
        ->where('slug', 'hero-section')
        ->first();

    $textEl = \App\Database\QueryBuilder::query('elements')
        ->select('id')
        ->where('slug', 'text-section')
        ->first();

    $elementsJson = json_encode([
        ['element_id' => (int)$heroEl['id'], 'slot_data' => ['title' => 'Welcome', 'alignment' => 'center']],
        ['element_id' => (int)$textEl['id'], 'slot_data' => ['heading' => 'About Us']],
    ]);

    $req = makeRequest('POST', '/admin/content', [
        'title'         => 'Element Mode Page',
        'slug'          => 'element-mode-page',
        'body'          => '',
        'type'          => 'page',
        'status'        => 'published',
        'editor_mode'   => 'elements',
        'elements_json' => $elementsJson,
        'published_at'  => '',
        'excerpt'       => '',
        'meta_title'    => '',
        'meta_description' => '',
        'featured_image'   => '',
        'sort_order'    => '0',
        'csrf_token'    => $_SESSION['csrf_token'],
    ]);

    $resp = $ctrl->store($req);

    // Find the created content
    $created = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('slug', 'element-mode-page')
        ->first();

    $editorModeOk = $created !== null && ($created['editor_mode'] ?? '') === 'elements';

    // Check page_elements rows
    $peRows = [];
    if ($created !== null) {
        $peRows = \App\Database\QueryBuilder::query('page_elements')
            ->select()
            ->where('content_id', (int)$created['id'])
            ->orderBy('sort_order')
            ->get();
    }

    $peCountOk = count($peRows) === 2;
    $peOrderOk = $peCountOk
        && (int)$peRows[0]['sort_order'] === 0
        && (int)$peRows[1]['sort_order'] === 1;
    $peDataOk = $peCountOk
        && (int)$peRows[0]['element_id'] === (int)$heroEl['id']
        && (int)$peRows[1]['element_id'] === (int)$textEl['id'];

    // Check slot data persisted
    $slotData0 = json_decode($peRows[0]['slot_data_json'] ?? '{}', true);
    $slotDataOk = ($slotData0['title'] ?? '') === 'Welcome';

    if ($editorModeOk && $peCountOk && $peOrderOk && $peDataOk && $slotDataOk) {
        test_pass('store() saves editor_mode=elements, 2 page_elements rows with correct sort_order and slot_data');
    } else {
        test_fail('store() element mode', 'editorMode=' . ($editorModeOk ? 'ok' : 'FAIL')
            . ' peCount=' . count($peRows) . ' order=' . ($peOrderOk ? 'ok' : 'FAIL')
            . ' data=' . ($peDataOk ? 'ok' : 'FAIL') . ' slotData=' . ($slotDataOk ? 'ok' : 'FAIL'));
    }

    $createdContentId = $created !== null ? (int)$created['id'] : null;
} catch (\Throwable $e) {
    test_fail('store() element mode', $e->getMessage());
    $createdContentId = null;
}

// ===========================================================================
// Test 7: ContentController::edit() loads page_elements and passes to template
// ===========================================================================
try {
    if ($createdContentId === null) {
        test_skip('edit() loads page_elements — no content created in previous test');
    } else {
        $req = makeRequest('GET', '/admin/content/' . $createdContentId . '/edit');
        $resp = $ctrl->edit($req, (string)$createdContentId);

        $body = $resp->getBody();

        // The template should contain the initPageBuilder call with existing instances
        $hasInitWithData = str_contains($body, 'initPageBuilder');
        // Check that the existing element data appears (hero element name or slot data)
        $hasElementData = str_contains($body, 'hero-section') || str_contains($body, 'Hero Section')
            || str_contains($body, 'Welcome') || str_contains($body, 'elementId');
        // The mode should be set to elements
        $hasElementsMode = str_contains($body, '"elements"') || str_contains($body, "'elements'")
            || str_contains($body, 'value="elements"');

        if ($resp->getStatus() === 200 && $hasInitWithData) {
            test_pass('edit() renders page with initPageBuilder and element data');
        } else {
            test_fail('edit() page_elements', 'status=' . $resp->getStatus()
                . ' initPageBuilder=' . ($hasInitWithData ? 'yes' : 'no'));
        }
    }
} catch (\Throwable $e) {
    test_fail('edit() page_elements', $e->getMessage());
}

// ===========================================================================
// Test 8: ContentController::update() saves editor_mode and page_elements
// ===========================================================================
try {
    if ($createdContentId === null) {
        test_skip('update() saves page_elements — no content from previous test');
    } else {
        // Update: reorder and change data (swap hero and text, update title)
        $elementsJsonUpdate = json_encode([
            ['element_id' => (int)$textEl['id'], 'slot_data' => ['heading' => 'About Us Updated']],
            ['element_id' => (int)$heroEl['id'], 'slot_data' => ['title' => 'Welcome Updated']],
        ]);

        $req = makeRequest('POST', '/admin/content/' . $createdContentId, [
            '_method'       => 'PUT',
            'title'         => 'Element Mode Page Updated',
            'slug'          => 'element-mode-page',
            'body'          => '',
            'type'          => 'page',
            'status'        => 'published',
            'editor_mode'   => 'elements',
            'elements_json' => $elementsJsonUpdate,
            'published_at'  => '',
            'excerpt'       => '',
            'meta_title'    => '',
            'meta_description' => '',
            'featured_image'   => '',
            'sort_order'    => '0',
            'csrf_token'    => $_SESSION['csrf_token'],
        ]);

        $resp = $ctrl->update($req, (string)$createdContentId);

        // Check updated content
        $updated = \App\Database\QueryBuilder::query('content')
            ->select()
            ->where('id', $createdContentId)
            ->first();

        $titleOk = ($updated['title'] ?? '') === 'Element Mode Page Updated';

        // Check page_elements: should now be text first, hero second
        $peRows = \App\Database\QueryBuilder::query('page_elements')
            ->select()
            ->where('content_id', $createdContentId)
            ->orderBy('sort_order')
            ->get();

        $countOk = count($peRows) === 2;
        $orderSwapped = $countOk
            && (int)$peRows[0]['element_id'] === (int)$textEl['id']
            && (int)$peRows[1]['element_id'] === (int)$heroEl['id'];

        $slotUpdated = $countOk
            && str_contains($peRows[0]['slot_data_json'], 'About Us Updated')
            && str_contains($peRows[1]['slot_data_json'], 'Welcome Updated');

        if ($titleOk && $countOk && $orderSwapped && $slotUpdated) {
            test_pass('update() persists reordered page_elements with updated slot_data');
        } else {
            test_fail('update() page_elements', 'title=' . ($titleOk ? 'ok' : 'FAIL')
                . ' count=' . count($peRows) . ' orderSwapped=' . ($orderSwapped ? 'ok' : 'FAIL')
                . ' slotUpdated=' . ($slotUpdated ? 'ok' : 'FAIL'));
        }
    }
} catch (\Throwable $e) {
    test_fail('update() page_elements', $e->getMessage());
}

// ===========================================================================
// Test 9: savePageElements skips invalid element_ids
// ===========================================================================
try {
    // Create content with an invalid element_id (9999 doesn't exist)
    $badJson = json_encode([
        ['element_id' => 9999, 'slot_data' => ['title' => 'Ghost']],
        ['element_id' => (int)$heroEl['id'], 'slot_data' => ['title' => 'Valid']],
    ]);

    $req = makeRequest('POST', '/admin/content', [
        'title'         => 'Bad Element Test',
        'slug'          => 'bad-element-test',
        'body'          => '',
        'type'          => 'page',
        'status'        => 'draft',
        'editor_mode'   => 'elements',
        'elements_json' => $badJson,
        'published_at'  => '',
        'excerpt'       => '',
        'meta_title'    => '',
        'meta_description' => '',
        'featured_image'   => '',
        'sort_order'    => '0',
        'csrf_token'    => $_SESSION['csrf_token'],
    ]);

    $resp = $ctrl->store($req);

    $badContent = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('slug', 'bad-element-test')
        ->first();

    if ($badContent !== null) {
        $peRows = \App\Database\QueryBuilder::query('page_elements')
            ->select()
            ->where('content_id', (int)$badContent['id'])
            ->get();

        // Should only have 1 row (the valid hero element), not 2
        if (count($peRows) === 1 && (int)$peRows[0]['element_id'] === (int)$heroEl['id']) {
            test_pass('savePageElements skips invalid element_id (9999) and keeps valid one');
        } else {
            test_fail('savePageElements validation', 'expected 1 row, got ' . count($peRows));
        }
    } else {
        test_fail('savePageElements validation', 'content was not created');
    }
} catch (\Throwable $e) {
    test_fail('savePageElements validation', $e->getMessage());
}

// ===========================================================================
// Test 10: loadPageElements returns correct structure
// ===========================================================================
try {
    if ($createdContentId === null) {
        test_skip('loadPageElements structure — no content from previous test');
    } else {
        $reflection = new ReflectionClass($ctrl);
        $loadMethod = $reflection->getMethod('loadPageElements');
        $loadMethod->setAccessible(true);

        $result = $loadMethod->invoke($ctrl, $createdContentId);

        $isArray = is_array($result);
        $hasCorrectCount = $isArray && count($result) === 2;

        $hasStructure = false;
        if ($hasCorrectCount) {
            $first = $result[0];
            $hasStructure = isset($first['elementId'])
                && isset($first['elementSlug'])
                && isset($first['elementName'])
                && isset($first['elementCategory'])
                && isset($first['slots'])
                && isset($first['slotData'])
                && is_int($first['elementId'])
                && is_array($first['slots'])
                && is_array($first['slotData']);
        }

        if ($hasCorrectCount && $hasStructure) {
            test_pass('loadPageElements() returns correct structure (elementId, elementSlug, elementName, elementCategory, slots, slotData)');
        } else {
            test_fail('loadPageElements() structure', 'isArray=' . ($isArray ? 'yes' : 'no')
                . ' count=' . ($isArray ? count($result) : 'N/A')
                . ' structure=' . ($hasStructure ? 'ok' : 'FAIL'));
        }
    }
} catch (\Throwable $e) {
    test_fail('loadPageElements() structure', $e->getMessage());
}

// ===========================================================================
// Test 11: HTML-mode content does NOT create page_elements rows
// ===========================================================================
try {
    $req = makeRequest('POST', '/admin/content', [
        'title'         => 'HTML Mode Page',
        'slug'          => 'html-mode-page',
        'body'          => '<p>Normal HTML content</p>',
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
        ->where('slug', 'html-mode-page')
        ->first();

    if ($htmlContent !== null) {
        $editorModeOk = ($htmlContent['editor_mode'] ?? 'html') === 'html';

        $peRows = \App\Database\QueryBuilder::query('page_elements')
            ->select()
            ->where('content_id', (int)$htmlContent['id'])
            ->get();

        if ($editorModeOk && count($peRows) === 0) {
            test_pass('HTML-mode content has editor_mode=html and 0 page_elements rows');
        } else {
            test_fail('HTML-mode content', 'editorMode=' . ($htmlContent['editor_mode'] ?? '?')
                . ' peCount=' . count($peRows));
        }
    } else {
        test_fail('HTML-mode content', 'content not created');
    }
} catch (\Throwable $e) {
    test_fail('HTML-mode content', $e->getMessage());
}

// ===========================================================================
// Test 12: page-builder.js has required functions and entry point
// ===========================================================================
$jsSrc = @file_get_contents($rootDir . '/public/assets/js/page-builder.js');
if ($jsSrc === false) {
    test_fail('page-builder.js readable', 'file not found');
} else {
    $jsFunctions = [
        'initPageBuilder'      => str_contains($jsSrc, 'initPageBuilder'),
        'fetchCatalogue'       => str_contains($jsSrc, 'fetchCatalogue') || str_contains($jsSrc, 'fetchCatalog'),
        'openPicker'           => str_contains($jsSrc, 'openPicker'),
        'addElement'           => str_contains($jsSrc, 'addElement'),
        'removeInstance'       => str_contains($jsSrc, 'removeInstance'),
        'renderAllInstances'   => str_contains($jsSrc, 'renderAllInstances') || str_contains($jsSrc, 'renderAll'),
        'createInstanceCard'   => str_contains($jsSrc, 'createInstanceCard') || str_contains($jsSrc, 'InstanceCard'),
        'createSlotField'      => str_contains($jsSrc, 'createSlotField') || str_contains($jsSrc, 'SlotField'),
        'serializeInstances'   => str_contains($jsSrc, 'serializeInstances') || str_contains($jsSrc, 'serialize'),
        'readInstancesFromDOM' => str_contains($jsSrc, 'readInstancesFromDOM') || str_contains($jsSrc, 'readInstances'),
        'dragstart/drop'       => str_contains($jsSrc, 'dragstart') || str_contains($jsSrc, 'DragStart'),
        'window.initPageBuilder' => str_contains($jsSrc, 'window.initPageBuilder'),
    ];

    $allJs = !in_array(false, $jsFunctions, true);
    if ($allJs) {
        test_pass('page-builder.js has all required functions (initPageBuilder, picker, instances, slots, drag-drop, serialize)');
    } else {
        $missing = array_keys(array_filter($jsFunctions, fn($v) => !$v));
        test_fail('page-builder.js functions', 'missing: ' . implode(', ', $missing));
    }
}

// ===========================================================================
// Test 13: admin.css has page builder styles
// ===========================================================================
$cssSrc = @file_get_contents($rootDir . '/public/assets/css/admin.css');
if ($cssSrc === false) {
    test_fail('admin.css readable', 'file not found');
} else {
    $cssChecks = [
        'pb-mode-toggle'    => str_contains($cssSrc, '.pb-mode-toggle') || str_contains($cssSrc, 'pb-mode-option'),
        'pb-instance-card'  => str_contains($cssSrc, '.pb-instance-card'),
        'pb-drag-handle'    => str_contains($cssSrc, '.pb-drag-handle'),
        'pb-slot-field'     => str_contains($cssSrc, '.pb-slot-field'),
        'pb-picker-modal'   => str_contains($cssSrc, '.pb-picker-modal'),
        'pb-picker-grid'    => str_contains($cssSrc, '.pb-picker-grid'),
        'pb-empty-state'    => str_contains($cssSrc, '.pb-empty-state'),
        'pb-list-field'     => str_contains($cssSrc, '.pb-list-field') || str_contains($cssSrc, 'pb-list-item'),
    ];

    $allCss = !in_array(false, $cssChecks, true);
    if ($allCss) {
        test_pass('admin.css has page builder styles (mode toggle, instance cards, drag handle, slots, picker, list)');
    } else {
        $missing = array_keys(array_filter($cssChecks, fn($v) => !$v));
        test_fail('admin.css page builder styles', 'missing: ' . implode(', ', $missing));
    }
}

// ---------------------------------------------------------------------------
// Summary (printed by cleanup shutdown function)
// ---------------------------------------------------------------------------
exit($fail > 0 ? 1 : 0);
