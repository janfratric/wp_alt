<?php declare(strict_types=1);

namespace App\Core;

class Middleware
{
    /**
     * Run the request through a middleware pipeline and final handler.
     *
     * Each middleware is a callable: fn(Request $request, callable $next): Response
     * The handler is the final callable: fn(Request $request): Response
     *
     * @param Request  $request     The HTTP request
     * @param array    $middlewares Array of middleware callables
     * @param callable $handler     The final request handler
     * @return Response
     */
    public static function run(Request $request, array $middlewares, callable $handler): Response
    {
        // Start with the final handler
        $next = function (Request $req) use ($handler): Response {
            return $handler($req);
        };

        // Wrap from right to left so the first middleware in the array executes first
        foreach (array_reverse($middlewares) as $mw) {
            $prev = $next;
            $next = function (Request $req) use ($mw, $prev): Response {
                return $mw($req, $prev);
            };
        }

        return $next($request);
    }
}
