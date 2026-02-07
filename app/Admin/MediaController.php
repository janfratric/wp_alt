<?php declare(strict_types=1);

namespace App\Admin;

use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Database\QueryBuilder;
use App\Auth\Session;

class MediaController
{
    private App $app;

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/media — Media library grid with pagination.
     */
    public function index(Request $request): Response
    {
        $page    = max(1, (int) $request->query('page', '1'));
        $perPage = Config::getInt('items_per_page', 10);

        $total      = QueryBuilder::query('media')->select()->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        $items = QueryBuilder::query('media')
            ->select('media.*', 'users.username as uploaded_by_name')
            ->leftJoin('users', 'users.id', '=', 'media.uploaded_by')
            ->orderBy('media.created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $html = $this->app->template()->render('admin/media/index', [
            'title'      => 'Media Library',
            'activeNav'  => 'media',
            'items'      => $items,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);

        return $this->withSecurityHeaders(Response::html($html));
    }

    /**
     * POST /admin/media/upload — Handle file upload.
     */
    public function upload(Request $request): Response
    {
        $isAjax = $request->isAjax();

        // Check file presence
        if (!$request->hasFile('file')) {
            return $this->uploadError('No file was uploaded.', $isAjax);
        }

        $file = $request->file('file');
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $this->uploadError($this->uploadErrorMessage($file['error']), $isAjax);
        }

        // Validate extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return $this->uploadError(
                'File type not allowed. Allowed: ' . implode(', ', self::ALLOWED_EXTENSIONS),
                $isAjax
            );
        }

        // Validate MIME type with finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return $this->uploadError('File content does not match its extension.', $isAjax);
        }

        // Validate file size
        $maxSize = Config::getInt('max_upload_size', 5242880);
        if ($file['size'] > $maxSize) {
            return $this->uploadError(
                'File is too large. Maximum size: ' . $this->formatFileSize($maxSize),
                $isAjax
            );
        }

        // Generate unique filename
        $hash = bin2hex(random_bytes(16));
        $newFilename = $hash . '.' . $ext;
        $subdir = date('Y') . '/' . date('m');

        // Ensure directory exists
        $uploadsBase = $this->getUploadsPath();
        $fullDir = $uploadsBase . $subdir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        // Move uploaded file
        $destPath = $fullDir . '/' . $newFilename;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return $this->uploadError('Failed to save uploaded file.', $isAjax);
        }

        // Insert database record
        $relativePath = $subdir . '/' . $newFilename;
        $id = QueryBuilder::query('media')->insert([
            'filename'      => $relativePath,
            'original_name' => $file['name'],
            'mime_type'     => $mime,
            'size_bytes'    => $file['size'],
            'uploaded_by'   => (int) Session::get('user_id'),
        ]);

        $url = '/assets/uploads/' . $relativePath;

        if ($isAjax) {
            return Response::json([
                'success'       => true,
                'id'            => (int) $id,
                'url'           => $url,
                'original_name' => $file['name'],
                'mime_type'     => $mime,
            ]);
        }

        Session::flash('success', 'File uploaded successfully.');
        return Response::redirect('/admin/media');
    }

    /**
     * DELETE /admin/media/{id} — Delete a media item.
     */
    public function delete(Request $request, string $id): Response
    {
        $isAjax = $request->isAjax();

        $item = QueryBuilder::query('media')
            ->select()
            ->where('id', (int) $id)
            ->first();

        if ($item === null) {
            if ($isAjax) {
                return Response::json(['success' => false, 'error' => 'Media not found.'], 404);
            }
            Session::flash('error', 'Media not found.');
            return Response::redirect('/admin/media');
        }

        // Delete file from disk
        $filePath = $this->getUploadsPath() . $item['filename'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        // Delete database record
        QueryBuilder::query('media')->where('id', (int) $id)->delete();

        if ($isAjax) {
            return Response::json(['success' => true]);
        }

        Session::flash('success', 'Media deleted.');
        return Response::redirect('/admin/media');
    }

    /**
     * GET /admin/media/browse — JSON endpoint for media browser modal.
     */
    public function browse(Request $request): Response
    {
        $page    = max(1, (int) $request->query('page', '1'));
        $perPage = 20;
        $type    = (string) $request->query('type', '');

        $qb = QueryBuilder::query('media')->select();
        if ($type === 'image') {
            $qb->whereRaw('mime_type LIKE :mime', [':mime' => 'image/%']);
        }
        $total = $qb->count();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        $qb2 = QueryBuilder::query('media')->select();
        if ($type === 'image') {
            $qb2->whereRaw('mime_type LIKE :mime', [':mime' => 'image/%']);
        }
        $items = $qb2->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'id'            => (int) $item['id'],
                'url'           => '/assets/uploads/' . $item['filename'],
                'original_name' => $item['original_name'],
                'mime_type'     => $item['mime_type'],
                'size'          => $this->formatFileSize((int) $item['size_bytes']),
                'created_at'    => $item['created_at'],
            ];
        }

        return Response::json([
            'items'      => $result,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    private function getUploadsPath(): string
    {
        return dirname(__DIR__, 2) . '/public/assets/uploads/';
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1048576, 1) . ' MB';
    }

    private function uploadError(string $message, bool $isAjax): Response
    {
        if ($isAjax) {
            return Response::json(['success' => false, 'error' => $message], 400);
        }
        Session::flash('error', $message);
        return Response::redirect('/admin/media');
    }

    private function uploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size.',
            UPLOAD_ERR_PARTIAL   => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
            default               => 'Unknown upload error.',
        };
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self' https://cdn.jsdelivr.net; "
                . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
                . "img-src 'self' data: blob:; "
                . "connect-src 'self'; "
                . "font-src 'self' https://cdn.jsdelivr.net"
            );
    }
}
