<?php declare(strict_types=1);

/**
 * LiteCMS — Cumulative Test Runner
 *
 * Usage:
 *   php tests/run-all.php          # Run all tests (same as --full)
 *   php tests/run-all.php --full   # Run every test for every completed chunk
 *   php tests/run-all.php --quick  # Run only the latest chunk + smoke tests for previous
 *
 * Exit codes:
 *   0 = all tests passed
 *   1 = one or more tests failed
 */

$rootDir = dirname(__DIR__);

// Parse flags
$mode = 'full';
if (in_array('--quick', $argv, true)) {
    $mode = 'quick';
} elseif (in_array('--full', $argv, true)) {
    $mode = 'full';
}

// Ordered list of chunk test files — add new chunks here as they are implemented
$chunks = [
    '1.1' => 'chunk-1.1-verify.php',
    '1.2' => 'chunk-1.2-verify.php',
    '1.3' => 'chunk-1.3-verify.php',
    '2.1' => 'chunk-2.1-verify.php',
    '2.2' => 'chunk-2.2-verify.php',
    '2.3' => 'chunk-2.3-verify.php',
    '2.4' => 'chunk-2.4-verify.php',
    '3.1' => 'chunk-3.1-verify.php',
    '3.2' => 'chunk-3.2-verify.php',
    '4.1' => 'chunk-4.1-verify.php',
    '4.2' => 'chunk-4.2-verify.php',
    '5.1' => 'chunk-5.1-verify.php',
    '5.2' => 'chunk-5.2-verify.php',
    '5.3' => 'chunk-5.3-verify.php',
    '6.1' => 'chunk-6.1-verify.php',
    '6.2' => 'chunk-6.2-verify.php',
    '6.3' => 'chunk-6.3-verify.php',
    '6.4' => 'chunk-6.4-verify.php',
    '6.5' => 'chunk-6.5-verify.php',
    '6.6' => 'chunk-6.6-verify.php',
    '7.1' => 'chunk-7.1-verify.php',
    '7.2' => 'chunk-7.2-verify.php',
    '7.3' => 'chunk-7.3-verify.php',
    '7.4' => 'chunk-7.4-verify.php',
    '7.5' => 'chunk-7.5-verify.php',
    '7.6' => 'chunk-7.6-verify.php',
    '8.1' => 'chunk-8.1-verify.php',
];

// Discover which test files actually exist
$available = [];
foreach ($chunks as $chunkId => $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        $available[$chunkId] = $path;
    }
}

if (empty($available)) {
    echo "[INFO] No chunk test files found. Nothing to run.\n";
    exit(0);
}

// In --quick mode: run only the latest chunk fully, and previous chunks in smoke mode
$latestChunk = array_key_last($available);

echo "=== LiteCMS Test Runner (mode: {$mode}) ===\n";
echo "Available test suites: " . implode(', ', array_keys($available)) . "\n";
echo str_repeat('=', 50) . "\n\n";

$totalPass = 0;
$totalFail = 0;
$totalSkip = 0;
$failedChunks = [];

foreach ($available as $chunkId => $path) {
    $isSmoke = ($mode === 'quick' && $chunkId !== $latestChunk);
    $envFlag = $isSmoke ? 'LITECMS_TEST_SMOKE=1' : '';

    echo "--- Chunk {$chunkId}" . ($isSmoke ? ' (smoke)' : '') . " ---\n";

    // Run the test file as a subprocess to isolate state
    // On Windows, cmd.exe may have delayed expansion enabled which interprets !
    // in paths. We disable it with /V:OFF to prevent path mangling.
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'cmd /V:OFF /C php "' . $path . '"';
    } else {
        $cmd = 'php ' . escapeshellarg($path);
    }
    if ($isSmoke) {
        // Pass smoke flag as environment variable
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'cmd /V:OFF /C "set LITECMS_TEST_SMOKE=1 && php "' . $path . '""';
        } else {
            $cmd = "LITECMS_TEST_SMOKE=1 php " . escapeshellarg($path);
        }
    }

    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    // Parse output for [PASS], [FAIL], [SKIP] lines
    $chunkPass = 0;
    $chunkFail = 0;
    $chunkSkip = 0;

    foreach ($output as $line) {
        echo "  {$line}\n";
        if (str_starts_with($line, '[PASS]')) {
            $chunkPass++;
        } elseif (str_starts_with($line, '[FAIL]')) {
            $chunkFail++;
        } elseif (str_starts_with($line, '[SKIP]')) {
            $chunkSkip++;
        }
    }

    // Also check exit code in case test crashed without [FAIL] output
    if ($exitCode !== 0 && $chunkFail === 0) {
        $chunkFail++;
        echo "  [FAIL] Chunk {$chunkId} exited with code {$exitCode}\n";
    }

    $totalPass += $chunkPass;
    $totalFail += $chunkFail;
    $totalSkip += $chunkSkip;

    if ($chunkFail > 0) {
        $failedChunks[] = $chunkId;
    }

    echo "\n";
}

// Summary
echo str_repeat('=', 50) . "\n";
echo "RESULTS: {$totalPass} passed, {$totalFail} failed, {$totalSkip} skipped\n";

if (!empty($failedChunks)) {
    echo "FAILED CHUNKS: " . implode(', ', $failedChunks) . "\n";
}

echo str_repeat('=', 50) . "\n";

// --- Auto-update STATUS.md from test results ---
updateStatusFile($rootDir, $chunks, $available, $failedChunks);

exit($totalFail > 0 ? 1 : 0);

// ============================================================================
// STATUS.md generator — keeps project status in sync with reality
// ============================================================================

/**
 * Regenerates STATUS.md from test results so it always reflects reality.
 * Runs after every test suite execution.
 */
function updateStatusFile(
    string $rootDir,
    array $allChunks,
    array $testedChunks,
    array $failedChunks
): void {
    $chunkNames = [
        '1.1' => 'Scaffolding & Core Framework',
        '1.2' => 'Database Layer & Migrations',
        '1.3' => 'Authentication System',
        '2.1' => 'Admin Layout & Dashboard',
        '2.2' => 'Content CRUD (Pages & Posts)',
        '2.3' => 'Media Management',
        '2.4' => 'User Management',
        '3.1' => 'Template Engine & Front Controller',
        '3.2' => 'Public Templates & Styling',
        '4.1' => 'Claude API Client & Backend',
        '4.2' => 'AI Chat Panel Frontend',
        '5.1' => 'Custom Content Types',
        '5.2' => 'Settings Panel & Site Configuration',
        '5.3' => 'AI Page Generator',
        '6.1' => 'Element Catalogue & Rendering Engine',
        '6.2' => 'Content Editor Element Mode & Page Builder UI',
        '6.3' => 'Per-Instance Element Styling',
        '6.4' => 'AI Element Integration',
        '6.5' => 'Layout Template Block Management',
        '6.6' => 'Homepage Content & Recent Posts Element',
        '7.1' => 'Embed Pencil Editor in LiteCMS Admin',
        '7.2' => '.pen-to-HTML Converter (PenConverter)',
        '7.3' => 'LiteCMS Design System as .pen File',
        '7.4' => 'AI Design Pipeline',
        '7.5' => 'Admin Integration & Preview',
        '7.6' => 'Template System & Theme Integration',
        '8.1' => 'Final Polish, Error Handling & Documentation',
    ];

    // Dependency graph: chunk => prerequisites
    $deps = [
        '1.1' => [],
        '1.2' => ['1.1'],
        '1.3' => ['1.2'],
        '2.1' => ['1.3'],
        '2.2' => ['2.1'],
        '2.3' => ['2.2'],
        '2.4' => ['2.1'],
        '3.1' => ['2.1'],
        '3.2' => ['3.1'],
        '4.1' => ['2.2', '2.3', '2.4'],
        '4.2' => ['4.1'],
        '5.1' => ['4.2'],
        '5.2' => ['5.1'],
        '5.3' => ['5.2'],
        '6.1' => ['5.3'],
        '6.2' => ['6.1'],
        '6.3' => ['6.2'],
        '6.4' => ['6.3'],
        '6.5' => ['6.4'],
        '6.6' => ['6.5'],
        '7.1' => ['6.6'],
        '7.2' => ['7.1'],
        '7.3' => ['7.1', '7.2'],
        '7.4' => ['7.2', '7.3'],
        '7.5' => ['7.1', '7.2', '7.4'],
        '7.6' => ['7.2', '7.3', '7.5'],
        '8.1' => ['7.6'],
    ];

    // Determine which chunks passed
    $failedSet = array_flip($failedChunks);
    $completedChunks = [];
    foreach ($allChunks as $chunkId => $file) {
        if (isset($testedChunks[$chunkId]) && !isset($failedSet[$chunkId])) {
            $completedChunks[$chunkId] = true;
        }
    }

    // Find next actionable chunks (prerequisites met, not yet complete)
    $readyChunks = [];
    foreach ($allChunks as $chunkId => $file) {
        if (isset($completedChunks[$chunkId])) {
            continue;
        }
        $prereqsMet = true;
        foreach ($deps[$chunkId] ?? [] as $dep) {
            if (!isset($completedChunks[$dep])) {
                $prereqsMet = false;
                break;
            }
        }
        if ($prereqsMet) {
            $readyChunks[] = $chunkId;
        }
    }

    $chunksDir = $rootDir . '/chunks';
    $testsDir = $rootDir . '/tests';
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $completedCount = count($completedChunks);
    $totalCount = count($allChunks);

    $lines = [];
    $lines[] = '# LiteCMS — Project Status';
    $lines[] = '';
    $lines[] = '> Auto-generated by `php tests/run-all.php` at ' . $timestamp;
    $lines[] = '> Do not edit manually — this file is overwritten on every test run.';
    $lines[] = '';
    $lines[] = "## Progress: {$completedCount}/{$totalCount} chunks complete";
    $lines[] = '';

    // Chunk checklist with status
    $lines[] = '## Chunk Status';
    $lines[] = '';
    foreach ($allChunks as $chunkId => $file) {
        $name = $chunkNames[$chunkId] ?? $chunkId;
        if (isset($completedChunks[$chunkId])) {
            $lines[] = "- [x] {$chunkId} {$name}";
        } elseif (isset($failedSet[$chunkId])) {
            $lines[] = "- [ ] {$chunkId} {$name} — **FAILING**";
        } elseif (in_array($chunkId, $readyChunks, true)) {
            $lines[] = "- [ ] {$chunkId} {$name} — ready";
        } else {
            $lines[] = "- [ ] {$chunkId} {$name} — blocked";
        }
    }
    $lines[] = '';

    // Step Status — per-step tracking for each chunk
    $lines[] = '## Step Status';
    $lines[] = '';
    $lines[] = '| Chunk | Name | Status | 1·Plan | 2·Prompt | 3·Test | 4·Execute |';
    $lines[] = '|-------|------|--------|:------:|:--------:|:------:|:---------:|';
    foreach ($allChunks as $chunkId => $file) {
        $name = $chunkNames[$chunkId] ?? $chunkId;
        $hasPlan = file_exists($rootDir . '/CHUNK-' . $chunkId . '-PLAN.md');
        $hasPrompt = file_exists($chunksDir . '/' . $chunkId . '-prompt.md');
        $hasTest = file_exists($testsDir . '/chunk-' . $chunkId . '-verify.php');
        $isComplete = isset($completedChunks[$chunkId]);
        $isFailing = isset($failedSet[$chunkId]);
        $isReady = in_array($chunkId, $readyChunks, true);

        if ($isComplete) {
            $status = 'done';
            $s1 = $s2 = $s3 = $s4 = 'done';
        } elseif ($isFailing) {
            $status = '**FAIL**';
            $s1 = $hasPlan ? 'done' : '—';
            $s2 = $hasPrompt ? 'done' : '—';
            $s3 = $hasTest ? 'done' : '—';
            $s4 = '**FIX**';
        } elseif ($isReady) {
            $status = 'ready';
            if (!$hasPlan) {
                $s1 = '**NEXT**'; $s2 = '—'; $s3 = '—'; $s4 = '—';
            } elseif (!$hasPrompt) {
                $s1 = 'done'; $s2 = '**NEXT**'; $s3 = '—'; $s4 = '—';
            } elseif (!$hasTest) {
                $s1 = 'done'; $s2 = 'done'; $s3 = '**NEXT**'; $s4 = '—';
            } else {
                $s1 = 'done'; $s2 = 'done'; $s3 = 'done'; $s4 = '**NEXT**';
            }
        } else {
            $status = 'blocked';
            $s1 = $hasPlan ? 'done' : '—';
            $s2 = $hasPrompt ? 'done' : '—';
            $s3 = $hasTest ? 'done' : '—';
            $s4 = '—';
        }

        $lines[] = "| {$chunkId} | {$name} | {$status} | {$s1} | {$s2} | {$s3} | {$s4} |";
    }
    $lines[] = '';

    // Next steps — what should the next agent do?
    $lines[] = '## Next Steps';
    $lines[] = '';
    if ($completedCount === $totalCount) {
        $lines[] = 'All chunks complete. Run specialist review agents (see `review/` directory).';
    } elseif (!empty($failedChunks)) {
        foreach ($failedChunks as $fc) {
            $name = $chunkNames[$fc] ?? $fc;
            $lines[] = "- **Chunk {$fc} — {$name}**: Step 4 failing → follow `4_execute.md` to fix";
        }
    } elseif (!empty($readyChunks)) {
        foreach ($readyChunks as $rc) {
            $name = $chunkNames[$rc] ?? $rc;
            $hasPlan = file_exists($rootDir . '/CHUNK-' . $rc . '-PLAN.md');
            $hasPrompt = file_exists($chunksDir . '/' . $rc . '-prompt.md');
            $hasTest = file_exists($testsDir . '/chunk-' . $rc . '-verify.php');

            if (!$hasPlan) {
                $step = 1; $desc = 'Create Plan'; $runFile = '1_plan.md';
            } elseif (!$hasPrompt) {
                $step = 2; $desc = 'Create Prompt'; $runFile = '2_prompt.md';
            } elseif (!$hasTest) {
                $step = 3; $desc = 'Create Test'; $runFile = '3_test.md';
            } else {
                $step = 4; $desc = 'Execute'; $runFile = '4_execute.md';
            }

            $lines[] = "- **Chunk {$rc} — {$name}**: Step {$step} ({$desc}) → follow `{$runFile}`";
        }
    } else {
        $lines[] = 'No chunks are ready. Check dependency graph in PLAN.md.';
    }
    $lines[] = '';

    // Preserve Known Issues from existing file
    $lines[] = '## Known Issues';
    $lines[] = '';
    $existingIssues = extractKnownIssues($rootDir . '/STATUS.md');
    if (!empty($existingIssues)) {
        foreach ($existingIssues as $issue) {
            $lines[] = $issue;
        }
    } else {
        $lines[] = '- (none)';
    }
    $lines[] = '';

    file_put_contents($rootDir . '/STATUS.md', implode("\n", $lines));
    echo "\n[INFO] STATUS.md updated ({$completedCount}/{$totalCount} chunks complete)\n";
}

/**
 * Extracts Known Issues lines from existing STATUS.md so manual entries aren't lost.
 */
function extractKnownIssues(string $statusPath): array
{
    if (!file_exists($statusPath)) {
        return [];
    }
    $content = file_get_contents($statusPath);
    if (!preg_match('/## Known Issues\s*\n(.*?)(?=\n##|\z)/s', $content, $matches)) {
        return [];
    }
    $lines = array_filter(
        array_map('trim', explode("\n", trim($matches[1]))),
        fn(string $line) => $line !== '' && $line !== '- (none)'
    );
    return array_values($lines);
}
