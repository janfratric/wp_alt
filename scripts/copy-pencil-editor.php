<?php declare(strict_types=1);
/**
 * One-time setup script: copies Pencil editor SPA files from the VS Code extension
 * to the public directory and patches index.html for LiteCMS integration.
 *
 * Usage: php scripts/copy-pencil-editor.php
 */

$appdata = getenv('APPDATA');
if (!$appdata) {
    // macOS/Linux fallback
    $home = getenv('HOME') ?: '';
    $appdata = $home . '/.vscode/extensions';
}
$source = $appdata . '/Code/User/globalStorage/highagency.pencildev/editor';
$dest = __DIR__ . '/../public/assets/pencil-editor';

if (!is_dir($source)) {
    echo "ERROR: Pencil editor not found at: $source\n";
    echo "Please install the Pencil VS Code extension first.\n";
    exit(1);
}

// Create destination directories
foreach (['', '/assets', '/images'] as $subdir) {
    $dir = $dest . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created: $dir\n";
    }
}

// Copy assets (skip .map files)
$copied = 0;
$skipped = 0;
$assets = glob($source . '/assets/*');
if ($assets) {
    foreach ($assets as $file) {
        if (is_dir($file)) {
            continue;
        }
        if (str_ends_with($file, '.map')) {
            $skipped++;
            continue;
        }
        $basename = basename($file);
        echo "Copying assets/$basename...\n";
        copy($file, $dest . '/assets/' . $basename);
        $copied++;
    }
}

// Copy images
$images = glob($source . '/images/*');
if ($images) {
    foreach ($images as $file) {
        if (is_dir($file)) {
            continue;
        }
        $basename = basename($file);
        echo "Copying images/$basename...\n";
        copy($file, $dest . '/images/' . $basename);
        $copied++;
    }
}

// Create patched index.html
$patchedHtml = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="google" content="notranslate">
    <title>Design Editor</title>
    <script src="../js/pencil-bridge.js"></script>
    <script type="module" crossorigin src="./assets/index.js"></script>
    <link rel="stylesheet" crossorigin href="./assets/index.css">
  </head>
  <body>
    <div id="root"></div>
  </body>
</html>
HTML;

file_put_contents($dest . '/index.html', $patchedHtml);
echo "Created patched index.html\n";

// Calculate total size
$totalSize = 0;
$assetFiles = glob($dest . '/assets/*');
if ($assetFiles) {
    foreach ($assetFiles as $f) {
        if (is_file($f)) {
            $totalSize += filesize($f);
        }
    }
}

echo "\nDone! Editor files copied to: $dest\n";
echo "Files copied: $copied, sourcemaps skipped: $skipped\n";
echo "Assets size: " . round($totalSize / 1024 / 1024, 1) . " MB\n";
