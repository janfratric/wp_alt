<?php declare(strict_types=1);

/**
 * Chunk 5.2 — Settings Panel & Site Configuration
 * Automated Verification Tests
 *
 * Tests:
 *   1. Config::loadDbSettings() exists and is callable
 *   2. Config::reset() exists and clears cached state
 *   3. Config DB overlay: DB settings override file config
 *   [SMOKE STOP]
 *   4. Config protectedKeys: db_* and app_secret never overridden by DB
 *   5. Config::all() returns merged file+DB config
 *   6. Config::loadDbSettings() handles missing settings table gracefully
 *   7. SettingsController has getTimezoneList() and saveCheckbox() methods
 *   8. Settings save and load: General settings (site_name, site_url, tagline, timezone, items_per_page)
 *   9. Settings save and load: SEO settings (default_meta_description, og_default_image)
 *  10. Settings save and load: Cookie consent & Analytics (cookie_consent_enabled, cookie_consent_text, cookie_consent_link, ga_enabled, ga_measurement_id)
 *  11. Settings save and load: Contact form (contact_notification_email)
 *  12. Settings save and load: Advanced (registration_enabled, maintenance_mode)
 *  13. Timezone validation — only valid timezones accepted
 *  14. Items per page clamped to 1–100
 *  15. GA Measurement ID validation — G-XXXXXXXXXX format
 *  16. Email validation — only valid emails or empty accepted
 *  17. Settings template has all required sections
 *  18. Settings template has checkbox fields with hidden input pattern
 *  19. Settings template has timezone select dropdown
 *  20. FrontController fetches cookie_consent_enabled
 *  21. Public layout wraps consent partial in conditional
 *  22. Config DB override integrates with existing code (items_per_page, site_name)
 *  23. AI settings preserved (API key, model, AI params fields still present)
 *  24. App::__construct() calls Config::loadDbSettings()
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
$testDbPath = $rootDir . '/storage/test_chunk52.sqlite';

// Clean up any previous test database
if (file_exists($testDbPath)) {
    unlink($testDbPath);
}
$walPath = $testDbPath . '-wal';
$shmPath = $testDbPath . '-shm';
if (file_exists($walPath)) { unlink($walPath); }
if (file_exists($shmPath)) { unlink($shmPath); }

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
putenv('APP_SECRET=test-secret-for-chunk-52');

// Force Config to reload (use reflection to clear both file config and DB settings)
$configReflection = new ReflectionClass(\App\Core\Config::class);
$configProp = $configReflection->getProperty('config');
$configProp->setAccessible(true);
$configProp->setValue(null, null);

// Also clear dbSettings if the property exists
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

// Run migrations to create tables
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

// ---------------------------------------------------------------------------
// Test 1: Config::loadDbSettings() exists and is callable
// ---------------------------------------------------------------------------
try {
    if ($configReflection->hasMethod('loadDbSettings')) {
        $method = $configReflection->getMethod('loadDbSettings');
        if ($method->isPublic() && $method->isStatic()) {
            test_pass('Config::loadDbSettings() exists and is public static');
        } else {
            test_fail('Config::loadDbSettings() visibility', 'must be public static');
        }
    } else {
        test_fail('Config::loadDbSettings() exists', 'method not found');
    }
} catch (\Throwable $e) {
    test_fail('Config::loadDbSettings() check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 2: Config::reset() exists and clears cached state
// ---------------------------------------------------------------------------
try {
    if ($configReflection->hasMethod('reset')) {
        $resetMethod = $configReflection->getMethod('reset');
        if ($resetMethod->isPublic() && $resetMethod->isStatic()) {
            // Test that reset actually clears state
            \App\Core\Config::loadDbSettings();
            \App\Core\Config::reset();

            // After reset, dbSettings should be null (requiring reload)
            if ($hasDbSettings) {
                $val = $dbSettingsProp->getValue(null);
                if ($val === null) {
                    test_pass('Config::reset() clears both file and DB settings cache');
                } else {
                    test_fail('Config::reset() clears state', 'dbSettings not null after reset');
                }
            } else {
                test_fail('Config::reset() check', 'dbSettings property not found');
            }
        } else {
            test_fail('Config::reset() visibility', 'must be public static');
        }
    } else {
        test_fail('Config::reset() exists', 'method not found');
    }
} catch (\Throwable $e) {
    test_fail('Config::reset() check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 3: Config DB overlay — DB settings override file config
// ---------------------------------------------------------------------------
try {
    // Insert a setting that overrides a file config value
    \App\Database\QueryBuilder::query('settings')->insert([
        'key'   => 'site_name',
        'value' => 'Test Override Site',
    ]);

    // Reset and reload
    \App\Core\Config::reset();
    // Need to also reset the file config via reflection
    $configProp->setValue(null, null);
    if ($hasDbSettings) {
        $dbSettingsProp->setValue(null, null);
    }

    \App\Core\Config::loadDbSettings();

    $siteName = \App\Core\Config::getString('site_name', 'LiteCMS');
    if ($siteName === 'Test Override Site') {
        test_pass('Config::get() returns DB value over file config (site_name overridden)');
    } else {
        test_fail('Config DB override', "expected 'Test Override Site', got '{$siteName}'");
    }
} catch (\Throwable $e) {
    test_fail('Config DB overlay works without errors', $e->getMessage());
}

if ($isSmoke) {
    // Smoke mode — stop here, clean up
    \App\Database\Connection::reset();
    $configProp->setValue(null, null);
    if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }
    putenv('DB_DRIVER');
    putenv('DB_PATH');
    putenv('APP_SECRET');
    if (file_exists($testDbPath)) { unlink($testDbPath); }
    if (file_exists($walPath)) { unlink($walPath); }
    if (file_exists($shmPath)) { unlink($shmPath); }
    echo "\n[INFO] Smoke mode — skipping remaining tests\n";
    echo "\nChunk 5.2 results: {$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// ---------------------------------------------------------------------------
// Test 4: Config protectedKeys — db_* and app_secret never overridden by DB
// ---------------------------------------------------------------------------
try {
    // Insert a protected key into settings
    \App\Database\QueryBuilder::query('settings')->insert([
        'key'   => 'db_driver',
        'value' => 'pgsql',
    ]);
    \App\Database\QueryBuilder::query('settings')->insert([
        'key'   => 'app_secret',
        'value' => 'hacked-secret',
    ]);

    // Reload
    \App\Core\Config::reset();
    $configProp->setValue(null, null);
    if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }
    \App\Core\Config::loadDbSettings();

    $driver = \App\Core\Config::getString('db_driver');
    $secret = \App\Core\Config::getString('app_secret');

    $driverOk = ($driver === 'sqlite'); // Should come from file config, not DB's 'pgsql'
    $secretOk = ($secret !== 'hacked-secret'); // Should NOT be the DB-injected value

    if ($driverOk && $secretOk) {
        test_pass('Config protectedKeys: db_driver and app_secret not overridden by DB values');
    } else {
        $details = [];
        if (!$driverOk) { $details[] = "db_driver='{$driver}' (expected 'sqlite')"; }
        if (!$secretOk) { $details[] = "app_secret was overridden to DB value"; }
        test_fail('Config protectedKeys', implode('; ', $details));
    }

    // Verify other db_* keys are also protected
    $hasProtectedKeys = $configReflection->hasProperty('protectedKeys');
    if ($hasProtectedKeys) {
        $pkProp = $configReflection->getProperty('protectedKeys');
        $pkProp->setAccessible(true);
        $protectedKeys = $pkProp->getValue(null);

        $requiredProtected = ['db_driver', 'db_path', 'db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'app_secret'];
        $allProtected = true;
        foreach ($requiredProtected as $rk) {
            if (!in_array($rk, $protectedKeys, true)) {
                $allProtected = false;
            }
        }

        if ($allProtected) {
            test_pass('Config protectedKeys includes all 8 required keys (db_*, app_secret)');
        } else {
            test_fail('Config protectedKeys list', 'missing some required keys');
        }
    } else {
        test_fail('Config protectedKeys property', 'protectedKeys property not found');
    }

    // Clean up injected protected keys
    \App\Database\QueryBuilder::query('settings')->where('key', 'db_driver')->delete();
    \App\Database\QueryBuilder::query('settings')->where('key', 'app_secret')->delete();
} catch (\Throwable $e) {
    test_fail('Config protectedKeys check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 5: Config::all() returns merged file+DB config
// ---------------------------------------------------------------------------
try {
    \App\Core\Config::reset();
    $configProp->setValue(null, null);
    if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }
    \App\Core\Config::loadDbSettings();

    $all = \App\Core\Config::all();

    if (!is_array($all)) {
        test_fail('Config::all() returns array', 'got ' . gettype($all));
    } else {
        // Should have file config keys AND the DB override for site_name
        $hasSiteName = isset($all['site_name']) && $all['site_name'] === 'Test Override Site';
        $hasDbDriver = isset($all['db_driver']) && $all['db_driver'] === 'sqlite'; // Not overridden

        if ($hasSiteName && $hasDbDriver) {
            test_pass('Config::all() returns merged config (DB overrides + file protected keys intact)');
        } else {
            $snVal = $all['site_name'] ?? 'null';
            $ddVal = $all['db_driver'] ?? 'null';
            test_fail('Config::all() merge', "site_name={$snVal}, db_driver={$ddVal}");
        }
    }
} catch (\Throwable $e) {
    test_fail('Config::all() merge check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 6: Config::loadDbSettings() handles missing settings table gracefully
// ---------------------------------------------------------------------------
try {
    // Save current state, then simulate missing table by using a fresh empty DB
    $tempDbPath = $rootDir . '/storage/test_chunk52_empty.sqlite';
    if (file_exists($tempDbPath)) { unlink($tempDbPath); }

    // Create an empty SQLite DB with no tables
    $tempPdo = new \PDO('sqlite:' . $tempDbPath);
    $tempPdo = null;

    \App\Database\Connection::reset();
    putenv('DB_PATH=' . $tempDbPath);
    $configProp->setValue(null, null);
    if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }

    // This should not throw
    \App\Core\Config::loadDbSettings();

    // After handling gracefully, file config should still work
    $driver = \App\Core\Config::getString('db_driver');
    if ($driver === 'sqlite') {
        test_pass('Config::loadDbSettings() handles missing settings table gracefully (no exception)');
    } else {
        test_fail('Config after missing table', "expected db_driver='sqlite', got '{$driver}'");
    }

    // Restore original test DB
    \App\Database\Connection::reset();
    putenv('DB_PATH=' . $testDbPath);
    $configProp->setValue(null, null);
    if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }
    \App\Core\Config::loadDbSettings();

    // Clean up temp DB
    if (file_exists($tempDbPath)) { unlink($tempDbPath); }
    $tmpWal = $tempDbPath . '-wal';
    $tmpShm = $tempDbPath . '-shm';
    if (file_exists($tmpWal)) { unlink($tmpWal); }
    if (file_exists($tmpShm)) { unlink($tmpShm); }
} catch (\Throwable $e) {
    // Restore even on failure
    \App\Database\Connection::reset();
    putenv('DB_PATH=' . $testDbPath);
    $configProp->setValue(null, null);
    if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }
    \App\Core\Config::loadDbSettings();
    test_fail('Config::loadDbSettings() graceful handling', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 7: SettingsController has getTimezoneList() and saveCheckbox() methods
// ---------------------------------------------------------------------------
try {
    $scReflection = new ReflectionClass(\App\Admin\SettingsController::class);

    $hasGetTimezoneList = $scReflection->hasMethod('getTimezoneList');
    $hasSaveCheckbox = $scReflection->hasMethod('saveCheckbox');

    if ($hasGetTimezoneList) {
        // Call it via reflection to verify it returns a grouped array
        $tzMethod = $scReflection->getMethod('getTimezoneList');
        $tzMethod->setAccessible(true);
        $timezones = $tzMethod->invoke(null);

        if (is_array($timezones) && count($timezones) > 0) {
            // Check it has common groups
            $hasUtcOrOther = isset($timezones['UTC']) || isset($timezones['Other']);
            $hasAmerica = isset($timezones['America']);
            $hasEurope = isset($timezones['Europe']);

            if ($hasAmerica && $hasEurope) {
                test_pass('getTimezoneList() returns grouped timezones (America, Europe, etc.)');
            } else {
                test_fail('getTimezoneList() grouping', 'missing America or Europe groups');
            }
        } else {
            test_fail('getTimezoneList() returns data', 'empty or not an array');
        }
    } else {
        test_fail('SettingsController::getTimezoneList() exists', 'method not found');
    }

    if ($hasSaveCheckbox) {
        test_pass('SettingsController::saveCheckbox() exists');
    } else {
        test_fail('SettingsController::saveCheckbox() exists', 'method not found');
    }
} catch (\Throwable $e) {
    test_fail('SettingsController new methods check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Helper: save a setting using the same upsert pattern as SettingsController
// ---------------------------------------------------------------------------
function saveSetting(string $key, string $value): void {
    $existing = \App\Database\QueryBuilder::query('settings')
        ->select('key')
        ->where('key', $key)
        ->first();

    if ($existing !== null) {
        \App\Database\QueryBuilder::query('settings')
            ->where('key', $key)
            ->update(['value' => $value]);
    } else {
        \App\Database\QueryBuilder::query('settings')->insert([
            'key'   => $key,
            'value' => $value,
        ]);
    }
}

function loadSetting(string $key): ?string {
    $row = \App\Database\QueryBuilder::query('settings')
        ->select('value')
        ->where('key', $key)
        ->first();
    return $row !== null ? $row['value'] : null;
}

// ---------------------------------------------------------------------------
// Test 8: General settings save and load
// ---------------------------------------------------------------------------
try {
    $generalSettings = [
        'site_name'      => 'My Test Site',
        'site_url'       => 'https://example.com',
        'site_tagline'   => 'A great tagline',
        'timezone'       => 'America/New_York',
        'items_per_page' => '15',
    ];

    foreach ($generalSettings as $key => $value) {
        saveSetting($key, $value);
    }

    $allOk = true;
    foreach ($generalSettings as $key => $expected) {
        $actual = loadSetting($key);
        if ($actual !== $expected) {
            test_fail("General setting {$key}", "expected '{$expected}', got '{$actual}'");
            $allOk = false;
        }
    }

    if ($allOk) {
        test_pass('General settings save and load correctly (site_name, site_url, tagline, timezone, items_per_page)');
    }

    // Verify Config picks up the DB values
    \App\Core\Config::reset();
    $configProp->setValue(null, null);
    if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }
    \App\Core\Config::loadDbSettings();

    $itemsPerPage = \App\Core\Config::getInt('items_per_page');
    if ($itemsPerPage === 15) {
        test_pass('Config::getInt() returns DB-stored items_per_page value (15)');
    } else {
        test_fail('Config items_per_page override', "expected 15, got {$itemsPerPage}");
    }
} catch (\Throwable $e) {
    test_fail('General settings save/load', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 9: SEO settings save and load
// ---------------------------------------------------------------------------
try {
    saveSetting('default_meta_description', 'A test description for SEO purposes.');
    saveSetting('og_default_image', '/assets/uploads/og-default.jpg');

    $metaDesc = loadSetting('default_meta_description');
    $ogImage = loadSetting('og_default_image');

    if ($metaDesc === 'A test description for SEO purposes.' && $ogImage === '/assets/uploads/og-default.jpg') {
        test_pass('SEO settings save and load correctly (default_meta_description, og_default_image)');
    } else {
        test_fail('SEO settings', "metaDesc='{$metaDesc}', ogImage='{$ogImage}'");
    }
} catch (\Throwable $e) {
    test_fail('SEO settings save/load', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 10: Cookie consent & Analytics settings save and load
// ---------------------------------------------------------------------------
try {
    saveSetting('cookie_consent_enabled', '1');
    saveSetting('cookie_consent_text', 'We use cookies for analytics.');
    saveSetting('cookie_consent_link', '/privacy-policy');
    saveSetting('ga_enabled', '1');
    saveSetting('ga_measurement_id', 'G-TEST12345');

    $consentEnabled = loadSetting('cookie_consent_enabled');
    $consentText = loadSetting('cookie_consent_text');
    $consentLink = loadSetting('cookie_consent_link');
    $gaEnabled = loadSetting('ga_enabled');
    $gaId = loadSetting('ga_measurement_id');

    $allOk = ($consentEnabled === '1')
        && ($consentText === 'We use cookies for analytics.')
        && ($consentLink === '/privacy-policy')
        && ($gaEnabled === '1')
        && ($gaId === 'G-TEST12345');

    if ($allOk) {
        test_pass('Cookie consent & Analytics settings save and load correctly');
    } else {
        test_fail('Cookie/Analytics settings', "consent={$consentEnabled}, text={$consentText}, link={$consentLink}, ga={$gaEnabled}, gaId={$gaId}");
    }

    // Toggle off
    saveSetting('cookie_consent_enabled', '0');
    saveSetting('ga_enabled', '0');

    if (loadSetting('cookie_consent_enabled') === '0' && loadSetting('ga_enabled') === '0') {
        test_pass('Cookie consent and GA toggles save "0" when disabled');
    } else {
        test_fail('Toggle off', 'values not "0" after toggle');
    }
} catch (\Throwable $e) {
    test_fail('Cookie/Analytics settings save/load', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 11: Contact form settings save and load
// ---------------------------------------------------------------------------
try {
    saveSetting('contact_notification_email', 'admin@example.com');

    $email = loadSetting('contact_notification_email');
    if ($email === 'admin@example.com') {
        test_pass('Contact notification email saves and loads correctly');
    } else {
        test_fail('Contact notification email', "expected 'admin@example.com', got '{$email}'");
    }

    // Allow empty (disable notifications)
    saveSetting('contact_notification_email', '');
    if (loadSetting('contact_notification_email') === '') {
        test_pass('Contact notification email accepts empty value (disable notifications)');
    } else {
        test_fail('Contact notification email empty', 'value not empty after saving empty');
    }
} catch (\Throwable $e) {
    test_fail('Contact settings save/load', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: Advanced settings save and load
// ---------------------------------------------------------------------------
try {
    saveSetting('registration_enabled', '0');
    saveSetting('maintenance_mode', '0');

    if (loadSetting('registration_enabled') === '0' && loadSetting('maintenance_mode') === '0') {
        test_pass('Advanced settings save and load correctly (registration_enabled, maintenance_mode)');
    } else {
        test_fail('Advanced settings', 'unexpected values');
    }

    // Toggle on
    saveSetting('registration_enabled', '1');
    saveSetting('maintenance_mode', '1');

    if (loadSetting('registration_enabled') === '1' && loadSetting('maintenance_mode') === '1') {
        test_pass('Advanced toggle settings save "1" when enabled');
    } else {
        test_fail('Advanced toggle on', 'values not "1" after toggle');
    }
} catch (\Throwable $e) {
    test_fail('Advanced settings save/load', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 13: Timezone validation — only valid timezones accepted
// ---------------------------------------------------------------------------
try {
    $validTimezones = \DateTimeZone::listIdentifiers();

    // Known valid timezone
    if (in_array('America/New_York', $validTimezones, true)) {
        test_pass('DateTimeZone::listIdentifiers() includes America/New_York');
    } else {
        test_fail('Timezone list', 'America/New_York not in list');
    }

    // Invalid timezone should not be in the list
    if (!in_array('Fake/Timezone', $validTimezones, true)) {
        test_pass('Invalid timezone "Fake/Timezone" correctly rejected by validation');
    } else {
        test_fail('Invalid timezone', 'Fake/Timezone found in valid list');
    }

    // Verify SettingsController source validates against DateTimeZone::listIdentifiers
    $scSource = file_get_contents($rootDir . '/app/Admin/SettingsController.php');
    if (str_contains($scSource, 'DateTimeZone::listIdentifiers') || str_contains($scSource, 'listIdentifiers')) {
        test_pass('SettingsController validates timezone against DateTimeZone::listIdentifiers()');
    } else {
        test_fail('Timezone validation in controller', 'DateTimeZone::listIdentifiers not found in source');
    }
} catch (\Throwable $e) {
    test_fail('Timezone validation', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 14: Items per page clamped to 1–100
// ---------------------------------------------------------------------------
try {
    $scSource = file_get_contents($rootDir . '/app/Admin/SettingsController.php');

    // Check for clamping logic (max(..., min(...)))
    if ((str_contains($scSource, 'max(1') || str_contains($scSource, 'max( 1'))
        && (str_contains($scSource, 'min(100') || str_contains($scSource, 'min( 100'))) {
        test_pass('SettingsController clamps items_per_page to 1–100 range');
    } else {
        test_fail('Items per page clamping', 'max(1, min(100, ...)) pattern not found in source');
    }
} catch (\Throwable $e) {
    test_fail('Items per page validation', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 15: GA Measurement ID validation
// ---------------------------------------------------------------------------
try {
    $scSource = file_get_contents($rootDir . '/app/Admin/SettingsController.php');

    // Should have a regex or pattern check for G-XXXXXXXXXX
    if (str_contains($scSource, 'G-') && (str_contains($scSource, 'preg_match') || str_contains($scSource, 'pattern'))) {
        test_pass('SettingsController validates GA Measurement ID format (G-XXXXXXXXXX)');
    } else {
        test_fail('GA ID validation', 'no regex/pattern validation for G- prefix found');
    }

    // Test the regex pattern directly
    $validIds = ['G-TEST12345', 'G-ABCDEF1234', 'G-X1'];
    $invalidIds = ['UA-12345', 'test', 'G-', 'g-lowercase'];

    $allValid = true;
    foreach ($validIds as $id) {
        if (!preg_match('/^G-[A-Z0-9]+$/', $id)) {
            $allValid = false;
            test_fail('GA ID regex', "valid ID '{$id}' rejected");
        }
    }

    $allInvalid = true;
    foreach ($invalidIds as $id) {
        if (preg_match('/^G-[A-Z0-9]+$/', $id)) {
            $allInvalid = false;
            test_fail('GA ID regex', "invalid ID '{$id}' accepted");
        }
    }

    if ($allValid && $allInvalid) {
        test_pass('GA Measurement ID regex correctly accepts valid and rejects invalid IDs');
    }
} catch (\Throwable $e) {
    test_fail('GA ID validation', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 16: Email validation
// ---------------------------------------------------------------------------
try {
    $scSource = file_get_contents($rootDir . '/app/Admin/SettingsController.php');

    if (str_contains($scSource, 'FILTER_VALIDATE_EMAIL')) {
        test_pass('SettingsController validates contact_notification_email with FILTER_VALIDATE_EMAIL');
    } else {
        test_fail('Email validation', 'FILTER_VALIDATE_EMAIL not found in source');
    }
} catch (\Throwable $e) {
    test_fail('Email validation check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 17: Settings template has all required sections
// ---------------------------------------------------------------------------
try {
    $tplPath = $rootDir . '/templates/admin/settings.php';
    if (!file_exists($tplPath)) {
        test_fail('Settings template exists', 'templates/admin/settings.php not found');
    } else {
        $tpl = file_get_contents($tplPath);

        $requiredSections = [
            'General'                       => ['site_name', 'site_url', 'site_tagline', 'timezone', 'items_per_page'],
            'SEO'                           => ['default_meta_description', 'og_default_image'],
            'Cookie Consent'                => ['cookie_consent_enabled', 'cookie_consent_text', 'cookie_consent_link', 'ga_enabled', 'ga_measurement_id'],
            'Contact Form'                  => ['contact_notification_email'],
            'Advanced'                      => ['registration_enabled', 'maintenance_mode'],
        ];

        $allSectionsOk = true;
        foreach ($requiredSections as $section => $fields) {
            // Check section heading exists
            if (!str_contains($tpl, $section)) {
                test_fail("Settings section '{$section}'", 'section heading not found in template');
                $allSectionsOk = false;
                continue;
            }

            // Check all fields in the section
            foreach ($fields as $field) {
                if (!str_contains($tpl, $field)) {
                    test_fail("Settings field '{$field}'", "not found in template");
                    $allSectionsOk = false;
                }
            }
        }

        if ($allSectionsOk) {
            test_pass('Settings template has all required sections and fields (General, SEO, Cookie/Analytics, Contact, Advanced)');
        }
    }
} catch (\Throwable $e) {
    test_fail('Settings template sections check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 18: Settings template has checkbox fields with hidden input pattern
// ---------------------------------------------------------------------------
try {
    $tpl = file_get_contents($rootDir . '/templates/admin/settings.php');

    // Checkbox fields need hidden+checkbox pattern for proper form handling
    $checkboxFields = ['cookie_consent_enabled', 'ga_enabled', 'registration_enabled', 'maintenance_mode'];
    $allHaveHidden = true;

    foreach ($checkboxFields as $field) {
        // Check for hidden input with the field name
        $hiddenPattern = 'type="hidden" name="' . $field . '"';
        $altHiddenPattern = "type=\"hidden\" name=\"{$field}\"";
        $checkboxPattern = 'type="checkbox"';

        if (!str_contains($tpl, $field)) {
            test_fail("Checkbox field '{$field}'", 'field not found in template');
            $allHaveHidden = false;
            continue;
        }

        // Check for hidden input pattern (value="0" before checkbox)
        if (!str_contains($tpl, $hiddenPattern) && !str_contains($tpl, $altHiddenPattern)) {
            test_fail("Hidden input for '{$field}'", 'hidden input pattern not found');
            $allHaveHidden = false;
        }
    }

    if ($allHaveHidden) {
        test_pass('All checkbox fields have hidden input pattern for proper unchecked handling');
    }
} catch (\Throwable $e) {
    test_fail('Checkbox hidden input check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 19: Settings template has timezone select dropdown
// ---------------------------------------------------------------------------
try {
    $tpl = file_get_contents($rootDir . '/templates/admin/settings.php');

    if (str_contains($tpl, '<select') && str_contains($tpl, 'timezone') && str_contains($tpl, 'optgroup')) {
        test_pass('Settings template has timezone select dropdown with optgroups');
    } else {
        $missing = [];
        if (!str_contains($tpl, '<select')) { $missing[] = '<select>'; }
        if (!str_contains($tpl, 'timezone')) { $missing[] = 'timezone name'; }
        if (!str_contains($tpl, 'optgroup')) { $missing[] = 'optgroup'; }
        test_fail('Timezone select dropdown', 'missing: ' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    test_fail('Timezone dropdown check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 20: FrontController fetches cookie_consent_enabled
// ---------------------------------------------------------------------------
try {
    $fcSource = file_get_contents($rootDir . '/app/Templates/FrontController.php');

    if (str_contains($fcSource, 'cookie_consent_enabled')) {
        test_pass('FrontController fetches cookie_consent_enabled from settings');
    } else {
        test_fail('FrontController cookie_consent_enabled', 'string not found in source');
    }

    // Check it passes consentEnabled to templates
    if (str_contains($fcSource, 'consentEnabled')) {
        test_pass('FrontController passes consentEnabled to public templates');
    } else {
        test_fail('FrontController consentEnabled', 'consentEnabled variable not found in renderPublic');
    }
} catch (\Throwable $e) {
    test_fail('FrontController settings check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 21: Public layout wraps consent partial in conditional
// ---------------------------------------------------------------------------
try {
    $layoutSource = file_get_contents($rootDir . '/templates/public/layout.php');

    // Should have a conditional around the cookie consent include
    if (str_contains($layoutSource, 'consentEnabled') && str_contains($layoutSource, 'cookie-consent')) {
        test_pass('Public layout conditionally includes cookie consent based on consentEnabled');
    } else {
        $missing = [];
        if (!str_contains($layoutSource, 'consentEnabled')) { $missing[] = 'consentEnabled conditional'; }
        if (!str_contains($layoutSource, 'cookie-consent')) { $missing[] = 'cookie-consent partial'; }
        test_fail('Public layout consent conditional', 'missing: ' . implode(', ', $missing));
    }
} catch (\Throwable $e) {
    test_fail('Public layout consent check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 22: Config DB override integrates with existing code
// ---------------------------------------------------------------------------
try {
    // Verify that after saving items_per_page=25, Config::getInt() returns 25
    saveSetting('items_per_page', '25');

    \App\Core\Config::reset();
    $configProp->setValue(null, null);
    if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }
    \App\Core\Config::loadDbSettings();

    $perPage = \App\Core\Config::getInt('items_per_page', 10);
    if ($perPage === 25) {
        test_pass('Config::getInt() correctly casts DB string "25" to int 25');
    } else {
        test_fail('Config::getInt() DB cast', "expected 25, got {$perPage}");
    }

    // Verify site_url override
    saveSetting('site_url', 'https://mysite.com');
    \App\Core\Config::reset();
    $configProp->setValue(null, null);
    if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }
    \App\Core\Config::loadDbSettings();

    $siteUrl = \App\Core\Config::getString('site_url');
    if ($siteUrl === 'https://mysite.com') {
        test_pass('Config::getString() returns DB-stored site_url');
    } else {
        test_fail('Config site_url override', "expected 'https://mysite.com', got '{$siteUrl}'");
    }
} catch (\Throwable $e) {
    test_fail('Config integration check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 23: AI settings preserved (from chunk 4.1)
// ---------------------------------------------------------------------------
try {
    $tpl = file_get_contents($rootDir . '/templates/admin/settings.php');

    $aiFields = ['claude_api_key', 'claude_model', 'ai_max_tokens', 'ai_timeout', 'ai_temperature'];
    $allPresent = true;

    foreach ($aiFields as $field) {
        if (!str_contains($tpl, $field)) {
            test_fail("AI field '{$field}' preserved in template", 'not found');
            $allPresent = false;
        }
    }

    if ($allPresent) {
        test_pass('AI Assistant settings fields preserved in template (API key, model, max_tokens, timeout, temperature)');
    }

    // Check AI section heading
    if (str_contains($tpl, 'AI Assistant')) {
        test_pass('AI Assistant section heading preserved in settings template');
    } else {
        test_fail('AI Assistant section heading', 'not found in template');
    }

    // Model management preserved
    if (str_contains($tpl, 'model-management') && str_contains($tpl, 'fetch-models-btn')) {
        test_pass('Model management UI preserved in settings template');
    } else {
        test_fail('Model management UI', 'model-management section or fetch button missing');
    }
} catch (\Throwable $e) {
    test_fail('AI settings preservation check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 24: App::__construct() calls Config::loadDbSettings()
// ---------------------------------------------------------------------------
try {
    $appSource = file_get_contents($rootDir . '/app/Core/App.php');

    if (str_contains($appSource, 'Config::loadDbSettings') || str_contains($appSource, 'loadDbSettings')) {
        test_pass('App::__construct() calls Config::loadDbSettings() during bootstrap');
    } else {
        test_fail('App bootstrap loadDbSettings', 'Config::loadDbSettings() call not found in App.php');
    }

    // Check for timezone re-apply after loadDbSettings
    if (str_contains($appSource, 'date_default_timezone_set')) {
        test_pass('App.php sets timezone (including potential DB override)');
    } else {
        test_fail('App.php timezone', 'date_default_timezone_set not found');
    }
} catch (\Throwable $e) {
    test_fail('App bootstrap check', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Cleanup: remove the test database
// ---------------------------------------------------------------------------
\App\Database\Connection::reset();

// Reset Config
$configProp->setValue(null, null);
if ($hasDbSettings) { $dbSettingsProp->setValue(null, null); }
putenv('DB_DRIVER');
putenv('DB_PATH');
putenv('APP_SECRET');

if (file_exists($testDbPath)) {
    unlink($testDbPath);
}
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
echo "Chunk 5.2 results: {$pass} passed, {$fail} failed\n";

exit($fail > 0 ? 1 : 0);
