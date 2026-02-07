<?php declare(strict_types=1);

/**
 * Chunk 2.1 — Admin Layout & Dashboard
 * Automated Verification Tests
 *
 * Tests:
 *   1. DashboardController class is autoloadable
 *   2. Static assets exist (admin.css, admin.js)
 *   3. DashboardController::index() renders with 200 status
 *   [SMOKE STOP]
 *   4. Dashboard shows correct stats for empty database (fresh install)
 *   5. Dashboard shows correct stats after inserting test data
 *   6. Recent content table includes items with author name (LEFT JOIN)
 *   7. Response includes CSP and X-Frame-Options headers
 *   8. Admin layout links admin.css and admin.js
 *   9. Sidebar navigation contains all 5 menu items
 *  10. Active navigation highlighting works (dashboard page)
 *  11. Sidebar shows username and logout form
 *  12. Flash messages render with CSS classes (not inline styles)
 *  13. Placeholder template renders correctly
 *  14. Responsive markup present (sidebar-toggle, sidebar-overlay)
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
$testDbPath = $rootDir . '/storage/test_chunk21.sqlite';

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

// Start session (needed by templates)
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
function makeRequest(string $method, string $uri, array $post = [], array $server = []): \App\Core\Request
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_POST = $post;
    $_GET = [];
    $_COOKIE = [];
    foreach ($server as $k => $v) {
        $_SERVER[$k] = $v;
    }
    return new \App\Core\Request();
}

// ---------------------------------------------------------------------------
// Test 1: DashboardController class is autoloadable
// ---------------------------------------------------------------------------
$controllerClass = 'App\\Admin\\DashboardController';

if (!class_exists($controllerClass)) {
    test_fail('DashboardController is autoloadable', "class {$controllerClass} not found");
} else {
    test_pass('DashboardController is autoloadable');
}

// ---------------------------------------------------------------------------
// Test 2: Static assets exist (admin.css, admin.js)
// ---------------------------------------------------------------------------
$cssPath = $rootDir . '/public/assets/css/admin.css';
$jsPath = $rootDir . '/public/assets/js/admin.js';

$cssExists = file_exists($cssPath);
$jsExists = file_exists($jsPath);

if ($cssExists && $jsExists) {
    // Check CSS has meaningful content (not just empty file)
    $cssSize = filesize($cssPath);
    $jsSize = filesize($jsPath);
    if ($cssSize > 100 && $jsSize > 50) {
        test_pass("Static assets exist: admin.css ({$cssSize} bytes), admin.js ({$jsSize} bytes)");
    } else {
        test_fail('Static assets have content', "admin.css={$cssSize}b, admin.js={$jsSize}b — files seem too small");
    }
} else {
    if (!$cssExists) test_fail('admin.css exists', 'public/assets/css/admin.css not found');
    if (!$jsExists) test_fail('admin.js exists', 'public/assets/js/admin.js not found');
}

// ---------------------------------------------------------------------------
// Test 3: DashboardController::index() renders with 200 status
// ---------------------------------------------------------------------------
$dashboardHtml = '';
$dashboardResponse = null;
$controller = null;
$app = null;

try {
    $app = new \App\Core\App();
    $app->register('db', $pdo);

    $controller = new \App\Admin\DashboardController($app);
    $request = makeRequest('GET', '/admin/dashboard');
    $dashboardResponse = $controller->index($request);
    $dashboardHtml = $dashboardResponse->getBody();

    if ($dashboardResponse->getStatus() === 200 && strlen($dashboardHtml) > 100) {
        test_pass('DashboardController::index() returns 200 with rendered HTML');
    } else {
        test_fail('DashboardController::index() returns 200', "status={$dashboardResponse->getStatus()}, bodyLen=" . strlen($dashboardHtml));
    }
} catch (\Throwable $e) {
    test_fail('DashboardController::index() works without errors', $e->getMessage());
}

// Guard: if controller failed to instantiate, remaining tests cannot proceed
if ($controller === null) {
    echo "\n[FAIL] Cannot continue — DashboardController not available\n";
    // Cleanup
    $pdo = null;
    \App\Database\Connection::reset();
    $configProp->setValue(null, null);
    putenv('DB_DRIVER');
    putenv('DB_PATH');
    // Wait briefly for Windows to release file handles
    usleep(100000);
    if (file_exists($testDbPath)) { @unlink($testDbPath); }
    foreach ([$testDbPath . '-wal', $testDbPath . '-shm'] as $f) {
        if (file_exists($f)) { @unlink($f); }
    }
    echo "\nChunk 2.1 results: {$pass} passed, {$fail} failed\n";
    exit(1);
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    // Cleanup
    \App\Database\Connection::reset();
    $configProp->setValue(null, null);
    putenv('DB_DRIVER');
    putenv('DB_PATH');
    if (file_exists($testDbPath)) { unlink($testDbPath); }
    foreach ([$testDbPath . '-wal', $testDbPath . '-shm'] as $f) {
        if (file_exists($f)) { unlink($f); }
    }
    echo "\nChunk 2.1 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: Dashboard shows correct stats for empty database (fresh install)
// ---------------------------------------------------------------------------
try {
    // No content, media, or extra users — only the 1 admin user we created
    // Check that the HTML contains the stat values we expect
    // Total Content = 0, Users = 1, Media = 0
    $hasZeroContent = str_contains($dashboardHtml, '>0<');
    $hasOneUser = (bool) preg_match('/Users.*?<.*?1/s', $dashboardHtml);

    if ($hasZeroContent && $hasOneUser) {
        test_pass('Dashboard stats correct for fresh install (content=0, users=1)');
    } else {
        test_fail('Dashboard fresh stats', "hasZeroContent={$hasZeroContent}, hasOneUser={$hasOneUser}");
    }
} catch (\Throwable $e) {
    test_fail('Dashboard fresh stats check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: Dashboard shows correct stats after inserting test data
// ---------------------------------------------------------------------------
try {
    // Insert test content
    \App\Database\QueryBuilder::query('content')->insert([
        'type' => 'page', 'title' => 'About Us', 'slug' => 'about-us',
        'body' => '<p>About</p>', 'status' => 'published', 'author_id' => 1,
    ]);
    \App\Database\QueryBuilder::query('content')->insert([
        'type' => 'page', 'title' => 'Contact', 'slug' => 'contact',
        'body' => '<p>Contact</p>', 'status' => 'published', 'author_id' => 1,
    ]);
    \App\Database\QueryBuilder::query('content')->insert([
        'type' => 'post', 'title' => 'First Post', 'slug' => 'first-post',
        'body' => '<p>Hello</p>', 'status' => 'draft', 'author_id' => 1,
    ]);
    \App\Database\QueryBuilder::query('content')->insert([
        'type' => 'post', 'title' => 'Second Post', 'slug' => 'second-post',
        'body' => '<p>World</p>', 'status' => 'published', 'author_id' => 1,
    ]);

    // Insert a media record
    \App\Database\QueryBuilder::query('media')->insert([
        'filename' => 'abc123.jpg', 'original_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 12345, 'uploaded_by' => 1,
    ]);

    // Re-render dashboard with new data
    $response2 = $controller->index($request);
    $html2 = $response2->getBody();

    // Total content = 4, published = 3, drafts = 1, pages = 2, posts = 2, users = 1, media = 1
    $totalCheck = (bool) preg_match('/Total Content.*?<.*?>4</s', $html2);
    $publishedCheck = (bool) preg_match('/Published.*?<.*?>3</s', $html2);
    $draftsCheck = (bool) preg_match('/Drafts.*?<.*?>1</s', $html2);
    $mediaCheck = (bool) preg_match('/Media.*?<.*?>1</s', $html2);

    if ($totalCheck && $publishedCheck && $draftsCheck && $mediaCheck) {
        test_pass('Dashboard stats correct with data: total=4, published=3, drafts=1, media=1');
    } else {
        test_fail('Dashboard data stats', "total={$totalCheck}, published={$publishedCheck}, drafts={$draftsCheck}, media={$mediaCheck}");
    }
} catch (\Throwable $e) {
    test_fail('Dashboard with test data works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: Recent content table includes items with author name (LEFT JOIN)
// ---------------------------------------------------------------------------
try {
    // html2 should already contain the recent content table from test 5
    $hasAbout = str_contains($html2, 'About Us');
    $hasFirstPost = str_contains($html2, 'First Post');
    $hasAuthor = str_contains($html2, 'admin');
    $hasTable = str_contains($html2, '<table');

    if ($hasTable && $hasAbout && $hasFirstPost && $hasAuthor) {
        test_pass('Recent content table shows items with author name from LEFT JOIN');
    } else {
        test_fail('Recent content table', "table={$hasTable}, about={$hasAbout}, post={$hasFirstPost}, author={$hasAuthor}");
    }

    // Check status badges are rendered
    $hasPublishedBadge = str_contains($html2, 'badge-published');
    $hasDraftBadge = str_contains($html2, 'badge-draft');

    if ($hasPublishedBadge && $hasDraftBadge) {
        test_pass('Recent content table shows status badges (published, draft)');
    } else {
        test_fail('Status badges', "published={$hasPublishedBadge}, draft={$hasDraftBadge}");
    }
} catch (\Throwable $e) {
    test_fail('Recent content table works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: Response includes CSP and X-Frame-Options headers
// ---------------------------------------------------------------------------
try {
    $headers = $dashboardResponse->getHeaders();
    $xFrameOptions = $headers['X-Frame-Options'] ?? '';
    $csp = $headers['Content-Security-Policy'] ?? '';

    $hasXFrame = ($xFrameOptions === 'DENY');
    $hasCsp = str_contains($csp, "default-src 'self'") && str_contains($csp, "script-src 'self'");

    if ($hasXFrame && $hasCsp) {
        test_pass('Response includes X-Frame-Options: DENY and Content-Security-Policy headers');
    } else {
        test_fail('Security headers', "X-Frame-Options={$xFrameOptions}, CSP=" . substr($csp, 0, 60));
    }
} catch (\Throwable $e) {
    test_fail('Security headers check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: Admin layout links admin.css and admin.js
// ---------------------------------------------------------------------------
try {
    $hasCssLink = str_contains($dashboardHtml, 'admin.css');
    $hasJsLink = str_contains($dashboardHtml, 'admin.js');

    if ($hasCssLink && $hasJsLink) {
        test_pass('Admin layout includes <link> to admin.css and <script> to admin.js');
    } else {
        test_fail('Asset links in layout', "css={$hasCssLink}, js={$hasJsLink}");
    }
} catch (\Throwable $e) {
    test_fail('Asset link check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: Sidebar navigation contains all 5 menu items
// ---------------------------------------------------------------------------
try {
    $navLinks = [
        '/admin/dashboard' => 'Dashboard',
        '/admin/content'   => 'Content',
        '/admin/media'     => 'Media',
        '/admin/users'     => 'Users',
        '/admin/settings'  => 'Settings',
    ];

    $allFound = true;
    $missing = [];
    foreach ($navLinks as $href => $label) {
        if (!str_contains($dashboardHtml, $href)) {
            $allFound = false;
            $missing[] = "{$label} ({$href})";
        }
    }

    if ($allFound) {
        test_pass('Sidebar navigation contains all 5 menu items: Dashboard, Content, Media, Users, Settings');
    } else {
        test_fail('Sidebar nav items', 'missing: ' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    test_fail('Sidebar nav check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: Active navigation highlighting works (dashboard page)
// ---------------------------------------------------------------------------
try {
    // The dashboard link should have class="active" (or class containing 'active')
    // Look for the pattern: href="/admin/dashboard" with class containing "active" nearby
    $hasDashboardActive = (bool) preg_match(
        '/href="\/admin\/dashboard"[^>]*class="[^"]*active[^"]*"/i',
        $dashboardHtml
    );

    // Alternative: class before href
    if (!$hasDashboardActive) {
        $hasDashboardActive = (bool) preg_match(
            '/class="[^"]*active[^"]*"[^>]*href="\/admin\/dashboard"/i',
            $dashboardHtml
        );
    }

    if ($hasDashboardActive) {
        test_pass('Dashboard nav link has active class highlighted');
    } else {
        test_fail('Active nav highlighting', 'no "active" class found on /admin/dashboard link');
    }
} catch (\Throwable $e) {
    test_fail('Active nav check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: Sidebar shows username and logout form
// ---------------------------------------------------------------------------
try {
    $hasUsername = str_contains($dashboardHtml, 'admin');
    $hasLogoutForm = str_contains($dashboardHtml, '/admin/logout') && str_contains($dashboardHtml, 'method="POST"');
    $hasRole = str_contains($dashboardHtml, 'Admin');

    if ($hasUsername && $hasLogoutForm && $hasRole) {
        test_pass('Sidebar shows username "admin", role "Admin", and logout form');
    } else {
        test_fail('Sidebar user info', "username={$hasUsername}, logout={$hasLogoutForm}, role={$hasRole}");
    }
} catch (\Throwable $e) {
    test_fail('Sidebar user info check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: Flash messages render with CSS classes (not inline styles)
// ---------------------------------------------------------------------------
try {
    // Set a flash message and re-render
    \App\Auth\Session::flash('error', 'Test error message');

    $response3 = $controller->index($request);
    $html3 = $response3->getBody();

    $hasFlashClass = str_contains($html3, 'class="alert alert-error"') || str_contains($html3, "class='alert alert-error'");
    $hasFlashText = str_contains($html3, 'Test error message');
    // Ensure no inline style on the alert (would indicate old layout)
    $hasInlineStyle = (bool) preg_match('/class="alert alert-error"[^>]*style="/i', $html3);

    if ($hasFlashClass && $hasFlashText && !$hasInlineStyle) {
        test_pass('Flash messages use CSS classes (alert alert-error), no inline styles');
    } elseif ($hasFlashText && !$hasFlashClass) {
        test_fail('Flash message CSS classes', 'message renders but without alert-error class');
    } else {
        test_fail('Flash message rendering', "class={$hasFlashClass}, text={$hasFlashText}, inlineStyle={$hasInlineStyle}");
    }
} catch (\Throwable $e) {
    test_fail('Flash message check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: Placeholder template renders correctly
// ---------------------------------------------------------------------------
try {
    $templatePath = $rootDir . '/templates/admin/placeholder.php';

    if (!file_exists($templatePath)) {
        test_fail('Placeholder template exists', 'templates/admin/placeholder.php not found');
    } else {
        $engine = new \App\Templates\TemplateEngine($rootDir . '/templates');
        $placeholderHtml = $engine->render('admin/placeholder', [
            'title'     => 'Content',
            'activeNav' => 'content',
            'message'   => 'Content management is coming in Chunk 2.2.',
        ]);

        $hasTitle = str_contains($placeholderHtml, 'Content');
        $hasMessage = str_contains($placeholderHtml, 'Chunk 2.2');
        $hasLayout = str_contains($placeholderHtml, '<!DOCTYPE html') || str_contains($placeholderHtml, '<html');

        if ($hasTitle && $hasMessage && $hasLayout) {
            test_pass('Placeholder template renders with title, message, and admin layout wrapper');
        } else {
            test_fail('Placeholder render', "title={$hasTitle}, message={$hasMessage}, layout={$hasLayout}");
        }

        // Check that the Content nav link is active (not Dashboard)
        $contentActive = (bool) preg_match(
            '/href="\/admin\/content"[^>]*class="[^"]*active[^"]*"/i',
            $placeholderHtml
        );
        if (!$contentActive) {
            $contentActive = (bool) preg_match(
                '/class="[^"]*active[^"]*"[^>]*href="\/admin\/content"/i',
                $placeholderHtml
            );
        }

        if ($contentActive) {
            test_pass('Placeholder page highlights correct nav item (Content is active)');
        } else {
            test_fail('Placeholder nav highlighting', 'Content link not marked active');
        }
    }
} catch (\Throwable $e) {
    test_fail('Placeholder template works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: Responsive markup present (sidebar-toggle, sidebar-overlay)
// ---------------------------------------------------------------------------
try {
    $hasToggle = str_contains($dashboardHtml, 'sidebar-toggle');
    $hasOverlay = str_contains($dashboardHtml, 'sidebar-overlay');

    if ($hasToggle && $hasOverlay) {
        test_pass('Responsive markup present: sidebar-toggle button and sidebar-overlay div');
    } else {
        test_fail('Responsive markup', "toggle={$hasToggle}, overlay={$hasOverlay}");
    }

    // Also check admin.css has the mobile media query
    $cssContent = $cssExists ? file_get_contents($cssPath) : '';
    $hasMobileQuery = str_contains($cssContent, '@media') && str_contains($cssContent, '768px');

    if ($hasMobileQuery) {
        test_pass('admin.css contains responsive media query for mobile (768px breakpoint)');
    } else {
        test_fail('CSS responsive breakpoint', 'no @media query with 768px found in admin.css');
    }
} catch (\Throwable $e) {
    test_fail('Responsive markup check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
$pdo = null;
\App\Database\Connection::reset();
$configProp->setValue(null, null);
putenv('DB_DRIVER');
putenv('DB_PATH');

// Remove test database (brief delay for Windows file handle release)
usleep(100000);
if (file_exists($testDbPath)) { @unlink($testDbPath); }
foreach ([$testDbPath . '-wal', $testDbPath . '-shm'] as $f) {
    if (file_exists($f)) { @unlink($f); }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 2.1 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
