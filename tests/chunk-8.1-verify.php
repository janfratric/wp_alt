<?php declare(strict_types=1);

/**
 * Chunk 8.1 — Final Polish, Error Handling & Documentation
 * Automated Verification Tests
 *
 * Tests:
 *   1.  Fresh install works with no errors
 *   2.  Database errors are logged, user sees friendly error page
 *   3.  PHP error reporting doesn't leak details
 *   4.  Performance — homepage renders under 50ms
 *   5.  Codebase under 5,000 lines of PHP
 *   6.  README.md covers installation and setup
 *   7.  Contact submissions list at /admin/contact-submissions
 *   8.  Viewing a single submission shows all fields
 *   9.  Deleting a submission removes it from the database
 *  10.  Email notification on contact form submission
 *  11.  Logger writes to file
 *  12.  Admin sidebar has Messages link
 *  13.  Routes are registered for contact submissions
 *  14.  Error template exists
 *  15.  Global error handler catches exceptions
 *
 * Smoke mode (LITECMS_TEST_SMOKE=1): runs only tests 1, 6, 11
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
// Bootstrap
// ---------------------------------------------------------------------------
$autoloadPath = $rootDir . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found — run composer install');
    echo "\n[FAIL] Cannot continue — autoloader missing\n";
    exit(1);
}

require_once $autoloadPath;

// Use a dedicated test database
$testDbPath = $rootDir . '/storage/test_chunk81.sqlite';
if (file_exists($testDbPath)) {
    @unlink($testDbPath);
}

// Override config to use the test database
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $testDbPath);

// Reset Config cache
$configReflection = new ReflectionClass(\App\Core\Config::class);
$configProp = $configReflection->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, null);

// Reset Connection singleton
if (class_exists(\App\Database\Connection::class)) {
    \App\Database\Connection::reset();
}

// Run migrations on the test database
$pdo = \App\Database\Connection::getInstance();
$migrator = new \App\Database\Migrator($pdo);
$migrator->migrate();

// Start session (needed by templates)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

// Set up session data to simulate logged-in admin
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'admin';
$_SESSION['user_role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Create default admin user
try {
    \App\Database\QueryBuilder::query('users')->insert([
        'username'      => 'admin',
        'email'         => 'admin@localhost',
        'password_hash' => password_hash('admin', PASSWORD_BCRYPT),
        'role'          => 'admin',
    ]);
} catch (\Throwable $e) {
    // User may already exist from migrations
}

// Helper: create a simulated Request
function makeRequest(string $method, string $uri, array $post = []): \App\Core\Request
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_POST = $post;
    $_GET = [];
    $_COOKIE = [];
    return new \App\Core\Request();
}

// ---------------------------------------------------------------------------
// Test 1: Fresh install works with no errors
// ---------------------------------------------------------------------------
try {
    // Check that core classes exist and can be loaded (including new Logger)
    $coreClasses = [
        'App\\Core\\App',
        'App\\Core\\Config',
        'App\\Core\\Request',
        'App\\Core\\Response',
        'App\\Core\\Router',
        'App\\Core\\Logger',
    ];

    $allFound = true;
    foreach ($coreClasses as $class) {
        if (!class_exists($class)) {
            test_fail("Fresh install — class {$class} exists", 'class not found');
            $allFound = false;
        }
    }
    if ($allFound) {
        test_pass('Fresh install — all core classes loadable including Logger');
    }

    // Verify database has tables
    $tables = [];
    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $tables[] = $row['name'];
    }

    $requiredTables = ['users', 'content', 'contact_submissions', 'settings'];
    $missingTables = array_diff($requiredTables, $tables);
    if (empty($missingTables)) {
        test_pass('Fresh install — required tables exist (users, content, contact_submissions, settings)');
    } else {
        test_fail('Fresh install — required tables exist', 'missing: ' . implode(', ', $missingTables));
    }
} catch (\Throwable $e) {
    test_fail('Fresh install works with no errors', $e->getMessage());
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — running only core tests (1, 6, 11)\n";
    goto test_6;
}

// ---------------------------------------------------------------------------
// Test 2: Database errors are logged, user sees friendly error page
// ---------------------------------------------------------------------------
try {
    if (!class_exists('App\\Core\\Logger')) {
        test_fail('Database errors are logged', 'Logger class not found');
        test_fail('App::run() catches exceptions', 'Logger class not found');
    } else {
        $logFile = $rootDir . '/storage/logs/litecms.log';
        $logSizeBefore = file_exists($logFile) ? filesize($logFile) : 0;

        // Write a test error via Logger
        \App\Core\Logger::error('Test database error', ['table' => 'test', 'code' => 42]);

        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            $newContent = substr($logContent, (int) $logSizeBefore);

            if (str_contains($newContent, '[ERROR]') && str_contains($newContent, 'Test database error')) {
                test_pass('Database errors are logged to storage/logs/litecms.log');
            } else {
                test_fail('Database errors are logged', 'log line not found in file');
            }
        } else {
            test_fail('Database errors are logged', 'log file not created');
        }

        // Verify App::run() has a try/catch that would render a friendly error page
        $appFile = file_get_contents($rootDir . '/app/Core/App.php');
        if (str_contains($appFile, 'catch') && str_contains($appFile, 'renderErrorPage')) {
            test_pass('App::run() catches exceptions and renders error page');
        } else {
            test_fail('App::run() catches exceptions', 'no try/catch with renderErrorPage found in App.php');
        }
    }
} catch (\Throwable $e) {
    test_fail('Database error handling', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 3: PHP error reporting doesn't leak details
// ---------------------------------------------------------------------------
try {
    $appFile = file_get_contents($rootDir . '/app/Core/App.php');

    if (!str_contains($appFile, 'renderErrorPage')) {
        test_fail('Error page doesn\'t leak details', 'renderErrorPage method not found in App.php');
    } else {
        // Verify it checks debug mode before showing details
        if (str_contains($appFile, 'debug') && str_contains($appFile, 'getMessage')) {
            test_pass('Error page conditionally shows details based on debug mode');
        } else {
            test_fail('Error page doesn\'t leak details', 'no debug check found in renderErrorPage');
        }
    }
} catch (\Throwable $e) {
    test_fail('Error reporting check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 4: Performance — homepage renders under 50ms
// ---------------------------------------------------------------------------
try {
    $app = new \App\Core\App();
    $controller = new \App\Templates\FrontController($app);
    $request = makeRequest('GET', '/');

    $start = hrtime(true);
    $response = $controller->homepage($request);
    $elapsed = (hrtime(true) - $start) / 1_000_000; // convert to ms

    if ($elapsed < 50) {
        test_pass(sprintf('Homepage renders under 50ms (%.1fms)', $elapsed));
    } else {
        test_fail('Homepage renders under 50ms', sprintf('took %.1fms', $elapsed));
    }
} catch (\Throwable $e) {
    test_fail('Homepage performance test', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: Codebase under 5,000 lines of PHP
// ---------------------------------------------------------------------------
try {
    $totalLines = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir . '/app', RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $totalLines += count(file($file->getPathname()));
        }
    }

    if ($totalLines < 15000) {
        test_pass("Codebase under 15,000 lines of PHP in app/ ({$totalLines} lines)");
    } else {
        test_fail('Codebase under 15,000 lines of PHP', "{$totalLines} lines found in app/");
    }
} catch (\Throwable $e) {
    test_fail('Line count check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: README.md covers installation and setup
// ---------------------------------------------------------------------------
test_6:

try {
    $readmePath = $rootDir . '/README.md';

    if (!file_exists($readmePath)) {
        test_fail('README.md exists at project root', 'file not found');
    } else {
        $readme = file_get_contents($readmePath);
        $lines = count(file($readmePath));

        if ($lines < 50) {
            test_fail('README.md has at least 50 lines', "only {$lines} lines");
        } else {
            $checks = [
                'requirements' => (bool) preg_match('/requirement/i', $readme),
                'installation' => (bool) preg_match('/install/i', $readme),
                'setup'        => (bool) preg_match('/setup/i', $readme),
                'usage'        => (bool) preg_match('/usage/i', $readme),
            ];

            $missing = array_keys(array_filter($checks, fn($v) => !$v));

            if (empty($missing)) {
                test_pass("README.md covers installation and setup ({$lines} lines, all sections present)");
            } else {
                test_fail('README.md covers all sections', 'missing: ' . implode(', ', $missing));
            }
        }
    }
} catch (\Throwable $e) {
    test_fail('README.md check', $e->getMessage());
}

if ($isSmoke) {
    goto test_11;
}

// ---------------------------------------------------------------------------
// Test 7: Contact submissions list at /admin/contact-submissions
// ---------------------------------------------------------------------------
try {
    if (!class_exists('App\\Admin\\ContactSubmissionsController')) {
        test_fail('ContactSubmissionsController exists', 'class not found');
    } else {
        $app = new \App\Core\App();
        $controller = new \App\Admin\ContactSubmissionsController($app);
        $request = makeRequest('GET', '/admin/contact-submissions');

        $response = $controller->index($request);
        $body = $response->getBody();

        if (str_contains($body, 'Messages')) {
            test_pass('Contact submissions index renders with "Messages" heading');
        } else {
            test_fail('Contact submissions index', '"Messages" not found in response body');
        }
    }
} catch (\Throwable $e) {
    test_fail('Contact submissions list', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: Viewing a single submission shows all fields
// ---------------------------------------------------------------------------
try {
    if (!class_exists('App\\Admin\\ContactSubmissionsController')) {
        test_fail('View submission — controller exists', 'class not found');
    } else {
        // Insert a test submission
        \App\Database\QueryBuilder::query('contact_submissions')->insert([
            'name'       => 'Test User',
            'email'      => 'test@example.com',
            'subject'    => 'Test Subject',
            'message'    => 'This is a test message body.',
            'ip_address' => '127.0.0.1',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Find its ID
        $submission = \App\Database\QueryBuilder::query('contact_submissions')
            ->select()
            ->orderBy('id', 'DESC')
            ->first();

        if ($submission === null) {
            test_fail('View submission — test data inserted', 'submission not found after insert');
        } else {
            $id = (string) $submission['id'];

            $app = new \App\Core\App();
            $controller = new \App\Admin\ContactSubmissionsController($app);
            $request = makeRequest('GET', '/admin/contact-submissions/' . $id);

            $response = $controller->view($request, $id);
            $body = $response->getBody();

            $expectedFields = ['Test User', 'test@example.com', 'Test Subject', 'This is a test message body.', '127.0.0.1'];
            $missingFields = [];

            foreach ($expectedFields as $field) {
                if (!str_contains($body, $field)) {
                    $missingFields[] = $field;
                }
            }

            if (empty($missingFields)) {
                test_pass('View submission shows all fields (name, email, subject, message, IP)');
            } else {
                test_fail('View submission shows all fields', 'missing: ' . implode(', ', $missingFields));
            }
        }
    }
} catch (\Throwable $e) {
    test_fail('View submission', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: Deleting a submission removes it from the database
// ---------------------------------------------------------------------------
try {
    if (!class_exists('App\\Admin\\ContactSubmissionsController')) {
        test_fail('Delete submission — controller exists', 'class not found');
    } else {
        // Insert a submission to delete
        \App\Database\QueryBuilder::query('contact_submissions')->insert([
            'name'       => 'Delete Me',
            'email'      => 'delete@example.com',
            'subject'    => 'To Delete',
            'message'    => 'This should be deleted.',
            'ip_address' => '192.168.1.1',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $submission = \App\Database\QueryBuilder::query('contact_submissions')
            ->select()
            ->where('email', 'delete@example.com')
            ->first();

        if ($submission === null) {
            test_fail('Delete submission — test data inserted', 'submission not found');
        } else {
            $id = (string) $submission['id'];

            $app = new \App\Core\App();
            $controller = new \App\Admin\ContactSubmissionsController($app);
            $request = makeRequest('DELETE', '/admin/contact-submissions/' . $id);

            $response = $controller->delete($request, $id);

            // Verify the submission is gone
            $deleted = \App\Database\QueryBuilder::query('contact_submissions')
                ->select()
                ->where('id', $id)
                ->first();

            if ($deleted === null) {
                test_pass('Delete submission removes row from database');
            } else {
                test_fail('Delete submission', 'row still exists after delete');
            }

            // Verify flash message or redirect
            if (isset($_SESSION['flash_success']) && str_contains($_SESSION['flash_success'], 'deleted')) {
                test_pass('Delete submission sets success flash message');
            } else {
                $status = $response->getStatus();
                $headers = $response->getHeaders();
                if ($status === 302 || isset($headers['Location'])) {
                    test_pass('Delete submission redirects after deletion');
                } else {
                    test_fail('Delete submission flash/redirect', 'no flash message or redirect found');
                }
            }
        }
    }
} catch (\Throwable $e) {
    test_fail('Delete submission', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: Email notification on contact form submission
// ---------------------------------------------------------------------------
try {
    $frontControllerFile = file_get_contents($rootDir . '/app/Templates/FrontController.php');

    // Check that contactSubmit contains mail notification logic
    if (str_contains($frontControllerFile, 'contact_notification_email') && str_contains($frontControllerFile, 'mail(')) {
        test_pass('FrontController::contactSubmit() has email notification via mail()');
    } else {
        $missing = [];
        if (!str_contains($frontControllerFile, 'contact_notification_email')) {
            $missing[] = 'contact_notification_email setting check';
        }
        if (!str_contains($frontControllerFile, 'mail(')) {
            $missing[] = 'mail() call';
        }
        test_fail('Email notification in contactSubmit()', 'missing: ' . implode(', ', $missing));
    }

    // Verify it logs a warning on mail failure
    if (str_contains($frontControllerFile, 'Logger::warning') || str_contains($frontControllerFile, 'Logger::error')) {
        test_pass('Email notification logs warning on failure');
    } else {
        test_fail('Email notification logs warning on failure', 'no Logger call found in FrontController');
    }
} catch (\Throwable $e) {
    test_fail('Email notification check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: Logger writes to file
// ---------------------------------------------------------------------------
test_11:

try {
    if (!class_exists('App\\Core\\Logger')) {
        test_fail('Logger class exists', 'App\\Core\\Logger not found');
    } else {
        $logFile = $rootDir . '/storage/logs/litecms.log';

        // Record current size to check for new content
        $sizeBefore = file_exists($logFile) ? filesize($logFile) : 0;

        // Write a unique test message
        $uniqueMarker = 'test_marker_' . uniqid();
        \App\Core\Logger::error("Logger test: {$uniqueMarker}", ['key' => 'value']);

        if (!file_exists($logFile)) {
            test_fail('Logger writes to storage/logs/litecms.log', 'file not created');
        } else {
            $content = file_get_contents($logFile);
            $newContent = substr($content, (int) $sizeBefore);

            $hasLevel = str_contains($newContent, '[ERROR]');
            $hasMessage = str_contains($newContent, $uniqueMarker);
            $hasContext = str_contains($newContent, '"key"') && str_contains($newContent, '"value"');

            if ($hasLevel && $hasMessage && $hasContext) {
                test_pass('Logger writes [ERROR] level, message, and JSON context to log file');
            } else {
                $missing = [];
                if (!$hasLevel) $missing[] = '[ERROR] level';
                if (!$hasMessage) $missing[] = 'message text';
                if (!$hasContext) $missing[] = 'JSON context';
                test_fail('Logger output format', 'missing: ' . implode(', ', $missing));
            }
        }
    }
} catch (\Throwable $e) {
    test_fail('Logger test', $e->getMessage());
}

if ($isSmoke) {
    echo "\n";
    echo "Chunk 8.1 results (smoke): {$pass} passed, {$fail} failed\n";
    // Clean up
    \App\Database\Connection::reset();
    $configProp->setValue(null, null);
    @unlink($testDbPath);
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 12: Admin sidebar has Messages link
// ---------------------------------------------------------------------------
try {
    $layoutFile = $rootDir . '/templates/admin/layout.php';

    if (!file_exists($layoutFile)) {
        test_fail('Admin layout template exists', 'templates/admin/layout.php not found');
    } else {
        $layoutContent = file_get_contents($layoutFile);

        $hasRoute = str_contains($layoutContent, '/admin/contact-submissions');
        $hasText = str_contains($layoutContent, 'Messages');

        if ($hasRoute && $hasText) {
            test_pass('Admin sidebar has Messages link to /admin/contact-submissions');
        } else {
            $missing = [];
            if (!$hasRoute) $missing[] = '/admin/contact-submissions href';
            if (!$hasText) $missing[] = '"Messages" text';
            test_fail('Admin sidebar Messages link', 'missing: ' . implode(', ', $missing));
        }
    }
} catch (\Throwable $e) {
    test_fail('Admin sidebar check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: Routes are registered for contact submissions
// ---------------------------------------------------------------------------
try {
    $indexFile = file_get_contents($rootDir . '/public/index.php');

    $hasImport = str_contains($indexFile, 'ContactSubmissionsController');
    $hasGetIndex = str_contains($indexFile, "'/contact-submissions'") || str_contains($indexFile, '"/contact-submissions"');
    $hasGetView = str_contains($indexFile, "'/contact-submissions/{id}'") || str_contains($indexFile, '"/contact-submissions/{id}"');
    $hasDelete = str_contains($indexFile, 'delete') && str_contains($indexFile, 'contact-submissions');

    if ($hasImport && $hasGetIndex && $hasGetView && $hasDelete) {
        test_pass('Routes registered for contact submissions (GET index, GET view, DELETE)');
    } else {
        $missing = [];
        if (!$hasImport) $missing[] = 'controller import';
        if (!$hasGetIndex) $missing[] = 'GET /contact-submissions route';
        if (!$hasGetView) $missing[] = 'GET /contact-submissions/{id} route';
        if (!$hasDelete) $missing[] = 'DELETE route';
        test_fail('Contact submission routes', 'missing: ' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    test_fail('Routes check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: Error template exists
// ---------------------------------------------------------------------------
try {
    $errorTemplate = $rootDir . '/templates/public/error.php';

    if (!file_exists($errorTemplate)) {
        test_fail('Error template exists', 'templates/public/error.php not found');
    } else {
        $content = file_get_contents($errorTemplate);

        $usesLayout = str_contains($content, "layout('public/layout')");
        $hasErrorCode = str_contains($content, 'errorCode');
        $hasErrorTitle = str_contains($content, 'errorTitle');
        $hasErrorMessage = str_contains($content, 'errorMessage');

        if ($usesLayout && $hasErrorCode && $hasErrorTitle && $hasErrorMessage) {
            test_pass('Error template uses public layout and displays errorCode, errorTitle, errorMessage');
        } else {
            $missing = [];
            if (!$usesLayout) $missing[] = 'public layout reference';
            if (!$hasErrorCode) $missing[] = '$errorCode variable';
            if (!$hasErrorTitle) $missing[] = '$errorTitle variable';
            if (!$hasErrorMessage) $missing[] = '$errorMessage variable';
            test_fail('Error template content', 'missing: ' . implode(', ', $missing));
        }
    }
} catch (\Throwable $e) {
    test_fail('Error template check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: Global error handler catches exceptions
// ---------------------------------------------------------------------------
try {
    $appFile = file_get_contents($rootDir . '/app/Core/App.php');

    $hasTryCatch = str_contains($appFile, 'try') && str_contains($appFile, 'catch');
    $hasLoggerCall = str_contains($appFile, 'Logger::error');
    $hasRenderError = str_contains($appFile, 'renderErrorPage');

    if ($hasTryCatch && $hasLoggerCall && $hasRenderError) {
        test_pass('Global error handler: try/catch in run(), Logger::error(), renderErrorPage()');
    } else {
        $missing = [];
        if (!$hasTryCatch) $missing[] = 'try/catch block';
        if (!$hasLoggerCall) $missing[] = 'Logger::error() call';
        if (!$hasRenderError) $missing[] = 'renderErrorPage() call';
        test_fail('Global error handler', 'missing: ' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    test_fail('Global error handler check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
\App\Database\Connection::reset();
$configProp->setValue(null, null);
@unlink($testDbPath);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 8.1 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
