<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Auth\Session;
use App\PageBuilder\PenConverter;

class DesignController
{
    private App $app;
    private string $designsDir;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->designsDir = dirname(__DIR__, 2) . '/designs';

        if (!is_dir($this->designsDir)) {
            mkdir($this->designsDir, 0755, true);
        }
    }

    /**
     * GET /admin/design/editor — Render the admin page with editor iframe.
     */
    public function editor(Request $request): Response
    {
        $currentFile = (string) $request->query('file', '');
        $csrfToken = Session::get('csrf_token', '');
        $designFiles = $this->getDesignFileList();

        $html = $this->app->template()->render('admin/design/editor', [
            'title'       => 'Design Editor',
            'activeNav'   => 'design-editor',
            'currentFile' => $currentFile,
            'csrfToken'   => $csrfToken,
            'designFiles' => $designFiles,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * GET /admin/design/load — Return .pen file content as JSON.
     */
    public function load(Request $request): Response
    {
        $path = (string) $request->query('path', '');
        $safePath = $this->sanitizePath($path);

        if ($safePath === null) {
            return Response::json(['success' => false, 'error' => 'Invalid path'], 400);
        }

        $fullPath = $this->designsDir . '/' . $safePath;

        if (!file_exists($fullPath)) {
            // Null content signals "new file" — editor will start with blank canvas
            return Response::json(['success' => true, 'content' => null]);
        }

        $content = file_get_contents($fullPath);

        return Response::json(['success' => true, 'content' => $content]);
    }

    /**
     * POST /admin/design/save — Write .pen file content to disk.
     */
    public function save(Request $request): Response
    {
        $body = json_decode(file_get_contents('php://input'), true);

        if (!$body) {
            return Response::json(['success' => false, 'error' => 'Invalid request'], 400);
        }

        $path = (string) ($body['path'] ?? '');
        $content = $body['content'] ?? '';
        $safePath = $this->sanitizePath($path);

        if ($safePath === null) {
            return Response::json(['success' => false, 'error' => 'Invalid path'], 400);
        }

        $fullPath = $this->designsDir . '/' . $safePath;

        // Ensure parent directory exists (for subdirectories like designs/pages/)
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Content may be a string (JSON) or array — normalize
        if (is_array($content) || is_object($content)) {
            $content = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        file_put_contents($fullPath, (string) $content, LOCK_EX);

        return Response::json(['success' => true]);
    }

    /**
     * POST /admin/design/import-file — Import an image/asset file.
     */
    public function importFile(Request $request): Response
    {
        $body = json_decode(file_get_contents('php://input'), true);

        if (!$body) {
            return Response::json(['success' => false, 'error' => 'Invalid request'], 400);
        }

        $uri = (string) ($body['uri'] ?? '');

        if ($uri === '') {
            return Response::json(['success' => false, 'error' => 'No URI provided'], 400);
        }

        // Handle data URI
        if (str_starts_with($uri, 'data:')) {
            if (!preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,(.+)$#s', $uri, $m)) {
                return Response::json(['success' => false, 'error' => 'Unsupported image format'], 400);
            }

            $mimeExt = $m[1];
            if ($mimeExt === 'jpeg') {
                $ext = 'jpg';
            } elseif ($mimeExt === 'svg+xml') {
                $ext = 'svg';
            } else {
                $ext = $mimeExt;
            }

            $data = base64_decode($m[2], true);
            if ($data === false) {
                return Response::json(['success' => false, 'error' => 'Invalid base64 data'], 400);
            }

            $filename = bin2hex(random_bytes(16)) . '.' . $ext;
            $uploadDir = dirname(__DIR__, 2) . '/public/assets/uploads/design';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            file_put_contents($uploadDir . '/' . $filename, $data);

            return Response::json(['success' => true, 'url' => '/assets/uploads/design/' . $filename]);
        }

        // Handle external URL — pass through
        if (str_starts_with($uri, 'http')) {
            return Response::json(['success' => true, 'url' => $uri]);
        }

        return Response::json(['success' => false, 'error' => 'Unsupported URI format'], 400);
    }

    /**
     * GET /admin/design/list — List all .pen files in the designs directory.
     */
    public function list(Request $request): Response
    {
        $files = $this->getDesignFileList();

        return Response::json(['success' => true, 'files' => $files]);
    }

    /**
     * Convert a .pen file to HTML + CSS.
     * POST /admin/design/convert
     * Body: { "path": "filename.pen" } or { "json": "..." }
     */
    public function convert(Request $request): Response
    {
        try {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];

            if (isset($body['path'])) {
                $path = $this->sanitizePath($body['path']);
                if ($path === null) {
                    return Response::json(['success' => false, 'error' => 'Invalid path'], 400);
                }
                $fullPath = $this->designsDir . DIRECTORY_SEPARATOR . $path;
                $result = PenConverter::convertFile($fullPath);
            } elseif (isset($body['json'])) {
                $result = PenConverter::convertJson($body['json']);
            } else {
                return Response::json(['success' => false, 'error' => 'Provide path or json'], 400);
            }

            return Response::json([
                'success' => true,
                'html' => $result['html'],
                'css' => $result['css'],
            ]);
        } catch (\Throwable $e) {
            return Response::json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Preview a .pen file conversion as standalone HTML page.
     * GET /admin/design/preview?path=filename.pen
     */
    public function preview(Request $request): Response
    {
        try {
            $path = $this->sanitizePath($request->query('path', ''));
            if ($path === null) {
                return Response::html('<h1>Invalid path</h1>', 400);
            }

            $fullPath = $this->designsDir . DIRECTORY_SEPARATOR . $path;
            $result = PenConverter::convertFile($fullPath);

            $html = '<!DOCTYPE html><html lang="en"><head>';
            $html .= '<meta charset="UTF-8">';
            $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
            $html .= '<title>Preview: ' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . '</title>';
            $html .= '<style>' . $result['css'] . '</style>';
            $html .= '</head><body>';
            $html .= $result['html'];
            $html .= '</body></html>';

            return Response::html($html);
        } catch (\Throwable $e) {
            return Response::html(
                '<h1>Conversion Error</h1><pre>' .
                htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') .
                '</pre>',
                500
            );
        }
    }

    /**
     * Validate and sanitize a file path to prevent directory traversal.
     */
    private function sanitizePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        // Remove leading slashes
        $path = ltrim($path, '/\\');

        // Block path traversal
        if (str_contains($path, '..')) {
            return null;
        }

        // Block null bytes
        if (str_contains($path, "\0")) {
            return null;
        }

        // Must end with .pen
        if (!str_ends_with($path, '.pen')) {
            return null;
        }

        // Only allow safe characters
        if (!preg_match('#^[a-zA-Z0-9_\-/]+\.pen$#', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * Scan designs/ directory for .pen files.
     */
    private function getDesignFileList(): array
    {
        if (!is_dir($this->designsDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->designsDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'pen') {
                continue;
            }
            $relativePath = str_replace($this->designsDir . DIRECTORY_SEPARATOR, '', $fileInfo->getPathname());
            // Normalize path separators for Windows
            $relativePath = str_replace('\\', '/', $relativePath);
            $files[] = [
                'name'     => $fileInfo->getBasename('.pen'),
                'path'     => $relativePath,
                'modified' => date('Y-m-d H:i:s', $fileInfo->getMTime()),
                'size'     => $fileInfo->getSize(),
            ];
        }

        usort($files, fn($a, $b) => strcmp($b['modified'], $a['modified']));

        return $files;
    }

    /**
     * Add security headers. CSP relaxed for Pencil editor (WASM, inline, workers).
     */
    private function withSecurityHeaders(Response $response): Response
    {
        $csp = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "connect-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com "
            .   "https://unpkg.com https://images.unsplash.com "
            .   "https://hctfc8iexhqk0x3o.public.blob.vercel-storage.com; "
            . "img-src 'self' data: blob: https://images.unsplash.com "
            .   "https://*.public.blob.vercel-storage.com; "
            . "font-src 'self' data: blob: https://fonts.gstatic.com https://unpkg.com "
            .   "https://hctfc8iexhqk0x3o.public.blob.vercel-storage.com; "
            . "worker-src 'self' blob: data:; "
            . "child-src 'self' blob:; "
            . "frame-src 'self'";

        return $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
