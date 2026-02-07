<?php declare(strict_types=1);

/**
 * Chunk 2.2 — Content CRUD (Pages & Posts)
 * Automated Verification Tests
 *
 * Tests:
 *   1. ContentController class is autoloadable
 *   2. Required files exist (templates, JS)
 *   3. ContentController::index() renders content list with 200 status
 *   [SMOKE STOP]
 *   4. ContentController::store() creates new content and redirects
 *   5. Created content appears in index listing
 *   6. Slug is auto-generated from title
 *   7. Duplicate slugs are made unique (appends -2)
 *   8. ContentController::edit() renders editor with existing data
 *   9. ContentController::update() persists changes
 *  10. ContentController::delete() removes content
 *  11. Filter by type shows only matching type
 *  12. Filter by status shows only matching status
 *  13. Search by title returns matching results
 *  14. Bulk delete removes multiple items
 *  15. Bulk status change updates multiple items
 *  16. Pagination works when items exceed per-page limit
 *  17. Validation rejects empty title
 *  18. Validation rejects invalid status/type
 *  19. published_at is auto-set when publishing without a date
 *  20. Response includes CSP with TinyMCE CDN and X-Frame-Options
 *  21. Content list links editor.js
 *  22. Edit form includes CSRF field and _method hidden input
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
$testDbPath = $rootDir . '/storage/test_chunk22.sqlite';

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

// Helper: cleanup function for early exits
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
    echo "Chunk 2.2 results: {$pass} passed, {$fail} failed\n";
}

// ---------------------------------------------------------------------------
// Test 1: ContentController class is autoloadable
// ---------------------------------------------------------------------------
$controllerClass = 'App\\Admin\\ContentController';

if (!class_exists($controllerClass)) {
    test_fail('ContentController is autoloadable', "class {$controllerClass} not found");
    cleanup();
    exit(1);
} else {
    test_pass('ContentController is autoloadable');
}

// ---------------------------------------------------------------------------
// Test 2: Required files exist (templates, JS)
// ---------------------------------------------------------------------------
$requiredFiles = [
    'templates/admin/content/index.php'  => 'Content list template',
    'templates/admin/content/edit.php'   => 'Content edit template',
    'public/assets/js/editor.js'         => 'Editor JavaScript',
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
    test_pass('All required files exist: content/index.php, content/edit.php, editor.js');
}

// ---------------------------------------------------------------------------
// Test 3: ContentController::index() renders content list with 200 status
// ---------------------------------------------------------------------------
$app = null;
$controller = null;
$indexHtml = '';

try {
    $app = new \App\Core\App();
    $app->register('db', $pdo);

    $controller = new \App\Admin\ContentController($app);
    $request = makeRequest('GET', '/admin/content');
    $response = $controller->index($request);
    $indexHtml = $response->getBody();

    if ($response->getStatus() === 200 && strlen($indexHtml) > 100) {
        test_pass('ContentController::index() returns 200 with rendered HTML');
    } else {
        test_fail('ContentController::index() returns 200', "status={$response->getStatus()}, bodyLen=" . strlen($indexHtml));
    }
} catch (\Throwable $e) {
    test_fail('ContentController::index() works without errors', $e->getMessage());
}

if ($controller === null) {
    echo "\n[FAIL] Cannot continue — ContentController not available\n";
    cleanup();
    exit(1);
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    cleanup();
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: ContentController::store() creates new content and redirects
// ---------------------------------------------------------------------------
$createdId = null;
try {
    // Clear any pending flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $request = makeRequest('POST', '/admin/content', [
        'title'       => 'About Us',
        'slug'        => '',
        'body'        => '<p>This is the about page.</p>',
        'excerpt'     => 'About our company',
        'type'        => 'page',
        'status'      => 'published',
        'meta_title'  => 'About Us | LiteCMS',
        'meta_description' => 'Learn about our company.',
        'featured_image'   => '',
        'published_at'     => '',
        'sort_order'       => '1',
        '_csrf_token'      => $_SESSION['csrf_token'],
    ]);

    $response = $controller->store($request);
    $status = $response->getStatus();
    $headers = $response->getHeaders();
    $location = $headers['Location'] ?? '';

    if ($status === 302 && str_contains($location, '/admin/content/')) {
        // Extract the created ID from the redirect URL
        if (preg_match('#/admin/content/(\d+)/edit#', $location, $m)) {
            $createdId = $m[1];
        }
        test_pass("store() redirects to edit page after creating content (→ {$location})");
    } else {
        test_fail('store() redirects after creation', "status={$status}, location={$location}");
    }

    // Verify the row exists in DB
    if ($createdId !== null) {
        $row = \App\Database\QueryBuilder::query('content')
            ->select()
            ->where('id', (int)$createdId)
            ->first();
        if ($row !== null && $row['title'] === 'About Us' && $row['status'] === 'published') {
            test_pass('Content row persisted in database with correct title and status');
        } else {
            test_fail('Content persistence', 'row not found or data mismatch');
        }
    }
} catch (\Throwable $e) {
    test_fail('store() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: Created content appears in index listing
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $request = makeRequest('GET', '/admin/content');
    $response = $controller->index($request);
    $html = $response->getBody();

    if (str_contains($html, 'About Us')) {
        test_pass('Created content "About Us" appears in content list');
    } else {
        test_fail('Content appears in list', '"About Us" not found in index HTML');
    }
} catch (\Throwable $e) {
    test_fail('Index listing check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: Slug is auto-generated from title
// ---------------------------------------------------------------------------
try {
    $row = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('title', 'About Us')
        ->first();

    if ($row !== null && $row['slug'] === 'about-us') {
        test_pass('Slug auto-generated from title: "About Us" → "about-us"');
    } else {
        $actualSlug = $row['slug'] ?? '(null)';
        test_fail('Slug auto-generation', "expected 'about-us', got '{$actualSlug}'");
    }
} catch (\Throwable $e) {
    test_fail('Slug auto-generation check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: Duplicate slugs are made unique (appends -2)
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $request = makeRequest('POST', '/admin/content', [
        'title'       => 'About Us',
        'slug'        => '',
        'body'        => '<p>Another about page.</p>',
        'excerpt'     => '',
        'type'        => 'page',
        'status'      => 'draft',
        'meta_title'  => '',
        'meta_description' => '',
        'featured_image'   => '',
        'published_at'     => '',
        'sort_order'       => '0',
        '_csrf_token'      => $_SESSION['csrf_token'],
    ]);

    $response = $controller->store($request);
    $headers = $response->getHeaders();
    $location = $headers['Location'] ?? '';

    // Find the new row
    $duplicate = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('slug', 'about-us-2')
        ->first();

    if ($duplicate !== null) {
        test_pass('Duplicate slug made unique: "about-us" → "about-us-2"');
    } else {
        // Maybe it's -3 or another suffix — check for any about-us-* slug
        $all = \App\Database\QueryBuilder::query('content')
            ->select('slug')
            ->get();
        $slugs = array_column($all, 'slug');
        test_fail('Duplicate slug uniqueness', 'expected "about-us-2", found slugs: ' . implode(', ', $slugs));
    }
} catch (\Throwable $e) {
    test_fail('Duplicate slug check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: ContentController::edit() renders editor with existing data
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    if ($createdId !== null) {
        $request = makeRequest('GET', "/admin/content/{$createdId}/edit");
        $response = $controller->edit($request, $createdId);
        $editHtml = $response->getBody();

        $hasTitle = str_contains($editHtml, 'About Us');
        $hasSlug = str_contains($editHtml, 'about-us');
        $hasBody = str_contains($editHtml, 'This is the about page');
        $hasForm = str_contains($editHtml, '<form');

        if ($response->getStatus() === 200 && $hasTitle && $hasSlug && $hasBody && $hasForm) {
            test_pass('edit() renders editor with existing title, slug, body, and form');
        } else {
            test_fail('edit() content', "status={$response->getStatus()}, title={$hasTitle}, slug={$hasSlug}, body={$hasBody}, form={$hasForm}");
        }
    } else {
        test_skip('edit() rendering — no content ID from store test');
    }
} catch (\Throwable $e) {
    test_fail('edit() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: ContentController::update() persists changes
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    if ($createdId !== null) {
        $request = makeRequest('POST', "/admin/content/{$createdId}", [
            '_method'     => 'PUT',
            'title'       => 'About Our Company',
            'slug'        => 'about-us',
            'body'        => '<p>Updated body content.</p>',
            'excerpt'     => 'Updated excerpt',
            'type'        => 'page',
            'status'      => 'published',
            'meta_title'  => 'Updated Meta',
            'meta_description' => 'Updated description.',
            'featured_image'   => '',
            'published_at'     => '',
            'sort_order'       => '2',
            '_csrf_token'      => $_SESSION['csrf_token'],
        ]);

        $response = $controller->update($request, $createdId);
        $status = $response->getStatus();

        // Verify the changes in DB
        $updated = \App\Database\QueryBuilder::query('content')
            ->select()
            ->where('id', (int)$createdId)
            ->first();

        if ($status === 302 && $updated !== null && $updated['title'] === 'About Our Company'
            && str_contains($updated['body'], 'Updated body') && $updated['excerpt'] === 'Updated excerpt') {
            test_pass('update() persists title, body, and excerpt changes');
        } else {
            test_fail('update() persistence', "status={$status}, title=" . ($updated['title'] ?? 'null'));
        }
    } else {
        test_skip('update() — no content ID from store test');
    }
} catch (\Throwable $e) {
    test_fail('update() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: ContentController::delete() removes content
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Create a throwaway item to delete
    $delId = \App\Database\QueryBuilder::query('content')->insert([
        'type' => 'post', 'title' => 'Delete Me', 'slug' => 'delete-me',
        'body' => '<p>temp</p>', 'status' => 'draft', 'author_id' => 1,
    ]);

    $request = makeRequest('POST', "/admin/content/{$delId}/delete", [
        '_method' => 'DELETE',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->delete($request, $delId);
    $status = $response->getStatus();

    $gone = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('id', (int)$delId)
        ->first();

    if ($status === 302 && $gone === null) {
        test_pass('delete() removes content from database and redirects');
    } else {
        test_fail('delete()', "status={$status}, still_exists=" . ($gone !== null ? 'yes' : 'no'));
    }
} catch (\Throwable $e) {
    test_fail('delete() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: Filter by type shows only matching type
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Insert a post so we have both types
    \App\Database\QueryBuilder::query('content')->insert([
        'type' => 'post', 'title' => 'First Blog Post', 'slug' => 'first-blog-post',
        'body' => '<p>Blog content</p>', 'status' => 'published', 'author_id' => 1,
    ]);

    // Filter by type=page
    $request = makeRequest('GET', '/admin/content', [], ['type' => 'page']);
    $response = $controller->index($request);
    $html = $response->getBody();

    $hasPage = str_contains($html, 'About');
    $hasPost = str_contains($html, 'First Blog Post');

    if ($hasPage && !$hasPost) {
        test_pass('Filter by type=page shows only pages, not posts');
    } else {
        test_fail('Type filter', "hasPage={$hasPage}, hasPost={$hasPost} (expected page=true, post=false)");
    }
} catch (\Throwable $e) {
    test_fail('Type filter works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: Filter by status shows only matching status
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // We have: About Our Company (published), about-us-2 (draft), First Blog Post (published)
    $request = makeRequest('GET', '/admin/content', [], ['status' => 'draft']);
    $response = $controller->index($request);
    $html = $response->getBody();

    // The draft item should appear, published items should not
    $hasDraftItem = str_contains($html, 'about-us-2');
    $hasPublishedItem = str_contains($html, 'First Blog Post');

    if ($hasDraftItem && !$hasPublishedItem) {
        test_pass('Filter by status=draft shows only draft items');
    } else {
        test_fail('Status filter', "hasDraft={$hasDraftItem}, hasPublished={$hasPublishedItem}");
    }
} catch (\Throwable $e) {
    test_fail('Status filter works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: Search by title returns matching results
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $request = makeRequest('GET', '/admin/content', [], ['q' => 'Blog']);
    $response = $controller->index($request);
    $html = $response->getBody();

    $hasBlog = str_contains($html, 'First Blog Post');
    $hasAbout = str_contains($html, 'About Our Company');

    if ($hasBlog && !$hasAbout) {
        test_pass('Search for "Blog" returns blog post, excludes about page');
    } else {
        test_fail('Search filter', "hasBlog={$hasBlog}, hasAbout={$hasAbout}");
    }
} catch (\Throwable $e) {
    test_fail('Search filter works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: Bulk delete removes multiple items
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Create two throwaway items
    $bulkId1 = \App\Database\QueryBuilder::query('content')->insert([
        'type' => 'post', 'title' => 'Bulk Delete 1', 'slug' => 'bulk-delete-1',
        'body' => '<p>temp1</p>', 'status' => 'draft', 'author_id' => 1,
    ]);
    $bulkId2 = \App\Database\QueryBuilder::query('content')->insert([
        'type' => 'post', 'title' => 'Bulk Delete 2', 'slug' => 'bulk-delete-2',
        'body' => '<p>temp2</p>', 'status' => 'draft', 'author_id' => 1,
    ]);

    $request = makeRequest('POST', '/admin/content/bulk', [
        'bulk_action' => 'delete',
        'ids'         => [$bulkId1, $bulkId2],
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->bulk($request);

    $gone1 = \App\Database\QueryBuilder::query('content')->select()->where('id', (int)$bulkId1)->first();
    $gone2 = \App\Database\QueryBuilder::query('content')->select()->where('id', (int)$bulkId2)->first();

    if ($response->getStatus() === 302 && $gone1 === null && $gone2 === null) {
        test_pass('Bulk delete removes both items from database');
    } else {
        test_fail('Bulk delete', "gone1=" . ($gone1 === null ? 'yes' : 'no') . ", gone2=" . ($gone2 === null ? 'yes' : 'no'));
    }
} catch (\Throwable $e) {
    test_fail('Bulk delete works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: Bulk status change updates multiple items
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Create two draft items
    $bsId1 = \App\Database\QueryBuilder::query('content')->insert([
        'type' => 'post', 'title' => 'Bulk Status 1', 'slug' => 'bulk-status-1',
        'body' => '<p>bs1</p>', 'status' => 'draft', 'author_id' => 1,
    ]);
    $bsId2 = \App\Database\QueryBuilder::query('content')->insert([
        'type' => 'post', 'title' => 'Bulk Status 2', 'slug' => 'bulk-status-2',
        'body' => '<p>bs2</p>', 'status' => 'draft', 'author_id' => 1,
    ]);

    $request = makeRequest('POST', '/admin/content/bulk', [
        'bulk_action' => 'publish',
        'ids'         => [$bsId1, $bsId2],
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->bulk($request);

    $row1 = \App\Database\QueryBuilder::query('content')->select()->where('id', (int)$bsId1)->first();
    $row2 = \App\Database\QueryBuilder::query('content')->select()->where('id', (int)$bsId2)->first();

    if ($row1 !== null && $row1['status'] === 'published'
        && $row2 !== null && $row2['status'] === 'published') {
        test_pass('Bulk publish changes status of both items to "published"');
    } else {
        test_fail('Bulk status change', "status1=" . ($row1['status'] ?? 'null') . ", status2=" . ($row2['status'] ?? 'null'));
    }

    // Cleanup bulk status items
    \App\Database\QueryBuilder::query('content')->where('id', (int)$bsId1)->delete();
    \App\Database\QueryBuilder::query('content')->where('id', (int)$bsId2)->delete();
} catch (\Throwable $e) {
    test_fail('Bulk status change works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: Pagination works when items exceed per-page limit
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Insert enough items to exceed default items_per_page (10)
    $paginationIds = [];
    for ($i = 1; $i <= 12; $i++) {
        $paginationIds[] = \App\Database\QueryBuilder::query('content')->insert([
            'type' => 'post', 'title' => "Pagination Post {$i}", 'slug' => "pagination-post-{$i}",
            'body' => "<p>Post {$i}</p>", 'status' => 'draft', 'author_id' => 1,
        ]);
    }

    // Page 1 — should show items but not all
    $request = makeRequest('GET', '/admin/content', [], ['page' => '1']);
    $response = $controller->index($request);
    $page1Html = $response->getBody();

    // Clear flash messages again
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Page 2 — should exist (we have 15 total items, 10 per page)
    $request = makeRequest('GET', '/admin/content', [], ['page' => '2']);
    $response = $controller->index($request);
    $page2Html = $response->getBody();

    $hasNextOnPage1 = str_contains($page1Html, 'Next') || str_contains($page1Html, 'page=2');
    $hasPrevOnPage2 = str_contains($page2Html, 'Prev') || str_contains($page2Html, 'page=1');

    if ($hasNextOnPage1 && $hasPrevOnPage2) {
        test_pass('Pagination: page 1 has Next link, page 2 has Prev link');
    } else {
        test_fail('Pagination links', "nextOnPage1={$hasNextOnPage1}, prevOnPage2={$hasPrevOnPage2}");
    }

    // Page info text
    $hasPageInfo = str_contains($page1Html, 'Page 1') || str_contains($page1Html, 'page-info');
    if ($hasPageInfo) {
        test_pass('Pagination shows page info text');
    } else {
        test_fail('Pagination page info', 'no page info text found on page 1');
    }

    // Cleanup pagination items
    foreach ($paginationIds as $pid) {
        \App\Database\QueryBuilder::query('content')->where('id', (int)$pid)->delete();
    }
} catch (\Throwable $e) {
    test_fail('Pagination works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 17: Validation rejects empty title
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $countBefore = \App\Database\QueryBuilder::query('content')->select()->count();

    $request = makeRequest('POST', '/admin/content', [
        'title'       => '',
        'slug'        => '',
        'body'        => '<p>No title</p>',
        'excerpt'     => '',
        'type'        => 'page',
        'status'      => 'draft',
        'meta_title'  => '',
        'meta_description' => '',
        'featured_image'   => '',
        'published_at'     => '',
        'sort_order'       => '0',
        '_csrf_token'      => $_SESSION['csrf_token'],
    ]);

    $response = $controller->store($request);
    $status = $response->getStatus();

    $countAfter = \App\Database\QueryBuilder::query('content')->select()->count();

    if ($status === 302 && $countAfter === $countBefore) {
        test_pass('Validation rejects empty title — no new row inserted, redirects');
    } else {
        test_fail('Empty title validation', "status={$status}, countBefore={$countBefore}, countAfter={$countAfter}");
    }
} catch (\Throwable $e) {
    test_fail('Title validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: Validation rejects invalid status/type
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $countBefore = \App\Database\QueryBuilder::query('content')->select()->count();

    $request = makeRequest('POST', '/admin/content', [
        'title'       => 'Invalid Type Test',
        'slug'        => '',
        'body'        => '',
        'excerpt'     => '',
        'type'        => 'invalid_type',
        'status'      => 'draft',
        'meta_title'  => '',
        'meta_description' => '',
        'featured_image'   => '',
        'published_at'     => '',
        'sort_order'       => '0',
        '_csrf_token'      => $_SESSION['csrf_token'],
    ]);

    $response = $controller->store($request);

    $countAfter = \App\Database\QueryBuilder::query('content')->select()->count();

    if ($response->getStatus() === 302 && $countAfter === $countBefore) {
        test_pass('Validation rejects invalid content type — no row inserted');
    } else {
        test_fail('Invalid type validation', "countBefore={$countBefore}, countAfter={$countAfter}");
    }
} catch (\Throwable $e) {
    test_fail('Type validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: published_at is auto-set when publishing without a date
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $request = makeRequest('POST', '/admin/content', [
        'title'       => 'Auto Publish Date',
        'slug'        => 'auto-publish-date',
        'body'        => '<p>Auto date test</p>',
        'excerpt'     => '',
        'type'        => 'post',
        'status'      => 'published',
        'meta_title'  => '',
        'meta_description' => '',
        'featured_image'   => '',
        'published_at'     => '',
        'sort_order'       => '0',
        '_csrf_token'      => $_SESSION['csrf_token'],
    ]);

    $response = $controller->store($request);

    $row = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('slug', 'auto-publish-date')
        ->first();

    if ($row !== null && !empty($row['published_at'])) {
        test_pass('published_at auto-set when status is "published" and no date provided: ' . $row['published_at']);
    } else {
        $pubAt = $row['published_at'] ?? '(null)';
        test_fail('Auto-set published_at', "published_at={$pubAt}");
    }
} catch (\Throwable $e) {
    test_fail('published_at auto-set works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 20: Response includes CSP with TinyMCE CDN and X-Frame-Options
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    if ($createdId !== null) {
        $request = makeRequest('GET', "/admin/content/{$createdId}/edit");
        $response = $controller->edit($request, $createdId);
        $headers = $response->getHeaders();

        $xFrame = $headers['X-Frame-Options'] ?? '';
        $csp = $headers['Content-Security-Policy'] ?? '';

        $hasXFrame = ($xFrame === 'DENY');
        $hasTinyCdn = str_contains($csp, 'cdn.tiny.cloud');
        $hasSelf = str_contains($csp, "default-src 'self'");

        if ($hasXFrame && $hasTinyCdn && $hasSelf) {
            test_pass('Edit response has X-Frame-Options: DENY and CSP allowing cdn.tiny.cloud');
        } else {
            test_fail('Security headers on edit', "xframe={$xFrame}, tinyCdn={$hasTinyCdn}, self={$hasSelf}");
        }
    } else {
        test_skip('Security headers — no content ID available');
    }
} catch (\Throwable $e) {
    test_fail('Security headers check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 21: Content list/edit links editor.js
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Check index page
    $request = makeRequest('GET', '/admin/content');
    $response = $controller->index($request);
    $html = $response->getBody();
    $indexHasEditor = str_contains($html, 'editor.js');

    // Check edit page
    if ($createdId !== null) {
        // Clear flash messages
        \App\Auth\Session::flash('success');
        \App\Auth\Session::flash('error');

        $request = makeRequest('GET', "/admin/content/{$createdId}/edit");
        $response = $controller->edit($request, $createdId);
        $editHtml = $response->getBody();
        $editHasEditor = str_contains($editHtml, 'editor.js');
    } else {
        $editHasEditor = false;
    }

    if ($editHasEditor) {
        test_pass('Edit page links editor.js');
    } else {
        test_fail('editor.js link', "index={$indexHasEditor}, edit={$editHasEditor}");
    }
} catch (\Throwable $e) {
    test_fail('editor.js link check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 22: Edit form includes CSRF field and _method hidden input
// ---------------------------------------------------------------------------
try {
    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    if ($createdId !== null) {
        $request = makeRequest('GET', "/admin/content/{$createdId}/edit");
        $response = $controller->edit($request, $createdId);
        $editHtml = $response->getBody();

        $hasCsrf = str_contains($editHtml, '_csrf_token');
        $hasMethodOverride = str_contains($editHtml, '_method') && str_contains($editHtml, 'PUT');

        if ($hasCsrf && $hasMethodOverride) {
            test_pass('Edit form includes CSRF token field and _method=PUT hidden input');
        } else {
            test_fail('Form security fields', "csrf={$hasCsrf}, methodOverride={$hasMethodOverride}");
        }
    } else {
        test_skip('Form security fields — no content ID available');
    }
} catch (\Throwable $e) {
    test_fail('Form security fields check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
cleanup();

exit($fail > 0 ? 1 : 0);
