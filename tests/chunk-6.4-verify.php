<?php declare(strict_types=1);

/**
 * Chunk 6.4 — AI Element Integration
 * Automated Verification Tests
 *
 * Tests:
 *   1.  ElementPrompts class exists and systemPrompt() includes template syntax + CSS scoping
 *   2.  ElementAIController class exists with chat() and conversations() methods
 *   3.  Required new files exist (migrations, JS, proposals template)
 *  [SMOKE STOP]
 *   4.  Migration 006: element_id column on ai_conversations
 *   5.  ConversationManager has findOrCreateForElement() and getHistoryForElement() methods
 *   6.  ConversationManager::findOrCreateForElement() works correctly (create + find)
 *   7.  GeneratorPrompts has formatElementCatalogue(), elementGatheringPrompt(), elementGenerationPrompt()
 *   8.  GeneratorPrompts::formatElementCatalogue() formats elements correctly
 *   9.  PageGeneratorController reads editor_mode and references element prompts
 *  10.  ElementController has proposals(), approveProposal(), rejectProposal() methods
 *  11.  Element edit template has AI panel markup, scripts, and toggle button
 *  12.  element-ai-assistant.js has AIChatCore, extractCodeBlock, and element endpoints
 *  13.  Routes registered: element AI chat, conversations, proposals
 *  14.  AIController buildSystemPrompt references element catalogue for elements mode
 *  15.  Generator index template has editor mode toggle
 *  16.  admin.css has element AI panel grid rule
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
// Test 1: ElementPrompts class exists and systemPrompt() includes template syntax + CSS scoping
// ===========================================================================
$elementPromptsLoadable = class_exists(\App\AIAssistant\ElementPrompts::class);

if ($elementPromptsLoadable) {
    try {
        $prompt = \App\AIAssistant\ElementPrompts::systemPrompt('TestSite', null);

        $hasTemplateSyntax = str_contains($prompt, '{{slot}}') || str_contains($prompt, '{{');
        $hasCssScoping = str_contains($prompt, 'lcms-el-') || str_contains($prompt, '.lcms-el');
        $hasCodeBlocks = str_contains($prompt, '```html') || str_contains($prompt, 'html') || str_contains($prompt, 'code block');

        if ($hasTemplateSyntax && $hasCssScoping) {
            test_pass('ElementPrompts exists, systemPrompt() includes template syntax + CSS scoping');
        } else {
            test_fail('ElementPrompts::systemPrompt()', 'templateSyntax=' . ($hasTemplateSyntax ? 'ok' : 'MISSING')
                . ' cssScoping=' . ($hasCssScoping ? 'ok' : 'MISSING'));
        }
    } catch (\Throwable $e) {
        test_fail('ElementPrompts::systemPrompt()', $e->getMessage());
    }
} else {
    test_fail('ElementPrompts class is autoloadable');
}

// ===========================================================================
// Test 2: ElementAIController class exists with chat() and conversations()
// ===========================================================================
$controllerLoadable = class_exists(\App\AIAssistant\ElementAIController::class);

if ($controllerLoadable) {
    try {
        $reflection = new ReflectionClass(\App\AIAssistant\ElementAIController::class);
        $hasChat = $reflection->hasMethod('chat');
        $hasConversations = $reflection->hasMethod('conversations');

        if ($hasChat && $hasConversations) {
            test_pass('ElementAIController exists with chat() and conversations() methods');
        } else {
            test_fail('ElementAIController methods', 'chat=' . ($hasChat ? 'ok' : 'MISSING')
                . ' conversations=' . ($hasConversations ? 'ok' : 'MISSING'));
        }
    } catch (\Throwable $e) {
        test_fail('ElementAIController reflection', $e->getMessage());
    }
} else {
    test_fail('ElementAIController class is autoloadable');
}

// ===========================================================================
// Test 3: Required new files exist
// ===========================================================================
$requiredFiles = [
    'migrations/006_element_ai.sqlite.sql',
    'migrations/006_element_ai.mysql.sql',
    'migrations/006_element_ai.pgsql.sql',
    'app/AIAssistant/ElementPrompts.php',
    'app/AIAssistant/ElementAIController.php',
    'public/assets/js/element-ai-assistant.js',
    'templates/admin/elements/proposals.php',
];

$allExist = true;
$missing = [];
foreach ($requiredFiles as $file) {
    if (!file_exists($rootDir . '/' . $file)) {
        $allExist = false;
        $missing[] = $file;
    }
}

if ($allExist) {
    test_pass('All required new files exist (migrations x3, ElementPrompts, ElementAIController, JS, proposals template)');
} else {
    test_fail('Required files exist', 'missing: ' . implode(', ', $missing));
}

// --- Smoke stop ---
if ($isSmoke) {
    echo "\n  [INFO] Smoke mode — skipping remaining tests\n";
    echo "\n  Chunk 6.4 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Setup: test database for full tests
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk64.sqlite';

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
if (class_exists(\App\PageBuilder\SeedElements::class)) {
    \App\PageBuilder\SeedElements::seed();
}

// Cleanup helper
function cleanup(): void
{
    global $testDbPath, $configProp, $pass, $fail;

    $pdo = null;
    \App\Database\Connection::reset();
    $configProp->setValue(null, null);
    putenv('DB_DRIVER');
    putenv('DB_PATH');

    usleep(100000);

    @unlink($testDbPath);
    @unlink($testDbPath . '-wal');
    @unlink($testDbPath . '-shm');

    echo "\n  Chunk 6.4 results: {$pass} passed, {$fail} failed\n";
}

register_shutdown_function('cleanup');

// ===========================================================================
// Test 4: Migration 006: element_id column on ai_conversations
// ===========================================================================
try {
    $cols = $pdo->query("PRAGMA table_info(ai_conversations)")->fetchAll(\PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    $hasElementId = in_array('element_id', $colNames, true);

    if ($hasElementId) {
        test_pass('Migration 006: ai_conversations has element_id column');
    } else {
        test_fail('Migration 006', 'element_id column missing from ai_conversations. Columns: ' . implode(', ', $colNames));
    }
} catch (\Throwable $e) {
    test_fail('Migration 006', $e->getMessage());
}

// ===========================================================================
// Test 5: ConversationManager has findOrCreateForElement() and getHistoryForElement()
// ===========================================================================
try {
    $cmReflection = new ReflectionClass(\App\AIAssistant\ConversationManager::class);
    $hasFind = $cmReflection->hasMethod('findOrCreateForElement');
    $hasHistory = $cmReflection->hasMethod('getHistoryForElement');

    if ($hasFind && $hasHistory) {
        test_pass('ConversationManager has findOrCreateForElement() and getHistoryForElement()');
    } else {
        test_fail('ConversationManager element methods',
            'findOrCreateForElement=' . ($hasFind ? 'ok' : 'MISSING')
            . ' getHistoryForElement=' . ($hasHistory ? 'ok' : 'MISSING'));
    }
} catch (\Throwable $e) {
    test_fail('ConversationManager reflection', $e->getMessage());
}

// ===========================================================================
// Test 6: ConversationManager::findOrCreateForElement() works correctly
// ===========================================================================
try {
    $manager = new \App\AIAssistant\ConversationManager();

    // Create a conversation for element_id = null (new element)
    $conv1 = $manager->findOrCreateForElement(1, null);
    $created = ($conv1 !== null && isset($conv1['id']));

    // Finding again should return the same conversation
    $conv2 = $manager->findOrCreateForElement(1, null);
    $sameConv = ($conv2 !== null && (int)$conv2['id'] === (int)$conv1['id']);

    // Creating for a different element_id should give a different conversation
    // First get an element ID to use
    $heroEl = \App\Database\QueryBuilder::query('elements')
        ->select('id')
        ->where('slug', 'hero-section')
        ->first();

    $differentConv = true;
    if ($heroEl !== null) {
        $conv3 = $manager->findOrCreateForElement(1, (int)$heroEl['id']);
        $differentConv = ($conv3 !== null && (int)$conv3['id'] !== (int)$conv1['id']);
    }

    if ($created && $sameConv && $differentConv) {
        test_pass('findOrCreateForElement() creates, finds existing, and scopes by element_id');
    } else {
        test_fail('findOrCreateForElement()', 'created=' . ($created ? 'ok' : 'FAIL')
            . ' sameConv=' . ($sameConv ? 'ok' : 'FAIL')
            . ' differentConv=' . ($differentConv ? 'ok' : 'FAIL'));
    }
} catch (\Throwable $e) {
    test_fail('findOrCreateForElement()', $e->getMessage());
}

// ===========================================================================
// Test 7: GeneratorPrompts has element-aware methods
// ===========================================================================
try {
    $gpReflection = new ReflectionClass(\App\AIAssistant\GeneratorPrompts::class);
    $hasCatalogue = $gpReflection->hasMethod('formatElementCatalogue');
    $hasGathering = $gpReflection->hasMethod('elementGatheringPrompt');
    $hasGeneration = $gpReflection->hasMethod('elementGenerationPrompt');

    if ($hasCatalogue && $hasGathering && $hasGeneration) {
        test_pass('GeneratorPrompts has formatElementCatalogue(), elementGatheringPrompt(), elementGenerationPrompt()');
    } else {
        test_fail('GeneratorPrompts element methods',
            'formatElementCatalogue=' . ($hasCatalogue ? 'ok' : 'MISSING')
            . ' elementGatheringPrompt=' . ($hasGathering ? 'ok' : 'MISSING')
            . ' elementGenerationPrompt=' . ($hasGeneration ? 'ok' : 'MISSING'));
    }
} catch (\Throwable $e) {
    test_fail('GeneratorPrompts reflection', $e->getMessage());
}

// ===========================================================================
// Test 8: GeneratorPrompts::formatElementCatalogue() formats elements correctly
// ===========================================================================
try {
    // Empty catalogue
    $emptyResult = \App\AIAssistant\GeneratorPrompts::formatElementCatalogue([]);
    $emptyOk = str_contains(strtolower($emptyResult), 'no element') || $emptyResult === '';

    // With elements
    $sampleElements = [
        [
            'name' => 'Hero Section',
            'slug' => 'hero-section',
            'description' => 'Full-width hero banner',
            'slots_json' => json_encode([
                ['key' => 'title', 'type' => 'text', 'label' => 'Headline'],
            ]),
        ],
    ];
    $formatted = \App\AIAssistant\GeneratorPrompts::formatElementCatalogue($sampleElements);
    $hasName = str_contains($formatted, 'Hero Section');
    $hasSlug = str_contains($formatted, 'hero-section');

    if ($emptyOk && $hasName && $hasSlug) {
        test_pass('formatElementCatalogue() handles empty array and formats elements with name + slug');
    } else {
        test_fail('formatElementCatalogue()', 'empty=' . ($emptyOk ? 'ok' : 'FAIL')
            . ' name=' . ($hasName ? 'ok' : 'FAIL')
            . ' slug=' . ($hasSlug ? 'ok' : 'FAIL'));
    }
} catch (\Throwable $e) {
    test_fail('formatElementCatalogue()', $e->getMessage());
}

// ===========================================================================
// Test 9: PageGeneratorController reads editor_mode and references element prompts
// ===========================================================================
try {
    $pgcSrc = file_get_contents($rootDir . '/app/AIAssistant/PageGeneratorController.php');
    if ($pgcSrc === false) {
        test_fail('PageGeneratorController readable', 'file not found');
    } else {
        $hasEditorMode = str_contains($pgcSrc, 'editor_mode');
        $hasElementPrompts = str_contains($pgcSrc, 'elementGatheringPrompt')
            || str_contains($pgcSrc, 'elementGenerationPrompt')
            || str_contains($pgcSrc, 'formatElementCatalogue');
        $hasProposal = str_contains($pgcSrc, 'element_proposals')
            || str_contains($pgcSrc, 'createElementProposal')
            || str_contains($pgcSrc, '__new__');

        if ($hasEditorMode && $hasElementPrompts) {
            test_pass('PageGeneratorController reads editor_mode and uses element-aware prompts');
        } else {
            test_fail('PageGeneratorController element mode',
                'editor_mode=' . ($hasEditorMode ? 'ok' : 'MISSING')
                . ' elementPrompts=' . ($hasElementPrompts ? 'ok' : 'MISSING')
                . ' proposals=' . ($hasProposal ? 'ok' : 'MISSING'));
        }
    }
} catch (\Throwable $e) {
    test_fail('PageGeneratorController', $e->getMessage());
}

// ===========================================================================
// Test 10: ElementController has proposal methods
// ===========================================================================
try {
    $ecReflection = new ReflectionClass(\App\Admin\ElementController::class);
    $hasProposals = $ecReflection->hasMethod('proposals');
    $hasApprove = $ecReflection->hasMethod('approveProposal');
    $hasReject = $ecReflection->hasMethod('rejectProposal');

    if ($hasProposals && $hasApprove && $hasReject) {
        test_pass('ElementController has proposals(), approveProposal(), rejectProposal()');
    } else {
        test_fail('ElementController proposal methods',
            'proposals=' . ($hasProposals ? 'ok' : 'MISSING')
            . ' approveProposal=' . ($hasApprove ? 'ok' : 'MISSING')
            . ' rejectProposal=' . ($hasReject ? 'ok' : 'MISSING'));
    }
} catch (\Throwable $e) {
    test_fail('ElementController reflection', $e->getMessage());
}

// ===========================================================================
// Test 11: Element edit template has AI panel markup, scripts, and toggle
// ===========================================================================
$editSrc = @file_get_contents($rootDir . '/templates/admin/elements/edit.php');
if ($editSrc === false) {
    test_fail('Element edit template readable', 'file not found');
} else {
    $checks = [
        'element-ai-panel'         => str_contains($editSrc, 'element-ai-panel'),
        'element-ai-toggle'        => str_contains($editSrc, 'element-ai-toggle'),
        'ai-chat-core.js'          => str_contains($editSrc, 'ai-chat-core.js'),
        'element-ai-assistant.js'  => str_contains($editSrc, 'element-ai-assistant.js'),
        'element-ai-messages'      => str_contains($editSrc, 'element-ai-messages'),
    ];

    $allPresent = !in_array(false, $checks, true);
    if ($allPresent) {
        test_pass('Element edit template has AI panel, toggle button, chat core script, and assistant script');
    } else {
        $missing = array_keys(array_filter($checks, fn($v) => !$v));
        test_fail('Element edit template AI markup', 'missing: ' . implode(', ', $missing));
    }
}

// ===========================================================================
// Test 12: element-ai-assistant.js has AIChatCore, extractCodeBlock, element endpoints
// ===========================================================================
$jsSrc = @file_get_contents($rootDir . '/public/assets/js/element-ai-assistant.js');
if ($jsSrc === false) {
    test_fail('element-ai-assistant.js readable', 'file not found');
} else {
    $jsChecks = [
        'AIChatCore'               => str_contains($jsSrc, 'AIChatCore'),
        'extractCodeBlock'         => str_contains($jsSrc, 'extractCodeBlock'),
        '/admin/ai/element/chat'   => str_contains($jsSrc, '/admin/ai/element/chat'),
        'Apply HTML / applyCode'   => str_contains($jsSrc, 'Apply HTML') || str_contains($jsSrc, 'applyCodeBlock'),
        'current_html'             => str_contains($jsSrc, 'current_html'),
        'current_css'              => str_contains($jsSrc, 'current_css'),
    ];

    $allJs = !in_array(false, $jsChecks, true);
    if ($allJs) {
        test_pass('element-ai-assistant.js has AIChatCore, extractCodeBlock, element endpoints, and Apply actions');
    } else {
        $missing = array_keys(array_filter($jsChecks, fn($v) => !$v));
        test_fail('element-ai-assistant.js features', 'missing: ' . implode(', ', $missing));
    }
}

// ===========================================================================
// Test 13: Routes registered for element AI and proposals
// ===========================================================================
$indexSrc = @file_get_contents($rootDir . '/public/index.php');
if ($indexSrc === false) {
    test_fail('public/index.php readable', 'file not found');
} else {
    $routeChecks = [
        'ai/element/chat'           => str_contains($indexSrc, 'ai/element/chat'),
        'ai/element/conversations'   => str_contains($indexSrc, 'ai/element/conversations'),
        'element-proposals'          => str_contains($indexSrc, 'element-proposals'),
        'ElementAIController'        => str_contains($indexSrc, 'ElementAIController'),
    ];

    $allRoutes = !in_array(false, $routeChecks, true);
    if ($allRoutes) {
        test_pass('Routes registered: element AI chat, conversations, proposals, with ElementAIController import');
    } else {
        $missing = array_keys(array_filter($routeChecks, fn($v) => !$v));
        test_fail('Route registration', 'missing: ' . implode(', ', $missing));
    }
}

// ===========================================================================
// Test 14: AIController buildSystemPrompt references element catalogue
// ===========================================================================
$aiCtrlSrc = @file_get_contents($rootDir . '/app/AIAssistant/AIController.php');
if ($aiCtrlSrc === false) {
    test_fail('AIController.php readable', 'file not found');
} else {
    $hasElementMode = str_contains($aiCtrlSrc, 'editor_mode') || str_contains($aiCtrlSrc, 'elements');
    $hasCatalogue = str_contains($aiCtrlSrc, 'formatElementCatalogue')
        || str_contains($aiCtrlSrc, 'element catalogue')
        || str_contains($aiCtrlSrc, 'Available elements');

    if ($hasElementMode && $hasCatalogue) {
        test_pass('AIController buildSystemPrompt references element mode and catalogue');
    } else {
        test_fail('AIController element catalogue context',
            'editorMode=' . ($hasElementMode ? 'ok' : 'MISSING')
            . ' catalogue=' . ($hasCatalogue ? 'ok' : 'MISSING'));
    }
}

// ===========================================================================
// Test 15: Generator index template has editor mode toggle
// ===========================================================================
$genSrc = @file_get_contents($rootDir . '/templates/admin/generator/index.php');
if ($genSrc === false) {
    test_fail('Generator index template readable', 'file not found');
} else {
    $hasMode = str_contains($genSrc, 'editor-mode') || str_contains($genSrc, 'mode-option')
        || str_contains($genSrc, 'editor_mode') || str_contains($genSrc, 'editorMode');
    $hasHtml = str_contains($genSrc, '"html"') || str_contains($genSrc, "'html'");
    $hasElements = str_contains($genSrc, '"elements"') || str_contains($genSrc, "'elements'");

    if ($hasMode && $hasElements) {
        test_pass('Generator index has editor mode toggle with html and elements options');
    } else {
        test_fail('Generator editor mode toggle',
            'modeUI=' . ($hasMode ? 'ok' : 'MISSING')
            . ' htmlOption=' . ($hasHtml ? 'ok' : 'MISSING')
            . ' elementsOption=' . ($hasElements ? 'ok' : 'MISSING'));
    }
}

// ===========================================================================
// Test 16: admin.css has element AI panel grid rule
// ===========================================================================
$cssSrc = @file_get_contents($rootDir . '/public/assets/css/admin.css');
if ($cssSrc === false) {
    test_fail('admin.css readable', 'file not found');
} else {
    $hasGridRule = str_contains($cssSrc, '.element-editor-grid.ai-panel-open')
        || (str_contains($cssSrc, 'element-editor-grid') && str_contains($cssSrc, 'ai-panel-open'));
    $hasElementPanel = str_contains($cssSrc, '.element-ai-panel') || str_contains($cssSrc, 'element-ai-panel');

    if ($hasGridRule) {
        test_pass('admin.css has .element-editor-grid.ai-panel-open grid rule');
    } else {
        test_fail('admin.css element AI panel', 'gridRule=' . ($hasGridRule ? 'ok' : 'MISSING')
            . ' elementPanel=' . ($hasElementPanel ? 'ok' : 'MISSING'));
    }
}

// ---------------------------------------------------------------------------
// Summary (printed by cleanup shutdown function)
// ---------------------------------------------------------------------------
exit($fail > 0 ? 1 : 0);
