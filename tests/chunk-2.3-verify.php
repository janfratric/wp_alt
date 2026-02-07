<?php declare(strict_types=1);

/**
 * Chunk 2.3 — Media Management
 * Automated Verification Tests
 *
 * Tests:
 *   1. MediaController class is autoloadable
 *   2. Required files exist (template, .htaccess, CSS additions, JS additions)
 *   3. Request::file() and Request::hasFile() methods exist
 *   [SMOKE STOP]
 *   4. Config has max_upload_size key
 *   5. MediaController::index() renders media library with 200 status
 *   6. MediaController constants define correct allowed extensions and MIME types
 *   7. MediaController::delete() removes media record from database
 *   8. MediaController::browse() returns JSON with items array
 *   9. Uploads .htaccess disables PHP execution
 *  10. Media index template has upload form with enctype=multipart/form-data
 *  11. Content edit template has media browser button (featured-image-browse)
 *  12. Editor.js has media browser modal and images_upload_handler
 *  13. Admin CSS has media grid and modal styles
 *  14. Routes registered: GET /media, GET /media/browse, POST /media/upload, DELETE /media/{id}
 *  15. Response includes security headers (X-Frame-Options, CSP)
 *  16. MediaController::index() paginates media items
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
$testDbPath = $rootDir . '/storage/test_chunk23.sqlite';

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
    echo "Chunk 2.3 results: {$pass} passed, {$fail} failed\n";
}

// ---------------------------------------------------------------------------
// Test 1: MediaController class is autoloadable
// ---------------------------------------------------------------------------
$controllerClass = 'App\\Admin\\MediaController';

if (!class_exists($controllerClass)) {
    test_fail('MediaController is autoloadable', "class {$controllerClass} not found");
    cleanup();
    exit(1);
} else {
    test_pass('MediaController is autoloadable');
}

// ---------------------------------------------------------------------------
// Test 2: Required files exist
// ---------------------------------------------------------------------------
$requiredFiles = [
    'app/Admin/MediaController.php'          => 'MediaController class file',
    'templates/admin/media/index.php'        => 'Media library template',
    'public/assets/uploads/.htaccess'        => 'Uploads security .htaccess',
    'public/assets/js/editor.js'             => 'Editor JavaScript',
    'public/assets/css/admin.css'            => 'Admin CSS',
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
    test_pass('All required files exist: MediaController, media/index template, uploads .htaccess, editor.js, admin.css');
}

// ---------------------------------------------------------------------------
// Test 3: Request::file() and Request::hasFile() methods exist
// ---------------------------------------------------------------------------
try {
    $requestReflection = new ReflectionClass(\App\Core\Request::class);

    $hasFileMethod = $requestReflection->hasMethod('file');
    $hasHasFileMethod = $requestReflection->hasMethod('hasFile');

    if ($hasFileMethod && $hasHasFileMethod) {
        // Verify method signatures
        $fileMethod = $requestReflection->getMethod('file');
        $hasFileMethodRef = $requestReflection->getMethod('hasFile');

        $fileParams = $fileMethod->getParameters();
        $hasFileParams = $hasFileMethodRef->getParameters();

        $fileParamOk = count($fileParams) === 1 && $fileParams[0]->getName() === 'key';
        $hasFileParamOk = count($hasFileParams) === 1 && $hasFileParams[0]->getName() === 'key';

        if ($fileParamOk && $hasFileParamOk) {
            test_pass('Request::file(string $key) and Request::hasFile(string $key) methods exist with correct signatures');
        } else {
            test_fail('Request file methods signatures', 'parameter names or counts do not match');
        }
    } else {
        test_fail('Request file upload methods', "file()={$hasFileMethod}, hasFile()={$hasHasFileMethod}");
    }

    // Test hasFile returns false when no file uploaded
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_GET = [];
    $_POST = [];
    $req = new \App\Core\Request();
    if ($req->hasFile('nonexistent') === false) {
        test_pass('Request::hasFile() returns false when no file is present');
    } else {
        test_fail('Request::hasFile() false check', 'should return false for missing file');
    }
} catch (\Throwable $e) {
    test_fail('Request file methods check', $e->getMessage());
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    cleanup();
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: Config has max_upload_size key
// ---------------------------------------------------------------------------
try {
    $maxUpload = \App\Core\Config::getInt('max_upload_size', 0);
    if ($maxUpload > 0) {
        test_pass("Config max_upload_size is set: {$maxUpload} bytes (" . round($maxUpload / 1048576, 1) . " MB)");
    } else {
        test_fail('Config max_upload_size', 'expected a positive integer, got: ' . var_export($maxUpload, true));
    }
} catch (\Throwable $e) {
    test_fail('Config max_upload_size check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: MediaController::index() renders media library with 200 status
// ---------------------------------------------------------------------------
$app = null;
$controller = null;

try {
    $app = new \App\Core\App();
    $app->register('db', $pdo);

    $controller = new \App\Admin\MediaController($app);

    // Clear flash messages
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $request = makeRequest('GET', '/admin/media');
    $response = $controller->index($request);
    $html = $response->getBody();

    if ($response->getStatus() === 200 && strlen($html) > 100) {
        // Should contain upload form and media library heading
        $hasUploadForm = stripos($html, 'enctype') !== false && stripos($html, 'multipart') !== false;
        $hasTitle = stripos($html, 'Media Library') !== false || stripos($html, 'media') !== false;

        if ($hasUploadForm && $hasTitle) {
            test_pass('MediaController::index() returns 200 with upload form and media library heading');
        } else {
            test_fail('MediaController::index() content', "uploadForm={$hasUploadForm}, title={$hasTitle}");
        }
    } else {
        test_fail('MediaController::index() returns 200', "status={$response->getStatus()}, bodyLen=" . strlen($html));
    }
} catch (\Throwable $e) {
    test_fail('MediaController::index() works without errors', $e->getMessage());
}

if ($controller === null) {
    echo "\n[FAIL] Cannot continue — MediaController not available\n";
    cleanup();
    exit(1);
}

// ---------------------------------------------------------------------------
// Test 6: MediaController constants define correct allowed extensions and MIME types
// ---------------------------------------------------------------------------
try {
    $ref = new ReflectionClass(\App\Admin\MediaController::class);

    $extConst = $ref->getConstant('ALLOWED_EXTENSIONS');
    $mimeConst = $ref->getConstant('ALLOWED_MIMES');

    if ($extConst === false || $mimeConst === false) {
        test_fail('MediaController constants', 'ALLOWED_EXTENSIONS or ALLOWED_MIMES not found');
    } else {
        // Check that jpg, png, pdf are in allowed extensions
        $hasJpg = in_array('jpg', $extConst, true);
        $hasPng = in_array('png', $extConst, true);
        $hasPdf = in_array('pdf', $extConst, true);
        // Check that .php is NOT allowed
        $hasPhp = in_array('php', $extConst, true);

        $hasMimeJpeg = in_array('image/jpeg', $mimeConst, true);
        $hasMimePng = in_array('image/png', $mimeConst, true);
        $hasMimePdf = in_array('application/pdf', $mimeConst, true);

        if ($hasJpg && $hasPng && $hasPdf && !$hasPhp && $hasMimeJpeg && $hasMimePng && $hasMimePdf) {
            test_pass('ALLOWED_EXTENSIONS includes jpg/png/pdf, excludes php; ALLOWED_MIMES matches');
        } else {
            test_fail('Constants validation', "jpg={$hasJpg}, png={$hasPng}, pdf={$hasPdf}, php={$hasPhp}");
        }
    }
} catch (\Throwable $e) {
    test_fail('MediaController constants check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: MediaController::delete() removes media record from database
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Insert a fake media record directly into the database
    $mediaId = \App\Database\QueryBuilder::query('media')->insert([
        'filename'      => '2026/02/fakefile123456789abcdef012345678.jpg',
        'original_name' => 'test-photo.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 12345,
        'uploaded_by'   => 1,
    ]);

    $request = makeRequest('POST', "/admin/media/{$mediaId}", [
        '_method' => 'DELETE',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->delete($request, $mediaId);
    $status = $response->getStatus();

    $gone = \App\Database\QueryBuilder::query('media')
        ->select()
        ->where('id', (int)$mediaId)
        ->first();

    if ($status === 302 && $gone === null) {
        test_pass('delete() removes media record from database and redirects');
    } else {
        test_fail('delete()', "status={$status}, still_exists=" . ($gone !== null ? 'yes' : 'no'));
    }
} catch (\Throwable $e) {
    test_fail('delete() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: MediaController::browse() returns JSON with items array
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Insert a couple of media records for browse
    \App\Database\QueryBuilder::query('media')->insert([
        'filename'      => '2026/02/browse1aaaabbbbccccdddd11112222.jpg',
        'original_name' => 'browse-test-1.jpg',
        'mime_type'     => 'image/jpeg',
        'size_bytes'    => 5000,
        'uploaded_by'   => 1,
    ]);
    \App\Database\QueryBuilder::query('media')->insert([
        'filename'      => '2026/02/browse2aaaabbbbccccdddd11112222.png',
        'original_name' => 'browse-test-2.png',
        'mime_type'     => 'image/png',
        'size_bytes'    => 8000,
        'uploaded_by'   => 1,
    ]);
    // Insert a PDF (should be excluded when type=image)
    $pdfId = \App\Database\QueryBuilder::query('media')->insert([
        'filename'      => '2026/02/pdffileaaaabbbbccccdddd11112222.pdf',
        'original_name' => 'document.pdf',
        'mime_type'     => 'application/pdf',
        'size_bytes'    => 20000,
        'uploaded_by'   => 1,
    ]);

    // Set AJAX header for JSON response detection
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $request = makeRequest('GET', '/admin/media/browse', [], ['type' => 'image', 'page' => '1']);
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    // Re-create request to pick up the header
    $request = new \App\Core\Request();

    $response = $controller->browse($request);
    $body = $response->getBody();
    $data = json_decode($body, true);

    // Clean up AJAX header
    unset($_SERVER['HTTP_X_REQUESTED_WITH']);

    if ($data !== null && isset($data['items']) && is_array($data['items'])) {
        // Should have images but not the PDF when type=image
        $hasImages = count($data['items']) >= 2;
        $pdfFound = false;
        foreach ($data['items'] as $item) {
            if (str_contains($item['mime_type'] ?? '', 'pdf')) {
                $pdfFound = true;
            }
        }

        if ($hasImages && !$pdfFound && isset($data['page']) && isset($data['totalPages'])) {
            test_pass('browse() returns JSON with image items (PDF excluded), page, and totalPages');
        } else {
            test_fail('browse() content', "imageCount=" . count($data['items']) . ", pdfFound={$pdfFound}");
        }
    } else {
        test_fail('browse() JSON structure', 'expected {items: [...], page, totalPages}, got: ' . substr($body, 0, 200));
    }

    // Each item should have required fields
    if (!empty($data['items'])) {
        $item = $data['items'][0];
        $hasId = isset($item['id']);
        $hasUrl = isset($item['url']) && str_contains($item['url'], '/assets/uploads/');
        $hasOrigName = isset($item['original_name']);
        $hasMime = isset($item['mime_type']);

        if ($hasId && $hasUrl && $hasOrigName && $hasMime) {
            test_pass('browse() items contain id, url, original_name, and mime_type fields');
        } else {
            test_fail('browse() item fields', "id={$hasId}, url={$hasUrl}, name={$hasOrigName}, mime={$hasMime}");
        }
    } else {
        test_skip('browse() item fields — no items returned');
    }

    // Clean up test media records
    \App\Database\QueryBuilder::query('media')->where('original_name', 'browse-test-1.jpg')->delete();
    \App\Database\QueryBuilder::query('media')->where('original_name', 'browse-test-2.png')->delete();
    \App\Database\QueryBuilder::query('media')->where('id', (int)$pdfId)->delete();
} catch (\Throwable $e) {
    test_fail('browse() works without errors', $e->getMessage());
    unset($_SERVER['HTTP_X_REQUESTED_WITH']);
}

// ---------------------------------------------------------------------------
// Test 9: Uploads .htaccess disables PHP execution
// ---------------------------------------------------------------------------
try {
    $htaccessPath = $rootDir . '/public/assets/uploads/.htaccess';
    if (!file_exists($htaccessPath)) {
        test_fail('Uploads .htaccess exists');
    } else {
        $htaccess = file_get_contents($htaccessPath);
        $hasPhpOff = stripos($htaccess, 'engine off') !== false;
        $hasFilesMatch = stripos($htaccess, 'FilesMatch') !== false && stripos($htaccess, '.php') !== false;
        $hasNoSniff = stripos($htaccess, 'nosniff') !== false;

        if ($hasPhpOff || $hasFilesMatch) {
            test_pass('Uploads .htaccess disables PHP execution (engine off or FilesMatch deny)');
        } else {
            test_fail('Uploads .htaccess security', 'missing PHP execution prevention rules');
        }
    }
} catch (\Throwable $e) {
    test_fail('Uploads .htaccess check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: Media index template has upload form with enctype
// ---------------------------------------------------------------------------
try {
    $templatePath = $rootDir . '/templates/admin/media/index.php';
    $templateContent = file_get_contents($templatePath);

    $hasEnctype = stripos($templateContent, 'multipart/form-data') !== false;
    $hasFileInput = stripos($templateContent, 'type="file"') !== false || stripos($templateContent, "type='file'") !== false;
    $hasCsrf = stripos($templateContent, 'csrfField') !== false || stripos($templateContent, 'csrf_token') !== false;
    $hasMediaGrid = stripos($templateContent, 'media-grid') !== false || stripos($templateContent, 'media-card') !== false;

    if ($hasEnctype && $hasFileInput && $hasCsrf && $hasMediaGrid) {
        test_pass('Media template has multipart form, file input, CSRF field, and media grid');
    } else {
        test_fail('Media template content', "enctype={$hasEnctype}, fileInput={$hasFileInput}, csrf={$hasCsrf}, grid={$hasMediaGrid}");
    }
} catch (\Throwable $e) {
    test_fail('Media template check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: Content edit template has media browser button
// ---------------------------------------------------------------------------
try {
    $editTemplatePath = $rootDir . '/templates/admin/content/edit.php';
    $editContent = file_get_contents($editTemplatePath);

    $hasBrowseBtn = stripos($editContent, 'featured-image-browse') !== false;
    $hasPreviewImg = stripos($editContent, 'featured-image-preview') !== false;
    $hasHiddenInput = stripos($editContent, 'type="hidden"') !== false
                      && stripos($editContent, 'featured_image') !== false;

    // Should no longer have the "Media browser coming in a future update" placeholder
    $hasOldPlaceholder = stripos($editContent, 'Media browser coming in a future update') !== false;

    if ($hasBrowseBtn && $hasPreviewImg && !$hasOldPlaceholder) {
        test_pass('Content edit template has Browse Media button, preview image, and no old placeholder');
    } else {
        test_fail('Content edit featured image', "browseBtn={$hasBrowseBtn}, preview={$hasPreviewImg}, oldPlaceholder={$hasOldPlaceholder}");
    }
} catch (\Throwable $e) {
    test_fail('Content edit template check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: Editor.js has media browser modal and images_upload_handler
// ---------------------------------------------------------------------------
try {
    $editorPath = $rootDir . '/public/assets/js/editor.js';
    $editorContent = file_get_contents($editorPath);

    $hasUploadHandler = stripos($editorContent, 'images_upload_handler') !== false;
    $hasMediaBrowser = stripos($editorContent, 'openMediaBrowser') !== false
                       || stripos($editorContent, 'mediabrowser') !== false;
    $hasFeaturedPicker = stripos($editorContent, 'featured-image-browse') !== false;
    $hasUploadEndpoint = stripos($editorContent, '/admin/media/upload') !== false;
    $hasBrowseEndpoint = stripos($editorContent, '/admin/media/browse') !== false;

    if ($hasUploadHandler && $hasMediaBrowser && $hasFeaturedPicker && $hasUploadEndpoint && $hasBrowseEndpoint) {
        test_pass('editor.js has images_upload_handler, media browser modal, featured image picker, and correct endpoints');
    } else {
        test_fail('editor.js media features',
            "uploadHandler={$hasUploadHandler}, mediaBrowser={$hasMediaBrowser}, "
            . "featured={$hasFeaturedPicker}, uploadEndpoint={$hasUploadEndpoint}, browseEndpoint={$hasBrowseEndpoint}");
    }
} catch (\Throwable $e) {
    test_fail('editor.js check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: Admin CSS has media grid and modal styles
// ---------------------------------------------------------------------------
try {
    $cssPath = $rootDir . '/public/assets/css/admin.css';
    $cssContent = file_get_contents($cssPath);

    $hasUploadZone = stripos($cssContent, '.upload-zone') !== false;
    $hasMediaGrid = stripos($cssContent, '.media-grid') !== false;
    $hasMediaCard = stripos($cssContent, '.media-card') !== false;
    $hasModal = stripos($cssContent, '.media-modal') !== false;
    $hasFeaturedPreview = stripos($cssContent, '.featured-image-preview') !== false;

    if ($hasUploadZone && $hasMediaGrid && $hasMediaCard && $hasModal && $hasFeaturedPreview) {
        test_pass('admin.css has upload-zone, media-grid, media-card, media-modal, and featured-image-preview styles');
    } else {
        test_fail('admin.css media styles',
            "uploadZone={$hasUploadZone}, grid={$hasMediaGrid}, card={$hasMediaCard}, "
            . "modal={$hasModal}, featured={$hasFeaturedPreview}");
    }
} catch (\Throwable $e) {
    test_fail('admin.css check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: Routes registered for media controller
// ---------------------------------------------------------------------------
try {
    $indexContent = file_get_contents($rootDir . '/public/index.php');

    $hasMediaImport = stripos($indexContent, 'MediaController') !== false;
    $hasGetMedia = stripos($indexContent, "'/media'") !== false || stripos($indexContent, '"/media"') !== false;
    $hasBrowseRoute = stripos($indexContent, '/media/browse') !== false;
    $hasUploadRoute = stripos($indexContent, '/media/upload') !== false;
    $hasDeleteRoute = stripos($indexContent, '/media/{id}') !== false;

    // Should NOT have the old placeholder "Media management is coming"
    $hasOldPlaceholder = stripos($indexContent, 'Media management is coming') !== false;

    if ($hasMediaImport && $hasGetMedia && $hasBrowseRoute && $hasUploadRoute && $hasDeleteRoute && !$hasOldPlaceholder) {
        test_pass('index.php has MediaController import, all 4 media routes, and no old placeholder');
    } else {
        test_fail('Media routes',
            "import={$hasMediaImport}, get={$hasGetMedia}, browse={$hasBrowseRoute}, "
            . "upload={$hasUploadRoute}, delete={$hasDeleteRoute}, oldPlaceholder={$hasOldPlaceholder}");
    }
} catch (\Throwable $e) {
    test_fail('Routes check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: Response includes security headers
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $request = makeRequest('GET', '/admin/media');
    $response = $controller->index($request);
    $headers = $response->getHeaders();

    $xFrame = $headers['X-Frame-Options'] ?? '';
    $csp = $headers['Content-Security-Policy'] ?? '';

    $hasXFrame = ($xFrame === 'DENY');
    $hasCsp = str_contains($csp, "default-src 'self'") && str_contains($csp, 'cdn.jsdelivr.net');

    if ($hasXFrame && $hasCsp) {
        test_pass('Media index response has X-Frame-Options: DENY and CSP with jsdelivr CDN');
    } else {
        test_fail('Security headers', "X-Frame-Options={$xFrame}, CSP=" . substr($csp, 0, 80));
    }
} catch (\Throwable $e) {
    test_fail('Security headers check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: MediaController::index() paginates media items
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Insert 15 media records to exceed default items_per_page (10)
    $paginationIds = [];
    for ($i = 1; $i <= 15; $i++) {
        $paginationIds[] = \App\Database\QueryBuilder::query('media')->insert([
            'filename'      => "2026/02/pagtest{$i}aaaaaabbbbccccdddd1111.jpg",
            'original_name' => "pagination-test-{$i}.jpg",
            'mime_type'     => 'image/jpeg',
            'size_bytes'    => 1000 * $i,
            'uploaded_by'   => 1,
        ]);
    }

    // Page 1
    $request = makeRequest('GET', '/admin/media', [], ['page' => '1']);
    $response = $controller->index($request);
    $page1Html = $response->getBody();

    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Page 2
    $request = makeRequest('GET', '/admin/media', [], ['page' => '2']);
    $response = $controller->index($request);
    $page2Html = $response->getBody();

    $hasNextOnPage1 = str_contains($page1Html, 'Next') || str_contains($page1Html, 'page=2');
    $hasPrevOnPage2 = str_contains($page2Html, 'Prev') || str_contains($page2Html, 'page=1');

    if ($hasNextOnPage1 && $hasPrevOnPage2) {
        test_pass('Pagination: page 1 has Next link, page 2 has Prev link');
    } else {
        test_fail('Pagination links', "nextOnPage1={$hasNextOnPage1}, prevOnPage2={$hasPrevOnPage2}");
    }

    // Cleanup pagination items
    foreach ($paginationIds as $pid) {
        \App\Database\QueryBuilder::query('media')->where('id', (int)$pid)->delete();
    }
} catch (\Throwable $e) {
    test_fail('Pagination works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
cleanup();

exit($fail > 0 ? 1 : 0);
