<?php declare(strict_types=1);

/**
 * Chunk 1.2 — Database Layer & Migrations
 * Automated Verification Tests
 *
 * Tests:
 *   1. Database classes are autoloadable
 *   2. Connection creates a valid PDO instance (SQLite)
 *   3. Migrator runs initial migration and creates all 7 tables
 *   4. Migrations are idempotent (running twice is safe)
 *   5. QueryBuilder can INSERT and SELECT from users table
 *   6. QueryBuilder supports where(), orderBy(), limit() chains
 *   7. QueryBuilder supports update() and delete()
 *   8. QueryBuilder count() works
 *   9. All 7 tables exist with correct names
 *  10. Migration files exist for all 3 drivers
 *  11. Connection driver detection works
 *  12. Database is registered in App container via index.php bootstrap
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
// Setup: use a dedicated test database to avoid polluting the real one
// ---------------------------------------------------------------------------
$testDbPath = $rootDir . '/storage/test_chunk12.sqlite';

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
// We need to set the environment before Config is loaded
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $testDbPath);

// Force Config to reload (reset cached config if any)
// Config uses a static property, so we need to reset it
$configReflection = new ReflectionClass(\App\Core\Config::class);
$configProp = $configReflection->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, null);

// Also reset the Connection singleton so it uses the test config
if (class_exists(\App\Database\Connection::class)) {
    \App\Database\Connection::reset();
}

// ---------------------------------------------------------------------------
// Test 1: Database classes are autoloadable
// ---------------------------------------------------------------------------
$requiredClasses = [
    'App\\Database\\Connection',
    'App\\Database\\QueryBuilder',
    'App\\Database\\Migrator',
];

$allClassesFound = true;
foreach ($requiredClasses as $class) {
    if (!class_exists($class)) {
        test_fail("Class {$class} is autoloadable", 'class not found');
        $allClassesFound = false;
    }
}
if ($allClassesFound) {
    test_pass('All database classes are autoloadable (Connection, QueryBuilder, Migrator)');
}

// ---------------------------------------------------------------------------
// Test 2: Connection creates a valid PDO instance (SQLite)
// ---------------------------------------------------------------------------
$pdo = null;
try {
    $pdo = \App\Database\Connection::getInstance();
    if ($pdo instanceof PDO) {
        test_pass('Connection::getInstance() returns a valid PDO instance');
    } else {
        test_fail('Connection::getInstance() returns a valid PDO instance', 'returned non-PDO value');
    }

    // Verify the SQLite file was created
    if (file_exists($testDbPath)) {
        test_pass('SQLite database file auto-created at configured path');
    } else {
        test_fail('SQLite database file auto-created', "expected file at {$testDbPath}");
    }
} catch (\Throwable $e) {
    test_fail('Connection::getInstance() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 3: Migrator runs initial migration and creates tables
// ---------------------------------------------------------------------------
$migrator = null;
try {
    $migrator = new \App\Database\Migrator($pdo);
    $applied = $migrator->migrate();

    if (!empty($applied)) {
        test_pass('Migrator::migrate() applied initial migration: ' . implode(', ', $applied));
    } else {
        // Maybe migration was already applied — check if tables exist
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        $result = $stmt->fetch();
        if ($result) {
            test_pass('Migrator::migrate() — tables already exist (migration previously applied)');
        } else {
            test_fail('Migrator::migrate() applied initial migration', 'no migrations applied and no tables found');
        }
    }
} catch (\Throwable $e) {
    test_fail('Migrator runs without errors', $e->getMessage());
}

if ($isSmoke) {
    // Smoke mode — stop here
    // Clean up test database
    \App\Database\Connection::reset();
    if (file_exists($testDbPath)) {
        unlink($testDbPath);
    }
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 1.2 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: Migrations are idempotent (running twice is safe)
// ---------------------------------------------------------------------------
try {
    $secondRun = $migrator->migrate();

    if (empty($secondRun)) {
        test_pass('Migrations are idempotent — second run applies nothing');
    } else {
        test_fail('Migrations are idempotent', 'second run applied: ' . implode(', ', $secondRun));
    }

    // Verify _migrations table has exactly 1 row
    $stmt = $pdo->query('SELECT COUNT(*) as cnt FROM _migrations');
    $row = $stmt->fetch();
    $migrationCount = (int) ($row['cnt'] ?? 0);

    if ($migrationCount >= 1) {
        test_pass("_migrations table has {$migrationCount} record(s) (includes 001_initial)");
    } else {
        test_fail('_migrations table has at least 1 record', "found {$migrationCount} records");
    }
} catch (\Throwable $e) {
    test_fail('Idempotent migration check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: QueryBuilder can INSERT and SELECT from users table
// ---------------------------------------------------------------------------
$insertedUserId = null;
try {
    $insertedUserId = \App\Database\QueryBuilder::query('users')->insert([
        'username'      => 'testuser',
        'email'         => 'test@example.com',
        'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
        'role'          => 'admin',
    ]);

    if (!empty($insertedUserId)) {
        test_pass("QueryBuilder INSERT returns lastInsertId: {$insertedUserId}");
    } else {
        test_fail('QueryBuilder INSERT returns lastInsertId', 'got empty value');
    }

    // SELECT it back
    $user = \App\Database\QueryBuilder::query('users')
        ->select()
        ->where('id', $insertedUserId)
        ->first();

    if ($user !== null && ($user['username'] ?? '') === 'testuser' && ($user['email'] ?? '') === 'test@example.com') {
        test_pass('QueryBuilder SELECT with where() retrieves inserted row correctly');
    } else {
        test_fail('QueryBuilder SELECT with where()', 'user not found or data mismatch: ' . var_export($user, true));
    }

    // Verify role
    if (($user['role'] ?? '') === 'admin') {
        test_pass('Inserted user has correct role: admin');
    } else {
        test_fail('Inserted user role', "expected 'admin', got: " . var_export($user['role'] ?? null, true));
    }
} catch (\Throwable $e) {
    test_fail('QueryBuilder INSERT/SELECT works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: QueryBuilder supports where(), orderBy(), limit() chains
// ---------------------------------------------------------------------------
try {
    // Insert additional content rows for testing
    $authorId = $insertedUserId ?? '1';

    \App\Database\QueryBuilder::query('content')->insert([
        'type'      => 'post',
        'title'     => 'First Post',
        'slug'      => 'first-post',
        'body'      => '<p>Body 1</p>',
        'status'    => 'published',
        'author_id' => $authorId,
    ]);

    \App\Database\QueryBuilder::query('content')->insert([
        'type'      => 'post',
        'title'     => 'Second Post',
        'slug'      => 'second-post',
        'body'      => '<p>Body 2</p>',
        'status'    => 'draft',
        'author_id' => $authorId,
    ]);

    \App\Database\QueryBuilder::query('content')->insert([
        'type'      => 'page',
        'title'     => 'About Us',
        'slug'      => 'about-us',
        'body'      => '<p>About</p>',
        'status'    => 'published',
        'author_id' => $authorId,
    ]);

    // Test: WHERE filter by status
    $drafts = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('status', 'draft')
        ->get();

    if (count($drafts) === 1 && ($drafts[0]['title'] ?? '') === 'Second Post') {
        test_pass('QueryBuilder where() filters correctly: 1 draft found');
    } else {
        test_fail('QueryBuilder where() filter', 'expected 1 draft, got ' . count($drafts));
    }

    // Test: WHERE filter by type
    $posts = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('type', 'post')
        ->get();

    if (count($posts) === 2) {
        test_pass('QueryBuilder where() by type: 2 posts found');
    } else {
        test_fail('QueryBuilder where() by type', 'expected 2, got ' . count($posts));
    }

    // Test: ORDER BY + LIMIT
    $limited = \App\Database\QueryBuilder::query('content')
        ->select()
        ->orderBy('title', 'ASC')
        ->limit(2)
        ->get();

    if (count($limited) === 2) {
        $firstTitle = $limited[0]['title'] ?? '';
        // "About Us" should come first alphabetically
        if ($firstTitle === 'About Us') {
            test_pass('QueryBuilder orderBy() + limit() work: first result is "About Us", 2 rows returned');
        } else {
            test_fail('QueryBuilder orderBy()', "expected first result 'About Us', got '{$firstTitle}'");
        }
    } else {
        test_fail('QueryBuilder limit()', 'expected 2 rows, got ' . count($limited));
    }

    // Test: first() returns single row
    $single = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('slug', 'about-us')
        ->first();

    if ($single !== null && ($single['title'] ?? '') === 'About Us') {
        test_pass('QueryBuilder first() returns single matching row');
    } else {
        test_fail('QueryBuilder first()', 'expected About Us page, got: ' . var_export($single, true));
    }

    // Test: first() returns null for no match
    $noMatch = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('slug', 'nonexistent-slug')
        ->first();

    if ($noMatch === null) {
        test_pass('QueryBuilder first() returns null when no rows match');
    } else {
        test_fail('QueryBuilder first() null case', 'expected null, got: ' . var_export($noMatch, true));
    }
} catch (\Throwable $e) {
    test_fail('QueryBuilder chaining works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: QueryBuilder supports update() and delete()
// ---------------------------------------------------------------------------
try {
    // UPDATE: change the draft post to published
    $affected = \App\Database\QueryBuilder::query('content')
        ->where('slug', 'second-post')
        ->update(['status' => 'published']);

    if ($affected === 1) {
        test_pass('QueryBuilder update() returns affected row count: 1');
    } else {
        test_fail('QueryBuilder update() affected rows', "expected 1, got {$affected}");
    }

    // Verify the update
    $updated = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('slug', 'second-post')
        ->first();

    if ($updated !== null && ($updated['status'] ?? '') === 'published') {
        test_pass('QueryBuilder update() persisted: status changed to published');
    } else {
        test_fail('QueryBuilder update() verification', 'status not updated');
    }

    // DELETE: remove the second post
    $deleted = \App\Database\QueryBuilder::query('content')
        ->where('slug', 'second-post')
        ->delete();

    if ($deleted === 1) {
        test_pass('QueryBuilder delete() returns affected row count: 1');
    } else {
        test_fail('QueryBuilder delete() affected rows', "expected 1, got {$deleted}");
    }

    // Verify deletion
    $gone = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('slug', 'second-post')
        ->first();

    if ($gone === null) {
        test_pass('QueryBuilder delete() confirmed: row no longer exists');
    } else {
        test_fail('QueryBuilder delete() verification', 'row still exists after delete');
    }
} catch (\Throwable $e) {
    test_fail('QueryBuilder update/delete works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 8: QueryBuilder count() works
// ---------------------------------------------------------------------------
try {
    // We have 2 remaining content rows (first-post and about-us)
    $count = \App\Database\QueryBuilder::query('content')
        ->select()
        ->count();

    if ($count === 2) {
        test_pass("QueryBuilder count() returns correct value: {$count}");
    } else {
        test_fail('QueryBuilder count()', "expected 2, got {$count}");
    }

    // Count with where clause
    $publishedCount = \App\Database\QueryBuilder::query('content')
        ->select()
        ->where('status', 'published')
        ->count();

    if ($publishedCount === 2) {
        test_pass("QueryBuilder count() with where() works: {$publishedCount} published");
    } else {
        test_fail('QueryBuilder count() with where()', "expected 2, got {$publishedCount}");
    }
} catch (\Throwable $e) {
    test_fail('QueryBuilder count() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: All 7 tables exist with correct names
// ---------------------------------------------------------------------------
try {
    $expectedTables = [
        'users',
        'content',
        'content_types',
        'custom_fields',
        'media',
        'settings',
        'ai_conversations',
    ];

    $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $allTablesFound = true;
    foreach ($expectedTables as $table) {
        if (!in_array($table, $existingTables, true)) {
            test_fail("Table exists: {$table}", 'not found in database');
            $allTablesFound = false;
        }
    }

    if ($allTablesFound) {
        test_pass('All 7 schema tables exist: ' . implode(', ', $expectedTables));
    }

    // Also verify _migrations tracking table exists
    if (in_array('_migrations', $existingTables, true)) {
        test_pass('Migration tracking table _migrations exists');
    } else {
        test_fail('Migration tracking table _migrations exists', 'not found');
    }
} catch (\Throwable $e) {
    test_fail('Table existence check works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: Migration files exist for all 3 drivers
// ---------------------------------------------------------------------------
$migrationFiles = [
    'migrations/001_initial.sqlite.sql',
    'migrations/001_initial.pgsql.sql',
    'migrations/001_initial.mysql.sql',
];

$allMigrationsExist = true;
foreach ($migrationFiles as $file) {
    $path = $rootDir . '/' . $file;
    if (!file_exists($path)) {
        test_fail("Migration file exists: {$file}");
        $allMigrationsExist = false;
    }
}
if ($allMigrationsExist) {
    test_pass('Migration files exist for all 3 drivers (sqlite, pgsql, mysql)');
}

// ---------------------------------------------------------------------------
// Test 11: Connection driver detection works
// ---------------------------------------------------------------------------
try {
    $driver = \App\Database\Connection::getDriver();
    if ($driver === 'sqlite') {
        test_pass("Connection::getDriver() returns 'sqlite' (matches config)");
    } else {
        test_fail("Connection::getDriver() returns 'sqlite'", "got: {$driver}");
    }

    // Verify the connection can execute a simple query
    $stmt = $pdo->query('SELECT 1 as test_val');
    $row = $stmt->fetch();
    if (($row['test_val'] ?? null) == 1) {
        test_pass('PDO connection can execute queries (SELECT 1)');
    } else {
        test_fail('PDO connection execute query', 'unexpected result from SELECT 1');
    }
} catch (\Throwable $e) {
    test_fail('Connection driver detection works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: Migrator tracking methods work
// ---------------------------------------------------------------------------
try {
    $applied = $migrator->getApplied();
    if (is_array($applied) && count($applied) >= 1) {
        test_pass('Migrator::getApplied() returns array with ' . count($applied) . ' migration(s)');
    } else {
        test_fail('Migrator::getApplied()', 'expected at least 1 migration, got ' . count($applied));
    }

    $hasInitial = $migrator->hasBeenApplied('001_initial.sqlite.sql');
    if ($hasInitial === true) {
        test_pass('Migrator::hasBeenApplied() returns true for applied migration');
    } else {
        test_fail('Migrator::hasBeenApplied()', 'returned false for 001_initial.sqlite.sql');
    }

    $hasFake = $migrator->hasBeenApplied('999_nonexistent.sqlite.sql');
    if ($hasFake === false) {
        test_pass('Migrator::hasBeenApplied() returns false for unapplied migration');
    } else {
        test_fail('Migrator::hasBeenApplied() false case', 'returned true for nonexistent migration');
    }
} catch (\Throwable $e) {
    test_fail('Migrator tracking methods work without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: QueryBuilder raw() works
// ---------------------------------------------------------------------------
try {
    $stmt = \App\Database\QueryBuilder::raw(
        'SELECT COUNT(*) as total FROM content WHERE status = :status',
        [':status' => 'published']
    );
    $row = $stmt->fetch();
    $total = (int) ($row['total'] ?? -1);

    if ($total >= 0) {
        test_pass("QueryBuilder::raw() executes parameterized SQL (found {$total} published items)");
    } else {
        test_fail('QueryBuilder::raw()', 'unexpected result');
    }
} catch (\Throwable $e) {
    test_fail('QueryBuilder::raw() works without errors', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup: remove the test database
// ---------------------------------------------------------------------------
\App\Database\Connection::reset();

// Reset Config so other tests are not affected
$configProp->setValue(null, null);
putenv('DB_DRIVER');
putenv('DB_PATH');

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
echo "Chunk 1.2 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
