<?php declare(strict_types=1);

namespace App\Auth;

use App\Core\Request;
use App\Core\Response;

class RoleMiddleware
{
    /**
     * Check if current user has the required role.
     * Returns null if authorized, or a 403 Response if not.
     */
    public static function check(string $requiredRole): ?Response
    {
        $userRole = (string) Session::get('user_role', '');

        if ($userRole !== $requiredRole) {
            return Response::html(
                '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>',
                403
            );
        }

        return null;
    }

    /**
     * Returns a middleware closure that requires a specific role.
     */
    public static function require(string $role): \Closure
    {
        return function (Request $request, callable $next) use ($role): Response {
            $denied = self::check($role);
            if ($denied !== null) {
                return $denied;
            }
            return $next($request);
        };
    }
}
