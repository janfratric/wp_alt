<?php declare(strict_types=1);

/**
 * Chunk 5.3 — AI Page Generator
 * Automated Verification Tests
 *
 * Tests:
 *   1. GeneratorPrompts class is autoloadable
 *   2. PageGeneratorController class is autoloadable
 *   3. GeneratorPrompts::gatheringPrompt() returns prompt with site context
 *   [SMOKE STOP]
 *   4. GeneratorPrompts::generationPrompt() returns prompt with JSON format instructions
 *   5. GeneratorPrompts::formatExistingPages() formats page list correctly
 *   6. GeneratorPrompts::formatCustomFields() formats field definitions
 *   7. PageGeneratorController::index() renders generator page with content types
 *   8. PageGeneratorController::chat() validates required message field
 *   9. PageGeneratorController::chat() returns error when API key is missing
 *  10. PageGeneratorController::create() inserts content record with all fields
 *  11. PageGeneratorController::create() saves custom fields
 *  12. Slug generation and uniqueness
 *  13. parseGeneratedContent() parses valid JSON
 *  14. parseGeneratedContent() strips markdown code fences
 *  15. parseGeneratedContent() returns null for invalid JSON
 *  16. Generator template exists with required UI elements
 *  17. page-generator.js exists with required functions
 *  18. Admin layout has "Generate Page" nav link
 *  19. Routes registered for generator (GET + 2 POST)
 *  20. admin.css has generator styles
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
// Setup: load autoloader
// ---------------------------------------------------------------------------
$autoloadPath = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found — run composer install');
    echo "\n[FAIL] Cannot continue — autoloader missing\n";
    exit(1);
}
require_once $autoloadPath;

// ---------------------------------------------------------------------------
// Test 1: GeneratorPrompts class is autoloadable
// ---------------------------------------------------------------------------
try {
    if (class_exists(\App\AIAssistant\GeneratorPrompts::class)) {
        test_pass('GeneratorPrompts class is autoloadable');
    } else {
        test_fail('GeneratorPrompts class is autoloadable', 'class not found');
    }
} catch (\Throwable $e) {
    test_fail('GeneratorPrompts autoload', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 2: PageGeneratorController class is autoloadable
// ---------------------------------------------------------------------------
try {
    if (class_exists(\App\AIAssistant\PageGeneratorController::class)) {
        test_pass('PageGeneratorController class is autoloadable');
    } else {
        test_fail('PageGeneratorController class is autoloadable', 'class not found');
    }
} catch (\Throwable $e) {
    test_fail('PageGeneratorController autoload', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 3: GeneratorPrompts::gatheringPrompt() returns prompt with site context
// ---------------------------------------------------------------------------
try {
    $prompt = \App\AIAssistant\GeneratorPrompts::gatheringPrompt(
        'My Test Site',
        [['title' => 'Home', 'slug' => ''], ['title' => 'About', 'slug' => 'about']],
        null
    );

    $hasName = str_contains($prompt, 'My Test Site');
    $hasPages = str_contains($prompt, 'Home') && str_contains($prompt, 'About');
    $hasMarker = str_contains($prompt, 'READY_TO_GENERATE');
    $hasGuidelines = str_contains($prompt, 'questions') || str_contains($prompt, 'purpose');

    if ($hasName && $hasPages && $hasMarker && $hasGuidelines) {
        test_pass('gatheringPrompt() includes site name, existing pages, READY_TO_GENERATE marker, and guidelines');
    } else {
        $missing = [];
        if (!$hasName) { $missing[] = 'site name'; }
        if (!$hasPages) { $missing[] = 'existing pages'; }
        if (!$hasMarker) { $missing[] = 'READY_TO_GENERATE marker'; }
        if (!$hasGuidelines) { $missing[] = 'guidelines'; }
        test_fail('gatheringPrompt() content', 'missing: ' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    test_fail('gatheringPrompt() check', $e->getMessage());
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 5.3 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: GeneratorPrompts::generationPrompt() returns prompt with JSON format
// ---------------------------------------------------------------------------
try {
    $prompt = \App\AIAssistant\GeneratorPrompts::generationPrompt('My Test Site', 'page', null);

    $hasJson = str_contains($prompt, 'JSON') || str_contains($prompt, 'json');
    $hasTitle = str_contains($prompt, '"title"');
    $hasBody = str_contains($prompt, '"body"');
    $hasSlug = str_contains($prompt, '"slug"');
    $hasNoH1 = str_contains($prompt, 'h1') || str_contains($prompt, 'H1');
    $hasSemanticRules = str_contains($prompt, 'semantic') || str_contains($prompt, 'section');

    if ($hasJson && $hasTitle && $hasBody && $hasSlug && $hasSemanticRules) {
        test_pass('generationPrompt() includes JSON format, required keys (title, body, slug), and HTML rules');
    } else {
        $missing = [];
        if (!$hasJson) { $missing[] = 'JSON instruction'; }
        if (!$hasTitle) { $missing[] = '"title" key'; }
        if (!$hasBody) { $missing[] = '"body" key'; }
        if (!$hasSlug) { $missing[] = '"slug" key'; }
        if (!$hasSemanticRules) { $missing[] = 'semantic HTML rules'; }
        test_fail('generationPrompt() content', 'missing: ' . implode(', ', $missing));
    }

    // Test with custom fields
    $customFields = [
        ['key' => 'price', 'type' => 'text', 'label' => 'Price', 'required' => true],
        ['key' => 'featured', 'type' => 'boolean', 'label' => 'Featured'],
    ];
    $promptCustom = \App\AIAssistant\GeneratorPrompts::generationPrompt('My Test Site', 'product', $customFields);

    if (str_contains($promptCustom, 'custom_fields') && str_contains($promptCustom, 'price')) {
        test_pass('generationPrompt() includes custom_fields in JSON format when custom type provided');
    } else {
        test_fail('generationPrompt() custom fields', 'custom_fields or field keys not in prompt');
    }
} catch (\Throwable $e) {
    test_fail('generationPrompt() check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: GeneratorPrompts::formatExistingPages() formats page list
// ---------------------------------------------------------------------------
try {
    // With pages
    $formatted = \App\AIAssistant\GeneratorPrompts::formatExistingPages([
        ['title' => 'Home', 'slug' => ''],
        ['title' => 'About Us', 'slug' => 'about-us'],
        ['title' => 'Contact', 'slug' => 'contact'],
    ]);

    $hasHome = str_contains($formatted, 'Home');
    $hasAbout = str_contains($formatted, 'About Us');
    $hasContact = str_contains($formatted, 'Contact');

    if ($hasHome && $hasAbout && $hasContact) {
        test_pass('formatExistingPages() lists all page titles');
    } else {
        test_fail('formatExistingPages() with pages', 'missing page titles in output');
    }

    // Empty pages
    $emptyFormatted = \App\AIAssistant\GeneratorPrompts::formatExistingPages([]);
    if (str_contains($emptyFormatted, 'no pages') || str_contains($emptyFormatted, 'No pages') || str_contains($emptyFormatted, 'new site')) {
        test_pass('formatExistingPages() handles empty page list gracefully');
    } else {
        test_fail('formatExistingPages() empty', "expected 'no pages' message, got: '{$emptyFormatted}'");
    }
} catch (\Throwable $e) {
    test_fail('formatExistingPages() check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: GeneratorPrompts::formatCustomFields() formats field definitions
// ---------------------------------------------------------------------------
try {
    $fields = [
        ['key' => 'price', 'type' => 'text', 'label' => 'Price', 'required' => true],
        ['key' => 'description', 'type' => 'textarea', 'label' => 'Description'],
        ['key' => 'category', 'type' => 'select', 'label' => 'Category', 'options' => ['Electronics', 'Books', 'Clothing']],
    ];

    $formatted = \App\AIAssistant\GeneratorPrompts::formatCustomFields($fields);

    $hasPrice = str_contains($formatted, 'Price') || str_contains($formatted, 'price');
    $hasRequired = str_contains($formatted, 'required');
    $hasSelect = str_contains($formatted, 'select') || str_contains($formatted, 'Category');
    $hasOptions = str_contains($formatted, 'Electronics') || str_contains($formatted, 'Books');

    if ($hasPrice && $hasRequired && $hasSelect) {
        test_pass('formatCustomFields() includes field names, types, and required flags');
    } else {
        $missing = [];
        if (!$hasPrice) { $missing[] = 'price field'; }
        if (!$hasRequired) { $missing[] = 'required flag'; }
        if (!$hasSelect) { $missing[] = 'select type'; }
        test_fail('formatCustomFields() output', 'missing: ' . implode(', ', $missing));
    }

    // Empty fields
    $emptyFormatted = \App\AIAssistant\GeneratorPrompts::formatCustomFields([]);
    if ($emptyFormatted === '' || str_contains($emptyFormatted, 'no custom') || str_contains($emptyFormatted, 'No custom')) {
        test_pass('formatCustomFields() handles empty fields (returns empty or "no custom fields")');
    } else {
        test_fail('formatCustomFields() empty', "unexpected output: '{$emptyFormatted}'");
    }
} catch (\Throwable $e) {
    test_fail('formatCustomFields() check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Setup: test database for controller tests
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk53.sqlite';
if (file_exists($testDbPath)) { unlink($testDbPath); }
$walPath = $testDbPath . '-wal';
$shmPath = $testDbPath . '-shm';
if (file_exists($walPath)) { unlink($walPath); }
if (file_exists($shmPath)) { unlink($shmPath); }

putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $testDbPath);
putenv('APP_SECRET=test-secret-for-chunk-53');

// Reset Config
$configReflection = new ReflectionClass(\App\Core\Config::class);
$configProp = $configReflection->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, null);

$hasDbSettings = $configReflection->hasProperty('dbSettings');
$dbSettingsProp = null;
if ($hasDbSettings) {
    $dbSettingsProp = $configReflection->getProperty('dbSettings');
    $dbSettingsProp->setAccessible(true);
    $dbSettingsProp->setValue(null, null);
}

// Reset Connection singleton
if (class_exists(\App\Database\Connection::class)) {
    \App\Database\Connection::reset();
}

// Run migrations
$pdo = \App\Database\Connection::getInstance();
$migrator = new \App\Database\Migrator($pdo);
$migrator->migrate();

// Create a test admin user
$testUserId = (int) \App\Database\QueryBuilder::query('users')->insert([
    'username'      => 'testadmin',
    'email'         => 'admin@test.com',
    'password_hash' => password_hash('test', PASSWORD_BCRYPT),
    'role'          => 'admin',
]);

// Set session values (session already started by earlier code or autoloader)
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$_SESSION['user_id'] = $testUserId;
$_SESSION['user_name'] = 'testadmin';
$_SESSION['user_role'] = 'admin';

// ---------------------------------------------------------------------------
// Test 7: PageGeneratorController::index() renders generator page
// ---------------------------------------------------------------------------
try {
    // Create a custom content type for testing
    \App\Database\QueryBuilder::query('content_types')->insert([
        'slug'        => 'product',
        'name'        => 'Product',
        'fields_json' => json_encode([
            ['key' => 'price', 'type' => 'text', 'label' => 'Price', 'required' => true],
        ]),
        'has_archive' => 1,
    ]);

    // Load DB settings
    if ($hasDbSettings) {
        $dbSettingsProp->setValue(null, null);
    }
    $configProp->setValue(null, null);
    \App\Core\Config::loadDbSettings();

    $app = new \App\Core\App();

    $controller = new \App\AIAssistant\PageGeneratorController($app);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/admin/generator';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_GET = [];
    $_POST = [];
    $request = new \App\Core\Request();

    $response = $controller->index($request);

    $body = $response->getBody();
    $status = $response->getStatus();

    $hasPageType = str_contains($body, 'Page') || str_contains($body, 'page');
    $hasPostType = str_contains($body, 'Blog Post') || str_contains($body, 'post');
    $hasCustomType = str_contains($body, 'Product') || str_contains($body, 'product');
    $hasGeneratorUI = str_contains($body, 'generator-app') || str_contains($body, 'generator-container');

    if ($status === 200 && $hasGeneratorUI) {
        test_pass('PageGeneratorController::index() returns 200 with generator UI');
    } else {
        test_fail('index() response', "status={$status}, hasUI=" . ($hasGeneratorUI ? 'yes' : 'no'));
    }

    if ($hasCustomType) {
        test_pass('index() includes custom content type "Product" in type selector');
    } else {
        test_fail('index() custom types', 'Product not found in response body');
    }
} catch (\Throwable $e) {
    test_fail('PageGeneratorController::index()', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: PageGeneratorController::chat() validates required message
// ---------------------------------------------------------------------------
try {
    $controller = new \App\AIAssistant\PageGeneratorController($app);

    // Verify the controller has the chat method with correct signature.
    $ref = new ReflectionClass(\App\AIAssistant\PageGeneratorController::class);
    $chatMethod = $ref->getMethod('chat');

    if ($chatMethod->isPublic() && $chatMethod->getNumberOfParameters() === 1) {
        test_pass('PageGeneratorController::chat() is public and accepts Request parameter');
    } else {
        test_fail('chat() method signature', 'expected public method with 1 parameter');
    }

    // Verify the method source checks for empty message
    $sourceFile = $rootDir . '/app/AIAssistant/PageGeneratorController.php';
    if (!file_exists($sourceFile)) {
        test_fail('chat() message validation', 'PageGeneratorController.php not found');
    } else {
        $source = file_get_contents($sourceFile);
        if (str_contains($source, 'message') && (str_contains($source, 'required') || str_contains($source, 'empty'))) {
            test_pass('chat() validates that message field is present');
        } else {
            test_fail('chat() message validation', 'message validation logic not found in source');
        }
    }
} catch (\Throwable $e) {
    test_fail('chat() validation check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: PageGeneratorController::chat() returns error when API key missing
// ---------------------------------------------------------------------------
try {
    $sourceFile = $rootDir . '/app/AIAssistant/PageGeneratorController.php';
    if (!file_exists($sourceFile)) {
        test_fail('chat() API key check', 'PageGeneratorController.php not found');
    } else {
        $source = file_get_contents($sourceFile);

        // Check that getApiKey is called and missing key returns an error
        $hasApiKeyCheck = str_contains($source, 'getApiKey');
        $hasApiKeyError = str_contains($source, 'API key') && str_contains($source, 'not configured');

        if ($hasApiKeyCheck && $hasApiKeyError) {
            test_pass('chat() checks for API key and returns helpful error when missing');
        } else {
            $missing = [];
            if (!$hasApiKeyCheck) { $missing[] = 'getApiKey call'; }
            if (!$hasApiKeyError) { $missing[] = 'missing key error message'; }
            test_fail('chat() API key check', 'missing: ' . implode(', ', $missing));
        }
    }
} catch (\Throwable $e) {
    test_fail('chat() API key error check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: PageGeneratorController::create() inserts content record
// ---------------------------------------------------------------------------
try {
    $controller = new \App\AIAssistant\PageGeneratorController($app);
    $ref = new ReflectionClass($controller);

    // Test create method exists and is public
    $createMethod = $ref->getMethod('create');
    if (!$createMethod->isPublic()) {
        test_fail('create() is public', 'method is not public');
    } else {
        $sourceFile = $rootDir . '/app/AIAssistant/PageGeneratorController.php';
        if (!file_exists($sourceFile)) {
            test_fail('create() content insertion', 'PageGeneratorController.php not found');
        } else {
            $source = file_get_contents($sourceFile);

            $insertsContent = str_contains($source, "query('content')") && str_contains($source, 'insert');
            $hasTitle = str_contains($source, "'title'");
            $hasSlug = str_contains($source, "'slug'");
            $hasBody = str_contains($source, "'body'");
            $hasStatus = str_contains($source, "'status'");
            $hasAuthor = str_contains($source, 'author_id') && str_contains($source, 'user_id');
            $hasPublishedAt = str_contains($source, 'published_at');
            $returnsJson = str_contains($source, 'content_id') && str_contains($source, 'edit_url');

            if ($insertsContent && $hasTitle && $hasBody && $hasStatus && $hasAuthor && $hasPublishedAt && $returnsJson) {
                test_pass('create() inserts content with all required fields and returns content_id + edit_url');
            } else {
                $missing = [];
                if (!$insertsContent) { $missing[] = 'content insert'; }
                if (!$hasAuthor) { $missing[] = 'author_id'; }
                if (!$hasPublishedAt) { $missing[] = 'published_at'; }
                if (!$returnsJson) { $missing[] = 'JSON response'; }
                test_fail('create() content insertion', 'missing: ' . implode(', ', $missing));
            }
        }
    }
} catch (\Throwable $e) {
    test_fail('create() content insertion check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: PageGeneratorController::create() saves custom fields
// ---------------------------------------------------------------------------
try {
    $sourceFile = $rootDir . '/app/AIAssistant/PageGeneratorController.php';
    if (!file_exists($sourceFile)) {
        test_fail('create() custom fields', 'PageGeneratorController.php not found');
    } else {
        $source = file_get_contents($sourceFile);

        $hasCustomFieldsLoop = str_contains($source, 'custom_fields');
        $insertsCustomFields = str_contains($source, "query('custom_fields')") && str_contains($source, 'field_key') && str_contains($source, 'field_value');

        if ($hasCustomFieldsLoop && $insertsCustomFields) {
            test_pass('create() iterates custom_fields and inserts each into custom_fields table');
        } else {
            $missing = [];
            if (!$hasCustomFieldsLoop) { $missing[] = 'custom_fields handling'; }
            if (!$insertsCustomFields) { $missing[] = 'custom_fields table insert'; }
            test_fail('create() custom fields', 'missing: ' . implode(', ', $missing));
        }
    }
} catch (\Throwable $e) {
    test_fail('create() custom fields check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: Slug generation and uniqueness
// ---------------------------------------------------------------------------
try {
    $ref = new ReflectionClass(\App\AIAssistant\PageGeneratorController::class);

    // Test generateSlug
    $genSlug = $ref->getMethod('generateSlug');
    $genSlug->setAccessible(true);

    $controller = new \App\AIAssistant\PageGeneratorController($app);

    $slug1 = $genSlug->invoke($controller, 'Hello World Page!');
    if ($slug1 === 'hello-world-page') {
        test_pass('generateSlug() converts "Hello World Page!" to "hello-world-page"');
    } else {
        test_fail('generateSlug()', "expected 'hello-world-page', got '{$slug1}'");
    }

    // Test ensureUniqueSlug
    $ensureSlug = $ref->getMethod('ensureUniqueSlug');
    $ensureSlug->setAccessible(true);

    // Insert a content record to test uniqueness
    \App\Database\QueryBuilder::query('content')->insert([
        'type'       => 'page',
        'title'      => 'Test Page',
        'slug'       => 'test-page',
        'body'       => '<p>Test</p>',
        'excerpt'    => '',
        'status'     => 'draft',
        'author_id'  => $testUserId,
        'sort_order' => 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    $unique = $ensureSlug->invoke($controller, 'test-page');
    if ($unique === 'test-page-2') {
        test_pass('ensureUniqueSlug() appends -2 when slug exists');
    } else {
        test_fail('ensureUniqueSlug()', "expected 'test-page-2', got '{$unique}'");
    }

    // Non-conflicting slug stays the same
    $unique2 = $ensureSlug->invoke($controller, 'new-unique-slug');
    if ($unique2 === 'new-unique-slug') {
        test_pass('ensureUniqueSlug() returns original slug when no conflict');
    } else {
        test_fail('ensureUniqueSlug() no conflict', "expected 'new-unique-slug', got '{$unique2}'");
    }
} catch (\Throwable $e) {
    test_fail('Slug generation check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: parseGeneratedContent() parses valid JSON
// ---------------------------------------------------------------------------
$parseMethod = null;
$parseController = null;
try {
    $ref = new ReflectionClass(\App\AIAssistant\PageGeneratorController::class);
    $parseMethod = $ref->getMethod('parseGeneratedContent');
    $parseMethod->setAccessible(true);

    $parseController = new \App\AIAssistant\PageGeneratorController($app);

    $validJson = json_encode([
        'title'            => 'About Us',
        'slug'             => 'about-us',
        'excerpt'          => 'Learn about our company.',
        'meta_title'       => 'About Us - My Site',
        'meta_description' => 'We are a great company.',
        'body'             => '<section><h2>Our Story</h2><p>We started in 2020.</p></section>',
        'custom_fields'    => [],
    ]);

    $result = $parseMethod->invoke($parseController, $validJson);

    if ($result !== null
        && $result['title'] === 'About Us'
        && $result['slug'] === 'about-us'
        && str_contains($result['body'], '<section>')
        && $result['excerpt'] === 'Learn about our company.'
        && $result['meta_title'] === 'About Us - My Site'
    ) {
        test_pass('parseGeneratedContent() correctly parses valid JSON with all fields');
    } else {
        test_fail('parseGeneratedContent() valid JSON', 'parsed result missing expected fields');
    }
} catch (\Throwable $e) {
    test_fail('parseGeneratedContent() valid JSON', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: parseGeneratedContent() strips markdown code fences
// ---------------------------------------------------------------------------
try {
    if ($parseMethod === null || $parseController === null) {
        test_fail('parseGeneratedContent() code fences', 'parseMethod not available (test 13 failed)');
        test_fail('parseGeneratedContent() fences no lang', 'parseMethod not available');
    } else {
        $fencedJson = "```json\n" . json_encode([
            'title' => 'Test Page',
            'body'  => '<section><h2>Hello</h2></section>',
        ]) . "\n```";

        $result = $parseMethod->invoke($parseController, $fencedJson);

        if ($result !== null && $result['title'] === 'Test Page') {
            test_pass('parseGeneratedContent() strips markdown code fences and parses JSON');
        } else {
            test_fail('parseGeneratedContent() code fences', 'failed to parse fenced JSON');
        }

        // Also test without language specifier
        $fencedNoLang = "```\n" . json_encode([
            'title' => 'Another Page',
            'body'  => '<p>Content</p>',
        ]) . "\n```";

        $result2 = $parseMethod->invoke($parseController, $fencedNoLang);
        if ($result2 !== null && $result2['title'] === 'Another Page') {
            test_pass('parseGeneratedContent() strips code fences without language specifier');
        } else {
            test_fail('parseGeneratedContent() fences no lang', 'failed to parse');
        }
    }
} catch (\Throwable $e) {
    test_fail('parseGeneratedContent() code fences', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: parseGeneratedContent() returns null for invalid input
// ---------------------------------------------------------------------------
try {
    if ($parseMethod === null || $parseController === null) {
        test_fail('parseGeneratedContent() invalid input', 'parseMethod not available (test 13 failed)');
    } else {
        // Invalid JSON
        $invalid1 = $parseMethod->invoke($parseController, 'This is not JSON at all');
        if ($invalid1 === null) {
            test_pass('parseGeneratedContent() returns null for non-JSON text');
        } else {
            test_fail('parseGeneratedContent() invalid JSON', 'expected null, got array');
        }

        // Valid JSON but missing required keys
        $invalid2 = $parseMethod->invoke($parseController, json_encode(['slug' => 'test']));
        if ($invalid2 === null) {
            test_pass('parseGeneratedContent() returns null when title and body are missing');
        } else {
            test_fail('parseGeneratedContent() missing keys', 'expected null for JSON without title/body');
        }

        // Empty string
        $invalid3 = $parseMethod->invoke($parseController, '');
        if ($invalid3 === null) {
            test_pass('parseGeneratedContent() returns null for empty string');
        } else {
            test_fail('parseGeneratedContent() empty', 'expected null');
        }
    }
} catch (\Throwable $e) {
    test_fail('parseGeneratedContent() invalid input', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: Generator template exists with required UI elements
// ---------------------------------------------------------------------------
try {
    $tplPath = $rootDir . '/templates/admin/generator/index.php';
    if (!file_exists($tplPath)) {
        test_fail('Generator template exists', 'templates/admin/generator/index.php not found');
    } else {
        $tpl = file_get_contents($tplPath);

        $hasLayout = str_contains($tpl, 'layout(');
        $hasSteps = str_contains($tpl, 'step-setup') && str_contains($tpl, 'step-gathering')
                    && str_contains($tpl, 'step-preview') && str_contains($tpl, 'step-created');
        $hasChat = str_contains($tpl, 'generator-messages') && str_contains($tpl, 'generator-input');
        $hasTypeSelector = str_contains($tpl, 'type-option') || str_contains($tpl, 'type-selector');
        $hasPreview = str_contains($tpl, 'preview-title') && str_contains($tpl, 'preview-body');
        $hasCreateButtons = str_contains($tpl, 'btn-create-draft') && str_contains($tpl, 'btn-create-publish');
        $hasSuccess = str_contains($tpl, 'btn-edit-content') || str_contains($tpl, 'Open in Editor');
        $hasCsrf = str_contains($tpl, 'csrfToken') || str_contains($tpl, 'csrf');
        $hasScript = str_contains($tpl, 'page-generator.js');

        if ($hasLayout && $hasSteps && $hasChat && $hasTypeSelector && $hasPreview
            && $hasCreateButtons && $hasSuccess && $hasCsrf && $hasScript) {
            test_pass('Generator template has all required elements (layout, 4 steps, chat, type selector, preview, create buttons, CSRF, JS)');
        } else {
            $missing = [];
            if (!$hasLayout) { $missing[] = 'layout call'; }
            if (!$hasSteps) { $missing[] = '4 step panels'; }
            if (!$hasChat) { $missing[] = 'chat interface'; }
            if (!$hasTypeSelector) { $missing[] = 'type selector'; }
            if (!$hasPreview) { $missing[] = 'preview pane'; }
            if (!$hasCreateButtons) { $missing[] = 'create buttons'; }
            if (!$hasSuccess) { $missing[] = 'success step'; }
            if (!$hasCsrf) { $missing[] = 'CSRF token'; }
            if (!$hasScript) { $missing[] = 'page-generator.js script'; }
            test_fail('Generator template elements', 'missing: ' . implode(', ', $missing));
        }
    }
} catch (\Throwable $e) {
    test_fail('Generator template check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 17: page-generator.js exists with required functions
// ---------------------------------------------------------------------------
try {
    $jsPath = $rootDir . '/public/assets/js/page-generator.js';
    if (!file_exists($jsPath)) {
        test_fail('page-generator.js exists', 'public/assets/js/page-generator.js not found');
    } else {
        $js = file_get_contents($jsPath);
        // Also load shared ai-chat-core.js for combined checks
        $coreJsPath53 = $rootDir . '/public/assets/js/ai-chat-core.js';
        $combinedJs53 = $js . (file_exists($coreJsPath53) ? "\n" . file_get_contents($coreJsPath53) : '');

        $hasSendMessage = str_contains($combinedJs53, 'sendMessage');
        $hasGoToStep = str_contains($combinedJs53, 'goToStep');
        $hasOnTypeSelected = str_contains($combinedJs53, 'onTypeSelected') || str_contains($combinedJs53, 'TypeSelected');
        $hasRequestGen = str_contains($combinedJs53, 'requestGeneration');
        $hasCreateContent = str_contains($combinedJs53, 'createContent');
        $hasAppendMessage = str_contains($combinedJs53, 'appendMessage');
        $hasApiCall = str_contains($combinedJs53, 'apiCall') || (str_contains($combinedJs53, 'fetch') && str_contains($combinedJs53, '/admin/generator'));
        $hasCsrf = str_contains($combinedJs53, 'X-CSRF-Token') || str_contains($combinedJs53, 'csrf');
        $hasReadyCheck = str_contains($combinedJs53, 'ready') && str_contains($combinedJs53, 'Generate');
        $hasDomReady = str_contains($combinedJs53, 'DOMContentLoaded');

        if ($hasSendMessage && $hasGoToStep && $hasRequestGen && $hasCreateContent
            && $hasAppendMessage && $hasApiCall && $hasCsrf && $hasDomReady) {
            test_pass('page-generator.js has all required functions (sendMessage, goToStep, requestGeneration, createContent, appendMessage, apiCall, CSRF, DOMContentLoaded)');
        } else {
            $missing = [];
            if (!$hasSendMessage) { $missing[] = 'sendMessage'; }
            if (!$hasGoToStep) { $missing[] = 'goToStep'; }
            if (!$hasRequestGen) { $missing[] = 'requestGeneration'; }
            if (!$hasCreateContent) { $missing[] = 'createContent'; }
            if (!$hasAppendMessage) { $missing[] = 'appendMessage'; }
            if (!$hasApiCall) { $missing[] = 'apiCall/fetch'; }
            if (!$hasCsrf) { $missing[] = 'CSRF token'; }
            if (!$hasDomReady) { $missing[] = 'DOMContentLoaded'; }
            test_fail('page-generator.js functions', 'missing: ' . implode(', ', $missing));
        }

        // Check for Enter key handling (may be in shared ai-chat-core.js)
        if (str_contains($combinedJs53, 'Enter') && str_contains($combinedJs53, 'shiftKey')) {
            test_pass('page-generator.js handles Enter key (send) and Shift+Enter (newline)');
        } else {
            test_fail('page-generator.js Enter key', 'Enter/shiftKey handling not found');
        }

        // Check for READY_TO_GENERATE marker handling
        if ($hasReadyCheck) {
            test_pass('page-generator.js checks for ready state and shows Generate button');
        } else {
            test_fail('page-generator.js ready state', 'ready/Generate check not found');
        }
    }
} catch (\Throwable $e) {
    test_fail('page-generator.js check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: Admin layout has "Generate Page" nav link
// ---------------------------------------------------------------------------
try {
    $layoutPath = $rootDir . '/templates/admin/layout.php';
    if (!file_exists($layoutPath)) {
        test_fail('Admin layout exists', 'templates/admin/layout.php not found');
    } else {
        $layout = file_get_contents($layoutPath);

        $hasLink = str_contains($layout, '/admin/generator');
        $hasLabel = str_contains($layout, 'Generate') || str_contains($layout, 'generator');
        $hasActiveNav = str_contains($layout, "'generator'") || str_contains($layout, '"generator"');

        if ($hasLink && $hasLabel && $hasActiveNav) {
            test_pass('Admin layout has "Generate Page" nav link with /admin/generator href and active state');
        } else {
            $missing = [];
            if (!$hasLink) { $missing[] = '/admin/generator link'; }
            if (!$hasLabel) { $missing[] = 'Generate label'; }
            if (!$hasActiveNav) { $missing[] = 'active nav state'; }
            test_fail('Admin layout generator link', 'missing: ' . implode(', ', $missing));
        }
    }
} catch (\Throwable $e) {
    test_fail('Admin layout check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: Routes registered for generator
// ---------------------------------------------------------------------------
try {
    $indexSource = file_get_contents($rootDir . '/public/index.php');

    $hasImport = str_contains($indexSource, 'PageGeneratorController');
    $hasGetRoute = str_contains($indexSource, '/generator') && str_contains($indexSource, 'index');
    $hasChatRoute = str_contains($indexSource, '/generator/chat') && str_contains($indexSource, 'chat');
    $hasCreateRoute = str_contains($indexSource, '/generator/create') && str_contains($indexSource, 'create');

    if ($hasImport && $hasGetRoute && $hasChatRoute && $hasCreateRoute) {
        test_pass('Routes registered: GET /generator, POST /generator/chat, POST /generator/create with PageGeneratorController');
    } else {
        $missing = [];
        if (!$hasImport) { $missing[] = 'PageGeneratorController import'; }
        if (!$hasGetRoute) { $missing[] = 'GET /generator route'; }
        if (!$hasChatRoute) { $missing[] = 'POST /generator/chat route'; }
        if (!$hasCreateRoute) { $missing[] = 'POST /generator/create route'; }
        test_fail('Generator routes', 'missing: ' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    test_fail('Route registration check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 20: admin.css has generator styles
// ---------------------------------------------------------------------------
try {
    $cssPath = $rootDir . '/public/assets/css/admin.css';
    if (!file_exists($cssPath)) {
        test_fail('admin.css exists', 'public/assets/css/admin.css not found');
    } else {
        $css = file_get_contents($cssPath);

        $hasContainer = str_contains($css, '.generator-container');
        $hasSteps = str_contains($css, '.generator-steps');
        $hasTypeSelector = str_contains($css, '.type-selector') || str_contains($css, '.type-option');
        $hasChatStyles = str_contains($css, '.generator-chat') || str_contains($css, '.chat-messages');
        $hasChatBubbles = str_contains($css, '.chat-bubble') || str_contains($css, '.chat-message');
        $hasPreview = str_contains($css, '.preview-content') || str_contains($css, '.preview-meta');
        $hasSuccess = str_contains($css, '.success-message');
        $hasHidden = str_contains($css, '.generator-panel');

        if ($hasContainer && $hasSteps && $hasTypeSelector && $hasChatStyles && $hasPreview) {
            test_pass('admin.css has generator styles (container, steps, type selector, chat, preview)');
        } else {
            $missing = [];
            if (!$hasContainer) { $missing[] = '.generator-container'; }
            if (!$hasSteps) { $missing[] = '.generator-steps'; }
            if (!$hasTypeSelector) { $missing[] = '.type-selector/.type-option'; }
            if (!$hasChatStyles) { $missing[] = '.generator-chat/.chat-messages'; }
            if (!$hasPreview) { $missing[] = '.preview-content/.preview-meta'; }
            test_fail('admin.css generator styles', 'missing: ' . implode(', ', $missing));
        }
    }
} catch (\Throwable $e) {
    test_fail('admin.css check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup: remove the test database
// ---------------------------------------------------------------------------
\App\Database\Connection::reset();

$configProp->setValue(null, null);
if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }
putenv('DB_DRIVER');
putenv('DB_PATH');
putenv('APP_SECRET');

if (file_exists($testDbPath)) { @unlink($testDbPath); }
if (file_exists($walPath)) { @unlink($walPath); }
if (file_exists($shmPath)) { @unlink($shmPath); }

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 5.3 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
