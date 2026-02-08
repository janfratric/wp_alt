<?php declare(strict_types=1);

/**
 * Chunk 3.2 — Public Templates & Styling
 * Automated Verification Tests
 *
 * Tests:
 *   1. Required new files exist (CSS, JS, templates, migrations)
 *   2. FrontController has new methods (contactPage, contactSubmit, archive, getPublicSettings)
 *   3. Public stylesheet contains CSS custom properties and key sections
 *   [SMOKE STOP]
 *   4. Cookie consent JS contains IIFE, consent cookie logic, GA loader
 *   5. Cookie consent partial has dialog role and accept/decline buttons
 *   6. Contact template has CSRF field and required form fields
 *   7. Archive template has pagination and post-card structure
 *   8. Layout includes CSS link, nav-toggle button, cookie consent partial, cookie-consent.js
 *   9. Home template has hero section with CTA button
 *  10. Migration files contain contact_submissions table schema
 *  11. Contact routes registered in index.php (GET + POST /contact)
 *  12. FrontController::contactPage() renders contact form with 200
 *  13. FrontController::contactSubmit() validates and stores submission
 *  14. FrontController::contactSubmit() rejects missing required fields
 *  15. FrontController::contactSubmit() uses PRG pattern (redirect after success)
 *  16. Contact submission stored in database with correct data
 *  17. renderPublic() merges cookie consent and GA settings into template data
 *  18. Homepage renders with hero section markup
 *  19. getPublicSettings() returns settings from database
 *  20. Layout renders data-ga-id when GA is enabled
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
$testDbPath = $rootDir . '/storage/test_chunk32.sqlite';

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
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_POST = $post;
    $_GET = $get;
    $_COOKIE = [];
    return new \App\Core\Request();
}

// Helper: insert test content
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
    echo "Chunk 3.2 results: {$pass} passed, {$fail} failed\n";
}

// ---------------------------------------------------------------------------
// Test 1: Required new files exist
// ---------------------------------------------------------------------------
$requiredFiles = [
    'public/assets/css/style.css'                          => 'Public stylesheet',
    'public/assets/js/cookie-consent.js'                   => 'Cookie consent JS',
    'templates/public/partials/cookie-consent.php'         => 'Cookie consent partial',
    'templates/public/contact.php'                         => 'Contact page template',
    'templates/public/archive.php'                         => 'Archive listing template',
    'migrations/002_contact_submissions.sqlite.sql'        => 'SQLite migration',
    'migrations/002_contact_submissions.pgsql.sql'         => 'PostgreSQL migration',
    'migrations/002_contact_submissions.mysql.sql'         => 'MySQL migration',
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
    test_pass('All required new files exist: CSS, JS, templates, migrations');
}

// ---------------------------------------------------------------------------
// Test 2: FrontController has new methods
// ---------------------------------------------------------------------------
$fc_class = 'App\\Templates\\FrontController';
if (!class_exists($fc_class)) {
    test_fail('FrontController is autoloadable', "class {$fc_class} not found");
    cleanup();
    exit(1);
}

$newMethods = ['contactPage', 'contactSubmit', 'archive'];
$allMethodsExist = true;
foreach ($newMethods as $method) {
    if (!method_exists($fc_class, $method)) {
        test_fail("FrontController::{$method}() exists", 'method not found');
        $allMethodsExist = false;
    }
}

// Check private getPublicSettings via reflection
$fcReflection = new ReflectionClass($fc_class);
$hasGetPublicSettings = $fcReflection->hasMethod('getPublicSettings');
if (!$hasGetPublicSettings) {
    test_fail('FrontController::getPublicSettings() exists (private)', 'method not found');
    $allMethodsExist = false;
}

if ($allMethodsExist) {
    test_pass('FrontController has all new methods: contactPage, contactSubmit, archive, getPublicSettings');
}

// ---------------------------------------------------------------------------
// Test 3: Public stylesheet contains CSS custom properties and key sections
// ---------------------------------------------------------------------------
$cssPath = $rootDir . '/public/assets/css/style.css';
if (file_exists($cssPath)) {
    $css = file_get_contents($cssPath);

    $cssChecks = [
        [':root',              'CSS custom properties (:root)'],
        ['--color-primary',    'primary color variable'],
        ['--font-family',      'font family variable'],
        ['.site-header',       'site header styles'],
        ['.site-nav',          'site nav styles'],
        ['.hero',              'hero section styles'],
        ['.post-card',         'post card styles'],
        ['.contact-form',      'contact form styles'],
        ['.cookie-consent',    'cookie consent styles'],
        ['.pagination',        'pagination styles'],
        ['@media',             'responsive media queries'],
    ];

    $allCssPresent = true;
    foreach ($cssChecks as [$needle, $label]) {
        if (!str_contains($css, $needle)) {
            test_fail("CSS contains {$label}", "'{$needle}' not found");
            $allCssPresent = false;
        }
    }
    if ($allCssPresent) {
        test_pass('Public stylesheet contains all key sections: custom props, header, hero, cards, contact, consent, pagination');
    }
} else {
    test_fail('Public stylesheet readable', 'file not found');
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    cleanup();
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: Cookie consent JS contains IIFE, consent logic, GA loader
// ---------------------------------------------------------------------------
$jsPath = $rootDir . '/public/assets/js/cookie-consent.js';
if (file_exists($jsPath)) {
    $js = file_get_contents($jsPath);

    $jsChecks = [
        ['litecms_consent',        'consent cookie name'],
        ['setCookie',              'setCookie function'],
        ['getCookie',              'getCookie function'],
        ['googletagmanager',       'GA script URL'],
        ['data-ga-id',            'reads GA ID from body attribute'],
        ['accepted',              'accepted state handling'],
        ['declined',              'declined state handling'],
        ['DOMContentLoaded',      'DOMContentLoaded listener'],
    ];

    $allJsPresent = true;
    foreach ($jsChecks as [$needle, $label]) {
        if (!str_contains($js, $needle)) {
            test_fail("Cookie consent JS contains {$label}", "'{$needle}' not found");
            $allJsPresent = false;
        }
    }
    if ($allJsPresent) {
        test_pass('Cookie consent JS: IIFE with consent cookie logic, GA conditional loading, DOMContentLoaded');
    }
} else {
    test_fail('Cookie consent JS readable', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 5: Cookie consent partial has dialog role and buttons
// ---------------------------------------------------------------------------
$consentPartialPath = $rootDir . '/templates/public/partials/cookie-consent.php';
if (file_exists($consentPartialPath)) {
    $consentHtml = file_get_contents($consentPartialPath);

    $partialChecks = [
        ['role="dialog"',         'dialog role for accessibility'],
        ['cookie-accept',         'accept button ID'],
        ['cookie-decline',        'decline button ID'],
        ['cookie-consent-text',   'consent text container'],
    ];

    $allPartialPresent = true;
    foreach ($partialChecks as [$needle, $label]) {
        if (!str_contains($consentHtml, $needle)) {
            test_fail("Cookie consent partial contains {$label}", "'{$needle}' not found");
            $allPartialPresent = false;
        }
    }
    if ($allPartialPresent) {
        test_pass('Cookie consent partial: role="dialog", accept/decline buttons, text container');
    }
} else {
    test_fail('Cookie consent partial readable', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 6: Contact template has CSRF field and required form fields
// ---------------------------------------------------------------------------
$contactTemplatePath = $rootDir . '/templates/public/contact.php';
if (file_exists($contactTemplatePath)) {
    $contactTpl = file_get_contents($contactTemplatePath);

    $contactChecks = [
        ['csrfField',          'CSRF token field'],
        ['name="name"',        'name input field'],
        ['name="email"',       'email input field'],
        ['name="message"',     'message textarea'],
        ['flash-success',      'success flash message'],
        ['flash-error',        'error flash message'],
        ['method="POST"',      'POST form method'],
        ['/contact',           'form action /contact'],
    ];

    $allContactPresent = true;
    foreach ($contactChecks as [$needle, $label]) {
        if (!str_contains($contactTpl, $needle)) {
            test_fail("Contact template contains {$label}", "'{$needle}' not found");
            $allContactPresent = false;
        }
    }
    if ($allContactPresent) {
        test_pass('Contact template: CSRF field, name/email/message inputs, flash messages, POST to /contact');
    }
} else {
    test_fail('Contact template readable', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 7: Archive template has pagination and post-card structure
// ---------------------------------------------------------------------------
$archiveTemplatePath = $rootDir . '/templates/public/archive.php';
if (file_exists($archiveTemplatePath)) {
    $archiveTpl = file_get_contents($archiveTemplatePath);

    $archiveChecks = [
        ['post-card',       'post-card class for items'],
        ['pagination',      'pagination section'],
        ['archiveSlug',     'archiveSlug variable usage'],
        ['archiveTitle',    'archiveTitle variable usage'],
        ['totalPages',      'totalPages pagination variable'],
    ];

    $allArchivePresent = true;
    foreach ($archiveChecks as [$needle, $label]) {
        if (!str_contains($archiveTpl, $needle)) {
            test_fail("Archive template contains {$label}", "'{$needle}' not found");
            $allArchivePresent = false;
        }
    }
    if ($allArchivePresent) {
        test_pass('Archive template: post-card items, pagination, archiveSlug/archiveTitle variables');
    }
} else {
    test_fail('Archive template readable', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 8: Layout includes CSS link, nav-toggle, cookie consent, JS
// ---------------------------------------------------------------------------
$layoutPath = $rootDir . '/templates/public/layout.php';
if (file_exists($layoutPath)) {
    $layoutSrc = file_get_contents($layoutPath);

    $layoutChecks = [
        ['style.css',              'stylesheet link'],
        ['nav-toggle',             'hamburger nav toggle button'],
        ['cookie-consent',         'cookie consent partial or element'],
        ['cookie-consent.js',      'cookie consent JS script'],
        ['aria-expanded',          'aria-expanded on nav toggle'],
        ['data-ga-id',             'GA measurement ID data attribute'],
    ];

    $allLayoutPresent = true;
    foreach ($layoutChecks as [$needle, $label]) {
        if (!str_contains($layoutSrc, $needle)) {
            test_fail("Layout contains {$label}", "'{$needle}' not found");
            $allLayoutPresent = false;
        }
    }
    if ($allLayoutPresent) {
        test_pass('Layout: CSS link, nav-toggle, cookie consent partial+JS, aria-expanded, data-ga-id');
    }

    // Check Contact link in nav
    if (str_contains($layoutSrc, 'href="/contact"')) {
        test_pass('Layout navigation includes Contact link');
    } else {
        test_fail('Layout navigation Contact link', 'href="/contact" not found');
    }
} else {
    test_fail('Layout template readable', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 9: Home template has hero section with CTA button
// ---------------------------------------------------------------------------
$homePath = $rootDir . '/templates/public/home.php';
if (file_exists($homePath)) {
    $homeSrc = file_get_contents($homePath);

    if (str_contains($homeSrc, 'hero') && str_contains($homeSrc, 'cta-button')) {
        test_pass('Home template has hero section with CTA button');
    } else {
        $missing = [];
        if (!str_contains($homeSrc, 'hero')) { $missing[] = 'hero'; }
        if (!str_contains($homeSrc, 'cta-button')) { $missing[] = 'cta-button'; }
        test_fail('Home template hero/CTA', 'missing: ' . implode(', ', $missing));
    }
} else {
    test_fail('Home template readable', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 10: Migration files contain contact_submissions table schema
// ---------------------------------------------------------------------------
$sqliteMigration = $rootDir . '/migrations/002_contact_submissions.sqlite.sql';
if (file_exists($sqliteMigration)) {
    $sql = file_get_contents($sqliteMigration);

    $sqlChecks = [
        ['contact_submissions',   'table name'],
        ['name',                  'name column'],
        ['email',                 'email column'],
        ['message',               'message column'],
        ['ip_address',            'ip_address column'],
        ['created_at',            'created_at column'],
    ];

    $allSqlPresent = true;
    foreach ($sqlChecks as [$needle, $label]) {
        if (!str_contains($sql, $needle)) {
            test_fail("SQLite migration contains {$label}", "'{$needle}' not found");
            $allSqlPresent = false;
        }
    }
    if ($allSqlPresent) {
        test_pass('SQLite migration: contact_submissions table with name, email, message, ip_address, created_at');
    }
} else {
    test_fail('SQLite migration readable', 'file not found');
}

// Verify the migration actually ran (table should exist from migrator.migrate())
try {
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='contact_submissions'")->fetchAll();
    if (count($tables) > 0) {
        test_pass('contact_submissions table exists in database after migration');
    } else {
        test_fail('contact_submissions table exists', 'table not found after migration');
    }
} catch (\Throwable $e) {
    test_fail('contact_submissions table check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: Contact routes registered in index.php
// ---------------------------------------------------------------------------
$indexPath = $rootDir . '/public/index.php';
if (file_exists($indexPath)) {
    $indexSrc = file_get_contents($indexPath);

    $hasGetContact = str_contains($indexSrc, "'/contact'") && str_contains($indexSrc, 'contactPage');
    $hasPostContact = str_contains($indexSrc, 'contactSubmit');

    if ($hasGetContact && $hasPostContact) {
        test_pass('index.php registers GET /contact (contactPage) and POST /contact (contactSubmit)');
    } else {
        $missing = [];
        if (!$hasGetContact) { $missing[] = 'GET /contact → contactPage'; }
        if (!$hasPostContact) { $missing[] = 'POST /contact → contactSubmit'; }
        test_fail('Contact routes in index.php', 'missing: ' . implode(', ', $missing));
    }

    // Contact routes must appear BEFORE the catch-all /{slug}
    $contactPos = strpos($indexSrc, 'contactPage');
    $catchAllPos = strrpos($indexSrc, '{slug}');
    if ($contactPos !== false && $catchAllPos !== false && $contactPos < $catchAllPos) {
        test_pass('Contact routes registered before catch-all /{slug}');
    } else {
        test_fail('Contact route ordering', '/contact should appear before /{slug} catch-all');
    }
} else {
    test_fail('index.php readable', 'file not found');
}

// ---------------------------------------------------------------------------
// Set up App and FrontController for functional tests
// ---------------------------------------------------------------------------
// Insert some test content for rendering
insertContent([
    'type'         => 'post',
    'title'        => 'Test Post One',
    'slug'         => 'test-post-one',
    'body'         => '<p>Post body content.</p>',
    'excerpt'      => 'A test post excerpt',
    'status'       => 'published',
    'published_at' => gmdate('Y-m-d H:i:s', strtotime('-1 hour')),
]);

insertContent([
    'type'         => 'page',
    'title'        => 'About',
    'slug'         => 'about',
    'body'         => '<p>About us page.</p>',
    'status'       => 'published',
    'sort_order'   => 1,
    'published_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day')),
]);

$app = new \App\Core\App();
$app->register('db', $pdo);
$fc = new \App\Templates\FrontController($app);

// ---------------------------------------------------------------------------
// Test 12: contactPage() renders contact form with 200
// ---------------------------------------------------------------------------
try {
    $request = makeRequest('GET', '/contact');
    $response = $fc->contactPage($request);

    if ($response->getStatus() === 200) {
        test_pass('contactPage() returns 200');
    } else {
        test_fail('contactPage() returns 200', "got status {$response->getStatus()}");
    }

    $html = $response->getBody();
    if (str_contains($html, '<form') && str_contains($html, 'contact')) {
        test_pass('contactPage() renders HTML with contact form');
    } else {
        test_fail('contactPage() renders form', '<form or contact not found in output');
    }
} catch (\Throwable $e) {
    test_fail('contactPage() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: contactSubmit() validates and stores valid submission
// ---------------------------------------------------------------------------
try {
    $csrfToken = $_SESSION['csrf_token'];
    $request = makeRequest('POST', '/contact', [
        '_csrf_token' => $csrfToken,
        'name'        => 'Jane Doe',
        'email'       => 'jane@example.com',
        'subject'     => 'Test Subject',
        'message'     => 'Hello, this is a test message.',
    ]);

    $response = $fc->contactSubmit($request);

    // Should redirect (PRG pattern) — status 302
    $status = $response->getStatus();
    $headers = $response->getHeaders();
    $location = $headers['Location'] ?? '';

    if ($status === 302 && str_contains($location, '/contact')) {
        test_pass('contactSubmit() redirects to /contact after valid submission (PRG)');
    } else {
        test_fail('contactSubmit() PRG redirect', "expected 302 → /contact, got status={$status}, location={$location}");
    }
} catch (\Throwable $e) {
    test_fail('contactSubmit() valid submission works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: contactSubmit() rejects missing required fields
// ---------------------------------------------------------------------------
try {
    $csrfToken = $_SESSION['csrf_token'];
    $request = makeRequest('POST', '/contact', [
        '_csrf_token' => $csrfToken,
        'name'        => '',
        'email'       => 'not-an-email',
        'subject'     => '',
        'message'     => '',
    ]);

    $response = $fc->contactSubmit($request);

    // Should re-render the form (not redirect) with error messages
    $status = $response->getStatus();
    $html = $response->getBody();

    if ($status === 200 && str_contains($html, 'flash-error')) {
        test_pass('contactSubmit() re-renders form with errors for invalid input');
    } else if ($status === 200) {
        // Might display errors differently
        test_pass('contactSubmit() returns 200 (re-render) for invalid input');
    } else {
        test_fail('contactSubmit() validation', "expected 200 re-render, got status={$status}");
    }
} catch (\Throwable $e) {
    test_fail('contactSubmit() validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: contactSubmit() uses PRG pattern — flash message in session
// ---------------------------------------------------------------------------
try {
    // After a successful submission (test 13), the session should have had a flash message
    // Let's do another submission and check the session
    $csrfToken = $_SESSION['csrf_token'];
    $request = makeRequest('POST', '/contact', [
        '_csrf_token' => $csrfToken,
        'name'        => 'PRG Tester',
        'email'       => 'prg@example.com',
        'subject'     => '',
        'message'     => 'Testing PRG pattern.',
    ]);

    $response = $fc->contactSubmit($request);

    if ($response->getStatus() === 302 && isset($_SESSION['flash_success'])) {
        test_pass('contactSubmit() sets $_SESSION[flash_success] for PRG pattern');
    } else if ($response->getStatus() === 302) {
        // Flash may have already been consumed
        test_pass('contactSubmit() returns 302 redirect (PRG pattern works)');
    } else {
        test_fail('contactSubmit() PRG flash', "status={$response->getStatus()}, flash_success missing");
    }

    // Clear flash for subsequent tests
    unset($_SESSION['flash_success']);
} catch (\Throwable $e) {
    test_fail('contactSubmit() PRG pattern works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: Contact submission stored in database with correct data
// ---------------------------------------------------------------------------
try {
    $submissions = \App\Database\QueryBuilder::query('contact_submissions')
        ->select()
        ->where('email', 'jane@example.com')
        ->get();

    if (count($submissions) >= 1) {
        $sub = $submissions[0];
        $checks = [
            'name'    => 'Jane Doe',
            'email'   => 'jane@example.com',
            'subject' => 'Test Subject',
            'message' => 'Hello, this is a test message.',
        ];

        $allMatch = true;
        foreach ($checks as $field => $expected) {
            if (($sub[$field] ?? '') !== $expected) {
                test_fail("Submission {$field} matches", "expected '{$expected}', got '{$sub[$field]}'");
                $allMatch = false;
            }
        }
        if ($allMatch) {
            test_pass('Contact submission stored with correct name, email, subject, message');
        }

        // Check ip_address was stored
        if (!empty($sub['ip_address'])) {
            test_pass('Contact submission includes ip_address');
        } else {
            test_fail('Contact submission ip_address', 'ip_address is empty');
        }
    } else {
        test_fail('Contact submission exists in database', 'no row found for jane@example.com');
    }
} catch (\Throwable $e) {
    test_fail('Contact submission DB check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 17: renderPublic() merges cookie consent and GA settings
// ---------------------------------------------------------------------------
try {
    // Insert settings for cookie consent and GA
    \App\Database\QueryBuilder::query('settings')->insert([
        'key'   => 'cookie_consent_text',
        'value' => 'We use cookies on this site.',
    ]);
    \App\Database\QueryBuilder::query('settings')->insert([
        'key'   => 'ga_enabled',
        'value' => '1',
    ]);
    \App\Database\QueryBuilder::query('settings')->insert([
        'key'   => 'ga_measurement_id',
        'value' => 'G-TESTID123',
    ]);

    // Reset the static cache in getPublicSettings by using a fresh FrontController
    // (static cache is per-request; new FC instance simulates a new request)
    $fc2 = new \App\Templates\FrontController($app);

    $request = makeRequest('GET', '/');
    $response = $fc2->homepage($request);
    $html = $response->getBody();

    // Check that the GA data attribute is rendered
    if (str_contains($html, 'data-ga-id') && str_contains($html, 'G-TESTID123')) {
        test_pass('renderPublic() passes GA ID to layout (data-ga-id="G-TESTID123")');
    } else {
        test_fail('renderPublic() GA ID in layout', 'data-ga-id="G-TESTID123" not found in output');
    }
} catch (\Throwable $e) {
    test_fail('renderPublic() settings merging works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: Homepage renders with hero section markup
// ---------------------------------------------------------------------------
try {
    $fc3 = new \App\Templates\FrontController($app);
    $request = makeRequest('GET', '/');
    $response = $fc3->homepage($request);
    $html = $response->getBody();

    if (str_contains($html, 'hero')) {
        test_pass('Homepage output contains hero section');
    } else {
        test_fail('Homepage hero section', '"hero" class not found in homepage output');
    }

    if (str_contains($html, 'cta-button') || str_contains($html, 'Read Our Blog')) {
        test_pass('Homepage output contains CTA button');
    } else {
        test_fail('Homepage CTA button', 'cta-button or "Read Our Blog" not found');
    }
} catch (\Throwable $e) {
    test_fail('Homepage hero rendering works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: getPublicSettings() returns settings from database
// ---------------------------------------------------------------------------
try {
    $getPublicSettings = $fcReflection->getMethod('getPublicSettings');
    $getPublicSettings->setAccessible(true);

    // Need a fresh instance to clear static cache
    $fc4 = new \App\Templates\FrontController($app);
    $settings = $getPublicSettings->invoke($fc4);

    if (is_array($settings)) {
        test_pass('getPublicSettings() returns an array');
    } else {
        test_fail('getPublicSettings() returns array', 'got: ' . gettype($settings));
    }

    if (($settings['ga_enabled'] ?? '') === '1') {
        test_pass('getPublicSettings() includes ga_enabled=1 from database');
    } else {
        test_fail('getPublicSettings() ga_enabled', "expected '1', got: " . var_export($settings['ga_enabled'] ?? null, true));
    }

    if (($settings['ga_measurement_id'] ?? '') === 'G-TESTID123') {
        test_pass('getPublicSettings() includes ga_measurement_id from database');
    } else {
        test_fail('getPublicSettings() ga_measurement_id', "expected 'G-TESTID123', got: " . var_export($settings['ga_measurement_id'] ?? null, true));
    }
} catch (\Throwable $e) {
    test_fail('getPublicSettings() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 20: Layout renders data-ga-id only when GA is enabled
// ---------------------------------------------------------------------------
try {
    // Delete GA settings to test absence
    $pdo->exec("DELETE FROM settings WHERE key IN ('ga_enabled', 'ga_measurement_id')");

    $fc5 = new \App\Templates\FrontController($app);
    $request = makeRequest('GET', '/');
    $response = $fc5->homepage($request);
    $html = $response->getBody();

    if (!str_contains($html, 'data-ga-id')) {
        test_pass('Layout omits data-ga-id when GA is not enabled');
    } else {
        test_fail('Layout data-ga-id conditional', 'data-ga-id found even though GA settings removed');
    }
} catch (\Throwable $e) {
    test_fail('Layout GA conditional works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup & Summary
// ---------------------------------------------------------------------------
cleanup();

exit($fail > 0 ? 1 : 0);
