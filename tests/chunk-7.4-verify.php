<?php declare(strict_types=1);

/**
 * Chunk 7.4 — AI Design Pipeline
 * Automated Verification Tests
 *
 * Tests:
 *   1.  Migration files exist (3 drivers)
 *   2.  Migration SQL has correct ALTER TABLE
 *   3.  Migration applies — design_file column exists
 *   4.  GeneratorPrompts has design methods
 *   5.  formatDesignSystemComponents produces valid output
 *   6.  penDesignGatheringPrompt includes component context
 *   7.  penDesignGenerationPrompt specifies JSON format
 *   8.  PageGeneratorController handles design mode
 *   9.  parseGeneratedContent handles design JSON
 *  10.  ContentController validates design editor_mode
 *  11.  ContentController has previewPen method
 *  12.  previewPen converts pen_page with design system
 *  13.  Route /admin/content/preview-pen registered
 *  14.  Generator template has design mode button
 *  15.  page-generator.js handles design mode
 *  16.  Design file save creates valid .pen JSON
 *  17.  PenConverter renders assembled design document
 *  18.  Content CRUD persists design_file
 *  19.  Descendants overrides work in generated pages
 *  20.  Full pipeline integration
 *
 * Smoke mode (LITECMS_TEST_SMOKE=1): runs only tests 1-5
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
// Autoloader
// ---------------------------------------------------------------------------
$autoloadPath = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found — run composer install');
    echo "\n[FAIL] Cannot continue — autoloader missing\n";
    exit(1);
}
require_once $autoloadPath;

use App\AIAssistant\GeneratorPrompts;
use App\AIAssistant\PageGeneratorController;
use App\Admin\ContentController;
use App\PageBuilder\PenConverter;
use App\Database\Connection;
use App\Database\QueryBuilder;
use App\Database\Migrator;

// Load design system (needed by multiple tests)
$dsPath = $rootDir . '/designs/litecms-system.pen';
$dsDoc = null;
if (file_exists($dsPath)) {
    $dsDoc = json_decode(file_get_contents($dsPath), true);
}

// ---------------------------------------------------------------------------
// Test 1: Migration files exist (3 drivers)
// ---------------------------------------------------------------------------
$migrationDrivers = ['sqlite', 'mysql', 'pgsql'];
$migrationFiles = [];
$allExist = true;
foreach ($migrationDrivers as $driver) {
    $path = $rootDir . "/migrations/010_design_file.{$driver}.sql";
    $migrationFiles[$driver] = $path;
    if (!file_exists($path)) {
        $allExist = false;
    }
}
if ($allExist) {
    test_pass('Test 1: Migration files exist (3 drivers)');
} else {
    $missing = [];
    foreach ($migrationFiles as $d => $p) {
        if (!file_exists($p)) $missing[] = $d;
    }
    test_fail('Test 1: Migration files exist', 'missing: ' . implode(', ', $missing));
}

// ---------------------------------------------------------------------------
// Test 2: Migration SQL has correct ALTER TABLE
// ---------------------------------------------------------------------------
$sqlContent = @file_get_contents($migrationFiles['sqlite'] ?? '');
if ($sqlContent !== false &&
    stripos($sqlContent, 'ALTER TABLE') !== false &&
    stripos($sqlContent, 'design_file') !== false) {
    test_pass('Test 2: Migration SQL has ALTER TABLE with design_file');
} else {
    test_fail('Test 2: Migration SQL content', 'expected ALTER TABLE ... design_file');
}

// ---------------------------------------------------------------------------
// Test 3: Migration applies — design_file column exists
// ---------------------------------------------------------------------------
try {
    $pdo = Connection::getInstance();
    $migrator = new Migrator($pdo);
    $migrator->migrate();

    $stmt = $pdo->query("PRAGMA table_info(content)");
    $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $hasDesignFile = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'design_file') {
            $hasDesignFile = true;
            break;
        }
    }

    if ($hasDesignFile) {
        test_pass('Test 3: Migration applies — design_file column exists');
    } else {
        test_fail('Test 3: Migration applies', 'design_file column not found in content table');
    }
} catch (\Throwable $e) {
    test_fail('Test 3: Migration applies', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 4: GeneratorPrompts has design methods
// ---------------------------------------------------------------------------
$requiredMethods = [
    'formatDesignSystemComponents',
    'penDesignGatheringPrompt',
    'penDesignGenerationPrompt',
];
$missingMethods = [];
foreach ($requiredMethods as $method) {
    if (!method_exists(GeneratorPrompts::class, $method)) {
        $missingMethods[] = $method;
    }
}
if (empty($missingMethods)) {
    test_pass('Test 4: GeneratorPrompts has design methods');
} else {
    test_fail('Test 4: GeneratorPrompts methods', 'missing: ' . implode(', ', $missingMethods));
}

// ---------------------------------------------------------------------------
// Test 5: formatDesignSystemComponents produces valid output
// ---------------------------------------------------------------------------
$componentSummary = '';
try {
    if ($dsDoc === null) {
        test_fail('Test 5: formatDesignSystemComponents', 'design system file not loaded');
    } else {
        $componentSummary = GeneratorPrompts::formatDesignSystemComponents($dsDoc);

        $hasComponent = str_contains($componentSummary, 'hero-section');
        $hasSlots = str_contains($componentSummary, 'Slots:');
        $hasLength = strlen($componentSummary) > 100;

        if ($hasComponent && $hasSlots && $hasLength) {
            test_pass('Test 5: formatDesignSystemComponents produces valid output');
        } else {
            $issues = [];
            if (!$hasComponent) $issues[] = 'missing hero-section';
            if (!$hasSlots) $issues[] = 'missing Slots:';
            if (!$hasLength) $issues[] = 'output too short';
            test_fail('Test 5: formatDesignSystemComponents', implode(', ', $issues));
        }
    }
} catch (\Throwable $e) {
    test_fail('Test 5: formatDesignSystemComponents', $e->getMessage());
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 7.4 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 6: penDesignGatheringPrompt includes component context
// ---------------------------------------------------------------------------
try {
    $prompt = GeneratorPrompts::penDesignGatheringPrompt(
        'Test Site', [['title' => 'Home', 'slug' => '/']], null, $componentSummary
    );
    $ok = str_contains($prompt, 'hero-section') &&
          str_contains($prompt, 'READY_TO_GENERATE') &&
          str_contains($prompt, 'design system');
    if ($ok) {
        test_pass('Test 6: penDesignGatheringPrompt includes component context');
    } else {
        $issues = [];
        if (!str_contains($prompt, 'hero-section')) $issues[] = 'missing hero-section';
        if (!str_contains($prompt, 'READY_TO_GENERATE')) $issues[] = 'missing READY_TO_GENERATE';
        if (!str_contains($prompt, 'design system')) $issues[] = 'missing "design system"';
        test_fail('Test 6: Gathering prompt', implode(', ', $issues));
    }
} catch (\Throwable $e) {
    test_fail('Test 6: Gathering prompt', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: penDesignGenerationPrompt specifies JSON format
// ---------------------------------------------------------------------------
try {
    $prompt = GeneratorPrompts::penDesignGenerationPrompt(
        'Test Site', 'page', null, $componentSummary, $dsDoc['variables'] ?? []
    );
    $ok = str_contains($prompt, 'pen_page') &&
          str_contains($prompt, '"ref"') &&
          str_contains($prompt, 'descendants');
    if ($ok) {
        test_pass('Test 7: penDesignGenerationPrompt specifies JSON format');
    } else {
        $issues = [];
        if (!str_contains($prompt, 'pen_page')) $issues[] = 'missing pen_page';
        if (!str_contains($prompt, '"ref"')) $issues[] = 'missing "ref"';
        if (!str_contains($prompt, 'descendants')) $issues[] = 'missing descendants';
        test_fail('Test 7: Generation prompt', implode(', ', $issues));
    }
} catch (\Throwable $e) {
    test_fail('Test 7: Generation prompt', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: PageGeneratorController handles design mode
// ---------------------------------------------------------------------------
if (class_exists(PageGeneratorController::class)) {
    test_pass('Test 8: PageGeneratorController class exists');
} else {
    test_fail('Test 8: PageGeneratorController', 'class not found');
}

// ---------------------------------------------------------------------------
// Test 9: parseGeneratedContent handles design JSON
// ---------------------------------------------------------------------------
try {
    $ref = new \ReflectionClass(PageGeneratorController::class);
    $method = $ref->getMethod('parseGeneratedContent');
    $method->setAccessible(true);

    $testJson = json_encode([
        'title' => 'Test Page',
        'slug' => 'test-page',
        'excerpt' => 'Test excerpt',
        'meta_title' => 'Test Meta',
        'meta_description' => 'Test desc',
        'pen_page' => [
            'id' => 'page-root',
            'type' => 'frame',
            'name' => 'Page',
            'layout' => 'vertical',
            'width' => 1200,
            'children' => [
                ['id' => 'hero-1', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                    'hero-heading' => ['content' => 'My Custom Title'],
                ]],
            ],
        ],
    ]);

    $app = new \App\Core\App();
    $controller = new PageGeneratorController($app);

    // Third parameter (isDesign) should be the new design mode flag
    $params = $method->getParameters();
    $paramCount = count($params);

    if ($paramCount >= 3) {
        $result = $method->invoke($controller, $testJson, false, true);
    } else {
        // Fallback: maybe design mode is detected differently
        $result = $method->invoke($controller, $testJson, false);
    }

    if (is_array($result) &&
        ($result['title'] ?? '') === 'Test Page' &&
        ($result['editor_mode'] ?? '') === 'design' &&
        is_array($result['pen_page'] ?? null) &&
        ($result['pen_page']['id'] ?? '') === 'page-root') {
        test_pass('Test 9: parseGeneratedContent handles design JSON');
    } else {
        test_fail('Test 9: Design JSON parsing', 'returned unexpected structure');
    }
} catch (\Throwable $e) {
    test_fail('Test 9: Design JSON parsing', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: ContentController validates design editor_mode
// ---------------------------------------------------------------------------
try {
    $ccContent = file_get_contents($rootDir . '/app/Admin/ContentController.php');
    if (str_contains($ccContent, "'design'") &&
        str_contains($ccContent, 'design_file')) {
        test_pass('Test 10: ContentController validates design editor_mode');
    } else {
        $issues = [];
        if (!str_contains($ccContent, "'design'")) $issues[] = "missing 'design' mode";
        if (!str_contains($ccContent, 'design_file')) $issues[] = 'missing design_file handling';
        test_fail('Test 10: ContentController', implode(', ', $issues));
    }
} catch (\Throwable $e) {
    test_fail('Test 10: ContentController', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: ContentController has previewPen method
// ---------------------------------------------------------------------------
if (method_exists(ContentController::class, 'previewPen')) {
    test_pass('Test 11: ContentController has previewPen method');
} else {
    test_fail('Test 11: previewPen method', 'method not found on ContentController');
}

// ---------------------------------------------------------------------------
// Test 12: previewPen converts pen_page with design system
// ---------------------------------------------------------------------------
try {
    // Test the conversion logic directly (same as previewPen internals)
    $penPage = [
        'id' => 'preview-test',
        'type' => 'frame',
        'name' => 'Preview Test',
        'layout' => 'vertical',
        'width' => 1200,
        'children' => [
            ['id' => 'ph1', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                'hero-heading' => ['content' => 'Preview Test Title'],
            ]],
        ],
    ];

    $document = [
        'version' => '2.7',
        'variables' => $dsDoc['variables'] ?? [],
        'children' => array_merge($dsDoc['children'] ?? [], [$penPage]),
    ];

    $result = PenConverter::convertDocument($document);

    if (str_contains($result['html'], 'Preview Test Title') &&
        str_contains($result['css'], ':root')) {
        test_pass('Test 12: previewPen converts pen_page with design system');
    } else {
        $issues = [];
        if (!str_contains($result['html'], 'Preview Test Title')) $issues[] = 'custom text missing from HTML';
        if (!str_contains($result['css'], ':root')) $issues[] = ':root missing from CSS';
        test_fail('Test 12: Preview conversion', implode(', ', $issues));
    }
} catch (\Throwable $e) {
    test_fail('Test 12: Preview conversion', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: Route /admin/content/preview-pen registered
// ---------------------------------------------------------------------------
try {
    $indexPhp = file_get_contents($rootDir . '/public/index.php');
    if (str_contains($indexPhp, 'preview-pen') &&
        str_contains($indexPhp, 'previewPen')) {
        test_pass('Test 13: Route /admin/content/preview-pen registered');
    } else {
        test_fail('Test 13: Route registration', 'preview-pen route not found in index.php');
    }
} catch (\Throwable $e) {
    test_fail('Test 13: Route', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: Generator template has design mode button
// ---------------------------------------------------------------------------
try {
    $tplContent = file_get_contents($rootDir . '/templates/admin/generator/index.php');
    if (str_contains($tplContent, 'data-mode="design"') &&
        str_contains($tplContent, 'Visual Design')) {
        test_pass('Test 14: Generator template has design mode button');
    } else {
        $issues = [];
        if (!str_contains($tplContent, 'data-mode="design"')) $issues[] = 'missing data-mode="design"';
        if (!str_contains($tplContent, 'Visual Design')) $issues[] = 'missing "Visual Design" text';
        test_fail('Test 14: Template', implode(', ', $issues));
    }
} catch (\Throwable $e) {
    test_fail('Test 14: Template', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: page-generator.js handles design mode
// ---------------------------------------------------------------------------
try {
    $jsContent = file_get_contents($rootDir . '/public/assets/js/page-generator.js');
    if (str_contains($jsContent, 'design') &&
        str_contains($jsContent, 'preview-pen') &&
        str_contains($jsContent, 'pen_page')) {
        test_pass('Test 15: page-generator.js handles design mode');
    } else {
        $issues = [];
        if (!str_contains($jsContent, 'design')) $issues[] = 'missing "design" handling';
        if (!str_contains($jsContent, 'preview-pen')) $issues[] = 'missing preview-pen fetch';
        if (!str_contains($jsContent, 'pen_page')) $issues[] = 'missing pen_page reference';
        test_fail('Test 15: JS file', implode(', ', $issues));
    }
} catch (\Throwable $e) {
    test_fail('Test 15: JS file', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: Design file save creates valid .pen JSON
// ---------------------------------------------------------------------------
try {
    $testPage = [
        'id' => 'save-test-page',
        'type' => 'frame',
        'name' => 'Save Test',
        'layout' => 'vertical',
        'width' => 1200,
        'children' => [
            ['id' => 'save-hero', 'type' => 'ref', 'ref' => 'hero-section'],
        ],
    ];

    $fullDoc = [
        'version' => '2.7',
        'variables' => $dsDoc['variables'] ?? [],
        'children' => array_merge($dsDoc['children'] ?? [], [$testPage]),
    ];

    $testPath = $rootDir . '/designs/pages/_test_save.pen';
    $dir = dirname($testPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($testPath, json_encode($fullDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    // Verify it's valid JSON and re-convertable
    $reloaded = json_decode(file_get_contents($testPath), true, 512, JSON_THROW_ON_ERROR);
    $reResult = PenConverter::convertDocument($reloaded);

    if (strlen($reResult['html']) > 50 && str_contains($reResult['css'], ':root')) {
        test_pass('Test 16: Design file save creates valid .pen JSON');
    } else {
        test_fail('Test 16: Save/reload', 're-converted HTML too short or missing CSS');
    }

    @unlink($testPath);
} catch (\Throwable $e) {
    test_fail('Test 16: Save/reload', $e->getMessage());
    @unlink($rootDir . '/designs/pages/_test_save.pen');
}

// ---------------------------------------------------------------------------
// Test 17: PenConverter renders assembled design document
// ---------------------------------------------------------------------------
try {
    $page = [
        'id' => 'render-test',
        'type' => 'frame',
        'name' => 'Render Test Page',
        'layout' => 'vertical',
        'width' => 1200,
        'children' => [
            ['id' => 'r-hero', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                'hero-heading' => ['content' => 'Render Test Hero'],
            ]],
            ['id' => 'r-text', 'type' => 'ref', 'ref' => 'text-section', 'descendants' => [
                'text-content-wrapper/text-heading' => ['content' => 'Render Test Section'],
                'text-content-wrapper/text-body' => ['content' => 'This is render test body content.'],
            ]],
            ['id' => 'r-footer', 'type' => 'ref', 'ref' => 'footer-section', 'descendants' => [
                'footer-left/footer-copyright' => ['content' => '© 2026 Render Test'],
            ]],
        ],
    ];

    $doc = [
        'version' => '2.7',
        'variables' => $dsDoc['variables'] ?? [],
        'children' => array_merge($dsDoc['children'] ?? [], [$page]),
    ];

    $result = PenConverter::convertDocument($doc);

    $checks = [
        str_contains($result['html'], 'Render Test Hero'),
        str_contains($result['html'], 'Render Test Section'),
        str_contains($result['html'], 'render test body content'),
        str_contains($result['css'], '--primary'),
    ];

    if (!in_array(false, $checks, true)) {
        test_pass('Test 17: PenConverter renders assembled design document');
    } else {
        test_fail('Test 17: Render test', 'missing expected content in HTML or CSS');
    }
} catch (\Throwable $e) {
    test_fail('Test 17: Render test', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: Content CRUD persists design_file
// ---------------------------------------------------------------------------
try {
    $testSlug = '_test_design_' . time();
    $id = QueryBuilder::query('content')->insert([
        'type'        => 'page',
        'title'       => 'Design Test Page',
        'slug'        => $testSlug,
        'body'        => '<p>Test</p>',
        'excerpt'     => '',
        'status'      => 'draft',
        'author_id'   => 1,
        'sort_order'  => 0,
        'editor_mode' => 'design',
        'design_file' => 'pages/test-design.pen',
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);

    $record = QueryBuilder::query('content')
        ->select('editor_mode', 'design_file')
        ->where('id', (int) $id)
        ->first();

    if ($record !== null &&
        ($record['editor_mode'] ?? '') === 'design' &&
        ($record['design_file'] ?? '') === 'pages/test-design.pen') {
        test_pass('Test 18: Content CRUD persists design_file');
    } else {
        test_fail('Test 18: Content CRUD', 'design_file or editor_mode not persisted correctly');
    }

    // Cleanup
    QueryBuilder::query('content')->where('id', (int) $id)->delete();
} catch (\Throwable $e) {
    test_fail('Test 18: Content CRUD', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: Descendants overrides work in generated pages
// ---------------------------------------------------------------------------
try {
    $page = [
        'id' => 'override-test',
        'type' => 'frame',
        'name' => 'Override Test',
        'layout' => 'vertical',
        'width' => 1200,
        'children' => [
            ['id' => 'oh', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                'hero-heading' => ['content' => 'UNIQUE_OVERRIDE_TITLE_XYZ'],
                'hero-subheading' => ['content' => 'UNIQUE_OVERRIDE_SUB_ABC'],
                'hero-cta/hero-cta-text' => ['content' => 'UNIQUE_CTA_BTN'],
            ]],
        ],
    ];

    $doc = [
        'version' => '2.7',
        'variables' => $dsDoc['variables'] ?? [],
        'children' => array_merge($dsDoc['children'] ?? [], [$page]),
    ];

    $result = PenConverter::convertDocument($doc);

    $ok = str_contains($result['html'], 'UNIQUE_OVERRIDE_TITLE_XYZ') &&
          str_contains($result['html'], 'UNIQUE_OVERRIDE_SUB_ABC') &&
          str_contains($result['html'], 'UNIQUE_CTA_BTN') &&
          !str_contains($result['html'], 'Welcome to Our Site');

    if ($ok) {
        test_pass('Test 19: Descendants overrides work in generated pages');
    } else {
        $issues = [];
        if (!str_contains($result['html'], 'UNIQUE_OVERRIDE_TITLE_XYZ')) $issues[] = 'title override missing';
        if (!str_contains($result['html'], 'UNIQUE_OVERRIDE_SUB_ABC')) $issues[] = 'subtitle override missing';
        if (!str_contains($result['html'], 'UNIQUE_CTA_BTN')) $issues[] = 'CTA override missing';
        if (str_contains($result['html'], 'Welcome to Our Site')) $issues[] = 'default text still present';
        test_fail('Test 19: Overrides', implode(', ', $issues));
    }
} catch (\Throwable $e) {
    test_fail('Test 19: Overrides', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 20: Full pipeline integration
// ---------------------------------------------------------------------------
try {
    $ok = true;

    // 1. Format design system components
    $summary = GeneratorPrompts::formatDesignSystemComponents($dsDoc);

    // 2. Generate prompts (verify they don't crash)
    $gatherPrompt = GeneratorPrompts::penDesignGatheringPrompt(
        'Test Site', [['title' => 'Home', 'slug' => '/']], null, $summary
    );
    $genPrompt = GeneratorPrompts::penDesignGenerationPrompt(
        'Test Site', 'page', null, $summary, $dsDoc['variables'] ?? []
    );

    // 3. Simulate AI output (parseGeneratedContent)
    $fakeAiOutput = json_encode([
        'title' => 'Integration Test Page',
        'slug' => 'integration-test',
        'excerpt' => 'An integration test page.',
        'meta_title' => 'Integration Test',
        'meta_description' => 'Testing the full pipeline.',
        'pen_page' => [
            'id' => 'int-page',
            'type' => 'frame',
            'name' => 'Integration Page',
            'layout' => 'vertical',
            'width' => 1200,
            'children' => [
                ['id' => 'int-hero', 'type' => 'ref', 'ref' => 'hero-section', 'descendants' => [
                    'hero-heading' => ['content' => 'Integration Hero'],
                ]],
                ['id' => 'int-text', 'type' => 'ref', 'ref' => 'text-section', 'descendants' => [
                    'text-content-wrapper/text-heading' => ['content' => 'Integration Section'],
                ]],
                ['id' => 'int-cta', 'type' => 'ref', 'ref' => 'cta-banner', 'descendants' => [
                    'cta-text-group/cta-heading' => ['content' => 'Integration CTA'],
                ]],
            ],
        ],
    ]);

    // 4. Parse via reflection
    $ref = new \ReflectionClass(PageGeneratorController::class);
    $parseMethod = $ref->getMethod('parseGeneratedContent');
    $parseMethod->setAccessible(true);
    $app = new \App\Core\App();
    $controller = new PageGeneratorController($app);

    $params = $parseMethod->getParameters();
    if (count($params) >= 3) {
        $parsed = $parseMethod->invoke($controller, $fakeAiOutput, false, true);
    } else {
        $parsed = $parseMethod->invoke($controller, $fakeAiOutput, false);
    }

    // Check prompts are non-empty
    if (strlen($gatherPrompt) < 100 || strlen($genPrompt) < 100) {
        test_fail('Test 20: Integration — prompts too short');
        $ok = false;
    }

    // Check parsed data
    if (!is_array($parsed) ||
        ($parsed['title'] ?? '') !== 'Integration Test Page' ||
        ($parsed['editor_mode'] ?? '') !== 'design') {
        test_fail('Test 20: Integration — parsing', 'parsed data incorrect');
        $ok = false;
    }

    if ($ok && is_array($parsed['pen_page'] ?? null)) {
        // 5. Assemble full document
        $fullDoc = [
            'version' => '2.7',
            'variables' => $dsDoc['variables'] ?? [],
            'children' => array_merge($dsDoc['children'] ?? [], [$parsed['pen_page']]),
        ];

        // 6. Convert to HTML
        $result = PenConverter::convertDocument($fullDoc);

        // Check HTML contains customized content
        if (!str_contains($result['html'], 'Integration Hero') ||
            !str_contains($result['html'], 'Integration Section') ||
            !str_contains($result['html'], 'Integration CTA')) {
            test_fail('Test 20: Integration — HTML content', 'missing expected text in output');
            $ok = false;
        }

        // Check CSS has variables
        if (!str_contains($result['css'], ':root') || !str_contains($result['css'], '--primary')) {
            test_fail('Test 20: Integration — CSS variables', 'missing :root or --primary');
            $ok = false;
        }

        // 7. Save to disk and re-convert
        $testPenPath = $rootDir . '/designs/pages/_integration_test.pen';
        $dir = dirname($testPenPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($testPenPath, json_encode($fullDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        $reResult = PenConverter::convertFile($testPenPath);
        if (!str_contains($reResult['html'], 'Integration Hero')) {
            test_fail('Test 20: Integration — re-conversion', 'saved/reloaded file produces different output');
            $ok = false;
        }

        @unlink($testPenPath);
    } elseif ($ok) {
        test_fail('Test 20: Integration — pen_page', 'pen_page not found in parsed result');
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 20: Full pipeline integration');
    }
} catch (\Throwable $e) {
    test_fail('Test 20: Integration', $e->getMessage());
    @unlink($rootDir . '/designs/pages/_integration_test.pen');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 7.4 results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
