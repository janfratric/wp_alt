<?php declare(strict_types=1);

/**
 * Chunk 4.1 — Claude API Client & Backend
 * Automated Verification Tests
 *
 * Tests:
 *   1. All chunk 4.1 classes are autoloadable
 *   2. AIController::encrypt() / decrypt() roundtrip
 *   3. ClaudeClient throws on empty API key
 *   4. ConversationManager::findOrCreate() creates a new conversation
 *   5. ConversationManager::appendMessage() persists messages
 *   6. ConversationManager::getMessages() parses JSON correctly
 *   7. ConversationManager::getHistory() returns user conversations
 *   8. ConversationManager::delete() removes conversation
 *   9. SettingsController settings CRUD (save + load via DB)
 *  10. API key stored encrypted in settings (not plain text)
 *  11. Routes registered for settings and AI endpoints
 *  12. CSRF middleware accepts X-CSRF-Token header
 *  13. ClaudeClient constructs successfully with valid params
 *  14. Conversation ownership check (user cannot access another's conversation)
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
// Setup: use a dedicated test database
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk41.sqlite';

// Clean up any previous test database
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

// Load autoloader
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
putenv('APP_SECRET=test-secret-for-chunk-41');

// Force Config to reload
$configReflection = new ReflectionClass(\App\Core\Config::class);
$configProp = $configReflection->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, null);

// Reset Connection singleton
if (class_exists(\App\Database\Connection::class)) {
    \App\Database\Connection::reset();
}

// Run migrations to create tables
$pdo = \App\Database\Connection::getInstance();
$migrator = new \App\Database\Migrator($pdo);
$migrator->migrate();

// Create a test user for session simulation
$testUserId = (int) \App\Database\QueryBuilder::query('users')->insert([
    'username'      => 'testadmin',
    'email'         => 'admin@test.com',
    'password_hash' => password_hash('test', PASSWORD_BCRYPT),
    'role'          => 'admin',
]);

// Create a second user for ownership tests
$otherUserId = (int) \App\Database\QueryBuilder::query('users')->insert([
    'username'      => 'otheruser',
    'email'         => 'other@test.com',
    'password_hash' => password_hash('test', PASSWORD_BCRYPT),
    'role'          => 'editor',
]);

// Create a content item for context tests
$testContentId = (int) \App\Database\QueryBuilder::query('content')->insert([
    'type'      => 'page',
    'title'     => 'Test Page',
    'slug'      => 'test-page',
    'body'      => '<p>This is test content for AI context.</p>',
    'status'    => 'published',
    'author_id' => $testUserId,
]);

// ---------------------------------------------------------------------------
// Test 1: All chunk 4.1 classes are autoloadable
// ---------------------------------------------------------------------------
$requiredClasses = [
    'App\\AIAssistant\\ClaudeClient',
    'App\\AIAssistant\\ConversationManager',
    'App\\AIAssistant\\AIController',
    'App\\Admin\\SettingsController',
];

$allClassesFound = true;
foreach ($requiredClasses as $class) {
    if (!class_exists($class)) {
        test_fail("Class {$class} is autoloadable", 'class not found');
        $allClassesFound = false;
    }
}
if ($allClassesFound) {
    test_pass('All chunk 4.1 classes are autoloadable (ClaudeClient, ConversationManager, AIController, SettingsController)');
}

// ---------------------------------------------------------------------------
// Test 2: AIController encrypt/decrypt roundtrip
// ---------------------------------------------------------------------------
try {
    $testKey = 'sk-ant-api03-test-key-12345678';
    $encrypted = \App\AIAssistant\AIController::encrypt($testKey);

    if ($encrypted === '') {
        test_fail('encrypt() returns non-empty value', 'got empty string');
    } elseif ($encrypted === $testKey) {
        test_fail('encrypt() produces different output than input', 'encrypted equals plain text');
    } else {
        test_pass("encrypt() returns non-empty, non-plain-text value (length: " . strlen($encrypted) . ")");
    }

    $decrypted = \App\AIAssistant\AIController::decrypt($encrypted);
    if ($decrypted === $testKey) {
        test_pass("decrypt() recovers original value: roundtrip successful");
    } else {
        test_fail('decrypt() recovers original value', "expected '{$testKey}', got '{$decrypted}'");
    }

    // Verify encrypted value looks like base64
    $decoded = base64_decode($encrypted, true);
    if ($decoded !== false && strlen($decoded) > 16) {
        test_pass('Encrypted value is valid base64 with IV prefix (length > 16 bytes)');
    } else {
        test_fail('Encrypted value format', 'not valid base64 or too short');
    }

    // Decrypt with wrong data returns empty
    $badResult = \App\AIAssistant\AIController::decrypt('invalid-not-base64');
    if ($badResult === '') {
        test_pass('decrypt() returns empty string for invalid input');
    } else {
        test_fail('decrypt() invalid input handling', "expected empty, got: '{$badResult}'");
    }
} catch (\Throwable $e) {
    test_fail('Encrypt/decrypt roundtrip works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 3: ClaudeClient throws on empty API key
// ---------------------------------------------------------------------------
try {
    $client = new \App\AIAssistant\ClaudeClient('', 'claude-sonnet-4-20250514');

    $threw = false;
    try {
        $client->sendMessage([['role' => 'user', 'content' => 'Hello']]);
    } catch (\RuntimeException $e) {
        $threw = true;
        if (str_contains(strtolower($e->getMessage()), 'api key')) {
            test_pass('ClaudeClient throws RuntimeException for empty API key with descriptive message');
        } else {
            test_fail('ClaudeClient empty key error message', "expected message about API key, got: {$e->getMessage()}");
        }
    }

    if (!$threw) {
        test_fail('ClaudeClient throws on empty API key', 'no exception was thrown');
    }
} catch (\Throwable $e) {
    test_fail('ClaudeClient empty key test works without errors', $e->getMessage());
}

if ($isSmoke) {
    // Smoke mode — stop here, clean up
    \App\Database\Connection::reset();
    $configProp->setValue(null, null);
    putenv('DB_DRIVER');
    putenv('DB_PATH');
    putenv('APP_SECRET');
    if (file_exists($testDbPath)) { unlink($testDbPath); }
    $walPath = $testDbPath . '-wal';
    $shmPath = $testDbPath . '-shm';
    if (file_exists($walPath)) { unlink($walPath); }
    if (file_exists($shmPath)) { unlink($shmPath); }
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 4.1 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: ConversationManager::findOrCreate() creates a new conversation
// ---------------------------------------------------------------------------
try {
    $manager = new \App\AIAssistant\ConversationManager();

    $conversation = $manager->findOrCreate($testUserId, $testContentId);

    if ($conversation !== null && isset($conversation['id'])) {
        test_pass("findOrCreate() creates conversation (id: {$conversation['id']})");
    } else {
        test_fail('findOrCreate() creates conversation', 'returned null or missing id');
    }

    // Verify it's in the database
    $row = \App\Database\QueryBuilder::query('ai_conversations')
        ->select()
        ->where('id', (int) $conversation['id'])
        ->first();

    if ($row !== null && (int) $row['user_id'] === $testUserId && (int) $row['content_id'] === $testContentId) {
        test_pass('Conversation persisted in database with correct user_id and content_id');
    } else {
        test_fail('Conversation DB persistence', 'row not found or data mismatch');
    }

    // Calling findOrCreate again returns the SAME conversation (not a new one)
    $same = $manager->findOrCreate($testUserId, $testContentId);
    if ((int) $same['id'] === (int) $conversation['id']) {
        test_pass('findOrCreate() returns existing conversation on second call (idempotent)');
    } else {
        test_fail('findOrCreate() idempotent', "expected id {$conversation['id']}, got {$same['id']}");
    }
} catch (\Throwable $e) {
    test_fail('ConversationManager::findOrCreate() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: ConversationManager::appendMessage() persists messages
// ---------------------------------------------------------------------------
$conversationId = (int) ($conversation['id'] ?? 0);
try {
    $manager = new \App\AIAssistant\ConversationManager();

    $messages = $manager->appendMessage($conversationId, 'user', 'Hello, AI!');

    if (count($messages) === 1) {
        test_pass('appendMessage() returns array with 1 message after first append');
    } else {
        test_fail('appendMessage() first message', 'expected 1 message, got ' . count($messages));
    }

    if (($messages[0]['role'] ?? '') === 'user' && ($messages[0]['content'] ?? '') === 'Hello, AI!') {
        test_pass('Appended message has correct role and content');
    } else {
        test_fail('Appended message data', 'role or content mismatch');
    }

    if (isset($messages[0]['timestamp'])) {
        test_pass('Appended message includes timestamp');
    } else {
        test_fail('Appended message timestamp', 'no timestamp field');
    }

    // Append a second message
    $messages2 = $manager->appendMessage($conversationId, 'assistant', 'Hi there! How can I help?');

    if (count($messages2) === 2) {
        test_pass('appendMessage() accumulates: 2 messages after second append');
    } else {
        test_fail('appendMessage() accumulation', 'expected 2 messages, got ' . count($messages2));
    }

    // Verify it's persisted in the database
    $dbRow = \App\Database\QueryBuilder::query('ai_conversations')
        ->select('messages_json')
        ->where('id', $conversationId)
        ->first();

    $dbMessages = json_decode($dbRow['messages_json'] ?? '[]', true);
    if (count($dbMessages) === 2) {
        test_pass('Messages persisted in database as JSON (2 entries in messages_json)');
    } else {
        test_fail('Messages DB persistence', 'expected 2 in DB, got ' . count($dbMessages));
    }
} catch (\Throwable $e) {
    test_fail('ConversationManager::appendMessage() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: ConversationManager::getMessages() parses JSON correctly
// ---------------------------------------------------------------------------
try {
    $manager = new \App\AIAssistant\ConversationManager();

    $conv = $manager->findById($conversationId);
    $messages = $manager->getMessages($conv);

    if (is_array($messages) && count($messages) === 2) {
        test_pass('getMessages() parses stored JSON into array with 2 messages');
    } else {
        test_fail('getMessages() parsing', 'expected array with 2 items');
    }

    // Test with invalid JSON
    $fakeConv = ['messages_json' => 'not-valid-json{{{'];
    $empty = $manager->getMessages($fakeConv);
    if ($empty === []) {
        test_pass('getMessages() returns empty array for invalid JSON');
    } else {
        test_fail('getMessages() invalid JSON', 'expected empty array');
    }

    // Test with missing key
    $noKey = [];
    $empty2 = $manager->getMessages($noKey);
    if ($empty2 === []) {
        test_pass('getMessages() returns empty array for missing messages_json key');
    } else {
        test_fail('getMessages() missing key', 'expected empty array');
    }
} catch (\Throwable $e) {
    test_fail('ConversationManager::getMessages() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: ConversationManager::getHistory() returns user conversations
// ---------------------------------------------------------------------------
try {
    $manager = new \App\AIAssistant\ConversationManager();

    $history = $manager->getHistory($testUserId, $testContentId);

    if (is_array($history) && count($history) >= 1) {
        test_pass('getHistory() returns at least 1 conversation for test user + content');
    } else {
        test_fail('getHistory() results', 'expected at least 1, got ' . count($history));
    }

    // Other user should have no conversations for this content
    $otherHistory = $manager->getHistory($otherUserId, $testContentId);
    if (count($otherHistory) === 0) {
        test_pass('getHistory() returns empty for different user (conversation isolation)');
    } else {
        test_fail('getHistory() user isolation', 'expected 0 for other user, got ' . count($otherHistory));
    }
} catch (\Throwable $e) {
    test_fail('ConversationManager::getHistory() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: ConversationManager::delete() removes conversation
// ---------------------------------------------------------------------------
try {
    $manager = new \App\AIAssistant\ConversationManager();

    // Create a throwaway conversation to delete
    $throwaway = $manager->findOrCreate($testUserId, null);
    $throwawayId = (int) $throwaway['id'];

    $manager->delete($throwawayId);

    $gone = $manager->findById($throwawayId);
    if ($gone === null) {
        test_pass('delete() removes conversation from database');
    } else {
        test_fail('delete() verification', 'conversation still exists after delete');
    }
} catch (\Throwable $e) {
    test_fail('ConversationManager::delete() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: Settings CRUD (save + load via DB)
// ---------------------------------------------------------------------------
try {
    // Directly test settings table operations (mimicking SettingsController internals)
    // Save a setting
    \App\Database\QueryBuilder::query('settings')->insert([
        'key'   => 'test_setting',
        'value' => 'hello world',
    ]);

    // Load it back
    $row = \App\Database\QueryBuilder::query('settings')
        ->select('value')
        ->where('key', 'test_setting')
        ->first();

    if ($row !== null && $row['value'] === 'hello world') {
        test_pass('Settings table: insert and select work correctly');
    } else {
        test_fail('Settings table CRUD', 'value mismatch or not found');
    }

    // Update the setting (upsert pattern)
    \App\Database\QueryBuilder::query('settings')
        ->where('key', 'test_setting')
        ->update(['value' => 'updated value']);

    $updated = \App\Database\QueryBuilder::query('settings')
        ->select('value')
        ->where('key', 'test_setting')
        ->first();

    if ($updated !== null && $updated['value'] === 'updated value') {
        test_pass('Settings table: update works correctly');
    } else {
        test_fail('Settings table update', 'value not updated');
    }

    // Test loading all settings as key-value map
    \App\Database\QueryBuilder::query('settings')->insert([
        'key'   => 'another_setting',
        'value' => 'foo',
    ]);

    $allRows = \App\Database\QueryBuilder::query('settings')->select('key', 'value')->get();
    $settingsMap = [];
    foreach ($allRows as $r) {
        $settingsMap[$r['key']] = $r['value'];
    }

    if (isset($settingsMap['test_setting']) && isset($settingsMap['another_setting'])) {
        test_pass('Settings load as key-value map with multiple entries');
    } else {
        test_fail('Settings load', 'missing expected keys in map');
    }
} catch (\Throwable $e) {
    test_fail('Settings CRUD works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: API key stored encrypted in settings (not plain text)
// ---------------------------------------------------------------------------
try {
    $plainKey = 'sk-ant-api03-my-secret-key-999';
    $encrypted = \App\AIAssistant\AIController::encrypt($plainKey);

    // Save to settings table as the SettingsController would
    $existing = \App\Database\QueryBuilder::query('settings')
        ->select('key')
        ->where('key', 'claude_api_key')
        ->first();

    if ($existing !== null) {
        \App\Database\QueryBuilder::query('settings')
            ->where('key', 'claude_api_key')
            ->update(['value' => $encrypted]);
    } else {
        \App\Database\QueryBuilder::query('settings')->insert([
            'key'   => 'claude_api_key',
            'value' => $encrypted,
        ]);
    }

    // Read it back
    $stored = \App\Database\QueryBuilder::query('settings')
        ->select('value')
        ->where('key', 'claude_api_key')
        ->first();

    $storedValue = $stored['value'] ?? '';

    if ($storedValue !== $plainKey) {
        test_pass('API key in settings table is NOT plain text');
    } else {
        test_fail('API key encryption', 'stored value matches plain text — not encrypted');
    }

    // Verify we can decrypt it back
    $recovered = \App\AIAssistant\AIController::decrypt($storedValue);
    if ($recovered === $plainKey) {
        test_pass('Encrypted API key in settings can be decrypted back to original');
    } else {
        test_fail('API key decrypt from settings', "expected '{$plainKey}', got '{$recovered}'");
    }
} catch (\Throwable $e) {
    test_fail('API key encryption storage works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: Routes registered for settings and AI endpoints
// ---------------------------------------------------------------------------
try {
    $indexPath = $rootDir . '/public/index.php';
    if (!file_exists($indexPath)) {
        test_fail('public/index.php exists', 'file not found');
    } else {
        $indexContent = file_get_contents($indexPath);

        // Check settings routes
        if (str_contains($indexContent, 'SettingsController') &&
            str_contains($indexContent, "'/settings'")) {
            test_pass('Settings routes registered in public/index.php');
        } else {
            test_fail('Settings routes in index.php', 'SettingsController or /settings route not found');
        }

        // Check AI routes
        if (str_contains($indexContent, 'AIController') &&
            str_contains($indexContent, "'/ai/chat'")) {
            test_pass('AI chat route registered in public/index.php');
        } else {
            test_fail('AI chat route in index.php', 'AIController or /ai/chat route not found');
        }

        // Check conversations route
        if (str_contains($indexContent, "'/ai/conversations'")) {
            test_pass('AI conversations route registered in public/index.php');
        } else {
            test_fail('AI conversations route in index.php', '/ai/conversations route not found');
        }

        // Verify the old placeholder is removed
        if (!str_contains($indexContent, 'Chunk 5.2') && !str_contains($indexContent, 'placeholder')) {
            test_pass('Settings placeholder route has been replaced');
        } else {
            test_fail('Settings placeholder removal', 'old placeholder text still found in index.php');
        }
    }
} catch (\Throwable $e) {
    test_fail('Route registration check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: CSRF middleware accepts X-CSRF-Token header
// ---------------------------------------------------------------------------
try {
    $csrfPath = $rootDir . '/app/Auth/CsrfMiddleware.php';
    if (!file_exists($csrfPath)) {
        test_fail('CsrfMiddleware.php exists', 'file not found');
    } else {
        $csrfContent = file_get_contents($csrfPath);

        if (str_contains($csrfContent, 'HTTP_X_CSRF_TOKEN') ||
            str_contains($csrfContent, 'X-CSRF-Token') ||
            str_contains($csrfContent, 'X_CSRF_TOKEN')) {
            test_pass('CsrfMiddleware supports header-based CSRF token (X-CSRF-Token)');
        } else {
            test_fail('CsrfMiddleware header support', 'no X-CSRF-Token header check found');
        }
    }
} catch (\Throwable $e) {
    test_fail('CSRF middleware check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: ClaudeClient constructs successfully with valid params
// ---------------------------------------------------------------------------
try {
    $client = new \App\AIAssistant\ClaudeClient('sk-ant-test-key', 'claude-sonnet-4-20250514');

    // The client should construct without error — we can't call sendMessage
    // without hitting the real API, but construction should work
    if ($client instanceof \App\AIAssistant\ClaudeClient) {
        test_pass('ClaudeClient constructs with API key and model');
    } else {
        test_fail('ClaudeClient construction', 'unexpected type');
    }

    // Test with a fake API key — sendMessage should fail at network level (not key validation)
    // We SKIP the actual API call test since it requires a real key
    test_skip('ClaudeClient::sendMessage() with real API key (requires valid key — test manually)');
} catch (\Throwable $e) {
    test_fail('ClaudeClient construction works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: Conversation ownership (user cannot access another's conversation)
// ---------------------------------------------------------------------------
try {
    $manager = new \App\AIAssistant\ConversationManager();

    // The conversation created in test 4 belongs to $testUserId
    // findOrCreate for $otherUserId should NOT return the same conversation
    $otherConv = $manager->findOrCreate($otherUserId, $testContentId);

    if ((int) $otherConv['id'] !== $conversationId) {
        test_pass('Different user gets a separate conversation for same content (ownership isolation)');
    } else {
        test_fail('Conversation ownership', 'other user got the same conversation id');
    }

    // Verify findById can still get any conversation (it's a raw lookup)
    $directLookup = $manager->findById($conversationId);
    if ($directLookup !== null && (int) $directLookup['user_id'] === $testUserId) {
        test_pass('findById() returns conversation with correct user_id for ownership verification');
    } else {
        test_fail('findById() ownership data', 'missing or wrong user_id');
    }
} catch (\Throwable $e) {
    test_fail('Conversation ownership check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: Settings template exists
// ---------------------------------------------------------------------------
$settingsTemplate = $rootDir . '/templates/admin/settings.php';
if (file_exists($settingsTemplate)) {
    $tplContent = file_get_contents($settingsTemplate);

    if (str_contains($tplContent, 'claude_api_key') && str_contains($tplContent, 'claude_model')) {
        test_pass('Settings template exists with API key and model fields');
    } else {
        test_fail('Settings template content', 'missing claude_api_key or claude_model fields');
    }

    if (str_contains($tplContent, 'type="password"') || str_contains($tplContent, "type='password'")) {
        test_pass('API key field uses type="password" (not displayed as plain text)');
    } else {
        test_fail('API key field type', 'expected type="password" for API key input');
    }

    if (str_contains($tplContent, 'csrfField')) {
        test_pass('Settings form includes CSRF token field');
    } else {
        test_fail('Settings form CSRF', 'csrfField() not found in template');
    }
} else {
    test_fail('Settings template exists', 'templates/admin/settings.php not found');
}

// ---------------------------------------------------------------------------
// Cleanup: remove the test database
// ---------------------------------------------------------------------------
\App\Database\Connection::reset();

// Reset Config so other tests are not affected
$configProp->setValue(null, null);
putenv('DB_DRIVER');
putenv('DB_PATH');
putenv('APP_SECRET');

if (file_exists($testDbPath)) {
    unlink($testDbPath);
}

// Also clean up WAL/SHM files if they exist
$walPath = $testDbPath . '-wal';
$shmPath = $testDbPath . '-shm';
if (file_exists($walPath)) {
    unlink($walPath);
}
if (file_exists($shmPath)) {
    unlink($shmPath);
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n";
echo "Chunk 4.1 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
