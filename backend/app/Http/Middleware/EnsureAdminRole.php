<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = $request->attributes->get('admin');

        if (!$admin || !in_array($admin->role, $roles, true)) {
            return response()->json(['message' => 'No tienes permisos para esta operación.'], 403);
        }

        return $next($request);
    }
}
