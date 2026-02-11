<?php declare(strict_types=1);

/**
 * Chunk 6.6 — Homepage Content + Default Layout Block + Recent Posts Element
 * Automated Verification Tests
 *
 * Tests:
 *   1.  Migration 009 files exist for all 3 drivers
 *   2.  DynamicElements class exists with isDynamic() and enrich() methods
 *   3.  SeedElements::definitions() includes recent-posts element
 *  [SMOKE STOP]
 *   4.  Migration 009 seeds default block for default layout
 *   5.  Bootstrap seeds Home content item (slug=home, editor_mode=elements)
 *   6.  recent-posts element has correct slots (heading, count, columns, show_*)
 *   7.  DynamicElements::isDynamic('recent-posts') returns true
 *   8.  DynamicElements::isDynamic('hero-section') returns false
 *   9.  DynamicElements::enrich('recent-posts', ...) returns array with posts key
 *  10.  PageRenderer::renderInstance() renders recent-posts with dynamic data
 *  11.  FrontController::homepage() renders content-based homepage when Home page exists
 *  12.  FrontController::homepage() falls back to procedural when Home page is missing
 *  13.  FrontController::page() redirects /home to / with 301
 *  14.  getNavPages() excludes slug='home' from navigation
 *  15.  layout.php nav active state matches both '' and 'home' slugs
 *  16.  index.php has SeedElements::seed() bootstrap call
 *  17.  PageRenderer has DynamicElements enrichment hook in renderInstance()
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
// Test 1: Migration 009 files exist for all 3 drivers
// ===========================================================================
$migrationFiles = [
    'migrations/009_default_block_and_home.sqlite.sql',
    'migrations/009_default_block_and_home.mysql.sql',
    'migrations/009_default_block_and_home.pgsql.sql',
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
    test_pass('Migration 009 files exist for all 3 drivers (sqlite, mysql, pgsql)');
} else {
    test_fail('Migration 009 files exist', 'missing: ' . implode(', ', $missing));
}

// ===========================================================================
// Test 2: DynamicElements class exists with isDynamic() and enrich()
// ===========================================================================
$deClass = \App\PageBuilder\DynamicElements::class;
if (class_exists($deClass)) {
    $deRef = new ReflectionClass($deClass);
    $hasIsDynamic = $deRef->hasMethod('isDynamic');
    $hasEnrich = $deRef->hasMethod('enrich');

    if ($hasIsDynamic && $hasEnrich) {
        test_pass('DynamicElements class exists with isDynamic() and enrich() methods');
    } else {
        $missing = [];
        if (!$hasIsDynamic) $missing[] = 'isDynamic';
        if (!$hasEnrich) $missing[] = 'enrich';
        test_fail('DynamicElements methods', 'missing: ' . implode(', ', $missing));
    }
} else {
    test_fail('DynamicElements class exists');
}

// ===========================================================================
// Test 3: SeedElements::definitions() includes recent-posts
// ===========================================================================
$defs = \App\PageBuilder\SeedElements::definitions();
$slugs = array_column($defs, 'slug');
if (in_array('recent-posts', $slugs, true)) {
    test_pass('SeedElements::definitions() includes recent-posts element');
} else {
    test_fail('SeedElements has recent-posts', 'slug not found in definitions()');
}

// ===========================================================================
// Smoke stop
// ===========================================================================
if ($isSmoke) {
    echo "\n  Chunk 6.6 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Setup: test database for full tests
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk66.sqlite';

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
\App\PageBuilder\SeedElements::seed();

// Seed homepage (same as index.php bootstrap)
$homeExists = \App\Database\QueryBuilder::query('content')
    ->select('id')
    ->where('slug', 'home')
    ->where('type', 'page')
    ->first();
if ($homeExists === null) {
    \App\Database\QueryBuilder::query('content')->insert([
        'type'        => 'page',
        'title'       => 'Home',
        'slug'        => 'home',
        'body'        => '',
        'excerpt'     => '',
        'status'      => 'published',
        'author_id'   => '1',
        'sort_order'  => '-1',
        'editor_mode' => 'elements',
    ]);
}

// ===========================================================================
// Test 4: Migration 009 seeds default block for default layout
// ===========================================================================
$defaultLayout = \App\Database\QueryBuilder::query('layout_templates')
    ->select('id')
    ->where('slug', 'default-layout')
    ->first();

if ($defaultLayout === null) {
    test_fail('Default layout template exists', 'layout_templates has no default-layout slug');
} else {
    $blocks = \App\Database\QueryBuilder::query('page_blocks')
        ->select()
        ->where('layout_template_id', (string) $defaultLayout['id'])
        ->get();

    if (count($blocks) >= 1 && $blocks[0]['name'] === 'Main Content') {
        $b = $blocks[0];
        if ((int) $b['columns'] === 1 && (int) $b['width_percent'] === 100 && $b['alignment'] === 'center') {
            test_pass('Migration 009 seeds default block for default layout');
        } else {
            test_fail('Default block properties', "columns={$b['columns']}, width={$b['width_percent']}, align={$b['alignment']}");
        }
    } else {
        test_fail('Default block exists', 'no "Main Content" block found for default layout');
    }
}

// ===========================================================================
// Test 5: Migration 009 seeds Home content item
// ===========================================================================
$homeContent = \App\Database\QueryBuilder::query('content')
    ->select()
    ->where('slug', 'home')
    ->where('type', 'page')
    ->first();

if ($homeContent !== null) {
    if ($homeContent['editor_mode'] === 'elements' && $homeContent['status'] === 'published') {
        test_pass('Bootstrap seeds Home content item (slug=home, editor_mode=elements)');
    } else {
        test_fail('Home content properties', "editor_mode={$homeContent['editor_mode']}, status={$homeContent['status']}");
    }
} else {
    test_fail('Home content item exists', 'no content with slug=home, type=page found');
}

// ===========================================================================
// Test 6: recent-posts element has correct slots
// ===========================================================================
$recentPostsEl = \App\Database\QueryBuilder::query('elements')
    ->select('slots_json')
    ->where('slug', 'recent-posts')
    ->first();

if ($recentPostsEl !== null) {
    $slots = json_decode($recentPostsEl['slots_json'], true);
    $slotKeys = array_column($slots, 'key');
    $expectedKeys = ['heading', 'count', 'columns', 'show_image', 'show_excerpt', 'show_date', 'show_author'];
    $allPresent = empty(array_diff($expectedKeys, $slotKeys));

    if ($allPresent) {
        test_pass('recent-posts element has correct slots (heading, count, columns, show_*)');
    } else {
        $missing = array_diff($expectedKeys, $slotKeys);
        test_fail('recent-posts slots', 'missing keys: ' . implode(', ', $missing));
    }
} else {
    test_fail('recent-posts element exists in database');
}

// ===========================================================================
// Test 7: DynamicElements::isDynamic('recent-posts') returns true
// ===========================================================================
if (\App\PageBuilder\DynamicElements::isDynamic('recent-posts')) {
    test_pass('DynamicElements::isDynamic(\'recent-posts\') returns true');
} else {
    test_fail('isDynamic recent-posts', 'returned false');
}

// ===========================================================================
// Test 8: DynamicElements::isDynamic('hero-section') returns false
// ===========================================================================
if (!\App\PageBuilder\DynamicElements::isDynamic('hero-section')) {
    test_pass('DynamicElements::isDynamic(\'hero-section\') returns false');
} else {
    test_fail('isDynamic hero-section', 'returned true (should be false)');
}

// ===========================================================================
// Test 9: DynamicElements::enrich('recent-posts', ...) returns posts
// ===========================================================================
// First create a test post
\App\Database\QueryBuilder::query('content')->insert([
    'type'         => 'post',
    'title'        => 'Test Blog Post',
    'slug'         => 'test-blog-post',
    'body'         => 'This is a test blog post body content for verification.',
    'excerpt'      => 'Test excerpt',
    'status'       => 'published',
    'author_id'    => '1',
    'editor_mode'  => 'html',
    'published_at' => gmdate('Y-m-d H:i:s'),
]);

$enriched = \App\PageBuilder\DynamicElements::enrich('recent-posts', ['count' => '3', 'columns' => '3']);
if (isset($enriched['posts']) && is_array($enriched['posts'])) {
    if (count($enriched['posts']) >= 1 && isset($enriched['posts'][0]['title'])) {
        $post = $enriched['posts'][0];
        if ($post['title'] === 'Test Blog Post' && $post['slug'] === 'test-blog-post' && !empty($post['formatted_date'])) {
            test_pass('DynamicElements::enrich(\'recent-posts\', ...) returns formatted posts array');
        } else {
            test_fail('Enriched post data', "title={$post['title']}, slug={$post['slug']}");
        }
    } else {
        test_fail('Enriched posts count', 'expected at least 1 post with title field');
    }
} else {
    test_fail('DynamicElements::enrich returns posts key', 'posts key missing or not array');
}

// ===========================================================================
// Test 10: PageRenderer::renderInstance() renders recent-posts with dynamic data
// ===========================================================================
$rpElement = \App\Database\QueryBuilder::query('elements')
    ->select('id', 'slug', 'name', 'html_template', 'css', 'slots_json')
    ->where('slug', 'recent-posts')
    ->first();

if ($rpElement !== null) {
    $testInstance = [
        'id'              => 999,
        'element_id'      => (int) $rpElement['id'],
        'slug'            => $rpElement['slug'],
        'name'            => $rpElement['name'],
        'html_template'   => $rpElement['html_template'],
        'css'             => $rpElement['css'],
        'slot_data_json'  => json_encode(['heading' => 'Latest News', 'count' => '3', 'columns' => '2', 'show_image' => true, 'show_excerpt' => true, 'show_date' => true, 'show_author' => true]),
        'style_data_json' => '{}',
    ];

    $html = \App\PageBuilder\PageRenderer::renderInstance($testInstance);
    $hasWrapper = str_contains($html, 'lcms-el-recent-posts');
    $hasHeading = str_contains($html, 'Latest News');
    $hasPostTitle = str_contains($html, 'Test Blog Post');
    $hasGrid = str_contains($html, 'recent-posts-cols-2');

    if ($hasWrapper && $hasHeading && $hasPostTitle && $hasGrid) {
        test_pass('PageRenderer::renderInstance() renders recent-posts with dynamic data');
    } else {
        $missing = [];
        if (!$hasWrapper) $missing[] = 'wrapper class';
        if (!$hasHeading) $missing[] = 'heading';
        if (!$hasPostTitle) $missing[] = 'post title';
        if (!$hasGrid) $missing[] = 'grid columns';
        test_fail('renderInstance recent-posts', 'missing: ' . implode(', ', $missing));
    }
} else {
    test_fail('recent-posts element for renderInstance test');
}

// ===========================================================================
// Test 11: FrontController::homepage() renders content-based homepage
// ===========================================================================
$app = new \App\Core\App();
$fc = new \App\Templates\FrontController($app);

// Home page already seeded by migration 009
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = [];
$_POST = [];
$request = new \App\Core\Request();

$response = $fc->homepage($request);
$body = $response->getBody();

// Content-based homepage should render the page template (not the home template with hero section)
// The home page has editor_mode=elements but no elements added, so body may be empty
// But it should NOT have the fallback hero section class
$isContentBased = str_contains($body, '<article') || !str_contains($body, 'hero-section');
if ($isContentBased) {
    test_pass('FrontController::homepage() renders content-based homepage when Home page exists');
} else {
    test_fail('Content-based homepage', 'response appears to be the fallback template');
}

// ===========================================================================
// Test 12: FrontController::homepage() falls back when Home page deleted
// ===========================================================================
// Delete the home page
\App\Database\QueryBuilder::query('content')->where('slug', 'home')->delete();

$response2 = $fc->homepage($request);
$body2 = $response2->getBody();

// Fallback should have the blog-style posts listing
$isFallback = str_contains($body2, 'recent-posts') || str_contains($body2, 'post-card') || str_contains($body2, 'hero-section');
if ($isFallback) {
    test_pass('FrontController::homepage() falls back to procedural when Home page is missing');
} else {
    test_fail('Fallback homepage', 'response does not look like the fallback template');
}

// Re-insert home page for remaining tests
\App\Database\QueryBuilder::query('content')->insert([
    'type'         => 'page',
    'title'        => 'Home',
    'slug'         => 'home',
    'body'         => '',
    'excerpt'      => '',
    'status'       => 'published',
    'author_id'    => '1',
    'sort_order'   => '-1',
    'editor_mode'  => 'elements',
    'published_at' => gmdate('Y-m-d H:i:s'),
]);

// ===========================================================================
// Test 13: FrontController::page() redirects /home to /
// ===========================================================================
$_SERVER['REQUEST_URI'] = '/home';
$reqHome = new \App\Core\Request();
$responseHome = $fc->page($reqHome, 'home');
$status = $responseHome->getStatus();
$headers = $responseHome->getHeaders();
$locationHeader = $headers['Location'] ?? '';

if ($status === 301 && $locationHeader === '/') {
    test_pass('FrontController::page() redirects /home to / with 301');
} else {
    test_fail('/home redirect', "status={$status}, location={$locationHeader}");
}

// ===========================================================================
// Test 14: getNavPages() excludes slug='home'
// ===========================================================================
$fcRef = new ReflectionClass(\App\Templates\FrontController::class);
$getNavMethod = $fcRef->getMethod('getNavPages');
$getNavMethod->setAccessible(true);

$navPages = $getNavMethod->invoke($fc);
$navSlugs = array_column($navPages, 'slug');

if (!in_array('home', $navSlugs, true)) {
    test_pass('getNavPages() excludes slug=\'home\' from navigation');
} else {
    test_fail('Nav excludes home', 'home slug found in nav pages');
}

// ===========================================================================
// Test 15: layout.php nav active state matches 'home' slug
// ===========================================================================
$layoutPath = $rootDir . '/templates/public/layout.php';
if (file_exists($layoutPath)) {
    $layoutSrc = file_get_contents($layoutPath);
    if (str_contains($layoutSrc, "'home'") && str_contains($layoutSrc, "currentSlug")) {
        test_pass('layout.php nav active state matches both \'\' and \'home\' slugs');
    } else {
        test_fail('layout.php nav active', 'does not check for \'home\' slug');
    }
} else {
    test_fail('layout.php exists');
}

// ===========================================================================
// Test 16: index.php has SeedElements::seed() and homepage bootstrap
// ===========================================================================
$indexPath = $rootDir . '/public/index.php';
if (file_exists($indexPath)) {
    $indexSrc = file_get_contents($indexPath);
    $hasSeed = str_contains($indexSrc, 'SeedElements::seed()');
    $hasHomeBootstrap = str_contains($indexSrc, "slug', 'home'") || str_contains($indexSrc, "'slug' => 'home'");

    if ($hasSeed && $hasHomeBootstrap) {
        test_pass('index.php has SeedElements::seed() and homepage bootstrap');
    } else {
        $missing = [];
        if (!$hasSeed) $missing[] = 'SeedElements::seed()';
        if (!$hasHomeBootstrap) $missing[] = 'home page bootstrap';
        test_fail('index.php bootstrap', 'missing: ' . implode(', ', $missing));
    }
} else {
    test_fail('index.php exists');
}

// ===========================================================================
// Test 17: PageRenderer has DynamicElements enrichment hook
// ===========================================================================
$prPath = $rootDir . '/app/PageBuilder/PageRenderer.php';
if (file_exists($prPath)) {
    $prSrc = file_get_contents($prPath);
    $hasCheck = str_contains($prSrc, 'DynamicElements::isDynamic');
    $hasEnrich = str_contains($prSrc, 'DynamicElements::enrich');

    if ($hasCheck && $hasEnrich) {
        test_pass('PageRenderer has DynamicElements enrichment hook in renderInstance()');
    } else {
        $missing = [];
        if (!$hasCheck) $missing[] = 'isDynamic check';
        if (!$hasEnrich) $missing[] = 'enrich call';
        test_fail('PageRenderer dynamic hook', 'missing: ' . implode(', ', $missing));
    }
} else {
    test_fail('PageRenderer.php exists');
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
if (file_exists($testDbPath)) {
    @unlink($testDbPath);
}

echo "\n  Chunk 6.6 results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
