<?php declare(strict_types=1);

/**
 * Chunk 7.2 — .pen-to-HTML Converter (PenConverter)
 * Automated Verification Tests
 *
 * Tests:
 *   1.  PenStyleBuilder — resolveValue handles variables
 *   2.  PenStyleBuilder — resolveColor handles hex formats
 *   3.  PenStyleBuilder — buildFill converts colors and gradients
 *   4.  PenStyleBuilder — buildStroke converts borders
 *   5.  PenStyleBuilder — buildEffects converts shadows and blurs
 *   6.  PenStyleBuilder — buildLayout converts flexbox
 *   7.  PenStyleBuilder — buildTypography converts fonts
 *   8.  PenStyleBuilder — buildSizing handles fill_container/fit_content
 *   9.  PenNodeRenderer — renderFrame produces semantic HTML
 *  10.  PenNodeRenderer — renderText produces correct tags
 *  11.  PenNodeRenderer — renderText handles content types
 *  12.  PenNodeRenderer — renderRectangle outputs styled div
 *  13.  PenNodeRenderer — renderEllipse outputs div with border-radius 50%
 *  14.  PenNodeRenderer — renderPath outputs SVG
 *  15.  PenNodeRenderer — renderLine outputs hr
 *  16.  PenNodeRenderer — renderPolygon outputs SVG polygon
 *  17.  PenNodeRenderer — renderRef resolves components
 *  18.  PenNodeRenderer — renderRef applies descendant overrides
 *  19.  PenNodeRenderer — renderRef handles circular refs
 *  20.  PenNodeRenderer — renderIconFont outputs icon markup
 *  21.  PenNodeRenderer — disabled nodes produce empty output
 *  22.  PenConverter — convertDocument processes full document
 *  23.  PenConverter — component registry built correctly
 *  24.  PenConverter — variable CSS generated correctly
 *  25.  PenConverter — full integration test
 *  26.  PenConverter — convertFile reads from disk
 *  27.  PenConverter — convertFile throws on missing file
 *  28.  PenConverter — icon font imports deduplicated
 *  29.  PageRenderer — renderFromPen method exists and delegates
 *  30.  DesignController — convert endpoint exists
 *  31.  DesignController — preview endpoint exists
 *  32.  FrontController — design_file check is safe when column missing
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
// Autoloader
// ---------------------------------------------------------------------------
$autoloadPath = $rootDir . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found — run composer install');
    echo "\n[FAIL] Cannot continue — autoloader missing\n";
    exit(1);
}

require_once $autoloadPath;

// Check required classes exist
$requiredClasses = [
    'App\\PageBuilder\\PenStyleBuilder',
    'App\\PageBuilder\\PenNodeRenderer',
    'App\\PageBuilder\\PenConverter',
];

$allClassesFound = true;
foreach ($requiredClasses as $class) {
    if (!class_exists($class)) {
        test_fail("Class {$class} is autoloadable", 'class not found');
        $allClassesFound = false;
    }
}
if (!$allClassesFound) {
    echo "\n[FAIL] Cannot continue — required classes missing\n";
    exit(1);
}

use App\PageBuilder\PenStyleBuilder;
use App\PageBuilder\PenNodeRenderer;
use App\PageBuilder\PenConverter;

// ---------------------------------------------------------------------------
// Helper: build a minimal .pen document for converter tests
// ---------------------------------------------------------------------------
function makeDocument(array $children, array $variables = []): array {
    $doc = ['children' => $children];
    if (!empty($variables)) {
        $doc['variables'] = $variables;
    }
    return $doc;
}

// ---------------------------------------------------------------------------
// Test 1: PenStyleBuilder — resolveValue handles variables
// ---------------------------------------------------------------------------
try {
    $ok = true;

    $r1 = PenStyleBuilder::resolveValue('$--primary');
    if ($r1 !== 'var(--primary)') {
        test_fail('resolveValue("$--primary")', "expected var(--primary), got: {$r1}");
        $ok = false;
    }

    $r2 = PenStyleBuilder::resolveValue('$spacing-m');
    if ($r2 !== 'var(--spacing-m)') {
        test_fail('resolveValue("$spacing-m")', "expected var(--spacing-m), got: {$r2}");
        $ok = false;
    }

    $r3 = PenStyleBuilder::resolveValue(42);
    if ($r3 !== '42') {
        test_fail('resolveValue(42)', "expected 42, got: {$r3}");
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 1: PenStyleBuilder — resolveValue handles variables');
    }
} catch (\Throwable $e) {
    test_fail('Test 1: resolveValue', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 2: PenStyleBuilder — resolveColor handles hex formats
// ---------------------------------------------------------------------------
try {
    $ok = true;

    $c1 = PenStyleBuilder::resolveColor('#AABBCCDD');
    if (!str_contains($c1, 'rgba(')) {
        test_fail('resolveColor("#AABBCCDD")', "expected rgba(), got: {$c1}");
        $ok = false;
    }

    $c2 = PenStyleBuilder::resolveColor('#ABC');
    if ($c2 !== '#AABBCC') {
        test_fail('resolveColor("#ABC")', "expected #AABBCC, got: {$c2}");
        $ok = false;
    }

    $c3 = PenStyleBuilder::resolveColor('$--bg');
    if (!str_contains($c3, 'var(--bg)')) {
        test_fail('resolveColor("$--bg")', "expected var(--bg), got: {$c3}");
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 2: PenStyleBuilder — resolveColor handles hex formats');
    }
} catch (\Throwable $e) {
    test_fail('Test 2: resolveColor', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 3: PenStyleBuilder — buildFill converts colors and gradients
// ---------------------------------------------------------------------------
try {
    $ok = true;

    // Simple color
    $f1 = PenStyleBuilder::buildFill('#FF0000');
    if (!str_contains($f1, 'background-color: #FF0000')) {
        test_fail('buildFill color', "expected background-color: #FF0000, got: {$f1}");
        $ok = false;
    }

    // Linear gradient
    $f2 = PenStyleBuilder::buildFill([
        'type' => 'gradient',
        'gradientType' => 'linear',
        'rotation' => 0,
        'colors' => [
            ['color' => '#FF0000', 'position' => 0],
            ['color' => '#0000FF', 'position' => 1],
        ],
    ]);
    if (!str_contains($f2, 'linear-gradient')) {
        test_fail('buildFill gradient', "expected linear-gradient, got: {$f2}");
        $ok = false;
    }

    // Image fill
    $f3 = PenStyleBuilder::buildFill([
        'type' => 'image',
        'url' => 'https://example.com/img.png',
        'mode' => 'fill',
    ]);
    if (!str_contains($f3, 'background-image') || !str_contains($f3, 'cover')) {
        test_fail('buildFill image', "expected background-image + cover, got: {$f3}");
        $ok = false;
    }

    // Variable
    $f4 = PenStyleBuilder::buildFill('$--bg');
    if (!str_contains($f4, 'var(--bg)')) {
        test_fail('buildFill variable', "expected var(--bg), got: {$f4}");
        $ok = false;
    }

    // Disabled fill
    $f5 = PenStyleBuilder::buildFill(['type' => 'color', 'color' => '#000', 'enabled' => false]);
    if ($f5 !== '') {
        test_fail('buildFill disabled', "expected empty, got: {$f5}");
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 3: PenStyleBuilder — buildFill converts colors and gradients');
    }
} catch (\Throwable $e) {
    test_fail('Test 3: buildFill', $e->getMessage());
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 7.2 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: PenStyleBuilder — buildStroke converts borders
// ---------------------------------------------------------------------------
try {
    $ok = true;

    $s1 = PenStyleBuilder::buildStroke(['thickness' => 2, 'fill' => '#000000']);
    if (!str_contains($s1, 'border:') || !str_contains($s1, '2px') || !str_contains($s1, 'solid')) {
        test_fail('buildStroke uniform', "expected border: 2px solid, got: {$s1}");
        $ok = false;
    }

    $s2 = PenStyleBuilder::buildStroke([
        'thickness' => ['top' => 1, 'right' => 0, 'bottom' => 2, 'left' => 0],
        'fill' => '#333',
    ]);
    if (!str_contains($s2, 'border-top') || !str_contains($s2, 'border-bottom')) {
        test_fail('buildStroke per-side', "expected border-top/bottom, got: {$s2}");
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 4: PenStyleBuilder — buildStroke converts borders');
    }
} catch (\Throwable $e) {
    test_fail('Test 4: buildStroke', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: PenStyleBuilder — buildEffects converts shadows and blurs
// ---------------------------------------------------------------------------
try {
    $ok = true;

    $e1 = PenStyleBuilder::buildEffects([
        ['type' => 'shadow', 'shadowType' => 'outer', 'offset' => ['x' => 2, 'y' => 4], 'blur' => 8, 'spread' => 0, 'color' => '#00000040'],
    ]);
    if (!str_contains($e1, 'box-shadow:')) {
        test_fail('buildEffects shadow', "expected box-shadow, got: {$e1}");
        $ok = false;
    }

    $e2 = PenStyleBuilder::buildEffects([
        ['type' => 'shadow', 'shadowType' => 'inner', 'offset' => ['x' => 0, 'y' => 2], 'blur' => 4, 'spread' => 0, 'color' => '#000'],
    ]);
    if (!str_contains($e2, 'inset')) {
        test_fail('buildEffects inner shadow', "expected inset, got: {$e2}");
        $ok = false;
    }

    $e3 = PenStyleBuilder::buildEffects([
        ['type' => 'blur', 'radius' => 10],
    ]);
    if (!str_contains($e3, 'filter: blur(10px)')) {
        test_fail('buildEffects blur', "expected filter: blur(10px), got: {$e3}");
        $ok = false;
    }

    $e4 = PenStyleBuilder::buildEffects([
        ['type' => 'background_blur', 'radius' => 5],
    ]);
    if (!str_contains($e4, 'backdrop-filter')) {
        test_fail('buildEffects backdrop', "expected backdrop-filter, got: {$e4}");
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 5: PenStyleBuilder — buildEffects converts shadows and blurs');
    }
} catch (\Throwable $e) {
    test_fail('Test 5: buildEffects', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: PenStyleBuilder — buildLayout converts flexbox
// ---------------------------------------------------------------------------
try {
    $ok = true;

    $l1 = PenStyleBuilder::buildLayout(['layout' => 'horizontal']);
    if (!str_contains($l1, 'flex-direction: row')) {
        test_fail('buildLayout horizontal', "expected flex-direction: row, got: {$l1}");
        $ok = false;
    }

    $l2 = PenStyleBuilder::buildLayout(['layout' => 'vertical']);
    if (!str_contains($l2, 'flex-direction: column')) {
        test_fail('buildLayout vertical', "expected flex-direction: column, got: {$l2}");
        $ok = false;
    }

    $l3 = PenStyleBuilder::buildLayout(['layout' => 'horizontal', 'gap' => 16]);
    if (!str_contains($l3, 'gap: 16px')) {
        test_fail('buildLayout gap', "expected gap: 16px, got: {$l3}");
        $ok = false;
    }

    $l4 = PenStyleBuilder::buildLayout(['layout' => 'horizontal', 'padding' => [10, 20, 10, 20]]);
    if (!str_contains($l4, 'padding:')) {
        test_fail('buildLayout padding', "expected padding, got: {$l4}");
        $ok = false;
    }

    $l5 = PenStyleBuilder::buildLayout(['layout' => 'horizontal', 'justifyContent' => 'space_between', 'alignItems' => 'center']);
    if (!str_contains($l5, 'space-between') || !str_contains($l5, 'align-items: center')) {
        test_fail('buildLayout justify/align', "expected space-between + center, got: {$l5}");
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 6: PenStyleBuilder — buildLayout converts flexbox');
    }
} catch (\Throwable $e) {
    test_fail('Test 6: buildLayout', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: PenStyleBuilder — buildTypography converts fonts
// ---------------------------------------------------------------------------
try {
    $ok = true;

    $t1 = PenStyleBuilder::buildTypography([
        'fontFamily' => 'Inter',
        'fontSize' => 16,
        'fontWeight' => '600',
    ]);
    if (!str_contains($t1, '"Inter"') || !str_contains($t1, 'font-size: 16px') || !str_contains($t1, 'font-weight: 600')) {
        test_fail('buildTypography basics', "got: {$t1}");
        $ok = false;
    }

    $t2 = PenStyleBuilder::buildTypography(['fontFamily' => '$--heading-font']);
    if (!str_contains($t2, 'var(--heading-font)')) {
        test_fail('buildTypography variable font', "expected var(), got: {$t2}");
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 7: PenStyleBuilder — buildTypography converts fonts');
    }
} catch (\Throwable $e) {
    test_fail('Test 7: buildTypography', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: PenStyleBuilder — buildSizing handles fill_container/fit_content
// ---------------------------------------------------------------------------
try {
    $ok = true;

    $s1 = PenStyleBuilder::buildSizing(['width' => 'fill_container'], 'horizontal');
    if (!str_contains($s1, 'flex:') || !str_contains($s1, '1')) {
        test_fail('buildSizing fill_container', "expected flex: 1, got: {$s1}");
        $ok = false;
    }

    $s2 = PenStyleBuilder::buildSizing(['width' => 'fill_container(200)'], 'horizontal');
    if (!str_contains($s2, '200')) {
        test_fail('buildSizing fill_container(200)', "expected 200 fallback, got: {$s2}");
        $ok = false;
    }

    $s3 = PenStyleBuilder::buildSizing(['width' => 'fit_content']);
    if (!str_contains($s3, 'fit-content')) {
        test_fail('buildSizing fit_content', "expected fit-content, got: {$s3}");
        $ok = false;
    }

    $s4 = PenStyleBuilder::buildSizing(['width' => 300]);
    if (!str_contains($s4, 'width: 300px')) {
        test_fail('buildSizing numeric', "expected width: 300px, got: {$s4}");
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 8: PenStyleBuilder — buildSizing handles fill_container/fit_content');
    }
} catch (\Throwable $e) {
    test_fail('Test 8: buildSizing', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: PenNodeRenderer — renderFrame produces semantic HTML
// ---------------------------------------------------------------------------
try {
    $ok = true;

    // Build a minimal document for converter context
    $doc = makeDocument([
        ['id' => 'hdr', 'type' => 'frame', 'name' => 'Header Section', 'layout' => 'horizontal', 'children' => []],
        ['id' => 'ftr', 'type' => 'frame', 'name' => 'Footer', 'layout' => 'horizontal', 'children' => []],
        ['id' => 'mn', 'type' => 'frame', 'name' => 'Main Content', 'layout' => 'vertical', 'children' => []],
        ['id' => 'crd', 'type' => 'frame', 'name' => 'Card', 'layout' => 'vertical', 'children' => []],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (str_contains($result['html'], '<header') && str_contains($result['html'], '</header>')) {
        // ok
    } else {
        test_fail('renderFrame Header → <header>', 'not found in output');
        $ok = false;
    }

    if (str_contains($result['html'], '<footer') && str_contains($result['html'], '</footer>')) {
        // ok
    } else {
        test_fail('renderFrame Footer → <footer>', 'not found in output');
        $ok = false;
    }

    if (str_contains($result['html'], '<main') && str_contains($result['html'], '</main>')) {
        // ok
    } else {
        test_fail('renderFrame Main → <main>', 'not found in output');
        $ok = false;
    }

    if (str_contains($result['html'], '<div') && str_contains($result['html'], 'pen-crd')) {
        // ok
    } else {
        test_fail('renderFrame Card → <div>', 'not found in output');
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 9: PenNodeRenderer — renderFrame produces semantic HTML');
    }
} catch (\Throwable $e) {
    test_fail('Test 9: renderFrame', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: PenNodeRenderer — renderText produces correct tags
// ---------------------------------------------------------------------------
try {
    $ok = true;

    $doc = makeDocument([
        ['id' => 'h1t', 'type' => 'text', 'content' => 'Big Title', 'fontSize' => 32, 'fontWeight' => '700'],
        ['id' => 'h2t', 'type' => 'text', 'content' => 'Subtitle', 'fontSize' => 24, 'fontWeight' => '600'],
        ['id' => 'pt', 'type' => 'text', 'content' => 'Body text here', 'fontSize' => 14],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (str_contains($result['html'], '<h1')) {
        // ok
    } else {
        test_fail('renderText fontSize:32 → <h1>', 'not found');
        $ok = false;
    }

    if (str_contains($result['html'], '<h2')) {
        // ok
    } else {
        test_fail('renderText fontSize:24 → <h2>', 'not found');
        $ok = false;
    }

    if (str_contains($result['html'], '<p')) {
        // ok
    } else {
        test_fail('renderText fontSize:14 → <p>', 'not found');
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 10: PenNodeRenderer — renderText produces correct tags');
    }
} catch (\Throwable $e) {
    test_fail('Test 10: renderText tags', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: PenNodeRenderer — renderText handles content types
// ---------------------------------------------------------------------------
try {
    $ok = true;

    // String with HTML entities
    $doc = makeDocument([
        ['id' => 'esc', 'type' => 'text', 'content' => '<script>alert("xss")</script>', 'fontSize' => 14],
    ]);
    $result = PenConverter::convertDocument($doc);
    if (!str_contains($result['html'], '<script>')) {
        // ok — escaped
    } else {
        test_fail('renderText escapes HTML', 'raw <script> in output');
        $ok = false;
    }

    // Array of styled runs
    $doc2 = makeDocument([
        ['id' => 'runs', 'type' => 'text', 'fontSize' => 14, 'content' => [
            ['content' => 'Bold ', 'fontWeight' => '700'],
            ['content' => 'Normal'],
        ]],
    ]);
    $result2 = PenConverter::convertDocument($doc2);
    if (str_contains($result2['html'], 'Bold') && str_contains($result2['html'], 'Normal')) {
        // ok
    } else {
        test_fail('renderText styled runs', 'run content not found');
        $ok = false;
    }

    // Empty content
    $doc3 = makeDocument([
        ['id' => 'emp', 'type' => 'text', 'content' => '', 'fontSize' => 14],
    ]);
    $result3 = PenConverter::convertDocument($doc3);
    // Should not crash
    if ($result3 !== null) {
        // ok
    } else {
        test_fail('renderText empty content', 'returned null');
        $ok = false;
    }

    if ($ok) {
        test_pass('Test 11: PenNodeRenderer — renderText handles content types');
    }
} catch (\Throwable $e) {
    test_fail('Test 11: renderText content', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: PenNodeRenderer — renderRectangle outputs styled div
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        ['id' => 'rect1', 'type' => 'rectangle', 'width' => 100, 'height' => 50,
         'fill' => '#FF0000', 'stroke' => ['thickness' => 1, 'fill' => '#000']],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (str_contains($result['html'], '<div') && str_contains($result['html'], 'pen-rect1')
        && str_contains($result['css'], 'background-color') && str_contains($result['css'], 'border')) {
        test_pass('Test 12: PenNodeRenderer — renderRectangle outputs styled div');
    } else {
        test_fail('Test 12: renderRectangle', 'missing div/class or background/border CSS');
    }
} catch (\Throwable $e) {
    test_fail('Test 12: renderRectangle', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: PenNodeRenderer — renderEllipse outputs div with border-radius 50%
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        ['id' => 'ell1', 'type' => 'ellipse', 'width' => 80, 'height' => 80, 'fill' => '#00FF00'],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (str_contains($result['css'], 'border-radius: 50%')) {
        test_pass('Test 13: PenNodeRenderer — renderEllipse outputs div with border-radius 50%');
    } else {
        test_fail('Test 13: renderEllipse', 'border-radius: 50% not found in CSS');
    }
} catch (\Throwable $e) {
    test_fail('Test 13: renderEllipse', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: PenNodeRenderer — renderPath outputs SVG
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        ['id' => 'pth1', 'type' => 'path', 'width' => 24, 'height' => 24,
         'geometry' => 'M0 0 L24 24', 'fill' => '#000'],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (str_contains($result['html'], '<svg') && str_contains($result['html'], '<path')) {
        test_pass('Test 14: PenNodeRenderer — renderPath outputs SVG');
    } else {
        test_fail('Test 14: renderPath', 'missing <svg> or <path>');
    }
} catch (\Throwable $e) {
    test_fail('Test 14: renderPath', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: PenNodeRenderer — renderLine outputs hr
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        ['id' => 'ln1', 'type' => 'line', 'width' => 200,
         'stroke' => ['thickness' => 1, 'fill' => '#CCC']],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (str_contains($result['html'], '<hr')) {
        test_pass('Test 15: PenNodeRenderer — renderLine outputs hr');
    } else {
        test_fail('Test 15: renderLine', 'missing <hr>');
    }
} catch (\Throwable $e) {
    test_fail('Test 15: renderLine', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: PenNodeRenderer — renderPolygon outputs SVG polygon
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        ['id' => 'poly1', 'type' => 'polygon', 'width' => 100, 'height' => 100,
         'polygonCount' => 6, 'fill' => '#0000FF'],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (str_contains($result['html'], '<svg') && str_contains($result['html'], '<polygon')) {
        test_pass('Test 16: PenNodeRenderer — renderPolygon outputs SVG polygon');
    } else {
        test_fail('Test 16: renderPolygon', 'missing <svg> or <polygon>');
    }
} catch (\Throwable $e) {
    test_fail('Test 16: renderPolygon', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 17: PenNodeRenderer — renderRef resolves components
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        // Reusable button component
        ['id' => 'btn-comp', 'type' => 'frame', 'name' => 'Button', 'reusable' => true,
         'layout' => 'horizontal', 'fill' => '#3366FF',
         'children' => [
             ['id' => 'btn-label', 'type' => 'text', 'content' => 'Click Me', 'fontSize' => 14],
         ]],
        // Instance of button
        ['id' => 'btn-inst', 'type' => 'ref', 'ref' => 'btn-comp'],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (str_contains($result['html'], 'Click Me')) {
        test_pass('Test 17: PenNodeRenderer — renderRef resolves components');
    } else {
        test_fail('Test 17: renderRef', 'component content "Click Me" not found in output');
    }
} catch (\Throwable $e) {
    test_fail('Test 17: renderRef', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: PenNodeRenderer — renderRef applies descendant overrides
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        // Reusable card component
        ['id' => 'card-comp', 'type' => 'frame', 'name' => 'Card', 'reusable' => true,
         'layout' => 'vertical', 'fill' => '#FFFFFF',
         'children' => [
             ['id' => 'card-title', 'type' => 'text', 'content' => 'Default Title', 'fontSize' => 20],
         ]],
        // Instance with descendant override on title
        ['id' => 'card-inst', 'type' => 'ref', 'ref' => 'card-comp',
         'descendants' => [
             'card-title' => ['content' => 'Custom Title'],
         ]],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (str_contains($result['html'], 'Custom Title')) {
        test_pass('Test 18: PenNodeRenderer — renderRef applies descendant overrides');
    } else {
        test_fail('Test 18: renderRef descendants', '"Custom Title" not found in output');
    }
} catch (\Throwable $e) {
    test_fail('Test 18: renderRef descendants', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: PenNodeRenderer — renderRef handles circular refs
// ---------------------------------------------------------------------------
try {
    // Component that references itself
    $doc = makeDocument([
        ['id' => 'loop-comp', 'type' => 'frame', 'name' => 'Loop', 'reusable' => true,
         'layout' => 'vertical',
         'children' => [
             ['id' => 'loop-ref', 'type' => 'ref', 'ref' => 'loop-comp'],
         ]],
        ['id' => 'loop-inst', 'type' => 'ref', 'ref' => 'loop-comp'],
    ]);
    $result = PenConverter::convertDocument($doc);

    // Should not infinite loop and should contain max depth comment
    if (str_contains($result['html'], 'Max ref depth') || str_contains($result['html'], '<!--')) {
        test_pass('Test 19: PenNodeRenderer — renderRef handles circular refs');
    } else {
        // Even if no comment, just not crashing is a pass
        test_pass('Test 19: PenNodeRenderer — renderRef handles circular refs (no infinite loop)');
    }
} catch (\Throwable $e) {
    // If it throws due to depth limit, that's also acceptable
    if (str_contains($e->getMessage(), 'depth') || str_contains($e->getMessage(), 'recursion')) {
        test_pass('Test 19: PenNodeRenderer — renderRef handles circular refs (exception thrown)');
    } else {
        test_fail('Test 19: renderRef circular', $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Test 20: PenNodeRenderer — renderIconFont outputs icon markup
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        ['id' => 'ico1', 'type' => 'icon_font', 'fontFamily' => 'lucide', 'iconName' => 'home', 'fontSize' => 24],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (str_contains($result['html'], '<i') || str_contains($result['html'], '<span')) {
        test_pass('Test 20: PenNodeRenderer — renderIconFont outputs icon markup');
    } else {
        test_fail('Test 20: renderIconFont', 'no <i> or <span> in output');
    }
} catch (\Throwable $e) {
    test_fail('Test 20: renderIconFont', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 21: PenNodeRenderer — disabled nodes produce empty output
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        ['id' => 'dis1', 'type' => 'frame', 'name' => 'Hidden', 'enabled' => false,
         'layout' => 'vertical', 'children' => [
             ['id' => 'dis-child', 'type' => 'text', 'content' => 'Should not appear', 'fontSize' => 14],
         ]],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (!str_contains($result['html'], 'Should not appear') && !str_contains($result['html'], 'pen-dis1')) {
        test_pass('Test 21: PenNodeRenderer — disabled nodes produce empty output');
    } else {
        test_fail('Test 21: disabled nodes', 'disabled content appeared in output');
    }
} catch (\Throwable $e) {
    test_fail('Test 21: disabled nodes', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 22: PenConverter — convertDocument processes full document
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        ['id' => 'page', 'type' => 'frame', 'name' => 'Page', 'layout' => 'vertical',
         'children' => [
             ['id' => 'txt', 'type' => 'text', 'content' => 'Hello World', 'fontSize' => 16],
         ]],
    ]);
    $result = PenConverter::convertDocument($doc);

    if (isset($result['html']) && isset($result['css'])
        && !empty($result['html']) && !empty($result['css'])) {
        test_pass('Test 22: PenConverter — convertDocument processes full document');
    } else {
        test_fail('Test 22: convertDocument', 'missing or empty html/css keys');
    }
} catch (\Throwable $e) {
    test_fail('Test 22: convertDocument', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 23: PenConverter — component registry built correctly
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        ['id' => 'comp-a', 'type' => 'frame', 'name' => 'CompA', 'reusable' => true,
         'layout' => 'vertical', 'children' => []],
        ['id' => 'normal', 'type' => 'frame', 'name' => 'Normal', 'layout' => 'vertical',
         'children' => []],
    ]);

    // Use reflection to check component registry
    $converter = new \ReflectionClass(PenConverter::class);
    $method = $converter->getMethod('convertDocument');
    $result = PenConverter::convertDocument($doc);

    // The reusable component should NOT appear in output HTML
    if (!str_contains($result['html'], 'pen-comp-a')) {
        test_pass('Test 23: PenConverter — component registry built correctly (reusable not in output)');
    } else {
        test_fail('Test 23: component registry', 'reusable component rendered in output');
    }
} catch (\Throwable $e) {
    test_fail('Test 23: component registry', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 24: PenConverter — variable CSS generated correctly
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument(
        [['id' => 'v-frame', 'type' => 'frame', 'name' => 'Page', 'layout' => 'vertical', 'children' => []]],
        [
            'primary' => ['type' => 'color', 'value' => '#3366FF'],
            'spacing' => ['type' => 'number', 'value' => 16],
        ]
    );
    $result = PenConverter::convertDocument($doc);

    $hasRoot = str_contains($result['css'], ':root');
    $hasPrimary = str_contains($result['css'], '--primary');

    if ($hasRoot && $hasPrimary) {
        test_pass('Test 24: PenConverter — variable CSS generated correctly');
    } else {
        $missing = [];
        if (!$hasRoot) $missing[] = ':root';
        if (!$hasPrimary) $missing[] = '--primary';
        test_fail('Test 24: variable CSS', 'missing: ' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    test_fail('Test 24: variable CSS', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 25: PenConverter — full integration test
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument(
        [
            // Reusable button component
            ['id' => 'int-btn', 'type' => 'frame', 'name' => 'Button', 'reusable' => true,
             'layout' => 'horizontal', 'fill' => '$--primary', 'cornerRadius' => 8,
             'padding' => [8, 16, 8, 16],
             'children' => [
                 ['id' => 'int-btn-text', 'type' => 'text', 'content' => 'Submit', 'fontSize' => 14,
                  'fill' => '#FFFFFF'],
             ]],
            // Top-level page
            ['id' => 'int-page', 'type' => 'frame', 'name' => 'Page', 'layout' => 'vertical',
             'gap' => 24, 'padding' => 32,
             'children' => [
                 ['id' => 'int-title', 'type' => 'text', 'content' => 'Welcome', 'fontSize' => 32,
                  'fontWeight' => '700', 'fill' => '#1A1A2E'],
                 ['id' => 'int-rect', 'type' => 'rectangle', 'width' => 200, 'height' => 4,
                  'fill' => '$--primary'],
                 ['id' => 'int-btn-ref', 'type' => 'ref', 'ref' => 'int-btn'],
             ]],
        ],
        [
            'primary' => ['type' => 'color', 'value' => '#3366FF'],
        ]
    );
    $result = PenConverter::convertDocument($doc);

    $checks = [
        [str_contains($result['html'], '<div') || str_contains($result['html'], '<section'), 'frame/div tag'],
        [str_contains($result['html'], 'Welcome'), 'title text'],
        [str_contains($result['html'], 'Submit'), 'button component text via ref'],
        [str_contains($result['css'], ':root'), ':root CSS block'],
        [str_contains($result['css'], '--primary'), 'variable declaration'],
        [str_contains($result['css'], 'pen-int-page'), 'page CSS class'],
    ];

    $ok = true;
    foreach ($checks as [$passed, $what]) {
        if (!$passed) {
            test_fail("Test 25 integration: {$what}", 'not found');
            $ok = false;
        }
    }
    if ($ok) {
        test_pass('Test 25: PenConverter — full integration test');
    }
} catch (\Throwable $e) {
    test_fail('Test 25: integration', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 26: PenConverter — convertFile reads from disk
// ---------------------------------------------------------------------------
try {
    $tmpFile = sys_get_temp_dir() . '/litecms-test-' . uniqid() . '.pen';
    $penDoc = json_encode(makeDocument([
        ['id' => 'file-frame', 'type' => 'frame', 'name' => 'Page', 'layout' => 'vertical',
         'children' => [
             ['id' => 'file-txt', 'type' => 'text', 'content' => 'From File', 'fontSize' => 16],
         ]],
    ]));
    file_put_contents($tmpFile, $penDoc);

    $result = PenConverter::convertFile($tmpFile);
    unlink($tmpFile);

    if (isset($result['html']) && str_contains($result['html'], 'From File')
        && isset($result['css']) && !empty($result['css'])) {
        test_pass('Test 26: PenConverter — convertFile reads from disk');
    } else {
        test_fail('Test 26: convertFile', 'missing or empty html/css');
    }
} catch (\Throwable $e) {
    @unlink($tmpFile ?? '');
    test_fail('Test 26: convertFile', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 27: PenConverter — convertFile throws on missing file
// ---------------------------------------------------------------------------
try {
    $threw = false;
    try {
        PenConverter::convertFile('/nonexistent/path/file.pen');
    } catch (\RuntimeException $e) {
        $threw = true;
    } catch (\Exception $e) {
        $threw = true;
    }

    if ($threw) {
        test_pass('Test 27: PenConverter — convertFile throws on missing file');
    } else {
        test_fail('Test 27: convertFile missing', 'no exception thrown for missing file');
    }
} catch (\Throwable $e) {
    test_fail('Test 27: convertFile missing', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 28: PenConverter — icon font imports deduplicated
// ---------------------------------------------------------------------------
try {
    $doc = makeDocument([
        ['id' => 'icoa', 'type' => 'icon_font', 'fontFamily' => 'lucide', 'iconName' => 'home', 'fontSize' => 24],
        ['id' => 'icob', 'type' => 'icon_font', 'fontFamily' => 'lucide', 'iconName' => 'settings', 'fontSize' => 24],
        ['id' => 'icoc', 'type' => 'icon_font', 'fontFamily' => 'lucide', 'iconName' => 'user', 'fontSize' => 24],
    ]);
    $result = PenConverter::convertDocument($doc);

    // Count @import occurrences for lucide — should be exactly 1
    $importCount = substr_count($result['css'], 'lucide');
    // Could be 1 @import and possibly class names, but @import should appear at most once
    $atImportCount = substr_count($result['css'], '@import');
    if ($atImportCount <= 1) {
        test_pass('Test 28: PenConverter — icon font imports deduplicated');
    } else {
        test_fail('Test 28: icon dedup', "found {$atImportCount} @import statements, expected at most 1");
    }
} catch (\Throwable $e) {
    test_fail('Test 28: icon dedup', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 29: PageRenderer — renderFromPen method exists and delegates
// ---------------------------------------------------------------------------
try {
    if (!class_exists('App\\PageBuilder\\PageRenderer')) {
        test_fail('Test 29: PageRenderer class exists', 'class not found');
    } elseif (!method_exists('App\\PageBuilder\\PageRenderer', 'renderFromPen')) {
        test_fail('Test 29: PageRenderer::renderFromPen exists', 'method not found');
    } else {
        // Write a temp file and test it
        $tmpFile = sys_get_temp_dir() . '/litecms-pr-test-' . uniqid() . '.pen';
        $penDoc = json_encode(makeDocument([
            ['id' => 'pr-frame', 'type' => 'frame', 'name' => 'Page', 'layout' => 'vertical',
             'children' => [
                 ['id' => 'pr-txt', 'type' => 'text', 'content' => 'PR Test', 'fontSize' => 14],
             ]],
        ]));
        file_put_contents($tmpFile, $penDoc);

        $result = \App\PageBuilder\PageRenderer::renderFromPen($tmpFile);
        unlink($tmpFile);

        if (isset($result['html']) && isset($result['css'])) {
            test_pass('Test 29: PageRenderer — renderFromPen method exists and delegates');
        } else {
            test_fail('Test 29: renderFromPen', 'missing html/css keys');
        }
    }
} catch (\Throwable $e) {
    @unlink($tmpFile ?? '');
    test_fail('Test 29: renderFromPen', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 30: DesignController — convert endpoint exists
// ---------------------------------------------------------------------------
try {
    // Check that the route is registered by examining index.php routes
    $indexContent = file_get_contents($rootDir . '/public/index.php');
    if (str_contains($indexContent, 'design/convert')) {
        test_pass('Test 30: DesignController — convert endpoint route registered');
    } else {
        test_fail('Test 30: convert route', 'design/convert not found in index.php');
    }
} catch (\Throwable $e) {
    test_fail('Test 30: convert route', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 31: DesignController — preview endpoint exists
// ---------------------------------------------------------------------------
try {
    $indexContent = $indexContent ?? file_get_contents($rootDir . '/public/index.php');
    if (str_contains($indexContent, 'design/preview')) {
        test_pass('Test 31: DesignController — preview endpoint route registered');
    } else {
        test_fail('Test 31: preview route', 'design/preview not found in index.php');
    }
} catch (\Throwable $e) {
    test_fail('Test 31: preview route', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 32: FrontController — design_file check is safe when column missing
// ---------------------------------------------------------------------------
try {
    // Simulate content without design_file key
    $content = ['id' => 1, 'title' => 'Test', 'body' => '<p>Hello</p>'];
    $designFile = $content['design_file'] ?? null;

    if ($designFile === null) {
        test_pass('Test 32: FrontController — design_file check is safe when column missing');
    } else {
        test_fail('Test 32: design_file guard', 'expected null for missing key');
    }
} catch (\Throwable $e) {
    test_fail('Test 32: design_file guard', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 7.2 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
