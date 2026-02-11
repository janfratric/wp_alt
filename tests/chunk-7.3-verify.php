<?php declare(strict_types=1);

/**
 * Chunk 7.3 — LiteCMS Design System as .pen File
 * Automated Verification Tests
 *
 * Tests:
 *   1.  Design system file exists
 *   2.  File is valid JSON
 *   3.  Document has correct structure (children + variables)
 *   4.  Has exactly 8 reusable components
 *   5.  All component IDs present
 *   6.  Components have children (slot nodes)
 *   7.  Components use $-- variable references
 *   8.  Variables include color tokens
 *   9.  Variables include typography tokens
 *  10.  Variables include spacing tokens
 *  11.  Themed variables have light/dark values
 *  12.  PenConverter builds component registry from design system
 *  13.  PenConverter converts page with hero instance
 *  14.  PenConverter converts page with multiple component instances
 *  15.  Descendant overrides customize component content
 *  16.  Variable CSS output has :root block
 *  17.  Variable CSS output has dark theme block
 *  18.  HTML output uses semantic tags
 *  19.  README file exists
 *  20.  Full pipeline integration test
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

use App\PageBuilder\PenConverter;

$designFile = $rootDir . '/designs/litecms-system.pen';

// Expected component IDs
$expectedIds = [
    'hero-section',
    'text-section',
    'feature-grid',
    'cta-banner',
    'image-text',
    'testimonial-section',
    'faq-section',
    'footer-section',
];

$doc = null; // loaded after test 2

// ---------------------------------------------------------------------------
// Test 1: Design system file exists
// ---------------------------------------------------------------------------
if (file_exists($designFile)) {
    test_pass('Test 1: Design system file exists');
} else {
    test_fail('Test 1: Design system file exists', 'designs/litecms-system.pen not found');
    echo "\n[FAIL] Cannot continue — design system file missing\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Test 2: File is valid JSON
// ---------------------------------------------------------------------------
try {
    $json = file_get_contents($designFile);
    $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    test_pass('Test 2: File is valid JSON');
} catch (\JsonException $e) {
    test_fail('Test 2: File is valid JSON', $e->getMessage());
    echo "\n[FAIL] Cannot continue — invalid JSON\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Test 3: Document has correct structure
// ---------------------------------------------------------------------------
if (isset($doc['children']) && is_array($doc['children']) &&
    isset($doc['variables']) && is_array($doc['variables'])) {
    test_pass('Test 3: Document has correct structure (children + variables)');
} else {
    $missing = [];
    if (!isset($doc['children'])) $missing[] = 'children';
    if (!isset($doc['variables'])) $missing[] = 'variables';
    test_fail('Test 3: Document structure', 'missing: ' . implode(', ', $missing));
}

// ---------------------------------------------------------------------------
// Test 4: Has exactly 8 reusable components
// ---------------------------------------------------------------------------
$reusableCount = 0;
$foundComponents = [];
foreach ($doc['children'] as $child) {
    if (!empty($child['reusable'])) {
        $reusableCount++;
        $foundComponents[$child['id'] ?? ''] = $child;
    }
}
if ($reusableCount === 8) {
    test_pass('Test 4: Has exactly 8 reusable components');
} else {
    test_fail('Test 4: 8 reusable components', "found {$reusableCount}");
}

// ---------------------------------------------------------------------------
// Test 5: All component IDs present
// ---------------------------------------------------------------------------
$missingIds = [];
foreach ($expectedIds as $id) {
    if (!isset($foundComponents[$id])) {
        $missingIds[] = $id;
    }
}
if (empty($missingIds)) {
    test_pass('Test 5: All component IDs present');
} else {
    test_fail('Test 5: Component IDs', 'missing: ' . implode(', ', $missingIds));
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 7.3 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 6: Components have children (slot nodes)
// ---------------------------------------------------------------------------
$emptyComponents = [];
foreach ($foundComponents as $id => $comp) {
    if (empty($comp['children']) || !is_array($comp['children'])) {
        $emptyComponents[] = $id;
    }
}
if (empty($emptyComponents)) {
    test_pass('Test 6: Components have children (slot nodes)');
} else {
    test_fail('Test 6: Components with children', 'empty: ' . implode(', ', $emptyComponents));
}

// ---------------------------------------------------------------------------
// Test 7: Components use $-- variable references
// ---------------------------------------------------------------------------
function findVarRefs(array $node): bool {
    foreach ($node as $key => $value) {
        if (is_string($value) && str_starts_with($value, '$--')) {
            return true;
        }
        if (is_array($value) && findVarRefs($value)) {
            return true;
        }
    }
    return false;
}

$noVarComponents = [];
foreach ($foundComponents as $id => $comp) {
    if (!findVarRefs($comp)) {
        $noVarComponents[] = $id;
    }
}
if (empty($noVarComponents)) {
    test_pass('Test 7: Components use $-- variable references');
} else {
    test_fail('Test 7: Variable references', 'no refs in: ' . implode(', ', $noVarComponents));
}

// ---------------------------------------------------------------------------
// Test 8: Variables include color tokens
// ---------------------------------------------------------------------------
$colorTokens = ['primary', 'background', 'foreground', 'muted-foreground', 'card', 'border'];
$missingColors = [];
foreach ($colorTokens as $token) {
    if (!isset($doc['variables'][$token])) {
        $missingColors[] = $token;
    }
}
if (empty($missingColors)) {
    test_pass('Test 8: Variables include color tokens');
} else {
    test_fail('Test 8: Color tokens', 'missing: ' . implode(', ', $missingColors));
}

// ---------------------------------------------------------------------------
// Test 9: Variables include typography tokens
// ---------------------------------------------------------------------------
$typoTokens = ['font-primary', 'font-secondary'];
$missingTypo = [];
foreach ($typoTokens as $token) {
    if (!isset($doc['variables'][$token])) {
        $missingTypo[] = $token;
    }
}
if (empty($missingTypo)) {
    test_pass('Test 9: Variables include typography tokens');
} else {
    test_fail('Test 9: Typography tokens', 'missing: ' . implode(', ', $missingTypo));
}

// ---------------------------------------------------------------------------
// Test 10: Variables include spacing tokens
// ---------------------------------------------------------------------------
$spacingTokens = ['radius-m', 'radius-pill', 'spacing-section', 'max-width'];
$missingSpacing = [];
foreach ($spacingTokens as $token) {
    if (!isset($doc['variables'][$token])) {
        $missingSpacing[] = $token;
    }
}
if (empty($missingSpacing)) {
    test_pass('Test 10: Variables include spacing tokens');
} else {
    test_fail('Test 10: Spacing tokens', 'missing: ' . implode(', ', $missingSpacing));
}

// ---------------------------------------------------------------------------
// Test 11: Themed variables have light/dark values
// ---------------------------------------------------------------------------
try {
    $ok = true;
    $primary = $doc['variables']['primary'] ?? null;
    if ($primary === null || !is_array($primary['value'] ?? null)) {
        test_fail('Test 11: Themed variables', 'primary variable value is not an array');
        $ok = false;
    } else {
        $values = $primary['value'];
        $hasDefault = false;
        $hasDark = false;
        foreach ($values as $entry) {
            if (empty($entry['theme'] ?? [])) {
                $hasDefault = true;
            }
            if (($entry['theme']['mode'] ?? '') === 'dark') {
                $hasDark = true;
            }
        }
        if (!$hasDefault) {
            test_fail('Test 11: Themed variables', 'primary missing default (light) value');
            $ok = false;
        }
        if (!$hasDark) {
            test_fail('Test 11: Themed variables', 'primary missing dark theme value');
            $ok = false;
        }
    }
    if ($ok) {
        test_pass('Test 11: Themed variables have light/dark values');
    }
} catch (\Throwable $e) {
    test_fail('Test 11: Themed variables', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: PenConverter builds component registry from design system
// ---------------------------------------------------------------------------
try {
    // Convert the design system file — should succeed even with empty output
    $result = PenConverter::convertDocument($doc);

    // All 8 components should be registered internally.
    // We verify by creating instances that reference them.
    $ok = true;
    foreach ($expectedIds as $compId) {
        $testDoc = [
            'children' => array_merge(
                $doc['children'],
                [['id' => 'test-inst', 'type' => 'ref', 'ref' => $compId]]
            ),
            'variables' => $doc['variables'],
        ];
        $testResult = PenConverter::convertDocument($testDoc);
        if (empty($testResult['html']) || str_contains($testResult['html'], 'Component not found')) {
            test_fail("Test 12: Component registry — {$compId}", 'not found or not rendered');
            $ok = false;
        }
    }
    if ($ok) {
        test_pass('Test 12: PenConverter builds component registry from design system');
    }
} catch (\Throwable $e) {
    test_fail('Test 12: Component registry', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: PenConverter converts page with hero instance
// ---------------------------------------------------------------------------
try {
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [[
                'id' => 'test-page',
                'type' => 'frame',
                'name' => 'Test Page',
                'layout' => 'vertical',
                'width' => 1200,
                'children' => [
                    ['id' => 'hero-inst', 'type' => 'ref', 'ref' => 'hero-section'],
                ],
            ]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    if (str_contains($result['html'], 'Welcome to Our Site') &&
        str_contains($result['html'], 'Get Started')) {
        test_pass('Test 13: PenConverter converts page with hero instance');
    } else {
        test_fail('Test 13: Hero instance', 'expected hero text content in HTML output');
    }
} catch (\Throwable $e) {
    test_fail('Test 13: Hero instance', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: PenConverter converts page with multiple component instances
// ---------------------------------------------------------------------------
try {
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [[
                'id' => 'multi-page',
                'type' => 'frame',
                'name' => 'Multi Page',
                'layout' => 'vertical',
                'width' => 1200,
                'children' => [
                    ['id' => 'hero-i', 'type' => 'ref', 'ref' => 'hero-section'],
                    ['id' => 'text-i', 'type' => 'ref', 'ref' => 'text-section'],
                    ['id' => 'cta-i', 'type' => 'ref', 'ref' => 'cta-banner'],
                    ['id' => 'footer-i', 'type' => 'ref', 'ref' => 'footer-section'],
                ],
            ]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    $checks = [
        str_contains($result['html'], 'Welcome to Our Site'),
        str_contains($result['html'], 'Section Heading'),
        str_contains($result['html'], 'Ready to get started'),
        str_contains($result['html'], 'All rights reserved'),
    ];
    if (!in_array(false, $checks, true)) {
        test_pass('Test 14: PenConverter converts page with multiple component instances');
    } else {
        test_fail('Test 14: Multiple instances', 'not all component content found in output');
    }
} catch (\Throwable $e) {
    test_fail('Test 14: Multiple instances', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: Descendant overrides customize component content
// ---------------------------------------------------------------------------
try {
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [[
                'id' => 'override-page',
                'type' => 'frame',
                'name' => 'Override Page',
                'layout' => 'vertical',
                'width' => 1200,
                'children' => [
                    [
                        'id' => 'hero-custom',
                        'type' => 'ref',
                        'ref' => 'hero-section',
                        'descendants' => [
                            'hero-heading' => ['content' => 'Custom Hero Title'],
                            'hero-subheading' => ['content' => 'Custom subtitle here'],
                        ],
                    ],
                ],
            ]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    if (str_contains($result['html'], 'Custom Hero Title') &&
        str_contains($result['html'], 'Custom subtitle here') &&
        !str_contains($result['html'], 'Welcome to Our Site')) {
        test_pass('Test 15: Descendant overrides customize component content');
    } else {
        test_fail('Test 15: Descendant overrides', 'override text not found or default still present');
    }
} catch (\Throwable $e) {
    test_fail('Test 15: Descendant overrides', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: Variable CSS output has :root block
// ---------------------------------------------------------------------------
$cssResult = null;
try {
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [['id' => 'css-page', 'type' => 'frame', 'name' => 'CSS Page', 'layout' => 'vertical',
              'width' => 1200, 'children' => [
                  ['id' => 'css-hero', 'type' => 'ref', 'ref' => 'hero-section'],
              ]]]
        ),
        'variables' => $doc['variables'],
    ];
    $cssResult = PenConverter::convertDocument($testDoc);

    if (str_contains($cssResult['css'], ':root') &&
        str_contains($cssResult['css'], '--primary')) {
        test_pass('Test 16: Variable CSS output has :root block');
    } else {
        test_fail('Test 16: :root CSS', 'missing :root or --primary in CSS output');
    }
} catch (\Throwable $e) {
    test_fail('Test 16: :root CSS', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 17: Variable CSS output has dark theme block
// ---------------------------------------------------------------------------
try {
    if ($cssResult !== null && str_contains($cssResult['css'], '[data-theme-mode="dark"]')) {
        test_pass('Test 17: Variable CSS output has dark theme block');
    } else {
        test_fail('Test 17: Dark theme CSS', 'missing [data-theme-mode="dark"] in CSS');
    }
} catch (\Throwable $e) {
    test_fail('Test 17: Dark theme CSS', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: HTML output uses semantic tags
// ---------------------------------------------------------------------------
try {
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [[
                'id' => 'sem-page',
                'type' => 'frame',
                'name' => 'Semantic Page',
                'layout' => 'vertical',
                'width' => 1200,
                'children' => [
                    ['id' => 'sem-hero', 'type' => 'ref', 'ref' => 'hero-section'],
                    ['id' => 'sem-footer', 'type' => 'ref', 'ref' => 'footer-section'],
                ],
            ]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    $hasSection = str_contains($result['html'], '<section') || str_contains($result['html'], '<div');
    $hasFooter = str_contains($result['html'], '<footer');

    if ($hasSection && $hasFooter) {
        test_pass('Test 18: HTML output uses semantic tags');
    } else {
        $missing = [];
        if (!$hasSection) $missing[] = '<section> or <div> for hero';
        if (!$hasFooter) $missing[] = '<footer>';
        test_fail('Test 18: Semantic tags', 'missing: ' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    test_fail('Test 18: Semantic tags', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: README file exists
// ---------------------------------------------------------------------------
$readmeFile = $rootDir . '/designs/README.md';
if (file_exists($readmeFile) && filesize($readmeFile) > 50) {
    test_pass('Test 19: README file exists');
} else {
    test_fail('Test 19: README file', 'designs/README.md missing or too small');
}

// ---------------------------------------------------------------------------
// Test 20: Full pipeline integration test
// ---------------------------------------------------------------------------
try {
    // Build a complete page using all 8 components
    $allRefs = [];
    foreach ($expectedIds as $i => $compId) {
        $allRefs[] = ['id' => "full-{$i}", 'type' => 'ref', 'ref' => $compId];
    }
    $testDoc = [
        'children' => array_merge(
            $doc['children'],
            [[
                'id' => 'full-page',
                'type' => 'frame',
                'name' => 'Full Page',
                'layout' => 'vertical',
                'width' => 1200,
                'children' => $allRefs,
            ]]
        ),
        'variables' => $doc['variables'],
    ];
    $result = PenConverter::convertDocument($testDoc);

    $ok = true;

    // Check HTML is non-empty and has substantial content
    if (strlen($result['html']) < 200) {
        test_fail('Test 20: Integration — HTML length', 'HTML too short: ' . strlen($result['html']));
        $ok = false;
    }

    // Check CSS has variables, theme, and node styles
    if (!str_contains($result['css'], ':root') ||
        !str_contains($result['css'], '--primary') ||
        !str_contains($result['css'], '[data-theme-mode="dark"]')) {
        test_fail('Test 20: Integration — CSS variables/themes', 'missing expected CSS blocks');
        $ok = false;
    }

    // Check key content from different components
    $expectedContent = [
        'Welcome to Our Site',       // hero
        'Section Heading',            // text section
        'Our Features',               // feature grid
        'Ready to get started',       // CTA
        'About This Topic',           // image-text
        'What Our Customers Say',     // testimonials
        'Frequently Asked Questions', // FAQ
        'All rights reserved',        // footer
    ];
    foreach ($expectedContent as $text) {
        if (!str_contains($result['html'], $text)) {
            test_fail("Test 20: Integration — content: {$text}", 'not found in HTML');
            $ok = false;
        }
    }

    // Check CSS has component-specific classes
    if (!str_contains($result['css'], 'pen-full-page')) {
        test_fail('Test 20: Integration — CSS classes', 'page CSS class not found');
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 20: Full pipeline integration test');
    }
} catch (\Throwable $e) {
    test_fail('Test 20: Integration', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 7.3 results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
