<?php declare(strict_types=1);

namespace App\Auth;

use App\Core\Request;
use App\Core\Response;

class CsrfMiddleware
{
    public static function handle(Request $request, callable $next): Response
    {
        // Generate CSRF token if not present in session
        if (!Session::get('csrf_token')) {
            Session::set('csrf_token', bin2hex(random_bytes(32)));
        }

        // Validate on state-changing methods
        if (in_array($request->method(), ['POST', 'PUT', 'DELETE'], true)) {
            $token = (string) $request->input('_csrf_token', '');

            // Also accept token from header (for AJAX/fetch requests)
            if ($token === '') {
                $token = (string) ($request->server('HTTP_X_CSRF_TOKEN') ?? '');
            }

            $sessionToken = (string) Session::get('csrf_token', '');

            if ($sessionToken === '' || !hash_equals($sessionToken, $token)) {
                return Response::html(
                    '<h1>403 Forbidden</h1><p>Invalid or missing CSRF token.</p>',
                    403
                );
            }
        }

        return $next($request);
    }
}
