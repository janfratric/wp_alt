<?php declare(strict_types=1);

namespace App\Auth;

use App\Core\Request;
use App\Core\Response;

class AuthMiddleware
{
    /**
     * Paths under /admin that do NOT require authentication.
     */
    private const PUBLIC_ADMIN_PATHS = [
        '/admin/login',
    ];

    public static function handle(Request $request, callable $next): Response
    {
        $uri = $request->uri();

        // Only protect /admin routes
        if (!str_starts_with($uri, '/admin')) {
            return $next($request);
        }

        // Allow public admin paths (login page)
        if (in_array($uri, self::PUBLIC_ADMIN_PATHS, true)) {
            return $next($request);
        }

        // Check authentication
        if (!Session::get('user_id')) {
            return Response::redirect('/admin/login');
        }

        return $next($request);
    }
}
