<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->attributes->get('app_session');

        if (!$session || $session->principal_type !== 'admin') {
            return response()->json(['message' => 'Sesión administrativa requerida.'], 403);
        }

        $admin = Admin::query()->whereKey($session->principal_id)->where('active', true)->first();

        if (!$admin) {
            $session->delete();
            return response()->json(['message' => 'Cuenta administrativa inactiva.'], 403);
        }

        $request->attributes->set('admin', $admin);

        return $next($request);
    }
}
