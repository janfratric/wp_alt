<?php declare(strict_types=1);

/**
 * Chunk 4.2 — AI Chat Panel Frontend
 * Automated Verification Tests
 *
 * Tests:
 *   1. ai-assistant.js file exists
 *   2. ai-assistant.js has all required functions
 *   3. ai-assistant.js has proper event handling (DOMContentLoaded, Enter key, toggle)
 *   4. ai-assistant.js sends CSRF token header in fetch requests
 *   5. ai-assistant.js has TinyMCE integration (insert + replace + confirm)
 *   6. ai-assistant.js has conversation loading from backend
 *   7. admin.css has AI panel layout styles
 *   8. admin.css has AI message bubble styles
 *   9. admin.css has AI panel responsive styles (mobile overlay)
 *  10. edit.php has AI toggle button in page header
 *  11. edit.php has data-content-id attribute on form
 *  12. edit.php has AI panel HTML structure (#ai-panel, #ai-messages, input)
 *  13. edit.php loads ai-assistant.js script after editor.js
 *  14. ai-assistant.js uses textContent for user messages (XSS safe)
 *  15. ai-assistant.js has loading indicator (typing animation)
 *  16. ai-assistant.js has new conversation support
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

// Load autoloader (required by convention)
$autoloadPath = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    test_fail('Composer autoload exists', 'vendor/autoload.php not found — run composer install');
    echo "\n[FAIL] Cannot continue — autoloader missing\n";
    exit(1);
}
require_once $autoloadPath;

// ---------------------------------------------------------------------------
// Test 1: ai-assistant.js file exists
// ---------------------------------------------------------------------------
$jsPath = $rootDir . '/public/assets/js/ai-assistant.js';

if (!file_exists($jsPath)) {
    test_fail('ai-assistant.js exists', 'public/assets/js/ai-assistant.js not found');
    echo "\n[FAIL] Cannot continue — main JS file missing\n";
    exit(1);
}
test_pass('ai-assistant.js exists');

$jsContent = file_get_contents($jsPath);

// ---------------------------------------------------------------------------
// Test 2: ai-assistant.js has all required functions
// ---------------------------------------------------------------------------
$requiredFunctions = [
    'initAIPanel'          => 'entry point / initialization',
    'togglePanel'          => 'show/hide AI panel',
    'sendMessage'          => 'send user message to backend',
    'appendMessage'        => 'add message bubble to chat',
    'appendError'          => 'show error message in chat',
    'showLoading'          => 'show typing indicator',
    'hideLoading'          => 'hide typing indicator',
    'loadConversation'     => 'load conversation history',
    'insertToEditor'       => 'insert AI HTML into TinyMCE',
    'replaceEditorContent' => 'replace TinyMCE content',
    'copyToClipboard'      => 'copy to clipboard',
    'scrollToBottom'       => 'scroll messages container',
    'escapeHtml'           => 'HTML entity escaping',
];

$allFunctionsFound = true;
$missingFunctions = [];
foreach ($requiredFunctions as $fn => $purpose) {
    // Match "function functionName" pattern
    if (!preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $jsContent)) {
        $allFunctionsFound = false;
        $missingFunctions[] = $fn;
    }
}

if ($allFunctionsFound) {
    test_pass('ai-assistant.js has all ' . count($requiredFunctions) . ' required functions');
} else {
    test_fail('ai-assistant.js required functions', 'missing: ' . implode(', ', $missingFunctions));
}

// ---------------------------------------------------------------------------
// Test 3: ai-assistant.js has proper event handling
// ---------------------------------------------------------------------------
$eventChecks = [
    'DOMContentLoaded'  => 'initializes on DOM ready',
    'ai-toggle-btn'     => 'references toggle button',
    'ai-send-btn'       => 'references send button',
    'ai-input'          => 'references input textarea',
];

$allEventsFound = true;
$missingEvents = [];
foreach ($eventChecks as $pattern => $purpose) {
    if (strpos($jsContent, $pattern) === false) {
        $allEventsFound = false;
        $missingEvents[] = "{$pattern} ({$purpose})";
    }
}

// Check for Enter key handling (send on Enter, newline on Shift+Enter)
if (strpos($jsContent, 'Enter') === false || strpos($jsContent, 'shiftKey') === false) {
    $allEventsFound = false;
    $missingEvents[] = 'Enter/Shift+Enter key handling';
}

if ($allEventsFound) {
    test_pass('ai-assistant.js has proper event handling (DOMContentLoaded, toggle, send, Enter/Shift+Enter)');
} else {
    test_fail('ai-assistant.js event handling', 'missing: ' . implode(', ', $missingEvents));
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 4.2 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: ai-assistant.js sends CSRF token header in fetch requests
// ---------------------------------------------------------------------------
if (str_contains($jsContent, 'X-CSRF-Token') && str_contains($jsContent, '_csrf_token')) {
    test_pass('ai-assistant.js reads CSRF token and sends X-CSRF-Token header');
} else {
    test_fail('ai-assistant.js CSRF handling', 'missing X-CSRF-Token header or _csrf_token input read');
}

// Verify it sends JSON content type
if (str_contains($jsContent, 'application/json')) {
    test_pass('ai-assistant.js sends Content-Type: application/json');
} else {
    test_fail('ai-assistant.js Content-Type', 'application/json not found in fetch headers');
}

// ---------------------------------------------------------------------------
// Test 5: ai-assistant.js has TinyMCE integration
// ---------------------------------------------------------------------------
if (str_contains($jsContent, 'tinymce') && str_contains($jsContent, 'insertContent')) {
    test_pass('insertToEditor() uses tinymce.activeEditor.insertContent()');
} else {
    test_fail('TinyMCE insert integration', 'tinymce or insertContent not found');
}

if (str_contains($jsContent, 'setContent')) {
    test_pass('replaceEditorContent() uses tinymce.activeEditor.setContent()');
} else {
    test_fail('TinyMCE replace integration', 'setContent not found');
}

if (str_contains($jsContent, 'confirm(')) {
    test_pass('replaceEditorContent() shows confirm() dialog before replacing');
} else {
    test_fail('Replace confirmation', 'confirm() dialog not found');
}

// ---------------------------------------------------------------------------
// Test 6: ai-assistant.js has conversation loading from backend
// ---------------------------------------------------------------------------
if (str_contains($jsContent, '/admin/ai/chat') && str_contains($jsContent, '/admin/ai/conversations')) {
    test_pass('ai-assistant.js calls both API endpoints (chat + conversations)');
} else {
    test_fail('API endpoint calls', 'missing /admin/ai/chat or /admin/ai/conversations');
}

if (str_contains($jsContent, 'conversation_id') && str_contains($jsContent, 'content_id')) {
    test_pass('ai-assistant.js sends conversation_id and content_id in requests');
} else {
    test_fail('Request payload fields', 'missing conversation_id or content_id');
}

// ---------------------------------------------------------------------------
// Test 7: admin.css has AI panel layout styles
// ---------------------------------------------------------------------------
$cssPath = $rootDir . '/public/assets/css/admin.css';

if (!file_exists($cssPath)) {
    test_fail('admin.css exists', 'public/assets/css/admin.css not found');
} else {
    $cssContent = file_get_contents($cssPath);

    $layoutSelectors = [
        '#ai-panel'             => 'panel container',
        '#ai-panel.active'      => 'panel visible state',
        '.ai-panel-open'        => 'editor layout adjustment',
        '.ai-panel-header'      => 'panel header',
        '#ai-messages'          => 'messages container',
        '.ai-panel-input'       => 'input area',
        '#ai-send-btn'          => 'send button',
    ];

    $allLayoutFound = true;
    $missingLayout = [];
    foreach ($layoutSelectors as $selector => $purpose) {
        if (strpos($cssContent, $selector) === false) {
            $allLayoutFound = false;
            $missingLayout[] = "{$selector} ({$purpose})";
        }
    }

    if ($allLayoutFound) {
        test_pass('admin.css has all AI panel layout styles (panel, header, messages, input, send btn)');
    } else {
        test_fail('admin.css AI panel layout', 'missing: ' . implode(', ', $missingLayout));
    }

    // Check 3-column grid when panel open
    if (str_contains($cssContent, 'grid-template-columns') && str_contains($cssContent, '380px')) {
        test_pass('admin.css adjusts editor to 3-column grid when AI panel open');
    } else {
        test_fail('3-column grid layout', 'grid-template-columns with 380px not found');
    }

    // ---------------------------------------------------------------------------
    // Test 8: admin.css has AI message bubble styles
    // ---------------------------------------------------------------------------
    $bubbleSelectors = [
        '.ai-message'           => 'base message class',
        '.ai-message-user'      => 'user message bubble',
        '.ai-message-assistant' => 'assistant message bubble',
        '.ai-message-error'     => 'error message bubble',
        '.ai-message-actions'   => 'action buttons container',
        '.ai-action-btn'        => 'action button style',
    ];

    $allBubblesFound = true;
    $missingBubbles = [];
    foreach ($bubbleSelectors as $selector => $purpose) {
        if (strpos($cssContent, $selector) === false) {
            $allBubblesFound = false;
            $missingBubbles[] = "{$selector} ({$purpose})";
        }
    }

    if ($allBubblesFound) {
        test_pass('admin.css has all AI message bubble styles (user, assistant, error, actions)');
    } else {
        test_fail('admin.css message bubbles', 'missing: ' . implode(', ', $missingBubbles));
    }

    // Check for typing indicator animation
    if (str_contains($cssContent, '.ai-typing-indicator') && str_contains($cssContent, '@keyframes')) {
        test_pass('admin.css has typing indicator with keyframe animation');
    } else {
        test_fail('Typing indicator CSS', 'missing .ai-typing-indicator or @keyframes');
    }

    // ---------------------------------------------------------------------------
    // Test 9: admin.css has AI panel responsive styles
    // ---------------------------------------------------------------------------
    // Check for responsive override that makes panel full-screen on mobile
    if (str_contains($cssContent, 'position: fixed') || str_contains($cssContent, 'position:fixed')) {
        $hasInset = str_contains($cssContent, 'inset:') || str_contains($cssContent, 'inset :');
        $hasZIndex = (bool) preg_match('/z-index\s*:\s*1000/', $cssContent);

        if ($hasInset || $hasZIndex) {
            test_pass('admin.css has responsive AI panel (full-screen overlay on mobile)');
        } else {
            test_fail('Responsive AI panel', 'position:fixed found but missing inset:0 or z-index:1000');
        }
    } else {
        test_fail('Responsive AI panel', 'position:fixed not found for mobile overlay');
    }
}

// ---------------------------------------------------------------------------
// Test 10: edit.php has AI toggle button in page header
// ---------------------------------------------------------------------------
$editPath = $rootDir . '/templates/admin/content/edit.php';

if (!file_exists($editPath)) {
    test_fail('edit.php exists', 'templates/admin/content/edit.php not found');
    echo "\n[FAIL] Cannot continue — edit template missing\n";
} else {
    $editContent = file_get_contents($editPath);

    if (str_contains($editContent, 'ai-toggle-btn') && str_contains($editContent, 'AI Assistant')) {
        test_pass('edit.php has AI toggle button (#ai-toggle-btn) with "AI Assistant" label');
    } else {
        test_fail('AI toggle button', 'missing ai-toggle-btn id or "AI Assistant" text');
    }

    if (str_contains($editContent, 'aria-expanded')) {
        test_pass('AI toggle button has aria-expanded attribute (accessibility)');
    } else {
        test_fail('Toggle accessibility', 'aria-expanded not found on toggle button');
    }

    // ---------------------------------------------------------------------------
    // Test 11: edit.php has data-content-id attribute on form
    // ---------------------------------------------------------------------------
    if (str_contains($editContent, 'data-content-id')) {
        test_pass('edit.php form has data-content-id attribute for AI panel');
    } else {
        test_fail('data-content-id attribute', 'not found on content form');
    }

    // ---------------------------------------------------------------------------
    // Test 12: edit.php has AI panel HTML structure
    // ---------------------------------------------------------------------------
    $panelElements = [
        'id="ai-panel"'     => 'panel container',
        'ai-panel-header'   => 'panel header',
        'id="ai-messages"'  => 'messages container',
        'id="ai-input"'     => 'input textarea',
        'id="ai-send-btn"'  => 'send button',
        'ai-empty-state'    => 'empty state placeholder',
    ];

    $allPanelFound = true;
    $missingPanel = [];
    foreach ($panelElements as $pattern => $purpose) {
        if (strpos($editContent, $pattern) === false) {
            $allPanelFound = false;
            $missingPanel[] = "{$pattern} ({$purpose})";
        }
    }

    if ($allPanelFound) {
        test_pass('edit.php has complete AI panel HTML (panel, header, messages, input, send btn, empty state)');
    } else {
        test_fail('AI panel HTML structure', 'missing: ' . implode(', ', $missingPanel));
    }

    // Check for "New conversation" button
    if (str_contains($editContent, 'ai-new-conversation')) {
        test_pass('edit.php has "New conversation" button in panel header');
    } else {
        test_fail('New conversation button', 'ai-new-conversation not found');
    }

    // ---------------------------------------------------------------------------
    // Test 13: edit.php loads ai-assistant.js script after editor.js
    // ---------------------------------------------------------------------------
    $editorJsPos = strpos($editContent, 'editor.js');
    $aiJsPos = strpos($editContent, 'ai-assistant.js');

    if ($aiJsPos !== false) {
        if ($editorJsPos !== false && $aiJsPos > $editorJsPos) {
            test_pass('edit.php loads ai-assistant.js after editor.js (correct order)');
        } elseif ($editorJsPos === false) {
            test_fail('Script load order', 'editor.js reference not found');
        } else {
            test_fail('Script load order', 'ai-assistant.js appears before editor.js');
        }
    } else {
        test_fail('ai-assistant.js script tag', 'not found in edit.php');
    }
}

// ---------------------------------------------------------------------------
// Test 14: ai-assistant.js uses textContent for user messages (XSS safe)
// ---------------------------------------------------------------------------
// User messages should use textContent, assistant messages use innerHTML
if (str_contains($jsContent, 'textContent') && str_contains($jsContent, 'innerHTML')) {
    // Verify the pattern: user messages get textContent, assistant messages get innerHTML
    // Check that appendMessage function differentiates by role
    if (preg_match('/role\s*===?\s*[\'"]assistant[\'"]/', $jsContent) &&
        preg_match('/role\s*===?\s*[\'"]user[\'"]/', $jsContent)) {
        test_pass('ai-assistant.js differentiates user (textContent) and assistant (innerHTML) messages');
    } else {
        test_fail('Message role differentiation', 'role checks for user/assistant not found');
    }
} else {
    test_fail('XSS-safe message rendering', 'expected both textContent and innerHTML usage');
}

// ---------------------------------------------------------------------------
// Test 15: ai-assistant.js has loading indicator
// ---------------------------------------------------------------------------
if (str_contains($jsContent, 'ai-message-loading') && str_contains($jsContent, 'ai-typing-indicator')) {
    test_pass('ai-assistant.js creates loading/typing indicator elements');
} else {
    test_fail('Loading indicator', 'missing ai-message-loading or ai-typing-indicator class');
}

// Check isLoading guard prevents double-send
if (str_contains($jsContent, 'isLoading')) {
    test_pass('ai-assistant.js uses isLoading flag to prevent double-send');
} else {
    test_fail('Double-send prevention', 'isLoading flag not found');
}

// ---------------------------------------------------------------------------
// Test 16: ai-assistant.js has new conversation support
// ---------------------------------------------------------------------------
if (str_contains($jsContent, 'ai-new-conversation') || str_contains($jsContent, 'new-conversation')) {
    test_pass('ai-assistant.js handles new conversation button');
} else {
    test_fail('New conversation handler', 'ai-new-conversation reference not found');
}

if (str_contains($jsContent, 'conversationId') && str_contains($jsContent, 'null')) {
    test_pass('ai-assistant.js resets conversationId for new conversations');
} else {
    test_fail('Conversation ID reset', 'conversationId = null pattern not found');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 4.2 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
