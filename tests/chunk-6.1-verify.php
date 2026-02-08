<?php declare(strict_types=1);

/**
 * Chunk 6.1 — Element Catalogue & Rendering Engine
 * Automated Verification Tests
 *
 * Tests:
 *   1.  SlotRenderer and PageRenderer classes are autoloadable
 *   2.  ElementController class is autoloadable
 *   3.  Required files exist (migrations, templates, JS, CSS)
 *   [SMOKE STOP]
 *   4.  Migration creates elements, page_elements, element_proposals tables + editor_mode column
 *   5.  SeedElements::seed() populates 7 starter elements
 *   6.  SlotRenderer: {{key}} escapes HTML
 *   7.  SlotRenderer: {{{key}}} outputs raw HTML
 *   8.  SlotRenderer: {{#key}}...{{/key}} conditional (truthy shows, falsy hides)
 *   9.  SlotRenderer: {{#items}}...{{/items}} loops over arrays
 *  10.  SlotRenderer: {{^key}}...{{/key}} inverted section (falsy shows, truthy hides)
 *  11.  SlotRenderer: {{key.sub}} dot notation
 *  12.  PageRenderer: renderInstance() wraps in .lcms-el-{slug} div
 *  13.  PageRenderer: renderPage() + getPageCss() for element-based content
 *  14.  ElementController::index() returns 200 with element list
 *  15.  ElementController::store() creates element and redirects
 *  16.  ElementController::edit() renders editor with existing data
 *  17.  ElementController::update() persists changes and increments version
 *  18.  ElementController::delete() blocks when element is in use
 *  19.  ElementController::delete() succeeds when element has no usage
 *  20.  ElementController::preview() returns JSON with rendered HTML and CSS
 *  21.  ElementController::apiList() returns JSON with active elements
 *  22.  Validation rejects invalid slot JSON (duplicate keys)
 *  23.  FrontController renders element-mode pages with PageRenderer
 *  24.  Public layout has elementCss style block
 *  25.  Admin layout has Elements nav link
 *  26.  Routes registered in index.php
 *  27.  admin.css has element catalogue and editor styles
 *  28.  element-editor.js has required functions
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
// Setup: test database + autoloader
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk61.sqlite';

if (file_exists($testDbPath)) {
    @unlink($testDbPath);
}

$autoloadPath = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found — run composer install');
    echo "\n[FAIL] Cannot continue — autoloader missing\n";
    exit(1);
}
require_once $autoloadPath;

// Override config to use the test database
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

// Run migrations on the test database
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

    echo "\n  Chunk 6.1 results: {$pass} passed, {$fail} failed\n";
}

register_shutdown_function('cleanup');

// ===========================================================================
// Test 1: SlotRenderer and PageRenderer are autoloadable
// ===========================================================================
$slotRendererLoadable = class_exists(\App\PageBuilder\SlotRenderer::class);
$pageRendererLoadable = class_exists(\App\PageBuilder\PageRenderer::class);
$seedElementsLoadable = class_exists(\App\PageBuilder\SeedElements::class);

if ($slotRendererLoadable && $pageRendererLoadable && $seedElementsLoadable) {
    test_pass('All PageBuilder classes are autoloadable (SlotRenderer, PageRenderer, SeedElements)');
} else {
    test_fail('PageBuilder classes autoloadable', 'SlotRenderer=' . ($slotRendererLoadable ? 'ok' : 'MISSING')
        . ' PageRenderer=' . ($pageRendererLoadable ? 'ok' : 'MISSING')
        . ' SeedElements=' . ($seedElementsLoadable ? 'ok' : 'MISSING'));
}

// ===========================================================================
// Test 2: ElementController is autoloadable
// ===========================================================================
if (class_exists(\App\Admin\ElementController::class)) {
    test_pass('ElementController is autoloadable');
} else {
    test_fail('ElementController is autoloadable');
}

// ===========================================================================
// Test 3: Required files exist
// ===========================================================================
$requiredFiles = [
    'migrations/004_page_builder.sqlite.sql',
    'migrations/004_page_builder.mysql.sql',
    'migrations/004_page_builder.pgsql.sql',
    'templates/admin/elements/index.php',
    'templates/admin/elements/edit.php',
    'public/assets/js/element-editor.js',
    'app/PageBuilder/SlotRenderer.php',
    'app/PageBuilder/PageRenderer.php',
    'app/PageBuilder/SeedElements.php',
    'app/Admin/ElementController.php',
];

$allExist = true;
$missing = [];
foreach ($requiredFiles as $file) {
    $fullPath = $rootDir . '/' . $file;
    if (!file_exists($fullPath)) {
        $allExist = false;
        $missing[] = $file;
    }
}

if ($allExist) {
    test_pass('All required files exist: migrations, templates, JS, controllers');
} else {
    test_fail('Required files exist', 'missing: ' . implode(', ', $missing));
}

// --- Smoke stop ---
if ($isSmoke) {
    return;
}

// ===========================================================================
// Test 4: Migration creates required tables and columns
// ===========================================================================
$tables = ['elements', 'page_elements', 'element_proposals'];
$tablesOk = true;
foreach ($tables as $table) {
    $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
    if ($check !== $table) {
        $tablesOk = false;
    }
}

// Check editor_mode column on content
$contentCols = $pdo->query("PRAGMA table_info(content)")->fetchAll(\PDO::FETCH_ASSOC);
$hasEditorMode = false;
foreach ($contentCols as $col) {
    if ($col['name'] === 'editor_mode') {
        $hasEditorMode = true;
    }
}

if ($tablesOk && $hasEditorMode) {
    test_pass('Migration creates elements, page_elements, element_proposals tables + editor_mode column');
} else {
    test_fail('Migration tables/columns', 'tables=' . ($tablesOk ? 'ok' : 'MISSING')
        . ' editor_mode=' . ($hasEditorMode ? 'ok' : 'MISSING'));
}

// ===========================================================================
// Test 5: SeedElements::seed() populates 7 starter elements
// ===========================================================================
$seededCount = \App\PageBuilder\SeedElements::seed();
$totalElements = \App\Database\QueryBuilder::query('elements')->select()->count();

if ($totalElements === 7) {
    test_pass('SeedElements::seed() populates 7 starter elements');
} else {
    test_fail('SeedElements::seed()', "expected 7 elements, got {$totalElements}");
}

// Verify idempotence
$seededCount2 = \App\PageBuilder\SeedElements::seed();
$totalElements2 = \App\Database\QueryBuilder::query('elements')->select()->count();

if ($seededCount2 === 0 && $totalElements2 === 7) {
    test_pass('SeedElements::seed() is idempotent (0 new on second call)');
} else {
    test_fail('SeedElements idempotent', "seeded={$seededCount2}, total={$totalElements2}");
}

// ===========================================================================
// Test 6: SlotRenderer: {{key}} escapes HTML
// ===========================================================================
$rendered = \App\PageBuilder\SlotRenderer::render(
    '<h1>{{title}}</h1>',
    ['title' => '<script>alert("xss")</script>']
);
if (str_contains($rendered, '&lt;script&gt;') && !str_contains($rendered, '<script>')) {
    test_pass('SlotRenderer: {{key}} escapes HTML (XSS protection)');
} else {
    test_fail('SlotRenderer {{key}} escaping', "got: {$rendered}");
}

// ===========================================================================
// Test 7: SlotRenderer: {{{key}}} outputs raw HTML
// ===========================================================================
$rawHtml = '<p>Hello <strong>World</strong></p>';
$rendered = \App\PageBuilder\SlotRenderer::render('{{{body}}}', ['body' => $rawHtml]);
if ($rendered === $rawHtml) {
    test_pass('SlotRenderer: {{{key}}} outputs raw HTML');
} else {
    test_fail('SlotRenderer {{{key}}} raw output', "expected: {$rawHtml}, got: {$rendered}");
}

// ===========================================================================
// Test 8: SlotRenderer: {{#key}}...{{/key}} conditional
// ===========================================================================
$template = '{{#show}}Visible{{/show}}{{^show}}Hidden{{/show}}';

$resultTrue = \App\PageBuilder\SlotRenderer::render($template, ['show' => true]);
$resultFalse = \App\PageBuilder\SlotRenderer::render($template, ['show' => false]);

if ($resultTrue === 'Visible' && $resultFalse === 'Hidden') {
    test_pass('SlotRenderer: {{#key}}...{{/key}} conditional (truthy shows, falsy hides)');
} else {
    test_fail('SlotRenderer conditional', "truthy=\"{$resultTrue}\", falsy=\"{$resultFalse}\"");
}

// ===========================================================================
// Test 9: SlotRenderer: {{#items}}...{{/items}} loops over arrays
// ===========================================================================
$template = '<ul>{{#items}}<li>{{name}}</li>{{/items}}</ul>';
$data = ['items' => [
    ['name' => 'Alpha'],
    ['name' => 'Beta'],
    ['name' => 'Gamma'],
]];
$rendered = \App\PageBuilder\SlotRenderer::render($template, $data);
if (str_contains($rendered, '<li>Alpha</li>') && str_contains($rendered, '<li>Beta</li>') && str_contains($rendered, '<li>Gamma</li>')) {
    test_pass('SlotRenderer: {{#items}}...{{/items}} loops over arrays');
} else {
    test_fail('SlotRenderer loop', "got: {$rendered}");
}

// ===========================================================================
// Test 10: SlotRenderer: {{^key}}...{{/key}} inverted section
// ===========================================================================
$template = '{{^items}}No items{{/items}}';
$renderedEmpty = \App\PageBuilder\SlotRenderer::render($template, ['items' => []]);
$renderedFull = \App\PageBuilder\SlotRenderer::render($template, ['items' => [['x' => 1]]]);

if (trim($renderedEmpty) === 'No items' && trim($renderedFull) === '') {
    test_pass('SlotRenderer: {{^key}}...{{/key}} inverted section');
} else {
    test_fail('SlotRenderer inverted section', "empty=\"{$renderedEmpty}\", full=\"{$renderedFull}\"");
}

// ===========================================================================
// Test 11: SlotRenderer: {{key.sub}} dot notation
// ===========================================================================
$rendered = \App\PageBuilder\SlotRenderer::render(
    '<a href="{{cta.url}}">{{cta.text}}</a>',
    ['cta' => ['url' => 'https://example.com', 'text' => 'Click Me']]
);
if (str_contains($rendered, 'href="https://example.com"') && str_contains($rendered, '>Click Me</a>')) {
    test_pass('SlotRenderer: {{key.sub}} dot notation resolves nested values');
} else {
    test_fail('SlotRenderer dot notation', "got: {$rendered}");
}

// ===========================================================================
// Test 12: PageRenderer::renderInstance() wraps in .lcms-el-{slug}
// ===========================================================================
$instance = [
    'slug' => 'test-element',
    'element_id' => 99,
    'html_template' => '<p>{{text}}</p>',
    'slot_data' => ['text' => 'Hello'],
    'css' => '.lcms-el-test-element { color: red; }',
];
$html = \App\PageBuilder\PageRenderer::renderInstance($instance);
if (str_contains($html, 'class="lcms-el lcms-el-test-element"')
    && str_contains($html, 'data-element-id="99"')
    && str_contains($html, '<p>Hello</p>')) {
    test_pass('PageRenderer::renderInstance() wraps in .lcms-el-{slug} div with data-element-id');
} else {
    test_fail('PageRenderer::renderInstance()', "got: " . substr($html, 0, 200));
}

// ===========================================================================
// Test 13: PageRenderer full render for element-based content
// ===========================================================================
// Create a test content page with editor_mode=elements
$contentId = \App\Database\QueryBuilder::query('content')->insert([
    'title'       => 'Element Test Page',
    'slug'        => 'element-test',
    'body'        => '',
    'type'        => 'page',
    'status'      => 'published',
    'editor_mode' => 'elements',
    'author_id'   => 1,
    'published_at' => date('Y-m-d H:i:s'),
]);

// Get hero-section element ID
$heroEl = \App\Database\QueryBuilder::query('elements')
    ->select('id', 'css')
    ->where('slug', 'hero-section')
    ->first();

$textEl = \App\Database\QueryBuilder::query('elements')
    ->select('id', 'css')
    ->where('slug', 'text-section')
    ->first();

// Insert page_elements
\App\Database\QueryBuilder::query('page_elements')->insert([
    'content_id'    => $contentId,
    'element_id'    => (int)$heroEl['id'],
    'sort_order'    => 0,
    'slot_data_json' => json_encode(['title' => 'Welcome', 'description' => '<p>Hello</p>']),
]);

\App\Database\QueryBuilder::query('page_elements')->insert([
    'content_id'    => $contentId,
    'element_id'    => (int)$textEl['id'],
    'sort_order'    => 1,
    'slot_data_json' => json_encode(['heading' => 'About Us', 'body' => '<p>Content here.</p>']),
]);

$contentId = (int) $contentId;
$pageHtml = \App\PageBuilder\PageRenderer::renderPage($contentId);
$pageCss = \App\PageBuilder\PageRenderer::getPageCss($contentId);

$htmlOk = str_contains($pageHtml, 'lcms-el-hero-section') && str_contains($pageHtml, 'lcms-el-text-section');
$cssOk = str_contains($pageCss, 'lcms-el-hero-section') && str_contains($pageCss, 'lcms-el-text-section');

if ($htmlOk && $cssOk) {
    test_pass('PageRenderer::renderPage() + getPageCss() assembles element-based page with HTML + CSS');
} else {
    test_fail('PageRenderer full render', 'htmlOk=' . ($htmlOk ? 'yes' : 'no') . ' cssOk=' . ($cssOk ? 'yes' : 'no'));
}

// ===========================================================================
// Test 14: ElementController::index() returns 200
// ===========================================================================
$app = new \App\Core\App();
$ctrl = new \App\Admin\ElementController($app);

$req = makeRequest('GET', '/admin/elements');
$resp = $ctrl->index($req);

if ($resp->getStatus() === 200) {
    test_pass('ElementController::index() returns 200 with element list');
} else {
    test_fail('ElementController::index()', 'status=' . $resp->getStatus());
}

// Check index output contains seed elements
$body = $resp->getBody();
if (str_contains($body, 'Hero Section') && str_contains($body, 'Feature Grid')) {
    test_pass('Element index page lists seed elements (Hero Section, Feature Grid)');
} else {
    test_fail('Element index content', 'missing seed element names');
}

// ===========================================================================
// Test 15: ElementController::store() creates element
// ===========================================================================
$req = makeRequest('POST', '/admin/elements', [
    'name'          => 'Test Card',
    'slug'          => 'test-card',
    'description'   => 'A test element',
    'category'      => 'testing',
    'html_template' => '<div>{{title}}</div>',
    'css'           => '.lcms-el-test-card { padding: 1rem; }',
    'slots_json'    => json_encode([['key' => 'title', 'label' => 'Title', 'type' => 'text']]),
    'status'        => 'active',
    'csrf_token'    => $_SESSION['csrf_token'],
]);

$resp = $ctrl->store($req);
$status = $resp->getStatus();
$headers = $resp->getHeaders();
$location = $headers['Location'] ?? '';

if ($status === 302 && str_contains($location, '/admin/elements/')) {
    test_pass('ElementController::store() creates element and redirects to edit page');
} else {
    test_fail('ElementController::store()', "status={$status}, location={$location}");
}

// Verify persisted
$testCard = \App\Database\QueryBuilder::query('elements')->select()->where('slug', 'test-card')->first();
if ($testCard !== null && $testCard['name'] === 'Test Card' && $testCard['category'] === 'testing') {
    test_pass('Element persisted in database with correct name, slug, and category');
} else {
    test_fail('Element persistence', $testCard === null ? 'not found' : 'wrong data');
}

// ===========================================================================
// Test 16: ElementController::edit() renders editor
// ===========================================================================
$testCardId = (string) $testCard['id'];
$req = makeRequest('GET', '/admin/elements/' . $testCardId . '/edit');
$resp = $ctrl->edit($req, $testCardId);

if ($resp->getStatus() === 200 && str_contains($resp->getBody(), 'Test Card')) {
    test_pass('ElementController::edit() renders editor with existing element data');
} else {
    test_fail('ElementController::edit()', 'status=' . $resp->getStatus());
}

// ===========================================================================
// Test 17: ElementController::update() persists changes
// ===========================================================================
$req = makeRequest('POST', '/admin/elements/' . $testCardId, [
    '_method'       => 'PUT',
    'name'          => 'Test Card Updated',
    'slug'          => 'test-card',
    'description'   => 'Updated description',
    'category'      => 'testing',
    'html_template' => '<div>{{title}} - Updated</div>',
    'css'           => '.lcms-el-test-card { padding: 2rem; }',
    'slots_json'    => json_encode([['key' => 'title', 'label' => 'Title', 'type' => 'text']]),
    'status'        => 'active',
    'csrf_token'    => $_SESSION['csrf_token'],
]);

$resp = $ctrl->update($req, $testCardId);
$updated = \App\Database\QueryBuilder::query('elements')->select()->where('id', (int)$testCardId)->first();

if ($updated['name'] === 'Test Card Updated' && (int)$updated['version'] === 2) {
    test_pass('ElementController::update() persists changes and increments version');
} else {
    test_fail('ElementController::update()', 'name=' . ($updated['name'] ?? '?') . ' version=' . ($updated['version'] ?? '?'));
}

// ===========================================================================
// Test 18: ElementController::delete() blocks when in use
// ===========================================================================
// Use the hero element which has page_elements referencing it
$heroId = (string) $heroEl['id'];
$req = makeRequest('POST', '/admin/elements/' . $heroId, [
    '_method' => 'DELETE',
    'csrf_token' => $_SESSION['csrf_token'],
]);

$resp = $ctrl->delete($req, $heroId);
$heroStillExists = \App\Database\QueryBuilder::query('elements')->select()->where('id', (int)$heroId)->first();

if ($heroStillExists !== null && $resp->getStatus() === 302) {
    test_pass('ElementController::delete() blocks deletion when element is in use');
} else {
    test_fail('ElementController::delete() blocks', $heroStillExists === null ? 'element was deleted!' : 'unexpected');
}

// ===========================================================================
// Test 19: ElementController::delete() succeeds when unused
// ===========================================================================
$req = makeRequest('POST', '/admin/elements/' . $testCardId, [
    '_method' => 'DELETE',
    'csrf_token' => $_SESSION['csrf_token'],
]);

$resp = $ctrl->delete($req, $testCardId);
$deleted = \App\Database\QueryBuilder::query('elements')->select()->where('id', (int)$testCardId)->first();

if ($deleted === null) {
    test_pass('ElementController::delete() succeeds when element has no usage');
} else {
    test_fail('ElementController::delete() unused', 'element still exists');
}

// ===========================================================================
// Test 20: ElementController::preview() returns JSON
// ===========================================================================
$req = makeRequest('GET', '/admin/elements/' . $heroId . '/preview');
$resp = $ctrl->preview($req, $heroId);
$json = json_decode($resp->getBody(), true);

if (($json['success'] ?? false) === true && !empty($json['html']) && isset($json['css'])) {
    test_pass('ElementController::preview() returns JSON with rendered HTML and CSS');
} else {
    test_fail('ElementController::preview()', 'response: ' . substr($resp->getBody(), 0, 200));
}

// ===========================================================================
// Test 21: ElementController::apiList() returns active elements
// ===========================================================================
$req = makeRequest('GET', '/admin/elements/api/list');
$resp = $ctrl->apiList($req);
$json = json_decode($resp->getBody(), true);

$apiCount = count($json['elements'] ?? []);
if (($json['success'] ?? false) === true && $apiCount >= 7) {
    test_pass('ElementController::apiList() returns JSON with active elements (' . $apiCount . ')');
} else {
    test_fail('ElementController::apiList()', "count={$apiCount}");
}

// ===========================================================================
// Test 22: Validation rejects duplicate slot keys
// ===========================================================================
$req = makeRequest('POST', '/admin/elements', [
    'name'          => 'Bad Element',
    'slug'          => 'bad-element',
    'description'   => '',
    'category'      => 'general',
    'html_template' => '<div>{{title}}</div>',
    'css'           => '',
    'slots_json'    => json_encode([
        ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
        ['key' => 'title', 'label' => 'Title 2', 'type' => 'text'],
    ]),
    'status'        => 'active',
    'csrf_token'    => $_SESSION['csrf_token'],
]);

$resp = $ctrl->store($req);
$badElement = \App\Database\QueryBuilder::query('elements')->select()->where('slug', 'bad-element')->first();

if ($badElement === null && $resp->getStatus() === 302) {
    test_pass('Validation rejects duplicate slot keys — element not created');
} else {
    test_fail('Validation duplicate keys', $badElement !== null ? 'element was created' : 'unexpected status');
    // cleanup
    if ($badElement !== null) {
        \App\Database\QueryBuilder::query('elements')->where('id', (int)$badElement['id'])->delete();
    }
}

// ===========================================================================
// Test 23: FrontController element-mode rendering branch
// ===========================================================================
$frontControllerSrc = file_get_contents($rootDir . '/app/Templates/FrontController.php');
$hasPageRendererImport = str_contains($frontControllerSrc, 'use App\\PageBuilder\\PageRenderer');
$hasEditorModeCheck = str_contains($frontControllerSrc, "editor_mode") && str_contains($frontControllerSrc, 'PageRenderer::renderPage');

if ($hasPageRendererImport && $hasEditorModeCheck) {
    test_pass('FrontController has element-mode rendering branch (PageRenderer import + editor_mode check)');
} else {
    test_fail('FrontController element mode', 'import=' . ($hasPageRendererImport ? 'yes' : 'no')
        . ' check=' . ($hasEditorModeCheck ? 'yes' : 'no'));
}

// ===========================================================================
// Test 24: Public layout has elementCss style block
// ===========================================================================
$layoutSrc = file_get_contents($rootDir . '/templates/public/layout.php');
if (str_contains($layoutSrc, 'elementCss') && str_contains($layoutSrc, 'litecms-element-styles')) {
    test_pass('Public layout has elementCss style block in <head>');
} else {
    test_fail('Public layout elementCss', 'missing elementCss or litecms-element-styles');
}

// ===========================================================================
// Test 25: Admin layout has Elements nav link
// ===========================================================================
$adminLayoutSrc = file_get_contents($rootDir . '/templates/admin/layout.php');
if (str_contains($adminLayoutSrc, '/admin/elements') && str_contains($adminLayoutSrc, 'Elements')) {
    test_pass('Admin layout has "Elements" nav link pointing to /admin/elements');
} else {
    test_fail('Admin layout Elements nav', 'missing nav link');
}

// ===========================================================================
// Test 26: Routes registered in index.php
// ===========================================================================
$indexSrc = file_get_contents($rootDir . '/public/index.php');
$routeChecks = [
    'ElementController' => str_contains($indexSrc, 'ElementController'),
    'GET /elements' => str_contains($indexSrc, "'/elements'"),
    'POST /elements' => str_contains($indexSrc, "'/elements'"),
    'preview route' => str_contains($indexSrc, 'preview'),
    'apiList route' => str_contains($indexSrc, 'apiList'),
];

$allRoutes = !in_array(false, $routeChecks, true);
if ($allRoutes) {
    test_pass('index.php registers ElementController import and all element routes (CRUD + preview + apiList)');
} else {
    $missing = array_keys(array_filter($routeChecks, fn($v) => !$v));
    test_fail('Element routes', 'missing: ' . implode(', ', $missing));
}

// ===========================================================================
// Test 27: admin.css has element catalogue and editor styles
// ===========================================================================
$cssSrc = file_get_contents($rootDir . '/public/assets/css/admin.css');
$cssChecks = [
    'elements-grid'    => str_contains($cssSrc, '.elements-grid'),
    'element-card'     => str_contains($cssSrc, '.element-card'),
    'element-editor'   => str_contains($cssSrc, '.element-editor-grid'),
    'code-editor'      => str_contains($cssSrc, '.code-editor'),
    'slot-item'        => str_contains($cssSrc, '.slot-item'),
    'preview-container'=> str_contains($cssSrc, '.element-preview-container'),
];

$allCss = !in_array(false, $cssChecks, true);
if ($allCss) {
    test_pass('admin.css has element catalogue and editor styles (grid, cards, editor, code, slots, preview)');
} else {
    $missing = array_keys(array_filter($cssChecks, fn($v) => !$v));
    test_fail('admin.css element styles', 'missing: ' . implode(', ', $missing));
}

// ===========================================================================
// Test 28: element-editor.js has required functions
// ===========================================================================
$jsSrc = file_get_contents($rootDir . '/public/assets/js/element-editor.js');
$jsFunctions = [
    'initElementEditor' => str_contains($jsSrc, 'initElementEditor'),
    'createSlotRow'     => str_contains($jsSrc, 'createSlotRow'),
    'slot-remove-btn'   => str_contains($jsSrc, 'slot-remove-btn'),
    'serializeSlots'    => str_contains($jsSrc, 'serializeSlots'),
    'add-slot-btn'      => str_contains($jsSrc, 'add-slot-btn'),
    'refreshPreview'    => str_contains($jsSrc, 'refreshPreview'),
];

$allJs = !in_array(false, $jsFunctions, true);
if ($allJs) {
    test_pass('element-editor.js has required functions (initElementEditor, createSlotRow, serializeSlots, refreshPreview)');
} else {
    $missing = array_keys(array_filter($jsFunctions, fn($v) => !$v));
    test_fail('element-editor.js functions', 'missing: ' . implode(', ', $missing));
}
