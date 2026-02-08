<?php declare(strict_types=1);

/**
 * Chunk 5.1 — Custom Content Types
 * Automated Verification Tests
 *
 * Tests:
 *   1. ContentTypeController class is autoloadable
 *   2. Required files exist (templates, JS)
 *   3. ContentTypeController::index() renders content type list with 200 status
 *   [SMOKE STOP]
 *   4. ContentTypeController::store() creates a content type with valid fields JSON
 *   5. ContentTypeController validation rejects reserved slugs
 *   6. ContentTypeController validation rejects invalid field JSON
 *   7. ContentTypeController::edit() renders editor with existing data
 *   8. ContentTypeController::update() persists changes and cascades slug rename
 *   9. ContentTypeController::delete() blocks when content items exist
 *  10. ContentTypeController::delete() succeeds when no content references the type
 *  11. ContentController::store() saves custom fields to custom_fields table
 *  12. ContentController::edit() loads custom field definitions and values
 *  13. ContentController::update() re-saves custom fields (delete + re-insert)
 *  14. ContentController::index() passes contentTypes to template (dynamic type filter)
 *  15. Admin layout has "Content Types" nav link
 *  16. Content edit template renders custom fields section
 *  17. Content index template has dynamic type filter with custom types
 *  18. field-builder.js has all required functions
 *  19. Dynamic public routes registered in index.php
 *  20. ContentTypeController::store() validates field key uniqueness
 *  21. Select field type requires options array
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
$testDbPath = $rootDir . '/storage/test_chunk51.sqlite';

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
    echo "Chunk 5.1 results: {$pass} passed, {$fail} failed\n";
}

// ---------------------------------------------------------------------------
// Test 1: ContentTypeController class is autoloadable
// ---------------------------------------------------------------------------
$controllerClass = 'App\\Admin\\ContentTypeController';

if (!class_exists($controllerClass)) {
    test_fail('ContentTypeController is autoloadable', "class {$controllerClass} not found");
    cleanup();
    exit(1);
} else {
    test_pass('ContentTypeController is autoloadable');
}

// ---------------------------------------------------------------------------
// Test 2: Required files exist (templates, JS)
// ---------------------------------------------------------------------------
$requiredFiles = [
    'templates/admin/content-types/index.php' => 'Content types list template',
    'templates/admin/content-types/edit.php'   => 'Content types edit template',
    'public/assets/js/field-builder.js'        => 'Field builder JavaScript',
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
    test_pass('All required files exist: content-types/index.php, content-types/edit.php, field-builder.js');
}

// ---------------------------------------------------------------------------
// Test 3: ContentTypeController::index() renders content type list with 200
// ---------------------------------------------------------------------------
$app = null;
$ctController = null;

try {
    $app = new \App\Core\App();
    $app->register('db', $pdo);

    $ctController = new \App\Admin\ContentTypeController($app);
    $request = makeRequest('GET', '/admin/content-types');
    $response = $ctController->index($request);
    $indexHtml = $response->getBody();

    if ($response->getStatus() === 200 && strlen($indexHtml) > 100) {
        test_pass('ContentTypeController::index() returns 200 with rendered HTML');
    } else {
        test_fail('ContentTypeController::index() returns 200', "status={$response->getStatus()}, bodyLen=" . strlen($indexHtml));
    }
} catch (\Throwable $e) {
    test_fail('ContentTypeController::index() works without errors', $e->getMessage());
}

if ($ctController === null) {
    echo "\n[FAIL] Cannot continue — ContentTypeController not available\n";
    cleanup();
    exit(1);
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    cleanup();
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: store() creates a content type with valid fields JSON
// ---------------------------------------------------------------------------
$createdTypeId = null;
$fieldsJson = json_encode([
    ['key' => 'price', 'label' => 'Price', 'type' => 'text', 'required' => true],
    ['key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => false],
    ['key' => 'featured', 'label' => 'Featured Product', 'type' => 'boolean', 'required' => false],
]);

try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $request = makeRequest('POST', '/admin/content-types', [
        'name'        => 'Products',
        'slug'        => 'products',
        'has_archive' => '1',
        'fields_json' => $fieldsJson,
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $ctController->store($request);
    $status = $response->getStatus();
    $headers = $response->getHeaders();
    $location = $headers['Location'] ?? '';

    if ($status === 302 && str_contains($location, '/admin/content-types/')) {
        // Extract the created ID from the redirect URL
        if (preg_match('#/admin/content-types/(\d+)/edit#', $location, $m)) {
            $createdTypeId = $m[1];
        }
        test_pass("store() creates content type and redirects to edit page (→ {$location})");
    } else {
        test_fail('store() creates content type', "status={$status}, location={$location}");
    }

    // Verify the row exists in DB
    $row = \App\Database\QueryBuilder::query('content_types')
        ->select()
        ->where('slug', 'products')
        ->first();
    if ($row !== null && $row['name'] === 'Products' && (int)$row['has_archive'] === 1) {
        // Verify fields_json was stored correctly
        $storedFields = json_decode($row['fields_json'], true);
        if (is_array($storedFields) && count($storedFields) === 3) {
            test_pass('Content type persisted: name=Products, slug=products, 3 fields in fields_json');
        } else {
            test_fail('Content type fields_json', 'expected 3 fields, got: ' . var_export($storedFields, true));
        }
    } else {
        test_fail('Content type persistence', 'row not found or data mismatch');
    }
} catch (\Throwable $e) {
    test_fail('store() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: Validation rejects reserved slugs
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $reservedSlugs = ['page', 'post', 'blog', 'admin'];
    $allRejected = true;

    foreach ($reservedSlugs as $reserved) {
        $countBefore = \App\Database\QueryBuilder::query('content_types')->select()->count();

        $request = makeRequest('POST', '/admin/content-types', [
            'name'        => 'Test ' . ucfirst($reserved),
            'slug'        => $reserved,
            'has_archive' => '1',
            'fields_json' => '[]',
            '_csrf_token' => $_SESSION['csrf_token'],
        ]);

        $response = $ctController->store($request);
        $countAfter = \App\Database\QueryBuilder::query('content_types')->select()->count();

        if ($countAfter !== $countBefore) {
            $allRejected = false;
            test_fail("Reserved slug '{$reserved}' rejected", 'row was inserted');
            // Clean up the wrongly inserted row
            \App\Database\QueryBuilder::query('content_types')
                ->where('slug', $reserved)
                ->delete();
        }

        // Clear flash for next iteration
        \App\Auth\Session::flash('success');
        \App\Auth\Session::flash('error');
    }

    if ($allRejected) {
        test_pass('Validation rejects reserved slugs: page, post, blog, admin');
    }
} catch (\Throwable $e) {
    test_fail('Reserved slug validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: Validation rejects invalid field JSON
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $countBefore = \App\Database\QueryBuilder::query('content_types')->select()->count();

    // Invalid JSON: field missing 'key'
    $badFields = json_encode([
        ['label' => 'No Key', 'type' => 'text'],
    ]);

    $request = makeRequest('POST', '/admin/content-types', [
        'name'        => 'Bad Fields Type',
        'slug'        => 'bad-fields',
        'has_archive' => '1',
        'fields_json' => $badFields,
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $ctController->store($request);
    $countAfter = \App\Database\QueryBuilder::query('content_types')->select()->count();

    if ($countAfter === $countBefore) {
        test_pass('Validation rejects field JSON missing required "key" property');
    } else {
        test_fail('Invalid field JSON validation', 'row was inserted despite missing key');
        \App\Database\QueryBuilder::query('content_types')->where('slug', 'bad-fields')->delete();
    }
} catch (\Throwable $e) {
    test_fail('Field JSON validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: edit() renders editor with existing data
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    if ($createdTypeId !== null) {
        $request = makeRequest('GET', "/admin/content-types/{$createdTypeId}/edit");
        $response = $ctController->edit($request, $createdTypeId);
        $editHtml = $response->getBody();

        $hasName = str_contains($editHtml, 'Products');
        $hasSlug = str_contains($editHtml, 'products');
        $hasForm = str_contains($editHtml, '<form');
        $hasFieldsJson = str_contains($editHtml, 'fields_json') || str_contains($editHtml, 'fields-json');

        if ($response->getStatus() === 200 && $hasName && $hasSlug && $hasForm) {
            test_pass('edit() renders editor with name, slug, and form');
        } else {
            test_fail('edit() content', "status={$response->getStatus()}, name={$hasName}, slug={$hasSlug}, form={$hasForm}");
        }
    } else {
        test_skip('edit() rendering — no content type ID from store test');
    }
} catch (\Throwable $e) {
    test_fail('edit() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: update() persists changes and cascades slug rename
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    if ($createdTypeId !== null) {
        // First, create a content item of type "products" so we can test slug cascade
        $contentId = \App\Database\QueryBuilder::query('content')->insert([
            'type'      => 'products',
            'title'     => 'Widget Pro',
            'slug'      => 'widget-pro',
            'body'      => '<p>A great widget</p>',
            'status'    => 'published',
            'author_id' => 1,
        ]);

        // Update the content type — change slug from "products" to "items"
        $request = makeRequest('POST', "/admin/content-types/{$createdTypeId}", [
            '_method'     => 'PUT',
            'name'        => 'Items',
            'slug'        => 'items',
            'has_archive' => '1',
            'fields_json' => $fieldsJson,
            '_csrf_token' => $_SESSION['csrf_token'],
        ]);

        $response = $ctController->update($request, $createdTypeId);

        // Verify the content type was updated
        $updatedType = \App\Database\QueryBuilder::query('content_types')
            ->select()
            ->where('id', (int)$createdTypeId)
            ->first();

        // Verify the content item's type was cascaded
        $updatedContent = \App\Database\QueryBuilder::query('content')
            ->select()
            ->where('id', (int)$contentId)
            ->first();

        if ($updatedType !== null && $updatedType['slug'] === 'items' && $updatedType['name'] === 'Items') {
            test_pass('update() persists name and slug changes');
        } else {
            test_fail('update() persistence', 'type not updated correctly');
        }

        if ($updatedContent !== null && $updatedContent['type'] === 'items') {
            test_pass('update() cascades slug change to content items (products → items)');
        } else {
            $contentType = $updatedContent['type'] ?? '(null)';
            test_fail('Slug cascade', "expected content type='items', got '{$contentType}'");
        }

        // Revert slug back to "products" for subsequent tests
        $request = makeRequest('POST', "/admin/content-types/{$createdTypeId}", [
            '_method'     => 'PUT',
            'name'        => 'Products',
            'slug'        => 'products',
            'has_archive' => '1',
            'fields_json' => $fieldsJson,
            '_csrf_token' => $_SESSION['csrf_token'],
        ]);
        \App\Auth\Session::flash('success');
        \App\Auth\Session::flash('error');
        $ctController->update($request, $createdTypeId);
    } else {
        test_skip('update() — no content type ID from store test');
        test_skip('Slug cascade — no content type ID from store test');
    }
} catch (\Throwable $e) {
    test_fail('update() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: delete() blocks when content items exist
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    if ($createdTypeId !== null) {
        $request = makeRequest('POST', "/admin/content-types/{$createdTypeId}", [
            '_method'     => 'DELETE',
            '_csrf_token' => $_SESSION['csrf_token'],
        ]);

        $response = $ctController->delete($request, $createdTypeId);

        // Type should still exist
        $stillExists = \App\Database\QueryBuilder::query('content_types')
            ->select()
            ->where('id', (int)$createdTypeId)
            ->first();

        if ($stillExists !== null) {
            test_pass('delete() blocks deletion when content items reference the type');
        } else {
            test_fail('Delete protection', 'type was deleted despite having content items');
        }
    } else {
        test_skip('delete() protection — no content type ID from store test');
    }
} catch (\Throwable $e) {
    test_fail('delete() protection works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: delete() succeeds when no content references the type
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    // Create a temporary type with no content
    $tempTypeId = \App\Database\QueryBuilder::query('content_types')->insert([
        'slug'        => 'temp-type',
        'name'        => 'Temporary Type',
        'fields_json' => '[]',
        'has_archive' => 1,
    ]);

    $request = makeRequest('POST', "/admin/content-types/{$tempTypeId}", [
        '_method'     => 'DELETE',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $ctController->delete($request, (string) $tempTypeId);

    $gone = \App\Database\QueryBuilder::query('content_types')
        ->select()
        ->where('id', (int)$tempTypeId)
        ->first();

    if ($gone === null) {
        test_pass('delete() removes content type when no content references it');
    } else {
        test_fail('delete() unused type', 'type still exists after deletion');
    }
} catch (\Throwable $e) {
    test_fail('delete() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: ContentController::store() saves custom fields to custom_fields table
// ---------------------------------------------------------------------------
$contentController = null;
$customContentId = null;

try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $contentController = new \App\Admin\ContentController($app);

    $request = makeRequest('POST', '/admin/content', [
        'title'       => 'Widget Pro',
        'slug'        => 'widget-pro-2',
        'body'        => '<p>A great widget</p>',
        'excerpt'     => 'Widget excerpt',
        'type'        => 'products',
        'status'      => 'published',
        'meta_title'  => '',
        'meta_description' => '',
        'featured_image'   => '',
        'published_at'     => '',
        'sort_order'       => '0',
        'custom_fields'    => [
            'price'       => '29.99',
            'description' => 'A great widget',
            'featured'    => '1',
        ],
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $contentController->store($request);
    $headers = $response->getHeaders();
    $location = $headers['Location'] ?? '';

    // Extract the created content ID
    if (preg_match('#/admin/content/(\d+)/edit#', $location, $m)) {
        $customContentId = $m[1];
    }

    if ($customContentId !== null) {
        // Check custom_fields table
        $cfRows = \App\Database\QueryBuilder::query('custom_fields')
            ->select()
            ->where('content_id', (int)$customContentId)
            ->get();

        $cfMap = [];
        foreach ($cfRows as $row) {
            $cfMap[$row['field_key']] = $row['field_value'];
        }

        $hasPrice = ($cfMap['price'] ?? '') === '29.99';
        $hasDesc = ($cfMap['description'] ?? '') === 'A great widget';
        $hasFeatured = ($cfMap['featured'] ?? '') === '1';

        if ($hasPrice && $hasDesc && $hasFeatured) {
            test_pass('ContentController::store() saves custom fields (price=29.99, description, featured=1)');
        } else {
            test_fail('Custom fields persistence', "price={$hasPrice}, desc={$hasDesc}, featured={$hasFeatured}, rows=" . count($cfRows));
        }
    } else {
        test_fail('Custom content creation', "could not extract content ID from redirect: {$location}");
    }
} catch (\Throwable $e) {
    test_fail('Custom fields store works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: ContentController::edit() loads custom field definitions and values
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    if ($customContentId !== null && $contentController !== null) {
        $request = makeRequest('GET', "/admin/content/{$customContentId}/edit");
        $response = $contentController->edit($request, $customContentId);
        $editHtml = $response->getBody();

        // Should show custom field labels and values
        $hasPrice = str_contains($editHtml, 'Price');
        $hasPriceValue = str_contains($editHtml, '29.99');
        $hasDescription = str_contains($editHtml, 'Description');
        $hasFeatured = str_contains($editHtml, 'Featured');

        if ($hasPrice && $hasPriceValue) {
            test_pass('edit() renders custom fields with labels and saved values (Price=29.99)');
        } else {
            test_fail('Custom fields in edit', "price_label={$hasPrice}, price_value={$hasPriceValue}, desc={$hasDescription}");
        }
    } else {
        test_skip('edit() custom fields — no custom content ID available');
    }
} catch (\Throwable $e) {
    test_fail('edit() custom fields works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: ContentController::update() re-saves custom fields
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    if ($customContentId !== null && $contentController !== null) {
        $request = makeRequest('POST', "/admin/content/{$customContentId}", [
            '_method'     => 'PUT',
            'title'       => 'Widget Pro',
            'slug'        => 'widget-pro-2',
            'body'        => '<p>A great widget</p>',
            'excerpt'     => 'Widget excerpt',
            'type'        => 'products',
            'status'      => 'published',
            'meta_title'  => '',
            'meta_description' => '',
            'featured_image'   => '',
            'published_at'     => '',
            'sort_order'       => '0',
            'custom_fields'    => [
                'price'       => '39.99',
                'description' => 'Updated widget desc',
                // 'featured' intentionally omitted (unchecked checkbox)
            ],
            '_csrf_token' => $_SESSION['csrf_token'],
        ]);

        $response = $contentController->update($request, $customContentId);

        // Check updated custom_fields
        $cfRows = \App\Database\QueryBuilder::query('custom_fields')
            ->select()
            ->where('content_id', (int)$customContentId)
            ->get();

        $cfMap = [];
        foreach ($cfRows as $row) {
            $cfMap[$row['field_key']] = $row['field_value'];
        }

        $priceUpdated = ($cfMap['price'] ?? '') === '39.99';
        $descUpdated = ($cfMap['description'] ?? '') === 'Updated widget desc';
        $featuredGone = !isset($cfMap['featured']);

        if ($priceUpdated && $descUpdated && $featuredGone) {
            test_pass('update() re-saves custom fields: price=39.99, featured removed (unchecked)');
        } else {
            test_fail('Custom fields update', "price={$priceUpdated}, desc={$descUpdated}, featuredGone={$featuredGone}");
        }
    } else {
        test_skip('update() custom fields — no custom content ID available');
    }
} catch (\Throwable $e) {
    test_fail('update() custom fields works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: ContentController::index() passes contentTypes to template
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    if ($contentController !== null) {
        $request = makeRequest('GET', '/admin/content');
        $response = $contentController->index($request);
        $html = $response->getBody();

        // The template should show "Products" as a type filter option
        if (str_contains($html, 'Products')) {
            test_pass('ContentController::index() passes custom types to template (Products visible in filter)');
        } else {
            test_fail('Custom type in content list', '"Products" not found in content index HTML');
        }
    } else {
        test_skip('Content index custom types — ContentController not available');
    }
} catch (\Throwable $e) {
    test_fail('Content index custom types works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: Admin layout has "Content Types" nav link
// ---------------------------------------------------------------------------
$layoutPath = $rootDir . '/templates/admin/layout.php';
if (!file_exists($layoutPath)) {
    test_fail('Admin layout exists', 'templates/admin/layout.php not found');
} else {
    $layoutContent = file_get_contents($layoutPath);

    if (str_contains($layoutContent, '/admin/content-types') && str_contains($layoutContent, 'Content Types')) {
        test_pass('Admin layout has "Content Types" nav link pointing to /admin/content-types');
    } else {
        test_fail('Content Types nav link', 'link or text not found in admin layout');
    }
}

// ---------------------------------------------------------------------------
// Test 16: Content edit template renders custom fields section
// ---------------------------------------------------------------------------
$editTemplatePath = $rootDir . '/templates/admin/content/edit.php';
if (!file_exists($editTemplatePath)) {
    test_fail('Content edit template exists');
} else {
    $editContent = file_get_contents($editTemplatePath);

    $hasCustomFieldsSection = str_contains($editContent, 'custom_fields') || str_contains($editContent, 'customField');
    $hasFieldDefLoop = str_contains($editContent, 'customFieldDefinitions') || str_contains($editContent, 'fieldDef');

    if ($hasCustomFieldsSection && $hasFieldDefLoop) {
        test_pass('Content edit template has custom fields section with field definition loop');
    } else {
        test_fail('Custom fields in edit template', "customFields={$hasCustomFieldsSection}, fieldDefLoop={$hasFieldDefLoop}");
    }

    // Check for dynamic type dropdown with custom types
    $hasContentTypes = str_contains($editContent, 'contentTypes') || str_contains($editContent, 'content_types');
    if ($hasContentTypes) {
        test_pass('Content edit template has dynamic type dropdown with custom content types');
    } else {
        test_fail('Dynamic type dropdown in edit', 'contentTypes variable not found');
    }
}

// ---------------------------------------------------------------------------
// Test 17: Content index template has dynamic type filter
// ---------------------------------------------------------------------------
$indexTemplatePath = $rootDir . '/templates/admin/content/index.php';
if (!file_exists($indexTemplatePath)) {
    test_fail('Content index template exists');
} else {
    $indexContent = file_get_contents($indexTemplatePath);

    $hasContentTypes = str_contains($indexContent, 'contentTypes') || str_contains($indexContent, 'content_types');
    $hasForeach = str_contains($indexContent, 'foreach');

    if ($hasContentTypes && $hasForeach) {
        test_pass('Content index template dynamically renders custom types in filter dropdown');
    } else {
        test_fail('Dynamic type filter in index', "contentTypes={$hasContentTypes}, foreach={$hasForeach}");
    }
}

// ---------------------------------------------------------------------------
// Test 18: field-builder.js has all required functions
// ---------------------------------------------------------------------------
$jsPath = $rootDir . '/public/assets/js/field-builder.js';
if (!file_exists($jsPath)) {
    test_fail('field-builder.js exists', 'already reported in test 2');
} else {
    $jsContent = file_get_contents($jsPath);

    $requiredFunctions = [
        'initFieldBuilder'  => 'entry point / initialization',
        'serializeFields'   => 'serialize fields to JSON',
    ];

    $requiredPatterns = [
        'field-key'        => 'field key input',
        'field-label'      => 'field label input',
        'field-type'       => 'field type dropdown',
        'field-required'   => 'field required checkbox',
        'field-options'    => 'field options for select type',
    ];

    $allFound = true;
    $missing = [];

    foreach ($requiredFunctions as $fn => $purpose) {
        if (!preg_match('/(?:function\s+' . preg_quote($fn, '/') . '|' . preg_quote($fn, '/') . '\s*=\s*function)/', $jsContent)) {
            $allFound = false;
            $missing[] = "function {$fn}";
        }
    }

    foreach ($requiredPatterns as $pattern => $purpose) {
        if (!str_contains($jsContent, $pattern)) {
            $allFound = false;
            $missing[] = "{$pattern} ({$purpose})";
        }
    }

    if ($allFound) {
        test_pass('field-builder.js has required functions and UI elements (initFieldBuilder, serializeFields, field inputs)');
    } else {
        test_fail('field-builder.js contents', 'missing: ' . implode(', ', $missing));
    }

    // Check for add/remove/move functionality
    $hasAdd = str_contains($jsContent, 'add-field-btn') || str_contains($jsContent, 'addField');
    $hasRemove = str_contains($jsContent, 'remove') || str_contains($jsContent, 'Remove');
    $hasMove = str_contains($jsContent, 'move-up') || str_contains($jsContent, 'moveField') || str_contains($jsContent, 'Move');

    if ($hasAdd && $hasRemove && $hasMove) {
        test_pass('field-builder.js has add, remove, and move field functionality');
    } else {
        test_fail('field-builder.js CRUD ops', "add={$hasAdd}, remove={$hasRemove}, move={$hasMove}");
    }

    // Check for form submit serialization
    if (str_contains($jsContent, 'submit') && str_contains($jsContent, 'JSON.stringify')) {
        test_pass('field-builder.js serializes fields to JSON on form submit');
    } else {
        test_fail('field-builder.js serialization', 'submit handler or JSON.stringify not found');
    }
}

// ---------------------------------------------------------------------------
// Test 19: Dynamic public routes registered in index.php
// ---------------------------------------------------------------------------
$indexPhpPath = $rootDir . '/public/index.php';
if (!file_exists($indexPhpPath)) {
    test_fail('public/index.php exists');
} else {
    $indexPhpContent = file_get_contents($indexPhpPath);

    // Check for ContentTypeController use statement
    $hasUse = str_contains($indexPhpContent, 'ContentTypeController');

    // Check for content type admin routes
    $hasAdminRoutes = str_contains($indexPhpContent, '/content-types')
        && str_contains($indexPhpContent, 'ContentTypeController');

    // Check for dynamic public archive routes
    $hasDynamicRoutes = str_contains($indexPhpContent, 'content_types')
        && str_contains($indexPhpContent, 'has_archive');

    if ($hasUse && $hasAdminRoutes) {
        test_pass('index.php registers ContentTypeController admin routes');
    } else {
        test_fail('Admin content type routes', "use={$hasUse}, routes={$hasAdminRoutes}");
    }

    if ($hasDynamicRoutes) {
        test_pass('index.php dynamically registers public archive routes from content_types table');
    } else {
        test_fail('Dynamic public routes', 'content_types query with has_archive not found');
    }
}

// ---------------------------------------------------------------------------
// Test 20: Validation rejects duplicate field keys
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $countBefore = \App\Database\QueryBuilder::query('content_types')->select()->count();

    $duplicateKeyFields = json_encode([
        ['key' => 'price', 'label' => 'Price', 'type' => 'text'],
        ['key' => 'price', 'label' => 'Price Again', 'type' => 'text'],
    ]);

    $request = makeRequest('POST', '/admin/content-types', [
        'name'        => 'Duplicate Key Type',
        'slug'        => 'dup-key',
        'has_archive' => '1',
        'fields_json' => $duplicateKeyFields,
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $ctController->store($request);
    $countAfter = \App\Database\QueryBuilder::query('content_types')->select()->count();

    if ($countAfter === $countBefore) {
        test_pass('Validation rejects duplicate field keys within a content type');
    } else {
        test_fail('Duplicate field key validation', 'row was inserted despite duplicate keys');
        \App\Database\QueryBuilder::query('content_types')->where('slug', 'dup-key')->delete();
    }
} catch (\Throwable $e) {
    test_fail('Duplicate field key validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 21: Select field type requires options array
// ---------------------------------------------------------------------------
try {
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');

    $countBefore = \App\Database\QueryBuilder::query('content_types')->select()->count();

    $selectNoOptions = json_encode([
        ['key' => 'category', 'label' => 'Category', 'type' => 'select'],
    ]);

    $request = makeRequest('POST', '/admin/content-types', [
        'name'        => 'Select No Options',
        'slug'        => 'select-no-opts',
        'has_archive' => '1',
        'fields_json' => $selectNoOptions,
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $ctController->store($request);
    $countAfter = \App\Database\QueryBuilder::query('content_types')->select()->count();

    if ($countAfter === $countBefore) {
        test_pass('Validation rejects select field type without options array');
    } else {
        test_fail('Select options validation', 'row was inserted despite missing options');
        \App\Database\QueryBuilder::query('content_types')->where('slug', 'select-no-opts')->delete();
    }
} catch (\Throwable $e) {
    test_fail('Select options validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
cleanup();

exit($fail > 0 ? 1 : 0);
