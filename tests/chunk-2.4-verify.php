<?php declare(strict_types=1);

/**
 * Chunk 2.4 — User Management
 * Automated Verification Tests
 *
 * Tests:
 *   1. UserController class is autoloadable
 *   2. Required files exist (controller, templates)
 *   3. UserController::index() renders user list with 200 status (admin role)
 *   [SMOKE STOP]
 *   4. UserController::create() renders create form
 *   5. UserController::store() creates a new user and redirects
 *   6. Created user appears in user list and can be fetched from DB
 *   7. Password is stored as bcrypt hash (not plain text)
 *   8. UserController::edit() renders edit form with existing data
 *   9. UserController::update() persists username, email, and role changes
 *  10. Password change on own account requires current password
 *  11. Admin can reset another user's password without current password
 *  12. Self-role-change is prevented
 *  13. Self-deletion is prevented
 *  14. UserController::delete() removes user and redirects
 *  15. Deleting user with content reassigns content to target user
 *  16. Editor role gets 403 on user management pages
 *  17. Validation rejects empty username
 *  18. Validation rejects invalid email
 *  19. Validation rejects duplicate username
 *  20. Validation rejects duplicate email
 *  21. Search by username/email works
 *  22. Pagination works when users exceed per-page limit
 *  23. Routes registered in index.php (no placeholder)
 *  24. Edit form includes CSRF field and _method hidden input
 *  25. Response includes security headers (X-Frame-Options, CSP)
 *  26. Session is updated when editing own username
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
$testDbPath = $rootDir . '/storage/test_chunk24.sqlite';

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

// Set up session data to simulate logged-in admin (user_id=1)
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

// Helper: clear flash messages
function clearFlash(): void
{
    \App\Auth\Session::flash('success');
    \App\Auth\Session::flash('error');
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
    echo "Chunk 2.4 results: {$pass} passed, {$fail} failed\n";
}

// ---------------------------------------------------------------------------
// Test 1: UserController class is autoloadable
// ---------------------------------------------------------------------------
$controllerClass = 'App\\Admin\\UserController';

if (!class_exists($controllerClass)) {
    test_fail('UserController is autoloadable', "class {$controllerClass} not found");
    cleanup();
    exit(1);
} else {
    test_pass('UserController is autoloadable');
}

// ---------------------------------------------------------------------------
// Test 2: Required files exist
// ---------------------------------------------------------------------------
$requiredFiles = [
    'app/Admin/UserController.php'          => 'UserController class file',
    'templates/admin/users/index.php'       => 'User list template',
    'templates/admin/users/edit.php'        => 'User edit template',
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
    test_pass('All required files exist: UserController, users/index template, users/edit template');
}

// ---------------------------------------------------------------------------
// Test 3: UserController::index() renders user list with 200 status (admin)
// ---------------------------------------------------------------------------
$app = null;
$controller = null;

try {
    $app = new \App\Core\App();
    $app->register('db', $pdo);

    $controller = new \App\Admin\UserController($app);

    clearFlash();

    $request = makeRequest('GET', '/admin/users');
    $response = $controller->index($request);
    $html = $response->getBody();

    if ($response->getStatus() === 200 && strlen($html) > 100) {
        $hasAdminUser = str_contains($html, 'admin');
        $hasTable = str_contains($html, '<table') || str_contains($html, 'data-table');

        if ($hasAdminUser && $hasTable) {
            test_pass('UserController::index() returns 200 with user list showing admin user');
        } else {
            test_fail('UserController::index() content', "hasAdmin={$hasAdminUser}, hasTable={$hasTable}");
        }
    } else {
        test_fail('UserController::index() returns 200', "status={$response->getStatus()}, bodyLen=" . strlen($html));
    }
} catch (\Throwable $e) {
    test_fail('UserController::index() works without errors', $e->getMessage());
}

if ($controller === null) {
    echo "\n[FAIL] Cannot continue — UserController not available\n";
    cleanup();
    exit(1);
}

if ($isSmoke) {
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    cleanup();
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: UserController::create() renders create form
// ---------------------------------------------------------------------------
try {
    clearFlash();

    $request = makeRequest('GET', '/admin/users/create');
    $response = $controller->create($request);
    $html = $response->getBody();

    $hasForm = str_contains($html, '<form');
    $hasUsername = str_contains($html, 'username');
    $hasEmail = str_contains($html, 'email');
    $hasPassword = str_contains($html, 'password');
    $hasRole = str_contains($html, 'role');

    if ($response->getStatus() === 200 && $hasForm && $hasUsername && $hasEmail && $hasPassword && $hasRole) {
        test_pass('create() returns 200 with form containing username, email, password, and role fields');
    } else {
        test_fail('create() form', "status={$response->getStatus()}, form={$hasForm}, user={$hasUsername}, email={$hasEmail}");
    }
} catch (\Throwable $e) {
    test_fail('create() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: UserController::store() creates a new user and redirects
// ---------------------------------------------------------------------------
$editorId = null;
try {
    clearFlash();

    $countBefore = \App\Database\QueryBuilder::query('users')->select()->count();

    $request = makeRequest('POST', '/admin/users', [
        'username'    => 'editor1',
        'email'       => 'editor1@example.com',
        'password'    => 'password123',
        'role'        => 'editor',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->store($request);
    $status = $response->getStatus();
    $headers = $response->getHeaders();
    $location = $headers['Location'] ?? '';

    $countAfter = \App\Database\QueryBuilder::query('users')->select()->count();

    if ($status === 302 && $countAfter === $countBefore + 1) {
        // Find the created user
        $editor = \App\Database\QueryBuilder::query('users')
            ->select()
            ->where('username', 'editor1')
            ->first();
        if ($editor !== null) {
            $editorId = (string) $editor['id'];
        }
        test_pass("store() creates user and redirects (count: {$countBefore} → {$countAfter})");
    } else {
        test_fail('store() user creation', "status={$status}, countBefore={$countBefore}, countAfter={$countAfter}");
    }
} catch (\Throwable $e) {
    test_fail('store() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: Created user appears in user list and DB
// ---------------------------------------------------------------------------
try {
    clearFlash();

    $editor = \App\Database\QueryBuilder::query('users')
        ->select()
        ->where('username', 'editor1')
        ->first();

    if ($editor !== null
        && $editor['email'] === 'editor1@example.com'
        && $editor['role'] === 'editor') {
        test_pass('Created user persisted with correct username, email, and role');
    } else {
        test_fail('User persistence', 'user not found or data mismatch');
    }

    // Also verify they appear in the index listing
    $request = makeRequest('GET', '/admin/users');
    $response = $controller->index($request);
    $html = $response->getBody();

    if (str_contains($html, 'editor1')) {
        test_pass('Created user "editor1" appears in user list');
    } else {
        test_fail('User in list', '"editor1" not found in index HTML');
    }
} catch (\Throwable $e) {
    test_fail('User list check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: Password is stored as bcrypt hash (not plain text)
// ---------------------------------------------------------------------------
try {
    $editor = \App\Database\QueryBuilder::query('users')
        ->select()
        ->where('username', 'editor1')
        ->first();

    if ($editor !== null) {
        $hash = $editor['password_hash'];
        $isBcrypt = str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2b$');
        $verifies = password_verify('password123', $hash);
        $notPlain = ($hash !== 'password123');

        if ($isBcrypt && $verifies && $notPlain) {
            test_pass('Password stored as bcrypt hash, verifies correctly, not plain text');
        } else {
            test_fail('Password hashing', "bcrypt={$isBcrypt}, verifies={$verifies}, notPlain={$notPlain}");
        }
    } else {
        test_fail('Password hash check', 'editor1 user not found');
    }
} catch (\Throwable $e) {
    test_fail('Password hashing check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: UserController::edit() renders edit form with existing data
// ---------------------------------------------------------------------------
try {
    clearFlash();

    if ($editorId !== null) {
        $request = makeRequest('GET', "/admin/users/{$editorId}/edit");
        $response = $controller->edit($request, $editorId);
        $html = $response->getBody();

        $hasUsername = str_contains($html, 'editor1');
        $hasEmail = str_contains($html, 'editor1@example.com');
        $hasForm = str_contains($html, '<form');

        if ($response->getStatus() === 200 && $hasUsername && $hasEmail && $hasForm) {
            test_pass('edit() renders form with existing username and email');
        } else {
            test_fail('edit() content', "status={$response->getStatus()}, user={$hasUsername}, email={$hasEmail}");
        }
    } else {
        test_skip('edit() rendering — no editor user ID from store test');
    }
} catch (\Throwable $e) {
    test_fail('edit() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: UserController::update() persists username, email, and role changes
// ---------------------------------------------------------------------------
try {
    clearFlash();

    if ($editorId !== null) {
        $request = makeRequest('POST', "/admin/users/{$editorId}", [
            '_method'     => 'PUT',
            'username'    => 'editor1_updated',
            'email'       => 'editor1_updated@example.com',
            'password'    => '',
            'role'        => 'admin',
            '_csrf_token' => $_SESSION['csrf_token'],
        ]);

        $response = $controller->update($request, $editorId);

        $updated = \App\Database\QueryBuilder::query('users')
            ->select()
            ->where('id', (int) $editorId)
            ->first();

        if ($response->getStatus() === 302
            && $updated !== null
            && $updated['username'] === 'editor1_updated'
            && $updated['email'] === 'editor1_updated@example.com'
            && $updated['role'] === 'admin') {
            test_pass('update() persists username, email, and role changes');
        } else {
            test_fail('update() persistence',
                "status={$response->getStatus()}, username=" . ($updated['username'] ?? 'null')
                . ", role=" . ($updated['role'] ?? 'null'));
        }

        // Restore to editor for subsequent tests
        \App\Database\QueryBuilder::query('users')
            ->where('id', (int) $editorId)
            ->update(['username' => 'editor1', 'email' => 'editor1@example.com', 'role' => 'editor']);
    } else {
        test_skip('update() — no editor user ID');
    }
} catch (\Throwable $e) {
    test_fail('update() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: Password change on own account requires current password
// ---------------------------------------------------------------------------
try {
    clearFlash();

    // Try to change admin's own password WITHOUT providing current password
    $adminId = '1';
    $request = makeRequest('POST', "/admin/users/{$adminId}", [
        '_method'          => 'PUT',
        'username'         => 'admin',
        'email'            => 'admin@localhost',
        'password'         => 'newpassword123',
        'current_password' => '',
        'role'             => 'admin',
        '_csrf_token'      => $_SESSION['csrf_token'],
    ]);

    $response = $controller->update($request, $adminId);

    // Password should NOT have changed
    $adminRow = \App\Database\QueryBuilder::query('users')
        ->select()
        ->where('id', 1)
        ->first();

    $oldPasswordStillWorks = password_verify('admin', $adminRow['password_hash']);

    if ($response->getStatus() === 302 && $oldPasswordStillWorks) {
        test_pass('Self password change rejected when current password not provided');
    } else {
        test_fail('Self password protection', "status={$response->getStatus()}, oldPwWorks={$oldPasswordStillWorks}");
    }

    // Now try WITH correct current password
    clearFlash();
    $request = makeRequest('POST', "/admin/users/{$adminId}", [
        '_method'          => 'PUT',
        'username'         => 'admin',
        'email'            => 'admin@localhost',
        'password'         => 'newadminpass',
        'current_password' => 'admin',
        'role'             => 'admin',
        '_csrf_token'      => $_SESSION['csrf_token'],
    ]);

    $response = $controller->update($request, $adminId);

    $adminRow = \App\Database\QueryBuilder::query('users')
        ->select()
        ->where('id', 1)
        ->first();

    $newPasswordWorks = password_verify('newadminpass', $adminRow['password_hash']);

    if ($response->getStatus() === 302 && $newPasswordWorks) {
        test_pass('Self password change succeeds with correct current password');
    } else {
        test_fail('Self password change with current pw', "status={$response->getStatus()}, newPwWorks={$newPasswordWorks}");
    }

    // Restore admin password for remaining tests
    \App\Database\QueryBuilder::query('users')
        ->where('id', 1)
        ->update(['password_hash' => password_hash('admin', PASSWORD_BCRYPT)]);
} catch (\Throwable $e) {
    test_fail('Self password change check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: Admin can reset another user's password without current password
// ---------------------------------------------------------------------------
try {
    clearFlash();

    if ($editorId !== null) {
        $request = makeRequest('POST', "/admin/users/{$editorId}", [
            '_method'     => 'PUT',
            'username'    => 'editor1',
            'email'       => 'editor1@example.com',
            'password'    => 'resetpassword',
            'role'        => 'editor',
            '_csrf_token' => $_SESSION['csrf_token'],
        ]);

        $response = $controller->update($request, $editorId);

        $editorRow = \App\Database\QueryBuilder::query('users')
            ->select()
            ->where('id', (int) $editorId)
            ->first();

        $resetWorks = password_verify('resetpassword', $editorRow['password_hash']);

        if ($response->getStatus() === 302 && $resetWorks) {
            test_pass('Admin can reset another user\'s password without providing current password');
        } else {
            test_fail('Admin password reset', "status={$response->getStatus()}, resetWorks={$resetWorks}");
        }
    } else {
        test_skip('Admin password reset — no editor user ID');
    }
} catch (\Throwable $e) {
    test_fail('Admin password reset works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: Self-role-change is prevented
// ---------------------------------------------------------------------------
try {
    clearFlash();

    $adminId = '1';
    $request = makeRequest('POST', "/admin/users/{$adminId}", [
        '_method'     => 'PUT',
        'username'    => 'admin',
        'email'       => 'admin@localhost',
        'password'    => '',
        'role'        => 'editor',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->update($request, $adminId);

    $adminRow = \App\Database\QueryBuilder::query('users')
        ->select()
        ->where('id', 1)
        ->first();

    if ($adminRow['role'] === 'admin') {
        test_pass('Self-role-change prevented — admin role unchanged after attempting to set to editor');
    } else {
        test_fail('Self-role-change prevention', "role is now: {$adminRow['role']}");
    }
} catch (\Throwable $e) {
    test_fail('Self-role-change check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: Self-deletion is prevented
// ---------------------------------------------------------------------------
try {
    clearFlash();

    $adminId = '1';
    $request = makeRequest('POST', "/admin/users/{$adminId}", [
        '_method'     => 'DELETE',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->delete($request, $adminId);

    $adminStillExists = \App\Database\QueryBuilder::query('users')
        ->select()
        ->where('id', 1)
        ->first();

    if ($response->getStatus() === 302 && $adminStillExists !== null) {
        test_pass('Self-deletion prevented — admin user still exists after delete attempt');
    } else {
        test_fail('Self-deletion prevention', "status={$response->getStatus()}, exists=" . ($adminStillExists !== null ? 'yes' : 'no'));
    }
} catch (\Throwable $e) {
    test_fail('Self-deletion check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: UserController::delete() removes user and redirects
// ---------------------------------------------------------------------------
$deleteTestId = null;
try {
    clearFlash();

    // Create a user to delete (one without content)
    $deleteTestId = \App\Database\QueryBuilder::query('users')->insert([
        'username'      => 'todelete',
        'email'         => 'todelete@example.com',
        'password_hash' => password_hash('pass123', PASSWORD_BCRYPT),
        'role'          => 'editor',
    ]);

    $request = makeRequest('POST', "/admin/users/{$deleteTestId}", [
        '_method'     => 'DELETE',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->delete($request, (string) $deleteTestId);

    $gone = \App\Database\QueryBuilder::query('users')
        ->select()
        ->where('id', (int) $deleteTestId)
        ->first();

    if ($response->getStatus() === 302 && $gone === null) {
        test_pass('delete() removes user from database and redirects');
    } else {
        test_fail('delete()', "status={$response->getStatus()}, still_exists=" . ($gone !== null ? 'yes' : 'no'));
    }
} catch (\Throwable $e) {
    test_fail('delete() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: Deleting user with content reassigns content to target user
// ---------------------------------------------------------------------------
try {
    clearFlash();

    // Create a user who will have content
    $authorId = \App\Database\QueryBuilder::query('users')->insert([
        'username'      => 'author_user',
        'email'         => 'author@example.com',
        'password_hash' => password_hash('pass123', PASSWORD_BCRYPT),
        'role'          => 'editor',
    ]);

    // Create content authored by this user
    $contentId = \App\Database\QueryBuilder::query('content')->insert([
        'type'      => 'page',
        'title'     => 'Author Test Page',
        'slug'      => 'author-test-page',
        'body'      => '<p>Test content</p>',
        'status'    => 'published',
        'author_id' => $authorId,
    ]);

    // Delete the author, reassigning content to admin (id=1)
    $request = makeRequest('POST', "/admin/users/{$authorId}", [
        '_method'      => 'DELETE',
        'reassign_to'  => '1',
        '_csrf_token'  => $_SESSION['csrf_token'],
    ]);

    $response = $controller->delete($request, (string) $authorId);

    $authorGone = \App\Database\QueryBuilder::query('users')
        ->select()
        ->where('id', (int) $authorId)
        ->first();

    $content = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('id', (int) $contentId)
        ->first();

    if ($authorGone === null
        && $content !== null
        && (int) $content['author_id'] === 1) {
        test_pass('Deleted user\'s content reassigned to admin (author_id=1)');
    } else {
        $actualAuthor = $content['author_id'] ?? 'null';
        test_fail('Content reassignment',
            "authorGone=" . ($authorGone === null ? 'yes' : 'no')
            . ", contentExists=" . ($content !== null ? 'yes' : 'no')
            . ", author_id={$actualAuthor}");
    }

    // Clean up test content
    \App\Database\QueryBuilder::query('content')->where('id', (int) $contentId)->delete();
} catch (\Throwable $e) {
    test_fail('Content reassignment works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: Editor role gets 403 on user management pages
// ---------------------------------------------------------------------------
try {
    clearFlash();

    // Temporarily switch session to editor role
    $savedRole = $_SESSION['user_role'];
    $savedId = $_SESSION['user_id'];
    $_SESSION['user_role'] = 'editor';
    $_SESSION['user_id'] = (int) ($editorId ?? 2);

    $request = makeRequest('GET', '/admin/users');
    $response = $controller->index($request);

    $is403 = ($response->getStatus() === 403);

    // Also check create
    clearFlash();
    $request = makeRequest('GET', '/admin/users/create');
    $response2 = $controller->create($request);
    $create403 = ($response2->getStatus() === 403);

    // Restore admin session
    $_SESSION['user_role'] = $savedRole;
    $_SESSION['user_id'] = $savedId;

    if ($is403 && $create403) {
        test_pass('Editor role gets 403 on index and create (role enforcement works)');
    } else {
        test_fail('Role enforcement', "index403={$is403}, create403={$create403}");
    }
} catch (\Throwable $e) {
    // Restore session in case of error
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_id'] = 1;
    test_fail('Role enforcement works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 17: Validation rejects empty username
// ---------------------------------------------------------------------------
try {
    clearFlash();

    $countBefore = \App\Database\QueryBuilder::query('users')->select()->count();

    $request = makeRequest('POST', '/admin/users', [
        'username'    => '',
        'email'       => 'empty@example.com',
        'password'    => 'password123',
        'role'        => 'editor',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->store($request);
    $countAfter = \App\Database\QueryBuilder::query('users')->select()->count();

    if ($response->getStatus() === 302 && $countAfter === $countBefore) {
        test_pass('Validation rejects empty username — no new user inserted');
    } else {
        test_fail('Empty username validation', "countBefore={$countBefore}, countAfter={$countAfter}");
    }
} catch (\Throwable $e) {
    test_fail('Empty username validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: Validation rejects invalid email
// ---------------------------------------------------------------------------
try {
    clearFlash();

    $countBefore = \App\Database\QueryBuilder::query('users')->select()->count();

    $request = makeRequest('POST', '/admin/users', [
        'username'    => 'invalidemailuser',
        'email'       => 'not-an-email',
        'password'    => 'password123',
        'role'        => 'editor',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->store($request);
    $countAfter = \App\Database\QueryBuilder::query('users')->select()->count();

    if ($response->getStatus() === 302 && $countAfter === $countBefore) {
        test_pass('Validation rejects invalid email — no new user inserted');
    } else {
        test_fail('Invalid email validation', "countBefore={$countBefore}, countAfter={$countAfter}");
    }
} catch (\Throwable $e) {
    test_fail('Invalid email validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: Validation rejects duplicate username
// ---------------------------------------------------------------------------
try {
    clearFlash();

    $countBefore = \App\Database\QueryBuilder::query('users')->select()->count();

    $request = makeRequest('POST', '/admin/users', [
        'username'    => 'admin',
        'email'       => 'newadmin@example.com',
        'password'    => 'password123',
        'role'        => 'editor',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->store($request);
    $countAfter = \App\Database\QueryBuilder::query('users')->select()->count();

    if ($response->getStatus() === 302 && $countAfter === $countBefore) {
        test_pass('Validation rejects duplicate username "admin" — no new user inserted');
    } else {
        test_fail('Duplicate username validation', "countBefore={$countBefore}, countAfter={$countAfter}");
    }
} catch (\Throwable $e) {
    test_fail('Duplicate username validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 20: Validation rejects duplicate email
// ---------------------------------------------------------------------------
try {
    clearFlash();

    $countBefore = \App\Database\QueryBuilder::query('users')->select()->count();

    $request = makeRequest('POST', '/admin/users', [
        'username'    => 'uniqueuser',
        'email'       => 'admin@localhost',
        'password'    => 'password123',
        'role'        => 'editor',
        '_csrf_token' => $_SESSION['csrf_token'],
    ]);

    $response = $controller->store($request);
    $countAfter = \App\Database\QueryBuilder::query('users')->select()->count();

    if ($response->getStatus() === 302 && $countAfter === $countBefore) {
        test_pass('Validation rejects duplicate email "admin@localhost" — no new user inserted');
    } else {
        test_fail('Duplicate email validation', "countBefore={$countBefore}, countAfter={$countAfter}");
    }
} catch (\Throwable $e) {
    test_fail('Duplicate email validation works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 21: Search by username/email works
// ---------------------------------------------------------------------------
try {
    clearFlash();

    // Search by username
    $request = makeRequest('GET', '/admin/users', [], ['q' => 'editor1']);
    $response = $controller->index($request);
    $html = $response->getBody();

    $hasEditor = str_contains($html, 'editor1');
    // "admin" as a username may or may not appear (the word "admin" appears in layout),
    // so check that the page rendered with search results

    if ($hasEditor) {
        test_pass('Search for "editor1" finds the editor user');
    } else {
        test_fail('Search by username', '"editor1" not found in search results');
    }

    // Search by email
    clearFlash();
    $request = makeRequest('GET', '/admin/users', [], ['q' => 'editor1@example.com']);
    $response = $controller->index($request);
    $html = $response->getBody();

    if (str_contains($html, 'editor1')) {
        test_pass('Search by email "editor1@example.com" finds the editor user');
    } else {
        test_fail('Search by email', '"editor1" not found when searching by email');
    }
} catch (\Throwable $e) {
    test_fail('Search works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 22: Pagination works when users exceed per-page limit
// ---------------------------------------------------------------------------
try {
    clearFlash();

    // Insert enough users to exceed default items_per_page (10)
    $paginationIds = [];
    for ($i = 1; $i <= 12; $i++) {
        $paginationIds[] = \App\Database\QueryBuilder::query('users')->insert([
            'username'      => "paguser{$i}",
            'email'         => "paguser{$i}@example.com",
            'password_hash' => password_hash('pass', PASSWORD_BCRYPT),
            'role'          => 'editor',
        ]);
    }

    // Page 1
    $request = makeRequest('GET', '/admin/users', [], ['page' => '1']);
    $response = $controller->index($request);
    $page1Html = $response->getBody();

    clearFlash();

    // Page 2
    $request = makeRequest('GET', '/admin/users', [], ['page' => '2']);
    $response = $controller->index($request);
    $page2Html = $response->getBody();

    $hasNextOnPage1 = str_contains($page1Html, 'Next') || str_contains($page1Html, 'page=2');
    $hasPrevOnPage2 = str_contains($page2Html, 'Prev') || str_contains($page2Html, 'page=1');

    if ($hasNextOnPage1 && $hasPrevOnPage2) {
        test_pass('Pagination: page 1 has Next link, page 2 has Prev link');
    } else {
        test_fail('Pagination links', "nextOnPage1={$hasNextOnPage1}, prevOnPage2={$hasPrevOnPage2}");
    }

    // Cleanup pagination users
    foreach ($paginationIds as $pid) {
        \App\Database\QueryBuilder::query('users')->where('id', (int) $pid)->delete();
    }
} catch (\Throwable $e) {
    test_fail('Pagination works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 23: Routes registered in index.php (no placeholder)
// ---------------------------------------------------------------------------
try {
    $indexContent = file_get_contents($rootDir . '/public/index.php');

    $hasUserImport = stripos($indexContent, 'UserController') !== false;
    $hasGetUsers = stripos($indexContent, "'/users'") !== false || stripos($indexContent, '"/users"') !== false;
    $hasCreateRoute = stripos($indexContent, '/users/create') !== false;
    $hasEditRoute = stripos($indexContent, '/users/{id}/edit') !== false;
    $hasDeleteRoute = stripos($indexContent, '/users/{id}') !== false;

    // Should NOT have the old placeholder
    $hasOldPlaceholder = stripos($indexContent, 'User management is coming') !== false;

    if ($hasUserImport && $hasGetUsers && $hasCreateRoute && $hasEditRoute && $hasDeleteRoute && !$hasOldPlaceholder) {
        test_pass('index.php has UserController import, CRUD routes, and no placeholder');
    } else {
        test_fail('User routes',
            "import={$hasUserImport}, get={$hasGetUsers}, create={$hasCreateRoute}, "
            . "edit={$hasEditRoute}, delete={$hasDeleteRoute}, placeholder={$hasOldPlaceholder}");
    }
} catch (\Throwable $e) {
    test_fail('Routes check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 24: Edit form includes CSRF field and _method hidden input
// ---------------------------------------------------------------------------
try {
    clearFlash();

    if ($editorId !== null) {
        $request = makeRequest('GET', "/admin/users/{$editorId}/edit");
        $response = $controller->edit($request, $editorId);
        $html = $response->getBody();

        $hasCsrf = str_contains($html, '_csrf_token');
        $hasMethodOverride = str_contains($html, '_method') && str_contains($html, 'PUT');

        if ($hasCsrf && $hasMethodOverride) {
            test_pass('Edit form includes CSRF token field and _method=PUT hidden input');
        } else {
            test_fail('Form security fields', "csrf={$hasCsrf}, methodOverride={$hasMethodOverride}");
        }
    } else {
        test_skip('Form security fields — no editor user ID available');
    }
} catch (\Throwable $e) {
    test_fail('Form security fields check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 25: Response includes security headers (X-Frame-Options, CSP)
// ---------------------------------------------------------------------------
try {
    clearFlash();

    $request = makeRequest('GET', '/admin/users');
    $response = $controller->index($request);
    $headers = $response->getHeaders();

    $xFrame = $headers['X-Frame-Options'] ?? '';
    $csp = $headers['Content-Security-Policy'] ?? '';

    $hasXFrame = ($xFrame === 'DENY');
    $hasCsp = str_contains($csp, "default-src 'self'");

    if ($hasXFrame && $hasCsp) {
        test_pass('User list response has X-Frame-Options: DENY and CSP with default-src self');
    } else {
        test_fail('Security headers', "X-Frame-Options={$xFrame}, CSP=" . substr($csp, 0, 80));
    }
} catch (\Throwable $e) {
    test_fail('Security headers check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 26: Session is updated when editing own username
// ---------------------------------------------------------------------------
try {
    clearFlash();

    $adminId = '1';
    $request = makeRequest('POST', "/admin/users/{$adminId}", [
        '_method'          => 'PUT',
        'username'         => 'admin_renamed',
        'email'            => 'admin@localhost',
        'password'         => '',
        'current_password' => '',
        'role'             => 'admin',
        '_csrf_token'      => $_SESSION['csrf_token'],
    ]);

    $response = $controller->update($request, $adminId);

    $sessionName = $_SESSION['user_name'] ?? '';

    if ($sessionName === 'admin_renamed') {
        test_pass('Session user_name updated when admin edits own username');
    } else {
        test_fail('Session sync', "expected 'admin_renamed', got '{$sessionName}'");
    }

    // Restore admin username
    \App\Database\QueryBuilder::query('users')
        ->where('id', 1)
        ->update(['username' => 'admin']);
    $_SESSION['user_name'] = 'admin';
} catch (\Throwable $e) {
    test_fail('Session sync check works without errors', $e->getMessage());
    $_SESSION['user_name'] = 'admin';
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
cleanup();

exit($fail > 0 ? 1 : 0);
