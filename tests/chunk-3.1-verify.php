<?php declare(strict_types=1);

/**
 * Chunk 3.1 — Template Engine & Front Controller
 * Automated Verification Tests
 *
 * Tests:
 *   1. FrontController class is autoloadable
 *   2. Required files exist (controller, templates)
 *   3. TemplateEngine has new methods (sections, metaTags, navigation, breadcrumbs)
 *   [SMOKE STOP]
 *   4. TemplateEngine section/yield system works
 *   5. TemplateEngine::metaTags() generates correct SEO HTML
 *   6. TemplateEngine::navigation() generates nav list with active state
 *   7. TemplateEngine::breadcrumbs() generates accessible breadcrumb HTML
 *   8. FrontController::homepage() renders with 200 status
 *   9. FrontController::page() returns published page by slug
 *  10. FrontController::page() returns 404 for draft page
 *  11. FrontController::page() returns 404 for archived page
 *  12. FrontController::blogPost() returns published post by slug
 *  13. FrontController::blogPost() returns 404 for future-scheduled post
 *  14. FrontController::page() redirects posts to /blog/{slug} (301)
 *  15. FrontController::blogIndex() renders with pagination data
 *  16. FrontController::notFound() returns 404 status with styled page
 *  17. Navigation includes published pages sorted by sort_order
 *  18. SEO meta tags present in rendered page output
 *  19. Blog post meta includes og:type article
 *  20. Homepage shows recent posts
 *  21. App 404 fallback uses styled template (not raw HTML)
 *  22. Public routes registered in index.php
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
// Setup: test database + autoloader
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk31.sqlite';

// Clean up any previous test database
if (file_exists($testDbPath)) {
    unlink($testDbPath);
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

// Start session (needed by templates and Session::flash)
if (session_status() !== PHP_SESSION_ACTIVE) {
    \App\Auth\Session::start();
}

// Set up session data to simulate logged-in admin
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'admin';
$_SESSION['user_role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Create default admin user for the test database
\App\Database\QueryBuilder::query('users')->insert([
    'username'      => 'admin',
    'email'         => 'admin@localhost',
    'password_hash' => password_hash('admin', PASSWORD_BCRYPT),
    'role'          => 'admin',
]);

// Helper: create a simulated Request
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

// Helper: cleanup function
function cleanup(): void
{
    global $testDbPath, $configProp, $pass, $fail;

    $pdo = null;
    \App\Database\Connection::reset();
    $configProp->setValue(null, null);
    putenv('DB_DRIVER');
    putenv('DB_PATH');

    usleep(100000);
    if (file_exists($testDbPath)) { @unlink($testDbPath); }
    foreach ([$testDbPath . '-wal', $testDbPath . '-shm'] as $f) {
        if (file_exists($f)) { @unlink($f); }
    }

    echo "\n";
    echo "Chunk 3.1 results: {$pass} passed, {$fail} failed\n";
}

// Helper: insert test content directly via QueryBuilder
function insertContent(array $data): int
{
    $defaults = [
        'type'             => 'page',
        'title'            => 'Test Page',
        'slug'             => 'test-page',
        'body'             => '<p>Test body content.</p>',
        'excerpt'          => 'Test excerpt',
        'status'           => 'published',
        'author_id'        => 1,
        'template'         => '',
        'sort_order'       => 0,
        'meta_title'       => '',
        'meta_description' => '',
        'featured_image'   => '',
        'published_at'     => gmdate('Y-m-d H:i:s', strtotime('-1 hour')),
    ];

    $row = array_merge($defaults, $data);
    return (int) \App\Database\QueryBuilder::query('content')->insert($row);
}

// ---------------------------------------------------------------------------
// Test 1: FrontController class is autoloadable
// ---------------------------------------------------------------------------
$frontControllerClass = 'App\\Templates\\FrontController';

if (!class_exists($frontControllerClass)) {
    test_fail('FrontController is autoloadable', "class {$frontControllerClass} not found");
    cleanup();
    exit(1);
} else {
    test_pass('FrontController is autoloadable');
}

// ---------------------------------------------------------------------------
// Test 2: Required files exist (controller, templates)
// ---------------------------------------------------------------------------
$requiredFiles = [
    'app/Templates/FrontController.php'  => 'FrontController class file',
    'templates/public/layout.php'        => 'Public layout template',
    'templates/public/home.php'          => 'Homepage template',
    'templates/public/404.php'           => '404 error template',
];

$allFilesExist = true;
foreach ($requiredFiles as $file => $label) {
    $path = $rootDir . '/' . $file;
    if (!file_exists($path)) {
        test_fail("{$label} exists ({$file})");
        $allFilesExist = false;
    }
}
if ($allFilesExist) {
    test_pass('All required files exist: FrontController.php, layout.php, home.php, 404.php');
}

// ---------------------------------------------------------------------------
// Test 3: TemplateEngine has new methods
// ---------------------------------------------------------------------------
$engine = new \App\Templates\TemplateEngine($rootDir . '/templates');

$newMethods = ['startSection', 'endSection', 'yieldSection', 'hasSection', 'metaTags', 'navigation', 'breadcrumbs'];
$allMethodsExist = true;
foreach ($newMethods as $method) {
    if (!method_exists($engine, $method)) {
        test_fail("TemplateEngine::{$method}() exists", 'method not found');
        $allMethodsExist = false;
    }
}
if ($allMethodsExist) {
    test_pass('TemplateEngine has all new methods: startSection, endSection, yieldSection, hasSection, metaTags, navigation, breadcrumbs');
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    cleanup();
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: TemplateEngine section/yield system works
// ---------------------------------------------------------------------------
try {
    $engine->startSection('test_section');
    echo 'Hello from section';
    $engine->endSection();

    $content = $engine->yieldSection('test_section');
    if ($content === 'Hello from section') {
        test_pass('Section/yield system captures and yields content correctly');
    } else {
        test_fail('Section/yield captures content', "expected 'Hello from section', got: " . var_export($content, true));
    }

    // Test hasSection
    if ($engine->hasSection('test_section')) {
        test_pass('hasSection() returns true for defined section');
    } else {
        test_fail('hasSection() returns true for defined section');
    }

    // Test yieldSection with default
    $defaultContent = $engine->yieldSection('nonexistent_section', 'default_value');
    if ($defaultContent === 'default_value') {
        test_pass('yieldSection() returns default for undefined section');
    } else {
        test_fail('yieldSection() returns default', "got: " . var_export($defaultContent, true));
    }
} catch (\Throwable $e) {
    test_fail('Section/yield system works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: TemplateEngine::metaTags() generates correct SEO HTML
// ---------------------------------------------------------------------------
try {
    $meta = [
        'title'       => 'Test Page Title',
        'description' => 'A test description for SEO.',
        'canonical'   => 'http://localhost/test-page',
        'og_title'    => 'Test OG Title',
        'og_type'     => 'website',
        'og_url'      => 'http://localhost/test-page',
        'og_image'    => 'http://localhost/assets/uploads/test.jpg',
    ];

    $html = $engine->metaTags($meta);

    $checks = [
        ['str' => 'meta name="description"',    'label' => 'meta description tag'],
        ['str' => 'A test description for SEO.', 'label' => 'description content'],
        ['str' => 'rel="canonical"',             'label' => 'canonical link'],
        ['str' => 'og:title',                    'label' => 'og:title tag'],
        ['str' => 'og:type',                     'label' => 'og:type tag'],
        ['str' => 'og:url',                      'label' => 'og:url tag'],
        ['str' => 'og:image',                    'label' => 'og:image tag'],
    ];

    $allPresent = true;
    foreach ($checks as $check) {
        if (!str_contains($html, $check['str'])) {
            test_fail("metaTags() includes {$check['label']}", "'{$check['str']}' not found");
            $allPresent = false;
        }
    }
    if ($allPresent) {
        test_pass('metaTags() generates all SEO tags: description, canonical, og:title, og:type, og:url, og:image');
    }

    // Test escaping
    $xssMeta = ['description' => '<script>alert("xss")</script>'];
    $xssHtml = $engine->metaTags($xssMeta);
    if (!str_contains($xssHtml, '<script>')) {
        test_pass('metaTags() escapes HTML in values (XSS protection)');
    } else {
        test_fail('metaTags() escapes HTML', 'raw <script> tag found');
    }

    // Test empty values are skipped
    $emptyMeta = ['description' => '', 'og_image' => ''];
    $emptyHtml = $engine->metaTags($emptyMeta);
    if (!str_contains($emptyHtml, 'meta name="description"') && !str_contains($emptyHtml, 'og:image')) {
        test_pass('metaTags() skips empty values');
    } else {
        test_fail('metaTags() skips empty values', 'empty tags were output');
    }
} catch (\Throwable $e) {
    test_fail('metaTags() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: TemplateEngine::navigation() generates nav with active state
// ---------------------------------------------------------------------------
try {
    $pages = [
        ['title' => 'About', 'slug' => 'about'],
        ['title' => 'Services', 'slug' => 'services'],
        ['title' => 'Contact', 'slug' => 'contact'],
    ];

    $navHtml = $engine->navigation($pages, 'services');

    if (str_contains($navHtml, '<ul') && str_contains($navHtml, '</ul>')) {
        test_pass('navigation() generates <ul> list');
    } else {
        test_fail('navigation() generates <ul> list');
    }

    if (str_contains($navHtml, 'href="/about"') && str_contains($navHtml, 'About')) {
        test_pass('navigation() renders page links with correct href and title');
    } else {
        test_fail('navigation() renders page links', 'href or title missing');
    }

    // Active state on the matching slug
    if (preg_match('/<li[^>]*class="active"[^>]*>.*?services/i', $navHtml)) {
        test_pass('navigation() marks matching slug with class="active"');
    } else {
        test_fail('navigation() active state', 'class="active" not found on services item');
    }

    // Empty pages
    $emptyNav = $engine->navigation([]);
    if ($emptyNav === '') {
        test_pass('navigation() returns empty string for no pages');
    } else {
        test_fail('navigation() returns empty for no pages', "got: " . var_export($emptyNav, true));
    }
} catch (\Throwable $e) {
    test_fail('navigation() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: TemplateEngine::breadcrumbs() generates accessible HTML
// ---------------------------------------------------------------------------
try {
    $crumbs = [
        ['label' => 'Home', 'url' => '/'],
        ['label' => 'Blog', 'url' => '/blog'],
        ['label' => 'My Post', 'url' => ''],
    ];

    $bcHtml = $engine->breadcrumbs($crumbs);

    if (str_contains($bcHtml, 'aria-label="Breadcrumb"')) {
        test_pass('breadcrumbs() includes aria-label="Breadcrumb"');
    } else {
        test_fail('breadcrumbs() aria-label', 'aria-label="Breadcrumb" not found');
    }

    if (str_contains($bcHtml, 'aria-current="page"') && str_contains($bcHtml, 'My Post')) {
        test_pass('breadcrumbs() marks last item with aria-current="page" (no link)');
    } else {
        test_fail('breadcrumbs() last item', 'aria-current or last label missing');
    }

    if (str_contains($bcHtml, '<a href="/"') && str_contains($bcHtml, '<a href="/blog"')) {
        test_pass('breadcrumbs() renders links for non-last items');
    } else {
        test_fail('breadcrumbs() links', 'non-last items missing <a> links');
    }
} catch (\Throwable $e) {
    test_fail('breadcrumbs() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Set up test content for FrontController tests
// ---------------------------------------------------------------------------
// Published page
$pageAboutId = insertContent([
    'type'             => 'page',
    'title'            => 'About Us',
    'slug'             => 'about',
    'body'             => '<p>We are a great company.</p>',
    'excerpt'          => 'About our company',
    'status'           => 'published',
    'sort_order'       => 1,
    'meta_title'       => 'About Us | TestSite',
    'meta_description' => 'Learn about our company.',
    'published_at'     => gmdate('Y-m-d H:i:s', strtotime('-1 day')),
]);

// Published page (higher sort order)
$pageServicesId = insertContent([
    'type'       => 'page',
    'title'      => 'Services',
    'slug'       => 'services',
    'body'       => '<p>Our services.</p>',
    'status'     => 'published',
    'sort_order' => 2,
    'published_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day')),
]);

// Draft page (should NOT be visible)
$pageDraftId = insertContent([
    'type'   => 'page',
    'title'  => 'Secret Draft',
    'slug'   => 'secret-draft',
    'body'   => '<p>This is secret.</p>',
    'status' => 'draft',
]);

// Archived page (should NOT be visible)
$pageArchivedId = insertContent([
    'type'   => 'page',
    'title'  => 'Old Page',
    'slug'   => 'old-page',
    'body'   => '<p>This is archived.</p>',
    'status' => 'archived',
]);

// Published post (past date)
$postPublishedId = insertContent([
    'type'         => 'post',
    'title'        => 'Hello World',
    'slug'         => 'hello-world',
    'body'         => '<p>This is my first post.</p>',
    'excerpt'      => 'My first blog post',
    'status'       => 'published',
    'meta_title'   => 'Hello World | Blog',
    'published_at' => gmdate('Y-m-d H:i:s', strtotime('-2 hours')),
]);

// Future-scheduled post (should NOT be visible)
$postFutureId = insertContent([
    'type'         => 'post',
    'title'        => 'Future Post',
    'slug'         => 'future-post',
    'body'         => '<p>Coming soon.</p>',
    'status'       => 'published',
    'published_at' => gmdate('Y-m-d H:i:s', strtotime('+1 day')),
]);

// Create the App and FrontController
$app = new \App\Core\App();
$app->register('db', $pdo);
$fc = new \App\Templates\FrontController($app);

// ---------------------------------------------------------------------------
// Test 8: FrontController::homepage() renders with 200 status
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/');
    $response = $fc->homepage($request);

    if ($response->getStatus() === 200 && strlen($response->getBody()) > 100) {
        test_pass('homepage() returns 200 with rendered HTML');
    } else {
        test_fail('homepage() returns 200', "status={$response->getStatus()}, bodyLen=" . strlen($response->getBody()));
    }

    // Check it has layout structure
    $html = $response->getBody();
    if (str_contains($html, '<!DOCTYPE html') || str_contains($html, '<html')) {
        test_pass('homepage() output wrapped in HTML layout');
    } else {
        test_fail('homepage() layout wrapping', 'no HTML document structure');
    }
} catch (\Throwable $e) {
    test_fail('homepage() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: page() returns published page by slug
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/about');
    $response = $fc->page($request, 'about');

    if ($response->getStatus() === 200) {
        test_pass('page("about") returns 200 for published page');
    } else {
        test_fail('page("about") returns 200', "got status {$response->getStatus()}");
    }

    $html = $response->getBody();
    if (str_contains($html, 'About Us')) {
        test_pass('page("about") output contains page title "About Us"');
    } else {
        test_fail('page("about") contains title', '"About Us" not found in output');
    }
} catch (\Throwable $e) {
    test_fail('page() for published page works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: page() returns 404 for draft page
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/secret-draft');
    $response = $fc->page($request, 'secret-draft');

    if ($response->getStatus() === 404) {
        test_pass('page("secret-draft") returns 404 for draft page');
    } else {
        test_fail('page() 404 for draft', "expected 404, got {$response->getStatus()}");
    }
} catch (\Throwable $e) {
    test_fail('page() for draft works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: page() returns 404 for archived page
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/old-page');
    $response = $fc->page($request, 'old-page');

    if ($response->getStatus() === 404) {
        test_pass('page("old-page") returns 404 for archived page');
    } else {
        test_fail('page() 404 for archived', "expected 404, got {$response->getStatus()}");
    }
} catch (\Throwable $e) {
    test_fail('page() for archived works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: blogPost() returns published post by slug
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/blog/hello-world');
    $response = $fc->blogPost($request, 'hello-world');

    if ($response->getStatus() === 200) {
        test_pass('blogPost("hello-world") returns 200 for published post');
    } else {
        test_fail('blogPost() returns 200', "got status {$response->getStatus()}");
    }

    $html = $response->getBody();
    if (str_contains($html, 'Hello World')) {
        test_pass('blogPost() output contains post title "Hello World"');
    } else {
        test_fail('blogPost() contains title', '"Hello World" not found');
    }
} catch (\Throwable $e) {
    test_fail('blogPost() for published post works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: blogPost() returns 404 for future-scheduled post
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/blog/future-post');
    $response = $fc->blogPost($request, 'future-post');

    if ($response->getStatus() === 404) {
        test_pass('blogPost("future-post") returns 404 for future-scheduled post');
    } else {
        test_fail('blogPost() 404 for future post', "expected 404, got {$response->getStatus()}");
    }
} catch (\Throwable $e) {
    test_fail('blogPost() for future post works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: page() redirects posts to /blog/{slug} (301)
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/hello-world');
    $response = $fc->page($request, 'hello-world');

    $status = $response->getStatus();
    $headers = $response->getHeaders();
    $location = $headers['Location'] ?? '';

    if ($status === 301 && str_contains($location, '/blog/hello-world')) {
        test_pass('page() redirects post slug to /blog/{slug} with 301');
    } else {
        test_fail('page() post redirect', "expected 301 → /blog/hello-world, got status={$status}, location={$location}");
    }
} catch (\Throwable $e) {
    test_fail('page() post redirect works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: blogIndex() renders with pagination data
// ---------------------------------------------------------------------------
try {
    // Insert additional posts for pagination testing
    for ($i = 1; $i <= 12; $i++) {
        insertContent([
            'type'         => 'post',
            'title'        => "Pagination Post {$i}",
            'slug'         => "pagination-post-{$i}",
            'body'         => "<p>Pagination test post number {$i}.</p>",
            'status'       => 'published',
            'published_at' => gmdate('Y-m-d H:i:s', strtotime("-{$i} hours")),
        ]);
    }

    // Page 1
    $request = makeRequest('GET', '/blog', [], ['page' => '1']);
    $response = $fc->blogIndex($request);

    if ($response->getStatus() === 200) {
        test_pass('blogIndex() returns 200');
    } else {
        test_fail('blogIndex() returns 200', "got status {$response->getStatus()}");
    }

    $html = $response->getBody();
    if (str_contains($html, '<!DOCTYPE html') || str_contains($html, '<html')) {
        test_pass('blogIndex() output wrapped in HTML layout');
    } else {
        test_fail('blogIndex() layout wrapping', 'no HTML document structure');
    }

    // Page 2 — should also return 200
    $request2 = makeRequest('GET', '/blog', [], ['page' => '2']);
    $response2 = $fc->blogIndex($request2);

    if ($response2->getStatus() === 200) {
        test_pass('blogIndex() page 2 returns 200');
    } else {
        test_fail('blogIndex() page 2', "got status {$response2->getStatus()}");
    }
} catch (\Throwable $e) {
    test_fail('blogIndex() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: notFound() returns 404 status with styled page
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/nonexistent');
    $response = $fc->notFound($request);

    if ($response->getStatus() === 404) {
        test_pass('notFound() returns HTTP 404 status');
    } else {
        test_fail('notFound() status', "expected 404, got {$response->getStatus()}");
    }

    $html = $response->getBody();
    if (str_contains($html, '404') && str_contains($html, '<!DOCTYPE html')) {
        test_pass('notFound() renders styled 404 page with layout');
    } else {
        test_fail('notFound() styled output', '404 text or layout missing');
    }

    if (str_contains($html, 'href="/"') || str_contains($html, 'homepage')) {
        test_pass('notFound() page includes link back to homepage');
    } else {
        test_fail('notFound() homepage link', 'no link to homepage found');
    }
} catch (\Throwable $e) {
    test_fail('notFound() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 17: Navigation includes published pages sorted by sort_order
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/about');
    $response = $fc->page($request, 'about');
    $html = $response->getBody();

    // "About Us" (sort_order=1) should appear in nav
    // "Services" (sort_order=2) should appear in nav
    // "Secret Draft" and "Old Page" should NOT appear
    if (str_contains($html, 'href="/about"') && str_contains($html, 'href="/services"')) {
        test_pass('Navigation includes published pages (About, Services)');
    } else {
        test_fail('Navigation published pages', 'About or Services links missing');
    }

    if (!str_contains($html, 'secret-draft') && !str_contains($html, 'old-page')) {
        test_pass('Navigation excludes draft and archived pages');
    } else {
        test_fail('Navigation exclusion', 'draft or archived page found in nav');
    }

    // Check sort order: "About" should appear before "Services" in the HTML
    $aboutPos = strpos($html, 'href="/about"');
    $servicesPos = strpos($html, 'href="/services"');
    if ($aboutPos !== false && $servicesPos !== false && $aboutPos < $servicesPos) {
        test_pass('Navigation pages are sorted by sort_order (About before Services)');
    } else {
        test_fail('Navigation sort order', 'About not before Services in output');
    }
} catch (\Throwable $e) {
    test_fail('Navigation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: SEO meta tags present in rendered page output
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/about');
    $response = $fc->page($request, 'about');
    $html = $response->getBody();

    // meta_title was set to "About Us | TestSite"
    if (str_contains($html, 'About Us | TestSite')) {
        test_pass('Page output includes meta_title in <title> tag');
    } else {
        test_fail('meta_title in output', '"About Us | TestSite" not found');
    }

    // meta_description
    if (str_contains($html, 'Learn about our company')) {
        test_pass('Page output includes meta_description');
    } else {
        test_fail('meta_description in output', '"Learn about our company" not found');
    }

    // Canonical
    if (str_contains($html, 'canonical')) {
        test_pass('Page output includes canonical link');
    } else {
        test_fail('canonical link', 'canonical not found in output');
    }

    // og:type for pages should be website
    if (str_contains($html, 'og:type') && str_contains($html, 'website')) {
        test_pass('Page output includes og:type="website"');
    } else {
        test_fail('og:type for page', 'og:type website not found');
    }
} catch (\Throwable $e) {
    test_fail('SEO meta tags work without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: Blog post meta includes og:type article
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/blog/hello-world');
    $response = $fc->blogPost($request, 'hello-world');
    $html = $response->getBody();

    if (str_contains($html, 'og:type') && str_contains($html, 'article')) {
        test_pass('Blog post includes og:type="article"');
    } else {
        test_fail('Blog post og:type', 'og:type article not found');
    }

    // Check for article:author or article:published_time
    if (str_contains($html, 'article:author') || str_contains($html, 'article:published_time')) {
        test_pass('Blog post includes article-specific OG tags (author/published_time)');
    } else {
        test_fail('Blog post article OG tags', 'article:author/published_time not found');
    }
} catch (\Throwable $e) {
    test_fail('Blog post OG tags work without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 20: Homepage shows recent posts
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/');
    $response = $fc->homepage($request);
    $html = $response->getBody();

    // Should contain at least one of our published posts
    if (str_contains($html, 'Hello World') || str_contains($html, 'Pagination Post')) {
        test_pass('Homepage displays recent published posts');
    } else {
        test_fail('Homepage recent posts', 'no post titles found in homepage output');
    }

    // Should NOT contain the future-scheduled post
    if (!str_contains($html, 'Future Post')) {
        test_pass('Homepage excludes future-scheduled posts');
    } else {
        test_fail('Homepage future exclusion', 'Future Post found in homepage');
    }
} catch (\Throwable $e) {
    test_fail('Homepage recent posts work without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 21: App 404 fallback uses styled template (not raw HTML)
// ---------------------------------------------------------------------------
try {
    $appSource = file_get_contents($rootDir . '/app/Core/App.php');

    // Check that the raw '<h1>404 Not Found</h1>' fallback has been replaced
    if (!str_contains($appSource, "'<h1>404 Not Found</h1>'")) {
        test_pass('App.php 404 fallback no longer uses raw HTML string');
    } else {
        test_fail('App.php 404 fallback', 'still contains raw <h1>404 Not Found</h1> string');
    }

    // Check that FrontController is referenced in the 404 path
    if (str_contains($appSource, 'FrontController') || str_contains($appSource, 'notFound')) {
        test_pass('App.php 404 fallback references FrontController or notFound');
    } else {
        test_fail('App.php 404 FrontController reference', 'no FrontController/notFound reference found');
    }
} catch (\Throwable $e) {
    test_fail('App.php 404 check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 22: Public routes registered in index.php
// ---------------------------------------------------------------------------
try {
    $indexSource = file_get_contents($rootDir . '/public/index.php');

    $routeChecks = [
        ["FrontController",  "FrontController import/usage"],
        ["homepage",         "homepage route"],
        ["blogIndex",        "blogIndex route"],
        ["blogPost",         "blogPost route"],
    ];

    $allRoutesFound = true;
    foreach ($routeChecks as [$needle, $label]) {
        if (!str_contains($indexSource, $needle)) {
            test_fail("index.php contains {$label}", "'{$needle}' not found");
            $allRoutesFound = false;
        }
    }
    if ($allRoutesFound) {
        test_pass('index.php registers all public routes: homepage, blogIndex, blogPost, page');
    }

    // Catch-all /{slug} route should appear AFTER other routes
    $catchAllPos = strrpos($indexSource, '{slug}');
    $blogPos = strpos($indexSource, 'blogIndex');
    if ($catchAllPos !== false && $blogPos !== false && $catchAllPos > $blogPos) {
        test_pass('Catch-all /{slug} route is registered after specific routes');
    } else {
        test_fail('Catch-all route order', '/{slug} should be after /blog routes');
    }
} catch (\Throwable $e) {
    test_fail('Route registration check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup & Summary
// ---------------------------------------------------------------------------
cleanup();

exit($fail > 0 ? 1 : 0);
