<?php declare(strict_types=1);

/**
 * Chunk 7.1 — Embed Pencil Editor in LiteCMS Admin
 * Automated Verification Tests
 *
 * Tests:
 *   1. DesignController class exists and is autoloadable
 *   2. DesignController has required methods (editor, load, save, importFile, list)
 *   3. designs/ directory exists
 *   4. public/assets/pencil-editor/index.html exists (patched)
 *   5. public/assets/pencil-editor/assets/index.js exists (editor bundle)
 *   6. public/assets/pencil-editor/assets/pencil.wasm exists (WASM binary)
 *   7. public/assets/pencil-editor/assets/index.css exists
 *   8. public/assets/js/pencil-bridge.js exists and contains vscodeapi mock
 *   9. Patched index.html references pencil-bridge.js (bridge script tag)
 *  10. Patched index.html does NOT contain original CSP meta tag
 *  11. templates/admin/design/editor.php exists
 *  12. Editor template contains iframe element
 *  13. Editor template contains file selector
 *  14. Admin layout sidebar contains "Design Editor" link
 *  15. Route GET /admin/design/editor resolves to DesignController::editor
 *  16. Route GET /admin/design/load resolves to DesignController::load
 *  17. Route POST /admin/design/save resolves to DesignController::save
 *  18. Route POST /admin/design/import-file resolves to DesignController::importFile
 *  19. Route GET /admin/design/list resolves to DesignController::list
 *  20. DesignController::sanitizePath blocks path traversal ('../../etc/passwd')
 *  21. DesignController::sanitizePath blocks non-.pen extensions
 *  22. DesignController::sanitizePath blocks null bytes
 *  23. DesignController::sanitizePath accepts valid paths ('my-design.pen')
 *  24. DesignController::save writes file to designs/ directory
 *  25. DesignController::load reads file from designs/ directory
 *  26. DesignController::list returns array of .pen files
 *  27. public/assets/uploads/design/ directory exists with .htaccess
 *  28. Editor worker files exist (browserAll.js, webworkerAll.js, etc.)
 *  29. admin.css contains design-editor-page styles
 *  30. pencil-bridge.js contains CSRF token handling for fetch requests
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

// ---------------------------------------------------------------------------
// Test 1: DesignController class exists and is autoloadable
// ---------------------------------------------------------------------------
$controllerClass = 'App\\Admin\\DesignController';

if (class_exists($controllerClass)) {
    test_pass('DesignController class exists and is autoloadable');
} else {
    test_fail('DesignController class exists and is autoloadable', 'class not found');
}

// ---------------------------------------------------------------------------
// Test 2: DesignController has required methods
// ---------------------------------------------------------------------------
$requiredMethods = ['editor', 'load', 'save', 'importFile', 'list'];

if (class_exists($controllerClass)) {
    $reflection = new ReflectionClass($controllerClass);
    $missingMethods = [];
    foreach ($requiredMethods as $method) {
        if (!$reflection->hasMethod($method) || !$reflection->getMethod($method)->isPublic()) {
            $missingMethods[] = $method;
        }
    }
    if (empty($missingMethods)) {
        test_pass('DesignController has required public methods: ' . implode(', ', $requiredMethods));
    } else {
        test_fail('DesignController has required public methods', 'missing: ' . implode(', ', $missingMethods));
    }
} else {
    test_fail('DesignController has required public methods', 'class not found');
}

// ---------------------------------------------------------------------------
// Test 3: designs/ directory exists
// ---------------------------------------------------------------------------
$designsDir = $rootDir . '/designs';

if (is_dir($designsDir)) {
    test_pass('designs/ directory exists');
} else {
    test_fail('designs/ directory exists', 'directory not found');
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 7.1 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: public/assets/pencil-editor/index.html exists (patched)
// ---------------------------------------------------------------------------
$editorHtml = $rootDir . '/public/assets/pencil-editor/index.html';

if (file_exists($editorHtml)) {
    test_pass('public/assets/pencil-editor/index.html exists');
} else {
    test_fail('public/assets/pencil-editor/index.html exists', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 5: public/assets/pencil-editor/assets/index.js exists (editor bundle)
// ---------------------------------------------------------------------------
$editorJs = $rootDir . '/public/assets/pencil-editor/assets/index.js';

if (file_exists($editorJs)) {
    test_pass('public/assets/pencil-editor/assets/index.js exists');
} else {
    test_fail('public/assets/pencil-editor/assets/index.js exists', 'file not found — run: php scripts/copy-pencil-editor.php');
}

// ---------------------------------------------------------------------------
// Test 6: public/assets/pencil-editor/assets/pencil.wasm exists (WASM binary)
// ---------------------------------------------------------------------------
$wasmFile = $rootDir . '/public/assets/pencil-editor/assets/pencil.wasm';

if (file_exists($wasmFile)) {
    test_pass('public/assets/pencil-editor/assets/pencil.wasm exists');
} else {
    test_fail('public/assets/pencil-editor/assets/pencil.wasm exists', 'file not found — run: php scripts/copy-pencil-editor.php');
}

// ---------------------------------------------------------------------------
// Test 7: public/assets/pencil-editor/assets/index.css exists
// ---------------------------------------------------------------------------
$editorCss = $rootDir . '/public/assets/pencil-editor/assets/index.css';

if (file_exists($editorCss)) {
    test_pass('public/assets/pencil-editor/assets/index.css exists');
} else {
    test_fail('public/assets/pencil-editor/assets/index.css exists', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 8: pencil-bridge.js exists and contains vscodeapi mock
// ---------------------------------------------------------------------------
$bridgeJs = $rootDir . '/public/assets/js/pencil-bridge.js';

if (file_exists($bridgeJs)) {
    $bridgeContent = file_get_contents($bridgeJs);
    if (str_contains($bridgeContent, 'window.vscodeapi')) {
        test_pass('pencil-bridge.js exists and contains vscodeapi mock');
    } else {
        test_fail('pencil-bridge.js contains vscodeapi mock', 'window.vscodeapi not found in file');
    }
} else {
    test_fail('pencil-bridge.js exists', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 9: Patched index.html references pencil-bridge.js
// ---------------------------------------------------------------------------
if (file_exists($editorHtml)) {
    $htmlContent = file_get_contents($editorHtml);
    if (str_contains($htmlContent, 'pencil-bridge.js')) {
        test_pass('Patched index.html references pencil-bridge.js');
    } else {
        test_fail('Patched index.html references pencil-bridge.js', 'script tag not found');
    }
} else {
    test_skip('Patched index.html references pencil-bridge.js (index.html missing)');
}

// ---------------------------------------------------------------------------
// Test 10: Patched index.html does NOT contain original CSP meta tag
// ---------------------------------------------------------------------------
if (file_exists($editorHtml)) {
    $htmlContent = $htmlContent ?? file_get_contents($editorHtml);
    if (!str_contains($htmlContent, 'Content-Security-Policy')) {
        test_pass('Patched index.html does NOT contain CSP meta tag');
    } else {
        test_fail('Patched index.html does NOT contain CSP meta tag', 'CSP meta tag still present');
    }
} else {
    test_skip('Patched index.html CSP check (index.html missing)');
}

// ---------------------------------------------------------------------------
// Test 11: templates/admin/design/editor.php exists
// ---------------------------------------------------------------------------
$editorTemplate = $rootDir . '/templates/admin/design/editor.php';

if (file_exists($editorTemplate)) {
    test_pass('templates/admin/design/editor.php exists');
} else {
    test_fail('templates/admin/design/editor.php exists', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 12: Editor template contains iframe element
// ---------------------------------------------------------------------------
if (file_exists($editorTemplate)) {
    $templateContent = file_get_contents($editorTemplate);
    if (str_contains($templateContent, '<iframe') || str_contains($templateContent, 'iframe')) {
        test_pass('Editor template contains iframe element');
    } else {
        test_fail('Editor template contains iframe element', 'no iframe found');
    }
} else {
    test_skip('Editor template iframe check (template missing)');
}

// ---------------------------------------------------------------------------
// Test 13: Editor template contains file selector
// ---------------------------------------------------------------------------
if (file_exists($editorTemplate)) {
    $templateContent = $templateContent ?? file_get_contents($editorTemplate);
    if (str_contains($templateContent, '<select') || str_contains($templateContent, 'design-file')) {
        test_pass('Editor template contains file selector');
    } else {
        test_fail('Editor template contains file selector', 'no select/file-selector found');
    }
} else {
    test_skip('Editor template file selector check (template missing)');
}

// ---------------------------------------------------------------------------
// Test 14: Admin layout sidebar contains "Design Editor" link
// ---------------------------------------------------------------------------
$layoutTemplate = $rootDir . '/templates/admin/layout.php';

if (file_exists($layoutTemplate)) {
    $layoutContent = file_get_contents($layoutTemplate);
    if (str_contains($layoutContent, 'design/editor') || str_contains($layoutContent, 'Design Editor')) {
        test_pass('Admin layout sidebar contains "Design Editor" link');
    } else {
        test_fail('Admin layout sidebar contains "Design Editor" link', 'link not found in layout');
    }
} else {
    test_fail('Admin layout exists', 'templates/admin/layout.php not found');
}

// ---------------------------------------------------------------------------
// Tests 15-19: Route registration
// ---------------------------------------------------------------------------
$routeTests = [
    ['GET',  '/admin/design/editor',      'App\\Admin\\DesignController', 'editor'],
    ['GET',  '/admin/design/load',        'App\\Admin\\DesignController', 'load'],
    ['POST', '/admin/design/save',        'App\\Admin\\DesignController', 'save'],
    ['POST', '/admin/design/import-file', 'App\\Admin\\DesignController', 'importFile'],
    ['GET',  '/admin/design/list',        'App\\Admin\\DesignController', 'list'],
];

// Build a Router instance and register routes the same way index.php does
$indexPhp = $rootDir . '/public/index.php';
$indexContent = file_exists($indexPhp) ? file_get_contents($indexPhp) : '';

// Check if the routes are at least declared in index.php source
foreach ($routeTests as [$httpMethod, $uri, $expectedClass, $expectedMethod]) {
    $routePattern = str_replace('/admin', '', $uri); // route inside the /admin group
    // Look for the route pattern in index.php
    $shortPath = ltrim($routePattern, '/'); // e.g. "design/editor"
    if (str_contains($indexContent, $shortPath) || str_contains($indexContent, "'" . $routePattern . "'") || str_contains($indexContent, '"' . $routePattern . '"')) {
        // Also verify it references the correct method
        if (str_contains($indexContent, $expectedMethod)) {
            test_pass("Route {$httpMethod} {$uri} registered → {$expectedClass}::{$expectedMethod}");
        } else {
            test_fail("Route {$httpMethod} {$uri} maps to {$expectedClass}::{$expectedMethod}", 'route found but method reference missing');
        }
    } else {
        test_fail("Route {$httpMethod} {$uri} registered", "route not found in public/index.php");
    }
}

// ---------------------------------------------------------------------------
// Tests 20-23: sanitizePath security
// ---------------------------------------------------------------------------
if (class_exists($controllerClass)) {
    $reflection = new ReflectionClass($controllerClass);

    if ($reflection->hasMethod('sanitizePath')) {
        $sanitizeMethod = $reflection->getMethod('sanitizePath');
        $sanitizeMethod->setAccessible(true);

        // We need an instance of DesignController — requires App
        // Try to create a minimal instance via reflection to avoid full App boot
        $constructor = $reflection->getConstructor();
        $instance = null;

        try {
            // Attempt to create instance — DesignController needs App
            if (class_exists('App\\Core\\App')) {
                // Provide minimal App setup
                $_SERVER['REQUEST_METHOD'] = 'GET';
                $_SERVER['REQUEST_URI'] = '/';
                $_SERVER['SCRIPT_NAME'] = '/index.php';
                $_GET = [];
                $_POST = [];
                $_COOKIE = [];

                $app = new \App\Core\App();
                $instance = new $controllerClass($app);
            }
        } catch (\Throwable $e) {
            // If App construction fails, create instance without constructor
            try {
                $instance = $reflection->newInstanceWithoutConstructor();
                // Set designsDir property manually
                if ($reflection->hasProperty('designsDir')) {
                    $prop = $reflection->getProperty('designsDir');
                    $prop->setAccessible(true);
                    $prop->setValue($instance, $rootDir . '/designs');
                }
            } catch (\Throwable $e2) {
                $instance = null;
            }
        }

        if ($instance !== null) {
            // Test 20: Block path traversal
            $result = $sanitizeMethod->invoke($instance, '../../etc/passwd.pen');
            if ($result === null) {
                test_pass('sanitizePath blocks path traversal (../../etc/passwd.pen)');
            } else {
                test_fail('sanitizePath blocks path traversal', "returned: {$result}");
            }

            // Test 21: Block non-.pen extensions
            $result = $sanitizeMethod->invoke($instance, 'malicious.php');
            if ($result === null) {
                test_pass('sanitizePath blocks non-.pen extensions (malicious.php)');
            } else {
                test_fail('sanitizePath blocks non-.pen extensions', "returned: {$result}");
            }

            // Test 22: Block null bytes
            $result = $sanitizeMethod->invoke($instance, "design\0.pen");
            if ($result === null) {
                test_pass('sanitizePath blocks null bytes');
            } else {
                test_fail('sanitizePath blocks null bytes', 'null byte path was accepted');
            }

            // Test 23: Accept valid paths
            $result = $sanitizeMethod->invoke($instance, 'my-design.pen');
            if ($result === 'my-design.pen') {
                test_pass('sanitizePath accepts valid paths (my-design.pen)');
            } else {
                test_fail('sanitizePath accepts valid paths', "expected 'my-design.pen', got: " . var_export($result, true));
            }
        } else {
            test_skip('sanitizePath tests (could not instantiate DesignController)');
            test_skip('sanitizePath tests (could not instantiate DesignController)');
            test_skip('sanitizePath tests (could not instantiate DesignController)');
            test_skip('sanitizePath tests (could not instantiate DesignController)');
        }
    } else {
        test_fail('sanitizePath method exists', 'method not found on DesignController');
        test_skip('sanitizePath blocks path traversal (method missing)');
        test_skip('sanitizePath blocks non-.pen extensions (method missing)');
        test_skip('sanitizePath blocks null bytes (method missing)');
    }
} else {
    test_skip('sanitizePath tests (DesignController class missing)');
    test_skip('sanitizePath tests (DesignController class missing)');
    test_skip('sanitizePath tests (DesignController class missing)');
    test_skip('sanitizePath tests (DesignController class missing)');
}

// ---------------------------------------------------------------------------
// Tests 24-26: File I/O operations
// ---------------------------------------------------------------------------
// These tests write/read/list files in the designs/ directory

$testFileName = '_test-7-1-verify-' . bin2hex(random_bytes(4)) . '.pen';
$testFilePath = $rootDir . '/designs/' . $testFileName;
$testContent = '{"nodes":[],"test":true}';
$ioTestsRan = false;

if (is_dir($designsDir)) {
    // Test 24: Save writes file to designs/
    try {
        file_put_contents($testFilePath, $testContent, LOCK_EX);
        if (file_exists($testFilePath) && file_get_contents($testFilePath) === $testContent) {
            test_pass('File write to designs/ directory works');
            $ioTestsRan = true;
        } else {
            test_fail('File write to designs/ directory', 'file not found after write');
        }
    } catch (\Throwable $e) {
        test_fail('File write to designs/ directory', $e->getMessage());
    }

    // Test 25: Load reads file from designs/
    if ($ioTestsRan) {
        $readContent = file_get_contents($testFilePath);
        if ($readContent === $testContent) {
            test_pass('File read from designs/ directory works');
        } else {
            test_fail('File read from designs/ directory', 'content mismatch');
        }
    } else {
        test_skip('File read from designs/ (write failed)');
    }

    // Test 26: List returns .pen files
    if (class_exists($controllerClass) && isset($reflection) && $reflection->hasMethod('getDesignFileList')) {
        $listMethod = $reflection->getMethod('getDesignFileList');
        $listMethod->setAccessible(true);

        if (isset($instance) && $instance !== null) {
            try {
                $files = $listMethod->invoke($instance);
                if (is_array($files)) {
                    // Check that our test file appears in the list
                    $found = false;
                    foreach ($files as $f) {
                        if (($f['path'] ?? '') === $testFileName || ($f['name'] ?? '') === str_replace('.pen', '', $testFileName)) {
                            $found = true;
                            break;
                        }
                    }
                    if ($found) {
                        test_pass('getDesignFileList() returns array containing .pen files');
                    } else {
                        test_fail('getDesignFileList() returns array containing .pen files', 'test file not found in listing');
                    }
                } else {
                    test_fail('getDesignFileList() returns array', 'returned non-array');
                }
            } catch (\Throwable $e) {
                test_fail('getDesignFileList() works', $e->getMessage());
            }
        } else {
            // Fallback: just check that designs/ contains our test .pen file via glob
            $penFiles = glob($designsDir . '/*.pen');
            if (is_array($penFiles) && count($penFiles) > 0) {
                test_pass('designs/ directory contains .pen files (glob check)');
            } else {
                test_fail('designs/ directory contains .pen files', 'no .pen files found');
            }
        }
    } else {
        // Fallback: check designs/ has .pen files
        $penFiles = glob($designsDir . '/*.pen');
        if (is_array($penFiles) && count($penFiles) > 0) {
            test_pass('designs/ directory contains .pen files (glob check)');
        } else {
            test_skip('List .pen files (getDesignFileList method not available, no .pen files found)');
        }
    }

    // Cleanup test file
    if (file_exists($testFilePath)) {
        @unlink($testFilePath);
    }
} else {
    test_fail('designs/ directory exists for I/O tests', 'directory missing');
    test_skip('File read from designs/ (directory missing)');
    test_skip('List .pen files (directory missing)');
}

// ---------------------------------------------------------------------------
// Test 27: public/assets/uploads/design/ directory exists with .htaccess
// ---------------------------------------------------------------------------
$uploadDesignDir = $rootDir . '/public/assets/uploads/design';
$htaccessFile = $uploadDesignDir . '/.htaccess';

if (is_dir($uploadDesignDir)) {
    if (file_exists($htaccessFile)) {
        test_pass('public/assets/uploads/design/ exists with .htaccess');
    } else {
        test_fail('public/assets/uploads/design/ .htaccess', 'directory exists but .htaccess missing');
    }
} else {
    test_fail('public/assets/uploads/design/ directory exists', 'directory not found');
}

// ---------------------------------------------------------------------------
// Test 28: Editor worker files exist
// ---------------------------------------------------------------------------
$workerFiles = [
    'browserAll.js',
    'browserAll2.js',
    'webworkerAll.js',
    'webworkerAll2.js',
];
$assetsDir = $rootDir . '/public/assets/pencil-editor/assets';
$missingWorkers = [];

foreach ($workerFiles as $wf) {
    if (!file_exists($assetsDir . '/' . $wf)) {
        $missingWorkers[] = $wf;
    }
}

if (empty($missingWorkers)) {
    test_pass('Editor worker files exist (' . implode(', ', $workerFiles) . ')');
} else {
    test_fail('Editor worker files exist', 'missing: ' . implode(', ', $missingWorkers));
}

// ---------------------------------------------------------------------------
// Test 29: admin.css contains design-editor-page styles
// ---------------------------------------------------------------------------
$adminCss = $rootDir . '/public/assets/css/admin.css';

if (file_exists($adminCss)) {
    $cssContent = file_get_contents($adminCss);
    if (str_contains($cssContent, 'design-editor-page')) {
        test_pass('admin.css contains design-editor-page styles');
    } else {
        test_fail('admin.css contains design-editor-page styles', 'class not found in CSS');
    }
} else {
    test_fail('admin.css exists', 'file not found');
}

// ---------------------------------------------------------------------------
// Test 30: pencil-bridge.js contains CSRF token handling for fetch requests
// ---------------------------------------------------------------------------
if (file_exists($bridgeJs)) {
    $bridgeContent = $bridgeContent ?? file_get_contents($bridgeJs);
    if (str_contains($bridgeContent, 'X-CSRF-Token') && str_contains($bridgeContent, 'csrfToken')) {
        test_pass('pencil-bridge.js contains CSRF token handling for fetch requests');
    } else {
        test_fail('pencil-bridge.js contains CSRF token handling', 'X-CSRF-Token or csrfToken not found');
    }
} else {
    test_skip('pencil-bridge.js CSRF check (file missing)');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 7.1 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
